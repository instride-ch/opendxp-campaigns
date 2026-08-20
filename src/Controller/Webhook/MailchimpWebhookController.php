<?php

declare(strict_types=1);

/**
 * OpenDXP Campaigns.
 *
 * LICENSE
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  2026 instride AG (https://instride.ch)
 * @license    https://github.com/instride-ch/opendxp-campaigns/blob/main/gpl-3.0.txt GNU General Public License version 3 (GPLv3)
 */

namespace Instride\Bundle\OpenDxpCampaignsBundle\Controller\Webhook;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberResolverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\Mailchimp\MailchimpDriver;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\ConnectorNotFoundException;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\IncomingMemberSync;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\OutboundSyncSuppressor;
use OpenDxp\Model\Element\DuplicateFullPathException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

readonly class MailchimpWebhookController
{
    public function __construct(
        private DriverRegistry $registry,
        private MemberResolverInterface $memberResolver,
        private IncomingMemberSync $incomingSync,
        private OutboundSyncSuppressor $suppressor,
        private LoggerInterface $logger,
    ) {}

    /**
     * GET: Mailchimp verification handshake — must respond 200 OK with empty body.
     * POST: Incoming webhook event.
     */
    #[Route(
        '/webhooks/campaigns/mailchimp/{connectorName}',
        name: 'opendxp_campaigns_webhook_mailchimp',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $connectorName): Response
    {
        if ($request->isMethod('GET')) {
            return new Response('', Response::HTTP_OK);
        }

        if (!$this->validateSecret($request, $connectorName)) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        // Mailchimp posts form-encoded, so the parsed request body already holds the event.
        $type = $request->request->getString('type');
        $data = $request->request->all('data');

        if ($type === '') {
            $this->logger->warning('[OpenDXP Campaigns] Mailchimp webhook without an event type, ignoring.');

            return new Response('', Response::HTTP_OK);
        }

        $email = \is_string($data['email'] ?? null) && $data['email'] !== '' ? $data['email'] : null;
        $providerListId = \is_string($data['list_id'] ?? null) ? $data['list_id'] : '';

        $this->logger->info(
            \sprintf(
                '[OpenDXP Campaigns] Mailchimp webhook received: type=%s connector=%s',
                $type,
                $connectorName
            ),
            ['email' => $email],
        );

        $member = $this->resolveMember($email);
        $status = match ($type) {
            'subscribe' => SubscriptionStatus::SUBSCRIBED,
            'unsubscribe' => SubscriptionStatus::UNSUBSCRIBED,
            'cleaned' => SubscriptionStatus::CLEANED,
            default => null,
        };

        $changed = match (true) {
            $status !== null => $this->handleStatusChange($member, $connectorName, $status, $email, $providerListId),
            $type === 'profile' => $this->handleProfileUpdate($member, $connectorName, $data['merges'] ?? [], $providerListId),
            default => $this->handleUnknownType($type),
        };

        if ($changed && $member !== null) {
            try {
                // Suppress the outbound sync listener: this save applies provider state,
                // pushing it straight back would be a redundant round-trip.
                $this->suppressor->suppress(
                    static fn () => $member->save(['versionNote' => '[OpenDXP Campaigns] Updated by Mailchimp webhook!']),
                );
            } catch (DuplicateFullPathException $exception) {
                $this->logger->error(
                    \sprintf(
                        '[OpenDXP Campaigns] Failed to save member "%s" after Mailchimp webhook update: %s',
                        $email,
                        $exception->getMessage()
                    ),
                );
            }
        }

        return new Response('', Response::HTTP_OK);
    }

    private function handleStatusChange(
        ?NewsletterMemberInterface $member,
        string $connectorName,
        SubscriptionStatus $newStatus,
        ?string $email,
        string $providerListId,
    ): bool {
        if ($member === null || $email === null) {
            return false;
        }

        $changed = false;

        foreach ($this->listNames($connectorName, $providerListId) as $listName) {
            $changed = $this->incomingSync->applyStatus($member, $listName, $newStatus, $email, 'webhook.mailchimp') || $changed;
        }

        return $changed;
    }

    /**
     * @param array<string, scalar> $mergeFieldData  provider merge tags from the webhook payload
     */
    private function handleProfileUpdate(
        ?NewsletterMemberInterface $member,
        string $connectorName,
        array $mergeFieldData,
        string $providerListId,
    ): bool {
        if ($member === null || $mergeFieldData === []) {
            return false;
        }

        $changed = false;

        foreach ($this->listNames($connectorName, $providerListId) as $listName) {
            $changed = $this->incomingSync->applyMergeFields($member, $listName, $mergeFieldData) || $changed;
        }

        return $changed;
    }

    private function handleUnknownType(string $type): false
    {
        $this->logger->debug(
            \sprintf('[OpenDXP Campaigns] Unhandled Mailchimp webhook type "%s", ignoring.', $type),
        );

        return false;
    }

    /**
     * The configured lists an event applies to.
     *
     * Mailchimp names the audience the event came from, and a connector may serve several. Without
     * that filter an unsubscribe from one audience would mark the member unsubscribed on every list
     * of the connector. An event without an audience falls back to all of them, which is what a
     * hand-made call or an older payload looks like.
     *
     * @return string[]
     */
    private function listNames(string $connectorName, string $providerListId): array
    {
        $listNames = [];

        foreach ($this->registry->getListConfigs() as $listName => $listConfig) {
            if ($listConfig->connectorName !== $connectorName) {
                continue;
            }

            if ($providerListId !== '' && $listConfig->providerListId !== $providerListId) {
                continue;
            }

            $listNames[] = $listName;
        }

        return $listNames;
    }

    private function validateSecret(Request $request, string $connectorName): bool
    {
        try {
            $driver = $this->registry->getDriverForConnector($connectorName);
        } catch (ConnectorNotFoundException) {
            // The URL is public, so a wrong or outdated connector name is a caller's mistake,
            // not ours: answering 401 keeps it out of the error log and out of Mailchimp's retries.
            $this->logger->warning('[OpenDXP Campaigns] Webhook for unknown connector "{connector}".', [
                'connector' => $connectorName,
            ]);

            return false;
        }

        if (!$driver instanceof MailchimpDriver) {
            return false;
        }

        $expectedSecret = $driver->getWebhookSecret();

        if ($expectedSecret === null) {
            return true;
        }

        $providedSecret = $request->query->getString('secret');

        return \hash_equals($expectedSecret, $providedSecret);
    }

    private function resolveMember(?string $email): ?NewsletterMemberInterface
    {
        if ($email === null) {
            $this->logger->warning('[OpenDXP Campaigns] Mailchimp webhook missing email.');

            return null;
        }

        $member = $this->memberResolver->resolveByEmail($email);

        if ($member === null) {
            $this->logger->warning(
                \sprintf('[OpenDXP Campaigns] Mailchimp webhook could not find member with email "%s".', $email),
            );
        }

        return $member;
    }
}

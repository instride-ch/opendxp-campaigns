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

use DrewM\MailChimp\Webhook;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberResolverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\Mailchimp\MailchimpDriver;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
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

        $result = Webhook::receive();
        $type = $result['type'];
        $data = $result['data'];
        $email = $data['email'] ?? null;

        $this->logger->info(
            \sprintf(
                '[OpenDXP Campaigns] Mailchimp webhook received: type=%s connector=%s',
                $type,
                $connectorName
            ),
            ['email' => $email],
        );

        $member = $this->resolveMember($email);
        $changed = match ($type) {
            'subscribe' => $this->handleStatusChange($member, $connectorName, SubscriptionStatus::SUBSCRIBED),
            'unsubscribe' => $this->handleStatusChange($member, $connectorName, SubscriptionStatus::UNSUBSCRIBED),
            'cleaned' => $this->handleStatusChange($member, $connectorName, SubscriptionStatus::CLEANED),
            'profile' => $this->handleProfileUpdate($member, $connectorName, $data['merges'] ?? []),
            default => $this->handleUnknownType($type),
        };

        if ($changed) {
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
        SubscriptionStatus $newStatus
    ): bool {
        if ($member === null) {
            return false;
        }

        $changed = false;

        foreach ($this->registry->getListConfigs() as $listName => $listConfig) {
            if ($listConfig->connectorName !== $connectorName) {
                continue;
            }

            $changed = $this->incomingSync->applyStatus($member, $listName, $newStatus, 'webhook.mailchimp') || $changed;
        }

        return $changed;
    }

    /**
     * @param array<string, scalar> $mergeFieldData  provider merge tags from the webhook payload
     */
    private function handleProfileUpdate(
        ?NewsletterMemberInterface $member,
        string $connectorName,
        array $mergeFieldData
    ): bool {
        if ($member === null || $mergeFieldData === []) {
            return false;
        }

        $changed = false;

        foreach ($this->registry->getListConfigs() as $listName => $listConfig) {
            if ($listConfig->connectorName !== $connectorName) {
                continue;
            }

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

    private function validateSecret(Request $request, string $connectorName): bool
    {
        $driver = $this->registry->getDriverForConnector($connectorName);

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
        if (!\is_string($email) || $email === '') {
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

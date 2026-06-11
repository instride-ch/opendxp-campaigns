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
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Event\MemberSubscriptionStatusChangedEvent;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\MergeFieldMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

readonly class MailchimpWebhookController
{
    public function __construct(
        private DriverRegistry $registry,
        private MemberResolverInterface $memberResolver,
        private MergeFieldMapper $mapper,
        private EventDispatcherInterface $eventDispatcher,
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

        $type = $request->request->getString('type');
        $data = $request->request->all('data');
        $email = $data['email'] ?? null;

        $this->logger->info(
            \sprintf('[OpenDXP Campaigns] Mailchimp webhook received: type=%s connector=%s', $type, $connectorName),
            ['email' => $email],
        );

        $member = $this->resolveMember($email);
        $changed = match ($type) {
            'subscribe' => $this->handleStatusChange($member, $connectorName, SubscriptionStatus::SUBSCRIBED->value),
            'unsubscribe' => $this->handleStatusChange($member, $connectorName, SubscriptionStatus::UNSUBSCRIBED->value),
            'cleaned' => $this->handleStatusChange($member, $connectorName, SubscriptionStatus::CLEANED->value),
            'profile' => $this->handleProfileUpdate($member, $connectorName, $data['merges'] ?? []),
            default => $this->handleUnknownType($type),
        };

        if ($changed) {
            $member->save(['versionNote' => '[OpenDXP Campaigns] Updated by Mailchimp webhook!']);
        }

        return new Response('', Response::HTTP_OK);
    }

    private function handleStatusChange(?NewsletterMemberInterface $member, string $connectorName, string $newStatus): bool
    {
        if ($member === null) {
            return false;
        }

        $changed = false;

        foreach ($this->registry->getListConfigs() as $listName => $listConfig) {
            if ($listConfig->connectorName !== $connectorName) {
                continue;
            }

            $previousStatus = $member->getNewsletterSubscriptionStatus($listName) ?? '';
            $member->setNewsletterSubscriptionStatus($listName, $newStatus);

            $this->eventDispatcher->dispatch(new MemberSubscriptionStatusChangedEvent(
                member: $member,
                listName: $listName,
                previousStatus: $previousStatus,
                newStatus: $newStatus,
                source: 'webhook.mailchimp',
            ));

            $changed = true;
        }

        return $changed;
    }

    /**
     * @param array<string, scalar> $mergeFieldData  provider merge tags from the webhook payload
     */
    private function handleProfileUpdate(?NewsletterMemberInterface $member, string $connectorName, array $mergeFieldData): bool
    {
        if ($member === null || $mergeFieldData === []) {
            return false;
        }

        $changed = false;

        foreach ($this->registry->getListConfigs() as $listConfig) {
            if ($listConfig->connectorName !== $connectorName || $listConfig->mergeFieldMappings === []) {
                continue;
            }

            $localFields = $this->mapper->fromProvider($mergeFieldData, $listConfig->mergeFieldMappings);

            foreach ($localFields as $localField => $value) {
                $setter = 'set' . \ucfirst($localField);

                if (\method_exists($member, $setter)) {
                    $member->$setter($value);
                    $changed = true;
                }
            }
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

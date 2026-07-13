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

namespace Instride\Bundle\OpenDxpCampaignsBundle\EventListener;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Message\SyncMemberToListMessage;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\OutboundSyncSuppressor;
use OpenDxp\Event\Model\DataObjectEvent;
use OpenDxp\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps the newsletter provider in sync with OpenDXP by pushing a member's data to
 * every configured list it is subscribed to whenever that member is saved.
 *
 * Mirrors the outbound half of {@see \Instride\Bundle\OpenDxpCampaignsBundle\Command\PushNewsletterCommand}:
 * rather than calling the provider inline (which would block the save on a remote HTTP
 * round-trip and could fail the save if the provider is down), it dispatches an
 * idempotent {@see SyncMemberToListMessage} per subscribed list. The existing
 * SyncMemberToListHandler resolves the member fresh and reconciles provider state.
 */
final readonly class MemberDataObjectSyncListener
{
    public function __construct(
        private DriverRegistry $registry,
        private MessageBusInterface $bus,
        private OutboundSyncSuppressor $suppressor,
        private LoggerInterface $logger,
    ) {}

    /**
     * Handles both opendxp.dataobject.postAdd and opendxp.dataobject.postUpdate.
     */
    public function onPostWrite(DataObjectEvent $event): void
    {
        // The change originates from an inbound provider sync (webhook / pull) that is
        // persisting the member — don't echo it straight back to the provider.
        if ($this->suppressor->isSuppressed()) {
            return;
        }

        // Version-only saves (auto-save drafts) are not real state changes.
        if ($event->hasArgument('saveVersionOnly') && $event->getArgument('saveVersionOnly') === true) {
            return;
        }

        $object = $event->getObject();

        if (!$object instanceof NewsletterMemberInterface) {
            return;
        }

        // Unpublished / draft members stay local until they go live.
        if ($object instanceof Concrete && !$object->isPublished()) {
            return;
        }

        $memberId = $object->getId();
        $email = $object->getNewsletterEmail();

        if ($memberId === null || $email === '') {
            return;
        }

        foreach ($this->registry->getListNames() as $listName) {
            // Only sync lists the member actually carries a subscription for; an unknown
            // (null) status means the member never opted into this list, so pushing would
            // silently subscribe them everywhere.
            if ($object->getNewsletterSubscriptionStatus($listName) === null) {
                continue;
            }

            try {
                $this->bus->dispatch(new SyncMemberToListMessage($listName, $memberId));
            } catch (ExceptionInterface $exception) {
                $this->logger->error(
                    '[OpenDXP Campaigns] Failed to dispatch member sync after save.',
                    [
                        'member_id' => $memberId,
                        'email' => $email,
                        'list_name' => $listName,
                        'exception' => $exception->getMessage(),
                    ],
                );
            }
        }
    }
}
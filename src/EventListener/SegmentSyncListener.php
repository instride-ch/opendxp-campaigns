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

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentGroupInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Message\DeleteSegmentGroupMessage;
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Message\DeleteSegmentMessage;
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Message\SyncSegmentGroupMessage;
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Message\SyncSegmentMessage;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\RemoteIdStore;
use OpenDxp\Event\Model\DataObjectEvent;
use OpenDxp\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\OutboundSyncSuppressor;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps the newsletter provider in sync with OpenDXP segment groups and segments.
 *
 * On save, it dispatches an idempotent (re-)export message per object. On delete it
 * captures the provider remote IDs while the object and its Notes still exist
 * (preDelete), then dispatches the delete message once the deletion is committed
 * (postDelete), so a failed delete never triggers a phantom provider delete.
 *
 * Only wired when opendxp_campaigns.segments.sync_on_save is enabled.
 */
final class SegmentSyncListener
{
    /**
     * preDelete-captured delete messages, keyed by object id, dispatched in postDelete.
     *
     * @var array<int, DeleteSegmentGroupMessage|DeleteSegmentMessage>
     */
    private array $pendingDeletes = [];

    public function __construct(
        private readonly DriverRegistry $registry,
        private readonly RemoteIdStore $remoteIds,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly OutboundSyncSuppressor $suppressor,
    ) {}

    public function onPostWrite(DataObjectEvent $event): void
    {
        // The change originates from an inbound provider sync that is persisting the object —
        // don't echo it straight back to the provider.
        if ($this->suppressor->isSuppressed()) {
            return;
        }

        if ($this->isVersionOnly($event)) {
            return;
        }

        $object = $event->getObject();

        // Unpublished / draft objects stay local until they go live.
        if ($object instanceof Concrete && !$object->isPublished()) {
            return;
        }

        $message = match (true) {
            $object instanceof NewsletterSegmentGroupInterface => new SyncSegmentGroupMessage((int) $object->getId()),
            $object instanceof NewsletterSegmentInterface => new SyncSegmentMessage((int) $object->getId()),
            default => null,
        };

        if ($message !== null) {
            $this->dispatch($message, (int) $object->getId());
        }
    }

    public function onPreDelete(DataObjectEvent $event): void
    {
        $object = $event->getObject();
        $id = $object->getId();

        if ($id === null) {
            return;
        }

        // Groups take precedence: a group is also technically iterable, but only the
        // segment branch resolves a parent group.
        if ($object instanceof NewsletterSegmentGroupInterface) {
            $this->pendingDeletes[$id] = new DeleteSegmentGroupMessage($this->captureGroupRemoteIds($object));

            return;
        }

        if ($object instanceof NewsletterSegmentInterface) {
            $this->pendingDeletes[$id] = new DeleteSegmentMessage($this->captureSegmentRemoteIds($object));
        }
    }

    public function onPostDelete(DataObjectEvent $event): void
    {
        $id = $event->getObject()->getId();

        if ($id === null || !isset($this->pendingDeletes[$id])) {
            return;
        }

        $message = $this->pendingDeletes[$id];
        unset($this->pendingDeletes[$id]);

        // Nothing stored on the provider → nothing to delete.
        if ($this->isEmptyDelete($message)) {
            return;
        }

        $this->dispatch($message, $id);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string> list identifier => provider group ID
     */
    private function captureGroupRemoteIds(NewsletterSegmentGroupInterface $group): array
    {
        $captured = [];

        foreach ($group->getNewsletterListNames() as $listName) {
            $connector = $this->connectorFor($listName);

            if ($connector === null) {
                continue;
            }

            $remoteId = $this->remoteIds->getRemoteId($group, $connector, $listName);

            if ($remoteId !== null) {
                $captured[$listName] = $remoteId;
            }
        }

        return $captured;
    }

    /**
     * @return array<string, array{group_remote_id: string, remote_id: string}>
     */
    private function captureSegmentRemoteIds(NewsletterSegmentInterface $segment): array
    {
        try {
            $group = $segment->getNewsletterSegmentGroup();
        } catch (\Throwable $exception) {
            $this->logger->warning(
                '[OpenDXP Campaigns] Could not resolve group for deleted segment {id}: {msg}',
                ['id' => (string) $segment->getId(), 'msg' => $exception->getMessage()],
            );

            return [];
        }

        $captured = [];

        foreach ($group->getNewsletterListNames() as $listName) {
            $connector = $this->connectorFor($listName);

            if ($connector === null) {
                continue;
            }

            $groupRemoteId = $this->remoteIds->getRemoteId($group, $connector, $listName);
            $remoteId = $this->remoteIds->getRemoteId($segment, $connector, $listName);

            if ($groupRemoteId !== null && $remoteId !== null) {
                $captured[$listName] = ['group_remote_id' => $groupRemoteId, 'remote_id' => $remoteId];
            }
        }

        return $captured;
    }

    private function connectorFor(string $listName): ?string
    {
        try {
            return $this->registry->getListConfig($listName)->connectorName;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isEmptyDelete(DeleteSegmentGroupMessage|DeleteSegmentMessage $message): bool
    {
        return $message instanceof DeleteSegmentGroupMessage
            ? $message->remoteIdsByList === []
            : $message->byList === [];
    }

    private function isVersionOnly(DataObjectEvent $event): bool
    {
        return $event->hasArgument('saveVersionOnly') && $event->getArgument('saveVersionOnly') === true;
    }

    private function dispatch(object $message, int $objectId): void
    {
        try {
            $this->bus->dispatch($message);
        } catch (ExceptionInterface $exception) {
            $this->logger->error(
                '[OpenDXP Campaigns] Failed to dispatch segment sync message.',
                [
                    'object_id' => $objectId,
                    'message' => $message::class,
                    'exception' => $exception->getMessage(),
                ],
            );
        }
    }
}

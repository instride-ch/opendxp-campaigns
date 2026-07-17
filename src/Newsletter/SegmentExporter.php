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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Newsletter;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentGroupInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\SegmentExportCapableInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Provider-agnostic orchestration for exporting segment groups and segments to
 * the configured newsletter lists.
 *
 * A group (Mailchimp interest category) is scoped to a single audience, so it is
 * exported once per list the group targets, and one remote ID is stored per
 * (object, connector, list) via {@see RemoteIdStore}.
 *
 * Create-vs-update is decided by the presence of a stored remote ID, making every
 * export idempotent. A named lock per (object, list) serializes concurrent workers
 * so a group is never created twice — the reason interest-category creation, which
 * is not idempotent on the provider side, is safe here.
 */
final readonly class SegmentExporter
{
    public function __construct(
        private DriverRegistry $registry,
        private RemoteIdStore $remoteIds,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {}

    /**
     * (Re-)export a group to every list it targets, then remove it from lists it
     * no longer targets.
     */
    public function exportGroup(NewsletterSegmentGroupInterface $group): void
    {
        $targets = $group->getNewsletterListNames();

        foreach ($targets as $listName) {
            $this->upsertGroup($group, $listName);
        }

        $this->reconcileGroupLists($group, $targets);
    }

    /**
     * (Re-)export a segment to every list its group targets, ensuring the parent
     * group exists on the provider first.
     */
    public function exportSegment(NewsletterSegmentInterface $segment): void
    {
        $group = $segment->getNewsletterSegmentGroup();

        foreach ($group->getNewsletterListNames() as $listName) {
            if (!$this->supportsSegments($listName)) {
                continue;
            }

            $listConfig = $this->registry->getListConfig($listName);
            $groupRemoteId = $this->ensureGroupRemoteId($group, $listName);

            if ($groupRemoteId === '') {
                continue;
            }

            $this->withLock('seg', $segment->getId(), $listName, function () use ($segment, $group, $listConfig, $listName, $groupRemoteId): void {
                /** @var SegmentExportCapableInterface $driver */
                $driver = $this->registry->getDriverForList($listName);

                $remoteId = $this->remoteIds->getRemoteId($segment, $listConfig->connectorName, $listName);
                $newId = $driver->exportSegment(
                    $listConfig->providerListId,
                    $groupRemoteId,
                    $segment->getNewsletterSegmentName(),
                    $remoteId,
                );

                $this->remoteIds->setRemoteId($segment, $listConfig->connectorName, $listName, $newId);
            });
        }
    }

    /**
     * @param array<string, string> $remoteIdsByList list identifier => provider group ID
     */
    public function deleteGroup(array $remoteIdsByList): void
    {
        foreach ($remoteIdsByList as $listName => $remoteId) {
            if (!$this->supportsSegments($listName)) {
                continue;
            }

            $listConfig = $this->registry->getListConfig($listName);
            /** @var SegmentExportCapableInterface $driver */
            $driver = $this->registry->getDriverForList($listName);

            $driver->deleteSegmentGroup($listConfig->providerListId, $remoteId);
        }
    }

    /**
     * @param array<string, array{group_remote_id: string, remote_id: string}> $byList
     */
    public function deleteSegment(array $byList): void
    {
        foreach ($byList as $listName => $ids) {
            if (!$this->supportsSegments($listName)) {
                continue;
            }

            $listConfig = $this->registry->getListConfig($listName);
            /** @var SegmentExportCapableInterface $driver */
            $driver = $this->registry->getDriverForList($listName);

            $driver->deleteSegment($listConfig->providerListId, $ids['group_remote_id'], $ids['remote_id']);
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function upsertGroup(NewsletterSegmentGroupInterface $group, string $listName): string
    {
        if (!$this->supportsSegments($listName)) {
            return '';
        }

        $listConfig = $this->registry->getListConfig($listName);

        return $this->withLock('grp', $group->getId(), $listName, function () use ($group, $listConfig, $listName): string {
            /** @var SegmentExportCapableInterface $driver */
            $driver = $this->registry->getDriverForList($listName);

            $remoteId = $this->remoteIds->getRemoteId($group, $listConfig->connectorName, $listName);
            $newId = $driver->exportSegmentGroup(
                $listConfig->providerListId,
                $group->getNewsletterSegmentGroupName(),
                $remoteId,
            );

            $this->remoteIds->setRemoteId($group, $listConfig->connectorName, $listName, $newId);

            return $newId;
        });
    }

    /**
     * Returns the group's provider ID for a list, creating the group on the
     * provider only if it has not been exported yet (avoids a redundant PATCH on
     * every segment save).
     */
    private function ensureGroupRemoteId(NewsletterSegmentGroupInterface $group, string $listName): string
    {
        $listConfig = $this->registry->getListConfig($listName);
        $existing = $this->remoteIds->getRemoteId($group, $listConfig->connectorName, $listName);

        return $existing ?? $this->upsertGroup($group, $listName);
    }

    /**
     * Delete the group from any configured list it no longer targets.
     *
     * @param string[] $currentTargets
     */
    private function reconcileGroupLists(NewsletterSegmentGroupInterface $group, array $currentTargets): void
    {
        foreach ($this->registry->getListNames() as $listName) {
            if (\in_array($listName, $currentTargets, true) || !$this->supportsSegments($listName)) {
                continue;
            }

            $listConfig = $this->registry->getListConfig($listName);
            $remoteId = $this->remoteIds->getRemoteId($group, $listConfig->connectorName, $listName);

            if ($remoteId === null) {
                continue;
            }

            /** @var SegmentExportCapableInterface $driver */
            $driver = $this->registry->getDriverForList($listName);
            $driver->deleteSegmentGroup($listConfig->providerListId, $remoteId);
            $this->remoteIds->removeRemoteId($group, $listConfig->connectorName, $listName);
        }
    }

    private function supportsSegments(string $listName): bool
    {
        $driver = $this->registry->getDriverForList($listName);

        if ($driver instanceof SegmentExportCapableInterface) {
            return true;
        }

        $this->logger->info(
            '[OpenDXP Campaigns] Driver for list "{list}" does not support segment export; skipping.',
            ['list' => $listName],
        );

        return false;
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withLock(string $kind, ?int $objectId, string $listName, callable $callback): mixed
    {
        $lock = $this->lockFactory->createLock(\sprintf('opendxp_campaigns_%s_%s_%s', $kind, (string) $objectId, $listName));
        $lock->acquire(true);

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
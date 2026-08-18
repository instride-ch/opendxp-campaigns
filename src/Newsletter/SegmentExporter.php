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

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentGroupInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\SegmentExportCapableInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\ListNotFoundException;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\SegmentPlacementException;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\SegmentProviderInterface;
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
final readonly class SegmentExporter implements ManagedSegmentInterestsInterface
{
    public function __construct(
        private DriverRegistry $registry,
        private RemoteIdStore $remoteIds,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
        private SegmentProviderInterface $segments,
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

            $this->withLock('seg', $segment->getId(), $listName, function () use ($segment, $listConfig, $listName, $groupRemoteId): void {
                /** @var NewsletterDriverInterface&SegmentExportCapableInterface $driver */
                $driver = $this->registry->getDriverForList($listName);

                $remoteId = $this->remoteIds->getRemoteId($segment, $listConfig->connectorName, $listName);
                $newId = $driver->exportSegment(
                    $listConfig->providerListId,
                    $groupRemoteId,
                    $segment->getNewsletterSegmentName(),
                    $remoteId,
                );

                if ($newId !== $remoteId) {
                    $this->remoteIds->setRemoteId($segment, $listConfig->connectorName, $listName, $newId);
                }
            });
        }
    }

    /**
     * Walks every group and segment for one list and makes the provider match.
     *
     * The event-driven export only ever sees the object that changed, so a dropped Messenger
     * message leaves a segment that exists here and nowhere else, and a delete that never arrived
     * leaves one there and nowhere here. Neither is visible from a single object; both are obvious
     * from a full pass. This is what the Customer Management Framework does on every CLI run
     * (Mailchimp::updateSegmentGroups + SegmentExporter::deleteNonExistingSegmentsFromGroup) — here
     * it is the recovery path rather than the normal one.
     *
     * @return array{groups: int, segments: int, removed_groups: int, removed_segments: int}
     */
    public function syncList(string $listName, bool $dryRun = false): array
    {
        $counts = ['groups' => 0, 'segments' => 0, 'removed_groups' => 0, 'removed_segments' => 0];

        if (!$this->supportsSegments($listName)) {
            return $counts;
        }

        $listConfig = $this->registry->getListConfig($listName);
        /** @var NewsletterDriverInterface&SegmentExportCapableInterface $driver */
        $driver = $this->registry->getDriverForList($listName);

        $keep = [];

        foreach ($this->segments->allGroups() as $group) {
            if (!\in_array($listName, $group->getNewsletterListNames(), true)) {
                continue;
            }

            $remoteId = $dryRun
                ? $this->remoteIds->getRemoteId($group, $listConfig->connectorName, $listName)
                : $this->upsertGroup($group, $listName);

            $counts['groups']++;

            if ($remoteId !== null && $remoteId !== '') {
                $keep[$remoteId] = [];
            }
        }

        foreach ($this->segmentsOfList($listName) as [$segment, $group]) {
            if (!$dryRun) {
                $this->exportSegment($segment);
            }

            $counts['segments']++;

            $groupRemoteId = $this->remoteIds->getRemoteId($group, $listConfig->connectorName, $listName);
            $segmentRemoteId = $this->remoteIds->getRemoteId($segment, $listConfig->connectorName, $listName);

            if ($groupRemoteId !== null && $segmentRemoteId !== null) {
                $keep[$groupRemoteId][$segmentRemoteId] = true;
            }
        }

        foreach ($driver->listSegmentGroups($listConfig->providerListId) as $remoteId => $name) {
            if (!isset($keep[$remoteId])) {
                $this->logger->notice('[OpenDXP Campaigns] Segment group {name} exists only at the provider.', [
                    'name' => $name,
                    'remote_id' => $remoteId,
                    'list_name' => $listName,
                    'dry_run' => $dryRun,
                ]);

                if (!$dryRun) {
                    $driver->deleteSegmentGroup($listConfig->providerListId, $remoteId);
                }

                $counts['removed_groups']++;

                continue;
            }

            foreach ($driver->listSegments($listConfig->providerListId, $remoteId) as $segmentRemoteId => $segmentName) {
                if (isset($keep[$remoteId][$segmentRemoteId])) {
                    continue;
                }

                $this->logger->notice('[OpenDXP Campaigns] Segment {name} exists only at the provider.', [
                    'name' => $segmentName,
                    'remote_id' => $segmentRemoteId,
                    'list_name' => $listName,
                    'dry_run' => $dryRun,
                ]);

                if (!$dryRun) {
                    $driver->deleteSegment($listConfig->providerListId, $remoteId, $segmentRemoteId);
                }

                $counts['removed_segments']++;
            }
        }

        return $counts;
    }

    /**
     * Every segment remote ID this list manages, so a member push can say which interests a member
     * does not have. Only what we exported ourselves — an interest somebody created at the provider
     * by hand is none of our business, which is how the Customer Management Framework draws the line
     * too (Mailchimp::buildCustomerSegmentData walks its own exportable segments).
     *
     * @return string[]
     */
    public function managedSegmentRemoteIds(string $listName): array
    {
        if (!$this->supportsSegments($listName)) {
            return [];
        }

        $listConfig = $this->registry->getListConfig($listName);
        $remoteIds = [];

        foreach ($this->segmentsOfList($listName) as [$segment]) {
            $remoteId = $this->remoteIds->getRemoteId($segment, $listConfig->connectorName, $listName);

            if ($remoteId !== null) {
                $remoteIds[] = $remoteId;
            }
        }

        return $remoteIds;
    }

    /**
     * Every segment this list exports, with the group it sits under.
     *
     * The group is yielded along because every caller needs it and asking the segment a second
     * time can throw — a segment lying outside a group is skipped here, once, for all of them.
     *
     * @return iterable<array{NewsletterSegmentInterface, NewsletterSegmentGroupInterface}>
     */
    public function segmentsOfList(string $listName): iterable
    {
        foreach ($this->segments->allSegments() as $segment) {
            $group = $this->groupOf($segment);

            if ($group === null || !\in_array($listName, $group->getNewsletterListNames(), true)) {
                continue;
            }

            yield [$segment, $group];
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
            /** @var NewsletterDriverInterface&SegmentExportCapableInterface $driver */
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
            /** @var NewsletterDriverInterface&SegmentExportCapableInterface $driver */
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
            /** @var NewsletterDriverInterface&SegmentExportCapableInterface $driver */
            $driver = $this->registry->getDriverForList($listName);

            $remoteId = $this->remoteIds->getRemoteId($group, $listConfig->connectorName, $listName);
            $newId = $driver->exportSegmentGroup(
                $listConfig->providerListId,
                $group->getNewsletterSegmentGroupName(),
                $remoteId,
            );

            if ($newId !== $remoteId) {
                $this->remoteIds->setRemoteId($group, $listConfig->connectorName, $listName, $newId);
            }

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

            /** @var NewsletterDriverInterface&SegmentExportCapableInterface $driver */
            $driver = $this->registry->getDriverForList($listName);
            $driver->deleteSegmentGroup($listConfig->providerListId, $remoteId);
            $this->remoteIds->removeRemoteId($group, $listConfig->connectorName, $listName);
        }
    }

    /**
     * The group a segment sits under, or null when it sits under none.
     *
     * A single-object export may fail loudly — somebody just saved that segment and can move it.
     * A pass over every segment may not: one object in the wrong place would otherwise stop the
     * sweep and, through the managed interests, every member push with it.
     */
    private function groupOf(NewsletterSegmentInterface $segment): ?NewsletterSegmentGroupInterface
    {
        try {
            return $segment->getNewsletterSegmentGroup();
        } catch (SegmentPlacementException $exception) {
            $this->logger->warning('[OpenDXP Campaigns] {message} Skipping it.', [
                'message' => $exception->getMessage(),
                'segment_id' => $segment->getId(),
            ]);

            return null;
        }
    }

    private function supportsSegments(string $listName): bool
    {
        try {
            $driver = $this->registry->getDriverForList($listName);
        } catch (ListNotFoundException) {
            // A group may still name a list that was removed from the configuration. Skipping it
            // keeps the remaining lists exportable instead of losing the whole run to one stale name.
            $this->logger->warning(
                '[OpenDXP Campaigns] List "{list}" is no longer configured; skipping segment export for it.',
                ['list' => $listName],
            );

            return false;
        }

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
        $lock = $this->lockFactory->createLock(\sprintf('opendxp_campaigns_%s_%s_%s', $kind, $objectId, $listName));
        $lock->acquire(true);

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}

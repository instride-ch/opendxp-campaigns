<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Newsletter;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentGroupInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\SegmentExportCapableInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\ListConfig;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\RemoteIdStore;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\SegmentProviderInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\EmptySegmentProvider;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\SegmentPlacementException;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\SegmentExporter;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class SegmentExporterTest extends Unit
{
    /** @var NewsletterDriverInterface&SegmentExportCapableInterface&MockObject */
    private $driver;
    private RemoteIdStore&MockObject $remoteIds;
    private SegmentExporter $exporter;

    private DriverRegistry $registry;

    protected function setUp(): void
    {
        $this->driver = $this->createMockForIntersectionOfInterfaces([
            NewsletterDriverInterface::class,
            SegmentExportCapableInterface::class,
        ]);
        $this->remoteIds = $this->createMock(RemoteIdStore::class);

        $registry = new DriverRegistry(
            connectors: ['main' => $this->driver],
            listConfigs: [
                'default_newsletter' => new ListConfig('default_newsletter', 'main', 'abc123', 'Default'),
                'product_updates' => new ListConfig('product_updates', 'main', 'def456', 'Products'),
            ],
        );

        $this->registry = $registry;
        $this->exporter = new SegmentExporter(
            $registry,
            $this->remoteIds,
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
            $this->createMock(SegmentProviderInterface::class),
        );
    }

    public function testExportGroupCreatesInterestCategoryAndStoresRemoteId(): void
    {
        $group = $this->createMock(NewsletterSegmentGroupInterface::class);
        $group->method('getId')->willReturn(5);
        $group->method('getNewsletterSegmentGroupName')->willReturn('Sports');
        $group->method('getNewsletterListNames')->willReturn(['default_newsletter']);

        // Not yet exported anywhere.
        $this->remoteIds->method('getRemoteId')->willReturn(null);

        $this->driver
            ->expects($this->once())
            ->method('exportSegmentGroup')
            ->with('abc123', 'Sports', null)
            ->willReturn('cat_1');

        $this->remoteIds
            ->expects($this->once())
            ->method('setRemoteId')
            ->with($group, 'main', 'default_newsletter', 'cat_1');

        // No stored id on the non-targeted list, so nothing to reconcile away.
        $this->driver->expects($this->never())->method('deleteSegmentGroup');

        $this->exporter->exportGroup($group);
    }

    public function testExportGroupSkipsListsThatAreNoLongerConfigured(): void
    {
        $group = $this->createMock(NewsletterSegmentGroupInterface::class);
        $group->method('getId')->willReturn(7);
        $group->method('getNewsletterSegmentGroupName')->willReturn('Interests');
        $group->method('getNewsletterListNames')->willReturn(['gone_list', 'default_newsletter']);

        $this->remoteIds->method('getRemoteId')->willReturn(null);

        // Only the configured list reaches the provider; the stale name is skipped, not fatal.
        $this->driver
            ->expects($this->once())
            ->method('exportSegmentGroup')
            ->with('abc123', 'Interests', null)
            ->willReturn('cat_1');

        $this->exporter->exportGroup($group);
    }

    public function testExportGroupReconcilesDeTargetedList(): void
    {
        $group = $this->createMock(NewsletterSegmentGroupInterface::class);
        $group->method('getId')->willReturn(5);
        $group->method('getNewsletterSegmentGroupName')->willReturn('Sports');
        // Group now targets only default_newsletter; product_updates was dropped.
        $group->method('getNewsletterListNames')->willReturn(['default_newsletter']);

        $this->remoteIds->method('getRemoteId')->willReturnMap([
            [$group, 'main', 'default_newsletter', null],
            [$group, 'main', 'product_updates', 'cat_old'],
        ]);

        $this->driver->method('exportSegmentGroup')->willReturn('cat_1');

        // The de-targeted list's category is deleted and its stored id removed.
        $this->driver
            ->expects($this->once())
            ->method('deleteSegmentGroup')
            ->with('def456', 'cat_old');

        $this->remoteIds
            ->expects($this->once())
            ->method('removeRemoteId')
            ->with($group, 'main', 'product_updates');

        $this->exporter->exportGroup($group);
    }

    public function testExportSegmentEnsuresGroupThenExportsSegment(): void
    {
        $group = $this->createMock(NewsletterSegmentGroupInterface::class);
        $group->method('getId')->willReturn(5);
        $group->method('getNewsletterSegmentGroupName')->willReturn('Sports');
        $group->method('getNewsletterListNames')->willReturn(['default_newsletter']);

        $segment = $this->createMock(NewsletterSegmentInterface::class);
        $segment->method('getId')->willReturn(9);
        $segment->method('getNewsletterSegmentName')->willReturn('Tennis');
        $segment->method('getNewsletterSegmentGroup')->willReturn($group);

        // Group already exported (has a remote id); segment not yet.
        $this->remoteIds->method('getRemoteId')->willReturnMap([
            [$group, 'main', 'default_newsletter', 'cat_1'],
            [$segment, 'main', 'default_newsletter', null],
        ]);

        // Group already has an id, so it is NOT re-exported.
        $this->driver->expects($this->never())->method('exportSegmentGroup');

        $this->driver
            ->expects($this->once())
            ->method('exportSegment')
            ->with('abc123', 'cat_1', 'Tennis', null)
            ->willReturn('int_7');

        $this->remoteIds
            ->expects($this->once())
            ->method('setRemoteId')
            ->with($segment, 'main', 'default_newsletter', 'int_7');

        $this->exporter->exportSegment($segment);
    }

    public function testDeleteGroupDelegatesPerCapturedList(): void
    {
        $this->driver
            ->expects($this->once())
            ->method('deleteSegmentGroup')
            ->with('abc123', 'cat_1');

        $this->exporter->deleteGroup(['default_newsletter' => 'cat_1']);
    }

    public function testTheSweepTakesAwayWhatOnlyTheProviderHolds(): void
    {
        $exporter = $this->exporterWith([], []);

        $this->driver->method('listSegmentGroups')->willReturn(['cat_stray' => 'Left over']);
        $this->driver->expects($this->once())->method('deleteSegmentGroup')->with('abc123', 'cat_stray');

        $counts = $exporter->syncList('default_newsletter');

        $this->assertSame(1, $counts['removed_groups']);
    }

    public function testTheSweepExportsEveryGroupAndSegmentOfTheList(): void
    {
        $group = $this->createMock(NewsletterSegmentGroupInterface::class);
        $group->method('getId')->willReturn(5);
        $group->method('getNewsletterSegmentGroupName')->willReturn('Interests');
        $group->method('getNewsletterListNames')->willReturn(['default_newsletter']);

        $segment = $this->createMock(NewsletterSegmentInterface::class);
        $segment->method('getId')->willReturn(9);
        $segment->method('getNewsletterSegmentName')->willReturn('Facade');
        $segment->method('getNewsletterSegmentGroup')->willReturn($group);

        $exporter = $this->exporterWith([$group], [$segment]);

        $this->remoteIds->method('getRemoteId')->willReturn(null);
        $this->driver->method('exportSegmentGroup')->willReturn('cat_1');
        $this->driver->expects($this->once())->method('exportSegment')->willReturn('int_1');
        $this->driver->method('listSegmentGroups')->willReturn([]);

        $counts = $exporter->syncList('default_newsletter');

        $this->assertSame(1, $counts['groups']);
        $this->assertSame(1, $counts['segments']);
    }

    public function testADryRunReportsWithoutTouchingAnything(): void
    {
        $exporter = $this->exporterWith([], []);

        $this->driver->method('listSegmentGroups')->willReturn(['cat_stray' => 'Left over']);
        $this->driver->expects($this->never())->method('deleteSegmentGroup');
        $this->driver->expects($this->never())->method('exportSegmentGroup');

        $counts = $exporter->syncList('default_newsletter', dryRun: true);

        $this->assertSame(1, $counts['removed_groups']);
    }

    public function testOnlySegmentsOfThisListCountAsManaged(): void
    {
        $ours = $this->createMock(NewsletterSegmentGroupInterface::class);
        $ours->method('getNewsletterListNames')->willReturn(['default_newsletter']);
        $theirs = $this->createMock(NewsletterSegmentGroupInterface::class);
        $theirs->method('getNewsletterListNames')->willReturn(['product_updates']);

        $mine = $this->createMock(NewsletterSegmentInterface::class);
        $mine->method('getNewsletterSegmentGroup')->willReturn($ours);
        $other = $this->createMock(NewsletterSegmentInterface::class);
        $other->method('getNewsletterSegmentGroup')->willReturn($theirs);

        $exporter = $this->exporterWith([], [$mine, $other]);
        $this->remoteIds->method('getRemoteId')->willReturn('int_1');
        $this->providerHolds(['cat_1' => ['int_1']]);

        // The other list's segment must not end up in the payload that switches interests off.
        $this->assertSame(['int_1'], $exporter->managedSegmentRemoteIds('default_newsletter'));
    }

    /**
     * @param NewsletterSegmentGroupInterface[] $groups
     * @param NewsletterSegmentInterface[]      $segments
     */
    private function exporterWith(array $groups, array $segments): SegmentExporter
    {
        $provider = $this->createMock(SegmentProviderInterface::class);
        $provider->method('allGroups')->willReturn($groups);
        $provider->method('allSegments')->willReturn($segments);

        return new SegmentExporter(
            $this->registry,
            $this->remoteIds,
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
            $provider,
        );
    }

    public function testASegmentOutsideAnyGroupDoesNotStopTheSweep(): void
    {
        $placed = $this->segmentUnderGroup('Sports', 'default_newsletter');
        $stray = $this->createMock(NewsletterSegmentInterface::class);
        $stray->method('getNewsletterSegmentGroup')
            ->willThrowException(SegmentPlacementException::forPath('/newsletter/stray'));

        $exporter = $this->exporterWithSegments([$stray, $placed]);

        $this->driver->method('listSegmentGroups')->willReturn([]);

        $counts = $exporter->syncList('default_newsletter', dryRun: true);

        $this->assertSame(1, $counts['segments']);
    }

    public function testManagedInterestsAreEmptyWhenNoSegmentClassesAreConfigured(): void
    {
        $exporter = new SegmentExporter(
            $this->registry,
            $this->remoteIds,
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
            new EmptySegmentProvider(),
        );

        $this->assertSame([], $exporter->managedSegmentRemoteIds('default_newsletter'));
    }

    /**
     * @param NewsletterSegmentInterface[] $segments
     */
    private function exporterWithSegments(array $segments): SegmentExporter
    {
        $provider = $this->createMock(SegmentProviderInterface::class);
        $provider->method('allGroups')->willReturn([]);
        $provider->method('allSegments')->willReturn($segments);

        return new SegmentExporter(
            $this->registry,
            $this->remoteIds,
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
            $provider,
        );
    }

    private function segmentUnderGroup(string $name, string $listName): NewsletterSegmentInterface&MockObject
    {
        $group = $this->createMock(NewsletterSegmentGroupInterface::class);
        $group->method('getNewsletterListNames')->willReturn([$listName]);

        $segment = $this->createMock(NewsletterSegmentInterface::class);
        $segment->method('getNewsletterSegmentName')->willReturn($name);
        $segment->method('getNewsletterSegmentGroup')->willReturn($group);

        return $segment;
    }

    /**
     * @param array<string, string[]> $categories provider category ID => interest IDs it holds
     */
    private function providerHolds(array $categories): void
    {
        $groups = [];

        foreach ($categories as $categoryId => $interestIds) {
            $groups[$categoryId] = 'Category ' . $categoryId;
        }

        $this->driver->method('listSegmentGroups')->willReturn($groups);
        $this->driver->method('listSegments')->willReturnCallback(
            static fn (string $listId, string $categoryId): array => \array_fill_keys($categories[$categoryId] ?? [], 'name'),
        );
    }
}

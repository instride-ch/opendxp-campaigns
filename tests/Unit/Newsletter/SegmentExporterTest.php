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

        $this->exporter = new SegmentExporter(
            $registry,
            $this->remoteIds,
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
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
}
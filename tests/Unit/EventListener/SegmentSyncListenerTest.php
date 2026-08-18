<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\EventListener;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentGroupInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\EventListener\SegmentSyncListener;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\OutboundSyncSuppressor;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\RemoteIdStore;
use OpenDxp\Event\Model\DataObjectEvent;
use OpenDxp\Model\DataObject\Concrete;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

abstract class SyncListenerTestGroup extends Concrete implements NewsletterSegmentGroupInterface {}

/**
 * newsletter-segmentation §6.1 requires the listener to respect the suppressor, so a save
 * caused by an inbound provider sync is not echoed straight back to the provider.
 */
class SegmentSyncListenerTest extends Unit
{
    private MessageBusInterface&MockObject $bus;
    private OutboundSyncSuppressor $suppressor;

    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(
            static fn (object $message): Envelope => new Envelope($message),
        );
        $this->suppressor = new OutboundSyncSuppressor();
    }

    public function testSkipsWhenSuppressed(): void
    {
        $group = $this->buildGroup();

        $this->bus->expects($this->never())->method('dispatch');

        $listener = $this->createListener();
        $this->suppressor->suppress(function () use ($listener, $group): void {
            $listener->onPostWrite(new DataObjectEvent($group));
        });
    }

    public function testDispatchesWhenNotSuppressed(): void
    {
        $group = $this->buildGroup();

        $this->bus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $this->createListener()->onPostWrite(new DataObjectEvent($group));
    }

    private function buildGroup(): SyncListenerTestGroup&MockObject
    {
        $group = $this->createMock(SyncListenerTestGroup::class);
        $group->method('getId')->willReturn(9);
        $group->method('isPublished')->willReturn(true);

        return $group;
    }

    private function createListener(): SegmentSyncListener
    {
        return new SegmentSyncListener(
            $this->createMock(DriverRegistry::class),
            $this->createMock(RemoteIdStore::class),
            $this->bus,
            new NullLogger(),
            $this->suppressor,
        );
    }
}

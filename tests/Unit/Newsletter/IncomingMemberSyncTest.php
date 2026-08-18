<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Newsletter;

use Carbon\Carbon;
use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\ListConfig;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\MergeFieldMapping;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Instride\Bundle\OpenDxpCampaignsBundle\Event\MemberSubscriptionStatusChangedEvent;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\IncomingMemberSync;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\MergeFieldMapper;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Members operated on by the sync implement NewsletterMemberInterface *and* expose
 * app-specific merge-field accessors resolved dynamically (getFirstname/setFirstname).
 * Neither lives on the interface, so we declare a local double type here.
 */
interface SyncTestMember extends NewsletterMemberInterface
{
    public function getFirstname(): ?string;

    public function setFirstname(?string $firstname): void;
}

class IncomingMemberSyncTest extends Unit
{
    private const string LIST = 'default_newsletter';
    private const string CONNECTOR = 'main';

    private EventDispatcherInterface&MockObject $eventDispatcher;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    }

    // -------------------------------------------------------------------------
    // applyStatus
    // -------------------------------------------------------------------------

    public function testApplyStatusUpdatesSetsSyncDateAndDispatchesWhenChanged(): void
    {
        $member = $this->createMock(SyncTestMember::class);
        $member->method('getNewsletterSubscriptionStatus')->with(self::LIST)
            ->willReturn(SubscriptionStatus::UNSUBSCRIBED);

        $member->expects($this->once())
            ->method('setNewsletterSubscriptionStatus')
            ->with(self::LIST, SubscriptionStatus::SUBSCRIBED);
        $member->expects($this->once())
            ->method('setNewsletterLastSyncDate')
            ->with(self::LIST, $this->isInstanceOf(Carbon::class));

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (MemberSubscriptionStatusChangedEvent $event): bool {
                return $event->getListName() === self::LIST
                    && $event->getPreviousStatus() === SubscriptionStatus::UNSUBSCRIBED
                    && $event->getNewStatus() === SubscriptionStatus::SUBSCRIBED
                    && $event->getSource() === 'sync.pull';
            }));

        $sync = $this->buildSync();
        $result = $sync->applyStatus($member, self::LIST, SubscriptionStatus::SUBSCRIBED, 'jane@example.com', 'sync.pull');

        $this->assertTrue($result);
    }

    public function testApplyStatusIsNoOpWhenBothStatusesUnchanged(): void
    {
        $member = $this->createMock(SyncTestMember::class);
        $member->method('getNewsletterSubscriptionStatus')->with(self::LIST)
            ->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderStatus')->with(self::LIST)
            ->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderEmail')->with(self::LIST)
            ->willReturn('jane@example.com');

        $member->expects($this->never())->method('setNewsletterSubscriptionStatus');
        $member->expects($this->never())->method('setNewsletterProviderStatus');
        $member->expects($this->never())->method('setNewsletterProviderEmail');
        $member->expects($this->never())->method('setNewsletterLastSyncDate');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $sync = $this->buildSync();
        $result = $sync->applyStatus($member, self::LIST, SubscriptionStatus::SUBSCRIBED, 'jane@example.com', 'sync.pull');

        $this->assertFalse($result);
    }

    public function testApplyStatusRecordsTheAddressTheProviderAnsweredUnder(): void
    {
        $member = $this->createMock(SyncTestMember::class);
        $member->method('getNewsletterSubscriptionStatus')->with(self::LIST)
            ->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderStatus')->with(self::LIST)
            ->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderEmail')->with(self::LIST)->willReturn(null);

        // Arms the push path to clean up after an address change on members that were only pulled.
        $member->expects($this->once())->method('setNewsletterProviderEmail')
            ->with(self::LIST, 'jane@example.com');

        $sync = $this->buildSync();

        $this->assertTrue(
            $sync->applyStatus($member, self::LIST, SubscriptionStatus::SUBSCRIBED, 'jane@example.com', 'sync.pull'),
        );
    }

    public function testApplyStatusRecordsProviderStatusEvenWhenOwnStatusUnchanged(): void
    {
        $member = $this->createMock(SyncTestMember::class);
        $member->method('getNewsletterSubscriptionStatus')->with(self::LIST)
            ->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderStatus')->with(self::LIST)->willReturn(null);

        $member->expects($this->once())->method('setNewsletterProviderStatus')
            ->with(self::LIST, SubscriptionStatus::SUBSCRIBED);
        $member->expects($this->never())->method('setNewsletterSubscriptionStatus');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $sync = $this->buildSync();

        $this->assertTrue($sync->applyStatus($member, self::LIST, SubscriptionStatus::SUBSCRIBED, 'jane@example.com', 'sync.pull'));
    }

    // -------------------------------------------------------------------------
    // applyMergeFields
    // -------------------------------------------------------------------------

    public function testApplyMergeFieldsSetsChangedValueAndBumpsSyncDate(): void
    {
        $member = $this->createMock(SyncTestMember::class);
        $member->method('getFirstname')->willReturn('John');

        $member->expects($this->once())->method('setFirstname')->with('Jane');
        $member->expects($this->once())
            ->method('setNewsletterLastSyncDate')
            ->with(self::LIST, $this->isInstanceOf(Carbon::class));

        $sync = $this->buildSync(['firstname' => new MergeFieldMapping('firstname', 'FNAME')]);
        $result = $sync->applyMergeFields($member, self::LIST, ['FNAME' => 'Jane']);

        $this->assertTrue($result);
    }

    public function testApplyMergeFieldsSkipsUnchangedValue(): void
    {
        $member = $this->createMock(SyncTestMember::class);
        $member->method('getFirstname')->willReturn('Jane');

        $member->expects($this->never())->method('setFirstname');
        $member->expects($this->never())->method('setNewsletterLastSyncDate');

        $sync = $this->buildSync(['firstname' => new MergeFieldMapping('firstname', 'FNAME')]);
        $result = $sync->applyMergeFields($member, self::LIST, ['FNAME' => 'Jane']);

        $this->assertFalse($result);
    }

    public function testApplyMergeFieldsReturnsFalseWhenListHasNoMappings(): void
    {
        $member = $this->createMock(SyncTestMember::class);
        $member->expects($this->never())->method('setFirstname');

        $sync = $this->buildSync();
        $result = $sync->applyMergeFields($member, self::LIST, ['FNAME' => 'Jane']);

        $this->assertFalse($result);
    }

    public function testApplyMergeFieldsReturnsFalseWhenPayloadEmpty(): void
    {
        $member = $this->createMock(SyncTestMember::class);
        $member->expects($this->never())->method('setFirstname');

        $sync = $this->buildSync(['firstname' => new MergeFieldMapping('firstname', 'FNAME')]);
        $result = $sync->applyMergeFields($member, self::LIST, []);

        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, MergeFieldMapping> $mergeFieldMappings
     */
    private function buildSync(array $mergeFieldMappings = []): IncomingMemberSync
    {
        $listConfig = new ListConfig(
            identifier: self::LIST,
            connectorName: self::CONNECTOR,
            providerListId: 'abc123',
            label: 'Default Newsletter',
            mergeFieldMappings: $mergeFieldMappings,
        );

        $registry = new DriverRegistry(
            connectors: [],
            listConfigs: [self::LIST => $listConfig],
        );

        return new IncomingMemberSync($registry, new MergeFieldMapper(), $this->eventDispatcher);
    }
}
<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Newsletter;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\ListConfig;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\MergeFieldMapping;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentGroupInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\MergeFieldMapper;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\NewsletterManager;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\OutboundSyncSuppressor;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\RemoteIdStore;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\ManagedSegmentInterestsInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use function Symfony\Component\String\u;

/**
 * Members carry app-specific merge-field getters (getFirstname/getLastname) resolved
 * dynamically by MergeFieldMapper. These do not live on NewsletterMemberInterface, so
 * we declare a local test-double type that exposes exactly the surface these tests touch.
 */
interface ManagerTestMember extends NewsletterMemberInterface
{
    public function getFirstname(): ?string;

    public function getLastname(): ?string;
}

class NewsletterManagerTest extends Unit
{
    private NewsletterDriverInterface $driver;
    private DriverRegistry $registry;
    private NewsletterManager $manager;
    private ListConfig $listConfig;
    private MergeFieldMapper $mapper;
    private RemoteIdStore&MockObject $remoteIds;

    protected function setUp(): void
    {
        $this->driver = $this->createMock(NewsletterDriverInterface::class);
        $this->mapper = new MergeFieldMapper();
        $this->remoteIds = $this->createMock(RemoteIdStore::class);

        $this->listConfig = new ListConfig(
            identifier: 'default_newsletter',
            connectorName: 'main',
            providerListId: 'abc123',
            label: 'Default Newsletter',
        );

        $this->registry = new DriverRegistry(
            connectors: ['main' => $this->driver],
            listConfigs: ['default_newsletter' => $this->listConfig],
        );

        $this->manager = new NewsletterManager($this->registry, 'default_newsletter', $this->mapper, $this->remoteIds, new OutboundSyncSuppressor(), new NullLogger(), $this->segmentExporter());
    }

    public function testSubscribeWithStringEmail(): void
    {
        $this->driver
            ->expects($this->once())
            ->method('subscribeOrUpdate')
            ->with('abc123', 'jane@example.com', [], [], SubscriptionStatus::SUBSCRIBED);

        $this->manager->subscribe('jane@example.com');
    }

    public function testSubscribeWithMemberObjectAndNoMappings(): void
    {
        $member = $this->buildMember('jane@example.com', []);

        $this->driver
            ->expects($this->once())
            ->method('subscribeOrUpdate')
            ->with('abc123', 'jane@example.com', [], [], SubscriptionStatus::SUBSCRIBED);

        $this->manager->subscribe($member);
    }

    public function testSubscribeWithMemberObjectAndMappings(): void
    {
        $listConfigWithMappings = new ListConfig(
            identifier: 'default_newsletter',
            connectorName: 'main',
            providerListId: 'abc123',
            label: 'Default Newsletter',
            mergeFieldMappings: [
                'firstname' => new MergeFieldMapping('firstname', 'FNAME'),
                'lastname' => new MergeFieldMapping('lastname', 'LNAME'),
            ],
        );

        $registry = new DriverRegistry(
            connectors: ['main' => $this->driver],
            listConfigs: ['default_newsletter' => $listConfigWithMappings],
        );
        $manager = new NewsletterManager($registry, 'default_newsletter', $this->mapper, $this->remoteIds, new OutboundSyncSuppressor(), new NullLogger(), $this->segmentExporter());

        $member = $this->buildMember('jane@example.com', ['firstname' => 'Jane', 'lastname' => 'Doe']);

        $this->driver
            ->expects($this->once())
            ->method('subscribeOrUpdate')
            ->with('abc123', 'jane@example.com', ['FNAME' => 'Jane', 'LNAME' => 'Doe'], [], SubscriptionStatus::SUBSCRIBED);

        $manager->subscribe($member);
    }

    public function testSubscribeWithExplicitListName(): void
    {
        $this->driver
            ->expects($this->once())
            ->method('subscribeOrUpdate')
            ->with('abc123', 'jane@example.com');

        $this->manager->subscribe('jane@example.com', 'default_newsletter');
    }

    public function testUnsubscribeDelegatesToDriver(): void
    {
        $this->driver
            ->expects($this->once())
            ->method('unsubscribe')
            ->with('abc123', 'jane@example.com');

        $this->manager->unsubscribe('jane@example.com');
    }

    public function testSubscribeOrUpdateDelegatesToDriver(): void
    {
        $this->driver
            ->expects($this->once())
            ->method('subscribeOrUpdate')
            ->with('abc123', 'jane@example.com', [], []);

        $this->manager->subscribeOrUpdate('jane@example.com');
    }

    public function testDeleteDelegatesToDriver(): void
    {
        $this->driver
            ->expects($this->once())
            ->method('delete')
            ->with('abc123', 'jane@example.com');

        $this->manager->delete('jane@example.com');
    }

    public function testHasMemberDelegatesToDriver(): void
    {
        $this->driver->method('hasMember')->with('abc123', 'jane@example.com')->willReturn(true);

        $this->assertTrue($this->manager->hasMember('jane@example.com', 'default_newsletter'));
    }

    public function testIsSubscribedDelegatesToDriver(): void
    {
        $this->driver->method('isSubscribed')->with('abc123', 'jane@example.com')->willReturn(false);

        $this->assertFalse($this->manager->isSubscribed('jane@example.com', 'default_newsletter'));
    }

    public function testGetMemberDelegatesToDriver(): void
    {
        $this->driver->method('getMember')->with('abc123', 'jane@example.com')->willReturn(['email_address' => 'jane@example.com']);

        $result = $this->manager->getMember('jane@example.com', 'default_newsletter');

        $this->assertSame(['email_address' => 'jane@example.com'], $result);
    }

    public function testSyncMemberToListCallsSubscribeOrUpdateForSubscribedStatus(): void
    {
        $member = $this->buildMember('jane@example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->with('default_newsletter')
            ->willReturn(SubscriptionStatus::SUBSCRIBED);

        $this->driver
            ->expects($this->once())
            ->method('subscribeOrUpdate')
            ->with('abc123', 'jane@example.com', [], [], SubscriptionStatus::SUBSCRIBED);

        $this->manager->syncMemberToList($member, 'default_newsletter');
    }

    public function testSyncMemberToListMapsMergeFieldsViaConfig(): void
    {
        $listConfigWithMappings = new ListConfig(
            identifier: 'default_newsletter',
            connectorName: 'main',
            providerListId: 'abc123',
            label: 'Default Newsletter',
            mergeFieldMappings: [
                'firstname' => new MergeFieldMapping('firstname', 'FNAME'),
            ],
        );

        $registry = new DriverRegistry(
            connectors: ['main' => $this->driver],
            listConfigs: ['default_newsletter' => $listConfigWithMappings],
        );
        $manager = new NewsletterManager($registry, 'default_newsletter', $this->mapper, $this->remoteIds, new OutboundSyncSuppressor(), new NullLogger(), $this->segmentExporter());

        $member = $this->buildMember('jane@example.com', ['firstname' => 'Jane']);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);

        $this->driver
            ->expects($this->once())
            ->method('subscribeOrUpdate')
            ->with('abc123', 'jane@example.com', ['FNAME' => 'Jane'], [], SubscriptionStatus::SUBSCRIBED);

        $manager->syncMemberToList($member, 'default_newsletter');
    }

    public function testSyncMemberToListCallsUnsubscribeForUnsubscribedStatus(): void
    {
        $member = $this->buildMember('jane@example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->with('default_newsletter')
            ->willReturn(SubscriptionStatus::UNSUBSCRIBED);
        $this->driver->expects($this->once())->method('unsubscribe')->with('abc123', 'jane@example.com');
        $this->driver->expects($this->never())->method('subscribeOrUpdate');

        $this->manager->syncMemberToList($member, 'default_newsletter');
    }

    /**
     * The Customer Management Framework has no mapping entry for an empty status and falls back
     * to unsubscribed, so a member we hold no status for must not be treated as subscribable.
     */
    public function testSyncMemberToListTreatsAnUnknownStatusAsUnsubscribed(): void
    {
        $member = $this->buildMember('jane@example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(null);
        $member->method('getNewsletterProviderStatus')->willReturn(null);

        $this->driver->expects($this->once())->method('unsubscribe')->with('abc123', 'jane@example.com');
        $this->driver->expects($this->never())->method('subscribeOrUpdate');

        $this->manager->syncMemberToList($member, 'default_newsletter');
    }

    public function testSyncMemberToListLeavesProviderStatusAloneWhenItAlreadyMatches(): void
    {
        $member = $this->buildMember('jane@example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);

        $this->driver
            ->expects($this->once())
            ->method('subscribeOrUpdate')
            ->with('abc123', 'jane@example.com', [], [], SubscriptionStatus::SUBSCRIBED, false);

        $this->manager->syncMemberToList($member, 'default_newsletter');
    }

    public function testSyncMemberToListRecordsWhatTheProviderNowHolds(): void
    {
        $member = $this->buildMember('jane@example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderStatus')->willReturn(null);

        // Without this the comparison only starts working after the first pull.
        $member->expects($this->once())->method('setNewsletterProviderStatus')
            ->with('default_newsletter', SubscriptionStatus::SUBSCRIBED);
        $member->expects($this->once())->method('setNewsletterProviderEmail')
            ->with('default_newsletter', 'jane@example.com');
        $member->expects($this->once())->method('save');

        $this->manager->syncMemberToList($member, 'default_newsletter');
    }

    public function testSyncMemberToListDoesNotSaveWhenTheProviderStatusIsUnchanged(): void
    {
        $member = $this->buildMember('jane@example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderEmail')->willReturn('jane@example.com');

        $member->expects($this->never())->method('setNewsletterProviderStatus');
        $member->expects($this->never())->method('save');

        $this->manager->syncMemberToList($member, 'default_newsletter');
    }

    public function testChangedEmailDropsTheEntryLeftBehindAndRecreatesIt(): void
    {
        $member = $this->buildMember('new@example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderEmail')->willReturn('old@example.com');

        $this->driver
            ->expects($this->once())
            ->method('archive')
            ->with('abc123', 'old@example.com');

        // The provider holds nothing for the new address, so the status has to be written along.
        $this->driver
            ->expects($this->once())
            ->method('subscribeOrUpdate')
            ->with('abc123', 'new@example.com', [], [], SubscriptionStatus::SUBSCRIBED, true);

        $member->expects($this->once())->method('setNewsletterProviderEmail')
            ->with('default_newsletter', 'new@example.com');

        $this->manager->syncMemberToList($member, 'default_newsletter');
    }

    public function testADifferentSpellingOfTheSameAddressIsNotTreatedAsAChange(): void
    {
        $member = $this->buildMember('Jane@Example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderEmail')->willReturn('jane@example.com');

        $this->driver->expects($this->never())->method('archive');

        $this->manager->syncMemberToList($member, 'default_newsletter');
    }

    public function testAnUnsubscribedMemberWhoseAddressChangedIsOnlyDropped(): void
    {
        $member = $this->buildMember('new@example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(SubscriptionStatus::UNSUBSCRIBED);
        $member->method('getNewsletterProviderStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);
        $member->method('getNewsletterProviderEmail')->willReturn('old@example.com');

        $this->driver->expects($this->once())->method('archive')->with('abc123', 'old@example.com');

        // Unsubscribing the new address would ask the provider about someone it never heard of.
        $this->driver->expects($this->never())->method('unsubscribe');
        $this->driver->expects($this->never())->method('subscribeOrUpdate');

        $this->manager->syncMemberToList($member, 'default_newsletter');
    }

    public function testSyncMemberToListDoesNotUnsubscribeWhenProviderAlreadyUnsubscribed(): void
    {
        $member = $this->buildMember('jane@example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(SubscriptionStatus::UNSUBSCRIBED);
        $member->method('getNewsletterProviderStatus')->willReturn(SubscriptionStatus::UNSUBSCRIBED);

        $this->driver->expects($this->never())->method('unsubscribe');

        $this->manager->syncMemberToList($member, 'default_newsletter');
    }

    public function testSyncMemberIteratesAllLists(): void
    {
        $member = $this->buildMember('jane@example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);

        $this->driver->expects($this->once())->method('subscribeOrUpdate');

        $this->manager->syncMember($member);
    }

    public function testInterestIdsResolvedFromStoreOnlyForTargetedLists(): void
    {
        $group = $this->createMock(NewsletterSegmentGroupInterface::class);
        $group->method('getNewsletterListNames')->willReturn(['default_newsletter']);

        $targetedSegment = $this->createMock(NewsletterSegmentInterface::class);
        $targetedSegment->method('getNewsletterSegmentGroup')->willReturn($group);

        // Segment whose group targets a different list — must be excluded.
        $otherGroup = $this->createMock(NewsletterSegmentGroupInterface::class);
        $otherGroup->method('getNewsletterListNames')->willReturn(['product_updates']);
        $otherSegment = $this->createMock(NewsletterSegmentInterface::class);
        $otherSegment->method('getNewsletterSegmentGroup')->willReturn($otherGroup);

        $member = $this->buildMember('jane@example.com', [], [$targetedSegment, $otherSegment]);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(SubscriptionStatus::SUBSCRIBED);

        $this->remoteIds
            ->method('getRemoteId')
            ->with($targetedSegment, 'main', 'default_newsletter')
            ->willReturn('interest_123');

        $this->driver
            ->expects($this->once())
            ->method('subscribeOrUpdate')
            ->with('abc123', 'jane@example.com', [], ['interest_123'], SubscriptionStatus::SUBSCRIBED);

        $this->managerManaging(['interest_123'])->syncMemberToList($member, 'default_newsletter');
    }

    public function testNoListNameAndNoDefaultThrowsLogicException(): void
    {
        $managerWithoutDefault = new NewsletterManager($this->registry, null, $this->mapper, $this->remoteIds, new OutboundSyncSuppressor(), new NullLogger(), $this->segmentExporter());

        $this->expectException(\LogicException::class);
        $managerWithoutDefault->subscribe('jane@example.com');
    }

    // -------------------------------------------------------------------------

    /**
     * Segments are stubbed here rather than by the caller: PHPUnit keeps the first
     * matching stub's return value, so a second stub for the same method never applies.
     *
     * @param array<string, mixed>              $attributes localField → value, matched by getter name
     * @param list<NewsletterSegmentInterface>  $segments
     */
    private function buildMember(string $email, array $attributes, array $segments = []): ManagerTestMember&MockObject
    {
        $member = $this->createMock(ManagerTestMember::class);
        $member->method('getNewsletterEmail')->willReturn($email);
        $member->method('getNewsletterSegments')->willReturn($segments);

        foreach ($attributes as $field => $value) {
            $getter = u($field)
                ->pascal()
                ->ensureStart('get')
                ->toString();
            $member->method($getter)->willReturn($value);
        }

        return $member;
    }

    /**
     * The manager asks it once per list for the segments this list manages; nothing here has any.
     */
    /**
     * The manager asks it once per list which interests it manages. Everything a member carries is
     * checked against that answer, so a test that expects an interest to travel has to name it.
     *
     * @param string[] $managed
     */
    private function segmentExporter(array $managed = []): ManagedSegmentInterestsInterface
    {
        $exporter = $this->createMock(ManagedSegmentInterestsInterface::class);
        $exporter->method('managedSegmentRemoteIds')->willReturn($managed);

        return $exporter;
    }

    /**
     * @param string[] $managed
     */
    private function managerManaging(array $managed): NewsletterManager
    {
        return new NewsletterManager(
            $this->registry,
            'default_newsletter',
            $this->mapper,
            $this->remoteIds,
            new OutboundSyncSuppressor(),
            new NullLogger(),
            $this->segmentExporter($managed),
        );
    }

    public function testASignupRecordsTheSubscriptionOnTheMember(): void
    {
        $member = $this->buildMember('jane@example.com', []);

        $member->expects($this->once())
            ->method('setNewsletterSubscriptionStatus')
            ->with('default_newsletter', SubscriptionStatus::SUBSCRIBED);
        $member->expects($this->once())
            ->method('setNewsletterProviderStatus')
            ->with('default_newsletter', SubscriptionStatus::SUBSCRIBED);
        $member->expects($this->once())
            ->method('setNewsletterProviderEmail')
            ->with('default_newsletter', 'jane@example.com');
        $member->expects($this->once())->method('save');

        $this->manager->subscribe($member, 'default_newsletter');
    }

    public function testSubscribingByAddressAloneTouchesNoMember(): void
    {
        $this->driver
            ->expects($this->once())
            ->method('subscribeOrUpdate')
            ->with('abc123', 'walkin@example.com', [], [], SubscriptionStatus::SUBSCRIBED);

        $this->manager->subscribe('walkin@example.com', 'default_newsletter');
    }

    public function testUnsubscribingRecordsItOnTheMember(): void
    {
        $member = $this->buildMember('jane@example.com', []);

        $member->expects($this->once())
            ->method('setNewsletterSubscriptionStatus')
            ->with('default_newsletter', SubscriptionStatus::UNSUBSCRIBED);
        $member->expects($this->once())
            ->method('setNewsletterProviderStatus')
            ->with('default_newsletter', SubscriptionStatus::UNSUBSCRIBED);
        $member->expects($this->once())->method('save');

        $this->manager->unsubscribe($member, 'default_newsletter');
    }

    public function testDeletingRecordsItOnTheMember(): void
    {
        $member = $this->buildMember('jane@example.com', []);

        $member->expects($this->once())
            ->method('setNewsletterSubscriptionStatus')
            ->with('default_newsletter', SubscriptionStatus::UNSUBSCRIBED);
        $member->expects($this->once())->method('save');

        $this->manager->delete($member, 'default_newsletter');
    }

    public function testUnsubscribingByAddressAloneTouchesNoMember(): void
    {
        $this->driver->expects($this->once())->method('unsubscribe')->with('abc123', 'walkin@example.com');

        $this->manager->unsubscribe('walkin@example.com', 'default_newsletter');
    }
}

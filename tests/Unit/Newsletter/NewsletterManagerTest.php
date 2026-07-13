<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Newsletter;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\ListConfig;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\MergeFieldMapping;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\MergeFieldMapper;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\NewsletterManager;
use PHPUnit\Framework\MockObject\MockObject;
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

    protected function setUp(): void
    {
        $this->driver = $this->createMock(NewsletterDriverInterface::class);
        $this->mapper = new MergeFieldMapper();

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

        $this->manager = new NewsletterManager($this->registry, 'default_newsletter', $this->mapper);
    }

    public function testSubscribeWithStringEmail(): void
    {
        $this->driver
            ->expects($this->once())
            ->method('subscribe')
            ->with('abc123', 'jane@example.com', [], []);

        $this->manager->subscribe('jane@example.com');
    }

    public function testSubscribeWithMemberObjectAndNoMappings(): void
    {
        $member = $this->buildMember('jane@example.com', []);

        $this->driver
            ->expects($this->once())
            ->method('subscribe')
            ->with('abc123', 'jane@example.com', [], []);

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
        $manager = new NewsletterManager($registry, 'default_newsletter', $this->mapper);

        $member = $this->buildMember('jane@example.com', ['firstname' => 'Jane', 'lastname' => 'Doe']);

        $this->driver
            ->expects($this->once())
            ->method('subscribe')
            ->with('abc123', 'jane@example.com', ['FNAME' => 'Jane', 'LNAME' => 'Doe'], []);

        $manager->subscribe($member);
    }

    public function testSubscribeWithExplicitListName(): void
    {
        $this->driver
            ->expects($this->once())
            ->method('subscribe')
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
        $manager = new NewsletterManager($registry, 'default_newsletter', $this->mapper);

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

    public function testSyncMemberToListDefaultsToSubscribedWhenStatusIsNull(): void
    {
        $member = $this->buildMember('jane@example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(null);

        $this->driver
            ->expects($this->once())
            ->method('subscribeOrUpdate')
            ->with('abc123', 'jane@example.com', [], [], SubscriptionStatus::SUBSCRIBED);

        $this->manager->syncMemberToList($member, 'default_newsletter');
    }

    public function testSyncMemberIteratesAllLists(): void
    {
        $member = $this->buildMember('jane@example.com', []);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(null);

        $this->driver->expects($this->once())->method('subscribeOrUpdate');

        $this->manager->syncMember($member);
    }

    public function testNoListNameAndNoDefaultThrowsLogicException(): void
    {
        $managerWithoutDefault = new NewsletterManager($this->registry, null, $this->mapper);

        $this->expectException(\LogicException::class);
        $managerWithoutDefault->subscribe('jane@example.com');
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $attributes  localField → value, matched by getter name
     */
    private function buildMember(string $email, array $attributes): ManagerTestMember&MockObject
    {
        $member = $this->createMock(ManagerTestMember::class);
        $member->method('getNewsletterEmail')->willReturn($email);
        $member->method('getNewsletterSegments')->willReturn([]);

        foreach ($attributes as $field => $value) {
            $getter = u($field)
                ->pascal()
                ->ensureStart('get')
                ->toString();
            $member->method($getter)->willReturn($value);
        }

        return $member;
    }
}

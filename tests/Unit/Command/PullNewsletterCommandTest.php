<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Command;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Command\PullNewsletterCommand;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberResolverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\ListConfig;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\RemoteMember;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\IncomingMemberSync;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\OutboundSyncSuppressor;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\MergeFieldMapper;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class PullNewsletterCommandTest extends Unit
{
    private const string LIST = 'default_newsletter';
    private const string CONNECTOR = 'main';
    private const string EMAIL = 'jane@example.com';

    private MemberResolverInterface&MockObject $memberResolver;
    private EventDispatcherInterface&MockObject $eventDispatcher;

    protected function setUp(): void
    {
        $this->memberResolver = $this->createMock(MemberResolverInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    }

    public function testPullUpdatesChangedMemberAndSaves(): void
    {
        $member = $this->createMock(NewsletterMemberInterface::class);
        $member->method('getNewsletterSubscriptionStatus')->willReturn(SubscriptionStatus::UNSUBSCRIBED);
        $member->expects($this->once())
            ->method('setNewsletterSubscriptionStatus')
            ->with(self::LIST, SubscriptionStatus::SUBSCRIBED);
        $member->expects($this->once())
            ->method('save')
            ->with(['versionNote' => '[OpenDXP Campaigns] Updated by pull sync']);

        $this->memberResolver->method('resolveByEmail')->with(self::EMAIL)->willReturn($member);

        $driver = $this->createDriver([
            new RemoteMember(self::EMAIL, SubscriptionStatus::SUBSCRIBED),
        ]);

        $tester = $this->buildTester($driver);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('updated 1', $tester->getDisplay());
    }

    public function testUnknownMemberIsCountedAndNotSaved(): void
    {
        $this->memberResolver->method('resolveByEmail')->with(self::EMAIL)->willReturn(null);

        $driver = $this->createDriver([
            new RemoteMember(self::EMAIL, SubscriptionStatus::SUBSCRIBED),
        ]);

        $tester = $this->buildTester($driver);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('not found 1', $tester->getDisplay());
    }

    public function testDryRunDoesNotSave(): void
    {
        $member = $this->createMock(NewsletterMemberInterface::class);
        $member->expects($this->never())->method('save');
        $member->expects($this->never())->method('setNewsletterSubscriptionStatus');

        $this->memberResolver->method('resolveByEmail')->willReturn($member);

        $driver = $this->createDriver([
            new RemoteMember(self::EMAIL, SubscriptionStatus::SUBSCRIBED),
        ]);

        $tester = $this->buildTester($driver);
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('would sync', $tester->getDisplay());
    }

    public function testInvalidSinceValueFails(): void
    {
        $driver = $this->createDriver([]);

        $tester = $this->buildTester($driver);
        $exitCode = $tester->execute(['--since' => 'not-a-date']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid --since value', $tester->getDisplay());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param RemoteMember[] $members
     */
    private function createDriver(array $members): NewsletterDriverInterface&MockObject
    {
        $driver = $this->createMock(NewsletterDriverInterface::class);
        $driver->method('getName')->willReturn('mailchimp');
        $driver->method('listChangedMembers')->willReturn($members);

        return $driver;
    }

    private function buildTester(NewsletterDriverInterface $driver): CommandTester
    {
        $listConfig = new ListConfig(
            identifier: self::LIST,
            connectorName: self::CONNECTOR,
            providerListId: 'abc123',
            label: 'Default Newsletter',
        );

        $registry = new DriverRegistry(
            connectors: [self::CONNECTOR => $driver],
            listConfigs: [self::LIST => $listConfig],
        );

        $incomingSync = new IncomingMemberSync($registry, new MergeFieldMapper(), $this->eventDispatcher);

        $command = new PullNewsletterCommand($registry, $incomingSync, new OutboundSyncSuppressor(), $this->memberResolver);

        return new CommandTester($command);
    }
}
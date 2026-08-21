<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Command;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Command\PushNewsletterCommand;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberProviderInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberResolverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\ListConfig;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\NewsletterManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\SegmentProviderInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\RemoteIdStore;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\SegmentExporter;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pushing a list nothing was ever pulled into reports every member without a status as an
 * unsubscribe. The command refuses instead, and says how to proceed.
 */
class PushNewsletterCommandGuardTest extends Unit
{
    private MemberProviderInterface&MockObject $memberProvider;

    protected function setUp(): void
    {
        $this->memberProvider = $this->createMock(MemberProviderInterface::class);
    }

    public function testRefusesAListNothingWasEverPulledInto(): void
    {
        $this->memberProvider->method('hasMemberSyncedFromProvider')->willReturn(false);
        $this->memberProvider->expects($this->never())->method('findByList');

        $tester = new CommandTester($this->buildCommand());
        $status = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('campaigns:newsletter:pull', $tester->getDisplay());
    }

    public function testForceGoesAheadAnyway(): void
    {
        $this->memberProvider->method('hasMemberSyncedFromProvider')->willReturn(false);
        $this->memberProvider->expects($this->once())->method('findByList')->willReturn([]);

        $tester = new CommandTester($this->buildCommand());

        $this->assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
    }

    public function testDryRunIsExempt(): void
    {
        $this->memberProvider->method('hasMemberSyncedFromProvider')->willReturn(false);
        $this->memberProvider->expects($this->once())->method('findByList')->willReturn([]);

        $tester = new CommandTester($this->buildCommand());

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
    }

    public function testProceedsOnceSomethingWasPulled(): void
    {
        $this->memberProvider->method('hasMemberSyncedFromProvider')->willReturn(true);
        $this->memberProvider->expects($this->once())->method('findByList')->willReturn([]);

        $tester = new CommandTester($this->buildCommand());

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
    }

    private function buildCommand(): PushNewsletterCommand
    {
        $registry = new DriverRegistry(
            connectors: ['main' => $this->createMock(NewsletterDriverInterface::class)],
            listConfigs: ['default_newsletter' => new ListConfig('default_newsletter', 'main', 'abc123', 'Default')],
        );

        return new PushNewsletterCommand(
            $registry,
            $this->createMock(NewsletterManagerInterface::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(MemberResolverInterface::class),
            $this->memberProvider,
            // A real exporter: this test never reaches the segment pass, and the class is final.
            new SegmentExporter(
                $registry,
                $this->createMock(RemoteIdStore::class),
                new LockFactory(new InMemoryStore()),
                new NullLogger(),
                $this->createMock(SegmentProviderInterface::class),
            ),
        );
    }
}

<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\EventListener;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\ListConfig;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Instride\Bundle\OpenDxpCampaignsBundle\EventListener\MemberDataObjectSyncListener;
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Message\SyncMemberToListMessage;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\OutboundSyncSuppressor;
use OpenDxp\Event\Model\DataObjectEvent;
use OpenDxp\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A newsletter member is a Concrete DataObject that also implements
 * NewsletterMemberInterface. We declare an abstract double so createMock() can
 * satisfy DataObjectEvent's AbstractObject type hint while stubbing the member API.
 */
abstract class SyncListenerTestMember extends Concrete implements NewsletterMemberInterface {}

class MemberDataObjectSyncListenerTest extends Unit
{
    private const string LIST_A = 'newsletter_a';
    private const string LIST_B = 'newsletter_b';

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

    public function testDispatchesSyncMessageForSubscribedList(): void
    {
        $member = $this->buildMember(42, 'jane@example.com', true, [
            self::LIST_A => SubscriptionStatus::SUBSCRIBED,
        ]);

        $this->bus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (SyncMemberToListMessage $message): bool {
                return $message->listName === self::LIST_A && $message->memberValue === 42;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $this->createListener()->onPostWrite(new DataObjectEvent($member));
    }

    public function testDispatchesOnlyForListsTheMemberIsSubscribedTo(): void
    {
        // Status known for LIST_A, unknown (null) for LIST_B → only LIST_A syncs.
        $member = $this->buildMember(7, 'jane@example.com', true, [
            self::LIST_A => SubscriptionStatus::UNSUBSCRIBED,
        ]);

        $dispatched = [];
        $this->bus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (SyncMemberToListMessage $message) use (&$dispatched): Envelope {
                $dispatched[] = $message->listName;

                return new Envelope($message);
            });

        $this->createListener()->onPostWrite(new DataObjectEvent($member));

        $this->assertSame([self::LIST_A], $dispatched);
    }

    public function testIgnoresNonMemberObjects(): void
    {
        $object = $this->createMock(Concrete::class);

        $this->bus->expects($this->never())->method('dispatch');

        $this->createListener()->onPostWrite(new DataObjectEvent($object));
    }

    public function testSkipsVersionOnlySaves(): void
    {
        $member = $this->buildMember(1, 'jane@example.com', true, [
            self::LIST_A => SubscriptionStatus::SUBSCRIBED,
        ]);

        $this->bus->expects($this->never())->method('dispatch');

        $this->createListener()->onPostWrite(new DataObjectEvent($member, ['saveVersionOnly' => true]));
    }

    public function testSkipsWhenSuppressed(): void
    {
        $member = $this->buildMember(1, 'jane@example.com', true, [
            self::LIST_A => SubscriptionStatus::SUBSCRIBED,
        ]);

        $this->bus->expects($this->never())->method('dispatch');

        $listener = $this->createListener();
        $this->suppressor->suppress(function () use ($listener, $member): void {
            $listener->onPostWrite(new DataObjectEvent($member));
        });
    }

    public function testSkipsUnpublishedMembers(): void
    {
        $member = $this->buildMember(1, 'jane@example.com', false, [
            self::LIST_A => SubscriptionStatus::SUBSCRIBED,
        ]);

        $this->bus->expects($this->never())->method('dispatch');

        $this->createListener()->onPostWrite(new DataObjectEvent($member));
    }

    public function testSkipsMembersWithoutEmail(): void
    {
        $member = $this->buildMember(1, '', true, [
            self::LIST_A => SubscriptionStatus::SUBSCRIBED,
        ]);

        $this->bus->expects($this->never())->method('dispatch');

        $this->createListener()->onPostWrite(new DataObjectEvent($member));
    }

    // -------------------------------------------------------------------------

    private function createListener(LoggerInterface $logger = new NullLogger()): MemberDataObjectSyncListener
    {
        $registry = new DriverRegistry(
            connectors: ['main' => $this->createMock(NewsletterDriverInterface::class)],
            listConfigs: [
                self::LIST_A => new ListConfig(self::LIST_A, 'main', 'a123', 'List A'),
                self::LIST_B => new ListConfig(self::LIST_B, 'main', 'b456', 'List B'),
            ],
        );

        return new MemberDataObjectSyncListener($registry, $this->bus, $this->suppressor, $logger);
    }

    /**
     * @param array<string, SubscriptionStatus> $statuses  list name → known status
     */
    private function buildMember(
        int $id,
        string $email,
        bool $published,
        array $statuses,
    ): SyncListenerTestMember&MockObject {
        $member = $this->createMock(SyncListenerTestMember::class);
        $member->method('getId')->willReturn($id);
        $member->method('isPublished')->willReturn($published);
        $member->method('getNewsletterEmail')->willReturn($email);
        $member->method('getNewsletterSubscriptionStatus')->willReturnCallback(
            static fn (string $listName): ?SubscriptionStatus => $statuses[$listName] ?? null,
        );

        return $member;
    }
}
<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Messenger;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberResolverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Handler\SyncMemberToListHandler;
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Message\SyncMemberToListMessage;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\NewsletterManagerInterface;
use Psr\Log\NullLogger;

class SyncMemberToListHandlerTest extends Unit
{
    /**
     * The message carries string|int, the resolver takes int, and the file declares strict
     * types — a queued id that arrived as a string used to abort the handler with a TypeError.
     */
    public function testNumericStringIdIsResolvedById(): void
    {
        $member = $this->createMock(NewsletterMemberInterface::class);

        $resolver = $this->createMock(MemberResolverInterface::class);
        $resolver->expects($this->once())->method('resolveById')->with(42)->willReturn($member);
        $resolver->expects($this->never())->method('resolveByEmail');

        $manager = $this->createMock(NewsletterManagerInterface::class);
        $manager->expects($this->once())->method('syncMemberToList')->with($member, 'default_newsletter');

        $handler = new SyncMemberToListHandler($manager, $resolver, new NullLogger());
        $handler(new SyncMemberToListMessage('default_newsletter', '42'));
    }

    public function testNonNumericValueIsResolvedByEmail(): void
    {
        $member = $this->createMock(NewsletterMemberInterface::class);

        $resolver = $this->createMock(MemberResolverInterface::class);
        $resolver->expects($this->once())->method('resolveByEmail')->with('jane@example.com')->willReturn($member);
        $resolver->expects($this->never())->method('resolveById');

        $manager = $this->createMock(NewsletterManagerInterface::class);
        $manager->expects($this->once())->method('syncMemberToList');

        $handler = new SyncMemberToListHandler($manager, $resolver, new NullLogger());
        $handler(new SyncMemberToListMessage('default_newsletter', 'jane@example.com'));
    }
}

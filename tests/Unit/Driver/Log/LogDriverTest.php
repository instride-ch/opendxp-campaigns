<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Driver\Log;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\Log\LogDriver;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Psr\Log\Test\TestLogger;

class LogDriverTest extends Unit
{
    private TestLogger $logger;
    private LogDriver $driver;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
        $this->driver = new LogDriver('test_connector', $this->logger);
    }

    public function testGetNameReturnsLog(): void
    {
        $this->assertSame('log', $this->driver->getName());
    }

    public function testSubscribeLogsWithEmailAndMergeFields(): void
    {
        $this->driver->subscribe('list-123', 'jane@example.com', ['FNAME' => 'Jane']);

        $this->assertTrue($this->logger->hasInfo(''));
        $log = $this->logger->records[0];

        $this->assertStringContainsString('subscribe', $log['message']);
        $this->assertStringContainsString('test_connector', $log['message']);
        $this->assertSame('jane@example.com', $log['context']['email']);
        $this->assertSame(['FNAME' => 'Jane'], $log['context']['merge_fields']);
    }

    public function testSubscribeLogsWithInterestIds(): void
    {
        $this->driver->subscribe('list-123', 'jane@example.com', [], ['interest-a', 'interest-b']);

        $log = $this->logger->records[0];
        $this->assertSame(['interest-a', 'interest-b'], $log['context']['interest_ids']);
    }

    public function testUnsubscribeLogsOperation(): void
    {
        $this->driver->unsubscribe('list-123', 'jane@example.com');

        $log = $this->logger->records[0];
        $this->assertStringContainsString('unsubscribe', $log['message']);
        $this->assertSame('jane@example.com', $log['context']['email']);
    }

    public function testSubscribeOrUpdateLogsOperation(): void
    {
        $this->driver->subscribeOrUpdate('list-123', 'jane@example.com', ['LNAME' => 'Doe']);

        $log = $this->logger->records[0];
        $this->assertStringContainsString('subscribeOrUpdate', $log['message']);
        $this->assertSame(['LNAME' => 'Doe'], $log['context']['merge_fields']);
    }

    public function testDeleteLogsOperation(): void
    {
        $this->driver->delete('list-123', 'jane@example.com');

        $log = $this->logger->records[0];
        $this->assertStringContainsString('delete', $log['message']);
    }

    public function testGetMemberLogsAndReturnsNull(): void
    {
        $result = $this->driver->getMember('list-123', 'jane@example.com');

        $this->assertNull($result);
        $log = $this->logger->records[0];
        $this->assertStringContainsString('getMember', $log['message']);
    }

    public function testHasMemberLogsAndReturnsFalse(): void
    {
        $result = $this->driver->hasMember('list-123', 'jane@example.com');

        $this->assertFalse($result);
    }

    public function testIsSubscribedLogsAndReturnsFalse(): void
    {
        $result = $this->driver->isSubscribed('list-123', 'jane@example.com');

        $this->assertFalse($result);
    }

    public function testSubscribeDefaultsToSubscribedStatus(): void
    {
        $this->driver->subscribe('list-123', 'jane@example.com');

        $log = $this->logger->records[0];
        $this->assertSame(SubscriptionStatus::Subscribed->value, $log['context']['status']);
    }
}
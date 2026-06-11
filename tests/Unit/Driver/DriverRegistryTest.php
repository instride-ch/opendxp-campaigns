<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Driver;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\ListConfig;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\ConnectorNotFoundException;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\ListNotFoundException;

class DriverRegistryTest extends Unit
{
    private NewsletterDriverInterface $driver;
    private DriverRegistry $registry;

    protected function setUp(): void
    {
        $this->driver = $this->createMock(NewsletterDriverInterface::class);

        $listConfig = new ListConfig(
            identifier: 'default_newsletter',
            connectorName: 'main',
            providerListId: 'abc123',
            label: 'Default Newsletter',
        );

        $this->registry = new DriverRegistry(
            connectors: ['main' => $this->driver],
            listConfigs: ['default_newsletter' => $listConfig],
        );
    }

    public function testGetDriverForConnector(): void
    {
        $this->assertSame($this->driver, $this->registry->getDriverForConnector('main'));
    }

    public function testGetDriverForConnectorThrowsOnUnknown(): void
    {
        $this->expectException(ConnectorNotFoundException::class);
        $this->registry->getDriverForConnector('unknown');
    }

    public function testGetDriverForList(): void
    {
        $this->assertSame($this->driver, $this->registry->getDriverForList('default_newsletter'));
    }

    public function testGetDriverForListThrowsOnUnknownList(): void
    {
        $this->expectException(ListNotFoundException::class);
        $this->registry->getDriverForList('nonexistent');
    }

    public function testGetListConfig(): void
    {
        $config = $this->registry->getListConfig('default_newsletter');

        $this->assertSame('default_newsletter', $config->identifier);
        $this->assertSame('abc123', $config->providerListId);
        $this->assertSame('main', $config->connectorName);
    }

    public function testGetListConfigThrowsOnUnknown(): void
    {
        $this->expectException(ListNotFoundException::class);
        $this->registry->getListConfig('nonexistent');
    }

    public function testGetConnectorNames(): void
    {
        $this->assertSame(['main'], $this->registry->getConnectorNames());
    }

    public function testGetListNames(): void
    {
        $this->assertSame(['default_newsletter'], $this->registry->getListNames());
    }

    public function testGetListConfigs(): void
    {
        $configs = $this->registry->getListConfigs();

        $this->assertCount(1, $configs);
        $this->assertArrayHasKey('default_newsletter', $configs);
    }
}
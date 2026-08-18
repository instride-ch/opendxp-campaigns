<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\DependencyInjection;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\DataObjectMemberProvider;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\DataObjectMemberResolver;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberProviderInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberResolverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\UnconfiguredMemberSource;
use Instride\Bundle\OpenDxpCampaignsBundle\DependencyInjection\OpenDxpCampaignsExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A bundle nobody can install before configuring it is no use, so the container has to compile
 * with no member configuration at all. What is missing then shows up when something asks for a
 * member, not while the kernel boots.
 */
class MemberServiceRegistrationTest extends Unit
{
    public function testAliasesPointAtTheStandInsWithoutAnyMemberConfiguration(): void
    {
        $container = $this->load([]);

        $this->assertSame(
            UnconfiguredMemberSource::class,
            (string) $container->getAlias(MemberResolverInterface::class),
        );
        $this->assertSame(
            UnconfiguredMemberSource::class,
            (string) $container->getAlias(MemberProviderInterface::class),
        );
    }

    public function testMemberClassWiresTheDataObjectServices(): void
    {
        $container = $this->load(['member_class' => 'App\\Model\\DataObject\\Customer']);

        $this->assertSame(
            DataObjectMemberResolver::class,
            (string) $container->getAlias(MemberResolverInterface::class),
        );
        $this->assertSame(
            DataObjectMemberProvider::class,
            (string) $container->getAlias(MemberProviderInterface::class),
        );
    }

    public function testAnExplicitServiceIdWinsOverMemberClass(): void
    {
        $container = $this->load([
            'member_class' => 'App\\Model\\DataObject\\Customer',
            'member_resolver' => 'app.own_resolver',
            'member_provider' => 'app.own_provider',
        ]);

        $this->assertSame('app.own_resolver', (string) $container->getAlias(MemberResolverInterface::class));
        $this->assertSame('app.own_provider', (string) $container->getAlias(MemberProviderInterface::class));
    }

    public function testTheStandInNamesTheKeysItIsMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/member_class.*member_resolver/s');

        (new UnconfiguredMemberSource())->resolveByEmail('jane@example.com');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new OpenDxpCampaignsExtension())->load([$config], $container);

        return $container;
    }
}

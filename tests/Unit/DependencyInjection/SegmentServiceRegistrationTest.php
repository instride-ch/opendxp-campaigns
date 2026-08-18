<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\DependencyInjection;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\DataObjectSegmentProvider;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\EmptySegmentProvider;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\SegmentProviderInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DependencyInjection\Configuration;
use Instride\Bundle\OpenDxpCampaignsBundle\DependencyInjection\OpenDxpCampaignsExtension;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Segments are optional. An install without them still pushes members, and a push asks the
 * provider list which segments it manages before every send — so the answer has to be "none"
 * rather than an exception. Half a configuration is the one case worth refusing.
 */
class SegmentServiceRegistrationTest extends Unit
{
    public function testTheProviderYieldsNothingWithoutSegmentClasses(): void
    {
        $container = $this->load([]);

        $this->assertSame(
            EmptySegmentProvider::class,
            (string) $container->getAlias(SegmentProviderInterface::class),
        );
        $this->assertSame([], [...(new EmptySegmentProvider())->allSegments()]);
    }

    public function testBothSegmentClassesWireTheDataObjectProvider(): void
    {
        $container = $this->load(['segments' => [
            'segment_class' => 'App\\Model\\DataObject\\NewsletterSegment',
            'segment_group_class' => 'App\\Model\\DataObject\\NewsletterSegmentGroup',
        ]]);

        $this->assertSame(
            DataObjectSegmentProvider::class,
            (string) $container->getAlias(SegmentProviderInterface::class),
        );
    }

    public function testOneSegmentClassWithoutTheOtherIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/segment_class and segment_group_class together/');

        (new Processor())->processConfiguration(new Configuration(), [[
            'segments' => ['segment_class' => 'App\\Model\\DataObject\\NewsletterSegment'],
        ]]);
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

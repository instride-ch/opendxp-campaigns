<?php

declare(strict_types=1);

/**
 * OpenDXP Campaigns.
 *
 * LICENSE
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  2026 instride AG (https://instride.ch)
 * @license    https://github.com/instride-ch/opendxp-campaigns/blob/main/gpl-3.0.txt GNU General Public License version 3 (GPLv3)
 */

namespace Instride\Bundle\OpenDxpCampaignsBundle\DependencyInjection;

use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\DataObjectMemberProvider;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\DataObjectMemberResolver;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberProviderInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberResolverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\ListConfig;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\Log\LogDriver;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\Mailchimp\MailchimpDriver;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\MergeFieldMapping;
use Instride\Bundle\OpenDxpCampaignsBundle\EventListener\MemberDataObjectSyncListener;
use Instride\Bundle\OpenDxpCampaignsBundle\EventListener\SegmentSyncListener;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\MergeFieldMapper;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\NewsletterManager;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\RemoteIdStore;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class OpenDxpCampaignsExtension extends Extension
{
    /**
     * @throws \Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $connectorRefs = $this->registerConnectorServices($config['connectors'] ?? [], $container);
        $listConfigs = $this->buildListConfigs($config['lists'] ?? []);

        $this->registerDriverRegistry($connectorRefs, $listConfigs, $container);
        $this->registerMergeFieldMapper($container);
        $this->registerNewsletterManager($config['default_list_name'] ?? null, $container);
        $this->registerMemberServices($config['member_class'] ?? null, $config['email_field'], $container);

        // Consumed by the Installer to detect (and, when missing, create) the matching
        // Member / Segment / SegmentGroup class definitions (see services.yaml).
        $container->setParameter('opendxp_campaigns.member_class', $config['member_class'] ?? null);
        $container->setParameter('opendxp_campaigns.segments.segment_class', $config['segments']['segment_class'] ?? null);
        $container->setParameter('opendxp_campaigns.segments.segment_group_class', $config['segments']['segment_group_class'] ?? null);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // Opt-in: only wire the save-triggered outbound sync listener when enabled,
        // so it adds no overhead to DataObject saves in installs that don't want it.
        if ($config['sync_on_save'] !== true) {
            $container->removeDefinition(MemberDataObjectSyncListener::class);
        }

        // Opt-in: same for the segment / segment-group sync listener.
        if ($config['segments']['sync_on_save'] !== true) {
            $container->removeDefinition(SegmentSyncListener::class);
        }
    }

    public function getAlias(): string
    {
        return 'opendxp_campaigns';
    }

    /**
     * @param array<string, array{driver: string, config: array<string, mixed>}> $connectors
     * @return array<string, Reference>
     */
    private function registerConnectorServices(array $connectors, ContainerBuilder $container): array
    {
        $refs = [];

        foreach ($connectors as $name => $connectorConfig) {
            $serviceId = \sprintf('opendxp_campaigns.connector.%s', $name);
            $definition = $this->createDriverDefinition($name, $connectorConfig['driver'], $connectorConfig['config'] ?? []);
            $container->setDefinition($serviceId, $definition);
            $refs[$name] = new Reference($serviceId);
        }

        return $refs;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createDriverDefinition(string $connectorName, string $driver, array $config): Definition
    {
        return match ($driver) {
            'mailchimp' => (new Definition(MailchimpDriver::class, [
                $connectorName,
                $config['api_key'] ?? '',
                $config['webhook_secret'] ?? null,
                new Reference('logger'),
            ]))->setAutowired(false),

            'log' => (new Definition(LogDriver::class, [
                $connectorName,
                new Reference('logger'),
            ]))->setAutowired(false),

            default => throw new \InvalidArgumentException(
                \sprintf(
                    'Unknown newsletter driver "%s" for connector "%s". Supported drivers: mailchimp, log.',
                    $driver,
                    $connectorName,
                ),
            ),
        };
    }

    /**
     * @param array<string, array{connector: string, provider_list_id: string, label: string, merge_fields: array<string, array{field: string, transformer: string|null}>}> $lists
     * @return Definition[]
     */
    private function buildListConfigs(array $lists): array
    {
        $listConfigs = [];

        foreach ($lists as $name => $listConfig) {
            $mergeFieldMappings = $this->buildMergeFieldMappings($listConfig['merge_fields'] ?? []);

            $listConfigs[$name] = new Definition(ListConfig::class, [
                $name,
                $listConfig['connector'],
                $listConfig['provider_list_id'],
                $listConfig['label'],
                $mergeFieldMappings,
            ]);
        }

        return $listConfigs;
    }

    /**
     * @param array<string, array{field: string, transformer: string|null}> $mergeFields
     * @return array<string, Definition>
     */
    private function buildMergeFieldMappings(array $mergeFields): array
    {
        $mappings = [];

        foreach ($mergeFields as $localField => $fieldConfig) {
            $transformerArg = null;

            if ($fieldConfig['transformer'] !== null) {
                $id = $fieldConfig['transformer'];
                // '@ServiceId' → container Reference; 'App\ClassName' → anonymous inline service
                $transformerArg = \str_starts_with($id, '@')
                    ? new Reference(\substr($id, 1))
                    : new Definition($id);
            }

            $mappings[$localField] = new Definition(MergeFieldMapping::class, [
                $localField,
                $fieldConfig['field'],
                $transformerArg,
            ]);
        }

        return $mappings;
    }

    /**
     * @param array<string, Reference>   $connectorRefs
     * @param array<string, Definition>  $listConfigs
     */
    private function registerDriverRegistry(array $connectorRefs, array $listConfigs, ContainerBuilder $container): void
    {
        $container->setDefinition(
            DriverRegistry::class,
            new Definition(DriverRegistry::class, [$connectorRefs, $listConfigs]),
        );
    }

    private function registerMergeFieldMapper(ContainerBuilder $container): void
    {
        $container->setDefinition(
            MergeFieldMapper::class,
            (new Definition(MergeFieldMapper::class))->setAutowired(false),
        );
    }

    private function registerNewsletterManager(?string $defaultListName, ContainerBuilder $container): void
    {
        $container->setDefinition(
            NewsletterManager::class,
            (new Definition(NewsletterManager::class, [
                new Reference(DriverRegistry::class),
                $defaultListName,
                new Reference(MergeFieldMapper::class),
                new Reference(RemoteIdStore::class),
            ]))->setAutowired(false),
        );
    }

    private function registerMemberServices(?string $memberClass, string $emailField, ContainerBuilder $container): void
    {
        if ($memberClass === null) {
            return;
        }

        $resolverDef = (new Definition(DataObjectMemberResolver::class, [$memberClass, $emailField]))->setAutowired(false);
        $container->setDefinition(DataObjectMemberResolver::class, $resolverDef);
        $container->setAlias(MemberResolverInterface::class, DataObjectMemberResolver::class)->setPublic(false);

        $providerDef = (new Definition(DataObjectMemberProvider::class, [$memberClass]))->setAutowired(false);
        $container->setDefinition(DataObjectMemberProvider::class, $providerDef);
        $container->setAlias(MemberProviderInterface::class, DataObjectMemberProvider::class)->setPublic(false);
    }
}

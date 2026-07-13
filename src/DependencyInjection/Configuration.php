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

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('opendxp_campaigns');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('member_class')
                    ->info('Fully-qualified class name of the DataObject that implements NewsletterMemberInterface. Auto-registers the DataObjectMemberResolver and DataObjectMemberProvider services.')
                    ->defaultNull()
                ->end()
                ->scalarNode('email_field')
                    ->info('DataObject field name used to look up members by email address. Defaults to "email".')
                    ->defaultValue('email')
                ->end()
                ->booleanNode('sync_on_save')
                    ->info('When true, saving a member DataObject automatically pushes it to the newsletter provider (async, per subscribed list) via MemberDataObjectSyncListener. Disabled by default; enable to keep the provider continuously in sync.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('connectors')
                    ->info('Named connector instances, each backed by a provider driver.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('driver')
                                ->info('Driver to use for this connector (e.g. "mailchimp", "log").')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->arrayNode('config')
                                ->info('Driver-specific configuration options.')
                                ->variablePrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('default_list_name')
                    ->info('The list identifier used when no explicit list name is passed to manager methods.')
                    ->defaultNull()
                ->end()
                ->arrayNode('lists')
                    ->info('Named newsletter lists/audiences linked to connectors.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('connector')
                                ->info('Name of the connector this list belongs to.')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('provider_list_id')
                                ->info('The provider-side list/audience ID (e.g. Mailchimp audience ID).')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('label')
                                ->info('Human-readable label for this list.')
                                ->defaultValue('')
                            ->end()
                            ->arrayNode('merge_fields')
                                ->info('Mapping of local member attribute names to provider merge field names. Shorthand: `firstname: FNAME`. Expanded: `phone: { field: PHONE, transformer: App\\Newsletter\\PhoneNormalizer }`.')
                                ->useAttributeAsKey('name')
                                ->beforeNormalization()
                                    ->always(static function (array $v): array {
                                        foreach ($v as $key => $value) {
                                            if (\is_string($value)) {
                                                $v[$key] = ['field' => $value];
                                            }
                                        }

                                        return $v;
                                    })
                                ->end()
                                ->arrayPrototype()
                                    ->children()
                                        ->scalarNode('field')
                                            ->info('Provider-side merge tag name (e.g. FNAME for Mailchimp).')
                                            ->isRequired()
                                            ->cannotBeEmpty()
                                        ->end()
                                        ->scalarNode('transformer')
                                            ->info('Optional MergeFieldTransformerInterface. Prefix with "@" for a container service (e.g. "@App\\Transformer\\Phone"), or use a bare class name for a plain PHP class (e.g. "App\\Transformer\\Phone").')
                                            ->defaultNull()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}

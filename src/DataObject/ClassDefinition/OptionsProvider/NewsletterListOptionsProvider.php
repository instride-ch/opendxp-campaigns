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

namespace Instride\Bundle\OpenDxpCampaignsBundle\DataObject\ClassDefinition\OptionsProvider;

use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use OpenDxp\Model\DataObject\ClassDefinition\DynamicOptionsProvider\SelectOptionsProviderInterface;

/**
 * Provides all configured newsletter lists (opendxp_campaigns.lists) as
 * selectable options for select / multiselect data object fields.
 *
 * The option key is the human-readable list label, and the option value is
 * the list identifier used throughout the bundle (NewsletterManager, etc.).
 */
final readonly class NewsletterListOptionsProvider implements SelectOptionsProviderInterface
{
    public function __construct(
        private DriverRegistry $driverRegistry,
    ) {}

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, array{key: string, value: string}>
     */
    public function getOptions(array $context, Data $fieldDefinition): array
    {
        $options = [];

        foreach ($this->driverRegistry->getListConfigs() as $listConfig) {
            $options[] = [
                'key' => $listConfig->label,
                'value' => $listConfig->identifier,
            ];
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function hasStaticOptions(array $context, Data $fieldDefinition): bool
    {
        // Options are derived from the bundle configuration and are identical
        // for every object, so batch assignment and list filtering are allowed.
        return true;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return string|array<string|array{value: string}>|null
     */
    public function getDefaultValue(array $context, Data $fieldDefinition): string|array|null
    {
        if (\method_exists($fieldDefinition, 'getDefaultValue')) {
            return $fieldDefinition->getDefaultValue();
        }

        return null;
    }
}
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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Driver;

final readonly class ListConfig
{
    /**
     * @param array<string, MergeFieldMapping> $mergeFieldMappings  localField → mapping
     */
    public function __construct(
        public string $identifier,
        public string $connectorName,
        public string $providerListId,
        public string $label,
        public array $mergeFieldMappings = [],
    ) {}
}

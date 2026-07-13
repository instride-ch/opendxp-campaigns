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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Newsletter;

use Instride\Bundle\OpenDxpCampaignsBundle\Driver\MergeFieldMapping;
use function Symfony\Component\String\u;

/**
 * Translates member attribute values to/from provider merge field payloads using
 * the per-list merge_fields configuration.
 *
 * Reads and writes member attributes via dynamic getters/setters derived from the
 * local field name: 'firstname' → getFirstname() / setFirstname($value).
 */
final class MergeFieldMapper
{
    /**
     * Builds the provider merge field payload for a member.
     *
     * For each mapping, calls get<LocalField>() on the member. Null values become
     * empty strings unless a transformer handles the conversion.
     *
     * @param array<string, MergeFieldMapping> $mappings  localField → mapping
     * @return array<string, scalar>
     */
    public function toProvider(object $member, array $mappings): array
    {
        $result = [];

        foreach ($mappings as $mapping) {
            $getter = u($mapping->localField)
                ->pascal()
                ->ensureStart('get')
                ->toString();

            if (!\method_exists($member, $getter)) {
                continue;
            }

            $value = $member->$getter();

            if ($mapping->transformer !== null) {
                $value = $mapping->transformer->toProvider($value);
            } elseif ($value === null) {
                $value = '';
            }

            $result[$mapping->providerField] = $value;
        }

        return $result;
    }

    /**
     * Converts a provider merge field payload back to local attribute names and values.
     *
     * Used when processing webhook profile-update events. Provider fields that have no
     * mapping entry are silently ignored.
     *
     * @param array<string, scalar>            $providerFields  providerTag → value
     * @param array<string, MergeFieldMapping> $mappings        localField → mapping
     * @return array<string, mixed>  localField → (transformed) value
     */
    public function fromProvider(array $providerFields, array $mappings): array
    {
        $reverseLookup = [];
        foreach ($mappings as $mapping) {
            $reverseLookup[$mapping->providerField] = $mapping;
        }

        $result = [];

        foreach ($providerFields as $providerField => $value) {
            if (!isset($reverseLookup[$providerField])) {
                continue;
            }

            $mapping = $reverseLookup[$providerField];
            $result[$mapping->localField] = $mapping->transformer !== null
                ? $mapping->transformer->fromProvider($value)
                : $value;
        }

        return $result;
    }
}

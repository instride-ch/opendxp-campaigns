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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Contract;

/**
 * Bidirectional value transformer for a single merge field.
 *
 * Implement this interface when a raw member attribute value needs to be shaped
 * before sending to the provider (e.g., formatting a date, normalizing a phone
 * number) and when an incoming webhook value needs to be shaped back before
 * being written to the member object.
 */
interface MergeFieldTransformerInterface
{
    /**
     * Converts a local member attribute value to a provider-side scalar.
     *
     * Called during subscribe/sync before the payload is sent to the provider.
     */
    public function toProvider(mixed $value): mixed;

    /**
     * Converts a provider-side scalar back to a local member attribute value.
     *
     * Called when a webhook profile-update event carries a new merge field value.
     */
    public function fromProvider(mixed $value): mixed;
}

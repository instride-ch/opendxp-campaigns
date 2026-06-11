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

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\MergeFieldTransformerInterface;

/**
 * Immutable mapping between a local member attribute and a provider merge field.
 *
 * Constructed by the DI extension from the list-level `merge_fields` config. The optional
 * transformer is resolved as a real service instance at compile time.
 */
final readonly class MergeFieldMapping
{
    public function __construct(
        /** Local member attribute name (e.g. 'firstname'). Used to derive getter/setter names. */
        public string $localField,
        /** Provider-side merge tag name (e.g. 'FNAME' for Mailchimp). */
        public string $providerField,
        public ?MergeFieldTransformerInterface $transformer = null,
    ) {}
}
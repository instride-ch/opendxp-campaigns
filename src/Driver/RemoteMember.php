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

use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;

/**
 * Normalized snapshot of a member as it exists on the provider side.
 */
final readonly class RemoteMember
{
    /**
     * @param string                  $email            subscriber email on the provider
     * @param SubscriptionStatus|null $status           mapped status, or null when the provider status has no enum mapping
     * @param array<string, scalar>   $mergeFields      provider merge tags → value, raw from the provider
     * @param string|null             $providerMemberId provider-side member ID, when available
     */
    public function __construct(
        public string $email,
        public ?SubscriptionStatus $status,
        public array $mergeFields = [],
        public ?string $providerMemberId = null,
    ) {}
}

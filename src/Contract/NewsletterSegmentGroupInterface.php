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
 * Represents a group of newsletter segments.
 *
 * Maps to Mailchimp Interest Categories. The identifier must match the
 * provider-side category ID so the INTERESTED merge tag works correctly.
 */
interface NewsletterSegmentGroupInterface
{
    /**
     * Provider-specific group identifier (e.g. Mailchimp interest category ID).
     */
    public function getNewsletterGroupIdentifier(): string;

    public function getNewsletterGroupName(): string;
}
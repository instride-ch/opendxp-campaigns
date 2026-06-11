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
 * Represents a single newsletter segment belonging to a group.
 *
 * Maps to a Mailchimp Interest within an Interest Category. The identifier
 * must match the Mailchimp Interest ID for the INTERESTED merge tag to work.
 */
interface NewsletterSegmentInterface
{
    /**
     * Provider-specific segment identifier (e.g. Mailchimp interest ID).
     */
    public function getNewsletterSegmentIdentifier(): string;

    public function getNewsletterSegmentName(): string;

    public function getNewsletterSegmentGroup(): NewsletterSegmentGroupInterface;
}
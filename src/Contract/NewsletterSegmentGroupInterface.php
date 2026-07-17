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

use OpenDxp\Model\Element\ElementInterface;

/**
 * Represents a group of newsletter segments.
 *
 * Maps to a Mailchimp Interest Category. The provider-side category ID is not
 * part of this contract: it is assigned by the provider on export and persisted
 * by the bundle in an OpenDXP Note (see RemoteIdStore). Because an Interest
 * Category belongs to a single audience, a group declares which configured
 * lists it (and its segments) export to via getNewsletterListNames().
 */
interface NewsletterSegmentGroupInterface extends ElementInterface
{
    public function getNewsletterSegmentGroupName(): string;

    /**
     * @return iterable<NewsletterSegmentInterface>
     */
    public function getNewsletterSegments(): iterable;

    /**
     * Configured list identifiers (YAML keys under `lists:`) this group and its
     * segments export to. Backed by a multiselect field populated by
     * {@see \Instride\Bundle\OpenDxpCampaignsBundle\DataObject\ClassDefinition\OptionsProvider\NewsletterListOptionsProvider}.
     *
     * @return string[]
     */
    public function getNewsletterListNames(): array;
}

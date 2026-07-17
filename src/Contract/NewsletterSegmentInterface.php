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
 * Represents a single newsletter segment belonging to a group.
 *
 * Maps to a Mailchimp Interest within an Interest Category. The provider-side
 * identifier is not part of this contract: it is assigned by the provider on
 * export and persisted by the bundle in an OpenDXP Note (see RemoteIdStore).
 * The segment name is used verbatim in the *|INTERESTED|* merge tag, so it must
 * not contain the reserved characters * | : , (enforced on save).
 */
interface NewsletterSegmentInterface extends ElementInterface
{
    public function getNewsletterSegmentName(): string;

    /**
     * The group this segment belongs to.
     *
     * Implementations should resolve this by walking up the object tree to the
     * nearest ancestor implementing NewsletterSegmentGroupInterface
     * (see NewsletterSegmentTrait).
     */
    public function getNewsletterSegmentGroup(): NewsletterSegmentGroupInterface;
}

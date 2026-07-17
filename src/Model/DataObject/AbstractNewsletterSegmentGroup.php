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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Model\DataObject;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentGroupInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\NewsletterSegmentGroupTrait;
use OpenDxp\Model\DataObject\Concrete;

/**
 * Ready-to-extend base class for a newsletter segment group DataObject.
 *
 * Set this as the parent class of your generated SegmentGroup class (the
 * Installer's NewsletterSegmentGroup class definition does this by default). The
 * generated class needs a `name` input field and a `lists` multiselect field
 * whose options come from NewsletterListOptionsProvider.
 */
abstract class AbstractNewsletterSegmentGroup extends Concrete implements NewsletterSegmentGroupInterface
{
    use NewsletterSegmentGroupTrait;

    abstract public function getName(): ?string;

    /**
     * @return array<int, string>|null
     */
    abstract public function getLists(): ?array;
}
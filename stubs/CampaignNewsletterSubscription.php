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

namespace OpenDxp\Model\DataObject\Fieldcollection\Data;

use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\Fieldcollection\AbstractCampaignNewsletterSubscription;

/**
 * Stands in for the class OpenDXP generates from config/install/fieldcollections.
 *
 * It exists only at runtime, in var/classes, so static analysis has nothing to look at — which is
 * why every accessor of the subscription entry read as a call on an unknown class. Analysis only:
 * the file is outside the autoloader and never loaded.
 */
class CampaignNewsletterSubscription extends AbstractCampaignNewsletterSubscription
{
}

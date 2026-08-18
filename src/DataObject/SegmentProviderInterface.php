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

namespace Instride\Bundle\OpenDxpCampaignsBundle\DataObject;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentGroupInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentInterface;

/**
 * Enumerates the segments an installation has, which the event-driven export cannot do.
 *
 * Events only ever carry the one object that changed. A full pass needs the opposite view —
 * everything that should exist at the provider — to backfill what a lost message never exported
 * and to spot what the provider still holds after a local delete went missing.
 */
interface SegmentProviderInterface
{
    /**
     * @return iterable<NewsletterSegmentGroupInterface>
     */
    public function allGroups(): iterable;

    /**
     * @return iterable<NewsletterSegmentInterface>
     */
    public function allSegments(): iterable;
}

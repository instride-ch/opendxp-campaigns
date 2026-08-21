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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Newsletter;

/**
 * Tells a member push which provider-side interests belong to us.
 *
 * Needed because taking an interest away means naming it explicitly, and naming the wrong ones
 * would trample interests the provider holds for reasons of its own.
 */
interface ManagedSegmentInterestsInterface
{
    /**
     * @return string[] remote IDs of every segment this list exported
     */
    public function managedSegmentRemoteIds(string $listName): array;
}

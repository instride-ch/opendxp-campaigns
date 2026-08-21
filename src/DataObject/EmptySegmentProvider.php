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

/**
 * Stands in when no segment classes are configured, which is a legitimate setup: a member push asks
 * which segments it manages before every send, and on an install without segments the honest answer
 * is none. Configuration rejects half a configuration, so this never hides a typo.
 */
final readonly class EmptySegmentProvider implements SegmentProviderInterface
{
    public function allGroups(): iterable
    {
        return [];
    }

    public function allSegments(): iterable
    {
        return [];
    }
}

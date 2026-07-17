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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Exception;

/**
 * Thrown when a newsletter segment has no ancestor segment group in the object tree.
 */
final class SegmentPlacementException extends \RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self(\sprintf(
            'Newsletter segment "%s" must live under a newsletter segment group in the object tree.',
            $path,
        ));
    }
}
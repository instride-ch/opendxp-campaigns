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
 * Lists the configured segment and segment group DataObject classes.
 */
final readonly class DataObjectSegmentProvider implements SegmentProviderInterface
{
    use BatchedListingTrait;

    /**
     * @param class-string $segmentClass
     * @param class-string $segmentGroupClass
     */
    public function __construct(
        private string $segmentClass,
        private string $segmentGroupClass,
    ) {}

    public function allGroups(): iterable
    {
        foreach ($this->iterateBatched($this->segmentGroupClass) as $object) {
            if ($object instanceof NewsletterSegmentGroupInterface) {
                yield $object;
            }
        }
    }

    public function allSegments(): iterable
    {
        foreach ($this->iterateBatched($this->segmentClass) as $object) {
            if ($object instanceof NewsletterSegmentInterface) {
                yield $object;
            }
        }
    }
}

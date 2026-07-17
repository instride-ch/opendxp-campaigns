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
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\SegmentPlacementException;
use OpenDxp\Model\DataObject\AbstractObject;

/**
 * Default NewsletterSegmentInterface implementation for OpenDXP DataObjects.
 *
 * Apply to any Concrete class that implements NewsletterSegmentInterface. The
 * group is resolved dynamically by walking up the object tree (through folders)
 * to the nearest NewsletterSegmentGroupInterface, so no stored relation field is
 * needed and the group can never drift out of sync.
 *
 * @see \Instride\Bundle\OpenDxpCampaignsBundle\EventListener\SegmentNameValidationListener
 *      enforces that a segment is actually placed under a group on save.
 */
trait NewsletterSegmentTrait
{
    public function getNewsletterSegmentName(): string
    {
        return \trim((string) ($this->getName() ?? $this->getKey()));
    }

    public function getNewsletterSegmentGroup(): NewsletterSegmentGroupInterface
    {
        // Walk up through any object node — including DataObject\Folder, which is an
        // AbstractObject but not a Concrete — so segments nested in folders still
        // resolve to the group above them. The match itself is the interface check.
        $node = $this->getParent();

        while ($node instanceof AbstractObject) {
            if ($node instanceof NewsletterSegmentGroupInterface) {
                return $node;
            }

            $node = $node->getParent();
        }

        throw SegmentPlacementException::forPath($this->getRealFullPath());
    }

    /**
     * Provided by the DataObject; the segment's display name. Falls back to the
     * object key when not set.
     */
    abstract public function getName(): ?string;
}
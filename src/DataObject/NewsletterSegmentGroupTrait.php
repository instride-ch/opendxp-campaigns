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
use OpenDxp\Model\DataObject\AbstractObject;

/**
 * Default NewsletterSegmentGroupInterface implementation for OpenDXP DataObjects.
 *
 * Apply to any Concrete class that implements NewsletterSegmentGroupInterface.
 * Segments are collected from the object subtree (descending through folders) so
 * the tree layout is the single source of truth: any segment placed under this
 * group — directly or via folders — belongs to it. Nested groups own their own
 * subtree and are not descended into.
 */
trait NewsletterSegmentGroupTrait
{
    public function getNewsletterSegmentGroupName(): string
    {
        return \trim((string) ($this->getName() ?? $this->getKey()));
    }

    /**
     * @return iterable<NewsletterSegmentInterface>
     */
    public function getNewsletterSegments(): iterable
    {
        return $this->collectNewsletterSegments($this);
    }

    public function getNewsletterListNames(): array
    {
        return \array_values(\array_filter(
            $this->getLists() ?? [],
            static fn (mixed $list): bool => \is_string($list) && $list !== '',
        ));
    }

    /**
     * @return list<NewsletterSegmentInterface>
     */
    private function collectNewsletterSegments(AbstractObject $node): array
    {
        $segments = [];

        foreach ($node->getChildren([AbstractObject::OBJECT_TYPE_OBJECT, AbstractObject::OBJECT_TYPE_FOLDER]) as $child) {
            if ($child instanceof NewsletterSegmentGroupInterface) {
                // A nested group owns its own segments; do not steal them.
                continue;
            }

            if ($child instanceof NewsletterSegmentInterface) {
                $segments[] = $child;

                continue;
            }

            if ($child instanceof AbstractObject) {
                // Folder (or other container): descend to find nested segments.
                $segments = \array_merge($segments, $this->collectNewsletterSegments($child));
            }
        }

        return $segments;
    }

    /**
     * Provided by the DataObject; the group's display name. Falls back to the key.
     */
    abstract public function getName(): ?string;

    /**
     * Provided by the DataObject; the multiselect of target list identifiers.
     *
     * @return array<int, string>|null
     */
    abstract public function getLists(): ?array;
}
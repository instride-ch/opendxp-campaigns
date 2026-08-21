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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\DataObject;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentGroupInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\NewsletterSegmentTrait;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\SegmentPlacementException;
use OpenDxp\Model\DataObject\Folder;

/**
 * A segment finds its group by walking up the object tree, which is what lets editors drop folders
 * in between for their own ordering without breaking the export.
 */
class NewsletterSegmentTraitTest extends Unit
{
    public function testTheGroupIsFoundDirectlyAbove(): void
    {
        $group = $this->group();
        $segment = new TraitTestSegment('Fassade', $group);

        $this->assertSame($group, $segment->getNewsletterSegmentGroup());
    }

    public function testTheGroupIsFoundThroughFolders(): void
    {
        $group = $this->group();
        $outer = $this->createMock(Folder::class);
        $outer->method('getParent')->willReturn($group);
        $inner = $this->createMock(Folder::class);
        $inner->method('getParent')->willReturn($outer);

        $segment = new TraitTestSegment('Fassade', $inner);

        $this->assertSame($group, $segment->getNewsletterSegmentGroup());
    }

    public function testASegmentOutsideAnyGroupIsRefused(): void
    {
        $segment = new TraitTestSegment('Verirrt', $this->createMock(Folder::class));

        $this->expectException(SegmentPlacementException::class);

        $segment->getNewsletterSegmentGroup();
    }

    public function testTheKeyStandsInForAMissingName(): void
    {
        $segment = new TraitTestSegment(null, null, 'fassade');

        $this->assertSame('fassade', $segment->getNewsletterSegmentName());
    }

    private function group(): NewsletterSegmentGroupInterface&Folder
    {
        return $this->createMock(TraitTestGroup::class);
    }
}

/**
 * A group has to be both an object in the tree and a segment group for the walk to end on it.
 */
abstract class TraitTestGroup extends Folder implements NewsletterSegmentGroupInterface
{
}

class TraitTestSegment
{
    use NewsletterSegmentTrait;

    public function __construct(
        private readonly ?string $name,
        private readonly ?object $parent = null,
        private readonly string $key = 'segment',
    ) {}

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getParent(): ?object
    {
        return $this->parent;
    }

    public function getRealFullPath(): string
    {
        return '/Newsletter/Segmente/' . $this->key;
    }
}

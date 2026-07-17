<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\EventListener;

use Codeception\Test\Unit;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentGroupInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\EventListener\SegmentNameValidationListener;
use OpenDxp\Event\Model\DataObjectEvent;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\Element\ValidationException;

abstract class ValidationTestSegment extends Concrete implements NewsletterSegmentInterface {}
abstract class ValidationTestGroup extends Concrete implements NewsletterSegmentGroupInterface {}

class SegmentNameValidationListenerTest extends Unit
{
    private SegmentNameValidationListener $listener;

    protected function setUp(): void
    {
        $this->listener = new SegmentNameValidationListener();
    }

    public function testValidGroupNamePasses(): void
    {
        $group = $this->createMock(ValidationTestGroup::class);
        $group->method('getNewsletterSegmentGroupName')->willReturn('Sports & Leisure');

        $this->listener->onPreWrite(new DataObjectEvent($group));

        $this->assertTrue(true); // no exception thrown
    }

    /**
     * @dataProvider invalidNames
     */
    public function testInvalidGroupNameThrows(string $name): void
    {
        $group = $this->createMock(ValidationTestGroup::class);
        $group->method('getNewsletterSegmentGroupName')->willReturn($name);

        $this->expectException(ValidationException::class);
        $this->listener->onPreWrite(new DataObjectEvent($group));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'asterisk' => ['Sport*'];
        yield 'pipe' => ['A|B'];
        yield 'colon' => ['A:B'];
        yield 'comma' => ['A,B'];
        yield 'empty' => ['   '];
    }

    public function testSegmentWithoutGroupThrowsPlacementError(): void
    {
        $segment = $this->createMock(ValidationTestSegment::class);
        $segment->method('getNewsletterSegmentName')->willReturn('Tennis');
        $segment->method('getNewsletterSegmentGroup')
            ->willThrowException(new \RuntimeException('no group'));

        $this->expectException(ValidationException::class);
        $this->listener->onPreWrite(new DataObjectEvent($segment));
    }

    public function testDuplicateSegmentNameWithinGroupThrows(): void
    {
        $existing = $this->createMock(ValidationTestSegment::class);
        $existing->method('getId')->willReturn(1);
        $existing->method('getNewsletterSegmentName')->willReturn('Tennis');

        $group = $this->createMock(ValidationTestGroup::class);
        $group->method('getNewsletterSegmentGroupName')->willReturn('Sports');
        $group->method('getNewsletterSegments')->willReturn([$existing]);

        $segment = $this->createMock(ValidationTestSegment::class);
        $segment->method('getId')->willReturn(2);
        $segment->method('getNewsletterSegmentName')->willReturn('Tennis');
        $segment->method('getNewsletterSegmentGroup')->willReturn($group);

        $this->expectException(ValidationException::class);
        $this->listener->onPreWrite(new DataObjectEvent($segment));
    }

    public function testUniqueSegmentNameWithinGroupPasses(): void
    {
        $existing = $this->createMock(ValidationTestSegment::class);
        $existing->method('getId')->willReturn(1);
        $existing->method('getNewsletterSegmentName')->willReturn('Football');

        $group = $this->createMock(ValidationTestGroup::class);
        $group->method('getNewsletterSegments')->willReturn([$existing]);

        $segment = $this->createMock(ValidationTestSegment::class);
        $segment->method('getId')->willReturn(2);
        $segment->method('getNewsletterSegmentName')->willReturn('Tennis');
        $segment->method('getNewsletterSegmentGroup')->willReturn($group);

        $this->listener->onPreWrite(new DataObjectEvent($segment));

        $this->assertTrue(true); // no exception thrown
    }
}
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

namespace Instride\Bundle\OpenDxpCampaignsBundle\EventListener;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentGroupInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentInterface;
use OpenDxp\Event\Model\DataObjectEvent;
use OpenDxp\Model\Element\ValidationException;

/**
 * Validates newsletter segments and segment groups before they are written, so
 * only export-ready objects reach the provider.
 *
 * Enforces three rules on opendxp.dataobject.preAdd / preUpdate:
 *  1. Names are non-empty and free of the characters * | : , which are reserved
 *     by Mailchimp's *|INTERESTED:group:segments|* conditional merge tag.
 *  2. A segment lives under a segment group in the object tree (placement).
 *  3. Segment names are unique within their group (names address interests in the
 *     merge tag, so duplicates would be ambiguous).
 *
 * Throws {@see ValidationException} so the admin UI surfaces a clean message.
 */
final readonly class SegmentNameValidationListener
{
    private const string INVALID_CHARS = '*|:,';

    /**
     * Handles both opendxp.dataobject.preAdd and opendxp.dataobject.preUpdate.
     */
    public function onPreWrite(DataObjectEvent $event): void
    {
        // Version-only saves (auto-save drafts) are not real state changes.
        if ($event->hasArgument('saveVersionOnly') && $event->getArgument('saveVersionOnly') === true) {
            return;
        }

        $object = $event->getObject();

        if ($object instanceof NewsletterSegmentGroupInterface) {
            $this->validateName($object->getNewsletterSegmentGroupName());

            return;
        }

        if ($object instanceof NewsletterSegmentInterface) {
            $this->validateName($object->getNewsletterSegmentName());
            $group = $this->resolveGroup($object);
            $this->ensureUniqueWithinGroup($object, $group);
        }
    }

    private function validateName(string $name): void
    {
        if (\trim($name) === '') {
            throw new ValidationException('Segment/Group name is required for export to the newsletter provider.');
        }

        if (\strpbrk($name, self::INVALID_CHARS) !== false) {
            throw new ValidationException(\sprintf(
                'Segment/Group name may not contain any of the following characters: %s',
                \implode(' ', \str_split(self::INVALID_CHARS)),
            ));
        }
    }

    private function resolveGroup(NewsletterSegmentInterface $segment): NewsletterSegmentGroupInterface
    {
        try {
            return $segment->getNewsletterSegmentGroup();
        } catch (\Throwable $exception) {
            throw new ValidationException(
                'A newsletter segment must be placed under a newsletter segment group in the object tree.',
                previous: $exception,
            );
        }
    }

    private function ensureUniqueWithinGroup(NewsletterSegmentInterface $segment, NewsletterSegmentGroupInterface $group): void
    {
        $name = $segment->getNewsletterSegmentName();

        foreach ($group->getNewsletterSegments() as $other) {
            if ($other->getId() === $segment->getId()) {
                continue;
            }

            if ($other->getNewsletterSegmentName() === $name) {
                throw new ValidationException(\sprintf(
                    'Segment name "%s" is already in use within group "%s".',
                    $name,
                    $group->getNewsletterSegmentGroupName(),
                ));
            }
        }
    }
}
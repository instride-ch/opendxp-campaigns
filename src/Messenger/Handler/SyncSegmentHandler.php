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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Handler;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Message\SyncSegmentMessage;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\SegmentExporter;
use OpenDxp\Model\DataObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SyncSegmentHandler
{
    public function __construct(
        private SegmentExporter $exporter,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(SyncSegmentMessage $message): void
    {
        $object = DataObject::getById($message->objectId);

        if (!$object instanceof NewsletterSegmentInterface) {
            $this->logger->warning(
                '[OpenDXP Campaigns] Segment {id} not found or not a NewsletterSegment, skipping.',
                ['id' => $message->objectId],
            );

            return;
        }

        $this->exporter->exportSegment($object);
    }
}
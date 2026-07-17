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

use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Message\DeleteSegmentGroupMessage;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\SegmentExporter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class DeleteSegmentGroupHandler
{
    public function __construct(
        private SegmentExporter $exporter,
    ) {}

    public function __invoke(DeleteSegmentGroupMessage $message): void
    {
        $this->exporter->deleteGroup($message->remoteIdsByList);
    }
}
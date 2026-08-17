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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Message;

use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\CampaignsMessageInterface;

/**
 * Dispatched when a segment group DataObject is deleted.
 *
 * The provider remote IDs are captured at delete time (preDelete) because the
 * object and its Notes no longer exist when an async worker runs.
 */
final readonly class DeleteSegmentGroupMessage implements CampaignsMessageInterface
{
    /**
     * @param array<string, string> $remoteIdsByList list identifier => provider group ID
     */
    public function __construct(
        public array $remoteIdsByList,
    ) {}
}
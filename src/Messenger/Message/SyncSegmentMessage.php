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

/**
 * Dispatched when a segment DataObject is created or updated and should be
 * (re-)exported to the newsletter provider.
 *
 * Idempotent: the handler ensures the parent group exists first, then upserts
 * the segment, so replays are safe and retryable.
 */
final readonly class SyncSegmentMessage
{
    public function __construct(
        public int $objectId,
    ) {}
}
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
 * Dispatched when a member's newsletter data should be synchronized to a list.
 *
 * This message is idempotent: dispatching it multiple times for the same
 * member and list will always produce the same result (current member state
 * wins). It is safe to retry on failure.
 *
 * Either memberId or memberEmail must be provided so the handler can resolve
 * the concrete Member object via MemberResolverInterface.
 */
final readonly class SyncMemberToListMessage
{
    public function __construct(
        public string $listName,
        public string|int $memberValue,
    ) {
    }
}

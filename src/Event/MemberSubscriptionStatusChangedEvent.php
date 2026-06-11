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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Event;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;

/**
 * Dispatched after a member's subscription status has been updated and persisted.
 *
 * Listen to this event to react to provider-side status changes (e.g. send
 * confirmation emails, update CRM records, etc.).
 */
final readonly class MemberSubscriptionStatusChangedEvent
{
    public function __construct(
        public NewsletterMemberInterface $member,
        public string $listName,
        public string $previousStatus,
        public string $newStatus,
        /** The source of the change, e.g. 'webhook.mailchimp', 'sync.command'. */
        public string $source,
    ) {}
}

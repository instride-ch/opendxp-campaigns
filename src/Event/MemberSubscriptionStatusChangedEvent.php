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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Event;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a member's subscription status has been updated and persisted.
 *
 * Listen to this event to react to provider-side status changes (e.g. send
 * confirmation emails, update CRM records, etc.).
 */
final class MemberSubscriptionStatusChangedEvent extends Event
{
    public function __construct(
        private readonly NewsletterMemberInterface $member,
        private readonly string $listName,
        private readonly ?SubscriptionStatus $previousStatus,
        private readonly SubscriptionStatus $newStatus,
        private readonly string $source,
    ) {}

    /**
     * The member whose subscription status changed.
     */
    public function getMember(): NewsletterMemberInterface
    {
        return $this->member;
    }

    /**
     * The configured list name for which the status changed.
     */
    public function getListName(): string
    {
        return $this->listName;
    }

    /**
     * The previous subscription status.
     */
    public function getPreviousStatus(): ?SubscriptionStatus
    {
        return $this->previousStatus;
    }

    /**
     * The new subscription status.
     */
    public function getNewStatus(): SubscriptionStatus
    {
        return $this->newStatus;
    }

    /**
     * The source of the change, e.g. 'webhook.mailchimp', 'sync.command'
     */
    public function getSource(): string
    {
        return $this->source;
    }
}

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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Newsletter;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;

/**
 * Primary service API for newsletter operations.
 *
 * Passing null for $listName applies the operation to all configured lists.
 * String members (email-only) are supported for simple lookups; full
 * NewsletterMemberInterface objects are required for sync operations.
 */
interface NewsletterManagerInterface
{
    /**
     * Creates or updates the member at the provider as subscribed.
     */
    public function subscribe(NewsletterMemberInterface|string $member, ?string $listName = null): void;

    public function unsubscribe(NewsletterMemberInterface|string $member, ?string $listName = null): void;

    public function subscribeOrUpdate(NewsletterMemberInterface|string $member, ?string $listName = null): void;

    public function delete(NewsletterMemberInterface|string $member, ?string $listName = null): void;

    /**
     * @return array<string, mixed>|null
     */
    public function getMember(string $email, string $listName): ?array;

    public function hasMember(string $email, string $listName): bool;

    public function isSubscribed(string $email, string $listName): bool;

    /**
     * Synchronize a member to all configured lists based on their current subscription status.
     */
    public function syncMember(NewsletterMemberInterface $member): void;

    /**
     * Synchronize a member to a single configured list.
     */
    public function syncMemberToList(NewsletterMemberInterface $member, string $listName): void;
}
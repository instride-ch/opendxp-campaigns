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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Contract;

use Carbon\CarbonInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\RemoteMember;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;

/**
 * Abstraction layer for newsletter provider integrations.
 *
 * Drivers must be stateless with respect to connector config — all configs are
 * provided at construction time. Implement TemplateExportCapableInterface
 * separately if the provider supports template export.
 */
interface NewsletterDriverInterface
{
    /**
     * The driver name as used in bundle configuration (e.g. 'mailchimp', 'log').
     */
    public function getName(): string;

    /**
     * Subscribe a new member to the given provider list.
     *
     * @param string                $listId      the provider-side list/audience ID
     * @param string                $email       subscriber email
     * @param array<string, scalar> $mergeFields provider merge fields
     * @param string[]              $interestIds provider-specific interest IDs to enable
     * @param SubscriptionStatus    $status      desired subscription status
     */
    public function subscribe(
        string $listId,
        string $email,
        array $mergeFields = [],
        array $interestIds = [],
        SubscriptionStatus $status = SubscriptionStatus::SUBSCRIBED,
    ): void;

    /**
     * Unsubscribe a member from the given provider list.
     */
    public function unsubscribe(string $listId, string $email): void;

    /**
     * Subscribe or update an existing member (upsert).
     *
     * Drivers should implement this as an idempotent operation so it is safe
     * to call repeatedly during resync.
     *
     * @param array<string, scalar> $mergeFields
     * @param string[]              $interestIds
     */
    public function subscribeOrUpdate(
        string $listId,
        string $email,
        array $mergeFields = [],
        array $interestIds = [],
        SubscriptionStatus $status = SubscriptionStatus::SUBSCRIBED,
    ): void;

    /**
     * Permanently delete a member from the given provider list.
     */
    public function delete(string $listId, string $email): void;

    /**
     * Retrieve raw member data from the provider, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function getMember(string $listId, string $email): ?array;

    /**
     * Check whether a member record exists on the provider list.
     */
    public function hasMember(string $listId, string $email): bool;

    /**
     * Check whether a member is actively subscribed (status = subscribed).
     */
    public function isSubscribed(string $listId, string $email): bool;

    /**
     * Yield members whose provider record changed at or after $since.
     *
     * Implementations should page through the provider API internally and yield
     * one normalized RemoteMember per record (generator) to keep memory bounded
     * regardless of list size.
     *
     * @param string $listId the provider-side list/audience ID
     *
     * @return iterable<RemoteMember>
     */
    public function listChangedMembers(string $listId, CarbonInterface $since): iterable;
}

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
     * $mayOverwriteStatus is the caller's permission to change the status of a member the provider
     * already knows. False means: create the member with this status if it is unknown, otherwise
     * leave its status untouched, so a resync cannot undo an unsubscribe made at the provider.
     * Merge fields and interests are written either way.
     *
     * A driver whose API cannot express this must refrain from writing the status at all rather
     * than write it anyway — not changing it is always the safe reading.
     *
     * @param array<string, mixed> $mergeFields
     * @param string[]             $interestIds
     * @param string[]             $managedInterestIds
     */
    public function subscribeOrUpdate(
        string $listId,
        string $email,
        array $mergeFields = [],
        array $interestIds = [],
        SubscriptionStatus $status = SubscriptionStatus::SUBSCRIBED,
        bool $mayOverwriteStatus = true,
        array $managedInterestIds = [],
    ): void;

    /**
     * Permanently delete a member from the given provider list.
     *
     * Irreversible where the provider says so: Mailchimp refuses to re-import an address that was
     * permanently deleted ("the contact must re-subscribe"). Use {@see self::archive()} whenever the
     * address might come back.
     */
    public function delete(string $listId, string $email): void;

    /**
     * Take a member off the given provider list while keeping the address usable.
     *
     * Used when an address changed on our side: the entry under the previous address has to go, but
     * a later change back to it must still be possible. A driver whose provider knows only one kind
     * of removal, and that one reversible, may point this at the same call as delete().
     */
    public function archive(string $listId, string $email): void;

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

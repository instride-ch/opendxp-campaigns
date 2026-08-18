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

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\ListConfig;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\SegmentPlacementException;
use Psr\Log\LoggerInterface;

class NewsletterManager implements NewsletterManagerInterface
{
    /** @var array<string, string[]> managed segment remote IDs per list, resolved once per run */
    private array $managedInterestIds = [];

    public function __construct(
        private readonly DriverRegistry $registry,
        private readonly ?string $defaultListName,
        private readonly MergeFieldMapper $mapper,
        private readonly RemoteIdStore $remoteIds,
        private readonly OutboundSyncSuppressor $suppressor,
        private readonly LoggerInterface $logger,
        private readonly ManagedSegmentInterestsInterface $segmentExporter,
    ) {}

    public function subscribe(NewsletterMemberInterface|string $member, ?string $listName = null): void
    {
        $resolvedList = $this->resolveListName($listName);
        $email = $this->resolveEmail($member);
        $listConfig = $this->registry->getListConfig($resolvedList);

        // An upsert, not a create: the provider knows addresses it has archived or that unsubscribed
        // long ago, and answers a plain create with "is already a list member".
        if ($member instanceof NewsletterMemberInterface) {
            $this->writeMember($member, $listConfig, $resolvedList, SubscriptionStatus::SUBSCRIBED, true);

            return;
        }

        $this->registry->getDriverForList($resolvedList)->subscribeOrUpdate($listConfig->providerListId, $email);
    }

    public function unsubscribe(NewsletterMemberInterface|string $member, ?string $listName = null): void
    {
        $resolvedList = $this->resolveListName($listName);
        $email = $this->resolveEmail($member);
        $listConfig = $this->registry->getListConfig($resolvedList);

        $this->registry->getDriverForList($resolvedList)->unsubscribe($listConfig->providerListId, $email);

        if ($member instanceof NewsletterMemberInterface) {
            $this->recordSubscription($member, $resolvedList, SubscriptionStatus::UNSUBSCRIBED, $email);
        }
    }

    public function subscribeOrUpdate(NewsletterMemberInterface|string $member, ?string $listName = null): void
    {
        $resolvedList = $this->resolveListName($listName);
        $email = $this->resolveEmail($member);
        $listConfig = $this->registry->getListConfig($resolvedList);

        if ($member instanceof NewsletterMemberInterface) {
            $mergeFields = $this->mapper->toProvider($member, $listConfig->mergeFieldMappings);
            $interestIds = $this->interestIdsForList($member, $listConfig);
        }

        $this->registry->getDriverForList($resolvedList)->subscribeOrUpdate(
            $listConfig->providerListId,
            $email,
            $mergeFields ?? [],
            $interestIds ?? [],
        );
    }

    public function delete(NewsletterMemberInterface|string $member, ?string $listName = null): void
    {
        $resolvedList = $this->resolveListName($listName);
        $email = $this->resolveEmail($member);
        $listConfig = $this->registry->getListConfig($resolvedList);

        $this->registry->getDriverForList($resolvedList)->delete($listConfig->providerListId, $email);

        if ($member instanceof NewsletterMemberInterface) {
            // The entry is gone for good, so what the member says about it can only be wrong.
            $this->recordSubscription($member, $resolvedList, SubscriptionStatus::UNSUBSCRIBED, $email);
        }
    }

    public function getMember(string $email, string $listName): ?array
    {
        $listConfig = $this->registry->getListConfig($listName);

        return $this->registry->getDriverForList($listName)->getMember($listConfig->providerListId, $email);
    }

    public function hasMember(string $email, string $listName): bool
    {
        $listConfig = $this->registry->getListConfig($listName);

        return $this->registry->getDriverForList($listName)->hasMember($listConfig->providerListId, $email);
    }

    public function isSubscribed(string $email, string $listName): bool
    {
        $listConfig = $this->registry->getListConfig($listName);

        return $this->registry->getDriverForList($listName)->isSubscribed($listConfig->providerListId, $email);
    }

    public function syncMember(NewsletterMemberInterface $member): void
    {
        foreach ($this->registry->getListNames() as $listName) {
            $this->syncMemberToList($member, $listName);
        }
    }

    public function syncMemberToList(NewsletterMemberInterface $member, string $listName): void
    {
        $listConfig = $this->registry->getListConfig($listName);
        $driver = $this->registry->getDriverForList($listName);
        $email = $member->getNewsletterEmail();
        $status = $member->getNewsletterSubscriptionStatus($listName);

        // A status we do not hold means unsubscribed, as in the Customer Management Framework:
        // its status mapping has no entry for an empty value and falls back to unsubscribed, and
        // it exports every published, active customer rather than only those carrying a status.
        // Run a pull before the first push, or this turns everyone unknown into an unsubscribe.
        $status ??= SubscriptionStatus::UNSUBSCRIBED;

        $providerStatus = $member->getNewsletterProviderStatus($listName);
        $wasDropped = $this->dropRenamedEntry($member, $listName, $listConfig, $driver, $email);

        if ($wasDropped) {
            // Whatever the provider held went with the old address.
            $providerStatus = null;
        }

        if ($status === SubscriptionStatus::UNSUBSCRIBED) {
            // Nothing to unsubscribe when the entry is already gone, and nothing to do when the
            // provider is known to hold that status anyway.
            if (!$wasDropped && $providerStatus !== SubscriptionStatus::UNSUBSCRIBED) {
                $driver->unsubscribe($listConfig->providerListId, $email);
            }

            $this->recordSubscription($member, $listName, $status, $email);

            return;
        }

        // Only claim the right to change the status when it differs from what the provider holds.
        $this->writeMember($member, $listConfig, $listName, $status, $providerStatus !== $status);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveListName(?string $listName): string
    {
        $resolved = $listName ?? $this->defaultListName;

        if ($resolved === null) {
            throw new \LogicException(
                'No list name provided and no default_list_name is configured. '
                . 'Pass an explicit list name or set default_list_name in opendxp_campaigns configuration.',
            );
        }

        return $resolved;
    }

    private function resolveEmail(NewsletterMemberInterface|string $member): string
    {
        return $member instanceof NewsletterMemberInterface ? $member->getNewsletterEmail() : $member;
    }

    /**
     * Resolve the provider interest IDs a member should carry on a specific list.
     *
     * Only segments whose group targets this list are considered, and only those
     * already exported (i.e. with a stored remote ID) are included. Not-yet-exported
     * segments are skipped; the segment sync flow / backup command backfill them.
     *
     * @return string[]
     */
    private function interestIdsForList(NewsletterMemberInterface $member, ListConfig $listConfig): array
    {
        $interestIds = [];

        foreach ($member->getNewsletterSegments() as $segment) {
            try {
                $listNames = $segment->getNewsletterSegmentGroup()->getNewsletterListNames();
            } catch (SegmentPlacementException $exception) {
                // One segment lying outside a group is no reason to leave the member unsynchronised.
                $this->logger->warning('[OpenDXP Campaigns] {message} Skipping it for this member.', [
                    'message' => $exception->getMessage(),
                    'member_id' => $member->getId(),
                ]);

                continue;
            }

            if (!\in_array($listConfig->identifier, $listNames, true)) {
                continue;
            }

            $remoteId = $this->remoteIds->getRemoteId($segment, $listConfig->connectorName, $listConfig->identifier);

            if ($remoteId !== null) {
                $interestIds[] = $remoteId;
            }
        }

        return $interestIds;
    }

    /**
     * Writes the member at the provider and records what was written.
     *
     * Both ways in end here — a signup from the website and a push from the command line — so the
     * merge fields, the interests, the tags and the record on the member cannot drift apart
     * between them.
     */
    private function writeMember(
        NewsletterMemberInterface $member,
        ListConfig $listConfig,
        string $listName,
        SubscriptionStatus $status,
        bool $mayOverwriteStatus,
    ): void {
        $this->registry->getDriverForList($listName)->subscribeOrUpdate(
            $listConfig->providerListId,
            $member->getNewsletterEmail(),
            $this->mapper->toProvider($member, $listConfig->mergeFieldMappings),
            $this->interestIdsForList($member, $listConfig),
            $status,
            mayOverwriteStatus: $mayOverwriteStatus,
            managedInterestIds: $this->managedInterestIds($listName),
        );

        $this->recordSubscription($member, $listName, $status, $member->getNewsletterEmail());
    }

    /**
     * The segment remote IDs this list manages, resolved once and kept for the run.
     *
     * A push walks thousands of members and the answer is the same for all of them, so asking the
     * segment provider per member would list every segment object over and over.
     *
     * @return string[]
     */
    private function managedInterestIds(string $listName): array
    {
        return $this->managedInterestIds[$listName] ??= $this->segmentExporter->managedSegmentRemoteIds($listName);
    }

    /**
     * Writes what the provider now holds onto the member, creating the list's entry when there was
     * none — a first-time signup has nothing to update.
     *
     * Every call that changes a member at the provider ends here, so the two never drift: a
     * subscription nobody recorded reads as an unsubscribe on the next push, and an unsubscribe
     * nobody recorded leaves the member looking subscribed until the next pull. A push carries the
     * status it just read off the member, so there this writes nothing and saves nothing — which
     * matters, because the setter walks the whole fieldcollection on every member of a full run.
     *
     * Saving looks like a member edit to the outbound listener, hence the suppressor.
     */
    private function recordSubscription(
        NewsletterMemberInterface $member,
        string $listName,
        SubscriptionStatus $status,
        string $email,
    ): void {
        $changed = false;

        if ($member->getNewsletterSubscriptionStatus($listName) !== $status) {
            $member->setNewsletterSubscriptionStatus($listName, $status);
            $changed = true;
        }

        if ($member->getNewsletterProviderStatus($listName) !== $status) {
            $member->setNewsletterProviderStatus($listName, $status);
            $changed = true;
        }

        if ($member->getNewsletterProviderEmail($listName) !== $email) {
            $member->setNewsletterProviderEmail($listName, $email);
            $changed = true;
        }

        if ($changed) {
            $this->suppressor->suppress(static fn (): mixed => $member->save());
        }
    }

    /**
     * A provider identifies a member by their address, so an address changed in the PIM reaches it
     * as a second member while the first stays behind, subscribed. The Customer Management Framework
     * deletes and recreates for the same reason (SingleExporter::handleChangedEmail); it carries the
     * previous address in its queue item, we hold it on the member instead so every path is covered
     * — a save, a command line push, a rerun after an abort.
     *
     * Archived rather than deleted: a permanent delete locks the address out at Mailchimp, so a
     * member renamed back to a previous address could never be reached again.
     *
     * Returns whether an entry was actually removed.
     */
    private function dropRenamedEntry(
        NewsletterMemberInterface $member,
        string $listName,
        ListConfig $listConfig,
        NewsletterDriverInterface $driver,
        string $email,
    ): bool {
        $providerEmail = $member->getNewsletterProviderEmail($listName);

        // Addresses are matched case-insensitively, or a mere change of case would drop and recreate.
        if ($providerEmail === null || \mb_strtolower($providerEmail) === \mb_strtolower($email)) {
            return false;
        }

        $driver->archive($listConfig->providerListId, $providerEmail);

        $this->logger->info('[OpenDXP Campaigns] Removed the entry left behind by an address change.', [
            'member_id' => $member->getId(),
            'list_name' => $listName,
            'previous_email' => $providerEmail,
        ]);

        return true;
    }
}

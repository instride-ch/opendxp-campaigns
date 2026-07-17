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
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterSegmentInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\ListConfig;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;

readonly class NewsletterManager implements NewsletterManagerInterface
{
    public function __construct(
        private DriverRegistry $registry,
        private ?string $defaultListName,
        private MergeFieldMapper $mapper,
        private RemoteIdStore $remoteIds,
    ) {}

    public function subscribe(NewsletterMemberInterface|string $member, ?string $listName = null): void
    {
        $resolvedList = $this->resolveListName($listName);
        $email = $this->resolveEmail($member);
        $listConfig = $this->registry->getListConfig($resolvedList);

        if ($member instanceof NewsletterMemberInterface) {
            $mergeFields = $this->mapper->toProvider($member, $listConfig->mergeFieldMappings);
            $interestIds = $this->interestIdsForList($member, $listConfig);
        }

        $this->registry->getDriverForList($resolvedList)->subscribe(
            $listConfig->providerListId,
            $email,
            $mergeFields ?? [],
            $interestIds ?? [],
        );
    }

    public function unsubscribe(NewsletterMemberInterface|string $member, ?string $listName = null): void
    {
        $resolvedList = $this->resolveListName($listName);
        $email = $this->resolveEmail($member);
        $listConfig = $this->registry->getListConfig($resolvedList);

        $this->registry->getDriverForList($resolvedList)->unsubscribe($listConfig->providerListId, $email);
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
        $status = $member->getNewsletterSubscriptionStatus($listName);

        // Treat unknown status as subscribable — let the provider decide
        $resolvedStatus = $status ?? SubscriptionStatus::SUBSCRIBED;

        if ($resolvedStatus === SubscriptionStatus::UNSUBSCRIBED) {
            $driver->unsubscribe($listConfig->providerListId, $member->getNewsletterEmail());

            return;
        }

        $driver->subscribeOrUpdate(
            $listConfig->providerListId,
            $member->getNewsletterEmail(),
            $this->mapper->toProvider($member, $listConfig->mergeFieldMappings),
            $this->interestIdsForList($member, $listConfig),
            $resolvedStatus,
        );
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
            if (!$segment instanceof NewsletterSegmentInterface) {
                continue;
            }

            if (!\in_array($listConfig->identifier, $segment->getNewsletterSegmentGroup()->getNewsletterListNames(), true)) {
                continue;
            }

            $remoteId = $this->remoteIds->getRemoteId($segment, $listConfig->connectorName, $listConfig->identifier);

            if ($remoteId !== null) {
                $interestIds[] = $remoteId;
            }
        }

        return $interestIds;
    }
}

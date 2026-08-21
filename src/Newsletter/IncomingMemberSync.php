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

use Carbon\Carbon;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Instride\Bundle\OpenDxpCampaignsBundle\Event\MemberSubscriptionStatusChangedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use function Symfony\Component\String\u;

/**
 * Applies provider-side member state (subscription status, merge fields) back onto
 * an OpenDXP member for a single configured list.
 *
 * Shared by the incoming webhook (MailchimpWebhookController) and the pull command
 * (PullNewsletterCommand) so both entry points mutate members identically. Callers
 * are responsible for persisting the member (save()) once, after applying changes.
 */
final readonly class IncomingMemberSync
{
    public function __construct(
        private DriverRegistry $registry,
        private MergeFieldMapper $mapper,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Applies the given subscription status to the member for one list.
     *
     * Idempotent: when the member already carries this status for the list, nothing
     * is mutated, no event is dispatched and false is returned — so repeated syncs
     * do not create redundant object versions. When the status differs, it is set,
     * the last-sync timestamp is bumped, a MemberSubscriptionStatusChangedEvent is
     * dispatched and true is returned.
     *
     * @param string $providerEmail the address the provider holds the member under
     * @param string $source        origin of the change, e.g. 'webhook.mailchimp' or 'sync.pull'
     */
    public function applyStatus(
        NewsletterMemberInterface $member,
        string $listName,
        SubscriptionStatus $status,
        string $providerEmail,
        string $source,
    ): bool {
        $previousStatus = $member->getNewsletterSubscriptionStatus($listName);

        // What the provider reports is worth keeping even when our own status already matches:
        // the push path compares against it to decide whether it may overwrite the provider.
        $providerChanged = $member->getNewsletterProviderStatus($listName) !== $status;

        // Not merely idempotence: the setter walks the whole fieldcollection and reassigns it,
        // which is real work on every unchanged member of a full pull.
        if ($providerChanged) {
            $member->setNewsletterProviderStatus($listName, $status);
        }

        // The address the provider answered under. Recording it here arms the push path to clean
        // up after an address change for members that were only ever pulled, never pushed.
        if ($member->getNewsletterProviderEmail($listName) !== $providerEmail) {
            $member->setNewsletterProviderEmail($listName, $providerEmail);
            $providerChanged = true;
        }

        if ($previousStatus === $status) {
            return $providerChanged;
        }

        $member->setNewsletterSubscriptionStatus($listName, $status);
        $member->setNewsletterLastSyncDate($listName, Carbon::now());

        $this->eventDispatcher->dispatch(new MemberSubscriptionStatusChangedEvent(
            member: $member,
            listName: $listName,
            previousStatus: $previousStatus,
            newStatus: $status,
            source: $source,
        ));

        return true;
    }

    /**
     * Applies provider merge field values to the member for one list.
     *
     * Provider tags are translated to local fields via the list's merge_fields
     * configuration. Only fields whose value actually differs from the current
     * value are written, so unchanged members are not marked dirty. When at least
     * one field changed the last-sync timestamp is bumped and true is returned.
     *
     * @param array<string, scalar> $providerMergeFields providerTag → value from the provider
     */
    public function applyMergeFields(
        NewsletterMemberInterface $member,
        string $listName,
        array $providerMergeFields,
    ): bool {
        if ($providerMergeFields === []) {
            return false;
        }

        $listConfig = $this->registry->getListConfig($listName);

        if ($listConfig->mergeFieldMappings === []) {
            return false;
        }

        $localFields = $this->mapper->fromProvider($providerMergeFields, $listConfig->mergeFieldMappings);
        $changed = false;

        foreach ($localFields as $localField => $value) {
            $setter = u($localField)
                ->pascal()
                ->ensureStart('set')
                ->toString();

            if (!\method_exists($member, $setter)) {
                continue;
            }

            $getter = u($localField)
                ->pascal()
                ->ensureStart('get')
                ->toString();

            if (\method_exists($member, $getter) && $member->$getter() === $value) {
                continue;
            }

            $member->$setter($value);
            $changed = true;
        }

        if ($changed) {
            $member->setNewsletterLastSyncDate($listName, Carbon::now());
        }

        return $changed;
    }
}

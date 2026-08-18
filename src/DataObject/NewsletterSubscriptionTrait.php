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

namespace Instride\Bundle\OpenDxpCampaignsBundle\DataObject;

use Carbon\Carbon;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use OpenDxp\Model\DataObject\Fieldcollection;
use OpenDxp\Model\DataObject\Fieldcollection\Data\AbstractData;
use OpenDxp\Model\DataObject\Fieldcollection\Data\CampaignNewsletterSubscription;

/**
 * Implements NewsletterMemberInterface subscription state management via a Fieldcollection field.
 *
 * Apply this trait to any DataObject class that also implements NewsletterMemberInterface.
 * The DataObject must have a Fieldcollections field named `newsletterSubscriptions` with
 * CampaignNewsletterSubscription as an allowed type.
 */
trait NewsletterSubscriptionTrait
{
    public function getNewsletterSubscriptionStatus(string $listKey): ?SubscriptionStatus
    {
        foreach ($this->getSubscriptionItemsForList($listKey) as $item) {
            return SubscriptionStatus::tryFrom($item->getStatus() ?? '');
        }

        return null;
    }

    public function setNewsletterSubscriptionStatus(string $listKey, SubscriptionStatus $status): void
    {
        $this->updateSubscriptionItemForList(
            $listKey,
            static function (CampaignNewsletterSubscription $item) use ($status): void {
                $item->setStatus($status->value);
            },
        );
    }

    public function getNewsletterProviderStatus(string $listKey): ?SubscriptionStatus
    {
        foreach ($this->getSubscriptionItemsForList($listKey) as $item) {
            return SubscriptionStatus::tryFrom($item->getProviderStatus() ?? '');
        }

        return null;
    }

    public function setNewsletterProviderStatus(string $listKey, SubscriptionStatus $status): void
    {
        $this->updateSubscriptionItemForList(
            $listKey,
            static function (CampaignNewsletterSubscription $item) use ($status): void {
                $item->setProviderStatus($status->value);
            },
        );
    }

    public function getNewsletterProviderEmail(string $listKey): ?string
    {
        foreach ($this->getSubscriptionItemsForList($listKey) as $item) {
            return $item->getProviderEmail();
        }

        return null;
    }

    public function setNewsletterProviderEmail(string $listKey, string $email): void
    {
        $this->updateSubscriptionItemForList(
            $listKey,
            static function (CampaignNewsletterSubscription $item) use ($email): void {
                $item->setProviderEmail($email);
            },
        );
    }

    public function getNewsletterLastSyncDate(string $listKey): ?Carbon
    {
        foreach ($this->getSubscriptionItemsForList($listKey) as $item) {
            return $item->getLastSyncedAt();
        }

        return null;
    }

    public function setNewsletterLastSyncDate(string $listKey, Carbon $date): void
    {
        $this->updateSubscriptionItemForList(
            $listKey,
            static function (CampaignNewsletterSubscription $item) use ($date): void {
                $item->setLastSyncedAt($date);
            },
        );
    }

    /**
     * Returns the subscription item for the given list key if one exists.
     *
     * @return iterable<CampaignNewsletterSubscription>
     */
    private function getSubscriptionItemsForList(string $listKey): iterable
    {
        $collection = $this->getNewsletterSubscriptions();

        if ($collection === null) {
            return;
        }

        foreach ($collection->getItems() as $item) {
            if ($item instanceof CampaignNewsletterSubscription && $item->getNewsletterList() === $listKey) {
                yield $item;
            }
        }
    }

    /**
     * Applies the given mutation to the subscription item for the list key,
     * creating and appending a new item when none exists yet.
     *
     * @param callable(CampaignNewsletterSubscription): void $mutator
     */
    private function updateSubscriptionItemForList(string $listKey, callable $mutator): void
    {
        $collection = $this->getNewsletterSubscriptions() ?? new Fieldcollection();

        foreach ($collection->getItems() as $item) {
            if ($item instanceof CampaignNewsletterSubscription && $item->getNewsletterList() === $listKey) {
                $mutator($item);
                $this->setNewsletterSubscriptions($collection);

                return;
            }
        }

        $item = new CampaignNewsletterSubscription();
        $item->setNewsletterList($listKey);
        $mutator($item);
        $collection->add($item);

        $this->setNewsletterSubscriptions($collection);
    }

    /**
     * @return Fieldcollection<AbstractData>|null
     */
    abstract public function getNewsletterSubscriptions(): ?Fieldcollection;

    /**
     * @param Fieldcollection<AbstractData>|null $newsletterSubscriptions
     */
    abstract public function setNewsletterSubscriptions(?Fieldcollection $newsletterSubscriptions): static;
}

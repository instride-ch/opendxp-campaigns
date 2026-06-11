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

use OpenDxp\Model\DataObject\Fieldcollection;
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
    public function getNewsletterSubscriptionStatus(string $listKey): ?string
    {
        foreach ($this->getSubscriptionItemsForList($listKey) as $item) {
            return $item->getStatus();
        }

        return null;
    }

    public function setNewsletterSubscriptionStatus(string $listKey, string $status): void
    {
        $collection = $this->getNewsletterSubscriptions() ?? new Fieldcollection();
        $found = false;

        foreach ($collection->getItems() as $item) {
            if ($item instanceof CampaignNewsletterSubscription && $item->getListKey() === $listKey) {
                $item->setStatus($status);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $item = new CampaignNewsletterSubscription();
            $item->setListKey($listKey);
            $item->setStatus($status);
            $collection->add($item);
        }

        $this->setNewsletterSubscriptions($collection);
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
            if ($item instanceof CampaignNewsletterSubscription && $item->getListKey() === $listKey) {
                yield $item;
            }
        }
    }

    abstract public function getNewsletterSubscriptions(): ?Fieldcollection;

    abstract public function setNewsletterSubscriptions(?Fieldcollection $newsletterSubscriptions): static;
}

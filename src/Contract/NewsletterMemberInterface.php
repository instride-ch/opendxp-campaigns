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

use OpenDxp\Model\Element\ElementInterface;

/**
 * Contract for application-specific member objects that participate in newsletter synchronization.
 *
 * The bundle owns no concrete Member entity. The application implements this interface
 * on its own Member class. Subscription status changes are persisted by the bundle via
 * the save() method inherited from ElementInterface.
 */
interface NewsletterMemberInterface extends ElementInterface
{
    /**
     * The email address used for newsletter subscriptions.
     */
    public function getNewsletterEmail(): string;

    /**
     * The segments (interests) this member belongs to, across all groups.
     *
     * @return iterable<NewsletterSegmentInterface>
     */
    public function getNewsletterSegments(): iterable;

    /**
     * Returns the current subscription status for the given list key, or null if unknown.
     *
     * @param string $listKey the configured list identifier (YAML key under `lists:`)
     */
    public function getNewsletterSubscriptionStatus(string $listKey): ?string;

    /**
     * Stores the subscription status for the given list key.
     *
     * The application must persist this change — the bundle only calls this setter.
     * Dispatch a domain event or flush from an event listener to persist.
     *
     * @param string $listKey  the configured list identifier (YAML key under `lists:`)
     * @param string $status   one of the SubscriptionStatus enum values
     */
    public function setNewsletterSubscriptionStatus(string $listKey, string $status): void;
}
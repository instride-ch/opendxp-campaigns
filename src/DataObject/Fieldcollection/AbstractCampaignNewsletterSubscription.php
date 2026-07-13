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

namespace Instride\Bundle\OpenDxpCampaignsBundle\DataObject\Fieldcollection;

use Carbon\Carbon;
use OpenDxp\Model\DataObject\Fieldcollection\Data\AbstractData;

/**
 * Abstract parent class for the CampaignNewsletterSubscription fieldcollection.
 *
 * OpenDXP generates a concrete subclass at var/classes/DataObject/Fieldcollection/Data/CampaignNewsletterSubscription.php.
 * The bundle sets this class as the parentClass in the fieldcollection definition so that
 * NewsletterSubscriptionTrait can type-hint against it and access all fields in a typed way.
 */
abstract class AbstractCampaignNewsletterSubscription extends AbstractData
{
    abstract public function getNewsletterList(): ?string;

    abstract public function setNewsletterList(?string $listKey): static;

    abstract public function getStatus(): ?string;

    abstract public function setStatus(?string $status): static;

    abstract public function getProviderMemberId(): ?string;

    abstract public function setProviderMemberId(?string $providerMemberId): static;

    abstract public function getLastSyncedAt(): ?Carbon;

    abstract public function setLastSyncedAt(?Carbon $lastSyncedAt): static;
}

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

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use OpenDxp\Model\DataObject\Concrete;
use OpenDxp\Model\DataObject\Listing\Concrete as ConcreteListing;

/**
 * Provides newsletter members from an OpenDXP DataObject class.
 *
 * The configured class must extend Concrete and implement NewsletterMemberInterface.
 * OpenDXP generates a Listing class at <MemberClass>\Listing for each Concrete subclass;
 * this provider uses that generated listing to yield all published objects.
 */
final readonly class DataObjectMemberProvider implements MemberProviderInterface
{
    use BatchedListingTrait;

    /**
     * @param class-string<Concrete&NewsletterMemberInterface> $memberClass
     */
    public function __construct(private string $memberClass) {}

    public function findAll(): iterable
    {
        yield from $this->members();
    }

    public function findByList(string $listName): iterable
    {
        yield from $this->members(static function (ConcreteListing $listing) use ($listName): void {
            $listing->addFieldCollection('CampaignNewsletterSubscription');
            $listing->setCondition('`CampaignNewsletterSubscription`.`newsletterList` = :list', [
                'list' => $listName,
            ]);
        });
    }

    public function hasMemberSyncedFromProvider(string $listName): bool
    {
        $listingClass = $this->memberClass . '\\Listing';
        /** @var ConcreteListing $listing */
        $listing = new $listingClass();
        $listing->addFieldCollection('CampaignNewsletterSubscription');
        $listing->setCondition(
            '`CampaignNewsletterSubscription`.`newsletterList` = :list'
            . ' AND `CampaignNewsletterSubscription`.`lastSyncedAt` IS NOT NULL',
            ['list' => $listName],
        );

        return $listing->count() > 0;
    }

    /**
     * @return iterable<NewsletterMemberInterface>
     */
    private function members(?callable $configure = null): iterable
    {
        foreach ($this->iterateBatched($this->memberClass, $configure) as $object) {
            if ($object instanceof NewsletterMemberInterface) {
                yield $object;
            }
        }
    }
}

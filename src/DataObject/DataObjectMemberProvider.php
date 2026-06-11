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
    private const int BATCH_SIZE = 100;

    /**
     * @param class-string<Concrete&NewsletterMemberInterface> $memberClass
     */
    public function __construct(private string $memberClass) {}

    public function findAll(): iterable
    {
        yield from $this->iterateBatched();
    }

    public function findByList(string $listName): iterable
    {
        yield from $this->iterateBatched( static function (ConcreteListing $listing) use ($listName): void {
            $listing->addFieldCollection('CampaignNewsletterSubscription');
            $listing->setCondition('`CampaignNewsletterSubscription`.`listKey` = :list', [
                'list' => $listName,
            ]);
        });
    }

    private function iterateBatched(?callable $configure = null): iterable
    {
        $offset = 0;

        do {
            $listing = $this->createListing();
            $listing->setLimit(self::BATCH_SIZE);
            $listing->setOffset($offset);

            if ($configure !== null) {
                $configure($listing);
            }

            $objects = $listing->getObjects();
            $fetched = \count($objects);

            foreach ($objects as $object) {
                if ($object instanceof NewsletterMemberInterface) {
                    yield $object;
                }
            }

            unset($objects, $listing);
            $offset += self::BATCH_SIZE;
        } while ($fetched === self::BATCH_SIZE);
    }

    private function createListing(): ConcreteListing
    {
        /** @var class-string<ConcreteListing> $listingClass */
        $listingClass = $this->memberClass . '\\Listing';

        return new $listingClass();
    }
}

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

use OpenDxp\Cache\RuntimeCache;
use OpenDxp\Model\DataObject\Listing\Concrete as ConcreteListing;

/**
 * Walks a DataObject class in pages instead of loading it whole.
 *
 * A listing is an Iterator but fetches every row first, which is no way to cross an audience of
 * thousands. Each page also drops what OpenDXP kept in its runtime cache, or a full push ends up
 * holding every member it has ever touched.
 */
trait BatchedListingTrait
{
    private const int BATCH_SIZE = 100;

    /**
     * @param class-string $class
     * @param ?callable(ConcreteListing): void $configure
     *
     * @return iterable<object>
     */
    private function iterateBatched(string $class, ?callable $configure = null): iterable
    {
        /** @var class-string<ConcreteListing> $listingClass */
        $listingClass = $class . '\\Listing';
        $offset = 0;

        do {
            $listing = new $listingClass();
            $listing->setLimit(self::BATCH_SIZE);
            $listing->setOffset($offset);

            if ($configure !== null) {
                $configure($listing);
            }

            $objects = $listing->getObjects();
            $fetched = \count($objects);

            yield from $objects;

            unset($objects, $listing);
            RuntimeCache::clear();

            $offset += self::BATCH_SIZE;
        } while ($fetched === self::BATCH_SIZE);
    }
}

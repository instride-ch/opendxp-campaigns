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

/**
 * Provides iterable access to all Member objects for bulk synchronization.
 */
interface MemberProviderInterface
{
    /**
     * Yield all members that should be synchronized to newsletter lists.
     *
     * Implementations should use lazy loading / generators to avoid loading
     * large member collections into memory at once.
     *
     * @return iterable<NewsletterMemberInterface>
     */
    public function findAll(): iterable;

    /**
     * Yield members that should be synchronized to a specific configured list.
     *
     * @return iterable<NewsletterMemberInterface>
     */
    public function findByList(string $listName): iterable;
}

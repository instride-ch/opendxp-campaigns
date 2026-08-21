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
 * Stands in for both member services while neither member_class nor an own service is configured.
 *
 * The container has to compile either way — a bundle that cannot be installed before it is
 * configured is no use. So the failure waits until something actually asks for a member, and then
 * says which key is missing.
 */
final readonly class UnconfiguredMemberSource implements MemberResolverInterface, MemberProviderInterface
{
    public function resolveById(int $id): ?NewsletterMemberInterface
    {
        throw $this->missingConfiguration('member_resolver');
    }

    public function resolveByEmail(string $email): ?NewsletterMemberInterface
    {
        throw $this->missingConfiguration('member_resolver');
    }

    public function findAll(): iterable
    {
        throw $this->missingConfiguration('member_provider');
    }

    public function findByList(string $listName): iterable
    {
        throw $this->missingConfiguration('member_provider');
    }

    public function hasMemberSyncedFromProvider(string $listName): bool
    {
        throw $this->missingConfiguration('member_provider');
    }

    private function missingConfiguration(string $key): \RuntimeException
    {
        return new \RuntimeException(\sprintf(
            'No newsletter %s is configured. Set opendxp_campaigns.member_class to a DataObject class'
            . ' implementing NewsletterMemberInterface, or point opendxp_campaigns.%s at your own service.',
            \str_replace('_', ' ', $key),
            $key,
        ));
    }
}

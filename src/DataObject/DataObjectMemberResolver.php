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

/**
 * Resolves newsletter members from an OpenDXP DataObject class.
 *
 * The configured class must extend Concrete and implement NewsletterMemberInterface.
 * resolveByEmail() calls the auto-generated static finder `getBy<emailField>()` on the
 * DataObject class. Configure `email_field` in the bundle config to match your field name.
 */
final readonly class DataObjectMemberResolver implements MemberResolverInterface
{
    /**
     * @param class-string<Concrete&NewsletterMemberInterface> $memberClass
     */
    public function __construct(
        private string $memberClass,
        private string $emailField = 'email',
    ) {}

    public function resolveByEmail(string $email): ?NewsletterMemberInterface
    {
        $finder = 'getBy' . \ucfirst($this->emailField);
        $object = $this->memberClass::$finder($email, 1);

        return $object instanceof NewsletterMemberInterface ? $object : null;
    }

    public function resolveById(int $id): ?NewsletterMemberInterface
    {
        $object = $this->memberClass::getById($id);

        return $object instanceof NewsletterMemberInterface ? $object : null;
    }
}

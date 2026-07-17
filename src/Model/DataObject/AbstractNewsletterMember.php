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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Model\DataObject;

use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\NewsletterSubscriptionTrait;
use OpenDxp\Model\DataObject\Concrete;

/**
 * Ready-to-extend base class for a newsletter member DataObject.
 *
 * Set this as the parent class of your generated Member class (the Installer's
 * NewsletterMember class definition does this by default). The generated class
 * provides the `email` input field and the `newsletterSubscriptions`
 * Fieldcollections field that the subscription trait relies on; the segment
 * membership comes from a `newsletterSegments` object relation.
 */
abstract class AbstractNewsletterMember extends Concrete implements NewsletterMemberInterface
{
    use NewsletterSubscriptionTrait;

    abstract public function getEmail(): ?string;

    public function getNewsletterEmail(): string
    {
        $email = $this->getEmail();

        if (empty($email)) {
            throw new \RuntimeException('Email is required for newsletter subscription.');
        }

        return $email;
    }
}

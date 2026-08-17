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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Handler;

use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberResolverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Messenger\Message\SyncMemberToListMessage;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\NewsletterManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SyncMemberToListHandler
{
    public function __construct(
        private NewsletterManagerInterface $newsletterManager,
        private MemberResolverInterface $memberResolver,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(SyncMemberToListMessage $message): void
    {
        $member = \is_numeric($message->memberValue)
            ? $this->memberResolver->resolveById((int) $message->memberValue)
            : $this->memberResolver->resolveByEmail($message->memberValue);

        if ($member === null) {
            $this->logger->warning(
                '[OpenDXP Campaigns] Could not resolve member for SyncMemberToListMessage, skipping.',
                [
                    'member_value' => $message->memberValue,
                    'list_name' => $message->listName,
                ],
            );

            return;
        }

        $this->newsletterManager->syncMemberToList($member, $message->listName);
    }
}

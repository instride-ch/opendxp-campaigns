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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Driver\Log;

use Carbon\CarbonInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Psr\Log\LoggerInterface;

/**
 * No-op driver that logs all operations without making external API calls.
 *
 * Use this driver for local development or when testing newsletter integration
 * without connecting to a live provider account.
 */
readonly class LogDriver implements NewsletterDriverInterface
{
    public function __construct(
        private string $connectorName,
        private LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'log';
    }

    public function subscribe(
        string $listId,
        string $email,
        array $mergeFields = [],
        array $interestIds = [],
        SubscriptionStatus $status = SubscriptionStatus::SUBSCRIBED,
    ): void {
        $this->log('subscribe', $listId, $email, [
            'merge_fields' => $mergeFields,
            'interest_ids' => $interestIds,
            'status' => $status->value,
        ]);
    }

    public function unsubscribe(string $listId, string $email): void
    {
        $this->log('unsubscribe', $listId, $email);
    }

    public function subscribeOrUpdate(
        string $listId,
        string $email,
        array $mergeFields = [],
        array $interestIds = [],
        SubscriptionStatus $status = SubscriptionStatus::SUBSCRIBED,
    ): void {
        $this->log('subscribeOrUpdate', $listId, $email, [
            'merge_fields' => $mergeFields,
            'interest_ids' => $interestIds,
            'status' => $status->value,
        ]);
    }

    public function delete(string $listId, string $email): void
    {
        $this->log('delete', $listId, $email);
    }

    public function getMember(string $listId, string $email): ?array
    {
        $this->log('getMember', $listId, $email);

        return null;
    }

    public function hasMember(string $listId, string $email): bool
    {
        $this->log('hasMember', $listId, $email);

        return false;
    }

    public function isSubscribed(string $listId, string $email): bool
    {
        $this->log('isSubscribed', $listId, $email);

        return false;
    }

    public function listChangedMembers(string $listId, CarbonInterface $since): iterable
    {
        $this->log('listChangedMembers', $listId, '', [
            'since' => $since->toIso8601String(),
        ]);

        return [];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $operation, string $listId, string $email, array $context = []): void
    {
        if ($email !== '') {
            $context = \array_merge(['email' => $email], $context);
        }

        $this->logger->info(
            \sprintf('[OpenDXP Campaigns][log driver][%s] %s → list %s', $this->connectorName, $operation, $listId),
            $context,
        );
    }
}

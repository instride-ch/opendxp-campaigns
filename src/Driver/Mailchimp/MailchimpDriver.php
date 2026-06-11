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

namespace Instride\Bundle\OpenDxpCampaignsBundle\Driver\Mailchimp;

use DrewM\MailChimp\MailChimp;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\TemplateExportCapableInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\DriverException;
use Instride\Bundle\OpenDxpCampaignsBundle\Template\TemplateExport;
use Psr\Log\LoggerInterface;

/**
 * Mailchimp newsletter driver using the drewm/mailchimp-api package.
 *
 * Segments map to Mailchimp Interest Categories / Interests so that the
 * *|INTERESTED:<group>:<segments>|* merge tag works correctly. Interest IDs
 * passed via interestIds[] are used directly as Mailchimp interest IDs.
 *
 * Requires drewm/mailchimp-api to be installed:
 *   composer require drewm/mailchimp-api
 */
class MailchimpDriver implements NewsletterDriverInterface, TemplateExportCapableInterface
{
    private ?MailChimp $client = null;

    public function __construct(
        private readonly string $connectorName,
        private readonly string $apiKey,
        private readonly ?string $webhookSecret,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'mailchimp';
    }

    public function subscribe(
        string $listId,
        string $email,
        array $mergeFields = [],
        array $interestIds = [],
        string $status = SubscriptionStatus::SUBSCRIBED->value,
    ): void {
        $payload = $this->buildMemberPayload($email, $mergeFields, $interestIds, $status);

        $result = $this->client()->post("lists/{$listId}/members", $payload);

        $this->assertSuccess('subscribe', $result);
    }

    public function unsubscribe(string $listId, string $email): void
    {
        $hash = $this->subscriberHash($email);

        $result = $this->client()->patch("lists/{$listId}/members/{$hash}", [
            'status' => SubscriptionStatus::UNSUBSCRIBED->value,
        ]);

        $this->assertSuccess('unsubscribe', $result);
    }

    public function subscribeOrUpdate(
        string $listId,
        string $email,
        array $mergeFields = [],
        array $interestIds = [],
        string $status = SubscriptionStatus::SUBSCRIBED->value,
    ): void {
        $hash = $this->subscriberHash($email);
        $payload = $this->buildMemberPayload($email, $mergeFields, $interestIds, $status);

        // PUT is the Mailchimp upsert endpoint — idempotent and safe to retry
        $result = $this->client()->put("lists/{$listId}/members/{$hash}", $payload);

        $this->assertSuccess('subscribeOrUpdate', $result);
    }

    public function delete(string $listId, string $email): void
    {
        $hash = $this->subscriberHash($email);

        // Use permanent delete endpoint so the email can be re-added later
        $result = $this->client()->post("lists/{$listId}/members/{$hash}/actions/delete-permanent");

        // 204 No Content is the success response for permanent delete
        if ($this->client()->getLastResponse()['headers']['http_code'] === 204) {
            return;
        }

        $this->assertSuccess('delete', $result);
    }

    public function getMember(string $listId, string $email): ?array
    {
        $hash = $this->subscriberHash($email);
        $result = $this->client()->get("lists/{$listId}/members/{$hash}");

        if ($this->client()->success()) {
            return $result;
        }

        // 404 means member not found — return null rather than throwing
        if (($result['status'] ?? 0) === 404) {
            return null;
        }

        $this->assertSuccess('getMember', $result);

        return null;
    }

    public function hasMember(string $listId, string $email): bool
    {
        return $this->getMember($listId, $email) !== null;
    }

    public function isSubscribed(string $listId, string $email): bool
    {
        $member = $this->getMember($listId, $email);

        return ($member['status'] ?? '') === SubscriptionStatus::SUBSCRIBED->value;
    }

    public function exportTemplate(TemplateExport $template): string
    {
        $existingId = $template->providerTemplateId ?? $this->findTemplateIdByName($template->name);

        if ($existingId !== null) {
            $result = $this->client()->patch("templates/{$existingId}", [
                'name' => $template->name,
                'html' => $template->html,
            ]);
            $this->assertSuccess('exportTemplate (update)', $result);

            return $existingId;
        }

        $result = $this->client()->post('templates', [
            'name' => $template->name,
            'html' => $template->html,
        ]);
        $this->assertSuccess('exportTemplate (create)', $result);

        return (string) ($result['id'] ?? '');
    }

    public function getWebhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function client(): MailChimp
    {
        if ($this->client === null) {
            if (!\class_exists(MailChimp::class)) {
                throw new \LogicException(
                    'Package drewm/mailchimp-api is required for the Mailchimp driver. '
                    . 'Install it with: composer require drewm/mailchimp-api',
                );
            }

            $this->client = new MailChimp($this->apiKey);
        }

        return $this->client;
    }

    private function subscriberHash(string $email): string
    {
        return \md5(\strtolower(\trim($email)));
    }

    /**
     * @param array<string, scalar> $mergeFields
     * @param string[]              $interestIds
     * @return array<string, mixed>
     */
    private function buildMemberPayload(
        string $email,
        array $mergeFields,
        array $interestIds,
        string $status,
    ): array {
        $payload = [
            'email_address' => $email,
            'status' => $status,
        ];

        if (!empty($mergeFields)) {
            $payload['merge_fields'] = $mergeFields;
        }

        if (!empty($interestIds)) {
            // Mailchimp expects interests as {interestId: bool} — enable all provided IDs
            $payload['interests'] = \array_fill_keys($interestIds, true);
        }

        return $payload;
    }

    private function findTemplateIdByName(string $name): ?string
    {
        $result = $this->client()->get('templates', ['type' => 'user', 'count' => 1000]);

        if (!$this->client()->success()) {
            return null;
        }

        foreach ($result['templates'] ?? [] as $template) {
            if ($template['name'] === $name) {
                return (string) $template['id'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|false $result
     */
    private function assertSuccess(string $operation, array|false $result): void
    {
        if (!$this->client()->success()) {
            $detail = \is_array($result) ? ($result['detail'] ?? \json_encode($result)) : 'unknown error';

            $this->logger->error(
                \sprintf('[OpenDXP Campaigns][mailchimp][%s] %s failed: %s', $this->connectorName, $operation, $detail),
            );

            throw DriverException::apiError('mailchimp', \sprintf('%s: %s', $operation, $detail));
        }
    }
}

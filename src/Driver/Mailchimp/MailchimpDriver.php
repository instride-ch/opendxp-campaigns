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

use Carbon\CarbonInterface;
use DrewM\MailChimp\MailChimp;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\SegmentExportCapableInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\TemplateExportCapableInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\RemoteMember;
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
class MailchimpDriver implements NewsletterDriverInterface, TemplateExportCapableInterface, SegmentExportCapableInterface
{
    /**
     * Interest category display type. "hidden" keeps the grouping out of
     * subscriber-facing signup forms; segments are managed from OpenDXP.
     */
    private const string INTEREST_CATEGORY_TYPE = 'hidden';

    /**
     * Mailchimp caps the members endpoint at 1000 rows per page; 100 keeps each
     * response small while still limiting the number of round-trips.
     */
    private const int PAGE_SIZE = 100;

    /**
     * Only these fields are requested from the members endpoint so responses stay
     * small when paging through a full audience.
     */
    private const string LIST_FIELDS = 'members.email_address,members.status,members.merge_fields,members.id';


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
        SubscriptionStatus $status = SubscriptionStatus::SUBSCRIBED,
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
        SubscriptionStatus $status = SubscriptionStatus::SUBSCRIBED,
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
        $status = SubscriptionStatus::tryFrom($member['status'] ?? '');

        return $status === SubscriptionStatus::SUBSCRIBED;
    }

    public function listChangedMembers(string $listId, CarbonInterface $since): iterable
    {
        $offset = 0;

        do {
            $result = $this->client()->get("lists/{$listId}/members", [
                'since_last_changed' => $since->toAtomString(),
                'count' => self::PAGE_SIZE,
                'offset' => $offset,
                'fields' => self::LIST_FIELDS,
            ]);

            $this->assertSuccess('listChangedMembers', $result);

            $members = \is_array($result) ? ($result['members'] ?? []) : [];

            foreach ($members as $row) {
                yield $this->mapRemoteMember($row);
            }

            $fetched = \count($members);
            $offset += self::PAGE_SIZE;
        } while ($fetched === self::PAGE_SIZE);
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

    public function exportSegmentGroup(string $listId, string $name, ?string $remoteId): string
    {
        if ($remoteId !== null) {
            $result = $this->client()->patch("lists/{$listId}/interest-categories/{$remoteId}", [
                'title' => $name,
                'type' => self::INTEREST_CATEGORY_TYPE,
            ]);
            $this->assertSuccess('exportSegmentGroup (update)', $result);

            return $remoteId;
        }

        $result = $this->client()->post("lists/{$listId}/interest-categories", [
            'title' => $name,
            'type' => self::INTEREST_CATEGORY_TYPE,
        ]);
        $this->assertSuccess('exportSegmentGroup (create)', $result);

        return (string) ($result['id'] ?? '');
    }

    public function deleteSegmentGroup(string $listId, string $remoteId): void
    {
        $this->client()->delete("lists/{$listId}/interest-categories/{$remoteId}");
        $this->assertDeleted('deleteSegmentGroup');
    }

    public function exportSegment(string $listId, string $groupRemoteId, string $name, ?string $remoteId): string
    {
        $base = "lists/{$listId}/interest-categories/{$groupRemoteId}/interests";

        if ($remoteId !== null) {
            $result = $this->client()->patch("{$base}/{$remoteId}", ['name' => $name]);
            $this->assertSuccess('exportSegment (update)', $result);

            return $remoteId;
        }

        $result = $this->client()->post($base, ['name' => $name]);
        $this->assertSuccess('exportSegment (create)', $result);

        return (string) ($result['id'] ?? '');
    }

    public function deleteSegment(string $listId, string $groupRemoteId, string $remoteId): void
    {
        $this->client()->delete("lists/{$listId}/interest-categories/{$groupRemoteId}/interests/{$remoteId}");
        $this->assertDeleted('deleteSegment');
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
     * Maps a raw Mailchimp members row to a normalized RemoteMember.
     *
     * Statuses without an enum mapping (e.g. 'transactional', 'archived') become null,
     * so the caller applies merge fields but skips the status change.
     *
     * @param array<string, mixed> $row
     */
    private function mapRemoteMember(array $row): RemoteMember
    {
        $mergeFields = [];
        foreach (($row['merge_fields'] ?? []) as $tag => $value) {
            if (\is_scalar($value)) {
                $mergeFields[$tag] = $value;
            }
        }

        return new RemoteMember(
            email: (string) ($row['email_address'] ?? ''),
            status: SubscriptionStatus::tryFrom((string) ($row['status'] ?? '')),
            mergeFields: $mergeFields,
            providerMemberId: isset($row['id']) ? (string) $row['id'] : null,
        );
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
        SubscriptionStatus $status,
    ): array {
        $payload = [
            'email_address' => $email,
            'status' => $status->value,
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
     * Asserts a delete succeeded, treating an already-absent resource (404) as success
     * so deletes stay idempotent and safe to retry.
     */
    private function assertDeleted(string $operation): void
    {
        if ($this->client()->success()) {
            return;
        }

        if (($this->client()->getLastResponse()['headers']['http_code'] ?? 0) === 404) {
            return;
        }

        $this->logger->error(
            \sprintf('[OpenDXP Campaigns][mailchimp][%s] %s failed.', $this->connectorName, $operation),
        );

        throw DriverException::apiError('mailchimp', $operation);
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

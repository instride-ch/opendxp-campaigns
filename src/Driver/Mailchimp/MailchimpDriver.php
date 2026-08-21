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
     * Mailchimp caps the members endpoint at 1000 rows per page; 100 keeps each
     * response small while still limiting the number of round-trips.
     */
    private const int PAGE_SIZE = 100;

    /**
     * How an exported segment group is shown to subscribers, as the specification prescribes.
     */
    private const string INTEREST_CATEGORY_TYPE = 'checkboxes';

    /**
     * Only these fields are requested from the members endpoint so responses stay
     * small when paging through a full audience.
     */
    private const string LIST_FIELDS = 'members.email_address,members.status,members.merge_fields,members.id';

    /**
     * @param ?MailChimp $client Built from the API key on first use; a test passes its own.
     */
    /** @var array<string, string[]> interest IDs per audience, read once per run */
    private array $interestsByList = [];

    public function __construct(
        private readonly string $connectorName,
        private readonly string $apiKey,
        private readonly ?string $webhookSecret,
        private readonly LoggerInterface $logger,
        private ?MailChimp $client = null,
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

        // An address the audience never had is already as unsubscribed as it can be. It happens to
        // anyone deleted at the provider after OpenDXP recorded them, and failing the member over
        // it would repeat on every run.
        if ($this->isMissing()) {
            return;
        }

        $this->assertSuccess('unsubscribe', $result);
    }

    /**
     * @param array<string, mixed> $mergeFields
     * @param string[]             $interestIds
     * @param string[]             $managedInterestIds
     */
    public function subscribeOrUpdate(
        string $listId,
        string $email,
        array $mergeFields = [],
        array $interestIds = [],
        SubscriptionStatus $status = SubscriptionStatus::SUBSCRIBED,
        bool $mayOverwriteStatus = true,
        array $managedInterestIds = [],
    ): void {
        $hash = $this->subscriberHash($email);
        $held = $this->interestsHeldBy($listId);
        $payload = $this->buildMemberPayload(
            $email,
            $mergeFields,
            \array_values(\array_intersect($interestIds, $held)),
            $status,
            $mayOverwriteStatus,
            \array_values(\array_intersect($managedInterestIds, $held)),
        );

        // PUT is the Mailchimp upsert endpoint — idempotent and safe to retry
        $result = $this->client()->put("lists/{$listId}/members/{$hash}", $payload);

        $this->assertSuccess('subscribeOrUpdate', $result);
    }

    public function delete(string $listId, string $email): void
    {
        $hash = $this->subscriberHash($email);

        $result = $this->client()->post("lists/{$listId}/members/{$hash}/actions/delete-permanent");

        // 204 No Content is the success response for permanent delete
        if ($this->client()->getLastResponse()['headers']['http_code'] === 204) {
            return;
        }

        $this->assertSuccess('delete', $result);
    }

    public function archive(string $listId, string $email): void
    {
        $hash = $this->subscriberHash($email);

        // Mailchimp calls this DELETE, but it only archives: the member turns up as "archived" and a
        // later PUT brings them back. Measured against a trial audience — unlike delete-permanent,
        // which locks the address out for good.
        $result = $this->client()->delete("lists/{$listId}/members/{$hash}");

        $code = $this->client()->getLastResponse()['headers']['http_code'];

        // 204 is the success response. 404 means somebody removed the entry before us, which is the
        // state this call was after anyway. 405 is Mailchimp's answer for a contact it will not
        // archive — already archived, or pending, or bounced (measured 2026-08-20); the first is
        // done, and for the other two there is nothing an address change could still archive.
        // Without this a member whose old entry sits in one of those states never syncs again.
        if (\in_array($code, [204, 404, 405], true)) {
            if ($code === 405) {
                $this->logger->info(
                    '[OpenDXP Campaigns][mailchimp][{connector}] Provider will not archive {email}: {detail}',
                    [
                        'connector' => $this->connectorName,
                        'email' => $email,
                        'detail' => $result['detail'] ?? '',
                    ],
                );
            }

            return;
        }

        $this->assertSuccess('archive', $result);
    }

    public function getMember(string $listId, string $email): ?array
    {
        $hash = $this->subscriberHash($email);
        /** @var array<string, mixed> $result */
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
        $payload = ['name' => $template->name, 'html' => $template->html];
        $existingId = $template->providerTemplateId !== null
            ? $this->confirmTemplate($template->providerTemplateId)
            : null;

        if ($existingId !== null) {
            $result = $this->client()->patch("templates/{$existingId}", $payload);
            $this->assertSuccess('exportTemplate (update)', $result);

            return $existingId;
        }

        $result = $this->client()->post('templates', $payload);
        $this->assertSuccess('exportTemplate (create)', $result);

        return (string) ($result['id'] ?? '');
    }

    /**
     * A merge tag used as a link target looks like a relative URL to setAbsolutePaths(), which
     * would rewrite it into the site host. Masking it as a data: URI is what that function skips.
     */
    public function protectPlaceholders(string $html): string
    {
        return (string) \preg_replace('/\b(href|src)\s*=\s*(["\'])(\*\|[^"\']*\|\*)\2/i', '$1=$2data:$3$2', $html);
    }

    /**
     * Undoes the masking, and separately repairs merge tags inside otherwise absolute URLs,
     * which setAbsolutePaths() url-encodes while normalising the surrounding link.
     */
    public function restorePlaceholders(string $html): string
    {
        return \str_replace(['data:*|', '*%7C', '%7C*'], ['*|', '*|', '|*'], $html);
    }

    public function exportSegmentGroup(string $listId, string $name, ?string $remoteId): string
    {
        return $this->upsert(
            "lists/{$listId}/interest-categories",
            ['title' => $name, 'type' => self::INTEREST_CATEGORY_TYPE],
            $remoteId,
            'exportSegmentGroup',
        );
    }

    public function exportSegment(string $listId, string $groupRemoteId, string $name, ?string $remoteId): string
    {
        return $this->upsert(
            "lists/{$listId}/interest-categories/{$groupRemoteId}/interests",
            ['name' => $name],
            $remoteId,
            'exportSegment',
        );
    }

    public function deleteSegmentGroup(string $listId, string $remoteId): void
    {
        $this->client()->delete("lists/{$listId}/interest-categories/{$remoteId}");
        $this->assertDeleted('deleteSegmentGroup');
    }

    public function deleteSegment(string $listId, string $groupRemoteId, string $remoteId): void
    {
        $this->client()->delete("lists/{$listId}/interest-categories/{$groupRemoteId}/interests/{$remoteId}");
        $this->assertDeleted('deleteSegment');
    }

    /**
     * Updates what a stored ID names, or creates it again when the provider no longer has it.
     *
     * Interest categories and interests are deleted for good, unlike templates (measured
     * 2026-08-19: GET and PATCH on a deleted one both answer 404). Someone removing one in
     * Mailchimp would otherwise leave a stored ID that kills every later export.
     *
     * @param array<string, string> $payload
     */
    private function upsert(string $collection, array $payload, ?string $remoteId, string $operation): string
    {
        if ($remoteId !== null) {
            $result = $this->client()->patch("{$collection}/{$remoteId}", $payload);

            if (!$this->isMissing()) {
                $this->assertSuccess($operation . ' (update)', $result);

                return $remoteId;
            }

            $this->logger->info(
                '[OpenDXP Campaigns][mailchimp][{connector}] {operation}: {id} is gone; creating it again.',
                ['connector' => $this->connectorName, 'operation' => $operation, 'id' => $remoteId],
            );
        }

        $result = $this->client()->post($collection, $payload);
        $this->assertSuccess($operation . ' (create)', $result);

        return (string) ($result['id'] ?? '');
    }

    public function listSegmentGroups(string $listId): array
    {
        $result = $this->client()->get("lists/{$listId}/interest-categories", ['count' => 1000]);
        $groups = [];

        foreach ($result['categories'] ?? [] as $category) {
            $groups[(string) $category['id']] = (string) $category['title'];
        }

        return $groups;
    }

    public function listSegments(string $listId, string $groupRemoteId): array
    {
        $result = $this->client()->get(
            "lists/{$listId}/interest-categories/{$groupRemoteId}/interests",
            ['count' => 1000],
        );
        $segments = [];

        foreach ($result['interests'] ?? [] as $interest) {
            $segments[(string) $interest['id']] = (string) $interest['name'];
        }

        return $segments;
    }

    public function listMemberInterests(string $listId): iterable
    {
        $offset = 0;

        do {
            $result = $this->client()->get("lists/{$listId}/members", [
                'count' => self::PAGE_SIZE,
                'offset' => $offset,
                'fields' => 'members.email_address,members.interests',
            ]);
            $this->assertSuccess('listMemberInterests', $result);

            $members = $result['members'] ?? [];

            foreach ($members as $member) {
                yield (string) $member['email_address'] => \array_keys(
                    \array_filter($member['interests'] ?? [], static fn (mixed $held): bool => (bool) $held),
                );
            }

            $offset += self::PAGE_SIZE;
        } while (\count($members) === self::PAGE_SIZE);
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
     * The interest IDs this audience actually has, read once per list and kept for the run.
     *
     * Mailchimp answers a member write carrying one unknown interest ID with "Invalid interest ID"
     * and rejects the whole write — so a stored ID that outlived the interest it names would stop
     * every push. Nobody above this driver can know that rule, and nobody above it should.
     *
     * @return string[]
     */
    private function interestsHeldBy(string $listId): array
    {
        if (isset($this->interestsByList[$listId])) {
            return $this->interestsByList[$listId];
        }

        $held = [];

        foreach ($this->listSegmentGroups($listId) as $groupRemoteId => $ignored) {
            foreach ($this->listSegments($listId, $groupRemoteId) as $remoteId => $name) {
                $held[] = $remoteId;
            }
        }

        return $this->interestsByList[$listId] = $held;
    }

    /**
     * @param array<string, mixed> $mergeFields
     * @param string[]             $interestIds
     * @param string[]             $managedInterestIds
     *
     * @return array<string, mixed>
     */
    private function buildMemberPayload(
        string $email,
        array $mergeFields,
        array $interestIds,
        SubscriptionStatus $status,
        bool $mayOverwriteStatus = true,
        array $managedInterestIds = [],
    ): array {
        $payload = [
            'email_address' => $email,
            // Mailchimp's way of saying "only on create": status_if_new is ignored for a member it knows.
            $mayOverwriteStatus ? 'status' : 'status_if_new' => $status->value,
        ];

        if (!empty($mergeFields)) {
            $payload['merge_fields'] = $mergeFields;
        }

        // Mailchimp merges what it is sent, so listing only the active interests can never take one
        // away — measured 2026-08-19: a member whose segment was removed kept the interest. Every
        // interest we manage therefore travels explicitly, false for the ones the member lost.
        // Only ours: an interest somebody created at the provider by hand stays untouched.
        $known = \array_values(\array_unique(\array_merge($managedInterestIds, $interestIds)));

        if ($known !== []) {
            // array_replace, not array_merge: an interest ID of nothing but digits becomes an
            // integer array key, and array_merge renumbers integer keys from zero — which sent
            // Mailchimp keys it had never heard of and quietly dropped the interest.
            $payload['interests'] = \array_replace(
                \array_fill_keys($known, false),
                \array_fill_keys($interestIds, true),
            );
        }

        return $payload;
    }

    /**
     * Confirms a stored template ID before it is patched.
     *
     * Deletion in Mailchimp is soft, and both obvious shortcuts fail because of it (measured
     * 2026-08-17): a GET on a deleted template answers 200 and only reports active: false,
     * and a PATCH answers 200 while silently discarding the update. Without the active flag
     * a stale ID would swallow every later export without a trace.
     */
    private function confirmTemplate(string $remoteId): ?string
    {
        $result = $this->client()->get("templates/{$remoteId}");

        if (!$this->client()->success()) {
            return null;
        }

        return ($result['active'] ?? false) ? $remoteId : null;
    }

    /**
     * Whether the last call answered 404. Mailchimp deletes interest categories and interests for
     * good, so a stored id can simply be gone — which is recoverable, unlike a real failure.
     */
    private function isMissing(): bool
    {
        return ($this->client()->getLastResponse()['headers']['http_code'] ?? 0) === 404;
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

        if ($this->isMissing()) {
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

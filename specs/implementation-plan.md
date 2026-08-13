# OpenDXP Campaigns Bundle — Implementation Plan

## Resolved Design Questions

### 1. Mailchimp mapping for SegmentGroup / Segment
**Use Interest Categories (SegmentGroup) and Interests within them (Segment).**

Rationale:
- Interest Categories/Interests are the only Mailchimp model that supports the `*|INTERESTED:<group>:<segments>|*` merge tag natively.
- They model a parent-child hierarchy exactly matching SegmentGroup → Segment.
- Tags and Mailchimp Segments are *filtering* tools, not subscriber-profile fields — they do not work with the `INTERESTED` merge tag.

The member's `getNewsletterSegments()` returns objects whose `getNewsletterSegmentIdentifier()` returns the Mailchimp Interest ID directly (v1 simplicity; v2 can add a config mapping layer).

### 2. Multi-list subscription status storage
`NewsletterMemberInterface` defines `getNewsletterSubscriptionStatus(string $listKey): ?string` and `setNewsletterSubscriptionStatus(string $listKey, string $status): void`. The bundle only calls these methods; the application is responsible for persisting them (e.g. JSON column, separate table, etc.).

### 3. MemberResolver
The bundle defines `MemberResolverInterface`. **The app must register a service aliased to it** (via config `member_resolver:` key). The bundle ships no fallback — if the resolver is not configured, the webhook controller and sync command throw a clear `\RuntimeException` at runtime (not at boot).

### 4. Messenger Transport
The bundle defines messages and handlers only. **The app configures the transport and routing** via `config/packages/messenger.yaml`. This keeps the bundle non-opinionated about infrastructure.

### 5. Webhook URL
`/webhooks/campaigns/mailchimp/{connectorName}` — clean, provider-namespaced prefix.

---

## Architecture Overview

```
src/
├── OpenDxpCampaignsBundle.php          # Updated: registers extension, compiler passes
├── Contract/
│   ├── NewsletterMemberInterface.php   # App implements on Member entities
│   ├── NewsletterSegmentGroupInterface.php
│   ├── NewsletterSegmentInterface.php
│   ├── NewsletterDriverInterface.php   # subscribe/unsubscribe/update/delete/get
│   ├── TemplateExportCapableInterface.php  # Separate; MailchimpDriver implements both
│   ├── MemberResolverInterface.php     # App provides: email/id → Member
│   └── MemberProviderInterface.php     # App provides: iterate all members
├── Enum/
│   └── SubscriptionStatus.php          # Backed enum: SUBSCRIBED|UNSUBSCRIBED|PENDING|CLEANED
├── Exception/
│   ├── ConnectorNotFoundException.php
│   ├── ListNotFoundException.php
│   ├── DriverException.php
│   └── UnsupportedDriverOperationException.php
├── Driver/
│   ├── ConnectorConfig.php             # DTO: name, driver, config[]
│   ├── ListConfig.php                  # DTO: identifier, connectorName, providerListId, label
│   ├── DriverRegistry.php              # connector name → driver + list lookups
│   ├── Log/
│   │   └── LogDriver.php               # Logs all operations; no external calls
│   └── Mailchimp/
│       └── MailchimpDriver.php         # drewm/mailchimp-api wrapper
├── Newsletter/
│   ├── NewsletterManagerInterface.php  # subscribe/unsubscribe/subscribeOrUpdate/delete/get/sync
│   └── NewsletterManager.php           # Main service API
├── Template/
│   ├── TemplateExport.php              # Value object: name, html, ?providerTemplateId
│   └── TemplateExportService.php       # exportToConnector(connector, template): string
├── Messenger/
│   ├── Message/
│   │   └── SyncMemberToListMessage.php # email, listName, ?memberId
│   ├── Handler/
│   │   └── SyncMemberToListHandler.php # resolves member, calls manager
│   └── Event/
│       └── MemberSubscriptionStatusChangedEvent.php
├── Controller/
│   └── Webhook/
│       └── MailchimpWebhookController.php  # GET (verify) + POST (events)
├── Command/
│   └── PushNewsletterCommand.php       # campaigns:newsletter:push
└── DependencyInjection/
    ├── Configuration.php
    └── OpenDxpCampaignsExtension.php   # builds connector service defs programmatically

config/
├── services.yaml                       # imports sub-files
├── services/
│   ├── drivers.yaml                    # log driver registration
│   ├── manager.yaml
│   ├── messenger.yaml
│   ├── commands.yaml
│   └── controllers.yaml
└── opendxp/
    └── routing.yaml                    # webhook route
```

---

## Configuration Structure

```yaml
open_dxp_campaigns:
  member_resolver: 'App\Newsletter\MemberResolver'   # service id; required for webhooks + sync
  member_provider: 'App\Newsletter\MemberProvider'   # service id; required for bulk sync command

  connectors:
    main_mailchimp:
      driver: mailchimp
      config:
        api_key: '%env(MAILCHIMP_API_KEY)%'
        server_prefix: 'us21'
        webhook_secret: '%env(MAILCHIMP_WEBHOOK_SECRET)%'  # optional; validated as query param

    dev_logger:
      driver: log

  default_list_name: default_newsletter

  lists:
    default_newsletter:
      connector: main_mailchimp
      provider_list_id: 'abc123'
      label: 'Default Newsletter'

    product_updates:
      connector: main_mailchimp
      provider_list_id: 'def456'
      label: 'Product Updates'
```

---

## Interfaces (Contracts)

### `NewsletterMemberInterface`
```php
public function getNewsletterEmail(): string;
public function getNewsletterMergeFields(): array;            // ['FNAME' => 'John', ...]
public function getNewsletterSegments(): iterable;            // NewsletterSegmentInterface[]
public function getNewsletterSubscriptionStatus(string $listKey): ?string;
public function setNewsletterSubscriptionStatus(string $listKey, string $status): void;
```

### `NewsletterSegmentGroupInterface`
```php
public function getNewsletterGroupIdentifier(): string;  // maps to Mailchimp interest category ID
public function getNewsletterGroupName(): string;
```

### `NewsletterSegmentInterface`
```php
public function getNewsletterSegmentIdentifier(): string; // maps to Mailchimp interest ID
public function getNewsletterSegmentName(): string;
public function getNewsletterSegmentGroup(): NewsletterSegmentGroupInterface;
```

### `NewsletterDriverInterface`
```php
public function getName(): string;
public function subscribe(string $listId, string $email, array $mergeFields, array $interestIds, string $status): void;
public function unsubscribe(string $listId, string $email): void;
public function subscribeOrUpdate(string $listId, string $email, array $mergeFields, array $interestIds, string $status): void;
public function delete(string $listId, string $email): void;
public function getMember(string $listId, string $email): ?array;
public function hasMember(string $listId, string $email): bool;
public function isSubscribed(string $listId, string $email): bool;
```

### `TemplateExportCapableInterface`
```php
public function exportTemplate(TemplateExport $template): string; // returns provider template ID
```

### `MemberResolverInterface`
```php
public function resolveByEmail(string $email): ?NewsletterMemberInterface;
public function resolveById(string|int $id): ?NewsletterMemberInterface;
```

### `MemberProviderInterface`
```php
public function findAllSubscribable(): iterable; // all members that should be on any list
public function findByList(string $listName): iterable; // members assigned to a specific list
```

---

## Driver Registry

Built programmatically in the Extension at compile time:
- Maps connector name → `Reference` to driver service
- Maps list name → `ListConfig` DTO
- Provides `getDriverForConnector(string)`, `getDriverForList(string)`, `getListConfig(string)`

---

## DI Extension Behavior

1. For each `connector`, create a service `opendxp_campaigns.connector.<name>` of the correct driver class with constructor args from config.
2. Collect all connector References into `DriverRegistry` constructor argument.
3. Build `ListConfig[]` from `lists` config and pass to `DriverRegistry`.
4. If `member_resolver` key present, register alias `MemberResolverInterface → configured service`.
5. If `member_provider` key present, register alias `MemberProviderInterface → configured service`.
6. Load `services.yaml`.

---

## Newsletter Manager API

```php
interface NewsletterManagerInterface
{
    public function subscribe(NewsletterMemberInterface|string $member, ?string $listName = null): void;
    public function unsubscribe(NewsletterMemberInterface|string $member, ?string $listName = null): void;
    public function subscribeOrUpdate(NewsletterMemberInterface|string $member, ?string $listName = null): void;
    public function delete(NewsletterMemberInterface|string $member, ?string $listName = null): void;
    public function getMember(string $email, string $listName): ?array;
    public function hasMember(string $email, string $listName): bool;
    public function isSubscribed(string $email, string $listName): bool;
    public function syncMember(NewsletterMemberInterface $member): void;           // all lists
    public function syncMemberToList(NewsletterMemberInterface $member, string $listName): void;
}
```

When `$listName` is null, the operation is only applied to the default list.

---

## Messenger Flow

1. App dispatches `SyncMemberToListMessage(email, listName)` after a member changes.
2. `SyncMemberToListHandler` resolves the member via `MemberResolverInterface::resolveByEmail()`.
3. Handler calls `NewsletterManager::syncMemberToList()`.
4. `syncMemberToList` decides subscribe vs. update based on current subscription status.
5. If subscription status changes (webhook), `MemberSubscriptionStatusChangedEvent` is dispatched.
6. App listens and persists the updated status.

---

## Webhook Flow (Mailchimp)

- `GET /webhooks/campaigns/mailchimp/{connectorName}` → respond 200 OK (Mailchimp verification)
- `POST /webhooks/campaigns/mailchimp/{connectorName}` → validate optional secret, parse event:
  - `subscribe` → set status `subscribed`, dispatch `MemberSubscriptionStatusChangedEvent`
  - `unsubscribe` → set status `unsubscribed`
  - `cleaned` → set status `cleaned`
  - `profile` → dispatch `SyncMemberToListMessage` to pull latest data
  - `upemail` → update email (if resolver supports lookup by old email)

---

## Console Sync Command

```
bin/console opendxp:campaigns:newsletter:sync [options]

Options:
  --connector=<name>     Limit to a specific connector
  --list=<name>          Limit to a specific configured list
  --member=<id/email>    Sync a single member by ID or email
  --dry-run              Print what would happen without API calls
  --async                Dispatch Messenger messages instead of syncing inline
```

Without `--member`, iterates all members via `MemberProviderInterface`.

---

## Template Export

```php
// Value object
final class TemplateExport {
    public string $name;       // template name / slug used to find existing
    public string $html;       // full HTML content
    public ?string $providerTemplateId;  // skip lookup if already known
}

// Service
class TemplateExportService {
    public function exportToConnector(string $connectorName, TemplateExport $template): string;
}
```

`MailchimpDriver` (implements `TemplateExportCapableInterface`) searches by name first; creates or updates accordingly.

---

## Idempotency / Retry Safety

- `subscribeOrUpdate` uses Mailchimp's `PUT /lists/{id}/members/{hash}` — inherently upsert.
- `SyncMemberToListMessage` is idempotent: running it twice syncs the same current state.
- Messenger handles retries with backoff via standard transport config.
- The sync command can be run daily as a cronjob to catch missed webhook events.

---

## Design Refinements During Implementation

- **`NewsletterMemberInterface` extends `ElementInterface`** — gives the bundle access to `save()` for persisting status changes without a separate persistence service.
- **`default_list_name` config key** — operations without an explicit list name use this default rather than applying to all lists.
- **Testing stack**: Codeception + PHPStan (no PHP CS Fixer; no PHPUnit).
- **`MemberPersistenceInterface` was NOT added** — the bundle calls `$member->save()` directly via `ElementInterface`.

## Implementation Phases

| # | Phase | Files | Status |
|---|-------|-------|--------|
| 1 | Contracts & Enums | Contract/*, Enum/SubscriptionStatus.php | [x] |
| 2 | Exceptions | Exception/* | [x] |
| 3 | DTOs | Driver/ConnectorConfig.php, Driver/ListConfig.php, Template/TemplateExport.php | [x] |
| 4 | DI Configuration | DependencyInjection/Configuration.php, Extension.php | [x] |
| 5 | Driver Registry | Driver/DriverRegistry.php | [x] |
| 6 | Log Driver | Driver/Log/LogDriver.php | [x] |
| 7 | Mailchimp Driver | Driver/Mailchimp/MailchimpDriver.php | [x] |
| 8 | Newsletter Manager | Newsletter/NewsletterManagerInterface.php, NewsletterManager.php | [x] |
| 9 | Template Export Service | Template/TemplateExportService.php | [x] |
| 10 | Messenger | Messenger/Message/*, Handler/*, Event/* | [x] |
| 11 | Webhook Controller | Controller/Webhook/MailchimpWebhookController.php | [x] |
| 12 | Console Command | Command/PushNewsletterCommand.php | [x] |
| 13 | Service Config + Routing | config/** | [x] |
| 14 | Bundle Wiring | OpenDxpCampaignsBundle.php (update) | [x] |
| 15 | composer.json update | composer.json | [x] |
| 16 | Tests + Tooling | tests/Unit/**, codeception.yml, phpstan.neon | [x] |

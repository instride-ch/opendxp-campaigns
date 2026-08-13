# Newsletter Segmentation — Implementation Plan

Finalizes provider-independent **newsletter subscription segmentation** with Mailchimp as
the first driver. This document is the authoritative spec for the segmentation feature; it
supplements (does not replace) `specs/implementation-plan.md`, which covers member sync.

---

## 1. Resolved design decisions

All decisions below were confirmed with the product owner before implementation.

| # | Decision | Choice |
|---|----------|--------|
| D1 | Remote-ID model | **Note-backed, cached `RemoteIdStore`.** Drop the `getNewsletter*Identifier()` methods from both interfaces. The Mailchimp-assigned interest/category ID is the only real ID and is stored in an OpenDXP **Note** per `(object, connector, list)`. |
| D2 | Which list(s) a group exports to | **Configurable per Segment Group** via a **multiselect field** populated by the existing `NewsletterListOptionsProvider`. Export fans out to exactly those lists; one remote ID per `(object × list)`. |
| D3 | Segment → Group link | **Dynamic tree-walk** up `getParent()` (through folders) to the nearest `NewsletterSegmentGroupInterface`. No stored relation field. A **placement-validation listener** forbids saving a segment that has no ancestor group. |
| D4 | Developer experience | Ship **all four**: reusable **traits**, optional **abstract base classes**, **auto-registered listeners** (opt-in flag), and **Installer-provided class definitions** for Segment + SegmentGroup. |

### Mailchimp mapping (confirmed)

| Local | Mailchimp | API |
|-------|-----------|-----|
| `NewsletterSegmentGroupInterface` | Interest **Category** | `lists/{listId}/interest-categories` |
| `NewsletterSegmentInterface` | **Interest** (within a category) | `lists/{listId}/interest-categories/{catId}/interests` |

The `*|INTERESTED:GroupName:InterestA,InterestB|*` conditional merge tag references groups and
interests **by name**, which is why names must not contain `* | : ,` (the tag delimiters).
The **API**, however, addresses categories/interests **by ID** — hence the Note-backed store
(names for the merge tag, IDs for the API + member assignment).

---

## 2. Problems in the current draft (to fix)

1. **Fatal import bug** — `MailchimpDriver.php:29` imports `use function ECSPrefix202606\Symfony\Component\String\u;` (a php-scoper-prefixed namespace). Must be `Symfony\Component\String\u`. (Better: the driver does `md5(strtolower(trim($email)))` — a plain one-liner needs no String component at all.)
2. **No segment/group sync exists** — no driver methods, no messages, no handlers, no listeners.
3. **No remote-ID storage** — nothing writes/reads Notes; member export uses a client-generated slug (`slug_id`) as the Mailchimp interest ID, which cannot work.
4. **`BaseCustomerSegmentGroup::getNewsletterSegmentGroupIdentifier()` is an empty `// TODO` stub.**
5. **`SegmentNameValidationListener` is not registered** in `listeners.yaml`, so it never runs.
6. **`getNewsletterSegmentGroup()` in `BaseCustomerSegment`** relies on a `getGroup()` relation field instead of the required tree-walk.

---

## 3. Contracts (final shape)

Both interfaces continue to extend `OpenDxp\Model\Element\ElementInterface` (gives the bundle
`getId()`, `save()`, and Note attachment). The provider-specific `*Identifier()` getters are
**removed** — provider IDs are no longer a concern of the application object.

```php
interface NewsletterSegmentGroupInterface extends ElementInterface
{
    public function getNewsletterSegmentGroupName(): string;

    /** @return iterable<NewsletterSegmentInterface> */
    public function getNewsletterSegments(): iterable;

    /**
     * Configured list identifiers this group (and its segments) export to.
     * Backed by a multiselect field populated by NewsletterListOptionsProvider.
     *
     * @return string[]
     */
    public function getNewsletterListNames(): array;
}

interface NewsletterSegmentInterface extends ElementInterface
{
    public function getNewsletterSegmentName(): string;

    public function getNewsletterSegmentGroup(): NewsletterSegmentGroupInterface;
}
```

`NewsletterMemberInterface::getNewsletterSegments()` is unchanged (already returns
`iterable<NewsletterSegmentInterface>`).

---

## 4. Remote-ID store (Note-backed + cached)

A single service owns all provider-ID persistence and reading, so no provider terminology
leaks into the app and reads are cheap.

```php
final class RemoteIdStore
{
    public function getRemoteId(ElementInterface $object, string $connector, string $list): ?string;
    public function setRemoteId(ElementInterface $object, string $connector, string $list, string $remoteId): void;
    public function removeRemoteId(ElementInterface $object, string $connector, string $list): void;

    /** @return array<string,string>  list => remoteId, for every stored list of this object/connector */
    public function allRemoteIds(ElementInterface $object, string $connector): array;
}
```

- **Storage:** one OpenDXP `Note` per `(object, connector, list)`. `type = 'opendxp_campaigns.export'`, `title = "$connector:$list"`, data field `remote_id` (+ `synced_at`, `name` for debugging). Notes are queried via `Note\Listing` filtered by `cid`/`ctype` + type, then matched on title.
- **Caching (required):** a dedicated cache pool `cache.opendxp_campaigns` (declared in `services.yaml` under `framework.cache.pools`, or reuse `cache.app`) accessed via `Symfony\Contracts\Cache\CacheInterface`. Cache key = `remoteid_{ctype}_{cid}_{connector}_{list}`. Cache is populated on read and **invalidated on `set`/`remove`** (`cache->delete(...)`). This removes the per-export DB round-trip when re-exporting members and segments.
- **Lookups are also memoized in-request** to cover the case where the same object is touched multiple times in one process.

> **Why a store, not interface methods:** the app object never needs to know its Mailchimp
> ID. Keeping IDs in Notes means re-installing/re-importing the class definition never loses
> the mapping, and reverse lookup (webhook → object) is possible via `getObjectByRemoteId()`.

---

## 5. Driver layer

Segment export is provider-specific and optional (the `log` driver stubs it, a future SMS
provider might not support it), so it goes behind a **capability interface**, mirroring
`TemplateExportCapableInterface`.

```php
interface SegmentExportCapableInterface
{
    /** Create/update the interest category; returns the provider category ID. */
    public function exportSegmentGroup(string $listId, string $name, ?string $remoteId): string;
    public function deleteSegmentGroup(string $listId, string $remoteId): void;

    /** Create/update the interest inside a category; returns the provider interest ID. */
    public function exportSegment(string $listId, string $groupRemoteId, string $name, ?string $remoteId): string;
    public function deleteSegment(string $listId, string $groupRemoteId, string $remoteId): void;
}
```

**Mailchimp implementation** (`MailchimpDriver implements … SegmentExportCapableInterface`):

| Method | Endpoint | Notes |
|--------|----------|-------|
| `exportSegmentGroup` (create) | `POST lists/{list}/interest-categories` | body `{ title, type: 'checkboxes' }`; returns `id` |
| `exportSegmentGroup` (update) | `PATCH …/interest-categories/{remoteId}` | when `remoteId` known |
| `deleteSegmentGroup` | `DELETE …/interest-categories/{remoteId}` | deleting a category deletes its interests |
| `exportSegment` (create) | `POST …/interest-categories/{cat}/interests` | body `{ name }`; returns `id` |
| `exportSegment` (update) | `PATCH …/interests/{remoteId}` | |
| `deleteSegment` | `DELETE …/interests/{remoteId}` | |

- `LogDriver` implements the interface as no-op logging (returns a fake deterministic id such as `"log_{listId}"`), so local dev works end-to-end.
- `md5`/`subscriberHash` import bug fixed at the same time.

Member interest assignment reuses the **existing** `subscribeOrUpdate(..., interestIds, ...)`
path — no driver change needed there.

---

## 6. Synchronization flow

### 6.1 Outbound: segment / group saved

Listener `SegmentSyncListener` on `opendxp.dataobject.postAdd` + `opendxp.dataobject.postUpdate`
(skips `saveVersionOnly`, respects the `OutboundSyncSuppressor`):

- object is `NewsletterSegmentGroupInterface` → dispatch `SyncSegmentGroupMessage(objectId)`
- object is `NewsletterSegmentInterface` → dispatch `SyncSegmentMessage(objectId)`

### 6.2 Outbound: segment / group deleted

Deletion is special: **the object and its Notes are gone by the time an async worker runs**, so
we capture the remote IDs eagerly.

- `preDelete` listener reads `RemoteIdStore::allRemoteIds()` for the object and stashes them
  (keyed by object id in a request-scoped array).
- `postDelete` listener dispatches `DeleteSegmentGroupMessage(list => remoteId, …)` /
  `DeleteSegmentMessage(list => [groupRemoteId, remoteId], …)` from the stash.

### 6.3 Handlers (Symfony Messenger)

| Message | Handler behavior |
|---------|------------------|
| `SyncSegmentGroupMessage` | resolve group; for each `getNewsletterListNames()` list: under a **Lock** (`campaigns_grp_{id}_{list}`) read remote id, create-or-update via driver, persist via `RemoteIdStore`. Then reconcile: delete remote ids for lists **no longer** targeted (`allRemoteIds` − current targets). |
| `SyncSegmentMessage` | resolve segment + its group (tree-walk). For each of the **group's** target lists: ensure the group is exported first (idempotent, under group lock) → get category remote id → create-or-update interest under **segment lock** → persist. |
| `DeleteSegmentGroupMessage` | for each captured `(list, remoteId)`: `driver->deleteSegmentGroup(...)` (best-effort, tolerate 404). |
| `DeleteSegmentMessage` | for each captured `(list, groupRemoteId, remoteId)`: `driver->deleteSegment(...)`. |

- **Ordering / race safety:** because a segment handler may run before its group handler, the
  segment handler **ensures the group exists first** (idempotent). `symfony/lock`
  (`LockFactory`, default local store) serializes concurrent create attempts so two workers
  cannot create duplicate categories. This is exactly the failure the Note+Lock combination
  prevents.
- **Idempotency:** create-vs-update keys off the Note remote id, so replays PATCH instead of
  re-POSTing. Safe to retry via Messenger's standard retry/backoff.
- **Cascade:** renaming a group renames its Mailchimp category; deleting a group deletes the
  category (Mailchimp cascades interests). Renaming a segment PATCHes its interest.

### 6.4 Messages configured as async

New messages are added to the same transport routing the app already uses for
`SyncMemberToListMessage` (documented in README; the bundle stays transport-agnostic).

---

## 7. Member export integration

`NewsletterManager::extractInterestIds()` becomes **list-aware** and resolves real interest IDs
from the store instead of the removed slug:

```php
private function interestIdsForList(NewsletterMemberInterface $m, ListConfig $list): array
{
    $ids = [];
    foreach ($m->getNewsletterSegments() as $segment) {
        $group = $segment->getNewsletterSegmentGroup();
        if (!in_array($list->identifier, $group->getNewsletterListNames(), true)) {
            continue; // this segment's group does not target this list
        }
        $remoteId = $this->remoteIds->getRemoteId($segment, $list->connectorName, $list->identifier);
        if ($remoteId !== null) {
            $ids[] = $remoteId;
        }
    }
    return $ids;
}
```

Call sites: `subscribe`, `subscribeOrUpdate`, `syncMemberToList`. The `interests` payload then
carries genuine Mailchimp interest IDs so `*|INTERESTED|*` blocks resolve. (If a segment has no
remote id yet — not exported — it is simply skipped; the daily backup command backfills it.)

---

## 8. Automatic group assignment + placement validation

### `NewsletterSegmentTrait`
```php
public function getNewsletterSegmentGroup(): NewsletterSegmentGroupInterface
{
    $node = $this->getParent();
    while ($node !== null) {
        if ($node instanceof NewsletterSegmentGroupInterface) {
            return $node;
        }
        $node = $node->getParent();  // walks through folders too
    }
    throw new SegmentPlacementException(sprintf(
        'Segment "%s" must live under a NewsletterSegmentGroup in the object tree.',
        $this->getFullPath(),
    ));
}
```

### `SegmentPlacementValidationListener` (preAdd/preUpdate)
Rejects saving/moving a segment that has no ancestor group — enforces "a segment cannot be
placed outside a segment group." Throws `OpenDxp\Model\Element\ValidationException` so the admin
UI shows a clean message. Merge into / sit beside `SegmentNameValidationListener`.

---

## 9. `SegmentNameValidationListener` — simplification

Keep the responsibility, simplify the mechanics:

- Replace the `u($name)->containsAny([...])` scan with a single `strpbrk($name, '*|:,')`
  check — no String component needed, one call, clearer intent.
- Throw `OpenDxp\Model\Element\ValidationException` (admin-friendly) instead of bare
  `\RuntimeException`.
- Keep empty-name and **uniqueness-within-group** checks (names drive the merge tag, so
  duplicates within a category would break `*|INTERESTED|*`).
- **Register it** (auto-registered, see §10) — it currently never runs.
- The name char-set constant is shared with the docs so validation and export agree.

```php
private const string INVALID_CHARS = '*|:,';

private function validateName(string $name): void
{
    if (trim($name) === '') {
        throw new ValidationException('Segment/Group name is required for export.');
    }
    if (strpbrk($name, self::INVALID_CHARS) !== false) {
        throw new ValidationException(sprintf(
            'Segment/Group name may not contain any of: %s',
            implode(' ', str_split(self::INVALID_CHARS)),
        ));
    }
}
```

---

## 10. Developer-experience deliverables (D4 — all four)

### 10.1 Traits (`src/DataObject/`)
- `NewsletterSegmentTrait` — name normalization + tree-walk group resolution.
- `NewsletterSegmentGroupTrait` — name normalization + `getNewsletterListNames()` reading the
  multiselect field + `getNewsletterSegments()` default (children of type segment).

### 10.2 Abstract base classes (`src/Model/DataObject/`)
- `AbstractNewsletterSegment extends Concrete implements NewsletterSegmentInterface`
  (`use NewsletterSegmentTrait`).
- `AbstractNewsletterSegmentGroup extends Concrete implements NewsletterSegmentGroupInterface`
  (`use NewsletterSegmentGroupTrait`).

An adopting project's generated class simply extends these — no interface/trait wiring by hand.
The trial app's `BaseCustomerSegment` / `BaseCustomerSegmentGroup` are refactored to extend them
(and the `// TODO` stub disappears).

### 10.3 Auto-registered listeners
`listeners.yaml` registers the name/placement validation listener always, and the segment-sync
+ delete listeners gated by a new opt-in flag (see §11) — apps never edit `listeners.yaml`.

### 10.4 Installer class definitions
Add JSON class definitions under `config/install/classes/`:
- `NewsletterSegmentGroup.json` — fields: `name` (input), `lists` (**multiselect**, options
  provider = `NewsletterListOptionsProvider`), parent class = `AbstractNewsletterSegmentGroup`.
- `NewsletterSegment.json` — field: `name` (input), parent class = `AbstractNewsletterSegment`.
- `NewsletterMember.json` — fields: `email` (input), `firstname`/`lastname`/`phone` (input),
  `newsletterSegments` (**manyToManyObjectRelation** to the segment class, injected at install
  time), `newsletterSubscriptions` (**fieldcollections** allowing `CampaignNewsletterSubscription`),
  parent class = `AbstractNewsletterMember`.

`Installer::install()`/`uninstall()` import/remove them via `ClassDefinitionService`
(same pattern as the existing fieldcollection). Adopters get working, correctly-configured
objects out of the box; they may still hand-roll their own classes by implementing the
interfaces if preferred.

**Self-detecting installation (brownfield support).** There is no config flag. The Installer
resolves the target class name for each of `member_class`, `segments.segment_class` and
`segments.segment_group_class` (the short name of the configured FQCN, falling back to
`NewsletterMember` / `NewsletterSegment` / `NewsletterSegmentGroup` when unset) and, **per class,
imports the template only when no DataObject class of that name already exists** — an existing
project that supplies its own classes is detected and left untouched, a fresh project gets the
ready-made ones. The class is created under the configured name, so the member template's
segment relation is pointed at the resolved segment class name via a `%%SEGMENT_CLASS%%`
placeholder. The names actually created are recorded in the `SettingsStore`
(`opendxp_campaigns.installed_classes`, scope `opendxp_campaigns`) so `uninstall()` removes
**only** those and never deletes a class the bundle did not create. The member-subscription
fieldcollection remains unconditional. The Extension passes the three configured FQCNs to the
`Installer` as container parameters via `services.yaml`.

---

## 11. Configuration additions

```yaml
opendxp_campaigns:
  # existing …
  sync_on_save: true            # members (existing)

  # No install flag: the Installer creates the Member/Segment/SegmentGroup class definitions
  # only when a class of the configured name does not already exist, and uninstalls only
  # those it created.

  segments:
    segment_class:        App\Model\DataObject\CustomerSegment       # detection + backup command + resolution
    segment_group_class:  App\Model\DataObject\CustomerSegmentGroup
    sync_on_save:  true          # auto-dispatch group/segment sync on save & delete (default false)
```

The Extension registers `SegmentSyncListener` + delete listeners only when
`segments.sync_on_save` is true (mirroring how `MemberDataObjectSyncListener` is gated today).

---

## 12. Backup sync command

Extend the existing push command with a segment pass (or add `campaigns:segments:sync`):
re-export every group + segment for its target lists (idempotent create-or-update) and backfill
any missing remote IDs — the recovery path for missed Messenger events, mirroring the member
backup command. Options: `--list=`, `--dry-run`.

---

## 13. File-level change map

**New**
- `src/Contract/SegmentExportCapableInterface.php`
- `src/Newsletter/RemoteIdStore.php`
- `src/DataObject/NewsletterSegmentTrait.php`
- `src/DataObject/NewsletterSegmentGroupTrait.php`
- `src/Model/DataObject/AbstractNewsletterSegment.php`
- `src/Model/DataObject/AbstractNewsletterSegmentGroup.php`
- `src/EventListener/SegmentSyncListener.php` (+ delete handling)
- `src/EventListener/SegmentPlacementValidationListener.php` (or fold into name listener)
- `src/Messenger/Message/{SyncSegmentGroupMessage,SyncSegmentMessage,DeleteSegmentGroupMessage,DeleteSegmentMessage}.php`
- `src/Messenger/Handler/{SyncSegmentGroupHandler,SyncSegmentHandler,DeleteSegmentGroupHandler,DeleteSegmentHandler}.php`
- `src/Exception/SegmentPlacementException.php`
- `config/install/classes/{NewsletterSegmentGroup,NewsletterSegment}.json`

**Modified**
- `src/Contract/NewsletterSegmentInterface.php` — drop `getNewsletterSegmentIdentifier()`
- `src/Contract/NewsletterSegmentGroupInterface.php` — drop identifier, add `getNewsletterListNames()`
- `src/Driver/Mailchimp/MailchimpDriver.php` — fix import bug; implement `SegmentExportCapableInterface`
- `src/Driver/Log/LogDriver.php` — implement `SegmentExportCapableInterface`
- `src/Newsletter/NewsletterManager.php` — list-aware interest resolution via `RemoteIdStore`
- `src/DependencyInjection/{Configuration,OpenDxpCampaignsExtension}.php` — `segments` config + listener gating + `RemoteIdStore` + cache pool wiring
- `config/services/{listeners,messenger}.yaml` + register `SegmentNameValidationListener`
- `src/Installer.php` — install/uninstall the two class definitions
- App: `src/Model/DataObject/{BaseCustomer,BaseCustomerSegment,BaseCustomerSegmentGroup}.php` — extend new base classes, remove TODO stub, drop identifier usage

**Dependencies**
- No new `composer.json` requires: `symfony/lock`, `symfony/cache`, `symfony/string` and
  `symfony/messenger` all ship transitively with `open-dxp/opendxp` (a direct requirement),
  so the bundle relies on them being present rather than re-declaring them.

---

## 14. Testing

- `RemoteIdStoreTest` — set/get/remove, cache hit avoids Note listing, invalidation on write.
- `MailchimpDriverTest` — category/interest create-vs-update payloads + delete (mocked client).
- `SegmentSyncHandlerTest` — group exported before segment; reconcile removes de-targeted lists.
- `NewsletterSegmentTraitTest` — tree-walk finds group through folders; throws when absent.
- `SegmentNameValidationListenerTest` — rejects `* | : ,`, empty, and dup names within a group.
- `NewsletterManagerTest` — member export resolves only remote IDs for lists the group targets.

---

## 15. Open follow-ups (not v1-blocking)

- Reverse lookup `getObjectByRemoteId()` for webhook-driven interest changes (Mailchimp doesn't
  push interest-definition webhooks, so low priority).
- Diff-based cascade when a group is re-parented across audiences.
- Bulk export batching for very large segment counts.

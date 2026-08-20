# Migrating from Pimcore's Customer Management Framework

Three things have to happen before the first synchronisation. Each is a one-way street: skip one and
the provider ends up with duplicates, a mass unsubscribe, or an audience that has forgotten who
belongs to which group.

## 1. Pull before you push

Members need no migration on the provider side. Mailchimp identifies a contact by a hash of the
address and the export upserts on that hash, so existing subscribers are matched, not duplicated.

Their status does have to arrive in the `newsletterSubscriptions` fieldcollection — the bundle reads
nothing else. A member without a status counts as unsubscribed, the same fallback the framework
applies, so a first full push would report the entire audience to the provider as unsubscribes.

```bash
bin/console campaigns:newsletter:pull --list=<list>
```

`campaigns:newsletter:push` refuses a list nothing was ever pulled into. `--force` overrides it,
`--dry-run` is exempt. The check asks whether any member carries a sync date for that list, so a
pull that reached only part of the audience still satisfies it: it catches the wrong order, not an
incomplete run.

## 2. Import the provider IDs

Segment groups, segments and templates are different from members. The framework kept their provider
IDs in notes of its own, which this bundle does not read. A first sync would therefore create a
second interest category, a second interest and a second template beside the ones the audience
already has — and every subscriber's interest assignment would keep pointing at the originals.

```bash
bin/console campaigns:migrate:cmf-remote-ids --dry-run
bin/console campaigns:migrate:cmf-remote-ids
```

The command reads `export.mailchimp` notes, skips members, and maps the recorded audience ID back to
the configured list through `provider_list_id`. Two of its counts matter:

| Count | Meaning |
|---|---|
| **unmapped** | the audience is not configured under `lists`. Add it, or run against the connector that owns it. |
| **unloadable** | the element still holds a provider ID but its class no longer loads. Usually the segment classes: install them, then run again. |

A note is only looked up once its audience maps to a configured list, so on an installation that does
not have the audience configured everything lands under **unmapped** and neither of the other counts
says anything.

Run this before the first sync, and before enabling `sync_on_save`.

## 3. Bring the group membership back

The framework pushed segments and never read them back, so who belongs to which group lives at the
provider and, after a class rebuild, nowhere else. Left alone, the first full push sends every
managed interest as `false` for every member OpenDXP has no segments for, and the targeting a
newsletter relies on is gone.

```bash
bin/console campaigns:migrate:interests --list=<list> --dry-run
bin/console campaigns:migrate:interests --list=<list>
```

It matches an interest to a segment by group and segment name, adopts the provider's ID for both so
a later export updates what the audience already has, and assigns the segments to the members it
finds. Interests without a matching segment are reported, not created: where a segment belongs in
the object tree is your decision. Create the missing ones and run it again.

Run it before the first push, and check afterwards that a push reports no change at the provider.

## What the bundle does not carry over

The framework's segment builders — the ones that calculate segments from age, gender or state — have
no counterpart here. Segments are DataObjects; whatever fills them is the application's business.

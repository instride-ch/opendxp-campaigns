![OpenDXP Campaigns](docs/images/github_banner.png "OpenDXP Campaigns")

[![Software License](https://img.shields.io/badge/license-GPLv3-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Latest Stable Version](https://img.shields.io/packagist/v/instride/opendxp-campaigns.svg?style=flat-square)](https://packagist.org/packages/instride/opendxp-campaigns)

Newsletter subscriptions and segments live in OpenDXP and are kept in step with a mail provider:
what a member is subscribed to, the merge fields behind it, and which segments they belong to.
Everything a provider does sits behind a driver — Mailchimp is the one that ships, a second one
needs no change above the driver layer. Changes travel both ways: out over Messenger when an object
is saved, back in through the provider's webhook or a nightly pull.

### Requirements

PHP 8.3, 8.4 or 8.5, and OpenDXP 1.3 with its admin bundle. The Mailchimp driver additionally needs
`drewm/mailchimp-api`, which is suggested rather than required so a project on another provider does
not carry it.

### Installation

```
composer require instride/opendxp-campaigns drewm/mailchimp-api
```

Register the bundle in `config/bundles.php`:

```php
Instride\Bundle\OpenDxpCampaignsBundle\OpenDxpCampaignsBundle::class => ['all' => true],
```

Then let it install what it needs — the subscription fieldcollection and, where they are missing,
the class definitions described below:

```
bin/console opendxp:bundle:install OpenDxpCampaignsBundle
```

`bin/console opendxp:bundle:list` shows whether it is enabled and installed.

### Class definitions

The installer creates the `NewsletterMember`, `NewsletterSegment` and `NewsletterSegmentGroup`
class definitions **only when no class of that name exists yet**. It compares names, not contents,
so an existing class of the right name is left alone even when it does not satisfy the contracts —
and the bundle then reports itself installed while nothing works.

| Your project | What to do |
|---|---|
| No such classes | Nothing. The installer creates them. |
| Own classes, contracts satisfied | Point `member_class` and `segments.*_class` at them. |
| Own classes, contracts not satisfied | Implement the interface and use the matching trait, or rename them out of the way. |

Satisfying a contract is usually three lines:

```php
class Customer extends Concrete implements NewsletterMemberInterface
{
    use NewsletterSubscriptionTrait;   // reads and writes the subscription fieldcollection
}
```

`NewsletterSubscriptionTrait` expects a fieldcollection field `newsletterSubscriptions` allowing
`CampaignNewsletterSubscription`; `NewsletterSegmentTrait` and `NewsletterSegmentGroupTrait` cover
the segment side, the latter expecting a multiselect field `lists` fed by
`NewsletterListOptionsProvider`. The `CampaignNewsletterSubscription` fieldcollection is overwritten
on every install, so local changes to it are lost.

### Configuration

```yaml
opendxp_campaigns:
    # Either wrap a DataObject class implementing NewsletterMemberInterface …
    member_class: 'App\Model\DataObject\Customer'
    # … or point the bundle at your own services (these win over member_class).
    member_resolver: 'app.newsletter.member_resolver'
    member_provider: 'app.newsletter.member_provider'
    email_field: email

    sync_on_save: true                  # push a member when it is saved
    segments:
        sync_on_save: true              # same for groups and segments
        segment_class: 'App\Model\DataObject\CustomerSegment'
        segment_group_class: 'App\Model\DataObject\CustomerSegmentGroup'

    connectors:
        mailchimp:
            driver: mailchimp           # or "log", which only writes to the log
            config:
                api_key: '%env(MAILCHIMP_API_KEY)%'
                webhook_secret: '%env(MAILCHIMP_WEBHOOK_SECRET)%'

    default_list_name: newsletter
    lists:
        newsletter:
            connector: mailchimp
            provider_list_id: '%env(MAILCHIMP_AUDIENCE_ID)%'
            label: 'Newsletter'
            merge_fields:               # your field => the provider's tag
                firstname: FNAME
                lastname: LNAME
```

Incoming events reach `/webhooks/campaigns/mailchimp/<connector>`, which has to be registered at
the provider and needs `webhook_secret` set. Without it nothing comes back except through a pull.

### From your application

The bundle exposes one service, `NewsletterManagerInterface`. Inject it and speak in members and
lists; which provider is behind them, and what it calls things, is none of the caller's business.

```php
public function __construct(private readonly NewsletterManagerInterface $newsletter) {}

public function signUp(Customer $customer): void
{
    $this->newsletter->subscribe($customer, 'newsletter');
}

public function optOut(Customer $customer): void
{
    $this->newsletter->unsubscribe($customer, 'newsletter');
}
```

Both write what the provider now holds back onto the member, so a later push knows it has nothing
to do. Passing a plain address instead of an object works too — then nothing is recorded, because
there is nothing to record it on. `isSubscribed()`, `hasMember()` and `getMember()` ask the provider
directly, and `syncMemberToList()` is what the push and the queue use for one member.

When a status arrives from the provider — through the webhook or a pull —
`MemberSubscriptionStatusChangedEvent` is dispatched with the member, the list, the previous and the
new status, and where it came from:

```php
public function onStatusChanged(MemberSubscriptionStatusChangedEvent $event): void
{
    if ($event->getNewStatus() === SubscriptionStatus::UNSUBSCRIBED) {
        // …
    }
}
```

### Commands

| Command | |
|---|---|
| `campaigns:newsletter:pull --list=<list>` | provider → OpenDXP |
| `campaigns:newsletter:push --list=<list>` | OpenDXP → provider; `--member=<id\|email>` for one, `--dry-run`, `--async` |
| `campaigns:newsletter:push --list=<list> --segments` | brings groups and segments in line and removes what only the provider holds |
| `campaigns:migrate:cmf-remote-ids` | see [migrating from the CMF](docs/migrating-from-cmf.md) |
| `campaigns:migrate:interests` | adopts the provider's IDs for existing groups and the membership behind them |

Segments and members reach the provider on save, over Messenger. The `--segments` pass is the
recovery path for messages that never arrived; run it from cron. After a group was lost at the
provider, run it **before** the member push — recreating a group yields new provider IDs, and only
the member push writes the memberships again.

### Before your first synchronisation

Coming from Pimcore's Customer Management Framework, three steps come first: pull the statuses,
import the provider IDs, and bring the group membership back. Skipping one costs you a mass
unsubscribe, a duplicate of every group, segment and template, or an audience that no longer knows
who belongs to which group. → **[docs/migrating-from-cmf.md](docs/migrating-from-cmf.md)**

### Message queue

Every message the bundle dispatches is routed to its own `opendxp_campaigns` transport, so a
slow provider call cannot hold up OpenDXP's own queues. **Run a consumer for it**, otherwise
member and segment changes queue up and never reach the provider:

```
bin/console messenger:consume opendxp_campaigns
```

The transport is a plain Doctrine queue. To use RabbitMQ (or anything else) instead, point the
same name at another DSN in the application — no change to the bundle:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            opendxp_campaigns: '%env(MESSENGER_TRANSPORT_DSN)%'
```

Routing resolves through `CampaignsMessageInterface`, which all messages implement, so a single
entry redirects all of them at once:

```yaml
framework:
    messenger:
        routing:
            'Instride\Bundle\OpenDxpCampaignsBundle\Messenger\CampaignsMessageInterface': my_transport
```

### License
**instride AG**, Sandgruebestrasse 4, 6210 Sursee, Switzerland  
connect@instride.ch, [instride.ch](https://instride.ch)  
Copyright © 2026 instride AG. All rights reserved.

For licensing details please visit [LICENSE.md](LICENSE.md)

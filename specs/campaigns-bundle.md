You are working in an OpenDXP/Symfony/PHP bundle context.

## Goal

Build a OpenDXP bundle called `OpenDxpCampaignsBundle`. The basic structure of the bundle already exists. You need to implement the functionality as described in this specification.

The bundle should provide campaign-management functionality, with the first release focused on newsletter and email-marketing synchronization.

For v1, the main goal is to cover most of the Newsletter Sync functionality from Pimcore Customer Data Management Framework:

https://docs.pimcore.com/platform/Customer_Management_Framework/NewsletterSync/

The primary provider for v1 is Mailchimp. However, the architecture must be flexible enough to support additional newsletter providers later, for example:

- Mailcoach
- MailerLite
- other newsletter/email-marketing services

## Technical Context

The bundle should be implemented as a Symfony/OpenDXP bundle.

Configuration must be exposed through Symfony configuration, preferably YAML.

The bundle should support multiple newsletter connectors. A connector represents one configured provider account, for example one Mailchimp account.

Lists/audiences should be configured and linked to a connector in the same Symfony configuration file.

Example conceptual structure:

```yaml
open_dxp_campaigns:
  connectors:
    main_mailchimp:
      driver: mailchimp
      config:
        api_key: '%env(MAILCHIMP_API_KEY)%'
        server_prefix: 'us21'

    test_logger:
      driver: log

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

Please design the final configuration structure properly. The example above is only illustrative.

## Provider Driver Architecture

Use a driver-based architecture.

The driver is the abstraction layer for newsletter providers.

For v1, implement:

1. `mailchimp` driver
2. `log` driver

The `log` driver should not call any external API. It should only log the API operations that would have been executed. This is required for debugging and local development.

The API design should be inspired by the Laravel Newsletter package by Spatie:

https://github.com/spatie/laravel-newsletter

I like the convenience of methods such as:

```php
Newsletter::subscribe();
Newsletter::unsubscribe();
Newsletter::subscribeOrUpdate();
Newsletter::delete();
Newsletter::getMember();
Newsletter::hasMember();
Newsletter::isSubscribed();
Newsletter::getApi();
```

Do not copy the Laravel implementation directly, but use the general API ergonomics as inspiration.

In this Symfony/OpenDXP bundle, prefer service-based usage over static facades where appropriate.

## Member Integration

Instead of passing only plain email addresses, the bundle should allow passing a full OpenDXP Member object.

The actual Member object is application-specific and not part of this bundle.

Therefore, define a PHP interface that application-specific Member objects can implement.

Example concept:

```php
interface NewsletterMemberInterface
{
    public function getNewsletterEmail(): string;

    public function getNewsletterMergeFields(): array;

    public function getNewsletterSegments(): iterable;

    public function getNewsletterSubscriptionStatus(string $listIdentifier): ?string;

    public function setNewsletterSubscriptionStatus(string $listIdentifier, string $status): void;
}
```

Please improve this interface design if needed.

Important requirements:

- A member may be subscribed to multiple configured lists/audiences.
- Subscription status must be tracked dynamically on the Member object.
- Status updates must support provider-side status changes coming from webhooks.
- The interface should avoid forcing a specific persistence model onto the application.
- The bundle should provide the contracts and synchronization logic, but not own the concrete Member entity.

## Segment Integration

Segments are application-specific objects and are not managed by this bundle.

There are two conceptual object types:

1. SegmentGroup
2. Segment

OpenDXP users or the application itself manage these objects.

The bundle should only provide PHP interfaces that application-specific objects can implement:

```php
interface NewsletterSegmentGroupInterface
{
    // define required methods
}

interface NewsletterSegmentInterface
{
    // define required methods
}
```

Segments are linked to members. The member’s assigned segments should be synchronized to the newsletter provider.

For Mailchimp, I am unsure whether the implementation should use Mailchimp Segments, Tags, or Interest Categories / Interests.

Important Mailchimp requirement:

The implementation must work with the Mailchimp merge tag:

```text
*|INTERESTED:<group>:<segments>|*
```

Please research or reason through the correct Mailchimp model and recommend the best mapping.

The preferred outcome is to use Mailchimp’s model in a way that supports grouped segment logic and is compatible with this merge tag.

Please explicitly explain whether Mailchimp Segments, Tags, or Interest Categories / Interests are the correct choice here, and why.

## Synchronization Requirements

Customer/member data should be synchronized asynchronously using Symfony Messenger.

Required behavior:

- Member changes should dispatch messages to synchronize the member to the configured newsletter lists.
- Segment assignment changes should also trigger synchronization.
- Subscription status changes in OpenDXP should synchronize to the provider.
- Provider-side changes should synchronize back into OpenDXP using webhooks.
- Mailchimp webhooks should be supported in v1.
- Synchronization should be idempotent where possible.
- Failed synchronization jobs should be retryable through Symfony Messenger.
- The implementation should avoid duplicate API calls where practical.

HTML mail templates should be exportable to Mailchimp directly through API calls.

This does not need to run through Messenger initially unless there is a strong architectural reason to do so.

## Backup Synchronization Command

Add an OpenDXP/Symfony console command that can be used in a cronjob.

Purpose:

- Run synchronization once per day as a backup.
- Recover from failed webhook/API events.
- Ensure provider data and OpenDXP data remain consistent.

Please design the command API.

Example concepts:

```bash
bin/console open-dxp-campaigns:sync
bin/console open-dxp-campaigns:sync --connector=main_mailchimp
bin/console open-dxp-campaigns:sync --list=default_newsletter
bin/console open-dxp-campaigns:sync --member=123
```

Please improve the final command naming and options.

## Mail Template Export

The bundle should support exporting HTML mail templates to Mailchimp.

Requirements:

- The HTML template source may come from OpenDXP documents/templates.
- The exported template should be created or updated in Mailchimp.
- The bundle should provide a service API for this.
- Provider-specific implementation should live in the driver.

Please design the contracts and service structure for this.

## Desired Deliverables

Please implement or scaffold the bundle with a clean architecture.

Before writing code, inspect the existing project structure and conventions.

Then produce:

1. Recommended bundle architecture
2. PHP interfaces/contracts
3. Symfony configuration tree
4. Dependency injection setup
5. Driver registry/factory
6. Mailchimp driver
7. Log driver
8. Newsletter manager/service API
9. Symfony Messenger messages and handlers
10. Webhook controller for Mailchimp
11. Console sync command
12. Template export service
13. Basic tests where useful
14. Documentation examples for configuration and usage

## Architectural Expectations

Use clear separation of concerns:

- Contracts/interfaces
- Provider drivers
- Synchronization orchestration
- Messenger messages/handlers
- Webhook handling
- Console commands
- Configuration/DI
- Template export

Prefer extensibility over hardcoding.

Avoid Mailchimp-specific concepts leaking into the generic public API unless absolutely necessary.

Provider-specific details should stay inside the driver layer.

Design the bundle so that future providers can be added by implementing a driver interface.

## Questions to Resolve

Please explicitly address these points before or during implementation:

1. What is the recommended Mailchimp mapping for SegmentGroup and Segment?
2. Should Mailchimp Interest Categories / Interests be used instead of Tags or Segments?
3. How should multiple list subscription statuses be stored on the Member object without forcing a persistence model?
4. What should the generic newsletter driver interface look like?
5. How should webhook events be mapped back to application-specific Member objects?
6. Does the bundle need a configurable MemberProvider/MemberResolver service to load members by email, ID, or provider member hash?
7. How should merge fields be normalized between generic Member objects and provider-specific APIs?
8. How should the template export identify whether to create or update a provider-side template?
9. How can synchronization stay idempotent and retry-safe?

## Implementation Style

Use modern PHP and Symfony best practices.

Use strict types.

Prefer constructor dependency injection.

Use Symfony attributes where appropriate, but keep compatibility with standard Symfony bundle conventions.

Write code that is easy to test.

Document important extension points.

Do not implement application-specific Member, SegmentGroup, or Segment entities inside this bundle. Only provide interfaces and resolver/provider contracts so the consuming OpenDXP application can integrate its own objects.

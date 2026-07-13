<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Controller\Webhook;

use Carbon\Carbon;
use Codeception\Test\Unit;
use DrewM\MailChimp\Webhook;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterDriverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Contract\NewsletterMemberInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Controller\Webhook\MailchimpWebhookController;
use Instride\Bundle\OpenDxpCampaignsBundle\DataObject\MemberResolverInterface;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\DriverRegistry;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\ListConfig;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\Mailchimp\MailchimpDriver;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\MergeFieldMapping;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Instride\Bundle\OpenDxpCampaignsBundle\Event\MemberSubscriptionStatusChangedEvent;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\IncomingMemberSync;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\OutboundSyncSuppressor;
use Instride\Bundle\OpenDxpCampaignsBundle\Newsletter\MergeFieldMapper;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The member objects the bundle operates on implement NewsletterMemberInterface *and*
 * pull in NewsletterSubscriptionTrait (which adds setNewsletterLastSyncDate) plus
 * application-specific setters like setFirstname that the profile handler resolves
 * dynamically. Neither lives on the interface, so we declare a local test double type
 * that exposes exactly the surface the controller touches.
 */
interface WebhookTestMember extends NewsletterMemberInterface
{
    public function setNewsletterLastSyncDate(string $listKey, Carbon $date): void;

    public function setFirstname(?string $firstname): void;
}

class MailchimpWebhookControllerTest extends Unit
{
    private const string CONNECTOR = 'main';
    private const string LIST = 'default_newsletter';
    private const string EMAIL = 'jane@example.com';

    private MailchimpDriver&MockObject $driver;
    private MemberResolverInterface&MockObject $memberResolver;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->driver = $this->createMock(MailchimpDriver::class);
        $this->driver->method('getWebhookSecret')->willReturn(null);

        $this->memberResolver = $this->createMock(MemberResolverInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    // -------------------------------------------------------------------------
    // Invocation / routing
    // -------------------------------------------------------------------------

    public function testGetRequestReturnsHandshakeResponse(): void
    {
        // The resolver must never run for the GET verification handshake.
        $this->memberResolver->expects($this->never())->method('resolveByEmail');

        $controller = $this->buildController();
        $response = $controller(Request::create($this->uri(), 'GET'), self::CONNECTOR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    public function testInvalidSecretReturnsUnauthorized(): void
    {
        $this->driver = $this->createMock(MailchimpDriver::class);
        $this->driver->method('getWebhookSecret')->willReturn('expected-secret');

        // Nothing downstream should run once the secret check fails.
        $this->memberResolver->expects($this->never())->method('resolveByEmail');

        $this->primeWebhook(['type' => 'subscribe', 'data' => ['email' => self::EMAIL]]);

        $controller = $this->buildController();
        $request = Request::create($this->uri(['secret' => 'wrong-secret']), 'POST');
        $response = $controller($request, self::CONNECTOR);

        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testUnknownMemberIsNotPersisted(): void
    {
        $this->memberResolver->method('resolveByEmail')->with(self::EMAIL)->willReturn(null);

        $this->primeWebhook(['type' => 'subscribe', 'data' => ['email' => self::EMAIL]]);

        $controller = $this->buildController();
        $response = $controller(Request::create($this->uri(), 'POST'), self::CONNECTOR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Status change
    // -------------------------------------------------------------------------

    public function testSubscribeUpdatesStatusDispatchesEventAndSaves(): void
    {
        $member = $this->createMember();
        $member->method('getNewsletterSubscriptionStatus')->with(self::LIST)
            ->willReturn(SubscriptionStatus::UNSUBSCRIBED);

        $member->expects($this->once())
            ->method('setNewsletterSubscriptionStatus')
            ->with(self::LIST, SubscriptionStatus::SUBSCRIBED);
        $member->expects($this->once())
            ->method('setNewsletterLastSyncDate')
            ->with(self::LIST, $this->isInstanceOf(Carbon::class));
        $member->expects($this->once())
            ->method('save')
            ->with(['versionNote' => '[OpenDXP Campaigns] Updated by Mailchimp webhook!']);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (MemberSubscriptionStatusChangedEvent $event): bool {
                return $event->getListName() === self::LIST
                    && $event->getPreviousStatus() === SubscriptionStatus::UNSUBSCRIBED
                    && $event->getNewStatus() === SubscriptionStatus::SUBSCRIBED
                    && $event->getSource() === 'webhook.mailchimp';
            }));

        $this->memberResolver->method('resolveByEmail')->with(self::EMAIL)->willReturn($member);

        $this->primeWebhook(['type' => 'subscribe', 'data' => ['email' => self::EMAIL]]);

        $controller = $this->buildController();
        $response = $controller(Request::create($this->uri(), 'POST'), self::CONNECTOR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testUnsubscribeUpdatesStatus(): void
    {
        $member = $this->createMember();
        $member->expects($this->once())
            ->method('setNewsletterSubscriptionStatus')
            ->with(self::LIST, SubscriptionStatus::UNSUBSCRIBED);
        $member->expects($this->once())->method('save');

        $this->memberResolver->method('resolveByEmail')->willReturn($member);

        $this->primeWebhook(['type' => 'unsubscribe', 'data' => ['email' => self::EMAIL]]);

        $controller = $this->buildController();
        $response = $controller(Request::create($this->uri(), 'POST'), self::CONNECTOR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCleanedUpdatesStatus(): void
    {
        $member = $this->createMember();
        $member->expects($this->once())
            ->method('setNewsletterSubscriptionStatus')
            ->with(self::LIST, SubscriptionStatus::CLEANED);
        $member->expects($this->once())->method('save');

        $this->memberResolver->method('resolveByEmail')->willReturn($member);

        $this->primeWebhook(['type' => 'cleaned', 'data' => ['email' => self::EMAIL]]);

        $controller = $this->buildController();
        $response = $controller(Request::create($this->uri(), 'POST'), self::CONNECTOR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testStatusChangeIgnoresListsOnOtherConnectors(): void
    {
        $member = $this->createMember();
        $member->expects($this->never())->method('setNewsletterSubscriptionStatus');
        $member->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->memberResolver->method('resolveByEmail')->willReturn($member);

        $this->primeWebhook(['type' => 'subscribe', 'data' => ['email' => self::EMAIL]]);

        // List belongs to connector "other", so a webhook for "main" must not touch it.
        $controller = $this->buildController(connectorName: 'other');
        $response = $controller(Request::create($this->uri(), 'POST'), self::CONNECTOR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Profile update
    // -------------------------------------------------------------------------

    public function testProfileUpdateMapsMergeFieldsAndSaves(): void
    {
        $member = $this->createMember();
        $member->expects($this->once())->method('setFirstname')->with('Jane');
        $member->expects($this->once())
            ->method('setNewsletterLastSyncDate')
            ->with(self::LIST, $this->isInstanceOf(Carbon::class));
        $member->expects($this->once())->method('save');

        // Profile updates only touch member fields; no status event is dispatched.
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->memberResolver->method('resolveByEmail')->willReturn($member);

        $this->primeWebhook([
            'type' => 'profile',
            'data' => ['email' => self::EMAIL, 'merges' => ['FNAME' => 'Jane']],
        ]);

        $controller = $this->buildController(mergeFieldMappings: [
            'firstname' => new MergeFieldMapping('firstname', 'FNAME'),
        ]);
        $response = $controller(Request::create($this->uri(), 'POST'), self::CONNECTOR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testProfileUpdateWithoutMergesDoesNotSave(): void
    {
        $member = $this->createMember();
        $member->expects($this->never())->method('setFirstname');
        $member->expects($this->never())->method('save');

        $this->memberResolver->method('resolveByEmail')->willReturn($member);

        $this->primeWebhook(['type' => 'profile', 'data' => ['email' => self::EMAIL]]);

        $controller = $this->buildController(mergeFieldMappings: [
            'firstname' => new MergeFieldMapping('firstname', 'FNAME'),
        ]);
        $response = $controller(Request::create($this->uri(), 'POST'), self::CONNECTOR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Unknown types
    // -------------------------------------------------------------------------

    public function testUnknownTypeIsIgnoredAndNotPersisted(): void
    {
        $member = $this->createMember();
        $member->expects($this->never())->method('setNewsletterSubscriptionStatus');
        $member->expects($this->never())->method('setFirstname');
        $member->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        // The unknown-type branch logs a debug line and bails out.
        $this->logger->expects($this->atLeastOnce())->method('debug');

        $this->memberResolver->method('resolveByEmail')->willReturn($member);

        $this->primeWebhook(['type' => 'campaign', 'data' => ['email' => self::EMAIL]]);

        $controller = $this->buildController();
        $response = $controller(Request::create($this->uri(), 'POST'), self::CONNECTOR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, MergeFieldMapping> $mergeFieldMappings
     */
    private function buildController(
        string $connectorName = self::CONNECTOR,
        array $mergeFieldMappings = [],
    ): MailchimpWebhookController {
        $listConfig = new ListConfig(
            identifier: self::LIST,
            connectorName: $connectorName,
            providerListId: 'abc123',
            label: 'Default Newsletter',
            mergeFieldMappings: $mergeFieldMappings,
        );

        $registry = new DriverRegistry(
            connectors: [self::CONNECTOR => $this->driver],
            listConfigs: [self::LIST => $listConfig],
        );

        $incomingSync = new IncomingMemberSync(
            $registry,
            new MergeFieldMapper(),
            $this->eventDispatcher,
        );

        return new MailchimpWebhookController(
            $registry,
            $this->memberResolver,
            $incomingSync,
            new OutboundSyncSuppressor(),
            $this->logger,
        );
    }

    private function createMember(): WebhookTestMember&MockObject
    {
        return $this->createMock(WebhookTestMember::class);
    }

    /**
     * Seeds the drewm/mailchimp-api Webhook static input cache so the controller's
     * argument-less Webhook::receive() call reads our payload instead of php://input.
     *
     * @param array<string, mixed> $payload
     */
    private function primeWebhook(array $payload): void
    {
        Webhook::receive(\http_build_query($payload));
    }

    /**
     * @param array<string, string> $query
     */
    private function uri(array $query = []): string
    {
        $uri = '/webhooks/campaigns/mailchimp/' . self::CONNECTOR;

        return $query === [] ? $uri : $uri . '?' . \http_build_query($query);
    }
}

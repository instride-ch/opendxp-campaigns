<?php

declare(strict_types=1);

namespace Instride\Bundle\OpenDxpCampaignsBundle\Tests\Unit\Driver\Mailchimp;

use Codeception\Test\Unit;
use DrewM\MailChimp\MailChimp;
use Instride\Bundle\OpenDxpCampaignsBundle\Driver\Mailchimp\MailchimpDriver;
use Instride\Bundle\OpenDxpCampaignsBundle\Enum\SubscriptionStatus;
use Instride\Bundle\OpenDxpCampaignsBundle\Exception\DriverException;
use Instride\Bundle\OpenDxpCampaignsBundle\Template\TemplateExport;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

/**
 * The payload this driver builds decides whether a resync may change a member's status at the
 * provider. Everything the status handling does rests on that one key being right.
 */
class MailchimpDriverTest extends Unit
{
    private const string LIST = 'abc123';
    private const string MAIL = 'jane@example.com';

    private MailChimp&MockObject $client;

    protected function setUp(): void
    {
        $this->client = $this->createMock(MailChimp::class);
        $this->client->method('success')->willReturn(true);
        // Everything the payload tests below name; the filtering itself has its own test.
        $this->audienceHolds($this->client, [
            'i1', 'i2', 'i3', 'int_1', '12345678', '6883863330', '197279e2a3',
        ]);
    }

    public function testOverwritingSendsStatus(): void
    {
        $this->client
            ->expects($this->once())
            ->method('put')
            ->with(
                $this->stringContains('lists/' . self::LIST . '/members/'),
                $this->callback(static function (array $payload): bool {
                    self::assertSame('subscribed', $payload['status'] ?? null);
                    self::assertArrayNotHasKey('status_if_new', $payload);

                    return true;
                }),
            )
            ->willReturn(['id' => 'x']);

        $this->driver()->subscribeOrUpdate(self::LIST, self::MAIL, [], [], SubscriptionStatus::SUBSCRIBED, true);
    }

    public function testNotOverwritingSendsStatusIfNewInstead(): void
    {
        $this->client
            ->expects($this->once())
            ->method('put')
            ->with(
                $this->anything(),
                $this->callback(static function (array $payload): bool {
                    // Mailchimp ignores status_if_new for a member it already knows, which is the
                    // point: a resync must not undo an unsubscribe made at the provider.
                    self::assertSame('subscribed', $payload['status_if_new'] ?? null);
                    self::assertArrayNotHasKey('status', $payload);

                    return true;
                }),
            )
            ->willReturn(['id' => 'x']);

        $this->driver()->subscribeOrUpdate(self::LIST, self::MAIL, [], [], SubscriptionStatus::SUBSCRIBED, false);
    }

    public function testAMemberWithoutSegmentsHasEveryInterestSwitchedOff(): void
    {
        $this->client
            ->expects($this->once())
            ->method('put')
            ->with(
                $this->anything(),
                $this->callback(static function (array $payload): bool {
                    self::assertSame(['i1' => false, 'i2' => false], $payload['interests']);

                    return true;
                }),
            )
            ->willReturn(['id' => 'x']);

        $this->driver()->subscribeOrUpdate(
            self::LIST,
            self::MAIL,
            [],
            [],
            SubscriptionStatus::SUBSCRIBED,
            true,
            ['i1', 'i2'],
        );
    }

    public function testAnAllDigitInterestIdKeepsItsIdentity(): void
    {
        $this->client
            ->expects($this->once())
            ->method('put')
            ->with(
                $this->anything(),
                $this->callback(static function (array $payload): bool {
                    // Digits only: PHP turns such a key into an integer, and the wrong array
                    // function then renumbers it from zero and sends a key nobody knows.
                    self::assertSame(
                        ['6883863330' => true, '197279e2a3' => false],
                        $payload['interests'],
                    );

                    return true;
                }),
            )
            ->willReturn(['id' => 'x']);

        $this->driver()->subscribeOrUpdate(
            self::LIST,
            self::MAIL,
            [],
            ['6883863330'],
            SubscriptionStatus::SUBSCRIBED,
            true,
            ['6883863330', '197279e2a3'],
        );
    }

    public function testMergeFieldsAndInterestsRideAlongEitherWay(): void
    {
        $this->client
            ->expects($this->once())
            ->method('put')
            ->with(
                $this->anything(),
                $this->callback(static function (array $payload): bool {
                    self::assertSame(['FNAME' => 'Jane'], $payload['merge_fields']);

                    // i3 has to travel as false: leaving it out lets the provider keep it.
                    self::assertSame(['i1' => true, 'i2' => true, 'i3' => false], $payload['interests']);

                    return true;
                }),
            )
            ->willReturn(['id' => 'x']);

        $this->driver()->subscribeOrUpdate(
            self::LIST,
            self::MAIL,
            ['FNAME' => 'Jane'],
            ['i1', 'i2'],
            SubscriptionStatus::SUBSCRIBED,
            false,
            ['i1', 'i2', 'i3'],
        );
    }

    public function testEmptyMergeFieldsAndInterestsAreLeftOut(): void
    {
        $this->client
            ->expects($this->once())
            ->method('put')
            ->with(
                $this->anything(),
                $this->callback(static function (array $payload): bool {
                    self::assertArrayNotHasKey('merge_fields', $payload);
                    self::assertArrayNotHasKey('interests', $payload);

                    return true;
                }),
            )
            ->willReturn(['id' => 'x']);

        $this->driver()->subscribeOrUpdate(self::LIST, self::MAIL);
    }

    public function testAFailedCallBecomesADriverException(): void
    {
        $client = $this->createMock(MailChimp::class);
        $client->method('success')->willReturn(false);
        $client->method('put')->willReturn(['detail' => 'Member In Compliance State']);

        $driver = new MailchimpDriver('mailchimp', 'key-us1', null, new NullLogger(), $client);

        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/Compliance/');

        $driver->subscribeOrUpdate(self::LIST, self::MAIL, [], [], SubscriptionStatus::SUBSCRIBED, true);
    }

    public function testUnsubscribeSetsThatStatusOnTheKnownMember(): void
    {
        $this->client
            ->expects($this->once())
            ->method('patch')
            ->with($this->stringContains('lists/' . self::LIST . '/members/'), ['status' => 'unsubscribed'])
            ->willReturn(['id' => 'x']);

        $this->driver()->unsubscribe(self::LIST, self::MAIL);
    }

    public function testAnInterestCategoryThatIsGoneIsCreatedAgain(): void
    {
        $client = $this->createMock(MailChimp::class);
        $client->method('success')->willReturn(true);
        $client->method('patch')->willReturn(['title' => 'gone']);
        // Mailchimp deletes interest categories for good, so a stored id can simply be absent.
        $client->method('getLastResponse')->willReturn(['headers' => ['http_code' => 404]]);
        $client->expects($this->once())->method('post')->willReturn(['id' => 'cat_new']);

        $driver = new MailchimpDriver('mailchimp', 'key-us1', null, new NullLogger(), $client);

        $this->assertSame('cat_new', $driver->exportSegmentGroup(self::LIST, 'Interests', 'cat_stale'));
    }

    public function testAnInterestThatIsGoneIsCreatedAgain(): void
    {
        $client = $this->createMock(MailChimp::class);
        $client->method('success')->willReturn(true);
        $client->method('patch')->willReturn(['name' => 'gone']);
        $client->method('getLastResponse')->willReturn(['headers' => ['http_code' => 404]]);
        $client->expects($this->once())->method('post')->willReturn(['id' => 'int_new']);

        $driver = new MailchimpDriver('mailchimp', 'key-us1', null, new NullLogger(), $client);

        $this->assertSame('int_new', $driver->exportSegment(self::LIST, 'cat_1', 'Sports', 'int_stale'));
    }

    public function testAnExistingInterestCategoryIsUpdatedInPlace(): void
    {
        $this->client->method('getLastResponse')->willReturn(['headers' => ['http_code' => 200]]);
        $this->client->expects($this->once())->method('patch')->willReturn(['id' => 'cat_1']);
        $this->client->expects($this->never())->method('post');

        $this->assertSame('cat_1', $this->driver()->exportSegmentGroup(self::LIST, 'Interests', 'cat_1'));
    }

    /**
     * Lets the audience answer which interests it has — the driver reads them before it writes a
     * member, because Mailchimp rejects the whole write over one unknown ID.
     *
     * @param string[] $interestIds
     */
    private function audienceHolds(MailChimp&MockObject $client, array $interestIds): void
    {
        $client->method('get')->willReturnCallback(
            static function (string $path) use ($interestIds): array {
                if (\str_ends_with($path, '/interests')) {
                    return ['interests' => \array_map(
                        static fn (string $id): array => ['id' => $id, 'name' => $id],
                        $interestIds,
                    )];
                }

                if (\str_contains($path, 'interest-categories')) {
                    return ['categories' => [['id' => 'cat_1', 'title' => 'Interests']]];
                }

                return [];
            },
        );
    }

    private function driver(): MailchimpDriver
    {
        return new MailchimpDriver('mailchimp', 'key-us1', null, new NullLogger(), $this->client);
    }

    public function testArchivingSomebodyTheProviderNoLongerHoldsIsNotAFailure(): void
    {
        $client = $this->createMock(MailChimp::class);
        $client->method('success')->willReturn(false);
        $client->method('delete')->willReturn(['status' => 404, 'detail' => 'Resource Not Found']);
        $client->method('getLastResponse')->willReturn(['headers' => ['http_code' => 404]]);

        $driver = new MailchimpDriver('mailchimp', 'key-us1', null, new NullLogger(), $client);

        $this->expectNotToPerformAssertions();

        $driver->archive(self::LIST, self::MAIL);
    }

    public function testATemplateThatWasDeletedIsCreatedAgain(): void
    {
        $client = $this->createMock(MailChimp::class);
        $client->method('success')->willReturn(true);
        // Mailchimp keeps deleted templates and only reports them as inactive.
        $client->method('get')->willReturn(['id' => 'tpl_stale', 'active' => false]);
        $client->expects($this->never())->method('patch');
        $client->expects($this->once())->method('post')->willReturn(['id' => 'tpl_new']);

        $driver = new MailchimpDriver('mailchimp', 'key-us1', null, new NullLogger(), $client);

        $this->assertSame(
            'tpl_new',
            $driver->exportTemplate(new TemplateExport('Newsletter', '<p>hi</p>', 'tpl_stale')),
        );
    }

    public function testAContactTheProviderRefusesToArchiveDoesNotStopTheMember(): void
    {
        $client = $this->createMock(MailChimp::class);
        $client->method('success')->willReturn(false);
        $client->method('delete')->willReturn([
            'status' => 405,
            'detail' => 'This list member cannot be removed. Can not archive a contact that is bounced, pending or archived',
        ]);
        $client->method('getLastResponse')->willReturn(['headers' => ['http_code' => 405]]);

        $driver = new MailchimpDriver('mailchimp', 'key-us1', null, new NullLogger(), $client);

        $this->expectNotToPerformAssertions();

        $driver->archive(self::LIST, self::MAIL);
    }

    public function testMergeTagsInLinksAreMaskedAndRestored(): void
    {
        $html = '<a href="*|UNSUB|*">stop</a><a href=\'*|ARCHIVE|*\'>web</a>';

        $masked = $this->driver()->protectPlaceholders($html);

        $this->assertStringContainsString('href="data:*|UNSUB|*"', $masked);
        $this->assertStringContainsString("href='data:*|ARCHIVE|*'", $masked);
        $this->assertSame($html, $this->driver()->restorePlaceholders($masked));
    }
    public function testMaskingIsNotLimitedToKnownTagNames(): void
    {
        $masked = $this->driver()->protectPlaceholders('<a href="*|ARCHIVE_PAGE|*">x</a>');

        $this->assertStringContainsString('href="data:*|ARCHIVE_PAGE|*"', $masked);
    }
    public function testMergeTagsOutsideAttributesAreUntouched(): void
    {
        $html = '<p>Hello *|FNAME|*</p>';

        $this->assertSame($html, $this->driver()->protectPlaceholders($html));
    }
    public function testRestoreDecodesEncodedPipes(): void
    {
        $this->assertSame(
            '<a href="https://example.com/?u=*|UNIQID|*">x</a>',
            $this->driver()->restorePlaceholders('<a href="https://example.com/?u=*%7CUNIQID%7C*">x</a>'),
        );
    }

    public function testAnInterestTheAudienceDoesNotHaveIsDroppedFromThePayload(): void
    {
        $client = $this->createMock(MailChimp::class);
        $client->method('success')->willReturn(true);
        $this->audienceHolds($client, ['int_live']);

        $client
            ->expects($this->once())
            ->method('put')
            ->with(
                $this->anything(),
                $this->callback(static function (array $payload): bool {
                    // The stale one would make Mailchimp reject the member outright.
                    self::assertSame(['int_live' => true], $payload['interests'] ?? null);

                    return true;
                }),
            )
            ->willReturn(['id' => 'x']);

        (new MailchimpDriver('mailchimp', 'key-us1', null, new NullLogger(), $client))
            ->subscribeOrUpdate(self::LIST, self::MAIL, [], ['int_live', 'int_gone'], SubscriptionStatus::SUBSCRIBED);
    }
}

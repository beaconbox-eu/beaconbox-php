<?php

declare(strict_types=1);

namespace BeaconBox\Tests;

use BeaconBox\Enum\Channel;
use BeaconBox\Enum\MessageKind;
use BeaconBox\Enum\OrderStatus;
use BeaconBox\Enum\RecipientStatus;
use BeaconBox\Enum\SkipReason;
use BeaconBox\Enum\SmsConsentSource;
use BeaconBox\Enum\WebhookEventType;
use BeaconBox\Exception\BeaconBoxException;
use BeaconBox\Exception\ConflictException;
use BeaconBox\Exception\ResourceMissingException;
use BeaconBox\Model\Message;
use BeaconBox\Model\MessagePush;
use BeaconBox\RetryPolicy;
use BeaconBox\Tests\Support\Fake;
use PHPUnit\Framework\TestCase;

/**
 * Every operation: the right verb, the right path, the right body.
 *
 * These are the tests that catch a drift between the SDK and the API without a server. The batch
 * one earned its place: this SDK previously sent the list under `messages` rather than `items`,
 * which tests that only asserted "a POST happened" were happy with and a real server answered 422.
 */
final class ResourcesTest extends TestCase
{
    // --- Push ----------------------------------------------------------------------------

    public function testPushPostsTheRequiredFields(): void
    {
        [$client, $http] = Fake::client([Fake::json(201, Fake::pushResult())]);

        $client->messages->push(new MessagePush(
            recipientEmail: 'buyer@example.com',
            subject: 'Your order has shipped',
            body: 'Tracking XY123456789EE.',
        ));

        self::assertSame('POST', $http->only()->getMethod());
        self::assertStringEndsWith('/api/v1/messages', (string) $http->only()->getUri());
        self::assertSame([
            'recipient_email' => 'buyer@example.com',
            'subject' => 'Your order has shipped',
            'body' => 'Tracking XY123456789EE.',
            'kind' => 'one_off',
        ], $http->body());
    }

    public function testPushAcceptsARawArrayToo(): void
    {
        // For a caller building a payload dynamically, and for anything this SDK's model does not
        // yet know about.
        [$client, $http] = Fake::client([Fake::json(201, Fake::pushResult())]);

        $client->messages->push(['recipient_email' => 'a@b.c', 'subject' => 's', 'body' => 'b']);

        self::assertSame('a@b.c', $http->body()['recipient_email']);
    }

    public function testPushOmitsUnsetOptionalFields(): void
    {
        // `notify: null` means "apply the default". `notify: false` actively suppresses a nudge.
        // Sending null for the first would ask for the second.
        [$client, $http] = Fake::client([Fake::json(201, Fake::pushResult())]);

        $client->messages->push(new MessagePush('a@b.c', 's', 'b'));

        self::assertArrayNotHasKey('notify', $http->body());
        self::assertArrayNotHasKey('send_at', $http->body());
        self::assertArrayNotHasKey('channels', $http->body());
    }

    public function testPushSendsNotifyFalseWhenItIsAskedFor(): void
    {
        [$client, $http] = Fake::client([Fake::json(201, Fake::pushResult())]);

        $client->messages->push(new MessagePush('a@b.c', 's', 'b', notify: false));

        self::assertFalse($http->body()['notify']);
    }

    public function testPushAcceptsEnumsAndPlainStrings(): void
    {
        [$client, $http] = Fake::client([Fake::json(201, Fake::pushResult())]);

        $client->messages->push(new MessagePush(
            'a@b.c',
            's',
            'b',
            kind: MessageKind::Updateable,
            channels: [Channel::Sms, 'whatsapp'],
        ));

        self::assertSame('updateable', $http->body()['kind']);
        self::assertSame(['sms', 'whatsapp'], $http->body()['channels']);
    }

    public function testPushSendsTheOrderStatusAsItsValue(): void
    {
        [$client, $http] = Fake::client([Fake::json(201, [...Fake::pushResult(), 'order_status' => 'shipped'])]);

        $result = $client->messages->push(new MessagePush(
            'a@b.c',
            's',
            'b',
            reference: '#A-10294',
            orderStatus: OrderStatus::Shipped,
        ));

        self::assertSame('shipped', $http->body()['order_status']);
        self::assertSame(OrderStatus::Shipped, $result->orderStatus);
    }

    public function testWhatsAppOptInBecomesTheObjectTheApiRequires(): void
    {
        // The API refuses a bare boolean on purpose: a copied `true` is not evidence of anything,
        // and the source has to be a surface the merchant authored.
        [$client, $http] = Fake::client([Fake::json(201, Fake::pushResult())]);

        $client->messages->push(new MessagePush('a@b.c', 's', 'b', whatsAppOptInSource: 'checkout tickbox'));

        self::assertSame(['source' => 'checkout tickbox'], $http->body()['whatsapp_opt_in']);
    }

    public function testASkippedChannelDoesNotThrow(): void
    {
        // The push succeeded. The update is in the inbox and the email went.
        [$client] = Fake::client([Fake::json(201, Fake::pushResult())]);

        $result = $client->messages->push(new MessagePush('a@b.c', 's', 'b'));

        self::assertNotNull($result->sms);
        self::assertFalse($result->sms->queued);
        self::assertSame(SkipReason::InsufficientCredit->value, $result->sms->skippedReason);
    }

    /** `channels: [Channel::Sms]` on an account with SMS off, or not yet enabled, is still a 201. */
    public function testNamingAChannelCannotSwitchItOn(): void
    {
        foreach ([SkipReason::SmsDisabled, SkipReason::SmsNotEnabled] as $reason) {
            [$client, $http] = Fake::client([Fake::json(201, [
                ...Fake::pushResult(),
                'sms' => ['queued' => false, 'credits' => 0, 'skipped_reason' => $reason->value],
            ])]);

            $result = $client->messages->push(new MessagePush('a@b.c', 's', 'b', channels: [Channel::Sms]));

            self::assertSame(['sms'], $http->body()['channels']);
            self::assertNotNull($result->sms);
            self::assertFalse($result->sms->queued);
            self::assertSame($reason, SkipReason::tryFrom((string) $result->sms->skippedReason));
        }
    }

    // --- Batch ---------------------------------------------------------------------------

    public function testBatchSendsTheListUnderItems(): void
    {
        // `items` is what MessageBatchPush declares. Any other key is a 422 that only shows up
        // against a real server.
        [$client, $http] = Fake::client([Fake::json(200, ['items' => [], 'succeeded' => 0, 'failed' => 0])]);

        $client->messages->pushBatch([new MessagePush('a@b.c', 's', 'b')]);

        self::assertSame(['items'], array_keys($http->body()));
        self::assertSame('a@b.c', $http->body()['items'][0]['recipient_email']);
    }

    public function testABatchItemSendsItsOrderStatus(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, ['items' => [], 'succeeded' => 0, 'failed' => 0])]);

        $client->messages->pushBatch([
            new MessagePush('a@b.c', 's', 'b', orderStatus: OrderStatus::Delivered),
            new MessagePush('d@e.f', 's', 'b'),
        ]);

        self::assertSame('delivered', $http->body()['items'][0]['order_status']);
        self::assertArrayNotHasKey('order_status', $http->body()['items'][1]);
    }

    public function testBatchReportsFailuresAsData(): void
    {
        [$client] = Fake::client([Fake::json(200, [
            'items' => [
                ['index' => 0, 'ok' => true, 'result' => Fake::pushResult()],
                ['index' => 1, 'ok' => false, 'error_code' => 'common.validation_failed'],
            ],
            'succeeded' => 1,
            'failed' => 1,
        ])]);

        $result = $client->messages->pushBatch([
            new MessagePush('a@b.c', 's', 'b'),
            new MessagePush('bad', 's', 'b'),
        ]);

        self::assertSame(1, $result->succeeded);
        self::assertCount(1, $result->failures());
        self::assertSame(1, $result->failures()[0]->index);
        self::assertSame('common.validation_failed', $result->failures()[0]->errorCode);
        self::assertNotNull($result->items[0]->result);
        self::assertSame('m_8sKq2Vd1', $result->items[0]->result->id);
    }

    public function testBatchCarriesOneIdempotencyKey(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, ['items' => [], 'succeeded' => 0, 'failed' => 0])]);

        $client->messages->pushBatch([], 'nightly-2026-08-20');

        self::assertSame('nightly-2026-08-20', $http->only()->getHeaderLine('Idempotency-Key'));
    }

    public function testABatchStillRunningUnderTheKeyThrowsAndIsNotRetried(): void
    {
        // The whole batch's 409, never an item's result. Retrying it inside the SDK would only hit
        // the same live run, so it is the caller's to retry later with the same key.
        [$client, $http] = Fake::client([
            Fake::json(409, ['error_code' => 'idempotency.request_in_progress']),
            Fake::json(200, ['items' => [], 'succeeded' => 0, 'failed' => 0]),
        ], new RetryPolicy(maxRetries: 3, baseDelayMs: 0, maxDelayMs: 0));

        try {
            $client->messages->pushBatch(
                [new MessagePush(recipientEmail: 'a@b.c', subject: 's', body: 'b')],
                'nightly-2026-08-20',
            );
            self::fail('expected ConflictException');
        } catch (ConflictException $thrown) {
            self::assertSame('idempotency.request_in_progress', $thrown->errorCode);
        }

        self::assertSame('nightly-2026-08-20', $http->only()->getHeaderLine('Idempotency-Key'));
    }

    // --- Message reads -------------------------------------------------------------------

    public function testGet(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, Fake::messageView())]);

        $message = $client->messages->get('m_8sKq2Vd1');

        self::assertSame('GET', $http->only()->getMethod());
        self::assertStringEndsWith('/api/v1/messages/m_8sKq2Vd1', (string) $http->only()->getUri());
        self::assertTrue($message->delivery->delivered);
        self::assertNotNull($message->delivery->sms);
        self::assertSame(1, $message->delivery->sms->creditsCharged);
    }

    public function testGetOfAnotherBusinessesIdIsA404(): void
    {
        // Deliberately indistinguishable from "does not exist": a probe must not be able to tell
        // the two apart.
        [$client] = Fake::client([Fake::json(404, ['error_code' => 'message.not_found'])]);

        $this->expectException(ResourceMissingException::class);
        $client->messages->get('m_someone_elses');
    }

    public function testEachFollowsCursorsToTheEnd(): void
    {
        [$client, $http] = Fake::client([
            Fake::json(200, ['items' => [Fake::messageView()], 'next_cursor' => 'cur_2']),
            Fake::json(200, ['items' => [Fake::messageView()], 'next_cursor' => null]),
        ]);

        $messages = iterator_to_array($client->messages->each(), false);

        self::assertCount(2, $messages);
        self::assertContainsOnlyInstancesOf(Message::class, $messages);
        self::assertStringNotContainsString('cursor', (string) $http->requests[0]->getUri());
        self::assertStringContainsString('cursor=cur_2', (string) $http->requests[1]->getUri());
    }

    public function testEachIsLazy(): void
    {
        // A merchant with a year of history should not have to hold it in memory to count it.
        [$client, $http] = Fake::client([
            Fake::json(200, ['items' => [Fake::messageView()], 'next_cursor' => null]),
        ]);

        $generator = $client->messages->each();
        self::assertCount(0, $http->requests);

        $generator->current();
        self::assertCount(1, $http->requests);
    }

    // --- Message writes ------------------------------------------------------------------

    public function testResendSms(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, ['queued' => true, 'credits' => 1])]);

        $outcome = $client->messages->resendSms('m_8sKq2Vd1');

        self::assertSame('POST', $http->only()->getMethod());
        self::assertStringEndsWith('/messages/m_8sKq2Vd1/sms', (string) $http->only()->getUri());
        self::assertTrue($outcome->queued);
        self::assertNotSame('', $http->only()->getHeaderLine('Idempotency-Key'));
    }

    public function testResendSmsConflictsWhenOneAlreadyWent(): void
    {
        // A second send would add a second charge and a second interruption, nothing else.
        [$client] = Fake::client([Fake::json(409, ['error_code' => 'sms.already_sent'])]);

        $this->expectException(ConflictException::class);
        $client->messages->resendSms('m_8sKq2Vd1');
    }

    public function testAResendWhileASendIsStillQueuedIsAConflictWithARequestId(): void
    {
        [$client] = Fake::client([Fake::json(
            409,
            ['error_code' => 'sms.already_sent', 'detail' => 'already queued'],
            ['X-Request-Id' => 'abc123'],
        )]);

        try {
            $client->messages->resendSms('m_8sKq2Vd1');
            self::fail('expected ConflictException');
        } catch (ConflictException $thrown) {
            self::assertSame('sms.already_sent', $thrown->errorCode);
            self::assertSame('abc123', $thrown->getRequestId());
        }
    }

    public function testResendWhatsApp(): void
    {
        [$client, $http] = Fake::client([
            Fake::json(200, ['queued' => true, 'credits' => 1, 'sms_fallback' => true]),
        ]);

        $outcome = $client->messages->resendWhatsApp('m_8sKq2Vd1');

        self::assertStringEndsWith('/messages/m_8sKq2Vd1/whatsapp', (string) $http->only()->getUri());
        self::assertTrue($outcome->smsFallback);
    }

    public function testRetract(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, [
            'id' => 'm_8sKq2Vd1',
            'status' => 'retracted',
            'retracted' => true,
            'sends_cancelled' => 1,
            'already_notified' => false,
        ])]);

        $result = $client->messages->retract('m_8sKq2Vd1');

        self::assertStringEndsWith('/messages/m_8sKq2Vd1/retract', (string) $http->only()->getUri());
        self::assertTrue($result->retracted);
        self::assertSame(1, $result->sendsCancelled);
    }

    public function testASecondRetractionIsNotAFailure(): void
    {
        [$client] = Fake::client([Fake::json(200, [
            'id' => 'm_8sKq2Vd1',
            'status' => 'retracted',
            'retracted' => false,
            'sends_cancelled' => 0,
            'already_notified' => true,
        ])]);

        $result = $client->messages->retract('m_8sKq2Vd1');

        self::assertFalse($result->retracted);
        self::assertTrue($result->alreadyNotified);
    }

    // --- Credits -------------------------------------------------------------------------

    public function testCreditBalance(): void
    {
        [$client, $http] = Fake::client([
            Fake::json(200, ['balance' => 3, 'low_balance' => true, 'threshold' => 10]),
        ]);

        $balance = $client->credits->balance();

        self::assertStringEndsWith('/api/v1/credits', (string) $http->only()->getUri());
        self::assertSame(3, $balance->balance);
        self::assertTrue($balance->lowBalance);
    }

    // --- Recipients ----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function recipient(): array
    {
        return [
            'recipient_email' => 'buyer@example.com',
            'phone' => '+372 •••• 0134',
            'sms_status' => 'active',
            'sms_status_at' => '2026-08-20T09:00:00Z',
        ];
    }

    public function testRecipientReadReturnsAMaskedNumber(): void
    {
        // A leaked key must not be usable to dump a phone book.
        [$client, $http] = Fake::client([Fake::json(200, self::recipient())]);

        $recipient = $client->recipients->sms('buyer@example.com');

        self::assertStringEndsWith('/recipients/buyer%40example.com/sms', (string) $http->only()->getUri());
        self::assertSame('+372 •••• 0134', $recipient->phone);
        self::assertSame(RecipientStatus::Active, $recipient->smsStatus);
        self::assertNull($recipient->smsConsentAt);
        self::assertNull($recipient->smsConsentSource);
    }

    public function testRecipientReadCarriesWhenAndWhereConsentBegan(): void
    {
        [$client] = Fake::client([Fake::json(200, [
            ...self::recipient(),
            'sms_consent_at' => '2026-08-01T12:00:00Z',
            'sms_consent_source' => 'api',
        ])]);

        $recipient = $client->recipients->sms('buyer@example.com');

        self::assertSame('2026-08-01T12:00:00+00:00', $recipient->smsConsentAt?->format(DATE_ATOM));
        self::assertSame(SmsConsentSource::Api, $recipient->smsConsentSource);
    }

    public function testAnUnknownConsentSourceFallsBackToTheString(): void
    {
        [$client] = Fake::client([Fake::json(200, [...self::recipient(), 'sms_consent_source' => 'kiosk'])]);

        self::assertSame('kiosk', $client->recipients->sms('buyer@example.com')->smsConsentSource);
    }

    public function testSetPhone(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, self::recipient())]);

        $client->recipients->setPhone('buyer@example.com', '+37255550134');

        self::assertSame('PUT', $http->only()->getMethod());
        self::assertSame(['phone' => '+37255550134'], $http->body());
    }

    public function testClearPhone(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, [...self::recipient(), 'phone' => null])]);

        $recipient = $client->recipients->clearPhone('buyer@example.com');

        self::assertSame('DELETE', $http->only()->getMethod());
        self::assertNull($recipient->phone);
    }

    public function testEraseWhatsAppReturnsTheReceipt(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, [
            'replies_deleted' => 4,
            'webhook_payloads_scrubbed' => 2,
            'auto_reply_windows_dropped' => 1,
            'consent_withdrawn' => true,
            'replies_a_forward_email_may_have_carried' => 3,
            'forward_setting' => 'full',
            'first_erased_at' => '2026-08-20T10:00:00Z',
        ])]);

        $receipt = $client->recipients->eraseWhatsApp('buyer@example.com');

        self::assertSame('POST', $http->only()->getMethod());
        self::assertStringEndsWith('/recipients/buyer%40example.com/whatsapp/erase', (string) $http->only()->getUri());
        self::assertTrue($receipt->consentWithdrawn);
        // The half only the merchant can finish.
        self::assertSame(3, $receipt->repliesAForwardEmailMayHaveCarried);
    }

    public function testErasePostsWithAKeyAndReturnsTheReport(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, [
            'messages_deleted' => 2,
            'message_events_deleted' => 5,
            'channel_sends_deleted' => 1,
            'message_links_deleted' => 2,
            'whatsapp_replies_deleted' => 0,
            'webhook_payloads_scrubbed' => 1,
            'queued_jobs_cancelled' => 0,
            'idempotency_records_deleted' => 2,
            'audit_entries_pseudonymised' => 1,
            'subscription_deleted' => true,
            'stops_kept' => 1,
            'suppressions_kept' => 0,
            'auto_reply_windows_dropped' => 0,
            'replies_a_forward_email_may_have_carried' => 0,
        ])]);

        $report = $client->recipients->erase('buyer+tag@example.com');

        self::assertSame('POST', $http->only()->getMethod());
        self::assertStringEndsWith('/recipients/buyer%2Btag%40example.com/erase', (string) $http->only()->getUri());
        self::assertNotSame('', $http->only()->getHeaderLine('Idempotency-Key'));
        self::assertSame(2, $report->messagesDeleted);
        self::assertSame(1, $report->stopsKept);
        self::assertTrue($report->subscriptionDeleted);
    }

    public function testEraseForwardsACallerKey(): void
    {
        // Retrying with the same key is how a timed-out erasure gets its original counts back.
        [$client, $http] = Fake::client([Fake::json(200, [])]);

        $client->recipients->erase('buyer@example.com', 'erase-buyer-1');

        self::assertSame('erase-buyer-1', $http->only()->getHeaderLine('Idempotency-Key'));
    }

    public function testEraseCannotBeSteeredToAnotherRoute(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, [])]);

        $client->recipients->erase('a/../../keys@example.com');

        self::assertStringEndsWith(
            '/recipients/a%2F..%2F..%2Fkeys%40example.com/erase',
            (string) $http->only()->getUri(),
        );
    }

    /** @return list<array{string, string}> */
    public static function valuesThatAreNotAnAddress(): array
    {
        $cases = [];
        foreach (['erase', 'eraseWhatsApp'] as $method) {
            foreach (['', '   ', 'buyer', '@example.com', 'buyer@', 'buyer@localhost', 'a b@example.com'] as $email) {
                $cases[] = [$method, $email];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('valuesThatAreNotAnAddress')]
    public function testEraseRefusesAValueThatIsNotAnAddress(string $method, string $email): void
    {
        // The API answers 422 for these anyway. An erasure is the call where a slip should fail at
        // the caller's line, before anything is sent.
        [$client, $http] = Fake::client([Fake::json(200, [])]);

        try {
            $client->recipients->{$method}($email);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $thrown) {
            self::assertStringContainsString('email address', $thrown->getMessage());
            if (trim($email) !== '') {
                self::assertStringNotContainsString($email, $thrown->getMessage());
            }
        }
        self::assertSame([], $http->requests);
    }

    public function testEraseTrimsSurroundingWhitespace(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, [])]);

        $client->recipients->erase(" buyer@example.com\n");

        self::assertStringEndsWith('/recipients/buyer%40example.com/erase', (string) $http->only()->getUri());
    }

    // --- Keys ----------------------------------------------------------------------------

    public function testKeysListUnwrapsItems(): void
    {
        [$client] = Fake::client([Fake::json(200, ['items' => [[
            'id' => 'k_1',
            'name' => 'orders',
            'masked' => 'bbx_live_••••••••cdef',
            'created' => '2026-08-01T00:00:00Z',
        ]]])]);

        $keys = $client->keys->list();

        self::assertCount(1, $keys);
        self::assertSame('k_1', $keys[0]->id);
        self::assertNull($keys[0]->mintedBy);
    }

    public function testKeysListCarriesTheMintingKey(): void
    {
        [$client] = Fake::client([Fake::json(200, ['items' => [[
            'id' => 'k_2',
            'name' => 'planted',
            'masked' => 'bbx_live_••••••••abcd',
            'created' => '2026-08-01T00:00:00Z',
            'minted_by' => 'k_1',
        ]]])]);

        $keys = $client->keys->list();

        self::assertSame('k_1', $keys[0]->mintedBy);
    }

    public function testKeyCreate(): void
    {
        [$client, $http] = Fake::client([Fake::json(201, [
            'name' => 'orders',
            'key' => 'bbx_live_secret',
            'masked' => 'bbx_live_••••••••cret',
            'created' => '2026-08-20T00:00:00Z',
        ])]);

        $key = $client->keys->create('orders');

        self::assertSame(['name' => 'orders'], $http->body());
        self::assertSame('bbx_live_secret', $key->key);
        // Readable when asked for, redacted when dumped.
        self::assertStringNotContainsString('bbx_live_secret', print_r($key, true));
    }

    public function testDetachIsSentOnlyWhenAskedFor(): void
    {
        [$client, $http] = Fake::client([Fake::json(201, [
            'name' => 'rotation',
            'key' => 'bbx_live_new',
            'masked' => 'bbx_live_••••••••_new',
            'created' => '2026-10-07T00:00:00Z',
        ])]);

        $client->keys->create('rotation', detach: true);

        self::assertSame(['name' => 'rotation', 'detach' => true], $http->body());
    }

    public function testAMintedKeyIsRedactedForJsonEncodeToo(): void
    {
        // **The path that actually leaks.** `__debugInfo()` covers `var_dump()` and nothing else,
        // while a structured logger reaches for `json_encode()`: Monolog's `JsonFormatter`, and
        // every JSON-lines formatter like it, encodes the context array. Without `jsonSerialize()`
        // a line as ordinary as `$log->info('key minted', ['key' => $new])` wrote `bbx_live_…` and
        // the whole of `$raw` into the log stream, where it has to be treated as compromised.
        [$client] = Fake::client([Fake::json(201, [
            'name' => 'orders',
            'key' => 'bbx_live_secret',
            'masked' => 'bbx_live_••••••••cret',
            'created' => '2026-08-20T00:00:00Z',
        ])]);

        $encoded = json_encode($client->keys->create('orders'), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('bbx_live_secret', $encoded);
        self::assertStringContainsString('<redacted>', $encoded);
        self::assertStringContainsString('masked', $encoded, 'the mask is still useful');
    }

    public function testASigningSecretIsRedactedForJsonEncodeToo(): void
    {
        // The create response is the one moment `$secret` is the real signing secret, and anyone
        // holding it can forge deliveries to the endpoint it belongs to.
        [$client] = Fake::client([Fake::json(201, Fake::endpoint())]);

        $encoded = json_encode($client->webhookEndpoints->create('https://example.com/hooks'), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('whsec_real_secret', $encoded);
        self::assertStringContainsString('<redacted>', $encoded);
    }

    public function testKeyRevokeHandlesAnEmptyBody(): void
    {
        [$client, $http] = Fake::client([Fake::json(204)]);

        $client->keys->revoke('k_1');

        self::assertSame('DELETE', $http->only()->getMethod());
    }

    public function testAPageSizeBelowOneIsRefused(): void
    {
        // The server clamps `limit` into its own range, so this is not an error there — it quietly
        // becomes one message per request, and a merchant walking a year of history meets that as a
        // job that takes hours rather than as a mistake in their call.
        [$client, $http] = Fake::client([Fake::json(200, ['items' => [], 'next_cursor' => null])]);

        $this->expectExceptionMessage('pageSize must be at least 1');

        try {
            iterator_to_array($client->messages->each(pageSize: 0));
        } finally {
            self::assertSame([], $http->requests, 'nothing should reach the network');
        }
    }

    // --- Webhook endpoints ---------------------------------------------------------------

    public function testEndpointCreateDefaultsToEveryEventType(): void
    {
        // A narrow subscription is how a new event type silently passes an integration by.
        [$client, $http] = Fake::client([Fake::json(201, Fake::endpoint())]);

        $endpoint = $client->webhookEndpoints->create('https://example.com/hooks');

        self::assertSame([
            'url' => 'https://example.com/hooks',
            'event_types' => [],
            'description' => '',
        ], $http->body());
        self::assertSame('whsec_real_secret', $endpoint->secret);
    }

    public function testEndpointCreateAcceptsEnums(): void
    {
        [$client, $http] = Fake::client([Fake::json(201, Fake::endpoint())]);

        $client->webhookEndpoints->create(
            'https://example.com/hooks',
            [WebhookEventType::MessageBounced, 'sms.failed'],
            'orders',
        );

        self::assertSame(['message.bounced', 'sms.failed'], $http->body()['event_types']);
        self::assertSame('orders', $http->body()['description']);
    }

    public function testPushParsesThePerChannelEmailBlock(): void
    {
        // Its own block precisely so a bounce suppression never surfaces as an SMS reason, nor an
        // SMS STOP as an email one.
        [$client] = Fake::client([Fake::json(201, [
            ...Fake::pushResult(),
            'email' => ['sending' => false, 'skipped_reason' => 'suppressed'],
        ])]);

        $result = $client->messages->push(new MessagePush(recipientEmail: 'a@b.c', subject: 's', body: 'b'));

        self::assertNotNull($result->email);
        self::assertFalse($result->email->sending);
        self::assertSame('suppressed', $result->email->skippedReason);
    }

    public function testPushEmailBlockIsNullWhenNothingWasNudged(): void
    {
        [$client] = Fake::client([Fake::json(201, [...Fake::pushResult(), 'email' => null])]);

        $result = $client->messages->push(new MessagePush(recipientEmail: 'a@b.c', subject: 's', body: 'b'));

        self::assertNull($result->email);
    }

    public function testPushEmailBlockSurvivesAnUnknownReason(): void
    {
        // The vocabulary grows server-side; an unknown reason must arrive intact rather than
        // being dropped, so a merchant can treat it as "not sent" as the API documents.
        [$client] = Fake::client([Fake::json(201, [
            ...Fake::pushResult(),
            'email' => ['sending' => false, 'skipped_reason' => 'invented_later'],
        ])]);

        $result = $client->messages->push(new MessagePush(recipientEmail: 'a@b.c', subject: 's', body: 'b'));

        self::assertNotNull($result->email);
        self::assertSame('invented_later', $result->email->skippedReason);
    }

    public function testEndpointTestReportsAWorkingEndpoint(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, [
            'delivered' => true, 'status_code' => 200, 'error' => null, 'duration_ms' => 42,
        ])]);

        $result = $client->webhookEndpoints->test('we_1');

        self::assertSame('POST', $http->only()->getMethod());
        self::assertStringEndsWith(
            '/webhook-endpoints/we_1/test',
            $http->only()->getUri()->getPath(),
        );
        self::assertTrue($result->delivered);
        self::assertSame(200, $result->statusCode);
        self::assertSame(42, $result->durationMs);
    }

    public function testEndpointTestDoesNotThrowWhenTheReceiverIsBroken(): void
    {
        // The failure is at the caller's own server, so throwing would describe the wrong hop —
        // and a merchant debugging their handler would be fighting an exception instead of
        // reading a status code.
        [$client] = Fake::client([Fake::json(200, [
            'delivered' => false,
            'status_code' => 500,
            'error' => 'The endpoint answered HTTP 500.',
            'duration_ms' => 17,
        ])]);

        $result = $client->webhookEndpoints->test('we_1');

        self::assertFalse($result->delivered);
        self::assertSame(500, $result->statusCode);
        self::assertStringContainsString('500', (string) $result->error);
    }

    public function testEndpointTestDistinguishesUnreachableFromAZeroStatus(): void
    {
        // Why `statusCode` is nullable rather than defaulting to 0: `0` would read as an HTTP
        // status, and "never answered" is a different fact from any status it could have sent.
        [$client] = Fake::client([Fake::json(200, [
            'delivered' => false, 'status_code' => null, 'error' => 'no route', 'duration_ms' => 0,
        ])]);

        $result = $client->webhookEndpoints->test('we_1');

        self::assertNull($result->statusCode);
    }

    public function testEndpointListSurfacesADisabledEndpoint(): void
    {
        // A silent endpoint looks exactly like nothing having happened.
        [$client] = Fake::client([Fake::json(200, ['items' => [
            [...Fake::endpoint(), 'disabled' => true, 'last_error' => 'timeout'],
        ]])]);

        $endpoints = $client->webhookEndpoints->list();

        self::assertTrue($endpoints[0]->disabled);
        self::assertSame('timeout', $endpoints[0]->lastError);
    }

    public function testEndpointDelete(): void
    {
        [$client, $http] = Fake::client([Fake::json(204)]);

        $client->webhookEndpoints->delete('we_1');

        self::assertSame('DELETE', $http->only()->getMethod());
    }

    // --- Pagination cannot hang ----------------------------------------------------------

    public function testARepeatedCursorThrowsInsteadOfLooping(): void
    {
        // A server that hands back a cursor it already gave would spin `each()` forever, yielding
        // the same page over and over. That is a server bug, but its shape in a merchant's process
        // is a worker that never returns, which is far harder to diagnose than an exception.
        [$client, $http] = Fake::client([
            Fake::json(200, ['items' => [Fake::messageView()], 'next_cursor' => 'stuck']),
        ]);

        try {
            iterator_to_array($client->messages->each(), false);
            self::fail('expected a pagination failure');
        } catch (BeaconBoxException $thrown) {
            self::assertStringContainsString('did not advance', $thrown->getMessage());
        }

        // Two pages fetched, then it stopped: the first `stuck`, and the repeat that proved it.
        self::assertCount(2, $http->requests);
    }

    public function testDistinctCursorsAreFollowedNormally(): void
    {
        [$client] = Fake::client([
            Fake::json(200, ['items' => [Fake::messageView()], 'next_cursor' => 'a']),
            Fake::json(200, ['items' => [Fake::messageView()], 'next_cursor' => 'b']),
            Fake::json(200, ['items' => [Fake::messageView()], 'next_cursor' => null]),
        ]);

        self::assertCount(3, iterator_to_array($client->messages->each(), false));
    }

    public function testAnEmptyCursorStringEndsIteration(): void
    {
        // `""` is not a cursor. Treating it as one would fetch page one forever.
        [$client, $http] = Fake::client([
            Fake::json(200, ['items' => [Fake::messageView()], 'next_cursor' => '']),
        ]);

        self::assertCount(1, iterator_to_array($client->messages->each(), false));
        self::assertCount(1, $http->requests);
    }
}

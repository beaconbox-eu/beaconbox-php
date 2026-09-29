<?php

declare(strict_types=1);

namespace BeaconBox\Tests;

use BeaconBox\BeaconBoxClient;
use BeaconBox\Exception\ApiConnectionException;
use BeaconBox\Exception\AuthenticationException;
use BeaconBox\Exception\ConflictException;
use BeaconBox\Exception\InvalidRequestException;
use BeaconBox\Exception\PermissionException;
use BeaconBox\Exception\RateLimitException;
use BeaconBox\Exception\ResourceMissingException;
use BeaconBox\Exception\ServerException;
use BeaconBox\Model\MessagePush;
use BeaconBox\RetryPolicy;
use BeaconBox\Version;
use BeaconBox\Tests\Support\ConnectionFailure;
use BeaconBox\Tests\Support\Fake;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The transport: auth, idempotency, retries, error mapping and the security defaults.
 *
 * What is worth pinning here is not "it makes HTTP requests". It is the idempotency contract,
 * which is the whole reason a retry in this SDK is safe rather than a duplicate-message generator.
 */
final class TransportTest extends TestCase
{
    private function push(): MessagePush
    {
        return new MessagePush('buyer@example.com', 'Your order has shipped', 'Tracking XY123.');
    }

    // --- Authentication ------------------------------------------------------------------

    public function testAuthenticatesAndIdentifiesItself(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, ['balance' => 0, 'low_balance' => false, 'threshold' => 0])]);

        $client->credits->balance();

        self::assertSame('Bearer ' . Fake::API_KEY, $http->only()->getHeaderLine('Authorization'));
        self::assertStringStartsWith('beaconbox-php/', $http->only()->getHeaderLine('User-Agent'));
        self::assertSame('application/json', $http->only()->getHeaderLine('Accept'));
    }

    public function testAnEmptyApiKeyIsRefusedAtConstruction(): void
    {
        // Not at the first request, where it would surface as a confusing 401.
        $this->expectException(\InvalidArgumentException::class);
        new BeaconBoxClient('   ');
    }

    // --- Idempotency ---------------------------------------------------------------------

    public function testEveryWriteCarriesAnIdempotencyKey(): void
    {
        // The API declares the header required so a generated SDK cannot forget it. This is that
        // SDK not forgetting it.
        [$client, $http] = Fake::client([Fake::json(201, Fake::pushResult())]);

        $client->messages->push($this->push());

        self::assertNotSame('', $http->only()->getHeaderLine('Idempotency-Key'));
    }

    public function testARetryReusesTheSameIdempotencyKey(): void
    {
        // **The most important test in this package.** A connection error means the answer was
        // lost, not that the work was not done. Retrying with a *fresh* key would store a second
        // message and charge a second credit, for a customer who has already been messaged.
        [$client, $http] = Fake::client(
            [new ConnectionFailure(), Fake::json(201, Fake::pushResult())],
            new RetryPolicy(maxRetries: 2, baseDelayMs: 0, maxDelayMs: 0),
        );

        $client->messages->push($this->push());

        self::assertCount(2, $http->requests);
        self::assertCount(1, array_unique($http->idempotencyKeys()), 'a retry minted a new key');
    }

    public function testACallerSuppliedKeySurvivesTheRetriesToo(): void
    {
        // The better practice: a natural key means a retry from anywhere, a queue, a cron, a human
        // clicking twice, collapses onto it rather than only the retries this SDK makes itself.
        [$client, $http] = Fake::client(
            [Fake::json(500), Fake::json(201, Fake::pushResult())],
            new RetryPolicy(maxRetries: 1, baseDelayMs: 0, maxDelayMs: 0),
        );

        $client->messages->push($this->push(), 'order-4711-shipped');

        self::assertSame(['order-4711-shipped', 'order-4711-shipped'], $http->idempotencyKeys());
    }

    public function testTwoSeparateCallsGetSeparateKeys(): void
    {
        // The other half: reuse within a call, never across calls. Two pushes are two messages.
        [$client, $http] = Fake::client([Fake::json(201, Fake::pushResult())]);

        $client->messages->push($this->push());
        $client->messages->push($this->push());

        self::assertCount(2, array_unique($http->idempotencyKeys()));
    }

    public function testAReadCarriesNoIdempotencyKey(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, Fake::messageView())]);

        $client->messages->get('m_1');

        self::assertFalse($http->only()->hasHeader('Idempotency-Key'));
    }

    public function testSettingAPhoneCarriesNoIdempotencyKey(): void
    {
        // Idempotent by construction: setting a number twice leaves one number. Sending a message
        // twice sends two.
        [$client, $http] = Fake::client([Fake::json(200, [
            'recipient_email' => 'a@b.c', 'phone' => null, 'sms_status' => 'none', 'sms_status_at' => null,
        ])]);

        $client->recipients->setPhone('a@b.c', '+37255550134');

        self::assertSame('PUT', $http->only()->getMethod());
        self::assertFalse($http->only()->hasHeader('Idempotency-Key'));
    }

    // --- Retries -------------------------------------------------------------------------

    public function testRetriesALostConnectionThenSucceeds(): void
    {
        [$client, $http] = Fake::client(
            [new ConnectionFailure(), Fake::json(201, Fake::pushResult())],
            new RetryPolicy(maxRetries: 2, baseDelayMs: 0, maxDelayMs: 0),
        );

        $result = $client->messages->push($this->push());

        self::assertSame('m_8sKq2Vd1', $result->id);
        self::assertCount(2, $http->requests);
    }

    public function testGivesUpAndSaysWhatIsNotKnown(): void
    {
        [$client] = Fake::client(
            [new ConnectionFailure()],
            new RetryPolicy(maxRetries: 1, baseDelayMs: 0, maxDelayMs: 0),
        );

        try {
            $client->messages->push($this->push());
            self::fail('expected ApiConnectionException');
        } catch (ApiConnectionException $thrown) {
            self::assertStringContainsString('not proof the work did not happen', $thrown->getMessage());
        }
    }

    public function testRetriesA5xxUntilTheBudgetIsSpent(): void
    {
        [$client, $http] = Fake::client(
            [Fake::json(503)],
            new RetryPolicy(maxRetries: 2, baseDelayMs: 0, maxDelayMs: 0),
        );

        $this->expectException(ServerException::class);

        try {
            $client->messages->push($this->push());
        } finally {
            self::assertCount(3, $http->requests);
        }
    }

    public function testAConflictIsReportedRatherThanWaitedOut(): void
    {
        // "Your earlier attempt is still running" is a truthful answer to give a caller. A client
        // that quietly blocked for a second instead would be hiding it.
        [$client, $http] = Fake::client(
            [Fake::json(409, ['error_code' => 'message.push_conflict'])],
            new RetryPolicy(maxRetries: 3, baseDelayMs: 0, maxDelayMs: 0),
        );

        $this->expectException(ConflictException::class);

        try {
            $client->messages->push($this->push());
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    public function testRetryAfterIsHonouredInFullNotClampedToMaxDelay(): void
    {
        // `maxDelayMs` caps a delay the SDK invented; a `Retry-After` is the server's own answer
        // to when it will be ready, and shortening it only earns a second 429.
        $policy = new RetryPolicy(maxRetries: 1, maxDelayMs: 2_000, maxRetryAfterMs: 30_000);

        self::assertGreaterThanOrEqual(10_000, $policy->delayMs(0, 10_000));
    }

    public function testRetryAfterIsNeverWaitedOutBelowWhatTheServerAsked(): void
    {
        // The floor, sampled hard. Full jitter *within* the directive would undercut it.
        $policy = new RetryPolicy(maxDelayMs: 2_000, maxRetryAfterMs: 30_000);

        for ($i = 0; $i < 200; ++$i) {
            self::assertGreaterThanOrEqual(5_000, $policy->delayMs(0, 5_000));
        }
    }

    public function testRetryAfterIsJitteredOnTopOfItself(): void
    {
        // The herd is at its *worst* on this path: every worker that hit the same 429 was handed
        // the same number, so obeying it exactly reconstructs the lockstep jitter exists to break.
        $policy = new RetryPolicy(maxDelayMs: 2_000, maxRetryAfterMs: 30_000);

        $samples = [];
        for ($i = 0; $i < 50; ++$i) {
            $delay = $policy->delayMs(0, 5_000);
            self::assertLessThanOrEqual(6_000, $delay, 'spread is bounded to a fraction of the wait');
            $samples[$delay] = true;
        }

        self::assertGreaterThan(1, \count($samples), 'every worker would retry in the same millisecond');
    }

    public function testAHostileHeaderStillCannotParkAWorker(): void
    {
        // The original reason for the clamp survives, moved onto its own cap.
        $policy = new RetryPolicy(maxRetryAfterMs: 30_000);

        self::assertLessThanOrEqual(36_000, $policy->delayMs(0, 3_600_000));
    }

    public function testAWaitLongerThanWeWillSitThroughStopsTheRetry(): void
    {
        // Retrying early is not a compromise: it is a request the server has already said it will
        // refuse. Better to hand the caller the number and let them schedule it.
        $policy = new RetryPolicy(maxRetries: 3, maxRetryAfterMs: 30_000);

        self::assertTrue($policy->shouldRetry(429, 0, 10_000));
        self::assertFalse($policy->shouldRetry(429, 0, 300_000));
        self::assertTrue($policy->shouldRetry(429, 0, null));
        self::assertTrue($policy->shouldRetry(503, 0));
    }

    public function testALongRetryAfterFailsFastAndHandsBackTheNumber(): void
    {
        // The caller waits nothing and learns when to come back, instead of blocking for the cap
        // and being refused anyway.
        [$client, $http] = Fake::client(
            [Fake::json(429, [], ['Retry-After' => '600'])],
            new RetryPolicy(maxRetries: 3, baseDelayMs: 0, maxDelayMs: 0, maxRetryAfterMs: 30_000),
        );

        try {
            $client->messages->push($this->push());
            self::fail('expected a RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertSame(600_000, $exception->retryAfterMs);
            // And spent no further calls on a rate-limited service.
            self::assertCount(1, $http->requests);
        }
    }

    public function testRetryAfterSurvivesOntoTheException(): void
    {
        [$client] = Fake::client([Fake::json(429, [], ['Retry-After' => '42'])]);

        try {
            $client->messages->push($this->push());
            self::fail('expected a RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertSame(42_000, $exception->retryAfterMs);
        }
    }

    public function testAFractionalRetryAfterIsHonoured(): void
    {
        // The README promises both SDKs back off identically down to the constants, and the Python
        // one reads `1.5` as 1.5 seconds. `ctype_digit` refused it, so the same 429 from the same
        // server produced a server-directed wait in one language and an invented one in the other.
        [$client] = Fake::client([Fake::json(429, [], ['Retry-After' => '1.5'])]);

        try {
            $client->messages->push($this->push());
            self::fail('expected a RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertSame(1_500, $exception->retryAfterMs);
        }
    }

    /** @return list<array{string}> */
    public static function absurdRetryAfterValues(): array
    {
        return [['99999999999999999999'], [str_repeat('9', 40)]];
    }

    #[DataProvider('absurdRetryAfterValues')]
    public function testAnAbsurdRetryAfterIsCappedRatherThanOverflowed(string $value): void
    {
        // `((int) $seconds) * 1000` overflows to a float, and the parser declares `?int` under
        // `strict_types`, so a broken or hostile header turned a 429 into a TypeError thrown from
        // inside the retry loop. The caller still gets a rate-limit error with "absurdly long" on
        // it, and the policy still refuses to sit through it.
        [$client] = Fake::client([Fake::json(429, [], ['Retry-After' => $value])]);

        try {
            $client->messages->push($this->push());
            self::fail('expected a RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertSame(PHP_INT_MAX, $exception->retryAfterMs);
            self::assertFalse((new RetryPolicy())->shouldRetry(429, 0, $exception->retryAfterMs));
        }
    }

    /** @return list<array{string}> */
    public static function unusableRetryAfterValues(): array
    {
        // The HTTP-date form is legal and essentially never used by an API; parsing it would mean
        // trusting the caller's clock to agree with the server's.
        return [['Wed, 21 Oct 2026 07:28:00 GMT'], ['-5'], ['soon'], [''], ['1e3'], ['0x10']];
    }

    #[DataProvider('unusableRetryAfterValues')]
    public function testAnUnusableRetryAfterFallsBackToOurOwnBackoff(string $value): void
    {
        [$client] = Fake::client([Fake::json(429, [], ['Retry-After' => $value])]);

        try {
            $client->messages->push($this->push());
            self::fail('expected a RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertNull($exception->retryAfterMs);
        }
    }

    public function testAnErrorWithoutTheHeaderHasNoRetryAfter(): void
    {
        [$client] = Fake::client([Fake::json(429)]);

        try {
            $client->messages->push($this->push());
            self::fail('expected a RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertNull($exception->retryAfterMs);
        }
    }

    public function testBackoffIsJitteredAndCapped(): void
    {
        // Unjittered backoff makes every worker retry in lockstep, a thundering herd arriving
        // exactly when the service is least able to answer.
        $policy = new RetryPolicy(baseDelayMs: 1_000, maxDelayMs: 10_000);

        $samples = [];
        for ($i = 0; $i < 50; ++$i) {
            $delay = $policy->delayMs(3);
            self::assertLessThanOrEqual(10_000, $delay);
            $samples[$delay] = true;
        }

        self::assertGreaterThan(1, \count($samples));
    }

    // --- Error mapping -------------------------------------------------------------------

    /** @return list<array{int, class-string<\Throwable>}> */
    public static function statuses(): array
    {
        return [
            [401, AuthenticationException::class],
            [403, PermissionException::class],
            [404, ResourceMissingException::class],
            [409, ConflictException::class],
            [422, InvalidRequestException::class],
            [400, InvalidRequestException::class],
            [429, RateLimitException::class],
            [500, ServerException::class],
            [503, ServerException::class],
        ];
    }

    /** @param class-string<\Throwable> $expected */
    #[DataProvider('statuses')]
    public function testStatusSelectsTheException(int $status, string $expected): void
    {
        [$client] = Fake::client([Fake::json($status, ['error_code' => 'x.y'])]);

        $this->expectException($expected);
        $client->credits->balance();
    }

    public function testAnErrorCarriesItsMachineReadableCode(): void
    {
        [$client] = Fake::client([Fake::json(404, ['error_code' => 'message.not_found'])]);

        try {
            $client->messages->get('m_1');
            self::fail('expected ResourceMissingException');
        } catch (ResourceMissingException $thrown) {
            self::assertSame('message.not_found', $thrown->errorCode);
            self::assertSame(404, $thrown->statusCode);
            self::assertSame(['error_code' => 'message.not_found'], $thrown->body);
        }
    }

    public function testAnErrorCapturesARequestIdWhenOneIsSent(): void
    {
        // The one thing support needs and a caller never thinks to record.
        [$client] = Fake::client([new Response(500, ['X-Request-Id' => 'req_9'], '{}')]);

        try {
            $client->credits->balance();
            self::fail('expected ServerException');
        } catch (ServerException $thrown) {
            self::assertSame('req_9', $thrown->requestId);
        }
    }

    public function testANonJsonErrorBodyStillRaisesTheRightClass(): void
    {
        // An HTML 502 from a proxy in front of the API must not become a JSON parse error.
        [$client] = Fake::client([new Response(502, [], '<html>bad gateway</html>')]);

        try {
            $client->credits->balance();
            self::fail('expected ServerException');
        } catch (ServerException $thrown) {
            self::assertStringContainsString('bad gateway', (string) ($thrown->body['raw'] ?? ''));
        }
    }

    public function testTheApiKeyIsNeverInAnError(): void
    {
        // Exceptions get logged with their full text far more often than anyone intends.
        [$client] = Fake::client([Fake::json(401, ['error_code' => 'business.invalid_api_key'])]);

        try {
            $client->credits->balance();
            self::fail('expected AuthenticationException');
        } catch (AuthenticationException $thrown) {
            self::assertStringNotContainsString(Fake::API_KEY, $thrown->getMessage());
            self::assertStringNotContainsString(Fake::API_KEY, print_r($thrown->body, true));
        }
    }

    // --- URLs and security defaults ------------------------------------------------------

    public function testBuildsTheVersionedUrl(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, ['items' => [], 'next_cursor' => null])]);

        $client->messages->list();

        self::assertSame(Fake::BASE_URL . '/api/v1/messages', (string) $http->only()->getUri());
    }

    public function testDropsUnsetQueryParameters(): void
    {
        // A null filter must vanish, not become `?cursor=` and match nothing.
        [$client, $http] = Fake::client([Fake::json(200, ['items' => [], 'next_cursor' => null])]);

        $client->messages->list('buyer@example.com', 10);

        $query = (string) $http->only()->getUri()->getQuery();
        self::assertStringContainsString('recipient_email=buyer%40example.com', $query);
        self::assertStringContainsString('limit=10', $query);
        self::assertStringNotContainsString('cursor', $query);
    }

    public function testATrailingSlashOnTheBaseUrlDoesNotDoubleUp(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, ['balance' => 0, 'low_balance' => false, 'threshold' => 0])]);
        self::assertSame(Fake::BASE_URL, Fake::BASE_URL); // guard: Fake has no trailing slash

        $client->credits->balance();

        self::assertStringNotContainsString('//api/v1', (string) $http->only()->getUri());
    }

    public function testPlainHttpToARemoteHostIsRefused(): void
    {
        // Otherwise a typo in configuration puts a live API key on the wire in clear.
        $this->expectExceptionMessage('plain http');
        new BeaconBoxClient(Fake::API_KEY, 'http://api.beaconbox.test');
    }

    /** @return list<array{string}> */
    public static function urlsCarryingAQueryOrFragment(): array
    {
        return [
            ['https://api.beaconbox.test?x=1'],
            ['https://api.beaconbox.test/#frag'],
            ['https://api.beaconbox.test/v2?token=abc'],
            // A bare delimiter with nothing after it. This works because the check uses `isset()`
            // on the parsed component rather than testing it for truth — an empty query string is
            // still a query string, and it breaks the URL identically. Pinned so the check is not
            // "simplified" into a truthiness test, which is the bug the Python SDK had here.
            ['https://api.beaconbox.test?'],
            ['https://api.beaconbox.test#'],
            ['https://api.beaconbox.test/?'],
            ['https://api.beaconbox.test/#'],
        ];
    }

    #[DataProvider('urlsCarryingAQueryOrFragment')]
    public function testABaseUrlWithAQueryOrFragmentIsRefused(string $url): void
    {
        // It would not fail, it would go somewhere else: `request()` appends `/api/v1/...` and then
        // its own `?`, so `https://host?x=1` silently became `https://host?x=1/api/v1/credits` —
        // the whole API path swallowed into a query value, against an endpoint nobody chose.
        $this->expectExceptionMessage('query string or fragment');
        new BeaconBoxClient(Fake::API_KEY, $url);
    }

    public function testABaseUrlMayCarryAPathPrefix(): void
    {
        // A gateway that mounts BeaconBox under a prefix is a real deployment, unlike a query.
        [$client, $http] = Fake::client([Fake::balance()], baseUrl: 'https://gateway.test/beaconbox');

        $client->credits->balance();

        self::assertSame('https://gateway.test/beaconbox/api/v1/credits', (string) $http->only()->getUri());
    }

    /** @return list<array{string}> */
    public static function keysThatCannotGoInAHeader(): array
    {
        return [
            ["bbx\r\nX-Injected: 1"],
            ["bbx_live\nAuthorization: Bearer other"],
            ["bbx_live_\x00abc"],
            ["bbx_live_a\tb"],
            ['bbx_live_caf' . "\u{e9}"],
        ];
    }

    #[DataProvider('keysThatCannotGoInAHeader')]
    public function testAnApiKeyThatCannotGoInAHeaderIsRefused(string $key): void
    {
        // A `\r\n` in the key is request smuggling with the caller's own credential: curl does not
        // sanitise what it is handed in CURLOPT_HTTPHEADER. The usual cause is innocent — a key read
        // from a file keeps its trailing newline, or a word processor substituted a quote mark.
        $this->expectExceptionMessage('cannot go in an HTTP header');
        new BeaconBoxClient($key);
    }

    public function testTheRefusalNeverQuotesTheKey(): void
    {
        // This message reaches a log. The key it is complaining about must not.
        try {
            new BeaconBoxClient("bbx_live_secretvalue\r\nX-Injected: 1");
            self::fail('expected the key to be refused');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringNotContainsString('secretvalue', $exception->getMessage());
        }
    }

    public function testSurroundingWhitespaceIsStillForgiven(): void
    {
        // Stripped, not refused: a key pasted with a trailing newline is the normal case.
        [$client, $http] = Fake::client([Fake::balance()], apiKey: ' ' . Fake::API_KEY . "\n");

        $client->credits->balance();

        self::assertSame('Bearer ' . Fake::API_KEY, $http->only()->getHeaderLine('Authorization'));
    }

    public function testAUserAgentSuffixIdentifiesTheIntegration(): void
    {
        [$client, $http] = Fake::client([Fake::balance()], userAgentSuffix: 'acme-shop/2.1');

        $client->credits->balance();

        $agent = $http->only()->getHeaderLine('User-Agent');
        self::assertStringStartsWith('beaconbox-php/', $agent);
        self::assertStringEndsWith(' acme-shop/2.1', $agent);
    }

    /** @return list<array{string, string}> */
    public static function userAgentSuffixes(): array
    {
        // Byte for byte what the Python SDK produces for the same input. The README promises the two
        // behave the same, and a `User-Agent` that differs between them is the kind of difference
        // nobody notices until it is in a support conversation about which client sent what.
        return [
            ['acme-shop/2.1', 'acme-shop/2.1'],
            ["acme\r\nX-Injected: 1", 'acme X-Injected: 1'],
            ["a\tb", 'a b'],
            ['  padded  ', 'padded'],
            ["caf\u{e9}", 'caf'],
        ];
    }

    #[DataProvider('userAgentSuffixes')]
    public function testASuffixIsMadeHeaderSafe(string $suffix, string $expected): void
    {
        // A label for a human to read must not be able to become a second header, and must not be
        // able to fail a constructor either.
        [$client, $http] = Fake::client([Fake::balance()], userAgentSuffix: $suffix);

        $client->credits->balance();

        $agent = $http->only()->getHeaderLine('User-Agent');
        $base = 'beaconbox-php/' . Version::VERSION . ' php/' . PHP_VERSION;
        self::assertSame($base . ' ' . $expected, $agent);
        self::assertSame(1, preg_match('/^[\x20-\x7e]+$/', $agent), 'header-safe throughout');
    }

    /** @return list<array{string|null}> */
    public static function emptySuffixes(): array
    {
        return [[null], [''], ['   '], ["\r\n"]];
    }

    #[DataProvider('emptySuffixes')]
    public function testAnEmptySuffixLeavesNoTrailingSpace(?string $suffix): void
    {
        // A dangling space would be a User-Agent that differs from the default for no reason.
        [$client, $http] = Fake::client([Fake::balance()], userAgentSuffix: $suffix);

        $client->credits->balance();

        self::assertSame(
            'beaconbox-php/' . Version::VERSION . ' php/' . PHP_VERSION,
            $http->only()->getHeaderLine('User-Agent'),
        );
    }

    public function testPlainHttpToLoopbackIsAllowed(): void
    {
        // The local development stack and this SDK's own live tests run here.
        $client = new BeaconBoxClient(Fake::API_KEY, 'http://localhost:8000');

        self::assertInstanceOf(BeaconBoxClient::class, $client);
    }

    /** @return list<array{string}> */
    public static function badUrls(): array
    {
        return [['ftp://api.beaconbox.test'], ['api.beaconbox.test'], ['']];
    }

    #[DataProvider('badUrls')]
    public function testAUrlThatIsNotAUrlIsRefused(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BeaconBoxClient(Fake::API_KEY, $url);
    }

    public function testAPathSegmentCannotEscapeItsSegment(): void
    {
        // Otherwise a crafted id could address a different endpoint entirely.
        [$client, $http] = Fake::client([Fake::json(200, Fake::messageView())]);

        $client->messages->get('../../admin/keys');

        self::assertStringContainsString('%2F', (string) $http->only()->getUri());
        self::assertStringNotContainsString('/admin/keys', (string) $http->only()->getUri());
    }

    public function testAnEmailIsEncodedIntoItsPathSegment(): void
    {
        [$client, $http] = Fake::client([Fake::json(200, [
            'recipient_email' => 'a@b.c', 'phone' => null, 'sms_status' => 'none', 'sms_status_at' => null,
        ])]);

        $client->recipients->sms('buyer+tag@example.com');

        self::assertStringContainsString('buyer%2Btag%40example.com', (string) $http->only()->getUri());
    }

    public function testInjectingAPsrClientWithoutFactoriesIsRefused(): void
    {
        // A confusing null dereference later, turned into a clear message now.
        $this->expectExceptionMessage('PSR-17');
        [$_, $http] = Fake::client();
        new BeaconBoxClient(Fake::API_KEY, Fake::BASE_URL, 5_000, null, $http);
    }

    public function testTheApiKeyIsNotInTheClientsDebugOutput(): void
    {
        [$client] = Fake::client();

        self::assertStringNotContainsString(Fake::API_KEY, print_r($client, true));
    }
}

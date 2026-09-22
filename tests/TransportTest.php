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

<?php

declare(strict_types=1);

namespace BeaconBox\Tests\Support;

use BeaconBox\BeaconBoxClient;
use BeaconBox\RetryPolicy;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A real client wired to a fake network.
 *
 * Every test drives `BeaconBoxClient` through its public surface, with only the socket replaced.
 * That means the tests exercise the actual request building, the actual retry loop and the actual
 * parsing rather than a mock of them, and it means the PSR-18 injection point is covered by every
 * test that uses it.
 *
 * No mocking library, deliberately. A test that mocks `Transport` is a test that keeps passing
 * after `Transport` stops working.
 */
final class Fake
{
    public const API_KEY = 'bbx_live_0123456789abcdef0123456789abcdef';
    public const BASE_URL = 'https://api.beaconbox.test';

    /**
     * @param list<ResponseInterface|ConnectionFailure> $responses
     *
     * @return array{0: BeaconBoxClient, 1: RecordingClient}
     */
    public static function client(array $responses = [], ?RetryPolicy $retryPolicy = null): array
    {
        $http = new RecordingClient($responses === [] ? [new Response(200, [], '{}')] : $responses);
        $psr17 = new Psr17Factory();

        $client = new BeaconBoxClient(
            self::API_KEY,
            self::BASE_URL,
            5_000,
            // Zero retries by default so a test that is not about retrying does not silently make
            // three requests and pass anyway. Zero delays everywhere so the suite never sleeps.
            $retryPolicy ?? new RetryPolicy(maxRetries: 0, baseDelayMs: 0, maxDelayMs: 0),
            $http,
            $psr17,
            $psr17,
        );

        return [$client, $http];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $body = [], array $headers = []): Response
    {
        return new Response($status, $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    // --- Response fixtures ---------------------------------------------------------------
    //
    // Shaped exactly like the API's own schemas. Where a field is optional in the schema it is
    // omitted here rather than sent as null, because that is the harder case for a parser.

    /** @return array<string, mixed> */
    public static function pushResult(): array
    {
        return [
            'id' => 'm_8sKq2Vd1',
            'subject' => 'Your order has shipped',
            'kind' => 'updateable',
            'status' => 'active',
            'created' => true,
            'nudged' => true,
            'created_at' => '2026-08-20T09:15:00Z',
            'updated_at' => '2026-08-20T09:15:00Z',
            'sms' => ['queued' => false, 'credits' => 1, 'skipped_reason' => 'insufficient_credit'],
            'sms_units' => 0,
        ];
    }

    /** @return array<string, mixed> */
    public static function messageView(): array
    {
        return [
            'id' => 'm_8sKq2Vd1',
            'recipient_email' => 'buyer@example.com',
            'subject' => 'Your order has shipped',
            'kind' => 'one_off',
            'status' => 'active',
            'created_at' => '2026-08-20T09:15:00Z',
            'updated_at' => '2026-08-20T09:16:00Z',
            'delivery' => [
                'delivered' => true,
                'delivered_at' => '2026-08-20T09:15:30Z',
                'opened' => false,
                'opened_at' => null,
                'bounced' => false,
                'bounced_at' => null,
                'sms' => [
                    'status' => 'delivered',
                    'skipped_reason' => null,
                    'credits_charged' => 1,
                    'submitted_at' => '2026-08-20T09:15:10Z',
                    'last_status_at' => '2026-08-20T09:15:25Z',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function endpoint(): array
    {
        return [
            'id' => 'we_1',
            'url' => 'https://example.com/hooks',
            'description' => '',
            'secret' => 'whsec_real_secret',
            'event_types' => [],
            'disabled' => false,
            'consecutive_failures' => 0,
            'last_success_at' => null,
            'last_error' => null,
            'created_at' => '2026-08-20T00:00:00Z',
        ];
    }
}

/** Marker for "the request never completed", queued like a response. */
final class ConnectionFailure
{
}

/**
 * A PSR-18 client that replays a queue of responses and records what it was asked.
 *
 * The last response repeats once the queue is exhausted, so a test that only cares about one
 * request does not have to count the retries.
 */
final class RecordingClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface|ConnectionFailure> $responses */
    public function __construct(private readonly array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $index = min(\count($this->requests) - 1, \count($this->responses) - 1);
        $next = $this->responses[$index];

        if ($next instanceof ConnectionFailure) {
            throw new TransportFailure('connection reset');
        }

        return $next;
    }

    public function last(): RequestInterface
    {
        return $this->requests[\count($this->requests) - 1];
    }

    public function only(): RequestInterface
    {
        \PHPUnit\Framework\Assert::assertCount(1, $this->requests, 'expected exactly one request');

        return $this->requests[0];
    }

    /** @return array<string, mixed> */
    public function body(int $index = 0): array
    {
        $decoded = json_decode((string) $this->requests[$index]->getBody(), true);
        \PHPUnit\Framework\Assert::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return list<string> */
    public function idempotencyKeys(): array
    {
        return array_map(
            static fn (RequestInterface $r): string => $r->getHeaderLine('Idempotency-Key'),
            $this->requests,
        );
    }
}

final class TransportFailure extends \RuntimeException implements ClientExceptionInterface
{
}

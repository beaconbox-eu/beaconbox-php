<?php

declare(strict_types=1);

namespace BeaconBox\Tests;

use BeaconBox\BeaconBoxClient;
use BeaconBox\Exception\ApiConnectionException;
use BeaconBox\Exception\InvalidRequestException;
use BeaconBox\Model\MessagePush;
use BeaconBox\RetryPolicy;
use BeaconBox\Tests\Support\ConnectionFailure;
use BeaconBox\Tests\Support\Fake;
use BeaconBox\Tests\Support\RecordingLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * The logging contract a library owes its host application.
 *
 * PSR-3 and opt-in: pass a logger and you get output, pass nothing and the SDK is silent. A
 * library that writes to a file or to stderr of its own accord is a library fighting the host
 * application's logging.
 *
 * These tests exist because every one of them is invisible when broken. An SDK that logs a
 * recipient's email address does not fail any other test, it just does damage in somebody else's
 * production logs.
 */
final class LoggingTest extends TestCase
{
    private function push(): MessagePush
    {
        return new MessagePush('buyer@example.com', 'Your order has shipped', 'Tracking XY123456789EE.');
    }

    /**
     * @param list<\Psr\Http\Message\ResponseInterface|ConnectionFailure> $responses
     *
     * @return array{0: BeaconBoxClient, 1: RecordingLogger}
     */
    private function clientWithLogger(array $responses, ?RetryPolicy $retryPolicy = null): array
    {
        $logger = new RecordingLogger();
        $http = new \BeaconBox\Tests\Support\RecordingClient($responses);
        $psr17 = new Psr17Factory();

        $client = new BeaconBoxClient(
            Fake::API_KEY,
            Fake::BASE_URL,
            5_000,
            $retryPolicy ?? new RetryPolicy(maxRetries: 0, baseDelayMs: 0, maxDelayMs: 0),
            $http,
            $psr17,
            $psr17,
            null,
            $logger,
        );

        return [$client, $logger];
    }

    // --- Hygiene -------------------------------------------------------------------------

    public function testWithoutALoggerNothingIsLogged(): void
    {
        // The only acceptable default for a library. Asserted structurally: the constructor
        // installs a NullLogger rather than reaching for a concrete one.
        [$client] = Fake::client([Fake::json(201, Fake::pushResult())]);

        $result = $client->messages->push($this->push());

        self::assertSame('m_8sKq2Vd1', $result->id);
    }

    public function testTheDefaultLoggerIsANullLogger(): void
    {
        $transport = (new \ReflectionClass(\BeaconBox\Transport::class))
            ->newInstanceArgs([Fake::API_KEY, Fake::BASE_URL]);
        $logger = (new \ReflectionProperty(\BeaconBox\Transport::class, 'logger'))->getValue($transport);

        self::assertInstanceOf(NullLogger::class, $logger);
    }

    // --- Nothing sensitive is logged ------------------------------------------------------

    public function testASuccessfulPushLogsNoSecretAndNoPersonalData(): void
    {
        // A log line is copied to an aggregator, retained for months, and read by people who were
        // never meant to see a customer's address or a live credential.
        [$client, $logger] = $this->clientWithLogger([Fake::json(201, Fake::pushResult())]);

        $client->messages->push($this->push());

        $blob = $logger->everything();
        self::assertStringNotContainsString(Fake::API_KEY, $blob);
        self::assertStringNotContainsString('Bearer', $blob);
        self::assertStringNotContainsString('buyer@example.com', $blob, 'an address reached a log line');
        self::assertStringNotContainsString('Tracking XY123456789EE', $blob, 'a body reached a log line');
    }

    public function testAPathContainingAnEmailIsLoggedAsATemplate(): void
    {
        // `/recipients/buyer@example.com/sms` is personal data in a URL.
        [$client, $logger] = $this->clientWithLogger([Fake::json(200, [
            'recipient_email' => 'buyer@example.com',
            'phone' => null,
            'sms_status' => 'none',
            'sms_status_at' => null,
        ])]);

        $client->recipients->sms('buyer@example.com');

        self::assertStringNotContainsString('buyer@example.com', $logger->everything());
        self::assertStringContainsString('/recipients/{email}/sms', $logger->everything());
    }

    public function testAQueryStringIsNeverLogged(): void
    {
        // `?recipient_email=` is the other place an address hides.
        [$client, $logger] = $this->clientWithLogger([
            Fake::json(200, ['items' => [], 'next_cursor' => null]),
        ]);

        $client->messages->list('buyer@example.com');

        self::assertStringNotContainsString('buyer@example.com', $logger->everything());
    }

    public function testAnErrorBodyIsNotLogged(): void
    {
        // The exception carries the body to the caller. A log line does not need a second copy,
        // and error bodies can echo input.
        [$client, $logger] = $this->clientWithLogger([
            Fake::json(422, ['error_code' => 'common.validation_failed', 'detail' => 'buyer@example.com']),
        ]);

        try {
            $client->messages->push($this->push());
            self::fail('expected InvalidRequestException');
        } catch (InvalidRequestException) {
            self::assertStringNotContainsString('buyer@example.com', $logger->everything());
        }
    }

    // --- What is logged -------------------------------------------------------------------

    public function testASuccessfulCallOnlyLogsAtDebug(): void
    {
        // An SDK that chatters at info is an SDK whose users filter it out, warnings included.
        [$client, $logger] = $this->clientWithLogger([Fake::json(201, Fake::pushResult())]);

        $client->messages->push($this->push());

        self::assertSame([LogLevel::DEBUG, LogLevel::DEBUG], $logger->levels());
    }

    public function testDebugRecordsTheRequestAndTheResponse(): void
    {
        [$client, $logger] = $this->clientWithLogger([Fake::json(201, Fake::pushResult())]);

        $client->messages->push($this->push());

        self::assertStringContainsString('BeaconBox request POST /messages', $logger->messages()[0]);
        self::assertStringContainsString('BeaconBox response POST /messages 201', $logger->messages()[1]);
    }

    public function testARetryWarnsWithTheReasonAndTheBackoff(): void
    {
        // A retry means something went wrong, and it is what explains a job taking four seconds
        // instead of one.
        [$client, $logger] = $this->clientWithLogger(
            [Fake::json(503), Fake::json(201, Fake::pushResult())],
            new RetryPolicy(maxRetries: 1, baseDelayMs: 0, maxDelayMs: 0),
        );

        $client->messages->push($this->push());

        $warnings = $logger->messagesAt(LogLevel::WARNING);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('retrying POST /messages', $warnings[0]);
        self::assertStringContainsString('HTTP 503', $warnings[0]);
    }

    public function testTheRetryLogNamesTheKeyItReused(): void
    {
        // So an operator reading the logs can see the retry reused the key. A retry that minted a
        // fresh one would be a duplicate message, and this is where that is visible.
        [$client, $logger] = $this->clientWithLogger(
            [Fake::json(503), Fake::json(201, Fake::pushResult())],
            new RetryPolicy(maxRetries: 1, baseDelayMs: 0, maxDelayMs: 0),
        );

        $client->messages->push($this->push(), 'order-4711-shipped');

        $context = $logger->contextAt(LogLevel::WARNING)[0];
        self::assertSame('order-4711-shipped', $context['idempotency_key']);
    }

    public function testGivingUpWarnsOnce(): void
    {
        [$client, $logger] = $this->clientWithLogger(
            [new ConnectionFailure()],
            new RetryPolicy(maxRetries: 1, baseDelayMs: 0, maxDelayMs: 0),
        );

        try {
            $client->messages->push($this->push());
            self::fail('expected ApiConnectionException');
        } catch (ApiConnectionException) {
            $warnings = $logger->messagesAt(LogLevel::WARNING);
            $givingUp = array_filter($warnings, static fn (string $m): bool => str_contains($m, 'giving up'));
            self::assertCount(1, $givingUp);
        }
    }
}

<?php

declare(strict_types=1);

namespace BeaconBox;

use BeaconBox\Resource\Credits;
use BeaconBox\Resource\Keys;
use BeaconBox\Resource\Messages;
use BeaconBox\Resource\Recipients;
use BeaconBox\Resource\WebhookEndpoints;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The BeaconBox API, as five resources.
 *
 * ```php
 * $client = new BeaconBoxClient(getenv('BEACONBOX_API_KEY'));
 *
 * $result = $client->messages->push(new MessagePush(
 *     recipientEmail: 'buyer@example.com',
 *     subject: 'Your order has shipped',
 *     body: 'Tracking XY123456789EE.',
 * ));
 *
 * echo $result->id;
 * ```
 *
 * Two things this client does that are worth knowing before you use it:
 *
 * - **it supplies the `Idempotency-Key` every write requires**, and reuses it across its own
 *   retries, so a dropped connection cannot become a duplicate message to a real customer;
 * - **it does not throw on a skipped channel.** A push whose SMS was skipped for an empty balance
 *   is a *successful* push: the update is in the inbox and the email went. Read
 *   `$result->sms?->skippedReason`.
 *
 * Reuse one client. The bundled curl transport keeps a persistent handle for connection reuse,
 * which is also why it is not safe to share across coroutines: inject a PSR-18 client if you run
 * Swoole or ReactPHP.
 *
 * @param string|null $baseUrl Plain http is refused for anything but localhost, so a
 *                             misconfiguration cannot put your key on the wire in clear.
 * @param RetryPolicy|null $retryPolicy Pass `new RetryPolicy(maxRetries: 0)` inside a job runner
 *                             that already owns its own retry schedule, so the two do not
 *                             multiply.
 * @param string|null $caBundle Path to a PEM bundle, for a corporate TLS-inspecting proxy or a
 *                             staging deployment behind a private CA. There is no option to turn
 *                             verification off, because there is no legitimate production reason
 *                             to: bring the right certificate instead.
 * @param LoggerInterface|null $logger Any PSR-3 logger (Monolog, your framework's). Without one
 *                             the SDK logs nothing at all, which is the only acceptable default
 *                             for a library. Requests and responses are `debug`, retries are
 *                             `warning`, and nothing else is ever emitted. The API key, headers,
 *                             bodies, query strings and interpolated paths are never logged: see
 *                             `Transport::logContext()`.
 */
final class BeaconBoxClient
{
    public readonly Messages $messages;
    public readonly Credits $credits;
    public readonly Recipients $recipients;
    public readonly Keys $keys;
    public readonly WebhookEndpoints $webhookEndpoints;

    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        int $timeoutMs = Transport::DEFAULT_TIMEOUT_MS,
        ?RetryPolicy $retryPolicy = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?string $caBundle = null,
        ?LoggerInterface $logger = null,
    ) {
        $transport = new Transport(
            $apiKey,
            $baseUrl,
            $timeoutMs,
            $retryPolicy,
            $httpClient,
            $requestFactory,
            $streamFactory,
            $caBundle,
            $logger,
        );

        $this->messages = new Messages($transport);
        $this->credits = new Credits($transport);
        $this->recipients = new Recipients($transport);
        $this->keys = new Keys($transport);
        $this->webhookEndpoints = new WebhookEndpoints($transport);
    }
}

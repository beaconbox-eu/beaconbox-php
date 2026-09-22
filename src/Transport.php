<?php

declare(strict_types=1);

namespace BeaconBox;

use BeaconBox\Exception\ApiConnectionException;
use BeaconBox\Exception\ApiException;
use BeaconBox\Exception\AuthenticationException;
use BeaconBox\Exception\ConflictException;
use BeaconBox\Exception\InvalidRequestException;
use BeaconBox\Exception\PermissionException;
use BeaconBox\Exception\RateLimitException;
use BeaconBox\Exception\ResourceMissingException;
use BeaconBox\Exception\ServerException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * HTTP transport: auth, idempotency, retries, and error mapping.
 *
 * By default it uses a bundled curl client, so `composer require beaconbox/beaconbox` adds **no**
 * runtime dependencies beyond ext-curl and ext-json. A library that drags an HTTP stack into a
 * host application is a library that eventually conflicts with it. Inject any PSR-18 client plus
 * PSR-17 factories for full control (proxies, custom TLS, connection pooling, test doubles).
 *
 * **Logging is PSR-3 and opt-in.** Pass a `LoggerInterface` to see requests, responses and
 * retries; without one nothing is logged, because a library that writes to a file or to stderr of
 * its own accord is a library fighting the host application's logging. Nothing sensitive is ever
 * logged: see {@see self::logContext()}.
 *
 * **The idempotency key is generated here, once per logical call**, and reused for every retry of
 * that call. That pairing is the whole reason retries are safe: a connection error means the
 * answer was lost, not that the work was not done, and a fresh key on the second attempt would
 * store a second message and charge a second credit. A caller may supply their own key and should
 * whenever they have a natural one.
 */
final class Transport
{
    public const DEFAULT_BASE_URL = 'https://api.beaconbox.eu';
    public const DEFAULT_TIMEOUT_MS = 30_000;

    private const DEFAULT_CONNECT_TIMEOUT_MS = 10_000;
    private const API_PREFIX = '/api/v1';

    /** Plain http is permitted only to these, where nothing leaves the machine. */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    private readonly string $baseUrl;
    private readonly RetryPolicy $retryPolicy;
    private readonly LoggerInterface $logger;
    /**
     * Whether anything is listening.
     *
     * PSR-3 has no `isEnabledFor`, so without this the SDK would `sprintf` a message and build a
     * context array on every single request only for a `NullLogger` to discard them. That is the
     * default path, so it is the one that has to cost nothing.
     */
    private readonly bool $logging;
    private ?\CurlHandle $curlHandle = null;

    public function __construct(
        private readonly string $apiKey,
        ?string $baseUrl = null,
        private readonly int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        ?RetryPolicy $retryPolicy = null,
        private readonly ?ClientInterface $httpClient = null,
        private readonly ?RequestFactoryInterface $requestFactory = null,
        private readonly ?StreamFactoryInterface $streamFactory = null,
        private readonly ?string $caBundle = null,
        ?LoggerInterface $logger = null,
    ) {
        if ($apiKey === '' || trim($apiKey) === '') {
            throw new \InvalidArgumentException('BeaconBox: an API key is required.');
        }
        $this->baseUrl = self::validateBaseUrl($baseUrl ?? self::DEFAULT_BASE_URL);
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy();
        // NullLogger, never a concrete one. The default has to be silence.
        $this->logger = $logger ?? new NullLogger();
        $this->logging = !($this->logger instanceof NullLogger);

        if ($httpClient !== null && ($requestFactory === null || $streamFactory === null)) {
            throw new \InvalidArgumentException(
                'BeaconBox: injecting a PSR-18 client also requires PSR-17 request + stream factories '
                . '(e.g. nyholm/psr7 or php-http/discovery).',
            );
        }
        if ($httpClient === null && !\extension_loaded('curl')) {
            throw new \RuntimeException(
                'BeaconBox: the curl extension is required unless you inject a PSR-18 client.',
            );
        }
    }

    /**
     * Never the key. A transport ends up inside a client, and a client ends up in a stack trace.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => '<redacted>'];
    }

    /**
     * Run one logical call to a final answer, or throw.
     *
     * The key is minted before the loop and the URL and headers built once, which is what makes
     * every attempt after the first a genuine *retry* rather than a second push.
     *
     * @param array<string, mixed>|null  $body
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        ?string $idempotencyKey = null,
        ?string $route = null,
    ): array {
        // A templated, log-safe name. `$path` cannot be logged: a recipient's email address is
        // substituted straight into it, so logging it would write personal data into a merchant's
        // log aggregator on every request.
        $logName = strtoupper($method) . ' ' . ($route ?? $path);
        $url = $this->baseUrl . self::API_PREFIX . $path;
        $filtered = array_filter($query, static fn ($value) => $value !== null);
        if ($filtered !== []) {
            $url .= '?' . http_build_query($filtered);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'beaconbox-php/' . Version::VERSION . ' php/' . PHP_VERSION,
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        // Minted once, outside the retry loop. See the class docblock: reusing it is what makes a
        // retry a retry rather than a second push.
        if ($this->requiresIdempotency($method)) {
            $headers['Idempotency-Key'] = $idempotencyKey ?? IdempotencyKey::generate();
        }

        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);

        $attempt = 0;
        while (true) {
            if ($this->logging) {
                $this->logger->debug(
                    sprintf('BeaconBox request %s (attempt %d)', $logName, $attempt + 1),
                    $this->logContext($logName, $attempt, $headers),
                );
            }
            $started = microtime(true);

            try {
                [$status, $responseBody, $responseHeaders] = $this->send($method, $url, $headers, $payload);
            } catch (ApiConnectionException $exception) {
                if (!$this->retryPolicy->shouldRetry(null, $attempt)) {
                    // Logged *and* thrown, which is usually double reporting and here is not: the
                    // caller sees an exception saying the answer was lost, and the operator needs
                    // the same fact correlated with the retries above it.
                    $this->logger->warning(
                        sprintf('BeaconBox giving up on %s after %d attempt(s)', $logName, $attempt + 1),
                        $this->logContext($logName, $attempt, $headers),
                    );

                    throw $exception;
                }
                $delay = $this->retryPolicy->delayMs($attempt);
                $this->logRetry($logName, $attempt, 'connection failure', $delay, $headers);
                $this->sleep($delay);
                ++$attempt;
                continue;
            }

            if ($this->logging) {
                $this->logger->debug(
                    sprintf(
                        'BeaconBox response %s %d in %dms',
                        $logName,
                        $status,
                        (int) round((microtime(true) - $started) * 1000),
                    ),
                    $this->logContext($logName, $attempt, $headers) + ['status_code' => $status],
                );
            }

            if ($status < 400) {
                return $this->decode($responseBody);
            }
            // Read once and pass to both: the decision to retry now depends on how long the server
            // asked for, not only on the status. See RetryPolicy::shouldRetry().
            $retryAfterMs = self::retryAfterMs($responseHeaders);
            if ($this->retryPolicy->shouldRetry($status, $attempt, $retryAfterMs)) {
                $delay = $this->retryPolicy->delayMs($attempt, $retryAfterMs);
                $this->logRetry($logName, $attempt, 'HTTP ' . $status, $delay, $headers);
                $this->sleep($delay);
                ++$attempt;
                continue;
            }
            throw $this->toException($status, $responseBody, $responseHeaders);
        }
    }

    /**
     * Which verbs carry an idempotency key.
     *
     * Every state-changing call in this API requires one: pushes, resends, retractions, erasures.
     * `PUT`/`DELETE` on a recipient's phone do not, because they are idempotent by construction.
     * Setting a number twice leaves one number. Sending a message twice sends two.
     */
    private function requiresIdempotency(string $method): bool
    {
        return strtoupper($method) === 'POST';
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function send(string $method, string $url, array $headers, ?string $payload): array
    {
        return $this->httpClient !== null
            ? $this->sendPsr($method, $url, $headers, $payload)
            : $this->sendCurl($method, $url, $headers, $payload);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function sendPsr(string $method, string $url, array $headers, ?string $payload): array
    {
        \assert($this->httpClient !== null && $this->requestFactory !== null && $this->streamFactory !== null);

        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($payload !== null) {
            $request = $request->withBody($this->streamFactory->createStream($payload));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new ApiConnectionException(self::connectionMessage($exception->getMessage()), 0, $exception);
        }

        $responseHeaders = [];
        foreach (array_keys($response->getHeaders()) as $name) {
            $responseHeaders[(string) $name] = $response->getHeaderLine((string) $name);
        }

        return [$response->getStatusCode(), (string) $response->getBody(), $responseHeaders];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function sendCurl(string $method, string $url, array $headers, ?string $payload): array
    {
        // One persistent handle for the transport's lifetime, so repeated calls reuse the TLS
        // connection. The cost is that a Transport is not safe to share across coroutines, so
        // inject a PSR-18 client if you run Swoole or ReactPHP.
        $handle = $this->curlHandle ??= curl_init();
        curl_reset($handle);

        $responseHeaders = [];
        curl_setopt_array($handle, [
            // Both are non-empty by construction (the base URL is validated in the constructor and
            // the method is a literal at every call site) but the curl stubs type them as
            // `non-empty-string`, and an assertion is cheaper than a suppression nobody revisits.
            CURLOPT_URL => $url === '' ? throw new \LogicException('empty URL') : $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method) ?: 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min(self::DEFAULT_CONNECT_TIMEOUT_MS, $this->timeoutMs),
            // Never follow one. curl would re-send the Authorization header to wherever the
            // redirect points, so a compromised or misconfigured hop could harvest a live API key.
            // BeaconBox never redirects, so anything that does is not BeaconBox.
            CURLOPT_FOLLOWLOCATION => false,
            // Belt and braces: these are curl's defaults, and a php.ini or a system curl config
            // that has turned them off would otherwise disable certificate verification silently.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $name, string $value): string => $name . ': ' . $value,
                array_keys($headers),
                array_values($headers),
            ),
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $pair = explode(':', $line, 2);
                if (\count($pair) === 2) {
                    $responseHeaders[strtolower(trim($pair[0]))] = trim($pair[1]);
                }

                return \strlen($line);
            },
        ]);
        if ($this->caBundle !== null && $this->caBundle !== '') {
            // A private CA, not a way around verification: VERIFYPEER stays on above.
            curl_setopt($handle, CURLOPT_CAINFO, $this->caBundle);
        }
        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($handle);
        if ($response === false) {
            throw new ApiConnectionException(self::connectionMessage(curl_error($handle)));
        }

        /** @var array<string, string> $responseHeaders */
        return [(int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), (string) $response, $responseHeaders];
    }

    /**
     * The message says what is and is not known, because the distinction is the whole point.
     *
     * A caller who reads this as "it did not send" and pushes again with a fresh key has sent the
     * customer two emails.
     */
    private static function connectionMessage(string $detail): string
    {
        return 'BeaconBox: the request did not complete (' . $detail . '). '
            . 'This is not proof the work did not happen: retry with the same idempotency key.';
    }

    /**
     * The allow-list for log context.
     *
     * Deliberately an allow-list rather than a redaction pass. Redaction is a list of things
     * somebody remembered to hide, and the field added next year is not on it. This way a value
     * has to be named here to ever reach a log line.
     *
     * **What is never logged, and why:** the API key, or any header (`Authorization` is a header);
     * the request or response body (a message body is text written to a named customer); the query
     * string (`?recipient_email=`); and the interpolated URL path
     * (`/recipients/buyer@example.com/sms`). The templated route is logged instead.
     *
     * The idempotency key *is* logged, because it is a random value identifying one attempt, and
     * correlating retries without it is guesswork.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function logContext(string $logName, int $attempt, array $headers): array
    {
        return [
            'route' => $logName,
            'attempt' => $attempt + 1,
            'idempotency_key' => $headers['Idempotency-Key'] ?? null,
        ];
    }

    /**
     * A warning rather than a debug: a retry means something went wrong, and it is the signal that
     * explains why a merchant's job took four seconds instead of one.
     *
     * It names the idempotency key so a reader can see the retry reused it. A retry that minted a
     * fresh key would be a duplicate message, and this line is where that would be visible.
     *
     * @param array<string, string> $headers
     */
    private function logRetry(string $logName, int $attempt, string $reason, int $delayMs, array $headers): void
    {
        $this->logger->warning(
            sprintf(
                'BeaconBox retrying %s after %s, attempt %d in %dms',
                $logName,
                $reason,
                $attempt + 2,
                $delayMs,
            ),
            $this->logContext($logName, $attempt, $headers) + [
                'reason' => $reason,
                'retry_in_ms' => $delayMs,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        if (trim($body) === '') {
            return []; // 204, or a retraction that changed nothing and still answers
        }
        $decoded = json_decode($body, true);

        /** @var array<string, mixed> */
        return \is_array($decoded) ? $decoded : ['raw' => $body];
    }

    /**
     * `Retry-After` in milliseconds, when the server sent a sane one.
     *
     * Only the delta-seconds form is honoured. The HTTP-date form is legal and essentially never
     * used by an API, and parsing it would mean trusting the caller's clock to agree with the
     * server's, which is the assumption that makes it worse than our own backoff.
     *
     * @param array<string, string> $headers
     */
    private static function retryAfterMs(array $headers): ?int
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) !== 'retry-after') {
                continue;
            }
            $trimmed = trim($value);

            return ctype_digit($trimmed) ? ((int) $trimmed) * 1000 : null;
        }

        return null;
    }

    /** @param array<string, string> $headers */
    private function toException(int $status, string $body, array $headers): ApiException
    {
        $decoded = $this->decode($body);
        $code = \is_string($decoded['error_code'] ?? null) ? $decoded['error_code'] : null;
        $detail = \is_string($decoded['detail'] ?? null) ? $decoded['detail'] : null;
        $message = sprintf('BeaconBox: %s (HTTP %d)', $detail ?? $code ?? 'request failed', $status);

        $requestId = null;
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-request-id') {
                $requestId = $value;
                break;
            }
        }

        $class = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => ResourceMissingException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => InvalidRequestException::class,
        };

        return new $class($message, $status, $code, $decoded, $requestId, self::retryAfterMs($headers));
    }

    /**
     * Reject a base URL that would put an API key on the wire in clear.
     *
     * `http` is allowed only for a loopback host, which is what the local development stack and
     * this SDK's own live tests run against. Anywhere else it means the bearer token, the
     * recipient's email address and the body of the message are readable by anything on the path,
     * and an SDK that shrugs at that is the reason it happens in production.
     */
    private static function validateBaseUrl(string $baseUrl): string
    {
        $cleaned = rtrim(trim($baseUrl), '/');
        $parts = parse_url($cleaned);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = \is_array($parts) ? ($parts['host'] ?? null) : null;

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException(
                sprintf('BeaconBox: base_url must be http or https, got "%s".', $baseUrl),
            );
        }
        if ($host === null || $host === '') {
            throw new \InvalidArgumentException(
                sprintf('BeaconBox: base_url must include a host, got "%s".', $baseUrl),
            );
        }
        if ($scheme === 'http' && !\in_array(trim($host, '[]'), self::LOCAL_HOSTS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'BeaconBox: refusing to send an API key over plain http to "%s". '
                . 'Use https (http is permitted for localhost only).',
                $host,
            ));
        }

        return $cleaned;
    }

    private function sleep(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}

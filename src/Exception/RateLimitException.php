<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/**
 * 429. Too many attempts, and the SDK has already retried and backed off.
 *
 * **Check `retryAfterMs`.** A wait longer than the policy's `maxRetryAfterMs` is reported here
 * rather than slept through, so this can arrive milliseconds after the call started with the
 * server's own answer to "when should I come back" sitting on it.
 *
 * `webhook.test_rate_limited` is the per-business limit on `$client->webhookEndpoints->test()`:
 * each press is a request to your server.
 */
final class RateLimitException extends ApiException
{
}

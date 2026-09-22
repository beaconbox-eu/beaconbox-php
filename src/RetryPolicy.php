<?php

declare(strict_types=1);

namespace BeaconBox;

/**
 * When to try again, and how long to wait.
 *
 * **Retrying a write is only safe because every write carries an `Idempotency-Key`**, and the
 * transport reuses *the same key* across attempts. See {@see Transport::request()}. Without that
 * pairing this class would be a duplicate-message generator: the one failure it exists to survive,
 * a connection that died with the answer in flight, is exactly the failure where the server may
 * already have stored the message and charged the credit.
 *
 * `new RetryPolicy(maxRetries: 0)` disables retries entirely, which is the right setting inside a
 * job runner that already owns its own retry schedule and would otherwise multiply the two.
 */
final class RetryPolicy
{
    /**
     * How much jitter to add on top of a server-directed wait, as a fraction of it.
     *
     * See {@see self::delayMs()}. Added *on top* rather than sampled from zero, because
     * `Retry-After` is a floor the server set and waiting less than it is guaranteed to 429 again.
     */
    public const RETRY_AFTER_SPREAD = 0.2;

    /**
     * @param int $maxRetryAfterMs The longest server-directed wait this policy will sit through.
     *                             Deliberately much larger than `$maxDelayMs`, because the two
     *                             answer different questions: `$maxDelayMs` caps a delay *the SDK
     *                             invented* and should stay small, while this caps one **the server
     *                             asked for**, and capping that at two seconds means ignoring the
     *                             only accurate information anybody has about when the service will
     *                             be ready. Beyond it the policy stops retrying rather than
     *                             retrying early — see {@see self::shouldRetry()}. Lower it (or set
     *                             `maxRetries: 0`) if a blocked worker is worse for you than a
     *                             failed call; a single call can sleep roughly `$maxRetries` times
     *                             this.
     */
    public function __construct(
        public readonly int $maxRetries = 2,
        public readonly int $baseDelayMs = 200,
        public readonly int $maxDelayMs = 2_000,
        public readonly int $maxRetryAfterMs = 30_000,
    ) {
    }

    /**
     * Retry a connection failure, a 429 and a 5xx, and nothing else.
     *
     * A 4xx is the server saying the request is wrong. Sending it again unchanged asks the same
     * question and burns the caller's time on the same answer. 409 is deliberately *not* retried
     * even though it can mean "still in flight": the honest answer to a caller is that their
     * earlier attempt is still running, not a client that quietly blocks for a second.
     *
     * `$statusCode = null` means no response arrived at all.
     *
     * **A `Retry-After` longer than `$maxRetryAfterMs` stops the retry rather than shortening it.**
     * Retrying before the moment the server named is not a compromise, it is a request guaranteed
     * to be refused: the worker blocks for the cap, fails anyway, and the rate-limited service
     * absorbs another pointless call on the way. Failing immediately hands the caller a
     * {@see \BeaconBox\Exception\RateLimitException} with `retryAfterMs` on it, which is the number
     * they need to schedule a real retry.
     */
    public function shouldRetry(?int $statusCode, int $attempt, ?int $retryAfterMs = null): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }
        if ($statusCode === null) {
            return true;
        }
        if ($statusCode !== 429 && $statusCode < 500) {
            return false;
        }

        return $retryAfterMs === null || $retryAfterMs <= $this->maxRetryAfterMs;
    }

    /**
     * Milliseconds to wait before attempt `$attempt + 1`.
     *
     * Exponential backoff with full jitter. Jittered because the failure mode this guards against
     * is synchronised: a provider blip makes every one of a merchant's workers retry, and
     * unjittered backoff makes them do it in lockstep, a thundering herd that arrives precisely
     * when the service is least able to answer.
     *
     * **A `Retry-After` is honoured in full, and jittered on top of itself rather than within
     * itself.** The herd problem is at its worst here and not at its mildest: every worker that hit
     * the same 429 was handed the *same number*, so obeying it exactly reconstructs the lockstep
     * this class exists to break — but sampling from zero would wait less than the server asked
     * for, which only earns another 429. So the wait is the directive plus up to
     * {@see self::RETRY_AFTER_SPREAD} of it, bounded by `$maxDelayMs` so the spread stays a spread.
     */
    public function delayMs(int $attempt, ?int $retryAfterMs = null): int
    {
        if ($retryAfterMs !== null && $retryAfterMs > 0) {
            $honoured = min($retryAfterMs, $this->maxRetryAfterMs);
            $spread = (int) min($this->maxDelayMs, $honoured * self::RETRY_AFTER_SPREAD);

            return $honoured + random_int(0, max(1, $spread));
        }

        // `2 ** $attempt` is a float in PHP once it grows, and `random_int` wants ints, so the
        // cast lives here rather than at the call site where it would be easy to drop.
        $ceiling = (int) min($this->maxDelayMs, $this->baseDelayMs * (2 ** $attempt));

        return random_int(0, max(1, $ceiling));
    }
}

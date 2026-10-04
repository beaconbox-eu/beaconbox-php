<?php

declare(strict_types=1);

namespace BeaconBox;

use BeaconBox\Exception\WebhookVerificationException;
use BeaconBox\Model\WebhookEvent;

/**
 * Verify a webhook BeaconBox sent you.
 *
 * The header is `X-BeaconBox-Signature: t=<unix>,v1=<hex>`, an HMAC-SHA256 over `"<t>.<raw body>"`
 * with your endpoint's secret. **The timestamp is inside the signed string**, which is what makes
 * a replay detectable: a captured delivery cannot be re-sent later with a fresh timestamp, because
 * the signature would no longer match.
 *
 * Three rules this helper enforces so you do not have to remember them:
 *
 * 1. **Verify the raw body**, byte for byte. Decoding to an array and re-encoding is how a
 *    signature stops matching over key order or whitespace, and it fails *later*, in production,
 *    on a payload shaped slightly differently from the one you tested with.
 * 2. **Compare in constant time.** A `===` on the hex digest leaks, through timing, how much of a
 *    guess was right.
 * 3. **Reject a stale timestamp.** Without a freshness window, a delivery captured once is valid
 *    forever.
 *
 * What it cannot do for you is deduplicate. A delivery that timed out on your side is retried, so
 * the same `$event->id` can arrive twice. Treat handling as idempotent or keep the ids you have
 * seen.
 */
final class Webhooks
{
    public const SIGNATURE_HEADER = 'X-BeaconBox-Signature';

    /** How far a delivery's timestamp may be from now. Absorbs clock skew and provider retries. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Prove a delivery came from BeaconBox, then decode it.
     *
     * ```php
     * try {
     *     $event = Webhooks::verify(
     *         rawBody: file_get_contents('php://input'),   // the RAW body, byte for byte
     *         signatureHeader: $_SERVER['HTTP_X_BEACONBOX_SIGNATURE'] ?? '',
     *         secret: getenv('BEACONBOX_WEBHOOK_SECRET') ?: '',
     *     );
     * } catch (WebhookVerificationException) {
     *     http_response_code(400);
     *     return;
     * }
     *
     * if ($event->type === WebhookEventType::MessageBounced) {
     *     // ...
     * }
     * ```
     *
     * @param string $rawBody         The raw request body. In a framework that is the PSR-7
     *                                request body as a string, never a decoded array re-encoded.
     * @param string $secret          Your endpoint's signing secret, shown once when the endpoint
     *                                was created. Read it from your environment or secret store,
     *                                never from source.
     * @param int    $toleranceSeconds Freshness window. Widen it only if your clocks are genuinely
     *                                far apart, because every second of it is a second a captured
     *                                delivery stays replayable.
     * @param int|null $now           Override the clock, for tests.
     *
     * @throws WebhookVerificationException when the signature is missing, malformed, stale or wrong
     */
    public static function verify(
        string $rawBody,
        string $signatureHeader,
        string $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null,
    ): WebhookEvent {
        [$timestamp, $signature] = self::parseHeader($signatureHeader);

        $age = abs(($now ?? time()) - $timestamp);
        if ($age > $toleranceSeconds) {
            throw new WebhookVerificationException(
                sprintf(
                    'BeaconBox: webhook timestamp is %d seconds off, outside the %ds tolerance.',
                    $age,
                    $toleranceSeconds,
                ),
            );
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        // `strtolower` on the incoming value, because `hash_hmac` returns lowercase hex while
        // `hash_equals` compares bytes: without it the same signature spelled in uppercase hex
        // would be refused. Hex case carries no meaning, so normalising it rejects nothing real,
        // and the comparison itself stays constant-time.
        if (!hash_equals($expected, strtolower($signature))) {
            // Names the likely cause, because the obvious reading of this error is the wrong one.
            // "Signature does not match" sounds like a key problem, and the overwhelmingly common
            // cause is not: it is that the bytes checked are not the bytes sent. Any
            // parse-and-re-serialize — a framework handing over decoded JSON, a logging proxy, a
            // copy out of a webhook inspector — reorders keys or re-spaces the JSON and changes
            // the digest completely, while leaving a body that still looks identical to read.
            throw new WebhookVerificationException(
                'BeaconBox: webhook signature does not match. The most common cause is verifying '
                . 'against a re-serialized body rather than the raw request bytes — if you decoded '
                . 'the JSON first, or copied the body out of a tool that pretty-prints it, verify '
                . 'the original bytes instead. Failing that, check the secret belongs to this '
                . 'endpoint.',
            );
        }

        $decoded = json_decode($rawBody, true);
        // A JSON *array* decodes to a PHP array too, so `is_array` alone would wave `[1,2,3]`
        // through to `fromArray` and hand the caller an event with every field null rather than an
        // exception. Only reachable from a body that already passed the HMAC above.
        //
        // The `!== []` is load-bearing: `json_decode('{}', true)` also gives `[]`, and
        // `array_is_list([])` is true, so without it a legitimately empty object would be refused.
        // An empty object and an empty array are genuinely indistinguishable after an associative
        // decode, and both produce the same nulled event, so the ambiguity costs nothing.
        if (!\is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new WebhookVerificationException('BeaconBox: webhook body is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return WebhookEvent::fromArray($decoded);
    }

    /**
     * `t=<unix>,v1=<hex>` into its parts.
     *
     * Tolerant of extra comma-separated pairs so that a future `v2=` scheme can be added without
     * every deployed verifier rejecting the delivery outright.
     *
     * @return array{0: int, 1: string}
     */
    private static function parseHeader(string $header): array
    {
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            $pair = explode('=', trim($piece), 2);
            if (\count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        $raw = $parts['t'] ?? '';
        // Between one and twenty digits. `ctype_digit` alone accepted a megabyte of them and left
        // `(int)` to saturate silently at PHP_INT_MAX, and twenty digits is already far past any
        // Unix timestamp.
        //
        // `\z`, not `$`: PCRE's `$` also matches *before* a trailing newline, so `123\n` would
        // pass. `trim()` above happens to remove one today, which makes the pattern's correctness
        // depend on that rather than state it. In a signature path the pattern carries the rule.
        $timestamp = preg_match('/^[0-9]{1,20}\z/', $raw) === 1 ? (int) $raw : 0;
        $signature = $parts['v1'] ?? '';

        if ($timestamp <= 0 || $signature === '') {
            throw new WebhookVerificationException(
                'BeaconBox: malformed ' . self::SIGNATURE_HEADER . " header (expected 't=<unix>,v1=<hex>').",
            );
        }

        return [$timestamp, $signature];
    }
}

<?php

declare(strict_types=1);

namespace BeaconBox;

/**
 * Generates the `Idempotency-Key` every BeaconBox write requires.
 *
 * **This is the single most useful thing this SDK does.** The API declares the header as a required
 * OpenAPI parameter precisely so that a generated client cannot forget it, and forgetting it is
 * not a 400 you notice in development, it is a duplicate order-update email and a duplicate charged
 * SMS to a real customer, six months later, the first time your network hiccups.
 *
 * A caller may always pass their own, and should, if they have a natural key for the operation
 * (an order id, a job id). What they must never do is generate a fresh one *per attempt*, which is
 * why {@see Transport} threads one key through every retry of the same call.
 */
final class IdempotencyKey
{
    /** A RFC 4122 v4 UUID from the best randomness available. */
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

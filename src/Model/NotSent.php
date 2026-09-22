<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * Present when BeaconBox decided not to email a message at all, and that decision still stands.
 *
 * **The field that makes `delivered === false` readable.** Without it that flag meant two opposite
 * things — *on its way* and *never attempted, and never will be* — so a caller polling for
 * delivery had no way to stop waiting. Null is the ordinary case.
 *
 * `$reason` is a plain string rather than an enum, unlike `SmsDelivery::$skippedReason`'s
 * companion `SkipReason`. The server's list grows whenever a refusal is added to the send path,
 * and an SDK that threw on an unrecognised value would turn a new server-side reason into a
 * client-side crash. Compare against {@see \BeaconBox\Enum\EmailSkipReason} if you want the known
 * ones, and treat anything else as "not sent".
 */
final class NotSent
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $reason,
        public readonly ?\DateTimeImmutable $at = null,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            Parse::string($payload, 'reason'),
            Parse::nullableDatetime($payload, 'at'),
            $payload,
        );
    }
}

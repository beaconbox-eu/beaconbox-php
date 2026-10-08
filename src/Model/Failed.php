<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * Present when an email was attempted and BeaconBox has given up on it.
 *
 * {@see NotSent}'s counterpart at the other end of the send path. That one means BeaconBox decided
 * not to try; this one means it tried, and the email provider refused the message, every retry
 * failed, or the provider's answer was lost (`outcome_unknown`: it may still arrive, and is never
 * sent again). A caller polling for delivery can stop waiting, except on `outcome_unknown`: a
 * later delivery clears this back to null and shows as delivered, whatever its timestamp. Null
 * is the ordinary case.
 *
 * `$reason` is a plain string for the same reason `NotSent::$reason` is. Compare against
 * {@see \BeaconBox\Enum\EmailFailureReason} for the known values and treat anything else as
 * "failed".
 */
final class Failed
{
    /**
     * @param \DateTimeImmutable|null $at When the send was given up on.
     * @param array<string, mixed>    $raw
     */
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

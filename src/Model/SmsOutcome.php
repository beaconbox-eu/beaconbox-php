<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * The SMS side of a push, and the *only* way an SMS problem is ever reported.
 *
 * `queued = false` with a `$skippedReason` is a normal, successful outcome. The message is in the
 * recipient's inbox and the email nudge went out regardless. A push never fails because of SMS,
 * so reporting it as a failed request would misdescribe what happened and invite a retry that
 * stores the update twice.
 *
 * `queued = false` with **no** `$skippedReason` and an `$escalatesAt` is a third thing again:
 * nothing was refused and nothing has been charged, the send is simply armed for later.
 */
final class SmsOutcome
{
    /**
     * @param int                 $credits       What this send will cost when a carrier accepts
     *                                           it. Charged at send time, not now.
     * @param string|null         $skippedReason A `SkipReason` value, or null when nothing was
     *                                           refused.
     * @param \DateTimeImmutable|null $escalatesAt Set when `escalateIfUnreadAfterMinutes` armed
     *                                           this channel instead of sending it. At this
     *                                           moment BeaconBox checks whether the recipient has
     *                                           opened the message, and sends only if they have
     *                                           not.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $queued,
        public readonly int $credits,
        public readonly ?string $skippedReason = null,
        public readonly ?\DateTimeImmutable $escalatesAt = null,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            Parse::bool($payload, 'queued'),
            Parse::int($payload, 'credits'),
            Parse::nullableString($payload, 'skipped_reason'),
            Parse::nullableDatetime($payload, 'escalates_at'),
            $payload,
        );
    }
}

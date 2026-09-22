<?php

declare(strict_types=1);

namespace BeaconBox\Model;

use BeaconBox\Enum\ChannelSendStatus;

/** The SMS side of a message's delivery, present only when one was queued. */
final class SmsDelivery
{
    /**
     * @param ChannelSendStatus|string $status
     * @param array<string, mixed>     $raw
     */
    public function __construct(
        public readonly ChannelSendStatus|string $status,
        public readonly int $creditsCharged,
        public readonly ?string $skippedReason = null,
        public readonly ?\DateTimeImmutable $submittedAt = null,
        public readonly ?\DateTimeImmutable $lastStatusAt = null,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        /** @var ChannelSendStatus|string $status */
        $status = Parse::enum(ChannelSendStatus::class, $payload, 'status');

        return new self(
            $status,
            Parse::int($payload, 'credits_charged'),
            Parse::nullableString($payload, 'skipped_reason'),
            Parse::nullableDatetime($payload, 'submitted_at'),
            Parse::nullableDatetime($payload, 'last_status_at'),
            $payload,
        );
    }
}

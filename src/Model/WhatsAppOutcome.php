<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/** The WhatsApp side of a push. Same contract as {@see SmsOutcome}: reported, never thrown. */
final class WhatsAppOutcome
{
    /**
     * @param bool $smsFallback Whether an SMS is armed to follow if this WhatsApp message proves
     *                          undeliverable, or queued at once because WhatsApp cannot carry
     *                          it (`not_reachable`, or `template_not_sendable` because the push
     *                          lacks `reference` or `order_status`, or the reference or
     *                          business name cannot be shown). Either way it is a separate
     *                          send with its own credit: poll the message to see it.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $queued,
        public readonly int $credits,
        public readonly ?string $skippedReason = null,
        public readonly bool $smsFallback = false,
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
            Parse::bool($payload, 'sms_fallback'),
            Parse::nullableDatetime($payload, 'escalates_at'),
            $payload,
        );
    }
}

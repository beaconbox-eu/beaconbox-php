<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * A message's delivery state, derived from its events.
 *
 * `$opened` is the one worth acting on: it is the difference between "we sent it" and "they have
 * it", and it is what `escalateIfUnreadAfterMinutes` waits on.
 */
final class DeliveryStatus
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly bool $delivered,
        public readonly bool $opened,
        public readonly bool $bounced,
        public readonly ?\DateTimeImmutable $deliveredAt = null,
        public readonly ?\DateTimeImmutable $openedAt = null,
        public readonly ?\DateTimeImmutable $bouncedAt = null,
        public readonly ?SmsDelivery $sms = null,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            Parse::bool($payload, 'delivered'),
            Parse::bool($payload, 'opened'),
            Parse::bool($payload, 'bounced'),
            Parse::nullableDatetime($payload, 'delivered_at'),
            Parse::nullableDatetime($payload, 'opened_at'),
            Parse::nullableDatetime($payload, 'bounced_at'),
            isset($payload['sms']) && \is_array($payload['sms'])
                ? SmsDelivery::fromArray($payload['sms'])
                : null,
            $payload,
        );
    }
}

<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * A message's delivery state, derived from its events.
 *
 * `$opened` is the one worth acting on: it is the difference between "we sent it" and "they have
 * it", and it is what `escalateIfUnreadAfterMinutes` waits on.
 *
 * `$notSent` and `$failed` are the ones worth checking *before* you wait for any of them. A
 * non-null `$notSent` means no email was ever attempted and none will be; a non-null `$failed`
 * means one was attempted and BeaconBox has given up on it. Either way stop polling `$delivered`
 * for this message, unless `$failed->reason` is `outcome_unknown`: a delivery can still arrive
 * for that one, and clears `$failed`. See {@see NotSent} and {@see Failed}.
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
        public readonly ?NotSent $notSent = null,
        public readonly ?Failed $failed = null,
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
            isset($payload['not_sent']) && \is_array($payload['not_sent'])
                ? NotSent::fromArray($payload['not_sent'])
                : null,
            isset($payload['failed']) && \is_array($payload['failed'])
                ? Failed::fromArray($payload['failed'])
                : null,
            $payload,
        );
    }
}

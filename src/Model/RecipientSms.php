<?php

declare(strict_types=1);

namespace BeaconBox\Model;

use BeaconBox\Enum\RecipientStatus;

/**
 * A recipient's SMS state.
 *
 * `$phone` is **masked**, for example `+372 •••• 0134`. Reads never return it in full: an API key
 * that leaks must not be usable to dump a phone book.
 */
final class RecipientSms
{
    /**
     * @param RecipientStatus|string $smsStatus
     * @param array<string, mixed>   $raw
     */
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $phone,
        public readonly RecipientStatus|string $smsStatus,
        public readonly ?\DateTimeImmutable $smsStatusAt = null,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        /** @var RecipientStatus|string $status */
        $status = Parse::enum(RecipientStatus::class, $payload, 'sms_status');

        return new self(
            Parse::string($payload, 'recipient_email'),
            Parse::nullableString($payload, 'phone'),
            $status,
            Parse::nullableDatetime($payload, 'sms_status_at'),
            $payload,
        );
    }
}

<?php

declare(strict_types=1);

namespace BeaconBox\Model;

use BeaconBox\Enum\RecipientStatus;
use BeaconBox\Enum\SmsConsentSource;

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
     * @param \DateTimeImmutable|null $smsConsentAt When a number was first accepted for texting
     *                                this recipient. Null for a recipient who has never had one,
     *                                or whose number predates the field. Not moved by a later
     *                                number change, a STOP or a START.
     * @param SmsConsentSource|string|null $smsConsentSource The surface that first accepted it:
     *                                `push`, `api`, `admin` or `inbox`, or null when no consent
     *                                is on record.
     * @param array<string, mixed>   $raw
     */
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $phone,
        public readonly RecipientStatus|string $smsStatus,
        public readonly ?\DateTimeImmutable $smsStatusAt = null,
        public readonly ?\DateTimeImmutable $smsConsentAt = null,
        public readonly SmsConsentSource|string|null $smsConsentSource = null,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        /** @var RecipientStatus|string $status */
        $status = Parse::enum(RecipientStatus::class, $payload, 'sms_status');
        /** @var SmsConsentSource|string|null $source */
        $source = \is_string($payload['sms_consent_source'] ?? null)
            ? Parse::enum(SmsConsentSource::class, $payload, 'sms_consent_source')
            : null;

        return new self(
            Parse::string($payload, 'recipient_email'),
            Parse::nullableString($payload, 'phone'),
            $status,
            Parse::nullableDatetime($payload, 'sms_status_at'),
            Parse::nullableDatetime($payload, 'sms_consent_at'),
            $source,
            $payload,
        );
    }
}

<?php

declare(strict_types=1);

namespace BeaconBox\Model;

use BeaconBox\Enum\MessageKind;
use BeaconBox\Enum\MessageStatus;

/**
 * What a push returns.
 *
 * **Read this object, not the status code.** A push answers 201 even when the SMS or WhatsApp
 * message was not sent, because the update is already in the recipient's inbox and the email has
 * gone. The paid channel reports itself in `$sms` and `$whatsapp`.
 */
final class MessagePushResult
{
    /**
     * @param MessageKind|string   $kind
     * @param MessageStatus|string $status
     * @param bool                 $created      True if a new message was created, false if an
     *                                           existing one was updated in place.
     * @param bool                 $nudged       Whether a nudge was queued. Delivery and bounces
     *                                           arrive later, by webhook.
     * @param \DateTimeImmutable|null $scheduledFor When a deferred nudge will be sent, if
     *                                           `sendAt` held it back.
     * @param SmsOutcome|null      $sms          Present when SMS was in play, queued or
     *                                           explicitly refused. Null when the account has SMS
     *                                           off and this push did not ask for it.
     * @param WhatsAppOptInOutcome|null $whatsappOptIn Present exactly when you sent
     *                                           `whatsappOptInSource`. Top level rather than
     *                                           inside `$whatsapp`, because recording consent is
     *                                           not sending: an account collecting consent ahead
     *                                           of switching the channel on has no `$whatsapp`
     *                                           block at all.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $subject,
        public readonly MessageKind|string $kind,
        public readonly MessageStatus|string $status,
        public readonly bool $created,
        public readonly bool $nudged,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly ?\DateTimeImmutable $scheduledFor = null,
        public readonly ?SmsOutcome $sms = null,
        public readonly int $smsUnits = 0,
        public readonly ?WhatsAppOutcome $whatsapp = null,
        public readonly ?WhatsAppOptInOutcome $whatsappOptIn = null,
        public readonly ?string $reference = null,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        /** @var MessageKind|string $kind */
        $kind = Parse::enum(MessageKind::class, $payload, 'kind');
        /** @var MessageStatus|string $status */
        $status = Parse::enum(MessageStatus::class, $payload, 'status');

        return new self(
            Parse::string($payload, 'id'),
            Parse::string($payload, 'subject'),
            $kind,
            $status,
            Parse::bool($payload, 'created'),
            Parse::bool($payload, 'nudged'),
            Parse::datetime($payload, 'created_at'),
            Parse::datetime($payload, 'updated_at'),
            Parse::nullableDatetime($payload, 'scheduled_for'),
            isset($payload['sms']) && \is_array($payload['sms'])
                ? SmsOutcome::fromArray($payload['sms'])
                : null,
            Parse::int($payload, 'sms_units'),
            isset($payload['whatsapp']) && \is_array($payload['whatsapp'])
                ? WhatsAppOutcome::fromArray($payload['whatsapp'])
                : null,
            isset($payload['whatsapp_opt_in']) && \is_array($payload['whatsapp_opt_in'])
                ? WhatsAppOptInOutcome::fromArray($payload['whatsapp_opt_in'])
                : null,
            Parse::nullableString($payload, 'reference'),
            $payload,
        );
    }
}

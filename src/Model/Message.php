<?php

declare(strict_types=1);

namespace BeaconBox\Model;

use BeaconBox\Enum\MessageKind;
use BeaconBox\Enum\MessageStatus;

/** A message you sent, plus where its delivery got to. */
final class Message
{
    /**
     * @param MessageKind|string   $kind
     * @param MessageStatus|string $status
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $recipientEmail,
        public readonly string $subject,
        public readonly MessageKind|string $kind,
        public readonly MessageStatus|string $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly DeliveryStatus $delivery,
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
            Parse::string($payload, 'recipient_email'),
            Parse::string($payload, 'subject'),
            $kind,
            $status,
            Parse::datetime($payload, 'created_at'),
            Parse::datetime($payload, 'updated_at'),
            DeliveryStatus::fromArray(Parse::object($payload, 'delivery')),
            $payload,
        );
    }
}

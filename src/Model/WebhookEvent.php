<?php

declare(strict_types=1);

namespace BeaconBox\Model;

use BeaconBox\Enum\WebhookEventType;

/**
 * A verified webhook delivery.
 *
 * You only ever get one of these from {@see \BeaconBox\Webhooks::verify()}, so holding one is
 * proof the payload was signed with your endpoint's secret and is not a replay.
 */
final class WebhookEvent
{
    /**
     * @param string $id   The delivery id, also sent as `X-BeaconBox-Delivery`. **Use it to
     *                     deduplicate.** A webhook that timed out on your side is retried, so the
     *                     same event id can arrive twice.
     * @param WebhookEventType|string $type
     * @param array<string, mixed>    $data
     * @param array<string, mixed>    $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly WebhookEventType|string $type,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly array $data,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        /** @var WebhookEventType|string $type */
        $type = Parse::enum(WebhookEventType::class, $payload, 'type');

        return new self(
            Parse::string($payload, 'id'),
            $type,
            Parse::datetime($payload, 'occurred_at'),
            Parse::object($payload, 'data'),
            $payload,
        );
    }
}

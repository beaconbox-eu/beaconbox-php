<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * A registered endpoint. `$secret` is masked here.
 *
 * On the response to a *create* it is the real one, and that is the only time it is ever shown.
 * See {@see \BeaconBox\Resource\WebhookEndpoints::create()}.
 *
 * `$disabled` goes true once enough consecutive deliveries have exhausted their retries. That
 * takes hours of sustained failure, not a blip. Delete and re-create the endpoint once the
 * receiver is healthy, which also rotates the secret.
 */
final class WebhookEndpoint
{
    /**
     * @param list<string>         $eventTypes Empty means every event type, including ones added
     *                                         later.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly string $description,
        public readonly string $secret,
        public readonly array $eventTypes,
        public readonly bool $disabled,
        public readonly int $consecutiveFailures,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $lastSuccessAt = null,
        public readonly ?string $lastError = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Redacted: on a create response `$secret` is live, and this object is the one a caller is
     * most likely to dump while wiring the integration up.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'description' => $this->description,
            'secret' => '<redacted>',
            'eventTypes' => $this->eventTypes,
            'disabled' => $this->disabled,
            'consecutiveFailures' => $this->consecutiveFailures,
            'createdAt' => $this->createdAt,
            'lastSuccessAt' => $this->lastSuccessAt,
            'lastError' => $this->lastError,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            Parse::string($payload, 'id'),
            Parse::string($payload, 'url'),
            Parse::string($payload, 'description'),
            Parse::string($payload, 'secret'),
            Parse::stringList($payload, 'event_types'),
            Parse::bool($payload, 'disabled'),
            Parse::int($payload, 'consecutive_failures'),
            Parse::datetime($payload, 'created_at'),
            Parse::nullableDatetime($payload, 'last_success_at'),
            Parse::nullableString($payload, 'last_error'),
            $payload,
        );
    }
}

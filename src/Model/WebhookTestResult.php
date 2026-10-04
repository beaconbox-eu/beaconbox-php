<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * What a manual test POST to one of your endpoints did.
 *
 * `$delivered` is the field to read. The call itself succeeds whatever your endpoint answered —
 * the failure being reported is your server's, not BeaconBox's, so throwing would describe the
 * wrong hop and leave you debugging the wrong side of the wire.
 */
final class WebhookTestResult
{
    /**
     * @param int|null             $statusCode What your endpoint answered. Null when it could not
     *                                         be reached at all.
     * @param string|null          $error      What went wrong, in words meant for a person.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $delivered,
        public readonly ?int $statusCode = null,
        public readonly ?string $error = null,
        public readonly int $durationMs = 0,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            Parse::bool($payload, 'delivered'),
            Parse::nullableInt($payload, 'status_code'),
            Parse::nullableString($payload, 'error'),
            Parse::int($payload, 'duration_ms'),
            $payload,
        );
    }
}

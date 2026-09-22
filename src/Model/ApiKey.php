<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/** A key as listed. `$masked` is all that is ever shown after creation. */
final class ApiKey
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $masked,
        public readonly \DateTimeImmutable $created,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            Parse::string($payload, 'id'),
            Parse::string($payload, 'name'),
            Parse::string($payload, 'masked'),
            Parse::datetime($payload, 'created'),
            $payload,
        );
    }
}

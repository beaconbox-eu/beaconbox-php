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
        /**
         * The id of the key that created this one through the API, detached or not; null for a
         * key created in the dashboard (or by a server that predates the field). After a leak, a
         * key still listed with the leaked key's id here was created detached with it and is
         * still live.
         */
        public readonly ?string $mintedBy = null,
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
            Parse::nullableString($payload, 'minted_by'),
        );
    }
}

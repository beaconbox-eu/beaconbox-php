<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * A freshly minted key.
 *
 * `$key` is the **only** time the full value exists anywhere outside the caller. BeaconBox stores
 * a hash, so it cannot show it again and cannot recover it for you. Put it straight into a secret
 * store: a key that reaches a log line or a support ticket has to be revoked.
 */
final class NewApiKey
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $name,
        public readonly string $key,
        public readonly string $masked,
        public readonly \DateTimeImmutable $created,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Redacted, deliberately, and so is `$raw`.
     *
     * A `var_dump($key)` in a debugging session is exactly how a live credential reaches a log
     * aggregator. Read `->key` explicitly to get the value.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'key' => '<redacted>',
            'masked' => $this->masked,
            'created' => $this->created,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            Parse::string($payload, 'name'),
            Parse::string($payload, 'key'),
            Parse::string($payload, 'masked'),
            Parse::datetime($payload, 'created'),
            $payload,
        );
    }
}

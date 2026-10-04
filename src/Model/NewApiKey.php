<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * A freshly minted key.
 *
 * `$key` is the **only** time the full value exists anywhere outside the caller. BeaconBox stores
 * a hash, so it cannot show it again and cannot recover it for you. Put it straight into a secret
 * store: a key that reaches a log line or a support ticket has to be revoked.
 *
 * **What is guarded, and what is not.** `var_dump()` and `json_encode()` both show `<redacted>`,
 * which covers the incidental debug line and the structured logger. `var_export()` and
 * `serialize()` accept no hook in PHP, so they still carry the value, as does `->raw`. Treat this
 * object as the credential it holds: read `->key`, store it, drop the object.
 */
final class NewApiKey implements \JsonSerializable
{
    /**
     * @param string $id The handle for revoking this key: pass it to
     *                   {@see \BeaconBox\Resource\Keys::revoke()}. Use it rather than matching on
     *                   `$name`, which carries no unique constraint and defaults to "Untitled key",
     *                   so two unnamed keys cannot be told apart.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $id,
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
            'id' => $this->id,
            'name' => $this->name,
            'key' => '<redacted>',
            'masked' => $this->masked,
            'created' => $this->created,
        ];
    }

    /**
     * Redacted for `json_encode()` as well, and that is the path that actually leaks.
     *
     * `__debugInfo()` above covers `var_dump()` and nothing else. The way a live key really
     * reaches a log aggregator is a structured logger: Monolog's `JsonFormatter` — and every
     * JSON-lines formatter like it — calls `json_encode()` on the context array, which without
     * this method serialises every public property, so `$log->info('key minted', ['key' => $new])`
     * wrote `bb_live_…` and the whole of `$raw` into the log stream.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            Parse::string($payload, 'id'),
            Parse::string($payload, 'name'),
            Parse::string($payload, 'key'),
            Parse::string($payload, 'masked'),
            Parse::datetime($payload, 'created'),
            $payload,
        );
    }
}

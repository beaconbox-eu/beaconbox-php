<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * What a batch returns. **Always HTTP 200: check `$failed`, not the status code.**
 *
 * An item that failed is reported, not thrown. One bad recipient must not discard the forty-nine
 * good pushes alongside it, and a 4xx for the whole call would invite a retry of all fifty.
 */
final class BatchResult
{
    /**
     * @param list<BatchItemResult> $items
     * @param array<string, mixed>  $raw
     */
    public function __construct(
        public readonly array $items,
        public readonly int $succeeded,
        public readonly int $failed,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Just the items that did not go through, for the usual "log what broke" loop.
     *
     * @return list<BatchItemResult>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->items, static fn (BatchItemResult $i): bool => !$i->ok));
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            array_map(BatchItemResult::fromArray(...), Parse::objectList($payload, 'items')),
            Parse::int($payload, 'succeeded'),
            Parse::int($payload, 'failed'),
            $payload,
        );
    }
}

<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * One page of messages, newest first.
 *
 * Keyset-paginated. Pass `$nextCursor` back as `$cursor` for the next page, or use
 * `$client->messages->each()`, which does it for you. Offsets are deliberately not offered: a
 * page 3 read while new messages arrive shows rows page 2 already did.
 */
final class MessagePage
{
    /**
     * @param list<Message>        $items
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            array_map(Message::fromArray(...), Parse::objectList($payload, 'items')),
            Parse::nullableString($payload, 'next_cursor'),
            $payload,
        );
    }
}

<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/** One item's outcome inside a batch. Items succeed and fail independently. */
final class BatchItemResult
{
    /**
     * @param int                  $index Position in the request you sent, so results can be
     *                                    matched back up.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly int $index,
        public readonly bool $ok,
        public readonly ?MessagePushResult $result = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $detail = null,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            Parse::int($payload, 'index'),
            Parse::bool($payload, 'ok'),
            isset($payload['result']) && \is_array($payload['result'])
                ? MessagePushResult::fromArray($payload['result'])
                : null,
            Parse::nullableString($payload, 'error_code'),
            Parse::nullableString($payload, 'detail'),
            $payload,
        );
    }
}

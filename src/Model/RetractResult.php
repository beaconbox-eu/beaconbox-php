<?php

declare(strict_types=1);

namespace BeaconBox\Model;

use BeaconBox\Enum\MessageStatus;

/**
 * What a retraction did.
 *
 * `retracted = false` does not mean anything failed. It means the message was already not live,
 * because you retracted it before or a later push superseded it.
 */
final class RetractResult
{
    /**
     * @param MessageStatus|string $status
     * @param int  $sendsCancelled  Queued sends this call called off. Zero means there was nothing
     *                              left to stop.
     * @param bool $alreadyNotified True means the recipient was already told. The message shows as
     *                              withdrawn in their inbox, but an email or text about it is out
     *                              and cannot be recalled.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly MessageStatus|string $status,
        public readonly bool $retracted,
        public readonly int $sendsCancelled,
        public readonly bool $alreadyNotified,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        /** @var MessageStatus|string $status */
        $status = Parse::enum(MessageStatus::class, $payload, 'status');

        return new self(
            Parse::string($payload, 'id'),
            $status,
            Parse::bool($payload, 'retracted'),
            Parse::int($payload, 'sends_cancelled'),
            Parse::bool($payload, 'already_notified'),
            $payload,
        );
    }
}

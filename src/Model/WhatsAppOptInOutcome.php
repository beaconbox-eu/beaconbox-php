<?php

declare(strict_types=1);

namespace BeaconBox\Model;

use BeaconBox\Enum\RecipientStatus;

/**
 * What the push's `whatsappOptInSource` did.
 *
 * `recorded = false` is an ordinary success in three of its four shapes: consent was already on
 * file, the number clashed with another recipient's, or the recipient has withdrawn. The last is
 * an answer rather than a failure, and a durable one: an opt-out is permanent for your business.
 *
 * Read `$status` rather than inferring state from `$skippedReason`. It is this recipient's
 * consent *after* the push.
 */
final class WhatsAppOptInOutcome
{
    /**
     * @param RecipientStatus|string $status
     * @param array<string, mixed>   $raw
     */
    public function __construct(
        public readonly bool $recorded,
        public readonly RecipientStatus|string $status,
        public readonly ?string $skippedReason = null,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        /** @var RecipientStatus|string $status */
        $status = Parse::enum(RecipientStatus::class, $payload, 'status');

        return new self(
            Parse::bool($payload, 'recorded'),
            $status,
            Parse::nullableString($payload, 'skipped_reason'),
            $payload,
        );
    }
}

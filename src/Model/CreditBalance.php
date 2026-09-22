<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/** The prepaid balance. One balance, shared by SMS and WhatsApp. */
final class CreditBalance
{
    /**
     * @param bool $lowBalance True below your threshold. SMS and WhatsApp stop at zero. Email is
     *                         unaffected, so a customer never stops receiving their order updates
     *                         because a balance ran out.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly int $balance,
        public readonly bool $lowBalance,
        public readonly int $threshold,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            Parse::int($payload, 'balance'),
            Parse::bool($payload, 'low_balance'),
            Parse::int($payload, 'threshold'),
            $payload,
        );
    }
}

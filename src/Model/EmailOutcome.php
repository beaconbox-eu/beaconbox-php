<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * The email side of a push: whether the nudge is expected to go, and why not if it is not.
 *
 * **Its own type, like {@see SmsOutcome} and {@see WhatsAppOutcome}, because suppression is per
 * channel.** A recipient who texted STOP has suppressed SMS and nothing else; a hard bounce
 * suppresses email and nothing else. Reading one channel's reason off another's block would tell
 * you a channel is blocked when it is not.
 *
 * `sending = false` is reliable — no email will be sent. `sending = true` is a strong expectation
 * rather than a promise: it is the verdict as known when you called, and it cannot see the day's
 * cap filling up between then and the send. Read `$message->delivery` or subscribe to
 * `message.not_sent` for what actually happened.
 */
final class EmailOutcome
{
    /**
     * @param string|null          $skippedReason An `EmailSkipReason` value, or null when
     *                                            sending. Treat an unrecognised value as "not
     *                                            sending" rather than as an error — the list
     *                                            grows.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $sending,
        public readonly ?string $skippedReason = null,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            Parse::bool($payload, 'sending'),
            Parse::nullableString($payload, 'skipped_reason'),
            $payload,
        );
    }
}

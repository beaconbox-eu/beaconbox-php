<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/**
 * Why BeaconBox gave up on an attempted email: the values {@see \BeaconBox\Model\Failed::$reason}
 * and the `message.failed` webhook carry.
 *
 * The counterpart of {@see EmailSkipReason} at the other end of the send path. That one means
 * BeaconBox decided not to try; this one means it tried and the send was refused, never
 * succeeded, or ended without an answer ({@see self::OutcomeUnknown}). Like that enum it is **not
 * the parsed type** of the field: compare against it and treat anything else as "failed",
 * because the list grows.
 *
 * ```php
 * if ($m->delivery->failed?->reason === EmailFailureReason::EspRefused->value) { ... }
 * ```
 */
enum EmailFailureReason: string
{
    /** The email provider rejected the message outright. */
    case EspRefused = 'esp_refused';
    /** Every attempt failed and BeaconBox stopped trying. */
    case RetriesExhausted = 'retries_exhausted';
    /**
     * The email provider accepted the message, or may have, but its answer was lost. Reported as
     * failed so nothing waits on it forever, and never sent again, because a second email would
     * be a second live sign-in link. **It may still arrive**: a later delivery from the provider
     * clears `$failed` (back to null) and shows as delivered, whatever its timestamp.
     */
    case OutcomeUnknown = 'outcome_unknown';
}

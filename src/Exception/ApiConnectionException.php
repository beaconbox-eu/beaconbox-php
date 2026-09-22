<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/**
 * The request never got an answer: DNS, TLS, a timeout, a dropped connection.
 *
 * **This is the exception that makes `Idempotency-Key` matter.** A connection error does not mean
 * the push did not happen; it means you do not know. Retrying with the same key is safe and is what
 * the SDK does automatically; retrying with a fresh one stores the message twice.
 */
final class ApiConnectionException extends BeaconBoxException
{
}

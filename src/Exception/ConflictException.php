<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/**
 * 409. The operation collided with the state of things: an SMS already sent, or an identical
 * request still in flight.
 *
 * `errorCode` says which: `sms.already_sent`, `whatsapp.already_sent` or
 * `whatsapp.escalated_to_sms` on a resend; `sms.phone_already_in_use` when the number belongs to
 * another of your recipients; `idempotency.request_in_progress` while the same key is still
 * running; and `request.conflict` when two requests raced to write the same thing. That last one
 * is safe to retry: the retry sees what the other request wrote.
 *
 * Not retried by the SDK. "Your earlier attempt is still running" is a truthful answer to give a
 * caller, and a client that quietly blocked for a second instead would be hiding it.
 */
final class ConflictException extends ApiException
{
}

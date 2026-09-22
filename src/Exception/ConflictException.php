<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/**
 * 409. The operation collided with the state of things: an SMS already sent, or an identical
 * request still in flight.
 *
 * Not retried by the SDK. "Your earlier attempt is still running" is a truthful answer to give a
 * caller, and a client that quietly blocked for a second instead would be hiding it.
 */
final class ConflictException extends ApiException
{
}

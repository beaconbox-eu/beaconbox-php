<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/**
 * 422. The request itself is wrong: a malformed field, a missing header, or a reused idempotency
 * key with a different body.
 */
final class InvalidRequestException extends ApiException
{
}

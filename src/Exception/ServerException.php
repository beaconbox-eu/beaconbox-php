<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/**
 * 5xx. BeaconBox failed. Safe to retry with the same idempotency key, which the SDK has already
 * done before this reached you.
 */
final class ServerException extends ApiException
{
}

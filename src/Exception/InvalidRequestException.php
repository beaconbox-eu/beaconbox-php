<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/**
 * 422 (and any other 4xx). The request itself is wrong: a malformed field, a missing header, or a
 * reused idempotency key with a different body.
 *
 * Two codes are about the body as a whole rather than one field. `request.too_large` (413) means
 * it was over 2 MiB, or 8 MiB for a batch, and was refused before it was read.
 * `request.unstorable_input` (422) means some text in it cannot be stored: a NUL character or a
 * lone surrogate, usually from a binary value or a broken decode upstream. Neither is retried by
 * the SDK, because the same body is refused again; the idempotency key is not spent, so a
 * corrected request may reuse it.
 */
final class InvalidRequestException extends ApiException
{
}

<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/**
 * 403. Authenticated, but not allowed to touch this.
 *
 * `plan.read_only` is the one with a fix on your side: the plan has lapsed and the account is
 * read-only, so {@see \BeaconBox\Resource\Keys::create()} is refused until the subscription is
 * paid.
 */
final class PermissionException extends ApiException
{
}

<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/** 403. Authenticated, but not allowed to touch this. */
final class PermissionException extends ApiException
{
}

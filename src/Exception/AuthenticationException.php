<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/** 401. The API key is missing, malformed or revoked. */
final class AuthenticationException extends ApiException
{
}

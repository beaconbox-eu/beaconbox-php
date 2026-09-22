<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/**
 * 404. No such message, recipient or endpoint.
 *
 * Also what a *scoped* miss looks like: BeaconBox answers 404 rather than 403 for another
 * business's id, because an answer that distinguished them would confirm which ids exist.
 */
final class ResourceMissingException extends ApiException
{
}

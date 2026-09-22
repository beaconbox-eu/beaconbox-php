<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/**
 * A webhook delivery could not be proven to have come from BeaconBox.
 *
 * Deliberately not an {@see ApiException}: nothing was requested and no status code exists. Answer
 * 400 to the caller and do not process the payload.
 */
final class WebhookVerificationException extends BeaconBoxException
{
}

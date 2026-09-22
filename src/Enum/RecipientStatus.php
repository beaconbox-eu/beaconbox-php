<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/**
 * A recipient's consent on one channel, for one business.
 *
 * SMS and WhatsApp carry this separately and must never be conflated: holding a number for SMS
 * says nothing about whether its owner agreed to be messaged on WhatsApp, and Meta can audit
 * that agreement.
 *
 * `Stopped` is permanent for your business. It cannot be undone through the API, by design:
 * collect consent again out of band.
 */
enum RecipientStatus: string
{
    case None = 'none';
    case Active = 'active';
    case Stopped = 'stopped';
}

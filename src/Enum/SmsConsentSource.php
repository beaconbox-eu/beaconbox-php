<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/**
 * The surface a recipient's number was first accepted for texting through.
 *
 * Recorded once, when texting first began, and never overwritten: a later number change, a STOP
 * and a START all leave it as the evidence of how it started.
 */
enum SmsConsentSource: string
{
    /** A `recipientPhone` on a push. */
    case Push = 'push';
    /** {@see \BeaconBox\Resource\Recipients::setPhone()}. */
    case Api = 'api';
    /** A signed-in person on the recipient page in the dashboard. */
    case Admin = 'admin';
    /** The recipient adding their own number in their inbox. */
    case Inbox = 'inbox';
}

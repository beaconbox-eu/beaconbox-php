<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/**
 * A delivery channel a push may request.
 *
 * `Email` is implicit and always sent, because it carries the inbox link. Listing it is allowed
 * and harmless.
 */
enum Channel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
}

<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/** How much of a customer's WhatsApp reply your notification email carries. */
enum WhatsAppReplyForward: string
{
    /** The words themselves, which is what puts them in your mailboxes and your backups. */
    case Full = 'full';

    case LinkOnly = 'link_only';
    case Off = 'off';
}

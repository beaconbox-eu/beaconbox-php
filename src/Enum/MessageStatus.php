<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/** Where a message stands in the recipient's inbox. */
enum MessageStatus: string
{
    case Active = 'active';

    /** Superseded by a later push that named it in `obsoletes`. Greyed out, not removed. */
    case Obsolete = 'obsolete';

    /** Withdrawn by the sender. Also greyed out: they may already have read it. */
    case Retracted = 'retracted';
}

<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/** Whether a push creates a new message every time, or updates one in place. */
enum MessageKind: string
{
    /** Always a new message, always a nudge. */
    case OneOff = 'one_off';

    /** Matched on subject. Creates and nudges the first time, then updates in place quietly. */
    case Updateable = 'updateable';
}

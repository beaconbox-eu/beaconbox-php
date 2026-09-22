<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/**
 * How far one SMS or WhatsApp send got.
 *
 * `Accepted` means the carrier or Meta took it. `Delivered` means the handset confirmed it. The
 * gap between those two is where a number that no longer exists lives.
 */
enum ChannelSendStatus: string
{
    case Queued = 'queued';
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Skipped = 'skipped';
}

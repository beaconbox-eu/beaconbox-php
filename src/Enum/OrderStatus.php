<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/**
 * What happened to the order a push is about: the value of a push's `orderStatus`.
 *
 * BeaconBox picks the platform-written WhatsApp and SMS wording from it, for example "Your order
 * #A-10294 from PhonicBloom has shipped." A WhatsApp nudge is sent only when the push carries both
 * `reference` and `orderStatus`; otherwise WhatsApp is skipped with `template_not_sendable` and
 * nothing is charged.
 *
 * The list grows on the server, so a push accepts a plain string as well, and a result carrying a
 * value this SDK does not know reads it back as that string.
 */
enum OrderStatus: string
{
    case Confirmed = 'confirmed';
    case Shipped = 'shipped';
    case ReadyForPickup = 'ready_for_pickup';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Delayed = 'delayed';
}

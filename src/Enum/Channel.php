<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/**
 * A delivery channel a push may request.
 *
 * `Email` is implicit and always sent, because it carries the inbox link. Listing it is allowed
 * and harmless.
 *
 * Naming `Sms` or `WhatsApp` in a push's `channels` is a request that can only narrow what your
 * account settings allow, never switch a channel on. A channel whose mode is `off` stays off, and
 * one BeaconBox has not yet enabled for your business never sends. A channel in `on_request` mode
 * sends only on a push that names it.
 */
enum Channel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
}

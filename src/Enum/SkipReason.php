<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/**
 * Why a paid channel was not sent.
 *
 * **A skip is a successful push.** The update is in the recipient's inbox and the email nudge has
 * gone. This SDK never throws on one, because treating a skip as an error is what invites a
 * retry, and a retry of a push that already succeeded is a second message to a real person.
 *
 * Not every reason is actionable, and the useful split is between the ones you can do something
 * about and the ones you cannot:
 *
 * - `InsufficientCredit` is the one to act on. Top up, then call `$client->messages->resendSms()`.
 * - `NoPhone` and `PhoneConflict` are data problems on your side.
 * - `Stopped`, `Unsubscribed` and `NotOptedIn` are the recipient's choice. Do not route around
 *   them.
 * - The rest are account or destination settings.
 *
 * Values not listed here can appear. Models expose `skippedReason` as a plain `?string` for that
 * reason: compare with `SkipReason::InsufficientCredit->value`, or use `SkipReason::tryFrom()`.
 */
enum SkipReason: string
{
    case SendingPaused = 'sending_paused';
    case Unsubscribed = 'unsubscribed';

    /** Your own `channels` argument left this channel out. */
    case DisabledByRequest = 'disabled_by_request';

    case SmsDisabled = 'sms_disabled';
    case WhatsAppDisabled = 'whatsapp_disabled';
    case CountryNotAllowed = 'country_not_allowed';
    case NoPhone = 'no_phone';

    /** The number belongs to another of your recipients, so it was not stored. */
    case PhoneConflict = 'phone_conflict';

    case Stopped = 'stopped';
    case NotOptedIn = 'not_opted_in';

    /** The number is not on WhatsApp. Learned by sending, never by asking. */
    case NotReachable = 'not_reachable';

    case TemplateNotSendable = 'template_not_sendable';
    case InsufficientCredit = 'insufficient_credit';
    case RateLimited = 'rate_limited';
    case PlatformLimited = 'platform_limited';

    /** Consent was already on file, with its original date and source kept. */
    case AlreadyOptedIn = 'already_opted_in';

    case OptedOut = 'opted_out';
}

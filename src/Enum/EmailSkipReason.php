<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/**
 * Why a nudge email was never sent — the values {@see \BeaconBox\Model\NotSent::$reason} carries.
 *
 * **Not used as the parsed type**, deliberately. `NotSent::$reason` stays a string, because this
 * list grows on the server whenever a new refusal is added to the send path and an SDK that
 * refused to parse an unknown value would turn that into a client-side crash. This enum is here
 * to compare against and to document the vocabulary:
 *
 * ```php
 * if ($m->delivery->notSent?->reason === EmailSkipReason::PlanLapsed->value) { ... }
 * ```
 *
 * The three worth branching on are `PlanLapsed` (pay, and sending resumes), `DailyCapReached`
 * (nothing is wrong; the day's allowance ran out) and `Suppressed` (that address will never be
 * emailed again for you).
 */
enum EmailSkipReason: string
{
    /** The subscription no longer pays for email. Nothing was attempted. */
    case PlanLapsed = 'plan_lapsed';
    /** The sending domain's daily cap was reached. Later messages that day are also refused. */
    case DailyCapReached = 'daily_cap_reached';
    /** Sending is paused for this business — by the complaint monitor or by BeaconBox staff. */
    case SendingPaused = 'sending_paused';
    /** This address hard-bounced or complained for you, and is on your suppression list. */
    case Suppressed = 'suppressed';
    /** This address hard-bounced for another merchant on the shared sending domain. */
    case PlatformSuppressed = 'platform_suppressed';
    /** The recipient unsubscribed from this business. */
    case Unsubscribed = 'unsubscribed';
    /** You retracted the message before the nudge ran. */
    case MessageRetracted = 'message_retracted';
    /** A newer message replaced it before the nudge ran. */
    case MessageObsolete = 'message_obsolete';
}

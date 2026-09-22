<?php

declare(strict_types=1);

namespace BeaconBox\Enum;

/**
 * What an endpoint can subscribe to.
 *
 * Only outcomes the sender cannot already know. There is no `message.created`: you just made
 * that call and got the message back, so an event for it would be a round trip telling you
 * nothing.
 *
 * An endpoint registered with an **empty** list receives every type, including types added after
 * it was created. That is the recommended setting, because a narrow subscription is how a new
 * event type silently passes an integration by.
 */
enum WebhookEventType: string
{
    case MessageDelivered = 'message.delivered';
    case MessageOpened = 'message.opened';
    case MessageBounced = 'message.bounced';
    case MessageFailed = 'message.failed';
    case MessageComplained = 'message.complained';
    /** No email was sent at all, and why — the payload carries `reason`. Not `message.failed`:
     *  nothing was attempted and nothing bounced. */
    case MessageNotSent = 'message.not_sent';
    case SmsDelivered = 'sms.delivered';
    case SmsFailed = 'sms.failed';
    case SmsRejected = 'sms.rejected';
    case WhatsAppDelivered = 'whatsapp.delivered';
    case WhatsAppFailed = 'whatsapp.failed';
    case WhatsAppRejected = 'whatsapp.rejected';
    case WhatsAppRead = 'whatsapp.read';
    case WhatsAppInboundMessage = 'whatsapp.inbound_message';
    case CreditsLowBalance = 'credits.low_balance';
    /** The card failed and email sending stops at `period_end`. There is still time to fix it. */
    case PlanPastDue = 'plan.past_due';
    /** The paid period ended and email sending has stopped. Every nudge from here produces a
     *  `message.not_sent` with `reason: "plan_lapsed"` until the subscription is paid. */
    case PlanLapsed = 'plan.lapsed';
    /** The period's included email allowance is used up. Nothing stops — it is billable. */
    case PlanAllowanceExceeded = 'plan.allowance_exceeded';
}

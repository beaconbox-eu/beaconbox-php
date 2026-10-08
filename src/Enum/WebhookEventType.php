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
    /**
     * An email was attempted and will never be delivered. The payload carries `message_id`,
     * `recipient_email`, `subject` and `channel` (`"email"`), plus `reason` when BeaconBox gave up
     * on the send itself: `esp_refused`, `retries_exhausted` or `outcome_unknown` (the answer was
     * lost and it may still arrive; see {@see EmailFailureReason}).
     * The same outcome reads back as `$message->delivery->failed`; on `outcome_unknown` a later
     * `message.delivered` clears it.
     */
    case MessageFailed = 'message.failed';
    case MessageComplained = 'message.complained';
    /** No email was sent at all, and why — the payload carries `reason`. Not `message.failed`:
     *  nothing was attempted and nothing bounced. */
    case MessageNotSent = 'message.not_sent';
    case SmsDelivered = 'sms.delivered';
    /**
     * A text did not reach the handset. The payload carries `message_id`, `recipient_phone`
     * (masked), `country_code`, `credits_charged`, `channel` (`"sms"`) and `error_code`.
     *
     * `error_code` `sms.submit_outcome_unknown` means BeaconBox called the carrier and never
     * learned the answer. No second copy is sent, because the first may already have gone, and
     * the charge stands for the same reason: the recipient may still have received it.
     *
     * Sent on the carrier's receipt, and also when BeaconBox ends the send itself: an outcome it
     * never learned, or a send given up on after its retries.
     */
    case SmsFailed = 'sms.failed';
    /** The text was refused, by the carrier's receipt or at submit. */
    case SmsRejected = 'sms.rejected';
    case WhatsAppDelivered = 'whatsapp.delivered';
    /**
     * A WhatsApp message did not reach the handset. The payload has the same fields as
     * `sms.failed`, with `channel` `"whatsapp"`.
     *
     * `error_code` `whatsapp.submit_outcome_unknown` means BeaconBox called Meta and never learned
     * the answer, and no status arrived for it within five minutes. It is not resent and the
     * charge stands, as for SMS; `smsIfWhatsAppFails` still texts them.
     *
     * Sent on Meta's status, and also when BeaconBox ends the send itself: an outcome it never
     * learned, or a send given up on after its retries.
     */
    case WhatsAppFailed = 'whatsapp.failed';
    /**
     * The WhatsApp message was refused, by Meta's status or at submit. Also sent, with `error_code`
     * `whatsapp.submit_refused` and the credit refunded, for a send Meta refused on every attempt
     * until BeaconBox gave up.
     */
    case WhatsAppRejected = 'whatsapp.rejected';
    case WhatsAppRead = 'whatsapp.read';
    case WhatsAppInboundMessage = 'whatsapp.inbound_message';
    case CreditsLowBalance = 'credits.low_balance';
    /** The card failed and email sending stops at `period_end`. There is still time to fix it. */
    case PlanPastDue = 'plan.past_due';
    /** The paid period ended and email sending has stopped. Every nudge from here produces a
     *  `message.not_sent` with `reason: "plan_lapsed"` until the subscription is paid. */
    case PlanLapsed = 'plan.lapsed';
    /** The period's included email allowance is used up. Sending continues and nothing is charged
     *  for it: the allowance is fair use. */
    case PlanAllowanceExceeded = 'plan.allowance_exceeded';
}

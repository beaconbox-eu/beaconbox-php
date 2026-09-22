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
    case SmsDelivered = 'sms.delivered';
    case SmsFailed = 'sms.failed';
    case SmsRejected = 'sms.rejected';
    case WhatsAppDelivered = 'whatsapp.delivered';
    case WhatsAppFailed = 'whatsapp.failed';
    case WhatsAppRejected = 'whatsapp.rejected';
    case WhatsAppRead = 'whatsapp.read';
    case WhatsAppInboundMessage = 'whatsapp.inbound_message';
    case CreditsLowBalance = 'credits.low_balance';
}

<?php

declare(strict_types=1);

namespace BeaconBox\Model;

use BeaconBox\Enum\Channel;
use BeaconBox\Enum\MessageKind;
use BeaconBox\Enum\OrderStatus;

/**
 * One push, as an object.
 *
 * Named arguments make this read like the API's own documentation, and an editor completes the
 * field names. That is the whole reason it exists rather than an associative array: a typo in
 * `'recipent_email'` is a 422 at runtime, while a typo in `recipentEmail:` is an error before the
 * code ever runs.
 *
 * ```php
 * $client->messages->push(new MessagePush(
 *     recipientEmail: 'buyer@example.com',
 *     subject: 'Your order has shipped',
 *     body: 'Tracking XY123456789EE.',
 *     kind: MessageKind::Updateable,
 *     reference: '#A-10294',
 *     orderStatus: OrderStatus::Shipped,
 * ));
 * ```
 *
 * `push()` also accepts a plain array for callers building a payload dynamically, so nothing here
 * is mandatory.
 */
final class MessagePush
{
    /**
     * @param string $recipientEmail Who this is for. They do not need a BeaconBox account.
     * @param string $subject        What the update is about. For `MessageKind::Updateable` this
     *                               is also the **matching key**: pushing the same subject again
     *                               overwrites that message in place.
     * @param string $body           Plain text and clickable links only.
     * @param MessageKind|string $kind `OneOff` always creates a new message and nudges.
     *                               `Updateable` creates and nudges the first time, then updates
     *                               in place quietly, which is what you want for a shipping
     *                               estimate that moves twice before the parcel arrives.
     * @param string|null $reference Your own order number, for example `#A-10294`. Letters, digits
     *                               and `# - _ . /` only, no spaces, at most 32 characters. It
     *                               appears in the WhatsApp and the SMS nudge, so it has to be an
     *                               identifier rather than a sentence, and a nudge shows it only
     *                               when it passes a stricter display rule: an optional leading
     *                               `#`, then a letter or digit, then letters, digits and
     *                               `_ / # -`, with at most two dots, each directly before a digit
     *                               (`INV.2026` is shown, `shop.com` is not). A reference that
     *                               fails the rule is stored and echoed but never shown, and such
     *                               a push sends no WhatsApp message.
     * @param string|null $recipientPhone Mobile number for the SMS nudge, E.164 preferred. Stored
     *                               against this recipient for your business and reused on later
     *                               pushes, so it is optional once you have sent it once. A
     *                               number that cannot be parsed, or cannot be a recipient's
     *                               phone (premium-rate, toll-free, shared-cost, voicemail,
     *                               service, or a satellite or international code such as +881),
     *                               refuses the whole push with a 422 (`sms.phone_invalid`) before
     *                               anything is written, so you can correct it and retry with the
     *                               same key.
     * @param list<Channel|string>|null $channels Which paid channels this push asks for. **A
     *                               request that can only narrow, never switch a channel on.**
     *                               Null follows your account settings. Leaving a channel out
     *                               suppresses it for this push. Naming one sends on it only where
     *                               your settings already allow it: a channel in `off` mode stays
     *                               off (`sms_disabled`, `whatsapp_disabled`), one BeaconBox has
     *                               not yet enabled for your business never sends
     *                               (`sms_not_enabled`, `whatsapp_not_enabled`), and one in
     *                               `on_request` mode sends only on a push that names it. Naming a
     *                               channel never overrides a country restriction, a recipient who
     *                               opted out, or a recipient who never opted in to WhatsApp.
     *                               Listing both `sms` and `whatsapp` sends two messages and costs
     *                               two credits. Email is always sent regardless.
     * @param bool|null $notify      Null applies the default (create nudges, in-place update stays
     *                               silent). True forces a nudge on an update, false suppresses
     *                               one even on first create.
     * @param \DateTimeInterface|null $sendAt Hold the *nudge* until this moment. The message is
     *                               readable immediately either way: only the ping waits, because
     *                               the inbox is the source of truth and the nudge is what should
     *                               land at a civilised hour. At most 90 days out.
     * @param int|null $escalateIfUnreadAfterMinutes **Hold the paid channel back until the
     *                               recipient has had a chance to read the email.** The SMS or
     *                               WhatsApp message named in `$channels` is not sent now. It is
     *                               sent only if they still have not opened the message after this
     *                               many minutes (5 to 10080). If they open it first, nothing is
     *                               sent and nothing is charged. That inverts what a paid channel
     *                               usually costs you: instead of paying to interrupt everybody,
     *                               you pay only for the people the email did not reach. The
     *                               minutes count from the email: from `$sendAt` when you set
     *                               one, otherwise from the push, and if the email is still
     *                               queued at the deadline it moves to this many minutes after
     *                               the email goes. An email that bounced, failed or was skipped
     *                               (a suppressed address, say) counts as unread, so the paid
     *                               channel is still sent, subject to its own consent and
     *                               settings: that is the case the paid channel exists for. Only
     *                               a push that queued no nudge at all never escalates. A channel
     *                               in `on_request` mode that this push named still sends at the
     *                               deadline.
     * @param list<string> $obsoletes Ids of your earlier messages to grey out, for when this
     *                               update replaces them. At most 100 ids of at most 64
     *                               characters each; more is a 422.
     * @param string|null $whatsAppOptInSource Record that this recipient agreed to be messaged on
     *                               WhatsApp, naming the surface where they agreed in your own
     *                               words, for example `"checkout tickbox"`. This is the audit
     *                               answer to "prove they agreed", so it must name a real surface
     *                               of yours. Recording consent is not sending, so it works while
     *                               your WhatsApp channel is still off. Consent is per business
     *                               and reaches no other merchant.
     * @param bool $smsIfWhatsAppFails Text this recipient if the WhatsApp nudge proves
     *                               undeliverable: Meta refuses it, the delivery status comes back
     *                               failed, or no status arrives within five minutes. It does not
     *                               fire for a message that was delivered and not read. It also
     *                               texts at once when WhatsApp cannot carry the message at all:
     *                               the number is already known not to be on WhatsApp
     *                               (`not_reachable`), or there is no template for it
     *                               (`template_not_sendable` because the push lacks `$reference`
     *                               or `$orderStatus`, or the reference or your business name
     *                               cannot be shown); not while a template is still in review at
     *                               Meta. At most one text either way. The SMS costs its own
     *                               credit.
     * @param OrderStatus|string|null $orderStatus What happened to the order, as an
     *                               {@see OrderStatus} or its string value (`'shipped'`, say). A
     *                               plain string is sent as given, so a status the API adds later
     *                               works before this SDK knows it. BeaconBox picks the
     *                               platform-written WhatsApp and SMS wording from it, for example
     *                               "Your order #A-10294 from PhonicBloom has shipped." **A
     *                               WhatsApp nudge needs both `$reference` and `$orderStatus`:**
     *                               missing either, WhatsApp is skipped with
     *                               `template_not_sendable` and nothing is charged. An in-place
     *                               update replaces it, as it does `$reference`, so an update
     *                               without it clears it.
     */
    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $subject,
        public readonly string $body,
        public readonly MessageKind|string $kind = MessageKind::OneOff,
        public readonly ?string $reference = null,
        public readonly ?string $recipientPhone = null,
        public readonly ?array $channels = null,
        public readonly ?bool $notify = null,
        public readonly ?\DateTimeInterface $sendAt = null,
        public readonly ?int $escalateIfUnreadAfterMinutes = null,
        public readonly array $obsoletes = [],
        public readonly ?string $whatsAppOptInSource = null,
        public readonly bool $smsIfWhatsAppFails = false,
        // Last, so a caller passing the parameters above positionally is not shifted by it.
        public readonly OrderStatus|string|null $orderStatus = null,
    ) {
    }

    /**
     * The request body, with unset optional fields left out entirely.
     *
     * Omitted rather than sent as null, because null is not "unset" on several of these fields.
     * `notify: null` means "apply the default", while `notify: false` actively suppresses a nudge
     * that would otherwise be sent.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'recipient_email' => $this->recipientEmail,
            'subject' => $this->subject,
            'body' => $this->body,
            'kind' => $this->kind instanceof MessageKind ? $this->kind->value : $this->kind,
        ];

        if ($this->reference !== null) {
            $payload['reference'] = $this->reference;
        }
        if ($this->orderStatus !== null) {
            $payload['order_status'] = $this->orderStatus instanceof OrderStatus
                ? $this->orderStatus->value
                : $this->orderStatus;
        }
        if ($this->recipientPhone !== null) {
            $payload['recipient_phone'] = $this->recipientPhone;
        }
        if ($this->channels !== null) {
            $payload['channels'] = array_map(
                static fn (Channel|string $c): string => $c instanceof Channel ? $c->value : $c,
                $this->channels,
            );
        }
        if ($this->notify !== null) {
            $payload['notify'] = $this->notify;
        }
        if ($this->sendAt !== null) {
            $payload['send_at'] = $this->formatSendAt($this->sendAt);
        }
        if ($this->escalateIfUnreadAfterMinutes !== null) {
            $payload['escalate_if_unread_after_minutes'] = $this->escalateIfUnreadAfterMinutes;
        }
        if ($this->obsoletes !== []) {
            $payload['obsoletes'] = $this->obsoletes;
        }
        if ($this->whatsAppOptInSource !== null) {
            $payload['whatsapp_opt_in'] = ['source' => $this->whatsAppOptInSource];
        }
        if ($this->smsIfWhatsAppFails) {
            $payload['sms_if_whatsapp_fails'] = true;
        }

        return $payload;
    }

    /**
     * RFC 3339 with the offset the caller's object carries.
     *
     * `send_at` requires a timezone, and PHP will happily hand you a `DateTime` in whatever
     * `date.timezone` says, which on a default install is UTC and on a merchant's server is
     * frequently not. Formatting with the object's own offset rather than stripping it means a
     * nudge scheduled for "08:00 Tallinn" arrives at 08:00 in Tallinn.
     */
    private function formatSendAt(\DateTimeInterface $when): string
    {
        return $when->format(\DateTimeInterface::RFC3339);
    }
}

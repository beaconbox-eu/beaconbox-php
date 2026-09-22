<?php

declare(strict_types=1);

namespace BeaconBox\Model;

use BeaconBox\Enum\Channel;
use BeaconBox\Enum\MessageKind;

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
     *                               appears in the WhatsApp nudge, which Meta reviews, so it has
     *                               to be an identifier rather than a sentence.
     * @param string|null $recipientPhone Mobile number for the SMS nudge, E.164 preferred. Stored
     *                               against this recipient for your business and reused on later
     *                               pushes, so it is optional once you have sent it once.
     * @param list<Channel|string>|null $channels Override your account's channel settings for this
     *                               one request. Null follows the account defaults. Listing both
     *                               `sms` and `whatsapp` sends two messages and costs two credits.
     *                               Email is always sent regardless. An explicit request overrides
     *                               your account default only: it never overrides a country
     *                               restriction, a recipient who opted out, or a recipient who
     *                               never opted in to WhatsApp.
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
     *                               you pay only for the people the email did not reach.
     * @param list<string> $obsoletes Ids of your earlier messages to grey out, for when this
     *                               update replaces them.
     * @param string|null $whatsAppOptInSource Record that this recipient agreed to be messaged on
     *                               WhatsApp, naming the surface where they agreed in your own
     *                               words, for example `"checkout tickbox"`. This is the audit
     *                               answer to "prove they agreed", so it must name a real surface
     *                               of yours. Recording consent is not sending, so it works while
     *                               your WhatsApp channel is still off. Consent is per business
     *                               and reaches no other merchant.
     * @param bool $smsIfWhatsAppFails Text this recipient if the WhatsApp nudge proves
     *                               undeliverable. It does not fire for a message that was
     *                               delivered and not read. The SMS costs its own credit.
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

<?php

declare(strict_types=1);

namespace BeaconBox\Model;

/**
 * What `$client->recipients->erase()` removed from your records about one recipient, as counts.
 * **The erasure is not undoable.**
 *
 * Counts only, never content: a report quoting what it deleted would be a fresh copy of it.
 *
 * Two counts are of things **kept**, on purpose. `$suppressionsKept` and `$stopsKept` are what
 * stop this person being contacted again by a later push to the same address or number, and an
 * opt-out that is erased on request stops being one.
 *
 * A repeat erasure, and an erasure of an address you never messaged, both answer with zeros. To
 * get the original counts back after a timed-out request, retry with the **same** idempotency key.
 */
final class RecipientErasureReport
{
    /**
     * @param int  $messagesDeleted           Messages you sent them, subjects and bodies included.
     * @param int  $messageEventsDeleted      Delivery, open and not-sent events about those messages.
     * @param int  $channelSendsDeleted       SMS and WhatsApp send records, with the number they
     *                                        went to. Your credit ledger keeps its entries; they no
     *                                        longer point at a send.
     * @param int  $messageLinksDeleted       Links that opened one of those messages.
     * @param int  $whatsAppRepliesDeleted    WhatsApp replies they sent you, bodies included.
     * @param int  $webhookPayloadsScrubbed   Webhook deliveries about this person whose address,
     *                                        subject, number or reply text was replaced with null. A
     *                                        delivery still queued for your endpoint arrives with
     *                                        those fields empty. Copies your endpoint already
     *                                        received are yours to delete.
     * @param int  $queuedJobsCancelled       Nudges, texts and escalations still waiting to go out
     *                                        for those messages, now cancelled.
     * @param int  $idempotencyRecordsDeleted Stored API responses that named this recipient.
     *                                        Retrying one of those requests with its original key
     *                                        runs it again rather than replaying it.
     * @param int  $auditEntriesPseudonymised Entries in your audit log about this recipient. They
     *                                        stay, because they record what your staff did; the
     *                                        address in them is replaced by a keyed hash.
     * @param bool $subscriptionDeleted       Whether their recipient record (phone number, SMS and
     *                                        WhatsApp consent, unsubscribe state) was deleted. False
     *                                        when there was none, for example on a repeat erasure.
     * @param int  $stopsKept                 SMS or WhatsApp opt-outs for their number that are
     *                                        kept, so nobody can text them again by pushing to this
     *                                        address later.
     * @param int  $suppressionsKept          Email suppressions (a hard bounce, a complaint, or
     *                                        their unsubscribe from you) for this address that are
     *                                        kept, for the same reason.
     * @param int  $autoReplyWindowsDropped   Rows holding their number so the automatic WhatsApp
     *                                        acknowledgement would not repeat.
     * @param int  $repliesAForwardEmailMayHaveCarried **Your remaining work.** An upper bound on
     *                                        erased WhatsApp replies that your reply-forward email
     *                                        had already sent to your admin users' mailboxes. Those
     *                                        copies are outside anything BeaconBox can reach:
     *                                        search your own mail for this recipient.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly int $messagesDeleted,
        public readonly int $messageEventsDeleted,
        public readonly int $channelSendsDeleted,
        public readonly int $messageLinksDeleted,
        public readonly int $whatsAppRepliesDeleted,
        public readonly int $webhookPayloadsScrubbed,
        public readonly int $queuedJobsCancelled,
        public readonly int $idempotencyRecordsDeleted,
        public readonly int $auditEntriesPseudonymised,
        public readonly bool $subscriptionDeleted,
        public readonly int $stopsKept,
        public readonly int $suppressionsKept,
        public readonly int $autoReplyWindowsDropped,
        public readonly int $repliesAForwardEmailMayHaveCarried,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            Parse::int($payload, 'messages_deleted'),
            Parse::int($payload, 'message_events_deleted'),
            Parse::int($payload, 'channel_sends_deleted'),
            Parse::int($payload, 'message_links_deleted'),
            Parse::int($payload, 'whatsapp_replies_deleted'),
            Parse::int($payload, 'webhook_payloads_scrubbed'),
            Parse::int($payload, 'queued_jobs_cancelled'),
            Parse::int($payload, 'idempotency_records_deleted'),
            Parse::int($payload, 'audit_entries_pseudonymised'),
            Parse::bool($payload, 'subscription_deleted'),
            Parse::int($payload, 'stops_kept'),
            Parse::int($payload, 'suppressions_kept'),
            Parse::int($payload, 'auto_reply_windows_dropped'),
            Parse::int($payload, 'replies_a_forward_email_may_have_carried'),
            $payload,
        );
    }
}

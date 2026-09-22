<?php

declare(strict_types=1);

namespace BeaconBox\Model;

use BeaconBox\Enum\WhatsAppReplyForward;

/**
 * What an erasure deleted, and what it could not reach.
 *
 * Counts only. A receipt quoting the bodies it deleted would be a fresh copy of them.
 *
 * **`$repliesAForwardEmailMayHaveCarried` is your remaining work.** Until you act on it the
 * erasure is only ours.
 */
final class WhatsAppErasureReceipt
{
    /**
     * @param int $webhookPayloadsScrubbed Copies removed from `whatsapp.inbound_message`
     *                                     deliveries still queued for your endpoint. Those
     *                                     deliveries still arrive, now carrying a null body.
     * @param bool $consentWithdrawn True when this erasure moved them to stopped, which is
     *                               permanent for your business.
     * @param int $repliesAForwardEmailMayHaveCarried Erased replies whose reply-forward
     *                               notification had already run. Where your forwarding setting
     *                               was `full` at the time, the customer's words went to your
     *                               admin users' mailboxes, and an inbox, an archive, a backup and
     *                               a helpdesk's search index are all outside anything BeaconBox
     *                               can reach. An upper bound, because the setting on the day is
     *                               not recorded.
     * @param WhatsAppReplyForward|string $forwardSetting Your forwarding setting **now**, so you
     *                               know which mailboxes to search from here on. It says nothing
     *                               about what it was when the erased replies arrived.
     * @param \DateTimeImmutable $firstErasedAt When this recipient was *first* erased. On a repeat
     *                               erasure the counts above are zero because there was nothing
     *                               left, not because there never was anything.
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly int $repliesDeleted,
        public readonly int $webhookPayloadsScrubbed,
        public readonly int $autoReplyWindowsDropped,
        public readonly bool $consentWithdrawn,
        public readonly int $repliesAForwardEmailMayHaveCarried,
        public readonly WhatsAppReplyForward|string $forwardSetting,
        public readonly \DateTimeImmutable $firstErasedAt,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        /** @var WhatsAppReplyForward|string $forward */
        $forward = Parse::enum(WhatsAppReplyForward::class, $payload, 'forward_setting');

        return new self(
            Parse::int($payload, 'replies_deleted'),
            Parse::int($payload, 'webhook_payloads_scrubbed'),
            Parse::int($payload, 'auto_reply_windows_dropped'),
            Parse::bool($payload, 'consent_withdrawn'),
            Parse::int($payload, 'replies_a_forward_email_may_have_carried'),
            $forward,
            Parse::datetime($payload, 'first_erased_at'),
            $payload,
        );
    }
}

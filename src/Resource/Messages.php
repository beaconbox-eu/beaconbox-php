<?php

declare(strict_types=1);

namespace BeaconBox\Resource;

use BeaconBox\Exception\BeaconBoxException;
use BeaconBox\Model\BatchResult;
use BeaconBox\Model\Message;
use BeaconBox\Model\MessagePage;
use BeaconBox\Model\MessagePush;
use BeaconBox\Model\MessagePushResult;
use BeaconBox\Model\RetractResult;
use BeaconBox\Model\SmsOutcome;
use BeaconBox\Model\WhatsAppOutcome;

/**
 * Pushing updates, and everything that happens to one afterwards.
 *
 * **Read the response, not the status code.** A push returns 201 even when the SMS or WhatsApp
 * message was not sent. The update is in the customer's inbox and the email nudge has gone, so a
 * paid channel that could not send reports `skippedReason` and the request still succeeded. That
 * is the API's central convention and the one this SDK most has to avoid hiding: nothing here
 * throws on a skip.
 *
 * ```php
 * $result = $client->messages->push(new MessagePush(
 *     recipientEmail: 'buyer@example.com',
 *     subject: 'Your order has shipped',
 *     body: 'Tracking XY123456789EE.',
 *     channels: [Channel::Sms],
 *     recipientPhone: '+37255550134',
 * ));
 *
 * if ($result->sms?->skippedReason === SkipReason::InsufficientCredit->value) {
 *     // Top up, then: $client->messages->resendSms($result->id);
 * }
 * ```
 */
final class Messages extends BaseResource
{
    /**
     * Push an update to a customer.
     *
     * The customer gets an email whose link opens their inbox already signed in. No password, no
     * account to create.
     *
     * `$idempotencyKey` is optional and generated when omitted. **Pass your own whenever you have
     * a natural key**, an order id or a job id, because then a retry from *anywhere* (your queue,
     * a cron, a human clicking twice) collapses onto the same key rather than only the retries
     * this SDK makes internally.
     *
     * @param MessagePush|array<string, mixed> $message A {@see MessagePush} for named arguments
     *                                                  and editor completion, or a raw array for
     *                                                  a payload built dynamically.
     */
    public function push(MessagePush|array $message, ?string $idempotencyKey = null): MessagePushResult
    {
        return MessagePushResult::fromArray($this->transport->request(
            'POST',
            '/messages',
            $message instanceof MessagePush ? $message->toArray() : $message,
            idempotencyKey: $idempotencyKey,
        ));
    }

    /**
     * Up to 100 pushes in one call.
     *
     * A list of complete, individual pushes, not one message fanned out to many recipients: fifty
     * orders means fifty tracking numbers.
     *
     * **Always answers 200. Read `$result->failed`, not the status code.** One rejected item does
     * not fail the batch, because a batch that aborted at item 7 would leave items 8 onwards
     * unsent with nothing to say which.
     *
     * Safe to retry: each item carries its own key derived from the batch's, so a retry after a
     * crash replays the items that already landed instead of pushing them again.
     *
     * @param list<MessagePush|array<string, mixed>> $messages
     */
    public function pushBatch(array $messages, ?string $idempotencyKey = null): BatchResult
    {
        $items = array_map(
            static fn (MessagePush|array $m): array => $m instanceof MessagePush ? $m->toArray() : $m,
            $messages,
        );

        return BatchResult::fromArray($this->transport->request(
            'POST',
            '/messages/batch',
            // `items`, which is what the API's MessageBatchPush declares. The name matters: a
            // batch sent under any other key is a 422 that only shows up against a real server.
            ['items' => $items],
            idempotencyKey: $idempotencyKey,
        ));
    }

    /**
     * One message and its delivery status.
     *
     * Delivery and opens arrive asynchronously, so this is the poll-shaped answer to the same
     * question a webhook subscription answers by being told.
     */
    public function get(string $publicId): Message
    {
        return Message::fromArray($this->transport->request(
            'GET',
            '/messages/' . rawurlencode($publicId),
            route: '/messages/{id}',
        ));
    }

    /**
     * One page of messages, newest first.
     *
     * Keyset-paginated: pass the previous page's `nextCursor` back as `$cursor`. Prefer
     * {@see self::each()} unless you are pausing between pages, for instance to render them.
     * Offsets are deliberately not offered: a page 3 read while new messages arrive shows rows
     * page 2 already did.
     */
    public function list(?string $recipientEmail = null, ?int $limit = null, ?string $cursor = null): MessagePage
    {
        return MessagePage::fromArray($this->transport->request('GET', '/messages', query: [
            'recipient_email' => $recipientEmail,
            'limit' => $limit,
            'cursor' => $cursor,
        ]));
    }

    /**
     * Iterate every message, following cursors.
     *
     * A generator rather than an array: a merchant with a year of history should not have to hold
     * it in memory to count it.
     *
     * Stops if the server ever hands back a cursor it has already given, rather than looping on
     * the same page forever. That is a server bug if it happens, but the shape it takes in a
     * merchant's process is a worker that never returns and a generator that never ends, which is
     * far harder to diagnose than the exception thrown here.
     *
     * @return \Generator<int, Message>
     *
     * @throws BeaconBoxException when pagination does not advance
     */
    public function each(?string $recipientEmail = null, int $pageSize = 50): \Generator
    {
        $cursor = null;
        $seen = [];
        do {
            $page = $this->list($recipientEmail, $pageSize, $cursor);
            yield from $page->items;
            $cursor = $page->nextCursor;

            if ($cursor === null || $cursor === '') {
                return;
            }
            if (isset($seen[$cursor])) {
                throw new BeaconBoxException(sprintf(
                    'BeaconBox: pagination did not advance (cursor "%s" repeated). '
                    . 'Stopping rather than looping forever.',
                    $cursor,
                ));
            }
            $seen[$cursor] = true;
        } while (true);
    }

    /**
     * Send the SMS for a message whose text never went out.
     *
     * The recovery path for a `skippedReason`, most often an empty balance that has since been
     * topped up.
     *
     * Refused with a {@see \BeaconBox\Exception\ConflictException} if one is already queued or
     * delivered: the only thing a second send would add is a second charge and a second
     * interruption.
     */
    public function resendSms(string $publicId, ?string $idempotencyKey = null): SmsOutcome
    {
        return SmsOutcome::fromArray($this->transport->request(
            'POST',
            '/messages/' . rawurlencode($publicId) . '/sms',
            idempotencyKey: $idempotencyKey,
            route: '/messages/{id}/sms',
        ));
    }

    /**
     * The same, on WhatsApp.
     *
     * A separate call rather than a channel parameter, because a request naming both channels
     * would have to mean charging twice and interrupting twice for one update.
     */
    public function resendWhatsApp(string $publicId, ?string $idempotencyKey = null): WhatsAppOutcome
    {
        return WhatsAppOutcome::fromArray($this->transport->request(
            'POST',
            '/messages/' . rawurlencode($publicId) . '/whatsapp',
            idempotencyKey: $idempotencyKey,
            route: '/messages/{id}/whatsapp',
        ));
    }

    /**
     * Take a message back, and call off any nudge still queued for it.
     *
     * Marked withdrawn for the recipient rather than deleted: they may already have read it. A
     * cancelled send costs nothing, because credits are charged at send time.
     *
     * Idempotent: a second retraction reports `retracted: false` and the current status, which is
     * not a failure. Check `alreadyNotified` to learn whether you were in time. True means an
     * email or text about it is already out and cannot be recalled.
     */
    public function retract(string $publicId, ?string $idempotencyKey = null): RetractResult
    {
        return RetractResult::fromArray($this->transport->request(
            'POST',
            '/messages/' . rawurlencode($publicId) . '/retract',
            idempotencyKey: $idempotencyKey,
            route: '/messages/{id}/retract',
        ));
    }
}

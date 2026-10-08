<?php

declare(strict_types=1);

namespace BeaconBox\Resource;

use BeaconBox\Model\RecipientErasureReport;
use BeaconBox\Model\RecipientSms;
use BeaconBox\Model\WhatsAppErasureReceipt;

/**
 * A recipient's contact details, and their right to be forgotten.
 *
 * Reads return a **masked** number: an API key that leaks should not be usable to dump a phone
 * book.
 *
 * Two erasures, of different reach. {@see self::erase()} is the whole Article 17 request for your
 * business; {@see self::eraseWhatsApp()} removes only their WhatsApp history.
 */
final class Recipients extends BaseResource
{
    /** This recipient's stored number (masked) and SMS consent state. */
    public function sms(string $email): RecipientSms
    {
        return RecipientSms::fromArray(
            $this->transport->request(
                'GET',
                '/recipients/' . rawurlencode($email) . '/sms',
                route: '/recipients/{email}/sms',
            ),
        );
    }

    /**
     * Store a number, normalised to E.164.
     *
     * Unlike a push, this one fails loudly: storing the number is the entire request, so an
     * unparseable number is a 422 and a clash with another of your recipients is a 409.
     *
     * A number that cannot be a recipient's phone is refused the same way (`sms.phone_invalid`):
     * premium-rate, toll-free, shared-cost, voicemail and service numbers, and the satellite and
     * international codes that belong to no country (+800, +808, +870, +878, +881, +882, +883,
     * +888, +979). A number in those ranges stored before they were refused stays stored, and a
     * push skips SMS to it as `country_not_allowed`.
     */
    public function setPhone(string $email, string $phone): RecipientSms
    {
        return RecipientSms::fromArray($this->transport->request(
            'PUT',
            '/recipients/' . rawurlencode($email) . '/phone',
            ['phone' => $phone],
            route: '/recipients/{email}/phone',
        ));
    }

    /** Forget the number. Their inbox and their email nudges are unaffected. */
    public function clearPhone(string $email): RecipientSms
    {
        return RecipientSms::fromArray(
            $this->transport->request(
                'DELETE',
                '/recipients/' . rawurlencode($email) . '/phone',
                route: '/recipients/{email}/phone',
            ),
        );
    }

    /**
     * Erase this recipient's WhatsApp history for your business. **Not undoable.**
     *
     * The API half of an Article 17 request: it deletes their stored replies, removes the copies
     * inside webhook deliveries still queued for your endpoint, and withdraws WhatsApp consent
     * permanently for your business.
     *
     * **Read `repliesAForwardEmailMayHaveCarried` in the receipt.** It counts erased replies whose
     * words may already be sitting in *your* mailboxes, which nothing here can reach. That number
     * is your remaining work, and until you act on it the erasure is only ours.
     *
     * `$email` must be an email address. A blank value or one that is not an address throws
     * before anything is sent (the API would answer 422).
     *
     * @throws \InvalidArgumentException when `$email` is blank or not an email address
     */
    public function eraseWhatsApp(string $email, ?string $idempotencyKey = null): WhatsAppErasureReceipt
    {
        return WhatsAppErasureReceipt::fromArray($this->transport->request(
            'POST',
            '/recipients/' . rawurlencode(self::erasableAddress($email)) . '/whatsapp/erase',
            idempotencyKey: $idempotencyKey,
            route: '/recipients/{email}/whatsapp/erase',
        ));
    }

    /**
     * Erase everything held about this recipient for your business. **Not undoable.**
     *
     * The API half of an Article 17 request made to you. **Deleted:** the messages you sent them
     * (subjects and bodies), their delivery and open events, their SMS and WhatsApp send records
     * with the number each went to, the links that opened those messages, their WhatsApp replies,
     * and their recipient record (phone number, SMS and WhatsApp consent, unsubscribe state). The
     * copies inside webhook deliveries still queued for your endpoint and inside stored API
     * responses are scrubbed, and sends still waiting to go out are cancelled. Your audit log keeps
     * its entries with the address replaced by a keyed hash.
     *
     * **Retained, on purpose:** email suppressions for the address (hard bounces, complaints, and
     * an unsubscribe, which is kept as a suppression) and SMS or WhatsApp opt-outs for their
     * number. They are what stop this person being contacted again by a later push, and an
     * opt-out erased on request stops being one. **Erasing does not undo an unsubscribe:** a later
     * push to the address finds them still unsubscribed. The report counts both
     * (`$suppressionsKept`, `$stopsKept`). Other businesses' records about the same person are
     * untouched.
     *
     * **Copies that already left BeaconBox are yours to erase:** webhook deliveries your endpoint
     * received, and the WhatsApp replies your forward email sent to your mailboxes, counted in
     * `$repliesAForwardEmailMayHaveCarried`.
     *
     * It is a `POST` and carries an `Idempotency-Key` like every other. A repeat erasure truthfully
     * reports zeros, so retrying a timed-out call with the **same** key is how you get the original
     * counts back. An address you never messaged also answers with zeros, so this cannot be used
     * to test whether an address is on your list.
     *
     * `$email` must be an email address. A blank value or one that is not an address throws
     * before anything is sent (the API would answer 422), so a slip in the value you pass fails at
     * your line rather than as a server validation error.
     *
     * ```php
     * $report = $client->recipients->erase('buyer@example.com');
     * echo $report->messagesDeleted, ' ', $report->suppressionsKept, ' ', $report->stopsKept;
     * ```
     *
     * @throws \InvalidArgumentException when `$email` is blank or not an email address
     */
    public function erase(string $email, ?string $idempotencyKey = null): RecipientErasureReport
    {
        return RecipientErasureReport::fromArray($this->transport->request(
            'POST',
            '/recipients/' . rawurlencode(self::erasableAddress($email)) . '/erase',
            idempotencyKey: $idempotencyKey,
            route: '/recipients/{email}/erase',
        ));
    }

    /**
     * The address an erasure is for, trimmed, or an exception before anything is sent.
     *
     * The API answers 422 for anything that is not an email address, so this costs nothing it
     * would not refuse anyway. It is here because an erasure is the one call where a slip should
     * fail as early and as plainly as possible: a blank or mangled value from a form or a CSV
     * should say so at the line that passed it, not as a validation error from the server.
     *
     * Deliberately loose (something, `@`, a dotted domain, no whitespace) so that no address the
     * server would accept is refused here. The server's check stays the authority.
     */
    private static function erasableAddress(string $email): string
    {
        $cleaned = trim($email);
        $at = strrpos($cleaned, '@');
        $local = $at === false ? '' : substr($cleaned, 0, $at);
        $domain = $at === false ? '' : trim(substr($cleaned, $at + 1), '.');

        if ($local === '' || !str_contains($domain, '.') || preg_match('/\s/u', $cleaned) !== 0) {
            // The value is not quoted: it is whatever the caller had instead of an address (a
            // name, a phone number), and this message ends up in a log.
            throw new \InvalidArgumentException(sprintf(
                'BeaconBox: erasing a recipient needs their email address, and the value given %s. '
                . 'Nothing was sent.',
                $cleaned === '' ? 'is blank' : 'is not an email address',
            ));
        }

        return $cleaned;
    }
}

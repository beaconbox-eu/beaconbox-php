<?php

declare(strict_types=1);

namespace BeaconBox\Resource;

use BeaconBox\Model\RecipientSms;
use BeaconBox\Model\WhatsAppErasureReceipt;

/**
 * A recipient's contact details, and their right to be forgotten.
 *
 * Reads return a **masked** number: an API key that leaks should not be usable to dump a phone
 * book.
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
     */
    public function eraseWhatsApp(string $email, ?string $idempotencyKey = null): WhatsAppErasureReceipt
    {
        return WhatsAppErasureReceipt::fromArray($this->transport->request(
            'POST',
            '/recipients/' . rawurlencode($email) . '/whatsapp/erase',
            idempotencyKey: $idempotencyKey,
            route: '/recipients/{email}/whatsapp/erase',
        ));
    }
}

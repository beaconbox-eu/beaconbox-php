<?php

declare(strict_types=1);

namespace BeaconBox\Resource;

use BeaconBox\Model\CreditBalance;

/** The prepaid balance. One balance, shared by SMS and WhatsApp. */
final class Credits extends BaseResource
{
    /**
     * Credits remaining, and whether that is below your low-balance threshold.
     *
     * SMS and WhatsApp stop at zero. Email is unaffected, so a customer never stops receiving
     * their order updates because a balance ran out.
     */
    public function balance(): CreditBalance
    {
        return CreditBalance::fromArray($this->transport->request('GET', '/credits'));
    }
}

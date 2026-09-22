<?php

declare(strict_types=1);

namespace BeaconBox\Resource;

use BeaconBox\Model\ApiKey;
use BeaconBox\Model\NewApiKey;
use BeaconBox\Model\Parse;

/**
 * API keys.
 *
 * A minted key is shown **once**. BeaconBox stores a hash, so the create response is the only
 * place the full value will ever exist.
 */
final class Keys extends BaseResource
{
    /**
     * Your keys, masked. Useful for auditing what exists and revoking what should not.
     *
     * @return list<ApiKey>
     */
    public function list(): array
    {
        $payload = $this->transport->request('GET', '/keys');

        return array_map(ApiKey::fromArray(...), Parse::objectList($payload, 'items'));
    }

    /**
     * Mint a key. Name it after the service that will hold it, not the person creating it.
     *
     * The returned `->key` is unrecoverable. Write it to a secret store before the process exits.
     * Its `var_dump` output is redacted so an incidental debug line cannot leak it, so read the
     * property explicitly.
     */
    public function create(?string $name = null, ?string $idempotencyKey = null): NewApiKey
    {
        return NewApiKey::fromArray($this->transport->request(
            'POST',
            '/keys',
            ['name' => $name],
            idempotencyKey: $idempotencyKey,
        ));
    }

    /**
     * Revoke a key immediately. In-flight requests using it start failing with 401.
     *
     * Revoking the key this client is authenticated with is allowed, and is the correct response
     * to a leak even though the next call from this client will fail.
     */
    public function revoke(string $keyId): void
    {
        $this->transport->request('DELETE', '/keys/' . rawurlencode($keyId), route: '/keys/{id}');
    }
}

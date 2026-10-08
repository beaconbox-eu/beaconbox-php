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
     *
     * **A key minted with a key is revoked with it**, and with any key above it, unless it was
     * minted with `detach: true`. To rotate, pass `detach: true`: the new key keeps working after
     * you revoke the key this client uses. Mint it, move your systems to it, then {@see revoke()}
     * the old one. A leaked key can mint a detached key too, so after revoking one, list the keys
     * and revoke any whose `mintedBy` is the leaked key's id.
     *
     * Refused with a {@see \BeaconBox\Exception\PermissionException} (`plan.read_only`) while your
     * plan has lapsed: pay, and minting works again.
     */
    public function create(
        ?string $name = null,
        ?string $idempotencyKey = null,
        bool $detach = false,
    ): NewApiKey {
        $body = ['name' => $name];
        // Sent only when asked for, so a plain create is the same request it always was.
        if ($detach) {
            $body['detach'] = true;
        }

        return NewApiKey::fromArray($this->transport->request(
            'POST',
            '/keys',
            $body,
            idempotencyKey: $idempotencyKey,
        ));
    }

    /**
     * Revoke a key immediately. In-flight requests using it start failing with 401.
     *
     * Revoking the key this client is authenticated with is allowed, and is the correct response
     * to a leak even though the next call from this client will fail. Every key minted with it (and
     * not detached) is revoked too, and the keys those minted in turn. A detached key it minted is
     * not: find those by `mintedBy` in {@see list()}.
     */
    public function revoke(string $keyId): void
    {
        $this->transport->request('DELETE', '/keys/' . rawurlencode($keyId), route: '/keys/{id}');
    }
}

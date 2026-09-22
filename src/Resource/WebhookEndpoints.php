<?php

declare(strict_types=1);

namespace BeaconBox\Resource;

use BeaconBox\Enum\WebhookEventType;
use BeaconBox\Model\Parse;
use BeaconBox\Model\WebhookEndpoint;

/**
 * Where BeaconBox pushes delivery outcomes.
 *
 * Verify what arrives with {@see \BeaconBox\Webhooks::verify()}.
 */
final class WebhookEndpoints extends BaseResource
{
    /**
     * Registered endpoints, secrets masked.
     *
     * Worth checking `->disabled` here: an endpoint switched off after sustained delivery failure
     * is silent, and silence looks the same as nothing having happened.
     *
     * @return list<WebhookEndpoint>
     */
    public function list(): array
    {
        $payload = $this->transport->request('GET', '/webhook-endpoints');

        return array_map(WebhookEndpoint::fromArray(...), Parse::objectList($payload, 'items'));
    }

    /**
     * Register an endpoint. The returned `->secret` is the **real** signing secret, once.
     *
     * `$url` must be https and must resolve to a public address. Private, loopback and link-local
     * addresses are refused, and redirects are never followed, because a public URL that redirects
     * to an internal one is the cheapest way around a firewall.
     *
     * **Leave `$eventTypes` empty to receive everything**, including types added later. That is
     * the recommended setting: a narrow subscription is how a new event type silently passes an
     * integration by.
     *
     * @param list<WebhookEventType|string> $eventTypes
     */
    public function create(
        string $url,
        array $eventTypes = [],
        string $description = '',
        ?string $idempotencyKey = null,
    ): WebhookEndpoint {
        return WebhookEndpoint::fromArray($this->transport->request(
            'POST',
            '/webhook-endpoints',
            [
                'url' => $url,
                'event_types' => array_map(
                    static fn (WebhookEventType|string $t): string => $t instanceof WebhookEventType ? $t->value : $t,
                    $eventTypes,
                ),
                'description' => $description,
            ],
            idempotencyKey: $idempotencyKey,
        ));
    }

    /**
     * Delete an endpoint. Deliveries already queued for it stop.
     *
     * Also the way to re-enable one that was auto-disabled: delete it and register it again, which
     * rotates the secret.
     */
    public function delete(string $publicId): void
    {
        $this->transport->request(
            'DELETE',
            '/webhook-endpoints/' . rawurlencode($publicId),
            route: '/webhook-endpoints/{id}',
        );
    }
}

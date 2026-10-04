<?php

declare(strict_types=1);

namespace BeaconBox\Resource;

use BeaconBox\Enum\WebhookEventType;
use BeaconBox\Model\Parse;
use BeaconBox\Model\WebhookEndpoint;
use BeaconBox\Model\WebhookTestResult;

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
     * Send one sample `ping` event to this endpoint and report what came back.
     *
     * **The only way to find out your handler works before a real event depends on it.** A wrong
     * webhook fails silently by nature: your handler is simply never called, and nothing on
     * either side says so.
     *
     * The event type is `ping`, deliberately not a real one — a test carrying `message.delivered`
     * would be indistinguishable from the genuine article at your end. It is signed exactly like
     * a real delivery, so it also proves your signature verification.
     *
     * Never throws for a failure at *your* end: read `->delivered`. Testing does not affect the
     * endpoint's health counters, so press it as often as you like while fixing a handler.
     */
    public function test(string $publicId): WebhookTestResult
    {
        $payload = $this->transport->request(
            'POST',
            '/webhook-endpoints/' . rawurlencode($publicId) . '/test',
            route: '/webhook-endpoints/{id}/test',
        );

        return WebhookTestResult::fromArray($payload);
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

<?php

declare(strict_types=1);

namespace BeaconBox\Tests\Live;

use BeaconBox\BeaconBoxClient;
use BeaconBox\Enum\MessageKind;
use BeaconBox\Enum\MessageStatus;
use BeaconBox\Exception\ConflictException;
use BeaconBox\Exception\ResourceMissingException;
use BeaconBox\Model\MessagePush;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The SDK against a real BeaconBox.
 *
 * Skipped unless `BEACONBOX_LIVE_URL` and `BEACONBOX_API_KEY` are set, so `composer test` with no
 * setup stays hermetic and fast. `make live` in `sdk/` sets both from a fresh seed.
 *
 * **What this catches that the unit suite cannot.** Every unit test asserts against a payload this
 * repository wrote. If the SDK and the API disagree about a field name, both sides of a unit test
 * agree with each other and are wrong together. That is exactly how `pushBatch` shipped sending
 * `{"messages": ...}` against an API that reads `{"items": ...}`: unit tests green, every real
 * call a 422. So the assertions here are deliberately shallow. What matters is that the server
 * accepted the request and the SDK understood the answer.
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    private static ?BeaconBoxClient $client = null;
    private static string $recipient = 'live-sdk@example.com';

    protected function setUp(): void
    {
        $url = getenv('BEACONBOX_LIVE_URL');
        $key = getenv('BEACONBOX_API_KEY');
        if ($url === false || $url === '' || $key === false || $key === '') {
            self::markTestSkipped('live tests need BEACONBOX_LIVE_URL and BEACONBOX_API_KEY');
        }

        $recipient = getenv('BEACONBOX_LIVE_RECIPIENT');
        if (\is_string($recipient) && $recipient !== '') {
            self::$recipient = $recipient;
        }

        // The local stack serves https with a mkcert CA. Pointing the client at it exercises the
        // same option a merchant behind a TLS-inspecting corporate proxy needs, rather than
        // turning verification off and leaving the SDK's security default untested here.
        self::$client ??= new BeaconBoxClient($key, $url, caBundle: self::caBundle());
    }

    private static function caBundle(): ?string
    {
        $bundle = getenv('BEACONBOX_LIVE_CA_BUNDLE');

        return \is_string($bundle) && $bundle !== '' ? $bundle : null;
    }

    private function client(): BeaconBoxClient
    {
        self::assertNotNull(self::$client);

        return self::$client;
    }

    /**
     * A fresh subject per run.
     *
     * Necessary rather than tidy: an `Updateable` push matches on subject, so a reused one would
     * update the previous run's message and this suite would stop testing creation.
     */
    private function unique(string $prefix): string
    {
        return $prefix . ' ' . bin2hex(random_bytes(4));
    }

    // --- Messages ------------------------------------------------------------------------

    public function testPushAndReadBack(): void
    {
        $subject = $this->unique('SDK live push');

        $result = $this->client()->messages->push(new MessagePush(
            recipientEmail: self::$recipient,
            subject: $subject,
            body: 'Sent by the PHP SDK live suite.',
        ));

        self::assertNotSame('', $result->id);
        self::assertTrue($result->created);
        self::assertSame($subject, $result->subject);
        self::assertSame(MessageStatus::Active, $result->status);

        $fetched = $this->client()->messages->get($result->id);
        self::assertSame($result->id, $fetched->id);
        self::assertSame(self::$recipient, $fetched->recipientEmail);
    }

    public function testUpdateablePushUpdatesInPlace(): void
    {
        // The behaviour a merchant most depends on, and the one a mock cannot prove.
        $subject = $this->unique('SDK live updateable');

        $first = $this->client()->messages->push(new MessagePush(
            self::$recipient,
            $subject,
            'Estimated delivery Thursday.',
            kind: MessageKind::Updateable,
        ));
        $second = $this->client()->messages->push(new MessagePush(
            self::$recipient,
            $subject,
            'Estimated delivery Friday.',
            kind: MessageKind::Updateable,
        ));

        self::assertTrue($first->created);
        self::assertFalse($second->created, 'a repeated updateable subject created a second message');
        self::assertSame($first->id, $second->id);
    }

    public function testIdempotencyKeyCollapsesARepeat(): void
    {
        // The property the whole retry design rests on, proven against the real store. Two
        // identical pushes under one key must be one message, not two. If this ever fails, every
        // retry this SDK makes is a duplicate to a real customer.
        $key = 'live-' . bin2hex(random_bytes(8));
        $push = new MessagePush(self::$recipient, $this->unique('SDK live idempotency'), 'Sent twice.');

        $first = $this->client()->messages->push($push, $key);
        $second = $this->client()->messages->push($push, $key);

        self::assertSame($first->id, $second->id);
    }

    public function testBatchIsAccepted(): void
    {
        // The regression test for the `items` field name. A batch under any other key 422s.
        $result = $this->client()->messages->pushBatch([
            new MessagePush(self::$recipient, $this->unique('SDK live batch a'), 'One.'),
            new MessagePush(self::$recipient, $this->unique('SDK live batch b'), 'Two.'),
        ]);

        self::assertSame(0, $result->failed, 'batch items were rejected: ' . json_encode(
            array_map(static fn ($i) => $i->raw, $result->failures()),
        ));
        self::assertSame(2, $result->succeeded);
    }

    public function testListAndEach(): void
    {
        $page = $this->client()->messages->list(self::$recipient, 2);
        self::assertLessThanOrEqual(2, \count($page->items));

        $seen = 0;
        foreach ($this->client()->messages->each(self::$recipient, 2) as $_) {
            ++$seen;
            if ($seen >= 3) { // enough to prove the cursor was followed past page one
                break;
            }
        }
        self::assertGreaterThan(0, $seen);
    }

    public function testRetract(): void
    {
        $pushed = $this->client()->messages->push(new MessagePush(
            self::$recipient,
            $this->unique('SDK live retract'),
            'This one gets withdrawn.',
        ));

        $result = $this->client()->messages->retract($pushed->id);

        self::assertTrue($result->retracted);
        self::assertSame(MessageStatus::Retracted, $result->status);
        // Idempotent: the second call reports that it changed nothing, and does not fail.
        self::assertFalse($this->client()->messages->retract($pushed->id)->retracted);
    }

    public function testAnUnknownIdIsA404(): void
    {
        $this->expectException(ResourceMissingException::class);
        $this->client()->messages->get('m_definitelynotreal');
    }

    // --- Other resources -----------------------------------------------------------------

    public function testCreditsBalance(): void
    {
        $balance = $this->client()->credits->balance();

        self::assertGreaterThanOrEqual(0, $balance->threshold);
    }

    public function testRecipientPhoneRoundTrip(): void
    {
        $stored = $this->client()->recipients->setPhone(self::$recipient, '+37255550134');
        self::assertNotNull($stored->phone);

        $read = $this->client()->recipients->sms(self::$recipient);
        self::assertSame(self::$recipient, $read->recipientEmail);

        $cleared = $this->client()->recipients->clearPhone(self::$recipient);
        self::assertNull($cleared->phone);
    }

    public function testKeysCreateListRevoke(): void
    {
        $created = $this->client()->keys->create('sdk-live-' . bin2hex(random_bytes(3)));
        self::assertStringStartsWith('bbx_live_', $created->key);

        try {
            $names = array_map(static fn ($k): string => $k->name, $this->client()->keys->list());
            self::assertContains($created->name, $names);
        } finally {
            $this->revokeByName($created->name);
        }
    }

    public function testAMintedKeyActuallyWorks(): void
    {
        // End to end on the credential itself, which no unit test can reach.
        $created = $this->client()->keys->create('sdk-live-usable-' . bin2hex(random_bytes(3)));

        try {
            $url = (string) getenv('BEACONBOX_LIVE_URL');
            $minted = new BeaconBoxClient($created->key, $url, caBundle: self::caBundle());

            self::assertGreaterThanOrEqual(0, $minted->credits->balance()->threshold);
        } finally {
            $this->revokeByName($created->name);
        }
    }

    public function testWebhookEndpoints(): void
    {
        $url = 'https://example.com/hooks/' . bin2hex(random_bytes(4));
        $created = $this->client()->webhookEndpoints->create($url, description: 'sdk live suite');

        try {
            // The secret is real exactly once, and it is what Webhooks::verify needs.
            self::assertStringStartsWith('whsec_', $created->secret);
            self::assertGreaterThan(20, \strlen($created->secret), 'create returned a masked secret');

            $ids = array_map(static fn ($e): string => $e->id, $this->client()->webhookEndpoints->list());
            self::assertContains($created->id, $ids);
        } finally {
            $this->client()->webhookEndpoints->delete($created->id);
        }
    }

    public function testADuplicateEndpointUrlConflicts(): void
    {
        $url = 'https://example.com/hooks/' . bin2hex(random_bytes(4));
        $created = $this->client()->webhookEndpoints->create($url);

        try {
            $this->expectException(ConflictException::class);
            $this->client()->webhookEndpoints->create($url);
        } finally {
            $this->client()->webhookEndpoints->delete($created->id);
        }
    }

    /** Find our row by name: create returns the secret, list returns the id. */
    private function revokeByName(string $name): void
    {
        foreach ($this->client()->keys->list() as $key) {
            if ($key->name === $name) {
                $this->client()->keys->revoke($key->id);

                return;
            }
        }
    }
}

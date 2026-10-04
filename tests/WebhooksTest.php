<?php

declare(strict_types=1);

namespace BeaconBox\Tests;

use BeaconBox\Enum\WebhookEventType;
use BeaconBox\Exception\BeaconBoxException;
use BeaconBox\Exception\WebhookVerificationException;
use BeaconBox\Webhooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Webhook verification, which is the one place in this SDK where a bug is a security hole.
 *
 * The signatures here are computed the way the API computes them, from the API's own source:
 * HMAC-SHA256 over `"<timestamp>.<raw body>"`. If this file and `services/webhooks_out.sign` ever
 * disagree, one of them is wrong and every merchant integration finds out at the same time.
 */
final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_' . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NOW = 1_755_680_000;
    private const BODY = '{"id":"evt_1","type":"message.bounced",'
        . '"occurred_at":"2026-08-20T09:20:00Z","data":{"message_id":"m_1"}}';

    private function sign(?string $body = null, ?string $secret = null, ?int $timestamp = null): string
    {
        $timestamp ??= self::NOW;
        $digest = hash_hmac('sha256', $timestamp . '.' . ($body ?? self::BODY), $secret ?? self::SECRET);

        return "t={$timestamp},v1={$digest}";
    }

    // --- Accepts -------------------------------------------------------------------------

    public function testAGenuineDelivery(): void
    {
        $event = Webhooks::verify(self::BODY, $this->sign(), self::SECRET, now: self::NOW);

        self::assertSame('evt_1', $event->id);
        self::assertSame(WebhookEventType::MessageBounced, $event->type);
        self::assertSame(['message_id' => 'm_1'], $event->data);
        self::assertSame('2026-08-20T09:20:00+00:00', $event->occurredAt->format(\DateTimeInterface::RFC3339));
    }

    public function testADeliveryInsideTheToleranceWindow(): void
    {
        $event = Webhooks::verify(
            self::BODY,
            $this->sign(timestamp: self::NOW - 299),
            self::SECRET,
            now: self::NOW,
        );

        self::assertSame('evt_1', $event->id);
    }

    public function testClockSkewInEitherDirection(): void
    {
        // A receiver's clock can be ahead of ours as easily as behind.
        $event = Webhooks::verify(
            self::BODY,
            $this->sign(timestamp: self::NOW + 100),
            self::SECRET,
            now: self::NOW,
        );

        self::assertSame('evt_1', $event->id);
    }

    public function testAnUnknownEventTypeStillVerifies(): void
    {
        // A type added after this SDK shipped is a delivery to handle, not a forgery.
        $body = '{"id":"evt_2","type":"parcel.collected","occurred_at":"2026-08-20T09:20:00Z","data":{}}';

        $event = Webhooks::verify($body, $this->sign($body), self::SECRET, now: self::NOW);

        self::assertSame('parcel.collected', $event->type);
    }

    public function testAnUppercaseHexSignatureStillVerifies(): void
    {
        // Hex is hex. `hash_hmac` returns it lowercase and `hash_equals` compares bytes, so
        // without normalising the incoming value a sender that spelled the same digest in
        // uppercase would be refused: a false negative on a signature that is correct.
        $header = strtoupper($this->sign());
        $header = str_replace(['T=', 'V1='], ['t=', 'v1='], $header);

        $event = Webhooks::verify(self::BODY, $header, self::SECRET, now: self::NOW);

        self::assertSame('evt_1', $event->id);
    }

    public function testExtraSignaturePairsAreTolerated(): void
    {
        // So that a future `v2=` scheme does not make every deployed verifier reject the delivery
        // outright.
        $header = $this->sign() . ',v2=notyetimplemented';

        $event = Webhooks::verify(self::BODY, $header, self::SECRET, now: self::NOW);

        self::assertSame('evt_1', $event->id);
    }

    // --- Rejects -------------------------------------------------------------------------

    public function testATamperedBody(): void
    {
        $tampered = str_replace('m_1', 'm_2', self::BODY);

        $this->expectExceptionMessage('does not match');
        Webhooks::verify($tampered, $this->sign(), self::SECRET, now: self::NOW);
    }

    public function testTheWrongSecret(): void
    {
        $this->expectExceptionMessage('does not match');
        Webhooks::verify(
            self::BODY,
            $this->sign(secret: 'whsec_' . str_repeat('b', 64)),
            self::SECRET,
            now: self::NOW,
        );
    }

    public function testAReplayedDelivery(): void
    {
        // The timestamp is inside the signed string, so a captured delivery cannot be re-sent
        // later with a fresh one.
        $this->expectExceptionMessage('tolerance');
        Webhooks::verify(self::BODY, $this->sign(timestamp: self::NOW - 3600), self::SECRET, now: self::NOW);
    }

    public function testAMovedTimestampBreaksTheSignature(): void
    {
        // Proves the timestamp is signed rather than merely sent alongside.
        $header = str_replace('t=' . self::NOW, 't=' . (self::NOW - 10), $this->sign());

        $this->expectExceptionMessage('does not match');
        Webhooks::verify(self::BODY, $header, self::SECRET, now: self::NOW - 10);
    }

    /** @return list<array{string}> */
    public static function malformedHeaders(): array
    {
        return [
            [''],
            ['garbage'],
            ['t=,v1='],
            ['v1=abc'],
            ['t=' . self::NOW],
            ['t=notanumber,v1=abc'],
            ['t=0,v1=abc'],
            // Spellings a looser integer parse would accept. The timestamp is inside the signed
            // string, so it has exactly one canonical form and every alternative is a second way
            // to write the same header.
            ['t=+' . self::NOW . ',v1=abc'],
            ['t= ' . self::NOW . ' ,v1=abc'],
            ['t=1_700_000_000,v1=abc'],
            // Twenty-one digits and up. `ctype_digit` alone accepted any length and left `(int)`
            // to saturate at PHP_INT_MAX, so an absurd value became a silently plausible one.
            ['t=' . str_repeat('9', 21) . ',v1=abc'],
            ['t=' . str_repeat('9', 5000) . ',v1=abc'],
        ];
    }

    #[DataProvider('malformedHeaders')]
    public function testAMalformedHeader(string $header): void
    {
        $this->expectException(WebhookVerificationException::class);
        Webhooks::verify(self::BODY, $header, self::SECRET, now: self::NOW);
    }

    public function testTheLongestTimestampItAcceptsIsStillHandled(): void
    {
        // The boundary: twenty digits parses, and is then simply far outside tolerance. Asserted so
        // nobody tightens the bound below a real Unix timestamp.
        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/seconds off/');

        Webhooks::verify(self::BODY, 't=' . str_repeat('9', 20) . ',v1=abc', self::SECRET, now: self::NOW);
    }

    public function testABodyThatIsNotJson(): void
    {
        $body = 'not json at all';

        $this->expectExceptionMessage('not a JSON object');
        Webhooks::verify($body, $this->sign($body), self::SECRET, now: self::NOW);
    }

    public function testAJsonArrayIsRefusedRatherThanDecodedIntoANulledEvent(): void
    {
        // A JSON array decodes to a PHP array too, so an `is_array` check alone would wave this
        // through and hand the caller an event with every field null instead of an exception.
        $body = '[1,2,3]';

        $this->expectExceptionMessage('not a JSON object');
        Webhooks::verify($body, $this->sign($body), self::SECRET, now: self::NOW);
    }

    public function testAnEmptyObjectIsStillAcceptedAsOne(): void
    {
        // The guard above must not catch this: `json_decode('{}', true)` is `[]`, which is also
        // what an empty JSON array decodes to, and refusing it would reject a valid object.
        $body = '{}';

        $event = Webhooks::verify($body, $this->sign($body), self::SECRET, now: self::NOW);

        self::assertSame('', $event->id);
    }

    public function testReEncodingTheBodyBreaksVerification(): void
    {
        // The single most common way to get this wrong, demonstrated. Round-tripping through
        // json_decode/json_encode changes the bytes over separators and key order. It passes in
        // development against a payload that happens to survive it, and fails in production
        // against one that does not. Pass the raw body.
        $reEncoded = json_encode(json_decode(self::BODY, true), JSON_PRETTY_PRINT);
        self::assertIsString($reEncoded);
        self::assertNotSame(self::BODY, $reEncoded);

        $this->expectException(WebhookVerificationException::class);
        Webhooks::verify($reEncoded, $this->sign(), self::SECRET, now: self::NOW);
    }

    // --- Contract ------------------------------------------------------------------------

    public function testTheExceptionIsCatchableAsTheLibrarysBaseType(): void
    {
        // So `catch (BeaconBoxException)` around a whole handler still covers verification.
        try {
            Webhooks::verify(self::BODY, 'garbage', self::SECRET, now: self::NOW);
            self::fail('expected a verification failure');
        } catch (BeaconBoxException $thrown) {
            self::assertInstanceOf(WebhookVerificationException::class, $thrown);
        }
    }

    public function testComparisonIsConstantTime(): void
    {
        // A `===` on the hex digest leaks, through timing, how much of a guess was right.
        // Asserted structurally rather than by measuring: a timing assertion in a test suite is a
        // flake on a loaded CI runner.
        $source = (string) file_get_contents(__DIR__ . '/../src/Webhooks.php');

        self::assertStringContainsString('hash_equals(', $source);
        self::assertStringNotContainsString('=== $signature', $source);
    }

    /**
     * **The error a merchant actually hits, and the one most likely to be misread.**
     *
     * "Signature does not match" sounds like a key problem. The overwhelmingly common cause is
     * not: the bytes being checked are not the bytes that were sent, because something between
     * the wire and the check decoded and re-encoded the JSON. Found by using our own product — a
     * body copied out of a webhook inspector's "copy as curl" failed exactly this way, and the
     * first instinct was to go looking at the secret.
     *
     * Reproduced from its real cause rather than from a corrupted byte: the re-encoded body is
     * semantically identical and still cannot verify, which is why it confuses people.
     */
    public function testAReEncodedBodyIsWhatFails(): void
    {
        $reEncoded = json_encode(json_decode(self::BODY, true), JSON_PRETTY_PRINT);
        self::assertIsString($reEncoded);
        self::assertSame(
            json_decode(self::BODY, true),
            json_decode($reEncoded, true),
            'must differ only in formatting',
        );

        try {
            Webhooks::verify($reEncoded, $this->sign(), self::SECRET, now: self::NOW);
            self::fail('a re-encoded body must not verify');
        } catch (WebhookVerificationException $e) {
            self::assertStringContainsString('raw request bytes', $e->getMessage());
        }
    }

    /**
     * Ordering is the point: somebody who reads "check your secret" first rotates a working
     * secret before thinking about the body.
     */
    public function testTheMessageNamesTheLikelyCauseBeforeTheSecret(): void
    {
        try {
            Webhooks::verify('{"tampered":true}', $this->sign(), self::SECRET, now: self::NOW);
            self::fail('a tampered body must not verify');
        } catch (WebhookVerificationException $e) {
            $message = $e->getMessage();
            self::assertLessThan(
                strpos($message, 'secret'),
                strpos($message, 'raw request bytes'),
            );
        }
    }
}

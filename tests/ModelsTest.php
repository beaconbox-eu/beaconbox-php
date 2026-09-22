<?php

declare(strict_types=1);

namespace BeaconBox\Tests;

use BeaconBox\Enum\Channel;
use BeaconBox\Enum\EmailSkipReason;
use BeaconBox\Enum\MessageKind;
use BeaconBox\Enum\MessageStatus;
use BeaconBox\Enum\RecipientStatus;
use BeaconBox\Enum\SkipReason;
use BeaconBox\Model\DeliveryStatus;
use BeaconBox\Model\Message;
use BeaconBox\Model\MessagePush;
use BeaconBox\Model\MessagePushResult;
use BeaconBox\Model\SmsOutcome;
use BeaconBox\Model\WebhookEvent;
use BeaconBox\Tests\Support\Fake;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parsing: what a model does with a payload that is fine, and with one that is not.
 *
 * The forward-compatibility tests here are the load-bearing ones. An SDK that fatals on a field it
 * has not heard of turns an additive API change into an outage in a merchant's checkout, months
 * after the SDK was last touched.
 */
final class ModelsTest extends TestCase
{
    // --- Timestamps ----------------------------------------------------------------------

    public function testParsesATrailingZ(): void
    {
        $result = MessagePushResult::fromArray(Fake::pushResult());

        self::assertSame('2026-08-20T09:15:00+00:00', $result->createdAt->format(\DateTimeInterface::RFC3339));
    }

    public function testParsesAnExplicitOffset(): void
    {
        $result = MessagePushResult::fromArray([...Fake::pushResult(), 'created_at' => '2026-08-20T12:15:00+03:00']);

        self::assertSame(3 * 3600, $result->createdAt->getOffset());
    }

    public function testTimestampsAreImmutable(): void
    {
        // A mutable timestamp on a readonly value object would be a hole straight through the
        // immutability the rest of these models rely on.
        $result = MessagePushResult::fromArray(Fake::pushResult());

        self::assertInstanceOf(\DateTimeImmutable::class, $result->createdAt);
    }

    /** @return list<array{mixed}> */
    public static function badTimestamps(): array
    {
        return [[null], [''], ['not a date'], [12345], [[]]];
    }

    #[DataProvider('badTimestamps')]
    public function testAnUnparseableOptionalTimestampIsNullRatherThanAnError(mixed $value): void
    {
        $outcome = SmsOutcome::fromArray(['queued' => false, 'credits' => 1, 'escalates_at' => $value]);

        self::assertNull($outcome->escalatesAt);
    }

    public function testAMissingRequiredTimestampDoesNotCrashTheParse(): void
    {
        $result = MessagePushResult::fromArray([...Fake::pushResult(), 'created_at' => null]);

        self::assertSame('1970', $result->createdAt->format('Y'));
    }

    // --- Forward compatibility -----------------------------------------------------------

    public function testAnUnknownEnumValueFallsBackToTheString(): void
    {
        // A MessageStatus shipped next quarter must not become a fatal error in an old SDK.
        $result = MessagePushResult::fromArray([...Fake::pushResult(), 'status' => 'quarantined']);

        self::assertSame('quarantined', $result->status);
        self::assertNotInstanceOf(MessageStatus::class, $result->status);
    }

    public function testAKnownEnumValueBecomesTheCase(): void
    {
        $result = MessagePushResult::fromArray(Fake::pushResult());

        self::assertSame(MessageKind::Updateable, $result->kind);
        self::assertSame(MessageStatus::Active, $result->status);
    }

    public function testAnUnknownFieldIsIgnoredAndKeptInRaw(): void
    {
        $result = MessagePushResult::fromArray([...Fake::pushResult(), 'postage_class' => 'tracked']);

        self::assertSame('tracked', $result->raw['postage_class']);
    }

    public function testMissingOptionalBlocksAreNullNotErrors(): void
    {
        $result = MessagePushResult::fromArray([
            'id' => 'm_1',
            'subject' => 's',
            'kind' => 'one_off',
            'status' => 'active',
            'created' => true,
            'nudged' => false,
            'created_at' => '2026-08-20T09:00:00Z',
            'updated_at' => '2026-08-20T09:00:00Z',
        ]);

        self::assertNull($result->sms);
        self::assertNull($result->whatsapp);
        self::assertNull($result->whatsappOptIn);
    }

    public function testAWronglyTypedFieldDoesNotFatal(): void
    {
        // Defence against a proxy or a gateway rewriting a body, not against BeaconBox.
        $result = MessagePushResult::fromArray([...Fake::pushResult(), 'sms_units' => 'one', 'created' => 'yes']);

        self::assertSame(0, $result->smsUnits);
        self::assertFalse($result->created);
    }

    // --- Outcomes ------------------------------------------------------------------------

    public function testASkipCarriesItsReason(): void
    {
        $outcome = SmsOutcome::fromArray([
            'queued' => false,
            'credits' => 1,
            'skipped_reason' => 'insufficient_credit',
        ]);

        self::assertFalse($outcome->queued);
        self::assertSame(SkipReason::InsufficientCredit, SkipReason::tryFrom((string) $outcome->skippedReason));
    }

    public function testAnArmedEscalationIsNeitherQueuedNorSkipped(): void
    {
        // Nothing was refused and nothing has been charged. The send is armed for later.
        $outcome = SmsOutcome::fromArray([
            'queued' => false,
            'credits' => 1,
            'escalates_at' => '2026-08-20T11:15:00Z',
        ]);

        self::assertFalse($outcome->queued);
        self::assertNull($outcome->skippedReason);
        self::assertNotNull($outcome->escalatesAt);
    }

    public function testOptInOutcomeReportsStatusAfterThePush(): void
    {
        $result = MessagePushResult::fromArray([...Fake::pushResult(), 'whatsapp_opt_in' => [
            'recorded' => false,
            'status' => 'stopped',
            'skipped_reason' => 'opted_out',
        ]]);

        self::assertNotNull($result->whatsappOptIn);
        self::assertSame(RecipientStatus::Stopped, $result->whatsappOptIn->status);
        self::assertFalse($result->whatsappOptIn->recorded);
    }

    // --- Immutability --------------------------------------------------------------------

    public function testEveryFieldOfAResultIsReadonly(): void
    {
        // A response is a fact about a moment. A mutated one claims to be an API answer and is
        // not, and that object then gets logged.
        //
        // Asserted by reflection rather than by attempting an assignment, because an assignment
        // that a static analyser can already prove impossible is a test that fails to compile
        // rather than a test that passes.
        $properties = (new \ReflectionClass(MessagePushResult::class))->getProperties();

        self::assertNotEmpty($properties);
        foreach ($properties as $property) {
            self::assertTrue($property->isReadOnly(), $property->getName() . ' is not readonly');
        }
    }

    // --- MessagePush payload -------------------------------------------------------------

    public function testOnlyTheRequiredFieldsByDefault(): void
    {
        $payload = (new MessagePush('a@b.c', 's', 'b'))->toArray();

        self::assertSame([
            'recipient_email' => 'a@b.c',
            'subject' => 's',
            'body' => 'b',
            'kind' => 'one_off',
        ], $payload);
    }

    public function testSendAtIsSerializedWithItsOwnOffset(): void
    {
        // A nudge scheduled for "08:00 Tallinn" must arrive at 08:00 in Tallinn.
        $when = new \DateTimeImmutable('2026-08-21T08:00:00', new \DateTimeZone('Europe/Tallinn'));

        $payload = (new MessagePush('a@b.c', 's', 'b', sendAt: $when))->toArray();

        self::assertSame('2026-08-21T08:00:00+03:00', $payload['send_at']);
    }

    public function testEmptyObsoletesIsOmitted(): void
    {
        self::assertArrayNotHasKey('obsoletes', (new MessagePush('a@b.c', 's', 'b'))->toArray());
    }

    public function testEscalationAndFallbackPassThrough(): void
    {
        $payload = (new MessagePush(
            'a@b.c',
            's',
            'b',
            escalateIfUnreadAfterMinutes: 120,
            smsIfWhatsAppFails: true,
        ))->toArray();

        self::assertSame(120, $payload['escalate_if_unread_after_minutes']);
        self::assertTrue($payload['sms_if_whatsapp_fails']);
    }

    public function testChannelsAcceptEnumsAndStrings(): void
    {
        $payload = (new MessagePush('a@b.c', 's', 'b', channels: [Channel::Sms, 'whatsapp']))->toArray();

        self::assertSame(['sms', 'whatsapp'], $payload['channels']);
    }

    // --- Nested ------------------------------------------------------------------------

    public function testAMessageParsesItsWholeDeliveryTree(): void
    {
        $message = Message::fromArray(Fake::messageView());

        self::assertTrue($message->delivery->delivered);
        self::assertFalse($message->delivery->opened);
        self::assertNotNull($message->delivery->sms);
        self::assertSame('delivered', $message->delivery->sms->status->value ?? null);
        self::assertNotNull($message->delivery->sms->submittedAt);
    }

    /**
     * `delivery.notSent` — the field that tells a caller when to stop polling.
     *
     * Before it existed, `delivered === false` meant both *on its way* and *never attempted, and
     * never will be*, so an integration waiting on a delivery the server had already declined to
     * attempt waited forever.
     */
    public function testADeliveryWithNoRefusalHasNoNotSentBlock(): void
    {
        $delivery = DeliveryStatus::fromArray(['delivered' => false, 'opened' => false, 'bounced' => false]);

        self::assertNull($delivery->notSent);
    }

    public function testARefusalCarriesItsReasonAndTime(): void
    {
        $delivery = DeliveryStatus::fromArray([
            'delivered' => false,
            'opened' => false,
            'bounced' => false,
            'not_sent' => ['reason' => 'plan_lapsed', 'at' => '2026-09-22T10:00:00Z'],
        ]);

        self::assertNotNull($delivery->notSent);
        self::assertSame(EmailSkipReason::PlanLapsed->value, $delivery->notSent->reason);
        self::assertNotNull($delivery->notSent->at);
    }

    /**
     * The load-bearing one, and why `reason` is a string rather than the enum.
     *
     * The server's list grows whenever a refusal is added to the send path. An SDK that threw on
     * a value it had not heard of would turn an additive server change into a fatal in a
     * merchant's job runner — for a message it was being told about precisely because something
     * needed attention.
     */
    public function testAnUnknownReasonParsesRatherThanThrowing(): void
    {
        $delivery = DeliveryStatus::fromArray([
            'delivered' => false,
            'opened' => false,
            'bounced' => false,
            'not_sent' => ['reason' => 'some_future_reason'],
        ]);

        self::assertNotNull($delivery->notSent);
        self::assertSame('some_future_reason', $delivery->notSent->reason);
        self::assertNull($delivery->notSent->at);
    }

    public function testAWebhookEventKeepsItsDataUntouched(): void
    {
        $event = WebhookEvent::fromArray([
            'id' => 'evt_1',
            'type' => 'message.bounced',
            'occurred_at' => '2026-08-20T09:20:00Z',
            'data' => ['message_id' => 'm_1', 'reason' => 'mailbox full'],
        ]);

        self::assertSame('mailbox full', $event->data['reason']);
    }
}

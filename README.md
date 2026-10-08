# beaconbox/beaconbox-php

Official PHP SDK for [BeaconBox](https://beaconbox.eu), order updates your customers actually
receive.

```bash
composer require beaconbox/beaconbox-php
```

PHP 8.1+. The only runtime dependencies are `ext-curl`, `ext-json` and three PSR interface
packages (`psr/http-client`, `psr/http-factory` and `psr/log`), which ship interfaces and no
implementation. No HTTP stack is pulled into the host application: a library that drags one in is
a library that eventually conflicts with it.

Full documentation, including the API reference and the guides this README summarises, is at
[docs.beaconbox.eu](https://docs.beaconbox.eu/develop).

## Push an update

```php
use BeaconBox\BeaconBoxClient;
use BeaconBox\Enum\MessageKind;
use BeaconBox\Enum\OrderStatus;
use BeaconBox\Model\MessagePush;

$client = new BeaconBoxClient(getenv('BEACONBOX_API_KEY'));

$result = $client->messages->push(new MessagePush(
    recipientEmail: 'buyer@example.com',
    subject: 'Your order has shipped',
    body: 'Tracking XY123456789EE. Estimated delivery Thursday.',
    kind: MessageKind::Updateable,
    reference: '#A-10294',
    orderStatus: OrderStatus::Shipped,
));

echo $result->id;  // 3xg39cvt8a46
```

The customer gets an email whose link opens their inbox already signed in. No password, no account
to create.

Every response is a typed, readonly object, so your editor completes the fields and PHPStan checks
them. Anything the API adds that this SDK does not know about yet is still readable through
`$result->raw`.

Re-push the same `subject` with `MessageKind::Updateable` to overwrite it in place, quietly. Add
`notify: true` to force a nudge on an update, or `obsoletes: [...]` to grey out messages this one
replaces (at most 100 ids, each at most 64 characters).

Pass `reference:` (your order number) and `orderStatus:` (an `OrderStatus` such as
`OrderStatus::Shipped`, or its string value) and BeaconBox writes the WhatsApp and SMS wording for
you, for example "Your order #A-10294 from PhonicBloom has shipped." A WhatsApp nudge needs both:
missing either, WhatsApp is skipped with `template_not_sendable` and nothing is charged.

A request body may be at most 2 MiB, or 8 MiB for a batch. A larger one is refused with
`InvalidRequestException` (HTTP 413, `request.too_large`) before it is read.

## Two things to know before anything else

### 1. Read the response, not the status code

A push returns **201 even when the SMS or WhatsApp message was not sent.** The update is already in
the customer's inbox and the email nudge has gone, so a paid channel that could not send reports a
reason and the request still succeeded.

```php
use BeaconBox\Enum\Channel;
use BeaconBox\Enum\SkipReason;

$result = $client->messages->push(new MessagePush(
    recipientEmail: 'buyer@example.com',
    subject: 'Your order has shipped',
    body: 'Tracking XY123456789EE.',
    reference: '#A-10294',
    recipientPhone: '+37255550134',
    channels: [Channel::Sms],
    orderStatus: OrderStatus::Shipped,
));

if ($result->sms?->skippedReason === SkipReason::InsufficientCredit->value) {
    // Top up, then: $client->messages->resendSms($result->id);
} elseif (\in_array($result->sms?->skippedReason, [
    SkipReason::SmsDisabled->value,    // your SMS mode is off
    SkipReason::SmsNotEnabled->value,  // BeaconBox has not switched SMS on for you yet
], true)) {
    // The email went. Naming Channel::Sms above did not, and cannot, turn SMS on.
}
```

**This SDK does not throw on a skip**, deliberately. Treating one as an error is what invites a
retry, and a retry of a push that already succeeded is a second message to a real person.

#### What `channels` means

`channels` is a request that can only **narrow** what your account settings allow. It never
switches a channel on:

- Leave it null and the push follows your account settings.
- Leave a channel out of the list and this push does not use it (`disabled_by_request`).
- Name a channel whose mode is `off` and nothing is sent on it (`sms_disabled`,
  `whatsapp_disabled`). `off` is final.
- Name a channel BeaconBox has not yet enabled for your business and nothing is sent on it
  (`sms_not_enabled`, `whatsapp_not_enabled`). Ask for it from the dashboard.
- A channel in `on_request` mode sends only on a push that names it.

Naming a channel never overrides a country restriction or a recipient who opted out, and on
WhatsApp never a recipient who did not opt in. A push that does not mention a channel your account
has off, or sends only on request, gets no block for it at all (`$result->sms === null`).

The **email** side answers in two halves, because the decision is made twice.

`$result->email` is the verdict as known at push time, so the stable, recipient-shaped reasons
reach you in the response to the call you just made:

```php
if ($result->email !== null && !$result->email->sending) {
    // Reliable: no email will be sent, and skippedReason says why.
    error_log($result->email->skippedReason);  // plan_lapsed, unsubscribed, suppressed, ...
}
```

**`sending === false` is reliable; `sending === true` is an expectation, not a promise.** At push
time we can see a suppressed address or a lapsed plan; we cannot see the day's cap filling up
between now and the send, or a pause applied in between. So for what actually happened, read the
message back:

```php
use BeaconBox\Enum\EmailSkipReason;

$message = $client->messages->get($result->id);

if ($message->delivery->notSent !== null) {
    // No email was ever attempted, and none will be. Stop polling for delivery.
    if ($message->delivery->notSent->reason === EmailSkipReason::PlanLapsed->value) {
        // the subscription ran out; paying resumes sending
    }
}

if ($message->delivery->failed !== null) {
    // An email was attempted and BeaconBox gave up: esp_refused, retries_exhausted
    // or outcome_unknown (the answer was lost; it may still arrive, which clears `failed`,
    // and is never resent).
    error_log($message->delivery->failed->reason);
}
```

`notSent` is what tells `delivery->delivered === false` apart from itself. On its own that flag
covers both *on its way* and *never attempted*, which leaves a loop waiting for delivery nothing
to stop on. It is `null` in the ordinary case, and a later successful send withdraws it.
`failed` is its counterpart at the other end of the send path: the email was tried, and the
provider refused it, every retry failed, or (`outcome_unknown`) the provider's answer was lost,
in which case it may still arrive: a later delivery clears `failed` (back to `null`) and shows as
delivered, whatever its timestamp. Either one means stop waiting, unless an `outcome_unknown`
delivery still matters to you.

`daily_cap_reached` needs a resend: the nudge is not retried when the cap resets, so push again
the next day if the update still matters.

Compare `reason` as a string and treat an unrecognised value as "not sent": the server's list
grows whenever a refusal is added to the send path, which is why this field is not typed as the
enum.

### 2. Idempotency is handled for you, and you can do better

Every `POST` carries an `Idempotency-Key`. This SDK generates one per call and **reuses it across
its own retries**, so a connection that dies with the answer in flight cannot become a duplicate
email and a duplicate charged SMS.

`PUT` and `DELETE` do not carry one and do not need one: setting a recipient's number twice leaves
one number. Sending a message twice sends two messages and charges two credits, which is the whole
reason the header is mandatory on a push.

Pass your own whenever you have a natural key:

```php
$client->messages->push(
    new MessagePush(
        recipientEmail: 'buyer@example.com',
        subject: 'Your order has shipped',
        body: 'Tracking XY123456789EE.',
    ),
    idempotencyKey: 'order-4711-shipped',
);
```

Then a retry from *anywhere*, your queue, a cron, a human clicking twice, collapses onto the same
key rather than only the retries this SDK makes internally.

A key is at most 255 characters, and a **batch** key at most 252: each item's own key is the
batch key with `#<index>` appended. A longer one is refused with `InvalidRequestException` before
anything runs.

## Everything else

```php
// Delivery status
$message = $client->messages->get('3xg39cvt8a46');
$message->delivery->opened;

// Every message, following cursors. A generator, so a year of history is not held in memory
foreach ($client->messages->each(recipientEmail: 'buyer@example.com') as $message) { /* … */ }

// Take one back: withdrawn for the recipient, any queued nudge called off, no credit spent
$client->messages->retract('3xg39cvt8a46');

// Up to 100 pushes. Read ->failed, not the status code. A ConflictException
// (idempotency.request_in_progress) means the same batch is still running: retry it with the same key
// later. The SDK does not retry it for you, and items already sent are replayed rather than resent
$result = $client->messages->pushBatch([
    new MessagePush(recipientEmail: 'a@example.com', subject: 'Shipped', body: 'Tracking XY123456789EE.'),
    new MessagePush(recipientEmail: 'b@example.com', subject: 'Shipped', body: 'Tracking XY987654321EE.'),
]);
foreach ($result->failures() as $item) {
    error_log("item {$item->index} rejected: {$item->errorCode}");
}

// Contact details. Reads are masked: a leaked key must not dump a phone book
$client->recipients->sms('buyer@example.com')->smsConsentSource;  // push, api, admin or inbox
$client->recipients->setPhone('buyer@example.com', '+37255550134');
$client->recipients->clearPhone('buyer@example.com');

// The prepaid balance. One balance, shared by SMS and WhatsApp
$client->credits->balance()->balance;

// Keys and webhook endpoints
$client->keys->create('orders service');
$endpoint = $client->webhookEndpoints->create('https://example.com/hooks');  // empty = every event

// Send one sample ping and see what came back. Always 200, whatever your endpoint answers:
// the failure being reported belongs to the next hop, so read ->delivered, not the status.
$test = $client->webhookEndpoints->test($endpoint->id);
if (!$test->delivered) {
    error_log("{$test->statusCode} {$test->error} {$test->durationMs}ms");
}
```

### Pay only for the customers the email did not reach

`escalateIfUnreadAfterMinutes` holds the paid channel back. The SMS is sent only if the recipient
still has not opened the message after that long, and if they open it first, nothing is sent and
nothing is charged.

- **The minutes count from the email**: from `sendAt` when the message is scheduled, otherwise
  from the push. If the email is still queued at the deadline, the deadline moves to that many
  minutes after it goes.
- **An email that bounced, failed or was skipped counts as unread**, so the paid channel is still
  sent, subject to its own consent and settings. That is the case the paid channel exists for: the
  email did not reach them. Only a push that queued no nudge at all (`notify: false`, or a quiet
  in-place update) never escalates.
- A channel in `on_request` mode that the push named still sends at the deadline.
- `$result->sms->escalatesAt` (or `$result->whatsapp->escalatesAt`) says when the deadline falls.

```php
$client->messages->push(new MessagePush(
    recipientEmail: 'buyer@example.com',
    subject: 'Action needed on your order',
    body: 'We could not process your payment.',
    recipientPhone: '+37255550134',
    channels: [Channel::Sms],
    escalateIfUnreadAfterMinutes: 120,
));
```

### Erasing a recipient

`$client->recipients->erase($email)` is the API half of an Article 17 request made to you, and it
is **not undoable**. It deletes the messages you sent them, their delivery events, their SMS and
WhatsApp send records, their WhatsApp replies and their recipient record (phone number, consent,
unsubscribe state), scrubs them from webhook deliveries still queued for you, and cancels sends
still waiting to go out.

It **keeps** email suppressions for the address (an unsubscribe is kept as one) and SMS or WhatsApp
opt-outs for their number, on purpose: those are what stop them being contacted again by a later
push, and an opt-out erased on request stops being one. Erasing does not undo an unsubscribe: a
later push to the address finds them still unsubscribed. The report counts both. A repeat erasure,
or one of an address you never messaged, answers with zeros; retry a timed-out call with the same
idempotency key to get the original counts back.

`$client->recipients->eraseWhatsApp($email)` removes only their WhatsApp history and withdraws
WhatsApp consent.

Both take the recipient's email address and nothing else. A blank value, or one that is not an
address, throws `\InvalidArgumentException` before anything is sent (the API refuses it with a 422
anyway), so a slip in the value fails at your line.

```php
$report = $client->recipients->erase('buyer@example.com');
error_log("{$report->messagesDeleted} {$report->suppressionsKept} {$report->stopsKept}");

$receipt = $client->recipients->eraseWhatsApp('buyer@example.com');

// The half only you can finish: erased replies whose words may already be in *your* mailboxes.
$yours = $receipt->repliesAForwardEmailMayHaveCarried;
```

## Verifying webhooks

```php
use BeaconBox\Enum\WebhookEventType;
use BeaconBox\Exception\WebhookVerificationException;
use BeaconBox\Webhooks;

try {
    $event = Webhooks::verify(
        rawBody: file_get_contents('php://input'),   // the RAW body, byte for byte
        signatureHeader: $_SERVER['HTTP_X_BEACONBOX_SIGNATURE'] ?? '',
        secret: getenv('BEACONBOX_WEBHOOK_SECRET') ?: '',
    );
} catch (WebhookVerificationException) {
    http_response_code(400);
    return;
}

if ($event->type === WebhookEventType::MessageBounced) {
    // ...
}
```

**Pass the raw body.** Decoding to an array and re-encoding changes the bytes over key order and
whitespace, and the signature stops matching, in production, on a payload shaped slightly
differently from the one you tested with. The helper also checks the timestamp (which is signed, so
a captured delivery cannot be replayed) and compares in constant time.

Deliveries are **retried**, so the same `$event->id` can arrive twice. Deduplicate on it.

| Event | `$event->data` |
| --- | --- |
| `message.delivered`, `message.opened`, `message.bounced`, `message.complained` | `message_id`, `recipient_email`, `subject`, `channel` (`"email"`) |
| `message.not_sent` | the same, plus `reason` (an `EmailSkipReason` value): no email was attempted |
| `message.failed` | the same, plus `reason` (`esp_refused`, `retries_exhausted` or `outcome_unknown`) when BeaconBox gave up on the send: an email was attempted and will not be retried. `outcome_unknown` means the provider's answer was lost, so it may still arrive: a `message.delivered` for the message supersedes it, in whichever order the two arrive |
| `sms.delivered`, `sms.failed`, `sms.rejected`, and the same three for `whatsapp.*` | `message_id`, `recipient_phone` (masked), `country_code`, `credits_charged`, `channel`, `error_code`. Sent on the carrier's (or Meta's) report, and also when BeaconBox ends the send itself: refused at submit (`rejected`), an outcome never learned (`failed`, `*.submit_outcome_unknown`), or a send given up on after its retries (`failed`) |

## Errors

Everything extends `BeaconBox\Exception\BeaconBoxException`.

| Exception | When |
| --- | --- |
| `AuthenticationException` | 401, key missing, malformed or revoked |
| `PermissionException` | 403, for example `plan.read_only` |
| `InvalidRequestException` | 422, a malformed field, or a reused idempotency key with a different body. Also 413, a body over the size limit |
| `ResourceMissingException` | 404, no such id. Also what another business's id looks like, deliberately |
| `ConflictException` | 409, already sent, still queued, or an identical request still in flight |
| `RateLimitException` | 429, after the SDK has already retried |
| `ServerException` | 5xx, after the SDK has already retried |
| `ApiConnectionException` | no answer at all. **Not** proof the work did not happen |
| `WebhookVerificationException` | a delivery could not be proven to be ours |

Branch on `$exception->errorCode` (a stable dotted string such as `message.not_found`), not on the
message text. Some worth knowing:

| `errorCode` | Status | Means |
| --- | --- | --- |
| `sms.already_sent`, `whatsapp.already_sent` | 409 | a resend while that channel's send is still queued, or after it went |
| `whatsapp.escalated_to_sms` | 409 | a WhatsApp resend after `smsIfWhatsAppFails` already texted them |
| `sms.may_have_sent` | 409 | an SMS resend after an earlier text ended without the carrier's answer |
| `whatsapp.may_have_sent` | 409 | a WhatsApp resend after an earlier message ended without Meta's answer |
| `sms.phone_already_in_use` | 409 | the number belongs to another of your recipients |
| `idempotency.request_in_progress` | 409 | the same key is still running; wait and retry with it |
| `request.conflict` | 409 | two requests raced to write the same thing; a retry sees the winner |
| `webhook.test_rate_limited` | 429 | `webhookEndpoints->test()` pressed too often |
| `common.validation_failed` | 422 | a field is malformed or over a limit, for example more than 100 `obsoletes` |
| `sms.phone_invalid` | 422 | `recipientPhone` or `recipients->setPhone()` got a number that does not parse or cannot be a recipient's phone: premium-rate, toll-free, shared-cost, voicemail, service, or a satellite or international code (+800, 808, 870, 878, 881, 882, 883, 888, 979). A number in those ranges stored earlier is skipped as `country_not_allowed` |
| `request.too_large` | 413 | the body is over 2 MiB (8 MiB for a batch); refused before it is read |
| `request.unstorable_input` | 422 | text holds a NUL character or a lone surrogate, which cannot be stored; the idempotency key is not spent |
| `plan.read_only` | 403 | `keys->create()` while your plan has lapsed; pay, and it works again |

A paid send that fails after the push reports its own code on the `sms.failed` or `whatsapp.failed`
webhook as `$event->data['error_code']`. `sms.submit_outcome_unknown` and
`whatsapp.submit_outcome_unknown` mean BeaconBox called the carrier (or Meta) and never learned the
answer: the message may have arrived, so no second copy is sent and the charge stands. A WhatsApp
one still falls back to SMS when `smsIfWhatsAppFails` was set.

**Every response carries an `X-Request-Id`.** It is on every `ApiException` as
`$exception->getRequestId()` (also the `$requestId` property) and in its message. Quote it to
support: it is the one value that finds your request in our logs.

## Configuration

```php
use BeaconBox\BeaconBoxClient;
use BeaconBox\RetryPolicy;

$client = new BeaconBoxClient(
    apiKey: getenv('BEACONBOX_API_KEY'),
    baseUrl: 'https://api.beaconbox.eu',
    timeoutMs: 30_000,
    retryPolicy: new RetryPolicy(maxRetries: 2),
    userAgentSuffix: 'acme-shop/2.1',
);
```

Inside a job runner that already retries, pass `new RetryPolicy(maxRetries: 0)` so the two
schedules do not multiply.

`userAgentSuffix` is appended to the `User-Agent`, so your integration is identifiable in a support
conversation. It is a label for a human to read, so anything that cannot go in a header value is
stripped from it rather than refused.

### Backpressure

A connection failure, a 429 and a 5xx are retried; a 4xx is not, because sending the same wrong
request again asks the same question. Backoff is exponential with full jitter, since the failure
being absorbed is synchronised across every worker you run.

**A `Retry-After` is honoured in full, not shortened to the backoff cap.** It is the server's own
answer to when it will be ready, and retrying earlier only earns a second 429. Jitter is added *on
top* of it rather than sampled from within it, because the herd is at its worst here: every worker
that hit the same 429 was handed the same number.

If the server asks for longer than `RetryPolicy::$maxRetryAfterMs` (30s by default), the SDK
**stops rather than retrying early** and throws `RateLimitException` with `$retryAfterMs` set.
Blocking a worker for the cap and being refused anyway helps nobody; the number is what you need to
schedule a real retry.

## Logging

PSR-3, and **opt-in**. Pass any `LoggerInterface` and you get output; pass nothing and the SDK is
completely silent, because a library that writes to a file or to stderr of its own accord is a
library fighting the host application's logging.

```php
$client = new BeaconBoxClient(
    apiKey: getenv('BEACONBOX_API_KEY'),
    logger: $monolog,
);
```

| Level | What |
| --- | --- |
| `debug` | every request and response, with status and elapsed time |
| `warning` | a retry (with reason and backoff), and giving up after the last one |

Nothing is emitted at `info` or above, so a `warning` from this SDK always means something went
wrong. Each record carries context (`route`, `attempt`, `status_code`, `idempotency_key`,
`request_id`). `request_id` is the response's `X-Request-Id`, the value to quote to support.

**Nothing sensitive is ever logged.** Not the API key or any header, not request or response
bodies, not the query string, and not the interpolated URL path. A route template is logged
instead, so `/recipients/buyer@example.com/sms` appears as `/recipients/{email}/sms`. The context
is an allow-list rather than a redaction pass, because redaction is a list of things somebody
remembered to hide and the field added next year is not on it.

### Security defaults you cannot accidentally lose

- **Plain `http` is refused** for anything but localhost, so a misconfigured `baseUrl` cannot put
  your API key on the wire in clear.
- **Redirects are never followed.** curl would re-send the `Authorization` header to wherever a
  redirect points.
- `CURLOPT_SSL_VERIFYPEER` and `VERIFYHOST` are set explicitly, so a php.ini or a system curl
  config that has turned them off cannot silently disable certificate verification.
- **An API key that cannot go in a header is refused at construction**: a control character, a
  newline or a non-ASCII character. curl does not sanitise what it is handed, so a `\r\n` inside
  the key would be request smuggling with your own credential as the payload. Surrounding
  whitespace is trimmed, because a key read from a file keeps its trailing newline.
- **A `baseUrl` with a query string or a fragment is refused**, because it would not fail, it would
  go somewhere else. A path prefix is fine, for a gateway that mounts BeaconBox under one.
- The API key is redacted from `var_dump`, and so are `NewApiKey::$key` and a webhook endpoint's
  secret, from `json_encode` too, which is the path a structured logger actually takes. Note that
  `var_export`, `serialize` and `->raw` accept no hook and still carry the value: read the property,
  store it, drop the object.
- Webhook signatures are compared with `hash_equals`, over a signed timestamp.

Behind a corporate CA or a private staging certificate? Pass `caBundle:` a path to a PEM file.
There is no option to turn verification off, because there is no legitimate production reason to.

## Bring your own HTTP client

```php
$psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
$client = new BeaconBoxClient(
    apiKey: getenv('BEACONBOX_API_KEY'),
    httpClient: new \GuzzleHttp\Client(),
    requestFactory: $psr17,
    streamFactory: $psr17,
);
```

The bundled curl transport keeps one persistent handle for connection reuse, which makes it unsafe
to share across coroutines. Inject a PSR-18 client if you run Swoole or ReactPHP.

## Development

```bash
composer install
composer test      # phpunit, hermetic
composer analyse   # phpstan, level 8
```

The live suite runs against a real BeaconBox. See [`tests/Live/README.md`](tests/Live/README.md).

## Licence

MIT. See [LICENSE](LICENSE).

BeaconBox is a product of BloomHarbor OÜ, a company registered in Estonia.

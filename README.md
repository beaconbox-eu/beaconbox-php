# beaconbox/beaconbox-php

Official PHP SDK for [BeaconBox](https://beaconbox.eu), order updates your customers actually
receive.

```bash
composer require beaconbox/beaconbox-php
```

PHP 8.1+. The only runtime dependencies are `ext-curl`, `ext-json` and three PSR interface
packages — `psr/http-client`, `psr/http-factory` and `psr/log` — which ship interfaces and no
implementation. No HTTP stack is pulled into the host application: a library that drags one in is
a library that eventually conflicts with it.

## Push an update

```php
use BeaconBox\BeaconBoxClient;
use BeaconBox\Enum\MessageKind;
use BeaconBox\Model\MessagePush;

$client = new BeaconBoxClient(getenv('BEACONBOX_API_KEY'));

$result = $client->messages->push(new MessagePush(
    recipientEmail: 'buyer@example.com',
    subject: 'Your order has shipped',
    body: 'Tracking XY123456789EE. Estimated delivery Thursday.',
    kind: MessageKind::Updateable,
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
replaces.

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
    recipientPhone: '+37255550134',
    channels: [Channel::Sms],
));

if ($result->sms?->skippedReason === SkipReason::InsufficientCredit->value) {
    // Top up, then: $client->messages->resendSms($result->id);
}
```

**This SDK does not throw on a skip**, deliberately. Treating one as an error is what invites a
retry, and a retry of a push that already succeeded is a second message to a real person.

### 2. Idempotency is handled for you, and you can do better

Every write carries an `Idempotency-Key`. This SDK generates one per call and **reuses it across
its own retries**, so a connection that dies with the answer in flight cannot become a duplicate
email and a duplicate charged SMS.

Pass your own whenever you have a natural key:

```php
$client->messages->push($message, idempotencyKey: 'order-4711-shipped');
```

Then a retry from *anywhere*, your queue, a cron, a human clicking twice, collapses onto the same
key rather than only the retries this SDK makes internally.

## Everything else

```php
// Delivery status
$message = $client->messages->get('3xg39cvt8a46');
$message->delivery->opened;

// Every message, following cursors. A generator, so a year of history is not held in memory
foreach ($client->messages->each(recipientEmail: 'buyer@example.com') as $message) { /* … */ }

// Take one back: withdrawn for the recipient, any queued nudge called off, no credit spent
$client->messages->retract('3xg39cvt8a46');

// Up to 100 pushes. Always 200: read ->failed, not the status code
$result = $client->messages->pushBatch([$one, $two]);
foreach ($result->failures() as $item) {
    error_log("item {$item->index} rejected: {$item->errorCode}");
}

// Contact details. Reads are masked: a leaked key must not dump a phone book
$client->recipients->setPhone('buyer@example.com', '+37255550134');
$client->recipients->clearPhone('buyer@example.com');

// The prepaid balance. One balance, shared by SMS and WhatsApp
$client->credits->balance()->balance;

// Keys and webhook endpoints
$client->keys->create('orders service');
$client->webhookEndpoints->create('https://example.com/hooks');  // empty list means every event
```

### Pay only for the customers the email did not reach

`escalateIfUnreadAfterMinutes` holds the paid channel back. The SMS is sent only if the recipient
still has not opened the message after that long, and if they open it first, nothing is sent and
nothing is charged.

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

### Erasing a recipient's WhatsApp history

```php
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

## Errors

Everything extends `BeaconBox\Exception\BeaconBoxException`.

| Exception | When |
| --- | --- |
| `AuthenticationException` | 401, key missing, malformed or revoked |
| `PermissionException` | 403 |
| `InvalidRequestException` | 422, a malformed field, or a reused idempotency key with a different body |
| `ResourceMissingException` | 404, no such id. Also what another business's id looks like, deliberately |
| `ConflictException` | 409, already sent, or an identical request still in flight |
| `RateLimitException` | 429, after the SDK has already retried |
| `ServerException` | 5xx, after the SDK has already retried |
| `ApiConnectionException` | no answer at all. **Not** proof the work did not happen |
| `WebhookVerificationException` | a delivery could not be proven to be ours |

Branch on `$exception->errorCode` (a stable dotted string such as `message.not_found`), not on the
message text. `$exception->requestId` is what support will ask for.

## Configuration

```php
$client = new BeaconBoxClient(
    apiKey: getenv('BEACONBOX_API_KEY'),
    baseUrl: 'https://api.beaconbox.eu',
    timeoutMs: 30_000,
    retryPolicy: new BeaconBox\RetryPolicy(maxRetries: 2),
);
```

Inside a job runner that already retries, pass `new RetryPolicy(maxRetries: 0)` so the two
schedules do not multiply.

### Backpressure

A connection failure, a 429 and a 5xx are retried; a 4xx is not, because sending the same wrong
request again asks the same question. Backoff is exponential with full jitter, since the failure
being absorbed is synchronised across every worker you run.

**A `Retry-After` is honoured in full, not shortened to the backoff cap.** It is the server's own
answer to when it will be ready, and retrying earlier only earns a second 429. Jitter is added *on
top* of it rather than sampled from within it — the herd is at its worst here, because every worker
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
wrong. Each record carries context (`route`, `attempt`, `status_code`, `idempotency_key`).

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
- The API key is redacted from `var_dump`, and so are `NewApiKey::$key` and a webhook endpoint's
  secret.
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

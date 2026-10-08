# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.0] - 2026-10-07

### Added

- `MessagePush::$orderStatus` (`orderStatus:`), with `OrderStatus` listing the known values
  (`confirmed`, `shipped`, `ready_for_pickup`, `delivered`, `cancelled`, `refunded`, `delayed`).
  It says what happened to the order, and BeaconBox picks the WhatsApp and SMS wording from it. A
  WhatsApp nudge is sent only when a push carries both `reference` and `orderStatus`; otherwise
  WhatsApp is skipped with `template_not_sendable` and nothing is charged. A plain string is
  accepted too, so a status the API adds later can be sent before an SDK release.
  `MessagePushResult::$orderStatus` echoes it back, and an unknown value parses as a plain string.
- Error codes `sms.may_have_sent` and `whatsapp.may_have_sent` (409): a resend is refused while an
  earlier send of that message ended without the provider's answer, because it may have arrived.
  A later receipt that settles it lifts the refusal. Send records also gain the outcome codes
  `sms.never_submitted` and `whatsapp.never_submitted`: the send never reached the provider and
  its credits were returned.
- `$client->keys->create(detach: true)`: the API rotation path. A key minted with a key is now
  revoked when that key is revoked (and so is anything minted below it without `detach`). A
  detached key has no parent and keeps working after you revoke the key that minted it: mint it,
  move to it, then revoke the old one. A leaked key can mint one too, so after a leak check
  `$mintedBy` (below) for keys the leaked key created and revoke them as well.
- `ApiKey::$mintedBy`: the id of the key that created a listed key through the API, set even for a
  detached one, and null for a key created in the dashboard. After a leak, a key still listed with
  the leaked key's id there was created detached with it and keeps working until revoked.
- `$client->recipients->erase($email)`, returning a `RecipientErasureReport`. The API half of an
  Article 17 request: it deletes the messages you sent the recipient, their delivery events, their
  SMS and WhatsApp send records, their WhatsApp replies and their recipient record, scrubs them
  from webhook deliveries still queued for you and cancels sends still waiting to go out. It keeps
  email suppressions and SMS or WhatsApp opt-outs on purpose, because those are what stop the
  person being contacted again, and the report counts both (`$suppressionsKept`, `$stopsKept`).
  Not undoable. It is a `POST` and carries an `Idempotency-Key` like every other: a repeat erasure
  reports zeros, so retrying with the same key is how a timed-out call gets its original counts
  back.
- `DeliveryStatus::$failed`, a `Failed` with `$reason` and `$at`: the email was attempted and will
  never arrive, because the provider refused it or every retry failed. The counterpart of
  `$notSent`, and like it a reason to stop polling for delivery. `EmailFailureReason` lists the
  known values (`esp_refused`, `retries_exhausted`); `$reason` stays a plain string so a new value
  cannot throw.
- `SkipReason::SmsNotEnabled`, `SkipReason::SmsWarmupCapReached` and
  `SkipReason::WhatsAppNotEnabled`, and `EmailSkipReason::BusinessInactive`. Unknown reasons still
  parse as plain strings.
- `ApiException::getRequestId()`, the response's `X-Request-Id` (the same value as the existing
  `$requestId` property). It is now part of every `ApiException` message too, and it is logged as
  `request_id` on the debug response record and on the retry warning. Quote it to support.
- `MessagePushResult::$recipientEmail` (so also on each batch item's `$result`): the address the
  message was stored for, as the API normalised it. Null only on an idempotent replay of a
  response recorded before the API sent it.
- `RecipientSms::$smsConsentAt` and `RecipientSms::$smsConsentSource` (a `SmsConsentSource`:
  `push`, `api`, `admin` or `inbox`): when and through which surface texting this recipient first
  began. An unknown source parses as a plain string.
- `EmailFailureReason::OutcomeUnknown` (`outcome_unknown`): the email provider may have taken the
  message and its answer was lost. Reported as failed and never resent, but it may still arrive.
- Documented error codes: `request.too_large` (413, a body over 2 MiB or 8 MiB for a batch) and
  `request.unstorable_input` (422, a NUL character or lone surrogate in text), both thrown as
  `InvalidRequestException`; `plan.read_only` (403) on `keys->create()` while the plan has lapsed,
  as `PermissionException`. The `sms.failed` and `whatsapp.failed` webhooks document
  `sms.submit_outcome_unknown` and `whatsapp.submit_outcome_unknown` in `data['error_code']`.

### Changed

- **WhatsApp now needs `reference` and `orderStatus` on the push.** The generic WhatsApp templates
  are retired, because Meta classed them as marketing; each order status now has its own template. A
  push without both, or whose reference or business name a nudge cannot show, skips WhatsApp with
  `template_not_sendable` and is not charged. With `smsIfWhatsAppFails`, the text goes instead,
  once, as it does for a number not on WhatsApp.
- **The WhatsApp and SMS nudges show `reference` only when it passes a display rule**: an optional
  leading `#`, then a letter or digit, then only letters, digits and `_ / # -`, with at most 32
  characters and at most two dots, each directly before a digit (`INV.2026` is shown, `shop.com`
  is not). A reference the API accepts that fails the rule is stored and echoed but never shown,
  and such a push sends no WhatsApp message.
- `WebhookEventType::PlanAllowanceExceeded` is documented as fair use: sending continues and
  email past the allowance is not charged. Its payload no longer carries `overage_cents`.
- `whatsapp.rejected` is documented as also firing, with `error_code` `whatsapp.submit_refused`
  and the credit refunded, for a send Meta refused on every attempt. The API used to send
  `whatsapp.failed` for it; it now matches `sms.rejected`.
- `recipients->erase()` and `recipients->eraseWhatsApp()` throw `\InvalidArgumentException`
  before sending anything when the email is blank or not an address, which the API now refuses
  with a 422. The value is trimmed before it is sent.
- `escalateIfUnreadAfterMinutes` is documented as the API now behaves: it counts from `sendAt`
  when the message is scheduled, waits while the email is still queued, and still escalates when
  the email bounced, failed or was skipped. Only a push that queued no nudge never escalates, and
  an `on_request` channel the push named still sends at the deadline.
- `channels` on a push is documented as what the API now enforces: a request that can only narrow
  what your account settings allow, never switch a channel on. A channel in `off` mode stays off
  (`sms_disabled`, `whatsapp_disabled`), one BeaconBox has not enabled for your business never
  sends (`sms_not_enabled`, `whatsapp_not_enabled`), and one in `on_request` mode sends only on a
  push that names it. A push still answers 201 and reports the skip; nothing throws.
- `EmailSkipReason::DailyCapReached` is documented as requiring a resend: the nudge is not retried
  when the cap resets.
- `WebhookEventType::MessageFailed` documents its payload: `message_id`, `recipient_email`,
  `subject`, `channel`, and `reason` when BeaconBox gave up on the send.
- Docblocks and the README state the API's push limits: `obsoletes` takes at most 100 ids of at
  most 64 characters, and a batch idempotency key at most 252 characters (each item's key appends
  `#<index>`). Both are refused with `InvalidRequestException`. The SDK does not check them itself.
- The resend docblocks name the 409 codes (`sms.already_sent`, `whatsapp.already_sent`,
  `whatsapp.escalated_to_sms`), all thrown as `ConflictException`, and the README lists the other
  new codes a public call can answer: `request.conflict` (409) and `webhook.test_rate_limited`
  (429).
- `messages->pushBatch()` documents its one whole-batch refusal: a 409
  `idempotency.request_in_progress` while the same key is still running (or took the batch over),
  thrown as `ConflictException` and not retried by the SDK. Retry the whole batch later with the
  same key.
- `recipients->erase()` documents that an email unsubscribe is kept as a suppression, so erasing
  does not undo it.
- `EmailFailureReason::OutcomeUnknown` and `delivery->failed` document that a later delivery clears
  `failed` and shows as delivered, whatever its timestamp, so it is not a reason to stop polling.
- `recipients->setPhone()` and a push's `recipientPhone` document `sms.phone_invalid` (422) for
  premium-rate, toll-free, shared-cost, voicemail, service, satellite and international numbers; one
  already stored is skipped as `country_not_allowed`.
- The `sms.failed`, `sms.rejected`, `whatsapp.failed` and `whatsapp.rejected` webhooks document that
  they also fire when BeaconBox ends a send itself: refused at submit, outcome unknown, or given up
  on.
- An `ApiException` message now ends `(HTTP 409, request <id>)` when the response carried a
  request id, rather than `(HTTP 409)`. Code matching on the message text should match on
  `errorCode`.
- `DeliveryStatus::__construct()` takes `$failed` before `$raw`. Positional construction of this
  response model shifts by one; it is parsed via `fromArray()` in every normal use, and named
  arguments are unaffected.

## [0.4.0] - 2026-10-04

### Added

- `MessagePushResult::$email`, an `EmailOutcome` carrying whether the nudge is expected to send and
  why not if it is not. Its own type beside `$sms` and `$whatsapp`, because suppression is per
  channel: a recipient who texted STOP has suppressed SMS and nothing else, a hard bounce
  suppresses email and nothing else. `sending = false` is reliable; `sending = true` is the verdict
  as known at push time, so `$message->delivery` and the `message.not_sent` webhook remain
  authoritative.
- `webhookEndpoints->test($publicId)`. Send a sample `ping` to one of your endpoints and read a
  `WebhookTestResult`. It never throws for a failure at *your* end, because that failure belongs
  to your server rather than to the API call: read `->delivered`. The event type is `ping` rather
  than a real one, so a test cannot be mistaken for a genuine delivery by a handler that acts on
  them, and it is signed exactly like a real delivery, so it also proves your signature
  verification. It does not touch the endpoint's health counters.
- `Parse::nullableInt()`, so a field where `0` is a real value can still distinguish "absent".
  `WebhookTestResult::$statusCode` is exactly that case: `0` would read as an HTTP status.

### Fixed

- `Webhooks::verify()` accepts a signature spelled in uppercase hex. `hash_hmac` returns lowercase
  and `hash_equals` compares bytes, so the incoming value is now normalised before comparison.
  Hex case carries no meaning, and refusing it was a false negative on a correct signature. The
  comparison itself is still constant-time.
- `Webhooks::verify()` rejects a signature header whose `t=` is not between one and twenty plain
  ASCII digits, so a timestamp with a leading sign, surrounding whitespace, underscore separators
  or an absurd length is reported as a malformed header rather than parsed. The timestamp is part
  of the signed string and has one canonical form. Both patterns anchor with `\z` rather than `$`,
  which in PCRE also matches before a trailing newline.

### Changed

- `WebhookVerificationException` for a signature mismatch now names the likely cause. "Signature
  does not match" reads as a key problem and almost never is: the usual cause is verifying a
  re-encoded body instead of the raw request bytes, which looks identical and hashes differently.
  The message says so before it mentions the secret, so nobody rotates a working one.
- A `Retry-After` longer than twenty digits falls back to this SDK's own backoff instead of being
  capped at `PHP_INT_MAX` milliseconds. A wait of 10^20 seconds is not an instruction a server
  means to give, so it is read as a broken header. A value within the grammar is still capped
  rather than overflowed, because the millisecond arithmetic stops fitting an `int` well before the
  grammar's own limit.
- `MessagePushResult::__construct()` takes `$email` before `$sms`. Positional construction of this
  response model shifts by one; it is parsed from an API response via `fromArray()` in every
  normal use, and named arguments are unaffected.

## [0.3.0] - 2026-09-29

### Security

- `NewApiKey` and `WebhookEndpoint` redact their secret for `json_encode()` as well as for
  `var_dump()`. `__debugInfo()` covers `var_dump()` and nothing else, while a structured logger
  reaches for `json_encode()`: Monolog's `JsonFormatter`, and every JSON-lines formatter like it,
  encodes the context array, so `$log->info('key minted', ['key' => $new])` wrote the live key and
  the whole of `->raw` into the log stream. Both classes now implement `JsonSerializable`.
  `var_export()` and `serialize()` accept no hook in PHP and still carry the value, which the class
  docblocks now say out loud.
- An API key containing a control character, a newline or a non-ASCII character is refused at
  construction. curl does not sanitise what it is handed in `CURLOPT_HTTPHEADER`, so such a value
  reached the request verbatim. The message names the problem without quoting the key.
- A `baseUrl` carrying a query string or a fragment is refused. Such a URL does not fail, it goes
  somewhere else: `https://host?x=1` produced `https://host?x=1/api/v1/credits`. A path prefix is
  still allowed, for a gateway that mounts BeaconBox under one.
- `Parse::enum` and the rest of the parse layer agree field for field on every shape they are
  handed: a null, a number, a bool, an integral float and a wrongly typed string.

### Fixed

- `keys->create()` returns `$id`, so the key you just minted can be revoked in one call. The only
  other route to it was to list the keys and match on `$name`, which carries no unique constraint
  and defaults to "Untitled key", so two unnamed keys cannot be told apart.
- **The API key is trimmed.** The constructor checked `trim($apiKey)` for emptiness and then
  stored the untrimmed value, so the commonest way a key arrives, `file_get_contents` on a secret
  file or a `.env` line with trailing whitespace, put a trailing newline on the wire. That is a 401
  nobody can explain from a key that looks correct.
- A timestamp with no offset is read as UTC. PHP applied `date.timezone`, so the same response
  parsed as two different instants on two hosts. A value that carries an offset is unaffected.
- An integral `float` in a numeric field is read as the integer. `is_int()` alone rejected it, so a
  proxy or a gateway that writes `1` as `1.0` reported zero credits in a field a merchant is
  billed on. A non-integral float is still not coerced.
- A fractional `Retry-After` is honoured. `ctype_digit` refused `1.5`, so a server that answered
  something more precise than a whole second had its answer replaced by an invented backoff.
- An absurd `Retry-After` is capped rather than overflowed. `((int) $seconds) * 1000` becomes a
  `float` past `PHP_INT_MAX`, and the parser declares `?int` under `strict_types`, so a broken or
  hostile header turned a 429 into a `TypeError` thrown from inside the retry loop.
- `messages->each()` refuses a `pageSize` below 1. The server clamps `limit` into its own range,
  so this was not an error there; it quietly became one message per request.

### Added

- `userAgentSuffix:` on `BeaconBoxClient`, for identifying an integration in a support
  conversation. Anything that cannot go in a header value is stripped from it rather than refused,
  because the suffix is a label a human reads.

### Note

- `NewApiKey` gained `id` as its **first** field. It is a response model built by `fromArray()`, so
  this affects only code constructing one positionally by hand.

## [0.2.0] - 2026-09-22

### Added

- `$delivery->notSent` on a message: why no email was ever sent, and when the decision was made.
  Present only while that decision stands, since a later successful send withdraws it. It is what
  tells `delivered === false` apart from *never attempted, and never will be*, so a loop polling
  for delivery now has something to stop on. `EmailSkipReason` lists the known values; `$reason`
  stays a plain string, because the server's list grows and an unknown value must not throw.
- `message.not_sent` webhook event, carrying the same `reason`. Deliberately not folded into
  `message.failed`: nothing was attempted and nothing bounced.
- `plan.past_due`, `plan.lapsed` and `plan.allowance_exceeded` webhook events. The second is the
  one to alert on. It means email sending has stopped, and every nudge from then on produces a
  `message.not_sent` with `reason: "plan_lapsed"` until the subscription is paid.

### Note

- `DeliveryStatus` gained a constructor parameter before `$raw`. It is a response model built by
  `fromArray()`, so this affects only code constructing one positionally by hand.

## [0.1.0]

### Added

- `BeaconBoxClient`, with operations across `messages`, `recipients`, `keys`, `credits` and
  `webhookEndpoints`.
- Typed readonly models for every response. Unknown fields remain available via `->raw`.
- Cursor pagination via `messages->each()`, returning a `Generator`.
- Webhook signature verification via `Webhooks::verify()`.
- Automatic `Idempotency-Key` on every write, reused across the SDK's own retries. Override per
  call with `idempotencyKey:`.
- Retries with exponential backoff and jitter on connection errors, 429 and 5xx. Configure with
  `RetryPolicy`; `maxRetries: 0` disables them.
- `Retry-After` honoured up to `RetryPolicy::$maxRetryAfterMs` (30s). Beyond it,
  `RateLimitException` is thrown with `retryAfterMs` set rather than retrying.
- Skipped SMS and WhatsApp channels are reported on the result; they do not throw.
- Configurable `baseUrl`, `timeoutMs`, `retryPolicy`, `caBundle` and `logger`. A PSR-18 client and
  PSR-17 factories can be injected via `httpClient:`, `requestFactory:` and `streamFactory:`.
- Opt-in PSR-3 logging. Silent unless a logger is passed.
- Plain `http` is refused except on localhost.
- Redirects are not followed.
- `CURLOPT_SSL_VERIFYPEER` and `CURLOPT_SSL_VERIFYHOST` are set explicitly.
- API keys, newly minted keys and webhook endpoint secrets are excluded from `var_dump()`.
- Log records carry route templates (`/recipients/{email}/sms`), never interpolated paths.

### Requirements

- PHP 8.1+
- `ext-curl`, `ext-json`
- `psr/http-client`, `psr/http-factory`, `psr/log`

[0.5.0]: https://github.com/beaconbox-eu/beaconbox-php/releases/tag/v0.5.0
[0.4.0]: https://github.com/beaconbox-eu/beaconbox-php/releases/tag/v0.4.0
[0.3.0]: https://github.com/beaconbox-eu/beaconbox-php/releases/tag/v0.3.0
[0.2.0]: https://github.com/beaconbox-eu/beaconbox-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/beaconbox-eu/beaconbox-php/releases/tag/v0.1.0

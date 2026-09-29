# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] — 2026-09-29

### Security

- `NewApiKey` and `WebhookEndpoint` redact their secret for `json_encode()` as well as for
  `var_dump()`. `__debugInfo()` covers `var_dump()` and nothing else, while a structured logger
  reaches for `json_encode()`: Monolog's `JsonFormatter`, and every JSON-lines formatter like it,
  encodes the context array, so `$log->info('key minted', ['key' => $new])` wrote the live
  `bbx_live_…` and the whole of `->raw` into the log stream. Both classes now implement
  `JsonSerializable`. `var_export()` and `serialize()` accept no hook in PHP and still carry the
  value, which the class docblocks now say out loud.
- An API key containing a control character, a newline or a non-ASCII character is refused at
  construction. curl does not sanitise what it is handed in `CURLOPT_HTTPHEADER`, so a `\r\n`
  inside the key was request smuggling with the caller's own credential as the payload. The message
  names the problem without quoting the key.
- A `baseUrl` carrying a query string or a fragment is refused. It did not fail, it went somewhere
  else: `https://host?x=1` silently produced `https://host?x=1/api/v1/credits`. A path prefix is
  still allowed, for a gateway that mounts BeaconBox under one.
- `Parse::enum` and the rest of the parse layer now agree field for field with the Python SDK's, on
  every shape either one is handed: a null, a number, a bool, an integral float and a wrongly typed
  string all read the same in both.

### Fixed

- `keys->create()` returns `$id`, so the key you just minted can be revoked in one call. The only
  other route to it was to list the keys and match on `$name` — and a name carries no unique
  constraint and defaults to "Untitled key", so two unnamed keys are indistinguishable and the
  match resolves to whichever the ordering happens to put first. That is a wrong-key revocation on
  the one path that exists to answer a leak. Needs the matching API change.
- **The API key is trimmed.** The constructor checked `trim($apiKey)` for emptiness and then stored
  the untrimmed value, so the commonest way a key arrives — `file_get_contents` on a secret file,
  or a `.env` line with trailing whitespace — put `Bearer bbx_live_…\n` on the wire. That is a 401
  nobody can explain from a key that looks correct.
- A timestamp with no offset is read as UTC. PHP applied `date.timezone`, so the same response
  parsed as two different instants on two hosts. A value that carries an offset is unaffected.
- An integral `float` in a numeric field is read as the integer. `is_int()` alone rejected it, so a
  proxy or a gateway that writes `1` as `1.0` silently reported zero credits in a field a merchant
  is billed on. A non-integral float is still not coerced.
- A fractional `Retry-After` is honoured. `ctype_digit` refused `1.5`, so the same 429 from the
  same server produced a server-directed wait in the Python SDK and an invented backoff here,
  against a README that promises the two are identical down to the constants.
- An absurd `Retry-After` is capped rather than overflowed. `((int) $seconds) * 1000` becomes a
  `float` past `PHP_INT_MAX`, and the parser declares `?int` under `strict_types`, so a broken or
  hostile header turned a 429 into a `TypeError` thrown from inside the retry loop.
- `messages->each()` refuses a `pageSize` below 1. The server clamps `limit` into its own range, so
  this was not an error there — it quietly became one message per request.

### Added

- `userAgentSuffix:` on `BeaconBoxClient`, for identifying an integration in a support
  conversation. Parity with the Python SDK's `user_agent_suffix`, which had no counterpart here.
  Anything that cannot go in a header value is stripped from it rather than refused, byte for byte
  the way the Python SDK now does it — a test in each SDK pins the same five inputs to the same five
  outputs, because a `User-Agent` that differs between them is the kind of difference nobody notices
  until it is in a support conversation about which client sent what.

### Note

- `NewApiKey` gained `id` as its **first** field. It is a response model built by `fromArray()`, so this
  affects only code constructing one positionally by hand.

## [0.2.0] — 2026-09-22

### Added

- `$delivery->notSent` on a message: why no email was ever sent, and when we decided. Present only
  when that decision stands — a later successful send withdraws it. It is what tells
  `delivered === false` apart from *never attempted, and never will be*, so a loop polling for
  delivery now has
  something to stop on. `EmailSkipReason` lists the known values; `$reason` stays a plain string,
  because the server's list grows and an unknown value must not raise.
- `message.not_sent` webhook event, carrying the same `reason`. Deliberately not folded into
  `message.failed`: nothing was attempted and nothing bounced.
- `plan.past_due`, `plan.lapsed` and `plan.allowance_exceeded` webhook events. The second is the
  one to alert on — it means email sending has stopped, and every nudge from then on produces a
  `message.not_sent` with `reason: "plan_lapsed"` until the subscription is paid.

### Note

- `DeliveryStatus` gained a constructor parameter before `$raw`. It is a response model built by
  `fromArray()`, so this affects only code constructing one positionally by hand.

## [0.1.0]

### Added

- `BeaconBoxClient` with 18 operations across `messages`, `recipients`, `keys`, `credits` and
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

[0.3.0]: https://github.com/beaconbox-eu/beaconbox-php/releases/tag/v0.3.0
[0.2.0]: https://github.com/beaconbox-eu/beaconbox-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/beaconbox-eu/beaconbox-php/releases/tag/v0.1.0

# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[0.2.0]: https://github.com/beaconbox-eu/beaconbox-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/beaconbox-eu/beaconbox-php/releases/tag/v0.1.0

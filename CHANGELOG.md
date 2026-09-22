# Changelog

All notable changes to the BeaconBox PHP SDK are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Versioning is independent of the Python SDK and of the API: the two clients ship on their own
cadence, so the numbers will diverge.

Published to Packagist as `beaconbox/beaconbox-php`.

**For a client library the surface that counts is the one callers touch.** A renamed argument or a
changed return type is a major, even when the wire protocol did not move. A new method or an
optional argument is a minor.

## [0.1.0]

First public release, so everything is new and nothing is a fix: there is no earlier version
anybody could have installed.

### Added

- **`BeaconBoxClient`**, a synchronous client over a bundled curl transport. PHP 8.1+.
- **A PSR-18 seam.** Inject your own `ClientInterface` plus PSR-17 request and stream factories and
  the bundled transport is not used at all — which is how this works under Swoole or ReactPHP,
  where pooling is yours rather than ours.
- **Eighteen operations** across five resources: `messages` (push, pushBatch, get, list, retract,
  resendSms, resendWhatsApp), `recipients` (sms, setPhone, clearPhone, eraseWhatsApp), `keys`
  (list, create, revoke), `credits` (balance) and `webhookEndpoints` (list, create, delete).
  `messages->each()` is a pagination helper over `list`, not a nineteenth call.
- **Typed readonly models for every response**, so an editor completes the fields and PHPStan
  checks them. Anything the API adds that this version does not know about is still reachable
  through `->raw`, so a server-side addition never costs a caller an upgrade.
- **`Webhooks::verify`** for inbound signatures: HMAC-SHA256 over `"{timestamp}.{rawBody}"`, with a
  300-second freshness window so a captured delivery cannot be replayed later.
- **Opt-in PSR-3 logging** of the request and retry lifecycle. Silent until the host application
  passes a logger. API keys, request and response bodies and query strings are never logged; the
  idempotency key **is**, deliberately, because a retry that minted a fresh one would be a
  duplicate message and that log line is where it would be visible.

### The two things a caller should not have to think about

Documented here rather than left to a README nobody reads twice, because both are conventions an
SDK is unusually likely to get wrong in a way that costs the caller money.

- **One idempotency key is threaded through every retry of a call.** A retry that mints a fresh key
  is not a retry — it is a second order-update email and a second charged SMS. The key is generated
  once per logical call and reused for every attempt of it, so a "helpful" retry is safe by
  construction rather than by the caller remembering.
- **A skipped channel does not throw.** A push returns 201 even when the SMS or WhatsApp message
  was not sent: the update is already in the recipient's inbox and the email nudge has gone, so a
  paid channel that could not send reports a reason on the result instead. Throwing would be the
  SDK inventing a failure the API deliberately did not report. Read the response, not the status
  code.

### How it behaves under load

Not a change log — this release's behaviour, written down because it is what a caller has to reason
about when a queue backs up and it is not guessable from the method signatures.

- **A `Retry-After` is honoured, not clamped to the backoff ceiling.** That ceiling caps a delay the
  SDK invented; the server's directive is the only accurate information anybody has about when the
  service will be ready, so it gets its own setting (`RetryPolicy::$maxRetryAfterMs`, 30s).
- **Jitter is added on top of the directive, never sampled from within it.** Obeying a directive
  exactly puts every worker that hit the same 429 back on the wire in the same millisecond, which
  is the lockstep jitter exists to break. Sampling *within* it would wait less than the server
  asked, which only earns a second 429.
- **A directed wait longer than `maxRetryAfterMs` fails fast rather than retrying early.** Retrying
  before the moment the server named is a request already guaranteed to be refused.
  `RateLimitException` carries `retryAfterMs` (from `ApiException`), so the number is handed back
  rather than swallowed.

### Requirements

`php >= 8.1`, `ext-curl`, `ext-json`, and the PSR interface packages `psr/http-client`,
`psr/http-factory` and `psr/log`. All three are interface-only: no HTTP stack is pulled into the
host application, because a library that drags one in is a library that eventually conflicts with
it.

[0.1.0]: https://github.com/beaconbox-eu/beaconbox-php/releases/tag/v0.1.0

# Live tests (PHP)

These run the SDK against a **real** BeaconBox. They are a separate PHPUnit suite, so
`composer test` stays hermetic and fast.

## Running them

```bash
export BEACONBOX_LIVE_URL=https://api.beaconbox.eu
export BEACONBOX_API_KEY=bbx_live_...
composer test:live
```

| Variable | What it does |
| --- | --- |
| `BEACONBOX_LIVE_URL` | The BeaconBox to test against. Required; absent means skip. |
| `BEACONBOX_API_KEY` | A key for a **throwaway** business. Required; absent means skip. |
| `BEACONBOX_LIVE_RECIPIENT` | Recipient address. Defaults to `live-sdk@example.com`. |
| `BEACONBOX_LIVE_CA_BUNDLE` | A CA bundle, for a target serving a privately issued certificate. |

`BEACONBOX_LIVE_CA_BUNDLE` is passed straight to the client's `caBundle:`, which exercises the same
option a merchant behind a TLS-inspecting corporate proxy needs. The suite takes a bundle rather
than turning verification off, so the SDK's security default is never the thing under test.

**The account needs a positive credit balance for the paid-send test to run.** On a zero balance
every push answers `insufficient_credit`, `$delivery->sms` stays null, and the paid-send test skips
with a message saying so.

## What they are for

Every hermetic test asserts against a payload this repository wrote. If the SDK and the API
disagree about a field name, both sides of a unit test agree with each other and are wrong
together: the suite passes and every real call fails. These tests are what close that gap.

So the assertions here are deliberately shallow. What matters is that the server accepted the
request and the SDK understood the answer. The exceptions are the behaviours no mock can prove:
that an `Updateable` push with a repeated subject updates in place, that two pushes under one
idempotency key are one message in the real store, and that a key minted through `keys->create()`
actually authenticates.

## Notes

- **They write.** Each run pushes real messages and mints and revokes real keys. Point them at a
  throwaway business, never at production.
- Subjects are uniquified per run, because an `Updateable` push matches on subject and a reused one
  would update the previous run's message.
- Keys and webhook endpoints created here are cleaned up in `finally` blocks.

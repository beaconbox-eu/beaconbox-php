<?php

declare(strict_types=1);

/**
 * Drive the API the way a merchant's PHP integration does, and report what broke.
 *
 * The companion to `sdk/python/examples/beta_smoke.py`, and it earns its keep by not being a
 * translation of it. The server behaviour is already covered there; what is unproven until this
 * runs is **this SDK's** half — that its models parse what the API actually sends, that its
 * idempotency key is attached where it must be, and that its error types are thrown where a
 * merchant will catch them.
 *
 * Read-only and additive. Nothing here suppresses an address or deletes anything it did not
 * create.
 *
 *   BEACONBOX_API_KEY=bbx_live_... php examples/beta_smoke.php --to=test@bloomharbor.eu
 */

use BeaconBox\BeaconBoxClient;
use BeaconBox\Exception\InvalidRequestException;
use BeaconBox\Model\BatchItemResult;
use BeaconBox\Model\MessagePush;

require __DIR__ . '/../vendor/autoload.php';

/** @var list<array{string, bool, string}> $results */
$results = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $results;
    $results[] = [$name, $ok, $detail];
    printf("  %-5s %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $ok || $detail === '' ? '' : " — {$detail}");
}

$options = getopt('', ['to:']);
$to = \is_string($options['to'] ?? null) ? $options['to'] : '';
$apiKey = getenv('BEACONBOX_API_KEY');
if ($to === '' || !\is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "Usage: BEACONBOX_API_KEY=... php examples/beta_smoke.php --to=you@example.com\n");
    exit(2);
}

$client = new BeaconBoxClient($apiKey);
$run = substr(bin2hex(random_bytes(8)), 0, 8);
printf("\nRun %s\n\n", $run);

// --- the ordinary path ----------------------------------------------------------------
echo "Single push\n";
$first = $client->messages->push(new MessagePush(
    recipientEmail: $to,
    subject: "Order #{$run} is on its way",
    body: 'Your order shipped today. Tracking: XX000000000XX.',
    reference: "#{$run}",
));
check('push accepted', $first->created && $first->nudged);
// The per-channel block. On a clean recipient this must report sending; if it does not, the
// address is already suppressed and every later assertion is measuring the wrong thing.
check(
    'email block reports sending',
    $first->email !== null && $first->email->sending,
    var_export($first->email, true),
);

// --- idempotency: what the SDK's retry safety rests on --------------------------------
echo "\nIdempotency\n";
$key = sprintf('%s-replay', $run);
$one = $client->messages->push(
    new MessagePush(recipientEmail: $to, subject: "Replay {$run}", body: 'First.'),
    $key,
);
$two = $client->messages->push(
    // A DIFFERENT body under the SAME key. The stored response must come back unchanged — if the
    // second body were stored, every retry this SDK performs would be a silent overwrite.
    new MessagePush(recipientEmail: $to, subject: "Replay {$run}", body: 'Second — must not store.'),
    $key,
);
check('replay returns the original id', $one->id === $two->id, "{$one->id} vs {$two->id}");
check('replay does not re-create', $two->created === false);

// --- batch: always 200, so `failed` is the thing to read ------------------------------
echo "\nBatch push\n";
$batch = $client->messages->pushBatch([
    new MessagePush(recipientEmail: $to, subject: "Batch {$run} a", body: 'Item a.'),
    new MessagePush(recipientEmail: $to, subject: "Batch {$run} b", body: 'Item b.'),
]);
check('batch accepted all', $batch->failed === 0, "failed={$batch->failed}");
// Per item, not per batch: the email verdict is about one recipient's suppression state, so a
// batch sharing one verdict would be the bug rather than the feature.
$withVerdict = array_filter(
    $batch->items,
    static fn (BatchItemResult $item): bool => $item->result?->email !== null,
);
check(
    'every batch item carries its own email verdict',
    \count($withVerdict) === \count($batch->items),
    sprintf('%d of %d', \count($withVerdict), \count($batch->items)),
);

// --- reads ----------------------------------------------------------------------------
echo "\nReads\n";
$fetched = $client->messages->get($first->id);
check('get returns the message', $fetched->id === $first->id);
$balance = $client->credits->balance();
// Asserted on a value rather than on non-nullness: `balance()` cannot return null, so a
// `!== null` here would be a check that can never fail.
check('credit balance parses', $balance->balance >= 0, "balance={$balance->balance}");

// --- errors: the type a merchant will actually catch ----------------------------------
echo "\nError handling\n";
try {
    $client->messages->push(new MessagePush(recipientEmail: 'not-an-email', subject: 'x', body: 'y'));
    check('invalid email rejected', false, 'the API accepted a malformed address');
} catch (InvalidRequestException $e) {
    check('invalid email throws InvalidRequestException', true, $e->getMessage());
}

$failed = array_filter($results, static fn (array $r): bool => !$r[1]);
printf("\n%d passed, %d failed\n", \count($results) - \count($failed), \count($failed));
exit($failed === [] ? 0 : 1);

<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use Dn\Db\Database;
use Dn\Tenancy\TenantContext;
use Dn\Http\Idempotency;

$owner = Database::owner();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];
$ctx = new TenantContext(Database::app());
$body = ['plan' => 'day', 'count' => 10];

t('first call proceeds, second replays');
$r1 = $ctx->run($A['customer'], fn($db) => (new Idempotency($db))
    ->begin($A['customer'], $A['principal'], 'key-1', 'POST /me/vouchers', $body));
is_($r1['replay'], false, 'first call is not a replay');

$ctx->run($A['customer'], fn($db) => (new Idempotency($db))
    ->complete($A['customer'], 'key-1', 202, ['intent_id' => 'abc']));

$r2 = $ctx->run($A['customer'], fn($db) => (new Idempotency($db))
    ->begin($A['customer'], $A['principal'], 'key-1', 'POST /me/vouchers', $body));
is_($r2['replay'], true, 'second call is a replay');
is_($r2['status'], 202, 'the stored status comes back');
is_($r2['body']['intent_id'], 'abc', 'the stored body comes back — not a new intent');

t('a key reused with a different body is a conflict, not a replay');
// Answering this with the previous response would hide a client bug behind a
// plausible success.
$r3 = $ctx->run($A['customer'], fn($db) => (new Idempotency($db))
    ->begin($A['customer'], $A['principal'], 'key-1', 'POST /me/vouchers',
            ['plan' => 'week', 'count' => 500]));
is_(isset($r3['conflict']), true, 'differing body is rejected');
is_(isset($r3['replay']), false, 'and is NOT answered as a replay');

t('an in-flight key is a conflict, not a duplicate execution');
$ctx->run($A['customer'], fn($db) => (new Idempotency($db))
    ->begin($A['customer'], $A['principal'], 'key-2', 'POST /me/vouchers', $body));
$r4 = $ctx->run($A['customer'], fn($db) => (new Idempotency($db))
    ->begin($A['customer'], $A['principal'], 'key-2', 'POST /me/vouchers', $body));
is_(isset($r4['conflict']), true, 'a still-in-flight key does not run twice');

t('idempotency keys do not cross customers');
// B using the same key string must get a fresh call, not A's stored response.
$r5 = $ctx->run($B['customer'], fn($db) => (new Idempotency($db))
    ->begin($B['customer'], $B['principal'], 'key-1', 'POST /me/vouchers', $body));
is_($r5['replay'], false, "B reusing A's key string gets a fresh call");
is_(isset($r5['body']), false, "B never receives A's stored response body");

$rows = $ctx->run($B['customer'], fn($db) => $db->query('SELECT key FROM mt_idempotency'));
is_(count($rows), 1, 'B sees only its own idempotency rows');

exit(t_summary());

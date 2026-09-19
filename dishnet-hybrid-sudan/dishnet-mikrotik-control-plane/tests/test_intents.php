<?php
declare(strict_types=1);
/**
 * The intent engine.
 *
 * docs/55 step 3 exit condition: AN INTENT SURVIVES A CRASH MID-DELIVERY.
 * That is the section headed CRASH SAFETY below; everything else supports it.
 */
require __DIR__ . '/bootstrap.php';
use Dn\Db\Database;
use Dn\Delivery\DeliveryPort;
use Dn\Delivery\DeliveryResult;
use Dn\Intents\IntentQueue;
use Dn\Intents\IntentState;
use Dn\Jobs\IntentWorker;
use Dn\Tenancy\TenantContext;

$owner = Database::owner();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];
$db  = Database::app();
$ctx = new TenantContext($db);
// Claiming is a WORKER privilege after docs/57 §10; the request role has no
// EXECUTE on the claim primitive.
$workDb = Database::worker();
$ctxW   = new TenantContext($workDb);
$q      = new IntentQueue($workDb);

/** A delivery double whose behaviour each test chooses. */
final class Scripted implements DeliveryPort
{
    public array $delivered = [];
    public array $confirmed = [];
    public function __construct(
        private $onDeliver = null,
        private $onConfirm = null,
    ) {}
    public function deliver(\Dn\Db\Database $db, array $i): DeliveryResult {
        $this->delivered[] = $i['id'];
        return ($this->onDeliver)($i);
    }
    public function confirm(\Dn\Db\Database $db, array $i): bool {
        $this->confirmed[] = $i['id'];
        return ($this->onConfirm)($i);
    }
}
$always = fn() => DeliveryResult::accepted();
$yes    = fn() => true;

// ---------------------------------------------------------------------------
t('enqueue');
$i1 = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue(
    $A['customer'], 'voucher.create', ['count' => 5], $A['principal'], 'site', $A['site']));
is_($i1['state'], IntentState::QUEUED, 'a new intent is queued');
is_((int) $i1['attempts'], 0, 'with no attempts yet');
is_($i1['kind'], 'voucher.create', 'and the kind it was given');

t('an idempotency key does not queue the same work twice');
$k = 'req-abc';
$x1 = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue(
    $A['customer'], 'voucher.create', ['count' => 5], $A['principal'], null, null, $k));
$x2 = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue(
    $A['customer'], 'voucher.create', ['count' => 5], $A['principal'], null, null, $k));
is_($x1['id'], $x2['id'], 'the second call returns the first intent');
$n = $ctx->run($A['customer'], fn($d) => (int) $d->one(
    'SELECT count(*) AS n FROM mt_intents WHERE idempotency_key = ?', [$k])['n']);
is_($n, 1, 'and exactly one row exists');

// ---------------------------------------------------------------------------
t('STATE MACHINE — the database refuses illegal transitions');
$bad = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue($A['customer'], 'x'));
throws_(fn() => $ctx->run($A['customer'], fn($d) => $d->exec(
    "UPDATE mt_intents SET state = 'confirmed' WHERE id = ?", [$bad['id']])),
    'illegal', 'queued cannot jump straight to confirmed');

$ctx->run($A['customer'], fn($d) => $d->exec("UPDATE mt_intents SET state='sent' WHERE id=?", [$bad['id']]));
$ctx->run($A['customer'], fn($d) => $d->exec("UPDATE mt_intents SET state='confirmed' WHERE id=?", [$bad['id']]));
throws_(fn() => $ctx->run($A['customer'], fn($d) => $d->exec(
    "UPDATE mt_intents SET state = 'queued' WHERE id = ?", [$bad['id']])),
    'terminal', 'a confirmed intent cannot be reopened');
throws_(fn() => $ctx->run($A['customer'], fn($d) => $d->exec(
    "UPDATE mt_intents SET state = 'sent' WHERE id = ?", [$bad['id']])),
    'terminal', 'nor moved back to sent');

// ---------------------------------------------------------------------------
t('CRASH SAFETY — a claim is a lease, not a handover');
$c = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue($A['customer'], 'crash.test'));
$got = $q->claim('worker-1', '5 minutes', 10);
$mine = array_filter($got, fn($r) => $r['id'] === $c['id']);
is_(count($mine), 1, 'worker-1 claims it');

$again = $q->claim('worker-2', '5 minutes', 10);
$stolen = array_filter($again, fn($r) => $r['id'] === $c['id']);
is_(count($stolen), 0, 'worker-2 cannot claim it while the lease holds');

t('CRASH SAFETY — the work returns when the lease lapses');
// worker-1 is now "dead": it claimed and never finished.
$owner->exec("UPDATE mt_intents SET lease_expires_at = now() - interval '1 second' WHERE id = ?", [$c['id']]);
$recovered = $q->claim('worker-2', '5 minutes', 10);
$back = array_filter($recovered, fn($r) => $r['id'] === $c['id']);
is_(count($back), 1, 'worker-2 picks it up after the lease lapses');
$row = $owner->one('SELECT state, claimed_by FROM mt_intents WHERE id = ?', [$c['id']]);
is_($row['state'], IntentState::QUEUED, 'and it is still queued — the crash lost nothing');
is_($row['claimed_by'], 'worker-2', 'now held by the surviving worker');

t('CRASH SAFETY — two workers racing never claim the same intent');
// FOR UPDATE SKIP LOCKED is what makes this true; without it both would read
// the same row and both would deliver.
$owner->exec('DELETE FROM mt_intents');
for ($i = 0; $i < 20; $i++) {
    $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue($A['customer'], 'race.test'));
}
$w1 = (new IntentQueue(Database::worker()))->claim('race-1', '5 minutes', 20);
$w2 = (new IntentQueue(Database::worker()))->claim('race-2', '5 minutes', 20);
$o1 = array_column($w1, 'id'); $o2 = array_column($w2, 'id');
is_(count(array_intersect($o1, $o2)), 0, 'no intent is claimed by both workers');
is_(count($o1) + count($o2), 20, 'and between them they claim every one, exactly once');

// ---------------------------------------------------------------------------
t('RETRY — a retryable failure backs off rather than spinning');
$owner->exec('DELETE FROM mt_intents');
$r = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue($A['customer'], 'retry.test'));
$flaky = new Scripted(fn() => DeliveryResult::retryable('router unreachable'), $yes);
$w = new IntentWorker($workDb, $ctxW, $q, $flaky, 'w-retry');
$out = $w->runOnce();
is_($out['retrying'], 1, 'one intent is retrying');
$row = $owner->one('SELECT state, attempts, next_attempt_at > now() AS backed_off,
                           claimed_by FROM mt_intents WHERE id = ?', [$r['id']]);
is_($row['state'], IntentState::QUEUED, 'it returns to queued');
is_((int) $row['attempts'], 1, 'one attempt recorded');
is_($row['backed_off'], true, 'and it is not due again immediately');
is_($row['claimed_by'], null, 'the lease is released');
is_(count($q->claim('w-x', '5 minutes', 10)), 0, 'so no worker picks it up before the backoff');

t('RETRY — attempts are bounded, then it fails');
$owner->exec("UPDATE mt_intents SET attempts = 4, max_attempts = 5, next_attempt_at = now() WHERE id = ?", [$r['id']]);
$w->runOnce();
$row = $owner->one('SELECT state, last_error FROM mt_intents WHERE id = ?', [$r['id']]);
is_($row['state'], IntentState::FAILED, 'the last attempt fails it permanently');
is_(str_contains($row['last_error'], 'unreachable'), true, 'and records why');

t('RETRY — a permanent failure does not burn five attempts first');
$p = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue($A['customer'], 'perm.test'));
$broken = new Scripted(fn() => DeliveryResult::permanent('malformed request'), $yes);
(new IntentWorker($workDb, $ctxW, $q, $broken, 'w-perm'))->runOnce();
$row = $owner->one('SELECT state, attempts FROM mt_intents WHERE id = ?', [$p['id']]);
is_($row['state'], IntentState::FAILED, 'it fails at once');
is_((int) $row['attempts'] <= 1, true, 'without retrying something that can never work');

// ---------------------------------------------------------------------------
t('CONFIRMATION is a read, never the write\'s own return value');
$owner->exec('DELETE FROM mt_intents');
$ok = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue($A['customer'], 'confirm.test'));
// The router ACCEPTS the command and does not apply it. A design that trusted
// the write's success would call this done.
$lying = new Scripted($always, fn() => false);
$out = (new IntentWorker($workDb, $ctxW, $q, $lying, 'w-lie'))->runOnce();
is_($out['confirmed'], 0, 'an accepted-but-unapplied command is NOT confirmed');
is_(count($lying->confirmed), 1, 'confirm() was actually consulted');
$row = $owner->one('SELECT state FROM mt_intents WHERE id = ?', [$ok['id']]);
is_($row['state'], IntentState::QUEUED, 'it goes back for another look rather than being called done');

t('the happy path confirms');
$owner->exec('DELETE FROM mt_intents');
$h = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue($A['customer'], 'happy.test'));
$good = new Scripted($always, $yes);
$out = (new IntentWorker($workDb, $ctxW, $q, $good, 'w-good'))->runOnce();
is_($out['confirmed'], 1, 'delivered and confirmed');
$row = $owner->one('SELECT state, sent_at IS NOT NULL AS s, confirmed_at IS NOT NULL AS c
                      FROM mt_intents WHERE id = ?', [$h['id']]);
is_($row['state'], IntentState::CONFIRMED, 'state is confirmed');
is_([$row['s'], $row['c']], [true, true], 'both timestamps are set');

// ---------------------------------------------------------------------------
t('EXPIRY — work past its deadline stops rather than queueing forever');
$owner->exec('DELETE FROM mt_intents');
$e = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue($A['customer'], 'old.test'));
$owner->exec("UPDATE mt_intents SET deadline_at = now() - interval '1 hour' WHERE id = ?", [$e['id']]);
is_($q->expireOverdue(), 1, 'the sweep expires it');
is_($owner->one('SELECT state FROM mt_intents WHERE id = ?', [$e['id']])['state'],
    IntentState::EXPIRED, 'state is expired');
is_(count($q->claim('w-z', '5 minutes', 10)), 0, 'and it is no longer claimable');

// ---------------------------------------------------------------------------
t('ISOLATION — intents do not cross customers');
$owner->exec('DELETE FROM mt_intents');
$ia = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->enqueue($A['customer'], 'a.only'));
$ib = $ctx->run($B['customer'], fn($d) => (new IntentQueue($d))->enqueue($B['customer'], 'b.only'));
$seen = $ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->forCustomer());
is_(count($seen), 1, 'A sees one intent');
is_($seen[0]['kind'], 'a.only', "and it is A's");
is_($ctx->run($A['customer'], fn($d) => (new IntentQueue($d))->find($ib['id'])), null,
    "A cannot fetch B's intent by id");

t('a worker serving both customers still writes each audit row to the right one');
$good = new Scripted($always, $yes);
(new IntentWorker($workDb, $ctxW, $q, $good, 'w-both'))->runOnce();
// Scoped to the two intents created just above: mt_audit_log is append-only
// by design, so earlier sections' rows are still there and counting the whole
// table would measure the test's own history rather than this behaviour.
$aAudit = $owner->query(
    "SELECT customer_id FROM mt_audit_log
      WHERE action = 'intent.confirmed' AND target_id IN (?, ?)",
    [$ia['id'], $ib['id']]);
$byCustomer = array_count_values(array_column($aAudit, 'customer_id'));
is_(count($aAudit), 2, 'two confirmations were audited');
is_($byCustomer[$A['customer']] ?? 0, 1, "one against A");
is_($byCustomer[$B['customer']] ?? 0, 1, "one against B");

exit(t_summary());

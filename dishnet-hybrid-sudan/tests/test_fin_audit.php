<?php
declare(strict_types=1);
/**
 * test_fin_audit.php — "who changed this, and what did it say before?"
 *
 * That question had no answer. A cashbook row could be edited to a different
 * amount, or deleted outright, and afterwards there was nothing left: no
 * actor, no previous value, no trace the row had ever existed. The activity
 * log is a 500-entry JSON ring with no actor field, so it could not answer
 * it either — and a ring that drops its oldest entries is not an audit
 * trail, it is a recent-events panel.
 *
 * voidEntry was the one operation that always did this properly: it stamps
 * the reason, the actor and the time into the row and keeps the row. These
 * assertions hold edit and delete to that same standard, and hold the audit
 * table itself to being append-only — a history that can be rewritten is
 * worth nothing.
 */
require_once dirname(__DIR__) . '/lib/StoreInterface.php';
require_once dirname(__DIR__) . '/lib/SqliteStore.php';
require_once dirname(__DIR__) . '/lib/CashbookService.php';
require_once dirname(__DIR__) . '/lib/FinAudit.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$tmp = sys_get_temp_dir() . '/dn_finaudit_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$cb    = new CashbookService($store, $tmp);
$pdo   = $store->getPdo();

$bhavin  = ['id' => 7,  'name' => 'Bhavin Madlani'];
$richard = ['id' => 12, 'name' => 'Richard'];

// ── changedKeys ─────────────────────────────────────────────────────────────
echo "\nWhat actually moved\n";
t('a changed field is named',
  FinAudit::changedKeys(['amount' => 100], ['amount' => 250]), ['amount']);
t('an unchanged field is not',
  FinAudit::changedKeys(['amount' => 100, 'category' => 'Receipt'],
                        ['amount' => 250, 'category' => 'Receipt']), ['amount']);
// SQLite hands back '1500' where PHP wrote 1500. Strict comparison would call
// every untouched numeric column changed and bury the one that really moved.
t('1500 and "1500" are the same number, not a change',
  FinAudit::changedKeys(['amount' => 1500], ['amount' => '1500']), []);
t('updated_at is ignored — it moves on every write and means nothing',
  FinAudit::changedKeys(['amount' => 1, 'updated_at' => 'a'],
                        ['amount' => 1, 'updated_at' => 'b']), []);
t('a field that appears is a change',
  FinAudit::changedKeys(['a' => 1], ['a' => 1, 'person' => 'Sam']), ['person']);
t('a create has nothing to compare against',
  FinAudit::changedKeys(null, ['amount' => 5]), []);

// ── An edit ─────────────────────────────────────────────────────────────────
echo "\nEditing a ledger row\n";
$cb->addEntryRaw(['project' => 'dishnet', 'date' => '2026-09-11', 'direction' => 'in',
                  'amount' => 1500000, 'currency' => 'UGX', 'category' => 'Receipt',
                  'description' => 'Family Shoppers — kit payment']);
$id = (int)$pdo->query("SELECT id FROM cb_ledger ORDER BY id DESC LIMIT 1")->fetchColumn();
is_($id > 0, 'a ledger row exists to edit');

$r = $cb->updateEntry($id, ['amount' => 1650000.0], $bhavin);
t('the edit succeeds', $r['ok'], true);
t('and reports which field moved', $r['changed'] ?? [], ['amount']);

$hist = FinAudit::history($pdo, 'cb_ledger', $id);
is_(count($hist) === 1, 'one audit row was written', 'got ' . count($hist));
$a = $hist[0] ?? [];
t('recorded as an update', $a['action'] ?? '', 'update');
t('naming the person who did it', $a['actor_name'] ?? '', 'Bhavin Madlani');
t('and their staff id', (int)($a['actor_id'] ?? 0), 7);
$before = json_decode((string)($a['before_json'] ?? ''), true) ?: [];
$after  = json_decode((string)($a['after_json']  ?? ''), true) ?: [];
t('the amount BEFORE the edit is recoverable', (float)($before['amount'] ?? 0), 1500000.0);
t('and the amount after', (float)($after['amount'] ?? 0), 1650000.0);
is_(strpos((string)($before['description'] ?? ''), 'Family Shoppers') !== false,
    'the whole row is kept, not just the field that changed');
t('changed_keys reads at a glance', $a['changed_keys'] ?? '', 'amount');
is_(trim((string)($a['created_at'] ?? '')) !== '', 'and it is timestamped');

// ── A delete ────────────────────────────────────────────────────────────────
echo "\nDeleting a ledger row\n";
$gone = $cb->deleteEntry($id, $richard, 'duplicated from the bank import');
t('the delete succeeds', $gone['ok'], true);
is_((int)$pdo->query("SELECT COUNT(*) FROM cb_ledger WHERE id={$id}")->fetchColumn() === 0,
    'the row really is gone from the ledger');

$hist = FinAudit::history($pdo, 'cb_ledger', $id);
is_(count($hist) === 2, 'the audit trail now has both events', 'got ' . count($hist));
$d = $hist[1] ?? [];
t('recorded as a delete', $d['action'] ?? '', 'delete');
t('by the person who deleted it, not the one who edited it', $d['actor_name'] ?? '', 'Richard');
t('with the reason they gave', $d['reason'] ?? '', 'duplicated from the bank import');
$snap = json_decode((string)($d['before_json'] ?? ''), true) ?: [];
t('THE DELETED ENTRY IS STILL READABLE — amount', (float)($snap['amount'] ?? 0), 1650000.0);
is_(strpos((string)($snap['description'] ?? ''), 'Family Shoppers') !== false,
    'and its description, so the deletion can be undone by hand');
// ?? answers the fallback for a key that IS present and null — which is
// precisely the value being asserted. array_key_exists tells them apart.
is_(array_key_exists('after_json', $d) && $d['after_json'] === null,
    'nothing came after a delete');

// ── A void ──────────────────────────────────────────────────────────────────
echo "\nVoiding a ledger row\n";
$cb->addEntryRaw(['project' => 'dishnet', 'date' => '2026-09-11', 'direction' => 'out',
                  'amount' => 90000, 'currency' => 'UGX', 'category' => 'Expense',
                  'description' => 'Fuel — install run']);
$vid = (int)$pdo->query("SELECT id FROM cb_ledger ORDER BY id DESC LIMIT 1")->fetchColumn();
$v = $cb->voidEntry($vid, 'entered against the wrong project', 'Bhavin Madlani');
t('the void succeeds', $v['ok'], true);
$vh = FinAudit::history($pdo, 'cb_ledger', $vid);
is_(count($vh) === 1, 'a void is audited like everything else');
t('recorded as a void', $vh[0]['action'] ?? '', 'void');
t('with the actor', $vh[0]['actor_name'] ?? '', 'Bhavin Madlani');
t('and the reason', $vh[0]['reason'] ?? '', 'entered against the wrong project');
is_((int)$pdo->query("SELECT COUNT(*) FROM cb_ledger WHERE id={$vid}")->fetchColumn() === 1,
    'and unlike a delete, the row itself stays');

// ── The trail cannot be rewritten ───────────────────────────────────────────
echo "\nThe trail is append-only\n";
$src = '';
foreach (['lib', 'includes', 'tabs', 'cron', 'tools'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $dir));
    foreach ($it as $f) {
        if ($f->isFile() && substr($f->getFilename(), -4) === '.php') $src .= file_get_contents($f->getPathname());
    }
}
is_(stripos($src, 'DELETE FROM fin_audit') === false,
    'nothing anywhere deletes from the audit table');
is_(stripos($src, 'UPDATE fin_audit') === false,
    'and nothing updates a row once written');

// ── A broken audit must never block the money ───────────────────────────────
echo "\nAn audit failure is not a payment failure\n";
$bad = new PDO('sqlite::memory:');
$bad->exec("CREATE TABLE fin_audit (id INTEGER PRIMARY KEY, wrong_shape TEXT)");
$n = FinAudit::record($bad, 'cb_ledger', 1, 'update', $bhavin, ['a' => 1], ['a' => 2]);
t('a write that cannot land returns 0 rather than throwing', $n, 0);
t('an unknown action is refused the same way',
  FinAudit::record($pdo, 'cb_ledger', 1, 'reverse-engineer', $bhavin), 0);
is_(count(FinAudit::history($pdo, 'cb_ledger', 999999)) === 0,
    'history for a record with none is empty, not an error');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

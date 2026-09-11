<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * cashbook_assign_accounts.php — give the homeless ledger rows a home.
 *
 *   php tools/cashbook_assign_accounts.php                     what has no account
 *   php tools/cashbook_assign_accounts.php --assign CB-5=3,CB-12=3
 *   php tools/cashbook_assign_accounts.php --assign CB-5=3 --commit
 *   php tools/cashbook_assign_accounts.php --all-to 3          every UGX orphan at once
 *
 * Every expense in this book was entered without an account. The money left,
 * and no cash box or bank account was any lighter for it. The currency total
 * stayed right, because a row with no account still counts in the position —
 * so nothing looked wrong, while not one individual account balance was true.
 *
 * This moves WHERE a row lives and nothing else. The amount, the date and the
 * direction are never touched, and every move is written to the audit trail
 * with what it was before.
 *
 * Changes nothing without --commit.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CashbookService.php';

$args = array_slice($argv, 1);
$val = function (string $f) use ($args): string {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};
$KNOWN = ['--assign', '--all-to', '--commit', '--all'];
foreach ($args as $a) {
    if (strpos($a, '--') !== 0 || in_array($a, $KNOWN, true)) continue;
    fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: " . implode(' ', $KNOWN) . "\n\n");
    exit(2);
}

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$cb      = new CashbookService($store, $dataDir);
$pdo     = $store->getPdo();
$commit  = in_array('--commit', $args, true);
$actor   = ['id' => 0, 'name' => 'account assignment'];

$accounts = [];
foreach ($cb->accounts(true) as $a) $accounts[(int)$a['id']] = $a;

$showAccounts = function () use ($accounts): void {
    echo "  ACCOUNTS\n";
    foreach ($accounts as $a) {
        printf("    %-4s %-34s %-4s %s\n", $a['id'], $a['name'], $a['currency'], $a['kind']);
    }
    echo "\n";
};

// A row by its SR (CB-12) or its numeric id, since the book status prints SRs.
$findRow = function (string $key) use ($pdo): ?array {
    $key = trim($key);
    if ($key === '') return null;
    if (ctype_digit($key)) {
        $st = $pdo->prepare("SELECT * FROM cb_ledger WHERE id = ?");
        $st->execute([(int)$key]);
        if ($r = $st->fetch(\PDO::FETCH_ASSOC)) return $r;
    }
    $st = $pdo->prepare("SELECT * FROM cb_ledger WHERE UPPER(sr) = ?");
    $st->execute([strtoupper($key)]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
};

$line = function (array $r): string {
    return sprintf("    %-7s %s %-3s %-4s %14s  %-16s %s",
        $r['sr'], $r['date'], strtoupper((string)$r['direction']), $r['currency'],
        number_format((float)$r['amount'], 0),
        mb_substr((string)$r['category'], 0, 16),
        mb_substr(preg_replace('/\s+/', ' ', (string)$r['description']) ?? '', 0, 40));
};

// ── What has no home ────────────────────────────────────────────────────────
$orphans = $cb->unassignedRows();
$assign  = trim($val('--assign'));
$allTo   = trim($val('--all-to'));

echo "\n  CASHBOOK — ROWS WITHOUT AN ACCOUNT" . ($commit ? '' : '   (dry run — nothing written)') . "\n";
echo "  " . str_repeat('─', 72) . "\n\n";

if ($orphans === [] && $assign === '' && $allTo === '') {
    echo "  Every active row is in an account. Nothing to do.\n\n";
    exit(0);
}

if ($assign === '' && $allTo === '') {
    $byCur = [];
    foreach ($orphans as $r) {
        $c = strtoupper((string)$r['currency']) ?: 'UNKNOWN';
        $byCur[$c] = $byCur[$c] ?? ['n' => 0, 'out' => 0.0, 'in' => 0.0];
        $byCur[$c]['n']++;
        $byCur[$c][$r['direction'] === 'out' ? 'out' : 'in'] += (float)$r['amount'];
    }
    foreach ($orphans as $r) echo $line($r) . "\n";
    echo "\n";
    foreach ($byCur as $c => $t) {
        printf("    %d row(s) in %s — %s out, %s in\n", $t['n'], $c,
               number_format($t['out'], 0), number_format($t['in'], 0));
    }
    echo "\n  While these have no account, the currency total is still right but no\n";
    echo "  single account balance is. Say where each one came from:\n\n";
    $showAccounts();
    echo "    php tools/cashbook_assign_accounts.php --assign CB-5=3,CB-12=3\n";
    echo "    php tools/cashbook_assign_accounts.php --all-to 3        (every orphan of that currency)\n\n";
    exit(0);
}

// ── Build the list of moves ─────────────────────────────────────────────────
$moves = []; $bad = [];

if ($allTo !== '') {
    if (!ctype_digit($allTo) || !isset($accounts[(int)$allTo])) {
        fwrite(STDERR, "\n  --all-to needs the NUMBER of an active account, not '{$allTo}'.\n\n");
        $showAccounts();
        exit(2);
    }
    $acct = $accounts[(int)$allTo];
    foreach ($orphans as $r) {
        // Only rows this account can legally hold. A UGX row cannot live in a
        // USD account, and a bulk assignment must not quietly skip that rule.
        if (strtoupper((string)$r['currency']) !== strtoupper((string)$acct['currency'])) continue;
        $moves[] = [$r, (int)$allTo];
    }
    if ($moves === []) {
        echo "  No unassigned rows are in " . $acct['currency'] . ", so '" . $acct['name']
           . "' cannot take any of them.\n\n";
        exit(1);
    }
}

foreach (array_filter(array_map('trim', explode(',', $assign))) as $pair) {
    if (!preg_match('/^([A-Za-z0-9\-]+)\s*=\s*(\d+)$/', $pair, $m)) {
        $bad[] = "{$pair} — expected something like CB-12=3";
        continue;
    }
    $row = $findRow($m[1]);
    if (!$row)                        { $bad[] = "{$m[1]} — no such ledger row"; continue; }
    if (!isset($accounts[(int)$m[2]])) { $bad[] = "{$pair} — no active account {$m[2]}"; continue; }
    $moves[] = [$row, (int)$m[2]];
}

if ($bad !== []) {
    echo "  I could not read these:\n";
    foreach ($bad as $b) echo "    ✗ {$b}\n";
    echo "\n";
    $showAccounts();
    exit(2);
}

// ── Do them ─────────────────────────────────────────────────────────────────
$done = 0; $errors = [];
foreach ($moves as [$row, $acctId]) {
    $to   = $accounts[$acctId];
    $from = (int)($row['account_id'] ?? 0);
    printf("%s\n", $line($row));
    printf("    %-7s   %s → %s\n", '',
        $from === 0 ? 'unassigned' : ($accounts[$from]['name'] ?? ('#' . $from)), $to['name']);

    if (!$commit) { $done++; continue; }
    $r = $cb->assignAccount((int)$row['id'], $acctId, $actor);
    if (empty($r['ok'])) { $errors[] = $row['sr'] . ': ' . (string)$r['error']; continue; }
    $done++;
}

echo "\n";
printf("    %-22s %d\n", $commit ? 'rows moved' : 'rows to move', $done);
if ($errors !== []) {
    echo "\n  ERRORS\n";
    foreach ($errors as $e) echo "    ✗ {$e}\n";
}

echo "\n";
if (!$commit) {
    echo "  Nothing was written. Add --commit to do it.\n\n";
    exit(0);
}
echo "  Done. Check it:  php tools/uganda_book_status.php\n\n";
exit($errors === [] ? 0 : 1);

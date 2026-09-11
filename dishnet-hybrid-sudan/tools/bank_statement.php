<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * bank_statement.php — put a bank statement into the cashbook.
 *
 *   php tools/bank_statement.php --file ecobank-ugx.csv                 look
 *   php tools/bank_statement.php --file ecobank-ugx.csv --account 2     look, against an account
 *   php tools/bank_statement.php --file ecobank-ugx.csv --account 2 --commit
 *
 * The statement is checked against its own running balance before anything
 * else happens. If one figure is wrong, one row is missing, or a column was
 * read as the wrong one, the chain breaks and the file is refused — there is
 * no importing a statement that does not add up.
 *
 * Supplier card payments are matched against booked purchases. A match moves
 * the money out of the bank and into inventory (buying stock is not an
 * expense). No match goes to a suspense account, so the bank still
 * reconciles and nothing claims stock that cannot be evidenced.
 *
 * Money arriving needs --deposits, because a bank cannot tell share capital
 * from a director's loan from a customer paying an invoice.
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
require_once $root . '/lib/BankStatement.php';
require_once $root . '/lib/BankImport.php';

$args = array_slice($argv, 1);
$val = function (string $f) use ($args): string {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};
$KNOWN = ['--file', '--account', '--deposits', '--commit'];
foreach ($args as $a) {
    if (strpos($a, '--') !== 0 || in_array($a, $KNOWN, true)) continue;
    fwrite(STDERR, "\n  Unknown option: {$a}\n  Known: " . implode(' ', $KNOWN) . "\n\n");
    exit(2);
}

$dataDir = cliDataDir($root);
$store   = SqliteStore::create($dataDir);
$cb      = new CashbookService($store, $dataDir);
$commit  = in_array('--commit', $args, true);

// ── The file ────────────────────────────────────────────────────────────────
$file = trim($val('--file'));
if ($file === '') {
    echo "\n  Give it a statement:  --file <csv>\n\n";
    echo "  Export the account activity from your bank as CSV. It needs a date\n";
    echo "  column, a description, debit and/or credit, and the running balance —\n";
    echo "  the balance is what proves the file was read correctly.\n\n";
    exit(1);
}
if (!is_file($file)) { fwrite(STDERR, "\n  No such file: {$file}\n\n"); exit(1); }

$p = BankStatement::parse((string)file_get_contents($file));
if (empty($p['ok'])) { fwrite(STDERR, "\n  " . $p['error'] . "\n\n"); exit(1); }
$rows = $p['rows'];

// ── Does it add up? ─────────────────────────────────────────────────────────
$chain = BankStatement::verifyChain($rows);
echo "\n  BANK STATEMENT" . ($commit ? '' : '   (dry run — nothing written)') . "\n";
printf("  file: %s — %d row(s), %s → %s\n", basename($file), count($rows),
       $rows[0]['date'], $rows[count($rows) - 1]['date']);
echo "  " . str_repeat('─', 72) . "\n\n";

if (!$chain['ok']) {
    echo "  ✗ THIS STATEMENT DOES NOT ADD UP — nothing will be imported.\n\n";
    foreach ($chain['breaks'] as $b) {
        printf("    line %-4d %s  %s\n", $b['line'], $b['date'], $b['description']);
        printf("             running balance should be %s, the file says %s (out by %s)\n",
            number_format($b['expected'], 2), number_format($b['stated'], 2),
            number_format($b['out_by'], 2));
    }
    echo "\n  A row is missing, a figure is mistyped, or a column was read as the\n";
    echo "  wrong one. Fix the file and run it again.\n\n";
    exit(1);
}
printf("  ✓ every row agrees with the bank's own running balance\n");
printf("    opening %s → closing %s\n\n",
    number_format($chain['opening'], 2), number_format($chain['closing'], 2));

$sum = BankStatement::summarise($rows);
printf("    %-16s %16s %16s\n", '', 'IN', 'OUT');
foreach ($sum['by_kind'] as $kind => $k) {
    printf("    %-16s %16s %16s   %d row(s)\n", $kind,
        $k['in']  > 0 ? number_format($k['in'], 0)  : '—',
        $k['out'] > 0 ? number_format($k['out'], 0) : '—', $k['count']);
}
printf("    %-16s %16s %16s\n\n", 'total',
    number_format($sum['in'], 0), number_format($sum['out'], 0));

// ── Which account ───────────────────────────────────────────────────────────
$acctId = 0;
$acctArg = trim($val('--account'));
if ($acctArg !== '') {
    if (!ctype_digit($acctArg) || (int)$acctArg < 1) {
        fwrite(STDERR, "  --account needs the NUMBER of a cashbook account, not '{$acctArg}'.\n\n");
        $acctArg = ''; $acctId = -1;
    } else {
        $acctId = (int)$acctArg;
        if (!$cb->account($acctId)) { fwrite(STDERR, "  There is no cashbook account {$acctId}.\n\n"); $acctId = -1; }
    }
}
if ($acctId <= 0) {
    echo "  Which account is this statement for?\n\n";
    foreach ($cb->accounts(true) as $a) {
        printf("    %-4s %-34s %-4s %s\n", $a['id'], $a['name'], $a['currency'], $a['kind']);
    }
    echo "\n    php tools/bank_statement.php --file " . escapeshellarg($file) . " --account <id>\n\n";
    exit($acctId < 0 ? 2 : 0);
}

$acct = $cb->account($acctId);
printf("  account: %s (%s, %s)\n\n", $acct['name'], $acct['currency'], $acct['kind']);

$imp = new BankImport($cb, $store->getPdo());
$r   = $imp->import($rows, $acctId, [
    'commit'   => $commit,
    'deposits' => trim($val('--deposits')),
    'actor'    => 'bank import',
]);

printf("    %-11s %-46s %s\n", 'DATE', 'WHAT THE BANK CALLS IT', 'WHAT HAPPENS TO IT');
foreach ($r['lines'] as $l) {
    printf("    %-11s %-46s %s\n", $l['date'],
        mb_substr(preg_replace('/\s+/', ' ', $l['description']) ?? '', 0, 46), $l['action']);
    if ($l['detail'] !== '') printf("    %-11s   %s\n", '', $l['detail']);
}

echo "\n";
printf("    %-26s %d\n", $commit ? 'rows booked' : 'rows to book', $r['posted']);
printf("    %-26s %d\n", 'already in the book', $r['already']);
printf("    %-26s %d\n", 'matched to a purchase', $r['matched']);
printf("    %-26s %d\n", 'to suspense', $r['suspense']);
if ($r['ambiguous'] > 0) printf("    %-26s %d\n", '  of which matched on date', $r['ambiguous']);
printf("    %-26s %d\n", 'waiting on a decision', $r['skipped']);

if ($r['notes'] !== []) {
    echo "\n  NOTES\n";
    foreach ($r['notes'] as $n) echo "    · {$n}\n";
}
if ($r['errors'] !== []) {
    echo "\n  ERRORS\n";
    foreach ($r['errors'] as $e) echo "    ✗ {$e}\n";
}

if ($r['needs_decision'] !== []) {
    echo "\n  THESE NEED YOU\n";
    foreach ($r['needs_decision'] as $n) {
        printf("    %s  %14s  %s\n", $n['date'],
            number_format($n['debit'] > 0 ? $n['debit'] : $n['credit'], 0),
            preg_replace('/\s+/', ' ', $n['description']));
    }
    echo "\n  Money coming IN can be booked once you say what it is:\n";
    echo "    --deposits capital    share capital — the owners put it in, nothing is owed back\n";
    echo "    --deposits director   a director's loan — the company owes it back\n";
    echo "    --deposits receipt    a customer paying us\n\n";
    echo "  Cash withdrawals need to be told where the cash went; do those by hand\n";
    echo "  on the Cashbook screen, or say the word and I will add a flag for them.\n";
}

echo "\n";
if (!$commit) {
    echo "  Nothing was written. To go ahead:\n\n";
    echo "    php tools/bank_statement.php --file " . escapeshellarg($file)
       . " --account {$acctId}" . ($val('--deposits') !== '' ? ' --deposits ' . $val('--deposits') : '')
       . " --commit\n\n";
    exit(0);
}
echo "  Done. Check it:  php tools/uganda_book_status.php\n\n";
exit($r['errors'] === [] ? 0 : 1);

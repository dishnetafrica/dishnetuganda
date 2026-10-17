<?php
declare(strict_types=1);
/**
 * test_cash_in_hand_p3b_mutants.php — P3b (5.18.96; SPEC §d.3): T1's weakened copies, committed. T1
 * (test_cash_in_hand_p3b.php) runs in process with no exec() and no shell, because deploy A22 and R25 run it on the
 * server; so the copies SPEC §d.3 assigns to T1 are applied here instead, the way T2 (§H), T3 (§4) and T4 (§E) apply
 * their own. Development and release suites only, never on the server: it copies the plugin and runs child processes.
 *
 * Each copy is made with sj_weakened_copy() (its anchor asserted unique), every further edit is asserted the same way,
 * and the copy must hold every change. The copy's own T1 then judges the copy, and must FAIL — and fail at the part of
 * T1 that SPEC §d.3 names for it ("caught by"), not merely somewhere. In order:
 *   1. the control: T1 on this checkout, and T1 on an unchanged copy made the same way, both pass with one tally;
 *   2. M1–M17 (the definition, the gate, the streams, the table check), M25 (T1 run on an empty book), M29 (T1 without
 *      its own DN_VAULT_FILE, run with a planted vault at the default path), and two more found in review: the
 *      table-exists memo caching false, and a cast on the account id (a text id read as a number);
 *   3. the control on the controls: T1 on f4b4c96's own code (5.18.94, before P3b) fails, at its precondition. No Git,
 *      or no such commit: a skip, never a pass;
 *   4. the end check: the default vault beside this checkout is untouched, and every file this run made is in its own
 *      temporary folder.
 *
 *   php test_cash_in_hand_p3b_mutants.php
 */
$lib = dirname(__DIR__);

// This run's own temporary folder, and TMPDIR pointed at it before anything asks PHP for one: every copy, every child's
// own folder and the vault planted for M29 land inside it, and it is removed at the end.
$own = rtrim((string)(getenv('TMPDIR') ?: '/tmp'), '/') . '/p3b-t1m-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($own, 0700, true);
putenv('TMPDIR=' . $own);
putenv('DN_VAULT_FILE=' . $own . '/children.vault.json');   // T1 sets its own at once; this is only what it inherits
register_shutdown_function(function () use ($own) { exec('rm -rf ' . escapeshellarg($own)); });
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';                 // sj_baseline_tree(), sj_weakened_copy()

$defVault  = dirname($lib) . '/.dishnet-sudan.vault.json';                  // ConfigVault::path()'s default for this tree
clearstatcache();
$defBefore = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;

$pass = 0; $fail = 0; $skip = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . substr($d, 0, 700) : '') . "\n"; }
}

const T1M_TEST = 'tests/test_cash_in_hand_p3b.php';

/** T1 run as a child on $tree: [exit code, its FAIL lines, [passed, failed] or null]. */
function t1m_child(string $test, string $tree, bool $vaultUnset = false): array {
    $keep = (string)getenv('DN_VAULT_FILE');
    if ($vaultUnset) putenv('DN_VAULT_FILE');                             // unset: ConfigVault falls back to its default path
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test) . ' --root=' . escapeshellarg($tree) . ' 2>&1', $out, $rc);
    if ($vaultUnset) putenv('DN_VAULT_FILE=' . $keep);
    $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
    $tally = preg_match('/^(\d+) passed, (\d+) failed$/', (string)end($out), $tm) ? [(int)$tm[1], (int)$tm[2]] : null;
    return [$rc, $fails, $tally];
}
function t1m_report(array $fails, ?array $tally): void {
    echo '         ' . count($fails) . ' assertion(s) failed' . ($tally ? " ({$tally[0]} passed)" : '')
       . ($fails ? '; first: ' . trim(substr($fails[0], 7, 120)) : '') . "\n";
}

// ═══════════════════════════════════════════════════════════════════════════
echo "1. The control: T1 passes on this checkout and on an unchanged copy, with one tally\n";
[$rc0, $fails0, $tally0] = t1m_child($lib . '/' . T1M_TEST, $lib);
is_($rc0 === 0 && $fails0 === [] && $tally0 !== null && $tally0[1] === 0 && $tally0[0] > 0,
    'T1 passes on this checkout: ' . ($tally0 ? "{$tally0[0]} passed, 0 failed" : 'no tally'), 'exit ' . $rc0 . ' ' . ($fails0[0] ?? ''));
$CB = 'lib/CashbookService.php';
[$tmp, $n] = sj_weakened_copy($lib, $CB, 'public const CASH_IN_HAND_KINDS', 'public const CASH_IN_HAND_KINDS');
if ($n === 1) {
    [$rc, $fails, $tally] = t1m_child($tmp . '/' . T1M_TEST, $tmp);
    is_($rc === 0 && $fails === [] && $tally !== null && $tally === $tally0,
        'the unchanged copy passes with the same tally (' . ($tally ? "{$tally[0]} passed, 0 failed" : 'no tally') . ')',
        'exit ' . $rc . ' tally ' . json_encode($tally) . ' ' . ($fails[0] ?? ''));
} else {
    is_(false, 'the unchanged copy: its anchor occurs once', "found {$n} times");
}
exec('rm -rf ' . escapeshellarg($tmp));

// ═══════════════════════════════════════════════════════════════════════════
echo "\n2. Weakened copies: each must fail T1, at the part SPEC §d.3 names\n";
$GATE   = "\$this->cashRuleCache = StaffJobsGate::applies(\$cfg, \$this->dataDir) && !dn_ssp_selectable(\$cfg);";
$HEAD   = "return \" AND status='approved' AND (COALESCE(account_id,0)=0\"";
$IN     = "\" OR account_id IN (SELECT id FROM cb_accounts WHERE kind IN ({\$kinds}))\"";
$KINDS  = "\$kinds = implode(',', array_map([\$this->pdo(), 'quote'], self::CASH_IN_HAND_KINDS));";
$T1     = T1M_TEST;
// [label, [[file, anchor, replacement], ...], the FAIL line it must produce (SPEC §d.3's "caught by"), vault unset?]
$MUTANTS = [
    ['M1: status=\'approved\' dropped (every row not voided counts)', [[$CB, $HEAD, "return \" AND (COALESCE(account_id,0)=0\""]],
        '/^  FAIL \[(pending_approval|rejected)\] /', false],
    ['M2: account_id=0 in place of COALESCE(account_id,0)=0', [[$CB, $HEAD, "return \" AND status='approved' AND (account_id=0\""]],
        '/NULL row|\[NULL account\]/', false],
    ['M3a: LOWER(kind)', [[$CB, $IN, "\" OR account_id IN (SELECT id FROM cb_accounts WHERE LOWER(kind) IN ({\$kinds}))\""]],
        '/^  FAIL \[Cash kind\] /', false],
    ['M3b: TRIM(kind)', [[$CB, $IN, "\" OR account_id IN (SELECT id FROM cb_accounts WHERE TRIM(kind) IN ({\$kinds}))\""]],
        '/^  FAIL \[cash-space kind\] /', false],
    ['M4: active=1 on accounts', [[$CB, $IN, "\" OR account_id IN (SELECT id FROM cb_accounts WHERE kind IN ({\$kinds}) AND active=1)\""]],
        '/^  FAIL \[inactive momo\] /', false],
    ['M5: the account\'s currency must match the row\'s', [[$CB, $IN,
        "\" OR account_id IN (SELECT id FROM cb_accounts WHERE kind IN ({\$kinds}) AND cb_accounts.currency = cb_ledger.currency)\""]],
        '/^  FAIL \[mismatched-currency cash row\] /', false],
    ['M6: MONEY_KINDS, bank included', [[$CB, $KINDS, "\$kinds = implode(',', array_map([\$this->pdo(), 'quote'], self::MONEY_KINDS));"]],
        '/^  FAIL \[bank\] /', false],
    ['M7: momo left out', [[$CB, $KINDS, "\$kinds = implode(',', array_map([\$this->pdo(), 'quote'], ['cash']));"]],
        '/^  FAIL \[momo\] /', false],
    ['M8: orphans counted (OR account_id NOT IN cb_accounts)', [[$CB, $IN,
        "\" OR account_id IN (SELECT id FROM cb_accounts WHERE kind IN ({\$kinds})) OR account_id NOT IN (SELECT id FROM cb_accounts)\""]],
        '/^  FAIL \[(orphan id|text id \(empty\)|text id \(word\))\] /', false],
    ['M8 (review): the account id cast before the IN (a digit-first text id read as the cash account)', [[$CB, $IN,
        "\" OR CAST(account_id AS INTEGER) IN (SELECT id FROM cb_accounts WHERE kind IN ({\$kinds}))\""]],
        '/^  FAIL \[text id \(digits first\)\] /', false],
    ['M9: the gate forced true (ungated)', [[$CB, $GATE, "\$this->cashRuleCache = true;"]],
        '/^  FAIL South Sudan \(south-sudan; USD,SSP\): cashOnlyRuleInForce\(\) is false/', false],
    ['M10: the tenant half removed', [[$CB, $GATE, "\$this->cashRuleCache = !dn_ssp_selectable(\$cfg);"]],
        '/^  FAIL a South Sudan tenant without SSP [^:]*: cashOnlyRuleInForce\(\) is false/', false],
    ['M11: the SSP half removed', [[$CB, $GATE, "\$this->cashRuleCache = StaffJobsGate::applies(\$cfg, \$this->dataDir);"]],
        '/^  FAIL a Uganda tenant with SSP [^:]*: cashOnlyRuleInForce\(\) is false/', false],
    ['M12: the gate forced false', [[$CB, $GATE, "\$this->cashRuleCache = false;"]],
        '/^  FAIL rule A is in force on the Uganda book U .* the precondition of sections 1–9/', false],
    ['M13: the base stream keeps the old predicate (both forms)', [
        [$CB, "WHERE project=? AND status NOT IN ('voided','voided_reconcile')\"\n                . \$cashWhere\n", "WHERE project=? AND status NOT IN ('voided','voided_reconcile')\"\n"],
        [$CB, "                 WHERE status NOT IN ('voided','voided_reconcile')\"\n                . \$cashWhere\n", "                 WHERE status NOT IN ('voided','voided_reconcile')\"\n"]],
        '/^  FAIL U [^:]*: (the top UGX Balance is UGX cash in hand|every Balance and its currency are the stream oracle\'s)/', false],
    ['M14: the other-currency stream keeps the old predicate', [[$CB,
        "('voided','voided_reconcile')\" . \$cashWhere . \"\n", "('voided','voided_reconcile')\" . \"\n"]],
        '/^  FAIL U All\/[^:]*: (the top USD Balance is USD cash in hand|every Balance and its currency are the stream oracle\'s)/', false],
    ['M15: the filtered base stream without blank currency', [[$CB,
        "\$currWhere  = (\$currFilter === '' || (\$cashRule && \$currFilter === \$base))", "\$currWhere  = (\$currFilter === '')"]],
        '/^  FAIL U UGX\/[^:]*: (the top UGX Balance is UGX cash in hand|every Balance and its currency are the stream oracle\'s)/', false],
    ['M16: the table check removed (the subquery always)', [[$CB, "            . (\$this->accountsTableExists()\n", "            . (true\n"]],
        '/^  FAIL (every read runs without an error|E15, the book without accounts: ran to the end without an error)/', false],
    ['M17: ensureAccountsTable() on the read path', [[$CB,
        "        if (!\$this->cashOnlyRuleInForce()) return '';\n",
        "        if (!\$this->cashOnlyRuleInForce()) return '';\n        \$this->ensureAccountsTable();\n"]],
        '/^  FAIL (sqlite_master is byte-identical after the reads|and there is still no cb_accounts table)/', false],
    ['the table-exists memo caches false too (review)', [[$CB,
        'if ($this->hasAccountsTable === true) return true;',
        'if ($this->hasAccountsTable !== null) return $this->hasAccountsTable; $this->hasAccountsTable = false;']],
        '/^  FAIL the same instance counts a row on a cash account created after its first read/', false],
    ['M25: T1 run on an empty book', [[$T1, "    \$STEPS = t1_steps_u(\$U, \$acc);\n", "    \$STEPS = [];\n"]],
        '/^  FAIL book U holds a row of class: /', false],
    ['M29: T1 without its own DN_VAULT_FILE (run with a planted vault at the default path)', [[$T1,
        "    \$T1_VAULTS[\$file] = true;\n    putenv('DN_VAULT_FILE=' . \$file);\n", "    \$T1_VAULTS[\$file] = true;\n"]],
        '/^  FAIL the vault at the default path beside the code is unchanged/', true],
];
foreach ($MUTANTS as [$label, $edits, $catcher, $vaultUnset]) {
    [$rel0, $old0, $new0] = $edits[0];
    [$tmp, $n] = sj_weakened_copy($lib, $rel0, $old0, $new0);
    $held = $n === 1;
    foreach (array_slice($edits, 1) as [$rel, $o, $w]) {                   // further edits, each anchor counted the same way
        $src = (string)@file_get_contents($tmp . '/' . $rel);
        if (substr_count($src, $o) !== 1) { $held = false; break; }
        file_put_contents($tmp . '/' . $rel, str_replace($o, $w, $src));
    }
    foreach ($edits as [$rel, $o, $w]) {   // the copy holds each change, and each anchor survives only where its change carries it
        $src = (string)@file_get_contents($tmp . '/' . $rel);
        if (strpos($src, $w) === false || substr_count($src, $o) !== substr_count($w, $o)) $held = false;
    }
    if (!$held) {
        is_(false, "weakened copy \"{$label}\": each anchor occurs once and the copy holds every change", "first anchor found {$n} times");
        exec('rm -rf ' . escapeshellarg($tmp));
        continue;
    }
    $planted = null;
    if ($vaultUnset) {                                                      // the default path of the copy's tree: in $own
        $planted = dirname($tmp) . '/.dishnet-sudan.vault.json';
        file_put_contents($planted, json_encode(['config' => ['tenant_profile' => 'uganda', 'currency_code' => 'UGX']]));
        touch($planted, time() - 3600);
    }
    [$rc, $fails, $tally] = t1m_child($tmp . '/' . T1M_TEST, $tmp, $vaultUnset);
    $named = array_values(array_filter($fails, function ($l) use ($catcher) { return preg_match($catcher, $l) === 1; }));
    is_($rc !== 0 && $fails !== [] && $named !== [], "caught: {$label} — by T1's named check",
        'exit ' . $rc . ', ' . count($fails) . ' failure(s), ' . count($named) . ' at the named check; first: ' . ($fails[0] ?? 'none'));
    t1m_report($fails, $tally);
    if ($planted !== null) @unlink($planted);
    exec('rm -rf ' . escapeshellarg($tmp));
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\n3. The control on the controls: T1 on f4b4c96's own code (5.18.94) fails, at its precondition\n";
[$base, $why] = sj_baseline_tree('f4b4c96');
if ($base === null) {
    $skip++;
    echo "  skip f4b4c96's code — {$why} (a skip, not a pass)\n";
} else {
    [$rc, $fails, $tally] = t1m_child($lib . '/' . T1M_TEST, $base);
    $named = array_values(array_filter($fails, function ($l) { return strpos($l, 'the precondition of sections 1–9') !== false; }));
    is_($rc !== 0 && $named !== [], "control: f4b4c96's code fails T1 at the precondition (it has no cashOnlyRuleInForce())",
        'exit ' . $rc . ', ' . count($fails) . ' failure(s); first: ' . ($fails[0] ?? 'none'));
    t1m_report($fails, $tally);
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\n4. The end check\n";
clearstatcache();
$defAfter = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;
is_($defAfter === $defBefore, 'the default vault beside this checkout is untouched (' . ($defBefore === null ? 'still absent' : 'same bytes and mtime') . ')');
is_(strpos(sys_get_temp_dir(), $own) === 0, 'every temporary file of this run lives in its own folder, removed when it ends', sys_get_temp_dir());

printf("\n%d passed, %d failed, %d skipped\n", $pass, $fail, $skip);
exit($fail === 0 ? 0 : 1);

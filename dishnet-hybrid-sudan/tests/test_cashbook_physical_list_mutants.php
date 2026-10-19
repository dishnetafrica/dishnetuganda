<?php
declare(strict_types=1);
/**
 * test_cashbook_physical_list_mutants.php — 5.18.98 (SPEC-separate-v2 §2.9, "Weakened copies"): every weakened copy of
 * the design's table — W1–W22 with W21b and W21c, W-C and the structural copy — made in a copy of the plugin, must fail
 * the part the table names; the unchanged copy must pass everything first. The review of 5.18.98 adds one copy per fix it
 * asked for — W15b, W15c, W21d, W-K1, W-K2, W-P and W-R — each that fix taken out again. Development and release suites
 * only, never on the server: it copies the plugin and runs child processes.
 *
 * Each copy is made with sj_weakened_copy() and every further edit the same way: the anchor occurs exactly once, it is
 * replaced exactly once, the copy holds the new text, the anchor survives only where the new text carries it, and every
 * edited PHP file lints. A copy whose anchor is missing is a FAILURE of this harness, never a skip.
 *
 * What judges a copy:
 *   - tests/test_cashbook_physical_list.php, this checkout's, run with --root=<the copy> and --base=<5.18.97>. Its
 *     sections are read from its own headings (A, B, C, D, E, E2, F, G, H, I, J, K, L, M); a FAIL line belongs to the
 *     section above it. The table's "E" is the gate, E2 included; "E2" is E2 alone;
 *   - T1 (the copy's own tests/test_cash_in_hand_p3b.php --root=<the copy>), for W6;
 *   - the D1 guard (this checkout's tests/test_d1_bank_balance_readers.php --root=<the copy> --no-mutants), for W12;
 *   - the P3b mutant harness (the copy's own tests/test_cash_in_hand_p3b_mutants.php, which copies its own tree), for
 *     the structural copy: its "each anchor occurs once" checks (test_cash_in_hand_p3b_mutants.php:76, :160).
 * A copy is caught only when EVERY part it names failed; failing somewhere else does not count. Where a part is named
 * with a pattern, a FAIL line of that part must match it.
 *
 * In order:
 *   1. the control: the physical-list test passes on this checkout and on an unchanged copy, with one tally and no
 *      skip; and every other guard a selected copy names passes on the unchanged copy;
 *   2. the weakened copies, one line each: its name, the parts that failed, its verdict;
 *   3. the end check: the default vault beside this checkout is untouched, and every file this run made is in its own
 *      temporary folder.
 *
 *   php test_cashbook_physical_list_mutants.php [--mutants=REGEX] [--base=DIR]
 *
 * --mutants runs only the copies whose label matches (the control still runs first), so the table can be run in parts
 * within a time limit; --base is a 5.18.97 tree already taken from Git (default: ec0accd4, taken once). No Git: the
 * comparisons the copies need cannot run, and that is a failure, never a pass.
 */
$lib = dirname(__DIR__);
$opt = getopt('', ['mutants:', 'base:']);

// This run's own temporary folder, and TMPDIR pointed at it before anything asks PHP for one: every copy, the 5.18.97
// tree and every child's own folder land inside it, and it is removed at the end.
$own = rtrim((string)(getenv('TMPDIR') ?: '/tmp'), '/') . '/pl98m-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($own, 0700, true);
putenv('TMPDIR=' . $own);
putenv('DN_VAULT_FILE=' . $own . '/children.vault.json');   // each child sets its own; this is only what it inherits
register_shutdown_function(function () use ($own) { exec('rm -rf ' . escapeshellarg($own)); });
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';                 // sj_baseline_tree(), sj_weakened_copy()

$defVault  = dirname($lib) . '/.dishnet-sudan.vault.json';                  // ConfigVault::path()'s default for this tree
clearstatcache();
$defBefore = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . substr($d, 0, 900) : '') . "\n"; }
}
function plm_done(): void {
    global $pass, $fail;
    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}

const PLM_TEST = 'tests/test_cashbook_physical_list.php';
const PLM_T1   = 'tests/test_cash_in_hand_p3b.php';
const PLM_D1   = 'tests/test_d1_bank_balance_readers.php';
const PLM_P3BM = 'tests/test_cash_in_hand_p3b_mutants.php';
const PLM_BASE = 'ec0accd4';                                                // the 5.18.97 release commit

/** A child's output: [exit code, its lines]. */
function plm_exec(string $cmd): array {
    $out = [];
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, $out];
}
/** "N passed, M failed[, K skipped]" on the last line, or null. */
function plm_tally(array $out): ?array {
    return preg_match('/^(\d+) passed, (\d+) failed(?:, \d+ skipped)?$/', (string)end($out), $m) ? [(int)$m[1], (int)$m[2]] : null;
}
/** Its FAIL lines, the marker removed. */
function plm_fails(array $out): array {
    $f = [];
    foreach ($out as $l) if (strpos($l, '  FAIL ') === 0) $f[] = substr($l, 7);
    return $f;
}

/**
 * The physical-list test, this checkout's, judging $tree: [exit code, FAIL lines by section, tally, skips, fatal].
 * Sections are its own headings ("A. …", "E2. …"); a FAIL line before the first heading is "setup".
 */
function plm_list(string $lib, string $tree, string $base): array {
    [$rc, $out] = plm_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lib . '/' . PLM_TEST)
        . ' --root=' . escapeshellarg($tree) . ' --base=' . escapeshellarg($base));
    $sec = 'setup'; $by = []; $skips = 0; $fatal = '';
    foreach ($out as $l) {
        if (preg_match('/^([A-M]2?)\. /', $l, $m)) { $sec = $m[1]; continue; }
        if (strpos($l, '  FAIL ') === 0) $by[$sec][] = substr($l, 7);
        if (strpos($l, '  skip ') === 0) $skips++;
        if ($fatal === '' && preg_match('/^PHP (Fatal|Parse) error/', $l)) $fatal = $l;
    }
    return [$rc, $by, plm_tally($out), $skips, $fatal];
}

/** The other guards, each judging $tree: [exit code, its FAIL lines, tally]. */
function plm_guard(string $which, string $lib, string $tree): array {
    if ($which === 'T1') {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tree . '/' . PLM_T1) . ' --root=' . escapeshellarg($tree);
    } elseif ($which === 'D1') {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lib . '/' . PLM_D1) . ' --root=' . escapeshellarg($tree) . ' --no-mutants';
    } else {                                                                // P3b: the copy's own harness copies its own tree
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tree . '/' . PLM_P3BM);
    }
    [$rc, $out] = plm_exec($cmd);
    return [$rc, plm_fails($out), plm_tally($out)];
}
const PLM_GUARD_NAMES = ['T1' => 'T1 (test_cash_in_hand_p3b.php)', 'D1' => 'the D1 guard (test_d1_bank_balance_readers.php)',
                         'P3b' => 'the P3b mutant harness (test_cash_in_hand_p3b_mutants.php)'];

/**
 * A weakened copy: every edit asserted. [copy, '' or why it is not a valid copy]. The first edit is sj_weakened_copy()'s
 * (the anchor counted, replaced only when it occurs once); every further one is counted the same way, and replaced
 * exactly once. Then the copy must hold each new text, each anchor may survive only where its new text carries it, and
 * every edited PHP file must lint.
 */
function plm_copy(string $lib, array $edits): array {
    [$rel0, $old0, $new0] = $edits[0];
    [$tmp, $n] = sj_weakened_copy($lib, $rel0, $old0, $new0);
    if ($n !== 1) return [$tmp, "the anchor of edit 1 ({$rel0}) occurs {$n} time(s), not once"];
    foreach (array_slice($edits, 1) as $i => [$rel, $o, $w]) {
        $src = (string)@file_get_contents($tmp . '/' . $rel);
        $c = substr_count($src, $o);
        if ($c !== 1) return [$tmp, 'the anchor of edit ' . ($i + 2) . " ({$rel}) occurs {$c} time(s), not once"];
        $res = str_replace($o, $w, $src, $made);
        if ($made !== 1) return [$tmp, 'edit ' . ($i + 2) . " ({$rel}) replaced {$made} time(s), not once"];
        file_put_contents($tmp . '/' . $rel, $res);
    }
    $lint = [];
    foreach ($edits as $i => [$rel, $o, $w]) {
        $src = (string)@file_get_contents($tmp . '/' . $rel);
        if ($w !== '' && strpos($src, $w) === false) return [$tmp, 'the copy does not hold the new text of edit ' . ($i + 1) . " ({$rel})"];
        if (substr_count($src, $o) !== substr_count($w, $o)) return [$tmp, 'the anchor of edit ' . ($i + 1) . " ({$rel}) survives where its change does not carry it"];
        if (substr($rel, -4) === '.php') $lint[$rel] = true;
    }
    foreach (array_keys($lint) as $rel) {
        $lo = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp . '/' . $rel) . ' 2>&1', $lo, $lrc);
        if ($lrc !== 0) return [$tmp, "{$rel} does not lint: " . trim(implode(' ', $lo))];
    }
    return [$tmp, ''];
}

// ═══ The weakened copies (SPEC-separate-v2 §2.9's table) ═════════════════════════════════════════════════════════
// [label, [[file, anchor, replacement], ...], what must fail]. What must fail is a list of parts: a section letter of the
// physical-list test ('E' takes E2 too), or [section, pattern] when a FAIL line there must match the pattern, or
// ['T1' | 'D1' | 'P3b', pattern or null] for the other guards.
$CB  = 'lib/CashbookService.php';
$PG  = 'tabs/accounts/cashbook.php';
$NC  = 'tabs/accounts/cashbook_noncash.php';
$VW  = 'lib/CashbookNonCashView.php';
$RT  = 'includes/routes.php';
$NCX = 'includes/cashbook_noncash_csv.php';
$OTH   = "                    \$w[] = 'NOT (account_id IN (SELECT b.id FROM cb_accounts b WHERE ' . self::BANK_BUTTON_IDS . '))';";
$GATE  = "in_array((string)(\$csvUser['role'] ?? (empty(\$csvUser['is_admin']) ? 'sales' : 'admin')), ['sales','sales_staff','field_agent','field_accountant','collection'], true)";
$RET   = '        return $inHand ? "({$loc})" : "NOT ({$loc})";';
$KINDS = "            \$cashKinds = implode(', ', array_map([\$this->pdo(), 'quote'], self::CASH_IN_HAND_KINDS));";
$INQ   = '            $loc .= " OR account_id IN (SELECT a.id FROM cb_accounts a WHERE a.id IS NOT NULL AND a.kind IN ({$cashKinds}))";';
$BK    = "        \$bk = \$f['book'] ?? '';";
$LINK  = "      if (BankGate::showLink(\$dataDir ?? null, \$store ?? null, is_array(\$retailer ?? null) ? \$retailer : [])): ?>";
$RULE2 = '    $_csvRule2 = !$_hasSSP2 && $cb2csv->cashOnlyRuleInForce();   // 5.18.98';
$MUTANTS = [
    ["W1: status='approved' added to the cash side's predicate (the physical-cash list keeps approved rows only)",
        [[$CB, $RET, '        return $inHand ? "(status=\'approved\' AND ({$loc}))" : "NOT ({$loc})";']], ['B', 'A']],
    ['W2: MONEY_KINDS in place of CASH_IN_HAND_KINDS (bank accounts read as cash)',
        [[$CB, $KINDS, "            \$cashKinds = implode(', ', array_map([\$this->pdo(), 'quote'], self::MONEY_KINDS));"]], ['B', 'A']],
    ['W3: book ignored by buildWhere()',
        [[$CB, $BK, "        \$bk = '';"]], ['A', 'J']],
    ['W4: the service gate removed (book honoured wherever rule A is off)',
        [[$CB, "        if ((\$bk === 'cash' || \$bk === 'noncash') && \$this->cashOnlyRuleInForce()) {", "        if (\$bk === 'cash' || \$bk === 'noncash') {"]], ['E']],
    ['W5: $_cbBook not gated on $_cbRuleA',
        [[$PG, "\$_cbBook = \$_cbRuleA ? ((\$view === 'noncash' && !\$isFieldAgent) ? 'noncash' : 'cash') : '';",
               "\$_cbBook = ((\$view === 'noncash' && !\$isFieldAgent) ? 'noncash' : 'cash');"]], ['L', 'E']],
    ['W5: the tab not gated on $_cbRuleA',
        [[$PG, "if (\$_cbRuleA) array_splice(\$navItems, 1, 0, [['noncash', \"\\u{1F3E6} Bank & other accounts\", '']]);",
               "array_splice(\$navItems, 1, 0, [['noncash', \"\\u{1F3E6} Bank & other accounts\", '']]);"]], ['L', 'E']],
    ['W6: the cash clause applied with no key (every caller passing no book gets the cash list)',
        [[$CB, $BK, "        \$bk = \$f['book'] ?? 'cash';"]], ['I', ['T1', "/the row list is buildWhere\\(\\)'s/"]]],
    ['W7: not cash written as account_id > 0 AND account_id NOT IN (the cash ids)',
        [[$CB, $RET, '        return $inHand ? "({$loc})" : "(account_id > 0 AND account_id NOT IN (SELECT a.id FROM cb_accounts a WHERE a.id IS NOT NULL AND a.kind IN (\'cash\', \'momo\')))";']],
        ['B', 'A']],
    ['W8: no a.id IS NOT NULL in the cash accounts\' subquery',
        [[$CB, $INQ, '            $loc .= " OR account_id IN (SELECT a.id FROM cb_accounts a WHERE a.kind IN ({$cashKinds}))";']], ['G']],
    ['W9: ensureAccountsTable() on the list\'s read path (listLocationWhere)',
        [[$CB, "        \$loc = 'COALESCE(account_id, 0) = 0';\n", "        \$loc = 'COALESCE(account_id, 0) = 0';\n        \$this->ensureAccountsTable();\n"]], ['F']],
    ['W9: accounts() on the tab\'s read path (nonCashAccounts)',
        [[$CB, "        if (!\$this->accountsTableExists()) return [];\n        require_once __DIR__ . '/currency.php';\n",
               "        \$this->accounts();\n        if (!\$this->accountsTableExists()) return [];\n        require_once __DIR__ . '/currency.php';\n"]], ['F']],
    ['W10: countFiltered() without the key',
        [[$CB, "    public function countFiltered(array \$f = []): int\n    {\n        list(\$where, \$params) = \$this->buildWhere(\$f);\n",
               "    public function countFiltered(array \$f = []): int\n    {\n        list(\$where, \$params) = \$this->buildWhere(array_diff_key(\$f, ['book' => 1, 'book_account' => 1]));\n"]], ['D', 'J']],
    ['W11: the cash export without book',
        [[$RT, "    if (\$_csvRule2) \$csvF2['book'] = 'cash';   // 5.18.98: the Cashbook list's rows, physical cash only\n", '']], ['K']],
    ['W12: a Balance column in the new tab',
        [[$NC, '<th>Ref</th><th>State</th><th>Src</th>', '<th>Ref</th><th>State</th><th>Src</th><th>Balance</th>'],
         [$NC, "    <td><?php echo \$_cbNcSrc((string)(\$e['source'] ?? 'manual')); ?></td>\n",
               "    <td><?php echo \$_cbNcSrc((string)(\$e['source'] ?? 'manual')); ?></td>\n    <td><?php echo \$_cbNcEsc(\$e['running_balance'] ?? ''); ?></td>\n"]], ['J']],
    ['W12: accountBalance() read in the new tab, its figure shown',
        [[$NC, "\$_cbNcLabels = \$cb->accountLabels();\n", "\$_cbNcLabels = \$cb->accountLabels();\n\$_cbNcBal = is_int(\$_cbNcSel) ? \$cb->accountBalance(\$_cbNcSel) : null;\n"],
         [$NC, "  <div style=\"font-weight:800;margin-bottom:4px;\">Not physical cash.</div>\n",
               "  <div style=\"font-weight:800;margin-bottom:4px;\">Not physical cash.</div>\n<?php if (\$_cbNcBal !== null): ?>  <div>Book balance: UGX <?php echo number_format(\$_cbNcBal, 2); ?></div>\n<?php endif; ?>\n"]],
        ['J', ['D1', null]]],
    ['W13: a row checkbox kept in the new tab',
        [[$NC, "    <td style=\"font-family:monospace;font-size:10px;white-space:nowrap;\"><?php echo \$_cbNcEsc(\$e['date'] ?? ''); ?></td>\n",
               "    <td style=\"font-family:monospace;font-size:10px;white-space:nowrap;\"><input type=\"checkbox\" class=\"cb-row-chk\" value=\"<?php echo (int)\$e['id']; ?>\"> <?php echo \$_cbNcEsc(\$e['date'] ?? ''); ?></td>\n"]], ['J']],
    ['W13: the row menu (⋮, cbCrud) kept in the new tab',
        [[$NC, "    <td><?php echo \$_cbNcSrc((string)(\$e['source'] ?? 'manual')); ?></td>\n",
               "    <td><?php echo \$_cbNcSrc((string)(\$e['source'] ?? 'manual')); ?> <button type=\"button\" onclick=\"cbCrud(<?php echo (int)\$e['id']; ?>)\">⋮</button></td>\n"]], ['J']],
    ['W13: the bulk form kept in the new tab',
        [[$NC, "<!-- ══ DESKTOP: Table layout (read-only) ══ -->\n", "<!-- ══ DESKTOP: Table layout (read-only) ══ -->\n<form id=\"cbBulkForm\" method=\"post\"></form>\n"]], ['J']],
    ['W13b: the phone cards without Ref and Src (the completeness review, COMPL-1)',
        [[$NC, "      <?php if ((string)(\$e['validation_ref'] ?? '') !== ''): ?><span class=\"cb3-card-sr\"><?php echo \$_cbNcEsc(mb_strimwidth((string)(\$e['validation_ref'] ?? ''), 0, 20, '…')); ?></span><?php endif; ?>\n      <?php echo \$_cbNcSrc((string)(\$e['source'] ?? 'manual')); ?>\n", ""]], [['J', '/phone card carries/']]],
    ['W14: an (int) cast in label() (a text id read as a number)',
        [[$VW, "        \$raw = is_scalar(\$accountId) ? (string)\$accountId : '';", "        \$raw = is_scalar(\$accountId) ? (string)(int)\$accountId : '';"]],
        [['B', '/account not found|reads its own name/'], ['J', '/account not found|never as account 7|Account/']]],
    ['W14: an (int) cast in selected() (cb_acct read as a number)',
        [[$VW, "        if (\$want === 'other' || (\$want !== '' && ctype_digit(\$want))) {\n            foreach (\$chips as \$c) {\n                if ((string)\$c['key'] === \$want) return \$c['key'];",
               "        if (\$want === 'other' || (int)\$want > 0) {\n            foreach (\$chips as \$c) {\n                if ((string)\$c['key'] === \$want || \$c['key'] === (int)\$want) return \$c['key'];"]],
        [['B', '/never a cast/'], ['J', '/names no button/']]],
    ['W15: the Other accounts button without its NOT',
        [[$CB, $OTH, "                    \$w[] = '(account_id IN (SELECT b.id FROM cb_accounts b WHERE ' . self::BANK_BUTTON_IDS . '))';"]], [['A', "/the buttons' rows/"]]],
    // 5.18.98's review: SQL-1 / spec-conformance-1, SQL-2, TS-1, TS-3, SQL-3, SEC-2 — each fix taken out again, one at a time
    ['W15b: the Other accounts button leaving out every bank id, as before the review (a bank id no button can select is under no button)',
        [[$CB, $OTH, "                    \$w[] = \"NOT (account_id IN (SELECT b.id FROM cb_accounts b WHERE b.id IS NOT NULL AND b.kind = 'bank'))\";"]],
        [['G', '/whole-number id above 0|each button holds exactly/']]],
    ['W15c: nonCashAccounts() keeping a second record with the same id (two buttons for one account)',
        [[$CB, "            if (\$id <= 0 || isset(\$out[\$id])) continue;\n            \$out[\$id] = [", "            if (\$id <= 0) continue;\n            \$out[] = ["]],
        [['G', '/nonCashAccounts\\(\\)|whole-number id above 0/']]],
    ['W16: NOT dropped from listLocationWhere(false)',
        [[$CB, $RET, '        return $inHand ? "({$loc})" : "({$loc})";']], ['A']],
    ['W17: Entry state appended on a rule-off book (gated on the book\'s currencies alone)',
        [[$RT, "    if (\$_csvRule2) require_once dirname(__DIR__) . '/lib/CashbookNonCashView.php';", "    if (!\$_hasSSP2) require_once dirname(__DIR__) . '/lib/CashbookNonCashView.php';"],
         [$RT, "'Balance Currency','Ref','Status','Source'], \$_csvRule2 ? ['Entry state'] : []", "'Balance Currency','Ref','Status','Source'], !\$_hasSSP2 ? ['Entry state'] : []"],
         [$RT, "\$_csvCurr2.' Balance','Ref','Status','Source'], \$_csvRule2 ? ['Entry state'] : []", "\$_csvCurr2.' Balance','Ref','Status','Source'], !\$_hasSSP2 ? ['Entry state'] : []"],
         [$RT, "(\$bl2===''?'':\$blCur2),\n                \$e2['validation_ref'],\$e2['validation_status'],\$e2['source']??'manual'], \$_csvRule2 ? [",
               "(\$bl2===''?'':\$blCur2),\n                \$e2['validation_ref'],\$e2['validation_status'],\$e2['source']??'manual'], !\$_hasSSP2 ? ["],
         [$RT, "\$isIn2?'':\$uA2, \$bl2,\n                \$e2['validation_ref'],\$e2['validation_status'],\$e2['source']??'manual'], \$_csvRule2 ? [",
               "\$isIn2?'':\$uA2, \$bl2,\n                \$e2['validation_ref'],\$e2['validation_status'],\$e2['source']??'manual'], !\$_hasSSP2 ? ["]],
        ['L', 'K']],
    ['W18: the search hint counting the no-key list',
        [[$PG, "                                       'date_to' => \$dateTo, 'book' => 'noncash']))", "                                       'date_to' => \$dateTo]))"]],
        [['J', '/the hint|no hint/']]],
    ['W19: the export gated on the service rule alone',
        [[$RT, $RULE2, '    $_csvRule2 = $cb2csv->cashOnlyRuleInForce();   // 5.18.98']], ['E2']],
    ['W20: the hero changed to countFiltered()',
        [[$PG, '    $_cbLiveCount = $cb->countEntries($proj);', '    $_cbLiveCount = $cb->countFiltered($filters);']], [['J', '/the hero/']]],
    ['W21: the tab\'s ECO Bank link gated by true, not BankGate::showLink()',
        [[$NC, $LINK, '      if (true): ?>']], [['J', '/an accountant, not an administrator/']]],
    ['W21b: the tab\'s link gated by cashbook.php:7\'s $isAdmin && BankGate::open() && BankGate::allows() !== null',
        [[$NC, $LINK, "      if (\$isAdmin && BankGate::open(\$dataDir ?? null) && BankGate::allows('bank.view', \$store ?? null, is_array(\$retailer ?? null) ? \$retailer : []) !== null): ?>"]],
        [['J', '/made admin inside the 300-second session window/']]],
    ['W21c: the link carrying bk_acct for every bank button',
        [[$NC, "if (\$_cbNcChip !== null && is_int(\$_cbNcChip['key']) && \$_cbNcChip['active']\n    && in_array(strtoupper(trim((string)\$_cbNcChip['currency'])), \$_cbCurrs, true)) {",
               "if (\$_cbNcChip !== null && is_int(\$_cbNcChip['key'])) {"]], [['J', '/ECO Bank not offering this one/']]],
    ['W21d: the tab\'s link gated by the session\'s is_admin and BankGate::allows() alone — BankGate::open() dropped (review TS-1)',
        [[$NC, $LINK, "      if (!empty(\$retailer['is_admin']) && BankGate::allows('bank.view', \$store ?? null, is_array(\$retailer ?? null) ? \$retailer : []) !== null): ?>"]],
        [['J', '/the ECO Bank switch off/']]],
    ['W-K1: the Ref cell of the tab\'s export not neutralised (review TS-3)',
        [[$NCX, "        \$_ncxText(\$_ncxE['validation_ref'] ?? ''),\n", "        \$_ncxE['validation_ref'] ?? '',\n"]], [['K', '/formula-like/']]],
    ['W-K2: the entry\'s validation status written into the Category cell of the tab\'s export (review TS-3)',
        [[$NCX, "        \$_ncxText(\$_ncxE['category'] ?? ''),\n", "        \$_ncxText(\$_ncxE['validation_status'] ?? ''),\n"]], [['K', '/its 14 columns/']]],
    ['W-P: the tab\'s export reading routes.php\'s project, NULL with no cb_proj (review SQL-3)',
        [[$NCX, "\$_ncxChips  = CashbookNonCashView::chips(\$cb2csv, \$_ncxProj,", "\$_ncxChips  = CashbookNonCashView::chips(\$cb2csv, \$proj2,"]],
        [['K', '/no cb_proj/']]],
    ['W-R: the tab\'s export reading a record with no role as no field agent (\'\', not the page\'s \'sales\'; review SEC-2)',
        [[$RT, $GATE, "in_array(\$csvUser['role'] ?? '', ['sales','sales_staff','field_agent','field_accountant','collection'], true)"]],
        [['K', '/no role/']]],
    ['W22: the button\'s parameter put before the search\'s four (a misaligned $p)',
        [[$CB, "                    \$w[] = 'account_id = ?'; \$p[] = \$ba;",
               "                    \$w[] = 'account_id = ?'; array_splice(\$p, count(\$p) - (!empty(\$f['search']) ? 4 : 0), 0, [\$ba]);"]], ['A', 'D']],
    ['W-C: the list\'s predicate applied to the Balance stream of the cash list (no status term there, so a Balance changes)',
        [[$CB, '        $cashWhere  = $this->cashOnlyWhere();',
               "        \$cashWhere  = ((\$f['book'] ?? '') === 'cash' && \$cashRule) ? ' AND ' . \$this->listLocationWhere(true) : \$this->cashOnlyWhere();"]], ['C']],
    ['Structural: the list predicate copied verbatim from cashOnlyWhere()',
        [[$CB, "        \$loc = 'COALESCE(account_id, 0) = 0';\n        if (\$this->accountsTableExists()) {\n" . $KINDS . "\n" . $INQ . "\n",
               "        \$loc = 'COALESCE(account_id,0)=0';\n        if (\$this->accountsTableExists()) {\n"
               . "            \$kinds = implode(',', array_map([\$this->pdo(), 'quote'], self::CASH_IN_HAND_KINDS));\n"
               . "            \$loc .= \" OR account_id IN (SELECT id FROM cb_accounts WHERE kind IN ({\$kinds}))\";\n"]],
        [['P3b', '/its anchor occurs once|each anchor occurs once/']]],
];
$TABLE = count($MUTANTS);
if (isset($opt['mutants'])) {                                               // a part of the table only: the control still runs
    $only = '#' . str_replace('#', '\\#', (string)$opt['mutants']) . '#';
    $MUTANTS = array_values(array_filter($MUTANTS, function (array $m) use ($only) { return preg_match($only, $m[0]) === 1; }));
    echo '(--mutants: ' . count($MUTANTS) . " of the table's {$TABLE} weakened copies)\n";
    if ($MUTANTS === []) { is_(false, 'the --mutants pattern names at least one weakened copy'); plm_done(); }
}
$guardsNeeded = [];
foreach ($MUTANTS as $m) foreach ($m[2] as $x) if (is_array($x) && isset(PLM_GUARD_NAMES[$x[0]])) $guardsNeeded[$x[0]] = true;

// ═══════════════════════════════════════════════════════════════════════════
echo "1. The control: the physical-list test passes on this checkout and on an unchanged copy; the other guards pass on it\n";
$baseTree = null; $whyBase = '';
if (isset($opt['base'])) {
    $baseTree = rtrim((string)$opt['base'], '/');
    if (!is_file($baseTree . '/manifest.json')) { $baseTree = null; $whyBase = 'the given --base holds no plugin'; }
} else {
    [$baseTree, $whyBase] = sj_baseline_tree(PLM_BASE);
}
$bm = $baseTree !== null ? json_decode((string)@file_get_contents($baseTree . '/manifest.json'), true) : null;
is_($baseTree !== null && (string)($bm['information']['version'] ?? '') === '5.18.97',
    'the 5.18.97 tree the comparisons need is at hand (' . PLM_BASE . ', from Git, taken once)', $whyBase);
if ($baseTree === null) plm_done();

[$rc0, $by0, $tally0, $skip0, $fatal0] = plm_list($lib, $lib, $baseTree);
is_($rc0 === 0 && $by0 === [] && $tally0 !== null && $tally0[1] === 0 && $tally0[0] > 400 && $skip0 === 0,
    'the physical-list test passes on this checkout: ' . ($tally0 ? "{$tally0[0]} passed, 0 failed" : 'no tally') . ', no skip',
    'exit ' . $rc0 . ', skips ' . $skip0 . ' ' . json_encode($by0, JSON_UNESCAPED_UNICODE) . ' ' . $fatal0);
[$ctl, $why] = plm_copy($lib, [[$CB, "    private function listLocationWhere(bool \$inHand): string\n", "    private function listLocationWhere(bool \$inHand): string\n"]]);
if ($why !== '') {
    is_(false, 'the unchanged copy: its anchor occurs once and the copy lints', $why);
    exec('rm -rf ' . escapeshellarg($ctl));
    plm_done();
}
[$rc, $by, $tally, $skipN, $fatal] = plm_list($lib, $ctl, $baseTree);
is_($rc === 0 && $by === [] && $tally !== null && $tally === $tally0 && $skipN === 0,
    'the unchanged copy passes every section with the same tally (' . ($tally ? "{$tally[0]} passed, 0 failed" : 'no tally') . '), no skip',
    'exit ' . $rc . ' tally ' . json_encode($tally) . ', skips ' . $skipN . ' ' . json_encode($by, JSON_UNESCAPED_UNICODE) . ' ' . $fatal);
foreach (array_keys($guardsNeeded) as $g) {
    [$grc, $gf, $gt] = plm_guard($g, $lib, $ctl);
    is_($grc === 0 && $gf === [] && $gt !== null && $gt[1] === 0 && $gt[0] > 0,
        PLM_GUARD_NAMES[$g] . ' passes on the unchanged copy: ' . ($gt ? "{$gt[0]} passed, 0 failed" : 'no tally'),
        'exit ' . $grc . '; first: ' . ($gf[0] ?? 'none'));
}
exec('rm -rf ' . escapeshellarg($ctl));
if ($fail > 0) { echo "\n  The control failed: no weakened copy is judged against a harness that does not pass unchanged.\n"; plm_done(); }

// ═══════════════════════════════════════════════════════════════════════════
echo "\n2. The weakened copies: each must fail every part the table names for it\n";
$caught = 0;
foreach ($MUTANTS as [$label, $edits, $expect]) {
    $id = explode(':', $label)[0];
    [$tmp, $why] = plm_copy($lib, $edits);
    if ($why !== '') {
        is_(false, "{$id}: the copy is made — each anchor occurs once, is replaced once, the copy holds the change and lints", $why);
        exec('rm -rf ' . escapeshellarg($tmp));
        continue;
    }
    [$rc, $by, $tally, , $fatal] = plm_list($lib, $tmp, $baseTree);
    $guards = [];
    foreach ($expect as $x) if (is_array($x) && isset(PLM_GUARD_NAMES[$x[0]]) && !isset($guards[$x[0]])) $guards[$x[0]] = plm_guard($x[0], $lib, $tmp);
    $missed = [];
    foreach ($expect as $x) {
        [$part, $pat] = is_array($x) ? [$x[0], $x[1]] : [$x, null];
        if (isset(PLM_GUARD_NAMES[$part])) {
            [$grc, $gf] = $guards[$part];
            $hit = $grc !== 0 && $gf !== [] && ($pat === null || preg_grep($pat, $gf) !== []);
        } else {
            $secs = $part === 'E' ? ['E', 'E2'] : [$part];
            $lines = [];
            foreach ($secs as $s) $lines = array_merge($lines, $by[$s] ?? []);
            $hit = $rc !== 0 && $lines !== [] && ($pat === null || preg_grep($pat, $lines) !== []);
        }
        if (!$hit) $missed[] = $part . ($pat !== null ? " {$pat}" : '');
    }
    $failedParts = array_keys($by);
    foreach ($guards as $g => [$grc, $gf]) if ($grc !== 0 || $gf !== []) $failedParts[] = $g;
    $named = implode(', ', array_map(function ($x) { return is_array($x) ? $x[0] : $x; }, $expect));
    $ok = $missed === [];
    if ($ok) $caught++;
    is_($ok, sprintf('%-11s failed: %-28s %s', $id, $failedParts === [] ? 'nothing' : implode(', ', $failedParts),
        $ok ? "CAUGHT at {$named}" : 'SURVIVED: not failed at ' . implode('; ', $missed)) . ' — ' . substr($label, strlen($id) + 2),
        'exit ' . $rc . ($fatal !== '' ? '; ' . $fatal : ''));
    $nF = 0; foreach ($by as $l) $nF += count($l);
    $first = '';
    foreach ($expect as $x) {
        $part = is_array($x) ? $x[0] : $x;
        if (isset(PLM_GUARD_NAMES[$part])) { $first = $part . ': ' . ($guards[$part][1][0] ?? 'none'); break; }
        $s = $part === 'E' && empty($by['E']) ? 'E2' : $part;
        if (!empty($by[$s])) { $first = $s . ': ' . $by[$s][0]; break; }
    }
    echo '         ' . $nF . ' assertion(s) failed' . ($tally ? " ({$tally[0]} passed)" : '') . ($first !== '' ? '; at the named part, first: ' . substr($first, 0, 150) : '') . "\n";
    exec('rm -rf ' . escapeshellarg($tmp));
}
echo "\n  {$caught} of " . count($MUTANTS) . " weakened copies caught where the table says\n";

// ═══════════════════════════════════════════════════════════════════════════
echo "\n3. The end check\n";
clearstatcache();
$defAfter = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;
is_($defAfter === $defBefore, 'the default vault beside this checkout is untouched (' . ($defBefore === null ? 'still absent' : 'same bytes and mtime') . ')');
is_(strpos(sys_get_temp_dir(), $own) === 0, 'every temporary file of this run lives in its own folder, removed when it ends', sys_get_temp_dir());
plm_done();

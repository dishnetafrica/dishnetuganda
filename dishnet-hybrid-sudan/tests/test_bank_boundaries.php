<?php
declare(strict_types=1);
/**
 * test_bank_boundaries.php — T-B4 of the Bank screen v1 (5.18.97; SPEC-bank §f.2): what the module may and may not touch,
 * read from the code (and, for the backups and the export's cells, run).
 *
 * The module is lib/BankGate.php, lib/BankStatementStore.php, lib/EcobankDetailsCsv.php, tabs/accounts/bank.php,
 * includes/post/post_bank.php and the export hunk of includes/routes.php. The base is ec0accd4, the 5.18.97 release
 * commit (5.18.98 moved it there, on purpose: SPEC-separate-v2 §2.9), read from Git; when Git cannot supply it the
 * comparisons are skips, never passes.
 *
 *   A  (rewritten for 5.18.98) every file 5.18.98 leaves alone is byte-identical to ec0accd4: the module's shared files
 *      (the sidebar and the CEO page with their ECO Bank links, public.php, tools/set_config.php until 5.18.99,
 *      includes/post_handlers.php), the phone's More menu and the Cashbook's POST handler among them; includes/routes.php
 *      is ec0accd4's with 5.18.98's declared changed lines (the Uganda export's account names, and its two non-SSP
 *      layouts with Entry state last) and insertions only, each block naming 5.18.98 (and the export hunk sits after the
 *      Cashbook export); manifest.json differs by one line, its version, 5.18.97 → 5.18.98. The two menu-link blocks F
 *      reads are measured against e35eccee (5.18.96), the base of the release that added them
 *   B  nothing outside the module (and the $forbidden list, and migration 088) names the five bank_* tables
 *   C  the module writes no book and never reads cb_ledger: no Cashbook writer, no FinAudit, no BankImport, no
 *      BankStatement::, no "new CashbookService", no INSERT/UPDATE/DELETE on a cb_ table; its only SQL on an existing
 *      table is one SELECT … FROM cb_accounts; v1 never UPDATEs bank_statement_lines (a line's status stays free)
 *   D  the tab: no innerHTML (or outerHTML, insertAdjacentHTML, document.write), no json_encode in an onclick, no
 *      dn_cur(; every echo of a statement or account field passes h() or an (int) cast, the cards' (5.18.97) among
 *      them; the per-account reads behind the cards are never echoed
 *   E  the module: no strtotime(, no (float), every fgetcsv/fputcsv passes the escape '', no error_log(, no 'uploads/',
 *      no $_FILES[…]['name'] read; the Photo Manager's list does not name bank_statements
 *   F  the gate: each entry point (the tab, the POST handler, the export) calls BankGate::open( and then
 *      BankGate::allows( before it builds the store or draws anything; the POST handler acts on csrfCheck() AND
 *      crossSite() AND fromOwnPage('POST'); the export calls fromOwnPage('GET') before the store, the event and any
 *      line; the POST handler and the export sign the visitor in before they ask the gate; BankGate reads
 *      PluginConfig::read(, never a store copy. The menu links (5.18.97): inside the block A finds added to each menu
 *      file, BankGate::showLink( once, with the one call text, as the if right before the one tab=bank link, and no
 *      other gate of its own (no open(, allows(, switch, $config[ or $isAdmin); showLink()'s body is exactly the design's
 *   G  the backups: the statement folder is outside uploads/, so the Google Drive DATA zip (run, on a folder holding a
 *      kept and a held statement) carries neither, while it carries an upload (the control); the admin backup download
 *      and main.php's auto-backup name no bank folder
 *   H  the export's formula neutralising and amounts, run from the hunk's own code
 *   I  weakened copies: each fails this test (5.18.97's at each case it names); the unchanged copy passes
 *                                                                                          (--no-mutants skips)
 *
 *   php test_bank_boundaries.php [--root=DIR] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-mutants']);
$lib  = dirname(__DIR__);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : $lib;
$withMutants = !isset($opt['no-mutants']);
const BK_BASE = 'ec0accd4';         // 5.18.98: the 5.18.97 release commit (SPEC-separate-v2 §2.9)
const BK_LINKS_BASE = 'e35eccee';   // 5.18.96: what the 5.18.97 menu-link blocks are measured against (A, F)
const BK_TABLES = ['bank_account_profiles', 'bank_checks', 'bank_statements', 'bank_statement_lines', 'bank_line_events'];
const BK_MODULE = ['lib/BankGate.php', 'lib/BankStatementStore.php', 'lib/EcobankDetailsCsv.php', 'tabs/accounts/bank.php', 'includes/post/post_bank.php'];
// 5.18.98 (SPEC-separate-v2 §2.6): the lines of includes/routes.php it CHANGES rather than adds — the account names read
// with a SELECT only under rule A, and the two non-SSP layouts' header and row with Entry state appended — each as
// [5.18.97's text, 5.18.98's text]. A rewrites the base with them before it asks that everything else be insertions.
const BK_R98_CHANGED = [
    [<<<'R98'
            foreach ($cb2csv->accounts() as $_a2) { $_acctNames2[(int)$_a2['id']] = $_a2['name']; }
R98
    . "\n", <<<'R98'
            if ($_csvRule2) {   // 5.18.98: a Uganda export reads the names with a SELECT only, so it runs no DDL
                foreach ($cb2csv->accountLabels() as $_a2id => $_a2l) { $_acctNames2[(int)$_a2id] = $_a2l['name']; }
            } else {
                foreach ($cb2csv->accounts() as $_a2) { $_acctNames2[(int)$_a2['id']] = $_a2['name']; }
            }
R98
    . "\n"],
    [<<<'R98'
        fputcsv($out2, ['SR No.','Date','Account','Type','Particulars','Category','Person','Currency','Received','Payment','Balance','Balance Currency','Ref','Status','Source']);
R98
    . "\n", <<<'R98'
        fputcsv($out2, array_merge(['SR No.','Date','Account','Type','Particulars','Category','Person','Currency','Received','Payment','Balance','Balance Currency','Ref','Status','Source'], $_csvRule2 ? ['Entry state'] : []));   // 5.18.98: Entry state last
R98
    . "\n"],
    [<<<'R98'
        fputcsv($out2, ['SR No.','Date','Account','Type','Particulars','Category','Person','Received '.$_csvCurr2,'Payment '.$_csvCurr2,$_csvCurr2.' Balance','Ref','Status','Source']);
R98
    . "\n", <<<'R98'
        fputcsv($out2, array_merge(['SR No.','Date','Account','Type','Particulars','Category','Person','Received '.$_csvCurr2,'Payment '.$_csvCurr2,$_csvCurr2.' Balance','Ref','Status','Source'], $_csvRule2 ? ['Entry state'] : []));   // 5.18.98: Entry state last
R98
    . "\n"],
    [<<<'R98'
        } elseif ($_isAll2) {
            fputcsv($out2, [$e2['sr'],$e2['date'],
                $_acctNames2[(int)($e2['account_id'] ?? 0)] ?? '',
                $e2['txn_type'] ?? '',
                $e2['description'],$e2['category'],$e2['person'],
                $rowCur2, $isIn2?$uA2:'', $isIn2?'':$uA2, $bl2, ($bl2===''?'':$blCur2),
                $e2['validation_ref'],$e2['validation_status'],$e2['source']??'manual']);
R98
    . "\n", <<<'R98'
        } elseif ($_isAll2) {
            fputcsv($out2, array_merge([$e2['sr'],$e2['date'],
                $_acctNames2[(int)($e2['account_id'] ?? 0)] ?? '',
                $e2['txn_type'] ?? '',
                $e2['description'],$e2['category'],$e2['person'],
                $rowCur2, $isIn2?$uA2:'', $isIn2?'':$uA2, $bl2, ($bl2===''?'':$blCur2),
                $e2['validation_ref'],$e2['validation_status'],$e2['source']??'manual'], $_csvRule2 ? [CashbookNonCashView::state((string)($e2['status'] ?? ''))] : []));   // 5.18.98
R98
    . "\n"],
    [<<<'R98'
        } else {
            fputcsv($out2, [$e2['sr'],$e2['date'],
                $_acctNames2[(int)($e2['account_id'] ?? 0)] ?? '',
                $e2['txn_type'] ?? '',
                $e2['description'],$e2['category'],$e2['person'],
                $isIn2?$uA2:'', $isIn2?'':$uA2, $bl2,
                $e2['validation_ref'],$e2['validation_status'],$e2['source']??'manual']);
R98
    . "\n", <<<'R98'
        } else {
            fputcsv($out2, array_merge([$e2['sr'],$e2['date'],
                $_acctNames2[(int)($e2['account_id'] ?? 0)] ?? '',
                $e2['txn_type'] ?? '',
                $e2['description'],$e2['category'],$e2['person'],
                $isIn2?$uA2:'', $isIn2?'':$uA2, $bl2,
                $e2['validation_ref'],$e2['validation_status'],$e2['source']??'manual'], $_csvRule2 ? [CashbookNonCashView::state((string)($e2['status'] ?? ''))] : []));   // 5.18.98
R98
    . "\n"],
];

$pass = 0; $fail = 0; $skip = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . substr($d, 0, 700) : '') . "\n"; }
}
function t(string $m, $got, $want): void {
    is_($got === $want, $m, 'got  ' . substr((string)json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 400)
        . "\n       want " . substr((string)json_encode($want, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 400));
}
function src(string $rel): string { global $root; return (string)@file_get_contents($root . '/' . $rel); }
/** A file as BK_BASE (or $rev) holds it, from this checkout's Git; null when Git cannot supply it. */
function base_of(string $rel, string $rev = BK_BASE): ?string
{
    global $lib;
    $o = (string)shell_exec('git -C ' . escapeshellarg($lib) . ' show ' . escapeshellarg($rev . ':dishnet-hybrid-sudan/' . $rel)
        . ' 2>/dev/null; printf "\\n__RC=%s" "$?"');
    if (!preg_match('/\n__RC=(\d+)$/', $o, $m) || $m[1] !== '0') return null;
    return substr($o, 0, -strlen($m[0]));
}
/** Every line of $base kept in $cur, in order, and the lines $cur adds: [all kept, how many added, the added blocks]. */
function bk_insertions(array $base, array $cur): array
{
    $j = 0; $n = 0; $block = []; $blocks = [];
    foreach ($cur as $line) {
        if ($j < count($base) && $line === $base[$j]) { $j++; if ($block) { $blocks[] = $block; $block = []; } continue; }
        $n++; $block[] = $line;
    }
    if ($block) $blocks[] = $block;
    return [$j === count($base), $n, $blocks];
}
/** The export hunk of includes/routes.php, from its first comment line to the closing brace before the next export. */
function routes_hunk(): string
{
    $r = src('includes/routes.php');
    $a = strpos($r, "// ── The Bank screen's export (5.18.97");
    $b = strpos($r, "// ── Collections CSV export — must run before HTML buffer fills");
    return ($a === false || $b === false || $b < $a) ? '' : substr($r, $a, $b - $a);
}
/** Tokens of PHP code without its comments. A fragment (the hunk) is opened with "<?php ". */
function code_tokens(string $php): array
{
    $out = [];
    foreach (token_get_all($php) as $tk) {
        if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) continue;
        $out[] = $tk;
    }
    return $out;
}
/** The module's code: each file's tokens, plus the routes hunk's. */
function module_sources(): array
{
    $o = [];
    foreach (BK_MODULE as $rel) $o[$rel] = src($rel);
    $o['includes/routes.php (the export hunk)'] = "<?php\n" . routes_hunk();
    return $o;
}
/** The code with its comments taken out, whitespace kept. */
function code_only(string $php): string
{
    $o = '';
    foreach (token_get_all($php) as $tk) {
        if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $o .= is_array($tk) ? $tk[1] : $tk;
    }
    return $o;
}
function strings_of(array $toks): array
{
    $o = [];
    foreach ($toks as $tk) if (is_array($tk) && in_array($tk[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) $o[] = $tk[1];
    return $o;
}
function idents_of(array $toks): array
{
    $o = [];
    foreach ($toks as $tk) if (is_array($tk) && $tk[0] === T_STRING) $o[] = $tk[1];
    return $o;
}

// ═══════════════════════════════════════════════════════════════════════════
echo "A. (5.18.98) The untouched files equal " . BK_BASE . " (5.18.97); includes/routes.php differs only by 5.18.98's declared lines\n";
// 5.18.98 (SPEC-separate-v2 §2.9, rewritten on purpose). The base is the 5.18.97 release commit. 5.18.98 edits the Cashbook
// list (tabs/accounts/cashbook.php, lib/CashbookService.php), so those two leave this list and are held by
// tests/test_cashbook_physical_list.php M instead; the module's shared files, which 5.18.98 leaves alone, join it, and
// includes/post/post_cashbook.php stays. includes/routes.php is the one shared file 5.18.98 edits.
$UNTOUCHED = ['includes/styles.php', 'lib/BankStatement.php',
    'lib/BankImport.php', 'tools/bank_statement.php', 'tests/test_bank_statement.php', 'lib/GoogleDriveBackup.php', 'main.php', 'includes/post/post_sync.php',
    'lib/SqliteStore.php', 'lib/MigrationRunner.php', 'tabs/admin/photo_manager.php', 'includes/post/post_cashbook.php', 'lib/StaffJobsGate.php',
    'lib/PluginConfig.php', 'lib/currency.php',
    'tabs/sales/more_menu.php',   // 5.18.97: the phone's More menu gets no entry (SPEC §2.8.4: an admin has no menu there)
    'includes/navigation.php', 'tabs/admin/ceo_dashboard.php', 'public.php', 'includes/post_handlers.php'];   // 5.18.98: as 5.18.97 left them
// 5.18.99 (rewritten on purpose, SPEC-quote-company §3): tools/set_config.php leaves this list, as cashbook.php left it in
// 5.18.98 — 5.18.99 adds the ucrm_quote_organization_id entry and its validation block. It is held instead by
// tests/test_quote_company.php I3: ce7ac388's bytes (5.18.98 = 5.18.97's file) plus exactly those two. The bank switch's
// own entry is still asserted below ('bank_screen_enabled' => ['bool',).
$gitOk = base_of('manifest.json') !== null;
if (!$gitOk) {
    $skip++;
    echo "  skip section A: Git cannot supply " . BK_BASE . " (a skip, not a pass)\n";
} else {
    foreach ($UNTOUCHED as $rel) {
        $b = base_of($rel);
        is_($b !== null && hash('sha256', $b) === hash('sha256', src($rel)), "{$rel} is byte-identical to " . BK_BASE);
    }
    // manifest.json: the release's version and nothing else — ec0accd4's bytes with the one version line moved to the
    // release's (5.18.101: the version pin moves with the release; was 5.18.100, 5.18.99 and 5.18.98 before it).
    $mb = (string)base_of('manifest.json');
    $mw = str_replace('    "version": "5.18.97",', '    "version": "5.18.101",', $mb, $mn);
    t('manifest.json is ' . BK_BASE . '\'s with one line changed, its version, 5.18.97 → 5.18.101',
        [$mn, hash('sha256', $mw) === hash('sha256', src('manifest.json')), (string)(json_decode(src('manifest.json'), true)['information']['version'] ?? '')],
        [1, true, '5.18.101']);
    // includes/routes.php: the base with 5.18.98's changed lines put in their place; then every line of it is still there, in
    // order, and the extra lines are 5.18.98's, in blocks that each name it, and only these.
    $rb = (string)base_of('includes/routes.php'); $chg = [];
    foreach (BK_R98_CHANGED as [$old, $new]) { $chg[] = [substr_count($rb, $old), strpos($new, '5.18.98') !== false]; $rb = str_replace($old, $new, $rb); }
    t('includes/routes.php: each of the ' . count(BK_R98_CHANGED) . ' lines or rows 5.18.98 changes is ' . BK_BASE . '\'s text exactly once, and its new text names 5.18.98',
        $chg, array_fill(0, count(BK_R98_CHANGED), [1, true]));
    [$kept, $extra, $blocks] = bk_insertions(explode("\n", $rb), explode("\n", src('includes/routes.php')));
    $marked = count(array_filter($blocks, function ($bl) { return strpos(implode("\n", $bl), '5.18.98') !== false; }));
    t('includes/routes.php: with those in place, every line of ' . BK_BASE . ' is kept, in order; 11 line(s) added, in blocks that each name 5.18.98',
        [$kept, $extra, $marked === count($blocks)], [true, 11, true]);
    // The menu-link blocks that F reads (5.18.97): measured against the base of the release that added them.
    $ADDED = [];
    foreach (['includes/navigation.php' => 9, 'tabs/admin/ceo_dashboard.php' => 6] as $rel => $want) {
        $lb = base_of($rel, BK_LINKS_BASE);
        [$kept, $extra, $blocks] = bk_insertions(explode("\n", (string)$lb), explode("\n", src($rel)));
        $ADDED[$rel] = array_map(function (array $bl): string { return implode("\n", $bl); }, $blocks);
        $marked = count(array_filter($blocks, function ($bl) { $t = implode("\n", $bl); return strpos($t, '5.18.97') !== false || strpos($t, 'bank') !== false; }));
        t("{$rel}: against " . BK_LINKS_BASE . " (5.18.96) every line is kept, in order; {$want} line(s) added, the ECO Bank link, in blocks that each name the Bank release",
            [$lb !== null && $kept, $extra, $marked === count($blocks)], [true, $want, true]);
    }
    $r = src('includes/routes.php');
    $hunkAt = strpos($r, "// ── The Bank screen's export (5.18.97");
    $cbAt = strpos($r, "if ((\$tab ?? '') === 'cashbook' && !empty(\$_GET['cb_export']) && \$_GET['cb_export'] === 'csv') {");
    is_($hunkAt !== false && $cbAt !== false && $hunkAt > $cbAt, 'the export hunk sits after the Cashbook export');
}
$pub = src('public.php');
is_(strpos($pub, "    'bank'                 => 'tabs/accounts/bank.php',") !== false && strpos($pub, "    'bank'                 => '*admin',") !== false,
    'public.php registers the tab and its permission, *admin (never absent)');
is_(preg_match("/'bank_account_profiles', 'bank_checks', 'bank_statements', 'bank_statement_lines', 'bank_line_events',\n        \];\n        if \(in_array\(strtolower\(\\\$tName\), \\\$forbidden, true\)\)/", $pub) === 1,
    'the five bank_* tables close the emergency repair page\'s $forbidden list');
is_(strpos(src('includes/post_handlers.php'), "require __DIR__ . '/post/post_bank.php';") !== false, 'post_handlers.php requires the handler');
is_(preg_match("/'bank_screen_enabled' => \['bool',/", src('tools/set_config.php')) === 1, 'tools/set_config.php lists the switch, as a bool');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nB. Nothing outside the module names the bank tables\n";
$re = '/\b(' . implode('|', BK_TABLES) . ')\b/';
$skipDirs = ['tests', 'docs', 'data', '.git', 'prototype', 'dishnet-mikrotik-control-plane', 'node_modules'];
$hits = []; $scanned = 0;
$it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
    function ($f, $k, $iter) use ($root, $skipDirs) {
        $rel = substr($f->getPathname(), strlen($root) + 1);
        return !($f->isDir() && in_array(explode('/', $rel)[0], $skipDirs, true));
    }));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($root) + 1);
    if (!preg_match('/\.(php|sql|js|json|html|sh|inc)$/', $rel)) continue;
    $scanned++;
    $txt = (string)file_get_contents($f->getPathname());
    if (!preg_match($re, $txt)) continue;
    if (in_array($rel, BK_MODULE, true) || $rel === 'migrations/088_bank_statements.sql') continue;
    if ($rel === 'public.php') {
        $lines = array_values(array_filter(explode("\n", $txt), function ($l) use ($re) { return preg_match($re, $l) === 1; }));
        if ($lines === ["            'bank_account_profiles', 'bank_checks', 'bank_statements', 'bank_statement_lines', 'bank_line_events',"]) continue;
    }
    if ($rel === 'includes/routes.php' && !preg_match($re, str_replace(routes_hunk(), '', $txt))) continue;
    $hits[] = $rel;
}
t('no other file names bank_account_profiles, bank_checks, bank_statements, bank_statement_lines or bank_line_events', $hits, []);
is_($scanned > 300, "control: {$scanned} files were read");

// ═══════════════════════════════════════════════════════════════════════════
echo "\nC. The module writes no book and does not read cb_ledger\n";
$WRITERS = ['addEntry', 'addEntryRaw', 'recordAccountTransfer', 'recordFunding', 'recordCashExchange', 'assignAccount', 'voidEntry', 'approveEntry',
    'rejectEntry', 'updateEntry', 'deleteEntry', 'BankImport', 'CashbookService', 'FinAudit'];
$fromAccounts = 0; $sqlTables = [];
foreach (module_sources() as $rel => $code) {
    $toks = code_tokens($code);
    $ids = idents_of($toks); $strs = strings_of($toks);
    t("{$rel}: no Cashbook writer, CashbookService, FinAudit or BankImport", array_values(array_unique(array_intersect($ids, $WRITERS))), []);
    $bs = 0;
    foreach ($toks as $i => $tk) if (is_array($tk) && $tk[0] === T_STRING && $tk[1] === 'BankStatement' && is_array($toks[$i + 1] ?? null) && $toks[$i + 1][0] === T_DOUBLE_COLON) $bs++;
    t("{$rel}: no BankStatement::", $bs, 0);
    $j = implode("\n", $strs);
    t("{$rel}: no cb_ledger, fin_audit, INSERT INTO cb_, UPDATE cb_ or DELETE FROM cb_ in its code",
        array_values(array_filter(['cb_ledger', 'fin_audit', 'INSERT INTO cb_', 'UPDATE cb_', 'DELETE FROM cb_'], function ($n) use ($j) { return stripos($j, $n) !== false; })), []);
    t("{$rel}: v1 never updates a kept line (no UPDATE bank_statement_lines)", preg_match('/UPDATE\s+bank_statement_lines/i', $j), 0);
    preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+([A-Za-z_][A-Za-z0-9_]*)/', $j, $m);   // SQL here is upper case; prose is not
    foreach ($m[1] as $tb) $sqlTables[$tb] = true;
    $fromAccounts += preg_match_all('/\bFROM\s+cb_accounts\b/', $j);
}
$other = array_values(array_filter(array_keys($sqlTables), function ($tb) { return !in_array($tb, BK_TABLES, true) && !in_array($tb, ['sqlite_master', 'sqlite_sequence', 'cb_accounts'], true); }));
t('the module\'s SQL names only the bank tables, sqlite_master/sqlite_sequence and cb_accounts', $other, []);
t('its only SQL on an existing table is one SELECT … FROM cb_accounts', $fromAccounts, 1);
is_(isset($sqlTables['bank_statement_lines']) && isset($sqlTables['bank_checks']), 'control: the scan sees the module\'s own SQL');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nD. The tab\n";
$tab = src('tabs/accounts/bank.php');
t('no innerHTML, outerHTML, insertAdjacentHTML or document.write', array_values(array_filter(['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'],
    function ($n) use ($tab) { return stripos($tab, $n) !== false; })), []);
t('no json_encode inside an onclick (and no inline handler carrying PHP output)', preg_match('/\bon[a-z]+="[^"]*(json_encode|<\?)/i', $tab), 0);
t('no dn_cur(: a USD line never wears the base currency\'s sign', substr_count($tab, 'dn_cur('), 0);
// Every echo (an echo tag, or an echo statement) of a record the tab reads passes h() or an (int) cast.
// 5.18.97: the hero's card ($_bkHc, its currency $_bkHcur, its account id $_bkHid) and the placeholder's currency ($_bkNc)
// replace v1's one card ($_bkLast); the per-account reads ($_bkHero) are never echoed at all.
$WATCH = ['$_bkL', '$_bkC', '$_bkChk', '$_bkHc', '$_bkHcur', '$_bkHid', '$_bkNc', '$_bkSt', '$_bkA', '$_bkProfile', '$_bkAcct', '$_bkName', '$_bkStrip', '$_bkRange'];
$bad = []; $seen = 0; $seenBy = []; $heroEchoed = []; $inEcho = false; $stack = []; $prev = null;
foreach (token_get_all($tab) as $tk) {
    $id = is_array($tk) ? $tk[0] : null;
    if ($id === T_OPEN_TAG_WITH_ECHO || $id === T_ECHO) { $inEcho = true; $stack = []; $prev = $tk; continue; }
    if ($inEcho && ($id === T_CLOSE_TAG || $tk === ';')) { $inEcho = false; $prev = $tk; continue; }
    if (!$inEcho || $id === T_WHITESPACE) { if ($id !== T_WHITESPACE) $prev = $tk; continue; }
    if ($tk === '(') { $stack[] = (is_array($prev) && $prev[0] === T_STRING) ? $prev[1] : ''; }
    elseif ($tk === ')') { array_pop($stack); }
    elseif ($id === T_VARIABLE && $tk[1] === '$_bkHero') { $heroEchoed[] = 'line ' . $tk[2]; }
    elseif ($id === T_VARIABLE && in_array($tk[1], $WATCH, true)) {
        $seen++; $seenBy[$tk[1]] = ($seenBy[$tk[1]] ?? 0) + 1;
        $cast = is_array($prev) && $prev[0] === T_INT_CAST;
        if (!$cast && !in_array('h', $stack, true)) $bad[] = $tk[1] . ' at line ' . $tk[2];
    }
    $prev = $tk;
}
t('every echo of a line, check, statement or account field passes h() or (int)', $bad, []);
is_($seen > 60, "control: {$seen} echoed field(s) were checked");
t('control: each of the hero\'s four is seen in an echo, so the guard still covers the cards', array_values(array_filter(['$_bkHc', '$_bkHcur', '$_bkHid', '$_bkNc'],
    function ($v) use ($seenBy) { return ($seenBy[$v] ?? 0) === 0; })), []);
t('the per-account reads ($_bkHero) are never echoed at all', $heroEchoed, []);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nE. The module's code: dates, numbers, CSV, logs, folders, file names\n";
foreach (module_sources() as $rel => $code) {
    $toks = code_tokens($code);
    $ids = idents_of($toks);
    t("{$rel}: no strtotime() and no error_log()", array_values(array_unique(array_intersect($ids, ['strtotime', 'error_log']))), []);
    t("{$rel}: no (float)", count(array_filter($toks, function ($tk) { return is_array($tk) && $tk[0] === T_DOUBLE_CAST; })), 0);
    $flat = '';
    foreach ($toks as $tk) $flat .= is_array($tk) ? $tk[1] : $tk;
    $calls = preg_match_all('/\b(fgetcsv|fputcsv)\(/', $flat);
    preg_match_all('/\b(?:fgetcsv|fputcsv)(\((?:[^()]++|(?1))*\))/', $flat, $cm);
    $withEsc = count(array_filter($cm[0], function ($c) { return preg_match("/,'\"',''\)$/", (string)preg_replace('/\s+/', '', $c)) === 1; }));
    t("{$rel}: every fgetcsv/fputcsv passes the escape ''", [$calls, $withEsc], [$calls, $calls]);
    t("{$rel}: no 'uploads/' in its code", count(array_filter(strings_of($toks), function ($s) { return stripos($s, 'uploads/') !== false; })), 0);
    t("{$rel}: never reads a file's name from \$_FILES (nor from the upload's entry)", preg_match_all('/\$_FILES\[[^\]]*\]\[[\'"]name[\'"]\]|\$file\[[\'"]name[\'"]\]/', $flat), 0);
}
is_(strpos(src('tabs/admin/photo_manager.php'), 'bank_statements') === false, 'the Photo Manager\'s folder list does not name bank_statements');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nF. The gate, at every entry point\n";
$ENTRY = ['tabs/accounts/bank.php' => code_only($tab), 'includes/post/post_bank.php' => code_only(src('includes/post/post_bank.php')),
    'includes/routes.php (the export hunk)' => code_only("<?php\n" . routes_hunk())];
foreach ($ENTRY as $rel => $code) {
    $o = strpos($code, 'BankGate::open('); $a = strpos($code, 'BankGate::allows('); $n = strpos($code, 'new BankStatementStore(');
    is_($o !== false && $a !== false && $n !== false && $o < $a && $a < $n, "{$rel}: BankGate::open( first, then BankGate::allows(, before the store is built");
    is_(preg_match('/if \(!BankGate::open\(\$dataDir \?\? null\)\)/', $code) === 1 && preg_match('/\$_bk[a-z]*Me === null/', $code) === 1, "{$rel}: both answers are acted on");
}
$tabCode = code_only($tab);
$html0 = strpos($tabCode, '?>');
is_($html0 !== false && strpos($tabCode, 'BankGate::allows(') < $html0, 'the tab draws nothing before both answers');
$pb = code_only(src('includes/post/post_bank.php'));
is_(preg_match("/if \(!csrfCheck\(\) \|\| CustomerSession::crossSite\(\) \|\| !BankGate::fromOwnPage\('POST'\)\) \{\n\s+flash\('[^']+', 'danger'\);\n\s+redirect\(/", $pb) === 1,
    'the POST handler refuses on csrfCheck() OR crossSite() OR not fromOwnPage(\'POST\'), with a flash and a redirect');
$rt = code_only("<?php\n" . routes_hunk());
foreach (['includes/post/post_bank.php' => $pb, 'includes/routes.php (the export hunk)' => $rt] as $rel => $code) {
    $l = strpos($code, '$auth->requireLogin()'); $o = strpos($code, 'BankGate::open(');
    is_($l !== false && $o !== false && $l < $o, "{$rel}: the visitor is signed in before the gate is asked, so a visitor who is not signed in learns nothing about the switch (BK-SEC-4)");
}
$xo = strpos($rt, "if (!BankGate::fromOwnPage('GET')) \$_bkxRefuse(403,"); $xr = strpos($rt, '->recordExport('); $xs = strpos($rt, 'new BankStatementStore(');
is_($xo !== false && $xr !== false && $xs !== false && strpos($rt, 'BankGate::open(') < $xo && $xo < $xs && $xo < $xr && $xo < strpos($rt, 'fputcsv('),
    'the export refuses a request that did not come from this site\'s own page after the gate and before the store, the event and any line (BK-SEC-1)');
is_(strpos($pb, 'csrfCheck()') > strpos($pb, 'BankGate::allows(') && strpos($pb, 'csrfCheck()') < strpos($pb, 'new BankStatementStore('), 'the token is checked after the gate and before anything is read');
$gate = src('lib/BankGate.php');
is_(strpos(code_only($gate), 'PluginConfig::read(') !== false && preg_match('/->load\(|SqliteStore::|\$config\b/', code_only($gate)) === 0,
    'BankGate reads PluginConfig::read(), never a store copy');
// 5.18.97 (the operator, 18 Oct; Q15): the menu links. Within the block A finds added to each menu file (5.18.98 adds the
// Cashbook's "Bank & other accounts" tab to these): showLink() once, with the one call text, as the if right before the
// one tab=bank link, and no gate of the block's own. Scoped to the block: navigation.php names $config[ elsewhere.
const BK_LINK_CALL = 'BankGate::showLink($dataDir ?? null, $store ?? null, is_array($retailer ?? null) ? $retailer : [])';
if (!isset($ADDED)) {
    $skip++;
    echo "  skip the menu links' blocks: Git cannot supply " . BK_BASE . ", so A found no block (a skip, not a pass)\n";
} else {
    foreach (['includes/navigation.php', 'tabs/admin/ceo_dashboard.php'] as $rel) {
        $bl = $ADDED[$rel] ?? [];
        $code = count($bl) === 1 ? code_only($bl[0]) : '';
        t("{$rel}: its one added block calls BankGate::showLink( once, with the one call text, as the if right before its one tab=bank link",
            [count($bl), substr_count($code, 'BankGate::showLink('), substr_count($code, BK_LINK_CALL), substr_count($code, 'href="?page=dashboard&tab=bank"'),
             preg_match('/if \(' . preg_quote(BK_LINK_CALL, '/') . '\): \?>\n\s*<a href="\?page=dashboard&tab=bank"/', $code)], [1, 1, 1, 1, 1]);
        t("{$rel}: the block has no gate of its own — no BankGate::open(, BankGate::allows(, bank_screen_enabled, \$config[ or \$isAdmin",
            array_values(array_filter(['BankGate::open(', 'BankGate::allows(', 'bank_screen_enabled', '$config[', '$isAdmin'], function ($n) use ($code) { return strpos($code, $n) !== false; })), []);
    }
    is_(strpos(code_only(src('includes/navigation.php')), '$config[') !== false, 'control: navigation.php names $config[ outside the block, which is why the rule is scoped to it');
}
$showBody = preg_match('/    public static function showLink\(\?string \$dataDir, \$store, array \$sessionRetailer\): bool\n    \{\n(.*?)\n    \}\n/s', $gate, $sm) ? $sm[1] : null;
t('showLink()\'s body is exactly the design\'s: the session copy\'s is_admin (the dispatcher\'s *admin), then open(), then allows(\'bank.view\')',
    $showBody, "        return !empty(\$sessionRetailer['is_admin']) && self::open(\$dataDir) && self::allows('bank.view', \$store, \$sessionRetailer) !== null;");

// ═══════════════════════════════════════════════════════════════════════════
echo "\nG. The backups\n";
require_once $root . '/lib/EcobankDetailsCsv.php';
require_once $root . '/lib/BankStatementStore.php';
is_(stripos(BankStatementStore::DIR, 'uploads') === false && strpos(BankStatementStore::DIR, '/') === false, 'the statement folder is its own top-level folder, outside uploads/ (' . BankStatementStore::DIR . ')');
if (!class_exists('ZipArchive')) {
    $skip++;
    echo "  skip the Drive zip: no ZipArchive here (a skip, not a pass)\n";
} else {
    $T = rtrim(sys_get_temp_dir(), '/') . '/bk-tb4-' . getmypid() . '-' . bin2hex(random_bytes(3));
    $d = $T . '/data';
    foreach ([$d . '/' . BankStatementStore::DIR . '/7', $d . '/' . BankStatementStore::DIR . '/' . BankStatementStore::PENDING, $d . '/uploads/expenses', $d . '/backups'] as $dir) @mkdir($dir, 0700, true);
    file_put_contents($d . '/' . BankStatementStore::DIR . '/7/1-0123456789abcdef.csv', "\"RH kept\"\n");
    file_put_contents($d . '/' . BankStatementStore::DIR . '/' . BankStatementStore::PENDING . '/2-0123456789abcdef.csv', "\"RH held\"\n");
    file_put_contents($d . '/uploads/expenses/rh-photo.jpg', 'RH');
    file_put_contents($d . '/rh_settings.json', '{}');
    if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; } }
    require_once $root . '/lib/GoogleDriveBackup.php';
    $g = new GoogleDriveBackup($d);
    $mz = new \ReflectionMethod($g, 'createDataZip');
    $mz->setAccessible(true);
    $zr = $mz->invoke($g, $T . '/data.zip');
    $names = [];
    $z = new \ZipArchive();
    if (($zr['ok'] ?? false) && $z->open($T . '/data.zip') === true) { for ($i = 0; $i < $z->numFiles; $i++) $names[] = (string)$z->getNameIndex($i); $z->close(); }
    t('the Google Drive DATA zip carries the uploads and the top-level JSON (the control) and no statement, kept or held',
        [in_array('data/uploads/expenses/rh-photo.jpg', $names, true), in_array('data/rh_settings.json', $names, true),
         array_values(array_filter($names, function ($n) { return stripos($n, 'bank_statements') !== false || strpos($n, 'RH kept') !== false || stripos($n, '.pending') !== false; }))],
        [true, true, []]);
    exec('rm -rf ' . escapeshellarg($T));
}
is_(strpos(src('includes/post/post_sync.php'), 'bank_statements') === false && strpos(src('main.php'), 'bank_statements') === false,
    'the admin backup download and main.php\'s auto-backup name no bank folder');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nH. The export's cells, run from the hunk's own code\n";
$hunk = routes_hunk();
$fnText = preg_match('/\$_bkxText = (function \(\$v\): string \{.*?\n    \});/s', $hunk, $m1) ? $m1[1] : '';
$fnAmt  = preg_match('/\$_bkxAmt = (function \(int \$u\): string \{.*?\n    \});/s', $hunk, $m2) ? $m2[1] : '';
is_($fnText !== '' && $fnAmt !== '', 'the hunk holds its text-cell and amount functions');
$text = $fnText !== '' ? eval('return ' . $fnText . ';') : function ($v) { return 'MISSING'; };
$amt  = $fnAmt !== '' ? eval('return ' . $fnAmt . ';') : function ($u) { return 'MISSING'; };
t('a cell starting with = + - @, a tab or a CR gets a \' in front; anything else is untouched',
    array_map($text, ['=1+1', '+RH', '-RH', '@SUM(1)', "\tRH", "\rRH", 'RH=1', 'RH', '', '0001', "'RH"]),
    ["'=1+1", "'+RH", "'-RH", "'@SUM(1)", "'\tRH", "'\rRH", 'RH=1', 'RH', '', '0001', "'RH"]);
t('amounts are exact plain numbers from minor units, a minus where below zero', array_map($amt, [0, 5, 90, 123450, -1230, -5, 123456789012345]),
    ['0.00', '0.05', '0.90', '1234.50', '-12.30', '-0.05', '1234567890123.45']);
is_(preg_match_all("/fputcsv\(\\\$_bkxOut, /", $hunk) === 2 && strpos($hunk, "'In', 'Out', 'Balance'") !== false, 'the export writes its header and one row per line');

// ═══════════════════════════════════════════════════════════════════════════
if ($withMutants) {
    echo "\nI. Weakened copies each fail this test; the unchanged copy passes\n";
    require_once $lib . '/tests/fixtures/staff_jobs_sandbox.php';
    $child = function (string $tree): array {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tree) . ' --no-mutants 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        $tally = preg_match('/^(\d+) passed, (\d+) failed$/', (string)end($out), $tm) ? [(int)$tm[1], (int)$tm[2]] : null;
        return [$rc, $fails, $tally];
    };
    $TAB = 'tabs/accounts/bank.php'; $PB = 'includes/post/post_bank.php'; $RT = 'includes/routes.php'; $ST = 'lib/BankStatementStore.php'; $GT = 'lib/BankGate.php';
    // 5.18.97: the hero's one read per account, the anchor of the card copy (BW23 re-anchored as BC5b)
    $HERO = "\$_bkHero[\$_bkHid] = ['acct' => \$_bkA, 'eco' => \$_bkEco, 'head' => \$_bkHt, 'last' => \$_bkStore->latestLine(\$_bkHid), 'profile' => \$_bkStore->profile(\$_bkHid)];";
    $NAV = 'includes/navigation.php'; $CEO = 'tabs/admin/ceo_dashboard.php'; $LINK_IF = 'if (' . BK_LINK_CALL . '): ?>';
    $MUTANTS = [
        // 5.18.97: BW51 ("a menu link in the sidebar") is retired with the rule it guarded (O9); the menu links' copies replace it
        ['BM1b the sidebar\'s link shown unconditionally', [$NAV, $LINK_IF, 'if (true): ?>'], ['includes/navigation.php: its one added block calls BankGate::showLink( once, with the one call text, as the if right before its one tab=bank link']],
        ['BM2b the sidebar gated by the session\'s $isAdmin and the store copy\'s switch', [$NAV, $LINK_IF, "if (\$isAdmin && !empty(\$config['bank_screen_enabled'])): ?>"],
            ['includes/navigation.php: its one added block calls BankGate::showLink( once, with the one call text, as the if right before its one tab=bank link', 'includes/navigation.php: the block has no gate of its own']],
        ['BM10b the sidebar passing its own admin flag to showLink()', [$NAV, BK_LINK_CALL, "BankGate::showLink(\$dataDir ?? null, \$store ?? null, is_array(\$retailer ?? null) ? ['is_admin' => true] + \$retailer : [])"],
            ['includes/navigation.php: its one added block calls BankGate::showLink( once, with the one call text, as the if right before its one tab=bank link']],
        ['BM13 the sidebar asking open() and allows() itself', [$NAV, $LINK_IF,
            "if (BankGate::open(\$dataDir ?? null) && BankGate::allows('bank.view', \$store ?? null, is_array(\$retailer ?? null) ? \$retailer : []) !== null): ?>"],
            ['includes/navigation.php: its one added block calls BankGate::showLink( once, with the one call text, as the if right before its one tab=bank link', 'includes/navigation.php: the block has no gate of its own']],
        ['BM11b the CEO page\'s Quick Link shown unconditionally', [$CEO, $LINK_IF, 'if (true): ?>'], ['tabs/admin/ceo_dashboard.php: its one added block calls BankGate::showLink( once, with the one call text, as the if right before its one tab=bank link']],
        ['BW24 a reader of bank_statement_lines in the Cashbook tab', ['tabs/accounts/cashbook.php', '$cb      = new CashbookService($store, $dataDir);',
            "\$cb      = new CashbookService(\$store, \$dataDir); \$_rhBank = \$store->getPdo()->query('SELECT COUNT(*) FROM bank_statement_lines')->fetchColumn();"]],
        ['BC5b (BW23 re-anchored) a card read from cb_ledger', [$TAB, $HERO, $HERO . " \$_bkBook = \$store->getPdo()->query('SELECT SUM(amount) FROM cb_ledger')->fetchColumn();"],
            ['tabs/accounts/bank.php: no cb_ledger, fin_audit, INSERT INTO cb_, UPDATE cb_ or DELETE FROM cb_ in its code']],
        ['the handler builds a CashbookService', [$PB, '    $_bkpSay = function', "    \$_bkpCb = new CashbookService(\$store, \$dataDir);\n    \$_bkpSay = function"]],
        ['BW42 v1 updates a line\'s status', [$ST, '    private function insertLines(int $statementId', "    private function markLines(): void { \$this->pdo->exec(\"UPDATE bank_statement_lines SET status = 'matched'\"); }\n\n    private function insertLines(int \$statementId"]],
        ['the store reads a second existing table', [$ST, '    private function tableExists(string $name): bool', "    private function rhBook(): int { return (int)\$this->pdo->query('SELECT COUNT(*) FROM cb_ledger')->fetchColumn(); }\n\n    private function tableExists(string \$name): bool"]],
        ['BW35 innerHTML in the tab', [$TAB, "name.textContent = file.files && file.files[0] ? file.files[0].name : '';", "name.innerHTML = file.files && file.files[0] ? file.files[0].name : '';"]],
        ['json_encode in an onclick', [$TAB, '<button type="submit" class="cb3-filter-btn">Show</button>', '<button type="submit" class="cb3-filter-btn" onclick="bkGo(<?= h(json_encode($_bkId)) ?>)">Show</button>']],
        ['dn_cur( in the tab', [$TAB, "\$_bkSigned = function (string \$cur, int \$u, bool \$in) use (\$_bkMoney): string { return (\$in ? '+' : '−') . \$_bkMoney(\$cur, \$u); };",
            "\$_bkSigned = function (string \$cur, int \$u, bool \$in) use (\$_bkMoney, \$config): string { return (\$in ? '+' : '−') . dn_cur(\$config) . \$_bkMoney(\$cur, \$u); };"]],
        ['a Reference echoed without h()', [$TAB, "          <td><?= h((string)\$_bkL['reference']) ?></td>", "          <td><?= (string)\$_bkL['reference'] ?></td>"]],
        ['BD1 a card\'s book name echoed without h()', [$TAB, "<div class=\"bk-card-n\"><?= h(\$_bkMask((string)\$_bkHc['acct']['name'])) ?></div>",
            "<div class=\"bk-card-n\"><?= \$_bkMask((string)\$_bkHc['acct']['name']) ?></div>"], ['every echo of a line, check, statement or account field passes h() or (int)']],
        ['strtotime( in the tab', [$TAB, "        \$d = new \\DateTime(\$utc, new \\DateTimeZone('UTC'));", "        \$d = new \\DateTime('@' . strtotime(\$utc . ' UTC'));"]],
        ['(float) in the store', [$ST, "\$in = (int)\$r[3]; \$out = (int)\$r[4]; \$closing = (int)\$last['running_units'];", "\$in = (int)(float)\$r[3]; \$out = (int)\$r[4]; \$closing = (int)\$last['running_units'];"]],
        ['an fputcsv with the default escape', [$RT, "            \$_bkxText((string)\$_bkxL['status'] === 'imported' ? 'Not compared' : 'Unknown status'),\n        ], ',', '\"', '');",
            "            \$_bkxText((string)\$_bkxL['status'] === 'imported' ? 'Not compared' : 'Unknown status'),\n        ]);"]],
        ['error_log( in the handler', [$PB, "        flash('ECO Bank could not finish this. Nothing was kept. Report it.', 'danger');",
            "        error_log('bank: ' . \$_bkpE->getMessage());\n        flash('ECO Bank could not finish this. Nothing was kept. Report it.', 'danger');"]],
        ['BW21 the folder under uploads/', [$ST, "public const DIR       = 'bank_statements';", "public const DIR       = 'uploads/bank_statements';"]],
        ['the handler reads the file\'s name', [$PB, "            \$_bkpUp = BankStatementStore::uploaded(", "            \$_bkpName = (string)(\$_FILES['bk_file']['name'] ?? '');\n            \$_bkpUp = BankStatementStore::uploaded("]],
        ['the tab without BankGate::open()', [$TAB, "if (!BankGate::open(\$dataDir ?? null)) {\n    echo '<div style=\"padding:24px;color:#6b7280;\">ECO Bank is not available on this installation.</div>';",
            "if (false) {\n    echo '<div style=\"padding:24px;color:#6b7280;\">ECO Bank is not available on this installation.</div>';"]],
        ['the export without BankGate::allows()', [$RT, "    \$_bkxMe = BankGate::allows('bank.view', \$store, is_array(\$_bkxUser) ? \$_bkxUser : []);", '    $_bkxMe = $_bkxUser;']],
        ['the handler without crossSite()', [$PB, "if (!csrfCheck() || CustomerSession::crossSite() || !BankGate::fromOwnPage('POST')) {", "if (!csrfCheck() || !BankGate::fromOwnPage('POST')) {"]],
        ['the handler without fromOwnPage()', [$PB, "if (!csrfCheck() || CustomerSession::crossSite() || !BankGate::fromOwnPage('POST')) {", 'if (!csrfCheck() || CustomerSession::crossSite()) {']],
        ['the export without fromOwnPage()', [$RT, "    if (!BankGate::fromOwnPage('GET')) \$_bkxRefuse(403, 'The security check failed, so nothing was sent. Open ECO Bank and export from there.');\n", '']],
        ['the export asking the gate before the sign-in', [$RT, "    \$_bkxUser = \$auth->requireLogin();\n    if (!BankGate::open(\$dataDir ?? null)) \$_bkxRefuse(403, 'ECO Bank is not available on this installation.');\n",
            "    if (!BankGate::open(\$dataDir ?? null)) \$_bkxRefuse(403, 'ECO Bank is not available on this installation.');\n    \$_bkxUser = \$auth->requireLogin();\n"]],
        ['the handler asking the gate before the sign-in', [$PB, "    \$_bkpUser = \$auth->requireLogin();\n    if (!BankGate::open(\$dataDir ?? null)) {\n        flash('ECO Bank is not available on this installation.', 'danger');\n        redirect('?page=dashboard');\n    }\n",
            "    if (!BankGate::open(\$dataDir ?? null)) {\n        flash('ECO Bank is not available on this installation.', 'danger');\n        redirect('?page=dashboard');\n    }\n    \$_bkpUser = \$auth->requireLogin();\n"]],
        ['BW34 BankGate on the store copy', [$GT, 'self::$cfg[$dataDir] = PluginConfig::read(dirname(__DIR__), $dataDir);', "self::\$cfg[\$dataDir] = SqliteStore::create(\$dataDir)->load('kyc_config.json');"]],
        ['the neutraliser without @', [$RT, "strpos(\"=+-@\\t\\r\", \$s[0])", "strpos(\"=+-\\t\\r\", \$s[0])"]],
        ['a changed line in public.php beside the hunks', ['public.php', "    'distributors' => '*admin',", "    'distributors' => null,"]],
        ['the forbidden hunk missing', ['public.php', "            'bank_account_profiles', 'bank_checks', 'bank_statements', 'bank_statement_lines', 'bank_line_events',\n", '']],
        ['manifest.json left at 5.18.97', ['manifest.json', '    "version": "5.18.101",', '    "version": "5.18.97",']],   // 5.18.101 (was 5.18.100)
        ['manifest.json changed beside its version', ['manifest.json', '    "url": "https://dishnetafrica.com",', '    "url": "https://example.test",']],
    ];
    $mainPass = $pass;                                                      // the child runs sections A–H, exactly what this run passed
    [$tmp, $n] = sj_weakened_copy($lib, $TAB, '$_bkPer   = 50;', '$_bkPer   = 50;');
    if ($n === 1) {
        [$rc, $fails, $tally] = $child($tmp);
        is_($rc === 0 && $fails === [] && $tally === [$mainPass, 0], "the unchanged copy passes: {$mainPass} passed, 0 failed, as this run",
            'exit ' . $rc . ' tally ' . json_encode($tally) . ' ' . ($fails[0] ?? ''));
    } else {
        is_(false, 'the unchanged copy: its anchor occurs once', "found {$n} times");
    }
    exec('rm -rf ' . escapeshellarg($tmp));
    // A copy may name the cases it must fail (5.18.97's, from the design's table): each must be among its failures.
    foreach ($MUTANTS as $mu) {
        [$label, [$rel, $old, $new]] = $mu; $expect = $mu[2] ?? [];
        [$tmp, $n] = sj_weakened_copy($lib, $rel, $old, $new);
        $s = (string)@file_get_contents($tmp . '/' . $rel);
        $applied = $n === 1 && ($new === '' || strpos($s, $new) !== false);
        if ($applied && substr($rel, -4) === '.php') { $lint = []; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp . '/' . $rel) . ' 2>&1', $lint, $lrc); $applied = $lrc === 0; }
        if (!$applied) {
            is_(false, "weakened copy \"{$label}\": its anchor occurs once, the change is in the copy, and it lints", "found {$n} times");
            exec('rm -rf ' . escapeshellarg($tmp));
            continue;
        }
        [$rc, $fails, $tally] = $child($tmp);
        $missed = array_values(array_filter($expect, function (string $w) use ($fails): bool {
            foreach ($fails as $l) if (strpos($l, '  FAIL ' . $w) === 0) return false;
            return true;
        }));
        is_($rc !== 0 && $fails !== [] && $missed === [], "caught: {$label}" . ($expect ? ' — at each of its ' . count($expect) . ' named case(s)' : ''),
            'exit ' . $rc . ', ' . count($fails) . ' failure(s)' . ($missed ? '; not failed: ' . implode(' | ', $missed) : ''));
        echo '         ' . count($fails) . ' assertion(s) failed' . ($tally ? " ({$tally[0]} passed)" : '') . ($fails ? '; first: ' . trim(substr($fails[0], 7, 110)) : '') . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

if ($skip > 0) echo "\n  ({$skip} skip(s): reported, never counted as passes)\n";
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

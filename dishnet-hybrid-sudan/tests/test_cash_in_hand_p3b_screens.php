<?php
declare(strict_types=1);
/**
 * test_cash_in_hand_p3b_screens.php — P3b (5.18.96, D2 = A; SPEC §d.1 T2): on a Uganda book every screen that shows
 * cash in hand shows CashbookService::cashInHand() for its own scope, at 2 dp — the Cashbook hero per project, the
 * Balance column's top value per stream, the CSV export, the landing hero and chips, the Office figure, the CEO tiles,
 * the evening WhatsApp, tools/cash_in_hand.php and the API's running balances.
 *
 * Driven through the real pages on a sandboxed plugin (fake uCRM, fake Evolution). The book is written through the
 * plugin's own writers, in process, on the sandbox's own store; every amount and name is invented. The oracle is always
 * THIS checkout's library (the figures are also pinned by hand), so a weakened tree served by the sandbox is judged
 * against the rule, never against itself. Also proved on the same book:
 *   1. the CEO page reads "Cash in hand" under its new heading, negative USD red, "Today's collections", and the two
 *      tiles size their 2-dp figure to the screen so that both fit a 320px phone;
 *   2. the Cashbook's Alert 2 per currency, the Add Entry pill in the base code, the new wording and the count;
 *   3. Alert 6 as recorded (Q7): shown with cash in hand above zero, a pending disbursement and no receipt in seven
 *      days; not shown on BlueCARD, where its other two tests hold and cash in hand is below zero; not shown once one
 *      approved receipt on the Ecobank account falls inside the window;
 *   4. movement: a bank-account row moves no cash-in-hand screen; a pending row moves nothing until it is approved,
 *      then moves every screen whose scope holds it by exactly its amount;
 *   5. control: currencyPositions() — the Phase-C account position, bank included — is unchanged on the same book;
 *   6. weakened copies of the code each fail this test, the unchanged copy passes, and f4b4c96's own code fails it.
 *
 *   php test_cash_in_hand_p3b_screens.php [--root=DIR] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-mutants']);
$lib  = dirname(__DIR__);                                                   // the oracle's library: always this checkout's
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : $lib;     // the tree the sandbox serves
$withMutants = !isset($opt['no-mutants']);
date_default_timezone_set('Africa/Kampala');                               // the sandbox's own zone (its date() and ours agree)

// This process's own vault before anything can load a configuration; the sandbox points it at its own while it runs.
$own = sys_get_temp_dir() . '/p3b-t2-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($own, 0700, true);
$ownVault = $own . '/vault.json';
putenv('DN_VAULT_FILE=' . $ownVault);
register_shutdown_function(function () use ($own) { exec('rm -rf ' . escapeshellarg($own)); });
ini_set('error_log', $own . '/php-error.log');                              // the in-process vault's notices, kept off the tally
$defVault  = dirname($lib) . '/.dishnet-sudan.vault.json';                  // ConfigVault::path()'s default for this tree
$defBefore = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;

require_once $lib . '/tests/fixtures/staff_jobs_sandbox.php';
require_once $lib . '/lib/StoreInterface.php';
require_once $lib . '/lib/SqliteStore.php';
require_once $lib . '/lib/currency.php';
require_once $lib . '/lib/CashbookService.php';
require_once $lib . '/lib/StaffCashPositionService.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . substr($d, 0, 700) : '') . "\n"; }
}

$UG = ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'currency_symbol' => 'UGX', 'currency_code' => 'UGX',
       'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD'];
$PROJ  = ['dishnet', '4g', 'bluecard'];
$CHIPS = ['dishnet' => 'Fiber & Starlink', '4g' => 'DishNet 4G', 'bluecard' => 'BlueCARD'];
$VIEWS = ['' => 'All', 'UGX' => 'UGX', 'USD' => 'USD'];                      // cb_curr / currency filter => label
$money = function (string $s): ?float {                                     // "UGX -1,234.50" or "-1,234.50" → -1234.5
    $s = trim(html_entity_decode(strip_tags($s), ENT_QUOTES));
    if (!preg_match('/^(?:[A-Z]{3} ?)?(-?[0-9,]+(?:\.[0-9]+)?)$/', $s, $m)) return null;
    return round((float)str_replace(',', '', $m[1]), 2);
};
$f2 = function ($v): string { return $v === null ? 'none' : number_format((float)$v, 2); };
$same = function ($a, $b): bool { return $a !== null && $b !== null && abs((float)$a - (float)$b) < 0.005; };
$day  = function (int $n): string { return date('Y-m-d', strtotime("-{$n} days")); };
$RULE_LINE = 'approved entries with no account or on cash and mobile-money accounts (5.18.96)';
$RULE_SENT = "  the ledger's cash in hand per currency (approved entries with no account or on cash and mobile-money accounts):";
$OLD_SENT  = "  the ledger's running balance per currency (rows not voided; accounts and the unassigned split play no part):";
$HERO_SUB  = 'cash &amp; mobile money · entries with no account · approved';
$WA_LINE   = '_Cash and mobile money, entries with no account, approved entries._';
$CEO_HEAD  = '🏦 Cashbook — cash in hand: all projects · revenue and expenses: DishNet';
$EXPO      = 1111.0;                                                         // the collection planted with RH-Collector One

// ═══════════════════════════════════════════════════════════════════════════
echo "A. A Uganda book, written through the plugin's own writers (every amount and name invented)\n";
$s = SjSandbox::start($root, $UG, 'p3bt2');
$GLOBALS['dataDir'] = $s->data;     // as the tools do: dn_book_effective_config() (StaffCashPositionService's base) reads it
$s->staff('boss', ['name' => 'RH-Admin One', 'email' => 'rh-admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '+256700009911']);
$s->staff('coll', ['name' => 'RH-Collector One', 'email' => 'rh-coll@example.test', 'role' => 'sales', 'phone' => '+256700009912']);
$s->login('boss', 'rh-admin@example.test', 'sj-password-1');
$svc = function () use ($s): CashbookService { return new CashbookService($s->store(), $s->data); };   // a fresh instance: no cache
$cb  = $svc();
$admin = 'RH-Admin One';
$seed = $cb->seedStandardAccounts();
$cb->addAccount('RH-Cash – USD', 'USD', 'cash');
$acc = [];
foreach ($cb->accounts() as $a) $acc[(string)$a['name']] = (int)$a['id'];
$bankU = $acc['Ecobank Uganda – UGX'] ?? 0; $bankD = $acc['Ecobank Uganda – USD'] ?? 0; $cashU = $acc['Cash – Uganda'] ?? 0;
$momo  = $acc['MTN Mobile Money'] ?? 0;   $dir   = $acc['Director/Shareholder Funding'] ?? 0; $cashD = $acc['RH-Cash – USD'] ?? 0;
is_(!empty($seed['ok']) && $bankU && $bankD && $cashU && $momo && $dir && $cashD, 'the standard Uganda chart is seeded, plus a USD cash account', json_encode([$seed, $acc]));

$e = function (array $d) use ($cb): int { $r = $cb->addEntry($d + ['validation_status' => 'na'], ['name' => 'RH-Admin One']); return (int)($r['id'] ?? 0); };
$raw = function (array $d) use ($cb): void { $cb->addEntryRaw($d + ['status' => 'approved', 'approved_by' => 'RH-Admin One', 'validation_status' => 'na']); };
$ok = [];
// dishnet — openings: cash UGX 5,000 · Ecobank UGX 70,000 · MTN 1,200 · Ecobank USD 2,000 · cash USD 50
$ok[] = $cb->recordOpeningBalance($cashU, 5000, $day(30), 'RH opening', 'RH-OB', $admin)['ok'] ?? false;
$ok[] = $cb->recordOpeningBalance($bankU, 70000, $day(30), 'RH opening', 'RH-OB', $admin)['ok'] ?? false;
$ok[] = $cb->recordOpeningBalance($momo, 1200, $day(30), 'RH opening', 'RH-OB', $admin)['ok'] ?? false;
$ok[] = $cb->recordOpeningBalance($bankD, 2000, $day(30), 'RH opening', 'RH-OB', $admin)['ok'] ?? false;
$ok[] = $cb->recordOpeningBalance($cashD, 50, $day(30), 'RH opening', 'RH-OB', $admin)['ok'] ?? false;
// a row stored with no currency (base), no account, older than every other UGX row of the stream
$raw(['project' => 'dishnet', 'date' => $day(29), 'direction' => 'in', 'amount' => 70, 'currency' => '', 'category' => 'Receipt', 'description' => 'RH blank-currency receipt']);
$ok[] = $cb->recordFunding($bankU, $dir, 9000, $day(28), 'RH funding into the bank', '', $admin)['ok'] ?? false;
$e(['project' => 'dishnet', 'date' => $day(27), 'direction' => 'in', 'amount' => 800, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-Client One payment']);
$raw(['project' => 'dishnet', 'date' => $day(26), 'direction' => 'out', 'amount' => 3000, 'currency' => 'UGX', 'category' => 'Misc Expense', 'description' => 'RH bank expense', 'account_id' => $bankU]);
$raw(['project' => 'dishnet', 'date' => $day(25), 'direction' => 'in', 'amount' => 1000, 'currency' => 'USD', 'category' => 'Receipt', 'description' => 'RH-Client Two bank receipt', 'account_id' => $bankD]);
$e(['project' => 'dishnet', 'date' => $day(24), 'direction' => 'out', 'amount' => 340, 'currency' => 'USD', 'category' => 'Misc Expense', 'description' => 'RH dollar spending, no account']);
$ok[] = $cb->recordFunding($cashU, $dir, 600, $day(23), 'RH funding into cash', '', $admin)['ok'] ?? false;
$ok[] = $cb->recordAccountTransfer($bankU, $cashU, 400, 400, $day(22), 'RH bank to cash', '', $admin)['ok'] ?? false;
$e(['project' => 'dishnet', 'date' => $day(10), 'direction' => 'out', 'amount' => 260, 'currency' => 'UGX', 'category' => 'Staff Advance', 'person' => 'RH-Collector One', 'description' => 'RH pending disbursement', 'validation_status' => 'pending']);
$ok[] = $cb->recordAccountTransfer($cashU, $momo, 150, 150, $day(9), 'RH cash to mobile money', '', $admin)['ok'] ?? false;
$cb->addEntry(['project' => 'dishnet', 'date' => $day(9), 'direction' => 'in', 'amount' => 333, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH awaiting approval'], ['name' => $admin], false);
$rej = $e(['project' => 'dishnet', 'date' => $day(9), 'direction' => 'in', 'amount' => 444, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH rejected receipt']);
$cb->rejectEntry($rej, 'RH test rejection', ['name' => $admin]);
$voi = $e(['project' => 'dishnet', 'date' => $day(9), 'direction' => 'in', 'amount' => 555, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH voided receipt']);
$ok[] = $cb->voidEntry($voi, 'RH test void', $admin)['ok'] ?? false;
// 4g — a receipt with no account, a bank expense, dollar spending, a pending disbursement
$e(['project' => '4g', 'date' => $day(21), 'direction' => 'in', 'amount' => 1750, 'currency' => 'UGX', 'category' => 'Receipt', 'description' => 'RH-Client Three payment']);
$raw(['project' => '4g', 'date' => $day(20), 'direction' => 'out', 'amount' => 222, 'currency' => 'UGX', 'category' => 'Site Power', 'description' => 'RH 4G bank expense', 'account_id' => $bankU]);
$e(['project' => '4g', 'date' => $day(19), 'direction' => 'out', 'amount' => 30, 'currency' => 'USD', 'category' => 'Misc Expense', 'description' => 'RH 4G dollar spending']);
$e(['project' => '4g', 'date' => $day(11), 'direction' => 'out', 'amount' => 50, 'currency' => 'UGX', 'category' => 'Staff Advance', 'person' => 'RH-Collector One', 'description' => 'RH 4G pending disbursement', 'validation_status' => 'pending']);
// bluecard — exactly one row, and it is a pending disbursement: Alert 6's other two tests then hold on BlueCARD, so its
// "not shown" (section E) can only come from cash in hand below zero
$e(['project' => 'bluecard', 'date' => $day(12), 'direction' => 'out', 'amount' => 125, 'currency' => 'UGX', 'category' => 'Staff Advance', 'person' => 'RH-Collector One', 'description' => 'RH BlueCARD pending disbursement', 'validation_status' => 'pending']);
is_(!in_array(false, $ok, true) && count($ok) === 10, 'every writer accepted its row (openings, two fundings, two transfers, a void)', json_encode($ok));
// money held by a collector — the exposure the landing page's Office figure subtracts
$s->store()->appendWithId('payment_collections.json', ['retailer_id' => $s->ids['coll'], 'amount' => $EXPO, 'currency' => 'UGX',
    'client_name' => 'RH-Client One', 'created_at' => date('Y-m-d H:i:s'), 'status' => 'collected']);

// The book, by class: every one P3b treats differently is present (an empty or one-sided book cannot pass).
$kinds = [];
foreach ($cb->query('SELECT id, kind FROM cb_accounts') as $a) $kinds[(int)$a['id']] = (string)$a['kind'];
$inRule = function (array $r) use (&$kinds): bool {                         // D2 = A, written independently of the library
    $aid = $r['account_id'];
    $loc = ($aid === null || (string)$aid === '0') ? true
         : ((is_int($aid) || ctype_digit((string)$aid)) && in_array($kinds[(int)$aid] ?? '', ['cash', 'momo'], true));
    return (string)$r['status'] === 'approved' && $loc;
};
$rows = function () use ($cb): array { return $cb->query('SELECT id, project, currency, status, account_id, direction, amount FROM cb_ledger ORDER BY id'); };
$cls = [];
foreach ($rows() as $r) {
    $k = ($r['status'] !== 'approved') ? 'status:' . $r['status'] : ((int)$r['account_id'] === 0 ? 'no account' : 'kind:' . ($kinds[(int)$r['account_id']] ?? '?'));
    $cls[$k] = ($cls[$k] ?? 0) + 1;
}
ksort($cls);
foreach (['no account', 'kind:cash', 'kind:momo', 'kind:bank', 'kind:director', 'status:pending_approval', 'status:rejected', 'status:voided'] as $k) {
    is_(($cls[$k] ?? 0) > 0, "the book holds at least one row of class \"{$k}\"", json_encode($cls));
}

// The oracle, and the figures pinned by hand (UGX: 5,000 + 1,200 + 70 + 800 + 600 + 400 − 260 ± 150 = 7,810 on
// dishnet; 1,750 − 50 on 4g; −125 on BlueCARD. USD: 50 − 340 on dishnet; −30 on 4g).
$cih = function (string $cur, string $proj = '') use ($svc): float { return $svc()->cashInHand($cur, $proj); };
$HAND = ['UGX|dishnet' => 7810.0, 'UGX|4g' => 1700.0, 'UGX|bluecard' => -125.0, 'UGX|' => 9385.0,
         'USD|dishnet' => -290.0, 'USD|4g' => -30.0, 'USD|bluecard' => 0.0, 'USD|' => -320.0];
is_($svc()->cashOnlyRuleInForce() === true, 'control: rule A is in force on this book (the oracle\'s own gate, the sandbox\'s configuration)');
foreach ($HAND as $k => $v) {
    [$c, $p] = explode('|', $k);
    is_($same($cih($c, $p), $v), "the oracle: cashInHand({$c}" . ($p !== '' ? ", {$p}" : '') . ") = {$f2($v)}, as worked out by hand", $f2($cih($c, $p)));
}

// ── The readers ────────────────────────────────────────────────────────────
$rowsById = function () use ($rows): array { $o = []; foreach ($rows() as $r) $o[(int)$r['id']] = $r; return $o; };
$streamOf = function (array $r, string $view): string {                     // the stream a row runs in (= _bal_currency)
    if ($view !== '') return $view;
    $c = strtoupper(trim((string)$r['currency']));
    return ($c === '' || $c === 'UGX') ? 'UGX' : $c;
};
$cbPage = function (string $proj, string $view, string $cbView = 'ledger') use ($s): string {
    return $s->page('boss', 'page=dashboard&tab=cashbook&cb_proj=' . $proj . '&cb_view=' . $cbView . ($view !== '' ? '&cb_curr=' . $view : ''));
};
$heroes = function (string $html) use ($money): array {
    preg_match_all('/([A-Z]{3}) CASH IN HAND<\/div>\s*<div style="font-size:42px;[^"]*">([A-Z]{3}) (-?[0-9,]+\.[0-9]{2})<\/div>/', $html, $m, PREG_SET_ORDER);
    $o = []; foreach ($m as $x) $o[$x[1]] = $x[1] === $x[2] ? $money($x[3]) : null; return $o;
};
$balCells = function (string $html): array {                                // [[id, cell text]] in page order (desktop table)
    preg_match_all('/<tr [^>]*data-id="(\d+)"[^>]*>(.*?)<\/tr>/s', $html, $m, PREG_SET_ORDER);
    $o = [];
    foreach ($m as $x) {
        $cell = preg_match('/<td style="font-family:monospace;font-size:11px;">(.*?)<\/td>/s', $x[2], $c)
              ? trim(html_entity_decode(strip_tags($c[1]), ENT_QUOTES)) : '?';
        $o[] = [(int)$x[1], $cell];
    }
    return $o;
};
$csvRows = function (string $body): array {
    $lines = array_values(array_filter(preg_split('/\r?\n/', $body) ?: [], function ($l) { return trim($l) !== ''; }));
    if (!$lines) return [[], []];
    $hdr = str_getcsv(array_shift($lines), ',', '"', '\\');
    return [$hdr, array_map(function ($l) { return str_getcsv($l, ',', '"', '\\'); }, $lines)];
};

/** Every cash-in-hand figure every screen shows: key => [figure|null, currency, project ('' = all)]. Plus the pages. */
$read = function () use ($s, $PROJ, $CHIPS, $VIEWS, $cbPage, $heroes, $balCells, $csvRows, $money, $rowsById, $streamOf): array {
    $v = []; $pages = []; $byId = $rowsById();
    foreach ($PROJ as $p) {
        foreach ($VIEWS as $vw => $lbl) {
            $html = $cbPage($p, $vw);
            $pages["cashbook|{$p}|{$vw}"] = $html;
            if ($vw === '') {
                $h = $heroes($html);
                foreach (['UGX', 'USD'] as $c) $v["Cashbook hero, {$p}, {$c}"] = [$h[$c] ?? null, $c, $p];
                $pill = preg_match('/id="cb4PillBaseBal">(.*?)<\/div>/s', $html, $pm) ? trim(strip_tags($pm[1])) : '';
                $v["Add Entry base pill, {$p}"] = [preg_match('/^UGX -?[0-9,]+\.[0-9]{2}$/', $pill) ? $money($pill) : null, 'UGX', $p];
            }
            $top = [];
            foreach ($balCells($html) as [$id, $cell]) {
                if ($cell === '—' || !isset($byId[$id])) continue;
                $st = $streamOf($byId[$id], $vw);
                if (array_key_exists($st, $top)) continue;
                $top[$st] = (strpos($cell, $st . ' ') === 0) ? $money($cell) : null;    // the cell names its own stream
            }
            foreach ($vw === '' ? ['UGX', 'USD'] : [$vw] as $c) $v["Balance column top, {$p}, {$lbl} view, {$c}"] = [$top[$c] ?? 'none', $c, $p];
            // the CSV export of the same view
            [$hdr, $csv] = $csvRows($s->page('boss', 'page=dashboard&tab=cashbook&cb_export=csv&cb_proj=' . $p . ($vw !== '' ? '&cb_curr=' . $vw : '')));
            $bi = array_search($vw === '' ? 'Balance' : "{$vw} Balance", $hdr, true);
            $ci = $vw === '' ? array_search('Balance Currency', $hdr, true) : false;
            if ($vw === '' && $ci === false) $bi = false;                   // no stream column: nothing can be read
            $ctop = [];
            foreach ($csv as $r) {
                if ($bi === false || !isset($r[$bi]) || $r[$bi] === '') continue;
                $st = $vw === '' ? (string)($r[$ci] ?? '') : $vw;
                if (!array_key_exists($st, $ctop)) $ctop[$st] = is_numeric($r[$bi]) ? round((float)$r[$bi], 2) : null;
            }
            foreach ($vw === '' ? ['UGX', 'USD'] : [$vw] as $c) $v["CSV export, {$p}, {$lbl}, {$c}"] = [$bi === false ? null : ($ctop[$c] ?? 'none'), $c, $p];
        }
    }
    // the landing page (accounts dashboard)
    $html = $s->page('boss', 'page=dashboard&tab=accounts_dashboard');
    $pages['landing'] = $html;
    preg_match_all('/<div class="d2-bal-val"[^>]*>([A-Z]{3}) (-?[0-9,]+\.[0-9]{2})<\/div>/', $html, $m, PREG_SET_ORDER);
    $lh = []; foreach ($m as $x) $lh[$x[1]] = $money($x[2]);
    foreach (['UGX', 'USD'] as $c) $v["landing hero, {$c}"] = [$lh[$c] ?? null, $c, ''];
    foreach ($CHIPS as $p => $lbl) {
        $l = '(?:' . preg_quote(htmlspecialchars($lbl), '/') . '|' . preg_quote($lbl, '/') . ')';
        $v["landing chip, {$lbl}"] = [preg_match('/d2-bal-chip-lbl">' . $l . '<\/span><span class="d2-bal-chip-val">UGX (-?[0-9,]+\.[0-9]{2})<\/span>/', $html, $cm) ? $money($cm[1]) : null, 'UGX', $p];
    }
    $v['landing Office (Money Locations)'] = [preg_match('/Office \(Rupesh\)<\/div>\s*<div[^>]*>UGX (-?[0-9,]+\.[0-9]{2})<\/div>/', $html, $om) ? $money($om[1]) : null, 'UGX', 'office'];
    // the CEO dashboard
    $html = $s->page('boss', 'page=dashboard&tab=ceo_dashboard');
    $pages['ceo'] = $html;
    preg_match_all('/<div class="cd-k (cd-alert-red|cd-alert-green)">\s*<div class="cd-kv" style="(?:font-size:[^;]+;)?color:([^;]+);">([A-Z]{3}) (-?[0-9,]+\.[0-9]{2})<\/div>\s*<div class="cd-kl">([A-Z]{3}) Cash in hand<\/div>/', $html, $m, PREG_SET_ORDER);
    $ct = []; foreach ($m as $x) if ($x[3] === $x[5]) $ct[$x[3]] = $money($x[4]);
    foreach (['UGX', 'USD'] as $c) $v["CEO tile, {$c}"] = [$ct[$c] ?? null, $c, ''];
    // the evening WhatsApp, as master.php runs it at 18:00
    $n0 = count($s->texts());
    [$rc, $out] = $s->run('cron/cashbook_summary.php');
    $texts = $s->texts(); $wa = '';
    foreach ($texts as $t) if (strpos((string)($t['text'] ?? ''), 'DishNet Cashbook') !== false) $wa = (string)$t['text'];
    $pages['wa'] = count($texts) > $n0 ? $wa : '';
    foreach (['UGX', 'USD'] as $c) {
        $v["evening WhatsApp, {$c}"] = [count($texts) > $n0 && preg_match('/\*' . $c . ' CASH IN HAND: ' . $c . ' (-?[0-9,]+\.[0-9]{2})\*/', $wa, $wm) ? $money($wm[1]) : null, $c, ''];
    }
    // tools/cash_in_hand.php
    [$rc, $tool] = $s->run('tools/cash_in_hand.php');
    $pages['tool'] = $rc === 0 ? $tool : '';
    foreach (['UGX', 'USD'] as $c) {
        $v["tools/cash_in_hand.php, {$c}"] = [preg_match('/^    ' . $c . '\s+CASH IN HAND\s+(-?[0-9,]+\.[0-9]{2})$/m', $tool, $tm) ? $money($tm[1]) : null, $c, ''];
    }
    foreach ($CHIPS as $p => $lbl) {
        $v["tools/cash_in_hand.php, {$lbl}"] = [preg_match('/^    ' . preg_quote($lbl, '/') . '\s+UGX\s+(-?[0-9,]+\.[0-9]{2})$/m', $tool, $tm) ? $money($tm[1]) : null, 'UGX', $p];
    }
    // the API: cashbook_entries' latest running_balance per stream (keyed by _bal_currency), all projects
    foreach ($VIEWS as $vw => $lbl) {
        $j = $s->api('boss', 'GET', 'cashbook_entries', null, '&limit=500' . ($vw !== '' ? '&currency=' . $vw : ''))[2];
        if ($vw === '') $pages['api'] = $j;
        $at = [];
        foreach ((array)($j['data']['entries'] ?? []) as $r) {
            if (!array_key_exists('running_balance', $r) || $r['running_balance'] === null) continue;
            $st = (string)($r['_bal_currency'] ?? '');
            if (!array_key_exists($st, $at)) $at[$st] = round((float)$r['running_balance'], 2);
        }
        foreach ($vw === '' ? ['UGX', 'USD'] : [$vw] as $c) $v["API cashbook_entries, {$lbl}, {$c}"] = [isset($j['data']['entries']) ? ($at[$c] ?? 'none') : null, $c, ''];
    }
    return [$v, $pages];
};
/** The field exposure the landing page subtracts from base cash in hand for its Office figure. */
$expo = function () use ($s): float {
    $store = $s->store();
    $pos = new StaffCashPositionService($store, $store->getPdo());
    $rs = $store->load('retailers.json') ?? [];
    $tot = 0.0;
    foreach ($pos->getAllPositions() as $sid => $p) {                       // the landing page's own filters ($fieldPositions, $_cpByStaff)
        $r = null; foreach ($rs as $x) { if ((int)($x['id'] ?? 0) === (int)$sid) { $r = $x; break; } }
        if ($r && (!empty($r['is_admin']) || in_array($r['role'] ?? '', ['accountant', 'admin'], true))) continue;
        $x = (float)($p['cash_exposure'] ?? 0);
        if ($x > 0) $tot += $x;
    }
    return round($tot, 2);
};
/** What the oracle says each of them must read. 'none' (no Balance in that stream) is right only when the figure is 0. */
$want = function (array $meta) use ($cih, $expo): float {
    [, $c, $p] = $meta;
    if ($p === 'office') return round(max(0, $cih('UGX') - $expo()), 2);
    return $cih($c, $p);
};
$agrees = function ($got, float $exp) use ($same): bool { return $got === 'none' ? abs($exp) < 0.005 : $same($got, $exp); };

// ═══════════════════════════════════════════════════════════════════════════
echo "\nB. Every reader equals cashInHand() for its own scope, at 2 dp\n";
[$snap0, $pg0] = $read();
is_($expo() === $EXPO, "control: the field exposure the Office figure subtracts is UGX {$f2($EXPO)}, RH-Collector One's planted collection", $f2($expo()));
foreach ($snap0 as $k => $meta) {
    $exp = $want($meta);
    $scope = $meta[2] === 'office' ? 'max(0, cashInHand(UGX) − exposure)' : ('cashInHand(' . $meta[1] . ($meta[2] !== '' ? ", {$meta[2]}" : '') . ')');
    is_($agrees($meta[0], $exp), "{$k}: " . ($meta[0] === 'none' ? 'no Balance in this stream' : $f2($meta[0])) . " = {$scope} = {$f2($exp)}", 'read ' . json_encode($meta[0]));
}
// the Balance column follows the rule row by row: a value exactly where the row is inside the rule
$byId = $rowsById();
foreach ($PROJ as $p) {
    foreach ($VIEWS as $vw => $lbl) {
        $bad = []; $n = 0; $ids = [];
        foreach ($balCells($pg0["cashbook|{$p}|{$vw}"]) as [$id, $cell]) {
            $ids[] = $id;
            if (!isset($byId[$id])) { $bad[] = "#{$id}?"; continue; }
            $n++;
            if ($inRule($byId[$id]) !== ($cell !== '—')) $bad[] = "#{$id} " . ($inRule($byId[$id]) ? 'in' : 'out') . " '{$cell}'";
        }
        $listed = array_map(function ($r) { return (int)$r['id']; }, $svc()->getEntries(array_filter(['project' => $p, 'currency' => $vw, 'limit' => 50])));
        $nr = count($listed) . ' row' . (count($listed) === 1 ? '' : 's');
        is_($ids === $listed && $bad === [], "Balance column, {$p}, {$lbl} view: the {$nr} getEntries() lists, in its order, each with a Balance exactly when it is inside the rule (others \"—\")",
            implode('; ', $bad) . ' listed ' . json_encode($ids) . ' expected ' . json_encode($listed));
    }
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\nC. The CEO page: cash in hand, all projects, under its own heading\n";
$ceo = $pg0['ceo'];
is_(strpos($ceo, '<div class="cd-section">' . $CEO_HEAD . '</div>') !== false, "the heading reads \"{$CEO_HEAD}\"");
is_(strpos($ceo, '🏦 Cashbook — DishNet</div>') === false, 'the old heading "Cashbook — DishNet" is gone');
is_(preg_match_all('/<div class="cd-kl">[A-Z]{3} Cash in hand<\/div><div class="cd-ks">cash &amp; mobile money · all projects<\/div>/', $ceo) === 2,
    'two tiles labelled "<CUR> Cash in hand", sub-line "cash & mobile money · all projects"');
is_(preg_match('/[A-Z]{3} Position<\/div>/', $ceo) === 0 && strpos($ceo, '+ unassigned') === false && preg_match('/<div class="cd-ks">\d+ accounts?<\/div>/', $ceo) === 0,
    'no "Position" tile, no "account(s) + unassigned" sub-line');
is_(preg_match('/<div class="cd-k cd-alert-red">\s*<div class="cd-kv" style="font-size:clamp\(16px,5\.5vw,22px\);color:#991b1b;">USD -320\.00<\/div>\s*<div class="cd-kl">USD Cash in hand/', $ceo) === 1,
    'USD below zero is drawn "USD -320.00", red');
is_(preg_match('/<div class="cd-k cd-alert-green">\s*<div class="cd-kv" style="font-size:clamp\(16px,5\.5vw,22px\);color:#15803d;">UGX 9,385\.00<\/div>/', $ceo) === 1, 'UGX above zero is drawn "UGX 9,385.00", green');
// A 2-dp figure at the shared 22px pushed the two-column grid past a 320px phone (".cd-g4" is repeat(2,1fr), whose
// columns grow to their longest word): the rule-A tiles scale their figure with the screen, and nothing else changes.
is_(preg_match_all('/<div class="cd-kv" style="font-size:clamp\(16px,5\.5vw,22px\);color:#(?:15803d|991b1b);">[A-Z]{3} -?[0-9,]+\.[0-9]{2}<\/div>\s*<div class="cd-kl">[A-Z]{3} Cash in hand<\/div>/', $ceo) === 2
    && substr_count($ceo, 'font-size:clamp(16px,5.5vw,22px)') === 2,
    'both cash-in-hand tiles size their figure clamp(16px, 5.5vw, 22px), so two 2-dp figures fit a 320px screen; no other element does');
is_(strpos($ceo, "Today's collections</div>") !== false && strpos($ceo, "Today's Cash</div>") === false, '"Today\'s Cash" reads "Today\'s collections"');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nD. The Cashbook: Alert 2 per currency, the pill in the base code, the wording and the count\n";
$alertsOf = function (string $proj) use ($cbPage): array {
    preg_match_all('/<div style="font-size:14px;font-weight:800;color:[^;]+;margin-bottom:4px;">(.*?)<\/div>/s', $cbPage($proj, '', 'alerts'), $m);
    return array_map(function ($t) { return html_entity_decode(strip_tags($t), ENT_QUOTES); }, $m[1]);
};
$al = ['dishnet' => $alertsOf('dishnet'), '4g' => $alertsOf('4g'), 'bluecard' => $alertsOf('bluecard')];
is_(in_array('USD cash in hand is below zero: USD -290.00', $al['dishnet'], true), 'dishnet: "USD cash in hand is below zero: USD -290.00"', json_encode($al['dishnet']));
is_(in_array('USD cash in hand is below zero: USD -30.00', $al['4g'], true), '4g: "USD cash in hand is below zero: USD -30.00"', json_encode($al['4g']));
is_(in_array('UGX cash in hand is below zero: UGX -125.00', $al['bluecard'], true), 'BlueCARD: "UGX cash in hand is below zero: UGX -125.00"', json_encode($al['bluecard']));
$neg = array_merge(...array_values(array_map(function ($a) { return array_values(array_filter($a, function ($t) { return strpos($t, 'below zero') !== false || strpos($t, 'NEGATIVE') !== false; })); }, $al)));
is_(count($neg) === 3 && preg_grep('/NEGATIVE/', $neg) === [],
    'no other below-zero alert: none for a currency above zero, no "Cash balance is NEGATIVE"', json_encode($neg));
$cbd = $pg0['cashbook|dishnet|'];
is_(preg_match('/id="cb4PillBaseBal">UGX 7,810\.00<\/div>/', $cbd) === 1, 'the Add Entry base pill reads "UGX 7,810.00" — the base code and its cash in hand, not the dollar balance');
foreach ($PROJ as $p) {
    $html = $pg0["cashbook|{$p}|"];
    $n = $svc()->countEntries($p);
    $label = number_format($n) . ' entr' . ($n === 1 ? 'y' : 'ies') . ' in the book';
    is_(strpos($html, $HERO_SUB . ' &nbsp;·&nbsp; ' . $label) !== false && substr_count($html, $HERO_SUB) === 2,
        "{$p}: the hero names the rule under both cards, and the count reads \"{$label}\"", 'count ' . $n);
    is_(strpos($html, "the ledger's running balance") === false, "{$p}: the old sub-line \"the ledger's running balance\" is gone");
}
is_($svc()->countEntries('bluecard') === 1, 'control: BlueCARD holds exactly one row, so its count is the singular "1 entry in the book"');
is_(strpos($pg0['landing'], '<div class="d2-bal-lbl">Cash in hand — per currency</div>') !== false && strpos($pg0['landing'], $HERO_SUB) !== false,
    'the landing hero keeps its label and gains the definition line');
is_($pg0['wa'] !== '' && strpos($pg0['wa'], "\n" . $WA_LINE . "\n") !== false, "the evening WhatsApp says what it counts: \"{$WA_LINE}\"", $pg0['wa']);
$tool = $pg0['tool'];
is_(preg_match('/^  rule       (.*)$/m', $tool, $tr) === 1 && $tr[1] === $RULE_LINE, "the tool's rule line reads \"{$RULE_LINE}\"", $tr[1] ?? $tool);
is_(strpos($tool, "\n" . $RULE_SENT . "\n") !== false && strpos($tool, $OLD_SENT) === false, "the tool's sentence is the rule-A one, and the old one is gone", $tool);
is_(strpos($tool, '== cash in hand (5.18.96) — read-only ==') !== false && strpos($tool, 'READ-ONLY — nothing was changed.') !== false, 'the tool\'s banner is (5.18.96) and its READ-ONLY line is kept');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nE. Alert 6, as recorded (Q7)\n";
$no7 = function (array $titles): bool { return in_array('No cash received in last 7 days', $titles, true); };
is_($no7($al['dishnet']), 'dishnet: UGX cash in hand above zero, a pending disbursement, no receipt in seven days — the alert is shown', json_encode($al['dishnet']));
is_($no7($al['4g']), '4g: the same, while its dollar balance is below zero — the first test reads base cash in hand, not $projBal', json_encode($al['4g']));
$bcPend = count($svc()->getPendingDisbursements('bluecard'));
$bcIn7  = (float)($svc()->query("SELECT COALESCE(SUM(amount),0) AS t FROM cb_ledger WHERE project='bluecard' AND direction='in' AND status='approved' AND date>=?",
                   [date('Y-m-d', strtotime('-7 days'))])[0]['t'] ?? 0);
is_($bcPend > 0 && $bcIn7 == 0.0 && $cih('UGX', 'bluecard') < 0,
    "control: on BlueCARD Alert 6's other two tests hold ({$bcPend} pending disbursement, no approved receipt in seven days) and UGX cash in hand is below zero, so only the first test can hide it",
    'pending ' . $bcPend . ', receipts ' . $f2($bcIn7) . ', cash in hand ' . $f2($cih('UGX', 'bluecard')));
is_(!$no7($al['bluecard']), 'BlueCARD: cash in hand below zero — not shown');
$raw(['project' => 'dishnet', 'date' => date('Y-m-d'), 'direction' => 'in', 'amount' => 4321, 'currency' => 'UGX', 'category' => 'Receipt',
      'description' => 'RH-Client Four bank receipt (uCRM sync)', 'account_id' => $bankU, 'source' => 'crm_api_sync']);
$al6 = ['dishnet' => $alertsOf('dishnet'), '4g' => $alertsOf('4g')];
is_(!$no7($al6['dishnet']), 'dishnet: one approved receipt on the Ecobank account inside the window — the alert is not shown (it counts receipts on any account)', json_encode($al6['dishnet']));
is_($no7($al6['4g']), 'control: 4g, with no such receipt, still shows it', json_encode($al6['4g']));

// ═══════════════════════════════════════════════════════════════════════════
echo "\nF. Movement\n";
$diff = function (array $a, array $b, callable $delta) use ($same): array {   // what moved, against what should have
    $num = function ($x) { return $x === 'none' ? 0.0 : $x; };
    $bad = [];
    foreach ($a as $k => $m) {
        $x = $num($m[0]); $y = $num($b[$k][0] ?? null); $d = $delta($m);
        if ($x === null || $y === null || !$same((float)$y - (float)$x, $d)) {
            $bad[] = "{$k}: " . json_encode($m[0]) . ' → ' . json_encode($b[$k][0] ?? null) . ' (expected ' . ($d >= 0 ? '+' : '') . number_format($d, 2) . ')';
        }
    }
    return $bad;
};
$posTot = function (array $api, string $c): ?float { $t = $api['data']['balances']['positions'][$c]['total'] ?? null; return $t === null ? null : round((float)$t, 2); };
[$snap1, $pg1] = $read();
$bad = $diff($snap0, $snap1, function () { return 0.0; });
is_(count($snap1) === count($snap0) && $bad === [], 'a receipt on the Ecobank account moves no cash-in-hand screen: ' . count($snap1) . ' figures, every one unchanged', implode('; ', $bad));
is_($same($posTot($pg1['api'], 'UGX'), (float)$posTot($pg0['api'], 'UGX') + 4321), 'control: the row is real — the account position the API still reports (bank included) moved by +4,321.00',
    $f2($posTot($pg0['api'], 'UGX')) . ' → ' . $f2($posTot($pg1['api'], 'UGX')));

$pend = $svc()->addEntry(['project' => 'dishnet', 'date' => date('Y-m-d'), 'direction' => 'in', 'amount' => 2468, 'currency' => 'UGX', 'category' => 'Receipt',
                       'description' => 'RH-Client Five payment, awaiting approval', 'validation_status' => 'na'], ['name' => $admin], false);
$pid = (int)($pend['id'] ?? 0);
is_($pid > 0 && ($svc()->getEntryById($pid)['status'] ?? '') === 'pending_approval', 'a UGX 2,468 receipt is entered awaiting approval (no account)');
[$snap2, $pg2] = $read();
$bad = $diff($snap1, $snap2, function () { return 0.0; });
is_($bad === [], 'while it awaits approval it moves nothing: ' . count($snap2) . ' figures unchanged', implode('; ', $bad));
$svc()->approveEntry($pid, ['name' => $admin]);
[$snap3, $pg3] = $read();
$bad = $diff($snap2, $snap3, function (array $m) { return ($m[1] === 'UGX' && in_array($m[2], ['', 'dishnet', 'office'], true)) ? 2468.0 : 0.0; });
is_($bad === [], 'once approved it moves every screen whose scope holds it (UGX; dishnet or all projects; the Office) by exactly +2,468.00, and no other by anything', implode('; ', $bad));
$bad = [];
foreach ($snap3 as $k => $m) if (!$agrees($m[0], $want($m))) $bad[] = "{$k}: " . json_encode($m[0]) . ' ≠ ' . $f2($want($m));
is_($bad === [], 'and every reader still equals cashInHand() for its scope afterwards (' . count($snap3) . ' figures)', implode('; ', $bad));
is_($same($cih('UGX', 'dishnet'), 7810 + 2468) && $same($cih('UGX'), 9385 + 2468), 'control: the oracle moved by the same +2,468.00 (dishnet and all projects)');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nG. Control: currencyPositions() — the account position, bank included — is unchanged on the same book\n";
// UGX: Ecobank 70,000 + 9,000 − 3,000 − 400 − 222 + 4,321 = 79,699 · cash 5,000 + 600 + 400 − 150 = 5,850 · MTN
// 1,200 + 150 = 1,350 · approved rows with no account 70 + 800 − 260 + 1,750 − 50 − 125 + 2,468 = 4,653.
// USD: Ecobank 2,000 + 1,000 · cash 50 · no account −340 − 30.
$pos = $svc()->currencyPositions();
$PH = ['UGX' => ['accounts_total' => 86899.0, 'unassigned' => 4653.0, 'total' => 91552.0], 'USD' => ['accounts_total' => 3050.0, 'unassigned' => -370.0, 'total' => 2680.0]];
foreach ($PH as $c => $want3) {
    $got = ['accounts_total' => round((float)($pos[$c]['accounts_total'] ?? NAN), 2), 'unassigned' => round((float)($pos[$c]['unassigned'] ?? NAN), 2), 'total' => round((float)($pos[$c]['total'] ?? NAN), 2)];
    is_($got === $want3, "{$c}: accounts {$f2($want3['accounts_total'])} + unassigned {$f2($want3['unassigned'])} = {$f2($want3['total'])}, as before P3b", json_encode($got));
    is_($same($posTot($pg3['api'], $c), $want3['total']), "{$c}: the API's positions (the served tree's currencyPositions()) agree", $f2($posTot($pg3['api'], $c)));
}
is_(!$same($pos['UGX']['total'] ?? null, $cih('UGX')) && !$same($pos['USD']['total'] ?? null, $cih('USD')), 'and they are not cash in hand: the book discriminates between the two');
// The fake Evolution keeps its state in the system's temporary folder, named after its own path and port: this
// sandbox's file is found (so the name is right) and removed, and no other run's is touched.
$evoState = sys_get_temp_dir() . '/fake_evo_state_' . md5((string)realpath($root . '/tests/fixtures/fake_evo_server.php')
          . (string)parse_url($s->evo, PHP_URL_PORT)) . '.json';
$s->stop();
putenv('DN_VAULT_FILE=' . $ownVault);
$hadState = is_file($evoState);
@unlink($evoState);
is_($hadState && !is_file($evoState), "this run's fake Evolution state is found and removed (" . basename($evoState) . ')');

// ═══════════════════════════════════════════════════════════════════════════
if ($withMutants) {
    echo "\nH. Weakened copies of the code must each fail this test; the unchanged copy and nothing else passes\n";
    $child = function (string $tree): array {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tree) . ' --no-mutants 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        $tally = preg_match('/^(\d+) passed, (\d+) failed$/', (string)end($out), $tm) ? [(int)$tm[1], (int)$tm[2]] : null;
        return [$rc, $fails, $tally];
    };
    $CB = 'lib/CashbookService.php'; $CEO = 'tabs/admin/ceo_dashboard.php'; $PAGE = 'tabs/accounts/cashbook.php';
    $MUTANTS = [
        ['Alert 6 under rule A ignores cash in hand (its first test always true)', [[$PAGE,
            "if ((\$_cbRuleA ? (\$_cbCih[\$_cbBase] ?? 0) : \$projBal) > 0 && \$_recentInAmt == 0 && \$pendingCount > 0) {",
            "if ((\$_cbRuleA ? 1 : \$projBal) > 0 && \$_recentInAmt == 0 && \$pendingCount > 0) {"]]],
        ['P3B-PAGES-1: the rule-A CEO tile at the shared 22px again', [[$CEO,
            "<div class=\"cd-kv\" style=\"font-size:clamp(16px,5.5vw,22px);color:<?= \$_ceoCihAmt>=0",
            "<div class=\"cd-kv\" style=\"color:<?= \$_ceoCihAmt>=0"]]],
        ['M6 (CEO tile): MONEY_KINDS, bank included', [[$CB,
            "\$kinds = implode(',', array_map([\$this->pdo(), 'quote'], self::CASH_IN_HAND_KINDS));",
            "\$kinds = implode(',', array_map([\$this->pdo(), 'quote'], self::MONEY_KINDS));"]]],
        ['M12: the gate forced false', [[$CB,
            "\$this->cashRuleCache = StaffJobsGate::applies(\$cfg, \$this->dataDir) && !dn_ssp_selectable(\$cfg);",
            "\$this->cashRuleCache = false;"]]],
        ['M13: the base stream keeps the old predicate (both forms)', [
            [$CB, "WHERE project=? AND status NOT IN ('voided','voided_reconcile')\"\n                . \$cashWhere\n", "WHERE project=? AND status NOT IN ('voided','voided_reconcile')\"\n"],
            [$CB, "                 WHERE status NOT IN ('voided','voided_reconcile')\"\n                . \$cashWhere\n", "                 WHERE status NOT IN ('voided','voided_reconcile')\"\n"]]],
        ['M13a: the base stream keeps the old predicate, project form only', [
            [$CB, "WHERE project=? AND status NOT IN ('voided','voided_reconcile')\"\n                . \$cashWhere\n", "WHERE project=? AND status NOT IN ('voided','voided_reconcile')\"\n"]]],
        ['M13b: the base stream keeps the old predicate, all-projects form only', [
            [$CB, "                 WHERE status NOT IN ('voided','voided_reconcile')\"\n                . \$cashWhere\n", "                 WHERE status NOT IN ('voided','voided_reconcile')\"\n"]]],
        ['M18a: the CEO rule-A branch takes its figures from currencyPositions()', [[$CEO,
            "foreach (dn_book_currencies(\$config ?? null) as \$_ceoCihCur) \$_ceoCih[\$_ceoCihCur] = \$_ceoCb->cashInHand(\$_ceoCihCur);",
            "foreach (\$_ceoCb->currencyPositions() as \$_ceoCihCur => \$_ceoP) \$_ceoCih[\$_ceoCihCur] = (float)\$_ceoP['total'];"]]],
        ['M18b: the CEO page draws positions under rule A', [
            [$CEO, "        } else {\n            \$_ceoPositions = \$_ceoCb->currencyPositions();\n        }", "        }\n            \$_ceoPositions = \$_ceoCb->currencyPositions();"],
            [$CEO, "<?php if (\$_ceoRuleA): /* 5.18.96 (P3b, X5):", "<?php if (false): /* 5.18.96 (P3b, X5):"]]],
        ['M19a: the CEO label "Position" is back', [[$CEO,
            "<div class=\"cd-kl\"><?= htmlspecialchars(\$_ceoCihCur) ?> Cash in hand</div>", "<div class=\"cd-kl\"><?= htmlspecialchars(\$_ceoCihCur) ?> Position</div>"]]],
        ['M19b: the CEO heading reverted to "Cashbook — DishNet" under rule A', [[$CEO,
            "<div class=\"cd-section\">{$CEO_HEAD}</div>", "<div class=\"cd-section\">🏦 Cashbook — DishNet</div>"]]],
        ['M20: the CEO scope \'dishnet\'', [[$CEO,
            "\$_ceoCih[\$_ceoCihCur] = \$_ceoCb->cashInHand(\$_ceoCihCur);", "\$_ceoCih[\$_ceoCihCur] = \$_ceoCb->cashInHand(\$_ceoCihCur, 'dishnet');"]]],
        ['M21a: Alert 2 reads $projBal on Uganda', [[$PAGE,
            "if (\$_cbRuleA) {\n    foreach (\$_cbCih as \$_cbCihCur => \$_cbCihBal) {", "if (false) {\n    foreach (\$_cbCih as \$_cbCihCur => \$_cbCihBal) {"]]],
        ['M21b: the Add Entry pill reads $projBal on Uganda', [[$PAGE,
            "<?php if (\$_cbRuleA): ?><?= htmlspecialchars(\$_cbBase) ?> <?php echo number_format(\$_cbCih[\$_cbBase] ?? 0, 2); ?>",
            "<?php if (false): ?><?= htmlspecialchars(\$_cbBase) ?> <?php echo number_format(\$_cbCih[\$_cbBase] ?? 0, 2); ?>"]]],
        ['M22a (Uganda book half): the tool\'s rule line removed', [['tools/cash_in_hand.php',
            "echo \"  rule       \" . (\$ruleA ? 'approved entries with no account or on cash and mobile-money accounts (5.18.96)'\n                         : 'every entry not voided, any account (as before 5.18.96)') . \"\\n\";\n", '']]],
        ['M22b (Uganda book half): the tool always prints the old wording', [['tools/cash_in_hand.php',
            "\$ruleA   = \$cb->cashOnlyRuleInForce();", "\$ruleA   = false;"]]],
        ['M23: the evening WhatsApp computes its own figure (getBalanceByCurrency)', [['cron/cashbook_summary.php',
            "number_format(\$cb->cashInHand(\$cihCur), 2)",
            "number_format(array_sum(array_map(function (\$p) use (\$cb, \$cihCur) { return \$cb->getBalanceByCurrency(\$p, \$cihCur); }, ['dishnet', '4g', 'bluecard'])), 2)"]]],
    ];
    // The unchanged copy first, made the same way (one asserted edit that changes nothing). It must pass with this
    // run's own tally: sections A–G, plus the vault check every run ends with.
    $mainPass = $pass + 1;
    [$tmp, $n] = sj_weakened_copy($lib, $CB, 'public const CASH_IN_HAND_KINDS', 'public const CASH_IN_HAND_KINDS');
    if ($n === 1) {
        [$rc, $fails, $tally] = $child($tmp);
        is_($rc === 0 && $fails === [] && $tally === [$mainPass, 0],
            "the unchanged copy passes: {$mainPass} passed, 0 failed, as this run", 'exit ' . $rc . ' tally ' . json_encode($tally) . ' ' . ($fails[0] ?? ''));
    } else {
        is_(false, 'the unchanged copy: its anchor occurs once', "found {$n} times");
    }
    exec('rm -rf ' . escapeshellarg($tmp));
    foreach ($MUTANTS as [$label, $edits]) {
        [$rel0, $old0, $new0] = $edits[0];
        [$tmp, $n] = sj_weakened_copy($lib, $rel0, $old0, $new0);
        $counts = [$n];
        foreach (array_slice($edits, 1) as [$rel, $old, $new]) {           // further edits, each asserted the same way
            $src = (string)@file_get_contents($tmp . '/' . $rel);
            $c = substr_count($src, $old);
            if ($c === 1) file_put_contents($tmp . '/' . $rel, str_replace($old, $new, $src));
            $counts[] = $c;
        }
        $applied = true;
        foreach ($edits as [$rel, $old, $new]) {                           // the copy holds every intended change
            $src = (string)@file_get_contents($tmp . '/' . $rel);
            if ($new !== '' && strpos($src, $new) === false) $applied = false;
        }
        if ($counts !== array_fill(0, count($edits), 1) || !$applied) {
            is_(false, "weakened copy \"{$label}\": each anchor occurs once and the copy holds the change", 'counts ' . json_encode($counts));
            exec('rm -rf ' . escapeshellarg($tmp));
            continue;
        }
        [$rc, $fails, $tally] = $child($tmp);
        is_($rc !== 0 && $fails !== [], "caught: {$label}", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        echo '         ' . count($fails) . ' assertion(s) failed' . ($tally ? " ({$tally[0]} passed)" : '') . ($fails ? '; first: ' . trim(substr($fails[0], 7, 110)) : '') . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
    // the control on the controls: f4b4c96's own code (5.18.94, before P3b) must fail this test
    require_once $lib . '/tests/fixtures/staff_jobs_scenario.php';
    [$base, $why] = sj_baseline_tree('f4b4c96');
    if ($base === null) {
        echo "  SKIP f4b4c96's code — {$why} (a skip, not a pass)\n";
    } else {
        [$rc, $fails, $tally] = $child($base);
        is_($rc !== 0 && $fails !== [], "control: f4b4c96's code (5.18.94) fails this test", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        echo '         ' . count($fails) . ' assertion(s) failed' . ($tally ? " ({$tally[0]} passed)" : '') . ($fails ? '; first: ' . trim(substr($fails[0], 7, 110)) : '') . "\n";
    }
}

// This run wrote no vault but its own and the sandbox's.
$defAfter = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;
is_($defAfter === $defBefore, 'the default vault beside this checkout is untouched (' . ($defBefore === null ? 'still absent' : 'same bytes and mtime') . ')');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

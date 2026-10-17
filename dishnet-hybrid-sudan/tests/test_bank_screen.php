<?php
declare(strict_types=1);
/**
 * test_bank_screen.php — T-B3 of the Bank screen v1 (5.18.97; SPEC-bank §f.2): tabs/accounts/bank.php, its POST handler
 * includes/post/post_bank.php and its export route (includes/routes.php), driven over real HTTP on a sandboxed plugin
 * (tests/fixtures/staff_jobs_sandbox.php: a copy of the plugin served on 127.0.0.1, a fake uCRM, a fake Evolution).
 *
 * Every statement here is GENERATED (tests/fixtures/bank_statement_gen.php) and invented: account 0000000001, holder
 * "RH-Account Holder One", counterparties "RH-CLIENT n", bank line numbers 0000000101…; every staff member and account
 * name is invented too. A real statement is never a fixture. The oracle is always THIS checkout's code (the fixed
 * sentences, the generator), so a weakened tree served by the sandbox is judged against the rule, never against itself.
 *
 *   A  a Uganda book with three Ecobank accounts (two UGX, one USD), a cash account and an inactive bank account; an
 *      admin, a second admin (demoted later), an accountant and a sales user; the pages "posts nothing" compares later
 *   B  the first visit: one chip per offered account, no All chip, a long number in an account name masked, the card
 *      empty, upload offered; the book's own figure for the account nowhere
 *   C  upload → check → link-and-keep: the panel from the stored check, the link question, Keep refused without the
 *      link (link_required), then kept; the held file gone, the kept file 0600 outside uploads/
 *   D  the lines: newest first by bank line number; each Balance the stored running balance; the top row's Balance the
 *      card; the phone card's "Bal …" and the whole Remarks; the value date where it differs; "Not compared"
 *   E  an overlapping, shuffled, re-padded second file adds only its new lines; the pager; the shuffled file in bank order
 *   F  the month filter and its strip (opening, in, out, closing, adds up), against the stored lines
 *   G  the export: the screen's lines over all pages, oldest first; headers; the file name; the lines_exported event;
 *      a request another site started (an <img>, a navigation, a sibling host, a foreign Origin or Referer) refused
 *      before the event and before any line, with the screen's own requests as the controls (BK-SEC-1)
 *   H  the USD account: a ranged first file, gap_before, a front join; escaping (<script>, "><img>, =HYPERLINK, @SUM);
 *      formula neutralising in the export, a negative balance unprefixed; a month whose lines are not consecutive;
 *      one account per view (no figure equals UGX + USD)
 *   I  every refusal and note the screen can reach, each panel sentence equal to this checkout's sentence for the
 *      stored code and parameter; the marker, the account and the holder never in the page or the flash; the keep-level
 *      codes (changed_since_check, already_kept on a second keep, check_expired); an expired check shown without a write;
 *      the Statements tab and its coverage line; a missing kept file noted
 *   J  CSRF: no token (the global gate bypassed by cb_action, and not), a cross-site POST, a foreign Origin: each
 *      refused, nothing written; the same POST from our own page is the positive control; discard. A valid token is
 *      not enough (BK-SEC-2): a POST that shows nothing, an Origin of null, a sibling host, a contradiction, or the
 *      login page's token alone, each refused; an older browser's own Origin or Referer accepted (the controls)
 *   K  the gating matrix: an accountant, a sales user, an admin demoted inside the session window, the switch off, a
 *      Uganda book with SSP — the tab, the POST and the export each refuse and write nothing; a visitor who is not
 *      signed in is sent to the login page by the export and the POST, the switch on or off (BK-SEC-4)
 *   L  the emergency repair page refuses drop_table for each bank_* table; an unlisted table still drops (the control)
 *   M  nothing from a statement in any flash, log, URL, session, data file or non-line table, after a marker file is
 *      refused at every rule and another is kept
 *   N  posts nothing: cb_ledger, cb_accounts and fin_audit byte for byte, cashInHand() per currency and project, every
 *      non-bank object in sqlite_master; the Cashbook (All, UGX, USD), its CSV, the landing and CEO pages identical
 *   O  088 not applied: "not ready", no upload, the POST refused
 *   P  South Sudan: no screen, the POST and the export refused, the five tables empty; a South Sudan book without SSP;
 *      the pages equal 5.18.96's (419e2ffd) once the per-run values and each tree's version label are normalised
 *                                                                                        (--no-compare skips)
 *   Q  weakened copies of the code each fail this test; the unchanged copy passes            (--no-mutants skips)
 *
 *   php test_bank_screen.php [--root=DIR] [--base=DIR] [--no-compare] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'base:', 'no-compare', 'no-mutants']);
$lib  = dirname(__DIR__);                                                   // the oracle: always this checkout's
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : $lib;     // the tree the sandbox serves
$withMutants = !isset($opt['no-mutants']);
$withCompare = !isset($opt['no-compare']);
date_default_timezone_set('Africa/Kampala');

$own = rtrim(sys_get_temp_dir(), '/') . '/bk-tb3-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($own, 0700, true);
putenv('DN_VAULT_FILE=' . $own . '/vault.json');
register_shutdown_function(function () use ($own) { register_shutdown_function(function () use ($own) { exec('rm -rf ' . escapeshellarg($own)); }); });
ini_set('log_errors', '1');
ini_set('error_log', $own . '/php-error.log');
ini_set('display_errors', 'stderr');                                      // a fatal is printed, never lost with the log
$defVault  = dirname($lib) . '/.dishnet-sudan.vault.json';
$defBefore = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;

require_once $lib . '/tests/fixtures/staff_jobs_sandbox.php';
require_once $lib . '/tests/fixtures/staff_jobs_scenario.php';
require_once $lib . '/tests/fixtures/bank_statement_gen.php';
require_once $lib . '/lib/StoreInterface.php';
require_once $lib . '/lib/SqliteStore.php';
require_once $lib . '/lib/currency.php';
require_once $lib . '/lib/CashbookService.php';
require_once $lib . '/lib/EcobankDetailsCsv.php';

$pass = 0; $fail = 0; $skip = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . substr($d, 0, 700) : '') . "\n"; }
}
function t(string $m, $got, $want): void {
    is_($got === $want, $m, 'got  ' . substr((string)json_encode($got, JSON_UNESCAPED_UNICODE), 0, 340) . "\n       want " . substr((string)json_encode($want, JSON_UNESCAPED_UNICODE), 0, 340));
}

const BK_MARK = 'RHMARKER';
const BK_FNAME = 'RHMARKER-0000000001-statement.csv';            // the upload's own name: it must never be shown or kept
const BK_LEAKS = [BK_MARK, BK_GEN_ACCOUNT, BK_GEN_HOLDER];

/** One request: [code, body, location, headers (lower-case names)]. $file = [bytes, name] makes it multipart. */
function bk_req(SjSandbox $s, string $method, string $qs, ?string $who, $fields = null, array $headers = [], ?array $file = null): array
{
    $ch = curl_init($s->base . '?' . $qs);
    $hd = [];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hd) {
            $p = strpos($h, ':');
            if ($p !== false) $hd[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1));
            return strlen($h);
        }];
    if ($who !== null) { $o[CURLOPT_COOKIEJAR] = $s->jars[$who]; $o[CURLOPT_COOKIEFILE] = $s->jars[$who]; }
    $tmp = null;
    if ($file !== null) {
        $tmp = $s->sb . '/up_' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $file[0]);
        $f = (array)$fields;
        $f['bk_file'] = new \CURLFile($tmp, 'text/csv', $file[1]);
        $o[CURLOPT_POSTFIELDS] = $f;
    } elseif ($fields !== null) {
        $o[CURLOPT_POSTFIELDS] = is_string($fields) ? $fields : http_build_query($fields);
        if (!is_string($fields)) $o[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch, $o);
    $r = curl_exec($ch);
    $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($tmp !== null) @unlink($tmp);
    return [$r === false ? 0 : $c, (string)$r, $hd['location'] ?? '', $hd];
}
/** The Bank tab's own markup: from its wrapper to the end of its script. '' when the page holds no Bank screen. */
function bk_seg(string $html): string
{
    $i = strpos($html, '<div class="cb3-wrap bk-wrap">');
    if ($i === false) return '';
    $j = strpos($html, '</script>', $i);
    return substr($html, $i, ($j === false ? strlen($html) : $j + 9) - $i);
}
/** The flash a page shows: "type: text", or ''. */
function bk_flash_of(string $html): string
{
    if (!preg_match('#<div class="kyc-alert ([a-z]+)">(.*?)</div>#s', $html, $m)) return '';
    $t = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $t = (string)preg_replace('/^(\x{2705}|\x{274C}|\x{2139}\x{FE0F}|\x{26A0}\x{FE0F})\s*/u', '', $t);
    return $m[1] . ': ' . $t;
}
/** The money the screen must show for minor units: the account's code, then number_format(units / 100, 2). */
function bk_money(string $cur, int $u): string { return $cur . ' ' . number_format($u / 100, 2); }
/** The export's plain amount for minor units. */
function bk_plain(int $u): string { return number_format($u / 100, 2, '.', ''); }
function bk_csv(string $body): array
{
    $fh = fopen('php://temp', 'w+'); fwrite($fh, $body); rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) { if ($r === [null]) continue; $rows[] = $r; }
    fclose($fh);
    return $rows;
}
function bk_pdo(SjSandbox $s): \PDO
{
    $p = new \PDO('sqlite:' . $s->data . '/plugin.sqlite3');
    $p->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    $p->exec('PRAGMA busy_timeout = 5000');
    return $p;
}
function bk_n(SjSandbox $s, string $sql, array $p = []): int { return (int)array_values($s->q($sql, $p)[0] ?? [0])[0]; }
function bk_counts(SjSandbox $s): array
{
    $o = [];
    foreach (['bank_account_profiles', 'bank_checks', 'bank_statements', 'bank_statement_lines', 'bank_line_events'] as $t) {
        $o[$t] = bk_n($s, "SELECT COUNT(*) AS n FROM {$t}");
    }
    return $o;
}
function bk_digest(\PDO $pdo, string $table): string
{
    $n = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table))->fetchColumn();
    if ($n === 0) return 'absent';
    $rows = $pdo->query("SELECT * FROM {$table} ORDER BY rowid")->fetchAll(\PDO::FETCH_ASSOC);
    return count($rows) . ':' . hash('sha256', (string)json_encode($rows));
}
function bk_master(\PDO $pdo): string
{
    $rows = array_values(array_filter($pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')->fetchAll(\PDO::FETCH_ASSOC),
        function ($r) { return strncmp((string)$r['tbl_name'], 'bank_', 5) !== 0; }));
    return count($rows) . ':' . hash('sha256', (string)json_encode($rows));
}
/** The values that differ between two reads of the same page — and nothing else. */
function bk_norm(string $x, SjSandbox $s, array $stamps): string
{
    if (($p = strpos($x, '</html>')) !== false) $x = substr($x, 0, $p + 7);
    static $ver = [];                                                       // each tree's own version label (419e2ffd: 5.18.96)
    if (!isset($ver[$s->root])) {
        $m = json_decode((string)@file_get_contents($s->root . '/manifest.json'), true);
        $ver[$s->root] = (string)($m['information']['version'] ?? '');
    }
    if ($ver[$s->root] !== '') $x = (string)preg_replace('/\bv' . preg_quote($ver[$s->root], '/') . '\b/', 'v<version>', $x);
    foreach ($s->tok as $k => $t) $x = str_replace($t, "<tok:{$k}>", $x);
    $x = str_replace([$s->data, $s->plug, $s->sb], ['<data>', '<plug>', '<sb>'], $x);
    foreach (['crm' => $s->crm, 'evo' => $s->evo, 'plugin' => (string)preg_replace('#/public\.php$#', '', $s->base)] as $k => $a) {
        $x = str_replace([$a, str_replace('/', '\/', $a)], "<{$k}>", $x);
    }
    $x = (string)preg_replace('/\b\d{8}:[0-9a-f]{40}\b/', '<day-token>', $x);
    $x = (string)preg_replace('/name="_csrf" value="[^"]*"/', 'name="_csrf" value="<csrf>"', $x);
    $x = (string)preg_replace('/\b\d+[smhd] ago\b/', '<ago>', $x);
    $x = str_replace($stamps, array_map(function ($k) { return "<{$k}>"; }, array_keys($stamps)), $x);
    return (string)preg_replace('/\b\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?\b/', '<clock>', $x);
}
/** One read, normalised with the stamps of the minute it was read in (read again if it straddles a minute). */
function bk_read_norm(SjSandbox $s, string $who, string $qs): string
{
    for ($try = 0; $try < 4; $try++) {
        $t0 = time();
        $stamps = ['long-date' => date('l, d M Y', $t0), 'date' => date('d M Y', $t0), 'time' => date('g:i A', $t0), 'iso' => date('Y-m-d', $t0)];
        $x = $s->page($who, $qs);
        if (date('Y-m-d H:i', time()) === date('Y-m-d H:i', $t0)) break;
    }
    return bk_norm($x, $s, $stamps);
}

// ═══════════════════════════════════════════════════════════════════════════
echo "A. A Uganda book with three Ecobank accounts; four staff; the pages to compare later\n";
$UG = ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'currency_symbol' => 'UGX', 'currency_code' => 'UGX',
       'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD', 'bank_screen_enabled' => '1'];
$s = SjSandbox::start($root, $UG, 'bkt3');
$GLOBALS['dataDir'] = $s->data;
$s->staff('boss', ['name' => 'RH-Admin One', 'email' => 'rh-admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '+256700009931']);
$s->staff('boss2', ['name' => 'RH-Admin Two', 'email' => 'rh-admin2@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '+256700009932']);
$s->staff('acc', ['name' => 'RH-Accountant One', 'email' => 'rh-acc@example.test', 'role' => 'accountant', 'phone' => '+256700009933']);
$s->staff('sal', ['name' => 'RH-Sales One', 'email' => 'rh-sal@example.test', 'role' => 'sales', 'phone' => '+256700009934']);
foreach (['boss' => 'rh-admin', 'boss2' => 'rh-admin2', 'acc' => 'rh-acc', 'sal' => 'rh-sal'] as $who => $em) $s->login($who, $em . '@example.test', 'sj-password-1');
$cb = new CashbookService($s->store(), $s->data);
$seed = $cb->seedStandardAccounts();
$spareName = 'RH-Spare Bank 0123456789 – UGX';
$cb->addAccount($spareName, 'UGX', 'bank');
$cb->addAccount('RH-Old Bank – UGX', 'UGX', 'bank');
$acc = [];
foreach ($cb->accounts() as $a) $acc[(string)$a['name']] = (int)$a['id'];
$U = $acc['Ecobank Uganda – UGX'] ?? 0; $D = $acc['Ecobank Uganda – USD'] ?? 0; $SP = $acc[$spareName] ?? 0;
$OLD = $acc['RH-Old Bank – UGX'] ?? 0; $CASH = $acc['Cash – Uganda'] ?? 0;
$cb->setAccountActive($OLD, false);
$admin = 'RH-Admin One';
$day = function (int $n): string { return date('Y-m-d', strtotime("-{$n} days")); };
$okB = [];
$okB[] = $cb->recordOpeningBalance($U, 123456, $day(40), 'RH opening', 'RH-OB', $admin)['ok'] ?? false;   // the BOOK's figure: never the card
$okB[] = $cb->recordOpeningBalance($CASH, 5000, $day(40), 'RH opening', 'RH-OB', $admin)['ok'] ?? false;
$cb->addEntry(['project' => 'dishnet', 'date' => $day(20), 'direction' => 'in', 'amount' => 800, 'currency' => 'UGX', 'category' => 'Receipt',
    'description' => 'RH-Client One payment', 'validation_status' => 'na'], ['name' => $admin]);
$cb->addEntry(['project' => 'dishnet', 'date' => $day(19), 'direction' => 'out', 'amount' => 30, 'currency' => 'USD', 'category' => 'Misc Expense',
    'description' => 'RH dollar spending', 'validation_status' => 'na'], ['name' => $admin]);
is_(!empty($seed['ok']) && $U && $D && $SP && $OLD && $CASH && !in_array(false, $okB, true),
    'the standard Uganda chart is seeded, plus a spare UGX bank account and an inactive one; the book holds entries', json_encode([$seed, $acc]));
$csrf = '';
if (preg_match('/name="_csrf" value="([^"]+)"/', $s->page('boss', 'page=dashboard&tab=retailers'), $m)) $csrf = html_entity_decode($m[1], ENT_QUOTES);
is_($csrf !== '', 'the day\'s form token is read from a page');

// The pages "posts nothing" compares, read now (twice: the control that they are stable at all).
$PAGES = ['the Cashbook (All)' => 'page=dashboard&tab=cashbook', 'the Cashbook (UGX)' => 'page=dashboard&tab=cashbook&cb_curr=UGX',
    'the Cashbook (USD)' => 'page=dashboard&tab=cashbook&cb_curr=USD', 'the Cashbook CSV' => 'page=dashboard&tab=cashbook&cb_export=csv',
    'the Cashbook CSV (UGX)' => 'page=dashboard&tab=cashbook&cb_export=csv&cb_curr=UGX',
    'the landing page' => 'page=dashboard&tab=accounts_dashboard', 'the CEO page' => 'page=dashboard'];
$before = [];
foreach ($PAGES as $lbl => $qs) { bk_read_norm($s, 'boss', $qs); $before[$lbl] = bk_read_norm($s, 'boss', $qs); }
$stable = 0;
foreach ($PAGES as $lbl => $qs) if (bk_read_norm($s, 'boss', $qs) === $before[$lbl]) $stable++;
t('control: each page compared later reads the same twice before any Bank act', $stable, count($PAGES));
$P0 = bk_pdo($s);
$dig = function () use ($P0, $s): array {
    $c = new CashbookService($s->store(), $s->data);
    $cash = [];
    foreach (['UGX', 'USD'] as $cur) foreach (['', 'dishnet', '4g', 'bluecard'] as $p) $cash[$cur . '|' . $p] = round($c->cashInHand($cur, $p), 2);
    return ['cb_ledger' => bk_digest($P0, 'cb_ledger'), 'cb_accounts' => bk_digest($P0, 'cb_accounts'), 'fin_audit' => bk_digest($P0, 'fin_audit'),
        'master' => bk_master($P0), 'cash' => $cash, 'rule' => $c->cashOnlyRuleInForce()];
};
$digBefore = $dig();
is_($digBefore['rule'] === true && strpos($digBefore['cb_ledger'], '0:') !== 0, 'rule A is in force on this book, and its ledger holds rows (an empty book could not show a change)',
    json_encode($digBefore));
$SEGS = []; $FLASHES = []; $LOCS = [];
$page = function (string $who, string $qs) use ($s, &$SEGS): string {
    $h = $s->page($who, $qs);
    $g = bk_seg($h);
    if ($g !== '') $SEGS[] = $g;
    return $h;
};
$follow = function (array $r, string $who) use ($page, &$FLASHES, &$LOCS): array {
    $LOCS[] = $r[2];
    $h = $r[2] !== '' ? $page($who, ltrim($r[2], '?')) : '';
    $f = bk_flash_of($h);
    if ($f !== '') $FLASHES[] = $f;
    return [$h, $f];
};
$cap = 0;
$post = function (string $who, array $fields, ?array $file = null, ?array $hdr = null) use ($s, &$csrf, &$cap, $follow): array {
    // A browser posting from the screen's own page says so (Sec-Fetch-Site, Origin): null sends those two. An array sends
    // exactly what it holds — [] sends none — for the cases that test what the handler does without them (BK-SEC-2).
    if ($hdr === null) $hdr = ['Sec-Fetch-Site: same-origin', 'Origin: ' . (string)preg_replace('#^(https?://[^/]+).*$#', '$1', $s->base)];
    $f = array_key_exists('_csrf', $fields) ? $fields : ['_csrf' => $csrf] + $fields;   // '_csrf' => null sends none
    if ($f['_csrf'] === null) unset($f['_csrf']);
    if ($file !== null) {                                                    // as the form sends it: MAX_FILE_SIZE, then the file
        if (!isset($f['bk_action'])) $f['bk_action'] = 'check';
        $f['MAX_FILE_SIZE'] = (string)$cap;
    }
    $r = bk_req($s, 'POST', 'page=dashboard&tab=bank', $who, $f, $hdr, $file);
    [$h, $fl] = $follow($r, $who);
    $id = preg_match('/[?&]bk_check=(\d+)/', $r[2], $m) ? (int)$m[1] : 0;
    return ['code' => $r[0], 'loc' => $r[2], 'check' => $id, 'html' => $h, 'flash' => $fl];
};
$panel = function (string $html): array {
    $o = ['tone' => '', 'check' => 0, 'state' => '', 'text' => '', 'lines' => '', 'link' => '', 'buttons' => []];
    if (preg_match('#<div class="bk-panel ([a-z])" id="bkPanel" data-bk-check="(\d+)" data-bk-state="([^"]*)">#', $html, $m)) {
        $o['tone'] = $m[1]; $o['check'] = (int)$m[2]; $o['state'] = html_entity_decode($m[3], ENT_QUOTES);
    }
    $dec = function (string $x): string { return trim(html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_HTML5, 'UTF-8')); };
    if (preg_match('#<div class="bk-panel-t" id="bkPanelText">(.*?)</div>#s', $html, $m)) $o['text'] = $dec($m[1]);
    if (preg_match('#<div class="bk-gv" id="bkPanelLines">(.*?)</div>#s', $html, $m)) $o['lines'] = $dec($m[1]);
    if (preg_match('#<div class="bk-link" id="bkLinkQ">(.*?)</div>#s', $html, $m)) $o['link'] = $dec($m[1]);
    if (preg_match_all('#<button type="submit" class="(?:bk-keep|cbv2-bcancel)">([^<]*)</button>#', $html, $m)) $o['buttons'] = $m[1];
    return $o;
};

// ═══════════════════════════════════════════════════════════════════════════
echo "\nB. The first visit\n";
$h0 = $page('boss', 'page=dashboard&tab=bank');
$g0 = bk_seg($h0);
is_($g0 !== '', 'an admin on Uganda with the switch on sees the Bank screen');
preg_match_all('#<a class="bk-chip( on)?" data-bk-acct="(\d+)" href="[^"]*">(.*?)</a>#s', $g0, $cm, PREG_SET_ORDER);
t('one chip per offered account (active, kind bank, a book currency), UGX before USD, then by id', array_map(function ($x) { return (int)$x[2]; }, $cm), [$U, $SP, $D]);
t('the first chip is the default account', array_map(function ($x) { return $x[1] === ' on'; }, $cm), [true, false, false]);
is_(strpos($g0, '>All<') === false && stripos(implode(' ', array_column($cm, 3)), 'all') === false, 'there is no All chip');
is_(strpos($g0, '0123456789') === false && strpos($g0, "RH-Spare Bank \u{2022}\u{2022}\u{2022}\u{2022}6789 – UGX") !== false,
    'a run of digits in an account name is shown as •••• and its last four, never whole');
is_(substr_count($g0, 'not linked') === 3, 'every account is marked not linked before its first file');
is_(strpos($g0, 'No Ecobank statement kept yet') !== false && strpos($g0, 'Upload the Account Details CSV') !== false, 'the card says nothing is kept yet and offers the upload');
is_(strpos($g0, '123,456') === false, 'the book\'s own figure for the account (an opening of 123,456.00) is nowhere on the screen');
is_(strpos($g0, 'AS STATED BY ECOBANK') !== false && strpos($g0, 'Not compared with the Cashbook yet') !== false, 'the card is the bank\'s, captioned so');
is_(preg_match('/data-bk-cap="(\d+)"/', $g0, $mm) === 1 && ($cap = (int)$mm[1]) > 0, 'the upload form states its byte cap (' . $cap . ')');
t('the cap is the reader\'s effective cap', $cap, min(EcobankDetailsCsv::BYTES_MAX, $cap));

// ═══════════════════════════════════════════════════════════════════════════
echo "\nC. Upload → check → link-and-keep\n";
$H = g_history(90, 11, 0, 101, [2026, 8, 18]);
$H[5]['Remarks'] = 'TRF FROM RH-CLIENT 3 INV-0006 ' . str_repeat('RH LONG PARTICULARS ', 4) . 'END';
$H[5]['Transaction Reference'] = $H[5]['Remarks'];
$file1 = g_file(array_slice($H, 0, 40));
$c1 = $post('boss', ['bk_acct' => (string)$U], [$file1, BK_FNAME]);
t('the check redirects to the screen with the account and the check id, and nothing else', [$c1['code'], (bool)preg_match('/^\?page=dashboard&tab=bank&bk_acct=' . $U . '&bk_check=\d+$/', $c1['loc'])], [302, true]);
t('the flash is fixed text', $c1['flash'], 'success: The file passed every check. Nothing is kept until you press Keep.');
$p1 = $panel($c1['html']);
t('the panel is drawn from the stored check: green, waiting, its counts', [$p1['tone'], $p1['state'], $p1['lines'], $p1['check']],
    ['g', 'waiting/passed', '40 in the file · 40 new · 0 already kept', $c1['check']]);
t('the link question names the last four and the currency from the file, and the book account',
    $p1['link'], "This file is for an account ending \u{2022}\u{2022}\u{2022}\u{2022}0001, in UGX. Link it to «Ecobank Uganda – UGX»?");
t('its buttons: Link and keep, Discard', $p1['buttons'], ['Link and keep', 'Discard']);
t('nothing is kept yet: a waiting check, no profile, no line, one held file', [bk_n($s, 'SELECT COUNT(*) n FROM bank_checks WHERE status = ?', ['waiting']),
    bk_n($s, 'SELECT COUNT(*) n FROM bank_account_profiles'), bk_n($s, 'SELECT COUNT(*) n FROM bank_statement_lines'),
    count(glob($s->data . '/bank_statements/.pending/*.csv') ?: [])], [1, 0, 0, 1]);
$k0 = $post('boss', ['bk_action' => 'keep', 'bk_acct' => (string)$U, 'bk_check' => (string)$c1['check']]);
t('Keep without the link confirmation is refused with its sentence, and the check still waits',
    [$k0['flash'], bk_n($s, 'SELECT COUNT(*) n FROM bank_statement_lines'), (string)$s->q('SELECT status FROM bank_checks WHERE id = ?', [$c1['check']])[0]['status']],
    ['danger: ' . EcobankDetailsCsv::sentence('link_required'), 0, 'waiting']);
$k1 = $post('boss', ['bk_action' => 'keep', 'bk_acct' => (string)$U, 'bk_check' => (string)$c1['check'], 'bk_link' => '1']);
t('Link and keep: kept, linked, with fixed text', $k1['flash'],
    'success: Kept: 40 new lines added, 0 already kept. Nothing was posted to the Cashbook. This book account is now linked to its Ecobank account.');
$prof = $s->q('SELECT * FROM bank_account_profiles WHERE cb_account_id = ?', [$U]);
t('the profile holds the last four and the currency from the file, confirmed by the admin', [(string)($prof[0]['acct_last4'] ?? ''), (string)($prof[0]['currency'] ?? ''),
    (int)($prof[0]['confirmed_by_id'] ?? 0)], ['0001', 'UGX', $s->ids['boss']]);
$st1 = $s->q('SELECT * FROM bank_statements WHERE check_id = ?', [$c1['check']])[0] ?? [];
$kf = $s->data . '/' . (string)($st1['file_rel'] ?? '');
clearstatcache();
t('the kept file is under bank_statements/<account>/, not uploads/, 0600, the sha256 the row records; the held file is gone',
    [strpos((string)($st1['file_rel'] ?? ''), 'bank_statements/' . $U . '/') === 0, strpos((string)($st1['file_rel'] ?? ''), 'uploads') === false,
     is_file($kf) ? sprintf('%04o', fileperms($kf) & 0777) : 'absent', is_file($kf) ? hash_file('sha256', $kf) : '', count(glob($s->data . '/bank_statements/.pending/*.csv') ?: [])],
    [true, true, '0600', (string)($st1['sha256'] ?? '-'), 0]);
t('no file at all exists under uploads/ for the statement', count(array_filter(glob($s->data . '/uploads/*') ?: [], function ($p) { return stripos($p, 'bank') !== false; })), 0);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nD. The lines\n";
$dbLines = function (int $id) use ($s): array { return $s->q('SELECT * FROM bank_statement_lines WHERE cb_account_id = ? ORDER BY cheque_number DESC', [$id]); };
$rowsOf = function (string $seg): array {
    preg_match_all('#<tr data-bk-line="(\d{10})">(.*?)</tr>#s', $seg, $m, PREG_SET_ORDER);
    $o = [];
    foreach ($m as $x) {
        $bal = preg_match('#<td class="bk-n bk-bal">([^<]*)</td>#', $x[2], $b) ? html_entity_decode($b[1], ENT_QUOTES) : null;
        $title = preg_match('#<td title="([^"]*)">#', $x[2], $tt) ? html_entity_decode($tt[1], ENT_QUOTES) : null;
        $o[] = ['chq' => $x[1], 'bal' => $bal, 'title' => $title, 'html' => $x[2]];
    }
    return $o;
};
$cardsOf = function (string $seg): array {
    preg_match_all('#<div class="bk-line" data-bk-line="(\d{10})">(.*?)<div class="bk-line-bal">Bal ([^<]*)</div>#s', $seg, $m, PREG_SET_ORDER);
    $o = [];
    foreach ($m as $x) $o[] = ['chq' => $x[1], 'bal' => html_entity_decode($x[3], ENT_QUOTES), 'html' => $x[2]];
    return $o;
};
$cardFig = function (string $seg): ?string { return preg_match('#<div class="bk-card-v" id="bkCardFig">([^<]*)</div>#', $seg, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null; };
$hL = $page('boss', 'page=dashboard&tab=bank&bk_acct=' . $U);
$gL = bk_seg($hL);
$L = $dbLines($U);
$rows = $rowsOf($gL); $cards = $cardsOf($gL);
t('rows newest first by bank line number, exactly the kept lines', array_column($rows, 'chq'), array_column($L, 'cheque_number'));
t('each row\'s Balance is the stored running balance', array_column($rows, 'bal'), array_map(function ($l) { return bk_money('UGX', (int)$l['running_units']); }, $L));
t('the card is the running balance of the kept line with the highest bank line number, and the top row\'s Balance',
    [$cardFig($gL), $rows[0]['bal'] ?? null], [bk_money('UGX', (int)$L[0]['running_units']), bk_money('UGX', (int)$L[0]['running_units'])]);
is_(strpos($gL, 'account ending ' . "\u{2022}\u{2022}\u{2022}\u{2022}" . '0001') !== false && strpos($gL, 'after the last kept line, dated ') !== false,
    'the card says which line it is after, and the account\'s last four');
t('each phone card carries "Bal" and the stored running balance', array_column($cards, 'bal'), array_column($rows, 'bal'));
$longL = array_values(array_filter($L, function ($l) { return strlen((string)$l['remarks']) > 65; }));
$longChq = (string)($longL[0]['cheque_number'] ?? '');
$longRow = array_values(array_filter($rows, function ($r) use ($longChq) { return $r['chq'] === $longChq; }))[0] ?? null;
$longCard = array_values(array_filter($cards, function ($r) use ($longChq) { return $r['chq'] === $longChq; }))[0] ?? null;
is_($longChq !== '' && $longRow !== null && $longRow['title'] === (string)$longL[0]['remarks']
    && strpos($longRow['html'], htmlspecialchars(mb_substr((string)$longL[0]['remarks'], 0, 65), ENT_QUOTES, 'UTF-8') . '…') !== false
    && strpos($longRow['html'], 'END<') === false,
    'a long Remarks is cut at 65 characters in the table, the whole text in its title');
is_($longCard !== null && strpos($longCard['html'], htmlspecialchars((string)$longL[0]['remarks'], ENT_QUOTES, 'UTF-8')) !== false,
    'the phone card holds the whole Remarks text');
$vd = 0; $vdOk = 0;
foreach ($L as $i => $l) {
    $has = strpos($rows[$i]['html'] ?? '', 'val ' . $l['value_date']) !== false;
    if ($l['value_date'] !== $l['posting_date']) { $vd++; if ($has) $vdOk++; } elseif (!$has) $vdOk++;
}
is_($vd > 0 && $vdOk === count($L), "the value date shows under the date exactly where it differs ({$vd} line(s))", "{$vdOk} of " . count($L));
t('every line says Not compared; nothing claims a match', [substr_count($gL, '>Not compared<'), substr_count($gL, 'Unknown status')], [2 * count($L), 0]);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nE. An overlapping, shuffled, re-padded second file adds only its new lines\n";
$file2 = g_file(array_slice($H, 20, 40), 'UGX', ['shuffle' => true, 'pad' => 20]);
$c2 = $post('boss', ['bk_acct' => (string)$U], [$file2, BK_FNAME]);
$p2 = $panel($c2['html']);
t('it passes: 40 in the file, 20 new, 20 already kept; no link question; Keep this statement',
    [$p2['tone'], $p2['lines'], $p2['link'], $p2['buttons']], ['g', '40 in the file · 20 new · 20 already kept', '', ['Keep this statement', 'Discard']]);
$k2 = $post('boss', ['bk_action' => 'keep', 'bk_acct' => (string)$U, 'bk_check' => (string)$c2['check']]);
t('kept: only the new lines', [$k2['flash'], bk_n($s, 'SELECT COUNT(*) n FROM bank_statement_lines WHERE cb_account_id = ?', [$U]),
    bk_n($s, 'SELECT COUNT(*) n FROM bank_statement_lines WHERE statement_id = ?', [(int)$st1['id']])],
    ['success: Kept: 20 new lines added, 20 already kept. Nothing was posted to the Cashbook.', 60, 40]);
$L = $dbLines($U);
$g1 = bk_seg($page('boss', 'page=dashboard&tab=bank&bk_acct=' . $U));
$g2 = bk_seg($page('boss', 'page=dashboard&tab=bank&bk_acct=' . $U . '&bk_page=2'));
$r1 = $rowsOf($g1); $r2 = $rowsOf($g2);
t('50 a page, in bank order, the shuffled file\'s lines too', [count($r1), count($r2), array_merge(array_column($r1, 'chq'), array_column($r2, 'chq'))],
    [50, 10, array_column($L, 'cheque_number')]);
is_(strpos($g1, '1–50 of 60') !== false && strpos($g2, '51–60 of 60') !== false, 'the pager counts the kept lines');
t('the card moved to the new last line', $cardFig($g1), bk_money('UGX', (int)$L[0]['running_units']));
t('the balances are the bank\'s own, whatever padding the second file used', array_column($r1, 'bal'),
    array_map(function ($l) { return bk_money('UGX', (int)$l['running_units']); }, array_slice($L, 0, 50)));

// ═══════════════════════════════════════════════════════════════════════════
echo "\nF. The month filter and its strip\n";
$months = array_values(array_unique(array_map(function ($l) { return substr((string)$l['posting_date'], 0, 7); }, array_reverse($L))));
is_(count($months) >= 2, 'the kept lines span more than one month (' . implode(', ', $months) . ')');
is_(preg_match('#<input type="month" name="bk_month" class="cbv2-fi" value=""\s+min="' . $months[0] . '" max="' . end($months) . '"#', $g1) === 1,
    'the month input is bounded by the first and last kept months');
foreach ($months as $mo) {
    $gm = bk_seg($page('boss', "page=dashboard&tab=bank&bk_acct={$U}&bk_month={$mo}"));
    $ml = array_values(array_filter($L, function ($l) use ($mo) { return substr((string)$l['posting_date'], 0, 7) === $mo; }));
    $asc = array_reverse($ml);
    $open = (int)$asc[0]['running_units'] - (int)$asc[0]['credit_units'] + (int)$asc[0]['debit_units'];
    $in = array_sum(array_map('intval', array_column($ml, 'credit_units'))); $out = array_sum(array_map('intval', array_column($ml, 'debit_units')));
    $close = (int)$ml[0]['running_units'];
    preg_match_all('#<div class="bk-sv[^"]*">([^<]*)</div>#', $gm, $sv);
    t("{$mo}: only the month's lines, newest first", array_column($rowsOf($gm), 'chq'), array_column(array_slice($ml, 0, 50), 'cheque_number'));
    t("{$mo}: the strip — opening, in, out, closing — from the bank's own balances", array_map(function ($v) { return html_entity_decode($v, ENT_QUOTES); }, $sv[1]),
        [bk_money('UGX', $open), bk_money('UGX', $in), bk_money('UGX', $out), bk_money('UGX', $close)]);
    is_($open + $in - $out === $close && strpos($gm, '&#10003; adds up') !== false, "{$mo}: opening + in − out = closing, and the strip says it adds up");
    is_(preg_match('#id="bkCsv" href="[^"]*bk_month=' . $mo . '&amp;bk_export=csv">&\#8595; ' . preg_quote(date('M Y', (int)strtotime($mo . '-01 12:00:00')), '#') . '</a>#', $gm) === 1,
        "{$mo}: the export button names the month and exports it");
    is_($cardFig($gm) === bk_money('UGX', (int)$L[0]['running_units']), "{$mo}: the card ignores the month filter");
}
$gEmpty = bk_seg($page('boss', "page=dashboard&tab=bank&bk_acct={$U}&bk_month=2025-01"));
is_(strpos($gEmpty, 'No line in this month.') !== false && strpos($gEmpty, 'id="bkStrip"') === false, 'a month with no kept line says so and draws no strip');
$gBad = bk_seg($page('boss', "page=dashboard&tab=bank&bk_acct={$U}&bk_month=2026-13"));
t('a month that is not YYYY-MM is ignored (all lines)', count($rowsOf($gBad)), 50);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nG. The export\n";
$screenAll = array_merge(array_column($r1, 'chq'), array_column($r2, 'chq'));
$x1 = bk_req($s, 'GET', "page=dashboard&tab=bank&bk_acct={$U}&bk_export=csv", 'boss');
$x1r = bk_csv($x1[1]);
t('the header row', $x1r[0] ?? [], ['Bank line no.', 'Posting date', 'Value date', 'Type code', 'Type', 'Transaction code', 'Particulars', 'Reference', 'In', 'Out', 'Balance', 'Currency', 'Statement', 'Status']);
$x1b = array_slice($x1r, 1);
t('its lines are the screen\'s lines over all pages, oldest first', array_column($x1b, 0), array_reverse($screenAll));
$Lasc = array_reverse($L);
t('each line\'s amounts and balance are plain numbers from the stored units',
    array_map(function ($r) { return [$r[8], $r[9], $r[10]]; }, $x1b),
    array_map(function ($l) { $in = (string)$l['credit_text'] !== ''; return [$in ? bk_plain((int)$l['credit_units']) : '', $in ? '' : bk_plain((int)$l['debit_units']), bk_plain((int)$l['running_units'])]; }, $Lasc));
t('the text columns are the stored ones', array_map(function ($r) { return [$r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[11], $r[13]]; }, $x1b),
    array_map(function ($l) { return [$l['posting_date'], $l['value_date'], $l['type_code'], $l['type_desc'], $l['txn_code'], $l['remarks'], $l['reference'], 'UGX', 'Not compared']; }, $Lasc));
$shaOf = [];
foreach ($s->q('SELECT id, sha256 FROM bank_statements') as $r) $shaOf[(int)$r['id']] = substr((string)$r['sha256'], 0, 8);
t('each line names its statement by the first 8 of its sha256', array_column($x1b, 12), array_map(function ($l) use ($shaOf) { return $shaOf[(int)$l['statement_id']] ?? '?'; }, $Lasc));
$cd = (string)($x1[3]['content-disposition'] ?? '');
t('headers: text/csv, attachment, no-store, nosniff',
    [(string)($x1[3]['content-type'] ?? ''), strpos($cd, 'attachment;') === 0, strpos((string)($x1[3]['cache-control'] ?? ''), 'no-store') !== false, (string)($x1[3]['x-content-type-options'] ?? '')],
    ['text/csv; charset=UTF-8', true, true, 'nosniff']);
t('the file name is the currency, the filter and the day — never the account number or the account\'s name', $cd,
    'attachment; filename="ecobank-UGX-all-lines-' . date('Y-m-d') . '.csv"');
$ev = $s->q("SELECT detail, actor_id FROM bank_line_events WHERE event = 'lines_exported' ORDER BY id DESC LIMIT 1")[0] ?? [];
t('one lines_exported event: the count and the month, by the admin', [json_decode((string)($ev['detail'] ?? ''), true), (int)($ev['actor_id'] ?? 0)],
    [['count' => 60, 'month' => 'all'], $s->ids['boss']]);
$mo = $months[0];
$x2 = bk_req($s, 'GET', "page=dashboard&tab=bank&bk_acct={$U}&bk_month={$mo}&bk_export=csv", 'boss');
$screenMo = array_column($rowsOf(bk_seg($page('boss', "page=dashboard&tab=bank&bk_acct={$U}&bk_month={$mo}"))), 'chq');
t("the month's export is the month's screen lines, oldest first", array_column(array_slice(bk_csv($x2[1]), 1), 0), array_reverse($screenMo));
t("the month's file name", (string)($x2[3]['content-disposition'] ?? ''), 'attachment; filename="ecobank-UGX-' . $mo . '-lines-' . date('Y-m-d') . '.csv"');
// BK-SEC-1: the export is a GET that writes an append-only event in the admin's name and sends every kept line as an
// attachment, and the staff cookie is SameSite=None. A request another site started must be refused before either.
$expN = function () use ($s): int { return bk_n($s, "SELECT COUNT(*) n FROM bank_line_events WHERE event = 'lines_exported'"); };
$xq = "page=dashboard&tab=bank&bk_acct={$U}&bk_export=csv";
$xSec = 'The security check failed, so nothing was sent. Open the Bank screen and export from there.';
foreach ([['an <img> on another site', ['Sec-Fetch-Site: cross-site', 'Sec-Fetch-Mode: no-cors', 'Sec-Fetch-Dest: image', 'Referer: https://rh-attacker.example.test/page']],
          ['a top-level navigation from another site', ['Sec-Fetch-Site: cross-site', 'Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document']],
          ['a sibling host (Sec-Fetch-Site same-site)', ['Sec-Fetch-Site: same-site']],
          ['another site\'s Origin, from an older browser', ['Origin: https://rh-attacker.example.test']],
          ['another site\'s Referer, from an older browser', ['Referer: https://rh-attacker.example.test/page']]] as [$lbl, $hd]) {
    $n0 = $expN();
    $xr = bk_req($s, 'GET', $xq, 'boss', null, $hd);
    t("the export refuses {$lbl}: 403, plain text, no file, no line, no event",
        [$xr[0], strpos((string)($xr[3]['content-type'] ?? ''), 'text/plain') === 0, isset($xr[3]['content-disposition']), strpos($xr[1], 'Bank line no.') === false,
         trim($xr[1]), $expN() - $n0],
        [403, true, false, true, $xSec, 0]);
}
foreach ([['from the screen itself (Sec-Fetch-Site same-origin)', ['Sec-Fetch-Site: same-origin', 'Referer: ' . $s->base . '?page=dashboard&tab=bank']],
          ['opened from an address typed or a bookmark (Sec-Fetch-Site none)', ['Sec-Fetch-Site: none']],
          ['from an older browser whose Referer names this host', ['Referer: ' . $s->base . '?page=dashboard&tab=bank']]] as [$lbl, $hd]) {
    $n0 = $expN();
    $xr = bk_req($s, 'GET', $xq, 'boss', null, $hd);
    t("control: the export {$lbl} is sent, with one event",
        [$xr[0], strpos((string)($xr[3]['content-disposition'] ?? ''), 'attachment;') === 0, bk_csv($xr[1])[0][0] ?? '', $expN() - $n0], [200, true, 'Bank line no.', 1]);
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\nH. The USD account: a ranged first file, the front join, escaping, formulas, a negative balance, one account per view\n";
$HD = g_history(50, 21, 0, 501, [2026, 8, 25]);
$set = function (int $i, string $col, string $v) use (&$HD) {
    $HD[$i][$col] = $v;
    if ($col === 'Remarks') $HD[$i]['Transaction Reference'] = $v;
    if ($col === 'Reference') $HD[$i]['Customer Reference'] = $v;
};
$set(12, 'Remarks', '<script>alert("RH")</script>');
$set(13, 'Remarks', '"><img src=x onerror=alert(1)>');
$set(14, 'Remarks', '=HYPERLINK("http://example.test/rh","RH")');
$set(15, 'Remarks', '@SUM(1)');
$set(16, 'Remarks', '+RH plus');
$set(17, 'Remarks', '-RH minus');
$set(18, 'Remarks', "\tRH tab");
$set(19, 'Reference', '=RH1+RH2');
$set(20, 'Reference', '<b>RH</b>');
$HD[25]['_credit'] = false; $HD[25]['_cents'] = $HD[24]['_run'] + 77700;    // an overdraft: the balance goes below zero
$HD = g_rechain($HD, 25);
$HD[30]['Posting Date'] = '15-OCT-26';                                         // a posting date out of the bank's order
$neg = count(array_filter(array_slice($HD, 10, 30), function ($l) { return $l['_run'] < 0; }));
is_($neg > 0, "the USD history holds {$neg} line(s) with a balance below zero");
$cu1 = $post('boss', ['bk_acct' => (string)$D], [g_file(array_slice($HD, 10, 30), 'USD'), BK_FNAME]);
$pu1 = $panel($cu1['html']);
t('a ranged first USD file passes; the link question says USD', [$pu1['tone'], $pu1['link']],
    ['g', "This file is for an account ending \u{2022}\u{2022}\u{2022}\u{2022}0001, in USD. Link it to «Ecobank Uganda – USD»?"]);
$ku1 = $post('boss', ['bk_action' => 'keep', 'bk_acct' => (string)$D, 'bk_check' => (string)$cu1['check'], 'bk_link' => '1']);
is_(strpos($ku1['flash'], 'success: Kept: 30 new lines added') === 0, 'kept and linked', $ku1['flash']);
$firstUsd = (string)$s->q('SELECT MIN(posting_date) d FROM bank_statement_lines WHERE cb_account_id = ?', [$D])[0]['d'];
$cg = $post('boss', ['bk_acct' => (string)$D], [g_file(array_slice($HD, 0, 9), 'USD'), BK_FNAME]);
t('a file that ends before the first kept line is refused gap_before, dated that line',
    [$panel($cg['html'])['text'], (string)($s->q('SELECT code FROM bank_checks WHERE id = ?', [$cg['check']])[0]['code'] ?? '')],
    [EcobankDetailsCsv::sentence('gap_before', $firstUsd), 'gap_before']);
$cu2 = $post('boss', ['bk_acct' => (string)$D], [g_file(array_slice($HD, 0, 46), 'USD'), BK_FNAME]);
t('a from-opening file reaching the first kept line adds both ends', $panel($cu2['html'])['lines'], '46 in the file · 16 new · 30 already kept');
$post('boss', ['bk_action' => 'keep', 'bk_acct' => (string)$D, 'bk_check' => (string)$cu2['check']]);
$LD = $dbLines($D);
t('46 USD lines kept', count($LD), 46);
$gD = bk_seg($page('boss', 'page=dashboard&tab=bank&bk_acct=' . $D));
is_(strpos($gD, '<script>alert') === false && strpos($gD, '&lt;script&gt;alert(&quot;RH&quot;)&lt;/script&gt;') !== false
    && strpos($gD, 'title="&lt;script&gt;alert(&quot;RH&quot;)&lt;/script&gt;"') !== false, 'a <script> Remarks renders as text, in the cell and in its title');
is_(strpos($gD, '"><img src=x') === false && strpos($gD, '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;') !== false, 'a "><img> Remarks cannot leave its attribute or open a tag');
is_(strpos($gD, '<b>RH</b>') === false && strpos($gD, '&lt;b&gt;RH&lt;/b&gt;') !== false, 'a Reference holding markup renders as text');
is_(strpos($gD, '=HYPERLINK(&quot;http://example.test/rh&quot;,&quot;RH&quot;)') !== false && strpos($gD, '@SUM(1)') !== false, 'a formula-shaped Remarks is plain text on screen');
is_(stripos($gD, 'innerHTML') === false && stripos($gD, 'outerHTML') === false && stripos($gD, 'insertAdjacentHTML') === false && stripos($gD, 'document.write') === false,
    'the tab emits no innerHTML, outerHTML, insertAdjacentHTML or document.write');
$uFig = (int)$L[0]['running_units']; $dFig = (int)$LD[0]['running_units'];
t('the USD card is the USD account\'s own last line', $cardFig($gD), bk_money('USD', $dFig));
$sum = $uFig + $dFig;
is_(strpos($gD, number_format($sum / 100, 2)) === false && strpos($g1, number_format($sum / 100, 2)) === false,
    'one account per view: no figure on either page equals UGX + USD (' . number_format($sum / 100, 2) . ')');
$negL = array_values(array_filter($LD, function ($l) { return (int)$l['running_units'] < 0; }));
is_($negL !== [] && strpos($gD, bk_money('USD', (int)$negL[0]['running_units'])) !== false, 'a balance below zero is shown with its minus');
$xD = bk_csv(bk_req($s, 'GET', "page=dashboard&tab=bank&bk_acct={$D}&bk_export=csv", 'boss')[1]);
$byChq = [];
foreach (array_slice($xD, 1) as $r) $byChq[$r[0]] = $r;
$cq = function (int $i) use ($HD) { return (string)$HD[$i]['Cheque Number']; };
t('the export neutralises every text cell that starts like a formula, and nothing else',
    [$byChq[$cq(14)][6] ?? null, $byChq[$cq(15)][6] ?? null, $byChq[$cq(16)][6] ?? null, $byChq[$cq(17)][6] ?? null, $byChq[$cq(18)][6] ?? null,
     $byChq[$cq(19)][7] ?? null, $byChq[$cq(12)][6] ?? null, $byChq[$cq(11)][6] ?? null],
    ["'=HYPERLINK(\"http://example.test/rh\",\"RH\")", "'@SUM(1)", "'+RH plus", "'-RH minus", "'\tRH tab", "'=RH1+RH2", '<script>alert("RH")</script>', (string)$HD[11]['Remarks']]);
$badAmt = [];
foreach (array_slice($xD, 1) as $r) foreach ([8, 9, 10] as $c) if ($r[$c] !== '' && !preg_match('/^-?\d+\.\d{2}$/', $r[$c])) $badAmt[] = $r[$c];
$negX = array_values(array_filter(array_slice($xD, 1), function ($r) { return strpos($r[10], '-') === 0; }));
t('amounts are numbers, never prefixed — a negative balance included', [$badAmt, count($negX), $byChq[(string)$negL[0]['cheque_number']][10] ?? ''],
    [[], count($negL), bk_plain((int)$negL[0]['running_units'])]);
$sep = '2026-09';
$gS = bk_seg($page('boss', "page=dashboard&tab=bank&bk_acct={$D}&bk_month={$sep}"));
is_(strpos($gS, 'not consecutive in the bank') !== false && strpos($gS, 'adds up') === false && strpos($gS, 'id="bkStrip"') === false,
    'a month whose lines are not consecutive in the bank\'s order says so instead of showing a sum');
$gO = bk_seg($page('boss', "page=dashboard&tab=bank&bk_acct={$D}&bk_month=2026-10"));
is_(strpos($gO, 'id="bkStrip"') !== false && strpos($gO, '&#10003; adds up') !== false, 'the out-of-order line\'s own month is consecutive and adds up');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nI. Every refusal and note the screen can reach\n";
$linesBefore = bk_n($s, 'SELECT COUNT(*) n FROM bank_statement_lines');
$stBefore = bk_n($s, 'SELECT COUNT(*) n FROM bank_statements');
$HM = g_history(70, 3);
$mf = function (array $lines, array $o = []) { return g_file($lines, 'UGX', $o + ['marker' => BK_MARK]); };
$m40 = $mf(array_slice($HM, 0, 40));
$mut = function (callable $m) use ($mf, $HM) { return $mf(array_slice($HM, 0, 40), ['mutate' => $m]); };
$side = function ($L, int $at, string $v) { $c = $L[$at]['Credit'] !== '' ? 'Credit' : 'Debit'; $L[$at][$c] = $v; return $L; };
$hdrOnly = implode(',', array_map(function ($v) { return '"' . $v . '"'; }, EcobankDetailsCsv::HEADER)) . "\n";
$CASES = [
    ['empty', $SP, $hdrOnly, 'r'],
    ['not_csv', $SP, '%PDF-' . $m40, 'r', 'PDF'],
    ['not_csv', $SP, "PK\x03\x04" . $m40, 'r', 'EXCEL'],
    ['not_csv', $SP, $m40 . "\0", 'r', 'BINARY'],
    ['not_csv', $SP, $mut(function ($L) { $L[2]['Remarks'] .= "\xff"; return $L; }), 'r', 'ENCODING'],
    ['not_csv', $SP, str_replace("\n", "\r", $m40), 'r', 'LINE-ENDS'],
    ['summary_export', $SP, g_summary(true, 'UGX', BK_MARK), 'r'],
    ['summary_export', $SP, g_summary(false, 'UGX', BK_MARK), 'r'],
    ['header', $SP, g_file(array_slice($HM, 0, 40), 'UGX', ['marker' => BK_MARK, 'header' => array_merge(['RHMARKER Account'], array_slice(EcobankDetailsCsv::HEADER, 1))]), 'r'],
    ['columns', $SP, $m40 . "\"RHMARKER\",\"0000000001\",\"RH-Account Holder One\"\n", 'r'],
    ['field_too_long', $SP, $mut(function ($L) { $L[3]['Remarks'] = 'RHMARKER ' . str_repeat('R', 1001); return $L; }), 'r'],
    ['account_mixed', $SP, $mut(function ($L) { $L[9]['Account'] = '0000000002'; return $L; }), 'r'],
    ['account_mixed', $SP, $mut(function ($L) { $L[9]['Account Name'] = 'RHMARKER RH-Account Holder Two'; return $L; }), 'r'],
    ['currency_form', $SP, $mut(function ($L) { $L[9]['Closing Available'] = 'UGX 1.00'; return $L; }), 'r'],
    ['sequence', $SP, $mut(function ($L) { array_splice($L, 10, 1); return $L; }), 'r'],
    ['date', $SP, $mut(function ($L) { $L[3]['Value Date'] = '32-AUG-26'; return $L; }), 'r'],
    ['amount_both_neither', $SP, $mut(function ($L) { $L[5]['Credit'] = '1'; $L[5]['Debit'] = '1'; return $L; }), 'r'],
    ['amount_form', $SP, $mut(function ($L) use ($side) { return $side($L, 5, '1,000'); }), 'r'],
    ['amount_scale', $SP, $mut(function ($L) use ($side) { return $side($L, 5, '1.125'); }), 'r'],
    ['balance_form', $SP, $mut(function ($L) { $L[7]['Running Balance'] = '  12.5'; return $L; }), 'r'],
    ['chain', $SP, $mut(function ($L) { $L[20]['Running Balance'] = '        1.00'; return $L; }), 'r'],
    ['closing', $SP, $mut(function ($L) { array_pop($L); return $L; }), 'r'],
    ['lineid_form', $SP, $mut(function ($L) { $L[8]['Cheque Number'] = 'RHMARKER'; return $L; }), 'r'],
    ['lineid_duplicate', $SP, $mut(function ($L) { $L[8]['Cheque Number'] = $L[7]['Cheque Number']; return $L; }), 'r'],
    ['lineid_order', $SP, $mut(function ($L) { [$L[8]['Cheque Number'], $L[7]['Cheque Number']] = [$L[7]['Cheque Number'], $L[8]['Cheque Number']]; return $L; }), 'r'],
    ['currency_mismatch', $D, g_file(array_slice($HM, 0, 20)), 'r', 'UGX|USD'],
    ['account_linked_elsewhere', $SP, g_file(array_slice($HM, 0, 20)), 'r', '0001'],
];
$seenCodes = [];
$caseOk = function (array $cs, array $r, string $label) use ($s, $panel, &$seenCodes): void {
    [$code, $acct, , $tone] = $cs;
    $row = $s->q('SELECT code, param, outcome, status FROM bank_checks WHERE id = ?', [$r['check']])[0] ?? [];
    $pn = $panel($r['html']);
    $want = EcobankDetailsCsv::sentence($code, (string)($row['param'] ?? ''));
    $leak = array_values(array_filter(BK_LEAKS, function ($n) use ($r) { return strpos($r['html'], $n) !== false || strpos($r['flash'], $n) !== false || strpos($r['loc'], $n) !== false; }));
    $okParam = !isset($cs[4]) || (string)($row['param'] ?? '') === $cs[4];
    is_($r['code'] === 302 && ($row['code'] ?? '') === $code && $okParam && $pn['tone'] === $tone && $pn['text'] === $want && $pn['check'] === $r['check'] && $leak === []
        && $r['flash'] === ($tone === 'r' ? 'danger: The file was not kept. The reason is shown below.' : 'info: The file was checked. Nothing was added.'),
        "{$label}: the panel says «{$want}»; nothing from the file on the page, in the flash or the address",
        json_encode(['code' => $row['code'] ?? null, 'param' => $row['param'] ?? null, 'tone' => $pn['tone'], 'text' => $pn['text'], 'flash' => $r['flash'], 'leak' => $leak]));
    $seenCodes[$code] = true;
};
foreach ($CASES as $i => $cs) {
    $r = $post('boss', ['bk_acct' => (string)$cs[1]], [$cs[2], BK_FNAME]);
    $caseOk($cs, $r, $cs[0] . (isset($cs[4]) ? ' ' . $cs[4] : ' #' . ($i + 1)));
}
$big = g_file(g_history(EcobankDetailsCsv::LINES_MAX + 1, 5));
if (strlen($big) <= $cap) {
    $r = $post('boss', ['bk_acct' => (string)$SP], [$big, BK_FNAME]);
    $caseOk(['too_many_lines', $SP, '', 'r'], $r, 'too_many_lines');
} else {
    $skip++;
    echo "  skip too_many_lines over HTTP: a 10,001-line file is " . strlen($big) . " bytes, above this server's cap of {$cap} (test_bank_reader.php proves the code)\n";
}
unset($big);
// account_other: a file for ••••0002 on the account linked to ••••0001
$H2 = array_map(function ($l) { $l['Account'] = '0000000002'; return $l; }, array_slice($H, 60, 10));
$caseOk(['account_other', $U, '', 'r', '0002'], $post('boss', ['bk_acct' => (string)$U], [g_file($H2), BK_FNAME]), 'account_other 0002');
// changed_since_check: two checks wait; the first is kept; the second no longer gives its counts
$cA = $post('boss', ['bk_acct' => (string)$U], [g_file(array_slice($H, 55, 15)), BK_FNAME]);
$cB = $post('boss', ['bk_acct' => (string)$U], [g_file(array_slice($H, 50, 25)), BK_FNAME]);
t('two checks wait at once', [$panel($cA['html'])['lines'], $panel($cB['html'])['lines']], ['15 in the file · 10 new · 5 already kept', '25 in the file · 15 new · 10 already kept']);
$gW = bk_seg($page('boss', "page=dashboard&tab=bank&bk_acct={$U}&bk_view=statements"));
is_(strpos($gW, '&#128196; Statements <span class="cb3-badge">2</span>') !== false && substr_count($gW, '>Waiting to be kept<') === 2,
    'the Statements tab counts the two checks waiting to be kept');
preg_match_all('#<a class="bk-chip(?: on)?" data-bk-acct="\d+" href="([^"]*)">#', $gW, $chq);
t('changing account keeps the tab and drops the month', array_map(function ($u) { return html_entity_decode($u, ENT_QUOTES); }, $chq[1]),
    array_map(function ($id) { return '?page=dashboard&tab=bank&bk_acct=' . $id . '&bk_view=statements'; }, [$U, $SP, $D]));
$kA = $post('boss', ['bk_action' => 'keep', 'bk_acct' => (string)$U, 'bk_check' => (string)$cA['check']]);
$kB = $post('boss', ['bk_action' => 'keep', 'bk_acct' => (string)$U, 'bk_check' => (string)$cB['check']]);
t('the second keep refuses changed_since_check; its check expires and keeps nothing',
    [$kB['flash'], (string)$s->q('SELECT status FROM bank_checks WHERE id = ?', [$cB['check']])[0]['status'], bk_n($s, 'SELECT COUNT(*) n FROM bank_statement_lines WHERE cb_account_id = ?', [$U])],
    ['danger: ' . EcobankDetailsCsv::sentence('changed_since_check'), 'expired', 70]);
$pB = $panel($kB['html']);
t('its panel says nothing was kept', [$pB['tone'], $pB['state']], ['n', 'expired/passed']);
$kA2 = $post('boss', ['bk_action' => 'keep', 'bk_acct' => (string)$U, 'bk_check' => (string)$cA['check']]);
$keptDay = substr((string)$s->q('SELECT kept_at FROM bank_statements WHERE check_id = ?', [$cA['check']])[0]['kept_at'], 0, 10);
t('a second keep of a kept check is a note, already_kept, with the day it was kept', $kA2['flash'], 'info: ' . EcobankDetailsCsv::sentence('already_kept', $keptDay));
$seenCodes['changed_since_check'] = $seenCodes['link_required'] = true;
// the fit codes, on the account now holding lines 1–70
$od = $H; $od[65]['Remarks'] .= ' RESTATED'; $od[65]['Transaction Reference'] = $od[65]['Remarks'];
$caseOk(['overlap_differs', $U, '', 'r', (string)$H[65]['Cheque Number']], $post('boss', ['bk_acct' => (string)$U], [g_file(array_slice($od, 60, 16)), BK_FNAME]), 'overlap_differs');
$sl = array_slice($H, 40, 36); $ins = -1;
for ($i = 0; $i < 20; $i++) if ((int)$sl[$i + 1]['Cheque Number'] - (int)$sl[$i]['Cheque Number'] >= 2) { $ins = $i; break; }
$new = $sl[$ins]; $new['Cheque Number'] = sprintf('%010d', (int)$sl[$ins]['Cheque Number'] + 1); $new['Credit'] = '0'; $new['Debit'] = '';
$new['_cents'] = 0; $new['_credit'] = true; $new['Remarks'] = $new['Transaction Reference'] = 'RH INSERTED';
array_splice($sl, $ins + 1, 0, [$new]);
$caseOk(['overlap_interleave', $U, '', 'r', $new['Cheque Number']], $post('boss', ['bk_acct' => (string)$U], [g_file($sl), BK_FNAME]), 'overlap_interleave');
$lastDay = (string)$s->q('SELECT posting_date d FROM bank_statement_lines WHERE cb_account_id = ? ORDER BY cheque_number DESC LIMIT 1', [$U])[0]['d'];
$caseOk(['gap_after', $U, '', 'r', $lastDay], $post('boss', ['bk_acct' => (string)$U], [g_file(array_slice($H, 71, 9)), BK_FNAME]), 'gap_after');
$seenCodes['gap_before'] = true;
$caseOk(['nothing_new', $U, '', 'b'], $post('boss', ['bk_acct' => (string)$U], [g_file(array_slice($H, 10, 21), 'UGX', ['pad' => 14]), BK_FNAME]), 'nothing_new');
$caseOk(['already_kept', $U, '', 'b', substr((string)$st1['kept_at'], 0, 10)], $post('boss', ['bk_acct' => (string)$U], [$file1, BK_FNAME]), 'already_kept');
t('no refusal or note kept a statement or a line', [bk_n($s, 'SELECT COUNT(*) n FROM bank_statements'), bk_n($s, 'SELECT COUNT(*) n FROM bank_statement_lines')],
    [$stBefore + 1, $linesBefore + 10]);
// The upload's own refusals: nothing arrived to record, so no check row — the flash says it
$nb = bk_counts($s);
$nr = $post('boss', ['_csrf' => $csrf, 'bk_action' => 'check', 'bk_acct' => (string)$U]);
t('no file: not_received, with the cap', $nr['flash'], 'danger: ' . EcobankDetailsCsv::sentence('not_received', EcobankDetailsCsv::capMb($cap)));
$tl = $post('boss', ['bk_acct' => (string)$U], [str_repeat("\"RH\",", 10) . "\n" . str_repeat('R', $cap + 1), BK_FNAME]);
t('a file over the cap: too_large', $tl['flash'], 'danger: ' . EcobankDetailsCsv::sentence('too_large', EcobankDetailsCsv::capMb($cap)));
foreach (['an unknown account' => 999999, 'the inactive bank account' => $OLD, 'the cash account' => $CASH] as $lbl => $id) {
    $an = $post('boss', ['bk_acct' => (string)$id], [g_file(array_slice($H, 0, 5)), BK_FNAME]);
    t("{$lbl}: account_not_offered, before the file is read", $an['flash'], 'danger: ' . EcobankDetailsCsv::sentence('account_not_offered'));
}
t('none of the upload refusals wrote a row', bk_counts($s), $nb);
$seenCodes['not_received'] = $seenCodes['too_large'] = $seenCodes['account_not_offered'] = true;
// check_expired: a waiting check older than the TTL is shown expired WITHOUT a write, then expired by the next POST
$cx = $post('boss', ['bk_acct' => (string)$U], [g_file(array_slice($H, 69, 4)), BK_FNAME]);
$dx = $post('boss', ['bk_action' => 'discard', 'bk_acct' => (string)$U, 'bk_check' => (string)$cx['check']]);
t('discard: the check is discarded, its held file deleted, fixed text', [$dx['flash'], (string)$s->q('SELECT status FROM bank_checks WHERE id = ?', [$cx['check']])[0]['status'],
    count(glob($s->data . '/bank_statements/.pending/*.csv') ?: [])], ['success: Discarded. Nothing was kept from this file.', 'discarded', 0]);
$pdo = bk_pdo($s);
$oldAt = gmdate('Y-m-d H:i:s', time() - 3 * 3600);
$nextId = (int)$pdo->query('SELECT MAX(id) FROM bank_checks')->fetchColumn() + 1;
$heldRel = 'bank_statements/.pending/' . $nextId . '-' . bin2hex(random_bytes(8)) . '.csv';
$heldBytes = g_file(array_slice($H, 69, 4));
file_put_contents($s->data . '/' . $heldRel, $heldBytes);
chmod($s->data . '/' . $heldRel, 0600);
$cols = 'cb_account_id, sha256, bytes, profile, parser_version, outcome, code, param, currency, acct_last4, line_count, lines_new, lines_known, period_from, '
      . 'period_to, opening_stated, closing_stated, opening_derived, credit_count, credit_units, debit_count, debit_units, warnings';
$pdo->prepare("INSERT INTO bank_checks (id, {$cols}, pending_rel, status, checked_by_id, checked_at) SELECT ?, {$cols}, ?, 'waiting', checked_by_id, ? FROM bank_checks WHERE id = ?")
    ->execute([$nextId, $heldRel, $oldAt, $cx['check']]);
unset($pdo);
$hx = $page('boss', "page=dashboard&tab=bank&bk_acct={$U}&bk_check={$nextId}");
$px = $panel($hx);
t('a waiting check past the TTL is shown expired, and the GET wrote nothing',
    [$px['tone'], $px['text'], (string)$s->q('SELECT status FROM bank_checks WHERE id = ?', [$nextId])[0]['status'], is_file($s->data . '/' . $heldRel)],
    ['n', EcobankDetailsCsv::sentence('check_expired'), 'waiting', true]);
$kx = $post('boss', ['bk_action' => 'keep', 'bk_acct' => (string)$U, 'bk_check' => (string)$nextId]);
clearstatcache();
t('Keep on it: check_expired; the next POST expired it and deleted its held file',
    [$kx['flash'], (string)$s->q('SELECT status FROM bank_checks WHERE id = ?', [$nextId])[0]['status'], is_file($s->data . '/' . $heldRel)],
    ['danger: ' . EcobankDetailsCsv::sentence('check_expired'), 'expired', false]);
$seenCodes['check_expired'] = true;
$owned = array_merge(EcobankDetailsCsv::READER_CODES, EcobankDetailsCsv::FIT_CODES, ['currency_mismatch', 'account_other', 'account_linked_elsewhere',
    'account_not_offered', 'already_kept', 'link_required', 'changed_since_check', 'check_expired', 'not_received', 'too_large']);
$missing = array_values(array_diff(array_unique($owned), array_keys($seenCodes), $skip > 0 ? ['too_many_lines'] : []));
t('every code with a sentence was reached through the screen (too_many_lines only where the cap allows it)', $missing, []);
// The Statements tab
$gT = bk_seg($page('boss', "page=dashboard&tab=bank&bk_acct={$U}&bk_view=statements"));
$chks = $s->q('SELECT id, status, outcome FROM bank_checks WHERE cb_account_id = ? ORDER BY id DESC', [$U]);
preg_match_all('#<tr data-bk-check="(\d+)">(.*?)</tr>#s', $gT, $tm, PREG_SET_ORDER);
t('Statements: one row per check of the account, newest first', array_map(function ($x) { return (int)$x[1]; }, $tm), array_map(function ($c) { return (int)$c['id']; }, $chks));
$lab = function (array $c) { $m = ['kept' => 'Kept', 'discarded' => 'Discarded', 'expired' => 'Expired', 'waiting' => 'Waiting to be kept'];
    if (isset($m[$c['status']])) return $m[$c['status']];
    return $c['outcome'] === 'already_kept' ? 'Already kept' : ($c['outcome'] === 'nothing_new' ? 'Nothing new' : 'Refused'); };
t('each row\'s status', array_map(function ($x) { return preg_match('#<span class="bk-st">([^<]*)</span>#', $x[2], $m) ? $m[1] : '?'; }, $tm), array_map($lab, $chks));
$rng = $s->q('SELECT MIN(posting_date) a, MAX(posting_date) b FROM bank_statement_lines WHERE cb_account_id = ?', [$U])[0];
$sd = function (string $iso) { return date('d M Y', (int)strtotime($iso . ' 12:00:00')); };
is_(strpos($gT, 'Kept lines run from ' . $sd($rng['a']) . ' to ' . $sd($rng['b']) . '. Every file was complete by its own line numbers, and each one after the first overlapped the lines already kept.') !== false,
    'the coverage line says what the code proves, from the first to the last kept line');
is_(substr_count($gT, 'RH-Admin One') === count(array_filter($chks, function ($c) { return $c['status'] === 'kept'; })), 'each kept row names who kept it');
$f1 = $s->data . '/' . $st1['file_rel'];
rename($f1, $f1 . '.away');
$gT2 = bk_seg($page('boss', "page=dashboard&tab=bank&bk_acct={$U}&bk_view=statements"));
rename($f1 . '.away', $f1);
t('a kept statement whose file is not on this server says so, and its lines are still shown',
    [substr_count($gT2, 'the original file is not on this server; its lines are kept'), count($rowsOf(bk_seg($page('boss', 'page=dashboard&tab=bank&bk_acct=' . $U))))], [1, 50]);

// A status other than "imported" (P3c's, later) is never claimed as a match: it reads Unknown status, on screen and in the export
$topChq = (string)$s->q('SELECT cheque_number c FROM bank_statement_lines WHERE cb_account_id = ? ORDER BY cheque_number DESC LIMIT 1', [$U])[0]['c'];
$s->q("UPDATE bank_statement_lines SET status = 'matched' WHERE cb_account_id = ? AND cheque_number = ?", [$U, $topChq]);
$gU = bk_seg($page('boss', 'page=dashboard&tab=bank&bk_acct=' . $U));
$xU = bk_csv(bk_req($s, 'GET', "page=dashboard&tab=bank&bk_acct={$U}&bk_export=csv", 'boss')[1]);
$top = $rowsOf($gU)[0] ?? ['chq' => '', 'html' => ''];
t('a line whose status is not "imported" reads Unknown status, never a match — on screen and in the export',
    [$top['chq'], strpos($top['html'], '>Unknown status<') !== false, substr_count($gU, '>Unknown status<'), (string)(end($xU)[13] ?? '')], [$topChq, true, 2, 'Unknown status']);
$s->q("UPDATE bank_statement_lines SET status = 'imported' WHERE cb_account_id = ? AND cheque_number = ?", [$U, $topChq]);

// ═══════════════════════════════════════════════════════════════════════════
echo "\nJ. CSRF and cross-site posts\n";
$okFile = [g_file(array_map(function ($l) { $l['Account'] = '0000000003'; return $l; }, array_slice($HM, 0, 12))), BK_FNAME];
$host = (string)parse_url($s->base, PHP_URL_HOST) . ':' . (string)parse_url($s->base, PHP_URL_PORT);
$cn = bk_counts($s);
$sec = 'danger: The security check failed, so nothing was changed. Open the Bank screen again and retry.';
$f = $post('boss', ['_csrf' => null, 'cb_action' => 'x', 'bk_acct' => (string)$SP], $okFile);
t('no token, with cb_action=x (the global gate skips it): refused by the handler, nothing written', [$f['flash'], bk_counts($s)], [$sec, $cn]);
$f = $post('boss', ['bk_acct' => (string)$SP], $okFile, ['Sec-Fetch-Site: cross-site']);
t('a valid token sent cross-site (Sec-Fetch-Site): refused, nothing written', [$f['flash'], bk_counts($s)], [$sec, $cn]);
$f = $post('boss', ['bk_acct' => (string)$SP], $okFile, ['Origin: http://rh-elsewhere.example.test']);
t('a valid token from another Origin: refused, nothing written', [$f['flash'], bk_counts($s)], [$sec, $cn]);
$f = $post('boss', ['_csrf' => null, 'bk_acct' => (string)$SP], $okFile);
t('no token at all: the global gate refuses first, nothing written', [$f['flash'], bk_counts($s)], ['danger: Security token mismatch. Please try again.', $cn]);
$f = $post('boss', ['_csrf' => 'x', 'cb_action' => 'x', 'bk_acct' => (string)$SP], $okFile);
t('a forged token with cb_action=x: refused by the handler', [$f['flash'], bk_counts($s)], [$sec, $cn]);
$f = $post('boss', ['bk_acct' => (string)$SP], $okFile, ['Sec-Fetch-Site: same-origin', 'Origin: http://' . $host]);
t('control: the same POST from our own page is checked (one check row, one event)',
    [$f['flash'], bk_n($s, 'SELECT COUNT(*) n FROM bank_checks') - $cn['bank_checks'], bk_n($s, 'SELECT COUNT(*) n FROM bank_line_events') - $cn['bank_line_events']],
    ['success: The file passed every check. Nothing is kept until you press Keep.', 1, 1]);
$spCheck = $f['check'];
// BK-SEC-2: the token is the same for every user and day and the login page prints it, so a POST must SHOW it came from
// our own page. One that shows nothing, a sandboxed frame's Origin of null, a sibling host or a contradiction is refused;
// an older browser's own Origin, or its own Referer, naming this host, is accepted.
$loginTok = preg_match('/name="_csrf" value="([^"]+)"/', bk_req($s, 'GET', 'page=login', null)[1], $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
t('control: the login page prints the day\'s form token to anyone who opens it', [$loginTok !== '', $loginTok === $csrf], [true, true]);
foreach ([['no Sec-Fetch-Site, no Origin, no Referer', [], $csrf], ['an Origin of null (a sandboxed frame)', ['Origin: null'], $csrf],
          ['Sec-Fetch-Site same-site (a sibling host)', ['Sec-Fetch-Site: same-site'], $csrf],
          ['Sec-Fetch-Site same-origin beside a foreign Origin', ['Sec-Fetch-Site: same-origin', 'Origin: http://rh-elsewhere.example.test'], $csrf],
          ['the login page\'s token and nothing else', [], $loginTok], ['the login page\'s token from a sandboxed frame', ['Origin: null'], $loginTok]] as [$lbl, $hd, $tok]) {
    $cn = bk_counts($s);
    $f = $post('boss', ['_csrf' => $tok, 'bk_acct' => (string)$SP], $okFile, $hd);
    t("a valid token with {$lbl}: refused, nothing written", [$f['flash'], bk_counts($s)], [$sec, $cn]);
}
foreach ([['an older browser\'s own Origin', ['Origin: http://' . $host]], ['an older browser\'s own Referer, and no Origin', ['Referer: ' . $s->base . '?page=dashboard&tab=bank']]] as [$lbl, $hd]) {
    $cn = bk_counts($s);
    $f = $post('boss', ['bk_acct' => (string)$SP], $okFile, $hd);
    $d = $f['check'] > 0 ? $post('boss', ['bk_action' => 'discard', 'bk_acct' => (string)$SP, 'bk_check' => (string)$f['check']]) : ['flash' => ''];
    t("control: {$lbl} (no Sec-Fetch-Site) is checked, then discarded", [$f['flash'], bk_n($s, 'SELECT COUNT(*) n FROM bank_checks') - $cn['bank_checks'], $d['flash']],
        ['success: The file passed every check. Nothing is kept until you press Keep.', 1, 'success: Discarded. Nothing was kept from this file.']);
}
$bad = $post('boss', ['bk_action' => 'erase', 'bk_acct' => (string)$SP]);
t('an action that is not the screen\'s is refused', $bad['flash'], 'danger: That is not an action of the Bank screen. Nothing was changed.');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nK. The gating matrix\n";
$setCfg = function (array $over) use ($s): void {
    $cfg = array_merge($s->cfg, $over);
    file_put_contents($s->data . '/kyc_config.json', json_encode($cfg));
    $s->store()->save('kyc_config.json', $cfg);
};
$tabDeny = '<div style="color:#b91c1c;padding:24px;font-weight:600;">Access denied.</div>';
$gateOff = '<div style="padding:24px;color:#6b7280;">The Bank screen is not available on this installation.</div>';
$matrix = function (string $label, string $who, string $tabWant, string $flashWant, string $exportWant) use ($s, $page, $post, $okFile, $SP, $U): void {
    $cn = bk_counts($s);
    $h = $page($who, 'page=dashboard&tab=bank&bk_acct=' . $U);
    $tab = bk_seg($h) === '' && ($tabWant === '' ? strpos($h, 'Access denied.</div>') === false && strpos($h, 'not available on this installation') === false : strpos($h, $tabWant) !== false);
    $p = $post($who, ['bk_acct' => (string)$SP], $okFile);
    $x = bk_req($s, 'GET', "page=dashboard&tab=bank&bk_acct={$U}&bk_export=csv", $who);
    $exp = $x[0] === 403 && trim($x[1]) === $exportWant && strpos((string)($x[3]['content-type'] ?? ''), 'text/plain') === 0;
    is_($tab, "{$label}: the tab refuses" . ($tabWant === '' ? ' at the dispatcher (the tab itself never runs)' : ' with its own sentence'));
    t("{$label}: the POST refuses, nothing written", [$p['flash'], bk_counts($s)], [$flashWant, $cn]);
    is_($exp, "{$label}: the export refuses (403, nothing sent)", $x[0] . ' ' . substr($x[1], 0, 120));
};
// BK-SEC-4: the sign-in comes first at both entry points, so a visitor who is not signed in gets the same answer
// whatever the switch, the tenant or SSP: the login page. The POST carries the login page's own token (anyone has it).
$anon = function (SjSandbox $x, string $label, string $tok) use ($okFile): void {
    $cn = bk_counts($x);
    $e = bk_req($x, 'GET', 'page=dashboard&tab=bank&bk_acct=1&bk_export=csv', null);
    $p = bk_req($x, 'POST', 'page=dashboard&tab=bank', null, ['_csrf' => $tok, 'bk_action' => 'check', 'bk_acct' => '1'],
        ['Sec-Fetch-Site: same-origin', 'Origin: ' . (string)preg_replace('#^(https?://[^/]+).*$#', '$1', $x->base)], $okFile);
    t("{$label}: a visitor who is not signed in is sent to the login page by the export and by the POST, and learns nothing else",
        [$e[0], $e[2], strpos($e[1], 'not available') === false, $p[0], $p[2], bk_counts($x)], [302, '?page=login', true, 302, '?page=login', $cn]);
};
$anon($s, 'Uganda with the switch on', $loginTok);
$matrix('an accountant', 'acc', '', 'danger: Access denied.', 'Access denied.');
$matrix('a sales user', 'sal', '', 'danger: Access denied.', 'Access denied.');
$s->login('boss2', 'rh-admin2@example.test', 'sj-password-1');                  // a fresh session copy: inside its 300-second window
is_(bk_seg($page('boss2', 'page=dashboard&tab=bank')) !== '', 'control: the second admin sees the screen before the demotion');
$s->update($s->ids['boss2'], ['is_admin' => false, 'role' => 'accountant']);
$matrix('an admin demoted inside the session window (the session copy still says admin)', 'boss2', $tabDeny, 'danger: Access denied.', 'Access denied.');
$setCfg(['bank_screen_enabled' => '0']);
$matrix('Uganda with the switch off', 'boss', $gateOff, 'danger: The Bank screen is not available on this installation.', 'The Bank screen is not available on this installation.');
$anon($s, 'Uganda with the switch off', $loginTok);
$setCfg(['bank_screen_enabled' => '1', 'cashbook_currencies' => 'UGX,USD,SSP']);
$matrix('a Uganda book where SSP is bookable', 'boss', $gateOff, 'danger: The Bank screen is not available on this installation.', 'The Bank screen is not available on this installation.');
$anon($s, 'a Uganda book where SSP is bookable', $loginTok);
$setCfg([]);
is_(bk_seg($page('boss', 'page=dashboard&tab=bank')) !== '', 'control: the switch back on, the screen is back');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nL. The emergency repair page\n";
file_put_contents($s->data . '/emergency_repair_key.txt', "sj-repair-key-0123456789-abcdefghijklmnop\n");
$rep = function (string $t) use ($s): array {
    return $s->http('GET', "{$s->base}?page=emergency_repair&key=sj-repair-key-0123456789-abcdefghijklmnop&action=drop_table&t={$t}&confirm=YES_DROP_{$t}");
};
foreach (['bank_account_profiles', 'bank_checks', 'bank_statements', 'bank_statement_lines', 'bank_line_events'] as $tb) {
    $r = $rep($tb);
    is_($r[0] === 200 && strpos($r[1], 'REFUSED') !== false && strpos($r[1], 'Dropping table') === false
        && bk_n($s, "SELECT COUNT(*) n FROM sqlite_master WHERE type = 'table' AND name = ?", [$tb]) === 1, "drop_table refuses {$tb}, and it is still there", substr($r[1], -160));
}
$s->q('CREATE TABLE IF NOT EXISTS rh_throwaway (id INTEGER)');
$r = $rep('rh_throwaway');
// The control is that the LIST refuses the bank tables: an unlisted table gets past it to the DROP itself. (The page's
// DROP then fails on its own still-open sqlite_master cursor — "database table is locked" — on every table: a defect of
// the page that predates this release, recorded, not changed here.)
is_(strpos($r[1], 'REFUSED') === false && strpos($r[1], 'Dropping table: rh_throwaway') !== false,
    'control: an unlisted throwaway table passes the list and reaches the DROP', substr($r[1], -300));
$pdo = bk_pdo($s); $pdo->exec('DROP TABLE IF EXISTS rh_throwaway'); unset($pdo);
t('the throwaway table is gone again', bk_n($s, "SELECT COUNT(*) n FROM sqlite_master WHERE name = 'rh_throwaway'"), 0);
@unlink($s->data . '/emergency_repair_key.txt');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nM. Nothing from a statement in any flash, log, address, session, data file or other table\n";
$mk = array_map(function ($l) { $l['Account'] = '0000000003'; return $l; }, array_slice($HM, 0, 30));
$cm1 = $post('boss', ['bk_acct' => (string)$SP], [g_file($mk, 'UGX', ['marker' => BK_MARK]), BK_FNAME]);
$km1 = $post('boss', ['bk_action' => 'keep', 'bk_acct' => (string)$SP, 'bk_check' => (string)$cm1['check'], 'bk_link' => '1']);
is_(strpos($km1['flash'], 'success: Kept: 30 new lines added') === 0 && bk_n($s, "SELECT COUNT(*) n FROM bank_statement_lines WHERE remarks LIKE 'RHMARKER%'") === 30,
    'a marker file is kept on the spare account (its lines hold the marker, as they should)', $km1['flash']);
$post('boss', ['bk_action' => 'discard', 'bk_acct' => (string)$SP, 'bk_check' => (string)$spCheck]);
$hit = function (string $x): array { $o = []; foreach (array_merge(BK_LEAKS, [BK_FNAME]) as $n) if (strpos($x, $n) !== false) $o[] = $n; return $o; };
t('no flash of the whole run held the marker, the account, the holder or the file\'s name', $hit(implode("\n", $FLASHES)), []);
is_(count($FLASHES) >= 40, 'control: the run read ' . count($FLASHES) . ' flashes');
t('nor any cell the escaping lines carried (<script>, "><img>, =HYPERLINK, @SUM, a Reference), nor a Remarks',
    array_values(array_filter(['<script', '<img', 'HYPERLINK', '@SUM', '=RH1', '<b>RH', 'RH-CLIENT', 'RH LONG PARTICULARS'], function ($n) use ($FLASHES) { return strpos(implode("\n", $FLASHES), $n) !== false; })), []);
$addr = [];
foreach ($SEGS as $g) {
    preg_match_all('#(?:href|action)="(\?[^"]*)"#', $g, $am);
    foreach ($am[1] as $u) $addr[] = html_entity_decode($u, ENT_QUOTES);
}
foreach ($LOCS as $u) if ($u !== '' && $u[0] === '?') $addr[] = $u;
$badAddr = [];
$shape = ['page' => '/^dashboard$/', 'tab' => '/^bank$/', 'bk_acct' => '/^\d{1,12}$/', 'bk_view' => '/^(lines|statements)$/', 'bk_month' => '/^(\d{4}-\d{2})?$/',
    'bk_page' => '/^\d{1,6}$/', 'bk_check' => '/^\d{1,12}$/', 'bk_export' => '/^csv$/'];
foreach (array_unique($addr) as $u) {
    parse_str(substr($u, 1), $q);
    if (($q['tab'] ?? '') !== 'bank') continue;
    foreach ($q as $k => $v) if (!isset($shape[$k]) || !is_string($v) || !preg_match($shape[$k], $v)) $badAddr[] = $u;
}
t('every address the screen links to or redirects to carries only bk_acct, bk_view, bk_month, bk_page, bk_check and bk_export, each a number, a word or a month',
    array_values(array_unique($badAddr)), []);
is_(count(array_unique($addr)) > 20, 'control: ' . count(array_unique($addr)) . ' distinct addresses were read');
$fields = [];
foreach ($SEGS as $g) {
    preg_match_all('#<form method="get"[^>]*>(.*?)</form>#s', $g, $fm);
    foreach ($fm[1] as $form) { preg_match_all('#<(?:input|select|textarea)\b[^>]*\bname="([^"]*)"#', $form, $nm); foreach ($nm[1] as $n) $fields[$n] = true; }
}
ksort($fields);
t('the GET form carries only the page, the tab, the account and the month: no search box', array_keys($fields), ['bk_acct', 'bk_month', 'page', 'tab']);
is_(!array_filter($SEGS, function ($g) { return preg_match('#<input[^>]*type="(search|text)"#', $g) === 1 || strpos($g, 'bk_q') !== false; }), 'no page of the screen holds a text or search input, or bk_q');
$files = [];
$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($s->data, \FilesystemIterator::SKIP_DOTS));
foreach ($it as $fi) {
    $rel = substr($fi->getPathname(), strlen($s->data) + 1);
    if (strpos($rel, 'bank_statements/') === 0 || preg_match('/^plugin\.sqlite3(-wal|-shm)?$/', $rel)) continue;
    $hits = $hit((string)file_get_contents($fi->getPathname()));
    if ($hits) $files[$rel] = $hits;
}
t('no data file outside the statement folder and the database holds them (logs, JSON, caches)', $files, []);
$tabs = [];
$pdo = bk_pdo($s);
foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN) as $tn) {
    if ($tn === 'bank_statement_lines') continue;
    $hits = $hit((string)json_encode($pdo->query('SELECT * FROM "' . $tn . '"')->fetchAll(\PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($hits) $tabs[$tn] = $hits;
}
unset($pdo);
t('no table but the lines holds them (checks, events, statements, profiles and every other table)', $tabs, []);
// The sessions live in data/sessions (public.php), where the flash waits before it is shown: read each one of this run.
$sess = 0; $sessHits = [];
foreach (glob($s->data . '/sessions/sess_*') ?: [] as $sf) {
    $sess++;
    if ($hit((string)file_get_contents($sf))) $sessHits[] = basename($sf);
}
is_($sess >= 4, "control: the run's session files are read ({$sess})");
t('no session file holds them', $sessHits, []);
$gSp = bk_seg($page('boss', 'page=dashboard&tab=bank&bk_acct=' . $SP));
is_(strpos($gSp, BK_MARK) !== false && strpos($gSp, BK_GEN_ACCOUNT) === false && strpos($gSp, BK_GEN_HOLDER) === false,
    'the kept marker lines show their Remarks (what the screen is for), never the account number or the holder');

// ═══════════════════════════════════════════════════════════════════════════
echo "\nN. Posts nothing\n";
$digAfter = $dig();
foreach (['cb_ledger', 'cb_accounts', 'fin_audit'] as $tb) t("{$tb}: the same rows, byte for byte (count and sha256 in rowid order)", $digAfter[$tb], $digBefore[$tb]);
t('cashInHand() per currency and project is unchanged', $digAfter['cash'], $digBefore['cash']);
t('no object outside the bank_* tables changed in sqlite_master', $digAfter['master'], $digBefore['master']);
foreach ($PAGES as $lbl => $qs) {
    $a = bk_read_norm($s, 'boss', $qs);
    $same = $a === $before[$lbl];
    $where = '';
    if (!$same) { $i = 0; $n = min(strlen($a), strlen($before[$lbl])); while ($i < $n && $a[$i] === $before[$lbl][$i]) $i++; $where = "first difference at byte {$i}: " . json_encode(substr($before[$lbl], max(0, $i - 40), 120)) . ' → ' . json_encode(substr($a, max(0, $i - 40), 120)); }
    is_($same, "{$lbl} renders as before every Bank act (per-run values normalised)", $where);
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\nO. Migration 088 not applied\n";
try {
    $pdo = bk_pdo($s);
    $pdo->exec('PRAGMA legacy_alter_table = ON');                            // the schema holds an unrelated broken view
    $pdo->exec('ALTER TABLE bank_line_events RENAME TO rh_bank_line_events_away');
    unset($pdo);
} catch (\Throwable $e) {
    is_(false, 'the events table can be moved aside for this case', get_class($e) . ' ' . $e->getMessage());
}
$cnO = bk_n($s, 'SELECT COUNT(*) n FROM bank_checks');
$hO = $page('boss', 'page=dashboard&tab=bank');
$gO = bk_seg($hO);
is_(strpos($gO, 'The Bank screen is not ready on this installation.') !== false && strpos($gO, 'data-bk-open') === false && strpos($gO, 'bk-chip') === false,
    'the screen fails closed: "not ready", no account, no upload');
$pO = $post('boss', ['bk_acct' => (string)$U], [g_file(array_slice($H, 0, 5)), BK_FNAME]);
t('a check is refused and writes nothing', [$pO['flash'], bk_n($s, 'SELECT COUNT(*) n FROM bank_checks')], ['danger: The Bank screen is not ready on this installation. Nothing was kept.', $cnO]);
try {
    $pdo = bk_pdo($s);
    $pdo->exec('PRAGMA legacy_alter_table = ON');
    $pdo->exec('ALTER TABLE rh_bank_line_events_away RENAME TO bank_line_events');
    unset($pdo);
} catch (\Throwable $e) {
    is_(false, 'the events table is put back', get_class($e) . ' ' . $e->getMessage());
}
is_(bk_seg($page('boss', 'page=dashboard&tab=bank')) !== '' && strpos(bk_seg($page('boss', 'page=dashboard&tab=bank')), 'bk-chip') !== false, 'control: the table back, the screen is back');
$s->stop();

// ═══════════════════════════════════════════════════════════════════════════
echo "\nP. South Sudan\n";
$SS = ['tenant_profile' => 'south-sudan', 'timezone' => 'Africa/Juba', 'currency_code' => 'USD', 'currency_symbol' => '$',
       'cashbook_base_currency' => 'USD', 'cashbook_currencies' => 'USD,SSP', 'bank_screen_enabled' => '1'];
$ssBook = function (SjSandbox $x): void {
    $x->staff('boss', ['name' => 'RH-Admin One', 'email' => 'rh-admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '+211900009931']);
    $x->login('boss', 'rh-admin@example.test', 'sj-password-1');
    $c = new CashbookService($x->store(), $x->data);
    $c->addEntry(['project' => 'dishnet', 'date' => '2026-09-01', 'direction' => 'in', 'amount' => 700, 'currency' => 'USD', 'category' => 'Receipt',
        'description' => 'RH-Client One payment', 'validation_status' => 'na'], ['name' => 'RH-Admin One']);
    $c->addEntry(['project' => 'dishnet', 'date' => '2026-09-02', 'direction' => 'out', 'amount' => 9000, 'currency' => 'SSP', 'category' => 'Misc Expense',
        'description' => 'RH SSP spending', 'validation_status' => 'na'], ['name' => 'RH-Admin One']);
};
$ss = SjSandbox::start($root, $SS, 'bkt3ss');
$GLOBALS['dataDir'] = $ss->data;
$ssBook($ss);
$ssCsrf = preg_match('/name="_csrf" value="([^"]+)"/', $ss->page('boss', 'page=dashboard&tab=retailers'), $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
$hs = $ss->page('boss', 'page=dashboard&tab=bank');
is_(bk_seg($hs) === '' && strpos($hs, $gateOff) !== false, 'an admin on South Sudan (the switch set) gets the fixed refusal and no screen');
$r = bk_req($ss, 'POST', 'page=dashboard&tab=bank', 'boss', ['_csrf' => $ssCsrf, 'bk_action' => 'check', 'bk_acct' => '1'], [], [g_file(array_slice($H, 0, 5)), BK_FNAME]);
t('a POST is refused with fixed text', bk_flash_of($ss->page('boss', ltrim($r[2], '?'))), 'danger: The Bank screen is not available on this installation.');
$x = bk_req($ss, 'GET', 'page=dashboard&tab=bank&bk_acct=1&bk_export=csv', 'boss');
t('the export is refused', [$x[0], trim($x[1])], [403, 'The Bank screen is not available on this installation.']);
$anon($ss, 'South Sudan', $ssCsrf);
t('088\'s five tables exist on South Sudan and stay empty', bk_counts($ss), ['bank_account_profiles' => 0, 'bank_checks' => 0, 'bank_statements' => 0, 'bank_statement_lines' => 0, 'bank_line_events' => 0]);
is_(!is_dir($ss->data . '/bank_statements'), 'no statement folder was made on South Sudan');
$ssCfg = array_merge($ss->cfg, ['cashbook_currencies' => 'USD']);
file_put_contents($ss->data . '/kyc_config.json', json_encode($ssCfg));
$ss->store()->save('kyc_config.json', $ssCfg);
is_(strpos($ss->page('boss', 'page=dashboard&tab=bank'), $gateOff) !== false, 'a South Sudan book without SSP: the Uganda half alone refuses');
file_put_contents($ss->data . '/kyc_config.json', json_encode($ss->cfg));
$ss->store()->save('kyc_config.json', $ss->cfg);
if ($withCompare) {
    [$base, $why] = isset($opt['base']) ? [rtrim((string)$opt['base'], '/'), ''] : sj_baseline_tree('419e2ffd');
    if ($base === null) {
        $skip++;
        echo "  skip the two-tree comparison: {$why} (a skip, not a pass)\n";
    } else {
        $bs = SjSandbox::start($base, $SS, 'bkt3b');
        $GLOBALS['dataDir'] = $bs->data;
        $ssBook($bs);
        $GLOBALS['dataDir'] = $ss->data;
        $cmp = ['the CEO page' => 'page=dashboard', 'the Cashbook' => 'page=dashboard&tab=cashbook', 'the Cashbook (SSP)' => 'page=dashboard&tab=cashbook&cb_curr=SSP',
            'the Cashbook CSV' => 'page=dashboard&tab=cashbook&cb_export=csv', 'the landing page' => 'page=dashboard&tab=accounts_dashboard',
            'the staff page' => 'page=dashboard&tab=retailers', 'the SSP Cashbook' => 'page=dashboard&tab=ssp_cashbook'];
        foreach ($cmp as $lbl => $qs) {
            $a = bk_read_norm($bs, 'boss', $qs); $b = bk_read_norm($ss, 'boss', $qs);
            $i = 0; $n = min(strlen($a), strlen($b)); while ($i < $n && $a[$i] === $b[$i]) $i++;
            is_($a === $b, "South Sudan, {$lbl}: identical to 419e2ffd (5.18.96)", "first difference at byte {$i} of " . strlen($a) . '/' . strlen($b));
            if ($lbl === 'the CEO page') $verCtl = [substr_count($a, 'v<version>') >= 1, substr_count($b, 'v<version>') >= 1, preg_match('/\bv5\.18\.9\d\b/', $a . $b)];
        }
        t('control: the version label, each tree\'s own, is on both CEO pages and normalised (no v5.18.9x left)', $verCtl ?? null, [true, true, 0]);
        $a = bk_read_norm($bs, 'boss', 'page=dashboard&tab=bank'); $b = bk_read_norm($ss, 'boss', 'page=dashboard&tab=bank');
        $b2 = str_replace($gateOff, '', $b);
        $b2 = (string)preg_replace('#<div style="text-align:right;font-size:9px;color:\#cbd5e1;font-weight:700;letter-spacing:\.5px;margin-bottom:4px;user-select:none;">.*?</div>#s', '', $b2, 1);
        $i = 0; $n = min(strlen($a), strlen($b2)); while ($i < $n && $a[$i] === $b2[$i]) $i++;
        is_($a === $b2 && $b !== $a, 'South Sudan, ?tab=bank: 419e2ffd\'s page plus exactly the fixed refusal (and the dispatcher\'s watermark)',
            "first difference at byte {$i}: " . json_encode(substr($a, max(0, $i - 80), 200)) . ' → ' . json_encode(substr($b2, max(0, $i - 80), 200)));
        $bs->stop();
    }
}
$ss->stop();

// ═══════════════════════════════════════════════════════════════════════════
if ($withMutants) {
    echo "\nQ. Weakened copies of the code each fail this test; the unchanged copy passes\n";
    $baseArg = '';
    if ($withCompare) { [$bt, ] = sj_baseline_tree('419e2ffd'); if ($bt !== null) $baseArg = ' --base=' . escapeshellarg($bt); }
    $child = function (string $tree, bool $compare) use ($baseArg): array {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tree) . ' --no-mutants'
            . ($compare && $baseArg !== '' ? $baseArg : ' --no-compare') . ' 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        $tally = preg_match('/^(\d+) passed, (\d+) failed$/', (string)end($out), $tm) ? [(int)$tm[1], (int)$tm[2]] : null;
        return [$rc, $fails, $tally];
    };
    $TAB = 'tabs/accounts/bank.php'; $PB = 'includes/post/post_bank.php'; $RT = 'includes/routes.php'; $GT = 'lib/BankGate.php'; $PUB = 'public.php';
    $MUTANTS = [
        ['BW25 BankGate::open() without the switch', [[$GT, 'return self::halves($cfg, (string)$dataDir) && self::switchOn($cfg);', 'return self::halves($cfg, (string)$dataDir);']]],
        ['BW26a BankGate without the Uganda half', [[$GT, 'return StaffJobsGate::applies($cfg, $dataDir) && !dn_ssp_selectable($cfg);', 'return !dn_ssp_selectable($cfg);']]],
        ['BW26b BankGate without the no-SSP half', [[$GT, 'return StaffJobsGate::applies($cfg, $dataDir) && !dn_ssp_selectable($cfg);', 'return StaffJobsGate::applies($cfg, $dataDir);']]],
        ['BW27 allows() reading the session copy', [[$GT, "\$fresh = \$store->findOne('retailers.json', 'id', \$id);", '$fresh = $sessionRetailer;']]],
        ['BW29 csrfCheck() called and ignored', [[$PB, "if (!csrfCheck() || CustomerSession::crossSite() || !BankGate::fromOwnPage('POST')) {",
            "csrfCheck(); if (CustomerSession::crossSite() || !BankGate::fromOwnPage('POST')) {"]]],
        ['BW30 crossSite() left out', [[$PB, "if (!csrfCheck() || CustomerSession::crossSite() || !BankGate::fromOwnPage('POST')) {",
            "if (!csrfCheck() || !BankGate::fromOwnPage('POST')) {"]]],
        ['BW30b fromOwnPage() left out of the handler (BK-SEC-2)', [[$PB, "if (!csrfCheck() || CustomerSession::crossSite() || !BankGate::fromOwnPage('POST')) {",
            'if (!csrfCheck() || CustomerSession::crossSite()) {']]],
        ['fromOwnPage() passing a POST that shows nothing', [[$GT, "        return \$get;\n", "        return true;\n"]]],
        ['fromOwnPage() passing a sibling host (same-site)', [[$GT, "if (\$sfs !== '') return \$sfs === 'same-origin' || (\$get && \$sfs === 'none');",
            "if (\$sfs !== '') return \$sfs === 'same-origin' || \$sfs === 'same-site' || (\$get && \$sfs === 'none');"]]],
        ['the export without fromOwnPage() (BK-SEC-1)', [[$RT, "    if (!BankGate::fromOwnPage('GET')) \$_bkxRefuse(403, 'The security check failed, so nothing was sent. Open the Bank screen and export from there.');\n", '']]],
        ['the export\'s gate before the sign-in (BK-SEC-4)', [[$RT, "    \$_bkxUser = \$auth->requireLogin();\n    if (!BankGate::open(\$dataDir ?? null)) \$_bkxRefuse(403, 'The Bank screen is not available on this installation.');\n",
            "    if (!BankGate::open(\$dataDir ?? null)) \$_bkxRefuse(403, 'The Bank screen is not available on this installation.');\n    \$_bkxUser = \$auth->requireLogin();\n"]]],
        ['the handler\'s gate before the sign-in (BK-SEC-4)', [[$PB, "    \$_bkpUser = \$auth->requireLogin();\n    if (!BankGate::open(\$dataDir ?? null)) {\n        flash('The Bank screen is not available on this installation.', 'danger');\n        redirect('?page=dashboard');\n    }\n",
            "    if (!BankGate::open(\$dataDir ?? null)) {\n        flash('The Bank screen is not available on this installation.', 'danger');\n        redirect('?page=dashboard');\n    }\n    \$_bkpUser = \$auth->requireLogin();\n"]]],
        ['BW31 $_tabPerms[\'bank\'] left out', [[$PUB, "    'bank'                 => '*admin',                               // 5.18.97: never absent (absent = any signed-in user)\n", '']]],
        ['BW32 the card read from cb_ledger', [[$TAB, '$_bkLast = $_bkStore->latestLine($_bkId);',
            "\$_bkLast = \$_bkStore->latestLine(\$_bkId); if (\$_bkLast !== null) { \$_bkLast['running_units'] = (int)round(100 * (float)\$store->getPdo()->query('SELECT COALESCE(SUM(CASE WHEN direction = \\'in\\' THEN amount ELSE -amount END), 0) FROM cb_ledger WHERE account_id = ' . \$_bkId)->fetchColumn()); }"]]],
        ['BW33 the two accounts summed on the card', [[$TAB, '$_bkLast = $_bkStore->latestLine($_bkId);',
            "\$_bkLast = \$_bkStore->latestLine(\$_bkId); if (\$_bkLast !== null) { foreach (\$_bkAccts as \$_bkO) { if ((int)\$_bkO['id'] !== \$_bkId && (\$_bkOL = \$_bkStore->latestLine((int)\$_bkO['id'])) !== null) \$_bkLast['running_units'] += (int)\$_bkOL['running_units']; } }"]]],
        ['BW35 innerHTML in the tab', [[$TAB, "name.textContent = file.files && file.files[0] ? file.files[0].name : '';", "name.innerHTML = file.files && file.files[0] ? file.files[0].name : '';"]]],
        ['BW36 a free-text bk_q search box', [[$TAB, '<button type="submit" class="cb3-filter-btn">Show</button>', '<input type="search" name="bk_q" placeholder="Search"><button type="submit" class="cb3-filter-btn">Show</button>']]],
        ['BW37 the file\'s name in the flash', [[$PB, "flash('The file passed every check. Nothing is kept until you press Keep.', 'success');",
            "flash('The file passed every check (' . h((string)(\$_FILES['bk_file']['name'] ?? '')) . '). Nothing is kept until you press Keep.', 'success');"]]],
        ['BW50 the bank_* tables off the emergency repair list', [[$PUB, "            'bank_account_profiles', 'bank_checks', 'bank_statements', 'bank_statement_lines', 'bank_line_events',\n", '']]],
        ['the export without its formula neutralising', [[$RT, "return (\$s !== '' && strpos(\"=+-@\\t\\r\", \$s[0]) !== false) ? \"'\" . \$s : \$s;", 'return $s;']]],
        ['the export newest first', [[$RT, "\$_bkxRows = \$_bkxStore->lines((int)\$_bkxAcct['id'], \$_bkxMonth, 'asc');", "\$_bkxRows = \$_bkxStore->lines((int)\$_bkxAcct['id'], \$_bkxMonth, 'desc');"]]],
        ['the export of the first page only', [[$RT, "\$_bkxRows = \$_bkxStore->lines((int)\$_bkxAcct['id'], \$_bkxMonth, 'asc');", "\$_bkxRows = \$_bkxStore->lines((int)\$_bkxAcct['id'], \$_bkxMonth, 'asc', 50);"]]],
        ['the export\'s file name carries the account\'s name', [[$RT, "\$_bkxName = 'ecobank-' . \$_bkxCur", "\$_bkxName = 'ecobank-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string)\$_bkxAcct['name']) . '-' . \$_bkxCur"]]],
        ['the export without the permission check', [[$RT, "    if (\$_bkxMe === null) \$_bkxRefuse(403, 'Access denied.');\n", '']]],
        ['the POST handler acting on the session copy', [[$PB, "\$_bkpMe = BankGate::allows('bank.import', \$store, is_array(\$_bkpUser) ? \$_bkpUser : []);", '$_bkpMe = $_bkpUser;']]],
        ['Keep without the link confirmation', [[$PB, "(\$_POST['bk_link'] ?? '') === '1'", 'true']]],
        ['the Remarks cell unescaped', [[$TAB, "<?= h(\$_bkCut((string)\$_bkL['remarks'], 65)) ?>", "<?= \$_bkCut((string)\$_bkL['remarks'], 65) ?>"]]],
        ['the rows oldest first', [[$TAB, "\$_bkStore->lines(\$_bkId, \$_bkMonth, 'desc', \$_bkPer", "\$_bkStore->lines(\$_bkId, \$_bkMonth, 'asc', \$_bkPer"]]],
        ['the month strip\'s opening without the first line\'s own amount', [['lib/BankStatementStore.php',
            "\$opening = (int)\$first['running_units'] - (int)\$first['credit_units'] + (int)\$first['debit_units'];", "\$opening = (int)\$first['running_units'];"]]],
    ];
    $mainPass = $pass + 1;                                                  // the child runs A–P and the end check R
    [$tmp, $n] = sj_weakened_copy($lib, $TAB, '$_bkPer   = 50;', '$_bkPer   = 50;');
    if ($n === 1) {
        [$rc, $fails, $tally] = $child($tmp, $withCompare);
        is_($rc === 0 && $fails === [] && $tally === [$mainPass, 0],
            "the unchanged copy passes: {$mainPass} passed, 0 failed, as this run", 'exit ' . $rc . ' tally ' . json_encode($tally) . ' ' . ($fails[0] ?? ''));
    } else {
        is_(false, 'the unchanged copy: its anchor occurs once', "found {$n} times");
    }
    exec('rm -rf ' . escapeshellarg($tmp));
    foreach ($MUTANTS as [$label, $edits]) {
        [$rel0, $old0, $new0] = $edits[0];
        [$tmp, $n] = sj_weakened_copy($lib, $rel0, $old0, $new0);
        $src = (string)@file_get_contents($tmp . '/' . $rel0);
        $applied = $n === 1 && ($new0 === '' || strpos($src, $new0) !== false);
        $lint = [];
        if ($applied && substr($rel0, -4) === '.php') { exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp . '/' . $rel0) . ' 2>&1', $lint, $lrc); if ($lrc !== 0) $applied = false; }
        if (!$applied) {
            is_(false, "weakened copy \"{$label}\": its anchor occurs once, the change is in the copy and it lints", "found {$n} times");
            exec('rm -rf ' . escapeshellarg($tmp));
            continue;
        }
        [$rc, $fails, $tally] = $child($tmp, strpos($label, 'BW26') === 0);
        is_($rc !== 0 && $fails !== [], "caught: {$label}", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        echo '         ' . count($fails) . ' assertion(s) failed' . ($tally ? " ({$tally[0]} passed)" : '') . ($fails ? '; first: ' . trim(substr($fails[0], 7, 110)) : '') . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

// ═══════════════════════════════════════════════════════════════════════════
echo "\nR. The end check\n";
$defAfter = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;
is_($defAfter === $defBefore, 'the default vault beside this checkout is untouched (' . ($defBefore === null ? 'still absent' : 'same bytes and mtime') . ')');
if ($skip > 0) echo "  ({$skip} skip(s): reported, never counted as passes)\n";
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

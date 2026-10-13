<?php
/**
 * bank-recon-p0.php — P0 of docs/68: measure, READ ONLY, what "cash in hand" would read under each proposed treatment of
 * bank accounts, before anything is decided or built. Run by scripts/bank-recon-p0.sh, which pipes this file into the
 * container's PHP on standard input (nothing is copied into the container) as the database's owner:
 *
 *   php -d display_errors=stderr -- <data dir> <plugin dir> [--adjustment-id=N]  < scripts/bank-recon-p0.php
 *
 * Why it cannot write:
 *   - it loads NO plugin code. CashbookService's constructor alters cb_ledger and its bookBase() refreshes the
 *     configuration vault; SqliteStore::create() runs migrations. None of them is touched;
 *   - SQLite opens the database with SQLITE_OPEN_READONLY and immutable=1, then PRAGMA query_only=ON (checked). Immutable,
 *     SQLite takes no lock and makes no file: a plain read-only open of this WAL-mode book would MAKE its -wal and -shm
 *     when they are missing and leave them behind (measured; docs/07, 5.18.91), and could not be stopped from doing so;
 *   - so it reads only while the plugin has the book closed — no -wal, no -shm: then the file holds every committed row,
 *     because SQLite removes the -wal only after copying all of it back. It waits up to two minutes for that moment;
 *   - an immutable reader is not protected from a writer, so it reads the book twice, on two connections, and the result
 *     only counts if both readings are identical, the file's inode, size and times did not move, and no side file appeared;
 *   - every query goes through q(): one statement, beginning SELECT or WITH, inside one read transaction (BEGIN … ROLLBACK);
 *   - each reading ends with total_changes() — the rows that connection changed — and fails unless it is 0;
 *   - the only file functions used are is_file, is_dir, is_readable, is_writable, realpath, stat and file_get_contents
 *     (the three configuration files and the vault, for the book's currencies).
 *
 * What it prints: aggregates only — counts and net totals per currency and per kind of account, the figure the Cashbook
 * hero shows today, and that figure under four candidate definitions. Never a description, a person, a reference or a
 * customer; account names only for bank, cash and mobile-money accounts, with any run of 4+ digits masked.
 *
 * Exit: 0 done; 2 refused before reading (wrong folder, no database, the book kept open, unexpected schema); 3 a check
 * failed.
 */
declare(strict_types=1);

const CAPITAL_CATEGORIES = ['Opening Balance', 'Share Capital', 'Loan Received', 'Loan Given', 'Loan Return Received',
    'Interco In', 'Interco Out', 'Bank Transfer', 'Exchange', 'Staff Advance', 'SSP Advance', 'SSP Return', 'Advance Return'];
const CAPITAL_TXN_TYPES  = ['OPENING_BALANCE', 'DIRECTOR_FUNDING', 'TRANSFER', 'ADJUSTMENT'];
const ADJ_DATE           = '2026-09-12';
const WAIT_SECONDS       = 120;

function out(string $s = ''): void { echo $s, "\n"; }
function refuse(int $code, string $why): void { out("P0: REFUSED — {$why}. Nothing was written."); exit($code); }
function fail(string $why): void { out(''); out("P0: FAILED — {$why}. Nothing was written."); exit(3); }
function money(float $v): string { return number_format(round($v, 2), 2); }
/** A short label for the log: printable, at most 40 characters, and any run of 4+ digits (an account or phone number) masked. */
function label(?string $v): string {
    $v = preg_replace('/[^\p{L}\p{N} &().,\/_+\x{2013}-]/u', '?', (string)$v) ?? '?';
    $v = preg_replace('/\p{N}{4,}/u', '####', $v) ?? '?';
    return preg_replace('/^(.{39}).+$/us', '$1…', $v) ?? '?';
}
/** A date or a month for the log, or "?" when it is not one. */
function day(?string $v): string { return preg_match('/^\d{4}-\d{2}(-\d{2})?$/', (string)$v) ? (string)$v : '?'; }

$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => $a !== '--'));
$adjArg = null;
foreach ($args as $i => $a) {
    if (preg_match('/^--adjustment-id=(\d{1,10})$/', $a, $m)) { $adjArg = (int)$m[1]; unset($args[$i]); }
    elseif (strpos($a, '--') === 0) refuse(2, "unknown option {$a}");
}
$args = array_values($args);
if (count($args) !== 2) refuse(2, 'usage: <data dir> <plugin dir> [--adjustment-id=N]');
[$dataDir, $pluginDir] = $args;
$dataReal = realpath($dataDir); $plugReal = realpath($pluginDir);
if ($dataReal === false || !is_dir($dataReal)) refuse(2, "no data folder at {$dataDir}");
if ($plugReal !== false && $dataReal === rtrim($plugReal, '/') . '/data') {
    refuse(2, 'the data folder is <plugin>/data — the stray store\'s old place, which is never opened');
}
$db = $dataReal . '/plugin.sqlite3';
if (!is_file($db)) refuse(2, "no plugin.sqlite3 in {$dataReal}");
if (strpbrk($db, '?#%') !== false) refuse(2, 'the database path holds ? # or %, which an SQLite URI cannot carry');

// ── The book's currencies ────────────────────────────────────────────────────────────────────────────────────────
// cashInHand() takes its base from CashbookService::bookBase(): PluginConfig::load() — the three files merged, a later
// file winning a key even when it is empty; then the vault fills a key that is missing or empty; strings trimmed — and
// dn_book_base() on that. Rebuilt here reading only (load() itself would refresh the vault). The pages list currencies
// from the store's settings row instead; that row is read below and any disagreement is printed.
// dn_book_base() / dn_book_currencies(): a key that is absent or null falls back ('??') to dn_book_effective_config().
function bookBase(array $c, array $eff): string {
    $b = strtoupper(trim((string)($c['cashbook_base_currency'] ?? ($eff['cashbook_base_currency'] ?? ''))));
    return preg_match('/^[A-Z]{3}$/', $b) ? $b : 'USD';
}
function bookCurrencies(array $c, array $eff): array {
    $base = bookBase($c, $eff); $list = [];
    $raw  = strtoupper(trim((string)($c['cashbook_currencies'] ?? ($eff['cashbook_currencies'] ?? ''))));
    foreach ($raw === '' ? ['USD', 'SSP'] : explode(',', $raw) as $x) {
        $x = trim($x);
        if (preg_match('/^[A-Z]{3}$/', $x) && !in_array($x, $list, true)) $list[] = $x;
    }
    return array_values(array_unique(array_merge([$base], array_diff($list, [$base]))));
}
$plugRoot = $plugReal ?: rtrim($pluginDir, '/');
$cfg = []; $cfgFrom = []; $eff = [];   // $eff: dn_book_effective_config()'s merge, where a later EMPTY value never erases
foreach ([$plugRoot . '/data/config.json', $dataReal . '/config.json', $dataReal . '/kyc_config.json'] as $p) {
    if (!is_file($p) || !is_readable($p)) continue;
    $d = json_decode((string)@file_get_contents($p), true);
    if (!is_array($d)) continue;
    foreach (['cashbook_base_currency', 'cashbook_currencies'] as $k) {
        if (!array_key_exists($k, $d)) continue;
        $cfg[$k] = $d[$k]; $cfgFrom[$k] = basename($p);
        if (!(is_string($d[$k]) && trim($d[$k]) === '' && isset($eff[$k]) && trim((string)$eff[$k]) !== '')) $eff[$k] = $d[$k];
    }
}
$vaultFile = (string)getenv('DN_VAULT_FILE');
if ($vaultFile === '') {
    $parent    = dirname($plugRoot);
    $vaultFile = (is_dir($parent) && is_writable($parent)) ? $parent . '/.dishnet-sudan.vault.json' : $dataReal . '/config_vault.json';
}
if (is_file($vaultFile)) {
    $vd = json_decode((string)@file_get_contents($vaultFile), true);
    $vault = is_array($vd) && is_array($vd['config'] ?? null) ? $vd['config'] : [];
    foreach (['cashbook_base_currency', 'cashbook_currencies'] as $k) {
        $missing = !array_key_exists($k, $cfg) || (is_string($cfg[$k]) && trim($cfg[$k]) === '');
        if ($missing && array_key_exists($k, $vault)) { $cfg[$k] = $vault[$k]; $cfgFrom[$k] = 'the vault'; }
    }
    unset($vd, $vault);
}
$base = bookBase($cfg, $eff);
$curs = bookCurrencies($cfg, $eff);
unset($cfg);

// ── Wait for a moment when the plugin has the book closed ────────────────────────────────────────────────────────
function sides(string $db): bool { clearstatcache(); return is_file($db . '-wal') || is_file($db . '-shm') || is_file($db . '-journal'); }
function fileId(string $f): string { clearstatcache(); $s = @stat($f); return $s ? "{$s['ino']}|{$s['size']}|{$s['mtime']}|{$s['ctime']}" : '-'; }
$t0 = microtime(true);
while (sides($db)) {
    if (microtime(true) - $t0 > WAIT_SECONDS) {
        refuse(2, 'the plugin kept the book open for ' . WAIT_SECONDS . ' s (its -wal or -shm stayed beside it); run again in a few minutes');
    }
    usleep(250000);
}
$waited = microtime(true) - $t0;

function q(PDO $pdo, string $sql, array $p = []): array {
    $s = ltrim($sql);
    if (!preg_match('/^(SELECT|WITH)\b/i', $s) || strpos(rtrim($s, "; \n\t"), ';') !== false) {
        fail('a statement that is not a single SELECT was refused');
    }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * One currency, one scope: today's figure exactly as CashbookService::cashInHand($cur, $project) computes it (the same
 * rows, the same arithmetic), and the same rows split by where they sit. $project '' = all projects.
 */
function measure(PDO $pdo, string $cur, string $base, int $adjId, string $accJoin, string $live, string $project): array {
    $amt  = $cur === 'SSP' ? 'COALESCE(l.ssp_amount,0)' : 'l.amount';
    $curP = $cur === $base ? "(l.currency = :c OR l.currency IS NULL OR l.currency = '')" : 'l.currency = :c';
    $prjP = $project === '' ? '' : ' AND l.project = :p';
    $bind = [':c' => $cur] + ($project === '' ? [] : [':p' => $project]);
    $today = (float)(q($pdo, "SELECT ROUND(COALESCE(SUM(CASE WHEN l.direction='in' THEN {$amt} ELSE -{$amt} END), 0), 2) AS bal
                               FROM cb_ledger l WHERE l.{$live} AND {$curP}{$prjP}", $bind)[0]['bal'] ?? 0);
    $cls = ['U' => [0, 0.0], 'ADJ' => [0, 0.0], 'CASH' => [0, 0.0], 'MOMO' => [0, 0.0], 'BANK' => [0, 0.0], 'OTHER' => [0, 0.0], 'ORPHAN' => [0, 0.0]];
    foreach (q($pdo, "SELECT CASE WHEN l.id = :adj THEN 'ADJ'
                                  WHEN COALESCE(l.account_id,0) = 0 THEN 'U'
                                  WHEN a.id IS NULL THEN 'ORPHAN'
                                  WHEN a.kind = 'cash' THEN 'CASH' WHEN a.kind = 'momo' THEN 'MOMO' WHEN a.kind = 'bank' THEN 'BANK'
                                  ELSE 'OTHER' END AS cls,
                             COUNT(*) AS n, COALESCE(SUM(CASE WHEN l.direction='in' THEN {$amt} ELSE -{$amt} END), 0) AS net
                        FROM cb_ledger l {$accJoin} WHERE l.{$live} AND {$curP}{$prjP} GROUP BY cls",
                $bind + [':adj' => $adjId]) as $r) {
        $cls[$r['cls']] = [(int)$r['n'], (float)$r['net']];
    }
    $sum = 0.0; foreach ($cls as $v) $sum += $v[1];
    $U = $cls['U'][1]; $A = $cls['ADJ'][1]; $C = $cls['CASH'][1] + $cls['MOMO'][1];
    return ['today' => $today, 'cls' => $cls, 'sum' => $sum, 'ok' => abs(round($sum, 2) - $today) < 0.005,
            'opts' => ['A' => $U + $A + $C, 'B' => $U + $C, 'C' => $U + $A, 'D' => $U]];
}

/** One reading of the book, on its own immutable read-only connection: the lines to print, and its own checks. */
function readBook(string $db, string $base, array $curs, array $eff, array $cfgFrom, ?int $adjArg): array {
    $L = [];
    $o = static function (string $s = '') use (&$L): void { $L[] = $s; };
    try {
        $pdo = new PDO('sqlite:file:' . $db . '?immutable=1', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 15,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
        $pdo->exec('PRAGMA query_only = ON');
        $pdo->exec('BEGIN');
    } catch (Throwable $e) {
        refuse(2, 'the database could not be opened read-only and immutable (' . get_class($e) . ')');
    }
    $qo = (int)(q($pdo, 'SELECT * FROM pragma_query_only')[0]['query_only'] ?? 0);
    if ($qo !== 1) fail('query_only is not on for this connection');

    $tables = array_column(q($pdo, "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('cb_ledger','cb_accounts','kyc_config')"), 'name');
    if (!in_array('cb_ledger', $tables, true)) refuse(2, 'no cb_ledger table');
    $cols = array_column(q($pdo, "SELECT name FROM pragma_table_info('cb_ledger')"), 'name');
    foreach (['id', 'date', 'direction', 'amount', 'currency', 'status', 'project', 'category', 'description', 'source', 'account_id', 'txn_type', 'validation_ref', 'ssp_amount'] as $c) {
        if (!in_array($c, $cols, true)) refuse(2, "cb_ledger has no {$c} column — not the schema this was written for");
    }
    $hasAcc  = in_array('cb_accounts', $tables, true);
    $accJoin = $hasAcc ? 'LEFT JOIN cb_accounts a ON a.id = l.account_id' : 'LEFT JOIN (SELECT NULL AS id, NULL AS kind) a ON 0';
    $nRows   = (int)q($pdo, 'SELECT COUNT(*) AS n FROM cb_ledger')[0]['n'];
    $nAcc    = $hasAcc ? (int)q($pdo, 'SELECT COUNT(*) AS n FROM cb_accounts')[0]['n'] : 0;
    $live    = "status NOT IN ('voided','voided_reconcile')";

    $o('  database        ' . $db . " — read-only, immutable, query_only=1; cb_ledger {$nRows} rows, cb_accounts {$nAcc} rows");
    $o('  book            base ' . $base . ' (' . ($cfgFrom['cashbook_base_currency'] ?? 'default') . '), as cash in hand decides it; currencies '
        . implode(',', $curs) . ' (' . ($cfgFrom['cashbook_currencies'] ?? 'default') . ')');
    $kyc = in_array('kyc_config', $tables, true) ? q($pdo, 'SELECT data FROM kyc_config WHERE id = 0 LIMIT 1') : [];
    $row = $kyc ? json_decode((string)$kyc[0]['data'], true) : null;
    unset($kyc);
    if (is_array($row)) {
        $rowBase = bookBase($row, $eff); $rowCurs = bookCurrencies($row, $eff);
        unset($row);
        $o('                  the pages\' settings row: base ' . $rowBase . ', currencies ' . implode(',', $rowCurs)
            . ($rowBase === $base && $rowCurs === $curs ? ' — the same' : ' — DIFFERENT: every currency of both is measured below; blank-currency rows count as ' . $base));
        foreach ($rowCurs as $c) if (!in_array($c, $curs, true)) $curs[] = $c;
    } else {
        $o('                  the pages\' settings row: none');
    }

    // ── Accounts: names only for money accounts ──────────────────────────────────────────────────────────────────
    if ($hasAcc) {
        $o('  accounts        id   kind         cur  active  rows  name');
        foreach (q($pdo, "SELECT a.id, a.name, a.kind, a.currency, a.active,
                                  (SELECT COUNT(*) FROM cb_ledger l WHERE l.account_id = a.id AND l.{$live}) AS n
                             FROM cb_accounts a ORDER BY a.id") as $a) {
            $money = in_array((string)$a['kind'], ['bank', 'cash', 'momo'], true);
            $o(sprintf('                  %-4d %-12s %-4s %-6s %5d  %s', (int)$a['id'], label((string)$a['kind']), label((string)$a['currency']),
                ((int)$a['active'] === 1 ? 'yes' : 'no'), (int)$a['n'], $money ? label((string)$a['name']) : '(name withheld: not a money account)'));
        }
    }

    // ── The 12 Sep adjustment ────────────────────────────────────────────────────────────────────────────────────
    $adj = null;
    if ($adjArg !== null) {
        $r = q($pdo, "SELECT * FROM cb_ledger WHERE id = ? AND COALESCE(account_id,0) = 0 AND {$live}", [$adjArg]);
        if (count($r) !== 1) fail("--adjustment-id={$adjArg} is not a live, unassigned row");
        $adj = $r[0]; $adjHow = 'named by --adjustment-id';
    } else {
        $r = q($pdo, "SELECT * FROM cb_ledger WHERE date LIKE ? AND COALESCE(account_id,0) = 0 AND {$live}
                        AND (UPPER(COALESCE(description,'')) LIKE '%ADJUSTMENT%' OR UPPER(COALESCE(category,'')) LIKE '%ADJUST%')",
                    [ADJ_DATE . '%']);
        if (count($r) === 1) { $adj = $r[0]; $adjHow = 'found: the one live unassigned row of ' . ADJ_DATE . ' named an adjustment'; }
        else $adjHow = count($r) . ' candidate(s) on ' . ADJ_DATE . ' — not identified; run again with --adjustment-id=N';
    }
    $adjId = $adj ? (int)$adj['id'] : -1;
    if ($adj) {
        $typ  = strtoupper(trim((string)$adj['txn_type']));
        $inPl = (string)$adj['status'] === 'approved'
            && ($typ !== '' ? !in_array($typ, CAPITAL_TXN_TYPES, true) : !in_array((string)$adj['category'], CAPITAL_CATEGORIES, true));
        $o("  12 Sep adjust.  {$adjHow}: id {$adjId}, " . day(substr((string)$adj['date'], 0, 10)) . ', ' . label((string)$adj['direction'])
            . ', category "' . label((string)$adj['category']) . '", ' . ($typ === '' ? 'untyped' : label($typ)) . ', source ' . label((string)($adj['source'] ?: '-'))
            . ', ' . label((string)$adj['status']) . ', project ' . label((string)$adj['project']) . ', currency ' . label((string)(($adj['currency'] ?? '') ?: '(blank = base)')));
        $o('                  in the P&L of ' . day(substr((string)$adj['date'], 0, 7)) . ': ' . ($inPl
            ? 'YES — voiding it would move that month\'s P&L by its full amount'
            : 'no — approved capital/typed row or not approved: voiding it would not move the P&L'));
        unset($r);
    } else {
        $o("  12 Sep adjust.  {$adjHow}");
    }

    // ── The earlier bank import ──────────────────────────────────────────────────────────────────────────────────
    $bi = q($pdo, "SELECT COUNT(*) AS n,
                          SUM(CASE WHEN status IN ('voided','voided_reconcile') THEN 1 ELSE 0 END) AS v,
                          MIN(substr(date,1,10)) AS d1, MAX(substr(date,1,10)) AS d2,
                          SUM(CASE WHEN validation_ref GLOB 'BANK-[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]' THEN 1 ELSE 0 END) AS h,
                          SUM(CASE WHEN validation_ref LIKE 'BANK-%' THEN 1 ELSE 0 END) AS b
                     FROM cb_ledger WHERE source = 'bank_import'")[0];
    $biOn = q($pdo, "SELECT COALESCE(a.kind,'(none)') AS k, COUNT(*) AS n FROM cb_ledger l {$accJoin}
                      WHERE l.source = 'bank_import' GROUP BY k ORDER BY k");
    $o(sprintf('  earlier import  %d row(s), %d voided; dated %s to %s; on %s; references: BANK-<hash> %d, other BANK- %d, none %d',
        (int)$bi['n'], (int)$bi['v'], $bi['d1'] === null ? '-' : day((string)$bi['d1']), $bi['d2'] === null ? '-' : day((string)$bi['d2']),
        $biOn ? implode(', ', array_map(static fn($r) => label((string)$r['k']) . ' ' . $r['n'], $biOn)) : '-',
        (int)$bi['h'], (int)$bi['b'] - (int)$bi['h'], (int)$bi['n'] - (int)$bi['b']));

    // ── Per currency ─────────────────────────────────────────────────────────────────────────────────────────────
    $extra = [];
    foreach (q($pdo, "SELECT COALESCE(currency,'') AS c, COUNT(*) AS n FROM cb_ledger WHERE {$live} GROUP BY c") as $x) {
        $c = (string)$x['c'];
        if ($c === '' || in_array($c, $curs, true)) continue;
        $extra[] = label($c) . " {$x['n']}";
    }
    $checks = [];
    foreach ($curs as $cur) {
        $amt  = $cur === 'SSP' ? 'COALESCE(l.ssp_amount,0)' : 'l.amount';
        $curP = $cur === $base ? "(l.currency = :c OR l.currency IS NULL OR l.currency = '')" : 'l.currency = :c';
        $m  = measure($pdo, $cur, $base, $adjId, $accJoin, $live, '');
        $md = measure($pdo, $cur, $base, $adjId, $accJoin, $live, 'dishnet');
        $cls = $m['cls'];
        $checks[] = "{$cur} " . ($m['ok'] && $md['ok'] ? 'ok' : 'MISMATCH');
        $pend = ['pending_approval' => [0, 0.0], 'rejected' => [0, 0.0]];
        foreach (q($pdo, "SELECT l.status AS s, COUNT(*) AS n, COALESCE(SUM(CASE WHEN l.direction='in' THEN {$amt} ELSE -{$amt} END), 0) AS net
                            FROM cb_ledger l WHERE l.{$live} AND {$curP} AND l.status IN ('pending_approval','rejected') GROUP BY l.status",
                     [':c' => $cur]) as $r) {
            $pend[$r['s']] = [(int)$r['n'], (float)$r['net']];
        }
        $comp = q($pdo, "SELECT CASE WHEN UPPER(TRIM(COALESCE(l.txn_type,''))) = '' THEN 'untyped' ELSE UPPER(TRIM(l.txn_type)) END AS t,
                                COUNT(*) AS n, COALESCE(SUM(CASE WHEN l.direction='in' THEN {$amt} ELSE -{$amt} END), 0) AS net
                           FROM cb_ledger l {$accJoin} WHERE l.{$live} AND {$curP} AND a.kind IN ('cash','momo') GROUP BY t ORDER BY t",
                  [':c' => $cur]);

        $o('');
        $o("{$cur}  cash in hand today (the Cashbook hero, the dashboard and the evening WhatsApp; all projects)   " . money($m['today']));
        $o('       rows by where they sit                               rows                 net');
        $row = static function (string $l, array $v) use ($o): void { $o(sprintf('       %-50s %6d %19s', $l, $v[0], money($v[1]))); };
        $row('unassigned (no account), without the adjustment', $cls['U']);
        $row('the 12 Sep adjustment', $cls['ADJ']);
        $row('cash accounts', $cls['CASH']);
        $row('mobile-money accounts', $cls['MOMO']);
        $row('bank accounts', $cls['BANK']);
        $row('other accounts (inventory, asset, director ...)', $cls['OTHER']);
        if ($cls['ORPHAN'][0] > 0) $row('rows naming an account that does not exist', $cls['ORPHAN']);
        $o('       the parts add up to today\'s figure: ' . ($m['ok'] ? 'yes' : 'NO — ' . money($m['sum'])));
        $o(sprintf('       of these, pending approval %d (net %s) and rejected %d (net %s) — today\'s figure counts both',
            $pend['pending_approval'][0], money($pend['pending_approval'][1]), $pend['rejected'][0], money($pend['rejected'][1])));
        $o('       cash and mobile-money accounts by type: ' . ($comp
            ? implode(' · ', array_map(static fn($r) => label((string)$r['t']) . ' ' . $r['n'] . ' (' . money((float)$r['net']) . ')', $comp)) : 'none'));
        $labels = [
            'A' => 'bank and other accounts out, adjustment kept      ',
            'B' => 'bank and other accounts out, adjustment out       ',
            'C' => 'unassigned rows only, adjustment kept             ',
            'D' => 'unassigned rows only, adjustment out              ',
        ];
        $delta = static function (float $v, float $t): string { $d = round($v - $t, 2);
            return $d == 0.0 ? 'same' : ($d > 0 ? '+' : '') . money($d); };
        $o('       cash in hand under each option                          figure          vs today');
        foreach ($labels as $k => $label) {
            if (!$adj && in_array($k, ['B', 'D'], true)) { $o("       {$k}  {$label}  (not computed: the adjustment was not identified)"); continue; }
            $o(sprintf('       %s  %s %15s %17s', $k, $label, money($m['opts'][$k]), $delta($m['opts'][$k], $m['today'])));
        }
        $line = [];
        foreach (array_keys($labels) as $k) {
            $line[] = (!$adj && in_array($k, ['B', 'D'], true)) ? "{$k} -" : "{$k} " . $delta($md['opts'][$k], $md['today']);
        }
        $o('       the Cashbook page (project dishnet, its default) today ' . money($md['today']) . '; vs today: ' . implode(' · ', $line)
            . ($md['ok'] ? '' : ' — PARTS DO NOT ADD UP'));
    }
    if ($extra) { $o(''); $o('  other currencies in the book, not shown on the hero: ' . implode(', ', $extra) . ' row(s)'); }

    $changed = (int)(q($pdo, 'SELECT total_changes() AS n')[0]['n'] ?? -1);
    $qo2     = (int)(q($pdo, 'SELECT * FROM pragma_query_only')[0]['query_only'] ?? 0);
    $pdo->exec('ROLLBACK');
    $pdo = null;
    return ['lines' => $L, 'checks' => $checks, 'changed' => $changed, 'qo' => $qo2];
}

// ── Two readings, compared ───────────────────────────────────────────────────────────────────────────────────────
$before = fileId($db);
$r1 = readBook($db, $base, $curs, $eff, $cfgFrom, $adjArg);
$r2 = readBook($db, $base, $curs, $eff, $cfgFrom, $adjArg);
$after   = fileId($db);
$sidesNow = sides($db);
foreach ($r1['lines'] as $l) out($l);
out('');
out('  checks          the parts add up: ' . implode(', ', $r1['checks']) . "; the connections changed {$r1['changed']} and {$r2['changed']} row(s); query_only={$r1['qo']}");
out('                  read twice, immutable' . ($waited >= 0.5 ? sprintf(' (after waiting %.0f s for the plugin to close the book)', $waited) : '')
    . ': the readings are ' . ($r1['lines'] === $r2['lines'] ? 'identical' : 'DIFFERENT')
    . '; the database file ' . ($after === $before ? 'did not move (inode, size, times)' : 'MOVED during the reading')
    . '; side files ' . ($sidesNow ? 'APPEARED' : 'none, before or after'));
if ($r1['changed'] !== 0 || $r2['changed'] !== 0 || $r1['qo'] !== 1 || $r2['qo'] !== 1) fail('the connection is not provably read-only');
if ($r1['lines'] !== $r2['lines'] || $after !== $before || $sidesNow) {
    fail('the plugin opened or wrote the book while P0 read it, so these figures may not be one moment of the book; run again');
}
if (in_array(false, array_map(static fn($c) => substr($c, -2) === 'ok', $r1['checks']), true)) fail('the parts do not add up to today\'s figure');
out('P0: DONE — read only; nothing was written.');

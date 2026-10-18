<?php
declare(strict_types=1);
/**
 * test_cash_in_hand_p3b.php — P3b (5.18.96; D2 = A, docs/69): on a Uganda book, cash in hand is the approved money in
 * the cash and mobile-money accounts plus every approved row on no account. Bank, counterpart and orphan rows, and
 * pending or rejected ones, are out. Wherever the rule is not in force — South Sudan, the two misconfigured books, a
 * book whose gate cannot decide — the figure and the Balance column are 5.18.94's.
 *
 * In process, on synthetic books only: no exec(), no shell, no socket. Every name and amount is invented. It proves:
 *   0. its own vault and data folder, set before any service is built. ConfigVault follows DN_VAULT_FILE, never
 *      DN_DATA_DIR, and run from a plugin folder the default vault is the live one (lib/ConfigVault.php:126-148);
 *   1. book U, a Uganda book built through the plugin's writers plus the raw rows no writer can make: each row moves
 *      the figure by exactly its amount or by 0, by its class. The same row moves 5.18.94's rule as recorded, so a row
 *      that was never written cannot pass as a 0;
 *   2. the class-presence control: book U holds a row of every class, so an empty book cannot pass;
 *   3. two independent oracles equal cashInHand() per currency and scope: P0's class SQL (scripts/bank-recon-p0.php
 *      :155-172, status = 'approved'), and a row-by-row reading that treats a text account id as an orphan;
 *   4. E17: in every view the top Balance of each stream equals cash in hand, keyed by _bal_currency; a row has a
 *      Balance exactly when it is inside the rule; the row list is buildWhere()'s; and the UGX-only view's one
 *      exception, a newer blank-currency row, asserted apart;
 *   5. E9: putting the adjustment-shaped row on the bank moves the figure by exactly that row;
 *   6. E1: a legacy cb_ledger whose account_id is nullable keeps it so, and its NULL row counts;
 *   7. E15: with no cb_accounts only unassigned rows count, nothing throws, and sqlite_master is untouched; then, on
 *      the same instance, a cash account created afterwards and a row posted on it count (the table-exists memo caches
 *      only true);
 *   8. E11: every movement in the map-definition table, under A and, as the contrast, under 5.18.94's rule;
 *   9. the truth table: cashOnlyRuleInForce() per book, and 5.18.94's SQL wherever the rule is off;
 *  10. the end check: every vault written is this test's own, and a vault at the default path is untouched.
 *
 * Runs from any code copy that holds lib/ AND profiles/ (deploy A22 and R25 run it from one), never from a plugin folder
 * holding a live book. The Uganda gate reads profiles/<id>.json beside lib/ (TenantProfile::load()); without it every
 * book reads as rule-off, so a copy without profiles/ is refused up front, by name.
 *
 *   php test_cash_in_hand_p3b.php [--root=DIR]
 */
$opt  = getopt('', ['root:']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}
function t1_threw(string $where, \Throwable $e): void {
    is_(false, "{$where}: ran to the end without an error", get_class($e) . ': ' . substr($e->getMessage(), 0, 220));
}

// A plugin folder with a configuration or a book beside its code would hand its settings to every book below, and the
// vault refresh would copy them into this test's temporary folder. Refuse, before anything is built.
if (is_file($root . '/data/config.json') || is_file($root . '/data/kyc_config.json') || is_file($root . '/data/plugin.sqlite3')) {
    is_(false, 'this test runs from a code copy, never from a plugin folder holding a configuration or a book', $root . '/data');
    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit(1);
}

// The gate reads profiles/<id>.json beside lib/ (TenantProfile::load(), lib/TenantProfile.php). On a copy without them
// StaffJobsGate::applies() answers false for every book, and every rule-A assertion below would fail for a reason none
// of them names. Refuse, by name, before anything is built.
foreach (['uganda', 'south-sudan'] as $t1Prof) {
    if (!is_array(json_decode((string)@file_get_contents($root . '/profiles/' . $t1Prof . '.json'), true))) {
        is_(false, "this code copy holds profiles/{$t1Prof}.json (the Uganda gate reads it; without profiles/ every book reads as rule-off)", $root . '/profiles');
        printf("\n%d passed, %d failed\n", $pass, $fail);
        exit(1);
    }
}

// ── 0. Its own vault and data folder, before any service exists ──────────────
$T = sys_get_temp_dir() . '/t1-cih-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($T . '/vaults', 0700, true);
mkdir($T . '/data-env', 0700, true);
mkdir($T . '/books', 0700, true);
/** @var array<string,bool> every vault file this test named */
$T1_VAULTS = [];
function t1_vault(string $file): void {
    global $T1_VAULTS;
    $T1_VAULTS[$file] = true;
    putenv('DN_VAULT_FILE=' . $file);
}
t1_vault($T . '/vaults/boot.json');
putenv('DN_DATA_DIR=' . $T . '/data-env');

/** sha256, mtime and size of a file, or null when it does not exist. */
function t1_fstate(string $f): ?array {
    clearstatcache();
    if (!is_file($f)) return null;
    return [hash_file('sha256', $f), filemtime($f), filesize($f)];
}
$defVault  = dirname($root) . '/.dishnet-sudan.vault.json';   // ConfigVault::path() when DN_VAULT_FILE is unset
$defBefore = t1_fstate($defVault);

function t1_rmtree(string $d): void {
    if (!is_dir($d) || is_link($d)) { @unlink($d); return; }
    foreach (scandir($d) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        t1_rmtree($d . '/' . $e);
    }
    @rmdir($d);
}
// Removed last of all: each SqliteStore registers a checkpoint at shutdown, so this one queues itself behind them.
register_shutdown_function(function () use ($T) {
    register_shutdown_function(function () use ($T) { t1_rmtree($T); });
});

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CashbookService.php';

const T1_SCOPES     = ['', 'dishnet', '4g', 'bluecard'];
const T1_CASH_KINDS = ['cash', 'momo'];   // written here, not read from the class: the oracle stays independent
const T1_ME         = 'RH-Tester';

$UG_CFG = ['tenant_profile' => 'uganda', 'currency_code' => 'UGX', 'currency_symbol' => 'UGX',
           'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD'];

/**
 * A synthetic book in its own data folder, with its own vault. The configuration is written after the store exists
 * (SqliteStore::create() imports any *.json in a fresh folder), and is loaded once, at once, under this book's vault:
 * bookBase() and the gate are memoised per instance, so nothing later reads another book's vault.
 */
function t1_book(string $name, array $cfg, ?string $vaultText = null, ?callable $prep = null, bool $evalGate = true): array {
    global $T;
    $dir = $T . '/books/' . $name;
    mkdir($dir, 0700, true);
    $store = SqliteStore::create($dir);
    $pdo   = $store->getPdo();
    if ($prep !== null) $prep($pdo);
    $cb = new CashbookService($store, $dir);
    file_put_contents($dir . '/config.json', json_encode($cfg));
    $vault = $T . '/vaults/' . $name . '.json';
    if ($vaultText !== null) file_put_contents($vault, $vaultText);
    t1_vault($vault);
    $base = $cb->bookBase();
    $rule = $evalGate ? t1_rule($cb) : null;
    return ['name' => $name, 'dir' => $dir, 'store' => $store, 'pdo' => $pdo, 'cb' => $cb, 'base' => $base,
            'rule' => $rule, 'vault' => $vault];
}

/** The service's own gate; null where the code has none (5.18.94), so the test reports instead of crashing. */
function t1_rule(CashbookService $cb): ?bool {
    return method_exists($cb, 'cashOnlyRuleInForce') ? $cb->cashOnlyRuleInForce() : null;
}

function t1_raw(PDO $pdo, array $r): int {
    $r += ['project' => 'dishnet', 'status' => 'approved', 'category' => 'Misc Expense', 'category_raw' => 'Misc Expense',
           'source' => 'manual', 'approved_by' => T1_ME, 'txn_type' => '',
           'created_at' => '2026-10-01 09:00:00', 'updated_at' => '2026-10-01 09:00:00'];
    $cols = array_keys($r);
    $st = $pdo->prepare('INSERT INTO cb_ledger (' . implode(',', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')');
    $st->execute(array_values($r));
    return (int)$pdo->lastInsertId();
}

function t1_entry(CashbookService $cb, string $date, string $dir, float $amt, string $cur, string $proj, int $acct,
                  string $desc, string $status = 'approved', array $extra = []): string {
    return $cb->addEntryRaw($extra + ['project' => $proj, 'date' => $date, 'direction' => $dir, 'amount' => $amt,
        'currency' => $cur, 'category' => $dir === 'in' ? 'Receipt' : 'Misc Expense',
        'category_raw' => $dir === 'in' ? 'Receipt' : 'Misc Expense', 'description' => $desc,
        'status' => $status, 'approved_by' => T1_ME, 'source' => 'manual', 'account_id' => $acct]);
}

function t1_id_by_desc(PDO $pdo, string $desc): int {
    $st = $pdo->prepare('SELECT id FROM cb_ledger WHERE description = ? ORDER BY id');
    $st->execute([$desc]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    return count($ids) === 1 ? (int)$ids[0] : 0;
}

function t1_has_accounts(PDO $pdo): bool {
    return (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='cb_accounts'")->fetchColumn() > 0;
}

/** Every ledger row, with the storage class of its account id: the oracles never cast an id. */
function t1_rows(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT id, date, direction, amount, ssp_amount, currency, status, project, account_id,
                                 typeof(account_id) AS acc_type FROM cb_ledger ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['id'] = (int)$r['id'];
        $out[] = $r;
    }
    return $out;
}

/** @return array<int,string> account id => kind, exactly as stored */
function t1_kinds(PDO $pdo): array {
    if (!t1_has_accounts($pdo)) return [];
    $k = [];
    foreach ($pdo->query('SELECT id, kind FROM cb_accounts')->fetchAll(PDO::FETCH_ASSOC) as $a) $k[(int)$a['id']] = (string)$a['kind'];
    return $k;
}

// ── The oracles ───────────────────────────────────────────────────────────────

/**
 * Rule A's location term, row by row (E1–E6). An account id stored as NULL or the integer 0 is unassigned; an
 * integer naming an account of kind exactly cash or momo is in; anything else — an orphan, a negative id, any text
 * ('' and 'abc' alike, E4) — is out. A text id is never cast.
 */
function t1_loc_in(array $r, array $kinds): bool {
    $t = (string)$r['acc_type'];
    if ($t === 'null') return true;
    if ($t === 'integer') {
        $v = (int)$r['account_id'];
        if ($v === 0) return true;
        return isset($kinds[$v]) && in_array($kinds[$v], T1_CASH_KINDS, true);
    }
    if ($t === 'real') {   // none is written here; SQLite would compare it numerically
        $v = (float)$r['account_id'];
        if ($v == 0.0) return true;
        foreach ($kinds as $id => $k) if ((float)$id === $v) return in_array($k, T1_CASH_KINDS, true);
        return false;
    }
    return false;
}
function t1_live(array $r): bool { return !in_array((string)$r['status'], ['voided', 'voided_reconcile'], true); }
function t1_in_rule(array $r, array $kinds): bool { return (string)$r['status'] === 'approved' && t1_loc_in($r, $kinds); }
function t1_counts(array $r, array $kinds, bool $ruleA): bool { return t1_live($r) && (!$ruleA || t1_in_rule($r, $kinds)); }

function t1_amount(array $r, string $cur): float {
    return $cur === 'SSP' ? (float)($r['ssp_amount'] ?? 0) : (float)$r['amount'];
}

/** cashInHand() row by row: rule A, or 5.18.94's rule (every row not voided, any account). */
function t1_hero_php(array $rows, array $kinds, string $base, string $cur, string $proj, bool $ruleA): float {
    $sum = 0.0;
    foreach ($rows as $r) {
        if (!t1_counts($r, $kinds, $ruleA)) continue;
        if ($proj !== '' && (string)$r['project'] !== $proj) continue;
        $c = $r['currency'];
        $match = $cur === $base ? ($c === $base || $c === null || $c === '') : ($c === $cur);
        if (!$match) continue;
        $a = t1_amount($r, $cur);
        $sum += $r['direction'] === 'in' ? $a : -$a;
    }
    return round($sum, 2);
}

/** 5.18.94's cashInHand() SQL, its text as at f4b4c96 (lib/CashbookService.php:671-686), run on the same book. */
function t1_today_sql(PDO $pdo, string $base, string $cur, string $project): float {
    $amt  = $cur === 'SSP' ? 'COALESCE(ssp_amount,0)' : 'amount';
    $sql  = "SELECT ROUND(COALESCE(SUM(CASE WHEN direction='in' THEN {$amt} ELSE -{$amt} END), 0), 2) AS bal
             FROM cb_ledger WHERE status NOT IN ('voided','voided_reconcile')"
          . ($cur === $base
              ? " AND (currency=" . $pdo->quote($base) . " OR currency IS NULL OR currency='')"
              : " AND currency=" . $pdo->quote($cur));
    $params = [];
    if ($project !== '') { $sql .= ' AND project=?'; $params[] = $project; }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetchAll(PDO::FETCH_ASSOC);
    return round((float)($row[0]['bal'] ?? 0), 2);
}

/** P0's statement gate (scripts/bank-recon-p0.php:142-150): a single SELECT, nothing else. */
function q(PDO $pdo, string $sql, array $p = []): array {
    $s = ltrim($sql);
    if (!preg_match('/^(SELECT|WITH)\b/i', $s) || strpos(rtrim($s, "; \n\t"), ';') !== false) {
        throw new \RuntimeException('a statement that is not a single SELECT was refused');
    }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * P0's class measurement. The body down to the class loop is scripts/bank-recon-p0.php:155-172 verbatim; the caller
 * swaps the status term to status = 'approved' (D2 = A as shipped, docs/69). A = U + ADJ + CASH + MOMO (P0 :177).
 */
function t1_p0_measure(PDO $pdo, string $cur, string $base, int $adjId, string $accJoin, string $live, string $project): array {
    $amt  = $cur === 'SSP' ? 'COALESCE(l.ssp_amount,0)' : 'l.amount';
    $curP = $cur === $base ? "(l.currency = :c OR l.currency IS NULL OR l.currency = '')" : 'l.currency = :c';
    $prjP = $project === '' ? '' : ' AND l.project = :p';
    $bind = [':c' => $cur] + ($project === '' ? [] : [':p' => $project]);
    $t = q($pdo, "SELECT ROUND(COALESCE(SUM(CASE WHEN l.direction='in' THEN {$amt} ELSE -{$amt} END), 0), 2) AS bal,
                         COALESCE(SUM(CASE WHEN l.direction='in' THEN {$amt} ELSE -{$amt} END), 0) AS raw
                    FROM cb_ledger l WHERE l.{$live} AND {$curP}{$prjP}", $bind)[0];
    $today = (float)($t['bal'] ?? 0); $raw = (float)($t['raw'] ?? 0);
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
    return ['A' => $cls['U'][1] + $cls['ADJ'][1] + $cls['CASH'][1] + $cls['MOMO'][1], 'cls' => $cls, 'raw' => $raw, 'bal' => $today];
}
function t1_p0_A(PDO $pdo, string $cur, string $base, int $adjId, string $project): float {
    // The join, or P0's dummy join when the book has no accounts table (scripts/bank-recon-p0.php:208).
    $accJoin = t1_has_accounts($pdo) ? 'LEFT JOIN cb_accounts a ON a.id = l.account_id' : 'LEFT JOIN (SELECT NULL AS id, NULL AS kind) a ON 0';
    return t1_p0_measure($pdo, $cur, $base, $adjId, $accJoin, "status = 'approved'", $project)['A'];
}

/**
 * getEntries()' running balances, row by row (lib/CashbookService.php getEntries, the f4b4c96 shape plus D2 = A):
 * the base or filtered stream; in the All view every other stored currency its own stream; SSP in ssp_amount and
 * to 0 dp. Under rule A only rows inside the rule run, and a base-currency filter takes blank-currency rows as the
 * hero does; under 5.18.94's rule a base filter matches the code exactly (N5).
 *
 * @return array<int,array{0:?float,1:string}> id => [running balance or null, _bal_currency]
 */
function t1_streams(array $rows, array $kinds, string $base, string $view, string $proj, bool $ruleA): array {
    $streamCur = $view !== '' ? $view : $base;
    $sorted = $rows;
    usort($sorted, function ($x, $y) { $c = strcmp((string)$x['date'], (string)$y['date']); return $c !== 0 ? $c : $x['id'] <=> $y['id']; });
    $run = 0.0; $baseMap = []; $otherRun = []; $otherMap = [];
    foreach ($sorted as $r) {
        if (!t1_counts($r, $kinds, $ruleA)) continue;
        if ($proj !== '' && (string)$r['project'] !== $proj) continue;
        $c = $r['currency'];
        $blank = ($c === null || $c === '');
        $inBase = ($view === '' || ($ruleA && $view === $base)) ? ($c === $base || $blank) : ($c === $streamCur);
        if ($inBase) {
            $a = t1_amount($r, $streamCur);
            $run += $r['direction'] === 'in' ? $a : -$a;
            $baseMap[$r['id']] = round($run, $streamCur === 'SSP' ? 0 : 2);
        }
        if ($view === '' && !$blank && $c !== $base) {
            $a = t1_amount($r, (string)$c);
            $otherRun[$c] = ($otherRun[$c] ?? 0.0) + ($r['direction'] === 'in' ? $a : -$a);
            $otherMap[$r['id']] = [round($otherRun[$c], $c === 'SSP' ? 0 : 2), (string)$c];
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $id = $r['id'];
        $out[$id] = ($view === '' && isset($otherMap[$id])) ? $otherMap[$id] : [$baseMap[$id] ?? null, $streamCur];
    }
    return $out;
}

/** The row list as buildWhere() has always made it (f4b4c96 :2040-2060), newest first. */
function t1_list_ids(PDO $pdo, string $proj, string $cur): array {
    $w = ["NOT (amount=0 AND sr='')", "status != 'voided_reconcile'"]; $p = [];
    if ($proj !== '') { $w[] = 'project=?';  $p[] = $proj; }
    if ($cur !== '')  { $w[] = 'currency=?'; $p[] = $cur; }
    $st = $pdo->prepare('SELECT id FROM cb_ledger WHERE ' . implode(' AND ', $w) . ' ORDER BY date DESC, id DESC');
    $st->execute($p);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** cashInHand() for both Uganda currencies in every scope. */
function t1_vec(CashbookService $cb, array $curs = ['UGX', 'USD']): array {
    $v = [];
    foreach ($curs as $c) foreach (T1_SCOPES as $s) $v["{$c}|{$s}"] = $cb->cashInHand($c, $s);
    return $v;
}
function t1_vec_today(PDO $pdo, string $base, array $curs = ['UGX', 'USD']): array {
    $v = [];
    foreach ($curs as $c) foreach (T1_SCOPES as $s) $v["{$c}|{$s}"] = t1_today_sql($pdo, $base, $c, $s);
    return $v;
}
function t1_minus(array $a, array $b): array {
    $o = [];
    foreach ($a as $k => $x) $o[$k] = round($x - ($b[$k] ?? 0.0), 2);
    return $o;
}
/** Each leg is [stored currency, project, signed amount, inside rule A, live under 5.18.94's rule]. */
function t1_expect(array $legs, string $base, int $flag, array $curs = ['UGX', 'USD']): array {
    $v = [];
    foreach ($curs as $c) foreach (T1_SCOPES as $s) $v["{$c}|{$s}"] = 0.0;
    foreach ($legs as $l) {
        if (!$l[$flag]) continue;
        $c = ($l[0] === '' || $l[0] === null) ? $base : $l[0];
        if (!in_array($c, $curs, true)) continue;   // a lowercase code joins no hero (E12)
        $v["{$c}|"]      += $l[2];
        $v["{$c}|{$l[1]}"] += $l[2];
    }
    foreach ($v as $k => $x) $v[$k] = round($x, 2);
    return $v;
}
function t1_vdiff(array $got, array $want): string {
    $bad = [];
    foreach ($want as $k => $w) {
        $g = $got[$k] ?? null;
        if ($g === null || abs($g - $w) >= 0.005) $bad[] = "{$k}: got " . ($g === null ? 'none' : number_format($g, 2, '.', '')) . ', want ' . number_format($w, 2, '.', '');
    }
    return implode('; ', $bad);
}
function t1_fmt(float $x): string { return ($x > 0 ? '+' : '') . number_format($x, 2, '.', ''); }

/**
 * E17 over every view and scope of a book: the row list is buildWhere()'s; every Balance and its currency are the
 * stream oracle's; under rule A a row has a Balance exactly when it is inside the rule; and, where asked, the top
 * Balance of each stream, keyed by _bal_currency, is cash in hand.
 */
function t1_views(array $B, array $views, array $heroes, bool $ruleA, bool $heroCheck): void {
    $cb = $B['cb']; $pdo = $B['pdo']; $base = $B['base'];
    $rows = t1_rows($pdo); $kinds = t1_kinds($pdo);
    $byId = [];
    foreach ($rows as $r) $byId[$r['id']] = $r;
    foreach ($views as $V) foreach (T1_SCOPES as $P) {
        $tag = "{$B['name']} " . ($V === '' ? 'All' : $V) . '/' . ($P === '' ? 'all projects' : $P);
        $got = $cb->getEntries(['project' => $P, 'currency' => $V, 'limit' => 100000]);
        $ids = array_map(function ($r) { return (int)$r['id']; }, $got);
        $want = t1_list_ids($pdo, $P, $V);
        is_($ids === $want && $cb->countFiltered(['project' => $P, 'currency' => $V]) === count($want),
            "{$tag}: the row list is buildWhere()'s (" . count($want) . ' rows, the same order)');

        $exp = t1_streams($rows, $kinds, $base, $V, $P, $ruleA);
        $bad = '';
        foreach ($got as $r) {
            $id = (int)$r['id'];
            [$ev, $ec] = $exp[$id] ?? [null, '?'];
            $gv = $r['running_balance'];
            $okV = ($ev === null) ? ($gv === null) : ($gv !== null && abs((float)$gv - $ev) < 0.005);
            if (!$okV || (string)$r['_bal_currency'] !== $ec) {
                $bad = "row {$id}: got " . var_export($gv, true) . '/' . $r['_bal_currency'] . ', want ' . var_export($ev, true) . "/{$ec}";
                break;
            }
        }
        is_($bad === '', "{$tag}: every Balance and its currency are the stream oracle's", $bad);

        if ($ruleA) {
            $in = 0; $out = 0; $bad = '';
            foreach ($got as $r) {
                $inside = t1_in_rule($byId[(int)$r['id']], $kinds);
                if ($inside !== ($r['running_balance'] !== null)) { $bad = 'row ' . $r['id'] . ($inside ? ' is inside the rule with no Balance' : ' is outside it with one'); break; }
                $inside ? $in++ : $out++;
            }
            is_($bad === '', "{$tag}: a row has a Balance exactly when it is inside the rule ({$in} inside, {$out} outside)", $bad);
        }

        if (!$heroCheck) continue;
        foreach ($V === '' ? $heroes : [$V] as $C) {
            if ($ruleA && $V !== '' && $V === $base) {
                // E17's precondition for the filtered base view: its stream's newest row is one the view lists.
                $last = null;
                foreach ($rows as $r) {
                    if (($exp[$r['id']][0] ?? null) === null) continue;
                    if ($last === null || strcmp((string)$r['date'], (string)$last['date']) > 0
                        || ((string)$r['date'] === (string)$last['date'] && $r['id'] > $last['id'])) $last = $r;
                }
                if ($last !== null && $last['currency'] !== $base) {
                    is_(false, "{$tag}: no blank-currency row of the stream is newer than its newest {$base} row (the precondition)", 'row ' . $last['id']);
                    continue;
                }
            }
            $top = null;
            foreach ($got as $r) {
                if ((string)$r['_bal_currency'] === $C && $r['running_balance'] !== null) { $top = (float)$r['running_balance']; break; }
            }
            $hero = $cb->cashInHand($C, $P);
            if ($top === null) is_(abs($hero) < 0.005, "{$tag}: no {$C} row carries a Balance, and {$C} cash in hand is 0", t1_fmt($hero));
            else is_(abs($top - $hero) < 0.005, "{$tag}: the top {$C} Balance is {$C} cash in hand (" . t1_fmt($hero) . ')',
                     'top ' . t1_fmt($top) . ', cash in hand ' . t1_fmt($hero));
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
echo "\n1. Book U — a Uganda book, built row by row; each row moves the figure by its amount or by 0\n";
$U = null; $acc = []; $STEPS = [];
try {
    $U = t1_book('U', $UG_CFG);
    $cbU = $U['cb']; $pdoU = $U['pdo'];
    $seed = $cbU->seedStandardAccounts();
    is_(($seed['ok'] ?? false) === true && count($seed['created'] ?? []) === 6, 'the standard Uganda chart is seeded (six accounts)');
    foreach ($cbU->accounts() as $a) $acc[(string)$a['name']] = (int)$a['id'];
    foreach ([['RH-Cash USD', 'USD', 'cash'], ['RH-Stock', 'UGX', 'inventory'], ['RH-Fixed Asset', 'UGX', 'asset'],
              ['RH-Share Capital', 'UGX', 'equity'], ['RH-Receivable', 'UGX', 'receivable'], ['RH-Payable', 'UGX', 'payable'],
              ['RH-Old Wallet', 'UGX', 'momo'], ['RH-Director USD', 'USD', 'director']] as [$n, $c, $k]) {
        $r = $cbU->addAccount($n, $c, $k);
        $acc[$n] = (int)($r['id'] ?? 0);
    }
    $cbU->setAccountActive($acc['RH-Old Wallet'], false);
    // addAccount() refuses both kinds (lib/CashbookService.php ACCOUNT_KINDS): raw rows, the only way to make them.
    $pdoU->exec("INSERT INTO cb_accounts (name, currency, kind) VALUES ('RH-Cash Capital K', 'UGX', 'Cash'), ('RH-Cash Spaced K', 'UGX', 'cash ')");
    $acc['RH-Cash Capital K'] = (int)$pdoU->query("SELECT id FROM cb_accounts WHERE name='RH-Cash Capital K'")->fetchColumn();
    $acc['RH-Cash Spaced K']  = (int)$pdoU->query("SELECT id FROM cb_accounts WHERE name='RH-Cash Spaced K'")->fetchColumn();
    is_(count(array_filter($acc)) === 16, 'sixteen accounts: the chart, seven more kinds and currencies, an inactive wallet, two odd kinds');
} catch (\Throwable $e) { t1_threw('setting up book U', $e); }
// The precondition of sections 1–9: rule A is in force on book U. Checked here, by name, so that a gate that is off —
// in the copy's code or its configuration — stops the run with that reason, not with every rule-A assertion below.
if ($U !== null) {
    is_($U['rule'] === true, 'rule A is in force on the Uganda book U (cashOnlyRuleInForce() is true) — the precondition of sections 1–9',
        'cashOnlyRuleInForce() = ' . var_export($U['rule'], true));
    if ($U['rule'] !== true) { printf("\n%d passed, %d failed\n", $pass, $fail); exit(1); }
}

/**
 * Book U, one act at a time: [class, what, the act, its legs]. A leg is [stored currency, project, signed amount,
 * inside rule A, live under 5.18.94's rule]. Raw SQL makes only what no writer can: an orphan id, text ids, the two
 * odd kinds' accounts, a USD row on a UGX cash account, a blank-currency row on an account, voided_reconcile.
 */
function t1_steps_u(array $U, array $a): array {
    $cb = $U['cb']; $pdo = $U['pdo']; $actor = ['name' => T1_ME];
    $e = function (string $date, string $dir, float $amt, string $cur, string $proj, int $acct, string $desc, string $status = 'approved') use ($cb): void {
        t1_entry($cb, $date, $dir, $amt, $cur, $proj, $acct, $desc, $status);
    };
    $ugxBank = $a['Ecobank Uganda – UGX']; $usdBank = $a['Ecobank Uganda – USD']; $cash = $a['Cash – Uganda'];
    $mtn = $a['MTN Mobile Money']; $airtel = $a['Airtel Money']; $dirU = $a['Director/Shareholder Funding'];
    return [
        ['cash', 'an opening balance on the cash account', function () use ($cb, $cash) { $cb->recordOpeningBalance($cash, 5000.00, '2026-09-01', 'RH-U opening cash', 'RH-OB-1', T1_ME); },
            [['UGX', 'dishnet', 5000.00, true, true]]],
        ['bank', 'an opening balance on the UGX bank account', function () use ($cb, $ugxBank) { $cb->recordOpeningBalance($ugxBank, 7000.00, '2026-09-01', 'RH-U opening bank', 'RH-OB-2', T1_ME); },
            [['UGX', 'dishnet', 7000.00, false, true]]],
        ['momo', 'an opening balance on a mobile-money account', function () use ($cb, $mtn) { $cb->recordOpeningBalance($mtn, 300.00, '2026-09-01', 'RH-U opening wallet', 'RH-OB-3', T1_ME); },
            [['UGX', 'dishnet', 300.00, true, true]]],
        ['director', 'an opening balance on the director account', function () use ($cb, $dirU) { $cb->recordOpeningBalance($dirU, 900.00, '2026-09-01', 'RH-U opening director', 'RH-OB-4', T1_ME); },
            [['UGX', 'dishnet', 900.00, false, true]]],
        ['bank', 'an opening balance on the USD bank account', function () use ($cb, $usdBank) { $cb->recordOpeningBalance($usdBank, 2500.00, '2026-09-01', 'RH-U opening USD bank', 'RH-OB-5', T1_ME); },
            [['USD', 'dishnet', 2500.00, false, true]]],
        ['cash', 'an opening balance on the USD cash account', function () use ($cb, $a) { $cb->recordOpeningBalance($a['RH-Cash USD'], 40.00, '2026-09-01', 'RH-U opening USD cash', 'RH-OB-6', T1_ME); },
            [['USD', 'dishnet', 40.00, true, true]]],
        ['unassigned', 'a UGX receipt on no account (dishnet)', function () use ($e) { $e('2026-09-03', 'in', 1210.55, 'UGX', 'dishnet', 0, 'RH-U receipt dishnet'); },
            [['UGX', 'dishnet', 1210.55, true, true]]],
        ['unassigned', 'a UGX expense on no account (4g)', function () use ($e) { $e('2026-09-03', 'out', 230.10, 'UGX', '4g', 0, 'RH-U expense 4g'); },
            [['UGX', '4g', -230.10, true, true]]],
        ['unassigned', 'a UGX receipt on no account (bluecard)', function () use ($e) { $e('2026-09-04', 'in', 150.00, 'UGX', 'bluecard', 0, 'RH-U receipt bluecard'); },
            [['UGX', 'bluecard', 150.00, true, true]]],
        ['cash', 'a UGX expense on the cash account', function () use ($e, $cash) { $e('2026-09-05', 'out', 120.25, 'UGX', 'dishnet', $cash, 'RH-U cash expense'); },
            [['UGX', 'dishnet', -120.25, true, true]]],
        ['momo', 'a UGX receipt on a mobile-money account (4g)', function () use ($e, $airtel) { $e('2026-09-05', 'in', 80.00, 'UGX', '4g', $airtel, 'RH-U wallet receipt'); },
            [['UGX', '4g', 80.00, true, true]]],
        ['inactive momo', 'a receipt on an inactive mobile-money account (E3: no active test)', function () use ($e, $a) { $e('2026-09-06', 'in', 61.00, 'UGX', 'dishnet', $a['RH-Old Wallet'], 'RH-U old wallet receipt'); },
            [['UGX', 'dishnet', 61.00, true, true]]],
        ['mismatched-currency cash row', 'a USD row on the UGX cash account counts in USD, its own currency (E5)', function () use ($pdo, $cash) {
                t1_raw($pdo, ['sr' => 'RH-RAW-1', 'date' => '2026-09-06', 'direction' => 'in', 'amount' => 17.50, 'currency' => 'USD', 'account_id' => $cash, 'description' => 'RH-U USD on UGX cash']); },
            [['USD', 'dishnet', 17.50, true, true]]],
        ['adjustment-shaped', 'an approved UGX out row on no account, 12 Sep (E9: in while unassigned)', function () use ($e) { $e('2026-09-12', 'out', 999.99, 'UGX', 'dishnet', 0, 'RH-U adjustment-shaped (invented)'); },
            [['UGX', 'dishnet', -999.99, true, true]]],
        ['bank', 'a UGX expense on the bank account', function () use ($e, $ugxBank) { $e('2026-09-07', 'out', 444.44, 'UGX', 'dishnet', $ugxBank, 'RH-U bank expense'); },
            [['UGX', 'dishnet', -444.44, false, true]]],
        ['inventory', 'a row on a stock account', function () use ($e, $a) { $e('2026-09-07', 'in', 55.55, 'UGX', 'dishnet', $a['RH-Stock'], 'RH-U stock'); },
            [['UGX', 'dishnet', 55.55, false, true]]],
        ['asset', 'a row on an asset account (4g)', function () use ($e, $a) { $e('2026-09-08', 'in', 66.60, 'UGX', '4g', $a['RH-Fixed Asset'], 'RH-U asset'); },
            [['UGX', '4g', 66.60, false, true]]],
        ['equity', 'a row on an equity account', function () use ($e, $a) { $e('2026-09-08', 'in', 77.70, 'UGX', 'dishnet', $a['RH-Share Capital'], 'RH-U equity'); },
            [['UGX', 'dishnet', 77.70, false, true]]],
        ['receivable', 'a row on a receivable account (bluecard)', function () use ($e, $a) { $e('2026-09-09', 'in', 88.80, 'UGX', 'bluecard', $a['RH-Receivable'], 'RH-U receivable'); },
            [['UGX', 'bluecard', 88.80, false, true]]],
        ['payable', 'a row on a payable account', function () use ($e, $a) { $e('2026-09-09', 'out', 99.90, 'UGX', 'dishnet', $a['RH-Payable'], 'RH-U payable'); },
            [['UGX', 'dishnet', -99.90, false, true]]],
        ['director', 'a row on the director account', function () use ($e, $dirU) { $e('2026-09-10', 'out', 12.34, 'UGX', 'dishnet', $dirU, 'RH-U director'); },
            [['UGX', 'dishnet', -12.34, false, true]]],
        ['orphan id', 'a row on an account id that names no account (E2; addEntryRaw refuses it)', function () use ($pdo) {
                t1_raw($pdo, ['sr' => 'RH-RAW-2', 'date' => '2026-09-10', 'direction' => 'in', 'amount' => 33.33, 'currency' => 'UGX', 'account_id' => 9999, 'description' => 'RH-U orphan']); },
            [['UGX', 'dishnet', 33.33, false, true]]],
        ['negative id', 'a row on a negative account id (E2; addEntryRaw checks only ids above 0)', function () use ($e) { $e('2026-09-10', 'in', 22.22, 'UGX', '4g', -5, 'RH-U negative id'); },
            [['UGX', '4g', 22.22, false, true]]],
        ['text id (empty)', "a row whose account id is the text '' (E4: an orphan, never cast)", function () use ($pdo) {
                t1_raw($pdo, ['sr' => 'RH-RAW-3', 'date' => '2026-09-11', 'direction' => 'in', 'amount' => 11.11, 'currency' => 'UGX', 'account_id' => '', 'description' => 'RH-U text id empty']); },
            [['UGX', 'dishnet', 11.11, false, true]]],
        ['text id (word)', "a row whose account id is the text 'abc' (E4)", function () use ($pdo) {
                t1_raw($pdo, ['sr' => 'RH-RAW-4', 'date' => '2026-09-11', 'direction' => 'in', 'amount' => 14.14, 'currency' => 'UGX', 'account_id' => 'abc', 'description' => 'RH-U text id word']); },
            [['UGX', 'dishnet', 14.14, false, true]]],
        ['text id (digits first)', "a row whose account id is the text '<cash id>x' (E4: never cast — a cast would read it as the cash account)", function () use ($pdo, $cash) {
                t1_raw($pdo, ['sr' => 'RH-RAW-6', 'date' => '2026-09-11', 'direction' => 'in', 'amount' => 15.15, 'currency' => 'UGX', 'account_id' => $cash . 'x', 'description' => 'RH-U text id digits']); },
            [['UGX', 'dishnet', 15.15, false, true]]],
        ['Cash kind', "a row on an account of kind 'Cash' (E6: exact kinds only)", function () use ($e, $a) { $e('2026-09-11', 'in', 36.36, 'UGX', 'dishnet', $a['RH-Cash Capital K'], 'RH-U Cash kind'); },
            [['UGX', 'dishnet', 36.36, false, true]]],
        ['cash-space kind', "a row on an account of kind 'cash ' (trailing space, E6)", function () use ($e, $a) { $e('2026-09-11', 'in', 37.37, 'UGX', 'dishnet', $a['RH-Cash Spaced K'], 'RH-U cash space kind'); },
            [['UGX', 'dishnet', 37.37, false, true]]],
        ['pending_approval', 'a manual entry with approval off (E8)', function () use ($cb, $actor) {
                $cb->addEntry(['project' => 'dishnet', 'date' => '2026-09-13', 'direction' => 'out', 'amount' => 47.47, 'currency' => 'UGX',
                               'category' => 'Misc Expense', 'description' => 'RH-U pending'], $actor, false); },
            [['UGX', 'dishnet', -47.47, false, true]]],
        ['rejected', 'a manual entry with approval off, then rejected (E8)', function () use ($cb, $actor) {
                $r = $cb->addEntry(['project' => '4g', 'date' => '2026-09-13', 'direction' => 'in', 'amount' => 48.48, 'currency' => 'UGX',
                                    'category' => 'Receipt', 'description' => 'RH-U rejected'], $actor, false);
                $cb->rejectEntry((int)($r['id'] ?? 0), 'RH-not ours', $actor); },
            [['UGX', '4g', 48.48, false, true]]],
        ['voided', 'a row written, then voided (E7)', function () use ($cb, $e, $pdo) {
                $e('2026-09-14', 'in', 49.49, 'UGX', 'dishnet', 0, 'RH-U voided');
                $cb->voidEntry(t1_id_by_desc($pdo, 'RH-U voided'), 'RH-entered twice', T1_ME); },
            [['UGX', 'dishnet', 49.49, false, false]]],
        ['voided_reconcile', 'a row written, then marked voided_reconcile (its only writer is a debug route\'s own UPDATE)', function () use ($e, $pdo) {
                $e('2026-09-14', 'in', 41.41, 'UGX', 'dishnet', 0, 'RH-U voided reconcile');
                $pdo->prepare("UPDATE cb_ledger SET status = 'voided_reconcile' WHERE id = ?")->execute([t1_id_by_desc($pdo, 'RH-U voided reconcile')]); },
            [['UGX', 'dishnet', 41.41, false, false]]],
        ['status Approved', "a row whose status is 'Approved' (E8: exact 'approved' only)", function () use ($e) { $e('2026-09-15', 'in', 50.50, 'UGX', 'dishnet', 0, 'RH-U status Approved', 'Approved'); },
            [['UGX', 'dishnet', 50.50, false, true]]],
        ['status blank', "a row whose status is '' (E8)", function () use ($e) { $e('2026-09-15', 'in', 51.51, 'UGX', 'dishnet', 0, 'RH-U status blank', ''); },
            [['UGX', 'dishnet', 51.51, false, true]]],
        ['lowercase ugx', "a row stored in 'ugx' (E12: in no figure)", function () use ($e) { $e('2026-09-16', 'in', 52.52, 'ugx', 'dishnet', 0, 'RH-U lowercase'); },
            [['ugx', 'dishnet', 52.52, true, true]]],
        ['blank currency', 'a receipt stored with no currency counts in UGX (dishnet)', function () use ($e) { $e('2026-09-20', 'in', 63.63, '', 'dishnet', 0, 'RH-U blank dishnet'); },
            [['', 'dishnet', 63.63, true, true]]],
        ['blank currency', 'an expense stored with no currency (4g)', function () use ($e) { $e('2026-09-21', 'out', 21.00, '', '4g', 0, 'RH-U blank 4g'); },
            [['', '4g', -21.00, true, true]]],
        ['blank currency on a cash account', 'a blank-currency row on the cash account counts in UGX (E12; addEntryRaw refuses it)', function () use ($pdo, $cash) {
                t1_raw($pdo, ['sr' => 'RH-RAW-5', 'date' => '2026-09-22', 'direction' => 'in', 'amount' => 5.05, 'currency' => '', 'account_id' => $cash, 'description' => 'RH-U blank on cash']); },
            [['', 'dishnet', 5.05, true, true]]],
        ['transfer', 'bank → cash (E11: +X)', function () use ($cb, $ugxBank, $cash) { $cb->recordAccountTransfer($ugxBank, $cash, 1000.00, 1000.00, '2026-09-23', '', '', T1_ME); },
            [['UGX', 'dishnet', -1000.00, false, true], ['UGX', 'dishnet', 1000.00, true, true]]],
        ['transfer', 'cash → bank (E11: −X)', function () use ($cb, $ugxBank, $cash) { $cb->recordAccountTransfer($cash, $ugxBank, 400.00, 400.00, '2026-09-23', '', '', T1_ME); },
            [['UGX', 'dishnet', -400.00, true, true], ['UGX', 'dishnet', 400.00, false, true]]],
        ['transfer', 'cash → mobile money (E11: 0)', function () use ($cb, $cash, $mtn) { $cb->recordAccountTransfer($cash, $mtn, 250.00, 250.00, '2026-09-24', '', '', T1_ME); },
            [['UGX', 'dishnet', -250.00, true, true], ['UGX', 'dishnet', 250.00, true, true]]],
        ['transfer', 'mobile money → cash (E11: 0)', function () use ($cb, $cash, $mtn) { $cb->recordAccountTransfer($mtn, $cash, 50.00, 50.00, '2026-09-24', '', '', T1_ME); },
            [['UGX', 'dishnet', -50.00, true, true], ['UGX', 'dishnet', 50.00, true, true]]],
        ['transfer', 'USD bank → UGX cash (E11: USD 0, UGX +Y)', function () use ($cb, $usdBank, $cash) { $cb->recordAccountTransfer($usdBank, $cash, 10.00, 37.00, '2026-09-24', '', 'RH-board rate', T1_ME); },
            [['USD', 'dishnet', -10.00, false, true], ['UGX', 'dishnet', 37.00, true, true]]],
        ['exchange', 'a cash-bag exchange, USD → UGX, both legs on no account (4g)', function () use ($cb) { $cb->recordCashExchange(5.00, 3.70, 'usd_to_base', '2026-09-25', '', '4g', 'RH-Changer', T1_ME); },
            [['USD', '4g', -5.00, true, true], ['UGX', '4g', 18.50, true, true]]],
        ['funding', 'funding into the cash account (E11: +X, the cash leg only)', function () use ($cb, $cash, $dirU) { $cb->recordFunding($cash, $dirU, 700.00, '2026-09-25', 'RH-U funding cash', '', T1_ME); },
            [['UGX', 'dishnet', 700.00, true, true], ['UGX', 'dishnet', 700.00, false, true]]],
        ['funding', 'funding into the USD bank account (E11: 0)', function () use ($cb, $usdBank, $a) { $cb->recordFunding($usdBank, $a['RH-Director USD'], 1500.00, '2026-09-25', 'RH-U funding bank', '', T1_ME); },
            [['USD', 'dishnet', 1500.00, false, true], ['USD', 'dishnet', 1500.00, false, true]]],
        ['unassigned', 'a USD expense on no account (dishnet): USD goes below zero', function () use ($e) { $e('2026-09-26', 'out', 1890.00, 'USD', 'dishnet', 0, 'RH-U USD spend dishnet'); },
            [['USD', 'dishnet', -1890.00, true, true]]],
        ['unassigned', 'a USD expense on no account (4g)', function () use ($e) { $e('2026-09-26', 'out', 25.00, 'USD', '4g', 0, 'RH-U USD spend 4g'); },
            [['USD', '4g', -25.00, true, true]]],
        ['unassigned', 'a USD receipt on no account (bluecard)', function () use ($e) { $e('2026-09-27', 'in', 12.00, 'USD', 'bluecard', 0, 'RH-U USD bluecard'); },
            [['USD', 'bluecard', 12.00, true, true]]],
        ['direction other than in', "a row whose direction is 'IN' is subtracted, as always (E16)", function () use ($e) { $e('2026-09-27', 'IN', 7.77, 'UGX', 'dishnet', 0, 'RH-U direction IN'); },
            [['UGX', 'dishnet', -7.77, true, true]]],
        ['assigned to mobile money', 'assignAccount() puts a receipt on no account onto a wallet: it stays in', function () use ($cb, $pdo, $mtn, $actor) {
                $cb->assignAccount(t1_id_by_desc($pdo, 'RH-U receipt dishnet'), $mtn, $actor, 'RH-placing'); },
            []],
        ['unassigned', 'the newest UGX row of each project', function () use ($e) {
                $e('2026-10-08', 'in', 3.03, 'UGX', 'dishnet', 0, 'RH-U newest dishnet');
                $e('2026-10-08', 'in', 2.02, 'UGX', '4g', 0, 'RH-U newest 4g');
                $e('2026-10-08', 'in', 1.01, 'UGX', 'bluecard', 0, 'RH-U newest bluecard'); },
            [['UGX', 'dishnet', 3.03, true, true], ['UGX', '4g', 2.02, true, true], ['UGX', 'bluecard', 1.01, true, true]]],
    ];
}

if ($U !== null) {
    $STEPS = t1_steps_u($U, $acc);
    foreach ($STEPS as [$class, $what, $act, $legs]) {
        try {
            $n0 = (int)$pdoU->query('SELECT COUNT(*) FROM cb_ledger')->fetchColumn();
            $a0 = t1_vec($cbU); $t0 = t1_vec_today($pdoU, 'UGX');
            $act();
            $n1 = (int)$pdoU->query('SELECT COUNT(*) FROM cb_ledger')->fetchColumn();
            $dA = t1_minus(t1_vec($cbU), $a0); $dT = t1_minus(t1_vec_today($pdoU, 'UGX'), $t0);
            $wantA = t1_expect($legs, 'UGX', 3); $wantT = t1_expect($legs, 'UGX', 4);
            $net = $wantA['UGX|'] !== 0.0 ? 'UGX ' . t1_fmt($wantA['UGX|']) : '';
            $net .= $wantA['USD|'] !== 0.0 ? ($net !== '' ? ', ' : '') . 'USD ' . t1_fmt($wantA['USD|']) : '';
            is_(t1_vdiff($dA, $wantA) === '', "[{$class}] {$what}: cash in hand moves " . ($net !== '' ? $net : 'by 0'), t1_vdiff($dA, $wantA));
            is_($n1 - $n0 === count($legs) && t1_vdiff($dT, $wantT) === '',
                "  …the act wrote " . count($legs) . " row(s), and 5.18.94's rule moves as recorded (control)",
                'rows ' . ($n1 - $n0) . '; ' . t1_vdiff($dT, $wantT));
        } catch (\Throwable $ex) { t1_threw("[{$class}] {$what}", $ex); }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
echo "\n2. The class-presence control: book U holds a row of every class\n";
try {
    $classes = ['unassigned' => 0, 'cash' => 0, 'momo' => 0, 'inactive momo' => 0, 'mismatched-currency cash row' => 0,
        'adjustment-shaped' => 0, 'bank' => 0, 'inventory' => 0, 'asset' => 0, 'director' => 0, 'equity' => 0,
        'receivable' => 0, 'payable' => 0, 'orphan id' => 0, 'negative id' => 0, "text id ''" => 0, 'text id (a word)' => 0,
        'text id that a cast would read as a cash or momo account' => 0,
        "kind 'Cash'" => 0, "kind 'cash '" => 0, 'pending_approval' => 0, 'rejected' => 0, 'voided' => 0,
        'voided_reconcile' => 0, "status 'Approved'" => 0, "status ''" => 0, "currency 'ugx'" => 0, 'blank currency' => 0,
        'blank currency on a cash account' => 0, 'USD' => 0, 'USD on a bank account' => 0, 'project dishnet' => 0,
        'project 4g' => 0, 'project bluecard' => 0, "direction neither 'in' nor 'out'" => 0];
    if ($U !== null) {
        $kU2 = t1_kinds($pdoU);
        foreach ($pdoU->query('SELECT l.status, l.direction, l.currency, l.project, l.date, typeof(l.account_id) AS t, l.account_id,
                                      a.id AS aid, a.kind, a.active, a.currency AS acur
                                 FROM cb_ledger l LEFT JOIN cb_accounts a ON a.id = l.account_id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st = (string)$r['status']; $ok = $st === 'approved'; $kind = $r['aid'] !== null ? (string)$r['kind'] : null;
            $un = $r['t'] === 'null' || ($r['t'] === 'integer' && (int)$r['account_id'] === 0);
            if ($ok && $un) $classes['unassigned']++;
            if ($ok && $kind === 'cash') $classes['cash']++;
            if ($ok && $kind === 'momo' && (int)$r['active'] === 1) $classes['momo']++;
            if ($ok && $kind === 'momo' && (int)$r['active'] === 0) $classes['inactive momo']++;
            if ($ok && $kind === 'cash' && (string)$r['currency'] !== '' && (string)$r['currency'] !== (string)$r['acur']) $classes['mismatched-currency cash row']++;
            if ($ok && $un && $r['direction'] === 'out' && $r['currency'] === 'UGX' && $r['date'] === '2026-09-12') $classes['adjustment-shaped']++;
            foreach (['bank', 'inventory', 'asset', 'director', 'equity', 'receivable', 'payable'] as $k) if ($ok && $kind === $k) $classes[$k]++;
            if ($r['t'] === 'integer' && (int)$r['account_id'] > 0 && $r['aid'] === null) $classes['orphan id']++;
            if ($r['t'] === 'integer' && (int)$r['account_id'] < 0) $classes['negative id']++;
            if ($r['t'] === 'text' && (string)$r['account_id'] === '') $classes["text id ''"]++;
            if ($r['t'] === 'text' && (string)$r['account_id'] !== '') $classes['text id (a word)']++;
            if ($r['t'] === 'text' && (int)$r['account_id'] > 0 && in_array($kU2[(int)$r['account_id']] ?? '', T1_CASH_KINDS, true)) $classes['text id that a cast would read as a cash or momo account']++;
            if ($kind === 'Cash') $classes["kind 'Cash'"]++;
            if ($kind === 'cash ') $classes["kind 'cash '"]++;
            foreach (['pending_approval', 'rejected', 'voided', 'voided_reconcile'] as $s) if ($st === $s) $classes[$s]++;
            if ($st === 'Approved') $classes["status 'Approved'"]++;
            if ($st === '') $classes["status ''"]++;
            if ($r['currency'] === 'ugx') $classes["currency 'ugx'"]++;
            if ($r['currency'] === '' || $r['currency'] === null) $classes['blank currency']++;
            if (($r['currency'] === '' || $r['currency'] === null) && $kind === 'cash') $classes['blank currency on a cash account']++;
            if ($r['currency'] === 'USD') $classes['USD']++;
            if ($r['currency'] === 'USD' && $kind === 'bank') $classes['USD on a bank account']++;
            foreach (['dishnet', '4g', 'bluecard'] as $p) if ($r['project'] === $p) $classes["project {$p}"]++;
            if (!in_array($r['direction'], ['in', 'out'], true)) $classes["direction neither 'in' nor 'out'"]++;
        }
    }
    foreach ($classes as $c => $n) is_($n >= 1, "book U holds a row of class: {$c} ({$n})");
} catch (\Throwable $e) { t1_threw('the class-presence control', $e); }

// ═════════════════════════════════════════════════════════════════════════════
echo "\n3. Two independent oracles: P0's class SQL (approved) and the row-by-row reading, per currency and scope\n";
/** Compares cashInHand() with both oracles in every currency and scope; returns how many cells differ from today's rule. */
function t1_oracles(array $B, int $adjId, string $when): int {
    $rows = t1_rows($B['pdo']); $kinds = t1_kinds($B['pdo']); $differs = 0;
    foreach (['UGX', 'USD'] as $c) foreach (T1_SCOPES as $s) {
        $tag = "{$B['name']} {$c}/" . ($s === '' ? 'all projects' : $s) . ($when !== '' ? " {$when}" : '');
        $got = $B['cb']->cashInHand($c, $s);
        $p0  = round(t1_p0_A($B['pdo'], $c, $B['base'], $adjId, $s), 2);
        $php = t1_hero_php($rows, $kinds, $B['base'], $c, $s, true);
        is_(abs($got - $p0) < 0.005, "{$tag}: cash in hand = P0's A (" . t1_fmt($p0) . ')', 'cashInHand ' . t1_fmt($got));
        is_(abs($got - $php) < 0.005, "{$tag}: cash in hand = the row-by-row reading (" . t1_fmt($php) . ')', 'cashInHand ' . t1_fmt($got));
        if (abs($php - t1_hero_php($rows, $kinds, $B['base'], $c, $s, false)) >= 0.005) $differs++;
    }
    return $differs;
}
$adjU = $U !== null ? t1_id_by_desc($pdoU, 'RH-U adjustment-shaped (invented)') : 0;
try {
    if ($U !== null) {
        $d = t1_oracles($U, $adjU, '');
        is_($d >= 6, "the control: on book U, rule A differs from 5.18.94's rule in {$d} of 8 cells");
        is_($cbU->cashInHand('USD') < 0, 'USD cash in hand is below zero, as the operator accepted (' . t1_fmt($cbU->cashInHand('USD')) . ')');
    }
} catch (\Throwable $e) { t1_threw('the oracles on book U', $e); }

// ═════════════════════════════════════════════════════════════════════════════
echo "\n4. E17 — the Balance column: All, UGX and USD views, every project; hero = top Balance per _bal_currency\n";
try {
    if ($U !== null) {
        t1_views($U, ['', 'UGX', 'USD'], ['UGX', 'USD'], true, true);
        // Lowercase rows: their own 'ugx' stream in the All view, non-null, as before 5.18.96; compared with no hero.
        $lc = 0; $bad = '';
        foreach ($cbU->getEntries(['project' => '', 'currency' => '', 'limit' => 100000]) as $r) {
            if ($r['currency'] !== 'ugx') continue;
            $lc++;
            if ($r['running_balance'] === null || $r['_bal_currency'] !== 'ugx') $bad = 'row ' . $r['id'];
        }
        is_($lc >= 1 && $bad === '', "the {$lc} lowercase 'ugx' row(s) run their own 'ugx' stream in the All view, with a Balance", $bad);
        // A blank-currency row older than the newest UGX row, inside the filtered UGX stream: the reason M15 is visible.
        $blankIn = 0; $kU = t1_kinds($pdoU);
        foreach (t1_rows($pdoU) as $r) if (($r['currency'] === '' || $r['currency'] === null) && t1_in_rule($r, $kU)) $blankIn++;
        is_($blankIn >= 2, "{$blankIn} blank-currency rows sit inside the rule, older than every project's newest UGX row");
    }
} catch (\Throwable $e) { t1_threw('E17 on book U', $e); }

// ═════════════════════════════════════════════════════════════════════════════
echo "\n5. E9 — the adjustment-shaped row put on the bank leaves cash in hand by exactly its amount\n";
try {
    is_($adjU > 0, 'the adjustment-shaped row is found by its invented description, once');
    if ($U !== null && $adjU > 0) {
        $a0 = t1_vec($cbU); $t0 = t1_vec_today($pdoU, 'UGX');
        $r = $cbU->assignAccount($adjU, $acc['Ecobank Uganda – UGX'], ['name' => T1_ME], 'RH-E9');
        is_(($r['ok'] ?? false) === true, 'assignAccount() moves it to the UGX bank account');
        $want = t1_expect([['UGX', 'dishnet', 999.99, true, true]], 'UGX', 3);
        $dA = t1_minus(t1_vec($cbU), $a0);
        is_(t1_vdiff($dA, $want) === '', 'cash in hand rises by exactly the row (+999.99), in UGX all projects and dishnet, nowhere else', t1_vdiff($dA, $want));
        $dT = t1_minus(t1_vec_today($pdoU, 'UGX'), $t0);
        is_(t1_vdiff($dT, t1_expect([], 'UGX', 4)) === '', "…while 5.18.94's rule does not move: an account never mattered to it (control)", t1_vdiff($dT, t1_expect([], 'UGX', 4)));
        // P0 accepts --adjustment-id only for a live, unassigned row (scripts/bank-recon-p0.php:246-247): now 0.
        t1_oracles($U, 0, 'after E9');
    }
} catch (\Throwable $e) { t1_threw('E9', $e); }

// ═════════════════════════════════════════════════════════════════════════════
echo "\n6. E17's one exception: a blank-currency row newer than every UGX row, asserted apart\n";
try {
    if ($U !== null) {
        $h0 = ['dishnet' => $cbU->cashInHand('UGX', 'dishnet'), '' => $cbU->cashInHand('UGX')];
        $top = function (string $proj, string $view) use ($cbU): ?float {
            foreach ($cbU->getEntries(['project' => $proj, 'currency' => $view, 'limit' => 100000]) as $r) {
                if ($r['_bal_currency'] === 'UGX' && $r['running_balance'] !== null) return (float)$r['running_balance'];
            }
            return null;
        };
        t1_entry($cbU, '2026-10-09', 'in', 70.07, '', 'dishnet', 0, 'RH-U blank newest');
        $nb = t1_id_by_desc($pdoU, 'RH-U blank newest');
        foreach (['dishnet', ''] as $p) {
            $pl = $p === '' ? 'all projects' : $p;
            $h1 = $cbU->cashInHand('UGX', $p);
            is_(abs($h1 - $h0[$p] - 70.07) < 0.005, "{$pl}: UGX cash in hand moves by the blank row (+70.07)", t1_fmt($h1 - $h0[$p]));
            $ids = array_map(function ($r) { return (int)$r['id']; }, $cbU->getEntries(['project' => $p, 'currency' => 'UGX', 'limit' => 100000]));
            is_(!in_array($nb, $ids, true), "{$pl}: the UGX-only view does not list it (buildWhere() matches the code exactly)");
            $t = $top($p, 'UGX');
            is_($t !== null && abs($h1 - $t - 70.07) < 0.005, "{$pl}: so that view's top Balance is short of the hero by exactly that row",
                't top ' . ($t === null ? 'none' : t1_fmt($t)) . ', hero ' . t1_fmt($h1));
            $all = $cbU->getEntries(['project' => $p, 'currency' => '', 'limit' => 100000]);
            is_((int)($all[0]['id'] ?? 0) === $nb && $all[0]['_bal_currency'] === 'UGX' && abs((float)$all[0]['running_balance'] - $h1) < 0.005,
                "{$pl}: the All view lists it first, with the hero's value");
        }
        // A UGX row newer still: the filtered stream had carried the blank row all along.
        t1_entry($cbU, '2026-10-10', 'in', 8.08, 'UGX', 'dishnet', 0, 'RH-U newest UGX after');
        foreach (['dishnet', ''] as $p) {
            $t = $top($p, 'UGX'); $h = $cbU->cashInHand('UGX', $p);
            is_($t !== null && abs($t - $h) < 0.005, ($p === '' ? 'all projects' : $p) . ': with a newer UGX row the UGX-only top Balance is the hero again, blank row included',
                't top ' . ($t === null ? 'none' : t1_fmt($t)) . ', hero ' . t1_fmt($h));
        }
    }
} catch (\Throwable $e) { t1_threw("E17's exception", $e); }

// ═════════════════════════════════════════════════════════════════════════════
echo "\n7. E1 — a legacy cb_ledger whose account_id is nullable: its NULL row counts\n";
try {
    $L = t1_book('legacy', $UG_CFG, null, function (PDO $pdo) {
        // A hand-made table from before the account column was NOT NULL. initTable() only adds missing columns.
        $pdo->exec('DROP TABLE IF EXISTS cb_ledger');
        $pdo->exec("CREATE TABLE cb_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, sr TEXT NOT NULL DEFAULT '', date TEXT NOT NULL DEFAULT '',
                    direction TEXT NOT NULL DEFAULT 'in', amount REAL NOT NULL DEFAULT 0, currency TEXT NOT NULL DEFAULT 'USD',
                    status TEXT NOT NULL DEFAULT 'approved', project TEXT NOT NULL DEFAULT 'dishnet', account_id INTEGER)");
        $pdo->exec("INSERT INTO cb_ledger (sr, date, direction, amount, currency, status, project, account_id) VALUES
                    ('RH-L1', '2026-09-03', 'in', 120.00, 'UGX', 'approved', 'dishnet', NULL),
                    ('RH-L2', '2026-09-04', 'in', 30.00, 'UGX', 'approved', 'dishnet', 0)");
    });
    $col = null;
    foreach ($L['pdo']->query("SELECT name, type, \"notnull\" FROM pragma_table_info('cb_ledger')")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if ($c['name'] === 'account_id') $col = $c;
    }
    is_($col !== null && (int)$col['notnull'] === 0, 'after the service is built, account_id is still nullable (pragma_table_info): the case is real');
    is_((string)$L['pdo']->query("SELECT typeof(account_id) FROM cb_ledger WHERE sr='RH-L1'")->fetchColumn() === 'null', 'and the first row stores NULL');
    is_(t1_rule($L['cb']) === true, 'rule A is in force on the legacy book');
    $rowsL = t1_rows($L['pdo']);
    $g = $L['cb']->cashInHand('UGX');
    is_(abs($g - 150.00) < 0.005, 'UGX cash in hand counts the NULL row and the 0 row (+150.00)', t1_fmt($g));
    is_(abs($g - round(t1_p0_A($L['pdo'], 'UGX', 'UGX', 0, ''), 2)) < 0.005 && abs($g - t1_hero_php($rowsL, [], 'UGX', 'UGX', '', true)) < 0.005,
        "…and equals P0's A (dummy join, no accounts table) and the row-by-row reading");
    $before = $L['cb']->cashInHand('UGX'); $tb = t1_today_sql($L['pdo'], 'UGX', 'UGX', '');
    t1_raw($L['pdo'], ['sr' => 'RH-L3', 'date' => '2026-09-05', 'direction' => 'out', 'amount' => 45.00, 'currency' => 'UGX', 'account_id' => null, 'description' => 'RH-L NULL out']);
    is_(abs($L['cb']->cashInHand('UGX') - $before + 45.00) < 0.005, '[NULL account] a second NULL row moves cash in hand by its amount (−45.00)',
        t1_fmt($L['cb']->cashInHand('UGX') - $before));
    is_(abs(t1_today_sql($L['pdo'], 'UGX', 'UGX', '') - $tb + 45.00) < 0.005, "  …and 5.18.94's rule by the same (control)");
} catch (\Throwable $e) { t1_threw('E1, the legacy book', $e); }

// ═════════════════════════════════════════════════════════════════════════════
echo "\n8. E15 — a Uganda book with no cb_accounts: unassigned rows only, nothing created; then an account on the same instance\n";
try {
    $E = t1_book('noaccounts', $UG_CFG);
    // Only rows on no account through the writers: any non-zero account id makes addEntryRaw() look the account up,
    // which creates the table. The assigned and text-id rows are raw.
    t1_entry($E['cb'], '2026-09-02', 'in', 210.00, 'UGX', 'dishnet', 0, 'RH-E receipt');
    t1_entry($E['cb'], '2026-09-03', 'out', 35.00, 'UGX', '4g', 0, 'RH-E expense');
    t1_entry($E['cb'], '2026-09-03', 'in', 9.00, 'USD', 'dishnet', 0, 'RH-E USD');
    $E['cb']->addEntry(['project' => 'dishnet', 'date' => '2026-09-04', 'direction' => 'out', 'amount' => 5.00, 'currency' => 'UGX',
                        'category' => 'Misc Expense', 'description' => 'RH-E pending'], ['name' => T1_ME], false);
    t1_raw($E['pdo'], ['sr' => 'RH-E-RAW-1', 'date' => '2026-09-04', 'direction' => 'in', 'amount' => 400.00, 'currency' => 'UGX', 'account_id' => 3, 'description' => 'RH-E on account 3']);
    t1_raw($E['pdo'], ['sr' => 'RH-E-RAW-2', 'date' => '2026-09-04', 'direction' => 'in', 'amount' => 50.00, 'currency' => 'USD', 'account_id' => 2, 'description' => 'RH-E on account 2']);
    t1_raw($E['pdo'], ['sr' => 'RH-E-RAW-3', 'date' => '2026-09-05', 'direction' => 'in', 'amount' => 12.00, 'currency' => 'UGX', 'account_id' => 'abc', 'description' => 'RH-E text id']);
    $snap = function (PDO $pdo): string {
        return json_encode($pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC));
    };
    is_(!t1_has_accounts($E['pdo']), 'the book has no cb_accounts table to begin with');
    $s0 = $snap($E['pdo']);
    $threw = null; $figs = [];
    try {
        foreach (['UGX', 'USD'] as $c) foreach (T1_SCOPES as $s) $figs["{$c}|{$s}"] = $E['cb']->cashInHand($c, $s);
        t1_views($E, ['', 'UGX', 'USD'], ['UGX', 'USD'], true, true);
    } catch (\Throwable $ex) { $threw = $ex; }
    is_($threw === null, 'every read runs without an error', $threw ? get_class($threw) . ': ' . substr($threw->getMessage(), 0, 160) : '');
    $s1 = $snap($E['pdo']);
    is_($s0 === $s1, 'sqlite_master is byte-identical after the reads (no read creates a table)');
    is_(!t1_has_accounts($E['pdo']), 'and there is still no cb_accounts table');
    if ($threw === null) {
        $want = ['UGX|' => 175.00, 'UGX|dishnet' => 210.00, 'UGX|4g' => -35.00, 'UGX|bluecard' => 0.0,
                 'USD|' => 9.00, 'USD|dishnet' => 9.00, 'USD|4g' => 0.0, 'USD|bluecard' => 0.0];
        is_(t1_vdiff($figs, $want) === '', 'only approved rows on no account count: UGX +175.00, USD +9.00', t1_vdiff($figs, $want));
        $rowsE = t1_rows($E['pdo']); $bad = '';
        foreach (['UGX', 'USD'] as $c) foreach (T1_SCOPES as $s) {
            $p0 = round(t1_p0_A($E['pdo'], $c, 'UGX', 0, $s), 2); $php = t1_hero_php($rowsE, [], 'UGX', $c, $s, true);
            if (abs($figs["{$c}|{$s}"] - $p0) >= 0.005 || abs($figs["{$c}|{$s}"] - $php) >= 0.005) $bad .= "{$c}|{$s} ";
        }
        is_($bad === '', "…which is P0's A over its dummy join and the row-by-row reading, in every cell", $bad);
    }
    // The table-exists memo caches only true (CashbookService::accountsTableExists()): this same instance, which has read
    // the book while it had no cb_accounts, must count a cash account created afterwards and a row posted on it.
    $b0 = ['' => $E['cb']->cashInHand('UGX', ''), 'dishnet' => $E['cb']->cashInHand('UGX', 'dishnet')];
    $na = $E['cb']->addAccount('RH-E Cash box K', 'UGX', 'cash');
    is_(($na['ok'] ?? false) === true && (int)($na['id'] ?? 0) > 0 && t1_has_accounts($E['pdo']),
        'control: addAccount() on the same instance creates cb_accounts and a cash account', json_encode($na));
    t1_entry($E['cb'], '2026-09-06', 'in', 5.00, 'UGX', 'dishnet', (int)($na['id'] ?? 0), 'RH-E on the new cash box');
    $b1 = ['' => $E['cb']->cashInHand('UGX', ''), 'dishnet' => $E['cb']->cashInHand('UGX', 'dishnet')];
    is_(abs($b1[''] - $b0[''] - 5.00) < 0.005 && abs($b1['dishnet'] - $b0['dishnet'] - 5.00) < 0.005,
        'the same instance counts a row on a cash account created after its first read (+5.00, all projects and dishnet): no "no table" was cached',
        'all projects ' . t1_fmt($b1[''] - $b0['']) . ', dishnet ' . t1_fmt($b1['dishnet'] - $b0['dishnet']));
    $nid = t1_id_by_desc($E['pdo'], 'RH-E on the new cash box'); $nbal = null;
    foreach ($E['cb']->getEntries(['project' => '', 'currency' => '', 'limit' => 100000]) as $r) if ((int)$r['id'] === $nid) $nbal = $r['running_balance'];
    is_($nid > 0 && $nbal !== null && abs((float)$nbal - $b1['']) < 0.005, "…and on the same instance that row, the stream's newest, carries cash in hand as its Balance (E17)",
        'row ' . $nid . ': ' . var_export($nbal, true) . ', cash in hand ' . t1_fmt($b1['']));
    $fresh = new CashbookService($E['store'], $E['dir']);
    is_(abs($fresh->cashInHand('UGX', '') - $b1['']) < 0.005, 'and a fresh instance reads the same figure (the control)', t1_fmt($fresh->cashInHand('UGX', '')));
} catch (\Throwable $e) { t1_threw('E15, the book without accounts', $e); }

// ═════════════════════════════════════════════════════════════════════════════
echo "\n9. E11 — every movement of the map-definition table, X = 100.00, Y = 370.00 (and 5.18.94's rule, the contrast)\n";
try {
    $M = t1_book('moves', $UG_CFG);
    $cbM = $M['cb'];
    $cbM->seedStandardAccounts();
    $m = [];
    foreach ($cbM->accounts() as $a) $m[(string)$a['name']] = (int)$a['id'];
    foreach ([['RH-M Payable', 'UGX', 'payable'], ['RH-M Equity', 'UGX', 'equity'], ['RH-M Director USD', 'USD', 'director']] as [$n, $c, $k]) {
        $m[$n] = (int)($cbM->addAccount($n, $c, $k)['id'] ?? 0);
    }
    $bank = $m['Ecobank Uganda – UGX']; $bankU = $m['Ecobank Uganda – USD']; $cash = $m['Cash – Uganda'];
    $momo = $m['MTN Mobile Money']; $dir = $m['Director/Shareholder Funding'];
    $X = 100.00; $Y = 370.00;
    $moves = [
        ['an opening balance of a cash account',         function () use ($cbM, $cash, $X) { $cbM->recordOpeningBalance($cash, $X, '2026-09-01', 'RH-M', '', T1_ME); }, ['UGX' => [$X, $X]], 'UGX +X', 'UGX +X'],
        ['an opening balance of a mobile-money account', function () use ($cbM, $momo, $X) { $cbM->recordOpeningBalance($momo, $X, '2026-09-01', 'RH-M', '', T1_ME); }, ['UGX' => [$X, $X]], 'UGX +X', 'UGX +X'],
        ['an opening balance of a bank account',         function () use ($cbM, $bank, $X) { $cbM->recordOpeningBalance($bank, $X, '2026-09-01', 'RH-M', '', T1_ME); }, ['UGX' => [0.0, $X]], 'UGX 0', 'UGX +X'],
        ['an opening balance of a director account',     function () use ($cbM, $dir, $X) { $cbM->recordOpeningBalance($dir, $X, '2026-09-01', 'RH-M', '', T1_ME); }, ['UGX' => [0.0, $X]], 'UGX 0', 'UGX +X'],
        ['an opening balance of a payable account',      function () use ($cbM, $m, $X) { $cbM->recordOpeningBalance($m['RH-M Payable'], $X, '2026-09-01', 'RH-M', '', T1_ME); }, ['UGX' => [0.0, $X]], 'UGX 0', 'UGX +X'],
        ['an opening balance of an equity account',      function () use ($cbM, $m, $X) { $cbM->recordOpeningBalance($m['RH-M Equity'], $X, '2026-09-01', 'RH-M', '', T1_ME); }, ['UGX' => [0.0, $X]], 'UGX 0', 'UGX +X'],
        ['bank → cash',                                  function () use ($cbM, $bank, $cash, $X) { $cbM->recordAccountTransfer($bank, $cash, $X, $X, '2026-09-02', '', '', T1_ME); }, ['UGX' => [$X, 0.0]], 'UGX +X', 'UGX 0'],
        ['cash → bank, banking the drawer',              function () use ($cbM, $bank, $cash, $X) { $cbM->recordAccountTransfer($cash, $bank, $X, $X, '2026-09-02', '', '', T1_ME); }, ['UGX' => [-$X, 0.0]], 'UGX −X', 'UGX 0'],
        ['cash → mobile money',                          function () use ($cbM, $momo, $cash, $X) { $cbM->recordAccountTransfer($cash, $momo, $X, $X, '2026-09-03', '', '', T1_ME); }, ['UGX' => [0.0, 0.0]], 'UGX 0', 'UGX 0'],
        ['mobile money → cash',                          function () use ($cbM, $momo, $cash, $X) { $cbM->recordAccountTransfer($momo, $cash, $X, $X, '2026-09-03', '', '', T1_ME); }, ['UGX' => [0.0, 0.0]], 'UGX 0', 'UGX 0'],
        ['bank → mobile money, a wallet top-up',         function () use ($cbM, $bank, $momo, $X) { $cbM->recordAccountTransfer($bank, $momo, $X, $X, '2026-09-04', '', '', T1_ME); }, ['UGX' => [$X, 0.0]], 'UGX +X', 'UGX 0'],
        ['mobile money → bank, a sweep',                 function () use ($cbM, $bank, $momo, $X) { $cbM->recordAccountTransfer($momo, $bank, $X, $X, '2026-09-04', '', '', T1_ME); }, ['UGX' => [-$X, 0.0]], 'UGX −X', 'UGX 0'],
        ['FX: USD bank → UGX cash',                      function () use ($cbM, $bankU, $cash, $X, $Y) { $cbM->recordAccountTransfer($bankU, $cash, $X, $Y, '2026-09-05', '', 'RH-board rate', T1_ME); },
            ['USD' => [0.0, -$X], 'UGX' => [$Y, $Y]], 'USD 0, UGX +Y', 'USD −X, UGX +Y'],
        ['a cash-bag exchange, USD → UGX',               function () use ($cbM, $X) { $cbM->recordCashExchange($X, 3.70, 'usd_to_base', '2026-09-05', '', 'dishnet', 'RH-Changer', T1_ME); },
            ['USD' => [-$X, -$X], 'UGX' => [$Y, $Y]], 'USD −X, UGX +Y', 'USD −X, UGX +Y'],
        ['funding into a cash account',                  function () use ($cbM, $cash, $dir, $X) { $cbM->recordFunding($cash, $dir, $X, '2026-09-06', '', '', T1_ME); }, ['UGX' => [$X, 2 * $X]], 'UGX +X', 'UGX +2X'],
        ['funding into a bank account',                  function () use ($cbM, $bank, $dir, $X) { $cbM->recordFunding($bank, $dir, $X, '2026-09-06', '', '', T1_ME); }, ['UGX' => [0.0, 2 * $X]], 'UGX 0', 'UGX +2X'],
        ['USD funding into the USD bank',                function () use ($cbM, $bankU, $m, $X) { $cbM->recordFunding($bankU, $m['RH-M Director USD'], $X, '2026-09-06', '', '', T1_ME); }, ['USD' => [0.0, 2 * $X]], 'USD 0', 'USD +2X'],
        ['cash banked with the bank leg missing',        function () use ($cbM, $cash, $X) { t1_entry($cbM, '2026-09-07', 'out', $X, 'UGX', 'dishnet', $cash, 'RH-M banked, one leg'); }, ['UGX' => [-$X, -$X]], 'UGX −X', 'UGX −X'],
    ];
    foreach ($moves as [$what, $act, $want, $labelA, $labelT]) {
        $va = ['UGX' => $cbM->cashInHand('UGX'), 'USD' => $cbM->cashInHand('USD')];
        $vt = ['UGX' => t1_today_sql($M['pdo'], 'UGX', 'UGX', ''), 'USD' => t1_today_sql($M['pdo'], 'UGX', 'USD', '')];
        $act();
        $okA = true; $okT = true; $da = []; $dt = [];
        foreach (['UGX', 'USD'] as $c) {
            [$wa, $wt] = $want[$c] ?? [0.0, 0.0];
            $ga = round($cbM->cashInHand($c) - $va[$c], 2);
            $gt = round(t1_today_sql($M['pdo'], 'UGX', $c, '') - $vt[$c], 2);
            if (abs($ga - $wa) >= 0.005) $okA = false;
            if (abs($gt - $wt) >= 0.005) $okT = false;
            $da[] = "{$c} " . t1_fmt($ga); $dt[] = "{$c} " . t1_fmt($gt);
        }
        is_($okA, "E11 {$what}: {$labelA} under A", implode(', ', $da));
        is_($okT, "  …5.18.94's rule: {$labelT} (the contrast)", implode(', ', $dt));
    }
} catch (\Throwable $e) { t1_threw('E11, the movement book', $e); }

// ═════════════════════════════════════════════════════════════════════════════
echo "\n10. The truth table — the rule per book, and 5.18.94's SQL and streams wherever it is off\n";
/**
 * A book that discriminates: rule A would change it in its base and in every other currency (bank, director, funding,
 * pending, rejected, voided, voided_reconcile, orphan, blank currency, three projects), with SSP rows where asked.
 */
function t1_seed_tt(array $B, array $others, bool $withSsp): void {
    $cb = $B['cb']; $pdo = $B['pdo']; $base = $B['base']; $actor = ['name' => T1_ME];
    $bank = (int)($cb->addAccount("RH-TT Bank {$base}", $base, 'bank')['id'] ?? 0);
    $cash = (int)($cb->addAccount("RH-TT Cash {$base}", $base, 'cash')['id'] ?? 0);
    $dirA = (int)($cb->addAccount("RH-TT Director {$base}", $base, 'director')['id'] ?? 0);
    $cb->recordOpeningBalance($cash, 210.00, '2026-09-01', 'RH-TT opening cash', '', T1_ME);
    $cb->recordOpeningBalance($bank, 820.00, '2026-09-01', 'RH-TT opening bank', '', T1_ME);
    $cb->recordFunding($bank, $dirA, 150.00, '2026-09-02', 'RH-TT funding', '', T1_ME);
    t1_entry($cb, '2026-09-03', 'in', 500.00, $base, 'dishnet', 0, 'RH-TT receipt');
    t1_entry($cb, '2026-09-03', 'in', 7.00, '', 'dishnet', 0, 'RH-TT blank');
    t1_entry($cb, '2026-09-04', 'out', 20.00, $base, 'dishnet', $cash, 'RH-TT cash out');
    t1_entry($cb, '2026-09-04', 'out', 33.00, $base, 'dishnet', $bank, 'RH-TT bank out');
    $cb->addEntry(['project' => '4g', 'date' => '2026-09-05', 'direction' => 'out', 'amount' => 40.00, 'currency' => $base,
                   'category' => 'Misc Expense', 'description' => 'RH-TT pending'], $actor, false);
    $r = $cb->addEntry(['project' => 'dishnet', 'date' => '2026-09-05', 'direction' => 'in', 'amount' => 30.00, 'currency' => $base,
                        'category' => 'Receipt', 'description' => 'RH-TT rejected'], $actor, false);
    $cb->rejectEntry((int)($r['id'] ?? 0), 'RH-not ours', $actor);
    t1_entry($cb, '2026-09-06', 'in', 10.00, $base, 'dishnet', 0, 'RH-TT voided');
    $cb->voidEntry(t1_id_by_desc($pdo, 'RH-TT voided'), 'RH-entered twice', T1_ME);
    t1_entry($cb, '2026-09-06', 'in', 9.00, $base, 'dishnet', 0, 'RH-TT voided reconcile');
    $pdo->prepare("UPDATE cb_ledger SET status = 'voided_reconcile' WHERE id = ?")->execute([t1_id_by_desc($pdo, 'RH-TT voided reconcile')]);
    t1_raw($pdo, ['sr' => 'RH-TT-RAW-1', 'date' => '2026-09-07', 'direction' => 'in', 'amount' => 8.00, 'currency' => $base, 'account_id' => 9999, 'description' => 'RH-TT orphan']);
    t1_entry($cb, '2026-09-07', 'in', 6.00, $base, '4g', 0, 'RH-TT 4g');
    t1_entry($cb, '2026-09-08', 'out', 5.00, $base, 'bluecard', 0, 'RH-TT bluecard');
    foreach ($others as $c) {
        $ob = (int)($cb->addAccount("RH-TT Bank {$c}", $c, 'bank')['id'] ?? 0);
        t1_entry($cb, '2026-09-09', 'in', 50.00, $c, 'dishnet', 0, "RH-TT {$c} receipt");
        t1_entry($cb, '2026-09-09', 'in', 25.00, $c, 'dishnet', $ob, "RH-TT {$c} bank");
        $cb->addEntry(['project' => '4g', 'date' => '2026-09-10', 'direction' => 'out', 'amount' => 4.00, 'currency' => $c,
                       'category' => 'Misc Expense', 'description' => "RH-TT {$c} pending"], $actor, false);
    }
    if ($withSsp) {
        t1_entry($cb, '2026-09-11', 'in', 1.00, 'SSP', 'dishnet', 0, 'RH-TT SSP receipt', 'approved', ['ssp_amount' => 4500.0]);
        t1_entry($cb, '2026-09-11', 'out', 0.10, 'SSP', '4g', 0, 'RH-TT SSP spend', 'approved', ['ssp_amount' => 300.0]);
        $cb->addEntry(['project' => 'dishnet', 'date' => '2026-09-12', 'direction' => 'in', 'amount' => 0.20, 'currency' => 'SSP', 'ssp_amount' => 700.0,
                       'category' => 'Receipt', 'description' => 'RH-TT SSP pending'], $actor, false);
    }
    // The newest row of each project is in the base, so a rule-A book's filtered base view keeps hero = top Balance.
    foreach (['dishnet', '4g', 'bluecard'] as $p) t1_entry($cb, '2026-10-01', 'in', 1.00, $base, $p, 0, "RH-TT newest {$p}");
}
/** One truth-table row: the gate's answer, the figures in every currency and scope, the control, the streams. */
function t1_truth(array $B, bool $expectRule, string $label, array $curs): void {
    is_(t1_rule($B['cb']) === $expectRule, "{$label}: cashOnlyRuleInForce() is " . ($expectRule ? 'true' : 'false'),
        'got ' . var_export(t1_rule($B['cb']), true));
    $rows = t1_rows($B['pdo']); $kinds = t1_kinds($B['pdo']); $bad = []; $differs = 0; $n = 0;
    foreach ($curs as $c) foreach (T1_SCOPES as $s) {
        $n++;
        $got = $B['cb']->cashInHand($c, $s);
        if ($expectRule) {
            $w1 = round(t1_p0_A($B['pdo'], $c, $B['base'], 0, $s), 2); $w2 = t1_hero_php($rows, $kinds, $B['base'], $c, $s, true);
        } else {
            $w1 = t1_today_sql($B['pdo'], $B['base'], $c, $s); $w2 = t1_hero_php($rows, $kinds, $B['base'], $c, $s, false);
        }
        if (abs($got - $w1) >= 0.005 || abs($got - $w2) >= 0.005) $bad[] = "{$c}|{$s}: " . t1_fmt($got) . ' vs ' . t1_fmt($w1) . '/' . t1_fmt($w2);
        if (abs(t1_hero_php($rows, $kinds, $B['base'], $c, $s, true) - t1_hero_php($rows, $kinds, $B['base'], $c, $s, false)) >= 0.005) $differs++;
    }
    is_($bad === [], "{$label}: cash in hand is " . ($expectRule ? "P0's A and the row-by-row reading" : "5.18.94's SQL and its row-by-row reading")
        . " in every currency and scope ({$n} cells)", implode('; ', array_slice($bad, 0, 3)));
    is_($differs >= count($curs), "{$label}: the control — rule A would change this book ({$differs} of {$n} cells differ)");
    t1_views($B, array_merge([''], $curs), $curs, $expectRule, $expectRule);
}

$tt = [];
try {
    is_($U !== null && t1_rule($U['cb']) === true, 'Uganda (uganda; UGX,USD): cashOnlyRuleInForce() is true — sections 1–6 are its figures and streams');

    $S = t1_book('south-sudan', ['tenant_profile' => 'south-sudan', 'currency_code' => 'USD', 'currency_symbol' => '$',
                                 'cashbook_base_currency' => 'USD', 'cashbook_currencies' => 'USD,SSP']);
    t1_seed_tt($S, [], true);
    t1_truth($S, false, 'South Sudan (south-sudan; USD,SSP)', ['USD', 'SSP']);

    $SN = t1_book('ss-without-ssp', ['tenant_profile' => 'south-sudan', 'currency_code' => 'USD', 'currency_symbol' => '$',
                                     'cashbook_base_currency' => 'USD', 'cashbook_currencies' => 'USD']);
    t1_seed_tt($SN, [], false);
    t1_truth($SN, false, 'a South Sudan tenant without SSP (south-sudan; USD) — the tenant half', ['USD']);

    $UP = t1_book('ug-with-ssp', ['tenant_profile' => 'uganda', 'currency_code' => 'UGX', 'currency_symbol' => 'UGX',
                                  'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => 'UGX,USD,SSP']);
    t1_seed_tt($UP, ['USD'], true);
    t1_truth($UP, false, 'a Uganda tenant with SSP (uganda; UGX,USD,SSP) — the SSP half', ['UGX', 'USD', 'SSP']);

    // Uganda selected only by the vault, as on a box whose settings hold no currency: readable, then unreadable.
    $VC = t1_book('vault-readable', ['cashbook_currencies' => 'UGX,USD'],
                  json_encode(['config' => ['currency_code' => 'UGX', 'cashbook_base_currency' => 'UGX']]));
    is_($VC['base'] === 'UGX', "the control: Uganda held only in a readable vault is read (base {$VC['base']})");
    t1_seed_tt($VC, ['USD'], false);
    t1_truth($VC, true, 'Uganda held only in a readable vault — the control for the next row', ['UGX', 'USD']);

    $V = t1_book('vault-unreadable', ['cashbook_currencies' => 'UGX,USD'], '{"config": {"currency_code": "UGX"');
    $vOther = $V['base'] === 'UGX' ? 'USD' : 'UGX';
    t1_seed_tt($V, [$vOther], false);
    t1_truth($V, false, "the same book with its vault unreadable (not JSON; base {$V['base']})", [$V['base'], $vOther]);

    // Any throwable inside the gate: a currency list stored as a JSON array, read under a handler that throws.
    $TB = t1_book('gate-throws', ['tenant_profile' => 'uganda', 'currency_code' => 'UGX', 'currency_symbol' => 'UGX',
                                  'cashbook_base_currency' => 'UGX', 'cashbook_currencies' => ['UGX', 'USD']], null, null, false);
    $thrown = 0;
    set_error_handler(function (int $no, string $str) use (&$thrown) { $thrown++; throw new \ErrorException($str, 0, $no); });
    try { $ruleTB = t1_rule($TB['cb']); } finally { restore_error_handler(); }
    is_($thrown >= 1, "a throwable was raised inside the gate ({$thrown})");
    $cbTB2 = new CashbookService($TB['store'], $TB['dir']);
    set_error_handler(function () { return true; });
    try { $ruleTB2 = t1_rule($cbTB2); } finally { restore_error_handler(); }
    is_($ruleTB2 === true, 'the control: the same configuration read without the throwable is Uganda without SSP',
        'got ' . var_export($ruleTB2, true));
    t1_seed_tt($TB, ['USD'], false);
    t1_truth($TB, false, 'a gate that throws — any throwable is false', ['UGX', 'USD']);
} catch (\Throwable $e) { t1_threw('the truth table', $e); }

// ═════════════════════════════════════════════════════════════════════════════
echo "\n11. The end check — the only vault written is this test's own\n";
$env = (string)getenv('DN_VAULT_FILE');
is_($env !== '' && strpos($env, $T . '/vaults/') === 0, 'DN_VAULT_FILE still names a file inside this test\'s own folder',
    $env === '' ? 'unset' : 'it names another file');
$named = true; $files = [];
foreach (scandir($T . '/vaults') ?: [] as $f) {
    if ($f === '.' || $f === '..') continue;
    $files[] = $f;
    if (!isset($T1_VAULTS[$T . '/vaults/' . $f])) $named = false;
}
is_($named, 'every vault file in its folder is one this test named (' . count($files) . ')', implode(', ', $files));
$uv = $U !== null ? json_decode((string)@file_get_contents($U['vault']), true) : null;
is_(is_array($uv) && ($uv['config']['cashbook_base_currency'] ?? '') === 'UGX',
    "the control: book U's configuration load refreshed book U's own vault, so the mechanism watched here ran");
$strays = [];
foreach (scandir($T . '/books') ?: [] as $b) {
    if ($b === '.' || $b === '..') continue;
    foreach (['config_vault.json', '.dishnet-sudan.vault.json'] as $f) if (is_file("{$T}/books/{$b}/{$f}")) $strays[] = "{$b}/{$f}";
}
is_($strays === [], 'no book folder holds a vault of its own', implode(', ', $strays));
$defAfter = t1_fstate($defVault);
is_($defAfter === $defBefore, $defBefore === null
    ? 'no vault appeared at the default path beside the code'
    : 'the vault at the default path beside the code is unchanged: the same bytes and mtime',
    $defAfter === null ? 'now absent' : 'it changed');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

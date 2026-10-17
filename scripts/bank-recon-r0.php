<?php
/**
 * bank-recon-r0.php — R0 of docs/69 §2.3.8: a census, READ ONLY, of the Cashbook's voided entries, their evidence, their
 * twin candidates and the rows the 12 Sep adjustment could be, before any repair is designed. Run by
 * scripts/bank-recon-r0.sh, which pipes this file into the container's PHP on standard input (nothing is copied into the
 * container) as the database's owner:
 *
 *   php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -- <data dir> <plugin dir>  < scripts/bank-recon-r0.php
 *
 * Why it cannot write — P0's mechanics (scripts/bank-recon-p0.php), copied:
 *   - it loads NO plugin code and reads no configuration file and no vault;
 *   - SQLite opens the database with SQLITE_OPEN_READONLY and immutable=1, then PRAGMA query_only=ON and temp_store=MEMORY
 *     (both checked). Immutable, SQLite takes no lock and makes no file beside the book;
 *   - so it reads only while the plugin has the book closed — no -wal, no -shm, no -journal — waiting up to two minutes;
 *   - it reads the book twice, on two connections; the result only counts if both readings are identical (every printed
 *     line, and a digest of every row fetched), the file's inode, size and times did not move, and no side file appeared;
 *   - every query goes through q(): one statement, beginning SELECT or WITH, inside one read transaction (BEGIN … ROLLBACK);
 *   - each reading ends with total_changes() and fails unless it is 0;
 *   - the only file functions used are is_file, is_dir, realpath and stat (with clearstatcache).
 *
 * What it prints: counts, row ids, checked dates and months, and codes from fixed lists. No name, description, person,
 * reference, category text, customer or amount — not even of the 12 Sep adjustment, which it matches by a hash of the
 * figure recorded in docs/07 (ADJ_CENTS_SHA), never by the figure. No PHP diagnostic can reach the log: every warning is
 * an exception without a message, and an internal error is reported by its class and line only.
 *
 * Exit: 0 done; 2 refused before reading (wrong folder, no database, the book kept open, no cb_ledger or not its schema);
 * 3 a check failed, SQLite could not finish the reading (run again), or R0's own internal error (do not run it again).
 */
declare(strict_types=1);
set_error_handler(static function (int $no, string $s, string $f, int $l): bool { if (!(error_reporting() & $no)) return false; throw new ErrorException('', 0, $no, '', $l); });
set_exception_handler(static function (Throwable $e): void { echo "R0: FAILED — an internal error (", get_class($e), ", line ", $e->getLine(), "); nothing was written; do not run it again — send the log.\n"; exit(3); });

// ── Constants (docs/69 §2.3.8; the specification's §1.0) ───────────────────────────────────────────────────────────
// sha256 of 'dn-r0-adj|' . <the 12 Sep adjustment recorded at docs/07-change-control.md:3540, in integer cents> (docs/69:564).
// The figure itself is in no file of R0, never printed, and no row's own hash is kept beyond its comparison.
const ADJ_CENTS_SHA = 'f637ec2f3c625bb9baa817ab21dda7ef11d00982f0d0391b7dcb87ff3b900619';
const ADJ_DIRECTION = 'out';                                    // docs/07:3538-3541: it cancels the account rows
const ADJ_CURRENCIES = ['UGX', ''];                             // docs/07:3540 ("UGX"); blank = the base
const ADJ_DATE = '2026-09-12';                                  // P0's ADJ_DATE, scripts/bank-recon-p0.php:41
const CUTOFF_DAY = '2026-10-04';                                // docs/69:196, docs/07:3518-3527
const TOOL_BY = 'staff_records_currency 5.18.70';               // tools/staff_records_currency.php:126
const TOOL_BY_69 = 'staff_records_currency 5.18.69';            // fec15bc0's tools/staff_records_currency.php:105
const TOOL_REASON_MARK = 'by the Manual Entry form';            // tools/staff_records_currency.php:134; fec15bc0's :110
const TOOL_REASON_END = '(5.18.70)]';                           // the stamp's end, tools/staff_records_currency.php:134, :145
const TOOL_REASON_END_69 = '(5.18.69)]';                        // fec15bc0's :110, :121
const PAGE_ROWS = 2000;                                         // L1 is read in id-ordered pages of this size
const CASHINS = [3, 4, 5];                                      // docs/07-change-control.md:3931-3933 (25 Sep, Collection, USD)
const PAIR_SOURCES = ['account_transfer', 'funding', 'fx_exchange'];   // lib/CashbookService.php:818
const CASH_UUID = '6efe0fa8-36b2-4dd1-b049-427bffc7d369';       // PaymentUuids::CASH, lib/PaymentUuids.php:36
const WINDOWS = [0, 7];                                         // days: same day, and ±7 (a decision of the specification)
const WAIT_SECONDS = 120;                                       // scripts/bank-recon-p0.php:42

/** Only a value from a fixed list is ever printed; anything else reads "(other)". */
const KINDS    = ['bank', 'cash', 'momo', 'receivable', 'payable', 'inventory', 'asset', 'director', 'equity'];
const STATUSES = ['approved', 'pending_approval', 'rejected', 'voided', 'voided_reconcile'];
const JSTAT    = ['approved', 'pending', 'pending_approval', 'rejected', 'voided'];
const SLSTAT   = ['active', 'voided', 'cancelled'];
const ACTIONS  = ['create', 'update', 'void', 'delete'];
const VOIDED   = ['voided', 'voided_reconcile'];
const REFUND_CATEGORIES = ['Refund', 'Customer Refund', 'Customer Commission'];
const TABLES   = ['cb_ledger', 'cb_accounts', 'fin_audit', 'staff_ledger', 'equipment_assignments',
                  'payment_collections', 'cash_ins', 'cash_expenses', 'crm_payment_retry'];
const STORES   = ['payment_collections', 'cash_ins', 'cash_expenses', 'crm_payment_retry'];
const LEDGER_COLUMNS = ['id', 'sr', 'date', 'direction', 'amount', 'ssp_amount', 'currency', 'status', 'description',
                        'validation_ref', 'source', 'category', 'txn_type', 'person', 'crm_payment_id', 'crm_client_id',
                        'account_id', 'created_at'];
const NEED = [
    'cb_accounts'           => ['id', 'kind'],
    'fin_audit'             => ['id', 'record_type', 'record_id', 'action', 'created_at', 'changed_keys', 'before_json', 'after_json'],
    'staff_ledger'          => ['idempotency_key', 'status', 'voided_by', 'voided_at'],
    'equipment_assignments' => ['released_at', 'starlink_account'],
];
const STORE_FIELDS = [
    'payment_collections' => ['id', 'crm_payment_id', 'method', 'status', 'voided_by', 'voided_at', 'audit_log', 'source',
                              'crm_customer_id', 'kyc_app_id', 'amount', 'currency', 'created_at'],
    'cash_ins'            => ['id', 'status', 'voided_by', 'voided_at', 'created_at', 'category', 'currency', 'audit_log'],
    'cash_expenses'       => ['id', 'status', 'voided_at', 'audit_log'],
    'crm_payment_retry'   => ['id', 'collection_id', 'crm_payment_id', 'crm_id', 'status'],
];
const FA_TEXT  = ['status', 'date', 'direction', 'currency', 'created_at'];
const FA_REAL  = ['amount', 'ssp_amount'];
const FA_INT   = ['account_id', 'crm_payment_id'];
const VBY      = ['tool70' => 'the 5.18.70 tool', 'tool69' => 'the 5.18.69 tool', 'none' => 'none', 'other' => 'another value'];
const FORMS    = ['actor' => 'actor-minute', 'reconcile' => 'reconcile', 'tool' => 'tool', 'plain' => 'plain', 'none' => 'no stamp'];
const METHOD_CLASSES = ['cash', 'uuid cash', 'mobile money', 'bank', 'cheque', 'uuid other', 'blank', '(other)'];

function out(string $s = ''): void { echo $s, "\n"; }
function refuse(int $code, string $why): void { out("R0: REFUSED — {$why}. Nothing was written."); exit($code); }
function fail(string $why): void { out(''); out("R0: FAILED — {$why}. Nothing was written."); exit(3); }
function pick(?string $v, array $allowed): string { return in_array((string)$v, $allowed, true) ? (string)$v : '(other)'; }
/** A currency code as stored (case kept: a lowercase code is a finding), "(blank)" for none, or "(other)". */
function code(?string $v): string { if ((string)$v === '') return '(blank)'; return preg_match('/^[A-Za-z]{3}$/', (string)$v) ? (string)$v : '(other)'; }
/** A ledger source: the plugin's writers use short lowercase identifiers; anything else reads "(other)". */
function src(?string $v): string { return preg_match('/^[a-z][a-z_]{0,31}$/', (string)$v) ? (string)$v : '(other)'; }
/** A date or a month for the log, or "?" when it is not one. */
function day(?string $v): string { return preg_match('/^\d{4}-\d{2}(-\d{2})?$/', (string)$v) ? (string)$v : '?'; }
/** A label line and a continuation line (scripts/bank-recon-p0.php:213, :234). */
function L(string $label, string $text): string { return '  ' . str_pad($label, 16) . $text; }
function C(string $text): string { return str_repeat(' ', 18) . $text; }

$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => $a !== '--'));
foreach ($args as $a) {
    if (strpos((string)$a, '-') === 0) refuse(2, "unknown option {$a} (R0 takes none)");
}
if (count($args) !== 2) refuse(2, 'usage: <data dir> <plugin dir>');
[$dataDir, $pluginDir] = $args;
$dataReal = realpath($dataDir); $plugReal = realpath($pluginDir);
if ($dataReal === false || !is_dir($dataReal)) refuse(2, "no data folder at {$dataDir}");
if ($plugReal !== false && $dataReal === rtrim($plugReal, '/') . '/data') {
    refuse(2, 'the data folder is <plugin>/data — the stray store\'s old place, which is never opened');
}
$db = $dataReal . '/plugin.sqlite3';
if (!is_file($db)) refuse(2, "no plugin.sqlite3 in {$dataReal}");
if (strpbrk($db, '?#%') !== false) refuse(2, 'the database path holds ? # or %, which an SQLite URI cannot carry');

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

/** Every query: one statement, SELECT or WITH; the SQL, its parameters and every fetched row feed the reading's digest. */
function q(PDO $pdo, HashContext $h, string $sql, array $p = []): array {
    $s = ltrim($sql);
    if (!preg_match('/^(SELECT|WITH)\b/i', $s) || strpos(rtrim($s, "; \n\t"), ';') !== false) {
        fail('a statement that is not a single SELECT was refused');
    }
    hash_update($h, $sql . "\n" . serialize($p) . "\n");
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) hash_update($h, serialize($row) . "\n");
    return $rows;
}

// ── Small pure helpers (no I/O) ──────────────────────────────────────────────────────────────────────────────────
/** Days since 1970-01-01 for a valid YYYY-MM-DD, else -1 (Howard Hinnant's days_from_civil). */
function dnum(string $d): int {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return -1;
    $y = (int)$m[1]; $mo = (int)$m[2]; $dd = (int)$m[3];
    if (!checkdate($mo, $dd, $y)) return -1;
    $y -= $mo <= 2 ? 1 : 0;
    $era = intdiv($y >= 0 ? $y : $y - 399, 400);
    $yoe = $y - $era * 400;
    $doy = intdiv(153 * ($mo + ($mo > 2 ? -3 : 9)) + 2, 5) + $dd - 1;
    $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
    return $era * 146097 + $doe - 719468;
}
/** Text normalised for a comparison, hashed; never printed (docs/69:557). */
function texthash(string $prefix, string $s, bool $strip): string {
    $raw = $s;
    if ($strip) {
        $s = preg_replace('/ \[(?:VOIDED|REVERSED|CORRECTED)[^\]]*\]/', '', $s);
        if (!is_string($s)) return sha1($prefix . 'raw|' . $raw);
    }
    if (!mb_check_encoding($s, 'UTF-8')) return sha1($prefix . 'raw|' . $raw);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s, 'UTF-8'));
    if (!is_string($s)) return sha1($prefix . 'raw|' . $raw);
    $s = trim($s);
    return $s === '' ? '' : sha1($prefix . $s);
}
/** A list of ids, at most $max, then "… <k> more"; "none" when empty. */
function idlist(array $ids, int $max): string {
    if (!$ids) return 'none';
    $show = array_slice($ids, 0, $max);
    return implode(', ', array_map(static fn($x) => (string)(int)$x, $show)) . (count($ids) > $max ? ', … ' . (count($ids) - $max) . ' more' : '');
}
/** "<k> <n> · …" over a map already sorted, or "none". */
function joinmap(array $m): string {
    if (!$m) return 'none';
    $p = []; foreach ($m as $k => $n) $p[] = $k . ' ' . (int)$n;
    return implode(' · ', $p);
}
/** Why a table cannot be read, as printed. */
function na(string $t, string $why): string {
    if ($why === 'absent') return "absent ({$t} is not in this book)";
    if ($why === 'shape') return "absent ({$t} has an unexpected shape)";
    if ($why === 'nojson') return 'absent (no JSON functions)';
    return 'absent (column ' . $t . '.' . substr($why, 7) . ' missing)';
}
function isVoided(string $status): bool { return in_array($status, VOIDED, true); }
/** A store field as SQL: the JSON field, guarded (CASE evaluates only the branch it needs), or the proper table's column. */
function J(string $shape, array $cols, string $f): string {
    if ($shape === 'json') return "CASE WHEN json_valid(data) THEN json_extract(data,'$." . $f . "') END";
    return in_array($f, $cols, true) ? '"' . $f . '"' : 'NULL';
}
/** An audit_log test: json_each never sees text that is not a JSON array; an element that is not an object is skipped. */
function JA(string $shape, array $cols, string $what): string {
    if ($shape === 'json') { $src = "json_each(data,'$.audit_log')"; $g1 = 'json_valid(data)'; $g2 = "json_type(data,'$.audit_log')='array'"; }
    elseif (in_array('audit_log', $cols, true)) { $src = 'json_each("audit_log")'; $g1 = 'json_valid("audit_log")'; $g2 = "json_type(\"audit_log\")='array'"; }
    else return 'NULL';
    $act = "CASE WHEN e.type='object' THEN json_extract(e.value,'$.action') END";
    $sub = $what === 'void_at'
        ? "(SELECT substr(MIN(CASE WHEN e.type='object' THEN json_extract(e.value,'$.at') END),1,16) FROM {$src} e WHERE {$act} = 'void')"
        : "EXISTS(SELECT 1 FROM {$src} e WHERE {$act} = 'relabel')";
    return "CASE WHEN {$g1} THEN CASE WHEN {$g2} THEN {$sub} END END";
}
/** A voided_by value, classified in SQL: the name itself never reaches PHP. */
function VB(string $x): string {
    return "CASE WHEN {$x}='" . TOOL_BY . "' THEN 'tool70' WHEN {$x}='" . TOOL_BY_69 . "' THEN 'tool69' WHEN COALESCE({$x},'')='' THEN 'none' ELSE 'other' END";
}
/** A fin_audit snapshot field, guarded; numbers CAST (a snapshot may hold "1500" as a string, lib/FinAudit.php:114-116). */
function FA(bool $json, string $side, string $f, string $type): string {
    $col = $side === 'b' ? 'before_json' : 'after_json';
    if (!$json) return "NULL AS {$side}_{$f}";
    $x = "json_extract({$col},'$." . $f . "')";
    if ($type !== '') $x = "CAST({$x} AS {$type})";
    return "CASE WHEN json_valid({$col}) THEN {$x} END AS {$side}_{$f}";
}
/** The method class of a collection's method (lib/PaymentUuids.php:65-100). */
function methodClass(string $raw): string {
    if (trim($raw) === '') return 'blank';
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw)) {
        return strtolower($raw) === CASH_UUID ? 'uuid cash' : 'uuid other';
    }
    if (ctype_digit($raw)) return ['2' => 'cash', '3' => 'bank', '6' => 'mobile money'][(string)(int)$raw] ?? '(other)';
    $slug = str_replace([' ', '-'], '_', strtolower(trim($raw)));
    $map = ['cash' => 'cash', 'mobile_money' => 'mobile money', 'mobile' => 'mobile money', 'bank_transfer' => 'bank',
            'bank' => 'bank', 'check' => 'cheque', 'cheque' => 'cheque'];
    return $map[$slug] ?? '(other)';
}
/** A time a JSON record gives, or ''. */
function jtime(?string $v): string { $v = (string)$v; return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 16) : ''; }
/**
 * A row's creation minute as a stamp writes one, "Y-m-d H:i", from created_at's first 16 characters; a "T" between the
 * date and the time (uCRM's createdDate, kept by lib/CashbookService.php:2534, :2564) reads as the space. '' when it is
 * not a date and a time — then no stamp of the row is set aside (§1.3).
 */
function madeMinute(string $cat): string {
    return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}$/', $cat) === 1 ? substr_replace($cat, ' ', 10, 1) : '';
}
/** The text with the byte spans [offset, length] taken out (they may overlap); what is left keeps its order. */
function without(string $s, array $spans): string {
    if (!$spans) return $s;
    usort($spans, static fn($a, $b) => ($a[0] ?? 0) <=> ($b[0] ?? 0));
    $kept = ''; $at = 0;
    foreach ($spans as $sp) {
        $from = (int)($sp[0] ?? 0); $to = $from + (int)($sp[1] ?? 0);
        if ($from > $at) $kept .= substr($s, $at, $from - $at);
        if ($to > $at) $at = $to;
    }
    return $kept . substr($s, $at);
}
/**
 * Rows grouped into buckets, each sorted by day: per row, how many partners it has (others in its bucket within W days,
 * its own two-leg partner excluded) and how many of them are live. $W < 0 means no window.
 */
function bucketScan(array $buckets, array $dn, array $live, array $legOf, array $bucketOf, int $W): array {
    $partners = []; $liveP = [];
    foreach ($buckets as $b) {
        if (count($b) < 2) continue;
        if ($W >= 0) usort($b, static fn($x, $y) => [$dn[$x] ?? 0, $x] <=> [$dn[$y] ?? 0, $y]);
        $n = count($b); $pre = [0];
        for ($k = 0; $k < $n; $k++) $pre[] = ($pre[$k] ?? 0) + ((($live[$b[$k] ?? 0] ?? false)) ? 1 : 0);
        $lo = 0; $hi = 0;
        for ($k = 0; $k < $n; $k++) {
            $i = $b[$k] ?? 0;
            if ($W >= 0) {
                $d = $dn[$i] ?? 0;
                while ($lo < $n && ($dn[$b[$lo] ?? 0] ?? 0) < $d - $W) $lo++;
                if ($hi < $k + 1) $hi = $k + 1;
                while ($hi < $n && ($dn[$b[$hi] ?? 0] ?? 0) <= $d + $W) $hi++;
            } else { $lo = 0; $hi = $n; }
            $in = $hi - $lo; $liveIn = ($pre[$hi] ?? 0) - ($pre[$lo] ?? 0);
            $self = ($live[$i] ?? false) ? 1 : 0;
            $p = $in - 1; $lp = $liveIn - $self;
            $j = $legOf[$i] ?? -1;
            if ($j >= 0 && ($bucketOf[$j] ?? null) === ($bucketOf[$i] ?? '') && ($W < 0 || abs(($dn[$j] ?? 0) - ($dn[$i] ?? 0)) <= $W)) {
                $p--; if ($live[$j] ?? false) $lp--;
            }
            if ($p > 0) { $partners[$i] = $p; $liveP[$i] = $lp; }
        }
    }
    return [$partners, $liveP];
}
/** "pairs · rows · voided (live twin) · live dup" from per-row partner counts. */
function stat4(array $partners, array $liveP, array $live): array {
    $pairs = 0; $rows = 0; $vo = 0; $lt = 0; $ld = 0;
    foreach ($partners as $i => $p) {
        $pairs += $p; $rows++;
        $lp = $liveP[$i] ?? 0;
        if ($live[$i] ?? false) { if ($lp > 0) $ld++; }
        else { $vo++; if ($lp > 0) $lt++; }
    }
    return ['pairs' => intdiv($pairs, 2), 'rows' => $rows, 'voided' => $vo, 'lt' => $lt, 'ld' => $ld];
}
function statText(array $s): string {
    return "pairs {$s['pairs']} · rows {$s['rows']} · voided {$s['voided']} (live twin {$s['lt']}) · live dup {$s['ld']}";
}
/** From a set of unordered pairs "i:j": per-row partner and live-partner counts. */
function pairCounts(array $pairs, array $live): array {
    $p = []; $lp = [];
    foreach ($pairs as $k => $_) {
        [$a, $b] = array_map('intval', explode(':', (string)$k));
        $p[$a] = ($p[$a] ?? 0) + 1; $p[$b] = ($p[$b] ?? 0) + 1;
        $lp[$a] = ($lp[$a] ?? 0) + (($live[$b] ?? false) ? 1 : 0);
        $lp[$b] = ($lp[$b] ?? 0) + (($live[$a] ?? false) ? 1 : 0);
    }
    return [$p, $lp];
}
function pkey(int $a, int $b): string { return $a < $b ? "{$a}:{$b}" : "{$b}:{$a}"; }
/** "rows <n> · voided <n> (live twin <n>)" over the edges of a family. */
function famText(array $edges, array $live): string {
    [$p, $lp] = pairCounts($edges, $live);
    $vo = 0; $lt = 0;
    foreach ($p as $i => $_) if (!($live[$i] ?? false)) { $vo++; if (($lp[$i] ?? 0) > 0) $lt++; }
    return 'rows ' . count($p) . " · voided {$vo} (live twin {$lt})";
}

/** One copy of each repeated short string per reading (dates, codes, sources): 30,000 rows, a few megabytes less. */
function ist(array &$pool, string $s): string { return $pool[$s] ??= $s; }
/** The unordered pairs of a windowed bucket rule, explicitly (used where a family needs to know who the partner is). */
function windowPairs(array $buckets, array $dn, array $legOf, int $W): array {
    $out = [];
    foreach ($buckets as $b) {
        if (count($b) < 2) continue;
        usort($b, static fn($x, $y) => [$dn[$x] ?? 0, $x] <=> [$dn[$y] ?? 0, $y]);
        $n = count($b);
        for ($k = 0; $k < $n; $k++) {
            $i = $b[$k] ?? 0;
            for ($q = $k + 1; $q < $n; $q++) {
                $j = $b[$q] ?? 0;
                if (($dn[$j] ?? 0) - ($dn[$i] ?? 0) > $W) break;
                if (($legOf[$i] ?? -1) === $j) continue;
                $out[] = $i < $j ? $i * 4294967296 + $j : $j * 4294967296 + $i;
            }
        }
    }
    return $out;
}
/** A voided row is a twin (and a live twin) when a rule gave it a partner (a live one). */
function markTwins(array $p, array $lp, array $live, array &$twin, array &$liveTwin): void {
    foreach ($p as $i => $_) {
        if ($live[$i] ?? true) continue;
        $twin[$i] = true;
        if (($lp[$i] ?? 0) > 0) $liveTwin[$i] = true;
    }
}
/** Rows(c): the rows naming COL-c, and the receipt-family rows of each payment id in P (by pay or by PAY-p). */
function rowsOfCol(int $c, array $rowsByCol, array $rfByPay, array $rfByNpay, array $P): array {
    $r = $rowsByCol[$c] ?? [];
    foreach ($P as $p => $_) $r = array_merge($r, $rfByPay[$p] ?? [], $rfByNpay[$p] ?? []);
    $r = array_values(array_unique($r)); sort($r);
    return $r;
}
/** How the voided rows' first void compares with one candidate's creation; never "at or after" without a creation time. */
function vsCand(string $what, string $cat, array $V, array $vt): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $cat)) return '            candidate ' . $what . ': creation unknown';
    $b = 0; $a = 0; $u = 0;
    foreach ($V as $i) { $t = (string)($vt[$i] ?? ''); if ($t === '') $u++; elseif (strcmp($t, $cat) < 0) $b++; else $a++; }
    return 'vs candidate ' . $what . "  before its creation {$b} · at or after {$a} · unknown {$u}";
}
function formText(array $m): string {
    return 'actor-minute ' . ($m['actor'] ?? 0) . ' · reconcile ' . ($m['reconcile'] ?? 0) . ' · tool ' . ($m['tool'] ?? 0) . ' · plain ' . ($m['plain'] ?? 0) . ' · no stamp ' . ($m['none'] ?? 0);
}
function faIds(array $ids): string { return $ids ? implode(', ', array_map(static fn($x) => (string)(int)$x, $ids)) : '–'; }
/** The current kind of an account id, for a deleted or edited row's before-account. */
function kindOfAcc(bool $hasAcc, array $accKind, int $acc): string {
    if (!$hasAcc) return '(no accounts table)';
    if ($acc === 0) return 'unassigned';
    return $accKind[$acc] ?? 'missing';
}
/** ": id <id>  <date>  <code>  <status>  <kind>, …" for at most 20 rows. */
function candList(array $rows, array $C): string {
    if (!$rows) return '';
    $p = [];
    foreach (array_slice($rows, 0, 20) as $i) {
        $p[] = 'id ' . ($C['id'][$i] ?? 0) . '  ' . day((string)($C['d'][$i] ?? '')) . '  ' . ($C['curC'][$i] ?? '(other)') . '  '
             . pick((string)($C['status'][$i] ?? ''), STATUSES) . '  ' . ($C['kind'][$i] ?? '');
    }
    return ': ' . implode(', ', $p) . (count($rows) > 20 ? ', … ' . (count($rows) - 20) . ' more' : '');
}

/** One reading of the book, on its own immutable read-only connection: the lines to print, its digest, its own checks. */
function readBook(string $db): array {
    $L = [];
    $o = static function (string $s = '') use (&$L): void { $L[] = $s; };
    try {
        $pdo = new PDO('sqlite:file:' . $db . '?immutable=1', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 15,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);
        $pdo->exec('PRAGMA query_only = ON');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('BEGIN');
    } catch (Throwable $e) {
        refuse(2, 'the database could not be opened read-only and immutable (' . get_class($e) . ')');
    }
    $h = hash_init('sha256');
    try {
    $qo = (int)(q($pdo, $h, 'SELECT * FROM pragma_query_only')[0]['query_only'] ?? 0);
    if ($qo !== 1) fail('query_only is not on for this connection');
    if ((int)(q($pdo, $h, 'SELECT * FROM pragma_temp_store')[0]['temp_store'] ?? 0) !== 2) fail('temp_store is not MEMORY for this connection');

    // ── §1.1 the schema, first ───────────────────────────────────────────────────────────────────────────────────
    $present = array_column(q($pdo, $h, "SELECT name FROM sqlite_master WHERE type='table'
        AND name IN ('cb_ledger','cb_accounts','fin_audit','staff_ledger','equipment_assignments',
                     'payment_collections','cash_ins','cash_expenses','crm_payment_retry') ORDER BY name"), 'name');
    $cols = []; $cnt = [];
    foreach (TABLES as $t) {
        if (!in_array($t, $present, true)) continue;
        $cols[$t] = array_map('strval', array_column(q($pdo, $h, "SELECT name FROM pragma_table_info('{$t}') ORDER BY cid"), 'name'));
        $cnt[$t] = (int)(q($pdo, $h, "SELECT COUNT(*) AS n FROM {$t}")[0]['n'] ?? 0);
    }
    if (!isset($cols['cb_ledger'])) refuse(2, 'no cb_ledger table');
    foreach (LEDGER_COLUMNS as $c) {
        if (!in_array($c, $cols['cb_ledger'], true)) refuse(2, "cb_ledger has no {$c} column — not the schema this was written for");
    }
    try { $json = (int)(q($pdo, $h, "SELECT json_valid('{}') AS j")[0]['j'] ?? 0) === 1; }
    catch (PDOException $e) { if (strpos($e->getMessage(), 'no such function') === false) throw $e; $json = false; }

    $ok = []; $shape = []; $missing = [];
    foreach (NEED as $t => $need) {
        if (!isset($cols[$t])) { $ok[$t] = 'absent'; continue; }
        $ok[$t] = true;
        foreach ($need as $c) {
            if (!in_array($c, $cols[$t] ?? [], true)) { $missing[] = "{$t}.{$c}"; if (($ok[$t] ?? '') === true) $ok[$t] = 'column:' . $c; }
        }
    }
    if ($ok['fin_audit'] === true && !$json) $ok['fin_audit_json'] = 'nojson'; else $ok['fin_audit_json'] = $ok['fin_audit'];
    foreach (STORES as $t) {
        if (!isset($cols[$t])) { $ok[$t] = 'absent'; $shape[$t] = 'absent'; continue; }
        $tc = $cols[$t] ?? [];
        if (in_array('data', $tc, true)) $shape[$t] = 'json';
        elseif (count($tc) > 2) $shape[$t] = 'proper';
        else { $shape[$t] = 'shape'; $ok[$t] = 'shape'; continue; }
        $ok[$t] = true;
        if (($shape[$t] ?? '') === 'proper') {
            foreach (STORE_FIELDS[$t] ?? [] as $f) {
                if (in_array($f, $tc, true)) continue;
                $missing[] = "{$t}.{$f}";
                if ($f !== 'audit_log' && ($ok[$t] ?? '') === true) $ok[$t] = 'column:' . $f;
            }
        }
        if (($ok[$t] ?? '') === true && !$json) $ok[$t] = 'nojson';
    }
    $hasAcc  = $ok['cb_accounts'] === true;
    $accJoin = $hasAcc ? 'LEFT JOIN cb_accounts a ON a.id = l.account_id' : 'LEFT JOIN (SELECT NULL AS id, NULL AS kind) a ON 0';

    // ── L1: every ledger row, in id-ordered pages; description and person reduced to flags and hashes, page by page ─
    $C = ['id' => [], 'srcol' => [], 'vref' => [], 'vcol' => [], 'nref' => [], 'npay' => [], 'payref' => [], 'd' => [], 'dn' => [],
          'dir' => [], 'cents' => [], 'curU' => [], 'curC' => [], 'adj' => [], 'status' => [], 'live' => [], 'source' => [],
          'rf' => [], 'receipt' => [], 'pay' => [], 'cli' => [], 'kyc' => [], 'kind' => [], 'acc' => [], 'cat' => [],
          'form' => [], 'smin' => [], 'marks' => [], 'mark' => [], 'tool70' => [], 'tool69' => [], 'reversed' => [],
          'th' => [], 'np' => []];
    $after = -1; $idx = []; $pool = [];
    do {
        $page = q($pdo, $h, "SELECT l.id, l.sr, substr(l.date,1,10) AS d, l.direction AS dir, l.amount, l.ssp_amount, l.currency AS cur,
                   l.status, l.source, l.category, l.txn_type, TRIM(l.validation_ref) AS vref, l.person, l.description,
                   CAST(COALESCE(l.crm_payment_id,0) AS INTEGER) AS pay, CAST(COALESCE(l.crm_client_id,0) AS INTEGER) AS cli,
                   COALESCE(l.account_id,0) AS acc, a.kind AS akind, CASE WHEN a.id IS NULL THEN 1 ELSE 0 END AS amissing,
                   substr(l.created_at,1,16) AS cat
              FROM cb_ledger l {$accJoin}
             WHERE l.id > CAST(:after AS INTEGER) ORDER BY l.id LIMIT 2000", [':after' => $after]);
        $got = count($page);
        foreach ($page as $r) {
            $i = count($C['id']);
            $id = (int)$r['id']; $after = $id; $idx[$id] = $i;
            $C['id'][] = $id;
            $sr = (string)$r['sr']; $vref = (string)$r['vref'];
            $C['srcol'][] = preg_match('/^COL-(\d{1,18})$/', $sr, $m) ? (int)$m[1] : 0;
            $C['vref'][] = $vref;
            $C['vcol'][] = preg_match('/^COL-(\d{1,18})$/', $vref, $m) ? (int)$m[1] : 0;
            $nref = strpos($vref, 'CRM-PAY-') === 0 ? 'PAY-' . substr($vref, 8) : $vref;
            $C['nref'][] = $nref;
            $C['npay'][] = preg_match('/^PAY-(\d{1,18})$/', $nref, $m) ? (int)$m[1] : 0;
            $C['payref'][] = strpos($nref, 'PAY-') === 0;
            $d = (string)$r['d']; $dn = dnum($d);
            $C['d'][] = $dn >= 0 ? ist($pool, $d) : ''; $C['dn'][] = $dn;
            $dir = ist($pool, (string)$r['dir']); $C['dir'][] = $dir;
            $curRaw = (string)$r['cur']; $curU = ist($pool, strtoupper(trim($curRaw, ' ')));
            $C['curU'][] = $curU; $C['curC'][] = ist($pool, code((string)$curRaw));
            $C['cents'][] = (int)round(100 * ($curU === 'SSP' ? (float)($r['ssp_amount'] ?? 0) : (float)$r['amount']));
            $C['adj'][] = hash('sha256', 'dn-r0-adj|' . (int)round(100 * (float)$r['amount'])) === ADJ_CENTS_SHA;
            $status = ist($pool, (string)$r['status']); $C['status'][] = $status; $C['live'][] = !isVoided($status);
            $source = ist($pool, (string)$r['source']); $C['source'][] = $source;
            $cat = (string)$r['category'];
            $C['rf'][] = $dir === 'in' && $source !== 'crm_webhook_reversal' && strtoupper(trim((string)$r['txn_type'], ' ')) !== 'REFUND'
                         && !in_array($cat, REFUND_CATEGORIES, true);
            $C['receipt'][] = $dir === 'in' && $cat === 'Receipt';
            $C['pay'][] = (int)$r['pay']; $C['cli'][] = (int)$r['cli'];
            $C['kyc'][] = $source === 'kyc_cash_sale' || strpos($vref, 'CRM#') === 0;
            $acc = (int)$r['acc']; $C['acc'][] = $acc;
            $C['kind'][] = ist($pool, !$hasAcc ? '(no accounts table)' : ($acc === 0 ? 'unassigned' : ((int)$r['amissing'] === 1 ? 'missing' : pick((string)$r['akind'], KINDS))));
            $C['cat'][] = ist($pool, (string)$r['cat']);
            // a reversal row (§1.8): one test, used here and in §1.8
            $C['rev'][] = $source === 'crm_webhook_reversal' || strncasecmp($vref, 'REV-CRM-PAY-', 12) === 0;
            // the stamp forms (§1.3), byte-wise. A stamp whose minute is earlier than the row's own creation minute is not
            // this row's — a row cannot be voided before it exists: the reversal writer copies its original's text, stamps
            // included (webhook.php:3580-3582), and a hand entry can hold pasted text. Such a stamp sets neither the row's
            // form nor its void time, and its text is not read as a mark of this row.
            $desc = (string)$r['description'];
            $made = madeMinute((string)$r['cat']);
            $mins = []; $cut = []; $actor = 0; $recon = 0;
            preg_match_all('/ \[VOIDED: .*? — by [^\]]* (\d{4}-\d{2}-\d{2} \d{2}:\d{2})\]/s', $desc, $ma, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($ma ?? [] as $x) {
                $mi = (string)($x[1][0] ?? '');
                if ($made !== '' && strcmp($mi, $made) < 0) { $cut[] = [(int)($x[0][1] ?? 0), strlen((string)($x[0][0] ?? ''))]; continue; }
                $mins[] = $mi; $actor++;
            }
            preg_match_all('/ \[VOIDED: handover double-count reconcile (\d{4}-\d{2}-\d{2} \d{2}:\d{2})\]/', $desc, $mb, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($mb ?? [] as $x) {
                $mi = (string)($x[1][0] ?? '');
                if ($made !== '' && strcmp($mi, $made) < 0) { $cut[] = [(int)($x[0][1] ?? 0), strlen((string)($x[0][0] ?? ''))]; continue; }
                $mins[] = $mi; $recon++;
            }
            $mine = without($desc, $cut);
            $t70 = strpos($desc, TOOL_REASON_MARK) !== false && strpos($desc, TOOL_REASON_END) !== false;
            $t69 = strpos($desc, TOOL_REASON_MARK) !== false && strpos($desc, TOOL_REASON_END_69) !== false;
            $plain = preg_match('/ \[VOIDED: [^\]]*\]/', $mine) === 1;
            $form = $actor > 0 ? 'actor' : ($recon > 0 ? 'reconcile' : (($t70 || $t69) ? 'tool' : ($plain ? 'plain' : 'none')));
            $C['form'][] = $form; $C['smin'][] = $mins ? ist($pool, (string)min($mins)) : '';
            $C['marks'][] = substr_count($mine, '[VOIDED:'); $C['mark'][] = strpos($mine, '[VOIDED:') !== false;
            $C['rmark'][] = strpos($desc, '[VOIDED:') !== false; $C['early'][] = $cut !== [];
            $C['tool70'][] = $t70; $C['tool69'][] = $t69;
            $C['reversed'][] = strpos($desc, ' [REVERSED — CRM deleted]') !== false;
            $C['th'][] = texthash('t|', $desc, true);
            $C['np'][] = ist($pool, texthash('p|', (string)$r['person'], false));
            unset($desc, $mine, $mins, $cut, $ma, $mb);
        }
        unset($page, $r);
    } while ($got === PAGE_ROWS);
    unset($pool);
    $N = count($C['id']);
    $live = $C['live']; $dn = $C['dn'];

    // ── F1: fin_audit, cb_ledger records only ────────────────────────────────────────────────────────────────────
    $F = [];
    if ($ok['fin_audit'] === true) {
        $sel = ['id', 'record_id', 'action', 'substr(created_at,1,16) AS t', 'changed_keys AS ck',
                $json ? "CASE WHEN json_valid(before_json) THEN CASE WHEN CAST(json_extract(before_json,'$.id') AS INTEGER) = record_id THEN 1 ELSE 0 END ELSE 0 END AS bok" : 'NULL AS bok'];
        foreach (FA_TEXT as $f) { $sel[] = FA($json, 'b', $f, ''); $sel[] = FA($json, 'a', $f, ''); }
        foreach (FA_REAL as $f) { $sel[] = FA($json, 'b', $f, 'REAL'); $sel[] = FA($json, 'a', $f, 'REAL'); }
        foreach (FA_INT as $f)  { $sel[] = FA($json, 'b', $f, 'INTEGER'); $sel[] = FA($json, 'a', $f, 'INTEGER'); }
        $F = q($pdo, $h, 'SELECT ' . implode(', ', $sel) . " FROM fin_audit WHERE record_type='cb_ledger' ORDER BY id");
    }
    $faVoid = []; $faUpd = []; $faIds = []; $faDel = []; $faUpdRows = [];
    foreach ($F as $f) {
        $rid = (int)$f['record_id']; $act = (string)$f['action'];
        $faIds[$rid][$act][] = (int)$f['id'];
        if ($act === 'void') $faVoid[$rid][] = ['id' => (int)$f['id'], 't' => jtime((string)$f['t']), 'bok' => $f['bok'] === null ? null : (int)$f['bok']];
        if ($act === 'update') { $faUpd[$rid][] = ['id' => (int)$f['id'], 't' => (string)$f['t'],
                                   'desc' => strpos(',' . (string)$f['ck'] . ',', ',description,') !== false]; $faUpdRows[] = $f; }
        if ($act === 'delete') $faDel[] = $f;
    }
    unset($F);
    // the current kind of an account id (for a deleted or edited row's before-account)
    $accKind = [];
    if ($hasAcc) foreach (q($pdo, $h, 'SELECT id, kind FROM cb_accounts ORDER BY id') as $a) $accKind[(int)$a['id']] = pick((string)$a['kind'], KINDS);

    // ── S1–S4: the stores; every field named, nothing else (no name, no free text) ───────────────────────────────
    $S = [];
    foreach (STORES as $t) {
        $S[$t] = [];
        if (($ok[$t] ?? '') !== true) continue;
        $sh = $shape[$t] ?? ''; $tc = $cols[$t] ?? [];
        $okc = $sh === 'json' ? 'CASE WHEN json_valid(data) THEN 1 ELSE 0 END' : '1';
        if ($t === 'payment_collections') {
            $sql = "SELECT id AS rid, {$okc} AS ok, CAST(" . J($sh, $tc, 'id') . ' AS INTEGER) AS jid, CAST(' . J($sh, $tc, 'crm_payment_id') . ' AS INTEGER) AS pay, '
                 . J($sh, $tc, 'method') . ' AS method, ' . J($sh, $tc, 'status') . ' AS status, ' . VB(J($sh, $tc, 'voided_by')) . ' AS vby, '
                 . 'substr(' . J($sh, $tc, 'voided_at') . ',1,16) AS vat, ' . JA($sh, $tc, 'void_at') . ' AS void_at, ' . JA($sh, $tc, 'relabel') . ' AS relabelled, '
                 . J($sh, $tc, 'source') . ' AS source, CAST(' . J($sh, $tc, 'crm_customer_id') . ' AS INTEGER) AS cli, CAST(' . J($sh, $tc, 'kyc_app_id') . ' AS INTEGER) AS kapp, '
                 . J($sh, $tc, 'amount') . ' AS amount, ' . J($sh, $tc, 'currency') . ' AS cur, substr(' . J($sh, $tc, 'created_at') . ",1,10) AS d FROM payment_collections ORDER BY id";
        } elseif ($t === 'cash_ins') {
            $sql = "SELECT id AS rid, {$okc} AS ok, CAST(" . J($sh, $tc, 'id') . ' AS INTEGER) AS jid, ' . J($sh, $tc, 'status') . ' AS status, ' . VB(J($sh, $tc, 'voided_by')) . ' AS vby, '
                 . 'substr(' . J($sh, $tc, 'voided_at') . ',1,10) AS vat, substr(' . J($sh, $tc, 'created_at') . ',1,10) AS d, '
                 . 'CASE WHEN ' . J($sh, $tc, 'category') . "='Collection' THEN 1 ELSE 0 END AS catrec, " . J($sh, $tc, 'currency') . ' AS cur, '
                 . JA($sh, $tc, 'void_at') . ' AS void_at, ' . JA($sh, $tc, 'relabel') . ' AS relabelled FROM cash_ins ORDER BY id';
        } elseif ($t === 'cash_expenses') {
            $sql = "SELECT id AS rid, {$okc} AS ok, CAST(" . J($sh, $tc, 'id') . ' AS INTEGER) AS jid, ' . J($sh, $tc, 'status') . ' AS status, '
                 . 'substr(' . J($sh, $tc, 'voided_at') . ',1,16) AS vat, ' . JA($sh, $tc, 'void_at') . ' AS void_at FROM cash_expenses ORDER BY id';
        } else {
            $sql = "SELECT id AS rid, {$okc} AS ok, CAST(" . J($sh, $tc, 'id') . ' AS INTEGER) AS jid, CAST(' . J($sh, $tc, 'collection_id') . ' AS INTEGER) AS col, '
                 . 'CAST(' . J($sh, $tc, 'crm_payment_id') . ' AS INTEGER) AS p1, CAST(' . J($sh, $tc, 'crm_id') . ' AS INTEGER) AS p2, '
                 . J($sh, $tc, 'status') . ' AS status FROM crm_payment_retry ORDER BY id';
        }
        $S[$t] = q($pdo, $h, $sql);
    }
    $bad = []; $rec = [];
    foreach (STORES as $t) {
        $bad[$t] = 0; $rec[$t] = [];
        foreach ($S[$t] ?? [] as $x) {
            if ((int)$x['ok'] !== 1) { $bad[$t]++; continue; }
            $x['jid'] = $x['jid'] === null ? (int)$x['rid'] : (int)$x['jid'];
            $rec[$t][] = $x;
        }
    }
    unset($S);
    $PC = $ok['payment_collections'] === true; $RT = $ok['crm_payment_retry'] === true;

    // ── header (§1.1) ────────────────────────────────────────────────────────────────────────────────────────────
    $o(L('database', $db . ' — read-only, immutable, query_only=1; JSON functions ' . ($json ? 'yes' : 'no')));
    $tp = ['cb_ledger ' . ($cnt['cb_ledger'] ?? 0)];
    foreach (['cb_accounts', 'fin_audit', 'staff_ledger', 'equipment_assignments'] as $t) $tp[] = $t . ' ' . (isset($cnt[$t]) ? (string)($cnt[$t] ?? 0) : 'absent');
    $o(L('tables', implode(' · ', $tp)));
    $sp = [];
    foreach (STORES as $t) {
        $sh = $shape[$t] ?? 'absent';
        if ($sh === 'absent') { $sp[] = "{$t} absent"; continue; }
        if ($sh === 'shape') { $sp[] = "{$t} absent (unexpected shape)"; continue; }
        if ($sh === 'proper') { $sp[] = "{$t} proper " . ($cnt[$t] ?? 0); continue; }
        $sp[] = "{$t} json " . ($cnt[$t] ?? 0) . ($json ? ' (not valid JSON ' . ($bad[$t] ?? 0) . ')' : ' (not valid JSON: not measured, no JSON functions)');
    }
    $o(L('stores', implode(' · ', $sp)));
    sort($missing, SORT_STRING);
    $o(L('columns', $missing ? 'missing: ' . implode(', ', $missing) : 'none missing'));

    // ── KYC rows whose CRM# is an application id (§1.5.0) ────────────────────────────────────────────────────────
    $kycCli = []; $kycApp = []; $kycAppIds = 0; $kycAmbig = 0;
    if ($PC) foreach ($rec['payment_collections'] as $x) {
        if ((string)$x['source'] !== 'kyc_cash_sale') continue;
        if ((int)$x['cli'] > 0) $kycCli[(int)$x['cli']] = true;
        if ((int)$x['kapp'] > 0) $kycApp[(int)$x['kapp']] = true;
    }
    $cli = $C['cli'];
    if ($PC) for ($i = 0; $i < $N; $i++) {
        $x = $cli[$i] ?? 0;
        if (!($C['kyc'][$i] ?? false) || $x <= 0) continue;
        $c1 = isset($kycCli[$x]); $c2 = isset($kycApp[$x]);
        if (!$c1 && $c2) { $cli[$i] = 0; $kycAppIds++; }
        elseif ($c1 && $c2) $kycAmbig++;
    }

    // ── two-leg entries (§1.5.0) ─────────────────────────────────────────────────────────────────────────────────
    $grp = [];
    for ($i = 0; $i < $N; $i++) { $v = $C['vref'][$i] ?? ''; if ($v !== '') $grp[($C['source'][$i] ?? '') . "\x00" . $v][] = $i; }
    $legOf = []; $legF = 0; $legS = 0; $legO = 0;
    foreach ($grp as $g) {
        if (count($g) !== 2) continue;
        [$a, $b] = $g; $srcA = $C['source'][$a] ?? '';
        $opp = [$C['dir'][$a] ?? '', $C['dir'][$b] ?? '']; sort($opp, SORT_STRING);
        $isPair = in_array($srcA, PAIR_SOURCES, true);
        if (!$isPair && $opp !== ['in', 'out']) continue;
        $legOf[$a] = $b; $legOf[$b] = $a;
        if ($srcA === 'funding') $legF++; elseif ($isPair) $legS++; else $legO++;
    }
    unset($grp);

    // ── §1.2 voided rows ─────────────────────────────────────────────────────────────────────────────────────────
    $V = []; for ($i = 0; $i < $N; $i++) if (!($live[$i] ?? true)) $V[] = $i;
    $nV = count($V); $nVo = 0; $nVr = 0;
    foreach ($V as $i) { if (($C['status'][$i] ?? '') === 'voided') $nVo++; else $nVr++; }
    $bySrc = [];
    foreach (q($pdo, $h, "SELECT source AS s, COUNT(*) AS n FROM cb_ledger WHERE status IN ('voided','voided_reconcile') GROUP BY s ORDER BY s") as $r) {
        $k = src((string)$r['s']); $bySrc[$k] = ($bySrc[$k] ?? 0) + (int)$r['n'];
    }
    ksort($bySrc, SORT_STRING);
    $byMon = [];
    foreach (q($pdo, $h, "SELECT substr(date,1,7) AS m, COUNT(*) AS n FROM cb_ledger WHERE status IN ('voided','voided_reconcile') GROUP BY m ORDER BY m") as $r) {
        $k = day((string)$r['m']); $byMon[$k] = ($byMon[$k] ?? 0) + (int)$r['n'];
    }
    ksort($byMon, SORT_STRING);
    $path = ['reconcile' => 0, 'single+finaudit' => 0, 'pair' => 0, 'single, no finaudit' => 0, 'records tool' => 0, 'staff cashbooks' => 0, 'no stamp' => 0];
    $pairKeys = [];
    $FAok = $ok['fin_audit'] === true;
    foreach ($V as $i) {
        $st = $C['status'][$i] ?? ''; $form = $C['form'][$i] ?? 'none'; $id = $C['id'][$i] ?? 0;
        if ($st === 'voided_reconcile' || $form === 'reconcile') $p = 'reconcile';
        elseif ($st === 'voided' && $FAok && isset($faVoid[$id])) $p = 'single+finaudit';
        elseif ($st === 'voided' && in_array($C['source'][$i] ?? '', PAIR_SOURCES, true) && ($C['vref'][$i] ?? '') !== '' && $form === 'actor') {
            $p = 'pair'; $pairKeys[($C['source'][$i] ?? '') . "\x00" . ($C['vref'][$i] ?? '')] = true;
        }
        elseif ($form === 'actor') $p = 'single, no finaudit';
        elseif ($form === 'tool') $p = 'records tool';
        elseif ($form === 'plain') $p = 'staff cashbooks';
        else $p = 'no stamp';
        $path[$p]++;
    }
    $o(L('voided rows', "{$nV} of {$N} rows: voided {$nVo} · voided_reconcile {$nVr}"));
    $o(C('by source   ' . joinmap($bySrc)));
    $o(C('by path     reconcile ' . $path['reconcile'] . ' · single+finaudit ' . ($FAok ? (string)$path['single+finaudit'] : na('fin_audit', (string)$ok['fin_audit']))
        . ' · pair ' . $path['pair'] . ' (' . count($pairKeys) . ' pair(s)) · single, no finaudit ' . $path['single, no finaudit']
        . ' · records tool ' . $path['records tool'] . ' · staff cashbooks ' . $path['staff cashbooks'] . ' · no stamp ' . $path['no stamp']));
    $o(C('by month    ' . joinmap($byMon)));
    $parts = ['source' => array_sum($bySrc) === $nV, 'path' => array_sum($path) === $nV, 'month' => array_sum($byMon) === $nV];

    // ── §1.3 void time and evidence ──────────────────────────────────────────────────────────────────────────────
    $pcByJid = []; $pcByPay = []; $ceByJid = [];
    foreach ($rec['payment_collections'] as $x) {
        $t = (string)$x['status'] === 'voided' ? (jtime((string)$x['vat']) !== '' ? jtime((string)$x['vat']) : jtime((string)$x['void_at'])) : '';
        $pcByJid[(int)$x['jid']][] = $t;
        if ((int)$x['pay'] > 0) $pcByPay[(int)$x['pay']][] = $t;
    }
    foreach ($rec['cash_expenses'] as $x) {
        $t = (string)$x['status'] === 'voided' ? (jtime((string)$x['vat']) !== '' ? jtime((string)$x['vat']) : jtime((string)$x['void_at'])) : '';
        $ceByJid[(int)$x['jid']][] = $t;
    }
    $vt = []; $from = ['stamp' => 0, 'fin_audit' => 0, 'json' => 0, 'unknown' => 0]; $multi = 0;
    foreach ($V as $i) {
        $id = $C['id'][$i] ?? 0; $form = $C['form'][$i] ?? 'none';
        $best = ''; $how = 'unknown';
        $sm = $C['smin'][$i] ?? '';
        if ($sm !== '') { $best = $sm; $how = 'stamp'; }
        foreach ($faVoid[$id] ?? [] as $fv) { $t = (string)$fv['t']; if ($t !== '' && ($best === '' || strcmp($t, $best) < 0)) { $best = $t; $how = 'fin_audit'; } }
        if (in_array($form, ['plain', 'tool', 'none'], true)) {
            $v = $C['vref'][$i] ?? ''; $ts = [];
            if (preg_match('/^COL-(\d{1,18})$/', $v, $m)) $ts = $pcByJid[(int)$m[1]] ?? [];
            elseif (preg_match('/^(?:CRM-)?PAY-(\d{1,18})$/', $v, $m)) $ts = $pcByPay[(int)$m[1]] ?? [];
            elseif (preg_match('/^EXP-(\d{1,18})$/', $v, $m)) $ts = $ceByJid[(int)$m[1]] ?? [];
            foreach ($ts as $t) { $t = (string)$t; if ($t !== '' && ($best === '' || strcmp($t, $best) < 0)) { $best = $t; $how = 'json'; } }
        }
        $vt[$i] = $best; $from[$how]++;
        if (!($C['rev'][$i] ?? false) && ($C['marks'][$i] ?? 0) >= 2) $multi++;
    }
    $cut = CUTOFF_DAY . ' 00:00'; $vs = ['before' => 0, 'on' => 0, 'after' => 0];
    foreach ($V as $i) {
        $t = $vt[$i] ?? '';
        if ($t === '') continue;
        if (strpos($t, CUTOFF_DAY) === 0) $vs['on']++; elseif (strcmp($t, $cut) < 0) $vs['before']++; else $vs['after']++;
    }
    $jsonNote = [];
    foreach (['payment_collections', 'cash_expenses'] as $t) if (($ok[$t] ?? '') !== true) $jsonNote[] = $t . ' ' . na($t, (string)($ok[$t] ?? ''));
    $o(L('void time', "from a stamp {$from['stamp']} · from fin_audit " . ($FAok ? (string)$from['fin_audit'] : na('fin_audit', (string)$ok['fin_audit']))
        . " · from the JSON record {$from['json']}" . ($jsonNote ? ' (not read: ' . implode('; ', $jsonNote) . ')' : '')
        . " · unknown {$from['unknown']} (counted as before 04 Oct); rows with two or more void marks {$multi}"));
    $o(C("vs 04 Oct   before {$vs['before']} · on 04 Oct {$vs['on']} · after {$vs['after']} · unknown {$from['unknown']}"));
    $parts['void time'] = array_sum($from) === $nV;
    $parts['04 Oct'] = $vs['before'] + $vs['on'] + $vs['after'] + $from['unknown'] === $nV;

    // the candidates of §1.10, needed here for their creation time
    $cand = []; $notCur = []; $notDir = [];
    for ($i = 0; $i < $N; $i++) {
        if (!($C['adj'][$i] ?? false)) continue;
        $dir = $C['dir'][$i] ?? '';
        if ($dir === ADJ_DIRECTION) { if (in_array($C['curU'][$i] ?? '', ADJ_CURRENCIES, true)) $cand[] = $i; else $notCur[] = $i; }
        elseif ($dir === 'in') $notDir[] = $i;
    }
    $faCand = [];   // record_id => ['how' => deleted|edited away, 'cat' => b_created_at, 'f' => record]
    $faCandDel = []; $faCandEdit = [];
    if ($ok['fin_audit_json'] === true) {
        foreach ($faDel as $f) {
            if ((int)$f['bok'] !== 1) continue;
            if ((string)$f['b_direction'] === ADJ_DIRECTION && hash('sha256', 'dn-r0-adj|' . (int)round(100 * (float)$f['b_amount'])) === ADJ_CENTS_SHA
                && in_array(strtoupper(trim((string)$f['b_currency'], ' ')), ADJ_CURRENCIES, true)) $faCandDel[] = $f;
        }
        foreach ($faUpdRows as $f) {
            $bHit = (string)$f['b_direction'] === ADJ_DIRECTION && hash('sha256', 'dn-r0-adj|' . (int)round(100 * (float)$f['b_amount'])) === ADJ_CENTS_SHA
                    && in_array(strtoupper(trim((string)$f['b_currency'], ' ')), ADJ_CURRENCIES, true) && $f['b_amount'] !== null;
            $aHit = (string)$f['a_direction'] === ADJ_DIRECTION && hash('sha256', 'dn-r0-adj|' . (int)round(100 * (float)$f['a_amount'])) === ADJ_CENTS_SHA
                    && in_array(strtoupper(trim((string)$f['a_currency'], ' ')), ADJ_CURRENCIES, true) && $f['a_amount'] !== null;
            if ($bHit && !$aHit && !isset($faCandEdit[(int)$f['record_id']])) $faCandEdit[(int)$f['record_id']] = $f;
        }
        ksort($faCandEdit);
        foreach ($faCandDel as $f) if (!isset($faCand[(int)$f['record_id']])) $faCand[(int)$f['record_id']] = ['deleted', (string)$f['b_created_at']];
        foreach ($faCandEdit as $rid => $f) if (!isset($faCand[$rid])) $faCand[$rid] = ['edited away', (string)$f['b_created_at']];
        ksort($faCand);
    }
    foreach (array_slice($cand, 0, 20) as $i) $o(C(vsCand((string)($C['id'][$i] ?? 0), $C['cat'][$i] ?? '', $V, $vt)));
    $k = 0;
    foreach ($faCand as $rid => $fc) { if ($k++ >= 20) break; $o(C(vsCand((int)$rid . ' (' . ($fc[0] ?? '') . ')', substr((string)($fc[1] ?? ''), 0, 16), $V, $vt))); }

    // evidence
    if ($ok['fin_audit_json'] === true) {
        $ev = ['r' => array_fill_keys(array_keys(FORMS), 0), 'u' => 0, 'n' => array_fill_keys(array_keys(FORMS), 0)];
        foreach ($V as $i) {
            $id = $C['id'][$i] ?? 0; $form = $C['form'][$i] ?? 'none';
            if (!isset($faVoid[$id])) { $ev['n'][$form]++; continue; }
            $readable = false; foreach ($faVoid[$id] ?? [] as $fv) if ((int)$fv['bok'] === 1) $readable = true;
            if ($readable) $ev['r'][$form]++; else $ev['u']++;
        }
        $o(L('evidence', 'fin_audit void, before_json readable: ' . formText($ev['r'])));
        $o(C('fin_audit void, before_json unreadable: ' . $ev['u']));
        $o(C('no fin_audit void: ' . formText($ev['n'])));
        $parts['evidence'] = array_sum($ev['r']) + $ev['u'] + array_sum($ev['n']) === $nV;
    } else {
        $o(L('evidence', na('fin_audit', (string)$ok['fin_audit_json'])));
    }
    if ($FAok) {
        $pvRows = 0; $pvUpd = 0; $pvDesc = 0; $pvUnknown = 0;
        foreach ($V as $i) {
            $id = $C['id'][$i] ?? 0; $ups = $faUpd[$id] ?? [];
            if (!$ups) continue;
            $first = null; foreach ($faVoid[$id] ?? [] as $fv) if ($first === null || (int)$fv['id'] < $first) $first = (int)$fv['id'];
            $t = $vt[$i] ?? '';
            if ($first === null && $t === '') { $pvUnknown++; continue; }
            $hit = 0;
            foreach ($ups as $u) {
                $after = $first !== null ? (int)$u['id'] > $first : strcmp((string)$u['t'], $t) >= 0;
                if (!$after) continue;
                $hit++; $pvUpd++; if ($u['desc']) $pvDesc++;
            }
            if ($hit > 0) $pvRows++;
        }
        $o(C("post-void   voided rows with fin_audit updates after the first void {$pvRows} (update rows {$pvUpd}, of them touching the description {$pvDesc}); same-minute updates included where the void time is a minute only; voided rows with updates and the void time unknown {$pvUnknown}"));
    } else {
        $o(C('post-void   ' . na('fin_audit', (string)$ok['fin_audit'])));
    }
    $undone = [];
    if ($FAok) foreach ($faVoid as $rid => $_) { $i = $idx[$rid] ?? -1; if ($i >= 0 && ($live[$i] ?? false)) $undone[] = (int)$rid; }
    sort($undone);
    $lm = ['actor' => 0, 'reconcile' => 0, 'tool' => 0, 'plain' => 0]; $lmN = 0;
    for ($i = 0; $i < $N; $i++) {
        if (!($live[$i] ?? false) || !($C['mark'][$i] ?? false) || ($C['rev'][$i] ?? false)) continue;
        $lmN++; $f = $C['form'][$i] ?? 'none'; if (isset($lm[$f])) $lm[$f]++;
    }
    $o(C('void undone fin_audit void records whose row is now live ' . ($FAok ? count($undone) . ': ids ' . idlist($undone, 20) : na('fin_audit', (string)$ok['fin_audit']))
        . "; live rows carrying a [VOIDED: mark {$lmN}: actor-minute {$lm['actor']} · reconcile {$lm['reconcile']} · tool {$lm['tool']} · plain {$lm['plain']}"));
    // a reversal copies the first 80 bytes of its original's text (webhook.php:3580-3582), often cutting a stamp short:
    // its marks are not its own, so reversal rows are counted here and in neither mark count above
    $rmL = 0; $rmV = 0; $erR = 0; $erO = 0;
    for ($i = 0; $i < $N; $i++) {
        $rv = $C['rev'][$i] ?? false;
        if ($rv && ($C['rmark'][$i] ?? false)) { if ($live[$i] ?? false) $rmL++; else $rmV++; }
        if ($C['early'][$i] ?? false) { if ($rv) $erR++; else $erO++; }
    }
    $o(C('copied text reversal rows carrying a [VOIDED: mark ' . ($rmL + $rmV) . " (live {$rmL} · voided {$rmV}), left out of both mark counts above;"
        . " rows with a stamp minute before their own creation, not taken as theirs: reversal rows {$erR} · other rows {$erO}"));

    // ── §1.4 fin_audit delete, update and void records ───────────────────────────────────────────────────────────
    if ($FAok) {
        $acts = array_fill_keys(ACTIONS, 0) + ['(other)' => 0];
        foreach (q($pdo, $h, "SELECT action AS a, COUNT(*) AS n FROM fin_audit WHERE record_type='cb_ledger' GROUP BY a ORDER BY a") as $r) {
            $acts[pick((string)$r['a'], ACTIONS)] += (int)$r['n'];
        }
        $otherTypes = (int)(q($pdo, $h, "SELECT COUNT(*) AS n FROM fin_audit WHERE record_type<>'cb_ledger'")[0]['n'] ?? 0);
        $o(L('fin_audit', "cb_ledger records: create {$acts['create']} · update {$acts['update']} · void {$acts['void']} · delete {$acts['delete']} · other {$acts['(other)']}; other record types {$otherTypes}"));
        if ($json) {
            $dv = 0; $dl = 0; $du = 0; $dMon = []; $dIds = [];
            foreach ($faDel as $f) {
                if ((int)$f['bok'] !== 1) $du++;
                elseif (isVoided((string)$f['b_status'])) { $dv++; $dIds[] = [(int)$f['record_id'], (int)$f['id']]; }
                else $dl++;
                $m = day((string)substr((string)$f['t'], 0, 7)); $dMon[$m] = ($dMon[$m] ?? 0) + 1;
            }
            ksort($dMon, SORT_STRING); sort($dIds);
            $o(L('deleted', count($faDel) . " cb_ledger row(s) deleted: voided when deleted {$dv} · live {$dl} · before_json unreadable {$du}"));
            $o(C('by month    ' . joinmap($dMon)));
            $o(C('deleted voided rows, ids: ' . idlist(array_column($dIds, 0), 20)));
        } else {
            $o(L('deleted', count($faDel) . ' cb_ledger row(s) deleted: ' . na('fin_audit', 'nojson')));
        }
    } else {
        $o(L('fin_audit', na('fin_audit', (string)$ok['fin_audit'])));
        $o(L('deleted', na('fin_audit', (string)$ok['fin_audit'])));
    }

    // ── §1.5 twin candidates ─────────────────────────────────────────────────────────────────────────────────────
    $noDate = 0; for ($i = 0; $i < $N; $i++) if (($dn[$i] ?? -1) < 0) $noDate++;
    $cents = $C['cents']; $dirs = $C['dir']; $curU = $C['curU']; $rf = $C['rf']; $pay = $C['pay'];
    $twin7 = []; $liveTwin7 = [];
    // T1 payment id
    $b = []; $bo = [];
    for ($i = 0; $i < $N; $i++) if (($rf[$i] ?? false) && ($pay[$i] ?? 0) > 0) { $k = 'p' . ($pay[$i] ?? 0); $b[$k][] = $i; $bo[$i] = $k; }
    [$p1, $l1] = bucketScan($b, $dn, $live, $legOf, $bo, -1); $s1 = stat4($p1, $l1, $live); markTwins($p1, $l1, $live, $twin7, $liveTwin7);
    // T2 reference
    $b = []; $bo = [];
    for ($i = 0; $i < $N; $i++) { $k = $C['nref'][$i] ?? ''; if ($k !== '') { $b['r' . $k][] = $i; $bo[$i] = 'r' . $k; } }
    [$p2, $l2] = bucketScan($b, $dn, $live, $legOf, $bo, -1); $s2 = stat4($p2, $l2, $live); markTwins($p2, $l2, $live, $twin7, $liveTwin7);
    $legGroups = 0; foreach ($legOf as $i => $j) if ($i < $j && ($bo[$i] ?? null) !== null && ($bo[$i] ?? '') === ($bo[$j] ?? '')) $legGroups++;
    unset($b, $bo);
    // Rows(c) and P(c): collections, refs and retry items
    $rowsByCol = []; $rfByPay = []; $rfByNpay = [];
    for ($i = 0; $i < $N; $i++) {
        foreach (array_unique([$C['srcol'][$i] ?? 0, $C['vcol'][$i] ?? 0]) as $c) if ($c > 0) $rowsByCol[$c][] = $i;
        if ($rf[$i] ?? false) { if (($pay[$i] ?? 0) > 0) $rfByPay[$pay[$i] ?? 0][] = $i; if (($C['npay'][$i] ?? 0) > 0) $rfByNpay[$C['npay'][$i] ?? 0][] = $i; }
    }
    $Pown = []; $Pret = []; $colIds = [];
    foreach ($rec['payment_collections'] as $x) { $colIds[(int)$x['jid']] = true; if ((int)$x['pay'] > 0) $Pown[(int)$x['jid']][(int)$x['pay']] = true; }
    foreach ($rec['crm_payment_retry'] as $x) {
        $c = (int)$x['col']; if ($c <= 0) continue; $colIds[$c] = true;
        foreach ([(int)$x['p1'], (int)$x['p2']] as $p) if ($p > 0) $Pret[$c][$p] = true;
    }
    foreach (array_keys($rowsByCol) as $c) $colIds[$c] = true;
    ksort($colIds);
    $t3pairs = []; $t3col = 0; $t3ret = 0;
    if ($PC) foreach ($colIds as $c => $_) {
        $own = rowsOfCol($c, $rowsByCol, $rfByPay, $rfByNpay, $Pown[$c] ?? []);
        $all = rowsOfCol($c, $rowsByCol, $rfByPay, $rfByNpay, ($Pown[$c] ?? []) + ($Pret[$c] ?? []));
        if (count($all) < 2) continue;
        if (count($own) >= 2) $t3col++; else $t3ret++;
        foreach ($all as $x) foreach ($all as $y) if ($x < $y && ($legOf[$x] ?? -1) !== $y) $t3pairs[pkey($x, $y)] = true;
    }
    [$p3, $l3] = pairCounts($t3pairs, $live); $s3 = stat4($p3, $l3, $live); markTwins($p3, $l3, $live, $twin7, $liveTwin7);
    // T4 client + amount, T5 no client (and its text sub-count), per window
    $s4 = []; $s5 = []; $s5t = []; $t4pairs = [];
    foreach (WINDOWS as $W) {
        $b4 = []; $o4 = []; $b5 = []; $o5 = []; $b5t = []; $o5t = [];
        for ($i = 0; $i < $N; $i++) {
            if (($dn[$i] ?? -1) < 0) continue;
            $base = ($dirs[$i] ?? '') . "\x00" . ($curU[$i] ?? '') . "\x00" . ($cents[$i] ?? 0);
            $c = $cli[$i] ?? 0;
            if ($c > 0) { $k = $c . "\x00" . $base; $b4[$k][] = $i; $o4[$i] = $k; }
            elseif ($c === 0) {
                $b5[$base][] = $i; $o5[$i] = $base;
                $th = $C['th'][$i] ?? ''; if ($th !== '') { $k = $base . "\x00" . $th; $b5t[$k][] = $i; $o5t[$i] = $k; }
            }
        }
        if ($W === 7) $t4pairs = windowPairs($b4, $dn, $legOf, 7);
        [$p, $lp] = bucketScan($b4, $dn, $live, $legOf, $o4, $W); $s4[$W] = stat4($p, $lp, $live); if ($W === 7) markTwins($p, $lp, $live, $twin7, $liveTwin7);
        [$p, $lp] = bucketScan($b5, $dn, $live, $legOf, $o5, $W); $s5[$W] = stat4($p, $lp, $live); if ($W === 7) markTwins($p, $lp, $live, $twin7, $liveTwin7);
        [$p, $lp] = bucketScan($b5t, $dn, $live, $legOf, $o5t, $W); $s5t[$W] = stat4($p, $lp, $live);
        unset($b4, $o4, $b5, $o5, $b5t, $o5t);
    }
    // T6 a many-to-one sum
    $s6 = [];
    foreach (WINDOWS as $W) {
        $bk = [];
        for ($i = 0; $i < $N; $i++) {
            if (($dn[$i] ?? -1) < 0) continue;
            $c = $cli[$i] ?? 0; $np = $C['np'][$i] ?? '';
            $key = $c > 0 ? 'c:' . $c : ($np !== '' ? 'p:' . $np : '');
            if ($key === '') continue;
            $bk[$key . "\x00" . ($dirs[$i] ?? '') . "\x00" . ($curU[$i] ?? '')][] = $i;
        }
        $matched = 0; $mVoid = 0; $skipped = 0;
        foreach ($bk as $g) {
            if (count($g) < 3) continue;
            usort($g, static fn($x, $y) => [$dn[$x] ?? 0, $x] <=> [$dn[$y] ?? 0, $y]);
            $n = count($g); $lo = 0; $hi = 0;
            for ($k = 0; $k < $n; $k++) {
                $r = $g[$k] ?? 0; $d = $dn[$r] ?? 0; $tgt = $cents[$r] ?? 0;
                while ($lo < $n && ($dn[$g[$lo] ?? 0] ?? 0) < $d - $W) $lo++;
                if ($hi < $k + 1) $hi = $k + 1;
                while ($hi < $n && ($dn[$g[$hi] ?? 0] ?? 0) <= $d + $W) $hi++;
                if ($tgt <= 0) continue;
                $vals = []; $leg = $legOf[$r] ?? -1;
                for ($q = $lo; $q < $hi; $q++) {
                    $s = $g[$q] ?? 0; if ($s === $r || $s === $leg) continue;
                    $v = $cents[$s] ?? 0; if ($v > 0 && $v < $tgt) $vals[] = $v;
                }
                if (count($vals) < 2) continue;
                if (count($vals) > 500) { $skipped++; continue; }
                $hit = array_sum($vals) === $tgt;
                if (!$hit) {
                    $cntv = array_count_values($vals);
                    foreach ($vals as $v) { $need = $tgt - $v; if (($need === $v && ($cntv[$v] ?? 0) >= 2) || ($need !== $v && ($cntv[$need] ?? 0) >= 1)) { $hit = true; break; } }
                }
                if ($hit) { $matched++; if (!($live[$r] ?? true)) { $mVoid++; if ($W === 7) $twin7[$r] = true; } }
            }
        }
        $s6[$W] = ['m' => $matched, 'v' => $mVoid, 'x' => $skipped];
        unset($bk);
    }
    // T7 KYC rows against PAY- rows
    $kycRec = [];
    if ($PC) foreach ($rec['payment_collections'] as $x) {
        if ((string)$x['source'] !== 'kyc_cash_sale' || (int)$x['pay'] <= 0) continue;
        $kycRec[(int)$x['pay']][] = [(int)$x['cli'], (int)round(100 * (float)$x['amount']), (string)$x['d']];
    }
    $s7 = []; $t7pairs = [];
    foreach (WINDOWS as $W) {
        $bk = [];
        for ($i = 0; $i < $N; $i++) {
            if (($dn[$i] ?? -1) < 0 || ($cli[$i] ?? 0) <= 0) continue;
            $k = ($cli[$i] ?? 0) . "\x00" . ($curU[$i] ?? '') . "\x00" . ($cents[$i] ?? 0);
            if ($C['kyc'][$i] ?? false) $bk[$k]['k'][] = $i;
            if (($rf[$i] ?? false) && ($C['payref'][$i] ?? false)) $bk[$k]['s'][] = $i;
        }
        $pairs = [];
        foreach ($bk as $g) {
            foreach ($g['k'] ?? [] as $kk) foreach ($g['s'] ?? [] as $ss) {
                if ($kk === $ss || ($legOf[$kk] ?? -1) === $ss) continue;
                if (abs(($dn[$kk] ?? 0) - ($dn[$ss] ?? 0)) > $W) continue;
                $pairs[pkey($kk, $ss)] = [$kk, $ss];
            }
        }
        $t7pairs[$W] = $pairs;
        [$p, $lp] = pairCounts($pairs, $live); $s7[$W] = stat4($p, $lp, $live); if ($W === 7) markTwins($p, $lp, $live, $twin7, $liveTwin7);
        unset($bk);
    }
    $linked = 0;
    foreach ($t7pairs[7] as [$kk, $ss]) {
        foreach ($kycRec[$pay[$ss] ?? 0] ?? [] as $kr) {
            if (($kr[0] ?? 0) === ($cli[$kk] ?? -1) && ($kr[1] ?? 0) === ($cents[$kk] ?? -1) && ($kr[2] ?? '') === ($C['d'][$kk] ?? '-')) { $linked++; break; }
        }
    }
    $pcNa = $PC ? '' : na('payment_collections', (string)$ok['payment_collections']);
    $rtNa = $RT ? '' : na('crm_payment_retry', (string)$ok['crm_payment_retry']);
    $o(L('twins', "candidates only, never proof · windows: same day | ±7 days · rows without a usable date {$noDate}"));
    $o(C('two-leg     entries ' . intdiv(count($legOf), 2) . " (funding {$legF}, other pair sources {$legS}, opposite directions elsewhere {$legO}) — never twins of each other"));
    $o(C('KYC rows    ' . ($PC ? "whose CRM# is an application id {$kycAppIds} (client id set to 0); ambiguous, kept as a client {$kycAmbig}" : $pcNa . '; client ids kept as stored')));
    $o(C('T1 payment id    ' . statText($s1)));
    $o(C('T2 reference     ' . statText($s2) . "; pair legs excluded {$legGroups} group(s)"));
    $o(C('T3 COL/PAY       ' . ($PC ? statText($s3) . '; collections with ≥ 2 rows ' . ($t3col + $t3ret) . " (through the collection {$t3col}, through a retry item " . ($RT ? (string)$t3ret : $rtNa) . ')' : $pcNa)));
    $o(C('T4 client+amount same day: ' . statText($s4[0]) . ' | ±7 d: ' . statText($s4[7])));
    $o(C('T5 no client     same day: ' . statText($s5[0]) . ' | ±7 d: ' . statText($s5[7]) . '; text candidates same day: pairs ' . $s5t[0]['pairs'] . ' · rows ' . $s5t[0]['rows']
        . ' · voided ' . $s5t[0]['voided'] . ' | ±7 d: pairs ' . $s5t[7]['pairs'] . ' · rows ' . $s5t[7]['rows'] . ' · voided ' . $s5t[7]['voided']));
    $o(C('T6 sum           same day: rows matched ' . $s6[0]['m'] . ' · voided ' . $s6[0]['v'] . ' · not examined ' . $s6[0]['x']
        . ' | ±7 d: rows matched ' . $s6[7]['m'] . ' · voided ' . $s6[7]['v'] . ' · not examined ' . $s6[7]['x']));
    $o(C('T7 KYC/PAY       same day: ' . statText($s7[0]) . ' | ±7 d: ' . statText($s7[7]) . '; linked through the KYC collection record ' . ($PC ? (string)$linked : $pcNa)));
    $o(C('any rule ±7 d    voided rows with at least one twin ' . count($twin7) . " of {$nV} (with a live twin " . count($liveTwin7) . '; T6 counts as a twin, not as a live twin)'));

    // ── §1.6 the I1 families ─────────────────────────────────────────────────────────────────────────────────────
    if ($PC) {
        $aCol = 0; $aRet = 0; $aEdges = [];
        foreach ($colIds as $c => $_) {
            $r0 = []; foreach ($rowsByCol[$c] ?? [] as $i) if (($C['vcol'][$i] ?? 0) === $c && ($pay[$i] ?? 0) === 0) $r0[] = $i;
            if (!$r0) continue;
            $fromOwn = false; $fromRet = false; $partners = [];
            foreach (($Pown[$c] ?? []) + ($Pret[$c] ?? []) as $p => $_x) {
                foreach (array_unique(array_merge($rfByPay[$p] ?? [], $rfByNpay[$p] ?? [])) as $s) {
                    if (($C['source'][$s] ?? '') === 'catchup_sync' || ($C['srcol'][$s] ?? 0) === $c || ($C['vcol'][$s] ?? 0) === $c) continue;
                    $partners[$s] = true;
                    if (isset($Pown[$c][$p])) $fromOwn = true; else $fromRet = true;
                }
            }
            if (!$partners) continue;
            if ($fromOwn) $aCol++; else $aRet++;
            foreach ($r0 as $x) foreach ($partners as $y => $_y) if ($x !== $y && ($legOf[$x] ?? -1) !== $y) $aEdges[pkey($x, $y)] = true;
        }
        $o(L('I1 families', '(a) push failure, then the payment posted: ' . ($aCol + $aRet) . " collection(s) (through the collection {$aCol}, through a retry item " . ($RT ? (string)$aRet : $rtNa) . '); ' . famText($aEdges, $live)));
        $payPartner = [];
        foreach ($t4pairs as $pk) { $x = intdiv($pk, 4294967296); $y = $pk % 4294967296; if ($C['payref'][$y] ?? false) $payPartner[$x] = true; if ($C['payref'][$x] ?? false) $payPartner[$y] = true; }
        $un = 0; $unV = 0;
        for ($i = 0; $i < $N; $i++) {
            $c = $C['vcol'][$i] ?? 0;
            if ($c <= 0 || ($pay[$i] ?? 0) !== 0 || ($Pown[$c] ?? []) || ($Pret[$c] ?? []) || !isset($payPartner[$i])) continue;
            $un++; if (!($live[$i] ?? true)) $unV++;
        }
        $o(C("(a) unlinked: push-failure rows with a PAY- partner by client+amount ±7 d {$un} (voided {$unV})"));
    } else {
        $o(L('I1 families', '(a) push failure, then the payment posted: ' . $pcNa));
        $o(C('(a) unlinked: ' . $pcNa));
    }
    // (b)
    $bN = 0; $bEdges = [];
    foreach ($rowsByCol as $c => $rows) {
        $cu = []; foreach ($rows as $i) if (($C['source'][$i] ?? '') === 'catchup_sync' && ($C['srcol'][$i] ?? 0) === $c) $cu[] = $i;
        if (!$cu) continue;
        $hit = false;
        foreach ($cu as $x) foreach ($rows as $y) {
            if ($x === $y || ($C['source'][$y] ?? '') === 'catchup_sync' || ($legOf[$x] ?? -1) === $y) continue;
            $bEdges[pkey($x, $y)] = true; $hit = true;
        }
        if ($hit) $bN++;
    }
    $o(C("(b) catch-up beside the COL- row: {$bN} collection(s); " . famText($bEdges, $live)));
    // (c)
    if ($PC) {
        $cRows = []; $cEdges = [];
        for ($i = 0; $i < $N; $i++) {
            $c = $C['vcol'][$i] ?? 0;
            if (($C['source'][$i] ?? '') !== 'crm_sync' || $c <= 0) continue;
            $part = rowsOfCol($c, $rowsByCol, $rfByPay, $rfByNpay, ($Pown[$c] ?? []) + ($Pret[$c] ?? []));
            if (($pay[$i] ?? 0) > 0) $part = array_merge($part, $rfByPay[$pay[$i] ?? 0] ?? []);
            foreach (array_unique($part) as $s) { if ($s === $i || ($legOf[$i] ?? -1) === $s) continue; $cRows[$i] = true; $cEdges[$i][$s] = true; }
        }
        $cv = 0; $clt = 0;
        foreach ($cRows as $i => $_) { if ($live[$i] ?? true) continue; $cv++; foreach ($cEdges[$i] ?? [] as $s => $_s) if ($live[$s] ?? false) { $clt++; break; } }
        $o(C('(c) pull fallback beside another row of the collection: ' . count($cRows) . " row(s) · voided {$cv} (live twin {$clt})"));
    } else {
        $o(C('(c) pull fallback beside another row of the collection: ' . $pcNa));
    }
    // (d)
    $api = []; $gap = [];
    for ($i = 0; $i < $N; $i++) {
        if (!($rf[$i] ?? false)) continue;
        $s = $C['source'][$i] ?? '';
        if ($s !== 'crm_api_sync' && $s !== 'crm_gap_fill' && $s !== 'catchup_sync') continue;
        foreach (array_unique([$pay[$i] ?? 0, $C['npay'][$i] ?? 0]) as $p) {
            if ($p <= 0) continue;
            if ($s === 'crm_api_sync') $api[$p][] = $i; else $gap[$p][] = $i;
        }
    }
    $dN = 0; $dEdges = [];
    foreach ($api as $p => $xs) {
        if (!isset($gap[$p])) continue;
        $dN++;
        foreach ($xs as $x) foreach ($gap[$p] ?? [] as $y) if ($x !== $y && ($legOf[$x] ?? -1) !== $y) $dEdges[pkey($x, $y)] = true;
    }
    $o(C("(d) pull beside a gap-filled or catch-up receipt: {$dN} payment(s); " . famText($dEdges, $live)));
    // (e)
    $e0 = 0; $e7 = 0; $eRows = [];
    foreach (WINDOWS as $W) foreach ($t7pairs[$W] ?? [] as [$kk, $ss]) {
        if (!in_array($C['source'][$ss] ?? '', ['crm_webhook', 'crm_gap_fill', 'crm_api_sync'], true)) continue;
        if ($W === 0) $e0++; else { $e7++; $eRows[$kk] = true; $eRows[$ss] = true; }
    }
    $ev7 = 0; foreach ($eRows as $i => $_) if (!($live[$i] ?? true)) $ev7++;
    $o(C("(e) KYC row beside a uCRM receipt: same day {$e0} · ±7 d {$e7} (voided {$ev7})"));
    // (f)
    if ($PC && $RT) {
        $fN = 0; foreach ($colIds as $c => $_) if (count(($Pown[$c] ?? []) + ($Pret[$c] ?? [])) >= 2) $fN++;
        $fPairs = 0;
        foreach ($t4pairs as $pk) {
            $x = intdiv($pk, 4294967296); $y = $pk % 4294967296;
            if (($rf[$x] ?? false) && ($rf[$y] ?? false) && ($C['npay'][$x] ?? 0) > 0 && ($C['npay'][$y] ?? 0) > 0 && ($C['npay'][$x] ?? 0) !== ($C['npay'][$y] ?? 0)) $fPairs++;
        }
        $o(C("(f) two uCRM payments for one collection: {$fN} collection(s); ledger candidates ±7 d {$fPairs} pair(s)"));
    } else {
        $o(C('(f) two uCRM payments for one collection: ' . ($PC ? $rtNa : $pcNa)));
    }

    // ── §1.7 Receipt rows by their collection's method ───────────────────────────────────────────────────────────
    $nRec = 0; for ($i = 0; $i < $N; $i++) if ($C['receipt'][$i] ?? false) $nRec++;
    if ($PC) {
        $pcJ = []; $pcP = [];
        foreach ($rec['payment_collections'] as $x) {
            $r = ['jid' => (int)$x['jid'], 'cls' => methodClass((string)$x['method']), 'direct' => (string)$x['source'] === 'crm_direct'];
            $pcJ[(int)$x['jid']][] = $r; if ((int)$x['pay'] > 0) $pcP[(int)$x['pay']][] = $r;
        }
        $lk = 0; $sev = 0; $nl = 0; $dOnly = 0; $cls = array_fill_keys(METHOD_CLASSES, [0, 0]);
        for ($i = 0; $i < $N; $i++) {
            if (!($C['receipt'][$i] ?? false)) continue;
            $c = ($C['srcol'][$i] ?? 0) ?: ($C['vcol'][$i] ?? 0);
            if ($c > 0 && isset($pcJ[$c])) $set = $pcJ[$c] ?? [];
            else { $p = ($pay[$i] ?? 0) > 0 ? ($pay[$i] ?? 0) : ($C['npay'][$i] ?? 0); $set = $p > 0 ? ($pcP[$p] ?? []) : []; }
            if (!$set) { $nl++; continue; }
            $nd = array_values(array_filter($set, static fn($r) => !$r['direct']));
            if (!$nd) { $dOnly++; continue; }
            usort($nd, static fn($a, $b) => $a['jid'] <=> $b['jid']);
            $lk++; if (count($set) >= 2) $sev++;
            $k = (string)($nd[0]['cls'] ?? '(other)');
            $cls[$k][0]++; if (!($live[$i] ?? true)) $cls[$k][1]++;
        }
        $o(L('receipt method', "Receipt IN rows {$nRec}: linked to a collection {$lk} (to several {$sev}) · not linked {$nl}; linked only to a pull-made record (crm_direct) {$dOnly}, not classed"));
        $parts2 = []; foreach (METHOD_CLASSES as $k) $parts2[] = $k . ' ' . ($cls[$k][0] ?? 0) . ' (voided ' . ($cls[$k][1] ?? 0) . ')';
        $o(C('by class    ' . implode(' · ', $parts2)));
        $notCash = 0; $notCashV = 0; foreach (['mobile money', 'bank', 'cheque', 'uuid other'] as $k) { $notCash += $cls[$k][0] ?? 0; $notCashV += $cls[$k][1] ?? 0; }
        $o(C("not Cash    {$notCash} (voided {$notCashV}); unknown (blank, other) " . (($cls['blank'][0] ?? 0) + ($cls['(other)'][0] ?? 0))));
    } else {
        $o(L('receipt method', "Receipt IN rows {$nRec}: " . $pcNa));
    }

    // ── §1.8 reversal rows whose original is voided ──────────────────────────────────────────────────────────────
    $revs = [];
    for ($i = 0; $i < $N; $i++) {
        $v = $C['vref'][$i] ?? '';
        if (!($C['rev'][$i] ?? false)) continue;
        $p = ($pay[$i] ?? 0) > 0 ? ($pay[$i] ?? 0) : (preg_match('/^REV-CRM-PAY-(\d{1,18})$/i', $v, $m) ? (int)$m[1] : 0);
        $revs[$p][] = $i;
    }
    ksort($revs);
    $delPay = [];
    if ($ok['fin_audit_json'] === true) foreach ($faDel as $f) if ((string)$f['b_direction'] === 'in' && (int)$f['b_crm_payment_id'] > 0) $delPay[(int)$f['b_crm_payment_id']] = true;
    $rv = ['all' => 0, 'some' => 0, 'live' => 0, 'del' => 0, 'none' => 0]; $twice = 0; $markedV = 0; $nRev = 0;
    foreach ($revs as $p => $rows) {
        $nRev += count($rows); if (count($rows) >= 2) $twice++;
        $orig = $p > 0 ? ($rfByPay[$p] ?? []) : [];
        if (!$orig) { if ($p > 0 && isset($delPay[$p])) $rv['del']++; else $rv['none']++; continue; }
        $nv = 0; foreach ($orig as $x) { if (!($live[$x] ?? true)) { $nv++; if ($C['reversed'][$x] ?? false) $markedV++; } }
        if ($nv === count($orig)) $rv['all']++; elseif ($nv > 0) $rv['some']++; else $rv['live']++;
    }
    $o(L('reversals', "{$nRev} row(s) for " . count($revs) . " payment(s): every original voided {$rv['all']} · some voided {$rv['some']} · originals live {$rv['live']} · original deleted (fin_audit) "
        . ($ok['fin_audit_json'] === true ? (string)$rv['del'] : na('fin_audit', (string)$ok['fin_audit_json'])) . " · no original {$rv['none']}"));
    $o(C("payments reversed twice or more {$twice}; originals marked reversed and voided {$markedV}"));

    // ── §1.9 rows dated 2026-09-12 ───────────────────────────────────────────────────────────────────────────────
    $day12 = q($pdo, $h, "SELECT l.id, l.status, COALESCE(l.account_id,0) AS acc, a.kind AS akind, CASE WHEN a.id IS NULL THEN 1 ELSE 0 END AS amissing,
               CASE WHEN UPPER(COALESCE(l.description,'')) LIKE '%ADJUSTMENT%' OR UPPER(COALESCE(l.category,'')) LIKE '%ADJUST%' THEN 1 ELSE 0 END AS named
          FROM cb_ledger l {$accJoin} WHERE substr(l.date,1,10) = '2026-09-12' ORDER BY l.id");
    $d12del = []; $away = []; $into = [];
    if ($ok['fin_audit_json'] === true) {
        foreach ($faDel as $f) if (substr((string)$f['b_date'], 0, 10) === ADJ_DATE) $d12del[] = $f;
        foreach ($faUpdRows as $f) {
            if ($f['b_date'] === null || $f['a_date'] === null) continue;
            $bd = substr((string)$f['b_date'], 0, 10) === ADJ_DATE; $ad = substr((string)$f['a_date'], 0, 10) === ADJ_DATE;
            if ($bd && !$ad) $away[(int)$f['record_id']][] = (int)$f['id'];
            if (!$bd && $ad) $into[(int)$f['record_id']][] = (int)$f['id'];
        }
        ksort($away); ksort($into);
    }
    $faj = $ok['fin_audit_json'] === true; $fajNa = na('fin_audit', (string)$ok['fin_audit_json']);
    $o(L('rows 2026-09-12', count($day12) . ' row(s) in cb_ledger; deleted rows of that date (fin_audit) ' . ($faj ? (string)count($d12del) : $fajNa)
        . '; dates edited away from it ' . ($faj ? (string)count($away) : $fajNa) . ', into it ' . ($faj ? (string)count($into) : $fajNa)));
    $k = 0;
    foreach ($day12 as $r) {
        if ($k++ >= 200) { $o(C('… ' . (count($day12) - 200) . ' more')); break; }
        $id = (int)$r['id']; $acc = (int)$r['acc'];
        $kind = !$hasAcc ? '(no accounts table)' : ($acc === 0 ? 'unassigned' : ((int)$r['amissing'] === 1 ? 'missing' : pick((string)$r['akind'], KINDS)));
        $fa = $FAok ? 'update ' . faIds($faIds[$id]['update'] ?? []) . ' · void ' . faIds($faIds[$id]['void'] ?? []) . ' · delete ' . faIds($faIds[$id]['delete'] ?? [])
                    : na('fin_audit', (string)$ok['fin_audit']);
        $o(C("id {$id}  " . pick((string)$r['status'], STATUSES) . "  {$kind}  p0 rule: live " . (isVoided((string)$r['status']) ? 'no' : 'yes')
            . ' · unassigned ' . ($acc === 0 ? 'yes' : 'no') . ' · named ' . ((int)$r['named'] === 1 ? 'yes' : 'no') . "  fin_audit: {$fa}"));
    }
    unset($day12);
    foreach ($d12del as $f) {
        $o(C('deleted id ' . (int)$f['record_id'] . '  ' . pick((string)$f['b_status'], STATUSES) . '  ' . kindOfAcc($hasAcc, $accKind, (int)$f['b_account_id']) . '  fin_audit delete ' . (int)$f['id']));
    }
    foreach ($away as $rid => $ids) $o(C('date edited: id ' . (int)$rid . ' away from 2026-09-12 (fin_audit update ' . faIds($ids) . ')'));
    foreach ($into as $rid => $ids) $o(C('date edited: id ' . (int)$rid . ' into 2026-09-12 (fin_audit update ' . faIds($ids) . ')'));

    // ── §1.10 candidates for the 12 Sep adjustment ───────────────────────────────────────────────────────────────
    $o(L('adj. candidates', "rows with direction out and the recorded adjustment's amount (UGX or blank), any date, any status, any account: " . count($cand)));
    $k = 0;
    foreach ($cand as $i) {
        if ($k++ >= 50) { $o(C('… ' . (count($cand) - 50) . ' more')); break; }
        $o(C('id ' . ($C['id'][$i] ?? 0) . '  ' . day((string)($C['d'][$i] ?? '')) . '  ' . pick((string)($C['status'][$i] ?? ''), STATUSES) . '  ' . ($C['kind'][$i] ?? '')));
    }
    if ($faj) {
        foreach ($faCandDel as $f) {
            $o(C('deleted (fin_audit only): id ' . (int)$f['record_id'] . '  ' . day((string)substr((string)$f['b_date'], 0, 10)) . '  ' . pick((string)$f['b_status'], STATUSES)
                . '  ' . kindOfAcc($hasAcc, $accKind, (int)$f['b_account_id'])));
        }
        foreach ($faCandEdit as $rid => $f) {
            $i = $idx[$rid] ?? -1;
            $o(C('edited away (fin_audit before had it): ' . ($i >= 0 ? 'id ' . (int)$rid . '  ' . day((string)($C['d'][$i] ?? '')) . '  ' . pick((string)($C['status'][$i] ?? ''), STATUSES) . '  ' . ($C['kind'][$i] ?? '')
                : 'id ' . (int)$rid . ' (since deleted)')));
        }
    } else {
        $o(C('deleted, edited away: ' . $fajNa));
    }
    $o(C('not candidates, same amount and direction, another currency ' . count($notCur) . candList($notCur, $C)));
    $o(C('not candidates, same amount, direction in ' . count($notDir) . candList($notDir, $C)));

    // ── §1.11 the 5 largest-amount unassigned rows, per currency (computed from L1, which carries the same cents) ───
    $o(L('top unassigned', 'per currency, the 5 largest-amount unassigned rows, any status — no amount printed'));
    $top = [];
    for ($i = 0; $i < $N; $i++) if (($C['acc'][$i] ?? 1) === 0) $top[$C['curC'][$i] ?? '(other)'][] = $i;
    ksort($top, SORT_STRING);
    foreach ($top as $cc => $rows) {
        usort($rows, static fn($a, $b) => [-($cents[$a] ?? 0), $C['id'][$a] ?? 0] <=> [-($cents[$b] ?? 0), $C['id'][$b] ?? 0]);
        foreach (array_slice($rows, 0, 5) as $rk => $i) {
            $o(C($cc . '  ' . ($rk + 1) . '  id ' . ($C['id'][$i] ?? 0) . '  ' . day((string)($C['d'][$i] ?? '')) . '  ' . pick((string)($dirs[$i] ?? ''), ['in', 'out'])
                . '  ' . pick((string)($C['status'][$i] ?? ''), STATUSES)));
        }
    }
    unset($top);

    // ── §1.12 deliberate voids ───────────────────────────────────────────────────────────────────────────────────
    $tIds = []; $tv = 0; $tr = 0; $tl = 0; $t69 = 0; $byRef = [];
    for ($i = 0; $i < $N; $i++) {
        if ($C['tool69'][$i] ?? false) $t69++;
        $v = $C['vref'][$i] ?? ''; if ($v !== '') $byRef[$v][] = $i;
        if (!($C['tool70'][$i] ?? false)) continue;
        $tIds[] = $C['id'][$i] ?? 0; $st = $C['status'][$i] ?? '';
        if ($st === 'voided') $tv++; elseif ($st === 'voided_reconcile') $tr++; else $tl++;
    }
    $o(L('records tool', "cb_ledger rows with the 5.18.70 tool's reason stamp " . count($tIds) . " (voided {$tv}, voided_reconcile {$tr}, live {$tl}): ids " . idlist($tIds, 20) . "; with the 5.18.69 form {$t69} (expected 0)"));
    if ($PC) {
        $cA = 0; $cB = 0; $cC = 0; $cD = 0; $cIds = []; $c69 = 0;
        foreach ($rec['payment_collections'] as $x) {
            if ((string)$x['vby'] === 'tool69') $c69++;
            if ((string)$x['vby'] !== 'tool70') continue;
            $cIds[] = (int)$x['jid'];
            $ref = (int)$x['pay'] > 0 ? 'PAY-' . (int)$x['pay'] : 'COL-' . (int)$x['jid'];
            $rows = $byRef[$ref] ?? [];
            $st = 'd';
            foreach ($rows as $i) { if ($C['tool70'][$i] ?? false) { $st = 'a'; break; } }
            if ($st === 'd' && $rows) { $st = 'c'; foreach ($rows as $i) if (!($live[$i] ?? true)) $st = 'b'; }
            if ($st === 'a') $cA++; elseif ($st === 'b') $cB++; elseif ($st === 'c') $cC++; else $cD++;
        }
        sort($cIds);
        $o(C('payment_collections voided by the 5.18.70 tool ' . count($cIds) . ": ledger row tool-stamped {$cA} · ledger row voided, other stamp {$cB} · ledger row live {$cC} · no ledger row {$cD}; ids " . idlist($cIds, 20) . "; by the 5.18.69 tool {$c69} (expected 0)"));
    } else {
        $o(C('payment_collections voided by the 5.18.70 tool ' . $pcNa));
    }
    unset($byRef);
    $ciT = 0; foreach ($rec['cash_ins'] as $x) if ((string)$x['vby'] === 'tool70') $ciT++;
    $slT = $ok['staff_ledger'] === true ? (string)(int)(q($pdo, $h, "SELECT COUNT(*) AS n FROM staff_ledger WHERE voided_by = 'staff_records_currency 5.18.70'")[0]['n'] ?? 0) : na('staff_ledger', (string)$ok['staff_ledger']);
    $o(C('cash_ins voided by the tool ' . ($ok['cash_ins'] === true ? (string)$ciT : na('cash_ins', (string)$ok['cash_ins'])) . "; staff_ledger rows voided by the tool {$slT}"));

    // ── §1.13 the three recorded 25 Sep cash-ins ─────────────────────────────────────────────────────────────────
    $o(L('25 Sep cash-ins', 'the three of docs/07 (#3, #4, #5): was VOID typed at the 5.18.70 prompt?'));
    if ($ok['cash_ins'] === true) {
        $sl = [];
        if ($ok['staff_ledger'] === true) foreach (q($pdo, $h, "SELECT idempotency_key AS k, status,
               CASE WHEN voided_by='staff_records_currency 5.18.70' THEN 'tool70' WHEN voided_by='staff_records_currency 5.18.69' THEN 'tool69'
                    WHEN COALESCE(voided_by,'')='' THEN 'none' ELSE 'other' END AS vby,
               substr(voided_at,1,10) AS vat FROM staff_ledger
         WHERE idempotency_key IN ('CIN-3','CIN-4','CIN-5','CINO-3','CINO-4','CINO-5') ORDER BY k") as $r) $sl[(string)$r['k']] = $r;
        $relCol = ($shape['cash_ins'] ?? '') === 'json' || in_array('audit_log', $cols['cash_ins'] ?? [], true);
        $nv = 0; $nt = 0;
        foreach (CASHINS as $ci) {
            $x = null; foreach ($rec['cash_ins'] as $y) if ((int)$y['jid'] === $ci) { $x = $y; break; }
            $slp = [];
            foreach (['CIN-', 'CINO-'] as $pre) {
                $r = $sl[$pre . $ci] ?? null;
                $slp[] = $pre . $ci . ' ' . ($ok['staff_ledger'] !== true ? na('staff_ledger', (string)$ok['staff_ledger'])
                       : ($r === null ? 'absent' : pick((string)$r['status'], SLSTAT) . ', voided_by ' . (VBY[(string)$r['vby']] ?? 'another value')));
            }
            if ($x === null) { $o(C("cash-in {$ci}  absent · staff_ledger " . implode(' · ', $slp))); continue; }
            $st = $x['status'] === null ? '(none)' : pick((string)$x['status'], JSTAT);
            if ((string)$x['status'] === 'voided') { $nv++; if ((string)$x['vby'] === 'tool70') $nt++; }
            $rel = !$relCol ? 'unknown (column audit_log missing)' : ((int)$x['relabelled'] === 1 ? 'yes' : 'no');
            $o(C("cash-in {$ci}  present · " . day((string)$x['d']) . ' · ' . code((string)$x['cur']) . ' · category as recorded ' . ((int)$x['catrec'] === 1 ? 'yes' : 'no')
                . " · {$st} · voided_by " . (VBY[(string)$x['vby']] ?? 'another value') . ' · voided_at ' . ((string)$x['vat'] === '' ? '–' : day((string)$x['vat']))
                . " · relabelled {$rel} · staff_ledger " . implode(' · ', $slp)));
        }
        $o(C('VOID typed   ' . ($nt === 3 ? 'yes — all three voided by the 5.18.70 tool' : ($nv === 0 ? 'no sign of it — none of the three is voided' : 'mixed — read the three lines'))));
    } else {
        $o(C(na('cash_ins', (string)$ok['cash_ins'])));
    }

    // ── §1.14 live equipment assignments with an empty Starlink account ──────────────────────────────────────────
    if ($ok['equipment_assignments'] === true) {
        $eq = q($pdo, $h, "SELECT COUNT(*) AS n, SUM(CASE WHEN TRIM(COALESCE(starlink_account,''))='' THEN 1 ELSE 0 END) AS e
          FROM equipment_assignments WHERE released_at IS NULL")[0] ?? [];
        $sh = (int)(q($pdo, $h, "SELECT COUNT(*) AS s FROM (SELECT starlink_account FROM equipment_assignments
          WHERE released_at IS NULL AND TRIM(starlink_account)<>'' GROUP BY starlink_account HAVING COUNT(*) >= 2)")[0]['s'] ?? 0);
        $o(L('equipment', 'live assignments ' . (int)($eq['n'] ?? 0) . ': starlink_account empty ' . (int)($eq['e'] ?? 0) . "; non-empty accounts held by two or more live assignments {$sh}"));
    } else {
        $o(L('equipment', na('equipment_assignments', (string)$ok['equipment_assignments'])));
    }

    $changed = (int)(q($pdo, $h, 'SELECT total_changes() AS n')[0]['n'] ?? -1);
    $qo2     = (int)(q($pdo, $h, 'SELECT * FROM pragma_query_only')[0]['query_only'] ?? 0);
    $pdo->exec('ROLLBACK');
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            $code = (string)($e->errorInfo[1] ?? $e->getCode());
            fail('SQLite could not finish reading the book (' . get_class($e) . ', code ' . preg_replace('/[^0-9A-Za-z]/', '', $code)
                . '). With the book read immutable, that means the plugin rewrote it during the reading; the book itself is not'
                . ' touched by this. Nothing was measured; run again');
        }
        fail('an internal error (' . get_class($e) . ', line ' . $e->getLine() . '); this is R0\'s own fault, not the plugin\'s; do not run it again — send the log');
    }
    $pdo = null;
    return ['lines' => $L, 'digest' => hash_final($h), 'changed' => $changed, 'qo' => $qo2, 'parts' => $parts, 'nV' => $nV];
}

// ── Two readings, compared ───────────────────────────────────────────────────────────────────────────────────────
$before = fileId($db);
$r1 = readBook($db);
$r2 = readBook($db);
$after   = fileId($db);
$sidesNow = sides($db);
foreach ($r1['lines'] as $l) out($l);
out('');
$partsOk = !in_array(false, $r1['parts'], true);
$partNames = array_values(array_intersect(['source', 'path', 'month', 'void time', 'evidence', '04 Oct'], array_keys($r1['parts'])));
out('  checks          the parts add up: ' . ($partsOk ? 'ok' : 'MISMATCH') . ' (' . implode(', ', $partNames) . ' = ' . $r1['nV'] . ' each)'
    . "; the connections changed {$r1['changed']} and {$r2['changed']} row(s); query_only={$r1['qo']}");
out('                  read twice, immutable' . ($waited >= 0.5 ? sprintf(' (after waiting %.0f s for the plugin to close the book)', $waited) : '')
    . ': the readings are ' . ($r1['lines'] === $r2['lines'] && $r1['digest'] === $r2['digest'] ? 'identical' : 'DIFFERENT')
    . '; the database file ' . ($after === $before ? 'did not move (inode, size, times)' : 'MOVED during the reading')
    . '; side files ' . ($sidesNow ? 'APPEARED' : 'none, before or after'));
if ($r1['changed'] !== 0 || $r2['changed'] !== 0 || $r1['qo'] !== 1 || $r2['qo'] !== 1) fail('the connection is not provably read-only');
if ($r1['lines'] !== $r2['lines'] || $r1['digest'] !== $r2['digest'] || $after !== $before || $sidesNow) {
    fail('the plugin opened or wrote the book while R0 read it, so these counts may not be one moment of the book; run again');
}
if (!$partsOk) fail('the parts do not add up to the voided rows');
out('R0: DONE — read only; nothing was written.');

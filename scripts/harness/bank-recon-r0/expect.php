<?php
/**
 * expect.php — what R0 must print, computed in a way that shares no code with R0 (the specification's §5.3):
 *   - plain PDO on the sandbox book; no plugin code; every ordinary table read with SELECT *, every store with
 *     SELECT id, data (or a proper table's named columns) and decoded with json_decode — no json_extract, no GROUP BY,
 *     no SQL arithmetic;
 *   - every section computed with naive loops: all pairs for the twin rules (R0 buckets and scans), a scan of every row
 *     for each sum, a scan of every row for each collection;
 *   - the report rendered in R0's §1 format, from the `tables` line to the last line before `checks`.
 *
 *   php expect.php <data dir> <variant> <candidate cents|none>   > expected.txt
 *
 * <candidate cents>: the fictitious adjustment in cents, for a run of R0's candidate copy; "none" for the reviewed tool,
 * whose hash no planted amount matches (asserted separately).
 */
declare(strict_types=1);
[$_, $data, $variant, $candArg] = $argv + [null, '', '', 'none'];
$CAND = $candArg === 'none' ? null : (int)$candArg;
$pdo = new PDO('sqlite:file:' . $data . '/plugin.sqlite3?immutable=1', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$all = static fn(string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

const XKINDS  = ['bank', 'cash', 'momo', 'receivable', 'payable', 'inventory', 'asset', 'director', 'equity'];
const XSTAT   = ['approved', 'pending_approval', 'rejected', 'voided', 'voided_reconcile'];
const XJSTAT  = ['approved', 'pending', 'pending_approval', 'rejected', 'voided'];
const XSLSTAT = ['active', 'voided', 'cancelled'];
const XACT    = ['create', 'update', 'void', 'delete'];
const XT70 = 'staff_records_currency 5.18.70', XT69 = 'staff_records_currency 5.18.69';
const XCLS = ['cash', 'uuid cash', 'mobile money', 'bank', 'cheque', 'uuid other', 'blank', '(other)'];
function w(string $v, array $l): string { return in_array($v, $l, true) ? $v : '(other)'; }
function cc(string $v): string { return $v === '' ? '(blank)' : (preg_match('/^[A-Za-z]{3}$/', $v) ? $v : '(other)'); }
function sr(string $v): string { return preg_match('/^[a-z][a-z_]{0,31}$/', $v) ? $v : '(other)'; }
function dy(string $v): string { return preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $v) ? $v : '?'; }
function lab(string $l, string $t): string { return '  ' . str_pad($l, 16) . $t; }
function con(string $t): string { return '                  ' . $t; }
/** SQLite's CAST(x AS INTEGER), for the values this book holds. */
function ci($v): ?int {
    if ($v === null) return null;
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_int($v)) return $v;
    if (is_float($v)) return (int)$v;
    if (is_string($v)) return preg_match('/^\s*([+-]?\d+)/', $v, $m) ? (int)$m[1] : 0;
    return 0;
}
function cr($v): ?float {
    if ($v === null) return null;
    if (is_bool($v)) return $v ? 1.0 : 0.0;
    if (is_int($v) || is_float($v)) return (float)$v;
    if (is_string($v)) return preg_match('/^\s*([+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?)/', $v, $m) ? (float)$m[1] : 0.0;
    return 0.0;
}
/** A JSON value as json_extract would hand it to PHP (text for strings, a number, 1/0 for booleans, JSON text otherwise). */
function jx($obj, string $f) {
    if (!is_object($obj) || !property_exists($obj, $f)) return null;
    $v = $obj->$f;
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_object($v) || is_array($v)) return json_encode($v);
    return $v;
}
function vbc($v): string { $v = $v === null ? '' : (string)$v; return $v === XT70 ? 'tool70' : ($v === XT69 ? 'tool69' : ($v === '' ? 'none' : 'other')); }
function days(string $a, string $b): int { return (new DateTime($a))->diff(new DateTime($b))->days; }
function okDate(string $d): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}
function hsh(string $pre, string $s, bool $strip): string {
    $orig = $s;
    if ($strip) { $s = preg_replace('/ \[(?:VOIDED|REVERSED|CORRECTED)[^\]]*\]/', '', $s); if ($s === null) return sha1($pre . 'raw|' . $orig); }
    if (!mb_check_encoding($s, 'UTF-8')) return sha1($pre . 'raw|' . $orig);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s, 'UTF-8'));
    if ($s === null) return sha1($pre . 'raw|' . $orig);
    $s = trim($s);
    return $s === '' ? '' : sha1($pre . $s);
}
function lst(array $ids, int $max): string {
    if (!$ids) return 'none';
    $o = implode(', ', array_slice($ids, 0, $max));
    return count($ids) > $max ? $o . ', … ' . (count($ids) - $max) . ' more' : $o;
}
function jm(array $m): string { if (!$m) return 'none'; $o = []; foreach ($m as $k => $v) $o[] = "$k $v"; return implode(' · ', $o); }
function absentText(string $t, string $why): string {
    if ($why === 'absent') return "absent ({$t} is not in this book)";
    if ($why === 'shape') return "absent ({$t} has an unexpected shape)";
    if ($why === 'nojson') return 'absent (no JSON functions)';
    return 'absent (column ' . $t . '.' . substr($why, 7) . ' missing)';
}
function st4(array $partners, array $live): string {
    // $partners[i] = set of partner indices
    $pairs = 0; $rows = 0; $vo = 0; $lt = 0; $ld = 0;
    foreach ($partners as $i => $ps) {
        if (!$ps) continue;
        $rows++; $pairs += count($ps);
        $anyLive = false; foreach ($ps as $j => $_) if ($live[$j]) { $anyLive = true; break; }
        if ($live[$i]) { if ($anyLive) $ld++; } else { $vo++; if ($anyLive) $lt++; }
    }
    return 'pairs ' . intdiv($pairs, 2) . " · rows {$rows} · voided {$vo} (live twin {$lt}) · live dup {$ld}";
}
function famT(array $edges, array $live): string {
    $p = [];
    foreach ($edges as [$a, $b]) { $p[$a][$b] = true; $p[$b][$a] = true; }
    $vo = 0; $lt = 0;
    foreach ($p as $i => $ps) { if ($live[$i]) continue; $vo++; foreach ($ps as $j => $_) if ($live[$j]) { $lt++; break; } }
    return 'rows ' . count($p) . " · voided {$vo} (live twin {$lt})";
}

// ── the schema ───────────────────────────────────────────────────────────────────────────────────────────────────
$present = [];
foreach ($all("SELECT name FROM sqlite_master WHERE type='table'") as $r) $present[(string)$r['name']] = true;
$colsOf = static function (string $t) use ($all): array { return array_map(static fn($r) => (string)$r['name'], $all("PRAGMA table_info(" . $t . ")")); };
$T = ['cb_ledger', 'cb_accounts', 'fin_audit', 'staff_ledger', 'equipment_assignments', 'payment_collections', 'cash_ins', 'cash_expenses', 'crm_payment_retry'];
$cols = []; $count = [];
foreach ($T as $t) if (isset($present[$t])) { $cols[$t] = $colsOf($t); $count[$t] = count($all("SELECT rowid FROM $t")); }
$need = ['cb_accounts' => ['id', 'kind'], 'fin_audit' => ['id', 'record_type', 'record_id', 'action', 'created_at', 'changed_keys', 'before_json', 'after_json'],
         'staff_ledger' => ['idempotency_key', 'status', 'voided_by', 'voided_at'], 'equipment_assignments' => ['released_at', 'starlink_account']];
$fields = ['payment_collections' => ['id', 'crm_payment_id', 'method', 'status', 'voided_by', 'voided_at', 'audit_log', 'source', 'crm_customer_id', 'kyc_app_id', 'amount', 'currency', 'created_at'],
           'cash_ins' => ['id', 'status', 'voided_by', 'voided_at', 'created_at', 'category', 'currency', 'audit_log'],
           'cash_expenses' => ['id', 'status', 'voided_at', 'audit_log'], 'crm_payment_retry' => ['id', 'collection_id', 'crm_payment_id', 'crm_id', 'status']];
$use = []; $missing = []; $shape = [];
foreach ($need as $t => $cs) {
    if (!isset($cols[$t])) { $use[$t] = 'absent'; continue; }
    $use[$t] = true;
    foreach ($cs as $c) if (!in_array($c, $cols[$t], true)) { $missing[] = "$t.$c"; if ($use[$t] === true) $use[$t] = "column:$c"; }
}
foreach ($fields as $t => $fs) {
    if (!isset($cols[$t])) { $use[$t] = 'absent'; $shape[$t] = 'absent'; continue; }
    if (in_array('data', $cols[$t], true)) $shape[$t] = 'json'; elseif (count($cols[$t]) > 2) $shape[$t] = 'proper'; else { $shape[$t] = 'shape'; $use[$t] = 'shape'; continue; }
    $use[$t] = true;
    if ($shape[$t] === 'proper') foreach ($fs as $f) if (!in_array($f, $cols[$t], true)) { $missing[] = "$t.$f"; if ($f !== 'audit_log' && $use[$t] === true) $use[$t] = "column:$f"; }
}
$FA = $use['fin_audit'] === true; $PC = $use['payment_collections'] === true; $RT = $use['crm_payment_retry'] === true;
$hasAcc = $use['cb_accounts'] === true;

// ── the stores, decoded ──────────────────────────────────────────────────────────────────────────────────────────
$bad = []; $S = [];
foreach (array_keys($fields) as $t) {
    $bad[$t] = 0; $S[$t] = [];
    if ($use[$t] !== true) continue;
    if ($shape[$t] === 'json') {
        foreach ($all("SELECT id, data FROM $t ORDER BY id") as $r) {
            $o = json_decode((string)$r['data']);
            if (json_last_error() !== JSON_ERROR_NONE) { $bad[$t]++; continue; }
            $S[$t][] = ['rid' => (int)$r['id'], 'o' => $o, 'proper' => false];
        }
    } else {
        $sel = array_values(array_intersect($fields[$t], $cols[$t]));
        foreach ($all('SELECT id AS rid' . ($sel ? ', ' . implode(', ', array_map(static fn($c) => '"' . $c . '"', $sel)) : '') . " FROM $t ORDER BY id") as $r) {
            $o = new stdClass(); foreach ($sel as $c) $o->$c = $r[$c];
            $S[$t][] = ['rid' => (int)$r['rid'], 'o' => $o, 'proper' => true];
        }
    }
}
/** Field f of a store row. */
$F = static function (array $x, string $f) { return jx($x['o'], $f); };
/** The audit_log of a store row: [void_at, relabelled], each null when it is not a JSON array. */
$auditOf = static function (array $x): array {
    $raw = $x['proper'] ? (property_exists($x['o'], 'audit_log') ? $x['o']->audit_log : null) : null;
    if ($x['proper']) {
        if ($raw === null) return [null, null];
        $a = json_decode((string)$raw); if (json_last_error() !== JSON_ERROR_NONE) return [null, null];
    } else {
        if (!is_object($x['o']) || !property_exists($x['o'], 'audit_log')) return [null, null];
        $a = $x['o']->audit_log;
    }
    if (!is_array($a)) return [null, null];
    $min = null; $rel = 0;
    foreach ($a as $e) {
        if (!is_object($e) || !property_exists($e, 'action')) continue;
        if ($e->action === 'void' && property_exists($e, 'at') && is_string($e->at)) $min = $min === null || strcmp($e->at, $min) < 0 ? $e->at : $min;
        if ($e->action === 'relabel') $rel = 1;
    }
    return [$min === null ? null : substr($min, 0, 16), $rel];
};
$jid = static function (array $x) use ($F): int { $v = ci($F($x, 'id')); return $v === null ? $x['rid'] : $v; };

// ── the ledger, every row ────────────────────────────────────────────────────────────────────────────────────────
$acc = [];
if ($hasAcc) foreach ($all('SELECT * FROM cb_accounts') as $a) $acc[(int)$a['id']] = (string)$a['kind'];
$R = $all('SELECT * FROM cb_ledger ORDER BY id');
$N = count($R); $idx = [];
foreach ($R as $i => &$r) {
    $idx[(int)$r['id']] = $i;
    $r['_id'] = (int)$r['id'];
    $r['_vref'] = trim((string)$r['validation_ref'], ' ');
    $r['_nref'] = strncmp($r['_vref'], 'CRM-PAY-', 8) === 0 ? 'PAY-' . substr($r['_vref'], 8) : $r['_vref'];
    $d = substr((string)$r['date'], 0, 10); $r['_d'] = okDate($d) ? $d : '';
    $r['_dir'] = (string)$r['direction'];
    $r['_cu'] = strtoupper(trim((string)$r['currency'], ' '));
    $r['_cents'] = (int)round(100 * ($r['_cu'] === 'SSP' ? (float)($r['ssp_amount'] ?? 0) : (float)$r['amount']));
    $r['_ac'] = (int)round(100 * (float)$r['amount']);
    $r['_st'] = (string)$r['status']; $r['_live'] = !in_array($r['_st'], ['voided', 'voided_reconcile'], true);
    $r['_src'] = (string)$r['source']; $cat = (string)$r['category'];
    $r['_rf'] = $r['_dir'] === 'in' && $r['_src'] !== 'crm_webhook_reversal' && strtoupper(trim((string)$r['txn_type'], ' ')) !== 'REFUND' && !in_array($cat, ['Refund', 'Customer Refund', 'Customer Commission'], true);
    $r['_rec'] = $r['_dir'] === 'in' && $cat === 'Receipt';
    $r['_pay'] = ci($r['crm_payment_id'] ?? 0) ?? 0; $r['_cli'] = ci($r['crm_client_id'] ?? 0) ?? 0;
    $r['_kyc'] = $r['_src'] === 'kyc_cash_sale' || strncmp($r['_vref'], 'CRM#', 4) === 0;
    $a = (int)($r['account_id'] ?? 0); $r['_acc'] = $a;
    $r['_kind'] = !$hasAcc ? '(no accounts table)' : ($a === 0 ? 'unassigned' : (isset($acc[$a]) ? w($acc[$a], XKINDS) : 'missing'));
    $r['_cat'] = substr((string)$r['created_at'], 0, 16);
    $r['_srcol'] = preg_match('/^COL-(\d{1,18})$/', (string)$r['sr'], $m) ? (int)$m[1] : 0;
    $r['_vcol'] = preg_match('/^COL-(\d{1,18})$/', $r['_vref'], $m) ? (int)$m[1] : 0;
    $r['_npay'] = preg_match('/^PAY-(\d{1,18})$/', $r['_nref'], $m) ? (int)$m[1] : 0;
    $desc = (string)$r['description'];
    // a stamp older than the row's own creation minute is not the row's (a reversal copies its original's text, a hand
    // entry can hold pasted text): every byte of such a stamp is masked out, and the row is read from what is left
    $made = preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', (string)$r['created_at'], $mc) ? $mc[1] . ' ' . $mc[2] : '';
    $mask = []; $own = ['a' => [], 'r' => []]; $r['_early'] = false;
    foreach (['a' => '/ \[VOIDED: .*? — by [^\]]* (\d{4}-\d{2}-\d{2} \d{2}:\d{2})\]/s', 'r' => '/ \[VOIDED: handover double-count reconcile (\d{4}-\d{2}-\d{2} \d{2}:\d{2})\]/'] as $k => $re) {
        preg_match_all($re, $desc, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($ms as $x) {
            if ($made !== '' && $x[1][0] < $made) { $r['_early'] = true; for ($b = $x[0][1]; $b < $x[0][1] + strlen($x[0][0]); $b++) $mask[$b] = 1; }
            else $own[$k][] = $x[1][0];
        }
    }
    $left = ''; for ($b = 0, $n = strlen($desc); $b < $n; $b++) if (!isset($mask[$b])) $left .= $desc[$b];
    $t70 = str_contains($desc, 'by the Manual Entry form') && str_contains($desc, '(5.18.70)]');
    $t69 = str_contains($desc, 'by the Manual Entry form') && str_contains($desc, '(5.18.69)]');
    $r['_t70'] = $t70; $r['_t69'] = $t69;
    $r['_form'] = $own['a'] ? 'actor' : ($own['r'] ? 'reconcile' : (($t70 || $t69) ? 'tool' : (preg_match('/ \[VOIDED: [^\]]*\]/', $left) ? 'plain' : 'none')));
    $mins = array_merge($own['a'], $own['r']); sort($mins); $r['_smin'] = $mins[0] ?? '';
    $r['_marks'] = substr_count($left, '[VOIDED:');
    $r['_anymark'] = str_contains($desc, '[VOIDED:');
    $r['_isrev'] = (string)$r['source'] === 'crm_webhook_reversal' || stripos(trim((string)$r['validation_ref'], ' '), 'REV-CRM-PAY-') === 0;
    $r['_rev'] = str_contains($desc, ' [REVERSED — CRM deleted]');
    $r['_th'] = hsh('t|', $desc, true); $r['_np'] = hsh('p|', (string)$r['person'], false);
    $r['_adj'] = $CAND !== null && $r['_ac'] === $CAND;
}
unset($r);
$live = array_map(static fn($r) => $r['_live'], $R);

// ── fin_audit, every cb_ledger record ────────────────────────────────────────────────────────────────────────────
$FAR = [];
if ($FA) foreach ($all('SELECT * FROM fin_audit ORDER BY id') as $f) {
    if ((string)$f['record_type'] !== 'cb_ledger') continue;
    $b = $f['before_json'] === null ? null : json_decode((string)$f['before_json']); $bv = $f['before_json'] !== null && json_last_error() === JSON_ERROR_NONE;
    $a = $f['after_json'] === null ? null : json_decode((string)$f['after_json']); $av = $f['after_json'] !== null && json_last_error() === JSON_ERROR_NONE;
    $f['_b'] = $bv ? $b : null; $f['_a'] = $av ? $a : null;
    $f['_bok'] = $bv && is_object($b) && ci(jx($b, 'id')) === (int)$f['record_id'] ? 1 : 0;
    $f['_t'] = substr((string)$f['created_at'], 0, 16);
    $FAR[] = $f;
}
$bf = static fn(array $f, string $k) => jx($f['_b'], $k);
$af = static fn(array $f, string $k) => jx($f['_a'], $k);

$out = [];
$tl = ['cb_ledger ' . $count['cb_ledger']];
foreach (['cb_accounts', 'fin_audit', 'staff_ledger', 'equipment_assignments'] as $t) $tl[] = "$t " . (isset($count[$t]) ? $count[$t] : 'absent');
$out[] = lab('tables', implode(' · ', $tl));
$sp = [];
foreach (array_keys($fields) as $t) {
    $s = $shape[$t];
    $sp[] = $s === 'absent' ? "$t absent" : ($s === 'shape' ? "$t absent (unexpected shape)" : ($s === 'proper' ? "$t proper {$count[$t]}" : "$t json {$count[$t]} (not valid JSON {$bad[$t]})"));
}
$out[] = lab('stores', implode(' · ', $sp));
sort($missing, SORT_STRING);
$out[] = lab('columns', $missing ? 'missing: ' . implode(', ', $missing) : 'none missing');

// ── KYC application ids ──────────────────────────────────────────────────────────────────────────────────────────
$cli = array_map(static fn($r) => $r['_cli'], $R); $appIds = 0; $amb = 0;
if ($PC) foreach ($R as $i => $r) {
    $x = $r['_cli']; if (!$r['_kyc'] || $x <= 0) continue;
    $c1 = false; $c2 = false;
    foreach ($S['payment_collections'] as $p) {
        if ((string)$F($p, 'source') !== 'kyc_cash_sale') continue;
        if ((ci($F($p, 'crm_customer_id')) ?? 0) === $x) $c1 = true;
        if ((ci($F($p, 'kyc_app_id')) ?? 0) === $x) $c2 = true;
    }
    if (!$c1 && $c2) { $cli[$i] = 0; $appIds++; } elseif ($c1 && $c2) $amb++;
}

// ── two-leg entries: every pair of rows checked ──────────────────────────────────────────────────────────────────
$leg = []; $lf = 0; $ls = 0; $lo = 0;
for ($i = 0; $i < $N; $i++) {
    if ($R[$i]['_vref'] === '') continue;
    $same = [];
    for ($j = 0; $j < $N; $j++) if ($R[$j]['_vref'] === $R[$i]['_vref'] && $R[$j]['_src'] === $R[$i]['_src']) $same[] = $j;
    if (count($same) !== 2 || $same[0] !== $i) continue;
    $j = $same[1]; $s = $R[$i]['_src']; $pairSrc = in_array($s, ['account_transfer', 'funding', 'fx_exchange'], true);
    $opp = ($R[$i]['_dir'] === 'in' && $R[$j]['_dir'] === 'out') || ($R[$i]['_dir'] === 'out' && $R[$j]['_dir'] === 'in');
    if (!$pairSrc && !$opp) continue;
    $leg[$i] = $j; $leg[$j] = $i;
    if ($s === 'funding') $lf++; elseif ($pairSrc) $ls++; else $lo++;
}
$isLeg = static fn(int $a, int $b): bool => ($leg[$a] ?? -1) === $b;

// ── §1.2 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
$V = []; foreach ($R as $i => $r) if (!$r['_live']) $V[] = $i;
$nV = count($V); $vo = 0; foreach ($V as $i) if ($R[$i]['_st'] === 'voided') $vo++;
$bs = []; $bm = []; $pathN = ['reconcile' => 0, 'sf' => 0, 'pair' => 0, 'snf' => 0, 'tool' => 0, 'sc' => 0, 'none' => 0]; $pk = [];
$faVoidOf = static function (int $id) use ($FAR): array { $o = []; foreach ($FAR as $f) if ((int)$f['record_id'] === $id && (string)$f['action'] === 'void') $o[] = $f; return $o; };
foreach ($V as $i) {
    $r = $R[$i];
    $k = sr($r['_src']); $bs[$k] = ($bs[$k] ?? 0) + 1;
    $k = dy(substr((string)$r['date'], 0, 7)); $bm[$k] = ($bm[$k] ?? 0) + 1;
    if ($r['_st'] === 'voided_reconcile' || $r['_form'] === 'reconcile') $pathN['reconcile']++;
    elseif ($r['_st'] === 'voided' && $FA && $faVoidOf($r['_id'])) $pathN['sf']++;
    elseif ($r['_st'] === 'voided' && in_array($r['_src'], ['account_transfer', 'funding', 'fx_exchange'], true) && $r['_vref'] !== '' && $r['_form'] === 'actor') { $pathN['pair']++; $pk[$r['_src'] . '|' . $r['_vref']] = 1; }
    elseif ($r['_form'] === 'actor') $pathN['snf']++;
    elseif ($r['_form'] === 'tool') $pathN['tool']++;
    elseif ($r['_form'] === 'plain') $pathN['sc']++;
    else $pathN['none']++;
}
ksort($bs, SORT_STRING); ksort($bm, SORT_STRING);
$out[] = lab('voided rows', "$nV of $N rows: voided $vo · voided_reconcile " . ($nV - $vo));
$out[] = con('by source   ' . jm($bs));
$out[] = con("by path     reconcile {$pathN['reconcile']} · single+finaudit " . ($FA ? $pathN['sf'] : absentText('fin_audit', (string)$use['fin_audit'])) . " · pair {$pathN['pair']} (" . count($pk) . " pair(s)) · single, no finaudit {$pathN['snf']} · records tool {$pathN['tool']} · staff cashbooks {$pathN['sc']} · no stamp {$pathN['none']}");
$out[] = con('by month    ' . jm($bm));

// ── §1.3 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
$jt = static function (array $x) use ($F, $auditOf): string {
    if ((string)$F($x, 'status') !== 'voided') return '';
    $v = $F($x, 'voided_at'); $v = $v === null ? '' : substr((string)$v, 0, 16);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 16);
    $a = (string)($auditOf($x)[0] ?? '');
    return preg_match('/^\d{4}-\d{2}-\d{2}/', $a) ? substr($a, 0, 16) : '';
};
$vt = []; $from = ['s' => 0, 'f' => 0, 'j' => 0, 'u' => 0]; $multi = 0;
foreach ($V as $i) {
    $r = $R[$i]; $cands = [];
    if ($r['_smin'] !== '') $cands[] = [$r['_smin'], 0];
    foreach ($faVoidOf($r['_id']) as $f) if (preg_match('/^\d{4}-\d{2}-\d{2}/', $f['_t'])) $cands[] = [$f['_t'], 1];
    if (in_array($r['_form'], ['plain', 'tool', 'none'], true)) {
        $v = $r['_vref'];
        if (preg_match('/^COL-(\d+)$/', $v, $m)) { foreach ($S['payment_collections'] as $x) if ($jid($x) === (int)$m[1] && ($t = $jt($x)) !== '') $cands[] = [$t, 2]; }
        elseif (preg_match('/^(?:CRM-)?PAY-(\d+)$/', $v, $m)) { foreach ($S['payment_collections'] as $x) if ((ci($F($x, 'crm_payment_id')) ?? 0) === (int)$m[1] && (int)$m[1] > 0 && ($t = $jt($x)) !== '') $cands[] = [$t, 2]; }
        elseif (preg_match('/^EXP-(\d+)$/', $v, $m)) { foreach ($S['cash_expenses'] as $x) if ($jid($x) === (int)$m[1] && ($t = $jt($x)) !== '') $cands[] = [$t, 2]; }
    }
    usort($cands, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $vt[$i] = $cands[0][0] ?? '';
    $from[$cands ? ['s', 'f', 'j'][$cands[0][1]] : 'u']++;
    if ($r['_marks'] >= 2 && !$r['_isrev']) $multi++;
}
$x = 0; $y = 0; $z = 0;
foreach ($V as $i) { $t = $vt[$i]; if ($t === '') continue; if (substr($t, 0, 10) === '2026-10-04') $y++; elseif ($t < '2026-10-04 00:00') $x++; else $z++; }
$note = [];
foreach (['payment_collections', 'cash_expenses'] as $t) if ($use[$t] !== true) $note[] = "$t " . absentText($t, (string)$use[$t]);
$out[] = lab('void time', "from a stamp {$from['s']} · from fin_audit " . ($FA ? $from['f'] : absentText('fin_audit', (string)$use['fin_audit'])) . " · from the JSON record {$from['j']}"
    . ($note ? ' (not read: ' . implode('; ', $note) . ')' : '') . " · unknown {$from['u']} (counted as before 04 Oct); rows with two or more void marks $multi");
$out[] = con("vs 04 Oct   before $x · on 04 Oct $y · after $z · unknown {$from['u']}");
// candidates
$cand = []; $notCur = []; $notDir = [];
foreach ($R as $i => $r) {
    if (!$r['_adj']) continue;
    if ($r['_dir'] === 'out') { if ($r['_cu'] === 'UGX' || $r['_cu'] === '') $cand[] = $i; else $notCur[] = $i; }
    elseif ($r['_dir'] === 'in') $notDir[] = $i;
}
$hit = static function ($dir, $amt, $cur) use ($CAND): bool {
    return $CAND !== null && (string)$dir === 'out' && $amt !== null && (int)round(100 * (float)cr($amt)) === $CAND && in_array(strtoupper(trim((string)$cur, ' ')), ['UGX', ''], true);
};
$cDel = []; $cEdit = [];
if ($FA) foreach ($FAR as $f) {
    $act = (string)$f['action'];
    if ($act === 'delete' && $f['_bok'] === 1 && $hit($bf($f, 'direction'), $bf($f, 'amount'), $bf($f, 'currency'))) $cDel[] = $f;
    if ($act === 'update' && $hit($bf($f, 'direction'), $bf($f, 'amount'), $bf($f, 'currency')) && !$hit($af($f, 'direction'), $af($f, 'amount'), $af($f, 'currency'))) {
        if (!isset($cEdit[(int)$f['record_id']])) $cEdit[(int)$f['record_id']] = $f;
    }
}
ksort($cEdit);
$vsc = static function (string $what, string $cat) use ($V, $vt): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $cat)) return '            candidate ' . $what . ': creation unknown';
    $b = 0; $a = 0; $u = 0;
    foreach ($V as $i) { if ($vt[$i] === '') $u++; elseif ($vt[$i] < $cat) $b++; else $a++; }
    return "vs candidate $what  before its creation $b · at or after $a · unknown $u";
};
foreach (array_slice($cand, 0, 20) as $i) $out[] = con($vsc((string)$R[$i]['_id'], $R[$i]['_cat']));
$faC = [];
foreach ($cDel as $f) $faC[(int)$f['record_id']] ??= ['deleted', (string)$bf($f, 'created_at')];
foreach ($cEdit as $rid => $f) $faC[$rid] ??= ['edited away', (string)$bf($f, 'created_at')];
ksort($faC);
foreach (array_slice($faC, 0, 20, true) as $rid => [$how, $cat]) $out[] = con($vsc("$rid ($how)", substr($cat, 0, 16)));
// evidence
$fm = static fn(array $m): string => "actor-minute {$m['actor']} · reconcile {$m['reconcile']} · tool {$m['tool']} · plain {$m['plain']} · no stamp {$m['none']}";
if ($FA) {
    $e0 = ['actor' => 0, 'reconcile' => 0, 'tool' => 0, 'plain' => 0, 'none' => 0]; $er = $e0; $en = $e0; $eu = 0;
    foreach ($V as $i) {
        $fv = $faVoidOf($R[$i]['_id']);
        if (!$fv) { $en[$R[$i]['_form']]++; continue; }
        $readable = false; foreach ($fv as $f) if ($f['_bok'] === 1) $readable = true;
        if ($readable) $er[$R[$i]['_form']]++; else $eu++;
    }
    $out[] = lab('evidence', 'fin_audit void, before_json readable: ' . $fm($er));
    $out[] = con("fin_audit void, before_json unreadable: $eu");
    $out[] = con('no fin_audit void: ' . $fm($en));
    $pr = 0; $pu = 0; $pd = 0; $pn = 0;
    foreach ($V as $i) {
        $id = $R[$i]['_id']; $ups = []; foreach ($FAR as $f) if ((int)$f['record_id'] === $id && (string)$f['action'] === 'update') $ups[] = $f;
        if (!$ups) continue;
        $fv = $faVoidOf($id); $first = $fv ? min(array_map(static fn($f) => (int)$f['id'], $fv)) : null;
        if ($first === null && $vt[$i] === '') { $pn++; continue; }
        $c = 0;
        foreach ($ups as $u) {
            if ($first !== null ? (int)$u['id'] <= $first : $u['_t'] < $vt[$i]) continue;
            $c++; $pu++; if (in_array('description', explode(',', (string)$u['changed_keys']), true)) $pd++;
        }
        if ($c) $pr++;
    }
    $out[] = con("post-void   voided rows with fin_audit updates after the first void $pr (update rows $pu, of them touching the description $pd); same-minute updates included where the void time is a minute only; voided rows with updates and the void time unknown $pn");
} else {
    $out[] = lab('evidence', absentText('fin_audit', (string)$use['fin_audit']));
    $out[] = con('post-void   ' . absentText('fin_audit', (string)$use['fin_audit']));
}
$und = [];
if ($FA) foreach ($FAR as $f) { if ((string)$f['action'] !== 'void') continue; $i = $idx[(int)$f['record_id']] ?? -1; if ($i >= 0 && $live[$i]) $und[(int)$f['record_id']] = 1; }
ksort($und);
$lm = ['actor' => 0, 'reconcile' => 0, 'tool' => 0, 'plain' => 0]; $lmn = 0;
foreach ($R as $r) { if (!$r['_live'] || $r['_marks'] < 1 || $r['_isrev']) continue; $lmn++; if (isset($lm[$r['_form']])) $lm[$r['_form']]++; }
$out[] = con('void undone fin_audit void records whose row is now live ' . ($FA ? count($und) . ': ids ' . lst(array_keys($und), 20) : absentText('fin_audit', (string)$use['fin_audit']))
    . "; live rows carrying a [VOIDED: mark $lmn: actor-minute {$lm['actor']} · reconcile {$lm['reconcile']} · tool {$lm['tool']} · plain {$lm['plain']}");
$cm = ['live' => 0, 'voided' => 0]; $ea = ['rev' => 0, 'other' => 0];
foreach ($R as $r) {
    if ($r['_isrev'] && $r['_anymark']) $cm[$r['_live'] ? 'live' : 'voided']++;
    if ($r['_early']) $ea[$r['_isrev'] ? 'rev' : 'other']++;
}
$out[] = con('copied text reversal rows carrying a [VOIDED: mark ' . ($cm['live'] + $cm['voided']) . " (live {$cm['live']} · voided {$cm['voided']}), left out of both mark counts above; rows with a stamp minute before their own creation, not taken as theirs: reversal rows {$ea['rev']} · other rows {$ea['other']}");

// ── §1.4 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
if ($FA) {
    $ac = ['create' => 0, 'update' => 0, 'void' => 0, 'delete' => 0, '(other)' => 0]; $other = 0;
    foreach ($all('SELECT * FROM fin_audit') as $f) { if ((string)$f['record_type'] !== 'cb_ledger') { $other++; continue; } $ac[w((string)$f['action'], XACT)]++; }
    $out[] = lab('fin_audit', "cb_ledger records: create {$ac['create']} · update {$ac['update']} · void {$ac['void']} · delete {$ac['delete']} · other {$ac['(other)']}; other record types $other");
    $dv = 0; $dl = 0; $du = 0; $dm = []; $di = []; $nd = 0;
    foreach ($FAR as $f) {
        if ((string)$f['action'] !== 'delete') continue;
        $nd++;
        if ($f['_bok'] !== 1) $du++; elseif (in_array((string)$bf($f, 'status'), ['voided', 'voided_reconcile'], true)) { $dv++; $di[] = [(int)$f['record_id'], (int)$f['id']]; } else $dl++;
        $k = dy(substr($f['_t'], 0, 7)); $dm[$k] = ($dm[$k] ?? 0) + 1;
    }
    ksort($dm, SORT_STRING); sort($di);
    $out[] = lab('deleted', "$nd cb_ledger row(s) deleted: voided when deleted $dv · live $dl · before_json unreadable $du");
    $out[] = con('by month    ' . jm($dm));
    $out[] = con('deleted voided rows, ids: ' . lst(array_column($di, 0), 20));
} else {
    $out[] = lab('fin_audit', absentText('fin_audit', (string)$use['fin_audit']));
    $out[] = lab('deleted', absentText('fin_audit', (string)$use['fin_audit']));
}

// ── §1.5 twins: every pair of rows, every rule ───────────────────────────────────────────────────────────────────
$noDate = 0; foreach ($R as $r) if ($r['_d'] === '') $noDate++;
$rel = []; // rule => [i => [j => true]]
$add = static function (string $k, int $a, int $b) use (&$rel): void { $rel[$k][$a][$b] = true; $rel[$k][$b][$a] = true; };
$P = [];   // collection id => [p => own|retry]
$colIds = [];
foreach ($S['payment_collections'] as $x) { $c = $jid($x); $colIds[$c] = 1; $p = ci($F($x, 'crm_payment_id')) ?? 0; if ($p > 0) $P[$c][$p] = 'own'; }
foreach ($S['crm_payment_retry'] as $x) { $c = ci($F($x, 'collection_id')) ?? 0; if ($c <= 0) continue; $colIds[$c] = 1; foreach (['crm_payment_id', 'crm_id'] as $k) { $p = ci($F($x, $k)) ?? 0; if ($p > 0) $P[$c][$p] ??= 'retry'; } }
foreach ($R as $r) { if ($r['_srcol'] > 0) $colIds[$r['_srcol']] = 1; if ($r['_vcol'] > 0) $colIds[$r['_vcol']] = 1; }
ksort($colIds);
$inRows = static function (array $r, int $c, array $Pc): bool {
    if ($r['_srcol'] === $c || $r['_vcol'] === $c) return true;
    if (!$r['_rf']) return false;
    foreach ($Pc as $p => $_) if ($r['_pay'] === $p || $r['_npay'] === $p) return true;
    return false;
};
$t3own = 0; $t3ret = 0;
if ($PC) foreach ($colIds as $c => $_) {
    $own = array_filter($P[$c] ?? [], static fn($v) => $v === 'own');
    $rows = []; $rowsOwn = 0;
    foreach ($R as $i => $r) { if ($inRows($r, $c, $P[$c] ?? [])) $rows[] = $i; if ($inRows($r, $c, $own)) $rowsOwn++; }
    if (count($rows) < 2) continue;
    if ($rowsOwn >= 2) $t3own++; else $t3ret++;
    foreach ($rows as $a) foreach ($rows as $b) if ($a < $b && !$isLeg($a, $b)) $add('T3', $a, $b);
}
for ($i = 0; $i < $N; $i++) for ($j = $i + 1; $j < $N; $j++) {
    $a = $R[$i]; $b = $R[$j];
    if ($isLeg($i, $j)) continue;
    if ($a['_rf'] && $b['_rf'] && $a['_pay'] > 0 && $a['_pay'] === $b['_pay']) $add('T1', $i, $j);
    if ($a['_nref'] !== '' && $a['_nref'] === $b['_nref']) $add('T2', $i, $j);
    if ($a['_d'] === '' || $b['_d'] === '') continue;
    $dd = days($a['_d'], $b['_d']);
    $same = $a['_dir'] === $b['_dir'] && $a['_cu'] === $b['_cu'] && $a['_cents'] === $b['_cents'];
    foreach ([0, 7] as $W) {
        if ($dd > $W) continue;
        if ($same && $cli[$i] > 0 && $cli[$i] === $cli[$j]) $add("T4$W", $i, $j);
        if ($same && $cli[$i] === 0 && $cli[$j] === 0) { $add("T5$W", $i, $j); if ($a['_th'] !== '' && $a['_th'] === $b['_th']) $add("T5t$W", $i, $j); }
        foreach ([[$i, $j], [$j, $i]] as [$k, $s]) {
            if ($R[$k]['_kyc'] && $cli[$k] > 0 && $R[$s]['_rf'] && strncmp($R[$s]['_nref'], 'PAY-', 4) === 0 && $cli[$k] === $cli[$s]
                && $R[$k]['_cu'] === $R[$s]['_cu'] && $R[$k]['_cents'] === $R[$s]['_cents']) { $add("T7$W", $k, $s); $t7[$W][min($k, $s) . ':' . max($k, $s)] = [$k, $s]; }
        }
    }
}
$t7 = $t7 ?? []; $t7[0] = $t7[0] ?? []; $t7[7] = $t7[7] ?? [];
$s4 = static fn(string $k): string => st4($rel[$k] ?? [], $live);
// T6, naive
$t6 = [];
foreach ([0, 7] as $W) {
    $m = 0; $mv = 0; $nx = 0; $t6rows[$W] = [];
    foreach ($R as $i => $r) {
        if ($r['_d'] === '' || $r['_cents'] <= 0) continue;
        $key = $cli[$i] > 0 ? 'c' . $cli[$i] : ($r['_np'] !== '' ? 'p' . $r['_np'] : '');
        if ($key === '') continue;
        $Sx = [];
        foreach ($R as $j => $s) {
            if ($j === $i || $isLeg($i, $j) || $s['_d'] === '') continue;
            $k2 = $cli[$j] > 0 ? 'c' . $cli[$j] : ($s['_np'] !== '' ? 'p' . $s['_np'] : '');
            if ($k2 !== $key || $s['_dir'] !== $r['_dir'] || $s['_cu'] !== $r['_cu'] || days($s['_d'], $r['_d']) > $W) continue;
            if ($s['_cents'] > 0 && $s['_cents'] < $r['_cents']) $Sx[] = $s['_cents'];
        }
        if (count($Sx) < 2) continue;
        if (count($Sx) > 500) { $nx++; continue; }
        $ok = array_sum($Sx) === $r['_cents'];
        for ($p = 0; !$ok && $p < count($Sx); $p++) for ($q = $p + 1; !$ok && $q < count($Sx); $q++) if ($Sx[$p] + $Sx[$q] === $r['_cents']) $ok = true;
        if ($ok) { $m++; if (!$r['_live']) { $mv++; $t6rows[$W][$i] = 1; } }
    }
    $t6[$W] = "rows matched $m · voided $mv · not examined $nx";
}
$linked = 0;
foreach ($t7[7] as [$k, $s]) {
    foreach ($S['payment_collections'] as $x) {
        if ((string)$F($x, 'source') !== 'kyc_cash_sale' || (ci($F($x, 'crm_payment_id')) ?? 0) !== $R[$s]['_pay'] || $R[$s]['_pay'] <= 0) continue;
        if ((ci($F($x, 'crm_customer_id')) ?? 0) === $cli[$k] && (int)round(100 * (float)$F($x, 'amount')) === $R[$k]['_cents'] && substr((string)$F($x, 'created_at'), 0, 10) === $R[$k]['_d']) { $linked++; break; }
    }
}
$twin = []; $lt7 = [];
foreach (['T1', 'T2', 'T3', 'T40', 'T47', 'T50', 'T57', 'T70', 'T77'] as $k) {
    if (!in_array($k, ['T1', 'T2', 'T3', 'T47', 'T57', 'T77'], true)) continue;
    foreach ($rel[$k] ?? [] as $i => $ps) {
        if ($live[$i]) continue;
        $twin[$i] = 1; foreach ($ps as $j => $_) if ($live[$j]) $lt7[$i] = 1;
    }
}
foreach ($t6rows[7] as $i => $_) $twin[$i] = 1;
$pcA = $PC ? '' : absentText('payment_collections', (string)$use['payment_collections']);
$rtA = $RT ? '' : absentText('crm_payment_retry', (string)$use['crm_payment_retry']);
$out[] = lab('twins', "candidates only, never proof · windows: same day | ±7 days · rows without a usable date $noDate");
$out[] = con('two-leg     entries ' . ($lf + $ls + $lo) . " (funding $lf, other pair sources $ls, opposite directions elsewhere $lo) — never twins of each other");
$out[] = con('KYC rows    ' . ($PC ? "whose CRM# is an application id $appIds (client id set to 0); ambiguous, kept as a client $amb" : $pcA . '; client ids kept as stored'));
$out[] = con('T1 payment id    ' . $s4('T1'));
$out[] = con('T2 reference     ' . $s4('T2') . '; pair legs excluded ' . intdiv(count(array_filter(array_keys($leg), static fn($i) => $R[$i]['_nref'] !== '')), 2) . ' group(s)');
$out[] = con('T3 COL/PAY       ' . ($PC ? $s4('T3') . '; collections with ≥ 2 rows ' . ($t3own + $t3ret) . " (through the collection $t3own, through a retry item " . ($RT ? $t3ret : $rtA) . ')' : $pcA));
$out[] = con('T4 client+amount same day: ' . $s4('T40') . ' | ±7 d: ' . $s4('T47'));
$tt = static function (string $k) use ($rel, $live): string { $p = 0; $r = 0; $v = 0; foreach ($rel[$k] ?? [] as $i => $ps) { $p += count($ps); $r++; if (!$live[$i]) $v++; } return 'pairs ' . intdiv($p, 2) . " · rows $r · voided $v"; };
$out[] = con('T5 no client     same day: ' . $s4('T50') . ' | ±7 d: ' . $s4('T57') . '; text candidates same day: ' . $tt('T5t0') . ' | ±7 d: ' . $tt('T5t7'));
$out[] = con("T6 sum           same day: {$t6[0]} | ±7 d: {$t6[7]}");
$out[] = con('T7 KYC/PAY       same day: ' . $s4('T70') . ' | ±7 d: ' . $s4('T77') . '; linked through the KYC collection record ' . ($PC ? $linked : $pcA));
$out[] = con('any rule ±7 d    voided rows with at least one twin ' . count($twin) . " of $nV (with a live twin " . count($lt7) . '; T6 counts as a twin, not as a live twin)');

// ── §1.6 the families, each from its definition ──────────────────────────────────────────────────────────────────
if ($PC) {
    $na = 0; $nac = 0; $edges = [];
    foreach ($colIds as $c => $_) {
        $r0 = []; foreach ($R as $i => $r) if ($r['_vcol'] === $c && $r['_pay'] === 0) $r0[] = $i;
        if (!$r0) continue;
        $part = []; $own = false;
        foreach ($R as $i => $r) {
            if (!$r['_rf'] || $r['_src'] === 'catchup_sync' || $r['_srcol'] === $c || $r['_vcol'] === $c) continue;
            foreach ($P[$c] ?? [] as $p => $how) if ($r['_pay'] === $p || $r['_npay'] === $p) { $part[$i] = 1; if ($how === 'own') $own = true; }
        }
        if (!$part) continue;
        if ($own) $nac++; else $na++;
        foreach ($r0 as $a) foreach ($part as $b => $_x) if ($a !== $b && !$isLeg($a, $b)) $edges[] = [$a, $b];
    }
    $out[] = lab('I1 families', '(a) push failure, then the payment posted: ' . ($na + $nac) . " collection(s) (through the collection $nac, through a retry item " . ($RT ? $na : $rtA) . '); ' . famT($edges, $live));
    $un = 0; $unv = 0;
    foreach ($R as $i => $r) {
        $c = $r['_vcol']; if ($c <= 0 || $r['_pay'] !== 0 || !empty($P[$c])) continue;
        $ok = false; foreach ($rel['T47'][$i] ?? [] as $j => $_) if (strncmp($R[$j]['_nref'], 'PAY-', 4) === 0) $ok = true;
        if ($ok) { $un++; if (!$r['_live']) $unv++; }
    }
    $out[] = con("(a) unlinked: push-failure rows with a PAY- partner by client+amount ±7 d $un (voided $unv)");
} else {
    $out[] = lab('I1 families', '(a) push failure, then the payment posted: ' . $pcA);
    $out[] = con('(a) unlinked: ' . $pcA);
}
$nb = 0; $eb = [];
foreach ($colIds as $c => $_) {
    $cu = []; $oth = [];
    foreach ($R as $i => $r) { if ($r['_src'] === 'catchup_sync' && $r['_srcol'] === $c) $cu[] = $i; elseif ($r['_srcol'] === $c || $r['_vcol'] === $c) { if ($r['_src'] !== 'catchup_sync') $oth[] = $i; } }
    $any = false; foreach ($cu as $a) foreach ($oth as $b) if (!$isLeg($a, $b)) { $eb[] = [$a, $b]; $any = true; }
    if ($any) $nb++;
}
$out[] = con("(b) catch-up beside the COL- row: $nb collection(s); " . famT($eb, $live));
if ($PC) {
    $cr = []; $ce = [];
    foreach ($R as $i => $r) {
        if ($r['_src'] !== 'crm_sync' || $r['_vcol'] <= 0) continue;
        $c = $r['_vcol'];
        foreach ($R as $j => $s) {
            if ($j === $i || $isLeg($i, $j)) continue;
            if ($inRows($s, $c, $P[$c] ?? []) || ($r['_pay'] > 0 && $s['_rf'] && $s['_pay'] === $r['_pay'])) { $cr[$i] = 1; $ce[$i][$j] = 1; }
        }
    }
    $cv = 0; $cl = 0; foreach ($cr as $i => $_) { if ($live[$i]) continue; $cv++; foreach ($ce[$i] as $j => $_y) if ($live[$j]) { $cl++; break; } }
    $out[] = con('(c) pull fallback beside another row of the collection: ' . count($cr) . " row(s) · voided $cv (live twin $cl)");
} else $out[] = con('(c) pull fallback beside another row of the collection: ' . $pcA);
$pays = []; foreach ($R as $r) { if ($r['_pay'] > 0) $pays[$r['_pay']] = 1; if ($r['_npay'] > 0) $pays[$r['_npay']] = 1; }
$nd = 0; $ed = [];
foreach ($pays as $p => $_) {
    $ap = []; $gp = [];
    foreach ($R as $i => $r) {
        if (!$r['_rf'] || ($r['_pay'] !== $p && $r['_npay'] !== $p)) continue;
        if ($r['_src'] === 'crm_api_sync') $ap[] = $i; elseif (in_array($r['_src'], ['crm_gap_fill', 'catchup_sync'], true)) $gp[] = $i;
    }
    if (!$ap || !$gp) continue;
    $nd++; foreach ($ap as $a) foreach ($gp as $b) if (!$isLeg($a, $b)) $ed[] = [$a, $b];
}
$out[] = con("(d) pull beside a gap-filled or catch-up receipt: $nd payment(s); " . famT($ed, $live));
$e0 = 0; $e7 = 0; $er = [];
foreach ([0, 7] as $W) foreach ($t7[$W] as [$k, $s]) { if (!in_array($R[$s]['_src'], ['crm_webhook', 'crm_gap_fill', 'crm_api_sync'], true)) continue; if ($W === 0) $e0++; else { $e7++; $er[$k] = 1; $er[$s] = 1; } }
$ev = 0; foreach ($er as $i => $_) if (!$live[$i]) $ev++;
$out[] = con("(e) KYC row beside a uCRM receipt: same day $e0 · ±7 d $e7 (voided $ev)");
if ($PC && $RT) {
    $nf = 0; foreach ($colIds as $c => $_) if (count($P[$c] ?? []) >= 2) $nf++;
    $fp = 0;
    foreach ($rel['T47'] ?? [] as $i => $ps) foreach ($ps as $j => $_) {
        if ($i >= $j) continue;
        if ($R[$i]['_rf'] && $R[$j]['_rf'] && $R[$i]['_npay'] > 0 && $R[$j]['_npay'] > 0 && $R[$i]['_npay'] !== $R[$j]['_npay']) $fp++;
    }
    $out[] = con("(f) two uCRM payments for one collection: $nf collection(s); ledger candidates ±7 d $fp pair(s)");
} else $out[] = con('(f) two uCRM payments for one collection: ' . ($PC ? $rtA : $pcA));

// ── §1.7 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
$nr = 0; foreach ($R as $r) if ($r['_rec']) $nr++;
$cls = static function ($m): string {
    $m = $m === null ? '' : (string)$m;
    if (trim($m) === '') return 'blank';
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $m)) return strcasecmp($m, '6efe0fa8-36b2-4dd1-b049-427bffc7d369') === 0 ? 'uuid cash' : 'uuid other';
    if (ctype_digit($m)) { $n = (int)$m; return $n === 2 ? 'cash' : ($n === 3 ? 'bank' : ($n === 6 ? 'mobile money' : '(other)')); }
    $s = str_replace([' ', '-'], '_', strtolower(trim($m)));
    if ($s === 'cash') return 'cash';
    if ($s === 'mobile_money' || $s === 'mobile') return 'mobile money';
    if ($s === 'bank_transfer' || $s === 'bank') return 'bank';
    if ($s === 'check' || $s === 'cheque') return 'cheque';
    return '(other)';
};
if ($PC) {
    $lk = 0; $sv = 0; $nl = 0; $dO = 0; $byc = []; foreach (XCLS as $k) $byc[$k] = [0, 0];
    foreach ($R as $r) {
        if (!$r['_rec']) continue;
        $c = $r['_srcol'] ?: $r['_vcol']; $set = [];
        $byJid = []; if ($c > 0) foreach ($S['payment_collections'] as $x) if ($jid($x) === $c) $byJid[] = $x;
        if ($byJid) $set = $byJid;
        else { $p = $r['_pay'] > 0 ? $r['_pay'] : $r['_npay']; if ($p > 0) foreach ($S['payment_collections'] as $x) if ((ci($F($x, 'crm_payment_id')) ?? 0) === $p) $set[] = $x; }
        if (!$set) { $nl++; continue; }
        $nd2 = array_values(array_filter($set, static fn($x) => (string)$F($x, 'source') !== 'crm_direct'));
        if (!$nd2) { $dO++; continue; }
        usort($nd2, static fn($a, $b) => $jid($a) <=> $jid($b));
        $lk++; if (count($set) >= 2) $sv++;
        $k = $cls($F($nd2[0], 'method')); $byc[$k][0]++; if (!$r['_live']) $byc[$k][1]++;
    }
    $out[] = lab('receipt method', "Receipt IN rows $nr: linked to a collection $lk (to several $sv) · not linked $nl; linked only to a pull-made record (crm_direct) $dO, not classed");
    $o = []; foreach (XCLS as $k) $o[] = "$k {$byc[$k][0]} (voided {$byc[$k][1]})";
    $out[] = con('by class    ' . implode(' · ', $o));
    $out[] = con('not Cash    ' . ($byc['mobile money'][0] + $byc['bank'][0] + $byc['cheque'][0] + $byc['uuid other'][0]) . ' (voided ' . ($byc['mobile money'][1] + $byc['bank'][1] + $byc['cheque'][1] + $byc['uuid other'][1])
        . '); unknown (blank, other) ' . ($byc['blank'][0] + $byc['(other)'][0]));
} else $out[] = lab('receipt method', "Receipt IN rows $nr: " . $pcA);

// ── §1.8 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
$rv = [];
foreach ($R as $i => $r) {
    if (!($r['_src'] === 'crm_webhook_reversal' || stripos($r['_vref'], 'REV-CRM-PAY-') === 0)) continue;
    $p = $r['_pay'] > 0 ? $r['_pay'] : (preg_match('/^REV-CRM-PAY-(\d+)$/i', $r['_vref'], $m) ? (int)$m[1] : 0);
    $rv[$p][] = $i;
}
$cnt = ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0, 'e' => 0]; $tw = 0; $mk = 0; $nrv = 0;
foreach ($rv as $p => $rows) {
    $nrv += count($rows); if (count($rows) > 1) $tw++;
    $orig = []; if ($p > 0) foreach ($R as $j => $s) if ($s['_rf'] && $s['_pay'] === $p) $orig[] = $j;
    if (!$orig) {
        $del = false;
        if ($FA && $p > 0) foreach ($FAR as $f) if ((string)$f['action'] === 'delete' && (ci($bf($f, 'crm_payment_id')) ?? 0) === $p && (string)$bf($f, 'direction') === 'in') $del = true;
        $cnt[$del ? 'd' : 'e']++; continue;
    }
    $v = 0; foreach ($orig as $j) if (!$live[$j]) { $v++; if ($R[$j]['_rev']) $mk++; }
    $cnt[$v === count($orig) ? 'a' : ($v > 0 ? 'b' : 'c')]++;
}
$out[] = lab('reversals', "$nrv row(s) for " . count($rv) . " payment(s): every original voided {$cnt['a']} · some voided {$cnt['b']} · originals live {$cnt['c']} · original deleted (fin_audit) " . ($FA ? $cnt['d'] : absentText('fin_audit', (string)$use['fin_audit'])) . " · no original {$cnt['e']}");
$out[] = con("payments reversed twice or more $tw; originals marked reversed and voided $mk");

// ── §1.9 ─────────────────────────────────────────────────────────────────────────────────────────────────────────
$d12 = []; foreach ($R as $i => $r) if (substr((string)$r['date'], 0, 10) === '2026-09-12') $d12[] = $i;
$dl = []; $aw = []; $in = [];
if ($FA) foreach ($FAR as $f) {
    if ((string)$f['action'] === 'delete' && substr((string)$bf($f, 'date'), 0, 10) === '2026-09-12') $dl[] = $f;
    if ((string)$f['action'] === 'update' && $bf($f, 'date') !== null && $af($f, 'date') !== null) {
        $b = substr((string)$bf($f, 'date'), 0, 10) === '2026-09-12'; $a = substr((string)$af($f, 'date'), 0, 10) === '2026-09-12';
        if ($b && !$a) $aw[(int)$f['record_id']][] = (int)$f['id'];
        if (!$b && $a) $in[(int)$f['record_id']][] = (int)$f['id'];
    }
}
ksort($aw); ksort($in);
$fa = $FA ? null : absentText('fin_audit', (string)$use['fin_audit']);
$out[] = lab('rows 2026-09-12', count($d12) . ' row(s) in cb_ledger; deleted rows of that date (fin_audit) ' . ($fa ?? count($dl)) . '; dates edited away from it ' . ($fa ?? count($aw)) . ', into it ' . ($fa ?? count($in)));
$fids = static function (int $id, string $act) use ($FAR): string { $o = []; foreach ($FAR as $f) if ((int)$f['record_id'] === $id && (string)$f['action'] === $act) $o[] = (int)$f['id']; return $o ? implode(', ', $o) : '–'; };
$kindAt = static fn(int $a): string => !$hasAcc ? '(no accounts table)' : ($a === 0 ? 'unassigned' : (isset($acc[$a]) ? w($acc[$a], XKINDS) : 'missing'));
foreach (array_slice($d12, 0, 200) as $i) {
    $r = $R[$i];
    $named = str_contains(strtoupper((string)$r['description']), 'ADJUSTMENT') || str_contains(strtoupper((string)$r['category']), 'ADJUST');
    $out[] = con('id ' . $r['_id'] . '  ' . w($r['_st'], XSTAT) . '  ' . $r['_kind'] . '  p0 rule: live ' . ($r['_live'] ? 'yes' : 'no') . ' · unassigned ' . ($r['_acc'] === 0 ? 'yes' : 'no') . ' · named ' . ($named ? 'yes' : 'no')
        . '  fin_audit: ' . ($fa ?? 'update ' . $fids($r['_id'], 'update') . ' · void ' . $fids($r['_id'], 'void') . ' · delete ' . $fids($r['_id'], 'delete')));
}
if (count($d12) > 200) $out[] = con('… ' . (count($d12) - 200) . ' more');
foreach ($dl as $f) $out[] = con('deleted id ' . (int)$f['record_id'] . '  ' . w((string)$bf($f, 'status'), XSTAT) . '  ' . $kindAt(ci($bf($f, 'account_id')) ?? 0) . '  fin_audit delete ' . (int)$f['id']);
foreach ($aw as $rid => $ids) $out[] = con("date edited: id $rid away from 2026-09-12 (fin_audit update " . implode(', ', $ids) . ')');
foreach ($in as $rid => $ids) $out[] = con("date edited: id $rid into 2026-09-12 (fin_audit update " . implode(', ', $ids) . ')');

// ── §1.10 ────────────────────────────────────────────────────────────────────────────────────────────────────────
$out[] = lab('adj. candidates', "rows with direction out and the recorded adjustment's amount (UGX or blank), any date, any status, any account: " . count($cand));
foreach (array_slice($cand, 0, 50) as $i) $out[] = con('id ' . $R[$i]['_id'] . '  ' . dy($R[$i]['_d']) . '  ' . w($R[$i]['_st'], XSTAT) . '  ' . $R[$i]['_kind']);
if (count($cand) > 50) $out[] = con('… ' . (count($cand) - 50) . ' more');
if ($FA) {
    foreach ($cDel as $f) $out[] = con('deleted (fin_audit only): id ' . (int)$f['record_id'] . '  ' . dy(substr((string)$bf($f, 'date'), 0, 10)) . '  ' . w((string)$bf($f, 'status'), XSTAT) . '  ' . $kindAt(ci($bf($f, 'account_id')) ?? 0));
    foreach ($cEdit as $rid => $f) {
        $i = $idx[$rid] ?? null;
        $out[] = con('edited away (fin_audit before had it): ' . ($i !== null ? "id $rid  " . dy($R[$i]['_d']) . '  ' . w($R[$i]['_st'], XSTAT) . '  ' . $R[$i]['_kind'] : "id $rid (since deleted)"));
    }
} else $out[] = con('deleted, edited away: ' . absentText('fin_audit', (string)$use['fin_audit']));
$cl = static function (array $rows) use ($R): string {
    if (!$rows) return '';
    $o = []; foreach (array_slice($rows, 0, 20) as $i) $o[] = 'id ' . $R[$i]['_id'] . '  ' . dy($R[$i]['_d']) . '  ' . cc((string)$R[$i]['currency']) . '  ' . w($R[$i]['_st'], XSTAT) . '  ' . $R[$i]['_kind'];
    return ': ' . implode(', ', $o) . (count($rows) > 20 ? ', … ' . (count($rows) - 20) . ' more' : '');
};
$out[] = con('not candidates, same amount and direction, another currency ' . count($notCur) . $cl($notCur));
$out[] = con('not candidates, same amount, direction in ' . count($notDir) . $cl($notDir));

// ── §1.11 ────────────────────────────────────────────────────────────────────────────────────────────────────────
$out[] = lab('top unassigned', 'per currency, the 5 largest-amount unassigned rows, any status — no amount printed');
$g = []; foreach ($R as $i => $r) if ($r['_acc'] === 0) $g[cc((string)$r['currency'])][] = $i;
ksort($g, SORT_STRING);
foreach ($g as $k => $rows) {
    usort($rows, static fn($a, $b) => $R[$b]['_cents'] <=> $R[$a]['_cents'] ?: $R[$a]['_id'] <=> $R[$b]['_id']);
    foreach (array_slice($rows, 0, 5) as $n => $i) $out[] = con("$k  " . ($n + 1) . '  id ' . $R[$i]['_id'] . '  ' . dy($R[$i]['_d']) . '  ' . w($R[$i]['_dir'], ['in', 'out']) . '  ' . w($R[$i]['_st'], XSTAT));
}

// ── §1.12 ────────────────────────────────────────────────────────────────────────────────────────────────────────
$ti = []; $tv = 0; $tr = 0; $tlv = 0; $t69 = 0;
foreach ($R as $r) { if ($r['_t69']) $t69++; if (!$r['_t70']) continue; $ti[] = $r['_id']; if ($r['_st'] === 'voided') $tv++; elseif ($r['_st'] === 'voided_reconcile') $tr++; else $tlv++; }
$out[] = lab('records tool', "cb_ledger rows with the 5.18.70 tool's reason stamp " . count($ti) . " (voided $tv, voided_reconcile $tr, live $tlv): ids " . lst($ti, 20) . "; with the 5.18.69 form $t69 (expected 0)");
if ($PC) {
    $c = ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0]; $cid = []; $c69 = 0;
    foreach ($S['payment_collections'] as $x) {
        $vb = vbc($F($x, 'voided_by'));
        if ($vb === 'tool69') $c69++;
        if ($vb !== 'tool70') continue;
        $cid[] = $jid($x); $p = ci($F($x, 'crm_payment_id')) ?? 0;
        $ref = $p > 0 ? "PAY-$p" : 'COL-' . $jid($x);
        $rows = array_filter($R, static fn($r) => $r['_vref'] === $ref);
        $k = 'd';
        if ($rows) { $k = 'c'; foreach ($rows as $r) if (!$r['_live']) $k = 'b'; foreach ($rows as $r) if ($r['_t70']) $k = 'a'; }
        $c[$k]++;
    }
    sort($cid);
    $out[] = con('payment_collections voided by the 5.18.70 tool ' . count($cid) . ": ledger row tool-stamped {$c['a']} · ledger row voided, other stamp {$c['b']} · ledger row live {$c['c']} · no ledger row {$c['d']}; ids " . lst($cid, 20) . "; by the 5.18.69 tool $c69 (expected 0)");
} else $out[] = con('payment_collections voided by the 5.18.70 tool ' . $pcA);
$ct = 0; foreach ($S['cash_ins'] as $x) if (vbc($F($x, 'voided_by')) === 'tool70') $ct++;
$SL = $use['staff_ledger'] === true ? $all('SELECT * FROM staff_ledger') : [];
$slt = 0; foreach ($SL as $r) if ((string)$r['voided_by'] === XT70) $slt++;
$out[] = con('cash_ins voided by the tool ' . ($use['cash_ins'] === true ? $ct : absentText('cash_ins', (string)$use['cash_ins'])) . '; staff_ledger rows voided by the tool ' . ($use['staff_ledger'] === true ? $slt : absentText('staff_ledger', (string)$use['staff_ledger'])));

// ── §1.13 ────────────────────────────────────────────────────────────────────────────────────────────────────────
$out[] = lab('25 Sep cash-ins', 'the three of docs/07 (#3, #4, #5): was VOID typed at the 5.18.70 prompt?');
if ($use['cash_ins'] === true) {
    $hasAudit = $shape['cash_ins'] === 'json' || in_array('audit_log', $cols['cash_ins'], true);
    $vby = ['tool70' => 'the 5.18.70 tool', 'tool69' => 'the 5.18.69 tool', 'none' => 'none', 'other' => 'another value'];
    $voided = 0; $byTool = 0;
    foreach ([3, 4, 5] as $n) {
        $slp = [];
        foreach (['CIN-', 'CINO-'] as $pre) {
            $hit = null; foreach ($SL as $r) if ((string)$r['idempotency_key'] === $pre . $n) $hit = $r;
            $slp[] = $pre . $n . ' ' . ($use['staff_ledger'] !== true ? absentText('staff_ledger', (string)$use['staff_ledger']) : ($hit === null ? 'absent' : w((string)$hit['status'], XSLSTAT) . ', voided_by ' . $vby[vbc($hit['voided_by'])]));
        }
        $x = null; foreach ($S['cash_ins'] as $y) if ($jid($y) === $n) { $x = $y; break; }
        if ($x === null) { $out[] = con("cash-in $n  absent · staff_ledger " . implode(' · ', $slp)); continue; }
        $stv = $F($x, 'status');
        if ((string)$stv === 'voided') { $voided++; if (vbc($F($x, 'voided_by')) === 'tool70') $byTool++; }
        $va = substr((string)$F($x, 'voided_at'), 0, 10);
        $relab = !$hasAudit ? 'unknown (column audit_log missing)' : (($auditOf($x)[1] ?? 0) === 1 ? 'yes' : 'no');
        $out[] = con("cash-in $n  present · " . dy(substr((string)$F($x, 'created_at'), 0, 10)) . ' · ' . cc((string)$F($x, 'currency')) . ' · category as recorded ' . ((string)$F($x, 'category') === 'Collection' ? 'yes' : 'no')
            . ' · ' . ($stv === null ? '(none)' : w((string)$stv, XJSTAT)) . ' · voided_by ' . $vby[vbc($F($x, 'voided_by'))] . ' · voided_at ' . ($va === '' ? '–' : dy($va)) . " · relabelled $relab · staff_ledger " . implode(' · ', $slp));
    }
    $out[] = con('VOID typed   ' . ($byTool === 3 ? 'yes — all three voided by the 5.18.70 tool' : ($voided === 0 ? 'no sign of it — none of the three is voided' : 'mixed — read the three lines')));
} else $out[] = con(absentText('cash_ins', (string)$use['cash_ins']));

// ── §1.14 ────────────────────────────────────────────────────────────────────────────────────────────────────────
if ($use['equipment_assignments'] === true) {
    $l = 0; $e = 0; $accs = [];
    foreach ($all('SELECT * FROM equipment_assignments') as $r) {
        if ($r['released_at'] !== null) continue;
        $l++; $a = trim((string)$r['starlink_account'], ' ');
        if ($a === '') $e++; else $accs[(string)$r['starlink_account']] = ($accs[(string)$r['starlink_account']] ?? 0) + 1;
    }
    $out[] = lab('equipment', "live assignments $l: starlink_account empty $e; non-empty accounts held by two or more live assignments " . count(array_filter($accs, static fn($n) => $n >= 2)));
} else $out[] = lab('equipment', absentText('equipment_assignments', (string)$use['equipment_assignments']));

echo implode("\n", $out), "\n";

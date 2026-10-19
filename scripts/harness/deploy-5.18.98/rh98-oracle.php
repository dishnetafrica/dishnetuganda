<?php
// 5.18.98's rehearsal: its own reading of what the Cashbook's split leaves off the list — read-only (the connection's own
// query_only), written from the design (SPEC-separate v2 §2.1, §2.10 N1–N4), no plugin code, never the script's SQL. The
// predicate is applied row by row here in PHP, with SQLite's own three-valued logic written out, so that a slip in the
// script's CASE expression (NOTES_PHP) and one in this reading would have to be the same slip to agree. One line of JSON:
//   curs        the book's currencies, from the settings row (as rh97-oracle.php reads them)
//   n1          the rows that leave the list, per project — "all:B/O/M/X,dishnet:…,4g:…,bluecard:…,elsewhere:…" — on a
//               bank account (kind exactly 'bank') / an account of another kind / a whole id above 0 naming no account /
//               any other id (negative, text, real); in the form the script's note prints, before its "; " joins
//   noncash     the same rows' totals for all, dishnet, 4g and bluecard — "all:N,dishnet:N,4g:N,bluecard:N"
//   n2          approved, no account, listed, looking like bank money — "crm:N,btin:N,btout:N,charges:N"
//   n3          listed rows with no currency, and how many of them are on the cash side — "blank:N,cash:N"
//   n4          bank accounts ECO Bank does not offer (inactive, or a currency the book does not list) holding listed rows —
//               "accounts:N,rows:N"; n4ids their row ids, for the SHEET's own check alone (never the terminal's)
//   listed      every listed row, a count (the positive control: a reading that sees nothing would agree with anything)
// Counts and row ids only: never a name, a number, an amount.
declare(strict_types=1);
$p = new PDO('sqlite:' . (string)($argv[1] ?? ''), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15]);
$p->exec('PRAGMA query_only = ON');
$has = function (string $t) use ($p): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
    $q->execute([$t]);
    return (int)$q->fetchColumn() === 1;
};
$cfg = [];
if ($has('kyc_config')) {
    $row = $p->query('SELECT data FROM kyc_config WHERE id = 0')->fetchColumn();
    $cfg = is_string($row) ? (array)json_decode($row, true) : [];
}
$curs = [];
foreach (explode(',', (string)($cfg['cashbook_currencies'] ?? 'UGX')) as $c) {
    $c = strtoupper(trim($c));
    if (preg_match('/^[A-Z]{3}$/', $c) === 1 && !in_array($c, $curs, true)) $curs[] = $c;
}
// The chart of accounts: ids by kind. INTEGER PRIMARY KEY, so every id is an integer; kept as ints.
$acc = $has('cb_accounts'); $all = []; $bank = []; $cash = []; $banks = [];
if ($acc) {
    foreach ($p->query('SELECT id, kind, currency, active FROM cb_accounts WHERE id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $id = (int)$a['id']; $all[$id] = true;
        if ((string)$a['kind'] === 'bank') { $bank[$id] = true; $banks[$id] = $a; }
        if (in_array((string)$a['kind'], ['cash', 'momo'], true)) $cash[$id] = true;
    }
}
// One account id as SQLite holds it: [type, value]. A real that is a whole number compares as that number in SQLite.
$num = function (string $t, $v): ?float { return ($t === 'integer' || $t === 'real') ? (float)$v : null; };
$inSet = function (?float $n, array $set): bool { return $n !== null && floor($n) === $n && isset($set[(int)$n]); };
$P = ['dishnet', '4g', 'bluecard'];
$n1 = []; foreach (['all', 'dishnet', '4g', 'bluecard', 'elsewhere'] as $k) $n1[$k] = ['b' => 0, 'o' => 0, 'm' => 0, 'x' => 0];
$n2 = ['crm' => 0, 'btin' => 0, 'btout' => 0, 'charges' => 0]; $n3 = ['blank' => 0, 'cash' => 0];
$onAcct = []; $listedN = 0;
$q = $p->query('SELECT project, amount, typeof(amount) AS ta, sr, status, account_id, typeof(account_id) AS t, currency, source, validation_status, category, direction FROM cb_ledger');
while (($r = $q->fetch(PDO::FETCH_ASSOC)) !== false) {
    // Listed: NOT (amount = 0 AND sr = '') AND status != 'voided_reconcile', in three-valued logic — a NULL anywhere in a
    // term that cannot be decided leaves the row out, as SQLite's WHERE does.
    $amt0 = $r['amount'] === null ? null : ((float)$r['amount'] == 0.0);
    $srE  = $r['sr'] === null ? null : ((string)$r['sr'] === '');
    $ghost = ($amt0 === false || $srE === false) ? false : (($amt0 === null || $srE === null) ? null : true);
    if ($ghost !== false) continue;
    if ($r['status'] === null || (string)$r['status'] === 'voided_reconcile') continue;
    $listedN++;
    $t = (string)$r['t']; $n = $num($t, $r['account_id']);
    $isCash = $t === 'null' || ($n !== null && $n == 0.0) || ($acc && $inSet($n, $cash));
    $noAcct = $t === 'null' || ($n !== null && $n == 0.0);
    $pj = in_array($r['project'], $P, true) ? (string)$r['project'] : 'elsewhere';
    $blank = $r['currency'] === null || (string)$r['currency'] === '';
    if ($blank) { $n3['blank']++; if ($isCash) $n3['cash']++; }
    if ($n !== null && floor($n) === $n) { $onAcct[(int)$n] = ($onAcct[(int)$n] ?? 0) + 1; }
    if (!$isCash) {
        if ($inSet($n, $bank)) $k = 'b';
        elseif ($inSet($n, $all)) $k = 'o';
        elseif ($t === 'integer' && $n > 0) $k = 'm';
        else $k = 'x';
        $n1[$pj][$k]++; $n1['all'][$k]++;
    } elseif ($noAcct && (string)$r['status'] === 'approved') {
        if (in_array((string)$r['source'], ['crm_sync', 'crm_api_sync', 'crm_webhook'], true) && (string)$r['validation_status'] === 'online') $n2['crm']++;
        if ((string)$r['category'] === 'Bank Transfer' && (string)$r['direction'] === 'in') $n2['btin']++;
        if ((string)$r['category'] === 'Bank Transfer' && (string)$r['direction'] === 'out') $n2['btout']++;
        if ((string)$r['category'] === 'Bank Charges') $n2['charges']++;
    }
}
$n4a = 0; $n4r = 0; $n4ids = [];
ksort($banks);
foreach ($banks as $id => $a) {
    $cu = strtoupper(trim((string)$a['currency'])); $rows = (int)($onAcct[$id] ?? 0);
    if (((int)$a['active'] === 1 && in_array($cu, $curs, true)) || $rows === 0) continue;
    $n4a++; $n4r += $rows; $n4ids[] = (string)$id;
}
$f1 = []; $nc = [];
foreach ($n1 as $k => $v) {
    $f1[] = $k . ':' . $v['b'] . '/' . $v['o'] . '/' . $v['m'] . '/' . $v['x'];
    if ($k !== 'elsewhere') $nc[] = $k . ':' . ($v['b'] + $v['o'] + $v['m'] + $v['x']);
}
echo json_encode([
    'curs' => implode(',', $curs), 'listed' => $listedN,
    'n1' => implode(',', $f1), 'noncash' => implode(',', $nc),
    'n2' => 'crm:' . $n2['crm'] . ',btin:' . $n2['btin'] . ',btout:' . $n2['btout'] . ',charges:' . $n2['charges'],
    'n3' => 'blank:' . $n3['blank'] . ',cash:' . $n3['cash'],
    'n4' => 'accounts:' . $n4a . ',rows:' . $n4r, 'n4ids' => implode(',', $n4ids),
]), "\n";

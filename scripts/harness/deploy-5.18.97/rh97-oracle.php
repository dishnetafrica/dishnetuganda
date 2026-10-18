<?php
// 5.18.97's rehearsal: its own reading of what ECO Bank's checks read on the sandbox's live store — read-only (the
// connection's own query_only), plain SQL written from the design (SPEC-separate v2 §2.8.1, §2.8.2, §2.10b, and
// migrations/088_bank_statements.sql), no plugin code, never the script's. One line of JSON:
//   admins, nonadmins   the active staff as BankGate::allows() reads a fresh record: an id above 0, is_active, is_admin
//   curs                the book's currencies, from the settings row
//   offered, bn5        the offered bank accounts as offeredAccounts() is specified — active, kind 'bank', a book currency;
//                       the book's currency order, then id — per currency: offered, named Ecobank ("eco" starting a word,
//                       then "bank": the release's rule), named with a kept line; and the same in the deploy's words
//   one, both           every currency one account, named Ecobank (BN5's standard chart); every card a figure (CB)
//   list                per offered account, in that order: currency/named/kept/linked
//   ids, last           the offered accounts' row ids, and id:date of each one's last kept line — for the SHEET's own
//                       check alone; never compared with the terminal, which must carry neither
//   tables, rows        088's five tables present, and their row counts (- for a table that is not there)
//   triggers, trigmissing, ledger   088's eleven triggers present, those missing, and its ledger row
// Never a name, a number, an ending or an amount.
declare(strict_types=1);
$p = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15]);
$p->exec('PRAGMA query_only = ON');
$has = function (string $t) use ($p): bool {
    $q = $p->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
    $q->execute([$t]);
    return (int)$q->fetchColumn() === 1;
};
$yn = function (bool $b): string { return $b ? 'yes' : 'no'; };

$adm = 0; $non = 0;
if ($has('retailers')) {
    foreach ($p->query('SELECT data FROM retailers ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $r = json_decode((string)$d, true);
        if (!is_array($r) || (int)($r['id'] ?? 0) <= 0 || empty($r['is_active'])) continue;
        if (!empty($r['is_admin'])) $adm++; else $non++;
    }
}

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

$T = ['bank_account_profiles', 'bank_checks', 'bank_statements', 'bank_statement_lines', 'bank_line_events'];
$TRIG = ['bank_profiles_no_update', 'bank_profiles_no_delete', 'bank_checks_fixed', 'bank_checks_moves', 'bank_checks_no_delete',
         'bank_statements_fixed', 'bank_statements_no_delete', 'bank_lines_fixed', 'bank_lines_no_delete', 'bank_events_no_update',
         'bank_events_no_delete'];
$present = 0; $rows = [];
foreach ($T as $t) {
    if ($has($t)) { $present++; $rows[] = (string)(int)$p->query('SELECT COUNT(*) FROM [' . $t . ']')->fetchColumn(); }
    else $rows[] = '-';
}
$ready = $present === count($T);
$trig = [];
foreach ($p->query("SELECT name FROM sqlite_master WHERE type = 'trigger'")->fetchAll(PDO::FETCH_COLUMN) as $n) {
    if (in_array((string)$n, $TRIG, true)) $trig[] = (string)$n;
}
$led = 'absent';
if ($has('_migrations')) {
    $q = $p->prepare('SELECT checksum FROM _migrations WHERE filename = ?');
    $q->execute(['088_bank_statements.sql']);
    $c = $q->fetchColumn();
    if ($c !== false) $led = 'applied:' . (string)$c;
}

$off = [];
if ($has('cb_accounts')) {
    foreach ($p->query('SELECT id, name, currency, kind, active FROM cb_accounts')->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $c = strtoupper(trim((string)$a['currency']));
        if ((string)$a['kind'] !== 'bank' || (int)$a['active'] !== 1 || !in_array($c, $curs, true)) continue;
        $off[] = ['id' => (int)$a['id'], 'cur' => $c, 'named' => preg_match('/\beco[\s-]*bank/i', (string)$a['name']) === 1];
    }
}
usort($off, function (array $x, array $y) use ($curs): int {
    return [array_search($x['cur'], $curs, true), $x['id']] <=> [array_search($y['cur'], $curs, true), $y['id']];
});
$per = []; foreach ($curs as $c) $per[$c] = [0, 0, 0];
$list = []; $ids = []; $last = [];
foreach ($off as $a) {
    $date = ''; $linked = false;
    if ($ready) {
        $q = $p->prepare('SELECT posting_date FROM bank_statement_lines WHERE cb_account_id = ? ORDER BY cheque_number DESC LIMIT 1');
        $q->execute([$a['id']]);
        $d = $q->fetchColumn();
        $date = $d === false ? '' : (string)$d;
        $q = $p->prepare('SELECT COUNT(*) FROM bank_account_profiles WHERE cb_account_id = ?');
        $q->execute([$a['id']]);
        $linked = (int)$q->fetchColumn() > 0;
    }
    $kept = $date !== '';
    $per[$a['cur']][0]++;
    if ($a['named']) { $per[$a['cur']][1]++; if ($kept) $per[$a['cur']][2]++; }
    $list[] = $a['cur'] . '/' . $yn($a['named']) . '/' . $yn($kept) . '/' . $yn($linked);
    $ids[] = (string)$a['id'];
    if ($kept) $last[] = $a['id'] . ':' . $date;
}
$o = []; $say = []; $one = $per !== []; $both = true;
foreach ($per as $c => [$n, $e, $k]) {
    $o[] = "$c:$n:$e:$k";
    $say[] = sprintf('%s: %d offered, %d named Ecobank, %d of them with a statement kept', $c, $n, $e, $k);
    if ($n !== 1 || $e !== 1) $one = false;
    if ($e < 1 || $k < $e) $both = false;
}
echo json_encode([
    'admins' => $adm, 'nonadmins' => $non, 'curs' => implode(',', $curs),
    'offered' => implode(',', $o), 'bn5' => $say ? implode('; ', $say) : 'no bank account offered',
    'one' => $yn($one), 'both' => $yn($both), 'list' => implode(';', $list),
    'ids' => implode(',', $ids), 'last' => implode(',', $last),
    'tables' => $present . '/' . count($T), 'rows' => implode(':', $rows),
    'triggers' => count($trig) . '/' . count($TRIG), 'trigmissing' => ($m = array_values(array_diff($TRIG, $trig))) ? implode(',', $m) : '-',
    'ledger' => $led,
]), "\n";

<?php
// 5.18.99's rehearsal: its own reading of what the deploy script's quoting checks count on the book (SPEC-quote-company
// §6) — read-only (the connection's own query_only), no plugin code, never the script's probe. The store's collections
// are read as the store reads them (a table with a data column: its rows' JSON, oldest id first; a table of columns:
// its rows), and the design's counts taken from them:
//   php rh99-oracle.php <plugin.sqlite3> <the quoting organization's id, 0 for none> <file for the client ids>
// One line of JSON:
//   all, cap   how many distinct uCRM client ids the app holds (kyc_applications, leads, quotes_log — each newest first,
//              in that order) and how many of them Q-N2 reads (at most 300); the ids themselves, one a line, go to the
//              file named — never to standard output
//   src        how many distinct ids each collection holds: "apps:N,leads:N,quotes:N"
//   qg5        the last 30 days' Lead and Manual quotes with a uCRM client, and how many of them have a uCRM quote: "N:S"
//   qn4        KycCrmSync's queue by state, the pending ones at ten attempts and those whose saved request names another
//              organization than the quoting one: "pending:N,spent:N,otherorg:N,review:N,failed:N,synced:N"
//   qn5        crm_queue.json by status, "status:N,…" in order of the status, or "-"
// Counts and the ids file only: never a name, a phone or an amount.
declare(strict_types=1);
[$_, $db, $m, $idsFile] = $argv + [null, '', '0', ''];
$m = (int)$m;
$p = new PDO('sqlite:' . (string)$db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15]);
$p->exec('PRAGMA query_only = ON');
$load = function (string $t) use ($p): array {
    $cols = $p->query('PRAGMA table_info([' . $t . '])')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!$cols) return [];
    if (count($cols) > 2 && !in_array('data', $cols, true)) {
        $r = $p->query('SELECT * FROM [' . $t . '] ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter((array)$r, 'is_array'));
    }
    if (!in_array('data', $cols, true)) return [];
    $rows = $p->query('SELECT data FROM [' . $t . '] ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($rows as $r) { $d = json_decode((string)$r, true); if ($d) $out[] = $d; }
    return array_values(array_filter($out, 'is_array'));
};
// A uCRM id as the design reads one: a whole number above 0, or a string of digits that is one.
$pos = function ($v): int {
    if (is_int($v)) return $v > 0 ? $v : 0;
    if (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/', trim($v))) return (int)trim($v);
    return 0;
};
$newest = function (array $rows): array {
    usort($rows, function ($a, $b) { return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0); });
    return $rows;
};
$apps = $load('kyc_applications'); $leads = $load('leads'); $ql = $load('quotes_log'); $cq = $load('crm_queue');
$ids = []; $src = [];
foreach (['apps' => $apps, 'leads' => $leads, 'quotes' => $ql] as $k => $rows) {
    $seen = [];
    foreach ($newest($rows) as $r) { $c = $pos($r['crm_client_id'] ?? null); if ($c > 0) { $seen[$c] = true; $ids[$c] = true; } }
    $src[] = $k . ':' . count($seen);
}
$all = array_keys($ids); $cap = array_slice($all, 0, 300);
if ($idsFile !== '' && @file_put_contents($idsFile, $cap ? implode("\n", $cap) . "\n" : '') === false) { fwrite(STDERR, "the ids file could not be written\n"); exit(3); }
$since = time() - 30 * 86400; $n5 = 0; $s5 = 0;
foreach ($ql as $r) {
    if (!in_array((string)($r['type'] ?? ''), ['lead', 'manual'], true) || $pos($r['crm_client_id'] ?? null) <= 0) continue;
    $t = strtotime((string)($r['created_at'] ?? ''));
    if ($t === false || $t < $since) continue;
    $n5++;
    if ($pos($r['crm_quote_id'] ?? null) > 0) $s5++;
}
$kc = ['pending' => 0, 'spent' => 0, 'otherorg' => 0, 'review' => 0, 'failed' => 0, 'synced' => 0];
foreach ($apps as $r) {
    $st = (string)($r['crm_sync_status'] ?? '');
    if (!in_array($st, ['pending', 'review', 'failed', 'synced'], true)) continue;
    $kc[$st]++;
    if ($st !== 'pending') continue;
    if ((int)($r['crm_sync_attempts'] ?? 0) >= 10) $kc['spent']++;
    $pl = $r['crm_sync_payload'] ?? null;
    if (is_string($pl)) $pl = json_decode($pl, true);
    $po = is_array($pl) ? $pos($pl['organizationId'] ?? null) : 0;
    if ($m > 0 && $po > 0 && $po !== $m) $kc['otherorg']++;
}
$q5 = [];
foreach ($cq as $r) {
    $s = is_scalar($r['status'] ?? null) ? (string)preg_replace('/[^a-z_]/', '', strtolower((string)$r['status'])) : '';
    $s = $s === '' ? 'none' : $s;
    $q5[$s] = ($q5[$s] ?? 0) + 1;
}
ksort($q5); $q5s = [];
foreach ($q5 as $k => $v) $q5s[] = $k . ':' . $v;
$kcs = [];
foreach ($kc as $k => $v) $kcs[] = $k . ':' . $v;
echo json_encode(['all' => count($all), 'cap' => count($cap), 'src' => implode(',', $src), 'qg5' => $n5 . ':' . $s5,
                  'qn4' => implode(',', $kcs), 'qn5' => $q5s ? implode(',', $q5s) : '-']), "\n";

<?php
// 5.18.99's rehearsal: the app's own records of uCRM clients, added to the store through the INSTALLED plugin's own writer —
// SqliteStore::appendWithId(), as the KYC form, the lead capture, Send Quotation and the legacy queue add theirs —
// synthetic, every row marked "_rh99", every name and contact a marked secret of the rehearsal's (RH99SECRET-…):
//   php rh99-seed.php <plugin root> <data dir> <rows as JSON: {"kyc_applications.json": [...], "leads.json": [...], …}>
// The collections the deploy script's quoting checks read (SPEC-quote-company §6): the distinct crm_client_id of
// kyc_applications, leads and quotes_log (Q-N2), the last 30 days' Lead and Manual quotes (Q-G5), KycCrmSync's queue
// (Q-N4) and crm_queue.json (Q-N5). A created_at given as "rel:<strtotime phrase>" is written as that UTC time
// (Y-m-d H:i:s), so that "the last 30 days" holds whatever day the rehearsal runs. Prints how many rows were added as
// JSON, nothing else; never a row, a name or a number.
declare(strict_types=1);
[$_, $root, $data, $rows] = $argv + [null, '', '', ''];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
if (!is_file($root . '/lib/SqliteStore.php') || !is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no installed plugin or no store\n"); exit(2); }
$all = json_decode((string)$rows, true);
if (!is_array($all) || $all === []) { fwrite(STDERR, "refusing: no rows given\n"); exit(2); }
$files = ['kyc_applications.json', 'leads.json', 'quotes_log.json', 'crm_queue.json'];
foreach ($all as $file => $list) {
    if (!in_array($file, $files, true) || !is_array($list)) { fwrite(STDERR, "refusing: only the quoting checks' collections\n"); exit(2); }
    foreach ($list as $r) {
        if (!is_array($r) || ($r['_rh99'] ?? null) !== true) { fwrite(STDERR, "refusing: a rehearsal row carries \"_rh99\": true\n"); exit(2); }
    }
}
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore'] as $l) require_once $root . '/lib/' . $l . '.php';
$GLOBALS['dataDir'] = $data;   // as the plugin's own bootstrap sets it
$s = SqliteStore::create($data);
$n = 0;
try {
    foreach ($all as $file => $list) {
        foreach ($list as $r) {
            if (is_string($r['created_at'] ?? null) && strpos($r['created_at'], 'rel:') === 0) {
                $t = strtotime(substr($r['created_at'], 4));
                if ($t === false) { fwrite(STDERR, "refusing: a relative time strtotime cannot read\n"); exit(2); }
                $r['created_at'] = gmdate('Y-m-d H:i:s', $t);
            }
            $s->appendWithId($file, $r);
            $n++;
        }
    }
} catch (Throwable $e) { fwrite(STDERR, 'refused after ' . $n . ': ' . get_class($e) . "\n"); exit(3); }
echo json_encode(['rows' => $n]), "\n";

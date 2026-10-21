<?php
// 5.18.98's rehearsal: listed rows added to the book through the INSTALLED plugin's own writer —
// CashbookService::addEntryRaw(), as the plugin's own posting paths call it — synthetic, every description RH98:
//   php rh98-rows.php <plugin root> <data dir> <rows as a JSON list>
// The rows the design's notes N2 and N4 count (SPEC-separate v2 §2.10), so that the script's own SQL for them is shown
// to count something rather than 0 against 0: approved rows on no account that look like bank money (uCRM's online
// rows, from its webhook and from the gap-fill; a Bank Transfer in; a Bank Transfer out; a Bank Charges row), and a row
// on a bank account ECO Bank does not offer. Every amount the caller's, invented. Prints how many were added as JSON,
// nothing else; never a row, a name or an amount.
declare(strict_types=1);
[$_, $root, $data, $rows] = $argv + [null, '', '', ''];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
if (!is_file($root . '/lib/CashbookService.php') || !is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no installed plugin or no store\n"); exit(2); }
$list = json_decode((string)$rows, true);
if (!is_array($list) || $list === []) { fwrite(STDERR, "refusing: no rows given\n"); exit(2); }
foreach ($list as $r) {
    if (!is_array($r) || strpos((string)($r['description'] ?? ''), 'RH98 ') !== 0) { fwrite(STDERR, "refusing: a rehearsal row is described RH98 …\n"); exit(2); }
}
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'CashbookService'] as $l) require_once $root . '/lib/' . $l . '.php';
$GLOBALS['dataDir'] = $data;   // as the plugin's own bootstrap sets it
$s = SqliteStore::create($data);
$cb = new CashbookService($s, $data);
$n = 0;
try {
    foreach ($list as $r) { $cb->addEntryRaw($r); $n++; }
} catch (Throwable $e) { fwrite(STDERR, 'refused after ' . $n . ': ' . get_class($e) . "\n"); exit(3); }
echo json_encode(['rows' => $n]), "\n";

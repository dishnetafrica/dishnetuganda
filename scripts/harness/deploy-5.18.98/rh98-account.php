<?php
// 5.18.98's rehearsal: one more bank account in the book's chart of accounts, through the INSTALLED plugin's own writer —
// CashbookService::addAccount(), as the Accounts screen adds one — synthetic, every name RH98:
//   php rh98-account.php <plugin root> <data dir> <name> <currency>
// The design's G3 (SPEC-separate v2 §2.10 R3) is tried both ways with it: an account the book names Ecobank, offered on
// ECO Bank (active, a book currency), with no statement kept — a card that shows no figure, which G3 refuses (and
// --without-bank-balance turns into a note). Prints the new row's id as JSON, nothing else; the name is the caller's and
// is never printed back.
declare(strict_types=1);
[$_, $root, $data, $name, $cur] = $argv + [null, '', '', '', ''];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
if (!is_file($root . '/lib/CashbookService.php') || !is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no installed plugin or no store\n"); exit(2); }
if (strpos((string)$name, 'RH98 ') !== 0) { fwrite(STDERR, "refusing: a rehearsal account is named RH98 …\n"); exit(2); }
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'CashbookService'] as $l) require_once $root . '/lib/' . $l . '.php';
$GLOBALS['dataDir'] = $data;   // as the plugin's own bootstrap sets it
$s = SqliteStore::create($data);
$r = (new CashbookService($s, $data))->addAccount((string)$name, (string)$cur, 'bank');
if (empty($r['ok']) || (int)($r['id'] ?? 0) <= 0) { fwrite(STDERR, 'refused: ' . (string)($r['error'] ?? '?') . "\n"); exit(3); }
echo json_encode(['id' => (int)$r['id']]), "\n";

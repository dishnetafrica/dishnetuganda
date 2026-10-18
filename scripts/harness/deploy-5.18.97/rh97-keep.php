<?php
// 5.18.97's rehearsal: one synthetic Account Details statement kept on each of the book's two Ecobank accounts — what the
// operator does on ECO Bank once it is switched on (SPEC-separate v2 §1.8 item 2: each card's "Upload the Account Details
// CSV", check it, "Link and keep") — through the INSTALLED code's own BankStatementStore, check() then keep(…, link: true),
// as the active administrator's fresh record; no page is rendered. The files come from the release's own generator of
// synthetic statements (tests/fixtures/bank_statement_gen.php: every value invented — the holder "RH-Account Holder One",
// counterparties "RH-CLIENT n", accounts 0000000001 and 0000000002, made-up amounts from a fixed seed).
// Prints "<CUR>:<lines added>:linked|not-linked" per account. Every balance and amount of both files goes to AMOUNTS
// (0600), for the rehearsal's figure sweep alone; nothing of them is printed.
declare(strict_types=1);
[$_, $root, $data, $idsJson, $actor, $amounts] = $argv + [null, '', '', '{}', '0', ''];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
if (!is_file($root . '/lib/BankStatementStore.php') || !is_file($root . '/tests/fixtures/bank_statement_gen.php')) {
    fwrite(STDERR, "refusing: the installed plugin is not 5.18.97 (no BankStatementStore, or no generator)\n"); exit(2);
}
if ($amounts === '' || (int)$actor <= 0) { fwrite(STDERR, "refusing: no actor or no amounts file\n"); exit(2); }
foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'BankStatementStore'] as $l) require_once $root . '/lib/' . $l . '.php';
require_once $root . '/tests/fixtures/bank_statement_gen.php';
$GLOBALS['dataDir'] = $data;   // as the plugin's own bootstrap sets it
$ids = json_decode((string)$idsJson, true);
foreach (['bankU', 'bankD'] as $k) if (!is_int($ids[$k] ?? null) || $ids[$k] < 1) { fwrite(STDERR, "no id for $k\n"); exit(2); }
$s = SqliteStore::create($data);
$bs = new BankStatementStore($s->getPdo(), $data, ['UGX', 'USD']);
if (!$bs->ready()) { fwrite(STDERR, "refusing: 088 is not applied\n"); exit(2); }
$me = ['id' => (int)$actor];
$plan = [
    ['acct' => (int)$ids['bankU'], 'ccy' => 'UGX', 'no' => '0000000001', 'h' => g_history(40, 11, 0, 101, [2026, 7, 3])],
    ['acct' => (int)$ids['bankD'], 'ccy' => 'USD', 'no' => '0000000002', 'h' => g_history(30, 12, 0, 501, [2026, 7, 6])],
];
$out = []; $amts = [];
foreach ($plan as $x) {
    $no = $x['no'];
    $csv = g_file($x['h'], $x['ccy'], ['mutate' => function (array $L) use ($no): array {
        foreach ($L as &$l) $l['Account'] = $no;
        unset($l);
        return $L;
    }]);
    $c = $bs->check($x['acct'], $csv, $me);
    if (empty($c['ok'])) { fwrite(STDERR, 'check refused: ' . $x['ccy'] . ' ' . (string)($c['code'] ?? '?') . "\n"); exit(3); }
    $k = $bs->keep((int)$c['check_id'], $x['acct'], true, $me);
    if (empty($k['ok'])) { fwrite(STDERR, 'keep refused: ' . $x['ccy'] . ' ' . (string)($k['code'] ?? '?') . "\n"); exit(3); }
    $out[] = $x['ccy'] . ':' . (int)($k['lines_added'] ?? 0) . ':' . (!empty($k['linked']) ? 'linked' : 'not-linked');
    foreach ($x['h'] as $l) { $amts[] = g_money((int)$l['_run']); $amts[] = g_money((int)$l['_cents']); }
}
$old = umask(077);
$ok = file_put_contents($amounts, implode("\n", array_values(array_unique($amts))) . "\n");
umask($old);
if ($ok === false) { fwrite(STDERR, "the amounts file could not be written\n"); exit(3); }
@chmod($amounts, 0600);
echo implode(' ', $out), "\n";

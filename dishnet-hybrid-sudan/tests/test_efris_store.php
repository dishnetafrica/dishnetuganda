<?php
declare(strict_types=1);
/**
 * EfrisStore: schema, DB-level idempotency, whitelisted updates, filters.
 * Duplicate fiscalisation must be impossible at the database, not the UI.
 */
require_once dirname(__DIR__) . '/lib/EfrisStore.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$s = new EfrisStore($pdo);

echo "Claiming and idempotency\n";
[$row, $created] = $s->beginSubmission(101, 'INV-2026-00125', 'test',
    ['client_id' => 7, 'client_name' => 'Kampala Customer', 'amount' => 329000.0, 'currency' => 'UGX']);
t('first claim creates the row', $created, true);
t('starts PENDING', $row['status'], 'PENDING');
t('invoice number stored', $row['invoice_number'], 'INV-2026-00125');

[$row2, $created2] = $s->beginSubmission(101, 'INV-2026-00125', 'test');
t('second claim does NOT create', $created2, false);
t('same row comes back', (int)$row2['id'], (int)$row['id']);

$s->update((int)$row['id'], [
    'status' => 'FISCALISED', 'fdn' => 'TEST-FDN-000001',
    'verification_code' => 'TEST-VERIFICATION-000001',
    'qr_data' => 'TEST-QR|TEST-FDN-000001', 'fiscalised_at' => '2026-09-05 10:00:00',
]);
$r = $s->find(101);
t('fiscal values stored verbatim', $r['fdn'], 'TEST-FDN-000001');
t('status FISCALISED', $r['status'], 'FISCALISED');

$s->update((int)$row['id'], ['id' => 999999, 'created_at' => 'hacked', 'nonsense' => 'x', 'status' => 'FISCALISED']);
t('non-whitelisted columns are ignored', $s->get((int)$row['id'])['created_at'] !== 'hacked', true);

echo "\nKinds share the invoice without colliding\n";
[$cn, $cCreated] = $s->beginSubmission(101, 'CN-2026-00004', 'test', [], EfrisStore::KIND_CREDIT_NOTE);
t('credit note row is its own claim', $cCreated, true);
$s->update((int)$cn['id'], ['linked_invoice_id' => 101]);
t('credit note links to the original', (int)$s->find(101, EfrisStore::KIND_CREDIT_NOTE)['linked_invoice_id'], 101);

echo "\nListing and counters\n";
$s->beginSubmission(202, 'INV-2026-00126', 'test', ['client_name' => 'Entebbe Shop', 'amount' => 175000.0, 'currency' => 'UGX']);
$s->update((int)$s->find(202)['id'], ['status' => 'REJECTED', 'response_message' => 'TEST rejection']);
t('filter by status finds the rejection', count($s->recent(['status' => 'REJECTED'])), 1);
t('search by customer', $s->recent(['q' => 'Entebbe'])[0]['invoice_number'], 'INV-2026-00126');
$c = $s->counts();
t('counts total', $c['total'], 3);
t('counts by status FISCALISED', $c['by_status']['FISCALISED'], 1);
t('counts by kind credit_note', $c['by_kind']['credit_note'], 1);

$s->bumpRetry((int)$s->find(202)['id']);
t('retry counter increments', (int)$s->find(202)['retry_count'], 1);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);

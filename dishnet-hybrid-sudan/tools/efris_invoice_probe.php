<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * efris_invoice_probe.php — READ-ONLY: dump one real uCRM invoice exactly as
 * the API returns it, so the EFRIS mapper is frozen against reality instead
 * of documentation. Changes nothing anywhere.
 *
 * Run inside the ucrm container, from the plugin directory:
 *
 *   php tools/efris_invoice_probe.php latest      newest invoice
 *   php tools/efris_invoice_probe.php 123         a specific invoice id
 *
 * Prints: the raw invoice JSON, the raw client JSON (attributes included),
 * which tax shape each line item carries, and the field checklist the
 * Phase-1 approval asked for.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/error_handler.php';
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/EfrisInvoiceMapper.php';

$dataDir = getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$crm     = CrmApiClient::fromUcrm($root, $config);
if (!$crm->isConfigured()) exit("uCRM API not configured\n");

$arg = (string)($argv[1] ?? 'latest');
if ($arg === 'latest') {
    $list = $crm->get('invoices?limit=1&order=createdDate&direction=DESC') ?? [];
    $invoice = is_array($list) && isset($list[0]) ? $list[0] : null;
    if ($invoice && !empty($invoice['id'])) {
        // The list view can be abbreviated — always refetch the full record.
        $invoice = $crm->get('invoices/' . (int)$invoice['id']) ?? $invoice;
    }
} else {
    $invoice = $crm->get('invoices/' . (int)$arg) ?? $crm->get('billing/invoices/' . (int)$arg);
}
if (!is_array($invoice) || empty($invoice['id'])) exit("No invoice found for '{$arg}'\n");

$client = [];
if (!empty($invoice['clientId'])) {
    $client = $crm->get('clients/' . (int)$invoice['clientId']) ?? [];
}

echo "══ RAW INVOICE JSON (verbatim from uCRM) ══\n";
echo json_encode($invoice, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";
echo "══ RAW CLIENT JSON (verbatim, attributes included) ══\n";
echo json_encode($client, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n\n";

echo "══ FIELD CHECKLIST ══\n";
$f = function (string $label, $v): void {
    printf("  %-22s %s\n", $label, $v === null || $v === '' ? '(absent)' : json_encode($v, JSON_UNESCAPED_SLASHES));
};
$f('invoice number',  $invoice['number'] ?? null);
$f('created date',    $invoice['createdDate'] ?? null);
$f('due date',        $invoice['maturityDate'] ?? ($invoice['dueDate'] ?? null));
$f('currency',        $invoice['currencyCode'] ?? null);
$f('status',          $invoice['status'] ?? null);
$f('total',           $invoice['total'] ?? null);
$f('amountPaid',      $invoice['amountPaid'] ?? null);
$f('amountToPay',     $invoice['amountToPay'] ?? null);
$f('subtotal',        $invoice['subtotal'] ?? null);
$f('taxes (invoice)', $invoice['taxes'] ?? null);
$f('client company',  $client['companyName'] ?? null);
$f('client name',     trim((string)($client['firstName'] ?? '') . ' ' . (string)($client['lastName'] ?? '')));
$f('client attrs',    array_map(
    fn($a) => ($a['key'] ?? $a['name'] ?? '?') . '=' . ($a['value'] ?? ''),
    $client['attributes'] ?? []));

echo "\n══ PER-ITEM TAX SHAPE (what the mapper will read) ══\n";
$mapper = new EfrisInvoiceMapper($config);
$m = $mapper->map($invoice, $client);
foreach (($invoice['items'] ?? []) as $i => $it) {
    printf("  item %d: label=%s qty=%s price=%s total=%s\n",
        $i + 1, json_encode($it['label'] ?? $it['name'] ?? null),
        json_encode($it['quantity'] ?? null), json_encode($it['price'] ?? null),
        json_encode($it['total'] ?? null));
    printf("          tax keys present: %s\n",
        implode(', ', array_values(array_intersect(
            array_keys($it), ['taxes', 'tax', 'tax1', 'tax2', 'tax3', 'taxRate', 'taxAmount'])))
        ?: '(none)');
}
echo "\n  mapper detected shapes: " . implode(', ', $m['model']['meta']['tax_shapes'] ?? []) . "\n";
echo "  mapper verdict: " . ($m['ok'] ? 'OK' : 'BLOCKED') . "\n";
foreach ($m['errors']   as $e) echo "    ✗ {$e}\n";
foreach ($m['warnings'] as $w) echo "    ⚠ {$w}\n";
echo "\nPaste this whole output back for the mapping-freeze review.\n";

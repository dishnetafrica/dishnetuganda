<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * tax_probe.php — what does uCRM actually say about tax and one-time charges?
 *
 *   php tools/tax_probe.php
 *
 * READ-ONLY.
 *
 * Before the assistant can give a customer a TOTAL TO GET CONNECTED, three
 * things have to be known, and none of them can be read from the code:
 *
 *   1. What tax rates exist in uCRM, if any.
 *   2. Whether the catalogue prices ALREADY include tax. Getting this backwards
 *      is the expensive one — adding VAT to a VAT-inclusive price overcharges a
 *      customer by the rate, and assuming inclusive when it is exclusive
 *      undercharges DishNet by the same.
 *   3. Whether a UCC or other regulatory charge exists as a product at all.
 *      TBC_REGULATORY marks regulatory questions as having no approved answer,
 *      so if there is no such product, the assistant must say the quotation
 *      will confirm it rather than leaving it out as though the total were
 *      complete.
 *
 * The catalogue mapper currently drops every tax field — it keeps id, name,
 * price and unit — so this reads the RAW items to show what is arriving and
 * being discarded.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/CrmApiClient.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$crm     = CrmApiClient::fromUcrm($root, $config);
if (!$crm->isConfigured()) { echo "\n  uCRM is not configured for this plugin.\n\n"; exit(2); }

$get = function (string $path) use ($crm) {
    try { return $crm->get($path); } catch (\Throwable $e) { return ['__error' => $e->getMessage()]; }
};

echo "\n  1. TAX RATES IN uCRM\n\n";
$taxes = $get('taxes');
if (isset($taxes['__error'])) {
    echo "     could not read: " . $taxes['__error'] . "\n";
} elseif (!$taxes) {
    echo "     none defined\n";
    echo "     With no tax in uCRM the assistant has no rate to state, and must say the\n";
    echo "     quotation confirms the tax treatment rather than computing one.\n";
} else {
    foreach ((array)$taxes as $t) {
        if (!is_array($t)) continue;
        printf("     id %-4s %-28s %s%%%s\n", (string)($t['id'] ?? '?'),
               (string)($t['name'] ?? '(unnamed)'), (string)($t['rate'] ?? '?'),
               !empty($t['selected']) ? '   [default]' : '');
    }
}

echo "\n\n  2. DO CATALOGUE PRICES ALREADY INCLUDE TAX?\n\n";
// uCRM keeps this in system settings. The key name has moved between versions,
// so every candidate is looked for and the answer is reported as found or not
// found — never assumed, because both wrong answers cost real money.
$settings = $get('system/settings');
$found = null;
if (is_array($settings) && !isset($settings['__error'])) {
    foreach ($settings as $k => $v) {
        if (!is_string($k)) continue;
        if (stripos($k, 'pricingtax') !== false || stripos($k, 'priceswithtax') !== false
            || stripos($k, 'pricesincludetax') !== false || stripos($k, 'taxcoefficient') !== false) {
            $found[$k] = is_scalar($v) ? (string)$v : gettype($v);
        }
    }
}
if ($found) {
    foreach ($found as $k => $v) printf("     %-34s %s\n", $k, $v);
} else {
    echo "     NOT FOUND on the settings endpoint.\n";
    echo "     This must be confirmed from the uCRM billing settings screen before the\n";
    echo "     assistant states any tax amount. Until it is known, it must present the\n";
    echo "     sum of listed prices and say the quotation confirms the tax treatment.\n";
}

echo "\n\n  3. WHAT THE CATALOGUE CARRIES, AND WHAT IS DROPPED\n\n";
// mapServicePlan/mapHardwareItem keep id, name, price, unit. Anything below
// that is not in that list is reaching the plugin and being discarded.
$KEPT = ['id', 'name', 'price', 'unit', 'period', 'periods', 'downloadSpeed',
         'uploadSpeed', 'dataUsageLimit', 'organizationId'];
foreach (['products' => 'one-time items', 'service-plans' => 'monthly plans'] as $path => $label) {
    $rows = $get($path . '?limit=100');
    echo "     " . strtoupper($label) . "\n";
    if (isset($rows['__error'])) { echo "       could not read: " . $rows['__error'] . "\n\n"; continue; }
    if (!$rows) { echo "       none\n\n"; continue; }

    $taxKeys = [];
    foreach ((array)$rows as $r) {
        if (!is_array($r)) continue;
        foreach ($r as $k => $v) {
            if (stripos((string)$k, 'tax') !== false) $taxKeys[$k] = true;
        }
    }
    printf("       %-34s %s\n", 'tax-related keys returned',
           $taxKeys ? implode(', ', array_keys($taxKeys)) : 'NONE');
    if ($taxKeys) {
        echo "       ↑ these are arriving and being DROPPED — the mapper keeps only\n";
        echo "         id, name, price and unit, so the assistant never sees them.\n";
    }

    foreach ((array)$rows as $r) {
        if (!is_array($r)) continue;
        $tax = [];
        foreach ($taxKeys as $k => $_) {
            $v = $r[$k] ?? null;
            if ($v !== null && $v !== '') $tax[] = $k . '=' . (is_scalar($v) ? $v : gettype($v));
        }
        printf("       %-34s %-12s %s\n", mb_substr((string)($r['name'] ?? '?'), 0, 34),
               isset($r['price']) ? (string)$r['price'] : '—',
               $tax ? implode(' ', $tax) : 'no tax set');
    }
    echo "\n";
}

echo "  4. IS THERE A REGULATORY / UCC CHARGE AT ALL?\n\n";
$prods = $get('products?limit=200');
$hits  = [];
if (is_array($prods) && !isset($prods['__error'])) {
    foreach ($prods as $r) {
        if (!is_array($r)) continue;
        $n = mb_strtolower((string)($r['name'] ?? ''));
        foreach (['ucc', 'regulat', 'licence', 'license', 'levy', 'duty', 'statutory'] as $w) {
            if (strpos($n, $w) !== false) { $hits[] = (string)$r['name']; break; }
        }
    }
}
if ($hits) {
    foreach ($hits as $h) echo "     " . $h . "\n";
} else {
    echo "     No product in uCRM looks like a regulatory or UCC charge.\n";
    echo "     So it is not a line the assistant can quote. It must be named as still\n";
    echo "     to be confirmed rather than silently omitted from a total — a total that\n";
    echo "     leaves out a charge reads as complete and is not.\n";
}
echo "\n";
exit(0);

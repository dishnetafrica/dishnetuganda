<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * org_probe.php — which uCRM organization is DishNet Uganda, and what does it hold?
 *
 *   php tools/org_probe.php
 *
 * READ-ONLY. It issues GETs and writes nothing.
 *
 * Quotation branding is about to be sourced from uCRM instead of from plugin
 * config, and "read the organization" is ambiguous here: FtthCrmService
 * documents two (Org 2 DishNet Africa Limited, Org 7 FTTH Project) while the
 * admin screen shows DishNet Africa Limited at id 1. Taking organizations[0]
 * would be a guess, and guessing which company's phone number goes on a
 * customer's quotation is exactly the class of mistake this is meant to end.
 *
 * So this prints every organization, the fields a quotation would use, and
 * which organization the clients actually belong to — because a quote is for a
 * client, and the client's own organizationId is the answer that needs no guess.
 *
 * Company details (name, address, TIN, phone) are printed: they appear on every
 * invoice this business issues. No client data, no keys, no secrets.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/CrmApiClient.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);

$base = trim((string)($config['crm_base_url'] ?? ''));
$key  = trim((string)($config['crm_app_key']  ?? ''));
if ($base === '' || $key === '') { echo "\n  uCRM is not configured in this install.\n\n"; exit(2); }
$crm = new CrmApiClient($base, $key);

$orgs = null;
try { $orgs = $crm->get('organizations'); }
catch (\Throwable $e) { echo "\n  Could not read organizations: " . $e->getMessage() . "\n\n"; exit(2); }
if (!is_array($orgs) || !$orgs) { echo "\n  uCRM returned no organizations.\n\n"; exit(2); }

// The fields a quotation would want. Printed whether or not they are populated,
// because an EMPTY one is the finding — it is what falls through to the
// compiled South Sudan default.
$WANT = ['name', 'phone', 'email', 'website', 'street1', 'street2', 'city',
         'zipCode', 'countryId', 'registrationNumber', 'taxId', 'currencyCode'];

echo "\n  ORGANIZATIONS IN THIS uCRM\n";
foreach ($orgs as $o) {
    if (!is_array($o)) continue;
    printf("\n  ── id %s   %s%s\n", (string)($o['id'] ?? '?'), (string)($o['name'] ?? '(unnamed)'),
           !empty($o['selected']) ? '   [selected/default]' : '');
    foreach ($WANT as $f) {
        if (!array_key_exists($f, $o)) continue;
        $v = $o[$f];
        $shown = ($v === null || $v === '') ? '— empty' : (is_scalar($v) ? (string)$v : gettype($v));
        printf("       %-20s %s\n", $f, $shown);
    }
    // Anything uCRM returns that is not in the list above, by name only, so a
    // field we could use is not invisible just because nobody thought of it.
    $extra = array_diff(array_keys($o), $WANT);
    if ($extra) printf("       %-20s %s\n", 'other keys', implode(', ', $extra));
}

// Which organization do clients actually belong to? A quote is issued for a
// client, so the client's own organizationId decides — no guessing required.
echo "\n\n  WHICH ORGANIZATION DO CLIENTS BELONG TO?\n\n";
try {
    $clients = $crm->get('clients?limit=50') ?? [];
    $byOrg = [];
    foreach ($clients as $c) {
        if (!is_array($c)) continue;
        $oid = (string)($c['organizationId'] ?? 'none');
        $byOrg[$oid] = ($byOrg[$oid] ?? 0) + 1;
    }
    if (!$byOrg) {
        echo "     no clients returned\n";
    } else {
        arsort($byOrg);
        $names = [];
        foreach ($orgs as $o) if (is_array($o)) $names[(string)($o['id'] ?? '')] = (string)($o['name'] ?? '');
        foreach ($byOrg as $oid => $n) {
            printf("     org %-4s %-32s %d of %d sampled client(s)\n",
                   $oid, $names[$oid] ?? '(unknown)', $n, count($clients));
        }
        echo "\n     A quote is issued for a client, so the client's organizationId is what\n";
        echo "     decides whose details go on it. That is the primary lookup.\n";
    }
} catch (\Throwable $e) {
    echo "     could not read clients: " . $e->getMessage() . "\n";
}

// What the quote would print TODAY, before any change.
echo "\n\n  WHAT A QUOTATION PRINTS TODAY\n\n";
$today = [
    'company name' => [$config['quote_company_name']  ?? null, 'DishNet Africa'],
    'phone'        => [$config['quote_company_phone'] ?? null, '+211920000000'],
    'email'        => [$config['quote_company_email'] ?? null, 'info@dishnetafrica.com'],
];
$juba = false;
foreach ($today as $label => [$set, $fallback]) {
    $eff = ($set === null || $set === '') ? $fallback : (string)$set;
    $src = ($set === null || $set === '') ? 'compiled default' : 'plugin config';
    printf("     %-14s %-34s (%s)\n", $label, $eff, $src);
    if (strpos(preg_replace('/\D+/', '', $eff) ?? '', '211') === 0) $juba = true;
}
if ($juba) {
    echo "\n     A +211 number is printed on quotations sent to Ugandan customers.\n";
    exit(1);
}
echo "\n";
exit(0);

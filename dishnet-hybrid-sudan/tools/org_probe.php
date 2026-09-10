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

// fromUcrm() is how every other caller builds this: a manual crm_base_url +
// crm_auth_token pair if set, otherwise uCRM's own injected ucrm.json. The
// first version of this tool read crm_base_url and crm_app_key directly and
// reported "not configured" on an install that has been talking to uCRM all
// day — the keys were guessed rather than looked up, which is the same class
// of mistake as guessing an organization id.
$crm = CrmApiClient::fromUcrm($root, $config);
if (!$crm->isConfigured()) {
    echo "\n  uCRM is not configured for this plugin.\n";
    echo "  Checked: crm_base_url + crm_auth_token in config, then ucrm.json\n";
    echo "  (ucrmLocalUrl / ucrmPublicUrl + pluginAppKey) at:\n";
    echo "      " . $root . "/ucrm.json\n";
    echo "      " . $root . "/data/ucrm.json\n\n";
    exit(2);
}

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

// What a quotation prints — asked of the code that decides it.
//
// This section used to reimplement the lookup: read the config keys, fall back
// to the constants, print a verdict. It was therefore reporting the OLD rules
// for a full deploy after QuotationService started reading uCRM, and said
// "plugin config" while the live path was already answering "uCRM". A
// diagnostic that re-derives what it is meant to observe tells you about
// itself, not about the system.
echo "\n\n  WHAT A QUOTATION PRINTS\n\n";
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/NotificationService.php';
require_once $root . '/lib/QuotationService.php';

$LABELS = ['ucrm' => 'uCRM organization', 'config' => 'plugin config',
           'constant' => 'BUILT-IN DEFAULT'];
try {
    $store = SqliteStore::create($dataDir);
    $qs    = new QuotationService($store, $dataDir, $config);
    $co    = $qs->companyDetails();          // no client: the selected organization

    foreach (['name' => 'company name', 'phone' => 'phone', 'email' => 'email',
              'address' => 'address', 'website' => 'website', 'tax_id' => 'tax id',
              'registration_number' => 'registration no', 'bank_name' => 'bank account',
              'bank_1' => 'account field 1', 'bank_2' => 'account field 2'] as $k => $label) {
        $v = (string)($co[$k] ?? '');
        if ($v === '' && !isset($co['_source'][$k])) continue;   // absent, not printed
        printf("     %-17s %-36s (%s)\n", $label, $v,
               $LABELS[$co['_source'][$k] ?? 'constant'] ?? '?');
    }

    if (!empty($co['_warnings'])) {
        echo "\n     FELL THROUGH TO A BUILT-IN DEFAULT:\n";
        foreach ($co['_warnings'] as $w) echo "       - " . $w . "\n";
        echo "\n     These are South Sudan's. Set the field on the uCRM organization,\n";
        echo "     or as a plugin config key, so a customer is not told to call Juba.\n\n";
        exit(1);
    }
    echo "\n     Nothing is falling back to a built-in default.\n\n";
} catch (\Throwable $e) {
    echo "     could not resolve: " . $e->getMessage() . "\n\n";
    exit(2);
}
exit(0);

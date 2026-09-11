<?php
/**
 * test_quote_branding.php — whose phone number is on the quotation.
 *
 * QuotationService compiles three South Sudan constants. An unset config key
 * did not mean blank: it meant +211920000000 printed on a Ugandan customer's
 * quote as the number to call, and that was live until it was noticed by an
 * audit tool rather than by a customer.
 *
 * uCRM held the right answer the whole time — organization 1 on this install
 * carries DishNet Africa Limited, +256705993348, the Kampala address, the URA
 * TIN and the bank details. Reading it removes the duplicate rather than
 * patching over it.
 *
 * Two properties are what make that safe, and both are asserted here.
 *
 * WHICH organization is never a guess. A quote is issued for a client, and a
 * client carries organizationId. The two_orgs scenario deliberately returns
 * the wrong company FIRST, so anything reaching for organizations[0] fails.
 *
 * The fallback is PER FIELD. uCRM holding a name but no phone must not drag
 * the name back down to the constant with it.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

$tmp = sys_get_temp_dir() . '/dn_qbrand_' . bin2hex(random_bytes(4));
@mkdir($tmp . '/data', 0777, true);
// QuotationService derives the plugin root from the data dir by looking for
// manifest.json, so the temp tree needs one.
file_put_contents($tmp . '/manifest.json', '{}');

// ── Fake uCRM ───────────────────────────────────────────────────────────────
$router = $root . '/tests/fixtures/fake_ucrm_server.php';
$hit = function (int $port, string $p) {
    $ch = curl_init("http://127.0.0.1:{$port}{$p}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9800 + ((getmypid() + $slot * 13) % 70);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $hit($cand, '/__test/state');
        if ($got !== null) { $ours = strpos($got, 'FAKE-UCRM-TEST') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    proc_terminate($p); proc_close($p);
}
if ($port === 0) { fwrite(STDERR, "could not start the fake uCRM server\n"); exit(1); }
$scenario = function (string $name) use ($hit, $port) { $hit($port, '/__test/scenario?name=' . $name); };

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/NotificationService.php';
require_once $root . '/lib/QuotationService.php';

putenv('DN_DATA_DIR=' . $tmp . '/data');
$store = SqliteStore::create($tmp . '/data');

// crm_base_url + crm_auth_token is the manual pair fromUcrm() honours first.
$base = ['crm_base_url' => "http://127.0.0.1:{$port}", 'crm_auth_token' => 'TESTKEY'];
$svc  = fn(array $extra = []) => new QuotationService($store, $tmp . '/data', $base + $extra);

echo "\nuCRM is the source, and config does not override it\n";
$scenario('uganda');
$co = $svc(['quote_company_phone' => '+000000000000'])->companyDetails(9);
is_($co['phone'] === '+256705993348', 'the phone comes from uCRM', $co['phone']);
is_($co['_source']['phone'] === 'ucrm', 'and is recorded as such');
is_($co['name'] === 'DishNet Africa Limited', 'so does the name', $co['name']);
is_($co['email'] === 'accounts@dishnetuganda.com', 'and the email');

echo "\nThe fields nothing used to read come through too\n";
// Payment instructions and a TIN belong on a quotation and were sitting in
// uCRM unused while the plugin printed a South Sudan phone number.
is_($co['address'] === 'The Accacia Mall Office No TT06, Kampala',
    'the address is assembled, empty parts skipped', $co['address']);
is_($co['tax_id'] === 'TIN-TEST-0001', 'the tax id');
is_($co['bank_name'] === 'DISHNET AFRICA LIMITED', 'the bank account name');
is_($co['bank_1'] === 'TEST-UGX-0000000001', 'and the account number');
is_($co['logo_url'] !== '', 'and the logo');
is_(!$co['_warnings'], 'nothing warned when uCRM answers', implode(' | ', $co['_warnings']));

echo "\nWhich organization is decided by the client, never by list order\n";
// two_orgs returns the WRONG company first on purpose.
$scenario('two_orgs');
$ug = $svc()->companyDetails(9);
is_($ug['phone'] === '+256705993348', 'client 9 gets the Uganda organization',
    'organizations[0] is the other company — ' . $ug['phone']);
$other = $svc()->companyDetails(8);
is_($other['name'] === 'FTTH Project', 'and client 8 gets its own', $other['name']);

echo "\nWith no client, the organization uCRM marks selected is used\n";
$sel = $svc()->companyDetails(null);
is_($sel['phone'] === '+256705993348', 'not the first in the list', $sel['phone']);

echo "\nThe fallback is per field, not per source\n";
// uCRM has the address but no name and no phone. The address must still come
// from uCRM while those two fall independently to config.
$scenario('no_phone');
$mixed = $svc(['quote_company_name' => 'Configured Name',
               'quote_company_phone' => '+256700000000'])->companyDetails(9);
is_($mixed['phone'] === '+256700000000', 'the missing phone falls to config');
is_($mixed['_source']['phone'] === 'config', 'and says so');
is_($mixed['name'] === 'Configured Name', 'so does the missing name');
is_($mixed['email'] === 'accounts@dishnetuganda.com',
    'while the email uCRM DOES hold still comes from uCRM', $mixed['email']);
is_($mixed['_source']['email'] === 'ucrm', 'independently of the two that fell');
is_($mixed['address'] !== '', 'and the address survives too');

echo "\nReaching the compiled constant is recorded, never silent\n";
$scenario('no_org');
$fell = $svc()->companyDetails(null);
is_($fell['phone'] === '+211920000000', 'with nothing anywhere, the constant is what is left');
is_($fell['_source']['phone'] === 'constant', 'and the source says constant');
is_(count($fell['_warnings']) === 3, 'each field that fell through warns',
    implode(' | ', $fell['_warnings']));
is_(strpos(implode(' ', $fell['_warnings']), '+211920000000') !== false,
    'and the warning names the value that would be printed');

echo "\nuCRM being unreachable falls to config, not to Juba\n";
// The failure that matters: a network blip must not quietly restore the old
// South Sudan details on a document going to a customer.
$scenario('unreachable');
$down = $svc(['quote_company_phone' => '+256705993348',
              'quote_company_name'  => 'DishNet Africa Limited'])->companyDetails(9);
is_($down['phone'] === '+256705993348', 'the configured phone is used', $down['phone']);
is_($down['_source']['phone'] === 'config', 'sourced from config');
is_(strpos($down['phone'], '+211') === false, 'and no +211 number is produced');

echo "\nWith uCRM down AND nothing configured, it is a loud fallback\n";
$bare = $svc()->companyDetails(9);
is_($bare['_source']['phone'] === 'constant', 'the constant is reached');
is_(!empty($bare['_warnings']), 'and it is warned about, not printed quietly');

echo "\nWhen uCRM is not the source, it says why\n";
// The failure that cost a deploy to diagnose: QuotationService derived the
// plugin root by walking up from the data directory, which stopped being
// inside the plugin when bootstrap_data.php moved it out to survive upgrades.
// fromUcrm() then found no ucrm.json, every call failed, and branding fell to
// config with nothing anywhere saying so.
$scenario('unreachable');
$why = $svc(['quote_company_phone' => '+256705993348'])->companyDetails(9);
is_(!empty($why['_org_error']), 'a reason is recorded', 'silence is what made this expensive');
is_($why['_source']['phone'] === 'config', 'and the source is honest about it');

$scenario('uganda');
$fine = $svc()->companyDetails(9);
is_($fine['_org_error'] === '', 'and it is empty when uCRM did answer',
    'got: ' . $fine['_org_error']);

echo "\nThe plugin root is not guessed from the data directory\n";
// lib/ is inside the plugin root by definition. Walking up from $dataDir is
// not, and has not been since the data directory moved.
$qsrc = (string)file_get_contents($root . '/lib/QuotationService.php');
is_(strpos($qsrc, 'CrmApiClient::fromUcrm(dirname(__DIR__)') !== false,
    'the root comes from this file\'s own location');
is_(strpos($qsrc, '$pluginRoot = dirname($dataDir)') === false,
    'and never from walking up out of the data directory',
    'that walk lands outside the plugin whenever data lives beside it');

echo "\nThe probe reports this by asking, not by re-deriving it\n";
// org_probe.php used to reimplement the lookup — read the config keys, fall
// back to the constants, print a verdict. It therefore kept reporting the old
// rules for a whole deploy after QuotationService started reading uCRM, and
// said "plugin config" while the live path already answered "uCRM". A
// diagnostic that re-derives what it observes describes itself, not the system.
$probe = (string)file_get_contents($root . '/tools/org_probe.php');
is_(strpos($probe, 'companyDetails(') !== false,
    'it calls the method that actually decides');
is_(strpos($probe, '+211920000000') === false,
    'and carries no copy of the South Sudan constant',
    'a second copy of the fallback is a second answer waiting to disagree');
is_(strpos($probe, "_source") !== false,
    'and prints the source the resolver reports, per field');

if ($srv) { proc_terminate($srv); proc_close($srv); }
exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

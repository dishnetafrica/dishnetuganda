<?php
declare(strict_types=1);
/**
 * test_dpo_review_link.php — 5.18.32, getting DPO Pay ready for DPO's review.
 *
 * DPO's Uganda office sent test credentials on 25 September 2026 and asked for
 * a test link their team can pay through before they issue live credentials.
 * Checking the build against their instructions found five things, each
 * proved here:
 *
 *   A  their endpoint and checkout page: API/v6 for both calls, payv3.php —
 *      and the answer their verifyToken V6 documentation shows (no CompanyRef)
 *      still settles
 *   B  with a TEST token switched on, every customer saw Pay Now, and a payment
 *      made with DPO's published test cards would have been posted to uCRM
 *      against a real invoice. Now only named test customers can pay in test,
 *      and a test payment for anyone else is quarantined, never posted
 *   C  the portal draws Pay Now by the same rule
 *   D  the test link: a page DPO's reviewer can open without signing in, in the
 *      test environment only, with the key from the admin screen — driven
 *      through php -S with the real page, the real return page, a fake DPO and
 *      a fake uCRM, from Pay to "Payment successful"
 *   E  tools/dpo_probe.php, which asks DPO whether the saved token takes UGX
 *      before anyone is sent the link
 *
 * Nothing here reaches the real DPO: every client is pointed at the fake, and
 * the only seam that allows it (DN_DPO_FAKE_URL) is itself tested to refuse
 * anything but a loopback address in the test environment.
 */

$root = dirname(__DIR__);
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/DpoClient.php';
require_once $root . '/lib/DpoPaymentStore.php';
require_once $root . '/lib/DpoPaymentService.php';

$pass = 0; $fail = 0;
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$tmp = sys_get_temp_dir() . '/dn_dpo_review_' . getmypid();
exec('rm -rf ' . escapeshellarg($tmp));
@mkdir($tmp, 0777, true);
// Every vault read in this process and its children goes to an empty vault of
// our own — never the install's.
putenv('DN_VAULT_FILE=' . $tmp . '/vault.json');
putenv('DN_PLUGIN_ROOT=' . $root);
require_once $root . '/lib/DpoBootstrap.php';

$procs = [];
register_shutdown_function(function () use (&$procs, $tmp) {
    foreach ($procs as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    exec('rm -rf ' . escapeshellarg($tmp));
});

// ── the fakes ──────────────────────────────────────────────────────────────
function boot(string $router, int $base, string $sig, array $env = []): array {
    global $procs;
    $probe = function (int $port): ?string {
        $ch = curl_init("http://127.0.0.1:{$port}/");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '', CURLOPT_POSTFIELDS => '']);
        $r = curl_exec($ch); curl_close($ch);
        return $r === false ? null : (string)$r;
    };
    foreach (range(0, 9) as $slot) {
        $cand = $base + ((getmypid() + $slot * 13) % 70);
        if ($probe($cand) !== null) continue;                            // somebody else's
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                       [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
                       $env === [] ? null : $env + getenv());
        for ($i = 0; $i < 40; $i++) {
            $got = $probe($cand);
            if ($got !== null) {
                if ($sig === '' || strpos($got, $sig) !== false) { $procs[] = $p; return [$p, $cand]; }
                break;
            }
            usleep(100000);
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
}
$dpoFixture = $root . '/tests/fixtures/fake_dpo_server.php';
$crmFixture = $root . '/tests/fixtures/fake_ucrm_server.php';
[$dpoSrv, $dpoPort] = boot($dpoFixture, 9700, 'TEST-SERVER-SIGNATURE');
[$crmSrv, $crmPort] = boot($crmFixture, 9800, 'FAKE-UCRM-TEST');
if ($dpoSrv === null || $crmSrv === null) { echo "  FAIL the fake DPO and the fake uCRM started\n"; exit(1); }
$dpoState = sys_get_temp_dir() . '/fake_dpo_state_' . md5($dpoFixture . $dpoPort) . '.json';
$crmState = sys_get_temp_dir() . '/fake_ucrm_' . md5($crmFixture . $crmPort) . '.json';
@unlink($dpoState); @unlink($crmState);
register_shutdown_function(function () use ($dpoState, $crmState) { @unlink($dpoState); @unlink($crmState); });

$dpoBase = "http://127.0.0.1:{$dpoPort}/";
$crmBase = "http://127.0.0.1:{$crmPort}";
$METHOD  = 'aaaa1111-dpo-pay-method-uuid';

function http(string $url, ?array $post = null, int $timeout = 60): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => $timeout,
                            CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, strtolower(substr($raw, 0, $hlen)), substr($raw, $hlen), substr($raw, 0, $hlen)];
}
function crmCall(string $path): array {
    global $crmBase;
    $ch = curl_init($crmBase . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 5]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode((string)$r, true) ?: [];
}
function paymentCount(): int { return (int)(crmCall('/__test/payments')['count'] ?? -1); }
function dpoCalls(): int { global $dpoState; return (int)((json_decode((string)@file_get_contents($dpoState), true) ?: [])['calls'] ?? 0); }
crmCall('/__test/scenario?name=uganda'); crmCall('/__test/reset');

/** A service on a fresh database (or the one given), the fakes behind it. */
function svc(array $cfgOver = [], ?DpoPaymentStore $store = null): array {
    global $crmBase, $dpoBase, $METHOD;
    if ($store === null) {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec((string)file_get_contents(dirname(__DIR__) . '/migrations/071_dpo_payments.sql'));
        $store = new DpoPaymentStore($db);
    }
    $dpo   = new DpoClient(['company_token' => 'TESTTOKEN-0001', 'service_type' => '3854',
                            'api_create' => $dpoBase, 'api_verify' => $dpoBase, 'timeout' => 5]);
    $cfg   = array_merge(['dpo_enabled' => true, 'dpo_environment' => 'test',
        'dpo_payment_method_uuid' => $METHOD, 'dpo_ptl' => 30, 'dpo_currencies' => ['UGX'],
        'dpo_test_clients' => [12], 'dpo_return_url' => 'https://example.test/return',
        'dpo_back_url' => 'https://example.test/back'], $cfgOver);
    return [new DpoPaymentService($store, $dpo, new CrmApiClient($crmBase, 'test-key'), $cfg), $store];
}

// ── A. DPO's endpoint, checkout page and answer ────────────────────────────
echo "\nA. The endpoint and checkout page DPO named, and the answer they document\n";
t('createToken: API/v6', DpoClient::API_CREATE, 'https://secure.3gdirectpay.com/API/v6/');
t('verifyToken: the same API/v6, as DPO instructed (their class used v7)', DpoClient::API_VERIFY, 'https://secure.3gdirectpay.com/API/v6/');
t('the checkout page: payv3.php (their class used payv2.php)', DpoClient::PAY_URL, 'https://secure.3gdirectpay.com/payv3.php');
is_((new DpoClient(['company_token' => 'x', 'service_type' => 'y']))->checkoutUrl('TOK-1') === 'https://secure.3gdirectpay.com/payv3.php?ID=TOK-1',
    'the customer is sent to payv3.php?ID=<token>, the structure DPO gave');
// The V6 answer carries the figures and CustomerCreditType, and no CompanyRef.
crmCall('/__test/reset');
[$s, $st] = svc();
$i = $s->initiate(12, 140);
is_($i['ok'], 'a test customer starts a payment on the test invoice', json_encode($i));
$direct = new DpoClient(['company_token' => 'TESTTOKEN-0001', 'service_type' => '3854',
                         'api_create' => $dpoBase, 'api_verify' => $dpoBase, 'timeout' => 5]);
$tok = $direct->createToken(['reference' => 'DPO-140-12-V6ANS', 'amount' => 1000.0, 'currency' => 'UGX', 'description' => 'x']);
$st->update($i['reference'], ['dpo_trans_token' => $tok['trans_token']]);
$v6 = $direct->verifyToken($tok['trans_token']);
is_($v6['reference'] === '' && $v6['amount'] === 1000.0 && $v6['currency'] === 'UGX' && $v6['method'] === 'Mobile',
    'the V6-shaped answer: figures and credit type read, no CompanyRef', json_encode($v6));
$r = $s->verifyAndSettle($i['reference']);
t('and a V6-shaped paid answer settles — a missing CompanyRef is not a mismatch', $r['status'], 'SUCCESS');
t('one payment in uCRM', paymentCount(), 1);

// ── B. the test environment is for test customers ──────────────────────────
echo "\nB. In the test environment only test customers can pay\n";
crmCall('/__test/reset');
$callsBefore = dpoCalls();
[$s, $st] = svc();
$them = $s->initiate(7, 125);
t('a customer who is not a test customer is refused', $them['code'], 'TESTONLY');
t('in words that promise nothing', $them['error'], 'Online payment is not available yet.');
t('before anything was sent to DPO', dpoCalls(), $callsBefore);
t('and before an attempt was written', $st->openForInvoice(125), null);
[$s] = svc(['dpo_environment' => 'live']);
is_($s->initiate(7, 125)['ok'], 'live, the same customer pays as always (the control)');
[$s] = svc(['dpo_test_clients' => []]);
t('with no test customer named, nobody can pay in test', $s->initiate(12, 140)['code'], 'TESTONLY');

echo "\nA test payment for anyone else is quarantined, never posted to uCRM\n";
crmCall('/__test/reset');
[$s, $st] = svc(['dpo_test_clients' => [7, 12]]);
$was = $s->initiate(7, 125);                        // 7 was a test customer when it started
is_($was['ok'], 'a payment started while client 7 was a test customer');
[$s2] = svc(['dpo_test_clients' => [12]], $st);     // …and is not one by the time it settles
$q = $s2->verifyAndSettle($was['reference']);
t('it is quarantined', $q['status'], 'QUARANTINED');
t('and nothing reached uCRM', paymentCount(), 0);
is_(strpos((string)$st->byReference($was['reference'])['failure_reason'], 'not a test customer') !== false,
    'and the reason says why', (string)$st->byReference($was['reference'])['failure_reason']);
$ok = $s->verifyAndSettle($s->initiate(12, 140)['reference']);
t('a test customer\'s payment settles (the control)', $ok['status'], 'SUCCESS');
t('one payment in uCRM', paymentCount(), 1);
crmCall('/__test/reset');
[$sl, $stl] = svc(['dpo_environment' => 'live', 'dpo_test_clients' => []]);
t('a LIVE payment needs no test customer', $sl->verifyAndSettle($sl->initiate(7, 125)['reference'])['status'], 'SUCCESS');

// ── C. the portal's Pay Now ────────────────────────────────────────────────
echo "\nC. The portal draws Pay Now by the same rule\n";
$ready = ['dpo_enabled' => '1', 'dpo_company_token' => 'TESTTOKEN-0001', 'dpo_service_type' => '3854',
          'dpo_payment_method_uuid' => $METHOD, 'dpo_currencies' => 'UGX'];
is_(!DpoBootstrap::payNowFor($ready + ['dpo_environment' => 'test', 'dpo_test_clients' => '12'], 7),
    'test: not for a customer who is not a test customer');
is_(DpoBootstrap::payNowFor($ready + ['dpo_environment' => 'test', 'dpo_test_clients' => '12, 40'], 12),
    'test: for a test customer');
is_(!DpoBootstrap::payNowFor($ready + ['dpo_environment' => 'test'], 12), 'test, nobody named: for nobody');
is_(DpoBootstrap::payNowFor($ready + ['dpo_environment' => 'live'], 7), 'live: for every customer');
is_(!DpoBootstrap::payNowFor(['dpo_enabled' => '0'] + $ready + ['dpo_environment' => 'live'], 7), 'switched off: for nobody');
is_(!DpoBootstrap::payNowFor(['dpo_currencies' => ''] + $ready + ['dpo_environment' => 'live'], 7), 'not ready: for nobody');
t('test customers parse the way a person types them', DpoBootstrap::testClients(['dpo_test_clients' => ' 12, 40 ,12 abc 0']), [12, 40]);
$pd = (string)file_get_contents($root . '/tabs/customer_app/portal_data.php');
is_(strpos($pd, 'DpoBootstrap::payNowFor($config, (int)$portalCustomerId)') !== false,
    'and the portal asks it, for the signed-in customer');

// ── the seam, and the link's address ───────────────────────────────────────
echo "\nThe fake-DPO seam cannot point a real payment anywhere\n";
putenv('DN_DPO_FAKE_URL=http://127.0.0.1:9999/');
t('loopback, test: honoured', DpoBootstrap::harnessUrl('test'), 'http://127.0.0.1:9999/');
t('loopback, live: ignored',  DpoBootstrap::harnessUrl('live'), '');
putenv('DN_DPO_FAKE_URL=https://collector.example.com/');
t('any other address: ignored', DpoBootstrap::harnessUrl('test'), '');
putenv('DN_DPO_FAKE_URL=http://127.0.0.1:9999/@collector.example.com/');
t('nor smuggled after a loopback prefix', DpoBootstrap::harnessUrl('test'), '');
putenv('DN_DPO_FAKE_URL');
$key = str_repeat('ab12', 8);
$lc  = ['crm_public_url' => 'https://crm.example.test', 'dpo_test_link_key' => $key];
t('the test link', DpoBootstrap::testLinkUrl($lc + ['dpo_environment' => 'test']),
  'https://crm.example.test/crm/_plugins/dishnet-hybrid-sudan/public.php?page=dpo_test&k=' . $key);
t('none when live',          DpoBootstrap::testLinkUrl($lc + ['dpo_environment' => 'live']), '');
t('none before one is made', DpoBootstrap::testLinkUrl(['crm_public_url' => 'https://crm.example.test']), '');
require_once $root . '/lib/ConfigVault.php';
$vs = ConfigVault::store($root, $tmp, ['dpo_test_clients' => '12', 'dpo_test_link_key' => $key]);
is_(!empty($vs['ok']), 'both are vault keys, so the admin screen can keep them through a re-install', json_encode($vs));
@unlink($tmp . '/vault.json');

// ── D. the test link, through php -S ───────────────────────────────────────
echo "\nD. DPO's reviewer, from the test link to \"Payment successful\"\n";
$data = $tmp . '/data';
@mkdir($data, 0777, true);
file_put_contents($tmp . '/datadir', $data);
// The database first: a store opened on a directory with no database IMPORTS
// every *.json there and renames the originals, so a settings file written
// before it would vanish from under PluginConfig::load().
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
SqliteStore::create($data);
file_put_contents($tmp . '/router.php', '<?php
declare(strict_types=1);
$root = ' . var_export($root, true) . ';
$dataDir = trim((string)file_get_contents(' . var_export($tmp . '/datadir', true) . '));
parse_str((string)parse_url((string)($_SERVER["REQUEST_URI"] ?? ""), PHP_URL_QUERY), $q);
$page = (string)($q["page"] ?? "");
if (($_SERVER["REQUEST_METHOD"] ?? "") === "POST" && $page === "") { echo "router"; return; }
if ($page === "dpo_test")   { require $root . "/dpo_test.php";   return; }
if ($page === "dpo_return") { require $root . "/dpo_return.php"; return; }
http_response_code(404); echo "router: no such page";
');
$cfg = ['crm_base_url' => $crmBase, 'crm_auth_token' => 'FAKE-TEST-KEY', 'crm_public_url' => 'https://crm.example.test',
        'dpo_enabled' => '1', 'dpo_environment' => 'test', 'dpo_company_token' => 'TESTTOKEN-REVIEW-0001',
        'dpo_service_type' => '5525', 'dpo_payment_method_uuid' => $METHOD, 'dpo_currencies' => 'UGX',
        'dpo_test_clients' => '12', 'dpo_test_link_key' => $key];
// As the admin screen does it: the settings, and the vault kept in step (every
// PluginConfig::load() snapshots the settings into the vault and fills gaps
// from it, so a value cleared here alone would come straight back).
$setCfg = function (array $over) use ($data, $cfg, $tmp) {
    file_put_contents($data . '/kyc_config.json', json_encode(array_merge($cfg, $over)));
    @unlink($tmp . '/vault.json');
};
$setCfg([]);
[$webSrv, $webPort] = boot($tmp . '/router.php', 9900, 'router',
    ['DN_DPO_FAKE_URL' => $dpoBase, 'DN_VAULT_FILE' => $tmp . '/vault.json', 'DN_PLUGIN_ROOT' => $root]);
if ($webSrv === null) { echo "  FAIL the plugin pages started under php -S\n"; exit(1); }
$page = "http://127.0.0.1:{$webPort}/?page=dpo_test&k=";

[$c, , $b] = http($page . 'wrong-key-wrong-key-wrong');
t('a wrong key: 404', $c, 404);
t('saying nothing more', trim($b), 'Not found');
[$c] = http($page);
t('no key: 404', $c, 404);
$setCfg(['dpo_environment' => 'live']);
[$c] = http($page . $key);
t('the right key while LIVE: 404', $c, 404);
$setCfg(['dpo_test_link_key' => '']);
[$c] = http($page . $key);
t('the right key before a link is made: 404', $c, 404);
$setCfg([]);

[$c, $hd, $b] = http($page . $key);
t('the right key in test: the page', $c, 200);
is_(strpos($hd, 'referrer-policy: no-referrer') !== false, 'no referrer, so the key is never sent on to DPO');
is_(strpos($hd, 'x-robots-tag: noindex') !== false && strpos($b, 'noindex') !== false, 'not for search engines');
is_(strpos($hd, 'cache-control: no-store') !== false, 'never cached');
is_(strpos($b, 'DPO Test Customer') !== false && strpos($b, 'INV-2026-00140') !== false
    && strpos($b, 'UGX 1,000') !== false && strpos($b, 'Pay with DPO') !== false,
    'it lists the test customer\'s unpaid invoice with a Pay button');
is_(strpos($b, 'INV-2026-00125') === false, 'and nobody else\'s invoices');
is_(strpos($b, 'TESTTOKEN-REVIEW-0001') === false, 'and never the company token');

$pdo = function () use ($data): PDO {
    $p = new PDO('sqlite:' . $data . '/plugin.sqlite3'); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $p;
};
[$c, $hd, $b, $rawHd] = http($page . $key, ['invoice_id' => 140]);
t('Pay: a redirect', $c, 303);
is_(preg_match('#\nLocation: (https://secure\.3gdirectpay\.com/payv3\.php\?ID=TEST-TOK-\d+)#i', $rawHd, $m) === 1
    && strpos($m[1], 'ID=TEST-TOK-') !== false,
    'to DPO\'s payv3.php page with the new token', $rawHd);
$row = $pdo()->query("SELECT * FROM dpo_payments ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
is_((int)($row['crm_client_id'] ?? 0) === 12 && (int)($row['crm_invoice_id'] ?? 0) === 140
    && ($row['status'] ?? '') === 'REDIRECTED' && ($row['environment'] ?? '') === 'test'
    && (float)($row['amount'] ?? 0) === 1000.0, 'one attempt, for the test customer\'s invoice, stamped test', json_encode($row));
$token = (string)($row['dpo_trans_token'] ?? '');

$rows = (int)$pdo()->query("SELECT COUNT(*) FROM dpo_payments")->fetchColumn();
[$c, , $b] = http($page . $key, ['invoice_id' => 125]);
t('Pay on somebody else\'s invoice: the page again', $c, 200);
is_(strpos($b, 'That invoice is not on a test customer.') !== false, 'saying so');
t('and no attempt was written', (int)$pdo()->query("SELECT COUNT(*) FROM dpo_payments")->fetchColumn(), $rows);

crmCall('/__test/reset');
[$c, , $b] = http("http://127.0.0.1:{$webPort}/?page=dpo_return&TransactionToken=" . rawurlencode($token));
t('back from DPO: the result page', $c, 200);
is_(strpos($b, 'Payment successful') !== false, 'which says the payment succeeded, after asking DPO');
t('one payment in uCRM, for the test customer', paymentCount(), 1);
$pays = crmCall('/__test/payments')['payments'] ?? [];
is_((int)($pays[0]['clientId'] ?? 0) === 12 && strpos((string)($pays[0]['note'] ?? ''), 'Env: test') !== false,
    'marked Env: test in its note', json_encode($pays));
is_(strpos($b, 'Back to the test page') !== false && strpos($b, 'page=dpo_test&amp;k=' . $key) !== false,
    'and it sends the reviewer back to the test page, not to a portal they cannot sign in to');
is_(strpos($b, 'DPO test account — no real money moved.') !== false, 'saying no real money moved');
is_(strpos($b, 'View receipt') === false, 'with no portal buttons');
is_(substr_count($b, 'class="btn ') >= 1 && strpos($b, '<a class="pri"') === false,
    'its buttons carry the btn class, so they are styled as buttons');

// The same path for a customer who is not a test customer by the time DPO
// answers: quarantined, and the page sends them to the portal, not the link.
[$c, $hd] = http($page . $key, ['invoice_id' => 140]);
$row2 = $pdo()->query("SELECT * FROM dpo_payments ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
is_($c === 303 && ($row2['reference'] ?? '') !== ($row['reference'] ?? ''), 'a second test payment starts');
$setCfg(['dpo_test_clients' => '']);
crmCall('/__test/reset');
[$c, , $b] = http("http://127.0.0.1:{$webPort}/?page=dpo_return&TransactionToken=" . rawurlencode((string)$row2['dpo_trans_token']));
t('paid after the customer stopped being a test customer: quarantined',
  (string)$pdo()->query("SELECT status FROM dpo_payments WHERE id = " . (int)$row2['id'])->fetchColumn(), 'QUARANTINED');
t('nothing reached uCRM', paymentCount(), 0);
is_(strpos($b, 'Payment is being confirmed') !== false && strpos($b, 'Back to the test page') === false,
    'the page says it is being confirmed, and offers no test link');
$setCfg([]);

$setCfg(['dpo_enabled' => '0']);
[$c, , $b] = http($page . $key, ['invoice_id' => 140]);
is_($c === 200 && strpos($b, 'Pay Now is switched off') !== false && strpos($b, '(DISABLED)') !== false,
    'switched off: the page says so, and Pay is refused with the reason');
$setCfg([]);

$pub = (string)file_get_contents($root . '/public.php');
is_(preg_match("/\\\$page === 'dpo_test'\\) \\{\\s*while \\(ob_get_level\\(\\) > 0\\) ob_end_clean\\(\\);\\s*require __DIR__ \\. '\\/dpo_test\\.php';/", $pub) === 1,
    'public.php routes ?page=dpo_test to the page');
$adm = (string)file_get_contents($root . '/tabs/admin/dpo_payments.php');
is_(strpos($adm, 'name="dpo_test_clients"') !== false && strpos($adm, "'new_test_link'") !== false
    && strpos($adm, 'DpoBootstrap::testLinkUrl($dpConfig)') !== false && strpos($adm, 'random_bytes(16)') !== false,
    'the admin screen names test customers, and makes and replaces the link');
is_(preg_match("/'new_test_link'\\) \\{\\s*if \\(function_exists\\('csrfCheck'\\)\\) csrfCheck\\(\\);/", $adm) === 1,
    'replacing the link is CSRF-checked like saving');

// ── E. tools/dpo_probe.php ─────────────────────────────────────────────────
echo "\nE. tools/dpo_probe.php asks DPO before anyone is sent the link\n";
$pdir = $tmp . '/probe';
@mkdir($pdir, 0777, true);
$probe = function (array $cfgP, array $args = [], string $stdin = '') use ($root, $pdir, $dpoBase): array {
    file_put_contents($pdir . '/kyc_config.json', json_encode($cfgP));
    @unlink($pdir . '/vault.json');                     // its own vault, empty every run
    $env = ['DN_DATA_DIR' => $pdir, 'DN_VAULT_FILE' => $pdir . '/vault.json', 'DN_DPO_FAKE_URL' => $dpoBase] + getenv();
    $p = proc_open(array_merge(['php', $root . '/tools/dpo_probe.php'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    fwrite($pipes[0], $stdin); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out];
};
$SECRET = 'TESTTOKEN-PROBE-SECRET-1234';
$pc = ['dpo_environment' => 'test', 'dpo_company_token' => $SECRET, 'dpo_service_type' => '5525', 'dpo_currencies' => 'UGX'];

$before = dpoCalls();
[$code, $out] = $probe(['dpo_environment' => 'live'] + $pc);
t('live: it refuses', $code, 2);
is_(strpos($out, 'Nothing was sent') !== false, 'saying nothing was sent', $out);
t('and nothing was', dpoCalls(), $before);

[$code, $out] = $probe(['dpo_environment' => 'test', 'dpo_service_type' => '5525']);
is_($code === 1 && strpos($out, 'No company token') !== false, 'no token: it says so', $out);

[$code, $out] = $probe($pc);
t('a token that takes UGX: success', $code, 0);
is_(preg_match('/createToken\s+000/', $out) === 1 && preg_match('/verifyToken\s+900/', $out) === 1,
    'createToken 000, then verifyToken 900 "not paid yet"', $out);
is_(strpos($out, 'DPO accepted the token, the service type and UGX') !== false, 'in plain words');
is_(preg_match('#https://secure\.3gdirectpay\.com/payv3\.php\?ID=TEST-TOK-\d+#', $out) === 1, 'with DPO\'s page for it');
is_(strpos($out, $SECRET) === false, 'and never the company token');
is_(!is_file($pdir . '/plugin.sqlite3'), 'it opened no database — nothing of ours was written');

[$code, $out] = $probe($pc, ['--currency', 'XTS']);
is_($code === 1 && preg_match('/createToken\s+904/', $out) === 1 && strpos($out, 'does not take XTS') !== false,
    'an account that does not take the currency: 904, and what to do', $out);

[$code, $out] = $probe(['dpo_company_token' => 'LIVE-LOOKING-TOKEN'] + $pc);
is_($code === 1 && preg_match('/createToken\s+802/', $out) === 1 && strpos($out, 'does not know this company token') !== false,
    'a token DPO does not know: 802, said plainly', $out);

$ASKED = 'TESTTOKEN-ASKED-5678';
[$code, $out] = $probe(['dpo_environment' => 'test', 'dpo_currencies' => 'UGX'], ['--ask'], $ASKED . "\n5525\n");
t('--ask: a pair typed in is tried', $code, 0);
is_(strpos($out, $ASKED) === false && preg_match('/createToken\s+000/', $out) === 1,
    'and the typed token is not printed either', $out);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

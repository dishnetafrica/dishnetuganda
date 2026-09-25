<?php
/**
 * test_kyc_crm_create.php — a KYC customer reaches uCRM, on the uCRM that is
 * actually connected.
 *
 * Until 5.18.28 every KYC customer on the Uganda install stayed in the plugin.
 * Measured on the live box on 25 September 2026, read-only:
 *
 *   - the create named organization 2; that uCRM has only organization 1
 *   - it named client custom fields 36–43; that uCRM has only 1–4, and its
 *     field 1 is "EFRIS TIN" — where the create put the agent's name
 *   - uCRM answered 404 {"code":404,"message":"Not Found"}, twice in the logs
 *   - the agent was told "Customer saved!", the Orders screen counted the
 *     customer as "In CRM ✓", and the retry job never ran (it opened data.db)
 *   - all three KYC applications ever made there were stuck
 *
 * This drives the REAL code — KycService, KycCrmSync, UcrmClientTarget, the
 * cron file in a production directory layout, and the Orders template —
 * against a fake uCRM shaped like both installs (fixtures/fake_ucrm_kyc.php).
 * On the South Sudan layout the request must stay byte-identical: the
 * standing rule is that that install does not change without new config.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/WalletService.php';
require_once $root . '/lib/CrmQueue.php';
require_once $root . '/lib/EfrisClientField.php';
require_once $root . '/lib/UcrmClientTarget.php';
require_once $root . '/lib/KycService.php';
require_once $root . '/lib/KycCrmSync.php';

$tmp = sys_get_temp_dir() . '/dn_kyc_crm_' . getmypid();
@mkdir($tmp, 0777, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });

// ── A. the tax-field rule, one place ───────────────────────────────────────
echo "\nA. EfrisClientField — which custom fields are tax fields\n";
foreach (['efrisTin' => 'tin', 'EFRIS TIN' => 'tin', 'efrisBrn' => 'brn', 'efrisNin' => 'nin',
          'efrisTaxpayerType' => 'taxpayer_type', 'EFRIS Taxpayer Type' => 'taxpayer_type',
          'efrisBuyerType' => 'buyer_type'] as $k => $want) {
    is_(EfrisClientField::of($k) === $want, "'{$k}' is the {$want} field");
}
foreach (['salesPerson', 'Sales Person', 'priority', 'ref', 'deviceId', 'package',
          'kitNumber', 'kitQty', 'kitUnit', 'kitName', 'starlinkdetails', ''] as $k) {
    is_(EfrisClientField::of($k) === null, "'{$k}' is not a tax field");
}
is_(EfrisClientField::isTaxField(['key' => 'x1', 'name' => 'EFRIS TIN']), 'a tax NAME is enough, whatever the key');
is_(!EfrisClientField::isTaxField(['key' => 'salesPerson', 'name' => 'Sales Person']), 'Sales Person is not a tax field');

// ── the fake uCRM ──────────────────────────────────────────────────────────
function boot_fake(string $router): array {
    // A server already on the port (another run, or one left behind) must not
    // be mistaken for ours: ours echoes a token only this process knows.
    $token = bin2hex(random_bytes(8));
    foreach (range(0, 9) as $slot) {
        $port = 9800 + ((getmypid() + $slot * 13) % 150);
        if (ctl($port, '/__test/ping') !== null) continue;          // taken
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $port, escapeshellarg($router)),
                       [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes,
                       null, ['FAKE_UCRM_KYC_TOKEN' => $token] + getenv());
        for ($i = 0; $i < 40; $i++) {
            $r = ctl($port, '/__test/ping');
            if ($r !== null) {
                if (($r['marker'] ?? '') === 'FAKE-UCRM-KYC' && ($r['token'] ?? '') === $token) return [$p, $port];
                break;
            }
            usleep(100000);
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
}
function ctl(int $port, string $path, ?array $body = null): ?array {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    if ($body !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
                                CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    $r = curl_exec($ch);
    curl_close($ch);
    return $r === false ? null : (json_decode((string)$r, true) ?? []);
}
[$srv, $port] = boot_fake($root . '/tests/fixtures/fake_ucrm_kyc.php');
if ($srv === null) { bad('the fake uCRM started'); echo "\n{$pass} passed, {$fail} failed\n"; exit(1); }
register_shutdown_function(function () use (&$srv, $port) {
    @unlink(sys_get_temp_dir() . '/fake_ucrm_kyc_' . $port . '.json');
    if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
});
$BASE = "http://127.0.0.1:{$port}/api/v2.1";

function scenario(string $name): void { global $port; ctl($port, '/__test/reset?scenario=' . $name); }
function fake_log(): array { global $port; return ctl($port, '/__test/log') ?? []; }
function calls(string $method, string $pathRe): array {
    return array_values(array_filter(fake_log(), fn($e) => $e['method'] === $method
        && preg_match('#^/api/v2\.1' . $pathRe . '$#', (string)$e['path'])));
}
function crm(): CrmApiClient { global $BASE; return new CrmApiClient($BASE, 'FAKE-TEST-KEY', 'X-Auth-App-Key'); }
function fresh_store(string $name): SqliteStore {
    global $tmp;
    $dir = $tmp . '/' . $name;
    exec('rm -rf ' . escapeshellarg($dir));
    $s = SqliteStore::create($dir);
    $s->save('subscription_plans.json', [['id' => 1, 'name' => 'Residential Standard (TEST)', 'customer_price' => 100]]);
    return $s;
}
function kyc(SqliteStore $s, string $dir = ''): KycService {
    return new KycService(crm(), $s, new WalletService($s, $s->getPdo()), new CrmQueue($s), $dir);
}
function kyc_post(string $phone, string $last, string $salesType = 'Credit'): array {
    return ['action' => 'kyc_submit', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection',
            'firstname' => 'Test', 'lastname' => $last, 'mobile' => $phone, 'email' => '',
            'address_1' => 'Test address', 'package_choice' => '1', 'device_id' => '0',
            'sales_type' => $salesType, 'priority' => 'Low'];
}
function legacy_payload(string $phone = '+256700000101', string $username = 'STAR000001'): array {
    $attrs = [];
    foreach ([36 => 'Low', 1 => 'Test Agent', 43 => '', 42 => '0', 41 => '1', 37 => '', 38 => '', 40 => '', 39 => '']
             as $id => $v) $attrs[] = ['value' => $v, 'customAttributeId' => $id];
    return ['clientType' => 1, 'isLead' => true, 'firstName' => 'Test', 'lastName' => 'Old',
            'street1' => 'Test address', 'street2' => '', 'city' => '', 'countryId' => null,
            'organizationId' => 2, 'stateId' => null, 'note' => ' / Residential Standard (TEST)',
            'zipCode' => '', 'username' => $username,
            'contacts' => [['name' => 'Test', 'email' => '', 'phone' => $phone]], 'attributes' => $attrs];
}
$AGENT = ['id' => 7, 'name' => 'Test Agent', 'role' => 'sales'];
function app_of(SqliteStore $s, int $id): array { return $s->findOne('kyc_applications.json', 'id', $id) ?? []; }

// ── B. where a new client goes, per install ─────────────────────────────────
echo "\nB. UcrmClientTarget — the connected uCRM decides organization and custom fields\n";
$legacy = legacy_payload();

scenario('uganda');
$t = new UcrmClientTarget(crm());
is_($t->organization() === ['org' => 1, 'error' => ''], 'Uganda: organization 1, the only one (2 does not exist)');
is_($t->customFields() === ['legacy' => false, 'error' => ''], 'Uganda: the nine numbered custom fields do not apply');
is_(!$t->legacyLayout(), 'Uganda: not the South Sudan layout');
$placed = $t->apply($legacy);
is_($placed['error'] === '' && ($placed['payload']['organizationId'] ?? null) === 1, 'Uganda: the request names organization 1');
is_(!array_key_exists('attributes', $placed['payload'] ?? []), 'Uganda: no custom field is sent — field 1 there is EFRIS TIN');
$rest = $placed['payload']; $want = $legacy; unset($rest['organizationId'], $want['organizationId'], $want['attributes']);
is_($rest === $want, 'Uganda: everything else in the request is untouched');
is_(count(calls('GET', '/organizations')) === 1 && count(calls('GET', '/custom-attributes')) === 1,
    'uCRM is asked once each, however often the answer is used');
is_(calls('POST', '/clients') === [], 'deciding sends nothing to uCRM');

scenario('sudan');
$t = new UcrmClientTarget(crm());
is_($t->organization()['org'] === 2 && $t->customFields()['legacy'] && $t->legacyLayout(),
    'South Sudan layout: organization 2 and all nine fields');
$placed = $t->apply($legacy);
is_(json_encode($placed['payload']) === json_encode($legacy), 'South Sudan layout: the request is byte-identical to before');

scenario('sudan_partial');
$t = new UcrmClientTarget(crm());
is_($t->organization()['org'] === 2 && !$t->customFields()['legacy'], 'one of the nine missing: none is sent (all or nothing)');
is_(!array_key_exists('attributes', $t->apply($legacy)['payload'] ?? []), 'and the request carries no custom fields');

scenario('sudan_taxfield');
$t = new UcrmClientTarget(crm());
is_(!$t->customFields()['legacy'], 'all nine present but field 1 is a tax field: none is sent');

scenario('two_orgs');
$t = new UcrmClientTarget(crm());
$o = $t->organization();
is_($o['org'] === null && strpos($o['error'], 'will not guess') !== false, 'two organizations and no 2: REFUSE, never a guess', $o['error']);
is_($t->apply($legacy)['payload'] === null, 'and nothing is built to send');

scenario('down');
$t = new UcrmClientTarget(crm());
$o = $t->organization();
is_($o['org'] === null && strpos($o['error'], 'could not be read') !== false && strpos($o['error'], '503') !== false,
    'uCRM down: an error naming the HTTP status, not a default', $o['error']);
is_(UcrmClientTarget::describe(['http_code' => 404, 'response' => ['code' => 404, 'message' => 'Not Found']])
    === 'uCRM answered HTTP 404: Not Found', 'the refusal the live box logged is described as it was');
is_(strpos(UcrmClientTarget::describe(['http_code' => 422, 'response' => ['message' => '<b>x</b>',
    'errors' => ['organizationId' => ['x']]]]), '<') === false, 'a described error carries no markup');

// ── C. a KYC customer reaches uCRM on the Uganda layout ─────────────────────
echo "\nC. KycService — a new customer on the Uganda layout\n";
scenario('uganda');
$s = fresh_store('c');
$r = kyc($s, $tmp . '/c')->process(kyc_post('+256700000101', 'Customer-C'), [], $AGENT);
is_($r['success'] === true && strpos($r['message'], 'Customer registered! CRM ID: 901') === 0,
    'the agent is told the customer is in the CRM, with its id', $r['message']);
$app = app_of($s, (int)$r['data']['application_id']);
is_((string)($app['crm_client_id'] ?? '') === '901' && ($app['crm_sync_status'] ?? '') === 'synced'
    && !isset($app['crm_sync_error']), 'the application carries the uCRM id, synced, no error', json_encode(
    array_intersect_key($app, array_flip(['crm_client_id', 'crm_sync_status', 'crm_sync_error']))));
$post = calls('POST', '/clients');
is_(count($post) === 1, 'one create request');
is_(($post[0]['body']['organizationId'] ?? null) === 1, 'it names organization 1');
is_(!array_key_exists('attributes', $post[0]['body'] ?? []), 'it sends no custom field, so EFRIS TIN is untouched');
is_(($post[0]['body']['isLead'] ?? null) === true, 'a Credit sale is created as a lead, as the form says');
is_(calls('POST', '/documents') === [] && calls('PATCH', '/clients/\d+/add-tag/\d+') === [],
    'no South Sudan work-order template or tag is used on this layout');

// ── D. the South Sudan layout is unchanged ─────────────────────────────────
echo "\nD. KycService — the South Sudan layout sends what it always sent\n";
scenario('sudan');
$s = fresh_store('d');
$r = kyc($s, $tmp . '/d')->process(kyc_post('+211900000101', 'Customer-D'), [], $AGENT);
$post = calls('POST', '/clients');
is_($r['success'] && count($post) === 1 && ($post[0]['body']['organizationId'] ?? null) === 2, 'organization 2, as before');
$ids = array_map(fn($a) => $a['customAttributeId'], $post[0]['body']['attributes'] ?? []);
is_($ids === [36, 1, 43, 42, 41, 37, 38, 40, 39], 'the nine custom fields, in the same order', json_encode($ids));
$sp = array_values(array_filter($post[0]['body']['attributes'] ?? [], fn($a) => $a['customAttributeId'] === 1));
is_(($sp[0]['value'] ?? '') === 'Test Agent', 'field 1 carries the sales person there, where it is the Sales Person field');
is_(count(calls('POST', '/documents')) === 1 && count(calls('PATCH', '/clients/\d+/add-tag/52')) === 1,
    'the work order and the New Connection tag, as before');

// ── E. a refused create is saved, and said to be NOT in the CRM ─────────────
echo "\nE. KycService — uCRM refuses: saved here, and said plainly\n";
scenario('refuse');
$s = fresh_store('e');
$r = kyc($s, $tmp . '/e')->process(kyc_post('+256700000102', 'Customer-E'), [], $AGENT);
is_($r['success'] === true, 'the application is saved (the photos and the record are real)');
is_(strpos($r['message'], 'NOT in the CRM yet') !== false && strpos($r['message'], 'uCRM answered HTTP 404: Not Found') !== false,
    'the agent is told it is NOT in the CRM, and why', $r['message']);
is_(strpos($r['message'], 'Customer saved!') === false, 'the old reassuring wording is gone');
is_(($r['data']['crm_sync_status'] ?? '') === 'pending' && ($r['data']['crm_sync_error'] ?? '') === 'uCRM answered HTTP 404: Not Found',
    'the result says pending, with the reason');
$app = app_of($s, (int)$r['data']['application_id']);
is_(($app['crm_sync_error'] ?? '') === 'uCRM answered HTTP 404: Not Found', 'the reason is kept on the application');
$saved = json_decode((string)($app['crm_sync_payload'] ?? ''), true);
is_(($saved['organizationId'] ?? null) === 1 && !array_key_exists('attributes', $saved ?? []),
    'the request kept for the retry is already fitted to this uCRM');
is_(count(calls('POST', '/clients')) === 1, 'one create request, no loop on a 404');

scenario('two_orgs');
$s = fresh_store('e2');
$r = kyc($s, $tmp . '/e2')->process(kyc_post('+256700000103', 'Customer-E2'), [], $AGENT);
is_($r['success'] && strpos($r['message'], 'will not guess') !== false, 'an organization it cannot choose is the reason given', $r['message']);
is_(calls('POST', '/clients') === [], 'and nothing is sent to uCRM');

// ── F. registering the same customer again ─────────────────────────────────
echo "\nF. KycService — the same customer again, while the first is not in the CRM\n";
scenario('uganda');
$s = fresh_store('f');
$s->save('kyc_applications.json', [['id' => 1, 'firstname' => 'Test', 'lastname' => 'Waiting', 'mobile' => '+256700000104',
    'status' => 'new', 'crm_client_id' => null, 'crm_sync_status' => 'pending', 'submitted_at' => '2026-09-24 10:00:00']]);
$r = kyc($s, $tmp . '/f')->process(kyc_post('0700 000 104', 'Waiting'), [], $AGENT);
is_($r['success'] === false && strpos($r['message'], 'NOT in the CRM yet') !== false
    && strpos($r['message'], 'application #1') !== false, 'refused, and told the first one is waiting for the CRM', $r['message']);
is_(strpos($r['message'], 'already registered under') === false, 'not told the customer is already registered');
is_(fake_log() === [], 'uCRM is not asked anything');
$s->save('kyc_applications.json', [['id' => 2, 'firstname' => '<b onmouseover="x()">Test</b>', 'lastname' => 'A&B',
    'mobile' => '+256700000105', 'status' => 'new', 'crm_client_id' => null, 'crm_sync_status' => 'pending',
    'submitted_at' => '2026-09-24 10:00:00']]);
$r = kyc($s, $tmp . '/f')->process(kyc_post('+256700000105', 'Again'), [], $AGENT);
is_(strpos($r['message'], 'application #2') !== false && strpos($r['message'], 'onmouseover') !== false
    && strpbrk($r['message'], '<>&"') === false,
    'a stored name cannot put markup into that message (the staff app prints it as HTML)', $r['message']);

// ── G. the retry ───────────────────────────────────────────────────────────
echo "\nG. KycCrmSync — the retry finishes what the form could not\n";
$now = date('Y-m-d H:i:s');
function pending(int $id, string $phone, array $extra = []): array {
    return $extra + ['id' => $id, 'firstname' => 'Test', 'lastname' => 'Old', 'mobile' => $phone, 'status' => 'new',
        'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'sales_type' => 'Credit',
        'is_lead' => true, 'retailer_id' => 7, 'retailer_name' => 'Test Agent', 'username' => 'STAR00000' . $id,
        'crm_client_id' => null, 'crm_sync_status' => 'pending',
        'crm_sync_payload' => json_encode(legacy_payload($phone, 'STAR00000' . $id)),
        'submitted_at' => date('Y-m-d H:i:s', time() - 86400)];
}

scenario('uganda');
$s = fresh_store('g1');
$s->save('kyc_applications.json', [pending(1, '+256700000201')]);
$sum = (new KycCrmSync($s, crm(), []))->runDue();
$app = app_of($s, 1);
is_($sum['synced'] === 1 && ($app['crm_client_id'] ?? null) === '901' && ($app['crm_sync_status'] ?? '') === 'synced',
    'an application saved by the old code is created on the next run', json_encode($sum));
$post = calls('POST', '/clients');
is_(($post[0]['body']['organizationId'] ?? null) === 1 && !array_key_exists('attributes', $post[0]['body'] ?? []),
    'its stored South Sudan request is fitted to this uCRM first');
is_(calls('POST', '/documents') === [] && calls('PATCH', '/clients/\d+/add-tag/\d+') === [],
    'and no South Sudan work order or tag on this layout');
is_(!isset($app['crm_sync_payload']) && ($app['crm_sync_by'] ?? '') === 'cron' && !isset($app['crm_sync_claim'])
    && ($app['crm_sync_claimed_at'] ?? '') !== '', 'the stored request is cleared, the run is attributed, the claim taken and released');

scenario('uganda');
ctl($port, '/__test/set', ['clients' => [['id' => 555, 'contacts' => [['phone' => '+256 700 000 202']]]], 'seq' => 900]);
$s = fresh_store('g2');
$s->save('kyc_applications.json', [pending(2, '0700000202')]);
$sum = (new KycCrmSync($s, crm(), []))->runDue();
$app = app_of($s, 2);
is_($sum['review'] === 1 && ($app['crm_sync_status'] ?? '') === 'review' && ($app['crm_review_client_ids'] ?? null) === [555],
    'uCRM already has a client on that number: STOP and ask a person', json_encode($app['crm_review_client_ids'] ?? null));
is_(calls('POST', '/clients') === [], 'no client is created');
is_(strpos((string)($app['crm_sync_error'] ?? ''), '#555') !== false, 'the reason names the uCRM client to check');
$again = (new KycCrmSync($s, crm(), []))->runDue();
is_($again['due'] === 0 && calls('POST', '/clients') === [], "the cron leaves a 'review' application alone");
$r = (new KycCrmSync($s, crm(), []))->syncOne(2, true, 'admin:Tester');
is_($r['ok'] && $r['status'] === 'synced' && count(calls('POST', '/clients')) === 1,
    'an admin who checked can create it anyway', $r['message']);
is_((app_of($s, 2)['crm_sync_by'] ?? '') === 'admin:Tester', 'and the create is attributed to that admin');

scenario('uganda');
$s = fresh_store('g3');
$s->save('kyc_applications.json', [pending(3, '+256700000203', ['crm_sync_claim' => 'someone', 'crm_sync_claimed_at' => $now])]);
$r = (new KycCrmSync($s, crm(), []))->syncOne(3, false, 'admin:Tester');
is_($r['status'] === 'busy' && calls('POST', '/clients') === [], 'a live claim: the second caller creates nothing');
$s->updateOne('kyc_applications.json', 'id', 3, ['crm_sync_claimed_at' => date('Y-m-d H:i:s', time() - 600)]);
$r = (new KycCrmSync($s, crm(), []))->syncOne(3, false, 'admin:Tester');
is_($r['status'] === 'synced' && count(calls('POST', '/clients')) === 1, 'a claim from a run that died is taken over after five minutes');

scenario('uganda');
$s = fresh_store('g4');
$s->save('kyc_applications.json', [pending(4, '+256700000204', ['submitted_at' => date('Y-m-d H:i:s', time() - 40 * 86400)])]);
$sum = (new KycCrmSync($s, crm(), []))->runDue();
$app = app_of($s, 4);
is_($sum['review'] === 1 && ($app['crm_sync_status'] ?? '') === 'review' && strpos((string)($app['crm_sync_error'] ?? ''), '30 days') !== false,
    'older than 30 days: a person checks it first');
is_(fake_log() === [], 'and uCRM is not touched');

scenario('uganda');
$s = fresh_store('g5');
$s->save('kyc_applications.json', [
    pending(5, '+256700000205', ['crm_sync_attempts' => 2, 'crm_sync_last_attempt' => date('Y-m-d H:i:s', time() - 60)]),
    pending(6, '+256700000206', ['crm_sync_attempts' => 10, 'crm_sync_last_attempt' => date('Y-m-d H:i:s', time() - 86400)]),
]);
$sum = (new KycCrmSync($s, crm(), []))->runDue();
is_($sum['due'] === 0 && calls('POST', '/clients') === [], 'not due yet: the backoff holds');
is_((app_of($s, 6)['crm_sync_status'] ?? '') === 'failed' && $sum['gave_up'] === 1, 'ten attempts: it stops and says so');

scenario('refuse');
$s = fresh_store('g6');
$s->save('kyc_applications.json', [pending(7, '+256700000207')]);
$sum = (new KycCrmSync($s, crm(), []))->runDue();
$app = app_of($s, 7);
is_($sum['failed'] === 1 && ($app['crm_sync_status'] ?? '') === 'pending' && (int)($app['crm_sync_attempts'] ?? 0) === 1
    && ($app['crm_sync_error'] ?? '') === 'uCRM answered HTTP 404: Not Found', 'a refused retry is counted, with the reason, for the next run');

scenario('uganda');
ctl($port, '/__test/set', ['taken' => ['STAR000008'], 'seq' => 900]);
$s = fresh_store('g7');
$s->save('kyc_applications.json', [pending(8, '+256700000208')]);
(new KycCrmSync($s, crm(), []))->runDue();
$app = app_of($s, 8);
is_(($app['crm_sync_status'] ?? '') === 'synced' && ($app['username'] ?? '') === 'STAR000009',
    'a username taken meanwhile: the next one, as the form does', (string)($app['username'] ?? ''));

scenario('sudan');
$s = fresh_store('g8');
$s->save('kyc_applications.json', [pending(9, '+211900000209')]);
(new KycCrmSync($s, crm(), []))->runDue();
$post = calls('POST', '/clients');
is_(($post[0]['body']['organizationId'] ?? null) === 2 && count($post[0]['body']['attributes'] ?? []) === 9,
    'South Sudan layout: the retry sends organization 2 and the nine fields');
is_(count(calls('POST', '/documents')) === 1 && count(calls('PATCH', '/clients/\d+/add-tag/52')) === 1,
    'and the work order and tag, as the form does there');

scenario('uganda');
$s = fresh_store('g9');
$s->save('kyc_applications.json', [pending(10, '+256700000210', ['sales_type' => 'Cash', 'amount_charged' => 150,
    'is_lead' => false]), pending(11, '+256700000211', ['sales_type' => 'Cash', 'amount_charged' => 90, 'is_lead' => false])]);
(new KycCrmSync($s, crm(), ['kyc_auto_payment_enabled' => false]))->syncOne(11, false, 'admin:Tester');
is_(calls('POST', '/payments') === [], 'the auto-payment switch off: no payment, as on the form');
scenario('uganda');
(new KycCrmSync($s, crm(), []))->runDue();
$pays = calls('POST', '/payments');
is_(count($pays) === 1 && (float)($pays[0]['body']['amount'] ?? 0) === 150.0 && (int)($pays[0]['body']['clientId'] ?? 0) === 901
    && strpos((string)($pays[0]['body']['note'] ?? ''), 'Ref: KYC-901') !== false,
    'a Cash sale: the cash the agent collected is recorded in uCRM, with the form\'s reference', json_encode($pays));
is_((int)(app_of($s, 10)['payment_id'] ?? 0) === 902, 'and the application keeps the payment id');
$r = (new KycCrmSync($s, crm(), []))->syncOne(10, true, 'admin:Tester');
is_($r['status'] === 'synced' && count(calls('POST', '/payments')) === 1 && count(calls('POST', '/clients')) === 1,
    'retrying an application already in uCRM creates nothing more');

scenario('uganda');
$s = fresh_store('g10');
$s->save('kyc_applications.json', [pending(12, '+256700000212', ['crm_sync_payload' => null, 'address_1' => 'Plot 1'])]);
(new KycCrmSync($s, crm(), []))->runDue();
$post = calls('POST', '/clients');
$b = $post[0]['body'] ?? [];
is_(count($post) === 1 && ($b['organizationId'] ?? null) === 1 && !array_key_exists('attributes', $b)
    && ($b['firstName'] ?? '') === 'Test' && ($b['lastName'] ?? '') === 'Old' && ($b['street1'] ?? '') === 'Plot 1'
    && ($b['contacts'][0]['phone'] ?? '') === '+256700000212' && ($b['isLead'] ?? null) === true
    && ($b['username'] ?? '') === 'STAR0000012',
    'an application saved without a stored request: rebuilt from its own fields, then fitted', json_encode($b));

// ── H. the real cron file, in the directory layout of the live install ──────
echo "\nH. cron/kyc_crm_sync.php — run as master.php runs it, laid out as on the server\n";
scenario('uganda');
$plugins = $tmp . '/plugins';
$pr = $plugins . '/dishnet-hybrid-sudan';
exec('rm -rf ' . escapeshellarg($plugins));
@mkdir($pr . '/cron', 0777, true);
exec('cp -R ' . escapeshellarg($root . '/lib') . ' ' . escapeshellarg($pr . '/lib'));
copy($root . '/cron/kyc_crm_sync.php', $pr . '/cron/kyc_crm_sync.php');
file_put_contents($pr . '/ucrm.json', json_encode(['ucrmLocalUrl' => "http://127.0.0.1:{$port}/",
    'ucrmPublicUrl' => "http://127.0.0.1:{$port}/", 'pluginAppKey' => 'FAKE-TEST-KEY']));
$live = SqliteStore::create($plugins . '/.dishnet-hybrid-sudan-data');   // where getDataDir() puts it
$live->save('kyc_applications.json', [pending(1, '+256700000301')]);
$wrapper = $tmp . '/master_like.php';
file_put_contents($wrapper, '<?php
$pluginRoot = ' . var_export($pr, true) . ';
require_once $pluginRoot . "/lib/bootstrap_data.php";
$dataDir = getDataDir($pluginRoot);            // master.php:53
$before  = $dataDir;
try { include $pluginRoot . "/cron/kyc_crm_sync.php"; } catch (\Throwable $e) { echo "THREW ", $e->getMessage(), "\n"; }
echo "DATADIR_KEPT=", $dataDir === $before ? "yes" : "no", "\n";
');
exec('php ' . escapeshellarg($wrapper) . ' 2>&1', $out, $code);
$out = implode("\n", $out);
$app = app_of($live, 1);
is_($code === 0 && strpos($out, 'THREW') === false, 'the job runs to its end', $out);
is_(($app['crm_client_id'] ?? null) === '901' && ($app['crm_sync_status'] ?? '') === 'synced',
    'the stuck application is created in uCRM by the scheduled job', $out);
is_(strpos($out, 'DATADIR_KEPT=yes') !== false, "master.php's \$dataDir is left as it was");
is_(strpos($out, 'created 1') !== false, 'the job says what it did', $out);
is_(!is_file($pr . '/data/data.db') && !is_file($plugins . '/.dishnet-hybrid-sudan-data/data.db'), 'no data.db anywhere');

// ── I. the Orders screen ──────────────────────────────────────────────────
echo "\nI. The Orders screen counts from the uCRM id and says why\n";
function render_orders(array $apps, bool $admin): string {
    global $root, $tmp;
    $f = $tmp . '/render.php';
    file_put_contents($f, '<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function dn_cur($c = null){ return "UGX "; }
function dn_code($c = null){ return "UGX"; }
function csrfToken(){ return "TOKEN"; }
function csrfField(){ return "<input type=\"hidden\" name=\"_csrf\" value=\"TOKEN\">"; }
$myApps  = json_decode(' . var_export(json_encode($apps), true) . ', true);
$isAdmin = ' . ($admin ? 'true' : 'false') . ';
$config  = [];
$dataDir = ' . var_export($tmp, true) . ';
$_SERVER["HTTP_HOST"] = "crm.example.test";
include ' . var_export($root . '/tabs/sales/applications.php', true) . ';
');
    exec('php ' . escapeshellarg($f) . ' 2>&1', $o);
    return implode("\n", $o);
}
function tile(string $html, string $label): ?int {
    return preg_match('#<div class="app-stat-num"[^>]*>(\d+)</div><div class="app-stat-label">' . preg_quote($label, '#') . '</div>#u', $html, $m)
        ? (int)$m[1] : null;
}
$apps = [
    ['id' => 1, 'firstname' => 'A', 'lastname' => 'Synced',  'status' => 'new', 'crm_client_id' => '901', 'crm_sync_status' => 'synced'],
    ['id' => 2, 'firstname' => 'B', 'lastname' => 'Pending', 'status' => 'new', 'crm_client_id' => null, 'crm_sync_status' => 'pending',
     'crm_sync_error' => 'uCRM answered HTTP 404: Not Found <script>'],
    ['id' => 3, 'firstname' => 'C', 'lastname' => 'Review',  'status' => 'new', 'crm_client_id' => null, 'crm_sync_status' => 'review',
     'crm_sync_error' => 'uCRM already has a client with this phone number (#555).', 'crm_review_client_ids' => [555]],
    ['id' => 4, 'firstname' => 'D', 'lastname' => 'GaveUp',  'status' => 'new', 'crm_client_id' => null, 'crm_sync_status' => 'failed'],
];
$html = render_orders($apps, true);
is_(tile($html, 'In CRM ✓') === 1, "'In CRM' counts only the application with a uCRM id", (string)tile($html, 'In CRM ✓'));
is_(tile($html, 'Pending') === 2 && tile($html, 'Failed') === 1, 'waiting and stopped ones are counted as such');
is_(substr_count($html, 'Not in CRM yet') >= 2, "the badge says 'Not in CRM yet' instead of 'New'");
is_(strpos($html, 'uCRM answered HTTP 404: Not Found &lt;script&gt;') !== false && strpos($html, 'Not Found <script>') === false,
    'the reason is shown, escaped');
is_(substr_count($html, 'value="kyc_crm_retry"') === 3, 'an admin gets a retry on each application not in the CRM');
is_(substr_count($html, 'name="force" value="1"') === 1 && strpos($html, 'Create in CRM anyway') !== false,
    'only the one with a possible duplicate is forced, behind a confirmation');
is_(strpos($html, '/crm/client/555') !== false, 'with a link to the uCRM client to check');
$html = render_orders($apps, false);
is_(strpos($html, 'kyc_crm_retry') === false && strpos($html, 'Not in the CRM yet') !== false,
    'an agent sees the state and the reason, not the button');

// ── J. the sales-person index never reads a tax field ──────────────────────
echo "\nJ. The sales-person index skips tax fields\n";
foreach (['main.php', 'includes/api/api_crm_misc.php'] as $f) {
    $src = (string)file_get_contents($root . '/' . $f);
    is_(preg_match('/EfrisClientField::of\([^;]*\) !== null\) continue;/', $src) === 1, "{$f} skips a tax field when indexing sales people");
}

// ── K. the staff app's two POST handlers, run for real ─────────────────────
echo "\nK. includes/post/post_kyc.php — the flash colour and the admin Retry\n";
/**
 * Runs the real post_kyc.php in a fresh process with public.php's globals
 * stubbed. $kyc->process() answers what the scenario says; flash() and
 * redirect() report what the handler decided.
 */
function run_post(array $post, array $opts): array {
    global $root, $tmp, $BASE;
    $f = $tmp . '/post_run.php';
    file_put_contents($f, '<?php
error_reporting(E_ALL & ~E_DEPRECATED);
require_once ' . var_export($root . '/lib/StoreInterface.php', true) . ';
require_once ' . var_export($root . '/lib/JsonStore.php', true) . ';
require_once ' . var_export($root . '/lib/SqliteStore.php', true) . ';
require_once ' . var_export($root . '/lib/CrmApiClient.php', true) . ';
$GLOBALS["__flash"] = null;
function flash(string $m, string $t = "success"): void { $GLOBALS["__flash"] = ["msg" => $m, "type" => $t]; }
function redirect(string $u): void { echo json_encode(["flash" => $GLOBALS["__flash"], "to" => $u]); exit; }
function logActivity(...$a): void {}
final class StubAuth {
    public function __construct(private bool $admin) {}
    public function requireLogin(): array { return ["id" => 7, "name" => "Tester", "is_admin" => $this->admin]; }
    public function requireAdmin(): array { if (!$this->admin) { echo json_encode(["denied" => true]); exit; } return $this->requireLogin(); }
}
final class StubKyc { public function __construct(private array $r) {} public function process($p, $f, $r): array { return $this->r; } }
final class StubNotify { public function __call($n, $a) { return null; } }
$auth    = new StubAuth(' . var_export((bool)($opts['admin'] ?? false), true) . ');
$store   = SqliteStore::create(' . var_export($opts['dir'] ?? ($tmp . '/k'), true) . ');
$crm     = new CrmApiClient(' . var_export($BASE, true) . ', "FAKE-TEST-KEY", "X-Auth-App-Key");
$config  = [];
$dataDir = ' . var_export($tmp, true) . ';
$kyc     = new StubKyc(json_decode(' . var_export(json_encode($opts['result'] ?? []), true) . ', true));
$notify  = new StubNotify();
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST = json_decode(' . var_export(json_encode($post), true) . ', true);
$_FILES = [];
include ' . var_export($root . '/includes/post/post_kyc.php', true) . ';
echo json_encode(["fell_through" => true]);
');
    exec('php ' . escapeshellarg($f) . ' 2>&1', $o);
    $o = implode("\n", $o);
    $lines = explode("\n", trim($o));
    $j = json_decode((string)end($lines), true);   // the handler's decision is the last line
    return is_array($j) ? $j + ['_out' => $o] : ['_out' => $o];
}
$submitted = ['action' => 'kyc_submit'];
$r = run_post($submitted, ['result' => ['success' => true, 'message' => 'saved, NOT in the CRM yet',
    'data' => ['id' => 1, 'crm_client_id' => null, 'crm_sync_status' => 'pending']]]);
is_(($r['flash']['type'] ?? '') === 'warning', 'saved here but not in uCRM: amber, not green', $r['_out']);
$r = run_post($submitted, ['result' => ['success' => true, 'message' => 'Customer registered!',
    'data' => ['id' => 1, 'crm_client_id' => '901', 'crm_sync_status' => 'synced']]]);
is_(($r['flash']['type'] ?? '') === 'success', 'in uCRM: green, as before', $r['_out']);
$r = run_post($submitted, ['result' => ['success' => false, 'message' => 'refused', 'data' => []]]);
is_(($r['flash']['type'] ?? '') === 'danger', 'refused by the plugin: red, as before', $r['_out']);

scenario('uganda');
$kdir = $tmp . '/k';
exec('rm -rf ' . escapeshellarg($kdir));
$ks = SqliteStore::create($kdir);
$ks->save('kyc_applications.json', [pending(1, '+256700000401'), pending(2, '+256700000402')]);
$retry = ['action' => 'kyc_crm_retry', 'app_id' => '1'];
$r = run_post($retry, ['admin' => false, 'dir' => $kdir]);
is_(!empty($r['denied']) && fake_log() === [] && empty(app_of($ks, 1)['crm_client_id']),
    'an agent cannot retry: refused before uCRM is asked anything', $r['_out']);
$r = run_post($retry, ['admin' => true, 'dir' => $kdir]);
$app = app_of($ks, 1);
is_(($r['flash']['type'] ?? '') === 'success' && strpos((string)($r['flash']['msg'] ?? ''), 'client #901') !== false,
    'an admin retries: created, and told the uCRM number', $r['_out']);
is_((string)($app['crm_client_id'] ?? '') === '901' && ($app['crm_sync_by'] ?? '') === 'admin:Tester',
    'the application now carries it, attributed to that admin');
is_(($r['to'] ?? '') === '?page=dashboard&tab=applications', 'and the admin is sent back to Orders');
ctl($port, '/__test/set', ['clients' => [['id' => 555, 'contacts' => [['phone' => '0700 000 402']]]], 'seq' => 900, 'log' => []]);
$r = run_post(['action' => 'kyc_crm_retry', 'app_id' => '2'], ['admin' => true, 'dir' => $kdir]);
is_(($r['flash']['type'] ?? '') === 'warning' && calls('POST', '/clients') === [] && (app_of($ks, 2)['crm_sync_status'] ?? '') === 'review',
    'the same phone in uCRM: amber, nothing created, a person checks', $r['_out']);
$r = run_post(['action' => 'kyc_crm_retry', 'app_id' => '2', 'force' => '1'], ['admin' => true, 'dir' => $kdir]);
is_(($r['flash']['type'] ?? '') === 'success' && count(calls('POST', '/clients')) === 1,
    "'Create in CRM anyway' creates it", $r['_out']);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

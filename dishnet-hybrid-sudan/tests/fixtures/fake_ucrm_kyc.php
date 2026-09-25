<?php
declare(strict_types=1);
/**
 * ████ FAKE uCRM — TEST ONLY ████  (tests/test_kyc_crm_create.php)
 *
 * Two installs, shaped from what was measured:
 *
 *   uganda  the live Uganda uCRM on 25 Sep 2026 (tools read-only, via the
 *           plugin's own key): ONE organization, id 1; client custom fields
 *           1–4 are EFRIS fields (1 = "EFRIS TIN", key efrisTin) and 5 is a
 *           service field. POST /clients naming anything that does not exist
 *           answered 404 {"code":404,"message":"Not Found"} — the exact body
 *           the plugin logged twice.
 *   sudan   the layout the KYC code was written for: organizations 2 and 7,
 *           client fields 1 and 36–43. The field NAMES are this fixture's
 *           assumption (the code only documents "1=Sales Person, 36=Priority,
 *           41=Package, 43=Ref"); nothing under test depends on them except
 *           that none is a tax field.
 *
 * Variants: two_orgs (Uganda fields, organizations 1 and 3), sudan_partial
 * (field 43 missing), sudan_taxfield (field 1 is efrisTin), refuse (every
 * create answers 404), down (everything answers 503).
 *
 * State lives in a temp file per port. /__test/reset?scenario=… resets it,
 * /__test/set (POST JSON) merges keys (existing clients, taken usernames),
 * /__test/log returns every request.
 */
$port      = (string)($_SERVER['SERVER_PORT'] ?? '0');
$stateFile = sys_get_temp_dir() . '/fake_ucrm_kyc_' . $port . '.json';
$state     = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state    += ['scenario' => 'uganda', 'seq' => 900, 'log' => [], 'clients' => [], 'taken' => [], 'payments' => [],
               'refuse_quotes' => false];

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path   = (string)parse_url($uri, PHP_URL_PATH);
parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
$raw    = (string)file_get_contents('php://input');
$body   = json_decode($raw, true);
if (!is_array($body)) $body = [];

function kyc_save(array $state, string $file): void
{
    file_put_contents($file, json_encode($state), LOCK_EX);
}
function kyc_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ── test controls ────────────────────────────────────────────────────────
if ($path === '/__test/ping')  kyc_out(['marker' => 'FAKE-UCRM-KYC', 'token' => (string)getenv('FAKE_UCRM_KYC_TOKEN')]);
if ($path === '/__test/reset') {
    kyc_save(['scenario' => (string)($q['scenario'] ?? 'uganda'), 'seq' => 900, 'log' => [], 'clients' => [],
              'taken' => [], 'payments' => [], 'refuse_quotes' => false], $stateFile);
    kyc_out(['ok' => true]);
}
if ($path === '/__test/set') { kyc_save(array_merge($state, $body), $stateFile); kyc_out(['ok' => true]); }
if ($path === '/__test/log') kyc_out($state['log']);

// ── the install ──────────────────────────────────────────────────────────
$sc = (string)$state['scenario'];
$ugFields = [
    ['id' => 1, 'name' => 'EFRIS TIN',           'key' => 'efrisTin',          'attributeType' => 'client'],
    ['id' => 2, 'name' => 'EFRIS BRN',           'key' => 'efrisBrn',          'attributeType' => 'client'],
    ['id' => 3, 'name' => 'EFRIS NIN',           'key' => 'efrisNin',          'attributeType' => 'client'],
    ['id' => 4, 'name' => 'EFRIS Taxpayer Type', 'key' => 'efrisTaxpayerType', 'attributeType' => 'client'],
    ['id' => 5, 'name' => 'starlinkDetails',     'key' => 'starlinkdetails',   'attributeType' => 'service'],
];
$ssFields = [
    ['id' => 1,  'name' => 'Sales Person', 'key' => 'salesPerson', 'attributeType' => 'client'],
    ['id' => 36, 'name' => 'Priority',     'key' => 'priority',    'attributeType' => 'client'],
    ['id' => 37, 'name' => 'Kit Number',   'key' => 'kitNumber',   'attributeType' => 'client'],
    ['id' => 38, 'name' => 'Kit Qty',      'key' => 'kitQty',      'attributeType' => 'client'],
    ['id' => 39, 'name' => 'Kit Name',     'key' => 'kitName',     'attributeType' => 'client'],
    ['id' => 40, 'name' => 'Kit Unit',     'key' => 'kitUnit',     'attributeType' => 'client'],
    ['id' => 41, 'name' => 'Package',      'key' => 'package',     'attributeType' => 'client'],
    ['id' => 42, 'name' => 'Device ID',    'key' => 'deviceId',    'attributeType' => 'client'],
    ['id' => 43, 'name' => 'Ref',          'key' => 'ref',         'attributeType' => 'client'],
];
if ($sc === 'sudan_partial')  $ssFields = array_values(array_filter($ssFields, fn($f) => $f['id'] !== 43));
if ($sc === 'sudan_taxfield') $ssFields[0] = ['id' => 1, 'name' => 'EFRIS TIN', 'key' => 'efrisTin', 'attributeType' => 'client'];
$isSudan = in_array($sc, ['sudan', 'sudan_partial', 'sudan_taxfield'], true);
$orgs    = $isSudan ? [2, 7] : ($sc === 'two_orgs' ? [1, 3] : [1]);
$fields  = $isSudan ? $ssFields : $ugFields;

// ── every request is logged; only paths under the API are served ──────────
$auth = $_SERVER['HTTP_X_AUTH_APP_KEY'] ?? ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
// Which credential came: the plugin's app key, or an admin token (quotes).
$entry = ['method' => $method, 'path' => $path, 'query' => $q,
          'auth' => isset($_SERVER['HTTP_X_AUTH_APP_KEY']) ? 'app-key' : (isset($_SERVER['HTTP_X_AUTH_TOKEN']) ? 'token' : 'none')];
if ($method !== 'GET') $entry['body'] = $body;
$state['log'][] = $entry;
kyc_save($state, $stateFile);

if (strpos($path, '/api/v2.1/') !== 0) kyc_out(['code' => 404, 'message' => 'Not Found'], 404);
if ($auth === '')                      kyc_out(['code' => 401, 'message' => 'Unauthorized'], 401);
if ($sc === 'down')                    kyc_out(['code' => 503, 'message' => 'Service Unavailable'], 503);
$p = substr($path, strlen('/api/v2.1'));

if ($p === '/organizations' && $method === 'GET') {
    kyc_out(array_map(fn($id) => ['id' => $id, 'name' => 'Organization ' . $id], $orgs));
}
if (preg_match('#^/organizations/(\d+)$#', $p, $m) && $method === 'GET') {
    in_array((int)$m[1], $orgs, true) ? kyc_out(['id' => (int)$m[1]]) : kyc_out(['code' => 404, 'message' => 'Not Found'], 404);
}
if ($p === '/custom-attributes' && $method === 'GET') kyc_out($fields);

if ($p === '/clients' && $method === 'POST') {
    if ($sc === 'refuse') kyc_out(['code' => 404, 'message' => 'Not Found'], 404);
    $known = array_map(fn($f) => (int)$f['id'], array_filter($fields, fn($f) => $f['attributeType'] === 'client'));
    $org   = $body['organizationId'] ?? null;
    $bad   = $org === null || !in_array((int)$org, $orgs, true);
    foreach ((array)($body['attributes'] ?? []) as $a) {
        if (!in_array((int)($a['customAttributeId'] ?? 0), $known, true)) $bad = true;
    }
    if ($bad) kyc_out(['code' => 404, 'message' => 'Not Found'], 404);   // what the live uCRM answered
    if (in_array((string)($body['username'] ?? ''), (array)$state['taken'], true)) {
        kyc_out(['code' => 422, 'message' => 'Validation failed.',
                 'errors' => ['username' => ['This username is already taken.']]], 422);
    }
    $state['seq']++;
    $client = ['id' => $state['seq']] + $body;
    $state['clients'][] = $client;
    $state['taken'][]   = (string)($body['username'] ?? '');
    kyc_save($state, $stateFile);
    kyc_out($client, 201);
}
if ($p === '/clients' && $method === 'GET') {
    // uCRM's search, by phone: last nine digits, like the plugin compares.
    $needle = preg_replace('/[^0-9]/', '', (string)($q['search'] ?? ''));
    $needle = strlen($needle) >= 9 ? substr($needle, -9) : $needle;
    $hits = [];
    foreach ((array)$state['clients'] as $c) {
        foreach ((array)($c['contacts'] ?? []) as $ct) {
            $d = preg_replace('/[^0-9]/', '', (string)($ct['phone'] ?? ''));
            if ($needle !== '' && strlen($d) >= 9 && substr($d, -9) === $needle) { $hits[] = $c; break; }
        }
    }
    kyc_out($needle === '' ? array_slice((array)$state['clients'], 0, 5) : $hits);
}
if (preg_match('#^/clients/(\d+)$#', $p, $m) && $method === 'GET') {
    foreach ((array)$state['clients'] as $c) if ((int)$c['id'] === (int)$m[1]) kyc_out($c);
    kyc_out(['code' => 404, 'message' => 'Not Found'], 404);
}
if (preg_match('#^/clients/(\d+)$#', $p, $m) && $method === 'PATCH') kyc_out(['id' => (int)$m[1]] + $body);
if (preg_match('#^/clients/(\d+)/add-tag/(\d+)$#', $p) && $method === 'PATCH') kyc_out(['ok' => true]);
if ($p === '/documents' && $method === 'POST') kyc_out(['id' => 1], 201);
if (preg_match('#^/clients/(\d+)/quotes$#', $p) && $method === 'POST') {
    if (!empty($state['refuse_quotes'])) kyc_out(['code' => 403, 'message' => 'Forbidden'], 403);
    kyc_out(['id' => 77, 'number' => 'Q-77'], 201);
}
if (preg_match('#^/billing/quotes/(\d+)(/send)?$#', $p)) kyc_out(['id' => 77, 'number' => 'Q-77']);
if ($p === '/payments' && $method === 'GET') kyc_out((array)$state['payments']);
if ($p === '/payments' && $method === 'POST') {
    $state['seq']++;
    $pay = ['id' => $state['seq']] + $body;
    $state['payments'][] = $pay;
    kyc_save($state, $stateFile);
    kyc_out($pay, 201);
}
kyc_out(['code' => 404, 'message' => 'Not Found'], 404);

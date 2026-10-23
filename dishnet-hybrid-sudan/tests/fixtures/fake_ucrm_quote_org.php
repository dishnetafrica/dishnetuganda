<?php
declare(strict_types=1);
/**
 * ████ FAKE uCRM — the quoting organization (5.18.99) — TEST ONLY ████  (tests/test_quote_company.php)
 *
 * A uCRM with organizations, clients and quotes, for the Uganda "always company 1" tests and the South Sudan
 * byte-identity comparison. Every organization, client, phone, address and amount here is invented (TEST names,
 * example.test addresses, +2567000001xx numbers). Nothing leaves the machine.
 *
 * Paths are served with or without the /api/v2.1 prefix. State lives in a temp file per port:
 *   /__test/ping                  {marker, token} — the token is the one the starting process passed (FAKE_UCRM_QO_TOKEN)
 *   /__test/reset                 the default state (two organizations, no client, no quote)
 *   /__test/set     (POST JSON)   merges keys into the state (organizations, clients, quotes, toggles, sequences)
 *   /__test/log                   every request: method, path (without the API prefix), query, auth, key, body
 *   /__test/state                 the whole state (the PDF service's captured HTML is under pdf_html)
 *   /__pdf          (POST)        the Chromium PDF service: keeps the uploaded index.html, answers %PDF…
 *
 * Served: organizations (GET, /{id}); custom-attributes; clients (GET with ?phone= ?search= ?limit=, POST, /{id} GET and
 * PATCH, /{id}/add-tag/{n}); documents (POST); clients/{id}/quotes (POST); billing/quotes (POST, GET, /{id} GET and
 * PATCH, /{id}/send PATCH); quotes/{id} (GET); quotes/{id}/pdf and billing/quotes/{id}/pdf (404 unless pdf_serve);
 * products (GET, logged); service-plans; payments (GET, POST); scheduling/jobs (POST); users/admins (GET).
 *
 * Toggles (each off unless set): orgs_down (503), client_down (503), client_404, client_401, client_403, client_raw (200
 * with a body that is not JSON), quotes_refuse (422 on clients/{id}/quotes), billing_refuse (422), billing_401,
 * billing_500, billing_drop (the answer is cut short: curl reports an error), billing_nonjson (201 with a text body),
 * billing_noid (201 with {}), clients_refuse (POST clients answers 404, as the live uCRM did for a payload it could not
 * place), ignores_org (every POSTed client is filed under organization 2),
 * create_answer_no_org (the POST clients answer carries no organizationId), pdf_serve (uCRM serves a quote PDF).
 */
$port      = (string)($_SERVER['SERVER_PORT'] ?? '0');
$stateFile = sys_get_temp_dir() . '/fake_ucrm_qo_' . $port . '.json';

function qo_default(): array
{
    return [
        'organizations' => [
            ['id' => 1, 'name' => 'TEST Co One', 'selected' => false, 'currencyCode' => 'UGX', 'street1' => 'Plot 1 Test Road',
             'street2' => '', 'city' => 'Testville One', 'phone' => '+256700000101', 'email' => 'one@example.test',
             'website' => 'one.example.test', 'taxId' => 'TEST-TIN-1', 'registrationNumber' => 'TEST-REG-1'],
            ['id' => 2, 'name' => 'TEST Co Two', 'selected' => true, 'currencyCode' => 'UGX', 'street1' => 'Plot 2 Test Road',
             'street2' => '', 'city' => 'Testville Two', 'phone' => '+256700000102', 'email' => 'two@example.test',
             'website' => 'two.example.test', 'taxId' => 'TEST-TIN-2', 'registrationNumber' => 'TEST-REG-2'],
        ],
        'custom_attributes' => [
            ['id' => 1, 'name' => 'EFRIS TIN', 'key' => 'efrisTin', 'attributeType' => 'client'],
            ['id' => 5, 'name' => 'starlinkDetails', 'key' => 'starlinkdetails', 'attributeType' => 'service'],
        ],
        'clients' => [], 'quotes' => [], 'payments' => [], 'products' => [], 'jobs' => [],
        'client_seq' => 900, 'quote_seq' => 76, 'billing_seq' => 3499, 'job_seq' => 5000,
        'log' => [], 'pdf_html' => [],
    ];
}

$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += qo_default();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '/');
$full   = (string)parse_url($uri, PHP_URL_PATH);
$qs     = (string)parse_url($uri, PHP_URL_QUERY);
parse_str($qs, $q);
$raw    = (string)file_get_contents('php://input');
$body   = json_decode($raw, true);
if (!is_array($body)) $body = [];

function qo_save(): void
{
    file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state']), LOCK_EX);
}
function qo_out($data, int $code = 200): void
{
    qo_save();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
function qo_on(string $k): bool { return !empty($GLOBALS['state'][$k]); }
function qo_last9(string $s): string { $d = preg_replace('/\D/', '', $s); return strlen($d) >= 9 ? substr($d, -9) : $d; }

// ── test controls (not logged) ───────────────────────────────────────────────
if ($full === '/__test/ping')  { echo json_encode(['marker' => 'FAKE-UCRM-QO', 'token' => (string)getenv('FAKE_UCRM_QO_TOKEN')]); exit; }
if ($full === '/__test/reset') { $state = qo_default(); qo_out(['ok' => true]); }
if ($full === '/__test/set')   { $state = array_merge($state, $body); qo_out(['ok' => true]); }
if ($full === '/__test/log')   { echo json_encode($state['log']); exit; }
if ($full === '/__test/state') { echo json_encode($state); exit; }

// ── the Chromium PDF service: keeps what it was asked to print ──────────────
if ($full === '/__pdf') {
    $html = '';
    foreach ($_FILES as $f) {
        if (is_array($f) && is_file((string)($f['tmp_name'] ?? ''))) { $html = (string)file_get_contents($f['tmp_name']); break; }
    }
    $state['pdf_html'][] = $html;
    qo_save();
    header('Content-Type: application/pdf');
    echo "%PDF-1.4\n% FAKE PDF SERVICE — TEST ONLY\n" . str_repeat('0', 300) . "\n%%EOF\n";
    exit;
}

// ── every other request is logged ───────────────────────────────────────────
$p = (string)preg_replace('#^/api/v[0-9.]+#', '', $full);
$auth = isset($_SERVER['HTTP_X_AUTH_APP_KEY']) ? 'app-key' : (isset($_SERVER['HTTP_X_AUTH_TOKEN']) ? 'token' : 'none');
$key  = (string)($_SERVER['HTTP_X_AUTH_APP_KEY'] ?? $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
$entry = ['method' => $method, 'path' => $p, 'query' => $qs, 'auth' => $auth, 'key' => $key];
if ($method !== 'GET') $entry['body'] = $body;
$state['log'][] = $entry;
qo_save();

if ($auth === 'none') qo_out(['code' => 401, 'message' => 'Unauthorized'], 401);
$orgIds = array_map(function ($o) { return (int)($o['id'] ?? 0); }, (array)$state['organizations']);

// ── organizations ────────────────────────────────────────────────────────────
if ($p === '/organizations' && $method === 'GET') {
    if (qo_on('orgs_down')) qo_out(['code' => 503, 'message' => 'Service Unavailable'], 503);
    qo_out(array_values((array)$state['organizations']));
}
if (preg_match('#^/organizations/(\d+)$#', $p, $m) && $method === 'GET') {
    if (qo_on('orgs_down')) qo_out(['code' => 503, 'message' => 'Service Unavailable'], 503);
    foreach ((array)$state['organizations'] as $o) if ((int)$o['id'] === (int)$m[1]) qo_out($o);
    qo_out(['code' => 404, 'message' => 'Not Found'], 404);
}
if ($p === '/custom-attributes' && $method === 'GET') qo_out(array_values((array)$state['custom_attributes']));

// ── clients ──────────────────────────────────────────────────────────────────
if ($p === '/clients' && $method === 'GET') {
    $rows = array_values((array)$state['clients']);
    $needle = qo_last9((string)($q['phone'] ?? $q['search'] ?? ''));
    if (isset($q['phone']) || isset($q['search'])) {
        $rows = array_values(array_filter($rows, function ($c) use ($needle) {
            if ($needle === '') return false;
            foreach ((array)($c['contacts'] ?? []) as $ct) if (qo_last9((string)($ct['phone'] ?? '')) === $needle) return true;
            return false;
        }));
    }
    if (isset($q['limit'])) $rows = array_slice($rows, 0, max(0, (int)$q['limit']));
    qo_out($rows);
}
if ($p === '/clients' && $method === 'POST') {
    if (qo_on('clients_refuse')) qo_out(['code' => 404, 'message' => 'Not Found'], 404);
    $org = $body['organizationId'] ?? null;
    if ($org === null || !in_array((int)$org, $orgIds, true)) qo_out(['code' => 404, 'message' => 'Not Found'], 404);
    $known = [];
    foreach ((array)$state['custom_attributes'] as $f) if (($f['attributeType'] ?? '') === 'client') $known[] = (int)$f['id'];
    foreach ((array)($body['attributes'] ?? []) as $a) {
        if (!in_array((int)($a['customAttributeId'] ?? 0), $known, true)) qo_out(['code' => 404, 'message' => 'Not Found'], 404);
    }
    $state['client_seq']++;
    $client = ['id' => (int)$state['client_seq']] + $body;
    if (qo_on('ignores_org')) $client['organizationId'] = 2;
    $state['clients'][] = $client;
    $answer = $client;
    if (qo_on('create_answer_no_org')) unset($answer['organizationId']);
    qo_out($answer, 201);
}
if (preg_match('#^/clients/(\d+)$#', $p, $m) && $method === 'GET') {
    if (qo_on('client_down')) qo_out(['code' => 503, 'message' => 'Service Unavailable'], 503);
    if (qo_on('client_401'))  qo_out(['code' => 401, 'message' => 'Unauthorized'], 401);
    if (qo_on('client_403'))  qo_out(['code' => 403, 'message' => 'Forbidden'], 403);
    if (qo_on('client_404'))  qo_out(['code' => 404, 'message' => 'Not Found'], 404);
    if (qo_on('client_raw'))  { qo_save(); http_response_code(200); header('Content-Type: text/plain'); echo 'TEST: not JSON'; exit; }
    foreach ((array)$state['clients'] as $c) if ((int)$c['id'] === (int)$m[1]) qo_out($c);
    qo_out(['code' => 404, 'message' => 'Not Found'], 404);
}
if (preg_match('#^/clients/(\d+)$#', $p, $m) && $method === 'PATCH') {
    foreach ((array)$state['clients'] as $i => $c) {
        if ((int)$c['id'] === (int)$m[1]) { $state['clients'][$i] = array_merge($c, $body); qo_out($state['clients'][$i]); }
    }
    qo_out(['code' => 404, 'message' => 'Not Found'], 404);
}
if (preg_match('#^/clients/(\d+)/add-tag/(\d+)$#', $p) && $method === 'PATCH') qo_out(['ok' => true]);
if (preg_match('#^/clients/(\d+)/services$#', $p) && $method === 'GET') qo_out([]);
if ($p === '/documents' && $method === 'POST') qo_out(['id' => 1], 201);

// ── quotes ───────────────────────────────────────────────────────────────────
$lines = function (array $items): array {
    $out = []; $total = 0.0;
    foreach ($items as $it) {
        $line = (float)($it['quantity'] ?? 1) * (float)($it['price'] ?? 0);
        $total += $line;
        $out[] = ['label' => (string)($it['label'] ?? 'Item'), 'quantity' => (float)($it['quantity'] ?? 1),
                  'price' => (float)($it['price'] ?? 0), 'total' => $line, 'unit' => (string)($it['unit'] ?? '')];
    }
    return [$out, $total];
};
if (preg_match('#^/clients/(\d+)/quotes$#', $p, $m) && $method === 'POST') {
    if (qo_on('quotes_refuse')) qo_out(['code' => 422, 'message' => 'Validation failed.'], 422);
    $id = ++$state['quote_seq'];
    [$items, $total] = $lines((array)($body['items'] ?? []));
    $quote = ['id' => $id, 'number' => 'Q-' . $id, 'clientId' => (int)$m[1], 'status' => 0, 'items' => $items,
              'total' => $total, 'notes' => (string)($body['notes'] ?? ''), 'createdDate' => date('c')];
    $state['quotes'][(string)$id] = $quote;
    qo_out($quote, 201);
}
if ($p === '/billing/quotes' && $method === 'POST') {
    if (qo_on('billing_refuse')) qo_out(['code' => 422, 'message' => 'Validation failed.', 'errors' => ['invoiceItems' => ['TEST']]], 422);
    if (qo_on('billing_401'))    qo_out(['code' => 401, 'message' => 'Unauthorized'], 401);
    if (qo_on('billing_500'))    qo_out(['code' => 500, 'message' => 'Internal Server Error'], 500);
    if (qo_on('billing_drop'))   { qo_save(); http_response_code(201); header('Content-Type: application/json'); header('Content-Length: 4000'); echo '{"id":'; flush(); exit; }
    if (qo_on('billing_nonjson')) { qo_save(); http_response_code(201); header('Content-Type: text/plain'); echo 'TEST: created'; exit; }
    if (qo_on('billing_noid'))   qo_out(new stdClass(), 201);
    $id = ++$state['billing_seq'];
    [$items, $total] = $lines((array)($body['invoiceItems'] ?? []));
    $quote = ['id' => $id, 'number' => 'Q-' . $id, 'clientId' => (int)($body['clientId'] ?? 0), 'status' => 1, 'items' => $items,
              'total' => $total, 'notes' => (string)($body['notes'] ?? ''), 'createdDate' => date('c')];
    $state['quotes'][(string)$id] = $quote;
    qo_out($quote, 201);
}
if ($p === '/billing/quotes' && $method === 'GET') qo_out(array_values((array)$state['quotes']));
if (preg_match('#^/(?:billing/)?quotes/(\d+)/pdf$#', $p, $m) && $method === 'GET') {
    if (!qo_on('pdf_serve') || !isset($state['quotes'][$m[1]])) qo_out(['code' => 404, 'message' => 'Not Found'], 404);
    qo_save();
    header('Content-Type: application/pdf');
    echo "%PDF-1.4\n% FAKE uCRM quotation Q-{$m[1]} — TEST ONLY\n" . str_repeat('0', 300) . "\n%%EOF\n";
    exit;
}
if (preg_match('#^/billing/quotes/(\d+)/send$#', $p) && $method === 'PATCH') qo_out(new stdClass());
if (preg_match('#^/(?:billing/)?quotes/(\d+)$#', $p, $m)) {
    $quote = $state['quotes'][$m[1]] ?? null;
    if ($quote === null) qo_out(['code' => 404, 'message' => 'Not Found'], 404);
    if ($method === 'PATCH') { $quote = array_merge($quote, $body); $state['quotes'][$m[1]] = $quote; }
    qo_out($quote);
}

// ── the rest the paths under test touch ────────────────────────────────────────
if ($p === '/products' && $method === 'GET') qo_out(array_values((array)$state['products']));
if ($p === '/products' || preg_match('#^/products/\d+$#', $p)) qo_out(['id' => 1] + $body, $method === 'POST' ? 201 : 200);
if ($p === '/service-plans' && $method === 'GET') qo_out([]);
if ($p === '/payments' && $method === 'GET') qo_out(array_values((array)$state['payments']));
if ($p === '/payments' && $method === 'POST') {
    $state['client_seq']++;
    $pay = ['id' => (int)$state['client_seq']] + $body;
    $state['payments'][] = $pay;
    qo_out($pay, 201);
}
if ($p === '/scheduling/jobs' && $method === 'POST') {
    $id = ++$state['job_seq'];
    $state['jobs'][] = ['id' => $id] + $body;
    qo_out(['id' => $id] + $body, 201);
}
if ($p === '/users/admins' && $method === 'GET') qo_out([]);
qo_out(['code' => 404, 'message' => 'FAKE-UCRM-QO: not simulated: ' . $method . ' ' . $p], 404);

<?php
declare(strict_types=1);
/**
 * ████ FAKE uCRM (NOTIFICATIONS) — TEST ONLY ████
 *
 * A uCRM the notification tests seed with exactly the records a case needs: clients, payments, invoices, credit
 * notes, quotes, services, and the /options document. Every request is recorded — method, path, query and body — so
 * a test can prove what the plugin asked for and, as important, what it never did (no PATCH to /options, no second
 * credit note).
 *
 *   POST /__test/seed      {"clients":{"7":{…}}, "payments":{…}, "invoices":{…}, "credit_notes":{…}, "quotes":{…},
 *                           "services":{…}, "options":{…}, "fail":{"PATCH /options":500}}
 *   GET  /__test/requests  every request since the seed
 *   GET  /__test/state     marker + the records
 *
 * Paths are answered with and without the "billing/" prefix, as the plugin asks both. Every value is TEST data.
 *
 *     php -S 127.0.0.1:PORT tests/fixtures/fake_ucrm_notify.php
 */
$stateFile = sys_get_temp_dir() . '/fake_ucrm_notify_' . ($_SERVER['SERVER_PORT'] ?? '0') . '.json';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['clients' => [], 'payments' => [], 'invoices' => [], 'credit_notes' => [], 'quotes' => [],
           'services' => [], 'options' => [], 'fail' => [], 'requests' => [], 'seq' => 5000];

function fn_out($data, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json');
    file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state']));
    echo json_encode($data);
    exit;
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '');
$path   = parse_url($uri, PHP_URL_PATH) ?: '';
$q      = [];
parse_str((string)parse_url($uri, PHP_URL_QUERY), $q);
$raw    = (string)file_get_contents('php://input');
$json   = json_decode($raw, true);

if ($path === '/__test/state')    fn_out(['marker' => 'FAKE-UCRM-NOTIFY'] + $state);
if ($path === '/__test/requests') fn_out(['requests' => $state['requests'], 'count' => count($state['requests'])]);
if ($path === '/__test/seed') {
    $seed = is_array($json) ? $json : [];
    $state = ['clients' => [], 'payments' => [], 'invoices' => [], 'credit_notes' => [], 'quotes' => [],
              'services' => [], 'options' => [], 'fail' => [], 'requests' => [], 'seq' => 5000];
    foreach (['clients', 'payments', 'invoices', 'credit_notes', 'quotes', 'services', 'options', 'fail'] as $k) {
        if (isset($seed[$k]) && is_array($seed[$k])) $state[$k] = $seed[$k];
    }
    fn_out(['seeded' => true]);
}

$state['requests'][] = ['method' => $method, 'path' => $path, 'query' => $q, 'body' => is_array($json) ? $json : $raw];

// A configured failure, by "METHOD /path".
$failKey = $method . ' ' . $path;
if (isset($state['fail'][$failKey])) fn_out(['message' => 'FAKE-UCRM-NOTIFY failure (test control)'], (int)$state['fail'][$failKey]);

$p = preg_replace('#^/billing/#', '/', $path);   // billing/invoices → invoices, and so on

$one = function (string $kind, string $id) use (&$state) {
    return $state[$kind][$id] ?? null;
};
$filterList = function (array $rows) use ($q): array {
    $out = [];
    $st = [];
    foreach (['statuses', 'status'] as $k) if (isset($q[$k])) foreach ((array)$q[$k] as $v) $st[] = (int)$v;
    foreach ($rows as $r) {
        if (isset($q['clientId']) && (int)($r['clientId'] ?? 0) !== (int)$q['clientId']) continue;
        if ($st && !in_array((int)($r['status'] ?? -1), $st, true)) continue;
        $out[] = $r;
    }
    return $out;
};

// ── clients ──────────────────────────────────────────────────────────────────
if (preg_match('#^/clients/(\d+)$#', $p, $m)) {
    if ($method === 'PATCH') fn_out(($state['clients'][$m[1]] ?? []) + ['id' => (int)$m[1]]);
    $c = $one('clients', $m[1]);
    $c === null ? fn_out(['message' => 'Not found'], 404) : fn_out($c);
}
if (preg_match('#^/clients/(\d+)/payments$#', $p, $m)) {
    fn_out(array_values(array_filter($state['payments'], fn($x) => (int)($x['clientId'] ?? 0) === (int)$m[1])));
}
if (preg_match('#^/clients/(\d+)/services$#', $p, $m)) {
    fn_out(array_values(array_filter($state['services'], fn($x) => (int)($x['clientId'] ?? 0) === (int)$m[1])));
}
if ($p === '/clients/services') fn_out($filterList(array_values($state['services'])));
if (preg_match('#^/clients/services/(\d+)$#', $p, $m)) {
    $s = $one('services', $m[1]);
    $s === null ? fn_out(['message' => 'Not found'], 404) : fn_out($s);
}
if ($p === '/clients') fn_out([]);

// ── payments ─────────────────────────────────────────────────────────────────
if (preg_match('#^/payments/(\d+)$#', $p, $m)) {
    $x = $one('payments', $m[1]);
    $x === null ? fn_out(['message' => 'Not found'], 404) : fn_out($x);
}
if ($p === '/payments' && $method === 'POST') {
    $id = ++$state['seq'];
    $row = (is_array($json) ? $json : []) + ['id' => $id];
    $row['id'] = $id;
    $state['payments'][(string)$id] = $row;
    fn_out($row, 201);
}
if ($p === '/payments') fn_out($filterList(array_values($state['payments'])));

// ── invoices ─────────────────────────────────────────────────────────────────
if (preg_match('#^/invoices/(\d+)/pdf$#', $p, $m)) {
    if ($one('invoices', $m[1]) === null) fn_out(['message' => 'Not found'], 404);
    file_put_contents($stateFile, json_encode($state));
    header('Content-Type: application/pdf');
    echo "%PDF-1.4\n%FAKE-UCRM-NOTIFY-INVOICE-{$m[1]}\n" . str_repeat("0 0 obj << /Type /Fake >> endobj\n", 6) . "%%EOF\n";
    exit;
}
if (preg_match('#^/invoices/(\d+)$#', $p, $m)) {
    $x = $one('invoices', $m[1]);
    $x === null ? fn_out(['message' => 'Not found'], 404) : fn_out($x);
}
if ($p === '/invoices') fn_out($filterList(array_values($state['invoices'])));

// ── credit notes ─────────────────────────────────────────────────────────────
if ($p === '/credit-notes' && $method === 'POST') {
    $id  = ++$state['seq'];
    $row = (is_array($json) ? $json : []) + ['id' => $id];
    $row['id'] = $id;
    $row['number'] = 'CN-' . $id;
    $total = 0.0;
    foreach ((array)($row['items'] ?? []) as $it) $total += (float)($it['price'] ?? 0) * (float)($it['quantity'] ?? 1);
    $row['total'] = $total;
    $state['credit_notes'][(string)$id] = $row;
    fn_out($row, 201);
}
if (preg_match('#^/credit-notes/(\d+)$#', $p, $m)) {
    $x = $one('credit_notes', $m[1]);
    $x === null ? fn_out(['message' => 'Not found'], 404) : fn_out($x);
}

// ── quotes ───────────────────────────────────────────────────────────────────
if (preg_match('#^/quotes/(\d+)/send$#', $p, $m)) fn_out(['id' => (int)$m[1], 'sent' => true]);
if (preg_match('#^/quotes/(\d+)$#', $p, $m)) {
    if ($method === 'PATCH') fn_out(($state['quotes'][$m[1]] ?? []) + ['id' => (int)$m[1]]);
    $x = $one('quotes', $m[1]);
    $x === null ? fn_out(['message' => 'Not found'], 404) : fn_out($x);
}

// ── options (uCRM's settings document) ───────────────────────────────────────
if ($p === '/options') {
    if ($method === 'PATCH') fn_out(array_merge($state['options'], is_array($json) ? $json : []));
    fn_out($state['options']);
}

// ── scheduling (the staff brief) ─────────────────────────────────────────────
if ($p === '/scheduling/jobs') fn_out([]);

fn_out(['message' => 'FAKE-UCRM-NOTIFY: path not simulated: ' . $method . ' ' . $path], 404);

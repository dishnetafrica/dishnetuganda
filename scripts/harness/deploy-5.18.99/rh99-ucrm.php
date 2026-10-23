<?php
// 5.18.99's rehearsal: a stand-in uCRM — synthetic, on 127.0.0.1, never the network. The rehearsal serves it with
// `php -S 127.0.0.1:<port> <its own copy of this file>` from a folder of its own (the marker it looks for at its start:
// a stand-in left up by an earlier run, its parent gone, is refused, never reused). Its state is state.json beside it,
// written by the rehearsal (atomically); its record is requests.log beside it, one line per request:
//
//   <METHOD> <the path after the API prefix>[?<the query's keys, never their values>] <app-key|token|none>
//
// — never a key's value, never a body. It answers the four reads the deploy script's quoting checks make (SPEC-quote-
// company §6), and nothing else but 404:
//   GET organizations                the organizations (the token client's own list when state.token_orgs is set)
//   GET clients/{id}                 one client: id, organizationId, a name, a contact — 404 for an id it does not hold
//   GET clients?limit=…              every client it holds
//   GET billing/quotes?createdDateFrom=…   the quotes it holds, by id
// Every other method and path is recorded and answered 404: a request that is not a GET reaches the record, which is
// how the rehearsal shows that none ever does. Its auth is uCRM's: X-Auth-App-Key equal to state.app_key, or
// x-auth-token equal to state.token; anything else 401. Toggles, each off unless set: app_401 (the plugin's own key
// refused), token_401 (the admin token refused), orgs_500 (organizations unreadable), leaklog (a path: every GET of a
// client appends a line naming that client and "(deploy-check)" to it — a refusal line written to the live plugin log,
// which Q-CA3 must see), flip ({"<client id>": <organization>}: the FIRST GET of that client answers its own
// organization, every later one the organization named — a client moved in uCRM between Q-CA3's discovery of it and
// its check; the first GET is remembered in flip-seen-<id> beside this file, which the rehearsal removes whenever it
// writes the state). Every name, contact and key it serves is a marked secret of the rehearsal's own (RH99SECRET-…,
// rh99-…): the rehearsal searches every terminal, log, state file and sheet for them, and must find none.
declare(strict_types=1);
$dir = __DIR__;
$st = json_decode((string)@file_get_contents($dir . '/state.json'), true);
if (!is_array($st)) $st = [];
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$path = (string)parse_url($uri, PHP_URL_PATH);
$qs = (string)parse_url($uri, PHP_URL_QUERY);
$q = [];
parse_str($qs, $q);
if ($path === '/__rh99/ping') { header('Content-Type: text/plain'); echo 'rh99-ucrm-ready'; exit; }   // the rehearsal's own probe: not recorded
$p = (string)preg_replace('#^.*?/api/v[0-9.]+#', '', $path);
$app = (string)($_SERVER['HTTP_X_AUTH_APP_KEY'] ?? '');
$tok = (string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
$kind = $app !== '' ? 'app-key' : ($tok !== '' ? 'token' : 'none');
$keys = implode(',', array_map('strval', array_keys($q)));
@file_put_contents($dir . '/requests.log', $method . ' ' . ($p === '' ? '/' : $p) . ($keys !== '' ? '?' . $keys : '') . ' ' . $kind . "\n", FILE_APPEND | LOCK_EX);
$out = function ($d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($d);
    exit;
};
$want = function (string $k) use ($st): string { return is_string($st[$k] ?? null) ? $st[$k] : ''; };
$ok = ($kind === 'app-key' && $want('app_key') !== '' && hash_equals($want('app_key'), $app) && empty($st['app_401']))
   || ($kind === 'token' && $want('token') !== '' && hash_equals($want('token'), $tok) && empty($st['token_401']));
if (!$ok) $out(['code' => 401, 'message' => 'Unauthorized'], 401);
if ($method !== 'GET') $out(['code' => 404, 'message' => 'not served by the rehearsal'], 404);
$client = function (int $id, array $c): array {
    return ['id' => $id, 'organizationId' => (isset($c['org']) && is_int($c['org'])) ? $c['org'] : null,
            'firstName' => (string)($c['first'] ?? ''), 'lastName' => (string)($c['last'] ?? ''),
            'contacts' => [['phone' => (string)($c['phone'] ?? ''), 'email' => (string)($c['email'] ?? '')]]];
};
if ($p === '/organizations') {
    if (!empty($st['orgs_500'])) $out(['code' => 500, 'message' => 'Internal Server Error'], 500);
    $orgs = ($kind === 'token' && is_array($st['token_orgs'] ?? null)) ? $st['token_orgs'] : (array)($st['orgs'] ?? []);
    $out(array_values($orgs));
}
if (preg_match('#^/clients/([0-9]+)$#', $p, $m)) {
    $id = (int)$m[1];
    $c = $st['clients'][(string)$id] ?? null;
    if (!is_array($c)) $out(['code' => 404, 'message' => 'Not Found'], 404);
    $fl = $st['flip'][(string)$id] ?? null;
    if (is_int($fl)) {
        $seen = $dir . '/flip-seen-' . $id;
        if (is_file($seen)) $c['org'] = $fl; else @touch($seen);
    }
    $leak = $want('leaklog');
    if ($leak !== '') @file_put_contents($leak, '[QuoteOrganization] no quote for client #' . $id . " (deploy-check): planted by the rehearsal's stand-in uCRM\n", FILE_APPEND);
    $out($client($id, $c));
}
if ($p === '/clients') {
    $l = [];
    foreach ((array)($st['clients'] ?? []) as $k => $c) if (is_array($c)) $l[] = $client((int)$k, $c);
    $out($l);
}
if ($p === '/billing/quotes') {
    $l = [];
    foreach ((array)($st['quotes'] ?? []) as $x) $l[] = ['id' => (int)$x, 'number' => 'RH99-Q' . (int)$x, 'createdDate' => gmdate('Y-m-d\TH:i:sO')];
    $out($l);
}
$out(['code' => 404, 'message' => 'Not Found'], 404);

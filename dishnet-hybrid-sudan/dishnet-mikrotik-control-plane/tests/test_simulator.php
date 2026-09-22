<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Network\SignalReport;
use Dn\Plugin\Simulator;

/**
 * The simulated estate must be COHERENT and OBVIOUSLY SYNTHETIC.
 *
 * Both halves are lessons from a real defect. The panel was previously pointed
 * at the test database, which tests/run.sh drops and recreates on every run, so
 * what it displayed was fixture debris — the Routers list said "no routers"
 * while Router Detail showed a router, and the identifiers on screen
 * (WAN-UNSET-1, 4be1d3c3) looked like stray production values.
 *
 * This file drives the REAL CLI against a throwaway database, so it proves the
 * documented command works rather than testing a private method.
 */

$root = dirname(__DIR__);
$host = getenv('DNB_PGHOST') ?: '/var/tmp';
$port = getenv('DNB_PGPORT') ?: '55432';
$db   = 'dnb_simtest';
$dsn  = "pgsql:host={$host};port={$port};dbname={$db}";
$env  = 'DNB_DSN=' . escapeshellarg($dsn) . ' DNB_TOKEN_PEPPER=simtest';

$psql = static function (string $sql, string $on = 'postgres') use ($host, $port) {
    $cmd = sprintf('PGHOST=%s PGPORT=%s psql -U dnb -d %s -At -c %s 2>&1',
        escapeshellarg($host), escapeshellarg($port), escapeshellarg($on), escapeshellarg($sql));
    exec($cmd, $out, $code);
    return [$out, $code];
};

$psql("DROP DATABASE IF EXISTS {$db}");
$psql("CREATE DATABASE {$db}");

// ===========================================================================
t('1. THE DOCUMENTED COMMANDS ACTUALLY RUN');

exec("cd " . escapeshellarg($root) . " && {$env} php plugin/bin/plugin.php install 2>&1", $o1, $c1);
is_($c1, 0, 'plugin install succeeds against a fresh database');
is_(str_contains(implode("\n", $o1), 'verified'), true, 'and verifies its own effect');

exec("cd " . escapeshellarg($root) . " && {$env} php plugin/bin/plugin.php simulate 2>&1", $o2, $c2);
$log = implode("\n", $o2);
is_($c2, 0, 'plugin simulate succeeds');
is_(str_contains($log, 'simulated routers'), true, 'and reports what it built');

t('1b. it refuses to build twice, and refuses alongside real bindings');
exec("cd " . escapeshellarg($root) . " && {$env} php plugin/bin/plugin.php simulate 2>&1", $o3, $c3);
is_($c3, 2, 'a second simulate without --again is refused');
exec("cd " . escapeshellarg($root) . " && {$env} DN_ALLOW_REAL_BINDINGS=yes-f6b-authorized "
     . "php plugin/bin/plugin.php simulate --again 2>&1", $o4, $c4);
is_($c4, 2, 'and simulate is refused outright when real bindings are enabled');
is_(str_contains(implode("\n", $o4), 'Refusing'), true,
    'a simulated router must never share a process with a real adapter');

// ===========================================================================
t('2. THE ESTATE IS COHERENT');

$pdo = new PDO($dsn, 'postgres', 'postgres-local-dev',
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$q = static fn(string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$one = static fn(string $sql) => $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

$devices = $q("SELECT * FROM mt_devices ORDER BY serial");
is_(count($devices), 5, 'five routers exist');
is_(count($q("SELECT 1 FROM mt_customers WHERE name LIKE 'SIM-CUST-%'")), 3, 'three customers');
is_(count($q("SELECT 1 FROM mt_sites WHERE name LIKE 'SIM-SITE-%'")) >= 5, true, 'five or more sites');
is_(count($q("SELECT 1 FROM mt_vouchers")) > 0, true, 'vouchers were issued');
is_(count($q("SELECT 1 FROM mt_sessions")) > 0, true, 'sessions were ingested');
is_(count($q("SELECT 1 FROM mt_uplink_samples")) > 0, true, 'uplink samples were recorded');
is_(count($q("SELECT 1 FROM mt_intents")) > 0, true, 'provisioning jobs were queued');

t('2b. every router the LIST would show is a router the DETAIL can open');
// This is the contradiction that prompted this file: the list and the detail
// read the same projection, so a router in one is a router in the other.
$listed = array_column($q("SELECT id FROM mt_admin_routers()"), 'id');
is_(count($listed), 5, 'the list projection returns all five');
foreach ($listed as $id) {
    $d = $q("SELECT id FROM mt_admin_router('{$id}')");
    is_(count($d), 1, "the detail projection opens {$id}");
}
is_(count($q("SELECT id FROM mt_admin_router('00000000-0000-4000-8000-000000000000')")), 0,
    'and returns nothing for an id the list never offered');

t('2c. lifecycle states are varied and every one is legal');
$states = array_column($devices, 'state');
is_(count(array_unique($states)) >= 4, true,
    'at least four distinct lifecycle states: ' . implode(', ', array_unique($states)));
// Built by walking the real transition trigger, so an illegal state could not
// have been reached — if one appears, the trigger let it through.
$legal = ['registered','staged','shipped','connected','provisioned','active',
          'orphaned','diverged','decommissioned'];
foreach ($states as $st) { is_(in_array($st, $legal, true), true, "state {$st} is a legal state"); }

t('2d. the estate was BUILT, not inserted — the audit trail proves it');
$audit = $q("SELECT DISTINCT action FROM mt_audit_log ORDER BY action");
$actions = array_column($audit, 'action');
foreach (['customer.created', 'device.registered', 'device.assigned', 'device.state_changed'] as $a) {
    is_(in_array($a, $actions, true), true, "the acts wrote {$a} themselves");
}
is_(count($q("SELECT 1 FROM mt_audit_log WHERE actor LIKE 'sim:%'")) > 0, true,
    'attributed to a simulator actor, never to a real staff name');

// ===========================================================================
t('3. NOTHING HERE CAN BE MISTAKEN FOR A PRODUCTION RECORD');

foreach ($devices as $d) {
    is_(str_starts_with($d['serial'], Simulator::MARK), true,
        "router serial announces itself: {$d['serial']}");
    is_(str_starts_with((string) $d['wg_pubkey'], Simulator::MARK), true,
        'and so does its WireGuard public key');
}
foreach ($q("SELECT name FROM mt_customers") as $c) {
    is_(str_starts_with($c['name'], Simulator::MARK), true, "customer names too: {$c['name']}");
}
foreach ($q("SELECT nas_identifier FROM mt_sessions WHERE nas_identifier IS NOT NULL") as $s) {
    is_(str_starts_with($s['nas_identifier'], Simulator::MARK), true, 'and NAS identifiers');
}
// The specific shape that went wrong before: a device with no name, carrying a
// serial that reads like a stray production value.
is_(count($q("SELECT 1 FROM mt_devices WHERE serial LIKE 'WAN-%' OR serial LIKE '%UNSET%'")), 0,
    'no WAN-UNSET-style identifier survives anywhere');

t('3b. no real credential or endpoint is present');
$blob = strtolower(implode("\n", array_map(
    static fn($r) => implode(' ', array_map('strval', $r)),
    array_merge($devices, $q("SELECT * FROM mt_sessions")))));
foreach (['password', 'secret', 'api-key', 'https://', 'ssh'] as $leak) {
    is_(str_contains($blob, $leak), false, "the simulated estate holds no {$leak}");
}

// ===========================================================================
t('4. NO REAL NETWORK CALL IS POSSIBLE FROM THE SIMULATOR');

// Against CODE, not comments. The docblock says the simulator does not write
// last_seen_at, and naming the column is how it says so — this guard caught its
// own author's prose, which is the fifth time in this project.
$src = strip_php_comments(file_get_contents($root . '/src/Plugin/Simulator.php'));
is_(str_contains($src, 'last_seen_at'), false,
    'META: the stripper removed the docblock before this guard ran');
foreach (['curl_', 'fsockopen', 'stream_socket_client', 'file_get_contents(\'http',
          'RouterOs', 'RestClient'] as $net) {
    is_(str_contains($src, $net), false, "the simulator makes no {$net} call");
}
is_(preg_match('/last_seen_at\s*=|INSERT[^;]{0,200}last_seen_at/i', $src), 0,
    'and never writes last_seen_at to make the panel look alive');

// ===========================================================================
t('5. THE PANEL CARRIES NO CREDENTIAL AND NO ROUTER ENDPOINT');

$stripJs = static function (string $s): string {
    $s = preg_replace('#/\*.*?\*/#s', ' ', $s);
    return preg_replace('#(^|[^:])//.*$#m', '$1', $s);
};
$panel = implode("\n", array_map(
    static fn($f) => $stripJs(file_get_contents($f)),
    glob($root . '/panel/*.js')));
// 'RADIUS' appears as screen copy explaining why a per-router session count is
// not derivable, which is legitimate visible text. What must not appear is a
// credential, an AAA table, or a router endpoint.
foreach (['password', 'secret', 'api_key', 'apikey', 'Authorization',
          'radius_ref', 'radcheck', 'radreply', 'rest/', '8728', '8729'] as $leak) {
    is_(stripos($panel, $leak), false, "the panel code carries no {$leak}");
}

t('5b. the simulation banner is always rendered');
$app = file_get_contents($root . '/panel/app.js');
$api = file_get_contents($root . '/panel/api.js');
is_(str_contains($app, "document.getElementById('evidence').innerHTML = banner()"), true,
    'every render writes the evidence banner');
is_(str_contains($api, 'no router or AAA system has been contacted'), true,
    'and the simulated wording exists to be written');
is_(preg_match('/publisher_simulated\s*===\s*true/', $api), 1,
    'driven by the server\'s binding report, not by a client-side guess');

t('5c. signal colour comes from the server, never from the panel');
$code = $stripJs($app);
is_(preg_match('/class="signal \$\{esc\(x\.status\)\}/', $code), 1,
    'the signal class is the server\'s status string');
// A POSITIVE claim is the defect; a denial is the correct copy. The earlier
// form matched "whether a HotSpot server is running ... is not observed",
// which is the screen saying exactly the right thing. So the window must
// contain no negator, and — the stronger half — the verdict word a signal
// renders must come from the server through verdictOf(), never from a literal.
// Judged on the WHOLE LINE: a non-greedy match stops at the verb, so the
// negator that makes it a denial often sits just past the match.
$negated = static fn(string $l): bool =>
    (bool) preg_match('/\b(not|never|no|none|unavailable|unmeasured|without)\b/i', $l);
$lines = explode("\n", $code);
foreach (['wireguard', 'WireGuard', 'RADIUS', 'HotSpot'] as $w) {
    $claims = [];
    foreach ($lines as $l) {
        if (preg_match('/' . preg_quote($w, '/') . '[^\n]{0,60}?(connected|healthy|running|up\b)/i', $l)
            && !$negated($l)) {
            $claims[] = trim(substr($l, 0, 70));
        }
    }
    is_($claims, [], "the panel makes no positive claim about {$w}");
}
is_(preg_match('/\$\{esc\(verdictOf\(x\)\)\}/', $code), 1,
    'a signal\'s verdict word is rendered from the server, never from a literal');
foreach (['>Connected', '>Healthy', '>Running', '>Up<'] as $lit) {
    is_(str_contains($code, $lit), false, "no hardcoded verdict {$lit} is rendered");
}

t('5d. per-router figures the system cannot derive are not shown as zero');
// mt_session_account never sets device_id, so a session cannot be attributed to
// one router. Showing 0 would read as "none", which is a different claim.
is_(str_contains($code, 'not attributable'), true,
    'per-router sessions say "not attributable" rather than 0');
is_(str_contains($code, 'not exposed to Admin'), true,
    'and uplink says it is recorded but not exposed (D-4 open)');
$inv = [];
foreach (SignalReport::inventory() as $x) { $inv[$x['key']] = $x; }
is_($inv['uplink']['admin_readable'], false, 'the server agrees uplink is not admin-readable');
is_($inv['sessions']['admin_readable'], true, 'while sessions are, estate-wide');

// ===========================================================================
t('6. ALL REAL-NETWORK ACTIONS REMAIN UNAVAILABLE');

foreach (SignalReport::actions() as $a) {
    is_($a['available'], false, "{$a['key']} is unavailable");
}
is_(SignalReport::summary()['actions_available'], 0, 'none of them is actionable');

$psql("DROP DATABASE IF EXISTS {$db}");
exit(t_summary());

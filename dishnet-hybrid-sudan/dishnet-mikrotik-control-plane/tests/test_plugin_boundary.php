<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/admin_identity_double.php';

use Dn\Admin\AdminReader;
use Dn\Admin\DenyAllIdentity;
use Dn\Admin\DevStaffIdentity;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Db\Database;
use Dn\Http\Request;
use Dn\Network\SignalReport;
use Dn\Plugin\Installer;
use Dn\Plugin\Manifest;

/**
 * The plugin boundary and the honesty of the network panel.
 *
 * Two things are proven here, and the second is the one that matters:
 *
 *   1. The control plane is an installable unit — a manifest that matches the
 *      routes actually exposed, a lifecycle that verifies its own work, and an
 *      API entry point that admits nobody by default.
 *
 *   2. **No screen can claim a signal this system does not measure.** The
 *      Router Detail page in the brief showed WireGuard, RADIUS and HotSpot as
 *      green dots. Measured, there is no source for any of the three. These
 *      assertions are what stop a future edit quietly turning them green.
 */

$root = dirname(__DIR__);
$m    = Manifest::load($root . '/plugin/plugin.json');

// strip_php_comments() is a PHP tokenizer and leaves JS block comments intact —
// which is how the dnb_ guard below first passed nothing and failed loudly.
$stripJs = static function (string $s): string {
    $s = preg_replace('#/\*.*?\*/#s', ' ', $s);
    return preg_replace('#(^|[^:])//.*$#m', '$1', $s);
};
is_(trim($stripJs("/* hidden */ kept // trailing")), 'kept',
    'META: the JS stripper removes both comment forms before any guard uses it');

// ===========================================================================
t('1. THE MANIFEST DESCRIBES THE PLUGIN THAT ACTUALLY EXISTS');

is_($m->id, 'dishnet-mikrotik', 'the manifest identifies the plugin');
is_($m->domain, 'B', 'declared as Domain B');
is_($m->consumesDomainA, false, 'and declares NO dependency on Domain A');
is_(is_file($root . '/' . $m->apiEntrypoint), true,
    'the declared API entrypoint exists on disk: ' . $m->apiEntrypoint);

// A manifest that lists routes the code does not serve is a brochure. Compare
// it against the router the code actually builds.
$routes = AdminRoutes::build(new FixedStaff(new StaffIdentity('s', StaffRole::Admin, 't')),
                             \Dn\Runtime\Bindings::defaults(),
                             static fn(string $fn, array $a = []): array => []);
$declared = [];
foreach ($m->routes as $r) { $declared[] = $r['method'] . ' ' . $m->apiBase . $r['path']; }
foreach ($m->unboundWrites as $r) { $declared[] = $r['method'] . ' ' . $m->apiBase . $r['path']; }
// The login boundary. Declared separately because it is the only part of the
// surface with no capability — see the manifest note.
foreach ($m->sessionRoutes as $r) { $declared[] = $r['method'] . ' ' . $m->apiBase . $r['path']; }
// The DishNet staff roster (migration 026): the one capability-gated write
// block, declared apart from estate writes because it changes identity state.
foreach ($m->staffRoutes as $r) { $declared[] = $r['method'] . ' ' . $m->apiBase . $r['path']; }
sort($declared);

$served = [];
foreach ((new ReflectionClass($routes))->getProperties() as $p) {
    $p->setAccessible(true);
    $v = $p->getValue($routes);
    if (is_array($v) && isset($v[0]) && is_array($v[0]) && count($v[0]) === 4) {
        foreach ($v as [$method, $pattern, , ]) {
            if (str_starts_with($pattern, $m->apiBase)) { $served[] = $method . ' ' . $pattern; }
        }
    }
}
sort($served);
is_($declared, $served, 'every route the manifest declares is a route the plugin serves, and no other');

is_($m->writeRoutes, [], 'the manifest declares ZERO BOUND write routes');
is_(count($m->unboundWrites), 8, 'and eight declared-but-unbound write paths (the eighth: the 027 principal creator, docs/116 §J J-1)');
is_(count($m->sessionRoutes), 6,
    'and six session paths — who am I, log in, log out, change password, enrol, confirm');
is_(array_values(array_filter($m->sessionRoutes, fn($r) => ($r['capability'] ?? null) !== null)), [],
    'none of the six declares a capability: a capability is what logging in GRANTS');
// Changed DELIBERATELY with migration 026 (docs/114 §N R-7): a session is now a
// revocable row and an audit row, written through dnb_def_staff's functions.
// The ESTATE stays read-only, and that half is what writes.bound still asserts.
is_($m->apiSurface, 'estate read-only; identity read-write',
    'the surface says the truth: estate read-only, identity read-write');
is_($m->writeRoutes, [], 'and no ESTATE write route became bound by adding a login');
is_(count($m->staffRoutes), 7, 'seven staff-roster routes are declared');
is_(array_values(array_unique(array_column($m->staffRoutes, 'capability'))), ['staff.manage'],
    'every one of them gated on staff.manage, which only Admin carries');

// The honest part: those paths exist. Prove each answers 501 and writes nothing.
$reqW = new Request('POST', '/', [], [], [], '127.0.0.1');
foreach ($m->unboundWrites as $w) {
    $path = str_replace(['{device_id}', '{session_id}', '{customer_id}'],
                        '00000000-0000-4000-8000-000000000000', $m->apiBase . $w['path']);
    $mm = $routes->match($w['method'], $path);
    is_($mm !== null, true, "declared write path is routed: {$w['method']} {$w['path']}");
    is_(($mm[0])($reqW, null, null)->status, 501,
        "and answers 501 rather than writing: {$w['path']}");
}

t('1b. required configuration is declared, and secrets are marked as secrets');
foreach (['DNB_DSN', 'DNB_TOKEN_PEPPER'] as $k) {
    is_(($m->config[$k]['required'] ?? false), true, "{$k} is declared required");
}
is_(($m->config['DNB_TOKEN_PEPPER']['secret'] ?? false), true,
    'the token pepper is marked secret, so status never prints it');
is_(str_contains(file_get_contents($root . '/plugin/bin/plugin.php'), 'value withheld'), true,
    'and the status command withholds it by construction');

t('1c. every gate the manifest carries is closed');
foreach (['F6-B', 'admin-write', 'portal'] as $g) {
    is_(isset($m->gates[$g]), true, "gate {$g} is declared");
    is_($m->gateIsOpen($g), false, "gate {$g} is NOT open");
}

// ===========================================================================
t('2. THE LIFECYCLE VERIFIES ITSELF RATHER THAN ANNOUNCING SUCCESS');

$inst = new Installer($m, $root);
$s = $inst->status();
is_($s['db'], true, 'status reaches the database');
is_($s['schema'], true, 'and reports the schema present');
$recorded = (int) Database::owner()->one('SELECT count(*)::int c FROM mt_migrations')['c'];
is_($s['migrations'], $recorded,
    "the migration count is READ from mt_migrations ({$recorded}), not asserted");
is_($s['adminapi'], true, 'and the admin read role is confirmed to exist');

// install() must raise rather than report success when its own effect is absent.
is_(str_contains(file_get_contents($root . '/src/Plugin/Installer.php'),
    'install reported success but no schema is present'), true,
    'install refuses to report a success it did not verify');

t('2b. uninstall refuses without explicit confirmation');
$out = [];
$code = 0;
exec('cd ' . escapeshellarg($root) . ' && php plugin/bin/plugin.php uninstall 2>&1', $out, $code);
is_($code, 2, 'uninstall without the confirmation flag exits non-zero');
is_(str_contains(implode("\n", $out), 'Refusing to uninstall'), true, 'and says it refused');
is_(str_contains(implode("\n", $out), 'not reversible'), true,
    'and says plainly that it is not reversible');

// ===========================================================================
t('3. THE API ENTRYPOINT ADMITS NOBODY BY DEFAULT');

$denied = AdminRoutes::build(new DenyAllIdentity(), \Dn\Runtime\Bindings::defaults(),
    static fn(string $fn, array $a = []): array => throw new RuntimeException('reader was called'));
$req = new Request('GET', '/', [], [], [], '127.0.0.1');
foreach (['/api/v1/admin/routers', '/api/v1/admin/network-signals', '/api/v1/admin/health'] as $p) {
    $mm = $denied->match('GET', $p);
    is_($mm !== null, true, "route exists: {$p}");
    is_(($mm[0])($req, null, null)->status, 401, "401 before any read: {$p}");
}

$entry = file_get_contents($root . '/' . $m->apiEntrypoint);
$factory = file_get_contents($root . '/src/Admin/StaffIdentityFactory.php');
is_(str_contains($entry, 'StaffIdentityFactory::fromEnvironment()'), true,
    'the entrypoint chooses its identity through the one factory');
is_(str_contains($factory, 'new DenyAllIdentity()'), true,
    'and the factory binds DenyAllIdentity as its default');
is_(preg_match('/DN_STAFF_IDENTITY[^;]*\n[^;]*DenyAllIdentity|\$mode === \'\'/', $factory) === 1, true,
    'reached only when DN_STAFF_IDENTITY is unset — the real provider is selected, never assumed');
is_(str_contains($entry, 'Database::adminApi()'), true,
    'and reads through dnb_adminapi, which holds no table privilege');
is_(str_contains($entry, 'Database::owner()') || str_contains($entry, 'Database::admin()'), false,
    'the entrypoint never reaches for the owner or the broad admin role');

t('3b. the development identity cannot exist without its gate, or alongside F6-B');
putenv('DN_DEV_STAFF_IDENTITY');
throws_(fn() => new DevStaffIdentity(StaffRole::Admin), 'development-only',
    'no gate, no development identity');
putenv('DN_DEV_STAFF_IDENTITY=' . DevStaffIdentity::VALUE);
putenv('DN_ALLOW_REAL_BINDINGS=yes-f6b-authorized');
throws_(fn() => new DevStaffIdentity(StaffRole::Admin), 'real',
    'and never alongside real bindings — it throws rather than degrading');
putenv('DN_ALLOW_REAL_BINDINGS');
putenv('DN_DEV_STAFF_IDENTITY');

// ===========================================================================
t('4. NO SCREEN CAN CLAIM A SIGNAL THIS SYSTEM DOES NOT MEASURE');

$inv = SignalReport::inventory();
is_(count($inv) >= 8, true, 'the inventory covers the router signals a panel would show');

$byKey = [];
foreach ($inv as $x) { $byKey[$x['key']] = $x; }

// The three the brief drew as green dots. Each must be declared unmeasured,
// and each must say why and what would fix it.
foreach (['wireguard', 'radius', 'hotspot', 'wan_link'] as $k) {
    is_(isset($byKey[$k]), true, "the inventory covers {$k}");
    is_($byKey[$k]['status'], SignalReport::UNMEASURED, "{$k} is declared UNMEASURED");
    is_($byKey[$k]['source'], null, "{$k} names no source, because it has none");
    is_(is_string($byKey[$k]['reason']) && $byKey[$k]['reason'] !== '', true,
        "{$k} says WHY it cannot be measured");
    is_(is_string($byKey[$k]['needs']) && $byKey[$k]['needs'] !== '', true,
        "{$k} says what would have to exist");
}

// Every entry is one of exactly two states, and a measured one must cite its row.
foreach ($inv as $x) {
    is_(in_array($x['status'], [SignalReport::MEASURED, SignalReport::UNMEASURED], true), true,
        "{$x['key']} is measured or unmeasured, never a third thing");
    if ($x['status'] === SignalReport::MEASURED) {
        is_(is_string($x['source']) && $x['source'] !== '', true,
            "{$x['key']} is measured, so it names the source it comes from");
    }
}

t('4b. last_seen_at is claimed unwritten — prove the claim');
// The panel tells DishNet staff that this column is never written. If a future
// change starts writing it, this assertion fails and the copy gets corrected
// rather than becoming a lie.
$writes = [];
foreach (array_merge(glob($root . '/src/**/*.php') ?: [], glob($root . '/src/*.php') ?: [],
                     glob($root . '/migrations/*.sql') ?: []) as $f) {
    $c = file_get_contents($f);
    // A write is an INSERT naming the column, or a SET of it. mt_sessions has
    // its own last_seen_at and is not the device column, so the device tables
    // are what is checked.
    if (preg_match('/mt_devices[^;]{0,400}\blast_seen_at\b\s*=/is', $c)
        || preg_match('/INSERT INTO mt_devices[^;]{0,400}\blast_seen_at\b/is', $c)) {
        $writes[] = basename($f);
    }
}
is_($writes, [], 'nothing writes mt_devices.last_seen_at, exactly as the panel says');
is_($byKey['last_seen']['status'], SignalReport::UNMEASURED, 'so it is declared unmeasured');

t('4c. every router action is unavailable, with a reason');
$acts = SignalReport::actions();
is_(count($acts), 4, 'the four actions in the brief are all represented');
foreach ($acts as $a) {
    is_($a['available'], false, "{$a['key']} is NOT available");
    is_(is_string($a['reason']) && strlen($a['reason']) > 20, true,
        "{$a['key']} gives a specific reason, not a shrug");
}
is_(SignalReport::summary()['actions_available'], 0, 'nothing is actionable in this increment');

// ===========================================================================
t('5. THE PANEL TAKES THE INVENTORY FROM THE SERVER, NOT FROM A LITERAL');

$app = file_get_contents($root . '/panel/app.js');
$api = file_get_contents($root . '/panel/api.js');
$code = $stripJs($app);

is_(str_contains($api, "'/api/v1/admin/network-signals'"), true,
    'the client fetches the inventory from the plugin');
is_(preg_match('/signalPanel\s*\(\s*sig/', $code), 1,
    'and the panel renders what it was given');

// The decisive one: the panel must not contain its own opinion about these.
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
is_(preg_match('/class="signal \$\{esc\(x\.status\)\}/', $code), 1,
    'the signal class comes from the server status, so the UI cannot colour it in');

t('5b. the actions render inert');
is_(preg_match('/<button[^>]*\bdisabled\b/', $code), 1, 'the action button carries disabled');
is_(str_contains($code, 'aria-disabled="true"'), true, 'and is disabled for assistive technology too');
is_(preg_match('/data-action\s*=/', $code), 0, 'no action handler is wired to anything');

t('5c. the navigation is two planes');
foreach (['Network plane', 'Commercial plane'] as $sec) {
    is_(str_contains($app, "sec: '{$sec}'"), true, "nav declares the {$sec}");
}
foreach (['network', 'diagnostics'] as $v) {
    is_(str_contains($app, "id: '{$v}'"), true, "nav contains {$v}");
}

// ===========================================================================
t('6. THE PANEL IS OUTSIDE THE PLUGIN');

is_(is_dir($root . '/panel'), true, 'the panel lives in its own directory');
is_(is_dir($root . '/public/admin'), false, 'and no longer inside the plugin surface');
foreach (glob($root . '/panel/*') as $f) {
    is_(preg_match('/\.(js|html|css)$/', basename($f)) === 1, true,
        basename($f) . ' is a static asset — the panel holds no server code');
}
// Against CODE, not comments: api.js explains in prose that the server reads
// as dnb_adminapi, and naming the role is how it explains it. This is the
// fourth guard in this project to catch its own author's prose.
$panelCode = implode("\n", array_map(
    static fn($f) => $stripJs(file_get_contents($f)),
    array_filter(glob($root . '/panel/*'), static fn($f) => str_ends_with($f, '.js'))));
foreach (['pdo', 'pgsql', 'SELECT ', 'dnb_'] as $leak) {
    is_(stripos($panelCode, $leak), false,
        "the panel CODE contains no {$leak} — it reaches the estate only through the API");
}

exit(t_summary());

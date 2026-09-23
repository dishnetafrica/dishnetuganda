<?php
/**
 * F6-A — the Admin UI increment.
 *
 * Read-only by construction, not by intention. The assertions that matter most
 * are the ones about what the UI CANNOT do and CANNOT say: no write call
 * exists in the client, no excluded field can be rendered because no endpoint
 * emits it, a development identity cannot become a production one, and no copy
 * claims hardware that has answered nothing.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin_identity_double.php';

use Dn\Admin\AdminReader;
use Dn\Admin\DenyAllIdentity;
use Dn\Admin\DevStaffIdentity;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Db\Database;
use Dn\Http\Request;
use Dn\Runtime\Bindings;

$owner = Database::inspector();
$ids   = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];
$reader = new AdminReader(Database::adminApi());
$routes = AdminRoutes::build(new FixedStaff(new StaffIdentity('s', StaffRole::Admin, 't')),
                             Bindings::defaults(), $reader);
$req = new Request('GET', '/x');
$hit = function (string $p) use ($routes, $req): \Dn\Http\Response {
    $m = $routes->match('GET', $p);
    if ($m === null) { bad("no route {$p}"); return new \Dn\Http\Response(404); }
    [$h, $params] = $m;
    return $h(new Request('GET', $p, [], [], $params));
};

$js  = file_get_contents(__DIR__ . '/../panel/api.js');
$app = file_get_contents(__DIR__ . '/../panel/app.js');
$htm = file_get_contents(__DIR__ . '/../panel/index.html');
$stripJs = static function (string $s): string {
    $s = preg_replace('#/\*.*?\*/#s', ' ', $s);
    return preg_replace('#(^|[^:])//.*$#m', '$1', $s);
};
$code = $stripJs($js) . "\n" . $stripJs($app);

// ===========================================================================
t('1-3. THE UI RENDERS THE ESTATE — both customers, their sites and routers');
$c = $hit('/api/v1/admin/customers');
is_($c->status, 200, 'customers read succeeds');
$cids = array_column($c->body['customer'], 'id');
is_(in_array($A['customer'], $cids, true) && in_array($B['customer'], $cids, true), true,
    'both seeded customers are renderable');
$s = $hit('/api/v1/admin/sites');
is_(count(array_unique(array_column($s->body['site'], 'customer_id'))) >= 2, true,
    'sites span both customers');
$r = $hit('/api/v1/admin/routers');
is_($r->status, 200, 'routers read succeeds — the fleet view has a source');
is_(is_array($r->body['router']), true, 'and returns a list the table can render');

// ===========================================================================
t('4-5. EXCLUDED FIELDS CANNOT REACH THE SCREEN — the endpoint never emits them');
$blob = '';
foreach (['/api/v1/admin/customers','/api/v1/admin/sites','/api/v1/admin/routers',
          '/api/v1/admin/plans','/api/v1/admin/vouchers','/api/v1/admin/voucher-batches',
          '/api/v1/admin/sessions','/api/v1/admin/intents','/api/v1/admin/audit'] as $p) {
    $blob .= json_encode($hit($p)->body);
}
foreach (['radius_ref','wg_pubkey','secret_sealed','credential_hash','token_hash',
          'radius_username','"mac"','"code"','payload','last_error','"detail"'] as $f) {
    is_(str_contains($blob, $f), false, "no admin response carries {$f}");
}
// And the client cannot reconstruct them: it never names them either. One
// deliberate exception since migration 028 (docs/121 D-12): the Add-router
// form SENDS a WireGuard public key as the field the bench act needs. That is
// an input, not a read-back — the response withholds the key (asserted above
// and in test_router_control_plane), and the name may appear in app.js ONLY as
// that form field's name.
$inputOnly = preg_replace('/<input name="wg_pubkey"/', '<input name="WG-FORM-FIELD"', $code, -1, $formFields);
is_($formFields, 1, 'wg_pubkey names exactly one form field in the panel (the Add-router form)');
foreach (['payload','last_error','secret_sealed','wg_pubkey','radius_ref','radius_username'] as $f) {
    is_(str_contains($inputOnly, $f), false, "the admin client never references {$f}" . ($f === 'wg_pubkey' ? ' outside that one form field' : ''));
}

// ===========================================================================
t('6. DenyAllIdentity PRODUCES THE UNAUTHORIZED STATE, and the UI has words for it');
$denied = AdminRoutes::build(new DenyAllIdentity(), Bindings::defaults(), $reader);
foreach (['/api/v1/admin/customers','/api/v1/admin/routers','/api/v1/admin/audit'] as $p) {
    [$h, $prm] = $denied->match('GET', $p);
    is_($h(new Request('GET', $p, [], [], $prm))->status, 401, "401 under deny-all: {$p}");
}
is_(str_contains($app, 'Not signed in'), true, 'the UI renders an explicit signed-out state');
is_(str_contains($app, 'admits nobody'), true, 'and explains that no provider is selected');
// The rule that matters: 401 must not be drawn as "you have no routers".
is_(preg_match('/UNAUTHORIZED\]:\s*\[.Not signed in/', $app), 1,
    'unauthorized has its OWN copy, distinct from empty');
is_(preg_match('/EMPTY\]:\s*\[`No \$\{noun\} yet/', $app), 1,
    'and empty says the estate is genuinely empty');

// ===========================================================================
t('7. A DEVELOPMENT IDENTITY CANNOT BECOME A PRODUCTION ONE');
putenv('DN_DEV_STAFF_IDENTITY');           // unset
throws_(fn() => new DevStaffIdentity(StaffRole::Admin), 'development-only',
    'without the explicit variable it refuses to construct');
is_(getenv(DevStaffIdentity::ENV), false, 'and no default or fixture sets that variable');

putenv('DN_DEV_STAFF_IDENTITY=' . DevStaffIdentity::VALUE);
$dev = new DevStaffIdentity(StaffRole::Support);
is_($dev->providerName(), 'DEVELOPMENT-ONLY', 'when enabled it names itself unmistakably');
is_($dev->identify($req)->role, StaffRole::Support, 'and issues the role it was built with');

// The decisive one: a process authorized for real bindings must refuse it.
putenv(Bindings::REAL_GATE_ENV . '=' . Bindings::REAL_GATE_VALUE);
throws_(fn() => new DevStaffIdentity(StaffRole::Admin), 'refuses to run',
    'a process authorized for F6-B refuses a development staff member');
putenv(Bindings::REAL_GATE_ENV);
putenv('DN_DEV_STAFF_IDENTITY');
is_(getenv(Bindings::REAL_GATE_ENV), false, 'and the gate is left closed afterwards');
// It throws rather than degrading: a silent downgrade is how a caller comes to
// believe it authenticated somebody.
is_(str_contains(file_get_contents(__DIR__ . '/../src/Admin/DevStaffIdentity.php'),
    'DenyAllIdentity'), true, 'the class documents that it does not degrade');

// ===========================================================================
t('8. THE READ-ONLY CLIENT STAYS READ-ONLY; every estate write the UI makes is one of four, through routers.js (docs/121 D-13)');
// Checked as CALLS, not as words: "a provisioning gap" is legitimate screen
// copy, while `provision(` would be a write. The earlier version of this
// assertion matched the prose and was wrong about it.
//
// Since migration 028 app.js DOES perform estate writes — router register,
// assign, lifecycle state and the push_config action — but only as
// routersApi.<method>(…) calls into panel/routers.js, the router-write client.
// Those call sites are the ONLY exemption, named here, and a bare call is
// still caught (the control below proves the scan still fires).
$writeCalls = [];
preg_match_all('/\broutersApi\.(\w+)\s*\(/', $stripJs($app), $writeCalls);
is_(array_values(array_unique($writeCalls[1])) === [] ? 'none' : implode(',', array_values(array_diff(array_unique($writeCalls[1]), ['register', 'assign', 'setState', 'pushConfig']))), '',
    'every routersApi call in app.js is one of register, assign, setState, pushConfig' . ($writeCalls[1] ? ' (found: ' . implode(', ', array_unique($writeCalls[1])) . ')' : ''));
is_(count(array_unique($writeCalls[1])) >= 4, true, 'CONTROL: the scan does find the four router-write call sites');
$codeSans = preg_replace('/\broutersApi\.(register|assign|setState|pushConfig)\s*\(/', 'ROUTER_WRITE(', $code);
foreach (['assign','provision','reprovision','reboot','reset','revoke','disconnect',
          'createVoucher','createBatch','editPlan','delete'] as $w) {
    is_(preg_match('/\b' . preg_quote($w, '/') . '\s*\(/i', $codeSans), 0,
        "outside routers.js the admin client makes no {$w}() call");
}
is_(preg_match('/\bassign\s*\(/i', 'x.assign(1)'), 1, 'CONTROL: the call scan does match a bare assign() call');
is_(stripos($stripJs($js), 'POST') === false, true, 'the read-only client issues no POST');
is_(stripos($stripJs($app), 'POST') === false, true, 'and app.js carries no HTTP verb of its own: its writes are the four routers.js methods and the identity-plane clients');
// app.js has a list() helper whose PARAMETER is called fetch and is called
// with no arguments; a global fetch carries a URL. Scan for the latter.
is_(preg_match('/\bfetch\s*\(\s*[^)\s]/', $stripJs($app)), 0, 'app.js opens no fetch of its own (no fetch call carrying a URL)');
is_(preg_match('/\bfetch\s*\(\s*[^)\s]/', "fetch('/x')"), 1, 'CONTROL: that scan does match a real fetch call');
is_(str_contains($stripJs($js), "method:"), false, 'the read-only client issues no non-GET request');
is_(substr_count($stripJs($js), 'fetch('), 1, 'there is exactly one fetch call site in it');

// ===========================================================================
t('9-10. STATUS DOES NOT IMPLY HARDWARE, AND NO PUSH/POLL CLAIM APPEARS');
is_(str_contains($code, 'SIMULATED'), true, 'the UI can say SIMULATED');
is_(str_contains($js, 'no router or AAA system has been contacted'), true,
    'and says so in those words when the binding is simulated');
is_(preg_match('/publisher_simulated\s*===\s*true/', $js), 1,
    'the simulated banner is driven by the binding fact, not by a guess');
foreach (['check-in','check in','checkin','poll','will connect','next contact',
          'router will','heartbeat','online now','currently online'] as $w) {
    is_(stripos($code, $w) === false, true, "no admin CODE says '{$w}'");
}
is_(stripos($stripJs($htm), 'poll') === false, true, 'nor does the shell markup');
// Cohorts describe observed contact age only.
is_(str_contains($js, 'Never seen'), true, 'never-seen is a first-class cohort');
is_(str_contains($js, "if (!lastSeenAt) return 'never'"), true,
    'and derives from last contact alone');

t('NAVIGATION — the V2 structure, without reseller or tenant management');
foreach (['routers','customers','intents','sessions','dashboard','plans','vouchers',
          'batches','audit'] as $v) {
    is_(str_contains($app, "id: '{$v}'"), true, "nav contains {$v}");
}
// Against CODE, not comments: app.js's header explains that reseller
// navigation is absent by decision, and necessarily names it to do so. This is
// the third guard in this project to catch its own author's prose, which is
// the clearest evidence the guards are real.
$appCode = $stripJs($app);
foreach (['reseller','Reseller','tenants','Tenants'] as $n) {
    is_(str_contains($appCode, $n), false, "nav CODE contains no {$n}");
}

t('RESPONSIVE — the shell declares the viewport and lets wide tables scroll');
is_(str_contains($htm, 'width=device-width'), true, 'viewport meta present');
is_(str_contains($htm, 'overflow-x:auto'), true, 'wide tables scroll rather than overflow the page');
is_(count(array_unique(preg_split('/\D+/', implode(' ',
    (preg_match_all('/@media \(max-width:(\d+)px\)/', $htm, $m) ? $m[1] : []))))) >= 2,
    true, 'at least two breakpoints are defined');

exit(t_summary());

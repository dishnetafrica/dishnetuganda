<?php
declare(strict_types=1);
/**
 * The plugin's API entry point — the ONLY way into Domain B.
 *
 * Before this file existed the Admin surface was reachable from tests and from
 * nowhere else (`public/index.php` wires the customer API only), which made
 * "the panel consumes the plugin through an API" an aspiration rather than a
 * boundary. This is that boundary.
 *
 * Two properties it must keep:
 *
 *   1. **The panel gets a connection that can only read projections.**
 *      Database::adminApi() is dnb_adminapi: EXECUTE on the eleven projections
 *      of migration 019 and no privilege on any table. A bug in this file
 *      cannot widen that, because the privilege is on the connection.
 *
 *   2. **The default identity admits nobody.** DenyAllIdentity is the
 *      production binding (Decision 8 / W-4). A development identity requires
 *      an explicit environment gate that DevStaffIdentity itself refuses to
 *      honour when real bindings are allowed.
 */
require dirname(__DIR__, 2) . '/src/autoload.php';

use Dn\Admin\AdminReader;
use Dn\Admin\DenyAllIdentity;
use Dn\Admin\AdminSession;
use Dn\Admin\DevSessionIdentity;
use Dn\Admin\DevStaffIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Db\Database;
use Dn\Http\Request;
use Dn\Http\Response;
use Dn\Runtime\Bindings;

$bindings = Bindings::defaults();

// ── identity ────────────────────────────────────────────────────────────────
// A development identity is opt-in, loud, and impossible alongside F6-B.
// DevStaffIdentity throws rather than degrading, so a misconfigured deployment
// fails to start instead of quietly admitting a staff member who does not exist.
//
// THE FALLBACK ONLY RUNS ONE WAY. DenyAllIdentity is the start and the
// default; a development identity can REPLACE it inside the gate below, and
// nothing anywhere replaces a failed development identity with a working one,
// or a production identity with a development one. $issuer stays null unless
// this block ran, and the login route has nothing to mint with when it is.
$identity = new DenyAllIdentity();
$issuer   = null;
$devMode  = (getenv(DevStaffIdentity::ENV) ?: '') === DevStaffIdentity::VALUE;
if ($devMode) {
    // DevSessionIdentity requires a real login, so the panel has an
    // unauthenticated state to render. It throws rather than degrading if
    // either gate fails -- the process does not start, which is the point.
    $issuer   = new DevSessionIdentity(new AdminSession());
    $identity = $issuer;
}

// ── the projection reader ───────────────────────────────────────────────────
// Null when no admin database is configured: the routes then answer 501
// ("not configured"), which is the truth, rather than an empty estate.
$reader = null;
try {
    $reader = new AdminReader(Database::adminApi());
} catch (\Throwable $e) {
    error_log('[dnb-plugin] admin read connection unavailable: ' . $e::class);
}

$router = AdminRoutes::build($identity, $bindings, $reader, $issuer);
$req    = Request::fromGlobals();

$match = $router->match($req->method, $req->path);
if ($match === null) {
    Response::notFound()->send();
    return;
}
[$handler, $params] = [$match[0], $match[1]];

try {
    $handler($req->withParams($params), null, null)->send();
} catch (\Throwable $e) {
    // Never describe the fault to the caller: a message naming a relation tells
    // whoever is probing what the schema looks like.
    error_log('[dnb-plugin] ' . $e::class . ': ' . $e->getMessage());
    (new Response(500, ['error' => 'internal']))->send();
}

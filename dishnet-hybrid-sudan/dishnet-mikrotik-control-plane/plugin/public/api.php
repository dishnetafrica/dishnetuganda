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
 * Three properties it must keep:
 *
 *   1. **The panel gets a connection that can only read projections.**
 *      Database::adminApi() is dnb_adminapi: EXECUTE on the Admin projections
 *      and no privilege on any table. A bug in this file cannot widen that,
 *      because the privilege is on the connection.
 *
 *   2. **The default identity admits nobody.** StaffIdentityFactory binds
 *      DenyAllIdentity unless DN_STAFF_IDENTITY=dishnet selects the real
 *      provider (migration 026) or the development gate selects the
 *      development one. It never falls back between them.
 *
 *   3. **A provider that cannot work fails LOUDLY.** If the factory throws —
 *      a bad DN_STAFF_IDENTITY value, the development gate set beside the real
 *      provider, or a staff-auth connection that cannot open — every request
 *      answers 500 and one line reaches the log. It is never quietly replaced
 *      by deny-all (which would read as a policy) or by the development
 *      identity (which would admit an invented person).
 */
require dirname(__DIR__, 2) . '/src/autoload.php';

use Dn\Admin\AdminReader;
use Dn\Admin\StaffIdentityFactory;
use Dn\Api\AdminRoutes;
use Dn\Db\Database;
use Dn\Http\Request;
use Dn\Http\Response;
use Dn\Runtime\Bindings;

$bindings = Bindings::defaults();

// ── identity ────────────────────────────────────────────────────────────────
try {
    [$identity, $issuer, $staff] = StaffIdentityFactory::fromEnvironment();
} catch (\Throwable $e) {
    // Loud, and final for this request. Nothing below runs.
    error_log('[dnb-plugin] staff identity provider unavailable: ' . $e::class . ': ' . $e->getMessage());
    (new Response(500, ['error' => 'identity_provider_unavailable']))->send();
    return;
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

$router = AdminRoutes::build($identity, $bindings, $reader, $issuer, $staff);
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

<?php
declare(strict_types=1);
namespace Dn\Http;

use Dn\Auth\Authenticator;
use Dn\Db\Database;
use Dn\Tenancy\TenantContext;
use Throwable;

/**
 * Dispatch. Three things happen here and nowhere else:
 *
 *  1. the bearer token becomes a derived (principal, customer) pair
 *  2. every authenticated handler runs INSIDE the tenant context
 *  3. anything unroutable, unauthorised or unreachable becomes the same 404
 *
 * A handler therefore cannot run without a tenant context, and cannot choose
 * its own customer. That is F4 as a code path rather than a convention.
 */
final class Kernel
{
    public function __construct(
        private Router $router,
        private Database $db,
        private Authenticator $auth,
        private TenantContext $ctx,
    ) {}

    public function handle(Request $req): Response
    {
        $m = $this->router->match($req->method, $req->path);
        if ($m === null) { return Response::notFound(); }
        [$handler, $params, $needsAuth] = $m;
        $req = $req->withParams($params);

        try {
            if (!$needsAuth) {
                return $handler($req, $this->db, null);
            }

            $who = $this->auth->resolve($req->bearer());
            if ($who === null) { return Response::unauthorized(); }

            // Every authenticated handler runs inside the tenant context.
            // There is no route by which one does not.
            return $this->ctx->run($who['customer_id'],
                fn(Database $db) => $handler($req, $db, $who));

        } catch (Throwable $e) {
            // The capability floor (migration 027) refuses inside the function
            // with SQLSTATE 42501 and a message naming the capability. That is
            // the caller's own authorisation, so it is a 403 — the same answer
            // the route guard gives, reached only if a guard is missing. ANY
            // OTHER 42501 (a missing grant) stays a 500: a misconfiguration
            // must never read as a policy refusal.
            if ($e instanceof \PDOException
                && (string) ($e->errorInfo[0] ?? $e->getCode()) === '42501'
                && preg_match('/capability required: (op\.[a-z.]+)/', $e->getMessage(), $m)) {
                return new Response(403, ['error' => 'forbidden', 'capability' => $m[1]]);
            }
            // An internal failure must not describe itself to the caller: a
            // message like 'relation mt_devices does not exist' tells an
            // attacker the schema. Log it, return a shape that says nothing.
            error_log('[dnb] ' . $e::class . ': ' . $e->getMessage());
            return new Response(500, ['error' => 'internal']);
        }
    }
}

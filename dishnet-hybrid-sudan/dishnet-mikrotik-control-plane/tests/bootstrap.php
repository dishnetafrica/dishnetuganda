<?php
declare(strict_types=1);
// Dependency-free harness, matching the plugin's convention: t()/is_()/ok()/bad().
require __DIR__ . '/../src/autoload.php';

$GLOBALS['__t_pass'] = 0;
$GLOBALS['__t_fail'] = 0;
$GLOBALS['__t_name'] = '';

function t(string $name): void { $GLOBALS['__t_name'] = $name; echo "\n-- {$name}\n"; }
function ok(string $msg): void  { $GLOBALS['__t_pass']++; echo "  ok    {$msg}\n"; }
function bad(string $msg): void { $GLOBALS['__t_fail']++; echo "  FAIL  {$msg}\n"; }

function is_($actual, $expected, string $msg): void
{
    if ($actual === $expected) { ok($msg); return; }
    bad($msg . ' — expected ' . var_export($expected, true)
             . ', got ' . var_export($actual, true));
}

/** Assert a callable throws, optionally matching a substring. */
function throws_(callable $fn, string $needle, string $msg): void
{
    try { $fn(); } catch (Throwable $e) {
        if ($needle === '' || stripos($e->getMessage(), $needle) !== false) { ok($msg); return; }
        bad($msg . " — threw, but message lacked '{$needle}': " . $e->getMessage());
        return;
    }
    bad($msg . ' — did not throw');
}

function t_summary(): int
{
    $p = $GLOBALS['__t_pass']; $f = $GLOBALS['__t_fail'];
    echo "\n" . str_repeat('-', 56) . "\n";
    echo $f ? "FAILED  {$f} of " . ($p + $f) . "\n" : "PASSED  all {$p} assertions\n";
    return $f ? 1 : 0;
}

/**
 * Strip comments so a source-content guard scans CODE, not prose.
 *
 * Without this, a guard looking for "shape" matches the comment "one failure
 * shape for every reason", and a guard looking for refusal verbs matches
 * "ALLOWLIST, never a denylist". Both happened; both were false positives on
 * comments that exist to explain the very rule being checked.
 */
function strip_php_comments(string $code): string
{
    $out = '';
    foreach (token_get_all($code) as $tok) {
        if (is_array($tok)) {
            if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/**
 * Every customer-scoped table, DERIVED FROM THE CATALOGUE.
 *
 * Audit finding F4: the test titled "every customer-scoped table has RLS
 * enabled AND forced" iterated a hand-written list of eight. Nineteen tables
 * carry a customer. All nineteen happened to be correct, so nothing was
 * broken — but the guard could not have caught the regression it named, and
 * the next migration to add a table would have inherited that silence.
 *
 * A table is customer-scoped if it has a customer_id column, or if it IS the
 * customer table. That question is answered by the schema, so a table added
 * next year is in scope the day it is created, without anyone remembering.
 *
 * @return array<string,string> table => the column that carries the customer
 */
function customer_scoped_tables(\Dn\Db\Database $db): array
{
    $rows = $db->query(
        "SELECT c.relname AS t,
                CASE WHEN c.relname = 'mt_customers' THEN 'id' ELSE 'customer_id' END AS col
           FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname LIKE 'mt\\_%'
            AND (c.relname = 'mt_customers'
                 OR EXISTS (SELECT 1 FROM pg_attribute a
                             WHERE a.attrelid = c.oid AND a.attname = 'customer_id'
                               AND NOT a.attisdropped))
          ORDER BY 1");
    return array_column($rows, 'col', 't');
}

/**
 * Tables exempt from needing an all-roles isolation policy, with the reason.
 *
 * An exemption is a decision, so it is written down rather than implied by a
 * table's absence from a list. Everything here must STILL have RLS enabled and
 * forced; what it is excused from is the tenant policy.
 */
function rls_policy_exemptions(): array
{
    return [
        // Issued before anyone is authenticated, so there is no
        // mt_current_customer() to compare against and a tenant policy could
        // never be the protection. It is protected by grants instead: no
        // application role holds any privilege on it, and only the functions
        // owned by dnb_def_auth may touch it (migration 017).
        'mt_auth_codes' => 'pre-authentication; protected by grants, not by a tenant policy',
    ];
}

/**
 * Find every way a customer-scoped table can fail to be protected.
 *
 * Returns a list of human-readable violations; empty means the schema is
 * sound. Written as a function rather than inline assertions so that a
 * NEGATIVE test can plant a deliberately unprotected table and prove this
 * actually reports it — a guard nobody has watched fail is a guard nobody
 * knows works, which is the lesson this codebase keeps relearning.
 *
 * @return list<string>
 */
function rls_violations(\Dn\Db\Database $db): array
{
    $out = [];
    $exempt = rls_policy_exemptions();

    foreach (customer_scoped_tables($db) as $tbl => $col) {
        $c = $db->one('SELECT relrowsecurity AS rls, relforcerowsecurity AS force
                         FROM pg_class WHERE relname = ?', [$tbl]);
        if ($c['rls'] !== true)   { $out[] = "{$tbl}: RLS is not enabled"; }
        if ($c['force'] !== true) { $out[] = "{$tbl}: RLS is not FORCED, so the owner bypasses it"; }

        if (isset($exempt[$tbl])) { continue; }

        // The isolation policy is the one that applies to ALL roles; the
        // per-definer-role policies from migration 017 name their role and are
        // deliberately not tenant-scoped.
        $pols = $db->query(
            "SELECT p.polname AS name, p.polcmd::text AS cmd,
                    COALESCE(pg_get_expr(p.polqual, p.polrelid), '') AS using_expr,
                    COALESCE(pg_get_expr(p.polwithcheck, p.polrelid), '') AS check_expr
               FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid
              WHERE c.relname = ? AND 0 = ANY (p.polroles)", [$tbl]);

        if ($pols === []) { $out[] = "{$tbl}: no all-roles isolation policy"; continue; }

        // pg_get_expr renders "(customer_id = mt_current_customer())", so both
        // sides are normalised the same way. Stripping parentheses from only
        // one side would also strip them from mt_current_customer() and the
        // needle could never match — which is how the first version of this
        // check reported every table as broken.
        $flat = fn(string $e) => preg_replace('/[\s()]/', '', $e);
        $want = $flat("{$col} = mt_current_customer()");

        foreach ($pols as $pol) {
            $label = "{$col} = mt_current_customer()";
            if ($pol['cmd'] !== '*') {
                $out[] = "{$tbl}: policy {$pol['name']} covers only {$pol['cmd']}, not every command";
            }
            if (!str_contains($flat($pol['using_expr']), $want)) {
                $out[] = "{$tbl}: policy {$pol['name']} has no USING on {$label}";
            }
            if (!str_contains($flat($pol['check_expr']), $want)) {
                $out[] = "{$tbl}: policy {$pol['name']} has no WITH CHECK on {$label}"
                       . ' — it would permit a write it would not permit a read of';
            }
        }
    }
    return $out;
}

/**
 * Every customer-referencing table, child-first, for teardown.
 * Keep in sync with the schema — a guard test enforces that.
 */
function seed_tables(): array
{
    return [
        'mt_auth_codes', 'mt_intents', 'mt_idempotency', 'mt_audit_log',
        'mt_sessions', 'mt_hotspot_users', 'mt_vouchers', 'mt_voucher_batches',
        'mt_uplink_samples', 'mt_device_secrets', 'mt_device_config', 'mt_devices',
        'mt_plans', 'mt_sites', 'mt_entitlements', 'mt_services',
        'mt_auth_sessions', 'mt_principals',
    ];
}

/**
 * Delete every row from a table, lifting any delete-protection trigger for
 * exactly that statement.
 *
 * Two tables refuse DELETE in production and are right to: mt_audit_log is
 * append-only, and a plan is retired rather than deleted because a voucher
 * sold against it is a revenue record. A test fixture is the one place it is
 * legitimate to lift that, and doing it generically means the next protected
 * table a step adds does not break teardown in a way that looks like a
 * product bug.
 */
function clear_table(\Dn\Db\Database $owner, string $table): void
{
    // TRUNCATE, not DELETE. Since migration 017 the owner is not a superuser
    // and is subject to FORCE row-level security like everybody else, so a
    // DELETE as the owner matches no rows and silently removes nothing —
    // fixtures would accumulate across suites and the failures would point
    // anywhere but here. TRUNCATE is not subject to RLS and is the owner's to
    // perform, which makes teardown an owner operation rather than a tenant
    // one, which is what it actually is.
    //
    // The protection triggers have to be lifted for the statement: the
    // append-only guard on mt_audit_log fires on TRUNCATE as well as DELETE.
    //
    // They are lifted on EVERY mt_ table, not just this one, because CASCADE
    // propagates the truncation to referencing tables and fires THEIR triggers
    // — truncating mt_customers reaches mt_audit_log whether or not it was the
    // table named. Disabling only the named table's triggers looks correct and
    // fails on the second call.
    //
    // Discovered the way everything else in this codebase was: by watching it
    // fail, not by reading it.
    // Kept as (table, trigger) PAIRS. Keying a map by table name would silently
    // drop the second trigger on any table that has two.
    $all = $owner->query(
        "SELECT c.relname AS t, tg.tgname AS g
           FROM pg_trigger tg JOIN pg_class c ON c.oid = tg.tgrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = 'public' AND c.relname LIKE 'mt\\_%' AND NOT tg.tgisinternal");

    foreach ($all as $r) { $owner->pdo()->exec("ALTER TABLE {$r['t']} DISABLE TRIGGER {$r['g']}"); }
    try {
        $owner->pdo()->exec("TRUNCATE TABLE {$table} CASCADE");
    } finally {
        foreach ($all as $r) { $owner->pdo()->exec("ALTER TABLE {$r['t']} ENABLE TRIGGER {$r['g']}"); }
    }
}

/** Seed two customers with a full object graph each. Owner connection. */
function seed_two_customers(\Dn\Db\Database $owner): array
{
    // Child-first. seed_tables() is the single list; test_frozen_guards.php
    // asserts it covers every table that references a customer, so adding a
    // table in a later step fails loudly here instead of producing a foreign
    // key violation nobody expects.
    foreach (seed_tables() as $tbl) {
        clear_table($owner, $tbl);
    }
    clear_table($owner, 'mt_customers');
    // Global reference data, not customer-scoped, so it is not in
    // seed_tables(). Cleared after plans, which reference it.
    clear_table($owner, 'mt_profiles');

    // Built through the SAME paths the application uses, because since
    // migration 017 there is no other way: the owner cannot insert a customer
    // (no context can satisfy `id = mt_current_customer()` for a customer that
    // does not exist yet) and cannot insert tenant rows either.
    //
    // That is a feature of this harness, not a tax on it. A suite that seeded
    // by bypassing RLS could never show that the paths which do NOT bypass it
    // work — which is exactly the gap audit finding F2 was: eleven functions
    // silently doing nothing, under a green suite.
    $admin = \Dn\Db\Database::admin();
    $app   = \Dn\Db\Database::app();
    $ctx   = new \Dn\Tenancy\TenantContext($app);
    // Since migration 027 dnb_app cannot INSERT a principal at all; the first
    // owner of a new operator is created on the Admin plane, for an explicit
    // target operator, by the one function built for it (docs/116 D.9).
    $adminWrite = \Dn\Db\Database::adminWrite();

    $out = [];
    foreach ([['A','Riverside Hotel',1001], ['B','Kabale Hostel',1002]] as [$k,$name,$ucrm]) {
        // Onboarding: the one trusted administrative path (migration 017 §4).
        $cid = $admin->one('SELECT mt_customer_create(?,?) AS id',
                           [$name, 'test:seed'])['id'];
        $owner->exec('UPDATE mt_customers SET ucrm_client_id = ? WHERE id = ?', [$ucrm, $cid]);

        $p = $adminWrite->one('SELECT mt_admin_principal_create(?,?,?,?,?::text[],?) AS id',
                              [$cid, 'owner', $name . ' owner', '+25670000' . $ucrm, '{}', 'test:seed']);

        // Everything else belongs to that customer, so it is created inside
        // that customer's own context — which is how the application does it.
        $ids = $ctx->run($cid, function (\Dn\Db\Database $db) use ($cid, $name, $ucrm, $p) {
            $s = $db->one("INSERT INTO mt_services (customer_id, kind)
                           VALUES (?, 'mikrotik_hotspot') RETURNING id", [$cid]);
            $e = $db->one("INSERT INTO mt_entitlements (service_id, customer_id, key, int_value)
                           VALUES (?,?, 'max_routers', 2) RETURNING id", [$s['id'], $cid]);
            $si = $db->one('INSERT INTO mt_sites (customer_id, service_id, name, location)
                            VALUES (?,?,?,?) RETURNING id',
                           [$cid, $s['id'], $name . ' lobby', 'ground floor']);
            return ['principal' => $p['id'], 'service' => $s['id'],
                    'entitlement' => $e['id'], 'site' => $si['id']];
        });
        $out[$k] = ['customer' => $cid] + $ids;
    }
    return $out;
}

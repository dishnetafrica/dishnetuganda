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
    $triggers = array_column($owner->query(
        "SELECT tgname FROM pg_trigger
          WHERE tgrelid = ?::regclass AND NOT tgisinternal
            AND tgname LIKE '%no_delete%'", [$table]), 'tgname');

    foreach ($triggers as $tg) {
        $owner->pdo()->exec("ALTER TABLE {$table} DISABLE TRIGGER {$tg}");
    }
    try {
        $owner->exec("DELETE FROM {$table}");
    } finally {
        foreach ($triggers as $tg) {
            $owner->pdo()->exec("ALTER TABLE {$table} ENABLE TRIGGER {$tg}");
        }
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
    $owner->exec('DELETE FROM mt_customers');
    // Global reference data, not customer-scoped, so it is not in
    // seed_tables(). Cleared after plans, which reference it.
    clear_table($owner, 'mt_profiles');

    $out = [];
    foreach ([['A','Riverside Hotel',1001], ['B','Kabale Hostel',1002]] as [$k,$name,$ucrm]) {
        $c = $owner->one('INSERT INTO mt_customers (name, ucrm_client_id) VALUES (?,?) RETURNING id',
                         [$name, $ucrm]);
        $p = $owner->one("INSERT INTO mt_principals (customer_id, kind, display_name, phone)
                          VALUES (?, 'owner', ?, ?) RETURNING id",
                         [$c['id'], $name . ' owner', '+25670000' . $ucrm]);
        $s = $owner->one("INSERT INTO mt_services (customer_id, kind)
                          VALUES (?, 'mikrotik_hotspot') RETURNING id", [$c['id']]);
        $e = $owner->one("INSERT INTO mt_entitlements (service_id, customer_id, key, int_value)
                          VALUES (?,?, 'max_routers', 2) RETURNING id", [$s['id'], $c['id']]);
        $si = $owner->one('INSERT INTO mt_sites (customer_id, service_id, name, location)
                           VALUES (?,?,?,?) RETURNING id',
                          [$c['id'], $s['id'], $name . ' lobby', 'ground floor']);
        $out[$k] = ['customer' => $c['id'], 'principal' => $p['id'], 'service' => $s['id'],
                    'entitlement' => $e['id'], 'site' => $si['id']];
    }
    return $out;
}

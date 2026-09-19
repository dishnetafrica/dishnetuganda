<?php
declare(strict_types=1);
/**
 * Executable guards for docs/53 F1–F13.
 *
 * These exist because a frozen decision recorded only in prose is reopened by
 * whoever does not read the prose. Each assertion here fails the day someone
 * quietly crosses a line that was deliberately drawn.
 */
require __DIR__ . '/bootstrap.php';
use Dn\Db\Database;

$owner = Database::owner();
$root  = dirname(__DIR__);
$src   = static function () use ($root): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
    foreach ($it as $f) { if ($f->isFile() && $f->getExtension() === 'php') { $out[] = $f->getPathname(); } }
    return $out;
};

// ---------------------------------------------------------------------------
t('F8 — mt_entitlements cannot express bandwidth');
foreach (['max_bandwidth_mbps','uplink_mbps','max_mbps','bandwidth','rate_limit',
          'max_concurrent_sessions','data_cap_gb'] as $key) {
    $sql = "INSERT INTO mt_entitlements (service_id, customer_id, key, int_value)
            SELECT s.id, s.customer_id, '{$key}', 50 FROM mt_services s LIMIT 1";
    throws_(fn() => $owner->pdo()->exec($sql), 'check',
        "entitlement key '{$key}' is rejected by the schema");
}

t('F8 — the permitted key list is exactly the commercial-plane keys');
$def = $owner->one("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint
                    WHERE conrelid = 'mt_entitlements'::regclass AND contype = 'c'
                      AND pg_get_constraintdef(oid) LIKE '%key%'");
$permitted = [];
if (preg_match_all("/'([a-z_]+)'/", $def['d'], $m)) { $permitted = $m[1]; }
sort($permitted);
is_($permitted, ['feature_portal_branding','feature_uplink_alerts',
                 'max_operators','max_routers','max_sites'],
    'exactly five keys, all platform scope, none about the network');

t('F8/F10 — entitlements are recorded, never enforced against an operation');
// Your clarification: max_routers/max_sites/max_operators are COMMERCIAL
// controls. Until C11 and C20 are decided they must not gate a customer
// operation, and when they do it must bind at admin time, never at a guest.
// This asserts no such enforcement path has appeared.
$offenders = [];
foreach ($src() as $f) {
    $body = file_get_contents($f);
    if (!preg_match('/entitlement/i', $body)) { continue; }
    foreach (['throw','deny','reject','exceed','limit_reached','forbid'] as $verb) {
        if (preg_match('/' . $verb . '/i', $body)) { $offenders[] = basename($f) . " ({$verb})"; }
    }
}
is_($offenders, [], 'no source file both mentions entitlements and refuses an operation');

t('F13 — no enforcement path exists for anything measured');
$bad = [];
foreach ($src() as $f) {
    $b = file_get_contents($f);
    if (preg_match('/\b(shape|throttle|ration|cap_uplink|enforce_bandwidth)\b/i', $b)) {
        $bad[] = basename($f);
    }
}
is_($bad, [], 'no shaping, throttling or rationing verb appears in the source');

// ---------------------------------------------------------------------------
t('F1 — Domain B references no Domain A artefact');
$leaks = [];
foreach ($src() as $f) {
    $b = file_get_contents($f);
    foreach (['hotspot_paid_access','dr_wifi_','dr_accounts','starlink','ucrm.db',
              'dishnet-starlink-finance','dishnet-data-report'] as $needle) {
        if (stripos($b, $needle) !== false) { $leaks[] = basename($f) . " -> {$needle}"; }
    }
}
is_($leaks, [], 'no Domain A table, plugin or endpoint named anywhere in src/');

$sqlLeaks = [];
foreach (glob($root . '/migrations/*.sql') as $f) {
    $b = file_get_contents($f);
    foreach (['hotspot_paid_access','dr_wifi_','starlink'] as $needle) {
        if (stripos($b, $needle) !== false) { $sqlLeaks[] = basename($f) . " -> {$needle}"; }
    }
}
is_($sqlLeaks, [], 'no Domain A artefact named in any migration');

t('F1 — every table created carries the mt_ boundary prefix');
$tables = array_column($owner->query(
    "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"), 'tablename');
$unprefixed = array_values(array_filter($tables, fn($t) => strncmp($t, 'mt_', 3) !== 0));
is_($unprefixed, [], 'no table without the mt_ prefix: ' . implode(',', $tables));

// ---------------------------------------------------------------------------
t('docs/53 §4 — nothing has been built for BYO / remote adoption');
$byo = [];
foreach (array_merge($src(), glob($root . '/migrations/*.sql')) as $f) {
    $b = file_get_contents($f);
    foreach (['adopt','byo','remote_claim','self_stage','discover_capability'] as $needle) {
        if (preg_match('/\b' . $needle . '/i', $b)) { $byo[] = basename($f) . " -> {$needle}"; }
    }
}
is_($byo, [], 'no adoption, BYO or capability-discovery code exists');

t('F4 — no customer id is ever accepted from outside');
// TenantContext::run takes a derived id. Nothing may read one from a request.
$fromRequest = [];
foreach ($src() as $f) {
    $b = file_get_contents($f);
    if (preg_match('/\$_(GET|POST|REQUEST)\s*\[\s*[\'"](customer|customer_id|tenant|tenant_id)/i', $b)) {
        $fromRequest[] = basename($f);
    }
}
is_($fromRequest, [], 'no source file reads a customer/tenant id from a request');

t('the two connection roles are kept separate in code');
$db = file_get_contents($root . '/src/Db/Database.php');
is_(str_contains($db, 'public static function app()'), true, 'an explicit app() connection exists');
is_(str_contains($db, 'public static function owner()'), true, 'an explicit owner() connection exists');

exit(t_summary());

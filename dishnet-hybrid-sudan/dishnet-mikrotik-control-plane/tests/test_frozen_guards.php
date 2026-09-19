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
//
// The precise form of that guard is: no code reads an entitlement KEY. A
// first attempt grepped for refusal verbs near the word "entitlement" and
// flagged Projection.php for containing `throw` (on an unknown projection
// name) and the word "denylist" (in a comment explaining the allowlist).
// A guard that cannot tell those from an enforcement path is not a guard —
// it is noise that gets switched off. So it names the keys instead.
$keys = ['max_routers','max_sites','max_operators',
         'feature_portal_branding','feature_uplink_alerts'];
$offenders = [];
foreach ($src() as $f) {
    $body = strip_php_comments(file_get_contents($f));
    foreach ($keys as $k) {
        if (str_contains($body, $k)) { $offenders[] = basename($f) . " reads {$k}"; }
    }
}
is_($offenders, [], 'no source file reads an entitlement key — they are stored and shown, never applied');

t('F8/F10 — mt_entitlements is only ever read, never consulted in a decision');
// The entitlement KEY guard above catches code that names max_routers. This
// catches the other shape: reading the table at all outside the one read-only
// route. A SELECT that feeds an if-statement is a ceiling even if it never
// names a key.
$touches = [];
foreach ($src() as $f) {
    $body = strip_php_comments(file_get_contents($f));
    if (!str_contains($body, 'mt_entitlements')) { continue; }
    $touches[] = str_replace($root . '/src/', '', $f);
    foreach (['UPDATE mt_entitlements', 'DELETE FROM mt_entitlements',
              'INSERT INTO mt_entitlements'] as $write) {
        is_(stripos($body, $write) === false, true,
            basename($f) . " does not {$write} — entitlements are set by admin, not by a customer path");
    }
}
is_($touches, ['Api/Routes.php'],
    'exactly one source file reads mt_entitlements, and it is the read-only route');

t('F9 — no source file refuses anything on commercial grounds');
// If a ceiling creeps back, it announces itself in the wording first.
$wording = [];
foreach ($src() as $f) {
    $body = strip_php_comments(file_get_contents($f));
    foreach (['you did not buy', 'not purchased', 'exceeds your plan',
              'upgrade your', 'your allowance', 'plan does not permit',
              'exceeds your entitlement'] as $phrase) {
        if (stripos($body, $phrase) !== false) { $wording[] = basename($f) . " -> {$phrase}"; }
    }
}
is_($wording, [], 'no commercial-refusal wording anywhere in the service');

t('F13 — no code can send a rate limit or queue to a router');
// Named by the RouterOS/RADIUS artefacts that would actually do it, not by
// English words. An earlier version matched "failure shape" and "return a
// shape that says nothing", which are not network shaping.
$bad = [];
foreach ($src() as $f) {
    $b = strip_php_comments(file_get_contents($f));
    foreach (['Mikrotik-Rate-Limit', '/queue/simple', 'rate-limit',
              'burst-limit', 'Ascend-Data-Rate', 'WISPr-Bandwidth'] as $needle) {
        if (stripos($b, $needle) !== false) { $bad[] = basename($f) . " -> {$needle}"; }
    }
}
is_($bad, [], 'no RouterOS or RADIUS bandwidth-enforcement artefact appears in the source');

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

t('F2 — an HTTP request cannot reach a router within one process');
// The intent model as a code boundary rather than a convention: only Dn\Jobs
// may reference the delivery port. If Dn\Http or Dn\Api could, someone could
// call it from a request handler and the queue would be bypassed.
$violations = [];
foreach ($src() as $f) {
    $rel = str_replace($root . '/src/', '', $f);
    if (!preg_match('#^(Http|Api)/#', $rel)) { continue; }
    $body = strip_php_comments(file_get_contents($f));
    foreach (['DeliveryPort', 'Dn\\Delivery', 'NullDelivery', 'IntentWorker'] as $needle) {
        if (str_contains($body, $needle)) { $violations[] = "{$rel} -> {$needle}"; }
    }
}
is_($violations, [], 'no file in Http/ or Api/ references the delivery port or the worker');

$jobs = glob($root . '/src/Jobs/*.php');
is_(count($jobs) > 0, true, 'and there is a Jobs/ directory that does');
$jobsUseDelivery = false;
foreach ($jobs as $f) {
    if (str_contains(file_get_contents($f), 'DeliveryPort')) { $jobsUseDelivery = true; }
}
is_($jobsUseDelivery, true, 'Jobs/ is where delivery is reached from');

t('B1 — no server string asserts how or when queued work is delivered');
// The same two-sided guard the V2 prototype carries. It is two-sided on
// purpose: a one-sided check on a two-sided question missed six strings once.
// B1 is unresolved (docs/49), so neither model may be implied.
$POLL = '/\bchecks? in\b|\bcheck-?in\b|\bnext appears\b|\bwhen the router next\b'
      . '|\bnext contacts\b|\bphones home\b|\bpolls?\b/i';
$PUSH = '/\binstantly\b|\breal-?time control\b|\bimmediately sent\b'
      . '|\bpushed to the router\b|\bsent immediately\b/i';
//
// Comments are scanned too — a comment asserting a delivery model would
// mislead whoever implements against it. But a line that NAMES the two models
// in order to forbid them is discussing the rule, not breaking it, so a line
// citing the open question itself is exempt. Narrow enough not to be a
// loophole: someone would have to write "B1" or "docs/49" on the same line.
$discusses = '/\bB1\b|docs\/49/';
$b1 = [];
foreach (array_merge($src(), glob($root . '/migrations/*.sql')) as $f) {
    foreach (explode("\n", file_get_contents($f)) as $n => $line) {
        if (preg_match($discusses, $line)) { continue; }
        if (preg_match($POLL, $line) || preg_match($PUSH, $line)) {
            $b1[] = basename($f) . ':' . ($n + 1) . ' ' . trim(substr($line, 0, 60));
        }
    }
}
is_($b1, [], 'no push- or poll-implying language anywhere in the service');

t('the test seeder knows about every customer-referencing table');
// Otherwise adding a table in a later step produces a foreign-key violation
// during teardown that looks like a product bug and is not one.
$fkTables = array_column($owner->query(
    "SELECT DISTINCT c.conrelid::regclass::text AS t
       FROM pg_constraint c
      WHERE c.contype = 'f'
        AND c.confrelid = 'mt_customers'::regclass
        AND c.conrelid <> 'mt_customers'::regclass"), 't');
sort($fkTables);
$known = seed_tables(); sort($known);
is_(array_values(array_diff($fkTables, $known)), [],
    'no customer-referencing table is missing from seed_tables()');

t('F4 — no route pattern contains a customer or tenant id');
// The strongest form of "the tenant is never an argument": there is no URL
// shape that could carry one.
$patterns = [];
foreach (\Dn\Api\Routes::build(new \Dn\Auth\Authenticator(Database::app()))
         ->patterns() as $pat) { $patterns[] = $pat; }
$offending = array_values(array_filter($patterns,
    fn($p) => preg_match('/\{(customer|customer_id|tenant|tenant_id)\}/i', $p)));
is_($offending, [], 'no route pattern accepts a customer or tenant id');
is_(count($patterns) > 0, true, 'and there really are routes to check (' . count($patterns) . ')');

t('SECURITY DEFINER functions all pin their search_path');
// A SECURITY DEFINER function with a mutable search_path can be hijacked by a
// caller who creates a same-named object earlier on the path, running their
// SQL as the owner. This is the classic mistake with the pattern.
$defs = $owner->query(
    "SELECT proname, proconfig FROM pg_proc p
       JOIN pg_namespace n ON n.oid = p.pronamespace
      WHERE n.nspname = 'public' AND p.prosecdef");
is_(count($defs) > 0, true, 'there are SECURITY DEFINER functions to check');
$unpinned = [];
foreach ($defs as $d) {
    if (!str_contains((string) $d['proconfig'], 'search_path=')) { $unpinned[] = $d['proname']; }
}
is_($unpinned, [], 'every SECURITY DEFINER function pins search_path');

t('the app role has no direct access to the auth code table');
throws_(fn() => Database::app()->query('SELECT * FROM mt_auth_codes'), '',
    'dnb_app cannot read mt_auth_codes at all — only the functions may');

t('the two connection roles are kept separate in code');
$db = file_get_contents($root . '/src/Db/Database.php');
is_(str_contains($db, 'public static function app()'), true, 'an explicit app() connection exists');
is_(str_contains($db, 'public static function owner()'), true, 'an explicit owner() connection exists');

exit(t_summary());

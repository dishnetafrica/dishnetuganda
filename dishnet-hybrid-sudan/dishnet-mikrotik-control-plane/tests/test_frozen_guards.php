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

t('every catch of a constraint violation is savepointed');
// In PostgreSQL a failed statement aborts the whole transaction, so
// catch-the-unique-violation-and-continue silently does not work inside one:
// the recovery code cannot run, and neither can anything after it. It looks
// correct and fails the first time the collision it exists for happens.
// Found in three places at once in step 5, which is why this is a guard and
// not a note.
$unsafe = [];
foreach ($src() as $f) {
    $body = strip_php_comments(file_get_contents($f));
    if (!str_contains($body, '23505')) { continue; }
    if (!str_contains($body, 'attempt(')) {
        $unsafe[] = str_replace($root . '/src/', '', $f);
    }
}
is_($unsafe, [], 'every file catching 23505 routes the attempt through Database::attempt()');

$dbSrc = file_get_contents($root . '/src/Db/Database.php');
is_(str_contains($dbSrc, 'SAVEPOINT'), true, 'and attempt() really uses a savepoint');
is_(str_contains($dbSrc, 'ROLLBACK TO SAVEPOINT'), true, 'rolling back to it on failure');

t('RADIUS counters are combined with their gigawords companion');
// Acct-Input-Octets is 32-bit and wraps at 4 GiB. Reading it without
// Acct-Input-Gigawords under-reports every session past that, quietly — the
// figures look like light usage rather than like a fault.
//
// This is asserted BEHAVIOURALLY. A first version checked the source for the
// string '4294967296' — which survives in the constant declaration even if
// the multiplication is deleted, so the guard passed while the bug was
// present. A guard that cannot fail is worse than none, because it is
// believed. Negative-testing it is what caught that.
$combined = \Dn\Sessions\AccountingIngest::combineOctets(
    ['Acct-Input-Octets' => 1000, 'Acct-Input-Gigawords' => 2], 'Input');
is_($combined, 2 * 4294967296 + 1000, 'gigawords are multiplied in, not dropped');
is_(\Dn\Sessions\AccountingIngest::combineOctets(['Acct-Input-Octets' => 7], 'Input'), 7,
    'and a session below 4 GiB is unaffected');
$naive = [];
foreach ($src() as $f) {
    $body = strip_php_comments(file_get_contents($f));
    if (!str_contains($body, 'Acct-Input-Octets')) { continue; }
    if (!str_contains($body, 'Gigawords')) { $naive[] = basename($f); }
}
is_($naive, [], 'no file reads octets without also reading gigawords');

t('no router credential is ever stored or logged in the clear');
// docs/30 Artifact 7 principle 3. The whole point is that a dump yields
// nothing, so a column that held a password would defeat the encryption
// without removing it.
$cols = $owner->query(
    "SELECT table_name, column_name FROM information_schema.columns
      WHERE table_schema = 'public'
        AND column_name ~* '(password|passwd|secret|credential)'
        AND column_name !~* 'sealed|hash'");
is_(array_map(fn($c) => $c['table_name'] . '.' . $c['column_name'], $cols), [],
    'no column names a password, secret or credential outside a sealed or hashed one');

$plain = [];
foreach ($src() as $f) {
    $body = strip_php_comments(file_get_contents($f));
    // A credential reaching a log or an exception message is the other half
    // of the same leak.
    if (preg_match('/(error_log|var_dump|print_r)\s*\([^)]*\$(password|secret|creds)/i', $body)) {
        $plain[] = basename($f);
    }
}
is_($plain, [], 'no source file logs or dumps a credential variable');

t('router management is reachable over the tunnel only');
// Without this, the self-signed-certificate exception would quietly become
// "TLS verification is off everywhere".
foreach (['8.8.8.8', 'router.example.com', '10.67.0.1', '192.168.1.1'] as $h) {
    is_(\Dn\Delivery\RouterOs\RestClient::isTunnelHost($h), false, "{$h} is not a tunnel host");
}
is_(\Dn\Delivery\RouterOs\RestClient::isTunnelHost('10.66.0.11'), true,
    'and a 10.66.0.0/16 address is');

$client = file_get_contents($root . '/src/Delivery/RouterOs/RestClient.php');
is_(str_contains($client, 'CURLOPT_SSL_VERIFYPEER'), true, 'the client sets peer verification explicitly');
is_(str_contains($client, 'isTunnelHost'), true, 'and constrains the host before it does');

t('F13 — telemetry cannot reach anything that decides');
// The structural half of the saturation proof in test_telemetry.php. A
// decision-making module that could see the samples is one refactor away
// from using them.
$leak = [];
foreach ($src() as $f) {
    $rel = str_replace($root . '/src/', '', $f);
    if (!preg_match('#^(Policy|Vouchers|Intents|Delivery|Auth|Sessions)/#', $rel)) { continue; }
    $body = strip_php_comments(file_get_contents($f));
    foreach (['UplinkRepository', 'mt_uplink_samples', 'Dn\\Telemetry', 'uplink'] as $n) {
        if (stripos($body, $n) !== false) { $leak[] = "{$rel} -> {$n}"; }
    }
}
is_($leak, [], 'no decision-making module references uplink telemetry');

// And the reverse: telemetry must not import a decision-maker either.
$tele = [];
foreach (glob($root . '/src/Telemetry/*.php') as $f) {
    $body = strip_php_comments(file_get_contents($f));
    foreach (['PlanValidator', 'VoucherService', 'IntentQueue', 'DeliveryPort'] as $n) {
        if (str_contains($body, $n)) { $tele[] = basename($f) . " -> {$n}"; }
    }
}
is_($tele, [], 'telemetry imports nothing that could act on it');

t('F13 — no percentage or capacity is invented from a denominator we do not own');
// A "% utilised" needs a link capacity DishNet does not sell and, with
// Starlink, one that varies minute to minute. Printing it would be an
// invention that reads like a limit.
$tel = file_get_contents($root . '/src/Telemetry/UplinkRepository.php');
foreach (['utilisation', 'utilization', 'percent_used', 'capacity_bps'] as $w) {
    is_(stripos($tel, $w) === false, true, "the repository computes no {$w}");
}

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
//
// ARTEFACTS, not the brand name. The bare word "Starlink" was on this list
// until the product model changed: docs/50 established that the customer's
// UPLINK may be Starlink, and that it is theirs and DishNet does not ration
// it. So the word now appears legitimately in Domain B — describing a
// customer's own connection — while naming a Domain A TABLE, PLUGIN or
// ENDPOINT is still a violation. A guard that cannot tell those apart would
// force the code to stop explaining the very boundary it is keeping.
$artefacts = [
    'hotspot_paid_access',        // docs/41 §4.1, the named prohibition
    'dr_wifi_', 'dr_accounts', 'wifi_router_map',
    'dishnet-starlink-finance', 'dishnet-data-report',
    'StarlinkSessionStore', 'ucrm.db', 'SiblingPlugin',
];
$leaks = [];
foreach ($src() as $f) {
    $b = file_get_contents($f);
    foreach ($artefacts as $needle) {
        if (stripos($b, $needle) !== false) { $leaks[] = basename($f) . " -> {$needle}"; }
    }
}
is_($leaks, [], 'no Domain A table, plugin, store or file named anywhere in src/');

$sqlLeaks = [];
foreach (glob($root . '/migrations/*.sql') as $f) {
    $b = file_get_contents($f);
    foreach ($artefacts as $needle) {
        if (stripos($b, $needle) !== false) { $sqlLeaks[] = basename($f) . " -> {$needle}"; }
    }
}
is_($sqlLeaks, [], 'no Domain A artefact named in any migration');

// The separation itself is asserted where it actually lives: a different
// database, reached by a different connection string. Naming is a courtesy;
// this is the boundary.
is_(str_contains(getenv('DNB_DSN') ?: '', 'dbname=dnb'), true,
    'Domain B runs against its own database, not the plugin\'s SQLite');
$sqliteUse = [];
foreach ($src() as $f) {
    if (preg_match('/sqlite|\.db[\'"]/i', strip_php_comments(file_get_contents($f)))) {
        $sqliteUse[] = basename($f);
    }
}
is_($sqliteUse, [], 'nothing in Domain B opens a SQLite database');

t('F1 — every table created carries the mt_ boundary prefix');
$tables = array_column($owner->query(
    "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"), 'tablename');
$unprefixed = array_values(array_filter($tables, fn($t) => strncmp($t, 'mt_', 3) !== 0));
is_($unprefixed, [], 'no table without the mt_ prefix: ' . implode(',', $tables));

// ---------------------------------------------------------------------------
t('docs/53 §4 — nothing has been built for BYO / remote adoption');
//
// Line-wise, exempting lines that cite the rule — the same discriminator the
// B1 guard uses. This is the fourth text-scanning guard in this suite to trip
// over the prose that forbids the thing it looks for, which is worth stating
// as a general rule: such a guard must distinguish ASSERTION from DISCUSSION,
// and the cheapest reliable discriminator is whether the line names the rule.
$cites = '/docs\/53|docs\/52|C20/';
$byo = [];
foreach (array_merge($src(), glob($root . '/migrations/*.sql')) as $f) {
    foreach (explode("\n", file_get_contents($f)) as $n => $line) {
        if (preg_match($cites, $line)) { continue; }
        foreach (['adopt','byo','remote_claim','self_stage','discover_capability'] as $needle) {
            if (preg_match('/\b' . $needle . '/i', $line)) {
                $byo[] = basename($f) . ':' . ($n + 1) . " -> {$needle}";
            }
        }
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

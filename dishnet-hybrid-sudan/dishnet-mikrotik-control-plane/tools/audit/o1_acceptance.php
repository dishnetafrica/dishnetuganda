<?php
declare(strict_types=1);
/**
 * O-1 ACCEPTANCE HARNESS — SYNTHETIC DATA, DEVELOPMENT ONLY.
 *
 *   php tools/audit/o1_acceptance.php
 *
 * WHAT THIS IS NOT
 * ================
 * It is NOT the production census and it produces NO production evidence.
 * Production remains CENSUS NOT OBTAINED. Every row it creates is prefixed
 * O1FIX- and lives in a database this script creates and drops. Nothing here
 * may be quoted as a fact about the DishNet estate.
 *
 * WHAT IT IS
 * ==========
 * It proves three things that can be established without production:
 *
 *   1. the INSTRUMENT works — tools/audit/production_census.sql detects each
 *      anomaly it claims to detect, and its verdict is CLEAR only when the
 *      estate really is clean;
 *   2. the CONSTRAINT works — the candidate O-1 DDL applies to a clean estate,
 *      refuses a violating one, and leaves no partial schema change;
 *   3. RLS isolation is still substantive in the synthetic estate, so a
 *      "clean" reading is not a blinded one.
 *
 * Five of the fourteen anomalies CANNOT EXIST at this migration level -- the
 * schema already forbids them. For those, the harness drops the guarding
 * constraint in its own throwaway database, injects, measures, and restores.
 * That tests the census's detection, and is marked "constraint-suppressed"
 * wherever it is used: it is not a claim that such rows are reachable.
 */

require __DIR__ . '/../../tests/bootstrap.php';

const DB     = 'dnb_o1acc';
const PREFIX = 'O1FIX-';

// Host and port come from the DECLARED DNB_DSN, not from new variables of
// their own. test_installability.php sweeps tools/ as well as src/ and asserts
// that every getenv() the code performs is declared in the manifest -- and it
// is right to: an undeclared variable is a configuration surface nobody
// documented. Inventing DNB_PGHOST/DNB_PGPORT here would have widened that
// surface for a test tool.
$dsn  = getenv('DNB_DSN') ?: '';
$host = preg_match('/host=([^;]+)/', $dsn, $m) ? $m[1] : '/var/tmp';
$port = preg_match('/port=([0-9]+)/', $dsn, $m) ? $m[1] : '55432';
$own  = getenv('DNB_OWNER_USER') ?: 'dnb';
$insp = getenv('DNB_INSPECT_USER') ?: 'postgres';
$root = dirname(__DIR__, 1);          // tools/
$repo = dirname($root);

/** Run psql as a chosen role and return [exitCode, output]. */
function psql(string $user, string $db, string $sqlOrFile, bool $isFile = false): array
{
    global $host, $port;
    $pw  = $GLOBALS['rolepw'] ?? '';
    $cmd = sprintf('%sPGHOST=%s PGPORT=%s psql -U %s -d %s -X -q -v ON_ERROR_STOP=0 %s 2>&1',
        $pw !== '' ? 'PGPASSWORD=' . escapeshellarg($pw) . ' ' : '',
        escapeshellarg($host), escapeshellarg($port), escapeshellarg($user), escapeshellarg($db),
        $isFile ? '-f ' . escapeshellarg($sqlOrFile) : '-c ' . escapeshellarg($sqlOrFile));
    exec($cmd, $out, $rc);
    return [$rc, implode("\n", $out)];
}
function sql(string $s): string { [, $o] = psql($GLOBALS['insp'], DB, $s); return $o; }

/**
 * A single scalar. Returns null when the query yields no value, so a caller
 * can tell "no answer" from "the answer is zero" -- a distinction this
 * project has been bitten by before.
 */
function one(string $s): ?string
{
    global $host, $port, $insp;
    $cmd = sprintf('PGHOST=%s PGPORT=%s psql -U %s -d %s -X -tA -c %s 2>&1',
        escapeshellarg($host), escapeshellarg($port), escapeshellarg($insp),
        escapeshellarg(DB), escapeshellarg($s));
    exec($cmd, $out, $rc);
    if ($rc !== 0) { return null; }
    $v = trim($out[0] ?? '');
    return $v === '' ? null : $v;
}
function sql_as(string $user, string $s): string { [, $o] = psql($user, DB, $s); return $o; }
function num_as(string $user, string $s): int
{
    global $host, $port;
    $pw  = $GLOBALS['rolepw'] ?? '';
    $cmd = sprintf('%sPGHOST=%s PGPORT=%s psql -U %s -d %s -X -tA -c %s 2>&1',
        $pw !== '' ? 'PGPASSWORD=' . escapeshellarg($pw) . ' ' : '',
        escapeshellarg($host), escapeshellarg($port), escapeshellarg($user),
        escapeshellarg(DB), escapeshellarg($s));
    exec($cmd, $out, $rc);
    return (int) trim($out[0] ?? '');
}
function num(string $s): int
{
    $v = one($s);
    if ($v === null) { throw new RuntimeException("query returned no value: {$s}"); }
    return (int) $v;
}

/** The census verdict, and the count it blocked on. */
function census(string $user = null): array
{
    global $insp, $repo;
    [$rc, $out] = psql($user ?? $insp, DB, $repo . '/tools/audit/production_census.sql', true);
    $verdict = 'NO VERDICT';
    if (preg_match('/>> (CLEAR|INDETERMINATE)/', $out, $m))      { $verdict = $m[1]; }
    if (preg_match('/>> BLOCKED\((\d+)\)/', $out, $m))           { $verdict = 'BLOCKED'; }
    $block = 0;
    if (preg_match('/rows that would refuse the O-1 FK\s+:\s+(\d+)/', $out, $m)) { $block = (int) $m[1]; }
    $mismatch = 0;
    if (preg_match('/ANOTHER customer\s*:\s*(\d+)/', $out, $m)) { $mismatch = (int) $m[1]; }
    return ['verdict' => $verdict, 'block' => $block, 'mismatch' => $mismatch, 'raw' => $out, 'rc' => $rc];
}

// ===========================================================================
echo "\n=== o1_acceptance.php — SYNTHETIC, DEVELOPMENT ONLY, NOT PRODUCTION EVIDENCE ===\n";

t('the throwaway estate is built, and it is unmistakably synthetic');

psql($own, 'postgres', 'DROP DATABASE IF EXISTS ' . DB . ';');
psql($own, 'postgres', 'CREATE DATABASE ' . DB . ';');
$pw  = bin2hex(random_bytes(24));
// DNB_STAFFAUTH_PASS since migration 026 (docs/114 §O): without it the installer
// refuses, rightly, to invent a credential it has nowhere to write.
$env = sprintf('DNB_DSN=%s DNB_APP_PASS=%s DNB_WORKER_PASS=%s DNB_ADMIN_PASS=%s '
             . 'DNB_ADMINAPI_PASS=%s DNB_ADMINWRITE_PASS=%s DNB_RADIUS_PASS=%s '
             . 'DNB_STAFFAUTH_PASS=%s DNB_TOKEN_PEPPER=%s DNB_SECRET_KEY=%s',
    escapeshellarg("pgsql:host={$host};port={$port};dbname=" . DB),
    ...array_fill(0, 9, escapeshellarg($pw)));
exec("cd " . escapeshellarg($repo) . " && {$env} php plugin/bin/plugin.php install 2>&1", $io, $irc);
is_($irc, 0, 'the plugin schema installed into the throwaway database');
is_(num('SELECT count(*) FROM mt_migrations') > 0, true,
    'CONTROL: the migration ledger is readable, so the install really happened');

// --- the fixture. Every identifier carries the prefix. -------------------
sql(<<<'S'
INSERT INTO mt_profiles (id, rate_down_bps, rate_up_bps, session_timeout_s, shared_users)
VALUES ('00000000-0000-4000-8000-0000000000f1', 1000000, 500000, 3600, 1);

-- case 1/2: two isolated customers, each with a service and a site
INSERT INTO mt_customers (id, name) VALUES
  ('00000000-0000-4000-8000-00000000000a', 'O1FIX-CUST-A'),
  ('00000000-0000-4000-8000-00000000000b', 'O1FIX-CUST-B');
INSERT INTO mt_services (id, customer_id, kind) VALUES
  ('00000000-0000-4000-8000-0000000000a1', '00000000-0000-4000-8000-00000000000a', 'mikrotik_hotspot'),
  ('00000000-0000-4000-8000-0000000000b1', '00000000-0000-4000-8000-00000000000b', 'mikrotik_hotspot');

-- case 3: one service, several valid sites.  case 4/5: with and without device
INSERT INTO mt_sites (id, customer_id, service_id, name) VALUES
  ('00000000-0000-4000-8000-0000000000a5', '00000000-0000-4000-8000-00000000000a',
   '00000000-0000-4000-8000-0000000000a1', 'O1FIX-SITE-A1 with device'),
  ('00000000-0000-4000-8000-0000000000a6', '00000000-0000-4000-8000-00000000000a',
   '00000000-0000-4000-8000-0000000000a1', 'O1FIX-SITE-A2 no device'),
  ('00000000-0000-4000-8000-0000000000b5', '00000000-0000-4000-8000-00000000000b',
   '00000000-0000-4000-8000-0000000000b1', 'O1FIX-SITE-B1');

INSERT INTO mt_devices (id, customer_id, site_id, serial, model, wg_pubkey, tunnel_ip, state) VALUES
  ('00000000-0000-4000-8000-0000000000d1', '00000000-0000-4000-8000-00000000000a',
   '00000000-0000-4000-8000-0000000000a5', 'O1FIX-SER-0001', 'hAP ac2', 'O1FIX-WG-0001', '10.99.0.1', 'active');

-- a plan and vouchers, so the voucher sections have subject matter
INSERT INTO mt_plans (id, customer_id, site_id, profile_id, name, duration_s,
                      rate_down_bps, rate_up_bps, devices_per_voucher, mode, price_minor, currency)
VALUES ('00000000-0000-4000-8000-0000000000c1', '00000000-0000-4000-8000-00000000000a',
        '00000000-0000-4000-8000-0000000000a5', '00000000-0000-4000-8000-0000000000f1',
        'O1FIX-PLAN-A', 3600, 1000000, 500000, 1, 'elapsed', 1000, 'UGX');
INSERT INTO mt_voucher_batches (id, customer_id, site_id, plan_id, requested_count, issued_count, state)
VALUES ('00000000-0000-4000-8000-0000000000e1', '00000000-0000-4000-8000-00000000000a',
        '00000000-0000-4000-8000-0000000000a5', '00000000-0000-4000-8000-0000000000c1', 1, 1, 'issued');
INSERT INTO mt_vouchers (id, customer_id, batch_id, plan_id, site_id, code, price_minor, currency, duration_s)
VALUES ('00000000-0000-4000-8000-00000000001f', '00000000-0000-4000-8000-00000000000a',
        '00000000-0000-4000-8000-0000000000e1', '00000000-0000-4000-8000-0000000000c1',
        '00000000-0000-4000-8000-0000000000a5', 'O1FIX-AAAAA', 1000, 'UGX', 3600);
S);

is_(num("SELECT count(*) FROM mt_customers WHERE name LIKE 'O1FIX-%'"), 2, 'two synthetic customers');
is_(num('SELECT count(*) FROM mt_services'), 2, 'two services');
is_(num('SELECT count(*) FROM mt_sites'), 3, 'three sites — one service carries two of them');
is_(num('SELECT count(*) FROM mt_devices'), 1, 'one device, so one site has hardware and one does not');
is_(num("SELECT count(*) FROM mt_sites WHERE name LIKE 'O1FIX-%'"), 3,
    'CONTROL: the prefix probe DOES match — all three sites carry it');
is_(num("SELECT count(*) FROM mt_sites WHERE name NOT LIKE 'O1FIX-%'"), 0,
    'and nothing in the estate lacks it — no row can be mistaken for production data');

$GLOBALS['rolepw'] = $pw;

/** Constraints the candidate DDL would add, counted from the catalogue. */
$o1constraints = fn(): int => num(
    "SELECT count(*) FROM pg_constraint
      WHERE conname IN ('mt_services_id_customer_key','mt_sites_service_customer_fkey')");

// ===========================================================================
t('PHASE 1 — a clean synthetic estate reads CLEAR');

$c = census();
is_($c['verdict'], 'CLEAR', 'the census verdict on a clean estate is CLEAR');
is_($c['block'], 0, 'and nothing would refuse the O-1 foreign key');
is_(str_contains($c['raw'], 'mt_sites total                          : 3'), true,
    'CONTROL: it really read the estate — three sites, not a blinded zero');

// ===========================================================================
t('PHASE 2 — each anomaly the schema PERMITS is detected');

// case 6 + case 10 in one row: a site of customer A pointing at B's service.
// Representable because mt_sites carries two independent single-column FKs and
// nothing requires them to agree. That IS O-1.
sql("INSERT INTO mt_sites (id, customer_id, service_id, name) VALUES
     ('00000000-0000-4000-8000-0000000000aa', '00000000-0000-4000-8000-00000000000a',
      '00000000-0000-4000-8000-0000000000b1', 'O1FIX-SITE-CROSS');");
is_(num("SELECT count(*) FROM mt_sites WHERE name = 'O1FIX-SITE-CROSS'"), 1,
    'CONTROL: the cross-customer site was genuinely inserted — the schema permits it today');
$c = census();
is_($c['verdict'], 'BLOCKED', 'case 6 cross-customer site/service — verdict BLOCKED');
is_($c['mismatch'], 1, 'and the mismatch count is exactly the one row injected');
is_(str_contains($c['raw'], 'services reached by sites of >1 CUSTOMER: 1'), true,
    'case 10 service shared across customers — detected by the same row');
is_($c['block'], 1,
    'BLOCKED(1) — one offending ROW, not a sum of the detectors that saw it');
sql("DELETE FROM mt_sites WHERE name = 'O1FIX-SITE-CROSS';");
is_(census()['verdict'], 'CLEAR', 'CONTROL: removing it returns the estate to CLEAR');

// case 14: a voucher with no site
sql("UPDATE mt_vouchers SET site_id = NULL WHERE code = 'O1FIX-AAAAA';");
$c = census();
is_(str_contains($c['raw'], 'mt_vouchers with site_id NULL           : 1'), true,
    'case 14 voucher with NULL site_id — detected');
is_(str_contains($c['raw'], 'site-less and UNUSED (voidable)       : 1'), true,
    'and classified by state, because voiding one is not free');
sql("UPDATE mt_vouchers SET site_id = '00000000-0000-4000-8000-0000000000a5' WHERE code = 'O1FIX-AAAAA';");

// case 13: a decommissioned device still attached to a site
$before = sql("UPDATE mt_devices SET state = 'decommissioned'
                WHERE serial = 'O1FIX-SER-0001';");
if (num("SELECT count(*) FROM mt_devices WHERE state = 'decommissioned'") === 1) {
    is_(str_contains(census()['raw'], 'DECOMMISSIONED but still sited          : 1'), true,
        'case 13 decommissioned device still sited — detected');
    sql("UPDATE mt_devices SET state = 'active' WHERE serial = 'O1FIX-SER-0001';");
} else {
    ok('case 13 NOT REPRESENTABLE — the lifecycle trigger refused the transition: '
       . trim(explode("\n", $before)[0] ?? ''));
}

// ===========================================================================
t('PHASE 3 — anomalies the schema FORBIDS, measured constraint-suppressed');

// These five cannot exist at this migration level. The guard is dropped in
// THIS throwaway database only, to prove the census would SEE them if a
// production database were at an earlier migration level. It is not a claim
// that such rows are reachable here.
$suppressed = [
  ['case 7  orphan customer_id',
   "ALTER TABLE mt_sites DROP CONSTRAINT mt_sites_customer_id_fkey",
   "INSERT INTO mt_sites (id, customer_id, service_id, name) VALUES
     ('00000000-0000-4000-8000-00000000007a','00000000-0000-4000-8000-0000000007ff',
      '00000000-0000-4000-8000-0000000000a1','O1FIX-SITE-ORPHANCUST')",
   'mt_sites ORPHANED customer_id           : 1',
   "DELETE FROM mt_sites WHERE name='O1FIX-SITE-ORPHANCUST'",
   "ALTER TABLE mt_sites ADD CONSTRAINT mt_sites_customer_id_fkey
      FOREIGN KEY (customer_id) REFERENCES mt_customers(id) ON DELETE RESTRICT"],

  ['case 8  orphan service_id',
   "ALTER TABLE mt_sites DROP CONSTRAINT mt_sites_service_id_fkey",
   "INSERT INTO mt_sites (id, customer_id, service_id, name) VALUES
     ('00000000-0000-4000-8000-00000000008a','00000000-0000-4000-8000-00000000000a',
      '00000000-0000-4000-8000-0000000008ff','O1FIX-SITE-ORPHANSVC')",
   'mt_sites ORPHANED service_id            : 1',
   "DELETE FROM mt_sites WHERE name='O1FIX-SITE-ORPHANSVC'",
   "ALTER TABLE mt_sites ADD CONSTRAINT mt_sites_service_id_fkey
      FOREIGN KEY (service_id) REFERENCES mt_services(id) ON DELETE RESTRICT"],

  ['case 9  NULL service_id',
   "ALTER TABLE mt_sites ALTER COLUMN service_id DROP NOT NULL",
   "INSERT INTO mt_sites (id, customer_id, service_id, name) VALUES
     ('00000000-0000-4000-8000-00000000009a','00000000-0000-4000-8000-00000000000a',
      NULL,'O1FIX-SITE-NULLSVC')",
   'mt_sites with service_id NULL           : 1',
   "DELETE FROM mt_sites WHERE name='O1FIX-SITE-NULLSVC'",
   "ALTER TABLE mt_sites ALTER COLUMN service_id SET NOT NULL"],

  ['case 11 device customer/site mismatch',
   "ALTER TABLE mt_devices DROP CONSTRAINT mt_devices_site_customer_fkey",
   "UPDATE mt_devices SET site_id='00000000-0000-4000-8000-0000000000b5'
      WHERE serial='O1FIX-SER-0001'",
   'CROSS-CUSTOMER (site owned by another)  : 1',
   "UPDATE mt_devices SET site_id='00000000-0000-4000-8000-0000000000a5'
      WHERE serial='O1FIX-SER-0001'",
   "ALTER TABLE mt_devices ADD CONSTRAINT mt_devices_site_customer_fkey
      FOREIGN KEY (site_id, customer_id) REFERENCES mt_sites(id, customer_id)"],

  ['case 12 duplicate tunnel_ip',
   "ALTER TABLE mt_devices DROP CONSTRAINT mt_devices_tunnel_ip_key",
   "INSERT INTO mt_devices (id,customer_id,site_id,serial,model,wg_pubkey,tunnel_ip,state) VALUES
     ('00000000-0000-4000-8000-0000000000d2','00000000-0000-4000-8000-00000000000a',
      '00000000-0000-4000-8000-0000000000a5','O1FIX-SER-0002','hAP ac2','O1FIX-WG-0002','10.99.0.1','active')",
   'SHARED/DUPLICATE tunnel_ip              : 1',
   "DELETE FROM mt_devices WHERE serial='O1FIX-SER-0002'",
   "ALTER TABLE mt_devices ADD CONSTRAINT mt_devices_tunnel_ip_key UNIQUE (tunnel_ip)"],
];

foreach ($suppressed as [$label, $drop, $inject, $expect, $undo, $restore]) {
    // The constraint must refuse it FIRST, or "detected" would prove nothing
    // about a schema that already forbids the row.
    $refused = sql($inject);
    is_(str_contains($refused, 'ERROR'), true,
        "{$label} — the CURRENT schema refuses it outright");
    sql($drop);
    sql($inject);
    is_(str_contains(census()['raw'], $expect), true,
        "{$label} — and with the guard suppressed, the census detects it");
    sql($undo);
    sql($restore);
}
is_(census()['verdict'], 'CLEAR', 'CONTROL: every guard was restored — the estate is CLEAR again');

// ===========================================================================
t('PHASE 4 — the candidate O-1 constraint, applied for real');

is_($o1constraints(), 0, 'neither O-1 constraint is present to begin with');

sql("INSERT INTO mt_sites (id, customer_id, service_id, name) VALUES
     ('00000000-0000-4000-8000-0000000000aa', '00000000-0000-4000-8000-00000000000a',
      '00000000-0000-4000-8000-0000000000b1', 'O1FIX-SITE-CROSS');");
is_(num("SELECT count(*) FROM mt_sites WHERE name = 'O1FIX-SITE-CROSS'"), 1,
    'CONTROL: a violating row is present before the migration is attempted');

// --- 4a. THE FINDING -------------------------------------------------------
// docs/106 recorded that the migration "fails closed by itself" and that no
// guard clause is needed. That is FALSE for a role subject to RLS, and the
// schema owner is one: since migration 017 (F2) dnb is not a superuser, and
// mt_sites has FORCE ROW LEVEL SECURITY, so with no tenant context it sees
// zero rows and the validation scan finds nothing to object to.
is_(num_as($own, 'SELECT count(*) FROM mt_sites'), 0,
    'the OWNER sees zero site rows — FORCE RLS binds it too, with no tenant context');
$unguarded = "BEGIN;
  ALTER TABLE mt_services ADD CONSTRAINT o1probe_unique UNIQUE (id, customer_id);
  ALTER TABLE mt_sites ADD CONSTRAINT o1probe_fkey
    FOREIGN KEY (customer_id, service_id) REFERENCES mt_services (customer_id, id);
COMMIT;";
$u = sql_as($own, $unguarded);
is_(str_contains($u, 'ERROR'), false,
    'WITHOUT the guard the owner applies it with NO error, over violating data');
is_(num("SELECT count(*) FROM pg_constraint WHERE conname = 'o1probe_fkey' AND convalidated"), 1,
    '...and PostgreSQL marks the constraint VALIDATED — the invariant is asserted but untrue');
is_(num("SELECT count(*) FROM mt_sites s JOIN mt_services v ON v.id = s.service_id
          WHERE s.customer_id <> v.customer_id"), 1,
    '...while the violating row is still sitting underneath it');
sql("ALTER TABLE mt_sites DROP CONSTRAINT o1probe_fkey;
     ALTER TABLE mt_services DROP CONSTRAINT o1probe_unique;");

// --- 4b. the guard makes it fail closed regardless of role -----------------
[, $out] = psql($own, DB, __DIR__ . '/o1_composite_fk.sql', true);
is_(str_contains($out, 'would be affected by row-level security policy'), true,
    'WITH the guard the same blinded role REFUSES rather than mis-validating');
is_($o1constraints(), 0, 'and leaves no partial schema change');

// --- 4c. a role that can see every row refuses for the right reason --------
[, $out2] = psql($insp, DB, __DIR__ . '/o1_composite_fk.sql', true);
is_(str_contains($out2, 'violates foreign key constraint "mt_sites_service_customer_fkey"'), true,
    'a role that CAN see every row refuses, naming the constraint');
is_(str_contains($out2, 'Key (customer_id, service_id)='), true,
    'and names the offending pair — which is why the census must enumerate, not the error');
is_($o1constraints(), 0,
    'still no partial schema change — not even the UNIQUE that would have succeeded');

// --- 4d. clean estate: it applies, and then it does its job ----------------
sql("DELETE FROM mt_sites WHERE name = 'O1FIX-SITE-CROSS';");
[, $out3] = psql($insp, DB, __DIR__ . '/o1_composite_fk.sql', true);
is_(str_contains($out3, 'ERROR'), false, 'against a clean estate the migration applies');
is_($o1constraints(), 2, 'both constraints are now present');

$after = sql("INSERT INTO mt_sites (id, customer_id, service_id, name) VALUES
     ('00000000-0000-4000-8000-0000000000ab', '00000000-0000-4000-8000-00000000000a',
      '00000000-0000-4000-8000-0000000000b1', 'O1FIX-SITE-CROSS-2');");
is_(str_contains($after, 'mt_sites_service_customer_fkey'), true,
    'with O-1 applied, a new cross-customer site is refused BY NAME');
$own_ok = sql("INSERT INTO mt_sites (id, customer_id, service_id, name) VALUES
     ('00000000-0000-4000-8000-0000000000ac', '00000000-0000-4000-8000-00000000000a',
      '00000000-0000-4000-8000-0000000000a1', 'O1FIX-SITE-A3');");
is_(str_contains($own_ok, 'ERROR'), false,
    'CONTROL: a site on the customer OWN service is still accepted — not a blanket ban');

// ===========================================================================
t('PHASE 5 — the synthetic estate is genuinely tenant-isolated');

// A clean census must not be a blinded one. dnb_app under customer A's context
// must see A's rows and none of B's.
$asApp = function (string $cust, string $q): ?string {
    global $host, $port, $pw;
    $sqlText = "BEGIN; SELECT set_config('app.customer_id', '{$cust}', true); {$q}";
    $cmd = sprintf('PGPASSWORD=%s PGHOST=%s PGPORT=%s psql -U dnb_app -d %s -X -tA -c %s 2>&1',
        escapeshellarg($pw), escapeshellarg($host), escapeshellarg($port),
        escapeshellarg(DB), escapeshellarg($sqlText));
    exec($cmd, $o, $rc);
    return $o === [] ? null : trim((string) end($o));
};
$A = '00000000-0000-4000-8000-00000000000a';
$B = '00000000-0000-4000-8000-00000000000b';
is_($asApp($A, 'SELECT count(*) FROM mt_sites;'), '3', "A sees its own three sites");
is_($asApp($B, 'SELECT count(*) FROM mt_sites;'), '1', "B sees only its own one");
is_($asApp($B, "SELECT count(*) FROM mt_sites WHERE name LIKE 'O1FIX-SITE-A%';"), '0',
    "and none of A's — isolation holds in the synthetic estate too");

// ===========================================================================
t('PHASE 6 — the documented two-role census reaches a verdict (docs/79 §3, corrected by docs/123)');

// Until docs/123 this phase asserted that the dnb_adminapi run is INDETERMINATE
// "because it cannot read the migration ledger". The ledger is SCHEMA evidence,
// and the owner's run cannot see the data at all, so neither documented run
// could ever say CLEAR or BLOCKED. The assertion is rewritten to the verdict the
// documented DATA run must give, not deleted.
$GLOBALS['rolepw'] = $pw;
$adm = census('dnb_adminapi');
is_($adm['verdict'], 'CLEAR', 'dnb_adminapi — the documented DATA run — reads CLEAR on the clean estate');
is_(str_contains($adm['raw'], 'read path — sites:mt_admin_sites()  services:mt_admin_services()'), true,
    'CONTROL: it took the projection path and did see the estate');
is_(str_contains($adm['raw'], 'migration ledger read in this run       : f'), true,
    'and it says, beside the verdict, that it did NOT read the schema — the owner run supplies that');
$ow = census($own);
is_($ow['verdict'], 'INDETERMINATE', 'the owner run withholds a verdict — FORCE RLS hides the data from it');
is_(str_contains($ow['raw'], '*** HIDDEN'), true, 'and says why: its reads fall back to base tables under RLS');
is_((bool) preg_match('/migrations applied\s+:\s+[1-9][0-9]*/', $ow['raw']), true,
    'while it reads the schema level — the ledger — which is what it is run for');

// Re-admit a violation: drop the O-1 constraints PHASE 4 applied (throwaway database).
sql("ALTER TABLE mt_sites DROP CONSTRAINT mt_sites_service_customer_fkey;
     ALTER TABLE mt_services DROP CONSTRAINT mt_services_id_customer_key;");
sql("INSERT INTO mt_sites (id, customer_id, service_id, name) VALUES
     ('00000000-0000-4000-8000-0000000000aa', '00000000-0000-4000-8000-00000000000a',
      '00000000-0000-4000-8000-0000000000b1', 'O1FIX-SITE-CROSS');");
is_(num("SELECT count(*) FROM mt_sites WHERE name = 'O1FIX-SITE-CROSS'"), 1,
    'CONTROL: the violating row is present again');
$adm = census('dnb_adminapi');
is_($adm['verdict'], 'BLOCKED', 'dnb_adminapi reads BLOCKED over a real violation');
is_($adm['block'], 1, 'BLOCKED(1) — the one row, from the documented role, with no superuser');

// ===========================================================================
t('PHASE 7 — a read the census cannot trust withholds the verdict (docs/123)');

// Constructed grants, in THIS throwaway database only. No current role mixes a
// projection with a base-table read, but a database at an earlier migration
// level could. Measured before docs/123: sites through the base table and
// services through the projection read CLEAR over this very violation.
is_(num_as('dnb_admin', 'SELECT count(*) FROM mt_sites'), 0,
    'PREMISE: dnb_admin reading the base table sees ZERO sites — FORCE RLS, no tenant context');
is_(num('SELECT count(*) FROM mt_sites') > 0, true, 'CONTROL: while the sites exist');
sql('GRANT EXECUTE ON FUNCTION mt_admin_services() TO dnb_admin');
$mix = census('dnb_admin');
is_($mix['verdict'], 'INDETERMINATE', 'sites via the base table + services via the projection: INDETERMINATE, not CLEAR');
is_(str_contains($mix['raw'], '*** HIDDEN — read from base table(s) under row-level security: mt_sites'), true,
    'and it names the hidden read');
sql('REVOKE EXECUTE ON FUNCTION mt_admin_services() FROM dnb_admin');
sql('GRANT EXECUTE ON FUNCTION mt_admin_sites() TO dnb_admin');
is_(census('dnb_admin')['verdict'], 'INDETERMINATE',
    'the reverse mix: INDETERMINATE, not a confident BLOCKED over a hidden table');
sql('REVOKE EXECUTE ON FUNCTION mt_admin_sites() FROM dnb_admin');

sql('REVOKE EXECUTE ON FUNCTION mt_admin_services() FROM dnb_adminapi');
$gap = census('dnb_adminapi');
is_($gap['rc'], 0, 'a missing projection no longer aborts the run — psql exits 0');
is_($gap['verdict'], 'INDETERMINATE', 'and the verdict is withheld');
is_(str_contains($gap['raw'], '*** SECTION 4 UNREADABLE'), true, 'naming the section it could not read');
sql('GRANT EXECUTE ON FUNCTION mt_admin_services() TO dnb_adminapi');
is_(census('dnb_adminapi')['verdict'], 'BLOCKED',
    'CONTROL: with the projection restored the same run reads BLOCKED again');

// ===========================================================================
psql($own, 'postgres', 'DROP DATABASE IF EXISTS ' . DB . ';');
$gone = shell_exec(sprintf('PGHOST=%s PGPORT=%s psql -U %s -d postgres -X -tAc %s 2>&1',
    escapeshellarg($host), escapeshellarg($port), escapeshellarg($own),
    escapeshellarg("SELECT count(*) FROM pg_database WHERE datname = '" . DB . "'")));
t('the synthetic database is removed');
is_(trim((string) $gone), '0', 'the throwaway database is gone — no synthetic residue survives');

exit(t_summary());

exit(t_summary());

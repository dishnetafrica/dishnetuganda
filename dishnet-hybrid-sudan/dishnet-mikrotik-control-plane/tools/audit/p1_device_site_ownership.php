<?php
/**
 * P-1 measurement (docs/71 §1.2, question Q1): can the AUTHORIZED
 * administrative path create a device whose site belongs to a different
 * customer?
 *
 * The invariant under test:   mt_devices.customer_id = mt_sites.customer_id
 *                             for the device's own site_id
 *
 * Read-only with respect to the design: nothing is fixed, no constraint is
 * added, no function is modified. It only CALLS the existing path and records
 * what happens. Disposable database; never Phase 0.
 *
 *   DNB_DSN=... php tools/audit/p1_device_site_ownership.php
 */
declare(strict_types=1);
require __DIR__ . '/../../tests/bootstrap.php';

$owner   = \Dn\Db\Database::owner();
$admin   = \Dn\Db\Database::admin();
$inspect = \Dn\Db\Database::inspector();

$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];
printf("  customer A site belongs to A, customer B site belongs to B\n");
printf("  calling role: %s\n\n", $admin->one('SELECT current_user AS u')['u']);

function state(\Dn\Db\Database $i, string $dev): array {
    return $i->one(
        "SELECT d.customer_id::text AS dev_cust, d.site_id::text AS dev_site,
                s.customer_id::text AS site_cust,
                (d.customer_id = s.customer_id) AS invariant_holds
           FROM mt_devices d LEFT JOIN mt_sites s ON s.id = d.site_id
          WHERE d.id = ?", [$dev]);
}

// ---- case 1: MATCHING customer/site -------------------------------------
$dev1 = $admin->one("SELECT id FROM mt_device_register('P1-MATCH','hAP',null,null,'10.90.0.1','audit:p1')")['id'];
$r1 = null; $e1 = null;
try { $r1 = $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)',
        [$dev1, $A['customer'], $A['site'], 'matching']); }
catch (\Throwable $e) { $e1 = $e->getMessage(); }
$s1 = state($inspect, $dev1);
printf("  CASE 1  matching  (A.customer + A.site)\n");
printf("          call: %s\n", $e1 === null ? 'ACCEPTED' : 'REFUSED — ' . substr($e1,0,90));
printf("          device.customer = site.customer ? %s\n\n",
    $s1['invariant_holds'] === null ? 'no site' : ($s1['invariant_holds'] ? 'YES' : 'NO'));

// ---- case 2: MISMATCHED customer/site -----------------------------------
$dev2 = $admin->one("SELECT id FROM mt_device_register('P1-CROSS','hAP',null,null,'10.90.0.2','audit:p1')")['id'];
$r2 = null; $e2 = null;
try { $r2 = $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)',
        [$dev2, $A['customer'], $B['site'], 'cross-customer']); }
catch (\Throwable $e) { $e2 = $e->getMessage(); }
$s2 = state($inspect, $dev2);
printf("  CASE 2  MISMATCHED  (A.customer + B's site)\n");
printf("          call: %s\n", $e2 === null ? 'ACCEPTED' : 'REFUSED — ' . substr($e2,0,90));
printf("          device.customer_id = %s\n", $s2['dev_cust'] ?? 'NULL');
printf("          its site's customer = %s\n", $s2['site_cust'] ?? 'NULL');
printf("          device.customer = site.customer ? %s\n",
    $s2['invariant_holds'] === null ? 'no site' : ($s2['invariant_holds'] ? 'YES' : '*** NO ***'));

// ---- what, if anything, guards it ---------------------------------------
echo "\n  --- authoritative guards on mt_devices ---\n";
foreach ($inspect->query(
    "SELECT conname, contype, pg_get_constraintdef(oid) AS def
       FROM pg_constraint WHERE conrelid = 'mt_devices'::regclass ORDER BY contype, conname") as $c) {
    printf("    %-4s %-34s %s\n", $c['contype'], $c['conname'], substr($c['def'],0,64));
}
foreach ($inspect->query(
    "SELECT tgname, pg_get_triggerdef(oid) AS def FROM pg_trigger
      WHERE tgrelid = 'mt_devices'::regclass AND NOT tgisinternal ORDER BY tgname") as $t) {
    printf("    trig %-34s %s\n", $t['tgname'], substr($t['def'], strpos($t['def'],'EXECUTE')));
}
foreach ($inspect->query(
    "SELECT polname, polcmd, pg_get_expr(polqual, polrelid) AS using_,
            pg_get_expr(polwithcheck, polrelid) AS check_,
            (SELECT string_agg(r.rolname,',') FROM pg_roles r WHERE r.oid = ANY(polroles)) AS roles
       FROM pg_policy WHERE polrelid = 'mt_devices'::regclass ORDER BY polname") as $p) {
    printf("    pol  %-28s cmd=%s roles=%-14s USING=%s CHECK=%s\n",
        $p['polname'], $p['polcmd'], $p['roles'] ?? 'PUBLIC',
        $p['using_'] ?? '-', $p['check_'] ?? '-');
}

// ---- verdict -------------------------------------------------------------
$exploitable = ($e2 === null && $s2['invariant_holds'] === false);
echo "\n  ================================================================\n";
printf("  P-1 VERDICT: %s\n", $exploitable
    ? 'DOES NOT WORK / exploitable by authorized administrative path'
    : ($e2 !== null ? 'WORKS / protected' : 'NOT ESTABLISHED — inspect above'));
echo "  ================================================================\n";

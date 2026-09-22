<?php
/**
 * SUPERSEDED — kept for the record only. Several steps here ran as the `owner`
 * connection, which FORCE ROW LEVEL SECURITY binds, so their UPDATE/DELETE
 * matched zero rows and measured nothing while printing ALLOWED; one REFUSED
 * label was a duplicate-constraint error caught by the matcher. docs/73 §1.2
 * records this. The findings that stand come from passes 2-4, which print row
 * counts on every act. Do not cite this file's output.
 *
 * N10 remediation EVIDENCE (docs/72 §A.7): measure the three candidate
 * mechanisms for   mt_devices.customer_id = mt_sites.customer_id
 * against the REAL schema, its real roles and its real RLS.
 *
 * Nothing here is a proposal to keep. Every constraint/trigger is added to a
 * DISPOSABLE database built from the migrations and dropped afterwards. No
 * migration is edited, no project role or privilege is created, no production
 * anything is touched. The point is to find out which candidate actually holds
 * under FORCE RLS and the dnb_def_prov exemption — not to install one.
 */
declare(strict_types=1);
require __DIR__ . '/../../tests/bootstrap.php';

$owner   = \Dn\Db\Database::owner();
$admin   = \Dn\Db\Database::admin();
$inspect = \Dn\Db\Database::inspector();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];

$n = 0;
function try_(callable $f): string {
    try { $f(); return 'ALLOWED'; }
    catch (\Throwable $e) {
        $m = $e->getMessage();
        foreach (['violates foreign key constraint','violates check constraint',
                  'permission denied','N10','new row violates row-level security',
                  'MATCH FULL','null value'] as $k) {
            if (stripos($m, $k) !== false) return 'REFUSED (' . $k . ')';
        }
        return 'REFUSED (' . substr(preg_replace('/\s+/',' ',$m), 0, 58) . ')';
    }
}
function reg(\Dn\Db\Database $admin, string $tag): string {
    return $admin->one("SELECT id FROM mt_device_register(?,'hAP',null,null,?,'audit:n10')",
        ["N10-$tag", '10.95.' . (crc32($tag) % 250) . '.' . (crc32($tag.'x') % 250)])['id'];
}
function row(\Dn\Db\Database $i, string $d): string {
    $r = $i->one("SELECT coalesce(substr(customer_id::text,1,8),'NULL') c,
                         coalesce(substr(site_id::text,1,8),'NULL') s
                    FROM mt_devices WHERE id = ?", [$d]);
    return "customer={$r['c']} site={$r['s']}";
}
$line = function (string $label, string $res) { printf("    %-46s %s\n", $label, $res); };

// =========================================================================
echo "\n################ 0. BASELINE — no remediation ################\n";
$d = reg($admin, 'base');
$line('register: customer NULL, site NULL',      try_(fn() => null) . ' — ' . row($inspect, $d));
$line('assign MATCHING  (A + A.site)',           try_(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d,$A['customer'],$A['site'],'m'])));
$d2 = reg($admin, 'base2');
$line('assign MISMATCHED (A + B.site)',          try_(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d2,$A['customer'],$B['site'],'x'])));
$line('  -> resulting row',                      row($inspect, $d2));

// =========================================================================
echo "\n################ C1. COMPOSITE FOREIGN KEY ################\n";
echo "  UNIQUE (id, customer_id) on mt_sites, then FK (site_id, customer_id)\n";
$line('add UNIQUE(id,customer_id) on mt_sites', try_(fn() => $owner->exec(
    'ALTER TABLE mt_sites ADD CONSTRAINT n10_sites_id_cust UNIQUE (id, customer_id)')));
$line('add composite FK against EXISTING data', try_(fn() => $owner->exec(
    'ALTER TABLE mt_devices ADD CONSTRAINT n10_dev_site_cust
       FOREIGN KEY (site_id, customer_id) REFERENCES mt_sites (id, customer_id)')));
echo "  (the row from the baseline mismatch is still present — see above)\n";

// clear the offending row so the constraint can be added, then retry
$line('after clearing the bad row, add FK again', try_(function () use ($owner, $d2) {
    $owner->exec('UPDATE mt_devices SET site_id = NULL WHERE id = ?', [$d2]);
    $owner->exec('ALTER TABLE mt_devices ADD CONSTRAINT n10_dev_site_cust
       FOREIGN KEY (site_id, customer_id) REFERENCES mt_sites (id, customer_id)');
}));

echo "\n  -- MATCH SIMPLE (the default) --\n";
$d3 = reg($admin, 'c1a');
$line('register (both NULL) still legal',   try_(fn() => row($inspect,$d3)) . ' — ' . row($inspect,$d3));
$line('assign MATCHING',                    try_(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d3,$A['customer'],$A['site'],'m'])));
$d4 = reg($admin, 'c1b');
$line('assign MISMATCHED  <== THE TEST',    try_(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d4,$A['customer'],$B['site'],'x'])));
$d5 = reg($admin, 'c1c');
$line('PARTIAL NULL: site set, customer NULL', try_(fn() => $owner->exec(
    'UPDATE mt_devices SET site_id = ?, customer_id = NULL WHERE id = ?', [$B['site'], $d5])));
$line('  -> resulting row',                 row($inspect, $d5));
$line('reassignment A.site -> A.site2 (same cust)', try_(function () use ($owner,$admin,$A,$d3) {
    $s2 = $owner->one("SELECT mt_customer_create('x','t') AS z") ? null : null;
    $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d3,$A['customer'],$A['site'],'again']);
}));
$line('DELETE the site the device points at', try_(fn() => $owner->exec('DELETE FROM mt_sites WHERE id = ?', [$A['site']])));

echo "\n  -- MATCH FULL --\n";
$owner->exec('ALTER TABLE mt_devices DROP CONSTRAINT n10_dev_site_cust');
$line('add FK ... MATCH FULL', try_(fn() => $owner->exec(
    'ALTER TABLE mt_devices ADD CONSTRAINT n10_dev_site_cust
       FOREIGN KEY (site_id, customer_id) REFERENCES mt_sites (id, customer_id) MATCH FULL')));
$d6 = reg($admin, 'c1d');
$line('register (both NULL) under MATCH FULL', try_(fn() => row($inspect,$d6)) . ' — ' . row($inspect,$d6));
$line('PARTIAL NULL under MATCH FULL', try_(fn() => $owner->exec(
    'UPDATE mt_devices SET site_id = ?, customer_id = NULL WHERE id = ?', [$B['site'], $d6])));
$d7 = reg($admin, 'c1e');
$line('assign MISMATCHED under MATCH FULL', try_(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d7,$A['customer'],$B['site'],'x'])));
$owner->exec('ALTER TABLE mt_devices DROP CONSTRAINT n10_dev_site_cust');
$owner->exec('ALTER TABLE mt_sites DROP CONSTRAINT n10_sites_id_cust');

// =========================================================================
echo "\n################ C2. TRIGGER ################\n";
echo "  The question that decides it: can the trigger SEE mt_sites?\n";
echo "  dnb_def_prov has NO grant and NO policy on mt_sites (migration 017),\n";
echo "  and mt_sites has FORCE ROW LEVEL SECURITY.\n\n";
$owner->exec("CREATE OR REPLACE FUNCTION n10_check() RETURNS trigger
  LANGUAGE plpgsql AS \$\$
  DECLARE v uuid; BEGIN
    IF NEW.site_id IS NULL THEN RETURN NEW; END IF;
    SELECT customer_id INTO v FROM mt_sites WHERE id = NEW.site_id;
    IF NOT FOUND THEN
      RAISE EXCEPTION 'N10 trigger: site not visible to %', current_user; END IF;
    IF v IS DISTINCT FROM NEW.customer_id THEN
      RAISE EXCEPTION 'N10 trigger: site belongs to another customer'; END IF;
    RETURN NEW; END \$\$;");
$owner->exec('CREATE TRIGGER n10_trg BEFORE INSERT OR UPDATE ON mt_devices
              FOR EACH ROW EXECUTE FUNCTION n10_check()');
$d8 = reg($admin, 'c2a');
$line('plain trigger, assign MATCHING',    try_(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d8,$B['customer'],$B['site'],'m'])));
$d9 = reg($admin, 'c2b');
$line('plain trigger, assign MISMATCHED',  try_(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d9,$B['customer'],$A['site'],'x'])));

echo "\n  -- same trigger, but SECURITY DEFINER owned by the table owner --\n";
$owner->exec('ALTER FUNCTION n10_check() SECURITY DEFINER');
$owner->exec('ALTER FUNCTION n10_check() SET search_path = public, pg_temp');
$d10 = reg($admin, 'c2c');
$line('definer trigger, assign MATCHING',   try_(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d10,$B['customer'],$B['site'],'m'])));
$d11 = reg($admin, 'c2d');
$line('definer trigger, assign MISMATCHED', try_(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d11,$B['customer'],$A['site'],'x'])));

echo "\n  -- can the authorized caller get around a trigger? --\n";
$line("dnb_admin: SET session_replication_role=replica", try_(fn() => $admin->exec("SET session_replication_role = 'replica'")));
$line('dnb_admin: ALTER TABLE ... DISABLE TRIGGER ALL',  try_(fn() => $admin->exec('ALTER TABLE mt_devices DISABLE TRIGGER ALL')));
$line('dnb_admin: DROP TRIGGER',                         try_(fn() => $admin->exec('DROP TRIGGER n10_trg ON mt_devices')));
$line('dnb_admin: direct UPDATE, bypassing the function', try_(fn() => $admin->exec(
    'UPDATE mt_devices SET site_id = ? WHERE id = ?', [$A['site'], $d10])));
$owner->exec('DROP TRIGGER n10_trg ON mt_devices');
$owner->exec('DROP FUNCTION n10_check()');

// =========================================================================
echo "\n################ C3. FUNCTION-LEVEL VALIDATION ONLY ################\n";
echo "  Not installed. The question that bounds it: what else can reach the table?\n";
foreach ([['dnb_admin','UPDATE'],['dnb_admin','INSERT'],['dnb_app','UPDATE'],['dnb_worker','UPDATE']] as [$r,$p]) {
    $has = $inspect->one('SELECT has_table_privilege(?, ?, ?) AS h', [$r, 'mt_devices', $p])['h'];
    printf("    %-46s %s\n", "$r has $p on mt_devices directly", $has ? '*** YES ***' : 'no');
}
$line('dnb_admin: SET ROLE dnb_def_prov', try_(fn() => $admin->exec('SET ROLE dnb_def_prov')));
echo "\n";

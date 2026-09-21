<?php
/**
 * N10 evidence, corrected run. The first pass (n10_candidates.php) had several
 * steps that silently did nothing: an `owner` connection is bound by FORCE ROW
 * LEVEL SECURITY, so its UPDATE/DELETE matched zero rows. Anything that relied
 * on those steps is re-measured here with a connection that can actually write,
 * and every act under test states its ROW COUNT so a no-op cannot be mistaken
 * for a pass.  Disposable database; dropped by the runner.
 */
declare(strict_types=1);
require __DIR__ . '/../../tests/bootstrap.php';
$owner = \Dn\Db\Database::owner();
$admin = \Dn\Db\Database::admin();
$app   = \Dn\Db\Database::app();
$ins   = \Dn\Db\Database::inspector();          // BYPASSRLS — setup + inspection only
$ids = seed_two_customers($owner); $A=$ids['A']; $B=$ids['B'];

function att(callable $f): string {
    try { $r = $f(); return is_int($r) ? "OK (rows=$r)" : 'OK'; }
    catch (\Throwable $e) { return 'REFUSED: ' . substr(preg_replace('/\s+/',' ',$e->getMessage()), 0, 72); }
}
$L = fn(string $a, string $b) => printf("    %-44s %s\n", $a, $b);
$reg = fn(string $t) => $admin->one("SELECT id FROM mt_device_register(?,'hAP',null,null,?,'n10')",
        ["N10B-$t", '10.96.0.' . (abs(crc32($t)) % 250)])['id'];
$show = fn(string $d) => $ins->one("SELECT coalesce(substr(customer_id::text,1,8),'NULL') c,
        coalesce(substr(site_id::text,1,8),'NULL') s FROM mt_devices WHERE id=?", [$d]);

echo "\n########## 1. Does ADD FOREIGN KEY validate EXISTING bad rows? ##########\n";
echo "  It matters: a constraint that adds cleanly while validating nothing is\n";
echo "  a migration that reports success and enforces nothing for old rows.\n\n";
$bad = $reg('bad');
$admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$bad,$A['customer'],$B['site'],'bad']);
$r = $show($bad);
$L('planted violating row', "customer={$r['c']} site={$r['s']}");
$L('rows the OWNER can see in mt_devices', (string) $owner->one('SELECT count(*) n FROM mt_devices')['n']);
$L('rows the INSPECTOR can see',          (string) $ins->one('SELECT count(*) n FROM mt_devices')['n']);
$owner->exec('ALTER TABLE mt_sites ADD CONSTRAINT n10u UNIQUE (id, customer_id)');
$L('ADD composite FK with the bad row present', att(fn() => $owner->exec(
  'ALTER TABLE mt_devices ADD CONSTRAINT n10fk FOREIGN KEY (site_id, customer_id)
     REFERENCES mt_sites (id, customer_id)')));
$has = $ins->one("SELECT count(*) n FROM pg_constraint WHERE conname='n10fk'")['n'];
$L('constraint now exists?', $has ? 'YES' : 'no');
$still = $ins->one('SELECT (d.customer_id IS DISTINCT FROM s.customer_id) AS bad
                      FROM mt_devices d JOIN mt_sites s ON s.id=d.site_id WHERE d.id=?', [$bad]);
$L('the violating row SURVIVED the constraint?', $still['bad'] ? '*** YES — validated nothing ***' : 'no');
$L('is the constraint marked validated?', (string) $ins->one(
  "SELECT convalidated::text v FROM pg_constraint WHERE conname='n10fk'")['v']);

echo "\n########## 2. Does the FK still REFUSE new violations? ##########\n";
$d = $reg('new');
$L('assign MISMATCHED through mt_device_assign', att(fn() => $admin->one(
  'SELECT id FROM mt_device_assign(?,?,?,?)', [$d,$A['customer'],$B['site'],'x'])));
$d2 = $reg('ok');
$L('assign MATCHING through mt_device_assign', att(fn() => $admin->one(
  'SELECT id FROM mt_device_assign(?,?,?,?)', [$d2,$A['customer'],$A['site'],'m'])));
$L('  -> row', json_encode($show($d2)));

echo "\n########## 3. PARTIAL NULL, measured with a writer that can write ##########\n";
$L('site set + customer NULL  (MATCH SIMPLE)', att(fn() => $ins->exec(
  'UPDATE mt_devices SET site_id=?, customer_id=NULL WHERE id=?', [$B['site'], $d2])));
$L('  -> row', json_encode($show($d2)));
$ins->exec('UPDATE mt_devices SET site_id=NULL, customer_id=? WHERE id=?', [$A['customer'], $d2]);
$owner->exec('ALTER TABLE mt_devices DROP CONSTRAINT n10fk');
$owner->exec('ALTER TABLE mt_devices ADD CONSTRAINT n10fk FOREIGN KEY (site_id, customer_id)
                REFERENCES mt_sites (id, customer_id) MATCH FULL');
$L('site set + customer NULL  (MATCH FULL)', att(fn() => $ins->exec(
  'UPDATE mt_devices SET site_id=?, customer_id=NULL WHERE id=?', [$B['site'], $d2])));
$d3 = $reg('mf');
$L('register both NULL        (MATCH FULL)', att(fn() => (int) ($show($d3)['c'] === 'NULL')) . ' — row ' . json_encode($show($d3)));

echo "\n########## 4. Site deletion, with the composite FK present ##########\n";
$L('DELETE a site (inspector, so it really runs)', att(fn() => $ins->exec(
  'DELETE FROM mt_sites WHERE id=?', [$A['site']])));
$L('  devices still pointing at it', (string) $ins->one(
  'SELECT count(*) n FROM mt_devices WHERE site_id=?', [$A['site']])['n']);
$owner->exec('ALTER TABLE mt_devices DROP CONSTRAINT n10fk');
$owner->exec('ALTER TABLE mt_sites DROP CONSTRAINT n10u');

echo "\n########## 5. The trigger's real failure message ##########\n";
$owner->exec("CREATE OR REPLACE FUNCTION n10t() RETURNS trigger LANGUAGE plpgsql AS \$\$
  DECLARE v uuid; BEGIN
    IF NEW.site_id IS NULL THEN RETURN NEW; END IF;
    SELECT customer_id INTO v FROM mt_sites WHERE id=NEW.site_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'N10: site INVISIBLE to %', current_user; END IF;
    IF v IS DISTINCT FROM NEW.customer_id THEN RAISE EXCEPTION 'N10: wrong customer'; END IF;
    RETURN NEW; END \$\$;");
$owner->exec('CREATE TRIGGER n10trg BEFORE INSERT OR UPDATE ON mt_devices
              FOR EACH ROW EXECUTE FUNCTION n10t()');
$d4 = $reg('t1');
$L('plain trigger  + MATCHING assign', att(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d4,$B['customer'],$B['site'],'m'])));
$owner->exec('ALTER FUNCTION n10t() SECURITY DEFINER SET search_path=public,pg_temp');
$L('function owner', (string) $ins->one("SELECT pg_get_userbyid(proowner) o FROM pg_proc WHERE proname='n10t'")['o']);
$d5 = $reg('t2');
$L('DEFINER trigger + MATCHING assign', att(fn() => $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d5,$B['customer'],$B['site'],'m'])));
$owner->exec('DROP TRIGGER n10trg ON mt_devices'); $owner->exec('DROP FUNCTION n10t()');

/* Section 6 removed. It asked whether the request role can violate N10, but it
 * ran after section 4 had deleted the site it needed, so it died on a foreign
 * key error rather than measuring anything -- and a script that ends in a fatal
 * error is one whose earlier output nobody should trust at a glance. The
 * question is measured properly, in its own fresh database, by pass 3 (6').  */
echo "\n  (section 6 moved to pass 3 -- see n10_candidates3.php)\n\n";
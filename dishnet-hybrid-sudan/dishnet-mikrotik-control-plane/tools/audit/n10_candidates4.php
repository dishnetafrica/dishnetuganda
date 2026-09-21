<?php
/** N10 evidence, fourth pass: the combination the trade-off in pass 3 implies —
 *  composite FK (MATCH SIMPLE) + a single-table CHECK closing the partial-NULL
 *  hole — against all six states, site deletion, and the request-role path.
 *  Also: does the remediation migration validate existing rows at all?
 *  Disposable database; dropped by the runner. */
declare(strict_types=1);
require __DIR__ . '/../../tests/bootstrap.php';
$owner=\Dn\Db\Database::owner(); $admin=\Dn\Db\Database::admin();
$app=\Dn\Db\Database::app();     $ins=\Dn\Db\Database::inspector();
$ids=seed_two_customers($owner); $A=$ids['A']; $B=$ids['B'];
$L = fn(string $a, string $b) => printf("    %-48s %s\n", $a, $b);
$att = function (callable $f): string {
    try { $r=$f(); return is_int($r) ? "ALLOWED (rows=$r)" : 'ALLOWED'; }
    catch (\Throwable $e) { $m=preg_replace('/\s+/',' ',$e->getMessage());
        return 'REFUSED (' . (stripos($m,'check constraint')!==false ? 'CHECK'
             : (stripos($m,'foreign key')!==false ? 'FK' : substr($m,0,40))) . ')'; } };
$reg = fn(string $t) => $admin->one("SELECT id FROM mt_device_register(?,'hAP',null,null,?,'n10')",
        ["N10D-$t", '10.98.0.' . (abs(crc32($t))%250)])['id'];
$show = fn(string $d) => json_encode($ins->one("SELECT coalesce(substr(customer_id::text,1,8),'NULL') c,
        coalesce(substr(site_id::text,1,8),'NULL') s FROM mt_devices WHERE id=?", [$d]));

echo "\n##### THE COMBINATION: composite FK (MATCH SIMPLE) + one-table CHECK #####\n\n";
$owner->exec('ALTER TABLE mt_sites ADD CONSTRAINT n10u UNIQUE (id, customer_id)');
$owner->exec('ALTER TABLE mt_devices ADD CONSTRAINT n10fk FOREIGN KEY (site_id, customer_id)
                REFERENCES mt_sites (id, customer_id)');
$owner->exec('ALTER TABLE mt_devices ADD CONSTRAINT n10chk
                CHECK (site_id IS NULL OR customer_id IS NOT NULL)');
$L('both constraints added', 'yes');

echo "\n  -- the six reachable states --\n";
$d1=$reg('s1');
$L('1 unassigned stock: both NULL',        'ALLOWED — ' . $show($d1));
$d2=$reg('s2');
$L('2 assign MATCHING (via the function)', $att(fn()=>$admin->one('SELECT id FROM mt_device_assign(?,?,?,?)',[$d2,$A['customer'],$A['site'],'m'])));
$d3=$reg('s3');
$L('3 assign MISMATCHED (via the function)',$att(fn()=>$admin->one('SELECT id FROM mt_device_assign(?,?,?,?)',[$d3,$A['customer'],$B['site'],'x'])));
$d4=$reg('s4');
$L('4 site set, customer NULL  <== the hole',$att(fn()=>$ins->exec('UPDATE mt_devices SET site_id=?, customer_id=NULL WHERE id=?',[$A['site'],$d4])));
$d5=$reg('s5');
$L('5 customer set, site NULL (claimed, unsited)',$att(fn()=>$ins->exec('UPDATE mt_devices SET customer_id=? WHERE id=?',[$A['customer'],$d5])));
$L('6 reassign to a site of the SAME customer',$att(fn()=>$admin->one('SELECT id FROM mt_device_assign(?,?,?,?)',[$d2,$A['customer'],$A['site'],'again'])));

echo "\n  -- site deletion still works --\n";
$L('DELETE the site a live device points at', $att(fn()=>$ins->exec('DELETE FROM mt_sites WHERE id=?',[$A['site']])));
$L('  -> device row (customer must survive)', $show($d2));

echo "\n  -- the request role, now --\n";
$ids2=seed_two_customers($owner); $A2=$ids2['A']; $B2=$ids2['B'];
$m=$reg('app'); $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)',[$m,$A2['customer'],$A2['site'],'mine']);
$ctx=new \Dn\Tenancy\TenantContext($app);
$L("dnb_app (ctx=A): point own device at B's site", (string)$ctx->run($A2['customer'],
   fn(\Dn\Db\Database $db)=>$att(fn()=>$db->exec('UPDATE mt_devices SET site_id=? WHERE id=?',[$B2['site'],$m]))));
$L('  -> device row', $show($m));

echo "\n##### MIGRATION SAFETY: does ADD validate existing rows? #####\n";
$owner->exec('ALTER TABLE mt_devices DROP CONSTRAINT n10fk');
$owner->exec('ALTER TABLE mt_devices DROP CONSTRAINT n10chk');
$bad=$reg('bad'); $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)',[$bad,$A2['customer'],$B2['site'],'bad']);
$L('planted violating row', $show($bad));
$L('OWNER sees rows in mt_devices',  (string)$owner->one('SELECT count(*) n FROM mt_devices')['n']);
$L('ADD FK as the table owner', $att(fn()=>$owner->exec(
   'ALTER TABLE mt_devices ADD CONSTRAINT n10fk FOREIGN KEY (site_id, customer_id)
      REFERENCES mt_sites (id, customer_id)')));
$L('marked convalidated?', (string)$ins->one("SELECT convalidated::text v FROM pg_constraint WHERE conname='n10fk'")['v']);
$L('violating row still violating?', $ins->one('SELECT (d.customer_id IS DISTINCT FROM s.customer_id) b
      FROM mt_devices d JOIN mt_sites s ON s.id=d.site_id WHERE d.id=?',[$bad])['b'] ? '*** YES ***':'no');
$L('how many violations exist (BYPASSRLS count)', (string)$ins->one(
   'SELECT count(*) n FROM mt_devices d JOIN mt_sites s ON s.id=d.site_id
     WHERE d.customer_id IS DISTINCT FROM s.customer_id')['n']);
echo "\n";

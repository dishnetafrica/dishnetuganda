<?php
/** N10 evidence, third pass: the two cases the second pass could not settle.
 *  4' site deletion while a device really does point at that site, under each
 *     MATCH mode;  6' whether the REQUEST role can violate the invariant.
 *  Disposable database; dropped by the runner. */
declare(strict_types=1);
require __DIR__ . '/../../tests/bootstrap.php';
$owner=\Dn\Db\Database::owner(); $admin=\Dn\Db\Database::admin();
$app=\Dn\Db\Database::app();     $ins=\Dn\Db\Database::inspector();
$ids=seed_two_customers($owner); $A=$ids['A']; $B=$ids['B'];
$L = fn(string $a, string $b) => printf("    %-46s %s\n", $a, $b);
$att = function (callable $f): string {
    try { $r=$f(); return is_int($r) ? "OK (rows=$r)" : 'OK'; }
    catch (\Throwable $e) { return 'REFUSED: ' . substr(preg_replace('/\s+/',' ',$e->getMessage()),0,64); } };
$reg = fn(string $t) => $admin->one("SELECT id FROM mt_device_register(?,'hAP',null,null,?,'n10')",
        ["N10C-$t", '10.97.0.' . (abs(crc32($t))%250)])['id'];
$show = fn(string $d) => json_encode($ins->one("SELECT coalesce(substr(customer_id::text,1,8),'NULL') c,
        coalesce(substr(site_id::text,1,8),'NULL') s FROM mt_devices WHERE id=?", [$d]));

echo "\n########## 4'. Site deletion while a device DOES point at that site ##########\n";
$owner->exec('ALTER TABLE mt_sites ADD CONSTRAINT n10u UNIQUE (id, customer_id)');
foreach (['MATCH SIMPLE' => '', 'MATCH FULL' => ' MATCH FULL'] as $mode => $sql) {
    $ids2 = seed_two_customers($owner); $A2=$ids2['A'];
    $owner->exec("ALTER TABLE mt_devices ADD CONSTRAINT n10fk FOREIGN KEY (site_id, customer_id)
                    REFERENCES mt_sites (id, customer_id)$sql");
    $d = $reg('del' . strlen($sql));
    $admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$d,$A2['customer'],$A2['site'],'live']);
    printf("  -- %s --\n", $mode);
    $L('device assigned, matching',            $show($d));
    $L('DELETE that site',                     $att(fn() => $ins->exec('DELETE FROM mt_sites WHERE id=?', [$A2['site']])));
    $L('  -> device row afterwards',           $show($d));
    $owner->exec('ALTER TABLE mt_devices DROP CONSTRAINT n10fk');
}
$owner->exec('ALTER TABLE mt_sites DROP CONSTRAINT n10u');

echo "\n########## 6'. Can the REQUEST role (dnb_app) violate N10? ##########\n";
$ids3 = seed_two_customers($owner); $A3=$ids3['A']; $B3=$ids3['B'];
$mine = $reg('app');
$admin->one('SELECT id FROM mt_device_assign(?,?,?,?)', [$mine,$A3['customer'],$A3['site'],'mine']);
$L("A's device, correctly assigned", $show($mine));
$L('dnb_app grant: UPDATE on mt_devices', $ins->one(
    "SELECT has_table_privilege('dnb_app','mt_devices','UPDATE')::text h")['h']);
$ctx = new \Dn\Tenancy\TenantContext($app);
$L("as dnb_app (ctx=A): SET site_id = B's site", (string) $ctx->run($A3['customer'],
    function (\Dn\Db\Database $db) use ($mine,$B3,$att) {
        return $att(fn() => $db->exec('UPDATE mt_devices SET site_id=? WHERE id=?', [$B3['site'], $mine])); }));
$L('  -> device row afterwards', $show($mine));
$f = $ins->one('SELECT (d.customer_id IS DISTINCT FROM s.customer_id) AS bad FROM mt_devices d
                  JOIN mt_sites s ON s.id=d.site_id WHERE d.id=?', [$mine]);
$L('  -> N10 violated by the REQUEST role?', $f && $f['bad'] ? '*** YES ***' : 'no');
$L('  (does dnb_app hold EXECUTE on mt_device_assign?)', $ins->one(
    "SELECT has_function_privilege('dnb_app','mt_device_assign(uuid,uuid,uuid,text)','EXECUTE')::text h")['h']);
echo "\n";

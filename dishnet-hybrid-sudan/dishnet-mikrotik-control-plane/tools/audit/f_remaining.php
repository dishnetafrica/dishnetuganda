<?php
$base='/home/user/dishnetuganda/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane';
require "{$base}/src/autoload.php";
use Dn\Db\Database; use Dn\Tenancy\TenantContext;
$ins=Database::inspector(); $app=Database::app(); $ctx=new TenantContext($app);
$admin=Database::admin(); $work=Database::worker(); $radius=Database::radius();
$P=$ins->one("SELECT id FROM mt_customers WHERE name='CustP'")['id'];
$Q=$ins->one("SELECT id FROM mt_customers WHERE name='CustQ'")['id'];
function res($l,$v){ printf("  %-50s %s\n",$l,$v); }
function attempt(string $l, callable $fn): void {
  try { $fn(); res($l,'ALLOWED  <--'); }
  catch (\Throwable $e) { preg_match('/SQLSTATE\[(\w+)\]/',$e->getMessage(),$m);
    $msg=strstr($e->getMessage(),'ERROR:')?:$e->getMessage();
    res($l,'DENIED '.($m[1]??'?').' '.substr(preg_replace('/\s+/',' ',$msg),0,40)); }
}
// fixtures for Q, built the legitimate way
$qs=$ins->one("SELECT id FROM mt_services WHERE customer_id=?",[$Q])['id'];
$qdev=$admin->one("SELECT id FROM mt_device_register('SER-Q9','hAP',null,null,'10.90.0.9','tech')")['id'];
$admin->one("SELECT id FROM mt_device_assign(?,?,?,?)",[$qdev,$Q,null,'Q AP']);
$admin->one("SELECT mt_device_set_secret(?,?,?) AS ok",[$qdev,'mgmt','sealed-Q']);
$qbits=$ctx->run($Q, function(Database $db) use($Q){
  $pr=$db->one("INSERT INTO mt_profiles (rate_down_bps,rate_up_bps,session_timeout_s,shared_users)
                VALUES (9000001,500000,3600,2) ON CONFLICT DO NOTHING RETURNING id")['id']
      ?? $db->one("SELECT id FROM mt_profiles LIMIT 1")['id'];
  $pl=$db->one("INSERT INTO mt_plans (customer_id,profile_id,name,duration_s,rate_down_bps,rate_up_bps,
                devices_per_voucher,mode,price_minor,currency) VALUES (?,?,'Qplan',3600,1000000,500000,2,'elapsed',5000,'UGX')
                RETURNING id",[$Q,$pr])['id'];
  $v=$db->one("INSERT INTO mt_vouchers (customer_id,plan_id,code,price_minor,currency,duration_s)
               VALUES (?,?,'QCODE-123',5000,'UGX',3600) RETURNING id",[$Q,$pl])['id'];
  $db->exec("INSERT INTO mt_hotspot_users (voucher_id,customer_id,radius_username) VALUES (?,?,'u-Q9')",[$v,$Q]);
  return ['plan'=>$pl,'voucher'=>$v];
});
$pprin=$ins->one("SELECT id FROM mt_principals WHERE customer_id=?",[$P])['id'];
$qprin=$ins->one("SELECT id FROM mt_principals WHERE customer_id=?",[$Q])['id'];

echo "== F3: cross-object writes, using KNOWN Q object ids ==\n";
$asP=fn(string $sql,array $a)=>$ctx->run($P, fn(Database $db)=>$db->exec($sql,$a));
attempt("secret row on Q's DEVICE, labelled P", fn()=>$asP(
  "INSERT INTO mt_device_secrets (device_id,customer_id,username,secret_sealed) VALUES (?,?,?,?)",
  [$qdev,$P,'planted','x']));
attempt("config row on Q's DEVICE, labelled P", fn()=>$asP(
  "INSERT INTO mt_device_config (device_id,customer_id,desired) VALUES (?,?,'{}'::jsonb)",[$qdev,$P]));
attempt("uplink sample on Q's DEVICE, labelled P", fn()=>$asP(
  "INSERT INTO mt_uplink_samples (device_id,customer_id,rx_bps,tx_bps) VALUES (?,?,?,?)",[$qdev,$P,1,1]));
attempt("voucher against Q's PLAN, labelled P", fn()=>$asP(
  "INSERT INTO mt_vouchers (customer_id,plan_id,code,price_minor,currency,duration_s)
   VALUES (?,?,'STOLEN-1',1,'UGX',60)",[$P,$qbits['plan']]));
attempt("site under Q's SERVICE, labelled P", fn()=>$asP(
  "INSERT INTO mt_sites (customer_id,service_id,name) VALUES (?,?,'planted')",[$P,$qs]));
attempt("auth session for Q's PRINCIPAL, labelled P", fn()=>$asP(
  "INSERT INTO mt_auth_sessions (principal_id,customer_id,token_hash,expires_at)
   VALUES (?,?,'planted-f7', now()+interval '1 hour')",[$qprin,$P]));
res('and Q\'s device secret is still Q\'s',
  $ins->one("SELECT count(*) AS n FROM mt_device_secrets WHERE device_id=?",[$qdev])['n'].' row(s)');

echo "\n== F6: voucher redemption across customers ==\n";
$before=$ins->one("SELECT state FROM mt_vouchers WHERE code='QCODE-123'")['state'];
$out=$ctx->run($P, fn(Database $db)=>$db->query("SELECT * FROM mt_voucher_redeem(?)",['QCODE-123']));
$after=$ins->one("SELECT state FROM mt_vouchers WHERE code='QCODE-123'")['state'];
res("P redeems Q's voucher code (state {$before} ->)", $after);
res('  and learns which customer owns it', $out ? ($out[0]['customer_id']===$Q ? "YES — Q's uuid returned" : 'no') : 'no rows');
res('  could P have found the code without being told?',
  'keyspace ~1.1e15; not enumerable — it is a bearer credential');

echo "\n== F7: token / principal association ==\n";
$r=$ctx->run($P, fn(Database $db)=>$db->query("SELECT * FROM mt_auth_resolve_token(?)",['planted-f7']));
if ($r) {
  res('forged session resolves', 'YES');
  res('  principal returned belongs to', $r[0]['principal_id']===$qprin ? 'Q  <-- mismatch accepted' : 'P');
  res('  customer returned', $r[0]['customer_id']===$P ? 'P — tenant does NOT cross' : 'Q  <-- ESCALATION');
} else { res('forged session resolves','no'); }
attempt("P plants a session labelled Q", fn()=>$asP(
  "INSERT INTO mt_auth_sessions (principal_id,customer_id,token_hash,expires_at)
   VALUES (?,?,'planted-q', now()+interval '1 hour')",[$qprin,$Q]));
attempt("P repoints its OWN principal at Q", fn()=>$asP(
  "UPDATE mt_principals SET customer_id = ? WHERE id = ?",[$Q,$pprin]));

echo "\n== F8: who can write telemetry ==\n";
foreach ([['dnb_app (request)',$app],['dnb_worker',$work],['dnb_admin',$admin],['dnb_radius',$radius]] as [$lbl,$conn]) {
  try { $conn->exec('BEGIN'); $conn->pdo()->prepare("SELECT set_config('app.customer_id',?,true)")->execute([$P]);
        $conn->exec("INSERT INTO mt_uplink_samples (device_id,customer_id,rx_bps,tx_bps) VALUES (?,?,?,?)",[$qdev,$P,777,777]);
        $conn->exec('ROLLBACK'); res("{$lbl} direct INSERT into mt_uplink_samples",'ALLOWED  <--'); }
  catch(\Throwable $e){ try{$conn->exec('ROLLBACK');}catch(\Throwable){} res("{$lbl} direct INSERT into mt_uplink_samples",'DENIED'); }
}
attempt('P fabricates a sample for its OWN device id', fn()=>$asP(
  "INSERT INTO mt_uplink_samples (device_id,customer_id,rx_bps,tx_bps)
   VALUES ((SELECT id FROM mt_devices LIMIT 1),?,999999999,999999999)",[$P]));

echo "\n== F5: a NEW object under the production migration path ==\n";
$ins->pdo()->exec("CREATE TABLE mt_f5_probe (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), customer_id uuid)");
$ins->pdo()->exec("CREATE FUNCTION mt_f5_fn() RETURNS int LANGUAGE sql AS 'SELECT 1'");
foreach (['dnb_app'=>$app,'dnb_worker'=>$work,'dnb_admin'=>$admin,'dnb_radius'=>$radius] as $lbl=>$conn) {
  $t=$f='-';
  try { $conn->query("SELECT 1 FROM mt_f5_probe"); $t='SELECT ok'; } catch(\Throwable){ $t='denied'; }
  try { $conn->one("SELECT mt_f5_fn() AS x"); $f='EXECUTE ok'; } catch(\Throwable){ $f='denied'; }
  res("new table / new function as {$lbl}", "{$t} / {$f}");
}
res('new table RLS', $ins->one("SELECT relrowsecurity::text AS r FROM pg_class WHERE relname='mt_f5_probe'")['r']);
$ins->pdo()->exec("DROP TABLE mt_f5_probe"); $ins->pdo()->exec("DROP FUNCTION mt_f5_fn()");

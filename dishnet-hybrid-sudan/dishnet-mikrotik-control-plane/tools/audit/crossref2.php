<?php
$base='/home/user/dishnetuganda/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane';
require "{$base}/src/autoload.php";
use Dn\Db\Database;
$owner=Database::owner(); $app=Database::app();
$P=$owner->one("SELECT id FROM mt_customers WHERE name='Cust P'")['id'];
$Q=$owner->one("SELECT id FROM mt_customers WHERE name='Cust Q'")['id'];
// Ids the attacker is ASSUMED to know (the stated threat model).
$qsvc=$owner->one("SELECT id FROM mt_services WHERE customer_id=?",[$Q])['id'];
$qvch=$owner->one("SELECT id FROM mt_vouchers WHERE customer_id=?",[$Q])['id'];
$qdev=$owner->one("SELECT id FROM mt_devices WHERE customer_id=? LIMIT 1",[$Q])['id'];
$qplan=$owner->one("SELECT id FROM mt_plans WHERE customer_id=?",[$Q])['id'];
$qbatch=$owner->one("SELECT id FROM mt_voucher_batches WHERE customer_id=?",[$Q])['id'];
$qprin=$owner->one("SELECT id FROM mt_principals WHERE customer_id=?",[$Q])['id'];

$cases=[
 'site under Q service, labelled P' =>
  ["INSERT INTO mt_sites (customer_id,service_id,name) VALUES (?,?,'planted')",[$P,$qsvc]],
 'hotspot user on Q voucher, labelled P' =>
  ["INSERT INTO mt_hotspot_users (voucher_id,customer_id,radius_username) VALUES (?,?,'planted')",[$qvch,$P]],
 'voucher in Q batch, labelled P' =>
  ["INSERT INTO mt_vouchers (customer_id,plan_id,batch_id,code,price_minor,currency,duration_s) VALUES (?,?,?,'X-2',1,'UGX',60)",[$P,$qplan,$qbatch]],
 'auth session for Q principal, labelled P' =>
  ["INSERT INTO mt_auth_sessions (principal_id,customer_id,token_hash,expires_at) VALUES (?,?,'planted', now()+interval '1 hour')",[$qprin,$P]],
 'entitlement on Q service, labelled P' =>
  ["INSERT INTO mt_entitlements (service_id,customer_id,key,int_value) VALUES (?,?,'max_routers',9999)",[$qsvc,$P]],
 'intent naming Q device in payload, labelled P' =>
  ["INSERT INTO mt_intents (customer_id,kind,payload) VALUES (?,'device.push', jsonb_build_object('device_id', ?::text))",[$P,$qdev]],
];
foreach($cases as $label=>[$sql,$args]){
  try{
    $app->exec('BEGIN'); $app->exec("SET LOCAL app.customer_id = '{$P}'");
    $app->exec($sql,$args); $app->exec('ROLLBACK');
    printf("  %-44s ALLOWED  <-- cross-object write\n",$label);
  }catch(\Throwable $e){
    try{$app->exec('ROLLBACK');}catch(\Throwable){}
    preg_match('/SQLSTATE\[(\w+)\]/',$e->getMessage(),$m);
    $msg=preg_replace('/\s+/',' ',strstr($e->getMessage(),'ERROR:')?:$e->getMessage());
    printf("  %-44s DENIED %s %s\n",$label,$m[1]??'?',substr($msg,0,46));
  }
}

<?php
$base='/home/user/dishnetuganda/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane';
require "{$base}/src/autoload.php";
use Dn\Db\Database;
$owner=Database::owner(); $app=Database::app();
$P=$owner->one("SELECT id FROM mt_customers WHERE name='Cust P'")['id'];
$Q=$owner->one("SELECT id FROM mt_customers WHERE name='Cust Q'")['id'];
// A Q-owned device with NO secret and NO config yet.
$bare=$owner->one("INSERT INTO mt_devices (customer_id,serial,model,tunnel_ip)
                   VALUES (?,'SER-Q-BARE','hAP','10.98.0.7') RETURNING id",[$Q])['id'];
$qplan=$owner->one("SELECT id FROM mt_plans WHERE customer_id = ?",[$Q])['id'];
$qsite=$owner->one("SELECT id FROM mt_sites WHERE customer_id = ?",[$Q])['id'];

$cases = [
 'secret row on Q-owned device, labelled P' =>
   ["INSERT INTO mt_device_secrets (device_id,customer_id,username,secret_sealed) VALUES (?,?,?,?)",[$bare,$P,'mgmt','planted']],
 'config row on Q-owned device, labelled P' =>
   ["INSERT INTO mt_device_config (device_id,customer_id,desired) VALUES (?,?,'{\"planted\":true}'::jsonb)",[$bare,$P]],
 'uplink sample on Q-owned device, labelled P' =>
   ["INSERT INTO mt_uplink_samples (device_id,customer_id,rx_bps,tx_bps) VALUES (?,?,?,?)",[$bare,$P,999,999]],
 'voucher against Q-owned plan, labelled P' =>
   ["INSERT INTO mt_vouchers (customer_id,plan_id,code,price_minor,currency,duration_s) VALUES (?,?,?,?,?,?)",[$P,$qplan,'X-1',1,'UGX',60]],
 'site row referencing Q service, labelled P' =>
   ["INSERT INTO mt_sites (customer_id,service_id,name) VALUES (?, (SELECT id FROM mt_services WHERE customer_id = ? LIMIT 1), 'planted')",[$P,$Q]],
 'hotspot user on Q voucher, labelled P' =>
   ["INSERT INTO mt_hotspot_users (voucher_id,customer_id,radius_username) VALUES ((SELECT id FROM mt_vouchers WHERE customer_id = ? LIMIT 1), ?, 'planted')",[$Q,$P]],
];
foreach ($cases as $label=>[$sql,$args]) {
  try {
    $app->exec('BEGIN'); $app->exec("SET LOCAL app.customer_id = '{$P}'");
    $app->exec($sql,$args);
    $app->exec('ROLLBACK');
    printf("  %-44s ALLOWED  <-- cross-object write\n",$label);
  } catch(\Throwable $e){
    try{$app->exec('ROLLBACK');}catch(\Throwable){}
    preg_match('/SQLSTATE\[(\w+)\]/',$e->getMessage(),$m);
    $msg=preg_replace('/\s+/',' ',strstr($e->getMessage(),'ERROR:')?:$e->getMessage());
    printf("  %-44s DENIED %s %s\n",$label,$m[1]??'?',substr($msg,0,46));
  }
}
$owner->exec("DELETE FROM mt_devices WHERE id = ?",[$bare]);

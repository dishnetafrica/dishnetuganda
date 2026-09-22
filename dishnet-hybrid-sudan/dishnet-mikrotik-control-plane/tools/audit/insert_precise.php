<?php
$base='/home/user/dishnetuganda/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane';
require "{$base}/src/autoload.php";
use Dn\Db\Database;
$owner=Database::owner(); $app=Database::app();
$P=$owner->one("SELECT id FROM mt_customers WHERE name='Cust P'")['id'];
$Q=$owner->one("SELECT id FROM mt_customers WHERE name='Cust Q'")['id'];
$dev=$owner->one("SELECT id FROM mt_devices WHERE serial='SER-Q'")['id'];
$cases = [
 'fully valid intent owned by Q' => ["INSERT INTO mt_intents (customer_id,kind,payload) VALUES (?,?,'{}'::jsonb)", [$Q,'steal']],
 'fully valid session owned by Q'=> ["INSERT INTO mt_sessions (customer_id,acct_session_id,radius_username) VALUES (?,?,?)", [$Q,'s-x','u-x']],
 'fully valid device owned by Q' => ["INSERT INTO mt_devices (customer_id,serial,model) VALUES (?,?,?)", [$Q,'SER-STOLEN','hAP']],
 'secret row for Q device'       => ["INSERT INTO mt_device_secrets (device_id,customer_id,username,secret_sealed) VALUES (?,?,?,?)", [$dev,$Q,'mgmt','x']],
 'secret row for Q device, labelled P' => ["INSERT INTO mt_device_secrets (device_id,customer_id,username,secret_sealed) VALUES (?,?,?,?)", [$dev,$P,'mgmt','x']],
 'UPDATE own row to hand it to Q' => ["UPDATE mt_intents SET customer_id = ? WHERE customer_id = ?", [$Q,$P]],
];
foreach ($cases as $label => [$sql,$args]) {
  try {
    $app->exec('BEGIN'); $app->exec("SET LOCAL app.customer_id = '{$P}'");
    $app->exec($sql,$args); $app->exec('ROLLBACK');
    printf("  %-38s ALLOWED  <-- !!\n", $label);
  } catch (\Throwable $e) {
    try{$app->exec('ROLLBACK');}catch(\Throwable){}
    $m = preg_replace('/\s+/',' ',$e->getMessage());
    preg_match('/SQLSTATE\[(\w+)\]/',$m,$mm);
    printf("  %-38s DENIED %s %s\n", $label, $mm[1]??'?', substr(strstr($m,'ERROR:')?:$m,0,58));
  }
}

<?php
$base='/home/user/dishnetuganda/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane';
require "{$base}/src/autoload.php";
use Dn\Db\Database; use Dn\Tenancy\TenantContext;
$ins=Database::inspector(); $owner=Database::owner(); $app=Database::app(); $ctx=new TenantContext($app);
$admin=Database::admin(); $work=Database::worker(); $radius=Database::radius();
$P=$ins->one("SELECT id FROM mt_customers WHERE name='CustP'")['id'];
$Q=$ins->one("SELECT id FROM mt_customers WHERE name='CustQ'")['id'];
function res($l,$v){ printf("  %-50s %s\n",$l,$v); }

echo "== F5: new objects created BY THE OWNER — the production migration path ==\n";
$owner->pdo()->exec("CREATE TABLE mt_f5_probe (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), customer_id uuid)");
$owner->pdo()->exec("CREATE FUNCTION mt_f5_fn() RETURNS int LANGUAGE sql AS 'SELECT 1'");
$owner->pdo()->exec("INSERT INTO mt_f5_probe (customer_id) VALUES ('{$Q}')");
res('table relacl', $ins->one("SELECT COALESCE(relacl::text,'NULL') AS a FROM pg_class WHERE relname='mt_f5_probe'")['a']);
res('function proacl', $ins->one("SELECT COALESCE(proacl::text,'NULL = built-in default = PUBLIC') AS a FROM pg_proc WHERE proname='mt_f5_fn'")['a']);
res('RLS on the new table', $ins->one("SELECT relrowsecurity::text AS r FROM pg_class WHERE relname='mt_f5_probe'")['r']);
foreach (['dnb_app'=>$app,'dnb_worker'=>$work,'dnb_admin'=>$admin,'dnb_radius'=>$radius] as $lbl=>$c) {
  $t='denied'; $w='denied'; $f='denied';
  try { $n=count($c->query("SELECT 1 FROM mt_f5_probe")); $t="SELECT {$n} row(s) — Q's row"; } catch(\Throwable){}
  try { $c->exec("BEGIN"); $c->exec("INSERT INTO mt_f5_probe (customer_id) VALUES (?)",[$P]); $c->exec("ROLLBACK"); $w='INSERT ok'; }
  catch(\Throwable){ try{$c->exec('ROLLBACK');}catch(\Throwable){} }
  try { $c->one("SELECT mt_f5_fn() AS x"); $f='EXECUTE ok'; } catch(\Throwable){}
  res("  as {$lbl}", "{$t} / {$w} / {$f}");
}
res('would the F4 guard catch the table?', 'yes — RLS off is reported (proven in test_rls_isolation)');
res('would the 016 sweep catch the function?', 'only when a migration CALLS mt_revoke_public_execute()');
$owner->pdo()->exec("DROP TABLE mt_f5_probe"); $owner->pdo()->exec("DROP FUNCTION mt_f5_fn()");

echo "\n== F3 follow-up: squatting a device that has NO secret yet ==\n";
$bare=$admin->one("SELECT id FROM mt_device_register('SER-Q-BARE2','hAP',null,null,'10.91.0.9','tech')")['id'];
$admin->one("SELECT id FROM mt_device_assign(?,?,?,?)",[$bare,$Q,null,'Q bare']);
try {
  $ctx->run($P, fn(Database $db)=>$db->exec(
    "INSERT INTO mt_device_secrets (device_id,customer_id,username,secret_sealed) VALUES (?,?,?,?)",
    [$bare,$P,'squat','x']));
  res("P plants the secret row for Q's uncredentialed device",'ALLOWED  <--');
  try { $admin->one("SELECT mt_device_set_secret(?,?,?) AS ok",[$bare,'mgmt','real']);
        res('  can the admin still credential it?','yes'); }
  catch(\Throwable $e){ res('  can the admin still credential it?','NO — '.substr(strstr($e->getMessage(),'ERROR:')?:'',0,44)); }
} catch(\Throwable $e){ res("P plants the secret row",'DENIED '.substr(strstr($e->getMessage(),'ERROR:')?:'',0,40)); }

echo "\n== F8 follow-up: P fabricates telemetry for a device it owns ==\n";
$pdev=$admin->one("SELECT id FROM mt_device_register('SER-P9','hAP',null,null,'10.92.0.9','tech')")['id'];
$admin->one("SELECT id FROM mt_device_assign(?,?,?,?)",[$pdev,$P,null,'P AP']);
try { $ctx->run($P, fn(Database $db)=>$db->exec(
  "INSERT INTO mt_uplink_samples (device_id,customer_id,rx_bps,tx_bps) VALUES (?,?,?,?)",
  [$pdev,$P,987654321,987654321]));
  res('P writes a fabricated sample for its OWN device','ALLOWED  <--');
  res('  visible to P as its own telemetry',
    count($ctx->run($P, fn(Database $db)=>$db->query("SELECT 1 FROM mt_uplink_samples WHERE rx_bps=987654321"))).' row(s)');
} catch(\Throwable $e){ res('P writes a fabricated sample','DENIED '.substr(strstr($e->getMessage(),'ERROR:')?:'',0,40)); }
try { $ctx->run($P, fn(Database $db)=>$db->exec(
  "INSERT INTO mt_uplink_samples (device_id,customer_id,rx_bps,tx_bps) VALUES (?,?,?,?)",
  [$pdev,$Q,1,1]));
  res('P writes a sample LABELLED Q','ALLOWED  <--'); }
catch(\Throwable $e){ res('P writes a sample LABELLED Q','DENIED — RLS WITH CHECK'); }

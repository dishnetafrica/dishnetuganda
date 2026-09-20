<?php
$base='/home/user/dishnetuganda/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane';
require "{$base}/src/autoload.php";
use Dn\Db\Database;
$owner=Database::owner(); $app=Database::app();
$P=$owner->one("SELECT id FROM mt_customers WHERE name='Cust P'")['id'];
$Q=$owner->one("SELECT id FROM mt_customers WHERE name='Cust Q'")['id'];
$quser=$owner->one("SELECT radius_username FROM mt_hotspot_users WHERE customer_id=?",[$Q])['radius_username'];
$before=(int)$owner->one("SELECT count(*) AS n FROM mt_sessions WHERE customer_id=?",[$Q])['n'];
$app->exec('BEGIN'); $app->exec("SET LOCAL app.customer_id = '{$P}'");
$sid=$app->one("SELECT mt_session_account('Start',?,?,?,?,?,?,?,?) AS id",
        [$quser,'INJECTED-1','nas-x',9999999,8888888,'de:ad:be:ef','9.9.9.9',null])['id'];
$app->exec('COMMIT');
$after=(int)$owner->one("SELECT count(*) AS n FROM mt_sessions WHERE customer_id=?",[$Q])['n'];
printf("  injected session id returned to P            %s\n", $sid ? 'YES' : 'null');
printf("  Q's session rows before / after             %d / %d\n", $before, $after);
$row=$owner->one("SELECT customer_id, bytes_in, bytes_out FROM mt_sessions WHERE acct_session_id='INJECTED-1'");
if($row){ printf("  the new row is owned by                     %s\n", $row['customer_id']===$Q?"Q  <-- cross-customer WRITE":"P"); 
          printf("  fabricated byte counters                    in=%s out=%s\n",$row['bytes_in'],$row['bytes_out']); }
// can P read back what it planted?
$app->exec('BEGIN'); $app->exec("SET LOCAL app.customer_id = '{$P}'");
$seen=$app->query("SELECT 1 FROM mt_sessions WHERE acct_session_id='INJECTED-1'");
$app->exec('ROLLBACK');
printf("  P can read back the row it planted          %s\n", count($seen)?'YES':'no (write-only blind injection)');

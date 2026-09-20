<?php
$base='/home/user/dishnetuganda/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane';
require "{$base}/src/autoload.php";
use Dn\Db\Database;
$owner=Database::owner(); $app=Database::app(); $work=Database::worker(); $adm=Database::admin();
$P=$owner->one("SELECT id FROM mt_customers WHERE name='Cust P'")['id'];
$Q=$owner->one("SELECT id FROM mt_customers WHERE name='Cust Q'")['id'];
$qprin=$owner->one("SELECT id FROM mt_principals WHERE customer_id=?",[$Q])['id'];
function hdr($s){ echo "\n== $s ==\n"; }
function res($l,$v){ printf("  %-52s %s\n",$l,$v); }

hdr('A. forged session row: does it yield a usable identity?');
$app->exec('BEGIN'); $app->exec("SET LOCAL app.customer_id = '{$P}'");
$app->exec("INSERT INTO mt_auth_sessions (principal_id,customer_id,token_hash,expires_at)
            VALUES (?,?,'forged-token', now()+interval '1 hour')",[$qprin,$P]);
$r=$app->query("SELECT * FROM mt_auth_resolve_token('forged-token')");
res('resolve_token(forged) returns rows', count($r));
if ($r) { res('  -> principal_id is Q\'s principal', $r[0]['principal_id']===$qprin?'YES  <-- mismatch accepted':'no');
          res('  -> customer_id returned', $r[0]['customer_id']===$P?'P (tenant stays P)':'Q  <-- ESCALATION'); }
$app->exec('ROLLBACK');

hdr('B. SECURITY DEFINER functions the REQUEST role can call, used cross-customer');
$qcode=$owner->one("SELECT code FROM mt_vouchers WHERE customer_id=?",[$Q])['code'];
$app->exec('BEGIN'); $app->exec("SET LOCAL app.customer_id = '{$P}'");
try { $v=$app->query("SELECT * FROM mt_voucher_redeem(?)",[$qcode]);
      res("mt_voucher_redeem(Q's code) as P", $v ? 'REDEEMED  <-- cross-customer' : 'no rows (refused)'); }
catch(\Throwable $e){ res("mt_voucher_redeem(Q's code) as P",'ERR '.substr($e->getMessage(),0,40)); }
$app->exec('ROLLBACK');
$app->exec('BEGIN'); $app->exec("SET LOCAL app.customer_id = '{$P}'");
try { $a=$app->query("SELECT * FROM mt_session_account('Start',?,?,?,?,?,?,?,?)",
        ["u-Q",'sess-forge','nas',100,100,'aa:bb','1.2.3.4',null]);
      res("mt_session_account for Q's username as P", 'ACCEPTED  <-- see note'); }
catch(\Throwable $e){ res("mt_session_account for Q's username as P",'refused: '.substr(strstr($e->getMessage(),'ERROR:')?:'',0,40)); }
$app->exec('ROLLBACK');

hdr('C. context switching and connection reuse');
$app->exec('BEGIN'); $app->exec("SET LOCAL app.customer_id = '{$P}'"); $app->exec('COMMIT');
$leak=$app->one("SELECT current_setting('app.customer_id', true) AS c")['c'];
res('after COMMIT, SET LOCAL context persists?', ($leak===null||$leak==='')?'no (cleared)':"YES leaked={$leak}");
$app->exec("SET app.customer_id = '{$P}'");   // deliberately session-scoped
$leak2=$app->one("SELECT current_setting('app.customer_id', true) AS c")['c'];
res('plain SET (no LOCAL) persists on connection?', $leak2===$P?'YES — a pooled connection would carry it':'no');
$app->exec("RESET app.customer_id");
try { $app->exec('BEGIN'); $app->exec("SET LOCAL app.customer_id = '{$P}'");
      $app->exec("SELECT 1/0"); } catch(\Throwable){}
try { $app->exec('ROLLBACK'); } catch(\Throwable){}
$leak3=$app->one("SELECT current_setting('app.customer_id', true) AS c")['c'];
res('after a FAILED transaction, context left behind?', ($leak3===null||$leak3==='')?'no (cleared)':"YES leaked={$leak3}");
$unscoped=$app->query("SELECT 1 FROM mt_customers");
res('with NO context set, rows visible', count($unscoped).' (fail-closed if 0)');
try { $app->exec('BEGIN'); $app->exec("SET LOCAL app.customer_id = '{$Q}'");
      $n=count($app->query("SELECT 1 FROM mt_customers")); $app->exec('ROLLBACK');
      res('app sets context to Q by itself and reads Q', $n>0?"YES {$n} rows <-- context is self-asserted":'no'); }
catch(\Throwable $e){ try{$app->exec('ROLLBACK');}catch(\Throwable){} res('app sets context to Q','ERR'); }

hdr('D. worker boundary');
$n=0; try { $c=$work->query("SELECT * FROM mt_intent_claim('audit','5 minutes'::interval,50)"); $n=count($c);
  res('worker claims intents across customers', $n.' intents, '.count(array_unique(array_column($c,'customer_id'))).' customers');
} catch(\Throwable $e){ res('worker claim','ERR '.substr($e->getMessage(),0,40)); }
$wleak=$work->one("SELECT current_setting('app.customer_id', true) AS c")['c'];
res('worker connection carries any tenant context?', ($wleak===null||$wleak==='')?'no':"YES {$wleak}");
try { $work->query("SELECT 1 FROM mt_customers"); res('worker unscoped read of mt_customers', count($work->query("SELECT 1 FROM mt_customers")).' rows (0 = fail-closed)'); }
catch(\Throwable $e){ res('worker unscoped read','DENIED'); }

hdr('E. admin boundary — what dnb_admin can actually do');
foreach ([['mt_customers','SELECT 1 FROM mt_customers'],
          ['mt_device_secrets','SELECT 1 FROM mt_device_secrets'],
          ['mt_vouchers','SELECT 1 FROM mt_vouchers']] as [$lbl,$sql]) {
  try { res("admin unscoped read {$lbl}", count($adm->query($sql)).' rows (0 = RLS still applies)'); }
  catch(\Throwable $e){ res("admin unscoped read {$lbl}",'DENIED'); }
}
try { $adm->query("SELECT * FROM mt_intent_claim('admin','1 minute'::interval,1)"); res('admin can call worker claim','YES  <-- boundary blur'); }
catch(\Throwable $e){ res('admin can call worker claim','DENIED (correct)'); }
try { $app->query("SELECT * FROM mt_device_register('X','Y',null,null,null,'t')"); res('app can call admin register','YES  <-- boundary blur'); }
catch(\Throwable $e){ res('app can call admin register','DENIED (correct)'); }

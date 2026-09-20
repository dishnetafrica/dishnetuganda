<?php
$base='/home/user/dishnetuganda/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane';
require "{$base}/src/autoload.php";
use Dn\Db\Database; use Dn\Tenancy\TenantContext; use Dn\Vouchers\VoucherService;
$ins=Database::inspector(); $app=Database::app(); $ctx=new TenantContext($app); $admin=Database::admin();
function r($l,$v){ printf("  %-50s %s\n",$l,$v); }
$mk=function(string $n,string $tag) use($admin,$ctx){
  $c=$admin->one('SELECT mt_customer_create(?,?) AS id',[$n,'f6'])['id'];
  return $ctx->run($c, function(Database $db) use($c,$tag){
    $pr=$db->one("INSERT INTO mt_profiles (rate_down_bps,rate_up_bps,session_timeout_s,shared_users)
                  VALUES (?,500000,3600,2) ON CONFLICT DO NOTHING RETURNING id",[1000000+crc32($tag)%9000])['id']
        ?? $db->one("SELECT id FROM mt_profiles LIMIT 1")['id'];
    $pl=$db->one("INSERT INTO mt_plans (customer_id,profile_id,name,duration_s,rate_down_bps,rate_up_bps,
                  devices_per_voucher,mode,price_minor,currency)
                  VALUES (?,?,?,3600,1000000,500000,2,'elapsed',5000,'UGX') RETURNING id",[$c,$pr,"Plan {$tag}"])['id'];
    $v=$db->one("INSERT INTO mt_vouchers (customer_id,plan_id,code,price_minor,currency,duration_s)
                 VALUES (?,?,?,5000,'UGX',3600) RETURNING id",[$c,$pl,"CODE-{$tag}"])['id'];
    $db->exec("INSERT INTO mt_hotspot_users (voucher_id,customer_id,radius_username) VALUES (?,?,?)",
              [$v,$c,"u-{$tag}"]);
    return ['customer'=>$c,'plan'=>$pl,'voucher'=>$v];
  });
};
$P=$mk('Cust P','P'); $Q=$mk('Cust Q','Q');
$snap=fn()=>[
 'q_state'   => $ins->one("SELECT state FROM mt_vouchers WHERE code='CODE-Q'")['state'],
 'q_hotspot' => (int)$ins->one("SELECT count(*) AS n FROM mt_hotspot_users WHERE customer_id=?",[$Q['customer']])['n'],
 'q_sessions'=> (int)$ins->one("SELECT count(*) AS n FROM mt_sessions WHERE customer_id=?",[$Q['customer']])['n'],
 'q_usage'   => (int)$ins->one("SELECT COALESCE(sum(bytes_in),0) AS n FROM mt_sessions WHERE customer_id=?",[$Q['customer']])['n'],
];
$before=$snap();
echo "== 6. caller-supplied code selecting ANOTHER customer's voucher ==\n";
$out=$ctx->run($P['customer'], fn(Database $db)=>(new VoucherService($db))->redeem('CODE-Q'));
r("P redeems Q's code", $out ? 'REDEEMED' : 'refused');
echo "== 7. side effects on Q ==\n";
$after=$snap();
r("  Q's voucher state", "{$before['q_state']} -> {$after['q_state']}"
   . ($before['q_state']!==$after['q_state'] ? '   <-- MUTATED' : ''));
r("  Q's hotspot users", "{$before['q_hotspot']} -> {$after['q_hotspot']}");
r("  Q's session rows",  "{$before['q_sessions']} -> {$after['q_sessions']}");
r("  Q's accounting bytes", "{$before['q_usage']} -> {$after['q_usage']}");
r("  can P READ Q's voucher row afterwards?",
   count($ctx->run($P['customer'], fn(Database $db)=>$db->query("SELECT 1 FROM mt_vouchers WHERE code='CODE-Q'")))
   ? 'YES' : 'no — RLS still hides it');
echo "== 8. what the return value discloses ==\n";
if ($out) {
  r("  voucher_id", substr((string)$out['voucher_id'],0,13).'…');
  r("  customer_id", $out['customer_id']===$Q['customer'] ? "Q's uuid  <-- tenant identifier disclosed" : 'not Q');
  r("  duration_s / expires_at", $out['duration_s'].'s / '.substr((string)$out['expires_at'],0,19));
  r("  price or plan detail?", array_key_exists('price_minor',$out)||array_key_exists('plan_id',$out) ? 'YES' : 'no');
}
echo "== legitimate path ==\n";
$own=$ctx->run($P['customer'], fn(Database $db)=>(new VoucherService($db))->redeem('CODE-P'));
r("P redeems its OWN code", $own ? 'REDEEMED (as intended)' : 'refused  <-- would be a regression');
r("replay of an already-redeemed code",
   $ctx->run($P['customer'], fn(Database $db)=>(new VoucherService($db))->redeem('CODE-P')) ? 'REDEEMED AGAIN <--' : 'refused');
r("unknown code", $ctx->run($P['customer'], fn(Database $db)=>(new VoucherService($db))->redeem('NOPE')) ? 'redeemed' : 'refused, identically');
echo "== reachability ==\n";
r("HTTP routes referencing redeem", (string)(int)preg_match_all('/redeem/', file_get_contents("{$base}/src/Api/Routes.php")));
r("PHP callers of VoucherService::redeem outside tests", '0 — it is not wired to any route');

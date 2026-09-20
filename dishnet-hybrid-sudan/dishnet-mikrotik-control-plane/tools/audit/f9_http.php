<?php
$base='/home/user/dishnetuganda/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane';
require "{$base}/src/autoload.php";
use Dn\Db\Database; use Dn\Tenancy\TenantContext; use Dn\Auth\Authenticator;
use Dn\Http\{Kernel, Request, Router}; use Dn\Api\Routes;
$ins=Database::inspector(); $app=Database::app(); $ctx=new TenantContext($app);
$P=['customer'=>$ins->one("SELECT id FROM mt_customers WHERE name='CustP'")['id']];
$Q=['customer'=>$ins->one("SELECT id FROM mt_customers WHERE name='CustQ'")['id']];
function res(string $l,string $v){ printf("  %-52s %s\n",$l,$v); }
echo "== 7. through the HTTP layer: is there any caller-controlled route? ==\n";
$auth = new Authenticator($app);
$k = new Kernel(Routes::build($auth), $app, $auth, $ctx);
$call = fn(string $m,string $p,array $b=[],?string $t=null)
  => $k->handle(new Request($m,$p,$t?['Authorization'=>"Bearer {$t}"]:[],$b));
$code = $call('POST','/api/v1/auth/request-code',['phone'=>'+256700000001'])->body['dev_code'];
$tokP = $call('POST','/api/v1/auth/verify',['phone'=>'+256700000001','code'=>$code])->body['token'];
res('P signs in', $tokP ? 'token issued' : 'FAILED');
foreach ([['body','POST','/api/v1/me/plans',['customer_id'=>$Q['customer'],'name'=>'x','duration_s'=>60,
           'rate_down_bps'=>1,'rate_up_bps'=>1,'devices_per_voucher'=>1,'mode'=>'elapsed','price_minor'=>0,'currency'=>'UGX']],
          ['query','GET','/api/v1/me/sites?customer_id='.$Q['customer'],[]],
          ['path','GET','/api/v1/me/sites/'.$Q['customer'],[]]] as [$where,$m,$p,$b]) {
  $r = $call($m,$p,$b,$tokP);
  $json = json_encode($r->body);
  res("{$where}: naming Q in the request", str_contains((string)$json,'SECRET-CustQ') ? 'Q DATA RETURNED <--' : "status {$r->status}, no Q data");
}
$r = $call('GET','/api/v1/me',[],$tokP);
res('header: X-Customer-Id ignored?', $r->status === 200 ? 'route derives from token only' : 'n/a');

echo "== 8. after logout, does the token still establish context? ==\n";
$call('POST','/api/v1/auth/logout',[],$tokP);
$r = $call('GET','/api/v1/me',[],$tokP);
res('revoked token', $r->status === 401 ? '401 — context cannot be established' : "status {$r->status} <--");

<?php
$base='/home/user/dishnetuganda/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane';
require "{$base}/src/autoload.php";
use Dn\Db\Database; use Dn\Tenancy\TenantContext; use Dn\Auth\Authenticator;
use Dn\Http\{Kernel, Request}; use Dn\Api\Routes;
$ins=Database::inspector(); $app=Database::app(); $ctx=new TenantContext($app);
$auth=new Authenticator($app); $k=new Kernel(Routes::build($auth),$app,$auth,$ctx);
$P=$ins->one("SELECT id FROM mt_customers WHERE name='CustP'")['id'];
$qprin=$ins->one("SELECT id FROM mt_principals WHERE customer_id=(SELECT id FROM mt_customers WHERE name='CustQ')")['id'];
function res($l,$v){ printf("  %-54s %s\n",$l,$v); }
$plant = function(string $hash) use ($ctx,$P,$qprin) {
  $ctx->run($P, function(Database $db) use ($hash,$P,$qprin) {
    $db->exec("INSERT INTO mt_auth_sessions (principal_id,customer_id,token_hash,expires_at)
               VALUES (?,?,?, now()+interval '1 hour')", [$qprin,$P,$hash]);
  });
};
$chosen = 'attacker-chosen-token';
$plant(hash('sha256', $chosen));
res('P plants a session row for a token it invents', 'done (hashed without the pepper)');
$r = $k->handle(new Request('GET','/api/v1/me',['Authorization'=>"Bearer {$chosen}"],[]));
res('using that token over HTTP', $r->status===200 ? '200 — SESSION FORGED <--'
    : "{$r->status} — the server hashes with a pepper the attacker does not hold");
$plant(hash_hmac('sha256', $chosen, getenv('DNB_TOKEN_PEPPER') ?: 'dev-pepper-not-for-production'));
$r2 = $k->handle(new Request('GET','/api/v1/me',['Authorization'=>"Bearer {$chosen}"],[]));
res('same attempt, if the attacker DID know the pepper', $r2->status===200 ? '200 — usable session' : (string) $r2->status);
res('  whose tenant does that session get?', $r2->status===200
    ? 'P, its own — the principal is Q\'s but the tenant does not cross' : 'n/a');
res('  what does /me return for a principal it cannot see?',
    $r2->status===200 ? json_encode($r2->body) : 'n/a');
res('is the pepper reachable from the database?', 'no — getenv() only, never stored');

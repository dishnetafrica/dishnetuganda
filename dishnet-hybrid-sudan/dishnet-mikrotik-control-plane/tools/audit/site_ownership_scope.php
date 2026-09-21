<?php
/**
 * Scope audit for the customer/site ownership invariant: which of the four
 * tables carrying both columns can actually REPRESENT a cross-customer pair,
 * and by which role. Read-only with respect to design — it installs nothing
 * and proposes nothing. Disposable database; dropped by the runner.
 */
declare(strict_types=1);
require __DIR__ . '/../../tests/bootstrap.php';
$owner=\Dn\Db\Database::owner(); $app=\Dn\Db\Database::app();
$admin=\Dn\Db\Database::admin(); $ins=\Dn\Db\Database::inspector();
$ids=seed_two_customers($owner); $A=$ids['A']; $B=$ids['B'];
$ctx=new \Dn\Tenancy\TenantContext($app);
$L = fn(string $a,string $b)=>printf("    %-52s %s\n",$a,$b);
$try = function(callable $f): string {
    try { $r=$f(); return is_int($r)?"ALLOWED (rows=$r)":'ALLOWED'; }
    catch (\Throwable $e) { $m=preg_replace('/\s+/',' ',$e->getMessage());
        return 'REFUSED ('.(stripos($m,'row-level security')!==false?'RLS'
              :(stripos($m,'permission denied')!==false?'privilege':substr($m,0,44))).')'; } };

echo "\n########## 1. Does the API derive customer from the session? ##########\n";
$L('mt_vouchers.customer_id source', 'the authenticated principal ($who[customer_id]) — not the body');
$L('mt_vouchers.site_id source',     'request body, validated by a TENANT-FILTERED lookup');
$L('so are customer and site independent at the API?', 'NO — customer is derived, site is filtered');

echo "\n########## 2. Is the tenant-filtered lookup actually the protection? ##########\n";
$seen = $ctx->run($A['customer'], fn(\Dn\Db\Database $db) =>
    $db->one('SELECT id FROM mt_sites WHERE id = ?', [$B['site']]));
$L("as dnb_app (ctx=A): can it SEE B's site?", $seen === null ? "no — lookup returns NULL, route 404s" : 'YES — protection absent');

echo "\n########## 3. Can each table REPRESENT a cross-customer pair? ##########\n";
echo "    (dnb_app, tenant context = A, writing its OWN customer_id but B's site_id)\n\n";
$plan = $ctx->run($A['customer'], fn(\Dn\Db\Database $db) =>
    $db->one('SELECT id, price_minor, currency, duration_s FROM mt_plans WHERE customer_id=? LIMIT 1',[$A['customer']]));
if ($plan === null) {
    $prof = $owner->one('INSERT INTO mt_profiles (rate_down_bps,rate_up_bps,session_timeout_s,shared_users)
                         VALUES (5000000,2000000,3600,2) RETURNING id');
    $plan = $ctx->run($A['customer'], fn(\Dn\Db\Database $db) => $db->one(
      "INSERT INTO mt_plans (customer_id,site_id,profile_id,name,duration_s,rate_down_bps,rate_up_bps,
        devices_per_voucher,mode,price_minor,currency) VALUES (?,?,?,'p',3600,5000000,2000000,2,'elapsed',1000,'UGX')
       RETURNING id, price_minor, currency, duration_s", [$A['customer'], null, $prof['id']]));
}
$L('mt_plans  — site_id = B.site', $ctx->run($A['customer'], fn($db)=>$try(fn()=>$db->exec(
  "INSERT INTO mt_plans (customer_id,site_id,profile_id,name,duration_s,rate_down_bps,rate_up_bps,
     devices_per_voucher,mode,price_minor,currency)
   SELECT ?,?,profile_id,'cross',3600,5000000,2000000,2,'elapsed',1000,'UGX' FROM mt_plans WHERE id=?",
  [$A['customer'], $B['site'], $plan['id']]))));
$batch = $ctx->run($A['customer'], fn($db)=>$try(fn()=>$db->exec(
  'INSERT INTO mt_voucher_batches (customer_id,site_id,plan_id,requested_count) VALUES (?,?,?,1)',
  [$A['customer'], $B['site'], $plan['id']])));
$L('mt_voucher_batches — site_id = B.site', $batch);
$L('mt_vouchers — site_id = B.site', $ctx->run($A['customer'], fn($db)=>$try(fn()=>$db->exec(
  'INSERT INTO mt_vouchers (customer_id,batch_id,plan_id,site_id,code,price_minor,currency,duration_s)
   VALUES (?,NULL,?,?,?,?,?,?)',
  [$A['customer'], $plan['id'], $B['site'], 'CROSS-'.bin2hex(random_bytes(4)),
   (int)$plan['price_minor'], $plan['currency'], (int)$plan['duration_s']]))));

echo "\n########## 4. What the rows now look like ##########\n";
foreach (['mt_plans','mt_voucher_batches','mt_vouchers'] as $t) {
    $n = $ins->one("SELECT count(*) n FROM {$t} x JOIN mt_sites s ON s.id=x.site_id
                     WHERE x.customer_id IS DISTINCT FROM s.customer_id")['n'];
    $L("{$t}: rows whose site belongs to another customer", $n > 0 ? "*** {$n} ***" : '0');
}

echo "\n########## 5. Decision 2b exposure: vouchers with NO site at all ##########\n";
$L('is mt_vouchers.site_id nullable?', $ins->one("SELECT CASE WHEN attnotnull THEN 'NOT NULL' ELSE 'NULLABLE' END c
      FROM pg_attribute WHERE attrelid='mt_vouchers'::regclass AND attname='site_id'")['c']);
$L('does the API require site_id when issuing?', 'no — $req->body[site_id] ?? null');
$ok = $ctx->run($A['customer'], fn($db)=>$try(fn()=>$db->exec(
  'INSERT INTO mt_vouchers (customer_id,batch_id,plan_id,site_id,code,price_minor,currency,duration_s)
   VALUES (?,NULL,?,NULL,?,?,?,?)',
  [$A['customer'],$plan['id'],'NOSITE-'.bin2hex(random_bytes(4)),
   (int)$plan['price_minor'],$plan['currency'],(int)$plan['duration_s']])));
$L('insert a voucher with site_id NULL', $ok);

echo "\n########## 6. customer_id nullability (bears on the fix shape) ##########\n";
foreach (['mt_devices','mt_plans','mt_voucher_batches','mt_vouchers'] as $t) {
    $L("{$t}.customer_id", $ins->one("SELECT CASE WHEN attnotnull THEN 'NOT NULL' ELSE 'NULLABLE' END c
        FROM pg_attribute WHERE attrelid=?::regclass AND attname='customer_id'",[$t])['c']);
}
echo "\n";

<?php
/** Hostile isolation probe: every customer-scoped table, by execution. */
$base = '/home/user/dishnetuganda/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane';
require "{$base}/src/autoload.php";
use Dn\Db\Database;

$owner = Database::owner();          // superuser here: used only to plant fixtures
$app   = Database::app();

/** Build a complete object graph for one customer; returns id map. */
function graph(Database $o, string $tag): array {
    $c = $o->one('INSERT INTO mt_customers (name) VALUES (?) RETURNING id', ["Cust {$tag}"])['id'];
    $svc = $o->one('INSERT INTO mt_services (customer_id, kind) VALUES (?,?) RETURNING id', [$c,'mikrotik_hotspot'])['id'];
    $site= $o->one('INSERT INTO mt_sites (customer_id, service_id, name) VALUES (?,?,?) RETURNING id', [$c,$svc,"Site {$tag}"])['id'];
    $prof= $o->one('INSERT INTO mt_profiles (rate_down_bps,rate_up_bps,session_timeout_s,shared_users)
                    VALUES (?,?,?,?) ON CONFLICT DO NOTHING RETURNING id',
                   [1000000 + crc32($tag) % 1000, 500000, 3600, 2])['id']
           ?? $o->one('SELECT id FROM mt_profiles LIMIT 1')['id'];
    $plan= $o->one('INSERT INTO mt_plans (customer_id,profile_id,name,duration_s,rate_down_bps,rate_up_bps,
                    devices_per_voucher,mode,price_minor,currency) VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id',
                   [$c,$prof,"Plan {$tag}",3600,1000000,500000,2,'elapsed',1000,'UGX'])['id'];
    $batch=$o->one('INSERT INTO mt_voucher_batches (customer_id,plan_id,requested_count) VALUES (?,?,?) RETURNING id',[$c,$plan,1])['id'];
    $vch = $o->one('INSERT INTO mt_vouchers (customer_id,plan_id,batch_id,code,price_minor,currency,duration_s)
                    VALUES (?,?,?,?,?,?,?) RETURNING id',[$c,$plan,$batch,"CODE-{$tag}",1000,'UGX',3600])['id'];
    $o->exec('INSERT INTO mt_hotspot_users (voucher_id,customer_id,radius_username) VALUES (?,?,?)',
             [$vch,$c,"u-{$tag}"]);
    $dev = $o->one('INSERT INTO mt_devices (customer_id,serial,model,tunnel_ip) VALUES (?,?,?,?) RETURNING id',
                   [$c,"SER-{$tag}",'hAP','10.99.0.'.(ord($tag[0])%250)])['id'];
    $o->exec('INSERT INTO mt_device_secrets (device_id,customer_id,username,secret_sealed) VALUES (?,?,?,?)',
             [$dev,$c,'mgmt',"sealed-{$tag}"]);
    $o->exec('INSERT INTO mt_device_config (device_id,customer_id,desired) VALUES (?,?,?::jsonb)',[$dev,$c,'{"k":"'.$tag.'"}']);
    $o->exec('INSERT INTO mt_uplink_samples (device_id,customer_id,rx_bps,tx_bps) VALUES (?,?,?,?)',[$dev,$c,111,222]);
    $o->exec('INSERT INTO mt_sessions (customer_id,acct_session_id,radius_username) VALUES (?,?,?)',[$c,"sess-{$tag}","u-{$tag}"]);
    $o->exec('INSERT INTO mt_intents (customer_id,kind,payload) VALUES (?,?,?::jsonb)',[$c,'secret.work','{"secret":"'.$tag.'"}']);
    $pr  = $o->one('INSERT INTO mt_principals (customer_id,kind,display_name) VALUES (?,?,?) RETURNING id',[$c,'owner',"P {$tag}"])['id'];
    $o->exec('INSERT INTO mt_auth_sessions (principal_id,customer_id,token_hash,expires_at)
              VALUES (?,?,?, now() + interval \'1 hour\')',[$pr,$c,"tok-{$tag}"]);
    $o->exec('INSERT INTO mt_entitlements (service_id,customer_id,key) VALUES (?,?,?)',[$svc,$c,'max_routers']);
    $o->exec('INSERT INTO mt_idempotency (key,customer_id,endpoint,request_digest) VALUES (?,?,?,?)',
             ["idem-{$tag}",$c,'/x',"digest-{$tag}"]);
    $o->exec('INSERT INTO mt_audit_log (customer_id,actor,actor_kind,action) VALUES (?,?,?,?)',[$c,'a','system','probe']);
    $o->exec('INSERT INTO mt_auth_codes (phone,code_hash,customer_id,principal_id,expires_at)
              VALUES (?,?,?,?, now() + interval \'10 minutes\')',["+2567000{$tag}","h",$c,$pr]);
    return ['customer'=>$c,'device'=>$dev,'plan'=>$plan,'voucher'=>$vch,'principal'=>$pr,'service'=>$svc,'site'=>$site];
}

$P = graph($owner, 'P'); $Q = graph($owner, 'Q');

$tables = ['mt_audit_log','mt_auth_codes','mt_auth_sessions','mt_customers','mt_device_config',
           'mt_device_secrets','mt_devices','mt_entitlements','mt_hotspot_users','mt_idempotency',
           'mt_intents','mt_plans','mt_principals','mt_services','mt_sessions','mt_sites',
           'mt_uplink_samples','mt_voucher_batches','mt_vouchers','mt_profiles','mt_migrations'];

/** Run $sql as dnb_app inside P's tenant context; classify the outcome. */
function asP(Database $app, string $cust, string $sql, array $args = []): string {
    try {
        $app->exec('BEGIN');
        $app->exec("SET LOCAL app.customer_id = '{$cust}'");
        $r = $app->query($sql, $args);
        $app->exec('ROLLBACK');
        return 'rows=' . count($r);
    } catch (\Throwable $e) {
        try { $app->exec('ROLLBACK'); } catch (\Throwable) {}
        $m = $e->getMessage();
        if (str_contains($m, '42501') || stripos($m, 'permission denied') !== false) return 'DENIED-priv';
        if (stripos($m, 'row-level security') !== false) return 'DENIED-rls';
        return 'ERR:' . substr(preg_replace('/\s+/', ' ', $m), 0, 40);
    }
}

printf("%-20s %-12s %-12s %-12s %-12s %-14s\n",
       'TABLE','P sees Q','P upd Q','P del Q','P ins as Q','P sees own');
foreach ($tables as $t) {
    $scoped = in_array($t, ['mt_profiles','mt_migrations'], true) ? false : true;
    $col    = $t === 'mt_customers' ? 'id' : 'customer_id';

    if ($scoped) {
        $see = asP($app, $P['customer'], "SELECT 1 FROM {$t} WHERE {$col} = ?", [$Q['customer']]);
        $upd = asP($app, $P['customer'], "UPDATE {$t} SET {$col} = {$col} WHERE {$col} = ? RETURNING 1", [$Q['customer']]);
        $del = asP($app, $P['customer'], "DELETE FROM {$t} WHERE {$col} = ? RETURNING 1", [$Q['customer']]);
        $ins = $t === 'mt_customers'
             ? asP($app, $P['customer'], "INSERT INTO mt_customers (id,name) VALUES (?, 'forged') RETURNING 1", [$Q['customer']])
             : asP($app, $P['customer'], "INSERT INTO {$t} ({$col}) VALUES (?) RETURNING 1", [$Q['customer']]);
        $own = asP($app, $P['customer'], "SELECT 1 FROM {$t} WHERE {$col} = ?", [$P['customer']]);
    } else {
        $see = asP($app, $P['customer'], "SELECT 1 FROM {$t}");
        $upd = $del = $ins = $own = 'n/a-unscoped';
    }
    printf("%-20s %-12s %-12s %-12s %-12s %-14s\n", $t, $see, $upd, $del, $ins, $own);
}

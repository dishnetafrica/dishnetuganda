<?php
declare(strict_types=1);
/**
 * Audit finding F1 (docs/58 §4) — cross-customer accounting injection.
 *
 * Before migration 018, customer P could call mt_session_account with customer
 * Q's RADIUS username and fabricate a session in Q's records, with byte
 * counters of P's choosing, which Q would then be shown as their own usage.
 * P could not read the row back, so the write was blind — harder to notice,
 * not less serious. GREATEST on the counters means an inflated total can never
 * be corrected downward by the ingest path.
 */
require __DIR__ . '/bootstrap.php';
use Dn\Db\Database;
use Dn\Sessions\AccountingIngest;
use Dn\Tenancy\TenantContext;

putenv('DNB_SECRET_KEY=test-key-for-suite-only');

$inspect = Database::inspector();
$app     = Database::app();    $ctx = new TenantContext($app);
$work    = Database::worker();
$admin   = Database::admin();
$radius  = Database::radius();

$ids = seed_two_customers($inspect);
$P = $ids['A']; $Q = $ids['B'];

/** Give a customer a plan, a voucher and a hotspot user. */
// Manufactured on the fixture identity. Since migration 025 dnb_app may not
// write these tables at all -- and it never should have here: what this suite
// tests is the ACCOUNTING boundary, so the rows only need to exist.
$hotspot = function (array $who, string $tag) use ($inspect): string {
    return (function (Database $db) use ($who, $tag) {
        $prof = $db->one('INSERT INTO mt_profiles (rate_down_bps,rate_up_bps,session_timeout_s,shared_users)
                          VALUES (?,?,?,?) ON CONFLICT DO NOTHING RETURNING id',
                         [1000000 + crc32($tag) % 9000, 500000, 3600, 2])['id']
                ?? $db->one('SELECT id FROM mt_profiles LIMIT 1')['id'];
        $plan = $db->one('INSERT INTO mt_plans (customer_id,profile_id,name,duration_s,rate_down_bps,
                          rate_up_bps,devices_per_voucher,mode,price_minor,currency)
                          VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id',
                         [$who['customer'], $prof, "Plan {$tag}", 3600, 1000000, 500000, 2,
                          'elapsed', 1000, 'UGX'])['id'];
        $v = $db->one('INSERT INTO mt_vouchers (customer_id,plan_id,code,price_minor,currency,duration_s)
                       VALUES (?,?,?,?,?,?) RETURNING id',
                      [$who['customer'], $plan, "CODE-{$tag}", 1000, 'UGX', 3600])['id'];
        $u = "acct-{$tag}";
        $db->exec('INSERT INTO mt_hotspot_users (voucher_id,customer_id,radius_username) VALUES (?,?,?)',
                  [$v, $who['customer'], $u]);
        return $u;
    })($inspect);
};
$uP = $hotspot($P, 'P');
$uQ = $hotspot($Q, 'Q');

$pkt = fn(array $o) => array_merge(
    ['Acct-Status-Type' => 'Start', 'User-Name' => $uP, 'Acct-Session-Id' => 's-1',
     'NAS-Identifier' => 'nas-1'], $o);
$sessionsOf = fn(string $cust) => (int) $inspect->one(
    'SELECT count(*) AS n FROM mt_sessions WHERE customer_id = ?', [$cust])['n'];

// ===========================================================================
t('F1 ATTACK — the request role cannot reach the ingestion primitive at all');
throws_(fn() => $ctx->run($P['customer'], fn(Database $db) => $db->one(
        'SELECT mt_session_account(?,?,?,?,?,?,?,?,?) AS id',
        ['Start', $uQ, 's-attack', 'nas', 9999999, 8888888, null, null, null])),
    'permission denied', 'P cannot call mt_session_account, knowing Q\'s username or not');

throws_(fn() => $ctx->runUnscoped(fn(Database $db) => (new AccountingIngest($db))
        ->record(['Acct-Status-Type' => 'Start', 'User-Name' => $uQ, 'Acct-Session-Id' => 'x'])),
    'permission denied', 'nor through AccountingIngest on a request connection');

t('F1 ATTACK — and nothing landed in Q\'s records');
$before = $sessionsOf($Q['customer']);
foreach ([$uQ, $uP] as $target) {
    try { $ctx->run($P['customer'], fn(Database $db) => $db->one(
        'SELECT mt_session_account(?,?,?,?,?,?,?,?,?) AS id',
        ['Start', $target, 's-a2', 'nas', 999999999, 999999999, null, null, null]));
    } catch (\Throwable) {}
}
is_($sessionsOf($Q['customer']), $before, 'Q has exactly the sessions it had before');
is_((int) $inspect->one('SELECT count(*) AS n FROM mt_sessions
                          WHERE bytes_in > 900000000')['n'], 0,
    'and no inflated counter exists anywhere');

t('F1 — the worker cannot ingest either: this is not "some other trusted role"');
// A dedicated identity rather than reusing dnb_worker. Outbound delivery to a
// router and inbound accounting from a NAS are different exposures; the worker
// holds device credentials, and an accounting endpoint has no business with
// those if it is ever compromised.
throws_(fn() => $work->one('SELECT mt_session_account(?,?,?,?,?,?,?,?,?) AS id',
        ['Start', $uP, 's-w', 'nas', 1, 1, null, null, null]),
    'permission denied', 'dnb_worker holds no EXECUTE on the accounting primitive');
throws_(fn() => $admin->one('SELECT mt_session_account(?,?,?,?,?,?,?,?,?) AS id',
        ['Start', $uP, 's-ad', 'nas', 1, 1, null, null, null]),
    'permission denied', 'nor dnb_admin');

t('F1 — the ingestion identity holds EXECUTE and NOTHING else');
foreach (['mt_sessions','mt_hotspot_users','mt_vouchers','mt_customers','mt_devices',
          'mt_device_secrets'] as $tbl) {
    is_($inspect->one('SELECT has_table_privilege(?,?,?) AS p', ['dnb_radius', $tbl, 'SELECT'])['p'],
        false, "dnb_radius cannot SELECT {$tbl}");
    is_($inspect->one('SELECT has_table_privilege(?,?,?) AS p', ['dnb_radius', $tbl, 'INSERT'])['p'],
        false, "dnb_radius cannot INSERT into {$tbl}");
}
foreach (['mt_intent_claim(text,interval,integer)', 'mt_voucher_redeem(text)',
          'mt_device_set_secret(uuid,text,text,text)', 'mt_customer_create(text,text)'] as $fn) {
    is_($inspect->one('SELECT has_function_privilege(?,?,?) AS p', ['dnb_radius', $fn, 'EXECUTE'])['p'],
        false, 'dnb_radius cannot execute ' . explode('(', $fn)[0]);
}
$r = $inspect->one("SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname='dnb_radius'");
is_([$r['rolsuper'], $r['rolbypassrls']], [false, false], 'and is neither superuser nor BYPASSRLS');

// ===========================================================================
t('F1 LEGITIMATE — ingestion still works, for both customers');
$ingest = fn(array $packet) => (new AccountingIngest($radius))->record($packet);
$a = $ingest($pkt(['User-Name' => $uP, 'Acct-Session-Id' => 'p-1',
                   'Acct-Input-Octets' => 1000, 'Acct-Output-Octets' => 2000]));
$b = $ingest($pkt(['User-Name' => $uQ, 'Acct-Session-Id' => 'q-1',
                   'Acct-Input-Octets' => 3000, 'Acct-Output-Octets' => 4000]));
is_($a['session_id'] !== null, true, 'P\'s accounting is accepted');
is_($b['session_id'] !== null, true, 'and Q\'s');
is_($inspect->one("SELECT customer_id FROM mt_sessions WHERE acct_session_id='p-1'")['customer_id'],
    $P['customer'], 'P\'s session is attributed to P');
is_($inspect->one("SELECT customer_id FROM mt_sessions WHERE acct_session_id='q-1'")['customer_id'],
    $Q['customer'], 'and Q\'s to Q — ownership still comes from the username, not the caller');

t('F1 LEGITIMATE — an unknown username still produces nothing, not an error');
$u = $ingest($pkt(['User-Name' => 'nobody-at-all', 'Acct-Session-Id' => 'u-1']));
is_($u['session_id'], null, 'no session');
is_($u['status'], 'unknown_user', 'reported as an unknown user rather than refused');

t('F1 LEGITIMATE — retransmits stay idempotent');
$again = $ingest($pkt(['User-Name' => $uP, 'Acct-Session-Id' => 'p-1',
                       'Acct-Input-Octets' => 1000, 'Acct-Output-Octets' => 2000]));
is_($again['session_id'], $a['session_id'], 'the same Start yields the same session');
is_((int) $inspect->one("SELECT count(*) AS n FROM mt_sessions WHERE acct_session_id='p-1'")['n'],
    1, 'and exactly one row exists');

t('F1 LEGITIMATE — reordering cannot shrink a counter');
$ingest($pkt(['User-Name' => $uP, 'Acct-Session-Id' => 'p-1', 'Acct-Status-Type' => 'Interim-Update',
              'Acct-Input-Octets' => 50000, 'Acct-Output-Octets' => 60000]));
$ingest($pkt(['User-Name' => $uP, 'Acct-Session-Id' => 'p-1', 'Acct-Status-Type' => 'Interim-Update',
              'Acct-Input-Octets' => 1000,  'Acct-Output-Octets' => 2000]));   // late, smaller
$row = $inspect->one("SELECT bytes_in, bytes_out FROM mt_sessions WHERE acct_session_id='p-1'");
is_([(int) $row['bytes_in'], (int) $row['bytes_out']], [50000, 60000],
    'the earlier retransmit does not under-report the session');

t('F1 LEGITIMATE — Gigawords still combine');
$ingest($pkt(['User-Name' => $uP, 'Acct-Session-Id' => 'p-2', 'Acct-Status-Type' => 'Start',
              'Acct-Input-Octets' => 1, 'Acct-Input-Gigawords' => 2,
              'Acct-Output-Octets' => 0, 'Acct-Output-Gigawords' => 0]));
is_((int) $inspect->one("SELECT bytes_in FROM mt_sessions WHERE acct_session_id='p-2'")['bytes_in'],
    2 * AccountingIngest::GIGAWORD + 1, 'the 32-bit wrap is still carried');

t('F1 LEGITIMATE — a Stop still closes, and a late Interim cannot reopen');
$ingest($pkt(['User-Name' => $uP, 'Acct-Session-Id' => 'p-1', 'Acct-Status-Type' => 'Stop',
              'Acct-Input-Octets' => 70000, 'Acct-Output-Octets' => 80000,
              'Acct-Terminate-Cause' => 'User-Request']));
is_($inspect->one("SELECT state FROM mt_sessions WHERE acct_session_id='p-1'")['state'], 'closed',
    'the session is closed');
$ingest($pkt(['User-Name' => $uP, 'Acct-Session-Id' => 'p-1', 'Acct-Status-Type' => 'Interim-Update',
              'Acct-Input-Octets' => 999999, 'Acct-Output-Octets' => 999999]));
$after = $inspect->one("SELECT state, bytes_in FROM mt_sessions WHERE acct_session_id='p-1'");
is_($after['state'], 'closed', 'and stays closed');
is_((int) $after['bytes_in'], 70000, 'with its numbers unmoved by the late packet');

exit(t_summary());

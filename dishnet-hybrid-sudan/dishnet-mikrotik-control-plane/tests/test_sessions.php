<?php
declare(strict_types=1);
/**
 * Sessions and accounting ingestion.
 *
 * docs/55 step 6 exit condition:
 *   radacct Start / Interim / Stop REACHES mt_sessions.
 *
 * RADIUS accounting is UDP, so the interesting cases are not the happy path:
 * retransmits, reordering, a lost Start, a lost Stop, and a 32-bit counter
 * that wraps. Each gets its own section.
 */
require __DIR__ . '/bootstrap.php';
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Db\Database;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Http\Serializer\Projection;
use Dn\Sessions\AccountingIngest;
use Dn\Tenancy\TenantContext;
use Dn\Vouchers\VoucherService;

$owner = Database::inspector();
$ids = seed_two_customers($owner);
$A = $ids['A']; $B = $ids['B'];
$db   = Database::app();
$auth = new Authenticator($db);
$ctx  = new TenantContext($db);
$k    = new Kernel(Routes::build($auth), $db, $auth, $ctx);
$call = fn(string $m, string $p, array $body = [], string $tok = '', array $h = [])
    => $k->handle(new Request($m, $p,
        ($tok ? ['Authorization' => "Bearer {$tok}"] : []) + $h, $body));

putenv('DNB_EXPOSE_OTP=1');
putenv('DNB_INTERNAL_TOKEN=test-internal-secret');
$signIn = function (string $phone) use ($call, $owner): string {
    $owner->exec('DELETE FROM mt_auth_codes WHERE phone = ?', [$phone]);
    $c = $call('POST', '/api/v1/auth/request-code', ['phone' => $phone])->body['dev_code'];
    return $call('POST', '/api/v1/auth/verify', ['phone' => $phone, 'code' => $c])->body['token'];
};
$tokA = $signIn('+256700001001');
$tokB = $signIn('+256700001002');

$mkPlan = fn(string $tok, string $name) => $call('POST', '/api/v1/me/plans', [
    'name' => $name, 'duration_s' => 86400, 'rate_down_bps' => 10_000_000,
    'rate_up_bps' => 3_000_000, 'devices_per_voucher' => 2, 'mode' => 'elapsed',
    'price_minor' => 800000, 'currency' => 'UGX'], $tok)->body['plan'];
$planA = $mkPlan($tokA, 'Day Pass');
$planB = $mkPlan($tokB, 'Day Pass');

$vA = $call('POST', '/api/v1/me/vouchers', ['plan_id' => $planA['id'], 'count' => 3], $tokA)->body['vouchers'];
$vB = $call('POST', '/api/v1/me/vouchers', ['plan_id' => $planB['id'], 'count' => 1], $tokB)->body['vouchers'];

$userA = $owner->one('SELECT radius_username FROM mt_hotspot_users WHERE voucher_id = ?', [$vA[0]['id']]);
$userA2 = $owner->one('SELECT radius_username FROM mt_hotspot_users WHERE voucher_id = ?', [$vA[1]['id']]);
$userB = $owner->one('SELECT radius_username FROM mt_hotspot_users WHERE voucher_id = ?', [$vB[0]['id']]);

$acct = fn(array $p) => $call('POST', '/internal/radius/accounting', $p, '',
    ['X-Internal-Token' => 'test-internal-secret']);
$pkt = fn(array $o = []) => array_merge([
    'Acct-Status-Type' => 'Start', 'User-Name' => $userA['radius_username'],
    'Acct-Session-Id' => 'sess-0001', 'NAS-Identifier' => 'DN-HGX8842011',
    'Acct-Input-Octets' => 0, 'Acct-Output-Octets' => 0,
    'Calling-Station-Id' => 'AA:BB:CC:DD:EE:01', 'Framed-IP-Address' => '10.5.50.22',
], $o);

// ===========================================================================
t('EXIT CONDITION — Start, Interim, Stop reach mt_sessions');
is_($acct($pkt())->status, 204, 'Start accepted');
$s = $owner->one("SELECT * FROM mt_sessions WHERE acct_session_id = 'sess-0001'");
is_($s !== null, true, 'a session row exists');
is_($s['state'], 'open', 'it is open');
is_($s['customer_id'], $A['customer'], 'attributed to the right customer');
is_($s['voucher_id'], $vA[0]['id'], 'and linked to the voucher that let it on');
is_($s['mac'], 'AA:BB:CC:DD:EE:01', 'the device address is recorded');

$acct($pkt(['Acct-Status-Type' => 'Interim-Update',
            'Acct-Input-Octets' => 500_000, 'Acct-Output-Octets' => 40_000]));
$s = $owner->one("SELECT * FROM mt_sessions WHERE acct_session_id = 'sess-0001'");
is_((int) $s['bytes_in'], 500_000, 'Interim grows the inbound counter');
is_((int) $s['bytes_out'], 40_000, 'and the outbound one');
is_($s['state'], 'open', 'still open');

$acct($pkt(['Acct-Status-Type' => 'Stop', 'Acct-Input-Octets' => 900_000,
            'Acct-Output-Octets' => 70_000, 'Acct-Terminate-Cause' => 'Session-Timeout']));
$s = $owner->one("SELECT * FROM mt_sessions WHERE acct_session_id = 'sess-0001'");
is_($s['state'], 'closed', 'Stop closes it');
is_((int) $s['bytes_in'], 900_000, 'with final octets');
is_($s['terminate_cause'], 'Session-Timeout', 'and the cause it reported');
is_($s['ended_at'] !== null, true, 'and an end time');

is_((int) $owner->one("SELECT count(*) AS n FROM mt_sessions WHERE acct_session_id='sess-0001'")['n'],
    1, 'three packets produced exactly one session row');

// ===========================================================================
t('GIGAWORDS — the 32-bit counter wrap is combined, not dropped');
// Ignoring Acct-Input-Gigawords under-reports every session past 4 GiB, and
// does it quietly: the figures look like light usage rather than a fault.
$acct($pkt(['Acct-Session-Id' => 'sess-gw', 'Acct-Status-Type' => 'Start']));
$acct($pkt(['Acct-Session-Id' => 'sess-gw', 'Acct-Status-Type' => 'Interim-Update',
            'Acct-Input-Octets' => 1_000_000, 'Acct-Input-Gigawords' => 3,
            'Acct-Output-Octets' => 500, 'Acct-Output-Gigawords' => 1]));
$s = $owner->one("SELECT * FROM mt_sessions WHERE acct_session_id = 'sess-gw'");
$expectIn  = 3 * AccountingIngest::GIGAWORD + 1_000_000;
$expectOut = 1 * AccountingIngest::GIGAWORD + 500;
is_((int) $s['bytes_in'], $expectIn, '3 gigawords + octets = ' . number_format($expectIn));
is_((int) $s['bytes_out'], $expectOut, 'and the same on the way out');
is_((int) $s['bytes_in'] > 4_000_000_000, true, 'a >4 GiB session is reported as >4 GiB');

// ===========================================================================
t('RETRANSMIT — the same packet twice changes nothing');
$before = $owner->one("SELECT * FROM mt_sessions WHERE acct_session_id = 'sess-gw'");
$acct($pkt(['Acct-Session-Id' => 'sess-gw', 'Acct-Status-Type' => 'Interim-Update',
            'Acct-Input-Octets' => 1_000_000, 'Acct-Input-Gigawords' => 3,
            'Acct-Output-Octets' => 500, 'Acct-Output-Gigawords' => 1]));
$after = $owner->one("SELECT * FROM mt_sessions WHERE acct_session_id = 'sess-gw'");
is_((int) $after['bytes_in'], (int) $before['bytes_in'], 'octets unchanged');
is_((int) $owner->one("SELECT count(*) AS n FROM mt_sessions WHERE acct_session_id='sess-gw'")['n'],
    1, 'and no duplicate row');

t('REORDER — a stale Interim cannot shrink the counters');
// A retransmit of an EARLIER Interim can arrive after a later one. Assigning
// the value would move the session backwards; GREATEST refuses to.
$acct($pkt(['Acct-Session-Id' => 'sess-gw', 'Acct-Status-Type' => 'Interim-Update',
            'Acct-Input-Octets' => 12, 'Acct-Output-Octets' => 3]));
$s = $owner->one("SELECT * FROM mt_sessions WHERE acct_session_id = 'sess-gw'");
is_((int) $s['bytes_in'], $expectIn, 'a much smaller reading does not regress the total');

t('REORDER — an Interim after the Stop cannot reopen a closed session');
$acct($pkt(['Acct-Session-Id' => 'sess-gw', 'Acct-Status-Type' => 'Stop',
            'Acct-Input-Octets' => 2_000_000, 'Acct-Input-Gigawords' => 3,
            'Acct-Terminate-Cause' => 'User-Request']));
$closed = $owner->one("SELECT * FROM mt_sessions WHERE acct_session_id = 'sess-gw'");
is_($closed['state'], 'closed', 'closed by the Stop');

$acct($pkt(['Acct-Session-Id' => 'sess-gw', 'Acct-Status-Type' => 'Interim-Update',
            'Acct-Input-Octets' => 9_999_999, 'Acct-Input-Gigawords' => 9]));
$still = $owner->one("SELECT * FROM mt_sessions WHERE acct_session_id = 'sess-gw'");
is_($still['state'], 'closed', 'a late Interim leaves it closed');
is_((int) $still['bytes_in'], (int) $closed['bytes_in'], 'and does not move its numbers');

t('a lost Start still yields a session — the Stop lands on its own');
$acct($pkt(['Acct-Session-Id' => 'sess-orphan', 'Acct-Status-Type' => 'Stop',
            'Acct-Input-Octets' => 4242, 'Acct-Terminate-Cause' => 'Lost-Carrier']));
$o = $owner->one("SELECT * FROM mt_sessions WHERE acct_session_id = 'sess-orphan'");
is_($o !== null, true, 'a session exists despite never seeing Start');
is_($o['state'], 'closed', 'and it is closed');
is_((int) $o['bytes_in'], 4242, 'with the octets it reported');

// ===========================================================================
t('REAPING — a lost Stop does not leave a session open forever');
// An Accounting-Stop lost with the tunnel is docs/49 §2.2. Left alone,
// "devices online" only ever climbs.
$acct($pkt(['Acct-Session-Id' => 'sess-stale', 'Acct-Status-Type' => 'Start']));
$owner->exec("UPDATE mt_sessions SET last_seen_at = now() - interval '2 hours'
               WHERE acct_session_id = 'sess-stale'");
// The sweep crosses customers by definition, so it is worker work and runs as
// the worker role. Ingest of a single Accounting packet above stays on the app
// role, which is the split migration 015 introduced.
$n = (new AccountingIngest(Database::worker()))->reap('15 minutes');
is_($n, 1, 'the sweep closes one session');
$r = $owner->one("SELECT * FROM mt_sessions WHERE acct_session_id = 'sess-stale'");
is_($r['state'], 'reaped', "its state is 'reaped', not 'closed'");
is_($r['ended_at'], $r['last_seen_at'], 'and it ended when it was last heard from, not now');

t('reaped is distinguishable from closed, on purpose');
// A session nobody reported the end of is weaker evidence than one that
// reported its own Stop. Reconciliation should be able to tell them apart.
$states = array_column($owner->query('SELECT DISTINCT state FROM mt_sessions'), 'state');
sort($states);
is_(in_array('reaped', $states, true) && in_array('closed', $states, true), true,
    'both states exist in the data');

// ===========================================================================
t('an unknown username produces no session at all');
$before = (int) $owner->one('SELECT count(*) AS n FROM mt_sessions')['n'];
is_($acct($pkt(['User-Name' => 'nobody-at-all', 'Acct-Session-Id' => 'sess-ghost']))->status,
    204, 'the NAS still gets 204');
is_((int) $owner->one('SELECT count(*) AS n FROM mt_sessions')['n'], $before,
    'but no session belonging to nobody is created');

t('the endpoint does not tell a caller whether a username exists');
$known   = $acct($pkt(['Acct-Session-Id' => 'sess-probe-1']));
$unknown = $acct($pkt(['User-Name' => 'made-up', 'Acct-Session-Id' => 'sess-probe-2']));
is_($known->status, $unknown->status, 'same status for a real and a fake username');
is_($known->body, $unknown->body, 'and the same body');

t('the internal endpoint requires its secret');
is_($call('POST', '/internal/radius/accounting', $pkt())->status, 401, 'no token is refused');
is_($call('POST', '/internal/radius/accounting', $pkt(), '',
    ['X-Internal-Token' => 'wrong'])->status, 401, 'a wrong token is refused');
is_($call('POST', '/internal/radius/accounting', $pkt(), $tokA)->status, 401,
    'a CUSTOMER token does not open the internal endpoint');

// ===========================================================================
t('ISOLATION — sessions do not cross customers');
$acct($pkt(['User-Name' => $userB['radius_username'], 'Acct-Session-Id' => 'sess-b-1',
            'NAS-Identifier' => 'DN-OTHER']));
$listA = $call('GET', '/api/v1/me/sessions', [], $tokA)->body['sessions'];
$listB = $call('GET', '/api/v1/me/sessions', [], $tokB)->body['sessions'];
is_(count($listB), 1, 'B sees exactly one live session');
$bId = $owner->one("SELECT id FROM mt_sessions WHERE acct_session_id = 'sess-b-1'")['id'];
is_(in_array($bId, array_column($listA, 'id'), true), false, "B's session is absent from A's list");
is_($call('POST', "/api/v1/me/sessions/{$bId}/disconnect", [], $tokA)->status, 404,
    "A cannot disconnect B's session");

t('two NASes may use the same Acct-Session-Id without colliding');
// A router picks its own session ids; two routers can pick the same one.
$acct($pkt(['Acct-Session-Id' => 'shared-id', 'NAS-Identifier' => 'DN-ONE']));
$acct($pkt(['User-Name' => $userA2['radius_username'],
            'Acct-Session-Id' => 'shared-id', 'NAS-Identifier' => 'DN-TWO']));
is_((int) $owner->one("SELECT count(*) AS n FROM mt_sessions WHERE acct_session_id='shared-id'")['n'],
    2, 'two distinct sessions, keyed by NAS as well as session id');

// ===========================================================================
t('DISCONNECT is an intent, never a direct command');
$mine = $owner->one("SELECT id FROM mt_sessions WHERE customer_id = ? AND state='open' LIMIT 1",
                    [$A['customer']]);
$r = $call('POST', "/api/v1/me/sessions/{$mine['id']}/disconnect", [], $tokA);
is_($r->status, 202, 'accepted, not 200 — nothing happened synchronously');
is_(isset($r->body['intent_id']), true, 'an intent id comes back');
$i = $owner->one('SELECT kind, state FROM mt_intents WHERE id = ?', [$r->body['intent_id']]);
is_($i['kind'], 'session.disconnect', 'the intent names the action');
is_($i['state'], 'queued', 'and it is queued');
is_($owner->one('SELECT state FROM mt_sessions WHERE id = ?', [$mine['id']])['state'], 'open',
    'the session is still open — the request did not pretend to end it');

t('PROJECTION — AAA plumbing does not reach the customer');
$one = $listA[0];
$extra = array_diff(array_keys($one), Projection::fieldsFor('session'));
is_(array_values($extra), [], 'no field outside the session allowlist');
$json = json_encode($listA);
foreach (['radius_username', 'acct_session_id', 'nas_identifier', 'DN-HGX'] as $f) {
    is_(str_contains($json, $f), false, "session list withholds {$f}");
}

t('usage answers "how busy is my Wi-Fi", not "what did that guest do"');
$u = $call('GET', '/api/v1/me/usage', [], $tokA)->body['usage'];
is_(isset($u['open_now'], $u['sessions_total'], $u['bytes_in'], $u['bytes_out']), true,
    'four aggregate figures');
is_($u['bytes_in'] > 4_000_000_000, true, 'and the total carries the gigawords through');

t('accounting is evidence — it cannot be deleted');
throws_(fn() => $ctx->run($A['customer'], fn($d) => $d->exec(
    'DELETE FROM mt_sessions WHERE id = ?', [$mine['id']])),
    'not deleted', 'the database refuses to delete a session');

exit(t_summary());

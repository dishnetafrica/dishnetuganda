<?php
declare(strict_types=1);
/**
 * test_partner_otp_delivery.php — WS-A P4 (docs/53): deliver a distributor-portal
 * sign-in code over the EXISTING Evolution WhatsApp, and the admin TOTP reset.
 *
 * Everything here is SYNTHETIC. The real transport is a fake Evolution server
 * (tests/fixtures/fake_evo_server.php); no message ever leaves the machine and no
 * live provider is contacted. The operator decision is reuse of the existing
 * Evolution integration, instance = the existing SUPPORT number (D2).
 *
 *   A migration 083 — the auth-log table; 'delivered' is not a legal outcome
 *   B recipient resolver — server-side, verified-only, the account's OWN number;
 *     never the request's number
 *   C accepted — a real send reaches the SUPPORT instance, to the verified
 *     number, carrying the code; recorded 'accepted' (NOT delivered)
 *   D rejection — a provider error records 'failed'
 *   E uncertain — a gateway 50x, and a real Uganda timeout, record 'unknown' and
 *     are NEVER auto-resent
 *   F unverified recipient — no verified number => NO send, uniform {status:sent}
 *   G duplicate / rate — the send caps gate sending; a throttled request sends nothing
 *   H secrets — the code, TOTP secret, apikey and token are in no log row or response
 *   I TOTP reset — admin-only, audited, revokes the account's live sessions
 *   J outcome mapping + no self-retry — the channel is called exactly once
 *   K separation + support binding — own path (not NotificationService), CLASS_STAFF, support instance
 *   L no accidental send — the live entry binds NO sender
 *   M weakened copies, each caught
 */
$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/TenantProfile.php';
require_once $root . '/lib/PhoneNumber.php';
require_once $root . '/lib/Totp.php';
require_once $root . '/lib/ContactOptOut.php';
require_once $root . '/lib/EvolutionApiService.php';
require_once $root . '/lib/WhatsAppChannel.php';
require_once $root . '/lib/DistributorRegistry.php';
require_once $root . '/lib/DistributorNotifier.php';
require_once $root . '/lib/PartnerContext.php';
require_once $root . '/lib/PartnerSession.php';
require_once $root . '/lib/PartnerAccounts.php';
require_once $root . '/lib/PartnerAuth.php';
require_once $root . '/lib/DistributorPortalData.php';
require_once $root . '/lib/PartnerApi.php';
require_once $root . '/lib/PartnerOtpSender.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function nc(string $f): string { $o=''; foreach (token_get_all((string)file_get_contents($f)) as $k){ if(is_array($k)){ if(in_array($k[0],[T_COMMENT,T_DOC_COMMENT],true)) continue; $o.=$k[1]; } else $o.=$k; } return $o; }
function threw(callable $fn): bool { try { $fn(); return false; } catch (\Throwable $e) { return true; } }
$withMutants = !in_array('--no-mutants', $argv, true);

// ── an in-test channel double: records every send, returns a scripted result ──
final class OtpCaptureChannel implements WhatsAppChannel
{
    /** @var array<int,array{phone:string,text:string}> */
    public array $calls = [];
    /** @var array<int,array{sent:bool,detail:string}> */
    private array $script;
    public function __construct(array $script) { $this->script = $script; }
    public function send(string $phone, string $text): array {
        $this->calls[] = ['phone' => $phone, 'text' => $text];
        $i = min(count($this->calls) - 1, count($this->script) - 1);
        return $this->script[$i] ?? ['sent' => false, 'detail' => ''];
    }
    public function name(): string { return 'capture'; }
    public function isLive(): bool { return false; }
}

// ── a fake Evolution, so a real send is observable ────────────────────────────
$router = $root . '/tests/fixtures/fake_evo_server.php';
$hit = function (int $port, string $p) {
    $ch = curl_init("http://127.0.0.1:{$port}{$p}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? null : (string)$r;
};
$srv = null; $port = 0;
foreach (range(0, 9) as $slot) {
    $cand = 9720 + ((getmypid() + $slot * 13) % 70);
    $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                   [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $ours = false;
    for ($i = 0; $i < 40; $i++) {
        $got = $hit($cand, '/__test/state');
        if ($got !== null) { $ours = strpos($got, 'FAKE-EVO-TEST') !== false; break; }
        usleep(100000);
    }
    if ($ours) { $srv = $p; $port = $cand; break; }
    if (is_resource($p)) { proc_terminate($p); proc_close($p); }
}
if ($port === 0) { fwrite(STDERR, "could not start the fake Evolution server\n"); exit(1); }
$state = function () use ($hit, $port) { return json_decode((string)$hit($port, '/__test/state'), true) ?: []; };
$textCalls = function () use ($state) { return $state()['text_calls'] ?? []; };
register_shutdown_function(function () use (&$srv) { if ($srv) { @proc_terminate($srv); @proc_close($srv); } });

$hit($port, '/__test/reset');

// ── a synthetic estate: a partner, a portal account, a verified contact ───────
$UG  = TenantProfile::load('uganda');
$cfg = ['webhook_secret' => str_repeat('s', 40)];
$NOW = 1700000000;
$tmp = sys_get_temp_dir() . '/potp_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$store = SqliteStore::create($tmp);
$pdo = $store->getPdo();
$reg = DistributorRegistry::fromStore($store);
$acct = PartnerAccounts::fromStore($store);
$notif = DistributorNotifier::fromStore($store);

$ACCT_PHONE = '+256700000001';
$A = $reg->create(['legal_name' => 'Alpha Distributors Ltd', 'trading_name' => 'Alpha'], 'adm');
$pid = (int)$A['id'];
$uA = $acct->create($pid, ['role' => 'head_office', 'display_name' => 'Alice', 'phone' => $ACCT_PHONE], 'adm');
$uid = (int)$uA['id'];
$cA = $notif->addContact($pid, $ACCT_PHONE, 'owner', 'adm');   // the account's own number
$notif->verifyContact((int)$cA['id'], 'adm');                   // a DELIBERATE staff act (verified = 1)

// the evo config points at the fake; the SUPPORT instance is the D2 binding
$INSTANCE = 'ug-support';
$evoCfg = $cfg + [
    'evo_api_url'          => "http://127.0.0.1:{$port}",
    'evo_api_key'          => 'test-key-SECRET',
    'evo_instance_support' => $INSTANCE,
    'evo_instance_account' => 'ug-account',
];
$mkSender = function (array $extra = [], int $timeout = 20) use ($pdo, $evoCfg, $INSTANCE) {
    $evo = new EvolutionApiService($evoCfg + $extra, $timeout);
    return new PartnerOtpSender($pdo, new EvolutionWhatsAppChannel($evo, EvolutionApiService::CHANNEL_SUPPORT));
};
/** Mint a fresh pending code for an account and return its delivery descriptor.
 *  A high cap here keeps the shared send ledger from throttling the many sends
 *  these sections make; section G tests the cap itself on a cleared ledger. */
$deliveryFor = function (string $phone) use ($pdo, $cfg, $UG, &$NOW) {
    $NOW += 10;
    $hi = $cfg + ['dist_portal_send_per_hour_user' => 1000, 'dist_portal_send_per_hour_ip' => 1000];
    $r = PartnerAuth::requestLoginCode($pdo, $hi, $phone, $UG, '10.0.0.1', $NOW);
    return $r['delivery'] ?? null;
};
$authRows = function (string $outcome = '') use ($pdo) {
    $sql = "SELECT * FROM dist_partner_auth_log" . ($outcome !== '' ? " WHERE outcome = " . $pdo->quote($outcome) : '') . " ORDER BY id";
    return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
};

echo "A. migration 083 — the auth-log; 'delivered' is not a legal outcome\n";
is_($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='dist_partner_auth_log'")->fetchColumn() === 'dist_partner_auth_log', 'dist_partner_auth_log table created');
$lcols = array_column($pdo->query("PRAGMA table_info(dist_partner_auth_log)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
is_(in_array('outcome', $lcols, true) && in_array('event', $lcols, true) && in_array('contact_id', $lcols, true), 'has outcome / event / contact_id columns');
is_(!in_array('code', $lcols, true) && !in_array('code_hash', $lcols, true) && !in_array('secret', $lcols, true) && !in_array('token', $lcols, true), 'has NO code / secret / token column');
is_(threw(fn() => $pdo->exec("INSERT INTO dist_partner_auth_log (user_id, event, outcome, at) VALUES (1,'otp_send','delivered'," . time() . ")")), "the CHECK rejects outcome='delivered' (accepted is never delivery)");
is_(!threw(fn() => $pdo->exec("INSERT INTO dist_partner_auth_log (user_id, event, outcome, at) VALUES (1,'otp_send','accepted'," . time() . ")")), "the CHECK accepts outcome='accepted' (control)");
$pdo->exec("DELETE FROM dist_partner_auth_log");   // clear the control row
is_(!defined('PartnerOtpSender::OUTCOME_DELIVERED') && strpos(nc($root . '/lib/PartnerOtpSender.php'), "'delivered'") === false, "PartnerOtpSender defines no 'delivered' outcome");

echo "\nB. recipient resolver — server-side, verified-only, the account's OWN number\n";
$sender = $mkSender();
$rc = $sender->resolveRecipient($uid);
is_(is_array($rc) && (preg_replace('/\D+/', '', (string)$rc['phone']) === '256700000001'), 'a verified contact matching the account number resolves');
// an account with an UNVERIFIED contact only
$uB = $acct->create($pid, ['role' => 'head_office', 'phone' => '+256700000002'], 'adm');
$ubid = (int)$uB['id'];
$notif->addContact($pid, '+256700000002', 'owner', 'adm');   // added but NOT verified
is_($sender->resolveRecipient($ubid) === null, 'an unverified contact is not a recipient (no send)');
// an account with NO contact at all
$uC = $acct->create($pid, ['role' => 'head_office', 'phone' => '+256700000003'], 'adm');
is_($sender->resolveRecipient((int)$uC['id']) === null, 'an account with no contact resolves to none');
// a verified contact that is NOT the account's own number
$uD = $acct->create($pid, ['role' => 'head_office', 'phone' => '+256700000004'], 'adm');
$cDwrong = $notif->addContact($pid, '+256700000555', 'owner', 'adm');
$notif->verifyContact((int)$cDwrong['id'], 'adm');
is_($sender->resolveRecipient((int)$uD['id']) === null, "a verified number that is not the account's own is not used");
// a disabled account
$uE = $acct->create($pid, ['role' => 'head_office', 'phone' => '+256700000006'], 'adm');
$cE = $notif->addContact($pid, '+256700000006', 'owner', 'adm'); $notif->verifyContact((int)$cE['id'], 'adm');
$acct->disable((int)$uE['id'], 'adm');
is_($sender->resolveRecipient((int)$uE['id']) === null, 'a disabled account resolves to no recipient');
// the resolver takes only a user id — it cannot be handed a destination
$rm = new ReflectionMethod('PartnerOtpSender', 'resolveRecipient');
is_($rm->getNumberOfParameters() === 1 && (string)($rm->getParameters()[0]->getType()) === 'int', 'resolveRecipient takes a user id only — no phone parameter');

echo "\nC. accepted — a real send reaches the SUPPORT instance, to the verified number, carrying the code\n";
$hit($port, '/__test/reset');
$before = count($textCalls());
$del = $deliveryFor($ACCT_PHONE);
is_(is_array($del) && (int)$del['user_id'] === $uid, 'control: a known active account yields a delivery descriptor');
$codeA = (string)$del['code'];
// prove the destination is NOT read from the request: hand a bogus phone in the descriptor
$del['phone'] = '+256999999999';
$res = $sender->send($del);
$calls = $textCalls();
is_($res['outcome'] === PartnerOtpSender::OUTCOME_ACCEPTED && $res['sent'] === true, "a 2xx from Evolution is recorded 'accepted'");
is_(count($calls) === $before + 1, 'exactly one send reached Evolution');
$last = $calls ? $calls[count($calls) - 1] : [];
is_(($last['instance'] ?? '') === $INSTANCE, 'on the SUPPORT instance (D2)', json_encode($last['instance'] ?? null));
is_(preg_replace('/\D+/', '', (string)($last['number'] ?? '')) === '256700000001', "to the account's VERIFIED number, not the request's bogus number");
is_(strpos((string)($last['text'] ?? ''), $codeA) !== false, 'the message carries the sign-in code');
$acc = $authRows('accepted');
is_(count($acc) === 1 && (int)$acc[0]['user_id'] === $uid && (int)$acc[0]['contact_id'] === (int)$cA['id'], "an auth-log row records 'accepted' with the contact provenance");
is_(strpos(json_encode($acc[0]), $codeA) === false, 'the accepted row does not contain the code');

echo "\nD. rejection — a provider error records 'failed'\n";
$hit($port, '/__test/reset');
$hit($port, '/__test/fail_next?n=1');            // next send → HTTP 500
$pdo->exec("DELETE FROM dist_partner_auth_log");
$res = $sender->send($deliveryFor($ACCT_PHONE));
is_($res['outcome'] === PartnerOtpSender::OUTCOME_FAILED && $res['sent'] === false, "a 500 from Evolution records 'failed'");
is_(count($authRows('failed')) === 1, "one 'failed' auth-log row");

echo "\nE. uncertain — a gateway 50x, and a real timeout, record 'unknown' and are NEVER resent\n";
$hit($port, '/__test/reset');
$hit($port, "/__test/fail_next?n=1&code=504");   // a gateway timeout: 2xx-less, 'may have been sent'
$pdo->exec("DELETE FROM dist_partner_auth_log");
$res = $sender->send($deliveryFor($ACCT_PHONE));
is_($res['outcome'] === PartnerOtpSender::OUTCOME_UNKNOWN, "a gateway 504 records 'unknown' (may have been sent)");
is_((int)($state()['fail_next'] ?? -1) === 0, 'the request reached Evolution exactly once (fail_next 1→0) — no resend');
is_(count($authRows('unknown')) === 1, "one 'unknown' auth-log row");

// a REAL connection timeout on the Uganda no-resend transport: the request leaves,
// no answer comes back, and it must be recorded unknown and sent once only.
require_once $root . '/lib/NotifyGate.php';
NotifyGate::reset();
$hit($port, '/__test/reset');
$holdDir = $tmp . '/hold_' . bin2hex(random_bytes(3));
@mkdir($holdDir, 0777, true);
$hit($port, '/__test/hold?dir=' . urlencode($holdDir));
$pdo->exec("DELETE FROM dist_partner_auth_log");
$before = count($textCalls());
$senderUg = $mkSender(['tenant_profile' => 'uganda', '_data_dir' => $tmp], 1);  // 1-second timeout
$t0 = microtime(true);
$res = $senderUg->send($deliveryFor($ACCT_PHONE));                 // blocks ~1s, then the request has 'maybe' gone
$elapsed = microtime(true) - $t0;
@file_put_contents($holdDir . '/release', '1');                    // let the fake finish its one recorded send
$after = $before;
for ($i = 0; $i < 30; $i++) { $after = count($textCalls()); if ($after >= $before + 1) break; usleep(100000); }
is_($res['outcome'] === PartnerOtpSender::OUTCOME_UNKNOWN, "a real timeout that left is recorded 'unknown'");
is_($elapsed < 2.5, 'it failed at once — it was not retried for another full timeout', sprintf('%.2fs', $elapsed));
is_($after === $before + 1, 'the held request reached Evolution exactly once — never resent', 'sends ' . $before . ' → ' . $after);
is_(count($authRows('unknown')) === 1, "one 'unknown' auth-log row for the timeout");

echo "\nF. unverified recipient — NO send, and the guest response stays uniform\n";
$hit($port, '/__test/reset');
$pdo->exec("DELETE FROM dist_partner_auth_log");
$before = count($textCalls());
$res = $sender->send($deliveryFor('+256700000002'));    // uB — contact added but never verified
is_($res['outcome'] === PartnerOtpSender::OUTCOME_NO_RECIPIENT && $res['sent'] === false, "no verified number → 'no_recipient'");
is_(count($textCalls()) === $before, 'nothing was sent to Evolution');
is_(count($authRows('no_recipient')) >= 1, "a 'no_recipient' row records the refusal");
// through the dispatcher the response is uniform whether or not a code was sent
$deliver = [$sender, 'send'];
$respUnknown = PartnerApi::handle($pdo, $cfg, $UG, ['action' => 'auth.request_code', 'method' => 'POST', 'body' => ['phone' => '0700999999'], 'server' => ['HTTP_X_REQUESTED_WITH' => PartnerSession::REQUESTED_WITH], 'ip' => '10.0.0.9'], $deliver);
$respUnverified = PartnerApi::handle($pdo, $cfg, $UG, ['action' => 'auth.request_code', 'method' => 'POST', 'body' => ['phone' => '+256700000002'], 'server' => ['HTTP_X_REQUESTED_WITH' => PartnerSession::REQUESTED_WITH], 'ip' => '10.0.0.9'], $deliver);
is_(($respUnknown['body']['status'] ?? '') === 'sent' && ($respUnverified['body']['status'] ?? '') === 'sent', 'the response is a uniform {status:sent} for an unknown number and an unverified account alike');

echo "\nG. duplicate / rate — the send caps gate sending; a throttled request sends nothing\n";
$hit($port, '/__test/reset');
$pdo->exec("DELETE FROM dist_partner_rate");   // this section tests the cap itself, from a clean ledger
$capCfg = $cfg + ['dist_portal_send_per_hour_user' => 2];
$sendCount = 0;
$countingDeliver = function (array $d) use ($sender, &$sendCount) { $r = $sender->send($d); if (($r['outcome'] ?? '') !== PartnerOtpSender::OUTCOME_NO_RECIPIENT) $sendCount++; };
$before = count($textCalls());
for ($i = 0; $i < 4; $i++) {
    PartnerApi::handle($pdo, $capCfg, $UG, ['action' => 'auth.request_code', 'method' => 'POST', 'body' => ['phone' => $ACCT_PHONE], 'server' => ['HTTP_X_REQUESTED_WITH' => PartnerSession::REQUESTED_WITH], 'ip' => '10.0.0.7'], $countingDeliver);
}
is_(count($textCalls()) === $before + 2, 'four requests under a cap of two produced exactly two sends — the rest were throttled with no send', 'sends +' . (count($textCalls()) - $before));
is_((int)$pdo->query("SELECT COUNT(*) FROM dist_partner_otp WHERE user_id=$uid")->fetchColumn() === 1, 'at most one pending code exists at a time (ON CONFLICT replaces)');

echo "\nH. secrets — the code, the TOTP secret, the apikey and tokens appear in no log row or response\n";
$allRows = json_encode($authRows());
$leak = false;
foreach ($authRows() as $r) { foreach ($r as $v) { if (is_string($v) && (string)$v !== '') {
    if (strpos($v, 'test-key-SECRET') !== false) $leak = true;                 // the apikey
} } }
is_(!$leak, 'the Evolution apikey appears in no auth-log row');
is_(strpos($allRows, 'DishNet distributor portal: your sign-in code') === false, 'no auth-log row stores the message body (which carries the code)');
// every pending/sent code is absent from the log
$codesSeen = true;
$probe = $deliveryFor($ACCT_PHONE); $sender->send($probe);
foreach ($authRows() as $r) { if (strpos(json_encode($r), (string)$probe['code']) !== false) $codesSeen = false; }
is_($codesSeen, 'the login code is in no auth-log row');

echo "\nI. TOTP reset — admin-only, audited, revokes the account's live sessions\n";
// enrol an authenticator and open a live session for uA
$en = PartnerAuth::beginEnrol($pdo, $uid);
$secret = (string)$en['secret'];
PartnerAuth::confirmEnrol($pdo, $uid, Totp::at($secret, time()), time());
$token = PartnerSession::issue($pdo, $cfg, $uid, '10.0.0.1', 'ua');
$live = PartnerSession::authenticate($pdo, $cfg, 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token], []);
is_(($live['user_id'] ?? 0) === $uid, 'control: the session authenticates before the reset');
$pdo->exec("DELETE FROM dist_partner_auth_log");
$rr = $acct->resetTotp($uid, 'Admin User <admin@dishnet>');
is_(($rr['ok'] ?? false) === true && (int)($rr['revoked'] ?? 0) >= 1, 'resetTotp reports ok and revoked at least one session');
$u = $acct->get($uid);
is_((string)$u['totp_secret'] === '' && (int)$u['totp_confirmed'] === 0, 'the authenticator is cleared — the distributor must re-enrol');
is_(threw(fn() => PartnerSession::authenticate($pdo, $cfg, 'GET', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token], [])), 'the previously-live session no longer authenticates (revoked)');
$resetRows = $authRows('ok');
is_(count($resetRows) === 1 && $resetRows[0]['event'] === 'totp_reset' && strpos((string)$resetRows[0]['actor'], 'Admin User') === 0, 'an audit row records the reset with the acting admin as actor');
is_(strpos(json_encode($resetRows[0]), $secret) === false, 'the audit row does not contain the TOTP secret');
// the staff handler wires it the W-1 way: requireAdmin, actor from the identity boundary
$ph = nc($root . '/includes/post/post_distributors.php');
is_(strpos($ph, 'dist_totp_reset') !== false && strpos($ph, 'resetTotp') !== false && strpos($ph, 'requireAdmin') !== false, 'post_distributors wires dist_totp_reset through requireAdmin + resetTotp');

echo "\nJ. outcome mapping + no self-retry — the channel is called EXACTLY once\n";
$map = [
    [true,  'sent',                                                            PartnerOtpSender::OUTCOME_ACCEPTED],
    [false, 'May have been sent — no answer from Evolution: timed out',         PartnerOtpSender::OUTCOME_UNKNOWN],
    [false, 'Bad Gateway [HTTP 502 on POST /message/sendText/x]',               PartnerOtpSender::OUTCOME_UNKNOWN],
    [false, 'instance not connected [HTTP 400 on POST /message/sendText/x]',    PartnerOtpSender::OUTCOME_FAILED],
    [false, 'Connection failed: Connection refused',                           PartnerOtpSender::OUTCOME_FAILED],
];
foreach ($map as [$sent, $detail, $want]) {
    $dbl = new OtpCaptureChannel([['sent' => $sent, 'detail' => $detail]]);
    $s = new PartnerOtpSender($pdo, $dbl);
    $r = $s->send(['user_id' => $uid, 'phone' => 'ignored', 'code' => '424242', 'ttl' => 600]);
    is_($r['outcome'] === $want, "a send result '" . substr($detail, 0, 28) . "…' maps to '$want'", 'got ' . $r['outcome']);
    is_(count($dbl->calls) === 1, '…and the channel was called exactly once (the sender never resends)');
}
// no verified recipient → the channel is never called at all
$dbl0 = new OtpCaptureChannel([['sent' => true, 'detail' => 'sent']]);
$s0 = new PartnerOtpSender($pdo, $dbl0);
$r0 = $s0->send(['user_id' => (int)$uC['id'], 'code' => '424242', 'ttl' => 600]);  // uC has no verified contact
is_($r0['outcome'] === PartnerOtpSender::OUTCOME_NO_RECIPIENT && count($dbl0->calls) === 0, 'with no verified recipient the channel is not called');

echo "\nK. separation + support binding\n";
$snd = nc($root . '/lib/PartnerOtpSender.php');
is_(strpos($snd, 'NotificationService') === false, 'the sender does NOT go through NotificationService (the customer/marketing path)');
is_(strpos($snd, 'CHANNEL_SUPPORT') !== false, 'the sender binds the SUPPORT channel (D2)');
is_(strpos($snd, 'CLASS_STAFF') !== false || strpos(nc($root . '/lib/WhatsAppChannel.php'), 'CLASS_STAFF') !== false, 'the auth message is CLASS_STAFF (never suppressed by a customer opt-out, never marketing)');

echo "\nL. no accidental send — the live entry binds NO sender\n";
$entry = nc($root . '/partner_api.php');
is_(strpos($entry, '$deliver = null') !== false, 'partner_api.php keeps $deliver = null');
is_(strpos($entry, 'PartnerOtpSender') === false, 'partner_api.php constructs NO sender (the example in the comment is stripped, so this is code, not prose)');
is_(strpos(nc($root . '/lib/PartnerApi.php'), 'PartnerOtpSender') === false, 'the dispatcher hardcodes no sender either — delivery is injected');

echo "\nM. weakened copies, each caught\n";
$otpMutant = function (string $anchorOld, string $anchorNew, string $klass) use ($root, $pdo) {
    $src = (string)file_get_contents($root . '/lib/PartnerOtpSender.php');
    $n = substr_count($src, $anchorOld);
    is_($n === 1, "mutant anchor present exactly once ($klass)", "found $n");
    if ($n !== 1) return null;
    $src = str_replace($anchorOld, $anchorNew, $src);
    $src = str_replace("require_once __DIR__ . '/WhatsAppChannel.php';", '', $src);   // already loaded; temp dir has no sibling
    $src = preg_replace('/\bclass PartnerOtpSender\b/', 'class ' . $klass, $src, 1, $cnt);
    if ($cnt !== 1) { is_(false, "mutant class rename ($klass)", "renamed $cnt"); return null; }
    $f = sys_get_temp_dir() . '/' . $klass . '_' . bin2hex(random_bytes(3)) . '.php';
    file_put_contents($f, $src);
    require $f;
    return $klass;
};
if ($withMutants) {
    // 1) a copy that reads the destination from the REQUEST
    $k = $otpMutant(
        '$res = $this->channel->send((string)$rc[\'phone\'], $text);   // exactly once; no loop',
        '$res = $this->channel->send((string)($delivery[\'phone\'] ?? $rc[\'phone\']), $text);   // exactly once; no loop',
        'PartnerOtpSender_MutReqPhone');
    if ($k) {
        $dbl = new OtpCaptureChannel([['sent' => true, 'detail' => 'sent']]);
        $m = new $k($pdo, $dbl);
        $m->send(['user_id' => $uid, 'phone' => '+256999999999', 'code' => '424242', 'ttl' => 600]);
        $sentTo = preg_replace('/\D+/', '', (string)($dbl->calls[0]['phone'] ?? ''));
        is_($sentTo === '256999999999', 'caught: a copy that reads the destination from the request sends to the request number');
    }
    // 2) a copy that records an UNCERTAIN send as 'accepted' (over-claiming acceptance)
    $k = $otpMutant(
        '$outcome = self::OUTCOME_UNKNOWN;                  // may have gone — never auto-resend',
        '$outcome = self::OUTCOME_ACCEPTED;                  // may have gone — never auto-resend',
        'PartnerOtpSender_MutAcceptUncertain');
    if ($k) {
        $dbl = new OtpCaptureChannel([['sent' => false, 'detail' => 'Bad Gateway [HTTP 502 on POST /message/sendText/x]']]);
        $m = new $k($pdo, $dbl);
        $r = $m->send(['user_id' => $uid, 'code' => '424242', 'ttl' => 600]);
        is_($r['outcome'] === PartnerOtpSender::OUTCOME_ACCEPTED, "caught: a copy that records an uncertain send as 'accepted'");
    }
    // 3) a copy that logs the code into the audit row
    $k = $otpMutant(
        '$outcome, self::note($outcome, $detail), \'\');',
        '$outcome, self::note($outcome, $detail) . \' code=\' . $code, \'\');',
        'PartnerOtpSender_MutLogCode');
    if ($k) {
        $pdo->exec("DELETE FROM dist_partner_auth_log");
        $dbl = new OtpCaptureChannel([['sent' => true, 'detail' => 'sent']]);
        $m = new $k($pdo, $dbl);
        $m->send(['user_id' => $uid, 'code' => '913579', 'ttl' => 600]);
        $leaked = false; foreach ($authRows() as $r) { if (strpos(json_encode($r), '913579') !== false) $leaked = true; }
        is_($leaked, 'caught: a copy that writes the code into the audit row leaks it');
        $pdo->exec("DELETE FROM dist_partner_auth_log");
    }
}

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

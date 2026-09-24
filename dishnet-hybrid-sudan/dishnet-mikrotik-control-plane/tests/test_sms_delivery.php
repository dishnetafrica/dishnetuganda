<?php
/**
 * Sign-in codes by SMS — docs/127 §C, migration 032 (phase 2).
 *
 * PROVED here, by execution:
 *   - the outbox belongs to dnb_def_auth and no login role holds anything on
 *     it; the worker's three functions are dnb_worker's alone, code issue is
 *     dnb_app's alone, and the old three-argument issue function is gone;
 *   - the code rests only SEALED, under a key derived from DNB_SECRET_KEY with
 *     its own label and the phone as associated data — the raw key does not
 *     open it, another phone does not open it, and the envelope is erased the
 *     moment the message settles or expires;
 *   - only an active person of an active operator is ever queued a code; every
 *     request writes the same rows either way (S-1, S-2);
 *   - a person asks for a code on the route, the WORKER sends it, and the code
 *     in the message signs them in — through a recording double, and through
 *     the real Africa's Talking adapter against a loopback fake provider;
 *   - retry, failure, lease, stale claims, SKIP LOCKED and expiry behave as
 *     migration 032 says, and nothing is claimed while no sender is configured;
 *   - the adapter's request shape, its reading of every answer, its timeout,
 *     and that no reason it gives ever carries the key, the number or the code;
 *   - DN_SMS selects the sender with no fallback, the doctor says what the
 *     worker would do, and the worker process starts, refuses and logs as said.
 *
 * NOT PROVED, and not claimed: that the real provider accepts these requests
 * or answers as documented — only a real message from the operator's own
 * account shows that (S-6); anything on staging, a phone, the operator app
 * (phase 3), a router or production.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Crypto\SecretBox;
use Dn\Db\Database;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Jobs\SmsWorker;
use Dn\Notify\AfricasTalkingSms;
use Dn\Notify\CodeEnvelope;
use Dn\Notify\NullSms;
use Dn\Notify\SignInMessage;
use Dn\Notify\SmsResult;
use Dn\Notify\SmsSender;
use Dn\Notify\SmsSenders;
use Dn\Plugin\Doctor;
use Dn\Plugin\Manifest;
use Dn\Tenancy\TenantContext;

/** A test double: records every message and answers as it is told. */
final class RecordingSms implements SmsSender
{
    /** @var list<array{to:string,message:string}> */
    public array $sent = [];
    public function __construct(private ?\Closure $answer = null) {}
    public function bindingName(): string { return 'recording-test-double'; }
    public function isConfigured(): bool  { return true; }
    public function send(string $to, string $message): SmsResult
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
        return $this->answer !== null ? ($this->answer)($to, $message) : SmsResult::sent('TEST-' . count($this->sent));
    }
}

$root   = dirname(__DIR__);
$ins    = Database::inspector();      // BYPASSRLS fixture identity: sets up state, proves nothing by itself
$app    = Database::app();
$worker = Database::worker();
$ids    = seed_two_customers($ins);
$A = $ids['A']; $B = $ids['B'];
$auth = new Authenticator($app);
$k    = new Kernel(Routes::build($auth), $app, $auth, new TenantContext($app));
$call = static fn(string $m, string $p, array $body = [], string $tok = '') => $k->handle(
    new Request($m, $p, $tok !== '' ? ['Authorization' => "Bearer {$tok}"] : [], $body, [], '10.0.0.9'));
$ownerPhone = static fn(string $op) => $ins->one(
    'SELECT phone FROM mt_principals WHERE customer_id = ? AND kind = ? LIMIT 1', [$op, 'owner'])['phone'];
$phoneA = $ownerPhone($A['customer']);
$phoneB = $ownerPhone($B['customer']);
$outbox = static fn(string $phone) => $ins->one(
    'SELECT o.* FROM mt_auth_sms_outbox o JOIN mt_auth_codes c ON c.id = o.code_id
      WHERE c.phone = ? ORDER BY o.created_at DESC LIMIT 1', [$phone]);
$reset  = static fn(string $phone) => $ins->exec('DELETE FROM mt_auth_codes WHERE phone = ?', [$phone]);
$counts = static fn() => $ins->one('SELECT (SELECT count(*) FROM mt_auth_codes)::int AS codes,
                                           (SELECT count(*) FROM mt_auth_sms_outbox)::int AS outbox');
$codeIn = static fn(string $message): ?string => preg_match('/code: (\d{6})\./', $message, $m) ? $m[1] : null;
$master = getenv('DNB_SECRET_KEY') ?: '';

// Messages other suites left queued are closed first, so every count below is
// this suite's own. A fixture action by the fixture identity, nothing more.
$closePending = static fn() => $ins->exec(
    "UPDATE mt_auth_sms_outbox SET state = 'expired', sealed = NULL, lease_until = NULL, settled_at = now()
      WHERE state IN ('queued','sending')");
$closePending();

// ===========================================================================
t('1. MIGRATION 032 — the outbox is dnb_def_auth\'s, and no login role holds anything on it');
is_($ins->one("SELECT pg_get_userbyid(relowner) AS o FROM pg_class WHERE oid = 'mt_auth_sms_outbox'::regclass")['o'],
    'dnb_def_auth', 'the outbox is owned by dnb_def_auth — created as that role');
$loginRoles = array_column($ins->query(
    "SELECT rolname FROM pg_roles WHERE rolcanlogin AND rolname LIKE 'dnb\\_%' ORDER BY rolname"), 'rolname');
$held = [];
foreach ($loginRoles as $r) {
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $p) {
        if ($ins->one('SELECT has_table_privilege(?,?,?) AS h', [$r, 'mt_auth_sms_outbox', $p])['h']) { $held[] = "{$r} {$p}"; }
    }
}
is_([count($loginRoles) >= 7, $held], [true, []],
    'no dnb_* login role holds any privilege on it (' . count($loginRoles) . ' enumerated from pg_roles, not from a list)');
is_($ins->one("SELECT has_table_privilege('dnb_def_auth','mt_auth_sms_outbox','SELECT') AS h")['h'], true,
    'CONTROL: the owner can — the negatives above are not a broken probe');
is_($ins->query("SELECT 1 FROM pg_default_acl d JOIN pg_roles r ON r.oid = d.defaclrole WHERE r.rolname = 'dnb_def_auth'"), [],
    'dnb_def_auth has no default ACL, so nothing it creates grants anything by default');

is_($ins->one("SELECT to_regprocedure('mt_auth_issue_code(text,text,interval)') IS NULL AS gone")['gone'], true,
    'the three-argument mt_auth_issue_code is gone — no code can be issued without a sealed payload');
$reach = static fn(string $f) => array_column($ins->query(
    "SELECT rolname FROM pg_roles WHERE rolcanlogin AND rolname LIKE 'dnb\\_%'
        AND has_function_privilege(oid, ?::regprocedure, 'EXECUTE') ORDER BY 1", [$f]), 'rolname');
foreach (['mt_auth_issue_code(text,text,interval,text)'         => ['dnb_app'],
          'mt_auth_sms_claim(integer)'                          => ['dnb_worker'],
          'mt_auth_sms_settle(uuid,integer,text,text,text)'     => ['dnb_worker'],
          'mt_auth_sms_expire()'                                => ['dnb_worker']] as $f => $who) {
    $r = $ins->one('SELECT pg_get_userbyid(proowner) AS o, prosecdef AS d FROM pg_proc WHERE oid = ?::regprocedure', [$f]);
    is_([$r['o'], (bool) $r['d'], $reach($f)], ['dnb_def_auth', true, $who],
        "{$f}: SECURITY DEFINER owned by dnb_def_auth, executable by " . implode(', ', $who) . ' and no other login role');
}
is_([(bool) $ins->one("SELECT has_table_privilege('dnb_def_auth','mt_auth_codes','REFERENCES') AS h")['h'],
     (bool) $ins->one("SELECT has_schema_privilege('dnb_def_auth','public','CREATE') AS h")['h']], [false, false],
    'dnb_def_auth kept neither the REFERENCES the foreign key needed nor CREATE on the schema');
is_((int) $ins->one("SELECT count(*)::int AS n FROM mt_migrations WHERE filename = '032_sign_in_codes_by_sms.sql'")['n'], 1,
    'the ledger records 032');

// ===========================================================================
t('2. SEALED AT REST (S-3) — the code is never stored in clear, and only its own key and phone open it');
$reset($phoneA);
putenv('DNB_EXPOSE_OTP=1');           // this process only: lets the suite read the code the route issued
$rc = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
putenv('DNB_EXPOSE_OTP');
$code = (string) ($rc->body['dev_code'] ?? '');
$row  = $outbox($phoneA);
is_([$rc->status, preg_match('/^\d{6}$/', $code), $row['state'] ?? null, (int) ($row['attempts'] ?? -1)], [202, 1, 'queued', 0],
    'CONTROL: an active owner\'s request queues one message, not yet attempted');
$sealed = (string) ($row['sealed'] ?? '');
$bytes  = (string) base64_decode(substr($sealed, 3), true);
is_([str_starts_with($sealed, 'v1.'), str_contains($sealed, $code), str_contains($bytes, $code)], [true, false, false],
    'the row holds an envelope, and the code is in neither the envelope text nor its decoded bytes');
is_((new CodeEnvelope())->open($sealed, $phoneA), $code, 'CONTROL: the derived key and the right phone open it to the very code');
throws_(fn() => (new CodeEnvelope())->open($sealed, $phoneB), 'did not open',
    'with another number as the associated data it does not open: a code cannot be redirected to another phone');
throws_(fn() => (new SecretBox($master))->open($sealed, $phoneA), 'did not open',
    'the RAW DNB_SECRET_KEY does not open it — the key is derived, not reused');
throws_(fn() => (new CodeEnvelope())->open((new SecretBox($master))->seal($code, $phoneA), $phoneA), 'did not open',
    'and the other direction: a device-credential envelope does not open as a sign-in code');
throws_(fn() => new CodeEnvelope(''), 'DNB_SECRET_KEY is not set', 'with no master key there is no envelope — no default key');
is_(str_contains(json_encode($ins->one('SELECT * FROM mt_auth_codes WHERE id = ?', [$row['code_id']])), $code), false,
    'the code row carries only the hash, as before');

// The table is the floor under all of this: whatever a function does, a row
// that is no longer pending cannot hold an envelope, and a lease exists only
// while a message is being sent.
foreach (["state = 'sent'"                                => 'a sent row that still holds its envelope',
          "state = 'queued', lease_until = now()"         => 'a queued row with a lease',
          "state = 'queued', sealed = NULL"               => 'a queued row with no envelope'] as $set => $what) {
    $e = null;
    try { $ins->exec("UPDATE mt_auth_sms_outbox SET {$set} WHERE id = ?", [$row['id']]); }
    catch (\PDOException $x) { $e = $x->errorInfo[0] ?? null; }
    is_($e, '23514', "the table itself refuses {$what}");
}

// ===========================================================================
t('3. WHO GETS A CODE (S-1) — only an active person of an active operator; the same rows either way (S-2)');
$unknown = '+256799' . random_int(100000, 999999);
$reset($unknown); $reset($phoneA);
$before = $counts();
$r1 = $call('POST', '/api/v1/auth/request-code', ['phone' => $unknown]);
$mid = $counts();
$r2 = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
$after = $counts();
is_([$mid['codes'] - $before['codes'], $mid['outbox'] - $before['outbox'], $after['codes'] - $mid['codes'], $after['outbox'] - $mid['outbox']],
    [1, 1, 1, 1], 'each request writes exactly one code row and one outbox row, registered or not');
is_([[$r1->status, $r1->body], [$r2->status, $r2->body]], [[202, ['status' => 'sent']], [202, ['status' => 'sent']]],
    'and answers the same');
$u = $outbox($unknown);
is_([$u['state'], $u['sealed']], ['no_recipient', null], 'an unknown number: no_recipient, and no payload at all');
is_($outbox($phoneA)['state'], 'queued', 'CONTROL: the active owner\'s is queued');
$ins->exec("UPDATE mt_customers SET status = 'suspended' WHERE id = ?", [$A['customer']]);
$reset($phoneA);
$call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
$s = $outbox($phoneA);
$ins->exec("UPDATE mt_customers SET status = 'active' WHERE id = ?", [$A['customer']]);
is_([$s['state'], $s['sealed']], ['no_recipient', null], 'a suspended operator\'s owner: no_recipient — no code is ever sent');
$ins->exec("UPDATE mt_principals SET status = 'disabled' WHERE phone = ?", [$phoneA]);
$reset($phoneA);
$call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
$d = $outbox($phoneA);
$ins->exec("UPDATE mt_principals SET status = 'active' WHERE phone = ?", [$phoneA]);
is_([$d['state'], $d['sealed']], ['no_recipient', null], 'a disabled person: no_recipient too');

// ===========================================================================
t('4. A PAYLOAD IS REQUIRED — refused the same way for every number, before anything is written');
foreach ([$unknown => 'an unknown number', $phoneA => 'a registered owner'] as $ph => $what) {
    foreach ([null => 'no payload', 'not-an-envelope' => 'a malformed one'] as $bad => $how) {
        $before = $counts();
        $state = null;
        try { $app->one('SELECT mt_auth_issue_code(?,?,?::interval,?) AS id', [$ph, str_repeat('0', 64), 'PT10M', $bad === '' ? null : $bad]); }
        catch (\PDOException $e) { $state = $e->errorInfo[0] ?? null; }
        is_([$state, $counts()], ['22023', $before], "{$what}, {$how}: 22023 and no row written");
    }
}

// ===========================================================================
t('5. END TO END — the route queues, the WORKER sends, the code in the message signs the person in');
$reset($phoneA); $reset($unknown);
$rec  = new RecordingSms();
$sms  = new SmsWorker($worker, $rec);
$rq   = $call('POST', '/api/v1/auth/request-code', ['phone' => $phoneA]);
$call('POST', '/api/v1/auth/request-code', ['phone' => $unknown]);
is_([$rq->status, $rq->body], [202, ['status' => 'sent']], 'the route answers 202 {status: sent} and shows no code');
$run = $sms->runOnce();
is_([$run['claimed'], $run['sent'], count($rec->sent)], [1, 1, 1], 'one message claimed and sent — the unknown number\'s is not');
$msg = $rec->sent[0] ?? ['to' => '', 'message' => ''];
$sentCode = $codeIn($msg['message']);
is_([$msg['to'], $msg['message']], [$phoneA, SignInMessage::text((string) $sentCode)],
    'to the canonical number, with exactly the fixed text (S-7)');
is_(SignInMessage::text('123456'), 'DishNet sign-in code: 123456. Valid 10 minutes. Do not share it.',
    'the text is: DishNet sign-in code: 123456. Valid 10 minutes. Do not share it.');
$row = $outbox($phoneA);
is_([$row['state'], $row['sealed'], $row['provider_ref'], (int) $row['attempts'], $row['settled_at'] !== null],
    ['sent', null, 'TEST-1', 1, true], 'the row is sent, the envelope erased, the provider reference kept');
$vr = $call('POST', '/api/v1/auth/verify', ['phone' => $phoneA, 'code' => (string) $sentCode]);
is_([$vr->status, isset($vr->body['token'])], [200, true], 'the code the phone received signs the owner in');
is_($call('GET', '/api/v1/me', [], (string) ($vr->body['token'] ?? ''))->status, 200, 'and the session answers GET /me');
is_(str_contains(json_encode($run), (string) $sentCode) || str_contains(json_encode($run), $phoneA), false,
    'the worker\'s report is counts only — no code, no number');
is_($sms->runOnce()['claimed'], 0, 'nothing is sent twice');

// ===========================================================================
t('6. RETRY, FAILURE, LEASE, EXPIRY');
$reset($phoneA);
$flaky = new RecordingSms(static fn() => SmsResult::retry('HTTP 503'));
$sw    = new SmsWorker($worker, $flaky);
$auth->issueCode($phoneA);
$sw->runOnce();
$r = $outbox($phoneA);
is_([$r['state'], (int) $r['attempts'], $r['sealed'] !== null, $r['last_error']], ['queued', 1, true, 'HTTP 503'],
    'a retryable answer re-queues it, envelope kept, reason stored');
$sw->runOnce();
$sw->runOnce();
$r = $outbox($phoneA);
is_([$r['state'], (int) $r['attempts'], $r['sealed'], count($flaky->sent)], ['failed', 3, null, 3],
    'after the third attempt it fails and the envelope is erased — three sends, no more');
is_($sw->runOnce()['claimed'], 0, 'and it is never claimed again');

$reset($phoneA);
$refuse = new RecordingSms(static fn() => SmsResult::failed('provider status 403 InvalidPhoneNumber'));
$auth->issueCode($phoneA);
(new SmsWorker($worker, $refuse))->runOnce();
$r = $outbox($phoneA);
is_([$r['state'], (int) $r['attempts'], $r['sealed'], $r['last_error']], ['failed', 1, null, 'provider status 403 InvalidPhoneNumber'],
    'a permanent refusal fails at once — no attempt is wasted on it');

// The lease, and a stale claim.
$reset($phoneA);
$auth->issueCode($phoneA);
$c1 = $worker->query('SELECT id, attempt FROM mt_auth_sms_claim(10)');
is_([count($c1), (int) ($c1[0]['attempt'] ?? 0), $outbox($phoneA)['state']], [1, 1, 'sending'], 'claimed: sending, attempt 1, under a lease');
is_($worker->query('SELECT id FROM mt_auth_sms_claim(10)'), [], 'while the lease holds, nobody claims it again');
$ins->exec("UPDATE mt_auth_sms_outbox SET lease_until = now() - interval '1 second' WHERE id = ?", [$c1[0]['id']]);
$c2 = $worker->query('SELECT id, attempt FROM mt_auth_sms_claim(10)');
is_([count($c2), (int) ($c2[0]['attempt'] ?? 0)], [1, 2], 'its worker gone and the lease lapsed, it is claimed again as attempt 2');
is_($worker->one('SELECT mt_auth_sms_settle(?,?,?,?,?) AS s', [$c1[0]['id'], 1, 'sent', 'LATE', null])['s'], null,
    'the first worker, arriving late with attempt 1, settles nothing');
is_($outbox($phoneA)['state'], 'sending', 'and the newer claim is untouched');
is_($worker->one('SELECT mt_auth_sms_settle(?,?,?,?,?) AS s', [$c2[0]['id'], 2, 'sent', 'ON-TIME', null])['s'], 'sent',
    'the current claim settles it');
is_($worker->one('SELECT mt_auth_sms_settle(?,?,?,?,?) AS s', [$c2[0]['id'], 2, 'sent', 'AGAIN', null])['s'], null,
    'a second settle finds nothing to settle');
$e = null;
try { $worker->one('SELECT mt_auth_sms_settle(?,?,?,?,?) AS s', [$c2[0]['id'], 2, 'delivered', null, null]); }
catch (\PDOException $x) { $e = $x->errorInfo[0] ?? null; }
is_($e, '22023', 'an outcome that is not sent, retry or failed is refused');

// SKIP LOCKED: a claim in flight is invisible to a second worker.
$reset($phoneA); $reset($phoneB);
$auth->issueCode($phoneA);
$auth->issueCode($phoneB);
$w2 = Database::worker();
$w2->exec("SET lock_timeout = '2s'");   // without SKIP LOCKED this would wait: fail, don't hang
$worker->exec('BEGIN');
$held1 = $worker->query('SELECT id, phone FROM mt_auth_sms_claim(1)');
try { $held2 = $w2->query('SELECT id, phone FROM mt_auth_sms_claim(10)'); }
catch (\PDOException $x) { $held2 = []; }
$worker->exec('ROLLBACK');
is_([count($held1), count($held2), ($held1[0]['id'] ?? 'a') !== ($held2[0]['id'] ?? 'a')], [1, 1, true],
    'two workers at once claim two different messages — never the same one twice');
$closePending();

// Expiry.
$reset($phoneA);
$auth->issueCode($phoneA);
$ins->exec("UPDATE mt_auth_sms_outbox o SET expires_at = now() - interval '1 second'
             FROM mt_auth_codes c WHERE c.id = o.code_id AND c.phone = ?", [$phoneA]);
is_($worker->query('SELECT id FROM mt_auth_sms_claim(10)'), [], 'a message whose code has expired is not claimed');
is_((int) $worker->one('SELECT mt_auth_sms_expire() AS n')['n'], 1, 'expire closes it');
$r = $outbox($phoneA);
is_([$r['state'], $r['sealed'], $r['last_error']], ['expired', null, 'not sent before the code expired'], 'as expired, envelope erased');
$reset($phoneA);
$auth->issueCode($phoneA);
$c = $worker->query('SELECT id FROM mt_auth_sms_claim(10)');
$ins->exec("UPDATE mt_auth_sms_outbox SET attempts = 3, lease_until = now() - interval '1 second' WHERE id = ?", [$c[0]['id']]);
try { $fourth = $worker->query('SELECT id FROM mt_auth_sms_claim(10)'); }
catch (\PDOException $x) { $fourth = 'refused ' . ($x->errorInfo[0] ?? ''); }
is_($fourth, [], 'a third attempt whose lease lapsed is never claimed a fourth time — nor does the claim fail on it');
is_((int) $worker->one('SELECT mt_auth_sms_expire() AS n')['n'], 1, 'a third attempt whose worker never reported back');
is_([$outbox($phoneA)['state'], $outbox($phoneA)['last_error']], ['failed', 'the last attempt did not report back'], 'is closed as failed');

// No sender configured: nothing is claimed, and the message expires.
$reset($phoneA);
$auth->issueCode($phoneA);
$nw = new SmsWorker($worker, new NullSms());
is_([$nw->runOnce()['claimed'], $outbox($phoneA)['state'], (int) $outbox($phoneA)['attempts']], [0, 'queued', 0],
    'NullSms: nothing is claimed and no attempt is spent');
$ins->exec("UPDATE mt_auth_sms_outbox o SET expires_at = now() - interval '1 second'
             FROM mt_auth_codes c WHERE c.id = o.code_id AND c.phone = ?", [$phoneA]);
is_([$nw->runOnce()['expired'], $outbox($phoneA)['state'], $outbox($phoneA)['sealed']], [1, 'expired', null],
    'and when the code expires, so does the message — the truth');

// An envelope that does not open is never sent.
$reset($phoneA);
$auth->issueCode($phoneA);
$never = new RecordingSms();
(new SmsWorker($worker, $never, new CodeEnvelope('some-other-master-key')))->runOnce();
$r = $outbox($phoneA);
is_([count($never->sent), $r['state'], $r['sealed'], $r['last_error']], [0, 'failed', null, 'the sealed code did not open'],
    'under another key the envelope does not open: nothing is sent, the row fails, the envelope is erased');

// A sender that throws is retried, and what it said is not kept.
$reset($phoneA);
$auth->issueCode($phoneA);
(new SmsWorker($worker, new RecordingSms(static function () { throw new \RuntimeException('secret-canary-in-exception'); })))->runOnce();
$r = $outbox($phoneA);
is_([$r['state'], $r['last_error']], ['queued', 'the sender failed unexpectedly'],
    'a sender that throws is retried, and its exception message is not stored');

// ===========================================================================
t('7. SELECTION (S-5) — DN_SMS chooses, and nothing falls back');
$envKeys = ['DN_SMS', 'DNB_SMS_USERNAME', 'DNB_SMS_API_KEY', 'DNB_SMS_SENDER'];
$saved = [];
foreach ($envKeys as $x) { $saved[$x] = getenv($x); putenv($x); }
$withEnv = static function (array $env, callable $fn) use ($envKeys) {
    foreach ($envKeys as $x) { putenv($x); }
    foreach ($env as $x => $v) { putenv("{$x}={$v}"); }
    try { return $fn(); } finally { foreach ($envKeys as $x) { putenv($x); } }
};
$KEY = 'KEY-canary-' . bin2hex(random_bytes(6));
is_($withEnv([], fn() => get_class(SmsSenders::fromEnvironment())), NullSms::class, 'unset: NullSms');
is_($withEnv(['DN_SMS' => 'null'], fn() => get_class(SmsSenders::fromEnvironment())), NullSms::class, "'null': NullSms");
$msgOf = static function (callable $fn): string { try { $fn(); return ''; } catch (\Throwable $e) { return $e->getMessage(); } };
$m1 = $withEnv(['DN_SMS' => 'africastalking', 'DNB_SMS_API_KEY' => $KEY], fn() => $msgOf(fn() => SmsSenders::fromEnvironment()));
is_([str_contains($m1, 'DNB_SMS_USERNAME'), str_contains($m1, $KEY)], [true, false],
    'africastalking without a username refuses to start, naming the variable and never the key');
$m2 = $withEnv(['DN_SMS' => 'africastalking', 'DNB_SMS_USERNAME' => 'dishnet'], fn() => $msgOf(fn() => SmsSenders::fromEnvironment()));
is_(str_contains($m2, 'DNB_SMS_API_KEY'), true, 'without a key it refuses too');
$live = $withEnv(['DN_SMS' => 'africastalking', 'DNB_SMS_USERNAME' => 'dishnet', 'DNB_SMS_API_KEY' => $KEY],
                 fn() => SmsSenders::fromEnvironment());
is_([get_class($live), $live->isSandbox()], [AfricasTalkingSms::class, false], 'with both: the adapter, on the live endpoint');
$sand = $withEnv(['DN_SMS' => 'AfricasTalking', 'DNB_SMS_USERNAME' => 'sandbox', 'DNB_SMS_API_KEY' => $KEY],
                 fn() => SmsSenders::fromEnvironment());
is_($sand->isSandbox(), true, "the username 'sandbox' selects the provider's sandbox (the SDK's own rule); the mode is case-insensitive");
is_(str_contains($withEnv(['DN_SMS' => 'twilio'], fn() => $msgOf(fn() => SmsSenders::fromEnvironment())), "'twilio' is not an SMS binding"), true,
    'any other value refuses to start');
is_(str_contains($withEnv(['DN_SMS' => 'africastalking', 'DNB_SMS_USERNAME' => 'dishnet', 'DNB_SMS_API_KEY' => $KEY,
                           'DNB_SMS_SENDER' => 'DishNet"; rm'], fn() => $msgOf(fn() => SmsSenders::fromEnvironment())), 'sender name'), true,
    'a sender name that is not a plain name refuses to start');
is_([AfricasTalkingSms::LIVE, AfricasTalkingSms::SANDBOX],
    ['https://api.africastalking.com/version1/messaging', 'https://api.sandbox.africastalking.com/version1/messaging'],
    'the endpoints are the SDK\'s, exactly');
is_([AfricasTalkingSms::acceptableEndpoint('https://api.africastalking.com/version1/messaging'),
     AfricasTalkingSms::acceptableEndpoint('http://127.0.0.1:1/x'),
     AfricasTalkingSms::acceptableEndpoint('http://api.africastalking.com/version1/messaging'),
     AfricasTalkingSms::acceptableEndpoint('http://10.0.0.1/x'), AfricasTalkingSms::acceptableEndpoint('ftp://x/y')],
    [true, true, false, false, false], 'only HTTPS, or plain HTTP to a loopback test server, is accepted as an endpoint');
throws_(fn() => new AfricasTalkingSms('u', $KEY, null, 'http://sms.example/version1/messaging'), 'not HTTPS',
    'and the constructor refuses anything else');

// ===========================================================================
t('8. THE DOCTOR — reports what the worker would do, and never a value');
$man = Manifest::load($root . '/plugin/plugin.json');
$docRow = static function (array $env) use ($withEnv, $man, $root): array {
    return $withEnv($env, static function () use ($man, $root) {
        foreach ((new Doctor($man, $root, true))->run() as $r) { if ($r['id'] === 'sms.sender') { return $r; } }
        return [];
    });
};
$d0 = $docRow([]);
is_([$d0['state'] ?? null, str_contains($d0['detail'] ?? '', 'no operator can sign in')], [Doctor::WARN, true],
    'DN_SMS unset: WARN — no operator can sign in');
$d1 = $docRow(['DN_SMS' => 'africastalking', 'DNB_SMS_USERNAME' => 'dishnet']);
is_([$d1['state'] ?? null, str_contains($d1['detail'] ?? '', 'DNB_SMS_API_KEY')], [Doctor::BLOCKER, true],
    'the adapter without its key: BLOCKER, naming the variable');
$d2 = $docRow(['DN_SMS' => 'africastalking', 'DNB_SMS_USERNAME' => 'dishnet', 'DNB_SMS_API_KEY' => $KEY]);
is_([$d2['state'] ?? null, str_contains($d2['detail'] ?? '', 'value withheld'), str_contains(json_encode($d2), $KEY)],
    [Doctor::OK, true, false], 'configured: OK, "value withheld", and the key appears nowhere in the row');
$d3 = $docRow(['DN_SMS' => 'africastalking', 'DNB_SMS_USERNAME' => 'sandbox', 'DNB_SMS_API_KEY' => $KEY]);
is_(str_contains($d3['detail'] ?? '', 'SANDBOX'), true, 'the sandbox says so: its messages reach the provider\'s simulator, not phones');
is_(($docRow(['DN_SMS' => 'twilio'])['state'] ?? null), Doctor::BLOCKER, 'an unknown binding: BLOCKER');
foreach ($saved as $x => $v) { putenv($v === false ? $x : "{$x}={$v}"); }

// ===========================================================================
t('9. THE ADAPTER over a real socket, against a loopback fake provider');
$fdir = sys_get_temp_dir() . '/dnb-fake-sms-' . bin2hex(random_bytes(4));
mkdir($fdir, 0700);
$port = 59400 + (getmypid() % 400);
$flog = $fdir . '/server.log';
$fpid = (int) shell_exec(sprintf('FAKE_SMS_DIR=%s PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:%d %s > %s 2>&1 & echo $!',
    escapeshellarg($fdir), $port, escapeshellarg(__DIR__ . '/fake_sms_provider.php'), escapeshellarg($flog)));
register_shutdown_function(static function () use ($fpid, $fdir) {
    if ($fpid > 0) { @shell_exec("kill {$fpid} 2>/dev/null"); }
    foreach (glob($fdir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($fdir);
});
$up = false;
for ($i = 0; $i < 100; $i++) {
    $sk = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($sk) { fclose($sk); $up = true; break; }
    usleep(50_000);
}
is_($up, true, "the fake provider is listening on {$port}");
$url = "http://127.0.0.1:{$port}/version1/messaging";
$scene = static function (?int $status, ?string $body = null, int $sleep = 0) use ($fdir): void {
    @unlink($fdir . '/requests.jsonl');
    $s = ['sleep' => $sleep];
    if ($status !== null) { $s['status'] = $status; }
    if ($body !== null)   { $s['body'] = $body; }
    file_put_contents($fdir . '/scenario.json', json_encode($s));
};
$requests = static fn() => array_values(array_filter(array_map(static fn($l) => json_decode($l, true),
    file(is_file($fdir . '/requests.jsonl') ? $fdir . '/requests.jsonl' : '/dev/null') ?: [])));
$at   = new AfricasTalkingSms('dishnet', $KEY, 'DishNet', $url);
$TO   = '+256700' . random_int(100000, 999999);
$TEXT = SignInMessage::text('482913');
$said = [];      // every reason the adapter gave, checked for secrets at the end

$scene(null);
$res = $at->send($TO, $TEXT);
$rq  = $requests()[0] ?? [];
is_([$res->outcome, str_starts_with((string) $res->providerRef, 'ATXid_fake_')], [SmsResult::SENT, true],
    'an accepted message: sent, with the provider\'s message id as the reference');
is_([$rq['method'] ?? null, $rq['path'] ?? null, $rq['apikey'] ?? null, $rq['accept'] ?? null,
     str_starts_with((string) ($rq['content_type'] ?? ''), 'application/x-www-form-urlencoded')],
    ['POST', '/version1/messaging', $KEY, 'application/json', true],
    'the request: POST, the SDK\'s path, the apikey and Accept headers, a form body');
is_($rq['fields'] ?? null, ['username' => 'dishnet', 'to' => $TO, 'message' => $TEXT, 'from' => 'DishNet'],
    'the fields: username, to, message and from — exactly, and nothing else');
$scene(null);
(new AfricasTalkingSms('dishnet', $KEY, null, $url))->send($TO, $TEXT);
is_(array_key_exists('from', $requests()[0]['fields'] ?? []), false, 'no sender name configured: no from field at all');

$cases = [
    'a refused number'          => [201, json_encode(['SMSMessageData' => ['Message' => 'Sent to 0/1', 'Recipients' => [['statusCode' => 403, 'number' => $TO, 'status' => 'InvalidPhoneNumber']]]]),
                                    SmsResult::FAILED, 'provider status 403 InvalidPhoneNumber'],
    'no balance'                => [201, json_encode(['SMSMessageData' => ['Message' => 'Sent to 0/1', 'Recipients' => [['statusCode' => 405, 'number' => $TO, 'status' => 'InsufficientBalance']]]]),
                                    SmsResult::RETRY, 'provider status 405 InsufficientBalance'],
    'a status code as a string' => [201, json_encode(['SMSMessageData' => ['Message' => 'Sent to 1/1', 'Recipients' => [['statusCode' => '102', 'number' => $TO, 'messageId' => 'ATXid_q1']]]]),
                                    SmsResult::SENT, null],
    'an unknown status code'    => [201, json_encode(['SMSMessageData' => ['Message' => 'x', 'Recipients' => [['statusCode' => 999, 'number' => $TO]]]]),
                                    SmsResult::RETRY, 'provider status 999'],
    'no recipient accepted'     => [201, json_encode(['SMSMessageData' => ['Message' => 'InvalidSenderId', 'Recipients' => []]]),
                                    SmsResult::FAILED, 'no recipient was accepted (provider said InvalidSenderId)'],
    'free text is not repeated' => [201, json_encode(['SMSMessageData' => ['Message' => "Sent to 0/1 {$TO} key={$KEY}", 'Recipients' => []]]),
                                    SmsResult::FAILED, 'no recipient was accepted'],
    'refused credentials'       => [401, 'The supplied authentication is invalid', SmsResult::FAILED, 'the provider refused the credentials (HTTP 401)'],
    'a bad request'             => [400, 'bad', SmsResult::FAILED, 'HTTP 400'],
    'a provider fault'          => [500, 'oops', SmsResult::RETRY, 'HTTP 500'],
    'not JSON'                  => [201, '<html>maintenance</html>', SmsResult::RETRY, 'malformed answer'],
    'a 200 is not a 201'        => [200, json_encode(['SMSMessageData' => ['Recipients' => [['statusCode' => 101, 'messageId' => 'ATXid_200']]]]),
                                    SmsResult::RETRY, 'unexpected HTTP 200'],
];
foreach ($cases as $what => [$st, $body, $outcome, $why]) {
    $scene($st, $body);
    $res = $at->send($TO, $TEXT);
    $said[] = (string) $res->error . ' ' . (string) $res->providerRef;
    is_([$res->outcome, $res->error], [$outcome, $why], "{$what}: {$outcome}" . ($why !== null ? " — {$why}" : ''));
}
$closed = $port + 401;
$probe = @fsockopen('127.0.0.1', $closed, $e1, $e2, 0.2);
if ($probe) { fclose($probe); }
is_($probe === false, true, "CONTROL: nothing listens on {$closed}");
$res = (new AfricasTalkingSms('dishnet', $KEY, null, "http://127.0.0.1:{$closed}/version1/messaging"))->send($TO, $TEXT);
$said[] = (string) $res->error;
is_([$res->outcome, $res->error], [SmsResult::RETRY, 'transport: could not connect to the provider'], 'nothing listening: retry');
$scene(201, null, 3);
$t0  = microtime(true);
$res = (new AfricasTalkingSms('dishnet', $KEY, null, $url, 1, 1))->send($TO, $TEXT);
$said[] = (string) $res->error;
is_([$res->outcome, $res->error, microtime(true) - $t0 < 2.5], [SmsResult::RETRY, 'transport: timed out', true],
    'a provider slower than the timeout: retry after the timeout, not after the provider');
$leaks = array_values(array_filter($said, static fn($s) => str_contains($s, $KEY) || str_contains($s, $TO)
                                                           || str_contains($s, '482913') || str_contains($s, 'Valid 10')));
is_([count($said) >= 13, $leaks], [true, []], 'no reason the adapter gave carries the key, the number, the code or the message');
usleep(2_000_000);   // let the slow request finish before the next scenario

// ===========================================================================
t('10. THE WHOLE CHAIN with the real adapter — request, worker, provider, sign-in');
$closePending();     // §6 deliberately left a retry queued; this section counts only its own
$reset($phoneB);
$scene(null);
$aw = new SmsWorker($worker, new AfricasTalkingSms('dishnet', $KEY, 'DishNet', $url));
$call('POST', '/api/v1/auth/request-code', ['phone' => $phoneB]);
$run = $aw->runOnce();
$got = $requests();
$c   = $codeIn((string) ($got[0]['fields']['message'] ?? ''));
is_([$run['sent'], count($got), $got[0]['fields']['to'] ?? null], [1, 1, $phoneB],
    'the worker sent one request to the provider, addressed to the owner\'s number');
$vr = $call('POST', '/api/v1/auth/verify', ['phone' => $phoneB, 'code' => (string) $c]);
is_([$vr->status, isset($vr->body['token'])], [200, true], 'and the code the provider received signs the owner in');
$r = $outbox($phoneB);
is_([$r['state'], $r['sealed'], str_starts_with((string) $r['provider_ref'], 'ATXid_fake_')], ['sent', null, true],
    'the row is sent, the envelope erased, the provider\'s id kept');
$scene(403, 'no');
$reset($phoneB);
$call('POST', '/api/v1/auth/request-code', ['phone' => $phoneB]);
$aw->runOnce();
$r = $outbox($phoneB);
is_([$r['state'], $r['last_error'], str_contains(json_encode($r), $KEY)], ['failed', 'the provider refused the credentials (HTTP 403)', false],
    'a refusal fails the row, and nothing stored anywhere in it is the key');

// ===========================================================================
t('11. THE WORKER PROCESS — starts, refuses and logs as said');
$runWorker = static function (array $env) use ($root): array {
    $pre = '';
    foreach (['DN_SMS', 'DNB_SMS_USERNAME', 'DNB_SMS_API_KEY', 'DNB_SMS_SENDER', 'DN_DELIVERY'] as $x) { $pre .= "unset {$x}; "; }
    foreach ($env as $x => $v) { $pre .= $x . '=' . escapeshellarg($v) . ' '; }
    $out = []; $rc = 0;
    exec("{$pre}php " . escapeshellarg($root . '/bin/worker.php') . ' --once 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
};
[$rc0, $o0] = $runWorker([]);
is_([$rc0, str_contains($o0, '"sms":"null"')], [0, true], 'DN_SMS unset: the worker starts, runs a pass, and says its SMS binding is null');
[$rc1, $o1] = $runWorker(['DN_SMS' => 'twilio']);
is_([$rc1 !== 0, str_contains($o1, "DN_SMS='twilio' is not an SMS binding")], [true, true], 'an unknown binding: it refuses to start');
[$rc2, $o2] = $runWorker(['DN_SMS' => 'africastalking', 'DNB_SMS_USERNAME' => 'user-canary-7', 'DNB_SMS_API_KEY' => '']);
is_([$rc2 !== 0, str_contains($o2, 'DNB_SMS_API_KEY'), str_contains($o2, 'user-canary-7')], [true, true, false],
    'the adapter without its key: it refuses to start, naming the variable and no value');

// ===========================================================================
t('12. DECLARED — the manifest and the environment template say all of this');
$m = json_decode((string) file_get_contents($root . '/plugin/plugin.json'), true);
foreach (['DN_SMS' => false, 'DNB_SMS_USERNAME' => false, 'DNB_SMS_API_KEY' => true, 'DNB_SMS_SENDER' => false] as $x => $secret) {
    is_([isset($m['config'][$x]), $m['config'][$x]['secret'] ?? null, $m['config'][$x]['required'] ?? null], [true, $secret, false],
        "{$x} is declared, " . ($secret ? 'SECRET' : 'not secret') . ', optional');
}
is_($m['config']['DNB_SECRET_KEY']['required'] ?? null, true, 'DNB_SECRET_KEY is now required: without it no code can be sealed, so none can be issued');
$tpl = (string) file_get_contents($root . '/plugin/.env.example');
is_([str_contains($tpl, '# DN_SMS='), preg_match('/^DNB_SMS_API_KEY=\S/m', $tpl), str_contains($tpl, '# DNB_SMS_API_KEY=')], [true, 0, true],
    'the template documents the SMS variables, and carries no key — not even a placeholder value');

// ===========================================================================
t('13. REPOSITORY STATE');
$files = array_map('basename', glob($root . '/migrations/*.sql')); sort($files);
is_(array_values(array_filter($files, static fn($f) => str_starts_with($f, '032'))), ['032_sign_in_codes_by_sms.sql'], 'exactly one 032 file');
$doc = (string) @file_get_contents($root . '/../docs/127-OPERATOR-SIGN-IN-END-TO-END.md');
is_(str_contains($doc, '## G. Phase 2'), true, 'docs/127 carries the phase-2 build record');

exit(t_summary());

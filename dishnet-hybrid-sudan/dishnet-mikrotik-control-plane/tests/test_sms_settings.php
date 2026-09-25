<?php
/**
 * SMS settings from the Admin panel — docs/128, migration 033.
 *
 * PROVED here, by execution:
 *   - the settings row belongs to dnb_def_auth and NO login role holds anything
 *     on it; each of the four functions is executable by its one role only;
 *     dnb_def_auth may write its audit row and still no login role may;
 *   - the API key rests only SEALED — under a key derived from DNB_SECRET_KEY
 *     with a label of its own, the account as associated data — and nothing
 *     the database holds, the audit log records or any route answers is the
 *     key, its envelope or its fingerprint;
 *   - the function's rules: set, replaced, kept, removed; a username change
 *     without its key refused; identical settings write NOTHING (RULE I-1);
 *   - the routes: Admin only, a body of exactly four fields, 501 without the
 *     real staff provider or the write connection;
 *   - the worker follows the panel WITHOUT A RESTART, a code typed on the
 *     operator app is sent with the key typed in the panel (through the real
 *     adapter against a loopback fake provider) and signs the owner in; a key
 *     that does not open is reported and sends nothing; the environment,
 *     where DN_SMS is set, wins and says so;
 *   - the doctor says what the worker will do; the panel page and its client.
 *
 * NOT PROVED, and not claimed: that the real provider accepts these requests
 * or delivers a message (docs/127 S-6); anything on staging or a phone.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin_identity_double.php';

use Dn\Admin\AdminReader;
use Dn\Admin\Capability;
use Dn\Admin\OnboardingAdmin;
use Dn\Admin\SettingsAdmin;
use Dn\Admin\SettingsRefused;
use Dn\Admin\StaffAdmin;
use Dn\Admin\StaffIdentity;
use Dn\Admin\StaffRole;
use Dn\Api\AdminRoutes;
use Dn\Api\Routes;
use Dn\Auth\Authenticator;
use Dn\Crypto\SecretBox;
use Dn\Db\Database;
use Dn\Http\Kernel;
use Dn\Http\Request;
use Dn\Http\Router;
use Dn\Jobs\SmsWorker;
use Dn\Notify\AfricasTalkingSms;
use Dn\Notify\CodeEnvelope;
use Dn\Notify\EnvironmentSms;
use Dn\Notify\PanelSms;
use Dn\Notify\SmsSenders;
use Dn\Notify\SmsSettings;
use Dn\Plugin\Doctor;
use Dn\Plugin\Manifest;
use Dn\Runtime\Bindings;
use Dn\Tenancy\TenantContext;

$root = dirname(__DIR__);
$ins  = Database::inspector();     // BYPASSRLS fixture identity: reads state, proves nothing by itself
$aw   = Database::adminWrite();
$app  = Database::app();
$wk   = Database::worker();
$read = new AdminReader(Database::adminApi());
$crypto   = new SmsSettings();
$settings = SettingsAdmin::on($aw, $crypto);
$staffAdm = StaffAdmin::on($aw);

$KEY  = 'atsk_' . bin2hex(random_bytes(20));     // an invented key: it must appear nowhere it is not typed
$KEY2 = 'atsk_' . bin2hex(random_bytes(20));
$FNS  = ['mt_sms_settings_set(text,text,text,text,text,text)' => 'dnb_adminwrite',
         'mt_admin_sms_settings()'                            => 'dnb_adminapi',
         'mt_sms_settings_for_worker()'                       => 'dnb_worker',
         'mt_sms_worker_report(bigint,text,text)'             => 'dnb_worker'];

$row    = static fn(): array => $ins->one('SELECT * FROM mt_sms_settings WHERE id = 1');
$audits = static fn(): int => (int) $ins->one("SELECT count(*)::int AS c FROM mt_audit_log WHERE action = 'sms.settings_changed'")['c'];
$lastAudit = static fn(): ?array => $ins->one("SELECT * FROM mt_audit_log WHERE action = 'sms.settings_changed' ORDER BY at DESC, id DESC LIMIT 1");
$hit = static function (Router $r, string $m, string $p, array $body = []) {
    $mm = $r->match($m, $p);
    if ($mm === null) { bad("no route {$m} {$p}"); return new \Dn\Http\Response(404); }
    return ($mm[0])(new Request($m, $p, [], $body, $mm[1], '127.0.0.1'));
};
$as = static fn(StaffRole $role, ?StaffAdmin $s = null, ?SettingsAdmin $set = null) => AdminRoutes::build(
    new FixedStaff(new StaffIdentity($role->value . '-user', $role, 'test')), Bindings::defaults(), $read,
    null, $s, null, null, null, $set);
$admin = $as(StaffRole::Admin, $staffAdm, $settings);
$P = '/api/v1/admin/settings/sms';

// The settings are one deployment-wide row: leave them as found — off.
register_shutdown_function(static function () use ($aw) {
    try { $aw->one("SELECT mt_sms_settings_set('none', NULL, NULL, NULL, NULL, 'test:cleanup') AS r"); } catch (\Throwable) {}
});

// ===========================================================================
t('1. THE MIGRATION — owner, grants and the starting row, from the catalogue');
is_($ins->one("SELECT pg_get_userbyid(relowner) AS o FROM pg_class WHERE oid = 'mt_sms_settings'::regclass")['o'], 'dnb_def_auth',
    'mt_sms_settings belongs to dnb_def_auth');
is_((int) $ins->one("SELECT count(*)::int AS c FROM pg_class t, aclexplode(coalesce(t.relacl, acldefault('r', t.relowner))) a
                      WHERE t.oid = 'mt_sms_settings'::regclass AND a.grantee <> t.relowner")['c'], 0,
    'no role but its owner holds any privilege on it — by grant');
foreach ($FNS as $f => $who) {
    $x = $ins->one("SELECT pg_get_userbyid(proowner) AS o, prosecdef AS d,
                           (SELECT string_agg(CASE WHEN a.grantee = 0 THEN 'PUBLIC' ELSE pg_get_userbyid(a.grantee) END, ',' ORDER BY 1)
                              FROM aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
                             WHERE a.privilege_type = 'EXECUTE' AND a.grantee <> p.proowner) AS g
                      FROM pg_proc p WHERE p.oid = ?::regprocedure", [$f]);
    is_([$x['o'], $x['d'], $x['g']], ['dnb_def_auth', true, $who], "{$f}: SECURITY DEFINER, dnb_def_auth's, EXECUTE {$who} only");
}
$aud = $ins->one("SELECT string_agg(pg_get_userbyid(a.grantee), ',' ORDER BY 1) AS g FROM pg_proc p,
                         aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
                   WHERE p.oid = 'mt_audit_write(uuid,text,text,text,text,text,text,jsonb)'::regprocedure
                     AND a.privilege_type = 'EXECUTE' AND a.grantee <> p.proowner")['g'];
is_(in_array('dnb_def_auth', explode(',', (string) $aud), true), true, 'dnb_def_auth may write the audit row of the act it now owns');
is_((int) $ins->one("SELECT count(*)::int AS c FROM pg_roles r WHERE r.rolcanlogin AND NOT r.rolsuper
                      AND has_function_privilege(r.oid, 'mt_audit_write(uuid,text,text,text,text,text,text,jsonb)', 'EXECUTE')")['c'], 0,
    'and still no login role may');
is_(has_schema_create($ins, 'dnb_def_auth'), false, 'dnb_def_auth kept no CREATE on the schema');
$r0 = $row();
is_([$r0['provider'], $r0['username'], $r0['key_sealed'], $r0['key_fp']], ['none', null, null, null], 'the row starts off: no provider, no username, no key');
$shape = $ins->one("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'mt_sms_settings_shape'")['d'] ?? '';
is_(str_contains($shape, 'key_sealed IS NULL') && str_contains($shape, 'key_sealed IS NOT NULL'), true,
    'a CHECK makes "none" hold nothing and "africastalking" hold a username and a sealed key');

function has_schema_create(Database $db, string $role): bool
{
    return (bool) $db->one("SELECT has_schema_privilege(?, 'public', 'CREATE') AS p", [$role])['p'];
}

// ===========================================================================
t('2. BY EXECUTION — every login role, enumerated, refused all but its own function');
$logins = array_column($ins->query("SELECT rolname FROM pg_roles WHERE rolcanlogin AND NOT rolsuper ORDER BY 1"), 'rolname');
is_(count($logins) >= 8, true, 'CONTROL: the cluster has the project\'s login roles to test (' . count($logins) . ')');
$calls = ['mt_sms_settings_set(text,text,text,text,text,text)' => "SELECT mt_sms_settings_set('none', NULL, NULL, NULL, NULL, 'probe')",
          'mt_admin_sms_settings()'                            => 'SELECT * FROM mt_admin_sms_settings()',
          'mt_sms_settings_for_worker()'                       => 'SELECT * FROM mt_sms_settings_for_worker()',
          'mt_sms_worker_report(bigint,text,text)'             => "SELECT mt_sms_worker_report(NULL, 'off', 'probe')"];
$allowed = []; $refusedAll = true; $tableRefused = 0;
foreach ($logins as $role) {
    $ins->pdo()->beginTransaction();
    try {
        $ins->exec('SET LOCAL ROLE ' . $role);
        try { $ins->attempt(static fn(Database $d) => $d->query('SELECT * FROM mt_sms_settings')); }
        catch (\PDOException $e) { if (str_contains($e->getMessage(), 'permission denied for table mt_sms_settings')) { $tableRefused++; } }
        foreach ($calls as $f => $sql) {
            try {
                $ins->attempt(static fn(Database $d) => $d->query($sql));
                $allowed[] = $f . ':' . $role;
                if ($FNS[$f] !== $role) { $refusedAll = false; }
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), 'permission denied for function')) { $refusedAll = false; }
            }
        }
    } finally { $ins->pdo()->rollBack(); }
}
sort($allowed);
is_($tableRefused, count($logins), 'every login role, the installing owner included, is refused the table');
is_($allowed, ['mt_admin_sms_settings():dnb_adminapi', 'mt_sms_settings_for_worker():dnb_worker',
               'mt_sms_settings_set(text,text,text,text,text,text):dnb_adminwrite', 'mt_sms_worker_report(bigint,text,text):dnb_worker'],
    'each function ran for its own role — the positive controls — and for no other');
is_($refusedAll, true, 'and every other call was refused as a privilege, not failed for another reason');
is_(($row())['version'], $r0['version'], 'the rolled-back probes left the row as it was');

// ===========================================================================
t('3. SEALING — its own derived key, the account as associated data');
$sealed = $crypto->seal($KEY, 'dishnet');
// An envelope that does not open is a counted failure here, never a crash that
// ends the suite (a weakened copy that stored the key unsealed died that way).
$opens = static function (string $env, string $user) use ($crypto): string {
    try { return $crypto->open($env, $user); } catch (\Throwable) { return 'DID NOT OPEN'; }
};
is_($opens($sealed, 'dishnet'), $KEY, 'an envelope opens for the account it was sealed for');
$threw = static function (callable $f): bool { try { $f(); return false; } catch (\Throwable) { return true; } };
is_($threw(static fn() => $crypto->open($sealed, 'other-account')), true, 'and not for another username');
is_($threw(static fn() => (new SecretBox(getenv('DNB_SECRET_KEY')))->open($sealed, SmsSettings::associatedData('dishnet'))), true,
    'the raw DNB_SECRET_KEY does not open it: the key is derived');
is_($threw(static fn() => (new SmsSettings('another-master-key'))->open($sealed, 'dishnet')), true, 'another DNB_SECRET_KEY does not open it');
$code = (new CodeEnvelope())->seal('482913', '+256700000001');
is_($threw(static fn() => $crypto->open($code, '+256700000001')), true, 'an outbox envelope does not open as a settings envelope');
is_($threw(static fn() => (new CodeEnvelope())->open($sealed, 'dishnet')), true, 'nor a settings envelope as an outbox one');
is_([$crypto->fingerprint($KEY) === $crypto->fingerprint($KEY), $crypto->fingerprint($KEY) === $crypto->fingerprint($KEY2),
     $crypto->fingerprint($KEY) === hash('sha256', $KEY), (bool) preg_match('/^[0-9a-f]{64}$/', $crypto->fingerprint($KEY))],
    [true, false, false, true], 'the fingerprint is stable, differs per key, is keyed (not a plain hash) and is 64 hex digits');
is_([SmsSettings::validUsername('dishnet'), SmsSettings::validUsername('bad user'), SmsSettings::validApiKey($KEY),
     SmsSettings::validApiKey('short'), SmsSettings::validSender('DishNet'), SmsSettings::validSender('a sender too long')],
    [true, false, true, false, true, false], 'one validation rule, the staging command\'s and the adapter\'s');

// ===========================================================================
t('4. THE WRITE — the function\'s rules, through the façade on dnb_adminwrite');
$a0 = $audits();
$s1 = $settings->saveSms('africastalking', 'dishnet', $KEY, 'DishNet', 'admin-user');
is_([$s1['changed'], $s1['key']], [true, 'set'], 'a first key: changed, key set');
is_($audits() - $a0, 1, 'exactly one audit row');
$la = $lastAudit();
is_([$la['actor_kind'], $la['actor'], $la['customer_id'], $la['target_type'], $la['source']], ['staff', 'admin-user', null, 'sms_settings', 'admin'],
    'the row: actor_kind staff, the actor passed in, no operator, the settings as target');
$rw = $row();
$rowText = json_encode($rw);
is_([$rw['provider'], $rw['username'], $rw['sender'], str_starts_with((string) $rw['key_sealed'], 'v1.'), $rw['key_fp'] === $crypto->fingerprint($KEY)],
    ['africastalking', 'dishnet', 'DishNet', true, true], 'the row holds the account, the sender, an envelope and the fingerprint');
is_(str_contains($rowText, $KEY), false, 'THE KEY is nowhere in the row, in any column');
is_($opens((string) $rw['key_sealed'], 'dishnet'), $KEY, 'and the envelope opens to it — the worker\'s view');
$auditText = json_encode($la);
is_([str_contains($auditText, $KEY), str_contains($auditText, 'v1.'), str_contains($auditText, (string) $rw['key_fp'])], [false, false, false],
    'the audit row carries neither the key, nor an envelope, nor the fingerprint');
is_(json_decode((string) $la['detail'], true)['key'] ?? null, 'set', 'and says what happened to the key');

$v1 = (int) $rw['version']; $a1 = $audits();
$s2 = $settings->saveSms('africastalking', 'dishnet', $KEY, 'DishNet', 'admin-user');
is_([$s2['changed'], $s2['key'], $audits() - $a1, (int) $row()['version']], [false, 'kept', 0, $v1],
    'RULE I-1: the same key typed again — no change, NO audit row, the version unchanged');
$s3 = $settings->saveSms('africastalking', 'dishnet', null, 'DishNet', 'admin-user');
is_([$s3['changed'], $audits() - $a1], [false, 0], 'no key with the same settings: nothing written');
$s4 = $settings->saveSms('africastalking', 'dishnet', null, null, 'admin-user');
is_([$s4['changed'], $s4['key'], $audits() - $a1, $row()['sender']], [true, 'kept', 1, null], 'the sender cleared: one change, the key kept');
$refusal = static function (callable $f): string { try { $f(); return 'no refusal'; } catch (SettingsRefused $e) { return $e->getMessage(); } };
$a2 = $audits();
is_($refusal(static fn() => $settings->saveSms('africastalking', 'other-account', null, null, 'admin-user')),
    'a new username needs its API key typed again', 'a new username without its key: refused, in the function\'s words');
is_($audits(), $a2, 'and nothing was written');
$s5 = $settings->saveSms('africastalking', 'dishnet', $KEY2, 'DishNet', 'admin-user');
is_([$s5['changed'], $s5['key']], [true, 'replaced'], 'a different key: replaced');
$s6 = $settings->saveSms('africastalking', 'other-account', $KEY, null, 'admin-user');
is_([$s6['changed'], $s6['key'], $opens((string) $row()['key_sealed'], 'other-account')], [true, 'replaced', $KEY],
    'a new username WITH its key: accepted, sealed for the new account');
$s7 = $settings->saveSms('none', null, null, null, 'admin-user');
is_([$s7['changed'], $s7['key'], $row()['provider'], $row()['key_sealed'], $row()['key_fp']], [true, 'removed', 'none', null, null],
    'off: the key removed with everything else');
$a3 = $audits();
$s8 = $settings->saveSms('none', null, null, null, 'admin-user');
is_([$s8['changed'], $audits() - $a3], [false, 0], 'off again: no change, no audit row');
is_($threw(static fn() => $settings->saveSms('none', null, null, null, '  ')), true, 'a blank actor is refused before anything is sent');
is_($refusal(static fn() => $settings->saveSms('africastalking', 'dishnet', null, null, 'admin-user')), 'an API key is required',
    'turning it on without a key: refused');
// A driver error never carries the row into an exception: PostgreSQL's DETAIL
// line quotes the failing row. Forced with a constraint that exists only inside
// a rolled-back transaction on the fixture connection.
$ins->pdo()->beginTransaction();
try {
    $ins->exec("ALTER TABLE mt_sms_settings ADD CONSTRAINT probe_detail CHECK (username IS DISTINCT FROM 'probe-detail')");
    $msg = '';
    try { SettingsAdmin::on($ins, $crypto)->saveSms('africastalking', 'probe-detail', $KEY, null, 'admin-user'); }
    catch (\Throwable $e) { $msg = $e->getMessage(); }
    is_([str_contains($msg, 'SQLSTATE 23514'), str_contains($msg, 'v1.'), str_contains($msg, $KEY), str_contains($msg, 'Failing row')],
        [true, false, false, false], 'a driver error is reduced to its SQLSTATE: no envelope, no key, no quoted row');
} finally { $ins->pdo()->rollBack(); }

// ===========================================================================
t('5. THE ROUTES — Admin only, four fields, the key never answered');
foreach ([StaffRole::Noc, StaffRole::Sales, StaffRole::Support] as $role) {
    $rr = $as($role, $staffAdm, $settings);
    $g = $hit($rr, 'GET', $P); $p = $hit($rr, 'POST', $P, ['provider' => 'none']);
    is_([$g->status, $g->body['capability'] ?? null, $p->status, $p->body['capability'] ?? null],
        [403, 'sms.manage', 403, 'sms.manage'], "{$role->value}: 403 naming sms.manage, read and write");
    is_($role->can(Capability::SMS_MANAGE), false, "{$role->value} does not hold sms.manage");
}
is_(StaffRole::Admin->can(Capability::SMS_MANAGE), true, 'Admin holds it — the positive control');
$dev = $as(StaffRole::Admin, null, $settings);
is_([$hit($dev, 'GET', $P)->status, $hit($dev, 'POST', $P, ['provider' => 'none'])->body['error'] ?? null],
    [501, 'sms_settings_unavailable'], 'without the real staff provider (the development identity): 501, both ways');
$nowrite = $as(StaffRole::Admin, $staffAdm, null);
is_($hit($nowrite, 'POST', $P, ['provider' => 'none'])->status, 501, 'without the Admin write connection: 501');

$g0 = $hit($admin, 'GET', $P);
is_([$g0->status, $g0->body['sms']['provider'] ?? null, $g0->body['sms']['key_set'] ?? null], [200, 'none', false], 'GET: 200, off, no key set');
is_(array_keys($g0->body), ['sms', 'worker', 'delivery'], 'three parts: the settings, the worker\'s report, the outbox\'s record');
$w = $hit($admin, 'POST', $P, ['provider' => 'africastalking', 'username' => 'dishnet', 'api_key' => $KEY, 'sender' => 'DishNet']);
is_([$w->status, $w->body], [200, ['changed' => true, 'version' => (int) $row()['version'], 'key' => 'set']],
    'POST: 200 with what happened — changed, the version, the key "set" — and nothing else');
$la = $lastAudit();
is_([$la['actor'], $la['actor_kind']], ['admin-user', 'staff'], 'the actor is the authenticated subject');
$g1 = $hit($admin, 'GET', $P);
$gText = json_encode($g1->body);
is_([$g1->body['sms']['provider'], $g1->body['sms']['username'], $g1->body['sms']['sender'], $g1->body['sms']['key_set'], $g1->body['sms']['updated_by']],
    ['africastalking', 'dishnet', 'DishNet', true, 'admin-user'], 'GET afterwards: the account, the sender, a key set, and who set it');
is_([str_contains($gText, $KEY), str_contains($gText, 'v1.'), str_contains($gText, (string) $row()['key_fp']), array_key_exists('key_sealed', $g1->body['sms'])],
    [false, false, false, false], 'and NOTHING of the key: not the key, not an envelope, not the fingerprint, no such field');
foreach (['actor', 'updated_by', 'key_sealed', 'key_fp', 'version', 'id', 'provider_ref'] as $f) {
    $res = $hit($admin, 'POST', $P, ['provider' => 'africastalking', 'username' => 'dishnet', 'api_key' => $KEY, $f => 'x']);
    is_([$res->status, str_contains(json_encode($res->body), $f), str_contains(json_encode($res->body), $KEY)], [400, true, false],
        "carrying {$f}: 400 naming it, refused rather than ignored — and the key not echoed");
}
$bad = [[['provider' => 'twilio'], 'provider'], [['provider' => 'africastalking', 'username' => 'bad user', 'api_key' => $KEY], 'username'],
        [['provider' => 'africastalking', 'username' => 'dishnet', 'api_key' => 'short'], 'api_key'],
        [['provider' => 'africastalking', 'username' => 'dishnet', 'api_key' => $KEY, 'sender' => 'a sender far too long'], 'sender'],
        [['provider' => 'none', 'username' => 'dishnet'], 'turning SMS off'], [['provider' => 'africastalking', 'username' => ['x']], 'username'],
        [[], 'provider']];
foreach ($bad as [$body, $word]) {
    $res = $hit($admin, 'POST', $P, $body);
    is_([$res->status, str_contains(json_encode($res->body), $word)], [400, true], "a body with bad {$word}: 400, saying which");
}
$badKey = 'bad key ' . bin2hex(random_bytes(8));
$res = $hit($admin, 'POST', $P, ['provider' => 'africastalking', 'username' => 'dishnet', 'api_key' => $badKey]);
is_([$res->status, str_contains(json_encode($res->body), $badKey)], [400, false], 'a key that fails the rule: 400, and the value is not echoed');
$res = $hit($admin, 'POST', $P, ['provider' => 'africastalking', 'username' => 'renamed', 'api_key' => '']);
is_([$res->status, $res->body['detail'] ?? null], [409, 'a new username needs its API key typed again'],
    'a new username with an empty key: 409 with the function\'s own reason');
$res = $hit($admin, 'POST', $P, ['provider' => 'africastalking', 'username' => 'dishnet', 'api_key' => $KEY, 'sender' => 'DishNet']);
is_([$res->status, $res->body['changed'], $res->body['key']], [200, false, 'kept'], 'a double click: 200, nothing changed');

// ===========================================================================
t('6. THE WORKER FOLLOWS THE PANEL — no restart; the typed key sends the code; a code signs the owner in');
$fdir = sys_get_temp_dir() . '/dnb-fake-sms-' . bin2hex(random_bytes(4));
mkdir($fdir, 0700);
$port = 59800 + (getmypid() % 150);
$fpid = (int) shell_exec(sprintf('FAKE_SMS_DIR=%s PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:%d %s > %s 2>&1 & echo $!',
    escapeshellarg($fdir), $port, escapeshellarg(__DIR__ . '/fake_sms_provider.php'), escapeshellarg($fdir . '/server.log')));
register_shutdown_function(static function () use ($fpid, $fdir) {
    if ($fpid > 0) { @shell_exec("kill {$fpid} 2>/dev/null"); }
    foreach (glob($fdir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($fdir);
});
$up = false;
for ($i = 0; $i < 100; $i++) { $sk = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($sk) { fclose($sk); $up = true; break; } usleep(50_000); }
is_($up, true, "the fake provider is listening on {$port}");
$requests = static fn() => array_values(array_filter(array_map(static fn($l) => json_decode($l, true),
    file(is_file($fdir . '/requests.jsonl') ? $fdir . '/requests.jsonl' : '/dev/null') ?: [])));
$built = [];
$build = static function (string $user, string $key, ?string $sender) use ($port, &$built) {
    $built[] = $user;
    return new AfricasTalkingSms($user, $key, $sender, "http://127.0.0.1:{$port}/version1/messaging");
};
$panel = new PanelSms($wk, $crypto, $build, 0);
$texts = new SmsWorker($wk, $panel);

// An operator and its owner, for this suite alone.
$on   = OnboardingAdmin::on($aw);
$op   = $on->createOperator('SMS Settings Hotel', 'sms33-' . bin2hex(random_bytes(4)), 'test:sms33')['customer'];
$ownerPhone = '+2567' . random_int(10000000, 99999999);
$on->addPrincipal($op['id'], 'owner', 'Settings Owner', $ownerPhone, [], 'test:sms33');
$auth = new Authenticator($app);
$k    = new Kernel(Routes::build($auth), $app, $auth, new TenantContext($app));
$call = static fn(string $m, string $p, array $body = []) => $k->handle(new Request($m, $p, [], $body, [], '10.0.0.33'));

$hit($admin, 'POST', $P, ['provider' => 'none']);
is_([$panel->isConfigured(), $panel->status()['state']], [false, 'off'], 'the panel off: the worker sends nothing, and knows why');
$wr = $row();
is_([$wr['worker_state'], (int) $wr['worker_version'], $wr['worker_seen_at'] !== null], ['off', (int) $wr['version'], true],
    'and it REPORTED that: off, at the settings\' version, just now');
$call('POST', '/api/v1/auth/request-code', ['phone' => $ownerPhone]);
$r1 = $texts->runOnce();
is_([$r1['claimed'], $requests()], [0, []], 'a code requested while off: nothing claimed, nothing sent');

$hit($admin, 'POST', $P, ['provider' => 'africastalking', 'username' => 'dishnet', 'api_key' => $KEY, 'sender' => 'DishNet']);
$r2 = $texts->runOnce();
$rq = $requests();
is_([$r2['claimed'], $r2['sent'], count($rq)], [1, 1, 1], 'saved in the panel, the SAME worker object sends the waiting code on its next tick — no restart');
is_([$rq[0]['apikey'] ?? null, $rq[0]['fields']['username'] ?? null, $rq[0]['fields']['from'] ?? null, $rq[0]['fields']['to'] ?? null],
    [$KEY, 'dishnet', 'DishNet', $ownerPhone], 'with the key typed in the panel, its account and sender, to the owner\'s number');
preg_match('/\b(\d{6})\b/', (string) ($rq[0]['fields']['message'] ?? ''), $mm);
$vr = $call('POST', '/api/v1/auth/verify', ['phone' => $ownerPhone, 'code' => $mm[1] ?? '']);
is_([$vr->status, isset($vr->body['token'])], [200, true], 'and the code the provider received signs the owner in');
is_([$panel->status()['state'], $row()['worker_state'], (int) $row()['worker_version'], (int) $row()['version']],
    ['in_use', 'in_use', (int) $row()['version'], (int) $row()['version']], 'the worker reports in use, at the version it applied');

@unlink($fdir . '/requests.jsonl');
$hit($admin, 'POST', $P, ['provider' => 'africastalking', 'username' => 'dishnet', 'api_key' => $KEY2, 'sender' => 'DishNet']);
$call('POST', '/api/v1/auth/request-code', ['phone' => $ownerPhone]);
$texts->runOnce();
is_(($requests()[0]['apikey'] ?? null), $KEY2, 'a new key saved in the panel is the one the next message uses');
$nBuilt = count($built);
$texts->runOnce(); $texts->runOnce();
is_(count($built), $nBuilt, 'and the sender is rebuilt only when the settings change, not every tick');

// A key that does not open — sealed under another DNB_SECRET_KEY, written by the fixture identity.
$other = new SmsSettings('another-master-key');
$ins->exec("UPDATE mt_sms_settings SET key_sealed = ?, version = version + 1 WHERE id = 1", [$other->seal($KEY, 'dishnet')]);
@unlink($fdir . '/requests.jsonl');
$call('POST', '/api/v1/auth/request-code', ['phone' => $ownerPhone]);
$r3 = $texts->runOnce();
$st = $panel->status();
is_([$r3['claimed'], $requests(), $st['state']], [0, [], 'unusable'], 'a key that does not open: nothing claimed, nothing sent, the worker keeps running');
is_([$row()['worker_state'], str_contains((string) $row()['worker_detail'], 'does not open'), str_contains((string) $row()['worker_detail'], $KEY)],
    ['unusable', true, false], 'and REPORTS it, with a fixed reason that carries no key');
$g2 = $hit($admin, 'GET', $P);
is_([$g2->body['worker']['state'], $g2->body['worker']['recent']], ['unusable', true], 'the page reads the same report');

// Heartbeat: with nothing changed the report is refreshed, not rewritten as new.
$seen = $row()['worker_seen_at'];
usleep(20_000);
$panel->isConfigured();
is_($row()['worker_seen_at'] !== $seen, true, 'with nothing changed the worker still reports (its heartbeat)');

$hit($admin, 'POST', $P, ['provider' => 'none']);
is_([$panel->isConfigured(), $row()['worker_state']], [false, 'off'], 'turned off in the panel: off at the next tick');
$ins->exec("UPDATE mt_auth_sms_outbox o SET state = 'expired', sealed = NULL, lease_until = NULL, settled_at = now()
             FROM mt_auth_codes c WHERE c.id = o.code_id AND c.phone = ? AND o.state IN ('queued','sending')", [$ownerPhone]);

// ===========================================================================
t('7. THE ENVIRONMENT WINS WHERE DN_SMS IS SET — and says so');
$saved = getenv('DN_SMS');
putenv('DN_SMS');
is_([SmsSenders::isSetInEnvironment(), SmsSenders::forWorker($wk) instanceof PanelSms], [false, true], 'DN_SMS unset: the worker follows the panel');
putenv('DN_SMS=null');
$hit($admin, 'POST', $P, ['provider' => 'africastalking', 'username' => 'dishnet', 'api_key' => $KEY]);
$env = SmsSenders::forWorker($wk);
is_([$env instanceof EnvironmentSms, $env->bindingName(), $env->isConfigured()], [true, 'null', false],
    'DN_SMS=null: the environment decides — nothing is sent though the panel says africastalking');
is_([$row()['worker_state'], str_contains((string) $row()['worker_detail'], 'DN_SMS=null')], ['environment', true],
    'and the worker reports the environment, so the page can say the form is ignored');
putenv('DN_SMS=twilio');
is_($threw(static fn() => SmsSenders::forWorker($wk)), true, 'an unknown DN_SMS still refuses to start — no fallback to the panel');
$saved === false ? putenv('DN_SMS') : putenv('DN_SMS=' . $saved);
$hit($admin, 'POST', $P, ['provider' => 'none']);

$out = []; $rc = 0;
exec('unset DN_SMS DNB_SMS_USERNAME DNB_SMS_API_KEY DNB_SMS_SENDER DN_DELIVERY; php ' . escapeshellarg($root . '/bin/worker.php') . ' --once 2>&1', $out, $rc);
$o = implode("\n", $out);
is_([$rc, str_contains($o, '"sms":"panel"'), (bool) preg_match('/"sms_settings":\{"version":\d+,"state":"off","binding":"null"/', $o), str_contains($o, $KEY)],
    [0, true, true, false], 'the worker process: follows the panel, logs what it applied (off), and no key');

// ===========================================================================
t('8. THE DOCTOR — says what the worker will do, and opens nothing');
$man = Manifest::load($root . '/plugin/plugin.json');
$docRow = static function () use ($man, $root): array {
    foreach ((new Doctor($man, $root, true))->run() as $r) { if ($r['id'] === 'sms.sender') { return $r; } }
    return [];
};
putenv('DN_SMS');
$d0 = $docRow();
is_([$d0['state'] ?? null, str_contains($d0['detail'] ?? '', 'Admin panel')], [Doctor::WARN, true], 'unset and nothing in the panel: WARN, pointing at the panel');
$hit($admin, 'POST', $P, ['provider' => 'africastalking', 'username' => 'dishnet', 'api_key' => $KEY]);
$d1 = $docRow();
is_([$d1['state'] ?? null, str_contains($d1['detail'] ?? '', 'set in the Admin panel'), str_contains($d1['detail'] ?? '', 'value withheld'),
     str_contains(json_encode($d1), $KEY)], [Doctor::OK, true, true, false], 'set in the panel: OK, the key withheld and not in the report');
$hit($admin, 'POST', $P, ['provider' => 'africastalking', 'username' => 'sandbox', 'api_key' => $KEY]);
is_(str_contains($docRow()['detail'] ?? '', 'SANDBOX'), true, 'the sandbox account says so');
putenv('DN_SMS=null');
$dn = $docRow();
is_([$dn['state'] ?? null, str_contains($dn['detail'] ?? '', 'DN_SMS=null')], [Doctor::WARN, true], 'DN_SMS=null: WARN, whatever the panel says');
$saved === false ? putenv('DN_SMS') : putenv('DN_SMS=' . $saved);
$hit($admin, 'POST', $P, ['provider' => 'none']);

// ===========================================================================
t('9. THE ADMIN READ — the sixteenth function the reader may call, and what it cannot return');
$cols = array_column($ins->query("SELECT a.attname FROM pg_proc p, unnest(p.proargnames) WITH ORDINALITY a(attname, n)
                                   WHERE p.oid = 'mt_admin_sms_settings()'::regprocedure ORDER BY n"), 'attname');
is_($cols, ['provider', 'username', 'sender', 'key_set', 'version', 'updated_at', 'updated_by', 'worker_state', 'worker_version',
            'worker_detail', 'worker_seen_at', 'worker_recent', 'sent_24h', 'failed_24h', 'expired_24h', 'no_recipient_24h',
            'pending_now', 'last_sent_at', 'last_failure_at', 'last_failure'],
    'its columns, exactly: whether a key is set — never the key, the envelope or the fingerprint; no phone, no code');
$x = $read('mt_admin_sms_settings')[0] ?? [];
is_([(int) $x['sent_24h'] >= 2, $x['last_sent_at'] !== null], [true, true], 'the outbox\'s record shows the messages this suite sent');
is_(str_contains(json_encode($x), $ownerPhone), false, 'and no phone number');
$allow = (new ReflectionClassConstant(AdminReader::class, 'ALLOWED'))->getValue();
is_([array_key_exists('mt_admin_sms_settings', $allow), count($allow)], [true, 16], 'it is the sixteenth entry of the reader\'s allow-list');

// ===========================================================================
t('10. THE PANEL — its own client, a password field never filled in');
$appJs = file_get_contents($root . '/panel/app.js');
$setJs = file_get_contents($root . '/panel/settings.js');
$strip = static fn(string $s) => preg_replace(['#/\*.*?\*/#s', '#(?<![:\'"])//[^\n]*#'], '', $s);
preg_match_all("#send\('(GET|POST)', '([^']+)'#", $setJs, $sm, PREG_SET_ORDER);
is_(array_map(static fn($m) => $m[1] . ' ' . $m[2], $sm), ['GET /settings/sms', 'POST /settings/sms'], 'settings.js reaches exactly one path: one read, one write');
is_(substr_count($strip($setJs), 'fetch('), 0, 'and opens no fetch of its own (it uses staff.js\'s one call site)');
preg_match_all('/\bsmsApi\.(\w+)\s*\(/', $strip($appJs), $uc);
$used = array_values(array_unique($uc[1])); sort($used);
is_($used, ['read', 'save'], 'app.js calls smsApi.read and smsApi.save and nothing else');
is_(preg_match('/<input name="api_key" type="password" autocomplete="new-password"/', $appJs), 1, 'the key field is a password field, never autofilled');
is_(preg_match('/name="api_key"[^>]*\bvalue=/', $appJs), 0, 'and is never given a value');
is_(str_contains($appJs, "{ id: 'sms',"), true, 'the Administration navigation has the SMS page');
is_(str_contains($appJs, 'never shown again'), true, 'the page says the key is never shown again');
is_(str_contains($strip($appJs), 'console.'), false, 'app.js logs nothing to the console');

// ===========================================================================
t('11. THE MANIFEST, THE CAPABILITY, THE RECORD');
$m = Manifest::load($root . '/plugin/plugin.json');
is_(array_map(static fn($r) => $r['method'] . ' ' . $r['path'] . ' ' . ($r['function'] ?? '') . ' ' . ($r['role'] ?? ''), $m->settingsRoutes),
    ['GET /settings/sms mt_admin_sms_settings dnb_adminapi', 'POST /settings/sms mt_sms_settings_set dnb_adminwrite'],
    'the manifest declares both routes with their function and role');
is_(str_contains($m->config['DN_SMS']['description'] ?? '', 'Admin panel'), true, 'and says what an unset DN_SMS now means');
is_([in_array(Capability::SMS_MANAGE, Capability::ALL, true), in_array(Capability::SMS_MANAGE, AdminRoutes::declaredCapabilities(), true)],
    [true, true], 'sms.manage is a declared capability the surface gates on');
$doc = file_get_contents($root . '/../docs/128-SMS-SETTINGS-FROM-THE-ADMIN-PANEL.md');
foreach (['## A. What exists', '## B. Decisions', '## C. Evidence', '## D. Order', '## E. What the operator will do'] as $h) {
    is_(str_contains($doc, $h), true, "docs/128 has '{$h}'");
}

exit(t_summary());

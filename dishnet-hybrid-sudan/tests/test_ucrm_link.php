<?php
declare(strict_types=1);
/**
 * test_ucrm_link.php — 5.18.50 (docs/44 J2, J3, D6, D9, M5, M7; release A): a staff member's uCRM user is chosen from
 * uCRM's own users and checked with uCRM when it is saved, and only such a link counts; a job-taking account's number
 * is saved in the international form.
 *
 * Proved through the real Staff page, its Edit form, the API and a fake uCRM, on a sandboxed Uganda plugin:
 *   1. set_ucrm_user_id: an administrator's; links only an active uCRM user with the account's own e-mail (ignoring
 *      case), not held by another active account, on a role that takes jobs; each refusal changes nothing; a uCRM that
 *      does not answer changes nothing; "not linked" clears (M5);
 *   2. the Edit form: the same rules at save, the other fields saving beside a refusal; an unchanged verified link is
 *      not re-asked; an old id left as it was is kept, still unverified, and the page says so; a changed e-mail breaks
 *      the link; a form without the field leaves it alone;
 *   3. the Staff page: a picker, not a number; badges for verified, not verified, not a uCRM user and not linked; the
 *      organisation-7 tile, filter and badges gone (D6); the number warnings (J3);
 *   4. the engineer lists offer only verified accounts, never a phone number, to those who may create a job (D7);
 *   5. J3's table through the real save handlers — edit, create and import — for job-taking roles only;
 *   6. weakened copies of the code each fail this test.
 *
 *   php test_ucrm_link.php [--root=DIR] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

$s = SjSandbox::start($root, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'], 'sjlink');
$users = [
    '1000' => ['id' => 1000, 'username' => 'sb-admin', 'firstName' => '', 'lastName' => '', 'email' => 'admin@example.test', 'isActive' => true],
    '1099' => ['id' => 1099, 'username' => 'sb-tech', 'firstName' => '', 'lastName' => '', 'email' => 'tech@example.test', 'isActive' => true],
    '1200' => ['id' => 1200, 'username' => 'sb-gone', 'firstName' => '', 'lastName' => '', 'email' => 'gone@example.test', 'isActive' => false],
    '1300' => ['id' => 1300, 'username' => 'sb-eng', 'firstName' => 'Sandbox', 'lastName' => 'Engineer', 'email' => ' TECH2@Example.Test ', 'isActive' => true],
    '1400' => ['id' => 1400, 'username' => 'sb-acct', 'firstName' => '', 'lastName' => '', 'email' => 'acct@example.test', 'isActive' => true],
];
$s->seedCrm(['users' => $users]);
$s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true, 'phone' => '+256 700 000 110', 'ucrm_user_id' => 1]);
$s->staff('tech',  ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support', 'phone' => '+256 700 000 111', 'ucrm_user_id' => 81]);
$s->staff('tech2', ['name' => 'Sandbox Engineer', 'email' => 'tech2@example.test', 'role' => 'support_engineer', 'phone' => '0700 000 112']);
$s->staff('dup',   ['name' => 'Sandbox Duplicate', 'email' => 'tech@example.test', 'role' => 'support', 'phone' => '']);
$s->staff('acct',  ['name' => 'Sandbox Accountant', 'email' => 'acct@example.test', 'role' => 'accountant', 'phone' => '12345']);
$s->staff('ret',   ['name' => 'Sandbox Retailer', 'email' => 'ret@example.test', 'role' => 'sales', 'phone' => '12345']);
$s->staff('s3',    ['name' => 'Sandbox Three', 'email' => 's3@example.test', 'role' => 'support', 'phone' => '12345', 'ucrm_user_id' => 4]);
$s->staff('s5',    ['name' => 'Sandbox Five', 'email' => 's5@example.test', 'role' => 'support', 'phone' => '+256 700 000 115', 'ucrm_user_id' => 1581]);
$s->staff('real',  ['name' => 'Sandbox Unverified', 'email' => 'admin2@example.test', 'role' => 'support', 'phone' => '+256 700 000 116', 'ucrm_user_id' => 1000]);
$row  = function (string $k) use ($s): array { return $s->row($s->ids[$k]); };
$link = function (string $k) use ($row) { return $row($k)['ucrm_link'] ?? null; };
/** Stored as null — which ?? cannot tell from absent. */
$nulled = function (array $r, string $key): bool { return array_key_exists($key, $r) && $r[$key] === null; };

// ── 1. set_ucrm_user_id ──────────────────────────────────────────────────────
echo "\n1. set_ucrm_user_id: checked with uCRM before it is saved\n";
$set = function (string $who, string $k, int $id) use ($s) {
    return $s->api($who, 'POST', 'set_ucrm_user_id', ['retailer_id' => $s->ids[$k], 'ucrm_user_id' => $id]);
};
$r = $set('tech', 'tech', 1099);
is_($r[0] === 403 && (int)($row('tech')['ucrm_user_id'] ?? 0) === 81, 'a support account cannot link anyone, itself included', (string)$r[0]);
$r = $set('admin', 'tech', 1099);
$l = $link('tech');
is_($r[0] === 200 && ($r[2]['data']['verified'] ?? null) === true && (int)($row('tech')['ucrm_user_id'] ?? 0) === 1099,
    'an administrator links the support account to uCRM user 1099, whose e-mail is its own', $r[1]);
is_(is_array($l) && (int)$l['user_id'] === 1099 && $l['email'] === 'tech@example.test' && (int)$l['verified_by'] === $s->ids['admin']
    && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string)$l['verified_at']),
    'and records the link beside it: user, e-mail, when and by whom', json_encode($l));
$refusals = [
    ['tech2', 4242, 422, 'uCRM has no user #4242', 'an id uCRM does not know'],
    ['tech2', 1200, 422, 'is not active',          'an inactive uCRM user'],
    ['tech2', 1000, 422, 'different e-mail',       'a uCRM user with another e-mail (D9)'],
    ['dup',   1099, 422, 'already linked to another active account', 'a uCRM user already linked to another active account'],
    ['acct',  1400, 422, 'does not take jobs',     'an accountant — a role that does not take jobs'],
    ['ret',   1000, 422, 'does not take jobs',     'a retailer'],
];
foreach ($refusals as [$k, $id, $code, $why, $label]) {
    $before = $row($k);
    $r = $set('admin', $k, $id);
    $after = $row($k);
    is_($r[0] === $code && strpos((string)($r[2]['message'] ?? ''), $why) !== false
        && ($after['ucrm_user_id'] ?? null) === ($before['ucrm_user_id'] ?? null) && ($after['ucrm_link'] ?? null) === ($before['ucrm_link'] ?? null),
        "refused, nothing changed: {$label}", $r[0] . ' ' . ($r[2]['message'] ?? $r[1]));
}
$r = $set('admin', 'tech2', 1300);
is_($r[0] === 200 && (int)($row('tech2')['ucrm_user_id'] ?? 0) === 1300 && ($link('tech2')['email'] ?? '') === 'tech2@example.test',
    "uCRM's e-mail in capitals with spaces still matches the account's (support_engineer, D7)", $r[1]);
$s->usersDown(true);
$r = $set('admin', 's5', 1099);
is_($r[0] === 503 && strpos((string)($r[2]['message'] ?? ''), 'could not be reached') !== false && (int)($row('s5')['ucrm_user_id'] ?? 0) === 1581,
    'uCRM not answering: 503, and the link is not changed — never set unverified', $r[0] . ' ' . ($r[2]['message'] ?? ''));
$s->usersDown(false);
$r = $set('admin', 's5', 0);
is_($r[0] === 200 && $nulled($row('s5'), 'ucrm_user_id') && $nulled($row('s5'), 'ucrm_link'),
    '"not linked" clears the id and the link (M5)', $r[1] . ' ' . json_encode($row('s5')));

// ── 2. The Edit form ─────────────────────────────────────────────────────────
echo "\n2. The Staff page's Edit form\n";
$s->login('admin', 'admin@example.test', 'sj-password-1');
// The form as the picker sends it: "keep" for the link as saved, unless the test chooses a user.
$edit = function (string $k, array $changes, bool $withField = true) use ($s, $row): array {
    $r = $row($k);
    $f = ['action' => 'edit_retailer', 'retailer_id' => $s->ids[$k], 'name' => $r['name'] ?? '', 'email' => $r['email'] ?? '',
          'phone' => $r['phone'] ?? '', 'role' => $r['role'] ?? 'support', 'is_active' => '1'];
    if (!empty($r['is_admin'])) $f['is_admin'] = '1';
    if ($withField) $f['ucrm_user_id'] = !empty($r['ucrm_user_id']) ? 'keep' : '0';
    $res = $s->form('admin', array_merge($f, $changes));
    return [$res, $s->flash('admin')];
};
// uCRM asked about one user — what a save's check does. The Staff page's own list (users/admins) is not counted.
$usersReqs = function () use ($s): int { return count($s->crmReqs('GET', '#^/users/admins/\d+$#')); };

$n0 = $usersReqs();
[$res, $flash] = $edit('tech', ['name' => 'Sandbox Tech Renamed']);
is_(($row('tech')['name'] ?? '') === 'Sandbox Tech Renamed' && (int)($row('tech')['ucrm_user_id'] ?? 0) === 1099 && is_array($link('tech')),
    'a save that leaves a verified link as it was keeps it', $flash);
is_($usersReqs() === $n0 && strpos($flash, 'success:') === 0, '…without asking uCRM again, and the page says the account was updated', $flash);

[$res, $flash] = $edit('s3', ['name' => 'Sandbox Three Renamed']);
is_(($row('s3')['name'] ?? '') === 'Sandbox Three Renamed' && (int)($row('s3')['ucrm_user_id'] ?? 0) === 4 && $link('s3') === null,
    'an old unverified id left as it was is kept — still unverified, matching nobody');
is_(strpos($flash, 'warning:') === 0 && strpos($flash, 'uCRM user #4 was left as it was. It is not verified') !== false,
    'and the page says so', $flash);

$n0 = $usersReqs();
[$res, $flash] = $edit('s3', ['ucrm_user_id' => '4']);
is_((int)($row('s3')['ucrm_user_id'] ?? 0) === 4 && $link('s3') === null && $usersReqs() === $n0 + 1
    && strpos($flash, 'The uCRM link was not changed: uCRM has no user #4') !== false,
    'choosing the old id itself is a choice: uCRM is asked, and says it has no such user', $flash);
[$res, $flash] = $edit('s3', ['ucrm_user_id' => 'not-a-number']);
is_((int)($row('s3')['ucrm_user_id'] ?? 0) === 4, 'a value that is no number changes nothing');
[$res, $flash] = $edit('s3', ['ucrm_user_id' => '0']);
is_($nulled($row('s3'), 'ucrm_user_id') && $nulled($row('s3'), 'ucrm_link'), '"— not linked —" clears the old id (M5)', $flash);

[$res, $flash] = $edit('dup', ['name' => 'Sandbox Duplicate Renamed', 'ucrm_user_id' => '1099']);
is_(($row('dup')['name'] ?? '') === 'Sandbox Duplicate Renamed' && empty($row('dup')['ucrm_user_id']),
    'a refused link: the other fields save, the link does not');
is_(strpos($flash, 'The uCRM link was not changed: uCRM user #1099 is already linked to another active account') !== false,
    'and the page gives the reason', $flash);

$s->usersDown(true);
[$res, $flash] = $edit('s5', ['name' => 'Sandbox Five Renamed', 'ucrm_user_id' => '1099']);
$s->usersDown(false);
is_(($row('s5')['name'] ?? '') === 'Sandbox Five Renamed' && empty($row('s5')['ucrm_user_id'])
    && strpos($flash, 'The uCRM link was not changed: uCRM could not be reached') !== false,
    'uCRM not answering at save: the link keeps its value, the other fields save, the page says so', $flash);

require_once "{$root}/lib/StaffDirectory.php";
[$res, $flash] = $edit('tech', ['email' => 'tech.new@example.test']);
$t = $row('tech');
is_(($t['email'] ?? '') === 'tech.new@example.test' && (int)($t['ucrm_user_id'] ?? 0) === 1099
    && (($t['ucrm_link']['email'] ?? '') === 'tech@example.test') && StaffDirectory::linkedUcrmUser($t) === 0,
    'a changed e-mail: the id stays, the recorded link keeps the old e-mail, and the link no longer counts (M7)');
is_(strpos($flash, 'The e-mail changed, so the link to uCRM user #1099 no longer counts') !== false, 'and the page says so', $flash);
[$res, $flash] = $edit('tech', ['email' => 'tech@example.test']);
is_(StaffDirectory::linkedUcrmUser($row('tech')) === 1099 && strpos($flash, 'success:') === 0,
    'the e-mail put back: the link counts again, and nothing needs saying', $flash);
$n0 = $usersReqs();
[$res, $flash] = $edit('tech', ['ucrm_user_id' => '1099']);
is_(StaffDirectory::linkedUcrmUser($row('tech')) === 1099 && $usersReqs() === $n0 + 1 && strpos($flash, 'success:') === 0,
    'choosing the same user again is checked with uCRM, and stays linked', $flash);

[$res, $flash] = $edit('tech', ['name' => 'Sandbox Tech'], false);
is_((int)($row('tech')['ucrm_user_id'] ?? 0) === 1099 && is_array($link('tech')), 'a form without the field leaves the link alone');

// ── 3. The Staff page ────────────────────────────────────────────────────────
echo "\n3. The Staff page\n";
$html = $s->page('admin', 'page=dashboard&tab=retailers');
is_(strpos($html, '<select name="ucrm_user_id" id="edit_ucrm_user_id"') !== false
    && strpos($html, '<input type="number" name="ucrm_user_id"') === false, 'the uCRM user is a picker, not a number to type');
is_(strpos($html, 'function rtFillUcrmPicker') !== false && strpos($html, 'rtFillUcrmPicker(id, d);') !== false,
    'filled from uCRM when the Edit form opens');
/** One staff card, from its name to its footer — found by the card's own name block, never an earlier list. */
$card = function (string $name) use ($html): string {
    if (!preg_match('#<div class="rt-card-name">\s*' . preg_quote($name, '#') . '\s*<#', $html, $m, PREG_OFFSET_CAPTURE)) return '';
    $i = $m[0][1];
    $j = strpos($html, 'rt-card-footer', $i);
    return substr($html, $i, $j === false ? 3000 : $j - $i);
};
is_($card('Sandbox Tech') !== '' && $card('Sandbox Accountant') !== '', 'the cards are found by name (a control on the locator)');
is_(strpos($card('Sandbox Tech'), '🔗 uCRM #1099') !== false, 'a verified link: "🔗 uCRM #1099"');
is_(strpos($card('Sandbox Five Renamed'), '⚠ Not linked to uCRM') !== false, 'no link: "⚠ Not linked to uCRM"');
is_(strpos($card('Sandbox Unverified'), '⚠ uCRM #1000 not verified') !== false,
    'a real uCRM user saved the old way: "not verified" — the stored id alone is never trusted');
is_(strpos($card('Sandbox Admin'), '⚠ uCRM #1 is not a uCRM user') !== false, 'an id uCRM does not know: "is not a uCRM user"');
is_(strpos($card('Sandbox Accountant'), 'uCRM') === false, 'an accountant (no jobs) has no uCRM badge');
is_(strpos($html, 'CRM Linked') === false && strpos($html, 'No CRM link') === false && strpos($html, '🔗 CRM #') === false
    && strpos($html, '<option value="linked">CRM Linked</option>') === false,
    'D6: the organisation-7 tile, filter and badges are not drawn');
is_(strpos($html, '<select id="rtCrmFilter" class="rt-filter" style="display:none;">') !== false,
    '…the filter kept as a hidden element, because the page script reads it');
is_(strpos($card('Sandbox Three Renamed'), '⚠ Number cannot receive WhatsApp') !== false, 'J3: a job-taking account whose number has no international form is flagged');
is_(strpos($card('Sandbox Duplicate Renamed'), '⚠ No number: job messages cannot reach this person') !== false, 'J3: one with no number is flagged too');
is_(strpos($card('Sandbox Retailer'), 'Number cannot receive') === false, 'a retailer\'s number is not judged (J3 is for job-taking roles)');
is_(strpos($html, '"ucrm_verified":true') !== false && strpos($html, '"ucrm_verified":false') !== false,
    'the form knows which links are verified');

// ── 4. The engineer lists ────────────────────────────────────────────────────
echo "\n4. The engineer lists offer only verified accounts\n";
$r = $s->api('tech2', 'GET', 'support_engineers');
$agents = $r[2]['data']['agents'] ?? null;
$names = is_array($agents) ? array_column($agents, 'name') : [];
sort($names);
is_($r[0] === 200 && $names === ['Sandbox Engineer', 'Sandbox Tech'], 'New Job\'s list: exactly the two verified accounts (asked by a support engineer, D7)', $r[1]);
is_(strpos($r[1], 'phone') === false && strpos($r[1], '+256') === false, 'and no phone number in it');
foreach (['ret', 'acct'] as $who) {
    $r = $s->api($who, 'GET', 'support_engineers');
    is_($r[0] === 403, "and nothing for the {$who} account, who may not create a job", (string)$r[0]);
}
$r = $s->api('admin', 'GET', 'get_support_staff');
$ids = is_array($r[2]['data'] ?? null) ? array_map('intval', array_column($r[2]['data'], 'ucrm_user_id')) : [];
sort($ids);
is_($r[0] === 200 && $ids === [1099, 1300], 'Bulk Dispatch\'s list: the same two', $r[1]);
$s->update($s->ids['tech2'], ['is_active' => false]);
$r = $s->api('admin', 'GET', 'support_engineers');
is_($r[0] === 200 && count($r[2]['data']['agents'] ?? []) === 1, 'an account made inactive drops out at once');
$s->update($s->ids['tech2'], ['is_active' => true]);
$s->seedCrm(['users' => array_replace($users, ['1300' => array_merge($users['1300'], ['isActive' => false])])]);
$r = $s->api('admin', 'GET', 'support_engineers');
is_($r[0] === 200 && count($r[2]['data']['agents'] ?? []) === 1, 'and so does one whose uCRM user was made inactive in uCRM');
$s->seedCrm(['users' => $users]);

// ── 5. J3 through the real save handlers ─────────────────────────────────────
echo "\n5. J3: a job-taking account's number is saved in the international form\n";
require_once "{$root}/lib/TenantProfile.php";
require_once "{$root}/lib/StaffDirectory.php";
$ugp = TenantProfile::load('uganda');
$TABLE = [
    ['0772 123 456',      '+256772123456', ''],
    ['772123456',         '+256772123456', ''],
    ['+256 772 123 456',  '+256772123456', ''],
    ['00256772123456',    '+256772123456', ''],
    ['+211 912 345 678',  '+211912345678', ''],
    ['12345',             '12345',         'This number cannot receive WhatsApp. Write it as +256 7XX XXX XXX.'],
    ['07721',             '07721',         'This number cannot receive WhatsApp. Write it as +256 7XX XXX XXX.'],
    ['',                  '',              ''],
];
foreach ($TABLE as [$typed, $stored, $warn]) {
    [$res, $flash] = $edit('tech', ['phone' => $typed]);
    $got = (string)($row('tech')['phone'] ?? '');
    is_($got === $stored && ($warn === '' ? strpos($flash, 'cannot receive') === false : strpos($flash, $warn) !== false),
        sprintf('edit, support: "%s" is saved as "%s"%s', $typed, $stored, $warn !== '' ? ', with the warning' : ''), "saved \"{$got}\"; {$flash}");
    $p = StaffDirectory::phoneOf(['phone' => $got], $ugp);
    is_($stored === '' ? $p === null : ($warn !== '' ? $p === null : $p === $stored), '  …and read back on use the same way (phoneOf)');
}
[$res, $flash] = $edit('acct', ['phone' => '0772 123 456']);
is_(($row('acct')['phone'] ?? '') === '0772 123 456' && strpos($flash, 'cannot receive') === false,
    'an accountant\'s number is saved exactly as typed (J3 is for job-taking roles)');
[$res, $flash] = $edit('ret', ['phone' => '0772 123 458']);
is_(($row('ret')['phone'] ?? '') === '0772 123 458', 'and so is a retailer\'s');

$create = function (string $email, string $role, string $phone) use ($s): string {
    $s->form('admin', ['action' => 'create_retailer', 'name' => 'Sandbox New ' . $role, 'email' => $email, 'phone' => $phone,
                       'password' => 'sj-new-password-1', 'role' => $role, 'is_employee' => '1', 'wallet' => '0']);
    return $s->flash('admin');
};
$byEmail = function (string $email) use ($s): array {
    foreach ($s->q('SELECT data FROM retailers') as $r) { $d = json_decode((string)$r['data'], true) ?: []; if (($d['email'] ?? '') === $email) return $d; }
    return [];
};
$flash = $create('new.support@example.test', 'support', '0772 123 457');
is_(($byEmail('new.support@example.test')['phone'] ?? '') === '+256772123457', 'create, support: saved in the international form', $flash);
$flash = $create('new.bad@example.test', 'support_leader', '12345');
is_(($byEmail('new.bad@example.test')['phone'] ?? '') === '12345' && strpos($flash, 'This number cannot receive WhatsApp') !== false
    && strpos($flash, 'Retailer created') !== false, 'create, support leader, no international form: kept, and the page warns', $flash);
$flash = $create('new.sales@example.test', 'sales', '0772 123 459');
is_(($byEmail('new.sales@example.test')['phone'] ?? '') === '0772 123 459' && strpos($flash, 'cannot receive') === false,
    'create, retailer: saved exactly as typed');
$s->form('admin', ['action' => 'import_crm_staff', 'crm_email' => 'imported@example.test', 'crm_name' => 'Sandbox Imported',
                   'crm_phone' => '0772 123 460', 'crm_id' => '77', 'import_role' => 'support']);
$s->flash('admin');   // read and discarded: this message carries a temporary password and is never printed
is_(($byEmail('imported@example.test')['phone'] ?? '') === '+256772123460', 'import, support: saved in the international form');

// ── 6. Weakened copies ───────────────────────────────────────────────────────
$s->stop();
if ($withMutants) {
    echo "\n6. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['includes/post/post_sync.php', "            \$_erV  = StaffLink::verify(",
         "            \$_erV  = ['ok' => true, 'action' => \$_erWanted > 0 ? 'link' : 'clear', 'reason' => '', 'link' => StaffDirectory::makeLink(\$_erWanted, StaffDirectory::email(\$_erCand), 0), 'unreachable' => false] ?: StaffLink::verify(",
         'the Edit form saves without asking uCRM'],
        ['lib/StaffLink.php', "        if (\$other !== null) return \$out(false, 'refuse',", "        if (false) return \$out(false, 'refuse',", 'the "already linked" check skipped'],
        ['lib/UcrmUsers.php', "        return strtolower(trim((string)(\$u['email'] ?? '')));", "        return (string)(\$u['email'] ?? '');", 'a case-sensitive e-mail match'],
        ['tabs/admin/retailers.php', "        } elseif (\$_rtVer !== \$_rtId) {", "        } elseif (false) {", 'the badge trusting the stored id'],
        ['lib/StaffDirectory.php', "        if (\$email === '' || (string)(\$link['email'] ?? '') !== \$email) return 0;", '', 'a link that survives a changed e-mail'],
        ['includes/post/post_sync.php', "            if (\$_erIntl !== null) { \$updates['phone'] = \$_erIntl; \$_erCand['phone'] = \$_erIntl; }",
         "            if (false) { }", 'J3 bypassed on edit'],
        ['includes/post/post_sync.php', "            else \$_erNotes[] = 'This number cannot receive WhatsApp. Write it as +256 7XX XXX XXX.';",
         "            else { \$updates['phone'] = ''; }", 'an invalid number silently emptied'],
        ['includes/api/api_support.php', "            foreach (JobAccess::assignableByUcrmUser(\$store->load('retailers.json') ?? [], \$_seUsers) as \$_seUid => \$r) {",
         "            foreach (array_filter(\$store->load('retailers.json') ?? [], function (\$r) { return !empty(\$r['ucrm_user_id']); }) as \$_seUid => \$r) {",
         'the engineer list trusting any stored id'],
    ];
    foreach ($MUTANTS as [$rel, $old, $new, $label]) {
        [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
        if ($n !== 1) { is_(false, "weakened copy \"{$label}\": its anchor occurs once in {$rel}", "found {$n} times"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tmp) . ' --no-mutants 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        is_($rc !== 0 && $fails !== [], "caught: {$label}", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        if ($fails) echo '         first: ' . trim(substr($fails[0], 7, 110)) . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

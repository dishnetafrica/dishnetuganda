<?php
declare(strict_types=1);
/**
 * test_staff_jobs_gate.php — 5.18.50 (docs/44 J1, release A): on Uganda, nothing but an administrator's verified link
 * writes a staff member's uCRM user.
 *
 * Five hard-coded South Sudan lists rewrote ucrm_user_id — on every version change, on every page load, when My Jobs
 * opened, on Clear Cache and on auto-map. Measured on Uganda (docs/43 §11) they forced two people onto uCRM users that
 * do not exist there. This test proves, through the real pages and API of a sandboxed plugin:
 *   1. the gate: Uganda by tenant_profile, Uganda by a UGX currency held only in the vault — which needs the data
 *      directory, the trap every call site must avoid — South Sudan otherwise, and false on any doubt; and that every
 *      call in the plugin passes the data directory;
 *   2. on Uganda: a version change, a page load and My Jobs rewrite nothing; Clear Cache is an administrator's, empties
 *      the jobs cache and leaves the staff table byte for byte; get_ucrm_users is an administrator's and carries
 *      uCRM's own users, none of South Sudan's names or e-mails; auto-map links nobody;
 *   3. the control: the same page load on a South Sudan copy does rewrite, so the observation above can see one;
 *   4. weakened copies of the code each fail this test.
 *
 * Every person here is fictitious, except that the South Sudan lists' own e-mails are read from the source to seed
 * accounts they would rewrite. They are never printed.
 *
 *   php test_staff_jobs_gate.php [--root=DIR] [--no-mutants]
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

// ── The South Sudan lists, read from the source (never printed) ──────────────
function sj_map(string $src, string $var): array {
    if (!preg_match('/' . preg_quote($var, '/') . '\s*=\s*\[(.*?)\];/s', $src, $m)) return [];
    preg_match_all("/'([^'@\s]+@[^'\s]+)'\s*=>\s*(\d+)/", $m[1], $p, PREG_SET_ORDER);
    $out = [];
    foreach ($p as $x) $out[strtolower($x[1])] = (int)$x[2];
    return $out;
}
$pub   = (string)file_get_contents("{$root}/public.php");
$tab   = (string)file_get_contents("{$root}/tabs/support/scheduling.php");
$apiS  = (string)file_get_contents("{$root}/includes/api/api_scheduling.php");
$maps = [
    'page load'      => sj_map($pub, '$_ucrmSeedMapGlobal'),
    'version change' => sj_map($pub, '$_seedMapUpgrade'),
    'My Jobs'        => sj_map($tab, '$_ucrmSeedMap'),
    'Clear Cache'    => sj_map($apiS, '$seedMap'),
];
$common = null;
foreach ($maps as $m) $common = $common === null ? $m : array_intersect_assoc($common, $m);
$seedEmail = ''; $seedId = 0;
foreach ((array)$common as $e => $id) { if ($id > 1) { $seedEmail = $e; $seedId = $id; break; } }
preg_match_all("/'firstName'\s*=>\s*'([^']+)'/", $apiS, $fn);
$ssNames = array_values(array_unique(array_filter($fn[1], function ($n) { return strlen($n) > 3; })));
$ssEmails = array_keys(array_merge(...array_values($maps)));

echo "\n0. The South Sudan lists this test guards\n";
foreach ($maps as $where => $m) is_(count($m) >= 10, "the {$where} list is read from the source (" . count($m) . ' entries)');
is_($seedEmail !== '' && $seedId > 1, 'one e-mail sits in all four lists with the same uCRM user — the account they would rewrite');

// ── 1. The gate ───────────────────────────────────────────────────────────────
echo "\n1. The gate\n";
require_once "{$root}/lib/TenantProfile.php";
require_once "{$root}/lib/StaffJobsGate.php";
$gdir = sys_get_temp_dir() . '/sj-gate-' . getmypid();
@mkdir($gdir, 0700, true);
$gvault = $gdir . '/vault.json';
file_put_contents($gvault, json_encode(['config' => ['currency_code' => 'UGX']]));
putenv('DN_VAULT_FILE=' . $gvault);
StaffJobsGate::reset();
is_(StaffJobsGate::applies(['tenant_profile' => 'uganda'], null) === true, 'tenant_profile uganda: Uganda');
is_(StaffJobsGate::applies([], $gdir) === true, 'UGX held only in the vault, with the data directory: Uganda');
StaffJobsGate::reset();
is_(StaffJobsGate::applies([], null) === false,
    'the same install asked WITHOUT the data directory reads as South Sudan — the trap every call must avoid');
StaffJobsGate::reset();
is_(StaffJobsGate::applies(['tenant_profile' => 'south-sudan'], $gdir) === false, 'an explicit south-sudan profile wins over the vault');
file_put_contents($gvault, json_encode(['config' => []]));
StaffJobsGate::reset();
is_(StaffJobsGate::applies([], $gdir . '-none') === false, 'nothing configured: South Sudan');
is_(StaffJobsGate::applies(['tenant_profile' => 'no-such-country'], null) === false, 'an unknown profile: South Sudan');
putenv('DN_VAULT_FILE');
exec('rm -rf ' . escapeshellarg($gdir));

// Every call passes the data directory: a second argument at the top level of the call.
$calls = 0; $bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (substr($p, -4) !== '.php' || strpos($p, '/tests/') !== false || strpos($p, '/docs/') !== false) continue;
    $src = (string)file_get_contents($p);
    $off = 0;
    while (($i = strpos($src, 'StaffJobsGate::applies(', $off)) !== false) {
        $off = $i + 1;
        $j = $i + strlen('StaffJobsGate::applies(');
        $depth = 1; $comma = false;
        for (; $j < strlen($src) && $depth > 0; $j++) {
            $c = $src[$j];
            if ($c === '(' || $c === '[') $depth++;
            elseif ($c === ')' || $c === ']') $depth--;
            elseif ($c === ',' && $depth === 1) $comma = true;
        }
        if (strpos(substr($src, max(0, $i - 30), 30), 'function') !== false) continue;   // the definition
        $calls++;
        if (!$comma) $bad[] = substr($p, strlen($root) + 1);
    }
}
is_($calls >= 12, "the gate is called at {$calls} places");
is_($bad === [], 'every call passes the data directory as its second argument', implode(', ', $bad));

// ── 2. On Uganda, through the real pages and API ─────────────────────────────
echo "\n2. On Uganda (selected by the vault, as the live box is before D8)\n";
$ug = SjSandbox::start($root, ['timezone' => 'Africa/Kampala'], 'sjgate');
file_put_contents($ug->vault, json_encode(['config' => ['currency_code' => 'UGX']]));
$ug->seedCrm(['users' => [
    '1000' => ['id' => 1000, 'username' => 'sb-admin', 'firstName' => '', 'lastName' => '', 'email' => 'admin@example.test', 'isActive' => true],
    '1099' => ['id' => 1099, 'username' => 'sb-tech', 'firstName' => '', 'lastName' => '', 'email' => 'tech@example.test', 'isActive' => true],
]]);
$ug->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
$ug->staff('acct',  ['name' => 'Sandbox Accountant', 'email' => 'acct@example.test', 'role' => 'accountant']);
$ug->staff('ret',   ['name' => 'Sandbox Retailer', 'email' => 'ret@example.test', 'role' => 'sales']);
$ug->staff('sup',   ['name' => 'Sandbox Support', 'email' => 'tech@example.test', 'role' => 'support', 'ucrm_user_id' => 81]);
$ug->staff('seed',  ['name' => 'Sandbox Listed', 'email' => $seedEmail, 'role' => 'support', 'ucrm_user_id' => 18]);
$ug->staff('blank', ['name' => 'Sandbox Unlinked', 'email' => $seedEmail === '' ? 'x@example.test' : 'blank.' . $seedEmail, 'role' => 'support']);
$ug->store()->save('scheduling_cache_meta.json', ['plugin_version' => 'an-older-release']);
$ug->store()->save('scheduling_jobs_cache.json', [['id' => 1, 'title' => 'cached']]);
$ids = function (SjSandbox $s): array {
    $out = [];
    foreach ($s->q('SELECT id, data FROM retailers ORDER BY id') as $r) {
        $d = json_decode((string)$r['data'], true) ?: [];
        $out[(int)$r['id']] = $d['ucrm_user_id'] ?? null;
    }
    return $out;
};
$before = $ids($ug);

$ug->login('seed', $seedEmail, 'sj-password-1');
$html = $ug->page('seed', 'page=dashboard');
is_(strpos($html, '<html') !== false, 'the listed account signs in and a page renders');
$ug->page('seed', 'page=dashboard&tab=scheduling');
is_($ids($ug) === $before, 'a version change, a page load and My Jobs rewrite no uCRM user',
    json_encode(['before' => $before, 'after' => $ids($ug)]));
is_(($ug->store()->load('scheduling_cache_meta.json')['plugin_version'] ?? '') !== 'an-older-release',
    '…while the version-change block did run (it cleared the cache), so the list had its chance');

$tables = function (SjSandbox $s): string { return json_encode($s->q('SELECT id, data FROM retailers ORDER BY id')); };
$t0 = $tables($ug);
foreach (['acct', 'ret', 'sup'] as $who) {
    $r = $ug->api($who, 'GET', 'scheduling_clear_cache');
    is_($r[0] === 403, "Clear Cache: 403 for the {$who} account", $r[0] . ' ' . substr($r[1], 0, 120));
}
$ug->store()->save('scheduling_jobs_cache.json', [['id' => 1, 'title' => 'cached']]);
$r = $ug->api('admin', 'GET', 'scheduling_clear_cache');
is_($r[0] === 200 && ($r[2]['data']['message'] ?? '') === 'Cache cleared.', 'Clear Cache: 200 for an administrator', $r[1]);
is_(($ug->store()->load('scheduling_jobs_cache.json') ?? ['x']) === [], 'the jobs cache is emptied');
is_($tables($ug) === $t0, 'and the staff table is byte-identical afterwards');

foreach (['acct', 'ret', 'sup'] as $who) {
    $r = $ug->api($who, 'GET', 'get_ucrm_users');
    is_($r[0] === 403, "get_ucrm_users: 403 for the {$who} account", (string)$r[0]);
}
$r = $ug->api('admin', 'GET', 'get_ucrm_users', null, '&retailer_id=' . $ug->ids['sup']);
$users = $r[2]['data']['users'] ?? [];
is_($r[0] === 200 && array_column($users, 'id') === [1000, 1099], "an administrator gets uCRM's own users, and only them", $r[1]);
is_(strpos($r[1], '@') === false, 'no e-mail in the answer');
$leak = array_filter(array_merge($ssNames, $ssEmails), function ($n) use ($r) { return stripos($r[1], $n) !== false; });
is_($leak === [] && count($ssNames) >= 10, 'none of the South Sudan list\'s ' . count($ssNames) . ' names or ' . count($ssEmails) . ' e-mails (canary)');
$same = array_values(array_filter($users, function ($u) { return !empty($u['same_email']); }));
is_(count($same) === 1 && (int)$same[0]['id'] === 1099, 'the user with the account\'s own e-mail is marked, and nothing is chosen for it');

$t0 = $tables($ug);
$r = $ug->api('sup', 'POST', 'auto_map_ucrm_users', []);
is_($r[0] === 403, 'auto-map: 403 for a support account', (string)$r[0]);
$r = $ug->api('admin', 'POST', 'auto_map_ucrm_users', []);
is_($r[0] === 409 && strpos((string)($r[2]['message'] ?? ''), 'Staff & Retailers page') !== false,
    'auto-map: 409 for an administrator, pointing at the Staff page', $r[0] . ' ' . substr($r[1], 0, 160));
is_($tables($ug) === $t0, 'and nothing is linked: the staff table is byte-identical');
$ug->stop();

// ── 3. The control: South Sudan still rewrites ────────────────────────────────
echo "\n3. The control: a South Sudan copy, same account, same page\n";
$ss = SjSandbox::start($root, [], 'sjgate-ss');
$ss->staff('seed', ['name' => 'Sandbox Listed', 'email' => $seedEmail, 'role' => 'support', 'ucrm_user_id' => 18]);
$ss->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
$ss->login('seed', $seedEmail, 'sj-password-1');
$ss->page('seed', 'page=dashboard');
$after = (int)($ss->row($ss->ids['seed'])['ucrm_user_id'] ?? 0);
is_($after === $seedId, "South Sudan's page load still rewrites the listed account (18 → the list's user) — the observation above is live",
    "now {$after}");
$r = $ss->api('admin', 'GET', 'get_ucrm_users');
is_($r[0] === 200 && count($r[2]['data']['users'] ?? []) >= 10, 'and its get_ucrm_users still answers the South Sudan list, as in 5.18.49');
$ss->stop();

// ── 4. Weakened copies ────────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n4. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['public.php', "        if (!\$_staffJobsUganda) {\n        \$_seedMapUpgrade = [", "        if (true) {\n        \$_seedMapUpgrade = [",
         'the version-change list left ungated'],
        ['public.php', 'if (!empty($retailer) && !$_staffJobsUganda) {', 'if (!empty($retailer)) {', 'the page-load list left ungated'],
        ['tabs/support/scheduling.php', "if (!\$_sjUganda) {\nif (\$_correctId", "if (true) {\nif (\$_correctId", 'the My Jobs correction left ungated'],
        ['public.php', '$_staffJobsUganda = StaffJobsGate::applies(is_array($config ?? null) ? $config : [], $dataDir);',
         '$_staffJobsUganda = StaffJobsGate::applies(is_array($config ?? null) ? $config : [], null);', 'the gate asked without the data directory'],
        ['includes/api/api_scheduling.php', "        if (!StaffDirectory::isAdmin(\$_sjMe)) \$er2('Admin only.', 403);\n        \$store->save('scheduling_jobs_cache.json', []);",
         "        \$store->save('scheduling_jobs_cache.json', []);", 'Clear Cache without its admin check'],
        ['includes/api/api_scheduling.php', "if (\$_sjUganda && \$act === 'auto_map_ucrm_users' && \$met === 'POST') {",
         "if (false && \$act === 'auto_map_ucrm_users' && \$met === 'POST') {", 'auto-map still writing on Uganda'],
        ['includes/api/api_scheduling.php', "if (\$_sjUganda && \$act === 'get_ucrm_users') {", "if (false && \$act === 'get_ucrm_users') {",
         'get_ucrm_users back to the South Sudan list'],
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

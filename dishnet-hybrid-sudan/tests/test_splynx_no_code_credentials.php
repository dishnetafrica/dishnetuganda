<?php
declare(strict_types=1);
/**
 * test_splynx_no_code_credentials.php — 5.18.95 (D5): the Splynx API key and secret are no longer written in the code.
 * The repository is public, so values in it are known to anyone; the key and secret now come only from the settings
 * (Settings → Splynx), and the old ?page=splynx_patch, which wrote the code's values into the configuration, is retired.
 *
 *   1. no PHP file assigns a quoted value to splynx_key or splynx_secret;
 *   2. over HTTP: requests leave no splynx_key or splynx_secret in the configuration; ?page=splynx_patch answers 410
 *      to an admin and changes nothing, 403 to anyone else;
 *   3. control: on the live code (f4b4c96) the first request writes a key into the configuration, so check 2 can
 *      fail. The value is never printed: only whether one is there.
 *
 *   php test_splynx_no_code_credentials.php [--root=DIR] [--no-control]
 */
$opt  = getopt('', ['root:', 'no-control']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0; $skip = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}
$has = function (SjSandbox $s, string $k): bool { return trim((string)($s->store()->load('kyc_config.json')[$k] ?? '')) !== ''; };

echo "1. No credential in the code\n";
$hits = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $fi) {
    $p = $fi->getPathname();
    if (substr($p, -4) !== '.php' || strpos($p, '/tests/') !== false || strpos($p, '/data/') !== false) continue;
    if (preg_match("/splynx_(key|secret)'\\]\\s*=\\s*'[^']+'/", (string)file_get_contents($p))) $hits[] = substr($p, strlen($root) + 1);
}
is_($hits === [], 'no PHP file assigns a quoted splynx_key or splynx_secret', implode(', ', $hits));

echo "\n2. Over HTTP\n";
$s = SjSandbox::start($root, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'], 'sjsplynx');
$s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
$s->staff('acct',  ['name' => 'Sandbox Accountant', 'email' => 'acct@example.test', 'role' => 'accountant']);
$s->login('admin', 'admin@example.test', 'sj-password-1');
$s->login('acct', 'acct@example.test', 'sj-password-1');
$s->page('admin', 'page=dashboard');
is_(!$has($s, 'splynx_key') && !$has($s, 'splynx_secret'), 'after requests the configuration holds no Splynx key or secret');
$before = (string)@file_get_contents($s->data . '/kyc_config.json');
$r = $s->http('GET', "{$s->base}?page=splynx_patch", null, [], $s->jars['admin']);
is_($r[0] === 410 && strpos($r[1], 'Settings') !== false, 'splynx_patch: 410 for an admin, pointing to Settings', $r[0] . ' ' . substr($r[1], 0, 80));
is_((string)@file_get_contents($s->data . '/kyc_config.json') === $before && !$has($s, 'splynx_key'), 'and it changed nothing');
$r = $s->http('GET', "{$s->base}?page=splynx_patch", null, [], $s->jars['acct']);
is_($r[0] === 403, 'splynx_patch: 403 for an accountant', (string)$r[0]);
$s->stop();

echo "\n3. Control: the live code\n";
if (isset($opt['no-control'])) { $skip++; echo "  skip (--no-control)\n"; }
else {
    [$base, $why] = sj_baseline_tree('f4b4c96');
    if ($base === null) { $skip++; echo "  skip {$why}\n"; }
    else {
        $b = SjSandbox::start($base, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'], 'sjsplynxb');
        $b->http('GET', "{$b->base}?page=login");
        is_($has($b, 'splynx_key'), 'f4b4c96: the first request writes the code\'s Splynx key into the configuration (value not printed)');
        $b->stop();
    }
}

echo "\n{$pass} passed, {$fail} failed, {$skip} skipped\n";
exit($fail === 0 ? 0 : 1);

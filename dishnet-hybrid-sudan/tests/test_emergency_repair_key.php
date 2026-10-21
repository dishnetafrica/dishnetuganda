<?php
declare(strict_types=1);
/**
 * test_emergency_repair_key.php — 5.18.95 (K2): the emergency repair page has no built-in key. It works only with the
 * one line in data/emergency_repair_key.txt, at least 32 printable characters; otherwise every request gets one 403.
 *
 * Proved over HTTP on the sandbox, anonymously, as the page is reached. Every key here is invented and nothing leaves
 * the machine:
 *   1. no key file: 403 for the old built-in key, an empty key and no key; nothing on the page runs;
 *   2. unusable key files — the old key, a short key, a key with a space — are refused the same way;
 *   3. a usable key: a wrong key and a non-string key get the same 403; the right key reaches the page as before;
 *   4. no answer prints the stored key; the runbook and the suspensions page no longer carry a key;
 *   5. the live code (f4b4c96) opens the page with the old built-in key, so these checks can fail; skipped, never
 *      passed, when Git cannot supply that tree;
 *   6. four weakened copies of the check — no length rule, no refusal of the old key, a disabled page that still
 *      compares, the stored key printed again — each make this test fail.
 *
 *   php test_emergency_repair_key.php [--root=DIR] [--no-control] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-control', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0; $skip = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const OLD_KEY  = 'DISHNET_' . 'REPAIR';                      // split so a grep for the burned key finds only the refusal
const GOOD_KEY = 'sj-repair-key-0123456789-abcdefghijklmnop';  // invented, 41 printable characters
$repair = function (SjSandbox $s, string $qs): array { return $s->http('GET', "{$s->base}?page=emergency_repair{$qs}"); };
$keyFile = function (SjSandbox $s, ?string $line): void {
    $f = $s->data . '/emergency_repair_key.txt';
    if ($line === null) { @unlink($f); return; }
    file_put_contents($f, $line . "\n");
};
$opened = function (array $r): bool { return $r[0] === 200 && strpos($r[1], '=== DishNet Emergency Repair ===') !== false; };
$refused = function (array $r): bool {
    return $r[0] === 403 && strpos($r[1], '=== DishNet Emergency Repair ===') === false
        && strpos($r[1], 'disabled, or the key is wrong') !== false;
};

$s = SjSandbox::start($root, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'], 'sjrepair');
$s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);

echo "1. No key file\n";
$keyFile($s, null);
foreach (['&key=' . OLD_KEY => 'the old built-in key', '&key=' => 'an empty key', '' => 'no key',
          '&key=' . OLD_KEY . '&action=clear_wal' => 'the old key with clear_wal'] as $qs => $label) {
    $r = $repair($s, $qs);
    is_($refused($r), "{$label}: 403, the page does not run", $r[0] . ' ' . substr($r[1], 0, 120));
}

echo "\n2. Unusable key files are refused the same way\n";
foreach ([OLD_KEY => 'the old key', strtolower(OLD_KEY) . str_repeat('x', 30) => 'a long key containing the old one',
          substr(GOOD_KEY, 0, 31) => 'a 31-character key', 'sj repair key with spaces 0123456789' => 'a key with spaces'] as $line => $label) {
    $keyFile($s, $line);
    $r = $repair($s, '&key=' . rawurlencode($line));
    is_($refused($r), "{$label}, given exactly: 403", (string)$r[0]);
    is_(strpos($r[1], $line) === false, "{$label}: the answer does not print it");
}

echo "\n3. A usable key\n";
$keyFile($s, GOOD_KEY);
$r = $repair($s, '&key=' . substr(GOOD_KEY, 0, -1) . 'Z');
is_($refused($r), 'a wrong key: 403, the same answer as a disabled page', (string)$r[0]);
$r = $repair($s, '&key[]=' . GOOD_KEY);
is_($refused($r), 'a key given as a list: 403', (string)$r[0]);
$r = $repair($s, '&key=' . GOOD_KEY);
is_($opened($r), 'the right key opens the page (diagnose, read-only)', $r[0] . ' ' . substr($r[1], 0, 120));
is_(strpos($r[1], 'key=YOUR-KEY') !== false, 'its action lines show key=YOUR-KEY');
foreach (['retailers', 'fin_audit', 'RETAILERS', 'cb_ledger'] as $t) {
    $d = $repair($s, '&key=' . GOOD_KEY . '&action=drop_table&t=' . $t . '&confirm=YES_DROP_' . $t);
    is_($d[0] === 200 && strpos($d[1], 'REFUSED') !== false && strpos($d[1], 'Dropping table') === false,
        "drop_table refuses {$t}, whatever its case", substr($d[1], -200));
}

echo "\n4. No answer carries the stored key\n";
is_(strpos($r[1], GOOD_KEY) === false, 'the opened page does not print the stored key');
$jar = $s->login('admin', 'admin@example.test', 'sj-password-1');
$rb = $s->page('admin', 'page=dashboard&tab=runbook');
is_(strpos($rb, 'emergency_repair') !== false, 'the runbook still explains the repair page (control)');
is_(strpos($rb, OLD_KEY) === false && strpos($rb, 'key=YOUR-KEY') !== false, 'the runbook shows key=YOUR-KEY, not a key');
$sus = (string)file_get_contents($root . '/tabs/admin/starlink_suspensions.php');
is_(strpos($sus, OLD_KEY) === false && strpos($sus, 'emergency_repair&key') === false, 'the suspensions page links no key');
$s->stop();

echo "\n5. Control: the live code\n";
if (isset($opt['no-control'])) {
    $skip++; echo "  skip (--no-control)\n";
} else {
    [$base, $why] = sj_baseline_tree('f4b4c96');
    if ($base === null) { $skip++; echo "  skip {$why}\n"; }
    else {
        $b = SjSandbox::start($base, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'], 'sjrepairb');
        $r = $repair($b, '&key=' . OLD_KEY);
        is_($opened($r), 'f4b4c96, no key file: the old built-in key opens the page (the hole these checks close)', (string)$r[0]);
        is_(!$refused($r), 'so the refusal check above can fail');
        $b->stop();
    }
}

if (!isset($opt['no-mutants'])) {
    echo "\n6. Weakened copies, each caught\n";
    $mutants = [
        ['no length rule', '$keyUsable = strlen($storedKey) >= 32 &&', '$keyUsable = strlen($storedKey) >= 0 &&'],
        ['the old key not refused', '&& stripos($storedKey, \'DISHNET_\' . \'REPAIR\') === false;', '&& true;'],
        ['a disabled page still compares', 'if (!$keyUsable || !is_string($givenKey)', 'if (!is_string($givenKey)'],
        ['the stored key printed again', 'key=YOUR-KEY&action=clear_wal', 'key={$storedKey}&action=clear_wal'],
    ];
    foreach ($mutants as [$label, $old, $new]) {
        $old = str_replace("'DISHNET_' . 'REPAIR'", "'" . OLD_KEY . "'", $old);
        [$tmp, $n] = sj_weakened_copy($root, 'public.php', $old, $new);
        if ($n !== 1) { is_(false, "weakened copy: {$label}", "anchor found {$n} times"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        $out = []; $rc = 0;
        exec(sprintf('%s %s --root=%s --no-control --no-mutants 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__),
            escapeshellarg($tmp)), $out, $rc);
        $fails = count(preg_grep('/^\s+FAIL /', $out));
        is_($rc !== 0 && $fails > 0, "weakened copy caught: {$label} ({$fails} checks failed)", 'rc=' . $rc);
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

echo "\n{$pass} passed, {$fail} failed, {$skip} skipped\n";
exit($fail === 0 ? 0 : 1);

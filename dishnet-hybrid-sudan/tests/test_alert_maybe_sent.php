<?php
declare(strict_types=1);
/**
 * test_alert_maybe_sent.php — 5.18.54, docs/46 row 44 (N-17): an administrator alert that may have reached the phone is
 * not sent again at the next run.
 *
 * AlertService takes its cooldown before the send and releases it when the send fails, so that the next run tries
 * again. Since row 31 a failed send says whether it may nevertheless have gone ("May have been sent — …", or a 502 or
 * 504 on the POST). Released after one of those, the alert went out again at the next run of whatever raised it: the
 * duplicate row 31 stopped for customer messages, on the administrator's phone.
 *
 *    1. Uganda: an alert that may have gone keeps its cooldown; one that certainly did not is released, as before
 *    2. South Sudan: released in every case, as in 5.18.53
 *    3. weakened copies, each caught
 *
 * The real AlertService, from the plugin tree, against a fake Evolution (tests/fixtures/alert_maybe_sent_probe.php).
 * Nothing leaves the machine. `--no-mutants` skips 3.
 */
$root = dirname(__DIR__);
$withMutants = !in_array('--no-mutants', $argv, true);

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const AM_MAYBE   = 'May have been sent — no answer from Evolution after the request left: Operation timed out';
const AM_502     = 'Evolution API error [HTTP 502 on POST /message/sendText/sj-sales]';
const AM_NOTSENT = 'Not sent — could not connect to Evolution: Connection refused';
const AM_REFUSED = 'Evolution API error [HTTP 400 on POST /message/sendText/sj-sales]';

/** Two alerts with one key through the tree's AlertService; the first send fails with $error. */
function am_probe(string $tree, string $tenant, string $error): array
{
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/alert_maybe_sent_probe.php') . ' '
        . escapeshellarg($tree) . ' ' . escapeshellarg($tenant) . ' ' . escapeshellarg($error) . ' 2>&1', $out);
    $j = json_decode((string)end($out), true);
    return is_array($j) ? $j : ['raw' => implode("\n", $out)];
}
function am_show(array $p): string { return substr((string)json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 500); }

/** What the second alert did: 'cooldown' (kept) or 'sent' (released and sent again). */
function am_second(array $p): string
{
    return (string)($p['second']['reason'] ?? '?') . '/' . (int)($p['calls'] ?? -1);
}

// ── The cases, as functions of the plugin tree, so a weakened copy runs the very same ones ─────────────────────────
$caseUganda = function (string $tree): array {
    $out = [];
    foreach (['may have been sent' => AM_MAYBE, 'a 502 on the POST' => AM_502] as $what => $err) {
        $p = am_probe($tree, 'uganda', $err);
        $out[] = [($p['first']['sent'] ?? true) === false && am_second($p) === 'cooldown/1',
                  "{$what}: the cooldown is kept — the second alert is not sent (one request to Evolution)", am_show($p)];
    }
    foreach (['certainly not sent' => AM_NOTSENT, 'refused' => AM_REFUSED] as $what => $err) {
        $p = am_probe($tree, 'uganda', $err);
        $out[] = [am_second($p) === 'sent/2' && ($p['second']['sent'] ?? false) === true,
                  "{$what}: released, so the next alert goes, as before (two requests)", am_show($p)];
    }
    return $out;
};
$caseSouthSudan = function (string $tree): array {
    $out = [];
    foreach (['may have been sent' => AM_MAYBE, 'certainly not sent' => AM_NOTSENT] as $what => $err) {
        $p = am_probe($tree, 'south-sudan', $err);
        $out[] = [am_second($p) === 'sent/2', "{$what}: released, as in 5.18.53 (two requests)", am_show($p)];
    }
    return $out;
};

/** A copy of what the probe loads: lib/ and the tenant profiles. */
function am_tree(string $root): string
{
    $t = sys_get_temp_dir() . '/am-tree-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($t, 0700, true);
    foreach (['lib', 'profiles'] as $d) exec('cp -R ' . escapeshellarg($root . '/' . $d) . ' ' . escapeshellarg($t . '/'));
    return $t;
}

// ══════════════════════════════════════════════════════════════════════════════
echo "\n1. Uganda — an alert that may have gone is not sent again\n";
foreach ($caseUganda($root) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n2. South Sudan — released in every case, as in 5.18.53\n";
foreach ($caseSouthSudan($root) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n3. Weakened copies, each caught\n";
$failedWhere = function (array $triples, string $what): bool {
    foreach ($triples as [$ok, $m]) if (!$ok && strpos($m, $what) !== false) return true;
    return false;
};
$mutants = [
    'the cooldown always released' => ['lib/AlertService.php',
        "            return NotifyGate::applies(NotifyGate::EVO_RETRY, \$this->config, \$dir) && EvolutionApiService::mayHaveBeenSent(\$r);\n",
        "            return false;\n",
        fn(string $t) => $failedWhere($caseUganda($t), 'may have been sent: the cooldown is kept'), 'the alert that may have gone was sent again'],
    'the cooldown kept after any failure' => ['lib/AlertService.php',
        " && EvolutionApiService::mayHaveBeenSent(\$r);\n", ";\n",
        fn(string $t) => $failedWhere($caseUganda($t), 'certainly not sent: released'), 'an alert that never left was not tried again'],
    'not gated' => ['lib/AlertService.php',
        "            return NotifyGate::applies(NotifyGate::EVO_RETRY, \$this->config, \$dir) && ", "            return ",
        fn(string $t) => $failedWhere($caseSouthSudan($t), 'may have been sent: released'), 'South Sudan kept the cooldown'],
];
foreach ($withMutants ? $mutants : [] as $name => [$rel, $old, $new, $caught, $why]) {
    $t = am_tree($root);
    $src = (string)file_get_contents($t . '/' . $rel);
    if (substr_count($src, $old) !== 1) {
        is_(false, "caught: {$name}", "the anchor was not found exactly once in {$rel}");
    } else {
        file_put_contents($t . '/' . $rel, str_replace($old, $new, $src));
        $ok = (bool)$caught($t);
        is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''));
    }
    exec('rm -rf ' . escapeshellarg($t));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

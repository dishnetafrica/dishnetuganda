<?php
declare(strict_types=1);
/**
 * test_starlink_notice_number.php — 5.18.54, docs/46 row 45 (N-18): the Starlink mail worker's customer WhatsApps
 * ("order confirmed", "shipped", "active") go to the number in international form.
 *
 * The worker sends through Evolution itself, not through the notifier, so row 14's rule (D-10: international form
 * before WhatsApp) never reached it. It passed on the number as uCRM stores it. Measured before the fix: a number stored
 * as "0772 000 001" was sent as given, which Evolution reduces to 0772000001, not a WhatsApp address, and the event
 * was recorded as notified.
 *
 *    1. Uganda: a local number, and one already international, go in international form; a number with no
 *       international form is not sent to
 *    2. South Sudan: the number as uCRM stores it, as in 5.18.53
 *    3. weakened copies, each caught
 *
 * The real StarlinkMailWorker, from the plugin tree, against fakes for uCRM, Evolution and the classifier
 * (tests/fixtures/starlink_notice_probe.php). Nothing leaves the machine. `--no-mutants` skips 3.
 */
$root = dirname(__DIR__);
$withMutants = !in_array('--no-mutants', $argv, true);

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

/** One "order confirmed" e-mail through the tree's worker, for a customer whose number uCRM stores as $phone. */
function sn_probe(string $tree, string $tenant, string $phone): array
{
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/starlink_notice_probe.php') . ' '
        . escapeshellarg($tree) . ' ' . escapeshellarg($tenant) . ' ' . escapeshellarg($phone) . ' 2>&1', $out);
    $j = json_decode((string)end($out), true);
    return is_array($j) ? $j : ['raw' => implode("\n", $out)];
}
function sn_show(array $p): string { return substr((string)json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0, 400); }

// ── The cases, as functions of the plugin tree, so a weakened copy runs the very same ones ─────────────────────────
$caseUganda = function (string $tree): array {
    $out = [];
    foreach (['0772 000 001' => '256772000001', '+256 772 000 002' => '256772000002'] as $stored => $want) {
        $p = sn_probe($tree, 'uganda', $stored);
        $out[] = [($p['sent_to'] ?? null) === [$want] && ($p['result'] ?? '') === 'notified',
                  "stored as \"{$stored}\": sent to {$want}", sn_show($p)];
    }
    $p = sn_probe($tree, 'uganda', '12345');
    $out[] = [($p['sent_to'] ?? null) === [] && ($p['result'] ?? '') === 'events',
              'a number with no international form: nothing sent, and the event is not recorded as notified', sn_show($p)];
    return $out;
};
$caseSouthSudan = function (string $tree): array {
    $p = sn_probe($tree, 'south-sudan', '0912 000 001');
    return [[($p['sent_to'] ?? null) === ['0912 000 001'] && ($p['result'] ?? '') === 'notified',
             'the number as uCRM stores it, as in 5.18.53', sn_show($p)]];
};

/** A copy of what the probe loads: lib/, workers/, migrations/ and the tenant profiles. */
function sn_tree(string $root): string
{
    $t = sys_get_temp_dir() . '/sn-tree-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($t, 0700, true);
    foreach (['lib', 'workers', 'migrations', 'profiles'] as $d) exec('cp -R ' . escapeshellarg($root . '/' . $d) . ' ' . escapeshellarg($t . '/'));
    return $t;
}

// ══════════════════════════════════════════════════════════════════════════════
echo "\n1. Uganda — the number in international form\n";
foreach ($caseUganda($root) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n2. South Sudan — the number as uCRM stores it, as in 5.18.53\n";
foreach ($caseSouthSudan($root) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n3. Weakened copies, each caught\n";
$failedWhere = function (array $triples, string $what): bool {
    foreach ($triples as [$ok, $m]) if (!$ok && strpos($m, $what) !== false) return true;
    return false;
};
$mutants = [
    'the number passed on as stored' => ['workers/StarlinkMailWorker.php',
        "            \$phone = \$this->internationalUg(\$phone);\n", '',
        fn(string $t) => $failedWhere($caseUganda($t), 'stored as "0772 000 001"'), 'the local form was sent'],
    'a number with no international form sent anyway' => ['workers/StarlinkMailWorker.php',
        "            return \$intl === null ? '' : substr(\$intl, 1);\n", "            return \$intl === null ? \$phone : substr(\$intl, 1);\n",
        fn(string $t) => $failedWhere($caseUganda($t), 'no international form'), '12345 was sent to'],
    'not gated' => ['workers/StarlinkMailWorker.php',
        "            if (!\\NotifyGate::applies(\\NotifyGate::PHONE_FORM, \$this->config, \$dd)) return \$phone;\n", '',
        fn(string $t) => $failedWhere($caseSouthSudan($t), 'as in 5.18.53'), 'South Sudan\'s number was rewritten'],
];
foreach ($withMutants ? $mutants : [] as $name => [$rel, $old, $new, $caught, $why]) {
    $t = sn_tree($root);
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

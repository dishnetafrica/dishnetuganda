<?php
declare(strict_types=1);
/**
 * test_notify_ladder_record.php — 5.18.54, docs/46 rows 23 (D-5) and 40 (N-11): the overdue e-mail ladder sends from its
 * own run, and records a success that follows a failure.
 *
 * Row 40: the ladder's SMTP sender announces itself with MailService::ehloName(), and nothing on the ladder's own path
 * loads MailService. Run the way main.php's tick runs it, every e-mail failed after connecting.
 *
 * Row 23: for stages 1-8 the ladder's log row is written with INSERT OR IGNORE under a unique (invoice, stage). When the
 * first attempt fails, its row says success=0; the attempt that later goes is IGNORED, so the "already sent" check never
 * finds a success, and the stage goes again on every run.
 *
 * The real cron_overdue_email.php, run three times in the sandbox (tests/fixtures/staff_jobs_sandbox.php) against a
 * fake uCRM holding one invoice 20 days overdue (stage 1, e-mail), and the fake SMTP relay
 * (tests/fixtures/fake_smtp_server.php):
 *
 *   run 1 — the relay is down: the e-mail fails, and its row says so
 *   run 2 — the relay is up: the e-mail goes (control: a failure is retried)
 *   run 3 — Uganda: nothing goes, the stage is recorded as sent
 *
 * South Sudan keeps 5.18.53 (§E): run on its own, its run 2 fails on the missing class; run with MailService loaded
 * first, as a cycle from public.php has it (tests/fixtures/run_with_mail_class.php), its run 3 sends again.
 *
 * Plus weakened copies, each caught (skipped with --no-mutants). Every person, address and document is fictitious;
 * nothing leaves the machine.
 */
$root = dirname(__DIR__);
$withMutants = !in_array('--no-mutants', $argv, true);
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const LR_CLASS_ERROR = 'Class "MailService" not found';

/** A port nothing listens on. */
function closed_port(): int
{
    foreach (range(0, 30) as $i) {
        $p = 9700 + ((getmypid() + $i * 17) % 80);
        $c = @fsockopen('127.0.0.1', $p, $e1, $e2, 0.3);
        if (!$c) return $p;
        fclose($c);
    }
    return 1;
}
/** The fake relay, up: [process, port, transcript]. */
function relay_start(string $dir): array
{
    foreach (range(0, 9) as $slot) {
        $port = 9790 + ((getmypid() + $slot * 13) % 60);
        $tr = "{$dir}/smtp_{$port}.json";
        @unlink($tr);
        $p = proc_open(sprintf('exec php %s %d %s 120', escapeshellarg(dirname(__DIR__) . '/tests/fixtures/fake_smtp_server.php'), $port, escapeshellarg($tr)),
                       [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 40 && !is_file($tr); $i++) usleep(50000);
        if (is_file($tr)) return [$p, $port, $tr];
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0, ''];
}
/** How many messages the relay took for this address: a recipient and a body, not a connection that stopped early. */
function relayed(string $tr, string $to): int
{
    $n = 0;
    foreach ((array)(json_decode((string)@file_get_contents($tr), true) ?: []) as $session) {
        if (in_array($to, (array)($session['rcpt_to'] ?? []), true) && trim((string)($session['data'] ?? '')) !== '') $n++;
    }
    return $n;
}

/** Three runs of the ladder on $tree, as described above; $preload runs each with MailService loaded first. */
$ladder = function (string $tree, string $tenant, string $tag, bool $preload = false): array {
    $zone = $tenant === 'uganda' ? 'Africa/Kampala' : 'Africa/Juba';
    $base = $tenant === 'uganda' ? ['tenant_profile' => 'uganda', 'timezone' => $zone] : ['timezone' => $zone];
    $s = SjSandbox::start($tree, $base, $tag);
    $due = (new DateTime('now', new DateTimeZone($zone)))->modify('-20 days')->format('Y-m-d') . 'T00:00:00+0300';
    $s->seedCrm(SjScenario::crm() + ['invoices' => ['7001' => ['id' => 7001, 'number' => 'INV-7001', 'clientId' => 15,
        'status' => 1, 'total' => 100000.0, 'amountToPay' => 100000.0, 'dueDate' => $due, 'currencyCode' => 'UGX']]]);
    $smtp = function (int $port) use ($s): void {
        file_put_contents($s->data . '/email_settings.json', json_encode(['smtp_host' => '127.0.0.1', 'smtp_port' => $port,
            'smtp_enc' => '', 'smtp_user' => '', 'smtp_pass' => '', 'smtp_from' => 'accounts@example.test']));
    };
    $row = function () use ($s): array {
        return $s->q("SELECT success, COALESCE(error, '') AS error FROM overdue_email_log WHERE invoice_number = 'INV-7001' AND stage = 1");
    };
    $run = function () use ($s, $preload): array {
        return $preload ? $s->run(__DIR__ . '/fixtures/run_with_mail_class.php', ['cron_overdue_email.php'])
                        : $s->run('cron_overdue_email.php');
    };
    $o = [];
    $smtp(closed_port());
    [$o['rc1'], $o['out1']] = $run();
    $o['row1'] = $row();
    [$relay, $port, $tr] = relay_start($s->data);
    $o['relay'] = $port;
    $smtp($port);
    [$o['rc2'], $o['out2']] = $run();
    $o['sent2'] = relayed($tr, 'customer@example.test');
    $o['row2'] = $row();
    [$o['rc3'], $o['out3']] = $run();
    $o['sent3'] = relayed($tr, 'customer@example.test');
    $o['row3'] = $row();
    if (is_resource($relay)) { proc_terminate($relay); proc_close($relay); }
    $s->stop();
    return $o;
};
$tail = function (string $out): string { return mb_substr($out, -400); };
$classErr = function (string $out): bool { return strpos($out, LR_CLASS_ERROR) !== false; };

// ══════════════════════════════════════════════════════════════════════════════
echo "\n1. Uganda, the cron on its own (as main.php's tick runs it)\n";
$u = $ladder($root, 'uganda', 'lr-ug');
is_($u['relay'] > 0, 'the fake relay started');
is_(count($u['row1']) === 1 && (int)$u['row1'][0]['success'] === 0 && strpos($u['row1'][0]['error'], 'Connect failed') === 0,
    'run 1, relay down: the e-mail failed and its row says so', json_encode($u['row1']) . ' ' . $tail($u['out1']));
is_($u['sent2'] === 1 && !$classErr($u['out2']), 'run 2, relay up: the stage goes, one message (row 40: the class is there)', $tail($u['out2']));
is_(count($u['row2']) === 1 && (int)$u['row2'][0]['success'] === 1 && $u['row2'][0]['error'] === '',
    'and its row now says it went — one row, no error (row 23)', json_encode($u['row2']));
is_($u['sent3'] === 1 && strpos($u['out3'], 'EMAIL stage 1') === false && strpos($u['out3'], 'already_sent_stage') !== false,
    'run 3: nothing goes again — the stage is skipped as sent', $tail($u['out3']));

echo "\n2. South Sudan, the cron on its own — as in 5.18.53: the e-mail fails on the missing class (docs/46 §E)\n";
$so = $ladder($root, 'south-sudan', 'lr-so');
is_((int)($so['row1'][0]['success'] ?? -1) === 0, 'run 1 fails with the relay down, as on Uganda', json_encode($so['row1']));
is_($so['sent2'] === 0 && $classErr($so['out2']), 'run 2, relay up: nothing is relayed — ' . LR_CLASS_ERROR, $tail($so['out2']));

echo "\n3. South Sudan, MailService loaded first (a cycle from public.php) — as in 5.18.53: the stage goes again (§E)\n";
$ss = $ladder($root, 'south-sudan', 'lr-ss', true);
is_((int)($ss['row1'][0]['success'] ?? -1) === 0 && $ss['sent2'] === 1, 'run 1 fails and run 2 sends', json_encode([$ss['row1'], $ss['sent2']]) . ' ' . $tail($ss['out2']));
is_((int)($ss['row2'][0]['success'] ?? -1) === 0 && $ss['sent3'] === 2, 'the row still says failed, and run 3 sends it again', json_encode([$ss['row2'], $ss['sent3']]));

// ══════════════════════════════════════════════════════════════════════════════
echo "\n4. Weakened copies, each caught\n";
$mutants = [
    'the success not recorded' => ['cron_overdue_email.php',
        "        if (NotifyGate::applies(NotifyGate::LADDER_RECORD, is_array(\$config ?? null) ? \$config : [], \$dataDir ?? null)) {\n",
        "        if (false) {\n",
        function (array $x) { return $x['sent2'] === 1 && $x['sent3'] === 2; }, 'run 3 sent the stage again'],
    'a failure recorded as a success' => ['cron_overdue_email.php',
        "    if (\$ok && (int)\$matchedStage['id'] !== 9) {\n",
        "    if ((int)\$matchedStage['id'] !== 9) {\n",
        function (array $x) { return (int)($x['row1'][0]['success'] ?? 0) === 1 && $x['sent2'] === 0; }, 'the failed e-mail was never retried'],
    'the class not loaded' => ['lib/OverdueDunningHelpers.php',
        "            if (NotifyGate::applies(NotifyGate::MAIL_CLASS, _dunningEffectiveConfig(), is_string(\$dd) ? \$dd : null)) {\n",
        "            if (false) {\n",
        function (array $x) use ($classErr) { return $x['sent2'] === 0 && $classErr($x['out2']); }, 'run 2 failed on the missing class'],
];
foreach ($withMutants ? $mutants : [] as $name => [$rel, $o_, $n_, $caught, $why]) {
    [$tree, $n] = sj_weakened_copy($root, $rel, $o_, $n_);
    if ($n !== 1) { is_(false, "caught: {$name}", "the anchor was not found exactly once in {$rel}"); exec('rm -rf ' . escapeshellarg($tree)); continue; }
    $x = $ladder($tree, 'uganda', 'lr-wk' . substr(md5($name), 0, 6));
    $ok = (bool)$caught($x);
    is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''), json_encode(['row1' => $x['row1'], 'sent2' => $x['sent2'], 'sent3' => $x['sent3']]) . ' ' . $tail($x['out2']));
    exec('rm -rf ' . escapeshellarg($tree));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

<?php
declare(strict_types=1);
/**
 * Rehearses scripts/job-walkthrough.sh (docs/44 §16.25) against the plugin's own sandbox: the plugin served on
 * 127.0.0.1, a fake uCRM that sends the plugin its job webhooks a second after each change (fake_ucrm_emit.php), the
 * repository's fake WhatsApp (Evolution) and fake mail relay. The script runs unchanged, answering its questions from
 * here; a stand-in `docker` runs its helper with this machine's PHP. Everything is fictitious; nothing leaves the host.
 *
 *   php rehearse.php [script] [scenarios]   — script defaults to the repository's; scenarios is a comma list (S1,…)
 * Prints one line per assertion and "rehearsal: P passed, F failed"; exits 1 on any failure.
 */
$repo   = dirname(__DIR__, 3);
$plugin = $repo . '/dishnet-hybrid-sudan';
$script = $argv[1] ?? $repo . '/scripts/job-walkthrough.sh';
$only   = array_filter(explode(',', (string)($argv[2] ?? '')));
require $plugin . '/tests/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $what, string $why = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $what\n"; } else { $fail++; echo "  FAIL $what" . ($why !== '' ? " — $why" : '') . "\n"; }
}
function want(string $id): bool { global $only; return $only === [] || in_array($id, $only, true); }

/** A scratch plugin tree: the plugin, with the emitting fake uCRM over the repository's; optionally another webhook.php. */
function wt_root(string $plugin, ?string $webhook = null): string
{
    $tmp = sys_get_temp_dir() . '/wt-root-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($tmp, 0700, true);
    exec('tar -C ' . escapeshellarg($plugin) . ' --exclude=./docs --exclude=./prototype --exclude=./dishnet-mikrotik-control-plane'
        . ' --exclude=./data --exclude=./.git -cf - . | tar -C ' . escapeshellarg($tmp) . ' -xf -');
    $fx = $tmp . '/tests/fixtures';
    rename("$fx/fake_ucrm_staff_jobs.php", "$fx/fake_ucrm_staff_jobs_orig.php");
    copy(__DIR__ . '/fake_ucrm_emit.php', "$fx/fake_ucrm_staff_jobs.php");
    if ($webhook !== null) file_put_contents("$tmp/webhook.php", $webhook);
    register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });
    return $tmp;
}

/** Uganda, the technician (uCRM user 1099) with a verified link, the customer (client 15) with an e-mail, a mail relay. */
function wt_sandbox(string $root, string $tag, array $cfg = []): SjSandbox
{
    $s = SjSandbox::start($root, $cfg + ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'contact_support_phone' => '+256 700 000 100',
        'email_reply_to' => 'jobs-reply@example.test', 'customer_emails_enabled' => '1', 'customer_email_install_scheduled' => '1'], $tag);
    file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
    $s->seedCrm(SjScenario::crm());
    SjScenario::staff($s);
    $s->mailRelay(900);
    file_put_contents($s->sb . '/webhook_url.txt', $s->base . '?page=crm_webhook');
    return $s;
}

/**
 * The script, run as the operator would, its questions answered by $answer(prompt, transcript) → the line typed.
 * @return array{0:int,1:string,2:string} exit code, what the terminal showed, the log file's content
 */
function wt_run(string $script, SjSandbox $s, array $args, callable $answer, int $timeout = 300, ?string $inContainer = null): array
{
    $bin = $s->sb . '/bin'; @mkdir($bin, 0700, true);
    file_put_contents("$bin/docker", "#!/bin/bash\n# rehearsal stand-in: docker exec [-i] [-u U] CONTAINER cmd… runs cmd here\n"
        . "[ \"\$1\" = exec ] || { echo 'stand-in docker: exec only' >&2; exit 9; }\nshift\n"
        . "while [ \$# -gt 0 ]; do case \"\$1\" in -i) shift ;; -u) shift 2 ;; *) break ;; esac; done\nshift\nexec \"\$@\"\n");
    chmod("$bin/docker", 0755);
    $out = $s->sb . '/out'; @mkdir($out, 0700, true);
    $env = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $env = array_merge($env, ['PATH' => $bin . ':' . getenv('PATH'), 'IN_CONTAINER' => $inContainer ?? $s->plug, 'OUT' => $out,
        'DN_VAULT_FILE' => $s->vault, 'DN_DATA_DIR' => $s->data, 'UCRM_CONTAINER' => 'ucrm']);
    $p = proc_open(array_merge(['bash', $script], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $buf = ''; $t0 = time(); $asked = 0; $rc = -1; $marker = '[Enter = yes, s = skip, q = stop]: ';
    while (true) {
        $r = [$pipes[1], $pipes[2]]; $w = null; $e = null;
        if (@stream_select($r, $w, $e, 1) > 0) foreach ($r as $fh) { $c = fread($fh, 65536); if (is_string($c)) $buf .= $c; }
        if (substr($buf, -strlen($marker)) === $marker && substr_count($buf, $marker) > $asked) {
            $asked++;
            $pos = strrpos($buf, '→ ');
            $prompt = $pos === false ? '' : trim(substr($buf, $pos + strlen('→ '), strrpos($buf, $marker) - $pos - strlen('→ ')));
            fwrite($pipes[0], $answer($prompt, $buf) . "\n");
        }
        $st = proc_get_status($p);
        if (!$st['running']) {
            $rc = (int)$st['exitcode'];
            usleep(300000);
            foreach ([$pipes[1], $pipes[2]] as $fh) { while (is_string($c = fread($fh, 65536)) && $c !== '') $buf .= $c; }
            break;
        }
        if (time() - $t0 > $timeout) { proc_terminate($p); $buf .= "\n[rehearsal: the script timed out]\n"; break; }
    }
    foreach ($pipes as $fh) @fclose($fh);
    proc_close($p);
    if (getenv('WT_SHOW')) echo "----- what the terminal showed -----\n", $buf, "\n----- end -----\n";
    $logs = glob($out . '/walkthrough-*.log') ?: [];
    sort($logs);
    return [$rc, $buf, $logs ? (string)file_get_contents(end($logs)) : ''];
}

/** The fixtures' personal data: none of it may be printed. */
const WT_SECRETS = ['+256700000111', '256700000111', 'tech@example.test', '+256700000915', '256700000915', 'customer@example.test',
                    'Sandbox Tech', 'Sandbox Customer', 'admin@example.test'];
function wt_leaks(string $text): array { return array_values(array_filter(WT_SECRETS, function ($x) use ($text) { return strpos($text, $x) !== false; })); }

$jobTexts = function (SjSandbox $s): array {
    return array_values(array_filter($s->texts(), function ($t) { return ($t['number'] ?? '') === '256700000111'; }));
};
$posts = function (SjSandbox $s): int { return count($s->crmReqs('POST', '#^/scheduling/jobs$#')); };

// ═════════════════════════════════════════════════════════════════════════════
if (want('S1')) {
    echo "\nS1 every step, Enter to each question; the technician accepts at step 3\n";
    $s = wt_sandbox(wt_root($plugin), 'wt1');
    $job = 0;
    [$rc, $t, $log] = wt_run($script, $s, ['--client', '15', '--tech', '1099'], function (string $q, string $buf) use ($s, &$job): string {
        if (preg_match('/created: job #(\d+)/', $buf, $m)) $job = (int)$m[1];
        if (strpos($q, 'Press Enter once Accept has been pressed') === 0) {
            // What the job page does when the technician presses Accept (tabs/support/scheduling.php).
            $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => $job, 'status' => 'open', 'notify_accept' => 1]);
        }
        return '';
    });
    ok($rc === 0, 'the script ends with exit 0', "exit $rc");
    ok(strpos($t, "\nGO\n") === false && preg_match('/^  ok    this install reads Uganda/m', $t) === 1, 'the preflight reads Uganda, and GO is not printed as a line of its own');
    ok($job > 0, 'step 1 created a job and said its number', (string)$job);
    ok(preg_match_all('/^  ✓ as expected: .*, and the e-mail was handed to the mail server$/m', $t) === 6,
        'all six steps say "✓ as expected", the WhatsApp and the e-mail', (string)preg_match_all('/^  ✓ as expected/m', $t));
    ok(preg_match_all('/^  step \d [a-z ]+: ✓ as expected/m', $t) === 6, 'and the summary lists the six');
    $tx = array_map(function ($x) { return (string)$x['text']; }, $jobTexts($s));
    $want = ['New Job Has Been Assigned to You', "has a new time", 'Thank you for accepting the job!', 'is no longer assigned to you',
             'New Job Has Been Assigned to You', 'has been cancelled'];
    $got = []; foreach ($tx as $x) foreach ($want as $w) if (strpos($x, $w) !== false) { $got[] = $w; break; }
    ok($got === $want, 'the technician received exactly the six WhatsApp messages, in order', json_encode($got));
    $mails = array_values(array_filter($s->mails(), function ($m) { return $m['to'] === ['tech@example.test']; }));
    ok(count($mails) === 6, 'and six e-mails with the same texts', (string)count($mails));
    $cm = array_values(array_filter($s->mails(), function ($m) { return $m['to'] === ['customer@example.test']; }));
    ok(count($cm) === 1, 'the customer received one "installation booked" e-mail, at step 1', (string)count($cm));
    $ev = array_column($s->q('SELECT event FROM job_notify_events WHERE job_id = ? ORDER BY id', [$job]), 'event');
    ok($ev === ['assigned', 'rescheduled', 'accepted', 'unassigned', 'assigned', 'cancelled'], "the job's history holds the six events", json_encode($ev));
    $dump = $s->crmDump();
    ok(!isset($dump['jobs'][(string)$job]), 'the test job is gone from uCRM at the end');
    $made = array_values(array_filter($dump['requests'] ?? [], function ($r) { return $r['method'] === 'POST' && $r['path'] === '/scheduling/jobs'; }));
    ok(count($made) === 1 && preg_match('/\(run wt-\d{8}T\d{6}Z-\d+\)/', (string)($made[0]['body']['description'] ?? '')) === 1,
        "one job was created, and its description carries the run's mark", json_encode($made[0]['body']['description'] ?? null));
    $changes = array_values(array_filter($dump['requests'] ?? [], function ($r) { return in_array($r['method'], ['PATCH', 'DELETE'], true) && strpos((string)$r['path'], '/scheduling/jobs/') === 0; }));
    ok(count($changes) === 5 && count(array_filter($changes, function ($r) use ($job) { return $r['path'] === "/scheduling/jobs/{$job}"; })) === 5,
        "uCRM saw five changes, every one to the test job: the time, the job page's Accept, take away, give back, delete", json_encode(array_column($changes, 'path')));
    ok(strpos($t, '[ConfigVault]') === false, "the plugin's routine vault notice is not shown");
    ok(strpos($t, 'log  ') !== false && strpos($t, 'history  assigned assigned ucrm_webhook sent email=sent') !== false,
        "it prints the plugin's log lines and the job's history");
    ok(strpos($t, '<e-mail>') !== false, "the customer e-mail's log line is printed with its address masked");
    ok(wt_leaks($t) === [], 'no name, number or e-mail of the fixtures is printed', json_encode(wt_leaks($t)));
    ok($log !== '' && strpos($log, '✓ as expected') !== false && wt_leaks($log) === [], 'the log file holds the run, masked too');
    ok(strpos($t, 'The test job #') === false, 'the summary does not say a test job was left behind');
    $s->stop();
}

if (want('S2')) {
    echo "\nS2 q at the first question: nothing is created\n";
    $s = wt_sandbox(wt_root($plugin), 'wt2');
    [$rc, $t] = wt_run($script, $s, ['--client', '15', '--tech', '1099'], function () { return 'q'; });
    ok($rc === 0 && strpos($t, 'Stopped. Nothing more is done.') !== false, 'it stops at once', "exit $rc");
    ok($posts($s) === 0 && count($jobTexts($s)) === 0, 'no job was created and nobody was messaged');
    $s->stop();
}

if (want('S3')) {
    echo "\nS3 not Uganda: NO-GO before anything\n";
    $s = wt_sandbox(wt_root($plugin), 'wt3', ['tenant_profile' => 'south_sudan', 'timezone' => 'Africa/Juba']);
    [$rc, $t] = wt_run($script, $s, ['--client', '15', '--tech', '1099'], function () { return ''; });
    ok($rc === 1 && strpos($t, 'NO-GO') !== false && preg_match('/^  NO    this install reads Uganda/m', $t) === 1, 'the preflight says NO-GO, naming the tenant', "exit $rc");
    ok($posts($s) === 0 && count($jobTexts($s)) === 0, 'no job was created and nobody was messaged');
    $s->stop();
}

if (want('S4')) {
    echo "\nS4 the technician's link is not verified: NO-GO\n";
    $s = wt_sandbox(wt_root($plugin), 'wt4');
    $s->update($s->ids['tech'], ['ucrm_link' => null]);
    [$rc, $t] = wt_run($script, $s, ['--client', '15', '--tech', '1099'], function () { return ''; });
    ok($rc === 1 && strpos($t, 'no active staff account has a verified link to uCRM user #1099') !== false, 'the preflight says why, and stops', "exit $rc");
    ok($posts($s) === 0, 'no job was created');
    $s->stop();
}

if (want('S5')) {
    echo "\nS5 the files on disk are 5.18.52 but the web server runs 5.18.51's webhook.php (docs/44 §16.23): step 1 says so,\n"
       . "   stops, and deletes the test job when asked\n";
    $old = (string)shell_exec('git -C ' . escapeshellarg($repo) . ' show 240f2f9:dishnet-hybrid-sudan/webhook.php 2>/dev/null');
    if ($old === '') { ok(false, "git could not supply 240f2f9's webhook.php"); }
    else {
        $s = wt_sandbox(wt_root($plugin, $old), 'wt5');
        // What the helper reads (the disk): 5.18.52, the same data directory. What the web server runs: 5.18.51's webhook.php.
        $disk = $s->sb . '/disk-view';
        exec('cp -R ' . escapeshellarg($s->plug) . ' ' . escapeshellarg($disk));
        copy($plugin . '/webhook.php', $disk . '/webhook.php');
        [$rc, $t] = wt_run($script, $s, ['--client', '15', '--tech', '1099'], function () { return ''; }, 300, $disk);
        ok(strpos($t, 'the installed plugin is 5.18.52, with the job notifier') !== false, 'the preflight passes: the disk has 5.18.52');
        ok($rc === 1 && strpos($t, "5.18.51's words: the web server is still running the old code") !== false, 'step 1 names the old code and stops', "exit $rc");
        ok(strpos($t, 'not switched on yet') !== false, "and shows the plugin's own line");
        ok(strpos($t, 'deleted.') !== false && count($s->crmReqs('DELETE', '#^/scheduling/jobs/\d+$#')) === 1, 'the test job was deleted when asked');
        ok(count($jobTexts($s)) === 0 && strpos($t, 'Step 2 of 6') === false, 'nobody was messaged, and no later step ran');
        $s->stop();

        echo "\nS5b 5.18.51's webhook.php on disk as well: the preflight says so and nothing is created\n";
        $s = wt_sandbox(wt_root($plugin, $old), 'wt5b');
        [$rc, $t] = wt_run($script, $s, ['--client', '15', '--tech', '1099'], function () { return ''; });
        ok($rc === 1 && strpos($t, 'WITHOUT the job notifier') !== false && strpos($t, 'NO-GO') !== false, 'NO-GO, naming the missing notifier', "exit $rc");
        ok($posts($s) === 0, 'no job was created');
        $s->stop();
    }
}

if (want('S6')) {
    echo "\nS6 a job this run did not create is refused, whatever its number\n";
    $s = wt_sandbox(wt_root($plugin), 'wt6');
    $src = (string)file_get_contents($script);
    $a = strpos($src, "read -r -d '' HELPER <<'PHP'\n"); $b = strpos($src, "\nPHP\n", (int)$a);
    $helper = $s->sb . '/helper.php';
    file_put_contents($helper, substr($src, $a + strlen("read -r -d '' HELPER <<'PHP'\n"), $b - $a - strlen("read -r -d '' HELPER <<'PHP'\n") + 1));
    $env = 'env DN_VAULT_FILE=' . escapeshellarg($s->vault) . ' DN_DATA_DIR=' . escapeshellarg($s->data) . ' ';
    $run = function (string $args) use ($env, $helper, $s) { return trim((string)shell_exec($env . 'php -- ' . escapeshellarg($s->plug) . ' ' . $args . ' < ' . escapeshellarg($helper) . ' 2>&1')); };
    $p = $run('patch 901 wt-20260101T000000Z-1 assignee=none');
    $d = $run('delete 901 wt-20260101T000000Z-1');
    ok(strpos($p, 'REFUSED') === 0 && strpos($d, 'REFUSED') === 0, 'a change and a delete of job 901 (not created by a walk-through) are refused', $p . ' / ' . $d);
    $j = $s->crmDump()['jobs']['901'] ?? null;
    ok(is_array($j) && (int)($j['assignedUserId'] ?? 0) === 1099 && count($s->crmReqs('PATCH', '#^/scheduling/jobs/901$#')) === 0, 'job 901 is untouched, and uCRM saw no change to it');
    $s->stop();
}

if (want('S7')) {
    echo "\nS7 steps 2 to 5 skipped: message 1, then the cancellation, and nothing between\n";
    $s = wt_sandbox(wt_root($plugin), 'wt7');
    [$rc, $t] = wt_run($script, $s, ['--client', '15', '--tech', '1099', '--no-customer-email'], function (string $q) {
        return (strpos($q, 'Create') === 0 || strpos($q, 'Delete') === 0) ? '' : 's';
    });
    $tx = array_map(function ($x) { return (string)$x['text']; }, $jobTexts($s));
    ok($rc === 0 && count($tx) === 2 && strpos($tx[0], 'New Job Has Been Assigned') !== false && strpos($tx[1], 'has been cancelled') !== false,
        'two WhatsApp messages: message 1 and "cancelled"', json_encode(array_map(function ($x) { return substr($x, 0, 60); }, $tx)));
    ok(substr_count($t, ': skipped') === 4, 'the summary lists four steps skipped');
    ok(count(array_filter($s->mails(), function ($m) { return $m['to'] === ['customer@example.test']; })) === 0, '--no-customer-email: the customer got no e-mail');
    $s->stop();
}

echo "\nrehearsal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

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

// The staff app ends its request at a warning only when PHP's error level includes it (public.php), and --accept-test
// judges each warning by the configured level. Every PHP the rehearsal starts — the plugin, the fakes, the script's
// helper — reads one more ini file that sets the usual production level, E_ALL without deprecations, so the verdicts do
// not depend on this machine's php.ini. The leading ':' keeps PHP's own scan directory (its extensions) as well.
$iniDir = sys_get_temp_dir() . '/wt-ini-' . getmypid();
@mkdir($iniDir, 0700, true);
file_put_contents("$iniDir/zz-wt-level.ini", "error_reporting = E_ALL & ~E_DEPRECATED\n");
putenv('PHP_INI_SCAN_DIR=:' . $iniDir);
register_shutdown_function(function () use ($iniDir) { @unlink("$iniDir/zz-wt-level.ini"); @rmdir($iniDir); });

$pass = 0; $fail = 0;
function ok(bool $c, string $what, string $why = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $what\n"; } else { $fail++; echo "  FAIL $what" . ($why !== '' ? " — $why" : '') . "\n"; }
}
function want(string $id): bool { global $only; return $only === [] || in_array($id, $only, true); }

/**
 * A scratch plugin tree: the plugin, with the emitting fake uCRM over the repository's; optionally another webhook.php,
 * and optionally, in message 2's text (JobMessages::accepted), which DishNet's Accept builds after its claim and before
 * it sends — where production's job #10 stopped (docs/44 §16.27) — one of: a PHP deprecation, which the pinned error
 * level leaves out, then a PHP warning, which it includes ('warning'); an exception ('exception'); or a run past PHP's
 * time limit, a fatal error ('timeout').
 */
function wt_root(string $plugin, ?string $webhook = null, ?string $inject = null): string
{
    $tmp = sys_get_temp_dir() . '/wt-root-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($tmp, 0700, true);
    exec('tar -C ' . escapeshellarg($plugin) . ' --exclude=./docs --exclude=./prototype --exclude=./dishnet-mikrotik-control-plane'
        . ' --exclude=./data --exclude=./.git -cf - . | tar -C ' . escapeshellarg($tmp) . ' -xf -');
    $fx = $tmp . '/tests/fixtures';
    rename("$fx/fake_ucrm_staff_jobs.php", "$fx/fake_ucrm_staff_jobs_orig.php");
    copy(__DIR__ . '/fake_ucrm_emit.php', "$fx/fake_ucrm_staff_jobs.php");
    if ($webhook !== null) file_put_contents("$tmp/webhook.php", $webhook);
    if ($inject !== null) {
        $code = ['warning'   => "        trim(null);\n        trigger_error('rehearsal: an injected warning at https://example.test/hook?token=rehearsal-secret', E_USER_WARNING);\n",
                 'exception' => "        throw new \\RuntimeException('rehearsal: an injected exception');\n",
                 'timeout'   => "        set_time_limit(1);\n        for (;;) {}\n"][$inject] ?? null;
        if ($code === null) throw new \RuntimeException("no such injection: {$inject}");
        $f = "$tmp/lib/JobMessages.php"; $src = (string)file_get_contents($f);
        $at = "public static function accepted(array \$f): string\n    {\n";
        if (substr_count($src, $at) !== 1) throw new \RuntimeException('the injection could not be made: anchor not found once');
        file_put_contents($f, str_replace($at, $at . $code, $src));
    }
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
 * The script's environment: a stand-in `docker` that runs the helper with this machine's PHP, the log directory, and
 * step 3's wait cut to 6 s (an Accept here is done before its question is answered, so the wait never matters).
 */
function wt_env(SjSandbox $s, ?string $inContainer = null): array
{
    $bin = $s->sb . '/bin'; @mkdir($bin, 0700, true);
    file_put_contents("$bin/docker", "#!/bin/bash\n# rehearsal stand-in: docker exec [-i] [-u U] CONTAINER cmd… runs cmd here\n"
        . "[ \"\$1\" = exec ] || { echo 'stand-in docker: exec only' >&2; exit 9; }\nshift\n"
        . "while [ \$# -gt 0 ]; do case \"\$1\" in -i) shift ;; -u) shift 2 ;; *) break ;; esac; done\nshift\nexec \"\$@\"\n");
    chmod("$bin/docker", 0755);
    $out = $s->sb . '/out'; @mkdir($out, 0700, true);
    $env = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    return array_merge($env, ['PATH' => $bin . ':' . getenv('PATH'), 'IN_CONTAINER' => $inContainer ?? $s->plug, 'OUT' => $out,
        'DN_VAULT_FILE' => $s->vault, 'DN_DATA_DIR' => $s->data, 'UCRM_CONTAINER' => 'ucrm', 'ACCEPT_WAIT' => '6']);
}

/** --facts N, as the operator would run it: [exit code, what the terminal showed, the log file's content]. */
function wt_facts(string $script, SjSandbox $s, string $job): array
{
    $p = proc_open(['bash', $script, '--facts', $job], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, wt_env($s));
    $t = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($p);
    $logs = glob($s->sb . '/out/job-facts-' . $job . '-*.log') ?: [];
    sort($logs);
    return [$rc, $t, $logs ? (string)file_get_contents(end($logs)) : ''];
}

/**
 * The script, run as the operator would, its questions answered by $answer(prompt, transcript) → the line typed.
 * @return array{0:int,1:string,2:string} exit code, what the terminal showed, the log file's content
 */
function wt_run(string $script, SjSandbox $s, array $args, callable $answer, int $timeout = 300, ?string $inContainer = null): array
{
    $out = $s->sb . '/out';
    $env = wt_env($s, $inContainer);
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
    $logs = array_merge(glob($out . '/walkthrough-*.log') ?: [], glob($out . '/accept-test-*.log') ?: []);
    usort($logs, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
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
    ok(isset($tx[3]) && strpos($tx[3], "Date: Not scheduled yet") !== false,
        '"no longer assigned" reads "Date: Not scheduled yet": uCRM took the time away with the engineer (docs/44 §16.26)');
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
    $b = array_column($changes, 'body');
    ok(($b[2] ?? null) === ['assignedUserId' => null, 'date' => null], 'step 4 took the engineer and the time away together', json_encode($b[2] ?? null));
    ok(($b[3]['assignedUserId'] ?? null) === 1099 && is_string($b[3]['date'] ?? null) && ($b[3]['status'] ?? null) === 0,
        'step 5 gave back the engineer and a time, and set the job Open', json_encode($b[3] ?? null));
    ok(count(array_filter($dump['requests'] ?? [], function ($r) { return isset($r['refused']); })) === 0, 'uCRM refused nothing');
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

if (want('S8a')) {
    echo "\nS8a the fake uCRM answers as the real one did on 28 September (job #10): a time with nobody assigned is 422\n";
    $s = wt_sandbox(wt_root($plugin), 'wt8a');
    $url = "{$s->crm}/scheduling/jobs";
    $new = $s->http('POST', $url, ['title' => 'Fixture', 'date' => '2026-10-01T10:00:00+0300', 'assignedUserId' => 1099, 'clientId' => 15, 'status' => 0],
                    ['Content-Type: application/json']);
    $id = (int)($new[2]['id'] ?? 0);
    $a = $s->http('PATCH', "{$url}/{$id}", ['assignedUserId' => null], ['Content-Type: application/json']);
    ok($id > 0 && $a[0] === 422 && ($a[2]['errors']['assignedUserId'][0] ?? '') === 'You must assign an user in order to set the date.',
        'nobody assigned, the time kept: 422 with uCRM\'s own words', $a[0] . ' ' . $a[1]);
    ok((int)($s->crmDump()['jobs'][(string)$id]['assignedUserId'] ?? 0) === 1099, 'and the job is unchanged');
    $b = $s->http('PATCH', "{$url}/{$id}", ['assignedUserId' => null, 'date' => null], ['Content-Type: application/json']);
    $j = (array)($s->crmDump()['jobs'][(string)$id] ?? []);
    ok($b[0] === 200 && array_key_exists('assignedUserId', $j) && $j['assignedUserId'] === null && $j['date'] === null,
        'nobody assigned and no time: accepted', $b[0] . ' ' . json_encode($j));
    $s->stop();
}

if (want('S8')) {
    echo "\nS8 uCRM refuses step 4 outright: step 4 shows uCRM's answer, and step 5 is not offered\n";
    $s = wt_sandbox(wt_root($plugin), 'wt8');
    touch($s->sb . '/refuse_takeaway.txt');
    $asked = [];
    [$rc, $t] = wt_run($script, $s, ['--client', '15', '--tech', '1099', '--no-customer-email'], function (string $q) use (&$asked): string {
        $asked[] = $q;
        return strpos($q, 'Press Enter once Accept') === 0 || strpos($q, 'Change the time') === 0 ? 's' : '';
    });
    ok(is_file($s->sb . '/refuse_takeaway.txt'), 'the control is in place', $s->sb);
    ok($rc === 0 && strpos($t, '✗ uCRM refused: {"http_code":422') !== false, "step 4 prints uCRM's refusal", "exit $rc");
    ok(count(array_filter($asked, function ($q) { return strpos($q, 'Give the job back') === 0; })) === 0
        && strpos($t, 'Skipped: step 4 did not take the job away, so there is nothing to give back.') !== false, 'step 5 is not offered, and says why');
    ok(strpos($t, 'step 5: skipped (step 4 did not take the job away)') !== false, 'the summary says so');
    $tx = array_map(function ($x) { return (string)$x['text']; }, $jobTexts($s));
    ok(count($tx) === 2 && strpos($tx[1], 'has been cancelled') !== false, 'the technician got message 1 and the cancellation, nothing between', (string)count($tx));
    $refused = array_values(array_filter($s->crmDump()['requests'] ?? [], function ($r) { return isset($r['refused']); }));
    ok(count($refused) === 1 && ($refused[0]['body'] ?? null) === ['assignedUserId' => null, 'date' => null], 'uCRM saw one refused change: step 4\'s');
    $s->stop();
}

if (want('S9')) {
    echo "\nS9 step 3 done in uCRM's own screen: no claim, and the script says so; it puts the job back to Open, and the Accept\n"
       . "   pressed in DishNet then sends message 2. --facts reads the job before and after\n";
    $s = wt_sandbox(wt_root($plugin), 'wt9');
    $job = 0; $mid = [1, '', '']; $asked = [];
    [$rc, $t] = wt_run($script, $s, ['--client', '15', '--tech', '1099'], function (string $q, string $buf) use ($s, $script, &$job, &$mid, &$asked): string {
        $asked[] = $q;
        if (preg_match('/created: job #(\d+)/', $buf, $m)) $job = (int)$m[1];
        if ($q === 'Press Enter once Accept has been pressed') {
            // uCRM's own screen: the status set in uCRM itself, which then tells the plugin (job.edit).
            $s->http('PATCH', "{$s->crm}/scheduling/jobs/{$job}", ['status' => 1], ['Content-Type: application/json']);
            sleep(3);
            return '';
        }
        if (strpos($q, 'Put job #') === 0) { $mid = wt_facts($script, $s, (string)$job); return ''; }
        if ($q === 'Press Enter once Accept has been pressed in DishNet') {
            $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => $job, 'status' => 'open', 'notify_accept' => 1]);
            return '';
        }
        return strpos($q, 'Take the job away') === 0 ? 'q' : '';
    });
    ok($rc === 0 && $job > 0, 'the run ends with exit 0 after q at step 4', "exit $rc");
    ok(preg_match("/^  ✗ uCRM shows job #{$job} In progress, but DishNet's Accept left no claim on it: it was set In progress outside/m", $t) === 1,
        'the first check says uCRM shows the job In progress and DishNet\'s Accept left no claim');
    ok(strpos($t, "plugin record  assigned to uCRM user #1099;") !== false && strpos($t, "DishNet's Accept claim: none") !== false,
        "and prints the notifier's record: no claim");
    ok(count(array_filter($asked, function ($q) { return strpos($q, 'Put job #') === 0; })) === 1, 'it offers once to put the job back to Open');
    ok(preg_match('/^  ✓ as expected: message 2 went, and the e-mail was handed to the mail server$/m', $t) === 1
        && strpos($t, 'step 3 accept: ✓ as expected: message 2 went, and the e-mail was handed to the mail server (on the second try)') !== false,
        'the Accept pressed in DishNet sends message 2; the summary says it was the second try');
    $patches = array_values(array_filter($s->crmReqs('PATCH', '#^/scheduling/jobs/\d+$#'), function ($r) use ($job) { return $r['path'] === "/scheduling/jobs/{$job}"; }));
    ok(count(array_filter($patches, function ($r) { return ($r['body'] ?? null) === ['status' => 0]; })) === 1, 'the script set the job back to Open once');
    $tx = array_map(function ($x) { return (string)$x['text']; }, $jobTexts($s));
    ok(count(array_filter($tx, function ($x) { return strpos($x, 'Thank you for accepting the job!') !== false; })) === 1, 'message 2 went exactly once');
    [$mrc, $mt, $mlog] = $mid;
    ok($mrc === 0 && strpos($mt, "Read-only: this changes nothing and sends nothing.") !== false, '--facts during the run: exit 0, read-only', "exit $mrc");
    ok(preg_match("/^  DishNet's Accept left no claim on job #{$job}, yet it was In progress\\. Either it was set In progress outside/m", $mt) === 1,
        '--facts names the case: In progress, with no claim');
    ok(strpos($mt, 'Received UCRM webhook: job.edit') !== false && strpos($mt, "Job #{$job} — no new assignment, time or cancellation: nothing to send") !== false,
        "--facts shows uCRM's notice of the status change and the plugin's answer to it");
    ok($mlog !== '' && strpos($mlog, 'yet it was In progress') !== false, 'and writes the same to its own log file');
    [$frc, $ft] = wt_facts($script, $s, (string)$job);
    ok($frc === 0 && preg_match("/^  DishNet's Accept was recorded: message 2 went, at /m", $ft) === 1
        && strpos($ft, "DishNet's Accept claim: uCRM user #1099") !== false, '--facts after the run: message 2 recorded, the claim uCRM user #1099');
    ok(preg_match('/^  \d{4}-\d\d-\d\d \d\d:\d\d:\d\d  ops_job_accepted_self sent$/m', $ft) === 1, '--facts lists message 2 in the Message Log');
    ok(strpos($ft, '<e-mail>') !== false && wt_leaks($t . $mt . $ft . $mlog) === [], 'everything printed is masked', json_encode(wt_leaks($t . $mt . $ft . $mlog)));
    ok(!is_dir($s->sb . '/out/walkthrough.lock'), 'no lock is left behind');
    $s->stop();
}

if (want('S9b')) {
    echo "\nS9b the same, but q at the offer of a second try: the summary still gives step 3's verdict, and nothing more is done\n";
    $s = wt_sandbox(wt_root($plugin), 'wt9b');
    $job = 0;
    [$rc, $t] = wt_run($script, $s, ['--client', '15', '--tech', '1099', '--no-customer-email'], function (string $q, string $buf) use ($s, &$job): string {
        if (preg_match('/created: job #(\d+)/', $buf, $m)) $job = (int)$m[1];
        if (strpos($q, 'Change the time') === 0) return 's';
        if ($q === 'Press Enter once Accept has been pressed') {
            $s->http('PATCH', "{$s->crm}/scheduling/jobs/{$job}", ['status' => 1], ['Content-Type: application/json']);
            sleep(3);
            return '';
        }
        return strpos($q, 'Put job #') === 0 ? 'q' : '';
    });
    ok($rc === 0 && strpos($t, 'Stopped. Nothing more is done.') !== false, 'q at the offer stops the run', "exit $rc");
    ok(preg_match("/^  step 3 accept: ✗ uCRM shows job #{$job} In progress, but DishNet's Accept left no claim/m", $t) === 1,
        "the summary keeps step 3's verdict");
    ok(count(array_filter($s->crmReqs('PATCH', '#^/scheduling/jobs/\d+$#'), function ($r) { return ($r['body'] ?? null) === ['status' => 0]; })) === 0,
        'the job was not put back to Open');
    ok(strpos($t, "The test job #{$job} is still in uCRM") !== false, 'and the summary says the test job is still in uCRM');
    $s->stop();
}

if (want('S10')) {
    echo "\nS10 --facts: a claim with no message 2 is named a fault; an unknown job reads as nothing; a bad number is refused\n";
    $s = wt_sandbox(wt_root($plugin), 'wt10');
    // Job 901, the scenario's own, assigned to 1099: the notifier's record claims it, and no history row says why.
    $s->q("INSERT OR REPLACE INTO job_notify_state (job_id, assignee_id, job_time, job_status, title, gone, accepted_by, version, updated_at)
           VALUES (901, 1099, '', 1, 'Fixture job', 0, 1099, 1, datetime('now'))");
    [$rc, $t, $log] = wt_facts($script, $s, '901');
    ok($rc === 0 && strpos($t, "DishNet's Accept claim: uCRM user #1099") !== false, 'the record shows the claim', "exit $rc");
    ok(preg_match("/^  DishNet's Accept claimed job #901 for uCRM user #1099 but recorded no message 2: a fault in the Accept path\\./m", $t) === 1,
        'and --facts names it a fault');
    ok(strpos($t, 'no history row for job #901') !== false && strpos($t, "holds no notice about job #901") !== false, 'no history, no notice: said, not left blank');
    ok($log !== '' && strpos($log, 'a fault in the Accept path') !== false, 'its log file holds the same');
    [$rc, $t] = wt_facts($script, $s, '4242');
    ok($rc === 0 && strpos($t, 'holds no record of job #4242') !== false && strpos($t, 'the job is gone (404)') !== false
        && strpos($t, 'Accept has not been pressed') !== false, 'an unknown job: no record, gone in uCRM, no Accept', "exit $rc");
    $bad = function (array $args) use ($script, $s): array {
        $p = proc_open(array_merge(['bash', $script], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, wt_env($s));
        $o = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($p), $o];
    };
    [$r1, $o1] = $bad(['--facts', 'abc']); [$r2, $o2] = $bad(['--facts']);
    ok($r1 === 2 && strpos($o1, '--facts takes a job number') !== false && $r2 === 2 && strpos($o2, '--facts takes a number') !== false,
        'a bad or missing number: refused with exit 2', "$r1 / $r2");
    $req = $s->crmDump()['requests'] ?? [];
    ok(count(array_filter($req, function ($r) { return $r['method'] !== 'GET'; })) === 0 && count($jobTexts($s)) === 0,
        'read-only: uCRM saw no change and nobody was messaged');
    $st = $s->q('SELECT accepted_by, version FROM job_notify_state WHERE job_id = 901');
    ok(($st[0]['accepted_by'] ?? null) == 1099 && (int)($st[0]['version'] ?? 0) === 1, "and the notifier's record is as it was");
    ok(!is_dir($s->sb . '/out/walkthrough.lock'), '--facts takes no lock');
    $s->stop();
}

if (want('S11')) {
    echo "\nS11 --accept-test: a test job, DishNet's Accept run by the script, message 2, then the job deleted\n";
    $s = wt_sandbox(wt_root($plugin), 'wt11');
    $job = 0;
    [$rc, $t, $log] = wt_run($script, $s, ['--accept-test', '--client', '15', '--tech', '1099'], function (string $q, string $buf) use (&$job): string {
        if (preg_match('/created: job #(\d+)/', $buf, $m)) $job = (int)$m[1];
        return '';
    });
    ok($rc === 0 && strpos($t, '== Accept test — ') !== false, 'the Accept test runs and ends with exit 0', "exit $rc");
    ok(preg_match('/^  ✓ no PHP warning that ends the staff app\'s request, and message 2 went, and the e-mail was handed to the mail server$/m', $t) === 1,
        'no PHP warning that ends the staff app\'s request, and message 2 went by WhatsApp and e-mail');
    ok(strpos($t, "PHP warning, ends the staff app's request") === false && strpos($t, 'PHP error  ') === false,
        'no warning that ends the request, and no error, is printed');
    ok(preg_match('/^  PHP error level \(the command line\'s\)  E_ALL without E_DEPRECATED \(\d+\); set in \S+\/zz-wt-level\.ini$/m', $t) === 1
        && strpos($t, '  PHP-FPM override  ') !== false && preg_match('/^  PHP-FPM workers  none found; this test runs as uid \d+$/m', $t) === 1,
        'it prints the error level it judged by (the pinned one), any PHP-FPM override, and PHP-FPM\'s user beside its own');
    ok(preg_match('/^  the Accept took  \d+\.\d s \(php\.ini\'s time limit: [^)]+\)$/m', $t) === 1, 'it prints how long the Accept took, beside php.ini\'s time limit');
    ok(preg_match('/^  WhatsApp for job messages  the staff app\'s settings: evolution; the webhook\'s: evolution$/m', $t) === 1
        && strpos($t, 'no WhatsApp transport for job messages') === false,
        'it reads the same WhatsApp transport from the staff app\'s settings as from the webhook\'s, and says nothing more');
    $tx = array_map(function ($x) { return (string)$x['text']; }, $jobTexts($s));
    $want = ['New Job Has Been Assigned to You', 'Thank you for accepting the job!', 'has been cancelled'];
    $got = []; foreach ($tx as $x) foreach ($want as $w) if (strpos($x, $w) !== false) { $got[] = $w; break; }
    ok($got === $want, 'the technician got message 1, message 2 and the cancellation, in order', json_encode($got));
    $patches = array_values(array_filter($s->crmReqs('PATCH', '#^/scheduling/jobs/\d+$#'), function ($r) use ($job) { return $r['path'] === "/scheduling/jobs/{$job}"; }));
    ok(count($patches) === 1 && ($patches[0]['body'] ?? null) === ['status' => 1], 'uCRM saw one change to the job: the Accept (status 1)', json_encode($patches));
    ok(!isset($s->crmDump()['jobs'][(string)$job]) && strpos($t, 'The test job #') === false, 'the test job was deleted');
    ok($log !== '' && strpos($log, '== Accept test — ') !== false && wt_leaks($t . $log) === [], 'its log file holds the run, masked', json_encode(wt_leaks($t . $log)));
    $s->stop();
}

if (want('S11b')) {
    echo "\nS11b one PHP warning after the claim: the staff app's Accept leaves job #10's trail, and --accept-test names the warning\n";
    $s = wt_sandbox(wt_root($plugin, null, 'warning'), 'wt11b');
    $h = ['Content-Type: application/json'];
    $d = (new DateTime('tomorrow', new DateTimeZone('Africa/Kampala')))->format('Y-m-d');
    $j = $s->http('POST', "{$s->crm}/scheduling/jobs", ['title' => 'Fixture job', 'date' => $d . 'T10:00:00+0300', 'assignedUserId' => 1099,
        'clientId' => 15, 'status' => 0, 'duration' => 60], $h);
    $id = (int)($j[2]['id'] ?? 0);
    sleep(4);
    $r = $s->api('tech', 'POST', 'scheduling_job_update', ['job_id' => $id, 'status' => 'open', 'notify_accept' => 1]);
    ok($r[0] === 500 && strpos((string)$r[1], 'rehearsal: an injected warning') !== false,
        "the staff app's Accept answers 500 with the warning, which the job page shows as \"Failed: …\"", $r[0] . ' ' . substr((string)$r[1], 0, 120));
    $st = $s->q('SELECT accepted_by FROM job_notify_state WHERE job_id = ?', [$id]);
    $acc = $s->q("SELECT id FROM job_notify_events WHERE job_id = ? AND event = 'accepted'", [$id]);
    $ml = $s->q("SELECT id FROM notification_audit_log WHERE event = 'ops_job_accepted_self'");
    ok((int)($st[0]['accepted_by'] ?? 0) === 1099 && $acc === [] && $ml === [],
        "job #10's trail: the claim is written, and neither the history nor the Message Log has message 2", json_encode([$st, $acc, $ml]));
    [$frc, $ft] = wt_facts($script, $s, (string)$id);
    ok($frc === 0 && preg_match("/^  DishNet's Accept claimed job #{$id} for uCRM user #1099 but recorded no message 2: a fault/m", $ft) === 1,
        '--facts reads it exactly as it read job #10');
    $job = 0;
    [$rc, $t, $log] = wt_run($script, $s, ['--accept-test', '--client', '15', '--tech', '1099', '--no-customer-email'], function (string $q, string $buf) use (&$job): string {
        if (preg_match('/created: job #(\d+)/', $buf, $m)) $job = (int)$m[1];
        return '';
    });
    ok($rc === 0 && preg_match("/^  PHP warning, ends the staff app's request  E_USER_WARNING rehearsal: an injected warning at https:\\/\\/example\\.test\\/hook\\?<query> — JobMessages\\.php:\\d+$/m", $t) === 1,
        '--accept-test prints the warning, that it ends the request, its level, file and line');
    ok(preg_match("/^  ✗ DishNet's Accept raised 1 PHP warning\\(s\\) that end the staff app's request\\. The first: E_USER_WARNING rehearsal: an injected warning at https:\\/\\/example\\.test\\/hook\\?<query> — JobMessages\\.php:\\d+\\. Here, where warnings do not stop it, message 2 went\\.$/m", $t) === 1,
        'and its verdict names it as what ends the staff app\'s Accept, while message 2 still went here');
    ok($log !== '' && strpos($t . $log, 'rehearsal-secret') === false,
        'the web address the warning quotes is printed without its query string, on screen and in the log');
    ok(preg_match('/^  PHP warning, noted only  E_DEPRECATED trim\\(\\): Passing null to parameter #1 \\(\\$string\\) of type string is deprecated — JobMessages\\.php:\\d+$/m', $t) === 1
        && preg_match('/^  \\(\\d+ PHP warning\\(s\\) above marked "noted only": the error level leaves them out, so they do not stop the staff app\\.\\)$/m', $t) === 1,
        'the deprecation before it, which the error level leaves out, is marked "noted only" and not counted');
    ok(count(array_filter($jobTexts($s), function ($x) { return strpos((string)$x['text'], 'Thank you for accepting the job!') !== false; })) === 1,
        'message 2 went once: from the test, not from the failed Accept');
    $s->stop();
}

if (want('S11c')) {
    echo "\nS11c settings split: the staff app's copy holds only uCRM's connection, the rest is in config.json, which the webhook also reads\n";
    $s = wt_sandbox(wt_root($plugin), 'wt11c');
    $full = $s->cfg;
    $keep = array_intersect_key($full, array_flip(['data_dir', 'dry_run_mode', 'crm_base_url', 'crm_auth_token']));
    $s->store()->save('kyc_config.json', $keep);
    file_put_contents($s->data . '/kyc_config.json', json_encode($keep));
    @mkdir($s->plug . '/data', 0700, true);
    file_put_contents($s->plug . '/data/config.json', json_encode($full));
    [$rc, $t] = wt_run($script, $s, ['--accept-test', '--client', '15', '--tech', '1099', '--no-customer-email'], function (string $q, string $buf): string { return ''; });
    ok($rc === 0 && preg_match('/^  WhatsApp for job messages  the staff app\'s settings: none; the webhook\'s: evolution$/m', $t) === 1,
        'it reads no WhatsApp transport from the staff app\'s settings, and Evolution from the webhook\'s');
    ok(preg_match('/^  ✗ the staff app\'s settings have no WhatsApp transport for job messages, while the webhook\'s have one: in the staff app DishNet\'s Accept sends message 2 by e-mail only, and writes no Message Log row$/m', $t) === 1,
        'and says what that does to the staff app\'s Accept');
    ok(preg_match('/^  history  accepted accepted accept failed email=sent \(staff account #\d+ \(no WhatsApp transport took it\)\)$/m', $t) === 1
        && preg_match('/^  ✗ no PHP warning that ends the staff app\'s request, but message 2 did not go by WhatsApp \(the e-mail was handed to the mail server\); see the history above$/m', $t) === 1,
        'the history and the verdict: no WhatsApp for message 2, the e-mail went');
    $tx = array_map(function ($x) { return (string)$x['text']; }, $jobTexts($s));
    ok(count(array_filter($tx, function ($x) { return strpos($x, 'New Job Has Been Assigned to You') !== false; })) === 1
        && count(array_filter($tx, function ($x) { return strpos($x, 'Thank you for accepting the job!') !== false; })) === 0,
        'by WhatsApp the technician got message 1, from the webhook, and not message 2', json_encode(array_map(function ($x) { return substr($x, 0, 40); }, $tx)));
    $s->stop();
}

foreach (['S11d' => ['exception', 'an exception', "RuntimeException: rehearsal: an injected exception — JobMessages\\.php:\\d+"],
          'S11e' => ['timeout', 'a run past PHP\'s time limit, a fatal error', "fatal: Maximum execution time of 1 second exceeded — JobMessages\\.php:\\d+"]] as $sc => [$kind, $what, $exc]) {
    if (!want($sc)) continue;
    echo "\n{$sc} {$what} after the claim: --accept-test reports it, and the job still goes\n";
    $s = wt_sandbox(wt_root($plugin, null, $kind), 'wt' . strtolower(substr($sc, 1)));
    $job = 0;
    [$rc, $t, $log] = wt_run($script, $s, ['--accept-test', '--client', '15', '--tech', '1099', '--no-customer-email'], function (string $q, string $buf) use (&$job): string {
        if (preg_match('/created: job #(\d+)/', $buf, $m)) $job = (int)$m[1];
        return '';
    });
    ok($rc === 0 && preg_match("/^  PHP error  {$exc}$/m", $t) === 1, 'it prints the error, with its file and line');
    ok(preg_match("/^  ✗ DishNet's Accept stopped with a PHP error: {$exc}$/m", $t) === 1, 'its verdict says the Accept stopped with that error');
    $st = $s->q('SELECT accepted_by FROM job_notify_state WHERE job_id = ?', [$job]);
    $acc = $s->q("SELECT id FROM job_notify_events WHERE job_id = ? AND event = 'accepted'", [$job]);
    $ml = $s->q("SELECT id FROM notification_audit_log WHERE event = 'ops_job_accepted_self'");
    ok((int)($st[0]['accepted_by'] ?? 0) === 1099 && $acc === [] && $ml === [], "the trail is job #10's: the claim, and no message 2 recorded", json_encode([$st, $acc, $ml]));
    ok(!isset($s->crmDump()['jobs'][(string)$job]) && $log !== '' && wt_leaks($t . $log) === [], 'the test job was still deleted, and the log is masked');
    $s->stop();
}

echo "\nrehearsal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

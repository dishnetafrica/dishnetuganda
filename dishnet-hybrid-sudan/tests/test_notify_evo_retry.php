<?php
declare(strict_types=1);
/**
 * test_notify_evo_retry.php — 5.18.54, docs/46 row 31 (N-1): a WhatsApp that may have gone is never sent again by the
 * Evolution client.
 *
 * EvolutionApiService::request() retried every failed connection, a POST included, up to three times; its own comment
 * said a POST that reached Evolution never is. A timeout after the request had left, and Evolution had taken the
 * message, sent it again, twice. On Uganda a POST is now retried only when nothing left: before the connection and the
 * TLS handshake are done. Otherwise it fails at once, and its error says the message may have been sent.
 *
 * Each case runs one call (tests/fixtures/evo_retry_probe.php, 1-second timeout) against a socket-level fake Evolution
 * (tests/fixtures/fake_evo_raw.php) that records every connection and every request that arrived:
 *
 *   hang, close   the request arrives and no answer comes back — Uganda: one request, "may have been sent";
 *                 South Sudan: three, as in 5.18.53 (§E)
 *   tls           the handshake fails, so no request is ever sent — retried, three connections, "not sent"
 *   refuse, ok    Evolution answers — never retried, as before
 *   a read (GET) meeting a 500 with a plain-text body — Uganda: retried as a read, three requests, an error;
 *                 South Sudan: it dies of a TypeError on the retry, as in 5.18.53
 *
 * And the two callers that sent again on their own (tests/fixtures/no_resend_probe.php, from a copy of the plugin):
 *   the AI reply worker, whose failure hands the event back to EventBus, which asks the AI again and sends again;
 *   the follow-up sender, which leaves a failed draft approved, to go again at its next run, five minutes later.
 *   Uganda: after a send that may have gone, one reply, handed to a person; one follow-up, set aside as uncertain.
 *   South Sudan: as in 5.18.53, both go again.
 *
 * Plus weakened copies, each caught (skipped with --no-mutants). Nothing leaves the machine.
 */
$root = dirname(__DIR__);
$withMutants = !in_array('--no-mutants', $argv, true);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';   // sj_weakened_copy()

$pass = 0; $fail = 0; $skip = 0;
require_once dirname(__DIR__) . '/lib/FollowUpPolicy.php';   // its HOUR_OPEN / HOUR_CLOSE, for er_window_zone()
/** A check that cannot run at this hour (see er_window_zone): counted apart, printed with its reason, never a failure. */
function skip_(string $m, string $why): void { global $skip; $skip++; echo "  skip $m — $why\n"; }
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const ER_NOT_SENT   = 'Not sent — ';
const ER_MAYBE_SENT = 'May have been sent — ';

/**
 * One case: the fake Evolution in $modes, one call through $tree's client as $tenant. Returns the probe's answer and
 * the fake's transcript, read after the fake has handled every connection it was given and stopped.
 */
function er_case(string $tree, string $tenant, string $op, string $modes, string $scheme = 'http'): array
{
    $dir = sys_get_temp_dir() . '/er-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($dir, 0700, true);
    $tr = $dir . '/transcript.json';
    $data = $dir . '/data';            // apart from the transcript: a store's first boot moves JSON files into SQLite
    mkdir($data, 0700, true);
    $proc = null; $port = 0;
    foreach (range(0, 9) as $slot) {
        $port = 9860 + ((getmypid() + $slot * 7 + random_int(0, 6)) % 90);
        $proc = proc_open(sprintf('exec %s %s %d %s %s 2', escapeshellarg(PHP_BINARY),
                    escapeshellarg(dirname(__DIR__) . '/tests/fixtures/fake_evo_raw.php'), $port, escapeshellarg($tr),
                    escapeshellarg($modes)),
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $dir . '/fake.err', 'w']], $pipes);
        for ($i = 0; $i < 40 && !is_file($tr); $i++) usleep(50000);
        if (is_file($tr)) break;
        proc_terminate($proc); proc_close($proc); $proc = null;
    }
    if (!$proc) return ['probe' => ['exception' => 'the fake Evolution did not start'], 'conns' => []];
    $cmd = sprintf('%s %s %s %s %s %s %s 2>&1', escapeshellarg(PHP_BINARY),
                   escapeshellarg(dirname(__DIR__) . '/tests/fixtures/evo_retry_probe.php'), escapeshellarg($tree),
                   escapeshellarg($tenant), escapeshellarg($op), escapeshellarg("{$scheme}://127.0.0.1:{$port}"),
                   escapeshellarg($data));
    $out = (string)shell_exec($cmd);
    proc_close($proc);                               // the fake stops 2 s after its last connection
    $probe = json_decode(trim($out), true);
    if (!is_array($probe)) $probe = ['exception' => 'no JSON from the probe: ' . mb_substr($out, 0, 300)];
    $conns = json_decode((string)@file_get_contents($tr), true) ?: [];
    exec('rm -rf ' . escapeshellarg($dir));
    return ['probe' => $probe, 'conns' => $conns];
}
function er_requests(array $c): int { return count(array_filter($c['conns'], fn($x) => !empty($x['http']))); }
function er_show(array $c): string
{
    return json_encode(['probe' => $c['probe'], 'connections' => count($c['conns']), 'requests' => er_requests($c)],
                       JSON_UNESCAPED_UNICODE);
}
function er_starts(array $c, string $prefix): bool { return strpos((string)($c['probe']['error'] ?? ''), $prefix) === 0; }

/** A plain copy of the plugin: the follow-up cron finds its data directory beside the plugin, as on the server. */
function er_tree_copy(string $root): string
{
    $tmp = sys_get_temp_dir() . '/er-tree-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($tmp, 0700, true);
    foreach (scandir($root) ?: [] as $e) {
        if ($e === '.' || $e === '..' || in_array($e, ['docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git'], true)) continue;
        exec('cp -R ' . escapeshellarg($root . '/' . $e) . ' ' . escapeshellarg($tmp . '/'));
    }
    return $tmp;
}
/** The data directory the plugin at $tree uses: the one beside it. */
function er_data_dir(string $tree): string { return dirname($tree) . '/.' . basename($tree) . '-data'; }
/** A zone in which it is now a sending day inside FollowUpPolicy's own hours — so the follow-up window is open whenever
 *  one exists anywhere. Searches the real offsets (UTC-12 … UTC+14) against HOUR_OPEN / HOUR_CLOSE; the policy refuses
 *  Sundays only. On a Sunday between roughly 08:00 and 18:00 UTC no zone on Earth is inside the window (Saturday has
 *  closed everywhere, Monday has opened nowhere): then it returns '' and the callers SKIP with that reason rather than
 *  fail — the first run on such a Sunday (4 Oct 2026, 07:12 UTC) read three false failures with the old 09-18 / ±11 hunt. */
function er_window_zone(): string
{
    $now = time();
    for ($off = -12; $off <= 14; $off++) {
        $t = $now + $off * 3600;
        if ((int)gmdate('w', $t) !== 0 && (int)gmdate('G', $t) >= FollowUpPolicy::HOUR_OPEN && (int)gmdate('G', $t) < FollowUpPolicy::HOUR_CLOSE) {
            return $off === 0 ? 'UTC' : 'Etc/GMT' . ($off > 0 ? '-' : '+') . abs($off);
        }
    }
    return '';
}
/**
 * One caller case: the fake Evolution closing every connection unanswered (the request arrived, no answer came back),
 * and the caller run $runs times from $tree. Returns the probe's answer and the customer messages the fake received.
 */
function er_caller(string $tree, string $tenant, string $what, int $runs): array
{
    $dd = er_data_dir($tree);
    exec('rm -rf ' . escapeshellarg($dd));
    mkdir($dd, 0700, true);
    $dir = sys_get_temp_dir() . '/er-c-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($dir, 0700, true);
    $tr = $dir . '/transcript.json';
    $proc = null; $port = 0;
    foreach (range(0, 9) as $slot) {
        $port = 9860 + ((getmypid() + $slot * 7 + random_int(0, 6)) % 90);
        $proc = proc_open(sprintf('exec %s %s %d %s close 2', escapeshellarg(PHP_BINARY),
                    escapeshellarg(dirname(__DIR__) . '/tests/fixtures/fake_evo_raw.php'), $port, escapeshellarg($tr)),
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 40 && !is_file($tr); $i++) usleep(50000);
        if (is_file($tr)) break;
        proc_terminate($proc); proc_close($proc); $proc = null;
    }
    if (!$proc) return ['probe' => ['exception' => 'the fake Evolution did not start'], 'sends' => -1];
    $out = (string)shell_exec(sprintf('%s %s %s %s %s %s %s %d %s 2>&1', escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__) . '/tests/fixtures/no_resend_probe.php'), escapeshellarg($tree), escapeshellarg($tenant),
        escapeshellarg($what), escapeshellarg("http://127.0.0.1:{$port}"), escapeshellarg($dd), $runs,
        escapeshellarg($what === 'followup' ? er_window_zone() : '')));
    proc_close($proc);
    // The last line is the probe's answer; a log line may come before it (an alert with no number says so, row 26).
    $lines = preg_split('/\R/', trim($out)) ?: [];
    $probe = json_decode((string)end($lines), true);
    if (!is_array($probe)) $probe = ['exception' => 'no JSON from the probe: ' . mb_substr($out, -400)];
    $sends = 0;
    foreach (json_decode((string)@file_get_contents($tr), true) ?: [] as $x) {
        if (strpos((string)($x['path'] ?? ''), '/message/sendText/') === 0) $sends++;
    }
    exec('rm -rf ' . escapeshellarg($dir) . ' ' . escapeshellarg($dd));
    return ['probe' => $probe, 'sends' => $sends];
}
function er_cshow(array $c): string { return json_encode($c, JSON_UNESCAPED_UNICODE); }

// ══════════════════════════════════════════════════════════════════════════════
echo "\n1. Uganda — a request that left is never sent again\n";
$c = er_case($root, 'uganda', 'post', 'hang');
is_(er_requests($c) === 1 && empty($c['probe']['ok']) && er_starts($c, ER_MAYBE_SENT) && !empty($c['probe']['maybe']),
    'no answer after the request arrived: one request, and the error says it may have been sent', er_show($c));
is_((float)($c['probe']['elapsed'] ?? 99) < 2.5, 'it failed at once: no second wait of 1 s', er_show($c));
$c = er_case($root, 'uganda', 'post', 'close');
is_(er_requests($c) === 1 && er_starts($c, ER_MAYBE_SENT), 'the connection closed without an answer: one request', er_show($c));

echo "\n2. Uganda — a request that never left is still retried\n";
$c = er_case($root, 'uganda', 'post', 'tls', 'https');
is_(count($c['conns']) === 3 && er_requests($c) === 0 && er_starts($c, ER_NOT_SENT) && empty($c['probe']['maybe']),
    'the TLS handshake failed each time: three connections, no request, and the error says not sent', er_show($c));

echo "\n3. Uganda — an answer from Evolution is taken as it was\n";
$c = er_case($root, 'uganda', 'post', 'refuse');
is_(er_requests($c) === 1 && strpos((string)($c['probe']['error'] ?? ''), '[HTTP 400 on POST /message/sendText/fake_inst]') !== false
    && empty($c['probe']['maybe']), 'a refusal: one request, not retried, and not "may have been sent"', er_show($c));
$c = er_case($root, 'uganda', 'post', 'ok');
is_(er_requests($c) === 1 && !empty($c['probe']['ok']), 'control: a send Evolution takes succeeds, once', er_show($c));

echo "\n4. Uganda — a read that meets a 500 with a plain-text body\n";
$c = er_case($root, 'uganda', 'get', 'text500');
is_(!isset($c['probe']['exception']) && er_requests($c) === 3
    && strpos((string)($c['probe']['error'] ?? ''), '[HTTP 500 on GET /instance/fetchInstances]') !== false,
    'retried as a read, three requests, and it ends in an error, not an exception', er_show($c));

echo "\n5. The error texts, as the retry job and the workers read them\n";
require_once $root . '/lib/EvolutionApiService.php';
$cases = [
    [ER_MAYBE_SENT . 'no answer from Evolution: Operation timed out', true],
    [ER_NOT_SENT . 'no connection to Evolution: Connection refused', false],
    ['Got an HTML page, not the API. [HTTP 504 on POST /message/sendText/x]', true],
    ['Bad Gateway [HTTP 502 on POST /message/sendMedia/x]', true],
    ['instance not connected [HTTP 400 on POST /message/sendText/x]', false],
    ['upstream [HTTP 500 on POST /message/sendText/x]', false],
    ['Bad Gateway [HTTP 502 on GET /instance/fetchInstances]', false],
    ['recipient has opted out: STOP', false],
    ['', false],
];
$wrong = [];
foreach ($cases as [$e, $want]) if (EvolutionApiService::mayHaveBeenSent($e) !== $want) $wrong[] = $e;
is_($wrong === [], 'may-have-been-sent: a lost answer, and a gateway 502 or 504 on a send — and nothing else', json_encode($wrong, JSON_UNESCAPED_UNICODE));
is_(EvolutionApiService::mayHaveBeenSent(['ok' => false, 'error' => ER_MAYBE_SENT . 'x']) === true, 'it reads a result as well as a text');

echo "\n6. South Sudan — as in 5.18.53 (docs/46 §E)\n";
$c = er_case($root, 'south-sudan', 'post', 'hang');
is_(er_requests($c) === 3 && er_starts($c, 'Connection failed: '), 'no answer after the request arrived: sent three times', er_show($c));
$c = er_case($root, 'south-sudan', 'post', 'tls', 'https');
is_(count($c['conns']) === 3 && er_starts($c, 'Connection failed: '), 'a handshake that failed: three connections', er_show($c));
$c = er_case($root, 'south-sudan', 'get', 'text500');
is_(er_requests($c) === 1 && strpos((string)($c['probe']['exception'] ?? ''), 'TypeError') === 0,
    'a read meeting a 500 with a plain-text body dies of a TypeError on its retry', er_show($c));
$c = er_case($root, 'south-sudan', 'post', 'ok');
is_(er_requests($c) === 1 && !empty($c['probe']['ok']), 'control: a send Evolution takes succeeds, once', er_show($c));

$copy = er_tree_copy($root);

echo "\n7. The AI reply worker, when its reply may have gone (two runs)\n";
$c = er_caller($copy, 'uganda', 'ai', 2);
$p = $c['probe'];
is_($c['sends'] === 1 && (int)($p['brain_calls'] ?? -1) === 1 && ($p['event']['status'] ?? '') === 'done',
    'Uganda: one reply sent, the AI asked once, and the event finished — not handed back to be retried', er_cshow($c));
is_(($p['state'] ?? '') === 'needs_human' && (int)($p['escalations'] ?? 0) === 1,
    'and a person takes over the conversation', er_cshow($c));
$c = er_caller($copy, 'south-sudan', 'ai', 2);
$p = $c['probe'];
is_($c['sends'] === 6 && (int)($p['brain_calls'] ?? -1) === 2 && ($p['event']['status'] ?? '') === 'failed',
    'South Sudan, as in 5.18.53: the AI asked twice, the reply sent six times (three per run)', er_cshow($c));

echo "\n8. The follow-up sender, when its message may have gone (two runs)\n";
$winZone = er_window_zone();
$winWhy  = 'no zone on Earth is inside the follow-up sending window at ' . gmdate('D H:i') . ' UTC (a Sunday between ~08:00 and ~18:00 UTC); the check runs at any other hour';
if ($winZone === '') {
    skip_('Uganda: one send, and the draft set aside as uncertain, with the reason logged — the second run sends nothing', $winWhy);
    skip_('South Sudan, as in 5.18.53: the draft stays approved and goes again at the next run', $winWhy);
} else {
    echo "  (window zone {$winZone})\n";
    $c = er_caller($copy, 'uganda', 'followup', 2);
    $p = $c['probe'];
    is_($c['sends'] === 1 && ($p['draft'] ?? '') === 'uncertain' && in_array('uncertain', (array)($p['events'] ?? []), true),
        'Uganda: one send, and the draft set aside as uncertain, with the reason logged — the second run sends nothing', er_cshow($c));
    $c = er_caller($copy, 'south-sudan', 'followup', 2);
    $p = $c['probe'];
    is_($c['sends'] === 6 && ($p['draft'] ?? '') === 'approved',
        'South Sudan, as in 5.18.53: the draft stays approved and goes again at the next run', er_cshow($c));
}
exec('rm -rf ' . escapeshellarg($copy));

// ══════════════════════════════════════════════════════════════════════════════
echo "\n9. Weakened copies, each caught\n";
$mutants = [
    'a request that left is sent again' => ['lib/EvolutionApiService.php',
        "                if ((\$read || \$unsent) && \$attempt < 3) {\n",
        "                if (\$attempt < 3) {\n",
        ['post', 'hang', 'http'], fn(array $c) => er_requests($c) === 3, 'the hung request arrived three times'],
    'a request that never left is not retried' => ['lib/EvolutionApiService.php',
        "            \$unsent    = (float)curl_getinfo(\$ch, CURLINFO_PRETRANSFER_TIME) == 0.0\n",
        "            \$unsent    = false && (float)curl_getinfo(\$ch, CURLINFO_PRETRANSFER_TIME) == 0.0\n",
        ['post', 'tls', 'https'], fn(array $c) => count($c['conns']) === 1, 'one connection, where the fix makes three'],
    'the Uganda branch never taken' => ['lib/EvolutionApiService.php',
        "        if (\$this->noResend()) return \$this->requestUg(\$method, \$path, \$body);   // 5.18.54, docs/46 row 31\n",
        "        if (false) return \$this->requestUg(\$method, \$path, \$body);   // 5.18.54, docs/46 row 31\n",
        ['post', 'hang', 'http'], fn(array $c) => er_requests($c) === 3, 'Uganda sent the hung request three times'],
];
$callerMutants = [
    'the AI worker hands a doubtful send back to be retried' => ['workers/AiReplyWorker.php',
        "            if (\\EvolutionApiService::mayHaveBeenSent(\$send) && \$this->noResendAfterDoubt()) {\n",
        "            if (false) {\n",
        ['ai', 2], fn(array $c) => $c['sends'] === 2 && (int)($c['probe']['brain_calls'] ?? 0) === 2, 'the AI was asked twice and the reply sent twice'],
    'the follow-up sender leaves a doubtful draft approved' => ['cron/followup_send.php',
        "                \$svc->recordUncertain(\$fuId, (int)\$d['id'], (string)\$res['error'], 'sender');\n                \$failed++;\n                continue;\n",
        "",
        ['followup', 2], fn(array $c) => $c['sends'] === 2 && ($c['probe']['draft'] ?? '') === 'approved', 'the follow-up went twice'],
];
foreach ($withMutants ? $callerMutants : [] as $name => [$rel, $o_, $n_, [$what, $runs], $caught, $why]) {
    if ($what === 'followup' && $winZone === '') { skip_("caught: {$name}", $winWhy); continue; }
    [$tree, $n] = sj_weakened_copy($root, $rel, $o_, $n_);
    if ($n !== 1) { is_(false, "caught: {$name}", "the anchor was not found exactly once in {$rel}"); exec('rm -rf ' . escapeshellarg($tree)); continue; }
    $c = er_caller($tree, 'uganda', $what, $runs);
    $ok = (bool)$caught($c);
    is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''), er_cshow($c));
    exec('rm -rf ' . escapeshellarg($tree));
}
foreach ($withMutants ? $mutants : [] as $name => [$rel, $o_, $n_, [$op, $mode, $scheme], $caught, $why]) {
    [$tree, $n] = sj_weakened_copy($root, $rel, $o_, $n_);
    if ($n !== 1) { is_(false, "caught: {$name}", "the anchor was not found exactly once in {$rel}"); exec('rm -rf ' . escapeshellarg($tree)); continue; }
    $c = er_case($tree, 'uganda', $op, $mode, $scheme);
    $ok = (bool)$caught($c);
    is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''), er_show($c));
    exec('rm -rf ' . escapeshellarg($tree));
}

echo "\n{$pass} passed, {$fail} failed" . ($skip ? ", {$skip} skipped" : '') . "\n";
exit($fail ? 1 : 0);

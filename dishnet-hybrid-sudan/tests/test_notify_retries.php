<?php
declare(strict_types=1);
/**
 * test_notify_retries.php — 5.18.54, docs/46 row 30 (M4): failed customer WhatsApps are retried automatically, a bounded
 * number of times, only when the message cannot have reached the customer and is still true; the Failed Queue shows
 * what the automatic retry did.
 *
 * The real notifier and the real cron/notify_retry.php, from a copy of the plugin (tests/fixtures/notify_retry_probe.php),
 * against a socket-level fake Evolution that records every request it receives (tests/fixtures/fake_evo_raw.php):
 *
 *    1. a refused receipt is retried, and sent once
 *    2. a timeout is never retried automatically, and Retry All leaves it out
 *    3. after the last try the row reads exhausted; a person can still retry it; nothing automatic follows
 *    4. a request that never left is retried
 *    5. only receipts, welcomes and quotations
 *    6. a row being retried is not sent a second time; a try that never finished is left for a person
 *    7. retry mode ends when a send throws (5.18.53 measured: later failures went unqueued)
 *    8. a retry that sent nothing is not reported sent (5.18.53 measured: a document row read the last send's success)
 *    9. South Sudan: no automatic retry, as in 5.18.53
 *   10. the schedule and the classifier
 *   11. the Failed Queue screen, the badge and a person's retry of an exhausted row, both countries
 *   12. weakened copies, each caught
 *
 * Nothing leaves the machine. `--no-mutants` skips 12.
 */
$root = dirname(__DIR__);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';
$withMutants = !in_array('--no-mutants', $argv, true);

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const NR_MAYBE      = 'May have been sent — ';
const NR_NOT_SENT   = 'Not sent — ';
const NR_NO_ATTEMPT = 'Not sent — no attempt was made';
const NR_REFUSED    = '[HTTP 400 on POST /message/sendText/fake_inst]';

/** A plain copy of the plugin: the cron finds its data directory beside it, as on the server. */
function nr_tree(string $root): string
{
    $tmp = sys_get_temp_dir() . '/nr-tree-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($tmp, 0700, true);
    foreach (scandir($root) ?: [] as $e) {
        if ($e === '.' || $e === '..' || in_array($e, ['docs', 'prototype', 'dishnet-mikrotik-control-plane', 'data', '.git'], true)) continue;
        exec('cp -R ' . escapeshellarg($root . '/' . $e) . ' ' . escapeshellarg($tmp . '/') . ' 2>/dev/null');
    }
    return $tmp;
}
function nr_data(string $tree): string { return dirname($tree) . '/.' . basename($tree) . '-data'; }
function nr_fresh(string $tree): void
{
    $dd = nr_data($tree);
    exec('rm -rf ' . escapeshellarg($dd));
    mkdir($dd, 0700, true);
}
function nr_drop(string $tree): void { exec('rm -rf ' . escapeshellarg($tree) . ' ' . escapeshellarg(nr_data($tree))); }

/** The fake Evolution, on a free port or the one given, answering each connection as the next mode says. */
function nr_fake(string $modes, int $port = 0): array
{
    $dir = sys_get_temp_dir() . '/nr-f-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($dir, 0700, true);
    $tr = $dir . '/transcript.json';
    foreach (range(0, 9) as $slot) {
        $p = $port ?: 9700 + ((getmypid() + $slot * 7 + random_int(0, 6)) % 90);
        $proc = proc_open(sprintf('exec %s %s %d %s %s 60', escapeshellarg(PHP_BINARY),
                    escapeshellarg(dirname(__DIR__) . '/tests/fixtures/fake_evo_raw.php'), $p, escapeshellarg($tr), escapeshellarg($modes)),
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $dir . '/fake.err', 'w']], $pipes);
        for ($i = 0; $i < 40 && !is_file($tr); $i++) usleep(50000);
        if (is_file($tr)) return ['proc' => $proc, 'tr' => $tr, 'dir' => $dir, 'url' => "http://127.0.0.1:{$p}", 'port' => $p];
        proc_terminate($proc); proc_close($proc);
        if ($port) break;
    }
    throw new \RuntimeException('the fake Evolution did not start');
}
/** Requests the fake received (a connection that sent no HTTP request is not one). */
function nr_requests(array $f): int
{
    $all = json_decode((string)@file_get_contents($f['tr']), true) ?: [];
    return count(array_filter($all, fn($x) => !empty($x['http'])));
}
function nr_stop(array $f): void { @proc_terminate($f['proc']); @proc_close($f['proc']); exec('rm -rf ' . escapeshellarg($f['dir'])); }
/** A port nothing listens on. */
function nr_closed_port(): int
{
    foreach (range(0, 20) as $i) {
        $p = 9600 + ((getmypid() + $i * 11 + random_int(0, 9)) % 90);
        $c = @fsockopen('127.0.0.1', $p, $e, $s, 0.2);
        if (!$c) return $p;
        fclose($c);
    }
    return 9599;
}

/** One probe step, by the tree's own probe (a weakened copy runs its own); its last line is its answer. */
function nr_probe(string $tree, string $tenant, string $step, string $url): array
{
    $cmd = sprintf('%s %s %s %s %s %s %s 2>&1', escapeshellarg(PHP_BINARY),
        escapeshellarg($tree . '/tests/fixtures/notify_retry_probe.php'),
        escapeshellarg($tree), escapeshellarg($tenant), escapeshellarg($step), escapeshellarg($url), escapeshellarg(nr_data($tree)));
    $lines = [];
    exec($cmd, $lines);
    $last = '';
    foreach (array_reverse($lines) as $l) if (trim($l) !== '') { $last = $l; break; }
    return (json_decode($last, true) ?: []) + ['_raw' => mb_substr(implode("\n", $lines), -700)];
}
function nr_row(array $p, int $id): array { foreach ($p['queue'] ?? [] as $r) if ((int)$r['id'] === $id) return $r; return []; }
function nr_show(array $p): string { unset($p['_raw']); return json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

// ── The cases, as functions of the plugin tree, so a weakened copy runs the very same ones ─────────────────────────
// Each returns [bool holds, what, detail] triples.

/** 1. A refused receipt: refused, refused, then taken. */
$caseRefused = function (string $tree): array {
    nr_fresh($tree);
    $f = nr_fake('refuse,refuse,ok');
    $s = nr_probe($tree, 'uganda', 'send:receipt', $f['url']);
    $r0 = nr_row($s, 1);
    $runs = [];
    for ($i = 0; $i < 4; $i++) $runs[] = nr_probe($tree, 'uganda', 'job', $f['url']);
    $end = end($runs);
    $row = nr_row($end, 1);
    $req = nr_requests($f);
    nr_stop($f);
    return [
        [($r0['status'] ?? '') === 'failed' && ($r0['event'] ?? '') === 'ops_payment_received' && strpos((string)($r0['error'] ?? ''), NR_REFUSED) !== false,
         'the receipt WhatsApp refused: queued as failed, with the refusal', nr_show($s)],
        [($row['status'] ?? '') === 'sent' && (int)($row['attempts'] ?? 0) === 3 && ($row['retry_by'] ?? '') === 'automatic retry',
         'retried automatically, refused once more, then sent: the row reads sent, 3 attempts, by the automatic retry', nr_show($end)],
        [$req === 3, 'three requests reached WhatsApp — the first send and two tries — and the fourth run sent nothing', "requests={$req}"],
    ];
};

/** 2. A timeout: the request left and no answer came back. */
$caseTimeout = function (string $tree): array {
    nr_fresh($tree);
    $f = nr_fake('hang');
    $s = nr_probe($tree, 'uganda', 'send:receipt', $f['url']);
    $j = null;
    for ($i = 0; $i < 3; $i++) $j = nr_probe($tree, 'uganda', 'job', $f['url']);
    $b = nr_probe($tree, 'uganda', 'bulk', $f['url']);
    $row = nr_row($b, 1);
    $req = nr_requests($f);
    nr_stop($f);
    return [
        [strpos((string)(nr_row($s, 1)['error'] ?? ''), NR_MAYBE) === 0, 'the receipt timed out after it left: queued, "May have been sent"', nr_show($s)],
        [strpos((string)($j['run'] ?? ''), 'due=0 tried=0') !== false && (int)(nr_row($j, 1)['attempts'] ?? 0) === 1,
         'three automatic runs: none tried it', nr_show($j)],
        [(int)($b['result']['skipped'] ?? -1) === 1 && (int)($b['result']['total'] ?? -1) === 0 && ($row['status'] ?? '') === 'failed',
         'Retry All left it out, and says so (skipped 1)', nr_show($b)],
        [$req === 1, 'one request in all: the customer can have the receipt at most once', "requests={$req}"],
    ];
};

/** 3. Refused every time: the last try, then exhausted. */
$caseExhausted = function (string $tree): array {
    nr_fresh($tree);
    $f = nr_fake('refuse');
    nr_probe($tree, 'uganda', 'send:receipt', $f['url']);
    $runs = [];
    for ($i = 0; $i < 5; $i++) $runs[] = nr_probe($tree, 'uganda', 'job', $f['url']);
    $row = nr_row($runs[4], 1);
    $afterJobs = nr_requests($f);
    $p = nr_probe($tree, 'uganda', 'retry:1', $f['url']);
    $afterPerson = nr_requests($f);
    $j = nr_probe($tree, 'uganda', 'job', $f['url']);
    $afterAll = nr_requests($f);
    nr_stop($f);
    return [
        [($row['status'] ?? '') === 'exhausted' && (int)($row['attempts'] ?? 0) === 4,
         'after the first send and three tries the row reads exhausted, 4 attempts', nr_show($runs[4])],
        [strpos((string)($runs[2]['run'] ?? ''), 'exhausted=1') !== false && strpos((string)($runs[3]['run'] ?? ''), 'due=0') !== false,
         'the third try exhausted it; the fourth and fifth runs found nothing due', nr_show($runs[2]) . ' ' . nr_show($runs[3])],
        [$afterJobs === 4, 'four requests: the send and three tries — bounded', "requests={$afterJobs}"],
        [!empty($p['result']['claimed']) && $afterPerson === 5, 'a person can still retry the exhausted row: it is sent to WhatsApp once more', nr_show($p) . " requests={$afterPerson}"],
        [$afterAll === 5 && strpos((string)($j['run'] ?? ''), 'due=0') !== false, 'and no automatic try follows it', nr_show($j)],
    ];
};

/** 4. The request never left (nothing listening), then WhatsApp is back. */
$caseNeverLeft = function (string $tree): array {
    nr_fresh($tree);
    $port = nr_closed_port();
    $s = nr_probe($tree, 'uganda', 'send:welcome', "http://127.0.0.1:{$port}");
    $f = nr_fake('ok', $port);
    $j = nr_probe($tree, 'uganda', 'job', $f['url']);
    $req = nr_requests($f);
    nr_stop($f);
    return [
        [strpos((string)(nr_row($s, 1)['error'] ?? ''), NR_NOT_SENT) === 0, 'the welcome could not connect: queued, "Not sent"', nr_show($s)],
        [(nr_row($j, 1)['status'] ?? '') === 'sent' && $req === 1, 'the next run sent it: one request', nr_show($j) . " requests={$req}"],
    ];
};

/** 5. Only receipts, welcomes and quotations. */
$caseOnlyThese = function (string $tree): array {
    nr_fresh($tree);
    $f = nr_fake('refuse');
    foreach (['reminder', 'staff', 'welcome', 'quote'] as $k) nr_probe($tree, 'uganda', 'send:' . $k, $f['url']);
    $j = nr_probe($tree, 'uganda', 'job', $f['url']);
    $req = nr_requests($f);
    nr_stop($f);
    $by = [];
    foreach ($j['queue'] ?? [] as $r) $by[$r['event']] = (int)$r['attempts'];
    return [
        [($by['ops_pre_due_d1'] ?? 0) === 1, 'a payment reminder, which a payment can overtake, is not retried automatically', json_encode($by)],
        [($by['ops_scheduling_job_assigned'] ?? 0) === 1, 'nor a staff message', json_encode($by)],
        [($by['event_client_add'] ?? 0) === 2 && ($by['ops_quote_created'] ?? 0) === 2, 'a welcome and a quotation are', json_encode($by)],
        [$req === 6, 'six requests: four sends and two tries', "requests={$req}"],
    ];
};

/** 6. A row being retried, and a try that never finished. */
$caseBusy = function (string $tree): array {
    nr_fresh($tree);
    $f = nr_fake('ok');
    $err = 'FAKE-RAW refused: the instance is not connected ' . NR_REFUSED;
    nr_probe($tree, 'uganda', 'seed:' . json_encode([
        ['event' => 'ops_payment_received', 'status' => 'retrying', 'error' => $err, 'ago_min' => 0],
        ['event' => 'ops_payment_received', 'status' => 'retrying', 'error' => $err, 'ago_min' => 20],
    ]), $f['url']);
    $p = nr_probe($tree, 'uganda', 'retry:1', $f['url']);
    $j = nr_probe($tree, 'uganda', 'job', $f['url']);
    $b = nr_probe($tree, 'uganda', 'bulk', $f['url']);
    $req = nr_requests($f);
    nr_stop($f);
    return [
        [empty($p['result']['claimed']) && empty($p['result']['success']), 'a person\'s retry of a row already being retried is refused', nr_show($p)],
        [(nr_row($j, 1)['status'] ?? '') === 'retrying', 'the automatic retry leaves a try in progress alone', nr_show($j)],
        [(nr_row($j, 2)['status'] ?? '') === 'failed' && strpos((string)(nr_row($j, 2)['error'] ?? ''), NR_MAYBE) === 0
         && strpos((string)($j['run'] ?? ''), 'unfinished=1') !== false,
         'a try that never finished (20 minutes on) reads failed, "May have been sent", for a person', nr_show($j)],
        [(int)($b['result']['skipped'] ?? 0) === 1 && (int)($b['result']['total'] ?? -1) === 0, 'Retry All leaves it out', nr_show($b)],
        [$req === 0, 'nothing was sent', "requests={$req}"],
    ];
};

/** 7. A retry whose send throws; then an ordinary send that fails, in the same process. */
$caseStuckMode = function (string $tree, string $tenant): array {
    nr_fresh($tree);
    $f = nr_fake('refuse');
    nr_probe($tree, $tenant, 'seed:' . json_encode([['event' => 'ops_payment_received',
        'error' => 'FAKE-RAW refused: the instance is not connected ' . NR_REFUSED]]), $f['url']);
    $p = nr_probe($tree, $tenant, 'stuck-mode:1', $f['url']);
    $req = nr_requests($f);
    nr_stop($f);
    return ['probe' => $p, 'requests' => $req, 'row1' => nr_row($p, 1), 'row2' => nr_row($p, 2)];
};

/** 8. A send that goes, then a person's retry of a document row whose recipient opted out of everything. */
$caseStale = function (string $tree, string $tenant): array {
    nr_fresh($tree);
    $f = nr_fake('ok');
    nr_probe($tree, $tenant, 'seed:' . json_encode([['event' => 'document_send', 'phone' => '256700000009',
        'vars' => json_encode(['_type' => 'document', 'url' => 'https://example.test/r.pdf', 'filename' => 'r.pdf']),
        'error' => 'FAKE-RAW refused [HTTP 400 on POST /message/sendMedia/fake_inst]']]), $f['url']);
    $p = nr_probe($tree, $tenant, 'stale-success:1', $f['url']);
    $req = nr_requests($f);
    nr_stop($f);
    return ['probe' => $p, 'requests' => $req, 'row1' => nr_row($p, 1)];
};

/** 9. South Sudan: the job does nothing. */
$caseSouthSudan = function (string $tree): array {
    nr_fresh($tree);
    $f = nr_fake('refuse,ok');
    nr_probe($tree, 'south-sudan', 'send:receipt', $f['url']);
    $j = null;
    for ($i = 0; $i < 3; $i++) $j = nr_probe($tree, 'south-sudan', 'job', $f['url']);
    $req = nr_requests($f);
    nr_stop($f);
    $row = nr_row($j, 1);
    return [
        [strpos((string)($j['run'] ?? ''), 'Not this install') !== false, 'the job says it is not this install\'s', nr_show($j)],
        [$req === 1 && ($row['status'] ?? '') === 'failed' && (int)($row['attempts'] ?? 0) === 1,
         'one request; the row still waits for a person, as in 5.18.53', nr_show($j) . " requests={$req}"],
    ];
};

/** 11. The Failed Queue screen, the badge, and a person's retry of an exhausted row. */
$caseScreen = function (string $tree, string $tenant): array {
    $base = $tenant === 'uganda' ? ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'] : ['timezone' => 'Africa/Juba'];
    $s = SjSandbox::start($tree, $base, 'nr' . substr($tenant, 0, 2));
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
    $s->q("CREATE TABLE IF NOT EXISTS notification_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT NOT NULL DEFAULT 'support',
           phone TEXT NOT NULL, message TEXT NOT NULL, event TEXT DEFAULT NULL, vars TEXT DEFAULT NULL, status TEXT NOT NULL DEFAULT 'failed',
           http_code INTEGER DEFAULT NULL, error TEXT DEFAULT NULL, attempts INTEGER NOT NULL DEFAULT 1, last_attempt_at TEXT NOT NULL,
           retry_at TEXT DEFAULT NULL, retry_by TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
    $refused = 'FAKE-RAW refused ' . NR_REFUSED;
    $rows = [
        [1, 'ops_payment_received', 'failed',    1, $refused],                                   // an automatic try pending
        [2, 'ops_payment_received', 'exhausted', 4, $refused],                                   // tries used up
        [3, 'event_client_add',     'failed',    1, NR_MAYBE . 'no answer from Evolution: timeout'],
        [4, 'ops_pre_due_d1',       'failed',    1, $refused],                                   // never retried automatically
    ];
    foreach ($rows as [$id, $ev, $st, $n, $err]) {
        $s->q("INSERT INTO notification_queue (id, sender, phone, message, event, status, attempts, error, last_attempt_at)
               VALUES (?, 'accounts', ?, ?, ?, ?, ?, ?, ?)", [$id, '25670000094' . $id, "CANARY-RETRY-{$id}", $ev, $st, $n, $err, date('Y-m-d H:i:s')]);
    }
    $s->login('admin', 'admin@example.test', 'sj-password-1');
    $s->q("CREATE TABLE IF NOT EXISTS plugin_kv (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT DEFAULT (datetime('now')))");
    $s->q("DELETE FROM plugin_kv WHERE key = 'nav_badges'");   // the badge counts cached at sign-in, before these rows
    $html = $s->page('admin', 'page=dashboard&tab=engage_failed_queue&fqsub=queue');
    $texts0 = count($s->texts());
    $retry = $s->api('admin', 'POST', 'notification_retry', [], '&id=2');
    $sent = array_values(array_filter(array_slice($s->texts(), $texts0), fn($t) => strpos((string)$t['text'], 'CANARY-RETRY-2') !== false));
    $row2 = $s->q('SELECT status FROM notification_queue WHERE id = 2')[0]['status'] ?? '';
    $s->stop();
    preg_match('/Failed \((\d+)\)/', $html, $m);
    return ['html' => $html, 'badge' => (int)($m[1] ?? -1), 'retry' => $retry, 'sent2' => count($sent), 'row2' => $row2,
            'retry_button_2' => (bool)preg_match('/name="fq_action" value="retry_one"><input type="hidden" name="queue_id" value="2"/', $html)];
};

// ══════════════════════════════════════════════════════════════════════════════
$tree = nr_tree($root);

echo "\n1. Uganda — a refused receipt is retried, and sent once\n";
foreach ($caseRefused($tree) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n2. Uganda — a timeout is never retried automatically\n";
foreach ($caseTimeout($tree) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n3. Uganda — after the last try, exhausted\n";
foreach ($caseExhausted($tree) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n4. Uganda — a request that never left is retried\n";
foreach ($caseNeverLeft($tree) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n5. Uganda — only receipts, welcomes and quotations\n";
foreach ($caseOnlyThese($tree) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n6. Uganda — a row being retried, and a try that never finished\n";
foreach ($caseBusy($tree) as [$ok, $m, $d]) is_($ok, $m, $d);

echo "\n7. Retry mode ends when a send throws\n";
$u = $caseStuckMode($tree, 'uganda');
is_(strpos((string)($u['probe']['retry']['error'] ?? ''), NR_MAYBE . 'the retry stopped with an error') === 0 && ($u['row1']['status'] ?? '') === 'failed',
    'Uganda: the retry reports it stopped, and its row reads failed, "May have been sent" — not left retrying', nr_show($u['probe']));
is_(($u['row2']['event'] ?? '') === 'ops_payment_received' && ($u['row2']['status'] ?? '') === 'failed' && $u['requests'] === 1,
    'Uganda: a later failed send in the same process is queued', nr_show($u['probe']));
$ss = $caseStuckMode($tree, 'south-sudan');
is_(($ss['row1']['status'] ?? '') === 'retrying' && $ss['row2'] === [] && $ss['requests'] === 1,
    'South Sudan, as in 5.18.53 (measured): the row is left retrying, and the later failure is lost from the queue', nr_show($ss['probe']));

echo "\n8. A retry that sent nothing is not reported sent\n";
$u = $caseStale($tree, 'uganda');
is_(!empty($u['probe']['first']['success']) && empty($u['probe']['retry']['success'])
    && strpos((string)($u['probe']['retry']['error'] ?? ''), NR_NO_ATTEMPT) === 0 && ($u['row1']['status'] ?? '') === 'failed' && $u['requests'] === 1,
    'Uganda: the opted-out document row reads failed, "no attempt was made"; one request in all (the first send)', nr_show($u['probe']));
$ss = $caseStale($tree, 'south-sudan');
is_(!empty($ss['probe']['retry']['success']) && ($ss['row1']['status'] ?? '') === 'sent' && $ss['requests'] === 1,
    'South Sudan, as in 5.18.53 (measured): the row reads sent, and nothing was sent for it', nr_show($ss['probe']));

echo "\n9. South Sudan — no automatic retry\n";
foreach ($caseSouthSudan($tree) as [$ok, $m, $d]) is_($ok, $m, $d);
$master = (string)file_get_contents($root . '/cron/master.php');
$jobLine = '';
foreach (explode("\n", $master) as $line) if (preg_match("/^\s*'notify_retry'\s*=>\s*\['interval'/", $line)) $jobLine = $line;
is_(strpos($jobLine, "'interval' => 240") !== false && strpos($jobLine, "'gate' => 'retries'") !== false
    && strpos($jobLine, "__DIR__ . '/notify_retry.php'") !== false,
    'master.php runs it at every cycle (240 s, under its ~300 s heartbeat), gated to the retries fix', $jobLine);
require_once $root . '/lib/NotifyGate.php';
is_(NotifyGate::RETRIES === 'retries', 'the gate named there is NotifyGate::RETRIES');

echo "\n10. The schedule and the classifier\n";
require_once $root . '/lib/NotificationRetry.php';
$cls = [
    [NR_NOT_SENT . 'no connection to Evolution: refused', null, NotificationRetry::NOT_SENT],
    ['Bad Request ' . NR_REFUSED, null, NotificationRetry::REFUSED],
    [NR_MAYBE . 'no answer from Evolution: timeout', null, NotificationRetry::MAYBE_SENT],
    ['Bad Gateway [HTTP 502 on POST /message/sendText/x]', null, NotificationRetry::MAYBE_SENT],
    ['Gateway Timeout [HTTP 504 on POST /message/sendText/x]', null, NotificationRetry::MAYBE_SENT],
    ['Internal Server Error [HTTP 500 on POST /message/sendText/x]', null, NotificationRetry::UNKNOWN],
    ['{"success":false}', 404, NotificationRetry::REFUSED],
    ['Operation timed out after 20001 milliseconds', 0, NotificationRetry::UNKNOWN],
    [NotificationRetry::NO_ATTEMPT_TEXT, null, NotificationRetry::NO_ATTEMPT],
    [NotificationRetry::UNFINISHED_TEXT, null, NotificationRetry::MAYBE_SENT],
    ['', null, NotificationRetry::UNKNOWN],
];
$bad = [];
foreach ($cls as [$e, $h, $want]) if (NotificationRetry::classify($e, $h) !== $want) $bad[] = "{$e} → " . NotificationRetry::classify($e, $h) . " (want {$want})";
is_($bad === [], 'refused (a 4xx) and not sent are certain; may-have-been-sent (502, 504, no answer, an unfinished try) and anything unreadable (a 500, a WASender timeout) are not', implode('; ', $bad));
$t0 = strtotime('2026-09-30 10:00:00');
$row = ['status' => 'failed', 'event' => 'ops_payment_received', 'error' => 'x ' . NR_REFUSED, 'http_code' => null,
        'created_at' => gmdate('Y-m-d H:i:s', $t0), 'last_attempt_at' => date('Y-m-d H:i:s', $t0)];
$at = fn(int $n, int $lastOffsetMin = 0) => NotificationRetry::nextTryAt(['attempts' => $n, 'last_attempt_at' => date('Y-m-d H:i:s', $t0 + $lastOffsetMin * 60)] + $row);
is_($at(1) === $t0 + 600 && $at(2) === $t0 + 1800 && $at(3) === $t0 + 7200 && $at(4) === null,
    'tries 10, 30 and 120 minutes after the attempt before; none after the fourth attempt', json_encode([$at(1), $at(2), $at(3), $at(4)]));
is_($at(3, 300) === null && $at(1, 300) === $t0 + 18600, 'no try later than six hours after the message was queued', json_encode([$at(3, 300), $at(1, 300)]));
is_(NotificationRetry::nextTryAt(['event' => 'ops_pre_due_d1', 'attempts' => 1] + $row) === null
    && NotificationRetry::nextTryAt(['vars' => json_encode(['_type' => 'document', 'url' => 'u']), 'attempts' => 1] + $row) === null
    && NotificationRetry::nextTryAt(['status' => 'exhausted', 'attempts' => 1] + $row) === null
    && NotificationRetry::nextTryAt(['error' => NR_MAYBE . 'x', 'attempts' => 1] + $row) === null,
    'never a reminder, a document, an exhausted row, or a message that may have been sent');
is_(NotificationRetry::EVENTS === ['ops_payment_received', 'ops_invoice_auto_paid', 'event_client_add', 'ops_kyc_customer_welcome',
        'ops_quote_created', 'ops_quote_wa', 'ops_quote_text', 'quote_kyc', 'quote_lead', 'quote_cash', 'quote_manual'],
    'the retried messages are exactly the receipts, the welcomes and the quotations', json_encode(NotificationRetry::EVENTS));
$ns = (string)file_get_contents($root . '/lib/NotificationService.php');
foreach (['ops_payment_received', 'ops_invoice_auto_paid', 'ops_kyc_customer_welcome'] as $ev) {
    is_(substr_count($ns, "'{$ev}'") >= 1, "the notifier sends {$ev} under that name");
}
$srcs = (string)file_get_contents($root . '/webhook.php') . file_get_contents($root . '/cron_quote_wa.php')
      . file_get_contents($root . '/includes/api/api_whatsapp.php') . file_get_contents($root . '/lib/QuotationService.php');
$missing = [];
foreach (['event_client_add', 'ops_quote_created', 'ops_quote_wa', 'ops_quote_text', 'quote_kyc', 'quote_lead', 'quote_cash', 'quote_manual'] as $ev) {
    if (strpos($srcs, "'{$ev}'") === false) $missing[] = $ev;
}
is_($missing === [], 'every other name in the list is one a sender uses', implode(', ', $missing));

echo "\n11. The Failed Queue screen, both countries\n";
$su = $caseScreen($tree, 'uganda');
$h = $su['html'];
is_(strpos($h, 'Retries used up') !== false && strpos($h, 'automatic retry at ') !== false && strpos($h, 'no automatic try left') !== false
    && strpos($h, 'may have been sent: check the chat first') !== false,
    'Uganda: the screen says what happens next — an automatic retry at a time, no try left, or "may have been sent"', substr(strip_tags($h), 0, 300));
is_($su['retry_button_2'], 'Uganda: an exhausted row can be retried from the screen');
is_($su['badge'] === 4, 'Uganda: the navigation counts the exhausted row with the failed ones (4)', "badge={$su['badge']}");
is_(($su['retry'][0] ?? 0) === 200 && $su['sent2'] === 1 && $su['row2'] === 'sent', 'Uganda: a person\'s retry of the exhausted row sends it once, and it reads sent',
    json_encode(['http' => $su['retry'][0] ?? null, 'sent' => $su['sent2'], 'row2' => $su['row2']]));
$ss = $caseScreen($tree, 'south-sudan');
$h = $ss['html'];
is_(strpos($h, 'Retries used up') === false && strpos($h, 'automatic retry at ') === false && strpos($h, 'may have been sent: check') === false
    && !$ss['retry_button_2'] && $ss['badge'] === 3, 'South Sudan: the screen and the badge as in 5.18.53', "badge={$ss['badge']}");
is_(($ss['retry'][0] ?? 0) === 422 && $ss['sent2'] === 0, 'South Sudan: the API refuses to retry a row not marked failed, as in 5.18.53',
    json_encode(['http' => $ss['retry'][0] ?? null, 'sent' => $ss['sent2']]));
nr_drop($tree);

// ══════════════════════════════════════════════════════════════════════════════
echo "\n12. Weakened copies, each caught\n";
$held = fn(array $triples) => array_reduce($triples, fn($c, $t) => $c && $t[0], true);
$failedWhere = function (array $triples, string $what): bool {
    foreach ($triples as [$ok, $m]) if (!$ok && strpos($m, $what) !== false) return true;
    return false;
};
/** Row 46's clearing at the start of a document send, as lib/NotificationService.php has it. */
const NR_DOC_CLEAR = "        if (\$this->quoteLogUg()) { \$this->_lastSendSuccess = false; \$this->_lastHttpCode = null; \$this->_lastError = null; }\n";
$mutants = [
    'every failure retried, a timeout included' => [[['lib/NotificationRetry.php',
        "        return \$class === self::NOT_SENT || \$class === self::REFUSED;\n", "        return true;\n"]],
        fn(string $t) => $failedWhere($caseTimeout($t), 'none tried it'), 'the timed-out receipt was tried again'],
    'a reminder in the list' => [[['lib/NotificationRetry.php',
        "        'ops_payment_received', 'ops_invoice_auto_paid',\n", "        'ops_payment_received', 'ops_invoice_auto_paid', 'ops_pre_due_d1',\n"]],
        fn(string $t) => $failedWhere($caseOnlyThese($t), 'payment reminder'), 'the reminder was retried'],
    'no exhausted mark' => [[['lib/NotificationRetry.php',
        "                \$pdo->prepare(\"UPDATE notification_queue SET status = 'exhausted' WHERE id = ? AND status = 'failed'\")->execute([\$id]);\n", '']],
        fn(string $t) => $failedWhere($caseExhausted($t), 'reads exhausted'), 'the row never read exhausted'],
    'the bound on the tries raised' => [[['lib/NotificationRetry.php',
        "    public const MAX_ATTEMPTS = 4;\n", "    public const MAX_ATTEMPTS = 99;\n"],
        ['lib/NotificationRetry.php',
        "        if (\$n >= self::MAX_ATTEMPTS || !isset(self::GAP_MIN[\$n])) return null;\n", "        if (\$n >= self::MAX_ATTEMPTS) return null;\n"],
        ['lib/NotificationRetry.php', "        \$at = \$last + self::GAP_MIN[\$n] * 60;\n", "        \$at = \$last + self::GAP_MIN[min(\$n, 3)] * 60;\n"],
        ['tests/fixtures/notify_retry_probe.php', "            \$gap = NotificationRetry::GAP_MIN[max(1, (int)\$r['attempts'])] ?? 0;\n",
                                                  "            \$gap = NotificationRetry::GAP_MIN[min(3, max(1, (int)\$r['attempts']))] ?? 0;\n"]],
        fn(string $t) => $failedWhere($caseExhausted($t), 'bounded'), 'more than four requests'],
    'retry mode left on after an exception' => [[['lib/NotificationService.php',
        "        } finally {\n            \$this->_retryMode = false;\n        }\n\n        \$sent = \$stopped === null", "        }\n\n        \$sent = \$stopped === null"]],
        fn(string $t) => ($c = $caseStuckMode($t, 'uganda')) && $c['row2'] === [], 'the later failure went unqueued'],
    // Since row 46 a document send clears the last result itself on Uganda (QUOTE_ONCE), so the retry's own clearing is
    // a second guard there. It is the only one wherever RETRIES applies and QUOTE_ONCE does not — each fix is approved
    // for South Sudan on its own (docs/46 E-4) — which this copy models by switching the document send's clearing off.
    // The control after the loop shows that the retry's clearing then holds by itself.
    'the last result not cleared before a retry (where the document send does not clear it)' => [[['lib/NotificationService.php',
        "        \$this->_lastSendSuccess = false; \$this->_lastHttpCode = null; \$this->_lastError = null;\n        \$this->_retryMode = true;\n        \$stopped = null;\n",
        "        \$this->_retryMode = true;\n        \$stopped = null;\n"],
        ['lib/NotificationService.php', NR_DOC_CLEAR, '']],
        fn(string $t) => ($c = $caseStale($t, 'uganda')) && ($c['row1']['status'] ?? '') === 'sent', 'the opted-out row read sent'],
    'Retry All sends what may have been sent' => [[['lib/NotificationService.php',
        "                if (\\NotificationRetry::classify(\$row['error'], \$row['http_code']) === \\NotificationRetry::MAYBE_SENT) {\n                    \$result['skipped']++;\n                    continue;\n                }\n", '']],
        fn(string $t) => $failedWhere($caseTimeout($t), 'Retry All left it out'), 'Retry All sent the timed-out receipt'],
    'a row in progress claimed again' => [[['lib/NotificationService.php',
        "                                     WHERE id = ? AND status IN ('failed', 'exhausted')\");", "                                     WHERE id = ? AND status IN ('failed', 'exhausted', 'retrying')\");"]],
        fn(string $t) => $failedWhere($caseBusy($t), 'already being retried'), 'a person\'s retry sent a row being retried'],
    'the job not gated' => [[['cron/notify_retry.php',
        "if (!NotifyGate::applies(NotifyGate::RETRIES, \$config, \$dataDir)) {\n", "if (false) {\n"]],
        fn(string $t) => $failedWhere($caseSouthSudan($t), 'as in 5.18.53'), 'South Sudan retried the receipt'],
    'the screen offers no retry for an exhausted row' => [[['tabs/engage/failed_queue.php',
        "<td><?php if(\$it['status']==='failed' || (\$fqUg && \$it['status']==='exhausted')):?>", "<td><?php if(\$it['status']==='failed'):?>"]],
        fn(string $t) => !$caseScreen($t, 'uganda')['retry_button_2'], 'no retry button on the exhausted row'],
];
foreach ($withMutants ? $mutants : [] as $name => [$edits, $caught, $why]) {
    $t = nr_tree($root);
    $okAnchors = true; $miss = '';
    foreach ($edits as [$rel, $o_, $n_]) {
        $src = (string)file_get_contents($t . '/' . $rel);
        if (substr_count($src, $o_) !== 1) { $okAnchors = false; $miss = $rel; break; }
        file_put_contents($t . '/' . $rel, str_replace($o_, $n_, $src));
    }
    if (!$okAnchors) { is_(false, "caught: {$name}", "the anchor was not found exactly once in {$miss}"); nr_drop($t); continue; }
    $ok = (bool)$caught($t);
    is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''));
    nr_drop($t);
}
if ($withMutants) {
    // Control on the control: the document send's clearing alone switched off (RETRIES without QUOTE_ONCE). The retry's
    // own clearing still reports the opted-out row as not sent, so each of the two guards holds by itself.
    $t = nr_tree($root);
    $src = (string)file_get_contents($t . '/lib/NotificationService.php');
    if (substr_count($src, NR_DOC_CLEAR) !== 1) {
        is_(false, 'control: the document send\'s clearing is found exactly once');
    } else {
        file_put_contents($t . '/lib/NotificationService.php', str_replace(NR_DOC_CLEAR, '', $src));
        $c = $caseStale($t, 'uganda');
        is_(($c['row1']['status'] ?? '') === 'failed',
            'control: without the document send\'s clearing, the retry\'s own still reports the opted-out row as not sent', nr_show($c));
    }
    nr_drop($t);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

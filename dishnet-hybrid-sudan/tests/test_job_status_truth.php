<?php
declare(strict_types=1);
/**
 * test_job_status_truth.php — 5.18.50 (docs/44 J7, release A): on Uganda the WhatsApp screens say what really happened.
 *
 * The plugin knows that it handed a message to WhatsApp; it does not know whether it reached the phone. Before this
 * release the WA Events screen called every "sent" line "Delivered", its navigation badge read timestamp keys the
 * webhook log has never written — so it never counted anything — and the Message Log called a message the opt-out
 * rule stopped a "fail". Proved here, on the real pages of a sandboxed plugin:
 *   1. WA Events: "Sent (handed to WhatsApp)", never "Delivered"; the job lines release B writes (5.18.52, docs/44 J4) —
 *      "WhatsApp sent to", "WhatsApp failed for", "WhatsApp skipped:" — are filed Sent, Failed and Skipped by the
 *      existing rule;
 *   2. the navigation badge counts today's events by received_at, the key the log writes, and does not count
 *      "WhatsApp skipped" as sent. "Today" is the install's own: public.php sets the tenant's zone before it hands an
 *      event to webhook.php, so received_at is Kampala's clock on Uganda — proved below with a real event. This test's
 *      own log writer used to write UTC, and so failed between 21:00 and 24:00 UTC (docs/44 §16.12); it now writes the
 *      tenant's zone, as public.php does;
 *   3. the Message Log says what "sent" means, and an opted-out message reads "suppressed", not "fail" — and is not
 *      counted as a failure;
 *   4. South Sudan: all three exactly as in 5.18.49;
 *   5. weakened copies of the code each fail this test.
 *
 *   php test_job_status_truth.php [--root=DIR] [--no-mutants]
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

/**
 * The webhook log as whLog() writes it: newest first, received_at — on the install's clock, because public.php applies
 * the tenant's zone before the crm_webhook route (section 2b proves it with a real event).
 */
$whLog = function (SjSandbox $s, array $lines, string $zone): void {
    $out = []; $id = count($lines);
    $at = (new DateTime('now', new DateTimeZone($zone)))->format('Y-m-d H:i:s');
    foreach ($lines as [$event, $message]) {
        $out[] = ['id' => $id--, 'event' => $event, 'message' => $message, 'data' => [], 'received_at' => $at, 'ip' => '127.0.0.1'];
    }
    file_put_contents($s->data . '/webhook_log.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
};
/** The Message Log's rows, as NotificationService::writeLog() stores them. */
$sends = function (SjSandbox $s): void {
    $s->q("CREATE TABLE IF NOT EXISTS notification_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT, event TEXT, phone TEXT, preview TEXT, success INTEGER NOT NULL DEFAULT 0, http_code INTEGER, error TEXT, sent_at TEXT NOT NULL DEFAULT (datetime('now')))");
    foreach ([['ops_job_accepted_self', 1, 200, null, 'Job Accepted'],
              ['ops_scheduling_task_done', 0, 500, 'HTTP 500 from the gateway', 'Great job completing Task 1'],
              ['ops_scheduling_job_complete_suppressed_optout', 0, null, 'the customer opted out', 'Job Completed']] as [$ev, $ok, $code, $err, $pre]) {
        $s->q('INSERT INTO notification_audit_log (sender, event, phone, preview, success, http_code, error) VALUES (?,?,?,?,?,?,?)',
              ['support', $ev, '256700000111', $pre, $ok, $code, $err]);
    }
};
/** The WA Events link in the navigation and its badge, if any. */
$badge = function (string $html): string {
    if (!preg_match('#<a href="\?page=dashboard&tab=engage_failed_queue&fqsub=crm_events"[^>]*>(.*?)</a>#s', $html, $m)) return '<no link>';
    return preg_match('#<span class="nav-badge"[^>]*>([^<]*)</span>#', $m[1], $b) ? trim($b[1]) : '';
};
$row = function (string $html, string $needle): string {   // the table row that mentions $needle, tags removed
    foreach (preg_split('#</tr>#', $html) as $r) if (strpos($r, $needle) !== false) return trim((string)preg_replace('/\s+/', ' ', strip_tags($r)));
    return '';
};

$SKIP_LINE = 'Job #911 (assigned) — WhatsApp skipped: no staff account is linked to uCRM user #1099';
$SENT_LINE = 'Job #912 (assigned) — WhatsApp sent to staff account #3';
$FAIL_LINE = 'Job #913 (reassigned: new engineer) — WhatsApp failed for staff account #3';
$run = function (array $cfg, string $tag, string $zone) use ($root, $whLog, $sends, $badge, $SKIP_LINE, $SENT_LINE, $FAIL_LINE): array {
    $s = SjSandbox::start($root, $cfg, $tag);
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
    $s->login('admin', 'admin@example.test', 'sj-password-1');
    $out = [];
    $whLog($s, [['job.add', $SKIP_LINE]], $zone);
    $out['badge_skipped_only'] = $badge($s->page('admin', 'page=dashboard&tab=dashboard'));
    $whLog($s, [['job.add', $SKIP_LINE], ['job.add', $SENT_LINE]], $zone);
    $out['badge_sent'] = $badge($s->page('admin', 'page=dashboard&tab=dashboard'));
    $whLog($s, [['job.add', $SKIP_LINE], ['job.add', $SENT_LINE], ['job.edit', $FAIL_LINE]], $zone);
    $out['badge_failed'] = $badge($s->page('admin', 'page=dashboard&tab=dashboard'));
    $out['events'] = $s->page('admin', 'page=dashboard&tab=engage_failed_queue&fqsub=crm_events');
    $sends($s);
    $out['log'] = $s->page('admin', 'page=dashboard&tab=whatsapp&subtab=log');
    // A real event through public.php, as uCRM delivers it: the clock its log line carries.
    @unlink($s->data . '/webhook_log.json');
    $s->fire('job.edit', 'job', 999999, 'sjtruth-clock');
    $first = array_reverse(json_decode($s->webhookLog(), true) ?: [])[0] ?? [];
    $out['clock'] = (string)($first['received_at'] ?? '');
    $s->stop();
    return $out;
};

// ═════════════════════════════════════════════════════════════════════════════
$ug = $run(['tenant_profile' => 'uganda'], 'sjtruth', 'Africa/Kampala');

echo "\n1. WA Events says \"sent\", because that is what the plugin knows\n";
$ev = $ug['events'];
is_(strpos($ev, '<div class="fq-stat-label">Sent (handed to WhatsApp)</div>') !== false, 'the counter is labelled "Sent (handed to WhatsApp)"', strlen($ev) . ' bytes');
is_(strpos($ev, '✅ Sent (handed to WhatsApp) (1)') !== false, 'so is its filter');
is_(strpos($ev, 'Delivered') === false, 'and "Delivered" appears nowhere on the screen');
is_(strpos($ev, 'whether the WhatsApp notification was sent (handed to WhatsApp; whether it reached the phone is not measured)') !== false,
    'the explanation says delivery is not measured');
$r = $row($ev, $SENT_LINE);
is_(strpos($r, '✅ Sent (handed to WhatsApp)') !== false, 'release B\'s "WhatsApp sent to staff account" line reads "Sent (handed to WhatsApp)"', $r);
$r = $row($ev, $FAIL_LINE);
is_(strpos($r, '❌ Failed') !== false, 'its "WhatsApp failed for" line reads "Failed"', $r);
$r = $row($ev, $SKIP_LINE);
is_(strpos($r, '⚠️ Skipped') !== false, 'and its "WhatsApp skipped:" line is filed Skipped by the existing rule', $r);

echo "\n2. The navigation badge counts today's events\n";
is_($ug['badge_sent'] === '1', 'one job message sent today: the badge shows 1', json_encode($ug['badge_sent']));
is_($ug['badge_failed'] === '1 fail', 'with a failed one too, it shows "1 fail"', json_encode($ug['badge_failed']));
is_($ug['badge_skipped_only'] === '', '"WhatsApp skipped" alone is not counted as sent: no badge', json_encode($ug['badge_skipped_only']));

echo "\n2b. \"Today\" is the install's own day\n";
$pub = (string)file_get_contents($root . '/public.php');
$zoneAt  = strpos($pub, "require_once __DIR__ . '/lib/timezone.php'; dn_tz_apply();");
$routeAt = strpos($pub, "if (\$page === 'crm_webhook') {");
is_($zoneAt !== false && $routeAt !== false && $zoneAt < $routeAt, 'public.php sets the tenant\'s zone before it hands an event to webhook.php',
    json_encode([$zoneAt, $routeAt]));
$clock = DateTime::createFromFormat('Y-m-d H:i:s', $ug['clock'], new DateTimeZone('UTC'));
$gap = function (string $zone) use ($clock): int {
    $now = DateTime::createFromFormat('Y-m-d H:i:s', (new DateTime('now', new DateTimeZone($zone)))->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    return $clock ? abs($now->getTimestamp() - $clock->getTimestamp()) : PHP_INT_MAX;
};
is_($clock !== false && $gap('Africa/Kampala') <= 120 && $gap('UTC') >= 3 * 3600 - 120,
    'a real event through public.php is logged on Kampala\'s clock, not UTC\'s — the badge\'s "today" is Kampala\'s at any hour', json_encode([$ug['clock'], $gap('Africa/Kampala'), $gap('UTC')]));

echo "\n3. The Message Log\n";
$lg = $ug['log'];
is_(strpos($lg, 'Sent = handed to WhatsApp. Whether it reached the phone is not measured.') !== false, 'it says what "sent" means', strlen($lg) . ' bytes');
is_(strpos($lg, '✓ 1 sent') !== false && strpos($lg, '✗ 1 failed') !== false && strpos($lg, '— 1 suppressed (opted out)') !== false,
    'its totals: 1 sent, 1 failed, 1 suppressed — the opt-out is not counted as a failure', preg_replace('/\s+/', ' ', substr($lg, strpos($lg, '📊 Last') ?: 0, 700)));
is_(strpos($row($lg, 'Job Completed'), '— suppressed (opted out)') !== false && strpos($row($lg, 'Job Completed'), '✗ fail') === false,
    'the opted-out message reads "suppressed (opted out)"', $row($lg, 'Job Completed'));
is_(strpos($row($lg, 'Great job completing'), '✗ fail') !== false, 'a real failure still reads "✗ fail"', $row($lg, 'Great job completing'));
is_(strpos($row($lg, 'Job Accepted'), '✓ sent') !== false, 'and a sent one "✓ sent"', $row($lg, 'Job Accepted'));

// ── 4. South Sudan ───────────────────────────────────────────────────────────
echo "\n4. South Sudan: all three as in 5.18.49\n";
$ss = $run([], 'sjtruth-ss', 'Africa/Juba');
is_(strpos($ss['events'], '<div class="fq-stat-label">Delivered</div>') !== false && strpos($ss['events'], 'handed to WhatsApp') === false,
    'WA Events still says "Delivered"');
is_($ss['badge_sent'] === '' && $ss['badge_failed'] === '', 'its badge still reads keys the log never writes, and shows nothing', json_encode([$ss['badge_sent'], $ss['badge_failed']]));
is_(strpos($ss['log'], '✗ 2 failed') !== false && strpos($ss['log'], 'suppressed (opted out)') === false && strpos($ss['log'], 'handed to WhatsApp') === false,
    'the Message Log counts the opt-out as a failure and adds no note, as before');
is_(strpos($row($ss['log'], 'Job Completed'), '✗ fail') !== false, 'and shows the opted-out message as "✗ fail"', $row($ss['log'], 'Job Completed'));

// ── 5. Weakened copies ───────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n5. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['includes/navigation.php', "if (substr((\$_navJ7 ? (\$_e['received_at'] ?? null) : null) ?? \$_e['timestamp'] ?? \$_e['created_at'] ?? '', 0, 10) !== \$today) continue;",
         "if (substr(\$_e['timestamp'] ?? \$_e['created_at'] ?? '', 0, 10) !== \$today) continue;", 'the badge reading keys the log never writes'],
        ['tabs/engage/failed_queue.php', "\$_fqSent = \$_fqJ7 ? 'Sent (handed to WhatsApp)' : 'Delivered';", "\$_fqSent = 'Delivered';", '"Delivered" claimed again'],
        ['tabs/engage/whatsapp.php', "    \$totalFail -= \$_wlSupp;\n", '', 'an opt-out counted as a failure'],
        ['tabs/engage/whatsapp.php', "<?php if (\$_wlJ7 && \$_wlSuppressed(\$nl)): ?>", "<?php if (false): ?>", 'an opted-out row shown as "fail"'],
        ['tabs/engage/whatsapp.php', "\$_wlJ7 = StaffJobsGate::applies(is_array(\$config ?? null) ? \$config : [], \$dataDir ?? null);", "\$_wlJ7 = true;",
         'the Message Log changed on South Sudan too'],
        ['public.php', "require_once __DIR__ . '/lib/timezone.php'; dn_tz_apply();", "require_once __DIR__ . '/lib/timezone.php';",
         'the tenant\'s zone no longer set before the webhook route (the log would be UTC)'],
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

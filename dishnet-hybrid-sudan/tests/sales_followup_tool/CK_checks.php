<?php
declare(strict_types=1);
/**
 * Section CK of tests/test_sales_followup_tool.php — Part F's checks after the world moved (root docs/73 §H.2.12.5,
 * §H.2.14 CK; ruling R18). Each case is a correct act — a chase claimed through the ledger, a list claimed and recorded,
 * a send through the real NotificationService or AlertService to the fake Evolution — followed by a change: a setting
 * narrowed, a row stored later, a colleague's reply, a number re-pointed. It reads PASS with the change printed as a
 * count. The controls plant rows directly and each reads FAIL. Every check runs through SalesFollowupChecks::check() on
 * the tool's read-only connection, which stays unchanged. TEST ONLY; every number and name is fictitious.
 *
 * B9-fix1 (root docs/73 §H.2.14 CK; ruling R18): the world moves through its real writers where D names them — the Inbox's
 * wa_send_reply served in the sandbox (CK06), NotificationService's sendVia failing and its retryOne() on Uganda (CK12),
 * tools/wa_prune_history.php itself (CK16), and the list job killed in its send, then QuoteDailyList::run() and its sweep
 * (CK20); the case D itself makes with a fake ledger stays planted (CK20b).
 */

/** The CK configuration: the ledger, tracking, the chase and the list on since before the fixed week. */
function ck_cfg(array $extra = []): array
{
    return $extra + [
        'wa_send_ledger' => 'on', 'wa_send_ledger_since' => '2026-10-28 00:00:00',
        'quote_tracking_enabled' => '1', 'quote_tracking_since' => '2026-11-01 00:00:00',
        'quote_chase_enabled' => '1', 'quote_chase_not_before' => '2026-11-01 00:00:00', 'sf_chase_first_on' => '2026-11-01 00:00:00',
        'quote_daily_list_enabled' => '1', 'quote_daily_list_since' => '2026-11-01 00:00:00',
        'evo_api_url' => 'http://127.0.0.1:9', 'evo_api_key' => 'fake-key-ck',
    ];
}

/** A book for the chase and list cases: the ledger, tracking and the chase on since before the fixed week. */
function ck_book(QfCtx $ctx, array $extra = [], string $tag = 'ck'): array
{
    $b = sft_book($ctx, ck_cfg($extra), $tag);
    sft_reminders_done($b, qf_t('2026-11-02 09:00:00'));
    return $b;
}

/** Run a check on the book's read-only connection with $cfg (the book's by default); $ro is asserted unchanged. */
function ck_check(QfCtx $ctx, array $b, string $id, int $now, ?array $cfg = null): array
{
    $ro = sft_ro($b);
    $r = SalesFollowupChecks::check($ro, $cfg ?? $b['cfg'], $b['data'], $id, $now);
    if (sft_changes($ro) !== 0) $ctx->t(false, 'CK the read-only connection was written by ' . $id);
    return $r;
}

/** A process that holds BEGIN IMMEDIATE on a book for $holdS seconds once $startFile exists (as test_wa_send_budget's). */
function ck_holder(string $db, string $startFile, string $lockedFile, float $holdS, string $releaseFile = '')
{
    $code = '$p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
          . '$p->exec("PRAGMA busy_timeout = 5000");'
          . 'for ($i = 0; $i < 600 && !is_file($argv[2]); $i++) usleep(50000);'
          . '$p->exec("BEGIN IMMEDIATE"); touch($argv[3]);'
          . 'if ($argv[5] !== "") touch($argv[5]);'
          . 'usleep((int)((float)$argv[4] * 1000000)); $p->exec("ROLLBACK");';
    return proc_open(['php', '-r', $code, $db, $startFile, $lockedFile, (string)$holdS, $releaseFile],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
}

/** A list row as QuoteDailyList::run writes it: six sections in SECTIONS order, each its count and listed keys. */
function ck_list(\PDO $p, string $day, string $owner, string $outcome, array $by, string $createdAt, ?string $detail = null): int
{
    $sec = [];
    foreach (['due', 'call', 'replied', 'stop', 'expiring', 'maybe_sent'] as $s) {
        $keys = $by[$s] ?? [];
        $sec[] = ['section' => $s, 'n' => count($keys), 'keys' => array_slice($keys, 0, 15)];
    }
    $n = array_sum(array_map(function ($s) { return $s['n']; }, $sec));
    $p->prepare('INSERT INTO quote_list_sends (list_date, owner_key, outcome, item_count, detail, sections, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
      ->execute([$day, $owner, $outcome, $outcome === 'missed' ? 0 : $n, $detail, $outcome === 'missed' ? null : json_encode($sec), $createdAt]);
    return (int)$p->lastInsertId();
}

/** A staff account in the store's retailers collection. */
function ck_staff(array $b, int $id, string $name, array $extra = []): void
{
    $b['pdo']->exec('CREATE TABLE IF NOT EXISTS [retailers] (id INTEGER PRIMARY KEY, data TEXT NOT NULL)');
    $b['pdo']->prepare('INSERT OR REPLACE INTO [retailers] (id, data) VALUES (?, ?)')
             ->execute([$id, json_encode(['id' => $id, 'name' => $name, 'is_active' => true, 'role' => 'sales'] + $extra)]);
}

require_once dirname(__DIR__) . '/quote_followup/J_list.php';   // 5.18.104 (root docs/73 §H.2.14 CK), B9-fix1: qf_jbook, qf_jrun, qf_jcron_bg

$SECTIONS['CK'] = ['title' => 'The checks after the world moved (§H.2.12.5; ruling R18)', 'run' => function (QfCtx $ctx) {
    $MON = function (string $hms): int { return qf_t(QF_MONDAY . ' ' . $hms); };
    $J = qf_t('2026-11-03 12:00:00');   // Tuesday: Monday judged

    // ── Q3-ON ──────────────────────────────────────────────────────────────────────────────────────────────────
    $ctx->run('CK01 positive control and the window (CK1): a chase claimed at 19:30, then `set window 8 18`', function () use ($ctx, $MON, $J) {
        $b = ck_book($ctx);
        $c = sft_chase($b, '256772501101', 'Q-CK01', $MON('19:30:00'));
        $ctx->eq($c['code'], 'ok', 'CK01 control: the chase is claimed at 19:30 under the default window');
        $r = ck_check($ctx, $b, 'Q3-ON', $J);
        $ctx->eq([sft_st($r), $r['counts']['claims'] ?? 0], ['PASS', 1], 'CK01 Q3-ON reads PASS over the claim', sft_detail($r));
        $r = ck_check($ctx, $b, 'Q3-ON', $J, ['wa_send_hour_from' => '8', 'wa_send_hour_to' => '18'] + $b['cfg']);
        $ctx->eq(sft_st($r), 'PASS', 'CK01 after set window 8 18: still PASS (the claim\'s own win_from/win_to)', sft_detail($r));
    });

    $ctx->run('CK02 the caps the claims recorded (CK2): a day of claims, then daily and hourly caps narrowed below them', function () use ($ctx, $MON, $J) {
        $b = ck_book($ctx);
        foreach (['01', '02', '03'] as $i => $n) sft_chase($b, '2567725012' . $n, 'Q-CK02-' . $n, $MON('10:0' . (2 * $i) . ':00'));
        $r = ck_check($ctx, $b, 'Q3-ON', $J, ['wa_send_daily_cap' => '2', 'wa_send_hourly_cap' => '2'] + $b['cfg']);
        $ctx->eq([sft_st($r), $r['counts']['claims'] ?? 0], ['PASS', 3], 'CK02 three claims, then caps of 2: PASS', sft_detail($r));
    });

    $ctx->run('CK03 the spacing each earlier row asked for (CK3): two claims 90 s apart, then `set gap 300 600`', function () use ($ctx, $MON, $J) {
        $b = ck_book($ctx);
        sft_chase($b, '256772501301', 'Q-CK03A', $MON('11:00:00'));
        sft_chase($b, '256772501302', 'Q-CK03B', $MON('11:01:30'));
        $r = ck_check($ctx, $b, 'Q3-ON', $J, ['wa_send_gap_min_s' => '300', 'wa_send_gap_max_s' => '600'] + $b['cfg']);
        $ctx->eq([sft_st($r), $r['counts']['claims'] ?? 0], ['PASS', 2], 'CK03 PASS by each row\'s own gap_s', sft_detail($r));
    });

    $ctx->run('CK04 CK05 a reply stored after the claim (device-dated before it), and one in the claim\'s own second', function () use ($ctx, $MON, $J) {
        foreach (['CK04' => ['2026-11-02 06:59:00', '2026-11-02 07:05:00'], 'CK05' => ['2026-11-02 07:00:00', '2026-11-02 07:00:00']] as $tag => [$sent, $stored]) {
            $b = ck_book($ctx);
            $c = sft_chase($b, '256772501401', 'Q-' . $tag, $MON('10:00:00'));
            sft_msg($b['pdo'], $c['conv'], ['direction' => 'in', 'role' => 'customer', 'body' => 'yes please (test)', 'sent_at' => $sent, 'created_at' => $stored]);
            $r = ck_check($ctx, $b, 'Q3-ON', $J);
            $ctx->eq([sft_st($r), ($r['counts']['reply_after_claim'] ?? 0) >= 1], ['PASS', true], $tag . ' PASS, the reply printed as a count', sft_detail($r));
        }
    });

    $ctx->run('CK06 a colleague\'s Inbox reply through the real wa_send_reply route after the chase: human_active, a row in the staff member\'s name, no Team row', function () use ($ctx, $MON) {
        // 5.18.104 (root docs/73 §H.2.14 CK; ruling R18), B9-fix1: the reply made by the Inbox's own route, served by the judged
        // tree in the sandbox (public.php under php -S, the repository's fake Evolution), as tests/wa_offers K07 makes it.
        require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';
        $sb = SjSandbox::start($ctx->root, ck_cfg(SFT_UG), 'ck06');
        try {
            $sb->staff('admin', ['name' => 'Sandbox Colleague', 'email' => 'ck06@example.test', 'role' => 'admin', 'is_admin' => true]);
            $store = $sb->store();
            $b = ['store' => $store, 'pdo' => $store->getPdo(), 'data' => $sb->data, 'cfg' => $sb->cfg + ['_data_dir' => $sb->data]];
            sft_reminders_done($b, qf_t('2026-11-02 09:00:00'));
            $c = sft_chase($b, '256772501501', 'Q-CK06', $MON('10:00:00'));
            $ctx->eq($c['code'], 'ok', 'CK06 control: the chase claimed on the sandbox\'s book');
            $rt = $sb->api('admin', 'POST', 'wa_send_reply', ['conversation_id' => $c['conv'], 'message' => 'I will call you (test)']);
            $p = $b['pdo'];
            $last = $p->query("SELECT agent_name, event_key FROM wa_messages WHERE conversation_id = {$c['conv']} AND direction = 'out' ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_NUM) ?: [];
            $state = (string)$p->query("SELECT state FROM wa_conversations WHERE id = {$c['conv']}")->fetchColumn();
            $team = (int)$p->query("SELECT COUNT(*) FROM wa_messages WHERE conversation_id = {$c['conv']} AND agent_name = 'Team'")->fetchColumn();
            $ctx->eq([$rt[0], $rt[2]['status'] ?? null, $last, $state, $team], [200, 'success', ['Sandbox Colleague', 'wa_staff_reply'], 'human_active', 0],
                     'CK06 control: the Inbox route\'s own writes — the colleague\'s row and human_active, no Team row');
            $r = ck_check($ctx, $b, 'Q3-ON', time() + 60);
            $ctx->eq([sft_st($r), $r['counts']['person_after_claim'] ?? 0], ['PASS', 1],
                     'CK06 PASS: a colleague\'s reply after the claim is not a person the sender could know of; printed as a count', sft_detail($r));
        } finally {
            $sb->stop();
        }
    });

    $ctx->run('CK07 an alert\'s echo stored as Team under the alert\'s own ledger id', function () use ($ctx, $MON, $J) {
        $b = ck_book($ctx);
        $c = sft_chase($b, '256772501601', 'Q-CK07', $MON('10:00:00'));
        $T = $MON('10:20:00');
        sft_ledger($b, $T)->record('Sales-UG', 'other', 'a:WA-ALERT-CK07', 'sent', ['wa_message_id' => 'WA-ALERT-CK07']);
        sft_msg($b['pdo'], $c['conv'], ['body' => 'an alert echo (test)', 'agent_name' => 'Team', 'wa_message_id' => 'WA-ALERT-CK07',
                                         'sent_at' => '2026-11-02 07:20:00', 'created_at' => '2026-11-02 07:20:00']);
        $r = ck_check($ctx, $b, 'Q3-ON', $J);
        $ctx->eq(sft_st($r), 'PASS', 'CK07 PASS: the R-05 trap is judged by the chase\'s own sent id', sft_detail($r));
    });

    $ctx->run('CK08 the line owner\'s first name changed after a persona chase', function () use ($ctx, $MON, $J) {
        require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';
        $b = ck_book($ctx, ['multi_number_channels_enabled' => '1', 'wa_followups_on_owned_numbers' => '1']);
        ck_staff($b, 81, 'Testa Owner');
        $reg = new ChannelRegistry($b['pdo'], $b['store']);
        $map = EvolutionApiService::configInstanceMap($b['cfg']);
        sj_verify_department_numbers($reg, $map);
        $reg->create(['channel_id' => 'sales-901', 'evo_instance' => 'Own-UG', 'display_name' => 'Own', 'role' => 'sales', 'owner_type' => 'staff',
                      'owner_staff_id' => 81, 'status' => 'active'], 'test', 'fixture', $map);
        $c = sft_chase($b, '256772501701', 'Q-CK08', $MON('10:00:00'), ['persona' => 'Testa', 'channel' => 'sales-901', 'handset' => 'Own-UG']);
        $ctx->eq([$c['code'], strpos($c['body'], "Testa's assistant") !== false], ['ok', true], 'CK08 control: a persona chase claimed and sent with the owner\'s first name');
        $r = ck_check($ctx, $b, 'Q3-ON', $J);
        $ctx->eq(sft_st($r), 'PASS', 'CK08 control: PASS before the rename', sft_detail($r));
        ck_staff($b, 81, 'Renamed Owner');
        $r = ck_check($ctx, $b, 'Q3-ON', $J);
        $ctx->eq(sft_st($r), 'PASS', 'CK08 the owner renamed: PASS (the body is judged by the name it carries)', sft_detail($r));
        $ctx->eq($r['counts']['persona_renamed'] ?? 0, 1, 'CK08 the name the body carries is not the owner\'s today: printed as a count');
    });

    $ctx->run('CK12 a reminder failed at 09:00, a chase at 10:00, the Failed Queue\'s retry through the real retryOne() on Uganda after the claim', function () use ($ctx) {
        // 5.18.104 (root docs/73 §H.2.14 CK; Q15-F01), B9-fix1: the reminder and its retry are the real NotificationService's
        // (sendVia to the fake Evolution, failing once; then retryOne() on Uganda), so the rows are the hook's own. The three
        // share the run's Kampala day, as the case's: the reminder and the retry at the real clock, the chase at 10:00 that day.
        $evo = qf_fake_evo();
        if ($evo === '') { $ctx->t(false, 'CK12 control: the fake Evolution is up'); return; }
        require_once $ctx->tree() . '/lib/NotificationService.php';
        $b = ck_book($ctx, ['evo_api_url' => $evo]);
        $day = WaSendBudget::localDay(time());
        sft_reminders_done($b, qf_t($day . ' 09:00:00'));
        $p = $b['pdo'];
        qf_http('GET', $evo . '/__test/fail_next?n=1');
        (new NotificationService($b['store'], $b['cfg']))->sendVia('support', '0772501801', 'Your payment is due tomorrow (test).', 'ops_pre_due_d1');
        $q = (int)$p->query("SELECT id FROM notification_queue WHERE event = 'ops_pre_due_d1' AND status = 'failed' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $first = $p->query("SELECT outcome FROM wa_auto_sends WHERE kind = 'reminder' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $ctx->eq([$q > 0, $first], [true, ['not_sent']], 'CK12 control: the reminder failed through the real sendVia, in the Failed Queue and the ledger not_sent');
        $c = sft_chase($b, '256772501801', 'Q-CK12', qf_t($day . ' 10:00:00'));
        $ctx->eq($c['code'], 'ok', 'CK12 control: the chase to that phone claimed at 10:00');
        $retry = (new NotificationService($b['store'], $b['cfg']))->retryOne($q, 'Test Admin');
        $after = $p->query("SELECT outcome FROM wa_auto_sends WHERE kind = 'reminder' AND id > {$c['claim']} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        $ctx->eq([!empty($retry['success']), $after], [true, ['sent']], 'CK12 control: the Failed Queue\'s retry through the real retryOne() on Uganda, recorded by the hook with a larger id', json_encode($retry));
        $r = ck_check($ctx, $b, 'Q3-ON', time() + 60);
        $ctx->eq([sft_st($r), $r['counts']['reminder_after_claim'] ?? 0], ['PASS', 1], 'CK12 PASS; reminder_after_claim counted (Q15-F01)', sft_detail($r));
    });

    $ctx->run('CK13 a quotation chased at step 1 and step 2: two followup_sends rows, one quotation (Q16-F01)', function () use ($ctx, $MON) {
        $b = ck_book($ctx);
        $c = sft_chase($b, '256772501901', 'Q-CK13', $MON('10:00:00'));
        sft_reminders_done($b, qf_t('2026-11-03 09:00:00'));
        sft_chase($b, '256772501901', 'Q-CK13', qf_t('2026-11-03 10:00:00'), ['step' => 2, 'existing' => [$c['q'], $c['fid']], 'wa' => 'WA-CK13-2']);
        $r = ck_check($ctx, $b, 'Q3-ON', qf_t('2026-11-04 12:00:00'));
        $ctx->eq([sft_st($r), $r['counts']['chase_sends'] ?? 0, $r['counts']['chased_quotations'] ?? 0], ['PASS', 2, 1], 'CK13 PASS, two sends and one quotation printed', sft_detail($r));
    });

    $ctx->run('CK Q3-ON controls: a chase after a reply stored before its second, a claim outside its own window, a send with no ledger row', function () use ($ctx, $MON, $J) {
        $b = ck_book($ctx);
        $c = sft_chase($b, '256772502001', 'Q-CKX1', $MON('10:00:00'));
        sft_msg($b['pdo'], $c['conv'], ['direction' => 'in', 'role' => 'customer', 'body' => 'ok (test)', 'sent_at' => '2026-11-02 06:58:00', 'created_at' => '2026-11-02 06:58:00']);
        $ctx->eq(sft_st(ck_check($ctx, $b, 'Q3-ON', $J)), 'FAIL chase_after_reply', 'CK control: a reply stored before the claim\'s second reads FAIL chase_after_reply');
        $b = ck_book($ctx);
        $c = sft_chase($b, '256772502002', 'Q-CKX2', $MON('10:00:00'));
        $b['pdo']->exec('DROP TRIGGER was_identity_fixed');
        $b['pdo']->prepare('UPDATE wa_auto_sends SET win_to = 9 WHERE id = ?')->execute([$c['claim']]);
        $ctx->eq(sft_st(ck_check($ctx, $b, 'Q3-ON', $J)), 'FAIL outside_window', 'CK control: a claim outside the window its row records reads FAIL outside_window');
        $b = ck_book($ctx);
        $c = sft_chase($b, '256772502003', 'Q-CKX3', $MON('10:00:00'));
        $b['pdo']->prepare("INSERT INTO followup_sends (followup_id, draft_id, attempt, sent_at, channel, phone, body, wa_message_id, decided_by)
                            VALUES (?, ?, 2, '2026-11-02 08:00:00', 'support', '256772502003', ?, 'WA-CKX3-2', 'auto:quote_chase')")
                   ->execute([$c['fid'], $c['draft'], $c['body']]);
        $ctx->eq(sft_st(ck_check($ctx, $b, 'Q3-ON', $J)), 'FAIL chase_ledger_mismatch', 'CK control: a followup_sends row with no quote row at its ref reads FAIL');
    });

    // ── Q1-ON ──────────────────────────────────────────────────────────────────────────────────────────────────
    $ctx->run('CK14 pending judged at the tracker\'s own moment: a row at 1 h 05 with no run since it turned 1 h', function () use ($ctx, $MON) {
        $now = $MON('12:00:00');
        foreach (['PASS' => 55, 'FAIL pending_past_1h' => 70] as $want => $ageAtRun) {
            $b = ck_book($ctx);
            $sent = $now - 65 * 60;
            sft_quote($b['pdo'], '256772502101', 'Q-CK14', ['sent_at' => UtcClock::stamp($sent), 'owner_source' => 'pending']);
            $b['pdo']->prepare("INSERT OR REPLACE INTO quote_followup_state (k, v) VALUES ('tracker_ran', ?)")->execute([UtcClock::stamp($sent + $ageAtRun * 60)]);
            $b['pdo']->prepare("INSERT OR REPLACE INTO quote_followup_state (k, v) VALUES ('tracker_cursor', ?)")->execute([(string)$b['pdo']->query('SELECT MAX(id) FROM wa_messages')->fetchColumn()]);
            $r = ck_check($ctx, $b, 'Q1-ON', $now);
            $ctx->eq(sft_st($r), $want, 'CK14 tracker_ran when the row was ' . $ageAtRun . ' min old: ' . $want, sft_detail($r));
            if ($want === 'PASS') $ctx->eq($r['counts']['pending_awaiting_run'] ?? 0, 1, 'CK14 counted pending_awaiting_run');
        }
    });

    $ctx->run('CK16 parity at the tracker\'s reach and by key: a deferred run, a resend, a pruned conversation', function () use ($ctx, $MON) {
        $now = $MON('09:45:00');
        $b = ck_book($ctx);
        $p = $b['pdo'];
        $a = sft_quote($p, '256772502201', 'Q-CK16A', ['sent_at' => '2026-11-01 08:00:00']);
        $g = sft_quote($p, '249912502202', 'Q-CK16G', ['sent_at' => '2026-11-01 08:10:00']);   // an inherited (249) conversation, which the prune removes
        sft_msg($p, $a['conv'], ['event_key' => 'ops_quote_text', 'body' => sft_ref_text('Q-CK16A'), 'sent_at' => '2026-11-01 10:00:00', 'created_at' => '2026-11-01 10:00:00']);
        $p->prepare("INSERT OR REPLACE INTO quote_followup_state (k, v) VALUES ('tracker_cursor', ?)")->execute([(string)$p->query('SELECT MAX(id) FROM wa_messages')->fetchColumn()]);
        $p->prepare("INSERT OR REPLACE INTO quote_followup_state (k, v) VALUES ('tracker_ran', ?)")->execute([UtcClock::stamp($MON('05:00:00'))]);
        sft_quote($p, '256772502203', 'Q-CK16D', ['sent_at' => UtcClock::stamp($MON('08:40:00')), 'track' => false]);
        // 5.18.104 (root docs/73 §H.2.14 CK), B9-fix1: removed by tools/wa_prune_history.php itself (--delete-sudan), its own statements
        $pr = (string)shell_exec('php ' . escapeshellarg($ctx->tree() . '/tools/wa_prune_history.php') . ' --delete-sudan --data ' . escapeshellarg($b['data']) . ' 2>&1');
        $ctx->eq([strpos($pr, 'deleted 1 conversation(s)') !== false, (int)$p->query("SELECT COUNT(*) FROM wa_conversations WHERE id = {$g['conv']}")->fetchColumn(),
                  (int)$p->query("SELECT COUNT(*) FROM wa_conversations WHERE id = {$a['conv']}")->fetchColumn()], [true, 0, 1],
                 'CK16 control: the prune tool removed the inherited conversation and its messages, and nothing else', $pr);
        $r = ck_check($ctx, $b, 'Q1-ON', $now);
        $ctx->eq([sft_st($r), $r['counts']['awaiting_run'] ?? 0, $r['counts']['evidence_gone'] ?? 0], ['PASS', 1, 1],
                 'CK16 PASS: the deferred row counted awaiting_run, the resend no mismatch, the pruned one evidence_gone', sft_detail($r));
        sft_quote($p, '256772502204', 'Q-CK16X', ['sent_at' => '2026-11-01 11:00:00', 'track' => false]);
        $p->prepare("INSERT OR REPLACE INTO quote_followup_state (k, v) VALUES ('tracker_cursor', ?)")->execute([(string)$p->query('SELECT MAX(id) FROM wa_messages')->fetchColumn()]);
        $ctx->eq(sft_st(ck_check($ctx, $b, 'Q1-ON', $now)), 'FAIL tracker_parity', 'CK control: a confirming row below the cursor with neither a tracker row nor a ref_conflict reads FAIL');
    });

    // ── Q2-ON ──────────────────────────────────────────────────────────────────────────────────────────────────
    $listBook = function () use ($ctx, $MON): array {
        $b = ck_book($ctx);
        $p = $b['pdo'];
        $p->exec("INSERT OR REPLACE INTO quote_followup_state (k, v) VALUES ('list_swept', '2026-11-02')");
        foreach ([81, 82, 83, 84, 85, 86, 87] as $id) ck_staff($b, $id, 'Seller' . $id . ' Test', ['phone' => '0700990' . $id]);
        $k1 = sft_quote($p, '256772502301', 'Q-CK2A', ['owner' => 81, 'owner_source' => 'kyc_sales_person', 'created_at' => '2026-11-01 08:00:00'])['key'];
        $k2 = sft_quote($p, '256772502302', 'Q-CK2B', ['owner' => 81, 'owner_source' => 'kyc_sales_person', 'created_at' => '2026-11-01 08:00:00'])['key'];
        $T = $MON('08:05:00');
        $L = sft_ledger($b, $T);
        $c = $L->claim('Sales-UG', 'staff_list', 'list:2026-11-02:staff:81');
        $L->settle((int)($c['claim'] ?? 0), 'sent', ['wa_message_id' => 'WA-LIST-81']);
        ck_list($p, '2026-11-02', 'staff:81', 'sent', ['due' => [$k1, $k2]], UtcClock::stamp($MON('08:05:00')));
        return [$b, $k1, $k2, $c];
    };

    $ctx->run('CK09 CK10 a list built at 08:05, then a STOP stored during its send, a quote answered and another created after', function () use ($ctx, $MON, $listBook) {
        [$b, $k1, , $c] = $listBook();
        $now = $MON('12:00:00');
        $ctx->eq(!empty($c['ok']), true, 'CK09 control: the list\'s claim was ok', json_encode($c));
        $ctx->eq(sft_st(ck_check($ctx, $b, 'Q2-ON', $now)), 'PASS', 'CK09 control: the list reads PASS');
        $p = $b['pdo'];
        $p->prepare("INSERT INTO contact_optouts (phone, reason, source, created_at) VALUES ('256772502301', 'customer_request', 'keyword', ?)")->execute([UtcClock::stamp($MON('08:05:03'))]);
        $p->prepare("UPDATE quote_tracker SET status = 'answered', status_at = ? WHERE quote_key = ?")->execute([UtcClock::stamp($MON('09:00:00')), $k1]);
        sft_quote($p, '256772502303', 'Q-CK2C', ['owner' => 81, 'owner_source' => 'kyc_sales_person', 'created_at' => '2026-11-02 06:00:00']);
        sft_quote($p, '256772502304', 'Q-CK2D', ['owner' => 81, 'owner_source' => 'kyc_sales_person', 'created_at' => '2026-11-02 06:10:00']);
        $r = ck_check($ctx, $b, 'Q2-ON', $now);
        $ctx->eq([sft_st($r), $r['counts']['optout_after_build'] ?? 0], ['PASS', 1], 'CK09/CK10 PASS: the STOP after the build counted, the page not compared', sft_detail($r));
        [$b] = $listBook();
        $b['pdo']->prepare("INSERT INTO contact_optouts (phone, reason, source, created_at) VALUES ('256772502301', 'customer_request', 'keyword', ?)")->execute([UtcClock::stamp($MON('08:04:00'))]);
        $ctx->eq(sft_st(ck_check($ctx, $b, 'Q2-ON', $now)), 'FAIL listed_after_optout', 'CK control: a key listed outside stop after an opt-out stored before the build reads FAIL');
    });

    $ctx->run('CK11 CK17 CK18 an owner given a phone after the sweep; an owner left out by list-staff; the sales number re-pointed', function () use ($ctx, $MON, $listBook) {
        [$b] = $listBook();
        $p = $b['pdo'];
        $now = $MON('12:00:00');
        sft_quote($p, '256772502401', 'Q-CK11', ['owner' => 82, 'owner_source' => 'kyc_sales_person', 'created_at' => '2026-11-01 08:00:00']);
        ck_list($p, '2026-11-02', 'staff:83', 'not_sent', ['due' => []], UtcClock::stamp($MON('08:05:00')), 'send_failed');
        $r = ck_check($ctx, $b, 'Q2-ON', $now, ['quote_daily_list_staff' => '81', 'evo_instance_sales' => 'Sales-UG-2'] + $b['cfg']);
        $ctx->eq([sft_st($r), $r['counts']['no_longer_due'] ?? 0, $r['counts']['handset_moved'] ?? 0], ['PASS', 1, 1],
                 'CK11/CK17/CK18 PASS: no_longer_due and handset_moved counted, the new owner not required', sft_detail($r));
        ck_list($p, '2026-11-02', 'staff:84', 'not_sent', ['due' => []], UtcClock::stamp($MON('08:05:00')), 'send_failed');
        ck_list($p, '2026-11-02', 'staff:84', 'missed', [], UtcClock::stamp($MON('11:00:00')), 'not_sent');
        $ctx->eq(sft_st(ck_check($ctx, $b, 'Q2-ON', $now)), 'FAIL missed', 'CK control: a not_sent try followed by the sweep\'s missed row reads FAIL missed');
    });

    $ctx->run('CK20 (the real job) a list try killed in its send, re-claimed in_flight then already_sent, written uncertain, read after the sweep; one killed at 10:55, which the sweep leaves out (Q18-F03)', function () use ($ctx) {
        // 5.18.104 (root docs/73 §H.2.14 CK; Q18-F03), B9-fix1: the list job itself — cron/quote_daily_list.php in a child
        // process, its send held at the fake Evolution and the process killed there, before its settle (as quote_followup J11
        // kills it) — then QuoteDailyList::run() for the next runs and the 11:00 sweep: the rows are the job's own.
        $evo = qf_fake_evo();
        if ($evo === '') { $ctx->t(false, 'CK20 control: the fake Evolution is up'); return; }
        foreach (['QuoteDailyList', 'AlertService'] as $l) require_once $ctx->tree() . '/lib/' . $l . '.php';
        foreach (['09:00' => false, '10:55' => true] as $hm => $late) {
            qf_fake_evo();
            $b = qf_jbook($ctx, $evo, ['quote_daily_list_since' => '2026-11-01 00:00:00']); $p = $b['pdo'];
            qf_staff($p, 85, 'Seller Eightyfive Test', true, ['phone' => '0700990085']);
            // the tracked quotation as the tracker leaves it, dated in the week (sft_quote; qf_jrow would date it by the real clock)
            sft_quote($p, '25677250250' . ($late ? '2' : '1'), 'Q-CK20' . ($late ? 'L' : 'D'), ['owner' => 85, 'owner_source' => 'quotes_log', 'created_at' => '2026-11-01 08:00:00']);
            $hold = $ctx->dir('hold');
            qf_http('GET', $evo . '/__test/hold?dir=' . rawurlencode($hold));
            [$proc, $wait] = qf_jcron_bg($ctx, $b, ['_qf_test_now' => qf_t(QF_MONDAY . ' ' . $hm . ':00')]);
            $held = sft_wait_file($hold . '/held', 10.0);
            proc_terminate($proc, 9);
            $wait();
            touch($hold . '/release');
            usleep(300000);
            $g = qf_jledger($p);
            $ctx->eq([$held, count($g), qf_jv($g, 0, 'outcome'), qf_jlists($p)], [true, 1, null, []], "CK20 control ({$hm}): the job killed in its send left its claim in flight and no list row");
            if (!$late) {
                $c1 = qf_jrun($ctx, $b, QF_MONDAY . ' 09:04:00');
                $c2 = qf_jrun($ctx, $b, QF_MONDAY . ' 09:16:00');
                $ctx->eq([$c1['deferred'], $c2['already_sent'], qf_jv(qf_jlists($p), 0, 'outcome'), qf_jv(qf_jledger($p), 0, 'outcome')], [1, 1, 'uncertain', 'uncertain'],
                         'CK20 control: re-claimed in_flight, then already_sent: the list written uncertain, its claim swept uncertain');
            }
            $sw = qf_jrun($ctx, $b, QF_MONDAY . ' 11:00:00');
            $ctx->eq([$sw['swept'], $sw['missed'], qf_state($p, 'list_swept')], [1, 0, QF_MONDAY], "CK20 control ({$hm}): the 11:00 sweep ran and wrote no missed row");
            $r = ck_check($ctx, $b, 'Q2-ON', qf_t(QF_MONDAY . ' 12:00:00'));
            $want = $late ? ['PASS', 0, 1] : ['PASS', 1, 0];
            $ctx->eq([sft_st($r), $r['counts']['lists_uncertain'] ?? -1, $r['counts']['settle_not_landed'] ?? -1], $want,
                     $late ? 'CK20 the try killed at 10:55, left out by the sweep: PASS, settle_not_landed counted'
                           : 'CK20 the dead try written uncertain: PASS, the list matched to its stale ledger row', sft_detail($r));
        }
    });

    $ctx->run('CK20b a sent list whose settle did not land (a fake ledger failing inside it), its row swept stale_orphan by a later claim (Q18-F03)', function () use ($ctx, $MON, $listBook) {
        [$b] = $listBook();
        $p = $b['pdo'];
        $now = $MON('12:00:00');
        $T = $MON('09:00:00'); $L = sft_ledger($b, $T);
        $L->claim('Sales-UG', 'staff_list', 'list:2026-11-02:staff:86');                       // sent, its settle lost
        ck_list($p, '2026-11-02', 'staff:86', 'sent', ['due' => []], UtcClock::stamp($MON('09:00:00')));
        $T = $MON('09:20:00');
        $x = $L->claim('Sales-UG', 'staff_list', 'list:2026-11-02:central');                  // a later claim sweeps it stale_orphan
        $L->settle((int)($x['claim'] ?? 0), 'sent');
        ck_list($p, '2026-11-02', 'central', 'sent', ['due' => []], UtcClock::stamp($MON('09:20:00')));
        $ctx->eq((string)$p->query("SELECT detail FROM wa_auto_sends WHERE ref = 'list:2026-11-02:staff:86'")->fetchColumn(), 'stale_orphan',
                 'CK20b control: the later claim swept the lost settle\'s row stale_orphan');
        $r = ck_check($ctx, $b, 'Q2-ON', $now);
        $ctx->eq([sft_st($r), $r['counts']['settle_not_landed'] ?? 0], ['PASS', 1], 'CK20b PASS: settle_not_landed counted', sft_detail($r));
        [$b] = $listBook();
        ck_list($b['pdo'], '2026-11-02', 'staff:82', 'sent', ['due' => []], UtcClock::stamp($MON('08:30:00')));
        $ctx->eq(sft_st(ck_check($ctx, $b, 'Q2-ON', $now)), 'FAIL list_ledger_missing', 'CK control: a sent list with no staff_list row reads FAIL');
        [$b] = $listBook();
        $T = $MON('08:40:00'); $L = sft_ledger($b, $T);
        $c = $L->claim('Sales-UG', 'staff_list', 'list:2026-11-02:staff:83');
        $L->settle((int)($c['claim'] ?? 0), 'failed');
        ck_list($b['pdo'], '2026-11-02', 'staff:83', 'uncertain', ['due' => []], UtcClock::stamp($MON('08:40:00')), 'maybe_sent');
        $ctx->eq(sft_st(ck_check($ctx, $b, 'Q2-ON', $now)), 'FAIL list_ledger_not_sent', 'CK control: an uncertain list whose rows are all not_sent reads FAIL');
    });

    // ── L-ON, through the real senders to the fake Evolution ────────────────────────────────────────────────────
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'CK control: the fake Evolution is up');
    $since = UtcClock::stamp(time() - 2 * 86400);
    $lbook = function (array $extra = []) use ($ctx, $evo, $since): array {
        return sft_book($ctx, $extra + ['wa_send_ledger' => 'on', 'wa_send_ledger_since' => $since, 'evo_api_url' => $evo, 'evo_api_key' => 'fake-key-ck'], 'ckl');
    };
    // The sends in Kampala, as on a Uganda install: the process zone (the Message Log's date()) and, B9-fix1, the install
    // zone PluginLog stamps its lines in (dn_tz() with no configuration reads the tree copy's empty data/, which names no
    // zone, so the lines were Juba's and, from 00:00 to 01:00 Kampala, on the previous day: CK21 read FAIL hook_silent then).
    $kampala = function (callable $fn) {
        $c =& dn_tz_cache(); $was = $c; $c['tz'] = QF_TZ;
        date_default_timezone_set(QF_TZ);
        try { $fn(); } finally { date_default_timezone_set('UTC'); $c = $was; }
    };

    $ctx->run('CK15 receipts WASender carried after on ack, then the channel given an instance and WASender\'s keys removed (Q17-F01)', function () use ($ctx, $lbook, $kampala) {
        sft_log_reset($ctx);
        $wasender = ['evo_instance_support' => '', 'evo_instance_account' => '', 'wa_plugin_url' => 'http://127.0.0.1:9', 'wa_app_key' => 'fake-app', 'wa_auth_key' => 'fake-auth'];
        $b = $lbook($wasender);
        $kampala(function () use ($b) { (new NotificationService($b['store'], $b['cfg']))->sendRaw('0772502501', 'A receipt WASender carries (test)', 'ops_receipt_ck'); });
        $cfg = $b['cfg']; foreach (['wa_plugin_url', 'wa_app_key', 'wa_auth_key'] as $k) unset($cfg[$k]);
        $cfg['evo_instance_support'] = 'DishNet-UG'; $cfg['evo_instance_account'] = 'DishNet-UG';
        $r = ck_check($ctx, $b, 'L-ON', time(), $cfg);
        $ctx->eq([sft_st($r), $r['counts']['wasender'] ?? 0, $r['counts']['hook_write_lost'] ?? -1], ['PASS', 1, 0], 'CK15 PASS, the day\'s WASender count printed, hook_write_lost 0', sft_detail($r));
    });

    $ctx->run('CK19 an escalation alert while evo_instance_sales was blank, the instance set since (Q18-F01)', function () use ($ctx, $lbook, $kampala) {
        sft_log_reset($ctx);
        $b = $lbook(['evo_instance_sales' => '', 'alert_whatsapp' => '256772502601']);
        $kampala(function () use ($b) { (new AlertService($b['store'], $b['cfg']))->notify('escalate:conv:91', 'A hand-over (test)', 0); });
        $r = ck_check($ctx, $b, 'L-ON', time(), ['evo_instance_sales' => 'Sales-UG'] + $b['cfg']);
        $ctx->eq([sft_st($r), $r['counts']['noinstance'] ?? 0, $r['counts']['hook_write_lost'] ?? -1], ['PASS', 1, 0], 'CK19 PASS, the no-instance count printed, hook_write_lost 0', sft_detail($r));
    });

    $ctx->run('CK21 CK22 a win-back whose hook write was lost behind a held lock; then data/plugin.log cleared (Q19-F02)', function () use ($ctx, $lbook, $kampala, $evo) {
        sft_log_reset($ctx);
        $b = $lbook();
        $d = $ctx->dir('hold');
        qf_http('GET', $evo . '/__test/hold?dir=' . rawurlencode($d));
        $h = ck_holder($b['data'] . '/plugin.sqlite3', $d . '/held', $d . '/locked', 12.5, $d . '/release');
        $kampala(function () use ($b) { (new NotificationService($b['store'], $b['cfg']))->sendRaw('0772502701', 'A win-back (test)', 'ops_win_back'); });
        proc_close($h);
        $ctx->eq([(int)$b['pdo']->query("SELECT COUNT(*) FROM wa_auto_sends WHERE kind = 'winback'")->fetchColumn(), $ctx->logLines('hook_write_lost winback')], [0, 1],
                 'CK21 control: no winback ledger row, one hook_write_lost winback line');
        $r = ck_check($ctx, $b, 'L-ON', time());
        $ctx->eq([sft_st($r), $r['counts']['hook_write_lost'] ?? -1], ['PASS', 1], 'CK21 PASS with hook_write_lost 1 beside the line', sft_detail($r));
        $tomorrow = (new \DateTime('@' . (time() + 86400)))->setTimezone(new \DateTimeZone(QF_TZ))->format('Y-m-d H:i:s');
        file_put_contents($ctx->tree() . '/data/plugin.log', '[' . $tomorrow . '] [test] the log rotated (fixture)' . "\n");
        $r = ck_check($ctx, $b, 'L-ON', time());
        $ctx->eq([sft_st($r), $r['counts']['hook_write_lost'] ?? -1, in_array('plugin_log_not_read', $r['notes'], true)], ['PASS', 1, true],
                 'CK22 the log cleared, its earliest line after the day: PASS, hook_write_lost 1, plugin_log_not_read', sft_detail($r));
    });

    $ctx->run('CK23 the controls: receipts and reminders logged with no trace of a hook; an alert logged http 0 with none', function () use ($ctx, $lbook) {
        sft_log_reset($ctx);
        $b = $lbook();
        $at = (new \DateTime('now', new \DateTimeZone(QF_TZ)))->format('Y-m-d H:i:s');
        (new NotificationService($b['store'], $b['cfg']))->logSend('support', 'ops_bootstrap_ck', '256772502800', 'x (test)', true, 200);   // the table, as the service makes it
        $b['pdo']->exec("DELETE FROM notification_audit_log");
        foreach ([['ops_receipt_ck', 200], ['ops_receipt_ck', 200], ['ops_pre_due_d1', 200]] as [$ev, $http]) {
            $b['pdo']->prepare("INSERT INTO notification_audit_log (sender, event, phone, preview, success, http_code, sent_at) VALUES ('support', ?, '256772502801', 'x', 1, ?, ?)")
                     ->execute([$ev, $http, $at]);
        }
        $ctx->eq(sft_st(ck_check($ctx, $b, 'L-ON', time())), 'FAIL hook_silent', 'CK23 a hook not wired at all: FAIL hook_silent');
        $b = $lbook();
        (new NotificationService($b['store'], $b['cfg']))->logSend('support', 'ops_bootstrap_ck', '256772502800', 'x (test)', true, 200);
        $b['pdo']->exec("DELETE FROM notification_audit_log");
        $b['pdo']->prepare("INSERT INTO notification_audit_log (sender, event, phone, preview, success, http_code, sent_at) VALUES ('sales', 'ops_alert_escalate', '256772502802', 'x', 0, 0, ?)")
                 ->execute([$at]);
        $ctx->eq(sft_st(ck_check($ctx, $b, 'L-ON', time())), 'FAIL hook_silent', 'CK control: an escalation alert logged http 0 with no row, count or line reads FAIL hook_silent');
    });
}];

<?php
declare(strict_types=1);
/**
 * Section G of tests/test_quote_followup.php — the sender, cron/followup_send.php's two passes (5.18.104, root docs/73
 * §H.2.10.6, §H.2.6.4, §H.2.5.8, §H.2.14 G; rulings R2, R18, R19; B11-02, Q15-F04). TEST ONLY.
 *
 * The real cron, run from the copy in a child process (tests/quote_followup/run_cron.php) against the repository's fake
 * Evolution, at a fixed Kampala moment ($_qf_test_now) so the window holds whatever the hour of the run: the live echo
 * order (the echo delivered DURING the settle, by a trigger on the ledger row); 'DishNet Plugin', which humanRepliedSince
 * and HumanTakeover never read as a colleague; one claim per handset; F3's tries and tries_exhausted; already_sent; the job
 * budget; an old approved enquiry draft never sent while followup_enabled is off and the chase on; preSendCheck's stops
 * (a mark, an escalation word in the customer's own row and never in ours, a stale or an edited draft); the stops read
 * again after the claim (a reply stored inside the claim); the fresh read before each claim (a window narrowed while the
 * pass is held inside its loop, an enquiry draft claimed, recorded or held as the files say then, a run begun with the
 * ledger off); and every 5.18.104 switch off: an enquiry sent as 5.18.103 sends it. B2b-fix1 (B11-02, items 6 and 12): the
 * quote pass's lock observed in the run's own process (tests/quote_followup/run_cron.php's probe; a child's exit frees any
 * flock, so a probe from the test process proves nothing), released when a read inside its listing throws under master's
 * try/catch, and free during the enquiry pass; and a refusal at the send of an enquiry the ledger records writes no row.
 * Every person and number is fictitious.
 */

/** The fixed moment of the sender's cases: Monday 2 November 2026, 10:00 Kampala. */
function qf_gnow(string $at = '2026-11-02 10:00:00'): int { return qf_t($at); }

/**
 * Run a plugin cron from the copy on book $b in a child process: its configuration written to <data>/kyc_config.json, the
 * copy's ucrm.json aimed at the book for the run (put back after), the runner's variables. [rc, output]; with $bg, a
 * function that waits for the run and returns [rc, output].
 */
function qf_cron(QfCtx $ctx, array $b, string $script, array $vars = [], ?array $cfg = null, bool $bg = false)
{
    $tree = $ctx->tree();
    $cfg = $cfg ?? $b['cfg'];
    unset($cfg['_data_dir']);
    file_put_contents($b['data'] . '/kyc_config.json', json_encode($cfg));
    $uj = $tree . '/ucrm.json';
    $was = is_file($uj) ? (string)file_get_contents($uj) : null;
    file_put_contents($uj, json_encode(['pluginDataDir' => $b['data']]));
    $restore = function () use ($uj, $was): void { if ($was === null) @unlink($uj); else file_put_contents($uj, $was); };
    $log = $b['data'] . '/cron-' . bin2hex(random_bytes(3)) . '.log';
    $cmd = sprintf('cd %s && DN_DATA_DIR=%s DN_VAULT_FILE=%s exec php %s %s %s > %s 2>&1', escapeshellarg($tree . '/cron'), escapeshellarg($b['data']),
                   escapeshellarg($b['data'] . '/vault.json'), escapeshellarg(__DIR__ . '/run_cron.php'), escapeshellarg($tree . '/' . $script),
                   escapeshellarg(json_encode($vars)), escapeshellarg($log));
    $env = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r']], $pipes, null, $env);
    $wait = function () use ($proc, $log, $restore): array {
        $rc = proc_close($proc);
        $restore();
        return [$rc, (string)@file_get_contents($log)];
    };
    return $bg ? $wait : $wait();
}

/** Write <data>/kyc_config.json while a run is held (what `set` or `on`/`off` does to the files). */
function qf_set_files(array $b, array $cfg): void
{
    unset($cfg['_data_dir']);
    file_put_contents($b['data'] . '/kyc_config.json', json_encode($cfg));
}

/** A sender book: the chase on, the fake Evolution, today's reminders done at 09:30. */
function qf_gbook(QfCtx $ctx, string $evo, array $extra = [], string $tag = 'g'): array
{
    $b = $ctx->book(qf_ccfg($extra + ['evo_api_url' => $evo, 'evo_api_key' => 'fake-key-qf']), $tag);
    $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => qf_t('2026-11-02 09:30:00'), 'last_run_at' => 'x', 'duration_ms' => 900]]);
    return $b;
}

/**
 * A chase as open() and draftDue() leave it: a tracker row, its quote follow-up and an approved draft of $step decided
 * at $decided (UTC). [conv, fid, draft, key, body].
 */
function qf_chase(array $b, string $phone, string $chan, string $ref, array $o = []): array
{
    $p = $b['pdo'];
    $o += ['sent_at' => '2026-11-01 07:00:00', 'valid_until' => '2026-11-20', 'step' => 1, 'variant' => 'A', 'decided' => '2026-11-02 06:00:00', 'spoke' => true,
           'persona' => null];
    $conv = $o['conv'] ?? qf_conv($p, $phone, $chan);
    if ($o['spoke']) qf_in($p, $conv, 'hello (test)', '2026-10-31 09:00:00');
    $key = qf_track_row($p, $ref, $phone, $conv, ['sent_at' => $o['sent_at'], 'valid_until' => $o['valid_until'], 'spoke' => $o['spoke'] ? $conv : 0,
                                                 'channel' => $chan === 'sales' ? 'support' : $chan, 'spoke_channel' => $chan]);
    $svc = new FollowUpService($p);
    $fid = $svc->openQuote(['id' => $conv, 'channel' => $chan, 'phone' => $phone],
                           ['quote_key' => $key, 'ref' => $ref, 'valid_until' => $o['valid_until'], 'sent_at' => $o['sent_at'], 'due_at' => '2026-11-02 07:00:00']);
    $p->prepare('UPDATE quote_tracker SET followup_id = ? WHERE quote_key = ?')->execute([$fid, $key]);
    if ($o['step'] > 1) $p->prepare('UPDATE followups SET attempts = ? WHERE id = ?')->execute([$o['step'] - 1, $fid]);
    $body = (string)QuoteChaseText::render($o['step'], $o['variant'], $ref, $o['valid_until'], $o['persona']);
    $d = $svc->draftTemplate($fid, $body, QuoteChaseText::templateId($o['step'], $o['variant'], $o['persona'] !== null));
    $svc->approve((int)$d['id'], 'auto:quote_chase');
    $p->prepare('UPDATE followup_drafts SET decided_at = ? WHERE id = ?')->execute([$o['decided'], (int)$d['id']]);
    return ['conv' => $conv, 'fid' => $fid, 'draft' => (int)$d['id'], 'key' => $key, 'body' => $body];
}

/** An approved enquiry draft, as followup_run leaves one. [conv, fid, draft, body]. */
function qf_enquiry(array $b, string $phone, string $chan, string $decided = '2026-11-02 06:00:00'): array
{
    $p = $b['pdo']; $svc = new FollowUpService($p);
    $conv = qf_conv($p, $phone, $chan);
    // the customer's last message before any decision a case plants, so the live reply check never closes it
    $p->prepare("UPDATE wa_conversations SET last_customer_at = '2026-10-19 09:00:00' WHERE id = ?")->execute([$conv]);
    $fu = $svc->open(['id' => $conv, 'channel' => $chan, 'phone' => $phone, 'last_customer_at' => '2026-10-19 09:00:00']);
    $body = 'Hello, did you find the kit you asked about? (test ' . substr($phone, -4) . ')';
    $d = $svc->draft((int)$fu['id'], ['verdict' => 'SEND', 'message' => $body, 'reason' => 'test']);
    $svc->approve((int)$d['id'], 'staff:Tester');
    $p->prepare('UPDATE followup_drafts SET decided_at = ? WHERE id = ?')->execute([$decided, (int)$d['id']]);
    return ['conv' => $conv, 'fid' => (int)$fu['id'], 'draft' => (int)$d['id'], 'body' => $body];
}

function qf_texts(string $evo): array { return (array)(qf_http('GET', $evo . '/__test/state')[1]['text_calls'] ?? []); }

function qf_rows(\PDO $p, string $sql, array $a = []): array { $st = $p->prepare($sql); $st->execute($a); return $st->fetchAll(\PDO::FETCH_ASSOC); }

$QF_SECTIONS['G'] = ['title' => 'The sender: cron/followup_send.php\'s two passes (§H.2.10.6)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'QuoteChase', 'ConversationService', 'HumanTakeover'] as $l) require_once $tree . '/lib/' . $l . '.php';
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'G00 control: the fake Evolution answers');
    if ($evo === '') return;
    $T = qf_gnow();
    $send = function (array $b, array $vars = [], ?array $cfg = null) use ($ctx, $T): array {
        return qf_cron($ctx, $b, 'cron/followup_send.php', $vars + ['_qf_test_now' => $T], $cfg);
    };

    $ctx->run('G01 a due chase is sent: claimed, sent, its echo claimed as quote.chase, settled with the id, stored as DishNet Plugin', function () use ($ctx, $evo, $send) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $c = qf_chase($b, '256772502201', 'support', 'Q-9201');
        [$rc, $out] = $send($b);
        $tx = qf_texts($evo);
        $ctx->eq([$rc, count($tx), $tx[0]['instance'] ?? null, $tx[0]['text'] ?? null], [0, 1, 'DishNet-UG', $c['body']], 'G01 one text, from support\'s instance, the template\'s bytes', $out);
        $l = qf_rows($p, 'SELECT kind, ref, mode, outcome, wa_message_id, claimed_at, phone, win_from, win_to, try FROM wa_auto_sends');
        $ctx->eq($l, [['kind' => 'quote', 'ref' => 'fu:' . $c['fid'] . ':1', 'mode' => 'claim', 'outcome' => 'sent', 'wa_message_id' => 'FAKE-EVO-MSG-1',
                       'claimed_at' => '2026-11-02 07:00:00', 'phone' => '256772502201', 'win_from' => 8, 'win_to' => 20, 'try' => 1]],
                 'G01 the ledger: a quote claim, ref fu:<id>:1, at the run\'s moment, settled sent with the message id');
        $ctx->eq(qf_rows($p, "SELECT event FROM evo_webhook_seen WHERE message_id = 'FAKE-EVO-MSG-1'"), [['event' => 'quote.chase']], 'G01 the echo claimed as quote.chase');
        $m = qf_rows($p, "SELECT role, agent_name, metadata FROM wa_messages WHERE wa_message_id = 'FAKE-EVO-MSG-1'");
        $meta = json_decode((string)($m[0]['metadata'] ?? ''), true);
        $ctx->eq([$m[0]['role'] ?? null, $m[0]['agent_name'] ?? null, $meta['source'] ?? null, $meta['step'] ?? null, $meta['template'] ?? null],
                 ['agent', 'DishNet Plugin', 'quote_chase', 1, 'template:quote_chase_v1:1A'], 'G01 stored as role agent, DishNet Plugin, with its metadata (R-10)');
        $f = (new FollowUpService($p))->get($c['fid']);
        $ctx->eq([(int)$f['attempts'], $f['last_sent_at'], $f['due_at'], $f['closed_at'], (new FollowUpService($p))->getDraft($c['draft'])['status'],
                  count(qf_rows($p, 'SELECT id FROM followup_sends'))],
                 [1, '2026-11-02 07:00:00', '2026-11-04 07:00:00', null, 'sent', 1], 'G01 step 1 spent; step 2 armed from the policy (Wednesday 10:00); the draft sent; one followup_sends row');
        $cs = new ConversationService($b['data'], $p);
        $ctx->eq([$cs->humanRepliedSince($c['conv'], '2026-11-02 06:00:00'), HumanTakeover::since($p, '256772502201', [$c['conv']], '2026-11-01 07:00:00')],
                 [false, null], 'G01 the stored chase is never a colleague: humanRepliedSince (the AI keeps answering) and HumanTakeover both read none');
    });

    $ctx->run('G02 the live echo order: the echo arriving during the settle finds our claim first (R-05; QM40)', function () use ($ctx, $evo, $send) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        qf_chase($b, '256772502211', 'support', 'Q-9211');
        $p->exec("CREATE TRIGGER qf_echo_during_settle AFTER UPDATE OF outcome ON wa_auto_sends WHEN NEW.wa_message_id IS NOT NULL
                  BEGIN INSERT OR IGNORE INTO evo_webhook_seen (message_id, instance, event, received_at)
                        VALUES (NEW.wa_message_id, 'DishNet-UG', 'messages.upsert', datetime('now')); END");
        [$rc, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), qf_rows($p, 'SELECT event FROM evo_webhook_seen')], [1, [['event' => 'quote.chase']]],
                 'G02 the echo delivered during the settle meets the sender\'s claim: dropped as ours, never a colleague', $out);
    });

    $ctx->run('G03 one claim per handset: support and account share one; sales has its own', function () use ($ctx, $evo, $send) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $a = qf_chase($b, '256772502221', 'support', 'Q-9221');
        $x = qf_chase($b, '256772502222', 'account', 'Q-9222');
        $s = qf_chase($b, '256772502223', 'sales', 'Q-9223');
        [$rc, $out] = $send($b);
        $tx = qf_texts($evo);
        $ctx->eq([count($tx), array_column($tx, 'instance'), array_column(qf_rows($p, 'SELECT ref FROM wa_auto_sends ORDER BY id'), 'ref'),
                  (new FollowUpService($p))->getDraft($x['draft'])['status']],
                 [2, ['DishNet-UG', 'Sales-UG'], ['fu:' . $a['fid'] . ':1', 'fu:' . $s['fid'] . ':1'], 'approved'],
                 'G03 the oldest per handset: support\'s and sales\'s sent; account\'s waits, approved and unclaimed', $out);
        $ctx->t(strpos($out, 'ledger_') === false, 'G03 the selection held it, never a ledger defer (no ledger_<code> counted)', $out);
    });

    $ctx->run('G04 F3\'s tries: a certain failure is not_sent and tried again, three tries, then tries_exhausted closes send_failed (QM26)', function () use ($ctx, $evo, $send) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $c = qf_chase($b, '256772502231', 'support', 'Q-9231');
        $outs = [];
        foreach ([0, 1, 2] as $i) {
            qf_http('GET', $evo . '/__test/fail_next?n=1');
            [, $outs[]] = $send($b, ['_qf_test_now' => qf_gnow('2026-11-02 10:0' . ($i * 3) . ':00')]);
        }
        $rows = qf_rows($p, 'SELECT try, outcome FROM wa_auto_sends ORDER BY id');
        $ctx->eq([$rows, (new FollowUpService($p))->getDraft($c['draft'])['status']],
                 [[['try' => 1, 'outcome' => 'not_sent'], ['try' => 2, 'outcome' => 'not_sent'], ['try' => 3, 'outcome' => 'not_sent']], 'approved'],
                 'G04 three tries, each not_sent (never counted), the draft still approved', implode("\n", $outs));
        [, $o4] = $send($b, ['_qf_test_now' => qf_gnow('2026-11-02 10:10:00')]);
        $f = (new FollowUpService($p))->get($c['fid']);
        $ctx->eq([count(qf_texts($evo)), $f['close_reason'], count($rows = qf_rows($p, 'SELECT id FROM wa_auto_sends'))], [0, 'send_failed', 3],
                 'G04 the fourth: tries_exhausted, closed send_failed, nothing sent, no fourth row', $o4);
    });

    $ctx->run('G05 already_sent: the step is counted as taken, the next armed, nothing sent (§H.2.5.8)', function () use ($ctx, $evo, $send) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $c = qf_chase($b, '256772502241', 'support', 'Q-9241');
        $p->prepare("INSERT INTO wa_auto_sends (handset, kind, ref, mode, proactive, phone, local_day, gap_s, claimed_at, outcome, settled_at)
                     VALUES ('i:dishnet-ug', 'quote', ?, 'record', 1, '256772502241', '2026-11-02', 60, '2026-11-02 05:00:00', 'uncertain', '2026-11-02 05:00:00')")
          ->execute(['fu:' . $c['fid'] . ':1']);
        [, $out] = $send($b);
        $f = (new FollowUpService($p))->get($c['fid']);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->getDraft($c['draft'])['status'], (int)$f['attempts'], $f['due_at'], $f['closed_at']],
                 [0, 'uncertain', 1, '2026-11-04 07:00:00', null], 'G05 never re-sent: the draft uncertain, step 1 counted, step 2 armed', $out);
    });

    $ctx->run('G06 the job budget: with less than one send left of master\'s cycle, nothing is claimed or sent', function () use ($ctx, $evo, $send, $T) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $c = qf_chase($b, '256772502251', 'support', 'Q-9251');
        [, $out] = $send($b, ['_m_deadline' => $T + 10]);
        $ctx->eq([count(qf_texts($evo)), count(qf_rows($p, 'SELECT id FROM wa_auto_sends')), (new FollowUpService($p))->getDraft($c['draft'])['status']],
                 [0, 0, 'approved'], 'G06 10 s left (< 20 s): held, unclaimed', $out);
        [, $out] = $send($b, ['_m_deadline' => $T + 60]);
        $ctx->eq(count(qf_texts($evo)), 1, 'G06 control: 60 s left, it goes', $out);
    });

    $ctx->run('G07 followup_enabled off, the chase on: an old approved enquiry draft is not sent while the quote drafts are (QM58)', function () use ($ctx, $evo, $send) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772502261', 'sales', '2026-10-20 08:00:00');
        $c = qf_chase($b, '256772502262', 'support', 'Q-9262');
        [, $out] = $send($b);
        $tx = qf_texts($evo);
        $ctx->eq([count($tx), $tx[0]['text'] ?? null, (new FollowUpService($p))->getDraft($e['draft'])['status'], (new FollowUpService($p))->get($e['fid'])['closed_at']],
                 [1, $c['body'], 'approved', null], 'G07 the chase sent; the enquiry draft left approved and its follow-up open: never listed, never sent', $out);
        // followup_enabled turned on while the pass is held: the enquiry pass is decided at the run's start, as 5.18.103's
        qf_fake_evo();
        $b2 = qf_gbook($ctx, $evo); $p2 = $b2['pdo'];
        $e2 = qf_enquiry($b2, '256772502263', 'sales', '2026-10-20 08:00:00');
        qf_chase($b2, '256772502264', 'support', 'Q-9264');
        $hold = $ctx->dir('hold');
        qf_http('GET', $evo . '/__test/hold?dir=' . urlencode($hold));
        $wait = qf_cron($ctx, $b2, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()], null, true);
        for ($i = 0; $i < 300 && !is_file($hold . '/held'); $i++) usleep(100000);
        qf_set_files($b2, $b2['cfg'] + ['followup_enabled' => '1']);
        touch($hold . '/release');
        [, $out2] = $wait();
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p2))->getDraft($e2['draft'])['status'], (new FollowUpService($p2))->get($e2['fid'])['closed_at']], [1, 'approved', null],
                 'G07 followup_enabled turned on during the pass: the run\'s enquiry pass was off at its start, so the planted draft still waits', $out2);
    });

    $ctx->run('G08 the stops read again after the claim: a reply stored inside it settles not_sent, nothing sent, closed replied (ruling R18; QM76)', function () use ($ctx, $evo, $send) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $c = qf_chase($b, '256772502271', 'support', 'Q-9271');
        $p->exec("CREATE TRIGGER qf_reply_in_claim AFTER INSERT ON wa_auto_sends WHEN NEW.kind = 'quote'
                  BEGIN INSERT INTO wa_messages (conversation_id, direction, role, body, sent_at, created_at)
                        VALUES (" . (int)$c['conv'] . ", 'in', 'customer', 'yes please (test)', '2026-11-02 06:59:00', datetime('now')); END");
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), qf_rows($p, 'SELECT outcome, detail FROM wa_auto_sends'), (new FollowUpService($p))->get($c['fid'])['close_reason'],
                  qf_trow($p, $c['key'])['status']],
                 [0, [['outcome' => 'not_sent', 'detail' => 'stopped_after_claim']], 'replied', 'answered'],
                 'G08 the claim settled not_sent (stopped_after_claim), nothing sent, the follow-up replied, the tracker answered', $out);
    });

    $ctx->run('G09 preSendCheck: an inbound mark since the quotation stops it before any claim (QM52)', function () use ($ctx, $evo, $send) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $c = qf_chase($b, '256772502281', 'support', 'Q-9281');
        qf_mark($p, 'in', '256772502281', 'support', 'WA-QF-G09', '2026-11-01 15:00:00');
        $ok = qf_chase($b, '256772502282', 'sales', 'Q-9282');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), qf_trow($p, $c['key'])['status'], qf_trow($p, $c['key'])['status_reason'],
                  array_column(qf_rows($p, 'SELECT ref FROM wa_auto_sends'), 'ref')],
                 [1, 'answered', 'unread_inbound', ['fu:' . $ok['fid'] . ':1']], 'G09 answered (unread_inbound), never claimed; control: the other handset\'s chase sent', $out);
    });

    $ctx->run('G10 preSendCheck: an escalation word in the customer\'s own row stops it; one in our own notice does not (QM23, QM24)', function () use ($ctx, $evo, $send) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $c = qf_chase($b, '256772502291', 'support', 'Q-9291');
        qf_in($p, $c['conv'], 'Last month I wanted a refund from my old provider (test)', '2026-10-31 08:00:00');
        $o = qf_chase($b, '256772502292', 'sales', 'Q-9292');
        qf_msg($p, $o['conv'], ['body' => 'Notice: no refund is due on this account (test)', 'agent_name' => 'DishNet Plugin', 'event_key' => 'ops_payment_received',
                                'sent_at' => '2026-10-31 08:00:00', 'created_at' => '2026-10-31 08:00:00']);
        [, $out] = $send($b);
        $tx = qf_texts($evo);
        $ctx->eq([qf_trow($p, $c['key'])['status'], qf_trow($p, $c['key'])['status_reason'], count($tx), $tx[0]['text'] ?? null],
                 ['taken', 'escalation_word', 1, $o['body']], 'G10 the customer\'s word: taken, not sent; our own notice\'s: the chase sent', $out);
    });

    $ctx->run('G11 preSendCheck: a draft decided yesterday, or with its text changed, is expired, never sent (QM20, QM39)', function () use ($ctx, $evo, $send) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $old = qf_chase($b, '256772502301', 'support', 'Q-9301', ['decided' => '2026-10-31 07:00:00']);
        $ed = qf_chase($b, '256772502302', 'sales', 'Q-9302');
        $p->prepare('UPDATE followup_drafts SET body = ? WHERE id = ?')->execute([$ed['body'] . ' Ask us about a discount.', $ed['draft']]);
        [, $out] = $send($b);
        $svc = new FollowUpService($p);
        $ctx->eq([count(qf_texts($evo)), $svc->getDraft($old['draft'])['status'], $svc->getDraft($ed['draft'])['status'],
                  count(qf_rows($p, "SELECT id FROM followup_events WHERE detail = 'draft expired: quote_chase_not_template'"))],
                 [0, 'expired', 'expired', 2], 'G11 both expired with quote_chase_not_template, nothing claimed or sent', $out);
    });

    $ctx->run('G12 the fresh read: set window 8 18 made at 18:30 while the pass is held inside its loop holds its next draft (ruling R19; QM77)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $a = qf_chase($b, '256772502311', 'support', 'Q-9311', ['decided' => '2026-11-02 14:00:00']);
        $s = qf_chase($b, '256772502312', 'sales', 'Q-9312', ['decided' => '2026-11-02 14:00:00']);
        $hold = $ctx->dir('hold');
        qf_http('GET', $evo . '/__test/hold?dir=' . urlencode($hold));
        $wait = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow('2026-11-02 18:30:00')], null, true);
        for ($i = 0; $i < 300 && !is_file($hold . '/held'); $i++) usleep(100000);
        $ctx->t(is_file($hold . '/held'), 'G12 control: the first send is held inside the pass');
        qf_set_files($b, $b['cfg'] + ['wa_send_hour_to' => '18']);
        touch($hold . '/release');
        [, $out] = $wait();
        $ctx->eq([count(qf_texts($evo)), array_column(qf_rows($p, 'SELECT ref FROM wa_auto_sends'), 'ref'), (new FollowUpService($p))->getDraft($s['draft'])['status']],
                 [1, ['fu:' . $a['fid'] . ':1'], 'approved'], 'G12 the second draft\'s claim decided on the files as they read then: window, nothing claimed', $out);
        foreach (['chase_off', 'rejected'] as $what) {
            qf_fake_evo();
            $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
            $a = qf_chase($b, '256772502313', 'support', 'Q-9313');
            $s = qf_chase($b, '256772502314', 'sales', 'Q-9314');
            $hold = $ctx->dir('hold');
            qf_http('GET', $evo . '/__test/hold?dir=' . urlencode($hold));
            $wait = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()], null, true);
            for ($i = 0; $i < 300 && !is_file($hold . '/held'); $i++) usleep(100000);
            if ($what === 'chase_off') { $cfg = $b['cfg']; unset($cfg['quote_chase_enabled']); qf_set_files($b, $cfg); }
            else $p->prepare("UPDATE followup_drafts SET status = 'rejected', decided_by = 'staff:Tester' WHERE id = ?")->execute([$s['draft']]);
            touch($hold . '/release');
            [, $out] = $wait();
            $ctx->eq([count(qf_texts($evo)), array_column(qf_rows($p, 'SELECT ref FROM wa_auto_sends'), 'ref')], [1, ['fu:' . $a['fid'] . ':1']],
                     $what === 'chase_off' ? 'G12 off chase made while the pass is held: its next draft is held, unclaimed (ruling R19)'
                                           : 'G12 the next draft rejected on the screen meanwhile: read again by id, not claimed (B11-02)', $out);
        }
    });

    $ctx->run('G13 an enquiry draft decided on the files read before its ledger step: the chase turned off meanwhile, it is recorded, not claimed (QM81)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo'];
        qf_chase($b, '256772502321', 'support', 'Q-9321');
        $e = qf_enquiry($b, '256772502322', 'sales');
        $hold = $ctx->dir('hold');
        qf_http('GET', $evo . '/__test/hold?dir=' . urlencode($hold));
        $wait = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()], null, true);
        for ($i = 0; $i < 300 && !is_file($hold . '/held'); $i++) usleep(100000);
        $cfg = $b['cfg']; unset($cfg['quote_chase_enabled']);
        qf_set_files($b, $cfg);
        touch($hold . '/release');
        [, $out] = $wait();
        $ctx->eq([count(qf_texts($evo)), qf_rows($p, "SELECT kind, mode, outcome FROM wa_auto_sends ORDER BY id")],
                 [2, [['kind' => 'quote', 'mode' => 'claim', 'outcome' => 'sent'], ['kind' => 'enquiry', 'mode' => 'record', 'outcome' => 'sent']]],
                 'G13 the quote claimed; the enquiry, with only the ledger on by then, recorded after its send', $out);
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo'];
        qf_chase($b, '256772502323', 'support', 'Q-9323');
        $e = qf_enquiry($b, '256772502324', 'sales');
        $hold = $ctx->dir('hold');
        qf_http('GET', $evo . '/__test/hold?dir=' . urlencode($hold));
        $wait = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()], null, true);
        for ($i = 0; $i < 300 && !is_file($hold . '/held'); $i++) usleep(100000);
        $cfg = $b['cfg']; unset($cfg['followup_enabled']);
        qf_set_files($b, $cfg);
        touch($hold . '/release');
        [, $out] = $wait();
        $ctx->eq([count(qf_texts($evo)), count(qf_rows($p, "SELECT id FROM wa_auto_sends WHERE kind = 'enquiry'")), (new FollowUpService($p))->getDraft($e['draft'])['status']],
                 [1, 0, 'approved'], 'G13 followup_enabled cleared meanwhile: the enquiry held, neither sent nor claimed', $out);
    });

    $ctx->run('G14 a run begun with the ledger off: on ack made while it is held holds its next enquiry draft; the next run claims it (Q15-F04; QM81)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $base = ['followup_enabled' => '1'];
        $b = qf_gbook($ctx, $evo, $base); $p = $b['pdo'];
        $off = $b['cfg']; unset($off['wa_send_ledger']);
        $e1 = qf_enquiry($b, '256772502331', 'support');
        $e2 = qf_enquiry($b, '256772502332', 'sales');
        $hold = $ctx->dir('hold');
        qf_http('GET', $evo . '/__test/hold?dir=' . urlencode($hold));
        $wait = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()], $off, true);
        for ($i = 0; $i < 300 && !is_file($hold . '/held'); $i++) usleep(100000);
        qf_set_files($b, $b['cfg']);   // on ack: the ledger on (the chase keys set)
        touch($hold . '/release');
        [, $out] = $wait();
        $svc = new FollowUpService($p);
        $ctx->eq([count(qf_texts($evo)), count(qf_rows($p, 'SELECT id FROM wa_auto_sends')), $svc->getDraft($e1['draft'])['status'], $svc->getDraft($e2['draft'])['status']],
                 [1, 0, 'sent', 'approved'], 'G14 the first sent as 5.18.103 sends it; the second held: neither sent nor claimed, no ledger row', $out);
        [, $out2] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow('2026-11-02 10:05:00')]);
        $ctx->eq([count(qf_texts($evo)), qf_rows($p, 'SELECT kind, ref, mode, outcome FROM wa_auto_sends')],
                 [2, [['kind' => 'enquiry', 'ref' => 'fu:' . $e2['fid'] . ':1', 'mode' => 'claim', 'outcome' => 'sent']]], 'G14 the next run claims it and sends it', $out2);
    });

    $ctx->run('G15 every 5.18.104 switch off: the enquiry drafts are sent as 5.18.103 sends them (QM38)', function () use ($ctx, $evo, $send) {
        qf_fake_evo();
        $b = $ctx->book(['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'evo_instance_support' => 'DishNet-UG', 'evo_instance_account' => 'DishNet-UG',
                         'evo_instance_sales' => 'Sales-UG', 'evo_api_url' => $evo, 'evo_api_key' => 'fake-key-qf', 'followup_enabled' => '1'], 'g');
        $e = qf_enquiry($b, '256772502341', 'support');
        $e2 = qf_enquiry($b, '256772502342', 'account');   // the same handset as support's (DishNet-UG): 5.18.103 sends both in one run
        [, $out] = $send($b);
        $svc = new FollowUpService($b['pdo']);
        $m = qf_rows($b['pdo'], "SELECT agent_name FROM wa_messages WHERE wa_message_id IN ('FAKE-EVO-MSG-1', 'FAKE-EVO-MSG-2') ORDER BY wa_message_id");
        $ctx->eq([count(qf_texts($evo)), array_column(qf_texts($evo), 'instance'), count(qf_rows($b['pdo'], 'SELECT id FROM wa_auto_sends')), array_column($m, 'agent_name'),
                  qf_rows($b['pdo'], 'SELECT event FROM evo_webhook_seen ORDER BY message_id'), $svc->getDraft($e['draft'])['status'], $svc->getDraft($e2['draft'])['status']],
                 [2, ['DishNet-UG', 'DishNet-UG'], 0, ['DishNet', 'DishNet'], [['event' => 'followup.send'], ['event' => 'followup.send']], 'sent', 'sent'],
                 'G15 both sent from the one handset (no per-handset cut), stored as DishNet, each echo followup.send, no claim and no ledger row', $out);
        $lines = array_values(array_map(function ($l) { return (string)preg_replace('/^.*?\[followup_send\] /', '', $l); },
                                        preg_grep('/\[followup_send\]/', explode("\n", $out))));
        $ctx->eq($lines, ['sent=2 held=0 failed=0'], 'G15 the run prints 5.18.103\'s one line and no 5.18.104 count (nothing held, deferred or claimed)', $out);
    });

    $ctx->run('G16 the owned-number hold on the fresh read: wa_followups_on_owned_numbers cleared between two drafts of one pass (ruling R19; Q16-F05; QM82)', function () use ($ctx, $evo) {
        require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';   // sj_verify_department_numbers()
        foreach (['ChannelRegistry', 'EvolutionApiService', 'OwnedNumberHold'] as $l) require_once $ctx->tree() . '/lib/' . $l . '.php';
        $setup = function (array $b): void {
            qf_staff($b['pdo'], 81, 'Test Owner');
            $reg = new ChannelRegistry($b['pdo'], $b['store']);
            $map = EvolutionApiService::configInstanceMap($b['cfg']);
            sj_verify_department_numbers($reg, $map);
            $reg->create(['channel_id' => 'sales-901', 'evo_instance' => 'Own-UG', 'display_name' => 'Own', 'role' => 'sales', 'owner_type' => 'staff',
                          'owner_staff_id' => 81, 'status' => 'active'], 'test', 'fixture', $map);
            $reg->verifyNumber('sales-901', '+256700588099', 'test', 'fixture');
        };
        $lift = ['multi_number_channels_enabled' => '1', 'wa_followups_on_owned_numbers' => '1', 'followup_enabled' => '1'];
        foreach (['quote', 'enquiry'] as $form) {
            foreach (['cleared', 'control'] as $what) {
                qf_fake_evo();
                $b = qf_gbook($ctx, $evo, $lift); $p = $b['pdo'];
                $setup($b);
                if ($form === 'quote') {
                    qf_chase($b, '256772502401', 'support', 'Q-9401');
                    $own = qf_chase($b, '256772502402', 'sales-901', 'Q-9402', ['persona' => 'Test']);
                } else {
                    qf_enquiry($b, '256772502401', 'support');
                    $own = qf_enquiry($b, '256772502402', 'sales-901');
                }
                $hold = $ctx->dir('hold');
                qf_http('GET', $evo . '/__test/hold?dir=' . urlencode($hold));
                $wait = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()], null, true);
                for ($i = 0; $i < 300 && !is_file($hold . '/held'); $i++) usleep(100000);
                $ctx->t(is_file($hold . '/held'), "G16 {$form} {$what}: control: the first send is held inside the pass");
                if ($what === 'cleared') { $cfg = $b['cfg']; unset($cfg['wa_followups_on_owned_numbers']); qf_set_files($b, $cfg); }
                touch($hold . '/release');
                [, $out] = $wait();
                $tx = qf_texts($evo);
                $fu = (new FollowUpService($p))->get($own['fid']);
                $refs = array_column(qf_rows($p, "SELECT ref FROM wa_auto_sends WHERE ref = ?", ['fu:' . $own['fid'] . ':1']), 'ref');
                $why = qf_rows($p, "SELECT detail FROM followup_events WHERE followup_id = ? AND event = 'closed'", [$own['fid']]);
                $held = isset($why[0]) && strpos((string)$why[0]['detail'], OwnedNumberHold::REASON) !== false;
                if ($what === 'cleared') {
                    $ctx->eq([count($tx), $fu['close_reason'], $held, $refs], [1, 'cancelled', true, []],
                             "G16 {$form} drafts: the second, on a salesperson's own number, closed with OwnedNumberHold::REASON; nothing claimed, recorded or sent", $out);
                } else {
                    $ctx->eq([count($tx), $tx[1]['instance'] ?? null, $fu['close_reason'], $refs], [2, 'Own-UG', null, ['fu:' . $own['fid'] . ':1']],
                             "G16 {$form} drafts, control: with no change between, the second is sent from the salesperson's own number", $out);
                }
            }
        }
    });

    $ctx->run('G17 the window: at 21:00 and on Sunday the quote pass sends nothing, and the draft waits (QM4, QM5)', function () use ($ctx, $evo) {
        foreach (['2026-11-02 21:00:00' => 'Monday 21:00', '2026-11-08 10:00:00' => 'Sunday 10:00'] as $at => $label) {
            qf_fake_evo();
            $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
            $c = qf_chase($b, '256772502351', 'support', 'Q-9351', ['decided' => gmdate('Y-m-d H:i:s', qf_t($at) - 600)]);
            [, $out] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_t($at)]);
            $ctx->eq([count(qf_texts($evo)), count(qf_rows($p, 'SELECT id FROM wa_auto_sends')), (new FollowUpService($p))->getDraft($c['draft'])['status']],
                     [0, 0, 'approved'], "G17 {$label}: nothing sent or claimed; the draft still approved", $out);
        }
    });

    $ctx->run('G18 a sticky human_active from before the quotation does not stop the chase (R104-02; QM44)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $cv = qf_conv($p, '256772502361', 'support', null, 'human_active');
        $p->prepare("UPDATE wa_conversations SET last_human_reply_at = '2026-10-20 08:00:00', last_customer_at = '2026-10-31 09:00:00' WHERE id = ?")->execute([$cv]);
        $c = qf_chase($b, '256772502361', 'support', 'Q-9361', ['conv' => $cv]);
        [, $out] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()]);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($c['fid'])['closed_at']], [1, null],
                 'G18 the conversation\'s old hand-over state and its earlier message are not a colleague since the quotation: sent', $out);
    });

    $ctx->run('G19 the quote pass\'s lock: while another holds it the quote pass is skipped and counted; the enquiry pass goes on (B11-02)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo'];
        $c = qf_chase($b, '256772502371', 'support', 'Q-9371');
        $e = qf_enquiry($b, '256772502372', 'sales');
        $lock = QuoteChase::passLock($b['data']);
        $ctx->t($lock !== null, 'G19 control: the test holds <data>/quote_send.lock');
        [, $out] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()]);
        QuoteChase::passUnlock($lock);
        $tx = qf_texts($evo);
        $ctx->eq([count($tx), $tx[0]['text'] ?? null, (new FollowUpService($p))->getDraft($c['draft'])['status'], strpos($out, 'quote_pass_busy=1') !== false],
                 [1, $e['body'], 'approved', true], 'G19 the quote draft waits, quote_pass_busy counted; the enquiry sent', $out);
        // The next run, probed in its own process after the cron returns, as master's next job would find it: a flock left
        // in the cron's globals is still held there (a child's exit would free it, so the test process cannot see this).
        [, $out2] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow('2026-11-02 10:05:00'), '_qf_lock_probe' => 1]);
        $ctx->eq((new FollowUpService($p))->getDraft($c['draft'])['status'], 'sent', 'G19 the test\'s lock released: the next run sends it', $out2);
        $ctx->t(strpos($out2, 'qf_lock_probe_control=held') !== false, 'G19 control: in the run\'s process, a lock held is seen held (a second passLock answers null)', $out2);
        $ctx->t(strpos($out2, 'qf_lock_probe=free') !== false, 'G19 the run left the lock free in its own process: released in its finally, never left to the process\'s exit (B11-02)', $out2);
    });

    $ctx->run('G20 a step that may have been sent: settled uncertain, counted as taken, the next armed, never sent again (§H.2.10.6 "Uncertain")', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $c = qf_chase($b, '256772502381', 'support', 'Q-9381');
        qf_http('GET', $evo . '/__test/fail_instance?name=DishNet-UG&code=504');
        [, $out] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()]);
        qf_http('GET', $evo . '/__test/fail_instance?clear=1');
        $f = (new FollowUpService($p))->get($c['fid']);
        $ctx->eq([qf_rows($p, 'SELECT outcome FROM wa_auto_sends'), (new FollowUpService($p))->getDraft($c['draft'])['status'], (int)$f['attempts'], $f['due_at'],
                  count(qf_rows($p, 'SELECT id FROM followup_sends'))],
                 [[['outcome' => 'uncertain']], 'uncertain', 1, '2026-11-04 07:00:00', 0],
                 'G20 a 504: the claim settled uncertain, the draft uncertain, step 1 counted, step 2 armed from the claim, no followup_sends row', $out);
        [, $out2] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow('2026-11-02 10:05:00')]);
        $ctx->eq([count(qf_texts($evo)), count(qf_rows($p, 'SELECT id FROM wa_auto_sends'))], [0, 1], 'G20 the next run sends nothing again', $out2);
    });

    $ctx->run('G21 a read inside the quote pass\'s listing throws under master\'s try/catch: the lock is released, never left to the process\'s exit (B11-02)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        qf_chase($b, '256772502411', 'support', 'Q-9411');
        // the listing's own read made to fail: approvedQuoteDrafts() orders by d.decided_at (PDO throws: ERRMODE_EXCEPTION). SQLite
        // checks the whole schema on RENAME COLUMN, and an older view of this book names a column it no longer has, so the views go
        // first (as H07 does); the cron reads no view before the throw.
        foreach ($p->query("SELECT name FROM sqlite_master WHERE type = 'view'")->fetchAll(\PDO::FETCH_COLUMN) as $v) $p->exec('DROP VIEW [' . $v . ']');
        $p->exec('ALTER TABLE followup_drafts RENAME COLUMN decided_at TO decided_at_qf');
        [, $out] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow(), '_qf_as_master' => 1, '_qf_lock_probe' => 1]);
        $ctx->t(strpos($out, 'qf_master_caught=PDOException') !== false && strpos($out, 'decided_at') !== false
                && preg_match('/qf_master_trace=[^\n]*FollowUpService->approvedQuoteDrafts/', $out) === 1,
                'G21 control: the throw came from the quote pass\'s listing (approvedQuoteDrafts), which runs only with the lock taken, and master\'s catch took it', $out);
        $ctx->eq([count(qf_texts($evo)), strpos($out, 'qf_lock_probe_control=held') !== false, strpos($out, 'qf_lock_probe=free') !== false], [0, true, true],
                 'G21 nothing sent; in the same process after the catch, the lock is free (the try opens before the lock is taken)', $out);
    });

    $ctx->run('G22 the quote pass\'s lock is held during its own sends and free during the enquiry pass, which holds neither lock (B11-02; §H.2.12.4)', function () use ($ctx, $evo) {
        $probe = function (array $b, string $hold, callable $wait) use ($ctx): array {
            for ($i = 0; $i < 300 && !is_file($hold . '/held'); $i++) usleep(100000);
            $isHeld = is_file($hold . '/held');
            $l = QuoteChase::passLock($b['data']);
            $free = $l !== null;
            if ($l !== null) QuoteChase::passUnlock($l);
            touch($hold . '/release');
            [, $out] = $wait();
            return [$isHeld, $free, $out];
        };
        // control: a send of the quote pass held inside its loop: the lock is held, by the run, until the pass ends
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']);
        $c = qf_chase($b, '256772502421', 'support', 'Q-9421');
        $e = qf_enquiry($b, '256772502422', 'sales');
        $hold = $ctx->dir('hold');
        qf_http('GET', $evo . '/__test/hold?dir=' . urlencode($hold));
        [$isHeld, $free, $out] = $probe($b, $hold, qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()], null, true));
        $tx = qf_texts($evo);
        $ctx->eq([$isHeld, $free, count($tx), $tx[0]['text'] ?? null], [true, false, 2, $c['body']],
                 'G22 control: the chase\'s send held inside the quote pass: the lock is held then; the chase and then the enquiry sent', $out);
        // an enquiry's send held, after a quote pass that took the lock and listed nothing: the lock is free then
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772502423', 'sales');
        $hold = $ctx->dir('hold');
        qf_http('GET', $evo . '/__test/hold?dir=' . urlencode($hold));
        [$isHeld, $free, $out] = $probe($b, $hold, qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()], null, true));
        $ctx->eq([$isHeld, $free, count(qf_texts($evo)), (new FollowUpService($p))->getDraft($e['draft'])['status']], [true, true, 1, 'sent'],
                 'G22 the enquiry\'s send held: the quote pass\'s lock is free then (released when the quote pass ended, so the tool\'s 70 s wait never waits on an enquiry send)', $out);
    });

    $ctx->run('G23 an enquiry the ledger records, refused at the send for a reason that clears by itself, writes no row; sent at the next run, its one row is sent (items 6, 12; §H.2.5.5)', function () use ($ctx, $evo) {
        require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';   // sj_verify_department_numbers()
        foreach (['ChannelRegistry', 'EvolutionApiService'] as $l) require_once $ctx->tree() . '/lib/' . $l . '.php';
        qf_fake_evo();
        // the ledger on, the chase off and no offers: an enquiry is recorded after its send, never claimed (§H.2.6.4)
        $b = qf_gbook($ctx, $evo, ['quote_chase_enabled' => '', 'followup_enabled' => '1', 'multi_number_channels_enabled' => '1',
                                   'wa_followups_on_owned_numbers' => '1']);
        $p = $b['pdo'];
        $ctx->t(WaSendBudget::on($b['cfg'], $b['data']) && !WaSendBudget::enquiryClaims($b['cfg']), 'G23 control: the ledger on, and an enquiry recorded, not claimed');
        qf_staff($p, 82, 'Test Owner');
        $reg = new ChannelRegistry($p, $b['store']);
        $map = EvolutionApiService::configInstanceMap($b['cfg']);
        $reg->create(['channel_id' => 'sales-902', 'evo_instance' => 'Own-UG', 'display_name' => 'Own', 'role' => 'sales', 'owner_type' => 'staff',
                      'owner_staff_id' => 82, 'status' => 'active'], 'test', 'fixture', $map);
        $reg->verifyNumber('sales-902', '+256700588098', 'test', 'fixture');
        // the department numbers not yet verified: the policy refuses sales-902 with internal_numbers_incomplete, a reason that
        // clears by itself (AutomationPolicy::TRANSIENT), so the draft stays approved
        $e = qf_enquiry($b, '256772502431', 'sales-902');
        // A variant beside the cron, in this copy only, with the sender's own hold and pre-check out of the way (as
        // tests/test_pilot_safety.php's followup_send_attime.php): only the central guard at the send is left to refuse it.
        $tree = $ctx->tree();
        $src = (string)file_get_contents($tree . '/cron/followup_send.php');
        $made = true;
        foreach (["[\$heldSql, \$heldArgs] = \$evo->automationPolicy()->sqlHeld('f.channel');" => "[\$heldSql, \$heldArgs] = ['', []];",
                  "    \$why = \$evo->automationPolicy()->refusal(\$chan, \$phone);" => "    \$why = '';"] as $o => $n) {
            if (substr_count($src, $o) !== 1) $made = false;
            $src = str_replace($o, $n, $src);
        }
        $ctx->t($made, 'G23 control: the variant was made, each of its two anchors found exactly once');
        if (!$made) return;
        $variant = 'cron/followup_send_qf_atsend.php';
        file_put_contents($tree . '/' . $variant, $src);
        try {
            [, $out] = qf_cron($ctx, $b, $variant, ['_qf_test_now' => qf_gnow()]);
        } finally {
            @unlink($tree . '/' . $variant);
        }
        $svc = new FollowUpService($p);
        $ctx->t(strpos($out, 'automated send refused on channel sales-902: internal_numbers_incomplete') !== false,
                'G23 control: the refusal was the send\'s own (EvolutionApiService\'s policy at sendText), after the ledger step', $out);
        $ctx->eq([count(qf_texts($evo)), qf_rows($p, 'SELECT ref, outcome FROM wa_auto_sends'), $svc->getDraft($e['draft'])['status'], $svc->get($e['fid'])['closed_at']],
                 [0, [], 'approved', null], 'G23 refused at the send: nothing sent, NO ledger row (item 12 records only an outcome step 9 knows), the draft approved, the follow-up open', $out);
        sj_verify_department_numbers($reg, $map);   // the reason clears
        [, $out2] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow('2026-11-02 10:05:00')]);
        $tx = qf_texts($evo);
        $ctx->eq([count($tx), $tx[0]['instance'] ?? null, qf_rows($p, 'SELECT kind, ref, mode, outcome FROM wa_auto_sends'), $svc->getDraft($e['draft'])['status']],
                 [1, 'Own-UG', [['kind' => 'enquiry', 'ref' => 'fu:' . $e['fid'] . ':1', 'mode' => 'record', 'outcome' => 'sent']], 'sent'],
                 'G23 the next run sends it: its one ledger row, at the same ref, is sent (counted by the caps, the spacing and the per-person rule)', $out2);
    });
}];

<?php
declare(strict_types=1);
/**
 * Section U of tests/test_quote_followup.php — the enquiry sender unjammed (5.18.106, root docs/75 §C F1–F5; G1–G7).
 * TEST ONLY.
 *
 * Uganda only (StaffJobsGate::applies); South Sudan is shown unchanged beside each fix. The real crons, from the copy, in
 * a child process (qf_cron): the sender at a fixed Kampala moment ($_qf_test_now, so its window and F2's limit hold
 * whatever the hour of the run), the run, the scan and the close job on the real clock (they read no test clock), so
 * their fixtures are placed relative to time().
 *
 *   F1  a draft Evolution refused three times since its approval (HTTP 400 to sendText, never an HTML page) closes
 *       send_failed once its number delivered another message after the first refusal; outside claim mode only.
 *   F2  the sender never sends to a customer quiet longer than followup_max_age_hours, on the run's clock: closed expired,
 *       before the window and before any claim; a misread limit expires nothing.
 *   F3  the close job expires stale follow-ups (row AND conversation stale; gone → cancelled; oldest first; 200 a pass);
 *       the run's due() leaves stale rows out.
 *   F4  the scan opens one follow-up per quiet spell, with one second chance after a single send_failed.
 *   F5  due() leaves out a follow-up holding a pending or approved draft, or an uncertain draft of its current attempt; the
 *       sender handles one draft of a follow-up a pass and expires its other approved drafts after a send.
 *
 * Every person and number is fictitious.
 */

/** A refusal as the sender records one (recordFailure), its event moved to $at (UTC). */
function qu_refuse(\PDO $p, int $fid, string $at, string $detail = 'FAKE-EVO-INSTANCE-DOWN (test control) [HTTP 400 on POST /message/sendText/DishNet-UG]'): void
{
    (new FollowUpService($p))->recordFailure($fid, 0, $detail, 'sender');
    $id = (int)$p->query('SELECT MAX(id) FROM followup_events')->fetchColumn();
    $p->prepare('UPDATE followup_events SET created_at = ? WHERE id = ?')->execute([$at, $id]);
}

/** A message stored on a conversation of $chan at $at (UTC): by default outbound, with WhatsApp's id (a delivery). */
function qu_delivered(\PDO $p, string $phone, string $chan, string $at, array $m = []): void
{
    static $n = 0;
    $n++;
    $st = $p->prepare('SELECT id FROM wa_conversations WHERE phone = ? AND channel = ?');
    $st->execute([$phone, $chan]);
    $cv = (int)($st->fetchColumn() ?: 0);
    if ($cv === 0) $cv = qf_conv($p, $phone, $chan);
    qf_msg($p, $cv, $m + ['direction' => 'out', 'role' => 'agent', 'agent_name' => 'DishNet', 'wa_message_id' => 'QU-DELIVERED-' . $n . '-' . bin2hex(random_bytes(3)),
                          'sent_at' => $at, 'created_at' => $at]);
}

/** A UTC moment $days days before now (the run, the scan and the close job read the real clock). */
function qu_ago(float $days): string { return gmdate('Y-m-d H:i:s', time() - (int)round($days * 86400)); }

/** The sender's 5.18.104 count line, as a list of its codes (fixed codes only). */
function qu_counts(string $out): string
{
    $l = '';
    foreach (preg_grep('/\[followup_send\] [a-z_]+=\d/', explode("\n", $out)) ?: [] as $line) if (strpos($line, 'sent=') === false) $l = (string)preg_replace('/^.*?\[followup_send\] /', '', $line);
    return $l;
}

/** A follow-up opened on conversation $cv (or a gone one) with the customer's last message at $last, closed $reason when given. */
function qu_open(\PDO $p, int $cv, string $phone, string $chan, string $last, ?string $reason = null): int
{
    $svc = new FollowUpService($p);
    $fu = $svc->open(['id' => $cv, 'channel' => $chan, 'phone' => $phone, 'last_customer_at' => $last], ['opened_by' => 'test']);
    if ($reason !== null) $svc->close((int)$fu['id'], $reason, 'test fixture', 'test');
    return (int)$fu['id'];
}

$QF_SECTIONS['U'] = ['title' => 'The enquiry sender unjammed (5.18.106, root docs/75 §C F1–F5)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'QuoteChase', 'ConversationService', 'HumanTakeover', 'WaSendBudget'] as $l) require_once $tree . '/lib/' . $l . '.php';
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'U00 control: the fake Evolution answers');
    if ($evo === '') return;
    $T = qf_gnow();   // Monday 2 November 2026, 10:00 Kampala = 07:00 UTC; F2's limit (336 h) is then 2026-10-19 07:00:00 UTC
    $send = function (array $b, array $vars = [], ?array $cfg = null) use ($ctx, $T): array {
        return qf_cron($ctx, $b, 'cron/followup_send.php', $vars + ['_qf_test_now' => $T], $cfg);
    };
    // the ledger on, the chase off and no offers: an enquiry is recorded after its send, never claimed (§H.2.6.4)
    $record = ['quote_chase_enabled' => '', 'followup_enabled' => '1'];
    $ss = function (string $tag) use ($ctx, $evo): array {
        return $ctx->book(['tenant_profile' => 'south-sudan', 'timezone' => QF_TZ, 'evo_instance_support' => 'DishNet-UG', 'evo_instance_account' => 'DishNet-UG',
                           'evo_instance_sales' => 'Sales-UG', 'evo_api_url' => $evo, 'evo_api_key' => 'fake-key-qf', 'followup_enabled' => '1'], $tag);
    };
    $stale = function (\PDO $p, int $conv, string $at): void {
        $p->prepare('UPDATE wa_conversations SET last_customer_at = ? WHERE id = ?')->execute([$at, $conv]);
        $p->prepare('UPDATE followups SET last_customer_at = ? WHERE conversation_id = ?')->execute([$at, $conv]);
    };

    // ---------------------------------------------------------------- F2: the sender's age limit
    $ctx->run('U01 F2: a draft whose customer last wrote 16 days ago is closed expired, never sent or claimed, even outside the window', function () use ($ctx, $evo, $stale) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506101', 'support');
        $stale($p, $e['conv'], '2026-10-17 09:00:00');
        [$rc, $out] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow('2026-11-02 21:00:00')]);   // outside the window
        $svc = new FollowUpService($p);
        $f = $svc->get($e['fid']);
        $ev = qf_rows($p, "SELECT detail, actor FROM followup_events WHERE followup_id = ? AND event = 'closed'", [$e['fid']]);
        $ctx->eq([$rc, count(qf_texts($evo)), $f['close_reason'], $ev[0]['detail'] ?? null, $ev[0]['actor'] ?? null, count(qf_rows($p, 'SELECT id FROM wa_auto_sends')),
                  strpos(qu_counts($out), 'enquiry_expired=1') !== false],
                 [0, 0, 'expired', 'expired — the customer last wrote more than 336 hours ago', 'sender', 0, true],
                 'U01 closed expired at 21:00 (outside the window), by the sender, with the limit named; nothing sent, no claim; counted enquiry_expired', $out);
        $ctx->eq($svc->approvedDrafts(10), [], 'U01 the draft left the approved list with its follow-up');
    });

    $ctx->run('U02 F2 control: 13 days quiet is sent; the limit is the run\'s clock, not the real one', function () use ($ctx, $evo, $send, $stale) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506201', 'support');
        $stale($p, $e['conv'], '2026-10-20 09:00:00');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->getDraft($e['draft'])['status'], (new FollowUpService($p))->get($e['fid'])['closed_at']],
                 [1, 'sent', null], 'U02 13 days before the run\'s moment (and weeks before the real clock): sent, the follow-up open', $out);
    });

    $ctx->run('U03 F2 reads followup_max_age_hours: 48 expires a 3-day silence; a misread (0, -5, abc) expires nothing (G5)', function () use ($ctx, $evo, $send, $stale) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1', 'followup_max_age_hours' => '48']); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506301', 'support');
        $stale($p, $e['conv'], '2026-10-30 09:00:00');
        [, $out] = $send($b);
        $f = (new FollowUpService($p))->get($e['fid']);
        $d = qf_rows($p, "SELECT detail FROM followup_events WHERE followup_id = ? AND event = 'closed'", [$e['fid']]);
        $ctx->eq([count(qf_texts($evo)), $f['close_reason'], $d[0]['detail'] ?? null],
                 [0, 'expired', 'expired — the customer last wrote more than 48 hours ago'], 'U03 48 hours: a 3-day silence expires', $out);
        foreach (['0', '-5', 'abc'] as $i => $bad) {
            qf_fake_evo();
            $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1', 'followup_max_age_hours' => $bad]); $p = $b['pdo'];
            $e = qf_enquiry($b, '25677250631' . $i, 'support');
            $stale($p, $e['conv'], '2026-10-10 09:00:00');
            [, $out] = $send($b);
            $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['close_reason']], [1, null],
                     "U03 followup_max_age_hours={$bad}: nothing expires (sent as 5.18.105 sends it)", $out);
        }
    });

    // ---------------------------------------------------------------- F1: three refusals while the number delivers
    // A refusal counts only when the number delivered another message since the refusal before it (for the first, since
    // the draft's approval): planted here as refusals at :10, :20, :30 past 06:00 UTC and deliveries between them.
    $ctx->run('U04 F1: three 400 refusals since approval, each after a delivery on its number: closed send_failed, not sent again (record mode, and the ledger off)', function () use ($ctx, $evo, $send, $record) {
        foreach (['record', 'off'] as $mode) {
            qf_fake_evo();
            $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
            $cfg = null;
            if ($mode === 'off') { $cfg = $b['cfg']; unset($cfg['wa_send_ledger']); }
            if ($mode === 'record') $ctx->t(WaSendBudget::on($b['cfg'], $b['data']) && !WaSendBudget::enquiryClaims($b['cfg']), 'U04 control: the ledger on, an enquiry recorded, not claimed');
            $e = qf_enquiry($b, '256772506401', 'support');
            foreach (['06:05', '06:15', '06:25'] as $hm) qu_delivered($p, '256772506409', 'support', '2026-11-02 ' . $hm . ':00');
            foreach (['06:10', '06:20', '06:30'] as $hm) qu_refuse($p, $e['fid'], '2026-11-02 ' . $hm . ':00');
            [, $out] = $send($b, [], $cfg);
            $f = (new FollowUpService($p))->get($e['fid']);
            $d = qf_rows($p, "SELECT detail FROM followup_events WHERE followup_id = ? AND event = 'closed'", [$e['fid']]);
            $ctx->eq([count(qf_texts($evo)), count((array)(qf_http('GET', $evo . '/__test/state')[1]['failed_calls'] ?? [])), $f['close_reason'], $d[0]['detail'] ?? null,
                      count(qf_rows($p, 'SELECT id FROM wa_auto_sends')), strpos(qu_counts($out), 'enquiry_tries_exhausted=1') !== false],
                     [0, 0, 'send_failed', 'send_failed — Evolution refused it three times (HTTP 400) while the number delivered other messages', 0, true],
                     "U04 {$mode}: closed send_failed with its reason, no fourth try, no ledger row, counted enquiry_tries_exhausted", $out);
        }
    });

    $ctx->run('U04b F1: an outage — three refusals with nothing delivered between them, then the number recovers and delivers: not closed; the draft is tried and sent', function () use ($ctx, $evo, $send, $record) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506451', 'support');
        foreach (['06:10', '06:20', '06:30'] as $hm) qu_refuse($p, $e['fid'], '2026-11-02 ' . $hm . ':00');
        foreach (['06:40', '06:45', '06:50'] as $hm) qu_delivered($p, '256772506459', 'support', '2026-11-02 ' . $hm . ':00');   // the recovery
        $ctx->eq((new FollowUpService($p))->refusedWhileDelivering($e['fid'], 'support', '2026-11-02 06:00:00'), 0, 'U04b none of the outage\'s refusals counts');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['closed_at'], (new FollowUpService($p))->getDraft($e['draft'])['status']],
                 [1, null, 'sent'], 'U04b after the outage: sent, the follow-up open', $out);
    });

    $ctx->run('U04c F1 places a delivery by when it was stored: the Inbox\'s media rows (sent_at in Kampala time, three hours ahead) count for none of an outage\'s refusals', function () use ($ctx, $evo, $send, $record) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506461', 'support');
        // images sent from the Inbox at 03:05, 03:15 and 03:25 UTC, before the draft's approval at 06:00: sent_at written by
        // date() in Kampala time reads 06:05, 06:15 and 06:25 — inside the outage's intervals
        foreach (['05', '15', '25'] as $mm) qu_delivered($p, '256772506469', 'support', '2026-11-02 06:' . $mm . ':00',
                                                       ['event_key' => 'wa_image_send', 'created_at' => '2026-11-02 03:' . $mm . ':00']);
        foreach (['06:10', '06:20', '06:30'] as $hm) qu_refuse($p, $e['fid'], '2026-11-02 ' . $hm . ':00');   // the number down throughout
        // and a handset message imported after the outage with WhatsApp's own time inside it: stored at 07:30 UTC
        qu_delivered($p, '256772506468', 'support', '2026-11-02 06:17:00', ['created_at' => '2026-11-02 07:30:00']);
        $ctx->eq((new FollowUpService($p))->refusedWhileDelivering($e['fid'], 'support', '2026-11-02 06:00:00'), 0,
                 'U04c none counts: by when they were stored, the media rows came before the approval and the import after the outage');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['closed_at']], [1, null], 'U04c not closed: tried and sent', $out);
    });

    $ctx->run('U05 F1\'s positive control: a delivery before the approval, on another channel, without WhatsApp\'s id, or inbound does not count', function () use ($ctx, $evo, $send, $record) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506501', 'support');
        foreach (['06:10', '06:20', '06:30'] as $hm) qu_refuse($p, $e['fid'], '2026-11-02 ' . $hm . ':00');
        qu_delivered($p, '256772506502', 'support', '2026-11-02 05:55:00');                                       // before the approval
        qu_delivered($p, '256772506503', 'support', '2026-11-02 06:15:00', ['wa_message_id' => '']);               // an empty id (one: the index is unique)
        foreach (['06:05', '06:15', '06:25'] as $i => $hm) {
            qu_delivered($p, '25677250651' . $i, 'sales', '2026-11-02 ' . $hm . ':00');                            // another channel
            qu_delivered($p, '25677250652' . $i, 'support', '2026-11-02 ' . $hm . ':00', ['wa_message_id' => null]); // no WhatsApp id
            qu_delivered($p, '25677250654' . $i, 'support', '2026-11-02 ' . $hm . ':00', ['direction' => 'in', 'role' => 'user', 'agent_name' => null]);   // inbound
        }
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['closed_at'], (new FollowUpService($p))->getDraft($e['draft'])['status']],
                 [1, null, 'sent'], 'U05 no delivery that counts between the refusals: not closed; tried again, and sent', $out);
    });

    $ctx->run('U06 F1: two counted refusals are not three; nor are three when only two came after a delivery', function () use ($ctx, $evo, $send, $record) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506601', 'support');
        foreach (['06:05', '06:15'] as $hm) qu_delivered($p, '256772506609', 'support', '2026-11-02 ' . $hm . ':00');
        foreach (['06:10', '06:20'] as $hm) qu_refuse($p, $e['fid'], '2026-11-02 ' . $hm . ':00');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['closed_at']], [1, null], 'U06 two refusals: sent, the follow-up open', $out);
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506611', 'support');
        foreach (['06:05', '06:15'] as $hm) qu_delivered($p, '256772506619', 'support', '2026-11-02 ' . $hm . ':00');
        foreach (['06:10', '06:20', '06:30'] as $hm) qu_refuse($p, $e['fid'], '2026-11-02 ' . $hm . ':00');   // nothing delivered in (:20, :30]
        $ctx->eq((new FollowUpService($p))->refusedWhileDelivering($e['fid'], 'support', '2026-11-02 06:00:00'), 2, 'U06 three refusals, two of them after a delivery: 2');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['closed_at']], [1, null], 'U06 three refusals, two counted: sent, the follow-up open', $out);
    });

    $ctx->run('U05b F1: a stored row whose WhatsApp id is empty is not a delivery (it alone in the middle interval: two counted, not three)', function () use ($ctx, $evo, $send, $record) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506561', 'support');
        qu_delivered($p, '256772506569', 'support', '2026-11-02 06:05:00');
        qu_delivered($p, '256772506568', 'support', '2026-11-02 06:15:00', ['wa_message_id' => '']);
        qu_delivered($p, '256772506569', 'support', '2026-11-02 06:25:00');
        foreach (['06:10', '06:20', '06:30'] as $hm) qu_refuse($p, $e['fid'], '2026-11-02 ' . $hm . ':00');
        $ctx->eq((new FollowUpService($p))->refusedWhileDelivering($e['fid'], 'support', '2026-11-02 06:00:00'), 2, 'U05b two counted');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['closed_at']], [1, null], 'U05b not closed: sent', $out);
    });

    $ctx->run('U06c F1: busy, then down — three deliveries before the first refusal and none between the refusals count one refusal, not three', function () use ($ctx, $evo, $send, $record) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506631', 'support');
        foreach (['06:01', '06:02', '06:03'] as $hm) qu_delivered($p, '256772506639', 'support', '2026-11-02 ' . $hm . ':00');
        foreach (['06:10', '06:20', '06:30'] as $hm) qu_refuse($p, $e['fid'], '2026-11-02 ' . $hm . ':00');
        $ctx->eq((new FollowUpService($p))->refusedWhileDelivering($e['fid'], 'support', '2026-11-02 06:00:00'), 1, 'U06c one counted: a delivery counts for the interval it falls in, once');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['closed_at']], [1, null], 'U06c not closed: sent', $out);
    });

    $ctx->run('U06d F1: the first refusal needs its delivery too — deliveries only after it count two, not three', function () use ($ctx, $evo, $send, $record) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506641', 'support');
        foreach (['06:15', '06:25'] as $hm) qu_delivered($p, '256772506649', 'support', '2026-11-02 ' . $hm . ':00');
        foreach (['06:10', '06:20', '06:30'] as $hm) qu_refuse($p, $e['fid'], '2026-11-02 ' . $hm . ':00');
        $ctx->eq((new FollowUpService($p))->refusedWhileDelivering($e['fid'], 'support', '2026-11-02 06:00:00'), 2, 'U06d two counted');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['closed_at']], [1, null], 'U06d not closed: sent', $out);
    });

    $ctx->run('U06e F1 on one-second clocks: a delivery stored in the same second as a refusal counts for that refusal, and only for it', function () use ($ctx, $evo, $send, $record) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506651', 'support');
        qu_delivered($p, '256772506659', 'support', '2026-11-02 06:10:00');   // the same second as the first refusal
        qu_delivered($p, '256772506659', 'support', '2026-11-02 06:25:00');   // nothing in (06:10, 06:20]; this one in (06:20, 06:30]
        foreach (['06:10', '06:20', '06:30'] as $hm) qu_refuse($p, $e['fid'], '2026-11-02 ' . $hm . ':00');
        $ctx->eq((new FollowUpService($p))->refusedWhileDelivering($e['fid'], 'support', '2026-11-02 06:00:00'), 2, 'U06e two counted: the tie for the first, the 06:25 delivery for the third');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['closed_at']], [1, null], 'U06e not closed: sent', $out);
    });

    $ctx->run('U07 F1 counts only a 400 to sendText: an HTML page, a 500, a 401 and a 400 elsewhere are not refusals', function () use ($ctx, $evo, $send, $record) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506701', 'support');
        foreach (['06:05', '06:11', '06:13', '06:15', '06:17', '06:19'] as $hm) qu_delivered($p, '256772506709', 'support', '2026-11-02 ' . $hm . ':00');
        qu_refuse($p, $e['fid'], '2026-11-02 06:10:00');
        qu_refuse($p, $e['fid'], '2026-11-02 06:12:00', 'Got an HTML page, not the API. Check the URL is the API root (no /manager on the end). [HTTP 400 on POST /message/sendText/DishNet-UG]');
        qu_refuse($p, $e['fid'], '2026-11-02 06:14:00', 'Internal Server Error [HTTP 500 on POST /message/sendText/DishNet-UG]');
        qu_refuse($p, $e['fid'], '2026-11-02 06:16:00', 'Unauthorized [HTTP 401 on POST /message/sendText/DishNet-UG]');
        qu_refuse($p, $e['fid'], '2026-11-02 06:18:00', 'Bad Request [HTTP 400 on POST /chat/whatsappNumbers/DishNet-UG]');
        qu_refuse($p, $e['fid'], '2026-11-02 06:20:00');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['closed_at'], (new FollowUpService($p))->refusedWhileDelivering($e['fid'], 'support', '2026-11-02 06:00:00')],
                 [1, null, 2], 'U07 two refusals counted of six failures, each after a delivery: sent, the follow-up open', $out);
    });

    $ctx->run('U07b F1 counts this follow-up\'s refusals only: another follow-up on the same number, never refused, is sent', function () use ($ctx, $evo, $send, $record) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
        $y = qf_enquiry($b, '256772506752', 'support', '2026-11-02 05:00:00');   // approved before X's refusals
        $x = qf_enquiry($b, '256772506751', 'support');
        foreach (['06:05', '06:15', '06:25'] as $hm) qu_delivered($p, '256772506759', 'support', '2026-11-02 ' . $hm . ':00');
        foreach (['06:10', '06:20', '06:30'] as $hm) qu_refuse($p, $x['fid'], '2026-11-02 ' . $hm . ':00');
        [, $out] = $send($b);
        $svc = new FollowUpService($p);
        $tx = qf_texts($evo);
        $ctx->eq([count($tx), $tx[0]['text'] ?? null, $svc->get($y['fid'])['closed_at'], $svc->getDraft($y['draft'])['status'], $svc->get($x['fid'])['close_reason']],
                 [1, $y['body'], null, 'sent', 'send_failed'], 'U07b the refused one closed send_failed; the other, on the same number, sent', $out);
    });

    $ctx->run('U08 F1 counts from the draft\'s approval: three refusals before it, each after a delivery, are not this draft\'s', function () use ($ctx, $evo, $send, $record) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo'];
        $e = qf_enquiry($b, '256772506801', 'support');   // decided 2026-11-02 06:00:00
        foreach (['05:05', '05:15', '05:25'] as $hm) qu_delivered($p, '256772506809', 'support', '2026-11-02 ' . $hm . ':00');
        foreach (['05:10', '05:20', '05:30'] as $hm) qu_refuse($p, $e['fid'], '2026-11-02 ' . $hm . ':00');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['closed_at']], [1, null], 'U08 refused before its approval: sent, the follow-up open', $out);
    });

    $ctx->run('U09 F1 leaves claim mode alone: its own tries_exhausted decides there', function () use ($ctx, $evo, $send) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo'];
        $ctx->t(WaSendBudget::enquiryClaims($b['cfg']), 'U09 control: the chase on, an enquiry claimed');
        $e = qf_enquiry($b, '256772506901', 'support');
        foreach (['06:05', '06:15', '06:25'] as $hm) qu_delivered($p, '256772506909', 'support', '2026-11-02 ' . $hm . ':00');
        foreach (['06:10', '06:20', '06:30'] as $hm) qu_refuse($p, $e['fid'], '2026-11-02 ' . $hm . ':00');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->get($e['fid'])['closed_at'], qf_rows($p, 'SELECT kind, mode, outcome FROM wa_auto_sends')],
                 [1, null, [['kind' => 'enquiry', 'mode' => 'claim', 'outcome' => 'sent']]], 'U09 claim mode: claimed and sent, not closed by F1', $out);
    });

    $ctx->run('U10 F1 through the real sender, record mode: refused while the number is down, then sent once it is back; refused while it delivers, closed at the fourth run', function () use ($ctx, $evo, $record) {
        // (a) an outage: every send on the instance refused (400) for three runs; nothing else delivered meanwhile
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo']; $svc = new FollowUpService($p);
        $e = qf_enquiry($b, '256772507001', 'support');
        qf_http('GET', $evo . '/__test/fail_instance?name=DishNet-UG&code=400');
        $outs = [];
        foreach ([0, 1, 2] as $i) [, $outs[]] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow('2026-11-02 10:0' . ($i * 3) . ':00')]);
        qf_http('GET', $evo . '/__test/fail_instance?clear=1');
        $ctx->eq([count(qf_rows($p, "SELECT id FROM followup_events WHERE followup_id = ? AND event = 'failed' AND detail LIKE '% [HTTP 400 on POST /message/sendText/%'", [$e['fid']])),
                  count((array)(qf_http('GET', $evo . '/__test/state')[1]['failed_calls'] ?? [])), $svc->getDraft($e['draft'])['status'], $svc->get($e['fid'])['closed_at']],
                 [3, 3, 'approved', null], 'U10 (a) three runs, three refusals recorded by the sender itself, the draft still approved, the follow-up open', implode("\n", $outs));
        sleep(1);
        $e2 = qf_enquiry($b, '256772507002', 'support', '2026-11-02 05:00:00');   // decided earlier: the pass sends it first
        [, $out] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow('2026-11-02 10:12:00')]);
        $ctx->eq([count(qf_texts($evo)), $svc->getDraft($e2['draft'])['status'], $svc->getDraft($e['draft'])['status'], $svc->get($e['fid'])['closed_at']],
                 [2, 'sent', 'sent', null], 'U10 (a) the number back: the other follow-up delivered, then the refused one tried and sent — the outage closed nothing', $out);
        // (b) the number delivers between the refused runs (a delivery stored before each, a second apart)
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo']; $svc = new FollowUpService($p);
        $e = qf_enquiry($b, '256772507011', 'support');
        qf_http('GET', $evo . '/__test/fail_instance?name=DishNet-UG&code=400');
        $outs = [];
        foreach ([0, 1, 2] as $i) {
            qu_delivered($p, '256772507019', 'support', gmdate('Y-m-d H:i:s'));
            sleep(1);
            [, $outs[]] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow('2026-11-02 10:0' . ($i * 3) . ':00')]);
            sleep(1);
        }
        $ctx->eq([$svc->refusedWhileDelivering($e['fid'], 'support', '2026-11-02 06:00:00'), $svc->get($e['fid'])['closed_at']], [3, null],
                 'U10 (b) three runs refused, each after a delivery: three counted, still open (F1 decides before the next send)', implode("\n", $outs));
        [, $out] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow('2026-11-02 10:12:00')]);
        qf_http('GET', $evo . '/__test/fail_instance?clear=1');
        $ctx->eq([count((array)(qf_http('GET', $evo . '/__test/state')[1]['failed_calls'] ?? [])), $svc->get($e['fid'])['close_reason'], strpos(qu_counts($out), 'enquiry_tries_exhausted=1') !== false],
                 [3, 'send_failed', true], 'U10 (b) the fourth run closes it send_failed before a fourth try', $out);
    });

    // ---------------------------------------------------------------- F5: one draft a pass; the others expire; a taken attempt is never sent
    $ctx->run('U11 F5: two approved drafts of one follow-up: one sent, the other expired after the send, never sent (the ledger off, record mode, claim mode)', function () use ($ctx, $evo, $send, $record) {
        foreach (['off', 'record', 'claim'] as $mode) {
            qf_fake_evo();
            $b = qf_gbook($ctx, $evo, $mode === 'claim' ? ['followup_enabled' => '1'] : $record); $p = $b['pdo']; $svc = new FollowUpService($p);
            $cfg = null;
            if ($mode === 'off') { $cfg = $b['cfg']; unset($cfg['wa_send_ledger']); }
            if ($mode === 'claim') $ctx->t(WaSendBudget::enquiryClaims($b['cfg']), 'U11 control: claim mode');
            $e = qf_enquiry($b, '256772507101', 'support');
            $d2 = $svc->draft($e['fid'], ['verdict' => 'SEND', 'message' => 'A second draft of the same follow-up (test)', 'reason' => 'test']);
            $svc->approve((int)$d2['id'], 'staff:Tester');
            $p->prepare("UPDATE followup_drafts SET decided_at = '2026-11-02 06:30:00' WHERE id = ?")->execute([(int)$d2['id']]);
            [, $out] = $send($b, [], $cfg);
            $tx = qf_texts($evo);
            $ev = qf_rows($p, "SELECT detail FROM followup_events WHERE followup_id = ? AND event = 'skipped' AND detail LIKE 'other approved%'", [$e['fid']]);
            $ctx->eq([count($tx), $tx[0]['text'] ?? null, $svc->getDraft($e['draft'])['status'], $svc->getDraft((int)$d2['id'])['status'], array_column($ev, 'detail')],
                     [1, $e['body'], 'sent', 'expired', ['other approved drafts expired: 1']],
                     "U11 {$mode}: the oldest sent; the second expired after the send, with one event", $out);
            if ($mode !== 'claim') $ctx->t(strpos(qu_counts($out), 'enquiry_second_draft=1') !== false, "U11 {$mode}: the second held this pass, counted enquiry_second_draft", $out);
            [, $out2] = $send($b, ['_qf_test_now' => qf_gnow('2026-11-02 10:05:00')], $cfg);
            $ctx->eq(count(qf_texts($evo)), 1, "U11 {$mode}: the next run sends nothing more", $out2);
        }
    });

    $ctx->run('U12 F5: the second draft is not tried in the same pass when the first fails; a pending draft is not expired by a send', function () use ($ctx, $evo, $send, $record) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo']; $svc = new FollowUpService($p);
        $e = qf_enquiry($b, '256772507201', 'support');
        $d2 = $svc->draft($e['fid'], ['verdict' => 'SEND', 'message' => 'A second draft (test)', 'reason' => 'test']);
        $svc->approve((int)$d2['id'], 'staff:Tester');
        $p->prepare("UPDATE followup_drafts SET decided_at = '2026-11-02 06:30:00' WHERE id = ?")->execute([(int)$d2['id']]);
        qf_http('GET', $evo . '/__test/fail_instance?name=DishNet-UG&code=500');
        [, $out] = $send($b);
        qf_http('GET', $evo . '/__test/fail_instance?clear=1');
        $ctx->eq([count((array)(qf_http('GET', $evo . '/__test/state')[1]['failed_calls'] ?? [])), $svc->getDraft($e['draft'])['status'], $svc->getDraft((int)$d2['id'])['status']],
                 [1, 'approved', 'approved'], 'U12 the first failed (500) and stays approved; the second not tried this pass', $out);
        $d3 = $svc->draft($e['fid'], ['verdict' => 'SEND', 'message' => 'A pending draft (test)', 'reason' => 'test']);
        [, $out2] = $send($b, ['_qf_test_now' => qf_gnow('2026-11-02 10:05:00')]);
        $ctx->eq([count(qf_texts($evo)), $svc->getDraft($e['draft'])['status'], $svc->getDraft((int)$d2['id'])['status'], $svc->getDraft((int)$d3['id'])['status']],
                 [1, 'sent', 'expired', 'pending'], 'U12 the next run: the first sent, the other approved one expired, the pending one left for a person', $out2);
    });

    $ctx->run('U12b F5: a draft whose attempt is taken — a sibling perhaps sent, or a send recorded — is expired, never sent; the next attempt\'s draft is sent', function () use ($ctx, $evo, $send, $record) {
        // (i) the sibling of the same attempt settled uncertain (it may have reached the customer)
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo']; $svc = new FollowUpService($p);
        $e = qf_enquiry($b, '256772507251', 'support');
        $d2 = $svc->draft($e['fid'], ['verdict' => 'SEND', 'message' => 'Its sibling (test)', 'reason' => 'test']);
        $svc->approve((int)$d2['id'], 'staff:Tester');
        $svc->recordUncertain($e['fid'], $e['draft'], 'test: no answer from Evolution', 'test');
        $ctx->eq([(int)$svc->getDraft($e['draft'])['attempt'], (int)$svc->getDraft((int)$d2['id'])['attempt'], $svc->getDraft($e['draft'])['status']], [1, 1, 'uncertain'],
                 'U12b control: both drafts of attempt 1, the first uncertain');
        [, $out] = $send($b);
        $ev = qf_rows($p, "SELECT detail FROM followup_events WHERE followup_id = ? AND event = 'skipped' AND detail LIKE 'draft expired%'", [$e['fid']]);
        $ctx->eq([count(qf_texts($evo)), $svc->getDraft((int)$d2['id'])['status'], count($ev), strpos(qu_counts($out), 'enquiry_attempt_taken=1') !== false],
                 [0, 'expired', 1, true], 'U12b (i) the sibling of an uncertain draft: expired with one event, nothing sent, counted enquiry_attempt_taken', $out);
        // (ii) a send already recorded for the attempt (the follow-up's attempts reached it)
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo']; $svc = new FollowUpService($p);
        $e = qf_enquiry($b, '256772507261', 'support');
        $p->prepare('UPDATE followups SET attempts = 1 WHERE id = ?')->execute([$e['fid']]);
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), $svc->getDraft($e['draft'])['status']], [0, 'expired'], 'U12b (ii) attempt 1 already counted: its draft expired, nothing sent', $out);
        // (iii) control: the next attempt's draft, after attempt 1 was sent
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo']; $svc = new FollowUpService($p);
        $e = qf_enquiry($b, '256772507271', 'support');
        $p->prepare("UPDATE followup_drafts SET status = 'sent' WHERE id = ?")->execute([$e['draft']]);
        $p->prepare('UPDATE followups SET attempts = 1 WHERE id = ?')->execute([$e['fid']]);
        $d2 = $svc->draft($e['fid'], ['verdict' => 'SEND', 'message' => 'The second attempt (test)', 'reason' => 'test']);
        $svc->approve((int)$d2['id'], 'staff:Tester');
        $p->prepare("UPDATE followup_drafts SET decided_at = '2026-11-02 06:00:00' WHERE id = ?")->execute([(int)$d2['id']]);
        [, $out] = $send($b);
        $ctx->eq([(int)$svc->getDraft((int)$d2['id'])['attempt'], count(qf_texts($evo)), $svc->getDraft((int)$d2['id'])['status']], [2, 1, 'sent'],
                 'U12b (iii) control: attempt 2\'s draft after attempt 1 was sent is sent', $out);
        // (iv) a draft of an earlier attempt than the follow-up has reached (sends recorded past it), no sibling of its own
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo']; $svc = new FollowUpService($p);
        $e = qf_enquiry($b, '256772507281', 'support');
        $p->prepare('UPDATE followups SET attempts = 2 WHERE id = ?')->execute([$e['fid']]);
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), $svc->getDraft($e['draft'])['status']], [0, 'expired'], 'U12b (iv) attempt 1\'s draft when two attempts are counted: expired, nothing sent', $out);
        // (v) a send whose bookkeeping stopped after its followup_sends row: the draft still approved, the attempts unchanged
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, $record); $p = $b['pdo']; $svc = new FollowUpService($p);
        $e = qf_enquiry($b, '256772507291', 'support');
        $p->prepare("INSERT INTO followup_sends (followup_id, draft_id, attempt, channel, phone, body, wa_message_id, decided_by)
                     VALUES (?, ?, 1, 'support', '256772507291', 'sent before (test)', 'QU-SENT-1', 'test')")->execute([$e['fid'], $e['draft']]);
        $ctx->eq([(int)$svc->get($e['fid'])['attempts'], $svc->getDraft($e['draft'])['status']], [0, 'approved'], 'U12b (v) control: the attempts unchanged, the draft still approved');
        [, $out] = $send($b);
        $ctx->eq([count(qf_texts($evo)), $svc->getDraft($e['draft'])['status']], [0, 'expired'], 'U12b (v) its followup_sends row stands for the send: expired, never sent twice', $out);
    });

    $ctx->run('U13 F3, F5: due() leaves out stale rows and rows with an approved or maybe-sent draft — only when asked; a pending one stays (the run skips it after its closes)', function () use ($ctx, $evo) {
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo']; $svc = new FollowUpService($p);
        $mk = function (string $ph, string $last) use ($p, $svc): int {
            $cv = qf_conv($p, $ph, 'support');
            return qu_open($p, $cv, $ph, 'support', $last);
        };
        $fresh = '2026-11-01 09:00:00';
        $r1 = $mk('256772507301', $fresh); $svc->approve((int)$svc->draft($r1, ['verdict' => 'SEND', 'message' => 'a (test)', 'reason' => 't'])['id'], 'staff:Tester');
        $r2 = $mk('256772507302', $fresh); $svc->draft($r2, ['verdict' => 'SEND', 'message' => 'b (test)', 'reason' => 't']);   // pending
        $r3 = $mk('256772507303', $fresh); $d3 = $svc->draft($r3, ['verdict' => 'SEND', 'message' => 'c (test)', 'reason' => 't']);
        $svc->approve((int)$d3['id'], 'staff:Tester'); $svc->recordUncertain($r3, (int)$d3['id'], 'test', 'test');      // maybe sent, this attempt
        $r4 = $mk('256772507304', $fresh); $d4 = $svc->draft($r4, ['verdict' => 'SEND', 'message' => 'd (test)', 'reason' => 't']);
        $svc->approve((int)$d4['id'], 'staff:Tester'); $svc->recordUncertain($r4, (int)$d4['id'], 'test', 'test');
        $p->prepare('UPDATE followups SET attempts = 1 WHERE id = ?')->execute([$r4]);                                      // an earlier attempt's
        $r5 = $mk('256772507305', $fresh); $d5 = $svc->draft($r5, ['verdict' => 'SEND', 'message' => 'e (test)', 'reason' => 't']);
        $p->prepare("UPDATE followup_drafts SET status = 'sent' WHERE id = ?")->execute([(int)$d5['id']]);
        $p->prepare('UPDATE followups SET attempts = 1 WHERE id = ?')->execute([$r5]);
        $r6 = $mk('256772507306', '2026-10-10 09:00:00');                                                                   // stale, no draft
        $p->exec("UPDATE followups SET due_at = strftime('%Y-%m-%d %H:%M:%S', '2026-11-01 00:00:00', '+' || id || ' minutes')");   // due in id order
        $ids = function (array $rows): array { $a = array_map(function ($r) { return (int)$r['id']; }, $rows); sort($a); return $a; };
        $all = [$r1, $r2, $r3, $r4, $r5, $r6];
        $ctx->eq($ids($svc->due('2026-11-02 07:00:00', 25)), $all, 'U13 control: due() as every other caller calls it returns all six');
        $ctx->eq($ids($svc->due('2026-11-02 07:00:00', 25, '2026-10-19 07:00:00')), [$r1, $r2, $r3, $r4, $r5], 'U13 the stale limit alone: the stale row left out');
        $ctx->eq($ids($svc->due('2026-11-02 07:00:00', 25, null, true)), [$r2, $r4, $r5, $r6], 'U13 waitingOut alone: approved and this attempt\'s uncertain left out; pending, an earlier attempt\'s uncertain and a sent draft kept');
        $ctx->eq($ids($svc->due('2026-11-02 07:00:00', 25, '2026-10-19 07:00:00', true)), [$r2, $r4, $r5], 'U13 both, as the run on Uganda asks');
        $ctx->eq($ids($svc->due('2026-11-02 07:00:00', 1, null, true)), [$r2], 'U13 the limit counts only the rows left in (the earliest due first)');
    });

    $ctx->run('U14 F3, F5 in the run: on Uganda a row holding an approved draft, or stale, takes no place; South Sudan\'s run is unchanged', function () use ($ctx) {
        foreach (['uganda', 'south-sudan'] as $t) {
            $b = $ctx->book(['tenant_profile' => $t, 'timezone' => QF_TZ, 'followup_enabled' => '1', 'followup_auto_send' => '1',
                             'claude_api_key' => 'qf-dummy-key-never-used', 'evo_api_url' => 'http://127.0.0.1:9'], 'u'); $p = $b['pdo'];
            $svc = new FollowUpService($p);
            // conversations that are gone: followup_run closes such a row 'cancelled' before any model call — the observable
            $x1 = qu_open($p, 990101, '256772507401', 'support', qu_ago(2));
            $svc->approve((int)$svc->draft($x1, ['verdict' => 'SEND', 'message' => 'waiting (test)', 'reason' => 't'])['id'], 'staff:Tester');
            $x2 = qu_open($p, 990102, '256772507402', 'support', qu_ago(20));
            $x3 = qu_open($p, 990103, '256772507403', 'support', qu_ago(2));
            $p->exec("UPDATE followups SET due_at = '" . qu_ago(1) . "'");
            [$rc, $out] = qf_cron($ctx, $b, 'cron/followup_run.php');
            $want = $t === 'uganda' ? [null, null, 'cancelled'] : ['cancelled', 'cancelled', 'cancelled'];
            $ctx->eq([$rc, $svc->get($x1)['close_reason'], $svc->get($x2)['close_reason'], $svc->get($x3)['close_reason']], array_merge([0], $want),
                     $t === 'uganda' ? 'U14 uganda: the row with an approved draft and the 20-day row are not reached; the control row is (cancelled)'
                                     : 'U14 south-sudan: every row reached, as before', $out);
        }
    });

    // ---------------------------------------------------------------- F3: the close job expires stale follow-ups
    $ctx->run('U15 F3: followup_close expires a stale row (row and conversation), leaves a fresh one and the quote rows, and a reply still closes replied', function () use ($ctx, $evo) {
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo']; $svc = new FollowUpService($p);
        $mk = function (string $ph, string $rowLast, string $convLast) use ($p): int {
            $cv = qf_conv($p, $ph, 'sales');
            $id = qu_open($p, $cv, $ph, 'sales', $rowLast);
            $p->prepare('UPDATE wa_conversations SET last_customer_at = ? WHERE id = ?')->execute([$convLast, $cv]);
            return $id;
        };
        $s1 = $mk('256772507501', qu_ago(20), qu_ago(20));
        $s2 = $mk('256772507502', qu_ago(20), qu_ago(2));   // the customer wrote since: CASE C, replied
        $s3 = $mk('256772507503', qu_ago(5), qu_ago(5));
        $s6 = $mk('256772507507', qu_ago(20), qu_ago(16));   // wrote again, long ago: both stale, the conversation newer — CASE C first
        $cq = qf_conv($p, '256772507504', 'support');
        $p->prepare("INSERT INTO quote_tracker (quote_key, quote_ref, phone, evidence_msg_id, evidence_event, made_by, sent_at, sent_conv_id, sent_channel, valid_until)
                     VALUES ('ref:Q-9750', 'Q-9750', '256772507504', 1, 'quote_manual', 'manual', ?, ?, 'support', '2099-12-31')")->execute([qu_ago(25), $cq]);
        $q = $svc->openQuote(['id' => $cq, 'channel' => 'support', 'phone' => '256772507504'],
                             ['quote_key' => 'ref:Q-9750', 'ref' => 'Q-9750', 'valid_until' => '2099-12-31', 'sent_at' => qu_ago(25), 'due_at' => '2099-01-01 00:00:00']);
        $p->prepare('UPDATE followups SET last_customer_at = ? WHERE id = ?')->execute([qu_ago(25), $q]);
        [$rc, $out] = qf_cron($ctx, $b, 'cron/followup_close.php');
        $d = qf_rows($p, "SELECT detail, actor FROM followup_events WHERE followup_id = ? AND event = 'closed'", [$s1]);
        $s1last = (string)$svc->get($s1)['last_customer_at'];
        $ctx->eq([$rc, $svc->get($s1)['close_reason'], $d[0]['detail'] ?? null, $d[0]['actor'] ?? null, $svc->get($s2)['close_reason'], $svc->get($s6)['close_reason'], $svc->get($s3)['closed_at'], $svc->get($q)['closed_at']],
                 [0, 'expired', 'expired — the customer last wrote at ' . $s1last . ', older than followup_max_age_hours 336 h', 'closer', 'replied', 'replied', null, null],
                 'U15 the stale row expired by the closer, its time named; the written-since rows replied (CASE C runs first, even when both copies are stale); the fresh row and the quote row open', $out);
        $ctx->t((bool)preg_match('/\[followup_close\] closed=2 expired=1$/m', $out), 'U15 the close job prints its expired count beside closed', $out);
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1', 'followup_max_age_hours' => '72']); $p = $b['pdo'];
        $cv = qf_conv($p, '256772507505', 'sales'); $s4 = qu_open($p, $cv, '256772507505', 'sales', qu_ago(4));
        $p->prepare('UPDATE wa_conversations SET last_customer_at = ? WHERE id = ?')->execute([qu_ago(4), $cv]);
        [, $out] = qf_cron($ctx, $b, 'cron/followup_close.php');
        $ctx->eq((new FollowUpService($p))->get($s4)['close_reason'], 'expired', 'U15 followup_max_age_hours=72: a 4-day silence expires (the setting is read)', $out);
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1', 'followup_max_age_hours' => 'abc']); $p = $b['pdo'];
        $cv = qf_conv($p, '256772507506', 'sales'); $s5 = qu_open($p, $cv, '256772507506', 'sales', qu_ago(40));
        $p->prepare('UPDATE wa_conversations SET last_customer_at = ? WHERE id = ?')->execute([qu_ago(40), $cv]);
        [, $out] = qf_cron($ctx, $b, 'cron/followup_close.php');
        $ctx->eq((new FollowUpService($p))->get($s5)['closed_at'], null, 'U15 a misread limit: nothing expires', $out);
    });

    $ctx->run('U16 F3: expireStale() — oldest first, its limit, a gone conversation cancelled, a conversation written since never expired, quote rows never', function () use ($ctx, $evo) {
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo']; $svc = new FollowUpService($p);
        $mk = function (string $ph, string $rowLast, ?string $convLast) use ($p): int {
            if ($convLast === null) return qu_open($p, 990200 + (int)substr($ph, -2), $ph, 'sales', $rowLast);   // a gone conversation
            $cv = qf_conv($p, $ph, 'sales');
            $id = qu_open($p, $cv, $ph, 'sales', $rowLast);
            $p->prepare('UPDATE wa_conversations SET last_customer_at = ? WHERE id = ?')->execute([$convLast, $cv]);
            return $id;
        };
        $b2 = $mk('256772507602', '2026-10-05 09:00:00', '2026-10-05 09:00:00');
        $a  = $mk('256772507601', '2026-10-01 09:00:00', '2026-10-01 09:00:00');
        $c  = $mk('256772507603', '2026-10-08 09:00:00', null);
        $dd = $mk('256772507604', '2026-10-09 09:00:00', '2026-11-01 09:00:00');   // written since
        $e  = $mk('256772507605', '2026-10-30 09:00:00', '2026-10-30 09:00:00');
        $cq = qf_conv($p, '256772507606', 'support');
        $p->exec("INSERT INTO quote_tracker (quote_key, quote_ref, phone, evidence_msg_id, evidence_event, made_by, sent_at, sent_conv_id, sent_channel, valid_until)
                  VALUES ('ref:Q-9760', 'Q-9760', '256772507606', 1, 'quote_manual', 'manual', '2026-10-01 07:00:00', {$cq}, 'support', '2099-12-31')");
        $q = $svc->openQuote(['id' => $cq, 'channel' => 'support', 'phone' => '256772507606'],
                             ['quote_key' => 'ref:Q-9760', 'ref' => 'Q-9760', 'valid_until' => '2099-12-31', 'sent_at' => '2026-10-01 07:00:00', 'due_at' => '2099-01-01 00:00:00']);
        $p->prepare("UPDATE followups SET last_customer_at = '2026-09-01 09:00:00' WHERE id = ?")->execute([$q]);
        $lim = '2026-10-19 07:00:00';
        $n1 = $svc->expireStale($lim, 2, '336', 'closer');
        $ctx->eq([$n1, $svc->get($a)['close_reason'], $svc->get($b2)['close_reason'], $svc->get($c)['closed_at']], [2, 'expired', 'expired', null],
                 'U16 limit 2: the two oldest by the customer\'s last message expired, the next left');
        $n2 = $svc->expireStale($lim, 10, '336', 'closer');
        $ctx->eq([$n2, $svc->get($c)['close_reason'], $svc->get($dd)['closed_at'], $svc->get($e)['closed_at'], $svc->get($q)['closed_at']], [1, 'cancelled', null, null, null],
                 'U16 the rest: the gone conversation cancelled; the row whose customer wrote since, the fresh row and the quote row left open');
        $ctx->eq($svc->expireStale($lim, 10, '336', 'closer'), 0, 'U16 a second pass finds nothing');
    });

    // ---------------------------------------------------------------- F4: one follow-up per quiet spell
    $ctx->run('U17 F4: the scan opens no second follow-up in a quiet spell, one second chance after a single send_failed, and a new spell opens; South Sudan unchanged', function () use ($ctx) {
        foreach (['uganda', 'south-sudan'] as $t) {
            $b = $ctx->book(['tenant_profile' => $t, 'timezone' => QF_TZ, 'followup_enabled' => '1', 'evo_api_url' => 'http://127.0.0.1:9'], 'u'); $p = $b['pdo'];
            $quiet = qu_ago(30 / 24);
            $mk = function (string $ph) use ($p, $quiet): int {
                $cv = qf_conv($p, $ph, 'sales');
                $p->prepare("UPDATE wa_conversations SET last_customer_at = ?, status = 'active' WHERE id = ?")->execute([$quiet, $cv]);
                return $cv;
            };
            $k1 = $mk('256772507701'); qu_open($p, $k1, '256772507701', 'sales', $quiet, 'expired');
            $k2 = $mk('256772507702'); qu_open($p, $k2, '256772507702', 'sales', $quiet, 'send_failed');
            $k3 = $mk('256772507703'); qu_open($p, $k3, '256772507703', 'sales', $quiet, 'send_failed'); qu_open($p, $k3, '256772507703', 'sales', $quiet, 'send_failed');
            $k4 = $mk('256772507704'); qu_open($p, $k4, '256772507704', 'sales', qu_ago(6), 'expired');   // an earlier spell
            $k5 = $mk('256772507705');                                                                     // control
            $k6 = $mk('256772507706'); qu_open($p, $k6, '256772507706', 'sales', $quiet, 'replied');
            $k7 = $mk('256772507707'); qu_open($p, $k7, '256772507707', 'sales', qu_ago(6), 'send_failed'); qu_open($p, $k7, '256772507707', 'sales', qu_ago(4), 'exhausted');   // two earlier spells
            [$rc, $out] = qf_cron($ctx, $b, 'cron/followup_scan.php');
            $opened = array_map('intval', $p->query("SELECT conversation_id FROM followups WHERE opened_by = 'scan' ORDER BY conversation_id")->fetchAll(\PDO::FETCH_COLUMN));
            $want = $t === 'uganda' ? [$k2, $k4, $k5, $k7] : [$k1, $k2, $k3, $k4, $k5, $k6, $k7];
            $ctx->eq([$rc, $opened], [0, $want], $t === 'uganda'
                     ? 'U17 uganda: closed this spell (expired, replied, two send_failed) not reopened; one send_failed reopened once; earlier spells (one, or two) and the control opened'
                     : 'U17 south-sudan: every conversation without an open follow-up opened, as before', $out);
        }
    });

    // ---------------------------------------------------------------- South Sudan: the sender unchanged
    $ctx->run('U18 South Sudan\'s sender is unchanged: a 16-day silence, three refusals with a delivery, and two drafts of one follow-up are all sent', function () use ($ctx, $evo, $send, $ss, $stale) {
        qf_fake_evo();
        $b = $ss('u'); $p = $b['pdo']; $svc = new FollowUpService($p);
        $e1 = qf_enquiry($b, '256772507801', 'support');
        $stale($p, $e1['conv'], '2026-10-17 09:00:00');
        $e2 = qf_enquiry($b, '256772507802', 'sales');
        foreach (['06:10', '06:20', '06:30'] as $hm) qu_refuse($p, $e2['fid'], '2026-11-02 ' . $hm . ':00', 'Bad Request [HTTP 400 on POST /message/sendText/Sales-UG]');
        foreach (['06:05', '06:15', '06:25'] as $hm) qu_delivered($p, '256772507809', 'sales', '2026-11-02 ' . $hm . ':00');   // each refusal after a delivery: F1's case on Uganda
        $e3 = qf_enquiry($b, '256772507803', 'account');
        $d = $svc->draft($e3['fid'], ['verdict' => 'SEND', 'message' => 'A second draft (test)', 'reason' => 'test']);
        $svc->approve((int)$d['id'], 'staff:Tester');
        [, $out] = $send($b);
        $ev = qf_rows($p, "SELECT detail FROM followup_events WHERE event = 'skipped' AND (detail LIKE 'other approved%' OR detail LIKE 'draft expired%')");
        $ctx->eq([count(qf_texts($evo)), $svc->get($e1['fid'])['close_reason'], $svc->get($e2['fid'])['close_reason'], $svc->getDraft((int)$d['id'])['status'], qu_counts($out), $ev],
                 [4, null, null, 'sent', '', []], 'U18 four sent (the stale one, the refused one, both drafts); nothing closed or expired; no 5.18.106 count or event', $out);
    });

    $ctx->run('U19 South Sudan\'s close job expires nothing', function () use ($ctx, $evo, $ss) {
        $b = $ss('u'); $p = $b['pdo'];
        $cv = qf_conv($p, '256772507901', 'sales'); $s = qu_open($p, $cv, '256772507901', 'sales', qu_ago(40));
        $p->prepare('UPDATE wa_conversations SET last_customer_at = ? WHERE id = ?')->execute([qu_ago(40), $cv]);
        [$rc, $out] = qf_cron($ctx, $b, 'cron/followup_close.php');
        $ctx->eq([$rc, (new FollowUpService($p))->get($s)['closed_at'], strpos($out, 'expired=') === false], [0, null, true], 'U19 the 40-day row left open; no expired count', $out);
    });

    // ---------------------------------------------------------------- the 5.18.106 boundary, against 7835906 from Git
    require_once dirname(__DIR__) . '/fixtures/r105_revert.php';   // R105_MARK, and the 5.18.106 layer it loads
    require_once dirname(__DIR__) . '/fixtures/r106_revert.php';
    [$B, $why] = r106_base($ctx->dir('r106-base'));
    $ctx->t($B !== null, 'U20 control: Git supplies ' . R106_BASE . ' (5.18.105) — without it nothing below is measured: a failure, never a skip', (string)$why);
    if ($B === null) return;
    $base = r106_walk($B);
    $tree = r106_walk($ctx->root);
    $ctx->t(count($base) > 1000 && isset($tree['cron/followup_send.php'], $base['cron/followup_send.php'], $base['webhook.php']),
            'U20 control: both walks see the plugin (' . count($base) . ' and ' . count($tree) . ' files)');
    $changed = []; $added = []; $removed = [];
    foreach ($tree as $rel => $p) {
        if (!isset($base[$rel])) { $added[] = $rel; continue; }
        if (hash_file('sha256', $p) !== hash_file('sha256', $base[$rel])) $changed[] = $rel;
    }
    foreach ($base as $rel => $p) if (!isset($tree[$rel])) $removed[] = $rel;

    $ctx->run('U20 exactly the declared files change or are added, none is removed; each new one is new, each changed one existed and changed', function () use ($ctx, $changed, $added, $removed, $base) {
        $moved = array_merge($changed, $added);
        $undeclared = array_values(array_filter($moved, function ($r) { return r106_declared_entry($r) === null; }));
        $kind = [];
        foreach ($moved as $r) {
            $e = r106_declared_entry($r);
            if ($e === null) continue;
            if (R106_DECLARED[$e][0] === 'new' && isset($base[$r])) $kind[] = $r . ' (declared new, but ' . R106_BASE . ' has it)';
            if (R106_DECLARED[$e][0] === 'changed' && !isset($base[$r])) $kind[] = $r . ' (declared changed, but ' . R106_BASE . ' has none)';
        }
        $still = [];
        foreach (R106_DECLARED as $p => $row) {
            if (!in_array($row[0], ['new', 'changed'], true) || trim((string)($row[1] ?? '')) === '') $kind[] = $p . ' (no kind or no reason)';
            if (substr($p, -1) !== '/' && !in_array($p, $moved, true)) $still[] = $p;
        }
        $ctx->eq([$undeclared, $removed, $kind, $still], [[], [], [], []],
                 'U20 against ' . R106_BASE . ' the tree changes or adds exactly the ' . count(R106_DECLARED) . ' declared entries (undeclared, removed, wrong kind, declared but unchanged)');
    });

    $ctx->run('U21 every hunk of every declared changed file carries the 5.18.106 marker, none 5.18.105\'s; manifest.json changes its version line only', function () use ($ctx, $B, $changed) {
        $bad = []; $n = 0;
        foreach ($changed as $rel) {
            $e = r106_declared_entry($rel);
            if ($rel === 'manifest.json' || $e === null || R106_DECLARED[$e][0] !== 'changed') continue;
            foreach (r106_hunks($B . '/' . $rel, $ctx->root . '/' . $rel) as [$os, $ol, $ns, $nl, $plus, , $marked]) {
                $n++;
                if (!$marked) $bad[] = $rel . ' +' . $ns . ',' . $nl . ' (no 5.18.106 marker in it or the 30 lines above)';
                if (preg_match(R105_MARK, implode("\n", $plus)) === 1) $bad[] = $rel . ' +' . $ns . ',' . $nl . ' (carries 5.18.105\'s marker)';
            }
        }
        $ctx->eq($bad, [], 'U21 every hunk is 5.18.106\'s by its marker');
        $ctx->t($n >= 20, 'U21 control: the hunks were read (' . $n . ')');
        $mh = array_map(function ($h) { return [$h[4], $h[5]]; }, r106_hunks($B . '/manifest.json', $ctx->root . '/manifest.json'));
        $ctx->eq($mh, [[['    "version": "5.18.106",'], ['    "version": "5.18.105",']]], 'U21 manifest.json: one hunk, its version line, 5.18.105 → 5.18.106');
    });

    $ctx->run('U22 what 5.18.106 holds is ' . R106_BASE . '\'s byte for byte', function () use ($ctx, $base, $tree) {
        $bad = []; $n = 0;
        foreach (R106_HELD as $h) {
            $rels = substr($h, -1) === '/'
                ? array_values(array_unique(array_filter(array_merge(array_keys($base), array_keys($tree)), function ($r) use ($h) { return strpos($r, $h) === 0; })))
                : [$h];
            foreach ($rels as $r) {
                $n++;
                if (!isset($base[$r], $tree[$r]) || hash_file('sha256', $base[$r]) !== hash_file('sha256', $tree[$r])) $bad[] = $r;
            }
        }
        $ctx->eq($bad, [], 'U22 every held file is ' . R106_BASE . '\'s');
        $ctx->t($n > 50, 'U22 control: ' . $n . ' files compared (the migrations among them)');
    });

    $ctx->run('U23 the layer takes exactly 5.18.106 out: r106_less() of this tree is ' . R106_BASE . '\'s, file for file, no unmarked hunk left', function () use ($ctx, $base) {
        try { [$L, $left] = r106_less_build($ctx->root, $ctx->dir('r106')); } catch (\RuntimeException $e) { $ctx->t(false, 'U23 the layer was built', $e->getMessage()); return; }
        $lw = r106_walk($L);
        $diff = [];
        foreach ($base as $r => $p) if (!isset($lw[$r]) || hash_file('sha256', $lw[$r]) !== hash_file('sha256', $p)) $diff[] = $r;
        foreach ($lw as $r => $p) if (!isset($base[$r])) $diff[] = $r . ' (not in ' . R106_BASE . ')';
        $ctx->eq([$diff, $left], [[], 0], 'U23 the judged tree with 5.18.106 taken out is ' . R106_BASE . '\'s, and no unmarked 5.18.106 hunk was left');
        $ctx->t(count($lw) === count($base) && hash_file('sha256', $ctx->root . '/cron/followup_send.php') !== hash_file('sha256', $L . '/cron/followup_send.php'),
                'U23 control: the layer changed something (the sender differs from the judged tree\'s) and kept every file (' . count($lw) . ')');
    });

    $ctx->run('U24 5.18.106\'s added runtime lines: php -l, the PHP 7.4 rules (QFU_I_RULES), and no setting 7835906 does not already read', function () use ($ctx, $B, $changed, $added, $base) {
        require_once dirname(__DIR__) . '/quote_from_ucrm/I_boundary.php';   // QFU_I_RULES
        $keyRe = '/\$(?:config|cfg|fc|lc|qc|fresh|this->config)\[\s*\'([a-z0-9_]+)\'\s*\]/';
        $runtime = array_values(array_filter(array_merge($changed, $added), function ($r) { return substr($r, -4) === '.php' && strpos($r, 'tests/') !== 0; }));
        $lint = []; $bad = []; $lines = 0; $keys = [];
        foreach ($runtime as $rel) {
            $lo = []; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($ctx->root . '/' . $rel) . ' 2>&1', $lo, $lrc);
            if ($lrc !== 0) $lint[] = $rel;
            $cb = $ctx->tmp . '/u24_base.php'; $cn = $ctx->tmp . '/u24_new.php';
            file_put_contents($cb, isset($base[$rel]) ? qf_n_code((string)file_get_contents($B . '/' . $rel)) : '');
            file_put_contents($cn, qf_n_code((string)file_get_contents($ctx->root . '/' . $rel)));
            $d = []; exec('diff ' . escapeshellarg($cb) . ' ' . escapeshellarg($cn), $d);
            foreach ($d as $l) {
                if (strpos($l, '> ') !== 0) continue;
                $l = substr($l, 2); $lines++;
                foreach (QFU_I_RULES as $what => $re) {
                    if (preg_match($re, $l) && !($what === 'a named argument' && preg_match('/\?\s*[^:]*:/', $l))) { $bad[] = "{$rel}: {$what}: " . mb_substr(trim($l), 0, 120); break; }
                }
            }
            // the settings read: on the raw added lines (the rules above read code with strings blanked, which hides the keys)
            $rb = $ctx->tmp . '/u24_rbase.php';
            file_put_contents($rb, isset($base[$rel]) ? (string)file_get_contents($B . '/' . $rel) : '');
            $rd = []; exec('diff ' . escapeshellarg($rb) . ' ' . escapeshellarg($ctx->root . '/' . $rel), $rd);
            foreach ($rd as $l) if (strpos($l, '> ') === 0 && preg_match_all($keyRe, $l, $km)) foreach ($km[1] as $k) $keys[$k] = $rel;
        }
        $known = [];
        foreach (r106_walk($B) as $rel => $abs) {
            if (substr($rel, -4) !== '.php' || strpos($rel, 'tests/') === 0) continue;
            if (preg_match_all($keyRe, (string)file_get_contents($abs), $km)) foreach ($km[1] as $k) $known[$k] = true;
        }
        $newKeys = array_values(array_filter(array_keys($keys), function ($k) use ($known) { return !isset($known[$k]); }));
        $ctx->eq([$lint, $bad, $newKeys], [[], [], []], 'U24 php -l on the ' . count($runtime) . ' runtime files; no added code line breaks a PHP 7.4 rule; every setting an added line reads, 7835906 already reads (' . implode(', ', array_keys($keys)) . ')');
        $ctx->t(count($runtime) === 5 && $lines >= 100 && isset($keys['followup_max_age_hours'], $known['followup_max_age_hours'], $known['followup_enabled']),
                'U24 control: the five runtime files and their added code lines were read (' . $lines . '), followup_max_age_hours among the keys, and 7835906\'s reads were found');
        $raw = "<?php\n\$a = str_contains(\$b, 'x'); \$c = \$config['followup_brand_new_key'];\n";
        $probe = qf_n_code($raw);
        $hit = array_keys(array_filter(QFU_I_RULES, function ($re) use ($probe) { return preg_match($re, $probe) === 1; }));
        $ctx->eq([$hit, preg_match_all($keyRe, $raw, $pm) ? $pm[1] : [], isset($known['followup_brand_new_key'])], [['str_contains('], ['followup_brand_new_key'], false],
                 'U24 control on the control: a planted str_contains( and a new key are found, and the new key is not among 7835906\'s');
    });
}];

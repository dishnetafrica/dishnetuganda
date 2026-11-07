<?php
declare(strict_types=1);
/**
 * Section H of tests/test_quote_followup.php — STOP, the kill switch and the enquiry engine (5.18.104, root docs/73
 * §H.2.10.7, §H.2.3 (b), (f), (g), §H.2.14 H; F1, R-11, R104-02, K2-22, S2-19). TEST ONLY.
 *
 * The chase off with followup_auto_send on: followup_run asks the AI nothing about a quotation (due()'s kind filter) and
 * followup_send sends no quote draft; back on, every stale draft expires before any send; followup_run completes on a 089
 * book on Uganda and South Sudan; followup_close leaves quote rows alone and still reaches the enquiry row behind 200 open
 * quote rows on a sticky human_active; sentTodayOn() counts enquiry sends only; the scan opens no enquiry beside a chased
 * quotation, and on a book whose 089 ALTER failed it runs 5.18.103's SQL. The crons run from the copy in a child process.
 * Every person and number is fictitious.
 */

$QF_SECTIONS['H'] = ['title' => 'STOP, the kill switch and the enquiry engine (§H.2.10.7)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'QuoteChase', 'FollowUpService'] as $l) require_once $tree . '/lib/' . $l . '.php';
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'H00 control: the fake Evolution answers');
    if ($evo === '') return;

    $ctx->run('H01 the chase off, followup_auto_send on: followup_send sends no quote draft; the enquiry pass is unchanged (QM10)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1', 'followup_auto_send' => '1', 'quote_chase_enabled' => '0']); $p = $b['pdo'];
        $c = qf_chase($b, '256772502501', 'support', 'Q-9501');
        $e = qf_enquiry($b, '256772502502', 'sales');
        [, $out] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()]);
        $tx = qf_texts($evo);
        $ctx->eq([count($tx), $tx[0]['text'] ?? null, (new FollowUpService($p))->getDraft($c['draft'])['status']], [1, $e['body'], 'approved'],
                 'H01 the enquiry sent (control); the quote draft left approved and inert', $out);
    });

    $ctx->run('H02 due() and approvedDrafts() never return a quote row; the enquiry\'s still (F1, R-11; QM14, QM15, QM47)', function () use ($ctx, $evo) {
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo']; $svc = new FollowUpService($p);
        $c = qf_chase($b, '256772502511', 'support', 'Q-9511');
        $e = qf_enquiry($b, '256772502512', 'sales');
        $p->exec("UPDATE followups SET due_at = '2026-11-01 00:00:00'");
        $due = array_map(function ($r) { return (int)$r['id']; }, $svc->due('2026-11-02 07:00:00', 25));
        $ap = array_map(function ($r) { return (int)$r['followup_id']; }, $svc->approvedDrafts(10));
        $ctx->eq([$due, $ap, $svc->hasKind()], [[$e['fid']], [$e['fid']], true], 'H02 due() and approvedDrafts(): the enquiry row alone');
        $ctx->eq(array_map(function ($r) { return (int)$r['followup_id']; }, $svc->approvedQuoteDrafts(50)), [$c['fid']], 'H02 the quote pass\'s own list: the quote draft');
    });

    $ctx->run('H03 followup_run completes on a 089 book, Uganda and South Sudan, and asks nothing about a quotation (R-11; QM14, QM47)', function () use ($ctx, $evo) {
        foreach (['uganda', 'south-sudan'] as $t) {
            $b = $ctx->book(['tenant_profile' => $t, 'timezone' => QF_TZ, 'followup_enabled' => '1', 'followup_auto_send' => '1',
                             'claude_api_key' => 'qf-dummy-key-never-used', 'evo_api_url' => 'http://127.0.0.1:9'], 'h'); $p = $b['pdo'];
            $svc = new FollowUpService($p);
            // two follow-ups whose conversations are gone: followup_run closes such a row 'cancelled' before any model call
            $e = $svc->open(['id' => 990001, 'channel' => 'support', 'phone' => '256772502521', 'last_customer_at' => '2026-10-30 09:00:00']);
            $q = 0;
            if ($svc->hasKind()) {
                $p->exec("INSERT INTO quote_tracker (quote_key, quote_ref, phone, evidence_msg_id, evidence_event, made_by, sent_at, sent_conv_id, sent_channel, valid_until)
                          VALUES ('ref:Q-9521', 'Q-9521', '256772502522', 1, 'quote_manual', 'manual', '2026-10-30 07:00:00', 990002, 'support', '2099-12-31')");
                $q = $svc->openQuote(['id' => 990002, 'channel' => 'support', 'phone' => '256772502522'],
                                     ['quote_key' => 'ref:Q-9521', 'ref' => 'Q-9521', 'valid_until' => '2099-12-31', 'sent_at' => '2026-10-30 07:00:00', 'due_at' => '2026-10-31 07:00:00']);
            }
            $p->exec("UPDATE followups SET due_at = '2026-10-31 07:00:00'");
            [$rc, $out] = qf_cron($ctx, $b, 'cron/followup_run.php');
            $quoteEvents = $q > 0 ? array_column(qf_rows($p, 'SELECT event FROM followup_events WHERE followup_id = ? ORDER BY id', [$q]), 'event') : [];
            $ctx->eq([$rc, $svc->get((int)$e['id'])['close_reason'], $q > 0 ? $svc->get($q)['closed_at'] : null, $quoteEvents],
                     [0, 'cancelled', null, $q > 0 ? ['created'] : []],
                     "H03 {$t}: the run completes and reaches the enquiry row (closed cancelled, the control); the quote row untouched", $out);
        }
    });

    $ctx->run('H04 back on: every stale draft expires before any send (K2-21; QM20)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_gbook($ctx, $evo); $p = $b['pdo'];
        $c = qf_chase($b, '256772502531', 'support', 'Q-9531', ['decided' => '2026-10-28 07:00:00']);
        [, $out] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()]);
        $ctx->eq([count(qf_texts($evo)), (new FollowUpService($p))->getDraft($c['draft'])['status']], [0, 'expired'],
                 'H04 the sender, run first: the draft approved days ago expires, never sent', $out);
        $c2 = qf_chase($b, '256772502532', 'sales', 'Q-9532', ['decided' => '2026-10-28 07:00:00']);
        $n = (new FollowUpService($p))->expireQuoteDrafts('2026-11-02');
        $ctx->eq([$n, (new FollowUpService($p))->getDraft($c2['draft'])['status']], [1, 'expired'], 'H04 quote_chase\'s first act, expireQuoteDrafts(today): the same');
    });

    $ctx->run('H05 followup_close leaves quote rows alone and reaches the enquiry row behind 200 open quote rows (R104-02; QM43, QM63)', function () use ($ctx, $evo) {
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo']; $svc = new FollowUpService($p);
        $p->exec('BEGIN');
        for ($i = 0; $i < 201; $i++) {
            $ph = '2567726' . sprintf('%05d', $i);
            $cv = qf_conv($p, $ph, 'support', null, 'human_active');
            $k = 'ref:Q-H' . $i;
            $p->prepare("INSERT INTO quote_tracker (quote_key, quote_ref, phone, evidence_msg_id, evidence_event, made_by, sent_at, sent_conv_id, sent_channel, valid_until)
                         VALUES (?, ?, ?, 1, 'quote_manual', 'manual', '2026-10-30 07:00:00', ?, 'support', '2099-12-31')")->execute([$k, substr($k, 4), $ph, $cv]);
            $svc->openQuote(['id' => $cv, 'channel' => 'support', 'phone' => $ph],
                            ['quote_key' => $k, 'ref' => substr($k, 4), 'valid_until' => '2099-12-31', 'sent_at' => '2026-10-30 07:00:00', 'due_at' => '2000-01-01 00:00:00']);
        }
        $p->exec('COMMIT');
        $ec = qf_conv($p, '256772502541', 'sales');
        $p->prepare("UPDATE wa_conversations SET last_customer_at = '2026-10-30 09:00:00' WHERE id = ?")->execute([$ec]);
        $e = $svc->open(['id' => $ec, 'channel' => 'sales', 'phone' => '256772502541', 'last_customer_at' => '2026-10-30 09:00:00']);
        $p->exec("UPDATE followups SET due_at = '2099-01-01 00:00:00' WHERE id = " . (int)$e['id']);
        $p->prepare("UPDATE wa_conversations SET last_customer_at = '2026-11-02 05:00:00' WHERE id = ?")->execute([$ec]);   // the customer wrote again
        [$rc, $out] = qf_cron($ctx, $b, 'cron/followup_close.php');
        $openQuotes = (int)$p->query("SELECT COUNT(*) FROM followups WHERE kind = 'quote' AND closed_at IS NULL")->fetchColumn();
        $ctx->eq([$rc, $openQuotes, $svc->get((int)$e['id'])['close_reason']], [0, 201, 'replied'],
                 'H05 201 quote rows on a sticky human_active stay open; the enquiry row behind them closed replied', $out);
    });

    $ctx->run('H06 sentTodayOn() counts enquiry sends only (K2-22; QM62)', function () use ($ctx, $evo) {
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo']; $svc = new FollowUpService($p);
        $c = qf_chase($b, '256772502551', 'support', 'Q-9551');
        $e = qf_enquiry($b, '256772502552', 'support');
        $ins = $p->prepare("INSERT INTO followup_sends (followup_id, attempt, channel, phone, body, decided_by, sent_at) VALUES (?, 1, 'support', ?, 'x (test)', 'test', '2026-11-02 06:00:00')");
        $ins->execute([$c['fid'], '256772502551']); $ins->execute([$c['fid'], '256772502551']); $ins->execute([$e['fid'], '256772502552']);
        $ctx->eq($svc->sentTodayOn('support', '2026-11-02 08:00:00'), 1, 'H06 two chases and one enquiry sent on support today: 1');
    });

    $ctx->run('H07 the scan opens no enquiry beside a chased quotation; on a book without 089\'s column it runs 5.18.103\'s SQL (R-06, S2-19; QM12, QM60)', function () use ($ctx, $evo) {
        $now = time();
        $quiet = gmdate('Y-m-d H:i:s', $now - 30 * 3600);
        foreach (['089', 'no_column'] as $shape) {
            $b = $ctx->book(['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'followup_enabled' => '1', 'evo_api_url' => 'http://127.0.0.1:9'], 'h'); $p = $b['pdo'];
            $svc = new FollowUpService($p);
            $mk = function (string $ph, string $ch) use ($p, $quiet): int {
                $cv = qf_conv($p, $ph, $ch);
                $p->prepare("UPDATE wa_conversations SET last_customer_at = ?, status = 'active' WHERE id = ?")->execute([$quiet, $cv]);
                return $cv;
            };
            $chased = $mk('256772502561', 'sales');
            $plain = $mk('256772502562', 'sales');
            if ($shape === '089') {
                $cq = qf_conv($p, '256772502561', 'support');
                $p->exec("INSERT INTO quote_tracker (quote_key, quote_ref, phone, evidence_msg_id, evidence_event, made_by, sent_at, sent_conv_id, sent_channel, valid_until)
                          VALUES ('ref:Q-9561', 'Q-9561', '256772502561', 1, 'quote_manual', 'manual', '2026-10-30 07:00:00', {$cq}, 'support', '2099-12-31')");
                $svc->openQuote(['id' => $cq, 'channel' => 'support', 'phone' => '256772502561'],
                                ['quote_key' => 'ref:Q-9561', 'ref' => 'Q-9561', 'valid_until' => '2099-12-31', 'sent_at' => '2026-10-30 07:00:00', 'due_at' => '2099-01-01 00:00:00']);
            } else {
                // a book whose 089 ALTER was lost (marked applied, never retried): the two columns removed. SQLite checks the whole
                // schema on DROP COLUMN, and an older view of this book names a column it no longer has, so the views go first.
                foreach ($p->query("SELECT name FROM sqlite_master WHERE type = 'view'")->fetchAll(\PDO::FETCH_COLUMN) as $v) $p->exec('DROP VIEW [' . $v . ']');
                $p->exec('DROP INDEX idx_fu_quote');
                $p->exec('ALTER TABLE followups DROP COLUMN quote_key');
                $p->exec('ALTER TABLE followups DROP COLUMN kind');
            }
            [$rc, $out] = qf_cron($ctx, $b, 'cron/followup_scan.php');
            $opened = array_map('intval', $p->query("SELECT conversation_id FROM followups WHERE opened_by = 'scan' ORDER BY conversation_id")->fetchAll(\PDO::FETCH_COLUMN));
            $ctx->eq([$rc, $opened], [0, $shape === '089' ? [$plain] : [$chased, $plain]],
                     $shape === '089' ? 'H07 the chased phone\'s other conversation gets no enquiry follow-up; the control phone does'
                                      : 'H07 without the column (hasKind() false): 5.18.103\'s SQL, both opened', $out);
        }
    });

    $ctx->run('H08 row 6\'s off-hook, its rollback form only: every open quote row closed rollback, its drafts expired, 0 left (§H.2.16 item 2)', function () use ($ctx, $evo) {
        $b = qf_gbook($ctx, $evo, ['followup_enabled' => '1']); $p = $b['pdo']; $svc = new FollowUpService($p);
        $c1 = qf_chase($b, '256772502581', 'support', 'Q-9581');
        $c2 = qf_chase($b, '256772502582', 'sales', 'Q-9582');
        $e = qf_enquiry($b, '256772502583', 'sales');
        $plain = QuoteChase::offHook($p, $b['cfg'], $b['data'], 'chase', 'switch');
        $ctx->eq([$plain, (int)$p->query("SELECT COUNT(*) FROM followups WHERE kind = 'quote' AND closed_at IS NULL")->fetchColumn()], [[], 2],
                 'H08 control: a plain off chase runs no hook and changes nothing');
        $r = QuoteChase::offHook($p, $b['cfg'], $b['data'], 'chase', 'rollback');
        $ctx->eq([$r, $svc->get($c1['fid'])['close_reason'], $svc->getDraft($c1['draft'])['status'], $svc->getDraft($c2['draft'])['status'],
                  $svc->get($e['fid'])['closed_at'], $svc->getDraft($e['draft'])['status']],
                 [['closed' => 2, 'expired' => 2, 'remaining' => 0], 'rollback', 'expired', 'expired', null, 'approved'],
                 'H08 the rollback: both chases closed rollback, their drafts expired, the enquiry row untouched');
        $ctx->eq(QuoteChase::offHook($p, $b['cfg'], $b['data'], 'cold', 'rollback'), [], 'H08 step 10 has no hook of its own');
    });
}];

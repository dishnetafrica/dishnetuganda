<?php
declare(strict_types=1);
/**
 * Section TL's quote-pass lock row, through the real senders (5.18.104, root docs/73 §H.2.12.4, §H.2.14 TL tool, TL32,
 * TL33, §H.2.16 item 2, §H.2.18 IO-8; B11-02), B9-fix1. Called at the end of TL (tests/sales_followup_tool/TL_tool.php).
 * TEST ONLY; every number and message is fictitious; nothing leaves 127.0.0.1.
 *
 *   TL32 (held pass)  a quote pass of the real cron/followup_send.php held inside its loop by the fake Evolution while
 *                     `off all --rollback` runs: the tool waits; row 6's hook runs after the pass; nothing is claimed or
 *                     sent after the hook (0 open quote follow-ups; every `sent` log row before the hook's `closed` rows)
 *   TL32 (read before) a pass that read its configuration before `off chase` and reaches its lock after it: it reads its
 *                     switches again there and claims nothing
 *   TL33 (held run)   OfferSender::runOnce held by the fake Evolution during `off sending`: the tool waits for the sender
 *                     lock, and no offer is claimed after `off sending` returns
 */
foreach (['B_evidence', 'D_policy', 'F_stops', 'G_sender'] as $qfF) require_once dirname(__DIR__) . '/quote_followup/' . $qfF . '.php';   // qf_gbook, qf_chase, qf_cron
unset($qfF);
require_once dirname(__DIR__) . '/wa_offers/_lib.php';
require_once dirname(__DIR__) . '/wa_offers/checks_lib.php';

/** Run the judged tree's tool in the background: a function that waits for it and returns [rc, output, seconds]; and its handle. */
function sft_run_bg(QfCtx $ctx, array $b, array $args, array $env = []): array
{
    $cmd = array_merge(['php', $ctx->tree() . '/tools/sales_followup.php'], $args);
    $e = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $e = $env + ['DN_DATA_DIR' => $b['data']] + $e;
    $log = $b['data'] . '/tool-' . bin2hex(random_bytes(3)) . '.log';
    $t0 = microtime(true);
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, null, $e);
    $wait = function () use ($p, $log, $t0): array {
        $rc = proc_close($p);
        return [$rc, (string)@file_get_contents($log), microtime(true) - $t0];
    };
    return [$wait, $p];
}

/** Whether a background process is still running. */
function sft_running($p): bool
{
    $s = proc_get_status($p);
    return (bool)$s['running'];
}

/** Wait (at most $s seconds) for a file to appear. */
function sft_wait_file(string $f, float $s = 15.0): bool
{
    for ($i = 0, $n = (int)($s * 20); $i < $n && !is_file($f); $i++) usleep(50000);
    return is_file($f);
}

function sft_tl_locks(QfCtx $ctx): void
{
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'QuoteChase', 'ConversationService', 'HumanTakeover', 'FollowUpService'] as $l) require_once $ctx->tree() . '/lib/' . $l . '.php';

    $ctx->run('TL32 (held pass) a quote pass held inside its loop during off all --rollback: the tool waits, row 6\'s hook runs after the pass, nothing claimed or sent after it', function () use ($ctx) {
        $evo = qf_fake_evo();
        $ctx->t($evo !== '', 'TL32 control: the fake Evolution is up');
        if ($evo === '') return;
        $b = qf_gbook($ctx, $evo, [], 'tl32h'); $p = $b['pdo'];
        $c1 = qf_chase($b, '256772504101', 'support', 'Q-TL32A');
        $c2 = qf_chase($b, '256772504102', 'sales', 'Q-TL32B');
        $hold = $ctx->dir('hold');
        qf_http('GET', $evo . '/__test/hold?dir=' . urlencode($hold));
        $cron = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow()], null, true);
        $held = sft_wait_file($hold . '/held');
        [$tool, $tp] = sft_run_bg($ctx, $b, ['off', 'all', '--rollback']);
        usleep(2500000);
        $waiting = sft_running($tp);
        touch($hold . '/release');
        [, $cout] = $cron();
        [$rc, $out, $secs] = $tool();
        $ctx->eq([$held, $waiting], [true, true], 'TL32 control: a chase\'s send held inside the quote pass, and the tool still running 2.5 s later (waiting for the lock)', $out . $cout);
        $tx = qf_texts($evo);
        $ctx->eq([$rc, strpos($out, 'rollback: every 5.18.104 key is unset') !== false, count($tx), $secs >= 2.5], [0, true, 2, true],
                 'TL32 the pass held: both chases of the pass sent, the tool waited for it and then rolled back clean', $out . $cout);
        $open = (int)$p->query("SELECT COUNT(*) FROM followups WHERE kind = 'quote' AND closed_at IS NULL")->fetchColumn();
        $drafts = qf_rows($p, 'SELECT status FROM followup_drafts WHERE id IN (?, ?) ORDER BY id', [$c1['draft'], $c2['draft']]);
        $ctx->eq([$open, array_column($drafts, 'status'), strpos($out, 'hook chase: closed=2') !== false], [0, ['sent', 'sent'], true],
                 'TL32 row 6\'s hook ran after the pass: 0 open quote follow-ups (both closed by it), both drafts sent before it', $out);
        $sent = array_map('intval', array_column(qf_rows($p, "SELECT id FROM followup_events WHERE event = 'sent'"), 'id'));
        $closed = array_map('intval', array_column(qf_rows($p, "SELECT id FROM followup_events WHERE event = 'closed' AND detail LIKE 'rollback%'"), 'id'));
        $ctx->eq([count($sent), count($closed), $sent !== [] && $closed !== [] && max($sent) < min($closed)], [2, 2, true],
                 'TL32 every send of the pass is logged before the hook\'s first close: nothing sent after the hook');
        $claims = qf_rows($p, "SELECT outcome FROM wa_auto_sends WHERE kind = 'quote'");
        $ctx->eq(array_column($claims, 'outcome'), ['sent', 'sent'], 'TL32 the ledger: the two claims of the pass, both settled sent; none after the hook');
    });

    $ctx->run('TL32 (read before) a pass that read its configuration before off chase and reaches its lock after: it reads its switches again and claims nothing', function () use ($ctx) {
        $evo = qf_fake_evo();
        if ($evo === '') { $ctx->t(false, 'TL32 control: the fake Evolution is up'); return; }
        foreach (['control' => false, 'off chase between' => true] as $what => $off) {
            qf_fake_evo();
            $b = qf_gbook($ctx, $evo, [], 'tl32r'); $p = $b['pdo'];
            $c = qf_chase($b, '256772504201', 'support', 'Q-TL32R');
            $pause = $ctx->dir('pause');
            $cron = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => qf_gnow(), '_qf_pause_after_config' => $pause], null, true);
            $read = sft_wait_file($pause . '/read');
            $out = '';
            if ($off) {
                [$rc, $out] = sft_run($ctx, $b, ['off', 'chase']);
                $ctx->eq([$read, $rc, isset(sft_kyc($b)['quote_chase_enabled'])], [true, 0, false], 'TL32 control: the pass paused after its configuration read; off chase returned, the switch off', $out);
            }
            touch($pause . '/go');
            [, $cout] = $cron();
            $tx = qf_texts($evo);
            $n = (int)$p->query("SELECT COUNT(*) FROM wa_auto_sends")->fetchColumn();
            $st = (new FollowUpService($p))->getDraft($c['draft'])['status'];
            if (!$off) {
                $ctx->eq([$read, count($tx), $n, $st], [true, 1, 1, 'sent'], 'TL32 control: the same paused pass with nothing switched off claims and sends the chase', $cout);
            } else {
                $ctx->eq([count($tx), $n, $st, strpos($cout, 'quote_switch_off=1') !== false], [0, 0, 'approved', true],
                         'TL32 the pass begun before off chase: holding its lock it reads the files again, finds the chase off, claims and sends nothing', $out . $cout);
            }
        }
    });

    $ctx->run('TL33 (held run) OfferSender::runOnce held by the fake Evolution during off sending: the tool waits for the sender lock, nothing claimed after it returns', function () use ($ctx) {
        $evo = qf_fake_evo();
        if ($evo === '') { $ctx->t(false, 'TL33 control: the fake Evolution is up'); return; }
        $T0 = wo_t0();
        $b = wo_book($ctx, wo_cfg(['evo_api_url' => $evo]), 'tl33');
        wo_admins($b);
        wo_warm($b, '256700943301', $T0, 86400);
        wo_warm($b, '256700943302', $T0, 90000, 'account');
        [$id, $st] = wo_started($b, $T0 - 60);
        $ctx->t(!empty($st['ok']), 'TL33 control: an offer started with a recipient on each of two handsets');
        $hold = $ctx->dir('hold');
        qf_http('GET', $evo . '/__test/hold?dir=' . urlencode($hold));
        $log = $b['data'] . '/offers-run.log';
        $sp = proc_open(['php', __DIR__ . '/run_offers.php', $ctx->tree(), $b['data'], (string)$T0], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
        $held = sft_wait_file($hold . '/held');
        $claimsAt = function () use ($b): int { return (int)$b['pdo']->query("SELECT COUNT(*) FROM wa_auto_sends WHERE kind = 'offer'")->fetchColumn(); };
        [$tool, $tp] = sft_run_bg($ctx, $b, ['off', 'sending']);
        $early = null;
        for ($i = 0; $i < 50; $i++) { if (!sft_running($tp)) { $early = $claimsAt(); break; } usleep(50000); }
        touch($hold . '/release');
        proc_close($sp);
        [$rc, $out, $secs] = $tool();
        $atReturn = $early ?? $claimsAt();
        $after = $claimsAt();
        $ctx->eq([$held, $early === null], [true, true], 'TL33 control: the sender\'s first send held, the tool still running while it was (waiting for the sender lock)', $out);
        $ctx->eq([$rc, isset(sft_kyc($b)['wa_send_ledger']), $after, $after - $atReturn], [0, false, 2, 0],
                 'TL33 off sending: the ledger cleared once the run returned; its two claims made before the tool returned, none after', $out . (string)@file_get_contents($log));
        $r = json_decode(trim((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/run_offers.php') . ' ' . escapeshellarg($ctx->tree()) . ' '
                                                 . escapeshellarg($b['data']) . ' ' . (int)($T0 + 600) . ' 2>&1')), true);
        $ctx->eq([(string)($r['code'] ?? '?'), $claimsAt()], ['ledger_off', 2], 'TL33 a run after off sending reads the files under its lock: ledger_off, nothing claimed');
    });
}

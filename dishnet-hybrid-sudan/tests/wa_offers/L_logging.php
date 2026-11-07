<?php
declare(strict_types=1);
/**
 * Section L — logging (5.18.104, root docs/73 §H.4.16 L). TEST ONLY.
 *
 * What the engine writes where a person could read it — the job's stdout (the run line) and the plugin log — carries
 * counts and fixed codes only: no run of nine or more digits, no fixture name (no person's or offer's name), and no
 * wording. Each check has its positive control: the line it reads exists. The screen's and the switch tool's output (B,
 * C) are stages B8 and B9's.
 */

/** The fixture names and wording fragments the engine must never write out. */
const WO_L_NEEDLES = ['Sandbox Admin', 'Sandbox Colleague', 'WO Test', 'November promotion', 'fictitious', 'wording'];

/** The lines of $file written after byte $from. */
function wo_lines_since(string $file, int $from): array
{
    clearstatcache();
    if (!is_file($file)) return [];
    $s = (string)file_get_contents($file, false, null, $from);
    return array_values(array_filter(explode("\n", $s), function ($l) { return trim($l) !== ''; }));
}

/** [line, why] for each line carrying a long digit run, a fixture name or a wording fragment. */
function wo_leaks(array $lines): array
{
    $out = [];
    foreach ($lines as $l) {
        if (preg_match('/\d{9,}/', $l)) { $out[] = [$l, 'digits']; continue; }
        foreach (WO_L_NEEDLES as $n) if (stripos($l, $n) !== false) { $out[] = [$l, $n]; continue 2; }
    }
    return $out;
}

$WO_SECTIONS['L'] = ['title' => 'Logging: counts and fixed codes only (§H.4.16 L)', 'run' => function (QfCtx $ctx) {
    $T0 = wo_t0();

    $ctx->run('L01 the job\'s run line on stdout, and its plugin-log line: counts and a fixed code, no phone, name or wording', function () use ($ctx, $T0) {
        if (!function_exists('wo_cron')) require_once __DIR__ . '/I_job.php';
        $now = time();
        $b = wo_book($ctx, wo_cfg(['offers_live_since' => UtcClock::stamp($now - 20 * 86400)]), 'l');
        wo_warm($b, '256700950101', $now, 86400);
        wo_warm($b, '256700950102', $now, 90000, 'support');
        [$id, $st] = wo_started($b, $now - 3600, new WoGateway(new WoLog()));
        $ctx->t($st['ok'], 'L01 control: an offer started, its recipients queued');
        if (!is_dir(wo_itree($ctx) . '/data')) mkdir(wo_itree($ctx) . '/data', 0777, true);
        $log = wo_itree($ctx) . '/data/plugin.log';
        $from = is_file($log) ? (int)filesize($log) : 0;
        $fp = fopen($b['data'] . '/offers_send.lock', 'c');
        flock($fp, LOCK_EX);                                                // another run holds the lock: the job answers busy
        [$out, $rc] = wo_cron($ctx, $b['data'], $b['cfg'], null);
        flock($fp, LOCK_UN); fclose($fp);
        $lines = array_values(array_filter(explode("\n", $out), function ($l) { return trim($l) !== ''; }));
        $ctx->eq([$rc, count($lines), (bool)preg_match('/ code=busy$/', $lines[0] ?? '')], [0, 1, true], 'L01 control: one run line, code=busy');
        $ctx->t((bool)preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d \[offers_send\] sent=\d+ uncertain=\d+ failed=\d+ skipped=\d+ deferred=\d+ handsets=\d+ code=[a-z_]+$/', $lines[0] ?? ''),
                'L01 the run line is the fixed format, counts and a code: ' . ($lines[0] ?? ''));
        $ctx->eq(wo_leaks($lines), [], 'L01 the job\'s stdout: no run of nine digits, no fixture name, no wording');
        $pl = wo_lines_since($log, $from);
        $ctx->eq([count($pl), (bool)preg_match('/\[offers_send\] busy$/', $pl[0] ?? '')], [1, true], 'L01 control: the plugin log has the job\'s one line, busy');
        $ctx->eq(wo_leaks($pl), [], 'L01 … and it carries no run of nine digits, no fixture name, no wording');
        unset($id);
    });

    $ctx->run('L02 the plugin log through sends, a failed send, a held run and a late settle: fixed codes only', function () use ($ctx, $T0) {
        if (!is_dir($ctx->tree() . '/data')) mkdir($ctx->tree() . '/data', 0777, true);
        $log = $ctx->tree() . '/data/plugin.log';
        $from = is_file($log) ? (int)filesize($log) : 0;
        [$b, $id, $glog, , $gw, $clk] = wo_fx($ctx, ['256700950201' => 'sales', '256700950202' => 'support', '256700950203' => 'sales']);   // the third waits: the offer stays running
        $gw->results = [new OfferSendResult(OfferSendResult::NOT_SENT, 'send_failed'), new OfferSendResult(OfferSendResult::SENT, '', 'WO-L02-1', false, 'claimed')];
        ob_start();
        $r1 = wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => qf_t('2026-11-01 09:05:00'), 'duration_ms' => 1000]]);
        $clk->t = $T0 + 600;
        $r2 = wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        $claim = (int)$b['pdo']->query("SELECT id FROM wa_auto_sends WHERE kind = 'offer' AND outcome = 'sent'")->fetchColumn();
        wo_ledger_of($b, $clk)->settle($claim, 'sent', ['wa_message_id' => 'WO-L02-1']);   // settled already: Part F's late_settle line
        $printed = ob_get_clean();
        $ctx->eq([$r1['sent'], $r1['failed'], $r2['code'], $claim > 0], [1, 1, 'reminders_pending', true], 'L02 control: one sent, one failed, then a run held for the reminders');
        $ctx->eq($printed, '', 'L02 the sender and the ledger print nothing to stdout');
        $pl = wo_lines_since($log, $from);
        $has = function (string $re) use ($pl): bool { foreach ($pl as $l) if (preg_match($re, $l)) return true; return false; };
        $ctx->eq([$has('/\[offers_send\] reminders_pending$/'), $has('/\[wa_send_ledger\] late_settle$/')], [true, true], 'L02 control: the held run\'s line and the late settle\'s line are in the plugin log');
        $ctx->eq(wo_leaks($pl), [], 'L02 no plugin-log line carries a run of nine digits, a fixture name or a wording');
        $bad = array_values(array_filter($pl, function ($l) { return strpos($l, '[offers_send] ') !== false && !preg_match('/\[offers_send\] [a-z_]+$/', $l); }));
        $ctx->eq($bad, [], 'L02 every offers_send line is one fixed code');
        unset($id, $glog);
    });
}];

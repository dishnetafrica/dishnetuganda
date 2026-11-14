<?php
declare(strict_types=1);
/**
 * Section B of tests/test_log_mask.php — 5.18.105 (root docs/74 §H.3.4 B, §H.1.2): South Sudan. TEST ONLY.
 *
 * 5.18.105 gates nothing by tenant, so the same lines are masked on South Sudan; D7 proves that log text is all that
 * moves. The one runtime differential left is cron/staff_ssp_report.php: it runs on South Sudan, no 5.18.104
 * differential covers it, and 5.18.105 changes its log line (LN-02). The job is run twice on the same South Sudan book
 * (SSP bookable, one field staff member with an SSP advance, the repository's fake Evolution): once on the judged tree,
 * once on 994d660's tree from Git (r105_base). Every output but the log line must be equal:
 *
 *   B01  the sends (instance, number, text), the Message Log rows (sender, event, phone, preview, success, http_code,
 *        error), and the job's stdout; the clock's minute (the message's "HH:MM EAT") is the one thing normalised;
 *   B02  PHP's log: 994d660's "Sent to" line with its " (<number>)" removed is the judged line, and every other line is
 *        equal; the control — 994d660's line carried the fixture number, so the differential has something to see.
 *
 * Every person and number is fictitious (+256700000102, as G02).
 */

/** A run's outputs with the clock's minute normalised: what B compares. */
function lm_b_norm($v)
{
    if (is_array($v)) return array_map('lm_b_norm', $v);
    return is_string($v) ? (string)preg_replace('/\b\d{1,2}:\d{2}(?::\d{2})?\b/', 'HH:MM', $v) : $v;
}

$LM_SECTIONS['B'] = ['title' => 'South Sudan: cron/staff_ssp_report.php equals 994d660\'s but for its log line (§H.3.4 B)', 'run' => function (QfCtx $ctx) {
    $runs = null;
    $get = function () use ($ctx, &$runs): array {
        if ($runs !== null) return $runs;
        [$base, $why] = r105_base($ctx->dir('r105-base'));
        if ($base === null) throw new \RuntimeException('994d660 from Git: ' . $why);
        $runs = ['new' => lm_ssp_run($ctx, 'b-new', 'USD,SSP'), 'old' => lm_ssp_run($ctx, 'b-old', 'USD,SSP', $base)];
        return $runs;
    };
    $ctx->run('B01 the sends, the rows and stdout of 994d660 and of the judged tree are equal', function () use ($ctx, $get) {
        ['new' => $n, 'old' => $o] = $get();
        $ctx->t(count($o['texts']) === 1 && strpos((string)($o['texts'][0]['text'] ?? ''), 'Daily Cash Report') !== false,
            'B01 control: on 994d660 the job sent its one report (the differential has a send to compare)', json_encode($o['texts']));
        $ctx->t($o['rows'] !== [], 'B01 control: on 994d660 the job wrote its Message Log row', $o['out']);
        $ctx->eq(lm_b_norm($n['texts']), lm_b_norm($o['texts']), 'B01 the sends (instance, number, text) are 994d660\'s');
        $ctx->eq(lm_b_norm($n['rows']), lm_b_norm($o['rows']), 'B01 the Message Log rows are 994d660\'s');
        $ctx->eq(lm_b_norm(trim($n['out'])), lm_b_norm(trim($o['out'])), 'B01 the job\'s stdout is 994d660\'s');
        $ctx->t(strpos($n['out'], 'Done. Sent: 1 | Skipped: 0') !== false, 'B01 control: the judged run reports "Done. Sent: 1 | Skipped: 0"', $n['out']);
    });
    $ctx->run('B02 PHP\'s log of 994d660 and of the judged tree differ only by the number in the "Sent to" line', function () use ($ctx, $get) {
        ['new' => $n, 'old' => $o] = $get();
        $sent = function (array $lines): array {
            return array_values(array_filter($lines, function ($l) { return strpos($l, '[staff_ssp_report] Sent to ') !== false; }));
        };
        $os = $sent($o['log']);
        $ctx->t(count($os) === 1 && lm_number_hits($os[0], [lm_num(2)]) !== [], 'B02 control: 994d660\'s "Sent to" line carries the fixture number',
            implode(' | ', $o['log']));
        $old = array_map(function ($l) {
            return strpos($l, '[staff_ssp_report] Sent to ') === false ? $l : (string)preg_replace('/ \(\+?\d+\)/', '', $l, 1);
        }, $o['log']);
        $ctx->eq(lm_b_norm($n['log']), lm_b_norm($old), 'B02 the judged log is 994d660\'s with " (<number>)" taken out of the "Sent to" line, and nothing else');
        $ctx->eq(count($sent($n['log'])), 1, 'B02 control: the judged run wrote its one "Sent to" line');
    });
}];

<?php
declare(strict_types=1);
/**
 * test_master_104.php — 5.18.104 (root docs/73 §H.2.13, §H.2.14 MS; ruling R1): cron/master.php's one coordinated change
 * and tools/cron_status.php's, read and run.
 *
 *   A  the lines, read as text (as tests/test_cron_keepalive_order.php reads them): ruling R1's four lines last in
 *      $_m_jobs, after notify_retry, in the order quote_chase, quote_daily_list, ai_media, offers_send, each equal byte for
 *      byte to ruling R1's line (key order interval, uganda, flag, script), intervals 540, 240, 60 and 240; the require of
 *      StaffJobsGate and the two dispatch lines present; starlink_alive still first.
 *   B  dispatch, run: the judged tree's own cron/master.php in a copy whose every job script is a stub that records it
 *      ran — Uganda with every flag off (the four neither run nor get a schedule row), Uganda with one flag on (that job
 *      alone of the four), South Sudan with every flag on (none of the four, no row: the gate, not the switch); and, each
 *      dark, master's dispatch equal to 221c9dc's master (from Git) stubbed the same way, on the same book.
 *   C  JobBudget (MS5): its budget cut to master's deadline, null by hand; each of the four jobs reads $_m_deadline.
 *   D  tools/cron_status.php (MS8, the one guard; Part V's V9/VN-CA2 and Part O's I read it): on Uganda with every flag
 *      off it lists exactly the four, OFF (switch), with or without --all; a flag on lists that job as any other; on South
 *      Sudan, every flag on, it lists none of them, and its output is 221c9dc's tool on 221c9dc's master, byte for byte
 *      but for the clock.
 *
 * Then the weakened copies MS1–MS10 (each a copy of the plugin with one anchored edit, judged by this file run with
 * --root=<the copy> --sections=<its sections>), through the harness of tests/quote_followup/_lib.php. Every person,
 * number and message is fictitious; nothing leaves 127.0.0.1.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_master_104.php [--root=DIR] [--sections=A,B] [--no-mutants]
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/quote_followup/_lib.php';
require_once __DIR__ . '/quote_followup/declared.php';
date_default_timezone_set('UTC');

require_once __DIR__ . '/quote_followup/master_lib.php';

$O   = qf_options();
$own = qf_own_tmp('ms');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

$MS_SECTIONS = [];

$MS_SECTIONS['A'] = ['title' => 'The four lines, read as text (§H.2.13.1, §H.2.13.5; ruling R1)', 'run' => function (QfCtx $ctx) {
    $src = (string)@file_get_contents($ctx->root . '/cron/master.php');
    $jobs = ms_job_lines($src);
    $names = array_keys($jobs);
    $ctx->t(count($jobs) > 30 && isset($jobs['starlink_alive'], $jobs['notify_retry']), 'A00 control: the job list reads (' . count($jobs) . ' jobs, starlink_alive and notify_retry among them)');
    $ctx->eq($names[0] ?? null, 'starlink_alive', 'A01 starlink_alive is still the first job');
    $ctx->eq(array_slice($names, -4), MS_JOBS, 'A02 the last four jobs of $_m_jobs are quote_chase, quote_daily_list, ai_media, offers_send, in that order');
    $at = array_search('notify_retry', $names, true);
    $ctx->eq($at === false ? null : array_slice($names, $at + 1), MS_JOBS, 'A03 they come straight after notify_retry: no job between, none after');
    $lines = array_map(function ($j) use ($jobs) { return $jobs[$j] ?? null; }, MS_JOBS);
    $ctx->eq($lines, MS_LINES, 'A04 each line is ruling R1\'s, byte for byte (key order interval, uganda, flag, script)');
    $blk = substr($src, (int)strpos($src, '$_m_jobs = ['));
    $blk = substr($blk, 0, (int)strpos($blk, "\n];") + 3);
    $nc = array_values(array_filter(array_map('trim', explode("\n", $blk)), function ($l) { return $l !== '' && strpos($l, '//') !== 0; }));
    $ctx->eq(array_slice($nc, -5), array_merge(array_map('trim', MS_LINES), ['];']), 'A05 they are the last four non-comment lines of $_m_jobs before its close (what OfferSwitch\'s P-O1-6 reads)');
    foreach (MS_JOBS as $i => $j) {
        $l = (string)($jobs[$j] ?? '');
        preg_match("/'interval'\s*=>\s*(\d+)/", $l, $iv);
        preg_match("/'flag'\s*=>\s*'([a-z_]+)'/", $l, $fl);
        $ctx->eq([(int)($iv[1] ?? -1), strpos($l, "'uganda' => true") !== false, $fl[1] ?? null, substr_count($src, "'" . $j . "'")],
                 [[540, 240, 60, 240][$i], true, MS_FLAGS[$j], 1],
                 "A06 {$j}: interval " . [540, 240, 60, 240][$i] . ", 'uganda' => true, flag " . MS_FLAGS[$j] . ', registered once');
    }
    $loop = substr($src, (int)strpos($src, 'foreach ($_m_jobs as $_m_name => $_m_job) {'));
    $gate = strpos($loop, "if (isset(\$_m_job['gate']) && !NotifyGate::applies(");
    $ug   = strpos($loop, "if (!empty(\$_m_job['uganda']) && !StaffJobsGate::applies(is_array(\$_m_config) ? \$_m_config : [], \$dataDir)) continue;");
    $flag = strpos($loop, "if (isset(\$_m_job['flag'])) {\n        \$_m_flagVal = is_array(\$_m_config) ? (\$_m_config[(string)\$_m_job['flag']] ?? null) : null;\n"
                        . "        if (!in_array(strtolower(trim((string)(\$_m_flagVal ?? ''))), ['1', 'true', 'on', 'yes'], true)) continue;\n    }");
    $script = strpos($loop, "\$_m_scriptPath = \$_m_job['script'];");
    $ctx->t($gate !== false && $ug !== false && $flag !== false && $script !== false && $gate < $ug && $ug < $flag && $flag < $script,
            'A07 the two dispatch lines, beside the gate line and before anything is dispatched or recorded', json_encode([$gate, $ug, $flag, $script]));
    $req = strpos($src, "require_once dirname(__DIR__) . '/lib/StaffJobsGate.php';");
    $ctx->t($req !== false && $req > (int)strpos($src, "require_once dirname(__DIR__) . '/lib/JobWindow.php';") && $req < (int)strpos($src, 'foreach ($_m_jobs as'),
            'A08 the require of lib/StaffJobsGate.php after JobWindow\'s and before the loop');
    $ctx->t(strpos($src, 'BUDGET EXCEEDED') !== false && strpos($loop, 'if (time() > $_m_deadline) {') !== false,
            'A09 master still stops dispatching once its budget is spent (the order decides only which job defers)');
}];

$MS_SECTIONS['B'] = ['title' => 'Dispatch, run: master in a copy whose job scripts are stubs (§H.2.13.2, §H.2.17.2 item 5)', 'run' => function (QfCtx $ctx) {
    $stub = ms_stub_tree($ctx, $ctx->root, 'msstub');
    $four = array_values(MS_SCRIPTS);
    $allOn = array_fill_keys(array_values(MS_FLAGS), '1');
    $mine = function (array $ran) use ($four): array { return array_values(array_intersect($ran, $four)); };
    $rows = function (array $sched): array { return array_values(array_intersect($sched, MS_JOBS)); };

    $ctx->run('B01 Uganda, every flag off: master dispatches its other jobs; none of the four runs or gets a schedule row (MS2, MS6)', function () use ($ctx, $stub, $mine, $rows) {
        [$ran, $sched, $rc, $out] = ms_master_run($ctx, $stub, ms_cfg('uganda'));
        $ctx->t($rc === 0 && in_array('cron/starlink_keepalive.php', $ran, true) && in_array('cron/notify_retry.php', $ran, true) && count($ran) > 20,
                'B01 control: the run completes and dispatches the other jobs (' . count($ran) . ' stubs, notify_retry\'s among them)', substr($out, -600));
        $ctx->eq([$mine($ran), $rows($sched)], [[], []], 'B01 none of the four ran, and master_schedule.json holds no row for them');
    });

    $ctx->run('B02 Uganda, one flag on at a time: that job alone of the four runs and gets its row (each spelling of on)', function () use ($ctx, $stub, $mine, $rows) {
        foreach (array_values(MS_JOBS) as $i => $j) {
            $on = ['1', 'true', 'on', 'yes'][$i];
            [$ran, $sched, $rc, $out] = ms_master_run($ctx, $stub, ms_cfg('uganda', [MS_FLAGS[$j] => $on]));
            $ctx->eq([$rc, $mine($ran), $rows($sched)], [0, [MS_SCRIPTS[$j]], [$j]], "B02 {$j} with " . MS_FLAGS[$j] . "='{$on}': it ran, and has its row", substr($out, -400));
        }
        [$ran, $sched] = ms_master_run($ctx, $stub, ms_cfg('uganda', ['quote_tracking_enabled' => '0', 'ai_media_enabled' => 'off', 'offers_live_enabled' => 'no', 'quote_daily_list_enabled' => '']));
        $ctx->eq([$mine($ran), $rows($sched)], [[], []], 'B02 0, off, no and an empty value read as off');
    });

    $ctx->run('B03 South Sudan with every flag on: none of the four — the gate stops them, not the switch (MS1)', function () use ($ctx, $stub, $mine, $rows, $allOn) {
        [$ran, $sched, $rc, $out] = ms_master_run($ctx, $stub, ms_cfg('south-sudan', $allOn));
        $ctx->t($rc === 0 && in_array('cron/starlink_keepalive.php', $ran, true) && count($ran) > 20, 'B03 control: South Sudan\'s run dispatches its jobs (' . count($ran) . ')', substr($out, -600));
        $ctx->eq([$mine($ran), $rows($sched)], [[], []], 'B03 none of the four ran, no schedule row');
        [$ran2, $sched2] = ms_master_run($ctx, $stub, ms_cfg('uganda', $allOn));
        $ctx->eq($mine($ran2), array_values(MS_SCRIPTS), 'B03 control: the same flags on Uganda run all four, in ruling R1\'s order');
    });

    $ctx->run('B04 dark, master dispatches exactly what 221c9dc\'s master does, on South Sudan (every flag on) and on Uganda (every flag off)', function () use ($ctx, $stub, $allOn) {
        [$base, $why] = qf_n_base($ctx->tmp . '/base221');
        $ctx->t($base !== null, 'B04 control: Git supplies ' . QF_N_BASE . ' — without it this is a failure, never a skip', $why);
        if ($base === null) return;
        $old = ms_stub_tree($ctx, $base, 'msstub221');
        foreach ([['south-sudan', $allOn], ['uganda', []]] as [$t, $flags]) {
            [$ranNew, $sNew, $rcNew] = ms_master_run($ctx, $stub, ms_cfg($t, $flags));
            [$ranOld, $sOld, $rcOld] = ms_master_run($ctx, $old, ms_cfg($t, $flags));
            $ctx->eq([$rcNew, $ranNew, $sNew], [$rcOld, $ranOld, $sOld], "B04 {$t}: the same jobs, in the same order, and the same schedule rows as 221c9dc's master (" . count($ranOld) . ' jobs)');
        }
    });
}];

$MS_SECTIONS['C'] = ['title' => 'JobBudget against master\'s deadline (§H.2.13.3; MS5)', 'run' => function (QfCtx $ctx) {
    require_once $ctx->tree() . '/lib/JobBudget.php';
    $n = 1_000_000;
    $ctx->eq(JobBudget::seconds(20, null, $n), 20, 'C00 control: run by hand (no deadline), a job has its own budget');
    $ctx->eq([JobBudget::seconds(20, $n + 300, $n), JobBudget::seconds(20, $n + 12, $n), JobBudget::seconds(35, $n + 35, $n)], [20, 12, 35],
             'C01 cut to what master\'s cycle has left: 300 s left → 20; 12 s left → 12; 35 s left → 35');
    $ctx->eq([JobBudget::seconds(20, $n, $n), JobBudget::seconds(20, $n - 40, $n)], [0, 0], 'C02 the deadline reached or passed: 0, do not start');
    $u = $n + JobBudget::seconds(35, $n + 30, $n);
    $ctx->eq([JobBudget::mayStart($u, 15, $n), JobBudget::mayStart($u, 15, $n + 15), JobBudget::mayStart($u, 15, $n + 16), JobBudget::mayStart($u, 20, $n + 10), JobBudget::mayStart($u, 20, $n + 11)],
             [true, true, false, true, false], 'C03 mayStart (the run ends 30 s on): a 15 s send may start until 15 s on, a 20 s one until 10 s on, and not one second later');
    $ctx->t(JobBudget::mayStart(time() + 30, 5) && !JobBudget::mayStart(time() + 3, 5), 'C04 mayStart without $now reads the clock');
    foreach (MS_SCRIPTS as $j => $rel) {
        $s = (string)@file_get_contents($ctx->root . '/' . $rel);
        $ctx->t(strpos($s, 'isset($_m_deadline) ? (int)$_m_deadline : null') !== false, "C05 {$j} ({$rel}) takes master's deadline, or null run by hand");
    }
}];

$MS_SECTIONS['D'] = ['title' => 'tools/cron_status.php: OFF (switch) on Uganda, never listed on South Sudan (§H.2.13.2; MS8)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    $ctx->run('D01 Uganda, every flag off: exactly the four, each OFF (switch), with and without --all; never NEVER RUN', function () use ($ctx, $tree) {
        [$rc, $out] = ms_status($ctx, $tree, ms_cfg('uganda'), ['--all']);
        $rows = ms_rows($out);
        $ctx->t($rc === 0 && count($rows) > 30 && ($rows['starlink_alive'] ?? null) !== null, 'D01 control: --all lists the job list (' . count($rows) . ' rows)', substr($out, 0, 600));
        $off = array_keys(array_filter($rows, function ($s) { return $s === 'OFF (switch)'; }));
        $ctx->eq($off, MS_JOBS, 'D01 --all: the four, and only they, read OFF (switch), last, in ruling R1\'s order');
        [$rc2, $out2] = ms_status($ctx, $tree, ms_cfg('uganda'));
        $rows2 = ms_rows($out2);
        $ctx->eq(array_values(array_intersect(array_keys($rows2), MS_JOBS)), MS_JOBS, 'D01 without --all they are listed too (a dark job is never a missing line)');
        $ctx->t(array_filter(array_intersect_key($rows2, array_flip(MS_JOBS)), function ($s) { return $s !== 'OFF (switch)'; }) === []
                && strpos($out2, 'OFF (switch): 4 job(s)') !== false, 'D01 … each OFF (switch), with the one line that says what it means', substr($out2, 0, 900));
    });
    $ctx->run('D02 Uganda, a flag on: that job is listed as any other (here NEVER RUN), the rest OFF (switch)', function () use ($ctx, $tree) {
        [$rc, $out] = ms_status($ctx, $tree, ms_cfg('uganda', ['ai_media_enabled' => 'on']), ['--all']);
        $r = ms_rows($out);
        $ctx->eq([$r['ai_media'] ?? null, $r['quote_chase'] ?? null, $r['quote_daily_list'] ?? null, $r['offers_send'] ?? null],
                 ['NEVER RUN', 'OFF (switch)', 'OFF (switch)', 'OFF (switch)'], 'D02 ai_media on: NEVER RUN; the other three OFF (switch)', substr($out, 0, 900));
    });
    $ctx->run('D03 South Sudan, every flag on: none of the four, and 221c9dc\'s tool on 221c9dc\'s master prints the same, but for the clock', function () use ($ctx, $tree) {
        $cfg = ms_cfg('south-sudan', array_fill_keys(array_values(MS_FLAGS), '1'));
        [$base, $why] = qf_n_base($ctx->tmp . '/base221');
        $ctx->t($base !== null, 'D03 control: Git supplies ' . QF_N_BASE, $why);
        foreach ([['--all'], []] as $args) {
            [$rc, $out, $data] = ms_status($ctx, $tree, $cfg, $args);
            $rows = ms_rows($out);
            $ctx->t($rc === 0 && ($args === [] || count($rows) > 30), 'D03 control: the tool runs on South Sudan' . ($args ? ' with --all (' . count($rows) . ' rows)' : ''), substr($out, 0, 400));
            $ctx->eq(array_values(array_intersect(array_keys($rows), MS_JOBS)), [], 'D03 ' . ($args ? '--all' : 'plain') . ': none of the four is listed');
            if ($base === null) continue;
            [, $old] = ms_status($ctx, $base, $cfg, $args, $data);
            $clock = function (string $s): string { return preg_replace('/\(\d+[smhd] ago,/', '(N ago,', $s); };
            $ctx->eq($clock($out), $clock($old), 'D03 ' . ($args ? '--all' : 'plain') . ': byte for byte 221c9dc\'s output (the "ago" figure masked)');
        }
    });
}];

qf_run_sections($ctx, $MS_SECTIONS, $O['sections']);

if ($O['mutants']) {
    $M = [
        ['label' => "MS1 the 'uganda' check removed (South Sudan dispatches)", 'file' => 'cron/master.php',
         'old' => "    if (!empty(\$_m_job['uganda']) && !StaffJobsGate::applies(is_array(\$_m_config) ? \$_m_config : [], \$dataDir)) continue;\n", 'new' => "\n",
         'sections' => 'A,B', 'pattern' => '/A07 |B03 |B04 /'],
        ['label' => 'MS2 the flag check removed (schedule rows while off)', 'file' => 'cron/master.php',
         'old' => "        if (!in_array(strtolower(trim((string)(\$_m_flagVal ?? ''))), ['1', 'true', 'on', 'yes'], true)) continue;\n", 'new' => "\n",
         'sections' => 'A,B', 'pattern' => '/A07 |B01 |B02 /'],
        ['label' => 'MS3 a new job placed before notify_retry', 'edits' => [
            ['cron/master.php', MS_LINES[0] . "\n", ''],
            ['cron/master.php', "    'notify_retry'  => ['interval' => 240,", MS_LINES[0] . "\n    'notify_retry'  => ['interval' => 240,"]],
         'sections' => 'A', 'pattern' => '/A02 |A03 |A05 /'],
        ['label' => 'MS4 intervals 600/300', 'edits' => [
            ['cron/master.php', "'quote_chase'      => ['interval' => 540,", "'quote_chase'      => ['interval' => 600,"],
            ['cron/master.php', "'offers_send'      => ['interval' => 240,", "'offers_send'      => ['interval' => 300,"]],
         'sections' => 'A', 'pattern' => '/A04 |A06 /'],
        ['label' => 'MS5 JobBudget ignores $_m_deadline', 'file' => 'lib/JobBudget.php',
         'old' => 'return max(0, min($own, $masterDeadline === null ? $own : $masterDeadline - $now));', 'new' => 'return max(0, $own);',
         'sections' => 'C', 'pattern' => '/C01 |C02 /'],
        ['label' => 'MS6 a job line without its flag (ai_media)', 'file' => 'cron/master.php',
         'old' => "'uganda' => true, 'flag' => 'ai_media_enabled',         'script'", 'new' => "'uganda' => true,                                       'script'",
         'sections' => 'A,B,D', 'pattern' => '/A04 |A06 |B01 |D01 /'],
        ['label' => 'MS7 the order changed (offers_send not last: first of the four)', 'edits' => [
            ['cron/master.php', MS_LINES[3] . "\n", ''],
            ['cron/master.php', MS_LINES[0] . "\n", MS_LINES[3] . "\n" . MS_LINES[0] . "\n"]],
         'sections' => 'A', 'pattern' => '/A0[25] /'],
        ['label' => 'MS7b offers_send before ai_media', 'edits' => [
            ['cron/master.php', MS_LINES[2] . "\n", ''],
            ['cron/master.php', MS_LINES[3] . "\n", MS_LINES[3] . "\n" . MS_LINES[2] . "\n"]],
         'sections' => 'A,B', 'pattern' => '/A0[25] |B03 /'],
        ['label' => 'MS8 cron_status hiding a flagged job that is off on Uganda', 'file' => 'tools/cron_status.php',
         'old' => "    if (!\$all && !\$late && !\$r['died'] && !\$r['off']) continue;", 'new' => "    if (\$r['off'] || (!\$all && !\$late && !\$r['died'])) continue;",
         'sections' => 'D', 'pattern' => '/D01 /'],
        ['label' => 'MS8b cron_status listing the four on South Sudan (the uganda filter removed)', 'file' => 'tools/cron_status.php',
         'old' => "        if (preg_match(\"/'uganda'\\s*=>\\s*true/\", \$line) && !StaffJobsGate::applies(\$gateCfg, \$dataDir)) continue;\n", 'new' => '',
         'sections' => 'D', 'pattern' => '/D03 /'],
        ['label' => 'MS8c cron_status listing a dark job as NEVER RUN (OFF (switch) not printed)', 'file' => 'tools/cron_status.php',
         'old' => "        \$r['off'] ? 'OFF (switch)' : (\$r['died']", 'new' => "        false ? 'OFF (switch)' : (\$r['died']",
         'sections' => 'D', 'pattern' => '/D01 |D02 /'],
        ['label' => 'MS9 a line whose bytes differ from ruling R1\'s (uganda and flag swapped)', 'file' => 'cron/master.php',
         'old' => "'quote_daily_list' => ['interval' => 240, 'uganda' => true, 'flag' => 'quote_daily_list_enabled', 'script'",
         'new' => "'quote_daily_list' => ['interval' => 240, 'flag' => 'quote_daily_list_enabled', 'uganda' => true, 'script'",
         'sections' => 'A', 'pattern' => '/A04 /'],
        ['label' => 'MS10 the require of lib/StaffJobsGate.php removed', 'file' => 'cron/master.php',
         'old' => "require_once dirname(__DIR__) . '/lib/StaffJobsGate.php';   // 5.18.104", 'new' => '// 5.18.104',
         // A alone: NotifyGate::uganda() also loads the class lazily (lib/NotifyGate.php:64), so dispatch still runs; the
         // require is D's one spelling of the dependency, held by A08
         'sections' => 'A', 'pattern' => '/A08 /'],
    ];
    qf_mutants($ctx, __FILE__, $M, $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

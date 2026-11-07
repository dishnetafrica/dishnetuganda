<?php
declare(strict_types=1);
/**
 * Section J — South Sudan unchanged, and Uganda with the switches off (5.18.104, root docs/73 §H.4.20, §H.4.16 J). TEST ONLY.
 *
 * Both offer keys SET on a South Sudan book, so the gate, not the switch, is shown to stop it: OfferSwitch reads it off;
 * the job included directly opens no store and prints nothing; the sender refuses; 091 leaves four empty tables and its
 * self-check row. On Uganda with both keys unset, nothing runs either. The screen, the nav entry, the seven actions and the
 * two-tree page comparison are stages B8 and B10's (they exist there).
 */
$WO_SECTIONS['J'] = ['title' => 'South Sudan unchanged, and the switches off (§H.4.20)', 'run' => function (QfCtx $ctx) {
    $ss = wo_cfg(['tenant_profile' => 'south-sudan', 'timezone' => null]);

    $ctx->run('J01 the gate: on South Sudan with both keys set, uganda(), screen() and live() are false', function () use ($ctx, $ss) {
        $b = wo_book($ctx, $ss, 'j');
        $ug = wo_book($ctx, wo_cfg(), 'j');
        $ctx->eq([OfferSwitch::uganda($ug['cfg'], $ug['data']), OfferSwitch::live($ug['cfg'], $ug['data'])], [true, true], 'J01 control: Uganda with the keys set is live');
        $ctx->eq([OfferSwitch::uganda($b['cfg'], $b['data']), OfferSwitch::screen($b['cfg'], $b['data']), OfferSwitch::live($b['cfg'], $b['data'])], [false, false, false],
                 'J01 South Sudan with the keys set: off, every one');
        $ctx->eq(OfferSwitch::live(wo_cfg(['offers_test_enabled' => null, 'offers_live_enabled' => null]), $ug['data']), false, 'J01 Uganda with both keys unset: off');
    });

    $ctx->run('J02 the job included directly on South Sudan: no store opened, nothing printed', function () use ($ctx, $ss) {
        if (!function_exists('wo_cron')) require_once __DIR__ . '/I_job.php';
        $d = $ctx->dir('j-ss');
        [$out, $rc] = wo_cron($ctx, $d, $ss, null);
        $ctx->eq([$rc, $out, is_file($d . '/plugin.sqlite3')], [0, '', false], 'J02 exit 0, no output, no book created');
        $b = wo_book($ctx, $ss, 'j');
        $before = wo_hashes($b['pdo']);
        [$out] = wo_cron($ctx, $b['data'], $ss, null);
        $ctx->eq([$out, wo_hashes($b['pdo']) === $before], ['', true], 'J02 on an existing South Sudan book: nothing printed, no row of any table changed');
    });

    $ctx->run('J03 the sender itself refuses on South Sudan; 091 there is four empty tables and its self-check row', function () use ($ctx, $ss) {
        $b = wo_book($ctx, $ss, 'j');
        $log = new WoLog();
        $r = wo_sender($ctx, $b, new WoClock(wo_t0()), new WoBudget($log), new WoGateway($log))->runOnce(null);
        $ctx->eq([$r['code'], $log->calls], ['offers_off', []], 'J03 runOnce on South Sudan: offers_off, no claim, no send');
        $n = [];
        foreach (OfferSchema::TABLES as $t) $n[] = (int)$b['pdo']->query("SELECT COUNT(*) FROM [{$t}]")->fetchColumn();
        $ctx->eq([OfferSchema::check($b['pdo']), $n], [[], [0, 0, 0, 0]], 'J03 091 on South Sudan: sound, four empty tables, the 25/25 row');
    });

    // 5.18.104 (root docs/73 §H.4.20 item 1; ruling R1): master and cron_status on South Sudan, a read of Part F's MS8
    $ctx->run('J05 master on South Sudan with both keys on leaves offers_send undispatched, with no row; cron_status never lists it', function () use ($ctx) {
        require_once dirname(__DIR__) . '/quote_followup/master_lib.php';
        $both = ['offers_test_enabled' => '1', 'offers_live_enabled' => '1'];
        $stub = ms_stub_tree($ctx, $ctx->root, 'wo-ms-ss');
        [$ran, $sched, $rc] = ms_master_run($ctx, $stub, ms_cfg('south-sudan', $both));
        $ctx->t($rc === 0 && count($ran) > 20, 'J05 control: master runs South Sudan\'s jobs (' . count($ran) . ')');
        $ctx->eq([in_array(MS_SCRIPTS['offers_send'], $ran, true), in_array('offers_send', $sched, true)], [false, false], 'J05 no run, no schedule row');
        [, $out] = ms_status($ctx, $ctx->root, ms_cfg('south-sudan', $both), ['--all']);
        $rows = ms_rows($out);
        $ctx->eq([count($rows) > 30, array_key_exists('offers_send', $rows)], [true, false], 'J05 cron_status --all lists South Sudan\'s jobs, and not offers_send');
    });

    // 5.18.104 (root docs/73 §H.4.20 item 4, OM4's J half), stage B10: two trees, the same book, the pages compared
    $ctx->run('J06 two trees: the dashboard and the Engage pages from 221c9dc and from 5.18.104, South Sudan (both keys on) and Uganda (both unset)', function () use ($ctx) {
        require_once dirname(__DIR__) . '/quote_followup/declared.php';
        require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';
        [$base, $why] = qf_n_base($ctx->tmp . '/base221');
        $ctx->t($base !== null, 'J06 control: Git supplies ' . QF_N_BASE . ' — without it this is a failure, never a skip', $why);
        if ($base === null) return;
        // Part V's Inbox hunk draws one JS line on Uganda (the R3 transcript, §H.3.11): on the Uganda book it is taken out of
        // 5.18.104's page, found exactly once, and counted; P6's claim is that P6 dark changes nothing there
        $vLine = "        if(m.voice_transcript){ body+='<div class=\"wai-voice-tx\"><b>[voice message \\u2014 machine transcript for staff, check it against the audio]</b> '+esc(m.voice_transcript).replace(/\\n/g,'<br>')+'</div>'; }\n";
        $norm = function (string $x, SjSandbox $s): string {
            if (($p = strpos($x, '</html>')) !== false) $x = substr($x, 0, $p + 7);
            $m = json_decode((string)@file_get_contents($s->root . '/manifest.json'), true);
            $v = (string)($m['information']['version'] ?? '');
            if ($v !== '') $x = (string)preg_replace('/\bv?' . preg_quote($v, '/') . '\b/', '<version>', $x);
            foreach ($s->tok as $k => $t) $x = str_replace($t, "<tok:{$k}>", $x);
            $x = str_replace([$s->data, $s->plug, $s->sb], ['<data>', '<plug>', '<sb>'], $x);
            foreach (['crm' => $s->crm, 'evo' => $s->evo, 'plugin' => (string)preg_replace('#/public\.php$#', '', $s->base)] as $k => $a) $x = str_replace([$a, str_replace('/', '\/', $a)], "<{$k}>", $x);
            $x = (string)preg_replace('/\b\d{8}:[0-9a-f]{40}\b/', '<day-token>', $x);
            $x = (string)preg_replace('/name="_csrf" value="[^"]*"/', 'name="_csrf" value="<csrf>"', $x);
            $x = (string)preg_replace('/(csrf[^\n]{0,40}?)[0-9a-f]{32,}/i', '$1<csrf>', $x);
            $x = (string)preg_replace('/\b\d+[smhd] ago\b/', '<ago>', $x);
            $x = (string)preg_replace('/\b(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,? \d{1,2} [A-Z][a-z]{2} \d{4}\b|\b\d{1,2} [A-Z][a-z]{2} \d{4}\b|\b\d{1,2}:\d{2} ?[AP]M\b/', '<date>', $x);
            return (string)preg_replace('/\b\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?\b/', '<clock>', $x);
        };
        $books = ['south-sudan' => ['tenant_profile' => 'south-sudan', 'offers_test_enabled' => '1', 'offers_live_enabled' => '1', 'offers_live_since' => '2026-11-01 06:00:00'],
                  'uganda' => ['tenant_profile' => 'uganda', 'timezone' => QF_TZ]];
        foreach ($books as $tenant => $cfg) {
            $pages = [];
            foreach (['old' => $base, 'new' => $ctx->root] as $which => $tree) {
                $sb = SjSandbox::start($tree, $cfg, 'woj' . $which);
                try {
                    $sb->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
                    $sb->login('admin', 'admin@example.test', 'sj-password-1');
                    foreach (['dashboard' => 'page=dashboard', 'followups' => 'page=dashboard&tab=followups', 'inbox' => 'page=dashboard&tab=wa_inbox'] as $k => $qs) {
                        $pages[$which][$k] = $norm($sb->page('admin', $qs), $sb);
                    }
                } finally { $sb->stop(); }
            }
            $vSeen = [substr_count($pages['old']['inbox'], $vLine), substr_count($pages['new']['inbox'], $vLine)];
            if ($tenant === 'uganda') $pages['new']['inbox'] = str_replace($vLine, '', $pages['new']['inbox']);
            $ctx->t(strlen($pages['old']['dashboard']) > 5000 && strpos($pages['old']['dashboard'], 'kyc-tab') !== false, "J06 control ({$tenant}): 221c9dc's dashboard renders its menu");
            foreach (['dashboard', 'followups', 'inbox'] as $k) {
                $a = $pages['old'][$k]; $b = $pages['new'][$k];
                $at = 0; while ($at < min(strlen($a), strlen($b)) && $a[$at] === $b[$at]) $at++;
                $ctx->t($a === $b, "J06 {$tenant}: the {$k} page is 221c9dc's" . ($tenant === 'uganda' && $k === 'inbox' ? ' (Part V\'s one Uganda line taken out)' : ''),
                        'first difference at byte ' . $at . ': ' . json_encode([substr($a, max(0, $at - 80), 200), substr($b, max(0, $at - 80), 200)]));
            }
            $ctx->eq($vSeen, $tenant === 'uganda' ? [0, 1] : [0, 0], "J06 {$tenant}: Part V's transcript line on 5.18.104's Inbox page " . ($tenant === 'uganda' ? 'once' : 'never') . ", on 221c9dc's never");
            $ctx->eq([strpos($pages['new']['dashboard'], 'tab=wa_offers') !== false, strpos($pages['new']['dashboard'], 'tab=my_quotes') !== false], [false, false],
                     "J06 {$tenant}: no WhatsApp offers or My quotations entry");
        }
    });

    $ctx->run('J04 a South Sudan number on Uganda is never offered', function () use ($ctx) {
        $b = wo_book($ctx, wo_cfg(), 'j');
        wo_warm($b, '211912300401', wo_t0(), 86400);
        wo_warm($b, '256700930401', wo_t0(), 86400);
        $a = OfferAudience::build($b['pdo'], $b['cfg'], $b['data'], [], [], 'all', wo_t0());
        $ctx->eq([array_column($a['eligible'], 'phone'), $a['left_out']], [['256700930401'], ['south_sudan_number' => 1]], 'J04 +211 left out as south_sudan_number');
    });
}];

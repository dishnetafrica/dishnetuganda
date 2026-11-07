<?php
declare(strict_types=1);
/**
 * test_voice_south_sudan.php — 5.18.104 (root docs/73 §H.3.26): South Sudan is unchanged by Part V's media chain.
 *
 * In a South Sudan sandbox with every voice key on (ai_media_enabled, the three rungs and ai_media_voice_reply, provider
 * openai, a sentinel key, a privacy date, wa_voice_ack_enabled, wa_send_ledger, the reply list `all`):
 * A the webhook: a voice note is stored as [AUDIO] with 5.18.103's columns and nothing else — no wa_media, wa_voice_calls
 *   or wa_voice_acks row, no ai.media or ai.voice_ack event, no send — and the answer has 5.18.103's keys; a fromMe
 *   captionless audio stores nothing; a text is queued for the assistant as always; (B10-fix1, B10-R02; root docs/73
 *   §H.2.17.2 item 4, §H.2.7.3 W2/W3) a reply inside a disappearing message (a wrapped inbound) and an @lid message with
 *   no number store nothing and leave no seen mark — with, as the control, the same two payloads through the same
 *   webhook on a Uganda sandbox with the ledger on leaving exactly W2's mark and W3's count;
 * B the same payloads through 221c9dc's evo_webhook.php (from Git) in a sibling sandbox: answers, rows, events and
 *   outbound calls compared equal; the wrapped inbound and the @lid message among them, 0 ledger rows and 0 marks;
 * C the worker and its runner do nothing there (a row planted by hand is never fetched), the prune prints nothing new,
 *   and the settings tab's AI section is 221c9dc's byte for byte.
 * Stage B6 adds the reply worker's and the Inbox API's halves; stage B10 master's and cron_status's:
 * D (5.18.104, root docs/73 §H.3.19, §H.3.26; ruling R1) master does not dispatch ai_media on South Sudan with every voice key
 *   on, and keeps no schedule row for it; tools/cron_status.php does not list it — a read of Part F's MS8
 *   (tests/test_master_104.php, through tests/quote_followup/master_lib.php); on Uganda, dark, it is listed OFF (switch).
 * Each section begins with its positive control; then the weakened copies (V1's worker form, SS-GATE, SS-TAB, V8, V9, and
 * SS-W2, SS-W3: the marks' gate taken from the wrapped-inbound path and from the @lid count).
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_voice_south_sudan.php [--root=DIR] [--sections=A] [--no-mutants]
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/voice_media/_lib.php';
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';
require_once __DIR__ . '/quote_followup/master_lib.php';   // 5.18.104 (root docs/73 §H.3.19): section D, a read of MS8
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('vss');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

const VSS_CFG = ['tenant_profile' => 'south-sudan', 'timezone' => 'UTC', 'ai_enabled' => '1', 'ai_provider' => 'claude',
                 'ai_media_enabled' => '1', 'ai_media_voice' => '1', 'ai_media_voice_handover' => '1', 'ai_media_voice_reply' => '1',
                 'ai_media_voice_reply_channels' => 'all', 'ai_media_voice_reply_approved' => '2026-10-25', 'ai_transcription_provider' => 'openai',
                 'openai_api_key' => VM_KEY, 'ai_media_voice_privacy_line' => VM_PRIVACY, 'wa_voice_ack_enabled' => '1', 'wa_send_ledger' => 'on'];

/**
 * The payloads every section sends, in order: a voice note, a forwarded audio, a captionless fromMe audio (W1's), a text,
 * and (B10-fix1, B10-R02) a reply inside a disappearing message (W2's wrapped inbound) and an @lid message with no number
 * (W3's: no senderPn, so no phone).
 */
function vss_payloads(): array
{
    $lid = vm_envelope('211912301306', ['id' => 'VSS-6', 'kind' => 'text', 'text' => 'VSS sent from a linked id']);
    $lid['key']['remoteJid'] = '84512301306@lid';
    return [
        vm_envelope('211912301301', ['id' => 'VSS-1']),
        vm_envelope('211912301302', ['id' => 'VSS-2', 'ptt' => false]),
        vm_envelope('211912301303', ['id' => 'VSS-3', 'from_me' => true]),
        vm_envelope('211912301304', ['id' => 'VSS-4', 'kind' => 'text', 'text' => 'VSS is the kit in stock?']),
        vm_envelope('211912301305', ['id' => 'VSS-5', 'kind' => 'text', 'text' => 'VSS yes, send the quotation', 'wrap' => 'ephemeralMessage']),
        $lid,
    ];
}

/** Every payload through the sandbox's webhook, then what it left. */
function vss_drive(SjSandbox $s): array
{
    $out = ['answers' => []];
    foreach (vss_payloads() as $p) {
        $p['messageTimestamp'] = time();
        $r = $s->http('POST', "{$s->base}?page=evo_webhook", ['event' => 'messages.upsert', 'instance' => 'sj-sales', 'data' => $p],
                      ['Content-Type: application/json', 'X-DishNet-Token: ' . $s->evoKey]);
        $out['answers'][] = is_array($r[2]) ? $r[2] : ['_code' => $r[0]];
    }
    $out['messages'] = $s->q('SELECT c.phone, c.channel, m.direction, m.role, m.body, m.media_type, m.wa_message_id FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id ORDER BY m.id');
    $out['events']   = array_map(function ($e) { return [$e['event_type'], $e['entity_type'], array_keys(json_decode($e['payload'], true) ?: [])]; },
                                 $s->q('SELECT event_type, entity_type, payload FROM events ORDER BY id'));
    $out['texts']    = $s->texts();
    $tables = array_column($s->q("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name');
    foreach (['wa_media', 'wa_voice_calls', 'wa_voice_acks', 'wa_auto_sends', 'wa_seen_marks'] as $t) {
        $out['rows'][$t] = in_array($t, $tables, true) ? (int)$s->q("SELECT COUNT(*) n FROM {$t}")[0]['n'] : 'absent';
    }
    // B10-fix1 (B10-R02): each mark by what wrote it — W1 (out, a media key), W2 (in, a wrapper) or W3 (in, lid_no_number)
    $out['marks'] = in_array('wa_seen_marks', $tables, true)
        ? array_map(function ($r) { return $r['direction'] . '|' . $r['kind'] . '|' . $r['wa_message_id']; },
                    $s->q('SELECT direction, kind, wa_message_id FROM wa_seen_marks ORDER BY id')) : 'absent';
    return $out;
}

$SECTIONS = [
    'A' => ['title' => 'South Sudan, every voice key on: the webhook is 5.18.103\'s', 'run' => function (QfCtx $ctx): void {
        vm_load($ctx);
        $s = SjSandbox::start($ctx->root, VSS_CFG, 'vssa');
        try {
            $f = vss_drive($s);
            $ctx->run('A01 control: the text is queued for the assistant, and the notes are stored as [AUDIO]', function () use ($ctx, $f) {
                $ctx->eq([$f['answers'][3]['queued'] ?? null, array_column(array_filter($f['messages'], function ($m) { return $m['body'] === '[AUDIO]'; }), 'wa_message_id')],
                         [1, ['VSS-1', 'VSS-2']], 'A01 ai.reply for the text; [AUDIO] rows for the two inbound notes');
            });
            $ctx->run('A02 nothing of Part V: no row, no event, no send', function () use ($ctx, $f) {
                $ctx->eq($f['rows'], ['wa_media' => 0, 'wa_voice_calls' => 0, 'wa_voice_acks' => 0, 'wa_auto_sends' => 0, 'wa_seen_marks' => 0], 'A02 090\'s and 089\'s tables exist and stay empty');
                $ctx->eq(array_column($f['events'], 0), ['ai.reply'], 'A02 the one event is the text\'s ai.reply');
                $ctx->eq([$f['texts'], count(array_filter($f['messages'], function ($m) { return $m['wa_message_id'] === 'VSS-3'; }))], [[], 0],
                         'A02 nothing sent; the captionless fromMe audio stored nothing');
            });
            $ctx->run('A03 the answer has 5.18.103\'s keys', function () use ($ctx, $f) {
                $ctx->eq(array_map('array_keys', $f['answers']), array_fill(0, count(vss_payloads()), ['status', 'outcome', 'channel', 'queued', 'skipped']), 'A03 no media_queued, no ack_queued');
            });
        } finally {
            $s->stop();
        }
        // B10-fix1 (B10-R02; §H.2.17.2 item 4, §H.2.7.3 W2/W3): the wrapped inbound and the @lid message, against a Uganda control
        $ctx->run('A04 a wrapped inbound and an @lid message with no number: nothing stored, no mark (W2, W3; W1 is A02\'s)', function () use ($ctx, $f) {
            $u = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'wa_send_ledger' => 'on'], 'vssu');
            try { $fu = vss_drive($u); } finally { $u->stop(); }
            $ctx->eq([in_array('out|audioMessage|VSS-3', (array)$fu['marks'], true), in_array('in|ephemeralMessage|VSS-5', (array)$fu['marks'], true),
                      in_array('in|lid_no_number|VSS-6', (array)$fu['marks'], true),
                      count(array_filter($fu['messages'], function ($m) { return in_array($m['wa_message_id'], ['VSS-3', 'VSS-5', 'VSS-6'], true); }))],
                     [true, true, true, 0], 'A04 control: on Uganda, the ledger on, the same payloads reach W1 (the fromMe audio\'s mark), W2 (a mark for the '
                     . 'wrapped reply) and W3 (the @lid count), none stored', json_encode($fu['marks']));
            $ctx->eq([$f['answers'][4]['skipped'] ?? null, $f['answers'][5]['skipped'] ?? null,
                      count(array_filter($f['messages'], function ($m) { return in_array($m['wa_message_id'], ['VSS-5', 'VSS-6'], true); }))],
                     [1, 1, 0], 'A04 South Sudan: both skipped, as on 221c9dc, nothing stored');
            $ctx->eq(array_values(array_filter((array)$f['marks'], function ($m) { return strpos($m, '|VSS-5') !== false; })), [],
                     'A04 South Sudan: the wrapped inbound left no mark (W2)');
            $ctx->eq(array_values(array_filter((array)$f['marks'], function ($m) { return strpos($m, '|VSS-6') !== false; })), [],
                     'A04 South Sudan: the @lid message with no number left no count (W3)');
        });
    }],
    'B' => ['title' => 'The same payloads through 221c9dc\'s webhook: equal', 'run' => function (QfCtx $ctx): void {
        vm_load($ctx);
        $base = vm_base($ctx);
        $ctx->t($base !== null, 'B00 Git supplied 221c9dc');
        if ($base === null) return;
        $now = SjSandbox::start($ctx->root, VSS_CFG, 'vssb'); $fNow = vss_drive($now); $now->stop();
        $then = SjSandbox::start($base, VSS_CFG, 'vssc'); $fThen = vss_drive($then); $then->stop();
        $ctx->run('B01 control: 221c9dc\'s run stored the notes and queued the text', function () use ($ctx, $fThen) {
            $ctx->eq([count($fThen['messages']), $fThen['answers'][3]['queued'] ?? null], [3, 1], 'B01 three stored messages, one queued');
        });
        $ctx->run('B02 answers, rows, events and sends equal', function () use ($ctx, $fNow, $fThen) {
            foreach (['answers', 'messages', 'events', 'texts'] as $k) $ctx->eq($fNow[$k], $fThen[$k], 'B02 the same ' . $k);
        });
        // B10-fix1 (B10-R02; §H.2.17.2 item 4): the fromMe audio (W1), the wrapped inbound (W2) and the @lid message (W3), one by one
        $ctx->run('B03 the fromMe audio, the wrapped inbound and the @lid message: the same answers and rows, 0 ledger rows or marks', function () use ($ctx, $fNow, $fThen) {
            $ids = ['VSS-3', 'VSS-5', 'VSS-6'];
            $pick = function (array $f) use ($ids) {
                return [array_slice($f['answers'], 4, 2), array_values(array_filter($f['messages'], function ($m) use ($ids) { return in_array($m['wa_message_id'], $ids, true); }))];
            };
            $ctx->eq([count($fNow['answers']), count($fThen['answers'])], [count(vss_payloads()), count(vss_payloads())], 'B03 control: each tree answered every payload');
            $ctx->eq($pick($fNow), $pick($fThen), 'B03 the wrapped inbound\'s and the @lid message\'s answers, and the three stored nothing in either tree');
            // Both books hold 089-091's tables: the sandbox's store, loaded in this process from the judged tree, migrates each book
            // at start. 221c9dc's code never writes them, so its counts are the zero 5.18.104's must equal.
            $ctx->eq([$fNow['rows'], $fNow['marks']], [$fThen['rows'], $fThen['marks']], 'B03 the same rows in 089\'s and 090\'s tables and the same marks');
            $ctx->eq([$fNow['rows']['wa_auto_sends'], $fNow['rows']['wa_seen_marks'], $fNow['marks']], [0, 0, []], 'B03 5.18.104\'s book: 0 ledger rows, 0 marks');
        });
    }],
    'C' => ['title' => 'The worker, the runner, the prune and the settings tab on South Sudan', 'run' => function (QfCtx $ctx): void {
        vm_load($ctx);
        $evo = qf_fake_evo();
        $ctx->t($evo !== '', 'C00 the fake Evolution started');
        if ($evo === '') return;
        $ctx->run('C01 control on Uganda, and the case on South Sudan: a row planted by hand is never fetched there (V1)', function () use ($ctx, $evo) {
            foreach (['uganda' => 'fetched', 'south-sudan' => 'skipped'] as $tenant => $want) {
                qf_http('GET', $evo . '/__test/reset');
                $b = vm_book($ctx, vm_cfg($evo, ['tenant_profile' => $tenant]));
                $v = vm_voice($b, '211912301305');
                vm_media($evo, $v['wa'], ogg_opus(['seconds' => 1.0]));
                vm_run($b, '');
                $ctx->eq([vm_row($b, $v['media'])['status'], count((array)(vm_evo_state($evo)['media_fetch_calls'] ?? []))], [$want, $want === 'fetched' ? 1 : 0], "C01 {$tenant}: {$want}");
            }
        });
        $ctx->run('C02 the runner on South Sudan does nothing at all', function () use ($ctx, $evo) {
            qf_http('GET', $evo . '/__test/reset');
            $b = vm_book($ctx, vm_cfg($evo, ['tenant_profile' => 'south-sudan'] + VSS_CFG));
            $v = vm_voice($b, '211912301306');
            [$rc] = vm_cli($ctx, $b, ['--spawned']);
            $ctx->eq([$rc, vm_row($b, $v['media'])['status'], vm_q($b, 'SELECT status FROM events WHERE id = ?', [$v['event']])[0]['status'] ?? ''], [0, 'pending', 'pending'],
                     'C02 the row and its event untouched');
        });
        $ctx->run('C03 the settings tab\'s AI section is 221c9dc\'s', function () use ($ctx) {
            $base = vm_base($ctx);
            if ($base === null) { $ctx->t(false, 'C03 Git supplied 221c9dc'); return; }
            $section = function (string $root) {
                $s = SjSandbox::start($root, VSS_CFG, 'vsst');
                try {
                    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
                    $s->login('admin', 'admin@example.test', 'sj-password-1');
                    $html = $s->page('admin', 'page=dashboard&tab=whatsapp&subtab=config');
                } finally { $s->stop(); }
                $i = strpos($html, '🤖 AI Bot Settings'); $j = strpos($html, '<!-- Usage stats for active provider -->');
                return ($i !== false && $j !== false && $j > $i) ? substr($html, $i, $j - $i) : '<no section>';
            };
            $now = $section($ctx->root); $then = $section($base);
            $ctx->t($then !== '<no section>' && strpos($then, VM_KEY) !== false, 'C03 control: 221c9dc\'s section prints the stored key (what South Sudan keeps)');
            $ctx->eq($now, $then, 'C03 the section, byte for byte');
        });
    }],
    // 5.18.104 (root docs/73 §H.3.19, §H.3.26; ruling R1): master and cron_status, read through Part F's one guard (MS8)
    'D' => ['title' => 'master and cron_status: ai_media never dispatched or listed on South Sudan (§H.3.19; MS8)', 'run' => function (QfCtx $ctx) {
        $ss = ms_cfg('south-sudan', VSS_CFG);
        $ug = ms_cfg('uganda');
        $ctx->run('D01 master, every voice key on: ai_media neither runs nor has a schedule row; on Uganda the same keys run it', function () use ($ctx, $ss) {
            $stub = ms_stub_tree($ctx, $ctx->root, 'vssd');
            [$ran, $sched, $rc] = ms_master_run($ctx, $stub, $ss);
            $ctx->t($rc === 0 && count($ran) > 20, 'D01 control: master runs its other jobs on South Sudan (' . count($ran) . ')');
            $ctx->eq([in_array(MS_SCRIPTS['ai_media'], $ran, true), in_array('ai_media', $sched, true)], [false, false], 'D01 South Sudan: no ai_media run, no row');
            [$ran2] = ms_master_run($ctx, $stub, ['tenant_profile' => 'uganda'] + $ss);
            $ctx->t(in_array(MS_SCRIPTS['ai_media'], $ran2, true), 'D01 control: the same keys on Uganda run ai_media');
        });
        $ctx->run('D02 cron_status: never listed on South Sudan, with every voice key on; OFF (switch) on Uganda while dark', function () use ($ctx, $ss, $ug) {
            [$rc, $out] = ms_status($ctx, $ctx->root, $ss, ['--all']);
            $rows = ms_rows($out);
            $ctx->t($rc === 0 && count($rows) > 30, 'D02 control: --all lists South Sudan\'s jobs (' . count($rows) . ')');
            $ctx->eq(array_key_exists('ai_media', $rows), false, 'D02 South Sudan: ai_media is not listed');
            [, $outU] = ms_status($ctx, $ctx->root, $ug, ['--all']);
            $ctx->eq(ms_rows($outU)['ai_media'] ?? null, 'OFF (switch)', 'D02 Uganda, ai_media_enabled unset: listed OFF (switch)');
        });
    }],
];

qf_run_sections($ctx, $SECTIONS, $O['sections']);

if ($O['mutants']) {
    qf_mutants($ctx, __FILE__, [
        ['label' => 'V1 MediaPolicy::enabled without StaffJobsGate (the worker fetches on South Sudan)', 'file' => 'lib/MediaPolicy.php',
         'old' => '        return StaffJobsGate::applies($config, $dataDir);', 'new' => '        return true;', 'sections' => 'C', 'cases' => 'C01', 'pattern' => '/C01 south-sudan/'],
        ['label' => 'SS-GATE step 8c on every tenant, both of its gates gone (the webhook\'s and MediaPolicy\'s)', 'edits' => [
            ['evo_webhook.php', "            \$_evoVoiceUg = StaffJobsGate::applies(is_array(\$config) ? \$config : [], \$dataDir);\n            if (\$_evoVoiceUg) {\n                require_once __DIR__ . '/lib/MediaPolicy.php';\n                require_once __DIR__ . '/lib/InboundMedia.php';",
             "            \$_evoVoiceUg = true;\n            if (\$_evoVoiceUg) {\n                require_once __DIR__ . '/lib/MediaPolicy.php';\n                require_once __DIR__ . '/lib/InboundMedia.php';"],
            ['lib/MediaPolicy.php', '        return StaffJobsGate::applies($config, $dataDir);', '        return true;']],
         'sections' => 'A', 'pattern' => '/A0[23] /'],
        ['label' => 'SS-TAB the key hidden on every tenant', 'file' => 'tabs/engage/whatsapp.php',
         'old' => '        $_aiKeyUg = StaffJobsGate::applies(is_array($config ?? null) ? $config : [], $dataDir ?? null);', 'new' => '        $_aiKeyUg = true;',
         'sections' => 'C', 'cases' => 'C03', 'pattern' => '/C03 the section/'],
        // 5.18.104 (root docs/73 §H.3.24 V8, V9; ruling R1): master and cron_status, read through MS8
        ['label' => 'V8 master without the uganda check (South Sudan dispatches ai_media)', 'file' => 'cron/master.php',
         'old' => "    if (!empty(\$_m_job['uganda']) && !StaffJobsGate::applies(is_array(\$_m_config) ? \$_m_config : [], \$dataDir)) continue;\n", 'new' => "\n",
         'sections' => 'D', 'cases' => 'D01', 'pattern' => '/D01 South Sudan/'],
        ['label' => 'V9 cron_status without the uganda filter (ai_media listed on South Sudan)', 'file' => 'tools/cron_status.php',
         'old' => "        if (preg_match(\"/'uganda'\\s*=>\\s*true/\", \$line) && !StaffJobsGate::applies(\$gateCfg, \$dataDir)) continue;\n", 'new' => '',
         'sections' => 'D', 'cases' => 'D02', 'pattern' => '/D02 South Sudan/'],
        ['label' => 'V9b cron_status not listing a dark ai_media as OFF (switch) on Uganda', 'file' => 'tools/cron_status.php',
         'old' => "        \$r['off'] ? 'OFF (switch)' : (\$r['died']", 'new' => "        false ? 'OFF (switch)' : (\$r['died']",
         'sections' => 'D', 'cases' => 'D02', 'pattern' => '/D02 Uganda/'],
        // B10-fix1 (B10-R02): the marks' gate taken from one path at a time — only the payload that takes that path can catch it
        ['label' => 'SS-W2 a seen mark written on South Sudan for an inbound (the wrapped-inbound path, W2)', 'file' => 'lib/WaSeenMarks.php',
         'old' => "            if (!self::uganda(\$config, \$dataDir)) return;\n            if ((\$direction",
         'new' => "            if (\$direction !== 'in' && !self::uganda(\$config, \$dataDir)) return;\n            if ((\$direction",
         'sections' => 'A', 'cases' => 'A04', 'pattern' => '/A04 South Sudan: the wrapped inbound left no mark/'],
        ['label' => 'SS-W3 the @lid count written on South Sudan (W3)', 'file' => 'lib/WaSeenMarks.php',
         'old' => "            if (!self::uganda(\$config, \$dataDir)) return;\n            if (\$waMessageId === '') return;",
         'new' => "            if (\$waMessageId === '') return;",
         'sections' => 'A', 'cases' => 'A04', 'pattern' => '/A04 South Sudan: the @lid message/'],
        ['label' => 'SS-RUNNER the runner off its Uganda gate', 'file' => 'run_media_worker.php',
         'old' => "if (!StaffJobsGate::applies(\$config, \$dataDir)) return;\n", 'new' => '',
         'sections' => 'C', 'cases' => 'C02', 'pattern' => '/C02 /'],
    ], $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

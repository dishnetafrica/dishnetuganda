<?php
declare(strict_types=1);
/**
 * Section L of tests/test_voice_ack.php — VA-ON1, step 2's check (5.18.104, root docs/73 §H.3.20; ruling R18; VK6's,
 * VK9's, VK10's, VK13's and VK14's VA-ON1 halves; T17's VA-ON1 form, T18, T19, T26, T27's VA-ON1 form). TEST ONLY.
 * Each acknowledgement is the real reply worker's against the fake Evolution, at a fixed Kampala moment; a fault is a
 * one-shot SQLite trigger on the worker's own connection. Each world-moved case has its act correct and the world
 * changed after it: the check reads PASS; each control, planted on a copy of the book through its own connection, reads
 * FAIL. total_changes() on $ro stays 0 throughout. L06 (B6-fix1) is the other way round: an event that died with no
 * decision is no correct act, so it reads FAIL, and its control on a copy, the decision planted, reads PASS.
 */
$VA_SECTIONS['L'] = ['title' => 'VA-ON1: each acknowledgement judged by what it applied, its alert, its ledger pair, its echo; undecided events counted', 'run' => function (QfCtx $ctx): void {
    va_load($ctx);
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'L00 the fake Evolution started');
    if ($evo === '') return;
    $day = 86400;
    $fresh = function (array $over = []) use ($ctx, $evo): array {
        qf_http('GET', $evo . '/__test/reset');
        qf_http('GET', $evo . '/__test/fail_instance?clear=1');
        return vm_book($ctx, va_cfg($evo, $over));
    };
    $ack = function (array $b, string $phone, int $t, array $o = []) {
        $n = va_note($b, $phone, $t, $o);
        va_run($b, $t, $o['run'] ?? []);
        return $n;
    };
    $va = function (array $b, int $now, array $cfgOver = []) {
        return va_check($b, 'VA-ON1', $now, array_merge($b['cfg'], $cfgOver));
    };
    $ok = function (array $r): bool { return $r['status'] === 'PASS' && $r['_changes'] === 0; };
    // A planted acknowledgement, sent, with its ledger row and its alert: a fixture whose only fault is the one named.
    $plant = function (array $b, array $d, array $l, bool $alert = true, bool $ledger = true): void {
        $conv = (int)(new ConversationService($b['data'], $b['pdo']))->ensureConversation((string)$d['phone'], (string)($d['channel'] ?? 'sales'))['id'];
        $at = (int)$l['at'];
        $wa = (string)$d['wa'];
        if ($ledger) va_put($b, 'wa_auto_sends', ['handset' => $l['handset'] ?? 'i:sales-ug', 'kind' => 'voice_ack', 'ref' => 'ack:' . $wa, 'try' => 1, 'mode' => 'claim',
            'proactive' => 0, 'local_day' => WaSendBudget::localDay($at), 'gap_s' => 60, 'claimed_at' => UtcClock::stamp($at),
            'outcome' => $l['outcome'] ?? 'sent', 'settled_at' => UtcClock::stamp($at), 'wa_message_id' => $l['id'] ?? ('VA-SENT-' . $wa),
            'detail' => $l['detail'] ?? null, 'win_from' => $l['win_from'] ?? 7, 'win_to' => $l['win_to'] ?? 21, 'cap_day' => $l['cap_day'] ?? 25]);
        va_put($b, 'wa_voice_acks', ['wa_message_id' => $wa, 'conversation_id' => $conv, 'channel' => $d['channel'] ?? 'sales', 'handset' => $l['handset'] ?? 'i:sales-ug',
            'outcome' => $d['outcome'] ?? 'sent', 'reason' => $d['reason'] ?? null, 'variant' => 'A', 'persona' => 0, 'ledger_ref' => 'ack:' . $wa,
            'period_hours' => $d['period'] ?? 24, 'gap_s' => $d['gap'] ?? 20, 'local_day' => WaSendBudget::localDay($at),
            'created_at' => UtcClock::stamp($at), 'updated_at' => UtcClock::stamp($at + 1)]);
        if ($alert) va_put($b, 'events', ['event_type' => 'wa.escalation', 'entity_type' => 'conversation', 'entity_id' => $conv, 'payload' => '{}',
            'status' => 'done', 'created_by' => 'voice_ack', 'created_at' => UtcClock::stamp($at + 1), 'processed_at' => UtcClock::stamp($at + 2)]);
    };

    $ctx->run('L01 the limits each acknowledgement applied, the settings narrowed afterwards (VK9; T18)', function () use ($ctx, $evo, $fresh, $ack, $va, $ok, $plant, $day) {
        $b = $fresh(['wa_voice_ack_daily_max' => '3', 'sf_persona_confirmed' => '2026-11-03', 'wa_voice_ack_since' => '2026-11-02 00:00:00']);
        $owner = ['sales' => ['role' => 'sales', 'owner_type' => 'staff', 'owner_staff_id' => 7, 'status' => 'active']];
        $b['store']->save('retailers.json', [['id' => 7, 'name' => 'Vee Seller', 'phone' => '0700000777', 'role' => 'sales', 'is_active' => true]]);
        $acts = [];
        $acts['p1'] = $ack($b, '256771400802', qf_t('2026-11-03 10:00:00'), ['channel' => 'support']);
        $list = WaSendBudget::forInstall($b['cfg'], $b['data'], $b['store'], null, function () { return qf_t('2026-11-04 11:00:00'); });
        $lc = $list->claim('DishNet-UG', 'staff_list', 'list:VA-L01:1');
        $list->settle((int)($lc['claim'] ?? 0), 'sent', ['wa_message_id' => 'VA-LIST-L01']);   // the list went: a send on the handset
        $acts['gap'] = $ack($b, '256771400803', qf_t('2026-11-04 11:00:25'), ['channel' => 'support']);           // 25 s after a list: above 20, below 60
        $acts['persona'] = $ack($b, '256771400807', qf_t('2026-11-04 12:00:00'), ['run' => ['before' => function ($w) use ($b, $owner) {
            $e = new VaEvo($b['cfg']); $e->rows = $owner; va_use_evo($w, $e); }]]);
        $acts['p2'] = $ack($b, '256771400802', qf_t('2026-11-04 16:00:00'), ['channel' => 'support']);            // 30 h later: outside 24, inside 48
        $acts['late'] = $ack($b, '256771400801', qf_t('2026-11-04 20:30:00'));                                   // inside 07–21, outside a later 07–20
        foreach (['256771400804', '256771400805', '256771400806'] as $i => $p) {
            $acts['d' . $i] = $ack($b, $p, qf_t('2026-11-05 09:00:00') + 60 * $i, ['channel' => 'support']);       // three on one handset in a day
        }
        $outs = array_map(function ($n) use ($b) { return va_outcome($b, $n['wa']); }, $acts);
        $ctx->eq(array_values(array_unique($outs)), ['sent'], 'L01 control: every act was a send the limits allowed', json_encode($outs));
        $ctx->eq((int)(va_dec($b, $acts['persona']['wa'])['persona'] ?? -1), 1, 'L01 control: the persona acknowledgement carries its flag');
        $narrow = ['wa_voice_ack_hour_to' => '20', 'wa_voice_ack_period_hours' => '48', 'wa_voice_ack_daily_max' => '2', 'wa_voice_ack_gap_s' => '60',
                   'sf_persona_confirmed' => '2026-11-06'];
        $r = $va($b, qf_t('2026-11-06 12:00:00'), $narrow);
        $ctx->t($ok($r), 'L01 VA-ON1 reads PASS after every limit was narrowed', json_encode($r));
        $ctx->eq([$r['counts']['persona_before_date'] ?? 0, $r['counts']['limits_judged'] ?? 0], [1, 8], 'L01 it prints the persona count 1; eight acknowledgements judged');
        foreach (['window' => [['outcome' => 'sent'], ['at' => qf_t('2026-11-05 21:10:00'), 'win_to' => 21, 'handset' => 'i:h-w'], 'limit_window'],
                  'period' => [['period' => 24], ['at' => qf_t('2026-11-03 20:00:00'), 'handset' => 'i:h-p'], 'limit_period'],
                  'ceiling' => [[], ['at' => qf_t('2026-11-05 09:03:00'), 'handset' => 'i:dishnet-ug', 'cap_day' => 3], 'limit_ceiling'],
                  'gap' => [['gap' => 20], ['at' => qf_t('2026-11-04 11:00:05'), 'handset' => 'i:dishnet-ug'], 'limit_gap']] as $k => [$d, $l, $code]) {
            $c = va_copy($ctx, $b);
            $phone = $k === 'period' ? '256771400802' : '25677140089' . strlen($k);
            $plant($c, $d + ['phone' => $phone, 'wa' => 'VA-L01-' . $k, 'channel' => $k === 'period' ? 'support' : 'sales'], $l);
            $rc = $va($c, qf_t('2026-11-06 12:00:00'), $narrow);
            $ctx->t(strpos($rc['status'], 'FAIL') === 0 && ($rc['counts'][$code] ?? 0) >= 1, "L01 control on a copy: a {$k} broken by its own recorded value reads FAIL ({$code})", json_encode($rc['status']) . json_encode($rc['counts']));
        }
    });

    $ctx->run('L02 the echo by identity: a colleague\'s Inbox reply after an acknowledgement is a count (VK10; T19)', function () use ($ctx, $fresh, $ack, $va, $ok) {
        $b = $fresh();
        $n = $ack($b, '256771400811', va_noon());
        va_put($b, 'wa_messages', ['conversation_id' => $n['conv'], 'direction' => 'out', 'role' => 'agent', 'body' => 'VA colleague (test)',
            'agent_name' => 'Vee Colleague', 'event_key' => 'wa_staff_reply', 'wa_message_id' => 'VA-INBOX-811',
            'sent_at' => UtcClock::stamp(va_noon() + 120), 'created_at' => UtcClock::stamp(va_noon() + 120)]);
        $b['pdo']->prepare("UPDATE wa_conversations SET state = 'human_active', last_human_reply_at = ? WHERE id = ?")->execute([UtcClock::stamp(va_noon() + 120), $n['conv']]);
        $r = $va($b, va_noon() + 2 * 86400);
        $ctx->t($ok($r) && ($r['counts']['person_after_ack inbox_reply'] ?? 0) === 1, 'L02 PASS, one Inbox reply counted', json_encode($r));
        $c = va_copy($ctx, $b);
        $sent = (string)(va_ledger($c, $n['wa'])[0]['wa_message_id'] ?? '');
        $c['pdo']->prepare('UPDATE wa_messages SET wa_message_id = ? WHERE wa_message_id = ?')->execute(['VA-OWN-' . $sent, $sent]);
        va_put($c, 'wa_messages', ['conversation_id' => $n['conv'], 'direction' => 'out', 'role' => 'agent', 'body' => 'echo', 'agent_name' => 'Team',
            'wa_message_id' => $sent, 'created_at' => UtcClock::stamp(va_noon() + 3)]);
        $ctx->eq($va($c, va_noon() + 2 * 86400)['status'], 'FAIL echo_as_person', 'L02 control on a copy: a Team row with the acknowledgement\'s own id reads FAIL');
    });

    $ctx->run('L03 the ledger pairs the design produces are counted, never FAIL (VK13; T26)', function () use ($ctx, $evo, $fresh, $va, $ok, $plant, $day) {
        $b = $fresh();
        $faults = ['s14' => 0, 'store' => 0, 'settle' => 0];
        $b['pdo']->sqliteCreateFunction('va_fault', function ($k) use (&$faults) { if (($faults[$k] ?? 0) > 0) { $faults[$k]--; return 1; } return 0; }, 1);
        $b['pdo']->exec("CREATE TEMP TRIGGER va_f14 BEFORE INSERT ON main.wa_voice_acks WHEN NEW.outcome = 'claimed' AND va_fault('s14') = 1
                         BEGIN SELECT RAISE(ABORT, 'test: database is locked'); END");
        $b['pdo']->exec("CREATE TEMP TRIGGER va_fst BEFORE INSERT ON main.wa_messages WHEN NEW.metadata LIKE '%voice_ack%' AND va_fault('store') = 1
                         BEGIN SELECT RAISE(ABORT, 'test: database is locked'); END");
        $b['pdo']->exec("CREATE TEMP TRIGGER va_fse BEFORE UPDATE OF outcome ON main.wa_auto_sends WHEN NEW.kind = 'voice_ack' AND va_fault('settle') = 1
                         BEGIN SELECT RAISE(ABORT, 'test: database is locked'); END");
        $inst = ['h1' => 'H1-UG', 'h2' => 'H2-UG', 'h5' => 'H5-UG'];
        $on = function (string $ch) use ($b, $inst) { return ['before' => function ($w) use ($b, $inst) { $e = new VaEvo($b['cfg']); $e->reg = false; $e->inst = $inst; va_use_evo($w, $e); }]; };
        $T = va_noon();
        $lo = function (array $b, string $wa) { $l = va_ledger($b, $wa); return $l === [] ? 'none' : $l[0]['outcome']; };
        // The first: step 14's INSERT throws after the claim committed; the retry answers in_flight, then past 600 s stale.
        $faults['s14'] = 1;
        $n1 = va_note($b, '256771400821', $T, ['channel' => 'h1', 'instance' => 'H1-UG']);
        va_run($b, $T, $on('h1'));
        va_run($b, $T + 30, $on('h1'));
        va_run($b, $T + 700, $on('h1'));
        // The second: storeMessage throws after the settle: the decision stays claimed beside a ledger row settled sent.
        $faults['store'] = 1;
        $n2 = va_note($b, '256771400822', $T + 800, ['channel' => 'h2', 'instance' => 'H2-UG']);
        va_run($b, $T + 800, $on('h2'));
        // The fifth: the settle throws once and does not throw into P5: sent beside a row in flight.
        $faults['settle'] = 1;
        $n5 = va_note($b, '256771400825', $T + 900, ['channel' => 'h5', 'instance' => 'H5-UG']);
        va_run($b, $T + 900, $on('h5'));
        // The fourth: a late settle, as the sweep leaves it (planted: a send held past 900 s).
        $plant($b, ['phone' => '256771400824', 'wa' => 'VA-L03-4', 'channel' => 'sales'], ['at' => $T + 950, 'outcome' => 'uncertain', 'detail' => 'stale_orphan', 'handset' => 'i:h4']);
        $ctx->eq([va_outcome($b, $n1['wa']), $lo($b, $n1['wa']), va_outcome($b, $n2['wa']), $lo($b, $n2['wa']),
                  va_outcome($b, $n5['wa']), $lo($b, $n5['wa'])],
                 ['skipped:stale', null, 'claimed', 'sent', 'sent', null], 'L03 control: the three faults left the pairs they leave');
        $now = $T + 960;
        $r = $va($b, $now);
        $ctx->t($ok($r) && ($r['counts']['in_progress'] ?? 0) === 1 && ($r['counts']['settle_not_landed'] ?? 0) === 1 && ($r['counts']['claim_before_decision'] ?? 0) === 1
                && ($r['counts']['late_settle'] ?? 0) === 1, 'L03 run at once: PASS, in_progress 1, settle_not_landed 1, claim_before_decision 1, late_settle 1', json_encode($r));
        foreach (['H1-UG' => 'list:VA-L03:1', 'H5-UG' => 'list:VA-L03:5'] as $h => $ref) {
            WaSendBudget::forInstall($b['cfg'], $b['data'], $b['store'], null, function () use ($T, $day) { return $T + 2 * $day; })->claim($h, 'staff_list', $ref);
        }
        $r2 = $va($b, $T + 2 * $day + 60);
        $ctx->eq([va_ledger($b, $n1['wa'])[0]['detail'] ?? null, va_ledger($b, $n5['wa'])[0]['detail'] ?? null], ['stale_orphan', 'stale_orphan'], 'L03 control: the later claims swept both rows');
        $ctx->t($ok($r2) && ($r2['counts']['claim_before_decision'] ?? 0) === 1 && ($r2['counts']['bookkeeping_failed'] ?? 0) === 1 && ($r2['counts']['late_settle'] ?? 0) === 2,
                'L03 two days on, after the sweeps: PASS, claim_before_decision 1, bookkeeping_failed 1, late_settle 2', json_encode($r2));
        foreach (['a skipped decision beside a ledger row sent' => [['outcome' => 'skipped', 'reason' => 'quiet_hours'], ['outcome' => 'sent']],
                  'a sent decision with no ledger row' => [['outcome' => 'sent'], null], 'a claimed decision with none' => [['outcome' => 'claimed'], null]] as $label => [$d, $l]) {
            $c = va_copy($ctx, $b);
            $plant($c, $d + ['phone' => '256771400829', 'wa' => 'VA-L03-C-' . md5($label)], ($l ?? []) + ['at' => $T + 2 * $day, 'handset' => 'i:h9'], true, $l !== null);
            $ctx->eq($va($c, $T + 2 * $day + 60)['status'], 'FAIL ledger_mismatch', "L03 control on a copy: {$label} reads FAIL");
        }
    });

    $ctx->run('L04 an event not yet decided is a count, split by ai_enabled, never FAIL (VK14; T27)', function () use ($ctx, $fresh, $va, $ok, $day) {
        $b = $fresh();
        WaSendBudget::forInstall($b['cfg'], $b['data'], $b['store'], null, function () { return va_noon(); })->claim('Sales-UG', 'staff_list', 'list:VA-L04:1');
        $n = va_note($b, '256771400831', va_noon() + 5);
        va_run($b, va_noon() + 5);
        $ev = vm_q($b, 'SELECT status, error FROM events WHERE id = ?', [$n['event']])[0] ?? [];
        $ctx->eq([$ev['status'] ?? null, $ev['error'] ?? null], ['pending', 'voice_ack: handset_busy'], 'L04 control: the acknowledgement deferred behind the list in flight');
        $r = $va($b, va_noon() + 7 * $day, ['ai_enabled' => false]);
        $ctx->t($ok($r) && ($r['counts']['undecided ai_enabled_off'] ?? 0) === 1, 'L04 seven days on with ai_enabled off: PASS, undecided 1 under ai_enabled off', json_encode($r));
        $c = va_copy($ctx, $b);
        $c['pdo']->prepare("UPDATE events SET status = 'done', processed_at = ? WHERE id = ?")->execute([UtcClock::stamp(va_noon() + 6), $n['event']]);
        $ctx->eq($va($c, va_noon() + 7 * $day)['status'], 'FAIL coverage', 'L04 control on a copy: the event done with no decision reads FAIL');
    });

    $ctx->run('L05 the event window: an acknowledgement older than 29 days whose alert was pruned is a count (VK6; T17)', function () use ($ctx, $fresh, $ack, $va, $ok, $day) {
        $b = $fresh(['wa_voice_ack_since' => UtcClock::stamp(va_noon() - 40 * $day)]);
        $old = $ack($b, '256771400841', va_noon() - 35 * $day);
        $new = $ack($b, '256771400842', va_noon() - 2 * $day);
        $b['pdo']->exec("UPDATE events SET status = 'done', processed_at = created_at WHERE event_type = 'wa.escalation'");
        $pruned = (new EventBus($b['pdo']))->prune(30);
        $ctx->eq([va_outcome($b, $old['wa']), va_outcome($b, $new['wa']), $pruned >= 1,
                  (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n']], ['sent', 'sent', true, 1],
                 'L05 control: two acknowledgements; the live prune deleted the old one\'s events');
        $r = $va($b, va_noon());
        $ctx->t($ok($r) && ($r['counts']['outside_event_window'] ?? 0) === 1, 'L05 PASS, outside_event_window 1', json_encode($r));
        $c = va_copy($ctx, $b);
        $c['pdo']->exec("DELETE FROM events WHERE event_type = 'wa.escalation'");
        $ctx->eq($va($c, va_noon())['status'], 'FAIL alert_missing', 'L05 control on a copy: the 2-day-old\'s alert deleted reads FAIL');
    });

    // B6-fix1 (B6-R02; notes/B6.md reading 7): an event that died with no decision is no correct act — every attempt of P5
    // failed to record what it decided — so D's Coverage (D 5405: "every ai.voice_ack event since since that is done or dead
    // has one decision row") reads FAIL, never a count; onDead records nothing, tells nobody and sends nothing (F06).
    $ctx->run('L06 a dead acknowledgement event with no decision reads FAIL coverage, never a count (D 5405; B6-R02)', function () use ($ctx, $evo, $fresh, $va, $ok, $day) {
        $b = $fresh();
        $T = va_noon();
        $n = va_note($b, '256771400861', $T);
        // Its last attempt, past 600 s: step 6's skip writes the decision, and the store refuses that one INSERT (a busy
        // timeout, one-shot: the store takes writes again after it, so a write onDead made would land).
        $left = 1;
        $b['pdo']->sqliteCreateFunction('va_fault1', function () use (&$left) { if ($left > 0) { $left--; return 1; } return 0; }, 0);
        $b['pdo']->exec("CREATE TEMP TRIGGER va_fdead BEFORE INSERT ON main.wa_voice_acks WHEN va_fault1() = 1
                         BEGIN SELECT RAISE(ABORT, 'test: database is locked'); END");
        $b['pdo']->prepare('UPDATE events SET attempts = max_attempts - 1 WHERE id = ?')->execute([$n['event']]);
        try { va_run($b, $T + 700); } finally { $b['pdo']->exec('DROP TRIGGER IF EXISTS temp.va_fdead'); }
        $ctx->eq([$left, vm_q($b, 'SELECT status FROM events WHERE id = ?', [$n['event']])[0]['status'] ?? null, va_ledger($b, $n['wa']),
                  (int)vm_q($b, "SELECT COUNT(*) n FROM events WHERE event_type = 'wa.escalation'")[0]['n'], vm_texts($evo)],
                 [0, 'dead', [], 0, []], 'L06 control: the fault fired once; the event dead, no ledger row, nobody told, nothing sent');
        $ctx->eq(va_outcome($b, $n['wa']), 'none', 'L06 onDead recorded no decision of its own');
        $r = $va($b, $T + 2 * $day);
        $und = array_filter(array_keys($r['counts'] ?? []), function ($k) { return strncmp((string)$k, 'undecided', 9) === 0; });
        $ctx->eq([$r['status'], $r['counts']['coverage_missing'] ?? 0, array_values($und), $r['_changes']], ['FAIL coverage', 1, [], 0],
                 'L06 VA-ON1 reads FAIL coverage, coverage_missing 1, nothing counted undecided', json_encode($r));
        $c = va_copy($ctx, $b);
        va_put($c, 'wa_voice_acks', ['wa_message_id' => $n['wa'], 'conversation_id' => $n['conv'], 'channel' => 'sales', 'handset' => 'i:sales-ug',
            'outcome' => 'skipped', 'reason' => 'stale', 'local_day' => WaSendBudget::localDay($T + 700),
            'created_at' => UtcClock::stamp($T + 700), 'updated_at' => UtcClock::stamp($T + 700)]);
        $rc = $va($c, $T + 2 * $day);
        $ctx->t($ok($rc), 'L06 control on a copy: the decision its retry would have written (skipped:stale) reads PASS', json_encode($rc));
    });
}];

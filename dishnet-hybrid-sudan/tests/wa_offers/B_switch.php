<?php
declare(strict_types=1);
/**
 * Section B — the switch's callables (5.18.104, root docs/73 §H.4.6, §H.4.16 B; rulings R5, R6, R7, R12, R13, R18, R19).
 * TEST ONLY.
 *
 * OfferSwitch's four callables as the ladder calls them, each on the tool's two connections (ruling R13): the
 * precondition of step 11 (P-O1-1, P-O1-3 … P-O1-9) and of step 12 (every setting in range), each refusal with its code
 * and nothing written on $ro; --check offers-test (C-O1-1 … C-O1-6) and --check offers-live (C-O2-*) over planted pass,
 * fail and not-yet books, printing only the ruling R6 vocabulary, with total_changes() == 0 on $ro; the off-hook before
 * the keys clear: it pauses, settles every claimed recipient with a ledger claim on Part F's ledger over $rw and returns
 * one with none to queued (ruling R12), sweeps a stale test, waits for the sender's lock and throws without it; the
 * test send decided on its own fresh read of the files (ruling R19); and --check offers-live over a real run in which
 * Evolution's echo beat the claim (B13, OM78's B half). The tool's rows, its Needs and ORDER, the cascade
 * and its sequence (P-O1-2, `skip tracking`, `off all --rollback`, TL14) are stage B9's, which builds the tool and the
 * ladder; until then lib/SalesFollowupLadder.php's key table is stood in for by wo_ladder_stub().
 */
require_once __DIR__ . '/checks_lib.php';
require_once __DIR__ . '/b_tool.php';   // 5.18.104 (root docs/73 §H.4.16 B), B9-fix1: the tool half

/** A Uganda book on which step 11's own precondition holds: an admin with a phone, ruling R1's lines planted. */
function wo_bbook(QfCtx $ctx, array $over = [], string $tag = 'b'): array
{
    wo_ladder_stub($ctx);
    wo_master_plant($ctx, true);
    $b = wo_book($ctx, wo_cfg($over), $tag);
    wo_admins($b);
    return $b;
}

$WO_SECTIONS['B'] = ['title' => 'The switch\'s callables: preconditions, the two checks, the off-hook (§H.4.6; ruling R13)', 'run' => function (QfCtx $ctx) {
    $T0 = wo_t0();
    $vocab = '/^(PASS|FAIL [a-z_]+|NOT_YET (too_soon|no_traffic|no_test))$/';
    try {

    $ctx->run('B01 control: step 11\'s own precondition holds on a sound Uganda book, and reads nothing it may not', function () use ($ctx) {
        $b = wo_bbook($ctx);
        $ro = wo_ro($b);
        $n0 = wo_changes($ro);
        $p = OfferSwitch::preconditions($ro, $b['cfg'], $b['data'], 'offers-test');
        $ctx->eq([$p['ok'], $p['code']], [true, null], 'B01 offers-test: ok, no code', json_encode($p['lines']));
        $ctx->eq(count(array_filter($p['lines'], function ($l) { return strpos($l, 'P-O1-') === 0; })), 8, 'B01 one line per own precondition (P-O1-1, -3 … -9)');
        $ctx->eq(wo_changes($ro), $n0, 'B01 total_changes() on $ro unchanged (ruling R13)');
        $p = OfferSwitch::preconditions($ro, $b['cfg'], $b['data'], 'offers-live');
        $ctx->eq([$p['ok'], $p['code']], [true, null], 'B01 offers-live: every setting in range');
        $ctx->eq(OfferSwitch::preconditions($ro, $b['cfg'], $b['data'], 'offers-other')['code'], 'unknown_step', 'B01 an unknown step is refused');
    });

    $ctx->run('B02 each of step 11\'s own preconditions refuses with its code, and $ro is left unchanged', function () use ($ctx) {
        $cases = [];
        $b = wo_bbook($ctx, ['tenant_profile' => 'south-sudan']);            $cases['not_uganda'] = $b;
        $b = wo_bbook($ctx); $b['pdo']->exec('DROP TRIGGER wor_final');     $cases['schema'] = $b;
        $b = wo_bbook($ctx); $b['pdo']->exec('DROP TABLE wa_seen_marks');   $cases['partf_missing'] = $b;
        $b = wo_bbook($ctx, ['timezone' => 'Africa/' . 'Juba']);            $cases['zone'] = $b;
        $b = wo_bbook($ctx); wo_admins($b, [], '');                          $cases['no_admin_phone'] = $b;
        $b = wo_bbook($ctx); $b['store']->save('retailers.json', [['id' => 7, 'name' => 'Sandbox Admin', 'is_admin' => true, 'is_active' => false, 'phone' => '0700990107']]);
        $cases['no_admin_phone '] = $b;
        $b = wo_bbook($ctx); wo_live_offer($b, wo_t0() - 600);               $cases['offer_running'] = $b;
        foreach ($cases as $want => $b) {
            $ro = wo_ro($b);
            $n0 = wo_changes($ro);
            $p = OfferSwitch::preconditions($ro, $b['cfg'], $b['data'], 'offers-test');
            $ctx->eq([$p['ok'], $p['code'], wo_changes($ro) - $n0], [false, trim($want), 0], 'B02 refused: ' . trim($want), json_encode($p['lines']));
        }
        $b = wo_bbook($ctx);
        wo_master_absent($ctx);   // 5.18.104 (root docs/73 §H.4.6 P-O1-6): the lines out, on a tree that holds them too
        $ro = wo_ro($b);
        $p = OfferSwitch::preconditions($ro, $b['cfg'], $b['data'], 'offers-test');
        $ctx->eq([$p['ok'], $p['code']], [false, 'master_lines'], 'B02 refused: master_lines (cron/master.php without ruling R1\'s four lines)');
        wo_master_plant($ctx, true);
        $ctx->t(class_exists('SalesFollowupLadder', false) && property_exists('SalesFollowupLadder', 'drop'),
                'B02 control: the ladder\'s key table is loaded, with its $drop seam (5.18.104 (root docs/73 §H.2.12.4))');
        if (class_exists('SalesFollowupLadder', false) && property_exists('SalesFollowupLadder', 'drop')) {
            foreach (['offers_live_since', 'offers_max_days'] as $k) {
                SalesFollowupLadder::$drop = [$k];
                $p = OfferSwitch::preconditions($ro, $b['cfg'], $b['data'], 'offers-test');
                SalesFollowupLadder::$drop = [];
                $ctx->eq([$p['ok'], $p['code']], [false, 'keys_undeclared'], 'B02 refused: keys_undeclared (' . $k . ' not in the ladder\'s table)');
            }
        }
    });

    $ctx->run('B03 step 12: every setting in range; live() false on an out-of-range value or without offers_live_since', function () use ($ctx) {
        $b = wo_bbook($ctx);
        $ro = wo_ro($b);
        foreach (['offers_warm_days' => 31, 'offers_recent_days' => 6, 'offers_daily_max_per_handset' => 61, 'offers_stop_pause_pct' => 0,
                  'offers_max_days' => 31, 'offers_on_owned_numbers' => 2] as $k => $v) {
            $c = $b['cfg']; $c[$k] = $v;
            $p = OfferSwitch::preconditions($ro, $c, $b['data'], 'offers-live');
            $ctx->eq([$p['ok'], $p['code'], OfferSwitch::live($c, $b['data'])], [false, 'settings_out_of_range', false], 'B03 ' . $k . ' ' . $v . ': refused, and live() false');
        }
        $c = $b['cfg']; $c['offers_daily_max_per_handset'] = 30; $c['wa_send_daily_cap'] = 25;
        $ctx->eq(OfferSwitch::preconditions($ro, $c, $b['data'], 'offers-live')['code'], 'settings_out_of_range', 'B03 the per-handset ceiling above the ledger\'s daily cap: refused');
        $c = $b['cfg']; unset($c['offers_live_since']);
        $ctx->eq([OfferSwitch::live($b['cfg'], $b['data']), OfferSwitch::live($c, $b['data'])], [true, false], 'B03 live(): true, then false without offers_live_since');
        $c = $b['cfg']; unset($c['offers_live_enabled']);
        $ctx->eq([OfferSwitch::screen($c, $b['data']), OfferSwitch::live($c, $b['data'])], [true, false], 'B03 offers-live off: the screen up, live() false (OM2)');
        $c = $b['cfg']; unset($c['offers_test_enabled']);
        $ctx->eq([OfferSwitch::screen($c, $b['data']), OfferSwitch::live($c, $b['data'])], [false, false], 'B03 offers-test off: neither the screen nor live (OM3)');
    });

    $ctx->run('B04 --check offers-test: NOT_YET no_test without a test; PASS with a recorded test of each wording; FAIL schema (C-O1-6)', function () use ($ctx, $T0, $vocab) {
        $b = wo_bbook($ctx);
        $id = wo_offer($b, $T0 - 3600);
        $r = OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0);
        $ctx->eq(wo_st($r), 'NOT_YET no_test', 'B04 a draft and no test: NOT_YET no_test', wo_detail($r));
        wo_ptest($b, $id, 1, $T0 - 600, ['ledger_row' => true]);
        $r = OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0);
        $ctx->eq(wo_st($r), 'NOT_YET no_test', 'B04 one wording of two tested: still NOT_YET no_test', wo_detail($r));
        wo_ptest($b, $id, 2, $T0 - 500, ['ledger_row' => true]);
        $ro = wo_ro($b);
        $n0 = wo_changes($ro);
        $r = OfferSwitch::checkTest($ro, $b['cfg'], $b['data'], $T0);
        $ctx->eq([wo_st($r), wo_counted($r, 'tests recorded'), $r['counts']['offers with every wording tested'] ?? null], ['PASS', 2, 1], 'B04 both wordings tested and recorded: PASS', wo_detail($r));
        $ctx->eq(wo_changes($ro), $n0, 'B04 total_changes() on $ro unchanged');
        $ctx->eq((bool)preg_match($vocab, wo_st($r)), true, 'B04 the status in the ladder\'s vocabulary');
        $b['pdo']->exec("UPDATE wa_offers SET wording_2 = 'A changed second wording, so the content differs.', content_hash = '" . str_repeat('ab', 32) . "' WHERE id = " . $id);
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'NOT_YET no_test', 'B04 the content changed: the old tests no longer count');
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0 + 8 * 86400)), 'NOT_YET no_test', 'B04 eight days later: no test within 7 days');
        $b2 = wo_bbook($ctx);
        $b2['pdo']->exec('DROP TRIGGER woe_never_changed');
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b2), $b2['cfg'], $b2['data'], $T0)), 'FAIL schema', 'B04 C-O1-6: a 091 object missing reads FAIL schema');
        $b3 = wo_bbook($ctx, ['timezone' => 'Africa/' . 'Juba']);
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b3), $b3['cfg'], $b3['data'], $T0)), 'FAIL zone', 'B04 C-O1-6: the zone not Kampala\'s reads FAIL zone');
    });

    $ctx->run('B05 C-O1-2: a late echo, a Team row and an echo not seen within 72 h each FAIL; an older test is not checked', function () use ($ctx, $T0) {
        $mk = function (array $o) use ($ctx, $T0): array {
            $b = wo_bbook($ctx);
            $id = wo_offer($b, $T0 - 3600);
            wo_ptest($b, $id, 1, $T0 - 600, ['ledger_row' => true]);
            $t2 = wo_ptest($b, $id, 2, $o['at'] ?? ($T0 - 500), ['ledger_row' => true] + $o);
            return [$b, $t2];
        };
        [$b] = $mk(['echo' => 'late']);
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'FAIL echo_late', 'B05 a late echo: FAIL echo_late');
        [$b, $t2] = $mk([]);
        $wa = (string)$b['pdo']->query('SELECT wa_message_id FROM wa_offer_tests WHERE id = ' . $t2)->fetchColumn();
        $c = wo_conv($b, '256700990107');
        wo_out($b, $c, 'An offer test (stored as Team by the race).', $T0 - 499, ['agent' => 'Team', 'id' => $wa]);
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'FAIL echo_team', 'B05 a wa_messages row stored as Team with the test\'s id: FAIL echo_team');
        [$b] = $mk(['seen' => false]);
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'FAIL echo_unseen', 'B05 a test within 72 h whose echo the guard never claimed: FAIL echo_unseen');
        [$b] = $mk(['seen' => false, 'at' => $T0 - 4 * 86400]);
        $r = OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0);
        $ctx->eq([wo_st($r), wo_counted($r, 'older than 72 h')], ['PASS', 1], 'B05 a test four days old, its echo row pruned: not checked, printed, PASS', wo_detail($r));
    });

    $ctx->run('B06 C-O1-3 by what each request recorded (ruling R18): swept tests and error answers counted, an unrecorded sent test FAIL', function () use ($ctx, $T0) {
        $b = wo_bbook($ctx);
        $id = wo_offer($b, $T0 - 3600);
        wo_ptest($b, $id, 1, $T0 - 600, ['ledger_row' => true]);
        wo_ptest($b, $id, 2, $T0 - 500, ['ledger_row' => true]);
        $s1 = wo_ptest($b, $id, 1, $T0 - 400, ['state' => 'uncertain', 'ledger' => null, 'wa_message_id' => null]);
        $s2 = wo_ptest($b, $id, 2, $T0 - 300, ['state' => 'uncertain', 'ledger' => null, 'wa_message_id' => null]);
        wo_pledger($b, ['ref' => 'offer_test:' . $s2, 'kind' => 'other', 'mode' => 'record', 'at' => $T0 - 299, 'outcome' => 'sent']);
        $r = OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0);
        $ctx->eq([wo_st($r), wo_counted($r, 'swept tests, with their ledger row'), wo_counted($r, 'swept tests, with no ledger row')], ['PASS', 1, 1],
                 'B06 an uncertain test with ledger NULL, with and without its offer_test row: PASS, each counted (OM118)', wo_detail($r));
        wo_ptest($b, $id, 1, $T0 - 200, ['ledger' => 'error']);
        $r = OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0);
        $ctx->eq([wo_st($r), wo_counted($r, 'answered error')], ['PASS', 1], 'B06 a sent test whose record() answered error: PASS, counted (OM119)', wo_detail($r));
        wo_ptest($b, $id, 2, $T0 - 100, ['ledger' => null]);
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'FAIL test_unrecorded', 'B06 control: a sent test with ledger NULL reads FAIL');
        $b = wo_bbook($ctx);
        $id = wo_offer($b, $T0 - 3600);
        wo_ptest($b, $id, 1, $T0 - 600, ['ledger_row' => true]);
        wo_ptest($b, $id, 2, $T0 - 500, ['ledger' => 'error']);
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'NOT_YET no_test', 'B06 a wording whose only test answered error: NOT_YET no_test (OM120)');
        wo_ptest($b, $id, 2, $T0 - 400, ['ledger_row' => true]);
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'PASS', 'B06 then a recorded test of that wording: PASS');
        foreach (['ledger_off', 'not_uganda', 'no_table'] as $code) {
            $b = wo_bbook($ctx);
            $id = wo_offer($b, $T0 - 3600);
            wo_ptest($b, $id, 1, $T0 - 600, ['ledger_row' => true]);
            wo_ptest($b, $id, 2, $T0 - 500, ['ledger_row' => true]);
            wo_ptest($b, $id, 2, $T0 - 400, ['ledger' => $code]);
            $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'FAIL test_not_recorded', 'B06 a test recorded ' . $code . ': FAIL (never a printed count)');
        }
        $b = wo_bbook($ctx);
        $id = wo_offer($b, $T0 - 3600);
        wo_ptest($b, $id, 1, $T0 - 600);
        wo_ptest($b, $id, 2, $T0 - 500, ['ledger_row' => true]);
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'FAIL test_ledger_missing', 'B06 a test recorded with no offer_test row on its handset: FAIL');
        $b = wo_bbook($ctx);
        $id = wo_offer($b, $T0 - 3600);
        wo_ptest($b, $id, 1, $T0 - 600, ['ledger_row' => true]);
        wo_ptest($b, $id, 2, $T0 - 500, ['ledger_row' => true]);
        wo_ptest($b, $id, 1, $T0 - 1200, ['state' => 'sending', 'ledger' => null, 'wa_message_id' => null]);
        $r = OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0);
        $ctx->eq([wo_st($r), wo_counted($r, 'not yet swept')], ['PASS', 1], 'B06 a test still sending, 20 minutes old: not judged, counted as not yet swept');
    });

    $ctx->run('B07 C-O1-4 before the first Start: each customer trace alone FAIL; after a Start a note, and PASS (OM88, OM89)', function () use ($ctx, $T0) {
        $base = function () use ($ctx, $T0): array {
            $b = wo_bbook($ctx);
            $id = wo_offer($b, $T0 - 3600);
            wo_ptest($b, $id, 1, $T0 - 600, ['ledger_row' => true]);
            wo_ptest($b, $id, 2, $T0 - 500, ['ledger_row' => true]);
            return [$b, $id];
        };
        [$b, $id] = $base();
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'PASS', 'B07 control: tests only, PASS');
        [$b, $id] = $base();
        wo_precip($b, $id, ['phone' => '256700990201']);
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'FAIL recipients_before_start', 'B07 a recipient row before any Start: FAIL');
        [$b, $id] = $base();
        wo_pledger($b, ['ref' => 'offer:' . $id . ':9', 'phone' => '256700990202', 'at' => $T0 - 100]);
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'FAIL offer_ledger_before_start', 'B07 an offer ledger row before any Start: FAIL (OM89)');
        [$b, $id] = $base();
        $b['pdo']->exec("INSERT INTO evo_webhook_seen (message_id, instance, event) VALUES ('WO-B07-SEEN', 'Sales-UG', 'offer.send')");
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'FAIL offer_echo_before_start', 'B07 an offer.send echo before any Start: FAIL');
        [$b, $id] = $base();
        $live = wo_live_offer($b, $T0 - 7200);
        wo_good_send($b, $live, '256700990203', $T0 - 3600);
        $r = OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0);
        $ctx->eq([wo_st($r), count(array_filter($r['notes'], function ($n) { return strpos($n, 'C-O1-4 an offer has started') === 0; }))], ['PASS', 1],
                 'B07 after a Start, the earlier run\'s recipient, ledger and echo rows: a note, and --check offers-test still PASS (OM88)', wo_detail($r));
        $b['pdo']->exec("UPDATE wa_offers SET state = 'paused' WHERE id = " . $live);
        $ctx->eq(wo_st(OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'PASS', 'B07 the offer paused (after off offers-live, or a redeploy): PASS, so step 12 may be met again');
    });

    $ctx->run('B08 --check offers-live: NOT_YET no_traffic before a Start; PASS over a correct send; the vocabulary; $ro unchanged', function () use ($ctx, $T0, $vocab) {
        $b = wo_bbook($ctx);
        $ctx->eq(wo_st(OfferSwitch::checkLive(wo_ro($b), $b['cfg'], $b['data'], $T0)), 'NOT_YET no_traffic', 'B08 no offer started: NOT_YET no_traffic');
        $id = wo_live_offer($b, $T0 - 7200);
        wo_good_send($b, $id, '256700990301', $T0 - 3600);
        $ro = wo_ro($b);
        $n0 = wo_changes($ro);
        $r = OfferSwitch::checkLive($ro, $b['cfg'], $b['data'], $T0);
        $ctx->eq(wo_st($r), 'PASS', 'B08 one correct send: PASS', wo_detail($r));
        $ctx->eq([wo_changes($ro), (bool)preg_match($vocab, wo_st($r))], [$n0, true], 'B08 total_changes() unchanged on $ro; the status in the vocabulary');
        $bad = wo_bbook($ctx);
        $id = wo_live_offer($bad, $T0 - 7200);
        wo_good_send($bad, $id, '256700990302', $T0 - 3600, ['ledger' => false]);
        $r = OfferSwitch::checkLive(wo_ro($bad), $bad['cfg'], $bad['data'], $T0);
        $ctx->eq([wo_st($r), (bool)preg_match($vocab, wo_st($r))], ['FAIL ledger_missing', true], 'B08 a send with no ledger row: FAIL ledger_missing (C-O2-4)', wo_detail($r));
        $late = wo_bbook($ctx);
        $id = wo_live_offer($late, $T0 - 7200);
        wo_good_send($late, $id, '256700990303', $T0 - 3600, ['echo' => 'late']);
        $ctx->eq(wo_st(OfferSwitch::checkLive(wo_ro($late), $late['cfg'], $late['data'], $T0)), 'FAIL echo_late', 'B08 a late echo: FAIL echo_late (C-O2-8; OM78\'s check half)');
    });

    $ctx->run('B09 the off-hook before the keys clear: pauses, settles on $rw\'s ledger, re-queues an unclaimed row, sweeps a test (rulings R7, R12, R13)', function () use ($ctx, $T0) {
        foreach (['switch' => 'paused_by_switch', 'rollback' => 'paused_by_rollback'] as $why => $act) {
            $b = wo_bbook($ctx);
            $id = wo_live_offer($b, time() - 3600);
            $r1 = wo_precip($b, $id, ['phone' => '256700990401', 'state' => 'claimed', 'attempt' => 1, 'claim_token' => 'h1',
                                      'claimed_at' => UtcClock::stamp(time() - 40), 'judged_at' => UtcClock::stamp(time() - 39), 'day_max' => 20, 'variant' => 1]);
            $lid = wo_pledger($b, ['ref' => 'offer:' . $id . ':' . $r1, 'phone' => '256700990401', 'at' => time() - 38, 'outcome' => null]);
            $b['pdo']->exec('UPDATE wa_offer_recipients SET ledger_claim = ' . $lid . ' WHERE id = ' . $r1);
            $r2 = wo_precip($b, $id, ['phone' => '256700990402', 'state' => 'claimed', 'attempt' => 1, 'claim_token' => 'h2', 'claimed_at' => UtcClock::stamp(time() - 20)]);
            $draft = wo_offer($b, time() - 7200);
            $tid = wo_ptest($b, $draft, 1, time() - 1200, ['state' => 'sending', 'ledger' => null, 'wa_message_id' => null]);
            $fresh = wo_ptest($b, $draft, 2, time() - 60, ['state' => 'sending', 'ledger' => null, 'wa_message_id' => null]);
            $c = OfferSwitch::offHook($b['pdo'], $b['cfg'], $b['data'], 'offers-live', $why);
            $ctx->eq($c, ['paused' => 1, 'settled_uncertain' => 1, 'already_settled' => 0, 'requeued' => 1, 'tests_uncertain' => 1], 'B09 ' . $why . ': the hook\'s counts');
            $o = OfferStore::find($b['pdo'], $id);
            $ev = $b['pdo']->query('SELECT action FROM wa_offer_events WHERE offer_id = ' . $id . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
            $ctx->eq([$o['state'], (int)$o['live_slot'], $ev], ['paused', 1, $act], 'B09 ' . $why . ': the running offer paused, live_slot kept, the event ' . $act . ' (OM62)');
            $q = $b['pdo']->query('SELECT state, reason, sent_at = claimed_at FROM wa_offer_recipients WHERE id = ' . $r1)->fetch(\PDO::FETCH_NUM);
            $out = $b['pdo']->query('SELECT outcome, detail FROM wa_auto_sends WHERE id = ' . $lid)->fetch(\PDO::FETCH_NUM);
            $ctx->eq([$q, $out], [['uncertain', 'stale_claim', 1], ['uncertain', null]],
                     'B09 ' . $why . ': the claimed row with a ledger claim uncertain(stale_claim), sent_at its claimed_at; its ledger row uncertain on $rw (OM90)');
            $q = $b['pdo']->query('SELECT state, not_before IS NOT NULL FROM wa_offer_recipients WHERE id = ' . $r2)->fetch(\PDO::FETCH_NUM);
            $ctx->eq($q, ['queued', 1], 'B09 ' . $why . ': the claimed row with no ledger claim back to queued, not_before now (ruling R12)');
            $q = $b['pdo']->query("SELECT id, state FROM wa_offer_tests WHERE id IN ({$tid}, {$fresh}) ORDER BY id")->fetchAll(\PDO::FETCH_NUM);
            $ctx->eq($q, [[$tid, 'uncertain'], [$fresh, 'sending']], 'B09 ' . $why . ': the test sending for 20 minutes uncertain; the one a minute old left');
            $c2 = OfferSwitch::offHook($b['pdo'], $b['cfg'], $b['data'], 'offers-test', $why);
            $ctx->eq($c2, ['paused' => 0, 'settled_uncertain' => 0, 'already_settled' => 0, 'requeued' => 0, 'tests_uncertain' => 0], 'B09 ' . $why . ': step 11\'s hook after step 12\'s finds nothing left');
        }
    });

    $ctx->run('B10 the hook\'s settle on a row already settled is tolerated; resuming is an admin\'s; switching on again resumes nothing', function () use ($ctx, $T0) {
        $b = wo_bbook($ctx);
        $id = wo_live_offer($b, time() - 3600);
        $r1 = wo_precip($b, $id, ['phone' => '256700990501', 'state' => 'claimed', 'attempt' => 1, 'claim_token' => 'h1',
                                  'claimed_at' => UtcClock::stamp(time() - 40), 'judged_at' => UtcClock::stamp(time() - 39), 'day_max' => 20, 'variant' => 1]);
        $lid = wo_pledger($b, ['ref' => 'offer:' . $id . ':' . $r1, 'phone' => '256700990501', 'at' => time() - 38, 'outcome' => 'sent']);
        $b['pdo']->exec('UPDATE wa_offer_recipients SET ledger_claim = ' . $lid . ' WHERE id = ' . $r1);
        $c = OfferSwitch::offHook($b['pdo'], $b['cfg'], $b['data'], 'offers-live', 'switch');
        $out = $b['pdo']->query('SELECT outcome FROM wa_auto_sends WHERE id = ' . $lid)->fetchColumn();
        $ctx->eq([$c['already_settled'], $c['settled_uncertain'], $out], [1, 1, 'sent'], 'B10 a death between steps 9 and 10: the ledger keeps sent, the row uncertain(stale_claim), the hook goes on');
        $ctx->eq((string)OfferStore::find($b['pdo'], $id)['state'], 'paused', 'B10 on offers-live again resumes nothing: the offer stays paused until Resume');
    });

    $ctx->run('B11 the hook waits for the sender\'s lock and throws without it, changing nothing', function () use ($ctx) {
        $b = wo_bbook($ctx);
        $id = wo_live_offer($b, time() - 3600);
        $fp = fopen($b['data'] . '/' . OfferSwitch::LOCK_FILE, 'c');
        flock($fp, LOCK_EX);
        $was = OfferSwitch::$lockWaitS;
        OfferSwitch::$lockWaitS = 1;
        $threw = '';
        try { OfferSwitch::offHook($b['pdo'], $b['cfg'], $b['data'], 'offers-live', 'switch'); } catch (\Throwable $e) { $threw = $e->getMessage(); }
        $ctx->eq([strpos($threw, 'lock') !== false, (string)OfferStore::find($b['pdo'], $id)['state']], [true, 'running'], 'B11 the lock held: the hook throws, the offer still running', $threw);
        flock($fp, LOCK_UN);
        fclose($fp);
        OfferSwitch::$lockWaitS = $was;
        OfferSwitch::offHook($b['pdo'], $b['cfg'], $b['data'], 'offers-live', 'switch');
        $ctx->eq((string)OfferStore::find($b['pdo'], $id)['state'], 'paused', 'B11 control: the lock free, the hook pauses');
        $threw = '';
        try { OfferSwitch::offHook($b['pdo'], $b['cfg'], $b['data'], 'offers-other', 'switch'); } catch (\Throwable $e) { $threw = get_class($e); }
        $ctx->eq($threw, 'InvalidArgumentException', 'B11 an unknown step throws');
    });

    $ctx->run('B12 the test send decides on its own fresh read: the ledger off in the files sends and writes nothing (ruling R19; OM113)', function () use ($ctx, $T0) {
        $b = wo_bbook($ctx);
        $id = wo_offer($b, $T0 - 3600);
        $admin = $b['store']->load('retailers.json')[0];
        $log = new WoLog();
        $gw = new WoGateway($log);
        $budget = new WoBudget($log);
        $s = wo_sender($ctx, $b, new WoClock($T0), $budget, $gw);
        wo_setcfg($b, ['wa_send_ledger' => null]);
        $r = $s->sendTest($id, 1, 'sales', $admin);
        $n = (int)$b['pdo']->query('SELECT COUNT(*) FROM wa_offer_tests')->fetchColumn();
        $ctx->eq([$r['code'], count(wo_calls($log, 'send')), count(wo_calls($log, 'record')), $n], ['ledger_off', 0, 0, 0],
                 'B12 the sender built while the ledger was on, the files then reading it off: ledger_off, no send, no record, no test row');
        wo_setcfg($b, ['wa_send_ledger' => 'on']);
        $r = $s->sendTest($id, 1, 'sales', $admin);
        $ctx->eq([$r['ok'], count(wo_calls($log, 'send')), count(wo_calls($log, 'record'))], [true, 1, 1], 'B12 control: the ledger on, the test goes and is recorded');
        $ctx->eq(wo_calls($log, 'send')[0][2], '256700990107', 'B12 the test goes to the admin\'s own phone of record');
    });

    $ctx->run('B13 the echo that beats the claim, through the real sender, the real gateway and the real webhook: echo late, and --check offers-live FAIL echo_late (OM78\'s B half)', function () use ($ctx, $T0) {
        // B8-fix1 (B8-R03): G's echo-before-claim run (G03) driven by the sender, then the check (D 7846: OM78 "G, B"). A book
        // and a webhook of its own, so G's later cases on the 'main' book never meet this echo's id.
        $stub = wo_stub('ok');                                        // its calls reset: the first send's id is WO-STUB-MSG-1
        $b = wo_whbook($ctx, wo_cfg(['evo_api_url' => $stub]), 'b13');
        if ($b['url'] === '' || $stub === '') { $ctx->t(false, 'B13 control: the webhook and the stub Evolution are served'); return; }
        wo_warm($b, '256700991301', $T0, 86400);
        [$id, $st] = wo_started($b, $T0 - 60, wo_evo_gw($b, $stub));
        $ctx->t($st['ok'], 'B13 control: an offer started through the real gateway');
        $ctx->eq(wo_echo($b['url'], '256700991301', 'WO-STUB-MSG-1', 'The offer, echoed before the claim (test)')[0], 200, 'B13 control: Evolution\'s echo reached the webhook first');
        OfferSender::forInstall($ctx->tree(), $b['data'], $b['cfg'], ['store' => $b['store'], 'clock' => new WoClock($T0), 'wait' => function (int $s): void {}])->runOnce(null);
        $row = wo_row($b['pdo'], '256700991301');
        $ctx->eq([$row['state'] ?? null, $row['echo'] ?? null, $row['wa_message_id'] ?? null], ['sent', 'late', 'WO-STUB-MSG-1'],
                 'B13 control: the sender sent it through the real gateway; the claim found the id already there: echo late');
        $r = OfferSwitch::checkLive(wo_ro($b), $b['cfg'], $b['data'], $T0 + 600);
        $ctx->eq(in_array('echo_late', wo_kfails($r), true), true, 'B13 --check offers-live over that run: FAIL echo_late (C-O2-8; OM78)', wo_detail($r));
    });

    // 5.18.104 (root docs/73 §H.4.16 B; coordinator items 10f, 10g), B9-fix1: the tool half — Part O's rows as the switch tool runs them
    wo_b_tool($ctx);

    } finally {
        wo_master_plant($ctx, false);
    }
}];

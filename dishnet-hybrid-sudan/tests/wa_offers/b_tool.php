<?php
declare(strict_types=1);
/**
 * Section B's tool half (5.18.104, root docs/73 §H.4.6, §H.4.16 B; rulings R5, R6, R7, R10, R13; coordinator items 10f,
 * 10g), B9-fix1: Part O's rows of the one ladder as the switch tool runs them — tools/sales_followup.php, a child process,
 * as the data folder's owner, against a book of its own. Called at the end of section B (tests/wa_offers/B_switch.php).
 * Also the tool forms of K's planted cases KC04 (`set`), KC10 (`off offers-test`, then the real enquiry pass) and KC15
 * (`erase`), which tests/wa_offers/k_checks.php runs through wo_tool(). TEST ONLY; every number and name is fictitious.
 */
foreach (['B_evidence', 'D_policy', 'F_stops', 'G_sender'] as $qfF) require_once dirname(__DIR__) . '/quote_followup/' . $qfF . '.php';   // qf_cron, qf_enquiry
unset($qfF);

/** The judged tree's tool against a book: [exit code, output]. $stdin is typed. */
function wo_tool(QfCtx $ctx, array $b, array $args, string $stdin = ''): array
{
    $cmd = array_merge(['php', $ctx->tree() . '/tools/sales_followup.php'], $args);
    $e = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $e = ['DN_DATA_DIR' => $b['data']] + $e;
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $e);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), (string)$out];
}

/** The data folder's kyc_config.json as the tool left it. */
function wo_kyc(array $b): array
{
    $j = json_decode((string)@file_get_contents($b['data'] . '/kyc_config.json'), true);
    return is_array($j) ? $j : [];
}

/** Every non-optional step up to and including $upto on, each since-stamp $ago seconds back (the ladder's own keys). */
function wo_ladder_cfg(string $upto, int $now, int $ago = 864000): array
{
    $c = [];
    foreach (SalesFollowupLadder::steps() as $s) {
        $row = SalesFollowupLadder::row($s);
        if (!$row['optional']) {
            foreach ($row['switch'] as $k => $v) $c[$k] = $v;
            if ($row['stamp'] !== null) $c[$row['stamp']] = UtcClock::stamp($now - $ago);
            foreach ($row['options'] as $o) $c[$o['key']] = $o['type'] === 'channels' ? 'all' : SalesFollowupLadder::today($now - $ago);
            if ($row['ledger']) { $c['wa_send_ledger'] = 'on'; $c['wa_send_ledger_since'] = UtcClock::stamp($now - $ago); }
        }
        if ($s === $upto) break;
    }
    return $c;
}

/** Part O's keys unset (a null unsets in wo_cfg). */
const WO_OFFERS_OFF = ['offers_test_enabled' => null, 'offers_live_enabled' => null, 'offers_live_since' => null];

/** A running offer with a recipient claimed and its ledger claim in flight, as B09 plants it; [offer, recipient, ledger id]. */
function wo_claimed_offer(array $b, int $at): array
{
    $id = wo_live_offer($b, $at - 3600);
    $r1 = wo_precip($b, $id, ['phone' => '256700991401', 'state' => 'claimed', 'attempt' => 1, 'claim_token' => 'bt1',
                              'claimed_at' => UtcClock::stamp($at - 40), 'judged_at' => UtcClock::stamp($at - 39), 'day_max' => 20, 'variant' => 1]);
    $lid = wo_pledger($b, ['ref' => 'offer:' . $id . ':' . $r1, 'phone' => '256700991401', 'at' => $at - 38, 'outcome' => null]);
    $b['pdo']->exec('UPDATE wa_offer_recipients SET ledger_claim = ' . $lid . ' WHERE id = ' . $r1);
    return [$id, $r1, $lid];
}

function wo_b_tool(QfCtx $ctx): void
{
    $now = time();
    wo_ladder_stub($ctx);                                       // the tree's own ladder (lib/SalesFollowupLadder.php)
    $ctx->t(class_exists('SalesFollowupLadder', false) && method_exists('SalesFollowupLadder', 'steps'), 'B14 control: the tree\'s ladder is loaded (not the stub)');

    $ctx->run('B14 skip tracking through the tool cascades to 11 and 12 (recorded skipped); on offers-test then refused by its need (ruling R6)', function () use ($ctx, $now) {
        $b = wo_bbook($ctx, WO_OFFERS_OFF + wo_ladder_cfg('ack', $now));
        [$rc, $out] = wo_tool($ctx, $b, ['skip', 'tracking', '--reason', 'not tracking quotations yet (test)']);
        $k = wo_kyc($b);
        $ctx->eq([$rc, isset($k['sf_skip_offers-test'], $k['sf_skip_offers-live']), isset($k['sf_skip_list'], $k['sf_skip_chase']), strpos($out, 'also skipped: list, chase, cold, offers-test, offers-live') !== false],
                 [0, true, true, true], 'B14 skip tracking: 11 and 12 recorded skipped with 4, 6 and 10, and said so', $out);
        [$rc, $out] = wo_tool($ctx, $b, ['skip', 'voice-measure', '--reason', 'no voice notes yet (test)']);
        $ctx->eq($rc, 0, 'B14 control: the voice steps recorded skipped too, so the order passes up to step 11', $out);
        [$rc, $out] = wo_tool($ctx, $b, ['on', 'offers-test', '--accept-not-yet', 'a quiet week (test)']);
        $ctx->eq([$rc, strpos($out, 'REFUSED need_off:tracking') !== false, isset(wo_kyc($b)['offers_test_enabled'])], [2, true, false],
                 'B14 on offers-test with tracking recorded skipped: refused by its need, nothing saved', $out);
    });

    $ctx->run('B15 on offers-test through the tool: refused by the ladder\'s order while a lower step is neither on nor skipped; admitted once it is (OM63)', function () use ($ctx, $now) {
        $cfg = WO_OFFERS_OFF + wo_ladder_cfg('voice-reply', $now);
        $b = wo_bbook($ctx, ['quote_daily_list_enabled' => null, 'quote_daily_list_since' => null] + $cfg);
        [$rc, $out] = wo_tool($ctx, $b, ['on', 'offers-test', '--accept-not-yet', 'a quiet week (test)']);
        $ctx->eq([$rc, strpos($out, 'REFUSED order:list') !== false, isset(wo_kyc($b)['offers_test_enabled'])], [2, true, false],
                 'B15 step 4 neither on nor skipped: on offers-test refused by the order, nothing saved', $out);
        $b = wo_bbook($ctx, $cfg);
        [$rc, $out] = wo_tool($ctx, $b, ['on', 'offers-test', '--accept-not-yet', 'a quiet week (test)']);
        $ctx->eq([$rc, wo_kyc($b)['offers_test_enabled'] ?? null], [0, '1'], 'B15 control: every lower step on: admitted and saved', $out);
    });

    $ctx->run('B16 off offers-test through the tool: the cascade clears 12 then 11, each hook before its keys, settling on the tool\'s $rw; both switches and the stamp unset (rulings R7, R13; OM77)', function () use ($ctx, $now) {
        $b = wo_bbook($ctx, wo_ladder_cfg('offers-live', $now));
        [$id, $r1, $lid] = wo_claimed_offer($b, $now);
        [$rc, $out] = wo_tool($ctx, $b, ['off', 'offers-test']);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), function ($l) { return preg_match('/^(OFF |hook )/', $l); }));
        $tags = array_map(function ($l) { return preg_replace('/^(OFF|hook) ([a-z\-]+).*$/', '$1 $2', $l); }, $lines);
        $ctx->eq([$rc, $tags], [0, ['hook offers-live', 'OFF offers-live', 'hook offers-test', 'OFF offers-test']], 'B16 the one sequence: 12\'s hook before 12\'s keys, then 11\'s hook before 11\'s keys', $out);
        $ctx->eq([preg_match('/hook offers-live: paused=1 settled_uncertain=1 /', $out), preg_match('/hook offers-test: paused=0 settled_uncertain=0 /', $out)], [1, 1],
                 'B16 12\'s hook paused the offer and settled the claim; 11\'s, after it, found nothing left', $out);
        $o = OfferStore::find($b['pdo'], $id);
        $q = $b['pdo']->query('SELECT state, reason FROM wa_offer_recipients WHERE id = ' . $r1)->fetch(\PDO::FETCH_NUM);
        $l = $b['pdo']->query('SELECT outcome FROM wa_auto_sends WHERE id = ' . $lid)->fetchColumn();
        $ctx->eq([$o['state'], $q, $l], ['paused', ['uncertain', 'stale_claim'], 'uncertain'], 'B16 the hook ran before the keys cleared: the offer paused, the claimed row and its ledger row uncertain');
        $k = wo_kyc($b);
        $ctx->eq([isset($k['offers_test_enabled']), isset($k['offers_live_enabled']), isset($k['offers_live_since'])], [false, false, false], 'B16 both switches and offers_live_since unset (never written 0)');
    });

    $ctx->run('B17 off all --rollback through the tool: P6\'s hook first in the one sequence, its end state asserted; after off all the six settings keep their values (rulings R5, R7)', function () use ($ctx, $now) {
        $b = wo_bbook($ctx, wo_ladder_cfg('offers-live', $now));
        $six = ['offers_warm_days' => '20', 'offers_recent_days' => '14', 'offers_daily_max_per_handset' => '10', 'offers_on_owned_numbers' => '1',
                'offers_stop_pause_pct' => '7', 'offers_max_days' => '10'];
        foreach ($six as $k => $v) {
            [$rc, $out] = wo_tool($ctx, $b, ['set', $k, $v]);
            $ctx->eq([$rc, wo_kyc($b)[$k] ?? null], [0, $v], 'B17 control: set ' . $k . ' ' . $v . ' through the tool', $out);
        }
        [$id] = wo_claimed_offer($b, $now);
        [$rc, $out] = wo_tool($ctx, $b, ['off', 'all', '--rollback']);
        $first = '';
        foreach (explode("\n", $out) as $l) { if (preg_match('/^\s*(OFF|hook) /', $l)) { $first = trim($l); break; } }
        $ev = $b['pdo']->query('SELECT action FROM wa_offer_events WHERE offer_id = ' . $id . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
        $claimed = (int)$b['pdo']->query("SELECT COUNT(*) FROM wa_offer_recipients WHERE state = 'claimed'")->fetchColumn();
        $ctx->eq([$rc, strpos($first, 'hook offers-live') === 0, $ev, $claimed, strpos($out, 'rollback: every 5.18.104 key is unset') !== false],
                 [0, true, 'paused_by_rollback', 0, true], 'B17 the rollback: P6\'s hook first, the offer paused by it, no recipient claimed, every key unset', $out);
        $k = wo_kyc($b);
        $ctx->eq([array_intersect_key($k, $six), isset($k['offers_test_enabled']) || isset($k['offers_live_enabled']) || isset($k['offers_live_since'])], [$six, false],
                 'B17 after off all: the six settings as set, P6\'s switches and stamp unset');
    });

    $ctx->run('B18 declaredKeys() equals P6\'s part of the ladder: its switches and stamp of allKeys(), its six settings of settingKeys() (ruling R5)', function () use ($ctx) {
        $L = 'SalesFollowupLadder';
        $rows = array_values(array_unique(array_merge($L::rowKeys('offers-test'), $L::rowKeys('offers-live'))));
        $sets = [];
        foreach ($L::SETTINGS as $k => $r) if (($r['part'] ?? '') === 'O') $sets[] = $k;
        $decl = OfferSwitch::declaredKeys();
        $sw = array_values(array_intersect($decl, $L::allKeys()));
        $st = array_values(array_intersect($decl, $L::settingKeys()));
        sort($rows); sort($sets); sort($sw); sort($st);
        $all = array_merge($rows, $sets); $d2 = $decl; sort($all); sort($d2);
        $ctx->eq([count($rows), count($sets)], [3, 6], 'B18 control: the ladder holds three P6 keys in its rows and six P6 settings');
        $ctx->eq([$sw, $st, $d2], [$rows, $sets, $all], 'B18 declaredKeys(): the switches and the stamp equal P6\'s rows of allKeys(), the six settings P6\'s rows of settingKeys(), nothing else');
    });

    $ctx->run('B19 off offers-live then on offers-live through the tool, an offer left running (a hook that did not run): the next run\'s live-since guard pauses it (O-D19; OM76\'s B half)', function () use ($ctx, $now) {
        $b = wo_bbook($ctx, wo_ladder_cfg('offers-live', $now));
        $id = wo_live_offer($b, $now - 7200);
        foreach (array_keys(OfferStore::wordingsOf(OfferStore::find($b['pdo'], $id))) as $v) wo_ptest($b, $id, (int)$v, $now - 5400 + 60 * (int)$v, ['ledger_row' => true]);
        [$rc, $out] = wo_tool($ctx, $b, ['off', 'offers-live']);
        $ctx->eq([$rc, (string)OfferStore::find($b['pdo'], $id)['state']], [0, 'paused'], 'B19 control: off offers-live, its hook pausing the offer', $out);
        $b['pdo']->exec("UPDATE wa_offers SET state = 'running' WHERE id = " . $id);          // as a hook that did not run would leave it
        [$rc, $out] = wo_tool($ctx, $b, ['on', 'offers-live', '--accept-not-yet', 'a quiet week (test)']);
        $since = (string)(wo_kyc($b)['offers_live_since'] ?? '');
        $ctx->eq([$rc, abs(UtcClock::parse($since) - time()) < 300], [0, true], 'B19 control: on offers-live admitted, offers_live_since stamped now', $out);
        $cfg = PluginConfig::read($ctx->tree(), $b['data']);
        $r = OfferSender::forInstall($ctx->tree(), $b['data'], $cfg, ['store' => $b['store'], 'wait' => function (int $s): void {}])->runOnce(null);
        $o = OfferStore::find($b['pdo'], $id);
        $ev = $b['pdo']->query('SELECT action FROM wa_offer_events WHERE offer_id = ' . $id . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
        $claims = (int)$b['pdo']->query("SELECT COUNT(*) FROM wa_offer_recipients WHERE offer_id = {$id} AND state IN ('claimed','sent','uncertain')")->fetchColumn();
        $ctx->eq([$o['state'], $ev, $claims], ['paused', 'paused_by_switch', 0], 'B19 the run after on offers-live: the offer started before offers_live_since paused by the guard, nothing claimed (' . (string)$r['code'] . ')');
    });
}

<?php
declare(strict_types=1);
/**
 * Section K's --check half (5.18.104, root docs/73 §H.4.6, §H.4.16 K; rulings R2, R3, R18): what OfferSwitch::checkLive()
 * and checkTest() read over Part F's real readers (QuoteChase::offerHold, HumanTakeover::phoneSince, its ledger's rows), and
 * over runs of the real sender on Part F's real ledger. Run at the end of section K (K_part_f.php), cases KC01 … KC30.
 * TEST ONLY.
 *
 * B8-fix1: KC20 … KC23 take C-O2-9's own reads clause by clause (the ledger half on Part F's real ledger, the followup_sends
 * half, the opt-out, the person rule), each FAIL with its PASS control and its copy; KC24 is OM69's K half through the real
 * sender; KC25 … KC30 run the cases D specifies over real flows on the rows the real sender and ledger leave — each crash
 * window killed at its step then swept (or the off-hook, or the by-hand put-back), a claim left unswept, a settle that did
 * not land under a real lock, an orphan re-claimed by the close, a follow-up opened by the real followup_scan, a test whose
 * request died — and the planted cases above (KC11 … KC17) stay as their FAIL controls.
 *
 * Every case is a correct act after which the world moved — a row stored later, a setting narrowed, a colleague's Inbox
 * reply, a list or a routing that changed, a record after the offer, a crash window's designed outcome — and reads PASS
 * with what moved counted and printed; each comes with its control, a planted fault that reads FAIL, and with a weakened
 * copy that judges by the current state (tests/wa_offers/mutants_KC.php). The rows are planted in the shapes the sender and
 * Part F's ledger write (tests/wa_offers/checks_lib.php), or written by them. Every number and name is fictitious.
 */
require_once __DIR__ . '/checks_lib.php';
require_once __DIR__ . '/b_tool.php';   // 5.18.104 (root docs/73 §H.4.16 K; coordinator item 10g), B9-fix1: wo_tool() for KC04, KC10, KC15

/** 5.18.104 (coordinator item 10g), B9-fix1: `set` through the switch tool, then the configuration as the files read now. */
function wo_kset(QfCtx $ctx, array $b, array $args): array
{
    [$rc, $out] = wo_tool($ctx, $b, array_merge(['set'], $args));
    if ($rc !== 0) $ctx->t(false, 'KC the tool\'s set ' . implode(' ', $args) . ' was saved', $out);
    return PluginConfig::read($ctx->tree(), $b['data']) + ['_data_dir' => $b['data']];
}

/** A Uganda book with an admin and an offer started three days before T0 (warm_days, recent_days as $o says). */
function wo_kc(QfCtx $ctx, array $over = [], array $o = []): array
{
    $b = wo_book($ctx, wo_cfg($over), 'kc');
    wo_admins($b);
    $oid = wo_live_offer($b, $o['start'] ?? (wo_t0() - 3 * 86400), $o);
    return [$b, $oid];
}

/** --check offers-live on the book's $ro at $now; $cfg the configuration as it reads now (default the book's). */
function wo_klive(array $b, int $now, ?array $cfg = null): array
{
    $ro = wo_ro($b);
    $n0 = wo_changes($ro);
    $r = OfferSwitch::checkLive($ro, $cfg ?? $b['cfg'], $b['data'], $now);
    if (wo_changes($ro) !== $n0) $r['status'] = 'WROTE ON $ro';
    return $r;
}

/** The FAIL codes a check named (its 'failed:' note), in order. */
function wo_kfails(array $r): array
{
    foreach ($r['notes'] as $n) if (strpos($n, 'failed: ') === 0) return explode(', ', substr($n, 8));
    return [];
}

/** A seen-by-the-guard row for every sent recipient without one, as the real gateway's echo claim leaves it. */
function wo_kseen(array $b): void
{
    foreach ($b['pdo']->query("SELECT wa_message_id FROM wa_offer_recipients WHERE state = 'sent' AND wa_message_id IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
        $b['pdo']->prepare("INSERT OR IGNORE INTO evo_webhook_seen (message_id, instance, event) VALUES (?, 'Sales-UG', 'offer.send')")->execute([(string)$id]);
    }
}

/**
 * A death at a statement (B8-fix1, B8-R04): thrown from the prepare of the first statement containing the hook's needle, or
 * from the scripted gateway's call, so what came before it is committed and nothing after it runs. runOnce()'s catch writes
 * nothing to the book (it ends the run 'error'), so the book is left as the process's death would leave it.
 */
final class WoKill extends \RuntimeException
{
}

/**
 * One run of the real sender over Part F's real ledger (no budget seam), on a WoStore so a statement can be hooked, the
 * injected clock and the scripted gateway. $kill: the statement at whose prepare the run dies. $with: a callable given the
 * WoStore before the run (another hook, the clock moved from a load). Its result.
 */
function wo_krun(QfCtx $ctx, array $b, WoClock $clk, WoGateway $gw, ?string $kill = null, ?callable $with = null): array
{
    foreach (['retailers.json', 'kyc_config.json', 'master_schedule.json'] as $f) $b['store']->load($f);   // tables made before any lock
    $store = new WoStore($b['store'], $b['data']);
    if ($kill !== null) $store->pdo->hooks[] = [$kill, function (): void { throw new WoKill('killed'); }];
    if ($with !== null) $with($store);
    return OfferSender::forInstall($ctx->tree(), $b['data'], $b['cfg'], ['store' => $store, 'clock' => $clk,
        'gateway' => function () use ($gw) { return $gw; }, 'wait' => function (int $s): void {}])->runOnce(null);
}

/** The offer ledger rows of a recipient: [outcome, detail, handset]. */
function wo_kled(array $b, int $offerId, int $rid): array
{
    $st = $b['pdo']->prepare('SELECT outcome, detail, handset FROM wa_auto_sends WHERE kind = \'offer\' AND ref = ? ORDER BY id');
    $st->execute(['offer:' . $offerId . ':' . $rid]);
    return $st->fetchAll(\PDO::FETCH_NUM);
}

/**
 * The real cron/followup_scan.php over the book (its own process, the real clock; opened_at its datetime('now') default):
 * the tree's ucrm.json points at the book for the run and is put back after. [exit code, output].
 */
function wo_kscan(QfCtx $ctx, array $b): array
{
    $tree = $ctx->tree();
    $uj = $tree . '/ucrm.json';
    $was = is_file($uj) ? (string)file_get_contents($uj) : null;
    file_put_contents($uj, json_encode(['pluginDataDir' => $b['data']]));
    try {
        $env = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
        $env['DN_DATA_DIR'] = $b['data'];
        $p = proc_open('exec php ' . escapeshellarg($tree . '/cron/followup_scan.php'), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                       $pipes, $tree . '/cron', $env);
        $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($p), $out];
    } finally {
        if ($was === null) @unlink($uj); else file_put_contents($uj, $was);
    }
}

/** A Monday-to-Saturday 10:30 Kampala at least $days days before the real clock (the injected clock behind the real one). */
function wo_kpast(int $days): int
{
    $d = (new \DateTime('@' . (time() - $days * 86400)))->setTimezone(new \DateTimeZone(QF_TZ));
    while ((int)$d->format('N') === 7) $d->modify('-1 day');
    $d->setTime(10, 30, 0);
    return $d->getTimestamp();
}

function wo_k_checks(QfCtx $ctx): void
{
    $T0 = wo_t0();
    $J = $T0 - 3600;            // a correct send's judgement, an hour before T0 (Monday 09:30 Kampala, after the reminders)
    $quote = function (array $b, int $conv, int $at): void {
        wo_out($b, $conv, "Your quotation (test)\n📋 *Ref:* Q-KC-" . $at . "\n⏳ *Valid Until:* 20 Nov 2026", $at, ['agent' => 'DishNet', 'event_key' => 'ops_quote_wa']);
    };
    $mark = function (array $b, string $phone, int $at): void {
        $b['pdo']->prepare("INSERT INTO wa_seen_marks (direction, phone, channel, instance, kind, wa_message_id, seen_at) VALUES ('in', ?, 'sales', 'Sales-UG', 'viewOnceMessage', ?, ?)")
            ->execute([$phone, 'WO-KC-MARK-' . bin2hex(random_bytes(4)), UtcClock::stamp($at)]);
    };

    $ctx->run('KC01 (1) a quotation stored 2 minutes before judge(): offerHold at judged_at holds, so a row planted sent then reads FAIL quote_hold', function () use ($ctx, $J, $quote) {
        [$b, $oid] = wo_kc($ctx);
        [, $conv] = wo_good_send($b, $oid, '256700950101', $J);
        $quote($b, $conv, $J + 2);
        $r = wo_klive($b, $J + 600);
        $ctx->eq([wo_st($r), wo_counted($r, 'stored after judged_at: quotation hold')], ['PASS', 1], 'KC01 control: the quotation stored after judged_at: PASS, printed', wo_detail($r));
        [$b, $oid] = wo_kc($ctx);
        [, $conv] = wo_good_send($b, $oid, '256700950102', $J);
        $quote($b, $conv, $J - 120);
        $r = wo_klive($b, $J + 600);
        $ctx->eq(in_array('quote_hold', wo_kfails($r), true), true, 'KC01 stored 2 minutes before judged_at, no tracker run between: FAIL quote_hold (OM97)', wo_detail($r));
    });

    $ctx->run('KC02 (2) rows stored between judge() and the gateway\'s return leave the send going and C-O2-9/10 at PASS, printed (OM98, OM99)', function () use ($ctx, $T0, $quote) {
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, ['256700950201' => 'sales']);
        $conv = (int)wo_row($b['pdo'], '256700950201')['conversation_id'];
        $gw->onSend = function () use ($b, $conv, $clk, $T0, $quote): void {
            $quote($b, $conv, $T0 + 2);
            (new ContactOptOut($b['pdo']))->add('256700950201', ['reason' => 'customer_request', 'source' => 'keyword']);
            $b['pdo']->exec("UPDATE contact_optouts SET created_at = '" . UtcClock::stamp($T0 + 2) . "' WHERE phone LIKE '%700950201'");
            wo_out($b, $conv, 'A colleague answers from the Inbox (test).', $T0 + 2, ['agent' => 'Office Colleague', 'event_key' => 'wa_staff_reply']);
            $b['pdo']->exec("UPDATE wa_conversations SET state = 'human_active', last_human_reply_at = '" . UtcClock::stamp($T0 + 2) . "' WHERE id = " . $conv);
            wo_in($b, $conv, '', $T0 - 60, ['media_type' => 'audio', 'stored' => $T0 + 2]);
            $clk->t = $T0 + 5;
        };
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        wo_kseen($b);
        $row = wo_row($b['pdo'], '256700950201');
        $ctx->eq([$row['state'], count(wo_calls($log, 'send'))], ['sent', 1], 'KC02 control: the send went (the rows came after judge())');
        $r = wo_klive($b, $T0 + 600);
        $ctx->eq(wo_st($r), 'PASS', 'KC02 C-O2-9 and C-O2-10 re-judged at judged_at: PASS', wo_detail($r));
        $ctx->eq([wo_counted($r, 'after judged_at: opt-out'), wo_counted($r, 'after judged_at: quotation hold'), wo_counted($r, 'after judged_at: voice note'),
                  wo_counted($r, 'after judged_at: a person'), wo_counted($r, 'C-O2-10 inbound stored after judged_at')], [1, 1, 1, 1, 1],
                 'KC02 the STOP, the quotation, the voice note (device time before judged_at), the colleague and the inbound: each printed as stored after judged_at', wo_detail($r));
    });

    $ctx->run('KC03 (3) a write in judged_at\'s own second is judged as an earlier row; one in the next second is printed', function () use ($ctx, $J, $mark) {
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700950301', $J);
        $mark($b, '256700950301', $J + 1);
        $r = wo_klive($b, $J + 600);
        $ctx->eq([wo_st($r), wo_counted($r, 'after judged_at: seen mark')], ['PASS', 1], 'KC03 the mark stored a second after judged_at: PASS, printed', wo_detail($r));
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700950302', $J);
        $mark($b, '256700950302', $J);
        $ctx->eq(in_array('unread_inbound', wo_kfails(wo_klive($b, $J + 600)), true), true, 'KC03 control: a mark in judged_at\'s own second reads FAIL unread_inbound');
    });

    $ctx->run('KC04 (4) settings narrowed after correct sends: the window, the day\'s ceiling, the spacing, recent_days, warm_days (OM100–OM103)', function () use ($ctx, $T0, $J) {
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700950401', qf_t('2026-11-02 18:59:58'));
        $c = wo_kset($ctx, $b, ['window', '8', '18']);            // B9-fix1: the tool's own `set` (coordinator item 10g)
        $ctx->eq((string)($c['wa_send_hour_to'] ?? ''), '18', 'KC04 control: set window 8 18 saved through the tool');
        $r = wo_klive($b, $T0 + 86400, $c);
        $ctx->eq(wo_st($r), 'PASS', 'KC04 a send claimed at 19:00, then set window 8 18 through the tool: C-O2-2 PASS (OM100)', wo_detail($r));
        [$b, $oid] = wo_kc($ctx);
        for ($i = 0; $i < 20; $i++) wo_good_send($b, $oid, sprintf('2567009504%02d', 10 + $i), $T0 - 6000 + 240 * $i);
        $c = wo_kset($ctx, $b, ['offers_daily_max_per_handset', '5']);
        $r = wo_klive($b, $T0 + 600, $c);
        $ctx->eq(wo_st($r), 'PASS', 'KC04 20 sends in a day on one handset, then set offers_daily_max_per_handset 5: C-O2-5 PASS (OM101)', wo_detail($r));
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700950501', $J);
        wo_good_send($b, $oid, '256700950502', $J + 90);
        $c = wo_kset($ctx, $b, ['gap', '120', '180']);
        $r = wo_klive($b, $T0 + 600, $c);
        $ctx->eq(wo_st($r), 'PASS', 'KC04 two sends 90 s apart (gap_s 60), then set gap 120 180 through the tool: C-O2-6 PASS (OM102)', wo_detail($r));
        $b2 = wo_book($ctx, wo_cfg(), 'kc');
        $o1 = wo_live_offer($b2, $T0 - 12 * 86400, ['recent_days' => 7, 'warm_days' => 7]);
        [, $conv] = wo_good_send($b2, $o1, '256700950601', $T0 - 10 * 86400 - 3600);
        $b2['pdo']->exec("UPDATE wa_offers SET state = 'done', live_slot = NULL, close_reason = 'done' WHERE id = " . $o1);
        $o2 = wo_live_offer($b2, $T0 - 86400, ['recent_days' => 7, 'warm_days' => 7]);
        wo_in($b2, $conv, 'A note in the warm window (test).', $J - 3 * 86400);
        wo_in($b2, $conv, '', $J - 20 * 86400, ['media_type' => 'audio']);
        wo_good_send($b2, $o2, '256700950601', $J, ['conv' => $conv]);
        wo_kset($ctx, $b2, ['offers_recent_days', '14']);
        $c = wo_kset($ctx, $b2, ['offers_warm_days', '30']);
        $r = wo_klive($b2, $T0 + 600, $c);
        $ctx->eq(wo_st($r), 'PASS', 'KC04 two offers 10 days apart under recent_days 7, a voice note 20 days before under warm_days 7, then both settings raised: C-O2-3 and C-O2-9 PASS (OM103)', wo_detail($r));
    });

    $ctx->run('KC05 (5) a colleague\'s Inbox reply 2 minutes after a correct send: C-O2-8 PASS and in its printed count (OM105)', function () use ($ctx, $J) {
        [$b, $oid] = wo_kc($ctx);
        [, $conv] = wo_good_send($b, $oid, '256700950701', $J);
        wo_out($b, $conv, 'A colleague answers from the Inbox (test).', $J + 123, ['agent' => 'Office Colleague', 'event_key' => 'wa_staff_reply']);
        $b['pdo']->exec("UPDATE wa_conversations SET state = 'human_active', last_human_reply_at = '" . UtcClock::stamp($J + 123) . "' WHERE id = " . $conv);
        $r = wo_klive($b, $J + 600);
        $ctx->eq([wo_st($r), wo_counted($r, 'within 5 minutes after a send: an Inbox reply')], ['PASS', 1], 'KC05 PASS; the reply printed', wo_detail($r));
    });

    $ctx->run('KC06 (6) lists that moved: a partner contact and a staff account added after a correct send are printed; one stored before judged_at FAIL (OM106, OM117)', function () use ($ctx, $J) {
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700950801', $J);
        $b['pdo']->prepare("INSERT INTO dist_contacts (partner_id, phone, created_at) VALUES (1, '+256700950801', ?)")->execute([UtcClock::stamp($J + 60)]);
        wo_admins($b, [['id' => 8, 'name' => 'New Colleague', 'role' => 'sales', 'is_active' => true, 'phone' => '0700950801']]);
        $r = wo_klive($b, $J + 600);
        $ctx->eq([wo_st($r), wo_counted($r, 'partner contacts stored after judged_at'), wo_counted($r, 'on the staff list today')], ['PASS', 1, 1],
                 'KC06 the partner contact and the staff account added after the send: PASS, both printed', wo_detail($r));
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700950802', $J);
        $b['pdo']->prepare("INSERT INTO dist_contacts (partner_id, phone, created_at) VALUES (1, '+256700950802', ?)")->execute([UtcClock::stamp($J - 60)]);
        $ctx->eq(in_array('partner_contact', wo_kfails(wo_klive($b, $J + 600)), true), true, 'KC06 control: the partner contact created before judged_at reads FAIL partner_contact');
    });

    $ctx->run('KC07 (7) a reminder recorded after the offer\'s claim is printed; an offer claimed after a reminder that day FAIL (OM108)', function () use ($ctx, $J) {
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700950901', $J);
        wo_pledger($b, ['kind' => 'reminder', 'mode' => 'record', 'ref' => 'rem:KC07', 'phone' => '256700950901', 'at' => $J + 1800, 'handset' => 'i:dishnet-ug']);
        $r = wo_klive($b, $J + 3600);
        $ctx->eq([wo_st($r), wo_counted($r, 'records after an offer that day (reminder)')], ['PASS', 1], 'KC07 PASS; the reminder printed', wo_detail($r));
        [$b, $oid] = wo_kc($ctx);
        wo_pledger($b, ['kind' => 'reminder', 'mode' => 'record', 'ref' => 'rem:KC07b', 'phone' => '256700950902', 'at' => $J - 1800, 'handset' => 'i:dishnet-ug']);
        wo_good_send($b, $oid, '256700950902', $J);
        $ctx->eq(in_array('phone_today', wo_kfails(wo_klive($b, $J + 3600)), true), true, 'KC07 control: an offer claimed after a reminder recorded that day reads FAIL phone_today');
    });

    $ctx->run('KC08 (8) routing that moved: a later inbound on a channel routed today to another handset is printed (OM109)', function () use ($ctx, $J) {
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700951001', $J);
        $sup = wo_conv($b, '256700951001', 'support');
        wo_in($b, $sup, 'A later message on the support number (test).', $J - 600);
        $r = wo_klive($b, $J + 600);
        $ctx->eq([wo_st($r), wo_counted($r, 'routed today to another handset')], ['PASS', 1], 'KC08 PASS; the other conversation printed', wo_detail($r));
        [$b, $oid] = wo_kc($ctx);
        $conv = wo_conv($b, '256700951002', 'sales');
        wo_good_send($b, $oid, '256700951002', $J, ['conv' => $conv]);
        $ctx->eq(in_array('not_warm', wo_kfails(wo_klive($b, $J + 600)), true), true, 'KC08 control: a send from a conversation with no inbound by judged_at reads FAIL not_warm');
    });

    $ctx->run('KC09 (9) a failed send pairs with nothing: a reminder not_sent before an offer, an offer not_sent before a chase (OM112)', function () use ($ctx, $J) {
        [$b, $oid] = wo_kc($ctx);
        wo_pledger($b, ['kind' => 'reminder', 'mode' => 'record', 'ref' => 'rem:KC09', 'phone' => '256700951101', 'at' => qf_t('2026-11-02 09:00:00'), 'outcome' => 'not_sent', 'handset' => 'i:dishnet-ug']);
        wo_good_send($b, $oid, '256700951101', $J);
        $rid = wo_precip($b, $oid, ['phone' => '256700951102', 'state' => 'queued', 'claimed_at' => UtcClock::stamp($J - 1), 'attempt' => 1]);
        wo_pledger($b, ['ref' => 'offer:' . $oid . ':' . $rid, 'phone' => '256700951102', 'at' => $J + 300, 'outcome' => 'not_sent']);
        wo_pledger($b, ['kind' => 'quote', 'ref' => 'qc:KC09', 'phone' => '256700951102', 'at' => $J + 1800]);
        $r = wo_klive($b, $J + 3600);
        $ctx->eq(wo_st($r), 'PASS', 'KC09 PASS: neither not_sent row is paired', wo_detail($r));
    });

    $ctx->run('KC10 (10) an enquiry recorded after the offer is printed; an enquiry claimed after it that day FAIL (OM114)', function () use ($ctx, $J) {
        // B9-fix1 (coordinator item 10g): off offers-test through the tool, then the real enquiry pass (cron/followup_send.php),
        // which records the enquiry (no offer switch on: WaSendBudget::enquiryClaims() false) — the row the pass itself writes
        $evo = qf_fake_evo();
        if ($evo === '') { $ctx->t(false, 'KC10 control: the fake Evolution is up'); return; }
        [$b, $oid] = wo_kc($ctx, ['evo_api_url' => $evo, 'followup_enabled' => '1']);
        wo_good_send($b, $oid, '256700951201', $J);
        [$rc, $out] = wo_tool($ctx, $b, ['off', 'offers-test']);
        $files = PluginConfig::read($ctx->tree(), $b['data']);
        $ctx->eq([$rc, isset($files['offers_test_enabled']), isset($files['offers_live_enabled']), WaSendBudget::enquiryClaims($files)], [0, false, false, false],
                 'KC10 control: off offers-test through the tool: both switches unset, so an enquiry is recorded, not claimed', $out);
        qf_enquiry($b, '256700951201', 'support', UtcClock::stamp($J + 600));
        [, $cout] = qf_cron($ctx, $b, 'cron/followup_send.php', ['_qf_test_now' => $J + 1800], $files);
        $rows = $b['pdo']->query("SELECT mode, outcome FROM wa_auto_sends WHERE kind = 'enquiry' AND phone = '256700951201'")->fetchAll(\PDO::FETCH_NUM);
        $ctx->eq($rows, [['record', 'sent']], 'KC10 control: the real enquiry pass sent the enquiry and recorded it after the offer that day', $cout);
        $r = wo_klive($b, $J + 3600);
        $ctx->eq([wo_st($r), wo_counted($r, 'records after an offer that day (enquiry)')], ['PASS', 1], 'KC10 PASS; the recorded enquiry printed', wo_detail($r));
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700951202', $J);
        wo_pledger($b, ['kind' => 'enquiry', 'mode' => 'claim', 'ref' => 'fu:KC10b', 'phone' => '256700951202', 'at' => $J + 1800, 'handset' => 'i:dishnet-ug']);
        $ctx->eq(in_array('phone_today', wo_kfails(wo_klive($b, $J + 3600)), true), true, 'KC10 control: an enquiry claimed after the offer that day reads FAIL phone_today');
    });

    $ctx->run('KC11 (11) the crash windows\' designed outcomes on Part F\'s ledger: 9–10, a certain failure, 6–7 then a move, the by-hand put-back (OM115, OM116)', function () use ($ctx, $J) {
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700951301', $J, ['state' => 'uncertain', 'reason' => 'stale_claim', 'sent_at' => $J - 1, 'outcome' => 'sent']);
        wo_good_send($b, $oid, '256700951302', $J + 200, ['state' => 'uncertain', 'reason' => 'stale_claim', 'sent_at' => $J + 199, 'outcome' => 'not_sent']);
        wo_good_send($b, $oid, '256700951303', $J + 400, ['state' => 'uncertain', 'reason' => 'ledger_already_sent', 'handset' => 'i:dishnet-ug', 'ledger_handset' => 'i:sales-ug',
                                                          'channel' => 'support', 'outcome' => 'uncertain', 'detail' => 'stale']);
        $r = wo_klive($b, $J + 3600);
        $ctx->eq(wo_st($r), 'PASS', 'KC11 the three designed outcomes: PASS', wo_detail($r));
        $ctx->eq([wo_counted($r, 'stale claims whose ledger row reads sent'), wo_counted($r, 'stale claims whose ledger row reads not_sent'),
                  wo_counted($r, 'stale claims certainly not sent'), wo_counted($r, 'already-sent rows on another handset')], [1, 1, 1, 1],
                 'KC11 each printed with its count (C-O2-2 counts the not_sent one apart)', wo_detail($r));
        [$b, $oid] = wo_kc($ctx);
        [$rid] = wo_good_send($b, $oid, '256700951304', $J, ['state' => 'uncertain', 'reason' => 'stale_claim', 'sent_at' => $J - 1, 'outcome' => null]);
        $r = wo_klive($b, $J + 3600);
        $ctx->eq([wo_st($r), wo_counted($r, 'stale claims whose ledger row is in flight'), wo_counted($r, 'in flight, not yet swept')], ['PASS', 1, 1],
                 'KC11 the by-hand put-back after a death between step 7 and the POST: the ledger row in flight, not yet swept, PASS', wo_detail($r));
        $b['pdo']->exec("UPDATE wa_auto_sends SET outcome = 'uncertain', settled_at = '" . UtcClock::stamp($J + 1000) . "', detail = 'stale_orphan' WHERE ref = 'offer:{$oid}:{$rid}'");
        wo_pledger($b, ['kind' => 'quote', 'ref' => 'qc:KC11', 'phone' => '256700951399', 'at' => $J + 1000]);
        $ctx->eq(wo_st(wo_klive($b, $J + 3600)), 'PASS', 'KC11 after a claim on its handset 900 s later swept it uncertain: PASS');
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700951305', $J, ['state' => 'uncertain', 'reason' => 'may_have_been_sent', 'ledger' => false]);
        $ctx->eq(in_array('ledger_missing', wo_kfails(wo_klive($b, $J + 3600)), true), true, 'KC11 control: a row planted uncertain with no ledger row under its ref reads FAIL');
    });

    $ctx->run('KC12 (12) a claim left unswept while no run sweeps: counted, not FAIL; once a later claim shows a sweep ran, FAIL claim_unswept (OM121)', function () use ($ctx, $J) {
        [$b, $oid] = wo_kc($ctx);
        $rid = wo_precip($b, $oid, ['phone' => '256700951401', 'state' => 'claimed', 'attempt' => 1, 'claim_token' => 'k12', 'claimed_at' => UtcClock::stamp($J),
                                    'judged_at' => UtcClock::stamp($J + 1), 'day_max' => 20, 'variant' => 1]);
        $lid = wo_pledger($b, ['ref' => 'offer:' . $oid . ':' . $rid, 'phone' => '256700951401', 'at' => $J + 2, 'outcome' => null]);
        $b['pdo']->exec('UPDATE wa_offer_recipients SET ledger_claim = ' . $lid . ' WHERE id = ' . $rid);
        $r = wo_klive($b, $J + 16 * 60);
        $ctx->eq([wo_st($r), wo_counted($r, 'claimed, not yet swept')], ['PASS', 1], 'KC12 16 minutes on, no run since (off sending): PASS, counted', wo_detail($r));
        wo_precip($b, $oid, ['phone' => '256700951402', 'state' => 'queued', 'attempt' => 1, 'claimed_at' => UtcClock::stamp($J + 15 * 60 + 36)]);
        $ctx->eq(in_array('claim_unswept', wo_kfails(wo_klive($b, $J + 17 * 60)), true), true, 'KC12 control: another recipient claimed 15 min 36 s later reads FAIL claim_unswept');
    });

    $ctx->run('KC13 (13) a settle that did not land: settle_not_landed before the orphan sweep and after it; all not_sent FAIL (OM123)', function () use ($ctx, $J) {
        [$b, $oid] = wo_kc($ctx);
        [$r1] = wo_good_send($b, $oid, '256700951501', $J, ['outcome' => null]);
        wo_good_send($b, $oid, '256700951502', $J + 300, ['state' => 'uncertain', 'reason' => 'may_have_been_sent', 'outcome' => null]);
        $r = wo_klive($b, $J + 600);
        $ctx->eq([wo_st($r), wo_counted($r, 'settle_not_landed')], ['PASS', 2], 'KC13 before the sweep: PASS, settle_not_landed counted twice', wo_detail($r));
        $b['pdo']->exec("UPDATE wa_auto_sends SET outcome = 'uncertain', settled_at = '" . UtcClock::stamp($J + 1300) . "', detail = 'stale_orphan' WHERE kind = 'offer'");
        $r = wo_klive($b, $J + 1400);
        $ctx->eq([wo_st($r), wo_counted($r, 'settle_not_landed')], ['PASS', 1], 'KC13 after the orphan sweep: PASS, the sent row counted settle_not_landed', wo_detail($r));
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700951503', $J, ['outcome' => 'not_sent']);
        $ctx->eq(in_array('ledger_not_sent', wo_kfails(wo_klive($b, $J + 600)), true), true, 'KC13 control: a sent row whose ledger rows are all not_sent reads FAIL');
    });

    $ctx->run('KC14 the orphan rule: in flight with no later claim counted; in flight 900 s after a later claim on its handset FAIL ledger_orphan', function () use ($ctx, $J) {
        [$b, $oid] = wo_kc($ctx);
        $rid = wo_precip($b, $oid, ['phone' => '256700951601', 'state' => 'queued', 'attempt' => 1, 'claimed_at' => UtcClock::stamp($J)]);
        wo_pledger($b, ['ref' => 'offer:' . $oid . ':' . $rid, 'phone' => '256700951601', 'at' => $J + 2, 'outcome' => null]);
        $r = wo_klive($b, $J + 1200);
        $ctx->eq([wo_st($r), wo_counted($r, 'in flight, not yet swept')], ['PASS', 1], 'KC14 a 6–7 orphan before any later claim: PASS, counted', wo_detail($r));
        wo_pledger($b, ['kind' => 'quote', 'ref' => 'qc:KC14', 'phone' => '256700951699', 'at' => $J + 903]);
        $ctx->eq(in_array('ledger_orphan', wo_kfails(wo_klive($b, $J + 1200)), true), true, 'KC14 a later claim on its handset 901 s after: FAIL ledger_orphan');
    });

    $ctx->run('KC15 an erased recipient and its ledger row: C-O2-3, C-O2-4, C-O2-9, C-O2-10, C-O2-12 at PASS, counted erased', function () use ($ctx, $J) {
        [$b, $oid] = wo_kc($ctx);
        foreach (['256700951701', '256700951702'] as $i => $p) {
            [$rid] = wo_good_send($b, $oid, $p, $J + 200 * $i);
            // B9-fix1 (coordinator item 10g): Part F's real erase, through the tool, the number typed (never in argv)
            [$rc, $out] = wo_tool($ctx, $b, ['erase'], '0' . substr($p, 3) . "\n");
            $e = $b['pdo']->query("SELECT r.phone, a.phone FROM wa_offer_recipients r LEFT JOIN wa_auto_sends a ON a.ref = 'offer:{$oid}:' || r.id WHERE r.id = {$rid}")->fetch(\PDO::FETCH_NUM);
            $ctx->eq([$rc, $e, strpos($out, substr($p, 3)) === false], [0, [null, null], true], 'KC15 control: erase through the tool blanked the recipient\'s and its ledger row\'s phone, printing no number', $out);
        }
        wo_pledger($b, ['kind' => 'reminder', 'mode' => 'record', 'ref' => 'rem:KC15', 'phone' => null, 'at' => $J - 600, 'handset' => 'i:dishnet-ug']);
        $r = wo_klive($b, $J + 3600);
        $ctx->eq([wo_st($r), wo_counted($r, 'C-O2-9 erased'), wo_counted($r, 'C-O2-3 erased'), wo_counted($r, 'C-O2-10 erased')], ['PASS', 2, 2, 2],
                 'KC15 two erased recipients in one offer, a reminder recorded under an unreadable number: PASS, counted erased', wo_detail($r));
    });

    $ctx->run('KC16 (A12-05) a follow-up opened after the offer, open and then closed: PASS; a send judged while it was open FAIL (OM96)', function () use ($ctx, $J) {
        [$b, $oid] = wo_kc($ctx);
        [, $conv] = wo_good_send($b, $oid, '256700951801', $J);
        $b['pdo']->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, opened_at) VALUES (?, 'sales', '256700951801', ?, ?)")
                 ->execute([$conv, UtcClock::stamp($J + 3600), UtcClock::stamp($J + 25 * 3600)]);
        $r = wo_klive($b, $J + 26 * 3600);
        $ctx->eq(wo_st($r), 'PASS', 'KC16 the follow-up opened 25 h after the send, still open: PASS', wo_detail($r));
        $b['pdo']->exec("UPDATE followups SET closed_at = '" . UtcClock::stamp($J + 26 * 3600) . "'");
        $ctx->eq(wo_st(wo_klive($b, $J + 27 * 3600)), 'PASS', 'KC16 … and once closed: PASS');
        [$b, $oid] = wo_kc($ctx);
        $conv = wo_warm($b, '256700951802', $J, 86400);
        $b['pdo']->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, opened_at) VALUES (?, 'sales', '256700951802', ?, ?)")
                 ->execute([$conv, UtcClock::stamp($J - 86400), UtcClock::stamp($J - 3600)]);
        wo_good_send($b, $oid, '256700951802', $J, ['conv' => $conv]);
        $ctx->eq(in_array('followup_open', wo_kfails(wo_klive($b, $J + 3600)), true), true, 'KC16 control: a send judged while a follow-up opened before it was open reads FAIL followup_open');
    });

    $ctx->run('KC17 (B11-01) a 6–7 orphan re-claimed after the close: judged by its ledger row\'s claimed_at, PASS; a claim 30 s before the close it records FAIL (OM94)', function () use ($ctx) {
        $close = qf_t('2026-11-02 20:00:00');
        [$b, $oid] = wo_kc($ctx);
        [$rid] = wo_good_send($b, $oid, '256700951901', $close - 3600, ['state' => 'uncertain', 'reason' => 'ledger_already_sent', 'sent_at' => $close + 40,
                                                                       'outcome' => 'uncertain', 'detail' => 'stale']);
        wo_good_send($b, $oid, '256700951903', $close - 600, ['sent_at' => $close + 5]);
        wo_good_send($b, $oid, '256700951902', $close - 60);
        $r = wo_klive($b, $close + 3600);
        $ctx->eq([wo_st($r), wo_counted($r, 'returns after the close')], ['PASS', 2], 'KC17 PASS; the two returns after the close printed, never FAIL', wo_detail($r));
        [$b, $oid] = wo_kc($ctx);
        wo_good_send($b, $oid, '256700951904', $close - 31, ['ledger_at' => $close - 30]);
        $ctx->eq(in_array('outside_window', wo_kfails(wo_klive($b, $close + 3600)), true), true, 'KC17 control: a ledger row claimed 30 s before the close it records reads FAIL');
    });

    $ctx->run('KC18 through the real sender: a phone 47 h after an enquiry settled uncertain, and one whose voice note the offer\'s warm_days hold, are not sent; C-O2-9 PASS (OM91, OM104)', function () use ($ctx, $T0) {
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, ['256700952001' => 'sales', '256700952002' => 'sales'], [], ['offer' => []]);
        wo_ledger($b['pdo'], 'enquiry', 'fu:KC18', '256700952001', $T0 - 47 * 3600, 'uncertain', 'claim', 'i:dishnet-ug');
        $conv = (int)wo_row($b['pdo'], '256700952002')['conversation_id'];
        wo_in($b, $conv, '', $T0 - 20 * 86400, ['media_type' => 'audio', 'stored' => $T0 - 3600]);
        wo_setcfg($b, ['offers_warm_days' => 7]);
        for ($i = 0; $i < 4; $i++) { wo_ksender($ctx, $b, $clk, $gw)->runOnce(null); $clk->t += 300; }
        wo_kseen($b);
        $ctx->eq([wo_row($b['pdo'], '256700952001')['reason'], wo_row($b['pdo'], '256700952002')['reason'], count(wo_calls($log, 'send'))],
                 ['followup_recent', 'recent_voice_note', 0], 'KC18 control: both skipped at the send (the ledger\'s enquiry; the offer\'s recorded warm_days 30)');
        $r = wo_klive($b, $clk->t);
        $ctx->eq(wo_st($r), 'PASS', 'KC18 --check offers-live PASS over a run that sent nothing it should not', wo_detail($r));
    });

    $ctx->run('KC19 the admin\'s test end to end on Part F\'s ledger: --check offers-test PASS; after off sending a press writes nothing and PASS holds (OM113)', function () use ($ctx, $T0) {
        wo_ladder_stub($ctx);
        $b = wo_book($ctx, wo_cfg(), 'kc');
        wo_admins($b);
        $id = wo_offer($b, $T0 - 600);
        $admin = $b['store']->load('retailers.json')[0];
        $clk = new WoClock($T0);
        $s = wo_ksender($ctx, $b, $clk, new WoGateway(new WoLog()));
        foreach ([1, 2] as $v) { $s->sendTest($id, $v, 'sales', $admin); $clk->t += 120; }
        $b['pdo']->exec("INSERT OR IGNORE INTO evo_webhook_seen (message_id, instance, event) SELECT wa_message_id, 'Sales-UG', 'offer.test' FROM wa_offer_tests WHERE wa_message_id IS NOT NULL");
        $chk = function () use ($b, $clk): array { return OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $clk->t); };
        $r = $chk();
        $ctx->eq([wo_st($r), wo_counted($r, 'tests recorded')], ['PASS', 2], 'KC19 two tests sent and recorded through record(): PASS', wo_detail($r));
        wo_setcfg($b, ['wa_send_ledger' => null]);
        $t = $s->sendTest($id, 1, 'sales', $admin);
        wo_setcfg($b, ['wa_send_ledger' => 'on']);
        $ctx->eq([$t['code'], (int)$b['pdo']->query('SELECT COUNT(*) FROM wa_offer_tests')->fetchColumn(), wo_st($chk())], ['ledger_off', 2, 'PASS'],
                 'KC19 a press with the send ledger off: nothing written; after on ack, --check offers-test still PASS');
    });

    // ── B8-fix1 (B8-R01): C-O2-9's own reads, clause by clause — each FAIL branch with its case, its PASS control and its
    // weakened copy (OM-KC4 … OM-KC7). C-O2-9 reads its sources with its own SQL bounded at judged_at (B8 note 11), so
    // OM91, which weakens OfferAudience::lists(), reaches the audience only; these copies weaken C-O2-9's reads themselves.

    $ctx->run('KC20 C-O2-9\'s ledger half, on Part F\'s real ledger: judged 47 h after an enquiry follow-up settled uncertain, no followup_sends row: FAIL followup_recent; settled not_sent, or 49 h before: PASS (OM-KC5)', function () use ($ctx, $T0) {
        $J = $T0 + 3 * 3600;                                           // Monday 13:30: 47 h and 49 h before are Saturday 14:30 and 12:30
        foreach ([['256700952001', 47, ['maybe_sent' => true], 'uncertain'], ['256700952002', 47, [], 'not_sent'], ['256700952003', 49, ['maybe_sent' => true], 'uncertain']] as [$p, $h, $o, $out]) {
            [$b, $oid] = wo_kc($ctx);
            $clk = new WoClock($J - $h * 3600);
            $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => ReminderDay::todayAt($clk->t, 9) + 300, 'duration_ms' => 1000]]);   // that day's reminders done
            $L = wo_ledger_of($b, $clk);
            $a = $L->claim('DishNet-UG', 'enquiry', 'fu:KC20:' . $p, ['phone' => $p]);
            $clk->t += 5;
            if (!empty($a['ok'])) $L->settle((int)$a['claim'], 'failed', $o);
            $got = $b['pdo']->query("SELECT outcome FROM wa_auto_sends WHERE kind = 'enquiry' AND phone = '{$p}'")->fetchColumn();
            $ctx->eq([!empty($a['ok']), $got, (int)$b['pdo']->query('SELECT COUNT(*) FROM followup_sends')->fetchColumn()], [true, $out, 0],
                     "KC20 control: an enquiry follow-up claimed {$h} h before judged_at through Part F's path, settled {$out}, no followup_sends row");
            wo_good_send($b, $oid, $p, $J);
            $r = wo_klive($b, $J + 600);
            if ($h === 47 && $out === 'uncertain') $ctx->eq(wo_kfails($r), ['followup_recent'], 'KC20 an offer judged 47 h after the enquiry settled uncertain in the real ledger: FAIL followup_recent', wo_detail($r));
            else $ctx->eq(wo_st($r), 'PASS', "KC20 control: the enquiry settled {$out} {$h} h before judged_at: PASS", wo_detail($r));
        }
        // the world moved after a correct offer: a quote chase claimed for the phone the next day through Part F's path
        [$b, $oid] = wo_kc($ctx);
        $p = '256700952004';
        wo_good_send($b, $oid, $p, $J);
        $clk = new WoClock($J + 86400);
        $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => ReminderDay::todayAt($clk->t, 9) + 300, 'duration_ms' => 1000]]);
        $L = wo_ledger_of($b, $clk);
        $a = $L->claim('Sales-UG', 'quote', 'qc:KC20:' . $p, ['phone' => $p]);
        $clk->t += 5;
        if (!empty($a['ok'])) $L->settle((int)$a['claim'], 'sent', ['wa_message_id' => 'WO-KC20-QC']);
        $r = wo_klive($b, $J + 86400 + 600);
        $ctx->eq([!empty($a['ok']), wo_st($r)], [true, 'PASS'], 'KC20 a quote chase claimed for the phone the next day, after a correct offer: PASS (the ledger half is bounded by judged_at; OM-KC9)', wo_detail($r));
    });

    $ctx->run('KC21 C-O2-9\'s followup_sends half: judged 30 h after a follow-up send (its follow-up closed before): FAIL followup_recent; one 49 h before: PASS (OM-KC4)', function () use ($ctx, $J) {
        foreach ([['256700952101', 30], ['256700952102', 49]] as [$p, $h]) {
            [$b, $oid] = wo_kc($ctx);
            [, $conv] = wo_good_send($b, $oid, $p, $J);
            $b['pdo']->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, opened_at, closed_at) VALUES (?, 'sales', ?, ?, ?, ?)")
                     ->execute([$conv, $p, UtcClock::stamp($J - 9 * 86400), UtcClock::stamp($J - 9 * 86400), UtcClock::stamp($J - 3600)]);
            $fu = (int)$b['pdo']->lastInsertId();
            $b['pdo']->prepare("INSERT INTO followup_sends (followup_id, attempt, sent_at, channel, phone, body, decided_by) VALUES (?, 1, ?, 'sales', ?, 'A follow-up (test)', 'staff:test')")
                     ->execute([$fu, UtcClock::stamp($J - $h * 3600), $p]);
            $ctx->eq((int)$b['pdo']->query("SELECT COUNT(*) FROM wa_auto_sends WHERE kind IN ('quote', 'enquiry')")->fetchColumn(), 0, "KC21 control: no ledger row of the follow-up ({$h} h): only followup_sends can see it");
            $r = wo_klive($b, $J + 600);
            if ($h === 30) $ctx->eq(wo_kfails($r), ['followup_recent'], 'KC21 an offer judged 30 h after a followup_sends row: FAIL followup_recent', wo_detail($r));
            else $ctx->eq(wo_st($r), 'PASS', 'KC21 control: the follow-up send 49 h before judged_at: PASS', wo_detail($r));
        }
        // the world moved after a correct offer: a follow-up send stored between judged_at and the gateway's return, and one the next day
        foreach ([2 => 1, 86400 => 0] as $after => $printed) {
            [$b, $oid] = wo_kc($ctx);
            $p = '25670095211' . $printed;
            [, $conv] = wo_good_send($b, $oid, $p, $J);
            $b['pdo']->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, opened_at, closed_at) VALUES (?, 'sales', ?, ?, ?, ?)")
                     ->execute([$conv, $p, UtcClock::stamp($J - 9 * 86400), UtcClock::stamp($J - 9 * 86400), UtcClock::stamp($J - 3600)]);
            $b['pdo']->prepare("INSERT INTO followup_sends (followup_id, attempt, sent_at, channel, phone, body, decided_by) VALUES (?, 1, ?, 'sales', ?, 'A follow-up (test)', 'staff:test')")
                     ->execute([(int)$b['pdo']->lastInsertId(), UtcClock::stamp($J + $after), $p]);
            $r = wo_klive($b, $J + 86400 + 600);
            $ctx->eq([wo_st($r), wo_counted($r, 'stored after judged_at: follow-up send')], ['PASS', $printed],
                     'KC21 a follow-up send ' . ($printed ? 'stored between judged_at and the return: PASS, printed' : 'the next day: PASS') . ' (OM-KC8)', wo_detail($r));
        }
    });

    $ctx->run('KC22 C-O2-9\'s opt-out clause: an opt-out live at judged_at, typed in the national form for another channel: FAIL opted_out; one lifted before judged_at: PASS (OM-KC6)', function () use ($ctx, $J) {
        foreach ([['256700952201', false], ['256700952202', true]] as [$p, $lifted]) {
            [$b, $oid] = wo_kc($ctx);
            wo_good_send($b, $oid, $p, $J);
            $nat = '0' . substr($p, 3);
            $oo = new ContactOptOut($b['pdo']);
            $add = $oo->add($nat, ['reason' => 'customer_request', 'source' => 'keyword', 'channel' => 'support']);
            $b['pdo']->exec("UPDATE contact_optouts SET created_at = '" . UtcClock::stamp($J - 86400) . "' WHERE id = " . (int)$add['id']);
            if ($lifted) {
                $oo->lift($nat, 'support', 'staff:test');
                $b['pdo']->exec("UPDATE contact_optouts SET lifted_at = '" . UtcClock::stamp($J - 3600) . "' WHERE id = " . (int)$add['id']);
            }
            $row = $b['pdo']->query('SELECT channel, active, lifted_at IS NOT NULL FROM contact_optouts WHERE id = ' . (int)$add['id'])->fetch(\PDO::FETCH_NUM);
            $ctx->eq([$add['created'], $row], [true, ['support', $lifted ? 0 : 1, $lifted ? 1 : 0]], 'KC22 control: the opt-out written by ContactOptOut' . ($lifted ? ', then lifted' : ''));
            $r = wo_klive($b, $J + 600);
            if (!$lifted) $ctx->eq(wo_kfails($r), ['opted_out'], 'KC22 an offer judged while an opt-out made a day before was live (national form, another channel): FAIL opted_out', wo_detail($r));
            else $ctx->eq(wo_st($r), 'PASS', 'KC22 control: the opt-out lifted an hour before judged_at: PASS', wo_detail($r));
        }
    });

    $ctx->run('KC23 C-O2-9\'s person rule (HumanTakeover at judged_at): a Team row, or an Inbox reply, stored within the 72 h before: FAIL person_handling; a Team row 73 h before: PASS (OM-KC7)', function () use ($ctx, $J) {
        foreach ([['256700952301', 3600, 'Team', null, 'team_message'], ['256700952302', 7200, 'Office Colleague', 'wa_staff_reply', 'inbox_reply'],
                  ['256700952303', 73 * 3600, 'Team', null, null]] as [$p, $ago, $agent, $key, $code]) {
            [$b, $oid] = wo_kc($ctx);
            [, $conv] = wo_good_send($b, $oid, $p, $J);
            wo_out($b, $conv, 'A colleague writes to the customer (test).', $J - $ago, ['agent' => $agent, 'event_key' => $key]);
            $ctx->eq(HumanTakeover::phoneSince($b['pdo'], $p, UtcClock::stamp($J - 72 * 3600), UtcClock::stamp($J)), $code,
                     'KC23 control: HumanTakeover at judged_at reads ' . ($code ?? 'nobody') . ' (a row ' . round($ago / 3600) . ' h before)');
            $r = wo_klive($b, $J + 600);
            if ($code !== null) $ctx->eq(wo_kfails($r), ['person_handling'], 'KC23 an offer judged ' . round($ago / 3600) . ' h after a ' . $code . ': FAIL person_handling', wo_detail($r));
            else $ctx->eq(wo_st($r), 'PASS', 'KC23 control: the Team row 73 h before judged_at: PASS', wo_detail($r));
        }
    });

    // ── B8-fix1 (B8-R03): OM69's K half, through the real sender (D 7837: "D, K").

    $ctx->run('KC24 through the real sender: a phone with an unread inbound mark in the warm window is left out, the other sent; --check offers-live PASS (OM69)', function () use ($ctx, $T0, $mark) {
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, ['256700952401' => 'sales', '256700952402' => 'sales'], [], ['before_start' => function (array $b) use ($T0, $mark) {
            $mark($b, '256700952401', $T0 - 2 * 86400);                                  // the webhook's 'in' mark, two days before
        }]);
        for ($i = 0; $i < 3; $i++) { wo_ksender($ctx, $b, $clk, $gw)->runOnce(null); $clk->t += 300; }
        wo_kseen($b);
        $sent = array_map(function ($c) { return $c[2]; }, wo_calls($log, 'send'));
        $ctx->eq([$sent, wo_row($b['pdo'], '256700952401')['state'] ?? 'no row'], [['256700952402'], 'no row'],
                 'KC24 the phone with the unread mark is not in the audience and never sent; the other is sent');
        $r = wo_klive($b, $clk->t);
        $ctx->eq(wo_st($r), 'PASS', 'KC24 --check offers-live PASS over the run', wo_detail($r));
    });

    // ── B8-fix1 (B8-R04): the --check cases D specifies over real flows, run over the rows the real sender and Part F's real
    // ledger leave (ruling R18: a FAIL over a correct real act is a defect). The planted cases above stay as their FAIL
    // controls (KC11, KC12, KC13, KC16, KC17).

    $ctx->run('KC25 (11) each crash window on Part F\'s real ledger, the real sender killed at each step, then the stale sweep — or the off-hook, or the by-hand put-back — never two messages, and --check offers-live PASS (OM115, OM116)', function () use ($ctx, $T0) {
        $p = '256700952501';
        $later = $T0 + 16 * 60;
        $book = function () use ($ctx, $p): array { return wo_fx($ctx, [$p => 'sales']); };
        $die = function (): void { throw new WoKill('killed in the POST'); };
        $live = function (array $b, int $now) { return wo_klive($b, $now); };
        // 2–6: died before the ledger claim (at step 5's read); swept back to queued, then sent by that run
        [$b, $id, $log, , $gw, $clk] = $book();
        $k = wo_krun($ctx, $b, $clk, $gw, "SELECT COUNT(*) FROM wa_offer_recipients WHERE handset = ? AND id <> ?");
        $r = wo_row($b['pdo'], $p);
        $ctx->eq([$k['code'], $r['state'], $r['ledger_claim'], $r['judged_at'] !== null, wo_kled($b, $id, (int)$r['id'])], ['error', 'claimed', null, true, []], 'KC25 2–6 control: the run died after step 4, before any ledger claim');
        $clk->t = $later;
        wo_krun($ctx, $b, $clk, $gw);
        wo_kseen($b);
        $c = $live($b, $later + 300);
        $ctx->eq([wo_row($b['pdo'], $p)['state'], count(wo_calls($log, 'send')), wo_st($c)], ['sent', 1, 'PASS'], 'KC25 2–6: swept back to queued and sent once; --check offers-live PASS', wo_detail($c));
        // 6–7: died after the ledger claim, before step 7; the next claim under the same ref answers already_sent
        [$b, $id, $log, , $gw, $clk] = $book();
        wo_krun($ctx, $b, $clk, $gw, 'UPDATE wa_offer_recipients SET ledger_claim');
        $r = wo_row($b['pdo'], $p);
        $ctx->eq([$r['state'], $r['ledger_claim'], wo_kled($b, $id, (int)$r['id'])], ['claimed', null, [[null, null, 'i:sales-ug']]], 'KC25 6–7 control: claimed, no ledger_claim, its ledger row in flight');
        $clk->t = $later;
        wo_krun($ctx, $b, $clk, $gw);
        $r = wo_row($b['pdo'], $p);
        $c = $live($b, $later + 300);
        $ctx->eq([$r['state'], $r['reason'], wo_kled($b, $id, (int)$r['id']), count(wo_calls($log, 'send')), wo_st($c)],
                 ['uncertain', 'ledger_already_sent', [['uncertain', 'stale', 'i:sales-ug']], 0, 'PASS'], 'KC25 6–7: uncertain(ledger_already_sent), nothing sent; --check offers-live PASS', wo_detail($c));
        // the POST: died inside the gateway's call; swept uncertain(stale_claim), the ledger settled may-have-been-sent
        foreach (['sweep', 'hook', 'by_hand'] as $how) {
            [$b, $id, $log, , $gw, $clk] = $book();
            $gw->onSend = $die;
            wo_krun($ctx, $b, $clk, $gw);
            $gw->onSend = null;
            $r = wo_row($b['pdo'], $p);
            $ctx->eq([$r['state'], $r['ledger_claim'] !== null, wo_kled($b, $id, (int)$r['id']), count(wo_calls($log, 'send'))], ['claimed', true, [[null, null, 'i:sales-ug']], 1],
                     "KC25 the POST ({$how}) control: claimed with its ledger claim, the ledger row in flight, one call to the gateway");
            if ($how === 'sweep') { $clk->t = $later; wo_krun($ctx, $b, $clk, $gw); }
            elseif ($how === 'hook') OfferSwitch::offHook($b['pdo'], $b['cfg'], $b['data'], 'offers-live', 'switch');
            else {
                // ruling R17's by-hand put-back, as §H.4.19 prints it (D 8040-8048), in place of the sweep
                foreach (["UPDATE wa_offers SET state = 'paused', updated_at = datetime('now') WHERE state = 'running'",
                          "UPDATE wa_offer_recipients SET state = 'uncertain', reason = 'stale_claim', sent_at = claimed_at, settled_at = datetime('now') WHERE state = 'claimed' AND ledger_claim IS NOT NULL",
                          "UPDATE wa_offer_recipients SET state = 'queued', not_before = datetime('now') WHERE state = 'claimed' AND ledger_claim IS NULL",
                          "UPDATE wa_offer_tests SET state = 'uncertain', settled_at = datetime('now') WHERE state = 'sending' AND started_at < datetime('now', '-15 minutes')"] as $sql) $b['pdo']->exec($sql);
            }
            $r = wo_row($b['pdo'], $p);
            $c = $live($b, $later + 300);
            $want = $how === 'by_hand' ? [[null, null, 'i:sales-ug']] : [['uncertain', null, 'i:sales-ug']];
            $ctx->eq([$r['state'], $r['reason'], wo_kled($b, $id, (int)$r['id']), count(wo_calls($log, 'send')), wo_st($c)], ['uncertain', 'stale_claim', $want, 1, 'PASS'],
                     "KC25 the POST, then the {$how}: uncertain(stale_claim), never sent again; --check offers-live PASS", wo_detail($c));
            if ($how === 'by_hand') {
                $ctx->eq([wo_counted($c, 'stale claims whose ledger row is in flight'), wo_counted($c, 'ledger rows in flight, not yet swept')], [1, 1],
                         'KC25 … the by-hand put-back leaves the ledger row in flight: counted, not yet swept', wo_detail($c));
                $q = wo_ledger_of($b, new WoClock($later))->claim('Sales-UG', 'quote', 'qc:KC25', ['phone' => '256700952599']);   // a later claim on its handset
                $c = $live($b, $later + 300);
                $ctx->eq([!empty($q['ok']), wo_kled($b, $id, (int)$r['id']), wo_st($c)], [true, [['uncertain', 'stale_orphan', 'i:sales-ug']], 'PASS'],
                         'KC25 … after a later claim on its handset 900 s on: the ledger row uncertain (stale_orphan), PASS', wo_detail($c));
            }
        }
        // 9–10: died after step 9's settle; the ledger keeps sent (or not_sent after a certain failure), the row swept uncertain
        foreach (['sent' => [null, "UPDATE wa_offer_recipients SET state = 'sent'", 'stale claims whose ledger row reads sent'],
                  'not_sent' => [new OfferSendResult(OfferSendResult::NOT_SENT, 'send_failed'), 'UPDATE wa_offer_recipients SET state = ?, reason = ?, settled_at = ?',
                                 'stale claims whose ledger row reads not_sent']] as $out => [$res, $needle, $count]) {
            [$b, $id, $log, , $gw, $clk] = $book();
            if ($res !== null) $gw->results = [$res];
            wo_krun($ctx, $b, $clk, $gw, $needle);
            $r = wo_row($b['pdo'], $p);
            $ctx->eq([$r['state'], wo_kled($b, $id, (int)$r['id'])[0][0] ?? 'none'], ['claimed', $out], "KC25 9–10 ({$out}) control: claimed, its ledger row already settled {$out}");
            $clk->t = $later;
            wo_krun($ctx, $b, $clk, $gw);
            $r = wo_row($b['pdo'], $p);
            $c = $live($b, $later + 300);
            $ctx->eq([$r['state'], $r['reason'], wo_kled($b, $id, (int)$r['id'])[0][0] ?? 'none', count(wo_calls($log, 'send')), wo_st($c), wo_counted($c, $count)],
                     ['uncertain', 'stale_claim', $out, 1, 'PASS', 1], "KC25 9–10 ({$out}): swept uncertain(stale_claim), the ledger keeps {$out}; PASS, printed", wo_detail($c));
            if ($out === 'not_sent') $ctx->eq(wo_counted($c, 'stale claims certainly not sent'), 1, 'KC25 … and C-O2-2 counts the not_sent one apart', wo_detail($c));
        }
        // 6–7, then a message on another handset: step 4 moves the row; the claim there answers in_flight, then already_sent
        [$b, $id, $log, , $gw, $clk] = $book();
        $moved = false;
        wo_krun($ctx, $b, $clk, $gw, 'UPDATE wa_offer_recipients SET ledger_claim', function (WoStore $s) use ($clk, &$moved): void {
            $s->onLoad = function (string $f) use ($clk, &$moved): void { if ($f === 'retailers.json' && !$moved) { $moved = true; $clk->t += 60; } };   // step 4: the ledger claim a minute later
        });
        $c2 = wo_conv($b, $p, 'support');                                   // its latest message now on another handset, answered,
        wo_in($b, $c2, 'Writing on the support number (test)', $T0 - 3 * 3600);    // older than the 120-minute wait (F15's shape)
        wo_out($b, $c2, 'Answered (test)', $T0 - 3 * 3600 + 30);
        $at = [];
        foreach ([905, 910, 970] as $s) {
            $clk->t = $T0 + $s;
            wo_krun($ctx, $b, $clk, $gw);
            $r = wo_row($b['pdo'], $p);
            $at[] = [$r['state'], $r['handset'], $r['reason']];
        }
        $ctx->eq($at, [['queued', 'i:dishnet-ug', 'moved'], ['queued', 'i:dishnet-ug', 'moved'], ['uncertain', 'i:dishnet-ug', 'ledger_already_sent']],
                 'KC25 6–7 then a move: swept and moved to the other handset, its claim there in_flight (released), then already_sent');
        $c = $live($b, $T0 + 1200);
        $ctx->eq([wo_kled($b, $id, (int)$r['id']), count(wo_calls($log, 'send')), wo_st($c), wo_counted($c, 'already-sent rows on another handset')],
                 [[['uncertain', 'stale', 'i:sales-ug']], 0, 'PASS', 1], 'KC25 … the ledger row on the old handset; C-O2-4 matches it by ref and prints the handset; PASS (OM115)', wo_detail($c));
    });

    $ctx->run('KC26 (12) a claim left unswept on Part F\'s real ledger: killed in the POST, then off sending, runs that sweep nothing; 16 minutes on --check offers-live PASS with both counted; after on ack and a run, swept, PASS (OM121)', function () use ($ctx, $T0) {
        $p = '256700952601';
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, [$p => 'sales']);
        $gw->onSend = function (): void { throw new WoKill('killed in the POST'); };
        wo_krun($ctx, $b, $clk, $gw);
        $gw->onSend = null;
        wo_setcfg($b, ['wa_send_ledger' => null]);                         // off sending: offers_live_enabled stays set, no hook runs
        $codes = [];
        foreach ([5, 10, 16] as $m) { $clk->t = $T0 + $m * 60; $codes[] = wo_krun($ctx, $b, $clk, $gw)['code']; }
        $r = wo_row($b['pdo'], $p);
        $ctx->eq([$codes, $r['state'], wo_kled($b, $id, (int)$r['id'])], [['ledger_off', 'ledger_off', 'ledger_off'], 'claimed', [[null, null, 'i:sales-ug']]],
                 'KC26 control: the runs after off sending return ledger_off at step 3 and sweep nothing');
        $c = wo_klive($b, $T0 + 16 * 60);
        $ctx->eq([wo_st($c), wo_counted($c, 'claimed, not yet swept'), wo_counted($c, 'ledger rows in flight, not yet swept')], ['PASS', 1, 1],
                 'KC26 16 minutes after the claim: PASS, the row claimed and its ledger row in flight, each counted not yet swept', wo_detail($c));
        wo_setcfg($b, ['wa_send_ledger' => 'on']);                         // on ack
        $clk->t = $T0 + 17 * 60;
        wo_krun($ctx, $b, $clk, $gw);
        $r = wo_row($b['pdo'], $p);
        $c = wo_klive($b, $T0 + 18 * 60);
        $ctx->eq([$r['state'], $r['reason'], wo_kled($b, $id, (int)$r['id']), count(wo_calls($log, 'send')), wo_st($c)],
                 ['uncertain', 'stale_claim', [['uncertain', null, 'i:sales-ug']], 1, 'PASS'], 'KC26 after on ack and the next run: uncertain(stale_claim), its ledger row uncertain; PASS', wo_detail($c));
    });

    $ctx->run('KC27 (13) a settle that did not land, on Part F\'s real ledger: the book\'s write lock held past the busy_timeout across step 9, released before step 10; PASS with settle_not_landed, before the orphan sweep and after it (OM123)', function () use ($ctx, $T0) {
        foreach (['sent' => null, 'uncertain' => new OfferSendResult(OfferSendResult::MAYBE_SENT, 'may_have_been_sent', 'WO-KC27-M', false, 'claimed')] as $state => $res) {
            $p = $state === 'sent' ? '256700952701' : '256700952702';
            [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, [$p => 'sales']);
            if ($res !== null) $gw->results = [$res];
            $ready = $ctx->dir('kc27') . '/ready';
            $held = 0;
            wo_krun($ctx, $b, $clk, $gw, null, function (WoStore $s) use ($b, $ready, &$held): void {
                $s->pdo->hooks[] = ['UPDATE wa_auto_sends SET outcome = ?, settled_at = ?', function () use ($b, $ready, &$held): void {
                    $held = time();
                    wo_bg('hold', $b['data'] . '/plugin.sqlite3', '6', $ready);   // step 9's settle waits 5 s and is swallowed; step 10 lands after it
                }];
            });
            $r = wo_row($b['pdo'], $p);
            $ctx->eq([$held > 0, $r['state'], wo_kled($b, $id, (int)$r['id'])], [true, $state, [[null, null, 'i:sales-ug']]],
                     "KC27 ({$state}) control: step 9's settle did not land, step 10 settled the row {$state}; its ledger row still in flight");
            wo_kseen($b);
            $c = wo_klive($b, $T0 + 600);
            $ctx->eq([wo_st($c), wo_counted($c, 'settle_not_landed')], ['PASS', 1], "KC27 ({$state}) before any later claim on the handset: PASS, settle_not_landed counted", wo_detail($c));
            $q = wo_ledger_of($b, new WoClock($T0 + 960))->claim('Sales-UG', 'quote', 'qc:KC27:' . $state, ['phone' => '256700952799']);
            $c = wo_klive($b, $T0 + 1200);
            $ctx->eq([!empty($q['ok']), wo_kled($b, $id, (int)$r['id']), wo_st($c), wo_counted($c, 'settle_not_landed')],
                     [true, [['uncertain', 'stale_orphan', 'i:sales-ug']], 'PASS', $state === 'sent' ? 1 : 0],
                     "KC27 ({$state}) after a claim on its handset 900 s later (Part F's orphan rule): the ledger row stale_orphan; PASS" . ($state === 'sent' ? ', settle_not_landed counted' : ''), wo_detail($c));
        }
    });

    $ctx->run('KC28 (B11-01) a 6–7 orphan re-claimed 20 s before the close on Part F\'s real ledger: uncertain(ledger_already_sent) with that later sent_at, judged by its ledger row\'s claimed_at: PASS (OM94)', function () use ($ctx) {
        $close = qf_t('2026-11-02 20:00:00');
        $p = '256700952801';
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, [$p => 'sales']);
        $clk->t = $close - 1200;
        wo_krun($ctx, $b, $clk, $gw, 'UPDATE wa_offer_recipients SET ledger_claim');
        $clk->t = $close - 20;
        wo_krun($ctx, $b, $clk, $gw);
        $r = wo_row($b['pdo'], $p);
        $ctx->eq([$r['state'], $r['reason'], $r['sent_at'], count(wo_calls($log, 'send'))], ['uncertain', 'ledger_already_sent', UtcClock::stamp($close - 20), 0],
                 'KC28 control: the orphan from 19:40 re-claimed at 19:59:40 answers already_sent; nothing sent');
        $c = wo_klive($b, $close + 3600);
        $ctx->eq([wo_st($c), wo_counted($c, 'claims outside their window')], ['PASS', 0], 'KC28 C-O2-2 judges it by its ledger row\'s claimed_at (19:40) and the window it recorded: PASS', wo_detail($c));
    });

    $ctx->run('KC29 (A12-05) an offer sent through P6\'s path, the reply after it, 24 h quiet, an enquiry follow-up opened by the real followup_scan: PASS while open and once FollowUpService::close() closed it; a row judged after its opened_at FAIL (OM96)', function () use ($ctx) {
        $T = wo_kpast(2);                                                  // the injected clock more than 24 h behind the real one
        $p = '256700952901'; $q = '256700952902';
        [$b, $id, $log, , $gw, $clk] = wo_fx($ctx, [$p => 'sales'], ['followup_enabled' => '1'], ['t0' => $T]);
        $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => $T - 85 * 60, 'duration_ms' => 1000]]);
        wo_ksender($ctx, $b, $clk, $gw)->runOnce(null);
        wo_kseen($b);
        $r = wo_row($b['pdo'], $p);
        $ctx->eq($r['state'] ?? null, 'sent', 'KC29 control: the offer sent through P6\'s path at the injected clock');
        wo_in($b, (int)$r['conversation_id'], 'Thanks, tell me more (test)', $T + 3600);    // the customer's reply after it, then quiet
        $cq = wo_conv($b, $q, 'sales');                                    // another customer, not in the offer, quiet as long
        wo_in($b, $cq, 'Hello, a question (test)', $T + 3600);
        [$rc, $out] = wo_kscan($ctx, $b);
        $fu = function (string $phone) use ($b): array {
            $st = $b['pdo']->prepare("SELECT id, opened_at, closed_at FROM followups WHERE phone = ? AND opened_by = 'scan' ORDER BY id");
            $st->execute([$phone]);
            return $st->fetchAll(\PDO::FETCH_ASSOC);
        };
        $f1 = $fu($p); $f2 = $fu($q);
        $ctx->eq([$rc, count($f1), count($f2), ($f1[0]['opened_at'] ?? '') >= UtcClock::stamp(time() - 600), array_key_exists('closed_at', $f1[0] ?? []) ? $f1[0]['closed_at'] : 'x'], [0, 1, 1, true, null],
                 'KC29 control: the real followup_scan opened an enquiry follow-up for each, opened_at its datetime(\'now\') default', $out);
        $c = wo_klive($b, time());
        $ctx->eq(wo_st($c), 'PASS', 'KC29 the follow-up opened after the offer\'s judged_at, still open: PASS', wo_detail($c));
        (new FollowUpService($b['pdo']))->close((int)$f1[0]['id'], 'replied', 'test');
        $ctx->eq($fu($p)[0]['closed_at'] !== null, true, 'KC29 control: FollowUpService::close() closed it');
        $ctx->eq(wo_st(wo_klive($b, time())), 'PASS', 'KC29 … and once closed: PASS');
        // the control: a row planted sent for the other phone, judged after its (still open) follow-up's opened_at
        wo_good_send($b, $id, $q, UtcClock::parse((string)$f2[0]['opened_at']) + 60, ['conv' => $cq]);
        $ctx->eq(in_array('followup_open', wo_kfails(wo_klive($b, time() + 600)), true), true, 'KC29 control: a row judged while that follow-up was open reads FAIL followup_open');
    });

    $ctx->run('KC30 (Q17-O01) a test whose request died — after its INSERT, before the send; after record() answered, before its settle — each swept by the next press 16 minutes on, the off-hook, the sender\'s step 4 and the by-hand put-back: --check offers-test PASS, the swept test counted (OM118)', function () use ($ctx, $T0) {
        wo_ladder_stub($ctx);
        foreach (['press', 'hook', 'sender', 'by_hand'] as $how) {
            foreach (['before_send' => 'no ledger row', 'before_settle' => 'their ledger row'] as $where => $what) {
                $b = wo_book($ctx, wo_cfg(), 'kc');
                wo_admins($b);
                foreach (['retailers.json', 'kyc_config.json', 'master_schedule.json'] as $f) $b['store']->load($f);
                $id = wo_offer($b, $T0 - 7200);
                $admin = $b['store']->load('retailers.json')[0];
                $gw = new WoGateway(new WoLog());
                $clk = new WoClock($T0 - 3600);
                $sender = function (?string $kill = null) use ($ctx, $b, $clk, $gw): OfferSender {
                    $store = new WoStore($b['store'], $b['data']);
                    if ($kill !== null) $store->pdo->hooks[] = [$kill, function (): void { throw new WoKill('killed'); }];
                    return OfferSender::forInstall($ctx->tree(), $b['data'], $b['cfg'], ['store' => $store, 'clock' => $clk, 'gateway' => function () use ($gw) { return $gw; },
                                                    'wait' => function (int $s): void {}]);
                };
                foreach ([1, 2] as $v) { $sender()->sendTest($id, $v, 'sales', $admin); $clk->t += 120; }   // each wording tested and recorded
                $threw = '';
                if ($where === 'before_send') $gw->onSend = function (): void { throw new WoKill('killed'); };
                try { $sender($where === 'before_settle' ? 'UPDATE wa_offer_tests SET state = ?, reason = ?, ledger = ?' : null)->sendTest($id, 1, 'sales', $admin); }
                catch (WoKill $e) { $threw = 'killed'; }
                $gw->onSend = null;
                $dead = $b['pdo']->query("SELECT id FROM wa_offer_tests WHERE state = 'sending'")->fetchColumn();
                $ctx->eq([$threw, $dead !== false, count(wo_lrows($b['pdo'], 'offer_test:' . (int)$dead))], ['killed', true, $where === 'before_settle' ? 1 : 0],
                         "KC30 {$where}, {$how} control: the request died with its test sending" . ($where === 'before_settle' ? ', record() having written its ledger row' : ''));
                $clk->t += 16 * 60;
                if ($how === 'press') $sender()->sendTest($id, 2, 'sales', $admin);
                elseif ($how === 'hook') OfferSwitch::offHook($b['pdo'], $b['cfg'], $b['data'], 'offers-test', 'switch');
                elseif ($how === 'sender') $sender()->runOnce(null);
                else $b['pdo']->exec("UPDATE wa_offer_tests SET state = 'uncertain', settled_at = datetime('now') WHERE state = 'sending' AND started_at < datetime('now', '-15 minutes')");
                $b['pdo']->exec("INSERT OR IGNORE INTO evo_webhook_seen (message_id, instance, event) SELECT wa_message_id, 'Sales-UG', 'offer.test' FROM wa_offer_tests WHERE wa_message_id IS NOT NULL");
                $st = $b['pdo']->query('SELECT state, ledger FROM wa_offer_tests WHERE id = ' . (int)$dead)->fetch(\PDO::FETCH_NUM);
                $c = OfferSwitch::checkTest(wo_ro($b), $b['cfg'], $b['data'], $clk->t);
                $ctx->eq([$st, wo_st($c), wo_counted($c, 'swept tests, with ' . $what)], [['uncertain', null], 'PASS', 1],
                         "KC30 {$where}, swept by the {$how}: uncertain with ledger NULL; --check offers-test PASS, counted as swept with {$what}", wo_detail($c));
            }
        }
    });
}

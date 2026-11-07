<?php
declare(strict_types=1);
/**
 * Section B of tests/test_quote_followup.php — evidence and tracking, F12 (5.18.104, root docs/73 §H.2.9.1–§H.2.9.3,
 * §H.2.9.5, §H.2.9.6, §H.2.14 B). TEST ONLY.
 *
 * The evidence list and the markers (each marker a literal of the live sender that writes it), one tracker row per
 * quote_key with the earliest row winning, the key from the number, the uCRM id mapped, 'PENDING' ignored, no row from a
 * claim with no message, backfilled before quote_tracking_since and again after tracking was switched off and on
 * (R-17), valid_until per marker, cron/quote_chase.php on and off — and §H.2.9.6's paths through the REAL senders:
 * QuotationService's own sends in-process (plugin-only manual, lead, the LTE form, cash, a refusal, the Failed Queue's
 * late retry), and under php -S the real plugin with the fake uCRM and the fake Evolution (uCRM's quote.add, the quote
 * screen with uCRM ticked, the webhook winning the screen's claim, the quote cron's flows A and B, quote.add's deferred
 * form with an earlier KYC application, the WhatsApp tab's text fallback). Fix round 1 adds B08 (paid set when the row
 * is made, §H.2.9.5) and B09: paths 1, 2, 3 and 8 on quote_from_ucrm's section-F harness (the fake uCRM of the quoting
 * organization with the link seed and the direct catalogue): the KYC form's handler for a web and a web-direct sale,
 * retry_quote after P1 (Make the quotation's implementation), the admin's Retry (KycCrmSync), a standard quotation from
 * Send Quotation and from the mobile API, with cron A's 📋 and the webhook's 🆔 — each row owned by rule 1 on the
 * application the real path stored (2 or 3 for path 8). Every person and number is fictitious.
 *
 * The helpers here (qf_tcfg, qf_blob, qf_qmsg, qf_track, qf_trow …) are shared by sections C, E and F: the runner loads
 * every section file before it runs any.
 */

/** The tracker's configuration: Uganda with the ledger on, tracking on since $since (UTC). */
function qf_tcfg(array $extra = [], string $since = '2026-11-01 00:00:00'): array
{
    return $extra + QF_UG + ['quote_tracking_enabled' => '1', 'quote_tracking_since' => $since];
}

/** A quotation text as QuotationService::buildProformaMessage() writes its head (lib/QuotationService.php:507-516). */
function qf_ref_text(string $ref, string $validUntil = '20 Nov 2026'): string
{
    return "━━━━━━━━━━━━━━━━━━━━━━\n🏢 *Test Company*\n📄 *Quotation*\n━━━━━━━━━━━━━━━━━━━━━━\n\n📋 *Ref:* {$ref}\n"
         . "👤 *To:* Test Customer\n📅 *Date:* 02 Nov 2026\n⏳ *Valid Until:* {$validUntil}\n\n━━━━━━━━━━━━━━━━━━━━━━\n  • Kit — 1\n";
}

/** The webhook's quote.add text head (webhook.php:2998-3002). */
function qf_order_text(string $num): string
{
    return "📄 *Quotation & Order Summary*\n\nDear Test Customer,\n\nThank you for choosing DishNet! 🌐\n\n🆔 *Order:* {$num}\n📍 *Location:* Test Road\n";
}

/** The WhatsApp tab's text fallback head (includes/api/api_whatsapp.php:665). */
function qf_quote_text(string $num): string
{
    return "📄 *Quote #{$num}*\n\nDear Test Customer,\n\nHere is your DishNet quotation:\n\n  • Kit — 1\n\nValid for 30 days.\n";
}

/** A row in one of the store's JSON collections (id, data), as SqliteStore keeps them. */
function qf_blob(\PDO $pdo, string $table, array $data): int
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS [{$table}] (id INTEGER PRIMARY KEY, data TEXT NOT NULL)");
    if (isset($data['id'])) {
        $pdo->prepare("INSERT INTO [{$table}] (id, data) VALUES (?, ?)")->execute([(int)$data['id'], json_encode($data)]);
        return (int)$data['id'];
    }
    $pdo->prepare("INSERT INTO [{$table}] (data) VALUES (?)")->execute([json_encode($data)]);
    $id = (int)$pdo->lastInsertId();
    $data['id'] = $id;
    $pdo->prepare("UPDATE [{$table}] SET data = ? WHERE id = ?")->execute([json_encode($data), $id]);
    return $id;
}

/** An outbound quotation row as sendVia stores it (lib/NotificationService.php:2462-2479); its id. */
function qf_qmsg(\PDO $pdo, int $conv, string $event, string $body, string $sentAt, ?string $createdAt = null): int
{
    $pdo->prepare("INSERT INTO wa_messages (conversation_id, direction, role, body, agent_name, event_key, sent_at, created_at)
                   VALUES (?, 'out', 'agent', ?, 'DishNet Plugin', ?, ?, ?)")->execute([$conv, $body, $event, $sentAt, $createdAt ?? $sentAt]);
    return (int)$pdo->lastInsertId();
}

/** An inbound row from the customer. */
function qf_in(\PDO $pdo, int $conv, string $body, string $sentAt, ?string $createdAt = null): void
{
    $pdo->prepare("INSERT INTO wa_messages (conversation_id, direction, role, body, sent_at, created_at) VALUES (?, 'in', 'customer', ?, ?, ?)")
        ->execute([$conv, $body, $sentAt, $createdAt ?? $sentAt]);
}

/** A staff account in retailers.json. */
function qf_staff(\PDO $pdo, int $id, string $name, bool $active = true, array $extra = []): void
{
    qf_blob($pdo, 'retailers', ['id' => $id, 'name' => $name, 'is_active' => $active, 'role' => 'sales'] + $extra);
}

/** One tracker run at the Kampala wall-clock moment $at (or a unix time), with the book's own configuration. */
function qf_track(array $b, $at, ?array $cfg = null, ?int $until = null): array
{
    return QuoteTracker::run($b['pdo'], $cfg ?? $b['cfg'], $b['data'], $b['store'], is_int($at) ? $at : qf_t($at), $until ?? time() + 120);
}

function qf_trow(\PDO $pdo, string $key): ?array
{
    $st = $pdo->prepare('SELECT * FROM quote_tracker WHERE quote_key = ?');
    $st->execute([$key]);
    $r = $st->fetch(\PDO::FETCH_ASSOC);
    return is_array($r) ? $r : null;
}

function qf_state(\PDO $pdo, string $k): ?string
{
    $st = $pdo->prepare('SELECT v FROM quote_followup_state WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

/** The fields of a tracker row a case compares. */
function qf_pick(?array $r, array $f): ?array
{
    if ($r === null) return null;
    $o = [];
    foreach ($f as $k) $o[$k] = $r[$k] === null ? null : (is_numeric($r[$k]) && in_array($k, ['quote_id', 'owner_staff_id', 'backfilled', 'crm_client_id', 'evidence_msg_id', 'sent_conv_id', 'spoke_conv_id'], true) ? (int)$r[$k] : (string)$r[$k]);
    return $o;
}

$QF_SECTIONS['B'] = ['title' => 'Evidence and tracking, F12 (§H.2.9.1–§H.2.9.3, §H.2.9.5, §H.2.9.6)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'QuoteSendEvidence', 'QuoteTracker', 'NotificationRetry'] as $l) require_once $tree . '/lib/' . $l . '.php';

    $ctx->run('B01 the evidence: the events, the markers each a literal of its sender, read() and key()', function () use ($ctx, $tree) {
        $quoteEvents = array_values(array_filter(NotificationRetry::EVENTS, function ($e) { return strpos($e, 'quote') !== false && $e !== 'quote_cash'; }));
        $ctx->eq(QuoteSendEvidence::EVENTS, $quoteEvents, 'B01 EVENTS = NotificationRetry::EVENTS\' quotation subset minus quote_cash (lib/NotificationRetry.php:32)');
        $src = ['ref' => 'lib/QuotationService.php', 'order' => 'webhook.php', 'quote' => 'includes/api/api_whatsapp.php'];
        $found = [];
        foreach (QuoteSendEvidence::MARKERS as $k => $m) $found[$k] = strpos((string)file_get_contents($tree . '/' . $src[$k]), rtrim($m)) !== false;
        $ctx->eq($found, ['ref' => true, 'order' => true, 'quote' => true], 'B01 each marker is a literal of the sender that writes it');
        $ctx->t(strpos((string)file_get_contents($tree . '/lib/QuotationService.php'), QuoteSendEvidence::VALID_LINE . '{$validUntil}') !== false,
            'B01 the validity line is QuotationService\'s literal');
        $row = function (string $body, string $event = 'quote_manual', string $dir = 'out') { return ['direction' => $dir, 'event_key' => $event, 'body' => $body]; };
        $ctx->eq([QuoteSendEvidence::read($row(qf_ref_text('MAN-202611-007'))), QuoteSendEvidence::read($row(qf_order_text('Q-3401'), 'ops_quote_created')),
                  QuoteSendEvidence::read($row(qf_quote_text('Q-3402'), 'ops_quote_text'))],
                 [['ref', 'MAN-202611-007'], ['order', 'Q-3401'], ['quote', 'Q-3402']], 'B01 control: the three markers give their numbers');
        $ctx->eq([QuoteSendEvidence::read($row(qf_ref_text('X-1'), 'quote_manual', 'in')), QuoteSendEvidence::read($row(qf_ref_text('CASH-1'), 'quote_cash')),
                  QuoteSendEvidence::read($row("📋 Quote #Q-1 sent to a customer", 'ops_quote_admin_cc')), QuoteSendEvidence::read($row('Thank you, no marker', 'quote_manual'))],
                 [null, null, null, null], 'B01 inbound, the cash proforma, the admins\' copy and a text without a marker are not evidence');
        $ctx->eq([QuoteSendEvidence::key(' #Q-3401 '), QuoteSendEvidence::key('MAN-202611-007')], ['ref:Q-3401', 'ref:MAN-202611-007'],
                 'B01 quote_key = ref: + the number, trimmed, a leading # removed');
    });

    $ctx->run('B02 valid_until per marker (§H.2.9.3; QM34)', function () use ($ctx) {
        $S = '2026-11-02 07:00:00';   // Monday 10:00 Kampala
        $ctx->eq([QuoteSendEvidence::validUntil('ref', qf_ref_text('A', '20 Nov 2026'), $S, false),
                  QuoteSendEvidence::validUntil('ref', qf_ref_text('A', '20 Nov 2026'), $S, true),
                  QuoteSendEvidence::validUntil('ref', qf_ref_text('A', '05 Nov 2026'), $S, true),
                  QuoteSendEvidence::validUntil('order', qf_order_text('Q-1'), $S, false),
                  QuoteSendEvidence::validUntil('quote', qf_quote_text('Q-1'), $S, false),
                  QuoteSendEvidence::validUntil('quote', qf_quote_text('Q-1'), $S, true),
                  QuoteSendEvidence::validUntil('ref', qf_ref_text('A', 'soon'), $S, false)],
                 ['2026-11-20', '2026-11-09', '2026-11-05', '2026-11-09', '2026-12-02', '2026-11-09', '2026-11-09'],
                 'B02 the stated date; with a uCRM id the earlier of it and the send + 7; 🆔 + 7; 📄 + 30, or + 7 with an id; an unreadable date + 7');
        $ctx->eq(QuoteSendEvidence::validUntil('order', qf_order_text('Q-1'), '2026-11-02 21:30:00', true), '2026-11-10',
                 'B02 the send date is Kampala\'s (21:30 UTC is 00:30 the next day there)');
    });

    $ctx->run('B03 one row per quotation: the earliest wins, the key is the number, the id mapped, PENDING ignored (QM32, QM33)', function () use ($ctx) {
        $b = $ctx->book(qf_tcfg(), 'b'); $p = $b['pdo'];
        $c = qf_conv($p, '256772501001');
        $first = qf_qmsg($p, $c, 'quote_manual', qf_ref_text('Q-5001'), '2026-11-02 07:00:00');
        qf_qmsg($p, $c, 'ops_quote_text', qf_quote_text('Q-5001'), '2026-11-02 08:00:00');      // a resend of the same number
        $p->exec("CREATE TABLE IF NOT EXISTS wa_sent_quotes (quote_id INTEGER NOT NULL, quote_ref TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT '', sent_at TEXT NOT NULL DEFAULT (datetime('now')), PRIMARY KEY (quote_id))");
        $p->exec("INSERT INTO wa_sent_quotes (quote_id, quote_ref, source) VALUES (5001, 'Q-5001', 'plugin_manual'), (5009, 'Q-5009', 'webhook')");
        $c2 = qf_conv($p, '256772501002');
        qf_blob($p, 'kyc_applications', ['quote_ref' => 'PF-5002', 'quote_id' => 'PENDING', 'crm_client_id' => 77, 'mobile' => '0772501002']);
        qf_qmsg($p, $c2, 'ops_quote_wa', qf_ref_text('PF-5002'), '2026-11-02 07:05:00');
        $c3 = qf_conv($p, '256772501003');
        qf_blob($p, 'quotes_log', ['type' => 'manual', 'quote_ref' => 'Q-5003', 'crm_quote_id' => 5003, 'sent_by' => 'nobody']);
        qf_qmsg($p, $c3, 'quote_manual', qf_ref_text('Q-5003'), '2026-11-02 07:06:00');
        $r = qf_track($b, '2026-11-02 11:00:00');
        $ctx->eq((int)$p->query('SELECT COUNT(*) FROM quote_tracker')->fetchColumn(), 3, 'B03 three quotations, three rows (a resend of the same number is no new quotation)');
        $ctx->eq(qf_pick(qf_trow($p, 'ref:Q-5001'), ['quote_ref', 'quote_id', 'evidence_msg_id', 'evidence_event', 'sent_at']),
                 ['quote_ref' => 'Q-5001', 'quote_id' => 5001, 'evidence_msg_id' => $first, 'evidence_event' => 'quote_manual', 'sent_at' => '2026-11-02 07:00:00'],
                 'B03 the earliest confirming row wins; the uCRM id from wa_sent_quotes; the key is the number\'s, never the id\'s');
        $ctx->eq([qf_pick(qf_trow($p, 'ref:PF-5002'), ['quote_id']), qf_pick(qf_trow($p, 'ref:Q-5003'), ['quote_id'])], [['quote_id' => null], ['quote_id' => 5003]],
                 'B03 a KYC application\'s \'PENDING\' maps no id; quotes_log\'s crm_quote_id does');
        $ctx->eq([qf_trow($p, 'ref:Q-5009'), $r['inserted']], [null, 3], 'B03 a claim with no message gives no row (wa_sent_quotes Q-5009 alone)');
    });

    $ctx->run('B04 backfilled before quote_tracking_since, and every catch-up after tracking was off and on again (R-17; QM51)', function () use ($ctx) {
        $b = $ctx->book(qf_tcfg([], '2026-11-02 07:30:00'), 'b'); $p = $b['pdo'];
        $c = qf_conv($p, '256772501011');
        qf_qmsg($p, $c, 'quote_manual', qf_ref_text('Q-5011'), '2026-11-02 07:00:00');
        qf_qmsg($p, qf_conv($p, '256772501012'), 'quote_manual', qf_ref_text('Q-5012'), '2026-11-02 08:00:00');
        qf_track($b, '2026-11-02 12:00:00');
        $ctx->eq([(int)qf_trow($p, 'ref:Q-5011')['backfilled'], (int)qf_trow($p, 'ref:Q-5012')['backfilled']], [1, 0],
                 'B04 sent before quote_tracking_since: backfilled; after it: not');
        // tracking off (no run), quotations meanwhile, then on again: since re-stamped (TL6), the catch-up is backfilled
        qf_qmsg($p, qf_conv($p, '256772501013'), 'quote_manual', qf_ref_text('Q-5013'), '2026-11-03 09:00:00');
        qf_qmsg($p, qf_conv($p, '256772501014'), 'quote_manual', qf_ref_text('Q-5014'), '2026-11-04 10:00:00');
        qf_track($b, '2026-11-04 14:00:00', qf_tcfg(['_data_dir' => $b['data']], '2026-11-04 09:30:00'));
        $ctx->eq([(int)qf_trow($p, 'ref:Q-5013')['backfilled'], (int)qf_trow($p, 'ref:Q-5014')['backfilled']], [1, 0],
                 'B04 after off and on: the quotation of the dark day backfilled, the one since the new stamp not');
        $b2 = $ctx->book(qf_tcfg(['quote_tracking_since' => '']), 'b'); $p2 = $b2['pdo'];
        qf_qmsg($p2, qf_conv($p2, '256772501015'), 'quote_manual', qf_ref_text('Q-5015'), '2026-11-02 08:00:00');
        qf_track($b2, '2026-11-02 12:00:00');
        $ctx->eq((int)qf_trow($p2, 'ref:Q-5015')['backfilled'], 1, 'B04 no readable quote_tracking_since: backfilled (never chased)');
    });

    $ctx->run('B05 the real senders in-process: plugin-only manual (6), lead (7), the LTE form (12), cash (13), a refusal (14), the late retry (15)', function () use ($ctx, $tree) {
        $evo = qf_fake_evo();
        $ctx->t($evo !== '', 'B05 control: the fake Evolution answers');
        if ($evo === '') return;
        foreach (['QuotationService', 'NotificationService'] as $l) require_once $tree . '/lib/' . $l . '.php';
        $since = gmdate('Y-m-d H:i:s', time() - 3600);
        $b = $ctx->book(qf_tcfg(['evo_api_url' => $evo, 'evo_api_key' => 'fake-key-qf'], $since), 'b5'); $p = $b['pdo']; $st = $b['store'];
        $st->save('kyc_config.json', $b['cfg']);
        $st->save('retailers.json', [['id' => 31, 'name' => 'Test Seller One', 'is_active' => true, 'role' => 'sales'],
                                     ['id' => 32, 'name' => 'Test Lead Owner', 'is_active' => true, 'role' => 'sales']]);
        $st->save('leads.json', [['id' => 41, 'customer_name' => 'Test Lead', 'phone' => '0772501023', 'assigned_to' => 32, 'service_type' => 'starlink']]);
        $me = ['id' => 31, 'name' => 'Test Seller One'];
        $kit = [['label' => 'Test Kit', 'quantity' => 1, 'price' => 100000]];
        $qs = new QuotationService($st, $b['data'], $b['cfg']);
        $m6 = $qs->sendManualQuote(['customer_name' => 'Test Customer', 'customer_phone' => '0772501021', 'items' => $kit, 'create_crm_quote' => false], $me);
        $m7 = $qs->sendLeadQuote(['id' => 41, 'customer_name' => 'Test Lead', 'phone' => '0772501023', 'assigned_to' => 32], $kit, $me, false);
        $ok12 = $qs->sendKycQuoteWhatsApp(['mobile' => '0772501024', 'firstname' => 'Test', 'lastname' => 'Subscriber', 'quote_ref' => 'LTE-20261105-9', 'crm_client_id' => 0],
                                          $kit, 7024, $me);
        $m13 = $qs->sendCashSaleProforma('Test Cash', '0772501025', $kit, 100000.0, $me);
        $m14 = $qs->sendManualQuote(['customer_name' => 'Test Refused', 'customer_phone' => '0772501026', 'create_crm_quote' => false,
                                     'items' => [['label' => 'Test Kit', 'quantity' => 1, 'price' => 0]]], $me);
        $ctx->eq([!empty($m6['ok']), !empty($m7['ok']), $ok12, !empty($m13['ok']), !empty($m14['ok'])], [true, true, true, true, false],
                 'B05 control: four sends made; a price-0 line refused on Uganda (nothing written or sent)');
        // 15: a send that failed and went to the Failed Queue, then its retry succeeded
        qf_http('GET', $evo . '/__test/fail_next?n=1');
        $m15 = $qs->sendManualQuote(['customer_name' => 'Test Retry', 'customer_phone' => '0772501027', 'items' => $kit, 'create_crm_quote' => false], $me);
        $q = (int)$p->query("SELECT id FROM notification_queue WHERE event = 'quote_manual' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $before = (int)$p->query("SELECT COUNT(*) FROM wa_messages WHERE event_key = 'quote_manual'")->fetchColumn();
        $retry = (new NotificationService($st, $b['cfg']))->retryOne($q, 'Test Admin');
        $ctx->eq([!empty($m15['ok']), $q > 0, !empty($retry['success']), (int)$p->query("SELECT COUNT(*) FROM wa_messages WHERE event_key = 'quote_manual'")->fetchColumn() - $before],
                 [true, true, true, 1], 'B05 control: the failed send was queued, and the retry stored its row on success');
        qf_track($b, time() + 60);
        $f = ['made_by', 'owner_source', 'owner_staff_id', 'evidence_event', 'status', 'backfilled'];
        $ctx->eq(qf_pick(qf_trow($p, 'ref:' . $m6['quote_ref']), $f),
                 ['made_by' => 'manual', 'owner_source' => 'quotes_log', 'owner_staff_id' => 31, 'evidence_event' => 'quote_manual', 'status' => 'open', 'backfilled' => 0],
                 'B05 path 6 (plugin-only MAN-): quote_manual 📋, owner rule 3 (quotes_log)');
        $ctx->eq(qf_pick(qf_trow($p, 'ref:' . $m7['quote_ref']), $f),
                 ['made_by' => 'lead', 'owner_source' => 'lead_owner', 'owner_staff_id' => 32, 'evidence_event' => 'quote_lead', 'status' => 'open', 'backfilled' => 0],
                 'B05 path 7 (a lead quotation): quote_lead 📋, owner rule 2 (the lead\'s assigned_to, not its maker)');
        $ctx->eq(qf_pick(qf_trow($p, 'ref:LTE-20261105-9'), array_merge($f, ['quote_id'])),
                 ['made_by' => 'lte', 'owner_source' => 'quotes_log', 'owner_staff_id' => 31, 'evidence_event' => 'quote_kyc', 'status' => 'open', 'backfilled' => 0, 'quote_id' => 7024],
                 'B05 path 12 (the LTE registration\'s form): quote_kyc 📋, the uCRM id from its claim, owner rule 3');
        $ctx->eq([(int)$p->query("SELECT COUNT(*) FROM quote_tracker WHERE quote_ref LIKE 'CASH-%'")->fetchColumn(),
                  (int)$p->query("SELECT COUNT(*) FROM wa_messages WHERE event_key = 'quote_cash'")->fetchColumn()], [0, 1],
                 'B05 path 13 (the cash proforma): sent, and no row');
        $ctx->eq([(int)$p->query("SELECT COUNT(*) FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id WHERE c.phone = '256772501026'")->fetchColumn(),
                  (int)$p->query("SELECT COUNT(*) FROM quote_tracker WHERE phone = '256772501026'")->fetchColumn()], [0, 0], 'B05 path 14 (a refusal): nothing sent, no row');
        $r15 = qf_trow($p, 'ref:' . $m15['quote_ref']);
        $ctx->eq([qf_pick($r15, ['made_by', 'owner_source', 'evidence_event']), (int)$p->query("SELECT COUNT(*) FROM quote_tracker WHERE phone = '256772501027'")->fetchColumn()],
                 [['made_by' => 'manual', 'owner_source' => 'quotes_log', 'evidence_event' => 'quote_manual'], 1],
                 'B05 path 15 (the Failed Queue\'s late success): stored on success, one row, as its path');
        $ctx->eq((int)$p->query('SELECT COUNT(*) FROM quote_tracker')->fetchColumn(), 4, 'B05 four quotations sent, four rows');
    });

    $ctx->run('B06 the real plugin under php -S: quote.add (10), the screen (4), the webhook winning (5), flow B (9), the deferred form (10), the tab\'s fallback (11)', function () use ($ctx) {
        require_once dirname(__DIR__) . '/fixtures/staff_jobs_scenario.php';
        $s = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ], 'qfb6');
        file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
        $crm = SjScenario::crm();
        $client = function (int $id) { return ['id' => $id, 'firstName' => 'Test', 'lastName' => 'Client ' . $id, 'organizationId' => 1, 'isLead' => false,
                                               'contacts' => [['phone' => '+2567000009' . $id, 'isBilling' => true]]]; };
        $crm['clients']['15']['organizationId'] = 1;
        foreach ([16, 17, 18, 19] as $id) $crm['clients'][(string)$id] = $client($id);
        $quote = function (int $id, int $clientId) { return ['id' => $id, 'number' => 'Q-' . $id, 'clientId' => $clientId, 'status' => 1, 'createdDate' => date('c'),
            'items' => [['label' => 'Test Kit', 'quantity' => 1, 'price' => 2500000.0, 'total' => 2500000.0]], 'total' => 2500000.0]; };
        $crm['quotes'] = ['3401' => $quote(3401, 15)];
        $crm['organizations'] = [['id' => 1, 'name' => 'TEST Company One', 'selected' => true, 'currencyCode' => 'UGX']];
        $s->seedCrm($crm);
        SjScenario::staff($s);
        $drain = function () use ($s) { $s->http('GET', "{$s->base}?page=login"); };
        $screen = function (int $client) use ($s) {
            $s->login('admin', 'admin@example.test', 'sj-password-1');
            return $s->form('admin', ['action' => 'send_manual_quote', 'mq_crm_client_id' => (string)$client, 'mq_customer_name' => 'Test Client ' . $client,
                'mq_customer_phone' => '+2567000009' . $client, 'mq_create_crm' => '1', 'mq_note' => '',
                'mq_items_json' => json_encode([['label' => 'Test Kit', 'quantity' => 1, 'price' => 2500000, 'unit' => 'amount']])]);
        };
        $since = gmdate('Y-m-d H:i:s', time() - 600);
        // path 10, with a conversation whose lead names its owner (rule 5): the customer wrote first
        $s->evoInbound('256700000915', 'Hello, a price please (test)');
        $store = $s->store(); $pdo = $store->getPdo();
        require_once $ctx->tree() . '/lib/ConversationService.php';
        new ConversationService($s->data, $pdo);   // adds wa_conversations.lead_id, as every live writer does
        qf_blob($pdo, 'leads', ['id' => 61, 'customer_name' => 'Test Client 15', 'phone' => '256700000915', 'assigned_to' => $s->ids['lead']]);
        $pdo->exec("UPDATE wa_conversations SET lead_id = 61 WHERE phone = '256700000915'");
        $s->fire('quote.add', 'quote', 3401, 'q-3401'); $drain();
        [$rc] = $s->run('cron_quote_wa.php');          // flow B finds the webhook's claim: DEDUP BLOCK, no second text
        // path 4: the screen, uCRM ticked → Q-3500, then uCRM's quote.add for it stands down
        $s->seedCrm(['next_quote' => 3500]);
        $f4 = $screen(16);
        $s->fire('quote.add', 'quote', 3500, 'q-3500'); $drain();
        // path 5: the webhook's quote.add claimed Q-3600 first and sent it; the screen then made the same quote and stood down
        $s->seedCrm(['quotes' => ['3401' => $quote(3401, 15), '3600' => $quote(3600, 17)], 'next_quote' => 3600]);
        $s->fire('quote.add', 'quote', 3600, 'q-3600'); $drain();
        $f5 = $screen(17);
        // path 10's deferred form: an earlier KYC application for client 18; uCRM's quote.add defers to the cron, flow A sends
        $app18 = $store->appendWithId('kyc_applications.json', ['crm_client_id' => '18', 'sales_person' => 'Sandbox Leader', 'retailer_id' => $s->ids['acct'],
            'mobile' => '+256700000918', 'firstname' => 'Test', 'lastname' => 'Client 18', 'submitted_at' => date('Y-m-d H:i:s', time() - 86400 * 5), 'status' => 'approved']);
        // … and one for client 19 whose application has already had its quotation: no text at all, no row
        $store->appendWithId('kyc_applications.json', ['crm_client_id' => '19', 'sales_person' => 'Sandbox Leader', 'retailer_id' => $s->ids['acct'],
            'mobile' => '+256700000919', 'submitted_at' => date('Y-m-d H:i:s', time() - 86400 * 9), 'wa_quote_sent' => true, 'status' => 'approved']);
        $s->seedCrm(['quotes' => ['3401' => $quote(3401, 15), '3600' => $quote(3600, 17), '3800' => $quote(3800, 18), '3900' => $quote(3900, 19)]]);
        $s->fire('quote.add', 'quote', 3800, 'q-3800'); $drain();
        $s->fire('quote.add', 'quote', 3900, 'q-3900'); $drain();
        $p0 = new \PDO('sqlite:' . $s->data . '/plugin.sqlite3');
        foreach ($p0->query('SELECT id, data FROM kyc_applications')->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)$r['data'], true) ?: [];
            if (!empty($d['wa_quote_deferred_at'])) {
                $d['wa_quote_deferred_at'] = date('Y-m-d H:i:s', time() - 900);   // the cron waits 3 minutes; this is 15 later
                $p0->prepare('UPDATE kyc_applications SET data = ? WHERE id = ?')->execute([json_encode($d), (int)$r['id']]);
            }
        }
        // path 9: a quotation made in uCRM whose quote.add never came — the cron's flow B sends it (and flow A the deferred one)
        $s->seedCrm(['quotes' => ['3401' => $quote(3401, 15), '3600' => $quote(3600, 17), '3800' => $quote(3800, 18), '3900' => $quote(3900, 19), '3700' => $quote(3700, 16)]]);
        [$rc2] = $s->run('cron_quote_wa.php');
        // path 11: the WhatsApp tab's send, its quotation's PDF not to be had: the text fallback
        $r11 = $s->api('admin', 'POST', 'wa_send_quote_pdf', ['crm_quote_id' => 4100, 'phone' => '256700000914', 'cc_admin' => false]);
        $texts = array_map(function ($t) { return (string)$t['text']; }, $s->texts());
        $has = function (string $needle) use ($texts) { return count(array_filter($texts, function ($t) use ($needle) { return strpos($t, $needle) !== false; })); };
        $ctx->eq([$rc, $rc2, (int)$r11[0], $has('🆔 *Order:* Q-3401'), $has('📋 *Ref:* Q-3500'), $has('🆔 *Order:* Q-3600'), $has('📋 *Ref:* Q-3700'),
                  $has('📋 *Ref:* Q-3800'), $has('Q-3900'), $has('📄 *Quote #Q-4100*')],
                 [0, 0, 200, 1, 1, 1, 1, 1, 0, 1], 'B06 control: each path sent its one text (Q-3900: none, its application already had its quotation)', json_encode([$f4[0] ?? 0, $f5[0] ?? 0, $r11[1] ?? '']));

        $cfg = qf_tcfg(['_data_dir' => $s->data], $since) + $s->cfg;
        QuoteTracker::run($pdo, $cfg, $s->data, $store, time() + 60, time() + 120);
        $f = ['made_by', 'owner_source', 'owner_staff_id', 'evidence_event', 'quote_id', 'sent_channel'];
        $got = [];
        foreach (['Q-3401', 'Q-3500', 'Q-3600', 'Q-3700', 'Q-3800', 'Q-4100'] as $k) $got[$k] = qf_pick(qf_trow($pdo, 'ref:' . $k), $f);
        $ctx->eq($got['Q-3401'], ['made_by' => 'ucrm', 'owner_source' => 'conv_lead', 'owner_staff_id' => $s->ids['lead'], 'evidence_event' => 'ops_quote_created',
                                  'quote_id' => 3401, 'sent_channel' => 'support'], 'B06 path 10 (uCRM\'s quote.add): 🆔, its id from the webhook\'s claim, owner rule 5 (the conversation\'s lead)');
        $ctx->eq($got['Q-3500'], ['made_by' => 'manual', 'owner_source' => 'quotes_log', 'owner_staff_id' => $s->ids['admin'], 'evidence_event' => 'quote_manual',
                                  'quote_id' => 3500, 'sent_channel' => 'support'], 'B06 path 4 (the screen, uCRM ticked, its claim won): quote_manual 📋, owner rule 3');
        $ctx->eq($got['Q-3600'], ['made_by' => 'manual', 'owner_source' => 'quotes_log', 'owner_staff_id' => $s->ids['admin'], 'evidence_event' => 'ops_quote_created',
                                  'quote_id' => 3600, 'sent_channel' => 'support'], 'B06 path 5 (the webhook won the screen\'s claim): 🆔, owner rule 3 from the screen\'s log');
        $ctx->eq($got['Q-3700'], ['made_by' => 'ucrm', 'owner_source' => 'pending', 'owner_staff_id' => null, 'evidence_event' => 'ops_quote_wa',
                                  'quote_id' => 3700, 'sent_channel' => 'support'], 'B06 path 9 (flow B, made in uCRM): ops_quote_wa 📋; no rule yields within the hour: pending');
        $ctx->eq($got['Q-3800'], ['made_by' => 'kyc', 'owner_source' => 'kyc_sales_person', 'owner_staff_id' => $s->ids['lead'], 'evidence_event' => 'ops_quote_wa',
                                  'quote_id' => 3800, 'sent_channel' => 'support'], 'B06 path 10\'s deferred form: flow A\'s 📋, owner rule 1 on the client\'s first application (the recorded limit)');
        $ctx->eq($got['Q-4100'], ['made_by' => 'resend', 'owner_source' => 'pending', 'owner_staff_id' => null, 'evidence_event' => 'ops_quote_text',
                                  'quote_id' => null, 'sent_channel' => 'support'], 'B06 path 11 (the tab\'s text fallback): ops_quote_text 📄');
        $ctx->eq([(int)$pdo->query("SELECT COUNT(*) FROM quote_tracker WHERE quote_ref = 'Q-3900'")->fetchColumn(), (int)$pdo->query('SELECT COUNT(*) FROM quote_tracker')->fetchColumn()],
                 [0, 6], 'B06 no row for Q-3900 (no text), and exactly one per quotation: no row from a DEDUP BLOCK or a stand-down');
        QuoteTracker::run($pdo, $cfg, $s->data, $store, time() + 3 * 3600, time() + 120);
        $ctx->eq([qf_trow($pdo, 'ref:Q-3700')['owner_source'], (int)$app18 > 0], ['none', true], 'B06 at 1 h the unresolved owner is final: none (the central list)');
        $s->stop();
    });

    $ctx->run('B07 cron/quote_chase.php: Uganda with tracking on tracks; off, or South Sudan, nothing; counts only; deferred past master\'s deadline', function () use ($ctx) {
        $tree = $ctx->tree();
        $ucrm = $tree . '/ucrm.json';
        $old = is_file($ucrm) ? (string)file_get_contents($ucrm) : null;
        $runCron = function (array $b, string $prefix = '') use ($tree, $ucrm): array {
            file_put_contents($ucrm, json_encode(['pluginDataDir' => $b['data']]));
            $out = [];
            $code = $prefix === '' ? 'include ' . var_export($tree . '/cron/quote_chase.php', true) . ';'
                                   : $prefix . ' include ' . var_export($tree . '/cron/quote_chase.php', true) . ';';
            exec('cd ' . escapeshellarg($tree) . ' && DN_DATA_DIR=' . escapeshellarg($b['data']) . ' php -r ' . escapeshellarg($code) . ' 2>&1', $out, $rc);
            return [$rc, implode("\n", $out)];
        };
        $mk = function (array $cfg) use ($ctx) {
            $b = $ctx->book($cfg, 'b7');
            $b['store']->save('kyc_config.json', $cfg);
            file_put_contents($b['data'] . '/kyc_config.json', json_encode($cfg));
            // rows before the quotation, so the cursor (a wa_messages.id) is past 1 and cannot pass for a 0|1 flag
            $cv = qf_conv($b['pdo'], '256772501031');
            foreach ([1, 2, 3] as $i) qf_msg($b['pdo'], $cv, ['body' => 'An earlier message (test)', 'sent_at' => gmdate('Y-m-d H:i:s', time() - 600 + $i)]);
            qf_qmsg($b['pdo'], $cv, 'quote_manual', qf_ref_text('Q-5031'), gmdate('Y-m-d H:i:s', time() - 120));
            return $b;
        };
        try {
            $on = $mk(qf_tcfg([], gmdate('Y-m-d H:i:s', time() - 3600)));
            [$rc, $out] = $runCron($on);
            $ctx->eq([$rc, qf_trow($on['pdo'], 'ref:Q-5031') !== null, qf_state($on['pdo'], 'tracker_ran') !== null], [0, true, true],
                     'B07 Uganda, tracking on: the row, tracker_ran', $out);
            $ctx->t((bool)preg_match('/^quote_chase: tracking( [a-z_]+=\d+)+$/m', $out) && !preg_match('/\d{9,}|Q-5031|Test/', $out),
                    'B07 its line is counts and fixed codes only', $out);
            // the exact key set, in order; the three flags 0 or 1; nothing else printed. The cursor is a wa_messages.id
            // (4 here): it is no count, and is never in the line (§C8; §H.2.14 M)
            $keys = ['read', 'confirming', 'inserted', 'kept_earliest', 'ref_conflict', 'no_phone', 'advanced', 'owners_found',
                     'owners_final', 'owners_pending', 'ran', 'stopped', 'mapped', 'held', 'cut'];
            $kv = [];
            foreach (explode(' ', (string)preg_replace('/^quote_chase: tracking /', '', trim($out))) as $pair) {
                $kv[(string)strstr($pair, '=', true)] = (string)substr((string)strstr($pair, '='), 1);
            }
            $ctx->eq([array_keys($kv), count(explode("\n", trim($out))), (int)qf_state($on['pdo'], 'tracker_cursor')], [$keys, 1, 4],
                     'B07 the line\'s keys are exactly the run\'s counts and flags, in order, on one line (the cursor stored, 4, never printed)', $out);
            $ctx->t(count(array_filter($kv, function ($v) { return !ctype_digit($v); })) === 0
                    && in_array($kv['advanced'] ?? '', ['0', '1'], true) && in_array($kv['ran'] ?? '', ['0', '1'], true) && in_array($kv['cut'] ?? '', ['0', '1'], true)
                    && ($kv['advanced'] ?? '') === '1' && ($kv['read'] ?? '') === '4',
                    'B07 every value a count; advanced, ran and cut each 0 or 1 (advanced 1: the cursor moved; read 4)', $out);
            $off = $mk(qf_tcfg(['quote_tracking_enabled' => '0']));
            [$rc2, $out2] = $runCron($off);
            $ss = $mk(qf_tcfg(['tenant_profile' => 'south-sudan']));
            [$rc3, $out3] = $runCron($ss);
            $ctx->eq([$rc2, trim($out2), qf_trow($off['pdo'], 'ref:Q-5031'), $rc3, trim($out3), qf_trow($ss['pdo'], 'ref:Q-5031')], [0, '', null, 0, '', null],
                     'B07 tracking off, and South Sudan with the key set: nothing read, nothing written, nothing said');
            $late = $mk(qf_tcfg([], gmdate('Y-m-d H:i:s', time() - 3600)));
            [$rc4, $out4] = $runCron($late, '$_m_deadline = time() - 5;');
            $ctx->eq([$rc4, trim($out4), qf_trow($late['pdo'], 'ref:Q-5031')], [0, 'quote_chase: deferred: master budget', null],
                     'B07 master\'s cycle already past its deadline: deferred, nothing done');
        } finally {
            if ($old === null) @unlink($ucrm); else file_put_contents($ucrm, $old);
        }
    });

    $ctx->run('B08 paid set at creation: a Cash KYC with amount_charged > 0 for the row\'s quote_id (§H.2.9.5; B2a-R02)', function () use ($ctx) {
        // The KYC application is submitted BEFORE its quotation is sent, so the paid stop's "after sent_at" (§H.2.9.7)
        // never sees it: only the status set when the row is made keeps a customer who paid cash at KYC out of the chase.
        $b = $ctx->book(qf_tcfg(), 'b8'); $p = $b['pdo'];
        $before = '2026-11-02 09:00:00';   // Kampala (date()), an hour before the sends at 07:00 UTC = 10:00 Kampala
        $app = function (string $ref, $quoteId, int $client, string $type, $amount) use ($p, $before) {
            qf_blob($p, 'kyc_applications', ['quote_ref' => $ref, 'quote_id' => $quoteId, 'crm_client_id' => $client, 'sales_type' => $type,
                                             'amount_charged' => $amount, 'submitted_at' => $before, 'sales_person' => 'nobody', 'mobile' => '07725010' . substr($ref, -2)]);
        };
        // cron A's 📋 for an application whose quote_id the application itself holds (a string, as KycService writes it)
        $app('Q-5041', '5041', 541, 'Cash', 1800);
        qf_qmsg($p, qf_conv($p, '256772501041'), 'ops_quote_wa', qf_ref_text('Q-5041'), '2026-11-02 07:00:00');
        // the webhook's 🆔, its id from the webhook's claim; the application's quote_id an integer, its amount a string
        $p->exec("CREATE TABLE IF NOT EXISTS wa_sent_quotes (quote_id INTEGER NOT NULL, quote_ref TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT '', sent_at TEXT NOT NULL DEFAULT (datetime('now')), PRIMARY KEY (quote_id))");
        $p->exec("INSERT INTO wa_sent_quotes (quote_id, quote_ref, source) VALUES (5044, 'Q-5044', 'webhook')");
        $app('Q-5044', 5044, 544, 'cash', '1800.00');
        qf_qmsg($p, qf_conv($p, '256772501044'), 'ops_quote_created', qf_order_text('Q-5044'), '2026-11-02 07:00:00');
        // the controls: a Credit application (even one carrying an amount), and a Cash one with nothing charged
        $app('Q-5042', '5042', 542, 'Credit', 1800);
        qf_qmsg($p, qf_conv($p, '256772501042'), 'ops_quote_wa', qf_ref_text('Q-5042'), '2026-11-02 07:00:00');
        $app('Q-5043', '5043', 543, 'Cash', 0);
        qf_qmsg($p, qf_conv($p, '256772501043'), 'ops_quote_wa', qf_ref_text('Q-5043'), '2026-11-02 07:00:00');
        $r = qf_track($b, '2026-11-02 11:00:00');   // the run's moment: 08:00:00 UTC
        $f = ['quote_id', 'made_by', 'status', 'status_reason', 'status_at'];
        $ctx->eq([$r['inserted'], qf_pick(qf_trow($p, 'ref:Q-5042'), $f), qf_pick(qf_trow($p, 'ref:Q-5043'), $f)],
                 [4, ['quote_id' => 5042, 'made_by' => 'kyc', 'status' => 'open', 'status_reason' => null, 'status_at' => null],
                     ['quote_id' => 5043, 'made_by' => 'kyc', 'status' => 'open', 'status_reason' => null, 'status_at' => null]],
                 'B08 control: a Credit application, and a Cash one with amount_charged 0, give rows made open');
        $ctx->eq([qf_pick(qf_trow($p, 'ref:Q-5041'), $f), qf_pick(qf_trow($p, 'ref:Q-5044'), $f)],
                 [['quote_id' => 5041, 'made_by' => 'kyc', 'status' => 'paid', 'status_reason' => 'cash_kyc', 'status_at' => '2026-11-02 08:00:00'],
                  ['quote_id' => 5044, 'made_by' => 'kyc', 'status' => 'paid', 'status_reason' => 'cash_kyc', 'status_at' => '2026-11-02 08:00:00']],
                 'B08 a Cash application with amount_charged > 0 for the row\'s quote_id: made paid, cash_kyc, status_at the run\'s moment');
        qf_track($b, '2026-11-03 11:00:00');
        $ctx->eq([qf_trow($p, 'ref:Q-5041')['status'], qf_trow($p, 'ref:Q-5042')['status'], qf_trow($p, 'ref:Q-5043')['status']], ['paid', 'open', 'open'],
                 'B08 a later run: the paid stop (after sent_at) does not see the application, which came before the send — the controls stay open');
    });

    $ctx->run('B09 the KYC paths and the standard quotation through their real senders: the form web and web-direct (1), retry_quote (2), KycCrmSync (3), Send Quotation and the mobile API (8)', function () use ($ctx) {
        // B2a fix round 1 (B2a-R01). quote_from_ucrm's section-F harness (tests/quote_from_ucrm/f_lib.php and the qcj_* of
        // fixtures/quote_company_journey.php) as catalogue_direct E builds its book (cde_book): one fake uCRM of the quoting
        // organization serving the link seed and the direct catalogue, the fake Evolution, a copy of the judged tree, every
        // step through the entry point staff use — the KYC form's handler (includes/post/post_kyc.php), the admin's Retry,
        // the debug API's retry_quote over HTTP, the uCRM webhook over HTTP, cron_quote_wa.php, Send Quotation's handler
        // (includes/post/post_leads.php) and the mobile API's own block of api/index.php. The texts are the real senders':
        // cron A's 📋 and the webhook's 🆔. Every person and number is fictitious.
        if (!defined('QFU_TZ')) define('QFU_TZ', QF_TZ);   // cde_book() makes the book in the runner's zone
        require_once dirname(__DIR__) . '/quote_from_ucrm/_lib.php';
        require_once dirname(__DIR__) . '/catalogue_direct/_lib.php';
        require_once dirname(__DIR__) . '/catalogue_direct/e_lib.php';
        $qc = new QfuCtx($ctx->root, $ctx->dir('qfu'));
        $tree = $ctx->dir('b9') . '/dishnet-hybrid-sudan';
        qcj_copy_tree($ctx->root, $tree);
        $D = cde_book($qc, $tree, ['records' => null]);            // the uCRM catalogue's switch off: 5.18.102's links decide
        $agent = (int)$D['agent']; $admin = (int)$D['admin'];       // "TEST Agent" (sales) and "TEST Admin", both active
        $since = gmdate('Y-m-d H:i:s', time() - 600);
        $setStore = function (string $file, $value) use ($D): void {
            qcj_php($D, 'require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
                $s = SqliteStore::create($DATA); $s->save($ARGS["file"], $ARGS["value"]); qcj_out(true);', ['file' => $file, 'value' => $value]);
        };
        $setCfg = function (array $set) use ($D): void {
            qcj_php($D, 'require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
                $s = SqliteStore::create($DATA); $c = $s->load("kyc_config.json") ?: [];
                foreach ($ARGS["set"] as $k => $v) { if ($v === null) unset($c[$k]); else $c[$k] = $v; }
                $s->save("kyc_config.json", $c); file_put_contents($DATA . "/kyc_config.json", json_encode($c)); qcj_out(true);', ['set' => $set]);
        };
        $app = function (string $last) use ($D): array {
            foreach ((array)(qcj_tables($D['data'], ['kyc_applications'])['kyc_applications'] ?? []) as $a) {
                if ((string)($a['lastname'] ?? '') === $last) return $a;
            }
            return [];
        };
        $kycPost = function (string $last, string $phone, string $salesPerson, array $more = []): array {
            return array_merge(['action' => 'kyc_submit', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection',
                'firstname' => 'TEST', 'lastname' => $last, 'mobile' => $phone, 'email' => '', 'address_1' => 'TEST address',
                'package_choice' => '1', 'device_id' => '3', 'kitQty' => '1', 'sales_type' => 'Credit', 'priority' => 'Low',
                'sales_person' => $salesPerson, 'install_by' => 'dishnet', 'ucrm_quote_form' => '5.18.102'], $more);
        };
        $ok = function (array $r): bool { return ($r['flash'][0]['type'] ?? '') === 'success'; };

        // path 1, the web form (5.18.102's marker, links): sales_person names the active "TEST Admin"
        $r1 = qcj_post($D, 'post_kyc.php', $kycPost('Pathweb', '+256700000231', 'TEST Admin'), $agent);
        // path 3: uCRM refuses the client at submission, so the application waits; the admin's Retry (KycCrmSync::syncOne)
        qcj_fake($D['fake'], '/__test/set', ['clients_refuse' => true]);
        $r3 = qcj_post($D, 'post_kyc.php', $kycPost('Pathsync', '+256700000234', 'TEST Nobody Known'), $agent);
        qcj_fake($D['fake'], '/__test/set', ['clients_refuse' => false]);
        $a3 = $app('Pathsync');
        $r3b = qcj_post($D, 'post_kyc.php', ['action' => 'kyc_crm_retry', 'app_id' => (string)(int)($a3['id'] ?? 0)], $admin);
        // path 2: the kit not linked at submission (unpriced, P1, no quotation); linked again, then retry_quote — whose gated
        // path is Make the quotation's one implementation (KycService::makeQuotation) — over the debug API, as an admin
        $setStore('ucrm_quote_links.json', array_values(array_filter(cde_links(), function ($l) { return $l['item'] !== 'kit'; })));
        $r2 = qcj_post($D, 'post_kyc.php', $kycPost('Pathretry', '+256700000233', 'TEST Admin'), $agent);
        $a2 = $app('Pathretry');
        $setStore('ucrm_quote_links.json', cde_links());
        $setCfg(['crm_auth_token' => QCJ_TOKEN]);
        [$srv, $base] = qcj_serve_plugin($D);
        $ctx->t($srv !== null, 'B09 control: the plugin is served (the debug API and the uCRM webhook are reached over HTTP)');
        if ($srv === null) return;
        try {
            $rq = qcj_api($base, $D['tok_admin'], 'GET', 'retry_quote', null, '&app_id=' . (int)($a2['id'] ?? 0));
            $setCfg(['crm_auth_token' => null]);
            // the uCRM catalogue's switch on: path 1's web-direct form (sales_person names no account: its submitter), and
            // path 8, a standard quotation drawn on Send Quotation and posted through its handler, then through the mobile API
            $STD = cd_std('Mini + Residential (TEST)', CD_PLAN_RES, 12, [['p' => CD_MINI, 'q' => 1]], true);
            $setStore('ucrm_catalogue.json', cd_records(true, CD_INSTALL, [], [$STD]));
            $r1d = qcj_post($D, 'post_kyc.php', $kycPost('Pathdirect', '+256700000232', 'TEST Nobody Known',
                cd_post([CD_PLAN_RES, 12], [[CD_KIT, 1]])), $agent);
            $SQ = cde_const(cde_page($D, ['qmode' => 'manual']), 'SQ_DIRECT');
            $lines = (array)($SQ['standards'][0]['lines'] ?? []);
            $r8 = cde_post($D, ['action' => 'send_manual_quote', 'ucrm_quote_form' => '5.18.103-direct', 'mq_crm_client_id' => '17',
                'mq_customer_name' => '', 'mq_customer_phone' => '', 'mq_create_crm' => '1', 'mq_note' => '', 'mq_items_json' => json_encode($lines)]);
            // the mobile API's lead quote: lead 1 (client 16) is assigned to the admin, so owner rule 2 names the admin, not its maker
            $leads = (array)(qcj_tables($D['data'], ['leads'])['leads'] ?? []);
            foreach ($leads as $i => $l) { unset($leads[$i]['__id']); if ((int)$l['id'] === 1) $leads[$i]['assigned_to'] = $admin; }
            $setStore('leads.json', array_values($leads));
            $r8a = cde_api($D, 'send_lead_quote', ['lead_id' => 1, 'create_crm_quote' => true,
                'items' => array_map(function ($l) { return $l + ['quantity' => $l['q'] ?? 1]; }, $lines)]);
            $setStore('ucrm_catalogue.json', cd_records(false, CD_INSTALL, [], [$STD]));
            $ad = $app('Pathdirect');
            // the texts. With kyc_messages_like_crm on, uCRM's quote.add for the web-direct application's quotation is
            // sent by the webhook (🆔) and claimed, so cron A stands down for it; the others go out from cron A (📋)
            $setCfg(['kyc_messages_like_crm' => true]);
            $wh = qcj_http('POST', "{$base}?page=crm_webhook", json_encode(['changeType' => 'quote.add', 'entity' => 'quote',
                'entityId' => (int)($ad['quote_id'] ?? 0), 'uuid' => 'qf-b09-' . bin2hex(random_bytes(4)),
                'extraData' => ['entity' => ['id' => (int)($ad['quote_id'] ?? 0)]]]),
                ['Content-Type: application/json', 'X-Ucrm-Key: ' . (string)$D['cfg']['webhook_secret']]);
            qcj_http('GET', "{$base}?page=login");   // the webhook's request has ended
            $setCfg(['kyc_messages_like_crm' => null]);
        } finally {
            qcj_stop($srv);
        }
        // cron A waits three minutes after the quotation was made; the fake's drafts approved, so it does not stop to approve
        $st = qcj_fake_state($D['fake']);
        $quotes = (array)($st['quotes'] ?? []);
        foreach ($quotes as $k => $q) $quotes[$k]['status'] = 1;
        qcj_fake($D['fake'], '/__test/set', ['quotes' => $quotes]);
        $all = (array)(qcj_tables($D['data'], ['kyc_applications'])['kyc_applications'] ?? []);
        foreach ($all as $i => $a) { unset($all[$i]['__id']); if (!empty($a['wa_quote_deferred_at'])) $all[$i]['wa_quote_deferred_at'] = date('Y-m-d H:i:s', time() - 900); }
        $setStore('kyc_applications.json', array_values($all));
        [$rcA] = qcj_script($D, 'cron_quote_wa.php');

        $a1 = $app('Pathweb'); $a3 = $app('Pathsync'); $a2 = $app('Pathretry'); $ad = $app('Pathdirect');
        $texts = array_map(function ($t) { return (string)($t['text'] ?? ''); }, qcj_evo_texts($D['evo']));
        $has = function (string $needle) use ($texts) { return count(array_filter($texts, function ($t) use ($needle) { return strpos($t, $needle) !== false; })); };
        $ref = function (array $a) { return (string)($a['quote_ref'] ?? ''); };
        $ref8 = (string)($r8['flash'][0]['msg'] ?? '');
        $ctx->eq([$ok($r1), $ok($r3b), $a2['quote_refused'] ?? null, $rq[0], $ok($r1d), $a1['quote_sale_entry'] ?? null, $ad['quote_sale_entry'] ?? null,
                  $a3['crm_sync_status'] ?? null, $ok($r8), $r8a['ok'] ?? null, $wh[0], $rcA],
                 [true, true, 'P1', 200, true, 'web', 'web-direct', 'synced', true, true, 200, 0],
                 'B09 control: each path ran through its entry point (the web and direct forms, the Retry, retry_quote after P1, Send Quotation, the API, the webhook, cron A)',
                 json_encode([$r1['flash'] ?? null, $r3['flash'] ?? null, $r3b['flash'] ?? null, $r2['flash'] ?? null, $rq[2] ?? null, $r1d['flash'] ?? null,
                              $r8['flash'] ?? $r8, $r8a, substr($wh[1], 0, 200)], JSON_UNESCAPED_UNICODE));
        $ctx->eq([$has('📋 *Ref:* ' . $ref($a1)), $has('📋 *Ref:* ' . $ref($a3)), $has('📋 *Ref:* ' . $ref($a2)), $has('🆔 *Order:* ' . $ref($ad)),
                  $has('📋 *Ref:* ' . $ref($ad))],
                 [1, 1, 1, 1, 0], 'B09 control: cron A\'s 📋 for paths 1 (web), 3 and 2; the webhook\'s 🆔 for path 1 (web-direct), which cron A then left alone',
                 json_encode([$ref($a1), $ref($a3), $ref($a2), $ref($ad)]));

        $store9 = SqliteStore::create($D['data']);
        $b = ['store' => $store9, 'pdo' => $store9->getPdo(), 'data' => $D['data']];
        $cfg = qf_tcfg(['_data_dir' => $D['data']], $since) + $D['cfg'];
        QuoteTracker::run($b['pdo'], $cfg, $D['data'], $b['store'], time() + 60, time() + 120);
        $f = ['made_by', 'owner_source', 'owner_staff_id', 'evidence_event', 'quote_id', 'status', 'backfilled'];
        $row = function (string $r) use ($b, $f) { return qf_pick(qf_trow($b['pdo'], 'ref:' . $r), $f); };
        $kyc = function (array $a, string $src, int $who, string $ev) {
            return ['made_by' => 'kyc', 'owner_source' => $src, 'owner_staff_id' => $who, 'evidence_event' => $ev,
                    'quote_id' => (int)($a['quote_id'] ?? 0), 'status' => 'open', 'backfilled' => 0];
        };
        $ctx->eq($row($ref($a1)), $kyc($a1, 'kyc_sales_person', $admin, 'ops_quote_wa'),
                 'B09 path 1 (the web form): cron A\'s 📋 maps to the application the form stored; owner rule 1 — its sales_person, an active account');
        $ctx->eq($row($ref($ad)), $kyc($ad, 'kyc_submitter', $agent, 'ops_quote_created'),
                 'B09 path 1 (the web-direct form): the webhook\'s 🆔 maps to it; owner rule 1 — sales_person names no account, so its submitter');
        $ctx->eq($row($ref($a2)), $kyc($a2, 'kyc_sales_person', $admin, 'ops_quote_wa'),
                 'B09 path 2 (retry_quote after P1, Make the quotation\'s implementation): 📋; owner rule 1 on the application');
        $ctx->eq($row($ref($a3)), $kyc($a3, 'kyc_submitter', $agent, 'ops_quote_wa'),
                 'B09 path 3 (KycCrmSync, the admin\'s Retry): 📋; owner rule 1 — its submitter');
        $q8 = array_values(array_filter((array)(qcj_tables($D['data'], ['quotes_log'])['quotes_log'] ?? []), function ($q) { return in_array((string)($q['type'] ?? ''), ['manual', 'lead'], true); }));
        $byType = [];
        foreach ($q8 as $q) $byType[(string)$q['type']] = (string)($q['quote_ref'] ?? '');
        $ctx->eq([$row($byType['manual'] ?? '-'), $row($byType['lead'] ?? '-')],
                 [['made_by' => 'manual', 'owner_source' => 'quotes_log', 'owner_staff_id' => $agent, 'evidence_event' => 'quote_manual',
                   'quote_id' => (int)substr((string)($byType['manual'] ?? 'Q-0'), 2), 'status' => 'open', 'backfilled' => 0],
                  ['made_by' => 'lead', 'owner_source' => 'lead_owner', 'owner_staff_id' => $admin, 'evidence_event' => 'quote_lead',
                   'quote_id' => (int)substr((string)($byType['lead'] ?? 'Q-0'), 2), 'status' => 'open', 'backfilled' => 0]],
                 'B09 path 8 (a standard quotation): from Send Quotation, quote_manual 📋 owned by rule 3 (its quotes_log maker); from the mobile API on a lead, quote_lead 📋 owned by rule 2 (the lead\'s owner, not its maker)',
                 json_encode($byType));
        $ctx->eq((int)$b['pdo']->query('SELECT COUNT(*) FROM quote_tracker')->fetchColumn(), 6, 'B09 six quotations sent, six rows: no row from a stand-down or a claim');
    });
}];

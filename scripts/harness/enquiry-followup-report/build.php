<?php
declare(strict_types=1);
/**
 * build.php ROOT OUT NOW — the test book for scripts/enquiry-followup-report.php.
 *
 * ROOT is the plugin's tree at the installed commit (994d660, by git archive); OUT a new folder; NOW a UTC time
 * 'YYYY-MM-DD HH:MM:SS'. Every migration of ROOT is applied through its own MigrationRunner, and the follow-ups are made
 * through its own FollowUpService and ConversationService — open, draft, approve, recordSend, close — so each row is the
 * shape the live code writes; only the times are then set relative to NOW, so the expected counts never depend on the
 * day the test runs. Every phone number, name, instance, staff name, message and odd channel or reason here is a PLANT:
 * the test asserts none of them reaches the report.
 */
[$root, $out, $nowIso] = array_slice($argv, 1, 3) + [null, null, null];
if (!$root || !$out || !$nowIso) { fwrite(STDERR, "usage: build.php ROOT OUT NOW\n"); exit(2); }
foreach (['StoreInterface', 'JsonStore', 'SqliteStore', 'MigrationRunner', 'ConversationService', 'ContactOptOut', 'FollowUpPolicy', 'FollowUpService'] as $c) {
    require_once $root . '/lib/' . $c . '.php';
}
$NOW = strtotime($nowIso . ' UTC');
$at = function (float $hours) use ($NOW): string { return gmdate('Y-m-d H:i:s', (int)round($NOW + $hours * 3600)); };

@mkdir($out . '/data', 0700, true);
$store = SqliteStore::create($out . '/data');
$pdo = $store->getPdo();
$r = (new MigrationRunner($pdo, $root . '/migrations'))->run();
foreach ($r as $m) if (($m['status'] ?? '') === 'FAILED') { fwrite(STDERR, 'migration failed: ' . ($m['file'] ?? '?') . "\n"); exit(3); }
$ledger = (int)$pdo->query('SELECT COUNT(*) FROM _migrations')->fetchColumn();
if ($ledger < 1) { fwrite(STDERR, "no migration recorded\n"); exit(3); }
$cs = new ConversationService($out . '/data', $pdo);
$svc = new FollowUpService($pdo);

// a seller's number, with an instance and a display name that must never print
$pdo->exec("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, owner_staff_id, portfolio_scope, ai_enabled, handover_to, status)
            VALUES ('sales-001', 'PLANTINSTZQX', 'PLANT-DISPLAY-KATO', 'sales', 'staff', 7, 'own', 1, 'owner', 'active')");
// a second seller's number, paused
$pdo->exec("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, owner_staff_id, portfolio_scope, ai_enabled, handover_to, status)
            VALUES ('sales-002', 'PLANTINSTB2', 'PLANT-DISPLAY-OKELLO', 'sales', 'staff', 8, 'own', 1, 'owner', 'paused')");

$conv = function (string $phone, string $channel, float $quietH, string $state = 'bot_active') use ($cs, $pdo, $at): array {
    $c = $cs->ensureConversation($phone, $channel, 'PLANT-NAME-NAKATO', 'test');
    $id = (int)$c['id'];
    $cs->storeMessage($id, ['direction' => 'in', 'role' => 'customer', 'body' => 'PLANT-BODY how much is it', 'sent_at' => $at(-$quietH)]);
    $pdo->prepare('UPDATE wa_conversations SET last_customer_at = ?, status = ?, state = ? WHERE id = ?')->execute([$at(-$quietH), 'active', $state, $id]);
    return $cs->getConversation($id);
};
$open = function (array $c, float $dueH, float $openedH) use ($svc, $pdo, $at): int {
    $o = $svc->open($c); if (!$o['ok'] || !$o['created']) { fwrite(STDERR, "open failed\n"); exit(3); }
    $pdo->prepare('UPDATE followups SET due_at = ?, opened_at = ? WHERE id = ?')->execute([$at($dueH), $at($openedH), $o['id']]);
    return (int)$o['id'];
};
$draft = function (int $fu, string $verdict, float $createdH) use ($svc, $pdo, $at): int {
    $d = $svc->draft($fu, ['verdict' => $verdict, 'reason' => 'PLANT-REASON Nakato 256771234567', 'message' => $verdict === 'SEND' ? 'PLANT-BODY Hello Nakato' : '']);
    if (!$d['ok']) { fwrite(STDERR, "draft failed\n"); exit(3); }
    $pdo->prepare('UPDATE followup_drafts SET created_at = ? WHERE id = ?')->execute([$at($createdH), $d['id']]);
    return (int)$d['id'];
};
$approve = function (int $d, string $by, float $decidedH) use ($svc, $pdo, $at): void {
    $a = $svc->approve($d, $by); if (!$a['ok']) { fwrite(STDERR, "approve failed: " . ($a['error'] ?? '') . "\n"); exit(3); }
    $pdo->prepare('UPDATE followup_drafts SET decided_at = ? WHERE id = ?')->execute([$at($decidedH), $d]);
};
$send = function (int $fu, int $d, float $sentH, float $nextDueH) use ($svc, $pdo, $at): void {
    $svc->recordSend($fu, $d, 'PLANT-BODY Hello Nakato', 'PLANTWAID', 'auto');
    $pdo->prepare('UPDATE followup_sends SET sent_at = ? WHERE draft_id = ?')->execute([$at($sentH), $d]);
    $pdo->prepare('UPDATE followups SET due_at = ? WHERE id = ? AND closed_at IS NULL')->execute([$at($nextDueH), $fu]);
};

// S0 sales — the oldest due, its draft waits for a person
$f0 = $open($conv('256700000100', 'sales', 16 * 24 + 24), -16 * 24, -17 * 24); $draft($f0, 'SEND', -15 * 24);
// S1 sales — due 98 h ago, its draft waits for a person
$f1 = $open($conv('256700000101', 'sales', 122), -98, -48); $draft($f1, 'SEND', -97);
// S2 sales — due 52 h ago, a WAIT draft: nothing to approve
$f2 = $open($conv('256700000102', 'sales', 76), -52, -48); $draft($f2, 'WAIT', -51);
$pdo->prepare("UPDATE wa_conversations SET category = ? WHERE id = (SELECT conversation_id FROM followups WHERE id = ?)")->execute(["staff\n\x0B", $f2]);   // a colleague's, filed later (PHP's trim reads it as staff)
// S3 support — due 13 days ago; approved by the assistant 12 days ago; the customer wrote again 11 days ago
$c3 = $conv('256700000103', 'support', 14 * 24 + 2); $f3 = $open($c3, -13 * 24, -15 * 24); $d3 = $draft($f3, 'SEND', -12 * 24 - 1); $approve($d3, 'auto', -12 * 24);
$pdo->prepare("UPDATE wa_conversations SET last_customer_at = ?, state = 'human_active' WHERE id = ?")->execute([$at(-11 * 24), (int)$c3['id']]);
// S4 sales-001 — not yet due; approved by a person 2 h ago; a person has the chat
$f4 = $open($conv('256700000104', 'sales-001', 6, 'human_active'), 18, -48); $d4 = $draft($f4, 'SEND', -3); $approve($d4, 'staff:PLANT-STAFF-OKELLO', -2);
// S5 account — approved, then closed before it went: a dead approved draft
$f5 = $open($conv('256700000105', 'account', 9 * 24), -8 * 24, -8 * 24); $d5 = $draft($f5, 'SEND', -8 * 24 + 1); $approve($d5, 'auto', -8 * 24 + 2);
$svc->close($f5, 'human_closed', 'PLANT a person took it, 256771234567');
// S6 sales — sent 3 days ago, next attempt in 20 h
$f6 = $open($conv('256700000106', 'sales', 5 * 24), -4 * 24, -48); $d6 = $draft($f6, 'SEND', -3 * 24 - 2); $approve($d6, 'auto', -3 * 24 - 1); $send($f6, $d6, -3 * 24, 20);
// S7 support — sent an hour ago, next attempt in 22 h
$f7 = $open($conv('256700000107', 'support', 30), -6, -48); $d7 = $draft($f7, 'SEND', -2); $approve($d7, 'auto', -1.5); $send($f7, $d7, -1, 22);
// S8 an odd channel, due half an hour ago, no draft (a row the scan could never write: the label must hold anyway)
$pdo->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, due_at, opened_at, opened_by) VALUES (999901, ?, '256779876543', ?, ?, ?, 'scan')")
    ->execute(['PLANT-CHAN 256779876543', $at(-25), $at(-0.5), $at(-48)]);
// S9 closed with an odd reason
$pdo->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, due_at, opened_at, closed_at, close_reason, opened_by) VALUES (999902, 'support', '256771111111', ?, ?, ?, ?, ?, 'scan')")
    ->execute([$at(-4 * 24), $at(-3 * 24), $at(-3 * 24), $at(-2 * 24), 'opted 256771111111 PLANT']);
// S10 a QUOTE chase row, open and due, with a pending draft: an enquiry report must not count it anywhere
$pdo->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, due_at, opened_at, opened_by, kind, quote_key) VALUES (999903, 'sales', '256771222222', ?, ?, ?, 'quote_chase', 'quote', 'PLANTQK1')")
    ->execute([$at(-30 * 24), $at(-20 * 24), $at(-2)]);
$q10 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO followup_drafts (followup_id, attempt, verdict, reason, body, created_at, status) VALUES (?, 1, 'SEND', 'q', 'PLANT-BODY quote', ?, 'pending')")->execute([$q10, $at(-20 * 24)]);
$pdo->prepare("INSERT INTO followup_drafts (followup_id, attempt, verdict, reason, body, created_at, status, decided_at, decided_by) VALUES (?, 2, 'SEND', 'q', 'PLANT-BODY quote', ?, 'approved', ?, 'auto')")->execute([$q10, $at(-19 * 24), $at(-19 * 24)]);
$pdo->prepare("INSERT INTO followup_sends (followup_id, attempt, sent_at, channel, phone, body, decided_by) VALUES (?, 1, ?, 'sales', '256771222222', 'PLANT-BODY quote', 'auto')")->execute([$q10, $at(-0.25)]);

// S11 sales-002 (a paused seller number) — not yet due; approved by the assistant half an hour ago
$f11 = $open($conv('256700000111', 'sales-002', 50), 5, -48); $d11 = $draft($f11, 'SEND', -0.6); $approve($d11, 'auto', -0.5);

// closed follow-ups written straight into the book (a closed row cannot be updated, so its times are set at insert)
$closedFu = function (int $convId, string $channel, float $lastH, float $openedH, float $closedH, string $reason, string $phone = '256771333333') use ($pdo, $at): int {
    $pdo->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, due_at, opened_at, closed_at, close_reason, opened_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scan')")
        ->execute([$convId, $channel, $phone, $at($lastH), $at($openedH), $at($openedH), $at($closedH), $reason]);
    return (int)$pdo->lastInsertId();
};
$pendingOn = function (int $fu, string $verdict, string $reason, float $createdH) use ($pdo, $at): void {
    $pdo->prepare("INSERT INTO followup_drafts (followup_id, attempt, verdict, reason, body, created_at, status) VALUES (?, 1, ?, ?, ?, ?, 'pending')")
        ->execute([$fu, $verdict, $reason, $verdict === 'SEND' ? 'PLANT-BODY Hello Nakato' : null, $at($createdH)]);
};
// B1-B49: 49 old follow-ups the assistant could not reach, closed not_interested 16 days ago, each leaving its waiting
// draft: with the quote draft they fill the Follow-ups screen's 50 cards, so S0's waiting draft is the 51st
for ($i = 1; $i <= 49; $i++) {
    $b = $closedFu(990000 + $i, 'sales', -18 * 24, -17 * 24, -16 * 24, 'not_interested');
    $pendingOn($b, 'DO_NOT_SEND', 'the assistant could not be reached: PLANT timeout 256771234567', -16 * 24);
}
// X1-X7: closed in the last two days, each leaving its waiting draft
$x = $closedFu(990101, 'sales', -5 * 24, -3 * 24, -2 * 24, 'not_interested'); $pendingOn($x, 'DO_NOT_SEND', 'the assistant could not be reached: PLANT 256771234567', -2 * 24 - 1);
$x = $closedFu(990102, 'sales', -5 * 24, -3 * 24, -2 * 24, 'escalated');      $pendingOn($x, 'ESCALATE_TO_HUMAN', 'PLANT wants a call 256771234567', -2 * 24 - 1);
$x = $closedFu(990103, 'support', -5 * 24, -3 * 24, -2 * 24, 'not_interested'); $pendingOn($x, 'SEND', 'PLANT a staff "Not interested" 256771234567', -2 * 24 - 1);
$x = $closedFu(990104, 'sales', -5 * 24, -3 * 24, -2 * 24, 'not_interested'); $pendingOn($x, 'DO_NOT_SEND', 'the assistant returned nothing', -2 * 24 - 1);
$x = $closedFu(990105, 'sales', -5 * 24, -3 * 24, -2 * 24, 'not_interested'); $pendingOn($x, 'DO_NOT_SEND', 'PLANT the customer said no 256771234567', -2 * 24 - 1);
$x = $closedFu(990106, 'sales', -5 * 24, -3 * 24, -2 * 24, 'not_interested'); $pendingOn($x, 'DO_NOT_SEND', 'unrecognised verdict "PLANT 256771234567"', -2 * 24 - 1);
$x = $closedFu(990107, 'sales', -5 * 24, -3 * 24, -2 * 24, 'not_interested'); $pendingOn($x, 'DO_NOT_SEND', 'the identity behind this number is not certain enough to write to', -2 * 24 - 1);
$x = $closedFu(990108, 'sales', -5 * 24, -3 * 24, -2 * 24, 'vetoed');         $pendingOn($x, 'PLANT 256771234567', 'PLANT an odd verdict', -2 * 24 - 1);   // X8 an odd verdict

// C-a…C-i: quiet conversations with no follow-up since the customer's last message, each for one reason (C-g is not one)
$conv('256700000201', 'sales', 48);                                            // C-a eligible, 1-3 days
$conv('256700000202', 'support', 10 * 24);                                     // C-b eligible, 7-14 days (before a floor 4 days back)
$conv('256700000203', 'sales-001', 3.5 * 24);                                  // C-c a seller's number: held when the hold is in force
$conv('256700000204', 'sales', 20 * 24);                                       // C-d older than followup_max_age_hours
$conv('256700000206', 'sales', 3 * 24, 'human_active');                        // C-f a person has it
$cg = $conv('256700000207', 'sales', 3.5 * 24);                                // C-g followed up and closed this spell: the scan reopens it
$closedFu((int)$cg['id'], 'sales', -3.5 * 24, -3 * 24, -2 * 24, 'exhausted');
$ch = $conv('256700000208', 'support', 2 * 24);                                // C-h followed up only before the last message
$closedFu((int)$ch['id'], 'support', -6 * 24, -5 * 24, -4 * 24, 'replied');
$ci = $conv('256700000209', 'sales', 2 * 24);                                  // C-i a colleague's conversation
$pdo->prepare("UPDATE wa_conversations SET category = ? WHERE id = ?")->execute([" Staff\t\r\n", (int)$ci['id']]);   // PHP's trim and strtolower read it as staff
$cj = $conv('256700000210', 'sales', 3 * 24);                                  // C-j closed this spell, then 'Not interested' (an opt-out)
$closedFu((int)$cj['id'], 'sales', -3 * 24, -2 * 24, -2 * 24 + 1, 'not_interested', '256700000210');
$optout = $pdo->prepare("INSERT INTO contact_optouts (phone, channel, scope, reason, source, evidence, created_by) VALUES (?, '*', 'proactive', ?, ?, 'PLANT', 'staff:PLANT')");
$optout->execute(['256700000210', 'not_interested', 'admin']);
$conv('256700000212', 'sales', 2 * 24);                                        // C-l typed STOP: an opt-out and no follow-up since
$optout->execute(['256700000212', 'customer_request', 'keyword']);
$conv('256771222222', 'sales', 2 * 24);                                        // C-m its phone's quotation is being chased (S10)
$cn = $conv('256700000214', 'support', 2.5 * 24);                              // C-n closed this spell with an odd reason
$closedFu((int)$cn['id'], 'support', -2.5 * 24, -2 * 24, -2 * 24 + 1, 'PLANT odd 256771234567', '256700000214');
$ck = $conv('256700000211', 'sales', 5 * 24);                                  // C-k opened three times for one quiet spell
$ka = $closedFu((int)$ck['id'], 'sales', -5 * 24, -4 * 24, -4 * 24 + 1, 'not_interested', '256771666666');
$kb = $closedFu((int)$ck['id'], 'sales', -5 * 24, -3 * 24, -3 * 24 + 1, 'PLANT 256771234567 vetoed', '256771666666');   // an odd reason
$kc = $closedFu((int)$ck['id'], 'sales', -5 * 24, -2 * 24, -2 * 24 + 1, 'escalated', '256771666666');

// the send ledger: a seller's handset (matched through wa_channels) and a department's (no instance recorded: never named)
$klaDay = gmdate('Y-m-d', $NOW + 3 * 3600); $kla3 = gmdate('Y-m-d', $NOW + 3 * 3600 - 3 * 86400);
$led = $pdo->prepare("INSERT INTO wa_auto_sends (handset, kind, ref, try, mode, proactive, phone, local_day, gap_s, claimed_at, outcome, win_from, win_to, cap_day, cap_hour)
                      VALUES (?, ?, ?, 1, ?, 1, '256771444444', ?, 60, ?, ?, ?, ?, ?, ?)");
$led->execute(['i:plantinstzqx', 'enquiry', 'fu:PLANT1', 'claim', $klaDay, $at(-3), 'sent', 8, 20, 60, 15]);
$led->execute(['i:plantinstzqx', 'enquiry', 'fu:PLANT2', 'claim', $klaDay, $at(-2), 'sent', 8, 20, 60, 15]);
$led->execute(['i:plantinstzqx', 'quote', 'qc:PLANT3', 'claim', $klaDay, $at(-2.5), 'not_sent', 8, 20, 60, 15]);
$led->execute(['i:plantdeptinst', 'enquiry', 'fu:PLANT4', 'claim', $klaDay, $at(-0.5), 'sent', 8, 20, 2, 15]);
$led->execute(['i:plantdeptinst', 'enquiry', 'fu:PLANT5', 'claim', $klaDay, $at(-0.25), 'sent', 8, 20, 2, 15]);
$led->execute(['i:plantdeptinst', 'enquiry', 'fu:PLANT6', 'record', $kla3, $at(-3 * 24), 'sent', null, null, null, null]);

// the book's own settings row, as the store keeps it: master's budget, the screen's switch (off: it differs), a secret
$store->save('kyc_config.json', ['master_budget_seconds' => 240, 'followup_enabled' => '0', 'evo_api_key' => 'PLANT-STORE-SECRET-256771234567']);

// every event the service logged, an hour ago; and the gate's answers, as followup_run.php writes them — in the run's
// last pass, which master stamped 10 minutes ago (master_schedule below)
$pdo->prepare('UPDATE followup_events SET created_at = ?')->execute([$at(-1)]);
$ev = $pdo->prepare('INSERT INTO followup_events (followup_id, conversation_id, event, detail, actor, created_at) VALUES (?, NULL, ?, ?, ?, ?)');
$ev->execute([$f1, 'evaluated', 'defer at daily_cap — 3 of 3 sent on this channel today', 'system', $at(-0.15)]);
$ev->execute([$f2, 'evaluated', 'defer at daily_cap — 3 of 3 sent on this channel today', 'system', $at(-0.14)]);
$ev->execute([$f3, 'evaluated', 'proceed — ok', 'system', $at(-0.13)]);
$ev->execute([$f4, 'evaluated', 'close at human_active — PLANT Nakato 256771234567', 'system', $at(-0.12)]);
$ev->execute([$f6, 'evaluated', 'PLANT NAME 256771234567 — odd', 'system', $at(-0.11)]);
$ev->execute([$f6, 'PLANT EVENT 256771234567', 'x', 'system', $at(-0.10)]);
$ev->execute([$q10, 'evaluated', 'proceed — a quote row', 'system', $at(-0.09)]);       // a quote row's: never counted
$ev->execute([$f0, 'evaluated', 'proceed — too old', 'system', $at(-8 * 24)]);           // older than 7 days: never counted
$ev->execute([$f3, 'failed', 'PLANT evolution said no 256771234567', 'sender', $at(-0.95)]);   // S3's approved draft failed, three times:
$ev->execute([$f3, 'failed', 'Connection Closed [HTTP 500 on POST /message/sendText/PLANTINSTSUP]', 'sender', $at(-0.9)]);    // the forms
$ev->execute([$f3, 'failed', 'Bad Request [HTTP 400 on POST /message/sendText/PLANTINSTSUP]', 'sender', $at(-0.85)]);        // Evolution writes
$ev->execute([$kb, 'evaluated', 'proceed — reopened', 'system', $at(-3 * 24 + 0.5)]);     // C-k's reopens, judged again
$ev->execute([$kc, 'evaluated', 'close at escalation — reopened', 'system', $at(-2 * 24 + 0.5)]);

// master's schedule, as master.php saves it (a flat row)
$store->save('master_schedule.json', [
    'followup_close' => ['last_run' => $NOW - 300,  'last_run_at' => gmdate('Y-m-d H:i:s', $NOW - 300),  'duration_ms' => 120],
    'followup_scan'  => ['last_run' => $NOW - 900,  'last_run_at' => gmdate('Y-m-d H:i:s', $NOW - 900),  'duration_ms' => 300],
    'followup_run'   => ['last_run' => $NOW - 600,  'last_run_at' => gmdate('Y-m-d H:i:s', $NOW - 600),  'duration_ms' => -1],
    'followup_send'  => ['last_run' => $NOW - 7200, 'last_run_at' => gmdate('Y-m-d H:i:s', $NOW - 7200), 'duration_ms' => 1500],
    'starlink_alive' => ['last_run' => $NOW - 60,   'last_run_at' => gmdate('Y-m-d H:i:s', $NOW - 60),   'duration_ms' => 50],
]);

// the settings, in PluginConfig's order (a later file wins a key): ROOT/data/config.json, then DATA/config.json, then
// DATA/kyc_config.json. data/ holds kyc_config.json with the cap of 3 (and a secret that must never print); data1/ the
// same with a cap of 1. root2's config.json says followup_run_limit 2 and a cap of 9, which kyc_config.json overrides,
// so a report that read the files in the wrong order would print 9; root1's says followup_run_limit 1.
$kyc = [
    'followup_enabled' => '1', 'followup_auto_send' => '1', 'followup_daily_cap' => '3', 'wa_followups_on_owned_numbers' => '1',
    'multi_number_channels_enabled' => '1', 'followup_not_before' => '2026-09-12 11:11:02', 'wa_send_ledger' => 'on',
    'evo_api_key' => 'PLANT-SECRET-KEY-256771234567', 'openai_api_key' => 'PLANT-SECRET-OPENAI',
];
file_put_contents($out . '/data/kyc_config.json', json_encode($kyc));
@mkdir($out . '/data1', 0700, true);
file_put_contents($out . '/data1/kyc_config.json', json_encode(['followup_daily_cap' => '1', 'wa_followups_on_owned_numbers' => '0',
    'followup_not_before' => $at(-4 * 24), 'quote_chase_enabled' => 'on', 'wa_send_daily_cap' => '2', 'wa_send_hourly_cap' => '2',
    'wa_send_hour_from' => '9', 'wa_send_hour_to' => '18'] + $kyc));
@mkdir($out . '/data3', 0700, true);
file_put_contents($out . '/data3/kyc_config.json', json_encode(['followup_enabled' => 'off', 'followup_daily_cap' => '0',
    'followup_not_before' => 'PLANT 256771234567', 'followup_auto_send' => 'no', 'followup_run_limit' => '-1'] + $kyc));
@mkdir($out . '/data4', 0700, true);   // R5: the run limit 0, an empty scan limit, a ledger switch that is not exactly 'on'
file_put_contents($out . '/data4/kyc_config.json', json_encode(['followup_run_limit' => '0', 'followup_scan_limit' => '', 'wa_send_ledger' => 'yes',
    'followup_not_before' => '2026-01-0112'] + $kyc));   // not a written date (a digit run after the day): never printed as stored
// R15-R17: data's settings, and the time public.php last ran master from an admin page (piggyback_last_run.txt), against
// the run's last start the test sets a day after NOW: 5 s before it (that cycle's stamp), an hour before it, a minute after
foreach (['data5' => -5, 'data6' => -3600, 'data7' => 60] as $dir => $off) {
    @mkdir($out . "/$dir", 0700, true);
    file_put_contents($out . "/$dir/kyc_config.json", json_encode($kyc));
    file_put_contents($out . "/$dir/piggyback_last_run.txt", (string)($NOW + 86400 + $off));
}
foreach (['root2' => ['followup_run_limit' => '2', 'followup_daily_cap' => '9'], 'root1' => ['followup_run_limit' => '1']] as $dir => $cfg) {
    @mkdir($out . "/$dir/lib", 0700, true); @mkdir($out . "/$dir/data", 0700, true);
    copy($root . '/lib/PluginConfig.php', $out . "/$dir/lib/PluginConfig.php");
    copy($root . '/lib/ConfigVault.php', $out . "/$dir/lib/ConfigVault.php");
    file_put_contents($out . "/$dir/data/config.json", json_encode($cfg));
}
file_put_contents($out . '/vault.json', json_encode(['config' => ['evo_api_key' => 'PLANT-VAULT-SECRET']]));
$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$pdo = null; $store = null;
echo "built: migrations recorded $ledger; ids f0=$f0 f3=$f3 f4=$f4 q10=$q10\n";

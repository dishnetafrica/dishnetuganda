<?php
declare(strict_types=1);
/**
 * ████ TEST FIXTURE — the read-only report's book (5.18.104, root docs/73 §H.2.21 "The report's tests"; root docs/72
 * §F.1 §3.7) ████
 *
 * One book that covers every definition of the report: a quotation confirmed by each of the three markers; a claim with
 * no message and a log row with no message; a reply on another channel; a STOP-only reply; chases by the engine, by a
 * person (a handset row whose metadata is double-encoded, an Inbox image dated by date() in Kampala), and what is never a
 * chase (an AI reply, a notification, an engine row labelled DishNet, our own automation's row); registration and payment
 * by each signal; each owner rule, the F4 order and a name two active accounts share; a colleague's phone; a web: session;
 * `accounts` folding into `account`; a partner's number; a 'PENDING' application; a phone sharing its last nine digits
 * with the customer's; a quotation at the window's edge and one still open. Every person, number and amount is
 * fictitious. The zone is never named here (tests/test_timezone.php): stamps are written as UTC, and the Kampala ones
 * (submitted_at, created_at of cash rows, the Inbox image) as their Kampala wall-clock text.
 *
 * sfr_fixture($pdo, $withNew): fills a fresh book built by the plugin's migrations. $withNew false leaves 089's two
 * tables the report reads through HumanTakeover (wa_auto_sends, wa_seen_marks) empty — the book an 088-shaped copy is
 * compared with (A11-02).
 */

/** A row in one of the store's JSON collections (id, data). */
function sfr_blob(\PDO $p, string $table, array $data): int
{
    $p->exec("CREATE TABLE IF NOT EXISTS [{$table}] (id INTEGER PRIMARY KEY, data TEXT NOT NULL)");
    if (isset($data['id'])) {
        $p->prepare("INSERT INTO [{$table}] (id, data) VALUES (?, ?)")->execute([(int)$data['id'], json_encode($data)]);
        return (int)$data['id'];
    }
    $p->prepare("INSERT INTO [{$table}] (data) VALUES (?)")->execute([json_encode($data)]);
    $id = (int)$p->lastInsertId();
    $data['id'] = $id;
    $p->prepare("UPDATE [{$table}] SET data = ? WHERE id = ?")->execute([json_encode($data), $id]);
    return $id;
}

function sfr_conv(\PDO $p, string $phone, string $channel = 'support', ?string $name = null, ?string $category = null): int
{
    $p->prepare('INSERT INTO wa_conversations (phone, channel, display_name, category) VALUES (?, ?, ?, ?)')->execute([$phone, $channel, $name, $category]);
    return (int)$p->lastInsertId();
}

function sfr_msg(\PDO $p, int $conv, array $m): int
{
    $m += ['direction' => 'out', 'role' => 'agent', 'body' => 'a message (test)', 'agent_name' => null, 'wa_message_id' => null,
           'event_key' => null, 'metadata' => null, 'sent_at' => '2026-10-01 07:00:00'];
    $m += ['created_at' => $m['sent_at']];
    $p->prepare('INSERT INTO wa_messages (conversation_id, direction, role, body, agent_name, wa_message_id, event_key, metadata, sent_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([$conv, $m['direction'], $m['role'], $m['body'], $m['agent_name'], $m['wa_message_id'], $m['event_key'], $m['metadata'], $m['sent_at'], $m['created_at']]);
    return (int)$p->lastInsertId();
}

function sfr_in(\PDO $p, int $conv, string $body, string $at): void
{
    sfr_msg($p, $conv, ['direction' => 'in', 'role' => 'customer', 'body' => $body, 'sent_at' => $at]);
}

/** The three quotation texts' heads (lib/QuotationService.php:507-516; webhook.php:2998-3002; includes/api/api_whatsapp.php:665). */
function sfr_ref_text(string $ref): string
{
    return "📄 *Quotation*\n\n📋 *Ref:* {$ref}\n👤 *To:* Test Buyer\n⏳ *Valid Until:* 30 Nov 2026\n\n  • Kit — 1\n";
}
function sfr_order_text(string $num): string { return "📄 *Quotation & Order Summary*\n\nDear Test Buyer,\n\n🆔 *Order:* {$num}\n"; }
function sfr_quote_text(string $num): string { return "📄 *Quote #{$num}*\n\nDear Test Buyer,\n\nValid for 30 days.\n"; }

/** A sent quotation: its text on the customer's conversation. */
function sfr_quote(\PDO $p, int $conv, string $event, string $body, string $at): int
{
    return sfr_msg($p, $conv, ['body' => $body, 'agent_name' => 'DishNet Plugin', 'event_key' => $event, 'sent_at' => $at]);
}

/** An engine follow-up that went: its followups row and its followup_sends row. */
function sfr_engine_send(\PDO $p, int $conv, string $phone, string $at): void
{
    $p->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, opened_at, closed_at, close_reason) VALUES (?, 'support', ?, ?, ?, ?, 'exhausted')")
      ->execute([$conv, $phone, $at, $at, $at]);
    $fid = (int)$p->lastInsertId();
    $p->prepare("INSERT INTO followup_sends (followup_id, attempt, sent_at, channel, phone, body, decided_by) VALUES (?, 1, ?, 'support', ?, 'Hello (test)', 'staff:Tester')")
      ->execute([$fid, $at, $phone]);
}

/**
 * Fill the book. Returns what the test needs to know about it: the phones (none may be printed) and the names (none
 * may be printed).
 */
function sfr_fixture(\PDO $p, bool $withNew = true): array
{
    // People: five accounts; two share a name; one is inactive; the first (a staff account) has a phone: a colleague's.
    sfr_blob($p, 'retailers', ['id' => 201, 'name' => 'Test Seller Kay', 'is_active' => true, 'role' => 'support', 'phone' => '0772500201']);
    sfr_blob($p, 'retailers', ['id' => 202, 'name' => 'Test Seller Lee', 'is_active' => true, 'role' => 'sales']);
    sfr_blob($p, 'retailers', ['id' => 203, 'name' => 'Test Seller Kay', 'is_active' => true, 'role' => 'sales']);
    sfr_blob($p, 'retailers', ['id' => 204, 'name' => 'Test Seller Moe', 'is_active' => true, 'role' => 'sales']);
    sfr_blob($p, 'retailers', ['id' => 205, 'name' => 'Test Seller Nil', 'is_active' => false, 'role' => 'sales']);

    // Numbers: the three departments (seeded by 087), a salesperson's and a partner's.
    foreach (['sales' => '+256700580011', 'support' => '+256700580012', 'account' => '+256700580013'] as $id => $num) {
        $p->prepare('UPDATE wa_channels SET business_number = ? WHERE channel_id = ?')->execute([$num, $id]);
    }
    $p->exec("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, owner_staff_id, business_number) VALUES ('sales-002', 'Line-Two', 'Test Line Moe', 'sales', 'staff', 204, '+256700580021')");
    $p->exec("INSERT INTO wa_channels (channel_id, evo_instance, display_name, role, owner_type, owner_partner_id, business_number) VALUES ('retailer-001', 'Line-Three', 'Test Partner', 'sales', 'partner', 1, '+256700580031')");
    // The columns the running plugin adds to a live book (ConversationService, CashbookService), absent from a fresh one.
    $have = array_flip($p->query("SELECT name FROM pragma_table_info('wa_conversations')")->fetchAll(\PDO::FETCH_COLUMN));
    if (!isset($have['crm_link_method'])) $p->exec('ALTER TABLE wa_conversations ADD COLUMN crm_link_method TEXT');
    $p->exec('ALTER TABLE cb_ledger ADD COLUMN crm_payment_id INTEGER');
    $p->exec('ALTER TABLE cb_ledger ADD COLUMN crm_client_id INTEGER');
    $p->exec("CREATE TABLE IF NOT EXISTS wa_sent_quotes (quote_id INTEGER NOT NULL, quote_ref TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT '',
              sent_at TEXT NOT NULL DEFAULT (datetime('now')), PRIMARY KEY (quote_id))");
    sfr_blob($p, 'quotes_log', ['type' => 'manual', 'quote_ref' => 'K-090', 'sent_by' => 'Test Seller Lee', 'created_at' => '2026-07-01 10:00:00']);

    // K-121: 90 days only (1 Sep).
    $c = sfr_conv($p, '256772503121', 'support', 'Test Buyer Ninety');
    sfr_in($p, $c, 'hello (test)', '2026-08-30 09:00:00');
    sfr_quote($p, $c, 'quote_manual', sfr_ref_text('K-121'), '2026-09-01 07:00:00');

    // K-122: at the 30-day window's edge, an hour before it opens (3 Oct 06:00 UTC, 09:00 Kampala): 90 days only.
    $c = sfr_conv($p, '256772503122', 'support', 'Test Buyer Edge');
    sfr_in($p, $c, 'hello (test)', '2026-10-02 09:00:00');
    sfr_quote($p, $c, 'quote_manual', sfr_ref_text('K-122'), '2026-10-03 06:00:00');

    // K-101 (📋): the customer wrote on sales; the quotation went to support; the reply came on sales (another channel). Rule 3.
    $s1 = sfr_conv($p, '256772503101', 'sales', 'Test Buyer One');
    $q1 = sfr_conv($p, '256772503101', 'support');
    sfr_in($p, $s1, 'how much is the kit? (test)', '2026-10-09 09:00:00');
    sfr_quote($p, $q1, 'quote_manual', sfr_ref_text('K-101'), '2026-10-10 07:00:00');
    sfr_blob($p, 'quotes_log', ['type' => 'manual', 'quote_ref' => 'K-101', 'sent_by' => 'Test Seller Lee', 'created_at' => '2026-10-10 10:00:00']);
    sfr_in($p, $s1, 'thank you, I will think (test)', '2026-10-11 09:00:00');

    // 2002 (🆔): spoke on support; the only reply is STOP: opted out, never answered; unattributed; neither.
    $c = sfr_conv($p, '256772503102', 'support', 'Test Buyer Two');
    sfr_in($p, $c, 'price please (test)', '2026-10-11 09:00:00');
    sfr_quote($p, $c, 'ops_quote_wa', sfr_order_text('2002'), '2026-10-12 07:00:00');
    sfr_in($p, $c, 'STOP', '2026-10-13 09:00:00');

    // 3003 (📄): chased by the engine; rule 2 (a lead quotation, the lead's owner).
    $c = sfr_conv($p, '256772503103', 'support', 'Test Buyer Three');
    sfr_in($p, $c, 'hi (test)', '2026-10-13 09:00:00');
    sfr_quote($p, $c, 'ops_quote_text', sfr_quote_text('3003'), '2026-10-14 07:00:00');
    sfr_blob($p, 'quotes_log', ['type' => 'lead', 'quote_ref' => '3003', 'sent_by' => 'Test Seller Moe', 'created_at' => '2026-10-14 10:00:00']);
    sfr_blob($p, 'leads', ['quote_ref' => '3003', 'assigned_to' => 202, 'customer_name' => 'Test Buyer Three']);
    sfr_engine_send($p, $c, '256772503103', '2026-10-15 07:00:00');

    // K-104: from a registration (rule 1, its sales_person); chased by a person on the handset, metadata double-encoded.
    $s4 = sfr_conv($p, '256772503104', 'sales', 'Test Buyer Four');
    $q4 = sfr_conv($p, '256772503104', 'support');
    sfr_in($p, $s4, 'I want to register (test)', '2026-10-15 09:00:00');
    sfr_quote($p, $q4, 'quote_lead', sfr_ref_text('K-104'), '2026-10-16 07:00:00');
    sfr_blob($p, 'kyc_applications', ['quote_ref' => 'K-104', 'sales_person' => 'Test Seller Lee', 'retailer_id' => 204, 'mobile' => '0772503104',
                                      'firstname' => 'Test', 'lastname' => 'Buyer Four', 'submitted_at' => '2026-10-16 08:00:00']);
    sfr_msg($p, $s4, ['agent_name' => 'Team', 'body' => 'did you see it? (test)', 'metadata' => json_encode(json_encode(['source' => 'handset'])),
                      'sent_at' => '2026-10-17 09:00:00']);

    // K-105: never a chase — an AI reply and a notification; the application's sales_person names two active accounts, so
    // rule 1 falls to its submitter; neither.
    $c = sfr_conv($p, '256772503105', 'support', 'Test Buyer Five');
    sfr_in($p, $c, 'hello (test)', '2026-10-17 09:00:00');
    sfr_quote($p, $c, 'quote_kyc', sfr_ref_text('K-105'), '2026-10-18 07:00:00');
    sfr_blob($p, 'kyc_applications', ['quote_ref' => 'K-105', 'sales_person' => 'Test Seller Kay', 'retailer_id' => 202, 'mobile' => '0772503105',
                                      'submitted_at' => '2026-10-18 08:00:00', 'sales_type' => 'Credit', 'amount_charged' => 0]);
    sfr_msg($p, $c, ['role' => 'assistant', 'agent_name' => 'DishNet AI', 'body' => 'the AI answers (test)', 'sent_at' => '2026-10-19 07:00:00']);
    sfr_msg($p, $c, ['agent_name' => 'DishNet Plugin', 'event_key' => 'ops_pre_due_d3', 'body' => 'a reminder (test)', 'sent_at' => '2026-10-19 08:00:00']);

    // K-106: the customer never wrote; registered later by its mobile; paid by the receipt message.
    $c = sfr_conv($p, '256772503106', 'support');
    sfr_quote($p, $c, 'quote_manual', sfr_ref_text('K-106'), '2026-10-20 07:00:00');
    sfr_blob($p, 'kyc_applications', ['quote_ref' => 'K-906', 'mobile' => '0772503106', 'submitted_at' => '2026-10-22 10:00:00']);
    sfr_msg($p, $c, ['agent_name' => 'DishNet Plugin', 'event_key' => 'ops_payment_received', 'body' => 'payment received (test)', 'sent_at' => '2026-10-25 07:00:00']);

    // K-107: on `accounts` (folded into account), sent from account; paid by a cashbook receipt for its client.
    $c = sfr_conv($p, '256772503107', 'accounts', 'Test Buyer Seven');
    sfr_in($p, $c, 'my invoice? (test)', '2026-10-20 09:00:00');
    sfr_quote($p, $c, 'quote_manual', sfr_ref_text('K-107'), '2026-10-21 07:00:00');
    sfr_blob($p, 'quotes_log', ['type' => 'manual', 'quote_ref' => 'K-107', 'sent_by' => 'nobody', 'crm_client_id' => 7007, 'created_at' => '2026-10-21 10:00:00']);
    $p->exec("INSERT INTO cb_ledger (direction, amount, crm_payment_id, crm_client_id, created_at) VALUES ('in', 1800000, 77, 7007, '2026-10-23 10:00:00')");
    sfr_blob($p, 'kyc_applications', ['crm_client_id' => 7007, 'submitted_at' => '2026-10-24 10:00:00']);   // registered, by its client

    // K-108: to a colleague's phone (an active account's): out of the report.
    $c = sfr_conv($p, '256772500201', 'support');
    sfr_quote($p, $c, 'quote_manual', sfr_ref_text('K-108'), '2026-10-21 08:00:00');

    // K-110: a cash receipt (quote_cash) is no quotation.
    $c = sfr_conv($p, '256772503110', 'support', 'Test Buyer Ten');
    sfr_quote($p, $c, 'quote_cash', sfr_ref_text('K-110'), '2026-10-22 07:00:00');

    // A claim with no message, and a log row with no message.
    $p->exec("INSERT INTO wa_sent_quotes (quote_id, quote_ref, source, sent_at) VALUES (9111, 'K-111', 'flow_b_ucrm', '2026-10-22 08:00:00')");
    sfr_blob($p, 'quotes_log', ['type' => 'manual', 'quote_ref' => 'K-112', 'sent_by' => 'Test Seller Lee', 'created_at' => '2026-10-22 10:00:00']);

    // K-113: the customer wrote on a partner's number: its own row.
    $pc = sfr_conv($p, '256772503113', 'retailer-001', 'Test Buyer Partner');
    $c = sfr_conv($p, '256772503113', 'support');
    sfr_in($p, $pc, 'hello partner (test)', '2026-10-22 09:00:00');
    sfr_quote($p, $c, 'quote_manual', sfr_ref_text('K-113'), '2026-10-23 07:00:00');
    sfr_blob($p, 'kyc_applications', ['wa_quote_phone' => '0772503113', 'submitted_at' => '2026-10-25 10:00:00']);   // registered, by its wa_quote_phone

    // K-114: the F4 order — an application (rule 1, Moe), a lead (rule 2, Lee) and a log (rule 3, Lee): rule 1 wins.
    $s14 = sfr_conv($p, '256772503114', 'sales-002', 'Test Buyer Fourteen');
    $c = sfr_conv($p, '256772503114', 'support');
    sfr_in($p, $s14, 'my own salesperson (test)', '2026-10-23 09:00:00');
    sfr_quote($p, $c, 'quote_lead', sfr_ref_text('K-114'), '2026-10-24 07:00:00');
    sfr_blob($p, 'kyc_applications', ['quote_ref' => 'K-114', 'sales_person' => 'Test Seller Moe', 'crm_client_id' => 7014, 'submitted_at' => '2026-10-24 08:00:00']);
    sfr_blob($p, 'kyc_applications', ['crm_client_id' => 7014, 'sales_type' => 'Cash', 'amount_charged' => 1800000, 'submitted_at' => '2026-10-26 10:00:00']);   // paid, by a Cash application
    sfr_blob($p, 'quotes_log', ['type' => 'lead', 'quote_ref' => 'K-114', 'sent_by' => 'Test Seller Lee', 'created_at' => '2026-10-24 10:00:00']);
    sfr_blob($p, 'leads', ['quote_ref' => 'K-114', 'assigned_to' => 202, 'customer_name' => 'Test Buyer Fourteen']);

    // PENDING: a quotation numbered PENDING, and an application still waiting for its quotation number.
    $c = sfr_conv($p, '256772503115', 'support', 'Test Buyer Pending');
    sfr_in($p, $c, 'hello (test)', '2026-10-24 09:00:00');
    sfr_quote($p, $c, 'ops_quote_wa', sfr_order_text('PENDING'), '2026-10-25 07:00:00');
    sfr_blob($p, 'kyc_applications', ['quote_ref' => 'PENDING', 'quote_id' => 'PENDING', 'sales_person' => 'Test Seller Moe', 'submitted_at' => '2026-10-25 08:00:00',
                                      'quote_refused' => 'P1']);

    // K-116: an Inbox image sent BEFORE the quotation, dated by date() in Kampala (09:30, 06:30 UTC): not a chase.
    $c = sfr_conv($p, '256772503116', 'support', 'Test Buyer Image');
    sfr_in($p, $c, 'a photo please (test)', '2026-10-25 09:00:00');
    sfr_msg($p, $c, ['agent_name' => 'Test Staff', 'event_key' => 'wa_image_send', 'body' => '[image]', 'sent_at' => '2026-10-26 09:30:00', 'created_at' => '2026-10-26 06:30:00']);
    sfr_quote($p, $c, 'quote_manual', sfr_ref_text('K-116'), '2026-10-26 07:00:00');

    // K-117 and K-118: an engine row labelled DishNet is never a person; our own automation's row neither.
    $c = sfr_conv($p, '256772503118', 'support', 'Test Buyer Engine');
    sfr_in($p, $c, 'hello (test)', '2026-10-24 09:00:00');
    sfr_quote($p, $c, 'quote_manual', sfr_ref_text('K-118'), '2026-10-25 07:30:00');
    sfr_msg($p, $c, ['agent_name' => 'DishNet', 'body' => 'an enquiry follow-up (test)', 'sent_at' => '2026-10-26 08:00:00']);
    if ($withNew) {
        $c = sfr_conv($p, '256772503117', 'support', 'Test Buyer Echo');
        sfr_in($p, $c, 'hello (test)', '2026-10-24 09:00:00');
        sfr_quote($p, $c, 'quote_manual', sfr_ref_text('K-117'), '2026-10-25 07:20:00');
        sfr_msg($p, $c, ['agent_name' => 'Team', 'wa_message_id' => 'WA-ECHO-117', 'body' => 'our own chase, echoed (test)', 'sent_at' => '2026-10-26 08:00:00']);
        $p->exec("INSERT INTO wa_auto_sends (handset, kind, ref, mode, proactive, phone, local_day, gap_s, claimed_at, outcome, settled_at, wa_message_id)
                  VALUES ('i:dishnet-ug', 'quote', 'fu:1:1', 'record', 1, '256772503117', '2026-10-26', 60, '2026-10-26 08:00:00', 'sent', '2026-10-26 08:00:00', 'WA-ECHO-117')");
        $p->exec("INSERT INTO wa_seen_marks (direction, phone, channel, instance, kind, wa_message_id, seen_at)
                  VALUES ('in', '256772503120', 'support', 'DishNet-UG', 'audioMessage', 'WA-MARK-1', '2026-10-30 06:00:00')");
    }

    // K-119: a +211 number sharing the customer's last nine digits writes back: not this customer's answer.
    $c = sfr_conv($p, '256772503119', 'support', 'Test Buyer Nineteen');
    sfr_in($p, $c, 'hello (test)', '2026-10-25 09:00:00');
    sfr_quote($p, $c, 'quote_manual', sfr_ref_text('K-119'), '2026-10-26 07:00:00');
    $o = sfr_conv($p, '211772503119', 'support', 'Test Buyer Elsewhere');
    sfr_in($p, $o, 'wrong person writes (test)', '2026-10-27 09:00:00');

    // K-120: sent four days ago: still open.
    $c = sfr_conv($p, '256772503120', 'support', 'Test Buyer Twenty');
    sfr_in($p, $c, 'hello (test)', '2026-10-28 09:00:00');
    sfr_quote($p, $c, 'quote_manual', sfr_ref_text('K-120'), '2026-10-29 07:00:00');

    // A web chat session, and a staff-filed conversation (out of every count).
    $w = sfr_conv($p, 'web:abcdef0123', 'web');
    sfr_in($p, $w, 'hello from the website (test)', '2026-10-27 09:00:00');
    $st = sfr_conv($p, '256772503199', 'support', 'Test Staff Thread', 'staff');
    sfr_in($p, $st, 'internal (test)', '2026-10-27 09:00:00');

    // Payment by a Cash application, and by a collection, for 2002's client (by its conversation's trusted link).
    $p->exec("UPDATE wa_conversations SET crm_client_id = 7002, crm_link_method = 'verified' WHERE phone = '256772503102'");
    sfr_blob($p, 'payment_collections', ['crm_customer_id' => '7002', 'amount' => 900000, 'created_at' => '2026-10-14 10:00:00']);

    return [
        'phones' => ['256772503101', '256772503102', '256772503103', '256772503104', '256772503105', '256772503106', '256772503107',
                     '256772500201', '256772503110', '256772503113', '256772503114', '256772503115', '256772503116', '256772503117',
                     '256772503118', '256772503119', '211772503119', '256772503120', '256772503121', '256772503122', '256772503199',
                     '256700580011', '256700580012', '256700580013', '256700580021', '256700580031'],
        'names'  => ['Test Seller', 'Test Buyer', 'Test Staff', 'Test Line', 'Test Partner', 'Test Customer'],
    ];
}

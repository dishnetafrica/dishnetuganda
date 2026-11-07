<?php
declare(strict_types=1);
/**
 * Weakened copies judged by section K (5.18.104, root docs/73 §H.4.16 K): the engine halves of the copies D names for K —
 * Part F's real ledger, hold and person rule under P6's calls. A copy D also names for D, F or A carries the same edit as
 * there (taken from that file's entry, so the two cannot drift) and is judged here again by K's own case. The --check
 * halves (OM91's, OM94, OM96, OM98–OM106, OM108, OM109, OM112, OM114–OM119, OM121, OM123) are stage B8's. TEST ONLY.
 */
$S = 'lib/OfferSender.php';

/** The entry of an earlier file whose label begins with $prefix, re-judged by K's $cases. */
$wo_again = function (string $prefix, string $cases) use (&$M): array {
    $hit = array_values(array_filter($M, function ($m) use ($prefix) { return strpos($m['label'], $prefix) === 0; }));
    if (count($hit) !== 1) throw new \LogicException("mutants_K: {$prefix} found " . count($hit) . ' times in the earlier files');
    $m = $hit[0];
    $m['label'] = $m['label'] . ' — again under Part F\'s real pieces';
    $m['sections'] = 'K';
    $m['cases'] = $cases;
    $m['pattern'] = '/(' . $cases . ') /';
    return $m;
};

$M = array_merge($M, [
    $wo_again('OM20 ', 'K07'),          // the webhook's handset echo of the offer itself read as a colleague
    $wo_again('OM27 ', 'K01|K04'),      // no claim: the chase's spacing ignored, and the row forced back sent again
    $wo_again('OM31 ', 'K12'),          // the real ledger holds sent for a may-have-been-sent
    $wo_again('OM67 ', 'K04'),          // the real ledger answers no_phone: the recipient skipped, never sent
    $wo_again('OM70 ', 'K07'),          // the out seen mark (a handset voice note) not a person
    $wo_again('OM73 ', 'K13'),          // the claim made before the day's reminders: the real ledger defers, the run does not end
    $wo_again('OM74 ', 'K14'),          // the raw instance name as the handset, through the real gateway
    $wo_again('OM83 ', 'K04'),          // a new ref on the second attempt: phone_today, not already_sent
    $wo_again('OM93 ', 'K08'),          // no in-flight bound: claimed 10 s before the close and sent after it
    ['label' => 'OM16-K Part F\'s hold ignored at the send (the single-phone read)', 'file' => 'lib/OfferAudience.php',
     'old' => 'self::one($phone, QuoteChase::offerHold($pdo, $phone, $at))', 'new' => '[]',
     'sections' => 'K', 'cases' => 'K09', 'pattern' => '/K09 /'],
    ['label' => 'OM79 the test claimed with kind other instead of recorded', 'file' => $S,
     'old' => "\$ledger = \$budget->record(\$handset, 'other', 'offer_test:' . \$tid, \$sent ? 'sent' : 'failed',\n                                  ['maybe_sent' => \$maybe, 'wa_message_id' => \$res->messageId]);",
     'new' => "\$ledger = (string)(\$budget->claim(\$handset, 'other', 'offer_test:' . \$tid, [])['defer'] ?? 'claimed');",
     'sections' => 'K', 'cases' => 'K06', 'pattern' => '/K06 /'],
    ['label' => 'OM81 settle without wa_message_id', 'file' => $S,
     'old' => "            \$fb->settle(\$h, 'sent', ['wa_message_id' => \$res->messageId]);", 'new' => "            \$fb->settle(\$h, 'sent');",
     'sections' => 'K', 'cases' => 'K07', 'pattern' => '/K07 /'],
    ['label' => 'OM97 Part F\'s QuoteChase without its quote_text_48h clause', 'file' => 'lib/QuoteChase.php',
     'old' => "            if (QuoteSendEvidence::read(\$r) !== null) \$out['quote_text_48h']", 'new' => "            if (false) \$out['quote_text_48h']",
     'sections' => 'K', 'cases' => 'K09', 'pattern' => '/K09 /'],
    ['label' => 'OM107 step 4 commits as soon as judge() returns, without holding the lock past judged_at\'s second', 'file' => $S,
     'old' => "            (\$this->wait)(\$real);", 'new' => "            unset(\$real);",
     'sections' => 'K', 'cases' => 'K10', 'pattern' => '/K10 /'],
    ['label' => 'OM113 sendTest() tests the screen alone, not WaSendBudget::on()', 'file' => $S,
     'old' => "        if (!WaSendBudget::on(\$f, \$this->dataDir)) return \$no('ledger_off');\n", 'new' => '',
     'sections' => 'K', 'cases' => 'K06', 'pattern' => '/K06 /'],
    ['label' => 'OM120 Start counts a sent test whatever its ledger', 'file' => 'lib/OfferStore.php',
     'old' => "AND state = 'sent' AND ledger = 'recorded'", 'new' => "AND state = 'sent'",
     'sections' => 'K', 'cases' => 'K15', 'pattern' => '/K15 /'],
    ['label' => 'OM122 sendTest() writes ledger by an UPDATE of its own, after the settle', 'file' => $S,
     'old' => "        \$this->pdo->prepare(\"UPDATE wa_offer_tests SET state = ?, reason = ?, ledger = ?, echo = ?, wa_message_id = ?, settled_at = ?\n"
            . "                              WHERE id = ? AND state = 'sending'\")\n"
            . "            ->execute([\$state, \$reason, (string)\$ledger, \$res->echo, \$res->messageId === '' ? null : \$res->messageId,\n"
            . "                       UtcClock::stamp(\$this->now()), \$tid]);\n",
     'new' => "        \$this->pdo->prepare(\"UPDATE wa_offer_tests SET state = ?, reason = ?, echo = ?, wa_message_id = ?, settled_at = ?\n"
            . "                              WHERE id = ? AND state = 'sending'\")\n"
            . "            ->execute([\$state, \$reason, \$res->echo, \$res->messageId === '' ? null : \$res->messageId,\n"
            . "                       UtcClock::stamp(\$this->now()), \$tid]);\n"
            . "        \$this->pdo->prepare('UPDATE wa_offer_tests SET ledger = ? WHERE id = ?')->execute([(string)\$ledger, \$tid]);\n",
     'sections' => 'K', 'cases' => 'K11', 'pattern' => '/K11 /'],
    ['label' => 'OM124 sendTest() lets started_at take its INSERT\'s default', 'edits' => [
        [$S, "(offer_id, content_hash, variant, staff_id, channel, handset, state, started_at)\n                             VALUES (?, ?, ?, ?, ?, ?, 'sending', ?)\")",
             "(offer_id, content_hash, variant, staff_id, channel, handset, state)\n                             VALUES (?, ?, ?, ?, ?, ?, 'sending')\")"],
        [$S, "\$channel, \$handset, UtcClock::stamp(\$t0)]);", "\$channel, \$handset]);"]],
     'sections' => 'K', 'cases' => 'K11', 'pattern' => '/K11 /'],
    // K07's fixtures through their real writers (ruling R3; B7-R02): each writer weakened so it no longer leaves the person's row
    // HumanTakeover reads while it still sets the conversation's state, which Part O does not read (ruling R3 dropped that clause)
    ['label' => 'OM-K2 the Inbox reply sets human_active but stores no row (wa_send_reply)', 'file' => 'includes/api/api_whatsapp.php',
     'old' => "        \$_convSvc->storeMessage(\$convId, [\n            'direction' => 'out', 'role' => 'agent', 'body' => \$text,\n            'agent_name' => \$staffNm, 'event_key' => 'wa_staff_reply',",
     'new' => "        if (false) \$_convSvc->storeMessage(\$convId, [\n            'direction' => 'out', 'role' => 'agent', 'body' => \$text,\n            'agent_name' => \$staffNm, 'event_key' => 'wa_staff_reply',",
     'sections' => 'K', 'cases' => 'K07', 'pattern' => '/K07 /'],
    ['label' => 'OM-K3 the Inbox reply stored under an event key HumanTakeover does not read', 'file' => 'includes/api/api_whatsapp.php',
     'old' => "            'agent_name' => \$staffNm, 'event_key' => 'wa_staff_reply',", 'new' => "            'agent_name' => \$staffNm, 'event_key' => 'wa_staff_text',",
     'sections' => 'K', 'cases' => 'K07', 'pattern' => '/K07 /'],
    ['label' => 'OM-K4 the Inbox image stored as automation (DishNet) while it sets human_active', 'file' => 'includes/api/api_whatsapp.php',
     'old' => "'agent_name' => \$staffNm, 'event_key' => 'wa_image_send',", 'new' => "'agent_name' => 'DishNet', 'event_key' => 'wa_image_send',",
     'sections' => 'K', 'cases' => 'K07', 'pattern' => '/K07 /'],
    ['label' => 'OM-K5 the webhook stores a colleague\'s handset text as automation while it still marks human_active', 'file' => 'evo_webhook.php',
     'old' => "'agent_name'    => \$canned ? ConversationService::AGENT_AUTO_REPLY : 'Team',", 'new' => "'agent_name'    => \$canned ? ConversationService::AGENT_AUTO_REPLY : 'DishNet',",
     'sections' => 'K', 'cases' => 'K07', 'pattern' => '/K07 /'],
    ['label' => 'OM-K6 the hand-over emits wa.escalation without needs_human', 'file' => 'lib/Handover.php',
     'old' => "\"UPDATE wa_conversations SET state = 'needs_human', updated_at = datetime('now') WHERE id = ?\"",
     'new' => "\"UPDATE wa_conversations SET state = state, updated_at = datetime('now') WHERE id = ?\"",
     'sections' => 'K', 'cases' => 'K07', 'pattern' => '/K07 /'],
    ['label' => 'OM-K1 the claim made on the channel, not the recipient\'s handset', 'file' => $S,
     'old' => "\$fb->claim((string)\$row['handset'], 'offer', \$ref,", 'new' => "\$fb->claim((string)\$row['channel'], 'offer', \$ref,",
     'sections' => 'K', 'cases' => 'K01', 'pattern' => '/K01 /'],
]);
unset($wo_again);

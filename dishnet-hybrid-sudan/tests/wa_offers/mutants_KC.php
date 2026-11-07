<?php
declare(strict_types=1);
/**
 * Weakened copies of the --check half of K (5.18.104, root docs/73 §H.4.6, §H.4.16 K; ruling R18), judged by section K's
 * cases KC01 … (tests/wa_offers/k_checks.php): each judges a past act by the current state, or reads FAIL over a designed
 * outcome, and the case where the world moved after a correct act catches it. Loaded after every earlier file, so the copies
 * D names for B, C and K that weaken another file (OM2, OM91, OM97, OM104, OM113, OM117, OM120) are re-judged here by
 * taking that file's entry (one edit text, so the two cannot drift). Each new copy is one anchored edit of
 * lib/OfferSwitch.php (or several, each found exactly once). B8-fix1 adds C-O2-9's clause copies (OM-KC4 … OM-KC9), the
 * halves D names in B8's scope (OM69 by KC24, OM119 by K15, OM78 by B13, OM118 by KC30) and the planted cases' copies
 * judged again over the real flows (OM94, OM96, OM115, OM116, OM121, OM123 by KC25 … KC29). TEST ONLY.
 */
$SW = 'lib/OfferSwitch.php';
$wo_take = function (string $prefix, string $section, string $cases) use (&$M): array {
    $hit = array_values(array_filter($M, function ($m) use ($prefix) { return strpos($m['label'], $prefix) === 0; }));
    if (count($hit) !== 1) throw new \LogicException("mutants_KC: {$prefix} found " . count($hit) . ' times in the earlier files');
    $m = $hit[0];
    $m['label'] = $m['label'] . ' — again by ' . $section . ' (' . $cases . ')';
    $m['sections'] = $section;
    $m['cases'] = $cases;
    $m['pattern'] = '/(' . $cases . ') /';
    return $m;
};
$M = array_merge($M, [
    $wo_take('OM2 ', 'B', 'B03'),
    $wo_take('OM113 ', 'B', 'B12'),
    $wo_take('OM120 ', 'B', 'B06'),
    array_merge($wo_take('OM120 ', 'C', 'C07'), ['cases' => 'C0[17]', 'pattern' => '/C07 /']),
    $wo_take('OM91 ', 'K', 'KC18'),
    $wo_take('OM97 ', 'K', 'KC01'),
    $wo_take('OM104 ', 'K', 'KC18'),
    $wo_take('OM113 ', 'K', 'KC19'),
    $wo_take('OM117 ', 'K', 'KC06'),
    // B8-fix1 (B8-R03): halves D names in B8's scope that were not judged there (OM69 "D, K"; OM119 "B, K"; OM78 "G, B";
    // OM118 "B, K"), each taking the earlier file's entry
    $wo_take('OM69 ', 'K', 'KC24'),
    $wo_take('OM119 ', 'K', 'K15'),
    $wo_take('OM78 ', 'B', 'B13'),

    ['label' => 'OM94 C-O2-2 judges an uncertain(ledger_already_sent) row by its own sent_at', 'file' => $SW,
     'old' => "                if (!self::inWindow((string)\$l['claimed_at'], (int)\$l['win_from'], (int)\$l['win_to'], self::IN_FLIGHT_S)) {",
     'new' => "                if (!self::inWindow((string)((string)\$r['reason'] === 'ledger_already_sent' ? \$r['sent_at'] : \$l['claimed_at']), (int)\$l['win_from'], (int)\$l['win_to'], self::IN_FLIGHT_S)) {",
     'sections' => 'K', 'cases' => 'KC17', 'pattern' => '/KC17 /'],
    ['label' => 'OM96 C-O2-9\'s followups clause without the opened_at bound', 'file' => $SW,
     'old' => "                if ((string)\$x['opened_at'] <= \$m && (\$x['closed_at'] === null || (string)\$x['closed_at'] > \$m)) { \$fail('followup_open'); break; }",
     'new' => "                if ((\$x['closed_at'] === null || (string)\$x['closed_at'] > \$m)) { \$fail('followup_open'); break; }",
     'sections' => 'K', 'cases' => 'KC16', 'pattern' => '/KC16 /'],
    ['label' => 'OM98 C-O2-9 and C-O2-10 re-judged at sent_at, the gateway\'s return', 'edits' => [
        [$SW, "            \$p = (string)\$r['phone'];\n            \$m = (string)\$r['judged_at'];", "            \$p = (string)\$r['phone'];\n            \$m = (string)\$r['sent_at'];"],
        [$SW, "            \$m = (string)\$r['judged_at'];\n            \$mt = UtcClock::parse(\$m);\n            \$warmFrom", "            \$m = (string)\$r['sent_at'];\n            \$mt = UtcClock::parse(\$m);\n            \$warmFrom"]],
     'sections' => 'K', 'cases' => 'KC02', 'pattern' => '/KC02 /'],
    ['label' => 'OM99 C-O2-9\'s and C-O2-10\'s inbound sources bounded by wa_messages.sent_at, the device time', 'edits' => [
        [$SW, "foreach (self::all(\$ro, \"SELECT c.phone, m.created_at FROM wa_messages m", "foreach (self::all(\$ro, \"SELECT c.phone, m.sent_at AS created_at FROM wa_messages m"],
        [$SW, "WHERE conversation_id = ? AND direction = 'in' AND created_at <= ? AND sent_at >= ?\",", "WHERE conversation_id = ? AND direction = 'in' AND sent_at <= ? AND sent_at >= ?\","]],
     'sections' => 'K', 'cases' => 'KC02', 'pattern' => '/KC02 /'],
    ['label' => 'OM100 C-O2-2 judged by WaSendBudget::window($config) as it reads now', 'edits' => [
        [$SW, "            self::c2Window(\$done, \$byRef, \$c, \$fails);", "            self::c2Window(\$done, \$byRef, \$c, \$fails, \$config);"],
        [$SW, "    private static function c2Window(array \$done, array \$byRef, array &\$c, array &\$fails): void",
              "    private static function c2Window(array \$done, array \$byRef, array &\$c, array &\$fails, array \$config = []): void"],
        [$SW, "(int)\$l['win_from'], (int)\$l['win_to'], self::IN_FLIGHT_S)", "WaSendBudget::window(\$config)[0], WaSendBudget::window(\$config)[1], self::IN_FLIGHT_S)"]],
     'sections' => 'K', 'cases' => 'KC04', 'pattern' => '/KC04 /'],
    ['label' => 'OM101 C-O2-5 judged by offers_daily_max_per_handset as it reads now', 'edits' => [
        [$SW, "            self::c5DayMax(\$done, \$ledger, \$c, \$fails, \$notes);", "            self::c5DayMax(\$done, \$ledger, \$c, \$fails, \$notes, \$config);"],
        [$SW, "    private static function c5DayMax(array \$done, array \$ledger, array &\$c, array &\$fails, array &\$notes): void",
              "    private static function c5DayMax(array \$done, array \$ledger, array &\$c, array &\$fails, array &\$notes, array \$config = []): void"],
        [$SW, "if (\$r['day_max'] === null || \$before >= (int)\$r['day_max'])", "if (\$before >= (int)(self::settings(\$config)['offers_daily_max_per_handset'] ?? 20))"]],
     'sections' => 'K', 'cases' => 'KC04', 'pattern' => '/KC04 /'],
    ['label' => 'OM102 C-O2-6 judged by wa_send_gap_min_s as it reads now', 'edits' => [
        [$SW, "            self::c6Spacing(\$ledger, \$c, \$fails);", "            self::c6Spacing(\$ledger, \$c, \$fails, \$config);"],
        [$SW, "    private static function c6Spacing(array \$ledger, array &\$c, array &\$fails): void", "    private static function c6Spacing(array \$ledger, array &\$c, array &\$fails, array \$config = []): void"],
        [$SW, "\$until = max((int)\$until, \$at + (int)\$l['gap_s']);", "\$until = max((int)\$until, \$at + (int)(\$config['wa_send_gap_min_s'] ?? 60));"]],
     'sections' => 'K', 'cases' => 'KC04', 'pattern' => '/KC04 /'],
    ['label' => 'OM103 C-O2-3 and C-O2-9 judged by offers_recent_days and offers_warm_days as they read now', 'edits' => [
        [$SW, "            self::c3Recent(\$done, \$offers, \$c, \$fails);", "            self::c3Recent(\$done, \$offers, \$c, \$fails, \$config);"],
        [$SW, "    private static function c3Recent(array \$done, array \$offers, array &\$c, array &\$fails): void",
              "    private static function c3Recent(array \$done, array \$offers, array &\$c, array &\$fails, array \$config = []): void"],
        [$SW, "\$days = (int)(\$offers[(int)\$later['offer_id']]['recent_days'] ?? 0);", "\$days = (int)(\$config['offers_recent_days'] ?? 7);"],
        [$SW, "            \$warm = (int)(\$o['warm_days'] ?? 0);", "            \$warm = (int)(\$config['offers_warm_days'] ?? 30);"]],
     'sections' => 'K', 'cases' => 'KC04', 'pattern' => '/KC04 /'],
    ['label' => 'OM105 C-O2-8: human_active within 5 minutes after a send without a Team row put back as a FAIL', 'file' => $SW,
     'old' => "            self::inc(\$c, 'C-O2-8 a person within 5 minutes after a send: '",
     'new' => "            if (\$team === 0) \$fails[] = 'human_active';\n            self::inc(\$c, 'C-O2-8 a person within 5 minutes after a send: '",
     'sections' => 'K', 'cases' => 'KC05', 'pattern' => '/KC05 /'],
    ['label' => 'OM106 C-O2-9\'s lists judged as today\'s lists, every match a FAIL', 'edits' => [
        [$SW, "            \$Lm = OfferAudience::lists(\$ro, \$config, \$dataDir, \$stored['retailers'], \$stored['kyc_config'], \$p, \$m, \$mt);",
              "            \$Lm = OfferAudience::lists(\$ro, \$config, \$dataDir, \$stored['retailers'], \$stored['kyc_config'], \$p, null, \$mt);"],
        [$SW, "            if (self::onList(\$Lm, 'colleague', \$p)) self::inc(\$c, 'C-O2-9 on the staff list today (no time recorded)');",
              "            if (self::onList(\$Lm, 'colleague', \$p)) \$fail('colleague');"]],
     'sections' => 'K', 'cases' => 'KC06', 'pattern' => '/KC06 /'],
    ['label' => 'OM108 C-O2-12 judges a reminder recorded after the offer\'s claim as a FAIL', 'file' => $SW,
     'old' => "                    self::inc(\$c, 'C-O2-12 records after an offer that day ('",
     'new' => "                    if ((string)\$p['kind'] === 'reminder') \$fails[] = 'phone_today';\n                    self::inc(\$c, 'C-O2-12 records after an offer that day ('",
     'sections' => 'K', 'cases' => 'KC07', 'pattern' => '/KC07 /'],
    ['label' => 'OM109 C-O2-10\'s other-conversation part a FAIL on today\'s routing', 'file' => $SW,
     'old' => "                    self::inc(\$c, 'C-O2-10 a later inbound on a channel routed today to another handset');",
     'new' => "                    self::inc(\$c, 'C-O2-10 a later inbound on a channel routed today to another handset'); \$fails[] = 'moved';",
     'sections' => 'K', 'cases' => 'KC08', 'pattern' => '/KC08 /'],
    ['label' => 'OM112 C-O2-12 pairs rows whatever their outcome, not_sent included', 'edits' => [
        [$SW, "                if (\$o['outcome'] === 'not_sent') continue;\n                foreach (\$others[\$k] ?? [] as \$p) {",
              "                foreach (\$others[\$k] ?? [] as \$p) {"],
        [$SW, "                    if (\$p['outcome'] === 'not_sent') {", "                    if (false) {"]],
     'sections' => 'K', 'cases' => 'KC09', 'pattern' => '/KC09 /'],
    ['label' => 'OM114 C-O2-12 judges every row with a higher id than the offer\'s claim as a FAIL, in either order', 'file' => $SW,
     'old' => "                    self::inc(\$c, 'C-O2-12 records after an offer that day ('",
     'new' => "                    \$fails[] = 'phone_today';\n                    self::inc(\$c, 'C-O2-12 records after an offer that day ('",
     'sections' => 'K', 'cases' => 'KC10', 'pattern' => '/KC10 /'],
    ['label' => 'OM115 C-O2-4 requires the recipient\'s handset for an uncertain(ledger_already_sent) row', 'file' => $SW,
     'old' => "self::inc(\$c, 'C-O2-4 already-sent rows on another handset');",
     'new' => "{ self::inc(\$c, 'C-O2-4 already-sent rows on another handset'); \$fails[] = 'ledger_handset'; }",
     'sections' => 'K', 'cases' => 'KC11', 'pattern' => '/KC11 /'],
    ['label' => 'OM116 C-O2-4 requires the ledger outcome uncertain for every uncertain row', 'edits' => [
        [$SW, "            if (\$reason === 'stale_claim' && \$state === 'uncertain') {", "            if (false) {"],
        [$SW, "                if (\$reason === 'stale_claim') { self::inc(\$c, 'C-O2-4 stale claims whose ledger row reads not_sent'); continue; }\n", '']],
     'sections' => 'K', 'cases' => 'KC11', 'pattern' => '/KC11 /'],
    ['label' => 'OM121 C-O2-7 reads FAIL on any claimed row older than 15 minutes, by its age alone', 'file' => $SW,
     'old' => "            if (\$latest > \$at + self::STALE_S + self::RUN_S) {", 'new' => "            if (true) {",
     'sections' => 'K', 'cases' => 'KC12', 'pattern' => '/KC12 /'],
    ['label' => 'OM123 C-O2-4 requires sent for every sent row, a step-9 settle that did not land included', 'file' => $SW,
     'old' => "                self::inc(\$c, 'C-O2-4 settle_not_landed'); continue;", 'new' => "                self::inc(\$c, 'C-O2-4 settle_not_landed'); \$fails[] = 'ledger_mismatch'; continue;",
     'sections' => 'K', 'cases' => 'KC13', 'pattern' => '/KC13 /'],
    ['label' => 'OM-KC1 C-O2-4\'s orphan rule a FAIL before any later claim on the handset', 'file' => $SW,
     'old' => "            if (\$later) { self::inc(\$c, 'C-O2-4 ledger_orphan'); \$fails[] = 'ledger_orphan'; }",
     'new' => "            if (\$later || \$now - \$at >= self::ORPHAN_S) { self::inc(\$c, 'C-O2-4 ledger_orphan'); \$fails[] = 'ledger_orphan'; }",
     'sections' => 'K', 'cases' => 'KC(11|14)', 'pattern' => '/KC14 /'],
    ['label' => 'OM-KC2 C-O2-9 and C-O2-3 group erased rows (NULL phones) as one phone', 'file' => $SW,
     'old' => "            if (\$r['phone'] === null || (string)\$r['phone'] === '') { self::inc(\$c, 'C-O2-3 erased rows, not grouped'); continue; }",
     'new' => "",
     'sections' => 'K', 'cases' => 'KC15', 'pattern' => '/KC15 /'],
    ['label' => 'OM-KC3 C-O2-9\'s seen-mark clause bounded after judged_at\'s second (a mark in that second not judged)', 'file' => $SW,
     'old' => "                if ((string)\$x['seen_at'] <= \$m) { \$fail('unread_inbound'); break; }",
     'new' => "                if ((string)\$x['seen_at'] < \$m) { \$fail('unread_inbound'); break; }",
     'sections' => 'K', 'cases' => 'KC03', 'pattern' => '/KC03 /'],
    // B8-fix1 (B8-R01): C-O2-9's own reads, clause by clause (its sources read with its own SQL at judged_at, so OM91 weakens
    // only the audience's lists(); each of these weakens C-O2-9 itself and is caught by its own case)
    ['label' => 'OM-KC4 C-O2-9\'s followup_sends half removed', 'file' => $SW,
     'old' => "{ \$fail('followup_recent'); break; }", 'new' => "{ break; }",
     'sections' => 'K', 'cases' => 'KC21', 'pattern' => '/KC21 /'],
    ['label' => 'OM-KC5 C-O2-9\'s ledger half removed (followup_recent from followup_sends alone)', 'file' => $SW,
     'old' => "if (\$n > 0) \$fail('followup_recent');", 'new' => "if (false) \$fail('followup_recent');",
     'sections' => 'K', 'cases' => 'KC20', 'pattern' => '/KC20 /'],
    ['label' => 'OM-KC8 C-O2-9\'s followup_sends half without its judged_at bound (a send stored after it judged a FAIL)', 'file' => $SW,
     'old' => "if ((string)\$x['sent_at'] <= \$m && (string)\$x['sent_at'] > \$since48) { \$fail('followup_recent'); break; }",
     'new' => "if ((string)\$x['sent_at'] > \$since48) { \$fail('followup_recent'); break; }",
     'sections' => 'K', 'cases' => 'KC21', 'pattern' => '/KC21 /'],
    ['label' => 'OM-KC9 C-O2-9\'s ledger half without its judged_at bound (a claim after it judged a FAIL)', 'file' => $SW,
     'old' => "AND claimed_at <= ? AND claimed_at > ?\", [\$p, \$m, \$since48]);", 'new' => "AND claimed_at > ?\", [\$p, \$since48]);",
     'sections' => 'K', 'cases' => 'KC20', 'pattern' => '/KC20 /'],
    ['label' => 'OM-KC6 C-O2-9\'s opt-out clause removed', 'file' => $SW,
     'old' => "if (\$live) { \$fail('opted_out'); break; }", 'new' => "if (\$live) { break; }",
     'sections' => 'K', 'cases' => 'KC22', 'pattern' => '/KC22 /'],
    ['label' => 'OM-KC7 C-O2-9\'s person rule removed', 'file' => $SW,
     'old' => "\$m) !== null) \$fail('person_handling');", 'new' => "\$m) !== null && false) \$fail('person_handling');",
     'sections' => 'K', 'cases' => 'KC23', 'pattern' => '/KC23 /'],
]);
// B8-fix1 (B8-R04): the copies of K's planted --check cases judged again over the real flows (KC25 … KC30), taken from
// the entries above, so the real run and the planted control each catch them
$M = array_merge($M, [
    $wo_take('OM115 ', 'K', 'KC25'),
    $wo_take('OM116 ', 'K', 'KC25'),
    $wo_take('OM121 ', 'K', 'KC26'),
    $wo_take('OM123 ', 'K', 'KC27'),
    $wo_take('OM94 ', 'K', 'KC28'),
    $wo_take('OM96 ', 'K', 'KC29'),
    $wo_take('OM118 ', 'K', 'KC30'),
    // 5.18.104 (root docs/73 §H.4.16 OM76 "B, F"), B9-fix1: OM76's B half, through the tool's off and on (b_tool.php B19)
    $wo_take('OM76 ', 'B', 'B19'),
]);
unset($SW, $wo_take);

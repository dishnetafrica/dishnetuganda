<?php
declare(strict_types=1);
/**
 * Weakened copies for section L of tests/test_voice_ack.php (5.18.104, root docs/73 §H.3.24: T17's VA-ON1 copy, T18's
 * five forms, T19, T26's five forms and T27's VA-ON1 copy; B6-fix1's VA-DEADU and VA-DEADR for L06). TEST ONLY. Each
 * judges a past act by the state as it reads now, or by the bare pair table, and the world-moved case its section names
 * reads FAIL; L06's two read PASS over a dead event that recorded nothing.
 */
$VS = 'lib/VoiceSteps.php';
$lim = '(VoiceAckLimits::of($config) ?? VoiceAckLimits::DEFAULTS)';
$M = array_merge($M, [
    ['label' => 'T18a VA-ON1 judging the period by the setting as it reads now', 'file' => $VS,
     'old' => "                \$period = (int)\$d['period_hours'];",
     'new' => "                \$period = (int){$lim}['period_hours'];",
     'sections' => 'L', 'cases' => 'L01', 'pattern' => '/L01 VA-ON1 reads PASS/'],
    ['label' => 'T18b VA-ON1 judging the window by the setting as it reads now', 'file' => $VS,
     'old' => "                if (\$l['win_from'] === null || \$l['win_to'] === null || \$h < (int)\$l['win_from'] || \$h >= (int)\$l['win_to']) {",
     'new' => "                if (\$h < (int){$lim}['hour_from'] || \$h >= (int){$lim}['hour_to']) {",
     'sections' => 'L', 'cases' => 'L01', 'pattern' => '/L01 VA-ON1 reads PASS/'],
    ['label' => 'T18c VA-ON1 judging the ceiling by the setting as it reads now', 'file' => $VS,
     'old' => "                if (\$l['cap_day'] === null || \$before >= (int)\$l['cap_day']) {",
     'new' => "                if (\$before >= (int){$lim}['daily_max']) {",
     'sections' => 'L', 'cases' => 'L01', 'pattern' => '/L01 VA-ON1 reads PASS/'],
    ['label' => 'T18d VA-ON1 judging the gap by the setting as it reads now', 'file' => $VS,
     'old' => "\$at < (int)\$prev + (int)\$d['gap_s']) {",
     'new' => "\$at < (int)\$prev + (int){$lim}['gap_s']) {",
     'sections' => 'L', 'cases' => 'L01', 'pattern' => '/L01 VA-ON1 reads PASS/'],
    ['label' => 'T18e VA-ON1 judging the persona flag against the date now set', 'file' => $VS,
     'old' => "strcmp((string)\$d['local_day'], \$pc) < 0) self::inc(\$c, 'persona_before_date');",
     'new' => "strcmp((string)\$d['local_day'], \$pc) < 0) { self::inc(\$c, 'persona_before_date'); \$fail[] = 'persona_before_date'; }",
     'sections' => 'L', 'cases' => 'L01', 'pattern' => '/L01 VA-ON1 reads PASS/'],
    ['label' => 'T19 VA-ON1\'s echo clause in its retired form: human_active within 5 min after an acknowledgement', 'file' => $VS,
     'old' => "                // Persona sends on a day before the date now set: a count (the decision recorded its flag, not the date).\n",
     'new' => "                \$hx = (int)self::one(\$ro, \"SELECT COUNT(*) FROM wa_conversations WHERE id = ? AND state = 'human_active' AND last_human_reply_at > ? AND last_human_reply_at <= ?\",\n"
            . "                                     [(int)\$d['conversation_id'], UtcClock::stamp(\$at), UtcClock::stamp(\$at + 300)]);\n"
            . "                if (\$hx > 0) { self::inc(\$c, 'echo_as_person'); \$fail[] = 'echo_as_person'; }\n"
            . "                // Persona sends on a day before the date now set: a count (the decision recorded its flag, not the date).\n",
     'sections' => 'L', 'cases' => 'L02', 'pattern' => '/L02 PASS, one Inbox reply/'],
    ['label' => 'T26a every other skip with a ledger row at its ref read FAIL', 'file' => $VS,
     'old' => "                elseif (\$lo === 'in_flight' || \$swept) \$pair = 'claim_before_decision';\n", 'new' => '',
     'sections' => 'L', 'cases' => 'L03', 'pattern' => '/L03 run at once/'],
    ['label' => 'T26b a claimed decision allowed only beside an in-flight or swept row', 'file' => $VS,
     'old' => "                    elseif (\$ownSettled) \$pair = \$age < self::IN_PROGRESS_S ? 'in_progress' : 'bookkeeping_failed';\n", 'new' => '',
     'sections' => 'L', 'cases' => 'L03', 'pattern' => '/L03 run at once/'],
    ['label' => 'T26c a sent decision allowed only beside a sent row (no late settle)', 'file' => $VS,
     'old' => "                elseif (\$swept) \$pair = 'late_settle';\n            } elseif (\$out === 'uncertain') {",
     'new' => "            } elseif (\$out === 'uncertain') {",
     'sections' => 'L', 'cases' => 'L03', 'pattern' => '/L03 run at once/'],
    ['label' => 'T26d the 15-min condition applied to a claimed decision beside a row P5 settled', 'file' => $VS,
     'old' => "elseif (\$ownSettled) \$pair = \$age < self::IN_PROGRESS_S ? 'in_progress' : 'bookkeeping_failed';",
     'new' => "elseif (\$ownSettled) \$pair = \$age < self::IN_PROGRESS_S ? 'fail' : 'bookkeeping_failed';",
     'sections' => 'L', 'cases' => 'L03', 'pattern' => '/L03 run at once/'],
    ['label' => 'T26e a decision P5 settled allowed only beside a settled row (no settle_not_landed)', 'file' => $VS,
     'old' => "\$pair = 'ok';\n                elseif (\$lo === 'in_flight') \$pair = 'settle_not_landed';\n                elseif (\$swept) \$pair = 'late_settle';\n            } elseif (\$out === 'uncertain') {",
     'new' => "\$pair = 'ok';\n                elseif (\$swept) \$pair = 'late_settle';\n            } elseif (\$out === 'uncertain') {",
     'sections' => 'L', 'cases' => 'L03', 'pattern' => '/L03 run at once/'],
    ['label' => 'T27a VA-ON1\'s Coverage in its retired form: an event with no decision allowed only while pending under 15 min', 'edits' => [
        [$VS, "\"SELECT id, payload, status, attempts, max_attempts FROM events", "\"SELECT id, payload, status, attempts, max_attempts, created_at FROM events"],
        [$VS, "            if (!\$decided) { self::inc(\$c, \$aiOff ? 'undecided ai_enabled_off' : 'undecided ai_enabled_on'); continue; }",
              "            if (!\$decided && \$e['status'] === 'pending' && \$now - UtcClock::parse((string)\$e['created_at']) < 900) continue;"]],
     'sections' => 'L', 'cases' => 'L04', 'pattern' => '/L04 seven days on/'],
    // B6-fix1 (B6-R02): a dead acknowledgement event with no decision is a FAIL of Coverage (D 5405), never a count; and
    // the option the review offered — onDead recording a fixed skipped decision — would hide that fault. Both caught by L06.
    ['label' => 'VA-DEADU VA-ON1 counting a dead event undecided (only done decided)', 'file' => $VS,
     'old' => "            \$decided = \$e['status'] === 'done' || \$e['status'] === 'dead'\n",
     'new' => "            \$decided = \$e['status'] === 'done'\n",
     'sections' => 'L', 'cases' => 'L06', 'pattern' => '/L06 VA-ON1 reads FAIL coverage/'],
    ['label' => 'VA-DEADR onDead recording a fixed skipped decision for a dead acknowledgement event', 'file' => 'workers/AiReplyWorker.php',
     'old' => "        if ((\$event['event_type'] ?? '') === 'ai.voice_ack') return;\n        \$p       = \$event['_payload'] ?? [];",
     'new' => "        if ((\$event['event_type'] ?? '') === 'ai.voice_ack') {\n"
            . "            \$vp = is_array(\$event['_payload'] ?? null) ? \$event['_payload'] : [];\n"
            . "            \$this->pdo->prepare(\"INSERT OR IGNORE INTO wa_voice_acks (wa_message_id, conversation_id, channel, handset, outcome, reason, local_day, created_at, updated_at)\n"
            . "                                  VALUES (?, ?, ?, ?, 'skipped', 'dead', date('now', '+3 hours'), datetime('now'), datetime('now'))\")\n"
            . "                      ->execute([(string)(\$vp['wa_message_id'] ?? ''), (int)(\$event['entity_id'] ?? 0), (string)(\$vp['channel'] ?? ''), 'i:dead']);\n"
            . "            return;\n"
            . "        }\n        \$p       = \$event['_payload'] ?? [];",
     'sections' => 'L', 'cases' => 'L06', 'pattern' => '/L06 (onDead recorded no decision|VA-ON1 reads FAIL coverage)/'],
    ['label' => 'T17a VA-ON1\'s Alerts judged from since, the event bound dropped', 'file' => $VS,
     'old' => "                if (UtcClock::parse((string)\$d['created_at']) >= \$bound) {",
     'new' => "                if (UtcClock::parse((string)\$d['created_at']) >= \$since) {",
     'sections' => 'L', 'cases' => 'L05', 'pattern' => '/L05 PASS/'],
]);

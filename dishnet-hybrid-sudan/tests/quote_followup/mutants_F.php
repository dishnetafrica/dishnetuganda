<?php
declare(strict_types=1);
/**
 * Weakened copies for section F of tests/test_quote_followup.php (5.18.104, root docs/73 §H.2.14: HT1-HT13, HT15,
 * HT18, HT19 — the forms whose guard this stage builds). Appended to $M by the runner. TEST ONLY.
 *
 * Not here, and why: HT14 (P5's since passed as received_at) is the caller's, lib/VoiceAck.php (Part V's A32); HT16-HT18's
 * bulk form and HT20 are mutants_OH.php's. Stage B2a adds the tracker's: HT7, QM3, QM6, QM9, QM16, QM18, QM19, QM22,
 * QM46, and two of its own (a stop leaving its follow-up open; a reply bounded by its device stamp alone); its fix round
 * 1 adds registered's and accepted's (F-RG1-3, F-AC1-2) and the Kampala stamps of the payment stop (F-KS1). QM52 (marks
 * ignored in preSendCheck) and QM23-24 are lib/QuoteChase.php's, stage B2b.
 */
$H = 'lib/HumanTakeover.php';

// HT10: one anchored edit — the region from step 7 to the end of W1, replaced by the same region with W1 first.
$wh = (string)file_get_contents($ctx->root . '/evo_webhook.php');
$r0 = strpos($wh, '    // ── 7. Idempotency');
$w1 = strpos($wh, '        // 5.18.104 (root docs/73 §H.2.7.3, R-07)');
$w9 = strpos($wh, "        if (\$custPhone !== '' && \$ownText !== '') {", (int)$w1);
$ht10old = ($r0 !== false && $w1 !== false && $w9 !== false) ? substr($wh, $r0, $w9 - $r0) : '§ the region was not found §';
$ht10new = "    if (\$fromMe) {   // HT10's copy: W1 before the idempotency claim\n"
         . "        \$cpX = EvolutionApiService::phoneFromJid(\$remoteJid);\n"
         . "        if (\$cpX !== '' && evoExtractText(\$msg) === '' && (string)(\$config['wa_send_ledger'] ?? '') === 'on') {\n"
         . "            require_once __DIR__ . '/lib/WaSeenMarks.php';\n"
         . "            WaSeenMarks::note(\$pdo, \$config, \$dataDir, 'out', \$cpX, \$channel, \$instance, \$messageId, \$msg);\n"
         . "        }\n    }\n"
         . (($w1 !== false && $w9 !== false && $r0 !== false) ? substr($wh, $r0, $w1 - $r0) : '');

$M = array_merge($M, [
    ['label' => 'HT1 humanRepliedSince\'s reading (the engine\'s own DishNet rows counted as a colleague)', 'file' => $H,
     'old' => "AND m.agent_name = 'Team' AND m.sent_at > :since{\$ub}{\$notOurs}\", \$args)) {",
     'new' => "AND COALESCE(m.agent_name, '') NOT IN ('', 'DishNet AI', 'DishNet Plugin', 'WhatsApp auto-reply') AND m.sent_at > :since{\$ub}{\$notOurs}\", \$args)) {",
     'sections' => 'F', 'cases' => 'F02', 'pattern' => '/F02 /'],
    ['label' => 'HT2 the state-only rule (a sticky human_active reads as a person)', 'file' => $H,
     'old' => "            return 'escalated';\n        }\n        return null;\n    }",
     'new' => "            return 'escalated';\n        }\n        if (\$hit(\"SELECT 1 FROM wa_conversations c WHERE c.id IN ({\$in}) AND c.state = 'human_active'\", [])) return 'human_active';\n        return null;\n    }",
     'sections' => 'F', 'cases' => 'F03', 'pattern' => '/F03 /'],
    ['label' => 'HT3 the escalation not time-bounded', 'file' => $H,
     'old' => "AND e.entity_id = c.id AND e.created_at > :since{\$eub})\", \$args)) {",
     'new' => "AND e.entity_id = c.id AND (e.created_at > :since OR e.created_at <= :since){\$eub})\", \$args)) {",
     'sections' => 'F', 'cases' => 'F03', 'pattern' => '/F03 /'],
    ['label' => 'HT4 the ledger-id exclusion removed (our echo stored as Team read as a person)', 'file' => $H,
     'old' => "        if (!\$hasLedger) return '';\n        return ' AND (m.wa_message_id IS NULL OR",
     'new' => "        return '';\n        return ' AND (m.wa_message_id IS NULL OR",
     'sections' => 'F', 'cases' => 'F04', 'pattern' => '/F04 /'],
    ['label' => 'HT5 no local-stamp conversion (the Inbox\'s Kampala-dated rows compared as UTC)', 'file' => $H,
     'old' => "return (new \\DateTime('@' . UtcClock::parse(\$utcStamp)))->setTimezone(new \\DateTimeZone(self::TZ))->format('Y-m-d H:i:s');",
     'new' => 'return $utcStamp;',
     'sections' => 'F', 'cases' => 'F05', 'pattern' => '/F05 /'],
    ['label' => 'HT6 handset marks ignored', 'file' => $H,
     'old' => "        if (\$hasMarks) {\n            \$st = \$pdo->query(\"SELECT DISTINCT channel", 'new' => "        if (false) {\n            \$st = \$pdo->query(\"SELECT DISTINCT channel",
     'sections' => 'F', 'cases' => 'F06', 'pattern' => '/F06 /'],
    ['label' => 'HT8a marks written with the ledger off', 'file' => 'lib/WaSeenMarks.php',
     'old' => "            if ((string)(\$config['wa_send_ledger'] ?? '') !== 'on') return;\n            if (!self::uganda(\$config, \$dataDir)) return;\n            if ((\$direction",
     'new' => "            if (!self::uganda(\$config, \$dataDir)) return;\n            if ((\$direction",
     'sections' => 'F', 'cases' => 'F10', 'pattern' => '/F10 /'],
    ['label' => 'HT8b marks written on South Sudan', 'file' => 'lib/WaSeenMarks.php',
     'old' => "            if (!self::uganda(\$config, \$dataDir)) return;\n            if ((\$direction",
     'new' => "            if ((\$direction",
     'sections' => 'F', 'cases' => 'F10', 'pattern' => '/F10 /'],
    ['label' => 'HT9 a mark for a reactionMessage or protocolMessage', 'file' => 'lib/WaSeenMarks.php',
     'old' => "const MEDIA    = ['audioMessage','imageMessage','documentMessage','videoMessage','stickerMessage'];",
     'new' => "const MEDIA    = ['audioMessage','imageMessage','documentMessage','videoMessage','stickerMessage','reactionMessage','protocolMessage'];",
     'sections' => 'F', 'cases' => 'F1[01]', 'pattern' => '/F10 |W1 /'],
    ['label' => 'HT10 W1 moved before step 7 (our echoes marked)', 'file' => 'evo_webhook.php', 'old' => $ht10old, 'new' => $ht10new,
     'sections' => 'F', 'cases' => 'F11', 'pattern' => '/W1 /'],
    ['label' => 'HT11 the exclusion written as a plain NOT IN (a sender-mode Inbox reply missed)', 'file' => $H,
     'old' => "        return ' AND (m.wa_message_id IS NULL OR m.wa_message_id NOT IN'\n             . ' (SELECT a.wa_message_id FROM wa_auto_sends a WHERE a.wa_message_id IS NOT NULL))';",
     'new' => "        return ' AND m.wa_message_id NOT IN (SELECT a.wa_message_id FROM wa_auto_sends a)';",
     'sections' => 'F', 'cases' => 'F04', 'pattern' => '/F04 /'],
    ['label' => 'HT12 inbox_reply requiring agent_name = Team', 'file' => $H,
     'old' => "                   AND \" . self::notAutomation('m') . \"{\$ub}{\$notOurs}\", \$args + [':since_local' => self::kampala(\$since)])) {",
     'new' => "                   AND m.agent_name = 'Team' AND \" . self::notAutomation('m') . \"{\$ub}{\$notOurs}\", \$args + [':since_local' => self::kampala(\$since)])) {",
     'sections' => 'F', 'cases' => 'F05', 'pattern' => '/F05 /'],
    ['label' => 'HT13 the automation-row exclusion removed from marks (an AI captionless photo read as a person)', 'file' => $H,
     'old' => "        return \$sql . ' AND NOT EXISTS (SELECT 1 FROM wa_messages x WHERE x.wa_message_id = w.wa_message_id'\n             . \" AND (x.role = 'assistant' OR \" . self::isAutomation('x') . '))';",
     'new' => '        return $sql;',
     'sections' => 'F', 'cases' => 'F06', 'pattern' => '/F06 /'],
    ['label' => 'HT15 a time argument compared without UtcClock normalisation', 'file' => $H,
     'old' => 'return $t === 0 ? null : UtcClock::stamp($t);', 'new' => 'return $t === 0 ? null : $s;',
     'sections' => 'F', 'cases' => 'F07', 'pattern' => '/F07 /'],
    ['label' => 'HT18 an unreadable time argument answering null instead of unreadable_time', 'file' => $H,
     'old' => "        if (\$since === null) return 'unreadable_time';\n        return self::judge(", 'new' => "        if (\$since === null) return null;\n        return self::judge(",
     'sections' => 'F', 'cases' => 'F07', 'pattern' => '/F07 /'],
    ['label' => 'HT19 the absent-table rule removed (an 088-shaped book read as if 089 were there)', 'file' => $H,
     'old' => "return [in_array('wa_auto_sends', \$names, true), in_array('wa_seen_marks', \$names, true)];", 'new' => 'return [true, true];',
     'sections' => 'F', 'cases' => 'F08', 'pattern' => '/F08 /'],
    // ── stage B2a: the tracker's stops (lib/QuoteTracker.php; §H.2.9.7, §H.2.10.8) ─────────────────────────────────
    ['label' => 'HT7 inbound marks ignored (the tracker\'s answered, and customerStop())', 'file' => 'lib/QuoteTracker.php',
     'old' => "        if (self::hasTable(\$pdo, 'wa_seen_marks')) {", 'new' => '        if (false) {',
     'sections' => 'F', 'cases' => 'F1[38]', 'pattern' => '/F13 |F18 /'],
    ['label' => 'QM3 replies only on the chase\'s thread (the quotation\'s own conversation)', 'file' => 'lib/QuoteTracker.php',
     'old' => "                case 'answered':   \$r = self::answered(\$pdo, \$p, \$convs, \$sent); break;",
     'new' => "                case 'answered':   \$r = self::answered(\$pdo, \$p, [(int)\$row['sent_conv_id']], \$sent); break;",
     'sections' => 'F', 'cases' => 'F1[38]', 'pattern' => '/F13 |F18 /'],
    ['label' => 'QM6a an opt-out read as transactional (only scope all blocks)', 'file' => 'lib/QuoteTracker.php',
     'old' => 'WHERE active = 1 AND phone IN ({$in}) LIMIT 1', 'new' => "WHERE active = 1 AND scope = 'all' AND phone IN ({\$in}) LIMIT 1",
     'sections' => 'F', 'cases' => 'F18', 'pattern' => '/F18 /'],
    ['label' => 'QM6b an opt-out read on one channel', 'file' => 'lib/QuoteTracker.php',
     'old' => 'WHERE active = 1 AND phone IN ({$in}) LIMIT 1', 'new' => "WHERE active = 1 AND channel IN ('*', 'support') AND phone IN ({\$in}) LIMIT 1",
     'sections' => 'F', 'cases' => 'F13', 'pattern' => '/F13 /'],
    ['label' => 'QM9 expiry ignored', 'file' => 'lib/QuoteTracker.php',
     'old' => "                case 'expired':    \$r = self::expired(\$row, \$now); break;", 'new' => "                case 'expired':    \$r = null; break;",
     'sections' => 'F', 'cases' => 'F14', 'pattern' => '/F14 /'],
    ['label' => 'QM16 an escalation after the quotation does not stop it', 'file' => 'lib/QuoteTracker.php',
     'old' => "                case 'taken':      \$r = HumanTakeover::since(\$pdo, \$p, \$convs, \$sent); break;",
     'new' => "                case 'taken':      \$r = HumanTakeover::since(\$pdo, \$p, \$convs, \$sent); if (\$r === 'escalated') \$r = null; break;",
     'sections' => 'F', 'cases' => 'F13', 'pattern' => '/F13 /'],
    ['label' => 'QM18 no supersede', 'file' => 'lib/QuoteTracker.php',
     'old' => "                case 'superseded': \$r = self::superseded(\$pdo, \$row); break;", 'new' => "                case 'superseded': \$r = null; break;",
     'sections' => 'F', 'cases' => 'F1[38]', 'pattern' => '/F13 |F18 /'],
    ['label' => 'QM19 payment ignored', 'file' => 'lib/QuoteTracker.php',
     'old' => "                case 'paid':       \$r = self::paid(\$pdo, \$ctx, \$row, \$convs); break;", 'new' => "                case 'paid':       \$r = null; break;",
     'sections' => 'F', 'cases' => 'F13', 'pattern' => '/F13 /'],
    ['label' => 'QM22 STOP-only counted as answered (answered read before opted_out)', 'file' => 'lib/QuoteTracker.php',
     'old' => "const STOPS = ['opted_out', 'answered', 'taken',", 'new' => "const STOPS = ['answered', 'opted_out', 'taken',",
     'sections' => 'F', 'cases' => 'F1[35]', 'pattern' => '/F13 |F15 /'],
    ['label' => 'QM46 close reasons not mapped', 'file' => 'lib/QuoteTracker.php',
     'old' => '            foreach (self::mapClosed($pdo, $moment) as $k => $v) $c[$k] += $v;', 'new' => '',
     'sections' => 'F', 'cases' => 'F17', 'pattern' => '/F17 /'],
    // B2a fix round 1 (B2a-R03): registered, accepted, and the Kampala stamps the stops compare (reading 11)
    ['label' => 'F-RG1 the registered stop removed', 'file' => 'lib/QuoteTracker.php',
     'old' => "                case 'registered': \$r = self::registered(\$pdo, \$ctx, \$row); break;", 'new' => "                case 'registered': \$r = null; break;",
     'sections' => 'F', 'cases' => 'F(13|21)', 'pattern' => '/F13 |F21 /'],
    ['label' => 'F-RG2 registered applied to a KYC-made quotation (the made_by kyc exemption removed)', 'file' => 'lib/QuoteTracker.php',
     'old' => "        if ((string)\$row['made_by'] === 'kyc') return null;\n", 'new' => '',
     'sections' => 'F', 'cases' => 'F21', 'pattern' => '/F21 /'],
    ['label' => 'F-RG3 registered compared against the UTC sent_at, not its Kampala stamp', 'file' => 'lib/QuoteTracker.php',
     'old' => "        \$local = self::kampalaStamp((string)\$row['sent_at']);", 'new' => "        \$local = (string)\$row['sent_at'];",
     'sections' => 'F', 'cases' => 'F21', 'pattern' => '/F21 /'],
    ['label' => 'F-AC1 the accepted stop removed', 'file' => 'lib/QuoteTracker.php',
     'old' => "                case 'accepted':   \$r = self::approved(\$pdo, \$convs, \$row); break;", 'new' => "                case 'accepted':   \$r = null; break;",
     'sections' => 'F', 'cases' => 'F13', 'pattern' => '/F13 /'],
    ['label' => 'F-AC2 accepted without its word boundary (#Q-80521 read as #Q-8052)', 'file' => 'lib/QuoteTracker.php',
     'old' => "'(?![A-Za-z0-9])/u';", 'new' => "'/u';",
     'sections' => 'F', 'cases' => 'F13', 'pattern' => '/F13 /'],
    ['label' => 'F-KS1 the cashbook, collection and Cash KYC stamps compared against the UTC sent_at, not kampalaStamp()', 'file' => 'lib/QuoteTracker.php',
     'old' => "        \$local = self::kampalaStamp(\$sent);", 'new' => "        \$local = \$sent;",
     'sections' => 'F', 'cases' => 'F13', 'pattern' => '/F13 /'],
    ['label' => 'F-CL a stop leaving its linked follow-up open', 'file' => 'lib/QuoteTracker.php',
     'old' => 'if ($fid > 0 && isset(self::STATUS_TO_CLOSE[$status])) {', 'new' => 'if (false) {',
     'sections' => 'F', 'cases' => 'F16', 'pattern' => '/F16 /'],
    ['label' => 'F-DEV a reply bounded by its device sent_at alone (one dated before the send, stored after, missed)', 'file' => 'lib/QuoteTracker.php',
     'old' => "                                  AND direction = 'in' AND (sent_at > ? OR created_at > ?) LIMIT 1\");\n            \$st->execute([\$sent, \$sent]);",
     'new' => "                                  AND direction = 'in' AND sent_at > ? LIMIT 1\");\n            \$st->execute([\$sent]);",
     'sections' => 'F', 'cases' => 'F13', 'pattern' => '/F13 /'],
]);

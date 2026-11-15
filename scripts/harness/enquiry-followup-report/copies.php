<?php
declare(strict_types=1);
/**
 * copies.php — the weakened copies weaken.php makes of scripts/enquiry-followup-report.php, each with the check that
 * must catch it. Every anchor is a nowdoc, so it is the report's text exactly, with nothing escaped.
 *
 * @return array<string, array{0: array<int, array{0: string, 1: string}>, 1: string}>  name => [[anchor, replacement]…], check
 */
$readWrite = [<<<'A'
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,

A, ''];
$labelRule = [<<<'A'
    if (!preg_match('/^(sales|support|account|retailer)(-[0-9]{1,4})?$/', $ch)) return 'other';

A, ''];
return [
    'W1 closed follow-ups\' approved drafts counted as the real queue' => [[[<<<'A'
$apOpen = (int)$q1("SELECT COUNT(*) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'approved' AND f.closed_at IS NULL$ENQ");
A, <<<'A'
$apOpen = (int)$q1("SELECT COUNT(*) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE d.status = 'approved'$ENQ");
A]], 'R2 prints: approved and waiting to send: 3'],
    'W2 the front ignores drafts waiting for a person' => [[[<<<'A'
    if ($x['pend_at'] !== null) { $frontPend++; $pendAts[] = (string)$x['pend_at']; }
A, <<<'A'
    if (false) { $frontPend++; $pendAts[] = (string)$x['pend_at']; }
A]], "R2 prints: open 9, due now 5 · the run's front: partly blocked"],
    'W3 a channel printed as stored' => [[$labelRule], 'R2: no PLANT string'],
    'W4 a word printed as stored' => [[[<<<'A'
return preg_match('/^[a-z][a-z0-9_.:\/-]{0,40}$/', $s) ? $s : 'other';
A, 'return $s;']], 'R2: no PLANT string'],
    'W5 the copy opened for writing, and its schema written' => [[$readWrite, [<<<'A'
    $db->exec('PRAGMA query_only = 1');
A, <<<'A'
    $db->exec('CREATE TABLE zz_w5 (x)');
A]], 'R2: the copy is byte for byte unchanged'],
    'W6 due now counts every open follow-up with a due time' => [[[<<<'A'
$dueNow = (int)$q1("SELECT COUNT(*) FROM followups WHERE closed_at IS NULL AND due_at IS NOT NULL AND due_at <= ?$ENQu", [$now]);
A, <<<'A'
$dueNow = (int)$q1("SELECT COUNT(*) FROM followups WHERE closed_at IS NULL AND due_at IS NOT NULL AND ? <> ''$ENQu", [$now]);
A]], 'R2 prints: open 9 · due now 5 · not yet due 4'],
    'W7 the settings files read in the wrong order (the code root wins)' => [[[<<<'A'
$cfg = PluginConfig::read($opt['root'], $opt['data']);
A, <<<'A'
$cfg = array_merge((array)json_decode((string)file_get_contents($opt['data'] . '/kyc_config.json'), true), (array)json_decode((string)file_get_contents($opt['root'] . '/data/config.json'), true));
A]], 'R2 prints: followup_daily_cap 3 — once'],
    'W8 quote follow-ups counted as enquiries' => [[[<<<'A'
$kindCol = in_array('kind', $cols('followups'), true);
A, '$kindCol = false;']], 'R2 prints: open 9 · due now 5 · not yet due 4'],
    'W9 the front ignores followup_run_limit' => [[[<<<'A'
ORDER BY f.due_at LIMIT ?", [$now, $runLimit]);
A, <<<'A'
ORDER BY f.due_at LIMIT 50", [$now]);
A]], 'R2 prints: the first 2 due (followup_run_limit 2)'],
    'W10 the front read newest first' => [[[<<<'A'
ORDER BY f.due_at LIMIT ?", [$now, $runLimit]);
A, <<<'A'
ORDER BY f.due_at DESC LIMIT ?", [$now, $runLimit]);
A]], "R1 prints: open 9, due now 5 · the run's front: JAMMED"],
    'W11 a channel printed as stored, and the last mask gone' => [[$labelRule, [<<<'A'
echo preg_replace('/[0-9]{9,}/', '<digits>', $text);
A, 'echo $text;']], 'R2: no run of nine or more digits'],
    'W12 a row rewritten with its own value (the bytes may not change)' => [[$readWrite, [<<<'A'
    $db->exec('PRAGMA query_only = 1');
A, <<<'A'
    $db->exec("UPDATE master_schedule SET data = data WHERE id = 0");
A]], 'R2 prints: changes on the copy: 0'],
    'W13 a write, with the change count hidden' => [[$readWrite, [<<<'A'
    $db->exec('PRAGMA query_only = 1');
A, <<<'A'
    $db->exec('CREATE TABLE zz_w13 (x)');
A], [<<<'A'
$changes = (int)$db->query('SELECT total_changes()')->fetchColumn();
A, '$changes = 0;']], 'RO: a copy the reader cannot write reads exactly as R2'],
    'W14 followup_not_before printed as stored' => [[[<<<'A'
($floorIsDate ? $notBefore : ($notBefore === '' ? 'unset' : 'set, not a plain date (the scan compares it as text)'))
A, '$notBefore']], 'R3: no PLANT string'],
    'W15 the gate\'s answer printed as stored' => [[[<<<'A'
    $head = preg_match('/^[a-z_]+( at [a-z0-9_]+)?$/', $head) ? $head : 'other';
A, '    $head = (string)$head;']], 'R2: no PLANT string'],
    'W16 the screen\'s cards on closed follow-ups not counted' => [[[<<<'A'
if ((int)$x['closed'] === 1) $scrClosed++;
A, '']], 'R2 prints: the Follow-ups screen: 49 of its 50'],
    'W17 the front\'s drafts beyond the screen\'s 50 not looked for' => [[[<<<'A'
>= 50) $hidden++;
A, '>= 5000) $hidden++;']], "R2 prints: the Follow-ups screen: 49 of its 50 cards are on a closed follow-up · the front's waiting drafts not on the screen: 1"],
    'W18 JAMMED when fewer are due than the run takes' => [[[<<<'A'
elseif ($frontPend === $frontN && !$unlimited && $frontN >= $runLimit)
A, 'elseif ($frontPend === $frontN)']], 'R4 prints: open 9, due now 1'],
    'W19 AI failures not told apart from declines' => [[[<<<'A'
$aiClass = "CASE WHEN d.reason LIKE 'the assistant could not be reached:%' THEN 'ai_unreachable'
A, <<<'A'
$aiClass = "CASE WHEN 0 THEN 'ai_unreachable'
A]], "R2 prints: the assistant's verdicts"],
    'W20 closures after an AI failure not counted' => [[[<<<'A'
$niFail = (int)$q1(
A, '$niFail = 0 * (int)$q1(']], 'R2 prints: of those closed not_interested, after an AI failure'],
    'W21 the salesperson-number hold ignored' => [[[<<<'A'
$heldOwned = $registry && !$ownedAllowed && $has('wa_channels');
A, '$heldOwned = false;']], 'R1 prints: the follow-up jobs run · automatic approval on · salesperson numbers HELD'],
    'W22 followup_enabled read as a word, not as the jobs read it' => [[[<<<'A'
$jobsRun = !empty($cfg['followup_enabled'] ?? null);
A, <<<'A'
$jobsRun = in_array(strtolower(trim((string)($cfg['followup_enabled'] ?? ''))), ['1', 'true', 'on', 'yes'], true);
A]], 'R3 prints: the follow-up jobs run · automatic approval off'],
    'W23 a cap of 0 read as a cap' => [[[<<<'A'
$full = 0; if ($cap > 0) foreach
A, '$full = 0; foreach'], [<<<'A'
' · numbers at the cap: ' . ($cap > 0 ? $full : 'none (no cap)')
A, <<<'A'
' · numbers at the cap: ' . $full
A]], 'R3 prints: today (UTC) per number, against followup_daily_cap 0'],
    'W24 master\'s budget read from the files, not the book\'s row' => [[[<<<'A'
$budget = (int)(($book ?? [])['master_budget_seconds'] ?? 300);
A, <<<'A'
$budget = (int)($cfg['master_budget_seconds'] ?? 300);
A]], 'R2 prints: master: the newest job start'],
    'W25 C read without the scan\'s rule (a conversation closed this quiet spell left out)' => [[[<<<'A'
AND NOT EXISTS (SELECT 1 FROM followups f WHERE f.conversation_id = c.id AND f.closed_at IS NULL)";
A, <<<'A'
AND NOT EXISTS (SELECT 1 FROM followups f WHERE f.conversation_id = c.id AND (f.closed_at IS NULL OR f.opened_at >= c.last_customer_at))";
A]], 'R2 prints: 14 in all'],
    'W26 the ledger\'s handset printed' => [[[<<<'A'
$l = isset($handsetChan[$h]) ? implode('+', array_map($label, $handsetChan[$h])) : 'a department number (or a moved one) #' . (++$k);
A, '$l = $h;']], 'R2: no PLANT string'],
    'W27 the ledger\'s not_sent rows counted as used' => [[[<<<'A'
AND (outcome IS NULL OR outcome <> 'not_sent') GROUP BY handset ORDER BY handset", [$klaDay]);
A, <<<'A'
GROUP BY handset ORDER BY handset", [$klaDay]);
A]], 'R2 prints: the ledger today'],
    'W28 the sending window ignored' => [[[<<<'A'
$inWindow = $klaDow !== 0 && $klaH >= 8 && $klaH < 20;
A, '$inWindow = true;']], 'R2 prints: approved and waiting to send: 3'],
    'W29 failed sends since approval not counted' => [[[<<<'A'
AND e.event = 'failed' AND e.created_at >= d.decided_at)";
A, <<<'A'
AND e.event = 'never' AND e.created_at >= d.decided_at)";
A]], 'R2 prints: approved and waiting to send: 3 (failed at least once: 1)'],
    'W30 an odd verdict printed as stored' => [[[<<<'A'
    $w = in_array((string)$x['verdict'], ['SEND', 'DO_NOT_SEND', 'WAIT', 'ESCALATE_TO_HUMAN'], true) ? (string)$x['verdict'] : 'other';
A, '    $w = (string)$x[\'verdict\'];']], 'R2: no PLANT string'],
    'W31 a run limit of 0 not told apart' => [[[<<<'A'
if ($runLimit === 0) $jam = 'NOTHING JUDGED — followup_run_limit is 0, so the run takes no follow-up';
elseif ($frontN === 0)
A, 'if ($frontN === 0)']], 'R5 prints: open 9, due now 5'],
    'W32 an empty setting read as unset' => [[[<<<'A'
    return (is_string($cfg[$k]) && trim($cfg[$k]) === '') ? ' (set but empty: the job reads 0)' : '';
A, "    return '';"]], 'R5 prints: followup_scan_limit 0 (set but empty'],
    'W33 the ledger\'s hourly cap not looked at' => [[[<<<'A'
        if (($hour[$h] ?? 0) >= $ledgerHourCap) $hourFull++;
A, '']], 'R1 prints: the ledger today'],
    'W34 wa_send_ledger read as any true word, not exactly on' => [[[<<<'A'
$ledgerOn = (string)($cfg['wa_send_ledger'] ?? '') === 'on';
A, "\$ledgerOn = \$bool('wa_send_ledger');"]], 'R5 prints: the send ledger: off'],
    'W35 followup_not_before applied only when it reads as a date' => [[[<<<'A'
$floorEff = ($notBefore !== '' && $notBefore > $ageFloor) ? $notBefore : null;
A, <<<'A'
$floorEff = (preg_match('/^\d{4}-/', $notBefore) && $notBefore > $ageFloor) ? $notBefore : null;
A]], 'R3 prints: 14 in all'],
    'W36 the sender\'s closes counted twice (not in its order)' => [[[<<<'A'
WHEN c.id IS NOT NULL AND NOT ($ha) AND c.last_customer_at
A, 'WHEN c.id IS NOT NULL AND c.last_customer_at']], 'R2 prints: in the real queue, the sender will CLOSE'],
    'W37 a paused number\'s drafts given sender places' => [[[<<<'A'
$notPaused = $paused !== [] ? ' AND f.channel NOT IN (' . implode(',', $paused) . ')' : '';
A, "\$notPaused = '';"]], "R2 prints: the real queue, failed at least once since approval"],
    'W38 the ledger\'s daily cap not read from the setting' => [[[<<<'A'
        $c = $ledgerDayCap;
A, '        $c = 60;']], 'R1 prints: the ledger today'],
    'W39 reopens not counted' => [[[<<<'A'
AND p.id < f.id AND p.last_customer_at = f.last_customer_at
A, 'AND p.id < f.id AND 0']], 'R2 prints: reopened by the scan for the same quiet spell'],
    'W40 a conversation closed this quiet spell counted as never followed up' => [[[<<<'A'
                 WHEN $prev IS NOT NULL THEN 'reopened_each_scan'

A, '']], 'R2 prints: 14 in all'],
    'W41 an opt-out not looked for' => [[[<<<'A'
                 WHEN $optSql THEN 'opted_out'

A, '']], 'R2 prints: 14 in all'],
    'W42 rows the run closes first counted as blocking' => [[[<<<'A'
    if ((int)$x['conv_ok'] !== 1 || ($heldOwned && ($chanOwner[(string)$x['channel']] ?? '') === 'staff')) { $frontClosing++; continue; }
A, '']], 'R3 prints: the first 5 due'],
    'W43 a run that judged nothing not reported' => [[[<<<'A'
if ($jobsRun && $runLimit !== 0 && $stale > 0 && $evRun === 0) {
A, 'if (false) {']], 'R6 prints: THE RUN JUDGED NOTHING'],
    'W44 a negative run limit read as a limit' => [[[<<<'A'
$unlimited = $runLimit < 0;
A, '$unlimited = false;']], 'R3 prints: followup_daily_cap 0 — no cap · followup_run_limit -1'],
    'W45 a quotation being chased not looked for' => [[[<<<'A'
                 WHEN $quoteSql THEN 'quotation_chased'

A, '']], 'R2 prints: 14 in all'],
    'W46 the opt-out looked for on the earlier follow-up only (not the conversation)' => [[[<<<'A'
                 WHEN $optSql THEN 'opted_out'
A, "                 WHEN \$prev IS NOT NULL AND \$optSql THEN 'opted_out'"]], 'R2 prints: 14 in all'],
    'W47 the evidence window not bounded by the run\'s last start (any answer ever counts)' => [[[<<<'A'
                        AND e.created_at >= ? AND e.created_at <= ?", [$runAt, $now]);
A, <<<'A'
                        AND ? <> '' AND e.created_at <= ?", [$runAt, $now]);
A]], 'R14 prints: THE RUN JUDGED NOTHING'],
    'W48 a jam reported as a silent run' => [[[<<<'A'
if ($jobsRun && $runLimit !== 0 && $stale > 0 && $evRun === 0) {
A, 'if ($jobsRun && $dueNow > 0 && $evRun === 0) {']], 'R4 does not print "THE RUN JUDGED NOTHING"'],
    'W49 the ledger\'s own window not read' => [[[<<<'A'
$ledgerFrom = $lset('wa_send_hour_from', 8);
$ledgerTo = $lset('wa_send_hour_to', 20);
A, "\$ledgerFrom = 8;\n\$ledgerTo = 20;"]], 'R7 prints: now Mon 18:00 Kampala'],
    'W50 the ledger\'s own window not applied' => [[[<<<'A'
if ($ledgerMode === 'claim') $inWindow = $inWindow && $inLedgerWindow;
A, '']], 'R7 prints: now Mon 18:00 Kampala'],
    'W51 the reopened ones\' close reasons printed as stored' => [[[<<<'A'
    $m = []; foreach ($r as $x) { $w = $word($x['cr']); $m[$w] = ($m[$w] ?? 0) + (int)$x['n']; }
    $say('  followed up and closed during this quiet spell
A, <<<'A'
    $m = []; foreach ($r as $x) { $w = (string)$x['cr']; $m[$w] = ($m[$w] ?? 0) + (int)$x['n']; }
    $say('  followed up and closed during this quiet spell
A]], 'R2: no PLANT string'],
    'W52 the earlier close reasons of reopens printed as stored' => [[[<<<'A'
$m = []; $reopen7 = 0; foreach ($r as $x) { $w = $word($x['cr']);
A, '$m = []; $reopen7 = 0; foreach ($r as $x) { $w = (string)$x[\'cr\'];']], 'R2: no PLANT string'],
    'W53 a colleague\'s row at the front counted as blocking' => [[[<<<'A'
    if ((int)$x['conv_ok'] !== 1 ||
A, "    if ((int)\$x['conv_ok'] === 0 ||"]], 'R3 prints: the first 5 due'],
    'W54 a floor in the past (or not a date) said to block everything' => [[[<<<'A'
$floorBlocksAll = $floorEff !== null && $floorEff > gmdate('Y-m-d H:i:s', $nowTs - 86400);
A, '$floorBlocksAll = $floorEff !== null || ($notBefore !== \'\' && !$floorIsDate);']], 'R1 prints: followup_scan_limit 25 (default)'],
    'W55 a person\'s decision after the run\'s last start not looked at' => [[[<<<'A'
            && ($x['dec_at'] === null || (string)$x['dec_at'] < $runAt)) $stalePos[] = $i + 1;
A, '            ) $stalePos[] = $i + 1;']], 'R8 does not print "THE RUN JUDGED NOTHING"'],
    'W56 a row that fell due after the run\'s last start counted' => [[[<<<'A'
    elseif ($runAt !== null && (string)$x['due_at'] < $runAt && (string)$x['opened_at'] < $runAt
A, "    elseif (\$runAt !== null && (string)\$x['opened_at'] < \$runAt"]], 'R9 does not print "THE RUN JUDGED NOTHING"'],
    'W57 a row opened after the run\'s last start counted' => [[[<<<'A'
    elseif ($runAt !== null && (string)$x['due_at'] < $runAt && (string)$x['opened_at'] < $runAt
A, "    elseif (\$runAt !== null && (string)\$x['due_at'] < \$runAt"]], 'R10 does not print "THE RUN JUDGED NOTHING"'],
    'W58 a policy refusal not counted as the run having taken a row' => [[[<<<'A'
WHERE (e.event = 'evaluated' OR e.actor = 'policy')
A, "WHERE e.event = 'evaluated'"]], 'R11 does not print "THE RUN JUDGED NOTHING"'],
    'W59 a floor with digits run on after the day printed as stored' => [[[<<<'A'
$floorIsDate = (bool)preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $notBefore);
A, <<<'A'
$floorIsDate = (bool)preg_match('/^\d{4}-\d{2}-\d{2}[ T]?\d{0,2}:?\d{0,2}:?\d{0,2}$/', $notBefore);
A]], 'R5 prints: followup_scan_limit 0 (set but empty: the job reads 0)'],
    'W60 the scan\'s colleague test (C) trims spaces only' => [[[<<<'A'
"LOWER(TRIM(COALESCE(c.category, ''), char(32, 9, 10, 13, 11)))" : "''";
A, <<<'A'
"LOWER(TRIM(COALESCE(c.category, '')))" : "''";
A]], 'R2 prints: 14 in all'],
    'W61 the run\'s colleague test (E) trims spaces only' => [[[<<<'A'
"LOWER(TRIM(COALESCE(c.category, ''), char(32, 9, 10, 13, 11))) = 'staff'"
A, <<<'A'
"LOWER(TRIM(COALESCE(c.category, ''))) = 'staff'"
A]], 'R3 prints: the first 5 due'],
    'W62 the run\'s last start not read (every due row counted)' => [[[<<<'A'
$runAt = $runLr > 1 ? gmdate('Y-m-d H:i:s', $runLr) : null;
A, "\$runAt = '9999-12-31 23:59:59';"]], 'R8 does not print "THE RUN JUDGED NOTHING"'],
    'W63 a place freed ahead since the run\'s last start not taken from its reach' => [[[<<<'A'
$reach = $unlimited ? PHP_INT_MAX : $runLimit - $leftFront;
A, '$reach = $unlimited ? PHP_INT_MAX : $runLimit;']], 'R12 does not print "THE RUN JUDGED NOTHING"'],
    'W64 the evidence read from the last 24 h, not since the run\'s last start' => [[[<<<'A'
                        AND e.created_at >= ? AND e.created_at <= ?", [$runAt, $now]);
A, <<<'A'
                        AND e.created_at > datetime(?, '-1 day') AND e.created_at <= ?", [$now, $now]);
A]], 'R13 does not print "THE RUN JUDGED NOTHING"'],
    'W65 a follow-up sent since the run\'s last start not counted as freeing a place' => [[[<<<'A'
WHERE e.event IN ('closed', 'opted_out', 'sent', 'skipped')
A, "WHERE e.event IN ('closed', 'opted_out', 'skipped')"]], 'R12 does not print "THE RUN JUDGED NOTHING"'],
    'W66 an admin page\'s stamp taken as a real pass by the detector' => [[[<<<'A'
if ($jobsRun && $runLimit !== 0 && $stale > 0 && $evRun === 0 && $byPage($runLr)) {
A, 'if (false) {']], 'R15 does not print "THE RUN JUDGED NOTHING"'],
    'W67 any stamp within two hours of an admin page\'s run taken as that cycle\'s' => [[[<<<'A'
$lr <= $pigTs + 120;
A, '$lr <= $pigTs + 7200;']], 'R16 prints: THE RUN JUDGED NOTHING'],
    'W68 a stamp before an admin page\'s run taken as that cycle\'s' => [[[<<<'A'
$lr > 1 && $lr >= $pigTs && $lr <= $pigTs + 120;
A, '$lr > 1 && $lr <= $pigTs + 120;']], 'R17 prints: THE RUN JUDGED NOTHING'],
    'W69 section B silent about an admin page\'s stamp' => [[[<<<'A'
            . ($byPage($lr) ? ' — an admin page\'s cycle (public.php), where the job does nothing: its last real pass is not recorded' : ''));
A, "            );"]], 'R15 prints: followup_run: last start 24 h ago'],
    'W70 the admin page\'s time not read' => [[[<<<'A'
if (is_file($pigFile) && is_readable($pigFile)) {
A, 'if (false) {']], 'R15 does not print "THE RUN JUDGED NOTHING"'],
];

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
($floorIsDate ? $notBefore : ($notBefore === '' ? 'unset' : 'set (unreadable)'))
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
A]], 'R2 prints: 11 in all'],
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
A]], 'R3 prints: 11 in all'],
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

A, '']], 'R2 prints: 11 in all'],
    'W41 an opt-out not looked for' => [[[<<<'A'
                 WHEN $prev IS NOT NULL AND $optSql THEN 'opted_out'

A, '']], 'R2 prints: 11 in all'],
    'W42 rows the run closes first counted as blocking' => [[[<<<'A'
    if ((int)$x['conv_ok'] === 0 || ($heldOwned && ($chanOwner[(string)$x['channel']] ?? '') === 'staff')) { $frontClosing++; continue; }
A, '']], 'R3 prints: the first 5 due'],
    'W43 a run that judged nothing not reported' => [[[<<<'A'
if ($jobsRun && $dueNow > 0 && $ev24 === 0) {
A, 'if (false) {']], 'R4 prints: THE RUN JUDGED NOTHING'],
    'W44 a negative run limit read as a limit' => [[[<<<'A'
$unlimited = $runLimit < 0;
A, '$unlimited = false;']], 'R3 prints: followup_daily_cap 0 — no cap · followup_run_limit -1'],
];

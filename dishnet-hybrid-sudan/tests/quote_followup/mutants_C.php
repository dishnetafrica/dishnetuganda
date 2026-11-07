<?php
declare(strict_types=1);
/**
 * Weakened copies for section C of tests/test_quote_followup.php (5.18.104, root docs/73 §H.2.14: QM30, and the guards
 * C names — kycAgent()'s two-match and inactive rules and its one copy (IP-1), pending judged at the run's moment,
 * tracker_ran only from a run that reached every pending row (Q16-F04), the cursor in the rows' transaction and
 * ref_conflict recorded once (Q17-F04)). Appended to $M by the runner. TEST ONLY.
 */
$T = 'lib/QuoteTracker.php';
$E = 'lib/QuoteSendEvidence.php';
$r2 = "        if (!empty(\$f['lead_log']) && is_array(\$f['lead'] ?? null)) {\n"
    . "            \$id = self::activeId(\$retailerRows, \$f['lead']['assigned_to'] ?? 0);\n"
    . "            if (\$id !== null) return ['id' => \$id, 'source' => 'lead_owner'];\n        }\n";
$r3 = "        if (is_array(\$f['log'] ?? null)) {\n"
    . "            \$id = self::activeByName(\$retailerRows, (string)(\$f['log']['sent_by'] ?? ''));\n"
    . "            if (\$id !== null) return ['id' => \$id, 'source' => 'quotes_log'];\n        }\n";
$M = array_merge($M, [
    ['label' => 'QM30 the F4 order reversed (rule 3 before rule 2)', 'file' => $E, 'old' => $r2 . $r3, 'new' => $r3 . $r2,
     'sections' => 'C', 'cases' => 'C02', 'pattern' => '/C02 /'],
    ['label' => 'C-K2 kycAgent(): two active accounts named, the first taken', 'file' => $E,
     'old' => 'return count($hit) === 1 ? (int)array_key_first($hit) : null;', 'new' => 'return $hit !== [] ? (int)array_key_first($hit) : null;',
     'sections' => 'C', 'cases' => 'C0[13]', 'pattern' => '/C01 |C03 /'],
    ['label' => 'C-KI kycAgent(): a name matching only an inactive account counted', 'file' => $E,
     'old' => "            if (!is_array(\$r) || !self::isActive(\$r)) continue;\n            if (strtolower(trim((string)(\$r['name'] ?? ''))) === \$want",
     'new' => "            if (!is_array(\$r)) continue;\n            if (strtolower(trim((string)(\$r['name'] ?? ''))) === \$want",
     'sections' => 'C', 'cases' => 'C0[13]', 'pattern' => '/C01 |C03 /'],
    ['label' => 'C-K6 rule 6 with a rule of its own instead of kycAgent() (IP-1)', 'file' => $E,
     'old' => "            \$k = self::kycAgent(\$f['client_app'], \$retailerRows);",
     'new' => "            \$k = ['id' => self::activeId(\$retailerRows, \$f['client_app']['retailer_id'] ?? 0), 'source' => 'kyc_submitter'];",
     'sections' => 'C', 'cases' => 'C03', 'pattern' => '/C03 /'],
    ['label' => 'C-PM1 pending judged by the wall clock at the row\'s making, not the run\'s moment', 'file' => $T,
     'old' => "\$own['source'] = (\$moment - UtcClock::parse(\$sentAt) < self::PENDING_S) ? 'pending' : 'none';",
     'new' => "\$own['source'] = (time() - UtcClock::parse(\$sentAt) < self::PENDING_S) ? 'pending' : 'none';",
     'sections' => 'C', 'cases' => 'C04', 'pattern' => '/C04 /'],
    ['label' => 'C-PM2 a pending row made final by the wall clock, not the run\'s moment', 'file' => $T,
     'old' => "        if (\$moment - UtcClock::parse((string)\$row['sent_at']) >= self::PENDING_S) {",
     'new' => "        if (time() - UtcClock::parse((string)\$row['sent_at']) >= self::PENDING_S) {",
     'sections' => 'C', 'cases' => 'C05', 'pattern' => '/C05 /'],
    ['label' => 'C-TR1 tracker_ran written by a run its budget cut short', 'file' => $T,
     'old' => '        if ($reached) {', 'new' => '        if (true) {',
     'sections' => 'C', 'cases' => 'C05', 'pattern' => '/C05 /'],
    ['label' => 'C-TR2 tracker_ran stamped with the wall clock, not the run\'s moment', 'file' => $T,
     'old' => "('tracker_ran', ?, datetime('now'))\")\n                ->execute([UtcClock::stamp(\$moment)]);",
     'new' => "('tracker_ran', ?, datetime('now'))\")\n                ->execute([UtcClock::stamp()]);",
     'sections' => 'C', 'cases' => 'C05', 'pattern' => '/C05 /'],
    ['label' => 'C-CU tracker_cursor written before the rows\' transaction (Q17-F04)', 'file' => $T,
     'old' => "        \$pdo->exec('BEGIN IMMEDIATE');\n        try {\n            foreach (\$plans as \$p) {",
     'new' => "        \$pdo->prepare(\"INSERT OR REPLACE INTO quote_followup_state (k, v) VALUES ('tracker_cursor', ?)\")->execute([(string)\$last]);\n"
            . "        \$pdo->exec('BEGIN IMMEDIATE');\n        try {\n            foreach (\$plans as \$p) {",
     'sections' => 'C', 'cases' => 'C06', 'pattern' => '/C06 /'],
    ['label' => 'C-RC1 a clash on idx_qt_quote_id not recorded', 'file' => $T,
     'old' => "                \$conflict->execute(['ref_conflict:' . \$p[':quote_key'], (string)\$p[':quote_id']]);", 'new' => '',
     'sections' => 'C', 'cases' => 'C07', 'pattern' => '/C07 /'],
    ['label' => 'C-RC2 a clash recorded again at every send', 'file' => $T,
     'old' => "                \$conflict->execute(['ref_conflict:' . \$p[':quote_key'], (string)\$p[':quote_id']]);",
     'new' => "                \$conflict->execute(['ref_conflict:' . \$p[':quote_key'] . ':' . \$p[':evidence_msg_id'], (string)\$p[':quote_id']]);",
     'sections' => 'C', 'cases' => 'C07', 'pattern' => '/C07 /'],
]);

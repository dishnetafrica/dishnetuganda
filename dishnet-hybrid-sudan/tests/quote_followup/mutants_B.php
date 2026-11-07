<?php
declare(strict_types=1);
/**
 * Weakened copies for section B of tests/test_quote_followup.php (5.18.104, root docs/73 §H.2.14: QM32, QM33, QM34, QM51,
 * and two this stage adds for its own guards — the earliest row winning, and the cash proforma kept out of the evidence).
 * Fix round 1 adds the job's line without the cursor (B-LOG1-3, B07), paid set at creation (B-PD1-3, B08) and owner rule
 * 1 on the applications F12's KYC paths store (B-R1, B09). Appended to $M by the runner. TEST ONLY. Each names the cases
 * that guard it (B06, the php -S paths, is left out; B-R1 runs B09 alone).
 */
$T = 'lib/QuoteTracker.php';
$E = 'lib/QuoteSendEvidence.php';
$M = array_merge($M, [
    ['label' => 'QM32 a row from a claim with no message (wa_sent_quotes taken as a send)', 'file' => $T,
     'old' => "        \$plans = [];\n        foreach (\$msgs as \$m) {",
     'new' => "        \$plans = [];\n"
            . "        if (self::hasTable(\$pdo, 'wa_sent_quotes')) {   // QM32's copy\n"
            . "            foreach (\$pdo->query('SELECT quote_id, quote_ref FROM wa_sent_quotes')->fetchAll(\\PDO::FETCH_ASSOC) as \$cl) {\n"
            . "                \$msgs[] = ['id' => \$last, 'conversation_id' => (int)\$msgs[0]['conversation_id'], 'direction' => 'out', 'event_key' => 'ops_quote_created',\n"
            . "                           'body' => QuoteSendEvidence::MARKERS['order'] . \$cl['quote_ref'], 'sent_at' => \$msgs[0]['sent_at'], 'created_at' => \$msgs[0]['sent_at']];\n"
            . "            }\n        }\n        foreach (\$msgs as \$m) {",
     'sections' => 'B', 'cases' => 'B03', 'pattern' => '/B03 /'],
    ['label' => 'QM33 the key from the uCRM id', 'file' => $T,
     'old' => "':quote_key' => \$key,", 'new' => "':quote_key' => \$quoteId !== null ? 'id:' . \$quoteId : \$key,",
     'sections' => 'B', 'cases' => 'B03', 'pattern' => '/B03 /'],
    ['label' => 'QM34 valid_until later than the text (the later of the dates)', 'file' => $E,
     'old' => "        sort(\$dates);\n        return \$dates[0];", 'new' => "        rsort(\$dates);\n        return \$dates[0];",
     'sections' => 'B', 'cases' => 'B02', 'pattern' => '/B02 /'],
    ['label' => 'QM51 catch-up rows not backfilled after tracking is switched on again (only the first run backfills)', 'file' => $T,
     'old' => "        \$backfilled = (\$since === null || UtcClock::parse(\$sentAt) < \$since) ? 1 : 0;",
     'new' => "        \$backfilled = ((\$since === null || UtcClock::parse(\$sentAt) < \$since) && (int)\$pdo->query(\"SELECT COUNT(*) FROM quote_followup_state WHERE k = 'tracker_cursor'\")->fetchColumn() === 0) ? 1 : 0;",
     'sections' => 'B', 'cases' => 'B04', 'pattern' => '/B04 /'],
    ['label' => 'B-EW the latest confirming row taking the key (INSERT OR REPLACE)', 'file' => $T,
     'old' => "\$ins = \$pdo->prepare('INSERT OR IGNORE INTO quote_tracker", 'new' => "\$ins = \$pdo->prepare('INSERT OR REPLACE INTO quote_tracker",
     'sections' => 'B', 'cases' => 'B03', 'pattern' => '/B03 /'],
    // B2a fix round 1 (B2a-R04): the job's line carries counts and fixed codes only (§C8; §H.2.14 M) — never the cursor, an id
    ['label' => 'B-LOG1 cron/quote_chase.php printing the cursor beside the counts', 'file' => 'cron/quote_chase.php',
     'old' => "foreach (\$qcCounts as \$qcK => \$qcV) \$qcLine[] = \$qcK . '=' . (int)\$qcV;",
     'new' => "foreach (\$qcCounts as \$qcK => \$qcV) \$qcLine[] = \$qcK . '=' . (int)\$qcV;\n"
            . "\$qcLine[] = 'cursor=' . (int)\$store->getPdo()->query(\"SELECT v FROM quote_followup_state WHERE k = 'tracker_cursor'\")->fetchColumn();",
     'sections' => 'B', 'cases' => 'B07', 'pattern' => '/B07 /'],
    ['label' => 'B-LOG2 run()\'s counts carrying the cursor (a wa_messages.id) again', 'file' => $T,
     'old' => "        \$out['advanced'] = 1;   // a flag:", 'new' => "        \$out['cursor'] = \$last;   // a flag:",
     'sections' => 'B', 'cases' => 'B07', 'pattern' => '/B07 /'],
    ['label' => 'B-LOG3 the flag carrying the cursor\'s value', 'file' => $T,
     'old' => "        \$out['advanced'] = 1;   // a flag:", 'new' => "        \$out['advanced'] = \$last;   // a flag:",
     'sections' => 'B', 'cases' => 'B07', 'pattern' => '/B07 /'],
    // B2a fix round 1 (B2a-R02): the status set at creation, paid (§H.2.9.5)
    ['label' => 'B-PD1 the creation-time paid status removed (a Cash KYC paid before the send left open)', 'file' => $T,
     'old' => "        } elseif (\$quoteId !== null && self::cashPaid(\$pdo, \$ctx, \$quoteId)) {", 'new' => "        } elseif (false) {",
     'sections' => 'B', 'cases' => 'B08', 'pattern' => '/B08 /'],
    ['label' => 'B-PD2 a Cash KYC counted paid without amount_charged > 0', 'file' => $T,
     'old' => "=== 'cash' && (float)(\$a['amount_charged'] ?? 0) > 0;", 'new' => "=== 'cash';",
     'sections' => 'B', 'cases' => 'B08', 'pattern' => '/B08 /'],
    ['label' => 'B-PD3 any KYC with an amount counted paid, Cash or not', 'file' => $T,
     'old' => "return strtolower(trim((string)(\$a['sales_type'] ?? ''))) === 'cash' && (float)", 'new' => "return (float)",
     'sections' => 'B', 'cases' => 'B08', 'pattern' => '/B08 /'],
    // B2a fix round 1 (B2a-R01): F12's KYC paths through their real senders are owned by rule 1 on the stored application
    ['label' => 'B-R1 owner rule 1 skipped (the KYC paths\' applications owned by a later rule)', 'file' => $E,
     'old' => "        if (is_array(\$f['kyc_app'] ?? null)) {\n            \$k = self::kycAgent(\$f['kyc_app'], \$retailerRows);",
     'new' => "        if (false) {\n            \$k = self::kycAgent(\$f['kyc_app'], \$retailerRows);",
     'sections' => 'B', 'cases' => 'B09', 'pattern' => '/B09 path [123] /'],
    ['label' => 'B-EV the cash proforma taken as evidence', 'file' => $E,
     'old' => "'quote_kyc', 'quote_lead', 'quote_manual'];", 'new' => "'quote_kyc', 'quote_lead', 'quote_manual', 'quote_cash'];",
     'sections' => 'B', 'cases' => 'B0[15]', 'pattern' => '/B01 |B05 /'],
]);

<?php
declare(strict_types=1);
/**
 * Weakened copies for section L of tests/test_quote_followup.php (5.18.104, root docs/73 §H.2.17.2 item 6): QM10, QM60,
 * WB23, HT8, MS1, MS8 and TL8 — each one of South Sudan's gates taken away — fail one of L's items 1–5 (and L06, the
 * tool). Each is the same edit as its own section's copy, judged here by L. QM10's L form takes QuoteChase::on()'s three
 * South Sudan defences away together (StaffJobsGate, the ledger's own gate and the Kampala zone), since any one of them
 * alone holds the chase there; WB23's and HT8's likewise their hook's caller gate where the caller asks too. B10-fix1:
 * HT8w and HT8l take the marks' gate from the wrapped-inbound path (W2) and the @lid count (W3) alone.
 * Appended to $M by the runner. TEST ONLY.
 */
$M = array_merge($M, [
    // QM10's L form: on South Sudan with every key set the quote pass then runs; its count line is stdout L02 compares
    ['label' => "QM10 (L) QuoteChase::on() without its South Sudan gates (the chase on with every key set there)",
     'file' => "lib/QuoteChase.php",
     'old' => "        if (!StaffJobsGate::applies(\$config, \$dataDir)) return false;\n        if (!WaSendBudget::on(\$config, \$dataDir)) return false;\n        if (!function_exists('dn_tz')) require_once __DIR__ . '/timezone.php';\n        return dn_tz(\$config) === self::TZ;",
     'new' => "        return true;",
     'sections' => "L", 'cases' => "L02", 'pattern' => "/L02 /"],
    ['label' => "QM60 (L) the scan's exclusion without hasKind() (the book whose 089 ALTER failed)",
     'file' => "cron/followup_scan.php",
     'old' => "\$quoteSql = \$svc->hasKind()\n    ?",
     'new' => "\$quoteSql = true\n    ?",
     'sections' => "L", 'cases' => "L03", 'pattern' => "/L03 /"],
    ['label' => 'WB23 (L) record() writes on South Sudan (with the hooks\' own gate, which asks first, gone too)', 'edits' => [
        ['lib/WaSendBudget.php', "if (!StaffJobsGate::applies(\$this->config, \$this->dataDir)) return 'not_uganda';", "if (false) return 'not_uganda';"],
        ['lib/WaSendBudget.php', "            if (!StaffJobsGate::applies(\$config, \$dataDir)) return;\n            \$res = json_decode(\$raw, true);", "            \$res = json_decode(\$raw, true);"]],
     'sections' => 'L', 'cases' => 'L04$|L04 ', 'pattern' => '/L04 /'],
    ['label' => 'HT8 (L) seen marks written on South Sudan', 'file' => 'lib/WaSeenMarks.php',
     'old' => "            if (!self::uganda(\$config, \$dataDir)) return;\n            if ((\$direction",
     'new' => "            if ((\$direction",
     'sections' => 'L', 'cases' => 'L04b', 'pattern' => '/L04b /'],
    // B10-fix1 (B10-R03): handover() is what L04 compares — its alert's ledger record (recordAlert) and notify()'s own path
    // (record() asks the gate again, so — as WB23 for recordNotify — its gate goes too; only an alert reaches recordAlert)
    ['label' => 'WB-A (L) a hand-over alert recorded on South Sudan (recordAlert\'s gate and record()\'s gone)', 'edits' => [
        ['lib/WaSendBudget.php', "if (!StaffJobsGate::applies(\$this->config, \$this->dataDir)) return 'not_uganda';", "if (false) return 'not_uganda';"],
        ['lib/WaSendBudget.php', "            if (!StaffJobsGate::applies(\$config, \$dataDir)) return;\n            \$inst = (is_object(\$evo) && method_exists(\$evo, 'instanceFor')) ? (string)\$evo->instanceFor('sales') : '';",
         "            \$inst = (is_object(\$evo) && method_exists(\$evo, 'instanceFor')) ? (string)\$evo->instanceFor('sales') : '';"]],
     'sections' => 'L', 'cases' => 'L04$|L04 ', 'pattern' => '/L04 … and (0 ledger rows|no row)/'],
    ['label' => 'AL-N (L) notify()\'s 5.18.104 hunk changing South Sudan\'s answer without an alert number', 'file' => 'lib/AlertService.php',
     'old' => "        \$this->lastResult = null;   // 5.18.104 (root docs/73 §H.2.6.2): this call's answer, never an earlier one\n        \$to = \$this->target();",
     'new' => "        \$this->lastResult = null;   // 5.18.104 (root docs/73 §H.2.6.2): this call's answer, never an earlier one\n        if (\$this->target() === '') return ['sent' => false, 'reason' => 'no_number'];\n        \$to = \$this->target();",
     'sections' => 'L', 'cases' => 'L04$|L04 ', 'pattern' => '/L04 the same/'],
    // B10-fix1 (B10-R02): HT8's gate taken from one path at a time, so the wrapped inbound (W2) and the @lid message (W3) each
    // catch it themselves through L04b (test_voice_south_sudan A04), not only the fromMe audio (W1)
    ['label' => 'HT8w (L) a seen mark written on South Sudan for a wrapped inbound only (W2)', 'file' => 'lib/WaSeenMarks.php',
     'old' => "            if (!self::uganda(\$config, \$dataDir)) return;\n            if ((\$direction",
     'new' => "            if (\$direction !== 'in' && !self::uganda(\$config, \$dataDir)) return;\n            if ((\$direction",
     'sections' => 'L', 'cases' => 'L04b', 'pattern' => '/L04b /'],
    ['label' => 'HT8l (L) the @lid count written on South Sudan (W3)', 'file' => 'lib/WaSeenMarks.php',
     'old' => "            if (!self::uganda(\$config, \$dataDir)) return;\n            if (\$waMessageId === '') return;",
     'new' => "            if (\$waMessageId === '') return;",
     'sections' => 'L', 'cases' => 'L04b', 'pattern' => '/L04b /'],
    ['label' => "MS1 (L) master's 'uganda' check removed", 'file' => 'cron/master.php',
     'old' => "    if (!empty(\$_m_job['uganda']) && !StaffJobsGate::applies(is_array(\$_m_config) ? \$_m_config : [], \$dataDir)) continue;\n", 'new' => "\n",
     'sections' => 'L', 'cases' => 'L05', 'pattern' => '/L05 /'],
    ['label' => 'MS8 (L) cron_status listing the four on South Sudan', 'file' => 'tools/cron_status.php',
     'old' => "        if (preg_match(\"/'uganda'\\s*=>\\s*true/\", \$line) && !StaffJobsGate::applies(\$gateCfg, \$dataDir)) continue;\n", 'new' => '',
     'sections' => 'L', 'cases' => 'L05', 'pattern' => '/L05 /'],
    ['label' => 'TL8 (L) the tool writing on South Sudan', 'file' => 'tools/sales_followup.php',
     'old' => "    if (!StaffJobsGate::applies(\$config, \$dataDir)) {", 'new' => "    if (false) {",
     'sections' => 'L', 'cases' => 'L06', 'pattern' => '/L06 /'],
]);

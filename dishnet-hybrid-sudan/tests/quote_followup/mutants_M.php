<?php
declare(strict_types=1);
/**
 * Weakened copies for section M of tests/test_quote_followup.php (5.18.104, root docs/73 §H.2.14 M; C8): a log line that
 * names a phone, a job's stdout that prints one, and an exception's message logged by a new file. B10-fix1 (B10-R01): each
 * of M02's clauses — a fixture name, an amount, a staff id (in the daily list's log and in the Offers screen's) — and its
 * daily-list control; and M01's three new rules — a literal labelling an id, a variable named as a person's id, the
 * private-sheet exemption (a sheet that is not exclusive and 0600 is a log). Appended to $M by the runner. TEST ONLY.
 */
$M = array_merge($M, [
    ['label' => 'QM-M1 a log line naming the phone (QuoteChase::offerHold)', 'file' => 'lib/QuoteChase.php',
     'old' => "    public static function offerHold(\\PDO \$pdo, string \$phone, string \$nowUtc): string\n    {\n",
     'new' => "    public static function offerHold(\\PDO \$pdo, string \$phone, string \$nowUtc): string\n    {\n        error_log('[quote_chase] hold for ' . \$phone);\n",
     'sections' => 'M', 'cases' => 'M01', 'pattern' => '/M01 the /'],
    ['label' => 'QM-M2 quote_chase\'s stdout printing a tracked phone', 'file' => 'cron/quote_chase.php',
     'old' => "echo 'quote_chase: tracking ' . implode(' ', \$qcLine) . \"\\n\";\n",
     'new' => "echo 'quote_chase: tracking ' . implode(' ', \$qcLine) . \"\\n\";\necho 'quote_chase: last ' . (string)\$store->getPdo()->query('SELECT phone FROM quote_tracker ORDER BY rowid DESC LIMIT 1')->fetchColumn() . \"\\n\";\n",
     'sections' => 'M', 'cases' => 'M02', 'pattern' => '/M02 no 9\+ digit run/'],   // B10-fix1: the clause's own assertion
    ['label' => 'QM-M3 a new file logging an exception\'s message (QuoteAgentCopy)', 'file' => 'lib/QuoteAgentCopy.php',
     'old' => "PluginLog::write('quote_agent_copy', \$what . ' not written (' . get_class(\$e) . ')');",
     'new' => "PluginLog::write('quote_agent_copy', \$what . ' not written (' . \$e->getMessage() . ')');",
     'sections' => 'M', 'cases' => 'M01', 'pattern' => '/M01 the /'],
    // B10-fix1 (B10-R01): M02's clauses, each by its own assertion
    ['label' => 'QM-M4 quote_daily_list\'s stdout naming a customer (a fixture name)', 'file' => 'cron/quote_daily_list.php',
     'old' => "echo 'quote_daily_list: ' . implode(' ', \$qlLine) . \"\\n\";\n",
     'new' => "echo 'quote_daily_list: ' . implode(' ', \$qlLine) . \"\\n\";\necho 'quote_daily_list: for ' . (string)\$store->getPdo()->query('SELECT display_name FROM wa_conversations WHERE display_name IS NOT NULL ORDER BY id LIMIT 1')->fetchColumn() . \"\\n\";\n",
     'sections' => 'M', 'cases' => 'M02', 'pattern' => '/M02 no fixture name/'],
    ['label' => 'QM-M5 quote_chase\'s stdout printing the quotation\'s amount', 'file' => 'cron/quote_chase.php',
     'old' => "echo 'quote_chase: tracking ' . implode(' ', \$qcLine) . \"\\n\";\n",
     'new' => "echo 'quote_chase: tracking ' . implode(' ', \$qcLine) . \"\\n\";\necho 'quote_chase: total ' . (preg_match('/UGX [0-9,]+/', (string)\$store->getPdo()->query(\"SELECT body FROM wa_messages WHERE event_key = 'quote_manual' ORDER BY id DESC LIMIT 1\")->fetchColumn(), \$qcM) ? \$qcM[0] : '') . \"\\n\";\n",
     'sections' => 'M', 'cases' => 'M02', 'pattern' => '/M02 no amount/'],
    ['label' => 'QM-M6 the daily list logging the owner it built for (staff:41)', 'file' => 'lib/QuoteDailyList.php',
     'old' => "        \$c['built'] = 1;\n",
     'new' => "        \$c['built'] = 1;\n        if (!class_exists('PluginLog')) require_once __DIR__ . '/PluginLog.php';\n        \\PluginLog::write('quote_daily_list', 'built for ' . \$key);\n",
     'sections' => 'M', 'cases' => 'M02', 'pattern' => '/M02 no staff id/'],
    ['label' => 'QM-M7 the Offers log line with the staff id again (judged by M02, an action posted through the served plugin)', 'file' => 'includes/post/post_offers.php',
     'old' => '    $_ofLog  = function (string $code) use ($_ofAct, &$_ofId, $_ofCode): void {
        PluginLog::write(\'wa_offers\', $_ofAct . \' offer=\' . (int)$_ofId . \' code=\' . ($code === \'\' ? \'ok\' : $_ofCode($code)));',
     'new' => '    $_ofLog  = function (string $code) use ($_ofAct, &$_ofId, $_ofMeId, $_ofCode): void {
        PluginLog::write(\'wa_offers\', $_ofAct . \' offer=\' . (int)$_ofId . \' staff=\' . $_ofMeId . \' code=\' . ($code === \'\' ? \'ok\' : $_ofCode($code)));',
     'sections' => 'M', 'cases' => 'M02', 'pattern' => '/M02 no staff id/'],
    ['label' => 'QM-M8 the Offers log line with the staff id again (judged by M01, the static read)', 'file' => 'includes/post/post_offers.php',
     'old' => '    $_ofLog  = function (string $code) use ($_ofAct, &$_ofId, $_ofCode): void {
        PluginLog::write(\'wa_offers\', $_ofAct . \' offer=\' . (int)$_ofId . \' code=\' . ($code === \'\' ? \'ok\' : $_ofCode($code)));',
     'new' => '    $_ofLog  = function (string $code) use ($_ofAct, &$_ofId, $_ofMeId, $_ofCode): void {
        PluginLog::write(\'wa_offers\', $_ofAct . \' offer=\' . (int)$_ofId . \' staff=\' . $_ofMeId . \' code=\' . ($code === \'\' ? \'ok\' : $_ofCode($code)));',
     'sections' => 'M', 'cases' => 'M01', 'pattern' => '/M01 the /'],
    ['label' => 'QM-M9 a log line labelling an id (staff=) on a variable no name rule knows', 'file' => 'lib/QuoteChase.php',
     'old' => "    public static function offerHold(\\PDO \$pdo, string \$phone, string \$nowUtc): string\n    {\n",
     'new' => "    public static function offerHold(\\PDO \$pdo, string \$phone, string \$nowUtc): string\n    {\n        error_log('[quote_chase] hold, staff=' . strlen(\$nowUtc));\n",
     'sections' => 'M', 'cases' => 'M01', 'pattern' => '/M01 the /'],
    ['label' => 'QM-M10 a log line naming a person\'s id by a variable not on QM_DENY ($qcOwnerId)', 'file' => 'lib/QuoteChase.php',
     'old' => "    public static function offerHold(\\PDO \$pdo, string \$phone, string \$nowUtc): string\n    {\n",
     'new' => "    public static function offerHold(\\PDO \$pdo, string \$phone, string \$nowUtc): string\n    {\n        \$qcOwnerId = 0;\n        error_log('[quote_chase] hold ' . \$qcOwnerId);\n",
     'sections' => 'M', 'cases' => 'M01', 'pattern' => '/M01 the /'],
    ['label' => 'QM-M11 the owners\' sheet opened for append (no longer exclusive): its staff ids are a log line', 'file' => 'tools/sales_followup_report.php',
     'old' => "\$sfrFh = @fopen(\$sfrOpt['sheet'], 'x');", 'new' => "\$sfrFh = @fopen(\$sfrOpt['sheet'], 'a');",
     'sections' => 'M', 'cases' => 'M01', 'pattern' => '/M01 the /'],
    ['label' => 'QM-M12 the daily list silent (on() false): M02\'s control asks for its line', 'file' => 'lib/QuoteDailyList.php',
     'old' => "        return self::truthy(\$config['quote_daily_list_enabled'] ?? null) && self::truthy(\$config['quote_tracking_enabled'] ?? null)\n            && StaffJobsGate::applies(\$config, \$dataDir);\n",
     'new' => "        return false;\n",
     'sections' => 'M', 'cases' => 'M02', 'pattern' => '/M02 control: quote_daily_list ran/'],
]);

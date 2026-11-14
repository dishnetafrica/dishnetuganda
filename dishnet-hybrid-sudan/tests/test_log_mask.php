<?php
declare(strict_types=1);
/**
 * test_log_mask.php — 5.18.105 (root docs/74 §H.3.4, §H.1.0): no phone number in any log line. The 35 masked lines
 * LN-01…LN-35, the helper PluginLog::tail(), the report's columns, and the release's boundary against 994d660.
 *
 * The sections are the files tests/log_mask/<Id>_*.php (Id: one capital letter), each registering one section in
 * $LM_SECTIONS:
 *
 *   A  each masked line driven through its real path (a served PHP, a sandboxed cron run, or a direct call with fakes),
 *      in 21 harness groups (tests/log_mask/g*.php), the sink captured; per LN: A1 the line is written (the positive
 *      control), A2 no form of the fixture number, A3 no run of nine digits or more; and PluginLog::tail() itself
 *   B  South Sudan: cron/staff_ssp_report.php's sends and rows equal 994d660's, only its log line moves
 *   C  the chase's precondition: the two W2 strings in evo_webhook.php, and `on chase` reaching "W2: installed"
 *   D  the boundary against 994d660, from Git (D1-D7)
 *   E  the logging rule: E1 on 5.18.105's added lines, E2 the ratchet over every log statement, E3 is A's assertion 3
 *   F  the report's BY NUMBER columns, counted in characters (tests/log_mask/F_report.php)
 *
 * Then the weakened copies (tests/log_mask/mutants_<Id>.php): each a copy of the plugin with an anchored edit, judged by
 * THIS file run with --root=<the copy> --sections=<the sections it names> [--cases=<regex>]; a copy is caught only when
 * every section it names fails (and every pattern it gives matches a FAIL line). A copy whose anchor is not found
 * exactly once, or whose planted edit stands inside a 5.18.105 marker's window, is a failure of this test, never a skip.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_log_mask.php [--root=DIR] [--sections=A,D]
 *        [--cases=REGEX] [--no-mutants] [--mutants=REGEX]
 *
 * The process clock is UTC on purpose. Every person, number and message is fictitious (+2567000001xx, "Test …",
 * example.test); nothing leaves 127.0.0.1.
 */
define('QF_TZ', 'Africa/Kampala');
/** South Sudan's zone, for section B and the South Sudan drives (a test file names the zones, tests/test_timezone.php). */
define('LM_TZ_SS', 'Africa/Juba');
require_once __DIR__ . '/log_mask/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('lm');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

$LM_SECTIONS = [];
foreach (glob(__DIR__ . '/log_mask/*.php') ?: [] as $f) {
    if (preg_match('/^[A-Z]_[a-z0-9_]+\.php$/', basename($f))) require $f;
}
ksort($LM_SECTIONS);

qf_run_sections($ctx, $LM_SECTIONS, $O['sections']);

if ($O['mutants']) {
    $M = [];
    foreach (glob(__DIR__ . '/log_mask/mutants_*.php') ?: [] as $f) {
        if (preg_match('/^mutants_[A-Z]\.php$/', basename($f))) require $f;
    }
    lm_mutants($ctx, __FILE__, $M, $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

<?php
declare(strict_types=1);
/**
 * test_quote_followup.php — 5.18.104 (Uganda, root docs/73 §H.2.14): quote tracking and the chase, the daily list, the
 * report's shared pieces, and what every part shares — migration 089, "a person took over" (C7) and the seen marks.
 *
 * The sections are the files tests/quote_followup/<Id>_*.php (Id: one or two capital letters), each registering one
 * section in $QF_SECTIONS. Stage B1 ships A (the schema), F's cases that need no tracker or chase (HumanTakeover, the
 * marks, the webhook's W1–W3) and OH's phone-level half (phoneSince()/phonesSince()); stage B2a adds quote tracking —
 * B (evidence and F12's paths through the real senders), C (owners), E (which number) and F's stops (F13–F20); stage B2b
 * adds the chase — D (the schedule, opening and drafting), G (the sender, cron/followup_send.php's two passes, run from the
 * copy by tests/quote_followup/run_cron.php), H (the kill switch and the enquiry engine), I (the templates) and OH's offers
 * hold; stage B3 adds J (the daily list, its job and the staff page; the report's section K is
 * tests/test_sales_followup_report.php's); later stages add the rest (L–N). Every section starts with its positive control. Then the weakened copies
 * (tests/quote_followup/mutants_<Id>.php): each a copy of the plugin with one anchored edit, judged by THIS file run with
 * --root=<the copy> --sections=<the sections it names>; a copy is caught only when every section it names fails (and,
 * where a pattern is given, a FAIL line matches it). A copy whose anchor is not found exactly once is a failure of this
 * test, never a skip.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_quote_followup.php [--root=DIR] [--sections=A,F,OH]
 *        [--cases=REGEX] [--no-mutants] [--mutants=REGEX]
 *
 * The process clock is UTC on purpose. Every person, number and message is fictitious; nothing leaves 127.0.0.1.
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/quote_followup/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('qf');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

$QF_SECTIONS = [];
foreach (glob(__DIR__ . '/quote_followup/*.php') ?: [] as $f) {
    if (preg_match('/^[A-Z]{1,2}_[a-z0-9_]+\.php$/', basename($f))) require $f;
}
uksort($QF_SECTIONS, function ($a, $b) { return [strlen($a), $a] <=> [strlen($b), $b]; });   // A … N, then OH

qf_run_sections($ctx, $QF_SECTIONS, $O['sections']);

if ($O['mutants']) {
    $M = [];
    foreach (glob(__DIR__ . '/quote_followup/mutants_*.php') ?: [] as $f) {
        if (preg_match('/^mutants_[A-Z]{1,2}\.php$/', basename($f))) require $f;
    }
    qf_mutants($ctx, __FILE__, $M, $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

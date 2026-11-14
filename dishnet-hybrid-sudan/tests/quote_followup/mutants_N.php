<?php
declare(strict_types=1);
/**
 * Weakened copies for section N of tests/test_quote_followup.php (5.18.104, root docs/73 §H.2.14 N; ruling R10): each a
 * change the one merged declared list does not declare, or a declared change missing, that N must catch. The list itself
 * is test code (tests/quote_followup/declared.php, loaded from this checkout), so every copy changes the judged tree.
 * Appended to $M by the runner. TEST ONLY.
 */
$M = array_merge($M, [
    ['label' => 'QN1 an undeclared runtime file changed (lib/currency.php)', 'file' => 'lib/currency.php',
     'old' => "<?php\n", 'new' => "<?php\n// changed\n", 'sections' => 'N', 'pattern' => '/N01 /'],
    ['label' => 'QN2 an unmarked hunk in a declared changed file (cron/followup_close.php)', 'file' => 'cron/followup_close.php',
     'old' => "<?php\ndeclare(strict_types=1);\n", 'new' => "<?php\ndeclare(strict_types=1);\n\$__qn2 = 1;\n", 'sections' => 'N', 'pattern' => '/N05 /'],
    ['label' => 'QN3 a migration of 221c9dc changed', 'file' => 'migrations/001_event_queue.sql',
     'old' => '-- Migration 001: Event Queue', 'new' => '-- Migration 001: Event Queue (edited)', 'sections' => 'N', 'pattern' => '/N02 /'],
    ['label' => 'QN5 a held file changed (lib/StaffDirectory.php)', 'file' => 'lib/StaffDirectory.php',
     'old' => "<?php\n", 'new' => "<?php\n// held, changed\n", 'sections' => 'N', 'pattern' => '/N03 /'],
    ['label' => 'QN6 the manifest changed beyond its version line', 'file' => 'manifest.json',
     'old' => '"url": "https://dishnetafrica.com",', 'new' => '"url": "https://example.test",', 'sections' => 'N', 'pattern' => '/N06 /'],
    ['label' => 'QN7 PHP 8 syntax in a new runtime file (lib/JobBudget.php)', 'file' => 'lib/JobBudget.php',
     'old' => "final class JobBudget\n{\n", 'new' => "final class JobBudget\n{\n    public static function qn7(\$o) { return \$o?->x; }\n", 'sections' => 'N', 'pattern' => '/N07 /'],
    // 5.18.105 (root docs/74 §H.1.0, §H.3.4): QN8 moved, the same check (N04, C1). 5.18.104 planted it in CD_I_KEPT
    // (tests/catalogue_direct/I_boundary.php:51), 12 lines above that file's 5.18.105 marker, which stands where the I0
    // re-point must (:63), so no edit of CD_I_KEPT can stand 31 lines from it. It now plants in N04's other held text,
    // test_quote_company's I3: the refused values at :1826, 82 lines above that file's first 5.18.105 marker (:1908),
    // where no marker's window reaches — asserted from the planted file. N04 still compares CD_I_KEPT's text; no copy of
    // N's plants there any more.
    ['label' => 'QN8 test_quote_company\'s I3 edited (a refused value, \'1O\', taken off it)', 'file' => 'tests/test_quote_company.php',
     'old' => "foreach (['disabled', 'n', '1O', '#1', '-1'] as \$v) {", 'new' => "foreach (['disabled', 'n', '#1', '-1'] as \$v) {", 'sections' => 'N', 'pattern' => '/N04 /',
     'r105_outside' => 'tests/test_quote_company.php'],
    // 5.18.105 (root docs/74 §H.1.0): QN8b, 5.18.104's own QN8 put back beside the moved one, so N04's CD_I_KEPT half is
    // proved to bite again; the I0 re-point's marker stands below it, which cannot mark it (r105_outside_layer)
    ['label' => 'QN8b CD_I_KEPT edited (webhook.php taken off it)', 'file' => 'tests/catalogue_direct/I_boundary.php',
     'old' => "    'webhook.php', 'cron_quote_wa.php', 'api/index.php',", 'new' => "    'cron_quote_wa.php', 'api/index.php',", 'sections' => 'N', 'pattern' => '/N04 /',
     'r105_outside_layer' => 'tests/catalogue_direct/I_boundary.php'],
    ['label' => 'QN9 tools/set_config.php changed', 'file' => 'tools/set_config.php',
     'old' => "<?php\ndeclare(strict_types=1);\n", 'new' => "<?php\ndeclare(strict_types=1);\n// changed\n", 'sections' => 'N', 'pattern' => '/N0[134] /'],
    ['label' => 'QN10 a declared change missing (tests/test_plan_fence.php put back to 221c9dc)', 'file' => 'tests/test_plan_fence.php',
     'old' => '    // 5.18.104 (root docs/73 §H.3.2): lib/Handover.php is ported from the development tree byte for byte, with this
    // decision (dev:tests/test_plan_fence.php). Handover::escalate() — AiReplyWorker::escalate() moved into a library
    // class so the media worker hands a voice note to a person through the same path — sends a customer ONE text, the
    // operator\'s own ai_handover_message, verbatim: a fixed line with no model output, so the plan fence does not apply.
    // Its staff alert goes through AlertService (\'staff\' above).
    \'Handover.php\'             => \'fixed\',
    // 5.18.104 (root docs/73 §H.3.15): lib/VoiceAck.php, P5, sends a customer who sent a voice note ONE of two fixed
    // texts, D9\'s approved A or B (§H.1) verbatim, on a salesperson\'s line prefixed with the fixed persona sentence and
    // the owner\'s first name from the registry: no model output, so the plan fence does not apply. Its staff alert goes
    // through Handover (\'fixed\' above) and AlertService (\'staff\').
    \'VoiceAck.php\'             => \'fixed\',
    // 5.18.104 (root docs/73 §H.4.11): lib/EvolutionOfferGateway.php, P6\'s gateway, sends a customer one of the offer\'s
    // wordings that a DishNet admin wrote and tested on the offers screen (OfferText::validate), verbatim, followed by
    // §H.1\'s fixed STOP line; and sends the admin\'s test of it to the admin\'s own phone of record. No model output, so
    // the plan fence does not apply.
    \'EvolutionOfferGateway.php\' => \'fixed\',
', 'new' => '', 'sections' => 'N', 'pattern' => '/N01 /'],
    // 5.18.105 (root docs/74 §H.3.4 "through the layer", §B.1 G-2): an edit 5.18.105 did not make, in cron_quote_wa.php's
    // three-minute wait (:114, 98 lines above the file's first 5.18.105 marker, outside every marker's window — asserted
    // from the planted file), stays in the tree N reads through tests/fixtures/r105_revert.php: N03 holds the file byte for
    // byte (QF_HELD F and P), as test_quote_company I4 and catalogue_direct I2 do beside it
    ['label' => 'QN11 a held file changed outside 5.18.105\'s hunks (cron_quote_wa.php\'s three-minute wait shortened)', 'file' => 'cron_quote_wa.php',
     'old' => '(time() - strtotime($deferredAt)) < 180) {', 'new' => '(time() - strtotime($deferredAt)) < 120) {', 'sections' => 'N', 'pattern' => '/N03 /',
     'r105_outside' => 'cron_quote_wa.php'],
]);

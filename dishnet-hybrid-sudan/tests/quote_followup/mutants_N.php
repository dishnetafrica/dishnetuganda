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
    ['label' => 'QN8 CD_I_KEPT edited (webhook.php taken off it)', 'file' => 'tests/catalogue_direct/I_boundary.php',
     'old' => "    'webhook.php', 'cron_quote_wa.php', 'api/index.php',", 'new' => "    'cron_quote_wa.php', 'api/index.php',", 'sections' => 'N', 'pattern' => '/N04 /'],
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
]);

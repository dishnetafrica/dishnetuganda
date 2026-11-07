<?php
declare(strict_types=1);
/**
 * test_voice_media.php — 5.18.104 (Uganda, root docs/73 §H.3.24): Part V's media chain, P4 — the rungs, the webhook's
 * step 8c, the media worker and its runner, VoiceTranscription per rung, the budget, the attempt cap, the dead letters,
 * the before-switch-on rule, the hand-over and its move into lib/Handover.php, and the prune.
 *
 * The sections are the files tests/voice_media/<Id>_*.php (Id: one or two capital letters), each registering one
 * section in $VM_SECTIONS and beginning with its positive control. Then the weakened copies
 * (tests/voice_media/mutants_<Id>.php): each a copy of the plugin with one anchored edit (or several, each found exactly
 * once), judged by THIS file run with --root=<the copy> --sections=<its sections>; a copy is caught only when every
 * section it names prints a FAIL line (matching its pattern, where it gives one). A copy whose anchor is not found
 * exactly once is a failure of this test, never a skip. Stage B6 adds P5, R3's Inbox, R4's reply worker and
 * VoiceSteps' checks; stage B9 the switch tool's rows.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_voice_media.php [--root=DIR] [--sections=A,C]
 *        [--cases=REGEX] [--no-mutants] [--mutants=REGEX]
 *
 * The process clock is UTC on purpose. Every person, number, key and message is fictitious; nothing leaves 127.0.0.1.
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/voice_media/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('vm');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

$VM_SECTIONS = [];
foreach (glob(__DIR__ . '/voice_media/*.php') ?: [] as $f) {
    if (preg_match('/^[A-Z]{1,2}_[a-z0-9_]+\.php$/', basename($f))) require $f;
}
uksort($VM_SECTIONS, function ($a, $b) { return [strlen($a), $a] <=> [strlen($b), $b]; });

qf_run_sections($ctx, $VM_SECTIONS, $O['sections']);

if ($O['mutants']) {
    $M = [];
    foreach (glob(__DIR__ . '/voice_media/mutants_*.php') ?: [] as $f) {
        if (preg_match('/^mutants_[A-Z]{1,2}\.php$/', basename($f))) require $f;
    }
    qf_mutants($ctx, __FILE__, $M, $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

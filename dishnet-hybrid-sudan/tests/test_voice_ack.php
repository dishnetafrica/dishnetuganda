<?php
declare(strict_types=1);
/**
 * test_voice_ack.php — 5.18.104 (Uganda, root docs/73 §H.3.11–§H.3.17, §H.3.20, §H.3.24): Part V's replies. P5, the
 * instant acknowledgement of a voice note (lib/VoiceAck.php, its fifteen steps, the shared ledger, the fresh read);
 * R3's machine transcript in the Inbox; R4's answers (F20, R-13, the brain's VOICE MESSAGE block); STOP in a voice note;
 * the hand-over wrapper; and VoiceSteps' checks VA-ON1 and VN-ON1–VN-ON4 (each past act judged by what its maker could
 * know, ruling R18), its preconditions and its off-hooks.
 *
 * The sections are the files tests/voice_ack/<Id>_*.php (Id: one or two capital letters), each registering one section
 * in $VA_SECTIONS and beginning with its positive control. Then the weakened copies (tests/voice_ack/mutants_<Id>.php):
 * each a copy of the plugin with one anchored edit (or several, each found exactly once), judged by THIS file run with
 * --root=<the copy> --sections=<its sections> [--cases=<regex>]; a copy is caught only when every section it names prints
 * a FAIL line (matching its pattern, where it gives one). A copy whose anchor is not found exactly once is a failure of
 * this test, never a skip. The switch tool's rows (VK1–VK4's tool halves, T1–T13) are stage B9's.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_voice_ack.php [--root=DIR] [--sections=A,C]
 *        [--cases=REGEX] [--no-mutants] [--mutants=REGEX]
 *
 * The process clock is UTC on purpose; P5's cases run at a fixed Kampala moment through VoiceAck's clock seam. Every
 * person, number, key and message is fictitious; nothing leaves 127.0.0.1.
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/voice_ack/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('va');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

$VA_SECTIONS = [];
foreach (glob(__DIR__ . '/voice_ack/*.php') ?: [] as $f) {
    if (preg_match('/^[A-Z]{1,2}_[a-z0-9_]+\.php$/', basename($f))) require $f;
}
uksort($VA_SECTIONS, function ($a, $b) { return [strlen($a), $a] <=> [strlen($b), $b]; });

qf_run_sections($ctx, $VA_SECTIONS, $O['sections']);

if ($O['mutants']) {
    $M = [];
    foreach (glob(__DIR__ . '/voice_ack/mutants_*.php') ?: [] as $f) {
        if (preg_match('/^mutants_[A-Z]{1,2}\.php$/', basename($f))) require $f;
    }
    qf_mutants($ctx, __FILE__, $M, $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

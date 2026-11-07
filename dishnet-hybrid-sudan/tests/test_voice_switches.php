<?php
declare(strict_types=1);
/**
 * test_voice_switches.php — 5.18.104 (Uganda, root docs/73 §H.3.5, §H.3.24 VK1–VK4, T1–T14; §H.2.18 IV-10; rulings R5,
 * R6, R7, R10, R13, R15), B9-fix1: Part V's switch-tool halves (notes/B6.md note 14 left them to the tool's stage).
 *
 *   VK  Part V's rows as the switch tool runs them: VK1 Part V's slice of TL14's one key scan; VK2 `off all` (and `off
 *       voice-measure`) leaving every rung predicate false, step 5's hook after its keys; VK3 the S2-11 scenario — stale
 *       reply keys after `off all`, then steps 2–8 switched on through the tool, R4 never armed; VK4 the ladder climbed
 *       through the tool over working rungs, `on voice-staff`, `on voice-reply`, `on cold` and `on offers-test` admitted;
 *       the refusals of `on voice-dry-run` (T2) and `on voice-reply` (T6), `show` (T4) and `--check` (T7) on $ro
 *
 * Every section begins with its positive control. Then the weakened copies (tests/voice_switches/mutants_VK.php): T2,
 * T4, T5, T6's third form, T7, T9 (both plant sites) and T14's step-5 form, each a copy of the plugin with one anchored
 * edit, judged by this file run with --root=<the copy> --sections=VK [--cases=<regex>]; a copy not caught is a failure,
 * never a skip. Part V's other T copies are read from Part F's TL (T1 TL1, T3 TL28, T10 TL23, T11 and T12 TL19, T13 TL22,
 * T14's first form TL27) or are Part V's own in tests/test_voice_ack.php (T8, T14c, T15 …) — ruling R10, IV-10.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_voice_switches.php [--root=DIR] [--sections=VK]
 *        [--cases=REGEX] [--no-mutants] [--mutants=REGEX]
 *
 * The tool runs as a child process, as the data folder's owner, against a book of its own; time passing between rungs
 * is the since-stamps the tool wrote moved back, and each rung's rows are planted as its worker leaves them
 * (tests/voice_ack's worlds; the real workers write them in tests/test_voice_media.php and tests/test_voice_ack.php).
 * Every number, name, key and message is fictitious; nothing leaves 127.0.0.1.
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/voice_ack/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('vsw');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];
$TREE = $ctx->tree();
require_once __DIR__ . '/sales_followup_tool/_lib.php';
sft_load($ctx);
va_load($ctx);

$SECTIONS = [];
require __DIR__ . '/voice_switches/VK_tool.php';

qf_run_sections($ctx, $SECTIONS, $O['sections']);

if ($O['mutants']) {
    $M = [];
    require __DIR__ . '/voice_switches/mutants_VK.php';
    qf_mutants($ctx, __FILE__, $M, $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

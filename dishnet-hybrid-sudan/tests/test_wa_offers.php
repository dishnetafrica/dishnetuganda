<?php
declare(strict_types=1);
/**
 * test_wa_offers.php — 5.18.104 (Uganda, root docs/73 §H.4.16): Part O's engine (P6). Migration 091 and OfferSchema; the
 * text, the image and the window; the switch's callables; the screen and its actions; the audience and every exclusion;
 * STOP in every scope; the sender (cron/offers_send.php
 * and OfferSender) with its crash idempotency; the gateway and its Evolution driver; the per-offer counts; the job's
 * closure and master's deadline; South Sudan and the switches off; Part F's real pieces under P6's calls; the log.
 *
 * The sections are the files tests/wa_offers/<Id>_*.php (Id: one or two capital letters), each registering one section in
 * $WO_SECTIONS and beginning with its positive control. Then the weakened copies (tests/wa_offers/mutants_<Id>.php): each a
 * copy of the plugin with one anchored edit (or several, each found exactly once), judged by THIS file run with
 * --root=<the copy> --sections=<its sections> [--cases=<regex>]; a copy is caught only when every section it names prints a
 * FAIL line (matching its pattern, where it gives one). A copy whose anchor is not found exactly once is a failure of this
 * test, never a skip. Then the control on the controls (CC): this runner on 221c9dc, taken from Git, must print a FAIL in
 * every section (wo_control_on_controls). B is the switch's callables (preconditions, the two checks, the off-hook), C the
 * screen and its seven actions, and K's --check half (KC) runs at the end of K; the switch tool's own rows (B's tool half) are stage B9's,
 * the master line and the merged boundary (I's master half, M) stage B10's.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_wa_offers.php [--root=DIR] [--sections=A,F]
 *        [--cases=REGEX] [--no-mutants] [--mutants=REGEX]       (--mutants='^CC$' runs the control on the controls alone)
 *
 * The process clock is UTC on purpose; the sender's cases run at fixed Kampala moments through its clock seam. Every
 * person, number and message is fictitious; nothing leaves 127.0.0.1.
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/wa_offers/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('wo');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];
wo_load($ctx);
// The scripted gateway implements the judged tree's OfferGateway: on a tree without it (221c9dc, the control on the
// controls) the fakes are not declared, and every case that needs them FAILs instead of the whole run dying at load.
if (interface_exists('OfferGateway')) require_once __DIR__ . '/wa_offers/fakes.php';

$WO_SECTIONS = [];
foreach (glob(__DIR__ . '/wa_offers/*.php') ?: [] as $f) {
    if (preg_match('/^[A-Z]{1,2}_[a-z0-9_]+\.php$/', basename($f))) require $f;
}
uksort($WO_SECTIONS, function ($a, $b) { return [strlen($a), $a] <=> [strlen($b), $b]; });

qf_run_sections($ctx, $WO_SECTIONS, $O['sections']);

if ($O['mutants']) {
    $M = [];
    foreach (glob(__DIR__ . '/wa_offers/mutants_*.php') ?: [] as $f) {
        if (preg_match('/^mutants_[A-Z]{1,2}\.php$/', basename($f))) require $f;
    }
    qf_mutants($ctx, __FILE__, $M, $O['only']);
    if ($O['only'] === null || preg_match('/' . $O['only'] . '/', 'CC')) wo_control_on_controls($ctx, __FILE__, array_keys($WO_SECTIONS));
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

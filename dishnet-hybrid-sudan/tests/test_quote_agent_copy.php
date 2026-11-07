<?php
declare(strict_types=1);
/**
 * test_quote_agent_copy.php — 5.18.104 Part P (P7, decision D16; root docs/73 §H.5.12): the agent's visible copy (Cc)
 * of the quotation e-mail the plugin sends.
 *
 *   A  the rules (pure): usableAddress, toOf, decide, mergeCc, rcptOutcome, webhookAgent/kycInFlight, one KYC agent, afterSend
 *   B  the app path: createCrmQuote → emailQuotePdf, the maker hand-off, fault injection, uCRM requests unchanged
 *   C  the webhook path: whQuotationEmail's resolution, in flight, first decision, fault injection
 *   D  the dispatcher: the setter, the merge floor, agent_rcpt
 *   E  the relay: a refused or stalled RCPT, a rejected DATA, the 998-octet line
 *   F  the row's own precondition (preconditions()); the rest of F is the tool's (stage B9)
 *   G  the check, AC-ON (ruling R18)
 *   H  South Sudan, and Uganda with P7 off, byte for byte as 221c9dc
 *   I  privacy: no address, name or phone in any P7 record or log line
 *   J  the boundary: P7's slice against 221c9dc
 *
 * The sections are tests/quote_agent_copy/<A…J>.php, each registering one section in $QAC_SECTIONS and beginning with
 * its positive control. Senders run in processes of their own (tests/quote_agent_copy/drive.php), each loading ONE tree:
 * the judged tree's copy, a stub tree, or 221c9dc from Git. Then the weakened copies (tests/quote_agent_copy/mutants.php,
 * PW1–PW79 where this stage can build them): each a copy of the plugin with one anchored edit, judged by THIS file run
 * with --root=<the copy> --sections=<its sections>; a copy is caught only when every section it names fails. An anchor
 * not found exactly once is a failure of this test, never a skip.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_quote_agent_copy.php [--root=DIR] [--sections=A,B]
 *        [--cases=REGEX] [--no-mutants] [--mutants=REGEX]
 *
 * The process clock is UTC on purpose. Every person, number and address is fictitious; nothing leaves 127.0.0.1.
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/quote_followup/_lib.php';
require_once __DIR__ . '/quote_agent_copy/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('qac');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

$QAC_SECTIONS = [];
foreach (glob(__DIR__ . '/quote_agent_copy/*.php') ?: [] as $f) {
    if (preg_match('/^[A-J]\.php$/', basename($f))) require $f;
}
ksort($QAC_SECTIONS);

qf_run_sections($ctx, $QAC_SECTIONS, $O['sections']);

if ($O['mutants']) {
    $M = [];
    require __DIR__ . '/quote_agent_copy/mutants.php';
    qf_mutants($ctx, __FILE__, $M, $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

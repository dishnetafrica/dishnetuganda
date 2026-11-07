<?php
declare(strict_types=1);
/**
 * test_sales_followup_tool.php — 5.18.104 (Uganda, root docs/73 §H.2.12, §H.2.14, §H.2.16): the one switch tool
 * (tools/sales_followup.php), the one ladder (lib/SalesFollowupLadder.php) and Part F's checks
 * (lib/SalesFollowupChecks.php).
 *
 *   TL  the tool: the order, the needs and pass_only, skip and its cascade, the one status vocabulary, the checks and
 *       step 2's one status, off and its hooks with their placement, the one rollback sequence, the read-back, off
 *       unsetting keys, allKeys() against settingKeys(), the uid and South Sudan refusals, every part's settings, both
 *       option forms, a row's options before its precondition, erase, doctor, the call surface (ruling R13), the locks
 *   CK  the checks after the world moved (ruling R18): each case a correct act followed by a change, reading PASS with
 *       the change printed as a count; and the controls, rows planted directly, each reading FAIL
 *
 * Every section begins with its positive control. Then the weakened copies, each a copy of the plugin with one
 * anchored edit, judged by this file run with --root=<the copy> --sections=<its sections>: a copy not caught is a
 * failure, never a skip; an anchor not found exactly once is a failure too.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_sales_followup_tool.php [--root=DIR] [--sections=TL,CK]
 *        [--cases=REGEX] [--no-mutants] [--mutants=REGEX]
 *
 * The tool runs as a child process, as the data folder's owner, against a book of its own; the checks run in process
 * on a read-only connection. Every number, name and message is fictitious; nothing leaves 127.0.0.1.
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/quote_followup/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('sft');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];
$TREE = $ctx->tree();
require_once __DIR__ . '/sales_followup_tool/_lib.php';
sft_load($ctx);

$SECTIONS = [];
require __DIR__ . '/sales_followup_tool/TL_locks.php';   // 5.18.104 (root docs/73 §H.2.14 TL32, TL33), B9-fix1
require __DIR__ . '/sales_followup_tool/TL_tool.php';
require __DIR__ . '/sales_followup_tool/CK_checks.php';

qf_run_sections($ctx, $SECTIONS, $O['sections']);

if ($O['mutants']) {
    $M = [];
    require __DIR__ . '/sales_followup_tool/mutants_TL.php';
    require __DIR__ . '/sales_followup_tool/mutants_CK.php';
    qf_mutants($ctx, __FILE__, $M, $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

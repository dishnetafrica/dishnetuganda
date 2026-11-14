<?php
declare(strict_types=1);
/**
 * Section C of tests/test_log_mask.php — 5.18.105 (root docs/74 §H.3.4 C): the chase's precondition. TEST ONLY.
 *
 * lib/SalesFollowupChecks.php (994d660:68-72) refuses `on chase` with w2_missing unless evo_webhook.php holds two strings:
 * `WaSeenMarks::note($pdo, $config, $dataDir, 'in', $phone,` (994d660:300) and `$convId === null && $text === ''` (:298).
 * 5.18.105 edits evo_webhook.php (LN-01, the opt-out line at :381-382, 81 lines below), so:
 *
 *   C01  each string occurs exactly once in the judged evo_webhook.php;
 *   C02  lib/SalesFollowupChecks.php is 994d660's byte for byte (from Git, r105_base);
 *   C03  `on chase` through tools/sales_followup.php, on a box of the judged tree whose book has every step before the
 *        chase on (tests/sales_followup_tool/_lib.php's sft_on_upto(), as TL13 sets it), reaches "W2: installed" and
 *        saves the switch; the control: the same run on a box whose evo_webhook.php lost the first string is REFUSED
 *        w2_missing — the check C03 relies on is the one that reads the file.
 *
 * The box is lm_cli_box()'s (no QfCtx::book(): see tests/log_mask/_lib.php lm_store_classes()). Nothing here is a phone
 * number of anyone; the tool's reason is "quiet week (test)".
 */
require_once dirname(__DIR__) . '/sales_followup_tool/_lib.php';   // sft_on_upto(), SFT_UG

/** The two strings the chase's check reads in evo_webhook.php (lib/SalesFollowupChecks.php, 994d660:69). */
const LM_W2 = ["WaSeenMarks::note(\$pdo, \$config, \$dataDir, 'in', \$phone,", "\$convId === null && \$text === ''"];

/** Run a box's tools/sales_followup.php: [exit code, output]; $stdin is typed. */
function lm_c_tool(string $plug, string $data, array $args, string $stdin): array
{
    $e = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $p = proc_open(array_merge([PHP_BINARY, $plug . '/tools/sales_followup.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                   $pipes, $plug, ['DN_DATA_DIR' => $data] + $e);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), (string)$out];
}

/** A box of the judged tree whose book has every step up to voice-measure on, as TL13's; [plug, data]. */
function lm_c_box(QfCtx $ctx, string $tag): array
{
    lm_lib($ctx, 'UtcClock');
    lm_lib($ctx, 'SalesFollowupLadder');
    $cfg = sft_on_upto('voice-measure', time());
    return lm_cli_box($ctx, $cfg, $tag);
}

$LM_SECTIONS['C'] = ['title' => 'The chase\'s precondition: the two W2 strings, and `on chase` reaching "W2: installed" (§H.3.4 C)', 'run' => function (QfCtx $ctx) {
    $ctx->run('C01 each W2 string occurs exactly once in the judged evo_webhook.php', function () use ($ctx) {
        $src = (string)@file_get_contents($ctx->root . '/evo_webhook.php');
        $ctx->t($src !== '', 'C01 control: evo_webhook.php is read');
        $ctx->eq(array_map(function ($s) use ($src) { return substr_count($src, $s); }, LM_W2), [1, 1],
            'C01 "WaSeenMarks::note($pdo, $config, $dataDir, \'in\', $phone," and "$convId === null && $text === \'\'" once each');
    });
    $ctx->run('C02 lib/SalesFollowupChecks.php is 994d660\'s byte for byte', function () use ($ctx) {
        [$base, $why] = r105_base($ctx->dir('r105-base'));
        $ctx->t($base !== null, 'C02 control: 994d660 from Git', (string)$why);
        if ($base === null) return;
        $ctx->t(is_file($base . '/lib/SalesFollowupChecks.php'), 'C02 control: 994d660 holds lib/SalesFollowupChecks.php');
        $ctx->eq(hash_file('sha256', $ctx->root . '/lib/SalesFollowupChecks.php'), hash_file('sha256', $base . '/lib/SalesFollowupChecks.php'),
            'C02 lib/SalesFollowupChecks.php: the same sha256 as 994d660\'s');
    });
    $ctx->run('C03 on chase through the tool on the judged tree reaches "W2: installed"', function () use ($ctx) {
        [$plug, $data] = lm_c_box($ctx, 'c03');
        [$rc, $out] = lm_c_tool($plug, $data, ['on', 'chase', '--accept-not-yet', 'quiet week (test)'], "yes\n");
        $k = json_decode((string)@file_get_contents($data . '/kyc_config.json'), true) ?: [];
        $ctx->eq([$rc, strpos($out, 'W2: installed') !== false, $k['quote_chase_enabled'] ?? null], [0, true, '1'],
            'C03 on chase: exit 0, "W2: installed", the switch saved', substr($out, -900));
        [$plug2, $data2] = lm_c_box($ctx, 'c03-cut');
        $src = (string)file_get_contents($plug2 . '/evo_webhook.php');
        $ctx->eq(substr_count($src, LM_W2[0]), 1, 'C03 control: the box\'s evo_webhook.php holds the first string once, to cut');
        file_put_contents($plug2 . '/evo_webhook.php', str_replace(LM_W2[0], 'WaSeenMarks::note_x(', $src));
        [$rc2, $out2] = lm_c_tool($plug2, $data2, ['on', 'chase', '--accept-not-yet', 'quiet week (test)'], "yes\n");
        $ctx->eq([$rc2, strpos($out2, 'REFUSED w2_missing') !== false], [2, true],
            'C03 control: with the string cut in a box, on chase is REFUSED w2_missing (the check reads the file)', substr($out2, -600));
    });
}];

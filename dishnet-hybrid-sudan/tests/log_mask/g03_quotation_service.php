<?php
declare(strict_types=1);
/**
 * g03_quotation_service.php — 5.18.105 (root docs/74 §H.1.3): group G03 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-03, QuotationService::sendWA()'s failure line. A direct call in a process of its own (tests/log_mask/side_direct.php):
 * sendManualQuote(), then sendLeadQuote(), with $ns replaced by reflection — as tests/quote_agent_copy/drive.php replaces
 * crm — by a NotificationService whose sendVia throws an exception carrying the fixture number (+256700000103);
 * ini_set('error_log', FILE). Extra: the line reads "[QuotationService] WA send failed (quote_manual): ", the exception's
 * number appears as <number>, and the method returns its failure as before (sent_via_wa false).
 */
function lm_g03(QfCtx $ctx): void
{
    $ctx->run('G03 LN-03 lib/QuotationService.php: a WhatsApp send that throws, PHP\'s log', function () use ($ctx) {
        [$tree, $data] = lm_cli_box($ctx, ['tenant_profile' => 'south-sudan', 'timezone' => LM_TZ_SS], 'g03');
        $err = $ctx->dir('g03-log') . '/php.log';
        touch($err);
        $spec = $ctx->dir('g03-spec') . '/spec.json';
        file_put_contents($spec, json_encode(['root' => $tree, 'data' => $data, 'error_log' => $err, 'do' => 'quotation',
            'phone' => lm_num(3), 'config' => ['tenant_profile' => 'south-sudan', 'timezone' => LM_TZ_SS, 'data_dir' => $data]]));
        [$res, $raw] = lm_side($spec);
        $ctx->t(!isset($res['manual']['exc']) && !isset($res['lead']['exc']) && isset($res['manual'], $res['lead']), 'LN-03 control: both senders ran to their end', $raw);
        $ctx->eq([$res['manual']['ok'] ?? null, $res['manual']['sent_via_wa'] ?? null, $res['lead']['ok'] ?? null, $res['lead']['sent_via_wa'] ?? null],
            [true, false, true, false], 'LN-03 each sender returns its failure as before: the quotation made (ok), sent_via_wa false');
        $ctx->eq(array_column((array)($res['calls'] ?? []), 1), ['quote_manual', 'quote_lead'], 'LN-03 control: the throwing notifier was asked twice, once per sender');
        $found = lm_judge($ctx, 'LN-03', lm_lines((string)@file_get_contents($err)), LM_LINES['LN-03']['fixed'], [lm_num(3)], 2);
        $ctx->eq(count(array_filter($found, function ($l) { return strpos($l, '[QuotationService] WA send failed (quote_manual): ') === 0; })), 1,
            'LN-03 the line reads "[QuotationService] WA send failed (quote_manual): …"');
        $ctx->eq(count(array_filter($found, function ($l) { return strpos($l, '[QuotationService] WA send failed (quote_lead): ') === 0; })), 1,
            'LN-03 … and "(quote_lead)" for the lead\'s quotation');
        $ctx->eq(count(array_filter($found, function ($l) { return strpos($l, 'Evolution refused the send to <number> (test)') !== false; })), 2,
            'LN-03 the exception\'s number appears as <number> (PluginLog::mask)');
    });
}

<?php
declare(strict_types=1);
/**
 * g08_wa_watchdog.php — 5.18.105 (root docs/74 §H.1.8): group G08 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-08, cron/wa_watchdog.php's "alerted for conv" line — test_pilot_safety's watchdog harness (tests/test_pilot_safety.php:
 * an unanswered conversation, last_customer_at 20 minutes back), here with no display_name, so the watchdog's $who is the
 * customer's number (+256700000108, fictitious); the alert number is +256700000158; alert hours the whole day. stdout is
 * captured (plugin.log). The alert text sent to staff keeps $who — a message, not a log — and is not judged here.
 */
function lm_g08(QfCtx $ctx): void
{
    $ctx->run('G08 LN-08 cron/wa_watchdog.php: an unanswered conversation with no name, stdout', function () use ($ctx) {
        $evo = qf_fake_evo();
        [$plug, $data] = lm_cli_box($ctx, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'evo_api_url' => $evo, 'evo_api_key' => 'lm-evo-key',
                                           'evo_instance_support' => 'lm-support', 'evo_instance_account' => 'lm-account',
                                           'evo_instance_sales' => 'lm-sales', 'alert_whatsapp' => lm_num(58),
                                           'alert_hours_from' => 0, 'alert_hours_to' => 24], 'g08');
        $pdo = lm_pdo($data);
        lm_lib($ctx, 'ConversationService');
        $c = (new ConversationService($data, $pdo))->ensureConversation(lm_forms(lm_num(8))[1], 'support');
        $pdo->prepare("UPDATE wa_conversations SET display_name = NULL, status = 'active', last_agent_at = NULL,
                       last_customer_at = datetime('now', '-20 minutes') WHERE id = ?")->execute([(int)$c['id']]);
        $err = $ctx->dir('g08-log') . '/php.log';
        touch($err);
        [, $out] = lm_cli_run($plug, $data, 'cron/wa_watchdog.php', $err);
        $found = lm_ln($ctx, 'LN-08', lm_lines($out), [lm_num(8), lm_num(58)]);
        $ctx->t($found !== [] && preg_match('/^wa_watchdog: alerted for conv ' . (int)$c['id'] . ' \(\d+ min\)$/', $found[0]) === 1,
            'LN-08 the line names the conversation and the minutes only', implode(' | ', $found) . ' ' . substr($out, -300));
        $ctx->eq(count(array_filter((array)(qf_http('GET', $evo . '/__test/state')[1]['text_calls'] ?? []), function ($t) {
            return strpos((string)$t['text'], 'has been waiting') !== false;
        })), 1, 'LN-08 control: the alert went to the alert number (the fake Evolution took it)');
    });
}

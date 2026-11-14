<?php
declare(strict_types=1);
/**
 * g16_wa_sync.php — 5.18.105 (root docs/74 §H.1.13): group G16 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-25, cron_wa_sync.php's per-message line (master's stdout, plugin.log): the job run in a sandbox of its own against
 * the new fake WhatsML feed under tests/log_mask/ (fake_whatsml_feed.php), wa_feed_url pointing at it, answering one
 * inbound row from +256700000125 (fictitious), ten minutes old (recorded, not answered). Extra: "<modality> from conv
 * #<id> on <channel>:".
 *
 * How it is run: under cron/master.php the job finds WaInbound already loaded — an earlier job of the same process loads
 * it — and the job itself calls WaInbound::normalise() before its own require of lib/WaMessageProcessor.php (which loads
 * it). Run alone, every row would stop there ("ERROR pkId=…") before the line under test. So the job is run as master runs
 * it, with lib/WaInbound.php loaded first (php -d auto_prepend_file=…, the copy's own file); the job's code is unchanged.
 */
function lm_g16(QfCtx $ctx): void
{
    $ctx->run('G16 LN-25 cron_wa_sync.php: one inbound row from the feed, stdout', function () use ($ctx) {
        $feed = lm_fake(__DIR__ . '/fake_whatsml_feed.php', 'LM-FAKE-WHATSML-FEED', 15000);
        $ctx->t($feed !== '', 'LN-25 control: the fake WhatsML feed is up');
        if ($feed === '') return;
        $secret = 'lm-feed-secret-test';
        $digits = lm_forms(lm_num(25))[1];
        qf_http('POST', $feed . '/__test/seed', ['secret' => $secret, 'rows' => [[
            'pkId' => 701, 'sessionId' => 'lm-session-support', 'remoteJid' => $digits . '@s.whatsapp.net', 'id' => 'LM-G16-1',
            'message' => json_encode(['conversation' => 'Hello, do you install in Entebbe? (test)']), 'fromMe' => 0,
            'pushName' => 'Test Feed Customer', 'messageTimestamp' => time() - 600]]]);
        [$plug, $data] = lm_cli_box($ctx, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'wa_sync_enabled' => true,
                                           'wa_feed_url' => $feed . '/message_feed.php', 'wa_feed_secret' => $secret,
                                           'wa_session_support' => 'lm-session-support', 'wa_session_accounts' => 'lm-session-accounts',
                                           'wa_bot_enabled' => false, 'wa_auto_reply_enabled' => false], 'g16');
        $err = $ctx->dir('g16-log') . '/php.log';
        touch($err);
        $cmd = sprintf('cd %s && DN_DATA_DIR=%s DN_VAULT_FILE=%s timeout 120 %s -d log_errors=1 -d display_errors=0 -d error_log=%s -d auto_prepend_file=%s cron_wa_sync.php 2>&1',
            escapeshellarg($plug), escapeshellarg($data), escapeshellarg((string)getenv('DN_VAULT_FILE')), escapeshellarg(PHP_BINARY),
            escapeshellarg($err), escapeshellarg($plug . '/lib/WaInbound.php'));
        $out = [];
        exec($cmd, $out);
        $out = implode("\n", $out);
        $polls = (array)(qf_http('GET', $feed . '/__test/state')[1]['polls'] ?? []);
        $ctx->t(count($polls) === 1 && !empty($polls[0]['secret_ok']), 'LN-25 control: the job polled the feed once, with its secret', json_encode($polls));
        $ctx->t(strpos($out, 'Done: stored=1') !== false, 'LN-25 control: the row was stored', substr($out, -400));
        $found = lm_ln($ctx, 'LN-25', lm_lines($out), [lm_num(25)]);
        $ctx->t($found !== [] && preg_match('/^  \S+ from conv #\d+ on support: \S/', $found[0]) === 1,
            'LN-25 "<modality> from conv #<id> on <channel>: <action>"', implode(' | ', $found));
    });
}

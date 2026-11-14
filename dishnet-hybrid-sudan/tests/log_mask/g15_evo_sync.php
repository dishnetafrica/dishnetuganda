<?php
declare(strict_types=1);
/**
 * g15_evo_sync.php — 5.18.105 (root docs/74 §H.1.12): group G15 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-24, the one central edit: evoLog() masks every line it writes to evo_sync.log, covering its three call sites
 * (cron_evo_sync.php's "Syncing …", "→ …: N messages imported" and "→ ERROR for …"). The job run in a sandbox of its
 * own against the new fake Evolution under tests/log_mask/ (fake_evo_chats.php: chat/findChats and chat/findMessages),
 * evo_instance_name set:
 *   - a chat from +256700000124 with one message: "Syncing <number> (<e-mail>)..." and "→ <number>: 1 messages imported";
 *   - a chat from +256700000174 whose messages throw (a record the store cannot take): "→ ERROR for <number>: …";
 *   - the count lines are unchanged ("Found 2 individual chats", "=== Sync complete: …").
 * Every number and name is fictitious.
 */
function lm_g15(QfCtx $ctx): void
{
    $ctx->run('G15 LN-24 cron_evo_sync.php: two chats, one that throws, evo_sync.log', function () use ($ctx) {
        $fake = lm_fake(__DIR__ . '/fake_evo_chats.php', 'LM-FAKE-EVO-CHATS', 14800);
        $ctx->t($fake !== '', 'LN-24 control: the fake Evolution (chat history) is up');
        if ($fake === '') return;
        $good = lm_forms(lm_num(24))[1] . '@s.whatsapp.net';
        $bad  = lm_forms(lm_num(74))[1] . '@s.whatsapp.net';
        qf_http('POST', $fake . '/__test/seed', [
            'chats' => [['remoteJid' => $good, 'lastMessage' => ['pushName' => 'Test Chatter']], ['remoteJid' => $bad]],
            'messages' => [
                $good => ['messages' => ['records' => [['key' => ['remoteJid' => $good, 'fromMe' => false, 'id' => 'LM-G15-1'],
                                                        'message' => ['conversation' => 'Hello, is the kit in stock? (test)'],
                                                        'messageTimestamp' => time() - 3600, 'pushName' => 'Test Chatter']], 'pages' => 1]],
                // a record that is not a message: importEvoMessage() takes an array, so the chat's sync throws
                $bad => ['messages' => ['records' => ['not a message (test)'], 'pages' => 1]],
            ],
        ]);
        [$plug, $data] = lm_cli_box($ctx, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'evo_api_url' => $fake, 'evo_api_key' => 'lm-evo-key',
                                           'evo_instance_name' => 'lm-history'], 'g15');
        $err = $ctx->dir('g15-log') . '/php.log';
        touch($err);
        [, $out] = lm_cli_run($plug, $data, 'cron_evo_sync.php', $err, ['--full']);
        $lines = lm_lines((string)@file_get_contents($data . '/evo_sync.log'));
        $ctx->t(strpos($out, '[evo_sync] Done: 1 chats, 1 msgs') !== false, 'LN-24 control: the job imported one chat and its message', substr($out, -300));
        $syncing = lm_judge($ctx, 'LN-24', $lines, 'Syncing ', [lm_num(24), lm_num(74)], 2, 'Syncing, both chats');
        $ctx->eq(count(array_filter($syncing, function ($l) { return $l === 'Syncing <number> (<e-mail>)...'; })), 2,
            'LN-24 "Syncing <number> (<e-mail>)..." for each chat (the JID an <e-mail>, the number a <number>)');
        $imported = lm_judge($ctx, 'LN-24', $lines, ' messages imported', [lm_num(24), lm_num(74)], 1, 'messages imported');
        $ctx->eq($imported[0] ?? '', '→ <number>: 1 messages imported', 'LN-24 "→ <number>: 1 messages imported"');
        $error = lm_judge($ctx, 'LN-24', $lines, 'ERROR for ', [lm_num(24), lm_num(74)], 1, 'the chat that throws');
        $ctx->t(($error[0] ?? '') !== '' && strpos($error[0], '→ ERROR for <number>: ') === 0, 'LN-24 "→ ERROR for <number>: …", its exception text masked too', $error[0] ?? '');
        $ctx->t(in_array('Found 2 individual chats (skipped groups)', $lines, true)
                && count(array_filter($lines, function ($l) { return strpos($l, '=== Sync complete: 1 chats processed, 1 messages imported, 0 skipped ===') === 0; })) === 1,
            'LN-24 the count lines are unchanged', implode(' | ', $lines));
    });
}

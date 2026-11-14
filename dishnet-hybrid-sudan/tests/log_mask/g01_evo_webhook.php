<?php
declare(strict_types=1);
/**
 * g01_evo_webhook.php — 5.18.105 (root docs/74 §H.1.1): group G01 of tests/test_log_mask.php section A. TEST ONLY.
 *
 * LN-01, evo_webhook.php's opt-out line, through the served webhook (F11's harness, tests/quote_followup/F_stops.php:
 * the real evo_webhook.php under php -S on a copy of the judged tree), PHP started with -d error_log=FILE:
 *   - a typed STOP on a stored conversation: exactly one line "opt-out recorded for conversation #<id> (matched "stop")";
 *   - a second STOP from the same number: no line (the opt-out is not created again);
 *   - a STOP whose conversation store fails (a trigger refuses the message row): "(not stored)";
 *   - the control: the ContactOptOut row exists, the one every send checks.
 * Numbers +256700000101 and +256700000151 (fictitious), in their digits form as Evolution sends them.
 */
function lm_g01(QfCtx $ctx): void
{
    $ctx->run('G01 LN-01 evo_webhook.php: a STOP through the served webhook, PHP\'s log', function () use ($ctx) {
        $secret = 'lm-webhook-secret-test';
        [$plug, $data] = lm_cli_box($ctx, QF_UG + ['evo_webhook_secret' => $secret, 'evo_api_url' => 'http://127.0.0.1:9',
                                                  'evo_api_key' => 'fake-key-lm'], 'g01');
        $err = $ctx->dir('g01-log') . '/php.log';
        touch($err);
        [$base] = lm_serve_root($plug, $err, function (int $p): bool {
            return qf_http('GET', "http://127.0.0.1:{$p}/evo_webhook.php", null, [], 3)[0] === 405;
        }, 14200, ['DN_VAULT_FILE' => (string)getenv('DN_VAULT_FILE')], ['disable_functions' => 'exec']);   // every STOP is queued for a reply; no worker spawned
        $ctx->t($base !== '', 'G01 control: the webhook is served from a copy of the judged tree');
        if ($base === '') return;
        $post = function (string $digits, string $text, string $id) use ($base, $secret): array {
            return qf_http('POST', $base . '/evo_webhook.php', ['event' => 'messages.upsert', 'instance' => 'DishNet-UG', 'data' => [
                'key' => ['remoteJid' => $digits . '@s.whatsapp.net', 'fromMe' => false, 'id' => $id],
                'message' => ['conversation' => $text], 'messageTimestamp' => time(), 'pushName' => 'Test Stopper']],
                ['X-DishNet-Token: ' . $secret]);
        };
        $lines = function () use ($err): array { return lm_lines((string)@file_get_contents($err)); };
        $fixed = LM_LINES['LN-01']['fixed'];
        $count = function () use ($lines, $fixed): int {
            return count(array_filter($lines(), function ($l) use ($fixed) { return strpos($l, $fixed) !== false; }));
        };
        $n1 = lm_forms(lm_num(1))[1];
        $n2 = lm_forms(lm_num(51))[1];

        $r = $post($n1, 'STOP', 'LM-G01-STOP-1');
        $ctx->eq($r[0], 200, 'LN-01 control: the STOP is answered 200');
        $pdo = lm_pdo($data);
        $st = $pdo->prepare('SELECT COUNT(*) FROM contact_optouts WHERE phone = ? AND active = 1');
        $st->execute([$n1]);
        $ctx->eq((int)$st->fetchColumn(), 1, 'LN-01 control: the ContactOptOut row exists — the opt-out every send checks');
        // the COUNT(*) cursor left open keeps this connection on a WAL read snapshot; the next STOP writes, and the trigger below
        // would then be refused at once (SQLITE_BUSY_SNAPSHOT, "database is locked") — this connection, not the webhook, held it
        $st->closeCursor();
        $found = lm_judge($ctx, 'LN-01', $lines(), $fixed, [lm_num(1)], 1, 'a STOP on a stored conversation');
        $ctx->t(count($found) === 1 && preg_match('/opt-out recorded for conversation #\d+ \(matched "stop"\)$/', $found[0]) === 1,
            'LN-01 exactly one line "opt-out recorded for conversation #<id> (matched "stop")"', implode(' | ', $found));

        $post($n1, 'STOP', 'LM-G01-STOP-2');
        $ctx->eq($count(), 1, 'LN-01 a second STOP from the same number writes no line (the opt-out is not created again)');

        $pdo->exec("CREATE TRIGGER lm_g01_store_fails BEFORE INSERT ON wa_messages BEGIN SELECT RAISE(ABORT, 'lm test: the store fails'); END");
        $post($n2, 'STOP', 'LM-G01-STOP-3');
        $pdo->exec('DROP TRIGGER IF EXISTS lm_g01_store_fails');
        $all = lm_judge($ctx, 'LN-01', $lines(), $fixed, [lm_num(1), lm_num(51)], 2, 'with a STOP whose store fails');
        $ctx->eq(count(array_filter($all, function ($l) { return strpos($l, 'opt-out recorded for conversation (not stored) (matched "stop")') !== false; })), 1,
            'LN-01 a STOP whose conversation store fails writes "(not stored)"');
    });
}

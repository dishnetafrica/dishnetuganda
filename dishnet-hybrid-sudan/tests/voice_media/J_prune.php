<?php
declare(strict_types=1);
/**
 * Section J of tests/test_voice_media.php — tools/wa_prune_history.php takes a pruned conversation's voice-note records
 * with it, inside its transaction, and only where wa_media exists (5.18.104, root docs/73 §H.3.21; V36). TEST ONLY.
 * Its new line prints only when it deleted rows, so tests/test_wa_prune.php's expectations are unchanged.
 */
$VM_SECTIONS['J'] = ['title' => 'The prune: a conversation\'s voice-note records go with it', 'run' => function (QfCtx $ctx): void {
    vm_load($ctx);
    $prune = function (array $b, string $mode) use ($ctx): array {
        $o = [];
        exec('DN_VAULT_FILE=' . escapeshellarg((string)getenv('DN_VAULT_FILE')) . ' php ' . escapeshellarg($ctx->tree() . '/tools/wa_prune_history.php')
            . ' ' . $mode . ' --data ' . escapeshellarg($b['data']) . ' 2>&1', $o, $rc);
        return [$rc, implode("\n", $o)];
    };
    $seed = function (array $b): void {
        foreach (['256771300801', '249912300801'] as $i => $phone) {
            vm_voice($b, $phone);
        }
    };

    $ctx->run('J01 control and case: --delete-all deletes every wa_media row with its conversation (V36)', function () use ($ctx, $prune, $seed) {
        $b = vm_book($ctx, vm_cfg('http://127.0.0.1:9'));
        $seed($b);
        [$rc, $out] = $prune($b, '--delete-all');
        $ctx->eq([$rc, (int)vm_q($b, 'SELECT COUNT(*) n FROM wa_media')[0]['n'], (int)vm_q($b, 'SELECT COUNT(*) n FROM wa_conversations')[0]['n']], [0, 0, 0],
                 'J01 no conversation and no voice-note record left', $out);
        $ctx->t(strpos($out, 'and 2 voice-note record(s) with them.') !== false, 'J01 the line names the count', $out);
    });

    $ctx->run('J02 --delete-sudan reaches only Sudan\'s conversations\' rows', function () use ($ctx, $prune, $seed) {
        $b = vm_book($ctx, vm_cfg('http://127.0.0.1:9'));
        $seed($b);
        [$rc, $out] = $prune($b, '--delete-sudan');
        $left = vm_q($b, 'SELECT c.phone FROM wa_media m JOIN wa_conversations c ON c.id = m.conversation_id');
        $ctx->eq([$rc, array_column($left, 'phone'), (int)vm_q($b, 'SELECT COUNT(*) n FROM wa_media')[0]['n']], [0, ['256771300801'], 1], 'J02 the Uganda conversation\'s row stays', $out);
    });

    $ctx->run('J03 no wa_media rows to delete: no new line; no wa_media table: no failure', function () use ($ctx, $prune) {
        $b = vm_book($ctx, vm_cfg('http://127.0.0.1:9'));
        (new ConversationService($b['data'], $b['pdo']))->ensureConversation('256771300803', 'sales');
        [$rc, $out] = $prune($b, '--delete-all');
        $ctx->eq([$rc, strpos($out, 'voice-note') === false, strpos($out, 'deleted 1 conversation(s)') !== false], [0, true, true], 'J03 rows deleted, no voice-note line', $out);
        $b2 = vm_book($ctx, vm_cfg('http://127.0.0.1:9'));
        (new ConversationService($b2['data'], $b2['pdo']))->ensureConversation('256771300813', 'sales');
        $b2['pdo']->exec('DROP TABLE wa_media');
        [$rc2, $out2] = $prune($b2, '--delete-all');
        $ctx->eq([$rc2, strpos($out2, 'deleted 1 conversation(s)') !== false, strpos($out2, 'voice-note') === false], [0, true, true], 'J03 a book without wa_media is pruned as before', $out2);
    });
}];

<?php
declare(strict_types=1);
/** Weakened copies for section J of tests/test_voice_media.php (5.18.104, root docs/73 §H.3.24: V36's two forms). TEST ONLY. */
$PR = 'tools/wa_prune_history.php';
$M = array_merge($M, [
    ['label' => 'V36a the prune leaves wa_media rows', 'file' => $PR,
     'old' => '    $vm = (int)$pdo->exec("DELETE FROM wa_media WHERE conversation_id IN (SELECT id FROM wa_conversations WHERE {$where})");', 'new' => '    $vm = 0;',
     'sections' => 'J', 'cases' => 'J01', 'pattern' => '/J01 /'],
    ['label' => 'V36b the prune failing on a book without wa_media', 'file' => $PR,
     'old' => "if (\$pdo->query(\"SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'wa_media'\")->fetchColumn() !== false) {", 'new' => 'if (true) {',
     'sections' => 'J', 'cases' => 'J03', 'pattern' => '/J03 a book without/'],
    ['label' => 'J-LINE the new line printed when nothing was deleted', 'file' => $PR,
     'old' => 'if ($vm > 0) echo "  and {$vm} voice-note record(s) with them.\n";', 'new' => 'echo "  and {$vm} voice-note record(s) with them.\n";',
     'sections' => 'J', 'cases' => 'J03', 'pattern' => '/J03 rows deleted/'],
]);

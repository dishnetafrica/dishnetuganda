<?php
declare(strict_types=1);
/**
 * Section K of tests/test_voice_media.php — the hand-over moved into lib/Handover.php changes no statement (5.18.104,
 * root docs/73 §H.3.2 "byte for byte", §H.3.26 "The Handover move changes no statement"; V34). TEST ONLY.
 * The same three hand-overs (one, the same again, one already answered), with and without a salesperson's own number,
 * are run through lib/Handover.php of the tree under test and through AiReplyWorker::escalate of 221c9dc, taken from
 * Git, each in its own process against the fake Evolution; what each leaves must be equal: needs_human, the
 * wa.escalation payloads, the alerts (keys, numbers and texts), the holding line once and the row it is stored as.
 */
$VM_SECTIONS['K'] = ['title' => 'The hand-over moved into lib/Handover.php: the same statements as 221c9dc\'s escalate', 'run' => function (QfCtx $ctx): void {
    $evo  = qf_fake_evo();
    $base = vm_base($ctx);
    $ctx->t($evo !== '' && $base !== null, 'K00 the fake Evolution started and Git supplied 221c9dc');
    if ($evo === '' || $base === null) return;
    $drive = function (string $tree, string $mode, bool $owner) use ($ctx, $evo): array {
        qf_http('GET', $evo . '/__test/reset');
        $data = $ctx->dir('ho-' . $mode);
        $o = [];
        exec('DN_VAULT_FILE=' . escapeshellarg((string)getenv('DN_VAULT_FILE')) . ' php ' . escapeshellarg(__DIR__ . '/handover_drive.php')
            . ' --tree=' . escapeshellarg($tree) . ' --mode=' . $mode . ' --data=' . escapeshellarg($data) . ' --evo=' . escapeshellarg($evo)
            . ' --owner=' . ($owner ? '1' : '0') . ' 2>&1', $o, $rc);
        $j = json_decode((string)end($o), true);
        return is_array($j) ? $j : ['_rc' => $rc, '_out' => implode("\n", array_slice($o, -10))];
    };
    foreach (['K01 a department number' => false, 'K02 a salesperson\'s own number, handed over to its owner' => true] as $label => $owner) {
        $ctx->run($label, function () use ($ctx, $drive, $base, $owner, $label) {
            $now  = $drive($ctx->tree(), 'lib', $owner);
            $then = $drive($base, 'worker', $owner);
            $ctx->t(isset($then['sends']) && count($then['sends']) >= 2 && isset($then['events']) && count($then['events']) === 3,
                    substr($label, 0, 3) . ' control: 221c9dc\'s escalate ran and did its work (alerts, a holding line, three escalations)', json_encode($then));
            $nums = array_map(function ($c) { return $c[1]; }, (array)($then['sends'] ?? []));
            $ctx->eq(in_array('256700000777', $nums, true), $owner, substr($label, 0, 3) . ' control: the owner is alerted exactly when the number is the owner\'s');
            foreach (['states', 'events', 'sends', 'stored', 'alerts'] as $k) {
                $ctx->eq($now[$k] ?? null, $then[$k] ?? null, substr($label, 0, 3) . ' the same ' . $k . ' as 221c9dc\'s escalate');
            }
        });
    }
}];

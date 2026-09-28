<?php
$n = (int)($argv[1] ?? 0); $f = $argv[2] ?? '/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/webhook_log.json';
$l = json_decode((string)@file_get_contents($f), true);
if (!is_array($l) || !$l) { echo "no webhook log at $f\n"; exit(1); }
echo "webhook log: ", count($l), " entries, ", (end($l)['received_at'] ?? '?'), " to ", (reset($l)['received_at'] ?? '?'), "\n";
$hit = 0; $keep = false; $t0 = 0;
foreach (array_reverse($l) as $e) {
    $m = (string)($e['message'] ?? ''); $d = (array)($e['data'] ?? []); $t = (int)strtotime((string)($e['received_at'] ?? ''));
    if (strpos($m, 'Received UCRM webhook') === 0) {
        $keep = (int)($d['entity_id'] ?? 0) === $n && strpos($m, 'Received UCRM webhook: job.') === 0; $t0 = $t;
    }
    $mine = preg_match("/^job #{$n}\\b/i", $m)
         || ($keep && abs($t - $t0) <= 120 && (strpos($m, 'Received UCRM webhook') === 0 || strpos($m, 'Customer email') === 0));
    if (!$mine) continue;
    $m = preg_replace(['/[^\s<>()]+@[^\s<>()]+/', '/\+\d[\d ()-]{7,}\d/', '/\b\d{9,}\b/', '/(notification sent to|No phone found for) .*/'],
                      ['<e-mail>', '<number>', '<number>', '$1 <name>'], $m);
    echo ($e['received_at'] ?? ''), "  ", ($e['event'] ?? ''), "  ", $m, "\n"; $hit++;
}
if (!$hit) echo "no line for job #$n: nothing arrived for it, or it has left the log (it keeps the newest 300 entries)\n";

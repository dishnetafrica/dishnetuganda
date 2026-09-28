<?php
// Read-only: every job event the plugin logged since a time (the log's own clock, Kampala), and what each one did.
$since = $argv[1] ?? '2026-09-28 12:18:55'; $f = $argv[2] ?? '/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/webhook_log.json';
$l = json_decode((string)@file_get_contents($f), true);
if (!is_array($l) || !$l) { echo "no webhook log at $f\n"; exit(1); }
echo "webhook log: ", count($l), " entries, ", (end($l)['received_at'] ?? '?'), " to ", (reset($l)['received_at'] ?? '?'), "; showing job events since $since\n";
$hit = 0; $keep = false; $t0 = 0;
foreach (array_reverse($l) as $e) {
    $at = (string)($e['received_at'] ?? ''); if ($at < $since) continue;
    $m = (string)($e['message'] ?? ''); $d = (array)($e['data'] ?? []); $t = (int)strtotime($at);
    if (strpos($m, 'Received UCRM webhook') === 0) { $keep = strpos($m, 'Received UCRM webhook: job.') === 0; $t0 = $t;
        if ($keep) $m .= '  (job #' . (int)($d['entity_id'] ?? 0) . ')'; }
    $mine = preg_match('/^job #\d+\b/i', $m) || ($keep && abs($t - $t0) <= 120
         && (strpos($m, 'Received UCRM webhook') === 0 || strpos($m, 'Customer email') === 0 || strpos($m, 'Unhandled event type') === 0));
    if (!$mine) continue;
    $m = preg_replace(['/[^\s<>()]+@[^\s<>()]+/', '/\+\d[\d ()-]{7,}\d/', '/\b\d{9,}\b/', '/(notification sent to|No phone found for) .*/'],
                      ['<e-mail>', '<number>', '<number>', '$1 <name>'], $m);
    echo $at, "  ", ($e['event'] ?? ''), "  ", $m, "\n"; $hit++;
}
if (!$hit) echo "no job event since $since: uCRM has sent the plugin no job change since then\n";

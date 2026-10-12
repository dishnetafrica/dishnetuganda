<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * media_status.php — what the media path did, in COUNTS (Batch 5 of the AI communication layer, docs/60 §4).
 *
 *   php tools/media_status.php              the last 7 days
 *   php tools/media_status.php --days 30    a longer window
 *   php tools/media_status.php --json       the same numbers as one JSON object, for a scheduled copy
 *
 * Read-only: the database is opened read-only and nothing is written anywhere. It answers the operating questions of
 * docs/59 §5.2 and docs/60 §4 from the tables the path already keeps — wa_messages (documents received), wa_media (every
 * fetch and every reading, by status and reason), the stored message's metadata (the classification, the mode it ran under,
 * the time it took) and the events table (the AI turns queued, the hand-overs, the dead letters, the stale locks).
 *
 * What it NEVER prints: a document's text or excerpt, bytes or base64, a file name, a caption, a customer's number or JID,
 * a hash, a URL, a key, a reply. Only those columns are ever selected; the output is codes, counts, durations and dates.
 * A window with nothing in it prints zeros, which is the truth and not a fault.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/MediaPolicy.php';
require_once $root . '/lib/MediaFetcher.php';
require_once $root . '/lib/DocumentClassifier.php';
require_once $root . '/lib/DocumentExtraction.php';

$days = 7; $json = false;
$args = array_slice($argv ?? [], 1);
for ($i = 0; $i < count($args); $i++) {
    $a = (string)$args[$i];
    if ($a === '--json') { $json = true; continue; }
    if ($a === '--days' && isset($args[$i + 1])) { $days = (int)$args[++$i]; continue; }
    if (strpos($a, '--days=') === 0) { $days = (int)substr($a, 7); continue; }
    if ($a === '--help' || $a === '-h') { echo "usage: php tools/media_status.php [--days N] [--json]\n"; exit(0); }
    fwrite(STDERR, "unknown argument: {$a}\n"); exit(1);
}
if ($days < 1 || $days > 3650) { fwrite(STDERR, "--days must be between 1 and 3650\n"); exit(1); }

$dataDir = cliDataDir($root);
$dbPath  = $dataDir . '/plugin.sqlite3';
if (!is_file($dbPath)) { fwrite(STDERR, "No database at {$dataDir}\n"); exit(2); }
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$config = PluginConfig::load($root, $dataDir);

$since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
$flagWord = function (string $key) use ($config): string {
    return in_array(strtolower(trim((string)($config[$key] ?? ''))), ['1', 'true', 'on', 'yes'], true) ? 'on' : 'off';
};
$out = [
    'window_days' => $days, 'since_utc' => $since,
    'mode' => MediaPolicy::documentMode($config),
    'flags' => ['ai_media_enabled' => $flagWord('ai_media_enabled'), 'ai_media_document' => $flagWord('ai_media_document'),
                'ai_media_document_handover' => $flagWord('ai_media_document_handover'), 'ai_media_document_reply' => $flagWord('ai_media_document_reply'),
                'ai_document_provider' => (string)($config['ai_document_provider'] ?? 'none') ?: 'none'],
];

$count = function (string $sql, array $p) use ($pdo): int { try { $st = $pdo->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); } catch (\Throwable $e) { return -1; } };
$pct = function (array $vals, float $q): int { if ($vals === []) return 0; sort($vals); return (int)$vals[max(0, min(count($vals) - 1, (int)ceil($q * count($vals)) - 1))]; };

// ── Documents received: what the webhook stored, flags or no flags ──────────────────────────────────────────────────────
$out['documents_received'] = $count("SELECT COUNT(*) FROM wa_messages WHERE direction = 'in' AND media_type = 'document' AND sent_at >= ?", [$since]);

// ── Every media row in the window: status and reason, by kind; only the columns a count needs ──────────────────────────
$fetchReasons = array_keys(MediaFetcher::REASONS);
$doc = ['recorded' => 0, 'fetch_ok' => 0, 'fetch_failed' => 0, 'fetch_reasons' => [], 'unsupported' => 0, 'skipped' => 0, 'dead' => 0, 'pending' => 0, 'in_progress' => 0,
        'understood' => 0, 'extraction_failed' => 0, 'extraction_reasons' => [], 'worker_lost' => 0, 'rows_retried' => 0, 'max_attempts_on_a_row' => 0, 'settled_seconds' => []];
$other = [];
$haveMedia = true;
$WORKER_LOST = 'worker_lost';   // MediaWorker::REASON_WORKER_LOST, named here so the worker class is not loaded by a status tool
try {
    $st = $pdo->prepare('SELECT kind, status, failure_reason, attempts, created_at, updated_at FROM wa_media WHERE created_at >= ?');
    $st->execute([$since]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $kind = (string)$r['kind']; $status = (string)$r['status']; $reason = (string)($r['failure_reason'] ?? '');
        if ($kind !== 'document') {
            $other[$kind] = $other[$kind] ?? ['recorded' => 0, 'fetched' => 0, 'failed' => 0, 'unsupported' => 0, 'skipped' => 0, 'dead' => 0];
            $other[$kind]['recorded']++;
            $slot = in_array($status, ['fetched', 'understood'], true) ? 'fetched' : (isset($other[$kind][$status]) ? $status : 'failed');
            $other[$kind][$slot]++;
            continue;
        }
        $doc['recorded']++;
        $att = (int)$r['attempts'];
        if ($att > 1) $doc['rows_retried']++;
        $doc['max_attempts_on_a_row'] = max($doc['max_attempts_on_a_row'], $att);
        if ($reason === $WORKER_LOST) $doc['worker_lost']++;
        switch ($status) {
            case 'understood':  $doc['fetch_ok']++; $doc['understood']++; break;
            case 'fetched':     $doc['fetch_ok']++; break;
            case 'extracting':  $doc['fetch_ok']++; $doc['in_progress']++; break;
            case 'unsupported': $doc['unsupported']++; $doc['fetch_reasons'][$reason ?: 'unsupported'] = ($doc['fetch_reasons'][$reason ?: 'unsupported'] ?? 0) + 1; break;
            case 'skipped':     $doc['skipped']++; break;
            case 'dead':        $doc['dead']++; break;
            case 'pending': case 'fetching': $doc[$status === 'pending' ? 'pending' : 'in_progress']++; break;
            default:            // failed: a fetch reason or an extraction reason
                if (in_array($reason, $fetchReasons, true) || $reason === '') { $doc['fetch_failed']++; $doc['fetch_reasons'][$reason ?: 'fetch_failed'] = ($doc['fetch_reasons'][$reason ?: 'fetch_failed'] ?? 0) + 1; }
                else { $doc['fetch_ok']++; $doc['extraction_failed']++; $doc['extraction_reasons'][$reason] = ($doc['extraction_reasons'][$reason] ?? 0) + 1; }
        }
        if (in_array($status, ['understood', 'failed', 'unsupported', 'dead', 'skipped'], true)) {
            $a = strtotime((string)$r['created_at'] . ' UTC'); $b = strtotime((string)$r['updated_at'] . ' UTC');
            if ($a && $b && $b >= $a) $doc['settled_seconds'][] = $b - $a;
        }
    }
} catch (\Throwable $e) {
    $haveMedia = false;
}

// ── The classification, the mode and the time: the stored message's metadata, never its body ─────────────────────────────
$cls = ['by_class' => [], 'human_only' => 0, 'harmless' => 0, 'by_mode' => ['dry_run' => 0, 'handover' => 0, 'reply' => 0], 'ms' => [], 'body_rewritten' => 0];
if ($haveMedia) {
    try {
        $st = $pdo->prepare("SELECT m.metadata FROM wa_media w JOIN wa_messages m ON m.id = w.message_id
                             WHERE w.kind = 'document' AND w.created_at >= ? AND w.status = 'understood' AND m.metadata IS NOT NULL");
        $st->execute([$since]);
        while ($meta = $st->fetchColumn()) {
            $d = (json_decode((string)$meta, true) ?: [])['document'] ?? null;
            if (!is_array($d)) continue;
            $c = (string)($d['classification'] ?? '');
            if ($c !== '') $cls['by_class'][$c] = ($cls['by_class'][$c] ?? 0) + 1;
            if (in_array($c, DocumentClassifier::HUMAN_ONLY, true)) $cls['human_only']++;
            if (in_array($c, DocumentClassifier::BRAIN_ELIGIBLE, true)) $cls['harmless']++;
            $m = (string)($d['mode'] ?? '');
            if (isset($cls['by_mode'][$m])) $cls['by_mode'][$m]++;
            if (isset($d['ms'])) $cls['ms'][] = (int)$d['ms'];
            if (!empty($d['body_rewritten'])) $cls['body_rewritten']++;
        }
    } catch (\Throwable $e) { /* older stored messages carry no metadata; counts stay as they are */ }
}

// ── The queue: AI turns queued for documents, hand-overs by the media worker, dead letters, stale locks ──────────────────
$q = ['ai_turns_queued_for_documents' => 0, 'handovers_by_media_worker' => 0, 'ai_media_pending' => 0, 'ai_media_failed' => 0, 'ai_media_dead' => 0, 'ai_media_done' => 0,
      'ai_media_stale_lock_releases' => 0, 'ai_media_events_retried' => 0];
try {
    $st = $pdo->prepare("SELECT event_type, status, created_by, attempts, error, payload FROM events
                         WHERE event_type IN ('ai.media', 'ai.reply', 'wa.escalation') AND created_at >= ?");
    $st->execute([$since]);
    while ($e = $st->fetch(PDO::FETCH_ASSOC)) {
        $type = (string)$e['event_type']; $by = (string)($e['created_by'] ?? '');
        if ($type === 'ai.reply') { if ($by === 'media_worker' && strpos((string)$e['payload'], '"origin":"document"') !== false) $q['ai_turns_queued_for_documents']++; continue; }
        if ($type === 'wa.escalation') { if ($by === 'media_worker') $q['handovers_by_media_worker']++; continue; }
        $s = (string)$e['status'];
        if (isset($q['ai_media_' . $s])) $q['ai_media_' . $s]++;
        if ((int)$e['attempts'] > 0) $q['ai_media_events_retried']++;
        if (strpos((string)($e['error'] ?? ''), '[stale lock released]') !== false) $q['ai_media_stale_lock_releases']++;
    }
} catch (\Throwable $e) { /* no events table: nothing to count */ }

$er = $doc['extraction_reasons'];
$out['documents'] = [
    'recorded_for_the_worker' => $haveMedia ? $doc['recorded'] : null,
    'fetch' => ['ok' => $doc['fetch_ok'], 'failed' => $doc['fetch_failed'], 'reasons' => $doc['fetch_reasons'], 'unsupported' => $doc['unsupported'],
                'skipped_flag_off' => $doc['skipped'], 'pending' => $doc['pending'], 'in_progress' => $doc['in_progress'], 'dead' => $doc['dead']],
    'extraction' => ['understood' => $doc['understood'], 'failed' => $doc['extraction_failed'], 'reasons' => $er,
                     'no_text_pdfs' => (int)($er['pdf_no_text'] ?? 0), 'scanned_no_provider' => (int)($er['provider_missing'] ?? 0),
                     'oversized' => (int)($er['too_large_document'] ?? 0) + (int)($doc['fetch_reasons']['too_large'] ?? 0),
                     'encrypted' => (int)($er['password_protected'] ?? 0), 'malformed' => (int)($er['malformed_document'] ?? 0),
                     'unsupported_document' => (int)($er['unsupported_document'] ?? 0) + (int)($er['unsupported_mime'] ?? 0),
                     'too_slow' => (int)($er['too_slow'] ?? 0),
                     'unclassifiable' => (int)($er['classification_uncertain'] ?? 0) + (int)($er['classification_incomplete'] ?? 0) + (int)($er['classification_failed'] ?? 0)],
    'classification' => ['human_only' => $cls['human_only'], 'harmless_ai_eligible' => $cls['harmless'], 'by_class' => $cls['by_class'], 'by_mode' => $cls['by_mode'],
                         'dry_run' => $cls['by_mode']['dry_run'], 'stored_body_rewritten' => $cls['body_rewritten']],
    'ai_turns_queued' => $q['ai_turns_queued_for_documents'],
    'handovers' => $q['handovers_by_media_worker'],
    'retries' => ['rows_with_more_than_one_attempt' => $doc['rows_retried'], 'most_attempts_on_a_row' => $doc['max_attempts_on_a_row'], 'worker_lost' => $doc['worker_lost'],
                  'events_retried' => $q['ai_media_events_retried'], 'stale_lock_releases' => $q['ai_media_stale_lock_releases']],
    'queue' => ['pending' => $q['ai_media_pending'], 'failed' => $q['ai_media_failed'], 'dead' => $q['ai_media_dead'], 'done' => $q['ai_media_done']],
    'duration_ms' => ['classified_p50' => $pct($cls['ms'], 0.5), 'classified_p95' => $pct($cls['ms'], 0.95), 'classified_max' => $cls['ms'] === [] ? 0 : max($cls['ms']),
                      'settled_rows_seconds_p95' => $pct($doc['settled_seconds'], 0.95), 'settled_rows_seconds_max' => $doc['settled_seconds'] === [] ? 0 : max($doc['settled_seconds'])],
];
ksort($other);
$out['other_media_fetch_only'] = $other;
if (!$haveMedia) $out['note'] = 'wa_media is not present: migration 085 has not been applied here';

if ($json) { echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n"; exit(0); }

$kv = function (array $m): string { if ($m === []) return 'none'; $p = []; foreach ($m as $k => $v) $p[] = "{$k} {$v}"; return implode(', ', $p); };
$d = $out['documents'];
printf("Media path — the last %d day(s), UTC, since %s\n", $days, $since);
printf("mode: %s   ai_media_enabled=%s  ai_media_document=%s  ai_media_document_handover=%s  ai_media_document_reply=%s  ai_document_provider=%s\n",
    $out['mode'], $out['flags']['ai_media_enabled'], $out['flags']['ai_media_document'], $out['flags']['ai_media_document_handover'], $out['flags']['ai_media_document_reply'], $out['flags']['ai_document_provider']);
printf("documents received (stored messages):  %d\n", $out['documents_received']);
if (!$haveMedia) { echo "documents recorded for the worker:     wa_media is not present (migration 085 not applied)\n"; exit(0); }
printf("documents recorded for the worker:     %d\n", $d['recorded_for_the_worker']);
printf("  fetch:        ok %d · failed %d (%s) · unsupported %d · skipped (flag off) %d · pending %d · in progress %d · dead %d\n",
    $d['fetch']['ok'], $d['fetch']['failed'], $kv($d['fetch']['reasons']), $d['fetch']['unsupported'], $d['fetch']['skipped_flag_off'], $d['fetch']['pending'], $d['fetch']['in_progress'], $d['fetch']['dead']);
printf("  extraction:   understood %d · failed %d (%s)\n", $d['extraction']['understood'], $d['extraction']['failed'], $kv($d['extraction']['reasons']));
printf("    no-text PDFs %d · scanned, no provider %d · oversized %d · encrypted %d · malformed %d · unsupported %d · too slow %d · unclassifiable %d\n",
    $d['extraction']['no_text_pdfs'], $d['extraction']['scanned_no_provider'], $d['extraction']['oversized'], $d['extraction']['encrypted'], $d['extraction']['malformed'],
    $d['extraction']['unsupported_document'], $d['extraction']['too_slow'], $d['extraction']['unclassifiable']);
printf("  classified:   human-only %d · harmless (AI-eligible) %d · by class: %s\n", $d['classification']['human_only'], $d['classification']['harmless_ai_eligible'], $kv($d['classification']['by_class']));
printf("    by mode: dry run %d · hand-over %d · reply %d · stored body rewritten %d\n", $d['classification']['by_mode']['dry_run'], $d['classification']['by_mode']['handover'], $d['classification']['by_mode']['reply'], $d['classification']['stored_body_rewritten']);
printf("  AI turns queued for documents %d · hand-overs by the media worker %d\n", $d['ai_turns_queued'], $d['handovers']);
printf("  retries:      rows with more than one attempt %d · most attempts on a row %d · worker lost %d · events retried %d · stale lock releases %d\n",
    $d['retries']['rows_with_more_than_one_attempt'], $d['retries']['most_attempts_on_a_row'], $d['retries']['worker_lost'], $d['retries']['events_retried'], $d['retries']['stale_lock_releases']);
printf("  queue:        ai.media pending %d · failed %d · dead %d · done %d\n", $d['queue']['pending'], $d['queue']['failed'], $d['queue']['dead'], $d['queue']['done']);
printf("  duration:     classified p50 %d ms · p95 %d ms · max %d ms · settled rows p95 %d s · max %d s\n",
    $d['duration_ms']['classified_p50'], $d['duration_ms']['classified_p95'], $d['duration_ms']['classified_max'], $d['duration_ms']['settled_rows_seconds_p95'], $d['duration_ms']['settled_rows_seconds_max']);
if ($other === []) { echo "other media (fetch only): none in the window\n"; }
else { $p = []; foreach ($other as $k => $v) $p[] = sprintf('%s %d (fetched %d, failed %d, unsupported %d, skipped %d, dead %d)', $k, $v['recorded'], $v['fetched'], $v['failed'], $v['unsupported'], $v['skipped'], $v['dead']); echo 'other media (fetch only): ', implode(' · ', $p), "\n"; }

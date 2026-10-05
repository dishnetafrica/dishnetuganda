<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkerBase.php';
require_once dirname(__DIR__) . '/lib/MediaPolicy.php';
require_once dirname(__DIR__) . '/lib/MediaFetcher.php';
require_once dirname(__DIR__) . '/lib/EvolutionApiService.php';

/**
 * MediaWorker — fetch and validate the media a customer sent, off the reply path (Batch 1, docs/55 §9).
 *
 * Its own worker, its own lock (WorkerBase names the lock after the class) and its own runner
 * (run_media_worker.php): a voice note that takes twenty seconds to come down from Evolution must never hold up a
 * text reply, and a text reply that is slow must never hold up the media. The two share the EventBus and nothing else.
 *
 * What it does with one ai.media event: read the wa_media row the webhook recorded; refuse to do anything if
 * ai_media_enabled is off (the row is marked skipped and the event acknowledged — nothing is fetched); ignore a row
 * that is already settled (a duplicate event, so one message is never fetched twice); otherwise fetch the file into
 * memory through MediaFetcher, record size, media type and sha256 on the row, and forget the bytes. Nothing is written
 * to disk, nothing is sent to the customer, and nothing is understood yet — Batch 2+ reads `fetched` rows.
 *
 * Failures: a transient one (timeout, 5xx, malformed answer) is thrown, so the EventBus retries it with its backoff and
 * the row says `failed` with the reason; a permanent one (wrong type, too large, no key) is recorded and acknowledged.
 * When the queue gives up (dead), the conversation is marked needs_human so a person sees it in the inbox — the same
 * signal AiReplyWorker gives when it cannot answer. No message is sent either way. PHP 7.4 compatible.
 */
final class MediaWorker extends WorkerBase
{
    /** Rows in one of these states are settled: a second event for the same message changes nothing. */
    public const SETTLED = ['fetched', 'understood', 'unsupported', 'skipped', 'dead'];

    /** @var MediaFetcher|null a fetcher injected by a test; otherwise built per event from the configuration */
    private $fetcher = null;

    protected function getEventTypes(): array
    {
        return ['ai.media'];
    }

    /** For tests: a fetcher that never opens a socket. Production never calls this. */
    public function useFetcher(MediaFetcher $fetcher): void
    {
        $this->fetcher = $fetcher;
    }

    protected function handle(array $event): void
    {
        $p       = self::payloadOf($event);
        $mediaId = (int)($p['media_id'] ?? 0);
        if ($mediaId <= 0) {
            $this->log('warn', 'ai.media with no media_id — dropped');
            return;
        }
        $row = $this->row($mediaId);
        if ($row === null) {
            $this->log('warn', sprintf('media #%d: no wa_media row — dropped', $mediaId));
            return;
        }

        // The switch, read on every event: a row recorded while the flag was on is not fetched after it goes off.
        if (!MediaPolicy::enabled($this->config)) {
            if (!in_array((string)$row['status'], self::SETTLED, true)) {
                $this->update($mediaId, ['status' => 'skipped', 'failure_reason' => 'media_disabled']);
            }
            $this->log('info', sprintf('media #%d: ai_media_enabled is off — nothing fetched, row skipped', $mediaId));
            return;
        }

        // Idempotency: one WhatsApp message is fetched once, however many events name it.
        if (in_array((string)$row['status'], self::SETTLED, true)) {
            $this->log('info', sprintf('media #%d: already %s — duplicate event, nothing fetched', $mediaId, (string)$row['status']));
            return;
        }

        $this->update($mediaId, ['status' => 'fetching', 'attempts' => (int)$row['attempts'] + 1]);
        $fetcher = $this->fetcher ?? new MediaFetcher(
            new EvolutionApiService($this->config, MediaPolicy::timeoutSeconds($this->config)), $this->config);
        $res = $fetcher->fetch($row);

        if (!empty($res['ok'])) {
            /** @var MediaBlob $blob */
            $blob = $res['blob'];
            $this->update($mediaId, [
                'status'           => 'fetched',
                'fetched_bytes'    => $blob->size,
                'fetched_mimetype' => $blob->mimetype,
                'sha256'           => $blob->sha256,
                'failure_reason'   => null,
            ]);
            // The bytes end here. What the log says about them is the kind, type, size and a hash prefix — never content.
            $this->log('info', sprintf('media #%d (conversation %d): fetched %s', $mediaId, (int)$row['conversation_id'], $blob->describe()));
            $blob->wipe();
            unset($blob, $res);
            return;
        }

        $reason = (string)($res['reason'] ?? 'fetch_failed');
        $detail = (string)($res['detail'] ?? '');
        if (!empty($res['retryable'])) {
            $this->update($mediaId, ['status' => 'failed', 'failure_reason' => $reason]);
            // Thrown, so the EventBus retries with its backoff. The message names the row and the code, nothing else.
            throw new \RuntimeException(sprintf('media #%d fetch %s (%s)', $mediaId, $reason, $detail));
        }
        $status = in_array($reason, ['unsupported_kind', 'unsupported_mime'], true) ? 'unsupported' : 'failed';
        $this->update($mediaId, ['status' => $status, 'failure_reason' => $reason]);
        $this->log('info', sprintf('media #%d: %s — %s (%s); not retried', $mediaId, $status, $reason, $detail));
    }

    /**
     * The queue has given up on this fetch. The row says dead; the conversation is handed to a person, as AiReplyWorker
     * does when it cannot answer. Nothing is sent to the customer.
     */
    protected function onDead(array $event, \Throwable $e): void
    {
        $p       = self::payloadOf($event);
        $mediaId = (int)($p['media_id'] ?? 0);
        if ($mediaId <= 0) return;
        $row = $this->row($mediaId);
        $this->update($mediaId, ['status' => 'dead']);
        $convId = (int)($row['conversation_id'] ?? ($p['conversation_id'] ?? 0));
        if ($convId > 0) {
            try {
                $this->pdo->prepare("UPDATE wa_conversations SET state = 'needs_human', updated_at = datetime('now') WHERE id = ?")
                          ->execute([$convId]);
            } catch (\Throwable $ignore) {
            }
        }
        $this->log('error', sprintf('media #%d (conversation %d) could not be fetched after every attempt — handed to a person: %s',
            $mediaId, $convId, $e->getMessage()));
    }

    private function row(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM wa_media WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return is_array($r) ? $r : null;
    }

    private function update(int $id, array $fields): void
    {
        $set = []; $args = [];
        foreach ($fields as $k => $v) { $set[] = $k . ' = ?'; $args[] = $v; }
        $set[]  = "updated_at = datetime('now')";
        $args[] = $id;
        $this->pdo->prepare('UPDATE wa_media SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    }

    /** The event's payload however it arrived: decoded by WorkerBase, already an array, or the stored JSON string. */
    private static function payloadOf(array $event): array
    {
        if (is_array($event['_payload'] ?? null) && $event['_payload'] !== []) return $event['_payload'];
        $raw = $event['payload'] ?? null;
        if (is_array($raw)) return $raw;
        if (is_string($raw) && $raw !== '') { $d = json_decode($raw, true); return is_array($d) ? $d : []; }
        return [];
    }
}

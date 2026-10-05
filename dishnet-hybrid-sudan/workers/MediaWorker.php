<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkerBase.php';
require_once dirname(__DIR__) . '/lib/MediaPolicy.php';
require_once dirname(__DIR__) . '/lib/MediaFetcher.php';
require_once dirname(__DIR__) . '/lib/EvolutionApiService.php';
require_once dirname(__DIR__) . '/lib/Transcriber.php';
require_once dirname(__DIR__) . '/lib/VoiceTranscription.php';
require_once dirname(__DIR__) . '/lib/Handover.php';
require_once dirname(__DIR__) . '/lib/ConversationService.php';

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
 *
 * Batch 2 (docs/55 §9, docs/56): a VOICE NOTE goes one step further, only while ai_media_voice is on as well. With the
 * bytes still in memory, VoiceTranscription asks the one configured provider for the transcript, labels it, writes it on
 * the row and on the stored message, runs the existing STOP detection, and queues ONE ordinary ai.reply event — the
 * existing assistant answers it exactly as it answers typed text. A retryable failure is thrown (the EventBus retries;
 * the retry fetches again, since nothing was kept); a permanent one, or the queue giving up, hands the conversation to a
 * person through lib/Handover.php — the same path AiReplyWorker uses — and never answers from a guess.
 */
final class MediaWorker extends WorkerBase
{
    /** Rows in one of these states are settled: a second event for the same message changes nothing. */
    public const SETTLED = ['fetched', 'understood', 'unsupported', 'skipped', 'dead'];

    /** @var MediaFetcher|null a fetcher injected by a test; otherwise built per event from the configuration */
    private $fetcher = null;
    /** @var TranscriberPort|null a transcriber injected by a test; otherwise TranscriberFactory::fromConfig() */
    private $transcriber = null;
    /** @var bool */
    private $transcriberInjected = false;
    /** @var EvolutionApiService|null */
    private $evoClient = null;

    protected function getEventTypes(): array
    {
        return ['ai.media'];
    }

    /** For tests: a fetcher that never opens a socket. Production never calls this. */
    public function useFetcher(MediaFetcher $fetcher): void
    {
        $this->fetcher = $fetcher;
    }

    /** For tests: the transcriber to use (null = none configured). Production builds it from the configuration. */
    public function useTranscriber(?TranscriberPort $transcriber): void
    {
        $this->transcriber         = $transcriber;
        $this->transcriberInjected = true;
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

        // Idempotency: one WhatsApp message is fetched once, however many events name it. Batch 2: a fetched voice
        // note whose transcript is still to come is not settled while ai_media_voice is on — the bytes were not kept,
        // so this attempt fetches them again and transcribes; once `understood` it is settled for good.
        $settled = in_array((string)$row['status'], self::SETTLED, true);
        if ($settled && (string)$row['status'] === 'fetched' && $this->voiceWanted($row)
            && trim((string)($row['understanding'] ?? '')) === '') {
            $settled = false;
        }
        if ($settled) {
            $this->log('info', sprintf('media #%d: already %s — duplicate event, nothing fetched', $mediaId, (string)$row['status']));
            return;
        }

        $this->update($mediaId, ['status' => 'fetching', 'attempts' => (int)$row['attempts'] + 1]);
        $fetcher = $this->fetcher ?? new MediaFetcher($this->evoClient(), $this->config);
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
            // Batch 2 (docs/55 §9, docs/56): a voice note goes on to transcription while the bytes are still in memory.
            if ($this->voiceWanted($row)) {
                $this->transcribe($mediaId, $blob);   // wipes the blob, whatever happens
                return;
            }
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
        // Batch 2: a voice note that will never be fetched is a customer who spoke and would hear nothing — a person.
        if ($this->voiceWanted($row)) {
            $this->handover($row, 'a voice message could not be fetched (' . $reason . ') — listen to it in WhatsApp');
        }
    }

    /** Batch 2: is this row a voice note that ai_media_voice (with ai_media_enabled) wants transcribed? */
    private function voiceWanted(array $row): bool
    {
        return (string)($row['kind'] ?? '') === 'audio' && MediaPolicy::voiceEnabled($this->config);
    }

    /**
     * Batch 2 (docs/55 §9, docs/56): the fetched voice note becomes the customer's turn, through VoiceTranscription.
     * The blob is wiped here whatever happens. A retryable failure is thrown so the EventBus retries the event; a
     * permanent one hands the conversation to a person and is acknowledged. The log never carries the transcript.
     */
    private function transcribe(int $mediaId, MediaBlob $blob): void
    {
        $row = $this->row($mediaId) ?? [];
        $svc = new VoiceTranscription($this->pdo, $this->store, $this->config, $this->transcriberFor(),
            function (string $level, string $message): void { $this->log($level, $message); });
        try {
            $r = $svc->process($row, $blob);
        } finally {
            $blob->wipe();
        }
        $outcome = (string)($r['outcome'] ?? 'failed');
        if ($outcome === 'understood') {
            $this->log('info', sprintf('media #%d (conversation %d): voice note transcribed — %d characters, ai.reply #%d queued',
                $mediaId, (int)($row['conversation_id'] ?? 0), (int)($r['chars'] ?? 0), (int)($r['event_id'] ?? 0)));
            return;
        }
        if ($outcome === 'already_understood') {
            $this->log('info', sprintf('media #%d: already understood — nothing queued twice', $mediaId));
            return;
        }
        $reason = (string)($r['reason'] ?? 'provider_error');
        $detail = (string)($r['detail'] ?? '');
        $this->update($mediaId, ['status' => 'failed', 'failure_reason' => $reason]);
        if (!empty($r['retryable'])) {
            throw new \RuntimeException(sprintf('media #%d transcription %s (%s)', $mediaId, $reason, $detail));
        }
        $this->log('warn', sprintf('media #%d: voice note not transcribed — %s (%s); handed to a person, nothing answered',
            $mediaId, $reason, $detail));
        $this->handover($row, 'a voice message could not be transcribed (' . $reason . ') — listen to it in WhatsApp');
    }

    /** The one hand-over path (lib/Handover.php): needs_human, the wa.escalation event, the staff alert, the holding line once. */
    private function handover(array $row, string $reason): void
    {
        $convId = (int)($row['conversation_id'] ?? 0);
        $phone  = '';
        if ($convId > 0) {
            $q = $this->pdo->prepare('SELECT phone FROM wa_conversations WHERE id = ?');
            $q->execute([$convId]);
            $phone = (string)($q->fetchColumn() ?: '');
        }
        if ($convId <= 0 || $phone === '') {
            $this->log('warn', sprintf('media #%d: no conversation to hand over', (int)($row['id'] ?? 0)));
            return;
        }
        $dataDir = dirname((string)($this->pdo->query('PRAGMA database_list')->fetch()['file'] ?? sys_get_temp_dir()));
        Handover::escalate($this->pdo, $this->bus, $this->store, $this->config, $this->evoClient(),
            new ConversationService($dataDir, $this->pdo), $convId, (string)($row['channel'] ?? ''), $phone, $reason, false,
            function (string $level, string $message): void { $this->log($level, $message); }, 'media_worker');
    }

    private function evoClient(): EvolutionApiService
    {
        if ($this->evoClient === null) {
            $this->evoClient = new EvolutionApiService($this->config, MediaPolicy::timeoutSeconds($this->config));
        }
        return $this->evoClient;
    }

    private function transcriberFor(): ?TranscriberPort
    {
        return $this->transcriberInjected ? $this->transcriber : TranscriberFactory::fromConfig($this->config);
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
        // Batch 2: a voice note the queue gave up on is handed to a person through the full path — the alert and the
        // holding line too, not only the inbox mark — because the customer spoke and has heard nothing.
        if (is_array($row) && $this->voiceWanted($row)) {
            $this->handover($row, 'a voice message could not be transcribed after every attempt — listen to it in WhatsApp');
            $this->log('error', sprintf('media #%d (conversation %d): voice note given up after every attempt — handed to a person: %s',
                $mediaId, $convId, $e->getMessage()));
            return;
        }
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

<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkerBase.php';
require_once dirname(__DIR__) . '/lib/MediaPolicy.php';
require_once dirname(__DIR__) . '/lib/MediaFetcher.php';
require_once dirname(__DIR__) . '/lib/EvolutionApiService.php';
require_once dirname(__DIR__) . '/lib/Transcriber.php';
require_once dirname(__DIR__) . '/lib/VoiceTranscription.php';
require_once dirname(__DIR__) . '/lib/ImageDescriber.php';
require_once dirname(__DIR__) . '/lib/ImageUnderstanding.php';
require_once dirname(__DIR__) . '/lib/DocumentOcr.php';
require_once dirname(__DIR__) . '/lib/DocumentExtraction.php';
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
 *
 * Batch 3 (docs/55 §9, docs/57): a PICTURE goes the same way, only while ai_media_image is on as well. ImageUnderstanding
 * reads the header and the caps before any provider, asks the one configured provider for a description, and then
 * EITHER queues the one ai.reply event (an ordinary picture) OR, for a payment screenshot or receipt — this plugin's own
 * decision, PaymentEvidence — queues nothing: the worker hands the conversation to a person, and no invoice, payment or
 * ledger is read or written. A captioned picture is answered once, by that turn (evo_webhook.php, step 9a).
 *
 * Batch 4 (docs/55 §9, docs/58, Slice 4a): a DOCUMENT goes the same way, only while ai_media_document is on as well, and
 * entirely inside this process — no library, no provider, no byte leaving the server. DocumentExtraction sniffs the content,
 * reads a Word, Excel, CSV, text or PDF file within every cap (PDF text since Slice 4b; a scanned PDF still has no OCR), classifies the text with
 * deterministic rules that FAIL CLOSED, and then EITHER queues the one ai.reply event (a harmless document, seen whole) OR
 * records a human-only class — payment evidence, a statement, an invoice, a contract, a quotation, an identity document, a
 * credential — and queues nothing: the worker hands the conversation to a person. Anything uncertain is a person too. No
 * payment, invoice, ledger, KYC or CRM record is read or written. A captioned document is answered once (step 9b).
 *
 * Batch 5 (docs/60): the document LADDER (MediaPolicy::documentMode). With ai_media_document alone the document is extracted,
 * classified and RECORDED and nothing else happens — the DRY RUN: no hand-over, no AI turn, the stored body untouched, the
 * caption answered as text by the webhook. ai_media_document_handover adds the person (a human-only class, a refusal, a fetch
 * that failed for good, a queue that gave up); ai_media_document_reply adds the assistant's turn for a harmless document. And
 * one guard the shared queue lacks: a row the queue has claimed as many times as it allows is a worker that died mid-way each
 * time (the EventBus counts attempts only on a reported failure), so it is settled `dead` here instead of being fetched for ever.
 */
final class MediaWorker extends WorkerBase
{
    /** Rows in one of these states are settled: a second event for the same message changes nothing. */
    public const SETTLED = ['fetched', 'understood', 'unsupported', 'skipped', 'dead'];

    /** Batch 5 (docs/60 §5): the row was claimed as often as its event may be attempted and never settled — a worker was lost each time. */
    public const REASON_WORKER_LOST = 'worker_lost';

    /** 5.18.86 (docs/65 §I): the channel registry would not confirm the number this arrived on — nothing fetched, a person told. */
    public const REASON_CHANNEL_REFUSED = 'channel_refused';
    /** 5.18.86 (docs/65 §D): the assistant is off on that number — nothing fetched; the team has the message. */
    public const REASON_CHANNEL_AI_OFF = 'channel_ai_off';

    /** @var MediaFetcher|null a fetcher injected by a test; otherwise built per event from the configuration */
    private $fetcher = null;
    /** @var TranscriberPort|null a transcriber injected by a test; otherwise TranscriberFactory::fromConfig() */
    private $transcriber = null;
    /** @var bool */
    private $transcriberInjected = false;
    /** @var ImageDescriberPort|null a describer injected by a test; otherwise ImageDescriberFactory::fromConfig() */
    private $describer = null;
    /** @var bool */
    private $describerInjected = false;
    /** @var DocumentOcrPort|null an OCR provider injected by a test; otherwise DocumentOcrFactory::fromConfig() — none today */
    private $ocr = null;
    /** @var bool */
    private $ocrInjected = false;
    /** @var DocumentDeadline|null a deadline injected by a test; otherwise built from ai_media_document_timeout_s */
    private $documentDeadline = null;
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

    /** For tests: the image describer to use (null = none configured). Production builds it from the configuration. */
    public function useImageDescriber(?ImageDescriberPort $describer): void
    {
        $this->describer         = $describer;
        $this->describerInjected = true;
    }

    /** For tests: the document OCR provider to use (null = none configured). Production builds it from the configuration. */
    public function useDocumentOcr(?DocumentOcrPort $ocr): void
    {
        $this->ocr         = $ocr;
        $this->ocrInjected = true;
    }

    /** For tests: the extraction deadline to use (a passed one proves the too_slow path). Production builds it from the policy. */
    public function useDocumentDeadline(?DocumentDeadline $deadline): void
    {
        $this->documentDeadline = $deadline;
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

        // 5.18.86 (docs/65 §I): with the channel registry on, a file is fetched — and anything is ever said about it —
        // only on the number it arrived on, while that number's channel is active and its assistant on. Otherwise the row
        // is settled as skipped and nothing is fetched: a person is told, with no holding line from a number that may not
        // be the customer's; or, with the assistant off on that number, the team simply has the message. Registry off:
        // nothing here runs.
        if (!in_array((string)$row['status'], self::SETTLED, true) && $this->evoClient()->registryOn()) {
            $route = $this->evoClient()->replyRoute((string)($row['channel'] ?? ''), (string)($row['instance'] ?? ''));
            if (!$route['ok']) {
                $aiOff = $route['reason'] === 'ai_disabled';
                $this->update($mediaId, ['status' => 'skipped',
                                         'failure_reason' => $aiOff ? self::REASON_CHANNEL_AI_OFF : self::REASON_CHANNEL_REFUSED]);
                if ($aiOff) {
                    $this->log('info', sprintf('media #%d: the assistant is off on channel %s — nothing fetched; kept for the team',
                        $mediaId, (string)($row['channel'] ?? '')));
                    return;
                }
                $this->log('warn', sprintf('media #%d: channel %s refused (%s) — nothing fetched; handed to a person',
                    $mediaId, (string)($row['channel'] ?? ''), $route['reason']));
                $this->handover($row, 'a ' . $this->mediaName($row) . ' arrived but the number for this chat could not be confirmed ('
                    . $route['reason'] . ') — look at it in WhatsApp', true);
                return;
            }
        }

        // Idempotency: one WhatsApp message is fetched once, however many events name it. Batch 2: a fetched voice
        // note whose transcript is still to come is not settled while ai_media_voice is on — the bytes were not kept,
        // so this attempt fetches them again and transcribes; once `understood` it is settled for good.
        $settled = in_array((string)$row['status'], self::SETTLED, true);
        if ($settled && (string)$row['status'] === 'fetched' && ($this->voiceWanted($row) || $this->imageWanted($row) || $this->documentWanted($row))
            && trim((string)($row['understanding'] ?? '')) === '') {
            $settled = false;
        }
        if ($settled) {
            $this->log('info', sprintf('media #%d: already %s — duplicate event, nothing fetched', $mediaId, (string)$row['status']));
            return;
        }

        // Batch 5 (docs/60 §5): the row counts its own claims (attempts, below). The queue counts an attempt only when the worker
        // REPORTS a failure, so a worker killed mid-way — a memory fatal, an OOM kill, a restart — leaves an event that is released
        // after five minutes and claimed again, with no path to dead. The row's count closes that loop here, without touching the
        // shared queue: as many claims as the event allows attempts, and the row is dead, a person is told as the queue's own dead
        // letter would tell them, and the event is acknowledged. A reported failure never gets here first: on the fifth claim the
        // row reads four, the event four; the fifth failure makes the event dead through fail() and onDead(), as before.
        $maxAttempts = max(1, (int)($event['max_attempts'] ?? 5));
        if ((int)$row['attempts'] >= $maxAttempts) {
            $this->update($mediaId, ['status' => 'dead', 'failure_reason' => self::REASON_WORKER_LOST]);
            $this->gaveUp($row, $mediaId, (int)($row['conversation_id'] ?? 0),
                sprintf('claimed %d times, the queue allows %d — a worker was lost each time', (int)$row['attempts'], $maxAttempts));
            $this->outcome($mediaId, $row, 'dead', ['reason' => self::REASON_WORKER_LOST]);
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
            // Batch 3 (docs/55 §9, docs/57): a picture goes on to understanding — or to a person, if it is a payment.
            if ($this->imageWanted($row)) {
                $this->understandImage($mediaId, $blob);   // wipes the blob, whatever happens
                return;
            }
            // Batch 4 (docs/55 §9, docs/58): a document goes on to extraction and classification — or to a person.
            if ($this->documentWanted($row)) {
                $this->understandDocument($mediaId, $blob);   // wipes the blob, whatever happens
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
        // Batch 2 / 3 / 4: a voice note, a picture or a document that will never be fetched is a customer who sent something
        // and would hear nothing — a person. Batch 5: for a document only when its hand-over rung is on (a dry run tells nobody).
        if ($this->voiceWanted($row) || $this->imageWanted($row) || $this->documentHandoverWanted($row)) {
            $this->handover($row, 'a ' . $this->mediaName($row) . ' could not be fetched (' . $reason . ') — look at it in WhatsApp');
        }
        if ((string)($row['kind'] ?? '') === 'document') $this->outcome($mediaId, $row, $status, ['reason' => $reason]);
    }

    /** Batch 4: is this row a document that ai_media_document (with ai_media_enabled) wants read? */
    private function documentWanted(array $row): bool
    {
        return (string)($row['kind'] ?? '') === 'document' && MediaPolicy::documentEnabled($this->config);
    }

    /** Batch 5 (docs/60 §2): is this row a document whose classification may be acted on for a PERSON (ai_media_document_handover, with every flag below it)? */
    private function documentHandoverWanted(array $row): bool
    {
        return (string)($row['kind'] ?? '') === 'document' && MediaPolicy::documentHandoverEnabled($this->config);
    }

    /**
     * Batch 5 (docs/60 §4): one structured line per document, for the counters — the outcome, the kind, the class or the reason,
     * the mode, the time and the row's attempts. Never the text, the file name, the caption, the number or the hash.
     */
    private function outcome(int $mediaId, array $row, string $outcome, array $o = []): void
    {
        $fresh = $this->row($mediaId) ?? $row;
        $this->log('info', sprintf('media #%d (conversation %d): document outcome=%s kind=%s class=%s reason=%s mode=%s ms=%d attempts=%d',
            $mediaId, (int)($fresh['conversation_id'] ?? 0), $outcome, (string)($o['kind'] ?? '-') ?: '-', (string)($o['class'] ?? '-') ?: '-',
            (string)($o['reason'] ?? '-') ?: '-', MediaPolicy::documentMode($this->config), (int)($o['ms'] ?? 0), (int)($fresh['attempts'] ?? 0)));
    }

    /**
     * Batch 4 (docs/55 §9, docs/58): the fetched document becomes the customer's turn through DocumentExtraction — or a record
     * and a person's job (a human-only class), or a reason and a person's job (a refusal, or a classification that could not be
     * made safely). The blob is wiped here whatever happens. Only the OCR boundary can fail in a way worth retrying; everything
     * else is permanent and handed over at once. The log never carries the text.
     */
    private function understandDocument(int $mediaId, MediaBlob $blob): void
    {
        $row = $this->row($mediaId) ?? [];
        $svc = new DocumentExtraction($this->pdo, $this->store, $this->config, $this->ocrFor(),
            function (string $level, string $message): void { $this->log($level, $message); });
        if ($this->documentDeadline !== null) $svc->useDeadline($this->documentDeadline);
        $t0 = microtime(true);
        try {
            $r = $svc->process($row, $blob);
        } finally {
            $blob->wipe();
        }
        $ms      = (int)round((microtime(true) - $t0) * 1000);
        $outcome = (string)($r['outcome'] ?? 'failed');
        $kind    = (string)($r['kind'] ?? '');
        $class   = (string)($r['classification'] ?? '');
        if ($outcome === 'understood') {
            $this->log('info', sprintf('media #%d (conversation %d): document read (%s, %s) — %d characters, ai.reply #%d queued',
                $mediaId, (int)($row['conversation_id'] ?? 0), $kind, $class !== '' ? $class : 'general', (int)($r['chars'] ?? 0), (int)($r['event_id'] ?? 0)));
            $this->outcome($mediaId, $row, 'understood', ['kind' => $kind, 'class' => $class, 'ms' => $ms]);
            return;
        }
        if ($outcome === 'recorded') {
            // Batch 5 (docs/60 §3): dry run, or hand-over mode with a harmless document — the record was written and that is all.
            $this->outcome($mediaId, $row, 'recorded', ['kind' => $kind, 'class' => $class, 'ms' => $ms]);
            return;
        }
        if ($outcome === 'evidence') {
            // A human-only class: no AI turn was queued. The hand-over names the class, never what the document says.
            $this->handover($row, DocumentExtraction::handoverReason('evidence', $class));
            $this->outcome($mediaId, $row, 'evidence', ['kind' => $kind, 'class' => $class, 'ms' => $ms]);
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
            throw new \RuntimeException(sprintf('media #%d document extraction %s (%s)', $mediaId, $reason, $detail));
        }
        if ($this->documentHandoverWanted($row)) {
            $this->log('warn', sprintf('media #%d: document not read — %s (%s); handed to a person, nothing answered', $mediaId, $reason, $detail));
            $this->handover($row, DocumentExtraction::handoverReason('failed', $reason, $detail));
        } else {
            // Batch 5 (docs/60 §3): the dry run tells nobody — the refusal is recorded on the row and counted, nothing more.
            $this->log('warn', sprintf('media #%d: document not read — %s (%s); dry run: recorded, nobody told, nothing answered', $mediaId, $reason, $detail));
        }
        $this->outcome($mediaId, $row, 'failed', ['kind' => $kind, 'reason' => $reason, 'ms' => $ms]);
    }

    private function ocrFor(): ?DocumentOcrPort
    {
        return $this->ocrInjected ? $this->ocr : DocumentOcrFactory::fromConfig($this->config);
    }

    /** Batch 2: is this row a voice note that ai_media_voice (with ai_media_enabled) wants transcribed? */
    private function voiceWanted(array $row): bool
    {
        return (string)($row['kind'] ?? '') === 'audio' && MediaPolicy::voiceEnabled($this->config);
    }

    /** Batch 3: is this row a picture that ai_media_image (with ai_media_enabled) wants described? */
    private function imageWanted(array $row): bool
    {
        return (string)($row['kind'] ?? '') === 'image' && MediaPolicy::imageEnabled($this->config);
    }

    /** What a hand-over reason calls the thing the customer sent. */
    private function mediaName(array $row): string
    {
        $kind = (string)($row['kind'] ?? '');
        return $kind === 'audio' ? 'voice message' : ($kind === 'image' ? 'photo' : ($kind === 'document' ? 'document' : 'file'));
    }

    /**
     * Batch 3 (docs/55 §9, docs/57): the fetched picture becomes the customer's turn through ImageUnderstanding — or,
     * when it is payment evidence, a person's job and nothing else. The blob is wiped here whatever happens. A
     * retryable failure is thrown so the EventBus retries the event; a permanent one hands the conversation to a
     * person and is acknowledged. The log never carries the description.
     */
    private function understandImage(int $mediaId, MediaBlob $blob): void
    {
        $row = $this->row($mediaId) ?? [];
        $svc = new ImageUnderstanding($this->pdo, $this->store, $this->config, $this->describerFor(),
            function (string $level, string $message): void { $this->log($level, $message); });
        try {
            $r = $svc->process($row, $blob);
        } finally {
            $blob->wipe();
        }
        $outcome = (string)($r['outcome'] ?? 'failed');
        if ($outcome === 'understood') {
            $this->log('info', sprintf('media #%d (conversation %d): picture described (%s) — %d characters, ai.reply #%d queued',
                $mediaId, (int)($row['conversation_id'] ?? 0), (string)($r['classification'] ?? 'general'),
                (int)($r['chars'] ?? 0), (int)($r['event_id'] ?? 0)));
            return;
        }
        if ($outcome === 'payment_evidence') {
            // Evidence for a person: no AI turn was queued. The hand-over says what arrived, never what it shows.
            $this->handover($row, PaymentEvidence::handoverReason());
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
            throw new \RuntimeException(sprintf('media #%d image understanding %s (%s)', $mediaId, $reason, $detail));
        }
        $this->log('warn', sprintf('media #%d: picture not understood — %s (%s); handed to a person, nothing answered',
            $mediaId, $reason, $detail));
        $this->handover($row, 'a photo could not be understood (' . $reason . ') — look at it in WhatsApp');
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

    /**
     * The one hand-over path (lib/Handover.php): needs_human, the wa.escalation event, the staff alert, the holding line once.
     * 5.18.86: $noHoldingLine when the number itself is in doubt — then nothing is sent to the customer.
     */
    private function handover(array $row, string $reason, bool $noHoldingLine = false): void
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
            new ConversationService($dataDir, $this->pdo), $convId, (string)($row['channel'] ?? ''), $phone, $reason, $noHoldingLine,
            function (string $level, string $message): void { $this->log($level, $message); }, 'media_worker');
    }

    private function evoClient(): EvolutionApiService
    {
        if ($this->evoClient === null) {
            // 5.18.86 (docs/65 §I): the constructor's service unless the channel registry is on (Uganda, behind
            // multi_number_channels_enabled); then a channel id resolves through wa_channels.
            $dataDir = dirname((string)($this->pdo->query('PRAGMA database_list')->fetch()['file'] ?? sys_get_temp_dir()));
            $this->evoClient = EvolutionApiService::forStore($this->config, $this->pdo, $dataDir,
                                                             MediaPolicy::timeoutSeconds($this->config));
        }
        return $this->evoClient;
    }

    private function transcriberFor(): ?TranscriberPort
    {
        return $this->transcriberInjected ? $this->transcriber : TranscriberFactory::fromConfig($this->config);
    }

    private function describerFor(): ?ImageDescriberPort
    {
        return $this->describerInjected ? $this->describer : ImageDescriberFactory::fromConfig($this->config);
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
        $this->gaveUp(is_array($row) ? $row : [], $mediaId, $convId, $e->getMessage());
        if (is_array($row) && (string)($row['kind'] ?? '') === 'document') {
            $this->outcome($mediaId, $row, 'dead', ['reason' => (string)($row['failure_reason'] ?? '')]);
        }
    }

    /**
     * The automatic path has given up on this row (the queue's dead letter, or Batch 5's lost-worker guard). Batch 2 / 3: a
     * voice note or a picture is handed to a person through the full path — the alert and the holding line too, not only the
     * inbox mark — because the customer sent something and has heard nothing. Batch 5: a document likewise, but only when its
     * hand-over rung is on; in a dry run, and for a kind nobody wanted understood, the conversation is marked for the inbox as
     * Batch 1 did, and no message is sent.
     */
    private function gaveUp(array $row, int $mediaId, int $convId, string $why): void
    {
        if ($row !== [] && ($this->voiceWanted($row) || $this->imageWanted($row) || $this->documentHandoverWanted($row))) {
            $name = $this->mediaName($row);
            $this->handover($row, 'a ' . $name . ' could not be ' . ($name === 'voice message' ? 'transcribed' : ($name === 'document' ? 'read' : 'understood'))
                . ' after every attempt — look at it in WhatsApp');
            $this->log('error', sprintf('media #%d (conversation %d): %s given up after every attempt — handed to a person: %s',
                $mediaId, $convId, $name, $why));
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
            $mediaId, $convId, $why));
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

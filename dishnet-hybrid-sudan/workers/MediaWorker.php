<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkerBase.php';
require_once dirname(__DIR__) . '/lib/PluginConfig.php';
require_once dirname(__DIR__) . '/lib/MediaPolicy.php';
require_once dirname(__DIR__) . '/lib/MediaFetcher.php';
require_once dirname(__DIR__) . '/lib/EvolutionApiService.php';
require_once dirname(__DIR__) . '/lib/Transcriber.php';
require_once dirname(__DIR__) . '/lib/VoiceTranscription.php';
require_once dirname(__DIR__) . '/lib/VoiceDuration.php';
require_once dirname(__DIR__) . '/lib/Handover.php';
require_once dirname(__DIR__) . '/lib/ConversationService.php';
require_once dirname(__DIR__) . '/lib/UtcClock.php';

/**
 * MediaWorker — fetch, measure and (from R2) transcribe the voice note a customer sent, off the reply path (Batch 1/2 of
 * the AI communication layer, docs/55 §9; 5.18.104, root docs/73 §H.3.6: ported from the development tree, trimmed to
 * audio, with its own constructor and budget, and every decision taken on a configuration read per event, ruling R19).
 *
 * Its own worker, its own lock (WorkerBase names the lock after the class) and its own runner (run_media_worker.php): a
 * voice note that takes twenty seconds to come down from Evolution never holds up a text reply.
 *
 * One ai.media event: the wa_media row the webhook recorded is read; the configuration is read again (the process's own
 * is read once, when it starts, and a run lasts up to 90 s) and decides the switch, the rung and whether the row predates
 * the switch-on. Off: the row is skipped, nothing fetched. Registry on: a DishNet number, a conversation with no phone or
 * a refused route settles the row with nothing fetched. A settled row is a duplicate event. Before the fetch is claimed
 * the run checks it has the time for it. Then the fetch into memory; the packets counted (VoiceDuration) and the row's
 * size, type, hash and local_seconds recorded; at R1 the bytes are wiped and that is all. From R2 VoiceTranscription asks
 * the provider. R1 and R2 tell nobody anything; at R3 and R4 a note that is not answered is handed to a person through
 * lib/Handover.php, the path AiReplyWorker uses. Nothing is written to disk, nothing is sent to the customer but the
 * hand-over's holding line. PHP 7.4 compatible.
 */
final class MediaWorker extends WorkerBase
{
    /** Rows in one of these states are settled: a second event for the same message changes nothing. */
    public const SETTLED = ['fetched', 'understood', 'unsupported', 'skipped', 'dead'];

    /** A retryable fetch or provider failure on the row's third claim is settled as permanent (§H.3.6, change 6). */
    public const VOICE_MAX_ATTEMPTS = 3;

    /** Batch 5 (docs/60 §5): the row was claimed as often as its event may be attempted and never settled — a worker was lost each time. */
    public const REASON_WORKER_LOST = 'worker_lost';
    /** 5.18.86 (docs/65 §I): the channel registry would not confirm the number this arrived on — nothing fetched. */
    public const REASON_CHANNEL_REFUSED = 'channel_refused';
    /** 5.18.86 (docs/65 §D): the assistant is off on that number — nothing fetched; the team has the message. */
    public const REASON_CHANNEL_AI_OFF = 'channel_ai_off';
    /** 5.18.90 (docs/65 §AD): the file came from one of DishNet's own numbers; kept for the team, nothing fetched. */
    public const REASON_DISHNET_NUMBER = 'dishnet_number';
    /** 5.18.90: the file's conversation has no phone on record — nothing could be said to anyone, so nothing is fetched. */
    public const REASON_NO_PHONE = 'no_phone';
    /** 5.18.104 (§H.3.6, change 2): recorded before the latest on voice-measure — never transcribed on a later on-period. */
    public const REASON_BEFORE_SWITCH_ON = 'before_switch_on';

    /** @var MediaFetcher|null a fetcher injected by a test; otherwise built per event from the configuration */
    private $fetcher = null;
    /** @var TranscriberPort|null a transcriber injected by a test; otherwise TranscriberFactory::fromConfig() on the call's own read */
    private $transcriber = null;
    /** @var bool */
    private $transcriberInjected = false;
    /** @var EvolutionApiService|null */
    private $evoClient = null;
    /** @var array the configuration this event read (§H.3.6, change 1) */
    private $fresh = [];

    private int $budgetEnd;
    private bool $budgetSpent = false;
    private string $dataDir;

    public function __construct($store, array $config, int $maxRun = 55, int $batch = 1, ?int $deadline = null)
    {
        parent::__construct($store, $config, $maxRun, $batch);
        $end = time() + $maxRun;
        $this->budgetEnd = $deadline !== null ? min($end, $deadline) : $end;
        $dir = (string)(getenv('DN_DATA_DIR') ?: '');
        if ($dir === '' && function_exists('getDataDir')) $dir = (string)getDataDir(dirname(__DIR__));
        if ($dir === '') $dir = dirname((string)($this->pdo->query('PRAGMA database_list')->fetch()['file'] ?? sys_get_temp_dir()));
        $this->dataDir = $dir;
    }

    protected function isTimedOut(): bool
    {
        return parent::isTimedOut() || $this->budgetSpent || time() >= $this->budgetEnd;
    }

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

        // The switch and the rung, on this event's own read of the configuration (ruling R19). A read that throws is off.
        $mode = 'off';
        try {
            $this->fresh = PluginConfig::read(dirname(__DIR__), $this->dataDir);
            if (MediaPolicy::enabled($this->fresh, $this->dataDir)) {
                $mode = MediaPolicy::voiceMode($this->fresh, $this->dataDir, (string)($row['channel'] ?? ''));
            }
        } catch (\Throwable $e) {
            $this->fresh = [];
            $mode = 'off';
        }
        if ($mode === 'off') {
            if (!in_array((string)$row['status'], self::SETTLED, true)) {
                $this->update($mediaId, ['status' => 'skipped', 'failure_reason' => 'media_disabled']);
            }
            $this->log('info', sprintf('media #%d: ai_media_enabled is off — nothing fetched, row skipped', $mediaId));
            return;
        }
        $tell = $mode === 'handover' || $mode === 'reply';

        // 5.18.86 (docs/65 §I): with the channel registry on, a file is fetched only on the number it arrived on, while that
        // number's channel is active and its assistant on. Registry off: nothing here runs.
        if (!in_array((string)$row['status'], self::SETTLED, true) && $this->evoClient()->registryOn()) {
            // 5.18.90 (docs/65 §AD): from one of DishNet's own numbers — kept for the team; nothing fetched, nothing said.
            // A read that fails throws (the EventBus retries; nothing is settled).
            $own = $this->ownSender($row);
            if ($own === self::REASON_NO_PHONE) {
                $this->update($mediaId, ['status' => 'skipped', 'failure_reason' => self::REASON_NO_PHONE]);
                $this->log('warn', sprintf('media #%d: no phone on record for its conversation on channel %s — nothing fetched; kept for the team',
                    $mediaId, (string)($row['channel'] ?? '')));
                return;
            }
            if ($own !== '') {
                $this->update($mediaId, ['status' => 'skipped', 'failure_reason' => self::REASON_DISHNET_NUMBER]);
                $this->log('info', sprintf('media #%d: a DishNet number (%s) on channel %s — nothing fetched; kept for the team',
                    $mediaId, $own, (string)($row['channel'] ?? '')));
                return;
            }
            $route = $this->evoClient()->replyRoute((string)($row['channel'] ?? ''), (string)($row['instance'] ?? ''));
            if (!$route['ok']) {
                $aiOff = $route['reason'] === 'ai_disabled' || $route['reason'] === 'internal_numbers_incomplete';
                $this->update($mediaId, ['status' => 'skipped',
                                         'failure_reason' => $aiOff ? self::REASON_CHANNEL_AI_OFF : self::REASON_CHANNEL_REFUSED]);
                if ($aiOff) {
                    $this->log('info', sprintf('media #%d: the assistant is off on channel %s — nothing fetched; kept for the team',
                        $mediaId, (string)($row['channel'] ?? '')));
                    return;
                }
                $this->log('warn', sprintf('media #%d: channel %s refused (%s) — nothing fetched%s',
                    $mediaId, (string)($row['channel'] ?? ''), $route['reason'], $tell ? '; handed to a person' : '; recorded'));
                // 5.18.104 (§H.3.6, change 5): R1 and R2 tell nobody; at R3/R4 a person, with no holding line.
                if ($tell) {
                    $this->handover($row, 'a voice message arrived but the number for this chat could not be confirmed ('
                        . $route['reason'] . ') — look at it in WhatsApp', true);
                }
                return;
            }
        }

        // Idempotency: one WhatsApp message is fetched once, however many events name it. A fetched voice note whose
        // transcript is still to come (from R2) is not settled: the bytes were not kept, so this attempt fetches again.
        $settled = in_array((string)$row['status'], self::SETTLED, true);
        if ($settled && (string)$row['status'] === 'fetched' && $mode !== 'fetch_only'
            && trim((string)($row['understanding'] ?? '')) === '' && (string)($row['voice_rung'] ?? '') !== 'fetch_only') {
            $settled = false;
        }
        if ($settled) {
            $this->log('info', sprintf('media #%d: already %s — duplicate event, nothing fetched', $mediaId, (string)$row['status']));
            return;
        }

        // 5.18.104 (§H.3.6, change 2): a row recorded before the latest on voice-measure is never fetched on this on-period.
        $since = UtcClock::parse((string)($this->fresh['ai_media_since'] ?? ''));
        $made  = UtcClock::parse((string)($row['created_at'] ?? ''));
        if ($since > 0 && $made > 0 && $made < $since) {
            $this->update($mediaId, ['status' => 'skipped', 'failure_reason' => self::REASON_BEFORE_SWITCH_ON]);
            $this->log('info', sprintf('media #%d: recorded before the switch-on — nothing fetched, row skipped', $mediaId));
            return;
        }

        // Batch 5 (docs/60 §5): the row counts its own claims. As many claims as the event allows attempts, and the row is
        // dead — a worker was lost each time — decided on this event's rung (§H.3.6, change 5).
        $maxAttempts = max(1, (int)($event['max_attempts'] ?? 5));
        if ((int)$row['attempts'] >= $maxAttempts) {
            $this->update($mediaId, ['failure_reason' => self::REASON_WORKER_LOST]);
            $this->gaveUp($this->row($mediaId) ?? $row, $mediaId, (int)($row['conversation_id'] ?? 0),
                sprintf('claimed %d times, the queue allows %d — a worker was lost each time', (int)$row['attempts'], $maxAttempts), $mode);
            return;
        }

        // 5.18.104 (§H.3.6, change 3): the budget, before the fetch is claimed, so no attempt is spent.
        $need = $this->fetchTimeoutS() + 5 + ($mode === 'fetch_only' ? 0 : $this->voiceTimeoutS());
        if (time() + $need > $this->budgetEnd) {
            $this->budgetSpent = true;
            throw new WorkerDefer(30, 'media: not enough time left in this run');
        }

        $attempt = (int)$row['attempts'] + 1;
        $this->update($mediaId, ['status' => 'fetching', 'attempts' => $attempt, 'voice_rung' => $mode]);
        $fetcher = $this->fetcher ?? new MediaFetcher($this->evoClient(), $this->fresh);
        $res = $fetcher->fetch($row);

        if (!empty($res['ok'])) {
            /** @var MediaBlob $blob */
            $blob = $res['blob'];
            // 5.18.104 (§H.3.6, change 4): every fetched file is measured; the packet count is what the budget trusts.
            $d = VoiceDuration::measure($blob);
            $this->update($mediaId, [
                'status'           => 'fetched',
                'fetched_bytes'    => $blob->size,
                'fetched_mimetype' => $blob->mimetype,
                'sha256'           => $blob->sha256,
                'local_seconds'    => !empty($d['ok']) ? (int)ceil((float)$d['seconds']) : null,
                'failure_reason'   => null,
            ]);
            // The bytes end here. What the log says about them is the kind, type, size and a hash prefix — never content.
            $this->log('info', sprintf('media #%d (conversation %d): fetched %s rung=%s duration=%s', $mediaId, (int)$row['conversation_id'],
                $blob->describe(), $mode, !empty($d['ok']) ? 'ok' : (string)$d['detail']));
            if ($mode === 'fetch_only') {
                $blob->wipe();
                unset($blob, $res);
                return;
            }
            $this->transcribe($mediaId, $blob, $mode, $attempt);   // wipes the blob, whatever happens
            return;
        }

        $reason = (string)($res['reason'] ?? 'fetch_failed');
        $detail = (string)($res['detail'] ?? '');
        if (!empty($res['retryable']) && $attempt < self::VOICE_MAX_ATTEMPTS) {
            $this->update($mediaId, ['status' => 'failed', 'failure_reason' => $reason]);
            // Thrown, so the EventBus retries with its backoff. The message names the row and the code, nothing else.
            throw new \RuntimeException(sprintf('media #%d fetch %s (%s)', $mediaId, $reason, $detail));
        }
        $status = in_array($reason, ['unsupported_kind', 'unsupported_mime'], true) ? 'unsupported' : 'failed';
        $this->update($mediaId, ['status' => $status, 'failure_reason' => $reason]);
        $this->log('info', sprintf('media #%d: %s — %s (%s); not retried', $mediaId, $status, $reason, $detail));
        // A voice note that will never be fetched is a customer who sent something and would hear nothing: a person,
        // at R3/R4 only (§H.3.6, change 5).
        if ($tell) {
            $this->handover($row, 'a voice message could not be fetched (' . $reason . ') — look at it in WhatsApp');
        }
    }

    /** The Evolution fetch's timeout: the one the worker's client was built with. */
    private function fetchTimeoutS(): int
    {
        return MediaPolicy::timeoutSeconds($this->config);
    }

    /** The provider's timeout, on this event's read (VoiceTranscription reads it again before the call). */
    private function voiceTimeoutS(): int
    {
        return MediaPolicy::voiceTimeoutSeconds($this->fresh);
    }

    /**
     * From R2: the fetched voice note through VoiceTranscription, at the event's rung. The blob is wiped here whatever
     * happens. A retryable failure is thrown until the row's third claim; a permanent one is recorded at R2 and handed to
     * a person at R3/R4. The log never carries the transcript.
     */
    private function transcribe(int $mediaId, MediaBlob $blob, string $mode, int $attempt): void
    {
        $row = $this->row($mediaId) ?? [];
        $svc = new VoiceTranscription($this->pdo, $this->store, $this->config, $this->transcriber,
            function (string $level, string $message): void { $this->log($level, $message); },
            $this->dataDir, $this->transcriberInjected);
        try {
            $r = $svc->process($row, $blob, $mode);
        } finally {
            $blob->wipe();
        }
        $outcome = (string)($r['outcome'] ?? 'failed');
        if ($outcome === 'recorded' || $outcome === 'understood') {
            $this->log('info', sprintf('media #%d (conversation %d): voice note transcribed at %s — %d characters%s', $mediaId,
                (int)($row['conversation_id'] ?? 0), $mode, (int)($r['chars'] ?? 0),
                $outcome === 'understood' ? sprintf(', ai.reply #%d queued', (int)($r['event_id'] ?? 0)) : ''));
            return;
        }
        if ($outcome === 'handover') {
            $this->log('info', sprintf('media #%d (conversation %d): voice note transcribed for staff — %d characters', $mediaId,
                (int)($row['conversation_id'] ?? 0), (int)($r['chars'] ?? 0)));
            $this->handover($row, 'a voice note: read its machine transcript in the Inbox, and listen to check it');
            return;
        }
        if ($outcome === 'already_understood') {
            $this->log('info', sprintf('media #%d: already understood — nothing queued twice', $mediaId));
            return;
        }
        if ($outcome === 'duplicate') {
            $this->log('info', sprintf('media #%d: attempt %d already reserved — this attempt stops', $mediaId, $attempt));
            return;
        }
        $reason = (string)($r['reason'] ?? 'provider_error');
        $detail = (string)($r['detail'] ?? '');
        $this->update($mediaId, ['status' => 'failed', 'failure_reason' => $reason]);
        if (!empty($r['retryable']) && $attempt < self::VOICE_MAX_ATTEMPTS) {
            throw new \RuntimeException(sprintf('media #%d transcription %s (%s)', $mediaId, $reason, $detail));
        }
        if ($mode === 'handover' || $mode === 'reply') {
            $this->log('warn', sprintf('media #%d: voice note not transcribed — %s (%s); handed to a person, nothing answered',
                $mediaId, $reason, $detail));
            $this->handover($row, 'a voice message could not be transcribed (' . $reason . ') — listen to it in WhatsApp');
            return;
        }
        $this->log('info', sprintf('media #%d: voice note not transcribed — %s (%s); dry run: recorded, nobody told', $mediaId, $reason, $detail));
    }

    /**
     * The one hand-over path (lib/Handover.php): needs_human, the wa.escalation event, the staff alert, the holding line once.
     * $noHoldingLine when the number itself is in doubt. 5.18.104 (§H.3.6, change 7): the holding line is also held while P5
     * serves this number, and within 10 min after an acknowledgement — decided on a read taken now, never the worker's own.
     */
    private function handover(array $row, string $reason, bool $noHoldingLine = false): void
    {
        $convId  = (int)($row['conversation_id'] ?? 0);
        $channel = (string)($row['channel'] ?? '');
        $phone   = '';
        if ($convId > 0) {
            $q = $this->pdo->prepare('SELECT phone FROM wa_conversations WHERE id = ?');
            $q->execute([$convId]);
            $phone = (string)($q->fetchColumn() ?: '');
        }
        if ($convId <= 0 || $phone === '') {
            $this->log('warn', sprintf('media #%d: no conversation to hand over', (int)($row['id'] ?? 0)));
            return;
        }
        try {
            $now = PluginConfig::read(dirname(__DIR__), $this->dataDir);
        } catch (\Throwable $e) {
            $now = null;
        }
        $already = $noHoldingLine || $this->p5Serves($now, $channel) || $this->recentAck($convId);
        $cfg = is_array($now) ? $now : $this->config;
        $owner = null;
        try {
            $evo = $this->evoClient();
            if ($evo->registryOn()) {
                $ctx = $evo->channelContext($channel);
                if ($ctx !== null && $ctx->handoverTo() === 'owner') {
                    if (!class_exists('LineOwner')) require_once dirname(__DIR__) . '/lib/LineOwner.php';
                    $owner = \LineOwner::of($ctx, $this->store, $cfg, $this->dataDir);
                }
            }
        } catch (\Throwable $e) {
            $owner = null;
        }
        Handover::escalate($this->pdo, $this->bus, $this->store, $cfg, $this->evoClient(),
            new ConversationService($this->dataDir, $this->pdo), $convId, $channel, $phone, $reason, $already,
            function (string $level, string $message): void { $this->log($level, $message); }, 'media_worker', $owner);
    }

    /**
     * Whether P5, the instant acknowledgement, serves this number on the read given: then it has already answered the
     * customer and a holding line would be a second message. A read that failed, or one that throws, counts as serving.
     */
    private function p5Serves(?array $cfg, string $channel): bool
    {
        if ($cfg === null) return true;
        try {
            if (!class_exists('VoiceAck')) {
                $f = dirname(__DIR__) . '/lib/VoiceAck.php';
                if (!is_file($f)) return true;   // no acknowledgement code to ask: counted as serving, so no second message
                require_once $f;
            }
            return \VoiceAck::enabled($cfg, $this->dataDir) && MediaPolicy::voiceMode($cfg, $this->dataDir, $channel) !== 'reply';
        } catch (\Throwable $e) {
            return true;
        }
    }

    /** An acknowledgement of this conversation, claimed, sent or uncertain, stored within the last 10 minutes. */
    private function recentAck(int $convId): bool
    {
        try {
            $q = $this->pdo->prepare("SELECT 1 FROM wa_voice_acks WHERE conversation_id = ? AND outcome IN ('claimed','sent','uncertain')
                                       AND created_at >= ? LIMIT 1");
            $q->execute([$convId, UtcClock::stamp(time() - 600)]);
            return $q->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return true;   // unreadable: held, a duplicate is the worse failure here
        }
    }

    private function evoClient(): EvolutionApiService
    {
        if ($this->evoClient === null) {
            // 5.18.86 (docs/65 §I): the constructor's service unless the channel registry is on (Uganda, behind
            // multi_number_channels_enabled); then a channel id resolves through wa_channels.
            $dataDir = dirname((string)($this->pdo->query('PRAGMA database_list')->fetch()['file'] ?? sys_get_temp_dir()));
            $this->evoClient = EvolutionApiService::forStore($this->config, $this->pdo, $dataDir,
                                                             MediaPolicy::timeoutSeconds($this->config));
            // 5.18.90 (docs/65 §AD): the policy reads the numbers Evolution reports, and the owners, from the store — on
            // every path, not only ownSender's: a hand-over holding line on a re-paired number must be refused too.
            $this->evoClient->useStaffStore($this->store);
        }
        return $this->evoClient;
    }

    /**
     * The queue has given up on this row. 5.18.104 (§H.3.6, change 5): decided on a rung read now, never the worker's own
     * configuration; a read that throws is off.
     */
    protected function onDead(array $event, \Throwable $e): void
    {
        $p       = self::payloadOf($event);
        $mediaId = (int)($p['media_id'] ?? 0);
        if ($mediaId <= 0) return;
        $row = $this->row($mediaId);
        if ($row === null) {
            $this->log('warn', sprintf('media #%d: dead letter with no row left — nothing to do', $mediaId));
            return;
        }
        $mode = 'off';
        try {
            $cfg = PluginConfig::read(dirname(__DIR__), $this->dataDir);
            $mode = MediaPolicy::voiceMode($cfg, $this->dataDir, (string)($row['channel'] ?? ''));
        } catch (\Throwable $ignore) {
            $mode = 'off';
        }
        $this->gaveUp($row, $mediaId, (int)($row['conversation_id'] ?? 0), 'the queue gave up', $mode);
    }

    /**
     * The automatic path has given up on this row (the queue's dead letter, or the lost-worker guard), at the rung given.
     * At R3/R4 the row is dead at that rung and a person is told through the full path. Below it the row is dead with its
     * reason and nobody is told; a row last acted on at R3/R4 is recorded `switched_off`.
     */
    private function gaveUp(array $row, int $mediaId, int $convId, string $why, string $mode): void
    {
        if ($mode === 'handover' || $mode === 'reply') {
            $this->update($mediaId, ['status' => 'dead', 'voice_rung' => $mode]);
            $this->handover($row, 'a voice message could not be transcribed after every attempt — look at it in WhatsApp');
            $this->log('error', sprintf('media #%d (conversation %d): voice note given up after every attempt — handed to a person (%s)',
                $mediaId, $convId, $why));
            return;
        }
        $fields = ['status' => 'dead'];
        if (in_array((string)($row['voice_rung'] ?? ''), ['handover', 'reply'], true)) $fields['failure_reason'] = 'switched_off';
        $this->update($mediaId, $fields);
        $this->log('error', sprintf('media #%d (conversation %d): voice note given up after every attempt — recorded, nobody told (%s)',
            $mediaId, $convId, $why));
    }

    /**
     * 5.18.90: the kind of DishNet number this file's conversation is with ('' for a customer), or REASON_NO_PHONE when the
     * conversation has none on record. A read that fails is not an answer: it throws, and the event is retried.
     */
    private function ownSender(array $row): string
    {
        $convId = (int)($row['conversation_id'] ?? 0);
        $phone  = '';
        if ($convId > 0) {
            $q = $this->pdo->prepare('SELECT phone FROM wa_conversations WHERE id = ?');   // a failure propagates: retried
            $q->execute([$convId]);
            $phone = (string)($q->fetchColumn() ?: '');
        }
        if ($phone === '') return self::REASON_NO_PHONE;
        $evo = $this->evoClient();
        $evo->useStaffStore($this->store);
        return $evo->automationPolicy()->senderClass((string)($row['channel'] ?? ''), $phone);
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

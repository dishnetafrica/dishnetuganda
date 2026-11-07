<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/MediaBlob.php';
require_once __DIR__ . '/Transcriber.php';
require_once __DIR__ . '/EventBus.php';
require_once __DIR__ . '/ContactOptOut.php';
require_once __DIR__ . '/UtcClock.php';
require_once __DIR__ . '/PluginConfig.php';
require_once __DIR__ . '/VoiceDuration.php';
require_once __DIR__ . '/VoiceBudget.php';

/**
 * VoiceTranscription — a fetched voice note, transcribed, per rung (Batch 2 of the AI communication layer, docs/55 §9,
 * docs/56; 5.18.104, root docs/73 §H.3.7: ported from the development tree and split per rung).
 *
 * MediaWorker calls process() with the audio in memory and the rung its event read (dry_run, handover or reply):
 *
 *   1. the kind and the fetched type — Ogg/Opus only in this release; anything else is unsupported_audio;
 *   2. the duration counted from the Opus packets (VoiceDuration): malformed, the container disagreeing with its
 *      packets, a bit rate above 64 kbit/s, or longer than ai_media_voice_max_seconds is refused before any call; the
 *      announced `seconds` is recorded only, never trusted;
 *   3. the configuration read again, immediately before the provider is asked (ruling R19): voice off on that read is
 *      `switched_off`, no provider is `provider_missing`;
 *   4. the budget (VoiceBudget) reserves the call, or refuses it;
 *   5. the one call; the calls row is settled with its outcome, status, ms and billed seconds;
 *   6. the text normalised (empty is a failure);
 *   7. complete(), in one transaction guarded by `status <> 'understood'`: at dry_run the row records counts only and
 *      `understanding` stays NULL; at handover the transcript is kept for staff and the body stays [AUDIO] (F10); at reply
 *      the body becomes the labelled transcript and ONE ai.reply event is queued with origin voice. A spoken STOP is
 *      recorded from R2, exactly as the webhook reads typed text.
 *
 * It never sends a message and never keeps audio. Every failure is a fixed reason code with a retryable flag (REASONS);
 * the worker records it and, at R3/R4, hands the conversation to a person. An exception's class is kept, never its
 * message. PHP 7.4 compatible.
 */
final class VoiceTranscription
{
    /** What the assistant reads before the transcript, and what the stored message says (R4 only). */
    public const LABEL = '[voice message, transcribed]';

    /** Reason code => retried by the EventBus? */
    public const REASONS = [
        'too_long'             => false,   // longer than ai_media_voice_max_seconds, counted from the packets
        'unsupported_audio'    => false,   // not Ogg/Opus (MP3, M4A, WAV, WebM, Vorbis, Speex)
        'invalid_audio'        => false,   // malformed, the container disagreeing with its packets, or the bit rate
        'empty_transcript'     => false,   // nothing recognised
        'conversation_missing' => false,   // the row names a conversation that no longer exists
        'provider_missing'     => false,   // no usable provider on the configuration read before the call
        'provider_auth'        => false,   // 401 / 403
        'provider_quota'       => false,   // 429 insufficient_quota
        'provider_model'       => false,   // 404: the model is not served
        'provider_rejected'    => false,   // 400: the file was refused
        'too_large'            => false,   // 413
        'budget_daily'         => false,   // the day's minutes are used up (or set to 0)
        'budget_conversation'  => false,   // this conversation's notes today are at the cap
        'switched_off'         => false,   // voice switched off between the fetch and the call
        'provider_error'       => true,    // a 5xx, a malformed answer, a transport failure
        'provider_busy'        => true,    // a 429 that is not the quota
        'timeout'              => true,    // no answer in time
    ];

    public const MODES = ['dry_run', 'handover', 'reply'];

    /** 64 kbit/s plus a page of slack: a voice note above it is not what it says it is. */
    private const BYTES_PER_SECOND = 8000;
    private const BYTES_SLACK      = 4096;

    /** @var \PDO */
    private $pdo;
    private $store;
    /** @var array */
    private $config;
    /** @var TranscriberPort|null */
    private $transcriber;
    /** @var bool */
    private $injected;
    /** @var callable */
    private $log;
    /** @var EventBus */
    private $bus;
    /** @var string */
    private $dataDir;
    /** @var int|null */
    private $now;

    /**
     * @param TranscriberPort|null $transcriber a provider injected by a test; with $injected false the provider is built
     *                                          from the configuration read before the call
     */
    public function __construct(\PDO $pdo, $store, array $config, ?TranscriberPort $transcriber, callable $log,
                                string $dataDir = '', bool $injected = false, ?int $now = null)
    {
        $this->pdo         = $pdo;
        $this->store       = $store;
        $this->config      = $config;
        $this->transcriber = $transcriber;
        $this->injected    = $injected;
        $this->log         = $log;
        $this->bus         = new EventBus($pdo);
        $this->dataDir     = $dataDir;
        $this->now         = $now;
    }

    /**
     * @param array     $row   the wa_media row, freshly read (kind, seconds, channel, instance, conversation_id, attempts, …)
     * @param MediaBlob $audio the fetched bytes, in memory
     * @param string    $mode  the rung the event read: dry_run | handover | reply
     * @return array ['outcome' => 'recorded'|'handover'|'understood', 'chars' => int, 'event_id' => int (reply)]
     *               | ['outcome' => 'already_understood'] | ['outcome' => 'duplicate']
     *               | ['outcome' => 'failed', 'reason' => string, 'retryable' => bool, 'detail' => string]
     */
    public function process(array $row, MediaBlob $audio, string $mode): array
    {
        if (!in_array($mode, self::MODES, true)) return self::fail('switched_off', 'rung ' . $mode);
        $id     = (int)($row['id'] ?? 0);
        $convId = (int)($row['conversation_id'] ?? 0);

        // 1. The kind and the fetched type.
        if ((string)($row['kind'] ?? '') !== 'audio') return self::fail('unsupported_audio', 'not an audio message');
        $base = MediaPolicy::baseMime((string)$audio->mimetype);
        if ($base !== 'audio/ogg' && $base !== 'audio/opus') return self::fail('unsupported_audio', $base !== '' ? $base : 'no type');

        // 2. The duration, from the packets; the announced seconds are recorded only.
        $d = VoiceDuration::measure($audio);
        if (empty($d['ok'])) return self::fail((string)$d['reason'], (string)$d['detail']);
        $secs = (int)ceil((float)$d['seconds']);
        if ((int)$audio->size > $secs * self::BYTES_PER_SECOND + self::BYTES_SLACK) return self::fail('invalid_audio', 'bitrate');
        $announced = $row['seconds'] ?? null;
        if (is_numeric($announced) && abs((int)$announced - (float)$d['seconds']) > 2) {
            ($this->log)('info', sprintf('media #%d: announced_mismatch', $id));
        }

        // 3. The provider, on a configuration read afresh (ruling R19).
        try {
            $fresh = PluginConfig::read(dirname(__DIR__), $this->dataDir !== '' ? $this->dataDir : dirname(__DIR__) . '/data');
            $on = MediaPolicy::voiceEnabled($fresh, $this->dataDir !== '' ? $this->dataDir : null);
        } catch (\Throwable $e) {
            $fresh = []; $on = false;
        }
        if (!$on) return self::fail('switched_off', 'voice off on the read before the call');
        if ($secs > MediaPolicy::voiceMaxSeconds($fresh)) {
            return self::fail('too_long', sprintf('%d s, limit %d s', $secs, MediaPolicy::voiceMaxSeconds($fresh)));
        }
        $tr = $this->injected ? $this->transcriber : TranscriberFactory::fromConfig($fresh);
        if ($tr === null) return self::fail('provider_missing', 'no provider');
        $model = MediaPolicy::sttModel($fresh);
        if ($model === '') return self::fail('provider_missing', 'model not on the allow-list');

        // 4. The budget, before the call.
        $budget  = new VoiceBudget($this->pdo, $fresh, $this->now);
        $attempt = max(1, (int)($row['attempts'] ?? 1));
        $res = $budget->reserve($id, $convId, $attempt, $secs, $model, $mode);
        if (empty($res['ok'])) {
            if (($res['reason'] ?? '') === 'duplicate') return ['outcome' => 'duplicate'];
            return self::fail((string)$res['reason'], 'reserved ' . $secs . ' s');
        }
        $callId = (int)$res['id'];

        // 5. The call.
        $this->setStatus($id, 'transcribing');
        $t0 = microtime(true);
        try {
            $ans = $tr->transcribe($audio, [
                'mimetype' => (string)$audio->mimetype, 'seconds' => $secs, 'language' => '',
                'channel'  => (string)($row['channel'] ?? ''),
            ], MediaPolicy::voiceTimeoutSeconds($fresh));
        } catch (\Throwable $e) {
            $ans = ['ok' => false, 'reason' => 'provider_error', 'retryable' => true, 'detail' => get_class($e)];
        }
        $ms   = (int)round((microtime(true) - $t0) * 1000);
        $http = is_array($ans) ? (int)($ans['http'] ?? 0) : 0;
        $ok   = is_array($ans) && !empty($ans['ok']);
        $billed = $secs;
        if ($ok && isset($ans['duration_s']) && is_numeric($ans['duration_s'])) $billed = (int)ceil((float)$ans['duration_s']);
        if (!$ok) {
            $reason = (string)(is_array($ans) ? ($ans['reason'] ?? '') : '');
            if (!array_key_exists($reason, self::REASONS)) $reason = 'provider_error';
            $retry = is_array($ans) && array_key_exists('retryable', $ans) ? (bool)$ans['retryable'] : self::REASONS[$reason];
            $budget->settle($callId, 'failed', $http, $ms, $secs, $reason);
            return ['outcome' => 'failed', 'reason' => $reason, 'retryable' => $retry,
                    'detail' => self::safeDetail((string)(is_array($ans) ? ($ans['detail'] ?? '') : 'no answer'))];
        }
        $budget->settle($callId, 'ok', $http, $ms, $billed);

        // 6. Normalise.
        $text = self::normalise((string)($ans['text'] ?? ''));
        if ($text === '') return self::fail('empty_transcript', 'nothing recognised');

        // 7. The rung's own record.
        return $this->complete($row, $text, [
            'provider' => 'openai', 'model' => $model, 'ms' => $ms,
            'provider_seconds' => isset($ans['duration_s']) && is_numeric($ans['duration_s']) ? (int)ceil((float)$ans['duration_s']) : null,
            'seconds' => $secs,
        ], $mode);
    }

    /**
     * The transcript's record, once, at the rung given. One transaction: the row is marked understood only if it was
     * not already (a retry, or a duplicate event, finds the guard and does nothing); at reply the stored message is
     * rewritten and the ai.reply event queued; from R2 a STOP is recorded. A database failure rolls all of it back and
     * is thrown, so the worker retries the whole thing.
     */
    public function complete(array $row, string $transcript, array $meta, string $mode): array
    {
        if (!in_array($mode, self::MODES, true)) return self::fail('switched_off', 'rung ' . $mode);
        $id     = (int)($row['id'] ?? 0);
        $convId = (int)($row['conversation_id'] ?? 0);
        $q = $this->pdo->prepare('SELECT phone, display_name, crm_client_id FROM wa_conversations WHERE id = ?');
        $q->execute([$convId]);
        $conv = $q->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($conv) || (string)($conv['phone'] ?? '') === '') return self::fail('conversation_missing', 'conversation ' . $convId);

        $chars    = mb_strlen($transcript);
        $keep     = $mode === 'dry_run' ? null : $transcript;
        $secs     = max(0, (int)($meta['seconds'] ?? 0));
        $eventId  = 0;
        $this->pdo->beginTransaction();
        try {
            $u = $this->pdo->prepare("UPDATE wa_media SET status = 'understood', voice_rung = ?, understanding = ?,
                                      understanding_kind = ?, provider = ?, provider_model = ?, provider_ms = ?,
                                      provider_seconds = ?, transcript_chars = ?, failure_reason = NULL,
                                      updated_at = datetime('now') WHERE id = ? AND status <> 'understood'");
            $u->execute([$mode, $keep, $keep === null ? null : 'transcript', (string)($meta['provider'] ?? ''),
                         (string)($meta['model'] ?? ''), (int)($meta['ms'] ?? 0), $meta['provider_seconds'] ?? null, $chars, $id]);
            if ($u->rowCount() !== 1) {
                $this->pdo->rollBack();
                return ['outcome' => 'already_understood'];
            }

            // STOP, exactly as evo_webhook.php reads it off typed text (step 8b), from R2: a proactive opt-out.
            $stop = ContactOptOut::detect($transcript);
            if ($stop['stop']) {
                $oo  = ContactOptOut::fromStore($this->store);
                $res = $oo->add((string)$conv['phone'], [
                    'channel'       => '*',
                    'scope'         => ContactOptOut::SCOPE_PROACTIVE,
                    'reason'        => 'customer_request',
                    'source'        => 'voice_keyword',
                    'evidence'      => mb_substr($transcript, 0, 40),
                    'crm_client_id' => (int)($conv['crm_client_id'] ?? 0),
                ]);
                if (!empty($res['created'])) {
                    ($this->log)('info', sprintf('media #%d: opt-out recorded from the transcript (matched "%s")', $id, $stop['matched']));
                }
            }

            if ($mode === 'reply') {
                // The stored "[AUDIO]" message says what was said: the inbox reads it, and so does the model's history.
                $labelled = self::label($transcript);
                $msgId = (int)($row['message_id'] ?? 0);
                if ($msgId > 0) {
                    $m = $this->pdo->prepare("SELECT metadata FROM wa_messages WHERE id = ? AND media_type = 'audio'");
                    $m->execute([$msgId]);
                    $metaRaw = $m->fetchColumn();
                    if ($metaRaw !== false) {
                        $md = json_decode((string)$metaRaw, true);
                        $md = is_array($md) ? $md : [];
                        $md['voice'] = ['media_id' => $id, 'seconds' => $secs, 'provider' => (string)($meta['provider'] ?? ''),
                                        'transcribed_at' => gmdate('Y-m-d H:i:s')];
                        $this->pdo->prepare("UPDATE wa_messages SET body = ?, metadata = ? WHERE id = ? AND media_type = 'audio'")
                                  ->execute([$labelled, json_encode($md, JSON_UNESCAPED_UNICODE), $msgId]);
                    }
                }
                // The one ai.reply event: the shape evo_webhook.php queues for a typed message, plus where it came from.
                $received = UtcClock::parse((string)($row['created_at'] ?? ''));
                $eventId  = (int)$this->bus->emit('ai.reply', 'conversation', $convId, [
                    'channel'           => (string)($row['channel'] ?? ''),
                    'whatsapp_instance' => (string)($row['instance'] ?? ''),
                    'customer_phone'    => (string)$conv['phone'],
                    'message'           => $labelled,
                    'push_name'         => (string)($conv['display_name'] ?? ''),
                    'wa_message_id'     => (string)($row['wa_message_id'] ?? ''),
                    'remote_jid'        => (string)($row['remote_jid'] ?? ''),
                    'received_at'       => gmdate('c', $received > 0 ? $received : time()),
                    'location'          => null,
                    'origin'            => 'voice',
                    'voice'             => ['media_id' => $id, 'seconds' => $secs, 'chars' => $chars],
                ], 3, 'media_worker');
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;   // the worker retries: the row is not understood, nothing was queued
        }
        if ($mode === 'reply') return ['outcome' => 'understood', 'event_id' => $eventId, 'chars' => $chars];
        return ['outcome' => $mode === 'handover' ? 'handover' : 'recorded', 'chars' => $chars];
    }

    /** Control characters out, whitespace collapsed, trimmed, cut at the policy's limit. */
    public static function normalise(string $text): string
    {
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text);
        if ($t === null) $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text) ?? $text;
        $t = preg_replace('/[ \t]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s*\n\s*/u', "\n", $t) ?? $t;
        $t = trim($t);
        if (mb_strlen($t) > MediaPolicy::VOICE_MAX_TRANSCRIPT_CHARS) {
            $t = rtrim(mb_substr($t, 0, MediaPolicy::VOICE_MAX_TRANSCRIPT_CHARS)) . '…';
        }
        return $t;
    }

    /** The message the assistant reads at R4, and the stored body there: labelled as a transcript, never bare. */
    public static function label(string $transcript): string
    {
        return self::LABEL . ' ' . $transcript;
    }

    private function setStatus(int $id, string $status): void
    {
        $this->pdo->prepare("UPDATE wa_media SET status = ?, updated_at = datetime('now') WHERE id = ?")->execute([$status, $id]);
    }

    private static function fail(string $reason, string $detail): array
    {
        return ['outcome' => 'failed', 'reason' => $reason, 'retryable' => self::REASONS[$reason] ?? false,
                'detail' => self::safeDetail($detail)];
    }

    /** A detail is a short code, status or class name — cut, and never a transcript. */
    private static function safeDetail(string $detail): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $detail) ?? $detail), 0, 120);
    }
}

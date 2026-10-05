<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/MediaBlob.php';
require_once __DIR__ . '/Transcriber.php';
require_once __DIR__ . '/EventBus.php';
require_once __DIR__ . '/ContactOptOut.php';
require_once __DIR__ . '/UtcClock.php';

/**
 * VoiceTranscription — a fetched voice note becomes the customer's turn, through the EXISTING assistant
 * (Batch 2 of the AI communication layer, docs/55 §9, docs/56).
 *
 * MediaWorker calls process() once it holds the audio in memory, for an audio row, only while ai_media_voice (and
 * ai_media_enabled) are on. This class:
 *
 *   - refuses before any provider call what cannot be transcribed: a non-audio type, a note longer than
 *     ai_media_voice_max_seconds, no provider configured;
 *   - asks the ONE provider behind TranscriberPort, with a time budget;
 *   - normalises the text (control characters out, whitespace collapsed, 4,000 characters at most; empty is a failure);
 *   - in one database transaction: marks the wa_media row `understood` with the transcript (guarded by
 *     `status <> 'understood'`, so a retry or a duplicate event can never do this twice), rewrites the stored
 *     "[AUDIO]" message as the labelled transcript so the inbox and the model's history show what was said, runs the
 *     SAME STOP detection the webhook runs on typed text, and queues ONE ordinary ai.reply event — the same shape
 *     evo_webhook.php queues for a typed message, plus `origin: voice`.
 *
 * It never sends a message, never decides a reply, and never keeps audio: after this the existing AiReplyWorker,
 * DishNetAiBrain, ReplyPrivacyGuard, hand-over and audit do what they do for text. Every failure is a fixed reason code
 * with a retryable flag (REASONS); the worker records it and, for a permanent one, hands the conversation to a person
 * through Handover — the customer is never answered from a transcript the system did not get. PHP 7.4 compatible.
 */
final class VoiceTranscription
{
    /** What the assistant reads before the transcript, and what the stored message says. */
    public const LABEL = '[voice message, transcribed]';

    /** Reason code => retried by the EventBus? */
    public const REASONS = [
        'provider_missing'     => false,   // ai_transcription_provider names nothing usable (docs/56)
        'too_long'             => false,   // longer than ai_media_voice_max_seconds, as announced
        'unsupported_audio'    => false,   // not an audio type the policy allows
        'invalid_audio'        => false,   // the provider could not decode it
        'empty_transcript'     => false,   // nothing recognised
        'conversation_missing' => false,   // the row names a conversation that no longer exists
        'provider_error'       => true,    // the provider answered with an error
        'timeout'              => true,    // the provider did not answer in time
    ];

    /** @var \PDO */
    private $pdo;
    private $store;
    /** @var array */
    private $config;
    /** @var TranscriberPort|null */
    private $transcriber;
    /** @var callable */
    private $log;
    /** @var EventBus */
    private $bus;

    public function __construct(\PDO $pdo, $store, array $config, ?TranscriberPort $transcriber, callable $log)
    {
        $this->pdo         = $pdo;
        $this->store       = $store;
        $this->config      = $config;
        $this->transcriber = $transcriber;
        $this->log         = $log;
        $this->bus         = new EventBus($pdo);
    }

    /**
     * @param array     $row   the wa_media row, freshly read (kind, seconds, channel, instance, conversation_id, …)
     * @param MediaBlob $audio the fetched bytes, in memory
     * @return array ['outcome' => 'understood', 'event_id' => int, 'chars' => int]
     *               | ['outcome' => 'already_understood']
     *               | ['outcome' => 'failed', 'reason' => string, 'retryable' => bool, 'detail' => string]
     */
    public function process(array $row, MediaBlob $audio): array
    {
        $id = (int)($row['id'] ?? 0);
        if ((string)($row['kind'] ?? '') !== 'audio') return self::fail('unsupported_audio', 'not an audio message');
        if (!MediaPolicy::allowed('audio', $audio->mimetype)) {
            return self::fail('unsupported_audio', MediaPolicy::baseMime($audio->mimetype) ?: 'no type');
        }
        $secs = max(0, (int)($row['seconds'] ?? 0));
        $max  = MediaPolicy::voiceMaxSeconds($this->config);
        if ($secs > $max) return self::fail('too_long', sprintf('%d s announced, limit %d s', $secs, $max));
        if ($this->transcriber === null) {
            return self::fail('provider_missing', 'ai_transcription_provider is ' . TranscriberFactory::providerName($this->config));
        }

        $this->setStatus($id, 'transcribing');
        try {
            $res = $this->transcriber->transcribe($audio, [
                'mimetype' => (string)$audio->mimetype, 'seconds' => $secs, 'language' => '',
                'channel'  => (string)($row['channel'] ?? ''),
            ], MediaPolicy::voiceTimeoutSeconds($this->config));
        } catch (\Throwable $e) {
            // The class, never the message: a provider's exception text could carry anything.
            return self::fail('provider_error', get_class($e));
        }
        if (!is_array($res) || empty($res['ok'])) {
            $reason = (string)(is_array($res) ? ($res['reason'] ?? '') : '');
            if (!array_key_exists($reason, self::REASONS)) $reason = 'provider_error';
            $retry = is_array($res) && array_key_exists('retryable', $res) ? (bool)$res['retryable'] : self::REASONS[$reason];
            return ['outcome' => 'failed', 'reason' => $reason, 'retryable' => $retry,
                    'detail' => self::safeDetail((string)(is_array($res) ? ($res['detail'] ?? '') : 'no answer'))];
        }
        $text = self::normalise((string)($res['text'] ?? ''));
        if ($text === '') return self::fail('empty_transcript', 'nothing recognised');

        return $this->complete($row, $text, [
            'language' => (string)($res['language'] ?? ''),
            'provider' => $this->transcriber->name(),
            'seconds'  => $secs,
        ]);
    }

    /**
     * The transcript becomes the customer's turn — once.
     *
     * One transaction: the row is marked understood only if it was not already (a retry that fetched again, or a
     * duplicate event, finds the guard and does nothing); the stored message is rewritten; STOP is detected and
     * recorded; the ai.reply event is queued. A database failure rolls all of it back and is thrown, so the worker
     * retries the whole thing — nothing half-done survives.
     */
    public function complete(array $row, string $transcript, array $meta): array
    {
        $id     = (int)($row['id'] ?? 0);
        $convId = (int)($row['conversation_id'] ?? 0);
        $q = $this->pdo->prepare('SELECT phone, display_name, crm_client_id FROM wa_conversations WHERE id = ?');
        $q->execute([$convId]);
        $conv = $q->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($conv) || (string)($conv['phone'] ?? '') === '') return self::fail('conversation_missing', 'conversation ' . $convId);

        $labelled = self::label($transcript);
        $secs     = max(0, (int)($meta['seconds'] ?? 0));
        $this->pdo->beginTransaction();
        try {
            $u = $this->pdo->prepare("UPDATE wa_media SET status = 'understood', understanding = ?, understanding_kind = 'transcript',
                                      failure_reason = NULL, updated_at = datetime('now') WHERE id = ? AND status <> 'understood'");
            $u->execute([$transcript, $id]);
            if ($u->rowCount() !== 1) {
                $this->pdo->rollBack();
                return ['outcome' => 'already_understood'];
            }

            // The stored "[AUDIO]" message says what was said: the inbox reads it, and so does the model's history
            // (AiReplyWorker::buildContext → getMessagesForAi), labelled, exactly as a typed message is stored.
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

            // STOP, exactly as evo_webhook.php reads it off typed text (step 8b): on the raw transcript, before the AI
            // is queued, and the message is still answered — someone who says STOP deserves an acknowledgement.
            $stop = ContactOptOut::detect($transcript);
            if ($stop['stop']) {
                $oo  = ContactOptOut::fromStore($this->store);
                $res = $oo->add((string)$conv['phone'], [
                    'channel'       => '*',
                    'scope'         => ContactOptOut::SCOPE_PROACTIVE,
                    'reason'        => 'customer_request',
                    'source'        => 'voice_keyword',
                    'evidence'      => $transcript,
                    'crm_client_id' => (int)($conv['crm_client_id'] ?? 0),
                ]);
                if (!empty($res['created'])) {
                    ($this->log)('info', sprintf('media #%d: opt-out recorded from the transcript (matched "%s")', $id, $stop['matched']));
                }
            }

            // The one ai.reply event: the shape evo_webhook.php queues for a typed message, plus where it came from.
            $received = UtcClock::parse((string)($row['created_at'] ?? ''));
            $eventId  = $this->bus->emit(
                'ai.reply',
                'conversation',
                $convId,
                [
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
                    'voice'             => ['media_id' => $id, 'seconds' => $secs, 'chars' => mb_strlen($transcript)],
                ],
                3,                 // above normal: a waiting customer, as for text
                'media_worker'
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;   // the worker retries: the row is not understood, nothing was queued
        }
        return ['outcome' => 'understood', 'event_id' => (int)$eventId, 'chars' => mb_strlen($transcript)];
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

    /** The message the assistant reads, and the stored message's body: labelled as a transcript, never bare. */
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

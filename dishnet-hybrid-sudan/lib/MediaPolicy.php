<?php
declare(strict_types=1);

/**
 * MediaPolicy — the limits a customer's voice note must satisfy before a byte of it is fetched or kept, and the voice
 * ladder's runtime predicates (5.18.104, root docs/73 §H.3.2, §H.3.5; ported from the development tree, trimmed to audio).
 *
 * Uganda only and OFF unless switched on: every predicate requires StaffJobsGate::applies($config, $dataDir), the
 * assistant (ai_enabled) and ai_media_enabled, and each higher rung requires every rung below it, whoever set the keys
 * (R-14). The rungs, the one word the webhook, the worker and the tool agree on:
 *
 *   off        — not Uganda, or ai_enabled / ai_media_enabled off: nothing is recorded, nothing fetched;
 *   fetch_only — R1, voice-measure: the note is fetched into memory, hashed, sized, its Opus packets counted, then wiped;
 *   dry_run    — R2: transcribed, and nothing is said or kept but counts (and a spoken STOP);
 *   handover   — R3: every voice note handed to a person, the transcript readable in the Inbox; the body stays [AUDIO];
 *   reply      — R4, per number: the assistant answers the transcript in text.
 *
 * Every limit lives here and nowhere else, clamped to the range the switch tool's `set` allows (§H.3.5), so a value
 * planted by hand can only narrow what the code does. Photos and documents are not handled in this release (D8). PHP 7.4.
 */
final class MediaPolicy
{
    /** Kampala's calendar decides "not after today" for the dates the ladder records (R-16). */
    public const TZ = 'Africa/Kampala';

    public const DEFAULT_MAX_BYTES = 2097152;   // 2 MiB: a two-minute voice note is well under it
    public const MIN_MAX_BYTES     = 65536;
    public const CAP_MAX_BYTES     = 8388608;
    public const DEFAULT_TIMEOUT_S = 20;        // the Evolution fetch
    public const MIN_TIMEOUT_S     = 3;
    public const CAP_TIMEOUT_S     = 25;

    /** What this release fetches: voice notes and other audio. Photos, documents, video and stickers are never recorded. */
    public const SUPPORTED_KINDS = ['audio'];

    public const VOICE_DEFAULT_MAX_SECONDS  = 120;   // longer than this is handed to a person (at R3/R4), not transcribed
    public const VOICE_MIN_SECONDS          = 10;
    public const VOICE_CAP_SECONDS          = 300;
    public const VOICE_DEFAULT_TIMEOUT_S    = 30;    // the provider's whole answer
    public const VOICE_MIN_TIMEOUT_S        = 5;
    public const VOICE_CAP_TIMEOUT_S        = 30;
    public const VOICE_MAX_TRANSCRIPT_CHARS = 4000;  // a transcript is cut here
    public const VOICE_DEFAULT_DAILY_MINUTES = 120;  // every attempt counted; 0 = no call at all
    public const VOICE_CAP_DAILY_MINUTES     = 600;
    public const VOICE_DEFAULT_CONV_DAILY    = 10;   // distinct voice notes per conversation per Kampala day
    public const VOICE_MIN_CONV_DAILY        = 1;
    public const VOICE_CAP_CONV_DAILY        = 50;

    /** The transcription models the owner may choose (D2: Whisper). A change of model is the owner's decision. */
    public const STT_MODELS        = ['whisper-1', 'gpt-transcribe', 'gpt-4o-mini-transcribe', 'gpt-4o-transcribe'];
    public const STT_DEFAULT_MODEL = 'whisper-1';

    public const MODES = ['off', 'fetch_only', 'dry_run', 'handover', 'reply'];

    /**
     * The base media types accepted (the part before any ';' — WhatsApp announces a voice note as
     * "audio/ogg; codecs=opus"). Only Ogg/Opus is transcribed (VoiceTranscription); the others are fetched at R1 so the
     * mix is measured, and refused as unsupported_audio above it.
     */
    public const ALLOWED = [
        'audio' => ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/aac', 'audio/amr',
                    'audio/wav', 'audio/x-wav', 'audio/webm'],
    ];

    /** R1 and above: Uganda, the assistant on, ai_media_enabled on. */
    public static function enabled(array $config, ?string $dataDir): bool
    {
        if (!self::flag($config['ai_enabled'] ?? null) || !self::flag($config['ai_media_enabled'] ?? null)) return false;
        if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
        return StaffJobsGate::applies($config, $dataDir);
    }

    /** R2 and above: R1, ai_media_voice, the provider openai, the Privacy Policy line published (a date ≤ today), the key set. */
    public static function voiceEnabled(array $config, ?string $dataDir): bool
    {
        return self::enabled($config, $dataDir)
            && self::flag($config['ai_media_voice'] ?? null)
            && strtolower(trim((string)($config['ai_transcription_provider'] ?? ''))) === 'openai'
            && self::privacyLineOk($config)
            && self::keySet($config);
    }

    /** R3 and above: R2 and ai_media_voice_handover. */
    public static function voiceHandoverEnabled(array $config, ?string $dataDir): bool
    {
        return self::voiceEnabled($config, $dataDir) && self::flag($config['ai_media_voice_handover'] ?? null);
    }

    /**
     * R4 on this number: R3, the explicit switch ai_media_voice_reply (S2-11: a stale channel list or approval date can
     * never arm R4 on its own), the owner's approval date (≤ today), and the number in the list (or the list `all`).
     */
    public static function voiceReplyEnabled(array $config, ?string $dataDir, string $channel): bool
    {
        return self::voiceHandoverEnabled($config, $dataDir)
            && self::flag($config['ai_media_voice_reply'] ?? null)
            && self::dateNotAfterToday($config['ai_media_voice_reply_approved'] ?? null)
            && self::channelListed($config['ai_media_voice_reply_channels'] ?? null, $channel);
    }

    /** off | fetch_only | dry_run | handover | reply — the one word the webhook, the worker and the tool agree on. */
    public static function voiceMode(array $config, ?string $dataDir, string $channel): string
    {
        if (!self::enabled($config, $dataDir)) return 'off';
        if (self::voiceReplyEnabled($config, $dataDir, $channel)) return 'reply';
        if (self::voiceHandoverEnabled($config, $dataDir)) return 'handover';
        if (self::voiceEnabled($config, $dataDir)) return 'dry_run';
        return 'fetch_only';
    }

    /** The Privacy Policy line naming OpenAI is published: ai_media_voice_privacy_line a parseable date not after today. */
    public static function privacyLineOk(array $config): bool
    {
        return self::dateNotAfterToday($config['ai_media_voice_privacy_line'] ?? null);
    }

    /** Whether an OpenAI key is stored. A boolean only: the key itself never leaves PluginConfig's array (C9). */
    public static function keySet(array $config): bool
    {
        return trim((string)($config['openai_api_key'] ?? '')) !== '';
    }

    public static function maxBytes(array $config): int
    {
        return self::intIn($config['ai_media_max_bytes'] ?? null, self::DEFAULT_MAX_BYTES, self::MIN_MAX_BYTES, self::CAP_MAX_BYTES);
    }

    public static function timeoutSeconds(array $config): int
    {
        return self::intIn($config['ai_media_timeout_s'] ?? null, self::DEFAULT_TIMEOUT_S, self::MIN_TIMEOUT_S, self::CAP_TIMEOUT_S);
    }

    public static function voiceMaxSeconds(array $config): int
    {
        return self::intIn($config['ai_media_voice_max_seconds'] ?? null, self::VOICE_DEFAULT_MAX_SECONDS, self::VOICE_MIN_SECONDS, self::VOICE_CAP_SECONDS);
    }

    public static function voiceTimeoutSeconds(array $config): int
    {
        return self::intIn($config['ai_media_voice_timeout_s'] ?? null, self::VOICE_DEFAULT_TIMEOUT_S, self::VOICE_MIN_TIMEOUT_S, self::VOICE_CAP_TIMEOUT_S);
    }

    public static function voiceDailyMinutes(array $config): int
    {
        return self::intIn($config['ai_media_voice_daily_minutes'] ?? null, self::VOICE_DEFAULT_DAILY_MINUTES, 0, self::VOICE_CAP_DAILY_MINUTES);
    }

    public static function voiceConversationDailyMax(array $config): int
    {
        return self::intIn($config['ai_media_voice_conversation_daily_max'] ?? null, self::VOICE_DEFAULT_CONV_DAILY, self::VOICE_MIN_CONV_DAILY, self::VOICE_CAP_CONV_DAILY);
    }

    /** The configured model, or '' when the stored value is not on the allow-list (no call is made with it). */
    public static function sttModel(array $config): string
    {
        $m = trim((string)($config['ai_media_openai_stt_model'] ?? ''));
        if ($m === '') return self::STT_DEFAULT_MODEL;
        return in_array($m, self::STT_MODELS, true) ? $m : '';
    }

    /** "audio/ogg; codecs=opus" → "audio/ogg"; lower-cased, trimmed. */
    public static function baseMime(string $mime): string
    {
        $m = strtolower(trim((string)explode(';', $mime)[0]));
        return $m;
    }

    public static function kindSupported(string $kind): bool
    {
        return in_array($kind, self::SUPPORTED_KINDS, true);
    }

    public static function allowed(string $kind, string $mime): bool
    {
        $base = self::baseMime($mime);
        if ($base === '') return false;
        return in_array($base, self::ALLOWED[$kind] ?? [], true);
    }

    /** 1/true/on/yes are on; unset, empty and everything else are off. */
    private static function flag($v): bool
    {
        if ($v === null || $v === '') return false;
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true);
    }

    /** A YYYY-MM-DD date that is real and not after today in Kampala; anything else is false. */
    private static function dateNotAfterToday($v): bool
    {
        $s = trim((string)$v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return false;
        $today = (new \DateTime('now', new \DateTimeZone(self::TZ)))->format('Y-m-d');
        return strcmp($s, $today) <= 0;
    }

    /** `all`, or a comma-separated list of channel ids that names this one. */
    private static function channelListed($v, string $channel): bool
    {
        $list = array_values(array_filter(array_map('trim', explode(',', strtolower(trim((string)$v)))), 'strlen'));
        if ($list === []) return false;
        if ($list === ['all']) return true;
        return $channel !== '' && in_array(strtolower(trim($channel)), $list, true);
    }

    private static function intIn($v, int $default, int $min, int $max): int
    {
        $n = (is_int($v) || (is_string($v) && preg_match('/^\s*\d{1,9}\s*$/', $v))) ? (int)$v : $default;
        return max($min, min($max, $n));
    }
}

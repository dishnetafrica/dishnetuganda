<?php
declare(strict_types=1);

/**
 * MediaPolicy — the limits a customer's media must satisfy before a byte of it is fetched or kept (Batch 1, docs/55 §9).
 *
 * Every number and every allowed type lives here and nowhere else. The flag is OFF unless the operator sets it:
 * ai_media_enabled unset or false means the webhook records nothing, queues nothing and the media worker fetches
 * nothing — the plugin behaves exactly as it did before this batch. PHP 7.4 compatible.
 */
final class MediaPolicy
{
    public const DEFAULT_MAX_BYTES = 15 * 1024 * 1024;   // 15 MiB: a voice note is tens of KB, a photo a few MB
    public const MIN_MAX_BYTES     = 64 * 1024;
    public const CAP_MAX_BYTES     = 64 * 1024 * 1024;
    public const DEFAULT_TIMEOUT_S = 20;
    public const MIN_TIMEOUT_S     = 3;
    public const CAP_TIMEOUT_S     = 60;

    /** What Batch 1 will fetch and validate. Video and stickers are recorded as unsupported, never fetched. */
    public const SUPPORTED_KINDS = ['audio', 'image', 'document'];

    // Batch 2 (docs/55 §9, docs/56): voice notes. ai_media_voice is OFF unless set, and it needs ai_media_enabled too.
    public const VOICE_DEFAULT_MAX_SECONDS   = 120;    // longer than this is handed to a person, not transcribed
    public const VOICE_MIN_SECONDS           = 10;
    public const VOICE_CAP_SECONDS           = 600;
    public const VOICE_DEFAULT_TIMEOUT_S     = 30;     // the provider's whole answer, within this
    public const VOICE_MIN_TIMEOUT_S         = 5;
    public const VOICE_CAP_TIMEOUT_S         = 120;
    public const VOICE_MAX_TRANSCRIPT_CHARS  = 4000;   // a transcript is cut here; a voice note is not an essay

    // Batch 3 (docs/55 §9, docs/57): pictures. ai_media_image is OFF unless set, and it needs ai_media_enabled too.
    public const IMAGE_DEFAULT_TIMEOUT_S     = 30;     // the provider's whole answer, within this
    public const IMAGE_MIN_TIMEOUT_S         = 5;
    public const IMAGE_CAP_TIMEOUT_S         = 120;
    public const IMAGE_MAX_SIDE_PX           = 8000;   // read from the header before any provider sees the picture
    public const IMAGE_MAX_PIXELS            = 25000000;
    public const IMAGE_MAX_DESCRIPTION_CHARS = 2000;   // a description is cut here

    // Batch 4 (docs/55 §9, docs/58): documents. ai_media_document is OFF unless set, and it needs ai_media_enabled too.
    // Three settings (the document cap, the PDF page cap, the extraction deadline) and the fixed caps every reader obeys.
    // Batch 5 (docs/60): two more rungs above it, ai_media_document_handover and ai_media_document_reply — see documentMode().
    public const DOCUMENT_DEFAULT_MAX_BYTES     = 10 * 1024 * 1024;   // the fetch cap (ai_media_max_bytes) applies first; the smaller wins
    public const DOCUMENT_DEFAULT_MAX_PAGES     = 20;                 // PDF pages read (Slice 4b, docs/58 D-1); beyond them the text is not "seen whole" — a person
    public const DOCUMENT_MIN_PAGES             = 1;
    public const DOCUMENT_CAP_PAGES             = 200;
    public const DOCUMENT_DEFAULT_TIMEOUT_S     = 20;                 // the whole extraction, checked between bounded steps
    public const DOCUMENT_MIN_TIMEOUT_S         = 5;
    public const DOCUMENT_CAP_TIMEOUT_S         = 60;
    public const DOCUMENT_MAX_SHEETS            = 10;                 // spreadsheets: what is SCANNED for classification
    public const DOCUMENT_MAX_ROWS_PER_SHEET    = 2000;
    public const DOCUMENT_MAX_CELLS             = 20000;
    public const DOCUMENT_MAX_CELL_CHARS        = 200;
    public const DOCUMENT_SHOW_SHEETS           = 2;                  // spreadsheets: what the assistant is SHOWN
    public const DOCUMENT_SHOW_ROWS             = 40;
    public const DOCUMENT_SHOW_COLS             = 12;
    public const DOCUMENT_MAX_TEXT_BYTES        = 1024 * 1024;        // txt / csv bytes read; beyond is truncated
    public const DOCUMENT_MAX_SCAN_CHARS        = 50000;              // characters the classifier reads; beyond, nothing is harmless
    public const DOCUMENT_MAX_BRAIN_CHARS       = 4000;               // the turn the assistant reads is cut here
    public const DOCUMENT_EXCERPT_CHARS         = 300;                // the masked excerpt kept for an evidence class
    public const DOCUMENT_ZIP_MAX_ENTRIES       = 2000;               // OOXML archive: central-directory entries
    public const DOCUMENT_ZIP_MAX_MEMBER_BYTES  = 16 * 1024 * 1024;   // one member, declared and inflated (zlib stops here)
    public const DOCUMENT_ZIP_MAX_TOTAL_BYTES   = 48 * 1024 * 1024;   // the members read, together
    public const DOCUMENT_PDF_MAX_STREAM_BYTES  = 8 * 1024 * 1024;    // one PDF stream inflated to look for a text layer
    public const DOCUMENT_PDF_MAX_OBJECTS       = 50000;

    /**
     * The base media types accepted per kind (the part before any ';' — WhatsApp announces voice notes as
     * "audio/ogg; codecs=opus"). Anything else is refused as unsupported_mime without being kept.
     */
    public const ALLOWED = [
        'audio'    => ['audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/aac', 'audio/amr',
                       'audio/wav', 'audio/x-wav', 'audio/webm'],
        'image'    => ['image/jpeg', 'image/png', 'image/webp'],
        'document' => ['application/pdf',
                       'application/msword',
                       'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                       'application/vnd.ms-excel',
                       'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                       'text/csv', 'text/plain'],
    ];

    public static function enabled(array $config): bool
    {
        return self::flag($config['ai_media_enabled'] ?? null);
    }

    /** Voice notes are transcribed only when BOTH flags are on: ai_media_enabled off wins, whatever ai_media_voice says. */
    public static function voiceEnabled(array $config): bool
    {
        return self::enabled($config) && self::flag($config['ai_media_voice'] ?? null);
    }

    /** Pictures are described only when BOTH flags are on: ai_media_enabled off wins, whatever ai_media_image says. */
    public static function imageEnabled(array $config): bool
    {
        return self::enabled($config) && self::flag($config['ai_media_image'] ?? null);
    }

    public static function imageTimeoutSeconds(array $config): int
    {
        $v = $config['ai_media_image_timeout_s'] ?? null;
        $n = is_numeric($v) ? (int)$v : self::IMAGE_DEFAULT_TIMEOUT_S;
        return max(self::IMAGE_MIN_TIMEOUT_S, min(self::IMAGE_CAP_TIMEOUT_S, $n));
    }

    /** Documents are read only when BOTH flags are on: ai_media_enabled off wins, whatever ai_media_document says. */
    public static function documentEnabled(array $config): bool
    {
        return self::enabled($config) && self::flag($config['ai_media_document'] ?? null);
    }

    /**
     * Batch 5 (docs/60 §2): the document LADDER. Each rung needs every rung below it, so a higher flag can never bypass a
     * lower one, and the four words below are the only states there are:
     *
     *   off       — ai_media_enabled or ai_media_document off: a document is stored as before (and fetched only, when the
     *               media flag alone is on); nothing is read.
     *   dry_run   — ai_media_document on, nothing above it: the document is fetched, extracted, classified and RECORDED on
     *               the wa_media row and the stored message's metadata — and that is all. Nobody is told, nothing is
     *               answered, the stored message's body is untouched (the model's history reads the body, so the extract
     *               never reaches the assistant, now or on a later turn), and a caption is answered as text as it always was.
     *   handover  — ai_media_document_handover on as well: the classification is ACTED ON for a person — a human-only class,
     *               a refusal, a fetch that failed for good or a queue that gave up hands the conversation to a person
     *               (needs_human, the alert, the holding line). Still no AI turn; the body still untouched.
     *   reply     — ai_media_document_reply on as well: a harmless document, seen whole, becomes the customer's turn through
     *               the EXISTING assistant, labelled; the stored body carries the labelled extract (or the evidence label);
     *               a caption is answered once, by that turn (evo_webhook.php step 9b). The only customer-facing document AI.
     */
    public const DOCUMENT_MODES = ['off', 'dry_run', 'handover', 'reply'];

    /** A document's classification is acted on for a person only when the three flags below it are on too. */
    public static function documentHandoverEnabled(array $config): bool
    {
        return self::documentEnabled($config) && self::flag($config['ai_media_document_handover'] ?? null);
    }

    /** The assistant answers a harmless document only when every rung is on: this flag alone, or without hand-over, does nothing. */
    public static function documentReplyEnabled(array $config): bool
    {
        return self::documentHandoverEnabled($config) && self::flag($config['ai_media_document_reply'] ?? null);
    }

    /** off | dry_run | handover | reply — the one word the worker, the extraction, the webhook and the status tool agree on. */
    public static function documentMode(array $config): string
    {
        if (!self::documentEnabled($config)) return 'off';
        if (self::documentReplyEnabled($config)) return 'reply';
        if (self::documentHandoverEnabled($config)) return 'handover';
        return 'dry_run';
    }

    /** The document cap, never above the fetch cap: the smaller of ai_media_document_max_bytes and ai_media_max_bytes. */
    public static function documentMaxBytes(array $config): int
    {
        $v = $config['ai_media_document_max_bytes'] ?? null;
        $n = is_numeric($v) ? (int)$v : self::DOCUMENT_DEFAULT_MAX_BYTES;
        $n = max(self::MIN_MAX_BYTES, min(self::CAP_MAX_BYTES, $n));
        return min($n, self::maxBytes($config));
    }

    public static function documentMaxPages(array $config): int
    {
        $v = $config['ai_media_document_max_pages'] ?? null;
        $n = is_numeric($v) ? (int)$v : self::DOCUMENT_DEFAULT_MAX_PAGES;
        return max(self::DOCUMENT_MIN_PAGES, min(self::DOCUMENT_CAP_PAGES, $n));
    }

    public static function documentTimeoutSeconds(array $config): int
    {
        $v = $config['ai_media_document_timeout_s'] ?? null;
        $n = is_numeric($v) ? (int)$v : self::DOCUMENT_DEFAULT_TIMEOUT_S;
        return max(self::DOCUMENT_MIN_TIMEOUT_S, min(self::DOCUMENT_CAP_TIMEOUT_S, $n));
    }

    public static function voiceMaxSeconds(array $config): int
    {
        $v = $config['ai_media_voice_max_seconds'] ?? null;
        $n = is_numeric($v) ? (int)$v : self::VOICE_DEFAULT_MAX_SECONDS;
        return max(self::VOICE_MIN_SECONDS, min(self::VOICE_CAP_SECONDS, $n));
    }

    public static function voiceTimeoutSeconds(array $config): int
    {
        $v = $config['ai_media_voice_timeout_s'] ?? null;
        $n = is_numeric($v) ? (int)$v : self::VOICE_DEFAULT_TIMEOUT_S;
        return max(self::VOICE_MIN_TIMEOUT_S, min(self::VOICE_CAP_TIMEOUT_S, $n));
    }

    /** 1/true/on/yes are on; unset, empty and everything else are off. */
    private static function flag($v): bool
    {
        if ($v === null || $v === '') return false;
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true);
    }

    public static function maxBytes(array $config): int
    {
        $v = $config['ai_media_max_bytes'] ?? null;
        $n = is_numeric($v) ? (int)$v : self::DEFAULT_MAX_BYTES;
        return max(self::MIN_MAX_BYTES, min(self::CAP_MAX_BYTES, $n));
    }

    public static function timeoutSeconds(array $config): int
    {
        $v = $config['ai_media_timeout_s'] ?? null;
        $n = is_numeric($v) ? (int)$v : self::DEFAULT_TIMEOUT_S;
        return max(self::MIN_TIMEOUT_S, min(self::CAP_TIMEOUT_S, $n));
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
}

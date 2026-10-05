<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';

/**
 * InboundMedia — one shape for every piece of media a customer sends (Batch 1, docs/55 §9).
 *
 * Evolution's messages.upsert envelope carries media under one of five keys, each with its own fields. This reads
 * them into a single normalised array the webhook records and the media worker fetches by:
 *
 *   kind           audio | image | document | video | sticker
 *   mimetype       as announced (e.g. "audio/ogg; codecs=opus"), '' when absent
 *   file_name      documents only
 *   caption        the caption, '' when none — the webhook already answers it as text; it is kept here for Batch 2+
 *   declared_bytes fileLength when announced, else null
 *   seconds        audio duration when announced, else null
 *   ptt            audio: a voice note rather than an audio file
 *   key            remote_jid | id | from_me — what getBase64FromMediaMessage needs
 *   message_type   the envelope key, for the log
 *
 * Nothing here fetches, stores or decides. PHP 7.4 compatible.
 */
final class InboundMedia
{
    public const KINDS = ['audio', 'image', 'document', 'video', 'sticker'];

    private const TYPE_KEYS = [
        'audioMessage'    => 'audio',
        'imageMessage'    => 'image',
        'documentMessage' => 'document',
        'videoMessage'    => 'video',
        'stickerMessage'  => 'sticker',
    ];

    /** @return array|null null when the envelope carries no media */
    public static function fromEvoMessage(array $msg): ?array
    {
        $m = $msg['message'] ?? null;
        if (!is_array($m)) return null;
        // documentWithCaptionMessage wraps a documentMessage (and ephemeral/viewOnce wrappers wrap anything).
        foreach (['documentWithCaptionMessage', 'ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2'] as $wrap) {
            if (isset($m[$wrap]['message']) && is_array($m[$wrap]['message'])) { $m = $m[$wrap]['message']; break; }
        }
        $type = ''; $kind = '';
        foreach (self::TYPE_KEYS as $k => $v) {
            if (isset($m[$k]) && is_array($m[$k])) { $type = $k; $kind = $v; break; }
        }
        if ($kind === '') return null;
        $part = $m[$type];
        $key  = is_array($msg['key'] ?? null) ? $msg['key'] : [];
        $len  = $part['fileLength'] ?? null;
        $secs = $part['seconds'] ?? null;
        return [
            'kind'           => $kind,
            'mimetype'       => trim((string)($part['mimetype'] ?? '')),
            'file_name'      => $kind === 'document' ? trim((string)($part['fileName'] ?? '')) : '',
            'caption'        => trim((string)($part['caption'] ?? '')),
            'declared_bytes' => is_numeric($len) ? (int)$len : null,
            'seconds'        => is_numeric($secs) ? (int)$secs : null,
            'ptt'            => $kind === 'audio' && !empty($part['ptt']),
            'key'            => [
                'remote_jid' => (string)($key['remoteJid'] ?? ''),
                'id'         => (string)($key['id'] ?? ''),
                'from_me'    => !empty($key['fromMe']),
            ],
            'message_type'   => $type,
        ];
    }

    /** The body the conversation store writes for a captionless media message (unchanged since 5.18.x). */
    public static function placeholder(string $kind): string
    {
        return '[' . strtoupper($kind) . ']';
    }

    /**
     * Record a media message once. INSERT OR IGNORE on the UNIQUE wa_message_id: the second delivery of the same
     * message, or a second pass over the same envelope, returns null and changes nothing.
     *
     * @return int|null the wa_media id when this call created the row; null when it already existed or could not be written
     */
    public static function record(\PDO $pdo, int $conversationId, array $media, string $instance, string $channel,
                                  string $waMessageId): ?int
    {
        if ($waMessageId === '' || $conversationId <= 0) return null;
        $msgRowId = null;
        try {
            $q = $pdo->prepare('SELECT id FROM wa_messages WHERE wa_message_id = ? LIMIT 1');
            $q->execute([$waMessageId]);
            $r = $q->fetchColumn();
            if ($r !== false) $msgRowId = (int)$r;
        } catch (\Throwable $e) { /* the placeholder row is optional context */ }

        $st = $pdo->prepare('INSERT OR IGNORE INTO wa_media
            (conversation_id, message_id, wa_message_id, instance, channel, remote_jid, from_me, kind, mimetype,
             file_name, caption, declared_bytes, seconds, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $kind   = (string)$media['kind'];
        $status = MediaPolicy::kindSupported($kind) ? 'pending' : 'unsupported';
        $st->execute([
            $conversationId, $msgRowId, $waMessageId, $instance, $channel,
            (string)($media['key']['remote_jid'] ?? ''), !empty($media['key']['from_me']) ? 1 : 0,
            $kind, (string)($media['mimetype'] ?? '') ?: null, (string)($media['file_name'] ?? '') ?: null,
            (string)($media['caption'] ?? '') ?: null, $media['declared_bytes'] ?? null, $media['seconds'] ?? null,
            $status,
        ]);
        if ($st->rowCount() !== 1) return null;   // ignored: already recorded
        $id = (int)$pdo->lastInsertId();
        if ($status === 'unsupported') {
            $pdo->prepare("UPDATE wa_media SET failure_reason = 'unsupported_kind', updated_at = datetime('now') WHERE id = ?")->execute([$id]);
        }
        return $id;
    }
}

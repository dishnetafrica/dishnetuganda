<?php
declare(strict_types=1);

/**
 * WaInbound — one shape for a WhatsApp message, whatever delivered it.
 *
 * Two transports reach this system and they disagree about almost everything.
 * The webhook receives a flat payload (`message`, `sender`, `type`) from
 * WASender or the pusher; the polling sync receives Evolution's nested
 * envelope (`message.conversation`, `message.imageMessage.caption`,
 * `key.remoteJid`). Each had its own extraction, and the two had drifted:
 *
 *   - the webhook's media branch hardcoded '[TYPE received]' as the body and
 *     DISCARDED the caption, so a photo captioned "is this installed right?"
 *     arrived as a photo with no question attached;
 *   - the cron path extracted captions correctly but never acknowledged the
 *     message, so the same customer got silence instead of a reply.
 *
 * Neither difference was intended. They are what happens when the same job is
 * written twice. This function is the one extraction, and everything after it
 * — identity, authorization, the model, the output guard — sees exactly the
 * same record no matter which transport delivered it.
 *
 * ── IT DOES NOT DOWNLOAD ANYTHING ───────────────────────────────────────
 *
 * A media reference is carried, never fetched. Voice, image and document
 * processing are later phases; this records enough for them to exist without
 * pulling a single byte across the wire today.
 */
final class WaInbound
{
    /** Every modality we recognise. Anything else is 'other'. */
    public const MODALITIES = ['text', 'audio', 'image', 'video', 'document',
                               'sticker', 'location', 'contact', 'other'];

    /**
     * Normalise either payload shape into one record.
     *
     * @param array  $payload the raw event
     * @param string $source  'webhook' or 'cron'
     * @return array{
     *   source:string, message_id:string, phone:string, push_name:string,
     *   channel:string, direction:string, modality:string, mime_type:string,
     *   filename:string, caption:string, text:string, media_ref:string,
     *   received_at:string, has_media:bool, usable:bool, why:string
     * }
     */
    public static function normalise(array $payload, string $source, string $channel = 'support'): array
    {
        $out = [
            'source'      => $source === 'cron' ? 'cron' : 'webhook',
            'message_id'  => '',
            'phone'       => '',
            'push_name'   => '',
            'channel'     => $channel === 'accounts' ? 'accounts' : 'support',
            'direction'   => 'in',
            'modality'    => 'text',
            'mime_type'   => '',
            'filename'    => '',
            'caption'     => '',
            'text'        => '',
            'media_ref'   => '',
            'received_at' => gmdate('Y-m-d H:i:s'),
            'has_media'   => false,
            'usable'      => false,
            'why'         => '',
        ];

        // Evolution's nested envelope, or the flat one. Detected by shape
        // rather than by the caller saying which, so a transport that changes
        // format is handled by the same function.
        $nested = is_array($payload['message'] ?? null) ? $payload['message'] : null;

        // ── who ──────────────────────────────────────────────────────────
        $jid = (string)($payload['key']['remoteJid'] ?? $payload['remoteJid'] ?? '');
        $raw = $jid !== ''
            ? explode('@', $jid)[0]
            : (string)($payload['sender'] ?? $payload['from'] ?? $payload['phone']
                    ?? $payload['contact'] ?? $payload['msisdn'] ?? '');
        $out['phone']     = (string)preg_replace('/[^0-9]/', '', $raw);
        $out['push_name'] = trim((string)($payload['pushName'] ?? $payload['name']
                        ?? $payload['display_name'] ?? $payload['contact_name'] ?? ''));

        $out['message_id'] = trim((string)($payload['key']['id'] ?? $payload['message_id']
                         ?? $payload['id'] ?? $payload['pkId'] ?? ''));

        $fromMe = !empty($payload['key']['fromMe']) || !empty($payload['fromMe']);
        $out['direction'] = $fromMe ? 'out' : 'in';

        $ts = $payload['messageTimestamp'] ?? $payload['timestamp'] ?? null;
        if ($ts !== null && $ts !== '') {
            $unix = is_numeric($ts) ? (int)$ts : (int)strtotime((string)$ts);
            if ($unix > 0) $out['received_at'] = gmdate('Y-m-d H:i:s', $unix);
        }

        // ── what ─────────────────────────────────────────────────────────
        if ($nested !== null) {
            self::fromEvolution($nested, $out);
        } else {
            self::fromFlat($payload, $out);
        }

        // The caption IS the message when there is no separate text. This is
        // the line the webhook path did not have: a caption is the customer's
        // question, and dropping it turns a question into a bare photo.
        if ($out['text'] === '' && $out['caption'] !== '') $out['text'] = $out['caption'];

        $out['has_media'] = $out['modality'] !== 'text';
        $out['usable']    = $out['phone'] !== ''
                         && ($out['text'] !== '' || $out['has_media']);
        if ($out['phone'] === '')                       $out['why'] = 'no sender';
        elseif ($out['text'] === '' && !$out['has_media']) $out['why'] = 'no text and no media';

        return $out;
    }

    /** Evolution's nested message object. */
    private static function fromEvolution(array $m, array &$out): void
    {
        if (isset($m['conversation'])) {
            $out['text'] = trim((string)$m['conversation']);
        } elseif (isset($m['extendedTextMessage']['text'])) {
            $out['text'] = trim((string)$m['extendedTextMessage']['text']);
        }

        $map = [
            'imageMessage'    => 'image',
            'videoMessage'    => 'video',
            'audioMessage'    => 'audio',
            'documentMessage' => 'document',
            'stickerMessage'  => 'sticker',
            'locationMessage' => 'location',
            'contactMessage'  => 'contact',
        ];
        foreach ($map as $key => $modality) {
            if (!isset($m[$key]) || !is_array($m[$key])) continue;
            $node = $m[$key];
            $out['modality']  = $modality;
            $out['mime_type'] = trim((string)($node['mimetype'] ?? ''));
            $out['filename']  = trim((string)($node['fileName'] ?? $node['filename'] ?? ''));
            $out['caption']   = trim((string)($node['caption'] ?? ''));
            $out['media_ref'] = trim((string)($node['url'] ?? $node['directPath'] ?? ''));
            if ($modality === 'location') {
                $lat = (string)($node['degreesLatitude'] ?? '');
                $lng = (string)($node['degreesLongitude'] ?? '');
                if ($lat !== '' && $lng !== '') $out['caption'] = "Location: {$lat},{$lng}";
            }
            break;
        }
    }

    /** The flat WASender / pusher payload. */
    private static function fromFlat(array $p, array &$out): void
    {
        $out['text'] = trim((string)($p['message'] ?? $p['body'] ?? $p['text']
                                  ?? $p['content'] ?? ''));

        $type = strtolower(trim((string)($p['type'] ?? 'text')));
        $out['modality'] = self::modalityOf($type);
        $out['mime_type'] = trim((string)($p['mimetype'] ?? $p['mime_type'] ?? ''));
        $out['filename']  = trim((string)($p['filename'] ?? $p['file_name'] ?? ''));
        $out['caption']   = trim((string)($p['caption'] ?? ''));
        $out['media_ref'] = trim((string)($p['media_url'] ?? $p['url'] ?? ''));

        // A flat payload that names no type but carries a mime type is still
        // media; trusting `type` alone loses those.
        if ($out['modality'] === 'text' && $out['mime_type'] !== '') {
            $out['modality'] = self::modalityOf(explode('/', $out['mime_type'])[0]);
        }
    }

    /** WhatsApp's type words, and mime top-levels, onto our modalities. */
    public static function modalityOf(string $type): string
    {
        $t = strtolower(trim($type));
        if ($t === '' || in_array($t, ['text', 'chat', 'conversation'], true)) return 'text';
        if (in_array($t, ['audio', 'ptt', 'voice'], true))                     return 'audio';
        if (in_array($t, ['image', 'photo'], true))                            return 'image';
        if ($t === 'video')                                                    return 'video';
        if (in_array($t, ['document', 'application', 'file'], true))           return 'document';
        if ($t === 'sticker')                                                  return 'sticker';
        if ($t === 'location')                                                 return 'location';
        if (in_array($t, ['contact', 'vcard'], true))                          return 'contact';
        return 'other';
    }

    /**
     * The body stored for a message, so the conversation stays readable.
     *
     * A caption is the customer's own words and is stored as such. Media with
     * no caption gets a marker, because a blank row in a conversation reads as
     * nothing having happened.
     */
    public static function storableBody(array $msg): string
    {
        $text = trim((string)($msg['text'] ?? ''));
        if ($text !== '') return $text;
        $mod = (string)($msg['modality'] ?? 'text');
        return $mod === 'text' ? '' : '[' . strtoupper($mod) . ']';
    }

    /**
     * Metadata kept against the message for the later multimodal phases.
     *
     * @return array<string,mixed>
     */
    public static function mediaMetadata(array $msg): array
    {
        return [
            'modality'          => (string)($msg['modality'] ?? 'text'),
            'mime_type'         => (string)($msg['mime_type'] ?? ''),
            'filename'          => (string)($msg['filename'] ?? ''),
            'caption'           => (string)($msg['caption'] ?? ''),
            'media_ref'         => (string)($msg['media_ref'] ?? ''),
            'received_at'       => (string)($msg['received_at'] ?? ''),
            'source'            => (string)($msg['source'] ?? ''),
            // Set by the processor once it has decided what it could do.
            'processing_status' => (string)($msg['processing_status'] ?? 'received'),
        ];
    }
}

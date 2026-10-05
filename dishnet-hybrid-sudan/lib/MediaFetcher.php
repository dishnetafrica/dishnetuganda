<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/MediaBlob.php';

/**
 * MediaFetcher — get a customer's media from Evolution, in memory, or say precisely why not (Batch 1, docs/55 §9).
 *
 * Uses the EXISTING Evolution integration: POST /chat/getBase64FromMediaMessage/{instance} with the message key the
 * webhook recorded. The webhook itself stays registered with base64 off — a payload carrying media bytes would meet the
 * webhook's 512 KB body cap — so the bytes are fetched here, by the media worker, never in the request path.
 *
 * Order of checks, cheapest first and before any network call: the key is complete; the kind is one Batch 1 handles;
 * the size the webhook announced is within the limit. Then the fetch, with its own timeout. Then the type Evolution
 * reports against the allow-list, a strict base64 decode, the actual size against the limit, and a sha256. The bytes
 * are returned in a MediaBlob and nowhere else: no file, no log line, no exception message carries them.
 *
 * Every refusal is a fixed code (REASONS) with a retryable flag: a timeout or a 5xx is worth another attempt, a wrong
 * type or an oversized file is not. PHP 7.4 compatible.
 */
final class MediaFetcher
{
    public const REASONS = [
        'missing_identifier' => false,   // no message key to fetch by
        'unsupported_kind'   => false,   // video, sticker
        'unsupported_mime'   => false,   // not in MediaPolicy::ALLOWED
        'too_large'          => false,   // announced or actual size over the limit
        'fetch_failed'       => true,    // Evolution answered with an error, or no answer (see detail)
        'timeout'            => true,    // the fetch did not complete in time
        'malformed'          => true,    // the answer was not a decodable base64 payload
    ];

    /** @var EvolutionApiService */
    private $evo;
    /** @var array */
    private $config;

    public function __construct($evo, array $config)
    {
        $this->evo    = $evo;
        $this->config = $config;
    }

    /**
     * @param array $row a wa_media row (kind, mimetype, declared_bytes, channel, remote_jid, wa_message_id, from_me, file_name)
     * @return array ['ok' => true, 'blob' => MediaBlob] or ['ok' => false, 'reason' => string, 'retryable' => bool, 'detail' => string]
     */
    public function fetch(array $row): array
    {
        $kind = (string)($row['kind'] ?? '');
        if (!MediaPolicy::kindSupported($kind)) return self::refuse('unsupported_kind', $kind);

        $id  = (string)($row['wa_message_id'] ?? '');
        $jid = (string)($row['remote_jid'] ?? '');
        if ($id === '' || $jid === '') return self::refuse('missing_identifier', 'no message key');

        $max = MediaPolicy::maxBytes($this->config);
        $declared = $row['declared_bytes'] ?? null;
        if (is_numeric($declared) && (int)$declared > $max) {
            return self::refuse('too_large', sprintf('announced %d bytes, limit %d', (int)$declared, $max));
        }

        $res = $this->evo->getBase64FromMediaMessage((string)($row['channel'] ?? ''), [
            'remoteJid' => $jid, 'id' => $id, 'fromMe' => !empty($row['from_me']),
        ]);
        if (empty($res['ok'])) {
            $err  = (string)($res['error'] ?? 'no answer');
            $http = (int)($res['http'] ?? 0);
            if ($http >= 400 && $http < 500) return self::refuse('fetch_failed', 'HTTP ' . $http, false);
            if (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) return self::refuse('timeout', 'HTTP ' . $http);
            return self::refuse('fetch_failed', $http > 0 ? 'HTTP ' . $http : 'no connection', true);
        }
        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $mime = trim((string)($data['mimetype'] ?? ''));
        if ($mime === '') $mime = (string)($row['mimetype'] ?? '');
        if (!MediaPolicy::allowed($kind, $mime)) {
            return self::refuse('unsupported_mime', MediaPolicy::baseMime($mime) ?: 'none announced');
        }
        $b64 = $data['base64'] ?? null;
        if (!is_string($b64) || $b64 === '') return self::refuse('malformed', 'no base64 in the answer');
        // Over the limit before decoding: base64 is 4/3 of the bytes, so a payload this long cannot fit.
        if (strlen($b64) > (int)ceil($max * 4 / 3) + 4) return self::refuse('too_large', sprintf('payload over %d bytes', $max));
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') return self::refuse('malformed', 'base64 did not decode');
        if (strlen($bytes) > $max) return self::refuse('too_large', sprintf('%d bytes, limit %d', strlen($bytes), $max));

        return ['ok' => true, 'blob' => new MediaBlob($bytes, $mime, $kind, (string)($data['fileName'] ?? ($row['file_name'] ?? '')))];
    }

    private static function refuse(string $reason, string $detail, ?bool $retryable = null): array
    {
        return ['ok' => false, 'reason' => $reason,
                'retryable' => $retryable ?? (self::REASONS[$reason] ?? false), 'detail' => $detail];
    }
}

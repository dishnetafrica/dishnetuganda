<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaBlob.php';
require_once __DIR__ . '/Transcriber.php';

/**
 * OpenAiTranscriber — a voice note to text through OpenAI's /v1/audio/transcriptions (5.18.104, root docs/73 §H.3.9).
 *
 * The key and the model are read here, from the configuration array, into private properties: the key never travels
 * as a string argument, so no stack trace can carry it (R-08), and __debugInfo() says only whether it is set. The
 * endpoint is OpenAI's, or the tests' loopback fake; no configuration key and no environment variable can name another.
 *
 * One POST per attempt, built in memory (multipart/form-data, no temporary file, no CURLFile), https only, no redirect,
 * the peer and host verified, never verbose. No `language` (forcing one would mistranscribe Luganda or Swahili), no
 * `prompt`, no `response_format` (the default JSON {"text": …} is the one shape read). What leaves this class is a
 * transcript or a fixed reason code with a detail of `HTTP <n>` and OpenAI's error type/code, each cut to
 * [a-z0-9_]{0,40}: never the response body (a 401 may quote the key's tail), never curl_error(). PHP 7.4 compatible.
 */
final class OpenAiTranscriber implements TranscriberPort
{
    public const ENDPOINT      = 'https://api.openai.com/v1/audio/transcriptions';
    public const MODELS        = ['whisper-1', 'gpt-transcribe', 'gpt-4o-mini-transcribe', 'gpt-4o-transcribe'];
    public const DEFAULT_MODEL = 'whisper-1';
    private const LOOPBACK     = '#^http://127\.0\.0\.1:\d{2,5}/#';

    /** @var string */
    private $key;
    /** @var string */
    private $model;
    /** @var string */
    private $endpoint;
    /** @var bool */
    private $usable;

    public function __construct(array $config, string $endpoint = self::ENDPOINT)
    {
        $this->key = trim((string)($config['openai_api_key'] ?? ''));
        $m = trim((string)($config['ai_media_openai_stt_model'] ?? ''));
        $this->model = $m === '' ? self::DEFAULT_MODEL : $m;
        $this->endpoint = $endpoint;
        $this->usable = in_array($this->model, self::MODELS, true)
            && ($endpoint === self::ENDPOINT || preg_match(self::LOOPBACK, $endpoint) === 1);
    }

    public function __debugInfo()
    {
        return ['model' => $this->model, 'key' => $this->key !== '' ? 'set' : 'not set'];
    }

    public function name(): string
    {
        return 'openai:' . $this->model;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function transcribe(MediaBlob $audio, array $hints, int $timeoutSeconds): array
    {
        try {
            return $this->call($audio, $timeoutSeconds);
        } catch (\Throwable $e) {
            return self::out('provider_error', true, get_class($e), 0);
        }
    }

    private function call(MediaBlob $audio, int $timeoutSeconds): array
    {
        if (!$this->usable) return self::out('provider_missing', false, 'not configured', 0);
        $bytes = (string)$audio->bytes;
        do {
            $boundary = bin2hex(random_bytes(16));
        } while (strpos($bytes, $boundary) !== false);
        $body = '--' . $boundary . "\r\n"
              . "Content-Disposition: form-data; name=\"model\"\r\n\r\n"
              . $this->model . "\r\n"
              . '--' . $boundary . "\r\n"
              . "Content-Disposition: form-data; name=\"file\"; filename=\"voice.ogg\"\r\n"
              . "Content-Type: audio/ogg\r\n\r\n"
              . $bytes . "\r\n"
              . '--' . $boundary . "--\r\n";
        unset($bytes);
        $headers = ['Authorization: Bearer ' . $this->key, 'Content-Type: multipart/form-data; boundary=' . $boundary, 'Expect:'];
        $https = $this->endpoint === self::ENDPOINT;
        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => max(1, $timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => $https ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_VERBOSE        => false,
        ]);
        if (!$https) curl_setopt($ch, CURLOPT_PROXY, '');   // the loopback fake is never reached through a proxy
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        unset($body, $headers, $ch);

        if ($raw === false || $errno !== 0) {
            if ($errno === 28) return self::out('timeout', true, 'curl 28', 0);
            return self::out('provider_error', true, 'curl ' . $errno, 0);
        }
        $j = json_decode((string)$raw, true);
        unset($raw);
        $err  = is_array($j) && is_array($j['error'] ?? null) ? $j['error'] : [];
        $type = self::code($err['type'] ?? '');
        $code = self::code($err['code'] ?? '');
        $detail = 'HTTP ' . $http . ($type !== '' ? ' ' . $type : '') . ($code !== '' ? ' ' . $code : '');
        if ($http === 200) {
            if (!is_array($j) || !is_string($j['text'] ?? null)) return self::out('provider_error', true, 'HTTP 200 malformed', 200);
            $secs = null;
            if (isset($j['duration']) && is_numeric($j['duration'])) $secs = (float)$j['duration'];
            elseif (isset($j['usage']['seconds']) && is_numeric($j['usage']['seconds'])) $secs = (float)$j['usage']['seconds'];
            return ['ok' => true, 'text' => (string)$j['text'], 'language' => '', 'duration_s' => $secs, 'http' => 200];
        }
        if ($http === 400) return self::out('provider_rejected', false, $detail, $http);
        if ($http === 401 || $http === 403) return self::out('provider_auth', false, $detail, $http);
        if ($http === 404) return self::out('provider_model', false, $detail, $http);
        if ($http === 413) return self::out('too_large', false, $detail, $http);
        if ($http === 429) {
            if ($code === 'insufficient_quota') return self::out('provider_quota', false, $detail, $http);
            return self::out('provider_busy', true, $detail, $http);
        }
        if ($http >= 500 && $http <= 599) return self::out('provider_error', true, $detail, $http);
        return self::out('provider_error', false, $detail, $http);
    }

    /** OpenAI's error type or code, cut to a fixed shape: nothing else of the answer leaves this class. */
    private static function code($v): string
    {
        if (!is_string($v)) return '';
        return substr((string)preg_replace('/[^a-z0-9_]/', '', strtolower($v)), 0, 40);
    }

    private static function out(string $reason, bool $retryable, string $detail, int $http): array
    {
        return ['ok' => false, 'reason' => $reason, 'retryable' => $retryable, 'detail' => $detail, 'http' => $http];
    }
}

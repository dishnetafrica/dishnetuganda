<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaBlob.php';

/**
 * The transcription boundary (Batch 2 of the AI communication layer, docs/56).
 *
 * Everything on this side — fetching the voice note, validating it, labelling and persisting the transcript, STOP
 * detection, queueing the one ai.reply event — is this plugin's code and runs without a network. Everything on the
 * other side is a provider that does NOT exist in this repository yet: no value of ai_transcription_provider selects a
 * real one, and the operator chooses it (and whether audio may leave the server at all) after reading docs/56.
 *
 * A provider receives the bytes in memory and a time budget, and answers with the result shape below and nothing
 * else: a transcript, or a FIXED reason code with a retryable flag. `detail` may name an HTTP status or an error class
 * and may never carry audio, a transcript, a key, a phone number or a JID. PHP 7.4 compatible.
 */
interface TranscriberPort
{
    /** A short stable name for logs and the record: 'fake', later e.g. 'openai-whisper'. */
    public function name(): string;

    /**
     * @param MediaBlob $audio          the bytes in memory, with mimetype, size and sha256 — never a path
     * @param array     $hints          'mimetype' (as announced), 'seconds' (announced duration, 0 unknown),
     *                                  'language' (BCP-47 hint or ''), 'channel' (sales | support | account)
     * @param int       $timeoutSeconds the whole call must return within this many seconds
     * @return array    ok=true:  ['ok' => true, 'text' => string, 'language' => string, 'duration_s' => float|null]
     *                  ok=false: ['ok' => false, 'reason' => string (VoiceTranscription::REASONS), 'retryable' => bool,
     *                             'detail' => string]
     */
    public function transcribe(MediaBlob $audio, array $hints, int $timeoutSeconds): array;
}

/**
 * Which provider, from the configuration. Null means none: with ai_media_voice on, every voice note is then handed to
 * a person with the reason `provider_missing` — never answered from a guess.
 */
final class TranscriberFactory
{
    /** The fake is constructible only when the test environment names its script file. Production never sets these. */
    public const ENV_FAKE_FILE = 'DN_FAKE_TRANSCRIBER_FILE';
    public const ENV_FAKE_LOG  = 'DN_FAKE_TRANSCRIBER_LOG';

    public static function providerName(array $config): string
    {
        $p = strtolower(trim((string)($config['ai_transcription_provider'] ?? '')));
        return $p === '' ? 'none' : $p;
    }

    public static function fromConfig(array $config): ?TranscriberPort
    {
        $p = self::providerName($config);
        if ($p === 'none') return null;
        if ($p === 'fake') {
            $file = (string)getenv(self::ENV_FAKE_FILE);
            if ($file === '' || !is_file($file)) {
                error_log('[Transcriber] provider "fake" is test-only and its script file is not present — no provider');
                return null;
            }
            return FakeTranscriber::fromFile($file, (string)getenv(self::ENV_FAKE_LOG));
        }
        error_log('[Transcriber] unknown transcription provider "' . $p . '" — no provider (docs/56)');
        return null;
    }
}

/**
 * The deterministic fake: the same audio always gives the same answer, from a script keyed by the audio's sha256.
 *
 *   sha256 => ['text' => 'what was said', 'language' => 'en']          a transcript ('' means nothing recognised)
 *   sha256 => ['fail' => 'timeout' | 'provider_error' | 'invalid_audio' | 'unsupported_audio', 'retryable' => bool?]
 *   '*'    => the answer for any audio not named
 *
 * Unscripted audio with no '*' entry is `invalid_audio`, permanent — a test must say what it expects. It records
 * sha256 prefixes and counts only — never bytes, never the text — in memory and, when asked, to a log file so a test
 * that ran the CLI runner can count the calls afterwards.
 */
final class FakeTranscriber implements TranscriberPort
{
    /** @var array<string,array> */
    private $script;
    /** @var string */
    private $logPath;
    /** @var array<int,array> */
    public $calls = [];

    public function __construct(array $script, string $logPath = '')
    {
        $this->script  = $script;
        $this->logPath = $logPath;
    }

    public static function fromFile(string $path, string $logPath = ''): ?self
    {
        $script = json_decode((string)@file_get_contents($path), true);
        if (!is_array($script)) return null;
        return new self($script, $logPath);
    }

    public function name(): string
    {
        return 'fake';
    }

    public function transcribe(MediaBlob $audio, array $hints, int $timeoutSeconds): array
    {
        $call = ['sha' => substr($audio->sha256, 0, 12), 'bytes' => $audio->size, 'timeout' => $timeoutSeconds,
                 'seconds' => (int)($hints['seconds'] ?? 0)];
        $this->calls[] = $call;
        if ($this->logPath !== '') @file_put_contents($this->logPath, json_encode($call) . "\n", FILE_APPEND);

        $entry = $this->script[$audio->sha256] ?? ($this->script['*'] ?? null);
        if (!is_array($entry)) {
            return ['ok' => false, 'reason' => 'invalid_audio', 'retryable' => false, 'detail' => 'unscripted audio'];
        }
        if (isset($entry['fail'])) {
            $reason = (string)$entry['fail'];
            $retry  = array_key_exists('retryable', $entry) ? (bool)$entry['retryable']
                    : in_array($reason, ['timeout', 'provider_error'], true);
            return ['ok' => false, 'reason' => $reason, 'retryable' => $retry, 'detail' => 'scripted'];
        }
        return ['ok' => true, 'text' => (string)($entry['text'] ?? ''), 'language' => (string)($entry['language'] ?? ''),
                'duration_s' => isset($entry['duration_s']) ? (float)$entry['duration_s'] : null];
    }
}

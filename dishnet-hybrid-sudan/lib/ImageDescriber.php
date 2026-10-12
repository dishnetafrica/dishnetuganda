<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaBlob.php';

/**
 * The image-understanding boundary (Batch 3 of the AI communication layer, docs/57).
 *
 * Everything on this side — fetching the picture, the header and size checks, the payment decision, labelling and
 * persisting the description, queueing the one ai.reply event or handing over — is this plugin's code and runs without a
 * network. Everything on the other side is a vision provider that does NOT exist in this repository yet: no value of
 * ai_image_provider selects a real one, and the operator chooses it (and whether a customer's picture — a payment
 * screenshot carries names, numbers and amounts — may leave the server at all) after reading docs/57.
 *
 * A provider receives the bytes in memory, the header facts and a time budget, and answers with the result shape below
 * and nothing else: a description with a classification and signals, or a FIXED reason code with a retryable flag. The
 * classification is ADVISORY — the payment decision is PaymentEvidence's, this plugin's own. `detail` may name an HTTP
 * status or an error class and may never carry bytes, a description, a key, a phone number or a JID. PHP 7.4 compatible.
 */
interface ImageDescriberPort
{
    /** A short stable name for logs and the record: 'fake', later a real provider's. */
    public function name(): string;

    /**
     * @param MediaBlob $image          the bytes in memory, with mimetype, size and sha256 — never a path
     * @param array     $hints          'mimetype' (from the header), 'width', 'height', 'caption' (the customer's words,
     *                                  '' when none), 'channel' (sales | support | account)
     * @param int       $timeoutSeconds the whole call must return within this many seconds
     * @return array    ok=true:  ['ok' => true, 'description' => string, 'classification' => string
     *                             (ImageUnderstanding::CLASSES), 'signals' => string[]]
     *                  ok=false: ['ok' => false, 'reason' => string (ImageUnderstanding::REASONS), 'retryable' => bool,
     *                             'detail' => string]
     */
    public function describe(MediaBlob $image, array $hints, int $timeoutSeconds): array;
}

/**
 * Which provider, from the configuration. Null means none: with ai_media_image on, every picture is then handed to a
 * person with the reason `provider_missing` — never answered from a guess.
 */
final class ImageDescriberFactory
{
    /** The fake is constructible only when the test environment names its script file. Production never sets these. */
    public const ENV_FAKE_FILE = 'DN_FAKE_IMAGE_FILE';
    public const ENV_FAKE_LOG  = 'DN_FAKE_IMAGE_LOG';

    public static function providerName(array $config): string
    {
        $p = strtolower(trim((string)($config['ai_image_provider'] ?? '')));
        return $p === '' ? 'none' : $p;
    }

    public static function fromConfig(array $config): ?ImageDescriberPort
    {
        $p = self::providerName($config);
        if ($p === 'none') return null;
        if ($p === 'fake') {
            $file = (string)getenv(self::ENV_FAKE_FILE);
            if ($file === '' || !is_file($file)) {
                error_log('[ImageDescriber] provider "fake" is test-only and its script file is not present — no provider');
                return null;
            }
            return FakeImageDescriber::fromFile($file, (string)getenv(self::ENV_FAKE_LOG));
        }
        error_log('[ImageDescriber] unknown image provider "' . $p . '" — no provider (docs/57)');
        return null;
    }
}

/**
 * The deterministic fake: the same picture always gives the same answer, from a script keyed by the image's sha256.
 *
 *   sha256 => ['description' => '…', 'classification' => 'site_photo', 'signals' => ['roof', 'pole']]
 *   sha256 => ['fail' => 'timeout' | 'provider_error' | 'malformed_image' | 'unsupported_mime', 'retryable' => bool?]
 *   '*'    => the answer for any picture not named
 *
 * Unscripted pictures with no '*' entry are `malformed_image`, permanent — a test must say what it expects. It records
 * sha256 prefixes and counts only — never bytes, never a description — in memory and, when asked, to a log file so a
 * test that ran the CLI runner can count the calls afterwards.
 */
final class FakeImageDescriber implements ImageDescriberPort
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

    public function describe(MediaBlob $image, array $hints, int $timeoutSeconds): array
    {
        $call = ['sha' => substr($image->sha256, 0, 12), 'bytes' => $image->size, 'timeout' => $timeoutSeconds,
                 'width' => (int)($hints['width'] ?? 0), 'height' => (int)($hints['height'] ?? 0),
                 'caption_chars' => mb_strlen((string)($hints['caption'] ?? ''))];
        $this->calls[] = $call;
        if ($this->logPath !== '') @file_put_contents($this->logPath, json_encode($call) . "\n", FILE_APPEND);

        $entry = $this->script[$image->sha256] ?? ($this->script['*'] ?? null);
        if (!is_array($entry)) {
            return ['ok' => false, 'reason' => 'malformed_image', 'retryable' => false, 'detail' => 'unscripted image'];
        }
        if (isset($entry['fail'])) {
            $reason = (string)$entry['fail'];
            $retry  = array_key_exists('retryable', $entry) ? (bool)$entry['retryable']
                    : in_array($reason, ['timeout', 'provider_error'], true);
            return ['ok' => false, 'reason' => $reason, 'retryable' => $retry, 'detail' => 'scripted'];
        }
        return ['ok' => true, 'description' => (string)($entry['description'] ?? ''),
                'classification' => (string)($entry['classification'] ?? 'general'),
                'signals' => array_values(array_map('strval', (array)($entry['signals'] ?? [])))];
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaBlob.php';

/**
 * The document OCR boundary (Batch 4 of the AI communication layer, docs/58 §13) — EMPTY by decision D-2.
 *
 * A scanned document (a PDF with no text layer) has nothing this plugin can read. The operator decided (docs/58 D-2) that no
 * OCR exists for now: no tesseract, no sidecar, no hosted service. This interface exists so that such a document already has a
 * defined fate — `provider_missing`, a person — and so that the day a provider is chosen it is one class behind one interface,
 * exactly as the voice and image providers are. No value of ai_document_provider selects anything; the deterministic fake is
 * constructible only in the test environment. PHP 7.4 compatible.
 */
interface DocumentOcrPort
{
    /** A short stable name for logs and the record: 'fake', later a real provider's. */
    public function name(): string;

    /**
     * @param MediaBlob $document       the bytes in memory — never a path — with mimetype, size, sha256
     * @param array     $hints          'mimetype', 'pages' (announced total, 0 unknown), 'max_pages' (read no more than this),
     *                                  'channel' (sales | support | account)
     * @param int       $timeoutSeconds the whole call must return within this many seconds
     * @return array    ok=true:  ['ok' => true, 'text' => string, 'pages' => int (pages read)]
     *                  ok=false: ['ok' => false, 'reason' => string (DocumentExtraction::REASONS), 'retryable' => bool, 'detail' => string]
     */
    public function ocr(MediaBlob $document, array $hints, int $timeoutSeconds): array;
}

/**
 * Which provider, from the configuration. Null means none: a scanned document is then handed to a person with the reason
 * `provider_missing` — never answered from a guess.
 */
final class DocumentOcrFactory
{
    /** The fake is constructible only when the test environment names its script file. Production never sets these. */
    public const ENV_FAKE_FILE = 'DN_FAKE_DOCUMENT_OCR_FILE';
    public const ENV_FAKE_LOG  = 'DN_FAKE_DOCUMENT_OCR_LOG';

    public static function providerName(array $config): string
    {
        $p = strtolower(trim((string)($config['ai_document_provider'] ?? '')));
        return $p === '' ? 'none' : $p;
    }

    public static function fromConfig(array $config): ?DocumentOcrPort
    {
        $p = self::providerName($config);
        if ($p === 'none') return null;
        if ($p === 'fake') {
            $file = (string)getenv(self::ENV_FAKE_FILE);
            if ($file === '' || !is_file($file)) {
                error_log('[DocumentOcr] provider "fake" is test-only and its script file is not present — no provider');
                return null;
            }
            return FakeDocumentOcr::fromFile($file, (string)getenv(self::ENV_FAKE_LOG));
        }
        error_log('[DocumentOcr] unknown document provider "' . $p . '" — no provider (docs/58)');
        return null;
    }
}

/**
 * The deterministic fake: the same document always gives the same answer, from a script keyed by its sha256.
 *
 *   sha256 => ['text' => 'what the pages say', 'pages' => 2]
 *   sha256 => ['fail' => 'timeout' | 'provider_error' | 'malformed_document', 'retryable' => bool?]
 *   '*'    => the answer for any document not named
 *
 * Unscripted documents with no '*' entry are `malformed_document`, permanent — a test must say what it expects. It records
 * sha256 prefixes and counts only — never bytes, never text — in memory and, when asked, to a log file.
 */
final class FakeDocumentOcr implements DocumentOcrPort
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

    public function ocr(MediaBlob $document, array $hints, int $timeoutSeconds): array
    {
        $call = ['sha' => substr($document->sha256, 0, 12), 'bytes' => $document->size, 'timeout' => $timeoutSeconds,
                 'pages' => (int)($hints['pages'] ?? 0), 'max_pages' => (int)($hints['max_pages'] ?? 0)];
        $this->calls[] = $call;
        if ($this->logPath !== '') @file_put_contents($this->logPath, json_encode($call) . "\n", FILE_APPEND);

        $entry = $this->script[$document->sha256] ?? ($this->script['*'] ?? null);
        if (!is_array($entry)) {
            return ['ok' => false, 'reason' => 'malformed_document', 'retryable' => false, 'detail' => 'unscripted document'];
        }
        if (isset($entry['fail'])) {
            $reason = (string)$entry['fail'];
            $retry  = array_key_exists('retryable', $entry) ? (bool)$entry['retryable']
                    : in_array($reason, ['timeout', 'provider_error'], true);
            return ['ok' => false, 'reason' => $reason, 'retryable' => $retry, 'detail' => 'scripted'];
        }
        return ['ok' => true, 'text' => (string)($entry['text'] ?? ''), 'pages' => (int)($entry['pages'] ?? 1)];
    }
}

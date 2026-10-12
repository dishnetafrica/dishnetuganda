<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/MediaBlob.php';
require_once __DIR__ . '/DocumentDeadline.php';
require_once __DIR__ . '/DocumentSniffer.php';
require_once __DIR__ . '/TextReader.php';
require_once __DIR__ . '/DocxReader.php';
require_once __DIR__ . '/SheetReader.php';
require_once __DIR__ . '/PdfReader.php';
require_once __DIR__ . '/DocumentOcr.php';
require_once __DIR__ . '/DocumentClassifier.php';
require_once __DIR__ . '/PaymentEvidence.php';
require_once __DIR__ . '/EventBus.php';
require_once __DIR__ . '/UtcClock.php';

/**
 * DocumentExtraction — a fetched document becomes the customer's turn through the EXISTING assistant, or a record and a
 * person's job, or a reason and a person's job (Batch 4 of the AI communication layer, Slice 4a; docs/55 §9, docs/58).
 *
 * MediaWorker calls process() once it holds the bytes in memory, for a document row, only while ai_media_document (and
 * ai_media_enabled) are on. In order, all inside the plugin process, no library, no network:
 *
 *   1. the caps: the document size against ai_media_document_max_bytes and the memory budget; a deadline for everything below;
 *   2. the sniff: the CONTENT names the kind — PDF, Word, Excel, CSV, text — or refuses it (an image, a legacy or macro-enabled
 *      Office file, an encrypted file, an archive that is not Word or Excel, bytes that are no document);
 *   3. the reader for that kind, bounded: Word paragraphs, Excel and CSV cells, text lines; a PDF yields FACTS only in this slice
 *      — encrypted → a person; a text layer → read by PdfReader (Slice 4b, D-1 = P-1), a layer that yields no text → `pdf_no_text`,
 *        a person; scanned → the empty OCR boundary → a person;
 *   4. the classifier (DocumentClassifier): seven human-only classes, two the assistant may see, and FAIL CLOSED everywhere else;
 *   5. in one transaction: the wa_media row `understood` (guarded, so never twice), the stored "[DOCUMENT]" message rewritten
 *      under its label with what the record policy allows, and then EITHER one ordinary ai.reply event (origin document) OR
 *      nothing queued at all — the worker hands over.
 *
 * It never sends a message, never decides a reply, never reads or writes an invoice, a payment, a ledger, a KYC record or a CRM
 * record, never keeps the document, never logs a line of it. Every failure is a fixed reason code (REASONS); local extraction
 * has no retryable failure, because repeating it changes nothing. PHP 7.4 compatible.
 */
final class DocumentExtraction
{
    /** What the assistant reads before the extract, and what the stored message says. The facts follow after " — ". */
    public const LABEL          = '[document, text extracted automatically';
    public const LABEL_EVIDENCE = '[document, %s — a colleague will handle it]';
    public const LABEL_CAPTION  = '[caption from the customer]';

    public const KIND_NAMES = ['pdf' => 'PDF', 'docx' => 'Word document', 'xlsx' => 'Excel workbook', 'csv' => 'CSV file', 'txt' => 'text file'];

    /** How a human-only class is named in the stored message and the record. */
    public const CLASS_WORDS = [
        'payment_proof'     => 'payment evidence',
        'statement'         => 'bank or mobile-money statement',
        'invoice'           => 'invoice',
        'contract'          => 'contract or agreement',
        'quotation'         => 'quotation',
        'identity_document' => 'identity document',
        'credential'        => 'document containing a credential',
    ];

    /** What the record keeps for each class (docs/58 §8, §11; D-7): nothing, a masked excerpt, or the capped text the assistant read. */
    public const RECORD = [
        'credential' => 'none', 'identity_document' => 'none',
        'payment_proof' => 'excerpt', 'statement' => 'excerpt', 'invoice' => 'excerpt', 'contract' => 'excerpt', 'quotation' => 'excerpt',
        'general' => 'text', 'spreadsheet' => 'text',
    ];

    /** Reason code => retried by the EventBus? Only the OCR boundary can fail in a way worth retrying. */
    public const REASONS = [
        'provider_missing'          => false,   // a scanned document and no OCR provider (D-2: none)
        'unsupported_mime'          => false,   // an image, or a zip that is not Word or Excel, behind a document label
        'unsupported_document'      => false,   // legacy binary Office, macro-enabled, zip64, an unknown compression
        'malformed_document'        => false,   // not a document, or a part that does not parse, or a DTD
        'too_large_document'        => false,   // over the document cap, a member cap, a stream cap, or the memory budget
        'password_protected'        => false,   // encrypted PDF, encrypted Office package or archive entry
        'pdf_no_text'               => false,   // a PDF with a text layer that yielded no text (Slice 4b): a person, never a guess
        'empty_extraction'          => false,   // nothing readable came out
        'extractor_unavailable'     => false,   // a PHP extension the reader needs is missing here
        'too_slow'                  => false,   // the deadline passed — permanent, a repeat would be as slow
        'conversation_missing'      => false,   // the row names a conversation that no longer exists
        'classification_failed'     => false,   // the classifier threw — a person, never a guess
        'classification_uncertain'  => false,   // a sensitive signal without a decision — a person
        'classification_incomplete' => false,   // the readers could not finish the text — a person
        'provider_error'            => true,    // the OCR provider answered with an error
        'timeout'                   => true,    // the OCR provider did not answer in time
    ];

    /** The operator-facing hand-over reasons for the human-only classes (docs/58 §6.4; D-8 — tested word for word). */
    public const HANDOVER = [
        'statement'         => 'a bank or mobile-money statement arrived — verify it in the books; nothing was recorded',
        'invoice'           => 'an invoice arrived — check it against uCRM; nothing was recorded or marked paid',
        'contract'          => 'a contract or agreement arrived — a person must read it; nothing was accepted or approved',
        'quotation'         => 'a quotation arrived — a person decides; no price was promised',
        'identity_document' => 'an identity document arrived — handle it under the KYC process; nothing was decided and nothing was stored',
        'credential'        => 'a document containing what looks like a password or key arrived — do not forward it; advise the customer to change it; nothing was stored',
    ];

    /** For tests only: pretend a capability is missing (['gzinflate' => false]). Production never sets this. */
    public static $capabilities = null;

    /** @var \PDO */
    private $pdo;
    private $store;
    /** @var array */
    private $config;
    /** @var DocumentOcrPort|null */
    private $ocr;
    /** @var callable */
    private $log;
    /** @var EventBus */
    private $bus;
    /** @var DocumentDeadline|null injected by a test */
    private $deadline = null;

    public function __construct(\PDO $pdo, $store, array $config, ?DocumentOcrPort $ocr, callable $log)
    {
        $this->pdo    = $pdo;
        $this->store  = $store;
        $this->config = $config;
        $this->ocr    = $ocr;
        $this->log    = $log;
        $this->bus    = new EventBus($pdo);
    }

    /** For tests: the deadline to use instead of one built from ai_media_document_timeout_s. */
    public function useDeadline(?DocumentDeadline $deadline): void
    {
        $this->deadline = $deadline;
    }

    /**
     * @return array ['outcome' => 'understood', 'event_id' => int, 'chars' => int, 'classification' => string, 'kind' => string, 'mode' => 'reply']
     *               | ['outcome' => 'evidence', 'classification' => string, 'kind' => string, 'chars' => int, 'mode' => 'handover'|'reply']
     *               | ['outcome' => 'recorded', 'route' => 'brain'|'evidence', 'classification' => string, 'kind' => string, 'chars' => int, 'mode' => 'dry_run'|'handover']
     *               | ['outcome' => 'already_understood']
     *               | ['outcome' => 'failed', 'reason' => string, 'retryable' => bool, 'detail' => string]
     *
     * Batch 5 (docs/60 §3): `recorded` is the dry-run and hand-over-mode outcome — the classification and the record were
     * written, no AI turn was queued and (dry run) nobody is to be told; the worker logs it and does nothing else.
     */
    public function process(array $row, MediaBlob $doc): array
    {
        $id = (int)($row['id'] ?? 0);
        $t0 = microtime(true);   // Batch 5 (docs/60 §4): the processing time, recorded as a number beside the classification
        if ((string)($row['kind'] ?? '') !== 'document') return self::fail('malformed_document', 'not a document message');

        $max = MediaPolicy::documentMaxBytes($this->config);
        if ($doc->size > $max) return self::fail('too_large_document', sprintf('%d bytes, limit %d', $doc->size, $max));
        $budget = self::memoryLimitBytes();
        if ($budget > 0 && memory_get_usage() + $doc->size * 4 + MediaPolicy::DOCUMENT_ZIP_MAX_MEMBER_BYTES > $budget) {
            return self::fail('too_large_document', sprintf('%d bytes would not fit the memory budget', $doc->size));
        }
        $deadline = $this->deadline ?? new DocumentDeadline((float)MediaPolicy::documentTimeoutSeconds($this->config));
        $fileName = trim((string)($doc->fileName !== '' ? $doc->fileName : ($row['file_name'] ?? '')));

        $this->setStatus($id, 'extracting');
        $facts = ['kind' => '', 'mime' => '', 'pages_total' => 0, 'pages_read' => 0, 'rows' => 0, 'sheets' => 0, 'paragraphs' => 0, 'lines' => 0];
        $raw = ''; $brainRaw = null; $sawEverything = true; $provider = ''; $sheetNames = [];
        try {
            $sn = DocumentSniffer::sniff($doc->bytes, $doc->mimetype, $deadline);
            $kind = $sn['kind'];
            $facts['kind'] = $kind; $facts['mime'] = $sn['mime'];
            switch ($kind) {
                case 'pdf':
                    $pf = PdfReader::facts($doc->bytes, $deadline);
                    $facts['pages_total'] = (int)$pf['pages_total'];
                    if ($pf['encrypted']) return self::fail('password_protected', 'encrypted PDF');
                    if ($pf['has_text_layer']) {
                        // Slice 4b (docs/58 D-1 = P-1): the text layer, read in this process by PdfReader — FlateDecode, object
                        // streams, simple fonts and ToUnicode CMaps; anything else refuses, and a layer that yields no text is a
                        // person (pdf_no_text), never a guess. The page cap, the character cap and the deadline bind inside.
                        self::requireCapabilities(['gzuncompress', 'inflate_init']);
                        $pt = PdfReader::text($doc->bytes, $deadline, MediaPolicy::documentMaxPages($this->config), MediaPolicy::DOCUMENT_MAX_SCAN_CHARS);
                        $facts['pages_total'] = max($facts['pages_total'], (int)$pt['pages_total']);
                        $facts['pages_read']  = (int)$pt['pages_read'];
                        $raw = (string)$pt['text'];
                        $sawEverything = !$pt['truncated'];
                        if (trim(self::normalise($raw)) === '') {
                            return self::fail('pdf_no_text', sprintf('%d page%s, a text layer that yielded no text', $facts['pages_total'], $facts['pages_total'] === 1 ? '' : 's'));
                        }
                        break;
                    }
                    // Scanned, or nothing to read: the OCR boundary, which is empty (D-2).
                    if ($this->ocr === null) {
                        return self::fail('provider_missing', sprintf('scanned PDF, %d page%s; ai_document_provider is %s',
                            $pf['pages_total'], $pf['pages_total'] === 1 ? '' : 's', DocumentOcrFactory::providerName($this->config)));
                    }
                    $maxPages = MediaPolicy::documentMaxPages($this->config);
                    try {
                        $res = $this->ocr->ocr($doc, ['mimetype' => 'application/pdf', 'pages' => (int)$pf['pages_total'], 'max_pages' => $maxPages,
                                                      'channel' => (string)($row['channel'] ?? '')],
                                               max(1, min(MediaPolicy::documentTimeoutSeconds($this->config), (int)ceil($deadline->remaining()))));
                    } catch (\Throwable $e) {
                        return self::fail('provider_error', get_class($e));   // the class, never the message
                    }
                    if (!is_array($res) || empty($res['ok'])) {
                        $reason = (string)(is_array($res) ? ($res['reason'] ?? '') : '');
                        if (!array_key_exists($reason, self::REASONS)) $reason = 'provider_error';
                        $retry = is_array($res) && array_key_exists('retryable', $res) ? (bool)$res['retryable'] : self::REASONS[$reason];
                        return ['outcome' => 'failed', 'reason' => $reason, 'retryable' => $retry,
                                'detail' => self::safeDetail((string)(is_array($res) ? ($res['detail'] ?? '') : 'no answer'))];
                    }
                    $raw = (string)($res['text'] ?? '');
                    $facts['pages_read'] = min($maxPages, max(0, (int)($res['pages'] ?? 0)));
                    $sawEverything = (int)($res['pages'] ?? 0) <= $maxPages;
                    $provider = $this->ocr->name();
                    break;
                case 'docx':
                    self::requireCapabilities(['gzinflate', 'XMLReader']);
                    $dr = DocxReader::read($sn['archive'], $deadline, MediaPolicy::DOCUMENT_MAX_SCAN_CHARS);
                    $raw = (string)$dr['text'];
                    $facts['paragraphs'] = (int)$dr['paragraphs'];
                    $sawEverything = !$dr['truncated'];
                    break;
                case 'xlsx':
                    self::requireCapabilities(['gzinflate', 'XMLReader']);
                    $sr = SheetReader::read($sn['archive'], $deadline);
                    $raw = SheetReader::render($sr['sheets']);
                    $brainRaw = SheetReader::render($sr['sheets'], MediaPolicy::DOCUMENT_SHOW_SHEETS, MediaPolicy::DOCUMENT_SHOW_ROWS, MediaPolicy::DOCUMENT_SHOW_COLS);
                    $facts['rows'] = (int)$sr['rows_total']; $facts['sheets'] = (int)$sr['sheets_total'];
                    $sawEverything = !$sr['truncated'];
                    foreach ($sr['sheets'] as $s) $sheetNames[] = (string)$s['name'];
                    break;
                case 'csv':
                    $sr = TextReader::csv($doc->bytes, $sn['encoding'], $sn['delimiter'], $deadline);
                    $raw = SheetReader::render($sr['sheets']);
                    $brainRaw = SheetReader::render($sr['sheets'], MediaPolicy::DOCUMENT_SHOW_SHEETS, MediaPolicy::DOCUMENT_SHOW_ROWS, MediaPolicy::DOCUMENT_SHOW_COLS);
                    $facts['rows'] = (int)$sr['rows_total']; $facts['sheets'] = 1;
                    $sawEverything = !$sr['truncated'];
                    break;
                case 'txt':
                    $tr = TextReader::text($doc->bytes, $sn['encoding'], $deadline);
                    $raw = (string)$tr['text'];
                    $facts['lines'] = (int)$tr['lines'];
                    $sawEverything = !$tr['truncated'];
                    break;
                default:
                    return self::fail('malformed_document', 'unknown kind');
            }
            $deadline->check('after reading');
        } catch (DocumentRefused $e) {
            return self::fail($e->reason, $e->detail);
        } catch (\Throwable $e) {
            return self::fail('malformed_document', get_class($e));   // a parser crash is a refusal; the class, never the message
        }

        $text = self::normalise($raw);
        if ($text === '') return self::fail('empty_extraction', 'nothing readable');
        $scan = $text;
        if (mb_strlen($scan) > MediaPolicy::DOCUMENT_MAX_SCAN_CHARS) { $scan = mb_substr($scan, 0, MediaPolicy::DOCUMENT_MAX_SCAN_CHARS); $sawEverything = false; }
        $signals = self::signals($fileName, $sheetNames);
        $cls = DocumentClassifier::classify($scan, $kind, $signals, $sawEverything);
        $class = $cls['class'];
        if ($cls['route'] === 'human' && $class === null) {
            return self::fail((string)$cls['reason'], implode(',', array_slice((array)$cls['hits'], 0, 6)));
        }
        if ($cls['route'] === 'human') {
            return $this->complete($row, ['route' => 'evidence', 'class' => (string)$class, 'kind' => $kind, 'facts' => $facts, 'provider' => $provider,
                                          'excerpt' => self::RECORD[$class] === 'excerpt' ? self::maskExcerpt($text) : '', 'truncated' => !$sawEverything,
                                          'ms' => (int)round((microtime(true) - $t0) * 1000)]);
        }
        [$capped, $truncated] = self::cut(self::normalise($brainRaw ?? $text), MediaPolicy::DOCUMENT_MAX_BRAIN_CHARS);
        if ($brainRaw !== null && !$truncated && mb_strlen($text) > mb_strlen(self::normalise($brainRaw))) $truncated = true;
        return $this->complete($row, ['route' => 'brain', 'class' => (string)$class, 'kind' => $kind, 'facts' => $facts, 'provider' => $provider,
                                      'text' => $capped, 'truncated' => $truncated, 'ms' => (int)round((microtime(true) - $t0) * 1000)]);
    }

    /**
     * The record, and either the customer's turn or a person's job — once.
     *
     * One transaction: the row is marked understood only if it was not already (a retry that fetched again, or a duplicate
     * event, finds the guard and does nothing); the stored message is rewritten under its label with what the record policy
     * allows; for a human-only class nothing is queued (the worker hands over after this returns); otherwise the one ai.reply
     * event is queued. A database failure rolls all of it back and is thrown, so the worker retries the whole thing.
     *
     * Batch 5 (docs/60 §3): what happens is decided by MediaPolicy::documentMode(). The wa_media record (understanding,
     * understanding_kind, the record policy) is the same in every mode — it is what the dry run exists to observe. The stored
     * message's BODY is rewritten only in `reply` mode, because the body is what the model reads as history on a later turn
     * (AiReplyWorker, getMessagesForAi); in `dry_run` and `handover` only its metadata is written, so the extract never
     * reaches the assistant. The ai.reply event is queued only in `reply` mode. A human-only class is returned as `evidence`
     * (a person is told) only in `handover` and `reply`; in `dry_run` it is `recorded`, like everything else.
     */
    public function complete(array $row, array $r): array
    {
        $id       = (int)($row['id'] ?? 0);
        $convId   = (int)($row['conversation_id'] ?? 0);
        $evidence = (string)($r['route'] ?? '') === 'evidence';
        $mode     = MediaPolicy::documentMode($this->config);
        $reply    = $mode === 'reply';
        $tell     = $mode === 'reply' || $mode === 'handover';   // may a person be told what arrived?
        $class    = (string)($r['class'] ?? 'general');
        $kind     = (string)($r['kind'] ?? '');
        $facts    = (array)($r['facts'] ?? []);
        $caption  = trim((string)($row['caption'] ?? ''));
        $record   = $evidence ? (self::RECORD[$class] ?? 'none') : 'text';
        $q = $this->pdo->prepare('SELECT phone, display_name FROM wa_conversations WHERE id = ?');
        $q->execute([$convId]);
        $conv = $q->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($conv) || (string)($conv['phone'] ?? '') === '') return self::fail('conversation_missing', 'conversation ' . $convId);

        $understanding = $evidence ? ($record === 'excerpt' ? (string)($r['excerpt'] ?? '') : '') : (string)($r['text'] ?? '');
        $labelled = $evidence ? self::evidenceLabel($class, $understanding, $caption) : self::label($kind, $facts, !empty($r['truncated']), $understanding, $caption);
        $this->pdo->beginTransaction();
        try {
            $u = $this->pdo->prepare("UPDATE wa_media SET status = 'understood', understanding = ?, understanding_kind = ?,
                                      failure_reason = NULL, updated_at = datetime('now') WHERE id = ? AND status <> 'understood'");
            $u->execute([$understanding, $evidence ? 'document_evidence' : 'extraction', $id]);
            if ($u->rowCount() !== 1) {
                $this->pdo->rollBack();
                return ['outcome' => 'already_understood'];
            }

            // The stored "[DOCUMENT]" message says what arrived: the inbox reads it (a person handling an invoice or a receipt
            // reads it here, against the books), and so does the model's history for a later turn — labelled, and only what
            // the record policy allows.
            $msgId = (int)($row['message_id'] ?? 0);
            if ($msgId > 0) {
                $m = $this->pdo->prepare("SELECT metadata FROM wa_messages WHERE id = ? AND media_type = 'document'");
                $m->execute([$msgId]);
                $metaRaw = $m->fetchColumn();
                if ($metaRaw !== false) {
                    $md = json_decode((string)$metaRaw, true);
                    $md = is_array($md) ? $md : [];
                    $md['document'] = ['media_id' => $id, 'kind' => $kind, 'classification' => $class, 'pages_total' => (int)($facts['pages_total'] ?? 0),
                                       'pages_read' => (int)($facts['pages_read'] ?? 0), 'rows' => (int)($facts['rows'] ?? 0), 'truncated' => !empty($r['truncated']),
                                       'evidence' => $evidence, 'record' => $record, 'provider' => (string)($r['provider'] ?? ''), 'extracted_at' => gmdate('Y-m-d H:i:s'),
                                       // Batch 5 (docs/60 §4): the mode this ran under, the processing time, and whether the body carries the record.
                                       'mode' => $mode, 'ms' => max(0, (int)($r['ms'] ?? 0)), 'body_rewritten' => $reply];
                    if ($reply) {
                        $this->pdo->prepare("UPDATE wa_messages SET body = ?, metadata = ? WHERE id = ? AND media_type = 'document'")
                                  ->execute([$labelled, json_encode($md, JSON_UNESCAPED_UNICODE), $msgId]);
                    } else {
                        // dry_run / handover: the record goes to the metadata only; the body the model reads stays as the webhook stored it.
                        $this->pdo->prepare("UPDATE wa_messages SET metadata = ? WHERE id = ? AND media_type = 'document'")
                                  ->execute([json_encode($md, JSON_UNESCAPED_UNICODE), $msgId]);
                    }
                }
            }

            if ($evidence) {
                $this->pdo->commit();
                if (!$tell) {
                    ($this->log)('info', sprintf('media #%d (conversation %d): document is %s (%s, %s) — dry run: recorded, nobody told, nothing answered',
                        $id, $convId, self::CLASS_WORDS[$class] ?? $class, $class, $kind));
                    return ['outcome' => 'recorded', 'route' => 'evidence', 'classification' => $class, 'kind' => $kind, 'chars' => mb_strlen($understanding), 'mode' => $mode];
                }
                ($this->log)('info', sprintf('media #%d (conversation %d): document is %s (%s, %s) — no AI turn; a person handles it',
                    $id, $convId, self::CLASS_WORDS[$class] ?? $class, $class, $kind));
                return ['outcome' => 'evidence', 'classification' => $class, 'kind' => $kind, 'chars' => mb_strlen($understanding), 'mode' => $mode];
            }

            if (!$reply) {
                // dry_run or handover: a harmless document is recorded and that is all — no AI turn; its caption was answered as text (step 9b).
                $this->pdo->commit();
                ($this->log)('info', sprintf('media #%d (conversation %d): document classified %s (%s) — %s: recorded, no AI turn',
                    $id, $convId, $class, $kind, $mode === 'dry_run' ? 'dry run' : 'hand-over mode'));
                return ['outcome' => 'recorded', 'route' => 'brain', 'classification' => $class, 'kind' => $kind, 'chars' => mb_strlen($understanding), 'mode' => $mode];
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
                    'origin'            => 'document',
                    'document'          => ['media_id' => $id, 'classification' => $class, 'kind' => $kind, 'pages_read' => (int)($facts['pages_read'] ?? 0),
                                            'pages_total' => (int)($facts['pages_total'] ?? 0), 'rows' => (int)($facts['rows'] ?? 0),
                                            'truncated' => !empty($r['truncated']), 'has_caption' => $caption !== '', 'chars' => mb_strlen($understanding)],
                ],
                3,                 // above normal: a waiting customer, as for text
                'media_worker'
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;   // the worker retries: the row is not understood, nothing was queued
        }
        return ['outcome' => 'understood', 'event_id' => (int)$eventId, 'chars' => mb_strlen($understanding), 'classification' => $class, 'kind' => $kind, 'mode' => $mode];
    }

    /** What a person is told. Generic by design: no amount, no name, no reference travels in an alert. */
    public static function handoverReason(string $outcome, string $classOrReason, string $detail = ''): string
    {
        if ($outcome === 'evidence') {
            if ($classOrReason === 'payment_proof') return PaymentEvidence::handoverReason();
            return self::HANDOVER[$classOrReason] ?? ('a ' . (self::CLASS_WORDS[$classOrReason] ?? 'document') . ' arrived — a person handles it; nothing was recorded');
        }
        switch ($classOrReason) {
            case 'pdf_no_text':
                return 'a PDF arrived (' . $detail . ') — no text could be read from it automatically; open it in WhatsApp';
            case 'provider_missing':
                return 'a scanned document arrived (' . $detail . ') — it cannot be read automatically; open it in WhatsApp';
            case 'classification_uncertain':
            case 'classification_incomplete':
            case 'classification_failed':
                return 'a document could not be classified safely (' . $classOrReason . ') — a person decides; open it in WhatsApp';
            default:
                return 'a document could not be read automatically (' . $classOrReason . ') — open it in WhatsApp';
        }
    }

    /** Control characters out, spaces collapsed, lines trimmed, trimmed — and NOT cut: cut() does that where a cap applies. */
    public static function normalise(string $text): string
    {
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text);
        if ($t === null) $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text) ?? $text;
        $t = preg_replace('/[ \t]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s*\n\s*/u', "\n", $t) ?? $t;
        return trim($t);
    }

    /** @return array{0:string,1:bool} the text cut at $cap characters with an ellipsis, and whether it was cut */
    public static function cut(string $text, int $cap): array
    {
        if (mb_strlen($text) <= $cap) return [$text, false];
        return [rtrim(mb_substr($text, 0, $cap)) . '…', true];
    }

    /**
     * The orientation a person gets for an evidence class: at most DOCUMENT_EXCERPT_CHARS characters, every run of six or more
     * digits (an account, a reference, a phone number) and every e-mail address masked. The original stays in WhatsApp.
     */
    public static function maskExcerpt(string $text): string
    {
        $t = preg_replace('/(?:\d[ \-.]?){5,}\d/u', '••••••', $text) ?? $text;
        $t = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', '•••@•••', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
        $t = trim($t);
        if (mb_strlen($t) > MediaPolicy::DOCUMENT_EXCERPT_CHARS) $t = rtrim(mb_substr($t, 0, MediaPolicy::DOCUMENT_EXCERPT_CHARS)) . '…';
        return $t;
    }

    /** The message the assistant reads: the label with the facts in words, the extract, the caption under its own label. */
    public static function label(string $kind, array $facts, bool $truncated, string $text, string $caption = ''): string
    {
        $name = self::KIND_NAMES[$kind] ?? 'document';
        $bits = [];
        if ((int)($facts['pages_read'] ?? 0) > 0) $bits[] = sprintf('%d page%s read of %d', (int)$facts['pages_read'], (int)$facts['pages_read'] === 1 ? '' : 's', (int)($facts['pages_total'] ?? 0));
        if ((int)($facts['paragraphs'] ?? 0) > 0) $bits[] = sprintf('%d paragraph%s', (int)$facts['paragraphs'], (int)$facts['paragraphs'] === 1 ? '' : 's');
        if ((int)($facts['sheets'] ?? 0) > 0) $bits[] = sprintf('%d sheet%s', (int)$facts['sheets'], (int)$facts['sheets'] === 1 ? '' : 's');
        if ((int)($facts['rows'] ?? 0) > 0) $bits[] = sprintf('%d row%s', (int)$facts['rows'], (int)$facts['rows'] === 1 ? '' : 's');
        if ((int)($facts['lines'] ?? 0) > 0) $bits[] = sprintf('%d line%s', (int)$facts['lines'], (int)$facts['lines'] === 1 ? '' : 's');
        if ($truncated) $bits[] = 'truncated';
        $out = self::LABEL . ' — ' . $name . ($bits !== [] ? ', ' . implode(', ', $bits) : '') . ']' . "\n" . $text;
        if (trim($caption) !== '') $out .= "\n" . self::LABEL_CAPTION . ' ' . trim($caption);
        return $out;
    }

    /** The stored message for a human-only class: the class in words, the masked excerpt where the record allows one, the caption. */
    public static function evidenceLabel(string $class, string $excerpt = '', string $caption = ''): string
    {
        $out = sprintf(self::LABEL_EVIDENCE, self::CLASS_WORDS[$class] ?? $class);
        if (trim($excerpt) !== '') $out .= ' ' . trim($excerpt);
        if (trim($caption) !== '') $out .= "\n" . self::LABEL_CAPTION . ' ' . trim($caption);
        return $out;
    }

    /** Words from the file name and the sheet names — signals for the classifier, never content, never for the assistant. */
    public static function signals(string $fileName, array $sheetNames): array
    {
        $out = [];
        foreach (array_merge([$fileName], $sheetNames) as $s) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string)$s)) ?: [] as $w) {
                if (mb_strlen($w) >= 2 && count($out) < 20) $out[] = $w;
            }
        }
        return array_values(array_unique($out));
    }

    /** @throws DocumentRefused extractor_unavailable */
    private static function requireCapabilities(array $names): void
    {
        foreach ($names as $n) {
            $have = is_array(self::$capabilities) && array_key_exists($n, self::$capabilities) ? (bool)self::$capabilities[$n]
                  : (function_exists($n) || class_exists($n));
            if (!$have) throw new DocumentRefused('extractor_unavailable', $n);
        }
    }

    /** 0 when PHP has no limit (-1). */
    public static function memoryLimitBytes(): int
    {
        $v = trim((string)ini_get('memory_limit'));
        if ($v === '' || $v === '-1') return 0;
        $n = (int)$v;
        switch (strtoupper(substr($v, -1))) {
            case 'G': $n *= 1024;
            case 'M': $n *= 1024;
            case 'K': $n *= 1024;
        }
        return max(0, $n);
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

    /** A detail is a short code, count, member name or class name — cut, and never a line of the document. */
    private static function safeDetail(string $detail): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $detail) ?? $detail), 0, 120);
    }
}

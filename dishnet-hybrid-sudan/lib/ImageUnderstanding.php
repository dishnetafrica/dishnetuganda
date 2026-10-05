<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/MediaBlob.php';
require_once __DIR__ . '/ImageDescriber.php';
require_once __DIR__ . '/PaymentEvidence.php';
require_once __DIR__ . '/EventBus.php';
require_once __DIR__ . '/UtcClock.php';

/**
 * ImageUnderstanding — a fetched picture becomes the customer's turn, through the EXISTING assistant — or, when it
 * looks like proof of a payment, becomes evidence for a person and nothing else (Batch 3, docs/55 §9, docs/57).
 *
 * MediaWorker calls process() once it holds the image in memory, for an image row, only while ai_media_image (and
 * ai_media_enabled) are on. This class:
 *
 *   - checks the picture itself before any provider sees it: the header must read as an image of an allowed type
 *     (getimagesizefromstring — no decoder library), with dimensions under the policy's caps; then a missing provider
 *     is refused too;
 *   - asks the ONE provider behind ImageDescriberPort, with a time budget;
 *   - normalises the description (control characters out, whitespace collapsed, 2,000 characters at most; empty is a
 *     failure) and fixes the classification to the known list;
 *   - decides, with PaymentEvidence and not the provider, whether this is payment evidence;
 *   - in one database transaction: marks the wa_media row `understood` (guarded by `status <> 'understood'`, so a retry
 *     or a duplicate event can never do this twice), rewrites the stored "[IMAGE]" message as the labelled description
 *     plus the customer's caption, and then EITHER queues ONE ordinary ai.reply event (the shape evo_webhook.php queues
 *     for a typed message, plus `origin: image`) OR, for payment evidence, queues NOTHING — the worker hands the
 *     conversation to a person.
 *
 * It never sends a message, never decides a reply, never reads or writes an invoice, a payment, a ledger or a CRM
 * record, and never keeps the picture. Every failure is a fixed reason code with a retryable flag (REASONS).
 * STOP is not read here: it is the webhook's, read off the caption when the message arrived — a description is the
 * provider's words, not the customer's. PHP 7.4 compatible.
 */
final class ImageUnderstanding
{
    /** What the assistant reads before the description, and what the stored message says. */
    public const LABEL         = '[image, described automatically]';
    public const LABEL_PAYMENT = '[image, payment evidence — a colleague will verify]';
    public const LABEL_CAPTION = '[caption from the customer]';

    /** The classifications a provider may answer with; anything else becomes 'general'. */
    public const CLASSES = ['payment_proof', 'site_photo', 'equipment_photo', 'screenshot', 'document_photo', 'general', 'unreadable'];

    /** Reason code => retried by the EventBus? */
    public const REASONS = [
        'provider_missing'     => false,   // ai_image_provider names nothing usable (docs/57)
        'unsupported_mime'     => false,   // the header says a type the policy does not allow
        'malformed_image'      => false,   // not an image at all, or a header that cannot be read
        'too_large_image'      => false,   // over the side or pixel cap
        'empty_description'    => false,   // the provider described nothing
        'conversation_missing' => false,   // the row names a conversation that no longer exists
        'provider_error'       => true,    // the provider answered with an error
        'timeout'              => true,    // the provider did not answer in time
    ];

    /** @var \PDO */
    private $pdo;
    private $store;
    /** @var array */
    private $config;
    /** @var ImageDescriberPort|null */
    private $describer;
    /** @var callable */
    private $log;
    /** @var EventBus */
    private $bus;

    public function __construct(\PDO $pdo, $store, array $config, ?ImageDescriberPort $describer, callable $log)
    {
        $this->pdo       = $pdo;
        $this->store     = $store;
        $this->config    = $config;
        $this->describer = $describer;
        $this->log       = $log;
        $this->bus       = new EventBus($pdo);
    }

    /**
     * @return array ['outcome' => 'understood', 'event_id' => int, 'chars' => int, 'classification' => string]
     *               | ['outcome' => 'payment_evidence', 'chars' => int, 'classification' => string]
     *               | ['outcome' => 'already_understood']
     *               | ['outcome' => 'failed', 'reason' => string, 'retryable' => bool, 'detail' => string]
     */
    public function process(array $row, MediaBlob $image): array
    {
        $id = (int)($row['id'] ?? 0);
        if ((string)($row['kind'] ?? '') !== 'image') return self::fail('malformed_image', 'not an image message');

        // The picture itself, before any provider: the header must read as an allowed image within the caps. A payload
        // behind an allowed label that is not an image, or is another type, stops here.
        $info = @getimagesizefromstring($image->bytes);
        if (!is_array($info) || (int)($info[0] ?? 0) <= 0 || (int)($info[1] ?? 0) <= 0) {
            return self::fail('malformed_image', 'no readable image header');
        }
        $mime = strtolower(trim((string)($info['mime'] ?? '')));
        if (!MediaPolicy::allowed('image', $mime)) return self::fail('unsupported_mime', $mime !== '' ? $mime : 'no type in header');
        $w = (int)$info[0]; $h = (int)$info[1];
        if ($w > MediaPolicy::IMAGE_MAX_SIDE_PX || $h > MediaPolicy::IMAGE_MAX_SIDE_PX || $w * $h > MediaPolicy::IMAGE_MAX_PIXELS) {
            return self::fail('too_large_image', sprintf('%dx%d px', $w, $h));
        }
        if ($this->describer === null) {
            return self::fail('provider_missing', 'ai_image_provider is ' . ImageDescriberFactory::providerName($this->config));
        }

        $caption = trim((string)($row['caption'] ?? ''));
        $this->setStatus($id, 'describing');
        try {
            $res = $this->describer->describe($image, [
                'mimetype' => $mime, 'width' => $w, 'height' => $h, 'caption' => $caption,
                'channel'  => (string)($row['channel'] ?? ''),
            ], MediaPolicy::imageTimeoutSeconds($this->config));
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
        $description = self::normalise((string)($res['description'] ?? ''));
        if ($description === '') return self::fail('empty_description', 'nothing described');
        $class = strtolower(trim((string)($res['classification'] ?? 'general')));
        if (!in_array($class, self::CLASSES, true)) $class = 'general';
        $signals = [];
        foreach (array_slice((array)($res['signals'] ?? []), 0, 20) as $sig) {
            $s = trim((string)$sig);
            if ($s !== '') $signals[] = mb_substr($s, 0, 40);
        }

        return $this->complete($row, $description, [
            'classification' => $class,
            'signals'        => $signals,
            'width'          => $w,
            'height'         => $h,
            'mime'           => $mime,
            'provider'       => $this->describer->name(),
            'payment'        => PaymentEvidence::looksLikePayment($class, $description, $signals),
        ]);
    }

    /**
     * The description becomes the record, and either the customer's turn or a person's evidence — once.
     *
     * One transaction: the row is marked understood only if it was not already; the stored message is rewritten; for
     * payment evidence nothing is queued (the worker hands over after this returns); otherwise the one ai.reply event
     * is queued. A database failure rolls all of it back and is thrown, so the worker retries the whole thing.
     */
    public function complete(array $row, string $description, array $meta): array
    {
        $id      = (int)($row['id'] ?? 0);
        $convId  = (int)($row['conversation_id'] ?? 0);
        $payment = !empty($meta['payment']);
        $class   = (string)($meta['classification'] ?? 'general');
        $caption = trim((string)($row['caption'] ?? ''));
        $q = $this->pdo->prepare('SELECT phone, display_name FROM wa_conversations WHERE id = ?');
        $q->execute([$convId]);
        $conv = $q->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($conv) || (string)($conv['phone'] ?? '') === '') return self::fail('conversation_missing', 'conversation ' . $convId);

        $labelled = self::label($description, $caption, $payment);
        $this->pdo->beginTransaction();
        try {
            $u = $this->pdo->prepare("UPDATE wa_media SET status = 'understood', understanding = ?, understanding_kind = ?,
                                      failure_reason = NULL, updated_at = datetime('now') WHERE id = ? AND status <> 'understood'");
            $u->execute([$description, $payment ? 'payment_evidence' : 'description', $id]);
            if ($u->rowCount() !== 1) {
                $this->pdo->rollBack();
                return ['outcome' => 'already_understood'];
            }

            // The stored "[IMAGE]" message says what the picture shows: the inbox reads it (a person verifying a payment
            // reads it here, against the books), and so does the model's history for a later turn — labelled.
            $msgId = (int)($row['message_id'] ?? 0);
            if ($msgId > 0) {
                $m = $this->pdo->prepare("SELECT metadata FROM wa_messages WHERE id = ? AND media_type = 'image'");
                $m->execute([$msgId]);
                $metaRaw = $m->fetchColumn();
                if ($metaRaw !== false) {
                    $md = json_decode((string)$metaRaw, true);
                    $md = is_array($md) ? $md : [];
                    $md['image'] = ['media_id' => $id, 'classification' => $class, 'width' => (int)($meta['width'] ?? 0),
                                    'height' => (int)($meta['height'] ?? 0), 'payment_evidence' => $payment,
                                    'provider' => (string)($meta['provider'] ?? ''), 'described_at' => gmdate('Y-m-d H:i:s')];
                    $this->pdo->prepare("UPDATE wa_messages SET body = ?, metadata = ? WHERE id = ? AND media_type = 'image'")
                              ->execute([$labelled, json_encode($md, JSON_UNESCAPED_UNICODE), $msgId]);
                }
            }

            if ($payment) {
                // Evidence for a person. Nothing is queued for the assistant; the worker hands the conversation over.
                $this->pdo->commit();
                ($this->log)('info', sprintf('media #%d (conversation %d): picture is payment evidence (%s) — no AI turn; a person verifies',
                    $id, $convId, $class));
                return ['outcome' => 'payment_evidence', 'chars' => mb_strlen($description), 'classification' => $class];
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
                    'origin'            => 'image',
                    'image'             => ['media_id' => $id, 'classification' => $class, 'width' => (int)($meta['width'] ?? 0),
                                            'height' => (int)($meta['height'] ?? 0), 'has_caption' => $caption !== ''],
                ],
                3,                 // above normal: a waiting customer, as for text
                'media_worker'
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;   // the worker retries: the row is not understood, nothing was queued
        }
        return ['outcome' => 'understood', 'event_id' => (int)$eventId, 'chars' => mb_strlen($description), 'classification' => $class];
    }

    /** Control characters out, whitespace collapsed, trimmed, cut at the policy's limit. */
    public static function normalise(string $text): string
    {
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text);
        if ($t === null) $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text) ?? $text;
        $t = preg_replace('/[ \t]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s*\n\s*/u', "\n", $t) ?? $t;
        $t = trim($t);
        if (mb_strlen($t) > MediaPolicy::IMAGE_MAX_DESCRIPTION_CHARS) {
            $t = rtrim(mb_substr($t, 0, MediaPolicy::IMAGE_MAX_DESCRIPTION_CHARS)) . '…';
        }
        return $t;
    }

    /**
     * The message the assistant reads, and the stored message's body: the description labelled as automatic (or as
     * payment evidence), and the customer's own caption labelled as theirs — never bare, never mixed.
     */
    public static function label(string $description, string $caption = '', bool $payment = false): string
    {
        $out = ($payment ? self::LABEL_PAYMENT : self::LABEL) . ' ' . $description;
        if (trim($caption) !== '') $out .= "\n" . self::LABEL_CAPTION . ' ' . trim($caption);
        return $out;
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

    /** A detail is a short code, status, size or class name — cut, and never a description. */
    private static function safeDetail(string $detail): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $detail) ?? $detail), 0, 120);
    }
}

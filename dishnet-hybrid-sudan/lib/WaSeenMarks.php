<?php
declare(strict_types=1);

/**
 * WaSeenMarks — a content-free mark for a WhatsApp message the webhook cannot store (5.18.104, root docs/73 §H.2.7.2;
 * R-01, R-07).
 *
 *   in  — a customer wrote inside a wrapper the store cannot read (a disappearing or view-once message): the chase
 *         treats it as a reply, and offers leave the phone out, since nobody could read what was said;
 *   out — a colleague answered from the handset with a voice note, a photo or a document without a caption: a person
 *         wrote to this customer, so a quote chase and the voice-note acknowledgement stop (HumanTakeover);
 *   lid_no_number — an inbound @lid message with no number: counted only.
 *
 * A mark holds no text and no file: direction, the customer's digits, the channel and instance, the envelope's key and
 * the message id. Our own sends are told apart when a mark is read (HumanTakeover), not here. note() tests the ledger
 * switch first, then StaffJobsGate, then kindOf(); it never throws into the webhook. On South Sudan the switch is
 * never set, so nothing is written and nothing else is required. PHP 7.4.
 */
final class WaSeenMarks
{
    const WRAPPERS = ['ephemeralMessage','viewOnceMessage','viewOnceMessageV2','viewOnceMessageV2Extension','documentWithCaptionMessage'];
    const MEDIA    = ['audioMessage','imageMessage','documentMessage','videoMessage','stickerMessage'];

    /**
     * Pure. in → a WRAPPERS key of the message; out → a WRAPPERS or MEDIA key; anything else (a reaction, a protocol
     * message, an edit, a poll) → null. The keys are looked for, never taken from array_keys()[0], which can be
     * messageContextInfo.
     */
    public static function kindOf(array $msg, string $direction): ?string
    {
        $m = $msg['message'] ?? null;
        if (!is_array($m)) return null;
        foreach (self::WRAPPERS as $k) if (array_key_exists($k, $m)) return $k;
        if ($direction === 'out') {
            foreach (self::MEDIA as $k) if (array_key_exists($k, $m)) return $k;
        }
        return null;
    }

    /** A mark for one message, with the ledger on, on Uganda, for a key kindOf() names. Never throws. */
    public static function note(\PDO $pdo, array $config, ?string $dataDir, string $direction, string $phone, string $channel,
                                string $instance, string $waMessageId, array $msg): void
    {
        try {
            if ((string)($config['wa_send_ledger'] ?? '') !== 'on') return;
            if (!self::uganda($config, $dataDir)) return;
            if (($direction !== 'in' && $direction !== 'out') || $waMessageId === '') return;
            $kind = self::kindOf($msg, $direction);
            if ($kind === null) return;
            self::insert($pdo, $direction, $phone, $channel, $instance, $kind, $waMessageId);
        } catch (\Throwable $e) {
            self::lost();
        }
    }

    /** An inbound @lid message with no number: counted only (phone '', kind lid_no_number). Never throws. */
    public static function noNumber(\PDO $pdo, array $config, ?string $dataDir, string $channel, string $instance, string $waMessageId): void
    {
        try {
            if ((string)($config['wa_send_ledger'] ?? '') !== 'on') return;
            if (!self::uganda($config, $dataDir)) return;
            if ($waMessageId === '') return;
            self::insert($pdo, 'in', '', $channel, $instance, 'lid_no_number', $waMessageId);
        } catch (\Throwable $e) {
            self::lost();
        }
    }

    private static function insert(\PDO $pdo, string $direction, string $phone, string $channel, string $instance,
                                   string $kind, string $waMessageId): void
    {
        $pdo->prepare('INSERT OR IGNORE INTO wa_seen_marks (direction, phone, channel, instance, kind, wa_message_id)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$direction, (string)preg_replace('/\D+/', '', $phone), $channel, $instance, $kind, $waMessageId]);
    }

    private static function uganda(array $config, ?string $dataDir): bool
    {
        if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
        return \StaffJobsGate::applies($config, $dataDir);
    }

    private static function lost(): void
    {
        try {
            if (!class_exists('PluginLog')) require_once __DIR__ . '/PluginLog.php';
            \PluginLog::write('wa_seen_marks', 'mark_write_lost');
        } catch (\Throwable $e) { /* the webhook matters more than its mark */ }
    }
}

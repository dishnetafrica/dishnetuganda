<?php
declare(strict_types=1);

/**
 * QuoteWaLedger — which uCRM quotations have gone out on WhatsApp, one row
 * per quote (table wa_sent_quotes).
 *
 * cron_quote_wa.php has kept this table since v4.11.3: before either of its
 * flows sends a quote it inserts the quote id, and whoever inserts second
 * finds the row and sends nothing. INSERT OR IGNORE makes that atomic — the
 * first insert wins, however the senders interleave.
 *
 * 5.18.30 gives the table a second writer. With kyc_messages_like_crm on, the
 * quote.add webhook sends a KYC customer's quotation itself, the way it sends
 * one made in uCRM, while the KYC form still queues the quote for the cron in
 * case uCRM's webhook never arrives. Both take the same claim here before
 * sending, so the customer gets the quotation once, from whichever is first.
 */
final class QuoteWaLedger
{
    public static function ensure(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_sent_quotes (
            quote_id   INTEGER NOT NULL,
            quote_ref  TEXT    NOT NULL DEFAULT '',
            source     TEXT    NOT NULL DEFAULT '',
            sent_at    TEXT    NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (quote_id)
        )");
    }

    /**
     * Claim quote $quoteId for sending. True when this caller is the first,
     * false when another sender already claimed it.
     */
    public static function claim(\PDO $pdo, int $quoteId, string $ref, string $source): bool
    {
        self::ensure($pdo);
        $st = $pdo->prepare('INSERT OR IGNORE INTO wa_sent_quotes (quote_id, quote_ref, source) VALUES (?, ?, ?)');
        $st->execute([$quoteId, $ref, $source]);
        return $st->rowCount() === 1;
    }
}

<?php
declare(strict_types=1);

/**
 * PaymentEvidence — does this picture look like proof of a payment? (Batch 3 of the AI communication layer, docs/57 §4, §6)
 *
 * The decision is this plugin's, not the vision provider's. A picture that looks like a payment, a transfer, a receipt,
 * a mobile-money or bank confirmation, an invoice marked paid, or account or till details is EVIDENCE for a person to
 * check against the books: it never reaches the assistant, never marks anything paid, never creates or changes a
 * payment, and is never read as a fact about money. The provider's classification counts; so do the words, because a
 * provider that labels a receipt "screenshot" must not get it past this rule.
 *
 * The rule errs towards yes. A wrong yes costs a person a look at a photo; a wrong no would have an assistant talking
 * about money it has not seen in the books. Both a MONEY token and a TRANSACTION token are needed for the words alone
 * to decide, so a shop front with a telecom sign or a price list is not a payment, while "MTN MoMo … UGX 150,000 …
 * transaction ID" is. PHP 7.4 compatible.
 */
final class PaymentEvidence
{
    /** The provider class that is a payment on its own. */
    public const CLASS_PAYMENT = 'payment_proof';

    /** A sum, a currency, or a word about a sum. */
    private const MONEY = '/\b(ugx|ush|shs|usd|ssp|kes|tzs|eur|gbp|amount|total|balance|sum)\b|(?<![\d.])\d{1,3}(?:[,\s]\d{3})+(?:\.\d{1,2})?(?![\d])|\d+(?:\.\d{2})?\s*(?:ugx|usd|shs|ush)\b/i';

    /** Something having been paid, moved or confirmed. */
    private const TRANSACTION = '/\b(paid|payment|payments|pay|transaction|txn|receipt|transfer|transferred|deposit|deposited|'
        . 'confirmation|confirmed|reference|ref\.?|mobile money|momo|m-pesa|mpesa|airtel money|mtn money|bank|till|merchant|'
        . 'sent to|received from|successful|successfully)\b/i';

    /**
     * @param string   $classification the provider's class (ImageUnderstanding::CLASSES)
     * @param string   $description    the normalised description
     * @param string[] $signals        the provider's short tags
     */
    public static function looksLikePayment(string $classification, string $description, array $signals): bool
    {
        if (strtolower(trim($classification)) === self::CLASS_PAYMENT) return true;
        $t = strtolower($description . ' ' . implode(' ', array_map('strval', $signals)));
        if (trim($t) === '') return false;
        return preg_match(self::MONEY, $t) === 1 && preg_match(self::TRANSACTION, $t) === 1;
    }

    /**
     * What a person is told. Deliberately generic: the alert travels by WhatsApp to the staff number, and the amounts,
     * names and references the picture may show stay on the record, for the inbox, not in a broadcast.
     */
    public static function handoverReason(): string
    {
        return 'a payment screenshot or receipt arrived — nothing was recorded or marked paid; verify it against uCRM '
             . 'and the bank or mobile-money statement before touching any invoice';
    }
}

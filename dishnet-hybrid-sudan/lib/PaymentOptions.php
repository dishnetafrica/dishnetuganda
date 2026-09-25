<?php
declare(strict_types=1);

/**
 * PaymentOptions — the ways to pay that the plugin itself prints to
 * customers: the quotation summary on WhatsApp and the "How to pay" block of
 * the e-mails. The assistant says what ai_fact_payment says, and the website
 * has its own pay page; those are configured and edited separately.
 *
 * 5.18.31: Airtel Money. DishNet Uganda holds an Airtel Money Pay merchant ID.
 * A customer pays it from any Airtel line by dialling *185*9# and entering the
 * merchant ID, the amount and their Airtel Money PIN, or by scanning the
 * merchant's QR code in the My Airtel app. Airtel charges the customer
 * nothing and texts both sides a transaction ID. Nothing connects that
 * payment to uCRM: the customer sends the transaction ID, and a person
 * records the payment.
 *
 * The merchant ID is configuration (`pay_airtel_merchant`), never code.
 * Unset, nothing here prints anything new — the South Sudan install has no
 * Airtel Money.
 */
final class PaymentOptions
{
    /** Airtel Uganda's code for Airtel Money Pay, the merchant-payment menu. */
    const AIRTEL_USSD = '*185*9#';

    /**
     * The Airtel Money merchant ID customers pay, as digits; '' when it is not
     * set or is not a merchant ID. Spaces and dashes typed into the setting
     * are dropped, because a customer must key the digits exactly.
     */
    public static function airtelMerchant(array $config): string
    {
        $v = preg_replace('/[\s-]+/', '', (string)($config['pay_airtel_merchant'] ?? '')) ?? '';
        return preg_match('/^\d{4,10}$/', $v) === 1 ? $v : '';
    }

    /**
     * The quotation summary's payment lines, for WhatsApp.
     *
     * Unset: the line the summary has always carried. Set: Airtel Money first,
     * then that same line — nothing that was offered before is taken away.
     *
     * The USSD code ends its line and shares it with no other asterisk.
     * WhatsApp pairs asterisks into bold, so a second one on the line could
     * swallow the code's first and leave the customer dialling 185*9#.
     */
    public static function quoteLines(array $config): string
    {
        $m = self::airtelMerchant($config);
        if ($m === '') return "💳 Cash / Transfer / Card\n";
        return "💳 Airtel Money: Merchant ID {$m}, dial " . self::AIRTEL_USSD . "\n"
             . "💵 Or Cash / Transfer / Card\n";
    }

    /**
     * The e-mails' "How to pay" row, as label => value; [] when unset.
     *
     * @return array<string,string>
     */
    public static function emailRows(array $config): array
    {
        $m = self::airtelMerchant($config);
        return $m === '' ? [] : ['Airtel Money' => "Merchant ID {$m} — dial " . self::AIRTEL_USSD];
    }

    /**
     * The sentence tools/ai_facts.php --uganda adds to the assistant's payment
     * answer; '' when unset.
     */
    public static function factSentence(array $config): string
    {
        $m = self::airtelMerchant($config);
        if ($m === '') return '';
        return 'Or by Airtel Money, free of charge to the customer: dial ' . self::AIRTEL_USSD
             . ', enter Merchant ID ' . $m . ', the amount and their Airtel Money PIN — then send'
             . ' the transaction ID from Airtel\'s SMS here so the payment can be matched to their account.';
    }
}

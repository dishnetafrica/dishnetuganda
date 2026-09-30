<?php
/**
 * ReceiptOnce — one WhatsApp receipt per payment, whoever sends it (5.18.54, docs/46 rows 2-4; Uganda only, behind
 * NotifyGate::RECEIPT_ONCE).
 *
 * The guards live in notification_dedup, the table payment.add already used for its own text receipt:
 *   PAY<id>        the WhatsApp text receipt for uCRM payment <id> (unchanged since before 5.18.54)
 *   PAYREF:<ref>   a collection receipted before uCRM had its payment; <ref> is the "Ref:" the collection wrote into
 *                  the payment's note, which is how payment.add recognises it when a retry job posts it later
 *   PAYWORK<id>    payment.add's own once-per-payment work: the e-mail, the receipt PDF, the delivery note
 *   PAYPDF<id>     the receipt PDF, queued once, by the staff collection or by payment.add
 * Every name is built here and nowhere else.
 */
final class ReceiptOnce
{
    public static function payKey(int $paymentId): string  { return 'PAY' . $paymentId; }
    public static function refKey(string $ref): string      { return 'PAYREF:' . $ref; }
    public static function workKey(int $paymentId): string { return 'PAYWORK' . $paymentId; }
    public static function pdfKey(int $paymentId): string  { return 'PAYPDF' . $paymentId; }

    /** The "Ref:" a staff collection wrote into its uCRM payment note, or ''. */
    public static function refFromNote(string $note): string
    {
        return preg_match('/\bRef:\s*([A-Za-z0-9._:-]{4,80})/', $note, $m) ? $m[1] : '';
    }

    /**
     * A collection about to receipt a payment itself: claim the text's guard, and the reference as well. False means
     * somebody already sent this receipt, and nothing is sent. With neither an id nor a reference there is nothing
     * to claim, and it sends as before.
     *
     * @param object $notify NotificationService (dedupMark)
     */
    public static function claimCollection($notify, ?int $crmPaymentId, string $paymentRef): bool
    {
        $send = ($crmPaymentId !== null && $crmPaymentId > 0) ? (bool)$notify->dedupMark(self::payKey($crmPaymentId)) : true;
        if ($send && $paymentRef !== '') $send = (bool)$notify->dedupMark(self::refKey($paymentRef));
        return $send;
    }
}

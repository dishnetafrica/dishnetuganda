<?php
/**
 * NotifyGate — the one switch for the notification fixes of 5.18.54 (docs/46).
 *
 * Each fix has a name. A fix applies where the tenant profile resolves to Uganda, by exactly the resolution
 * StaffJobsGate has used since 5.18.50, and nowhere else. Anything unclear — a missing profile, an unreadable vault,
 * any throwable — is false, which is South Sudan's behaviour: every place this gate guards keeps the 5.18.53 code,
 * verbatim, in its false branch.
 *
 * South Sudan gets a fix only when it is approved for it: its name then goes into EVERYWHERE, and that list is the
 * whole of the change. It is empty today (docs/46 §E lists the fixes recommended for it).
 *
 * Always pass the data directory, for the reason StaffJobsGate gives: currency_code, which selects Uganda on a box
 * with no tenant_profile setting, is a vault key.
 */
final class NotifyGate
{
    // docs/46 §A, by row
    public const PAYMENT_FLOW   = 'payment_flow';     // 1: payment.add carries on after the receipt (D-1, C7)
    public const RECEIPT_ONCE   = 'receipt_once';     // 2-4: one WhatsApp receipt per payment (D-2, D3)
    public const REMINDERS      = 'reminders';        // 5-8: one reminder path, daytime, prepaid rules (D-4, C1)
    public const EVENT_MAP      = 'event_map';        // 9: the Event Map says it is not used (D-3)
    public const QUEUE_ADMIN    = 'queue_admin';      // 10: failure-queue API for administrators only (S-1)
    public const ACTION_LINKS   = 'action_links';     // 11: no GET link that sends or changes uCRM (D-7, C3)
    public const CREDIT_NOTE    = 'credit_note_once'; // 12: one credit-note message, in the tenant's currency (D7)
    public const ACTIVATION     = 'activation_text';  // 13: no promise of an e-mail nobody sends (D-9)
    public const PHONE_FORM     = 'phone_form';       // 14: international form before WhatsApp (D-10)
    public const EVENT_ONCE     = 'event_once';       // 15: one message per uCRM event (D10)
    public const DRAFT_IDENTITY = 'draft_identity';   // 16: add_draft passes the client, not the invoice (D-6)
    public const QUOTE_ONCE     = 'quote_once';       // 17: one WhatsApp per plugin quote (D2c)
    public const KYC_QUOTE_SEND = 'kyc_quote_send';   // 18: uCRM's refusal to send a KYC quote is logged (D-8)
    public const KYC_WELCOME    = 'kyc_welcome';      // 19: welcome and "Request Confirmed!" never both (D8)
    public const WINBACK_OPTOUT = 'winback_optout';   // 20: STOP stops win-back (C9)
    public const TENANT_TEXT    = 'tenant_text';      // 21-22: no South Sudan content in Uganda paths (C8, C4)
    public const LADDER_RECORD  = 'ladder_record';    // 23: the e-mail ladder records a later success (D-5)
    public const STAFF_SIDE     = 'staff_side';       // 24-29: S-2 … S-7
    public const RETRIES        = 'retries';          // 30: bounded automatic retries (M4)
    public const EVO_RETRY      = 'evo_retry';        // 31: no automatic resend after a timeout (N-1)
    public const WATCHDOG       = 'watchdog';         // 32: jobs stopped, failures piling up
    public const MAIL_CLASS     = 'mail_class';       // 40: the ladder's SMTP sender loads the class it calls (N-11)

    public const ALL = [
        self::PAYMENT_FLOW, self::RECEIPT_ONCE, self::REMINDERS, self::EVENT_MAP, self::QUEUE_ADMIN,
        self::ACTION_LINKS, self::CREDIT_NOTE, self::ACTIVATION, self::PHONE_FORM, self::EVENT_ONCE,
        self::DRAFT_IDENTITY, self::QUOTE_ONCE, self::KYC_QUOTE_SEND, self::KYC_WELCOME, self::WINBACK_OPTOUT, self::TENANT_TEXT,
        self::LADDER_RECORD, self::STAFF_SIDE, self::RETRIES, self::EVO_RETRY, self::WATCHDOG, self::MAIL_CLASS,
    ];

    /** Fixes approved for every tenant, South Sudan included. Empty until approved (docs/46 §E). */
    public const EVERYWHERE = [];

    public static function applies(string $fix, array $config, ?string $dataDir): bool
    {
        if (!in_array($fix, self::ALL, true)) return false;     // an unknown name is nobody's fix
        if (in_array($fix, self::EVERYWHERE, true)) return true;
        return self::uganda($config, $dataDir);
    }

    /** True where the tenant profile resolves to Uganda: StaffJobsGate's own answer, so the two can never differ. */
    public static function uganda(array $config, ?string $dataDir): bool
    {
        try {
            if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
            return StaffJobsGate::applies($config, $dataDir);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** For tests that switch profiles inside one process. */
    public static function reset(): void
    {
        if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
        StaffJobsGate::reset();
    }
}

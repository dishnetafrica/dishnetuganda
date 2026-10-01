<?php
/**
 * EmailRecipients — turn a uCRM client into one To address and a CC list.
 *
 * The operator asked that customer email reach every address the client has in
 * uCRM: "the first one as the main one, the rest in CC." A client in uCRM holds
 * a contacts[] array, each contact with an optional email and an isBilling flag.
 *
 * The rule here:
 *   To  — the billing contact's email if a contact is flagged isBilling and has
 *         one; otherwise the first contact that has an email. This matches the
 *         existing lifecycle rule (CustomerEmailDispatcher sent to the billing
 *         contact first), so turning CC on never MOVES the main recipient — it
 *         only adds the others.
 *   CC  — every other distinct, valid email on the client, in the order uCRM
 *         lists them, with the To address removed.
 *
 * Invalid addresses are dropped, duplicates collapsed (case-insensitively). The
 * comparison is lower-cased so a@x.com and A@X.com are one person; the address
 * kept is the lower-cased form, which SMTP treats identically.
 *
 * This resolver NEVER touches OTP / login-code mail: those are sent by OtpEmail
 * straight through MailService and must go to exactly one person — a login code
 * copied to a colleague is a security defect, not a convenience.
 *
 * PHP 7.4 compatible.
 */
declare(strict_types=1);

class EmailRecipients
{
    /** @return array{to:string,to_name:string,cc:array<int,string>} */
    public static function fromClient(array $client): array
    {
        $name = trim((string)(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? '')));
        if ($name === '') $name = trim((string)($client['companyName'] ?? ''));

        [$emails, $billing] = self::collect($client);
        if ($emails === []) return ['to' => '', 'to_name' => $name, 'cc' => []];

        $to = $billing !== '' ? $billing : $emails[0];
        return ['to' => $to, 'to_name' => $name, 'cc' => self::others($emails, $to)];
    }

    /**
     * The CC list for a client given a To already chosen elsewhere.
     *
     * CustomerEmailDispatcher picks its To with its own long-standing rule; this
     * returns everyone else so that rule is preserved and CC is purely additive.
     *
     * @return array<int,string>
     */
    public static function ccFor(array $client, string $toEmail): array
    {
        [$emails] = self::collect($client);
        return self::others($emails, $toEmail);
    }

    /**
     * Distinct valid emails on the client, in uCRM order, plus the first billing
     * email seen.
     *
     * @return array{0:array<int,string>,1:string}
     */
    private static function collect(array $client): array
    {
        $contacts = is_array($client['contacts'] ?? null) ? $client['contacts'] : [];
        $emails = [];
        $billing = '';
        foreach ($contacts as $c) {
            if (!is_array($c)) continue;
            $e = strtolower(trim((string)($c['email'] ?? '')));
            if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) continue;
            if (!in_array($e, $emails, true)) $emails[] = $e;
            if ($billing === '' && !empty($c['isBilling'])) $billing = $e;
        }
        return [$emails, $billing];
    }

    /** Every email except $exclude (case-insensitive), order preserved. */
    private static function others(array $emails, string $exclude): array
    {
        $x = strtolower(trim($exclude));
        return array_values(array_filter($emails, static function ($e) use ($x) {
            return strtolower($e) !== $x;
        }));
    }
}

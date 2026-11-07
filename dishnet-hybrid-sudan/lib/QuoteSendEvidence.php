<?php
declare(strict_types=1);

/**
 * QuoteSendEvidence — what proves a quotation was sent on WhatsApp, and the pure rules read from it (5.18.104, Uganda,
 * root docs/73 §H.2.9.1–§H.2.9.5; contract C9; Part P's IP-1).
 *
 * Pure: nothing here opens a book, a file or a connection. The tracker (lib/QuoteTracker.php), the report
 * (tools/sales_followup_report.php) and Part P's agent copy (lib/QuoteAgentCopy.php) pass the rows in.
 *
 *   EVENTS, MARKERS     a row confirms a quote when it is outbound, its event is in EVENTS and its body carries one of the
 *                       three markers with the number (§H.2.9.1); sendDocument never writes wa_messages, so a PDF is never
 *                       evidence
 *   read($row)          [marker, number] of a confirming row, or null
 *   key($number)        'ref:' + the number, trimmed, a leading '#' removed (§H.2.9.2)
 *   validUntil(...)     the Kampala date the quotation is valid until (§H.2.9.3)
 *   kycAgent(...)       owner rule 1 — the ONE function rules 1 and 6 and Part P's P7 call (§H.2.9.4, IP-1)
 *   owner(...)          owner rules 1–7 over the facts passed in; the first that yields one active retailers row
 *   spokeTo(...)        the conversation the customer spoke to us on (§H.2.9.5)
 *   sentChannel(...)    the Evolution channel the quotation left from: account | support (§H.2.9.2)
 *   madeBy(...)         kyc | lead | manual | ucrm | resend | lte
 *
 * Every date is computed in a literal Africa/Kampala zone (R-16), never dn_tz(). PHP 7.4.
 */
final class QuoteSendEvidence
{
    /** The literal zone every date here is computed in. */
    public const TZ = 'Africa/Kampala';

    /** The quotation events (NotificationRetry::EVENTS' quote subset minus quote_cash; lib/NotificationRetry.php:32). */
    const EVENTS = ['ops_quote_created', 'ops_quote_wa', 'ops_quote_text', 'quote_kyc', 'quote_lead', 'quote_manual'];

    /** The three markers with the number (lib/QuotationService.php:513, webhook.php:3002, includes/api/api_whatsapp.php:665,674). */
    const MARKERS = [
        'ref'   => '📋 *Ref:* ',
        'order' => '🆔 *Order:* ',
        'quote' => '📄 *Quote #',
    ];

    /** The validity line the 📋 texts carry (lib/QuotationService.php:503-504,516): date('d M Y'). */
    const VALID_LINE = '⏳ *Valid Until:* ';

    /** A uCRM quotation's PDF is valid 7 days from its date (ucrm_pdf_templates/quotation_uganda/template.html.twig:205). */
    const UCRM_VALID_DAYS = 7;
    /** "Valid for 30 days." (includes/api/api_whatsapp.php:670,679). */
    const QUOTE_TEXT_VALID_DAYS = 30;

    /** A customer's latest inbound row must fall in (sent_at − 30 d, sent_at] for spoke-to (§H.2.9.5). */
    const SPOKE_WINDOW_S = 30 * 86400;

    /** The owner sources a tracker row may carry; 'pending' only while no rule yields and the row is under 1 h old. */
    const OWNER_SOURCES = ['kyc_sales_person', 'kyc_submitter', 'lead_owner', 'quotes_log', 'channel', 'conv_lead',
                           'kyc_client', 'pending', 'none'];

    // ── evidence ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * [marker, number] when $row confirms a quotation (outbound, an EVENTS event, a marker with a number), else null.
     * $row: direction, event_key, body (a wa_messages row as stored).
     *
     * @return array{0:string,1:string}|null
     */
    public static function read(array $row): ?array
    {
        if ((string)($row['direction'] ?? '') !== 'out') return null;
        if (!in_array((string)($row['event_key'] ?? ''), self::EVENTS, true)) return null;
        return self::numberOf((string)($row['body'] ?? ''));
    }

    /**
     * The first marker in the text, and the number it carries, or null. The 📋 and 🆔 markers carry the rest of their
     * line; 📄 carries what stands before its closing '*'.
     *
     * @return array{0:string,1:string}|null
     */
    public static function numberOf(string $body): ?array
    {
        $best = null;
        foreach (self::MARKERS as $kind => $marker) {
            $p = strpos($body, $marker);
            if ($p === false) continue;
            if ($best === null || $p < $best[1]) $best = [$kind, $p];
        }
        if ($best === null) return null;
        [$kind, $p] = $best;
        $rest = substr($body, $p + strlen(self::MARKERS[$kind]));
        $nl = strpos($rest, "\n");
        $line = $nl === false ? $rest : substr($rest, 0, $nl);
        if ($kind === 'quote') {
            $star = strpos($line, '*');
            if ($star === false) return null;
            $line = substr($line, 0, $star);
        }
        $number = self::clean($line);
        return $number === '' ? null : [$kind, $number];
    }

    /** The number, trimmed, with a leading '#' removed (§H.2.9.2). */
    public static function clean(string $number): string
    {
        $n = trim($number);
        if ($n !== '' && $n[0] === '#') $n = trim(substr($n, 1));
        return $n;
    }

    /** quote_key = 'ref:' + the number the customer saw (A3). */
    public static function key(string $number): string
    {
        return 'ref:' . self::clean($number);
    }

    // ── valid_until (§H.2.9.3) ───────────────────────────────────────────────────────────────────────────────────

    /**
     * The Kampala date (Y-m-d) the quotation is valid until: the earlier of the date the text states (the 📋 validity
     * line, or the sent date + 30 for 📄) and, when a uCRM id is known, the Kampala send date + 7. A 🆔 text states none
     * and is always a uCRM quotation, so the second applies to it whether or not its id was mapped. With no date at all
     * (a 📋 text whose line cannot be read and no uCRM id), the sent date + 7: the shorter rule, so a chase stops sooner.
     */
    public static function validUntil(string $marker, string $body, string $sentAtUtc, bool $quoteIdKnown): string
    {
        $sentDay = self::kampalaDay($sentAtUtc);
        $dates = [];
        if ($marker === 'ref') {
            $stated = self::statedValidity($body);
            if ($stated !== null) $dates[] = $stated;
        } elseif ($marker === 'quote') {
            $dates[] = self::addDays($sentDay, self::QUOTE_TEXT_VALID_DAYS);
        }
        if ($quoteIdKnown || $marker === 'order') $dates[] = self::addDays($sentDay, self::UCRM_VALID_DAYS);
        if ($dates === []) $dates[] = self::addDays($sentDay, self::UCRM_VALID_DAYS);
        sort($dates);
        return $dates[0];
    }

    /** The date on the 📋 validity line, Y-m-d, or null when there is none or it cannot be read. */
    public static function statedValidity(string $body): ?string
    {
        $p = strpos($body, self::VALID_LINE);
        if ($p === false) return null;
        $rest = substr($body, $p + strlen(self::VALID_LINE));
        $nl = strpos($rest, "\n");
        $s = trim($nl === false ? $rest : substr($rest, 0, $nl));
        $d = \DateTime::createFromFormat('!d M Y', $s, new \DateTimeZone(self::TZ));
        if ($d === false) return null;
        $errs = \DateTime::getLastErrors();
        if (is_array($errs) && ((int)($errs['warning_count'] ?? 0) > 0 || (int)($errs['error_count'] ?? 0) > 0)) return null;
        return $d->format('Y-m-d');
    }

    /** The Kampala date of a UTC storage stamp. */
    public static function kampalaDay(string $utcStamp): string
    {
        $t = strtotime(trim($utcStamp) . ' UTC');
        if ($t === false) $t = 0;
        return (new \DateTime('@' . $t))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d');
    }

    private static function addDays(string $day, int $n): string
    {
        $d = new \DateTime($day . ' 12:00:00', new \DateTimeZone(self::TZ));
        $d->modify('+' . $n . ' days');
        return $d->format('Y-m-d');
    }

    // ── owners (F4, §H.2.9.4) ────────────────────────────────────────────────────────────────────────────────────

    /** An active account (StaffDirectory::isActive, lib/StaffDirectory.php:38): only active accounts count. */
    public static function isActive(array $row): bool
    {
        return !empty($row['is_active']);
    }

    /**
     * The id of the ONE active account named $name (exact, case-insensitive, trimmed; cron_quote_wa.php:177-183), or
     * null: no match, two or more active matches, or a match that is inactive only.
     */
    public static function activeByName(array $retailerRows, string $name): ?int
    {
        $want = strtolower(trim($name));
        if ($want === '') return null;
        $hit = [];
        foreach ($retailerRows as $r) {
            if (!is_array($r) || !self::isActive($r)) continue;
            if (strtolower(trim((string)($r['name'] ?? ''))) === $want && (int)($r['id'] ?? 0) > 0) $hit[(int)$r['id']] = true;
        }
        return count($hit) === 1 ? (int)array_key_first($hit) : null;
    }

    /** The id when it names an active account, else null. */
    public static function activeId(array $retailerRows, $id): ?int
    {
        $id = (int)$id;
        if ($id <= 0) return null;
        foreach ($retailerRows as $r) {
            if (is_array($r) && (int)($r['id'] ?? 0) === $id) return self::isActive($r) ? $id : null;
        }
        return null;
    }

    /**
     * Owner rule 1 on one KYC application: `sales_person` naming exactly one active account → kyc_sales_person; else an
     * active `retailer_id` → kyc_submitter; else none. Two or more active accounts named, or a name that matches only an
     * inactive account, give no owner from the name. The one function rules 1 and 6 and Part P's P7 call (IP-1).
     *
     * @return array{id:?int, source:string}
     */
    public static function kycAgent(array $application, array $retailerRows): array
    {
        $byName = self::activeByName($retailerRows, (string)($application['sales_person'] ?? ''));
        if ($byName !== null) return ['id' => $byName, 'source' => 'kyc_sales_person'];
        $sub = self::activeId($retailerRows, $application['retailer_id'] ?? 0);
        if ($sub !== null) return ['id' => $sub, 'source' => 'kyc_submitter'];
        return ['id' => null, 'source' => 'none'];
    }

    /**
     * Rules 1–7 (A12), the first that yields one active retailers row. $f holds what the tracker read for the row:
     *   kyc_app      the KYC application mapped by quote_id (rule 1)
     *   lead_log     a quotes_log row of type lead joined by crm_quote_id or quote_ref (rule 2's condition) …
     *   lead         … and the lead it names (its assigned_to)
     *   log          the quotes_log row joined by crm_quote_id or quote_ref (rule 3, its sent_by)
     *   spoke_chan   the spoke-to conversation's wa_channels row (rule 4: owner_type staff; a partner yields none)
     *   conv_lead    the lead that conversation's lead_id names (rule 5)
     *   client_app   the latest KYC for the same crm_client_id before sent_at (rule 6)
     *
     * @return array{id:?int, source:string}   source 'none' when no rule yields (rule 7: the central list)
     */
    public static function owner(array $f, array $retailerRows): array
    {
        if (is_array($f['kyc_app'] ?? null)) {
            $k = self::kycAgent($f['kyc_app'], $retailerRows);
            if ($k['id'] !== null) return $k;
        }
        if (!empty($f['lead_log']) && is_array($f['lead'] ?? null)) {
            $id = self::activeId($retailerRows, $f['lead']['assigned_to'] ?? 0);
            if ($id !== null) return ['id' => $id, 'source' => 'lead_owner'];
        }
        if (is_array($f['log'] ?? null)) {
            $id = self::activeByName($retailerRows, (string)($f['log']['sent_by'] ?? ''));
            if ($id !== null) return ['id' => $id, 'source' => 'quotes_log'];
        }
        $ch = $f['spoke_chan'] ?? null;
        if (is_array($ch) && (string)($ch['owner_type'] ?? '') === 'staff') {
            $id = self::activeId($retailerRows, $ch['owner_staff_id'] ?? 0);
            if ($id !== null) return ['id' => $id, 'source' => 'channel'];
        }
        if (is_array($f['conv_lead'] ?? null)) {
            $id = self::activeId($retailerRows, $f['conv_lead']['assigned_to'] ?? 0);
            if ($id !== null) return ['id' => $id, 'source' => 'conv_lead'];
        }
        if (is_array($f['client_app'] ?? null)) {
            $k = self::kycAgent($f['client_app'], $retailerRows);
            if ($k['id'] !== null) return ['id' => $k['id'], 'source' => 'kyc_client'];
        }
        return ['id' => null, 'source' => 'none'];
    }

    // ── spoke-to, the channel, the maker (§H.2.9.2, §H.2.9.5) ───────────────────────────────────────────────────

    /**
     * The conversation the customer spoke to us on: among $candidates (each id, channel, role, category, last_in — the
     * latest inbound row's stamp at or before the send, UTC, or null), those not filed 'staff' whose last_in falls in
     * (sent_at − 30 d, sent_at]; the later one wins; a tie goes to a sales-role channel, then the lower id. Null = never
     * wrote. The caller passes only conversations whose phone is exactly the customer's (never the last nine digits).
     */
    public static function spokeTo(array $candidates, string $sentAtUtc): ?array
    {
        $sent = strtotime(trim($sentAtUtc) . ' UTC');
        if ($sent === false) return null;
        $best = null;
        foreach ($candidates as $c) {
            if (!is_array($c)) continue;
            if (strtolower(trim((string)($c['category'] ?? ''))) === 'staff') continue;
            $li = trim((string)($c['last_in'] ?? ''));
            if ($li === '') continue;
            $t = strtotime($li . ' UTC');
            if ($t === false || $t <= $sent - self::SPOKE_WINDOW_S || $t > $sent) continue;
            $rank = [$t, ((string)($c['role'] ?? '') === 'sales') ? 1 : 0, -(int)($c['id'] ?? 0)];
            if ($best === null || $rank > $best[0]) $best = [$rank, $c];
        }
        return $best === null ? null : $best[1];
    }

    /** `account` when the conversation is `accounts` or wa_force_accounts is on (lib/NotificationService.php:127-129,1920-1926). */
    public static function sentChannel(string $convChannel, bool $forceAccounts): string
    {
        return ($convChannel === 'accounts' || $forceAccounts) ? 'account' : 'support';
    }

    /** wa_force_accounts as NotificationService reads it (true, '1' or 1). */
    public static function forceAccounts(array $config): bool
    {
        $v = $config['wa_force_accounts'] ?? false;
        return $v === true || $v === '1' || $v === 1;
    }

    /**
     * Who made it: resend for the WhatsApp tab's text fallback; kyc when a KYC application maps it; lte for a KYC-form
     * text no application maps (the LTE registration's, includes/api/api_crm_misc.php:574-640); lead or manual by the
     * plugin's own quote log or its event; ucrm otherwise (made in uCRM: the webhook or cron flow B).
     */
    public static function madeBy(string $event, ?array $kycApp, ?array $logRow): string
    {
        if ($event === 'ops_quote_text') return 'resend';
        if ($kycApp !== null) return 'kyc';
        if ($event === 'quote_kyc') return 'lte';
        $type = $logRow !== null ? (string)($logRow['type'] ?? '') : '';
        if ($event === 'quote_lead' || $type === 'lead') return 'lead';
        if ($event === 'quote_manual' || $type === 'manual') return 'manual';
        if ($type === 'kyc') return 'lte';
        return 'ucrm';
    }
}

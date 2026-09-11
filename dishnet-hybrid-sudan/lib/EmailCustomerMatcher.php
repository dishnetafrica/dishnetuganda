<?php
declare(strict_types=1);

/**
 * EmailCustomerMatcher — whose email is this?
 *
 * The first real one made the problem concrete. Felix Orech wrote from his own
 * address about a quotation raised for Subterra Limited, and copied in two
 * colleagues. The person is not the account; the account is the company, and
 * the address on the message may belong to any of its staff.
 *
 * So matching is graded, and the grade travels with the answer. An exact
 * address match on the client record is a fact. A shared company domain is
 * likely. A name appearing in a subject line is a guess. All three are useful
 * — a draft written with the right account's plan and balance is worth having
 * — but only the first should ever be treated as certain, and no grade here
 * unlocks an automatic send. That decision belongs to EmailReplyPolicy.
 *
 * Free-mail domains are never a match. Half of Uganda's businesses write from
 * gmail.com, and matching on it would attach one customer's balance to
 * another customer's question.
 */
class EmailCustomerMatcher
{
    const EXACT  = 'exact';    // the address is on the client record
    const DOMAIN = 'domain';   // same company domain as a client's address
    const NAME   = 'name';     // company name seen in the subject or body
    const NONE   = 'none';

    /** Domains that identify a mail provider, never a customer. */
    const FREE_MAIL = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com',
        'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'icloud.com',
        'me.com', 'aol.com', 'protonmail.com', 'proton.me', 'zoho.com',
        'mail.com', 'gmx.com', 'yandex.com', 'rocketmail.com',
    ];

    /** @var CrmApiClient */
    private $crm;

    public function __construct($crm)
    {
        $this->crm = $crm;
    }

    /**
     * @param string $from     the sender's address
     * @param string $subject  used only for a last-resort name match
     * @param string $body     ditto
     *
     * @return array{client:?array, confidence:string, why:string, candidates:int}
     */
    public function match(string $from, string $subject = '', string $body = ''): array
    {
        $from   = strtolower(trim($from));
        $domain = self::domainOf($from);

        // ── 1. The address itself. uCRM indexes clients by email, so ask it.
        if ($from !== '') {
            $hit = $this->searchClients($from);
            foreach ($hit as $c) {
                foreach (self::addressesOf($c) as $addr) {
                    if ($addr === $from) {
                        return $this->found($c, self::EXACT,
                            'the address is on the client record', count($hit));
                    }
                }
            }
        }

        // ── 2. The company domain. Only for domains a company actually owns.
        if ($domain !== '' && !self::isFreeMail($domain)) {
            $hit = $this->searchClients('@' . $domain);
            foreach ($hit as $c) {
                foreach (self::addressesOf($c) as $addr) {
                    if (self::domainOf($addr) === $domain) {
                        return $this->found($c, self::DOMAIN,
                            'a colleague at ' . $domain . ' is this client', count($hit));
                    }
                }
            }
        }

        // ── 3. A company name in the subject. Weakest, and marked as such:
        //       our own subjects carry the customer's name, and a reply keeps
        //       it, which is exactly how Felix's message could be placed at
        //       all. Useful, but never mistaken for proof.
        $name = self::companyNameIn($subject . ' ' . $body);
        if ($name !== '') {
            $hit = $this->searchClients($name);
            foreach ($hit as $c) {
                $label = strtolower(trim((string)($c['companyName'] ?? $c['name'] ?? '')));
                if ($label !== '' && strpos(strtolower($name), $label) !== false) {
                    return $this->found($c, self::NAME,
                        'the subject names "' . $label . '"', count($hit));
                }
            }
        }

        return ['client' => null, 'confidence' => self::NONE,
                'why' => 'no client matched this sender', 'candidates' => 0];
    }

    /** True when this match may be shown to a customer as fact. */
    public static function isCertain(string $confidence): bool
    {
        return $confidence === self::EXACT;
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function found(array $c, string $confidence, string $why, int $n): array
    {
        return ['client' => $c, 'confidence' => $confidence, 'why' => $why, 'candidates' => $n];
    }

    /** @return array<int,array> */
    private function searchClients(string $query): array
    {
        if ($query === '') return [];
        try {
            $r = $this->crm->get('clients?query=' . rawurlencode($query) . '&limit=25');
        } catch (\Throwable $e) {
            error_log('[EmailCustomerMatcher] client search failed: ' . $e->getMessage());
            return [];
        }
        return is_array($r) ? array_values(array_filter($r, 'is_array')) : [];
    }

    /**
     * Every address on a client record. uCRM keeps contacts in a nested list
     * and often the billing address differs from the person who writes to us.
     *
     * @return string[]
     */
    public static function addressesOf(array $client): array
    {
        $out = [];
        foreach (['email', 'invoiceEmail'] as $k) {
            $v = strtolower(trim((string)($client[$k] ?? '')));
            if ($v !== '') $out[] = $v;
        }
        foreach ((array)($client['contacts'] ?? []) as $contact) {
            if (!is_array($contact)) continue;
            $v = strtolower(trim((string)($contact['email'] ?? '')));
            if ($v !== '') $out[] = $v;
        }
        return array_values(array_unique($out));
    }

    public static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');
        return $at === false ? '' : strtolower(trim(substr($address, $at + 1)));
    }

    public static function isFreeMail(string $domain): bool
    {
        return in_array(strtolower($domain), self::FREE_MAIL, true);
    }

    /**
     * Pull a company-shaped name out of text: a capitalised run ending in a
     * company suffix. Deliberately narrow — a wrong name here attaches the
     * wrong account's balance to somebody's question.
     */
    public static function companyNameIn(string $text): string
    {
        $re = '/\b((?:[A-Z][\w&\.\'-]*\s+){0,4}'
            . '(?:Limited|Ltd\.?|Company|Co\.|Enterprises?|Holdings|Group|'
            . 'Uganda|International|Services|Solutions|Investments|SMC))\b/u';
        if (!preg_match($re, $text, $m)) return '';
        return trim(preg_replace('/\s+/', ' ', $m[1]) ?? '');
    }
}

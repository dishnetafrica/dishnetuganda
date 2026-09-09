<?php
/**
 * EmailReplyPolicy — what the AI may answer, and what a person must see first.
 *
 * The split is not about how hard a question is. It is about what a wrong
 * answer costs. Quoting the wrong price wastes a phone call; conceding a
 * refund, admitting fault in a dispute, or improvising on a UCC obligation
 * creates a commitment DishNet may have to honour. Anything that could bind
 * the company, cost it money, or be read back to it later goes to a human,
 * however confident the model sounds.
 *
 * Even the safe half starts switched off. NEVER_AUTO cannot be switched on at
 * all — that is the point of it being a separate list rather than a default.
 *
 * PHP 7.4 compatible.
 */
declare(strict_types=1);

class EmailReplyPolicy
{
    /**
     * Categories where a wrong answer is recoverable: the facts are published,
     * the customer can check them, and nothing is promised.
     */
    const MAY_AUTO = [
        'plans_pricing'   => 'What plans and prices we offer',
        'coverage'        => 'Whether we cover an area, and what is involved',
        'installation'    => 'How installation works and what to expect',
        'how_to_pay'      => 'Payment methods and bank details',
        'contact_hours'   => 'Office location, opening hours, phone numbers',
        'account_status'  => 'Reading back what the account record already says',
        'invoice_query'   => 'Explaining what an invoice or receipt covers',
        'general_info'    => 'What Starlink is, what is included, how it works',
    ];

    /**
     * Categories a person must approve, always. Not configurable: an operator
     * who could switch these on would eventually switch them on.
     */
    const NEVER_AUTO = [
        'refund'          => 'Refunds and money back',
        'compensation'    => 'Credit, discount or compensation for an outage',
        'complaint'       => 'A complaint about service or staff',
        'dispute'         => 'A disputed charge, payment or contract term',
        'cancellation'    => 'Cancelling, downgrading or terminating',
        'legal'           => 'Anything legal, contractual or threatening',
        'regulatory'      => 'UCC or other regulatory questions',
        'outage_claim'    => 'A claim about service failure and its consequences',
        'unclear'         => 'Anything the classifier could not place confidently',

        // Added after the first real customer email. Subterra Limited replied
        // to a quotation with a purchase order attached and asked when we
        // would install. Nothing in the list above covered it, so it read as
        // 'installation' — a MAY_AUTO category — and a machine could have
        // answered an order with a date nobody had committed to.
        //
        // These four are all commitments. None is a published fact.
        'order_po'        => 'A purchase order or an order being placed',
        'schedule_request'=> 'Asking us to commit to a date or a visit',
        'payment_claim'   => 'A customer saying they have paid',
        'contract_signed' => 'A signed contract, agreement or document returned',
        'technical_fault' => 'A report that the service is not working',
    ];

    /** Every category, for settings screens and the draft inbox. */
    public static function all(): array
    {
        return self::MAY_AUTO + self::NEVER_AUTO;
    }

    public static function isKnown(string $category): bool
    {
        return isset(self::MAY_AUTO[$category]) || isset(self::NEVER_AUTO[$category]);
    }

    /**
     * Must a human see this before it goes out?
     *
     * An unknown category counts as yes. A classifier that invents a label
     * must not thereby unlock automatic sending.
     */
    public static function requiresHuman(string $category): bool
    {
        return !isset(self::MAY_AUTO[$category]);
    }

    /**
     * May this reply be sent without a person reading it?
     *
     * Three things must all be true: the category is in MAY_AUTO, the operator
     * has switched automatic sending on for that category, and the classifier
     * was confident. Any doubt goes to the draft inbox, which is never the
     * wrong answer — only slower.
     */
    public static function mayAutoSend(string $category, array $config, float $confidence = 0.0): bool
    {
        if (self::requiresHuman($category))          return false;
        if (empty($config['email_ai_auto_send']))    return false;   // master, off by default
        if (empty($config['email_ai_auto_' . $category])) return false;
        $floor = (float)($config['email_ai_confidence_floor'] ?? 0.8);
        return $confidence >= $floor;
    }

    /**
     * Words that force a human regardless of the category assigned.
     *
     * A message can be classified "plans_pricing" and still say "or I will
     * take this to the UCC". The classifier reads intent; this reads the
     * words, and either one is enough to stop an automatic reply.
     */
    const ESCALATION_WORDS = [
        'refund', 'money back', 'compensation', 'compensate', 'lawyer', 'legal action',
        'sue', 'court', 'attorney', 'advocate', 'uccc', 'ucc', 'regulator', 'ombudsman',
        'complaint', 'complain', 'fraud', 'scam', 'cheat', 'steal', 'stolen',
        'cancel my', 'terminate', 'disconnect me', 'close my account',
        'police', 'report you', 'take you to', 'consumer protection',
    ];

    /**
     * @return array{escalate:bool, matched:string}
     */
    public static function scanForEscalation(string $text): array
    {
        $t = ' ' . strtolower(preg_replace('/\s+/', ' ', $text)) . ' ';
        foreach (self::ESCALATION_WORDS as $w) {
            // Word-boundary match so "ucc" does not fire on "succumb" and
            // "sue" does not fire on "issue" — a false escalation is cheap,
            // but a filter nobody trusts gets switched off.
            if (preg_match('/\b' . preg_quote($w, '/') . '\b/', $t)) {
                return ['escalate' => true, 'matched' => $w];
            }
        }
        return ['escalate' => false, 'matched' => ''];
    }
}

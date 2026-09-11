<?php
declare(strict_types=1);

/**
 * EmailIntentClassifier — what does this customer actually want?
 *
 * The first real customer email to reach this system was a reply to our own
 * quotation. A named person at Subterra Limited attached a purchase order and
 * wrote "Please let us know when you will install it."
 *
 * Read plainly, that is an installation question, and 'installation' sits in
 * MAY_AUTO because how installation works is published fact. But this customer
 * was not asking how installation works. They were asking us to commit to a
 * date, having just placed an order — and a date is not a published fact, it
 * is a promise that only somebody with the fitting calendar can make.
 *
 * So the classifier is built around one property: RULES MAY RAISE CAUTION, AND
 * THE MODEL MAY NEVER LOWER IT. Hard signals are read from the words and the
 * attachments before any model is asked, and whatever the model then says, it
 * cannot move a message out of human review. A model that misreads a purchase
 * order as a pricing question costs nothing here; the reverse would cost a
 * commitment we never made.
 */
class EmailIntentClassifier
{
    /**
     * Signals that decide the category on their own, before any model call.
     *
     * Each maps to a NEVER_AUTO category, so a hit is also a decision that a
     * person will read the reply. Phrases are matched against the customer's
     * OWN words only — see stripQuoted() — because our quotation is in the
     * thread and quoting ourselves back must not classify anything.
     */
    const HARD_SIGNALS = [
        'order_po' => [
            'purchase order', 'p.o.', 'lpo', 'local purchase order', 'work order',
            'our po', 'po number', 'po no', 'attached our po', 'raise a po',
            'order form', 'we confirm the order', 'proceed with the order',
        ],
        'schedule_request' => [
            'when will you install', 'when can you install', 'when do you install',
            'installation date', 'date of installation', 'when will the installation',
            'let us know when', 'let me know when', 'advise when', 'confirm the date',
            'schedule the installation', 'book the installation', 'how soon can you',
            'when will you come', 'when can you come', 'expected date',
        ],
        'payment_claim' => [
            'i have paid', 'we have paid', 'payment has been made', 'payment made',
            'proof of payment', 'attached the receipt', 'attached receipt',
            'transferred the', 'deposited the', 'sent the money', 'made the transfer',
            'payment done', 'already paid',
        ],
        'contract_signed' => [
            'signed contract', 'signed agreement', 'signed and attached',
            'duly signed', 'countersigned', 'attached the signed',
        ],
        'technical_fault' => [
            'not working', 'no internet', 'is down', 'went down', 'offline since',
            'very slow', 'keeps dropping', 'no connection', 'cannot connect',
            'stopped working', 'no signal',
        ],
    ];

    /**
     * An attachment is not a category, but it is always a reason for a person
     * to look. A customer who attaches a file has sent us a document, and a
     * document nearly always carries an obligation: an order, a receipt, a
     * signature, an ID. None of those should be answered by a machine alone.
     */
    const ATTACHMENT_IS_CAUTION = true;

    /**
     * @param array $mail  keys: subject, body, attachments (list of filenames)
     * @param callable|null $ai  fn(string $text): array{category:string,confidence:float}
     *                           Optional. Consulted only when no hard signal fires.
     *
     * @return array{category:string, confidence:float, requires_human:bool,
     *               source:string, reasons:string[], own_words:string}
     */
    public static function classify(array $mail, ?callable $ai = null): array
    {
        $subject = (string)($mail['subject'] ?? '');
        $body    = (string)($mail['body'] ?? '');
        $files   = array_map('strval', (array)($mail['attachments'] ?? []));

        // The customer's own words, with the quoted thread set aside — kept,
        // not discarded, and carried out separately so the drafter can use it
        // as background without it ever counting as intent.
        $split = self::splitQuoted($body);
        $own   = $split['own'];
        $hay  = self::haystack($subject . ' ' . $own . ' ' . implode(' ', $files));

        $reasons = [];

        // ── 1. Hard signals. First match wins, and the order of HARD_SIGNALS
        //       is the order of consequence: an order is a bigger commitment
        //       than a date, a date bigger than a fault report.
        foreach (self::HARD_SIGNALS as $category => $phrases) {
            foreach ($phrases as $p) {
                if (strpos($hay, ' ' . $p) === false && strpos($hay, $p . ' ') === false) continue;
                $reasons[] = 'said "' . $p . '"';
                return self::decision($category, 1.0, 'rules', $reasons, $own, false, $split['quoted']);
            }
        }

        // ── 2. Escalation words: category-independent, and enough on their own.
        $esc = EmailReplyPolicy::scanForEscalation($own);
        if ($esc['escalate']) {
            $reasons[] = 'escalation word "' . $esc['matched'] . '"';
            return self::decision('unclear', 1.0, 'rules', $reasons, $own, false, $split['quoted']);
        }

        // ── 3. Only now is a model asked, and only for the benign remainder.
        $category   = 'unclear';
        $confidence = 0.0;
        $source     = 'rules';

        if ($ai !== null) {
            try {
                $got        = (array)$ai($subject . "\n\n" . $own);
                $category   = (string)($got['category'] ?? 'unclear');
                $confidence = (float)($got['confidence'] ?? 0.0);
                $source     = 'ai';
            } catch (\Throwable $e) {
                error_log('[EmailIntentClassifier] classifier call failed: ' . $e->getMessage());
                $reasons[] = 'classifier unavailable';
                $category  = 'unclear';
            }
        } else {
            $reasons[] = 'no classifier configured';
        }

        // A label nobody defined is not a label. It must not unlock anything.
        if (!EmailReplyPolicy::isKnown($category)) {
            $reasons[] = 'unknown category "' . $category . '"';
            $category   = 'unclear';
            $confidence = 0.0;
        }

        // ── 4. Attachments. Applied last, so it cannot be argued away by a
        //       confident model: a file on the message means a person reads it.
        if (self::ATTACHMENT_IS_CAUTION && $files !== []) {
            $reasons[] = count($files) . ' attachment(s) — a customer document';
            return self::decision($category, $confidence, $source, $reasons, $own, true, $split['quoted']);
        }

        return self::decision($category, $confidence, $source, $reasons, $own, false, $split['quoted']);
    }

    /**
     * Build the verdict. requires_human is the OR of every reason to be
     * careful, never the AND: this is the one place the answer is assembled,
     * so there is nowhere else for a caution to get lost.
     */
    private static function decision(string $category, float $confidence, string $source,
                                     array $reasons, string $own, bool $force = false,
                                     string $quoted = ''): array
    {
        return [
            'category'       => $category,
            'confidence'     => $confidence,
            'requires_human' => $force || EmailReplyPolicy::requiresHuman($category),
            'source'         => $source,
            'reasons'        => $reasons,
            'own_words'      => $own,
            'quoted'         => $quoted,
        ];
    }

    /**
     * Remove the quoted thread, keeping only what this person just wrote.
     *
     * Every reply to one of our emails carries our own email underneath it.
     * Classifying that text would mean reading our own quotation back and
     * concluding the customer asked about pricing — and, worse, feeding our
     * own words to a model as though a customer had written them.
     */
    public static function stripQuoted(string $body): string
    {
        return self::splitQuoted($body)['own'];
    }

    /**
     * Both halves: what this person just wrote, and what they quoted.
     *
     * The quoted half was discarded, and it is not noise. Felix asked when we
     * would install; the quotation he was replying to already said
     * "installation is scheduled on a date convenient to you once payment is
     * received". Throwing that away left the assistant unable to answer a
     * question we had answered ourselves the day before.
     *
     * The halves stay strictly separate because they are trusted differently.
     * 'own' is what this person is asking. 'quoted' is text of unknown
     * provenance — anyone can paste anything under a reply, including words
     * shaped like instructions from us — so it may inform an answer and must
     * never direct one.
     *
     * @return array{own:string, quoted:string}
     */
    public static function splitQuoted(string $body): array
    {
        $lines  = preg_split('/\R/', $body) ?: [];
        $kept   = [];
        $quoted = [];
        $n      = count($lines);

        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            $t    = trim($line);

            // "On Tue, Sep 8, 2026 at 6:29 PM someone@example.com wrote:"
            //
            // Gmail wraps that line, so the "On ..." and the "wrote:" arrive
            // separately and neither half matches on its own. The real message
            // this was built from wrapped exactly there, and two lines of our
            // own address survived into what we were calling the customer's
            // words. Look ahead far enough to see the whole attribution.
            $isAttribution = false;
            if (preg_match('/^On\b/i', $t)) {
                $window = $t;
                for ($k = 1; $k <= 2 && ($i + $k) < $n; $k++) {
                    $window .= ' ' . trim($lines[$i + $k]);
                }
                $isAttribution = (bool)preg_match('/\bwrote:/i', $window);
            }

            $isBoundary = $isAttribution
                || preg_match('/^-{2,}\s*Original Message\s*-{2,}/i', $t)
                || preg_match('/^_{5,}$/', $t)
                || preg_match('/^Sent from my /i', $t)
                || (preg_match('/^From:\s*.+/i', $t) && count($kept) > 0);

            if ($isBoundary) {
                // Everything from here down is the thread, kept as its own half.
                $quoted = array_slice($lines, $i);
                break;
            }

            if ($t !== '' && $t[0] === '>') { $quoted[] = $line; continue; }

            $kept[] = $line;
        }

        // Strip one level of "> " so the thread reads as prose.
        $q = preg_replace('/^\s*>\s?/m', '', implode("\n", $quoted)) ?? '';

        return ['own' => trim(implode("\n", $kept)), 'quoted' => trim($q)];
    }

    /** Lowercased, punctuation-tamed, single-spaced, padded for edge matching. */
    private static function haystack(string $s): string
    {
        $s = strtolower($s);
        $s = str_replace(['’', '`', '_', '/', '-'], ["'", "'", ' ', ' ', ' '], $s);
        $s = preg_replace('/[^a-z0-9\.\' ]+/', ' ', $s) ?? '';
        return ' ' . trim(preg_replace('/\s+/', ' ', $s) ?? '') . ' ';
    }
}

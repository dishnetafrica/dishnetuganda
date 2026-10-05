<?php
declare(strict_types=1);

require_once __DIR__ . '/PaymentEvidence.php';
require_once __DIR__ . '/ReplyPrivacyGuard.php';

/**
 * DocumentClassifier — what kind of document is this, and may the assistant see it? (Batch 4, docs/58 §6.4 and the operator's
 * fail-closed rule.)
 *
 * Deterministic words rules, this plugin's own, like PaymentEvidence. Nine classes in a fixed precedence — the first match wins:
 * credential → identity_document → a DECISIVE phrase of statement / invoice / contract / quotation → payment_proof (the Batch 3
 * money-and-transaction rule) → two supporting phrases of statement / invoice / contract / quotation → spreadsheet → general.
 * The first seven are HUMAN-ONLY: they never produce an AI turn. The last two may enter the EXISTING assistant.
 *
 * THE RULE FAILS CLOSED. The assistant sees a document only when the classifier saw ALL of its text and found NO signal of any
 * sensitive class — not one money word, not one transaction word, not one phrase from the identity, statement, invoice,
 * contract or quotation lists, nothing in the file name. Anything weaker than a decision is `classification_uncertain`; text
 * the readers could not finish is `classification_incomplete`; an exception is `classification_failed`; each is a person.
 * Never uncertain → general → AI. PHP 7.4 compatible.
 */
final class DocumentClassifier
{
    public const CLASSES        = ['credential', 'identity_document', 'payment_proof', 'statement', 'invoice', 'contract', 'quotation', 'spreadsheet', 'general'];
    public const HUMAN_ONLY     = ['credential', 'identity_document', 'payment_proof', 'statement', 'invoice', 'contract', 'quotation'];
    public const BRAIN_ELIGIBLE = ['general', 'spreadsheet'];

    /**
     * One decisive phrase decides a class; two supporting phrases decide it too; a single supporting phrase is a signal that
     * keeps the document away from the assistant. Lower-case; matched on word boundaries.
     */
    private const RULES = [
        'identity_document' => [
            'decisive'   => ['national identification', 'national id card', 'passport no', 'passport number', 'driving permit', 'refugee attestation',
                             'identity card', 'identification card', 'alien card', 'voter card', 'date of issue'],
            'supporting' => ['passport', 'nin', 'date of birth', 'nationality', 'card no', 'card number', 'place of birth', 'republic of uganda',
                             'surname', 'given name', 'given names', 'date of expiry', 'expiry date', 'sex', 'holder', 'identity', 'kyc'],
        ],
        'statement' => [
            'decisive'   => ['account statement', 'bank statement', 'statement of account', 'mini statement', 'closing balance', 'opening balance',
                             'balance brought forward', 'balance b/f', 'running balance', 'available balance'],
            'supporting' => ['statement', 'debit', 'credit', 'transaction date', 'value date', 'ledger', 'account no', 'account number', 'withdrawal'],
        ],
        'invoice' => [
            'decisive'   => ['tax invoice', 'invoice no', 'invoice number', 'invoice #', 'amount due', 'total due', 'balance due', 'invoice date'],
            'supporting' => ['invoice', 'due date', 'bill to', 'billed to', 'tin', 'vat', 'subtotal', 'payment terms', 'remit', 'pay by'],
        ],
        'contract' => [
            'decisive'   => ['service agreement', 'this agreement', 'terms and conditions', 'hereby agree', 'hereby agrees', 'memorandum of understanding',
                             'the parties', 'in witness whereof', 'governing law', 'service level agreement'],
            'supporting' => ['agreement', 'contract', 'signature', 'signed', 'witness', 'effective date', 'termination', 'clause', 'whereas',
                             'indemnify', 'indemnity', 'liability', 'party', 'parties', 'binding'],
        ],
        'quotation' => [
            'decisive'   => ['quotation', 'proforma', 'pro-forma', 'quote no', 'quote number', 'bill of quantities'],
            'supporting' => ['quote', 'quoted', 'valid until', 'validity', 'unit price', 'qty', 'quantity', 'boq', 'discount', 'lead time'],
        ],
    ];

    /** A word in the file name or a sheet name that is itself a signal. */
    private const NAME_SIGNALS = ['receipt', 'payment', 'paid', 'momo', 'mpesa', 'transaction', 'statement', 'invoice', 'inv', 'contract',
                                  'agreement', 'quotation', 'quote', 'proforma', 'passport', 'national', 'nin', 'id', 'kyc', 'bank', 'password', 'credentials'];

    /**
     * @param string   $text          the normalised extract the readers produced (the scan window, at most DOCUMENT_MAX_SCAN_CHARS)
     * @param string   $kind          pdf · docx · xlsx · csv · txt
     * @param string[] $signals       words from the file name and the sheet names — signals, never content
     * @param bool     $sawEverything false when any reader cap or the scan window cut the text: then nothing is harmless
     * @return array{class:?string, route:string, reason:string, hits:string[]}
     */
    public static function classify(string $text, string $kind, array $signals, bool $sawEverything): array
    {
        try {
            return self::decide($text, $kind, $signals, $sawEverything);
        } catch (\Throwable $e) {
            return ['class' => null, 'route' => 'human', 'reason' => 'classification_failed', 'hits' => [get_class($e)]];
        }
    }

    public static function isHumanOnly(string $class): bool
    {
        return in_array($class, self::HUMAN_ONLY, true);
    }

    private static function decide(string $text, string $kind, array $signals, bool $sawEverything): array
    {
        if (trim($text) === '') return ['class' => null, 'route' => 'human', 'reason' => 'empty_extraction', 'hits' => []];
        $sigWords = array_values(array_filter(array_map(function ($s) { return mb_strtolower(trim((string)$s)); }, $signals), function ($s) { return $s !== ''; }));
        $all = mb_strtolower($text) . ' ' . implode(' ', $sigWords);

        // 1. A credential, by the shapes the reply guard already refuses — on the text as written, before anything else.
        $secret = ReplyPrivacyGuard::secretShapesIn($text);
        if ($secret !== []) return ['class' => 'credential', 'route' => 'human', 'reason' => 'class', 'hits' => array_map(function ($s) { return 'secret:' . $s; }, $secret)];

        $hits = [];
        foreach (self::RULES as $class => $rule) {
            $hits[$class] = ['decisive' => self::phrases($rule['decisive'], $all), 'supporting' => self::phrases($rule['supporting'], $all)];
        }
        // 2. An identity document.
        if (self::decided($hits['identity_document'])) return self::human('identity_document', $hits['identity_document']);
        // 3. A DECISIVE phrase of a statement, an invoice, a contract or a quotation names the document more precisely than
        //    the payment words rule would (a statement has deposits and balances; an invoice has an amount and "pay by"), and
        //    the person is told which it is. Every one of them is human-only, so the route is the same either way.
        foreach (['statement', 'invoice', 'contract', 'quotation'] as $class) {
            if (count($hits[$class]['decisive']) >= 1) return self::human($class, $hits[$class]);
        }
        // 4. Payment evidence — the Batch 3 rule, verbatim: a money token AND a transaction token.
        if (PaymentEvidence::looksLikePayment('general', $text, $sigWords)) return ['class' => 'payment_proof', 'route' => 'human', 'reason' => 'class', 'hits' => ['payment:words']];
        // 5. Two supporting phrases decide a statement, an invoice, a contract or a quotation as well.
        foreach (['statement', 'invoice', 'contract', 'quotation'] as $class) {
            if (self::decided($hits[$class])) return self::human($class, $hits[$class]);
        }
        // 8. Text the readers could not finish: nothing about it is harmless.
        if (!$sawEverything) return ['class' => null, 'route' => 'human', 'reason' => 'classification_incomplete', 'hits' => ['scan:incomplete']];
        // 9. Any single signal of a sensitive class keeps the document from the assistant.
        $weak = [];
        foreach ($hits as $class => $h) {
            foreach (array_merge($h['decisive'], $h['supporting']) as $p) $weak[] = $class . ':' . $p;
        }
        if (PaymentEvidence::hasMoneyToken($all)) $weak[] = 'money';
        if (PaymentEvidence::hasTransactionToken($all)) $weak[] = 'transaction';
        foreach ($sigWords as $w) {
            foreach (preg_split('/[^a-z0-9]+/', $w) ?: [] as $part) {
                if ($part !== '' && in_array($part, self::NAME_SIGNALS, true)) $weak[] = 'name:' . $part;
            }
        }
        if ($weak !== []) return ['class' => null, 'route' => 'human', 'reason' => 'classification_uncertain', 'hits' => array_values(array_unique($weak))];
        // 10. Harmless, and seen whole.
        $class = in_array($kind, ['xlsx', 'csv'], true) ? 'spreadsheet' : 'general';
        return ['class' => $class, 'route' => 'brain', 'reason' => 'class', 'hits' => []];
    }

    /** @return string[] the phrases of the list that occur, on word boundaries */
    private static function phrases(array $list, string $haystack): array
    {
        $found = [];
        foreach ($list as $p) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($p, '/') . '(?![\p{L}\p{N}])/u', $haystack) === 1) $found[] = $p;
        }
        return $found;
    }

    private static function decided(array $h): bool
    {
        return count($h['decisive']) >= 1 || count($h['supporting']) >= 2;
    }

    private static function human(string $class, array $h): array
    {
        $hits = array_merge(array_map(function ($p) use ($class) { return $class . ':' . $p; }, $h['decisive']),
                            array_map(function ($p) use ($class) { return $class . ':' . $p; }, $h['supporting']));
        return ['class' => $class, 'route' => 'human', 'reason' => 'class', 'hits' => $hits];
    }
}

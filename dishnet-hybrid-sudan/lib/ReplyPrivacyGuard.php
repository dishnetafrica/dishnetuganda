<?php
declare(strict_types=1);

/**
 * ReplyPrivacyGuard — the last check before a reply reaches a person.
 *
 * SECONDARY. The boundary is CustomerDataTools: a customer-facing tool has no
 * code path to another customer's record, and that holds whatever the model
 * does. This guard exists for the case the boundary cannot cover — the model
 * inventing, remembering, or being fed something through a channel that is
 * not a tool. It catches a mistake; it does not create the guarantee.
 *
 * ── HOW IT KNOWS ANOTHER CUSTOMER'S DATA WITHOUT HOLDING ANY ────────────
 *
 * It never sees another customer's values, and it does not need to. It works
 * the other way round: an ALLOWLIST of what this customer was permitted, and
 * anything identifier-shaped outside it is refused.
 *
 *     permitted = what CustomerDataTools actually returned this turn
 *               + what the customer told us themselves
 *               + figures already public in the model's own prompt
 *
 * So if the reply names KIT999999999ZZ9 and no tool returned it, the guard
 * blocks it without ever having been told that the kit belongs to customer
 * 21 — or that customer 21 exists. The guard obeys the same least-privilege
 * rule as everything else: it is given one customer's permitted values, not
 * a dataset to search.
 *
 * This is also why it is not a keyword blocker. "password" in a sentence is
 * not a leak; an API key is, whatever words surround it. The categories below
 * are shape-based or allowlist-based, never vocabulary-based, with one narrow
 * exception for cost and margin, which is phrase-based because cost has no
 * distinguishing shape.
 *
 * ── ON FAILURE, THE WHOLE REPLY GOES ────────────────────────────────────
 *
 * Not redacted. A reply with a confidential fragment cut out of it is a reply
 * whose remaining sentences were written to explain the fragment, and partial
 * redaction leaves the shape of what was removed. The customer gets a safe
 * fallback that contains nothing, and a person picks it up.
 *
 * ── MODALITY AGNOSTIC ───────────────────────────────────────────────────
 *
 * check() takes a string and a permitted set. It knows nothing about
 * WhatsApp, and nothing about how the reply was produced, so the same guard
 * serves voice, image and document replies when those arrive.
 */
final class ReplyPrivacyGuard
{
    public const SAFE_FALLBACK =
        "I'm not able to complete that one automatically. I've passed it to our "
      . "team and someone will get back to you shortly.";

    /**
     * Things that are secret by their shape, whoever is asking.
     *
     * No allowlist exception applies to these: there is no customer for whom
     * an API key is permitted data.
     */
    private const SECRET_SHAPES = [
        'api_key'     => '/\b(?:sk-ant-|sk-[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{20,}|xox[baprs]-[A-Za-z0-9-]{10,})/',
        'bearer'      => '/\bBearer\s+[A-Za-z0-9._~+\/-]{20,}=*/i',
        'private_key' => '/-----BEGIN (?:RSA |EC |OPENSSH |PGP )?PRIVATE KEY-----/',
        'jwt'         => '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
        'cookie'      => '/\b(?:starlink\.com\.[a-z_]+|session[_-]?(?:id|token)|PHPSESSID)\s*=\s*\S{8,}/i',
        'conn_string' => '/\b(?:mysql|postgres(?:ql)?|mongodb|redis):\/\/[^\s]+:[^\s]+@/i',
        'credential'  => '/\b(?:password|passwd|api[_-]?key|secret|token)\s*[:=]\s*\S{6,}/i',
    ];

    /**
     * Identifier shapes whose every instance must be in the allowlist.
     *
     * A Starlink kit, an invoice number, a Starlink account, a phone number.
     * If the reply contains one the tools did not return, it came from
     * somewhere it should not have.
     */
    private const IDENTIFIER_SHAPES = [
        'kit'     => '/\bKIT[0-9A-Z]{4,}\b/i',
        'invoice' => '/\bINV-[A-Z0-9]+(?:-[A-Z0-9]+)+\b/i',
        'account' => '/\bACC-[A-Z0-9]+(?:-[A-Z0-9]+)+\b/i',
        // (?!\d) not (?![\d.]): a phone at the end of a sentence is followed by a
        // full stop, and excluding '.' here silently missed every one of them.
        'phone'   => '/(?<!\d)(?:\+?\d[\d\s-]{8,}\d)(?!\d)/',
    ];

    /**
     * Cost and margin, which have no shape of their own.
     *
     * Phrase-based and deliberately narrow: the disclosing construction, not
     * the word. "What does it cost me?" is a customer asking a fair question;
     * "it costs us" is the thing that must never be said.
     */
    private const INTERNAL_PHRASES = [
        // 'our cost', never bare 'the cost' — "the cost to you" is the customer's
        // own fair question. And 'we pay <anyone> for' catches the supplier named.
        'cost_or_margin' => '/\b(?:our|dishnet\'?s?)\s+(?:cost|margin|markup|buying\s+price|purchase\s+price|wholesale)\b|\b(?:we|dishnet)\s+(?:pay|paid|buy|bought|purchase[ds]?)\b(?![^.]{0,20}\byou\b)|\bcosts?\s+us\b|\bprofit\s+margin\b/i',
        'supplier'       => '/\b(?:supplier|wholesale)\s+(?:price|cost|invoice|terms|discount)\b/i',
    ];

    /**
     * Inspect a reply.
     *
     * @param string $reply     what the model produced
     * @param array  $permitted ['values' => string[], 'prompt' => string]
     *                          values: what the tools returned, plus what the
     *                          customer themselves said. prompt: the system
     *                          prompt, so a figure already public in it is
     *                          allowed, and so a verbatim run FROM it is caught.
     * @return array{safe:bool, reply:string, categories:array<int,string>}
     */
    public static function check(string $reply, array $permitted = []): array
    {
        $cats = [];
        $text = (string)$reply;

        if (trim($text) === '') {
            return ['safe' => false, 'reply' => self::SAFE_FALLBACK, 'categories' => ['empty']];
        }

        foreach (self::SECRET_SHAPES as $name => $re) {
            if (preg_match($re, $text) === 1) $cats[] = 'secret:' . $name;
        }
        foreach (self::INTERNAL_PHRASES as $name => $re) {
            if (preg_match($re, $text) === 1) $cats[] = 'internal:' . $name;
        }

        $allow  = self::normaliseSet((array)($permitted['values'] ?? []));
        $prompt = (string)($permitted['prompt'] ?? '');

        // Scanned twice: as written, and with internal spacing removed, so
        // "KIT 999999999ZZ9" cannot walk past a pattern that expects no space.
        $collapsed = (string)preg_replace('/(?<=[A-Za-z0-9])[ \t]+(?=[A-Za-z0-9])/', '', $text);
        foreach (self::IDENTIFIER_SHAPES as $name => $re) {
            $hits = [];
            if (preg_match_all($re, $text, $m1) > 0) $hits = $m1[0];
            if ($collapsed !== $text && preg_match_all($re, $collapsed, $m2) > 0) {
                $hits = array_merge($hits, $m2[0]);
            }
            if ($hits === []) continue;
            $m = [$hits];
            foreach ($m[0] as $found) {
                $n = self::normalise($found);
                if ($n === '' || isset($allow[$n])) continue;
                // A figure the model was already given publicly, in its own
                // prompt, is not a disclosure of anyone's record.
                if ($prompt !== '' && stripos($prompt, trim($found)) !== false) continue;
                $cats[] = 'foreign:' . $name;
                break;
            }
        }

        // Money the tools did not return and the public prompt does not
        // contain. This is what catches a supplier cost stated as a number.
        if (preg_match_all('/(?<![\d.,])\d{1,3}(?:[,\s]\d{3})+(?:\.\d{1,2})?(?![\d])/', $text, $m) > 0) {
            foreach ($m[0] as $amount) {
                $n = self::normalise($amount);
                if ($n === '' || isset($allow[$n])) continue;
                if ($prompt !== '' && self::promptHasAmount($prompt, $n)) continue;
                $cats[] = 'foreign:amount';
                break;
            }
        }

        // A verbatim run out of our own instructions.
        if ($prompt !== '' && self::quotesPrompt($text, $prompt)) $cats[] = 'system_prompt';

        $cats = array_values(array_unique($cats));
        return $cats === []
            ? ['safe' => true,  'reply' => $text, 'categories' => []]
            : ['safe' => false, 'reply' => self::SAFE_FALLBACK, 'categories' => $cats];
    }

    /** Digits and letters only, upper-cased — so formatting cannot smuggle. */
    public static function normalise(string $s): string
    {
        return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $s));
    }

    /** @return array<string,true> */
    private static function normaliseSet(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (is_array($v) || $v === null || is_bool($v)) continue;
            $s = self::normalise((string)$v);
            if ($s === '') continue;
            $out[$s] = true;
            // 249000 and 249,000.00 are the same permission.
            if (preg_match('/^\d+$/', $s) === 1) $out[ltrim($s, '0') ?: '0'] = true;
            if (substr($s, -2) === '00' && strlen($s) > 2) $out[substr($s, 0, -2)] = true;
        }
        return $out;
    }

    private static function promptHasAmount(string $prompt, string $normalised): bool
    {
        $digits = preg_replace('/[^0-9]/', '', $prompt) ?? '';
        return $normalised !== '' && strpos($digits, $normalised) !== false;
    }

    /**
     * Whether the reply reproduces a long run of the system prompt.
     *
     * Sentence-level rather than word-level: a model repeating one phrase it
     * was told is ordinary, reciting a whole instruction is not.
     */
    private static function quotesPrompt(string $reply, string $prompt): bool
    {
        foreach (preg_split('/(?<=[.\n])\s+/', $prompt) ?: [] as $line) {
            $line = trim((string)$line);
            if (strlen($line) < 45) continue;
            if (stripos($reply, $line) !== false) return true;
        }
        return false;
    }

    /**
     * The audit record for a block. Metadata only.
     *
     * The blocked text is deliberately absent: writing a leaked credential
     * into a log to record that it was caught simply moves it somewhere with
     * fewer controls. What is kept is enough to investigate — who, when,
     * which conversation, which category, which model — and nothing that
     * would itself be a disclosure.
     *
     * @return array<string,mixed>
     */
    public static function auditEvent(array $result, array $meta = []): array
    {
        return [
            'event'           => 'reply_blocked',
            'at'              => gmdate('c'),
            'customer_id'     => (int)($meta['customer_id'] ?? 0),
            'conversation_id' => (int)($meta['conversation_id'] ?? 0),
            'message_id'      => (string)($meta['message_id'] ?? ''),
            'channel'         => (string)($meta['channel'] ?? ''),
            'modality'        => (string)($meta['modality'] ?? 'text'),
            'provider'        => (string)($meta['provider'] ?? ''),
            'categories'      => array_values((array)($result['categories'] ?? [])),
            'tools_called'    => array_values((array)($meta['tools_called'] ?? [])),
            'blocked_length'  => (int)($meta['blocked_length'] ?? 0),
            'status'          => 'blocked',
        ];
    }
}

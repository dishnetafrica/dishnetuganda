<?php
declare(strict_types=1);

/**
 * PromptRiskSignal — notices suspicious language. Decides nothing.
 *
 * These patterns used to sit inside ClaudeWaClient::getReply() and
 * GptWaClient::getReply(), where a match returned a canned refusal before the
 * API was called, under a comment reading "never pass these to Claude". That
 * framing is the problem this class exists to correct.
 *
 * ── WHAT THE AUDIT ACTUALLY FOUND ───────────────────────────────────────
 *
 * The denylist could never GRANT anything. It returned a constant string; it
 * set no customer id, enabled no tool, altered no identity. So it was never
 * an authorization mechanism, and removing it takes nothing away from the
 * boundary. What it could do was DENY — and that turned out to be the defect:
 *
 *   /what is .{0,30}(password|api.?key|secret|token)/i
 *
 * matches "what is my password for the customer portal?", which is a
 * completely ordinary question from a paying customer. They received a canned
 * brush-off instead of help. Likewise /your instructions?/ matches "what are
 * your instructions for installation?".
 *
 * A denylist that fires on a paraphrase is weak; one that fires on a real
 * customer is worse than weak, because the failure is invisible — the
 * customer simply goes away.
 *
 * ── SO IT SIGNALS, AND NOTHING ELSE ─────────────────────────────────────
 *
 * assess() returns metadata. It has no side effects, touches no store, and
 * returns no decision. It cannot grant access, grant a tool, identify a
 * customer, or cause one to be denied service. Whether a message is answered
 * is decided by the pipeline that was built for that:
 *
 *   CustomerIdentity   who this is, exactly or not at all
 *   AiMinimalContext   what the model starts with — nothing of theirs
 *   CustomerDataTools  what it may fetch, with the id supplied by the server
 *   ReplyPrivacyGuard  what may leave
 *
 * Every one of those holds when this class returns risk => false. That is the
 * property: a miss here costs visibility, never containment.
 *
 * ── IT READS EVERY MODALITY ─────────────────────────────────────────────
 *
 * The old check saw only the typed message. A caption, a voice transcript or
 * the text inside a PDF went past it unread. assess() takes content and an
 * origin, so the same rules apply to a caption today and to a transcript or
 * an extracted document tomorrow — all of it customer-controlled, none of it
 * an instruction.
 */
final class PromptRiskSignal
{
    public const NONE   = 'none';
    public const LOW    = 'low';
    public const MEDIUM = 'medium';
    public const HIGH   = 'high';

    /**
     * The original patterns, kept rather than rewritten.
     *
     * They were not removed blindly: as a signal they are still worth having,
     * and changing them in the same step as changing what they DO would make
     * it impossible to tell which change caused what.
     */
    private const RULES = [
        ['id' => 'override_instructions', 'severity' => self::HIGH,
         're' => '/ignore (previous|all|your) (instructions?|rules?|prompt)/i'],
        ['id' => 'forget_instructions',   'severity' => self::HIGH,
         're' => '/forget (everything|instructions?|rules?|your training)/i'],
        ['id' => 'role_change',           'severity' => self::HIGH,
         're' => '/you are now|pretend (you are|to be)|act as (admin|system|root)/i'],
        ['id' => 'enumerate_customers',   'severity' => self::HIGH,
         're' => '/show (me )?(all |every |other )?customer(s| data| record| list)/i'],
        ['id' => 'request_customers',     'severity' => self::HIGH,
         're' => '/give me (all |every |other )?customer/i'],
        ['id' => 'extract_data',          'severity' => self::MEDIUM,
         're' => '/reveal|expose|dump|extract.{0,20}(data|record|customer|account)/i'],
        ['id' => 'disable_controls',      'severity' => self::HIGH,
         're' => '/override|bypass|disable|unlock (the )?(filter|rule|restriction)/i'],
        ['id' => 'jailbreak',             'severity' => self::HIGH,
         're' => '/DAN|jailbreak|unrestricted mode/i'],
        // Downgraded to LOW deliberately. These two matched real customer
        // questions -- "what is my password for the portal?" and "what are
        // your instructions for installation?" -- so as a BLOCK they were a
        // liability. As a signal they are still mildly interesting.
        ['id' => 'asks_about_secret',     'severity' => self::LOW,
         're' => '/what is .{0,30}(password|api.?key|secret|token)/i'],
        ['id' => 'asks_about_prompt',     'severity' => self::LOW,
         're' => '/system prompt|your instructions?|your (rule|prompt|training)/i'],
        // Claimed authority. Worth noticing precisely because it is the shape
        // of an attack that a denylist cannot be trusted to catch.
        ['id' => 'claimed_authority',     'severity' => self::MEDIUM,
         're' => '/\b(?:the )?(?:system )?admin(?:istrator)?\s+(?:has\s+)?(?:authoriz|approv|permit)/i'],
    ];

    /**
     * Look at one piece of customer-controlled content.
     *
     * @param string $content the customer's own words, from anywhere
     * @param string $origin  'text', 'caption', 'transcript', 'document', 'image'
     * @return array{risk:bool, severity:string, categories:array<int,string>, origin:string}
     */
    public static function assess(string $content, string $origin = 'text'): array
    {
        $cats = [];
        $worst = self::NONE;
        $rank = [self::NONE => 0, self::LOW => 1, self::MEDIUM => 2, self::HIGH => 3];

        foreach (self::RULES as $rule) {
            if (preg_match($rule['re'], $content) !== 1) continue;
            $cats[] = $rule['id'];
            if ($rank[$rule['severity']] > $rank[$worst]) $worst = $rule['severity'];
        }

        return [
            'risk'       => $cats !== [],
            'severity'   => $worst,
            'categories' => $cats,
            'origin'     => $origin,
        ];
    }

    /**
     * Assess every piece of customer content in one message at once.
     *
     * Text and caption today; transcript, document text and image description
     * when those phases land. The point of taking a map is that adding a
     * modality is adding a key, not finding every call site again.
     *
     * @param array<string,string> $parts origin => content
     */
    public static function assessAll(array $parts): array
    {
        $cats = []; $worst = self::NONE; $origins = [];
        $rank = [self::NONE => 0, self::LOW => 1, self::MEDIUM => 2, self::HIGH => 3];

        foreach ($parts as $origin => $content) {
            $content = trim((string)$content);
            if ($content === '') continue;
            $r = self::assess($content, (string)$origin);
            if (!$r['risk']) continue;
            $cats = array_merge($cats, $r['categories']);
            $origins[] = (string)$origin;
            if ($rank[$r['severity']] > $rank[$worst]) $worst = $r['severity'];
        }

        return [
            'risk'       => $cats !== [],
            'severity'   => $worst,
            'categories' => array_values(array_unique($cats)),
            'origins'    => array_values(array_unique($origins)),
        ];
    }

    /**
     * The audit record. Metadata about the signal, never the content.
     *
     * The message itself is already stored in the conversation, where it
     * belongs and where access to it is controlled. Copying it into a security
     * log as well would put customer words somewhere with different handling
     * for no investigative gain: the categories say what was noticed, and the
     * conversation id says where to read it.
     *
     * @return array<string,mixed>
     */
    public static function auditEvent(array $signal, array $meta = []): array
    {
        return [
            'event'           => 'prompt_risk_signal',
            'at'              => gmdate('c'),
            'severity'        => (string)($signal['severity'] ?? self::NONE),
            'categories'      => array_values((array)($signal['categories'] ?? [])),
            'origins'         => array_values((array)($signal['origins'] ?? [])),
            'customer_id'     => (int)($meta['customer_id'] ?? 0),
            'conversation_id' => (int)($meta['conversation_id'] ?? 0),
            'message_id'      => (string)($meta['message_id'] ?? ''),
            'channel'         => (string)($meta['channel'] ?? ''),
            'source'          => (string)($meta['source'] ?? ''),
            // Stated explicitly so nobody reading this log later mistakes it
            // for an enforcement record.
            'action_taken'    => 'none — signal only, message handled normally',
        ];
    }
}

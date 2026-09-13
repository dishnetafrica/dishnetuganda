<?php
declare(strict_types=1);

require_once __DIR__ . '/CustomerIdentity.php';

/**
 * AiMinimalContext — what the model is told before it has asked for anything.
 *
 * What this replaces: WaAutoReplyService assembled TWENTY-SIX context keys and
 * pushed them into the system prompt on every message, whatever was asked.
 * Balance, currency, last payment, plan, expiry, service id, address, open
 * ticket count, the latest ticket's title — and, from Splynx, the assigned IP,
 * the MAC address, the NAS identifier, the session IP, session start, bytes up
 * and down, and committed speeds. A customer saying "hi" had their balance and
 * their router's MAC address sent to an AI provider.
 *
 * The architecture that produced that is:
 *
 *     send everything  →  tell the model not to reveal it
 *
 * which makes the model the security boundary. This is the other one:
 *
 *     send nothing  →  the model asks  →  the tool authorizes and returns
 *                      the few fields that answer the question
 *
 * ── THE ALLOWLIST IS THE POINT ──────────────────────────────────────────
 *
 * build() cannot return a field that is not in KEYS. It is an allowlist, not
 * a list of things to strip, so a future edit that adds a field to the
 * assembly does not silently add it to the prompt: it has to be added here,
 * deliberately, in a file whose whole subject is what the model may know
 * before it has asked.
 *
 * ── WHY A NAME IS IN IT ─────────────────────────────────────────────────
 *
 * `name` is the one identity-derived field, and it is a deliberate choice
 * rather than an oversight. An assistant that cannot address a customer by
 * name reads as a robot, the customer already knows their own name, and it
 * discloses nothing they did not tell us. Every other identity-derived
 * field — anything about money, service, network or support — is not here and
 * must come through CustomerDataTools.
 */
final class AiMinimalContext
{
    /** The complete set of keys the model may receive before any tool call. */
    public const KEYS = ['identified', 'name', 'channel'];

    /**
     * @param array  $identity exactly what CustomerIdentity::resolve() returned
     * @param string $channel  'support' or 'accounts'
     * @return array{identified:bool, name:string, channel:string}
     */
    public static function build(array $identity, string $channel = 'support'): array
    {
        $identified = (($identity['status'] ?? '') === CustomerIdentity::IDENTIFIED)
                   && (int)($identity['client_id'] ?? 0) > 0;

        $name = '';
        if ($identified) {
            $c = is_array($identity['client'] ?? null) ? $identity['client'] : [];
            $n = trim((string)($c['firstName'] ?? ''));
            if ($n === '') {
                $n = trim((string)($c['name'] ?? $c['companyName'] ?? ''));
                // A full name is fine to say; anything longer is a record, not
                // a greeting, so it is trimmed to its first word.
                if ($n !== '' && str_word_count($n) > 3) $n = strtok($n, ' ') ?: '';
            }
            $name = $n;
        }

        return [
            'identified' => $identified,
            'name'       => $name,
            'channel'    => $channel === 'accounts' ? 'accounts' : 'support',
        ];
    }

    /**
     * Whether a context carries anything it should not.
     *
     * Used by tests and by the client itself, so that a context assembled
     * somewhere else — a future caller, a merge that reintroduces the old
     * block — cannot reach the model carrying customer data.
     *
     * @return array<int,string> the offending keys, empty when clean
     */
    public static function violations(array $ctx): array
    {
        $bad = [];
        foreach (array_keys($ctx) as $k) {
            if (!in_array((string)$k, self::KEYS, true)) $bad[] = (string)$k;
        }
        return $bad;
    }

    /**
     * Reduce any context to the permitted keys.
     *
     * Belt and braces for a caller that has not been updated: it cannot pass
     * customer data through simply by building its own array.
     */
    public static function enforce(array $ctx): array
    {
        $out = [];
        foreach (self::KEYS as $k) {
            if (array_key_exists($k, $ctx)) $out[$k] = $ctx[$k];
        }
        $out['identified'] = (bool)($out['identified'] ?? false);
        $out['name']       = (string)($out['name'] ?? '');
        $out['channel']    = (string)($out['channel'] ?? 'support');
        return $out;
    }
}

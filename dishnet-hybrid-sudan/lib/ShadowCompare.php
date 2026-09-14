<?php
declare(strict_types=1);

require_once __DIR__ . '/CustomerDataTools.php';

/**
 * ShadowCompare — does the tool layer answer what the prompt already states?
 *
 * B3.5 will take the customer's balance, invoice, payment, plan and service
 * status out of the prompt and let the model ask for them instead. That is a
 * good trade only if the answers are the same. This runs both readers against
 * the same customer on the same turn, compares them, and tells us — while the
 * customer still gets the legacy answer.
 *
 * Accounts is the reason this exists. It is the live money path, and a silent
 * divergence there does not produce a worse reply, it produces a confidently
 * wrong one: a customer in credit told they owe.
 *
 * ── IT COMPARES, IT DOES NOT DISCLOSE ───────────────────────────────────
 *
 * Nothing this class returns is a customer value. Not the balance, not the
 * invoice number, not the amount, not a date. The return is verdicts and
 * reason codes drawn from the fixed vocabularies below, and it is built the
 * way BrainContext and ShopBotPayload are built: constructed from constants,
 * never copied from data and filtered.
 *
 * That is a deliberate refusal, not an oversight. Logging what the two
 * readers said would create a second at-rest copy of every customer's
 * financial position, written on every message, in a log file with none of
 * the protections the database has — a migration instrument that is itself a
 * larger disclosure than the one being migrated away from. The conversation
 * id is already in every line the worker writes and is usually enough to find
 * the case; a person with database access can then look, under the access
 * controls that exist for that.
 *
 * So the strongest statement the log can make is "these two disagree about
 * the invoice number on conversation 412", and that is exactly what it makes.
 *
 * ── SCOPED TO WHAT THE CHANNEL ACTUALLY CARRIES ─────────────────────────
 *
 * Support renders services; accounts renders money. Comparing accounts facts
 * on a support turn would report a divergence on every single message, which
 * is how a warning becomes wallpaper. Each channel compares the facts its own
 * prompt states today, and runs only the tools those facts need — so the
 * shadow costs about one extra read per fact, not ten.
 *
 * ── WHAT IT WILL FIND, AND WHY THAT IS THE POINT ────────────────────────
 *
 * The two readers do NOT ask uCRM the same questions, and this was found by
 * reading them rather than by running them:
 *
 *   invoice   legacy asks for the unpaid set first (statuses 1,2 limit 1) and
 *             falls back to the most recent; the tool asks for the most recent
 *             five and takes the first. A customer whose newest invoice is
 *             paid gets a different invoice from each.
 *
 *   number    legacy reads $i['invoiceNumber']. Every other uCRM invoice
 *             reader in this plugin — EfrisService, CustomerAccountService,
 *             DpoPaymentService, OverdueWorkbenchService, WaAutoReplyService —
 *             reads $i['number']. So the legacy accounts prompt has been
 *             rendering "Latest invoice: no number" all along. Expect
 *             invoice=differ:number on every accounts turn; it is the legacy
 *             side that is wrong, and the one-line fix is not made here
 *             because correcting the thing you are about to measure is not a
 *             measurement.
 *
 *   amount    legacy reads $i['amountToPay']; the tool computes total less
 *             amountPaid. Both are real uCRM fields and they usually agree.
 *             Usually is what a shadow is for.
 *
 *   plan name legacy prefers $s['name'] then servicePlanName; the gateway
 *             prefers servicePlanName then name. Opposite precedence, same
 *             row.
 *
 *   status    not compared. The legacy prompt renders uCRM's raw integer into
 *             the text and leaves the model to interpret it, so there is no
 *             legacy FACT to compare a word against. What is reported instead
 *             is unmapped_status: the tool returning 'unknown' means live uCRM
 *             is using a status code outside the confirmed set, which is worth
 *             knowing on its own.
 */
final class ShadowCompare
{
    /** The five facts B3.5 moves from the prompt to a tool. */
    public const FACTS = ['balance', 'invoice', 'payment', 'plan', 'service_status'];

    /**
     * What each channel's prompt states TODAY — so what there is to compare.
     *
     * Taken from AiReplyWorker::buildContext(), not invented: support loads
     * services, accounts loads the account. A channel not listed here has no
     * customer data in its prompt and nothing to shadow.
     */
    public const CHANNEL_FACTS = [
        'account' => ['balance', 'invoice', 'payment'],
        'support' => ['plan', 'service_status'],
    ];

    /** Which brain tool answers each fact. All five are in BRAIN_TOOLS. */
    public const TOOL_FOR = [
        'balance'        => 'get_my_balance',
        'invoice'        => 'get_my_latest_invoice',
        'payment'        => 'get_my_last_payment',
        'plan'           => 'get_my_plan',
        'service_status' => 'get_my_service_status',
    ];

    /**
     * same        both readers state it, and they agree
     * differ      both state it, and they do not
     * legacy_only the prompt states it today; the tool would say nothing
     * tool_only   the tool has it; the prompt states nothing today
     * absent      neither has it — a customer with no invoices, say
     * error       the tool could not be asked
     */
    public const VERDICTS = ['same', 'differ', 'legacy_only', 'tool_only', 'absent', 'error'];

    /**
     * Why they differ. Field names, plus two structural ones.
     *
     * 'sign' is first because it is the dangerous one: the two readers
     * disagree about whether the customer owes or is owed.
     */
    public const REASONS = ['sign', 'amount', 'number', 'due_date', 'date',
                            'count', 'name', 'active_to',
                            'unmapped_status', 'tool_error'];

    /** The tool's word for a uCRM status code it has never been shown. */
    private const UNMAPPED = 'unknown';

    /**
     * Compare one turn.
     *
     * @param string $channel  the conversation's channel
     * @param array  $legacy   the legacy context, exactly as dataBlock() reads it
     * @param CustomerDataTools $tools  built for THIS customer, and not shared
     *        with any live caller — a tool call records what it disclosed, and
     *        the shadow's disclosures must never widen a guard allowlist that
     *        a real reply is checked against.
     *
     * @return array{channel:string, facts:array<string,array{verdict:string,why:string}>,
     *               divergent:array<int,string>, line:string}
     *         Verdicts and reason codes only. No customer value, at any depth.
     */
    public static function compare(string $channel, array $legacy, CustomerDataTools $tools): array
    {
        $chan  = isset(self::CHANNEL_FACTS[$channel]) ? $channel : 'other';
        $facts = [];

        foreach (self::CHANNEL_FACTS[$chan] ?? [] as $fact) {
            $facts[$fact] = self::one($fact, $legacy, $tools);
        }

        $divergent = [];
        foreach ($facts as $name => $f) {
            if ($f['verdict'] !== 'same' && $f['verdict'] !== 'absent') $divergent[] = $name;
        }

        return ['channel'   => $chan,
                'facts'     => $facts,
                'divergent' => $divergent,
                'line'      => self::line($chan, $facts)];
    }

    /**
     * One line, assembled from constants.
     *
     * Every token is a channel name, a fact name, a verdict or a reason, and
     * all four come from the constants above. There is no path by which a
     * value reaches this string, which is what makes it safe to log without
     * anyone having to remember that it is.
     */
    private static function line(string $chan, array $facts): string
    {
        $parts = [$chan];
        foreach ($facts as $name => $f) {
            $parts[] = $name . '=' . $f['verdict'] . ($f['why'] !== '' ? ':' . $f['why'] : '');
        }
        return implode(' ', $parts);
    }

    // ── One fact ────────────────────────────────────────────────────────────

    private static function one(string $fact, array $legacy, CustomerDataTools $tools): array
    {
        $r = $tools->call(self::TOOL_FOR[$fact]);

        if (!$r['ok'] && ($r['error'] ?? '') !== 'not found') {
            return self::verdict('error', ['tool_error']);
        }
        $new = $r['ok'] ? ($r['data'] ?? null) : null;
        $old = self::legacyFact($fact, $legacy);

        if ($old === null && $new === null) return self::verdict('absent', []);
        if ($old === null)                  return self::verdict('tool_only', []);
        if ($new === null)                  return self::verdict('legacy_only', []);

        $why = self::why($fact, $old, $new);
        return $why === [] ? self::verdict('same', []) : self::verdict('differ', $why);
    }

    /** @param array<int,string> $why */
    private static function verdict(string $v, array $why): array
    {
        // Constructed, like everything else here: a verdict outside the
        // vocabulary cannot be returned, and neither can a reason.
        $v = in_array($v, self::VERDICTS, true) ? $v : 'error';
        $ok = [];
        foreach ($why as $w) if (in_array($w, self::REASONS, true)) $ok[] = $w;
        return ['verdict' => $v, 'why' => implode('+', $ok)];
    }

    /**
     * What the legacy context holds for this fact, or null.
     *
     * These are values, and they stay inside this class.
     */
    private static function legacyFact(string $fact, array $legacy)
    {
        $acct = is_array($legacy['account'] ?? null) ? $legacy['account'] : null;
        $svcs = self::legacyServices($legacy);

        switch ($fact) {
            case 'balance':
                if ($acct === null || !array_key_exists('balance', $acct)) return null;
                return ['amount' => (float)$acct['balance']];

            case 'invoice':
                $i = is_array($acct['invoice'] ?? null) ? $acct['invoice'] : null;
                if ($i === null || $i === []) return null;
                return ['number'     => trim((string)($i['number'] ?? '')),
                        'amount_due' => self::floatOrNull($i['amount_due'] ?? null),
                        'due_date'   => trim((string)($i['due_date'] ?? ''))];

            case 'payment':
                $p = is_array($acct['last_payment'] ?? null) ? $acct['last_payment'] : null;
                if ($p === null || $p === []) return null;
                return ['amount' => self::floatOrNull($p['amount'] ?? null),
                        'date'   => trim((string)($p['date'] ?? ''))];

            case 'plan':
                if ($svcs === null) return null;
                $names = [];
                foreach ($svcs as $s) {
                    // The legacy prompt's own precedence: name, then plan_name.
                    $names[] = trim((string)($s['name'] ?? ($s['plan_name'] ?? '')));
                }
                return ['names' => $names];

            case 'service_status':
                if ($svcs === null) return null;
                $to = [];
                foreach ($svcs as $s) $to[] = trim((string)($s['active_to'] ?? ''));
                return ['active_to' => $to];
        }
        return null;
    }

    /** @return array<int,array<string,mixed>>|null */
    private static function legacyServices(array $legacy): ?array
    {
        $s = $legacy['services'] ?? null;
        if (!is_array($s) || $s === []) return null;
        $out = [];
        foreach ($s as $row) if (is_array($row)) $out[] = $row;
        return $out === [] ? null : $out;
    }

    /**
     * Where two present facts disagree.
     *
     * @return array<int,string> reason codes, empty when they agree
     */
    private static function why(string $fact, array $old, array $new): array
    {
        switch ($fact) {
            case 'balance':
                $a = (float)$old['amount'];
                $b = (float)($new['amount'] ?? 0);
                // Which way round it is, before how much — a sign disagreement
                // is the one that tells a customer in credit they owe money.
                if (self::state($a) !== self::state($b)) return ['sign'];
                return self::money($a, $b) ? [] : ['amount'];

            case 'invoice':
                $w = [];
                if ($old['number'] !== trim((string)($new['number'] ?? ''))) $w[] = 'number';
                if (!self::money($old['amount_due'], self::floatOrNull($new['amount_due'] ?? null))) $w[] = 'amount';
                if ($old['due_date'] !== trim((string)($new['due_date'] ?? ''))) $w[] = 'due_date';
                return $w;

            case 'payment':
                $w = [];
                if (!self::money($old['amount'], self::floatOrNull($new['amount'] ?? null))) $w[] = 'amount';
                if ($old['date'] !== trim((string)($new['date'] ?? ''))) $w[] = 'date';
                return $w;

            case 'plan':
                $names = [];
                foreach (self::rows($new['services'] ?? null) as $s) {
                    $names[] = trim((string)($s['name'] ?? ''));
                }
                if (count($names) !== count($old['names'])) return ['count'];
                return $names === $old['names'] ? [] : ['name'];

            case 'service_status':
                $to = $un = [];
                foreach (self::rows($new['services'] ?? null) as $s) {
                    $to[] = trim((string)($s['active_to'] ?? ''));
                    if ((string)($s['status'] ?? '') === self::UNMAPPED) $un[] = 1;
                }
                $w = [];
                if (count($to) !== count($old['active_to'])) $w[] = 'count';
                elseif ($to !== $old['active_to'])           $w[] = 'active_to';
                // Reported whether or not the dates agree: a status code uCRM
                // has started using and this plugin has never seen is a finding
                // in its own right.
                if ($un !== []) $w[] = 'unmapped_status';
                return $w;
        }
        return [];
    }

    /** Both absent is agreement; one absent is not. */
    private static function money(?float $a, ?float $b): bool
    {
        if ($a === null && $b === null) return true;
        if ($a === null || $b === null) return false;
        return round($a, 2) === round($b, 2);
    }

    /** The same three buckets get_my_balance() derives, on the same thresholds. */
    private static function state(float $a): string
    {
        return $a > 0.01 ? 'owed' : ($a < -0.01 ? 'credit' : 'clear');
    }

    private static function floatOrNull($v): ?float
    {
        if ($v === null || $v === '') return null;
        return (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) ? (float)$v : null;
    }

    /** @return array<int,array<string,mixed>> */
    private static function rows($v): array
    {
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $row) if (is_array($row)) $out[] = $row;
        return $out;
    }
}

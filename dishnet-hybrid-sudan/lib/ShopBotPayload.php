<?php
declare(strict_types=1);

/**
 * ShopBotPayload — what may leave this process for an external brain.
 *
 * AiReplyWorker can be pointed at an external AI service instead of the
 * in-process DishNetAiBrain (config shopbot_ai_url). That seam was written as
 * a clean architectural choice and it is one, but it did this:
 *
 *     CURLOPT_POSTFIELDS => json_encode($context)
 *
 * The whole context. And the context is not what the prompt renders. It
 * carries, on every channel where a phone number matched a customer:
 *
 *     $ctx['customer']['_raw']   the COMPLETE uCRM client record
 *     $ctx['customer']['id']     the internal uCRM client id
 *     $ctx['customer']['balance'] their balance — on the SALES channel too,
 *                                where the design says a balance is never
 *                                needed and dataBlock() never renders one
 *     $ctx['line_status']['splynx_id'], ['service_address']
 *     $ctx['services'][n]['_raw'], ['id'], ['plan_id']
 *     $ctx['account']['invoice']['_raw'], $ctx['account']['last_payment']['_raw']
 *     $ctx['customer_phone'], $ctx['whatsapp_instance']
 *
 * None of that is read by DishNetAiBrain. It reached a third party anyway,
 * outside the prompt, outside the tool layer, outside the output guard —
 * every control this plugin has, bypassed by one config value.
 *
 * ── HOW THE CONTRACT IS DERIVED ─────────────────────────────────────────
 *
 * Not by judgement. The external service is documented as interchangeable
 * with DishNetAiBrain::reply(), so what it can possibly need is exactly what
 * the brain itself reads out of the context — no more, because an
 * implementation of the same contract cannot need a field the reference
 * implementation never looks at.
 *
 * That list is enumerable: sixteen top-level keys, each projected down to the
 * leaves the brain actually reads.
 *
 * ── DEFAULT DENY ────────────────────────────────────────────────────────
 *
 * project() builds a NEW array from a fixed shape. It never copies the input
 * and unsets what it dislikes, because that is the version that leaks the day
 * somebody adds a field upstream. A key that is not written here does not
 * travel, and a key added to the context next year does not travel either
 * until a person adds it below and a reviewer sees them do it.
 */
final class ShopBotPayload
{
    /**
     * The outbound contract, as documentation and as a test fixture.
     *
     * Each entry is a top-level key and the leaves that survive under it.
     * A '*' means the scalar itself travels.
     */
    public const CONTRACT = [
        // The backend's answer about WHO this is — identified, anonymous,
        // unknown or ambiguous. Never a customer id: it describes the
        // authorisation posture, not the customer.
        'identity_state'     => ['*'],
        'channel'            => ['*'],
        'transport'          => ['*'],
        'medium'             => ['*'],
        'message'            => ['*'],
        'identity_ambiguous' => ['*'],
        'customer'           => ['name', 'is_lead'],
        'products'           => ['products.name', 'products.price', 'products.period_months',
                                 'products.download_speed', 'products.upload_speed',
                                 'products.data_limit', 'hardware.name', 'hardware.price'],
        'services'           => ['name', 'plan_name', 'status', 'active_to'],
        'account'            => ['balance', 'invoice.number', 'invoice.amount_due',
                                 'invoice.due_date', 'last_payment.amount', 'last_payment.date'],
        'history'            => ['role', 'text'],
        'thread'             => ['*'],
        'attachments'        => ['*'],
        'signature'          => ['*'],
        'constraints'        => ['*'],
    ];

    /**
     * Named so a reviewer can see what was decided, and why each one is a
     * decision rather than an oversight.
     */
    public const NEVER_SENT = [
        '_raw'              => 'the complete uCRM or Splynx record behind a normalised field',
        'customer.id'       => 'internal uCRM client id — an identifier for OUR system, not a fact about the customer',
        'customer.balance'  => 'a balance that bypasses the accounts-channel gate; account.balance is the gated one',
        'customer.is_active'=> 'never read by the brain, never rendered',
        'account.client_id' => 'internal uCRM client id',
        'account.name'      => 'already travelling as customer.name',
        'account.owes'      => 'derivable from balance; two sources of one truth',
        'account.in_credit' => 'likewise',
        'invoice.id'        => 'internal uCRM invoice id — invoice.number is the customer-facing one',
        'invoice.total'     => 'not read by the brain; amount_due is what is answered with',
        'invoice.created'   => 'not read by the brain',
        'invoice.status'    => 'not read by the brain',
        'services.id'       => 'internal uCRM service id',
        'services.plan_id'  => 'internal uCRM plan id',
        'line_status.splynx_id'       => 'internal Splynx customer id',
        'line_status.customer_name'   => 'already travelling as customer.name',
        'line_status.service_address' => 'where the customer lives — operational data with no use in a reply',
        'customer_phone'    => 'the customer identifies themselves to US; a third party does not need the number',
        'whatsapp_instance' => 'our own infrastructure identifier',
        'conversation_id'   => 'our own database key',
        'line_status'       => 'Splynx is the South Sudan fibre stack; Uganda is Starlink-only, '
                             . 'so this concept no longer exists in the Uganda contract at all',
        'webchat_lead'      => 'a website visitor\'s typed name and topic — untrusted content that '
                             . 'read like identity. Removed in B3.2 rather than renamed.',
        'push_name'         => 'never read by the brain',
    ];

    /**
     * Project a context down to the outbound contract.
     *
     * @param array $ctx the internal context, whatever it happens to contain
     * @return array     only what CONTRACT allows
     */
    public static function project(array $ctx): array
    {
        $out = [];

        $state = self::str($ctx['identity_state'] ?? null);
        if ($state !== '') $out['identity_state'] = $state;

        foreach (['channel', 'transport', 'medium', 'message', 'thread', 'signature'] as $k) {
            $v = self::str($ctx[$k] ?? null);
            if ($v !== '') $out[$k] = $v;
        }
        if (!empty($ctx['identity_ambiguous'])) {
            $out['identity_ambiguous'] = true;
        }

        // Who they are: the greeting and the posture, and nothing else. The
        // brain reads exactly these two.
        if (!empty($ctx['customer']) && is_array($ctx['customer'])) {
            $c = $ctx['customer'];
            $out['customer'] = [
                'name'    => self::str($c['name'] ?? null),
                'is_lead' => !empty($c['is_lead']),
            ];
        }

        // Public catalogue. Not customer data — the same for every visitor,
        // and the one thing the model cannot answer a sales question without.
        $prod = $ctx['products'] ?? null;
        if (is_array($prod)) {
            $plans = [];
            foreach (self::rows($prod['products'] ?? null) as $p) {
                $plans[] = [
                    'name'           => self::str($p['name'] ?? null),
                    'price'          => self::num($p['price'] ?? null),
                    'period_months'  => self::int($p['period_months'] ?? null),
                    'download_speed' => self::str($p['download_speed'] ?? null),
                    'upload_speed'   => self::str($p['upload_speed'] ?? null),
                    'data_limit'     => self::str($p['data_limit'] ?? null),
                ];
            }
            $hw = [];
            foreach (self::rows($prod['hardware'] ?? null) as $h) {
                $hw[] = ['name' => self::str($h['name'] ?? null), 'price' => self::num($h['price'] ?? null)];
            }
            if ($plans || $hw) $out['products'] = ['products' => $plans, 'hardware' => $hw];
        }

        // Their services: what the plan is called and whether it is running.
        $svcs = [];
        foreach (self::rows($ctx['services'] ?? null) as $s) {
            $svcs[] = [
                'name'      => self::str($s['name'] ?? null),
                'plan_name' => self::str($s['plan_name'] ?? null),
                'status'    => self::str($s['status'] ?? null),
                'active_to' => self::str($s['active_to'] ?? null),
            ];
        }
        if ($svcs) $out['services'] = $svcs;

        // Money. Only the six leaves the brain renders.
        $acct = $ctx['account'] ?? null;
        if (is_array($acct) && $acct) {
            $a = [];
            if (isset($acct['balance'])) $a['balance'] = self::num($acct['balance']);
            $inv = $acct['invoice'] ?? null;
            if (is_array($inv) && $inv) {
                $a['invoice'] = [
                    'number'     => self::str($inv['number'] ?? null),
                    'amount_due' => self::num($inv['amount_due'] ?? null),
                    'due_date'   => self::str($inv['due_date'] ?? null),
                ];
            }
            $pay = $acct['last_payment'] ?? null;
            if (is_array($pay) && $pay) {
                $a['last_payment'] = [
                    'amount' => self::num($pay['amount'] ?? null),
                    'date'   => self::str($pay['date'] ?? null),
                ];
            }
            if ($a) $out['account'] = $a;
        }

        // The conversation. Unchanged in content — what history carries is a
        // separate question, and a large one, but it is not this commit's.
        $hist = [];
        foreach (self::rows($ctx['history'] ?? null) as $h) {
            $text = self::str($h['text'] ?? null);
            if ($text === '') continue;
            $hist[] = ['role' => self::str($h['role'] ?? null) ?: 'customer', 'text' => $text];
        }
        if ($hist) $out['history'] = $hist;

        foreach (['attachments', 'constraints'] as $k) {
            $list = [];
            foreach (self::rows($ctx[$k] ?? null) as $v) {
                $s = self::str($v);
                if ($s !== '') $list[] = $s;
            }
            if ($list) $out[$k] = $list;
        }

        return $out;
    }

    /**
     * Which top-level context keys this projection dropped.
     *
     * Key names only — the values are the thing we are refusing to send, so
     * they have no business in a log line about not sending them.
     *
     * @return string[]
     */
    public static function dropped(array $ctx): array
    {
        $kept = array_keys(self::project($ctx));
        $out  = [];
        foreach (array_keys($ctx) as $k) {
            if (!in_array((string)$k, $kept, true)) $out[] = (string)$k;
        }
        sort($out);
        return $out;
    }

    /** A list, or nothing. Never a scalar pretending to be one. */
    private static function rows($v): array
    {
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $row) if (is_array($row) || is_scalar($row)) $out[] = $row;
        return $out;
    }

    /** A string, or ''. An array never becomes "Array" and travels. */
    private static function str($v): string
    {
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string)$v;
        if (is_bool($v)) return $v ? '1' : '';
        return '';
    }

    private static function num($v): ?float
    {
        return (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) ? (float)$v : null;
    }

    private static function int($v): ?int
    {
        return (is_int($v) || (is_string($v) && ctype_digit($v))) ? (int)$v : null;
    }
}

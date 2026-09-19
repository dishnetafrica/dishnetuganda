<?php
declare(strict_types=1);

require_once __DIR__ . '/ConversationService.php';

/**
 * BrainContext — the only things DishNetAiBrain is allowed to be told.
 *
 * The brain used to receive whatever its caller happened to assemble, and
 * dataBlock() rendered a good deal of it into every prompt: the customer's
 * balance, their latest invoice, their last payment, their plan and expiry,
 * a Splynx line record — proactively, on every turn, whether or not the
 * question needed any of it. Alongside that, never rendered but present in
 * the object and therefore one json_encode from leaving the process: the
 * complete uCRM client record, internal ids, the Splynx id and service
 * address, the customer's phone number, our own WhatsApp instance.
 *
 * This is the allowlist that replaces it. Twelve keys, and every one earns
 * its place by deciding what the model DOES rather than by being available.
 *
 * ── CONSTRUCTED, NEVER FILTERED ─────────────────────────────────────────
 *
 * build() assembles a NEW array from a fixed shape. It does not copy the
 * input and remove what it dislikes, because that is the version that leaks
 * the day somebody adds a field upstream: a filter has to remember every bad
 * field forever, while a constructor only has to know the good ones. A key
 * not written below does not travel, and a key added to a caller next year
 * does not travel either until a person adds it here and a reviewer sees it.
 *
 * There is deliberately no 'extra', no 'metadata', no 'raw', no 'account'
 * and no 'internal' bucket. A generic passthrough is the same hole with a
 * friendlier name.
 *
 * ── UGANDA IS STARLINK-ONLY ─────────────────────────────────────────────
 *
 * There is no line_status, no Splynx identifier, no Splynx service record
 * and no service address, because this deployment sells Starlink and Splynx
 * is the South Sudan fibre stack. Carrying a fibre concept into a Starlink
 * brain so that one triage case keeps working is how an architecture becomes
 * everybody's infrastructure at once.
 *
 * This decision is UGANDA-SPECIFIC and must not be generalised. If a South
 * Sudan deployment needs fibre status, it gets a country- and service-scoped
 * capability with its own authoritative backend — not a shared field here.
 *
 * ── WHAT IS NOT HERE, AND WHERE IT WENT ─────────────────────────────────
 *
 * balance, invoice, last payment, plan, service status and expiry are the
 * customer's own and are answered on demand by the controlled tool layer,
 * never injected because they exist. That layer is B3.3; until it lands, the
 * two callers that need them stay on the legacy shape. See LEGACY below.
 */
final class BrainContext
{
    /**
     * The contract. Twelve top-level keys, and the only twelve.
     *
     * Each entry names the leaves that survive under it; '*' is a scalar
     * that travels as itself.
     */
    public const CONTRACT = [
        'identity_state' => ['*'],
        'customer'       => ['name', 'is_lead', 'has_service'],
        'channel'        => ['*'],
        'transport'      => ['*'],
        'medium'         => ['*'],
        'products'       => ['products.name', 'products.price', 'products.period_months',
                             'products.download_speed', 'products.upload_speed',
                             'products.data_limit', 'hardware.name', 'hardware.price', 'stock'],
        'message'        => ['*'],
        'history'        => ['role', 'text'],
        'thread'         => ['*'],
        'attachments'    => ['*'],
        'signature'      => ['*'],
        'constraints'    => ['*'],
    ];

    /**
     * Named so a reviewer sees a decision rather than an omission.
     */
    public const NEVER_PRESENT = [
        'account'        => 'balance, invoice and payment are answered by a tool, not injected',
        'invoice'        => 'likewise',
        'last_payment'   => 'likewise',
        'services'       => 'plan, status and expiry are answered by a tool',
        'line_status'    => 'Splynx is the South Sudan fibre stack; Uganda is Starlink-only',
        'splynx_id'      => 'internal Splynx identifier',
        'service_address'=> 'where the customer lives — operational data with no use in a reply',
        'customer_phone' => 'they identified themselves to US; the model does not need the number',
        'whatsapp_instance' => 'our own infrastructure identifier',
        'conversation_id'=> 'our own database key',
        'push_name'      => 'never read by the prompt',
        'webchat_lead'   => 'a name and topic typed by a website visitor: untrusted content that '
                          . 'read like identity, matched on trailing digits with no country check, '
                          . 'and bought a greeting. Removed rather than renamed.',
        '_raw'           => 'the complete upstream record behind a normalised field',
        'client_id'      => 'the model never selects a customer, so it never needs an id',
        'metadata'       => 'a generic passthrough is the same hole with a friendlier name',
        'extra'          => 'likewise',
        'internal'       => 'likewise',
    ];

    /** The four states, mirroring ConversationService so there is one vocabulary. */
    public const STATES = [
        ConversationService::STATE_IDENTIFIED,
        ConversationService::STATE_ANONYMOUS,
        ConversationService::STATE_UNKNOWN,
        ConversationService::STATE_AMBIGUOUS,
    ];

    /**
     * Build the context from named parts.
     *
     * @param string $identityState one of STATES — the backend's answer, never the model's
     * @param array  $in            candidate values; anything not named below is ignored
     */
    public static function build(string $identityState, array $in): array
    {
        $state = in_array($identityState, self::STATES, true)
            ? $identityState
            : ConversationService::STATE_UNKNOWN;

        $out = [
            'identity_state' => $state,
            'channel'        => self::str($in['channel'] ?? '') ?: 'support',
            'transport'      => self::str($in['transport'] ?? '') ?: 'whatsapp',
            'message'        => self::str($in['message'] ?? ''),
        ];
        // Conditional, like thread and signature below: '' and absent mean the
        // same thing to every reader, and an always-present empty string
        // disagreed with the egress projection, which drops empty scalars.
        $medium = self::str($in['medium'] ?? '');
        if ($medium !== '') $out['medium'] = $medium;

        // Who they are — the greeting and the posture, and ONLY when the
        // backend actually identified them. An anonymous visitor has no name
        // here even if they typed one somewhere: a name a stranger supplies
        // is content, not identity.
        if ($state === ConversationService::STATE_IDENTIFIED
            && is_array($in['customer'] ?? null) && $in['customer']) {
            $out['customer'] = [
                'name'    => self::str($in['customer']['name'] ?? ''),
                'is_lead' => !empty($in['customer']['is_lead']),
            ];
            // Whether they have a live service — only when the caller looked.
            // Absent means "not looked up", which the prompt must never read
            // as "no service": a customer created in billing five minutes ago,
            // mid-conversation, is a sign-up in progress, not a subscriber.
            if (array_key_exists('has_service', $in['customer'])) {
                $out['customer']['has_service'] = (bool)$in['customer']['has_service'];
            }
        }

        // The public catalogue. Not customer data — identical for every
        // visitor, and the one thing a sales question cannot be answered
        // without. Present in EVERY identity state, deliberately.
        $p = $in['products'] ?? null;
        if (is_array($p)) {
            $plans = [];
            foreach (self::rows($p['products'] ?? null) as $row) {
                $plans[] = [
                    'name'           => self::str($row['name'] ?? ''),
                    'price'          => self::num($row['price'] ?? null),
                    'period_months'  => self::int($row['period_months'] ?? null),
                    'download_speed' => self::str($row['download_speed'] ?? ''),
                    'upload_speed'   => self::str($row['upload_speed'] ?? ''),
                    'data_limit'     => self::str($row['data_limit'] ?? ''),
                ];
            }
            $hw = [];
            foreach (self::rows($p['hardware'] ?? null) as $row) {
                $hw[] = ['name' => self::str($row['name'] ?? ''),
                         'price' => self::num($row['price'] ?? null)];
            }
            $stock = self::str($p['stock'] ?? '');
            if ($plans || $hw || $stock !== '') {
                $out['products'] = ['products' => $plans, 'hardware' => $hw, 'stock' => $stock];
            }
        }

        // The conversation so far. Supplied by the caller from the B3.1
        // identity-bound read and nothing else — this does not fetch, because
        // a context builder that could reach the message table would be a
        // second way to get history without an identity.
        $hist = [];
        foreach (self::rows($in['history'] ?? null) as $h) {
            $text = self::str($h['text'] ?? '');
            if ($text === '') continue;
            $hist[] = ['role' => self::str($h['role'] ?? '') === 'dishnet' ? 'dishnet' : 'customer',
                       'text' => $text];
        }
        if ($hist) $out['history'] = $hist;

        // Email only: what they quoted, what they attached, how we sign off,
        // and what a person has already decided this reply may not do.
        $thread = self::str($in['thread'] ?? '');
        if ($thread !== '') $out['thread'] = $thread;
        foreach (['attachments', 'constraints'] as $k) {
            $list = [];
            foreach (self::rows($in[$k] ?? null) as $v) {
                $s = self::str($v);
                if ($s !== '') $list[] = $s;
            }
            if ($list) $out[$k] = $list;
        }
        $sig = self::str($in['signature'] ?? '');
        if ($sig !== '') $out['signature'] = $sig;

        return $out;
    }

    /**
     * Is this array the contract, rather than a legacy context?
     *
     * identity_state is the discriminator because no legacy caller has ever
     * set it and none ever will: they are frozen. This is a MIGRATION marker
     * with a known end date, not a general "if the field is missing, fall
     * back" mechanism — that pattern is how an old shape quietly becomes a
     * supported API. When B3.5 migrates the last caller, the check and the
     * legacy branch go together.
     */
    public static function isContract(array $ctx): bool
    {
        return isset($ctx['identity_state'])
            && in_array((string)$ctx['identity_state'], self::STATES, true);
    }

    /**
     * ── LEGACY, AND WHY IT IS STILL HERE ────────────────────────────────
     *
     * Two callers still pass the old shape: WhatsApp support and WhatsApp
     * accounts. Their prompts carry the customer's plan, status, expiry,
     * balance, invoice and last payment, and the tool layer that will answer
     * those on demand does not exist yet (B3.3). Enforcing the contract on
     * them today would not make them safer — it would make them answer "I
     * will check that for you" to a customer asking what they owe, which is
     * a functionality regression bought with no security gain, in the middle
     * of a security migration.
     *
     * So this is migration debt, carried deliberately and on a schedule:
     *
     *     B3.3  controlled customer tools
     *     B3.4  shadow comparison, accounts especially — it is the money path
     *     B3.5  support migrates, then accounts, last
     *
     * It is NOT a second supported architecture. No new caller may adopt it.
     * The four already on the contract — WhatsApp sales, web chat, email,
     * and the synthetic callers — must not regress to it.
     */
    public const LEGACY_CALLERS = ['wa_support', 'wa_accounts'];

    private static function rows($v): array
    {
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $row) if (is_array($row) || is_scalar($row)) $out[] = $row;
        return $out;
    }

    private static function str($v): string
    {
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string)$v;
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

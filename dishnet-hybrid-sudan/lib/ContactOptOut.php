<?php
declare(strict_types=1);

/**
 * ContactOptOut — who has asked us to stop, and what that stops.
 *
 * This exists so that the moment the system can start a conversation, it can
 * also be told not to. Everything before it only ever replied, and a reply is
 * invited by definition; a follow-up is not.
 *
 * ── SCOPE ───────────────────────────────────────────────────────────────
 *
 * "Stop messaging me" almost never means "ignore me if I write to you". A
 * customer who opts out and then asks a question still deserves an answer —
 * refusing to reply would make the assistant look broken and would be worse
 * service than before we had any of this.
 *
 *   proactive   we never START a conversation. Replies still happen.
 *   all         nothing automatic at all, replies included.
 *
 * ── CLASS ───────────────────────────────────────────────────────────────
 *
 * Every send declares what kind of message it is, and the DEFAULT IS THE MOST
 * RESTRICTED ONE. A send site added later that forgets to classify itself is
 * treated as proactive and gets blocked by an opt-out — which is the right
 * direction to fail in. Forgetting to mark a marketing blast as marketing
 * should cost us a send, not cost a customer their choice.
 *
 *   reply          answering a message they just sent
 *   transactional  invoice, receipt, suspension notice — things they need
 *   proactive      follow-ups and anything else we start   (default)
 *   staff          our own team's alerts. Never blocked; not customer contact.
 */
final class ContactOptOut
{
    public const SCOPE_PROACTIVE = 'proactive';
    public const SCOPE_ALL       = 'all';

    public const CLASS_REPLY         = 'reply';
    public const CLASS_TRANSACTIONAL = 'transactional';
    public const CLASS_PROACTIVE     = 'proactive';
    public const CLASS_STAFF         = 'staff';

    /** Plain ways a person says stop. Matched as whole words on a short message. */
    public const STOP_WORDS = [
        'stop', 'unsubscribe', 'opt out', 'optout', 'opt-out',
        'remove me', 'delete me', 'leave me alone', 'do not contact',
        "don't contact", 'dont contact', 'do not message', "don't message",
        'dont message', 'no more messages', 'stop messaging', 'stop sending',
        'unsubscribe me', 'take me off',
    ];

    private \PDO $db;
    /** Per-instance, never static: one object's read must not answer another's. */
    private array $cache = [];

    public function __construct(\PDO $db) { $this->db = $db; }

    public static function fromStore($store): self { return new self($store->getPdo()); }

    /**
     * Resolve one from the plugin's own data directory, for callers that were
     * never given a store — the 27 places EvolutionApiService is constructed,
     * among others. Returns null when there is no database to ask, and the
     * caller must decide what that means; it is never treated as "no opt-out".
     */
    public static function resolve(?string $dataDir = null): ?self
    {
        $dir = $dataDir ?: (getenv('DN_DATA_DIR') ?: '');
        if ($dir === '') {
            $root = getenv('DN_PLUGIN_ROOT') ?: dirname(__DIR__);
            $dir  = dirname($root) . '/.' . basename($root) . '-data';
        }
        $file = rtrim($dir, '/') . '/plugin.sqlite3';
        if (!is_file($file)) return null;
        try {
            $pdo = new \PDO('sqlite:' . $file, null, null,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            return new self($pdo);
        } catch (\Throwable $e) { return null; }
    }

    /**
     * May we send this message?
     *
     * @return array{blocked:bool, reason:string, optout_id:int, scope:string}
     */
    public function blocks(string $phone, string $channel, string $class = self::CLASS_PROACTIVE): array
    {
        $none = ['blocked' => false, 'reason' => '', 'optout_id' => 0, 'scope' => ''];

        // Our own team is not a customer, and an alert about a customer must
        // never be suppressed by that customer's preference.
        if ($class === self::CLASS_STAFF) return $none;

        $row = $this->find($phone, $channel);
        if ($row === null) return $none;

        $scope = (string)$row['scope'];
        // 'proactive' blocks only what we start. 'all' blocks everything.
        $blocked = ($scope === self::SCOPE_ALL) || ($class === self::CLASS_PROACTIVE);
        if (!$blocked) return $none;

        return ['blocked' => true, 'optout_id' => (int)$row['id'], 'scope' => $scope,
                'reason' => 'opted out (' . $scope . ') on ' . (string)$row['created_at']
                          . ' — ' . (string)$row['reason']];
    }

    /** The live opt-out covering this phone and channel, or null. */
    public function find(string $phone, string $channel = '*'): ?array
    {
        $p   = self::normalise($phone);
        if ($p === '') return null;
        $key = $p . '|' . $channel;
        if (array_key_exists($key, $this->cache)) return $this->cache[$key];

        try {
            // A '*' opt-out covers every channel; a channel-specific one covers
            // only its own. Both are live rows, so ask for either.
            $st = $this->db->prepare(
                "SELECT * FROM contact_optouts
                  WHERE phone = ? AND active = 1 AND (channel = '*' OR channel = ?)
                  ORDER BY CASE WHEN channel = '*' THEN 0 ELSE 1 END, id DESC LIMIT 1");
            $st->execute([$p, $channel]);
            $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            // A missing table means the migration has not run. That is NOT
            // "nobody has opted out" — it is "we cannot tell". Callers of
            // blocks() get a non-blocking answer because refusing every send
            // on a schema problem would be worse, but the condition is loud.
            error_log('[ContactOptOut] cannot read opt-outs: ' . $e->getMessage());
            $row = null;
        }
        return $this->cache[$key] = $row;
    }

    /** True when this phone has any live opt-out at all. */
    public function has(string $phone, string $channel = '*'): bool
    {
        return $this->find($phone, $channel) !== null;
    }

    /**
     * Record an opt-out.
     *
     * Idempotent: a second request for a phone that already has a live row
     * returns the existing one rather than failing on the unique index, because
     * a customer repeating "STOP" must not produce an error.
     *
     * @return array{ok:bool, id:int, created:bool, error?:string}
     */
    public function add(string $phone, array $opts = []): array
    {
        $p = self::normalise($phone);
        if ($p === '') return ['ok' => false, 'id' => 0, 'created' => false,
                               'error' => 'no phone number'];

        $channel = (string)($opts['channel'] ?? '*');
        $existing = $this->find($p, $channel);
        if ($existing !== null) {
            return ['ok' => true, 'id' => (int)$existing['id'], 'created' => false];
        }

        $scope = (string)($opts['scope'] ?? self::SCOPE_PROACTIVE);
        if (!in_array($scope, [self::SCOPE_PROACTIVE, self::SCOPE_ALL], true)) {
            $scope = self::SCOPE_PROACTIVE;
        }
        try {
            $st = $this->db->prepare(
                "INSERT INTO contact_optouts
                    (phone, channel, scope, crm_client_id, reason, source, evidence, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $st->execute([
                $p, $channel, $scope,
                ($opts['crm_client_id'] ?? 0) > 0 ? (int)$opts['crm_client_id'] : null,
                (string)($opts['reason'] ?? 'customer_request'),
                (string)($opts['source'] ?? 'keyword'),
                isset($opts['evidence']) ? mb_substr((string)$opts['evidence'], 0, 500) : null,
                (string)($opts['created_by'] ?? 'system'),
            ]);
            $this->cache = [];   // a write invalidates what we had read
            return ['ok' => true, 'id' => (int)$this->db->lastInsertId(), 'created' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => 0, 'created' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Lift an opt-out — they have asked to hear from us again.
     *
     * The row is not deleted or rewritten: it is marked lifted, so what they
     * originally asked for is still on the record.
     */
    public function lift(string $phone, string $channel, string $by): array
    {
        $p = self::normalise($phone);
        $row = $this->find($p, $channel);
        if ($row === null) return ['ok' => true, 'lifted' => 0];
        try {
            $st = $this->db->prepare(
                "UPDATE contact_optouts SET active = 0, lifted_at = datetime('now'), lifted_by = ?
                  WHERE id = ? AND active = 1");
            $st->execute([$by, (int)$row['id']]);
            $this->cache = [];
            return ['ok' => true, 'lifted' => $st->rowCount()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'lifted' => 0, 'error' => $e->getMessage()];
        }
    }

    /** @return array<int,array<string,mixed>> live opt-outs, newest first */
    public function live(int $limit = 200): array
    {
        try {
            $st = $this->db->prepare(
                "SELECT * FROM contact_optouts WHERE active = 1 ORDER BY id DESC LIMIT ?");
            $st->execute([$limit]);
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return []; }
    }

    /**
     * Does this message ask us to stop?
     *
     * Deliberately conservative. "stop" is a common word — "stop the service
     * for a week", "my internet stopped" — so a stop word only counts on a
     * SHORT message, where it is plausibly the whole point of writing. A
     * missed opt-out is caught by the AI classifier and by staff; a false one
     * silently cuts a paying customer off from us, which is far worse.
     *
     * @return array{stop:bool, matched:string}
     */
    public static function detect(string $text): array
    {
        $t = strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? ''));
        if ($t === '') return ['stop' => false, 'matched' => ''];

        // Longer than a short line and it is a sentence about something, not a
        // request to be left alone.
        if (mb_strlen($t) > 40) return ['stop' => false, 'matched' => ''];

        $t = ' ' . preg_replace('/[^a-z0-9\' -]/', ' ', $t) . ' ';
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        foreach (self::STOP_WORDS as $w) {
            if (preg_match('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/', $t)) {
                return ['stop' => true, 'matched' => $w];
            }
        }
        return ['stop' => false, 'matched' => ''];
    }

    /** Digits only, so a number matches however it was typed. */
    public static function normalise(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}

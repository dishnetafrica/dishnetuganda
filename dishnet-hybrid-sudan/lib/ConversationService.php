<?php
declare(strict_types=1);

/**
 * ConversationService — Unified WhatsApp conversation store
 *
 * Stores conversations and messages from all channels:
 *   - WASender Support (211921443002)
 *   - WASender Accounts (211921443009)
 *   - Evolution API Sales/Marketing (211923400000)
 *
 * All data in SQLite tables: wa_conversations + wa_messages
 * See migrations/017_wa_conversation_store.sql
 */
class ConversationService
{
    private $db; // PDO
    private string $dataDir;

    /**
     * @param string   $dataDir  Plugin data directory
     * @param PDO|null $pdo      Reuse existing PDO connection (recommended)
     */
    public function __construct(string $dataDir, ?PDO $pdo = null)
    {
        $this->dataDir = $dataDir;
        if ($pdo) {
            $this->db = $pdo;
        } else {
            $dbPath = $dataDir . '/dishnet.sqlite';
            $this->db = new PDO('sqlite:' . $dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->exec('PRAGMA journal_mode=WAL');
            $this->db->exec('PRAGMA foreign_keys=ON');
        }
        $this->ensureTables();
    }

    /**
     * Auto-create wa_conversations + wa_messages if they don't exist.
     * Idempotent — safe to call on every boot.
     */
    private function ensureTables(): void
    {
        // Check if table exists at all
        $tableExists = $this->db->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='wa_conversations'"
        )->fetchColumn();

        if ($tableExists) {
            // Table exists — check for missing columns and ADD them (never DROP)
            $cols = $this->db->query("PRAGMA table_info(wa_conversations)")->fetchAll(PDO::FETCH_COLUMN, 1);
            $addCols = [
                'channel'         => "TEXT NOT NULL DEFAULT 'support'",
                'display_name'    => "TEXT",
                'crm_client_id'   => "INTEGER",
                'crm_client_name' => "TEXT",
                'status'          => "TEXT NOT NULL DEFAULT 'active'",
                'category'        => "TEXT",
                'last_message_at' => "TEXT",
                'last_customer_at'=> "TEXT",
                'last_agent_at'   => "TEXT",
                'message_count'   => "INTEGER NOT NULL DEFAULT 0",
                'unread_count'    => "INTEGER NOT NULL DEFAULT 0",
                'tags'            => "TEXT",
                'source'          => "TEXT DEFAULT 'webhook'",
                'lead_id'         => "INTEGER DEFAULT NULL",  // linked lead in leads.json
                'state'           => "TEXT NOT NULL DEFAULT 'bot_active'",  // bot_active | human_active | needs_human
                'last_human_reply_at' => "TEXT DEFAULT NULL",
                // HOW we came to believe this conversation belongs to that
                // customer. Replying to an inbound message tolerates a weak
                // link; opening an UNSOLICITED one does not, so the method is
                // recorded and the follow-up policy reads it.
                'crm_link_method' => "TEXT DEFAULT NULL",
                'crm_link_at'     => "TEXT DEFAULT NULL",
                // WHO this conversation currently belongs to, and how many
                // times that has changed. A phone number is a bearer token
                // that gets reassigned; these two columns are what stop the
                // next holder inheriting the last one's conversation.
                // Epoch 0 means "written before this existed", which is never
                // a current epoch and therefore never replayed to a model.
                'identity_epoch'  => "INTEGER NOT NULL DEFAULT 0",
                'identity_key'    => "TEXT DEFAULT NULL",
                'created_at'      => "TEXT NOT NULL DEFAULT (datetime('now'))",
                'updated_at'      => "TEXT NOT NULL DEFAULT (datetime('now'))",
            ];
            foreach ($addCols as $col => $def) {
                if (!in_array($col, $cols, true)) {
                    try { $this->db->exec("ALTER TABLE wa_conversations ADD COLUMN {$col} {$def}"); } catch (\Throwable $e) {}
                }
            }
            // Also ensure wa_messages exists
            $msgExists = $this->db->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='wa_messages'"
            )->fetchColumn();
            if (!$msgExists) {
                $this->createWaMessagesTable();
            } else {
                // The same additive treatment for messages, which this branch
                // never did: the table was checked for existence and nothing
                // else, so a column added here would never reach an existing
                // install.
                $mcols = $this->db->query("PRAGMA table_info(wa_messages)")->fetchAll(PDO::FETCH_COLUMN, 1);
                foreach (['identity_epoch' => "INTEGER NOT NULL DEFAULT 0",
                          'identity_key'   => "TEXT DEFAULT NULL"] as $col => $def) {
                    if (!in_array($col, $mcols, true)) {
                        try { $this->db->exec("ALTER TABLE wa_messages ADD COLUMN {$col} {$def}"); } catch (\Throwable $e) {}
                    }
                }
                try {
                    $this->db->exec('CREATE INDEX IF NOT EXISTS idx_wa_msg_conv_epoch
                                     ON wa_messages(conversation_id, identity_epoch, sent_at)');
                } catch (\Throwable $e) {}
            }
            return; // Done — never DROP existing data
        }

        // Table does not exist at all — create fresh (first boot only)

        $this->db->exec("
            CREATE TABLE wa_conversations (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                phone           TEXT    NOT NULL,
                channel         TEXT    NOT NULL DEFAULT 'support',
                display_name    TEXT,
                crm_client_id   INTEGER,
                crm_client_name TEXT,
                status          TEXT    NOT NULL DEFAULT 'active',
                category        TEXT,
                last_message_at TEXT,
                last_customer_at TEXT,
                last_agent_at   TEXT,
                message_count   INTEGER NOT NULL DEFAULT 0,
                unread_count    INTEGER NOT NULL DEFAULT 0,
                tags            TEXT,
                source          TEXT    DEFAULT 'webhook',
                state           TEXT    NOT NULL DEFAULT 'bot_active',
                last_human_reply_at TEXT DEFAULT NULL,
                crm_link_method TEXT DEFAULT NULL,
                crm_link_at     TEXT DEFAULT NULL,
                identity_epoch  INTEGER NOT NULL DEFAULT 0,
                identity_key    TEXT    DEFAULT NULL,
                created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->db->exec("CREATE UNIQUE INDEX idx_wa_conv_phone_channel ON wa_conversations(phone, channel)");
        $this->db->exec("CREATE INDEX idx_wa_conv_crm ON wa_conversations(crm_client_id) WHERE crm_client_id IS NOT NULL");
        $this->db->exec("CREATE INDEX idx_wa_conv_active ON wa_conversations(status, last_message_at DESC) WHERE status = 'active'");

        $this->db->exec("
            CREATE TABLE wa_messages (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id INTEGER NOT NULL REFERENCES wa_conversations(id) ON DELETE CASCADE,
                direction       TEXT    NOT NULL,
                role            TEXT    NOT NULL,
                body            TEXT    NOT NULL,
                media_type      TEXT,
                media_url       TEXT,
                agent_name      TEXT,
                wa_message_id   TEXT,
                event_key       TEXT,
                metadata        TEXT,
                identity_epoch  INTEGER NOT NULL DEFAULT 0,
                identity_key    TEXT    DEFAULT NULL,
                sent_at         TEXT    NOT NULL DEFAULT (datetime('now')),
                created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->db->exec("CREATE INDEX idx_wa_msg_conv_time ON wa_messages(conversation_id, sent_at)");
        $this->db->exec("CREATE INDEX idx_wa_msg_conv_epoch ON wa_messages(conversation_id, identity_epoch, sent_at)");
        $this->db->exec("CREATE UNIQUE INDEX idx_wa_msg_wamid ON wa_messages(wa_message_id) WHERE wa_message_id IS NOT NULL");
        $this->db->exec("CREATE INDEX idx_wa_msg_direction ON wa_messages(direction, sent_at)");
    }

    private function createWaMessagesTable(): void
    {
        $this->db->exec("
            CREATE TABLE wa_messages (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id INTEGER NOT NULL REFERENCES wa_conversations(id) ON DELETE CASCADE,
                direction       TEXT    NOT NULL,
                role            TEXT    NOT NULL,
                body            TEXT    NOT NULL,
                media_type      TEXT,
                media_url       TEXT,
                agent_name      TEXT,
                wa_message_id   TEXT,
                event_key       TEXT,
                metadata        TEXT,
                identity_epoch  INTEGER NOT NULL DEFAULT 0,
                identity_key    TEXT    DEFAULT NULL,
                sent_at         TEXT    NOT NULL DEFAULT (datetime('now')),
                created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->db->exec("CREATE INDEX idx_wa_msg_conv_time ON wa_messages(conversation_id, sent_at)");
        $this->db->exec("CREATE INDEX idx_wa_msg_conv_epoch ON wa_messages(conversation_id, identity_epoch, sent_at)");
        $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_wa_msg_wamid ON wa_messages(wa_message_id) WHERE wa_message_id IS NOT NULL");
        $this->db->exec("CREATE INDEX idx_wa_msg_direction ON wa_messages(direction, sent_at)");
    }

    // ══════════════════════════════════════════════════════════════════════
    // CONVERSATIONS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Find or create a conversation for a phone+channel pair.
     */
    public function ensureConversation(string $phone, string $channel, ?string $displayName = null, string $source = 'webhook'): array
    {
        $phone = $this->normalisePhone($phone);

        $stmt = $this->db->prepare('SELECT * FROM wa_conversations WHERE phone = ? AND channel = ?');
        $stmt->execute([$phone, $channel]);
        $conv = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($conv) {
            // Update display name if we now have one
            if ($displayName && (empty($conv['display_name']) || $conv['display_name'] === 'Unknown')) {
                $this->db->prepare('UPDATE wa_conversations SET display_name = ?, updated_at = datetime(\'now\') WHERE id = ?')
                         ->execute([$displayName, $conv['id']]);
                $conv['display_name'] = $displayName;
            }
            return $conv;
        }

        // Create new
        $stmt = $this->db->prepare(
            'INSERT INTO wa_conversations (phone, channel, display_name, source, created_at, updated_at)
             VALUES (?, ?, ?, ?, datetime(\'now\'), datetime(\'now\'))'
        );
        $stmt->execute([$phone, $channel, $displayName ?: 'Unknown', $source]);
        $id = (int)$this->db->lastInsertId();

        return $this->getConversation($id);
    }

    public function getConversation(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM wa_conversations WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByPhone(string $phone, ?string $channel = null): array
    {
        $phone = $this->normalisePhone($phone);
        if ($channel) {
            $stmt = $this->db->prepare('SELECT * FROM wa_conversations WHERE phone = ? AND channel = ? ORDER BY last_message_at DESC');
            $stmt->execute([$phone, $channel]);
        } else {
            $stmt = $this->db->prepare('SELECT * FROM wa_conversations WHERE phone = ? ORDER BY last_message_at DESC');
            $stmt->execute([$phone]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByCrmClient(int $crmClientId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM wa_conversations WHERE crm_client_id = ? ORDER BY last_message_at DESC');
        $stmt->execute([$crmClientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List conversations with filters.
     */
    public function listConversations(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['channel'])) {
            $where[]  = 'c.channel = ?';
            $params[] = $filters['channel'];
        }
        if (!empty($filters['status'])) {
            $where[]  = 'c.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['category'])) {
            $where[]  = 'c.category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(c.phone LIKE ? OR c.display_name LIKE ? OR c.crm_client_name LIKE ?)';
            $s = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }
        if (!empty($filters['state'])) {
            $where[]  = 'c.state = ?';
            $params[] = $filters['state'];
        }

        $sql = 'SELECT c.*,
                (SELECT body FROM wa_messages WHERE conversation_id = c.id ORDER BY sent_at DESC LIMIT 1) as last_message_preview
                FROM wa_conversations c
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY CASE WHEN c.last_message_at IS NULL THEN 1 ELSE 0 END, c.last_message_at DESC
                LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Fallback: column might not exist yet (e.g. state before migration runs)
            // Remove unknown column filters and retry
            $safeWhere  = array_filter($where, fn($w) => strpos($w, 'c.state') === false);
            $safeParams = [];
            foreach ($safeWhere as $i => $w) {
                if (strpos($w, 'c.state') === false) {
                    $paramCount = substr_count($w, '?');
                    for ($pi = 0; $pi < $paramCount; $pi++) {
                        $safeParams[] = $params[count($safeParams)];
                    }
                }
            }
            $safeParams[] = $limit;
            $safeParams[] = $offset;
            $safeSql = 'SELECT c.*,
                (SELECT body FROM wa_messages WHERE conversation_id = c.id ORDER BY sent_at DESC LIMIT 1) as last_message_preview
                FROM wa_conversations c
                WHERE ' . (empty($safeWhere) ? '1=1' : implode(' AND ', array_values($safeWhere))) . '
                ORDER BY CASE WHEN c.last_message_at IS NULL THEN 1 ELSE 0 END, c.last_message_at DESC
                LIMIT ? OFFSET ?';
            $stmt = $this->db->prepare($safeSql);
            $stmt->execute($safeParams);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    public function countConversations(array $filters = []): int
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['channel'])) {
            $where[]  = 'channel = ?';
            $params[] = $filters['channel'];
        }
        if (!empty($filters['status'])) {
            $where[]  = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['state'])) {
            $where[]  = 'state = ?';
            $params[] = $filters['state'];
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM wa_conversations WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** How a conversation came to be linked to a customer. */
    public const LINK_VERIFIED      = 'verified';       // authenticated, or staff-confirmed
    public const LINK_MANUAL        = 'manual';         // a person linked it
    public const LINK_AI            = 'ai_identified';  // one unambiguous match
    public const LINK_PHONE_TAIL    = 'phone_tail';     // 9-digit tail at first contact
    public const LINK_BULK_REMATCH  = 'bulk_rematch';   // batch re-linking pass
    public const LINK_AMBIGUOUS     = 'ambiguous';      // several clients share the number

    /**
     * Link a conversation to a CRM client, recording HOW.
     *
     * The method is not decoration. A 9-digit phone-tail match is good enough
     * to answer somebody who just wrote to us, and not good enough to open an
     * unsolicited message with their account details — because the worst
     * failure this system can have is sending one customer's private
     * information to another. FollowUpPolicy reads this column and decides
     * what a proactive message is allowed to contain.
     *
     * The method defaults to 'manual' rather than to nothing: an unlabelled
     * link is most likely a person, and 'manual' is a permissive value, so
     * leaving it unset must be a deliberate choice by the caller rather than
     * an accident that silently widens what we may say.
     */
    public function linkToCrm(int $convId, int $crmClientId, string $crmClientName = '',
                              string $method = self::LINK_MANUAL): void
    {
        $this->db->prepare(
            'UPDATE wa_conversations
                SET crm_client_id = ?, crm_client_name = ?,
                    crm_link_method = ?, crm_link_at = datetime(\'now\'),
                    updated_at = datetime(\'now\')
              WHERE id = ?')
                 ->execute([$crmClientId, $crmClientName, $method, $convId]);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  IDENTITY EPOCHS — who a conversation belongs to, and since when
    //
    //  A conversation is keyed by (phone, channel). A phone number is a
    //  bearer token: it gets reassigned, and it gets shared. Before this,
    //  replay followed the key, so the next holder of a number inherited the
    //  last holder's conversation — including an assistant reply stating
    //  their balance, replayed into the new person's prompt as the model's
    //  own previous turn.
    //
    //  So identity is resolved fresh every turn by the backend, stamped onto
    //  each message as it is written, and retrieval refuses to cross from one
    //  identity to another. History is context. It is never authorisation,
    //  and nothing here may be read as proof that anybody is entitled to
    //  anything.
    //
    //  Epoch 0 is "written before this existed". It is never a current epoch,
    //  so pre-existing conversations stay whole for staff and are never
    //  replayed to a model.
    // ══════════════════════════════════════════════════════════════════════

    /** No customer could be identified. Never replayable. */
    public const ID_UNKNOWN   = 'unknown';
    /** Several customers match. Never replayable. */
    public const ID_AMBIGUOUS = 'ambiguous';

    // ── FOUR STATES, AND TWO OF THEM ARE NOT THE SAME THING ──────────────
    //
    // There are exactly four things an identity can be, and the distinction
    // that matters most is between the first two:
    //
    //   IDENTIFIED  'client:7'      a CRM customer. Their own account data
    //                               may be disclosed to them.
    //   ANONYMOUS   'session:ab12'  a website visitor. They own a SALES
    //                               conversation and nothing else. There is
    //                               no account behind this, and there is no
    //                               way to get one from here.
    //   UNKNOWN     'unknown'       nobody could be identified — including
    //                               when the CRM is merely unreachable.
    //   AMBIGUOUS   'ambiguous'     several customers share the number.
    //
    // Both of the first two may replay their own history. Only the FIRST is
    // a customer. Anyone reaching for "can this identity have account data"
    // wants isCustomerIdentity(), never replayableIdentity() — the two
    // questions look alike and are not, and conflating them would let an
    // anonymous session inherit a customer's authority, which is the exact
    // shape of the bug this whole phase exists to remove.
    public const STATE_IDENTIFIED = 'identified';
    public const STATE_ANONYMOUS  = 'anonymous';
    public const STATE_UNKNOWN    = 'unknown';
    public const STATE_AMBIGUOUS  = 'ambiguous';

    /** Which of the four this key is. */
    public static function identityState(string $key): string
    {
        if (strncmp($key, 'client:', 7) === 0)  return self::STATE_IDENTIFIED;
        if (strncmp($key, 'session:', 8) === 0) return self::STATE_ANONYMOUS;
        if ($key === self::ID_AMBIGUOUS)        return self::STATE_AMBIGUOUS;
        return self::STATE_UNKNOWN;
    }

    /**
     * Is this a CRM customer — the only state that may see account data?
     *
     * An anonymous session is deliberately NOT one. A visitor holding a
     * session id has proved they are the same browser as last time, which is
     * enough to continue a sales conversation and is not evidence about any
     * account. Session history can never become proof of customer identity,
     * and this is the function that says so.
     */
    public static function isCustomerIdentity(string $key): bool
    {
        return self::identityState($key) === self::STATE_IDENTIFIED;
    }

    /** The customer id behind an identified key, or null for every other state. */
    public static function customerIdOf(string $key): ?int
    {
        if (!self::isCustomerIdentity($key)) return null;
        $id = (int)substr($key, 7);
        return $id > 0 ? $id : null;
    }

    /**
     * The identity key for a resolved CRM customer.
     *
     * @param int|null $clientId  the id the BACKEND resolved this turn
     * @param bool     $ambiguous several customers matched
     */
    public static function identityKey(?int $clientId, bool $ambiguous = false): string
    {
        if ($ambiguous) return self::ID_AMBIGUOUS;
        return ($clientId !== null && $clientId > 0) ? 'client:' . $clientId : self::ID_UNKNOWN;
    }

    /**
     * The identity key for an anonymous website visitor.
     *
     * A session is not a customer, and this is deliberately NOT 'unknown'. A
     * web conversation holds no account data — web_chat passes customer=null
     * and no account block — so there is nothing of anybody's to leak into
     * it, and the session id is held by one browser rather than reassigned
     * the way a phone number is. Treating it as unknown would end multi-turn
     * sales conversations on the website for no security gain.
     */
    public static function sessionIdentityKey(string $session): string
    {
        $s = preg_replace('/[^a-f0-9]/', '', strtolower($session)) ?? '';
        return $s === '' ? self::ID_UNKNOWN : 'session:' . $s;
    }

    /**
     * Can turns written under this identity ever be replayed to a model?
     *
     * True for a customer AND for an anonymous session — they are separate
     * authorisation domains that each own their own conversation. This
     * answers "may this identity see its own history", never "may this
     * identity see account data": that is isCustomerIdentity().
     */
    public static function replayableIdentity(string $key): bool
    {
        $s = self::identityState($key);
        return $s === self::STATE_IDENTIFIED || $s === self::STATE_ANONYMOUS;
    }

    /**
     * Open this turn under the identity the backend resolved for it.
     *
     * Returns the epoch messages written now belong to. When the identity has
     * changed — a different customer, or none, or several — the epoch
     * advances and the stored CRM link is cleared, because a link recorded
     * for somebody else is worse than no link at all: FollowUpPolicy reads
     * that column to decide whether a proactive message may carry account
     * content. A caller that has just identified somebody re-links straight
     * after this, so the clear costs nothing it should keep.
     *
     * Epoch 0 never survives contact: the first turn under this rule advances
     * to 1 whatever the identity, so nothing written before it is replayable.
     */
    public function beginTurn(int $convId, string $identityKey): int
    {
        $stmt = $this->db->prepare(
            'SELECT identity_key, identity_epoch FROM wa_conversations WHERE id = ?');
        $stmt->execute([$convId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $current = (string)($row['identity_key'] ?? '');
        $epoch   = (int)($row['identity_epoch'] ?? 0);

        if ($current === $identityKey && $epoch >= 1) {
            return $epoch;
        }

        $epoch++;
        $this->db->prepare(
            'UPDATE wa_conversations
                SET identity_epoch = ?, identity_key = ?,
                    crm_client_id = NULL, crm_client_name = NULL,
                    crm_link_method = NULL, crm_link_at = NULL,
                    updated_at = datetime(\'now\')
              WHERE id = ?')
                 ->execute([$epoch, $identityKey, $convId]);
        return $epoch;
    }

    /**
     * The turns a model may see, for the identity resolved THIS turn.
     *
     * Empty unless all of these hold, and each is a separate refusal rather
     * than one combined condition, so a future edit cannot relax them by
     * accident:
     *
     *   - the identity is one that can own a conversation at all;
     *   - the conversation is at a real epoch (never 0, never pre-existing);
     *   - the conversation's identity is the one asking now;
     *   - and the message itself was written under both.
     *
     * The caller's own limit still applies on top. Ordering is the same
     * newest-first-then-reversed shape as getMessages(), including the id
     * tiebreaker, because an AI reply lands in the same second as the
     * question it answers.
     */
    public function getMessagesForAi(int $convId, string $identityKey, int $limit = 20): array
    {
        if (!self::replayableIdentity($identityKey)) return [];

        $stmt = $this->db->prepare(
            'SELECT identity_key, identity_epoch FROM wa_conversations WHERE id = ?');
        $stmt->execute([$convId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];

        $epoch = (int)($row['identity_epoch'] ?? 0);
        if ($epoch < 1) return [];
        if ((string)($row['identity_key'] ?? '') !== $identityKey) return [];

        $stmt = $this->db->prepare(
            'SELECT * FROM (
                SELECT * FROM wa_messages
                 WHERE conversation_id = ? AND identity_epoch = ? AND identity_key = ?
                 ORDER BY sent_at DESC, id DESC LIMIT ?
             ) sub ORDER BY sent_at ASC, id ASC');
        $stmt->execute([$convId, $epoch, $identityKey, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Record that a number matches several customers — no proactive contact. */
    public function markIdentityAmbiguous(int $convId): void
    {
        $this->db->prepare(
            'UPDATE wa_conversations
                SET crm_link_method = ?, crm_link_at = datetime(\'now\'), updated_at = datetime(\'now\')
              WHERE id = ? AND crm_client_id IS NULL')
                 ->execute([self::LINK_AMBIGUOUS, $convId]);
    }

    public function categorise(int $convId, string $category): void
    {
        $this->db->prepare('UPDATE wa_conversations SET category = ?, updated_at = datetime(\'now\') WHERE id = ?')
                 ->execute([$category, $convId]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // MESSAGES
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Store a message. Returns the message ID.
     * Handles dedup via wa_message_id.
     */
    public function storeMessage(int $conversationId, array $msg): ?int
    {
        // Dedup check
        $waMsgId = $msg['wa_message_id'] ?? null;
        if ($waMsgId) {
            $stmt = $this->db->prepare('SELECT id FROM wa_messages WHERE wa_message_id = ?');
            $stmt->execute([$waMsgId]);
            if ($stmt->fetch()) return null; // Already exists
        }

        // Stamp the identity this message is written under, from the
        // conversation rather than from the caller. Every writer — the two
        // webhooks, the worker, web chat, the admin API, the importer — is
        // then correct without having to remember, and a message can never
        // end up in an epoch its conversation is not in.
        $ep  = 0;
        $key = null;
        try {
            $idq = $this->db->prepare(
                'SELECT identity_epoch, identity_key FROM wa_conversations WHERE id = ?');
            $idq->execute([$conversationId]);
            if ($idrow = $idq->fetch(PDO::FETCH_ASSOC)) {
                $ep  = (int)($idrow['identity_epoch'] ?? 0);
                $key = $idrow['identity_key'] ?? null;
            }
        } catch (\Throwable $e) { /* pre-migration row: epoch 0, never replayed */ }

        $stmt = $this->db->prepare(
            'INSERT INTO wa_messages (conversation_id, direction, role, body, media_type, media_url,
             agent_name, wa_message_id, event_key, metadata, identity_epoch, identity_key, sent_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))'
        );
        $stmt->execute([
            $conversationId,
            $msg['direction'],
            $msg['role'],
            $msg['body'],
            $msg['media_type'] ?? null,
            $msg['media_url'] ?? null,
            $msg['agent_name'] ?? null,
            $waMsgId,
            $msg['event_key'] ?? null,
            isset($msg['metadata']) ? json_encode($msg['metadata']) : null,
            $ep,
            $key,
            // gmdate, not date(): sent_at was written by whichever entry point
            // happened to run -- the webhook under Africa/Juba, the spawned CLI
            // worker under UTC -- so one conversation carried two clocks, and
            // ordering by sent_at put every AI reply two hours before the
            // question it answered. Both the Inbox and the model's history read
            // it that way. Storage is UTC everywhere; display localises.
            $msg['sent_at'] ?? gmdate('Y-m-d H:i:s'),
        ]);

        $msgId = (int)$this->db->lastInsertId();

        // Update conversation counters
        $dir  = $msg['direction'];
        $now  = $msg['sent_at'] ?? gmdate('Y-m-d H:i:s');
        $updates = [
            'message_count = message_count + 1',
            "last_message_at = MAX(COALESCE(last_message_at, ''), '{$now}')",
            "updated_at = datetime('now')",
        ];
        if ($dir === 'in') {
            $updates[] = "last_customer_at = MAX(COALESCE(last_customer_at, ''), '{$now}')";
            $updates[] = 'unread_count = unread_count + 1';
        } else {
            $updates[] = "last_agent_at = MAX(COALESCE(last_agent_at, ''), '{$now}')";
        }

        $this->db->exec("UPDATE wa_conversations SET " . implode(', ', $updates) . " WHERE id = {$conversationId}");

        return $msgId;
    }

    /**
     * Get messages for a conversation.
     */
    public function getMessages(int $conversationId, int $limit = 100, int $offset = 0): array
    {
        // Fetch newest messages first (DESC), then reverse so UI shows oldest→newest.
        // This fixes showing old messages when a conversation has >100 entries.
        //
        // id is the tiebreaker, and it is not optional. sent_at has one-second
        // resolution, and an AI reply almost always lands in the same second as
        // the message it answers -- with sent_at alone SQLite is free to order
        // ties however it likes, and it returned whole transcripts backwards.
        // That is visible in the Inbox, and worse, AiReplyWorker feeds this
        // exact function to the model as conversation history.
        $stmt = $this->db->prepare(
            'SELECT * FROM (
                SELECT * FROM wa_messages WHERE conversation_id = ?
                ORDER BY sent_at DESC, id DESC LIMIT ? OFFSET ?
             ) sub ORDER BY sent_at ASC, id ASC'
        );
        $stmt->execute([$conversationId, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countMessages(int $conversationId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM wa_messages WHERE conversation_id = ?');
        $stmt->execute([$conversationId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Search messages across all conversations.
     */
    public function searchMessages(string $query, ?string $channel = null, int $limit = 50): array
    {
        $params = ['%' . $query . '%'];
        $channelJoin = '';
        if ($channel) {
            $channelJoin = ' AND c.channel = ?';
            $params[] = $channel;
        }

        $stmt = $this->db->prepare(
            "SELECT m.*, c.phone, c.channel, c.display_name, c.crm_client_name
             FROM wa_messages m
             JOIN wa_conversations c ON c.id = m.conversation_id
             WHERE m.body LIKE ? {$channelJoin}
             ORDER BY m.sent_at DESC
             LIMIT ?"
        );
        $params[] = $limit;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark conversation as read (reset unread count).
     */
    /**
     * A colleague has picked this conversation up.
     *
     * Nothing on the live Evolution path ever set this. humanIsHandling()
     * checks state='human_active' and last_human_reply_at, and only
     * WaBotService — the legacy WASender path, disabled in this edition —
     * wrote either. So the AI's "a person is handling this, stay out" rule has
     * never once fired in production, and c109 is what that looks like: a
     * colleague typing on the handset at 19:18, 19:21, 19:24 and 19:49 with
     * the assistant answering over the top of them every time.
     */
    public function markHumanHandling(int $convId): void
    {
        if ($convId <= 0) return;
        $st = $this->db->prepare(
            "UPDATE wa_conversations
                SET state = 'human_active',
                    last_human_reply_at = ?,
                    updated_at = datetime('now')
              WHERE id = ?"
        );
        // UTC, matching wa_messages.sent_at. humanIsHandling() compares this
        // against time(), and a Juba-stamped value would read three hours in
        // the future and silence the AI for three hours longer than intended.
        $st->execute([gmdate('Y-m-d H:i:s'), $convId]);
    }

    public function markRead(int $conversationId): void
    {
        $this->db->prepare("UPDATE wa_conversations SET unread_count = 0, updated_at = datetime('now') WHERE id = ?")
                 ->execute([$conversationId]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ANALYTICS
    // ══════════════════════════════════════════════════════════════════════

    public function getStats(): array
    {
        $stats = ['by_channel' => [], 'by_direction' => [], 'by_category' => [],
                  'messages_today' => 0, 'active_7d' => 0, 'total_unread' => 0,
                  'unread_support' => 0, 'unread_accounts' => 0, 'unread_web' => 0,
                  'needs_human' => 0];

        try {
            $stmt = $this->db->query("SELECT channel, COUNT(*) as cnt, SUM(message_count) as msgs FROM wa_conversations GROUP BY channel");
            $stats['by_channel'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->db->query("SELECT direction, COUNT(*) as cnt FROM wa_messages GROUP BY direction");
            $stats['by_direction'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->db->query("SELECT COALESCE(category, 'uncategorised') as cat, COUNT(*) as cnt FROM wa_conversations GROUP BY category ORDER BY cnt DESC");
            $stats['by_category'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $this->db->query("SELECT COUNT(*) FROM wa_messages WHERE sent_at >= date('now')");
            $stats['messages_today'] = (int)$stmt->fetchColumn();

            $stmt = $this->db->query("SELECT COUNT(*) FROM wa_conversations WHERE last_message_at >= datetime('now', '-7 days')");
            $stats['active_7d'] = (int)$stmt->fetchColumn();

            $stmt = $this->db->query("SELECT SUM(unread_count) FROM wa_conversations");
            $stats['total_unread'] = (int)$stmt->fetchColumn();

            // Per-channel unread counts
            $stmt = $this->db->query("SELECT COALESCE(SUM(unread_count), 0) FROM wa_conversations WHERE channel = 'support'");
            $stats['unread_support'] = (int)$stmt->fetchColumn();
            $stmt = $this->db->query("SELECT COALESCE(SUM(unread_count), 0) FROM wa_conversations WHERE channel = 'web'");
            $stats['unread_web'] = (int)$stmt->fetchColumn();
            $stmt = $this->db->query("SELECT COALESCE(SUM(unread_count), 0) FROM wa_conversations WHERE channel = 'accounts'");
            $stats['unread_accounts'] = (int)$stmt->fetchColumn();

            // Needs human attention (escalated by bot)
            $stmt = $this->db->query("SELECT COUNT(*) FROM wa_conversations WHERE state = 'needs_human' AND status != 'closed'");
            $stats['needs_human'] = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            // Tables may not exist yet — return empty stats
        }

        return $stats;
    }

    // ══════════════════════════════════════════════════════════════════════
    // TRAINING DATA
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Auto-extract Q&A training pairs from a conversation.
     * Pairs: customer message (question) → next agent/bot response (answer).
     */
    public function extractTrainingPairs(int $conversationId): int
    {
        $msgs = $this->getMessages($conversationId, 5000);
        $conv = $this->getConversation($conversationId);
        if (!$conv || count($msgs) < 2) return 0;

        $pairs = 0;
        for ($i = 0; $i < count($msgs) - 1; $i++) {
            $q = $msgs[$i];
            $a = $msgs[$i + 1];

            // Customer question → agent/bot answer
            if ($q['direction'] === 'in' && $a['direction'] === 'out' && !empty($q['body']) && !empty($a['body'])) {
                // Skip very short or system messages
                if (mb_strlen($q['body']) < 3 || mb_strlen($a['body']) < 10) continue;

                // Check if already exists
                $check = $this->db->prepare('SELECT id FROM wa_training_data WHERE source_msg_id = ?');
                $check->execute([$q['id']]);
                if ($check->fetch()) continue;

                $this->db->prepare(
                    'INSERT INTO wa_training_data (channel, category, question, answer, source_conv_id, source_msg_id)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $conv['channel'],
                    $conv['category'],
                    $q['body'],
                    $a['body'],
                    $conversationId,
                    $q['id'],
                ]);
                $pairs++;
            }
        }

        return $pairs;
    }

    /**
     * Get training data for export or AI fine-tuning.
     */
    public function getTrainingData(string $quality = 'approved', ?string $channel = null, int $limit = 5000): array
    {
        $where  = ['quality = ?'];
        $params = [$quality];
        if ($channel) {
            $where[]  = 'channel = ?';
            $params[] = $channel;
        }
        $params[] = $limit;

        $stmt = $this->db->prepare(
            'SELECT * FROM wa_training_data WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ?'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ══════════════════════════════════════════════════════════════════════
    // BULK IMPORT (for Evolution API / WASender history)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Import a message from Evolution API format.
     * Returns conversation_id on success, null on dedup skip.
     */
    public function importEvoMessage(array $evoMsg, string $channel = 'marketing'): ?int
    {
        // key and message may be JSON strings when fetched from findMessages API
        $key = $evoMsg['key'] ?? [];
        if (is_string($key)) {
            $key = json_decode($key, true) ?? [];
        }

        $message = $evoMsg['message'] ?? [];
        if (is_string($message)) {
            $message = json_decode($message, true) ?? [];
        }

        $remoteJid = $key['remoteJid'] ?? '';
        if (empty($remoteJid) || strpos($remoteJid, '@g.us') !== false) {
            return null; // Skip groups
        }

        // Evolution API v2.3 uses @lid (Linked ID) — real phone is in senderPn
        $fromMe  = (bool)($key['fromMe'] ?? false);
        $senderPn = $key['senderPn'] ?? '';

        // Determine the customer's phone number
        if (!$fromMe && !empty($senderPn)) {
            $phone = $this->normalisePhone(explode('@', $senderPn)[0]);
        } elseif (strpos($remoteJid, '@lid') !== false) {
            $phone = !empty($senderPn)
                ? $this->normalisePhone(explode('@', $senderPn)[0])
                : $this->normalisePhone(explode('@', $remoteJid)[0]);
        } else {
            $phone = $this->normalisePhone(explode('@', $remoteJid)[0]);
        }

        if (empty($phone)) return null;

        $msgId    = $key['id'] ?? null;
        $pushName = $evoMsg['pushName'] ?? null;

        // Extract text from various message formats
        $body = $message['conversation']
             ?? $message['extendedTextMessage']['text']
             ?? $message['imageMessage']['caption']
             ?? $message['documentMessage']['caption']
             ?? $message['videoMessage']['caption']
             ?? '';

        // Determine media type
        $mediaType = null;
        if (isset($message['imageMessage']))    $mediaType = 'image';
        if (isset($message['documentMessage'])) $mediaType = 'document';
        if (isset($message['audioMessage']))    $mediaType = 'audio';
        if (isset($message['videoMessage']))    $mediaType = 'video';
        if (isset($message['stickerMessage'])) $mediaType = 'sticker';
        if (isset($message['locationMessage'])) $mediaType = 'location';

        // For media-only messages (no caption): use a placeholder body so the
        // message is stored in the conversation log instead of being dropped.
        if (empty($body) && $mediaType) {
            $body = '[' . strtoupper($mediaType) . ']';
        }

        if (empty($body)) return null; // truly empty — skip

        // Timestamp
        $ts = $evoMsg['messageTimestamp'] ?? $evoMsg['timestamp'] ?? null;
        // Evolution's messageTimestamp is unix seconds; gmdate keeps it UTC
        // regardless of the entry point's timezone.
        $sentAt = $ts ? gmdate('Y-m-d H:i:s', is_numeric($ts) ? (int)$ts : strtotime($ts)) : gmdate('Y-m-d H:i:s');

        // Ensure conversation exists
        $conv = $this->ensureConversation($phone, $channel, $pushName, 'import');

        // Store message
        return $this->storeMessage($conv['id'], [
            'direction'     => $fromMe ? 'out' : 'in',
            'role'          => $fromMe ? 'agent' : 'customer',
            'body'          => $body,
            'media_type'    => $mediaType,
            'wa_message_id' => $msgId,
            'sent_at'       => $sentAt,
            'agent_name'    => $fromMe ? 'DishNet' : null,
        ]) ? $conv['id'] : null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    private function normalisePhone(string $phone): string
    {
        // A website visitor has no phone number. They are keyed by their chat
        // session behind a "web:" prefix, which has to survive intact: stripping
        // it to digits would lose the session AND risk colliding with a real
        // customer's number. No real phone number can start with "web:", so this
        // branch cannot capture one.
        //
        // Unused until the WebChat migration is deployed -- nothing passes a
        // web: phone today, so this is inert on 5.5.2's behaviour.
        if (strpos($phone, 'web:') === 0) {
            // Lowercased first: session ids are lowercase hex from bin2hex(),
            // and folding case is safer than silently dropping characters.
            return 'web:' . preg_replace('/[^a-f0-9]/', '', strtolower(substr($phone, 4)));
        }
        return preg_replace('/[^0-9]/', '', $phone);
    }

    public function getPdo(): PDO
    {
        return $this->db;
    }
}

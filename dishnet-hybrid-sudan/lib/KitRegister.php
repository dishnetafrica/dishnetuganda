<?php
declare(strict_types=1);

/**
 * KitRegister — which kit went to which customer, and when.
 *
 * Two questions have to stay answerable for years: what hardware does this
 * customer have, and where did that serial go. A support call gives one and
 * needs the other; so does a warranty claim, an audit, and a kit that comes
 * back from a cancelled account and is handed to somebody else.
 *
 * So current state and history are kept separately. The kit row says where a
 * kit is NOW; the movement log says everywhere it has been. Overwriting the
 * first is normal, and it must never quietly erase the second — a kit that
 * moved from one customer to another has to show both, or nobody can answer
 * "who had this in March".
 *
 * Fed from two places that must not contradict each other: Starlink's own
 * emails, which name a kit before we have touched it, and a person recording
 * that an installer handed one over. Where they disagree the person wins,
 * because they were in the room.
 */
class KitRegister
{
    const ORDERED  = 'ordered';    // Starlink confirmed an order
    const SHIPPED  = 'shipped';    // in transit to us
    const IN_STOCK = 'in_stock';   // received, not yet given out
    const ASSIGNED = 'assigned';   // handed to a customer
    const ACTIVE   = 'active';     // Starlink says the service line is live
    const RETURNED = 'returned';   // came back to us

    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS starlink_kits (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                kit_id          TEXT    NOT NULL UNIQUE,
                order_reference TEXT    NOT NULL DEFAULT '',
                tracking_number TEXT    NOT NULL DEFAULT '',
                status          TEXT    NOT NULL DEFAULT 'ordered',
                crm_client_id   INTEGER NOT NULL DEFAULT 0,
                assigned_at     TEXT    NOT NULL DEFAULT '',
                assigned_by     TEXT    NOT NULL DEFAULT '',
                notes           TEXT    NOT NULL DEFAULT '',
                first_seen_at   TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
             )");

        // Every movement, kept forever. The kit row is overwritten as a kit
        // moves; this is not.
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS starlink_kit_events (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                kit_id        TEXT    NOT NULL,
                at            TEXT    NOT NULL DEFAULT (datetime('now')),
                what          TEXT    NOT NULL,
                crm_client_id INTEGER NOT NULL DEFAULT 0,
                who           TEXT    NOT NULL DEFAULT '',
                detail        TEXT    NOT NULL DEFAULT ''
             )");

        foreach ([
            'CREATE INDEX IF NOT EXISTS idx_kit_client ON starlink_kits(crm_client_id)',
            'CREATE INDEX IF NOT EXISTS idx_kit_status ON starlink_kits(status)',
            'CREATE INDEX IF NOT EXISTS idx_kitev_kit  ON starlink_kit_events(kit_id, at)',
        ] as $sql) {
            try { $this->pdo->exec($sql); } catch (\Throwable $e) {}
        }
    }

    /**
     * A kit named in one of Starlink's emails.
     *
     * Never assigns a customer on its own. Starlink's mail says a kit exists
     * and where it is in their process; it does not say which of our customers
     * ended up holding it, and inferring that from a delivery address would be
     * a guess wearing a fact's clothes.
     *
     * @param array $extracted  the classifier's extracted block
     * @return string the kit id recorded, or '' if the email named none
     */
    public function noteFromSupplier(array $extracted, string $type): string
    {
        $kit = self::normaliseKitId((string)($extracted['kit'] ?? ''));
        if ($kit === '') return '';

        $status = self::statusForSupplierEvent($type);
        $order  = trim((string)($extracted['order_reference'] ?? ''));
        $track  = trim((string)($extracted['tracking_number'] ?? ''));

        $existing = $this->find($kit);
        if ($existing === null) {
            $st = $this->pdo->prepare(
                'INSERT INTO starlink_kits (kit_id, order_reference, tracking_number, status)
                 VALUES (:k, :o, :t, :s)');
            $st->execute([':k' => $kit, ':o' => $order, ':t' => $track, ':s' => $status]);
            $this->log($kit, 'seen in supplier mail (' . $type . ')', 0, 'starlink', $order);
            return $kit;
        }

        // A kit already with a customer is not moved backwards by a late
        // shipping notice. Their email arriving out of order must not
        // un-assign hardware somebody is using.
        $keepStatus = in_array((string)$existing['status'],
            [self::ASSIGNED, self::ACTIVE, self::RETURNED], true)
            && $status !== self::ACTIVE;

        // The status is decided here, not in the SQL. PDO binds parameters as
        // text by default, and SQLite compares a text '1' against an integer 1
        // as unequal — so a CASE WHEN :keep = 1 was silently always false, and
        // a late shipping notice walked a customer's live kit backwards.
        $newStatus = $keepStatus ? (string)$existing['status'] : $status;

        $st = $this->pdo->prepare(
            'UPDATE starlink_kits
                SET order_reference = CASE WHEN :o <> "" THEN :o ELSE order_reference END,
                    tracking_number = CASE WHEN :t <> "" THEN :t ELSE tracking_number END,
                    status          = :s,
                    updated_at      = datetime("now")
              WHERE kit_id = :k');
        $st->execute([':k' => $kit, ':o' => $order, ':t' => $track, ':s' => $newStatus]);
        $this->log($kit, 'supplier mail (' . $type . ')',
                   (int)$existing['crm_client_id'], 'starlink', $order);
        return $kit;
    }

    /**
     * Record that a customer has this kit.
     *
     * @return array{ok:bool, error:string, moved_from:int}
     */
    public function assign(string $kitId, int $clientId, string $who, string $notes = ''): array
    {
        $kit = self::normaliseKitId($kitId);
        if ($kit === '')    return ['ok' => false, 'error' => 'no kit id given', 'moved_from' => 0];
        if ($clientId <= 0) return ['ok' => false, 'error' => 'no customer given', 'moved_from' => 0];

        $existing = $this->find($kit);
        $from     = $existing !== null ? (int)$existing['crm_client_id'] : 0;

        if ($existing === null) {
            // A kit we were never told about by Starlink. Recorded anyway: the
            // installer holding it is better evidence than our inbox.
            $this->pdo->prepare(
                'INSERT INTO starlink_kits (kit_id, status, crm_client_id, assigned_at, assigned_by, notes)
                 VALUES (:k, :s, :c, datetime("now"), :w, :n)')
                ->execute([':k' => $kit, ':s' => self::ASSIGNED, ':c' => $clientId,
                           ':w' => $who, ':n' => $notes]);
        } else {
            $this->pdo->prepare(
                'UPDATE starlink_kits
                    SET status = :s, crm_client_id = :c, assigned_at = datetime("now"),
                        assigned_by = :w,
                        notes = CASE WHEN :n <> "" THEN :n ELSE notes END,
                        updated_at = datetime("now")
                  WHERE kit_id = :k')
                ->execute([':k' => $kit, ':s' => self::ASSIGNED, ':c' => $clientId,
                           ':w' => $who, ':n' => $notes]);
        }

        $this->log($kit, $from > 0 && $from !== $clientId
            ? 'reassigned from client ' . $from : 'assigned', $clientId, $who, $notes);

        return ['ok' => true, 'error' => '', 'moved_from' => $from];
    }

    /** The kit comes back to us. The customer link stays in the history. */
    public function markReturned(string $kitId, string $who, string $notes = ''): array
    {
        $kit = self::normaliseKitId($kitId);
        $row = $this->find($kit);
        if ($row === null) return ['ok' => false, 'error' => 'no such kit'];

        $this->pdo->prepare(
            'UPDATE starlink_kits SET status = :s, crm_client_id = 0,
                    updated_at = datetime("now") WHERE kit_id = :k')
            ->execute([':k' => $kit, ':s' => self::RETURNED]);

        $this->log($kit, 'returned from client ' . (int)$row['crm_client_id'],
                   (int)$row['crm_client_id'], $who, $notes);
        return ['ok' => true, 'error' => ''];
    }

    public function find(string $kitId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM starlink_kits WHERE kit_id = ?');
        $st->execute([self::normaliseKitId($kitId)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** What this customer has now. */
    public function forClient(int $clientId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM starlink_kits WHERE crm_client_id = ? ORDER BY assigned_at DESC');
        $st->execute([$clientId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Everywhere a kit has been, oldest first. */
    public function history(string $kitId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM starlink_kit_events WHERE kit_id = ? ORDER BY id');
        $st->execute([self::normaliseKitId($kitId)]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Kits we hold that nobody has. */
    public function unassigned(int $limit = 200): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM starlink_kits WHERE crm_client_id = 0 ORDER BY updated_at DESC LIMIT :l');
        $st->bindValue(':l', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function counts(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT status, COUNT(*) c FROM starlink_kits GROUP BY status')
                 as $r) {
            $out[(string)$r['status']] = (int)$r['c'];
        }
        return $out;
    }

    private function log(string $kit, string $what, int $clientId, string $who, string $detail): void
    {
        $this->pdo->prepare(
            'INSERT INTO starlink_kit_events (kit_id, what, crm_client_id, who, detail)
             VALUES (:k, :w, :c, :o, :d)')
            ->execute([':k' => $kit, ':w' => $what, ':c' => $clientId,
                       ':o' => $who, ':d' => mb_substr($detail, 0, 500)]);
    }

    /**
     * One kit, one spelling.
     *
     * Serials arrive from an email, a spreadsheet and a person reading a label
     * on a roof. Without this the same kit lands three times and the register
     * answers "which customer" three different ways.
     */
    public static function normaliseKitId(string $id): string
    {
        $id = strtoupper(trim($id));
        $id = preg_replace('/[\s\-_.]+/', '', $id) ?? '';
        return preg_replace('/[^A-Z0-9]/', '', $id) ?? '';
    }

    /** Where a supplier event puts a kit in its journey. */
    public static function statusForSupplierEvent(string $type): string
    {
        switch (strtoupper($type)) {
            case 'ORDER_CONFIRMED': return self::ORDERED;
            case 'ORDER_SHIPPED':   return self::SHIPPED;
            case 'ACTIVATION':      return self::ACTIVE;
            default:                return self::ORDERED;
        }
    }
}

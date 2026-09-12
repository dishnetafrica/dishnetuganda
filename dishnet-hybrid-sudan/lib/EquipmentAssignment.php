<?php
declare(strict_types=1);

require_once __DIR__ . '/FinAudit.php';

/**
 * EquipmentAssignment — who owns this kit, answered the same way every time.
 *
 * The question had three answers before this class, depending on which code
 * path asked it. stock_units.crm_client_id was the strong one. A file from a
 * plugin that is not installed was the second. And the blocking path — the one
 * that decides whether a paying customer keeps their internet — fell through
 * to a regular expression over a service name somebody typed:
 *
 *     preg_match('/\bKIT[A-Z0-9]{8,}\b/i', $service['name'])
 *
 * Rename a service and a customer stops being blockable. Mistype a serial in a
 * service name and a different customer's dish goes dark. Neither leaves a
 * trace, because from the outside a guess that finds nothing and a guess that
 * finds the wrong thing look exactly alike.
 *
 * ── THE ONE RULE ────────────────────────────────────────────────────────
 *
 * Identity is uCRM's client id. An integer. Not a name, not a phone number,
 * not an email, not a service title, and never a substring of any of them. If
 * there is no assignment, the answer is "there is no assignment" — never a
 * best guess.
 *
 * ── BOTH DIRECTIONS ─────────────────────────────────────────────────────
 *
 *   forClient(123)       → the kits that customer has, now
 *   forService(500)      → the one kit that service runs on
 *   resolve(['kit_serial' => 'KIT…'])
 *                        → ['assigned' => true, 'crm_client_id' => 123, …]
 *
 * Each is an exact match on an indexed column. Given two identifiers that
 * disagree, resolve() returns nothing and says they disagree, because
 * picking one of two contradictory answers is guessing with extra steps.
 *
 * ── THE DATABASE DOES THE ENFORCING ─────────────────────────────────────
 *
 * Migration 068 carries partial unique indexes (live rows only) so a unit, a
 * service, and each Starlink identifier can each have at most one live
 * assignment — and triggers so a released assignment can never be edited or
 * deleted. The checks in this class produce readable errors; the database
 * produces them whatever else writes to it.
 */
final class EquipmentAssignment
{
    /** Identifiers that can resolve a kit back to a customer, in priority order. */
    public const KEYS = ['kit_serial', 'terminal_id', 'router_id', 'starlink_service_line'];

    private \PDO $db;

    public function __construct(\PDO $db) { $this->db = $db; }

    public static function fromStore($store): self { return new self($store->getPdo()); }

    // ── Assigning ───────────────────────────────────────────────────────────

    /**
     * Give a unit to a customer.
     *
     * @param array $data unit_id, crm_client_id, crm_service_id, starlink_account,
     *                    starlink_service_line, terminal_id, router_id, note
     * @param array $actor id, name
     *
     * @return array{ok:bool, id?:int, error?:string, assignment?:array}
     */
    public function assign(array $data, array $actor): array
    {
        $unitId   = (int)($data['unit_id'] ?? 0);
        $clientId = (int)($data['crm_client_id'] ?? 0);

        if ($unitId <= 0)   return ['ok' => false, 'error' => 'An assignment needs the stock unit it is for.'];
        // The defect this whole class exists to close: the install screens sent
        // parseInt(...) || 0, and a zero was written as NULL while the typed
        // customer NAME was kept. The unit then looked installed at a named
        // customer and was invisible to every lookup that matters.
        if ($clientId <= 0) return ['ok' => false, 'error' => 'An assignment needs a uCRM client id. A customer name is not an identity.'];

        $unit = $this->unit($unitId);
        if (!$unit) return ['ok' => false, 'error' => "There is no stock unit #{$unitId}."];

        $live = $this->activeForUnit($unitId);
        if ($live) {
            return ['ok' => false, 'error' => sprintf(
                'That unit is already assigned to client #%d (assignment #%d). Release it first.',
                (int)$live['crm_client_id'], (int)$live['id'])];
        }

        $serviceId = (int)($data['crm_service_id'] ?? 0) ?: null;
        if ($serviceId !== null) {
            $onService = $this->activeForService($serviceId);
            if ($onService) {
                return ['ok' => false, 'error' => sprintf(
                    'uCRM service #%d already runs on unit #%d (assignment #%d).',
                    $serviceId, (int)$onService['unit_id'], (int)$onService['id'])];
            }
        }

        $row = [
            'unit_id'               => $unitId,
            'crm_client_id'         => $clientId,
            'crm_service_id'        => $serviceId,
            // Inherited from the unit when the caller does not say. Which of
            // DishNet's Starlink accounts supplied a kit is recorded when it is
            // received; making somebody retype it at install is how it ends up
            // blank on half the fleet.
            'starlink_account'      => self::clean($data['starlink_account'] ?? '')
                                       ?: self::clean($unit['starlink_account'] ?? ''),
            'starlink_service_line' => self::clean($data['starlink_service_line'] ?? ''),
            'terminal_id'           => self::clean($data['terminal_id'] ?? ''),
            'router_id'             => self::routerId($data['router_id'] ?? ''),
            // Copied from the unit, never typed. The serial on the assignment
            // and the serial on the shelf are the same string by construction.
            'kit_serial'            => strtoupper(trim((string)($unit['serial_number'] ?? ''))),
            'note'                  => trim((string)($data['note'] ?? '')),
        ];

        // A Starlink identifier already live on another assignment means two
        // customers would resolve from one piece of hardware. The database
        // refuses it; this says which one and whose it is.
        foreach (self::KEYS as $k) {
            if ($row[$k] === '') continue;
            $clash = $this->liveBy($k, $row[$k]);
            if ($clash) {
                return ['ok' => false, 'error' => sprintf(
                    '%s %s is already live on assignment #%d (client #%d).',
                    $k, $row[$k], (int)$clash['id'], (int)$clash['crm_client_id'])];
            }
        }

        $now = date('Y-m-d H:i:s');
        try {
            $this->db->prepare(
                "INSERT INTO equipment_assignments
                 (unit_id, crm_client_id, crm_service_id, starlink_account,
                  starlink_service_line, terminal_id, router_id, kit_serial,
                  assigned_at, assigned_by, assigned_by_name, note, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $row['unit_id'], $row['crm_client_id'], $row['crm_service_id'],
                    $row['starlink_account'], $row['starlink_service_line'],
                    $row['terminal_id'], $row['router_id'], $row['kit_serial'],
                    $now, (int)($actor['id'] ?? 0) ?: null,
                    trim((string)($actor['name'] ?? '')), $row['note'], $now,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $id = (int)$this->db->lastInsertId();

        $this->mirrorToUnit($unitId);
        FinAudit::record($this->db, 'equipment_assignment', $id, 'create', $actor, null,
                         $this->get($id) ?? [], 'assigned to client #' . $clientId);

        return ['ok' => true, 'id' => $id, 'assignment' => $this->get($id)];
    }

    /**
     * Take a unit back.
     *
     * @param array $opts reason, replaced_by_unit_id
     * @return array{ok:bool, id?:int, error?:string}
     */
    public function release(int $unitId, array $opts, array $actor): array
    {
        $live = $this->activeForUnit($unitId);
        if (!$live) return ['ok' => false, 'error' => "Unit #{$unitId} has no live assignment."];

        $replacedBy = (int)($opts['replaced_by_unit_id'] ?? 0) ?: null;
        if ($replacedBy !== null && $replacedBy === $unitId) {
            return ['ok' => false, 'error' => 'A unit cannot replace itself.'];
        }

        $before = $this->get((int)$live['id']);
        try {
            $this->db->prepare(
                "UPDATE equipment_assignments
                 SET released_at = ?, released_reason = ?, released_by_name = ?,
                     replaced_by_unit_id = ?
                 WHERE id = ?")
                ->execute([
                    date('Y-m-d H:i:s'),
                    trim((string)($opts['reason'] ?? '')),
                    trim((string)($actor['name'] ?? '')),
                    $replacedBy, (int)$live['id'],
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $this->mirrorToUnit($unitId);
        FinAudit::record($this->db, 'equipment_assignment', (int)$live['id'], 'update', $actor,
                         $before, $this->get((int)$live['id']) ?? [],
                         'released' . (($opts['reason'] ?? '') !== '' ? ': ' . $opts['reason'] : ''));

        return ['ok' => true, 'id' => (int)$live['id']];
    }

    /**
     * Swap one kit for another at the same customer and service, keeping the
     * chain: the old assignment records which unit took over, the new one
     * carries on from it.
     */
    public function replaceUnit(int $oldUnitId, int $newUnitId, array $data, array $actor): array
    {
        $live = $this->activeForUnit($oldUnitId);
        if (!$live) return ['ok' => false, 'error' => "Unit #{$oldUnitId} has no live assignment to replace."];

        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $r = $this->release($oldUnitId, [
                'reason'              => trim((string)($data['reason'] ?? 'replaced')),
                'replaced_by_unit_id' => $newUnitId,
            ], $actor);
            if (empty($r['ok'])) { if ($own) $this->db->rollBack(); return $r; }

            // The replacement inherits the customer, the service and the
            // Starlink identifiers unless the caller says otherwise: the dish
            // changed, the subscription did not.
            $a = $this->assign([
                'unit_id'               => $newUnitId,
                'crm_client_id'         => (int)$live['crm_client_id'],
                'crm_service_id'        => $data['crm_service_id'] ?? (int)$live['crm_service_id'],
                'starlink_account'      => $data['starlink_account']      ?? $live['starlink_account'],
                'starlink_service_line' => $data['starlink_service_line'] ?? $live['starlink_service_line'],
                'terminal_id'           => $data['terminal_id']           ?? '',
                'router_id'             => $data['router_id']             ?? '',
                'note'                  => 'replaces unit #' . $oldUnitId,
            ], $actor);
            if (empty($a['ok'])) { if ($own) $this->db->rollBack(); return $a; }

            if ($own) $this->db->commit();
            return ['ok' => true, 'released' => (int)$r['id'], 'id' => (int)$a['id'],
                    'assignment' => $a['assignment']];
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── CRM → Starlink ──────────────────────────────────────────────────────

    /** Every kit this customer has right now. @return array<int,array<string,mixed>> */
    public function forClient(int $clientId): array
    {
        if ($clientId <= 0) return [];
        return $this->rows("SELECT * FROM equipment_assignments
                            WHERE crm_client_id = ? AND released_at IS NULL
                            ORDER BY id", [$clientId]);
    }

    /** The one kit this uCRM service runs on, or null. */
    public function forService(int $serviceId): ?array
    {
        if ($serviceId <= 0) return null;
        return $this->activeForService($serviceId);
    }

    /** Every kit this customer has ever had, newest first, released ones included. */
    public function historyForClient(int $clientId): array
    {
        return $this->rows("SELECT * FROM equipment_assignments
                            WHERE crm_client_id = ? ORDER BY id DESC", [$clientId]);
    }

    /** Everywhere this unit has ever been. */
    public function historyForUnit(int $unitId): array
    {
        return $this->rows("SELECT * FROM equipment_assignments
                            WHERE unit_id = ? ORDER BY id DESC", [$unitId]);
    }

    // ── Starlink → CRM ──────────────────────────────────────────────────────

    /**
     * Which customer owns the hardware these identifiers describe.
     *
     * Exact match on an indexed column, or nothing. Two identifiers that point
     * at different assignments produce nothing and say so: choosing between
     * two contradictory answers is guessing with extra steps, and the whole
     * point of this class is that the system stops guessing about who owns a
     * dish.
     *
     * @param array $ids kit_serial, terminal_id, router_id, starlink_service_line, unit_id
     * @return array{assigned:bool, crm_client_id?:int, crm_service_id?:int|null,
     *               assignment_id?:int, matched_on?:string, reason?:string}
     */
    public function resolve(array $ids): array
    {
        $hits = [];

        $unitId = (int)($ids['unit_id'] ?? 0);
        if ($unitId > 0) {
            $a = $this->activeForUnit($unitId);
            if ($a) $hits['unit_id'] = $a;
        }
        foreach (self::KEYS as $k) {
            $v = $k === 'router_id' ? self::routerId($ids[$k] ?? '') : self::clean($ids[$k] ?? '');
            // 'service_line' is what the data plugin's router map calls it.
            if ($v === '' && $k === 'starlink_service_line') $v = self::clean($ids['service_line'] ?? '');
            if ($v === '') continue;
            $a = $this->liveBy($k, $v);
            if ($a) $hits[$k] = $a;
        }

        if ($hits === []) {
            return ['assigned' => false,
                    'reason'   => 'No live equipment assignment matches any identifier given.'];
        }

        $distinct = array_unique(array_map(static fn(array $a): int => (int)$a['id'], $hits));
        if (count($distinct) > 1) {
            $detail = [];
            foreach ($hits as $k => $a) $detail[] = $k . ' → assignment #' . (int)$a['id'];
            return ['assigned' => false,
                    'reason'   => 'Identifiers disagree: ' . implode(', ', $detail)
                                . '. Nothing is returned rather than picking one.'];
        }

        $a = reset($hits);
        return [
            'assigned'       => true,
            'crm_client_id'  => (int)$a['crm_client_id'],
            'crm_service_id' => $a['crm_service_id'] === null ? null : (int)$a['crm_service_id'],
            'assignment_id'  => (int)$a['id'],
            'unit_id'        => (int)$a['unit_id'],
            'kit_serial'     => (string)$a['kit_serial'],
            'matched_on'     => (string)array_key_first($hits),
        ];
    }

    /** The kit serials a customer has right now — what blocking needs. @return string[] */
    public function kitSerialsForClient(int $clientId): array
    {
        $out = [];
        foreach ($this->forClient($clientId) as $a) {
            $s = strtoupper(trim((string)$a['kit_serial']));
            if ($s !== '') $out[] = $s;
        }
        return array_values(array_unique($out));
    }

    /** The kit serial a single uCRM service runs on — what per-service blocking needs. */
    public function kitSerialForService(int $serviceId): string
    {
        $a = $this->forService($serviceId);
        return $a ? strtoupper(trim((string)$a['kit_serial'])) : '';
    }

    /**
     * Every kit in the field right now, one row per assignment.
     *
     * Ordered by customer so a fleet list groups a customer's kits together.
     * Released rows are excluded here and nowhere else deletes them — the
     * history stays, it simply is not the fleet.
     *
     * @return array<int,array<string,mixed>>
     */
    public function liveAssignments(): array
    {
        return $this->rows("SELECT * FROM equipment_assignments
                            WHERE released_at IS NULL
                            ORDER BY crm_client_id, id", []);
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    public function get(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM equipment_assignments WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function activeForUnit(int $unitId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM equipment_assignments
                                  WHERE unit_id = ? AND released_at IS NULL LIMIT 1");
        $st->execute([$unitId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function activeForService(int $serviceId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM equipment_assignments
                                  WHERE crm_service_id = ? AND released_at IS NULL LIMIT 1");
        $st->execute([$serviceId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** A live assignment by one exact Starlink identifier. */
    private function liveBy(string $column, string $value): ?array
    {
        if (!in_array($column, self::KEYS, true) || $value === '') return null;
        $st = $this->db->prepare("SELECT * FROM equipment_assignments
                                  WHERE {$column} = ? AND released_at IS NULL LIMIT 1");
        $st->execute([$value]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Fill in Starlink identifiers learned after the assignment was made.
     *
     * A kit is usually installed before it appears in the router map — the
     * dish is on the roof and the router has not phoned home yet. This adds
     * what was missing WITHOUT touching what is already there: an identifier
     * that is already set is left alone, because silently repointing live
     * hardware at a different customer is the failure this class prevents.
     */
    public function addIdentifiers(int $assignmentId, array $ids, array $actor): array
    {
        $a = $this->get($assignmentId);
        if (!$a) return ['ok' => false, 'error' => "There is no assignment #{$assignmentId}."];
        if ($a['released_at'] !== null) return ['ok' => false, 'error' => 'That assignment is released.'];

        $set = []; $vals = []; $added = [];
        foreach (['starlink_account', 'starlink_service_line', 'terminal_id', 'router_id'] as $k) {
            if (!array_key_exists($k, $ids)) continue;
            $v = $k === 'router_id' ? self::routerId($ids[$k]) : self::clean($ids[$k]);
            if ($v === '' || trim((string)$a[$k]) !== '') continue;
            if (in_array($k, self::KEYS, true)) {
                $clash = $this->liveBy($k, $v);
                if ($clash) return ['ok' => false, 'error' => sprintf(
                    '%s %s is already live on assignment #%d.', $k, $v, (int)$clash['id'])];
            }
            $set[] = "{$k} = ?"; $vals[] = $v; $added[$k] = $v;
        }
        if ($set === []) return ['ok' => true, 'added' => []];

        $vals[] = $assignmentId;
        $this->db->prepare("UPDATE equipment_assignments SET " . implode(', ', $set) . " WHERE id = ?")
                 ->execute($vals);
        FinAudit::record($this->db, 'equipment_assignment', $assignmentId, 'update', $actor,
                         $a, $this->get($assignmentId) ?? [], 'Starlink identifiers learned');
        return ['ok' => true, 'added' => $added];
    }

    /**
     * Compare what an assignment says about the hardware with what Starlink
     * says, and report every disagreement.
     *
     * addIdentifiers() deliberately never overwrites a value that is already
     * there, because silently repointing live hardware at a different account
     * is the failure this class exists to prevent. But that leaves the other
     * half undone: a WRONG value, typed by a person who guessed, sits there
     * for ever and nothing says so. A conflict is not resolved by whoever
     * wrote last — it is reported.
     *
     * @param array $truth what Starlink's own data says: starlink_account,
     *                     starlink_service_line, terminal_id, router_id
     * @return array<int,array{field:string,ours:string,theirs:string}>
     */
    public function conflicts(int $assignmentId, array $truth): array
    {
        $a = $this->get($assignmentId);
        if (!$a || $a['released_at'] !== null) return [];

        $out = [];
        foreach (['starlink_account', 'starlink_service_line', 'terminal_id', 'router_id'] as $f) {
            if (!array_key_exists($f, $truth)) continue;
            $theirs = $f === 'router_id' ? self::routerId($truth[$f]) : self::clean($truth[$f]);
            $ours   = trim((string)$a[$f]);
            if ($theirs === '' || $ours === '' || strcasecmp($ours, $theirs) === 0) continue;
            $out[] = ['field' => $f, 'ours' => $ours, 'theirs' => $theirs];
        }
        return $out;
    }

    /**
     * Overwrite one identifier, on purpose, with a reason.
     *
     * The only path that may replace a non-empty identifier. It exists so a
     * value somebody typed can be corrected by Starlink's own answer — and it
     * is separate from addIdentifiers() so that correcting is always a
     * deliberate act with a name and a reason against it, never a side effect
     * of a routine sync.
     */
    public function correctIdentifier(int $assignmentId, string $field, string $value,
                                      array $actor, string $reason): array
    {
        $allowed = ['starlink_account', 'starlink_service_line', 'terminal_id', 'router_id'];
        if (!in_array($field, $allowed, true)) {
            return ['ok' => false, 'error' => "Cannot correct '{$field}'."];
        }
        $a = $this->get($assignmentId);
        if (!$a) return ['ok' => false, 'error' => "There is no assignment #{$assignmentId}."];
        if ($a['released_at'] !== null) return ['ok' => false, 'error' => 'That assignment is released.'];
        if (trim($reason) === '') return ['ok' => false, 'error' => 'A correction needs a reason.'];

        $value = $field === 'router_id' ? self::routerId($value) : self::clean($value);
        if ($value === '') return ['ok' => false, 'error' => 'A correction needs a value.'];

        if (in_array($field, self::KEYS, true)) {
            $clash = $this->liveBy($field, $value);
            if ($clash && (int)$clash['id'] !== $assignmentId) {
                return ['ok' => false, 'error' => sprintf(
                    '%s %s is already live on assignment #%d (client #%d).',
                    $field, $value, (int)$clash['id'], (int)$clash['crm_client_id'])];
            }
        }

        $was = (string)$a[$field];
        $this->db->prepare("UPDATE equipment_assignments SET {$field} = ? WHERE id = ?")
                 ->execute([$value, $assignmentId]);
        FinAudit::record($this->db, 'equipment_assignment', $assignmentId, 'update', $actor,
                         [$field => $was], [$field => $value], $reason);
        return ['ok' => true, 'was' => $was, 'now' => $value];
    }

    // ── Keeping stock_units honest ──────────────────────────────────────────

    /**
     * Copy the live assignment onto the unit.
     *
     * stock_units keeps crm_client_id and crm_service_id as CURRENT STATE only,
     * for the screens and queries that already read them. It is written here
     * and nowhere else, so it can never drift into being a second answer to
     * the same question.
     */
    public function mirrorToUnit(int $unitId): void
    {
        $a = $this->activeForUnit($unitId);
        if (!$a) {
            // A reservation is a hold, not ownership: reserve() puts a customer
            // on a unit before any assignment exists, and that hold lives on
            // stock_units by design. Clearing it here would quietly lose the
            // dish somebody has been promised.
            $u = $this->unit($unitId);
            if (($u['status'] ?? '') === 'reserved') return;
        }
        try {
            $this->db->prepare(
                "UPDATE stock_units SET crm_client_id = ?, crm_service_id = ?,
                        starlink_account = ?, updated_at = ? WHERE id = ?")
                ->execute([
                    $a ? (int)$a['crm_client_id'] : null,
                    $a && $a['crm_service_id'] !== null ? (int)$a['crm_service_id'] : null,
                    // Never blanked: the account is recorded at receipt, and an
                    // assignment that does not happen to carry one must not
                    // erase it. Writing '' here lost it on every install.
                    $a && (string)$a['starlink_account'] !== ''
                        ? (string)$a['starlink_account']
                        : (string)($this->unit($unitId)['starlink_account'] ?? ''),
                    date('Y-m-d H:i:s'), $unitId,
                ]);
        } catch (\Throwable $e) { /* no stock tables on this install */ }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function unit(int $id): ?array
    {
        try {
            $st = $this->db->prepare("SELECT * FROM stock_units WHERE id = ?");
            $st->execute([$id]);
            return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) { return null; }
    }

    private function rows(string $sql, array $p): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Identifiers are compared exactly, so they are stored exactly once one way. */
    public static function clean($v): string { return strtoupper(trim((string)$v)); }

    /**
     * The data plugin writes routers both ways — "Router-abc123" as a key and
     * "abc123" in the field. Stored without the prefix so one router is one
     * string, whichever side asks.
     */
    public static function routerId($v): string
    {
        $v = trim((string)$v);
        if (stripos($v, 'Router-') === 0) $v = substr($v, 7);
        return strtoupper(trim($v));
    }
}

<?php
declare(strict_types=1);

/**
 * DistributorAttribution — territory + the customer/lead -> distributor owner
 * link (migration 079, WS-A P2, docs/49). Local only; no uCRM, no messages.
 *
 * Territory is data: a distributor has named regions (dist_regions), and each
 * region covers one or more areas (dist_territory_map, keyed by a normalised
 * area token). The resolver PROPOSES a candidate distributor from a customer's
 * location; a human confirms the link (the pilot rule, docs/49 §6.4). Nothing
 * here auto-attributes a real customer.
 *
 * Hard rules (docs/49 §6):
 *   - NEVER by phone. There is no phone anywhere in this class or its tables;
 *     a link is keyed by the uCRM client id or the lead id.
 *   - ONE OWNER AT A TIME. link() supersedes the current active link (kept as
 *     history) and inserts the new one; a relink is audited, never a silent
 *     overwrite. The partial unique index is the floor.
 *   - ONE DISTRIBUTOR PER AREA. area_key is globally unique, so a territory
 *     resolve returns at most one candidate; anything else fails safe (null).
 */
class DistributorAttribution
{
    public const SCOPES = ['ucrm_client', 'lead'];
    public const VIAS   = ['territory', 'manual', 'application'];

    private \PDO $db;

    public function __construct(\PDO $db) { $this->db = $db; }

    public static function fromStore($store): self { return new self($store->getPdo()); }

    public function getDb(): \PDO { return $this->db; }

    /** Normalise a location/area string to a stable match key: lower, alnum+space, collapsed. '' if empty. */
    public static function normArea($s): string
    {
        $s = is_scalar($s) ? (string)$s : '';
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? '';
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        return $s;
    }

    private static function scrub($v, int $max): string
    {
        $v = is_scalar($v) ? (string)$v : '';
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        return mb_substr(trim($v), 0, $max);
    }

    private function partnerExists(int $partnerId): bool
    {
        $st = $this->db->prepare("SELECT 1 FROM dist_partners WHERE id = ?");
        $st->execute([$partnerId]);
        return $st->fetchColumn() !== false;
    }

    // ── Territory ───────────────────────────────────────────────────────────

    /** Add a named region to a partner. @return array{id:int} @throws on bad partner or duplicate code. */
    public function addRegion(int $partnerId, string $code, string $name, string $actor): array
    {
        if (!$this->partnerExists($partnerId)) throw new \RuntimeException('Partner not found.');
        $code = self::scrub($code, 40);
        $name = self::scrub($name, 160);
        if ($code === '') throw new \RuntimeException('A region code is required.');
        try {
            $st = $this->db->prepare(
                "INSERT INTO dist_regions (partner_id, code, name, created_by, created_at)
                 VALUES (?,?,?,?, datetime('now'))"
            );
            $st->execute([$partnerId, $code, $name, self::scrub($actor, 160)]);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'unique') !== false) throw new \RuntimeException('That region code already exists for this partner.');
            throw $e;
        }
        return ['id' => (int)$this->db->lastInsertId()];
    }

    /**
     * Map an area/district to a region (hence its partner). One distributor per
     * area: an area already owned by a DIFFERENT partner is refused (conflict for
     * review); the same partner re-adding it is an idempotent no-op.
     * @return array{id:int, area_key:string, added:bool}
     */
    public function addArea(int $regionId, string $areaText, string $actor): array
    {
        $r = $this->db->prepare("SELECT id, partner_id FROM dist_regions WHERE id = ?");
        $r->execute([$regionId]);
        $region = $r->fetch(\PDO::FETCH_ASSOC);
        if (!$region) throw new \RuntimeException('Region not found.');
        $partnerId = (int)$region['partner_id'];

        $areaKey = self::normArea($areaText);
        if ($areaKey === '') throw new \RuntimeException('An area/district is required.');

        $existing = $this->db->prepare("SELECT id, partner_id, region_id FROM dist_territory_map WHERE area_key = ?");
        $existing->execute([$areaKey]);
        $row = $existing->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            if ((int)$row['partner_id'] === $partnerId) {
                return ['id' => (int)$row['id'], 'area_key' => $areaKey, 'added' => false]; // idempotent
            }
            throw new \RuntimeException('Area "' . $areaText . '" is already a different distributor\'s territory. One area has one distributor; resolve in review.');
        }
        try {
            $st = $this->db->prepare(
                "INSERT INTO dist_territory_map (region_id, partner_id, area_key, area_label, created_by, created_at)
                 VALUES (?,?,?,?,?, datetime('now'))"
            );
            $st->execute([$regionId, $partnerId, $areaKey, self::scrub($areaText, 120), self::scrub($actor, 160)]);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'unique') !== false) throw new \RuntimeException('That area is already mapped (conflict).');
            throw $e;
        }
        return ['id' => (int)$this->db->lastInsertId(), 'area_key' => $areaKey, 'added' => true];
    }

    /**
     * PROPOSE the distributor whose territory covers a location. Returns the
     * candidate or null — never a guess, never by phone. At most one by the
     * unique area_key; a defensive >1 check returns null ('ambiguous').
     * @return array{partner_id:int, region_id:int, area_label:string, area_key:string}|null
     */
    public function candidateFor(string $locationText): ?array
    {
        $areaKey = self::normArea($locationText);
        if ($areaKey === '') return null;
        $st = $this->db->prepare("SELECT partner_id, region_id, area_label FROM dist_territory_map WHERE area_key = ?");
        $st->execute([$areaKey]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if (count($rows) !== 1) return null; // 0 = no territory; >1 = ambiguous, fail safe
        return [
            'partner_id' => (int)$rows[0]['partner_id'],
            'region_id'  => (int)$rows[0]['region_id'],
            'area_label' => (string)$rows[0]['area_label'],
            'area_key'   => $areaKey,
        ];
    }

    // ── Attribution ──────────────────────────────────────────────────────────

    /** The current owner of a customer/lead, or null. */
    public function activeLink(string $scope, string $entityId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM dist_customer_links WHERE scope = ? AND entity_id = ? AND active = 1");
        $st->execute([$scope, $entityId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Attribute a customer/lead to a distributor (human-confirmed). Supersedes
     * the current active link (kept as history) and records the new one. A
     * relink to the SAME partner is an idempotent no-op. NEVER by phone.
     *
     * @return array{linked:bool, relinked:bool, partner_id:int, reason?:string}
     * @throws on a bad scope/via, a missing partner, or an empty entity id.
     */
    public function link(string $scope, string $entityId, int $partnerId, string $via, string $actor, string $note = '', string $source = ''): array
    {
        if (!in_array($scope, self::SCOPES, true)) throw new \RuntimeException('Unknown scope.');
        if (!in_array($via, self::VIAS, true)) throw new \RuntimeException('Unknown assignment basis.');
        $entityId = self::scrub($entityId, 120);
        if ($entityId === '') throw new \RuntimeException('A customer/lead id is required.');
        if (!$this->partnerExists($partnerId)) throw new \RuntimeException('Partner not found.');

        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $current = $this->activeLink($scope, $entityId);
            if ($current && (int)$current['partner_id'] === $partnerId) {
                if ($own) $this->db->commit();
                return ['linked' => false, 'relinked' => false, 'partner_id' => $partnerId, 'reason' => 'unchanged'];
            }
            $relinked = false;
            if ($current) {
                $this->db->prepare("UPDATE dist_customer_links SET active = 0, superseded_at = datetime('now') WHERE id = ?")
                         ->execute([(int)$current['id']]);
                $relinked = true;
            }
            $this->db->prepare(
                "INSERT INTO dist_customer_links (scope, entity_id, partner_id, assigned_via, source, note, active, assigned_by, assigned_at)
                 VALUES (?,?,?,?,?,?,1,?, datetime('now'))"
            )->execute([$scope, $entityId, $partnerId, $via, self::scrub($source, 80), self::scrub($note, 500), self::scrub($actor, 160)]);
            if ($own) $this->db->commit();
            return ['linked' => true, 'relinked' => $relinked, 'partner_id' => $partnerId];
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof \PDOException && stripos($e->getMessage(), 'unique') !== false) {
                throw new \RuntimeException('That customer/lead already has an active owner (conflict). Nothing was changed.');
            }
            throw $e;
        }
    }

    /** Full attribution history for a customer/lead, newest first. */
    public function history(string $scope, string $entityId): array
    {
        $st = $this->db->prepare("SELECT * FROM dist_customer_links WHERE scope = ? AND entity_id = ? ORDER BY id DESC");
        $st->execute([$scope, $entityId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array> active links owned by a partner */
    public function linksForPartner(int $partnerId, bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM dist_customer_links WHERE partner_id = ?" . ($activeOnly ? " AND active = 1" : "") . " ORDER BY id DESC";
        $st = $this->db->prepare($sql);
        $st->execute([$partnerId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array> the territory map, with region + partner, for display */
    public function territory(?int $partnerId = null): array
    {
        if ($partnerId !== null) {
            $st = $this->db->prepare(
                "SELECT t.*, r.code region_code, r.name region_name FROM dist_territory_map t
                 JOIN dist_regions r ON r.id = t.region_id WHERE t.partner_id = ? ORDER BY t.area_key"
            );
            $st->execute([$partnerId]);
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
        return $this->db->query(
            "SELECT t.*, r.code region_code, r.name region_name FROM dist_territory_map t
             JOIN dist_regions r ON r.id = t.region_id ORDER BY t.area_key"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array> a partner's regions */
    public function regionsFor(int $partnerId): array
    {
        $st = $this->db->prepare("SELECT * FROM dist_regions WHERE partner_id = ? ORDER BY code");
        $st->execute([$partnerId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}

<?php
declare(strict_types=1);

/**
 * DistributorRegistry — the appointed-distributor entity (migration 078,
 * tables `dist_partners` + `dist_appointment`). WS-A Phase 1a, docs/49.
 *
 * Scope boundary (docs/49 §15.1): this is a LOCAL record only. It NEVER calls
 * uCRM, creates no uCRM client, grants no account/login/wallet/portal access,
 * and sends no message. Linking a uCRM company client is a separate, later step
 * (P1b). Appointing is a deliberate DishNet-admin action; the actor is passed in
 * from the identity boundary (never read from a request field), exactly as the
 * rest of the plugin's audited writes do.
 *
 * Dedupe rule (docs/47 §9.1): a partner is deduped by normalised TIN (unique
 * where present) and uCRM client id (unique where linked) — NEVER by phone.
 * There is no phone column; two applications sharing a contact phone appoint to
 * two distinct partners. This is enforced structurally, not by convention.
 *
 * The class only reads/writes its own two tables (and READS, never writes,
 * dist_partner_applications to seed an appointment).
 */
class DistributorRegistry
{
    public const PARTNER_TYPES = [
        'corporate_retail'     => 'Corporate / chain retail',
        'authorised_reseller'  => 'Authorised reseller',
        'regional_distributor' => 'Regional distributor',
        'wholesale_customer'   => 'Wholesale customer',
    ];
    public const CATEGORIES = [
        'fuel_station' => 'Fuel station',
        'supermarket'  => 'Supermarket',
        'electronics'  => 'Electronics',
        'distributor'  => 'Distributor',
        'other'        => 'Other',
    ];
    /** Lifecycle (docs/47 §9.2). Stock can move only while 'active' — enforced later, not here. */
    public const STATUSES = ['prospect', 'onboarding', 'active', 'suspended', 'terminated'];

    private \PDO $db;

    public function __construct(\PDO $db) { $this->db = $db; }

    public static function fromStore($store): self { return new self($store->getPdo()); }

    public function getDb(): \PDO { return $this->db; }

    /** A partner code derived from the row id: 'DP-00001'. Assigned once, never reused. */
    public static function code(int $id): string
    {
        return 'DP-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    }

    /** Normalise a TIN for dedupe: keep alphanumerics, upper-case. '' when empty. */
    public static function normTin($tin): string
    {
        $t = is_scalar($tin) ? strtoupper((string)$tin) : '';
        return preg_replace('/[^A-Z0-9]/', '', $t) ?? '';
    }

    private static function scrub($v, int $max): string
    {
        $v = is_scalar($v) ? (string)$v : '';
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        return mb_substr(trim($v), 0, $max);
    }

    /**
     * Create one partner (local record only). $fields keys: partner_type,
     * category, status, legal_name, trading_name, tin, registration_no,
     * trading_currency, account_manager_staff_id. There is NO phone parameter
     * and NO uCRM parameter — a prospect carries neither.
     *
     * Dedupe: a non-empty TIN that already exists is REFUSED (never an upsert),
     * mirroring the phone-uniqueness-is-a-refusal discipline elsewhere.
     *
     * @return array{id:int, partner_code:string}
     * @throws \RuntimeException on a duplicate TIN or an invalid enum value.
     */
    public function create(array $fields, string $actor): array
    {
        $type = self::scrub($fields['partner_type'] ?? '', 40);
        if ($type !== '' && !isset(self::PARTNER_TYPES[$type])) throw new \RuntimeException('Unknown partner type.');
        $cat = self::scrub($fields['category'] ?? '', 40);
        if ($cat !== '' && !isset(self::CATEGORIES[$cat])) throw new \RuntimeException('Unknown category.');
        $status = self::scrub($fields['status'] ?? 'prospect', 20);
        if (!in_array($status, self::STATUSES, true)) throw new \RuntimeException('Unknown status.');

        $tin     = self::scrub($fields['tin'] ?? '', 60);
        $tinNorm = self::normTin($tin);
        if ($tinNorm !== '' && $this->findByTinNorm($tinNorm) !== null) {
            throw new \RuntimeException('A partner with this TIN already exists.');
        }

        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            // Insert with a unique temporary code (satisfies NOT NULL UNIQUE),
            // then stamp the id-derived code. No race: the id is ours.
            $tmp = 'TMP-' . bin2hex(random_bytes(6));
            $st = $this->db->prepare(
                "INSERT INTO dist_partners
                 (partner_code, partner_type, category, status, legal_name, trading_name,
                  tin, tin_norm, registration_no, trading_currency, account_manager_staff_id,
                  created_by, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?, datetime('now'), datetime('now'))"
            );
            $st->execute([
                $tmp, $type, $cat, $status,
                self::scrub($fields['legal_name'] ?? '', 200),
                self::scrub($fields['trading_name'] ?? '', 200),
                $tin, $tinNorm,
                self::scrub($fields['registration_no'] ?? '', 80),
                self::scrub($fields['trading_currency'] ?? 'UGX', 8),
                isset($fields['account_manager_staff_id']) && $fields['account_manager_staff_id'] !== ''
                    ? (int)$fields['account_manager_staff_id'] : null,
                self::scrub($actor, 160),
            ]);
            $id = (int)$this->db->lastInsertId();
            $code = self::code($id);
            $this->db->prepare("UPDATE dist_partners SET partner_code = ? WHERE id = ?")->execute([$code, $id]);
            if ($own) $this->db->commit();
            return ['id' => $id, 'partner_code' => $code];
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Appoint a distributor FROM a recruitment application (DNP-…). Creates a
     * 'prospect' partner seeded from the application and records provenance in
     * dist_appointment. LOCAL only — no uCRM, no account. Phone is NOT consulted,
     * so two applications sharing a phone yield two distinct partners.
     *
     * @return array{partner_id:int, partner_code:string, appointment_id:int}
     * @throws \RuntimeException if the application is missing or already appointed.
     */
    public function appointFromApplication(int $appId, array $overrides, string $actor): array
    {
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $app = $this->db->prepare("SELECT * FROM dist_partner_applications WHERE id = ?");
            $app->execute([$appId]);
            $row = $app->fetch(\PDO::FETCH_ASSOC);
            if (!$row) throw new \RuntimeException('Application not found.');

            $seen = $this->db->prepare("SELECT partner_id FROM dist_appointment WHERE application_id = ?");
            $seen->execute([$appId]);
            if ($seen->fetchColumn() !== false) throw new \RuntimeException('This application has already been appointed.');

            $created = $this->create([
                'partner_type'  => self::scrub($overrides['partner_type'] ?? '', 40),
                'category'      => self::scrub($overrides['category'] ?? '', 40),
                'status'        => 'prospect',
                'legal_name'    => self::scrub($overrides['legal_name'] ?? ($row['business_name'] ?? ''), 200),
                'trading_name'  => self::scrub($overrides['trading_name'] ?? ($row['trading_name'] ?? ''), 200),
                // tin / uCRM deliberately empty here: the uCRM company client is linked in P1b.
            ], $actor);

            $ins = $this->db->prepare(
                "INSERT INTO dist_appointment (application_id, partner_id, appointed_by, note, appointed_at)
                 VALUES (?,?,?,?, datetime('now'))"
            );
            $ins->execute([$appId, $created['id'], self::scrub($actor, 160), self::scrub($overrides['note'] ?? '', 500)]);
            $apptId = (int)$this->db->lastInsertId();

            if ($own) $this->db->commit();
            return ['partner_id' => $created['id'], 'partner_code' => $created['partner_code'], 'appointment_id' => $apptId];
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Link an appointed partner to an EXISTING uCRM company client (WS-A P1b).
     *
     * This reads the uCRM client to verify and cache it; it NEVER creates or
     * modifies a uCRM record. $crm is any CrmApiClient (a fake in tests). The
     * only live uCRM dependency in the whole distributor feature is this one
     * read, and it is an administrative, off-the-operating-path action.
     *
     * Identity + dedupe (docs/47 §9.1, docs/49): the client must be a COMPANY
     * (clientType 2 / companyName present); dedupe is by uCRM client id and
     * normalised TIN, **never by phone**. A uCRM id already linked to another
     * partner, or a TIN another partner holds, is REFUSED and flagged for
     * review — never silently merged or re-pointed. The partial unique indexes
     * on ucrm_client_id and tin_norm are the floor beneath these checks.
     *
     * @return array{linked:bool, ucrm_client_id:int, legal_name?:string, tin?:string, reason?:string}
     * @throws \RuntimeException on any conflict, a non-company client, a missing
     *         identity, or a uCRM read error/timeout.
     */
    public function linkUcrmClient(int $partnerId, int $ucrmClientId, $crm, string $actor): array
    {
        $partner = $this->get($partnerId);
        if (!$partner) throw new \RuntimeException('Partner not found.');
        if ($ucrmClientId <= 0) throw new \RuntimeException('A valid uCRM client id is required.');

        // Already linked? Same id is an idempotent no-op; a different id is refused
        // (re-linking is a separate, audited action, not part of P1b).
        if (!empty($partner['ucrm_client_id'])) {
            if ((int)$partner['ucrm_client_id'] === $ucrmClientId) {
                return ['linked' => false, 'reason' => 'already_linked', 'ucrm_client_id' => $ucrmClientId];
            }
            throw new \RuntimeException('This partner is already linked to uCRM client #'
                . (int)$partner['ucrm_client_id'] . '. Unlinking/relinking is a separate, audited action.');
        }

        // uCRM id already owned by another partner? Conflict — never steal it.
        $other = $this->findByUcrmClientId($ucrmClientId);
        if ($other && (int)$other['id'] !== $partnerId) {
            throw new \RuntimeException('uCRM client #' . $ucrmClientId . ' is already linked to partner '
                . $other['partner_code'] . '. Conflicts are flagged for review, never merged.');
        }

        // The one live uCRM read. In development there is no uCRM, so this refuses
        // loudly rather than guessing.
        if (!is_object($crm) || !method_exists($crm, 'isConfigured') || !$crm->isConfigured()) {
            throw new \RuntimeException('uCRM is not configured on this install.');
        }
        $client = $crm->get('clients/' . $ucrmClientId);
        if ($client === null) {
            $err = method_exists($crm, 'getLastError') ? (string)(($crm->getLastError()['message'] ?? '')) : '';
            throw new \RuntimeException('Could not read uCRM client #' . $ucrmClientId
                . ' — a uCRM error or timeout' . ($err !== '' ? ': ' . $err : '') . '. Nothing was changed.');
        }
        if (!is_array($client) || empty($client)) {
            throw new \RuntimeException('uCRM client #' . $ucrmClientId . ' was not found.');
        }

        // Must be a company (legal entity), never an individual. docs/47 §9.1.
        $isCompany = ((int)($client['clientType'] ?? 0) === 2) || !empty($client['companyName']);
        if (!$isCompany) {
            throw new \RuntimeException('uCRM client #' . $ucrmClientId
                . ' is an individual, not a company. A distributor is a legal entity.');
        }
        $companyName = self::scrub($client['companyName'] ?? '', 200);
        if ($companyName === '') {
            throw new \RuntimeException('uCRM client #' . $ucrmClientId . ' has no company name — its legal identity is incomplete.');
        }

        // TIN coherence + dedupe (never phone): a normalised TIN another partner
        // holds is a conflict for review, not a merge.
        $tin = self::scrub($client['companyTaxId'] ?? '', 60);
        $tinNorm = self::normTin($tin);
        if ($tinNorm !== '') {
            $tinOwner = $this->findByTinNorm($tinNorm);
            if ($tinOwner && (int)$tinOwner['id'] !== $partnerId) {
                throw new \RuntimeException('Another partner (' . $tinOwner['partner_code']
                    . ') already holds this TIN. Conflicts are flagged for review, never merged.');
            }
        }
        $regNo = self::scrub($client['companyRegistrationNumber'] ?? '', 80);

        // Link + cache uCRM's company fields (uCRM is now the master; this is a
        // read-only local copy). The unique indexes are the floor: a race that
        // slips past the pre-checks fails here and is reported as a conflict.
        try {
            $st = $this->db->prepare(
                "UPDATE dist_partners
                    SET ucrm_client_id = ?, ucrm_linked_by = ?, ucrm_linked_at = datetime('now'),
                        legal_name = ?, tin = ?, tin_norm = ?, registration_no = ?, updated_at = datetime('now')
                  WHERE id = ?"
            );
            $st->execute([$ucrmClientId, self::scrub($actor, 160), $companyName, $tin, $tinNorm, $regNo, $partnerId]);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'unique') !== false) {
                throw new \RuntimeException('That uCRM client id or TIN is already linked to another partner (conflict). Nothing was changed.');
            }
            throw $e;
        }

        return ['linked' => true, 'ucrm_client_id' => $ucrmClientId, 'legal_name' => $companyName, 'tin' => $tin];
    }

    public function setStatus(int $id, string $status, string $actor): bool
    {
        if (!in_array($status, self::STATUSES, true)) throw new \RuntimeException('Unknown status.');
        $st = $this->db->prepare("UPDATE dist_partners SET status = ?, updated_at = datetime('now') WHERE id = ?");
        $st->execute([$status, $id]);
        return $st->rowCount() > 0;
    }

    public function get(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM dist_partners WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByTinNorm(string $tinNorm): ?array
    {
        if ($tinNorm === '') return null;
        $st = $this->db->prepare("SELECT * FROM dist_partners WHERE tin_norm = ?");
        $st->execute([$tinNorm]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByUcrmClientId(int $ucrmId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM dist_partners WHERE ucrm_client_id = ?");
        $st->execute([$ucrmId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<int,array> newest first */
    public function listAll(int $limit = 500, int $offset = 0): array
    {
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $st = $this->db->prepare("SELECT * FROM dist_partners ORDER BY id DESC LIMIT ? OFFSET ?");
        $st->execute([$limit, $offset]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Application ids that have already been appointed (for the review UI). @return array<int,bool> */
    public function appointedApplicationIds(): array
    {
        $rows = $this->db->query("SELECT application_id FROM dist_appointment")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        $out = [];
        foreach ($rows as $a) $out[(int)$a] = true;
        return $out;
    }

    /** @return array<string,int> status => count, plus total */
    public function counts(): array
    {
        $out = ['total' => 0];
        foreach (self::STATUSES as $s) $out[$s] = 0;
        $rows = $this->db->query("SELECT status, COUNT(*) c FROM dist_partners GROUP BY status")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) { $out[(string)$r['status']] = (int)$r['c']; $out['total'] += (int)$r['c']; }
        return $out;
    }
}

<?php
declare(strict_types=1);

/**
 * DistributorApplicationService — storage + validation for public distributor
 * recruitment applications (migration 077, table `dist_partner_applications`).
 *
 * Scope boundary (root docs/47, docs/48): this captures an EXPRESSION OF
 * INTEREST only. It never creates a uCRM client, a partner record, a service,
 * a site or any account; it never calls uCRM at all. Appointing a partner is a
 * separate DishNet staff action. The class only reads/writes its own table.
 *
 * normalise() is the server-side gate. The public endpoint never trusts the
 * browser: labels are DERIVED from ids here, every field is length-capped and
 * stripped of control characters, and unknown models/services/activities are
 * rejected or dropped. It is pure (no I/O) so it is unit-testable on its own.
 */
class DistributorApplicationService
{
    private \PDO $db;
    private string $dataDir;

    /** Canonical vocabularies — the only values accepted. Labels derived here. */
    public const MODELS = [
        'corporate' => 'Corporate or chain partner',
        'regional'  => 'Regional distributor',
        'retail'    => 'Retail outlet or reseller',
        'referral'  => 'Referral or sales partner',
    ];
    public const SERVICES = [
        'starlink' => 'Starlink',
        'data'     => 'Data Network',
        'fibre'    => 'Fibre & fixed connectivity',
        'business' => 'Business connectivity solutions',
    ];
    public const ACTIVITIES = [
        'refer'         => 'Refer customers to DishNet',
        'sell_services' => 'Sell DishNet services',
        'sell_hardware' => 'Sell or distribute hardware',
        'outlet'        => 'Operate a customer-facing outlet',
        'first_line'    => 'Provide first-line customer assistance',
        'stock'         => 'Hold or manage stock',
    ];
    public const STATUSES = ['received', 'reviewing', 'contacted', 'closed'];

    public function __construct(\PDO $db, string $dataDir)
    {
        $this->db = $db;
        $this->dataDir = $dataDir;
    }

    public static function fromStore($store, string $dataDir): self
    {
        return new self($store->getPdo(), $dataDir);
    }

    public function getDb(): \PDO { return $this->db; }

    /** A human reference derived from the row id. Stored nowhere; always 'DNP-'+id. */
    public static function ref(int $id): string
    {
        return 'DNP-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    }

    /** Trim, strip control chars (keep tab/newline), cap length. */
    private static function scrub($v, int $max): string
    {
        $v = is_scalar($v) ? (string)$v : '';
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
        $v = trim($v);
        return mb_substr($v, 0, $max);
    }

    /**
     * Validate + sanitise a raw submission (as the website posts it).
     * Returns ['ok'=>bool, 'errors'=>string[], 'clean'=>array].
     * Pure: no I/O, so it is directly testable.
     */
    public static function normalise(array $p): array
    {
        $errors = [];
        $model = self::scrub($p['model'] ?? '', 40);
        if (!isset(self::MODELS[$model])) { $errors[] = 'model'; $model = ''; }

        $pf = is_array($p['profile'] ?? null) ? $p['profile'] : [];
        $business_name = self::scrub($pf['biz_name'] ?? '', 200);
        $contact_name  = self::scrub($pf['contact_name'] ?? '', 200);
        $email         = self::scrub($pf['email'] ?? '', 160);
        $phone         = self::scrub($pf['phone'] ?? '', 40);
        $city          = self::scrub($pf['city'] ?? '', 120);

        if ($business_name === '') $errors[] = 'business_name';
        if ($contact_name === '')  $errors[] = 'contact_name';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'email';
        if (preg_replace('/\D+/', '', $phone) === '' || strlen(preg_replace('/\D+/', '', $phone)) < 9) $errors[] = 'phone';
        if ($city === '') $errors[] = 'city';
        if (empty($p['consent'])) $errors[] = 'consent';

        // Services / activities: accept ids only, from the canonical set.
        $services = [];
        foreach ((array)($p['services'] ?? []) as $s) {
            $s = self::scrub($s, 40);
            if (isset(self::SERVICES[$s]) && !in_array($s, $services, true)) $services[] = $s;
        }
        if (!$services) $errors[] = 'services';
        $activities = [];
        foreach ((array)($p['activities'] ?? []) as $a) {
            $a = self::scrub($a, 40);
            if (isset(self::ACTIVITIES[$a]) && !in_array($a, $activities, true)) $activities[] = $a;
        }
        if (!$activities) $errors[] = 'activities';

        // Bounded free-form JSON blobs (coverage / readiness / training), kept as given.
        $coverage  = self::boundedJson($p['coverage'] ?? [], 6000);
        $readiness = self::boundedJson($p['readiness'] ?? [], 3000);
        $training  = self::boundedJson($p['training'] ?? [], 8000);

        $clean = [
            'source'        => self::scrub($p['source'] ?? 'website', 80),
            'partner_model' => $model,
            'model_label'   => $model !== '' ? self::MODELS[$model] : '',
            'business_name' => $business_name,
            'trading_name'  => self::scrub($pf['trading_name'] ?? '', 200),
            'business_type' => self::scrub($pf['biz_type'] ?? '', 60),
            'contact_name'  => $contact_name,
            'contact_role'  => self::scrub($pf['contact_role'] ?? '', 120),
            'email'         => $email,
            'phone'         => $phone,
            'city'          => $city,
            'website'       => self::scrub($pf['website'] ?? '', 300),
            'description'   => self::scrub($pf['desc'] ?? '', 4000),
            'operating'     => self::scrub($pf['operating'] ?? '', 20),
            'services'      => json_encode(array_map(fn($s) => self::SERVICES[$s], $services), JSON_UNESCAPED_UNICODE),
            'activities'    => json_encode(array_map(fn($a) => self::ACTIVITIES[$a], $activities), JSON_UNESCAPED_UNICODE),
            'service_ids'   => $services,
            'activity_ids'  => $activities,
            'coverage'      => $coverage,
            'readiness'     => $readiness,
            'training'      => $training,
            'consent'       => empty($p['consent']) ? 0 : 1,
        ];
        return ['ok' => count($errors) === 0, 'errors' => $errors, 'clean' => $clean];
    }

    private static function boundedJson($v, int $max): string
    {
        if (!is_array($v)) return '';
        $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($j)) return '';
        return mb_substr($j, 0, $max);
    }

    /**
     * Insert one application. $clean is the output of normalise()['clean'];
     * $meta carries request context (ip, user_agent, raw_json).
     * Returns ['id'=>int, 'ref'=>string].
     */
    public function create(array $clean, array $meta = []): array
    {
        $cols = ['source','partner_model','model_label','business_name','trading_name','business_type',
                 'contact_name','contact_role','email','phone','city','website','description','operating',
                 'services','activities','coverage','readiness','training','consent','ip','user_agent','raw_json'];
        $vals = [
            $clean['source'] ?? '', $clean['partner_model'] ?? '', $clean['model_label'] ?? '',
            $clean['business_name'] ?? '', $clean['trading_name'] ?? '', $clean['business_type'] ?? '',
            $clean['contact_name'] ?? '', $clean['contact_role'] ?? '', $clean['email'] ?? '',
            $clean['phone'] ?? '', $clean['city'] ?? '', $clean['website'] ?? '',
            $clean['description'] ?? '', $clean['operating'] ?? '',
            $clean['services'] ?? '', $clean['activities'] ?? '', $clean['coverage'] ?? '',
            $clean['readiness'] ?? '', $clean['training'] ?? '', (int)($clean['consent'] ?? 0),
            self::scrub($meta['ip'] ?? '', 64), self::scrub($meta['user_agent'] ?? '', 300),
            mb_substr((string)($meta['raw_json'] ?? ''), 0, 20000),
        ];
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $this->db->prepare("INSERT INTO dist_partner_applications (" . implode(',', $cols) . ") VALUES ($ph)")
                 ->execute($vals);
        $id = (int)$this->db->lastInsertId();
        return ['id' => $id, 'ref' => self::ref($id)];
    }

    /** @return array{items: array<int,array>, total: int} */
    public function listRecent(int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $total = (int)$this->db->query("SELECT COUNT(*) FROM dist_partner_applications")->fetchColumn();
        $st = $this->db->prepare("SELECT * FROM dist_partner_applications ORDER BY id DESC LIMIT ? OFFSET ?");
        $st->execute([$limit, $offset]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) { $r['ref'] = self::ref((int)$r['id']); }
        return ['items' => $rows, 'total' => $total];
    }

    public function get(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM dist_partner_applications WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['ref'] = self::ref((int)$row['id']);
        return $row;
    }

    /** @return array<string,int> status => count */
    public function counts(): array
    {
        $out = ['total' => 0];
        foreach (self::STATUSES as $s) $out[$s] = 0;
        $rows = $this->db->query("SELECT status, COUNT(*) c FROM dist_partner_applications GROUP BY status")
                         ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $out[(string)$r['status']] = (int)$r['c'];
            $out['total'] += (int)$r['c'];
        }
        return $out;
    }
}

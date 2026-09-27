<?php
declare(strict_types=1);
/**
 * ClientSearchIndex — the one row builder for the customer index table
 * (Phase 2 of the customer-login audit, plan §E.6).
 *
 * client_search_index is a structured SQLite table (migration 054, columns
 * added by 074). Before this class three code paths rebuilt a JSON copy that
 * SqliteStore::save() silently discards for structured tables, and only
 * cron_sync.php wrote the table — with six columns. Now cron_sync and the
 * webhook's client.add / client.edit go through here, and the row carries
 * the e-mail identifier and the eligibility facts the sign-in gates read:
 *
 *   is_lead, is_archived, is_active, client_type   from the uCRM client object
 *   has_service                                     from ucrm_services_cache
 *   has_invoice                                     from ucrm_invoices_cache
 *
 * NULL means "unknown" and never refuses anyone.
 */
final class ClientSearchIndex
{
    public const TABLE = 'client_search_index';
    public const BASE_COLUMNS = ['id', 'name', 'phone', 'phone_norm', 'service'];
    public const FLAG_COLUMNS = ['email', 'is_lead', 'is_archived', 'is_active', 'client_type', 'has_service', 'has_invoice'];

    /** The six-column table cron_sync has always created; the flag columns come from migration 074. */
    public static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
            id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '',
            phone_norm TEXT NOT NULL DEFAULT '', service TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT (datetime('now')))");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_csi_phone_norm ON " . self::TABLE . "(phone_norm)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_csi_name ON " . self::TABLE . "(name COLLATE NOCASE)");
    }

    /** Column names the table has right now. */
    public static function columns(\PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("PRAGMA table_info(" . self::TABLE . ")")->fetchAll(\PDO::FETCH_ASSOC) as $c) $out[] = (string)$c['name'];
        return $out;
    }

    /** True when the table is the structured one AND migration 074 has run. */
    public static function hasFlags(\PDO $pdo): bool
    {
        $cols = self::columns($pdo);
        if (in_array('data', $cols, true)) return false;                 // legacy blob-shaped table
        foreach (self::FLAG_COLUMNS as $c) if (!in_array($c, $cols, true)) return false;
        return true;
    }

    public static function phoneNorm(string $phone): string
    {
        $d = (string)preg_replace('/[^0-9]/', '', $phone);
        return strlen($d) >= 9 ? substr($d, -9) : $d;
    }

    /**
     * The row for one raw uCRM client object, or null when it has no id.
     * @param array      $svcByClient  clientId => [plan names] (from the services cache)
     * @param array|null $invClients   set of clientIds that have an invoice, or null when unknown
     */
    public static function rowFor(array $c, array $svcByClient = [], ?array $invClients = null, bool $servicesKnown = true): ?array
    {
        $cid = (int)($c['id'] ?? 0);
        if (!$cid) return null;
        $fname = trim((string)($c['firstName'] ?? $c['first_name'] ?? ''));
        $lname = trim((string)($c['lastName']  ?? $c['last_name']  ?? ''));
        $name  = trim("$fname $lname");
        if ($name === '') $name = trim((string)($c['companyName'] ?? $c['company_name'] ?? $c['username'] ?? ''));

        $phone = ''; $email = '';
        foreach ((array)($c['contacts'] ?? []) as $ct) {
            if ($phone === '') {
                if (!empty($ct['phone'])) $phone = (string)$ct['phone'];
                elseif (!empty($ct['phones'][0]['number'])) $phone = (string)$ct['phones'][0]['number'];
            }
            if ($email === '' && !empty($ct['email'])) $email = (string)$ct['email'];
        }
        if ($phone === '') $phone = trim((string)($c['phone'] ?? $c['mobile'] ?? ''));
        if ($email === '') $email = trim((string)($c['email'] ?? ''));

        $flag = function ($v): ?int { if ($v === null) return null; if (is_bool($v)) return $v ? 1 : 0; if (is_numeric($v)) return (int)$v ? 1 : 0; return null; };
        return [
            'id'          => $cid,
            'name'        => $name,
            'phone'       => $phone,
            'phone_norm'  => self::phoneNorm($phone),
            'service'     => implode(' ', array_filter((array)($svcByClient[$cid] ?? []))),
            'email'       => strtolower(trim($email)),
            'is_lead'     => array_key_exists('isLead', $c) ? $flag($c['isLead']) : null,
            'is_archived' => array_key_exists('isArchived', $c) ? $flag($c['isArchived']) : null,
            'is_active'   => array_key_exists('isActive', $c) ? $flag($c['isActive']) : null,
            'client_type' => isset($c['clientType']) && is_numeric($c['clientType']) ? (int)$c['clientType'] : null,
            'has_service' => $servicesKnown ? (isset($svcByClient[$cid]) && $svcByClient[$cid] !== [] ? 1 : 0) : null,
            'has_invoice' => $invClients === null ? null : (isset($invClients[$cid]) ? 1 : 0),
        ];
    }

    /** REPLACE one row; the flag columns only where the table has them. */
    public static function upsert(\PDO $pdo, array $row, ?bool $withFlags = null): void
    {
        $withFlags = $withFlags ?? self::hasFlags($pdo);
        if ($withFlags) {
            $pdo->prepare("REPLACE INTO " . self::TABLE . " (id, name, phone, phone_norm, service, email, is_lead, is_archived, is_active, client_type, has_service, has_invoice, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))")
                ->execute([$row['id'], $row['name'], $row['phone'], $row['phone_norm'], $row['service'], $row['email'],
                           $row['is_lead'], $row['is_archived'], $row['is_active'], $row['client_type'], $row['has_service'], $row['has_invoice']]);
        } else {
            $pdo->prepare("REPLACE INTO " . self::TABLE . " (id, name, phone, phone_norm, service, updated_at) VALUES (?, ?, ?, ?, ?, datetime('now'))")
                ->execute([$row['id'], $row['name'], $row['phone'], $row['phone_norm'], $row['service']]);
        }
    }

    public static function upsertMany(\PDO $pdo, array $rows): void
    {
        if ($rows === []) return;
        $withFlags = self::hasFlags($pdo);
        $pdo->beginTransaction();
        try {
            foreach ($rows as $r) self::upsert($pdo, $r, $withFlags);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** clientId => [plan names], from the plans and services caches. */
    public static function servicesByClient($store): array
    {
        $planNames = []; $svc = [];
        try {
            foreach ((array)($store->load('ucrm_plans_cache.json') ?? []) as $p) if (!empty($p['id'])) $planNames[(int)$p['id']] = (string)($p['name'] ?? '');
            foreach ((array)($store->load('ucrm_services_cache.json') ?? []) as $s) {
                $cid = (int)($s['clientId'] ?? $s['_clientId'] ?? 0);
                if ($cid) $svc[$cid][] = $planNames[(int)($s['servicePlanId'] ?? 0)] ?? '';
            }
        } catch (\Throwable $e) { /* best effort */ }
        return $svc;
    }

    /** Set of clientIds with an invoice in the cache, or null when the cache is empty (unknown, not "none"). */
    public static function invoiceClients($store): ?array
    {
        try {
            $rows = (array)($store->load('ucrm_invoices_cache.json') ?? []);
            if ($rows === []) return null;
            $set = [];
            foreach ($rows as $inv) { $cid = (int)($inv['clientId'] ?? $inv['_clientId'] ?? 0); if ($cid) $set[$cid] = true; }
            return $set;
        } catch (\Throwable $e) { return null; }
    }

    /** One client, freshly read from uCRM (the webhook's client.add / client.edit). */
    public static function upsertClient($store, array $client): void
    {
        $pdo = $store->getPdo();
        self::ensureTable($pdo);
        $svc = self::servicesByClient($store);
        $row = self::rowFor($client, $svc, self::invoiceClients($store), $svc !== []);
        if ($row !== null) self::upsert($pdo, $row);
    }
}

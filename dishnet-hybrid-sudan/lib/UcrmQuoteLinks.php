<?php
declare(strict_types=1);

/**
 * UcrmQuoteLinks — 5.18.102 (Uganda, docs/71 §R, D14): which uCRM Product (and, for a plan, which Service Plan) an
 * admin linked each plugin item to. The link is the only thing that joins a plan, a kit or the installation to uCRM:
 * never a name, never a spelling, never ucrm_product_id (the operator, 28 Oct: "Pick the product per item … Nothing is
 * guessed").
 *
 * Where the links live (docs/71 §R.1): the record collection ucrm_quote_links.json — the table ucrm_quote_links in the
 * plugin's store, created lazily, no migration — one record per act of linking:
 *
 *   id, item (plan | kit | install), row_id (0 for install), row_stamp (rowStamp() at link time), product_id,
 *   service_plan_id (plans only, else null), organization_id (the quoting organization in force), row_name,
 *   product_name, service_plan_name (display and history only — never matched, never priced), linked_by_id,
 *   linked_by_name, linked_at, ended_at, ended_by_id, ended_why (relinked | unlinked | row deleted), linked_at_ts,
 *   ended_at_ts.
 *
 *   ended_at_ts null is the active marker. ended_at and linked_at are local display strings for the screens only: never
 *   compared, never ordered, never read to tell whether a record is active.
 *
 * The judge is pure, and the store only feeds it (docs/71 §R.1.5, E-5):
 *   - fromRecords($records, $kitRows) holds link records and kyc_devices rows in their stored shape, and opens nothing;
 *   - fromStore($store, $setting) takes the gate from its caller: with $setting['on'] false it opens nothing and holds
 *     nothing — SqliteStore::load() creates any table it does not find, so a read where the gate is off would add a
 *     table to South Sudan's store;
 *   - neither keeps the quoting organization (F-4): every method that judges a record's organization_id takes the id
 *     from its caller.
 *
 * Writes (set(), end()) go through $store->withLock('ucrm_quote_links.json', …): a link and the end of every active
 * record of the item are one write. The callback makes store checks only — never a uCRM read, never network I/O
 * (docs/71 §R.1.4: withLock takes its read snapshot before the callback, and a write committed by another connection
 * meanwhile would make the INSERT fail with "database is locked").
 *
 * This file loads no sibling file (E-5): its callers load lib/SqliteStore.php and lib/QuoteOrganization.php
 * themselves, and lib/UcrmQuoteLines.php loads this file by its own path (F-6), so one file declares the class.
 *
 * Read only where QuoteOrganization::setting()['on'] (D7): Uganda, unless ucrm_quote_organization_id is 0 or off.
 * South Sudan, and Uganda with the gate off, never construct it from a store. PHP 7.4 compatible.
 */
final class UcrmQuoteLinks
{
    /** 5.18.102: the collection, and so the table ucrm_quote_links (SqliteStore: the file name less .json). */
    public const FILE  = 'ucrm_quote_links.json';
    /** 5.18.102: the three kinds of item a Uganda quotation carries (docs/71 §R.1.1). */
    public const ITEMS = ['plan', 'kit', 'install'];
    /** 5.18.102: why a record ended (docs/71 §R.1.1). */
    public const WHY_RELINKED = 'relinked';
    public const WHY_UNLINKED = 'unlinked';
    public const WHY_DELETED  = 'row deleted';

    /** @var array<int,array> every record, the ended ones too, in stored order */
    private array $records;
    /** @var array<int,array> kyc_devices rows in their stored shape, by id */
    private array $kits;

    private function __construct(array $records, array $kitRows)
    {
        $this->records = array_values(array_filter($records, 'is_array'));
        $kits = [];
        foreach ($kitRows as $row) {
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) $kits[(int)$row['id']] = $row;
        }
        ksort($kits);
        $this->kits = $kits;
    }

    // ── constructors (5.18.102, docs/71 §R.1.5) ─────────────────────────────

    /**
     * The judge over records and kit rows the caller holds — the deploy's offer file, or the records and findOne rows
     * read inside the lock. Opens no store and no file. $kitRows are kyc_devices rows in their stored shape (an int id,
     * title, type, is_active, created_at, imported_at), never recast or renamed.
     */
    public static function fromRecords(array $records, array $kitRows): self
    {
        return new self($records, $kitRows);
    }

    /**
     * The judge over the store's links and kyc_devices rows. $setting is QuoteOrganization::setting() as the caller has
     * already read it: with $setting['on'] false nothing is opened, and the judge holds no record and no row.
     */
    public static function fromStore($store, array $setting): self
    {
        if (empty($setting['on'])) return new self([], []);
        $records = $store->load(self::FILE);
        $kits    = $store->load('kyc_devices.json');
        return new self(is_array($records) ? $records : [], is_array($kits) ? $kits : []);
    }

    // ── one implementation each (5.18.102) ─────────────────────────────────

    /** The row's stamp (docs/71 §R.1.1): created_at, else imported_at, else '' — a reused id is caught by it. */
    public static function rowStamp(array $row): string
    {
        foreach (['created_at', 'imported_at'] as $k) {
            $v = $row[$k] ?? null;
            if (is_scalar($v) && (string)$v !== '') return (string)$v;
        }
        return '';
    }

    /**
     * 5.18.102 (F-5): is this kyc_devices row a kit the KYC form offers? The form's own test, one implementation: the
     * form's kit loop calls it, and rule 8 judges by it.
     */
    public static function offeredKit(array $row): bool
    {
        return !empty($row['is_active']) && strtolower((string)($row['type'] ?? 'general')) !== 'fiber';
    }

    /** 5.18.102: a Fiber row, which is never linked and never priced on Uganda (docs/71 §R.4.3). */
    public static function isFiber(string $item, array $row): bool
    {
        if ($item === 'plan') return strtolower((string)($row['type'] ?? 'starlink')) === 'fiber';
        if ($item === 'kit')  return strtolower((string)($row['type'] ?? 'general')) === 'fiber';
        return false;
    }

    // ── reads (5.18.102) ───────────────────────────────────────────────────

    /** Every record whole, the ended ones too, with every key (the deploy's offer file reduces it, §R.7.1). */
    public function records(): array
    {
        return $this->records;
    }

    /** One record by its id, ended or not (the retry, §R.4.4); null when there is none. */
    public function record(int $id): ?array
    {
        foreach ($this->records as $r) {
            if (is_numeric($r['id'] ?? null) && (int)$r['id'] === $id) return $r;
        }
        return null;
    }

    /** The active records of one item — ended_at_ts null — in stored order. Two or more is a corrupt state (§R.1.1). */
    public function active(string $item, int $rowId): array
    {
        $out = [];
        foreach ($this->records as $r) {
            if (!self::isOf($r, $item, $rowId)) continue;
            if (($r['ended_at_ts'] ?? null) !== null) continue;
            $out[] = $r;
        }
        return $out;
    }

    /** The ended records of one item, in stored order: the panel's history (§R.2.3). */
    public function history(string $item, int $rowId): array
    {
        $out = [];
        foreach ($this->records as $r) {
            if (self::isOf($r, $item, $rowId) && ($r['ended_at_ts'] ?? null) !== null) $out[] = $r;
        }
        return $out;
    }

    /**
     * 5.18.102 (docs/71 §R.1.1, §R.3.2): the link in force for an item, judged against the quoting organization's id
     * its caller hands it (F-4). $row is the plugin row as read now (null for the installation, whose stamp is '').
     *
     *   none          no active record — not linked
     *   two           two or more active records — treated as no link at all (fail closed), one text everywhere (E-4)
     *   replaced      its row_stamp is not the row's current stamp — the id was reused by another row
     *   organization  linked under another quoting organization (or none is configured now)
     *   ok            the one record in force
     *
     * @return array{state:string,record:?array,records:array}  records: every active record of the item
     */
    public function judge(string $item, ?array $row, ?int $orgId): array
    {
        $row    = $row ?? [];
        $rowId  = ($item !== 'install' && is_numeric($row['id'] ?? null)) ? (int)$row['id'] : 0;
        $active = $this->active($item, $rowId);
        if ($active === [])     return ['state' => 'none', 'record' => null, 'records' => []];
        if (count($active) > 1) return ['state' => 'two', 'record' => null, 'records' => $active];
        $rec   = $active[0];
        $stamp = $item === 'install' ? '' : self::rowStamp($row);
        $had   = is_scalar($rec['row_stamp'] ?? null) ? (string)$rec['row_stamp'] : '';
        if ($had !== $stamp) return ['state' => 'replaced', 'record' => $rec, 'records' => $active];
        $org = $rec['organization_id'] ?? null;
        if (!is_int($orgId) || !is_numeric($org) || (int)$org !== $orgId) {
            return ['state' => 'organization', 'record' => $rec, 'records' => $active];
        }
        return ['state' => 'ok', 'record' => $rec, 'records' => $active];
    }

    /**
     * 5.18.102 (docs/71 §R.3.1 rule 8, R-N3): a product may not be both the installation's link and the active link of
     * a kit row the form offers. One method, over the records and kit rows this judge holds, which the save (inside the
     * lock), installLine() and kitLine() at use, and the deploy's remote reading all call.
     *
     * A link takes part only when it is in force (judge() 'ok': the item's single active record, with its row's
     * current stamp, under $orgId), and a kit's only when its row is offered (offeredKit()). A leftover link on an
     * inactive or Fiber kit row, or one with another stamp, does not block.
     *
     * @return array{install:bool,kits:array}  install: the installation's link names $productId; kits: the offered
     *                                          kit rows (stored shape, by id) whose link names it
     */
    public function rule8(int $productId, ?int $orgId): array
    {
        $out = ['install' => false, 'kits' => []];
        if ($productId <= 0) return $out;
        $i = $this->judge('install', null, $orgId);
        if ($i['state'] === 'ok' && (int)($i['record']['product_id'] ?? 0) === $productId) $out['install'] = true;
        foreach ($this->kits as $row) {
            if (!self::offeredKit($row)) continue;
            $k = $this->judge('kit', $row, $orgId);
            if ($k['state'] === 'ok' && (int)($k['record']['product_id'] ?? 0) === $productId) $out['kits'][] = $row;
        }
        return $out;
    }

    // ── what was quoted under a link (5.18.102, docs/71 §R.4.4) ────────────

    /** Was this application quoted under one of these link records: a quote_id, and quote_link_ids naming one of them? */
    public static function quotedUnder(array $app, array $recordIds): bool
    {
        if (empty($app['quote_id'])) return false;
        $ids = $app['quote_link_ids'] ?? null;
        if (!is_array($ids)) return false;
        $want = [];
        foreach ($recordIds as $r) if (is_numeric($r)) $want[(int)$r] = true;
        foreach ($ids as $v) {
            if (is_numeric($v) && isset($want[(int)$v])) return true;
        }
        return false;
    }

    /**
     * The KYC applications quoted under these records, by sales type (§R.2.3: "N application(s) were quoted under the
     * previous link (C Cash, K Credit)"). Reads kyc_applications only, and only where the gate is on.
     *
     * @return array{cash:int,credit:int}
     */
    public static function countQuoted($store, array $setting, array $recordIds): array
    {
        $out = ['cash' => 0, 'credit' => 0];
        if (empty($setting['on']) || $recordIds === []) return $out;
        foreach ((array)($store->load('kyc_applications.json') ?? []) as $app) {
            if (!is_array($app) || !self::quotedUnder($app, $recordIds)) continue;
            if (($app['sales_type'] ?? '') === 'Cash') $out['cash']++;
            else $out['credit']++;
        }
        return $out;
    }

    // ── writes, inside the lock (5.18.102, docs/71 §R.1.4) ─────────────────

    /**
     * Link one item. The uCRM read and rules 1–8 ran before this, outside any transaction (§R.1.4 step 1); here only
     * store checks are made, then the write:
     *   - the row still exists in its own table with the same id and row_stamp, and is not Fiber — else 'changed';
     *   - rule 8 again, over the records the lock read and the kit rows they name, each read by findOne inside this
     *     transaction (never load(), whose request cache can hold kyc_devices.json), with the quoting organization's
     *     id read here — else 'clash';
     *   - a no-op ('already') only when the item's single active record would itself resolve now: the same product and
     *     service plan, the row's current stamp, the quoting organization's id now;
     *   - otherwise every active record of the item is ended (relinked) and one fresh record appended ('linked').
     * Whatever withLock throws (it has already rolled back) goes to the caller, which answers "nothing was linked —
     * try again".
     *
     * @param array $link   item, row_id, row_stamp (as rule 2 read it), product_id, service_plan_id (plans), row_name,
     *                      product_name, service_plan_name
     * @param array $actor  the admin's account record re-read fresh by id (§R.2.1): id, name
     * @param array $config the configuration QuoteOrganization::setting() reads, with $dataDir
     * @return array{kind:string,text:string,record:?array,ended:array}
     */
    public static function set($store, array $link, array $actor, array $config, ?string $dataDir): array
    {
        $item = (string)($link['item'] ?? '');
        if (!in_array($item, self::ITEMS, true)) throw new \InvalidArgumentException('UcrmQuoteLinks::set: unknown item');
        $rowId     = $item === 'install' ? 0 : (int)($link['row_id'] ?? 0);
        $productId = (int)($link['product_id'] ?? 0);
        $planId    = $item === 'plan' ? (int)($link['service_plan_id'] ?? 0) : null;
        if (($item !== 'install' && $rowId <= 0) || $productId <= 0 || ($item === 'plan' && $planId <= 0)) {
            throw new \InvalidArgumentException('UcrmQuoteLinks::set: an id is missing');
        }
        $stampIn  = $item === 'install' ? '' : (string)($link['row_stamp'] ?? '');
        $actorId  = (int)($actor['id'] ?? 0);
        $word     = ['plan' => 'plan', 'kit' => 'kit', 'install' => 'installation'][$item];
        $changed  = "the {$word} changed while it was being linked — nothing was linked; try again";
        $rowName  = (string)($link['row_name'] ?? '');
        $prodName = (string)($link['product_name'] ?? '');

        return $store->withLock(self::FILE, function (array $records) use ($store, $item, $rowId, $productId, $planId,
            $stampIn, $actor, $actorId, $config, $dataDir, $changed, $rowName, $prodName, $link) {
            $records = array_values(array_filter($records, 'is_array'));
            $refuse  = function (string $kind, string $text) use ($records): array {
                return ['records' => $records, 'result' => ['kind' => $kind, 'text' => $text, 'record' => null, 'ended' => []]];
            };
            // The quoting organization, read here as the no-op check reads it (F-4).
            $setting = QuoteOrganization::setting($config, $dataDir);
            $orgId   = (!empty($setting['on']) && is_int($setting['id'] ?? null)) ? $setting['id'] : null;
            if ($orgId === null) return $refuse('changed', $changed);

            // The row, read in this transaction.
            $row = null;
            if ($item !== 'install') {
                $row = $store->findOne($item === 'plan' ? 'subscription_plans.json' : 'kyc_devices.json', 'id', $rowId);
                if (!is_array($row) || !is_numeric($row['id'] ?? null) || (int)$row['id'] !== $rowId
                    || self::rowStamp($row) !== $stampIn || self::isFiber($item, $row)) {
                    return $refuse('changed', $changed);
                }
            }

            // Rule 8 again, crosswise-safe: the records this lock read, and the kit rows they name, read by findOne.
            if ($item !== 'plan') {
                $kitRows = [];
                foreach ($records as $r) {
                    if (($r['item'] ?? null) !== 'kit' || !is_numeric($r['row_id'] ?? null)) continue;
                    $kid = (int)$r['row_id'];
                    if (isset($kitRows[$kid])) continue;
                    $k = $store->findOne('kyc_devices.json', 'id', $kid);
                    if (is_array($k)) $kitRows[$kid] = $k;
                }
                if ($item === 'kit') $kitRows[$rowId] = $row;
                $clash = self::fromRecords($records, array_values($kitRows))->rule8($productId, $orgId);
                // 5.18.102 D14 review round 2 (FX-money-1): any kit row, offered or not, as the resolver's check before
                // the lock and kitLine at use — an inactive row's refusal names no deactivation
                if ($item === 'kit' && $clash['install']) {
                    return $refuse('clash', self::rule8Refusal('kit', $prodName, $productId, (string)($row['title'] ?? $rowName), $rowId, self::offeredKit($row)));
                }
                if ($item === 'install' && $clash['kits'] !== []) {
                    $kit = $clash['kits'][0];
                    return $refuse('clash', self::rule8Refusal('install', $prodName, $productId, (string)($kit['title'] ?? ''), (int)($kit['id'] ?? 0)));
                }
            }

            // The item's active records.
            $mine = self::fromRecords($records, [])->active($item, $rowId);
            if (count($mine) === 1) {
                $m = $mine[0];
                $samePlan = $item === 'plan'
                    ? (is_numeric($m['service_plan_id'] ?? null) && (int)$m['service_plan_id'] === $planId)
                    : (($m['service_plan_id'] ?? null) === null);
                if (is_numeric($m['product_id'] ?? null) && (int)$m['product_id'] === $productId && $samePlan
                    && (is_scalar($m['row_stamp'] ?? null) ? (string)$m['row_stamp'] : '') === ($item === 'install' ? '' : self::rowStamp($row))
                    && is_numeric($m['organization_id'] ?? null) && (int)$m['organization_id'] === $orgId) {
                    return ['records' => $records, 'result' => ['kind' => 'already', 'text' => '', 'record' => $m, 'ended' => []]];
                }
            }

            // End every active record of the item, then one fresh record. ended_at and ended_at_ts are written together.
            $now = date('Y-m-d H:i:s');
            $ts  = time();
            $endIds = [];
            foreach ($mine as $m) $endIds[(int)($m['id'] ?? 0)] = true;
            $ended = [];
            $maxId = 0;
            foreach ($records as $i => $r) {
                $rid = is_numeric($r['id'] ?? null) ? (int)$r['id'] : 0;
                if ($rid > $maxId) $maxId = $rid;
                if (!self::isOf($r, $item, $rowId) || ($r['ended_at_ts'] ?? null) !== null || !isset($endIds[$rid])) continue;
                $records[$i]['ended_at']    = $now;
                $records[$i]['ended_at_ts'] = $ts;
                $records[$i]['ended_by_id'] = $actorId;
                $records[$i]['ended_why']   = self::WHY_RELINKED;
                $ended[] = $rid;
            }
            $new = [
                'id'                => $maxId + 1,
                'item'              => $item,
                'row_id'            => $rowId,
                'row_stamp'         => $item === 'install' ? '' : self::rowStamp($row),
                'product_id'        => $productId,
                'service_plan_id'   => $planId,
                'organization_id'   => $orgId,
                'row_name'          => $item === 'install' ? 'Installation' : $rowName,
                'product_name'      => $prodName,
                'service_plan_name' => $item === 'plan' ? (string)($link['service_plan_name'] ?? '') : null,
                'linked_by_id'      => $actorId,
                'linked_by_name'    => (string)($actor['name'] ?? ''),
                'linked_at'         => $now,
                'ended_at'          => null,
                'ended_by_id'       => null,
                'ended_why'         => null,
                'linked_at_ts'      => $ts,
                'ended_at_ts'       => null,
            ];
            $records[] = $new;
            return ['records' => $records, 'result' => ['kind' => 'linked', 'text' => '', 'record' => $new, 'ended' => $ended]];
        });
    }

    /**
     * 5.18.102 (docs/71 §R.1.3, §R.1.4): end every active record of an item — an unlink (WHY_UNLINKED) or a deleted row
     * (WHY_DELETED), never only one of them. Inside the lock, store only; whatever withLock throws goes to the caller.
     *
     * @return array<int> the ids of the records ended (none when nothing was active)
     */
    public static function end($store, string $item, int $rowId, string $why, array $actor): array
    {
        if (!in_array($item, self::ITEMS, true)) throw new \InvalidArgumentException('UcrmQuoteLinks::end: unknown item');
        if ($why !== self::WHY_UNLINKED && $why !== self::WHY_DELETED) throw new \InvalidArgumentException('UcrmQuoteLinks::end: unknown reason');
        if ($item === 'install') $rowId = 0;
        $actorId = (int)($actor['id'] ?? 0);
        return $store->withLock(self::FILE, function (array $records) use ($item, $rowId, $why, $actorId) {
            $records = array_values(array_filter($records, 'is_array'));
            $now = date('Y-m-d H:i:s');
            $ts  = time();
            $ended = [];
            foreach ($records as $i => $r) {
                if (!self::isOf($r, $item, $rowId) || ($r['ended_at_ts'] ?? null) !== null) continue;
                $records[$i]['ended_at']    = $now;
                $records[$i]['ended_at_ts'] = $ts;
                $records[$i]['ended_by_id'] = $actorId;
                $records[$i]['ended_why']   = $why;
                $ended[] = is_numeric($r['id'] ?? null) ? (int)$r['id'] : 0;
            }
            return ['records' => $records, 'result' => $ended];
        });
    }

    /**
     * 5.18.102 (docs/71 §R.3.1 rule 8): the save's refusal, naming the other item — one text, for the resolver's check
     * before the lock and this class's re-check inside it. Fixed text plus names through QuoteOrganization::plain.
     *   kit      saving a kit row to the installation's product: $otherTitle/$otherId are the kit row being linked; $offered
     *            says whether that row is offered — only an offered row is told to deactivate itself (5.18.102 D14 review
     *            round 2, FX-money-1: an inactive row is refused too, and that advice would mean nothing to it)
     *   install  saving the installation to an offered kit's product: $otherTitle/$otherId are that kit row
     */
    public static function rule8Refusal(string $item, string $productName, int $productId, string $otherTitle, int $otherId, bool $offered = true): string
    {
        $prod = self::plainName($productName, 'uCRM product #' . $productId);
        if ($item === 'kit') {
            return "{$prod} is the installation's uCRM product, so a hardware row cannot be linked to it."
                . ($offered ? ' If ' . self::plainName($otherTitle, 'the hardware row #' . $otherId) . ' is the installation itself, deactivate it.' : '');
        }
        return "{$prod} is linked to the hardware row " . self::plainName($otherTitle, '#' . $otherId)
            . ', so the installation cannot be linked to it.';
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** Is this record one of the item's (5.18.102)? */
    private static function isOf(array $r, string $item, int $rowId): bool
    {
        return ($r['item'] ?? null) === $item && is_numeric($r['row_id'] ?? null) && (int)$r['row_id'] === $rowId;
    }

    /** A name fit for a flash (5.18.102, docs/71 §R.2.3): QuoteOrganization::plain, else the fallback. */
    private static function plainName(string $name, string $fallback): string
    {
        $p = QuoteOrganization::plain($name);
        return $p !== '' ? $p : $fallback;
    }
}

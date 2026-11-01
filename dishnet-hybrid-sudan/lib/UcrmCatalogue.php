<?php
declare(strict_types=1);

/**
 * UcrmCatalogue — 5.18.103 (Uganda, root docs/72 §G): the uCRM catalogue's switch and its three settings, as an admin
 * sets them on the uCRM catalogue screen.
 *
 * With the switch on ("direct mode"), the KYC form, Send Quotation and the lead quotes offer uCRM's own service plans and
 * products, by uCRM id, at uCRM's prices (UcrmQuoteLines::offered()), and the plugin's own plan and kit lists are hidden
 * on Uganda. With it off — the state a deploy leaves — 5.18.103 behaves as 5.18.102 (docs/72 §G G-1).
 *
 * Where it lives (G-2): the record collection ucrm_catalogue.json — the table ucrm_catalogue in the plugin's store,
 * created by its first read or write, no migration; never a configuration key (web pages read the configuration from the
 * store row, the crons from the files). One record per act:
 *
 *   id, kind (mode | install | hidden | standard), and by kind:
 *     mode      on (bool)
 *     install   product_id, product_name (display only — never matched, never priced)
 *     hidden    product_id, product_name (display only)
 *     standard  title, plan {sp, p}, hw [{p, q}], install (bool)
 *   by_id, by_name, at, at_ts, ended_at, ended_at_ts, ended_by_id
 *
 *   ended_at_ts null is the in-force marker. A change ends the record in force and appends a new one, so the history
 *   stays. at and ended_at are display strings only: never compared, never ordered.
 *
 * Reads: fromStore($store, $setting) takes the gate from its caller — QuoteOrganization::setting(): 'on' with an integer
 * id — and opens nothing otherwise (SqliteStore::load() creates any table it does not find, so a read where the gate is
 * off would add a table to South Sudan's store); with the gate open it reads the table only when it exists, so no read
 * creates it (the first write does). Every reader fails safe: two records in force where one is allowed read
 * as none (the switch off, the installation not set).
 *
 * Writes go through $store->withLock(self::FILE, …), each re-reading the gate inside the lock and checking the store
 * only — never a uCRM read inside the lock. The caller judges uCRM items first (a fresh read through UcrmQuoteLines) and
 * passes the judged ids in, as UcrmQuoteLinks::set() does. Prices are never stored (G-12).
 *
 * This file loads lib/QuoteOrganization.php only when it is absent (forConfig(), the writes); its callers load
 * lib/SqliteStore.php.
 * PHP 7.4 compatible.
 */
final class UcrmCatalogue
{
    /** 5.18.103: the collection, and so the table ucrm_catalogue (SqliteStore: the file name less .json). */
    public const FILE  = 'ucrm_catalogue.json';
    public const KINDS = ['mode', 'install', 'hidden', 'standard'];
    /** 5.18.103 (§G.3): at most this many standard quotations in force, and this many hardware items in each. */
    public const MAX_STANDARDS      = 10;
    public const MAX_STANDARD_ITEMS = 10;
    /** 5.18.103 (§G.5, §G.6): the most of one item a sale may carry — the form's cap and the server's. */
    public const MAX_QTY   = 99;
    public const TITLE_MAX = 60;

    /** @var array<int,array> every record, the ended ones too, in stored order */
    private array $records;
    private bool $open;

    private function __construct(array $records, bool $open)
    {
        $this->records = $open ? array_values(array_filter($records, 'is_array')) : [];
        $this->open    = $open;
    }

    // ── constructors ───────────────────────────────────────────────────────

    /** Is the gate open: QuoteOrganization::setting()'s 'on', with an integer id (on can come with id null)? */
    public static function gateOpen(array $setting): bool
    {
        return !empty($setting['on']) && is_int($setting['id'] ?? null) && $setting['id'] > 0;
    }

    /**
     * The settings over the store's records. $setting is QuoteOrganization::setting() as the caller has read it: with the
     * gate closed nothing is opened, and the reader holds nothing (the switch off).
     */
    public static function fromStore($store, array $setting): self
    {
        if (!self::gateOpen($setting)) return new self([], false);
        // 5.18.103: a read never creates the table — SqliteStore::load() would — so a page or a sale that only reads the
        // switch leaves the store as it was; the first write (withLock) creates it.
        if (is_object($store) && method_exists($store, 'getPdo')) {
            try {
                $has = $store->getPdo()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'ucrm_catalogue'")->fetchColumn();
                if ((int)$has === 0) return new self([], true);
            } catch (\Throwable $e) {
                return new self([], true);   // unreadable: the switch reads off
            }
        }
        $records = $store->load(self::FILE);
        return new self(is_array($records) ? $records : [], true);
    }

    /**
     * 5.18.103: the settings as a page or a handler sees them — the gate read from its configuration
     * (QuoteOrganization::setting($config, $dataDir), loaded here when absent), then fromStore(). Never throws: anything
     * unclear is the gate closed.
     */
    public static function forConfig($store, array $config, ?string $dataDir): self
    {
        try {
            if (!class_exists('QuoteOrganization')) require_once __DIR__ . '/QuoteOrganization.php';
            return self::fromStore($store, QuoteOrganization::setting($config, $dataDir));
        } catch (\Throwable $e) {
            return new self([], false);
        }
    }

    /** The settings over records the caller holds (the deploy's copy, a test). Opens nothing. */
    public static function fromRecords(array $records, bool $open = true): self
    {
        return new self($records, $open);
    }

    // ── reads ──────────────────────────────────────────────────────────────

    public function open(): bool
    {
        return $this->open;
    }

    /** Every record whole, the ended ones too. */
    public function records(): array
    {
        return $this->records;
    }

    /** The records of a kind in force, in stored order. */
    private function inForce(string $kind): array
    {
        $out = [];
        foreach ($this->records as $r) {
            if (($r['kind'] ?? null) === $kind && ($r['ended_at_ts'] ?? null) === null) $out[] = $r;
        }
        return $out;
    }

    /** The switch's record in force, or null (none, or two — read as off). */
    public function modeRecord(): ?array
    {
        $m = $this->inForce('mode');
        return count($m) === 1 ? $m[0] : null;
    }

    /** Is direct mode on (§G.3): exactly one switch record in force, holding on === true. */
    public function direct(): bool
    {
        $m = $this->modeRecord();
        return $m !== null && ($m['on'] ?? null) === true;
    }

    /** The installation's record in force, or null (none, two, or not a positive id — read as not set). */
    public function installRecord(): ?array
    {
        $m = $this->inForce('install');
        if (count($m) !== 1) return null;
        return self::posInt($m[0]['product_id'] ?? null) ? $m[0] : null;
    }

    public function installProductId(): ?int
    {
        $r = $this->installRecord();
        return $r === null ? null : (int)$r['product_id'];
    }

    /** The product ids an admin keeps off the forms, unique, ascending. */
    public function hiddenIds(): array
    {
        $ids = [];
        foreach ($this->inForce('hidden') as $r) {
            if (self::posInt($r['product_id'] ?? null)) $ids[(int)$r['product_id']] = true;
        }
        $ids = array_keys($ids);
        sort($ids);
        return $ids;
    }

    /** The hidden records in force (for the screen: name, who, when). */
    public function hiddenRecords(): array
    {
        return $this->inForce('hidden');
    }

    /** The standard quotations in force, each valid in shape, by id ascending (a malformed record is left out). */
    public function standards(): array
    {
        $out = [];
        foreach ($this->inForce('standard') as $r) {
            if (self::standardShape($r) === '') $out[(int)$r['id']] = $r;
        }
        ksort($out);
        return array_values($out);
    }

    public function standard(int $id): ?array
    {
        foreach ($this->standards() as $r) if ((int)$r['id'] === $id) return $r;
        return null;
    }

    /** A kind's records, the ended ones too, newest first (the screen's history). */
    public function history(string $kind): array
    {
        $out = [];
        foreach ($this->records as $r) if (($r['kind'] ?? null) === $kind) $out[] = $r;
        usort($out, function ($a, $b) { return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0); });
        return $out;
    }

    // ── validation (one implementation: the writes, the screen and the deploy) ──────────────────

    /**
     * '' when a standard quotation's fields are well formed (§G.3), else what is wrong. title: plain text, 1–60
     * characters; plan: {sp, p} positive ints; hw: at most 10 entries, each {p, q} with p a positive int, unique, and q
     * 1–99; install: a bool.
     */
    public static function standardShape(array $s): string
    {
        $title = $s['title'] ?? null;
        if (!is_string($title) || trim($title) === '' || $title !== self::plainTitle($title)) return 'the title is missing or not plain text';
        $plan = $s['plan'] ?? null;
        if (!is_array($plan) || !self::posInt($plan['sp'] ?? null) || !self::posInt($plan['p'] ?? null)) return 'the plan is missing';
        $hw = $s['hw'] ?? null;
        if (!is_array($hw) || count($hw) > self::MAX_STANDARD_ITEMS || ($hw !== [] && array_keys($hw) !== range(0, count($hw) - 1))) {
            return 'the hardware list is not valid (at most ' . self::MAX_STANDARD_ITEMS . ' items)';
        }
        $seen = [];
        foreach ($hw as $h) {
            if (!is_array($h) || !self::posInt($h['p'] ?? null) || !is_int($h['q'] ?? null) || $h['q'] < 1 || $h['q'] > self::MAX_QTY) {
                return 'a hardware item is not valid (quantity 1–' . self::MAX_QTY . ')';
            }
            if (isset($seen[$h['p']])) return 'a hardware item is listed twice';
            $seen[$h['p']] = true;
        }
        if (!is_bool($s['install'] ?? null)) return 'whether it includes the installation is not said';
        return '';
    }

    /** A title as the screen stores it: control characters and < > & " removed, spaces collapsed, at most 60 characters. */
    public static function plainTitle(string $t): string
    {
        $t = preg_replace('/[\x00-\x1F\x7F<>&"]/u', '', $t) ?? '';
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        return mb_substr($t, 0, self::TITLE_MAX);
    }

    private static function posInt($v): bool
    {
        return is_int($v) && $v > 0 && $v <= 999999999;
    }

    // ── writes (each under the lock; store checks only) ────────────────────

    /**
     * Turn the switch on or off. On is asked by the caller only after its fresh read met the three conditions (§G.3, G-1);
     * $judgedInstall is the installation product that read judged: inside the lock it must still be the one in force, or
     * nothing changes ('changed'). Off needs nothing judged and is never refused but by the gate.
     *
     * @return array{kind:string,text:string,record:?array}  kind: on | off | already | changed | gate
     */
    public static function setMode($store, bool $on, ?int $judgedInstall, array $actor, array $config, ?string $dataDir): array
    {
        return self::write($store, $config, $dataDir, function (array $records, self $now) use ($on, $judgedInstall, $actor) {
            if ($on && ($judgedInstall === null || $now->installProductId() !== $judgedInstall)) {
                return ['kind' => 'changed', 'text' => 'the installation product changed while the switch was being turned on — nothing was changed; try again'];
            }
            if ($now->direct() === $on && $now->modeRecord() !== null) return ['kind' => 'already', 'shown' => $now->modeRecord()];
            return ['kind' => $on ? 'on' : 'off', 'new' => ['kind' => 'mode', 'on' => $on]];
        }, $actor);
    }

    /**
     * Set the installation product. The caller has judged $productId on a fresh read (badProduct and rule 7, §G.4).
     * Refused ('clash') when a standard quotation in force lists it as hardware.
     *
     * @return array{kind:string,text:string,record:?array}  kind: set | already | clash | gate
     */
    public static function setInstall($store, int $productId, string $productName, array $actor, array $config, ?string $dataDir): array
    {
        if (!self::posInt($productId)) throw new \InvalidArgumentException('UcrmCatalogue::setInstall: no product id');
        return self::write($store, $config, $dataDir, function (array $records, self $now) use ($productId, $productName) {
            foreach ($now->standards() as $s) {
                foreach ($s['hw'] as $h) {
                    if ((int)$h['p'] === $productId) {
                        return ['kind' => 'clash', 'text' => 'the standard quotation "' . (string)$s['title'] . '" lists this product as hardware — change it first; nothing was changed'];
                    }
                }
            }
            if ($now->installProductId() === $productId) return ['kind' => 'already', 'shown' => $now->installRecord()];
            return ['kind' => 'set', 'new' => ['kind' => 'install', 'product_id' => $productId, 'product_name' => self::plainTitle($productName)]];
        }, $actor);
    }

    /**
     * Keep a product off the forms ($hidden true) or offer it again (false).
     *
     * @return array{kind:string,text:string,record:?array}  kind: hidden | shown | already | gate
     */
    public static function setHidden($store, int $productId, bool $hidden, string $productName, array $actor, array $config, ?string $dataDir): array
    {
        if (!self::posInt($productId)) throw new \InvalidArgumentException('UcrmCatalogue::setHidden: no product id');
        return self::write($store, $config, $dataDir, function (array $records, self $now) use ($productId, $hidden, $productName) {
            $is = in_array($productId, $now->hiddenIds(), true);
            if ($is === $hidden) return ['kind' => 'already'];
            if ($hidden) return ['kind' => 'hidden', 'new' => ['kind' => 'hidden', 'product_id' => $productId, 'product_name' => self::plainTitle($productName)]];
            return ['kind' => 'shown', 'end' => ['hidden', $productId]];   // the end of the product's hidden records, no new record
        }, $actor);
    }

    /**
     * Save a standard quotation: a new one ($replaceId null) or a new version of one in force. The caller has judged every
     * item on a fresh read (each offered, §G.4). Refused when the shape is wrong ('invalid'), when it would be the
     * eleventh ('full'), when the one it replaces is no longer in force ('changed'), or when its hardware names the
     * installation product in force ('clash').
     *
     * @return array{kind:string,text:string,record:?array}  kind: saved | invalid | full | changed | clash | gate
     */
    public static function saveStandard($store, ?int $replaceId, array $std, array $actor, array $config, ?string $dataDir): array
    {
        $std = ['kind' => 'standard', 'title' => is_string($std['title'] ?? null) ? self::plainTitle($std['title']) : null,
                'plan' => $std['plan'] ?? null, 'hw' => $std['hw'] ?? null, 'install' => $std['install'] ?? null];
        $bad = self::standardShape($std);
        if ($bad !== '') return ['kind' => 'invalid', 'text' => $bad . ' — nothing was saved', 'record' => null];
        return self::write($store, $config, $dataDir, function (array $records, self $now) use ($replaceId, $std) {
            $install = $now->installProductId();
            foreach ($std['hw'] as $h) {
                if ($install !== null && (int)$h['p'] === $install) {
                    return ['kind' => 'clash', 'text' => 'the installation product cannot be listed as hardware — tick "includes installation" instead; nothing was saved'];
                }
            }
            if ($replaceId !== null) {
                if ($now->standard($replaceId) === null) return ['kind' => 'changed', 'text' => 'that standard quotation was changed or stopped meanwhile — nothing was saved; reload'];
                return ['kind' => 'saved', 'new' => $std, 'end' => ['standard-id', $replaceId]];
            }
            if (count($now->standards()) >= self::MAX_STANDARDS) {
                return ['kind' => 'full', 'text' => 'there are already ' . self::MAX_STANDARDS . ' standard quotations — stop using one first; nothing was saved'];
            }
            return ['kind' => 'saved', 'new' => $std];
        }, $actor);
    }

    /**
     * Stop using a standard quotation.
     *
     * @return array{kind:string,text:string,record:?array}  kind: ended | changed | gate
     */
    public static function endStandard($store, int $id, array $actor, array $config, ?string $dataDir): array
    {
        return self::write($store, $config, $dataDir, function (array $records, self $now) use ($id) {
            if ($now->standard($id) === null) return ['kind' => 'changed', 'text' => 'that standard quotation was already stopped or changed — nothing was changed; reload'];
            return ['kind' => 'ended', 'end' => ['standard-id', $id]];
        }, $actor);
    }

    /**
     * One write under the lock. $decide($records, $now) answers ['kind' => …, 'text'? => …, 'new'? => record to append,
     * 'end'? => ['standard-id', id] (end that record) or ['hidden', productId] (end that product's hidden records),
     * 'shown'? => the record in force an 'already' answer reports]. With a new record of a single-in-force kind (mode,
     * install) every record of the kind in force is ended first. An answer with neither 'new' nor 'end' returns the
     * records unchanged. Whatever withLock throws (it has already rolled back) goes to the caller.
     */
    private static function write($store, array $config, ?string $dataDir, callable $decide, array $actor): array
    {
        if (!class_exists('QuoteOrganization')) require_once __DIR__ . '/QuoteOrganization.php';
        $actorId   = (int)($actor['id'] ?? 0);
        $actorName = (string)($actor['name'] ?? '');
        return $store->withLock(self::FILE, function (array $records) use ($config, $dataDir, $decide, $actorId, $actorName) {
            $records = array_values(array_filter($records, 'is_array'));
            $same = function (string $kind, string $text, ?array $rec = null) use ($records): array {
                return ['records' => $records, 'result' => ['kind' => $kind, 'text' => $text, 'record' => $rec]];
            };
            // The gate, read here (G-2): a switch turned on where the quoting organization has since gone off stays off.
            if (!self::gateOpen(QuoteOrganization::setting($config, $dataDir))) {
                return $same('gate', 'the quoting organization is not in force here — nothing was changed');
            }
            $ans  = $decide($records, new self($records, true));
            $kind = (string)$ans['kind'];
            $text = (string)($ans['text'] ?? '');
            $new  = isset($ans['new']) && is_array($ans['new']) ? $ans['new'] : null;
            $end  = isset($ans['end']) && is_array($ans['end']) ? $ans['end'] : null;
            if ($new === null && $end === null) return $same($kind, $text, isset($ans['shown']) && is_array($ans['shown']) ? $ans['shown'] : null);

            $now = date('Y-m-d H:i:s');
            $ts  = time();
            $maxId = 0;
            foreach ($records as $r) {
                $rid = is_numeric($r['id'] ?? null) ? (int)$r['id'] : 0;
                if ($rid > $maxId) $maxId = $rid;
            }
            $single = is_array($new) && in_array($new['kind'] ?? '', ['mode', 'install'], true) ? (string)$new['kind'] : null;
            foreach ($records as $i => $r) {
                if (($r['ended_at_ts'] ?? null) !== null) continue;
                $hit = false;
                if ($single !== null && ($r['kind'] ?? null) === $single) $hit = true;
                if (is_array($end) && $end[0] === 'standard-id' && ($r['kind'] ?? null) === 'standard' && (int)($r['id'] ?? 0) === (int)$end[1]) $hit = true;
                if (is_array($end) && $end[0] === 'hidden' && ($r['kind'] ?? null) === 'hidden' && (int)($r['product_id'] ?? 0) === (int)$end[1]) $hit = true;
                if (!$hit) continue;
                $records[$i]['ended_at']    = $now;
                $records[$i]['ended_at_ts'] = $ts;
                $records[$i]['ended_by_id'] = $actorId;
            }
            $record = null;
            if (is_array($new)) {
                $record = ['id' => $maxId + 1] + $new + [
                    'by_id' => $actorId, 'by_name' => $actorName, 'at' => $now, 'at_ts' => $ts,
                    'ended_at' => null, 'ended_at_ts' => null, 'ended_by_id' => null,
                ];
                $records[] = $record;
            }
            return ['records' => $records, 'result' => ['kind' => $kind, 'text' => $text, 'record' => $record]];
        });
    }
}

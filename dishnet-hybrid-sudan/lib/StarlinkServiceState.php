<?php
declare(strict_types=1);

require_once __DIR__ . '/SiblingPlugin.php';
require_once __DIR__ . '/EquipmentAssignment.php';

/**
 * StarlinkServiceState — what Starlink says about a line, joined on the
 * service line rather than on the kit serial.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 *
 * KitUsage joins on kit_serial, because that is what sl_usage.json is keyed
 * by. On the Uganda server that file is `[]`, and so is dr_kit_registry.json
 * — and the reason is the same for both: the data plugin resolves service
 * lines but not kit serials. Its sl_svc_cache.json holds 15 real lines, each
 * with a live subscription state, and every one of them has kit_number "".
 * Anything keyed by kit therefore builds empty, and will keep building empty
 * however long we wait.
 *
 * The service line is not missing. We capture it at installation, it is on
 * equipment_assignments, and it is exactly what the data plugin keys that
 * cache by. So the join that cannot be made on a serial can be made on the
 * identifier we already own:
 *
 *     uCRM client → equipment_assignments → starlink_service_line
 *                                                │ exact match on the key
 *              dishnet-data-report/sl_svc_cache.json
 *
 * This reports subscription STATE, not consumption. It is not a substitute
 * for usage and does not pretend to be one: a line can be active and have no
 * telemetry collected, and both facts are reported separately.
 *
 * ── NOT KNOWING, AGAIN, IN FOUR FLAVOURS ────────────────────────────────
 *
 *   1. No cache file at all — the data plugin has never run here.
 *   2. A cache with no entry for this line — it ran, this line is unknown to
 *      it (a line on another account, or one added since the last sync).
 *   3. An entry whose subscription_active is null — Starlink has not said.
 *   4. An entry that is real but old — reported WITH its age, not hidden,
 *      because a stale answer a person can see the date of beats a blank.
 *
 * None of those is "inactive".
 */
final class StarlinkServiceState
{
    /** Written by the data plugin's own cron, in its own data directory. */
    public const CACHE_FILE = 'sl_svc_cache.json';
    public const PLUGIN     = 'dishnet-data-report';

    /** Older than this and the reading is flagged, though still shown. */
    public const STALE_HOURS = 24;

    /** Per-instance — a `static` would let one object's read answer another's. */
    private ?array $rows = null;
    private bool $read = false;

    /**
     * Every line the data plugin has cached, keyed as it keys them, or null
     * if there is no source at all — a different answer from an empty one.
     */
    public function rows(): ?array
    {
        if ($this->read) return $this->rows;
        $this->read = true;
        $raw = SiblingPlugin::readJson(self::PLUGIN, self::CACHE_FILE);
        if ($raw === null) return $this->rows = null;    // plugin or file absent
        $out = [];
        foreach ($raw as $key => $rec) {
            if (!is_array($rec)) continue;
            // The file is keyed by service line, and each record repeats it.
            // Prefer the key: it is what the writer indexed by.
            $line = self::normalise((string)$key);
            if ($line === '') $line = self::normalise((string)($rec['service_line'] ?? ''));
            if ($line === '') continue;
            $out[$line] = $rec;
        }
        return $this->rows = $out;
    }

    /** Whether the data plugin has written this cache here at all. */
    public function available(): array
    {
        $rows = $this->rows();
        if ($rows === null) {
            return ['available' => false, 'lines' => 0,
                    'reason' => 'The data-report plugin has written no service cache on '
                              . 'this server, so no line can show a Starlink state. This '
                              . 'is not the same as a fleet that is switched off.'];
        }
        if ($rows === []) {
            return ['available' => false, 'lines' => 0,
                    'reason' => 'The data-report plugin has written its service cache but '
                              . 'it holds no lines — its Starlink session saw nothing.'];
        }
        return ['available' => true, 'lines' => count($rows), 'reason' => ''];
    }

    /**
     * What Starlink says about one service line.
     *
     * @return array{known:bool, status:string, label:string, reason:string,
     *               plan:string, account:string, telemetry:bool|null,
     *               synced_at:string, age_hours:float|null, stale:bool}
     */
    public function forLine(string $serviceLine): array
    {
        $out = ['known' => false, 'status' => '', 'label' => '', 'reason' => '',
                'plan' => '', 'account' => '', 'telemetry' => null,
                'synced_at' => '', 'age_hours' => null, 'stale' => false];

        $line = self::normalise($serviceLine);
        if ($line === '') {
            $out['reason'] = 'no Starlink service line recorded on this assignment';
            return $out;
        }

        $rows = $this->rows();
        if ($rows === null) {
            $out['reason'] = 'the data-report plugin has written no service cache here';
            return $out;
        }
        if (!isset($rows[$line])) {
            $out['reason'] = 'this service line is not in the data plugin\'s cache';
            return $out;
        }

        $rec = $rows[$line];
        $out['plan']    = trim((string)($rec['product_desc'] ?? $rec['plan_id'] ?? ''));
        $out['account'] = trim((string)($rec['account_number'] ?? ''));
        if (array_key_exists('has_telemetry', $rec)) $out['telemetry'] = (bool)$rec['has_telemetry'];

        $out['synced_at'] = trim((string)($rec['sl_synced_at'] ?? ''));
        if ($out['synced_at'] !== '' && ($ts = strtotime($out['synced_at'])) !== false) {
            $out['age_hours'] = round((time() - $ts) / 3600, 1);
            $out['stale']     = $out['age_hours'] >= self::STALE_HOURS;
        }

        $status = self::statusOf($rec);
        if ($status === '') {
            $out['reason'] = 'Starlink has not reported a subscription state for this line';
            return $out;
        }

        $out['known']  = true;
        $out['status'] = $status;
        $out['label']  = self::labelFor($status);
        return $out;
    }

    /**
     * The one status a record means.
     *
     * Precedence follows what dishnet-data-report's own consumers use, so
     * three plugins do not each invent a different meaning for the same
     * record: pending beats suspended beats paused beats standby beats
     * active. A line can carry more than one of those flags at once, and the
     * first one that is true is the one a person needs to act on.
     *
     * subscription_active absent or null means Starlink has not told us —
     * which is not false, and must not become "inactive".
     *
     * Field names differ between the data plugin's files: sl_svc_cache.json
     * writes isPaused/isSuspended/isStandby/pendingActivation, while
     * dr_kit_registry.json writes subscription_paused/_suspended/_standby and
     * pending_activation. Both are read, so a record from either is understood.
     */
    public static function statusOf(array $rec): string
    {
        $active = array_key_exists('subscription_active', $rec) ? $rec['subscription_active'] : null;
        if ($active === null) return '';

        $flag = static function (array $r, string ...$keys): bool {
            foreach ($keys as $k) if (!empty($r[$k])) return true;
            return false;
        };

        if ($flag($rec, 'pendingActivation', 'pending_activation'))          return 'pending';
        if ($flag($rec, 'isSuspended', 'subscription_suspended'))            return 'suspended';
        if ($flag($rec, 'isPaused', 'subscription_paused'))                  return 'paused';
        if ($flag($rec, 'isStandby', 'subscription_standby'))                return 'standby';
        return $active ? 'active' : 'inactive';
    }

    /** What a person should read on the screen. */
    public static function labelFor(string $status): string
    {
        $map = ['active'    => 'Active',    'pending'  => 'Pending activation',
                'suspended' => 'Suspended', 'paused'   => 'Paused',
                'standby'   => 'Standby',   'inactive' => 'Inactive'];
        return $map[$status] ?? '';
    }

    /**
     * Service lines the data plugin holds that no live assignment claims.
     *
     * A line Starlink is billing us for and nobody in uCRM is bound to is
     * either an unrecorded installation or a subscription still running for a
     * customer who left. Both cost money, and neither shows on a list built
     * from assignments alone.
     *
     * @param array<int,string> $claimed service lines from live assignments
     * @return array<int,array{service_line:string, status:string, label:string,
     *                         plan:string, account:string}>
     */
    public function unclaimed(array $claimed): array
    {
        $rows = $this->rows();
        if ($rows === null || $rows === []) return [];

        $mine = [];
        foreach ($claimed as $c) {
            $n = self::normalise((string)$c);
            if ($n !== '') $mine[$n] = true;
        }

        $out = [];
        foreach ($rows as $line => $rec) {
            if (isset($mine[$line])) continue;
            $status = self::statusOf($rec);
            $out[] = ['service_line' => (string)($rec['service_line'] ?? $line),
                      'status'       => $status,
                      'label'        => $status === '' ? 'Unreported' : self::labelFor($status),
                      'plan'         => trim((string)($rec['product_desc'] ?? $rec['plan_id'] ?? '')),
                      'account'      => trim((string)($rec['account_number'] ?? ''))];
        }
        usort($out, static fn(array $a, array $b): int =>
            strcmp($a['service_line'], $b['service_line']));
        return $out;
    }

    /**
     * One spelling for a service line, so a match never turns on case or
     * stray whitespace. Deliberately NOT EquipmentAssignment::clean(), which
     * is for serials — this keeps the hyphens a service line is made of.
     */
    public static function normalise(string $v): string
    {
        return strtoupper(trim($v));
    }
}

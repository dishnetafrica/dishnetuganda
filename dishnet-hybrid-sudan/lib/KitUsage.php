<?php
declare(strict_types=1);

require_once __DIR__ . '/SiblingPlugin.php';
require_once __DIR__ . '/EquipmentAssignment.php';
require_once __DIR__ . '/ServicePlan.php';

/**
 * KitUsage — how much a customer has used, joined on the kit serial.
 *
 * The data-report plugin already collects this. Its cron writes sl_usage.json
 * into its own directory, one row per service line per billing cycle, each
 * carrying kit_number. What it cannot do is say WHOSE kit that is: it has no
 * crm_client_id anywhere, which is why South Sudan answers that question by
 * parsing uCRM service names.
 *
 * We can. equipment_assignments knows the customer and the serial, so the
 * join is an exact string match on a serial the assignment itself supplied.
 * No names, no service titles, no substrings.
 *
 *     uCRM client  →  equipment_assignments  →  kit_serial
 *                                                   │ exact match
 *                     dishnet-data-report/sl_usage.json
 *
 * ── ZERO IS NOT THE SAME AS UNKNOWN ─────────────────────────────────────
 *
 * A customer who has used nothing and a customer whose telemetry has not been
 * collected both produce no rows. Showing 0 GB to the second one is a lie of
 * the same family as showing "unlimited" to a capped customer: it looks like
 * an answer. Every reading here says which it is, and a caller that ignores
 * that distinction is doing the same damage South Sudan's customer page does.
 */
final class KitUsage
{
    /** Written by the data plugin's own cron, in its own data directory. */
    public const USAGE_FILE = 'sl_usage.json';
    public const PLUGIN     = 'dishnet-data-report';

    private ?EquipmentAssignment $ea;
    /** Per-instance — a `static` would let one object's read answer another's. */
    private ?array $rows = null;
    private ?string $ownDir;
    private string $source = '';

    /**
     * @param string|null $ownDir this plugin's data directory. When our own
     *        collector has written usage there, it is read in preference to the
     *        sibling's — same row shape, so nothing downstream changes.
     */
    public function __construct(?EquipmentAssignment $ea = null, ?string $ownDir = null)
    {
        $this->ea     = $ea;
        $this->ownDir = $ownDir;
    }

    /** Where the figures on screen came from, for a doctor or a banner. */
    public function source(): string { $this->rows(); return $this->source; }

    /**
     * Every usage row the data plugin has written, or null if there is no
     * source at all — which is a different answer from an empty one.
     */
    public function rows(): ?array
    {
        if ($this->rows !== null) return $this->rows === [] ? [] : $this->rows;

        // Ours first. cron/starlink_usage.php collects from our own Starlink
        // session, against the binding we already hold, and writes the same row
        // shape into our own data directory — which survives an upgrade of
        // either plugin. The sibling stays as the fallback it has always been:
        // on the South Sudan box it is the one doing the collecting.
        if ($this->ownDir !== null) {
            $mine = rtrim($this->ownDir, '/') . '/' . self::USAGE_FILE;
            if (is_file($mine)) {
                $d = json_decode((string)@file_get_contents($mine), true);
                if (is_array($d) && $d !== []) {
                    $this->source = 'this plugin (' . self::USAGE_FILE . ')';
                    return $this->rows = array_values(array_filter($d, 'is_array'));
                }
            }
        }

        $raw = SiblingPlugin::readJson(self::PLUGIN, self::USAGE_FILE);
        if ($raw === null) return null;                  // plugin or file absent
        $this->source = self::PLUGIN;
        return $this->rows = array_values(array_filter($raw, 'is_array'));
    }

    /**
     * Every billing cycle recorded for one kit, oldest first.
     *
     * @return array{available:bool, cycles:array, reason:string}
     */
    public function forKit(string $serial): array
    {
        $serial = EquipmentAssignment::clean($serial);
        if ($serial === '') {
            return ['available' => false, 'cycles' => [],
                    'reason' => 'no kit serial on the assignment'];
        }
        $rows = $this->rows();
        if ($rows === null) {
            return ['available' => false, 'cycles' => [],
                    'reason' => 'the data-report plugin has written no usage file here'];
        }

        $mine = [];
        foreach ($rows as $r) {
            if (EquipmentAssignment::clean($r['kit_number'] ?? '') !== $serial) continue;
            $mine[] = $r;
        }
        usort($mine, static fn(array $a, array $b): int =>
            strcmp((string)($a['cycle_key'] ?? ''), (string)($b['cycle_key'] ?? '')));

        return $mine === []
            ? ['available' => false, 'cycles' => [],
               'reason' => 'no telemetry has been collected for this kit yet']
            : ['available' => true, 'cycles' => $mine, 'reason' => ''];
    }

    /** The cycle in progress — the newest one recorded. */
    public function currentCycle(string $serial): ?array
    {
        $u = $this->forKit($serial);
        return $u['available'] ? end($u['cycles']) : null;
    }

    /**
     * What one kit has used this cycle, measured against what was sold.
     *
     * @param array|null $plan from ServicePlan::fromService()
     * @return array{known:bool, used_gb:float|null, cap_gb:float, unlimited:bool,
     *               pct:float|null, remaining_gb:float|null, cycle:string, reason:string}
     */
    public function against(string $serial, ?array $plan): array
    {
        $out = ['known' => false, 'used_gb' => null, 'cap_gb' => 0.0, 'unlimited' => false,
                'pct' => null, 'remaining_gb' => null, 'cycle' => '', 'reason' => ''];

        $cycle = $this->currentCycle($serial);
        if ($cycle === null) {
            $out['reason'] = $this->forKit($serial)['reason'];
            return $out;
        }

        $out['used_gb'] = round((float)($cycle['total_gb'] ?? 0), 2);
        $out['cycle']   = (string)($cycle['cycle_label'] ?? $cycle['cycle_key'] ?? '');

        if ($plan === null || empty($plan['known'])) {
            // Usage is real, the allowance is not known. Say exactly that
            // rather than implying either a cap or the absence of one.
            $out['reason'] = 'used '  . number_format($out['used_gb'], 1)
                           . ' GB, but the uCRM service names no allowance';
            return $out;
        }

        $out['known']     = true;
        $out['unlimited'] = (bool)$plan['unlimited'];
        $out['cap_gb']    = (float)$plan['cap_gb'];
        if (!$out['unlimited'] && $out['cap_gb'] > 0) {
            $out['pct']          = round(min(100, $out['used_gb'] / $out['cap_gb'] * 100), 1);
            $out['remaining_gb'] = round(max(0.0, $out['cap_gb'] - $out['used_gb']), 1);
        }
        return $out;
    }

    /**
     * Every kit a customer holds, with its usage — the whole chain in one call.
     *
     * @param callable|null $planFor given an assignment row, its ServicePlan
     *                               (CrmKitAttribute::planFor), or null offline
     * @return array<int,array<string,mixed>>
     */
    public function forClient(int $clientId, ?callable $planFor = null): array
    {
        if (!$this->ea) return [];
        $out = [];
        foreach ($this->ea->forClient($clientId) as $a) {
            $plan = $planFor ? $planFor($a) : null;
            $out[] = [
                'assignment_id' => (int)$a['id'],
                'kit_serial'    => (string)$a['kit_serial'],
                'service_id'    => $a['crm_service_id'] === null ? null : (int)$a['crm_service_id'],
                'plan'          => $plan,
                'usage'         => $this->against((string)$a['kit_serial'], $plan),
            ];
        }
        return $out;
    }
}

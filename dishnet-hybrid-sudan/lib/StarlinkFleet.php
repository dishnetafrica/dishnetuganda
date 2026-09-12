<?php
declare(strict_types=1);

require_once __DIR__ . '/EquipmentAssignment.php';
require_once __DIR__ . '/ServicePlan.php';
require_once __DIR__ . '/KitUsage.php';
require_once __DIR__ . '/StarlinkServiceState.php';

/**
 * StarlinkFleet — every Starlink customer on one list.
 *
 * This is the screen South Sudan's data-report plugin has had for years and
 * Uganda did not: one row per kit in the field, showing who owns it, what
 * they were sold, what they have used, and which Starlink identifiers it
 * answers to.
 *
 * ── WHY THIS READS equipment_assignments AND NOTHING ELSE ───────────────
 *
 * South Sudan builds the same list from sl_kits.json — a file the data
 * plugin writes — and tabs/admin/starlink_suspensions.php still does the
 * same here. That makes the fleet list agree with whatever the last sync
 * happened to see. A customer with a correct assignment but no row in that
 * file is invisible; a stale row keeps a returned kit on the list under its
 * old owner.
 *
 * Ownership has exactly one source, and this asks it.
 *
 * ── THREE KINDS OF NOT KNOWING ──────────────────────────────────────────
 *
 * The list is only worth having if it is honest about its gaps, and there
 * are three distinct ones that a careless screen would render identically:
 *
 *   1. No usage file at all — the data plugin has never run here.
 *   2. A usage file with nothing for this kit — it ran, this kit is silent
 *      (a dead cookie, a tombstoned service line, a kit not yet live).
 *   3. Usage collected, but the uCRM service names no allowance — so the
 *      figure is real and the percentage is unknowable.
 *
 * None of those is "0 GB", and none of them is "unlimited". Each row carries
 * the reason it cannot answer, and the summary counts them separately so an
 * operator can see whether the fleet is quiet or the pipeline is broken.
 */
final class StarlinkFleet
{
    private EquipmentAssignment $ea;
    private KitUsage $usage;
    private StarlinkServiceState $state;
    private $store;
    /** @var object|null CrmKitAttribute, when uCRM is reachable */
    private $kit;
    private ?array $clients = null;

    public function __construct(EquipmentAssignment $ea, KitUsage $usage, $store, $kit = null,
                                ?StarlinkServiceState $state = null)
    {
        $this->ea    = $ea;
        $this->usage = $usage;
        $this->store = $store;
        $this->kit   = $kit;
        // Default-constructed rather than required, so every existing caller
        // gains the Starlink state without being edited. It reads one sibling
        // file and reports honestly when there is none.
        $this->state = $state ?? new StarlinkServiceState();
    }

    /**
     * The fleet.
     *
     * @return array{rows:array<int,array<string,mixed>>, summary:array<string,int>,
     *               telemetry:array{available:bool, reason:string},
     *               starlink:array{available:bool, lines:int, reason:string},
     *               unclaimed:array<int,array<string,string>>}
     */
    public function build(): array
    {
        $live = $this->ea->liveAssignments();

        // One survey call for the whole fleet rather than one per row — it
        // reads uCRM, and a per-row read would make this screen quadratic in
        // customers for no extra truth.
        $labels = [];
        if ($this->kit !== null) {
            try {
                foreach ($this->kit->survey($live) as $s) $labels[(int)$s['assignment']] = $s;
            } catch (\Throwable $e) { $labels = []; }
        }

        $rows  = [];
        $lines = [];
        foreach ($live as $a) {
            $id     = (int)$a['id'];
            $serial = (string)$a['kit_serial'];
            $line   = (string)($a['starlink_service_line'] ?? '');
            $plan   = $this->planFor($a);
            if (trim($line) !== '') $lines[] = $line;

            $rows[] = [
                'assignment_id'  => $id,
                'client_id'      => (int)$a['crm_client_id'],
                'client_name'    => $this->clientName((int)$a['crm_client_id']),
                'service_id'     => $a['crm_service_id'] === null ? 0 : (int)$a['crm_service_id'],
                'kit_serial'     => $serial,
                'terminal_id'    => (string)($a['terminal_id'] ?? ''),
                'router_id'      => (string)($a['router_id'] ?? ''),
                'service_line'   => (string)($a['starlink_service_line'] ?? ''),
                'account'        => (string)($a['starlink_account'] ?? ''),
                'assigned_at'    => (string)($a['assigned_at'] ?? ''),
                'plan'           => $plan,
                'usage'          => $this->usage->against($serial, $plan),
                // What Starlink says about the line, joined on the service
                // line. Separate from usage on purpose: a line can be active
                // with no telemetry collected, and both are worth knowing.
                'live'           => $this->state->forLine($line),
                // 'unknown' when uCRM could not be read — which is not the
                // same as 'missing', and must not be shown as a fault.
                'label'          => $labels[$id]['state'] ?? 'unknown',
                'label_theirs'   => $labels[$id]['theirs'] ?? [],
            ];
        }

        return ['rows'      => $rows,
                'summary'   => $this->summarise($rows),
                'telemetry' => $this->telemetry(),
                'starlink'  => $this->state->available(),
                // Lines Starlink knows about that no assignment claims —
                // an unrecorded install, or a subscription still running
                // for a customer who has gone.
                'unclaimed' => $this->state->unclaimed($lines)];
    }

    /** Whether the data plugin has written anything here at all. */
    public function telemetry(): array
    {
        return $this->usage->rows() === null
            ? ['available' => false,
               'reason' => 'The data-report plugin has written no usage file on this server, '
                         . 'so no kit can show a reading. This is not the same as a fleet at zero.']
            : ['available' => true, 'reason' => ''];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function summarise(array $rows): array
    {
        $s = ['kits' => count($rows), 'customers' => 0, 'unserviced' => 0,
              'usage_known' => 0, 'usage_silent' => 0,
              'allowance_known' => 0, 'allowance_unknown' => 0,
              'over_cap' => 0,
              'label_match' => 0, 'label_missing' => 0, 'label_differs' => 0,
              // Starlink subscription state, counted separately from usage.
              'live_known' => 0, 'live_silent' => 0, 'live_stale' => 0,
              'live_active' => 0, 'live_pending' => 0, 'live_suspended' => 0,
              'live_paused' => 0, 'live_standby' => 0, 'live_inactive' => 0,
              'no_service_line' => 0];

        $seen = [];
        foreach ($rows as $r) {
            $seen[$r['client_id']] = true;
            if ($r['service_id'] <= 0) $s['unserviced']++;

            // A reading exists when a cycle was found, whether or not the
            // allowance is known — those are separate questions.
            if ($r['usage']['used_gb'] === null) $s['usage_silent']++; else $s['usage_known']++;

            if (!empty($r['plan']['known'])) $s['allowance_known']++; else $s['allowance_unknown']++;
            if (($r['usage']['pct'] ?? null) !== null && $r['usage']['pct'] >= 100) $s['over_cap']++;

            if (isset($s['label_' . $r['label']])) $s['label_' . $r['label']]++;

            // A line we never recorded is a different failure from one the
            // data plugin cannot see, and both differ from a silent Starlink.
            if (trim((string)$r['service_line']) === '') $s['no_service_line']++;
            if (!empty($r['live']['known'])) {
                $s['live_known']++;
                if (isset($s['live_' . $r['live']['status']])) $s['live_' . $r['live']['status']]++;
                if (!empty($r['live']['stale'])) $s['live_stale']++;
            } else {
                $s['live_silent']++;
            }
        }
        $s['customers'] = count($seen);
        return $s;
    }

    /** The plan on this assignment's service, or null when there is none to read. */
    private function planFor(array $a): ?array
    {
        if ($this->kit === null) return null;
        try { return $this->kit->planFor($a); } catch (\Throwable $e) { return null; }
    }

    /**
     * The customer's name, for the eye only.
     *
     * Returns '' when the cache does not have them, and the caller shows the
     * id instead. A name is never invented, and nothing here matches on one.
     */
    private function clientName(int $id): string
    {
        if ($this->clients === null) {
            $this->clients = [];
            try {
                foreach (($this->store->load('ucrm_clients_cache.json') ?? []) as $c) {
                    $cid = (int)($c['id'] ?? 0);
                    if ($cid <= 0) continue;
                    $n = trim(((string)($c['firstName'] ?? '')) . ' ' . ((string)($c['lastName'] ?? '')));
                    if ($n === '') $n = trim((string)($c['companyName'] ?? ''));
                    if ($n !== '') $this->clients[$cid] = $n;
                }
            } catch (\Throwable $e) { $this->clients = []; }
        }
        return $this->clients[$id] ?? '';
    }
}

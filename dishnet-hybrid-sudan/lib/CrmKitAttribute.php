<?php
declare(strict_types=1);

require_once __DIR__ . '/ServicePlan.php';
require_once __DIR__ . '/EquipmentAssignment.php';

/**
 * CrmKitAttribute — show the kit on the uCRM service, without ever trusting it.
 *
 * South Sudan puts the kit serial in a uCRM service attribute and treats it as
 * the binding. That is why a rename or a typo there could take a customer's
 * internet down. Uganda keeps the binding in equipment_assignments, where the
 * database can defend it.
 *
 * But South Sudan gets one thing from that convention that Uganda lost: an
 * operator looking at a service in uCRM can SEE which dish it is. Nobody
 * should have to open a terminal to answer that.
 *
 * So the flow is one-way and the direction is the whole point:
 *
 *     equipment_assignments  ──writes──▶  uCRM service attribute
 *                            ◀─never reads for a decision─
 *
 * The attribute is a label. If someone edits it by hand, this reports the
 * disagreement — it does not obey it, and it does not silently overwrite it
 * either. A human changed something; a human should see that they did.
 */
final class CrmKitAttribute
{
    /** The attribute a fresh install should create, matching South Sudan. */
    public const PREFERRED_KEY = 'starlinkDetails';

    private $crm;
    private EquipmentAssignment $ea;
    /** Per-instance, deliberately. A `static` inside the method would be
     *  shared by every CrmKitAttribute ever made in the process, so one
     *  object's empty lookup would answer for another's — the same bug that
     *  had ReportingService reporting an empty install's figures for a full
     *  one. It has been written twice in this codebase already. */
    private array $serviceCache = [];

    public function __construct($crm, EquipmentAssignment $ea)
    {
        $this->crm = $crm;
        $this->ea  = $ea;
    }

    /**
     * What uCRM says versus what the assignment says, for every live
     * assignment that names a service.
     *
     * @return array<int,array{assignment:int, service:int, client:int,
     *                         ours:string, theirs:string[], state:string}>
     *         state: 'match' | 'missing' | 'differs' | 'no_service'
     */
    public function survey(array $assignments): array
    {
        $out = [];
        foreach ($assignments as $a) {
            $serviceId = $a['crm_service_id'] === null ? 0 : (int)$a['crm_service_id'];
            $ours      = strtoupper(trim((string)$a['kit_serial']));
            $row = ['assignment' => (int)$a['id'], 'service' => $serviceId,
                    'client' => (int)$a['crm_client_id'], 'ours' => $ours,
                    'theirs' => [], 'state' => 'no_service'];

            if ($serviceId > 0 && $ours !== '') {
                $svc = $this->service($serviceId);
                if ($svc === null) {
                    $row['state'] = 'no_service';
                } else {
                    $row['theirs'] = ServicePlan::kitsOnService($svc);
                    if ($row['theirs'] === [])                       $row['state'] = 'missing';
                    elseif (in_array($ours, $row['theirs'], true))   $row['state'] = 'match';
                    else                                             $row['state'] = 'differs';
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Write the assignment's serial onto its uCRM service.
     *
     * Only where the attribute is absent, unless $overwrite — because a
     * different serial already on the service means a person put it there,
     * and quietly replacing it destroys the only evidence of a disagreement
     * worth investigating.
     *
     * @return array{ok:bool, error?:string, written:int, skipped:int, notes:string[]}
     */
    public function write(array $rows, int $attributeId, bool $overwrite = false, bool $commit = false): array
    {
        if ($attributeId <= 0) {
            return ['ok' => false, 'written' => 0, 'skipped' => 0, 'notes' => [],
                    'error' => 'No service custom attribute for the kit serial exists in uCRM. '
                             . 'Create one named "' . self::PREFERRED_KEY . '" under '
                             . 'System → Customisation → Custom attributes → Service.'];
        }

        $written = 0; $skipped = 0; $notes = [];
        foreach ($rows as $r) {
            if ($r['state'] === 'match')      { $skipped++; continue; }
            if ($r['state'] === 'no_service') {
                $skipped++;
                $notes[] = 'assignment #' . $r['assignment'] . ' has no uCRM service, so there is '
                         . 'nothing to label — give it one with assign_kit --service';
                continue;
            }
            if ($r['state'] === 'differs' && !$overwrite) {
                $skipped++;
                $notes[] = 'uCRM service #' . $r['service'] . ' says ' . implode(', ', $r['theirs'])
                         . ' where the assignment says ' . $r['ours']
                         . ' — somebody typed that; --overwrite replaces it';
                continue;
            }
            if (!$commit) { $written++; continue; }

            $res = $this->crm->patch('clients/services/' . $r['service'], [
                'attributes' => [['customAttributeId' => $attributeId, 'value' => $r['ours']]],
            ]);
            if (!is_array($res)) {
                $notes[] = 'uCRM refused the write on service #' . $r['service'];
                $skipped++;
                continue;
            }
            $written++;
        }
        return ['ok' => true, 'written' => $written, 'skipped' => $skipped, 'notes' => $notes];
    }

    /** The uCRM custom attribute the serial goes in, or 0 if none exists. */
    public function attributeId(): int
    {
        $list = $this->crm->get('custom-attributes');
        return is_array($list) ? ServicePlan::kitAttributeId($list) : 0;
    }

    /**
     * The plan a uCRM service sells, for one assignment.
     *
     * This is the half South Sudan's customer page skips — and why a customer
     * on 6TB is shown "Unlimited" there.
     *
     * @return array{raw:string,display:string,cap_gb:float,unlimited:bool,source:string}|null
     */
    public function planFor(array $assignment): ?array
    {
        $serviceId = $assignment['crm_service_id'] === null ? 0 : (int)$assignment['crm_service_id'];
        if ($serviceId <= 0) return null;
        $svc = $this->service($serviceId);
        return $svc === null ? null : ServicePlan::fromService($svc);
    }

    /** One service, cached for the life of the request. */
    private function service(int $id): ?array
    {
        if (array_key_exists($id, $this->serviceCache)) return $this->serviceCache[$id];
        try {
            $r = $this->crm->get('clients/services/' . $id);
            return $this->serviceCache[$id] = (is_array($r) && !empty($r['id'])) ? $r : null;
        } catch (\Throwable $e) {
            return $this->serviceCache[$id] = null;
        }
    }
}

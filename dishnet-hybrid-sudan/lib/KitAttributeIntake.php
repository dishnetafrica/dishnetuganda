<?php
declare(strict_types=1);

require_once __DIR__ . '/EquipmentAssignment.php';

/**
 * KitAttributeIntake — the Kit Number typed on a uCRM service, taken as INPUT.
 *
 * ── THE DECISION THIS IMPLEMENTS ────────────────────────────────────────
 *
 * South Sudan's workflow is: sell it, create the Starlink account by hand,
 * then type the kit number into a custom field on the customer's uCRM
 * service. That field is the bridge between our commercial record and
 * Starlink's operational data, and it is where the operator already is.
 *
 * Uganda's binding lives in equipment_assignments, where the database defends
 * it: a serial must exist in stock, a service must belong to its client, and
 * two customers cannot hold one kit.
 *
 * Those were treated as alternatives. They are not. This takes the typed
 * field as an INPUT and the assignment as the STORE:
 *
 *     uCRM service attribute ──read──▶ validated ──▶ equipment_assignments
 *                            ◀─label written back by crm_kit_label.php─
 *
 * So a person types it once, in uCRM, exactly as in South Sudan — and every
 * guarantee still holds, because nothing is written until the same checks
 * tools/assign_kit.php makes have passed.
 *
 * ── WHAT IT WILL NOT DO ─────────────────────────────────────────────────
 *
 * It never overwrites an existing assignment, never releases a kit, and never
 * moves one between customers. Those destroy history, and a typo in a text
 * field must not be able to do them. Anything it cannot safely act on is
 * REPORTED with the reason, which is the whole point: a refusal a person can
 * read is worth more than a silent skip.
 *
 * ── IT AGREES WITH dishnet-data-report BY CONSTRUCTION ───────────────────
 *
 * kitsFromService() reproduces that plugin's extraction exactly — the same
 * accepted key spellings, the same comma splitting, the same KIT pattern, and
 * the same fallback to the service name and invoice label. If the two read
 * the same field differently, one of them would bind a customer the other
 * does not, and a test pins the agreement.
 */
final class KitAttributeIntake
{
    /** Accepted attribute keys, normalised. Verbatim from dishnet-data-report. */
    const ATTR_KEYS = ['starlinkdetails', 'kitnumber', 'starlinkkit', 'kitno', 'kit'];

    /** A kit serial as both plugins recognise one. */
    const KIT_PATTERN = '/^KIT[0-9A-Z]{4,}$/i';

    /** uCRM service statuses worth reading. 1 active, 2 prepared, 3 suspended. */
    const LIVE_STATUSES = [1, 2, 3];

    private \PDO $db;
    private EquipmentAssignment $ea;
    /** @var object|null CrmApiClient */
    private $crm;

    public function __construct(\PDO $db, EquipmentAssignment $ea, $crm = null)
    {
        $this->db  = $db;
        $this->ea  = $ea;
        $this->crm = $crm;
    }

    // ── Reading the field ───────────────────────────────────────────────

    /**
     * Every kit number a uCRM service carries, however it was typed.
     *
     * Pure: give it a service as uCRM returns it, get the serials out. The
     * fallback to name/invoiceLabel is not tidiness — operators put the kit in
     * the service name for years before the attribute existed, and those
     * customers are still live.
     *
     * @return array<int,string> upper-cased, de-duplicated, in the order found
     */
    public static function kitsFromService(array $service): array
    {
        $out = [];
        foreach ((array)($service['attributes'] ?? []) as $attr) {
            if (!is_array($attr)) continue;
            $key = strtolower((string)preg_replace('/[\s_\-]+/', '', (string)($attr['key'] ?? '')));
            if (!in_array($key, self::ATTR_KEYS, true)) continue;
            $val = trim((string)($attr['value'] ?? ''));
            if ($val === '') continue;
            foreach (array_map('trim', explode(',', $val)) as $candidate) {
                $up = strtoupper($candidate);
                if (preg_match(self::KIT_PATTERN, $up)) $out[] = $up;
            }
        }
        if ($out === []) {
            $scan = (string)($service['name'] ?? '') . ' ' . (string)($service['invoiceLabel'] ?? '');
            if (preg_match_all('/\bKIT[0-9A-Z]{4,}\b/i', $scan, $m)) {
                foreach ($m[0] as $k) $out[] = strtoupper($k);
            }
        }
        return array_values(array_unique($out));
    }

    // ── Deciding ────────────────────────────────────────────────────────

    /**
     * What the typed fields would change, and what they cannot.
     *
     * Reads uCRM once for every service, then answers entirely from the
     * database. Writes nothing.
     *
     * @return array{proposals:array<int,array<string,mixed>>,
     *               refusals:array<int,array<string,mixed>>,
     *               settled:array<int,array<string,mixed>>,
     *               services:int, scanned:int, reachable:bool}
     *
     * `reachable` false means uCRM did not answer. It is NOT the same as
     * "there are no services", and a caller that renders both as three empty
     * sections tells an operator everything is fine when nothing was read at
     * all — the empty-reads-as-zero confusion this codebase keeps undoing.
     */
    public function scan(): array
    {
        $out = ['proposals' => [], 'refusals' => [], 'settled' => [],
                'services' => 0, 'scanned' => 0, 'reachable' => false];
        if ($this->crm === null) return $out;

        $services = $this->crm->get('clients/services?limit=1000');
        if (!is_array($services)) return $out;
        $out['reachable'] = true;
        $out['services']  = count($services);

        foreach ($services as $svc) {
            if (!is_array($svc)) continue;
            if (!in_array((int)($svc['status'] ?? 0), self::LIVE_STATUSES, true)) continue;

            $clientId  = (int)($svc['clientId'] ?? 0);
            $serviceId = (int)($svc['id'] ?? 0);
            if ($clientId <= 0 || $serviceId <= 0) continue;

            foreach (self::kitsFromService($svc) as $serial) {
                $out['scanned']++;
                $verdict = $this->judge($serial, $clientId, $serviceId, (string)($svc['name'] ?? ''));
                if ($verdict['action'] === 'assign')      $out['proposals'][] = $verdict;
                elseif ($verdict['action'] === 'settled') $out['settled'][]   = $verdict;
                else                                      $out['refusals'][]  = $verdict;
            }
        }
        return $out;
    }

    /**
     * One kit on one service: assign it, leave it, or refuse and say why.
     *
     * The order of these checks is the order that produces the most useful
     * sentence. "Already assigned to somebody else" is more informative than
     * "not in stock" for a kit that is both.
     */
    private function judge(string $serial, int $clientId, int $serviceId, string $svcName): array
    {
        $base = ['kit' => $serial, 'client' => $clientId, 'service' => $serviceId,
                 'service_name' => $svcName, 'action' => '', 'reason' => '', 'unit_id' => 0];

        // Is this kit already bound? By serial, because that is what was typed.
        $held = $this->liveBySerial($serial);
        if ($held !== null) {
            if ((int)$held['crm_client_id'] === $clientId) {
                // Ours already. Note a missing service id rather than fixing it:
                // changing a live assignment is not something a text field may do.
                $onService = (int)($held['crm_service_id'] ?? 0);
                if ($onService === 0) {
                    return array_merge($base, ['action' => 'refuse', 'reason' => 'service_missing',
                        'detail' => 'assignment #' . (int)$held['id'] . ' has no uCRM service recorded; '
                                  . 'the attribute says service #' . $serviceId
                                  . '. Add it with tools/assign_kit.php rather than from a text field.']);
                }
                if ($onService !== $serviceId) {
                    return array_merge($base, ['action' => 'refuse', 'reason' => 'service_differs',
                        'detail' => 'assignment #' . (int)$held['id'] . ' is on service #' . $onService
                                  . ', the attribute is on service #' . $serviceId]);
                }
                return array_merge($base, ['action' => 'settled', 'reason' => 'already_bound',
                    'assignment' => (int)$held['id'],
                    'detail' => 'assignment #' . (int)$held['id'] . ' already binds it to client #'
                              . $clientId . ' on service #' . $serviceId]);
            }
            return array_merge($base, ['action' => 'refuse', 'reason' => 'held_elsewhere',
                'detail' => 'assignment #' . (int)$held['id'] . ' already gives it to client #'
                          . (int)$held['crm_client_id'] . '. Release it first — that keeps the history.']);
        }

        // In stock? A serial nobody received is a typo, not equipment.
        $unit = $this->unitBySerial($serial);
        if ($unit === null) {
            return array_merge($base, ['action' => 'refuse', 'reason' => 'not_in_stock',
                'detail' => 'no stock unit has this serial, so assigning it would invent '
                          . 'inventory. Receive it on the Stock screen, or import the '
                          . 'Starlink order it came on.']);
        }

        // Is another kit already on this service? Two dishes on one subscription
        // makes every later suspension ambiguous.
        $onSvc = $this->ea->activeForService($serviceId);
        if ($onSvc !== null) {
            return array_merge($base, ['action' => 'refuse', 'reason' => 'service_taken',
                'detail' => 'service #' . $serviceId . ' already runs on '
                          . (string)($onSvc['kit_serial'] ?? ('unit #' . (int)$onSvc['unit_id']))
                          . ' (assignment #' . (int)$onSvc['id'] . ')']);
        }

        return array_merge($base, ['action' => 'assign', 'reason' => 'ready',
                                   'unit_id' => (int)$unit['id']]);
    }

    // ── Writing ─────────────────────────────────────────────────────────

    /**
     * Create the assignments a scan proposed.
     *
     * Every one goes through EquipmentAssignment::assign(), so the same
     * constraints apply as when a person runs assign_kit.php by hand. A
     * proposal that has gone stale since the scan is refused there, not here.
     *
     * @return array{created:array<int,array<string,mixed>>, failed:array<int,array<string,mixed>>}
     */
    public function apply(array $proposals, array $actor): array
    {
        $out = ['created' => [], 'failed' => []];
        foreach ($proposals as $p) {
            if (($p['action'] ?? '') !== 'assign') continue;
            $r = $this->ea->assign([
                'unit_id'        => (int)$p['unit_id'],
                'crm_client_id'  => (int)$p['client'],
                'crm_service_id' => (int)$p['service'],
                'note'           => 'from the uCRM service Kit Number field',
            ], $actor);
            if (!empty($r['ok'])) {
                $out['created'][] = $p + ['assignment' => (int)($r['id'] ?? 0)];
            } else {
                $out['failed'][] = $p + ['detail' => (string)($r['error'] ?? 'assign refused')];
            }
        }
        return $out;
    }

    /**
     * A fingerprint of the whole binding table.
     *
     * Taken before and after a scan, an unchanged value is evidence rather
     * than assurance: "the dry run wrote nothing" should be something the run
     * itself demonstrates, not something the reader has to take on trust.
     *
     * @return array{rows:int, live:int, digest:string}
     */
    public function fingerprint(): array
    {
        $rows = (int)$this->db->query('SELECT COUNT(*) FROM equipment_assignments')->fetchColumn();
        $live = (int)$this->db->query(
            'SELECT COUNT(*) FROM equipment_assignments WHERE released_at IS NULL')->fetchColumn();
        $all = $this->db->query(
            'SELECT id, crm_client_id, crm_service_id, kit_serial, released_at
               FROM equipment_assignments ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return ['rows' => $rows, 'live' => $live,
                'digest' => hash('sha256', (string)json_encode($all))];
    }

    // ── plumbing ────────────────────────────────────────────────────────

    /** A live assignment holding this serial, by serial rather than unit id. */
    private function liveBySerial(string $serial): ?array
    {
        $st = $this->db->prepare(
            'SELECT * FROM equipment_assignments
              WHERE released_at IS NULL AND UPPER(kit_serial) = ? LIMIT 1');
        $st->execute([EquipmentAssignment::clean($serial)]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    private function unitBySerial(string $serial): ?array
    {
        $st = $this->db->prepare(
            'SELECT * FROM stock_units WHERE UPPER(serial_number) = ? LIMIT 1');
        $st->execute([EquipmentAssignment::clean($serial)]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }
}

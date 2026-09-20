<?php
declare(strict_types=1);
namespace Dn\Jobs;

use Dn\Db\Database;
use Dn\Delivery\RouterOs\RestClient;
use Dn\Devices\DeviceRegistry;
use Dn\Telemetry\UplinkRepository;
use Dn\Tenancy\TenantContext;
use Throwable;

/**
 * Reads throughput off each managed router and stores it.
 *
 * Lives in Jobs/ because it opens a connection to a router, and only Jobs/
 * may (F2, enforced by a guard). The customer-facing route never reads a
 * router live — it reads stored samples, so a slow or unreachable device
 * makes a page stale rather than making it hang.
 *
 * A sampler that cannot reach a device records nothing. It does not record a
 * zero: zero throughput and no measurement are different facts, and
 * conflating them would draw a graph showing an idle link when what actually
 * happened is that DishNet could not see it.
 *
 * Audit finding R4 (docs/57 §1.1) made that rule bite in three more places.
 * This sampler used to look for an interface named `ether1`, which is a guess
 * about the customer's cabling dressed up as a constant. Where the guess was
 * wrong it measured a LAN bridge and recorded numbers that looked perfectly
 * reasonable — the dangerous failure, because nothing goes red and the graph
 * still draws.
 *
 * So the WAN interface is now a FACT established at staging and stored on the
 * device (migration 016), and there are three distinct ways to have no
 * measurement, none of which is a zero and none of which falls back to another
 * interface:
 *
 *   no_wan     nobody established which interface is the uplink
 *   wan_absent the device does not report the interface that was established
 *   unreachable we could not talk to the device at all
 *
 * They are counted separately because they are different problems for whoever
 * reads the counters: the first is a provisioning omission, the second is a
 * device that changed under us, the third is a network fault.
 */
final class UplinkSampler
{
    /** @param null|callable(array):RestClient $clientFactory seam for tests */
    public function __construct(
        private Database $db,
        private $clientFactory = null,
        private ?TenantContext $ctx = null,
    ) {
        $this->ctx ??= new TenantContext($this->db);
    }

    /** @return array{sampled:int,skipped:int,unreachable:int} */
    public function runOnce(): array
    {
        // Via the admin function: the sampler serves every customer and
        // cannot set a tenant context before it knows whose device it is
        // about to read, so an ordinary SELECT returns nothing under RLS.
        // The function hands back the id and tunnel address and nothing else.
        $devices = $this->db->query('SELECT * FROM mt_devices_samplable()');

        $out = ['sampled' => 0, 'skipped' => 0, 'no_wan' => 0,
                'wan_absent' => 0, 'unreachable' => 0];

        foreach ($devices as $device) {
            try {
                // Enter this device's OWN tenant context before touching
                // anything — the same thing the intent worker does. Audit
                // finding S1: reading credentials across customers is not
                // something a worker needs, so it no longer happens.
                // Established at staging, or not established at all. Checked
                // before the device is contacted: if we would not know what to
                // do with the answer, there is no reason to ask for it.
                $established = trim((string) ($device['wan_interface'] ?? ''));
                if ($established === '') { $out['no_wan']++; continue; }

                $result = $this->ctx->run($device['customer_id'],
                    function (Database $db) use ($device, $established) {
                        $client = $this->client($db, $device);
                        $res = $client->get('interface');
                        if ($res['status'] >= 400) { return 'unreachable'; }

                        $wan = $this->wan((array) ($res['body'] ?? []), $established);
                        if ($wan === null) { return 'wan_absent'; }

                        // R4 again, one layer down. Defaulting an absent rate
                        // key to 0 would record "the link was idle" when what
                        // happened is that the device did not report a rate —
                        // the same lie as guessing the interface, in a field
                        // rather than a row. R7 (whether these keys exist at
                        // all on a real unit) is still unverified, so this is
                        // the difference between a blank graph and a false one.
                        $rx = $wan['rx-bits-per-second'] ?? null;
                        $tx = $wan['tx-bits-per-second'] ?? null;
                        if (!is_numeric($rx) || !is_numeric($tx)) { return 'wan_absent'; }

                        $sessions = (int) ($db->one(
                            'SELECT count(*) AS n FROM mt_sessions WHERE device_id = ?',
                            [$device['id']])['n'] ?? 0);

                        return (new UplinkRepository($db))->record(
                            $device['id'], (int) $rx, (int) $tx, $sessions)
                                ? 'sampled' : 'skipped';
                    });
                $out[$result]++;
            } catch (Throwable) {
                // Unreachable is recorded as unreachable, never as zero.
                $out['unreachable']++;
            }
        }
        return $out;
    }

    /**
     * The established interface, or nothing.
     *
     * Matched on `name` alone, and exactly. Not `default-name`: the name is
     * what the person staging the device read off it, and following a rename
     * through default-name would silently re-point the measurement at an
     * interface nobody chose. A rename should surface as wan_absent — a device
     * that changed under us is worth noticing, and is the operator's to
     * re-establish.
     *
     * There is deliberately no fallback. Returning "some other interface"
     * when the established one is missing is exactly the ether1 bug with an
     * extra step.
     */
    private function wan(array $interfaces, string $established): ?array
    {
        foreach ($interfaces as $i) {
            if (($i['name'] ?? null) === $established) { return $i; }
        }
        return null;
    }

    private function client(Database $db, array $device): RestClient
    {
        if ($this->clientFactory !== null) { return ($this->clientFactory)($device); }
        $creds = (new DeviceRegistry($db))->credentials($device['id']);
        if ($creds === null) { throw new \RuntimeException('no credentials for device'); }
        return new RestClient($device['tunnel_ip'], $creds['username'], $creds['password']);
    }
}

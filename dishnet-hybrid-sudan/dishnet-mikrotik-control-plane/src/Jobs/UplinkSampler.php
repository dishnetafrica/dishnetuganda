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

        $out = ['sampled' => 0, 'skipped' => 0, 'unreachable' => 0];

        foreach ($devices as $device) {
            try {
                // Enter this device's OWN tenant context before touching
                // anything — the same thing the intent worker does. Audit
                // finding S1: reading credentials across customers is not
                // something a worker needs, so it no longer happens.
                $result = $this->ctx->run($device['customer_id'],
                    function (Database $db) use ($device) {
                        $client = $this->client($db, $device);
                        $res = $client->get('interface');
                        if ($res['status'] >= 400) { return 'unreachable'; }

                        $wan = $this->wan((array) ($res['body'] ?? []));
                        if ($wan === null) { return 'skipped'; }

                        $sessions = (int) ($db->one(
                            'SELECT count(*) AS n FROM mt_sessions WHERE device_id = ?',
                            [$device['id']])['n'] ?? 0);

                        return (new UplinkRepository($db))->record(
                            $device['id'], (int) ($wan['rx-bits-per-second'] ?? 0),
                            (int) ($wan['tx-bits-per-second'] ?? 0), $sessions)
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

    private function wan(array $interfaces): ?array
    {
        foreach ($interfaces as $i) {
            if (($i['name'] ?? '') === 'ether1' || ($i['default-name'] ?? '') === 'ether1') {
                return $i;
            }
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

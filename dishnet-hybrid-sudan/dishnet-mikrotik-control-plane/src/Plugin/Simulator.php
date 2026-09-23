<?php
declare(strict_types=1);
namespace Dn\Plugin;

use Dn\Db\Database;
use Dn\Devices\DeviceRegistry;
use Dn\Policy\PlanRepository;
use Dn\Sessions\AccountingIngest;
use Dn\Telemetry\UplinkRepository;
use Dn\Tenancy\TenantContext;
use Dn\Vouchers\VoucherService;

/**
 * One coherent, explicitly synthetic MikroTik estate.
 *
 * WHY THIS EXISTS. The panel was previously pointed at the test database, which
 * `tests/run.sh` drops and recreates on every run. What it displayed was
 * whatever fixture residue the last test file happened to leave behind — which
 * is how the Routers list came to say "no routers" while Router Detail showed a
 * router. There was no simulator dataset; there was debris.
 *
 * TWO RULES THIS CLASS KEEPS.
 *
 * 1. **Everything is built through the real Domain-B write paths.** Customers
 *    come from mt_customer_create, routers from mt_device_register, assignment
 *    from mt_device_assign, vouchers from VoucherService, sessions from RADIUS
 *    accounting ingest. Nothing is hand-inserted to make a screen look full. So
 *    the estate obeys every constraint and state-machine rule the real system
 *    has, and the audit trail appears because the acts really happened.
 *
 * 2. **Every identifier announces itself as simulated.** SIM-MT-0001,
 *    SIM-CUST-001, SIM-SITE-001. Nothing here can be mistaken at a glance for a
 *    production record, which is the property that matters when a screenshot of
 *    this ends up in a document.
 *
 * It does NOT contact a router, a RADIUS server or a WireGuard peer, and it
 * does not write last_seen_at to make the panel look alive.
 */
final class Simulator
{
    public const MARK = 'SIM-';

    /** @var list<string> */
    private array $log = [];

    public function __construct(private readonly bool $verbose = false) {}

    public function alreadyBuilt(): bool
    {
        // Across tenants WITHOUT BYPASSRLS, through the one path built for it:
        // the Admin read projection (migration 019), on the dnb_adminapi
        // identity that holds EXECUTE on it and no table privilege.
        //
        // A direct count would return zero however much exists: mt_devices is
        // RLS + FORCE, and with no tenant context `customer_id = NULL` is NULL
        // rather than true, so even unassigned stock is invisible.
        return count(Database::adminApi()->query(
            "SELECT id FROM mt_admin_routers() WHERE serial LIKE 'SIM-%'")) > 0;
    }

    /** @return list<string> */
    public function build(): array
    {
        $admin = Database::admin();
        $app   = Database::app();
        // The first owner of every simulated operator: mt_admin_principal_create
        // is dnb_adminwrite's, and since 027 the only creator outside a tenant.
        $adminWrite = Database::adminWrite();
        $ctx   = new TenantContext($app);

        // ── customers, each with a service, a principal and sites ──────────
        $estate = [];
        $plan   = [];
        foreach ([
            ['SIM-CUST-001', 'Riverside Hotel',  'sim-cust-001', ['SIM-SITE-001 Lobby', 'SIM-SITE-002 Poolside']],
            ['SIM-CUST-002', 'Kabale Hostel',    'sim-cust-002', ['SIM-SITE-003 Common room']],
            ['SIM-CUST-003', 'Mbarara Lodge',    'sim-cust-003', ['SIM-SITE-004 Reception', 'SIM-SITE-005 Annex']],
        ] as [$ref, $label, $radiusRef, $siteNames]) {
            $cid = $admin->one('SELECT mt_customer_create(?,?) AS id',
                               ["{$ref} {$label}", 'sim:seed'])['id'];
            // Inside the customer's own context: the tenant policy admits a row
            // whose id is the current customer, so no elevated role is needed.
            $ctx->run($cid, fn(Database $db) => $db->exec(
                'UPDATE mt_customers SET radius_ref = ? WHERE id = ?', [$radiusRef, $cid]));

            // The first owner of a new operator is created on the Admin plane and
            // nowhere else (migration 027, docs/116 D.9): dnb_app holds no INSERT
            // on mt_principals any more, and this is the real path.
            $p = ['id' => $adminWrite->one(
                'SELECT mt_admin_principal_create(?,?,?,?,?::text[],?) AS id',
                [$cid, 'owner', "{$ref} owner", '+2567' . substr(md5($ref), 0, 8), '{}', 'sim:seed'])['id']];
            $built = $ctx->run($cid, function (Database $db) use ($cid, $ref, $siteNames, $p) {
                $s = $db->one("INSERT INTO mt_services (customer_id, kind)
                               VALUES (?, 'mikrotik_hotspot') RETURNING id", [$cid]);
                $sites = [];
                foreach ($siteNames as $n) {
                    $sites[] = $db->one('INSERT INTO mt_sites (customer_id, service_id, name, location)
                                         VALUES (?,?,?,?) RETURNING id',
                                        [$cid, $s['id'], $n, 'simulated location'])['id'];
                }
                return ['principal' => $p['id'], 'service' => $s['id'], 'sites' => $sites];
            });
            $estate[$ref] = ['customer' => $cid] + $built;

            // One plan per customer, at its first site.
            $plan[$ref] = $ctx->run($cid, fn(Database $db) => (new PlanRepository($db))->create(
                ['name' => "{$ref} 1-hour", 'duration_s' => 3600,
                 'rate_down_bps' => 5_000_000, 'rate_up_bps' => 2_000_000,
                 'data_cap_bytes' => null, 'devices_per_voucher' => 1,
                 'mode' => 'elapsed', 'price_minor' => 2000, 'currency' => 'UGX'],
                $built['principal'], $built['sites'][0])['id']);
        }
        $this->say(count($estate) . ' simulated customers, each with a service, principal, sites and a plan');

        // ── routers, walked along the real state machine ───────────────────
        // Each stop is a legal transition (migration 012's trigger), so a state
        // that appears here is one a real router could actually be in.
        $reg = new DeviceRegistry($admin);
        $spec = [
            ['SIM-MT-0001', 'hAP ax2',  'SIM-CUST-001', 0, 'active',      'ether1'],
            ['SIM-MT-0002', 'hEX S',    'SIM-CUST-001', 1, 'provisioned', 'ether1'],
            ['SIM-MT-0003', 'hAP ac2',  'SIM-CUST-002', 0, 'connected',   null],
            ['SIM-MT-0004', 'hEX lite', null,           0, 'staged',      null],
            ['SIM-MT-0005', 'RB4011',   'SIM-CUST-003', 1, 'diverged',    'sfp-sfpplus1'],
        ];
        $routers = [];
        foreach ($spec as $i => [$serial, $model, $custRef, $siteIx, $target, $wan]) {
            $cur = $d = $reg->register($serial, $model, '7.14.3',
                                'SIM-WG-PUBKEY-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                                '10.99.0.' . ($i + 10), 'sim:staging-bench');
            if ($custRef !== null) {
                $e = $estate[$custRef];
                $cur = $reg->assign($d['id'], $e['customer'], $e['sites'][$siteIx],
                                    $serial . ' ' . $model, 'sim:field-engineer');
            }
            if ($wan !== null) { $cur = $reg->setWanInterface($d['id'], $wan, 'sim:field-engineer'); }
            foreach ($this->path($d['state'], $target) as $step) {
                $cur = $reg->transition($d['id'], $step, 'sim:provisioning');
            }
            // The row comes from whatever wrote it last. Re-reading it would
            // need an elevated identity: mt_devices is RLS + FORCE, so the admin
            // role with no tenant context sees nothing. Each write path already
            // returns the current row, so nothing has to be read back.
            $routers[$serial] = $cur;
        }
        $this->say(count($routers) . ' simulated routers across ' .
            count(array_unique(array_column($spec, 4))) . ' lifecycle states');

        // ── vouchers, issued through the real service ──────────────────────
        $vouchers = 0;
        foreach (['SIM-CUST-001' => 8, 'SIM-CUST-002' => 5, 'SIM-CUST-003' => 4] as $ref => $n) {
            $e = $estate[$ref];
            $out = $ctx->run($e['customer'], fn(Database $db) => (new VoucherService($db))->issueBatch(
                $plan[$ref], $n, $e['sites'][0], $e['principal']));
            $vouchers += count($out['vouchers']);
        }
        $this->say("{$vouchers} simulated vouchers in 3 batches, issued through the real service");

        // ── sessions, ingested as RADIUS accounting ────────────────────────
        // Through AccountingIngest on the dnb_radius identity, exactly as a NAS
        // would reach it. The usernames resolve through mt_hotspot_users.
        $ingest = new AccountingIngest(Database::radius());
        $users = [];
        foreach ($estate as $e) {
            foreach ($ctx->run($e['customer'], fn(Database $db) => $db->query(
                'SELECT radius_username FROM mt_hotspot_users ORDER BY created_at LIMIT 2'
            )) as $u) { $users[] = $u; }
        }
        $sessions = 0;
        foreach ($users as $k => $u) {
            $r = $ingest->record([
                'Acct-Status-Type'   => 'Start',
                'User-Name'          => $u['radius_username'],
                'Acct-Session-Id'    => 'SIM-SESS-' . str_pad((string) ($k + 1), 4, '0', STR_PAD_LEFT),
                'NAS-Identifier'     => 'SIM-NAS-00' . (($k % 3) + 1),
                'Calling-Station-Id' => 'SIM-MAC-' . str_pad((string) ($k + 1), 2, '0', STR_PAD_LEFT),
                'Framed-IP-Address'  => '10.50.0.' . ($k + 20),
            ]);
            if ($r['session_id'] !== null) { $sessions++; }
            if ($k % 2 === 0) {
                $ingest->record([
                    'Acct-Status-Type'  => 'Interim-Update',
                    'User-Name'         => $u['radius_username'],
                    'Acct-Session-Id'   => 'SIM-SESS-' . str_pad((string) ($k + 1), 4, '0', STR_PAD_LEFT),
                    'NAS-Identifier'    => 'SIM-NAS-00' . (($k % 3) + 1),
                    // Gigawords alongside octets: the 32-bit counter wraps at
                    // 4 GiB, and a reader that ignores the wrap count silently
                    // under-reports every long session.
                    'Acct-Input-Octets'    => 120_000_000 + $k * 7_000_000,
                    'Acct-Input-Gigawords' => $k % 3,
                    'Acct-Output-Octets'   => 40_000_000 + $k * 3_000_000,
                    'Acct-Output-Gigawords'=> ($k % 2),
                ]);
            }
        }
        $this->say("{$sessions} simulated sessions, ingested as RADIUS accounting");

        // ── uplink telemetry ───────────────────────────────────────────────
        $ctxW = new TenantContext(Database::worker());
        $samples = 0;
        foreach (['SIM-MT-0001', 'SIM-MT-0002', 'SIM-MT-0005'] as $serial) {
            $id = $routers[$serial]['id'];
            for ($t = 0; $t < 12; $t++) {
                $ok = $ctxW->runUnscoped(fn(Database $db) => (new UplinkRepository($db))->record(
                    $id, 3_000_000 + $t * 250_000, 900_000 + $t * 90_000, ($t % 4)));
                if ($ok) { $samples++; }
            }
        }
        $this->say("{$samples} simulated uplink samples on 3 routers");

        // ── provisioning jobs ──────────────────────────────────────────────
        $jobs = 0;
        foreach (['SIM-MT-0001', 'SIM-MT-0002', 'SIM-MT-0005'] as $serial) {
            // The Admin-plane path, exactly as the bound action route takes it
            // (migration 028, docs/121 D-15): dnb_adminwrite, the operator
            // DERIVED from the device row, a payload naming the device only,
            // and the audit row written by the function itself. These routers'
            // 10.99.0.x tunnel addresses lie outside 10.66/16, so the worker
            // still refuses the jobs as retryable (docs/120 §15.8.6) — a
            // recorded finding, deliberately not repaired here.
            $adminWrite->one('SELECT mt_device_provision_request(?,?,?) AS r',
                [$routers[$serial]['id'], 'SIM-JOB-' . $serial, 'sim:provisioning']);
            $jobs++;
        }
        $this->say("{$jobs} simulated provisioning jobs queued");

        $audit = 0;
        foreach ($estate as $e) {
            $audit += (int) $ctx->run($e['customer'], fn(Database $db) => $db->one(
                "SELECT count(*)::int c FROM mt_audit_log WHERE actor LIKE 'sim:%'"))['c'];
        }
        $this->say("{$audit} tenant-visible audit rows written by those acts, not inserted");

        return $this->log;
    }

    /** Legal path between two device states (migration 012's trigger). */
    private function path(string $from, string $to): array
    {
        $ladder = ['registered', 'staged', 'shipped', 'connected', 'provisioned', 'active'];
        $extra  = ['diverged' => 'active', 'orphaned' => 'connected'];
        $target = $extra[$to] ?? $to;
        $i = array_search($from, $ladder, true);
        $j = array_search($target, $ladder, true);
        if ($i === false || $j === false || $j < $i) { return []; }
        $steps = array_slice($ladder, $i + 1, $j - $i);
        if (isset($extra[$to])) { $steps[] = $to; }
        return $steps;
    }

    private function say(string $line): void
    {
        $this->log[] = $line;
        if ($this->verbose) { echo "  {$line}\n"; }
    }
}

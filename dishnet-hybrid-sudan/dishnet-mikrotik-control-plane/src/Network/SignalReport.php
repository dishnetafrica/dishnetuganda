<?php
declare(strict_types=1);
namespace Dn\Network;

/**
 * What this system can and cannot currently say about a router.
 *
 * This class exists because a network-management screen is mostly coloured
 * dots, and a coloured dot is a claim. The tempting version of the Router
 * Detail page shows "WireGuard ● Connected / RADIUS ● Healthy / HotSpot ●
 * Running". Measured, this system has **no source for any of those three**. A
 * green dot would not be a small inaccuracy; it would be the screen asserting
 * that a router was contacted when nothing contacted it.
 *
 * So the inventory is declared HERE, on the server, rather than in the UI. A
 * front-end change cannot invent a signal, because the front end is told which
 * signals exist and which do not, and why.
 *
 * MEASURED means a value comes from a row this system wrote.
 * UNMEASURED means no source exists. It is not "unknown right now" — it is
 * "nothing in this system could tell you, and here is the reason".
 */
final class SignalReport
{
    public const MEASURED   = 'measured';
    public const UNMEASURED = 'unmeasured';

    /**
     * The structural inventory: which router signals have a source at all.
     *
     * Every `unmeasured` entry names what would have to exist. That turns the
     * screen into a to-do list DishNet staff can read, instead of a wall of grey.
     *
     * `admin_readable` is a SEPARATE question from `status`. A signal can be
     * measured in Domain B and still not be readable through the Admin API —
     * uplink telemetry is exactly that, because exposing telemetry to Admin is
     * decision D-4 and D-4 is open. Collapsing the two would make the panel
     * claim it can show a number it cannot fetch.
     *
     * @return list<array{key:string,label:string,status:string,source:?string,reason:?string,needs:?string,admin_readable:bool}>
     */
    public static function inventory(): array
    {
        return [
            [
                'key' => 'lifecycle', 'label' => 'Provisioning state',
                'status' => self::MEASURED,
                'source' => 'mt_devices.state',
                'reason' => null,
                'needs'  => null,
                'admin_readable' => true,
            ],
            [
                'key' => 'wan_interface', 'label' => 'WAN interface assignment',
                'status' => self::MEASURED,
                // The headline word matters. "MEASURED" beside "WAN interface"
                // reads as a claim about the link. What is measured is the
                // interface a person RECORDED at staging, so the panel says so.
                'verdict' => 'recorded',
                'source' => 'mt_devices.wan_interface',
                // Worth saying out loud: this is which port, established by a
                // named person at staging. It is not a link-state signal.
                'reason' => 'Staging metadata: the interface a person recorded when the router was prepared, with their name and the time. It is not a link state and says nothing about whether the port is up.',
                'needs'  => null,
                'admin_readable' => true,
            ],
            [
                'key' => 'wan_link', 'label' => 'WAN link up/down',
                'status' => self::UNMEASURED,
                'source' => null,
                'reason' => 'No column, no probe. Only the interface NAME is recorded.',
                'needs'  => 'A RouterOS read-back of interface status (F6-B), or a device-reported heartbeat.',
                'admin_readable' => true,
            ],
            [
                'key' => 'wireguard', 'label' => 'WireGuard tunnel',
                'status' => self::UNMEASURED,
                'source' => null,
                'reason' => 'wg_pubkey and tunnel_ip are CONFIGURATION INTENT — what we mean to set up. Neither says a handshake occurred.',
                'needs'  => 'A handshake timestamp from the WireGuard peer, or a reachability probe of tunnel_ip.',
                'admin_readable' => true,
            ],
            [
                'key' => 'radius', 'label' => 'RADIUS',
                'status' => self::UNMEASURED,
                'source' => null,
                'reason' => 'No health probe exists anywhere in this application, and the AAA publisher has never been built.',
                'needs'  => 'The AAA Publisher (Decision 7) plus a reachability check of the radius database.',
                'admin_readable' => true,
            ],
            [
                'key' => 'hotspot', 'label' => 'HotSpot service',
                'status' => self::UNMEASURED,
                'source' => null,
                'reason' => 'Nothing in this system observes whether a HotSpot server is running on a router.',
                'needs'  => 'A RouterOS read-back (F6-B).',
                'admin_readable' => true,
            ],
            [
                'key' => 'last_seen', 'label' => 'Last contact',
                'status' => self::UNMEASURED,
                'source' => 'mt_devices.last_seen_at',
                // Measured during this build and worth recording on the screen:
                // the column is real, and nothing anywhere writes it.
                'reason' => 'The column exists but NOTHING WRITES IT. Every router reads null, so it is not a liveness signal — it is an empty field.',
                'needs'  => 'A writer. Which mechanism supplies it is the unresolved delivery-model question, so none is named here.',
                'admin_readable' => true,
            ],
            [
                'key' => 'uplink', 'label' => 'Uplink throughput',
                'status' => self::MEASURED,
                'source' => 'mt_uplink_samples',
                'reason' => 'Sampled rx/tx and session counts. Recorded in Domain B, but NOT exposed through the Admin read boundary: telemetry exposure is decision D-4, and D-4 is open.',
                'needs'  => 'An Admin projection for uplink samples, which needs D-4 decided first.',
                'admin_readable' => false,
            ],
            [
                'key' => 'sessions', 'label' => 'Active sessions',
                'status' => self::MEASURED,
                'per_router' => false,
                'source' => 'mt_sessions (RADIUS accounting)',
                'reason' => 'Ingested from RADIUS accounting, so it reflects what the NAS reported. ESTATE-WIDE ONLY: accounting carries a NAS identifier, and mt_session_account never sets device_id, so a session cannot be attributed to a particular router.',
                'needs'  => null,
                'admin_readable' => true,
            ],
        ];
    }

    /** The actions a router detail page could offer, and why each is unavailable. */
    public static function actions(): array
    {
        return [
            ['key' => 'push_config', 'label' => 'Push configuration',
             'available' => false,
             'reason' => 'A delivery case exists (RouterOsDelivery), but the Admin action route is not bound (G-C: queuing it needs an Admin-plane enqueue function, a migration) and F6-B is not authorized — so nothing can queue it, and a queued job would reach a simulator, not a router.'],
            ['key' => 'reboot', 'label' => 'Reboot router',
             'available' => false,
             'reason' => 'No delivery case exists for reboot. There is nothing to queue and nothing to deliver.'],
            ['key' => 'reprovision', 'label' => 'Reprovision',
             'available' => false,
             'reason' => 'No delivery case exists for reprovision.'],
            ['key' => 'diagnostics', 'label' => 'Run diagnostics',
             'available' => false,
             'reason' => 'Diagnostics would read live router state. Nothing in this system reads live router state yet.'],
        ];
    }

    /** Counts for a dashboard tile, so the figure on screen is derived, not typed. */
    public static function summary(): array
    {
        $inv = self::inventory();
        $measured = array_filter($inv, static fn($s) => $s['status'] === self::MEASURED);
        return [
            'total'      => count($inv),
            'measured'   => count($measured),
            'unmeasured' => count($inv) - count($measured),
            'admin_readable'    => count(array_filter($inv, static fn($s) => $s['admin_readable'])),
            'actions_available' => count(array_filter(self::actions(), static fn($a) => $a['available'])),
            'actions_total'     => count(self::actions()),
        ];
    }
}

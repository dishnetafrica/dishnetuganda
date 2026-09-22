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
     * screen into a to-do list an operator can read, instead of a wall of grey.
     *
     * @return list<array{key:string,label:string,status:string,source:?string,reason:?string,needs:?string}>
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
            ],
            [
                'key' => 'wan_interface', 'label' => 'WAN interface',
                'status' => self::MEASURED,
                'source' => 'mt_devices.wan_interface',
                // Worth saying out loud: this is which port, established by a
                // named person at staging. It is not a link-state signal.
                'reason' => 'The interface a person recorded at staging, with their name and the time. Not a link state.',
                'needs'  => null,
            ],
            [
                'key' => 'wan_link', 'label' => 'WAN link up/down',
                'status' => self::UNMEASURED,
                'source' => null,
                'reason' => 'No column, no probe. Only the interface NAME is recorded.',
                'needs'  => 'A RouterOS read-back of interface status (F6-B), or a device-reported heartbeat.',
            ],
            [
                'key' => 'wireguard', 'label' => 'WireGuard tunnel',
                'status' => self::UNMEASURED,
                'source' => null,
                'reason' => 'wg_pubkey and tunnel_ip are CONFIGURATION INTENT — what we mean to set up. Neither says a handshake occurred.',
                'needs'  => 'A handshake timestamp from the WireGuard peer, or a reachability probe of tunnel_ip.',
            ],
            [
                'key' => 'radius', 'label' => 'RADIUS',
                'status' => self::UNMEASURED,
                'source' => null,
                'reason' => 'No health probe exists anywhere in this application, and the AAA publisher has never been built.',
                'needs'  => 'The AAA Publisher (Decision 7) plus a reachability check of the radius database.',
            ],
            [
                'key' => 'hotspot', 'label' => 'HotSpot service',
                'status' => self::UNMEASURED,
                'source' => null,
                'reason' => 'Nothing in this system observes whether a HotSpot server is running on a router.',
                'needs'  => 'A RouterOS read-back (F6-B).',
            ],
            [
                'key' => 'last_seen', 'label' => 'Last contact',
                'status' => self::UNMEASURED,
                'source' => 'mt_devices.last_seen_at',
                // Measured during this build and worth recording on the screen:
                // the column is real, and nothing anywhere writes it.
                'reason' => 'The column exists but NOTHING WRITES IT. Every router reads null, so it is not a liveness signal — it is an empty field.',
                'needs'  => 'A writer. Which mechanism supplies it is the unresolved delivery-model question, so none is named here.',
            ],
            [
                'key' => 'uplink', 'label' => 'Uplink throughput',
                'status' => self::MEASURED,
                'source' => 'mt_uplink_samples',
                'reason' => 'Sampled rx/tx and session counts. Present only for routers a sampler has actually visited.',
                'needs'  => null,
            ],
            [
                'key' => 'sessions', 'label' => 'Active sessions',
                'status' => self::MEASURED,
                'source' => 'mt_sessions (RADIUS accounting)',
                'reason' => 'Ingested from RADIUS accounting, so it reflects what the NAS reported.',
                'needs'  => null,
            ],
        ];
    }

    /** The actions a router detail page could offer, and why each is unavailable. */
    public static function actions(): array
    {
        return [
            ['key' => 'push_config', 'label' => 'Push configuration',
             'available' => false,
             'reason' => 'A delivery case exists (RouterOsDelivery) but no Admin write route is bound, and F6-B is not authorized — so this would reach a simulator, not a router.'],
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
            'actions_available' => count(array_filter(self::actions(), static fn($a) => $a['available'])),
            'actions_total'     => count(self::actions()),
        ];
    }
}

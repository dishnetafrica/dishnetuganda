<?php
declare(strict_types=1);
namespace Dn\Devices;

/**
 * The management network — one rule, used from both sides of the boundary.
 *
 * docs/36 / docs/31 §1.3: the WireGuard tunnel subnet is 10.66.0.0/16 and
 * every device holds exactly one /32 in it, recorded against its serial at
 * staging (docs/31 §3.1 step 7 and 11). Two callers need the same answer and
 * may not import each other:
 *
 *   - Dn\Delivery\RouterOs\RestClient refuses to open a connection to any
 *     host outside it (management is reachable over the tunnel ONLY);
 *   - the Admin plane refuses to REGISTER a tunnel address outside it, so a
 *     row that RestClient would later refuse cannot be written in the first
 *     place.
 *
 * The Admin plane may not reference Dn\Delivery at all (F2, asserted by
 * tests/test_frozen_guards.php), which is why the rule lives here in Devices.
 */
final class TunnelAddress
{
    public const NETWORK = '10.66.0.0/16';

    /**
     * Is this host — bare, with a scheme, or with a port — an address inside
     * the management network?
     */
    public static function isManagement(string $host): bool
    {
        $h = preg_replace('#^https?://#', '', trim($host));
        $h = explode('/', $h)[0];
        $h = explode(':', $h)[0];
        return self::isRegistrable($h);
    }

    /**
     * A registrable tunnel address: exactly one dotted IPv4 address inside
     * the network. No scheme, no port, no prefix length, no name.
     */
    public static function isRegistrable(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) { return false; }
        return (bool) preg_match('/^10\.66\.\d{1,3}\.\d{1,3}$/', $ip);
    }
}

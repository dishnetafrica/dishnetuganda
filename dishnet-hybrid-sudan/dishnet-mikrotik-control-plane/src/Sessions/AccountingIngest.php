<?php
declare(strict_types=1);
namespace Dn\Sessions;

use Dn\Db\Database;

/**
 * Turns one RADIUS accounting record into a session row.
 *
 * Not customer-scoped: accounting arrives from the network with a username
 * and nothing else. The username is namespaced per customer (migration 010),
 * so resolving it IS the authorization — an unknown username produces no
 * session rather than a session belonging to nobody.
 */
final class AccountingIngest
{
    public const GIGAWORD = 4294967296;   // 2^32

    public function __construct(private Database $db) {}

    /**
     * @param array $packet RADIUS attribute names as FreeRADIUS emits them
     * @return array{session_id:?string,status:string}
     */
    public function record(array $packet): array
    {
        $status = (string) ($packet['Acct-Status-Type'] ?? '');
        if (!in_array($status, ['Start', 'Interim-Update', 'Stop'], true)) {
            return ['session_id' => null, 'status' => 'ignored'];
        }

        $username = trim((string) ($packet['User-Name'] ?? ''));
        $session  = trim((string) ($packet['Acct-Session-Id'] ?? ''));
        if ($username === '' || $session === '') {
            return ['session_id' => null, 'status' => 'incomplete'];
        }

        $id = $this->db->one(
            'SELECT mt_session_account(?,?,?,?,?,?,?,?,?) AS id',
            [
                $status, $username, $session,
                (string) ($packet['NAS-Identifier'] ?? ''),
                self::combineOctets($packet, 'Input'),
                self::combineOctets($packet, 'Output'),
                $packet['Calling-Station-Id'] ?? null,
                $packet['Framed-IP-Address'] ?? null,
                $packet['Acct-Terminate-Cause'] ?? null,
            ]
        )['id'] ?? null;

        return ['session_id' => $id, 'status' => $id === null ? 'unknown_user' : 'recorded'];
    }

    /**
     * Combine the 32-bit octet counter with its gigawords companion.
     *
     * Acct-Input-Octets is a 32-bit integer and wraps at 4 GiB. RFC 2869 puts
     * the number of wraps in Acct-Input-Gigawords. A deployment that reads
     * only the octets under-reports every session past 4 GiB — and does it
     * quietly, so the figures look like light usage rather than like a fault.
     * On a day pass over hotel Wi-Fi, 4 GiB is one evening of video.
     */
    public static function combineOctets(array $p, string $dir): int
    {
        $octets    = (int) ($p["Acct-{$dir}-Octets"] ?? 0);
        $gigawords = (int) ($p["Acct-{$dir}-Gigawords"] ?? 0);
        if ($octets < 0)    { $octets = 0; }
        if ($gigawords < 0) { $gigawords = 0; }
        return $gigawords * self::GIGAWORD + $octets;
    }

    /** Close sessions whose NAS stopped reporting. Not customer-scoped. */
    public function reap(string $stale = '15 minutes'): int
    {
        return (int) $this->db->one('SELECT mt_sessions_reap(?::interval) AS n', [$stale])['n'];
    }
}

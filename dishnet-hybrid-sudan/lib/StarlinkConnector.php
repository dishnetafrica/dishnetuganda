<?php
declare(strict_types=1);

/**
 * StarlinkConnector — the only thing in Hybrid that knows how we talk to
 * Starlink.
 *
 * Everything above this line — invoice sync, order sync, kits, service lines —
 * asks for a path and receives decoded JSON. None of it knows whether a cookie
 * or a token is underneath, which is the whole point: Uganda starts on the web
 * session that South Sudan already runs on, and the day Starlink grants API
 * access it is one implementation swapped and nothing else touched.
 *
 * The fleet plugin could not do this. Its session knowledge is spread across
 * cron.php, dr_wifi_change.php and session_manager.php, so replacing its
 * authentication means editing all three.
 */
interface StarlinkConnector
{
    /** Can this connector even try? No credential means no. */
    public function isConfigured(): bool;

    /**
     * Fetch a Starlink path and decode it.
     *
     * @param string $path  absolute path, e.g. /api/webagg/v2/accounts/service-lines
     * @return array{ok:bool, code:int, data:array, error:string, retryable:bool}
     */
    public function get(string $path): array;

    /**
     * Cheap proof that the credential works, without pulling data.
     *
     * @return array{ok:bool, error:string, detail:string}
     */
    public function verify(): array;

    /** How this connector authenticates, for logs and admin screens. */
    public function describe(): string;
}

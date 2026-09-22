<?php
declare(strict_types=1);
namespace Dn\Http\Serializer;

/**
 * What leaves the building on the ADMIN surface.
 *
 * A SEPARATE allowlist from Projection, per docs/55 §J — "same RLS, different
 * serializers". Separate, not a superset with exceptions: staff legitimately
 * see operational fields a customer must never see (device state, tunnel
 * address, provisioning history), and the two lists drift apart over time. One
 * shared list with role conditionals is how a customer eventually receives a
 * field because a condition was written the wrong way round.
 *
 * Still an ALLOWLIST. Three things are withheld from staff too, because no
 * screen needs them and an admin screen is not a reason to move a secret:
 *   - sealed device credentials (mt_device_secrets.secret_sealed)
 *   - the RADIUS shared secret (nas.secret) — not in this database at all
 *   - voucher CODES on list endpoints; a batch print is a deliberate,
 *     audited action, not a side effect of opening a list
 */
final class AdminProjection
{
    private const CUSTOMER = ['id', 'name', 'ucrm_client_id', 'status', 'created_at'];

    private const SITE = ['id', 'customer_id', 'service_id', 'name', 'location', 'created_at'];

    /**
     * The estate view of a router. Deliberately includes operational state the
     * customer projection withholds, and deliberately excludes the sealed
     * credential, which no screen renders.
     */
    private const ROUTER = [
        'id', 'customer_id', 'site_id', 'serial', 'model', 'ros_version',
        'tunnel_ip', 'name', 'state', 'wan_interface', 'wan_interface_set_by',
        'staged_by', 'staged_at', 'claimed_at', 'last_seen_at', 'created_at',
    ];

    private const PLAN = [
        'id', 'customer_id', 'site_id', 'profile_id', 'name', 'duration_s',
        'rate_down_bps', 'rate_up_bps', 'data_cap_bytes', 'devices_per_voucher',
        'mode', 'price_minor', 'currency', 'active', 'created_at',
    ];

    /** No `code`. See the class comment. */
    private const VOUCHER = [
        'id', 'customer_id', 'batch_id', 'plan_id', 'site_id', 'state',
        'price_minor', 'currency', 'duration_s',
        'created_at', 'activated_at', 'expires_at', 'revoked_at', 'sold_at',
    ];

    private const BATCH = [
        'id', 'customer_id', 'site_id', 'plan_id', 'requested_count',
        'issued_count', 'state', 'created_at', 'completed_at',
    ];

    private const SESSION = [
        'id', 'customer_id', 'voucher_id', 'nas_identifier', 'framed_ip',
        'started_at', 'last_seen_at', 'stopped_at', 'bytes_in', 'bytes_out',
    ];

    /** Staff DO see payload and last_error — diagnosing delivery is their job. */
    private const INTENT = [
        'id', 'customer_id', 'kind', 'state', 'payload', 'attempts',
        'last_error', 'target_type', 'target_id', 'created_at', 'updated_at',
    ];

    private const AUDIT = [
        'id', 'customer_id', 'actor', 'actor_kind', 'action',
        'target_type', 'target_id', 'source', 'detail', 'at',
    ];

    private static function pick(array $row, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) { if (array_key_exists($k, $row)) { $out[$k] = $row[$k]; } }
        return $out;
    }

    public static function customer(array $r): array { return self::pick($r, self::CUSTOMER); }
    public static function site(array $r): array     { return self::pick($r, self::SITE); }
    public static function router(array $r): array   { return self::pick($r, self::ROUTER); }
    public static function plan(array $r): array     { return self::pick($r, self::PLAN); }
    public static function voucher(array $r): array  { return self::pick($r, self::VOUCHER); }
    public static function batch(array $r): array    { return self::pick($r, self::BATCH); }
    public static function session(array $r): array  { return self::pick($r, self::SESSION); }
    public static function intent(array $r): array   { return self::pick($r, self::INTENT); }
    public static function audit(array $r): array    { return self::pick($r, self::AUDIT); }

    /** @param list<array> $rows */
    public static function many(string $kind, array $rows): array
    {
        return array_values(array_map(static fn(array $r): array => self::$kind($r), $rows));
    }

    /** Every field this serializer will ever emit — for the guard test. */
    public static function allFields(): array
    {
        return array_values(array_unique(array_merge(
            self::CUSTOMER, self::SITE, self::ROUTER, self::PLAN, self::VOUCHER,
            self::BATCH, self::SESSION, self::INTENT, self::AUDIT)));
    }
}

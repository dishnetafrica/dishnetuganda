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
    /** NOT `radius_ref`: it is the AAA username namespace for this customer,
     *  and no admin screen needs it. */
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

    /**
     * Corrected against the live schema: the columns are `ip` and `ended_at`,
     * not `framed_ip`/`stopped_at`, which never existed.
     *
     * Deliberately withheld even though staff could arguably see them:
     *   radius_username — the AAA identity. Staff diagnose by voucher, not by
     *                     credential; exposing it widens the AAA surface for
     *                     no screen that asked.
     *   mac            — a guest's device address. A guest is not a DishNet
     *                     customer and did not consent to staff browsing.
     * Either can be added later with a stated requirement, which is the point.
     */
    private const SESSION = [
        'id', 'customer_id', 'voucher_id', 'device_id', 'nas_identifier', 'ip',
        'bytes_in', 'bytes_out', 'state', 'terminate_cause',
        'started_at', 'last_seen_at', 'ended_at',
    ];

    /**
     * D-2 (docs/84): `payload` and `last_error` are NOT exposed.
     *
     * They are free-form. Their column names say nothing about what a future
     * code path may put inside them, so a name-based guard can never catch it
     * and a test asserting "today's values contain no secret" proves only that
     * today's values contain no secret. The projection prevents arbitrary
     * FUTURE contents from crossing the boundary, which a test cannot.
     *
     * Staff still diagnose delivery: state, attempts and the target are here.
     * If a screen genuinely needs detail, it gets its OWN projection with an
     * explicit allowlist of sanitized fields — not this one widened.
     */
    private const INTENT = [
        'id', 'customer_id', 'kind', 'state', 'attempts', 'max_attempts',
        'target_type', 'target_id',
        'created_at', 'sent_at', 'confirmed_at', 'failed_at',
    ];

    /** D-2: raw `detail` (jsonb, free-form) is NOT exposed. Same reasoning. */
    private const AUDIT = [
        'id', 'customer_id', 'actor', 'actor_kind', 'action',
        'target_type', 'target_id', 'source', 'at',
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

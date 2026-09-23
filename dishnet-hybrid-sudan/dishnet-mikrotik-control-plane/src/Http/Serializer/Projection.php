<?php
declare(strict_types=1);
namespace Dn\Http\Serializer;

/**
 * What leaves the building.
 *
 * ALLOWLIST, never a denylist. A field is withheld unless it is named here,
 * so a column added later is invisible until someone decides to expose it.
 * The reverse — listing what to hide — fails open the moment a migration adds
 * a column and nobody remembers to update the list.
 *
 * docs/45 §4: the customer view of an entity is a SUBSET. Operational fields
 * exist on the row and must not reach the customer.
 */
final class Projection
{
    /** ucrm_client_id is internal billing linkage; status is not the customer's business */
    private const CUSTOMER = ['id', 'name'];

    /** credential_hash and phone/email of OTHER principals never leave.
     *  status and capabilities (migration 027) are the caller's own role. */
    private const PRINCIPAL = ['id', 'kind', 'display_name', 'status', 'capabilities'];

    /**
     * A principal as the owner sees it on /me/staff: the same fields plus a
     * MASKED phone — never another principal's full number (docs/116 §E.3).
     * Masked here, once, so no route can forget to.
     */
    private const PRINCIPAL_LISTING = ['id', 'kind', 'display_name', 'status', 'capabilities', 'phone_masked'];

    private const SERVICE = ['id', 'kind', 'status', 'started_at'];

    private const SITE = ['id', 'name', 'location'];

    /** the customer sees what they bought, as a flat key/value */
    private const ENTITLEMENT = ['key', 'int_value', 'text_value'];

    /**
     * Router/access point — the projection docs/45 §4 specifies.
     * Present now, unused until step 7, so the rule is in force before the
     * fields it withholds exist.
     */
    private const ACCESS_POINT = ['id', 'name', 'site_id', 'online', 'session_count', 'last_seen'];

    /**
     * Queued work, as the customer sees it.
     *
     * Withheld: payload (carries router-shaped detail), last_error (raw
     * transport text), attempts, claimed_by, lease. A customer needs to know
     * a request exists and where it has got to — not how the platform is
     * going about it.
     */
    private const INTENT = ['id', 'kind', 'state', 'created_at'];

    /**
     * A retail plan, as its owner sees it.
     *
     * profile_id is absent deliberately: the enforcement profile is DishNet's
     * layer and the customer never learns the concept exists. Two customers
     * selling the same shape of access share a profile row, so exposing the
     * id would also let one infer something about the other.
     */
    private const PLAN = [
        'id', 'name', 'duration_s', 'rate_down_bps', 'rate_up_bps',
        'data_cap_bytes', 'devices_per_voucher', 'mode',
        'price_minor', 'currency', 'active', 'site_id', 'created_at',
    ];

    /**
     * A voucher as its owner sees it. The code IS shown — it is the thing
     * they sell. batch_id and plan_id are internal joins the customer has no
     * use for; price and duration are the snapshot taken at issue.
     */
    private const VOUCHER = [
        'id', 'code', 'state', 'price_minor', 'currency', 'duration_s',
        'site_id', 'created_at', 'activated_at', 'expires_at',
    ];

    private const BATCH = [
        'id', 'requested_count', 'issued_count', 'state', 'site_id', 'created_at',
    ];

    /**
     * A connected device, as the customer sees it.
     *
     * radius_username, acct_session_id and nas_identifier are withheld: they
     * are AAA plumbing, and the username carries the customer's own namespace
     * prefix. The customer sees a device using their Wi-Fi, against the
     * voucher that let it on.
     */
    private const SESSION = [
        'id', 'voucher_id', 'mac', 'ip', 'bytes_in', 'bytes_out',
        'state', 'started_at', 'last_seen_at', 'ended_at',
    ];

    public static function session(array $r): array     { return self::pick($r, self::SESSION); }
    public static function voucher(array $r): array     { return self::pick($r, self::VOUCHER); }
    public static function batch(array $r): array       { return self::pick($r, self::BATCH); }
    public static function plan(array $r): array        { return self::pick($r, self::PLAN); }
    public static function intent(array $r): array      { return self::pick($r, self::INTENT); }
    public static function customer(array $r): array    { return self::pick($r, self::CUSTOMER); }
    public static function principal(array $r): array   { return self::pick(self::withCapabilities($r), self::PRINCIPAL); }

    public static function principalListing(array $r): array
    {
        $r = self::withCapabilities($r);
        $r['phone_masked'] = self::maskPhone($r['phone'] ?? null);
        return self::pick($r, self::PRINCIPAL_LISTING);
    }

    /** `{op.a,op.b}` from PostgreSQL becomes a JSON list; absent stays absent. */
    private static function withCapabilities(array $r): array
    {
        if (array_key_exists('capabilities', $r) && !is_array($r['capabilities'])) {
            $r['capabilities'] = \Dn\Auth\OpCapability::fromPg($r['capabilities']);
        }
        return $r;
    }

    /** All but the last three digits. NULL stays NULL: a principal with no phone cannot sign in. */
    private static function maskPhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') { return null; }
        $keep = 3;
        return strlen($phone) <= $keep ? str_repeat('•', strlen($phone))
             : str_repeat('•', strlen($phone) - $keep) . substr($phone, -$keep);
    }
    public static function service(array $r): array     { return self::pick($r, self::SERVICE); }
    public static function site(array $r): array        { return self::pick($r, self::SITE); }
    public static function entitlement(array $r): array { return self::pick($r, self::ENTITLEMENT); }
    public static function accessPoint(array $r): array { return self::pick($r, self::ACCESS_POINT); }

    /** @param list<array> $rows */
    public static function many(callable $fn, array $rows): array
    {
        return array_values(array_map($fn, $rows));
    }

    /** The allowlist for a named projection, for tests to assert against. */
    public static function fieldsFor(string $name): array
    {
        return match ($name) {
            'customer'    => self::CUSTOMER,
            'principal'   => self::PRINCIPAL,
            'principalListing' => self::PRINCIPAL_LISTING,
            'service'     => self::SERVICE,
            'site'        => self::SITE,
            'entitlement' => self::ENTITLEMENT,
            'accessPoint' => self::ACCESS_POINT,
            'intent'      => self::INTENT,
            'plan'        => self::PLAN,
            'voucher'     => self::VOUCHER,
            'session'     => self::SESSION,
            'batch'       => self::BATCH,
            default       => throw new \InvalidArgumentException("unknown projection {$name}"),
        };
    }

    private static function pick(array $row, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $row)) { $out[$f] = $row[$f]; }
        }
        return $out;
    }
}

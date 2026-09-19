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

    /** credential_hash and phone/email of OTHER principals never leave */
    private const PRINCIPAL = ['id', 'kind', 'display_name'];

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

    public static function customer(array $r): array    { return self::pick($r, self::CUSTOMER); }
    public static function principal(array $r): array   { return self::pick($r, self::PRINCIPAL); }
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
            'service'     => self::SERVICE,
            'site'        => self::SITE,
            'entitlement' => self::ENTITLEMENT,
            'accessPoint' => self::ACCESS_POINT,
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

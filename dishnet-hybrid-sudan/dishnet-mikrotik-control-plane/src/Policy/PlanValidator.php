<?php
declare(strict_types=1);
namespace Dn\Policy;

/**
 * VALIDITY, NOT CEILING.
 *
 * This class answers one question: can these values be EXPRESSED — by the
 * RADIUS attributes that carry them and the hardware that enforces them?
 *
 * It does not answer, and must never answer, what the customer is allowed to
 * sell. "That rate cannot be written into the attribute" is a fact about the
 * protocol. "You did not buy that much" is a commercial ceiling, and under
 * F8/F9 it is wrong: the uplink is the customer's and DishNet does not ration
 * it. Nothing here reads an entitlement, and a guard asserts that no source
 * file does.
 *
 * A consequence worth stating plainly: a customer may create a 20 Mbps plan
 * whether or not their link can carry it, and whether or not DishNet sold
 * them anything. Overselling their own uplink is their business decision to
 * make (docs/50 §5). The platform shows them what is happening; it does not
 * refuse them.
 */
final class PlanValidator
{
    /**
     * PROTOCOL facts. These two are constants because they are properties of
     * the wire format, true of every device that speaks it.
     *
     * RFC 2865: Session-Timeout is a 32-bit unsigned integer. Mikrotik-Total-
     * Limit plus its Gigawords companion is effectively 64-bit. Neither needs
     * a MikroTik to confirm it; reading the RFC is the verification.
     *
     * The two numbers that used to sit beside them — the rate ceiling and the
     * shared-users ceiling — were NOT protocol facts. They are properties of a
     * firmware on a model, nobody had measured them, and a `const` claimed
     * otherwise. Audit findings R2 and R3; they now live in RouterOsLimits,
     * where a number carries its provenance.
     */
    public const MAX_SESSION_S = 4294967295;

    /** Mikrotik-Total-Limit plus its Gigawords companion: effectively 64-bit. */
    public const MAX_DATA_BYTES = 9223372036854775807;

    private RouterOsLimits $limits;

    /**
     * @param RouterOsLimits|null $limits what the target hardware accepts. Left
     *        null, the provisional bound applies and says so in any message it
     *        produces — there is no measured limit for any model yet, so that
     *        is currently every call.
     */
    public function __construct(?RouterOsLimits $limits = null)
    {
        $this->limits = $limits ?? RouterOsLimits::unverified();
    }

    /** @return list<string> reasons; empty means valid */
    public function check(array $p): array
    {
        $e = [];

        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '')            { $e[] = 'name is required'; }
        if (mb_strlen($name) > 80)   { $e[] = 'name must be 80 characters or fewer'; }

        $dur = $p['duration_s'] ?? null;
        if (!$this->isInt($dur) || (int) $dur < 1) {
            $e[] = 'duration must be a whole number of seconds, at least 1';
        } elseif ((int) $dur > self::MAX_SESSION_S) {
            $e[] = 'duration exceeds what the session timeout attribute can carry ('
                 . self::MAX_SESSION_S . ' seconds)';
        }

        foreach (['rate_down_bps' => 'download rate', 'rate_up_bps' => 'upload rate'] as $k => $label) {
            $v = $p[$k] ?? null;
            if (!$this->isInt($v) || (int) $v < 1) {
                $e[] = "{$label} must be a whole number of bits per second, at least 1";
            } elseif ((int) $v > $this->limits->maxRateBps) {
                // The message names the provenance on purpose. "Exceeds what
                // the attribute can express" reads as settled fact; when the
                // bound has never been measured, whoever hits it deserves to
                // know they may be arguing with a guard rail rather than with
                // a router.
                $e[] = "{$label} exceeds what the rate limit attribute can express ("
                     . $this->limits->maxRateBps . ' bps — ' . $this->limits->provenance . ')';
            }
        }

        $dev = $p['devices_per_voucher'] ?? null;
        if (!$this->isInt($dev) || (int) $dev < 1) {
            $e[] = 'devices per voucher must be a whole number, at least 1';
        } elseif ((int) $dev > $this->limits->maxSharedUsers) {
            $e[] = 'devices per voucher exceeds what the router can track ('
                 . $this->limits->maxSharedUsers . ' — ' . $this->limits->provenance . ')';
        }

        if (array_key_exists('data_cap_bytes', $p) && $p['data_cap_bytes'] !== null) {
            $d = $p['data_cap_bytes'];
            if (!$this->isInt($d) || (int) $d < 1) {
                $e[] = 'data allowance must be a whole number of bytes, at least 1';
            } elseif ((int) $d > self::MAX_DATA_BYTES) {
                $e[] = 'data allowance exceeds what the accounting attributes can carry';
            }
        }

        $mode = $p['mode'] ?? null;
        if (!in_array($mode, ['elapsed', 'paused'], true)) {
            $e[] = "mode must be 'elapsed' or 'paused'";
        }

        // Price: the customer's alone. Zero is valid — handing a guest free
        // access is a normal thing for a hotel to want. There is no maximum
        // beyond what the column can hold.
        $price = $p['price_minor'] ?? null;
        if (!$this->isInt($price) || (int) $price < 0) {
            $e[] = 'price must be a whole number of minor units, zero or more';
        }

        $cur = (string) ($p['currency'] ?? '');
        if (!preg_match('/^[A-Z]{3}$/', $cur)) {
            $e[] = 'currency must be a three-letter code';
        }

        return $e;
    }

    private function isInt(mixed $v): bool
    {
        return is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v) === 1);
    }
}

<?php
declare(strict_types=1);

/**
 * ServicePlan — what the customer bought, read off the uCRM service.
 *
 * Ported from the South Sudan data-report plugin, which has run this
 * convention for years: the plan a customer is SOLD lives in uCRM, and it
 * overrides whatever Starlink's own backend calls the service line. A
 * customer on "IOM – 6TB Monthly Plan" is on 6TB even if Starlink's console
 * says "Residential".
 *
 * ── THE BUG THIS DELIBERATELY DOES NOT INHERIT ──────────────────────────
 *
 * South Sudan resolves the plan two different ways on two pages. The admin
 * Clients tab calls getDishnetKitPlanMap(), which reads the uCRM service —
 * and shows "6TB plan". The customer's own shareable link calls
 * drServeReport(), which reads plan_name off sl_kits.json — Starlink's name,
 * "Residential" — finds no digits in it, and tells the customer their plan is
 * UNLIMITED. Same kit, same minute, two answers, and the one the customer
 * sees is the one that is wrong in the expensive direction.
 *
 * Here there is one function, it reads the uCRM service, and every screen
 * calls it.
 *
 * ── RAW FOR ARITHMETIC, MASKED FOR EYES ─────────────────────────────────
 *
 * The allowance is parsed from the RAW plan name, and only then is the name
 * masked for display. Doing it the other way round loses the number: mask
 * "IOM – 6TB Monthly Plan" first and the 6TB goes with it. South Sudan gets
 * this right (its v2.8.1 fix) and it is worth keeping right.
 */
final class ServicePlan
{
    /** Attribute keys that hold a kit serial, normalised: no spaces, dashes or underscores. */
    public const KIT_ATTRIBUTE_KEYS = ['starlinkdetails', 'kitnumber', 'starlinkkit', 'kitno', 'kit'];

    /** What a customer sees instead of a Starlink internal name. */
    public const GENERIC       = 'Starlink Service';
    public const GENERIC_PLAIN = 'Starlink Service Plan';

    /**
     * Everything worth knowing about a uCRM service's plan.
     *
     * @return array{raw:string, display:string, cap_gb:float, unlimited:bool,
     *               known:bool, source:string}
     *         known is false when we cannot say what the allowance is — which
     *         must be shown as unknown, never as unlimited.
     */
    public static function fromService(array $svc): array
    {
        [$raw, $source] = self::planName($svc);
        $cap     = self::capGb($raw);
        $display = self::mask($raw);

        // UNLIMITED HAS TO BE CLAIMED, NOT INFERRED.
        //
        // South Sudan treats "a plan name with no number in it" as unlimited.
        // That is how a customer whose Starlink line is called "Residential"
        // gets shown ∞ while the admin page shows their real 6TB. And Uganda
        // would repeat it immediately: client #7's service is literally named
        // "Service Plan: Starlink Residential".
        //
        // So unlimited requires the plan to SAY so, or to be a plan we
        // recognise as ours (mask() passes it through unchanged) that names no
        // size. Anything else — a bare Starlink tier, a wholesale code, an
        // empty field — is UNKNOWN. Unknown shows as unknown; it never shows
        // as unlimited, because the cost of that mistake lands on a bill.
        $unlimited = false;
        if ($cap < 0) {
            $unlimited = (bool)preg_match('/\bunlimited\b/i', $raw)
                      || $display === $raw;   // recognised as one of ours
        }

        return [
            'raw'       => $raw,
            'display'   => $display,
            'cap_gb'    => $cap > 0 ? $cap : 0.0,
            'unlimited' => $unlimited,
            'known'     => $cap > 0 || $unlimited,
            'source'    => $source,
        ];
    }

    /**
     * The plan name, in the order South Sudan learned to trust.
     *
     * The invoice label is first because that is the field an operator keeps
     * accurate — it is what the customer reads on the bill.
     *
     * @return array{0:string,1:string} name, and which field it came from
     */
    public static function planName(array $svc): array
    {
        $label = preg_replace('/\s+/', ' ', trim((string)($svc['invoiceLabel'] ?? ''))) ?? '';

        // 1. "… Services Plan : Starlink Priority 6TB"
        if ($label !== '' && preg_match('/Services?\s*Plan\s*[:\-]\s*(.+)$/i', $label, $m)) {
            return [trim(preg_replace('/\s+/', ' ', $m[1]) ?? ''), 'invoiceLabel'];
        }
        // 2. "blueCARD_Kodok_01 KIT302671048 Starlink Priority 3TB"
        if ($label !== '' && preg_match('/\bStarlink\s+\S.{2,}$/i', $label, $m)) {
            return [trim(preg_replace('/\s+/', ' ', $m[0]) ?? ''), 'invoiceLabel'];
        }
        // 3. The service plan object, then its flat name.
        $nested = trim((string)($svc['servicePlan']['name'] ?? ''));
        if ($nested !== '') return [$nested, 'servicePlan'];
        $flat = trim((string)($svc['servicePlanName'] ?? ''));
        if ($flat !== '') return [$flat, 'servicePlanName'];
        // 4. The service's own name, last — it is the field most likely to
        //    have been typed freehand.
        $name = trim((string)($svc['name'] ?? ''));
        if ($name !== '') return [$name, 'name'];

        return ['', 'none'];
    }

    /**
     * The allowance in GB.
     *
     * Returns -1 for "a plan with no number in it" — unlimited — and 0 for
     * "no plan at all". Those are different answers and a caller that
     * conflates them tells somebody on a capped plan they have no cap.
     */
    public static function capGb(string $raw): float
    {
        $raw = trim($raw);
        if ($raw === '') return 0.0;
        // A SPEED IS NOT AN ALLOWANCE.
        //
        // Uganda's service line is sold as "Residential - 100 Mbps", and the
        // tier above it is "1 Gbps" — which contains the letters GB. Without
        // the lookahead, a gigabit-per-second line reads as a ONE GIGABYTE
        // monthly cap, and mask() then decides the name "states a size", so
        // it shows the customer Starlink's internal tier name as well. Wrong
        // twice, and wrong in the expensive direction.
        //
        // The lookahead requires the unit to END there: "6TB", "500GB)",
        // "2TB/month" all count; "Gbps", "Gbit", "TBs" do not. A plural
        // "500GBs" therefore reads as UNKNOWN rather than as 500GB, which is
        // this class's stated bias — a false negative over a false positive.
        //
        // The LARGEST figure named, because "6TB Monthly Plan (500GB Priority)"
        // is a 6TB plan with a priority tranche inside it, not a 500GB plan.
        $best = 0.0;
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(TB|GB)(?![A-Za-z])/i', $raw, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) {
                $gb = strtoupper($m[2]) === 'TB' ? (float)$m[1] * 1024.0 : (float)$m[1];
                if ($gb > $best) $best = $gb;
            }
        }
        return $best > 0 ? $best : -1.0;
    }

    /**
     * Hide Starlink's internal plan names from customers.
     *
     * Straight from South Sudan, including its bias: a false negative (hiding
     * a legitimate plan name) is better than a false positive (showing a
     * customer a wholesale product code).
     */
    public static function mask(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '—' || $raw === '-') return self::GENERIC_PLAIN;

        // Anything that names DishNet or names a size is ours to show.
        foreach (['/dishnet/i', '/\d+\s*(TB|GB)(?![A-Za-z])/i'] as $ok) {
            if (preg_match($ok, $raw)) return $raw;
        }
        foreach ([
            '/^residential$/i',
            '/^roam/i',
            '/^mobile\s+(regional|priority|usage)/i',
            '/^priority(\s+\d+\s*TB)?$/i',
            '/^global\s+priority/i',
            '/^unlimited$/i',
            '/^ss-(consumer|mobile|business)/i',
            '/^sl-(consumer|mobile|business)/i',
            '/^starlink-(consumer|roam|mobile|business)/i',
            '/^local\s+priority$/i',
        ] as $bad) {
            if (preg_match($bad, $raw)) return self::GENERIC;
        }
        return self::GENERIC_PLAIN;
    }

    /**
     * The kit serial written on a uCRM service, if one is there.
     *
     * FOR DISPLAY AND DRIFT-CHECKING ONLY. This is the South Sudan binding,
     * and it is not the binding here: equipment_assignments decides who owns
     * a kit. This reads the attribute so the two can be COMPARED — an
     * operator editing it by hand should produce a reported disagreement, not
     * a silent change of ownership.
     *
     * @return string[] uppercase serials, in the order written
     */
    public static function kitsOnService(array $svc): array
    {
        $out = [];
        foreach ((array)($svc['attributes'] ?? []) as $attr) {
            $key = strtolower(preg_replace('/[\s_\-]+/', '',
                (string)($attr['key'] ?? $attr['customAttribute']['key'] ?? '')) ?? '');
            if (!in_array($key, self::KIT_ATTRIBUTE_KEYS, true)) continue;
            foreach (explode(',', (string)($attr['value'] ?? '')) as $one) {
                $one = strtoupper(trim($one));
                if ($one !== '' && preg_match('/^KIT[0-9A-Z]{4,}$/', $one)) $out[] = $one;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The uCRM custom-attribute id a kit serial should be written to, out of
     * the account's list of service attributes.
     */
    public static function kitAttributeId(array $customAttributes): int
    {
        foreach ($customAttributes as $ca) {
            if (strtolower((string)($ca['attributeType'] ?? 'service')) !== 'service') continue;
            $key = strtolower(preg_replace('/[\s_\-]+/', '', (string)($ca['key'] ?? $ca['name'] ?? '')) ?? '');
            if (in_array($key, self::KIT_ATTRIBUTE_KEYS, true)) return (int)($ca['id'] ?? 0);
        }
        return 0;
    }
}

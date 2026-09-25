<?php
declare(strict_types=1);

/**
 * PortalLocale — the country hint a customer sees on the login screen.
 *
 * The login page told every customer "Include country code. South Sudan:
 * +211", hardcoded. On the Uganda install that is an instruction to type the
 * wrong country's number, on the one screen where a customer has no context
 * to know better — and a number typed in the wrong format simply never
 * receives its code.
 *
 * Resolution order, and the order matters:
 *
 *   1. explicit config — portal_dial_code / portal_country_name
 *   2. derived from the ledger currency, which every install already sets
 *   3. South Sudan, so an install that configures neither behaves exactly as
 *      it did before this existed
 *
 * Step 2 is why Uganda needs no configuration at all: currency_code is
 * already UGX there and SSP in Juba. Step 3 is why Juba does not change.
 */
require_once __DIR__ . '/TenantProfile.php';

final class PortalLocale
{
    /** Currency → the country that spends it. Only the ones DishNet operates in. */
    const BY_CURRENCY = [
        'UGX' => ['+256', 'Uganda',      '+256 7XX XXX XXX'],
        'SSP' => ['+211', 'South Sudan', '+211 9XX XXX XXX'],
        'KES' => ['+254', 'Kenya',       '+254 7XX XXX XXX'],
        'USD' => ['+211', 'South Sudan', '+211 9XX XXX XXX'],
    ];

    const FALLBACK = ['+211', 'South Sudan', '+211 9XX XXX XXX'];

    /**
     * @return array{code:string, country:string, example:string}
     */
    public static function dialHint(array $config): array
    {
        $code    = trim((string)($config['portal_dial_code']    ?? ''));
        $country = trim((string)($config['portal_country_name'] ?? ''));

        if ($code !== '' || $country !== '') {
            // A half-configured install is still better served by the half it
            // set than by a default that contradicts it.
            [$dCode, $dCountry, $dExample] = self::byCurrency($config);
            $code    = $code    !== '' ? self::normalise($code) : $dCode;
            $country = $country !== '' ? $country               : $dCountry;
            return ['code' => $code, 'country' => $country,
                    'example' => self::example($code, $dCode, $dExample)];
        }

        // Phase 2: an explicit tenant_profile selector answers before the
        // currency does. Without one, the currency rule below is unchanged.
        $sel = strtolower(trim((string)($config[TenantProfile::SELECTOR_KEY] ?? '')));
        if (in_array($sel, TenantProfile::IDS, true)) {
            $t = TenantProfile::load($sel);
            $dial = $t->dialCode(); $ex = $t->phoneExample();
            if ($dial !== '' && $t->countryName() !== '') {
                return ['code' => '+' . $dial, 'country' => $t->countryName(),
                        'example' => $ex !== '' ? $ex : '+' . $dial . ' XXX XXX XXX'];
            }
        }

        [$c, $n, $ex] = self::byCurrency($config);
        return ['code' => $c, 'country' => $n, 'example' => $ex];
    }

    /** @return array{0:string,1:string,2:string} */
    private static function byCurrency(array $config): array
    {
        $cur = strtoupper(trim((string)($config['currency_code']
                                     ?? $config['cashbook_base_currency'] ?? '')));
        return self::BY_CURRENCY[$cur] ?? self::FALLBACK;
    }

    /** A dial code is "+" and digits. Anything else a person typed is cleaned. */
    private static function normalise(string $code): string
    {
        $digits = preg_replace('/\D/', '', $code) ?? '';
        return $digits === '' ? self::FALLBACK[0] : '+' . $digits;
    }

    /** Keep the known example when the code matches it; otherwise show the code alone. */
    private static function example(string $code, string $derivedCode, string $derivedExample): string
    {
        if ($code === $derivedCode) return $derivedExample;
        foreach (self::BY_CURRENCY as $row) {
            if ($row[0] === $code) return $row[2];
        }
        return $code . ' XXX XXX XXX';
    }
}

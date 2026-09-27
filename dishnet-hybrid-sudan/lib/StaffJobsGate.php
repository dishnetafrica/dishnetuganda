<?php
/**
 * StaffJobsGate — the one switch for the staff and job changes of 5.18.50 (docs/44, release A).
 *
 * True only where this install's tenant profile resolves to Uganda. Anything unclear — a missing profile file, an
 * unreadable vault, any throwable — is false, which is South Sudan's behaviour: every place this gate guards keeps the
 * 5.18.49 code, verbatim, in its false branch.
 *
 * Always pass the data directory. public.php builds $config from the store copy alone, and currency_code, which is
 * what selects Uganda on a box with no tenant_profile setting, is a vault key: called without the data directory the
 * gate could read Uganda as South Sudan. tests/test_staff_jobs_gate.php fails if any call omits it.
 */
final class StaffJobsGate
{
    /** @var array<string,bool> */
    private static $memo = [];

    public static function applies(array $config, ?string $dataDir): bool
    {
        try {
            if (!class_exists('TenantProfile')) require_once __DIR__ . '/TenantProfile.php';
            $dir = ($dataDir !== null && $dataDir !== '') ? $dataDir : null;
            $key = (string)$dir . '|' . json_encode([
                $config[TenantProfile::SELECTOR_KEY] ?? null,
                $config['currency_code'] ?? null,
                $config['cashbook_base_currency'] ?? null,
            ]);
            if (!array_key_exists($key, self::$memo)) {
                self::$memo[$key] = TenantProfile::current($config, $dir)->id() === 'uganda';
            }
            return self::$memo[$key];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** For tests that switch profiles inside one process. */
    public static function reset(): void
    {
        self::$memo = [];
    }
}

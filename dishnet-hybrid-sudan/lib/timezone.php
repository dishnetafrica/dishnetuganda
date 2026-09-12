<?php
declare(strict_types=1);

/**
 * dn_tz() — the one place that knows what time it is here.
 *
 * The zone was written into 44 places as the literal 'Africa/Juba', and the
 * comment above the staff manual explained why that was harmless: "Uganda
 * shares UTC+3 with South Sudan, so every clock in the system is correct for
 * Kampala — only the identifier is inherited."
 *
 * That was wrong, and had been wrong since 31 January 2021, when South Sudan
 * left East Africa Time. Africa/Juba is CAT, UTC+2. Africa/Kampala is EAT,
 * UTC+3. So this was never a cosmetic rename — every one of those 44 sites was
 * running an hour behind Kampala: cron schedules, the cashbook day boundary,
 * how many days overdue an invoice is when a customer gets chased for it.
 *
 * Storage was never affected. Messages are written with gmdate() and SQLite's
 * datetime('now'), both UTC, and test_message_timestamps.php pins that under a
 * deliberately wrong +2 process clock. What moved was everything computed or
 * displayed in local time.
 *
 * ── THE DEFAULT IS JUBA, ON PURPOSE ─────────────────────────────────────
 *
 * This plugin runs in two countries from one codebase, and the standing rule
 * is that an install which configures nothing behaves exactly as it did. So
 * the default here is the literal that was there before. The South Sudan box
 * sets no key and does not move by a second — and Juba is the correct zone
 * for it, so nothing is owed there. Uganda sets `timezone` and gets its own.
 *
 * Read from disk rather than from a passed-in array, because these calls sit
 * at the very top of cron scripts — before any config has been loaded, which
 * is precisely why the value was hardcoded in the first place.
 */

if (!function_exists('dn_tz')) {

    /**
     * The timezone identifier this install runs in.
     *
     * @param array|null $config an already-loaded config, if the caller has one
     */
    function dn_tz(?array $config = null): string
    {

        if (is_array($config)) {
            $t = trim((string)($config['timezone'] ?? ''));
            if ($t !== '' && dn_tz_valid($t)) return $t;
        }
        $c =& dn_tz_cache();
        if (isset($c['tz'])) return $c['tz'];

        $t = trim((string)(dn_tz_fromDisk()['timezone'] ?? ''));
        // An unreadable or misspelt zone must not throw at the top of a cron
        // and take the whole cycle with it. Falling back to the historical
        // literal keeps the machine running on the clock it has always used.
        return $c['tz'] = ($t !== '' && dn_tz_valid($t)) ? $t : 'Africa/Juba';
    }

    /** The one cache both readers share, so dn_tz_reset() can truly clear it. */
    function &dn_tz_cache(): array
    {
        static $c = [];
        return $c;
    }

    /** Is this a zone PHP actually knows? A typo must not become a silent shift. */
    function dn_tz_valid(string $t): bool
    {
        if ($t === '') return false;
        try { new \DateTimeZone($t); return true; }
        catch (\Throwable $e) { return false; }
    }

    /** A DateTimeZone for this install. */
    function dn_tz_obj(?array $config = null): \DateTimeZone
    {
        return new \DateTimeZone(dn_tz($config));
    }

    /** Apply it to the process. What every cron script calls at its top. */
    function dn_tz_apply(?array $config = null): void
    {
        date_default_timezone_set(dn_tz($config));
    }

    /** The config file, read once. Mirrors CustomerContact's own disk read. */
    function dn_tz_fromDisk(): array
    {
        $c =& dn_tz_cache();
        if (isset($c['cfg'])) return $c['cfg'];
        $cfg = [];
        $dir = getenv('DN_DATA_DIR') ?: '';
        if ($dir === '') {
            $root = getenv('DN_PLUGIN_ROOT') ?: dirname(__DIR__);
            $dir  = dirname($root) . '/.' . basename($root) . '-data';
        }
        $f = rtrim($dir, '/') . '/kyc_config.json';
        if (is_file($f)) {
            $raw = @json_decode((string)@file_get_contents($f), true);
            if (is_array($raw)) $cfg = $raw;
        }
        return $c['cfg'] = $cfg;
    }

    /**
     * A human label: the identifier and what it currently means.
     * "Africa/Kampala — EAT (UTC+3)". Computed, so it cannot claim an
     * offset the zone does not actually have.
     */
    function dn_tz_label(?array $config = null): string
    {
        $z = dn_tz($config);
        try {
            $t   = new \DateTimeZone($z);
            $now = new \DateTime('now', $t);
            $off = (int)$now->getOffset();
            $abbr = $now->format('T');
            $sign = $off < 0 ? '-' : '+';
            $h = intdiv(abs($off), 3600); $m = intdiv(abs($off) % 3600, 60);
            $utc = sprintf('UTC%s%d%s', $sign, $h, $m ? sprintf(':%02d', $m) : '');
            // format('T') gives "+03" on some builds; only show a real name.
            return preg_match('/^[A-Za-z]{2,5}$/', $abbr)
                 ? "{$z} — {$abbr} ({$utc})"
                 : "{$z} ({$utc})";
        } catch (\Throwable $e) { return $z; }
    }

    /** For tests, and for a long-running worker whose config changed. */
    function dn_tz_reset(): void
    {
        $c =& dn_tz_cache();
        $c = [];
    }
}

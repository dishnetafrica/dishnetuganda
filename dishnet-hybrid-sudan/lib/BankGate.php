<?php
declare(strict_types=1);
/**
 * BankGate — the one gate and the one permission function of the Bank screen v1 (5.18.97; SPEC-bank §d.1).
 *
 *   open($dataDir)        true only where the tenant profile resolves to Uganda, SSP is not bookable, AND the switch
 *                         bank_screen_enabled is on — all three read from ONE configuration, PluginConfig::read() of the
 *                         given data directory. Anything unclear (no data directory, any throwable) is false.
 *   ugandaWithoutSsp()    the first two halves alone: the same expression as P3b's rule A in CashbookService
 *                         (StaffJobsGate::applies($cfg, $this->dataDir) && !dn_ssp_selectable($cfg)), over the same
 *                         files. tests/test_bank_store.php holds the two equal on every book of SPEC.md §c.1.
 *   allows($key, …)       the FRESH staff record when it may do $key, else null. It re-reads retailers.json by id, never
 *                         the session copy (up to 300 s old, and returned even when the database read fails). A throwable
 *                         or a null read is a refusal.
 *   fromOwnPage($method)  true only when the browser shows the request came from this site's own page: positive
 *                         evidence, never the mere absence of a cross-site sign (BK-SEC-1, BK-SEC-2).
 *   showLink($dataDir, …) whether a link to ECO Bank is shown (5.18.97, the operator, 18 Oct): true exactly when the tab
 *                         would open for this visitor — the dispatcher's '*admin' on the session copy, then open(), then
 *                         allows('bank.view'). Every link calls it with the same text and decides nothing of its own.
 *
 * Why PluginConfig::read() and not public.php's $config: that one is the store copy, which lib/PluginConfig.php documents
 * can silently diverge from the files. read() is "the same configuration load() returns, without load()'s side effects":
 * a page request writes nothing (no vault refresh, no webhook-secret write), and the switch is read from the canonical
 * files that tools/set_config.php writes.
 *
 * Every Bank entry point signs the visitor in, then calls open() and then allows(), before anything else: the tab, the
 * POST handler, the export (the sign-in first, so a visitor who is not signed in learns nothing about the switch or the
 * tenant: BK-SEC-4). The POST handler and the export also require fromOwnPage() before they read or write anything.
 * GRANTS is the whole permission model of v1 — admins only (SPEC-bank O1). Widening it later (Q11) is three lines — the
 * entry here, public.php's $_tabPerms['bank'] = '*admin' and showLink()'s first term — plus an audited grant list, not a
 * change at any caller. RBAC ($can) is not used: its fixed list maps no bank.* key.
 *
 * PHP 7.4. Pure reads: nothing here writes a file or a row. PluginConfig::read() may write one log line per request for an
 * unreadable configuration file, and ConfigVault::fill one on an exception.
 */
final class BankGate
{
    /** The switch, set and cleared by tools/set_config.php. Off (absent) after the deploy. */
    public const SWITCH_KEY = 'bank_screen_enabled';

    /** What each key needs on the fresh record. v1: both are 'admin' — is_admin truthy, re-read on every request. */
    public const GRANTS = ['bank.view' => 'admin', 'bank.import' => 'admin'];

    /** @var array<string,?array> the configuration read for each data directory, once per request */
    private static $cfg = [];

    public static function open(?string $dataDir): bool
    {
        $cfg = self::config($dataDir);
        if ($cfg === null) return false;
        try {
            return self::halves($cfg, (string)$dataDir) && self::switchOn($cfg);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The Uganda-and-no-SSP halves of open(), without the switch: rule A's expression over the same files. */
    public static function ugandaWithoutSsp(?string $dataDir): bool
    {
        $cfg = self::config($dataDir);
        if ($cfg === null) return false;
        try {
            return self::halves($cfg, (string)$dataDir);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The switch, read as InstallAuth::flagOn() reads its flag: a boolean, or 1 / true / on / yes. */
    public static function switchOn(array $cfg): bool
    {
        $v = $cfg[self::SWITCH_KEY] ?? null;
        if (is_bool($v)) return $v;
        if (!is_scalar($v)) return false;
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * The fresh staff record when it may do $key, else null. $store is the plugin store (findOne); $sessionRetailer is
     * what requireLogin() returned, and only its id is used. Inactive, demoted, unknown, or unreadable: null.
     */
    public static function allows(string $key, $store, array $sessionRetailer): ?array
    {
        if (!isset(self::GRANTS[$key])) return null;
        $id = (int)($sessionRetailer['id'] ?? 0);
        if ($id <= 0 || !is_object($store) || !method_exists($store, 'findOne')) return null;
        try {
            $fresh = $store->findOne('retailers.json', 'id', $id);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($fresh) || (int)($fresh['id'] ?? 0) !== $id) return null;
        if (empty($fresh['is_active'])) return null;
        if (self::GRANTS[$key] === 'admin' && empty($fresh['is_admin'])) return null;
        return $fresh;
    }

    /**
     * Whether a link to ECO Bank is shown: the sidebar, the CEO page's Quick Links (Q15) and the Cashbook's "Bank & other
     * accounts" tab (5.18.98). True exactly when the tab would open for this visitor:
     *   - the dispatcher's own check, $_tabPerms['bank'] = '*admin', against the SESSION copy's is_admin — what
     *     public.php:2216 reads into $isAdmin. Never a page's own $isAdmin: cashbook.php:7 widens it to accountants;
     *   - then the tab's two: open() over the configuration FILES, and allows('bank.view') on the FRESH staff record.
     * Never the store copy of the settings. Cheapest first: a session that is not an administrator's reads nothing.
     */
    public static function showLink(?string $dataDir, $store, array $sessionRetailer): bool
    {
        return !empty($sessionRetailer['is_admin']) && self::open($dataDir) && self::allows('bank.view', $store, $sessionRetailer) !== null;
    }

    /**
     * True only when the browser shows that THIS request came from a page of this site (SPEC-bank §d.2, §d.4):
     *   - Sec-Fetch-Site 'same-origin', or, for a GET only, 'none' (an address typed, or a bookmark). Any other value
     *     ('cross-site', 'same-site') is a refusal;
     *   - with no Sec-Fetch-Site (an older browser): an Origin that names this host ('null' never does); with no Origin,
     *     a Referer that names this host;
     *   - with none of the three: a GET passes (an older browser's own navigation carries none), a POST is refused.
     * "Names this host" is the comparison CustomerSession::crossSite() makes (host[:port], or the host alone, equals
     * HTTP_HOST), so the two never disagree about one request. Why it is needed: the day's form token is the same for
     * every user and is printed on the login page, and the staff cookie is SameSite=None (the uCRM iframe), so neither
     * says where a request came from. A pure read of the request headers.
     */
    public static function fromOwnPage(string $method): bool
    {
        $get = strtoupper($method) === 'GET';
        $sfs = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($sfs !== '') return $sfs === 'same-origin' || ($get && $sfs === 'none');
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '') return self::namesThisHost($origin);
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer !== '') return self::namesThisHost($referer);
        return $get;
    }

    /** For tests that switch configurations inside one process. */
    public static function reset(): void
    {
        self::$cfg = [];
    }

    /**
     * An Origin or Referer whose host[:port], or host alone, is HTTP_HOST. No host (an Origin of 'null' has none) or no
     * HTTP_HOST: false.
     */
    private static function namesThisHost(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $req = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($host === '' || $req === '') return false;
        $port = parse_url($url, PHP_URL_PORT);
        return ($port ? $host . ':' . $port : $host) === $req || $host === $req;
    }

    private static function halves(array $cfg, string $dataDir): bool
    {
        require_once __DIR__ . '/currency.php';
        if (!class_exists('StaffJobsGate')) require_once __DIR__ . '/StaffJobsGate.php';
        return StaffJobsGate::applies($cfg, $dataDir) && !dn_ssp_selectable($cfg);
    }

    /** PluginConfig::read() of this data directory, once per request; null when there is none or it cannot be read. */
    private static function config(?string $dataDir): ?array
    {
        if ($dataDir === null || trim($dataDir) === '') return null;
        if (!array_key_exists($dataDir, self::$cfg)) {
            try {
                require_once __DIR__ . '/PluginConfig.php';
                self::$cfg[$dataDir] = PluginConfig::read(dirname(__DIR__), $dataDir);
            } catch (\Throwable $e) {
                self::$cfg[$dataDir] = null;
            }
        }
        return self::$cfg[$dataDir];
    }
}

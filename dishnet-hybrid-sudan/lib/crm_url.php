<?php
declare(strict_types=1);
/**
 * The ONE source of truth for THIS install's CRM web address.
 *
 * Every "View in CRM" link, WhatsApp message and webhook URL asks these
 * helpers instead of hardcoding a hostname, so the same code serves
 * crm.dishnetuganda.com, crm.dishnetafrica.com, or any future install:
 *
 *   dn_crm_web()        https://host              (scheme+host, no path)
 *   dn_crm_link()       https://host/crm/<path>   (a page inside the CRM UI)
 *   dn_plugin_public()  this plugin's public.php  (webhooks, public pages)
 *
 * Resolution order: config crm_public_url (an override, normally unset),
 * then ucrm.json (ucrmPublicUrl / pluginPublicUrl), then config
 * crm_base_url with any /crm and /api/vX.Y suffix stripped. Empty string
 * when none exists.
 *
 * The override exists because ucrm.json is not always externally correct.
 * uCRM writes the address it was CONFIGURED with, and behind a reverse
 * proxy that is often the internal one: crm.dishnetuganda.com:8443, where
 * 8443 is what the proxy forwards to and nothing a browser can reach.
 * Clicking through the CRM then lands on a dead port — and, worse, that
 * address is what the plugin puts in quote emails and portal links, so the
 * wrong port reaches customers.
 *
 * Fix the uCRM setting first; this is for when you cannot, or not yet.
 * Set crm_public_url to the address a CUSTOMER's browser must use and the
 * scheme, host and port of every generated link come from it, paths
 * untouched. Unset, nothing changes: every existing install resolves
 * exactly as it did before this existed.
 */
if (!function_exists('dn_crm_web')) {
    /** @return array<string,mixed> parsed ucrm.json, cached; [] if absent */
    function dn_ucrm_json(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $root = dirname(__DIR__);
        foreach ([$root . '/ucrm.json', $root . '/data/ucrm.json'] as $p) {
            if (!is_file($p)) continue;
            $d = json_decode((string)@file_get_contents($p), true);
            if (is_array($d) && $d) return $cached = $d;
        }
        return $cached = [];
    }

    /**
     * The operator's override, as scheme://host[:port], or '' when unset or
     * unusable. Never guessed: a value that is not an absolute http(s) URL
     * with a host is ignored, so a typo falls back to the old behaviour
     * rather than producing links to nowhere.
     */
    function dn_public_override(?array $config): string
    {
        if (!$config) return '';
        $raw = trim((string)($config['crm_public_url'] ?? ''));
        if ($raw === '') return '';
        $u = parse_url($raw);
        if (!is_array($u) || empty($u['host'])) return '';
        $scheme = strtolower((string)($u['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') return '';
        $port = isset($u['port']) ? (int)$u['port'] : 0;
        $bare = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        return $scheme . '://' . $u['host'] . ($port && !$bare ? ':' . $port : '');
    }

    /**
     * Put $url on the override's scheme/host/port, keeping its path and
     * query. That is what makes one setting correct every generated link,
     * including pluginPublicUrl's long path, without restating any of them.
     */
    function dn_with_override(string $url, ?array $config): string
    {
        $over = dn_public_override($config);
        if ($over === '' || $url === '') return $url;
        $u = parse_url($url);
        if (!is_array($u)) return $url;
        $tail = (string)($u['path'] ?? '');
        if (!empty($u['query']))    $tail .= '?' . $u['query'];
        if (!empty($u['fragment'])) $tail .= '#' . $u['fragment'];
        return $over . $tail;
    }

    /** Scheme+host of this uCRM install — no path, no trailing slash. */
    function dn_crm_web(?array $config = null): string
    {
        $over = dn_public_override($config);
        if ($over !== '') return $over;
        $base = trim((string)(dn_ucrm_json()['ucrmPublicUrl'] ?? ''));
        if ($base === '' && $config) $base = trim((string)($config['crm_base_url'] ?? ''));
        if ($base === '') return '';
        $base = preg_replace('#/api/v[\d.]+/?$#', '', rtrim($base, '/'));
        if (substr($base, -4) === '/crm') $base = substr($base, 0, -4);
        return rtrim($base, '/');
    }

    /** Absolute link into the CRM UI, e.g. dn_crm_link($config, 'client/123'). */
    function dn_crm_link(?array $config, string $path): string
    {
        return dn_crm_web($config) . '/crm/' . ltrim($path, '/');
    }

    /** This plugin's public.php URL — for webhooks and public pages. */
    function dn_plugin_public(?array $config = null): string
    {
        $url = trim((string)(dn_ucrm_json()['pluginPublicUrl'] ?? ''));
        if ($url !== '') return dn_with_override($url, $config);
        return dn_crm_web($config) . '/crm/_plugins/' . basename(dirname(__DIR__)) . '/public.php';
    }

    /** A sibling file of public.php in this plugin (e.g. 'webhook.php'). */
    function dn_plugin_file(?array $config, string $file): string
    {
        return preg_replace('#/public\.php$#', '', dn_plugin_public($config)) . '/' . ltrim($file, '/');
    }
}

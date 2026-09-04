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
 * Resolution order: ucrm.json (ucrmPublicUrl / pluginPublicUrl — written by
 * uCRM itself, always right), then config crm_base_url with any /crm and
 * /api/vX.Y suffix stripped. Empty string when neither exists.
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

    /** Scheme+host of this uCRM install — no path, no trailing slash. */
    function dn_crm_web(?array $config = null): string
    {
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
        if ($url !== '') return $url;
        return dn_crm_web($config) . '/crm/_plugins/' . basename(dirname(__DIR__)) . '/public.php';
    }

    /** A sibling file of public.php in this plugin (e.g. 'webhook.php'). */
    function dn_plugin_file(?array $config, string $file): string
    {
        return preg_replace('#/public\.php$#', '', dn_plugin_public($config)) . '/' . ltrim($file, '/');
    }
}

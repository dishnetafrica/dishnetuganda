<?php
declare(strict_types=1);
/**
 * The public address Evolution must POST WhatsApp events to.
 *
 * Shared by the Engage → WhatsApp AI page and the CLI webhook doctor, so the
 * button and the terminal can never disagree about the URL.
 *
 * uCRM does NOT serve arbitrary PHP files from a plugin directory — only
 * public.php. Asking for evo_webhook.php directly returns uCRM's own "Page not
 * found", which is why webhook registration once appeared to work and then
 * nothing ever arrived. Routes go through public.php?page=... instead.
 */
require_once __DIR__ . '/crm_url.php';

if (!function_exists('wa_ai_public_base')) {

    /**
     * Base URL of this plugin's public.php directory, no trailing slash.
     *
     * Resolution order:
     *   1. plugin_public_url from Configuration — the operator's word is law.
     *   2. ucrm.json's pluginPublicUrl — written by UISP itself at install
     *      time, so it is right on every install and works from the CLI,
     *      where no request context exists at all.
     *   3. Derived from the current request — last resort, because this page
     *      is normally loaded inside the UISP admin iframe where SCRIPT_NAME
     *      is not the plugin's own public path.
     *
     * Returns '' when no trustworthy address exists (never a scheme with an
     * empty host): callers must refuse to register a webhook in that case.
     */
    function wa_ai_public_base(array $cfg): string
    {
        $saved = rtrim(trim((string)($cfg['plugin_public_url'] ?? '')), '/');
        if ($saved !== '') return $saved;

        $fromUcrm = rtrim(preg_replace('#/public\.php$#', '', dn_plugin_public($cfg)), '/');
        if (preg_match('#^https?://[^/]+#', $fromUcrm)) return $fromUcrm;

        // uCRM terminates TLS and proxies to the plugin over plain HTTP, so
        // $_SERVER['HTTPS'] is unset here even when the browser is on https.
        // Trust the forwarded header, and otherwise assume https — an http
        // guess is always wrong for a uCRM install.
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') return '';
        $fwd    = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $scheme = $fwd !== '' ? $fwd : 'https';
        return $scheme . '://' . $host
             . rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    }

    /** The full webhook address, secret included. '' when no base is known. */
    function wa_ai_webhook_url(array $cfg, string $secret): string
    {
        $base = wa_ai_public_base($cfg);
        if ($base === '') return '';
        return $base . '/public.php?page=evo_webhook&token=' . rawurlencode($secret);
    }
}

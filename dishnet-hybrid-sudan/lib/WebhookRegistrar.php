<?php
declare(strict_types=1);
/**
 * WebhookRegistrar — the one policy for the plugin's uCRM webhook endpoint.
 *
 * Every event this plugin acts on — a new quote, an invoice, a payment, a
 * suspension — arrives through one uCRM webhook endpoint or not at all. Three
 * places used to register that endpoint, each with its own idea of the right
 * address and its own fixed list of events: the Settings button, the
 * webhook_register debug action and tools/webhook_setup.php. The button's list
 * had eight events and no quote.add; on an endpoint that delivered every
 * event, one click would have narrowed it and quotations would have stopped,
 * silently. It also replaced the endpoint's address with the public one from
 * ucrm.json — which uCRM calls from inside its own container and may not be
 * able to reach — and generated webhook_secret as a side line, a value that
 * also derives the customer app's JWT key and gates the debug_key API.
 *
 * This class is now the only policy, and it repairs rather than rewrites:
 *
 *   no endpoint of ours      → create one, for every event, at the first
 *                              address uCRM can actually reach
 *   ours, reachable          → keep its address, whatever it is
 *   ours, unreachable        → replace the address with one that is reached
 *   inactive                 → activate
 *   a narrowed event list    → widen it (uCRM's "any event", else add the
 *                              missing names); never narrow, never remove
 *   nothing wrong            → change nothing, call nothing that writes
 *   webhook_secret           → never read, never written
 *
 * Reachability is tested the way uCRM will call the address: an empty POST,
 * which webhook.php itself answers 400 "Empty body." (a GET gets 405 "POST
 * required."). Anything else — uCRM's own 404 page, a login redirect, no
 * connection — is not the plugin. Every write is re-read from uCRM and checked
 * before it is called a success. plan() is pure and decides; apply() acts.
 */
final class WebhookRegistrar
{
    /** The route that reaches webhook.php through public.php — what the CLI tool has always registered. */
    public const ROUTE = 'public.php?page=crm_webhook';

    /** Also routed to webhook.php (includes/routes.php). Accepted where found, never registered anew. */
    public const ROUTE_LEGACY = 'public.php?page=webhook';

    /**
     * uCRM event types webhook.php dispatches on. An endpoint narrowed to a list
     * must carry at least these; an empty list — uCRM's "any event" — is what a
     * created endpoint gets and what is preferred, because webhook.php decides
     * per event in its own switch and a list would drop any event added later.
     */
    public const REQUIRED_EVENTS = [
        'client.add', 'client.edit', 'client.delete', 'client.archive', 'client.invite',
        'invoice.add', 'invoice.add_draft', 'invoice.draft_approved', 'invoice.edit', 'invoice.delete',
        'invoice.near_due', 'invoice.overdue',
        'payment.add', 'payment.edit', 'payment.delete',
        'quote.add',
        'service.add', 'service.edit', 'service.activate', 'service.suspend', 'service.suspend_cancel',
        'service.postpone', 'service.end',
        'ticket.add', 'job.add',
    ];

    /** An endpoint is ours when its address is inside this plugin's directory. */
    public static function isOurs(array $endpoint, string $pluginDirName): bool
    {
        return strpos((string)($endpoint['url'] ?? ''), '/_plugins/' . $pluginDirName . '/') !== false;
    }

    /** True when the address is ours and its query is one of the two routes that reach webhook.php. */
    public static function routesToPlugin(string $url, string $pluginDirName): bool
    {
        if (!self::isOurs(['url' => $url], $pluginDirName)) return false;
        $path  = (string)parse_url($url, PHP_URL_PATH);
        $query = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        return substr($path, -11) === '/public.php'
            && in_array((string)($query['page'] ?? ''), ['crm_webhook', 'webhook'], true);
    }

    /** Events this endpoint would not deliver: [] for "any event" or a list that covers REQUIRED_EVENTS. */
    public static function missingEvents(array $endpoint): array
    {
        if (!empty($endpoint['anyEvent'])) return [];
        $have = array_map('strval', (array)($endpoint['eventTypes'] ?? []));
        if ($have === []) return [];
        return array_values(array_diff(self::REQUIRED_EVENTS, $have));
    }

    /** 'any' or the comma-joined list, for display. */
    public static function eventsLabel(array $endpoint): string
    {
        $have = (array)($endpoint['eventTypes'] ?? []);
        return (!empty($endpoint['anyEvent']) || $have === []) ? 'any' : implode(', ', array_map('strval', $have));
    }

    /**
     * Addresses to try, in order: the operator's public base (plugin_public_url,
     * else what uCRM told us), then the plugin as seen from the API's own root —
     * the address the API itself is reached on from in here, so it is known to
     * resolve from inside the container.
     */
    public static function candidates(array $config, string $pluginDirName, string $apiBase = ''): array
    {
        require_once __DIR__ . '/wa_webhook_url.php';
        $out  = [];
        $base = rtrim((string)wa_ai_public_base($config), '/');
        if ($base !== '') $out[] = $base . '/' . self::ROUTE;
        $apiBase = rtrim($apiBase, '/');
        if ($apiBase !== '') {
            $root  = preg_replace('#/api/v[0-9.]+$#', '', $apiBase);
            $out[] = $root . '/_plugins/' . $pluginDirName . '/' . self::ROUTE;
        }
        $out = array_filter($out, function ($u) { return preg_match('#^https?://#i', $u) === 1; });
        return array_values(array_unique($out));
    }

    /**
     * Does an empty POST to $url reach webhook.php? Only its own two answers count.
     * $verifySsl true asks for a verifiable certificate; a second call with
     * false tells the two apart, and the endpoint is registered accordingly.
     */
    public static function reaches(string $url, bool $verifySsl = false): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CUSTOMREQUEST  => 'POST', CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => $verifySsl, CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_PROXY          => '',
        ]);
        $body = (string)curl_exec($ch);
        curl_close($ch);
        return stripos($body, 'Empty body') !== false || stripos($body, 'POST required') !== false;
    }

    /**
     * Decide what to do. Pure: takes uCRM's endpoint list and a reachability
     * callable (string $url, bool $verifySsl): bool, and never calls uCRM. The
     * plan carries what was probed, what changes and why, for a page or a tool
     * to print before anything is done.
     *
     * @return array{action:string,endpoint:?array,ours_count:int,url:string,verify_ssl:bool,changes:array,missing_events:array,probed:array,reasons:array}
     */
    public static function plan(array $endpoints, array $config, string $pluginDirName, callable $reaches, string $apiBase = ''): array
    {
        $ours = array_values(array_filter($endpoints, function ($e) use ($pluginDirName) {
            return is_array($e) && self::isOurs($e, $pluginDirName);
        }));
        // Active first; among equals, uCRM's own order.
        $active = []; $idle = [];
        foreach ($ours as $e) { if (!empty($e['isActive'])) $active[] = $e; else $idle[] = $e; }
        $ours = array_merge($active, $idle);
        $ep   = $ours[0] ?? null;

        $plan = ['action' => 'keep', 'endpoint' => $ep, 'ours_count' => count($ours), 'url' => '',
                 'verify_ssl' => true, 'changes' => [], 'missing_events' => [], 'probed' => [], 'reasons' => []];

        $probe = function (string $url) use (&$plan, $reaches): bool {
            if (isset($plan['probed'][$url])) return $plan['probed'][$url]['reached'];
            if ($reaches($url, true))  { $plan['probed'][$url] = ['reached' => true,  'verify_ssl' => true];  return true; }
            if ($reaches($url, false)) { $plan['probed'][$url] = ['reached' => true,  'verify_ssl' => false]; return true; }
            $plan['probed'][$url] = ['reached' => false, 'verify_ssl' => false];
            return false;
        };
        $pick = function (array $urls) use ($probe): string {
            foreach ($urls as $u) { if ($probe($u)) return $u; }
            return '';
        };
        $candidates = self::candidates($config, $pluginDirName, $apiBase);

        if ($ep === null) {
            $url = $pick($candidates);
            if ($url === '') {
                $plan['action']    = 'unreachable';
                $plan['reasons'][] = 'no endpoint of ours exists, and uCRM cannot reach the plugin at any address tried';
                return $plan;
            }
            $plan['action']     = 'create';
            $plan['url']        = $url;
            $plan['verify_ssl'] = $plan['probed'][$url]['verify_ssl'];
            $plan['reasons'][]  = 'no endpoint of ours exists';
            return $plan;
        }

        $current = (string)($ep['url'] ?? '');
        if ($probe($current)) {
            $plan['url']        = $current;
            $plan['verify_ssl'] = $plan['probed'][$current]['verify_ssl'];
        } else {
            $plan['reasons'][] = 'uCRM cannot reach the registered address';
            $url = $pick($candidates);
            if ($url === '') {
                $plan['action']    = 'unreachable';
                $plan['url']       = $current;
                $plan['reasons'][] = 'nor the plugin at any other address tried — the endpoint was left as it is';
                return $plan;
            }
            $plan['url']            = $url;
            $plan['verify_ssl']     = $plan['probed'][$url]['verify_ssl'];
            $plan['changes']['url'] = $url;
        }
        if (empty($ep['isActive'])) {
            $plan['changes']['isActive'] = true;
            $plan['reasons'][] = 'the endpoint is inactive';
        }
        $missing = self::missingEvents($ep);
        if ($missing) {
            $plan['missing_events']    = $missing;
            $plan['changes']['events'] = 'any';
            $plan['reasons'][] = 'the event list omits ' . count($missing) . ' event(s) this plugin acts on: ' . implode(', ', $missing);
        }
        $plan['action'] = $plan['changes'] ? 'update' : 'keep';
        if ($plan['action'] === 'keep') {
            $plan['reasons'][] = 'the endpoint is reachable, active and delivers every event this plugin acts on';
        }
        return $plan;
    }

    /** The endpoint uCRM lists under $id right now, or null. */
    public static function find(CrmApiClient $crm, int $id): ?array
    {
        foreach ($crm->getWebhooks() as $e) {
            if (is_array($e) && (int)($e['id'] ?? 0) === $id) return $e;
        }
        return null;
    }

    /**
     * Carry out a plan against uCRM. Only 'create' and 'update' write; every
     * write is re-read and checked. Returns what the Settings button shows and
     * the tools print.
     */
    public static function apply(CrmApiClient $crm, array $plan): array
    {
        $out = ['success' => false, 'action' => $plan['action'], 'message' => '', 'webhook_id' => null,
                'url' => $plan['url'], 'events' => null, 'steps' => [], 'plan' => $plan];
        $ep   = is_array($plan['endpoint'] ?? null) ? $plan['endpoint'] : null;
        $step = function (string $name, bool $ok, $error = null) use (&$out): void {
            $out['steps'][] = ['step' => $name, 'ok' => $ok, 'error' => $ok ? null : $error];
        };

        switch ($plan['action']) {
            case 'keep':
                $out['success']    = true;
                $out['webhook_id'] = (int)($ep['id'] ?? 0);
                $out['events']     = self::eventsLabel($ep ?? []);
                $out['message']    = 'Webhook endpoint #' . $out['webhook_id'] . ' is reachable, active and delivers every event this plugin acts on — nothing changed.';
                return $out;

            case 'unreachable':
                $out['message'] = 'uCRM cannot reach the plugin webhook at any address tried; nothing was changed. '
                                . 'Set plugin_public_url in Settings to the address the plugin is served at, or check that the container can reach its own public name.';
                return $out;

            case 'create':
                $created = $crm->createWebhook($plan['url'], [], (bool)$plan['verify_ssl']);
                $id = (int)($created['id'] ?? 0);
                $step('create', $id > 0, $created === null ? $crm->getLastError() : $created);
                if ($id <= 0) { $out['message'] = 'uCRM did not create the endpoint: ' . json_encode($crm->getLastError()); return $out; }
                $fresh = self::find($crm, $id);
                $step('verify', $fresh !== null && self::missingEvents($fresh) === [] && !empty($fresh['isActive']), $fresh);
                $out['webhook_id'] = $id;
                if ($fresh === null) { $out['message'] = "uCRM answered the create but does not list endpoint #{$id} back — check System → Webhooks."; return $out; }
                $out['events']  = self::eventsLabel($fresh);
                $out['success'] = end($out['steps'])['ok'];
                $out['message'] = $out['success']
                    ? "Webhook endpoint #{$id} created for every event at {$plan['url']}."
                    : "Webhook endpoint #{$id} was created but uCRM lists it " . (empty($fresh['isActive']) ? 'inactive' : 'with a narrowed event list') . ' — check System → Webhooks.';
                return $out;

            case 'update':
                $id = (int)($ep['id'] ?? 0);
                $out['webhook_id'] = $id;
                $patch = [];
                if (isset($plan['changes']['url'])) {
                    $patch['url'] = $plan['changes']['url'];
                    // A new address is registered with the certificate setting its probe earned.
                    $patch['verifySslCertificate'] = (bool)$plan['verify_ssl'];
                }
                if (isset($plan['changes']['isActive'])) $patch['isActive'] = true;
                if ($patch) {
                    $r = $crm->updateWebhook($id, $patch);
                    $step('patch', $r !== null, $crm->getLastError());
                }
                if (($plan['changes']['events'] ?? null) === 'any') {
                    // uCRM's own "any event" first; an older uCRM without the
                    // field refuses it, and then the missing names are added to
                    // the list — never fewer than before, never a removal.
                    $r = $crm->updateWebhook($id, ['anyEvent' => true]);
                    $step('anyEvent', $r !== null, $crm->getLastError());
                    $fresh = self::find($crm, $id);
                    if ($fresh === null || self::missingEvents($fresh) !== []) {
                        $have  = array_map('strval', (array)($ep['eventTypes'] ?? []));
                        $union = array_values(array_unique(array_merge($have, self::REQUIRED_EVENTS)));
                        $r2 = $crm->updateWebhook($id, ['eventTypes' => $union]);
                        $step('eventTypes', $r2 !== null, $crm->getLastError());
                    }
                }
                $fresh = self::find($crm, $id);
                $stillMissing = $fresh === null ? self::REQUIRED_EVENTS : self::missingEvents($fresh);
                $good = $fresh !== null
                     && (string)($fresh['url'] ?? '') === (string)$plan['url']
                     && !empty($fresh['isActive'])
                     && $stillMissing === [];
                $step('verify', $good, $fresh);
                $out['success'] = $good;
                $out['events']  = $fresh ? self::eventsLabel($fresh) : null;
                if ($good) {
                    $done = [];
                    if (isset($plan['changes']['url']))      $done[] = 'address → ' . $plan['url'];
                    if (isset($plan['changes']['isActive'])) $done[] = 'activated';
                    if (isset($plan['changes']['events']))   $done[] = 'event list widened';
                    $out['message'] = "Webhook endpoint #{$id} repaired: " . implode('; ', $done) . '.';
                } else {
                    $why = [];
                    if ($fresh === null) $why[] = 'uCRM no longer lists it';
                    else {
                        if ((string)($fresh['url'] ?? '') !== (string)$plan['url']) $why[] = 'the address did not change';
                        if (empty($fresh['isActive']))                           $why[] = 'it is still inactive';
                        if ($stillMissing !== [])                                $why[] = 'it still omits ' . implode(', ', $stillMissing) . ' — in uCRM → System → Webhooks → endpoint #' . $id . ', tick "Any event"';
                    }
                    $out['message'] = "Webhook endpoint #{$id}: repair incomplete — " . implode('; ', $why) . '.';
                }
                return $out;
        }
        $out['message'] = 'Unknown plan action ' . (string)$plan['action'];
        return $out;
    }

    /** Plan against uCRM's current list, then act. What the button and the debug action call. */
    public static function run(CrmApiClient $crm, array $config, string $pluginDirName, ?callable $reaches = null): array
    {
        $plan = self::plan($crm->getWebhooks(), $config, $pluginDirName,
                           $reaches ?? [self::class, 'reaches'], $crm->getBaseUrl());
        return self::apply($crm, $plan);
    }
}

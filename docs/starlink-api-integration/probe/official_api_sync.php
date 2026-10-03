<?php
/**
 * official_api/official_api_sync.php
 * ---------------------------------------------------------------------------
 * PARALLEL, FLAG-OFF official Starlink Public API v2 adapter for
 * dishnet-data-report. Fetches ONE Starlink account via the official OIDC API
 * and writes SHADOW files under data/api_shadow/. It is a VALIDATION / parallel
 * source: the official API is NOT authoritative and this adapter changes nothing
 * the plugin already produces.
 *
 * WHAT IT DOES NOT TOUCH (by construction):
 *   - the cookie/session code (session_manager.php etc.) — not imported or called
 *   - any existing data file (sl_usage.json, sl_svc_cache.json, dr_accounts.json,
 *     wifi_router_map.json, dr_invoices.json, settings, ...) — never read or written
 *   - the current Data Report output, config, cron table, or dispatcher
 *   - the other Starlink accounts (this credential only sees its own account)
 *
 * IT IS INERT BY DEFAULT. It does nothing unless explicitly enabled:
 *   DR_OFFICIAL_API_SYNC=yes   (the enable flag)  AND
 *   credentials in the environment (see below).
 * It is not wired into any cron or ?action dispatcher; it runs only when invoked:
 *   DR_OFFICIAL_API_SYNC=yes DR_SL_CLIENT_ID=... DR_SL_CLIENT_SECRET=... \
 *     php official_api/official_api_sync.php
 * Fully reversible: delete data/api_shadow/ and this official_api/ directory.
 *
 * SAFETY:
 *   - Credentials come from ENVIRONMENT VARIABLES ONLY. Never hard-coded, never
 *     written to disk, never logged. DR_SL_CLIENT_ID + DR_SL_CLIENT_SECRET (or
 *     DR_SL_BEARER); SL_* accepted as a fallback for convenience.
 *   - Data requests are GET. The only POSTs are (a) the OAuth token mint and
 *     (b) /data-usage/query (a documented READ/QUERY with no mutating fields).
 *     There is NO PUT/PATCH/DELETE and NO other POST path; a hard guard enforces
 *     that the only POST targets are the token endpoint and the usage query.
 *   - Writes ONLY under data/api_shadow/ via atomic temp+rename (mode 0640).
 *   - No device command, no service/subscription/order mutation — impossible here.
 * ---------------------------------------------------------------------------
 */

// ---- configuration (environment only) --------------------------------------
$WELL_KNOWN   = getenv('DR_SL_WELL_KNOWN') ?: 'https://starlink.com/api/auth/.well-known/openid-configuration';
$BASE         = rtrim(getenv('DR_SL_BASE') ?: 'https://starlink.com/api/public/v2', '/');
$CLIENT_ID    = getenv('DR_SL_CLIENT_ID') ?: getenv('SL_CLIENT_ID');
$CLIENT_SECRET= getenv('DR_SL_CLIENT_SECRET') ?: getenv('SL_CLIENT_SECRET');
$BEARER       = getenv('DR_SL_BEARER') ?: getenv('SL_BEARER');
$SCOPE        = getenv('DR_SL_SCOPE') ?: getenv('SL_SCOPE') ?: '';
$AUDIENCE     = getenv('DR_SL_AUDIENCE') ?: getenv('SL_AUDIENCE') ?: '';
$TOKEN_AUTH   = strtolower(getenv('DR_SL_TOKEN_AUTH') ?: getenv('SL_TOKEN_AUTH') ?: 'body');
$ENABLE       = getenv('DR_OFFICIAL_API_SYNC');
$TIMEOUT      = (int)(getenv('DR_SL_TIMEOUT') ?: 30);
$MAX_PAGES    = (int)(getenv('DR_SL_MAX_PAGES') ?: 20);
$USAGE_BODY   = getenv('DR_SL_USAGE_BODY') ?: '{}';

$DATA_DIR     = __DIR__ . '/../data';
$SHADOW_DIR   = $DATA_DIR . '/api_shadow';

$TOKEN_ENDPOINT = null;   // set during discovery; the only non-usage POST target
$CALLS = 0;

// ---- flag / credential gate -------------------------------------------------
if ($ENABLE !== 'yes') {
    fwrite(STDOUT, "official_api_sync: DISABLED. Set DR_OFFICIAL_API_SYNC=yes to enable. Nothing was done.\n");
    exit(0);
}
if (!$BEARER && !($CLIENT_ID && $CLIENT_SECRET)) {
    fwrite(STDERR, "official_api_sync: need DR_SL_BEARER, or DR_SL_CLIENT_ID + DR_SL_CLIENT_SECRET, in the environment.\n");
    exit(2);
}

// ---- HTTP (GET-only for data; POST only to token endpoint + usage query) -----
function http_request(string $method, string $url, array $headers, ?string $body, int $timeout): array {
    global $TOKEN_ENDPOINT, $BASE, $CALLS;
    $path = strtok($url, '?');            // URL without query string
    if ($method === 'POST') {
        if ($path !== $TOKEN_ENDPOINT && $path !== $BASE . '/data-usage/query') {
            throw new RuntimeException('SAFETY: POST only permitted to the token endpoint or /data-usage/query');
        }
    } elseif ($method !== 'GET') {
        throw new RuntimeException('SAFETY: method ' . $method . ' not allowed (read-only adapter)');
    }
    $CALLS++;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return [0, json_encode(['_transport_error' => substr($err, 0, 160)])];
    }
    return [$code, $resp];
}

function bearer_headers(string $token): array {
    return ['Authorization: Bearer ' . $token, 'Accept: application/json'];
}

/** Parse the ServiceResponse envelope; return [httpCode, ok, content]. */
function envelope(int $code, string $body): array {
    $doc = json_decode($body, true);
    if (is_array($doc) && array_key_exists('content', $doc) && array_key_exists('isValid', $doc)) {
        return [$code, (bool)$doc['isValid'], $doc['content']];
    }
    return [$code, $code === 200, $doc];
}

function api_get(string $path, string $token, array $params = []): array {
    global $BASE, $TIMEOUT;
    $url = $BASE . $path . ($params ? ('?' . http_build_query($params)) : '');
    [$code, $body] = http_request('GET', $url, bearer_headers($token), null, $TIMEOUT);
    usleep(300000);
    return envelope($code, $body);
}

/** Walk page-index pagination for a GET list endpoint. */
function api_list(string $path, string $token): array {
    global $MAX_PAGES;
    $out = []; $page = 0;
    while ($page < $MAX_PAGES) {
        [$code, $ok, $content] = api_get($path, $token, ['page' => $page]);
        if (!$ok) break;
        if (is_array($content) && isset($content['results'])) {
            $out = array_merge($out, $content['results'] ?: []);
            if (!empty($content['isLastPage'])) break;
            $page++;
        } elseif (is_array($content) && array_keys($content) === range(0, count($content) - 1)) {
            $out = array_merge($out, $content); break;   // bare list
        } else {
            if ($content !== null) $out[] = $content;
            break;
        }
    }
    return $out;
}

function discover_token_endpoint(): string {
    global $WELL_KNOWN, $TIMEOUT;
    [$code, $body] = http_request('GET', $WELL_KNOWN, ['Accept: application/json'], null, $TIMEOUT);
    if ($code !== 200) throw new RuntimeException("OIDC discovery failed: HTTP $code");
    $te = json_decode($body, true)['token_endpoint'] ?? null;
    if (!$te) throw new RuntimeException('OIDC discovery returned no token_endpoint');
    return $te;
}

function mint_token(): string {
    global $TOKEN_ENDPOINT, $CLIENT_ID, $CLIENT_SECRET, $SCOPE, $AUDIENCE, $TOKEN_AUTH, $TIMEOUT;
    $form = ['grant_type' => 'client_credentials'];
    if ($SCOPE !== '')    $form['scope'] = $SCOPE;
    if ($AUDIENCE !== '') $form['audience'] = $AUDIENCE;
    $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
    if ($TOKEN_AUTH === 'basic') {
        $headers[] = 'Authorization: Basic ' . base64_encode($CLIENT_ID . ':' . $CLIENT_SECRET);
    } else {
        $form['client_id'] = $CLIENT_ID;
        $form['client_secret'] = $CLIENT_SECRET;
    }
    [$code, $body] = http_request('POST', $TOKEN_ENDPOINT, $headers, http_build_query($form), $TIMEOUT);
    if ($code !== 200) throw new RuntimeException("token mint failed: HTTP $code");  // body not echoed (may carry token material)
    $tok = json_decode($body, true)['access_token'] ?? null;
    if (!$tok) throw new RuntimeException('token mint returned no access_token');
    return $tok;
}

/** POST /data-usage/query — a documented READ/QUERY (filter+pagination body only). */
function usage_query(string $token, array $bodyObj, int $page): array {
    global $BASE, $TIMEOUT;
    $url = $BASE . '/data-usage/query?' . http_build_query(['page' => $page, 'limit' => 50]);
    $headers = bearer_headers($token);
    $headers[] = 'Content-Type: application/json';
    [$code, $body] = http_request('POST', $url, $headers, json_encode($bodyObj), $TIMEOUT);
    usleep(300000);
    return envelope($code, $body);
}

// ---- shadow writer (atomic; only under data/api_shadow/) ---------------------
function shadow_save(string $name, $data): string {
    global $SHADOW_DIR;
    $path = $SHADOW_DIR . '/' . $name;
    $tmp  = $path . '.tmp.' . getmypid();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException("json_encode failed for $name");
    if (file_put_contents($tmp, $json) === false) throw new RuntimeException("write failed: $tmp");
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException("rename failed: $path"); }
    return $path;
}

// ---- run --------------------------------------------------------------------
try {
    if (!is_dir($SHADOW_DIR) && !@mkdir($SHADOW_DIR, 0750, true) && !is_dir($SHADOW_DIR)) {
        throw new RuntimeException("cannot create shadow dir: $SHADOW_DIR");
    }
    fwrite(STDOUT, "official_api_sync: ENABLED. Writing shadow files under data/api_shadow/ (parallel; nothing else touched).\n");

    if ($BEARER) {
        $token = $BEARER;
        fwrite(STDOUT, "  auth = supplied bearer (no token POST)\n");
    } else {
        $TOKEN_ENDPOINT = discover_token_endpoint();
        $token = mint_token();
        fwrite(STDOUT, "  auth = client-credentials (token minted; value hidden)\n");
    }

    // --- account ---
    [$c, $ok, $account] = api_get('/account', $token);
    $accountNumber = is_array($account) ? ($account['accountNumber'] ?? null) : null;

    // --- service lines + user terminals (joined) ---
    $sls = api_list('/service-lines', $token);
    $uts = api_list('/user-terminals', $token);
    $utBySL = [];
    foreach ($uts as $u) {
        $sl = $u['serviceLineNumber'] ?? null;
        if ($sl) $utBySL[$sl] = $u;
    }
    $svcOut = [];
    foreach ($sls as $s) {
        $sl = $s['serviceLineNumber'] ?? null;
        $u  = ($sl && isset($utBySL[$sl])) ? $utBySL[$sl] : [];
        $svcOut[] = [
            'service_line'   => $sl,
            'account_number' => $s['accountNumber'] ?? $accountNumber,
            'kit_number'     => $u['kitSerialNumber'] ?? null,
            'terminal_id'    => $u['userTerminalId'] ?? null,
            'dish_serial'    => $u['dishSerialNumber'] ?? null,
            'active'         => $s['active'] ?? null,
            'start_date'     => $s['startDate'] ?? null,
            'end_date'       => $s['endDate'] ?? null,
            'product_ref'    => $s['productReferenceId'] ?? null,
            'nickname'       => $s['nickname'] ?? null,
            'address_ref'    => $s['addressReferenceId'] ?? null,
            'source'         => 'official_api',
        ];
    }

    // --- addresses ---
    $addrs = api_list('/addresses', $token);

    // --- invoices (cost side; Starlink billing DishNet — NOT customer revenue) ---
    $invs = api_list('/billing/invoices', $token);

    // --- usage (POST read/query) ---
    $usageOut = [];
    $usageNote = '';
    $bodyObj = json_decode($USAGE_BODY, true);
    if ($bodyObj === null) { $bodyObj = []; }
    $page = 0; $usageRows = [];
    while ($page < $MAX_PAGES) {
        [$uc, $uok, $ucontent] = usage_query($token, $bodyObj, $page);
        if (!$uok) { $usageNote = "data-usage/query returned HTTP $uc (validation/no-data); set DR_SL_USAGE_BODY if a filter is required"; break; }
        $rows = []; $last = true;
        if (is_array($ucontent) && isset($ucontent['results'])) { $rows = $ucontent['results'] ?: []; $last = !empty($ucontent['isLastPage']); }
        elseif (is_array($ucontent)) { $rows = $ucontent; }
        $usageRows = array_merge($usageRows, $rows);
        if ($last || !$rows) break;
        $page++;
    }
    foreach ($usageRows as $r) {
        $sl = $r['serviceLineNumber'] ?? null;
        foreach (($r['billingCycles'] ?? []) as $cyc) {
            $pri = $cyc['totalPriorityGB'] ?? null;
            $std = $cyc['totalStandardGB'] ?? null;
            $tot = (is_numeric($pri) || is_numeric($std)) ? round((float)$pri + (float)$std, 2) : null;
            $usageOut[] = [
                'service_line'   => $sl,
                'account_number' => $r['accountNumber'] ?? $accountNumber,
                'cycle_start'    => $cyc['startDate'] ?? null,
                'cycle_end'      => $cyc['endDate'] ?? null,
                'priority_gb'    => is_numeric($pri) ? round((float)$pri, 2) : $pri,
                'standard_gb'    => is_numeric($std) ? round((float)$std, 2) : $std,
                'total_gb'       => $tot,
                'last_updated'   => $r['lastUpdated'] ?? null,
                'source'         => 'official_api',
            ];
        }
    }

    // --- write shadow files ---
    shadow_save('accounts.json',      ['account' => $account, 'source' => 'official_api']);
    shadow_save('service_lines.json', $svcOut);
    shadow_save('addresses.json',     $addrs);
    shadow_save('invoices.json',      $invs);
    shadow_save('usage.json',         $usageOut);
    shadow_save('_meta.json', [
        'generated_at'        => gmdate('c'),
        'source'              => 'official_api_v2',
        'authoritative'       => false,
        'note'               => 'PARALLEL validation shadow of the official Starlink API. NOT used by Data Report. Cookie path is unchanged.',
        'account_number'      => $accountNumber,
        'counts'              => [
            'service_lines' => count($svcOut),
            'user_terminals'=> count($uts),
            'addresses'     => count($addrs),
            'invoices'      => count($invs),
            'usage_rows'    => count($usageOut),
        ],
        'usage_note'          => $usageNote,
        'api_calls'           => $CALLS,
    ]);

    fwrite(STDOUT, sprintf(
        "  wrote shadow: service_lines=%d user_terminals=%d addresses=%d invoices=%d usage_rows=%d (api_calls=%d)\n",
        count($svcOut), count($uts), count($addrs), count($invs), count($usageOut), $CALLS
    ));
    if ($usageNote !== '') fwrite(STDOUT, "  note: $usageNote\n");
    fwrite(STDOUT, "  done. No existing file was read or written; cookie path and current Data Report output are unchanged.\n");
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, 'official_api_sync stopped safely: ' . substr($e->getMessage(), 0, 200) . "\n");
    exit(1);
}

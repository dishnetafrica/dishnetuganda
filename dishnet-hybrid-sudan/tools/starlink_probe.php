<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * starlink_probe.php — does the Uganda session actually work?
 *
 *   php tools/starlink_probe.php            verify, then list service lines
 *   php tools/starlink_probe.php --verify   verify only, one request
 *   php tools/starlink_probe.php --shape    what the response actually looks like
 *   php tools/starlink_probe.php --info     who the account is, and what it owes
 *   php tools/starlink_probe.php --usage    find the endpoint that returns data usage
 *
 * Phase 1 ends here. This reads and prints; it stores no Starlink data, posts
 * nothing to the books, and changes nothing except the session's own
 * bookkeeping. If this works, the account is good and the syncs that follow
 * have something to stand on. If it does not, nothing else was built on sand.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/StarlinkSessionStore.php';
require_once $root . '/lib/StarlinkPortalConnector.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$store   = new StarlinkSessionStore($root, $dataDir);
$conn    = new StarlinkPortalConnector($store, $config);

echo "\n  " . $conn->describe() . "\n";
echo "  " . str_repeat('─', 64) . "\n";

if (!$conn->isConfigured()) {
    echo "\n  No session imported yet.\n\n";
    echo "    docker exec -it ucrm php tools/starlink_session.php --import\n\n";
    exit(1);
}

// ── 0a. Usage: which endpoint actually returns consumption? ──────────────
//
// On the Uganda server sl_usage.json is `[]` and dr_kit_registry.json has
// kit_count 0, while sl_svc_cache.json holds 15 live service lines — every
// one with kit_number "". Both empty files are keyed by a kit serial the
// data plugin never resolves, so they will stay empty however long we wait.
// The lines themselves say has_telemetry: true, which means Starlink holds
// readings we are not asking for.
//
// This finds the endpoint that returns them. Candidates are HARVESTED from
// the data plugin's own source before any are invented: whatever path it
// calls is the one Starlink actually answers, and reading it beats guessing
// at the shape of somebody else's API. Our own families are tried after, and
// clearly marked as guesses.
//
// Read-only. It stores nothing, writes nothing, and reports what answered.
if (in_array('--usage', array_slice($argv, 1), true)) {
    require_once $root . '/lib/SiblingPlugin.php';
    $GLOBALS['_PLUGIN_ROOT'] = ((string)getenv('DN_PLUGIN_ROOT')) ?: $root;

    // A real service line to substitute in. Prefer one the data plugin has
    // already resolved; fall back to a live assignment of our own.
    $line = ''; $acct = '';
    foreach ((array)SiblingPlugin::readJson('dishnet-data-report', 'sl_svc_cache.json') as $k => $rec) {
        if (!is_array($rec)) continue;
        $line = trim((string)($rec['service_line'] ?? $k));
        $acct = trim((string)($rec['account_number'] ?? ''));
        if ($line !== '') break;
    }
    if ($line === '') {
        echo "\n  No service line to test with — sl_svc_cache.json is empty or absent,\n";
        echo "  and without a real line every templated endpoint returns 404 whether\n";
        echo "  or not it exists.\n\n";
        exit(1);
    }
    echo "\n  testing with service line   {$line}\n";
    if ($acct !== '') echo "  on account                  {$acct}\n";

    // ── Harvested from dishnet-data-report's source ─────────────────────
    $harvested = [];
    $drRoot = dirname(SiblingPlugin::pluginRoot()) . '/dishnet-data-report';
    if (is_dir($drRoot)) {
        foreach ((array)glob($drRoot . '/*.php') as $f) {
            $src = (string)@file_get_contents($f);
            if (!preg_match_all('#[\'"](/api/[A-Za-z0-9/_.\-{}$\\[\]:?&=]+)[\'"]#', $src, $m)) continue;
            foreach ($m[1] as $path) {
                if (!preg_match('/usage|telemetry|data-usage|consumption|billing-cycle/i', $path)) continue;
                $harvested[$path] = basename($f);
            }
        }
    }
    echo "  harvested from that plugin  " . (count($harvested) ?: 'none — it may not fetch usage at all') . "\n\n";

    // ── Ours, clearly marked as guesses ─────────────────────────────────
    $L = rawurlencode($line);
    $guesses = [
        '/api/webagg/v1/service-lines/' . $L . '/data-usage',
        '/api/webagg/v2/service-lines/' . $L . '/data-usage',
        '/api/webagg/v1/accounts/service-lines/' . $L . '/data-usage',
        '/api/accounts/v1/service-lines/' . $L . '/data-usage',
        '/api/accounts/v1/service-lines/' . $L . '/usage',
        '/api/telemetry/v1/service-lines/' . $L . '/usage',
    ];

    $tries = [];
    foreach ($harvested as $path => $where) {
        // Templated paths in that plugin's source carry a placeholder where
        // the line goes. Put a real one in, whatever the placeholder looks like.
        $real = preg_replace('#\{[^}]*\}|\$[A-Za-z_][A-Za-z0-9_]*#', $L, $path);
        $tries[] = ['GET', (string)$real, 'data-report/' . $where];
    }
    foreach ($guesses as $g) $tries[] = ['GET', $g, 'guess'];

    printf("  %-6s %-58s %-7s %s\n", 'CODE', 'PATH', 'BYTES', 'SOURCE');
    printf("  %s\n", str_repeat('-', 96));
    $hit = [];
    foreach ($tries as [$m, $path, $src]) {
        $d = $conn->raw($m, $path);
        printf("  %-6s %-58s %-7d %s\n",
            $d['code'] ?: ($d['error'] !== '' ? 'ERR' : '0'), substr($path, 0, 56), $d['bytes'], $src);
        if ((int)$d['code'] === 200 && $d['bytes'] > 0) $hit[] = $path;
        elseif ($d['snippet'] !== '') echo "         " . substr($d['snippet'], 0, 100) . "\n";
    }

    echo "\n";
    if ($hit === []) {
        echo "  Nothing answered 200. That is a result, not a dead end: it means the\n";
        echo "  usage endpoint is not one of these, and the next place to look is the\n";
        echo "  browser's own network tab on starlink.com while a usage chart loads.\n";
        if ($harvested === []) {
            echo "  It also means dishnet-data-report never asks for usage at all, which\n";
            echo "  would explain sl_usage.json being [] on every run.\n";
        }
    } else {
        echo "  " . count($hit) . " endpoint(s) answered. Re-run with --shape-usage to see the\n";
        echo "  payload before anything is written against it:\n";
        foreach ($hit as $h) echo "    php tools/starlink_probe.php --shape-usage " . escapeshellarg($h) . "\n";
    }
    echo "\n";
    exit($hit === [] ? 1 : 0);
}

// ── 0b. Shape one usage payload, so a collector maps read fields ─────────
$_su = array_search('--shape-usage', array_slice($argv, 1), true);
if ($_su !== false) {
    $args = array_slice($argv, 1);
    $path = (string)($args[$_su + 1] ?? '');
    if ($path === '' || strpos($path, '/api/') !== 0) {
        echo "\n  --shape-usage needs a path, e.g. /api/webagg/v1/...\n\n";
        exit(2);
    }
    $r = $conn->get($path);
    if (empty($r['ok'])) { echo "\n  " . (string)$r['error'] . "\n\n"; exit(1); }
    $data = $r['data'] ?? $r;

    // A usage payload is mostly repetition — one entry per bucket, thousands
    // of bytes of it. Truncating the JSON at a fixed length shows the first
    // few buckets and hides the field that says which cycle they belong to,
    // which is the one a collector cannot work without. So print the SHAPE:
    // every key with its type, arrays with their length and one worked
    // example, and leave the repetition out.
    $shape = static function ($v, int $depth = 0, string $key = '') use (&$shape): string {
        $pad = str_repeat('  ', $depth + 1);
        if (is_array($v)) {
            $isList = $v === [] || array_keys($v) === range(0, count($v) - 1);
            if ($isList) {
                $out = $pad . ($key !== '' ? $key . ': ' : '') . 'list[' . count($v) . ']';
                if ($v === []) return $out . " (empty)\n";
                $out .= " — first entry:\n";
                return $out . $shape($v[0], $depth + 1);
            }
            $out = $key !== '' ? $pad . $key . ": {\n" : '';
            foreach ($v as $k => $sub) $out .= $shape($sub, $depth + ($key !== '' ? 1 : 0), (string)$k);
            return $out . ($key !== '' ? $pad . "}\n" : '');
        }
        $t = is_bool($v) ? ($v ? 'true' : 'false')
           : (is_null($v) ? 'null'
           : (is_string($v) ? '"' . (strlen($v) > 48 ? substr($v, 0, 45) . '…' : $v) . '"'
           : (string)$v));
        return $pad . $key . ' = ' . $t . "\n";
    };

    echo "\n  " . $path . "\n";
    echo "  " . strlen((string)json_encode($data)) . " bytes\n\n";
    echo $shape($data);
    echo "\n  Field names above are READ, not guessed. A collector maps these.\n\n";
    exit(0);
}

// ── 0c. Shape: what does the payload actually contain? ───────────────────
// Guessing field names is what produced a table of em-dashes: the kit serial
// was read from userTerminals[0].serialNumber because that is where the fleet
// plugin found it, and this account's response evidently puts it elsewhere.
// This prints the structure so the mapping is read rather than assumed.
if (in_array('--shape', array_slice($argv, 1), true)) {
    $r = $conn->get('/api/webagg/v2/accounts/service-lines?limit=2&page=0'
                  . '&isConverting=false&onlyActive=false');
    if (empty($r['ok'])) { echo "\n  " . (string)$r['error'] . "\n\n"; exit(1); }

    /** Keys and types, with short scalars shown. Business identifiers, not secrets. */
    $walk = function ($node, string $prefix = '', int $depth = 0) use (&$walk) {
        if ($depth > 4) return;
        foreach ((array)$node as $k => $v) {
            $path = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) {
                // An empty array decodes the same whether it was [] or {}, and
                // printing it as "object" hid the finding that mattered:
                // userTerminals is EMPTY on every line, which is why no serial
                // was found. Say empty when it is empty.
                if ($v === []) { printf("    %-46s %s\n", $path, 'EMPTY'); continue; }

                $isList = array_keys($v) === range(0, count($v) - 1);
                printf("    %-46s %s\n", $path, $isList ? 'list(' . count($v) . ')' : 'object');

                // Only descend into a container. Walking a string yields a
                // phantom child at key 0 — addressLines[0].0 was that.
                $next = $isList ? ($v[0] ?? null) : $v;
                if (is_array($next)) $walk($next, $path . ($isList ? '[0]' : ''), $depth + 1);
                elseif ($isList && $next !== null) {
                    $show = (string)$next;
                    printf("    %-46s %-5s %s\n", $path . '[0]', gettype($next),
                        mb_strlen($show) > 40 ? mb_substr($show, 0, 37) . '...' : $show);
                }
            } elseif (is_bool($v)) {
                printf("    %-46s bool  %s\n", $path, $v ? 'true' : 'false');
            } elseif ($v === null) {
                printf("    %-46s null\n", $path);
            } else {
                $show = (string)$v;
                if (mb_strlen($show) > 40) $show = mb_substr($show, 0, 37) . '...';
                printf("    %-46s %-5s %s\n", $path, gettype($v), $show);
            }
        }
    };

    echo "\n  RESPONSE SHAPE — service-lines\n\n";
    $walk($r['data']);
    echo "\n  Read the kit serial and status field names off this, rather than\n";
    echo "  guessing them a second time.\n\n";
    exit(0);
}

// ── 0b. Info: who the account is, and what it owes ──────────────────────
// The service-line listing says what the account HAS. These say who it is and
// what it is billed for, which is where Phase 2 starts. Read-only, three
// requests, stores nothing.
if (in_array('--info', array_slice($argv, 1), true)) {
    $show = function (string $label, array $r) {
        echo "\n  " . $label . "\n";
        if (empty($r['ok'])) { echo "    " . (string)$r['error'] . "\n"; return null; }
        $d = $r['data'];
        $body = is_array($d) && isset($d['content']) ? $d['content'] : $d;
        echo "    " . json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return $d;
    };

    // Who we are signed in as. The subject id it returns unlocks the next call.
    $sso = $conn->get(StarlinkPortalConnector::SSO_PATH);
    $me  = $show('SSO — signed in as', $sso);

    $subject = '';
    foreach (['subjectId', 'sub', 'userId', 'id'] as $k) {
        if (is_array($me) && isset($me[$k]) && is_string($me[$k]) && $me[$k] !== '') {
            $subject = $me[$k]; break;
        }
    }
    if ($subject !== '') {
        $show('ACCOUNT HOLDER', $conn->get(StarlinkPortalConnector::USER_PATH . rawurlencode($subject)));
    } else {
        echo "\n  ACCOUNT HOLDER\n    no subject id in the SSO response; skipped\n";
    }

    $show('SETTINGS',    $conn->get(StarlinkPortalConnector::SETTINGS_PATH));
    $show('OBLIGATIONS', $conn->get(StarlinkPortalConnector::OBLIGATIONS_PATH));
    echo "\n";
    exit(0);
}

// ── 0. Diagnose: what does Starlink actually say, to each thing we ask? ──
if (in_array('--diagnose', array_slice($argv, 1), true)) {
    $shape = $store->shape();
    printf("  %-22s %d cookies, %d bytes\n", 'session holds',
        count($shape['names']), $shape['total']);
    if (!empty($shape['suspect_truncated'])) {
        echo "  " . str_repeat('!', 64) . "\n";
        echo "  That is ~4096 bytes — where a terminal cuts a pasted line.\n";
        echo "  Every cookie NAME survives truncation; the last VALUE does not.\n";
        echo "  Re-import by piping the cookie in rather than pasting it.\n";
        echo "  " . str_repeat('!', 64) . "\n";
    }
    echo "\n  Raw responses — no refresh, no retry, nothing helping:\n\n";
    printf("  %-6s %-46s %-7s %s\n", 'CODE', 'PATH', 'BYTES', 'SETS COOKIES');
    printf("  %s\n", str_repeat('-', 88));
    // The refresh paths inherited from the fleet plugin answered 404 to a
    // dead session and 401 to a live one, so neither is renewing anything
    // here. Candidates are TRIED rather than assumed, and whichever answers
    // 200 is the one worth wiring in.
    foreach ([
        ['GET',  '/api/accounts/v3/accounts/contact'],
        // The full parameter set. Dropping isConverting/onlyActive was my
        // own malformed request being read as Starlink's fault.
        ['GET',  '/api/webagg/v2/accounts/service-lines?limit=1&page=0'
               . '&isConverting=false&onlyActive=false'],
        ['GET',  '/api/accounts/v1/accounts/service-line-numbers'],
        ['POST', '/api/auth/v1/session/refresh'],
        ['POST', '/api/auth/v1/token/refresh'],
        ['GET',  '/api/auth/v1/session/refresh'],
        ['POST', '/api/auth/v1/refresh'],
        ['GET',  '/auth-rp/auth/user'],
        ['GET',  '/api/auth-rp/auth/user'],
        ['GET',  '/api/auth/v1/session'],
        ['GET',  '/api/auth/v1/user'],
        // The SSO layer answers 200 while the data layer says token_expired,
        // so whatever mints a fresh access token most likely lives here.
        ['GET',  '/auth-rp/auth/refresh'],
        ['POST', '/auth-rp/auth/refresh'],
        ['GET',  '/auth-rp/auth/token'],
        ['GET',  '/auth-rp/auth/session'],
        ['GET',  '/auth-rp/auth/login'],
        ['GET',  '/auth-rp/auth/authorize'],
        ['GET',  '/auth-rp/signin-oidc'],
        ['GET',  '/api/auth/v1/access-token'],
    ] as [$m, $path]) {
        $d = $conn->raw($m, $path);
        $sets = $d['sets'] === [] ? '—' : implode(',', $d['sets']);
        printf("  %-6s %-46s %-7d %s\n", $d['code'] ?: ($d['error'] !== '' ? 'ERR' : '0'),
            $m . ' ' . substr($path, 0, 42), $d['bytes'], substr($sets, 0, 30));
        if ($d['location'] !== '') echo "         → " . substr($d['location'], 0, 90) . "\n";
        if ($d['error'] !== '')    echo "         " . $d['error'] . "\n";
        if ($d['snippet'] !== '' && $d['code'] !== 200) echo "         " . $d['snippet'] . "\n";
    }
    echo "\n  401/403 everywhere means the cookie is not accepted — truncated,\n";
    echo "  expired, from a different account, or REVOKED because somebody\n";
    echo "  signed out of starlink.com. The imported cookie is the browser's\n";
    echo "  own session, so signing out there kills it here.\n";
    echo "  200 on contact but 401 elsewhere means the session is real and a\n";
    echo "  second auth layer is refusing — a different problem entirely.\n";
    echo "  404 on a refresh path means that endpoint is not there any more.\n";
    echo "\n  What matters most in the SETS COOKIES column: any response that\n";
    echo "  sets Starlink.Com.Access.V1 has just minted a fresh access token,\n";
    echo "  whatever its status code. That is the endpoint worth wiring in.\n\n";
    exit(0);
}

// ── 1. Does Starlink still accept us? ────────────────────────────────────
$v = $conn->verify();
printf("  %-22s %s\n", 'session accepted', !empty($v['ok']) ? 'YES' : 'NO');
if (empty($v['ok'])) {
    echo "  " . str_repeat('─', 64) . "\n\n";
    echo "  " . (string)$v['error'] . "\n\n";
    $s = $store->status();
    if ($s['state'] === StarlinkSessionStore::STATE_DEAD) {
        echo "  The session is dead. Sign in again and import a fresh cookie:\n\n";
        echo "    docker exec -it ucrm php tools/starlink_session.php --import\n\n";
    }
    echo "  To see what Starlink said to each request, with nothing helping:\n\n";
    echo "    php tools/starlink_probe.php --diagnose\n\n";
    exit(1);
}
printf("  %-22s %s\n", 'identified as', (string)$v['detail']);

if (in_array('--verify', array_slice($argv, 1), true)) {
    echo "  " . str_repeat('─', 64) . "\n\n";
    exit(0);
}

// ── 2. What is on the account? ───────────────────────────────────────────
$r = $conn->get(StarlinkPortalConnector::LINES_PATH);
if (empty($r['ok']) && (int)$r['code'] >= 500) {
    // The rich endpoint is having a bad minute. The light one answered 200 in
    // the same run where this 500ed, and it still says which lines exist.
    echo "  " . str_repeat('─', 64) . "\n";
    echo "\n  service-lines returned " . (int)$r['code'] . " — their side. Falling back\n";
    echo "  to the lighter endpoint, which answered while it did not.\n";
    $light = $conn->get(StarlinkPortalConnector::LINES_LIGHT_PATH);
    if (!empty($light['ok'])) {
        // content is the ENVELOPE — pageIndex, limit, isLastPage, results.
        // Reading it instead of its results printed "0, 50, true" as though
        // they were service lines.
        $nums = $light['data']['content']['results']
             ?? $light['data']['results']
             ?? [];
        $nums = is_array($nums) ? array_values(array_filter($nums, 'is_string')) : [];
        echo "\n  " . count($nums) . " service line number(s):\n\n";
        foreach (array_slice($nums, 0, 50) as $n) {
            echo '    ' . (is_string($n) ? $n : json_encode($n)) . "\n";
        }
        echo "\n  The account is fine. Try the full call again in a minute.\n\n";
        exit(0);
    }
}
if (empty($r['ok'])) {
    echo "  " . str_repeat('─', 64) . "\n\n";
    echo "  The session works, but the service-line call did not: "
       . (string)$r['error'] . "\n";
    echo "  " . (!empty($r['retryable'])
        ? "That is worth one retry — it is a wait, not a wrong answer.\n\n"
        : "That is not a retry; something about the request is wrong.\n\n");
    exit(1);
}

$rows = $r['data']['content']['results'] ?? $r['data']['results'] ?? [];
if (!is_array($rows)) $rows = [];

// Every service line carries the account it belongs to. Learning it here
// saves an operator typing what the session already knows.
foreach ($rows as $sl) {
    if (is_array($sl) && trim((string)($sl['accountReferenceId'] ?? '')) !== '') {
        $store->rememberAccount('', (string)$sl['accountReferenceId']);
        break;
    }
}
printf("  %-22s %d\n", 'service lines', count($rows));
echo "  " . str_repeat('─', 64) . "\n";

if ($rows === []) {
    echo "\n  The account answered, with no service lines on it. That is a real\n";
    echo "  answer — worth checking it is the account you meant.\n\n";
    exit(0);
}

printf("\n  %-24s %-18s %-9s %-22s %s\n",
    'SERVICE LINE', 'DISH', 'STATE', 'PLAN', 'ADDRESS');
printf("  %s\n", str_repeat('-', 100));
foreach (array_slice($rows, 0, 50) as $sl) {
    if (!is_array($sl)) continue;
    // The kit serial rides inside the service line rather than arriving from
    // an endpoint of its own — the discovery that makes KitSync a consumer of
    // this call instead of a separate fetch.
    // Where the serial lives when there IS one. On this account every
    // service line has an empty userTerminals, because none has a dish
    // attached yet — pendingActivation is true on all ten. An em-dash here is
    // the truth, not a lookup that failed.
    $serial = '';
    foreach ([$sl['userTerminals'][0]['serialNumber'] ?? null,
              $sl['userTerminals'][0]['kitSerialNumber'] ?? null,
              $sl['userTerminal']['serialNumber']    ?? null,
              $sl['userTerminalSerialNumber']        ?? null,
              $sl['kitSerialNumber']                 ?? null] as $cand) {
        if (is_string($cand) && trim($cand) !== '') { $serial = trim($cand); break; }
    }

    // The subscription says in words what `status: 7` says in a code nobody
    // has decoded. Prefer the words.
    $sub    = is_array($sl['subscription'] ?? null) ? $sl['subscription'] : [];
    $state  = '—';
    if (!empty($sub['isSuspended']))            $state = 'suspended';
    elseif (!empty($sub['isPaused']))           $state = 'paused';
    elseif (!empty($sub['pendingActivation']))  $state = 'pending';
    elseif (!empty($sub['active']))             $state = 'active';
    elseif (array_key_exists('active', $sub))   $state = 'inactive';

    // Identifiers are printed WHOLE. A service line number cut to the column
    // width — SL-DF-15784590-46113-10 shown as ...-1 — is still a plausible
    // identifier, so nobody notices it is wrong until they use it. Descriptive
    // text may be trimmed; a thing you could paste into a search box may not.
    printf("  %-24s %-18s %-9s %-22s %s\n",
        (string)($sl['serviceLineNumber'] ?? ''),
        $serial !== '' ? $serial : 'no dish yet',
        $state,
        substr((string)($sub['productDescription'] ?? ''), 0, 22),
        substr((string)($sl['serviceAddress']['formattedAddress'] ?? ''), 0, 30));
}

$pending = 0;
foreach ($rows as $sl) {
    if (is_array($sl) && !empty($sl['subscription']['pendingActivation'])) $pending++;
}
if ($pending > 0) {
    echo "\n  {$pending} of these have no dish attached yet (pendingActivation).\n";
    echo "  An empty DISH column there is the account's real state, not a\n";
    echo "  lookup that failed — the serial appears once a terminal is\n";
    echo "  assigned to the line.\n";
}

echo "\n  Nothing was stored. Phase 2 is what starts keeping it.\n\n";
exit(0);

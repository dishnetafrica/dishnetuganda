<?php
declare(strict_types=1);
/**
 * Shared by section E of tests/test_catalogue_direct.php (5.18.103, root docs/72 §G.9, G-14, G-15): Send Quotation and
 * the lead quotes with the uCRM catalogue. TEST ONLY.
 *
 * It runs on 5.18.102's own section F machinery (tests/quote_from_ucrm/f_lib.php, which loads
 * tests/fixtures/quote_company_journey.php): the fake uCRM of the quoting organization and the fake Evolution
 * (qfu_servers()), a book per scenario (qcj_book()), every step in a process of its own on a copy of a plugin tree, the
 * POST handlers included as public.php includes them (qcj_post()), and the store read back straight from SQLite
 * (qcj_tables()). What this file adds:
 *
 *   cde_trees()    two writable trees: "new", a copy of the tree judged (--root, a weakened copy included), and "old",
 *                  5.18.102's live commit 1a0ea5f taken from Git (git archive, never the working tree); null when Git
 *                  cannot supply it, and the caller fails — never skips.
 *   cde_book()     a book holding the direct-mode catalogue of _lib.php (plus a product whose name carries markup), two
 *                  clients, seven leads, 5.18.102's link store, and — unless null — the uCRM catalogue's records.
 *   cde_page() / cde_post() / cde_build() / cde_api()
 *                  the entry points staff use: the Send Quotation page, the two POST handlers of
 *                  includes/post/post_leads.php, buildLeadItems(), and the mobile API's two blocks read from the tree's own
 *                  api/index.php (never re-typed) and run as that file runs them.
 *   cde_measure()  one step, and what it left: the uCRM requests and bodies, the WhatsApp texts, the quotes_log rows and
 *                  the notification events it added, and whether the page cache was there before and after.
 *   cde_browser()  pages drawn here, driven in headless Chromium by e_send_quote_browser.js.
 *
 * Every person, number, address and price is fictitious (TEST names, example.test, +256 700 000 1xx and 2xx); nothing
 * leaves the machine. No time zone is named here: a book is made in the runner's QFU_TZ.
 */
require_once dirname(__DIR__) . '/quote_from_ucrm/f_lib.php';   // qfu_servers(), qfu_norm(), qfu_first_diff(); qcj_*

/** 5.18.102's live commit, the comparison for everything the switch off and South Sudan draw and send. */
const CDE_BASE = '1a0ea5f';
/** The page cache forPage() keeps (UcrmQuoteLines::CACHE_FILE) and its last good copy (LAST_GOOD_FILE). */
const CDE_CACHE     = 'ucrm_quote_catalogue_cache.json';
const CDE_LAST_GOOD = 'ucrm_quote_catalogue_last_good.json';
/** A product whose uCRM name carries markup: offered as hardware, so the page must escape it. */
const CDE_MARKUP    = 91;

/** @return array{new:string,old:?string} */
function cde_trees(QfuCtx $c): array
{
    static $memo = [];
    if (isset($memo[$c->root])) return $memo[$c->root];
    $base = $c->dir('e-trees');
    // Both trees carry the plugin's own directory name: a page prints it.
    $new = $base . '/new/dishnet-hybrid-sudan';
    qcj_copy_tree($c->root, $new);
    $old = null;
    // The sections are always loaded from this checkout (the runner judges a copy with --root), so Git is asked here.
    $top = trim((string)shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --show-toplevel 2>/dev/null'));
    if ($top !== '') {
        $x = $base . '/archive';
        @mkdir($x, 0700, true);
        exec('git -C ' . escapeshellarg($top) . ' archive ' . CDE_BASE . ' dishnet-hybrid-sudan | tar -x -C ' . escapeshellarg($x) . ' 2>/dev/null');
        if (is_file($x . '/dishnet-hybrid-sudan/tabs/sales/send_quote.php') && !is_file($x . '/dishnet-hybrid-sudan/lib/UcrmCatalogue.php')) {
            $old = $base . '/old/dishnet-hybrid-sudan';
            qcj_copy_tree($x . '/dishnet-hybrid-sudan', $old);
        }
        exec('rm -rf ' . escapeshellarg($x));
    }
    return $memo[$c->root] = ['new' => $new, 'old' => $old];
}

/** The products a book's uCRM serves: _lib.php's direct-mode catalogue, and the markup product (2000, Pc). */
function cde_products(array $over = []): array
{
    return cd_products($over + [CDE_MARKUP => ['id' => CDE_MARKUP, 'name' => 'Kit <b>Pro</b> & "Co" (TEST)', 'price' => 2000,
                                               'unit' => 'Pc', 'taxable' => false]]);
}

/**
 * 5.18.102's link store (docs/71 §R.1.1), as a 5.18.102 book holds it: the plan row "TEST Plan" → product 12 with service
 * plan 6, the kit row → product 55, the installation → product 70. Direct mode never reads it; the switch off prices
 * through it.
 */
function cde_links(): array
{
    return [qcj_link(1, 'plan', 1, QCJ_PLAN_STAMP, 12, CD_PLAN_RES), qcj_link(2, 'kit', 3, QCJ_KIT_STAMP, CD_KIT),
            qcj_link(3, 'install', 0, '', CD_INSTALL)];
}

/** The leads of a book, each open, each with its own fictitious number; $agent is the book's agent. */
function cde_leads(int $agent): array
{
    $l = function (int $id, string $last, int $crm, string $interest) use ($agent): array {
        return ['id' => $id, 'customer_name' => 'TEST Lead' . $last, 'phone' => '+2567000002' . (10 + $id), 'crm_client_id' => $crm,
                'service_type' => 'starlink', 'interest_plan' => $interest, 'status' => 'open', 'retailer_id' => $agent,
                'notes' => 'INTERNAL TEST lead note — never to the customer'];
    };
    return [
        $l(1, 'one', 16, 'Residential Standard (TEST)'),   // keyed exactly like the offered plan 6 / product 12
        $l(2, 'two', 0, 'residential-standard test'),      // spelled otherwise, the same key
        $l(3, 'three', 0, 'Lonely Plan (TEST)'),           // a uCRM service plan that is not offered (no product)
        $l(4, 'four', 0, ''),                              // names no plan
        $l(5, 'five', 0, 'TEST Plan'),                     // the plugin's plan row, linked in 5.18.102 — no uCRM plan
        $l(6, 'six', 0, 'Plus (TEST)'),                    // contained in "Business Plus (TEST)", never its key
        $l(7, 'seven', 0, 'Business Plus (TEST)'),         // keyed like the offered plan 7 / product 17
    ];
}

/**
 * A book on $tree for section E. $o: tenant (uganda|south-sudan, default uganda), records (the uCRM catalogue's records;
 * null or absent: no table at all), products, plans (what uCRM serves; cde_products() and cd_plans() by default), down
 * (uCRM's products answer 503). The fake uCRM is reset and its log emptied, the fake Evolution reset.
 */
function cde_book(QfuCtx $c, string $tree, array $o = []): array
{
    $srv  = qfu_servers($c);
    $fake = $srv['fake'];
    $evo  = $srv['evo'];
    if ($fake === '' || $evo === '') throw new \RuntimeException('the fake uCRM or the fake Evolution did not start');
    $orgs = qcj_orgs([1, 2]);
    foreach ($orgs as $i => $org) $orgs[$i]['selected'] = ((int)$org['id'] === 2);
    qcj_fake($fake, '/__test/reset');
    qcj_fake($fake, '/__test/set', [
        'organizations' => $orgs, 'clients' => [qcj_client(16, 1, 'Leadone'), qcj_client(17, 1, 'Manual')],
        'products' => $o['products'] ?? cde_products(), 'service_plans' => $o['plans'] ?? cd_plans(),
        'products_down' => !empty($o['down']), 'log' => [],
    ]);
    qcj_http('GET', "{$evo}/__test/reset");
    $ctx = qcj_book(['tree' => $tree, 'dir' => $c->dir('e-book'), 'fake' => $fake, 'evo' => $evo, 'tenant' => $o['tenant'] ?? 'uganda',
                     'timezone' => QFU_TZ, 'links' => cde_links(),
                     'cfg' => ['kyc_quote_send_via_crm' => '1', 'kyc_quote_notes_prefix' => 'TEST prefix for customers']]);
    $r = qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        $s = SqliteStore::create($DATA);
        $s->save("leads.json", $ARGS["leads"]);
        if (is_array($ARGS["records"])) $s->save("ucrm_catalogue.json", $ARGS["records"]);
        qcj_out(true);', ['leads' => cde_leads((int)$ctx['agent']), 'records' => $o['records'] ?? null]);
    if (($r['out'] ?? null) !== true) throw new \RuntimeException('cde_book: ' . substr((string)$r['raw'], 0, 600));
    return $ctx;
}

/** The prelude every probe below runs: the store, the configuration and the caller (retailers.json, by id). */
const CDE_LIBS = '
require_once "$TREE/lib/currency.php";
require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
$dataDir = $DATA; $_PLUGIN_ROOT = $TREE;
$store  = SqliteStore::create($dataDir);
$config = $store->load("kyc_config.json") ?: [];
$rbac   = null;
$__who  = $store->findOne("retailers.json", "id", (int)$ARGS["who"]) ?? [];
';

/** The Send Quotation page (tabs/sales/send_quote.php) as the dashboard draws it for the book's agent, for $_GET. */
function cde_page(array $ctx, array $get): string
{
    $r = qcj_php($ctx, CDE_LIBS . '
function csrfField(): string { return \'<input type="hidden" name="_csrf" value="TEST-CSRF">\'; }
$retailer = $__who; $isAdmin = !empty($retailer["is_admin"]); $userRole = (string)($retailer["role"] ?? "");
$_GET = $ARGS["get"];
ob_start(); include "$TREE/tabs/sales/send_quote.php"; $html = ob_get_clean();
qcj_out(["html" => $html]);', ['who' => (int)$ctx['agent'], 'get' => $get]);
    return is_array($r['out']) ? (string)$r['out']['html'] : ('NO OUTPUT: ' . substr((string)$r['raw'], 0, 2000));
}

/** One of includes/post/post_leads.php's handlers, as public.php runs it for the book's agent: the flashes and where it sent the browser. */
function cde_post(array $ctx, array $fields): array
{
    $r = qcj_post($ctx, 'post_leads.php', array_filter($fields, function ($v) { return $v !== null; }), (int)$ctx['agent']);
    $raw = (string)($r['_raw'] ?? '');
    unset($r['_raw']);
    return $r + ['raw' => (isset($r['flash'][0]) ? '' : substr($raw, 0, 1500))];
}

/** QuotationService::buildLeadItems() for a lead of the book, as the lead quote calls it. */
function cde_build(array $ctx, int $leadId)
{
    $r = qcj_php($ctx, CDE_LIBS . '
require_once "$TREE/lib/QuotationService.php";
$q = new QuotationService($store, $DATA, $config);
qcj_out($q->buildLeadItems($store->findOne("leads.json", "id", (int)$ARGS["lead"])));', ['who' => (int)$ctx['agent'], 'lead' => $leadId]);
    return $r['out'] ?? ['raw' => substr((string)$r['raw'], 0, 2000)];
}

/**
 * The mobile API's send_lead_quote or send_manual_quote, with a body the app posts: the two blocks of the tree's own
 * api/index.php, read from that file (never re-typed), run as that file runs them — its QuotationService, its $body, the
 * API guard's record of the book's agent as $retailer — with apiOk()/apiError() answering. ['ok', 'code', 'data'|'error'];
 * 'fell' when the block answered nothing.
 */
function cde_api(array $ctx, string $action, array $body): array
{
    $r = qcj_php($ctx, CDE_LIBS . '
final class CdeApiAnswer extends \Exception { public $answer; public function __construct(array $a) { parent::__construct("answer"); $this->answer = $a; } }
function apiOk($d = [], string $m = "OK"): void { throw new CdeApiAnswer(["ok" => true, "code" => 200, "data" => $d]); }
function apiError(string $m, int $c = 400): void { throw new CdeApiAnswer(["ok" => false, "code" => $c, "error" => $m]); }
$__src = (string)file_get_contents("$TREE/api/index.php");
$__code = "";
foreach ($ARGS["anchors"] as $__ab) {
    $__a = strpos($__src, $__ab[0]);
    $__b = $__a === false ? false : strpos($__src, $__ab[1], $__a);
    if ($__a === false || $__b === false) { qcj_out(["ok" => null, "error" => "the API block is not in api/index.php: " . $__ab[0]]); return; }
    $__code .= substr($__src, $__a, $__b - $__a) . "\n";
}
$__dir = "$TREE/api";
$__code = str_replace("__DIR__", var_export($__dir, true), $__code);
require_once "$TREE/lib/QuotationService.php";
$action = $ARGS["action"]; $method = "POST"; $body = $ARGS["body"]; $retailer = $__who;
try { eval($__code); qcj_out(["ok" => null, "fell" => true]); } catch (CdeApiAnswer $__e) { qcj_out($__e->answer); }',
        ['who' => (int)$ctx['agent'], 'action' => $action, 'body' => $body, 'anchors' => [
            ["if (\$action === 'send_lead_quote' && \$method === 'POST') {", "// ── POST send_cash_proforma"],
            ["if (\$action === 'send_manual_quote' && \$method === 'POST') {", "// ── GET quote_logs"],
        ]]);
    return is_array($r['out']) ? $r['out'] : ['ok' => null, 'error' => 'NO OUTPUT: ' . substr((string)$r['raw'], 0, 1500)];
}

/**
 * One step on a book, and what it left: requests ("METHOD /path?query"), bodies (every non-GET request's), texts (the
 * WhatsApp texts the fake Evolution received), quotes (the quotes_log rows added), events (the notification events
 * added, by key), cache_before / cache_after (the page cache in the data directory).
 */
function cde_measure(array $ctx, callable $fn): array
{
    $cache = $ctx['data'] . '/' . CDE_CACHE;
    $mark  = count(qcj_fake_log($ctx['fake']));
    $t0    = count(qcj_evo_texts($ctx['evo']));
    $tb    = qcj_tables($ctx['data'], ['quotes_log', 'notification_audit_log']);
    $q0    = count((array)($tb['quotes_log'] ?? []));
    $e0    = count((array)($tb['notification_audit_log'] ?? []));
    $had   = is_file($cache);
    $res   = $fn();
    $log   = qcj_fake_log($ctx['fake']);
    $ta    = qcj_tables($ctx['data'], ['quotes_log', 'notification_audit_log']);
    return [
        'result'   => $res,
        'requests' => array_map(function ($r) { return preg_replace('#^(PATCH /billing/quotes/)\d+(/send)$#', '$1N$2', $r); }, qcj_reqs($log, $mark)),
        'bodies'   => array_values(array_map(function ($e) { return $e['body'] ?? null; },
                          array_filter(array_slice($log, $mark), function ($e) { return ($e['method'] ?? '') !== 'GET'; }))),
        'texts'    => array_map(function ($t) { return ['number' => (string)($t['number'] ?? ''), 'text' => (string)($t['text'] ?? '')]; },
                          array_slice(qcj_evo_texts($ctx['evo']), $t0)),
        'quotes'   => array_values(array_slice((array)($ta['quotes_log'] ?? []), $q0)),
        'events'   => array_values(array_map(function ($e) { return $e['event'] ?? null; }, array_slice((array)($ta['notification_audit_log'] ?? []), $e0))),
        'cache_before' => $had,
        'cache_after'  => is_file($cache),
    ];
}

/** The JSON a page assigns to `const NAME = …;` (one line), decoded — null when absent. */
function cde_const(string $html, string $name)
{
    if (!preg_match('/^const ' . preg_quote($name, '/') . '\s*=\s*(.*);$/m', $html, $m)) return null;
    return json_decode($m[1], true);
}

/** The options of the page's <select>s whose first option is $first: [[value, text, disabled]], in order. */
function cde_options(string $html, string $first): array
{
    $out = [];
    $at = 0;
    while (($a = strpos($html, '<option value="">' . $first . '</option>', $at)) !== false) {
        $b = strpos($html, '</select>', $a);
        if ($b === false) break;
        preg_match_all('#<option value="([^"]*)"( disabled)?>(.*?)</option>#s', substr($html, $a, $b - $a), $m, PREG_SET_ORDER);
        $rows = [];
        foreach ($m as $x) if ($x[1] !== '' || $x[2] !== '') $rows[] = [$x[1], $x[3], $x[2] !== ''];
        $out[] = $rows;
        $at = $b;
    }
    return $out;
}

/** A value with every array's keys sorted, recursively — for comparing lines whose key order is the browser's. */
function cde_sorted($v)
{
    if (!is_array($v)) return $v;
    foreach ($v as $k => $x) $v[$k] = cde_sorted($x);
    if (array_keys($v) !== range(0, count($v) - 1)) ksort($v);
    return $v;
}

/**
 * Pages driven in headless Chromium (e_send_quote_browser.js): each page an HTML file drawn here and a list of JavaScript
 * expressions evaluated in it in turn, after its own script ran. Returns ['ran' => bool, 'why' => …, 'pages' => [[results,
 * errors, dialogs]]]; 'ran' false (with why) when node or Playwright is not available here — the caller states the
 * limitation, never a pass.
 */
function cde_browser(QfuCtx $c, array $pages): array
{
    $node = trim((string)shell_exec('command -v node 2>/dev/null'));
    $nodePath = trim((string)(getenv('NODE_PATH') ?: ''));
    foreach (['/opt/node22/lib/node_modules', '/usr/lib/node_modules', '/usr/local/lib/node_modules'] as $cand) {
        if (is_dir($cand . '/playwright')) { $nodePath = $nodePath === '' ? $cand : $nodePath . ':' . $cand; break; }
    }
    if ($node === '') return ['ran' => false, 'why' => 'node is not available here'];
    exec('NODE_PATH=' . escapeshellarg($nodePath) . ' ' . escapeshellarg($node) . " -e \"require('playwright')\" 2>/dev/null", $po, $prc);
    if ($prc !== 0) return ['ran' => false, 'why' => 'Playwright is not available here (node: ' . $node . ')'];
    $dir = $c->dir('e-browser');
    $job = ['pages' => []];
    foreach ($pages as $i => $p) {
        file_put_contents("{$dir}/page{$i}.html", "<!doctype html>\n<html><head><meta charset=\"utf-8\"></head><body>\n" . $p['html'] . "\n</body></html>\n");
        $job['pages'][] = ['file' => "{$dir}/page{$i}.html", 'steps' => array_values($p['steps'])];
    }
    file_put_contents("{$dir}/job.json", json_encode($job));
    $out = [];
    exec('cd ' . escapeshellarg($dir) . ' && CDE_JOB=' . escapeshellarg("{$dir}/job.json") . ' NODE_PATH=' . escapeshellarg($nodePath)
        . ' timeout 180 ' . escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/e_send_quote_browser.js') . ' 2>&1', $out, $rc);
    $all = implode("\n", $out);
    $pos = strrpos($all, '@@CDE@@');
    $res = $pos === false ? null : json_decode(substr($all, $pos + 7), true);
    if (!is_array($res)) return ['ran' => true, 'error' => 'no answer (exit ' . $rc . '): ' . substr($all, -1500), 'pages' => []];
    return ['ran' => true, 'error' => $res['error'] ?? null, 'pages' => (array)($res['pages'] ?? [])];
}

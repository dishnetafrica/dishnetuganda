<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * crm_audit.php — what the lead and quotation machinery actually looks like.
 *
 *   php tools/crm_audit.php
 *
 * READ-ONLY. It opens no write path, and the test asserts row counts are
 * unchanged after it runs.
 *
 * Four questions have to be answered before an AI is allowed to create leads,
 * and none of them can be answered from the code — they depend on what is in
 * this install:
 *
 *   1. Do quotes carry Uganda's contact details, or the South Sudan defaults
 *      still compiled into QuotationService?
 *   2. Is there an active sales agent to assign a lead to? Without one,
 *      auto-assignment silently assigns to nobody and the AI would create
 *      leads no human is ever told about.
 *   3. How big is leads.json? It is loaded whole and rewritten on every
 *      change, so an AI writing on every qualified conversation changes what
 *      that costs.
 *   4. Are there already duplicates by phone, and is web_chat_leads.json
 *      still live? Three lead stores that do not reconcile is the reason not
 *      to add a fourth.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$store   = SqliteStore::create($dataDir);

$last9 = fn(string $p): string => substr(preg_replace('/\D+/', '', $p) ?? '', -9);
$warn  = [];

// ── 1. Whose phone number is on a quotation? ────────────────────────────────
echo "\n  1. QUOTATION BRANDING\n\n";
// The constants in QuotationService default to Juba. They are overridable, and
// whether this install overrode them is not knowable from the code.
$brand = [
    'quote_company_name'  => ['DishNet Africa',            'company name on the quote'],
    'quote_company_phone' => ['+211920000000',             'phone customers are told to call'],
    'quote_company_email' => ['info@dishnetafrica.com',    'reply address on the quote'],
];
foreach ($brand as $k => [$fallback, $what]) {
    $set = trim((string)($config[$k] ?? ''));
    $eff = $set !== '' ? $set : $fallback;
    printf("     %-22s %s\n", $k, $eff);
    printf("     %-22s %s%s\n\n", '', $what,
        $set === '' ? '  ← NOT SET, using the built-in South Sudan default' : '');
    if ($set === '') $warn[] = "{$k} is unset — quotes carry \"{$fallback}\"";
}

// ── 2. Is there anyone to assign a lead to? ─────────────────────────────────
echo "  2. SALES AGENTS AVAILABLE FOR ASSIGNMENT\n\n";
$SALES_ROLES = ['sales', 'field_agent', 'sales_staff'];
$retailers = [];
try { $retailers = $store->load('retailers.json') ?? []; } catch (\Throwable $e) {}
$agents = array_values(array_filter($retailers,
    fn($r) => !empty($r['is_active']) && in_array($r['role'] ?? '', $SALES_ROLES, true)));
if (!$agents) {
    echo "     none\n";
    echo "     Auto-assignment picks the lightest-loaded active agent in roles\n";
    echo "     " . implode('/', $SALES_ROLES) . ". With none, a new lead is assigned to nobody.\n\n";
    $warn[] = 'no active sales agent — AI-created leads would reach no one';
} else {
    foreach ($agents as $a) printf("     %-24s %s\n", (string)($a['name'] ?? '?'), (string)($a['role'] ?? '?'));
    printf("\n     %d agent(s) available\n\n", count($agents));
}

// ── 3 & 4. The lead stores ──────────────────────────────────────────────────
echo "  3. leads.json\n\n";
$leads = [];
try { $leads = $store->load('leads.json') ?? []; } catch (\Throwable $e) {}
$byStatus = []; $noPhone = 0; $seen = []; $dupes = [];
foreach ($leads as $l) {
    $st = (string)($l['status'] ?? 'unknown');
    $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
    $p = $last9((string)($l['phone'] ?? ''));
    if ($p === '') { $noPhone++; continue; }
    if (isset($seen[$p])) $dupes[$p] = ($dupes[$p] ?? 1) + 1; else $seen[$p] = true;
}
printf("     %-22s %d\n", 'rows', count($leads));
printf("     %-22s %s\n", 'raw size', number_format(strlen(json_encode($leads)) / 1024, 1) . ' KB');
printf("     %-22s %d\n", 'without a phone', $noPhone);
printf("     %-22s %d\n", 'duplicate phones', count($dupes));
echo "\n     by status:\n";
arsort($byStatus);
foreach ($byStatus as $st => $n) printf("       %-20s %d\n", $st, $n);
echo "\n     leads.json is loaded whole and rewritten on every change. An AI writing\n";
echo "     on each qualified conversation adds to that on every message.\n\n";
if (count($leads) > 2000) $warn[] = count($leads) . ' leads — whole-file rewrites are getting expensive';
if ($dupes) $warn[] = count($dupes) . ' phone number(s) already appear on more than one lead';

echo "  4. THE OTHER TWO LEAD STORES\n\n";
$wc = [];
try { $wc = $store->load('web_chat_leads.json') ?? []; } catch (\Throwable $e) {}
$wcLatest = '';
foreach ($wc as $w) {
    $t = (string)($w['created_at'] ?? ($w['updated_at'] ?? ''));
    if ($t > $wcLatest) $wcLatest = $t;
}
printf("     %-22s %d rows%s\n", 'web_chat_leads.json', count($wc),
       $wcLatest !== '' ? '   last: ' . $wcLatest : '');
echo "     keyed by session, not phone — it cannot be joined to leads.json as it stands\n\n";

// uCRM's own lead flag is a third notion; counting it needs an API round trip,
// so it is named rather than queried. This tool stays local and read-only.
echo "     uCRM isLead clients      a third notion, set by KycService on payment\n";
echo "     Three stores, no join. That is the reason not to add a fourth.\n\n";

// ── How much of the chain is already wired ──────────────────────────────────
echo "  5. CONVERSATIONS ALREADY LINKED TO A LEAD\n\n";
try {
    $pdo = $store->getPdo();
    $tot  = (int)$pdo->query("SELECT COUNT(*) FROM wa_conversations")->fetchColumn();
    $with = (int)$pdo->query("SELECT COUNT(*) FROM wa_conversations WHERE lead_id IS NOT NULL")->fetchColumn();
    printf("     %-22s %d of %d\n", 'linked', $with, $tot);
    echo "     The lead_id column and the manual \"convert from WA Inbox\" path already\n";
    echo "     exist. Phase 1 is that same path, called by the assistant.\n\n";
} catch (\Throwable $e) {
    echo "     could not read: " . $e->getMessage() . "\n\n";
}

if ($warn) {
    echo "  BEFORE LETTING THE AI CREATE LEADS\n\n";
    foreach ($warn as $w) echo "     - " . $w . "\n";
    echo "\n";
    exit(1);
}
echo "  Nothing blocking.\n\n";
exit(0);

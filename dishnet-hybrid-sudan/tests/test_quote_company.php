<?php
declare(strict_types=1);
/**
 * test_quote_company.php — 5.18.99 (SPEC-quote-company, revision 2): Uganda app customers and app quotes on one company.
 *
 * The operator, 20 Oct 2026: "always company 1". Every new app customer is filed under organization 1; a customer filed
 * under another organization is not quoted by the app, and the salesperson is told to move them first. South Sudan, and
 * any book where the gate is off, must send byte-identical requests and produce byte-identical results, WhatsApp texts,
 * PDF HTML, pages and store contents to ce7ac388 (5.18.98, live).
 *
 *   A  the gate: QuoteOrganization::setting() — tenant AND setting, read as QuoteTaxLine::applies reads the tenant
 *   B  item 1: the KYC path files a new client under the configured organization (UcrmClientTarget, KycService, the retry)
 *   C  item 2: the check before every Uganda app quote (KYC existing, new and retry; Lead and Manual, ticked or not; the
 *      debug retry; LTE), its failure modes, the client it reads with, its texts, its log and its request counts
 *   D  item 3: UcrmLeadSync falls back to the configured organization when ucrm_lead_organization_id is unset
 *   E  item 4: the WhatsApp quotation names the client's own organization; the Uganda profile is the last fallback
 *   F  item 5: the plugin's fallback quote PDF on Uganda reads the organization's real fields and prints nothing of Juba
 *   G  item 6: push_products_to_ucrm ("Sync All") is admin-only on every tenant
 *   H  item 7: a failed uCRM create on Send Quotation is shown to the salesperson (F7a / F7k / F7u)
 *   I  inventory and form: every quote create guarded, the profiles, tools/set_config.php, the declared hunks, no
 *      migration, PHP 7.4 syntax, each file's own require_once, every client create on the allow-list
 *   S  byte-identical: the journey J1–J14 (fixtures/quote_company_journey.php) on ce7ac388's tree and on this one, for
 *      South Sudan, South Sudan with the key set, and Uganda switched off (0 and off), after an explicit normaliser that
 *      a baseline self-comparison completes and checks; the gate-on run differs where it should
 *
 * Every section starts with its positive control. The weakened copies are tests/test_quote_company_mutants.php.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_quote_company.php [--root=DIR] [--base=DIR] [--sections=REGEX] [--no-mutants]
 *
 * --root: the plugin tree judged (default: this checkout's). --base: a ce7ac388 tree (default: from Git); without one,
 * the comparisons that need it FAIL — never a skip. --sections: run the sections whose letter matches. --no-mutants:
 * skip the weakened copies this file makes itself (the normaliser's control in §S).
 *
 * Every person, number, address, organization and amount is invented (TEST names, example.test, +2567000001xx).
 * The servers this test starts answer with a token only it knows, and only those are stopped. Nothing leaves the machine.
 */

require_once __DIR__ . '/fixtures/quote_company_journey.php';
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';   // sj_baseline_tree(), SjSandbox::parseMail()

$opt = getopt('', ['root:', 'base:', 'sections:', 'no-mutants']);
$LIB  = dirname(__DIR__);
$ROOT = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : $LIB;
$SECTIONS = isset($opt['sections']) ? (string)$opt['sections'] : '';
$WITH_MUTANTS = !isset($opt['no-mutants']);
const QC_BASE_COMMIT = 'ce7ac388';
// The zone each book runs in: a test file pins it, the fixture never does (tests/test_timezone.php). '' is the
// vault-only Uganda book, which names no tenant in its configuration.
const QC_TZ = ['uganda' => 'Africa/Kampala', 'south-sudan' => 'Africa/Juba', '' => 'Africa/Kampala'];

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void
{
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . str_replace("\n", "\n       ", $d) : '') . "\n"; }
}
function t(string $m, $got, $want): void
{
    $j = function ($x) { return (string)json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); };
    is_($got === $want, $m, 'got  ' . mb_substr($j($got), 0, 1500) . "\nwant " . mb_substr($j($want), 0, 1500));
}
function qc_done(): void
{
    global $pass, $fail;
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail ? 1 : 0);
}
function qc_section(string $letter): bool
{
    global $SECTIONS;
    return $SECTIONS === '' || preg_match('#' . str_replace('#', '\\#', $SECTIONS) . '#', $letter) === 1;
}
/** The key is there and holds null (?? cannot tell null from absent). */
function qc_isnull($a, string $k): bool { return is_array($a) && array_key_exists($k, $a) && $a[$k] === null; }
function qc_j($x): string { return (string)json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); }

// ── Where this run works ───────────────────────────────────────────────────────────────────────────────────────────
$TMP = sys_get_temp_dir() . '/dn_quote_company_' . getmypid() . '_' . bin2hex(random_bytes(3));
@mkdir($TMP, 0700, true);
$PROCS = [];
register_shutdown_function(function () use (&$PROCS, $TMP) {
    foreach ($PROCS as $p) qcj_stop($p);
    exec('rm -rf ' . escapeshellarg($TMP) . ' ' . escapeshellarg(sys_get_temp_dir() . '/qcj_probe_' . getmypid()));
});
if (!is_file($ROOT . '/manifest.json')) { is_(false, 'the tree to judge holds a plugin', $ROOT); qc_done(); }

// The fake uCRM and the fake Evolution — this checkout's fixtures, whatever tree is judged.
[$fp, $FAKE] = qcj_boot_fake(__DIR__ . '/fixtures');
[$ep, $EVO]  = qcj_boot_evo(__DIR__ . '/fixtures');
if ($fp === null || $ep === null) { is_(false, 'the fake uCRM and the fake Evolution started'); qc_done(); }
$PROCS[] = $fp; $PROCS[] = $ep;

// The tree judged, copied once (ucrm.json and the plugin log are written into a tree), and ce7ac388's, from Git.
$T = $TMP . '/t';
qcj_copy_tree($ROOT, $T);
$BASE = null; $whyBase = '';
if (isset($opt['base'])) {
    $BASE = rtrim((string)$opt['base'], '/');
    if (!is_file($BASE . '/manifest.json')) { $whyBase = 'the given --base holds no plugin'; $BASE = null; }
} else {
    [$BASE, $whyBase] = sj_baseline_tree(QC_BASE_COMMIT);
}
$baseVer = $BASE !== null ? (string)(json_decode((string)@file_get_contents($BASE . '/manifest.json'), true)['information']['version'] ?? '') : '';
$TB = null;
if ($BASE !== null && $baseVer === '5.18.98') { $TB = $TMP . '/tb'; qcj_copy_tree($BASE, $TB); }

// ── Books ──────────────────────────────────────────────────────────────────────────────────────────────────────────
/** A fresh book on tree $tree ($T unless given): its own data directory, vault and configuration. */
function qc_book(string $tenant, array $cfg = [], array $vault = [], ?string $tree = null): array
{
    global $T, $TMP, $FAKE, $EVO;
    static $n = 0;
    $n++;
    $dir = $TMP . '/books/b' . $n;
    return qcj_book(['tree' => $tree ?? $T, 'dir' => $dir, 'fake' => $FAKE, 'evo' => $EVO, 'tenant' => $tenant,
                     'timezone' => QC_TZ[$tenant] ?? QC_TZ[''], 'cfg' => $cfg, 'vault' => $vault]);
}
/** The fake uCRM in a known state: its organizations, its clients, and any toggles. */
function qc_fake(array $orgs, array $clients = [], array $set = []): void
{
    global $FAKE, $EVO;
    qcj_fake($FAKE, '/__test/reset');
    qcj_fake($FAKE, '/__test/set', ['organizations' => $orgs, 'clients' => $clients] + $set);
    qcj_http('GET', "{$EVO}/__test/reset");
}
function qc_log(): array { global $FAKE; return qcj_fake_log($FAKE); }
function qc_reqs(int $from = 0): array { return qcj_reqs(qc_log(), $from); }
/** The requests that WRITE (anything but GET). */
function qc_writes(array $reqs): array
{
    return array_values(array_filter($reqs, function ($r) { return strpos($r, 'GET ') !== 0; }));
}
function qc_texts(): array { global $EVO; return qcj_evo_texts($EVO); }
/** A digest of a book's store tables: equal digests mean nothing was written to them. */
function qc_digest(array $ctx, array $tables = ['kyc_applications', 'leads', 'quotes_log', 'wa_sent_quotes', 'payment_collections',
                                               'passbook', 'retailers', 'lte_subscribers']): array
{
    $out = [];
    // A table that does not exist yet and an empty one both hold nothing written (a read creates the table).
    foreach (qcj_tables($ctx['data'], $tables) as $t => $rows) $out[$t] = hash('sha256', qc_j($rows === null ? [] : $rows));
    return $out;
}
function qc_app(array $ctx, int $id): array
{
    foreach ((array)(qcj_tables($ctx['data'], ['kyc_applications'])['kyc_applications'] ?? []) as $a) {
        if ((int)($a['id'] ?? $a['__id'] ?? 0) === $id) return $a;
    }
    return [];
}
function qc_apps(array $ctx): array { return (array)(qcj_tables($ctx['data'], ['kyc_applications'])['kyc_applications'] ?? []); }

/** A fake SMTP relay of this run's own, and the book's mail settings pointed at it (quote_email_via_plugin on). */
function qc_smtp(array $ctx): array
{
    $transcript = $ctx['dir'] . '/smtp.json';
    foreach (range(0, 11) as $slot) {
        $port = 14600 + ((getmypid() * 5 + $slot * 11 + random_int(0, 9)) % 300);
        $p = proc_open(sprintf('exec %s %s %d %s %d', escapeshellarg(PHP_BINARY), escapeshellarg(__DIR__ . '/fixtures/fake_smtp_server.php'),
            $port, escapeshellarg($transcript), 60), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) {
            usleep(100000);
            if (!proc_get_status($p)['running']) break;
            if (!is_file($transcript)) continue;
            $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 2);
            if ($c) {
                $g = fgets($c, 256); fwrite($c, "QUIT\r\n"); fclose($c);
                if (strpos((string)$g, 'fake.smtp.test') !== false) {
                    file_put_contents($ctx['data'] . '/email_settings.json', json_encode(['use_ucrm_email' => false, 'smtp_host' => '127.0.0.1',
                        'smtp_port' => $port, 'smtp_user' => '', 'smtp_pass' => '', 'smtp_enc' => '', 'smtp_from' => 'accounts@example.test',
                        'quote_email_via_plugin' => true]));
                    return [$p, $transcript];
                }
            }
        }
        qcj_stop($p); @unlink($transcript);
    }
    return [null, $transcript];
}
/** The messages a relay took: envelope and headers (SjSandbox::parseMail). */
function qc_mails(string $transcript): array
{
    $out = [];
    foreach (json_decode((string)@file_get_contents($transcript), true) ?: [] as $m) {
        if (trim((string)($m['data'] ?? '')) === '') continue;
        $out[] = ['to' => (array)$m['rcpt_to']] + SjSandbox::parseMail((string)$m['data']);
    }
    return $out;
}
/**
 * A rendered page with what is a run's own and not the release's taken out: the session's CSRF token (read from the
 * page by its name), the served address, and the plugin's own version (manifest.json, which the release moves).
 */
function qc_norm_page(string $html, string $base, string $tree): string
{
    if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) $html = str_replace($m[1], '<N:csrf>', $html);
    $html = str_replace(preg_replace('#/public\.php$#', '', $base), '<plugin>', $html);
    $v = (string)(json_decode((string)@file_get_contents($tree . '/manifest.json'), true)['information']['version'] ?? '');
    return $v !== '' ? str_replace('v' . $v, 'v<N:version>', $html) : $html;
}

/** A KYC form submission (post_kyc.php, kyc_submit) for a new customer, or an existing one with $existing. */
function qc_kyc_post(string $last, string $phone, string $type = 'Credit', string $existing = ''): array
{
    return ['action' => 'kyc_submit', 'customer_type' => 'StarLink', 'connectivity_type' => 'New Connection', 'firstname' => 'TEST',
            'lastname' => $last, 'mobile' => $phone, 'email' => '', 'address_1' => 'TEST address', 'package_choice' => '1',
            'device_id' => '3', 'kitQty' => '1', 'sales_type' => $type, 'priority' => 'Low'] + ($existing !== '' ? ['customer_id' => $existing] : []);
}
const QC_ITEMS = [['label' => 'TEST Kit', 'quantity' => 1, 'price' => 1500, 'unit' => 'piece']];

// The salesperson-facing texts, written out (SPEC §1.4, §2.2 K, §2.7): a copy that changes a word must fail.
function qc_r1(string $client, string $quote): string
{
    return "This customer is filed under {$client} in uCRM. Move them to {$quote} in uCRM (or ask an admin to), then make the quote again.";
}
function qc_r2(string $quote): string
{
    return "uCRM does not say which company this customer is filed under. Open the customer in uCRM and file them under {$quote} (or ask an admin to), then make the quote again.";
}
function qc_r3(string $id): string { return "uCRM has no customer #{$id}. Check the customer's CRM ID, then make the quote again."; }
const QC_R4 = 'uCRM could not be reached to check which company this customer is filed under, so no quotation was made. Try again in a minute; if it keeps happening, tell an admin.';
function qc_r5(int $m): string { return "The company the app quotes under (uCRM organization #{$m}) is not in uCRM, so no quotation was made. Tell an admin."; }
const QC_R6 = 'The company the app quotes under is not set correctly, so no quotation was made. Tell an admin.';
const QC_R7 = "uCRM refused the app's key while checking which company this customer is filed under, so no quotation was made. Tell an admin: trying again will not help.";
function qc_k(int $id, string $reason): string
{
    return "The customer is in uCRM as #{$id}, but no quotation was made: {$reason} Then make the quotation from Send Quotation → Manual with CRM ID {$id}. Do not register this customer again, and do not use Existing Customer.";
}
const QC_C1_HEAD = 'uCRM does not list the company Uganda customers are filed under (organization #';
const QC_C2 = 'the company Uganda customers are filed under is not set correctly, so the plugin will not guess which company to file the customer under. Tell an admin';
/** The amber line's promise on a configuration refusal (C1/C2): what KycCrmSync's retry job really does (B2 measures it). */
const QC_RETRY_LINE = 'Once that is fixed it goes into the CRM at the next automatic retry. Automatic retries stop about two and a half days after this; then an admin retries it from Orders.';
const QC_ONE = 'TEST Co One (#1)';
const QC_TWO = 'TEST Co Two (#2)';
/**
 * I4: the hunks of 5.18.99 that do not themselves name it — each one's added lines, declared here exactly, as
 * tests/test_bank_boundaries.php declares the lines 5.18.98 changed in includes/routes.php. Every other hunk of the
 * release names 5.18.99. A hunk that is neither fails I4; a declared hunk that is no longer there fails it too.
 */
const QC_I4_UNMARKED = [
    'lib/KycCrmSync.php' => [
        '        $out = $this->result(true, \'synced\', $crmId,',
        '        $refused = \'\';',
        '        return $refused;',
        '    private function failed(array $app, string $reason, string $by, bool $config = false): array',
    ],
    'lib/KycService.php' => [
        '                    (string)($post[\'mobile\'] ?? \'\'), $quoteKnown);',
        '                                     string $notes, string $adminNotes, string $phone, ?array $known = null): array',
    ],
    'lib/PluginQuotePdf.php' => [
        '            \'{{HEADER_LOCALITY}}\' => $ug ? $ugLocality : \'Juba, South Sudan\',
            \'{{ORG_LINES}}\'       => $ug ? implode(\'<br>\', $ugLines) : \'{{ORG_STREET}}<br>{{ORG_CITY}}<br>{{ORG_PHONE}}<br>{{ORG_EMAIL}}<br>{{ORG_WEBSITE}}\',
            \'{{AMOUNT_HEAD}}\'     => $ug ? \'Amount (\' . htmlspecialchars(dn_code($this->config)) . \')\' : \'Amount (USD)\',
            \'{{PREPARED_ORG}}\'    => $ug ? \'{{ORG_NAME}}\' : \'DishNet Africa Ltd\',
            \'{{FOOTER_LOCALITY}}\' => $ug ? $ugLocality : \'Juba, South Sudan\',',
        '<div style="font-size:9px;color:#777;margin-top:4px;">Internet Service Provider &middot; {{HEADER_LOCALITY}}</div>',
        '<div style="font-size:10px;color:#6B6B6B;line-height:1.5;">{{ORG_LINES}}</div>',
        '<th style="background:#141414;color:#fff;font-size:9px;text-transform:uppercase;letter-spacing:0.8px;padding:9px 10px;font-weight:700;text-align:right;width:22%;">{{AMOUNT_HEAD}}</th>',
        '<span style="font-size:9px;color:#6B6B6B;float:right;">{{PREPARED_ORG}}</span>',
        '<td style="padding:8px 28px;font-size:9px;color:#777;">{{ORG_NAME}} &middot; {{FOOTER_LOCALITY}}</td>',
    ],
    'lib/QuotationService.php' => [
        '            $result = $this->createCrmQuote($crmClientId, $items, $quoteRef, $retailer, $note, \'manual\');',
        '            elseif ($profileLast !== null) {
                $out[$field] = $profileLast[$field]; $src[$field] = \'profile\';
                $warn[] = "{$field} fell through to the Uganda profile\'s \\"{$profileLast[$field]}\\" — "
                        . "uCRM has no {$orgKey} and {$cfgKey} is unset";
            }',
        '            $qo    = $this->quoteOrg();',
        '    public function createCrmQuote(int $crmClientId, array $items, string $quoteRef, array $retailer, string $note = \'\',
                                   string $where = \'lead\'): array',
        '                $madeId     = $quoteId;',
    ],
    'lib/UcrmClientTarget.php' => [
        '    public function __construct($crm, array $quoteOrg = [])',
        '        $this->quoteOrg = $quoteOrg;',
    ],
    'tools/set_config.php' => [
        '    \'ucrm_quote_organization_id\' => [\'text\',
        \'Uganda only (ignored on South Sudan): the uCRM organization app customers are filed under and app quotes are made for. Blank = the Uganda profile (organization 1); 0 or off = switched off, as 5.18.98\'],',
    ],
];

/**
 * I4: the release's documents and tests — the files I4's walk leaves out (tests/, docs/ … and every *.md) — each with
 * why. The release's declared file list and its pin carry these too; a file changed and not listed here fails I4.
 */
const QC_I4_DOCS_TESTS = [
    'SAFETY.md'                                => 'the 5.18.99 version log (and its review corrections)',
    'tests/fixtures/fake_smtp_server.php'      => 'tests only: each message written down before the relay answers, the transcript replaced whole (the 20 Oct H3 failure)',
    'tests/fixtures/fake_ucrm_quote_org.php'   => 'new: the fake uCRM of this test',
    'tests/fixtures/fake_ucrm_staff_jobs.php'  => 'serves /organizations only when a seed holds organizations (SPEC §3)',
    'tests/fixtures/quote_company_journey.php' => 'new: the §S journey J1–J14',
    'tests/test_bank_boundaries.php'           => 'the manifest line is 5.18.99; tools/set_config.php is held by I3 instead (SPEC §3)',
    'tests/test_cashbook_physical_list.php'    => 'section M measured on ce7ac388 from Git, the tree held to it for 5.18.98\'s six files',
    'tests/test_distributor_apply.php'         => 'the manifest pin moves to 5.18.99',
    'tests/test_distributor_link_ucrm.php'     => 'the manifest pin moves to 5.18.99',
    'tests/test_distributor_notify.php'        => 'the manifest pin moves to 5.18.99',
    'tests/test_distributor_registry.php'      => 'the manifest pin moves to 5.18.99',
    'tests/test_distributor_territory.php'     => 'the manifest pin moves to 5.18.99',
    'tests/test_fake_smtp_relay.php'           => 'new: the relay\'s checks, which 5.18.98\'s relay fails',
    'tests/test_notify_kyc_quote_send.php'     => 'client 901 seeded in organization 1 for the Uganda check (SPEC §3)',
    'tests/test_notify_quote_once.php'         => 'client 15 carries organizationId 1 for the Uganda check (SPEC §3)',
    'tests/test_quote_company.php'             => 'new: this test',
    'tests/test_quote_company_mutants.php'     => 'new: its weakened copies',
];

/**
 * I6: syntax newer than PHP 7.4, found on an added code line (comments taken out). Each rule names what it catches;
 * QC_I6_SAMPLES holds, per rule, a line it must catch and a PHP 7.4 line it must leave alone.
 */
const QC_I6_RULES = [
    'match ('                        => '/\bmatch\s*\(/',
    'str_contains('                  => '/\bstr_contains\(/',
    'str_starts_with('               => '/\bstr_starts_with\(/',
    'str_ends_with('                 => '/\bstr_ends_with\(/',
    '?->'                            => '/\?->/',
    'readonly'                       => '/\breadonly\b/',
    'a named argument'               => '/[(,]\s*[a-zA-Z_][a-zA-Z0-9_]*\s*:(?!:)\s*[^\s:]/',
    'a union type'                   => '/(?:[(,]\s*|\)\s*:\s*)\??[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\s*\|\s*\??[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:\s+&?(?:\.\.\.)?\$|\s*[{;])/',
    'constructor promotion'          => '/\bfunction\s+__construct\s*\([^)]*\b(?:public|private|protected)\s/',
    'a mixed, static or never type'  => '/\)\s*:\s*\??(?:mixed|static|never)\b|[(,]\s*\??mixed\s+&?\$/',
    'throw as an expression'         => '/(?:=>|\?\?|\?:|(?<![=!<>])=(?![=>]))\s*throw\b/',
    '::class on an object'           => '/\$[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*)*::class\b/',
    'a trailing comma in a parameter or use list' => '/\b(?:function|fn|use)\b[^()]*\([^()]*,\s*\)/',
];
const QC_I6_SAMPLES = [
    'match ('                        => ['$x = match ($a) { 1 => 2 };', '$x = $matches[0];'],
    'str_contains('                  => ['if (str_contains($h, $n)) {', 'if (strpos($h, $n) !== false) {'],
    'str_starts_with('               => ['$ok = str_starts_with($h, "a");', '$ok = strpos($h, "a") === 0;'],
    'str_ends_with('                 => ['$ok = str_ends_with($h, "a");', '$ok = substr($h, -1) === "a";'],
    '?->'                            => ['$x = $a?->b;', '$x = $a->b ?? null;'],
    'readonly'                       => ['public readonly int $x;', 'private int $x;'],
    'a named argument'               => ['foo(name: 1);', 'foo($a, B::C);'],
    'a union type'                   => ['function f(int|string $x): int|string {', 'function f(int $x): ?int { return A | B; }'],
    'constructor promotion'          => ['public function __construct(private int $x) {', 'public function __construct(int $x) {'],
    'a mixed, static or never type'  => ['public function f(): static {', 'public function f(): self {'],
    'throw as an expression'         => ['$x = $y ?? throw new E();', 'if (!$y) throw new E();'],
    '::class on an object'           => ['$n = $obj::class;', '$n = get_class($obj) . self::class;'],
    'a trailing comma in a parameter or use list' => ['$f = function ($a, $b, ) {', '$f = function ($a, $b) use ($c) { return g($a, ); };'],
];

/**
 * §S: the normaliser — an explicit list, not a pattern (SPEC §5 §S, SSG-1). Each entry is where the field is, the field,
 * and what writes it (file:line in this tree). Completed by measurement: ce7ac388's journey run twice, two seconds apart,
 * differs in exactly these and nothing else; §S re-runs that self-comparison every time, and fails naming any field that
 * differs and is not here. No entry is a no-op: each is present in both baseline runs of at least one scenario.
 */
const QC_NORMALISED = [
    ['kyc_applications', 'submitted_at',          'lib/KycService.php:1835'],
    ['kyc_applications', 'wa_quote_deferred_at',  'lib/KycService.php:402, :1745; the seeded Flow A row'],
    ['kyc_applications', 'crm_job_created_at',    'lib/KycService.php:426'],
    ['kyc_applications', 'crm_sync_claimed_at',   'lib/KycCrmSync.php:533 (left on the row after the claim is cleared)'],
    ['kyc_applications', 'crm_sync_last_attempt', 'lib/KycCrmSync.php:190, :227, :403, :427'],
    ['kyc_applications', 'crm_synced_at',         'lib/KycCrmSync.php:226, :402'],
    ['kyc_applications', 'wa_quote_sent_at',      'cron_quote_wa.php:202, :242'],
    ['leads',            'quoted_at',             'lib/QuotationService.php:218'],
    ['leads',            'crm_synced_at',         'lib/UcrmLeadSync.php:181'],
    ['lte_subscribers',  'created_at',            'lib/LteSqliteService.php:99 (the INSERT)'],
    ['passbook',         'created_at',            'lib/WalletService.php:345'],
    ['passbook',         'trx_no',                'lib/WalletService.php:344'],
    ['passbook',         'reference',             'lib/WalletService.php:186 (TOP-<Ymd>-<time()>)'],
    ['passbook',         'idempotency_key',       'lib/WalletService.php:114, :178 (genKey(), :390)'],
    ['payment_collections', 'created_at',         'lib/KycService.php:1199'],
    ['quotes_log',       'created_at',            'lib/QuotationService.php:1081'],
    ['wa_sent_quotes',   'sent_at',               'lib/QuoteWaLedger.php:27 (the column default)'],
    ['page:*',           'csrf',                  'the session CSRF token, read from the page by name="_csrf"'],
    ['page:*',           'address',               'the served address: its port is chosen per run'],
    ['page:*',           'version',               'manifest.json\'s version, which the release moves'],
];

echo "test_quote_company.php — 5.18.99, judging " . ($ROOT === $LIB ? 'this checkout' : $ROOT) . "\n";
is_($TB !== null, 'the ce7ac388 tree the comparisons need is at hand (5.18.98, ' . ($BASE !== null && !isset($opt['base']) ? 'from Git' : 'given') . ')',
    $BASE === null ? $whyBase : 'version ' . $baseVer);

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if (qc_section('A')) {
echo "\nA. The gate: QuoteOrganization::setting() — the Uganda tenant AND the setting\n";
/**
 * setting() and QuoteTaxLine::applies() for one book, read in a fresh process. $dd: pass the data directory (true) or
 * null (false). $tree: a tree other than $T (a profile copy).
 */
$gate = function (array $ctx, bool $dd = true, ?string $tree = null): array {
    $c = $tree !== null ? array_merge($ctx, ['tree' => $tree]) : $ctx;
    $r = qcj_php($c, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        require_once "$TREE/lib/QuoteOrganization.php"; require_once "$TREE/lib/QuoteTaxLine.php";
        $s = SqliteStore::create($DATA);
        $cfg = $s->load("kyc_config.json") ?: [];
        if (array_key_exists("raw", $ARGS)) $cfg["ucrm_quote_organization_id"] = $ARGS["raw"];
        $dd = $ARGS["dd"] ? $DATA : null;
        qcj_out(["s" => QuoteOrganization::setting($cfg, $dd), "tax" => QuoteTaxLine::applies($cfg, $dd)]);',
        ['dd' => $dd] + ($ctx['_raw'] ?? []));
    return is_array($r['out']) ? $r['out'] : ['s' => null, 'tax' => null, 'raw' => $r['raw']];
};
$withRaw = function (array $ctx, $raw): array { $ctx['_raw'] = ['raw' => $raw]; return $ctx; };
qc_fake(qcj_orgs([1, 2]));
$ON1 = ['on' => true, 'id' => 1, 'source' => 'profile', 'error' => ''];
$OFF = ['on' => false, 'id' => null, 'source' => 'off', 'error' => ''];
$XOFF = ['on' => false, 'id' => null, 'source' => 'explicit-off', 'error' => ''];
$INV = ['on' => true, 'id' => null, 'source' => 'setting', 'error' => 'invalid'];

$ug = qc_book('uganda');
$nU = count(qc_log());   // counted around the Uganda read itself, as around South Sudan's below
$g = $gate($ug);
$ugReqs = qc_reqs($nU);
t('A control: Uganda by tenant_profile, key unset → on, organization 1, from the Uganda profile', $g['s'], $ON1);
is_($g['tax'] === true, 'A control: … and QuoteTaxLine::applies() says Uganda too');
$n0 = count(qc_log());
$ss = qc_book('south-sudan');
$g = $gate($ss);
t('A South Sudan → off; no other key is read', $g['s'], $OFF);
$g2 = $gate($withRaw($ss, '1'));
t('A South Sudan with ucrm_quote_organization_id=1 → still off (the tenant half)', $g2['s'], $OFF);
is_($g['tax'] === false && $g2['tax'] === false, 'A … and QuoteTaxLine::applies() says not Uganda');
t('A reading the gate sends no request to uCRM, on either tenant (the Uganda read and the two South Sudan reads, each counted)',
    [$ugReqs, qc_reqs($n0)], [[], []]);
// Uganda selected by a currency held only in the vault: with the data directory → on; without it → off.
$vu = qc_book('', [], ['currency_code' => 'UGX']);
$g = $gate($vu, true);
t('A Uganda by a vault-only currency_code=UGX, the data directory passed → on, organization 1', $g['s'], $ON1);
$g = $gate($vu, false);
t('A … the same book with no data directory → off (the vault is not consulted)', $g['s'], $OFF);
// The off words, the valid values, the invalid ones.
foreach (['0', 'off', 'No', ' none ', 'FALSE'] as $v) {
    t('A explicit ' . qc_j($v) . ' → off, explicit-off (5.18.98\'s behaviour)', $gate($withRaw($ug, $v))['s'], $XOFF);
}
foreach (['3' => '3', ' 3 ' => ' 3 ', 'int 3' => 3] as $label => $v) {
    t("A explicit {$label} → organization 3, from the setting", $gate($withRaw($ug, $v))['s'], ['on' => true, 'id' => 3, 'source' => 'setting', 'error' => '']);
}
foreach (['abc' => 'abc', '-1' => '-1', '1.5' => '1.5', '1O' => '1O', '#1' => '#1', '[1]' => [1], 'int 0' => 0, 'int -4' => -4] as $label => $v) {
    t("A explicit {$label} → on, invalid (every user refuses; never a guess)", $gate($withRaw($ug, $v))['s'], $INV);
}
// The profile's own value: a copy of lib/ and profiles/ with uganda.json changed.
$pcopy = function (string $tag, callable $edit) use ($T, $TMP): string {
    $d = $TMP . '/profile_' . $tag;
    @mkdir($d, 0700, true);
    exec('cp -R ' . escapeshellarg($T . '/lib') . ' ' . escapeshellarg($T . '/profiles') . ' ' . escapeshellarg($d . '/'));
    $f = $d . '/profiles/uganda.json';
    file_put_contents($f, $edit((string)file_get_contents($f)));
    return $d;
};
$pj = function (string $src, $v): string {
    $p = json_decode($src, true); $p['quoting']['ucrm_organization_id'] = $v;
    return (string)json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};
t('A a profile copy with "ucrm_organization_id": null → off', $gate($ug, true, $pcopy('null', function ($s) use ($pj) { return $pj($s, null); }))['s'], $OFF);
t('A a profile copy with "ucrm_organization_id": "x" → on, invalid', $gate($ug, true, $pcopy('x', function ($s) use ($pj) { return $pj($s, 'x'); }))['s'],
    ['on' => true, 'id' => null, 'source' => 'profile', 'error' => 'invalid']);
$broken = $gate($ug, true, $pcopy('broken', function ($s) { return '{ not json'; }));
t('A an unreadable profile (TenantProfile throws) → off', $broken['s'], $OFF);
// The tenant half never disagrees with the tax line's.
$agree = [];
foreach ([[$ug, null], [$ss, null], [$withRaw($ss, '1'), null], [$vu, null], [$withRaw($ug, '3'), null], [$withRaw($ug, 'abc'), null],
          [$withRaw($ug, '0'), null]] as $i => [$c, $tr]) {
    $g = $gate($c);
    $tenantOn = $g['s']['on'] || $g['s']['source'] === 'explicit-off';
    if ($tenantOn !== $g['tax']) $agree[] = $i;
}
t('A in every case the gate\'s tenant half equals QuoteTaxLine::applies() (7 cases)', $agree, []);
// KycService reads the gate with the store's data directory, so the vault-only Uganda book is Uganda there too.
qc_fake(qcj_orgs([1, 2]));
$r = qcj_post($vu, 'post_kyc.php', qc_kyc_post('Vault', '+256700000150', 'Credit', 'abc'), (int)$vu['agent']);
t('A vault-only Uganda: KycService applies the gate (a CRM ID that is not a number is refused, R3), reading the store\'s data directory',
    $r['flash'], [['msg' => qc_r3('abc'), 'type' => 'danger']]);
}

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if (qc_section('B')) {
echo "\nB. Item 1: the KYC path files a new client under the configured organization\n";
/** UcrmClientTarget, built as the KYC path builds it (forInstall), against the fake. */
$target = function (array $ctx, bool $legacyCtor = false): array {
    $r = qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        require_once "$TREE/lib/CrmApiClient.php"; require_once "$TREE/lib/UcrmClientTarget.php";
        $s = SqliteStore::create($DATA);
        $cfg = $s->load("kyc_config.json") ?: [];
        $crm = CrmApiClient::fromUcrm($TREE, $cfg);
        $t = $ARGS["legacy"] ? new UcrmClientTarget($crm) : UcrmClientTarget::forInstall($crm, $cfg, $DATA);
        $o = $t->organization();
        qcj_out(["org" => $o, "rows" => method_exists($t, "organizationRows") ? $t->organizationRows() : "absent",
                 "apply" => $t->apply(["organizationId" => 2, "firstName" => "TEST"])]);', ['legacy' => $legacyCtor]);
    return is_array($r['out']) ? $r['out'] : ['org' => null, 'raw' => $r['raw']];
};
// Controls: the legacy rule, exactly as ce7ac388 — South Sudan [2,7] → 2; Uganda switched off [1,2] → 2.
qc_fake(qcj_orgs([2, 7]));
t('B1 control: South Sudan, organizations [2,7] → 2 (ce7ac388\'s rule)', $target(qc_book('south-sudan'))['org'], ['org' => 2, 'error' => '']);
qc_fake(qcj_orgs([1, 2]));
t('B1 control: Uganda switched off (0), organizations [1,2] → 2 (ce7ac388\'s rule)', $target(qc_book('uganda', ['ucrm_quote_organization_id' => '0']))['org'],
    ['org' => 2, 'error' => '']);
$n0 = count(qc_log());
$b1 = $target(qc_book('uganda'));
t('B1 Uganda, organizations [1,2] → 1, the configured organization (ce7ac388 picks 2)', $b1['org'], ['org' => 1, 'error' => '']);
is_(($b1['apply']['payload']['organizationId'] ?? null) === 1, 'B1 … and apply() puts organizationId 1 in the create', qc_j($b1['apply']));
t('B1 organizationRows() returns the rows it read', array_column((array)$b1['rows'], 'id'), [1, 2]);
qc_fake(qcj_orgs([2, 3]));
$b = $target(qc_book('uganda'));
is_(qc_isnull($b['org'], 'org') && !empty($b['org']['config']) && strpos((string)$b['org']['error'], QC_C1_HEAD . '1;') === 0
    && strpos((string)$b['org']['error'], '#2') !== false && strpos((string)$b['org']['error'], '#3') !== false
    && strpos((string)$b['org']['error'], 'Tell an admin') !== false && strpos((string)$b['org']['error'], 'ucrm_quote_organization_id') === false,
    'B1 organizations [2,3] → C1, marked config, listing the ids, with no setting name in it', qc_j($b['org']));
is_(qc_isnull($b['apply'], 'payload') && !empty($b['apply']['config']), 'B1 … and apply() refuses, marked config (nothing to send)', qc_j($b['apply']));
qc_fake(qcj_orgs([1, 2]));
$n0 = count(qc_log());
$b = $target(qc_book('uganda', ['ucrm_quote_organization_id' => 'abc']));
t('B1 an invalid setting → C2, marked config', $b['org'], ['org' => null, 'error' => QC_C2, 'config' => true]);
t('B1 … read without asking uCRM for its organizations', array_values(array_filter(qc_reqs($n0), function ($r) { return strpos($r, '/organizations') !== false; })), []);
qc_fake(qcj_orgs([1, 2]), [], ['orgs_down' => true]);
$b = $target(qc_book('uganda'));
is_(qc_isnull($b['org'], 'org') && strpos((string)$b['org']['error'], 'the organizations could not be read') === 0 && !isset($b['org']['config']),
    'B1 organizations unreadable → the existing "could not be read" error, not marked config (a retry can fix it)', qc_j($b['org']));

// B2 — KycService::handleNew through the post_kyc.php include.
$posts = function (int $from): array {
    return array_values(array_filter(array_slice(qc_log(), $from), function ($e) { return $e['method'] === 'POST' && $e['path'] === '/clients'; }));
};
qc_fake(qcj_orgs([1, 2]), [], ['orgs_down' => true]);
$off = qc_book('uganda', ['ucrm_quote_organization_id' => '0']);
$r = qcj_post($off, 'post_kyc.php', qc_kyc_post('Offdown', '+256700000151'), (int)$off['agent']);
is_(($r['flash'][0]['type'] ?? '') === 'warning'
    && strpos((string)$r['flash'][0]['msg'], 'It is retried automatically, and an admin can retry it now from Orders.') !== false,
    'B2 control: switched off, uCRM unreadable — amber, and the line still ends "…an admin can retry it now from Orders."', qc_j($r['flash']));
qc_fake(qcj_orgs([1, 2]));
$n0 = count(qc_log());
$r = qcj_post($off, 'post_kyc.php', qc_kyc_post('Offok', '+256700000152'), (int)$off['agent']);
t('B2 control: switched off, organizations [1,2] → the create names organization 2, as ce7ac388', array_map(function ($e) { return $e['body']['organizationId'] ?? null; }, $posts($n0)), [2]);
qc_fake(qcj_orgs([1, 2]));
$ug = qc_book('uganda');
$n0 = count(qc_log());
$r = qcj_post($ug, 'post_kyc.php', qc_kyc_post('Bnew', '+256700000153'), (int)$ug['agent']);
t('B2 Uganda, organizations [1,2]: exactly one POST clients, naming organization 1', array_map(function ($e) { return $e['body']['organizationId'] ?? null; }, $posts($n0)), [1]);
is_(($r['flash'][0]['type'] ?? '') === 'success', 'B2 … and the agent sees green', qc_j($r['flash']));
qc_fake(qcj_orgs([2, 3]));
$ug = qc_book('uganda');
$n0 = count(qc_log());
$r = qcj_post($ug, 'post_kyc.php', qc_kyc_post('Bmissing', '+256700000154'), (int)$ug['agent']);
$apps = qc_apps($ug);
$msg = (string)($r['flash'][0]['msg'] ?? '');
t('B2 organizations [2,3]: no POST clients', count($posts($n0)), 0);
is_(count($apps) === 1 && ($apps[0]['crm_sync_status'] ?? '') === 'pending' && strpos((string)($apps[0]['crm_sync_error'] ?? ''), QC_C1_HEAD) === 0,
    'B2 … the application is saved pending, with C1 as its reason', qc_j(array_map(function ($a) { return [$a['crm_sync_status'] ?? null, $a['crm_sync_error'] ?? null]; }, $apps)));
is_(($r['flash'][0]['type'] ?? '') === 'warning' && strpos($msg, QC_C1_HEAD) !== false
    && strpos($msg, '. ' . QC_RETRY_LINE . ' Username: ') !== false && strpos($msg, 'an admin can retry it now from Orders') === false,
    'B2 … amber, and the line says what the retry job does: "' . QC_RETRY_LINE . '" (a retry cannot fix a setting)', $msg);
// … and that is what KycCrmSync's retry job does, measured on this application (its cron pass, runDue): for each
// attempt the shortest wait after which it tries again (to the minute), the sum of those waits, and what happens after
// the last one. A configuration refusal counts like any failure, so the promise has an end and the line must say so.
$bApp = (int)($apps[0]['id'] ?? $apps[0]['__id'] ?? 0);
$probe = qcj_php($ug, '
    require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
    require_once "$TREE/lib/CrmApiClient.php"; require_once "$TREE/lib/KycCrmSync.php";
    $s = SqliteStore::create($DATA); $cfg = $s->load("kyc_config.json") ?: [];
    $crm = CrmApiClient::fromUcrm($TREE, $cfg);
    $id = (int)$ARGS["id"]; $errors = [];
    $set = function (int $k, int $ago) use ($s, $id) {
        $s->updateOne("kyc_applications.json", "id", $id, ["crm_sync_status" => "pending", "crm_sync_attempts" => $k,
            "crm_sync_last_attempt" => date("Y-m-d H:i:s", time() - $ago), "crm_sync_claim" => null]);
    };
    $tried = function (int $k, int $ago) use ($s, $id, $crm, $cfg, $set): bool {
        $set($k, $ago);
        (new KycCrmSync($s, $crm, $cfg))->runDue();
        return (int)($s->findOne("kyc_applications.json", "id", $id)["crm_sync_attempts"] ?? -1) === $k + 1;
    };
    $waits = [];
    for ($k = 1; $k < KycCrmSync::MAX_ATTEMPTS; $k++) {
        $lo = 0; $hi = 43200 + 120;   // not tried straight after an attempt; tried after 12 h (the longest back-off) and a margin
        if ($tried($k, $lo) || !$tried($k, $hi)) { $errors[] = "attempt {$k}: the bounds do not hold"; break; }
        while ($hi - $lo > 60) { $mid = intdiv($lo + $hi, 2); if ($tried($k, $mid)) $hi = $mid; else $lo = $mid; }
        $waits[] = $hi;
    }
    $set(KycCrmSync::MAX_ATTEMPTS, 43200 + 120);
    $sum = (new KycCrmSync($s, $crm, $cfg))->runDue();
    $after = $s->findOne("kyc_applications.json", "id", $id) ?: [];
    qcj_out(["errors" => $errors, "max" => KycCrmSync::MAX_ATTEMPTS, "waits" => $waits, "window" => array_sum($waits),
             "gave_up" => $sum["gave_up"] ?? null, "status" => $after["crm_sync_status"] ?? null]);', ['id' => $bApp]);
$pw = is_array($probe['out']) ? $probe['out'] : [];
$hours = (int)($pw['window'] ?? 0) / 3600;
t('B2 … measured: ' . ($pw['max'] ?? '?') . ' automatic attempts, the last ' . sprintf('%.1f', $hours) . ' h after the first — about two and a half days (54–66 h) — then given up (failed), never tried again by itself',
    [$bApp > 0, $pw['errors'] ?? null, count((array)($pw['waits'] ?? [])) === (int)($pw['max'] ?? 0) - 1, $hours >= 54 && $hours <= 66, $pw['gave_up'] ?? null, $pw['status'] ?? null],
    [true, [], true, true, 1, 'failed']);
// … then, fixed afterwards: the cron does nothing more, and an admin's Retry from Orders creates the customer.
qc_fake(qcj_orgs([1, 2]));
$n0 = count(qc_log());
[$rc] = qcj_script($ug, 'cron/kyc_crm_sync.php');
$autoPosts = count($posts($n0));
$n0 = count(qc_log());
$r = qcj_post($ug, 'post_kyc.php', ['action' => 'kyc_crm_retry', 'app_id' => (string)$bApp], (int)$ug['admin']);
t('B2 … uCRM fixed after that: the cron posts nothing (it gave up), and an admin\'s Retry from Orders creates the client in organization 1',
    [$rc, $autoPosts, array_map(function ($e) { return $e['body']['organizationId'] ?? null; }, $posts($n0)), $r['flash'][0]['type'] ?? null],
    [0, 0, [1], 'success']);
// The vault-only Uganda book: the store's data directory selects Uganda for the KYC create too.
qc_fake(qcj_orgs([1, 2]));
$vu = qc_book('', [], ['currency_code' => 'UGX']);
$n0 = count(qc_log());
$r = qcj_post($vu, 'post_kyc.php', qc_kyc_post('Bvault', '+256700000155'), (int)$vu['agent']);
t('B2 a vault-only Uganda book: the create names organization 1 too', array_map(function ($e) { return $e['body']['organizationId'] ?? null; }, $posts($n0)), [1]);

// B3 — the retry: an application saved pending, with organizationId 2 in its stored payload.
$pending = function (array $ctx, int $id, string $phone): void {
    qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        $s = SqliteStore::create($DATA);
        $s->append("kyc_applications.json", ["id" => (int)$ARGS["id"], "firstname" => "TEST", "lastname" => "Pending" . $ARGS["id"],
            "mobile" => $ARGS["ph"], "customer_type" => "StarLink", "connectivity_type" => "New Connection", "sales_type" => "Credit",
            "status" => "new", "crm_client_id" => null, "crm_sync_status" => "pending", "crm_sync_attempts" => 0,
            "submitted_at" => date("Y-m-d H:i:s"), "username" => "TESTPEND" . $ARGS["id"],
            "quote_items" => [["label" => "TEST Plan", "quantity" => 1, "price" => 100.0, "unit" => "month"]], "quote_ref" => "QREF-" . $ARGS["id"],
            "crm_sync_payload" => json_encode(["clientType" => 1, "isLead" => true, "firstName" => "TEST", "lastName" => "Pending",
                "organizationId" => 2, "username" => "TESTPEND" . $ARGS["id"], "contacts" => [["name" => "TEST", "email" => "", "phone" => $ARGS["ph"]]]])]);
        qcj_out(true);', ['id' => $id, 'ph' => $phone]);
};
qc_fake(qcj_orgs([1, 2]));
$ug = qc_book('uganda');
$pending($ug, 701, '+256700000156');
$pending($ug, 702, '+256700000157');
$n0 = count(qc_log());
$r = qcj_post($ug, 'post_kyc.php', ['action' => 'kyc_crm_retry', 'app_id' => '701'], (int)$ug['admin']);
t('B3 syncOne (an admin\'s Retry, post_kyc.php): the stored payload said 2; the create names 1', array_map(function ($e) { return $e['body']['organizationId'] ?? null; }, $posts($n0)), [1]);
$n0 = count(qc_log());
[$rc] = qcj_script($ug, 'cron/kyc_crm_sync.php');
t('B3 runDue (cron/kyc_crm_sync.php): the same', [$rc, array_map(function ($e) { return $e['body']['organizationId'] ?? null; }, $posts($n0))], [0, [1]]);
// The retry's refusal: organization 1 is not in uCRM. Nothing is posted and the application stays pending, with C1.
qc_fake(qcj_orgs([2, 3]));
$ug = qc_book('uganda');
$pending($ug, 703, '+256700000147');
$pending($ug, 704, '+256700000148');
$n0 = count(qc_log());
$r = qcj_post($ug, 'post_kyc.php', ['action' => 'kyc_crm_retry', 'app_id' => '703'], (int)$ug['admin']);
$a = qc_app($ug, 703);
t('B3 syncOne with organizations [2,3]: no POST clients, still pending with C1 as its reason, and the admin is told C1 (red)',
    [count($posts($n0)), $a['crm_sync_status'] ?? null, strpos((string)($a['crm_sync_error'] ?? ''), QC_C1_HEAD) === 0,
     $r['flash'][0]['type'] ?? null, strpos((string)($r['flash'][0]['msg'] ?? ''), QC_C1_HEAD) !== false],
    [0, 'pending', true, 'danger', true]);
$n0 = count(qc_log());
[$rc] = qcj_script($ug, 'cron/kyc_crm_sync.php');
$a = qc_app($ug, 704);
t('B3 runDue with organizations [2,3]: the same — no POST clients, still pending, C1, one attempt counted',
    [$rc, count($posts($n0)), $a['crm_sync_status'] ?? null, strpos((string)($a['crm_sync_error'] ?? ''), QC_C1_HEAD) === 0, (int)($a['crm_sync_attempts'] ?? -1)],
    [0, 0, 'pending', true, 1]);

// B4 — legacyLayout() is false on Uganda gate-on, even when uCRM carries South Sudan's custom fields.
$ssFields = [['id' => 1, 'name' => 'Sales Person', 'key' => 'salesPerson', 'attributeType' => 'client']];
foreach ([36 => 'Priority', 37 => 'Kit Number', 38 => 'Kit Qty', 39 => 'Kit Name', 40 => 'Kit Unit', 41 => 'Package', 42 => 'Device ID', 43 => 'Ref'] as $id => $nm) {
    $ssFields[] = ['id' => $id, 'name' => $nm, 'key' => lcfirst(str_replace(' ', '', $nm)), 'attributeType' => 'client'];
}
$legacyCalls = function (int $from): array {
    return array_values(array_filter(qc_reqs($from), function ($r) { return strpos($r, 'POST /documents') === 0 || strpos($r, '/add-tag/') !== false; }));
};
qc_fake(qcj_orgs([1, 2]), [], ['custom_attributes' => $ssFields]);
$off = qc_book('uganda', ['ucrm_quote_organization_id' => '0']);
$n0 = count(qc_log());
qcj_post($off, 'post_kyc.php', qc_kyc_post('Blegacy', '+256700000158'), (int)$off['agent']);
is_(count($legacyCalls($n0)) === 2, 'B4 control: switched off, organization 2 and the nine fields — the work order and the tag are sent, as ce7ac388', qc_j(qc_reqs($n0)));
qc_fake(qcj_orgs([1, 2]), [], ['custom_attributes' => $ssFields]);
$ug = qc_book('uganda');
$n0 = count(qc_log());
qcj_post($ug, 'post_kyc.php', qc_kyc_post('Bnolegacy', '+256700000159'), (int)$ug['agent']);
t('B4 Uganda gate on: organization 1, so legacyLayout() is false — no POST documents, no add-tag', $legacyCalls($n0), []);
}

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if (qc_section('C')) {
echo "\nC. Item 2: the check before every Uganda app quote\n";
$kycEx = function (array $ctx, string $cid, array $whoExtra = []) {
    return qcj_post($ctx, 'post_kyc.php', qc_kyc_post('Existing', '+256700000115', 'Credit', $cid), (int)$ctx['agent'], $whoExtra);
};
$manual = function (array $ctx, int $cid, string $tick) {
    return qcj_post($ctx, 'post_leads.php', ['action' => 'send_manual_quote', 'mq_crm_client_id' => (string)$cid, 'mq_customer_name' => '',
        'mq_customer_phone' => '', 'mq_create_crm' => $tick, 'mq_note' => '', 'mq_items_json' => json_encode(QC_ITEMS)], (int)$ctx['agent']);
};
$seedLead = function (array $ctx, int $crm) {
    qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        $s = SqliteStore::create($DATA);
        $s->save("leads.json", [["id" => 1, "customer_name" => "TEST Lead", "phone" => "+256700000116", "crm_client_id" => (int)$ARGS["crm"],
            "service_type" => "starlink", "status" => "open", "notes" => "", "retailer_id" => (int)$ARGS["agent"]]]);
        qcj_out(true);', ['crm' => $crm, 'agent' => $ctx['agent']]);
};
$leadQ = function (array $ctx, string $tick) {
    return qcj_post($ctx, 'post_leads.php', ['action' => 'send_lead_quote', 'lead_id' => '1', 'quote_items_json' => json_encode(QC_ITEMS),
        'create_crm_quote' => $tick], (int)$ctx['agent']);
};
/** KycService::process() in a fresh process, through lib/Services.php: the result array itself. */
$process = function (array $ctx, array $post): array {
    $r = qcj_php($ctx, QCJ_POST_PRELUDE . 'qcj_out($kyc->process($_POST, [], $__who));', ['post' => $post, 'who' => $ctx['agent']]);
    return is_array($r['out']) ? $r['out'] : ['raw' => $r['raw']];
};
$cnt = function (array $reqs, string $req): int { return count(array_filter($reqs, function ($r) use ($req) { return $r === $req; })); };
$O12 = qcj_orgs([1, 2]);

// C1 — KYC, existing customer
qc_fake($O12, [qcj_client(15, 1, 'Existing')]);
$ug = qc_book('uganda');
$n0 = count(qc_log());
$r = $kycEx($ug, '15');
is_(($r['flash'][0]['type'] ?? '') === 'success' && $cnt(qc_reqs($n0), 'POST /clients/15/quotes') === 1,
    'C1 control: client 15 in organization 1 — the additional-service quote is made, once, and the agent sees green', qc_j([$r['flash'], qc_reqs($n0)]));
qc_fake($O12, [qcj_client(15, 2, 'Existing')]);
$ug = qc_book('uganda');
$d0 = qc_digest($ug); $n0 = count(qc_log());
$r = $kycEx($ug, '15');
t('C1 client 15 in organization 2: refused with R1, naming both companies with their numbers', $r['flash'], [['msg' => qc_r1(QC_TWO, QC_ONE), 'type' => 'danger']]);
t('C1 … uCRM was only read: GET clients/15, then GET organizations (to name them) — no POST, no PATCH, no documents', qc_reqs($n0), ['GET /clients/15', 'GET /organizations']);
t('C1 … no application, nothing written to the store (a Credit sale debits no wallet either way: the Cash case below)', qc_digest($ug), $d0);
t('C1 … and nothing sent on WhatsApp', qc_texts(), []);
// C1 cash — a Cash sale debits the agent's wallet when the quote is made (the control); refused, it debits nothing.
$kycCash = function (array $ctx, string $cid) {
    return qcj_post($ctx, 'post_kyc.php', qc_kyc_post('Existing', '+256700000115', 'Cash', $cid), (int)$ctx['agent']);
};
$topUp = function (array $ctx): void {
    qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        require_once "$TREE/lib/WalletService.php";
        $s = SqliteStore::create($DATA);
        (new WalletService($s, $s->getPdo()))->credit((int)$ARGS["agent"], 1000000.0, "TEST top-up", "test", "qc-c1-topup");
        qcj_out(true);', ['agent' => $ctx['agent']]);
};
/** The agent's wallet (WalletService keeps it on the retailers row) and the passbook's row count. */
$purse = function (array $ctx) {
    $t = qcj_tables($ctx['data'], ['retailers', 'passbook']);
    $w = null;
    foreach ((array)($t['retailers'] ?? []) as $row) if ((int)($row['__id'] ?? $row['id'] ?? 0) === (int)$ctx['agent']) $w = $row['wallet'] ?? null;
    return [$w, count((array)($t['passbook'] ?? []))];
};
qc_fake($O12, [qcj_client(15, 1, 'Existing')]);
$ug = qc_book('uganda');
$topUp($ug);
[$w0, $p0] = $purse($ug);
$r = $kycCash($ug, '15');
[$w1, $p1] = $purse($ug);
is_(($r['flash'][0]['type'] ?? '') === 'success' && is_numeric($w0) && is_numeric($w1) && (float)$w1 < (float)$w0 && $p1 > $p0,
    'C1 cash control: client 15 in organization 1, a Cash sale — the quote is made, the agent\'s wallet is debited and the passbook gains a row',
    qc_j([$r['flash'], $w0, $w1, $p0, $p1]));
qc_fake($O12, [qcj_client(15, 2, 'Existing')]);
$ug = qc_book('uganda');
$topUp($ug);
[$w0, $p0] = $purse($ug); $d0 = qc_digest($ug); $n0 = count(qc_log());
$r = $kycCash($ug, '15');
t('C1 cash, client 15 in organization 2: refused with R1; the wallet and the passbook are as they were, nothing written, nothing sent to uCRM',
    [$r['flash'], $purse($ug), qc_digest($ug) === $d0, qc_writes(qc_reqs($n0))],
    [[['msg' => qc_r1(QC_TWO, QC_ONE), 'type' => 'danger']], [$w0, $p0], true, []]);

// C2 — KYC, new customer (postQuote)
qc_fake($O12);
$ug = qc_book('uganda');
$n0 = count(qc_log());
$r = qcj_post($ug, 'post_kyc.php', qc_kyc_post('Cnew', '+256700000160'), (int)$ug['agent']);
$rq = qc_reqs($n0);
$iC = array_search('POST /clients', $rq, true); $iQ = array_search('POST /clients/901/quotes', $rq, true);
is_(($r['flash'][0]['type'] ?? '') === 'success' && $iC !== false && $iQ === $iC + 1,
    'C2 (i) the create answer carries organizationId 1: the check sends NO request — POST clients is followed straight by POST clients/901/quotes', qc_j($rq));
$app = qc_apps($ug)[0] ?? [];
is_(!empty($app['quote_created']) && !empty($app['wa_quote_pending']) && !isset($app['quote_refused']), 'C2 (i) … the quote is made and its WhatsApp queued, as before', qc_j($app));
qc_fake($O12, [], ['ignores_org' => true]);
$ug = qc_book('uganda');
$n0 = count(qc_log());
$r = qcj_post($ug, 'post_kyc.php', qc_kyc_post('Cignored', '+256700000161'), (int)$ug['agent']);
$app = qc_apps($ug)[0] ?? [];
$K1 = qc_k(901, 'uCRM filed them under ' . QC_TWO . '. Move them to ' . QC_ONE . ' in uCRM (or ask an admin to).');
t('C2 (ii) uCRM files the new client under 2 anyway: no POST clients/901/quotes', $cnt(qc_reqs($n0), 'POST /clients/901/quotes'), 0);
t('C2 (ii) … the application records the refusal: quote_refused R1, its text K, quote_refused_at; no quote, no wa_quote_pending, no quote_error',
    [empty($app['quote_created']), empty($app['wa_quote_pending']), array_key_exists('quote_error', $app), $app['quote_refused'] ?? null,
     $app['quote_refused_text'] ?? null, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($app['quote_refused_at'] ?? '')) === 1],
    [true, true, false, 'R1', $K1, true]);
$msg = (string)($r['flash'][0]['msg'] ?? '');
is_(($r['flash'][0]['type'] ?? '') === 'warning' && substr_count($msg, $K1) === 1 && substr_count($msg, 'no quotation was made') === 1
    && strpos($msg, 'Customer registered! CRM ID: 901.') === 0,
    'C2 (ii) … post_kyc.php flashes amber, and the message carries K once (no repeated "no quotation was made")', qc_j($r['flash']));
qc_fake($O12, [], ['ignores_org' => true]);
$ug = qc_book('uganda');
$res = $process($ug, qc_kyc_post('Cdata', '+256700000162'));
t('C2 (ii) … and the result\'s data carries quote_refused = K', $res['data']['quote_refused'] ?? null, $K1);
qc_fake($O12, [], ['create_answer_no_org' => true]);
$ug = qc_book('uganda');
$n0 = count(qc_log());
$r = qcj_post($ug, 'post_kyc.php', qc_kyc_post('Cnoorg', '+256700000163'), (int)$ug['agent']);
$rq = qc_reqs($n0);
$iC = array_search('POST /clients', $rq, true);
is_($iC !== false && array_slice($rq, $iC + 1, 2) === ['GET /clients/901', 'POST /clients/901/quotes'],
    'C2 (iii) the create answer carries no organizationId: the check reads the client (GET clients/901), then the quote is made', qc_j($rq));

// C3 — the retry (KycCrmSync → postQuote)
$pendingApp = function (array $ctx, int $id, string $phone) {
    qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        $s = SqliteStore::create($DATA);
        $s->append("kyc_applications.json", ["id" => (int)$ARGS["id"], "firstname" => "TEST", "lastname" => "Retry", "mobile" => $ARGS["ph"],
            "customer_type" => "StarLink", "connectivity_type" => "New Connection", "sales_type" => "Credit", "status" => "new",
            "crm_client_id" => null, "crm_sync_status" => "pending", "crm_sync_attempts" => 0, "submitted_at" => date("Y-m-d H:i:s"),
            "username" => "TESTC3" . $ARGS["id"], "quote_ref" => "QREF-" . $ARGS["id"],
            "quote_items" => [["label" => "TEST Plan", "quantity" => 1, "price" => 100.0, "unit" => "month"]]]);
        qcj_out(true);', ['id' => $id, 'ph' => $phone]);
};
qc_fake($O12);
$ug = qc_book('uganda');
$pendingApp($ug, 711, '+256700000164');
$r = qcj_post($ug, 'post_kyc.php', ['action' => 'kyc_crm_retry', 'app_id' => '711'], (int)$ug['admin']);
is_(($r['flash'][0]['type'] ?? '') === 'success' && !empty(qc_app($ug, 711)['quote_created']),
    'C3 control: the retry creates the client in organization 1 and makes its quote; green', qc_j($r['flash']));
qc_fake($O12, [], ['ignores_org' => true]);
$ug = qc_book('uganda');
$pendingApp($ug, 712, '+256700000165');
$n0 = count(qc_log());
$r = qcj_post($ug, 'post_kyc.php', ['action' => 'kyc_crm_retry', 'app_id' => '712'], (int)$ug['admin']);
$msg = (string)($r['flash'][0]['msg'] ?? '');
is_(($r['flash'][0]['type'] ?? '') === 'warning' && strpos($msg, 'is now in the CRM as client #901') !== false && strpos($msg, $K1) !== false,
    'C3 an admin\'s Retry, uCRM files the client under 2: the message carries K and the flash is amber, not green', qc_j($r['flash']));
t('C3 … no quote was posted', $cnt(qc_reqs($n0), 'POST /clients/901/quotes'), 0);
qc_fake($O12, [], ['ignores_org' => true]);
$ug = qc_book('uganda');
$pendingApp($ug, 713, '+256700000166');
$rr = qcj_php($ug, '
    require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
    require_once "$TREE/lib/CrmApiClient.php"; require_once "$TREE/lib/KycCrmSync.php";
    $s = SqliteStore::create($DATA); $cfg = $s->load("kyc_config.json") ?: [];
    qcj_out((new KycCrmSync($s, CrmApiClient::fromUcrm($TREE, $cfg), $cfg))->syncOne(713, false, "admin:TEST"));');
t('C3 … KycCrmSync::syncOne()\'s result carries quote_refused = K', $rr['out']['quote_refused'] ?? null, $K1);
qc_fake($O12, [], ['ignores_org' => true]);
$ug = qc_book('uganda');
$pendingApp($ug, 714, '+256700000167');
[$rc] = qcj_script($ug, 'cron/kyc_crm_sync.php');
$app = qc_app($ug, 714);
t('C3 runDue (the cron): the fields are written on the application', [$rc, $app['quote_refused'] ?? null, $app['quote_refused_text'] ?? null, !empty($app['quote_refused_at'])],
    [0, 'R1', $K1, true]);
[$srv, $base] = qcj_serve_plugin($ug);
$jar = $ug['dir'] . '/jar.txt';
$page = ($srv !== null && qcj_login($base, $jar, 'admin@example.test')) ? qcj_http('GET', "{$base}?page=dashboard&tab=applications", null, [], $jar)[1] : '';
qcj_stop($srv);
is_(strpos($page, '⚠ No quotation was made') !== false && strpos($page, htmlspecialchars($K1, ENT_QUOTES)) !== false,
    'C3 … and Orders shows "⚠ No quotation was made" with the text (the only place a cron refusal is seen)', 'page bytes ' . strlen($page));
// Then the customer is moved to organization 1 and an admin's debug retry_quote makes the quote: Orders no longer says
// that no quotation was made (the refusal fields stay on the record; the block is drawn only while there is no quote).
qc_fake($O12, [qcj_client(901, 1, 'Retry')]);
qcj_php($ug, '
    require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
    $s = SqliteStore::create($DATA);
    $s->updateOne("kyc_applications.json", "id", 714, ["package_choice" => 1]);
    $c = $s->load("kyc_config.json") ?: []; $c["crm_auth_token"] = $ARGS["tok"]; $s->save("kyc_config.json", $c);
    qcj_out(true);', ['tok' => QCJ_TOKEN]);
[$srv, $base] = qcj_serve_plugin($ug);
$rq = $srv !== null ? qcj_api($base, $ug['tok_admin'], 'GET', 'retry_quote', null, '&app_id=714') : [0, '', null];
$jar = $ug['dir'] . '/jar2.txt';
$page = ($srv !== null && qcj_login($base, $jar, 'admin@example.test')) ? qcj_http('GET', "{$base}?page=dashboard&tab=applications", null, [], $jar)[1] : '';
qcj_stop($srv);
$app = qc_app($ug, 714);
is_($rq[0] === 200 && !empty($app['quote_created']) && ($app['quote_refused_text'] ?? null) === $K1 && strlen($page) > 10000
    && strpos($page, '⚠ No quotation was made') === false && strpos($page, htmlspecialchars($K1, ENT_QUOTES)) === false,
    'C3 … moved to organization 1, an admin\'s retry_quote makes the quote: Orders no longer shows "⚠ No quotation was made" for it',
    qc_j([$rq[0], $rq[2]['message'] ?? null, !empty($app['quote_created']), 'page bytes ' . strlen($page)]));

// C4 — Send Quotation, Lead (ticked, and unticked: C4u)
qc_fake($O12, [qcj_client(16, 1, 'Lead')]);
$ug = qc_book('uganda');
$seedLead($ug, 16);
$n0 = count(qc_log());
$r = $leadQ($ug, '1');
is_(($r['flash'][0]['type'] ?? '') === 'success' && $cnt(qc_reqs($n0), 'POST /billing/quotes') === 1 && count(qc_texts()) === 1,
    'C4 control: the lead\'s client 16 in organization 1, box ticked — one uCRM quote, one WhatsApp', qc_j([$r['flash'], qc_reqs($n0)]));
foreach (['C4' => '1', 'C4u' => '0'] as $id => $tick) {
    qc_fake($O12, [qcj_client(16, 2, 'Lead')]);
    $ug = qc_book('uganda');
    $seedLead($ug, 16);
    $d0 = qc_digest($ug, ['leads', 'quotes_log', 'wa_sent_quotes']); $n0 = count(qc_log());
    $r = $leadQ($ug, $tick);
    t("{$id} the lead's client 16 in organization 2, box " . ($tick === '1' ? 'ticked' : 'unticked') . ': "Quote failed: " + R1',
        $r['flash'], [['msg' => 'Quote failed: ' . qc_r1(QC_TWO, QC_ONE), 'type' => 'danger']]);
    t("{$id} … no POST billing/quotes (nothing written to uCRM), no WhatsApp, leads.json and quotes_log unchanged",
        [qc_writes(qc_reqs($n0)), qc_texts(), qc_digest($ug, ['leads', 'quotes_log', 'wa_sent_quotes'])], [[], [], $d0]);
}

// C5 — Send Quotation, Manual (ticked, and unticked: C5u)
qc_fake($O12, [qcj_client(17, 1, 'Manual')]);
$ug = qc_book('uganda');
$n0 = count(qc_log());
$r = $manual($ug, 17, '0');
$rq = qc_reqs($n0); $tx = qc_texts();
is_(($r['flash'][0]['type'] ?? '') === 'success' && $cnt($rq, 'GET /clients/17') === 1 && $cnt($rq, 'POST /billing/quotes') === 0
    && count($tx) === 1 && strpos((string)$tx[0]['text'], 'TEST Co One') !== false,
    'C5u control: client 17 in organization 1, box unticked — one GET clients/17 in all (the name and phone come from the check\'s row), a plugin-only quote letter-headed with organization 1',
    qc_j([$r['flash'], $rq, $tx]));
foreach (['C5' => '1', 'C5u' => '0'] as $id => $tick) {
    qc_fake($O12, [qcj_client(17, 2, 'Manual')]);
    $ug = qc_book('uganda');
    $d0 = qc_digest($ug, ['quotes_log', 'wa_sent_quotes']); $n0 = count(qc_log());
    $r = $manual($ug, 17, $tick);
    t("{$id} client 17 in organization 2, box " . ($tick === '1' ? 'ticked' : 'unticked') . ': "Failed: " + R1',
        $r['flash'], [['msg' => 'Failed: ' . qc_r1(QC_TWO, QC_ONE), 'type' => 'danger']]);
    t("{$id} … nothing written to uCRM, no WhatsApp, quotes_log unchanged", [qc_writes(qc_reqs($n0)), qc_texts(), qc_digest($ug, ['quotes_log', 'wa_sent_quotes'])], [[], [], $d0]);
}

// C6 — the debug retry_quote, over HTTP
$c6 = function (int $org) {
    qc_fake(qcj_orgs([1, 2]), [qcj_client(18, $org, 'Retry')]);
    $ug = qc_book('uganda', ['crm_auth_token' => QCJ_TOKEN]);
    qcj_php($ug, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        $s = SqliteStore::create($DATA);
        $s->append("kyc_applications.json", ["id" => 804, "firstname" => "TEST", "lastname" => "Retry", "mobile" => "+256700000118",
            "crm_client_id" => "18", "crm_sync_status" => "synced", "status" => "synced", "package_choice" => 1, "quote_created" => false]);
        qcj_out(true);');
    return $ug;
};
$ug = $c6(1);
[$srv, $base] = qcj_serve_plugin($ug);
$r = $srv !== null ? qcj_api($base, $ug['tok_admin'], 'GET', 'retry_quote', null, '&app_id=804') : [0, '', null];
qcj_stop($srv);
is_($r[0] === 200 && strpos((string)($r[2]['message'] ?? ''), 'created') !== false, 'C6 control: an admin\'s retry_quote for client 18 in organization 1 — the quote is created', qc_j([$r[0], $r[2]]));
$ug = $c6(2);
[$srv, $base] = qcj_serve_plugin($ug);
$d0 = qc_digest($ug, ['kyc_applications']); $n0 = count(qc_log());
$r  = $srv !== null ? qcj_api($base, $ug['tok_admin'], 'GET', 'retry_quote', null, '&app_id=804') : [0, '', null];
$wN = qc_writes(qc_reqs($n0));
$r2 = $srv !== null ? qcj_api($base, $ug['tok_agent'], 'GET', 'retry_quote', null, '&app_id=804') : [0, '', null];
qcj_stop($srv);
t('C6 client 18 in organization 2: an admin gets 409 with R1, nothing written (uCRM or store)', [$r[0], $r[2]['message'] ?? null, $wN, qc_digest($ug, ['kyc_applications'])],
    [409, qc_r1(QC_TWO, QC_ONE), [], $d0]);
t('C6 … a non-admin gets 403 (the existing guard)', [$r2[0], $r2[2]['message'] ?? null], [403, 'Admin only.']);

// C7 — LTE registration with a uCRM id (createCrmQuote's other caller), and the two LTE screens
$c7 = function (int $org, string $phone) {
    qc_fake(qcj_orgs([1, 2]), [qcj_client(19, $org, 'Lte')]);
    $ug = qc_book('uganda');
    [$srv, $base] = qcj_serve_plugin($ug);
    $n0 = count(qc_log());
    $r = $srv !== null ? qcj_api($base, $ug['tok_admin'], 'POST', 'lte_create_subscriber', ['name' => 'TEST Lte', 'phone' => $phone, 'ucrm_id' => 19, 'amount_paid' => 300]) : [0, '', null];
    $pages = [];
    if ($srv !== null && qcj_login($base, $ug['dir'] . '/jar.txt', 'admin@example.test')) {
        foreach (['kyc_form' => 'form', 'lte_dashboard' => 'lte_dashboard'] as $k => $tab) {
            $pages[$k] = qcj_http('GET', "{$base}?page=dashboard&tab={$tab}", null, [], $ug['dir'] . '/jar.txt')[1];
        }
    }
    qcj_stop($srv);
    return [$r, qc_reqs($n0), qc_texts(), $pages];
};
[$r, $rq, $tx] = $c7(1, '+256700000191');
is_($r[0] === 200 && $cnt($rq, 'POST /billing/quotes') === 1 && !empty($r[2]['data']['_quote_ref']) && empty($r[2]['data']['_quote_error']),
    'C7 control: LTE registration for client 19 in organization 1 — the uCRM quote is made', qc_j([$r[0], $r[2]['data'] ?? null, $rq]));
[$r, $rq, $tx, $pages] = $c7(2, '+256700000192');
$qe = (string)($r[2]['data']['_quote_error'] ?? '');
t('C7 client 19 in organization 2: registered, no POST billing/quotes, no cash proforma sent in its place',
    [$r[0], $cnt($rq, 'POST /billing/quotes'), array_values(array_filter($tx, function ($t) { return strpos((string)$t['number'], '700000192') !== false; }))], [200, 0, []]);
is_(strpos($qe, 'Subscriber registered. ' . qc_r1(QC_TWO, QC_ONE)) === 0 && strpos($qe, 'CRM ID 19') !== false,
    'C7 … data._quote_error holds R1 and says to use Send Quotation → Manual with CRM ID 19', $qe);
$frag = "+(d.data._quote_error?' '+d.data._quote_error:'')";
is_(strpos($pages['kyc_form'] ?? '', $frag) !== false && strpos($pages['lte_dashboard'] ?? '', $frag) !== false,
    'C7 … and the two LTE screens (the KYC form and the LTE dashboard) show it on Uganda', qc_j(array_map('strlen', $pages)));

// C8 — failure modes, on Q1 (KYC existing) and Q4 (Manual, ticked)
$cases = [
    'client_down' => [['client_down' => true], 2, [], null, function ($id) { return QC_R4; }],
    'client_404'  => [['client_404' => true], 2, [], null, function ($id) { return qc_r3((string)$id); }],
    'client_401'  => [['client_401' => true], 2, [], null, function ($id) { return QC_R7; }],
    'client_403'  => [['client_403' => true], 2, [], null, function ($id) { return QC_R7; }],
    'orgs_down'   => [['orgs_down' => true], 2, [], null, function ($id) { return qc_r1('organization #2', 'organization #1'); }],
    'R5 (#5 not listed)' => [[], 2, ['ucrm_quote_organization_id' => '5'], null, function ($id) { return qc_r5(5); }],
    'R2 (no organizationId)' => [[], null, [], null, function ($id) { return qc_r2(QC_ONE); }],
    'R6 (invalid setting)'   => [[], 2, ['ucrm_quote_organization_id' => 'abc'], 0, function ($id) { return QC_R6; }],
];
foreach ($cases as $label => [$toggles, $org, $cfg, $wantReqs, $text]) {
    foreach (['Q1' => 15, 'Q4' => 17] as $q => $cid) {
        qc_fake($O12, [qcj_client($cid, $org, 'Fail')], $toggles);
        $ug = qc_book('uganda', $cfg);
        $d0 = qc_digest($ug); $n0 = count(qc_log());
        $r = $q === 'Q1' ? $kycEx($ug, (string)$cid) : $manual($ug, $cid, '1');
        $want = ($q === 'Q1' ? '' : 'Failed: ') . $text($cid);
        $rq = qc_reqs($n0);
        t("C8 {$q} {$label}: " . ($q === 'Q1' ? 'R' : 'Failed: R') . "… exactly, nothing written to uCRM or the store, nothing sent",
            [$r['flash'], qc_writes($rq), qc_digest($ug), qc_texts()], [[['msg' => $want, 'type' => 'danger']], [], $d0, []]);
        if ($wantReqs !== null) t("C8 {$q} {$label}: zero requests", count($rq), $wantReqs);
    }
}

// C9 — the check reads with the client the quote is made with
$c9 = function (array $cfg, array $whoExtra) use ($kycEx, $O12) {
    qc_fake($O12, [qcj_client(15, 1, 'Existing')]);
    $ug = qc_book('uganda', $cfg);
    $n0 = count(qc_log());
    $kycEx($ug, '15', $whoExtra);
    $by = function ($m, $p) use ($n0) {
        foreach (array_slice(qc_log(), $n0) as $e) if ($e['method'] === $m && $e['path'] === $p) return $e['auth'] . ':' . $e['key'];
        return 'none';
    };
    return [$by('GET', '/clients/15'), $by('POST', '/clients/15/quotes')];
};
t('C9 crm_auth_token set: the check\'s GET carries the token, like the POST', $c9(['crm_auth_token' => QCJ_TOKEN], []), ['token:' . QCJ_TOKEN, 'token:' . QCJ_TOKEN]);
t('C9 no token: both carry the plugin\'s own key', $c9([], []), ['app-key:' . QCJ_APP_KEY, 'app-key:' . QCJ_APP_KEY]);
t('C9 an agent with a personal uCRM key (post_kyc.php): both carry that agent\'s key', $c9([], ['ucrm_app_key' => 'QC-AGENT-KEY-TEST']),
    ['app-key:QC-AGENT-KEY-TEST', 'app-key:QC-AGENT-KEY-TEST']);

// C10 — organization names are cleaned before staff see them
$o = qcj_orgs([1, 2]); $o[1]['name'] = 'TEST <b>Co</b> & "X"';
qc_fake($o, [qcj_client(15, 2, 'Existing')]);
$ug = qc_book('uganda');
$msg = (string)($kycEx($ug, '15')['flash'][0]['msg'] ?? '');
is_($msg === qc_r1('TEST bCo/b  X (#2)', QC_ONE) && strpbrk($msg, '<&"') === false, 'C10 an organization named TEST <b>Co</b> & "X" reaches R1 as "TEST bCo/b  X (#2)", with no <, & or "', $msg);

// C11 — the log: ids and organization numbers, never a name, phone, amount or organization name
qc_fake($O12, [qcj_client(17, 2, 'Manual')]);
$ug = qc_book('uganda');
$pl = $T . '/data/plugin.log';
$pl0 = strlen((string)@file_get_contents($pl)); $el0 = strlen((string)@file_get_contents($ug['errlog']));
$manual($ug, 17, '1');
$plNew = substr((string)@file_get_contents($pl), $pl0);
$elNew = implode("\n", array_filter(explode("\n", substr((string)@file_get_contents($ug['errlog']), $el0)), function ($l) { return strpos($l, '[QuoteOrganization]') !== false; }));
$both = $plNew . "\n" . $elNew;
is_(strpos($plNew, 'no quote for client #17 (manual): filed under organization #2, quotes are made under #1') !== false
    && strpos($elNew, 'no quote for client #17 (manual): filed under organization #2, quotes are made under #1') !== false,
    'C11 the refusal is logged in PHP\'s log and the plugin\'s log, with the client id and the organization numbers', qc_j([$plNew, $elNew]));
is_(strpos($both, 'TEST Manual') === false && strpos(preg_replace('/\D/', '', $both), '700000117') === false && strpos($both, '1500') === false
    && strpos($both, 'TEST Co') === false, 'C11 … and names no customer, phone, amount or organization', $both);
qc_fake($O12, [qcj_client(17, 2, 'Manual')], ['client_raw' => true]);
$ug = qc_book('uganda');
$sink = qcj_php($ug, '
    require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
    require_once "$TREE/lib/CrmApiClient.php"; require_once "$TREE/lib/QuoteOrganization.php";
    $s = SqliteStore::create($DATA); $cfg = $s->load("kyc_config.json") ?: [];
    $crm = CrmApiClient::fromUcrm($TREE, $cfg);
    $crm->get("not/simulated/here");            // an earlier failure: lastError is now an HTTP 404 …
    $crm->get("organizations");                 // … and a later success, which CrmApiClient never clears it after
    $lines = [];
    $r = (new QuoteOrganization($crm, QuoteOrganization::setting($cfg, $DATA), function ($l) use (&$lines) { $lines[] = $l; }))->checkClient(17, "manual");
    qcj_out(["r" => $r, "lines" => $lines]);');
is_(($sink['out']['r']['code'] ?? '') === 'R4' && strpos((string)($sink['out']['lines'][0] ?? ''), 'not JSON') !== false
    && strpos((string)($sink['out']['lines'][0] ?? ''), '404') === false,
    'C11 a client read answered with something that is not JSON, after an earlier failed call: the line says "not JSON", not the stale error', qc_j($sink['out']));

// C12 — the CRM ID the agent typed: one canonical number for the check, the quote and the record
qc_fake($O12, [qcj_client(15, 1, 'Existing'), qcj_client(16, 1, 'Other')]);
// ("0" itself never reaches this path: KycService::process() routes an empty() customer_id to the new-customer form, as
// ce7ac388 does; "00" is the number-shaped zero that does.)
$nanLine = '[QuoteOrganization] no quote for a CRM ID that is not a number (kyc-existing): no such client';
foreach (['15/../16', 'abc', '1 6', '00'] as $bad) {
    $ug = qc_book('uganda');
    $n0 = count(qc_log());
    $pl0 = strlen((string)@file_get_contents($pl)); $el0 = strlen((string)@file_get_contents($ug['errlog']));
    $r = $kycEx($ug, $bad);
    t('C12 customer_id ' . qc_j($bad) . ': R3, zero requests, no application', [$r['flash'], count(qc_reqs($n0)), qc_apps($ug)],
        [[['msg' => qc_r3($bad), 'type' => 'danger']], 0, []]);
    // Logged where every other refusal is (the default sink): the plugin's log and PHP's, never the typed value.
    $tails = function (string $s): array {
        $o = [];
        foreach (explode("\n", $s) as $l) if (($i = strpos($l, '[QuoteOrganization]')) !== false) $o[] = substr($l, $i);
        return $o;
    };
    $plT = $tails(substr((string)@file_get_contents($pl), $pl0)); $elT = $tails(substr((string)@file_get_contents($ug['errlog']), $el0));
    t('C12 … logged once in data/plugin.log and once in PHP\'s log, as every refusal, naming no typed value',
        [$plT, $elT], [[$nanLine], [$nanLine]]);
}
$ug = qc_book('uganda');
$n0 = count(qc_log());
$kycEx($ug, '015');
$rq = qc_reqs($n0); $app = qc_apps($ug)[0] ?? [];
is_($cnt($rq, 'GET /clients/15') === 1 && $cnt($rq, 'POST /clients/15/quotes') === 1 && (string)($app['crm_client_id'] ?? '') === '15'
    && count(array_filter($rq, function ($x) { return strpos($x, '015') !== false || strpos($x, '/16') !== false; })) === 0,
    'C12 customer_id "015": the check, the quote and the stored crm_client_id all use 15', qc_j([$rq, $app['crm_client_id'] ?? null]));

// C13 — the log sink
qc_fake($O12, [qcj_client(17, 2, 'Manual')]);
$ug = qc_book('uganda');
$h0 = hash_file('sha256', $pl) ?: ''; $el0 = strlen((string)@file_get_contents($ug['errlog']));
$probe = '
    require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
    require_once "$TREE/lib/CrmApiClient.php"; require_once "$TREE/lib/QuoteOrganization.php";
    $s = SqliteStore::create($DATA); $cfg = $s->load("kyc_config.json") ?: [];
    $lines = [];
    $sink = $ARGS["inject"] ? function ($l) use (&$lines) { $lines[] = $l; } : null;
    $r = (new QuoteOrganization(CrmApiClient::fromUcrm($TREE, $cfg), QuoteOrganization::setting($cfg, $DATA), $sink))->checkClient(17, "deploy-check");
    qcj_out(["code" => $r["code"], "lines" => $lines]);';
$x = qcj_php($ug, $probe, ['inject' => true]);
clearstatcache();
is_(count($x['out']['lines'] ?? []) === 1 && strpos((string)$x['out']['lines'][0], 'no quote for client #17 (deploy-check)') !== false
    && (hash_file('sha256', $pl) ?: '') === $h0 && strpos(substr((string)@file_get_contents($ug['errlog']), $el0), '[QuoteOrganization]') === false,
    'C13 an injected sink receives the refusal line; data/plugin.log and PHP\'s log are untouched', qc_j($x['out']));
$x = qcj_php($ug, $probe, ['inject' => false]);
clearstatcache();
is_((hash_file('sha256', $pl) ?: '') !== $h0 && strpos((string)file_get_contents($pl), 'no quote for client #17 (deploy-check)') !== false
    && strpos(substr((string)@file_get_contents($ug['errlog']), $el0), 'no quote for client #17 (deploy-check)') !== false,
    'C13 control: the default sink writes both', qc_j($x['out']));

// C14 — what the check costs when the client is in the configured organization
qc_fake($O12, [qcj_client(15, 1, 'Existing'), qcj_client(17, 1, 'Manual')]);
$ug = qc_book('uganda');
$n0 = count(qc_log());
$kycEx($ug, '15');
$rq = qc_reqs($n0);
t('C14 Q1, client in organization 1: exactly one check request (GET clients/15), no GET organizations', [$cnt($rq, 'GET /clients/15'), $cnt($rq, 'GET /organizations')], [1, 0]);
$n0 = count(qc_log());
$manual($ug, 17, '1');
$rq = qc_reqs($n0);
$iP = array_search('POST /billing/quotes', $rq, true);
t('C14 Q4, client in organization 1: before the POST billing/quotes, exactly GET clients/17', $iP === false ? $rq : array_slice($rq, 0, $iP), ['GET /clients/17']);
}


// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if (qc_section('D')) {
echo "\nD. Item 3: UcrmLeadSync falls back to the configured organization when ucrm_lead_organization_id is unset\n";
/** An AI lead (#5) into uCRM, ai_crm_lead_sync on: the result, the lead row afterwards, and the requests. */
$sync = function (array $ctx, array $cfg = [], bool $seed = true): array {
    $n0 = count(qc_log());
    $r = qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        require_once "$TREE/lib/CrmApiClient.php"; require_once "$TREE/lib/LeadMatcher.php"; require_once "$TREE/lib/UcrmLeadSync.php";
        $s = SqliteStore::create($DATA);
        if ($ARGS["seed"]) $s->save("leads.json", [["id" => 5, "customer_name" => "TEST Ailead", "phone" => "+256700000185", "service_type" => "starlink", "status" => "open"]]);
        $cfg = array_merge($s->load("kyc_config.json") ?: [], ["ai_crm_lead_sync" => "1"], $ARGS["cfg"]);
        $r = (new UcrmLeadSync($s, $cfg, CrmApiClient::fromUcrm($TREE, $cfg), $s->getPdo()))->syncLead(5);
        qcj_out(["r" => $r, "lead" => $s->findOne("leads.json", "id", 5)]);', ['cfg' => $cfg, 'seed' => $seed]);
    return (is_array($r['out']) ? $r['out'] : ['r' => null, 'raw' => $r['raw']]) + ['reqs' => qc_reqs($n0), 'log' => array_slice(qc_log(), $n0)];
};
$postOrg = function (array $x): array {
    return array_values(array_map(function ($e) { return $e['body']['organizationId'] ?? null; },
        array_filter($x['log'], function ($e) { return $e['method'] === 'POST' && $e['path'] === '/clients'; })));
};
// The sampled clients are mostly in organization 2: "the commonest of 50" says 2.
$sample = function (array $orgsOfClients): array {
    $out = []; $i = 30;
    foreach ($orgsOfClients as $o) { $c = qcj_client($i++, $o, 'Sample'); $c['countryId'] = 186; $out[] = $c; }
    return $out;
};
qc_fake(qcj_orgs([2, 7]), $sample([2, 2, 2, 7]));
$x = $sync(qc_book('south-sudan'));
is_(($x['r']['action'] ?? '') === 'created' && $postOrg($x) === [2] && !isset($x['lead']['crm_sync_flag']),
    'D control: South Sudan — the commonest of the sampled clients (organization 2), no flag', qc_j([$x['r'], $postOrg($x)]));
qc_fake(qcj_orgs([1, 2]), $sample([2, 2, 2, 1]));
$x = $sync(qc_book('uganda', ['ucrm_quote_organization_id' => '0']));
is_(($x['r']['action'] ?? '') === 'created' && $postOrg($x) === [2] && !isset($x['lead']['crm_sync_flag']),
    'D control: Uganda switched off — the commonest (organization 2), as ce7ac388', qc_j([$x['r'], $postOrg($x)]));
qc_fake(qcj_orgs([1, 2]), $sample([2, 2, 2, 1]));
$x = $sync(qc_book('uganda'));
$beforePost = array_slice($x['reqs'], 0, (int)array_search('POST /clients', $x['reqs'], true));
$lookups = array_values(array_filter($beforePost, function ($r) { return strpos($r, 'GET /clients?phone=') !== 0 && strpos($r, 'GET /clients?search=') !== 0; }));
is_(($x['r']['action'] ?? '') === 'created' && $postOrg($x) === [1], 'D Uganda, the lead key unset, the samples mostly in 2: the client is created in organization 1',
    qc_j([$x['r'], $postOrg($x)]));
t('D … after the phone lookups: GET organizations, then GET clients?limit=50 (for the country, as before)', $lookups, ['GET /organizations', 'GET /clients?limit=50']);
qc_fake(qcj_orgs([1, 2]), $sample([2, 2, 2, 1]));
$ug5 = qc_book('uganda', ['ucrm_quote_organization_id' => '5']);
$pl = $T . '/data/plugin.log'; $pl0 = strlen((string)@file_get_contents($pl));
$x = $sync($ug5);
$plNew = substr((string)@file_get_contents($pl), $pl0);
$why5 = 'uCRM has no organization 5, the company Uganda customers are filed under';
t('D the configured organization 5 is not in uCRM: skipped with that reason, nothing created',
    [$x['r']['ok'] ?? null, $x['r']['action'] ?? null, $x['r']['reason'] ?? null, $postOrg($x)], [false, 'skipped', $why5, []]);
t('D … the lead carries crm_sync_flag quote_org_missing', [$x['lead']['crm_sync_flag'] ?? null, $x['lead']['crm_sync_error'] ?? null], ['quote_org_missing', $why5]);
is_(substr_count($plNew, "\n") === 1 && strpos($plNew, 'lead #5 not synced: ' . $why5) !== false && strpos($plNew, 'TEST Ailead') === false
    && strpos(preg_replace('/\D/', '', $plNew), '700000185') === false, 'D … and data/plugin.log gains one line, naming only the lead id', $plNew);
// The same book, the setting corrected: the next sync creates the client, and clears the flag.
qcj_php($ug5, '
    require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
    $s = SqliteStore::create($DATA); $c = $s->load("kyc_config.json") ?: []; unset($c["ucrm_quote_organization_id"]); $s->save("kyc_config.json", $c);
    qcj_out(true);');
$x = $sync($ug5, [], false);
is_(($x['r']['action'] ?? '') === 'created' && $postOrg($x) === [1] && !isset($x['lead']['crm_sync_flag']),
    'D … with the setting corrected, a later sync creates it in organization 1 and clears the flag', qc_j([$x['r'], $x['lead']['crm_sync_flag'] ?? null]));
// A setting that is not an organization number: the same refusal, its own reason, and uCRM's organizations not asked for.
qc_fake(qcj_orgs([1, 2]), $sample([2, 2, 2, 1]));
$ugX = qc_book('uganda', ['ucrm_quote_organization_id' => 'abc']);
$pl0 = strlen((string)@file_get_contents($pl));
$x = $sync($ugX);
$plNew = substr((string)@file_get_contents($pl), $pl0);
$whyX = 'the setting ucrm_quote_organization_id is not an organization number';
t('D an invalid setting (abc): skipped with "not an organization number", nothing created, no GET organizations',
    [$x['r']['ok'] ?? null, $x['r']['action'] ?? null, $x['r']['reason'] ?? null, $postOrg($x), in_array('GET /organizations', $x['reqs'], true)],
    [false, 'skipped', $whyX, [], false]);
t('D … the lead carries crm_sync_flag quote_org_missing with that reason, and data/plugin.log gains that one line',
    [$x['lead']['crm_sync_flag'] ?? null, $x['lead']['crm_sync_error'] ?? null, substr_count($plNew, "\n"), strpos($plNew, 'lead #5 not synced: ' . $whyX) !== false],
    ['quote_org_missing', $whyX, 1, true]);
qc_fake(qcj_orgs([1, 2]), $sample([2, 2, 2, 1]), ['orgs_down' => true]);
$x = $sync(qc_book('uganda'));
t('D organizations unreadable: failed (retryable), nothing created, flagged crm_unreachable',
    [$x['r']['action'] ?? null, $postOrg($x), $x['lead']['crm_sync_flag'] ?? null], ['failed', [], 'crm_unreachable']);
qc_fake(qcj_orgs([1, 2, 7]), $sample([2, 2, 2, 1]));
$x = $sync(qc_book('uganda', ['ucrm_lead_organization_id' => '7']));
is_(($x['r']['action'] ?? '') === 'created' && $postOrg($x) === [7] && !in_array('GET /organizations', $x['reqs'], true),
    'D an explicit ucrm_lead_organization_id=7 still wins: created in 7, with no GET organizations (ce7ac388\'s behaviour)', qc_j([$x['r'], $x['reqs']]));
}

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if (qc_section('E')) {
echo "\nE. Item 4: the WhatsApp quotation names the client's own organization\n";
/** One QuotationService call in a fresh process on $ctx; the WhatsApp texts the fake Evolution received for it. */
$qs = function (array $ctx, string $call, array $args = []): array {
    global $EVO;
    qcj_http('GET', "{$EVO}/__test/reset");
    $r = qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        require_once "$TREE/lib/currency.php"; require_once "$TREE/lib/QuotationService.php";
        $s = SqliteStore::create($DATA); $cfg = $s->load("kyc_config.json") ?: [];
        $q = new QuotationService($s, $DATA, $cfg);
        $agent = ["id" => 7, "name" => "TEST Agent"];
        $items = $ARGS["items"];
        switch ($ARGS["call"]) {
            case "lead":    $out = $q->sendLeadQuote(["id" => 1, "customer_name" => "TEST Lead", "phone" => "+256700000116", "crm_client_id" => (int)$ARGS["cid"], "service_type" => "starlink"], $items, $agent, false); break;
            case "manual":  $out = $q->sendManualQuote(["crm_client_id" => (int)$ARGS["cid"], "customer_name" => "", "customer_phone" => "", "items" => $items, "create_crm_quote" => false], $agent); break;
            case "kyc":     $out = $q->sendKycQuoteWhatsApp(["firstname" => "TEST", "lastname" => "Kycapp", "mobile" => "+256700000120", "crm_client_id" => (string)$ARGS["cid"], "quote_ref" => "Q-3402"], $items, 3402, $agent); break;
            case "cash":    $out = $q->sendCashSaleProforma("TEST Walkin", "+256700000171", $items, 500.0, $agent); break;
            case "preview": $out = $q->buildProformaMessage("PREVIEW-001", "TEST Preview", $items, 1500.0, ["type" => "Quotation", "agent" => "TEST Agent"]); break;
        }
        qcj_out($out);', $args + ['call' => $call, 'items' => QC_ITEMS]);
    return ['out' => $r['out'], 'texts' => array_column(qc_texts(), 'text'), 'raw' => $r['raw']];
};
$text = function (array $x): string { return $x['texts'][0] ?? (is_string($x['out']) ? $x['out'] : ''); };
$o12 = qcj_orgs([1, 2]); $o12[1]['selected'] = true;      // uCRM marks organization 2 selected
$one = ['TEST Co One', '+256700000101', 'org1@example.test'];
$names = function (string $t, array $who): bool { foreach ($who as $w) if (strpos($t, $w) === false) return false; return true; };
/** The lines of $a and $b that differ, by index; the same count of lines is required. */
$diffLines = function (string $a, string $b): ?array {
    $x = explode("\n", $a); $y = explode("\n", $b);
    if (count($x) !== count($y)) return null;
    $d = [];
    foreach ($x as $i => $l) if ($l !== $y[$i]) $d[] = $i;
    return $d;
};
// Controls: South Sudan, organization 7 selected — the selected organization, exactly as ce7ac388.
$o27 = qcj_orgs([2, 7]); $o27[1]['selected'] = true;
foreach (['up' => [], 'orgs_down' => ['orgs_down' => true]] as $label => $tg) {
    qc_fake($o27, [qcj_client(17, 2, 'Manual')], $tg);
    $new = $text($qs(qc_book('south-sudan'), 'manual', ['cid' => 17]));
    $old = $TB !== null ? $text($qs(qc_book('south-sudan', [], [], $TB), 'manual', ['cid' => 17])) : null;
    is_($old !== null && $new === $old && $new !== '' && ($label === 'up' ? strpos($new, 'TEST Co Seven') !== false : strpos($new, '+211') !== false),
        'E control: South Sudan, ' . ($label === 'up' ? 'organization 7 selected — the selected organization' : 'organizations unreadable — the built-in constants') . ', byte for byte as ce7ac388',
        qc_j([$new, $old]));
}
// Uganda: the lead, manual and KYC WhatsApps for a client in organization 1 name organization 1, and differ from
// ce7ac388's text only in the lines that carry the company.
foreach (['lead' => 16, 'manual' => 17, 'kyc' => 20] as $call => $cid) {
    qc_fake($o12, [qcj_client($cid, 1, 'Client')]);
    $new = $text($qs(qc_book('uganda'), $call, ['cid' => $cid]));
    qc_fake($o12, [qcj_client($cid, 1, 'Client')]);
    $old = $TB !== null ? $text($qs(qc_book('uganda', [], [], $TB), $call, ['cid' => $cid])) : '';
    $d = $diffLines($new, $old);
    $company = [];
    foreach (explode("\n", $new) as $i => $l) foreach ($one as $w) if (strpos($l, $w) !== false) { $company[] = $i; break; }
    is_($names($new, $one) && strpos($new, 'TEST Co Two') === false, "E {$call}: the WhatsApp names organization 1's TEST name, phone and e-mail", $new);
    is_($d !== null && $d === $company && count($d) >= 3, "E {$call}: … and equals ce7ac388's text except those lines (" . qc_j($d) . ')', qc_j([$new, $old]));
}
// Every caller passes the quoted client as letterhead_client_id (SPEC §2.4's table). On Uganda a Lead or Manual quote
// reaches its letterhead only after the check passed, so the client's organization IS the configured one there and the
// text cannot tell the option from its absence; the five calls are therefore read from the code as well.
$lh = [];
foreach (['lib/QuotationService.php' => ["'letterhead_client_id' => (int)(\$application['crm_client_id'] ?? 0),", "'letterhead_client_id' => \$crmClientId,", "'letterhead_client_id' => \$crmClientId,"],
          'cron_quote_wa.php' => ["'letterhead_client_id' => (int)(\$app['crm_client_id'] ?? 0),", "'letterhead_client_id' => \$clientId,"]] as $rel => $want) {
    $src = (string)file_get_contents($T . '/' . $rel);
    preg_match_all("#'letterhead_client_id' => [^\n]*?,(?=\s*//|\s*\n)#", $src, $m);
    $lh[$rel] = $m[0];
    t("E {$rel}: the letterhead_client_id it passes (SPEC §2.4: sendKycQuoteWhatsApp, sendLeadQuote, sendManualQuote; Flow A, Flow B)", $m[0], $want);
}
// Flow B: a quote made in uCRM for a client in organization 2 is that company's quote, and names it.
qc_fake($o12, [qcj_client(15, 2, 'Existing')], ['quotes' => ['3401' => ['id' => 3401, 'number' => 'Q-3401', 'clientId' => 15, 'status' => 1,
    'createdDate' => date('c'), 'items' => [['label' => 'TEST Kit', 'quantity' => 1, 'price' => 1500.0, 'total' => 1500.0]], 'total' => 1500.0]]]);
$ug = qc_book('uganda');
[$rc] = qcj_script($ug, 'cron_quote_wa.php');
$tx = implode("\n---\n", array_column(qc_texts(), 'text'));
is_($rc === 0 && strpos($tx, 'Q-3401') !== false && strpos($tx, 'TEST Co Two') !== false && strpos($tx, 'TEST Co One') === false,
    'E cron Flow B: a uCRM-made quote for a client in organization 2 names organization 2', $tx);
qc_fake($o12);
is_($names($text($qs(qc_book('uganda'), 'cash')), $one), 'E a Cash Proforma (no client), organization 2 marked selected: names organization 1, never "whichever is selected"');
qc_fake($o12);
is_($names($text($qs(qc_book('uganda'), 'kyc', ['cid' => 999])), $one), 'E an unreadable client (#999, not in uCRM): falls back to organization 1');
qc_fake($o12);
is_($names($text($qs(qc_book('uganda'), 'preview')), $one), 'E the preview (no client): names organization 1');
// Organizations unreadable: the Uganda profile is the last fallback, in the WhatsApp and in the quotation e-mail's Reply-To.
$prof = ['DishNet Africa Limited', '+256 705 993 348', 'accounts@dishnetuganda.com'];
qc_fake($o12, [qcj_client(17, 1, 'Manual')], ['orgs_down' => true]);
$ug = qc_book('uganda');
[$smtp, $tr] = qc_smtp($ug);
$n0 = count(qc_log());
$r = qcj_post($ug, 'post_leads.php', ['action' => 'send_manual_quote', 'mq_crm_client_id' => '17', 'mq_customer_name' => '', 'mq_customer_phone' => '',
    'mq_create_crm' => '1', 'mq_note' => '', 'mq_items_json' => json_encode(QC_ITEMS)], (int)$ug['agent']);
$wa = implode("\n", array_column(qc_texts(), 'text'));
$mails = qc_mails($tr); qcj_stop($smtp);
is_($names($wa, $prof) && strpos($wa, '+211') === false && strpos($wa, 'dishnetafrica.com') === false,
    'E organizations unreadable: the WhatsApp holds the Uganda profile\'s name, phone and reply-to, no +211 and no dishnetafrica.com', $wa);
is_(count($mails) === 1 && $mails[0]['reply_to'] === 'accounts@dishnetuganda.com',
    'E … and the quotation e-mail (quote_email_via_plugin on) has the profile\'s reply-to as its Reply-To', qc_j([$r['flash'], array_map(function ($m) { return [$m['to'], $m['reply_to']]; }, $mails)]));
qc_fake($o12, [qcj_client(17, 1, 'Manual')], ['orgs_down' => true]);
$wa = $text($qs(qc_book('uganda', ['quote_company_phone' => '+256700000177']), 'manual', ['cid' => 17]));
is_(strpos($wa, '+256700000177') !== false && strpos($wa, '+256 705 993 348') === false, 'E … with quote_company_phone set, that value wins over the profile', $wa);
}

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if (qc_section('F')) {
echo "\nF. Item 5: the plugin's fallback quote PDF on Uganda\n";
$forbidden = ['Juba', 'South Sudan', '+211', '0925831111', 'dishnetafrica.com', 'OPP Ministries', 'Yash Madlani', 'Amount (USD)'];
$quote3401 = function (int $client): array {
    return ['3401' => ['id' => 3401, 'number' => 'Q-3401', 'clientId' => $client, 'status' => 1, 'createdDate' => '2026-10-20T08:00:00+0300',
        'items' => [['label' => 'TEST Kit', 'quantity' => 1, 'price' => 1500.0, 'total' => 1500.0, 'unit' => 'piece']], 'total' => 1500.0,
        'notes' => 'TEST note {{FOOTER_LOCALITY}} kept literally']];
};
/** QuotePdfSource::fetch in a fresh process: the source, and the HTML the PDF service was handed. */
$fetch = function (array $ctx): array {
    global $FAKE;
    $p0 = count((array)(qcj_fake_state($FAKE)['pdf_html'] ?? [])); $n0 = count(qc_log());
    $r = qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        require_once "$TREE/lib/CrmApiClient.php"; require_once "$TREE/lib/QuotePdfSource.php";
        $s = SqliteStore::create($DATA); $cfg = $s->load("kyc_config.json") ?: [];
        $crm = CrmApiClient::fromUcrm($TREE, $cfg);
        [$pdf, $src] = QuotePdfSource::fetch($crm, $DATA, $cfg, 3401, $crm->get("clients/15") ?: []);
        qcj_out(["bytes" => strlen($pdf), "src" => $src]);');
    $html = array_slice((array)(qcj_fake_state($FAKE)['pdf_html'] ?? []), $p0);
    return ['out' => $r['out'], 'html' => (string)($html[0] ?? ''), 'n' => count($html), 'reqs' => qc_reqs($n0)];
};
/** The FROM block a PDF must carry for one of qcj_orgs()'s organizations: its TEST name, street, city, phone, e-mail, website. */
$fromOf = function (int $id): array {
    $o = qcj_orgs([$id])[0];
    return [$o['name'], implode('<br>', [$o['street1'], $o['city'], $o['phone'], $o['email'], $o['website']])];
};
$check = function (string $label, string $html, int $org = 1) use ($forbidden, $fromOf) {
    $fromBlock = preg_match('#FROM</div>\s*<div[^>]*>(.*?)</div>\s*<div[^>]*>(.*?)</div>#s', $html, $m) ? [$m[1], $m[2]] : ['', ''];
    is_($html !== '' && $fromBlock === $fromOf($org),
        "F {$label}: the FROM block holds organization {$org}'s TEST name, street, city, phone, e-mail and website", qc_j($fromBlock));
    is_(substr_count($html, 'Kampala, Uganda') === 2 && strpos($html, 'Amount (UGX)') !== false
        && preg_match('#<span style="font-size:9px;color:\#6B6B6B;float:right;">' . preg_quote($fromOf($org)[0], '#') . '</span>#', $html) === 1,
        "F {$label}: Kampala, Uganda twice, Amount (UGX), and the organization under Prepared by", 'Kampala×' . substr_count($html, 'Kampala, Uganda'));
    t("F {$label}: none of Juba, South Sudan, +211, 0925831111, dishnetafrica.com, OPP Ministries, Yash Madlani, Amount (USD)",
        array_values(array_filter($forbidden, function ($w) use ($html) { return strpos($html, $w) !== false; })), []);
};
// Controls: South Sudan's fallback HTML is ce7ac388's, byte for byte; uCRM serving a PDF on Uganda costs no extra request.
qc_fake(qcj_orgs([2, 7]), [qcj_client(15, 2, 'Existing')], ['quotes' => $quote3401(15)]);
$new = $fetch(qc_book('south-sudan'));
qc_fake(qcj_orgs([2, 7]), [qcj_client(15, 2, 'Existing')], ['quotes' => $quote3401(15)]);
$old = $TB !== null ? $fetch(qc_book('south-sudan', [], [], $TB)) : ['html' => null, 'reqs' => null];
is_($new['html'] !== '' && $new['html'] === $old['html'] && $new['reqs'] === $old['reqs'],
    'F control: South Sudan — the fallback HTML and the requests are ce7ac388\'s, byte for byte', 'bytes ' . strlen($new['html']) . ' / ' . strlen((string)$old['html']));
is_(strpos($new['html'], 'TEST note {{FOOTER_LOCALITY}} kept literally') !== false, 'F … a client\'s notes holding {{FOOTER_LOCALITY}} keep it literally (South Sudan)');
qc_fake(qcj_orgs([1, 2]), [qcj_client(15, 1, 'Existing')], ['quotes' => $quote3401(15), 'pdf_serve' => true]);
$x = $fetch(qc_book('uganda'));
t('F control: uCRM serves the PDF on Uganda — it is used, and no GET organizations follows', [$x['out']['src'] ?? null, $x['n'], in_array('GET /organizations', $x['reqs'], true)],
    ['ucrm', 0, false]);
// Uganda, the direct fetch: the HTML, and the organization read only after uCRM served no PDF.
qc_fake(qcj_orgs([1, 2]), [qcj_client(15, 1, 'Existing')], ['quotes' => $quote3401(15)]);
$x = $fetch(qc_book('uganda'));
$check('QuotePdfSource::fetch', $x['html']);
is_(strpos($x['html'], 'TEST note {{FOOTER_LOCALITY}} kept literally') !== false, 'F … the notes holding {{FOOTER_LOCALITY}} keep it literally (Uganda): the five placeholders are replaced first');
$lastPdf = -1; $firstOrg = -1;
foreach ($x['reqs'] as $i => $rq) { if (preg_match('#/pdf$#', $rq)) $lastPdf = $i; if ($rq === 'GET /organizations' && $firstOrg < 0) $firstOrg = $i; }
is_($lastPdf > 0 && $firstOrg > $lastPdf, 'F … GET organizations comes only after uCRM\'s PDF answered 404 on both paths', qc_j($x['reqs']));
// An organization with no phone: the line is absent, not empty.
$o = qcj_orgs([1, 2]); $o[0]['phone'] = '';
qc_fake($o, [qcj_client(15, 1, 'Existing')], ['quotes' => $quote3401(15)]);
$x = $fetch(qc_book('uganda'));
is_(strpos($x['html'], 'Plot 1 Test Road<br>Testville 1<br>org1@example.test<br>org1.example.test</div>') !== false && strpos($x['html'], '<br><br>') === false,
    'F an organization with no phone: its line is left out', '');
// The client's own organization (SPEC §2.5): client 15 filed under organization 2 while the configured one is 1 — a
// quotation made in uCRM for that client is that company's, and its PDF names organization 2, not the configured one.
qc_fake(qcj_orgs([1, 2]), [qcj_client(15, 2, 'Existing')], ['quotes' => $quote3401(15)]);
$x = $fetch(qc_book('uganda'));
$check('QuotePdfSource::fetch, client 15 in organization 2 (configured 1)', $x['html'], 2);
// The organizations cannot be read: the PDF is still drawn, its organization lines are left out (no empty line), and
// nothing of South Sudan stands in for them.
qc_fake(qcj_orgs([1, 2]), [qcj_client(15, 1, 'Existing')], ['quotes' => $quote3401(15), 'orgs_down' => true]);
$x = $fetch(qc_book('uganda'));
$fromDown = preg_match('#FROM</div>\s*<div[^>]*>(.*?)</div>\s*<div[^>]*>(.*?)</div>#s', $x['html'], $m) ? [$m[1], $m[2]] : null;
t('F organizations unreadable: drawn, with no organization line in the FROM block and no empty <br> line, Kampala, Uganda twice, and none of ' . implode(', ', $forbidden),
    [$x['html'] !== '', $fromDown !== null && trim((string)$fromDown[0]) !== '' && strpos((string)$fromDown[0], 'TEST Co') === false, $fromDown[1] ?? null,
     strpos($x['html'], '<br><br>') === false, substr_count($x['html'], 'Kampala, Uganda'),
     array_values(array_filter($forbidden, function ($w) use ($x) { return strpos($x['html'], $w) !== false; }))],
    [true, true, '', true, 2, []]);
// Through the app path (QuotationService::emailQuotePdf, quote_email_via_plugin on).
qc_fake(qcj_orgs([1, 2]), [qcj_client(17, 1, 'Manual')]);
$ug = qc_book('uganda');
[$smtp, $tr] = qc_smtp($ug);
$p0 = count((array)(qcj_fake_state($FAKE)['pdf_html'] ?? []));
qcj_post($ug, 'post_leads.php', ['action' => 'send_manual_quote', 'mq_crm_client_id' => '17', 'mq_customer_name' => '', 'mq_customer_phone' => '',
    'mq_create_crm' => '1', 'mq_note' => '', 'mq_items_json' => json_encode(QC_ITEMS)], (int)$ug['agent']);
qcj_stop($smtp);
$check('emailQuotePdf (the Manual quote, the plugin e-mails it)', (string)(array_slice((array)(qcj_fake_state($FAKE)['pdf_html'] ?? []), $p0)[0] ?? ''));
// Through uCRM's quote.add (the webhook's whQuotationEmail, the quotation customer-email switch on): client 15 in
// organization 1, then — the realistic case of SPEC §2.5 — a quotation typed in uCRM's own screen for client 15 filed
// under organization 2, the configured one being 1.
foreach ([1 => '', 2 => ', client 15 in organization 2 (configured 1)'] as $corg => $tag) {
    qc_fake(qcj_orgs([1, 2]), [qcj_client(15, $corg, 'Existing')], ['quotes' => $quote3401(15)]);
    $ug = qc_book('uganda', ['customer_emails_enabled' => '1', 'customer_email_quotation' => '1']);
    [$srv, $base] = qcj_serve_plugin($ug);
    $p0 = count((array)(qcj_fake_state($FAKE)['pdf_html'] ?? []));
    $fire = $srv !== null ? qcj_http('POST', "{$base}?page=crm_webhook", json_encode(['changeType' => 'quote.add', 'entity' => 'quote', 'entityId' => 3401,
        'uuid' => 'qc-3401', 'extraData' => ['entity' => ['id' => 3401]]]), ['Content-Type: application/json', 'X-Ucrm-Key: qc-webhook-secret']) : [0, ''];
    if ($srv !== null) qcj_http('GET', "{$base}?page=login");   // php -S answers one request at a time: the webhook has finished
    qcj_stop($srv);
    $html = (string)(array_slice((array)(qcj_fake_state($FAKE)['pdf_html'] ?? []), $p0)[0] ?? '');
    is_($fire[0] === 200, 'F quote.add is accepted' . $tag, (string)$fire[0]);
    $check('whQuotationEmail (uCRM\'s quote.add)' . $tag, $html, $corg);
}
}

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if (qc_section('G')) {
echo "\nG. Item 6: push_products_to_ucrm (\"Sync All\") is admin-only, on every tenant\n";
foreach (['uganda', 'south-sudan'] as $tenant) {
    qc_fake(qcj_orgs([1, 2]));
    // The handler builds its client from crm_base_url and crm_auth_token (CrmApiClient::fromUcrm on includes/, where no
    // ucrm.json is), so the book names them: past the gate it really reads uCRM's products.
    $b = qc_book($tenant, ['crm_base_url' => $FAKE . '/api/v2.1', 'crm_auth_token' => QCJ_TOKEN]);
    [$srv, $base] = qcj_serve_plugin($b);
    if ($srv === null) { is_(false, "G {$tenant}: the plugin started"); continue; }
    $prod = function () { return count(array_filter(qc_reqs(0), function ($r) { return strpos($r, '/products') !== false; })); };
    $p0 = $prod();
    $ad = qcj_api($base, $b['tok_admin'], 'GET', 'push_products_to_ucrm', null, '&dry_run=1');
    is_($ad[0] !== 403 && $ad[0] !== 401 && $prod() > $p0, "G {$tenant} control: an admin gets past the gate (the fake logs GET products)", qc_j([$ad[0], substr($ad[1], 0, 200)]));
    foreach (['0', '1'] as $dry) {
        $p0 = $prod();
        $r = qcj_api($base, $b['tok_agent'], 'GET', 'push_products_to_ucrm', null, '&dry_run=' . $dry);
        t("G {$tenant}: a non-admin, dry_run={$dry} → 403 {\"status\":\"error\",\"message\":\"Admin only.\"}, and uCRM is never asked for its products",
            [$r[0], $r[2], $prod() - $p0], [403, ['status' => 'error', 'message' => 'Admin only.'], 0]);
    }
    $r = qcj_http('GET', "{$base}?page=api&action=push_products_to_ucrm&dry_run=0");
    is_($r[0] === 401, "G {$tenant}: no session at all → 401", (string)$r[0]);
    qcj_stop($srv);
}
// The three Sync All screens are not changed (SPEC §8 L1 records what a non-admin now sees there).
$screens = function (?string $tree) {
    if ($tree === null) return null;
    qc_fake(qcj_orgs([2, 7]));
    $b = qc_book('south-sudan', [], [], $tree);
    [$srv, $base] = qcj_serve_plugin($b);
    $out = [];
    if ($srv !== null && qcj_login($base, $b['dir'] . '/jar.txt', 'admin@example.test')) {
        foreach (['subscription_plans', 'hardware', 'product_mapping'] as $tab) {
            $out[$tab] = qc_norm_page(qcj_http('GET', "{$base}?page=dashboard&tab={$tab}", null, [], $b['dir'] . '/jar.txt')[1], $base, $tree);
        }
    }
    qcj_stop($srv);
    return $out;
};
$new = $screens($T); $old = $screens($TB);
foreach (['subscription_plans', 'hardware', 'product_mapping'] as $tab) {
    is_($old !== null && strlen((string)($new[$tab] ?? '')) > 5000 && ($new[$tab] ?? '') === ($old[$tab] ?? null),
        "G the {$tab} screen is ce7ac388's, byte for byte (its CSRF token, address and version aside)", strlen((string)($new[$tab] ?? '')) . ' / ' . strlen((string)($old[$tab] ?? '')));
}
}

// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if (qc_section('H')) {
echo "\nH. Item 7: a failed uCRM create on Send Quotation is shown to the salesperson\n";
$F7a = function (string $why) { return "uCRM refused the quotation ({$why}), so nothing was sent to the customer. Tell an admin: trying again will not help."; };
$F7k = "uCRM refused the app's key, so no quotation was made and nothing was sent to the customer. Tell an admin.";
$F7u = function (string $why) { return "uCRM did not confirm the quotation ({$why}). It may have been made, so check the customer's quotes in uCRM before trying again. Nothing was sent from the app."; };
$seed = function (array $ctx) {
    qcj_php($ctx, '
        require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
        $s = SqliteStore::create($DATA);
        $s->save("leads.json", [["id" => 1, "customer_name" => "TEST Lead", "phone" => "+256700000116", "crm_client_id" => 16,
            "service_type" => "starlink", "status" => "open", "notes" => "", "retailer_id" => (int)$ARGS["agent"]]]);
        qcj_out(true);', ['agent' => $ctx['agent']]);
};
$send = function (array $ctx, string $path) {
    $post = $path === 'lead'
        ? ['action' => 'send_lead_quote', 'lead_id' => '1', 'quote_items_json' => json_encode(QC_ITEMS), 'create_crm_quote' => '1']
        : ['action' => 'send_manual_quote', 'mq_crm_client_id' => '17', 'mq_customer_name' => '', 'mq_customer_phone' => '', 'mq_create_crm' => '1',
           'mq_note' => '', 'mq_items_json' => json_encode(QC_ITEMS)];
    return qcj_post($ctx, 'post_leads.php', $post, (int)$ctx['agent']);
};
$clients = [qcj_client(16, 1, 'Lead'), qcj_client(17, 1, 'Manual')];
foreach (['lead' => 'Quote failed: ', 'manual' => 'Failed: '] as $path => $prefix) {
    qc_fake(qcj_orgs([1, 2]), $clients);
    $ug = qc_book('uganda'); $seed($ug);
    $r = $send($ug, $path);
    $log = qcj_tables($ug['data'], ['quotes_log'])['quotes_log'] ?? [];
    is_(($r['flash'][0]['type'] ?? '') === 'success' && strpos((string)$r['flash'][0]['msg'], 'Q-3500') !== false && count(qc_texts()) === 1
        && (string)($log[0]['quote_ref'] ?? '') === 'Q-3500', "H control ({$path}): the create accepted — uCRM's number swapped in, the text sent", qc_j($r['flash']));
    $cases = [
        'billing_refuse (422)'  => [['billing_refuse' => true], $F7a('uCRM answered HTTP 422: Validation failed. (invoiceItems)')],
        'billing_401'           => [['billing_401' => true], $F7k],
        'billing_500'           => [['billing_500' => true], $F7u('uCRM answered HTTP 500: Internal Server Error')],
        'billing_drop'          => [['billing_drop' => true], null],
        'billing_nonjson'       => [['billing_nonjson' => true], $F7u('uCRM answered without a quotation number')],
        'billing_noid'          => [['billing_noid' => true], $F7u('uCRM answered without a quotation number')],
    ];
    foreach ($cases as $label => [$tg, $want]) {
        qc_fake(qcj_orgs([1, 2]), $clients, $tg);
        $ug = qc_book('uganda'); $seed($ug);
        $d0 = qc_digest($ug, ['leads', 'quotes_log', 'wa_sent_quotes']);
        $r = $send($ug, $path);
        $msg = (string)($r['flash'][0]['msg'] ?? '');
        $ok = $want !== null ? $msg === $prefix . $want
            : (strpos($msg, $prefix . 'uCRM did not confirm the quotation (uCRM could not be reached (') === 0 && substr($msg, -strlen('Nothing was sent from the app.')) === 'Nothing was sent from the app.');
        is_($ok && ($r['flash'][0]['type'] ?? '') === 'danger', "H {$path} {$label}: \"{$prefix}\" + " . ($want === $F7k ? 'F7k' : (strpos((string)$want, 'refused the quotation') !== false ? 'F7a' : 'F7u')), $msg);
        t("H {$path} {$label}: … no WhatsApp, no leads.json or quotes_log change", [qc_texts(), qc_digest($ug, ['leads', 'quotes_log', 'wa_sent_quotes'])], [[], $d0]);
    }
}
// In one request: an earlier failed GET, then a 2xx with no id — the reason is that answer, never the stale error.
qc_fake(qcj_orgs([1, 2]), $clients, ['billing_noid' => true]);
$ug = qc_book('uganda');
$x = qcj_php($ug, '
    require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
    require_once "$TREE/lib/currency.php"; require_once "$TREE/lib/QuotationService.php";
    $s = SqliteStore::create($DATA); $q = new QuotationService($s, $DATA, $s->load("kyc_config.json") ?: []);
    $agent = ["id" => 7, "name" => "TEST Agent"];
    $a = $q->sendManualQuote(["crm_client_id" => 999, "items" => $ARGS["items"], "create_crm_quote" => true], $agent);   // GET clients/999: 404
    $b = $q->sendLeadQuote(["id" => 1, "customer_name" => "TEST Lead", "phone" => "+256700000116", "crm_client_id" => 16], $ARGS["items"], $agent, true);
    qcj_out([$a, $b]);', ['items' => QC_ITEMS]);
t('H an earlier failed GET (client #999: R3) in the same request, then a 2xx with no id: F7u "without a quotation number", not the 404',
    [$x['out'][0]['error'] ?? null, $x['out'][1]['error'] ?? null], [qc_r3('999'), $F7u('uCRM answered without a quotation number')]);
// An exception after uCRM returned the id: the quote exists, and the text says so.
qc_fake(qcj_orgs([1, 2]), $clients, ['billing_seq' => 76]);
$ug = qc_book('uganda');
$x = qcj_php($ug, '
    require_once "$TREE/lib/StoreInterface.php"; require_once "$TREE/lib/JsonStore.php"; require_once "$TREE/lib/SqliteStore.php";
    require_once "$TREE/lib/currency.php"; require_once "$TREE/lib/QuotationService.php";
    final class QcThrowingCrm extends CrmApiClient {
        public function patch(string $path, array $p = []): ?array { throw new RuntimeException("TEST: the send failed"); }
    }
    $s = SqliteStore::create($DATA); $cfg = $s->load("kyc_config.json") ?: [];
    $q = new QuotationService($s, $DATA, $cfg);
    $real = CrmApiClient::fromUcrm($TREE, $cfg);
    $rp = new ReflectionProperty(QuotationService::class, "crm"); $rp->setAccessible(true);
    $rp->setValue($q, new QcThrowingCrm($real->getBaseUrl(), $real->getAppKey(), $real->getAuthHeader()));
    qcj_out($q->sendLeadQuote(["id" => 1, "customer_name" => "TEST Lead", "phone" => "+256700000116", "crm_client_id" => 16], $ARGS["items"], ["id" => 7, "name" => "TEST Agent"], true));',
    ['items' => QC_ITEMS]);
t('H an exception after uCRM returned quotation 77: F7u naming #77', [$x['out']['ok'] ?? null, $x['out']['error'] ?? null],
    [false, $F7u('uCRM made quotation #77 but the app could not finish it')]);
}


// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if (qc_section('I')) {
echo "\nI. Inventory and form\n";
/** Every file of a tree outside the directories a release does not ship (tests, docs, data …): rel => path. */
$files = function (string $tree): array {
    $out = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS),
        function ($f) use ($tree) { return !in_array(explode('/', substr($f->getPathname(), strlen($tree) + 1))[0], QCJ_SKIP, true); }));
    foreach ($it as $f) if ($f->isFile()) $out[substr($f->getPathname(), strlen($tree) + 1)] = $f->getPathname();
    ksort($out);
    return $out;
};
/** PHP source without its comments (so a comment that names a call is not counted as the call). */
$code = function (string $src): string {
    $o = '';
    foreach (token_get_all($src) as $tk) {
        if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) { $o .= str_repeat("\n", substr_count($tk[1], "\n")); continue; }
        $o .= is_array($tk) ? $tk[1] : $tk;
    }
    return $o;
};
/** [name, first line, last line] of every function body, closures included. */
$functions = function (string $src): array {
    $toks = token_get_all($src); $out = []; $line = 1; $stack = []; $pending = null; $depth = 0;
    foreach ($toks as $i => $tk) {
        $txt = is_array($tk) ? $tk[1] : $tk;
        if (is_array($tk) && $tk[0] === T_FUNCTION) {
            $name = 'closure';
            for ($j = $i + 1; $j < $i + 4 && isset($toks[$j]); $j++) if (is_array($toks[$j]) && $toks[$j][0] === T_STRING) { $name = $toks[$j][1]; break; }
            $pending = $name;
        }
        if ($txt === '{' || (is_array($tk) && in_array($tk[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
            if ($pending !== null && $txt === '{') { $stack[] = [$pending, $line, $depth]; $pending = null; }
        } elseif ($txt === '}') {
            if ($stack && end($stack)[2] === $depth) { [$n, $l0] = array_pop($stack); $out[] = [$n, $l0, $line]; }
            $depth--;
        } elseif ($txt === ';' && $pending !== null) {
            $pending = null;   // an abstract or interface method
        }
        $line += substr_count($txt, "\n");
    }
    return $out;
};
$skipTests = function (string $rel): bool { return strpos($rel, 'tests/') === 0; };
$tree = $files($T);

// I1 — every quote create is guarded
$sites = [];
foreach ($tree as $rel => $path) {
    if (substr($rel, -4) !== '.php') continue;
    $c = $code((string)file_get_contents($path));
    if (!preg_match_all('#->post\(\s*(["\'])(clients/\{?\$[A-Za-z_]+\}?/quotes|billing/quotes)\1#', $c, $m, PREG_OFFSET_CAPTURE)) continue;
    $fns = $functions($c);
    foreach ($m[0] as [$hit, $off]) {
        $ln = substr_count(substr($c, 0, $off), "\n") + 1;
        $scope = null;
        foreach ($fns as [$n, $a, $b]) if ($a <= $ln && $ln <= $b && ($scope === null || $a > $scope[1])) $scope = [$n, $a, $b];
        $lines = explode("\n", $c);
        if ($scope === null) {   // an include's top level: the action block it sits in
            $a = 1;
            for ($k = $ln - 1; $k >= 0; $k--) if (preg_match("#if \(\\\$act === '([a-z_0-9]+)'#", $lines[$k], $am)) { $a = $k + 1; $scope = ['act:' . $am[1], $a, $ln]; break; }
            if ($scope === null) $scope = ['top', 1, $ln];
        }
        $body = implode("\n", array_slice($lines, $scope[1] - 1, $ln - $scope[1] + 1));
        $sites[] = [$rel, $scope[0], strpos($body, 'checkClient(') !== false || strpos($body, 'checkQuoteClient(') !== false];
    }
}
usort($sites, function ($a, $b) { return strcmp($a[0] . $a[1], $b[0] . $b[1]); });
t('I1 every quote create in the tree is one of Q1–Q5, and each sits after a checkClient( or checkQuoteClient( in its own function (or action)',
    $sites, [['includes/api/api_cron_debug.php', 'act:retry_quote', true], ['lib/KycService.php', 'handleExisting', true],
             ['lib/KycService.php', 'postQuote', true], ['lib/QuotationService.php', 'createCrmQuote', true]]);
$posted = 0;
foreach ($tree as $rel => $path) if (substr($rel, -4) === '.php') $posted += preg_match_all('#KycService::postQuote\(|self::postQuote\(#', $code((string)file_get_contents($path)));
is_($posted === 2, 'I1 … postQuote() has its two callers, KycService::handleNew (Q2) and KycCrmSync::afterCreate (Q3)', (string)$posted);

// I2 — the profiles
$ugp = json_decode((string)file_get_contents($T . '/profiles/uganda.json'), true);
$ssp = json_decode((string)file_get_contents($T . '/profiles/south-sudan.json'), true);
t('I2 profiles/uganda.json: quoting.ucrm_organization_id === 1; profiles/south-sudan.json has no quoting block',
    [$ugp['quoting']['ucrm_organization_id'] ?? null, array_key_exists('quoting', (array)$ssp)], [1, false]);

// I3 — tools/set_config.php: ce7ac388's bytes plus exactly the entry and the validation block, and what they do
$I3_ENTRY = <<<'QC_ENTRY'
    'ucrm_quote_organization_id' => ['text',
        'Uganda only (ignored on South Sudan): the uCRM organization app customers are filed under and app quotes are made for. Blank = the Uganda profile (organization 1); 0 or off = switched off, as 5.18.98'],
QC_ENTRY;
$I3_BLOCK = <<<'QC_BLOCK'
// 5.18.99: the Uganda quoting organization — 0/off/no/none/false, or an organization number this uCRM lists. A mistyped
// value would stop every Uganda app quote from the next request, and a wrong number would refuse every one, so both are
// refused here and nothing is saved. uCRM is only read (GET organizations). --clear returns the key to the profile.
if (!$clear && $key === 'ucrm_quote_organization_id') {
    $qoGiven = trim($new);
    $new     = strtolower($qoGiven);
    if (!in_array($new, ['0', 'off', 'no', 'none', 'false'], true)) {
        if (!preg_match('/^[1-9][0-9]{0,8}$/', $new)) {
            echo "\n  \"" . $qoGiven . "\" is not an organization number or 0/off, so nothing was saved.\n\n";
            exit(1);
        }
        require_once $root . '/lib/CrmApiClient.php';
        require_once $root . '/lib/QuoteOrganization.php';
        $qoCfg  = PluginConfig::read($root, $dataDir);   // the read-only path: a refusal below must change nothing on disk
        $qoRead = (new QuoteOrganization(CrmApiClient::fromUcrm($root, $qoCfg),
            ['on' => true, 'id' => (int)$new, 'source' => 'setting', 'error' => '']))->organizations();
        if ($qoRead['list'] === null) {
            echo "\n  uCRM's organizations could not be read (" . $qoRead['error'] . "), so nothing was saved.\n\n";
            exit(1);
        }
        $qoIds = [];
        foreach ($qoRead['list'] as $qoRow) if (isset($qoRow['id'])) $qoIds[] = (int)$qoRow['id'];
        if (!in_array((int)$new, $qoIds, true)) {
            echo "\n  uCRM has no organization " . (int)$new . " (it has organization "
               . ($qoIds === [] ? 'none' : implode(', ', $qoIds)) . "), so nothing was saved.\n\n";
            exit(1);
        }
        $new = (string)(int)$new;
    }
}
QC_BLOCK;
$sbase = $BASE !== null ? (string)@file_get_contents($BASE . '/tools/set_config.php') : '';
$afterLead = "    'ucrm_lead_organization_id' => ['text',\n        'uCRM organization new leads belong to (unset = the one existing clients use)'],\n";
$beforeIa  = "// 5.18.82 (docs/63): the customer authorisation link's life in days, 1 to 90 — refused outside the range, never clamped silently.\n";
$want = str_replace($afterLead, $afterLead . $I3_ENTRY . "\n", $sbase, $n1);
$want = str_replace($beforeIa, $I3_BLOCK . "\n" . $beforeIa, $want, $n2);
t('I3 tools/set_config.php is ce7ac388\'s bytes plus exactly the entry (text, the SPEC §1.1 wording) and the validation block',
    [$sbase !== '', $n1, $n2, hash('sha256', $want) === hash_file('sha256', $T . '/tools/set_config.php')], [true, 1, 1, true]);
$sc = function (array $ctx, array $args) use ($T): array {
    $cmd = 'cd ' . escapeshellarg($T) . ' && exec ' . escapeshellarg(PHP_BINARY) . ' tools/set_config.php ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    qcj_point($ctx);
    $p = proc_open($cmd, [1 => ['pipe', 'w']], $pipes, null, qcj_env($ctx['env']));
    $out = (string)stream_get_contents($pipes[1]); fclose($pipes[1]);
    $rc = proc_close($p);
    $saved = json_decode((string)@file_get_contents($ctx['data'] . '/kyc_config.json'), true);
    return [$rc, is_array($saved) && array_key_exists('ucrm_quote_organization_id', $saved) ? $saved['ucrm_quote_organization_id'] : '<unset>', trim($out)];
};
qc_fake(qcj_orgs([1, 2]));
$b = qc_book('uganda');
$listed = $sc($b, []);
is_($listed[0] === 0 && preg_match('/ucrm_quote_organization_id/', $listed[2]) === 1, 'I3 control: the tool lists the key', substr($listed[2], 0, 200));
foreach (['0' => '0', 'off' => 'off', ' OFF ' => 'off', ' 1 ' => '1'] as $v => $stored) {
    $r = $sc($b, ['--key', 'ucrm_quote_organization_id', '--value', $v]);
    t('I3 --value ' . qc_j($v) . ' is accepted and stored as ' . qc_j($stored), [$r[0], $r[1]], [0, $stored]);
}
foreach (['disabled', 'n', '1O', '#1', '-1'] as $v) {
    $r = $sc($b, ['--key', 'ucrm_quote_organization_id', '--value', $v]);
    t('I3 --value ' . qc_j($v) . ' is refused, and nothing is saved', [$r[0], $r[1], strpos($r[2], 'is not an organization number or 0/off, so nothing was saved') !== false], [1, '1', true]);
}
// "Nothing was saved" must hold on disk too: the two refusals that read uCRM leave the vault as they found it. The
// vault is put back to the book's first state first, which the boot path (PluginConfig::load) would rewrite.
$vault0 = (string)json_encode(['config' => []]);
file_put_contents($b['vault'], $vault0);
$r = $sc($b, ['--key', 'ucrm_quote_organization_id', '--value', '5']);
t('I3 --value 5, which uCRM does not list: refused, nothing saved', [$r[0], $r[1], strpos($r[2], 'uCRM has no organization 5 (it has organization 1, 2), so nothing was saved.') !== false], [1, '1', true]);
clearstatcache();
$vault5 = (string)@file_get_contents($b['vault']);
qc_fake(qcj_orgs([1, 2]), [], ['orgs_down' => true]);
$r = $sc($b, ['--key', 'ucrm_quote_organization_id', '--value', '2']);
t('I3 --value 2 with uCRM\'s organizations unreadable: refused, nothing saved', [$r[0], $r[1], strpos($r[2], "uCRM's organizations could not be read") !== false], [1, '1', true]);
clearstatcache();
$vault2 = (string)@file_get_contents($b['vault']);
qc_fake(qcj_orgs([1, 2]));
$sc($b, []);   // the listing boots the configuration (PluginConfig::load), which refreshes the vault
clearstatcache();
$vaultL = (string)@file_get_contents($b['vault']);
t('I3 … and both refusals leave the vault byte for byte as they found it (read-only), where the boot path would have rewritten it (control)',
    [$vault5 === $vault0, $vault2 === $vault0, $vaultL !== $vault0 && $vaultL !== ''], [true, true, true]);
$r = $sc($b, ['--key', 'ucrm_quote_organization_id', '--clear']);
t('I3 --clear is accepted: the key goes back to the profile', [$r[0], $r[1]], [0, '<unset>']);

// I4 — the delta against ce7ac388: exactly the declared files, every hunk naming 5.18.99; the three tabs' hunks gated
$DECLARED = ['cron_quote_wa.php', 'includes/api/api_crm_misc.php', 'includes/api/api_cron_debug.php', 'includes/api/api_products_admin.php',
    'includes/post/post_kyc.php', 'lib/KycCrmSync.php', 'lib/KycService.php', 'lib/PluginQuotePdf.php', 'lib/QuotationService.php',
    'lib/QuoteOrganization.php', 'lib/QuotePdfSource.php', 'lib/UcrmClientTarget.php', 'lib/UcrmLeadSync.php', 'manifest.json',
    'profiles/uganda.json', 'tabs/lte/lte_dashboard.php', 'tabs/sales/applications.php', 'tabs/sales/kyc_form.php', 'tools/set_config.php'];
$changed = []; $hunkFails = []; $declSeen = [];
$baseFiles = $BASE !== null ? $files($BASE) : [];
foreach ($tree as $rel => $path) {
    if (substr($rel, -3) === '.md' || $rel === 'ucrm.json') continue;          // documents; the book's own pointer
    $old = $baseFiles[$rel] ?? null;
    if ($old !== null && hash_file('sha256', $old) === hash_file('sha256', $path)) continue;
    $changed[] = $rel;
    $d = []; exec('diff -U0 ' . escapeshellarg($old ?? '/dev/null') . ' ' . escapeshellarg($path), $d);
    $hunks = []; $cur = null;
    foreach ($d as $l) {
        if (strpos($l, '@@') === 0) { if ($cur !== null) $hunks[] = $cur; $cur = ['+' => [], '-' => []]; continue; }
        if ($cur === null || strpos($l, '+++') === 0 || strpos($l, '---') === 0) continue;
        if ($l !== '' && ($l[0] === '+' || $l[0] === '-')) $cur[$l[0]][] = substr($l, 1);
    }
    if ($cur !== null) $hunks[] = $cur;
    foreach ($hunks as $i => $h) {
        $added = implode("\n", $h['+']);
        if (strpos($added, '5.18.99') !== false) continue;
        $k = array_search($added, QC_I4_UNMARKED[$rel] ?? [], true);
        if ($k !== false && !isset($declSeen[$rel][$k])) { $declSeen[$rel][$k] = true; continue; }
        $hunkFails[] = $rel . ' hunk ' . ($i + 1) . ': ' . mb_substr(implode(' / ', $h['+'] ?: $h['-']), 0, 140);
    }
}
foreach ($baseFiles as $rel => $p) if (!isset($tree[$rel]) && substr($rel, -3) !== '.md') $changed[] = $rel . ' (removed)';
sort($changed);
t('I4 against ce7ac388 the release changes exactly the declared files (besides documents)', $BASE !== null ? $changed : null, $DECLARED);
t('I4 … and every hunk of every one names 5.18.99, or is one of the ' . (count(QC_I4_UNMARKED, COUNT_RECURSIVE) - count(QC_I4_UNMARKED)) . ' declared hunks', $hunkFails, []);
$stale = [];
foreach (QC_I4_UNMARKED as $rel => $list) foreach ($list as $k => $txt) if (!isset($declSeen[$rel][$k])) $stale[] = $rel . ': ' . mb_substr($txt, 0, 80);
t('I4 … and every declared hunk is still there (none left over)', $stale, []);
$gate = '<?php /* 5.18.99 (Uganda): the reason no quotation was made — emitted only where the quoting-organization gate is on */ require_once dirname(__DIR__, 2) . \'/lib/QuoteOrganization.php\'; if (QuoteOrganization::setting(is_array($config ?? null) ? $config : [], isset($dataDir) ? (string)$dataDir : null)[\'on\']): ?>+(d.data._quote_error?\' \'+d.data._quote_error:\'\')<?php endif; ?>';
foreach (['tabs/sales/kyc_form.php', 'tabs/lte/lte_dashboard.php'] as $rel) {
    $new = (string)file_get_contents($T . '/' . $rel);
    $back = str_replace($gate, '', $new, $gn);
    t("I4 {$rel}: its one hunk is the _quote_error fragment, inside a condition on the gate; without it the file is ce7ac388's", [$gn, $BASE !== null && $back === (string)file_get_contents($BASE . '/' . $rel)], [1, true]);
}
$appsHunk = "<?php if (!empty(\$a['quote_refused_text']) && empty(\$a['quote_created'])): /* 5.18.99 (Uganda): the quote check refused this customer's quotation, and none has been made since */ ?>\n"
    . "    <div style=\"margin-top:8px;padding:8px 10px;border-radius:10px;font-size:11px;color:#7c2d12;background:#FFF7ED;border:1px solid #FED7AA;\">\n"
    . "        <div style=\"font-weight:800;margin-bottom:3px;\">⚠ No quotation was made</div>\n"
    . "        <div><?= h((string)\$a['quote_refused_text']) ?></div>\n"
    . "    </div>\n<?php endif; ?>\n";
$back = str_replace($appsHunk, '', (string)file_get_contents($T . '/tabs/sales/applications.php'), $an);
t('I4 tabs/sales/applications.php: its one hunk is the refusal block, inside a condition on quote_refused_text (and no quote made since); without it the file is ce7ac388\'s',
    [$an, $BASE !== null && $back === (string)file_get_contents($BASE . '/tabs/sales/applications.php')], [1, true]);
// I4, the rest of the delta: what the walk above leaves out (tests/, docs/ … and every *.md) is declared too, each with
// why, so that the release's file list and its pin agree with the delta. Measured with Git on this checkout against
// ce7ac388: the tracked files that differ, and any new file not yet committed.
$git = function (string $args) use ($LIB): ?array {
    $o = []; exec('git -C ' . escapeshellarg($LIB) . ' ' . $args . ' 2>/dev/null', $o, $rc);
    return $rc === 0 ? $o : null;
};
$gd = $git('diff --name-only --relative ' . escapeshellarg(QC_BASE_COMMIT) . ' -- .');
$gu = $git('ls-files --others --exclude-standard -- .');
$outside = null;
if ($gd !== null && $gu !== null) {
    $outside = array_values(array_unique(array_filter(array_merge($gd, $gu), function ($rel) {
        return $rel !== '' && (substr($rel, -3) === '.md' || in_array(explode('/', $rel)[0], QCJ_SKIP, true));
    })));
    sort($outside);
}
$declOutside = array_keys(QC_I4_DOCS_TESTS); sort($declOutside);
t('I4 … and outside the walk (documents and tests) it changes exactly the ' . count(QC_I4_DOCS_TESTS) . ' declared files, each declared with why (Git, against ' . QC_BASE_COMMIT . ')',
    $outside, $declOutside);

// I5 — no migration; the manifest moves its version only
$mig = function (?string $t) use ($files) { if ($t === null) return null; $o = []; foreach (glob($t . '/migrations/*') ?: [] as $f) $o[basename($f)] = hash_file('sha256', $f); ksort($o); return $o; };
is_($BASE !== null && $mig($T) === $mig($BASE) && count((array)$mig($T)) > 50, 'I5 migrations/ is ce7ac388\'s, file for file (' . count((array)$mig($T)) . ' files): no migration');
$mb = $BASE !== null ? str_replace('    "version": "5.18.98",', '    "version": "5.18.99",', (string)file_get_contents($BASE . '/manifest.json'), $mn) : '';
t('I5 manifest.json is ce7ac388\'s with its version line moved to 5.18.99', [$mn ?? 0, hash('sha256', $mb) === hash_file('sha256', $T . '/manifest.json')], [1, true]);

// I6 — PHP 7.4 syntax on every added line, and php -l on every new or changed PHP file. php -l runs under this machine's
// PHP and, where installed, under php7.4 and php8.1 too; a version that is not installed is reported as a limitation
// (a note, never an ok), and then only the rules below stand for it — they catch what they name, nothing more (a
// signature spread over several lines, or a named argument on a line that also holds a ternary, is not seen).
$bad = [];
$lintFail = [];
$lintBins = ['php ' . PHP_VERSION => PHP_BINARY];
foreach (['php7.4', 'php8.1'] as $bin) {
    $where = trim((string)shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
    if ($where !== '') $lintBins[$bin] = $where;
    else echo "  note I6 limitation: {$bin} is not installed here, so no file was linted under it; PHP " . substr($bin, 3)
            . " syntax rests on the rules below and on php -l under PHP " . PHP_VERSION . " — a stated limitation, not a pass\n";
}
$lintFiles = array_merge($DECLARED, array_values(array_filter(array_keys(QC_I4_DOCS_TESTS), function ($r) { return substr($r, -4) === '.php'; })));
foreach ($lintFiles as $rel) {
    if (substr($rel, -4) !== '.php') continue;
    $path = strpos($rel, 'tests/') === 0 ? $LIB . '/' . $rel : $T . '/' . $rel;
    if (!is_file($path)) { $lintFail[] = $rel . ' (missing)'; continue; }
    foreach ($lintBins as $binName => $bin) {
        $o = []; exec(escapeshellarg($bin) . ' -l ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
        if ($rc !== 0) $lintFail[] = $rel . ' (' . $binName . ')';
    }
    if (strpos($rel, 'tests/') === 0) continue;
    // The added CODE lines: both sides with their comments taken out (line count kept), then compared.
    $cb = $TMP . '/i6_base.php'; $cn = $TMP . '/i6_new.php';
    file_put_contents($cb, $BASE !== null && is_file($BASE . '/' . $rel) ? $code((string)file_get_contents($BASE . '/' . $rel)) : '');
    file_put_contents($cn, $code((string)file_get_contents($path)));
    $d = []; exec('diff ' . escapeshellarg($cb) . ' ' . escapeshellarg($cn), $d);
    $added = implode("\n", array_map(function ($l) { return substr($l, 2); }, array_filter($d, function ($l) { return strpos($l, '> ') === 0; })));
    foreach (QC_I6_RULES as $what => $re) {
        foreach (explode("\n", $added) as $l) {
            if (preg_match($re, $l) && !($what === 'a named argument' && preg_match('/\?\s*[^:]*:/', $l))) { $bad[] = "{$rel}: {$what}: " . trim($l); break; }
        }
    }
}
t('I6 php -l passes on every new or changed PHP file, under ' . implode(', ', array_keys($lintBins)), $lintFail, []);
t('I6 no added line uses ' . implode(', ', array_keys(QC_I6_RULES)), $bad, []);
// The rules themselves, each against a line it must catch and a PHP 7.4 line it must leave alone (the control).
$ruleMiss = [];
foreach (QC_I6_SAMPLES as $what => [$hit, $clean]) {
    if (!isset(QC_I6_RULES[$what]) || preg_match(QC_I6_RULES[$what], $hit) !== 1 || preg_match(QC_I6_RULES[$what], $clean) === 1) $ruleMiss[] = $what;
}
t('I6 control: each of the ' . count(QC_I6_RULES) . ' rules catches its PHP 8 sample and passes its PHP 7.4 one', [$ruleMiss, count(QC_I6_SAMPLES) === count(QC_I6_RULES)], [[], true]);

// I7 — every file that names the class loads it itself
$loads = [];
foreach ($tree as $rel => $path) {
    if (substr($rel, -4) !== '.php' || $rel === 'lib/QuoteOrganization.php') continue;
    $c = $code((string)file_get_contents($path));
    if (strpos($c, 'QuoteOrganization::') === false && strpos($c, 'new QuoteOrganization') === false) continue;
    $loads[$rel] = preg_match("#require_once[^;]*QuoteOrganization\.php#", $c) === 1;
}
is_(count($loads) >= 11, 'I7 control: ' . count($loads) . ' files name QuoteOrganization (lib, the two API includes, the two LTE screens, tools/set_config.php)', qc_j(array_keys($loads)));
t('I7 every one of them holds its own require_once of QuoteOrganization.php', array_keys(array_filter($loads, function ($v) { return !$v; })), []);

// I8 — every client create is on the allow-list
$creates = [];
foreach ($tree as $rel => $path) {
    if (substr($rel, -4) !== '.php') continue;
    $n = preg_match_all('#->post\(\s*(["\'])clients\1#', $code((string)file_get_contents($path)));
    if ($n) $creates[$rel] = $n;
}
ksort($creates);
t('I8 every POST clients site is on the allow-list (item 1: KycService, KycCrmSync; item 3: UcrmLeadSync; retailers: FtthCrmService; the legacy queue: cron_sync.php, post_admin.php, api_crm_sync.php)',
    $creates, ['cron_sync.php' => 1, 'includes/api/api_crm_sync.php' => 1, 'includes/post/post_admin.php' => 1, 'lib/FtthCrmService.php' => 1,
               'lib/KycCrmSync.php' => 1, 'lib/KycService.php' => 1, 'lib/UcrmLeadSync.php' => 1]);
}


// ══════════════════════════════════════════════════════════════════════════════════════════════════════════════════════
if (qc_section('S')) {
echo "\nS. Byte-identical: the journey J1–J14 on ce7ac388 and on this tree — South Sudan, and Uganda switched off\n";
/** The journey on $tree for $sc, in a fresh work directory under this run's own. */
$journey = function (string $tree, string $sc) use ($FAKE, $EVO, $TMP): array {
    static $n = 0;
    $n++;
    $w = $TMP . '/journey/' . $n;
    @mkdir($w, 0700, true);
    $doc = qcj_journey($tree, $sc, $FAKE, $EVO, $w, QC_TZ[qcj_scenario($sc)['tenant']]);
    exec('rm -rf ' . escapeshellarg($w));
    return $doc;
};
$dates = function (): array {
    $o = [];
    foreach (['UTC', 'Africa/Kampala', 'Africa/Juba'] as $z) $o[] = (new DateTime('now', new DateTimeZone($z)))->format('Ymd');
    return $o;
};
/** The doc's paths that differ from $b's (at most 25), with both values, short. */
$diffs = function ($a, $b, string $path = '') use (&$diffs): array {
    if (is_array($a) && is_array($b)) {
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            if (!array_key_exists($k, $a)) { $out[] = $path . '/' . $k . ' (only in the second)'; continue; }
            if (!array_key_exists($k, $b)) { $out[] = $path . '/' . $k . ' (only in the first)'; continue; }
            foreach ($diffs($a[$k], $b[$k], $path . '/' . $k) as $d) $out[] = $d;
            if (count($out) >= 25) break;
        }
        return $out;
    }
    if ($a === $b) return [];
    if (is_string($a) && is_string($b) && (strlen($a) > 160 || strlen($b) > 160)) {
        $x = explode("\n", $a); $y = explode("\n", $b);
        foreach ($x as $i => $l) if (($y[$i] ?? null) !== $l) return [$path . ' line ' . ($i + 1) . ': ' . qc_j(mb_substr($l, 0, 100)) . ' ≠ ' . qc_j(mb_substr((string)($y[$i] ?? '<none>'), 0, 100))];
        return [$path . ': ' . count($x) . ' lines ≠ ' . count($y) . ' lines'];
    }
    return [$path . ': ' . mb_substr(qc_j($a), 0, 100) . ' ≠ ' . mb_substr(qc_j($b), 0, 100)];
};
/** The explicit normaliser: each listed field becomes <N:field>, presence kept; $seen records what was found. */
$normalise = function (array $doc, string $tree, array &$seen): array {
    foreach (QC_NORMALISED as [$where, $field]) {
        if (strpos($where, 'page:') === 0) continue;
        foreach ((array)($doc['tables'][$where] ?? []) as $i => $row) {
            if (is_array($row) && array_key_exists($field, $row)) { $doc['tables'][$where][$i][$field] = '<N:' . $field . '>'; $seen[$where . '.' . $field] = true; }
        }
    }
    $base = (string)($doc['served_base'] ?? '');
    $ver = (string)(json_decode((string)@file_get_contents($tree . '/manifest.json'), true)['information']['version'] ?? '');
    foreach ((array)($doc['pages'] ?? []) as $k => $html) {
        if (preg_match('/name="_csrf" value="([^"]+)"/', (string)$html, $m)) { $html = str_replace($m[1], '<N:csrf>', $html); $seen['page:*.csrf'] = true; }
        if ($base !== '' && strpos($html, preg_replace('#/public\.php$#', '', $base)) !== false) { $html = str_replace(preg_replace('#/public\.php$#', '', $base), '<plugin>', $html); $seen['page:*.address'] = true; }
        if ($ver !== '' && strpos($html, 'v' . $ver) !== false) { $html = str_replace('v' . $ver, 'v<N:version>', $html); $seen['page:*.version'] = true; }
        $doc['pages'][$k] = $html;
    }
    unset($doc['served_base']);
    return $doc;
};
/** The control on a baseline run: it did something worth comparing. */
$ranAll = function (array $d): array {
    $reqs = qcj_reqs((array)($d['fake_log'] ?? []));
    $has = function (string $re) use ($reqs) { foreach ($reqs as $r) if (preg_match($re, $r)) return true; return false; };
    $types = array_column((array)($d['flashes'] ?? []), 'type');
    return ['POST clients' => $has('#^POST /clients$#'), 'POST clients/{id}/quotes' => $has('#^POST /clients/\d+/quotes$#'),
            'POST billing/quotes' => $has('#^POST /billing/quotes$#'), 'an Evolution text' => count((array)($d['texts'] ?? [])) > 0,
            'PDF HTML' => strlen((string)(((array)($d['pdf_html'] ?? []))[0] ?? '')) > 1000, 'a warning flash' => in_array('warning', $types, true),
            'a success flash' => in_array('success', $types, true), 'a rendered J14 page' => strlen((string)($d['pages']['applications'] ?? '')) > 10000];
};
if ($BASE === null || $TB === null) {
    is_(false, 'S the ce7ac388 tree is at hand — without it §S is a failure, never a skip', $whyBase);
} else {
    $seenBoth = [];
    $firstSs = null;
    foreach (['ss', 'ss_keyset', 'ug_off', 'ug_off_word'] as $sc) {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $d0 = $dates(); $t0 = microtime(true);
            $b1 = $journey($BASE, $sc);
            while (microtime(true) - $t0 < 2.0) usleep(100000);   // the two baseline runs at least two seconds apart
            $b2 = $journey($BASE, $sc);
            $nw = $journey($ROOT, $sc);
            if ($dates() === $d0) break;
            if ($attempt === 2) is_(false, "S {$sc}: the runs straddled midnight twice");
        }
        $ctl = array_merge($ranAll($b1), array_combine(array_map(function ($k) { return $k . ' (2nd)'; }, array_keys($ranAll($b2))), array_values($ranAll($b2))));
        t("S {$sc} control: each baseline run made a client, a KYC quote and a Send Quotation quote in uCRM, a WhatsApp, a PDF, a warning and a success flash, and the pages",
            array_keys(array_filter($ctl, function ($v) { return !$v; })), []);
        $s1 = []; $s2 = []; $s3 = [];
        $n1 = $normalise($b1, $BASE, $s1); $n2 = $normalise($b2, $BASE, $s2); $nn = $normalise($nw, $ROOT, $s3);
        foreach (array_intersect_key($s1, $s2) as $k => $v) $seenBoth[$k] = true;
        t("S {$sc}: ce7ac388 run twice, two seconds apart, is identical to itself after the normaliser (a field differing here and not on the list fails, by name)",
            $diffs($n1, $n2), []);
        t("S {$sc}: this tree's journey is ce7ac388's, byte for byte — requests, results, flashes, WhatsApp texts, PDF HTML, pages and the store",
            $diffs($n1, $nn), []);
        if ($sc === 'ss') $firstSs = $n1;
    }
    $noop = [];
    foreach (QC_NORMALISED as [$where, $field]) if (!isset($seenBoth[$where . '.' . $field])) $noop[] = $where . '.' . $field;
    t('S no normaliser is a no-op: each of the ' . count(QC_NORMALISED) . ' listed fields was present in both baseline runs of at least one scenario', $noop, []);
    // The comparison has teeth: gate on, the same journey differs from ce7ac388 where 5.18.99 says it should.
    $bOn = $journey($BASE, 'ug2'); $nOn = $journey($ROOT, 'ug2');
    $x = []; $y = [];
    $bn = $normalise($bOn, $BASE, $x); $nn = $normalise($nOn, $ROOT, $y);
    $orgOf = function (array $d) { $o = []; foreach ((array)$d['fake_log'] as $e) if ($e['method'] === 'POST' && $e['path'] === '/clients') $o[] = $e['body']['organizationId'] ?? null; return $o; };
    $guard = count(array_filter(qcj_reqs($nOn['fake_log']), function ($r) { return $r === 'GET /clients/15'; }));
    $frag = "+(d.data._quote_error?' '+d.data._quote_error:'')";
    is_($diffs($bn, $nn) !== [] && array_unique($orgOf($bOn)) === [2] && array_unique($orgOf($nOn)) === [1] && $guard >= 1
        && strpos($nOn['pages']['kyc_form'] ?? '', $frag) !== false && strpos($bOn['pages']['kyc_form'] ?? '', $frag) === false,
        'S control: gate on (Uganda, clients in 2), the journey differs from ce7ac388 where it should — POST clients names 1, the check reads the client, the screens carry the fragment',
        qc_j([array_slice($diffs($bn, $nn), 0, 6), $orgOf($bOn), $orgOf($nOn), $guard]));
    // The control on the normaliser: a copy that writes a changed value into a field not on the list fails the comparison.
    if ($WITH_MUTANTS && $firstSs !== null) {
        [$wk, $hits] = sj_weakened_copy($ROOT, 'lib/QuotationService.php', "                \$l['status']     = 'quoted';\n",
            "                \$l['status']     = 'quoted';\n                \$l['notes']      = 'TEST changed by the normaliser control';\n");
        if ($hits !== 1) {
            is_(false, 'S control on the normaliser: its anchor occurs once', (string)$hits);
        } else {
            $z = [];
            $wn = $normalise($journey($wk, 'ss'), $wk, $z);
            $d = $diffs($firstSs, $wn);
            is_(count(array_filter($d, function ($p) { return strpos($p, '/tables/leads/0/notes') === 0; })) === 1,
                'S control on the normaliser: a copy writing a changed leads.notes fails the comparison, naming the field', qc_j($d));
        }
        exec('rm -rf ' . escapeshellarg($wk));
    }
}
}


qc_done();

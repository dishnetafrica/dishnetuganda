<?php
declare(strict_types=1);
/**
 * test_quote_company_mutants.php — 5.18.99 (SPEC-quote-company, revision 2, §5.1 "Weakened copies"): every weakened copy
 * of the table — W1–W60, W36b and W61 — made in a copy of the plugin, must fail the section the table names; the
 * unchanged copy must pass every section first. Development and release suites only, never on the server: it copies
 * the plugin and runs child processes.
 *
 * Each copy is made with sj_weakened_copy() (tests/fixtures/staff_jobs_sandbox.php) and every further edit the same way:
 * the anchor occurs exactly once, it is replaced exactly once, the copy holds the new text, the anchor survives only
 * where the new text carries it, every edited PHP file lints and every edited JSON file decodes. A new file (W40) must
 * not exist before it is written. A copy whose anchor is missing is a FAILURE of this harness, never a skip.
 *
 * What judges a copy: tests/test_quote_company.php, this checkout's, run with --root=<the copy>, --base=<ce7ac388's
 * tree, taken from Git once>, --no-mutants and --sections=<the letters the table names for it>. Its sections are read
 * from its own headings (A. … I., S.); a FAIL line belongs to the section above it. A copy is caught only when EVERY part
 * it names failed; failing somewhere else does not count. Where a part is named with a pattern — a sub-section such as
 * B1 or C8, or a South Sudan scenario of §S such as "S ss:" — a FAIL line of that section must match it.
 *
 * In order:
 *   1. the control: the test passes on this checkout and on an unchanged copy, every section, with one tally; and
 *      every section a copy may be judged by passes ALONE on the unchanged copy with exactly the assertions it holds
 *      in the full run (so no section leans on another, and --sections runs what it names);
 *   2. the weakened copies, one line each: its name, the sections that failed, its verdict;
 *   3. the end check: the default vault beside this checkout is untouched, and every file this run made is in its own
 *      temporary folder.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_quote_company_mutants.php [--mutants=REGEX] [--base=DIR]
 *
 * --mutants runs only the copies whose label matches (the control still runs first), so the table can be run in parts
 * within a time limit; --base is a ce7ac388 tree already taken from Git (default: taken once). No Git: the comparisons
 * the copies need cannot run, and that is a failure, never a pass.
 *
 * One copy is named more narrowly than the table, because what the table also names cannot see it (stated, not
 * hidden): W46 — QuotePdfSource.php without its own require_once. PluginQuotePdf.php, which QuotePdfSource loads before
 * it reads the gate, loads QuoteOrganization.php itself, so the journey's PDF HTML is unchanged and §S cannot fail;
 * I7 (every file that names the class loads it itself) is what catches it. And one is named as the table says but with
 * a pattern: W27 — the lead's letterhead_client_id not passed — at §E's static check of the five call sites, since the
 * letterhead then falls back to the configured organization, the same company for a client the check let through.
 */
$lib = dirname(__DIR__);
$opt = getopt('', ['mutants:', 'base:']);

// This run's own temporary folder, and TMPDIR pointed at it before anything asks PHP for one: every copy, the ce7ac388
// tree and every child's own folder land inside it, and it is removed at the end.
$own = rtrim((string)(getenv('TMPDIR') ?: '/tmp'), '/') . '/qc99m-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($own, 0700, true);
putenv('TMPDIR=' . $own);
putenv('DN_VAULT_FILE=' . $own . '/children.vault.json');   // each book sets its own; this is only what a child inherits
register_shutdown_function(function () use ($own) { exec('rm -rf ' . escapeshellarg($own)); });
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';                 // sj_baseline_tree(), sj_weakened_copy()

$defVault  = dirname($lib) . '/.dishnet-sudan.vault.json';                  // ConfigVault::path()'s default for this tree
clearstatcache();
$defBefore = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . substr($d, 0, 900) : '') . "\n"; }
}
function qcm_done(): void {
    global $pass, $fail;
    printf("\n%d passed, %d failed\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}

const QCM_TEST = 'tests/test_quote_company.php';
const QCM_BASE = 'ce7ac388';                                                 // 5.18.98, live
const QCM_ALL  = 'ABCDEFGHIS';                                               // the test's sections

/** A child's output: [exit code, its lines]. */
function qcm_exec(string $cmd): array {
    $out = [];
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, $out];
}
/** "N passed, M failed" on the last line, or null. */
function qcm_tally(array $out): ?array {
    return preg_match('/^(\d+) passed, (\d+) failed$/', (string)end($out), $m) ? [(int)$m[1], (int)$m[2]] : null;
}

/**
 * The quote-company test, this checkout's, judging $tree: [exit code, FAIL lines by section, tally, fatal, ok lines by
 * section]. Sections are its own headings ("A. …", "S. …"); a FAIL line before the first heading is "setup".
 */
function qcm_judge(string $lib, string $tree, string $base, string $letters): array {
    [$rc, $out] = qcm_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lib . '/' . QCM_TEST)
        . ' --root=' . escapeshellarg($tree) . ' --base=' . escapeshellarg($base) . ' --no-mutants'
        . ($letters !== QCM_ALL ? ' --sections=' . escapeshellarg('^[' . $letters . ']$') : ''));
    $sec = 'setup'; $by = []; $oks = []; $fatal = '';
    foreach ($out as $l) {
        if (preg_match('/^([A-IS])\. /', $l, $m)) { $sec = $m[1]; continue; }
        if (strpos($l, '  FAIL ') === 0) $by[$sec][] = substr($l, 7);
        if (strpos($l, '  ok   ') === 0) $oks[$sec] = ($oks[$sec] ?? 0) + 1;
        if ($fatal === '' && preg_match('/^PHP (Fatal|Parse) error|^(Fatal|Parse) error/', $l)) $fatal = $l;
    }
    return [$rc, $by, qcm_tally($out), $fatal, $oks];
}

/**
 * A weakened copy: every edit asserted. [copy, '' or why it is not a valid copy]. An edit is [file, anchor, replacement],
 * or [file, null, contents] for a file the copy must not hold yet. The first edit is sj_weakened_copy()'s (the anchor
 * counted, replaced only when it occurs once); every further one is counted the same way and replaced exactly once.
 * Then the copy must hold each new text, each anchor may survive only where its new text carries it, every edited PHP
 * file must lint and every edited JSON file must decode.
 */
function qcm_copy(string $lib, array $edits): array {
    $first = $edits[0];
    if ($first[1] === null) {                                               // a new file first: copy with a no-op edit
        [$tmp, $n] = sj_weakened_copy($lib, 'manifest.json', '"information"', '"information"');
        if ($n !== 1) return [$tmp, 'the unchanged manifest\'s "information" occurs ' . $n . ' time(s), not once'];
        $rest = $edits;
    } else {
        [$rel0, $old0, $new0] = $first;
        [$tmp, $n] = sj_weakened_copy($lib, $rel0, $old0, $new0);
        if ($n !== 1) return [$tmp, "the anchor of edit 1 ({$rel0}) occurs {$n} time(s), not once"];
        $rest = array_slice($edits, 1);
    }
    $offset = count($edits) - count($rest);
    foreach ($rest as $i => [$rel, $o, $w]) {
        $no = $i + 1 + $offset;
        if ($o === null) {
            if (file_exists($tmp . '/' . $rel)) return [$tmp, "edit {$no} ({$rel}) would write a file the copy already holds"];
            if (!is_dir(dirname($tmp . '/' . $rel))) return [$tmp, "edit {$no} ({$rel}): its folder is not in the copy"];
            file_put_contents($tmp . '/' . $rel, $w);
            continue;
        }
        $src = (string)@file_get_contents($tmp . '/' . $rel);
        $c = substr_count($src, $o);
        if ($c !== 1) return [$tmp, "the anchor of edit {$no} ({$rel}) occurs {$c} time(s), not once"];
        $res = str_replace($o, $w, $src, $made);
        if ($made !== 1) return [$tmp, "edit {$no} ({$rel}) replaced {$made} time(s), not once"];
        file_put_contents($tmp . '/' . $rel, $res);
    }
    $lint = [];
    foreach ($edits as $i => [$rel, $o, $w]) {
        $src = (string)@file_get_contents($tmp . '/' . $rel);
        if ($w !== '' && strpos($src, $w) === false) return [$tmp, 'the copy does not hold the new text of edit ' . ($i + 1) . " ({$rel})"];
        if ($o !== null && substr_count($src, $o) !== substr_count($w, $o)) {
            return [$tmp, 'the anchor of edit ' . ($i + 1) . " ({$rel}) survives where its change does not carry it"];
        }
        if (substr($rel, -4) === '.php') $lint[$rel] = true;
        if (substr($rel, -5) === '.json' && !is_array(json_decode($src, true))) return [$tmp, "{$rel} does not decode as JSON"];
    }
    foreach (array_keys($lint) as $rel) {
        $lo = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp . '/' . $rel) . ' 2>&1', $lo, $lrc);
        if ($lrc !== 0) return [$tmp, "{$rel} does not lint: " . trim(implode(' ', $lo))];
    }
    return [$tmp, ''];
}

// ═══ The weakened copies (SPEC-quote-company §5.1's table) ═══════════════════════════════════════════════════════
// [label, [[file, anchor, replacement], ...], what must fail]. What must fail is a list of parts: a section letter of
// the test, or [section, pattern] when a FAIL line there must match the pattern (a sub-section, a scenario of §S).
$QO  = 'lib/QuoteOrganization.php';
$UT  = 'lib/UcrmClientTarget.php';
$KS  = 'lib/KycService.php';
$KC  = 'lib/KycCrmSync.php';
$QS  = 'lib/QuotationService.php';
$LS  = 'lib/UcrmLeadSync.php';
$PQ  = 'lib/PluginQuotePdf.php';
$PS  = 'lib/QuotePdfSource.php';
$PK  = 'includes/post/post_kyc.php';
$CD  = 'includes/api/api_cron_debug.php';
$CM  = 'includes/api/api_crm_misc.php';
$PA  = 'includes/api/api_products_admin.php';
$SC  = 'tools/set_config.php';
$UG  = 'profiles/uganda.json';
$KF  = 'tabs/sales/kyc_form.php';
$LD  = 'tabs/lte/lte_dashboard.php';

$Q1 = "        if (\$quoteOrg['on']) {\n"
    . "            \$quoteCheck = (new QuoteOrganization(\$quoteCrm, \$quoteOrg))->checkClient((int)\$customerId, 'kyc-existing');\n"
    . "            if (!\$quoteCheck['ok']) return ['success' => false, 'message' => \$quoteCheck['message']];\n"
    . "        }\n";
$SAVED = "\n        \$savedApp = \$this->store->findOne('kyc_applications.json', 'id', \$appId);\n        \$qRef     = \$savedApp['quote_ref'] ?? '';\n";
$Q1_MOVED = "        if (\$quoteOrg['on']) {   // weakened copy W9: the check after saveApplication\n"
          . "            \$quoteCheck = (new QuoteOrganization(\$quoteCrm, \$quoteOrg))->checkClient((int)\$customerId, 'kyc-existing');\n"
          . "            if (!\$quoteCheck['ok']) return ['success' => false, 'message' => \$quoteCheck['message']];\n"
          . "        }\n";
$REFUSED3 = "                    'quote_refused'      => \$check['code'],\n"
          . "                    'quote_refused_text' => \$text,\n"
          . "                    'quote_refused_at'   => date('Y-m-d H:i:s'),\n";
$LEADQ  = "        if (\$this->quoteOrg()['on'] && (int)(\$lead['crm_client_id'] ?? 0) > 0) {";
$MANQ   = "        if (\$quoteOrgOn && \$crmClientId > 0) {";
$CCQ    = "        if (\$this->quoteOrg()['on']) {\n            \$check = \$this->checkQuoteClient(\$crmClientId, \$where);";
$LEADF  = "            } elseif (\$this->quoteOrg()['on']) {\n"
        . "                // 5.18.99 (Uganda): the uCRM quote failed, so the salesperson is told — never a plugin-only reference\n"
        . "                // sent in its place. No WhatsApp, no lead update, no log row.";
$LEADF_OFF = str_replace("elseif (\$this->quoteOrg()['on'])", 'elseif (false)', $LEADF);
$LEADF_ON  = str_replace("elseif (\$this->quoteOrg()['on'])", 'elseif (true)', $LEADF);
$MANF   = "            } elseif (\$quoteOrgOn) {\n"
        . "                // 5.18.99 (Uganda): the uCRM quote failed, so the salesperson is told — never a plugin-only reference\n"
        . "                // sent in its place. No WhatsApp, no log row.";
$MANF_ON = str_replace('elseif ($quoteOrgOn)', 'elseif (true)', $MANF);
$PH5 = "            '{{HEADER_LOCALITY}}' => \$ug ? \$ugLocality : 'Juba, South Sudan',\n"
     . "            '{{ORG_LINES}}'       => \$ug ? implode('<br>', \$ugLines) : '{{ORG_STREET}}<br>{{ORG_CITY}}<br>{{ORG_PHONE}}<br>{{ORG_EMAIL}}<br>{{ORG_WEBSITE}}',\n"
     . "            '{{AMOUNT_HEAD}}'     => \$ug ? 'Amount (' . htmlspecialchars(dn_code(\$this->config)) . ')' : 'Amount (USD)',\n"
     . "            '{{PREPARED_ORG}}'    => \$ug ? '{{ORG_NAME}}' : 'DishNet Africa Ltd',\n"
     . "            '{{FOOTER_LOCALITY}}' => \$ug ? \$ugLocality : 'Juba, South Sudan',\n";
$PH5_LAST = str_replace("            '{{HEADER_LOCALITY}}' => ", "            '{{HEADER_LOCALITY}}'  => ", $PH5);   // the same five, last
$AGENT = "            '{{AGENT_NAME}}'     => htmlspecialchars(\$salesPerson),\n";
$SC_ENTRY = "    'ucrm_quote_organization_id' => ['text',\n"
          . "        'Uganda only (ignored on South Sudan): the uCRM organization app customers are filed under and app quotes are made for. Blank = the Uganda profile (organization 1); 0 or off = switched off, as 5.18.98'],\n";
$LTE_GATE = "if (QuoteOrganization::setting(is_array(\$config ?? null) ? \$config : [], isset(\$dataDir) ? (string)\$dataDir : null)['on']): ?>";
$RETRY_HEAD = "    // ── Retry failed quote for a specific KYC app ─────────────────────\n";
$W38_BLOCK = "    // Weakened copy W38: a sixth quote create — a copy of Q5 without the check.\n"
           . "    if (\$act === 'retry_quote_unguarded') {\n"
           . "        \$wqApp = (int)(\$_GET['app_id'] ?? 0);\n"
           . "        \$wqCrm = new CrmApiClient(rtrim(\$crm->getBaseUrl(), '/'), (string)((\$store->load('kyc_config.json') ?? [])['crm_auth_token'] ?? ''), 'x-auth-token');\n"
           . "        \$wqRes = \$wqCrm->post(\"clients/{\$wqApp}/quotes\", ['name' => 'W38']);\n"
           . "        \$ok2(['quote' => \$wqRes], 'Quote created.');\n"
           . "    }\n\n";
$QO_HELPER = "    /** 5.18.99: QuoteOrganization::setting() for this instance's configuration and data directory, read once. */\n";

$MUTANTS = [
    ['W1: setting() drops the tenant check (on for every tenant)',
        [[$QO, "            \$profile = TenantProfile::current(\$config, (\$dataDir !== null && \$dataDir !== '') ? \$dataDir : null);\n            if (\$profile->id() !== 'uganda') return \$off;",
               "            \$profile = TenantProfile::load('uganda', \$config);"]],
        ['A', ['S', '/^S ss: /'], ['S', '/^S ss_keyset: /']]],
    ['W2: the off-words are not honoured ("0" read as an id, then invalid)',
        [[$QO, "            if (is_string(\$v) && in_array(strtolower(\$v), self::OFF_WORDS, true)) {", '            if (false) {']],
        ['A', ['S', '/^S ug_off: /'], ['S', '/^S ug_off_word: /']]],
    ['W3: profiles/uganda.json loses quoting',
        [[$UG, "  \"quoting\": {\n", "  \"quoting_removed\": {\n"]],
        ['A', ['B', '/^B1 /'], ['B', '/^B2 /']]],
    ['W4: an invalid setting falls back to the profile instead of refusing',
        [[$QO, "            return ['on' => true, 'id' => null, 'source' => 'setting', 'error' => 'invalid'];\n", '']],
        ['A', ['C', '/^C8 .*R6/']]],
    ['W5: UcrmClientTarget::organization() keeps "prefer 2" before the configured id',
        [[$UT, "        // 5.18.99 (Uganda): the configured quoting organization, if this uCRM lists it; otherwise a refusal marked\n",
               "        \$w5 = \$this->organizations();\n"
             . "        if (\$w5 !== null && in_array(self::LEGACY_ORGANIZATION, \$w5, true)) return ['org' => self::LEGACY_ORGANIZATION, 'error' => ''];\n"
             . "        // 5.18.99 (Uganda): the configured quoting organization, if this uCRM lists it; otherwise a refusal marked\n"]],
        [['B', '/^B1 /'], ['B', '/^B2 /']]],
    ['W6: a configured id not in uCRM falls through to the legacy rule (a guess)',
        [[$UT, "            return ['org' => null, 'error' => 'uCRM does not list the company Uganda customers are filed under (organization #' . \$want",
               "            if (false) return ['org' => null, 'error' => 'uCRM does not list the company Uganda customers are filed under (organization #' . \$want"]],
        [['B', '/^B1 .*C1/']]],
    ['W7: KycCrmSync::target() uses new UcrmClientTarget($this->crm)',
        [[$KC, "        return \$this->target ??= UcrmClientTarget::forInstall(\$this->crm, \$this->config, \$this->gateDataDir());",
               "        return \$this->target ??= new UcrmClientTarget(\$this->crm);"]],
        [['B', '/^B3 /']]],
    ['W8: KycService passes no data directory to the gate (SSG-3)',
        [[$KS, "        return method_exists(\$this->store, 'getDataDir') ? (string)\$this->store->getDataDir() : \$this->dataDir;",
               "        return '';"]],
        [['A', '/^A vault-only Uganda: KycService/'], ['B', '/^B2 a vault-only/']]],
    ['W9: the Q1 check moved after saveApplication',
        [[$KS, $Q1, ''], [$KS, $SAVED, $SAVED . $Q1_MOVED]],
        [['C', '/^C1 /']]],
    ['W10: the Q1 check removed',
        [[$KS, $Q1, '']],
        [['C', '/^C1 /']]],
    ['W11: the Q2/Q3 check in postQuote removed',
        [[$KS, "        if (\$quoteOrg['on']) {\n            \$where = is_array(\$known)", "        if (false) {\n            \$where = is_array(\$known)"]],
        [['C', '/^C2 /'], ['C', '/^C3 /']]],
    ['W12: postQuote writes quote_error on a refusal',
        [[$KS, "                    'quote_refused_at'   => date('Y-m-d H:i:s'),\n",
               "                    'quote_refused_at'   => date('Y-m-d H:i:s'),\n                    'quote_error'        => \$text,\n"]],
        [['C', '/^C2 \(ii\)/']]],
    ['W13: the guard passes when organizationId is missing',
        [[$QO, "        if (\$n <= 0) {", "        if (\$n <= 0) return \$this->pass();\n        if (false) {"]],
        [['C', '/^C8 .*R2/']]],
    ['W14: the guard passes when the client read fails (fail-open)',
        [[$QO, "            if (\$c === null) {", "            if (\$c === null) return \$this->pass();\n            if (false) {"]],
        [['C', '/^C8 .*client_/']]],
    ['W15: the guard skips the "configured organization listed" step',
        [[$QO, "        if (!in_array(\$m, \$ids, true)) {", '        if (false) {']],
        [['C', '/^C8 .*R5/']]],
    ['W16: the guard reads with the plugin\'s client instead of $quoteCrm',
        [[$KS, "            \$quoteCheck = (new QuoteOrganization(\$quoteCrm, \$quoteOrg))->checkClient((int)\$customerId, 'kyc-existing');",
               "            \$quoteCheck = (new QuoteOrganization(\$this->crm, \$quoteOrg))->checkClient((int)\$customerId, 'kyc-existing');"],
         [$KS, "            \$check = (new QuoteOrganization(\$quoteCrm, \$quoteOrg))->checkClient(\$crmClientId, \$where, \$known);",
               "            \$check = (new QuoteOrganization(\$pluginCrm, \$quoteOrg))->checkClient(\$crmClientId, \$where, \$known);"]],
        [['C', '/^C9 /']]],
    ['W17: the Q4 check removed from sendLeadQuote, sendManualQuote and createCrmQuote',
        [[$QS, $LEADQ, '        if (false) {'],
         [$QS, $MANQ . "\n            \$check = \$this->checkQuoteClient(\$crmClientId, 'manual');",
               "        if (false) {\n            \$check = \$this->checkQuoteClient(\$crmClientId, 'manual');"],
         [$QS, $CCQ, "        if (false) {\n            \$check = \$this->checkQuoteClient(\$crmClientId, \$where);"]],
        [['C', '/^C4 /'], ['C', '/^C5 /']]],
    ['W18: sendLeadQuote on a refusal still sends the plugin-only WhatsApp',
        [[$QS, "            \$check = \$this->checkQuoteClient((int)\$lead['crm_client_id'], 'lead');\n            if (!\$check['ok']) return",
               "            \$check = \$this->checkQuoteClient((int)\$lead['crm_client_id'], 'lead');\n            if (false) return"],
         [$QS, $LEADF, $LEADF_OFF]],
        [['C', '/^C4 /'], 'H']],
    ['W19: retry_quote (api_cron_debug.php) unguarded',
        [[$CD, "        if (\$_qoRetry['on']) {", '        if (false) {']],
        [['C', '/^C6 /']]],
    ['W20: the LTE fallback proforma sent on a refusal',
        [[$CM, "                    } elseif (!empty(\$crmResult['refused'])) {", '                    } elseif (false) {']],
        [['C', '/^C7 /']]],
    ['W21: plain() keeps < & "',
        [[$QO, "        \$s = str_replace(['<', '>', '&', '\"'], '', \$s);", "        \$s = str_replace(['>'], '', \$s);"]],
        [['C', '/^C10 /']]],
    ['W22: the refusal log line carries the organization\'s name',
        [[$QO, "        \$cl = self::orgLabel(\$n, \$rows); \$q = self::orgLabel(\$m, \$rows);\n        \$this->logLine(\$clientId, \$where, 'filed under organization #' . \$n . ', quotes are made under #' . \$m);",
               "        \$cl = self::orgLabel(\$n, \$rows); \$q = self::orgLabel(\$m, \$rows);\n        \$this->logLine(\$clientId, \$where, 'filed under ' . \$cl . ', quotes are made under #' . \$m);"]],
        [['C', '/^C11 /']]],
    ['W23: R1 with the two organizations swapped',
        [[$QO, "'R1' => 'This customer is filed under {CLIENT_ORG} in uCRM. Move them to {QUOTE_ORG} in uCRM",
               "'R1' => 'This customer is filed under {QUOTE_ORG} in uCRM. Move them to {CLIENT_ORG} in uCRM"]],
        [['C', '/^C1 /']]],
    ['W24: UcrmLeadSync ignores the configured fallback',
        [[$LS, "        if (\$org === null) {\n            \$qo = QuoteOrganization::setting(", "        if (false) {\n            \$qo = QuoteOrganization::setting("]],
        ['D']],
    ['W25: UcrmLeadSync posts the configured id without checking it is listed',
        [[$LS, "                    if (in_array((int)\$qo['id'], \$ids, true)) {", '                    if (true) {']],
        [['D', '/skipped/']]],
    ['W26: UcrmLeadSync falls to the commonest of 50 when the configured organization is missing',
        [[$LS, "            if (\$org === null && \$orgs !== [] && !\$qo['on']) {", "            if (\$org === null && \$orgs !== []) {"]],
        ['D']],
    ['W27: letterhead_client_id not passed at sendLeadQuote',
        [[$QS, "                'follow_up'  => \$lead['follow_up_date'] ?? '',\n                'letterhead_client_id' => \$crmClientId,   // 5.18.99 (read on Uganda only)\n",
               "                'follow_up'  => \$lead['follow_up_date'] ?? '',\n"]],
        [['E', '/letterhead_client_id/']]],
    ['W28: letterhead_client_id honoured where the gate is off',
        [[$QS, "        if (\$letter === null && \$this->quoteOrg()['on']) {", "        if (\$letter === null) {"]],
        [['S', '/^S ss: /'], ['E', '/control/']]],
    ['W29: the letterhead with no client keeps "selected" on Uganda',
        [[$QS, "            if ((\$orgId === null || \$orgId <= 0) && \$qo['on'] && is_int(\$qo['id'] ?? null) && \$qo['id'] > 0) {", '            if (false) {']],
        [['E', '/Cash Proforma|preview/']]],
    ['W30: QuotePdfSource still passes street on Uganda (no pdfFields)',
        [[$PS, "            \$res = \$gen->generate(\$quote, \$client, \$quoteOrg['on']\n                ? QuoteOrganization::pdfFields",
               "            \$res = \$gen->generate(\$quote, \$client, false\n                ? QuoteOrganization::pdfFields"]],
        ['F']],
    ['W31: PluginQuotePdf keeps "Juba, South Sudan" in the footer on Uganda',
        [[$PQ, "            '{{FOOTER_LOCALITY}}' => \$ug ? \$ugLocality : 'Juba, South Sudan',", "            '{{FOOTER_LOCALITY}}' => 'Juba, South Sudan',"]],
        ['F']],
    ['W32: PluginQuotePdf keeps the South Sudan phone default on Uganda',
        [[$PQ, "            \$orgPhone   = htmlspecialchars(\$ugText(\$org['phone'] ?? ''));",
               "            \$orgPhone   = htmlspecialchars(\$ugText(\$org['phone'] ?? '') ?: '0925831111');"]],
        ['F']],
    ['W33: the PDF gate applied on South Sudan (Kampala on South Sudan)',
        [[$PQ, "        \$ug = QuoteOrganization::setting(\$this->config, \$this->dataDir)['on'];", '        $ug = true;']],
        [['S', '/^S ss: /']]],
    ['W34: push_products_to_ucrm removed from $_gateAdminOnly',
        [[$PA, "        'push_products_to_ucrm',\n", '']],
        ['G']],
    ['W35: item 7: a failed create still returns ok=true',
        [[$QS, "        return ['ok' => false, 'error' => \$texts[\$code]];",
               "        return ['ok' => true, 'quote_id' => null, 'quote_number' => null, 'emailed_by_plugin' => false, 'email_error' => '', 'error' => \$texts[\$code]];"]],
        ['H']],
    ['W36: item 7 applied where the gate is off (South Sudan shows the failure)',
        [[$QS, $LEADF, $LEADF_ON]],
        [['S', '/^S ss: /']]],
    ['W36b: item 7 applied where the gate is off, on the Manual quote (sendManualQuote\'s own branch)',
        [[$QS, $MANF, $MANF_ON]],
        [['S', '/^S ss: /']]],
    ['W37: post_kyc.php keeps the success flash on quote_refused',
        [[$PK, "              : (!empty(\$result['data']['quote_refused']) ? 'warning' : 'success'));   // 5.18.99: no quote (Uganda) is amber",
               "              : 'success');"]],
        [['C', '/^C2 /']]],
    ['W38: a sixth, unguarded quote POST (a copy of Q5 without the check)',
        [[$CD, $RETRY_HEAD, $W38_BLOCK . $RETRY_HEAD]],
        [['I', '/^I1 /']]],
    ['W39: tools/set_config.php without the new entry',
        [[$SC, $SC_ENTRY, '']],
        [['I', '/^I3 control: the tool lists the key/']]],
    ['W40: a migration file added',
        [['migrations/999_test.sql', null, "-- weakened copy W40: a migration 5.18.99 must not hold\nSELECT 1;\n"]],
        [['I', '/^I5 /']]],
    ['W41: the unticked Lead/Manual path skips the check (CC-1)',
        [[$QS, $LEADQ, "        if (\$createCrmQuote && \$this->quoteOrg()['on'] && (int)(\$lead['crm_client_id'] ?? 0) > 0) {"],
         [$QS, $MANQ, "        if (\$createCrm && \$quoteOrgOn && \$crmClientId > 0) {"]],
        [['C', '/^C4u /'], ['C', '/^C5u /']]],
    ['W42: Q1 uses the raw customer_id in the POST path (CC-3)',
        [[$KS, "            \$customerId = (string)(int)\$customerId;\n", '']],
        [['C', '/^C12 /']]],
    ['W43: 401/403 read as R4 instead of R7 (CC-4, OPS-5)',
        [[$QO, "                if (\$code === 401 || \$code === 403) {", '                if (false) {']],
        [['C', '/^C8 .*client_40[13]/']]],
    ['W44: the guard ignores the injected sink and writes PluginLog too (OPS-3)',
        [[$QO, "            if (\$this->log !== null) { (\$this->log)(\$line); return; }", "            if (\$this->log !== null) { (\$this->log)(\$line); }"]],
        [['C', '/^C13 /']]],
    ['W45: the five new PDF placeholders replaced last (SSG-4)',
        [[$PQ, $PH5, ''], [$PQ, $AGENT, $AGENT . $PH5_LAST]],
        [['S', '/^S ss: /'], 'F']],
    ['W46: QuotePdfSource.php without its require_once of QuoteOrganization.php (SSG-5)',
        [[$PS, "require_once __DIR__ . '/QuoteOrganization.php';   // 5.18.99: the Uganda fallback PDF's organization fields\n", '']],
        [['I', '/^I7 /']]],
    ['W47: set_config keeps the given value untrimmed (OPS-4)',
        [[$SC, '    $qoGiven = trim($new);', '    $qoGiven = $new;']],
        [['I', '/^I3 --value " OFF "/']]],
    ['W48: set_config saves an id uCRM does not list (OPS-4)',
        [[$SC, "        if (!in_array((int)\$new, \$qoIds, true)) {", '        if (false) {']],
        [['I', '/^I3 --value 5, which uCRM does not list/']]],
    ['W49: setting() does not trim (OPS-4)',
        [[$QO, "        if (is_string(\$v)) \$v = trim(\$v);\n", '']],
        ['A']],
    ['W50: UcrmLeadSync\'s configuration refusal writes no quote_org_missing flag (OPS-7)',
        [[$LS, "                \$this->flag(\$leadId, 'quote_org_missing', (string)\$defaults['why']);\n", '']],
        [['D', '/quote_org_missing/']]],
    ['W51: F7a used for an uncertain failure (5xx, curl error, 2xx without id) (CC-5)',
        [[$QS, "            \$code = (\$http === 401 || \$http === 403) ? 'F7k' : ((\$http >= 400 && \$http <= 499) ? 'F7a' : 'F7u');",
               "            \$code = (\$http === 401 || \$http === 403) ? 'F7k' : 'F7a';"],
         [$QS, "            \$why  = 'uCRM answered without a quotation number';\n            \$code = 'F7u';",
               "            \$why  = 'uCRM answered without a quotation number';\n            \$code = 'F7a';"]],
        ['H']],
    ['W52: {WHY} taken from a stale lastError (OPS-12)',
        [[$QS, "            \$why  = 'uCRM answered without a quotation number';",
               "            if (!class_exists('UcrmClientTarget')) require_once __DIR__ . '/UcrmClientTarget.php';\n"
             . "            \$why  = UcrmClientTarget::describe((array)\$this->crm->getLastError());"],
         [$QO, "                \$this->logLine(\$clientId, \$where, 'uCRM could not be read (uCRM answered with something that is not JSON)');",
               "                \$this->logLine(\$clientId, \$where, 'uCRM could not be read (' . self::describe(method_exists(\$this->crm, 'getLastError') ? (array)\$this->crm->getLastError() : []) . ')');"]],
        ['H', ['C', '/^C11 /']]],
    ['W53: companyDetails keeps the class constants as the last fallback on Uganda (CC-7)',
        [[$QS, "        \$profileLast = \$this->quoteOrg()['on'] ? \$this->profileLetterhead() : null;", '        $profileLast = null;']],
        [['E', '/organizations unreadable/']]],
    ['W54: post_kyc.php keeps success when the retry\'s quote was refused (CC-9)',
        [[$PK, "    flash(\$retry['message'], \$retry['ok'] ? (!empty(\$retry['quote_refused']) ? 'warning' : 'success')   // 5.18.99",
               "    flash(\$retry['message'], \$retry['ok'] ? 'success'"]],
        [['C', '/^C3 /']]],
    ['W55: Q2 GETs the client although the create answer carried organizationId (OPS-1)',
        [[$QO, "        if (is_array(\$known) && isset(\$known['organizationId']) && is_int(\$known['organizationId']) && \$known['organizationId'] > 0) {",
               '        if (false) {']],
        [['C', '/^C2 \(i\) /']]],
    ['W56: the guard reads the organization list when the client is already in the configured organization (OPS-6)',
        [[$QO, "        if (\$n === \$m) return \$this->pass();", "        if (\$n === \$m) { \$this->organizations(); return \$this->pass(); }"]],
        [['C', '/^C14 /']]],
    ['W57: postQuote records no quote_refused* fields (OPS-1)',
        [[$KS, $REFUSED3, "                    'quote_refused_none' => true,\n"]],
        [['C', '/^C2 /'], ['C', '/^C3 /']]],
    ['W58: the LTE screens\' fragment emitted on South Sudan (kyc_form) and missing on Uganda (lte_dashboard) (OPS-9)',
        [[$KF, $LTE_GATE, 'if (true): ?>'], [$LD, $LTE_GATE, 'if (false): ?>']],
        [['S', '/^S ss: /'], ['C', '/^C7 /']]],
    ['W59: a new post(\'clients\' site outside the allow-list (CC-8)',
        [[$QS, $QO_HELPER, "    /** Weakened copy W59: a client create outside the allow-list. */\n"
                          . "    private function w59MakeClient(): ?array\n    {\n        return \$this->crm->post('clients', []);\n    }\n\n" . $QO_HELPER]],
        [['I', '/^I8 /']]],
    ['W60: the amber line keeps "an admin can retry it now from Orders" on a configuration refusal (OPS-11)',
        [[$KS, "                        . (!empty(\$placed['config'])   // 5.18.99: a retry cannot fix the company setting",
               "                        . (false   // 5.18.99: a retry cannot fix the company setting"]],
        [['B', '/^B2 /']]],
    ['W61: the fallback PDF names the configured organization, not the client\'s own (SPEC §2.5)',
        [[$QO, "        \$want = (int)(\$client['organizationId'] ?? 0);", '        $want = 0;']],
        ['F']],
];
$TABLE = count($MUTANTS);
if (isset($opt['mutants'])) {                                               // a part of the table only: the control still runs
    $only = '#' . str_replace('#', '\\#', (string)$opt['mutants']) . '#';
    $MUTANTS = array_values(array_filter($MUTANTS, function (array $m) use ($only) { return preg_match($only, $m[0]) === 1; }));
    echo '(--mutants: ' . count($MUTANTS) . " of the table's {$TABLE} weakened copies)\n";
    if ($MUTANTS === []) { is_(false, 'the --mutants pattern names at least one weakened copy'); qcm_done(); }
}

// ═══════════════════════════════════════════════════════════════════════════
echo "1. The control: the test passes on this checkout and on an unchanged copy, every section\n";
is_($TABLE === 62, 'the table holds W1–W60, W36b and W61, sixty-two weakened copies', (string)$TABLE);
$labels = array_map(function ($m) { return explode(':', $m[0])[0]; }, $MUTANTS);
is_(count(array_unique($labels)) === count($labels), 'no weakened copy is listed twice');
$baseTree = null; $whyBase = '';
if (isset($opt['base'])) {
    $baseTree = rtrim((string)$opt['base'], '/');
    if (!is_file($baseTree . '/manifest.json')) { $baseTree = null; $whyBase = 'the given --base holds no plugin'; }
} else {
    [$baseTree, $whyBase] = sj_baseline_tree(QCM_BASE);
}
$bm = $baseTree !== null ? json_decode((string)@file_get_contents($baseTree . '/manifest.json'), true) : null;
is_($baseTree !== null && (string)($bm['information']['version'] ?? '') === '5.18.98',
    'the ce7ac388 tree the comparisons need is at hand (5.18.98, from Git, taken once)', $whyBase);
if ($baseTree === null) qcm_done();

$expectedSections = str_split(QCM_ALL);
$secsRan = function (array $oks): array { return array_values(array_diff(array_keys($oks), ['setup'])); };
[$rc0, $by0, $tally0, $fatal0, $oks0] = qcm_judge($lib, $lib, $baseTree, QCM_ALL);
is_($rc0 === 0 && $by0 === [] && $tally0 !== null && $tally0[1] === 0 && $tally0[0] > 200 && $secsRan($oks0) === $expectedSections,
    'the test passes on this checkout: ' . ($tally0 ? "{$tally0[0]} passed, 0 failed" : 'no tally') . ', every section A–I and S ran',
    'exit ' . $rc0 . ' ' . json_encode($by0, JSON_UNESCAPED_UNICODE) . ' sections ' . implode('', $secsRan($oks0)) . ' ' . $fatal0);
[$ctl, $why] = qcm_copy($lib, [[$QO, "final class QuoteOrganization\n", "final class QuoteOrganization\n"]]);
if ($why !== '') {
    is_(false, 'the unchanged copy: its anchor occurs once and the copy lints', $why);
    exec('rm -rf ' . escapeshellarg($ctl));
    qcm_done();
}
[$rc, $by, $tally, $fatal, $oks] = qcm_judge($lib, $ctl, $baseTree, QCM_ALL);
is_($rc === 0 && $by === [] && $tally !== null && $tally === $tally0 && $oks === $oks0,
    'the unchanged copy passes every section with the same tally (' . ($tally ? "{$tally[0]} passed, 0 failed" : 'no tally') . '), section by section',
    'exit ' . $rc . ' tally ' . json_encode($tally) . ' ' . json_encode($by, JSON_UNESCAPED_UNICODE) . ' ' . $fatal);
// A copy is judged by the sections it names only: each section, run alone on the unchanged copy, must pass with exactly
// the assertions it holds in the full run — so a section does not lean on another, and --sections runs what it names.
$used = [];
foreach ($MUTANTS as $m) foreach ($m[2] as $x) $used[is_array($x) ? $x[0] : $x] = true;
foreach ($expectedSections as $s) {
    if (!isset($used[$s])) continue;
    [$rcS, $byS, $tallyS, $fatalS, $oksS] = qcm_judge($lib, $ctl, $baseTree, $s);
    is_($rcS === 0 && $byS === [] && $secsRan($oksS) === [$s] && ($oksS[$s] ?? 0) === ($oks0[$s] ?? -1),
        "section {$s} alone passes on the unchanged copy with its " . ($oks0[$s] ?? 0) . ' assertions, and nothing else runs',
        json_encode([$rcS, $byS, $secsRan($oksS), $oksS[$s] ?? null, $fatalS], JSON_UNESCAPED_UNICODE));
}
exec('rm -rf ' . escapeshellarg($ctl));
if ($fail > 0) { echo "\n  The control failed: no weakened copy is judged against a harness that does not pass unchanged.\n"; qcm_done(); }

// ═══════════════════════════════════════════════════════════════════════════
echo "\n2. The weakened copies: each must fail every part the table names for it\n";
$caught = 0;
foreach ($MUTANTS as [$label, $edits, $expect]) {
    $id = explode(':', $label)[0];
    [$tmp, $why] = qcm_copy($lib, $edits);
    if ($why !== '') {
        is_(false, "{$id}: the copy is made — each anchor occurs once, is replaced once, the copy holds the change and lints", $why);
        exec('rm -rf ' . escapeshellarg($tmp));
        continue;
    }
    $letters = '';
    foreach ($expect as $x) { $s = is_array($x) ? $x[0] : $x; if (strpos($letters, $s) === false) $letters .= $s; }
    $letters = implode('', array_values(array_intersect(str_split(QCM_ALL), str_split($letters))));
    [$rc, $by, $tally, $fatal, $oks] = qcm_judge($lib, $tmp, $baseTree, $letters);
    $missed = [];
    foreach ($expect as $x) {
        [$part, $pat] = is_array($x) ? [$x[0], $x[1]] : [$x, null];
        $lines = $by[$part] ?? [];
        $hit = $rc !== 0 && $lines !== [] && ($pat === null || preg_grep($pat, $lines) !== []);
        if (!$hit) $missed[] = $part . ($pat !== null ? " {$pat}" : '');
    }
    $failedParts = array_keys($by);
    $named = implode(', ', array_map(function ($x) { return is_array($x) ? $x[0] . ' ' . $x[1] : $x; }, $expect));
    $ok = $missed === [];
    if ($ok) $caught++;
    is_($ok, sprintf('%-4s failed: %-12s %s', $id, $failedParts === [] ? 'nothing' : implode(', ', $failedParts),
        $ok ? "CAUGHT at {$named}" : 'SURVIVED: not failed at ' . implode('; ', $missed)) . ' — ' . substr($label, strlen($id) + 2),
        'exit ' . $rc . ($fatal !== '' ? '; ' . $fatal : ''));
    $nF = 0; foreach ($by as $l) $nF += count($l);
    $first = '';
    foreach ($expect as $x) {
        [$part, $pat] = is_array($x) ? [$x[0], $x[1]] : [$x, null];
        $m = $pat === null ? ($by[$part] ?? []) : preg_grep($pat, $by[$part] ?? []);
        if ($m !== []) { $first = $part . ': ' . reset($m); break; }
    }
    echo '         ' . $nF . ' assertion(s) failed' . ($tally ? " ({$tally[0]} passed)" : '') . ($first !== '' ? '; at the named part, first: ' . substr($first, 0, 150) : '') . "\n";
    exec('rm -rf ' . escapeshellarg($tmp));
}
echo "\n  {$caught} of " . count($MUTANTS) . " weakened copies caught where the table says\n";

// ═══════════════════════════════════════════════════════════════════════════
echo "\n3. The end check\n";
clearstatcache();
$defAfter = is_file($defVault) ? [hash_file('sha256', $defVault), filemtime($defVault)] : null;
is_($defAfter === $defBefore, 'the default vault beside this checkout is untouched (' . ($defBefore === null ? 'still absent' : 'same bytes and mtime') . ')');
is_(strpos(sys_get_temp_dir(), $own) === 0, 'every temporary file of this run lives in its own folder, removed when it ends', sys_get_temp_dir());
qcm_done();

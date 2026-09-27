<?php
/**
 * test_quote_tax_line.php — 5.18.49, what every Uganda quotation says about tax (docs/42 §9).
 *
 * The operator, 27 Sep 2026, asked which was right for plans and accessories — the quotation PDF's "No VAT is charged
 * on this quotation" or the assistant's "prices include VAT" — and answered: "we are giving quote including all the
 * taxes"; for the WhatsApp quotation summary: "we are providing quote including UCC and URA charges". Then chose one
 * sentence for every quotation, with or without a kit, and chose it for the assistant's price fact too.
 *
 *   1. the sentence: the operator's words, pinned here as written, with no figure in it
 *   2. where it applies: Uganda, never South Sudan
 *   3. the app and KYC quotation (QuotationService::buildProformaMessage): under the TOTAL in Uganda; South Sudan byte
 *      for byte the message before (the class at 4c01d1c, from git, where the repository is there)
 *   4. the quotation PDF (ucrm_pdf_templates/quotation_uganda): clause 2 says it on every quote without tax lines
 *   5. the assistant's price fact (ai_fact_prices): in the prompt, the operator's own text, never said twice beside the
 *      kit line; the setting and the check tool
 *   6. weakened copies of the code, each of which must fail this test
 *
 * The quote.add webhook's summary is proved through the real webhook in tests/test_quote_summary.php, section K.
 *
 * Against another copy of the plugin:   php test_quote_tax_line.php --root=DIR [--no-mutants]
 */
declare(strict_types=1);

$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$repo = dirname(__DIR__, 2);
$withMutants = !isset($opt['no-mutants']);

$tmp = sys_get_temp_dir() . '/dn_t49_' . getmypid() . '_' . bin2hex(random_bytes(3));
@mkdir($tmp . '/ug', 0777, true);
@mkdir($tmp . '/ss', 0777, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });
// Run alone, the vault would be written beside the plugin; tests/run.sh gives every test its own, and so does this.
if ((string)getenv('DN_VAULT_FILE') === '') putenv('DN_VAULT_FILE=' . $tmp . '/vault.json');
ini_set('error_log', $tmp . '/php_errors.log');

foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'CrmApiClient', 'NotificationService',
          'QuoteTaxLine', 'QuotationService', 'KnowledgeSeeder', 'KnowledgeBase', 'PlanCatalogue', 'PlanFenceGuard',
          'KitTaxNote', 'ReplyPrivacyGuard', 'DishNetAiBrain'] as $lib) {
    require_once "{$root}/lib/{$lib}.php";
}

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; } else { $fail++; echo "  FAIL {$m}\n"; if ($d !== '') echo "       {$d}\n"; }
}

// The operator's words, 27 Sep 2026 — written out here, never read from the code under test.
const APPROVED = 'All prices include all taxes — URA taxes and UCC charges are already in them. Nothing is added on top.';
const SEP      = '━━━━━━━━━━━━━━━━━━━━━━';

// ════════════════════════════════════════════════════════════════════════════
echo "\n1. The sentence: the operator's words\n";
is_(QuoteTaxLine::TEXT === APPROVED, 'the sentence is the one the operator chose, word for word', QuoteTaxLine::TEXT);
is_(preg_match('/\d/', QuoteTaxLine::TEXT) === 0, 'no figure in it — no amount and no rate: prices come from uCRM only');
is_(strpos(QuoteTaxLine::TEXT, 'URA') !== false && strpos(QuoteTaxLine::TEXT, 'UCC') !== false,
    'it names both, as the operator did: "including UCC and URA charges"');

// ════════════════════════════════════════════════════════════════════════════
echo "\n2. Where it applies: Uganda, never South Sudan\n";
is_(QuoteTaxLine::applies(['currency_code' => 'UGX']) === true, 'an install in shillings is Uganda');
is_(QuoteTaxLine::applies(['tenant_profile' => 'uganda']) === true, 'an install that names the Uganda profile');
is_(QuoteTaxLine::applies(['currency_code' => 'USD']) === false, 'South Sudan (USD): no');
is_(QuoteTaxLine::applies([]) === false, 'an install that names nothing is the South Sudan default: no');

// ════════════════════════════════════════════════════════════════════════════
echo "\n3. The app and KYC quotation (QuotationService::buildProformaMessage)\n";
$ITEMS = [['label' => 'Starlink Standard Kit', 'quantity' => 1, 'price' => 2649000],
          ['label' => 'Residential (up to 400 Mbps)', 'quantity' => 1, 'price' => 329000],
          ['label' => 'Professional Installation', 'quantity' => 1, 'price' => 150000]];
// No uCRM answers (a closed port): the company details fall back to the configuration, the same for both builds.
$CRM = ['crm_base_url' => 'http://127.0.0.1:9', 'crm_auth_token' => 'TEST'];
$build = function (string $class, string $tenant, array $cfg, array $opts = []) use ($tmp, $ITEMS, $CRM): string {
    $dir = "{$tmp}/{$tenant}";
    $svc = new $class(SqliteStore::create($dir), $dir, $cfg + $CRM);
    return $svc->buildProformaMessage('Q-000200', 'Test Customer', $ITEMS, 3128000.0, ['type' => 'Quotation'] + $opts);
};
$UGC = ['currency_code' => 'UGX'];
$SSC = ['currency_code' => 'USD'];
$ug = $build('QuotationService', 'ug', $UGC);
$ss = $build('QuotationService', 'ss', $SSC);
$totalLine = function (string $m): string {
    foreach (explode("\n", $m) as $l) if (strpos($l, '💰 *TOTAL:') === 0) return $l;
    return '';
};
$tu = $totalLine($ug);
is_($tu !== '' && strpos($ug, $tu . "\n✅ " . APPROVED . "\n" . SEP) !== false,
    'Uganda: the sentence, right under the TOTAL, before the closing rule', $ug);
is_(substr_count($ug, APPROVED) === 1, '…once');
$ts = $totalLine($ss);
is_(strpos($ss, 'All prices include all taxes') === false && $ts !== '' && strpos($ss, $ts . "\n" . SEP) !== false,
    'South Sudan: no sentence — the TOTAL, then the closing rule, as before', $ss);
$paid = $build('QuotationService', 'ug', $UGC, ['amount_paid' => 1000000.0, 'balance' => 2128000.0]);
is_(strpos($paid, $totalLine($paid) . "\n✅ " . APPROVED . "\n✅ *Paid:") !== false,
    'Uganda with a payment recorded: the sentence under the TOTAL, then what was paid', $paid);

// The class as it was before this change, from git: South Sudan byte for byte, Uganda with exactly one line more.
$BEFORE = '4c01d1c';
$old = is_dir("{$repo}/.git")
    ? (string)shell_exec('git -C ' . escapeshellarg($repo) . ' show ' . $BEFORE . ':dishnet-hybrid-sudan/lib/QuotationService.php 2>/dev/null')
    : '';
if (strpos($old, 'class QuotationService') !== false) {
    $old = str_replace(['class QuotationService', '__DIR__'], ['class QuotationService_Before', var_export("{$root}/lib", true)], $old);
    file_put_contents("{$tmp}/QuotationService_Before.php", $old);
    require_once "{$tmp}/QuotationService_Before.php";
    $ssOld = $build('QuotationService_Before', 'ss', $SSC);
    $ugOld = $build('QuotationService_Before', 'ug', $UGC);
    is_($ss === $ssOld, "South Sudan: byte for byte the message the class at {$BEFORE} built", "got:\n{$ss}\nwant:\n{$ssOld}");
    is_($ug === str_replace($totalLine($ugOld) . "\n", $totalLine($ugOld) . "\n✅ " . APPROVED . "\n", $ugOld),
        "Uganda: the message the class at {$BEFORE} built, with that one line added and nothing else", "got:\n{$ug}\nwant:\n{$ugOld}");
} else {
    echo "  --   the repository is not beside this copy of the plugin: the comparison with {$BEFORE} is skipped\n";
}

// ════════════════════════════════════════════════════════════════════════════
echo "\n4. The quotation PDF: clause 2 says it on every quote without tax lines\n";
$tpl = (string)file_get_contents("{$root}/ucrm_pdf_templates/quotation_uganda/template.html.twig");
$lines = array_values(array_filter(explode("\n", $tpl), fn($l) => strpos($l, '2. Currency &amp; Pricing:') !== false));
$line = $lines[0] ?? '';
is_(count($lines) === 1, 'clause 2 is one line, which staff can paste alone');
$pdfSentence = 'All prices include all taxes &mdash; URA taxes and UCC charges are already in them. Nothing is added on top.';
is_(strpos($line, '{% if totals.taxes|length > 0 %}VAT is itemised in the totals on page 1.{% else %}' . $pdfSentence . '{% endif %}') !== false,
    'where uCRM itemises tax, it says so; everywhere else, the sentence', $line);
is_(html_entity_decode($pdfSentence, ENT_QUOTES | ENT_HTML5, 'UTF-8') === APPROVED, '…in the same words as the WhatsApp line');
is_(substr_count($tpl, $pdfSentence) === 1, '…once in the template');
is_(strpos($tpl, 'No VAT is charged') === false, '"No VAT is charged on this quotation" is gone: it said the opposite of the chat');
is_(strpos($tpl, 'has_kit') === false && strpos($tpl, 'The kit price includes') === false,
    'no kit test and no kit sentence: every quotation says the same (the kit sentence stays in chat replies)');
is_(preg_match('/\|(?!length)[a-z_]+/', $line) === 0, 'no filter but length, which the template already uses');
is_(is_file("{$repo}/scripts/harness/quotation-template/render.php") || !is_dir("{$repo}/scripts"),
    'the rendering proof, real Twig 2 and 3, is scripts/harness/quotation-template');

// ════════════════════════════════════════════════════════════════════════════
echo "\n5. The assistant's price fact: the same sentence\n";
$FACT = ['ai_provider' => 'openai', 'openai_api_key' => 'k', 'ai_fact_prices' => APPROVED];
$p = (new DishNetAiBrain($FACT))->promptPreview(['channel' => 'sales', 'message' => 'x', 'identity_state' => 'unknown']);
is_(strpos($p, '- PRICES: ' . APPROVED . ' This is a stated fact you may repeat;') !== false,
    'set, it reaches the prompt as a fact the assistant may repeat, with the no-arithmetic fence');
is_(in_array(APPROVED, DishNetAiBrain::operatorText($FACT), true),
    'it is the operator\'s own text, so repeating it is never read as quoting the prompt');
$UGAI = ['ai_hardware_expert' => '1'];
$reply = "The Starlink Standard Kit is 2,649,000 UGX.\n\n" . APPROVED;
$k = KitTaxNote::apply($reply, $UGAI, [2649000.0]);
is_(!$k['appended'] && $k['reason'] === 'the reply already says it in its own words',
    'a kit quote that already carries it gets no second tax line from 5.18.48', json_encode($k));
$set = (string)@file_get_contents("{$root}/tools/set_config.php");
is_(strpos($set, "'ai_fact_prices' => ['text',") !== false && strpos($set, "Uganda uses the quotations") !== false,
    'set_config.php manages the fact, and its help shows the quotations\' sentence');
if (is_file("{$repo}/scripts/lib/ai_check.php")) {
    $chk = (string)file_get_contents("{$repo}/scripts/lib/ai_check.php");
    is_(strpos($chk, "out('price fact',") !== false && strpos($chk, "\"the quotations' sentence: \"") !== false
        && strpos($chk, "'QuoteTaxLine'") !== false,
        'the check tool reports the fact, and says when it is the quotations\' sentence');
} else {
    echo "  --   the scripts are not beside this copy of the plugin (a sandbox): skipped\n";
}

// ════════════════════════════════════════════════════════════════════════════
if ($withMutants) {
    echo "\n6. Weakened copies of the code must each fail this test\n";
    $sandbox = function () use ($root): string {
        $t = sys_get_temp_dir() . '/dn-t49-m-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($t, 0700, true);
        exec('cp -r ' . escapeshellarg("{$root}/lib") . ' ' . escapeshellarg("{$root}/manifest.json") . ' '
            . escapeshellarg("{$root}/ucrm_pdf_templates") . ' ' . escapeshellarg($t));
        foreach (['workers', 'migrations', 'tools', 'assets', 'profiles', 'tests'] as $d) {
            if (is_dir("{$root}/{$d}")) symlink("{$root}/{$d}", "{$t}/{$d}");
        }
        return $t;
    };
    $TPL = 'ucrm_pdf_templates/quotation_uganda/template.html.twig';
    $MUTANTS = [
        ['lib/QuoteTaxLine.php', "->id() === 'uganda';", "->id() !== '';", 'every install counted as Uganda'],
        ['lib/QuoteTaxLine.php', 'URA taxes and UCC charges are', 'URA taxes and UCC fees are', 'one word of the sentence changed'],
        ['lib/QuotationService.php', "if (QuoteTaxLine::applies(\$this->config, \$this->dataDir)) \$lines[] = '✅ ' . QuoteTaxLine::TEXT;", '',
         'the app and KYC quotation never says it'],
        [$TPL, $pdfSentence, 'No VAT is charged on this quotation.', 'the PDF back to "No VAT is charged"'],
        [$TPL, '{% if totals.taxes|length > 0 %}VAT is itemised in the totals on page 1.{% else %}', '{% if false %}{% else %}',
         'the PDF claims all taxes are inside even where uCRM itemises VAT'],
    ];
    foreach ($MUTANTS as [$rel, $oldS, $newS, $label]) {
        $t = $sandbox();
        $file = "{$t}/{$rel}";
        $s = (string)file_get_contents($file);
        $n = substr_count($s, $oldS);
        if ($n !== 1) { is_(false, "weakened copy \"{$label}\": its anchor occurs once in {$rel}", "found {$n} times"); exec('rm -rf ' . escapeshellarg($t)); continue; }
        file_put_contents($file, str_replace($oldS, $newS, $s));
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($t) . ' --no-mutants 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, fn($l) => strpos($l, '  FAIL ') === 0));
        is_($rc !== 0 && $fails !== [], "caught: {$label}", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        if ($fails) echo '         first: ' . trim(substr($fails[0], 7, 110)) . "\n";
        exec('rm -rf ' . escapeshellarg($t));
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

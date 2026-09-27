<?php
/**
 * test_kit_tax_note.php — the taxes line under a Starlink kit price, and the quotation that says the same
 * (5.18.48, docs/42).
 *
 * The operator, 27 Sep 2026, on a live reply that quoted the Standard Kit at 2,649,000 and said nothing about tax:
 * "for kits can we clearly mention all the taxes are inclusive including UCC registration fees and URA taxes". They
 * approved the wording below and chose "match it" for the quotation PDF, whose clause 2 said "No VAT is charged on
 * this quotation." Uganda only (the hardware module); South Sudan: nothing appended, nothing read.
 *
 *   1. the note: the approved wording, where it applies, what counts as a Starlink kit
 *   2. when it is added to a reply, and when it is not
 *   3. the real worker: added after the price check, never to a refused reply; South Sudan unchanged
 *   4. listed as the operator's own text where it can be added
 *   5. the setting, ai_fact_kit_taxes
 *   6. the quotation template: clause 2, one line, the same words (rendered with real Twig in
 *      scripts/harness/quotation-template)
 *   7. the AI check tool reports it and counts it
 *   8. weakened copies must each fail
 *
 * Against another copy of the plugin:   php test_kit_tax_note.php --root=DIR [--no-mutants]
 */
declare(strict_types=1);

$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$repo = dirname(__DIR__, 2);
$withMutants = !isset($opt['no-mutants']);

foreach (['bootstrap_data', 'StoreInterface', 'JsonStore', 'SqliteStore', 'KnowledgeSeeder', 'KnowledgeBase',
          'ConversationService', 'BrainContext', 'PlanCatalogue', 'PlanFenceGuard', 'KitTaxNote', 'NetworkEquipment',
          'ShopCatalogue', 'PaymentOptions', 'ReplyPrivacyGuard', 'ReplyTotals', 'DishNetAiBrain', 'EventBus'] as $lib) {
    require_once "{$root}/lib/{$lib}.php";
}
require_once "{$root}/workers/WorkerBase.php";
require_once "{$root}/workers/AiReplyWorker.php";

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; } else { $fail++; echo "  FAIL {$m}\n"; if ($d !== '') echo "       {$d}\n"; }
}

const APPROVED = 'The kit price includes all taxes — URA taxes and the UCC registration fee are already in it. '
               . 'Nothing is added on top.';

// The live price list (docs/41 §8): the kits as uCRM names them, and the accessories as the shop catalogue does.
$PLANS = [['name' => 'Starlink Residential Lite ( up to 100 Mbps)', 'price' => 249000, 'period_months' => 1],
          ['name' => 'Residential (up to 400 Mbps)', 'price' => 329000, 'period_months' => 1],
          ['name' => 'Starlink Business 500 GB', 'price' => 285000, 'period_months' => 1]];
$HW    = [['name' => 'Starlink Mini Kit + Mini Router', 'price' => 2249000], ['name' => 'Starlink Standard Kit', 'price' => 2649000],
          ['name' => 'Professional Installation', 'price' => 150000], ['name' => 'MikroTik L009 Series', 'price' => 700000]];
$ACC   = [['name' => 'Router Mini', 'price' => 301000], ['name' => 'Travel Kit | Mini', 'price' => 301000],
          ['name' => 'Starlink Kit Carry Case', 'price' => 90000]];   // named like a kit, sold as an accessory
$CAT   = ['products' => $PLANS, 'hardware' => $HW, 'accessories' => $ACC];
$UG = ['ai_provider' => 'openai', 'openai_api_key' => 'x', 'ai_currency' => 'UGX', 'ai_qualification' => '1', 'ai_hardware_expert' => '1',
       'ai_sales_on_all_numbers' => '1', 'ai_lead_capture' => '1'];
$SS = ['ai_provider' => 'openai', 'openai_api_key' => 'x'];

// The assistant's words on 27 Sep, 12:31 (EAT) — the reply the operator sent. The customer's words are not kept.
$LIVE = "Thank you for your interest! The Starlink Standard Kit is what you're looking for. It's designed for higher-demand "
      . "everyday use. The price for the Starlink Standard Kit is 2,649,000 UGX as a one-time cost, plus a professional "
      . "installation fee of 150,000 UGX.\n\nFor your monthly plan, I recommend the Starlink Residential plan, which offers "
      . "speeds of up to 400 Mbps and is priced at 329,000 UGX per month with unlimited data.\n\nWould you like to proceed "
      . "with this option? 😊";

// ════════════════════════════════════════════════════════════════════════════
echo "1. The note: the approved wording, where it applies, what a Starlink kit is\n";
is_(KitTaxNote::DEFAULT_NOTE === APPROVED, 'the built-in wording is the one the operator approved, word for word');
is_(KitTaxNote::note($UG) === APPROVED, 'Uganda (the hardware module on): the approved wording');
foreach ([['South Sudan', $SS], ['the module off', ['ai_hardware_expert' => '0'] + $UG], ['no configuration', []],
          ['the module off, a wording set', ['ai_hardware_expert' => '0', 'ai_fact_kit_taxes' => 'Anything.'] + $UG]] as [$l, $c]) {
    is_(KitTaxNote::note($c) === '', "{$l}: no note");
}
is_(KitTaxNote::note(['ai_fact_kit_taxes' => 'omit'] + $UG) === '' && KitTaxNote::note(['ai_fact_kit_taxes' => ' OMIT '] + $UG) === '',
    '"omit" switches it off');
is_(KitTaxNote::note(['ai_fact_kit_taxes' => 'Our own words.'] + $UG) === 'Our own words.', "the operator's own wording, as typed");
foreach (['Starlink Standard Kit' => true, 'Starlink Mini Kit + Mini Router' => true, 'STARLINK STANDARD KIT' => true,
          'starlink mini kit' => true, 'Starlink Kits' => true, 'Travel Kit | Mini' => false, 'Router Mini' => false,
          'Professional Installation' => false, 'Starlink Residential (up to 400 Mbps)' => false, 'Kitchen Wi-Fi' => false] as $n => $want) {
    is_(KitTaxNote::isKit($n) === $want, ($want ? 'a Starlink kit: ' : 'not a Starlink kit: ') . $n);
}
$kp = KitTaxNote::kitPrices($CAT); sort($kp);
is_($kp === [2249000.0, 2649000.0], 'the kit prices are the two kits in HARDWARE', json_encode($kp));
is_(KitTaxNote::kitPrices(['accessories' => $ACC]) === [], 'an accessory is never read, even one named like a kit');
is_(KitTaxNote::kitPrices(['hardware' => [['name' => 'Starlink Standard Kit', 'price' => 'n/a'], ['name' => 'Starlink Mini Kit']]]) === [],
    'a kit with no price gives none');

// ════════════════════════════════════════════════════════════════════════════
echo "\n2. When it is added to a reply, and when it is not\n";
$kp = KitTaxNote::kitPrices($CAT);
$add = fn(string $r, array $c = []) => KitTaxNote::apply($r, ($c ?: $UG), $kp);
$a = $add($LIVE);
is_($a['appended'] && $a['reply'] === $LIVE . "\n\n" . APPROVED, 'the live reply of 27 Sep: the approved sentence, after a blank line, at the end',
    json_encode($a['reply']));
is_($a['reason'] === 'a Starlink kit price was quoted', '…and says why');
foreach (['written without commas'          => "The Standard Kit is 2649000 UGX, installed for 150000.",
          'written with spaces'             => "The Standard Kit is UGX 2 649 000.",
          'the Mini kit'                    => "The Mini Kit comes to 2,249,000 UGX.",
          'a kit line in a list and a total' => "- Starlink Standard Kit — UGX 2,649,000\n- Professional Installation — UGX 150,000\nTOTAL TO GET CONNECTED: UGX 2,799,000"]
         as $l => $r) {
    is_($add($r)['appended'], "added: {$l}");
}
foreach (['a plan price and the word kit'   => ['If you already have a kit, Residential is 329,000 UGX a month.', 'no kit price in the reply'],
          'the installation alone'          => ['Installation is 150,000 UGX.', 'no kit price in the reply'],
          'the Travel Kit case'             => ['The Travel Kit | Mini is 301,000 UGX.', 'no kit price in the reply'],
          'an accessory named like a kit'   => ['The Starlink Kit Carry Case is 90,000 UGX.', 'no kit price in the reply'],
          'the reply says it in its own words' => ['The kit is 2,649,000 UGX, all taxes and the UCC fee included.', 'the reply already says it in its own words'],
          'the note is already there'       => ["The kit is 2,649,000 UGX.\n\n" . APPROVED, 'the note is already present'],
          'an empty reply'                  => ['', 'empty reply']] as $l => [$r, $why]) {
    $x = $add($r);
    is_(!$x['appended'] && $x['reply'] === $r && $x['reason'] === $why, "not added: {$l} ({$why})", json_encode($x));
}
$x = KitTaxNote::apply($LIVE, $SS, $kp);
is_(!$x['appended'] && $x['reply'] === $LIVE && $x['reason'] === 'the hardware module is off', 'not added: South Sudan (the hardware module is off)');
$x = KitTaxNote::apply($LIVE, ['ai_fact_kit_taxes' => 'omit'] + $UG, $kp);
is_(!$x['appended'] && $x['reason'] === 'ai_fact_kit_taxes is omit', 'not added: switched off with "omit"');
$x = KitTaxNote::apply($LIVE, $UG, []);
is_(!$x['appended'] && $x['reason'] === 'no Starlink kit in the catalogue', 'not added: no Starlink kit in the catalogue');
$x = KitTaxNote::apply($LIVE, ['ai_fact_kit_taxes' => 'Kit prices include every tax.'] + $UG, $kp);
is_($x['appended'] && $x['reply'] === $LIVE . "\n\nKit prices include every tax.", "the operator's own wording is added as typed");

// ════════════════════════════════════════════════════════════════════════════
echo "\n3. The real worker: after the price check, never to a refused reply\n";
function ctxOf(array $cfg, array $cat, string $msg): array {
    return BrainContext::build('unknown', ['customer' => null, 'channel' => 'sales', 'transport' => 'whatsapp', 'medium' => '',
        'products' => AiReplyWorker::salesCatalogue($cat, $cfg), 'message' => $msg, 'history' => []]) + ['conversation_id' => 7];
}
$worker = (new ReflectionClass(AiReplyWorker::class))->newInstanceWithoutConstructor();
$events = new class { public $rows = []; public function append(string $f, array $r): array { $this->rows[] = [$f, $r]; return $r; } };
$setCfg = function (array $cfg) use ($worker, $events): void {
    foreach (['config' => $cfg, 'store' => $events] as $prop => $val) {
        $p = new ReflectionProperty(WorkerBase::class, $prop); $p->setAccessible(true); $p->setValue($worker, $val);
    }
};
$guard = new ReflectionMethod(AiReplyWorker::class, 'guardReply'); $guard->setAccessible(true);
$run = function (string $reply, array $cfg, string $msg = 'I want starlink the big one') use ($worker, $guard, $setCfg, $CAT): array {
    $setCfg($cfg);
    $ctx = ctxOf($cfg, $CAT, $msg);
    ob_start();
    $out = $guard->invoke($worker, ['reply' => $reply, 'escalate' => false], $ctx, (new DishNetAiBrain($cfg))->promptPreview($ctx));
    return [$out, (string)ob_get_clean()];
};
[$out, $log] = $run($LIVE, $UG);
is_(($out['reply'] ?? '') === $LIVE . "\n\n" . APPROVED, 'Uganda: the live reply passes the price check and carries the sentence', json_encode($out['reply'] ?? ''));
is_(strpos($log, 'conv 7: kit tax note appended — a Starlink kit price was quoted') !== false, '…and the worker log says so', trim($log));
[$out, $log] = $run($LIVE, $SS);
is_(($out['reply'] ?? '') === $LIVE && strpos($log, 'kit tax note') === false, 'South Sudan: the same reply is sent exactly as written');
[$out] = $run("- Starlink Standard Kit — UGX 2,649,000\n- Professional Installation — UGX 150,000\n\nTOTAL: UGX 2,899,000", $UG);
is_(($out['reply'] ?? '') === ReplyPrivacyGuard::SAFE_FALLBACK && !empty($out['escalate']),
    'a refused reply (its total does not add up) becomes the fallback…', json_encode($out));
is_(strpos((string)($out['reply'] ?? ''), 'taxes') === false, '…with no taxes line on it');
[$out] = $run("Business 500 GB is 285,000 UGX a month, and the Standard Kit is 2,649,000 UGX.", $UG, 'How much is Business 500 with the kit?');
$r = (string)($out['reply'] ?? '');
is_(strpos($r, PlanFenceGuard::DEFAULT_NOTE) !== false && substr($r, -strlen(APPROVED)) === APPROVED
    && strpos($r, PlanFenceGuard::DEFAULT_NOTE) < strpos($r, APPROVED),
    'with a Business plan too: the Business-plan note first, then the taxes line', json_encode($r));

// ════════════════════════════════════════════════════════════════════════════
echo "\n4. Listed as the operator's own text where it can be added\n";
is_(in_array(APPROVED, DishNetAiBrain::operatorText($UG), true), 'Uganda: the sentence is the operator\'s text, not a quote of the prompt');
is_(!in_array(APPROVED, DishNetAiBrain::operatorText($SS), true), 'South Sudan: not listed');
is_(DishNetAiBrain::operatorText($SS) === DishNetAiBrain::operatorText(['ai_hardware_expert' => '0'] + $SS),
    'South Sudan: the list is exactly what it was without the module');
is_(!in_array(APPROVED, DishNetAiBrain::operatorText(['ai_fact_kit_taxes' => 'omit'] + $UG), true), '"omit": not listed');
is_(in_array('Our own words.', DishNetAiBrain::operatorText(['ai_fact_kit_taxes' => 'Our own words.'] + $UG), true), 'the own wording is listed');

// ════════════════════════════════════════════════════════════════════════════
echo "\n5. The setting\n";
$sc = (string)file_get_contents("{$root}/tools/set_config.php");
is_(strpos($sc, "'ai_fact_kit_taxes' => ['text',") !== false, 'tools/set_config.php manages ai_fact_kit_taxes');
is_(strpos($sc, "in_array(\$key, ['ai_fact_prices', 'ai_fact_kit_taxes'], true) && \$new !== '' && preg_match('/\\d/', \$new)") !== false,
    '…and warns when a figure is typed into it, as for the tax fact');

// ════════════════════════════════════════════════════════════════════════════
echo "\n6. The quotation template: clause 2 says the same, in one line staff can paste\n";
$tpl = (string)file_get_contents("{$root}/ucrm_pdf_templates/quotation_uganda/template.html.twig");
$lines = array_values(array_filter(explode("\n", $tpl), fn($l) => strpos($l, '2. Currency &amp; Pricing:') !== false));
$line = $lines[0] ?? '';
is_(count($lines) === 1, 'clause 2 is one line');
$sentence = 'The kit price includes all taxes &mdash; URA taxes and the UCC registration fee are already in it. Nothing is added on top.';
is_(substr_count($tpl, $sentence) === 1 && strpos($line, $sentence) !== false, 'the sentence is in clause 2, once');
is_(html_entity_decode($sentence, ENT_QUOTES | ENT_HTML5, 'UTF-8') === KitTaxNote::DEFAULT_NOTE, '…in the same words as the WhatsApp line');
$order = ['{% set has_kit = false %}', '{% for item in items %}', "('Starlink' in item.label", "('Kit' in item.label",
          '{% set has_kit = true %}', '{% endfor %}', '{% if totals.taxes|length > 0 %}VAT is itemised in the totals on page 1.',
          '{% elseif has_kit %}' . $sentence, '{% else %}No VAT is charged on this quotation.{% endif %}'];
$at = -1; $inOrder = true;
foreach ($order as $piece) { $p = strpos($line, $piece); if ($p === false || $p < $at) { $inOrder = false; echo "       out of place: {$piece}\n"; } else $at = $p; }
is_($inOrder, 'self-contained, in order: the kit test, then tax lines itemised, then the kit sentence, then "No VAT is charged"');
is_(strpos($line, "('Kit' in item.label or 'kit' in item.label or 'KIT' in item.label)") !== false
    && strpos($line, "('Starlink' in item.label or 'starlink' in item.label or 'STARLINK' in item.label) and") !== false,
    'a kit on a quote needs both "Starlink" and "Kit" in one item, as on WhatsApp');
is_(preg_match('/\|(?!length)[a-z_]+/', $line) === 0, 'no filter but length, which the template already uses');
is_(is_file($repo . '/scripts/harness/quotation-template/render.php') || !is_dir($repo . '/scripts'),
    'the rendering proof, real Twig 2 and 3, is scripts/harness/quotation-template');

// ════════════════════════════════════════════════════════════════════════════
echo "\n7. The AI check tool reports it and counts it\n";
$chk = (string)@file_get_contents($repo . '/scripts/lib/ai_check.php');
$wrap = (string)@file_get_contents($repo . '/scripts/dnb-ai-check.sh');
if ($chk !== '' && $wrap !== '') {
    is_(strpos($chk, "'PlanFenceGuard', 'KitTaxNote',") !== false, 'the tool loads the class where the plugin has it');
    is_(strpos($chk, "out('kit tax note',") !== false && strpos($chk, "'the approved wording: '") !== false,
        '…says which wording is in force');
    is_(strpos($chk, "\$k = KitTaxNote::apply(\$final, \$config, KitTaxNote::kitPrices((array)(\$ctx['products'] ?? [])));") !== false
        && strpos($chk, "\$kitted++; \$how[] = 'the kit tax note was added';") !== false,
        '…adds it where the worker does, after the fence, and counts it');
    is_(strpos($chk, 'echo "@@ ask ok {$calls} {$blocked} {$fenced} {$kitted}\n";') !== false
        && strpos($wrap, 'read -r _ P STATE A B C D <<<"$LAST"') !== false && strpos($wrap, '%s with the kit tax note added') !== false,
        'the count reaches the wrapper as its own field, not folded into the Business-plan count');
} else {
    echo "  --   the scripts are not beside this copy of the plugin (a sandbox): skipped\n";
}

// ════════════════════════════════════════════════════════════════════════════
if ($withMutants) {
    echo "\n8. Weakened copies of the code must each fail this test\n";
    $sandbox = function () use ($root): string {
        $tmp = sys_get_temp_dir() . '/dn-t48-m-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($tmp, 0700, true);
        exec('cp -r ' . escapeshellarg("{$root}/lib") . ' ' . escapeshellarg("{$root}/workers") . ' ' . escapeshellarg("{$root}/manifest.json")
            . ' ' . escapeshellarg("{$root}/ucrm_pdf_templates") . ' ' . escapeshellarg($tmp));
        foreach (['migrations', 'tools', 'assets', 'profiles', 'tests'] as $d) {
            if (is_dir("{$root}/{$d}")) symlink("{$root}/{$d}", "{$tmp}/{$d}");
        }
        return $tmp;
    };
    $MUTANTS = [
        ['lib/KitTaxNote.php', "        if (!filter_var(\$config['ai_hardware_expert'] ?? false, FILTER_VALIDATE_BOOLEAN)) return '';\n", '',
         'the wording given whatever the module'],
        ['lib/KitTaxNote.php', "return \$keep('the hardware module is off');", "return \$keep('ignored');",
         'apply() not stopped by the module off'],
        ['lib/KitTaxNote.php', "return preg_match('/\\bstarlink\\b/i', \$name) === 1 && preg_match", "return preg_match",
         'any "Kit" taken for a Starlink kit'],
        ['lib/KitTaxNote.php', "foreach ((array)(\$products['hardware'] ?? []) as \$h) {",
         "foreach (array_merge((array)(\$products['hardware'] ?? []), (array)(\$products['accessories'] ?? [])) as \$h) {",
         'the accessories read for kit prices'],
        ['lib/KitTaxNote.php', 'ReplyPrivacyGuard::amountSpans($reply, true)', 'ReplyPrivacyGuard::amountSpans($reply, false)',
         'a price written without commas not read'],
        ['lib/KitTaxNote.php', "if (preg_match('/\\bUCC\\b/i', \$reply) === 1 && preg_match('/\\btax/i', \$reply) === 1) {", 'if (false) {',
         'said twice when the model already said it'],
        ['lib/KitTaxNote.php', "        if (stripos(\$reply, \$note) !== false)          return \$keep('the note is already present');\n", '',
         'no check for the note already there'],
        ['lib/KitTaxNote.php', "'reply' => rtrim(\$reply) . \"\\n\\n\" . \$note,", "'reply' => \$note . \"\\n\\n\" . rtrim(\$reply),",
         'the note put before the reply'],
        ['workers/AiReplyWorker.php', "if (\$kit['appended']) {", 'if (false) {', 'the worker never adds it'],
        ['lib/DishNetAiBrain.php', "        if (\$kit !== '') \$out[] = \$kit;\n", '', 'not listed as the operator\'s text'],
        ['ucrm_pdf_templates/quotation_uganda/template.html.twig', '{% elseif has_kit %}', '{% elseif false %}{# #}',
         'the quotation never says it'],
    ];
    foreach ($MUTANTS as [$rel, $old, $new, $label]) {
        $tmp  = $sandbox();
        $file = "{$tmp}/{$rel}";
        $s    = (string)file_get_contents($file);
        $n    = substr_count($s, $old);
        if ($n !== 1) { is_(false, "weakened copy \"{$label}\": its anchor occurs once in {$rel}", "found {$n} times"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        file_put_contents($file, str_replace($old, $new, $s));
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tmp) . ' --no-mutants 2>&1', $out, $rc);
        $fails = array_values(array_filter($out, fn($l) => strpos($l, '  FAIL ') === 0));
        is_($rc !== 0 && $fails !== [], "caught: {$label}", 'exit ' . $rc . ', ' . count($fails) . ' failure(s)');
        if ($fails) echo '         first: ' . trim(substr($fails[0], 7, 110)) . "\n";
        exec('rm -rf ' . escapeshellarg($tmp));
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

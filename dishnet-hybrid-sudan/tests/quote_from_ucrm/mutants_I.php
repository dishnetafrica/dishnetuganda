<?php
declare(strict_types=1);
/**
 * Section I's weakened copies (docs/71 §L.1, "Planted faults must fail it"): each plants one boundary fault in a copy of
 * the plugin, and section I, judging the copy against f2055baf from Git, must fail on it. TEST ONLY.
 *
 * 5.18.102 D14 (docs/71 §R.9.3): MI8 re-anchored (retry_quote's gated path is the shared implementation now, §R.4.8).
 * Added: MI10 (that gated block falling through to 5.18.101's POST), ML26 (a construction site without a link judge),
 * ML62's I half (lib/UcrmQuoteLinks.php requiring a sibling file), ML68 (the kit loop's own type test) and ML69's I half
 * (lib/UcrmQuoteLines.php without its require_once of lib/UcrmQuoteLinks.php).
 */
$QFU_MUTANTS = array_merge($QFU_MUTANTS, [
    ['label' => 'MI1 webhook.php changed (§L.4 keeps it byte for byte)', 'file' => 'webhook.php',
     'old' => "<?php\ndeclare(strict_types=1);\n", 'new' => "<?php\ndeclare(strict_types=1);\n// a stray line\n",
     'sections' => 'I', 'pattern' => '/I2 the 10 files/'],
    ['label' => 'MI2 a migration changed', 'file' => 'migrations/001_event_queue.sql',
     'old' => '-- Migration 001: Event Queue', 'new' => '-- Migration 001: Event Queue (edited)',
     'sections' => 'I', 'pattern' => '/I2 migrations/'],
    ['label' => 'MI3 the manifest changed beyond its version line', 'file' => 'manifest.json',
     'old' => '"description": "The full DishNet Hybrid platform', 'new' => '"description": "The DishNet Hybrid platform',
     'sections' => 'I', 'pattern' => '/I2 manifest/'],
    ['label' => 'MI4 an undeclared runtime file changed', 'file' => 'lib/currency.php',
     'old' => "    function dn_cur(?array \$config = null): string", 'new' => "    // edited\n    function dn_cur(?array \$config = null): string",
     'sections' => 'I', 'pattern' => '/I1 against/'],
    ['label' => 'MI5 a runtime hunk with no 5.18.102 marker', 'file' => 'lib/QuotationService.php',
     'old' => "    private function itemsTotal(array \$items): float\n    {\n        return round(",
     'new' => "    private function itemsTotal(array \$items): float\n    {\n        \$items = array_values(\$items);\n        return round(",
     'sections' => 'I', 'pattern' => '/I3/'],
    ['label' => 'MI6 PHP 8 syntax on an added line', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => "    public static function total(array \$lines): float\n    {\n        \$t = 0.0;",
     'new' => "    public static function total(array \$lines): float\n    {\n        \$t = str_contains('', '') ? 0.0 : 0.0;",
     'sections' => 'I', 'pattern' => '/I4 no added/'],
    ['label' => 'MI7 a new quote-create site', 'file' => 'lib/QuotationService.php',
     'old' => "    private function itemsTotal(array \$items): float\n    {\n",
     'new' => "    private function itemsTotal(array \$items): float\n    {\n        if (\$items === [0]) \$this->crm->post(\"clients/{\$items}/quotes\", []);   // 5.18.102\n",
     'sections' => 'I', 'pattern' => '/I5 the quote creates/'],
    // re-anchored by D14: on Uganda retry_quote quotes through the shared implementation, in the block guarded by the gate
    ['label' => 'MI8 retry_quote posting without the resolver\'s verdict (its gated block skipped, 5.18.101\'s POST reached on Uganda)', 'file' => 'includes/api/api_cron_debug.php',
     'old' => "        if (\$_qoRetry['on']) {\n            // The account re-read fresh by id",
     'new' => "        if (false) {\n            // The account re-read fresh by id",
     'sections' => 'I', 'pattern' => '/I5 retry_quote/'],
    ['label' => 'MI10 retry_quote\'s gated block falls through to 5.18.101\'s POST (its last answer removed)', 'file' => 'includes/api/api_cron_debug.php',
     'old' => "            \$er2((string)\$_mq['text'], 500);   // 'error'\n", 'new' => '',
     'sections' => 'I', 'pattern' => '/I5 retry_quote/'],
    ['label' => 'MI9 Send Quotation\'s manual sender without the unpriced-line refusal', 'file' => 'lib/QuotationService.php',
     'old' => "        if (empty(\$items)) return ['ok' => false, 'error' => 'At least one line item required.'];\n"
            . "        // 5.18.102 (Uganda, docs/71 §J): no line is ever sent at price 0 — the first one is named, and nothing is written\n"
            . "        // or sent. Lines staff type are otherwise sent as typed (no productId).\n"
            . "        if (\$this->quoteOrg()['on'] && (\$unpriced = \$this->unpricedLine(\$items)) !== '') {\n"
            . "            return ['ok' => false, 'error' => \$unpriced];\n        }\n",
     'new' => "        if (empty(\$items)) return ['ok' => false, 'error' => 'At least one line item required.'];\n        // 5.18.102\n",
     'sections' => 'I', 'pattern' => '/I5 … Send Quotation/'],

    // ── 5.18.102 D14 (docs/71 §R.9.3) ───────────────────────────────────────────────────────────────────────────────
    ['label' => 'ML26 a construction site without links (the judge made optional; the cron retry builds a resolver with none)', 'edits' => [
        ['lib/UcrmQuoteLines.php', "    public function __construct(\$crm, array \$setting, UcrmQuoteLinks \$links, ?array \$catalogue = null)\n    {\n        \$this->crm     = \$crm;\n        \$this->setting = \$setting;\n        \$this->links   = \$links;",
                                   "    public function __construct(\$crm, array \$setting, ?UcrmQuoteLinks \$links = null, ?array \$catalogue = null)\n    {\n        \$this->crm     = \$crm;\n        \$this->setting = \$setting;\n        \$this->links   = \$links ?? UcrmQuoteLinks::fromRecords([], []);"],
        ['lib/KycCrmSync.php', "new UcrmQuoteLines(\$quoteCrm, \$setting, UcrmQuoteLinks::fromStore(\$this->store, \$setting))",
                               "new UcrmQuoteLines(\$quoteCrm, \$setting)"],
     ], 'sections' => 'I', 'pattern' => '/I6 /'],
    ['label' => 'ML62 (I) lib/UcrmQuoteLinks.php require()s a sibling file', 'file' => 'lib/UcrmQuoteLinks.php',
     'old' => "<?php\ndeclare(strict_types=1);\n\n/**\n * UcrmQuoteLinks",
     'new' => "<?php\ndeclare(strict_types=1);\nrequire_once __DIR__ . '/QuoteOrganization.php';   // 5.18.102\n\n/**\n * UcrmQuoteLinks",
     'sections' => 'I', 'pattern' => '/I7 lib\/UcrmQuoteLinks\.php holds no require/'],
    ['label' => 'ML68 the kit loop keeps a type test of its own instead of calling UcrmQuoteLinks::offeredKit', 'file' => 'tabs/sales/kyc_form.php',
     'old' => "UcrmQuoteLinks::offeredKit(\$_q102D)\n                    ? \$_q102Cell(",
     'new' => "strtolower((string)(\$_q102D['type'] ?? 'general')) !== 'fiber'\n                    ? \$_q102Cell(",
     'sections' => 'I', 'pattern' => '/I8 the kit loop/'],
    ['label' => 'ML69 (I) lib/UcrmQuoteLines.php without its require_once of lib/UcrmQuoteLinks.php', 'file' => 'lib/UcrmQuoteLines.php',
     'old' => "require_once __DIR__ . '/UcrmQuoteLinks.php';   // 5.18.102 (D14, docs/71 §R.1.5, F-6): the link judge every resolver holds\n",
     'new' => '',
     'sections' => 'I', 'pattern' => '/I7 lib\/UcrmQuoteLines\.php holds require_once/'],
    // 5.18.102 D14 review (G3): SAFETY.md's operator step 3 put back to the spelling rule
    ['label' => 'MI11 SAFETY.md\'s operator step 3 put back to the spelling rule', 'file' => 'SAFETY.md',
     'old' => '3. Prepare uCRM and decide the picks: for each plan sold, one Product and one Service Plan spelled alike, with equal',
     'new' => '3. Check the catalogue: each plan offered on the form spelled alike in its plugin name, exactly one uCRM Product and',
     'sections' => 'I', 'pattern' => '/I6b SAFETY.md/'],
    // 5.18.102 D14 review round 2 (FX-tests-2): SAFETY.md's test record put back to the first cut's — the second 'gone' needle
    ['label' => "MI11b SAFETY.md's test record put back to nine sections", 'file' => 'SAFETY.md',
     'old' => 'in ten sections A–I and L, without the', 'new' => 'in nine sections A–I, without the',
     'sections' => 'I', 'pattern' => '/I6b SAFETY.md/'],
]);

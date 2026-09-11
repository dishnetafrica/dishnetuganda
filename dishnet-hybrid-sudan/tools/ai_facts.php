<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * ai_facts.php — the answers that are true in one country and false in the next.
 *
 *   php tools/ai_facts.php                       show what customers are told
 *   php tools/ai_facts.php --set office "..."    replace one
 *   php tools/ai_facts.php --omit delivery       say nothing rather than say Sudan's
 *   php tools/ai_facts.php --reset office        back to the Sudan default
 *   php tools/ai_facts.php --uganda              delivery and payment, Uganda
 *
 * The assistant's business facts were written for the South Sudan operation:
 * a walk-in office in Juba, kits flown to Renk and crossing at the Joda
 * border, payment at a Sudanese URL. On the Uganda box those went to Ugandan
 * customers unchanged, on WhatsApp, live. Nothing was broken — the answers
 * were simply another country's.
 *
 * Where the real answer is not known yet, --omit is better than either: the
 * assistant then says it will check, instead of confidently naming a border
 * crossing eight hundred miles away.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/DishNetAiBrain.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);

$args  = array_slice($argv, 1);
$value = function (string $f, int $n = 1) use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + $n])) ? (string)$args[$i + $n] : '';
};
$has = function (string $f) use ($args) { return in_array($f, $args, true); };

$keys = ['office' => 'ai_fact_office', 'delivery' => 'ai_fact_delivery',
         'payment' => 'ai_fact_payment'];

/** Sudan's own words, as the brain still uses them when nothing is set. */
function sudanText(string $key): string
{
    $brain = new DishNetAiBrain([]);
    $r = new ReflectionMethod(DishNetAiBrain::class, 'localFacts');
    $r->setAccessible(true);
    foreach (explode("\n", (string)$r->invoke($brain)) as $line) {
        if (preg_match('/^- ([A-Z ]+): (.*)$/', $line, $m)) {
            $label = strtolower(explode(' ', $m[1])[0]);
            if ($label === $key) return $m[2];
        }
    }
    return '';
}

if ($args === [] || $has('--show')) {
    echo "\n  WHAT THE ASSISTANT TELLS CUSTOMERS\n";
    echo "  These reach WhatsApp, the web chat and email drafts alike.\n\n";
    foreach ($keys as $short => $key) {
        $set = trim((string)($config[$key] ?? ''));
        $state = $set === '' ? 'SUDAN DEFAULT' : (strtolower($set) === 'omit' ? 'omitted' : 'set here');
        printf("  %-9s %s\n", strtoupper($short), $state);
        $text = $set === '' ? sudanText($short) : $set;
        if (strtolower($set) !== 'omit') {
            foreach (str_split(wordwrap($text, 66, "\n", true) ?: '', 10000) as $chunk) {
                foreach (explode("\n", $chunk) as $l) echo '            ' . $l . "\n";
            }
        } else {
            echo "            (the assistant says nothing, and offers to check)\n";
        }
        echo "\n";
    }
    // The knowledge base says these things too, and nothing showed both.
    //
    // An office address lives in TWO places — the ai_fact_office config key
    // above, and the OFFICE_LOCATION knowledge row — and they can disagree
    // without anyone noticing. One of them told customers the office was on
    // Mawanda Road for as long as it existed; the seeder reported "0
    // corrected" afterwards and there was no way to tell from that whether
    // the fix had landed or the row was simply already right.
    try {
        require_once $root . '/lib/StoreInterface.php';
        require_once $root . '/lib/JsonStore.php';
        require_once $root . '/lib/SqliteStore.php';
        require_once $root . '/lib/KnowledgeBase.php';
        $kb = KnowledgeBase::load(SqliteStore::create($dataDir)->getPdo());
        $rows = array_merge($kb['fact'], $kb['rule'], $kb['tbc']);
        if ($rows) {
            echo "  APPROVED KNOWLEDGE — " . count($rows) . " row(s), edited in admin
";
            echo "  Shown from the database, which is what the assistant actually reads.

";
            foreach ([['fact', 'answered from here'],
                      ['rule', 'conduct'],
                      ['tbc',  'never improvised — holding line and hand over']] as [$kind, $what]) {
                if (!$kb[$kind]) continue;
                printf("  %s (%d) — %s
", strtoupper($kind), count($kb[$kind]), $what);
                foreach ($kb[$kind] as $r) {
                    printf("    %-30s %s
", (string)$r['item_key'],
                           mb_substr(trim(preg_replace('/\s+/', ' ', (string)($r['answer'] ?: $r['title']))) ?? '', 0, 74));
                }
                echo "
";
            }
            // The specific disagreement that has already happened once.
            $office = '';
            foreach ($kb['fact'] as $r) {
                if ((string)$r['item_key'] === 'OFFICE_LOCATION') { $office = (string)$r['answer']; break; }
            }
            $cfgOffice = trim((string)($config['ai_fact_office'] ?? ''));
            if ($office !== '' && $cfgOffice !== '' && strtolower($cfgOffice) !== 'omit') {
                echo "  ⚠ The office address is set in BOTH places. They are in the same
";
                echo "    prompt, and the assistant will use whichever it reads first.

";
            }
            if ($office !== '') {
                echo "  OFFICE, as the knowledge base states it:
";
                foreach (explode("
", wordwrap($office, 66, "
", true) ?: '') as $l) {
                    echo '      ' . $l . "
";
                }
                echo "
";
            }
        } else {
            echo "  APPROVED KNOWLEDGE — none loaded.
";
            echo "  Run tools/seed_knowledge.php, or the assistant answers from the
";
            echo "  built-in prompt alone.

";
        }
    } catch (\Throwable $e) {
        echo "  (could not read the knowledge base: " . $e->getMessage() . ")

";
    }

    $stale = array_filter($keys, function ($k) use ($config) {
        return trim((string)($config[$k] ?? '')) === '';
    });
    if ($stale !== []) {
        echo "  " . count($stale) . " of 3 are still South Sudan's answers.\n";
        echo "  On a Uganda install a customer asking where the office is, how\n";
        echo "  delivery works, or how to pay is being told about Juba, the Joda\n";
        echo "  border, and a Sudanese payment page.\n\n";
        echo "    php tools/ai_facts.php --set payment \"...\"\n";
        echo "    php tools/ai_facts.php --omit delivery      until the real answer is known\n\n";
    }
    exit($stale === [] ? 0 : 1);
}

$updates = [];

// The Uganda answers that can be written from what the system already knows.
// The office is not among them: nobody can derive an address, and a wrong one
// sends a customer across Kampala for nothing. It is set on its own.
if ($has('--uganda')) {
    $bank = [
        'beneficiary' => trim((string)($config['email_bank_beneficiary'] ?? '')),
        'name'        => trim((string)($config['email_bank_name'] ?? '')),
        'ugx'         => trim((string)($config['email_bank_account_ugx'] ?? '')),
        'usd'         => trim((string)($config['email_bank_account_usd'] ?? '')),
        'swift'       => trim((string)($config['email_bank_swift'] ?? '')),
    ];
    $missing = array_keys(array_filter($bank, function ($v) { return $v === ''; }));

    // Account numbers are never typed in here. They are already configured for
    // the quotation and invoice templates, and one source telling customers two
    // different account numbers is worse than none.
    if (in_array('beneficiary', $missing, true) || in_array('ugx', $missing, true)) {
        echo "\n  The bank details are not configured, so the payment answer cannot be\n";
        echo "  written from them — and they are not going to be typed in here.\n\n";
        echo "    php tools/set_email_brand.php --uganda\n\n";
        exit(1);
    }

    $pay = 'payment is by bank transfer to ' . $bank['beneficiary']
         . ($bank['name'] !== '' ? ' at ' . $bank['name'] : '') . '. '
         . 'UGX account ' . $bank['ugx']
         . ($bank['usd'] !== '' ? ', USD account ' . $bank['usd'] : '')
         . ($bank['swift'] !== '' ? ', SWIFT ' . $bank['swift'] : '') . '. '
         . 'Ask them to use their quotation or invoice number as the payment reference. '
         . 'These are the only payment details we have — do not offer any other method, '
         . 'and do not name an amount unless it is in the DATA above.';

    $del = 'our team delivers the kit and installs it at the customer\'s premises. '
         . 'Do NOT promise a number of days, a specific date, or a transport cost — '
         . 'those vary by location, so offer to have a colleague confirm them.';

    $updates['ai_fact_payment']  = $pay;
    $updates['ai_fact_delivery'] = $del;

    echo "\n  Setting delivery and payment for Uganda.\n";
    echo "  The OFFICE answer is not set here — give the address with:\n";
    echo "    php tools/ai_facts.php --set office \"...\"\n";
}

foreach (['--set' => 1, '--omit' => 0, '--reset' => 0] as $flag => $takesText) {
    if (!$has($flag)) continue;
    $which = strtolower($value($flag));
    if (!isset($keys[$which])) {
        echo "\n  Name one of: " . implode(', ', array_keys($keys)) . "\n\n";
        exit(1);
    }
    if ($flag === '--set') {
        $text = $value($flag, 2);
        if (trim($text) === '') { echo "\n  Give the text to set.\n\n"; exit(1); }
        $updates[$keys[$which]] = trim($text);
    } elseif ($flag === '--omit') {
        $updates[$keys[$which]] = 'omit';
    } else {
        $updates[$keys[$which]] = '';   // empty clears the override
    }
}

if ($updates === []) { echo "\n  Nothing to change.\n\n"; exit(1); }

[$ok, $err] = PluginConfig::saveOverrides($dataDir, $updates) + [null, null];
if ($ok === false) { echo "\n  Could not save: {$err}\n\n"; exit(1); }

echo "\n  Saved. Run it with no arguments to see what customers are told now.\n\n";
exit(0);

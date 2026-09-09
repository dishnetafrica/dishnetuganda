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

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
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

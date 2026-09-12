<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * set_config.php — turn a plugin setting on or off from the terminal.
 *
 *   php tools/set_config.php                          what the AI flags are set to
 *   php tools/set_config.php --key <name> --value 1   set one
 *   php tools/set_config.php --key <name> --clear     back to the default
 *
 * Every feature added to the AI is gated on a config key whose absence means
 * the old behaviour, so South Sudan is never moved by a Uganda change. That is
 * the right design and it has one consequence: shipping a feature is not
 * enabling it, and there was no way to enable one without a browser.
 *
 * Secrets are refused. PluginConfig::saveOverrides rejects them at the write,
 * and they belong on the uCRM Configuration screen where they are stored
 * encrypted — never in a shell command, which lands in root's history.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';

$args  = array_slice($argv, 1);
$value = function (string $f) use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};

$dataDir = cliDataDir($root);

// The flags that change how the assistant behaves. Listed so `set_config.php`
// with no arguments answers "what is switched on?", which is the question
// actually being asked at a terminal.
// type matters. The first version of this tool ran every value through
// filter_var(FILTER_VALIDATE_BOOLEAN), which counts only 1/true/on/yes as
// true — so a 30-minute cooldown displayed as "OFF", indistinguishable from
// the 0 that disables the stand-down rule entirely. A settings screen that
// cannot tell 30 from 0 is worse than no settings screen.
$FLAGS = [
    'ai_qualification' => ['bool',
        'Qualify before recommending; route CCTV/VPN/servers to Business'],
    'ai_lead_capture' => ['bool',
        'Write a CRM lead when the assistant qualifies a real opportunity'],
    'ai_hardware_expert' => ['bool',
        'Know the dishes: coverage vs Wi-Fi, Ethernet per model, solar'],
    'ai_sales_on_all_numbers' => ['bool',
        'Let every number answer a sales question'],
    'ai_handover_message' => ['text',
        'What the customer hears when the AI hands over'],
    'wa_human_cooldown_minutes' => ['minutes',
        'How long the AI stays quiet after a colleague replies (0 = never stands down)'],
    'stock_statement' => ['text',
        'What to say about availability — stated to customers as written'],
    'ai_currency' => ['text',
        'Currency prices are stated in — shown to customers exactly as typed'],

    // Customer follow-up. Draft mode only: nothing reaches a customer without
    // somebody approving it on the Follow-ups screen.
    'followup_enabled' => ['bool',
        'Notice quiet enquiries and draft a follow-up for a person to approve'],
    'followup_not_before' => ['text',
        'Ignore conversations quiet BEFORE this UTC date — set it when switching on'],
    'followup_daily_cap' => ['minutes',
        'Most follow-ups sent on one channel in a day (default 30)'],
    'followup_max_age_hours' => ['minutes',
        'An enquiry older than this is history, not a live lead (default 336 = 14 days)'],

    // On every quotation the team sends. QuotationService compiles South Sudan
    // defaults for all three, so an unset key is not a blank — it is Juba's
    // phone number printed on a Ugandan customer's quote.
    'ai_fact_location_pin' => ['text',
        'Map pin for the office — sent verbatim; unset means the AI must not write one'],
    'quote_company_name' => ['text',
        'Company name on quotations (unset = "DishNet Africa")'],
    'quote_company_phone' => ['text',
        'Phone printed on quotations (unset = +211920000000, South Sudan)'],
    'quote_company_email' => ['text',
        'Reply address on quotations (unset = info@dishnetafrica.com)'],
];

$show = function () use ($root, $dataDir, $FLAGS) {
    $cfg = PluginConfig::load($root, $dataDir);
    echo "\n  AI SETTINGS\n\n";
    foreach ($FLAGS as $k => list($type, $what)) {
        $raw = $cfg[$k] ?? null;
        $set = !($raw === null || $raw === '');
        $note = '';

        if (!$set) {
            $shown = 'not set (default)';
            if ($type === 'minutes') $note = 'default: 1440 (24 hours)';
        } elseif ($type === 'bool') {
            $shown = filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 'ON' : 'OFF';
        } elseif ($type === 'minutes') {
            // Shown as the number it is. 0 is not "off" — it is a decision
            // with a consequence, so it says the consequence.
            $n = is_numeric($raw) ? (int)$raw : null;
            if ($n === null)   { $shown = '"' . (string)$raw . '"'; $note = 'not a number — treated as the 1440 default'; }
            elseif ($n === 0)  { $shown = '0 minutes'; $note = '⚠ the AI NEVER stands down, even while a colleague is typing'; }
            else               { $shown = $n . ' minutes'; }
        } else {
            $shown = '"' . (string)$raw . '"';
        }

        printf("    %-27s %s\n", $k, $shown);
        if ($note !== '') printf("    %-27s %s\n", '', $note);
        printf("    %-27s %s\n\n", '', $what);
    }
};

$key = trim($value('--key'));
if ($key === '') {
    // With no arguments at all, listing IS the job. But arguments that were
    // MEANT to change something and did not must never exit 0: a command
    // like --set foo=bar printed this whole list and returned success,
    // which reads exactly like it worked. It did nothing.
    if ($args) {
        echo "\n  Nothing was changed — this tool did not understand:\n\n";
        echo "      " . implode(' ', $args) . "\n\n";
        echo "  It takes --key and --value (or --clear):\n\n";
        echo "      php tools/set_config.php --key ai_qualification --value 1\n";
        echo "      php tools/set_config.php --key ai_qualification --clear\n\n";
        echo "  Run it with no arguments to see the settings it manages. Anything\n";
        echo "  outside that list — every Evolution, uCRM or mail setting, and\n";
        echo "  every secret — belongs on the uCRM Configuration screen.\n\n";
        exit(1);
    }
    $show();
    echo "    php tools/set_config.php --key ai_qualification --value 1\n";
    echo "    php tools/set_config.php --key ai_qualification --clear\n\n";
    exit(0);
}

if (!array_key_exists($key, $FLAGS)) {
    echo "\n  \"" . $key . "\" is not one of the settings this tool manages.\n\n";
    echo "  It manages:\n";
    foreach (array_keys($FLAGS) as $k) echo "      " . $k . "\n";
    echo "\n  Anything else — and every secret — belongs on the uCRM\n";
    echo "  Configuration screen, where it is stored encrypted.\n\n";
    exit(1);
}

$clear = in_array('--clear', $args, true);
if (!$clear && !in_array('--value', $args, true)) {
    echo "\n  Give --value <v>, or --clear to return it to the default.\n\n";
    exit(1);
}
$new = $clear ? '' : $value('--value');

// Some of these are not flags — they are text a customer reads, verbatim.
// Saying so at the moment of setting is the only time anyone is looking.
$warn = [];
if (!$clear) {
    if ($key === 'ai_currency' && $new !== '' && $new !== mb_strtoupper($new)) {
        $warn[] = 'This is printed next to every price exactly as typed, so prices will '
                . 'read "' . $new . ' 329,000". Your flyer says "' . mb_strtoupper($new) . '".';
    }
    if ($key === 'stock_statement' && $new !== ''
        && in_array(mb_strtolower(trim($new)), ['yes', 'no', 'y', 'n', 'ok', 'true', 'false'], true)) {
        $warn[] = 'The assistant is told to answer stock questions from this line directly '
                . 'and confidently. As "' . $new . '" that is thin — a sentence works better, '
                . 'for example: "Standard and Mini kits are in stock in Kampala."';
    }
    if ($key === 'wa_human_cooldown_minutes' && $new !== '' && is_numeric($new) && (int)$new === 0) {
        $warn[] = '0 means the AI NEVER stands down. It will keep answering while a colleague '
                . 'is typing, which is what produced ninety-seven messages on c109.';
    }
    if ($key === 'quote_company_phone' && $new !== ''
        && strpos(preg_replace('/\D+/', '', $new) ?? '', '211') === 0) {
        $warn[] = 'That is a South Sudan number. It is printed on quotations sent to '
                . 'Ugandan customers as the number to call.';
    }
    if ($key === 'ai_fact_location_pin' && $new !== ''
        && !preg_match('#^https?://#i', $new)) {
        $warn[] = 'That is not a URL. It is sent to customers exactly as typed.';
    }
    if ($key === 'ai_handover_message' && mb_strlen($new) > 160) {
        $warn[] = 'That is long for a holding line on WhatsApp. It is sent on its own, before '
                . 'a person arrives.';
    }
}

list($ok, $err) = PluginConfig::saveOverrides($dataDir, [$key => $new]);
if (!$ok) { echo "\n  Could not save: " . (string)$err . "\n\n"; exit(1); }

foreach ($warn as $w) echo "\n  Note: " . $w . "\n";

echo "\n  " . $key . ($clear ? ' cleared — back to the default.' : ' = ' . $new) . "\n";
$show();
exit(0);

<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * set_alert_number.php — whose phone rings when the AI needs a human.
 *
 *   php tools/set_alert_number.php                 what it is set to now
 *   php tools/set_alert_number.php --to <a handset>  set it
 *   php tools/set_alert_number.php --off           no alerts at all
 *
 * Three ways this has been wrong on one installation in one day:
 *
 *   +211927797217   a South Sudan number inherited from the Sudan config, so
 *                   every Uganda handover was announced in another country.
 *                   Five customers waited while nobody here was told, and the
 *                   alerts arriving on that handset were read back as a
 *                   customer wanting a Starlink quote — 24 messages of the
 *                   assistant talking to its own alert channel.
 *
 *   256705993348    one of the plugin's OWN WhatsApp numbers. An alert sent
 *                   from the sales instance lands on the support instance as
 *                   an incoming customer message, gets answered, and the
 *                   answer lands back on sales. That is a loop between two
 *                   numbers you own.
 *
 *   a placeholder   pasted verbatim out of a help text. target() strips
 *                   everything but digits and +, so it silently became empty
 *                   and alerts stopped without saying so.
 *
 * All three refused here, with the reason.
 *
 * The first of those is the one a blocklist would not have caught. That handset
 * was fine to alert — it was the operator's own. What made it a fault was that
 * it was ALSO conversation c109: a live thread the assistant answers. Alerts
 * landed in it, came back as customer messages, and were answered. So the guard
 * is not "never that number", it is "never a number this system is already
 * talking to", which is the actual mechanism and catches the next one too.
 *
 *   --force  set it anyway, with the reason printed. For the case where the
 *            thread is the operator's own and they have closed it.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/EvolutionApiService.php';
require_once $root . '/lib/AlertService.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$evo     = new EvolutionApiService($config);
$args    = array_slice($argv, 1);
$value   = function (string $f) use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};

/** Numbers the plugin itself answers on — never a valid alert target. */
$ours = [];
foreach ($evo->listInstances() as $i) {
    $d = preg_replace('/\D+/', '', (string)($i['phone'] ?? ''));
    if ($d !== '') $ours[$d] = (string)$i['name'];
}

$show = function () use ($config, $root, $dataDir, $ours) {
    $raw = (string)(PluginConfig::load($root, $dataDir)['alert_whatsapp'] ?? '');
    $eff = preg_replace('/[^0-9+]/', '', $raw);
    $dig = preg_replace('/\D+/', '', $raw);
    echo "\n  HANDOVER ALERTS GO TO\n\n";
    if (trim($raw) === '') {
        echo "    nothing — the AI hands over and no one is told\n";
    } elseif ($eff === '' || strlen($dig) < 9) {
        echo "    \"" . $raw . "\"  →  unusable, so no alert is sent\n";
    } elseif (isset($ours[$dig])) {
        echo "    " . $eff . "  →  this is our own " . $ours[$dig] . " instance (a loop)\n";
    } else {
        echo "    " . $eff . "\n";
    }
    echo "\n";
};

if (!in_array('--off', $args, true) && $value('--to') === '') {
    $show();
    // Not a dialable example. Pasted verbatim this fails loudly, which is
    // the point — the last two placeholders went in as-is.
    echo "    php tools/set_alert_number.php --to <the handset to wake>\n";
    echo "    php tools/set_alert_number.php --off\n";
    echo "    php tools/set_alert_number.php --to <handset> --force   (already a conversation)\n\n";
    echo "  Use a person's handset. Not a number this plugin answers on:\n";
    foreach ($ours as $d => $n) echo "      " . $d . "  is " . $n . "\n";
    echo "\n";
    exit(0);
}

if (in_array('--off', $args, true)) {
    list($ok, $e) = PluginConfig::saveOverrides($dataDir, ['alert_whatsapp' => '']);
    echo $ok ? "\n  Alerts are off. A handover will tell nobody.\n\n" : "\n  Could not save: {$e}\n\n";
    exit($ok ? 0 : 1);
}

$to  = trim($value('--to'));
$dig = preg_replace('/\D+/', '', $to);

if (strlen($dig) < 9) {
    echo "\n  \"" . $to . "\" is not a phone number";
    echo $dig === '' ? " — it contains no digits at all.\n" : " — it reduces to " . $dig . ".\n";
    echo "  Country code, no plus, and a handset somebody actually carries.\n\n";
    exit(1);
}
if (isset($ours[$dig])) {
    echo "\n  " . $dig . " is our own " . $ours[$dig] . " instance.\n";
    echo "  An alert sent to a number this plugin answers on arrives as a customer\n";
    echo "  message, gets answered, and the answer lands back on the sender. Use a\n";
    echo "  person's handset instead.\n\n";
    exit(1);
}

$force = in_array('--force', $args, true);

// Is this a number the assistant is already in a conversation with? That is
// the c109 mechanism: alerts land in the thread, read as customer messages,
// and get answered. Matched on the last 9 digits, the same way identity
// lookups match, so +211 927 797 217 and 0927797217 are one number.
$conv = null;
try {
    $pdo   = SqliteStore::create($dataDir)->getPdo();
    $last9 = strlen($dig) >= 9 ? substr($dig, -9) : $dig;
    $st = $pdo->prepare(
        "SELECT id, channel, message_count, last_message_at, state
           FROM wa_conversations
          WHERE replace(replace(replace(phone,'+',''),' ',''),'-','') LIKE ?
          ORDER BY message_count DESC LIMIT 1"
    );
    $st->execute(['%' . $last9]);
    $conv = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) {
    echo "\n  (could not check conversations: " . $e->getMessage() . ")\n";
}

if ($conv && !$force) {
    echo "\n  " . $dig . " is already conversation c" . (int)$conv['id']
       . " on " . (string)$conv['channel'] . " — " . (int)$conv['message_count']
       . " message(s), last " . (string)($conv['last_message_at'] ?: 'unknown') . ".\n\n";
    echo "  Alerts sent there land in that thread. The assistant reads them as\n";
    echo "  customer messages and answers them, which is how one handset ended up\n";
    echo "  in 24 messages with its own alert channel while five customers waited.\n\n";
    echo "  If this is your own handset, close that conversation first, or:\n";
    echo "    php tools/set_alert_number.php --to " . $dig . " --force\n\n";
    exit(1);
}

// Different country from every number we answer on. Not wrong — an operator
// travels, and this company runs in three countries — but it is how Uganda
// handovers ended up being announced in South Sudan, so it is said out loud.
$ourCc = [];
foreach (array_keys($ours) as $d) if (strlen($d) >= 3) $ourCc[substr($d, 0, 3)] = true;
if ($ourCc && strlen($dig) >= 3 && !isset($ourCc[substr($dig, 0, 3)])) {
    echo "\n  Note: +" . substr($dig, 0, 3) . " is not the country any of our numbers\n";
    echo "  are on (" . implode(', ', array_map(fn($c) => '+' . $c, array_keys($ourCc))) . ").\n";
    echo "  Fine if that is where the person is. Worth knowing if it is not.\n";
}

list($ok, $err) = PluginConfig::saveOverrides($dataDir, ['alert_whatsapp' => $dig]);
if (!$ok) { echo "\n  Could not save: " . (string)$err . "\n\n"; exit(1); }
$show();
echo "  Send yourself one to be sure it arrives:\n";
echo "    php tools/wa_send_test.php --to " . $dig . "\n\n";
exit(0);

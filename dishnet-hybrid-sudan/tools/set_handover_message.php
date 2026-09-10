<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * set_handover_message.php — what the customer hears when the AI hands over.
 *
 *   php tools/set_handover_message.php                        show it
 *   php tools/set_handover_message.php --text "One moment…"   set it
 *   php tools/set_handover_message.php --default              use the suggested line
 *   php tools/set_handover_message.php --off                  say nothing (the old behaviour)
 *
 * When the assistant decides it cannot answer, it marks the conversation for
 * a human and buzzes the team. Until now it said nothing to the person who
 * asked. From their side that is identical to being ignored, and six
 * conversations sat in silence — one of them from nine in the morning.
 *
 * Keep it short and true. It must not promise a time, name a price, or claim
 * anything the assistant is not allowed to claim; it exists only so the
 * customer knows a person is coming.
 *
 * Off unless set, so an installation that has never configured it behaves
 * exactly as it did.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

const SUGGESTED = 'Let me get a colleague to confirm that for you — they will reply here shortly.';

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$args    = array_slice($argv, 1);
$value   = function (string $f) use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';
};

$current = trim((string)($config['ai_handover_message'] ?? ''));

if (!in_array('--off', $args, true) && !in_array('--default', $args, true) && $value('--text') === '') {
    echo "\n  ON HANDOVER THE CUSTOMER HEARS\n\n";
    echo $current !== ''
        ? "    \"" . $current . "\"\n"
        : "    nothing — they are left in silence while the team is alerted\n";
    echo "\n    php tools/set_handover_message.php --default\n";
    echo "    php tools/set_handover_message.php --text \"your own wording\"\n";
    echo "    php tools/set_handover_message.php --off\n\n";
    echo "  Suggested: \"" . SUGGESTED . "\"\n\n";
    exit(0);
}

$new = in_array('--off', $args, true) ? ''
     : (in_array('--default', $args, true) ? SUGGESTED : trim($value('--text')));

list($ok, $err) = PluginConfig::saveOverrides($dataDir, ['ai_handover_message' => $new]);
if (!$ok) { echo "\n  Could not save: " . (string)$err . "\n\n"; exit(1); }

$fresh = trim((string)(PluginConfig::load($root, $dataDir)['ai_handover_message'] ?? ''));
echo "\n";
echo $fresh !== ''
    ? "  On handover the customer now hears:\n    \"" . $fresh . "\"\n"
    : "  On handover the customer hears nothing again.\n";
echo "\n  It is sent once per conversation, not on every repeated handoff.\n\n";
exit(0);

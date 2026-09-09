<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * set_inbound_mail.php — tell the plugin which mailbox the AI may read.
 *
 *   php tools/set_inbound_mail.php --show
 *   php tools/set_inbound_mail.php --url https://mail.dishnetuganda.com \
 *       --mailbox accounts@dishnetuganda.com
 *   php tools/set_inbound_mail.php --resolve mail.example.com:443:172.20.0.4
 *
 * The PASSWORD is not settable here, deliberately. PluginConfig refuses to
 * write a secret to the data directory, and that refusal is the design: a
 * password typed at a command line lives on in the shell history, the process
 * list, and any terminal recording. This one opens the mailbox customers
 * write to. It goes in on uCRM's own Configuration screen, where it is
 * encrypted, and this tool will tell you whether it arrived.
 *
 * Reading a mailbox does not send anything. Drafts wait for a person.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/SecureFile.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);

$args  = array_slice($argv, 1);
$value = function (string $f, string $d = '') use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : $d;
};
$has = function (string $f) use ($args) { return in_array($f, $args, true); };

function mask(string $s): string
{
    if ($s === '') return '(not set)';
    return str_repeat('•', max(4, min(12, strlen($s)))) . ' (' . strlen($s) . ' chars)';
}

if ($args === [] || $has('--show')) {
    echo "\n  INBOUND MAIL — the mailbox the AI reads\n\n";
    printf("    %-22s %s\n", 'email_ai_jmap_url',   ($config['email_ai_jmap_url']   ?? '') ?: '(not set)');
    printf("    %-22s %s\n", 'email_ai_mailbox',    ($config['email_ai_mailbox']    ?? '') ?: '(not set)');
    printf("    %-22s %s\n", 'email_ai_mailbox_pw', mask((string)($config['email_ai_mailbox_pw'] ?? '')));
    printf("    %-22s %s\n", 'email_ai_jmap_via', ($config['email_ai_jmap_via'] ?? '') ?: '(none — plain DNS)');
    printf("    %-22s %s\n", 'email_ai_jmap_resolve', ($config['email_ai_jmap_resolve'] ?? '') ?: '(none)');
    echo "\n  Reading is all this enables. Every reply still waits for a person.\n\n";
    // --show on its own is a question, not a change.
    if ($args === [] || count($args) === 1) exit(0);
}

$updates = [];
if ($value('--url')     !== '') $updates['email_ai_jmap_url'] = rtrim($value('--url'), '/');
if ($value('--mailbox') !== '') $updates['email_ai_mailbox']  = trim($value('--mailbox'));
// host:port:ip, comma separated — the certificate's name at the address that
// actually holds it. jmap_probe.php prints the value to use.
if ($value('--resolve')  !== '') $updates['email_ai_jmap_resolve'] = trim($value('--resolve'));
// The docker name of the container actually holding the mailbox. Preferred
// over --resolve: it is re-resolved on every connect, so an address change
// when the mail stack is recreated costs nothing.
if ($value('--via')      !== '') $updates['email_ai_jmap_via'] = trim($value('--via'));

if ($has('--password')) {
    echo "\n  The mailbox password is not set from here.\n\n";
    echo "  Put it on the uCRM Configuration screen instead:\n";
    echo "    uCRM → System → Plugins → DishNet → Configuration\n";
    echo "    field: \"Inbound mail: mailbox password\"\n\n";
    echo "  It is encrypted there. A password typed at a command line stays in\n";
    echo "  the shell history and the process list, and this one opens the\n";
    echo "  mailbox your customers write to.\n\n";
    exit(1);
}

if ($updates === []) { echo "  Nothing to change.\n\n"; exit(0); }

[$saved, $err] = PluginConfig::saveOverrides($dataDir, $updates) + [null, null];
if ($saved === false) { echo "\n  Could not save: {$err}\n\n"; exit(1); }

echo "\n  Saved:\n";
foreach ($updates as $k => $v) {
    printf("    %-22s %s\n", $k, (string)$v);
}
echo "\n  Next:  php tools/inbound_mail_run.php --dry\n\n";
exit(0);

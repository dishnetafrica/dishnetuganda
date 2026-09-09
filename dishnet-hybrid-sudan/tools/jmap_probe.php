<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * jmap_probe.php — where does this mail server answer JMAP?
 *
 *   php tools/jmap_probe.php
 *   php tools/jmap_probe.php --url https://mail.example.com
 *
 * The configured URL is a guess until something proves it. Stalwart may serve
 * JMAP on the public name, on a container name inside the docker network, on a
 * plain HTTP port that is not exposed outside it, or not at all if JMAP is
 * switched off. Each of those fails differently and, until now, reported
 * identically.
 *
 * So this tries the candidates in turn and says exactly what each one did:
 * a name that will not resolve, a certificate that will not verify, a 401,
 * a 404, or a session. It reads nothing and changes nothing.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/JmapMailbox.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$config  = PluginConfig::load($root, $dataDir);

$args  = array_slice($argv, 1);
$value = function (string $f, string $d = '') use ($args) {
    $i = array_search($f, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : $d;
};

$user = (string)($config['email_ai_mailbox']    ?? '');
$pass = (string)($config['email_ai_mailbox_pw'] ?? '');

if ($user === '' || $pass === '') {
    echo "\n  Set the mailbox and password first:\n";
    echo "    php tools/set_inbound_mail.php --mailbox <address>\n";
    echo "    php tools/set_mailbox_password.php\n\n";
    exit(1);
}

// The configured URL first, then the shapes a Stalwart install usually takes.
// The mail host is derived from the address, so this works for any domain.
$domain     = substr(strrchr($user, '@') ?: '', 1);
$configured = rtrim((string)($value('--url', (string)($config['email_ai_jmap_url'] ?? ''))), '/');

$candidates = array_values(array_unique(array_filter([
    $configured,
    $domain !== '' ? 'https://mail.' . $domain : '',
    $domain !== '' ? 'https://' . $domain      : '',
    'http://stalwart:8080',
    'https://stalwart:443',
    'http://stalwart',
])));

echo "\n  Probing JMAP as {$user}\n";
echo "  " . str_repeat('─', 62) . "\n";

$winner = '';
foreach ($candidates as $url) {
    $box = new JmapMailbox($url, $user, $pass);
    // fetch() with a limit of 1 opens the session and stops; the probe is the
    // session, not the mail.
    $r   = $box->fetch('', 1);
    $t   = $box->lastTransport();

    if (!empty($r['ok'])) {
        printf("  %-34s SESSION OK — %d message(s) visible\n", $url, count($r['emails']));
        if ($winner === '') $winner = $url;
        continue;
    }

    $why = $box->transportProblem();
    printf("  %-34s %s\n", $url, $why !== '' ? $why : (string)$r['error']);
}

echo "  " . str_repeat('─', 62) . "\n";

if ($winner === '') {
    echo "\n  Nothing answered JMAP.\n\n";
    echo "  If every line says the name could not be resolved, this container\n";
    echo "  cannot see the mail server at all — the docker network attachment is\n";
    echo "  not durable and is lost whenever the stack is recreated:\n\n";
    echo "    docker network connect dishnet-mail_default ucrm\n\n";
    echo "  If a line says 404, JMAP is probably disabled on that server.\n";
    echo "  If a line says 401, the address or password is wrong.\n\n";
    exit(1);
}

echo "\n  JMAP answers at: {$winner}\n";
if ($winner !== $configured) {
    echo "\n  That is not what is configured. Point the plugin at it:\n\n";
    echo "    php tools/set_inbound_mail.php --url {$winner}\n\n";
} else {
    echo "  which is what is configured.\n\n";
}
exit(0);

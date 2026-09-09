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

$mailHost = $domain !== '' ? 'mail.' . $domain : '';

// Where the container next door actually is. The docker network gives it a
// name; JMAP needs an address to pin the certificate's hostname to.
$stalwartIp = '';
foreach (['stalwart', 'mail', 'dishnet-mail'] as $n) {
    $ip = gethostbyname($n);
    if ($ip !== $n && filter_var($ip, FILTER_VALIDATE_IP)) { $stalwartIp = $ip; break; }
}

/** @var array<int,array{url:string,resolve:string[],note:string}> */
$candidates = [];
$add = function (string $url, array $resolve = [], string $note = '') use (&$candidates) {
    $url = rtrim($url, '/');
    if ($url === '') return;
    foreach ($candidates as $c) if ($c['url'] === $url && $c['resolve'] === $resolve) return;
    $candidates[] = ['url' => $url, 'resolve' => $resolve, 'note' => $note];
};

$add($configured, [], 'configured');
if ($mailHost !== '') $add('https://' . $mailHost);

// The interesting ones: the correct hostname, pinned to the container's own
// address. The certificate is issued for the public name, so asking for the
// container by its docker name fails the TLS check — and asking for the public
// name leaves the machine and comes back through a proxy that answered 502.
// This is the combination that is neither.
if ($stalwartIp !== '' && $mailHost !== '') {
    foreach ([443, 8443] as $port) {
        $add('https://' . $mailHost . ($port === 443 ? '' : ':' . $port),
             [$mailHost . ':' . $port . ':' . $stalwartIp],
             'via container ' . $stalwartIp);
    }
}
foreach ([8080, 8081, 80] as $port) {
    $add('http://stalwart:' . $port);
    if ($stalwartIp !== '') $add('http://' . $stalwartIp . ':' . $port);
}
if ($domain !== '') $add('https://' . $domain);

echo "\n  Probing JMAP as {$user}\n";
echo "  mail host " . ($mailHost ?: '(unknown)')
   . " · container " . ($stalwartIp ?: 'NOT FOUND on this docker network') . "\n";
echo "  " . str_repeat('─', 62) . "\n";

$winner        = '';
$winnerResolve = [];
foreach ($candidates as $c) {
    $box = new JmapMailbox($c['url'], $user, $pass);
    if ($c['resolve'] !== []) $box->setResolve($c['resolve']);

    // fetch() with a limit of 1 opens the session and stops; the probe is the
    // session, not the mail.
    $r = $box->fetch('', 1);

    $label = $c['url'] . ($c['note'] !== '' ? '  [' . $c['note'] . ']' : '');
    if (!empty($r['ok'])) {
        printf("  %-46s SESSION OK — %d message(s) visible\n", $label, count($r['emails']));
        if ($winner === '') { $winner = $c['url']; $winnerResolve = $c['resolve']; }
        continue;
    }

    $why = $box->transportProblem();
    printf("  %-46s %s\n", $label, $why !== '' ? $why : (string)$r['error']);
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
if ($winnerResolve !== []) {
    echo "  pinned to: " . implode(', ', $winnerResolve) . "\n";
    echo "\n  Store both — the address matters as much as the URL here:\n\n";
    echo "    php tools/set_inbound_mail.php --url {$winner} --resolve "
       . escapeshellarg(implode(',', $winnerResolve)) . "\n\n";
} elseif ($winner !== $configured) {
    echo "\n  That is not what is configured. Point the plugin at it:\n\n";
    echo "    php tools/set_inbound_mail.php --url {$winner}\n\n";
} else {
    echo "  which is what is configured.\n\n";
}
exit(0);

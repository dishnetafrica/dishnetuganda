<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * dpo_probe.php — does DPO accept this install's company token, service type
 * and currency? Asked of DPO directly, before anyone is sent a test link.
 *
 * ── WHY ─────────────────────────────────────────────────────────────────
 *
 * DPO's test company tokens are shared test accounts, and which currencies an
 * account takes is a property of the account (createToken answers 904,
 * "Currency not supported"). The test checkout DPO's reviewer opens would fail
 * on the first click if the saved token did not take UGX. This finds out
 * first, and touches neither uCRM nor the payments table:
 *
 *   1. createToken for a small amount in the first accepted currency
 *   2. verifyToken on the token DPO returned — expected: 900, not paid yet
 *   3. DPO's own answer to each, and the checkout link, which can be opened
 *      to see DPO's test page with the amount on it
 *
 * TEST ENVIRONMENT ONLY. Against a live token it would open a real, unpaid
 * transaction on the merchant account, so it refuses.
 *
 * It never prints the company token.
 *
 * Run inside the ucrm container, from the plugin directory:
 *   php tools/dpo_probe.php                       the saved settings
 *   php tools/dpo_probe.php --ask                 type a token and service type
 *                                                 to try, without saving them
 *                                                 (docker exec -it, so the token
 *                                                 is not echoed)
 *   php tools/dpo_probe.php --currency UGX --amount 1000
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/error_handler.php';
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/DpoBootstrap.php';

$args = $argv ?? [];
$opt  = static function (string $name) use ($args): ?string {
    $i = array_search($name, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : null;
};

$dataDir = cliDataDir($root);
$config  = DpoBootstrap::vaulted(PluginConfig::load($root, $dataDir));

echo "\nDPO Pay — connection check\n\n";

$env = ((string)($config['dpo_environment'] ?? 'test')) === 'live' ? 'live' : 'test';
if ($env !== 'test') {
    echo "  The DPO environment is LIVE. This check opens a transaction at DPO, and\n"
       . "  against a live token that would be a real one on the merchant account,\n"
       . "  so it runs only in the Test environment. Nothing was sent.\n\n";
    exit(2);
}

/**
 * A line from the operator; the token is not echoed when there is a terminal.
 * A terminal can wrap a paste in bracketed-paste markers, and a copy from an
 * e-mail can carry invisible characters; both are removed.
 */
$ask = static function (string $label, bool $hidden): string {
    fwrite(STDOUT, $label);
    $tty = function_exists('stream_isatty') && @stream_isatty(STDIN);
    if ($hidden && $tty) @shell_exec('stty -echo 2>/dev/null');
    $v = str_replace(["\e[200~", "\e[201~"], '', (string)fgets(STDIN));
    $v = trim((string)preg_replace('/[\x00-\x1F\x7F]|\xC2\xA0|\xE2\x80[\x8B-\x8D]|\xEF\xBB\xBF/', '', $v));
    if ($hidden && $tty) { @shell_exec('stty echo 2>/dev/null'); fwrite(STDOUT, "\n"); }
    return $v;
};

$token = (string)($config['dpo_company_token'] ?? '');
$stype = (string)($config['dpo_service_type'] ?? '');
if (in_array('--ask', $args, true)) {
    $token = $ask('  Company token to try (not shown, not saved): ', true);
    $stype = $ask('  Service type to try: ', false);
    echo "\n";
}
if ($token === '' || $stype === '') {
    echo "  " . ($token === '' ? 'No company token' : 'No service type') . " is set. Enter both on the\n"
       . "  DPO Pay admin screen, or try a pair with --ask.\n\n";
    exit(1);
}
// Checked BEFORE anything goes to DPO. A company token is a GUID; a service
// type is a number. Whatever else was pasted — on 25 September, most likely
// the clipboard's contents — is not sent anywhere, and not printed.
$typed = in_array('--ask', $args, true) ? 'typed' : 'saved';
if (preg_match('/^[0-9A-Fa-f]{8}(-[0-9A-Fa-f]{4}){3}-[0-9A-Fa-f]{12}$/', $token) !== 1) {
    echo "  The company token {$typed} is not a DPO token. DPO's tokens are 36\n"
       . "  characters in five groups, like XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX —\n"
       . "  copy only that from DPO's e-mail. Nothing was sent to DPO.\n\n";
    exit(1);
}
if (preg_match('/^\d{1,10}$/', $stype) !== 1) {
    echo "  The service type {$typed} is not a DPO service type. It is a number from\n"
       . "  DPO's e-mail, listed under the token, such as 54842. Nothing was sent to DPO.\n\n";
    exit(1);
}

$currencies = DpoBootstrap::currencies($config);
$currency   = strtoupper(trim((string)($opt('--currency') ?? ($currencies[0] ?? 'UGX'))));
$amount     = (float)($opt('--amount') ?? 1000);
if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $amount <= 0) {
    echo "  --currency takes a three-letter code and --amount a positive number.\n\n";
    exit(1);
}

$client = [
    'company_token'   => $token,
    'service_type'    => $stype,
    'company_acc_ref' => (string)($config['dpo_company_acc_ref'] ?? ''),
    'environment'     => 'test',
    'ptl'             => max(0, (int)($config['dpo_ptl'] ?? 30)),
    'ptl_type'        => (string)($config['dpo_ptl_type'] ?? 'minutes'),
    'timeout'         => 30,
];
$fake = DpoBootstrap::harnessUrl('test');
if ($fake !== '') { $client['api_create'] = $fake; $client['api_verify'] = $fake; }
$dpo = new DpoClient($client);

$ref = 'PROBE-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
printf("  Asking DPO for a %s %s test transaction, reference %s\n\n",
       $currency, number_format($amount, 2), $ref);

$c = $dpo->createToken([
    'reference'    => $ref,
    'amount'       => $amount,
    'currency'     => $currency,
    'description'  => 'DishNet connection check',
    'redirect_url' => DpoBootstrap::returnUrl($config),
    'back_url'     => DpoBootstrap::backUrl($config),
]);
printf("  createToken   %s  %s\n", $c['code'], $c['message']);

if (!$c['ok']) {
    $why = [
        '801' => 'The company token is missing from the request.',
        '802' => 'DPO does not know this company token. Check it against DPO\'s e-mail.',
        '904' => "This DPO account does not take {$currency}. Try another test token, or ask DPO to enable {$currency} on it.",
        '905' => 'The amount is over this account\'s limit. Try a smaller --amount.',
        '902' => 'DPO says a required field is missing — most often the service type does not belong to this token.',
        '950' => 'DPO says a required field is missing — most often the service type does not belong to this token.',
    ];
    $hint = $why[$c['code']] ?? (in_array($c['code'], ['BADXML', 'CONFIG', 'NOTOKEN'], true)
        ? 'DPO could not be asked properly: ' . $c['message'] : 'DPO refused the request.');
    echo "\n  " . wordwrap($hint, 74, "\n  ") . "\n\n  Nothing was saved and nothing was sent to uCRM.\n\n";
    exit(1);
}

$v = $dpo->verifyToken($c['trans_token']);
printf("  verifyToken   %s  %s\n", $v['code'], $v['message']);

echo "\n  DPO accepted the token, the service type and {$currency}.\n";
if ($v['code'] === '900') {
    echo "  And verifyToken answers \"not paid yet\" for the new transaction, as it should.\n";
} elseif (!$v['reachable']) {
    echo "  But verifyToken could not be reached — payments could not be confirmed.\n";
} else {
    echo "  verifyToken answered something other than \"not paid yet\"; look at the code above.\n";
}
echo "\n  DPO's test page for it (it expires unpaid; nothing needs doing):\n  "
   . $dpo->checkoutUrl($c['trans_token']) . "\n\n"
   . "  Nothing was saved and nothing was sent to uCRM.\n\n";
exit($v['reachable'] ? 0 : 1);

<?php
/**
 * test_customer_message_identity.php — the money and the phone numbers a
 * customer is shown must belong to the country they live in.
 *
 * Every customer-facing WhatsApp message wrote "$" in front of the figure and
 * a +211 number underneath it, so a Ugandan invoice notification read
 * "Amount: $1,645,440.00 ... Help: +211 921 443 002". Both now come from
 * config. These tests pin the Uganda rendering AND the Sudan one, because the
 * defaults have to reproduce what that install has always sent.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/currency.php';
require_once $root . '/lib/CustomerContact.php';
require_once $root . '/lib/NotificationService.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }
function eq(string $got, string $want, string $m): void {
    $got === $want ? ok($m) : bad($m, "got  [{$got}]\n       want [{$want}]");
}

$SUDAN  = ['currency_symbol' => '$'];
$UGANDA = ['currency_symbol' => 'UGX'] + CustomerContact::UGANDA;

echo "\nThe symbol sits where that currency puts it\n";
eq(dn_money(1234.5, $SUDAN),      '$1,234.50',      'a sigil sits tight against the digits');
eq(dn_money(1645440, $UGANDA),    'UGX 1,645,440.00', 'a letter code takes a space');
eq(dn_money('1,234.00', $SUDAN),  '$1,234.00',      'an already-formatted string is not reformatted');
eq(dn_money(1234.5, $SUDAN, null),'$1234.5',        'dp=null prints exactly the digits handed over');
eq(dn_money(0, $SUDAN),           '$0.00',          'zero still renders');
eq(dn_money(99, []),              'UGX 99.00',      'no config at all falls back to the plugin default');

echo "\nThe contact details default to Sudan and follow configuration\n";
eq(CustomerContact::accounts([]),   '+211 921 443 002', 'accounts number default');
eq(CustomerContact::support([]),    '+211 921 443 006', 'support number default');
eq(CustomerContact::sales([]),      '+211 921 443 009', 'sales number default');
eq(CustomerContact::escalation([]), '+211 927 797 217', 'escalation number default');
eq(CustomerContact::shop([]),       '0923 400 000',     'shop number default');
eq(CustomerContact::payUrl([]),     'https://dishnetafrica.com/tutorials/index.html', 'payment link default');
eq(CustomerContact::appUrl([]),     'https://dishnetafrica.com/get-the-app.html',     'app link default');
is_(CustomerContact::accounts($UGANDA) === '+256 705 993 348',
    'a configured install answers with its own number');
is_(strpos(json_encode(CustomerContact::all($UGANDA)), '+211') === false,
    'and nothing +211 survives anywhere in the configured set');

echo "\nNotificationService picks these up at construction\n";
$get = function (NotificationService $n, string $prop) {
    $r = new ReflectionProperty(NotificationService::class, $prop);
    $r->setAccessible(true);
    return (string)$r->getValue($n);
};
$callMoney = function (NotificationService $n, $v) {
    $m = new ReflectionMethod(NotificationService::class, 'money');
    $m->setAccessible(true);
    return (string)$m->invoke($n, $v);
};

$sud = new NotificationService(null, $SUDAN);
$ug  = new NotificationService(null, $UGANDA);

eq($get($sud, 'cAccountsPhone'), '+211 921 443 002', 'Sudan install keeps its accounts number');
eq($get($sud, 'cPayUrl'), 'https://dishnetafrica.com/tutorials/index.html', 'and its payment link');
eq($get($ug,  'cAccountsPhone'), '+256 705 993 348', 'Uganda install gets the Uganda number');
is_(strpos($get($ug, 'cPayUrl'), 'dishnetuganda.com') !== false,
    'and a dishnetuganda.com payment link');

eq($callMoney($sud, 1234.5),  '$1,234.50',        'money() on Sudan renders dollars');
eq($callMoney($ug,  1645440), 'UGX 1,645,440.00', 'money() on Uganda renders shillings');

echo "\nNothing customer-facing still hardcodes the other country\n";
foreach ([
    'lib/NotificationService.php', 'webhook.php', 'cron_quote_wa.php',
    'lib/DeliveryPdfService.php', 'cron_maintenance.php',
] as $rel) {
    $src = (string)file_get_contents($root . '/' . $rel);
    // Strip comments before looking: an explanatory comment may name the old
    // literal, and that is not what a customer reads.
    $code = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $code .= is_array($t) ? $t[1] : $t;
    }
    is_(strpos($code, '+211 921 443') === false,
        "{$rel}: no +211 number left in code");
    is_(strpos($code, 'dishnetafrica.com/tutorials') === false
        && strpos($code, 'dishnetafrica.com/get-the-app') === false,
        "{$rel}: no dishnetafrica.com customer link left in code");
}

echo "\nThe one source of truth is genuinely one\n";
$ns = (string)file_get_contents($root . '/lib/NotificationService.php');
is_(strpos($ns, 'CustomerContact::accounts($config)') !== false,
    'NotificationService reads the shared contact source, not its own copy');
$brand = (string)file_get_contents($root . '/tools/set_email_brand.php');
is_(strpos($brand, 'CustomerContact::UGANDA') !== false,
    'the Uganda preset writes the WhatsApp contacts as well as the email brand');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

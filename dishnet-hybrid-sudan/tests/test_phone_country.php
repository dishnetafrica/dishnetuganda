<?php
/**
 * test_phone_country.php — a customer's number keeps its own country.
 *
 * Two rules, and the second matters more than it looks:
 *
 *   A LOCAL number (0705993348) has to be given a country, and it should be
 *   the country the operation is in — not the country this plugin was first
 *   written for. Hardcoded to 211, a Ugandan number became 211705993348,
 *   a South Sudan number that does not exist.
 *
 *   An INTERNATIONAL number is never touched. A customer in Kampala holding a
 *   South Sudan, Kenyan or Indian WhatsApp number is completely ordinary. It
 *   is not an error to be corrected, and correcting it would send their
 *   messages into the void.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/CustomerContact.php';
require_once $root . '/lib/EvolutionApiService.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

// LeadRecoveryService::normalisePhone is private and the class needs a PDO,
// so exercise it through reflection on a real instance.
require_once $root . '/lib/LeadRecoveryService.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$svc = new LeadRecoveryService($pdo);
$m   = new ReflectionMethod(LeadRecoveryService::class, 'normalisePhone');
$m->setAccessible(true);
$norm = function (string $p) use ($m, $svc) { return (string)$m->invoke($svc, $p); };

// No config on disk in the test environment, so the default applies: 211.
echo "\nWith nothing configured, the Sudan default still applies\n";
is_(CustomerContact::countryCode([]) === '211', 'the country code defaults to 211');
is_($norm('0921443009') === '211921443009', 'a local 0-number becomes a 211 number');
is_($norm('921443009')  === '211921443009', 'and so does the 9-digit form');

echo "\nAn international number is left exactly as it is\n";
foreach ([
    '256705993348' => 'a Uganda number',
    '211927797217' => 'a South Sudan number',
    '254712345678' => 'a Kenya number',
    '919876543210' => 'an India number',
    '971501234567' => 'a UAE number',
] as $num => $what) {
    $num = (string)$num;   // numeric-looking keys arrive as int
    is_($norm($num) === $num, "{$what} passes through untouched", 'got ' . $norm($num));
    is_($norm('+' . $num) === $num, "{$what} with a plus is only stripped of the plus");
}
is_($norm('00256705993348') === '256705993348',
    'the 00 international prefix is removed, the country kept');

echo "\nThe send path never rewrites a number at all\n";
foreach (['256705993348', '211927797217', '919876543210'] as $num) {
    is_(EvolutionApiService::normalisePhone('+' . $num) === $num,
        "EvolutionApiService keeps {$num} as it is");
}
is_(EvolutionApiService::normalisePhone('+256 705 993 348') === '256705993348',
    'spacing and punctuation are removed, digits are not');

echo "\nThe code is configurable, which is the whole point\n";
is_(CustomerContact::countryCode(['contact_country_code' => '256']) === '256',
    'an install can declare its own');
is_(CustomerContact::countryCode(CustomerContact::UGANDA) === '256',
    'and the Uganda preset declares 256');
is_(CustomerContact::countryCode(['contact_country_code' => '+254']) === '254',
    'a plus in the setting is tolerated');
is_(CustomerContact::countryCode(['contact_country_code' => '']) === '211',
    'an empty setting falls back rather than producing a broken number');

echo "\nNo hardcoded 211 expansion is left\n";
$src = (string)file_get_contents($root . '/lib/LeadRecoveryService.php');
$code = '';
foreach (token_get_all($src) as $tk) {
    if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $code .= is_array($tk) ? $tk[1] : $tk;
}
is_(strpos($code, "'211' . substr(") === false, "no '211' . substr() expansion remains");
is_(strpos($code, "'211' . (\$phone[0]") === false, 'nor the 9-digit variant');
is_(substr_count($code, "knownPrefixes()") >= 3,
    'the matching heuristics ask for known prefixes instead');
is_(strpos($code, "strpos(\$phone, '211') === 0) return 'SS'") !== false
    || strpos($code, "'211') === 0) return 'SS'") !== false,
    'detectCountry still recognises 211 as South Sudan, which is correct');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

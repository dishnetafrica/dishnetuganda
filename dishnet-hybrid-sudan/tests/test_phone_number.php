<?php
declare(strict_types=1);
/**
 * test_phone_number.php — PhoneNumber::withDialCode (Phase 2 of the
 * customer-login audit, plan §D.4 / §I).
 *
 * The rule is table-driven and carries no country of its own: the dial code
 * comes from the caller (the tenant profile), a tenant without one gets null
 * for every input, and the source file holds no dial-code literal — that last
 * point is what stops the South Sudan code from creeping back in.
 */
$pass = 0; $fail = 0;
function t(string $n, $got, $want): void { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
function codeNC(string $f): string {
    $o = '';
    foreach (token_get_all((string)file_get_contents($f)) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $o .= $k[1]; }
        else $o .= $k;
    }
    return $o;
}
$root = dirname(__DIR__);
require_once $root . '/lib/PhoneNumber.php';

echo "1. Uganda tenant (dial 256, nine-digit national numbers)\n";
$ug = [
    '+256772123456'    => '+256772123456',   // international, kept
    '0772123456'       => '+256772123456',   // national with the trunk 0
    '256772123456'     => '+256772123456',   // international without the +
    '772123456'        => '+256772123456',   // bare national significant number
    '00256772123456'   => '+256772123456',   // 00 prefix
    '+256 772 123 456' => '+256772123456',   // spaces
    '0772-123-456'     => '+256772123456',   // dashes
    '(0772) 123 456'   => '+256772123456',   // brackets
    '+211921443006'    => '+211921443006',   // another country's international number: kept, NOT re-prefixed
    '211921443006'     => '+211921443006',   // same without the +
    '07721234'         => null,              // too short: never guess
    '7721234567'       => null,              // ten digits, no trunk 0, not international: never guess
    '02567721234'      => null,              // eleven digits starting with 0: not a number anyone can call
    '+0772123456'      => null,              // + with a trunk 0
    '+123'             => null,              // international but far too short
    '00'               => null,
    '+'                => null,
    ''                 => null,
    'abc'              => null,
    '   '              => null,
];
foreach ($ug as $in => $want) { $in = (string)$in; t("UG " . var_export($in, true), PhoneNumber::withDialCode($in, '256'), $want); }   // numeric-string keys become ints in PHP arrays

echo "2. South Sudan tenant (dial 211): the same rule, the other code\n";
$ss = [
    '0921443006'    => '+211921443006',
    '921443006'     => '+211921443006',
    '+211921443006' => '+211921443006',
    '00211921443006'=> '+211921443006',
    '+256772123456' => '+256772123456',      // a Uganda number typed in Juba is kept as typed
    '92144300'      => null,
];
foreach ($ss as $in => $want) { $in = (string)$in; t("SS " . var_export($in, true), PhoneNumber::withDialCode($in, '211'), $want); }

echo "3. The dial code's own spelling does not matter; a tenant without one addresses nothing\n";
t("'+256' as the dial code", PhoneNumber::withDialCode('0772123456', '+256'), '+256772123456');
t("' 256 ' as the dial code", PhoneNumber::withDialCode('0772123456', ' 256 '), '+256772123456');
t("empty dial code → null even for a valid national number", PhoneNumber::withDialCode('0772123456', ''), null);
t("empty dial code → null even for an international number", PhoneNumber::withDialCode('+256772123456', ''), null);
t("a national-number length below six is refused", PhoneNumber::withDialCode('12345', '256', 5), null);
t("a different national length is honoured (eight digits)", PhoneNumber::withDialCode('12345678', '20', 8), '+2012345678');

echo "4. The helper carries no country of its own\n";
$src = codeNC($root . '/lib/PhoneNumber.php');
is_(!preg_match('/(?<![0-9])(256|211)(?![0-9])/', $src), 'no 256 or 211 literal in lib/PhoneNumber.php (comments stripped)');
is_(!preg_match('/[\'"]\+?[1-9]\d{1,2}[\'"]/', $src), 'no quoted dial-code-shaped literal (two or three digits, not starting with 0) in lib/PhoneNumber.php');
is_(strpos($src, 'TenantProfile') !== false, 'international() takes the tenant profile, so the dial code has one source');
$rm = new ReflectionMethod('PhoneNumber', 'international');
$pt = $rm->getParameters()[1]->getType();
t('international()\'s second parameter is TenantProfile', $pt ? $pt->getName() : null, 'TenantProfile');

echo "5. The control on the control: a copy with a built-in fallback code is caught\n";
$weak = str_replace("if (\$dial === '' || \$nsnLength < 6) return null;", "if (\$dial === '') \$dial = '256'; if (\$nsnLength < 6) return null;", $src);
is_($weak !== $src, 'the weakened copy differs from the original');
is_((bool)preg_match('/(?<![0-9])(256|211)(?![0-9])/', $weak), 'the literal guard fires on the weakened copy');
$tmp = tempnam(sys_get_temp_dir(), 'dn-weak-'); file_put_contents($tmp, str_replace('final class PhoneNumber', 'final class PhoneNumberWeak', $weak));
require $tmp; @unlink($tmp);
is_(PhoneNumberWeak::withDialCode('0772123456', '') === '+256772123456', 'and the table row "empty dial code → null" would fail against it (it guesses Uganda)');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

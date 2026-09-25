<?php
declare(strict_types=1);
/**
 * test_tenant_profile.php — one profile, many readers (Phase 2 of the
 * customer-login audit, plan §D; decision D-16).
 *
 * Proves the resolution order (explicit key → profile → the reader's literal),
 * that profiles/south-sudan.json IS the set of literals the code carried
 * before (so an install that configures nothing is byte-identical), that
 * profiles/uganda.json holds only values already in the repository, that a
 * null field renders "not configured" and never the other tenant's value,
 * and that the profile class carries no country of its own.
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
if (!getenv('DN_VAULT_FILE')) putenv('DN_VAULT_FILE=' . tempnam(sys_get_temp_dir(), 'dn-vault-'));
require_once $root . '/lib/TenantProfile.php';
require_once $root . '/lib/PhoneNumber.php';
require_once $root . '/lib/CustomerContact.php';
require_once $root . '/lib/EmailTemplate.php';
require_once $root . '/lib/PortalLocale.php';
require_once $root . '/lib/timezone.php';

echo "1. Resolution: selector → currency → south-sudan\n";
t('nothing configured → south-sudan', TenantProfile::resolveId([]), 'south-sudan');
t('currency UGX → uganda', TenantProfile::resolveId(['currency_code' => 'UGX']), 'uganda');
t('cashbook_base_currency UGX → uganda', TenantProfile::resolveId(['cashbook_base_currency' => 'ugx']), 'uganda');
t('currency SSP → south-sudan', TenantProfile::resolveId(['currency_code' => 'SSP']), 'south-sudan');
t('currency USD → south-sudan', TenantProfile::resolveId(['currency_code' => 'USD']), 'south-sudan');
t('an unknown currency → south-sudan', TenantProfile::resolveId(['currency_code' => 'KES']), 'south-sudan');
t('the selector wins over the currency', TenantProfile::resolveId(['tenant_profile' => 'uganda', 'currency_code' => 'USD']), 'uganda');
t('…both ways', TenantProfile::resolveId(['tenant_profile' => 'south-sudan', 'currency_code' => 'UGX']), 'south-sudan');
t('a misspelt selector falls through to the currency', TenantProfile::resolveId(['tenant_profile' => 'kenya', 'currency_code' => 'UGX']), 'uganda');
t('load() of an unknown id gives the default profile', TenantProfile::load('nope')->id(), 'south-sudan');
t('current([]) is south-sudan', TenantProfile::current([])->id(), 'south-sudan');

echo "2. profiles/south-sudan.json IS the set of literals the readers carried\n";
$ss = TenantProfile::load('south-sudan');
$cc = $ss->contactDefaults();
foreach (CustomerContact::DEFAULTS as $k => $v) t("contacts: $k", $cc[$k] ?? null, $v);
t('no contact key beyond the DEFAULTS', array_diff(array_keys($cc), array_keys(CustomerContact::DEFAULTS)), []);
$eb = $ss->emailBrandDefaults();
foreach (EmailTemplate::DEFAULTS as $k => $v) if ($k !== 'email_accent') t("email brand: $k", $eb[$k] ?? null, $v);
$dn = $ss->dunningDefaults();
t('dunning from_name', $dn['overdue_email_from_name'] ?? null, 'DishNet Accounts');
t('dunning phone', $dn['overdue_email_phone'] ?? null, '+211 921 443 009');
t('dunning accounts e-mail', $dn['overdue_email_accounts_email'] ?? null, 'accounts@dishnetafrica.com');
t('dunning company line', $dn['overdue_email_company_line'] ?? null, 'DishNet Africa Ltd · Airport Road, Juba, South Sudan');
t('dunning website', $dn['overdue_email_website'] ?? null, 'www.dishnetafrica.com');
t('timezone = the literal dn_tz() falls back to', $ss->timezone(), 'Africa/Juba');
t('dial code = CustomerContact::countryCode([])', $ss->dialCode(), CustomerContact::countryCode([]));
t('the login hint = PortalLocale::FALLBACK', ['+' . $ss->dialCode(), $ss->countryName(), $ss->phoneExample()], PortalLocale::FALLBACK);
$web = (string)file_get_contents($root . '/tabs/customer_app/login_web.php');
foreach (['title' => 'DishNet Africa', 'footer_wa' => '211921443002', 'footer_entity' => 'DishNet Africa Ltd.', 'footer_locality' => 'Juba, South Sudan'] as $f => $lit) {
    t("login page: profile $f equals the page's own literal fallback", $ss->login($f), $lit);
    is_(strpos($web, "\$lwProfile->login('$f', '$lit')") !== false, "…and login_web.php reads it with that fallback");
}

echo "3. With nothing configured, every reader gives exactly what it gave before\n";
t('CustomerContact::all([]) = DEFAULTS', CustomerContact::all([]), CustomerContact::DEFAULTS);
$brand = EmailTemplate::brand([]);
foreach (EmailTemplate::DEFAULTS as $k => $v) t("EmailTemplate::brand([])[" . substr($k, 6) . "]", $brand[substr($k, 6)] ?? null, $v);
t('PortalLocale::dialHint([])', PortalLocale::dialHint([]), ['code' => '+211', 'country' => 'South Sudan', 'example' => '+211 9XX XXX XXX']);
if (function_exists('dn_tz_reset')) dn_tz_reset();
$dir = sys_get_temp_dir() . '/dn_tp_' . getmypid(); @mkdir($dir, 0700, true); putenv('DN_DATA_DIR=' . $dir);
if (function_exists('dn_tz_reset')) dn_tz_reset();
t('dn_tz() with no configuration anywhere', dn_tz(), 'Africa/Juba');

echo "4. profiles/uganda.json holds only values the repository already had\n";
$ug = TenantProfile::load('uganda');
$ucc = $ug->contactDefaults();
foreach (CustomerContact::UGANDA as $k => $v) t("uganda contacts: $k = CustomerContact::UGANDA", $ucc[$k] ?? null, $v);
$brandSrc = (string)file_get_contents($root . '/tools/set_email_brand.php');
foreach ($ug->emailBrandDefaults() as $k => $v) {
    if ($v === '') continue;
    is_(strpos($brandSrc, "'" . $v . "'") !== false, "uganda email brand $k = '$v' is the value tools/set_email_brand.php --uganda sets");
}
t('uganda timezone = FollowUpPolicy::TZ', $ug->timezone(), 'Africa/Kampala');
t('uganda login hint = PortalLocale::BY_CURRENCY[UGX]', ['+' . $ug->dialCode(), $ug->countryName(), $ug->phoneExample()], PortalLocale::BY_CURRENCY['UGX']);
is_(strpos((string)file_get_contents($root . '/tools/knowledge_seed.json'), 'Acacia Mall') !== false, 'the Kampala office comes from the knowledge seed');
t('uganda legal entity = the Uganda invoice template\'s', $ug->legalEntity(), 'DishNet Africa Limited');
t('the unanswered questions are null (plan §D.6)', $ug->nulls(), ['office.hours', 'jurisdiction.courts', 'legal_texts', 'payment_instructions']);
t('a null field renders "not configured"', $ug->text('office.hours', TenantProfile::notConfigured()), 'not configured');
is_($ug->text('office.hours', TenantProfile::notConfigured()) !== $ss->text('office.hours', 'x'), '…never the other tenant\'s value (which is null too)');
is_(strpos(json_encode($ug->describe()), '211') === false, 'nothing in the uganda profile mentions South Sudan\'s dial code');

echo "5. The selector alone re-points every reader; explicit keys still win\n";
$ugCfg = ['tenant_profile' => 'uganda'];
t('CustomerContact::accounts(uganda)', CustomerContact::accounts($ugCfg), '+256 705 993 348');
t('CustomerContact::countryCode(uganda)', CustomerContact::countryCode($ugCfg), '256');
t('CustomerContact::payUrl(uganda)', CustomerContact::payUrl($ugCfg), 'https://dishnetuganda.com/pay');
t('EmailTemplate::brand(uganda) company', EmailTemplate::brand($ugCfg)['company_name'], 'DishNet Africa Limited');
t('EmailTemplate::brand(uganda) support phone', EmailTemplate::brand($ugCfg)['support_phone'], '+256 705 993 348');
t('EmailTemplate::brand(uganda) accent stays the reader\'s own', EmailTemplate::brand($ugCfg)['accent'], '#D41C1C');
t('PortalLocale::dialHint(uganda)', PortalLocale::dialHint($ugCfg), ['code' => '+256', 'country' => 'Uganda', 'example' => '+256 7XX XXX XXX']);
file_put_contents($dir . '/kyc_config.json', json_encode(['tenant_profile' => 'uganda']));
if (function_exists('dn_tz_reset')) dn_tz_reset();
t('dn_tz() follows the profile on disk', dn_tz(), 'Africa/Kampala');
t('…but an explicit timezone wins', dn_tz(['timezone' => 'Africa/Nairobi']), 'Africa/Nairobi');
t('an explicit contact key beats the profile', CustomerContact::accounts($ugCfg + ['contact_accounts_phone' => '+256 700 000 001']), '+256 700 000 001');
t('an explicit e-mail key beats the profile', EmailTemplate::brand($ugCfg + ['email_company_name' => 'X Ltd'])['company_name'], 'X Ltd');
t('portal_dial_code beats the profile\'s dial code', TenantProfile::current($ugCfg + ['portal_dial_code' => '+254'])->dialCode(), '254');
t('contact_country_code beats the profile\'s dial code', TenantProfile::current($ugCfg + ['contact_country_code' => '211'])->dialCode(), '211');
t('PhoneNumber through the uganda profile', PhoneNumber::international('0772123456', $ug), '+256772123456');
t('PhoneNumber through the south-sudan profile', PhoneNumber::international('0921443006', $ss), '+211921443006');
t('a South Sudan install completes a national number with +211 still', PhoneNumber::international('0921443006', TenantProfile::current([])), '+211921443006');

echo "6. The class carries no country of its own\n";
$src = codeNC($root . '/lib/TenantProfile.php');
is_(!preg_match('/(?<![0-9])(256|211|254)(?![0-9])/', $src), 'no dial code literal in TenantProfile.php');
is_(strpos($src, 'Africa/') === false, 'no time zone literal in TenantProfile.php');
is_(strpos($src, 'Juba') === false && strpos($src, 'Kampala') === false, 'no city literal in TenantProfile.php');
foreach (['south-sudan', 'uganda'] as $id) is_(is_array(json_decode((string)file_get_contents("$root/profiles/$id.json"), true)), "profiles/$id.json is valid JSON");

echo "7. The control on the control: a drifted profile is caught\n";
$drift = json_decode((string)file_get_contents("$root/profiles/south-sudan.json"), true);
$drift['contacts']['accounts_phone'] = '+211 921 443 003';
$mismatch = 0; foreach (CustomerContact::DEFAULTS as $k => $v) { $pk = substr($k, 8); if (isset($drift['contacts'][$pk]) && $drift['contacts'][$pk] !== $v) $mismatch++; }
t('a profile that drifted one digit from the literals it replaced would fail §2', $mismatch, 1);

exec('rm -rf ' . escapeshellarg($dir));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

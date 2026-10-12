<?php
declare(strict_types=1);
/**
 * test_legal_overrides.php — 5.18.94 (docs/67 §J): a tenant's profile may replace a portal Terms or Privacy section's
 * body (`legal.terms_text.<key>`, `legal.privacy_text.<key>`); lib/LegalContent.php falls back to the shared text when
 * it does not. A fallback is silent, so a deleted, blank or misspelt Uganda override would bring back an untrue sentence
 * (a UCC authorisation, "best-effort", a discretionary credit) with nothing failing. Pinned here:
 *   - the section key lists match the documents, section for section;
 *   - Uganda's overrides are exactly the pinned set, in order, each a non-empty string that renders exactly;
 *   - South Sudan has no override and still renders byte for byte (the golden of test_portal_tenant);
 *   - Uganda's documents carry none of the withdrawn statements;
 *   - the headings and the section counts are unchanged for both tenants.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d !== '' ? "\n       $d" : '') . "\n"; } }
$root = dirname(__DIR__);
require_once $root . '/lib/TenantProfile.php';
require_once $root . '/lib/LegalContent.php';

$KEYS = dnLegalSectionKeys();
$ug = TenantProfile::load('uganda'); $ss = TenantProfile::load('south-sudan');
$docs = ['terms' => 'dnTermsContent', 'privacy' => 'dnPrivacyContent'];

echo "1. The key lists match the documents\n";
foreach ($docs as $doc => $fn) {
    foreach (['uganda' => $ug, 'south-sudan' => $ss] as $id => $tp) {
        is_(count($fn($tp)) === count($KEYS[$doc]), "[$id] $doc: one key per section (" . count($KEYS[$doc]) . ')');
    }
    is_(count(array_unique($KEYS[$doc])) === count($KEYS[$doc]), "$doc: the keys are distinct");
}
is_(array_column(dnTermsContent($ug), 'heading') === array_column(dnTermsContent($ss), 'heading'), 'terms: the headings are the same for both tenants (an override never touches a heading)');
$hU = array_column(dnPrivacyContent($ug), 'heading'); $hS = array_column(dnPrivacyContent($ss), 'heading');
$si = array_search('sign_in', $KEYS['privacy'], true); unset($hU[$si], $hS[$si]);
is_($hU === $hS, 'privacy: the headings are the shared ones, except the sign-in heading, which is each profile\'s own as before');

echo "\n2. Uganda's overrides: exactly the pinned set, each non-empty, each rendered exactly\n";
$PIN = ['terms'   => ['about', 'service', 'billing', 'acceptable_use', 'equipment', 'transfers', 'termination', 'liability', 'changes', 'jurisdiction'],
        'privacy' => ['collect', 'use', 'share', 'retention', 'rights', 'contact']];
$wa = TenantProfile::formatWa($ug->contact('support_wa', '211921443002'));
$em = $ug->email() ?: 'info@dishnetafrica.com';
foreach ($PIN as $doc => $want) {
    $ov = $ug->get("legal.{$doc}_text");
    is_(is_array($ov) && array_keys($ov) === $want, "[uganda] $doc override keys are exactly the pinned set, in order", json_encode(is_array($ov) ? array_keys($ov) : $ov));
    is_(is_array($ov) && array_diff(array_keys($ov), $KEYS[$doc]) === [], "[uganda] every $doc override names a real section");
    $rendered = $docs[$doc]($ug);
    foreach ($want as $k) {
        $v = is_array($ov) ? ($ov[$k] ?? null) : null;
        is_(is_string($v) && trim($v) !== '', "[uganda] $doc.$k is a non-empty string");
        $i = array_search($k, $KEYS[$doc], true);
        $exp = is_string($v) ? strtr($v, ['{wa}' => $wa, '{email}' => $em]) : null;
        is_($exp !== null && ($rendered[$i]['body'] ?? null) === $exp, "[uganda] $doc.$k renders exactly its override");
        is_($exp !== null && strpos($exp, '{') === false, "[uganda] $doc.$k leaves no placeholder unexpanded");
    }
}

echo "\n3. South Sudan has no override and renders as before\n";
is_($ss->get('legal.terms_text') === null && $ss->get('legal.privacy_text') === null, '[south-sudan] the profile defines no terms_text or privacy_text');
$golden = hash('sha256', json_encode([dnTermsContent($ss), dnPrivacyContent($ss)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
is_($golden === 'b2f4ff3b34bb1fcabc11bc87beb9f66cbafee22605c3a9e69d42ebcb1ead4637', '[south-sudan] Terms and Privacy byte-identical (golden sha256)', $golden);

echo "\n4. Uganda carries none of the withdrawn statements, and the version is 1.2\n";
$text = implode("\n", array_column(dnTermsContent($ug), 'body')) . "\n" . implode("\n", array_column(dnPrivacyContent($ug), 'body'));
foreach (['UCC-authorised', 'UCC Authorised', 'best-effort', 'at our discretion', 'without further notice', 'Late-payment',
          '3 additional years', 'stays on your router', 'anyone else without your permission', 'may change without notice',
          '90 to 365', 'You may end your service at any time', 'within a few business days', 'returned within 14 days'] as $n) {
    is_(strpos($text, $n) === false, "[uganda] no \"$n\"");
}
$v = dnLegalVersion($ug);
is_($v['tos'] === '1.2' && $v['privacy'] === '1.2', '[uganda] Terms and Privacy are version 1.2', json_encode($v));
is_((string)$ug->get('legal.identity') !== '' && stripos((string)$ug->get('legal.identity'), 'UCC') === false, '[uganda] legal.identity claims no UCC authorisation');
is_(stripos((string)$ug->get('positioning'), 'UCC') === false && stripos((string)$ug->get('email_brand.badge_line'), 'UCC') === false,
    '[uganda] positioning and the e-mail badge claim no UCC authorisation');

echo "\n5. The control: a planted fallback IS seen\n";
// Remove one override in a copy of the profile data and prove the fallback text returns — what the pins above catch.
$data = json_decode((string)file_get_contents("$root/profiles/uganda.json"), true);
unset($data['legal']['terms_text']['billing']);
$r = new ReflectionClass('TenantProfile');
$probe = $r->newInstanceWithoutConstructor();
foreach (['data'] as $prop) { if ($r->hasProperty($prop)) { $p = $r->getProperty($prop); $p->setAccessible(true); $p->setValue($probe, $data); } }
$billing = dnTermsContent($probe)[array_search('billing', $KEYS['terms'], true)]['body'] ?? '';
is_(strpos($billing, 'Late-payment and reconnection charges') !== false, 'with terms_text.billing removed, the old sentence comes back (so the pins above have subject matter)', substr($billing, 0, 80));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

<?php
declare(strict_types=1);
/**
 * test_tools_phone_identity.php — the live path decides identity the same way
 * the hardened one does.
 *
 * DishNetTools::identifyCustomerByPhone is what the Evolution WhatsApp path
 * uses, and that path is the one actually answering customers: it loads the
 * balance, the latest invoice and the last payment for whoever it decides the
 * caller is. So the question this file asks is not "does the matcher work" but
 * "can it ever name the wrong person".
 *
 * It could. Nine trailing digits are a subscriber number, not a person, and
 * this client base spans +256 and +211. A stored South Sudan number sharing
 * its last nine with an incoming Uganda number produced ONE match — not an
 * ambiguous result the caller asks a verifying question about, but a
 * confident identification of the wrong customer.
 *
 * These tests were the first coverage this matcher had.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/CustomerIdentity.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/DishNetTools.php';

/** The client index, and nothing else. No CRM, so no API fallback runs. */
final class IdxStore implements StoreInterface
{
    public array $index = [];
    public function load(string $file): array { return $file === 'client_search_index.json' ? $this->index : []; }
    public function save(string $file, array $data): void {}
    public function append(string $file, array $record): array { return $record; }
    public function findOne(string $file, string $key, $value): ?array { return null; }
    public function findAll(string $file, string $key, $value): array { return []; }
    public function updateOne(string $file, string $key, $value, array $updates): bool { return true; }
    public function nextId(string $file): int { return 1; }
    public function appendWithId(string $file, array $record): array { return $record; }
    public function path(string $file): string { return '/dev/null'; }
    public function withLock(string $file, callable $fn) { return $fn(); }
}

function toolsWith(array $index, array $config = []): DishNetTools
{
    $s = new IdxStore();
    $s->index = $index;
    return new DishNetTools($s, $config, dirname(__DIR__));
}

/** Reach the matcher directly — it is private, and it is the thing under test. */
function matches(DishNetTools $t, string $stored, string $incoming, bool $legacy = false): bool
{
    $m = new ReflectionMethod('DishNetTools', 'phoneMatches');
    $m->setAccessible(true);
    $d = (string)preg_replace('/[^0-9]/', '', $incoming);
    $needle = strlen($d) >= 9 ? substr($d, -9) : $d;
    return (bool)$m->invoke($t, (string)preg_replace('/[^0-9]/', '', $stored), $d, $needle, $legacy);
}

$T = toolsWith([]);

echo "\nThe same subscriber, however the number is written\n";
foreach ([
    ['+256758123456', '256758123456',  'with and without the plus'],
    ['+256758123456', '+256 758 123 456', 'spaced'],
    ['+256758123456', '0758123456',    'local trunk-zero form'],
    ['0758123456',    '+256758123456', 'the other way round'],
    ['758123456',     '+256758123456', 'bare subscriber number vs full'],
] as [$stored, $incoming, $what]) {
    is_(matches($T, $stored, $incoming), "matches: {$what}");
}

echo "\nA different country is a different person\n";
// The defect, stated as a test. Both numbers are real-shaped, both state a
// country code, and they share all nine significant digits.
is_(!matches($T, '+211758123456', '+256758123456'),
    'a +211 client is NOT matched by a +256 caller');
is_(!matches($T, '+256758123456', '+211758123456'),
    'nor the reverse');
is_(!matches($T, '+254758123456', '+256758123456'),
    'nor a neighbouring code that differs by one digit');

echo "\nAnd a fragment identifies nobody\n";
foreach (['12345678', '1234', '', '0'] as $frag) {
    is_(!matches($T, '+256758123456', $frag), "an incoming fragment \"{$frag}\" matches nothing");
    is_(!matches($T, $frag, '+256758123456'), "a stored fragment \"{$frag}\" matches nothing");
}

echo "\nThe rule is CustomerIdentity's, not a second copy of it\n";
// If these ever disagree, two paths are deciding identity differently again.
foreach ([
    ['+256758123456', '+256758123456'], ['+256758123456', '0758123456'],
    ['+211758123456', '+256758123456'], ['+256700000001', '+256700000002'],
    ['758123456', '758123456'],         ['12345', '+256758123456'],
] as [$a, $b]) {
    t('agrees with CustomerIdentity::same on ' . $a . ' vs ' . $b,
      matches($T, $a, $b), CustomerIdentity::same($a, $b));
}
$code = '';
foreach (token_get_all((string)file_get_contents($root . '/lib/DishNetTools.php')) as $k) {
    if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $k[1]; }
    else $code .= $k;
}
$s = strpos($code, 'function phoneMatches');
$body = substr($code, $s, strpos($code, 'function endsWith', $s) - $s);
is_(strpos($body, 'CustomerIdentity::same') !== false, 'phoneMatches delegates to CustomerIdentity');
is_(strpos($body, 'MIN_PHONE_MATCH_DIGITS') === false,
    'and no longer carries its own trailing-digit comparison');

echo "\nThe legacy escape hatch still works, and is still opt-in\n";
$L = toolsWith([], ['tools_legacy_phone_match' => true]);
is_(matches($L, '912345', '+211912345', true), 'legacy mode restores the loose suffix match');
is_(!matches($T, '912345', '+211912345'), 'and the default does not');

echo "\nEnd to end: the wrong customer is never identified\n";
// Two real clients, one Ugandan and one South Sudanese, sharing nine digits —
// the shape that exists in this client base today (5 x +256, 1 x +211).
$mixed = toolsWith([
    ['id' => 7,  'name' => 'Uganda customer',      'phone' => '+256758123456'],
    ['id' => 21, 'name' => 'South Sudan customer', 'phone' => '+211758123456'],
]);
$r = $mixed->identifyCustomerByPhone('+256758123456');
t('the Ugandan caller is identified', !empty($r['data']['found']), true);
t('and as themselves, not the other client',
  (int)($r['data']['customer']['id'] ?? 0), 7);

$r2 = $mixed->identifyCustomerByPhone('+211758123456');
t('the South Sudanese caller is identified', !empty($r2['data']['found']), true);
t('and as themselves', (int)($r2['data']['customer']['id'] ?? 0), 21);

// The case that used to disclose the wrong account: a caller whose number is
// not in the index at all, but whose last nine match somebody who is.
$oneSided = toolsWith([
    ['id' => 21, 'name' => 'South Sudan customer', 'phone' => '+211758123456'],
]);
$r3 = $oneSided->identifyCustomerByPhone('+256758123456');
t('an unknown +256 caller is NOT identified as the +211 client',
  !empty($r3['data']['found']), false);
// Two honest outcomes are possible here and both are safe. With a CRM
// configured the index miss becomes found=false/no_match; without one the
// API fallback cannot run and the whole lookup reports a failure. What must
// never happen is the third thing — found=true naming client 21 — and
// AiReplyWorker treats either honest outcome the same way: it logs, and
// leaves the caller unidentified, so no account block is loaded.
is_(($r3['ok'] === true && ($r3['data']['reason'] ?? '') === 'no_match')
    || ($r3['ok'] === false && $r3['data'] === null),
    'the answer is either "no match" or an honest failure — never a wrong match');
is_(json_encode($r3) !== false && strpos((string)json_encode($r3), '"id":21') === false,
    'and client 21 appears nowhere in the result');

echo "\nGenuine ambiguity is still reported as ambiguity\n";
$dupe = toolsWith([
    ['id' => 7,  'name' => 'One', 'phone' => '+256758123456'],
    ['id' => 9,  'name' => 'Two', 'phone' => '256758123456'],
]);
$r4 = $dupe->identifyCustomerByPhone('+256758123456');
t('two clients on one number are not resolved to either', !empty($r4['data']['found']), false);
t('and the caller is told to ask', $r4['data']['reason'] ?? '', 'ambiguous');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

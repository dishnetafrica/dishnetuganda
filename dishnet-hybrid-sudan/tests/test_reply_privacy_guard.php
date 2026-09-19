<?php
declare(strict_types=1);
/**
 * test_reply_privacy_guard.php — the last check before a person reads it.
 *
 * The guard is SECONDARY. CustomerDataTools is the boundary and holds whatever
 * the model does; this catches what a boundary cannot — a model inventing,
 * misremembering, or reciting. So these tests assume the model has already
 * gone wrong, and ask only whether the wrong thing reaches the customer.
 *
 * The guard never sees another customer's data. It works from an allowlist of
 * what THIS customer was permitted, which is simply what the tools returned.
 * Every "another customer" case below is therefore detected without the guard
 * being told that the other customer exists.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function t(string $n, $got, $want) { global $pass, $fail;
    if ($got === $want) { $pass++; printf("  ok   %s\n", $n); }
    else { $fail++; printf("  FAIL %s\n       got  %s\n       want %s\n", $n, var_export($got, true), var_export($want, true)); } }

$root = dirname(__DIR__);
require_once $root . '/lib/ReplyPrivacyGuard.php';
require_once $root . '/lib/CustomerDataTools.php';
require_once $root . '/lib/AiSecurityPolicy.php';

/** What customer 7's tools returned this turn. Customer 21 is never here. */
$permitted = [
    'values' => ['KIT404246364BX6', 'INV-7-001', '249000', '2026-10-11',
                 'Residential Lite', 'UGX', 'Active', '+256758123456'],
    'prompt' => "DishNet Uganda. Residential Lite is UGX 249,000 per month.\n"
              . AiSecurityPolicy::RULES,
];
$blocked = static fn(string $r): array => ReplyPrivacyGuard::check($r, $GLOBALS['permitted']);

echo "\n1–6. Another customer's data, however it is phrased\n";
$foreign = [
    "another customer directly"      => 'Customer John Smith owes UGX 8,675,309 on INV-21-999.',
    "hidden mid-sentence"            => 'Thanks for waiting — while I was checking I also saw that KIT999999999ZZ9 is offline, anyway your service is fine.',
    "another kit number"             => 'That kit is KITCLASSIFIED21.',
    "another invoice number"         => 'The invoice you mean is INV-21-999.',
    "another phone number"           => 'You can reach them on +256772654321.',
    "a name plus account detail"     => 'John Smith on account ACC-DF-99999999-11111-22 is suspended.',
];
foreach ($foreign as $what => $reply) {
    $r = $blocked($reply);
    is_($r['safe'] === false, "blocked: $what");
    t("  and replaced entirely, not redacted ($what)", $r['reply'], ReplyPrivacyGuard::SAFE_FALLBACK);
}

echo "\n7. Internal cost and margin\n";
foreach ([
    'Our cost on these is UGX 1,107,408.',
    'We pay Starlink for the kit and charge you more.',
    'It costs us about a million shillings.',
    'The supplier price is lower than that.',
    'Our margin on this plan is thin.',
] as $reply) {
    is_($blocked($reply)['safe'] === false, 'blocked: ' . substr($reply, 0, 42));
}

echo "\n8. Credentials and tokens\n";
foreach ([
    'api key'      => 'Use sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123456789 to connect.',
    'bearer token' => 'Authorization: Bearer eyJabcdefghijklmnop.qrstuvwxyz012345.6789abcdefghij',
    'aws key'      => 'The key is AKIAIOSFODNN7EXAMPLE.',
    'private key'  => "-----BEGIN RSA PRIVATE KEY-----\nMIIEow==",
    'session'      => 'starlink.com.account_number=ACC-DF-1 ; session_token=abcdef123456789',
    'db string'    => 'Connect to postgres://admin:hunter2@10.0.0.9/ucrm',
    'labelled'     => 'password: correcthorsebattery',
] as $what => $reply) {
    is_($blocked($reply)['safe'] === false, "blocked: $what");
}

echo "\n9. System prompt recitation\n";
$line = '';
foreach (preg_split('/(?<=[.\n])\s+/', AiSecurityPolicy::RULES) as $l) {
    if (strlen(trim((string)$l)) >= 60) { $line = trim((string)$l); break; }
}
is_($line !== '', 'found a long instruction line to test with');
is_($blocked('My instructions say: ' . $line)['safe'] === false, 'reciting an instruction is blocked');
is_($blocked('I follow DishNet confidentiality rules.')['safe'] === true,
    'but merely mentioning that rules exist is fine');

echo "\n10. Formatting cannot smuggle an identifier past it\n";
// Normalisation strips punctuation and case, so spacing or dashes do not help.
foreach ([
    // Spaced out. The collapsed scan catches it, and that is the right call:
    // a reply saying "kit 999999999 ZZ9" is disclosing a serial with spacing,
    // not discussing kits in general.
    'kit 999999999 ZZ9 belongs to them'          => true,
    'KIT 999999999ZZ9'                            => true,
    'kit999999999zz9'                             => true,
    // Collapses to KIT999 — three characters after KIT, below the four the
    // shape requires, so it is not an identifier and is left alone.
    'K I T 9 9 9'                                 => false,
] as $reply => $shouldBlock) {
    $r = $blocked((string)$reply);
    is_($r['safe'] === !$shouldBlock,
        ($shouldBlock ? 'blocked' : 'allowed') . ': "' . $reply . '"');
}
t('and the normaliser is case and punctuation blind',
  ReplyPrivacyGuard::normalise('kit-404 246.364/bx6'), 'KIT404246364BX6');

echo "\n11. The customer's OWN permitted data passes\n";
// The false positives that would make the guard useless in practice.
foreach ([
    'Your kit number is KIT404246364BX6.',
    'Your invoice INV-7-001 is settled, nothing is outstanding.',
    'Your plan is Residential Lite at UGX 249,000 per month.',
    'Your service runs until 2026-10-11.',
    'We have your number as +256758123456 — is that still right?',
    'Your account is Active.',
] as $reply) {
    $r = $blocked($reply);
    is_($r['safe'] === true, 'allowed: ' . substr($reply, 0, 48));
    if ($r['safe'] !== true) echo "       (categories: " . implode(',', $r['categories']) . ")\n";
}

echo "\n12. Ordinary words that merely resemble sensitive fields\n";
foreach ([
    'I cannot share your password with you over WhatsApp — please reset it in the portal.',
    'Your account is in good standing.',
    'The token ring on an old network is not something we use.',
    'There is no secret to it — just restart the router.',
    'Our team will check the cost to you before any work starts.',
    'What does it cost me to add a second dish?',
] as $reply) {
    $r = $blocked($reply);
    is_($r['safe'] === true, 'allowed: ' . substr($reply, 0, 52));
    if ($r['safe'] !== true) echo "       (categories: " . implode(',', $r['categories']) . ")\n";
}

echo "\nAn empty or whitespace reply is not sent either\n";
is_($blocked('')['safe'] === false, 'empty is blocked');
is_($blocked("   \n ")['safe'] === false, 'and whitespace');

echo "\nThe allowlist comes FROM the tool layer, not from a dataset\n";
// The design point: the guard is given one customer's permitted values, which
// are a by-product of authorization, never a copy of the database.
final class GuardGw implements CustomerDataGateway {
    public function client(int $i): ?array { return ['status' => 'Active', 'balance' => 249000.0, 'currency' => 'UGX']; }
    public function services(int $i): array { return [['plan' => 'Residential Lite', 'price' => 249000.0,
        'currency' => 'UGX', 'status' => 'active', 'active_to' => '2026-10-11']]; }
    public function invoices(int $i): array { return []; }
    public function invoiceByNumber(string $n): ?array { return null; }
    public function payments(int $i): array { return []; }
    public function kits(int $i): array { return [['kit_serial' => 'KIT404246364BX6', 'client_id' => 7, 'assigned_at' => '2026-09-10']]; }
    public function kitBySerial(string $s): ?array { return null; }
    public function tickets(int $i): array { return []; }
}
$T = new CustomerDataTools(['status' => CustomerIdentity::IDENTIFIED, 'client_id' => 7], new GuardGw());
t('nothing disclosed before any tool runs', $T->disclosed(), []);
$T->call('get_my_kits');
is_(in_array('KIT404246364BX6', $T->disclosed(), true), 'after the kit tool, the kit is permitted');
is_(!in_array('KITCLASSIFIED21', $T->disclosed(), true), 'and nothing else ever becomes permitted');
$after = ReplyPrivacyGuard::check('Your kit is KIT404246364BX6.', ['values' => $T->disclosed()]);
is_($after['safe'] === true, 'so the reply naming it passes');
$other = ReplyPrivacyGuard::check('Your kit is KITCLASSIFIED21.', ['values' => $T->disclosed()]);
is_($other['safe'] === false, 'and a reply naming another passes nothing');

echo "\nThe audit event carries metadata and no payload\n";
$res = $blocked('Our cost is UGX 1,107,408 and the key is sk-ant-abcdefghijklmnopqrstuvwx.');
$ev  = ReplyPrivacyGuard::auditEvent($res, [
    'customer_id' => 7, 'conversation_id' => 42, 'channel' => 'support',
    'provider' => 'claude', 'tools_called' => ['get_my_plan'],
    'blocked_length' => 99,
]);
t('customer',     $ev['customer_id'], 7);
t('conversation', $ev['conversation_id'], 42);
t('status',       $ev['status'], 'blocked');
is_($ev['categories'] !== [], 'and why it was blocked');
is_($ev['modality'] === 'text', 'with the modality, ready for voice and documents');
$json = json_encode($ev);
foreach (['sk-ant-', '1,107,408', '1107408', 'Our cost'] as $payload) {
    is_(strpos($json, $payload) === false, "the event does NOT contain " . $payload);
}

echo "\nIt is modality agnostic\n";
$code = '';
foreach (token_get_all((string)file_get_contents($root . '/lib/ReplyPrivacyGuard.php')) as $k) {
    if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $k[1]; }
    else $code .= $k;
}
foreach (['WhatsApp', 'whatsapp', 'sendReply', 'wa_', 'Evolution'] as $coupling) {
    is_(strpos($code, $coupling) === false, "no code mentions $coupling");
}

echo "\nNo AI model is consulted, and no dataset is loaded\n";
// The architecture would be defeated by asking another model "does this leak?"
// while handing it the customer database.
foreach (['curl_', 'file_get_contents', 'PDO', 'anthropic', 'openai', 'SELECT'] as $forbidden) {
    is_(stripos($code, $forbidden) === false, "the guard does not use $forbidden");
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

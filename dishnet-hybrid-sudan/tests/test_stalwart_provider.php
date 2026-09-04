<?php
require_once dirname(__DIR__) . '/lib/MailProviderInterface.php';
require_once dirname(__DIR__) . '/lib/StalwartProvider.php';

$pass=0; $fail=0;
function t(string $n, $got, $want){ global $pass,$fail;
  if ($got===$want){$pass++;printf("  ok   %s\n",$n);}
  else{$fail++;printf("  FAIL %s\n       got  %s\n       want %s\n",$n,var_export($got,true),var_export($want,true));}}

$CFG = ['stalwart_api_url' => 'https://mail.dishnetuganda.com', 'stalwart_api_token' => 'sekret-token-123'];

echo "Fail-closed configuration\n";
$p = new StalwartProvider([]);
t('unconfigured is not configured', $p->isConfigured(), false);
t('unconfigured create fails closed', $p->ensureMailbox('a@b.c', 'A')['ok'], false);
$pHttp = new StalwartProvider(['stalwart_api_url' => 'http://mail.dishnetuganda.com', 'stalwart_api_token' => 'x']);
t('plain http is refused (token would travel in clear)', $pHttp->isConfigured(), false);

echo "\nRequest shaping\n";
$calls = [];
$http = function ($method, $url, $headers, $body) use (&$calls, &$nextResponses) {
    $calls[] = ['m' => $method, 'u' => $url, 'h' => $headers, 'b' => $body];
    return array_shift($nextResponses) ?? [200, []];
};

$nextResponses = [[404, null], [201, []]];          // GET miss → POST created
$calls = [];
$p = new StalwartProvider($CFG, $http);
$r = $p->ensureMailbox('john.doe@dishnetuganda.com', 'John Doe', 250);
t('create succeeds', $r['ok'], true);
t('checks existence first', $calls[0]['m'] . ' ' . $calls[0]['u'],
  'GET https://mail.dishnetuganda.com/api/principal/john.doe');
t('then POSTs the principal', $calls[1]['m'], 'POST');
t('principal is an individual', $calls[1]['b']['type'], 'individual');
t('address attached', $calls[1]['b']['emails'], ['john.doe@dishnetuganda.com']);
t('quota converted to bytes', $calls[1]['b']['quota'], 250 * 1024 * 1024);
t('a discarded random secret is set', strlen($calls[1]['b']['secrets'][0]) >= 32, true);
t('bearer token in header', in_array('Authorization: Bearer sekret-token-123', $calls[0]['h'], true), true);

$nextResponses = [[200, []]];                        // already exists
$calls = [];
$r = $p->ensureMailbox('john.doe@dishnetuganda.com', 'John Doe');
t('existing principal is success (idempotent)', $r['ok'], true);
t('…and no POST is attempted', count($calls), 1);

$nextResponses = [[404, null], [409, []]];           // lost a create race
$r = $p->ensureMailbox('john.doe@dishnetuganda.com', 'John Doe');
t('409 on create is treated as success', $r['ok'], true);

echo "\nSecrets hygiene\n";
$nextResponses = [[404, null], [500, ['error' => 'boom']]];
$r = $p->ensureMailbox('x@dishnetuganda.com', 'X');
t('server error surfaces', $r['ok'], false);
t('error names the HTTP code', str_contains($r['error'], '500'), true);
t('error NEVER contains the token', str_contains($r['error'], 'sekret-token-123'), false);

echo "\nLifecycle calls\n";
$nextResponses = [[200, []]];
$calls = [];
$r = $p->suspendMailbox('john.doe@dishnetuganda.com');
t('suspend ok', $r['ok'], true);
t('suspend PATCHes the principal', $calls[0]['m'], 'PATCH');
$fields = array_column($calls[0]['b'], 'field');
t('suspend clears login secrets', in_array('secrets', $fields, true), true);

$nextResponses = [[200, []]];
$calls = [];
$r = $p->resetPassword('john.doe@dishnetuganda.com');
t('reset ok', $r['ok'], true);
t('reset returns the one-time password', is_string($r['data']) && strlen($r['data']) === 14, true);
t('password is WhatsApp-relayable (no ambiguous chars)', preg_match('/[0OIl1]/', $r['data']), 0);

echo "\nPath override\n";
$nextResponses = [[200, []]];
$calls = [];
$p2 = new StalwartProvider($CFG + ['stalwart_principal_path' => '/api/v2/accounts'], $http);
$p2->ensureMailbox('a@dishnetuganda.com', 'A');
t('principal path is configurable', str_contains($calls[0]['u'], '/api/v2/accounts/'), true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);

<?php
/**
 * test_crm_public_url.php — the port a customer's browser can actually open.
 *
 * uCRM's own Plugins page reported this plugin's public URL as
 *
 *   https://crm.dishnetuganda.com:8443/crm/_plugins/.../public.php
 *
 * while the CRM itself answers on 443. 8443 is what the reverse proxy
 * forwards TO — an internal port, published as though it were public.
 *
 * This is not only a broken click. dn_plugin_public() builds the PDF links
 * that webhook.php and cron_maintenance.php send to customers on WhatsApp,
 * and the Evolution webhook URL. A customer tapping an invoice link opens a
 * port that is not there.
 *
 * uCRM is the thing to fix, and it is not always ours to fix today. So the
 * plugin takes an override: set crm_public_url to the address a CUSTOMER's
 * browser uses, and every generated link is rebuilt on it — scheme, host and
 * port replaced, paths untouched.
 *
 * The property that matters most is the one about OTHER installs: with no
 * override set, every helper must resolve exactly as it did before this
 * existed. Sudan must not notice.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/crm_url.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   $m\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }
function eq(string $got, string $want, string $m): void { is_($got === $want, $m, 'got: ' . $got . "\n       want: " . $want); }

// The real reported value, verbatim from the uCRM Plugins page.
$BAD = 'https://crm.dishnetuganda.com:8443/crm/_plugins/dishnet-hybrid-sudan/public.php';
$GOOD = 'https://crm.dishnetuganda.com';

echo "\nAn override is only honoured when it is usable\n";

is_(dn_public_override(null) === '', 'no config, no override');
is_(dn_public_override([]) === '', 'no key, no override');
is_(dn_public_override(['crm_public_url' => '']) === '', 'empty, no override');
is_(dn_public_override(['crm_public_url' => '   ']) === '', 'blank, no override');
is_(dn_public_override(['crm_public_url' => 'crm.dishnetuganda.com']) === '',
    'a bare hostname is refused',
    'without a scheme it cannot be a link; falling back beats emitting nonsense');
is_(dn_public_override(['crm_public_url' => 'ftp://crm.dishnetuganda.com']) === '',
    'a non-http scheme is refused');
is_(dn_public_override(['crm_public_url' => 'https://']) === '', 'a URL with no host is refused');

eq(dn_public_override(['crm_public_url' => 'https://crm.dishnetuganda.com/']), $GOOD,
   'a good one is normalised, trailing slash dropped');
eq(dn_public_override(['crm_public_url' => 'https://crm.dishnetuganda.com:443']), $GOOD,
   'an explicit :443 on https is dropped — it is the default');
eq(dn_public_override(['crm_public_url' => 'http://box.local:80']), 'http://box.local',
   'and :80 on http likewise');
eq(dn_public_override(['crm_public_url' => 'https://crm.dishnetuganda.com:8443']),
   'https://crm.dishnetuganda.com:8443',
   'a non-default port is KEPT',
   'an operator who deliberately publishes a port must be obeyed');

echo "\nThe override replaces scheme, host and port — and nothing else\n";

$cfg = ['crm_public_url' => $GOOD];
eq(dn_with_override($BAD, $cfg),
   'https://crm.dishnetuganda.com/crm/_plugins/dishnet-hybrid-sudan/public.php',
   'the bad port is replaced and the long path survives intact',
   'rebuilding the path instead of reusing it is how a link silently changes meaning');

eq(dn_with_override('https://old:8443/a/b?x=1&y=2', $cfg),
   'https://crm.dishnetuganda.com/a/b?x=1&y=2', 'the query string survives');
eq(dn_with_override('https://old:8443/a#frag', $cfg),
   'https://crm.dishnetuganda.com/a#frag', 'and the fragment');
eq(dn_with_override($BAD, null), $BAD, 'with no config the URL is untouched');
eq(dn_with_override('', $cfg), '', 'an empty URL stays empty');
eq(dn_with_override($BAD, ['crm_public_url' => 'nonsense']), $BAD,
   'an unusable override changes nothing',
   'a typo must not rewrite links to somewhere that does not exist');

echo "\nEvery generated link follows it\n";

eq(dn_crm_web($cfg), $GOOD, 'the CRM home');
eq(dn_crm_link($cfg, 'client/1'), $GOOD . '/crm/client/1', 'a link into the CRM UI');
is_(strpos(dn_plugin_public($cfg), ':8443') === false,
    'the plugin public URL carries no stray port',
    'this one becomes the PDF link a customer taps on WhatsApp');
is_(strpos(dn_plugin_file($cfg, 'evo_webhook.php'), ':8443') === false,
    'and neither does the Evolution webhook URL');
is_(substr(dn_plugin_file($cfg, 'evo_webhook.php'), -strlen('/evo_webhook.php')) === '/evo_webhook.php',
    'which still ends at the right file');

echo "\nWithout an override, nothing changes for anyone\n";

// This is the Sudan guarantee: the resolution below is the code that existed
// before the override, exercised with no override present.
foreach ([null, [], ['crm_base_url' => 'https://crm.dishnetafrica.com/crm']] as $i => $c) {
    $web = dn_crm_web($c);
    is_(strpos($web, ':8443') === false, 'case ' . $i . ': no port is invented');
}
eq(dn_crm_web(['crm_base_url' => 'https://crm.dishnetafrica.com/crm']),
   'https://crm.dishnetafrica.com',
   'crm_base_url still has its /crm stripped');
eq(dn_crm_web(['crm_base_url' => 'https://crm.dishnetafrica.com/api/v1.0']),
   'https://crm.dishnetafrica.com',
   'and its /api/vX.Y');
eq(dn_crm_web(['crm_base_url' => 'https://crm.dishnetafrica.com:8443/crm']),
   'https://crm.dishnetafrica.com:8443',
   'a port in crm_base_url is preserved, not silently removed',
   'only an explicit override may change a port; guessing is how an install breaks');

echo "\nThe tool is honest about what it does and does not fix\n";

$tool = (string)file_get_contents($root . '/tools/crm_url_check.php');
is_(strpos($tool, 'Settings -> System -> Application') !== false,
    'it names the real fix in uCRM',
    'the override is a workaround; a tool that hides that leaves the redirect broken');
is_(strpos($tool, 'NOT stop uCRM redirecting') !== false,
    'and says plainly that it does not stop the redirect');
is_(strpos($tool, 'cliDataDir(') !== false, 'and resolves its data directory through the guard');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

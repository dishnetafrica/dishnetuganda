<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * crm_url_check.php — the address customers are actually sent to.
 *
 *   php tools/crm_url_check.php                  report
 *   php tools/crm_url_check.php --set <url>      set the override
 *   php tools/crm_url_check.php --clear          remove it
 *
 * Clicking https://crm.dishnetuganda.com/crm landed on port 8443. uCRM
 * builds its redirects and links from the address it was CONFIGURED with,
 * and behind a reverse proxy that is often the INTERNAL one — 8443 is what
 * the proxy forwards to, not something a browser can open.
 *
 * That address is not only a redirect. uCRM writes it into ucrm.json, this
 * plugin reads it, and it becomes every "View in CRM" link, every portal
 * link and every URL in a quote email. One wrong port, published in many
 * places, reaching customers.
 *
 * The real fix is in uCRM: Settings -> System -> Application, server domain
 * and port as a CUSTOMER's browser sees them. This tool finds the problem,
 * and --set works around it until that is done.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/crm_url.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);
$argv    = $argv ?? [];

/** Everything a generated link is built from, in one place. */
function dn_probe(string $url): array
{
    $u = parse_url($url);
    if (!is_array($u) || empty($u['host'])) return ['ok' => false, 'why' => 'not a URL'];
    $scheme = strtolower((string)($u['scheme'] ?? ''));
    $port   = isset($u['port']) ? (int)$u['port'] : ($scheme === 'https' ? 443 : 80);
    $normal = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    return ['ok' => true, 'scheme' => $scheme, 'host' => $u['host'],
            'port' => $port, 'normal' => $normal, 'explicit' => isset($u['port'])];
}

// ── --set / --clear ─────────────────────────────────────────────────────────
$setAt = array_search('--set', $argv, true);
$clear = in_array('--clear', $argv, true);
if ($setAt !== false || $clear) {
    $val = $clear ? '' : trim((string)($argv[$setAt + 1] ?? ''));
    if (!$clear) {
        $p = dn_probe($val);
        if (!$p['ok'] || ($p['scheme'] !== 'http' && $p['scheme'] !== 'https')) {
            echo "\n  \"" . $val . "\" is not an absolute http(s) URL.\n";
            echo "  Give the address a customer's browser uses, e.g.\n";
            echo "    php tools/crm_url_check.php --set https://crm.dishnetuganda.com\n\n";
            exit(1);
        }
    }
    $file = rtrim($dataDir, '/') . '/config.json';
    $cur  = is_file($file) ? (array)json_decode((string)@file_get_contents($file), true) : [];
    if ($val === '') { unset($cur['crm_public_url']); } else { $cur['crm_public_url'] = $val; }
    if (@file_put_contents($file, json_encode($cur, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        echo "\n  Could not write " . $file . "\n  Check it is writable by this user.\n\n";
        exit(1);
    }
    echo "\n  " . ($val === '' ? 'Override removed — links follow uCRM again.'
                               : 'Links will now be built on ' . $val) . "\n";
    echo "  Re-run with no arguments to see the result.\n\n";
    exit(0);
}

// ── Report ──────────────────────────────────────────────────────────────────
$json     = dn_ucrm_json();
$ucrmPub  = trim((string)($json['ucrmPublicUrl'] ?? ''));
$ucrmLoc  = trim((string)($json['ucrmLocalUrl'] ?? ''));
$plugPub  = trim((string)($json['pluginPublicUrl'] ?? ''));
$override = trim((string)($config['crm_public_url'] ?? ''));

echo "\n  WHAT uCRM REPORTS\n\n";
printf("    %-18s %s\n", 'ucrmPublicUrl', $ucrmPub !== '' ? $ucrmPub : '(not set)');
printf("    %-18s %s\n", 'ucrmLocalUrl',  $ucrmLoc !== '' ? $ucrmLoc : '(not set)');
printf("    %-18s %s\n", 'pluginPublicUrl', $plugPub !== '' ? $plugPub : '(not set)');

$problems = [];
$p = $ucrmPub !== '' ? dn_probe($ucrmPub) : ['ok' => false];
if ($p['ok'] && !$p['normal']) {
    echo "\n    ↑ port " . $p['port'] . " is not the standard port for " . $p['scheme'] . ".\n";
    echo "      Behind a reverse proxy that is usually the INTERNAL port: what the\n";
    echo "      proxy forwards to, not what a browser can open. Every link below\n";
    echo "      carries it, including the ones that reach customers.\n";
    $problems[] = 'uCRM publishes port ' . $p['port'] . ', which a browser outside cannot open';
}

echo "\n  WHAT THIS PLUGIN GENERATES\n\n";
printf("    %-18s %s\n", 'override',  $override !== '' ? $override : '(none — following uCRM)');
printf("    %-18s %s\n", 'CRM home',  dn_crm_web($config) ?: '(nothing — no URL to build on)');
printf("    %-18s %s\n", 'a client',  dn_crm_link($config, 'client/1'));
printf("    %-18s %s\n", 'public.php', dn_plugin_public($config));
if ($override !== '' && dn_public_override($config) === '') {
    echo "\n    ↑ the override is set but unusable, so it is being IGNORED.\n";
    echo "      It must be an absolute http(s) URL, e.g. https://crm.dishnetuganda.com\n";
    $problems[] = 'crm_public_url is set to something unusable and is being ignored';
}

// ── Does the public address actually answer? ────────────────────────────────
$test = dn_crm_web($config);
if ($test !== '' && function_exists('curl_init')) {
    echo "\n  WHAT THAT ADDRESS ANSWERS\n\n";
    echo "    Asked from INSIDE the uCRM container. That request may never leave\n";
    echo "    the host, so it can reach the internal server directly and see a\n";
    echo "    redirect a customer's browser would not. Evidence about the server,\n";
    echo "    not proof about the outside world — confirm from a phone on mobile\n";
    echo "    data before concluding either way.\n\n";
    foreach ([$test . '/crm' => 'the CRM'] as $url => $label) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($code === 0) {
            printf("    %-46s unreachable: %s\n", $url, $err !== '' ? $err : 'no response');
            $problems[] = $url . ' does not answer from this server';
            continue;
        }
        printf("    %-46s %d%s\n", $url, $code, $loc !== '' ? '  ->  ' . $loc : '');
        if ($loc !== '') {
            $lp = dn_probe($loc);
            if ($lp['ok'] && !$lp['normal']) {
                echo "      ↑ redirects to port " . $lp['port'] . ": the link is right, the\n";
                echo "        server sends the browser elsewhere.\n";
                if ($code === 301) {
                    echo "        A 301 is PERMANENT — browsers cache it. Once the server is\n";
                    echo "        fixed, anyone who hit this keeps being redirected until they\n";
                    echo "        clear it. Re-test in a private window.\n";
                }
                $problems[] = 'the CRM redirects to port ' . $lp['port']
                            . ' (seen from inside the container)';
            }
        }
    }
}

echo "\n";
if (!$problems) {
    echo "  Every generated link uses a standard port and the CRM answers on it.\n\n";
    exit(0);
}
echo "  NEEDS FIXING\n\n";
foreach ($problems as $x) echo "    - " . $x . "\n";
echo "\n  The real fix is in whatever redirects, and it is not this plugin:\n\n";
echo "    uCRM    Settings -> System -> Application\n";
echo "              Server domain name   the host a customer types\n";
echo "              Server port          the port their browser opens (443 for https)\n\n";
echo "    UISP    if uCRM already shows the right values and the redirect stays,\n";
echo "            the port is UISP's. It is chosen at install and is NOT in the\n";
echo "            settings UI, so look at the install config and the container:\n\n";
echo "              grep -riE port /home/unms/app/unms.conf\n";
echo "              docker inspect unms --format \'{{range .Config.Env}}{{println .}}{{end}}\' | grep -i port\n\n";
echo "  Until that is done, this makes the plugin's own links correct — it does\n";
echo "  NOT stop uCRM redirecting, so fix the setting too:\n\n";
echo "    php tools/crm_url_check.php --set https://" . (dn_probe($ucrmPub)['host'] ?? 'crm.example.com') . "\n\n";
exit(1);

<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * hardware_check.php — what we claim about the dishes, and how old it is.
 *
 *   php tools/hardware_check.php            what is recorded, and its age
 *   php tools/hardware_check.php --stale    only what needs re-checking
 *
 * A specification is not permanent. Starlink changes hardware generations, and
 * the assistant quotes what is in this file, so somebody has to be able to see
 * at a glance which figures are still trustworthy and which are old enough
 * that it is now hedging instead of answering.
 *
 * Exit 0 everything current · 1 something is stale or unverified.
 * Read-only; it never writes to the file.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/HardwareKnowledge.php';

$file  = $root . '/tools/starlink_hardware.json';
$only  = in_array('--stale', $argv, true);
$kb    = HardwareKnowledge::load($file);
$stale = max(1, $kb['stale_after_days']);
$now   = time();

if (!$kb['models']) { echo "\n  No hardware recorded in " . $file . "\n\n"; exit(1); }

// Fields the assistant is asked about constantly. A null here is not a bug —
// it is honest — but it is a gap somebody should close, so it is listed.
$WANT = ['wifi' => 'Wi-Fi', 'ethernet' => 'Ethernet', 'power_avg_w' => 'Power',
         'coverage_m2' => 'Coverage'];

printf("\n  STARLINK HARDWARE — stale after %d days\n\n", $stale);
$bad = 0;
foreach ($kb['models'] as $m) {
    $name = (string)($m['model'] ?? '?');
    $v    = (string)($m['verified_on'] ?? '');
    $age  = $v !== '' ? (int)floor(($now - (strtotime($v) ?: $now)) / 86400) : null;
    $old  = $age === null || $age > $stale;

    $gaps = [];
    foreach ($WANT as $k => $label) {
        if (($m[$k] ?? null) === null || ($m[$k] ?? '') === '') $gaps[] = $label;
    }
    if ($old || $gaps) $bad++;
    if ($only && !$old && !$gaps) continue;

    printf("  %-28s %s\n", $name, $old ? '⚠ NEEDS RE-CHECKING' : 'current');
    printf("      verified   %s%s\n", $v !== '' ? $v : 'never',
           $age !== null ? "  ({$age} days ago)" : '');
    printf("      source     %s\n", (string)($m['source'] ?? '—'));
    if ($gaps) {
        printf("      not verified: %s\n", implode(', ', $gaps));
        echo   "                    the assistant says so rather than guessing — but these\n";
        echo   "                    are the questions customers ask most.\n";
    }
    echo "\n";
}

if ($bad) {
    printf("  %d model(s) need attention. Check starlink.com, then update\n", $bad);
    echo   "  tools/starlink_hardware.json and move verified_on forward.\n\n";
    exit(1);
}
echo "  Everything recorded is current.\n\n";
exit(0);

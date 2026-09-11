<?php
declare(strict_types=1);
chdir(dirname(__DIR__));

/**
 * set_billing_model.php — declare whether this install bills postpaid or
 * prepaid, and switch off the machinery that only makes sense postpaid.
 *
 *   php tools/set_billing_model.php --show
 *   php tools/set_billing_model.php --prepaid      (Uganda)
 *   php tools/set_billing_model.php --postpaid     (Sudan — the default)
 *
 * Postpaid: the customer uses the service, an invoice falls due, and the
 * 9-stage overdue ladder chases the debt for up to 210 days while the line
 * stays suspended. That is the Sudan install, and it is the default whenever
 * this key is absent, so nothing changes there unless someone changes it.
 *
 * Prepaid: the customer buys a period up front. When it ends the service
 * PAUSES. Nothing is owed, nothing is overdue, nothing was suspended for
 * non-payment, and there is no reconnection fee — so the ladder's wording is
 * false at every stage and it refuses to run.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/PluginConfig.php';
require_once $root . '/lib/OverdueDunningHelpers.php';

$dataDir = cliDataDir($root);
$config  = PluginConfig::load($root, $dataDir);

function report(array $config): void
{
    $model = _dunningBillingModel($config);
    $why   = _dunningBlockedReason($config);
    echo "  billing_model            {$model}"
       . (isset($config['billing_model']) ? "\n" : "   (not set — the default)\n");
    echo "  overdue ladder           " . ($why === '' ? "ENABLED — it can email customers\n"
                                                      : "BLOCKED\n");
    if ($why !== '') echo "                           {$why}\n";
    echo "  paused / resumed emails  always available (CustomerEmails)\n";
}

if (in_array('--show', $argv, true) || count($argv) === 1) {
    echo "\nBilling model for this install:\n\n";
    report($config);
    echo "\nChange it with --prepaid or --postpaid.\n";
    exit(0);
}

$want = in_array('--prepaid', $argv, true) ? 'prepaid'
      : (in_array('--postpaid', $argv, true) ? 'postpaid' : '');
if ($want === '') {
    fwrite(STDERR, "Nothing to do. Use --show, --prepaid or --postpaid\n");
    exit(1);
}

echo "\nBefore:\n\n"; report($config);

list($ok, $err) = PluginConfig::saveOverrides($dataDir, ['billing_model' => $want]);
if (!$ok) { fwrite(STDERR, "FAILED: {$err}\n"); exit(1); }

// Re-read from disk rather than trusting the in-memory array — this is the
// value the cron will actually see on its next run.
$fresh = PluginConfig::load($root, $dataDir);
echo "\nAfter (read back from {$dataDir}/kyc_config.json):\n\n";
echo "  billing_model            " . (string)($fresh['billing_model'] ?? '(not set)') . "\n";
echo "  overdue ladder           "
   . (_dunningBlockedReason($fresh) === '' ? "ENABLED — it can email customers\n" : "BLOCKED\n");

if ($want === 'prepaid') {
    echo "\nThe weekly overdue cron and the workbench bulk-send will now refuse,\n";
    echo "and say why in their logs. Prepaid customers get the service paused /\n";
    echo "resumed emails instead — inspect them in Admin -> Email Preview.\n";
}
exit(0);

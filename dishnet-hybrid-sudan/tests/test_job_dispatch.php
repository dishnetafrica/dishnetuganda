<?php
declare(strict_types=1);
/**
 * test_job_dispatch.php — the technician actually gets told about the job.
 *
 * cron/job_assignment_notify.php polls uCRM scheduling jobs and WhatsApps the
 * assigned technician an ACCEPT link, then completion links once accepted. It
 * sent through NotificationService, which posts to WASender and opens with
 *
 *     if (!$this->enabled || empty($toPhone)) return;
 *
 * where `enabled` needs wa_plugin_url AND wa_app_key AND wa_auth_key. Uganda
 * runs Evolution, not WASender. On that box every dispatch returned without
 * sending and without logging a failure: no message, no error, no trace. The
 * job existed, the technician did not know.
 *
 * It also printed a South Sudan support number and dishnetafrica.com to
 * whoever received it — so a technician on a Kampala roof who needed help was
 * given a Juba number to ring.
 *
 * These tests read the cron as source rather than executing it: it is a
 * top-level script that polls a live uCRM, so there is no seam to call. What
 * can be pinned is that the send goes through the chooser, that the chooser
 * falls back when Evolution is absent, and that no country is welded in.
 */
$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m" . ($d ? "\n       $d" : '') . "\n"; } }

$root = dirname(__DIR__);
foreach (['StoreInterface','JsonStore','SqliteStore','ContactOptOut',
          'EvolutionApiService','EvoWebhookGuard','CustomerContact','currency'] as $c) {
    require_once $root . '/lib/' . $c . '.php';
}
$src     = file_get_contents($root . '/cron/job_assignment_notify.php');
$summary = file_get_contents($root . '/cron/staff_jobs_summary.php');

echo "\nDispatch goes out on the channel technicians know\n";
is_(strpos($src, "\$JOB_CHAN = 'support'") !== false,
    'job messages are sent on the support channel');
is_(strpos($src, 'sendText($JOB_CHAN') !== false, 'through Evolution sendText');
is_(strpos($src, 'ContactOptOut::CLASS_STAFF') !== false,
    'as CLASS_STAFF — a customer opt-out must never silence a work dispatch');
is_(substr_count($src, '$sendStaff(') === 2,
    'both the assign and the accepted message go through the one chooser',
    substr_count($src, '$sendStaff(') . ' call(s)');
is_(strpos($src, '$notify->sendRaw($phone, $msg, $event);') !== false,
    'and the old WASender path survives as the fallback');

echo "\nAn install with no Evolution behaves exactly as it did\n";
$noEvo = new EvolutionApiService([]);
is_($noEvo->isConfigured() === false, 'no Evolution config means not configured');
is_(strpos($src, '$evoLive  = $evoSvc->isConfigured()') !== false,
    'the cron checks isConfigured() before choosing');
is_(strpos($src, "evo_instance_' . \$JOB_CHAN") !== false,
    'and requires an instance for this specific channel, not just any config');
is_(strpos($src, 'if (!$evoLive) {') !== false, 'falling back when either is missing');
$evo = new EvolutionApiService(['evo_api_url' => 'https://evo.example.invalid',
                                'evo_api_key' => 'k', 'evo_instance_support' => 'ug-support']);
is_($evo->isConfigured() === true, 'a configured install is configured');
is_($evo->instanceFor('support') === 'ug-support', 'and resolves the support instance');

echo "\nThe echo is claimed, or the AI stands down on its own dispatch\n";
is_(strpos($src, '$evoGuard->claim(') !== false, 'the outbound message id is claimed');
$claimPos = strpos($src, '$evoGuard->claim(');
$okPos    = strpos($src, '$ok = empty($res[\'suppressed\'])');
is_($claimPos !== false && $okPos !== false && $claimPos < $okPos,
    'claimed BEFORE the result is even inspected — the echo is a separate request');
is_(strpos($src, "'job.assign'") !== false, 'and tagged so the source is traceable');

echo "\nNo country is welded into a staff message\n";
foreach ([['job_assignment_notify', $src], ['staff_jobs_summary', $summary]] as [$name, $text]) {
    // Strip comments: prose may name a number, code may not.
    $code = preg_replace('#^\s*(//|\*|/\*).*$#m', '', $text);
    is_(strpos($code, '+211') === false, "$name has no South Sudan number");
    is_(strpos($code, 'dishnetafrica.com') === false, "$name has no hardcoded site");
}
is_(strpos($src, 'CustomerContact::support($config)') !== false,
    'the support number comes from config');
is_(strpos($summary, 'CustomerContact::support($config)') !== false,
    'and so does the morning brief');

echo "\nWhich resolves to the right number on each install\n";
is_(CustomerContact::support(CustomerContact::UGANDA) === '+256 705 993 348',
    'Uganda gets +256 705 993 348', CustomerContact::support(CustomerContact::UGANDA));
$ss = CustomerContact::support([]);
is_(strpos($ss, '+211') === 0, 'and South Sudan keeps its own number', $ss);
is_(CustomerContact::support(CustomerContact::UGANDA) !== $ss,
    'the two installs do not share a support line');
is_(strpos(CustomerContact::appUrl(CustomerContact::UGANDA), 'dishnetuganda.com') !== false,
    'and the site link follows the country too');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

<?php
declare(strict_types=1);
/**
 * notify_units_side.php — the small decisions behind Uganda's reminder run, answered by the plugin tree under test (a
 * weakened copy included), so a test can compare them with what they must be. TEST ONLY.
 *
 *   php notify_units_side.php <pluginRoot> <runZone> <dateZone>   → prints {"name": answer, …} as JSON
 *   (the zones come from the calling test: a fixture may not pin one, tests/test_timezone.php)
 *
 *   window.*  JobWindow::due in Africa/Kampala: is the once-a-day job due at this time, given its last run?
 *   quiet.*   InvoiceReminders::quiet: is this hour inside the quiet hours?
 *   date.*    InvoiceReminders::parseDate: uCRM's due date as a local calendar date (Africa/Juba, UTC+2, on purpose:
 *             the zone that differs from uCRM's +0300)
 */
[$_, $root, $runZone, $dateZone] = array_pad($argv, 4, '');
if ($runZone === '' || $dateZone === '') { fwrite(STDERR, "usage: notify_units_side.php <pluginRoot> <runZone> <dateZone>\n"); exit(2); }
require_once $root . '/lib/JobWindow.php';
require_once $root . '/lib/InvoiceReminders.php';

date_default_timezone_set($runZone);
$t = fn(string $s): int => (int)strtotime($s);
$out = [];

// The window 09:00-17:00; "today" is Monday 5 October 2026.
$w = fn(string $now, string $last): bool => JobWindow::due($t($now), $last === '' ? 0 : $t($last), 9, 17);
$out['window.09:00, last ran yesterday 09:05']  = $w('2026-10-05 09:00:00', '2026-10-04 09:05:00');
$out['window.08:59, last ran yesterday']        = $w('2026-10-05 08:59:59', '2026-10-04 09:05:00');
$out['window.17:00, last ran yesterday']        = $w('2026-10-05 17:00:00', '2026-10-04 09:05:00');
$out['window.16:59, missed all morning']        = $w('2026-10-05 16:59:00', '2026-10-04 09:05:00');
$out['window.12:00, ran today at 09:05']        = $w('2026-10-05 12:00:00', '2026-10-05 09:05:00');
$out['window.12:00, never ran']                 = $w('2026-10-05 12:00:00', '');
$out['window.12:00, last run today at 08:30']   = $w('2026-10-05 12:00:00', '2026-10-05 08:30:00');
$out['window.02:00, last ran yesterday']        = $w('2026-10-05 02:00:00', '2026-10-04 09:05:00');

// Quiet hours: the defaults (21:00-08:00), a custom window, switched off, and a setting that makes no sense.
foreach ([21, 23, 0, 7, 8, 12, 20] as $h) $out["quiet.default {$h}h"] = InvoiceReminders::quiet([], $h);
$c = ['notify_quiet_from_hour' => 22, 'notify_quiet_until_hour' => 6];
foreach ([21, 22, 5, 6] as $h) $out["quiet.22-6 {$h}h"] = InvoiceReminders::quiet($c, $h);
$out['quiet.off (0-0) 3h']      = InvoiceReminders::quiet(['notify_quiet_from_hour' => 0, 'notify_quiet_until_hour' => 0], 3);
$out['quiet.invalid (25-8) 3h'] = InvoiceReminders::quiet(['notify_quiet_from_hour' => 25, 'notify_quiet_until_hour' => 8], 3);

// Due dates, read in Africa/Juba.
$juba = new DateTimeZone($dateZone);
$d = function (string $raw) use ($juba) { $x = InvoiceReminders::parseDate($raw, $juba); return $x ? $x->format('Y-m-d H:i T') : null; };
$out['date.+0300 midnight']       = $d('2026-10-12T00:00:00+0300');
$out['date.+03:00 midnight']      = $d('2026-10-12T00:00:00+03:00');
$out['date.UTC late evening']     = $d('2026-10-12T23:30:00+0000');
$out['date.bare date']            = $d('2026-10-12');
$out['date.not a date']           = $d('soon');
$out['date.impossible day']       = $d('2026-02-30');
$today = new DateTimeImmutable('2026-10-05 00:00:00', $juba);
$due   = InvoiceReminders::parseDate('2026-10-12T00:00:00+0300', $juba);
$out['date.days from 5 Oct to a +0300 due date of 12 Oct'] = $due ? (int)$today->diff($due)->format('%r%a') : null;

echo json_encode($out);

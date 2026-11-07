<?php
declare(strict_types=1);
/**
 * Weakened copies for section G of tests/test_voice_media.php (5.18.104, root docs/73 §H.3.24: V17, V18, and the
 * budget's own guards: the cap recorded as applied (ruling R18), settled once, Kampala's day). TEST ONLY.
 */
$VB = 'lib/VoiceBudget.php';
$M = array_merge($M, [
    ['label' => 'V17 the daily budget unchecked', 'file' => $VB,
     'old' => '            if ($minutes === 0 || $used + $seconds > $capDay) {', 'new' => '            if (false) {',
     'sections' => 'G', 'cases' => 'G02', 'pattern' => '/G02 /'],
    ['label' => 'V17b 0 minutes read as no cap', 'file' => $VB,
     'old' => '            if ($minutes === 0 || $used + $seconds > $capDay) {', 'new' => '            if ($minutes > 0 && $used + $seconds > $capDay) {',
     'sections' => 'G', 'cases' => 'G02', 'pattern' => '/G02 0 minutes/'],
    ['label' => 'V18 the per-conversation budget unchecked', 'file' => $VB,
     'old' => '            if (count($notes) >= $convMax && !in_array($mediaId, $notes, true)) {', 'new' => '            if (false) {',
     'sections' => 'G', 'cases' => 'G03', 'pattern' => '/G03 /'],
    ['label' => 'VB-CAP the reservation not recording the cap it applied', 'file' => $VB,
     'old' => '->execute([$mediaId, $convId, $attempt, $model, $day, $seconds, $capDay, $rung]);', 'new' => '->execute([$mediaId, $convId, $attempt, $model, $day, $seconds, 0, $rung]);',
     'sections' => 'G', 'cases' => 'G01', 'pattern' => '/G01 /'],
    ['label' => 'VB-ONCE a settle allowed twice', 'file' => $VB,
     'old' => "settled_at = datetime('now') WHERE id = ? AND outcome = 'started'\");", 'new' => "settled_at = datetime('now') WHERE id = ?\");",
     'sections' => 'G', 'cases' => 'G01', 'pattern' => '/G01 /'],
    ['label' => 'VB-DAY the day computed in the process zone', 'file' => $VB,
     'old' => "->setTimezone(new \\DateTimeZone(self::TZ))->format('Y-m-d');", 'new' => "->format('Y-m-d');",
     'sections' => 'G', 'cases' => 'G01', 'pattern' => '/G01 /'],
    ['label' => 'VB-RUNG the reservation not recording the rung the call was made at', 'file' => 'lib/VoiceTranscription.php',
     'old' => '$res = $budget->reserve($id, $convId, $attempt, $secs, $model, $mode);', 'new' => "\$res = \$budget->reserve(\$id, \$convId, \$attempt, \$secs, \$model, 'reply');",
     'sections' => 'D', 'cases' => 'D01', 'pattern' => '/D01 /'],
]);

<?php
declare(strict_types=1);
/**
 * Weakened copies for section B of tests/test_voice_ack.php (5.18.104, root docs/73 §H.3.24: A6–A9, A18, A19, A30, A31,
 * and the fresh-read ledger of ruling R19). TEST ONLY. The window is guarded twice — P5's step 7 and the ledger's claim
 * — through the one holder, so "the window removed" weakens VoiceAckLimits::inWindow, which both read.
 */
$VA = 'lib/VoiceAck.php';
$WB = 'lib/WaSendBudget.php';
$VL = 'lib/VoiceAckLimits.php';
$M = array_merge($M, [
    ['label' => 'A6 once per period removed', 'file' => $VA,
     'old' => "        if (\$this->ackedWithin(\$convId, \$now - \$period * 3600)) { \$this->skip(\$d, 'already_acked'); return; }", 'new' => '',
     'sections' => 'B', 'cases' => 'B01', 'pattern' => '/B01 /'],
    ['label' => 'A7 the window removed', 'file' => $VL,
     'old' => "        return \$h >= (int)(\$limits['hour_from'] ?? 24) && \$h < (int)(\$limits['hour_to'] ?? 0);", 'new' => '        return true;',
     'sections' => 'B', 'cases' => 'B02', 'pattern' => '/B02 /'],
    ['label' => 'A8 a Sunday refusal added', 'file' => $VL,
     'old' => "        return \$h >= (int)(\$limits['hour_from'] ?? 24) && \$h < (int)(\$limits['hour_to'] ?? 0);",
     'new' => "        if ((new \\DateTime('@' . \$nowUtc))->setTimezone(new \\DateTimeZone(self::TZ))->format('w') === '0') return false;\n        return \$h >= (int)(\$limits['hour_from'] ?? 24) && \$h < (int)(\$limits['hour_to'] ?? 0);",
     'sections' => 'B', 'cases' => 'B03', 'pattern' => '/B03 /'],
    ['label' => 'A9 the window computed in the process zone', 'file' => $VL,
     'old' => "        \$h = (int)(new \\DateTime('@' . \$nowUtc))->setTimezone(new \\DateTimeZone(self::TZ))->format('G');",
     'new' => "        \$h = (int)(new \\DateTime('@' . \$nowUtc))->setTimezone(new \\DateTimeZone(date_default_timezone_get()))->format('G');",
     'sections' => 'B', 'cases' => 'B04', 'pattern' => '/B04 /'],
    ['label' => 'A18 the ceiling removed', 'file' => $WB,
     'old' => "                if ((int)\$st->fetchColumn() >= \$capDay) return \$this->end(self::no('ack_ceiling', null, true));", 'new' => '',
     'sections' => 'B', 'cases' => 'B05', 'pattern' => '/B05 /'],
    ['label' => 'A19 the gap removed', 'file' => $WB,
     'old' => "                \$until = \$last === null || \$last === false ? 0 : (int)\$last + (int)\$pol['gap_s'];", 'new' => '                $until = 0;',
     'sections' => 'B', 'cases' => 'B06', 'pattern' => '/B06 10 s later/'],
    ['label' => 'A30 the ledger\'s window fixed at 07-21, not read through VoiceAckLimits', 'file' => $WB,
     'old' => "                if (\$pol === null || !VoiceAckLimits::inWindow(\$pol, \$now)) return \$this->end(self::no('window', null, true));",
     'new' => "                if (\$pol === null || !VoiceAckLimits::inWindow(VoiceAckLimits::DEFAULTS, \$now)) return \$this->end(self::no('window', null, true));",
     'sections' => 'B', 'cases' => 'B07', 'pattern' => '/B07 the claim/'],
    ['label' => 'A31 limits_invalid ignored', 'file' => $VA,
     'old' => "        if (\$lim === null) { \$this->skip(\$d, 'limits_invalid'); return; }", 'new' => '        if ($lim === null) $lim = VoiceAckLimits::DEFAULTS;',
     'sections' => 'B', 'cases' => 'B09', 'pattern' => '/B09 /'],
    ['label' => 'R19-L the ledger built from the worker\'s start-time configuration', 'file' => $VA,
     'old' => "        \$ledger = WaSendBudget::forInstall(\$fresh, \$this->dataDirOrNull(), \$this->store, null, \$this->now);",
     'new' => "        \$ledger = WaSendBudget::forInstall(\$this->config, \$this->dataDirOrNull(), \$this->store, null, \$this->now);",
     'sections' => 'B', 'cases' => 'B08', 'pattern' => '/B08 /'],
]);

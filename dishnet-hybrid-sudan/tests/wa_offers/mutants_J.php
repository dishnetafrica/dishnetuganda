<?php
declare(strict_types=1);
/**
 * Weakened copies judged by section J (5.18.104, root docs/73 §H.4.16 J, §H.4.20): the gate. TEST ONLY.
 */
$M = array_merge($M, [
    ['label' => 'OM1 OfferSwitch::uganda() returns true', 'file' => 'lib/OfferSwitch.php',
     'old' => "        return StaffJobsGate::applies(\$config, \$dataDir);\n    }", 'new' => "        return true;\n    }",
     'sections' => 'J', 'cases' => 'J0[13]', 'pattern' => '/J0[13] /'],
    // 5.18.104 (root docs/73 §H.4.16 OM4's J half), stage B10: the menu entry drawn without screen() — J06's South Sudan
    // dashboard (both keys on) then draws it, and differs from 221c9dc's
    ['label' => 'OM4 (J) the menu entry drawn without screen()', 'edits' => [['includes/navigation.php', "if (\$_wo104Nav): ?>", "if (true): ?>"]],
     'sections' => 'J', 'cases' => 'J06', 'pattern' => '/J06 south-sudan: the dashboard page|J06 south-sudan: no WhatsApp/'],
]);

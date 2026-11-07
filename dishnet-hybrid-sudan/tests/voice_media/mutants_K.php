<?php
declare(strict_types=1);
/**
 * Weakened copies for section K of tests/test_voice_media.php (5.18.104, root docs/73 §H.3.24 V34): the hand-over's move
 * reordering a statement, or dropping $owner — each caught by comparing behaviour with 221c9dc's escalate. TEST ONLY.
 */
$HO = 'lib/Handover.php';
$alerts = "            if (!class_exists('AlertService')) require_once __DIR__ . '/AlertService.php';\n"
        . "            \$alerts = new \\AlertService(\$store, \$config, \$evo);\n"
        . "            // 5.18.89 (docs/65 §AA, D4): on a salesperson's own number the salesperson is alerted too; with no owner\n"
        . "            // this is the alert it always was — the same key, the same text, the central number.\n"
        . "            \$sent = \$alerts->handover(\$convId, \$phone, \$channel, \$reason, \$owner);\n"
        . "            if (\$owner !== null && !isset(\$sent['owner'])) {\n"
        . "                \$log('warn', \"conv {\$convId}: the line's owner has no phone on record — the central alert number only\");\n"
        . "            }\n";
$M = array_merge($M, [
    ['label' => 'V34a the move reorders a statement (the alert after the holding line)', 'edits' => [
        [$HO, $alerts, ''],
        [$HO, "        } catch (\\Throwable \$e) {\n            \$log('error', 'escalation failed: ' . \$e->getMessage());",
              $alerts . "        } catch (\\Throwable \$e) {\n            \$log('error', 'escalation failed: ' . \$e->getMessage());"]],
     'sections' => 'K', 'pattern' => '/K0[12] the same sends/'],
    ['label' => 'V34b the move drops $owner', 'file' => $HO,
     'old' => '            $sent = $alerts->handover($convId, $phone, $channel, $reason, $owner);', 'new' => '            $sent = $alerts->handover($convId, $phone, $channel, $reason, null);',
     'sections' => 'K', 'cases' => 'K02', 'pattern' => '/K02 /'],
]);

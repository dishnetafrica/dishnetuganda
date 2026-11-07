<?php
declare(strict_types=1);
/**
 * Weakened copies for section I of tests/test_voice_media.php (5.18.104, root docs/73 §H.3.24: V48's dead-letter forms,
 * in onDead() and in the lost-worker guard, and the switched_off record). TEST ONLY.
 */
$MW = 'workers/MediaWorker.php';
$M = array_merge($M, [
    ['label' => 'V48d onDead() deciding on the worker\'s start-time configuration', 'file' => $MW,
     'old' => "            \$cfg = PluginConfig::read(dirname(__DIR__), \$this->dataDir);\n            \$mode = MediaPolicy::voiceMode(\$cfg,", 'new' => "            \$cfg = \$this->config;\n            \$mode = MediaPolicy::voiceMode(\$cfg,",
     'sections' => 'I', 'cases' => 'I01', 'pattern' => '/I01 built at R3/'],
    ['label' => 'V48e the lost-worker guard deciding on the worker\'s start-time configuration', 'file' => $MW,
     'old' => "(int)\$row['attempts'], \$maxAttempts), \$mode);", 'new' => "(int)\$row['attempts'], \$maxAttempts), MediaPolicy::voiceMode(\$this->config, \$this->dataDir, (string)(\$row['channel'] ?? '')));",
     'sections' => 'I', 'cases' => 'I02', 'pattern' => '/I02 built at R3/'],
    ['label' => 'MW-SWOFF a row last acted on at R3 dying below it, not recorded switched_off', 'file' => $MW,
     'old' => "        if (in_array((string)(\$row['voice_rung'] ?? ''), ['handover', 'reply'], true)) \$fields['failure_reason'] = 'switched_off';\n", 'new' => '',
     'sections' => 'I', 'cases' => 'I02', 'pattern' => '/I02 a row last acted/'],
    ['label' => 'MW-DEADRUNG a dead letter at R3 not given its rung', 'file' => $MW,
     'old' => "            \$this->update(\$mediaId, ['status' => 'dead', 'voice_rung' => \$mode]);", 'new' => "            \$this->update(\$mediaId, ['status' => 'dead']);",
     'sections' => 'I', 'cases' => 'I02', 'pattern' => '/I02 control/'],
]);

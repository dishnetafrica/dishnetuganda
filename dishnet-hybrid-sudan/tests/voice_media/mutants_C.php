<?php
declare(strict_types=1);
/**
 * Weakened copies for section C of tests/test_voice_media.php (5.18.104, root docs/73 §H.3.24: V15, V16, V44, V48's
 * per-event form, and the worker's own guards). TEST ONLY.
 */
$MW = 'workers/MediaWorker.php';
$M = array_merge($M, [
    ['label' => 'V44 the before-switch-on rule removed', 'file' => $MW,
     'old' => '        if ($since > 0 && $made > 0 && $made < $since) {', 'new' => '        if (false) {',
     'sections' => 'C', 'cases' => 'C04', 'pattern' => '/C04 /'],
    ['label' => 'V15a R1 hands over a dead letter', 'file' => $MW,
     'old' => "        if (\$mode === 'handover' || \$mode === 'reply') {\n            \$this->update(\$mediaId, ['status' => 'dead', 'voice_rung' => \$mode]);",
     'new' => "        if (true) {\n            \$this->update(\$mediaId, ['status' => 'dead', 'voice_rung' => \$mode]);",
     'sections' => 'C', 'cases' => 'C06', 'pattern' => '/C06 R1/'],
    ['label' => 'V15b R1 hands over a fetch failed for good', 'file' => $MW,
     'old' => "        if (\$tell) {\n            \$this->handover(\$row, 'a voice message could not be fetched (", 'new' => "        if (true) {\n            \$this->handover(\$row, 'a voice message could not be fetched (",
     'sections' => 'C', 'cases' => 'C05', 'pattern' => '/C05 /'],
    ['label' => 'V15c R1 hands over a route the registry refuses', 'file' => $MW,
     'old' => "                if (\$tell) {\n                    \$this->handover(\$row, 'a voice message arrived but the number", 'new' => "                if (true) {\n                    \$this->handover(\$row, 'a voice message arrived but the number",
     'sections' => 'C', 'cases' => 'C08', 'pattern' => '/C08 R1/'],
    ['label' => 'V15d R1 sets needs_human on a dead letter (dev\'s gaveUp)', 'file' => $MW,
     'old' => "        \$fields = ['status' => 'dead'];\n", 'new' => "        \$fields = ['status' => 'dead'];\n        \$this->pdo->prepare(\"UPDATE wa_conversations SET state = 'needs_human' WHERE id = ?\")->execute([\$convId]);\n",
     'sections' => 'C', 'cases' => 'C06', 'pattern' => '/C06 R1/'],
    ['label' => 'V16a the attempt cap removed (the fetch)', 'file' => $MW,
     'old' => "        if (!empty(\$res['retryable']) && \$attempt < self::VOICE_MAX_ATTEMPTS) {", 'new' => "        if (!empty(\$res['retryable'])) {",
     'sections' => 'C', 'cases' => 'C05', 'pattern' => '/C05 /'],
    ['label' => 'V48a the switch and the rung decided on the worker\'s start-time configuration', 'file' => $MW,
     'old' => "            \$this->fresh = PluginConfig::read(dirname(__DIR__), \$this->dataDir);\n            if (MediaPolicy::enabled(", 'new' => "            \$this->fresh = \$this->config;\n            if (MediaPolicy::enabled(",
     'sections' => 'C', 'cases' => 'C03|C07', 'pattern' => '/C0[37] /'],
    ['label' => 'MW-SETTLED a settled row fetched again', 'file' => $MW,
     'old' => "        if (\$settled) {\n            \$this->log('info', sprintf('media #%d: already %s", 'new' => "        if (false) {\n            \$this->log('info', sprintf('media #%d: already %s",
     'sections' => 'C', 'cases' => 'C02', 'pattern' => '/C02 /'],
    ['label' => 'MW-MEASURE the packets not counted on the fetched file', 'file' => $MW,
     'old' => "                'local_seconds'    => !empty(\$d['ok']) ? (int)ceil((float)\$d['seconds']) : null,", 'new' => "                'local_seconds'    => null,",
     'sections' => 'C', 'cases' => 'C01', 'pattern' => '/C01 /'],
]);

<?php
declare(strict_types=1);
/**
 * Weakened copies for section E of tests/test_voice_media.php (5.18.104, root docs/73 §H.3.24: V12, V13, V14, V31,
 * V32's two forms and V48's holding-line form). TEST ONLY.
 */
$MW = 'workers/MediaWorker.php';
$VT = 'lib/VoiceTranscription.php';
$M = array_merge($M, [
    ['label' => 'V12 R3 rewrites the body', 'file' => $VT,
     'old' => "            if (\$mode === 'reply') {\n                // The stored \"[AUDIO]\" message", 'new' => "            if (\$mode !== 'dry_run') {\n                // The stored \"[AUDIO]\" message",
     'sections' => 'E', 'cases' => 'E01', 'pattern' => '/E01 /'],
    ['label' => 'V13 R3 emits ai.reply', 'file' => $VT,
     'old' => "            if (\$mode === 'reply') {\n                // The stored \"[AUDIO]\" message",
     'new' => "            if (\$mode === 'handover') \$this->bus->emit('ai.reply', 'conversation', \$convId, ['origin' => 'voice'], 3, 'media_worker');\n            if (\$mode === 'reply') {\n                // The stored \"[AUDIO]\" message",
     'sections' => 'E', 'cases' => 'E01', 'pattern' => '/E01 /'],
    ['label' => 'V14a a transcription failure not handed over at R3/R4', 'file' => $MW,
     'old' => "        if (\$mode === 'handover' || \$mode === 'reply') {\n            \$this->log('warn', sprintf('media #%d: voice note not transcribed",
     'new' => "        if (false) {\n            \$this->log('warn', sprintf('media #%d: voice note not transcribed",
     'sections' => 'E', 'cases' => 'E02', 'pattern' => '/E02 /'],
    ['label' => 'V14b a fetch failed for good not handed over at R3/R4', 'file' => $MW,
     'old' => "        if (\$tell) {\n            \$this->handover(\$row, 'a voice message could not be fetched (", 'new' => "        if (false) {\n            \$this->handover(\$row, 'a voice message could not be fetched (",
     'sections' => 'E', 'cases' => 'E02', 'pattern' => '/E02 /'],
    ['label' => 'V31 the media worker passes no line owner', 'file' => $MW,
     'old' => "function (string \$level, string \$message): void { \$this->log(\$level, \$message); }, 'media_worker', \$owner);",
     'new' => "function (string \$level, string \$message): void { \$this->log(\$level, \$message); }, 'media_worker', null);",
     'sections' => 'E', 'cases' => 'E06', 'pattern' => '/E06 /'],
    ['label' => 'V32a R3 sends the holding line while P5 serves the number', 'file' => $MW,
     'old' => '        $already = $noHoldingLine || $this->p5Serves($now, $channel) || $this->recentAck($convId);', 'new' => '        $already = $noHoldingLine || $this->recentAck($convId);',
     'sections' => 'E', 'cases' => 'E03', 'pattern' => '/E03 /'],
    ['label' => 'V32b change 7 without its acknowledgement test', 'file' => $MW,
     'old' => '        $already = $noHoldingLine || $this->p5Serves($now, $channel) || $this->recentAck($convId);', 'new' => '        $already = $noHoldingLine || $this->p5Serves($now, $channel);',
     'sections' => 'E', 'cases' => 'E04', 'pattern' => '/E04 /'],
    ['label' => 'V48c the holding line decided on the worker\'s start-time configuration', 'file' => $MW,
     'old' => "            \$now = PluginConfig::read(dirname(__DIR__), \$this->dataDir);\n        } catch (\\Throwable \$e) {\n            \$now = null;",
     'new' => "            \$now = \$this->config;\n        } catch (\\Throwable \$e) {\n            \$now = null;",
     'sections' => 'E', 'cases' => 'E05', 'pattern' => '/E05 /'],
    ['label' => 'MW-HO the transcript at R3 not handed over', 'file' => $MW,
     'old' => "        if (\$outcome === 'handover') {", 'new' => "        if (\$outcome === 'handover' && false) {",
     'sections' => 'E', 'cases' => 'E01', 'pattern' => '/E01 /'],
]);

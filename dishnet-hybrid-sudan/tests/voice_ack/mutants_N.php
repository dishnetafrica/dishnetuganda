<?php
declare(strict_types=1);
/**
 * Weakened copies for section N of tests/test_voice_ack.php (5.18.104, root docs/73 §H.3.24: T15's VN-ON2 copy, T20,
 * and one each for the Opus gate, the provider refusals, the ok rate and VN-K1). TEST ONLY.
 */
$VS = 'lib/VoiceSteps.php';
$M = array_merge($M, [
    ['label' => 'T15b VN-ON2 counting every media_worker escalation', 'file' => $VS,
     'old' => "        \$owned = self::escalationOwners(\$ro, \$sinceS);\n        foreach (self::all(\$ro, \"SELECT * FROM wa_media WHERE kind = 'audio' AND voice_rung = 'dry_run'",
     'new' => "        \$owned = self::escalationOwners(\$ro, \$sinceS);\n        if (\$owned !== []) { self::inc(\$c, 'r2_acted'); \$fail[] = 'r2_acted'; }\n        foreach (self::all(\$ro, \"SELECT * FROM wa_media WHERE kind = 'audio' AND voice_rung = 'dry_run'",
     'sections' => 'N', 'cases' => 'N02', 'pattern' => '/N02 VN-ON2 reads PASS/'],
    ['label' => 'T20 VN-ON2 judging each day by ai_media_voice_daily_minutes as it reads now', 'file' => $VS,
     'old' => "if (\$used + (int)\$v['seconds_reserved'] > (int)\$v['cap_day_s']) {",
     'new' => "if (\$used + (int)\$v['seconds_reserved'] > 60 * (int)(\$config['ai_media_voice_daily_minutes'] ?? 0)) {",
     'sections' => 'N', 'cases' => 'N01', 'pattern' => '/N01 VN-ON2 reads PASS/'],
    ['label' => 'VS-OPUS the Opus gate dropped', 'file' => $VS, 'old' => "        if (\$opus === 0) {", 'new' => "        if (false) {",
     'sections' => 'N', 'cases' => 'N03', 'pattern' => '/N03 no Ogg/'],
    ['label' => 'VS-AUTH a provider refusal read as an ordinary failure', 'file' => $VS,
     'old' => "['provider_auth', 'provider_model', 'provider_quota']", 'new' => "['provider_model', 'provider_quota']",
     'sections' => 'N', 'cases' => 'N03', 'pattern' => '/N03 one call refused/'],
    ['label' => 'VS-RATE the ok rate dropped', 'file' => $VS, 'old' => "if (\$settled > 0 && \$ok * 100 < 80 * \$settled)", 'new' => "if (false)",
     'sections' => 'N', 'cases' => 'N03', 'pattern' => '/N03 seven of ten/'],
    ['label' => 'VN-K1 the log scan dropped', 'file' => $VS,
     'old' => "if (is_string(\$s) && strpos(\$s, \$key) !== false)", 'new' => "if (false)",
     'sections' => 'N', 'cases' => 'N04', 'pattern' => '/N04 control on a copy/'],
]);
$M = array_merge($M, [
    ['label' => 'VN-K2 the installed tab prints the stored key on Uganda', 'file' => 'tabs/engage/whatsapp.php',
     'old' => "value=\"<?= \$_aiKeyUg ? '' : h(\$config['openai_api_key'] ?? '')", 'new' => "value=\"<?= h(\$config['openai_api_key'] ?? '')",
     'sections' => 'N', 'cases' => 'N04', 'pattern' => '/N04 a log without the key/'],
]);

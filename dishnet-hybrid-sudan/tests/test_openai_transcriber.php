<?php
declare(strict_types=1);
/**
 * test_openai_transcriber.php — 5.18.104 (root docs/73 §H.3.9, §H.3.18; C9, R-08): lib/OpenAiTranscriber.php against
 * tests/fixtures/fake_openai_server.php on loopback, and TranscriberFactory's `openai` branch.
 *
 * A the request (multipart in memory: the model and the file only; the stored key as bearer); B every answer mapped to
 * its outcome and retry flag, a redirect never followed, a timeout and a refused connection; C what leaves the adapter
 * (a fixed detail, never the body, never the key — in its result, the error log, a dump of the object or the stack of an
 * exception raised under it), the endpoint (OpenAI's or the tests' loopback, never configuration), and no temporary file;
 * D the factory. Each section begins with its positive control; then the weakened copies (V24's three forms, V25's three,
 * V26, V27's two, and the adapter's own guards), each judged by this file run on the copy with the sections it names.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_openai_transcriber.php [--root=DIR] [--sections=A] [--no-mutants]
 *
 * Fictitious key and audio only; nothing leaves 127.0.0.1.
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/voice_media/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('oa');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

$load = function () use ($ctx): void {
    foreach (['lib/MediaPolicy.php', 'lib/MediaBlob.php', 'lib/Transcriber.php', 'lib/OpenAiTranscriber.php'] as $f) require_once $ctx->tree() . '/' . $f;
};
$cfg = ['openai_api_key' => VM_KEY];
$blob = function (string $bytes = '') { return new MediaBlob($bytes !== '' ? $bytes : ogg_opus(['seconds' => 2.0]), 'audio/ogg; codecs=opus', 'audio'); };
/** One call with the error log captured: [result, the log's text]. */
$call = function (OpenAiTranscriber $t, ?MediaBlob $b = null, int $timeout = 10) use ($ctx, $blob): array {
    $log = $ctx->dir('elog') . '/e.log';
    $prev = ini_set('error_log', $log);
    try { $r = $t->transcribe($b ?? $blob(), ['seconds' => 2], $timeout); } finally { ini_set('error_log', (string)$prev); }
    return [$r, (string)@file_get_contents($log)];
};

$SECTIONS = [
    'A' => ['title' => 'The request: model and file in memory, the stored key as bearer, nothing else', 'run' => function (QfCtx $ctx) use ($load, $cfg, $blob, $call): void {
        $load();
        $oa = vm_fake_openai();
        $ctx->t($oa !== '', 'A00 the fake OpenAI started');
        if ($oa === '') return;
        $ctx->run('A01 control: one POST, model whisper-1, file voice.ogg as audio/ogg, the key as bearer', function () use ($ctx, $oa, $cfg, $blob, $call) {
            vm_fake_openai();
            $b = $blob();
            [$r] = $call(vm_adapter($oa, $cfg), $b);
            $ctx->eq([$r['ok'], $r['text'], $r['http'] ?? null], [true, 'FAKE_OPENAI_TEXT', 200], 'A01 the transcript');
            $c = vm_oa_calls($oa);
            $ctx->eq(array_map(function ($x) { return [$x['auth'], $x['auth_sha'], $x['content_type'], $x['fields'], $x['model'], $x['file_name'], $x['file_type'], $x['file_size'], $x['file_sha']]; }, $c),
                     [['present', substr(hash('sha256', VM_KEY), 0, 12), 'multipart/form-data', ['model'], 'whisper-1', 'voice.ogg', 'audio/ogg', $b->size, substr($b->sha256, 0, 12)]],
                     'A01 one call: no language, no prompt, no response_format; the bytes as fetched');
        });
        $ctx->run('A02 the model is the configured one; the provider\'s duration is reported', function () use ($ctx, $oa, $cfg, $call) {
            vm_fake_openai();
            vm_oa_next($oa, [['status' => 200, 'body' => ['text' => 'x', 'duration' => 7.4]], ['status' => 200, 'body' => ['text' => 'y', 'usage' => ['seconds' => 3]]]]);
            $t = vm_adapter($oa, $cfg + ['ai_media_openai_stt_model' => 'gpt-4o-transcribe']);
            [$r1] = $call($t); [$r2] = $call($t);
            $ctx->eq([$t->name(), vm_oa_calls($oa)[0]['model'] ?? '', $r1['duration_s'], $r2['duration_s']], ['openai:gpt-4o-transcribe', 'gpt-4o-transcribe', 7.4, 3.0],
                     'A02 gpt-4o-transcribe named and sent; duration, then usage.seconds');
            [$r3] = $call(vm_adapter($oa, $cfg + ['ai_media_openai_stt_model' => 'whisper-9']));
            $ctx->eq([$r3['ok'], $r3['reason'], count(vm_oa_calls($oa))], [false, 'provider_missing', 2], 'A02 a model off the allow-list: no call');
        });
        $ctx->run('A03 a boundary never found in the audio', function () use ($ctx, $oa, $cfg, $call) {
            vm_fake_openai();
            $bytes = ogg_opus(['seconds' => 1.0]) . str_repeat('--', 10);
            [$r] = $call(vm_adapter($oa, $cfg), new MediaBlob($bytes, 'audio/ogg', 'audio'));
            $ctx->eq([$r['ok'], vm_oa_calls($oa)[0]['file_size'] ?? 0], [true, strlen($bytes)], 'A03 the file arrives whole');
        });
    }],
    'B' => ['title' => 'Every answer has one outcome, and a retry flag', 'run' => function (QfCtx $ctx) use ($load, $cfg, $call): void {
        $load();
        $oa = vm_fake_openai();
        if ($oa === '') { $ctx->t(false, 'B00 the fake OpenAI started'); return; }
        $ctx->run('B01 control: 200 with a string text is ok', function () use ($ctx, $oa, $cfg, $call) {
            vm_fake_openai();
            [$r] = $call(vm_adapter($oa, $cfg));
            $ctx->eq($r['ok'], true, 'B01 ok');
        });
        $ctx->run('B02 the table (§H.3.9)', function () use ($ctx, $oa, $cfg, $call) {
            $rows = [
                ['200 without text', ['status' => 200, 'body' => ['words' => []]], ['provider_error', true, 'HTTP 200 malformed']],
                ['200 not JSON', ['status' => 200, 'raw' => 'not json'], ['provider_error', true, 'HTTP 200 malformed']],
                ['400', ['status' => 400, 'body' => ['error' => ['type' => 'invalid_request_error', 'code' => 'unsupported_file']]], ['provider_rejected', false, 'HTTP 400 invalid_request_error unsupported_file']],
                ['401', ['status' => 401, 'body' => ['error' => ['type' => 'invalid_request_error', 'code' => 'invalid_api_key']]], ['provider_auth', false, 'HTTP 401 invalid_request_error invalid_api_key']],
                ['403', ['status' => 403, 'body' => ['error' => ['type' => 'permission_error']]], ['provider_auth', false, 'HTTP 403 permission_error']],
                ['404', ['status' => 404, 'body' => ['error' => ['code' => 'model_not_found']]], ['provider_model', false, 'HTTP 404 model_not_found']],
                ['413', ['status' => 413, 'body' => []], ['too_large', false, 'HTTP 413']],
                ['429 insufficient_quota', ['status' => 429, 'body' => ['error' => ['type' => 'insufficient_quota', 'code' => 'insufficient_quota']]], ['provider_quota', false, 'HTTP 429 insufficient_quota insufficient_quota']],
                ['429 rate limit', ['status' => 429, 'body' => ['error' => ['type' => 'requests', 'code' => 'rate_limit_exceeded']]], ['provider_busy', true, 'HTTP 429 requests rate_limit_exceeded']],
                ['500', ['status' => 500, 'body' => ['error' => ['type' => 'server_error']]], ['provider_error', true, 'HTTP 500 server_error']],
                ['503', ['status' => 503, 'raw' => 'busy'], ['provider_error', true, 'HTTP 503']],
                ['418', ['status' => 418, 'body' => []], ['provider_error', false, 'HTTP 418']],
                ['a redirect, never followed', ['status' => 302, 'redirect' => true], ['provider_error', false, 'HTTP 302']],
                ['an error code with odd characters, cut to [a-z0-9_]{0,40}', ['status' => 400, 'body' => ['error' => ['type' => 'Bad Type! <x>', 'code' => str_repeat('ab', 30)]]],
                 ['provider_rejected', false, 'HTTP 400 badtypex ' . str_repeat('ab', 20)]],
            ];
            foreach ($rows as [$label, $answer, $want]) {
                vm_fake_openai();
                vm_oa_next($oa, [$answer]);
                [$r] = $call(vm_adapter($oa, $cfg));
                $ctx->eq([$r['ok'], $r['reason'] ?? '', $r['retryable'] ?? null, $r['detail'] ?? ''], array_merge([false], $want), 'B02 ' . $label);
            }
            $ctx->eq(array_values(array_filter(vm_oa_calls($oa), function ($c) { return ($c['path'] ?? '') === '/redirected'; })), [], 'B02 the redirect\'s target was never asked');
        });
        $ctx->run('B03 a timeout, and a refused connection, are retryable transport failures', function () use ($ctx, $oa, $cfg, $call) {
            vm_fake_openai();
            vm_oa_next($oa, [['status' => 200, 'body' => ['text' => 'late'], 'hold' => 3]]);
            [$r] = $call(vm_adapter($oa, $cfg), null, 1);
            $ctx->eq([$r['ok'], $r['reason'], $r['retryable'], $r['detail']], [false, 'timeout', true, 'curl 28'], 'B03 no answer within the timeout: timeout');
            sleep(3);
            [$r2] = $call(new OpenAiTranscriber($cfg, 'http://127.0.0.1:47/v1/audio/transcriptions'));
            $ctx->eq([$r2['ok'], $r2['reason'], $r2['retryable'], $r2['detail']], [false, 'provider_error', true, 'curl 7'], 'B03 a refused connection: provider_error, curl 7');
        });
    }],
    'C' => ['title' => 'What leaves the adapter: a fixed detail — never the body, never the key; no temp file; no endpoint from configuration', 'run' => function (QfCtx $ctx) use ($load, $cfg, $blob, $call): void {
        $load();
        $oa = vm_fake_openai();
        if ($oa === '') { $ctx->t(false, 'C00 the fake OpenAI started'); return; }
        $tail = substr(VM_KEY, -8); $head = substr(VM_KEY, 0, 12);
        $ctx->run('C01 control: the fake\'s 401 quotes the key\'s tail; the adapter passes on none of it (V24)', function () use ($ctx, $oa, $cfg, $call, $tail, $head) {
            vm_fake_openai();
            vm_oa_next($oa, [['echo_key_tail' => true]]);
            $raw = qf_http('POST', $oa . '/v1/audio/transcriptions', null, ['Authorization: Bearer ' . VM_KEY]);
            $ctx->t(strpos($raw[2], $tail) !== false, 'C01 control: the fake\'s answer does carry the key\'s tail');
            vm_oa_next($oa, [['echo_key_tail' => true]]);
            [$r, $elog] = $call(vm_adapter($oa, $cfg));
            $ctx->eq([$r['reason'], $r['detail']], ['provider_auth', 'HTTP 401 invalid_request_error invalid_api_key'], 'C01 the outcome and its fixed detail');
            $ctx->t(strpos(json_encode($r), $tail) === false && strpos($elog, $tail) === false && strpos($elog, $head) === false,
                    'C01 neither the result nor the error log carries the key or its tail', $elog);
        });
        $ctx->run('C02 a dump of the adapter says only whether a key is set', function () use ($ctx, $oa, $cfg, $head) {
            $t = vm_adapter($oa, $cfg);
            ob_start(); var_dump($t); $vd = (string)ob_get_clean();
            $pr = print_r($t, true);
            $ctx->eq([strpos($vd, $head) === false, strpos($pr, $head) === false, strpos($pr, '[key] => set') !== false,
                      strpos(print_r(vm_adapter($oa, []), true), '[key] => not set') !== false, $t->name()], [true, true, true, true, 'openai:whisper-1'],
                     'C02 var_dump and print_r: model and set / not set; name() names the model');
        });
        $ctx->run('C03 an exception under the call: provider_error with its class, and no stack frame carries the key (R-08)', function () use ($ctx, $oa, $cfg, $call, $head) {
            if (!class_exists('VmThrowingBytes', false)) {
                eval('final class VmThrowingBytes { public static $trace = ""; public function __toString(): string { self::$trace = (new \Exception("probe"))->getTraceAsString(); throw new \RuntimeException("bytes unreadable"); } }');
            }
            ini_set('zend.exception_ignore_args', '0');
            ini_set('zend.exception_string_param_max_len', '15');
            $b = new MediaBlob('x', 'audio/ogg', 'audio');
            $b->bytes = new VmThrowingBytes();
            [$r] = $call(vm_adapter($oa, $cfg), $b);
            $ctx->eq([$r['ok'], $r['reason'], $r['detail']], [false, 'provider_error', 'RuntimeException'], 'C03 the class, never the message');
            $ctx->t(VmThrowingBytes::$trace !== '' && strpos(VmThrowingBytes::$trace, 'transcribe(') !== false, 'C03 control: the stack under the call was captured', VmThrowingBytes::$trace);
            $ctx->t(strpos(VmThrowingBytes::$trace, substr($head, 0, 10)) === false, 'C03 no frame of it carries the key as an argument', VmThrowingBytes::$trace);
        });
        $ctx->run('C04 the endpoint is OpenAI\'s or the loopback fake\'s, never configuration\'s (V27)', function () use ($ctx, $oa, $cfg, $call) {
            vm_fake_openai();
            [$r] = $call(new OpenAiTranscriber($cfg + ['ai_media_openai_endpoint' => $oa . '/planted/v1/audio/transcriptions',
                                                       'openai_endpoint' => $oa . '/planted/v1/audio/transcriptions'], $oa . '/v1/audio/transcriptions'));
            $ctx->eq([$r['ok'], count(vm_oa_calls($oa))], [true, 1], 'C04 control: a planted endpoint key is ignored, the call goes where the constructor said');
            $port = (int)parse_url($oa, PHP_URL_PORT);
            [$r2] = $call(new OpenAiTranscriber($cfg, "http://localhost:{$port}/v1/audio/transcriptions"));
            [$r3] = $call(new OpenAiTranscriber($cfg, 'https://transcribe.example.test/v1/audio/transcriptions'));
            $ctx->eq([$r2['reason'], $r3['reason'], count(vm_oa_calls($oa))], ['provider_missing', 'provider_missing', 1], 'C04 any other endpoint: provider_missing, no call');
            $src = (string)php_strip_whitespace($ctx->tree() . '/lib/OpenAiTranscriber.php');   // the code, its comments left out
            $ctx->eq([preg_match('/\bgetenv\s*\(/', $src), preg_match('/CURLFile|tempnam|tmpfile|sys_get_temp_dir|CURLOPT_VERBOSE\s*=>\s*true|curl_error\s*\(/', $src)], [0, 0],
                     'C04 no getenv, no CURLFile, no temp file, never verbose, never curl_error() in the file');
        });
        $ctx->run('C05 the audio is never written to disk (V26)', function () use ($ctx, $oa, $cfg, $call) {
            vm_fake_openai();
            $b = new MediaBlob(ogg_opus(['seconds' => 1.0]), 'audio/ogg', 'audio');
            [$r] = $call(vm_adapter($oa, $cfg), $b);
            $ctx->eq([$r['ok'], vm_files_with((string)getenv('TMPDIR'), OGG_OPUS_MARKER), vm_files_with($ctx->tree(), OGG_OPUS_MARKER)], [true, [], []],
                     'C05 after a call no file under the temp folder or the plugin holds the audio');
        });
    }],
    'D' => ['title' => 'The factory: openai only with a stored key; any doubt is no provider', 'run' => function (QfCtx $ctx) use ($load, $cfg): void {
        $load();
        $ctx->run('D01 control: openai with a key is the adapter', function () use ($ctx, $cfg) {
            $t = TranscriberFactory::fromConfig($cfg + ['ai_transcription_provider' => 'openai']);
            $ctx->eq([$t instanceof OpenAiTranscriber, $t ? $t->name() : ''], [true, 'openai:whisper-1'], 'D01 OpenAiTranscriber, whisper-1');
        });
        $ctx->run('D02 every other configuration is null, and nothing throws', function () use ($ctx, $cfg) {
            $ctx->eq([TranscriberFactory::fromConfig(['ai_transcription_provider' => 'openai']), TranscriberFactory::fromConfig(['ai_transcription_provider' => 'openai', 'openai_api_key' => '  ']),
                      TranscriberFactory::fromConfig($cfg), TranscriberFactory::fromConfig($cfg + ['ai_transcription_provider' => 'none']),
                      TranscriberFactory::fromConfig($cfg + ['ai_transcription_provider' => 'fake']), TranscriberFactory::fromConfig($cfg + ['ai_transcription_provider' => 'whisper'])],
                     [null, null, null, null, null, null], 'D02 no key, a blank key, no provider, none, fake without its test file, an unknown name');
            $ok = true;
            try { $x = TranscriberFactory::fromConfig(['ai_transcription_provider' => new \stdClass()]); } catch (\Throwable $e) { $ok = false; $x = 'threw'; }
            $ctx->eq([$ok, $x], [true, null], 'D02 a provider that cannot be read as a string: null, no throw');
        });
    }],
];

qf_run_sections($ctx, $SECTIONS, $O['sections']);

if ($O['mutants']) {
    $OA = 'lib/OpenAiTranscriber.php';
    qf_mutants($ctx, __FILE__, [
        ['label' => 'V24a the response body logged', 'file' => $OA, 'old' => "        \$j = json_decode((string)\$raw, true);\n",
         'new' => "        error_log((string)\$raw);\n        \$j = json_decode((string)\$raw, true);\n", 'sections' => 'C', 'cases' => 'C01', 'pattern' => '/C01 neither/'],
        ['label' => 'V24b the headers logged', 'file' => $OA, 'old' => "        \$https = \$this->endpoint === self::ENDPOINT;\n",
         'new' => "        error_log(implode(' ', \$headers));\n        \$https = \$this->endpoint === self::ENDPOINT;\n", 'sections' => 'C', 'cases' => 'C01', 'pattern' => '/C01 neither/'],
        ['label' => 'V24c the key passed as a string argument', 'edits' => [
            [$OA, '            return $this->call($audio, $timeoutSeconds);', '            return $this->call($this->key, $audio, $timeoutSeconds);'],
            [$OA, '    private function call(MediaBlob $audio, int $timeoutSeconds): array', '    private function call(string $key, MediaBlob $audio, int $timeoutSeconds): array']],
         'sections' => 'C', 'cases' => 'C03', 'pattern' => '/C03 no frame/'],
        ['label' => 'V25a 429 (not the quota) treated as permanent', 'file' => $OA, 'old' => "            return self::out('provider_busy', true, \$detail, \$http);",
         'new' => "            return self::out('provider_busy', false, \$detail, \$http);", 'sections' => 'B', 'cases' => 'B02', 'pattern' => '/B02 429 rate/'],
        ['label' => 'V25b 400 treated as retryable', 'file' => $OA, 'old' => "        if (\$http === 400) return self::out('provider_rejected', false, \$detail, \$http);",
         'new' => "        if (\$http === 400) return self::out('provider_rejected', true, \$detail, \$http);", 'sections' => 'B', 'cases' => 'B02', 'pattern' => '/B02 400/'],
        ['label' => 'V25c insufficient_quota treated as retryable', 'file' => $OA, 'old' => "            if (\$code === 'insufficient_quota') return self::out('provider_quota', false, \$detail, \$http);",
         'new' => "            if (\$code === 'insufficient_quota') return self::out('provider_quota', true, \$detail, \$http);", 'sections' => 'B', 'cases' => 'B02', 'pattern' => '/B02 429 insufficient/'],
        ['label' => 'V26 the audio written to a temporary file', 'file' => $OA, 'old' => "        unset(\$bytes);\n        \$headers = ",
         'new' => "        \$vmTmp = tempnam(sys_get_temp_dir(), 'oa'); file_put_contents(\$vmTmp, \$bytes);\n        unset(\$bytes);\n        \$headers = ",
         'sections' => 'C', 'cases' => 'C04|C05', 'pattern' => '/C0[45] /'],
        ['label' => 'V27a the endpoint read from configuration', 'file' => $OA, 'old' => '        $this->endpoint = $endpoint;',
         'new' => "        \$this->endpoint = (string)(\$config['ai_media_openai_endpoint'] ?? \$endpoint);", 'sections' => 'C', 'cases' => 'C04', 'pattern' => '/C04 control/'],
        ['label' => 'V27b a non-loopback endpoint accepted', 'file' => $OA, 'old' => "    private const LOOPBACK     = '#^http://127\\.0\\.0\\.1:\\d{2,5}/#';",
         'new' => "    private const LOOPBACK     = '#^https?://#';", 'sections' => 'C', 'cases' => 'C04', 'pattern' => '/C04 any other/'],
        ['label' => 'OA-REDIRECT a redirect followed', 'file' => $OA, 'old' => '            CURLOPT_FOLLOWLOCATION => false,',
         'new' => '            CURLOPT_FOLLOWLOCATION => true,', 'sections' => 'B', 'cases' => 'B02', 'pattern' => '/B02 a redirect|B02 the redirect/'],
        ['label' => 'OA-DETAIL the detail carrying the answer', 'file' => $OA, 'old' => "        if (\$http === 401 || \$http === 403) return self::out('provider_auth', false, \$detail, \$http);",
         'new' => "        if (\$http === 401 || \$http === 403) return self::out('provider_auth', false, \$detail . ' ' . json_encode(\$j), \$http);", 'sections' => 'C', 'cases' => 'C01', 'pattern' => '/C01 /'],
        ['label' => 'OA-DEBUG a dump showing the key', 'file' => $OA, 'old' => "        return ['model' => \$this->model, 'key' => \$this->key !== '' ? 'set' : 'not set'];",
         'new' => "        return ['model' => \$this->model, 'key' => \$this->key];", 'sections' => 'C', 'cases' => 'C02', 'pattern' => '/C02 /'],
        ['label' => 'OA-THROW transcribe without its catch-all', 'file' => $OA, 'old' => "        } catch (\\Throwable \$e) {\n            return self::out('provider_error', true, get_class(\$e), 0);\n        }",
         'new' => "        } finally {\n        }", 'sections' => 'C', 'cases' => 'C03', 'pattern' => '/C03 /'],
        ['label' => 'OA-MODEL a model off the allow-list called', 'file' => $OA, 'old' => "        \$this->usable = in_array(\$this->model, self::MODELS, true)\n            && (",
         'new' => "        \$this->usable = true\n            && (", 'sections' => 'A', 'cases' => 'A02', 'pattern' => '/A02 a model/'],
        ['label' => 'OA-FACTORY the factory without the key check', 'file' => 'lib/Transcriber.php', 'old' => "                if (trim((string)(\$config['openai_api_key'] ?? '')) === '') return null;\n",
         'new' => '', 'sections' => 'D', 'cases' => 'D02', 'pattern' => '/D02 no key/'],
        ['label' => 'OA-FACTORY-THROW the factory without its catch', 'file' => 'lib/Transcriber.php', 'old' => "        } catch (\\Throwable \$e) {\n            return null;\n        }",
         'new' => "        } finally {\n        }", 'sections' => 'D', 'cases' => 'D02', 'pattern' => '/D02 /'],
    ], $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

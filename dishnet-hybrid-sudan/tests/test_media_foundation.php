<?php
declare(strict_types=1);
/**
 * test_media_foundation.php — 5.18.104 (root docs/73 §H.3.2, §H.3.24): the media foundation ported from the development
 * tree (Batch 1, docs/55 §9), trimmed to audio.
 *
 * A InboundMedia: one shape for the five envelopes Evolution sends, null for text, the wrappers read through, `ptt`;
 * B MediaFetcher against the fake Evolution: every refusal before a call (no key, a kind this release does not fetch,
 * an announced size over the limit), then the answer checked (4xx final, 5xx and a timeout retryable, the type against
 * the allow-list, a strict decode, the payload's size) and the bytes in memory with their hash;
 * C wa_media: one row per WhatsApp message id, linked to its stored message; and photos and documents, which the
 * development tree read, are NOT recorded on this release — the webhook never records them, and a row for one would be
 * settled `unsupported` and never fetched;
 * D the worker's own lock: a second worker never runs beside the first.
 * Each section begins with its positive control; then the weakened copies (MF1-MF8).
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_media_foundation.php [--root=DIR] [--sections=A] [--no-mutants]
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/voice_media/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('mf');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

$SECTIONS = [
    'A' => ['title' => 'InboundMedia: one shape for every envelope', 'run' => function (QfCtx $ctx): void {
        vm_load($ctx);
        $ctx->run('A01 control: a voice note', function () use ($ctx) {
            $env = vm_envelope('256771301201', ['id' => 'MF-A01', 'seconds' => 7, 'length' => 9000]);
            $m = InboundMedia::fromEvoMessage($env);
            $ctx->eq($m, ['kind' => 'audio', 'mimetype' => 'audio/ogg; codecs=opus', 'file_name' => '', 'caption' => '', 'declared_bytes' => 9000, 'seconds' => 7,
                          'ptt' => true, 'key' => ['remote_jid' => '256771301201@s.whatsapp.net', 'id' => 'MF-A01', 'from_me' => false], 'message_type' => 'audioMessage'],
                     'A01 every field');
        });
        $ctx->run('A02 the other kinds, and text', function () use ($ctx) {
            $k = function (array $message) { $m = InboundMedia::fromEvoMessage(['key' => ['id' => 'x', 'remoteJid' => 'j'], 'message' => $message]); return $m === null ? null : [$m['kind'], $m['file_name'], $m['caption'], $m['ptt']]; };
            $ctx->eq([$k(['imageMessage' => ['caption' => ' see ']]), $k(['documentMessage' => ['fileName' => 'q.pdf']]), $k(['videoMessage' => []]), $k(['stickerMessage' => []]),
                      $k(['audioMessage' => []]), $k(['conversation' => 'hello']), $k([])],
                     [['image', '', 'see', false], ['document', 'q.pdf', '', false], ['video', '', '', false], ['sticker', '', '', false], ['audio', '', '', false], null, null],
                     'A02 image, document (its file name), video, sticker; forwarded audio without ptt; text and nothing are null');
        });
        $ctx->run('A03 the wrappers are read through, and said', function () use ($ctx) {
            $au = ['audioMessage' => ['ptt' => true]];
            $one = ['key' => ['id' => 'w1', 'remoteJid' => 'j'], 'message' => ['ephemeralMessage' => ['message' => $au]]];
            $two = ['key' => ['id' => 'w2', 'remoteJid' => 'j'], 'message' => ['ephemeralMessage' => ['message' => ['viewOnceMessageV2' => ['message' => $au]]]]];
            $ctx->eq([InboundMedia::fromEvoMessage($one)['kind'] ?? null, InboundMedia::fromEvoMessage($two)['ptt'] ?? null, InboundMedia::wasWrapped($one),
                      InboundMedia::wasWrapped(['message' => $au]), InboundMedia::placeholder('audio')], ['audio', true, true, false, '[AUDIO]'],
                     'A03 one and two levels; wasWrapped true for a wrapper, false for a plain note; the placeholder');
        });
    }],
    'B' => ['title' => 'MediaFetcher: refused before any call, or checked after it', 'run' => function (QfCtx $ctx): void {
        vm_load($ctx);
        $evo = qf_fake_evo();
        $ctx->t($evo !== '', 'B00 the fake Evolution started');
        if ($evo === '') return;
        $cfg = vm_cfg($evo, ['ai_media_timeout_s' => 3]);
        $b = vm_book($ctx, $cfg);
        $f = new MediaFetcher(EvolutionApiService::forStore($cfg, $b['pdo'], $b['data'], MediaPolicy::timeoutSeconds($cfg)), $cfg);
        $row = function (string $id, array $o = []) { return $o + ['kind' => 'audio', 'wa_message_id' => $id, 'remote_jid' => '256771301202@s.whatsapp.net', 'channel' => 'sales', 'from_me' => 0, 'declared_bytes' => 100]; };
        $short = function (array $r): array { return empty($r['ok']) ? [$r['reason'], $r['retryable']] : ['ok', $r['blob']->size]; };
        $calls = function () use ($evo): int { return count((array)(vm_evo_state($evo)['media_fetch_calls'] ?? [])); };
        $ctx->run('B01 control: a voice note in memory, its size, type and hash', function () use ($ctx, $f, $row, $evo) {
            qf_http('GET', $evo . '/__test/reset');
            $bytes = ogg_opus(['seconds' => 1.0]);
            vm_media($evo, 'MF-B01', $bytes);
            $r = $f->fetch($row('MF-B01'));
            $ctx->eq([$r['ok'], $r['blob']->size, $r['blob']->sha256, $r['blob']->mimetype, $r['blob']->bytes === $bytes], [true, strlen($bytes), hash('sha256', $bytes), 'audio/ogg; codecs=opus', true],
                     'B01 the bytes as sent');
            $r['blob']->wipe();
            $ctx->eq([$r['blob']->bytes, $r['blob']->size, strpos($r['blob']->describe(), 'audio audio/ogg') === 0], ['', strlen($bytes), true], 'B01 wiped: the bytes gone, size and hash kept');
        });
        $ctx->run('B02 refused before any call', function () use ($ctx, $f, $row, $short, $calls, $evo) {
            qf_http('GET', $evo . '/__test/reset');
            $ctx->eq([$short($f->fetch($row('', ['wa_message_id' => '']))), $short($f->fetch($row('MF-B02', ['remote_jid' => '']))),
                      $short($f->fetch($row('MF-B02', ['kind' => 'image']))), $short($f->fetch($row('MF-B02', ['kind' => 'document']))),
                      $short($f->fetch($row('MF-B02', ['declared_bytes' => 3000000])))],
                     [['missing_identifier', false], ['missing_identifier', false], ['unsupported_kind', false], ['unsupported_kind', false], ['too_large', false]],
                     'B02 no message id, no JID, a photo and a document (not fetched on this release), an announced 3 MB');
            $ctx->eq($calls(), 0, 'B02 and not one call');
        });
        $ctx->run('B03 the answer checked', function () use ($ctx, $f, $row, $short, $evo) {
            $cases = [
                'a 404 is final' => [['fail' => 404, 'base64' => 'eA=='], ['fetch_failed', false]],
                'a 503 is retryable' => [['fail' => 503, 'base64' => 'eA=='], ['fetch_failed', true]],
                'a type off the allow-list' => [['base64' => base64_encode('plain'), 'mimetype' => 'text/plain'], ['unsupported_mime', false]],
                'a type this release fetches but does not transcribe (MP3)' => [['base64' => base64_encode('ID3xx'), 'mimetype' => 'audio/mpeg'], ['ok', 5]],
                'not base64 (strict)' => [['base64' => 'QUJD$$$$', 'mimetype' => 'audio/ogg'], ['malformed', true]],
                'no base64' => [['raw' => '{"mimetype":"audio/ogg"}'], ['malformed', true]],
                'a payload over the limit' => [['base64' => base64_encode(str_repeat('a', 2200000)), 'mimetype' => 'audio/ogg'], ['too_large', false]],
            ];
            foreach ($cases as $label => [$next, $want]) {
                qf_http('GET', $evo . '/__test/reset');
                qf_http('POST', $evo . '/__test/media', $next + ['for_id' => 'MF-B03']);
                $ctx->eq($short($f->fetch($row('MF-B03'))), $want, 'B03 ' . $label);
            }
        });
        $ctx->run('B04 a timeout is retryable', function () use ($ctx, $f, $row, $evo) {
            qf_http('GET', $evo . '/__test/reset');
            vm_media($evo, 'MF-B04', 'x', 'audio/ogg', ['hold' => 5]);
            $t0 = microtime(true);
            $r = $f->fetch($row('MF-B04'));
            $ctx->eq([$r['reason'] ?? 'ok', $r['retryable'] ?? null, microtime(true) - $t0 < 4.5], ['timeout', true, true], 'B04 timeout, within the 3 s client limit');
            sleep(3);
        });
    }],
    'C' => ['title' => 'wa_media: one row per message — and no photo or document recorded on this release', 'run' => function (QfCtx $ctx): void {
        vm_load($ctx);
        $evo = qf_fake_evo();
        $ctx->run('C01 control: recorded once, linked to its stored message', function () use ($ctx, $evo) {
            $b = vm_book($ctx, vm_cfg($evo));
            $v = vm_voice($b, '256771301203');
            $again = InboundMedia::record($b['pdo'], $v['conv'], InboundMedia::fromEvoMessage(vm_envelope('256771301203', ['id' => $v['wa']])), 'Sales-UG', 'sales', $v['wa']);
            $row = vm_row($b, $v['media']);
            $ctx->eq([$again, (int)$row['message_id'], $row['status'], (int)vm_q($b, 'SELECT COUNT(*) n FROM wa_media')[0]['n']], [null, $v['msg'], 'pending', 1],
                     'C01 a second record of the same message: null, one row, linked');
            $ctx->eq([InboundMedia::record($b['pdo'], 0, InboundMedia::fromEvoMessage(vm_envelope('256771301203')), 'i', 'sales', 'MF-C01x'),
                      InboundMedia::record($b['pdo'], $v['conv'], InboundMedia::fromEvoMessage(vm_envelope('256771301203')), 'i', 'sales', '')], [null, null],
                     'C01 no conversation, or no message id: nothing recorded');
        });
        $ctx->run('C02 photos and documents: not recorded on this release; a row for one would never be fetched', function () use ($ctx, $evo) {
            qf_http('GET', $evo . '/__test/reset');
            $b = vm_book($ctx, vm_cfg($evo));
            $cs = new ConversationService($b['data'], $b['pdo']);
            $out = [];
            foreach (['image', 'document'] as $kind) {
                $env = vm_envelope('256771301204', ['kind' => $kind]);
                $conv = (int)$cs->importEvoMessage($env, 'sales');
                $id = (int)InboundMedia::record($b['pdo'], $conv, InboundMedia::fromEvoMessage($env), 'Sales-UG', 'sales', (string)$env['key']['id']);
                (new EventBus($b['pdo']))->emit('ai.media', 'media', $id, ['media_id' => $id], 3, 'test');
                $out[] = [vm_row($b, $id)['status'], vm_row($b, $id)['failure_reason']];
            }
            vm_run($b, '');
            $ctx->eq([$out, count((array)(vm_evo_state($evo)['media_fetch_calls'] ?? [])), array_column(vm_q($b, 'SELECT status FROM wa_media ORDER BY id'), 'status')],
                     [[['unsupported', 'unsupported_kind'], ['unsupported', 'unsupported_kind']], 0, ['unsupported', 'unsupported']],
                     'C02 each settled unsupported at once; the worker fetches neither');
        });
    }],
    'D' => ['title' => 'The worker\'s own lock', 'run' => function (QfCtx $ctx): void {
        vm_load($ctx);
        $evo = qf_fake_evo();
        $ctx->run('D01 control: alone it runs; with its lock held elsewhere it does not', function () use ($ctx, $evo) {
            qf_http('GET', $evo . '/__test/reset');
            $b = vm_book($ctx, vm_cfg($evo));
            $v = vm_voice($b, '256771301205');
            vm_media($evo, $v['wa'], ogg_opus(['seconds' => 1.0]));
            $fp = fopen($b['data'] . '/MediaWorker.lock', 'w+');
            flock($fp, LOCK_EX | LOCK_NB);
            $r = vm_run($b, '');
            $ctx->eq([$r['run']['skipped'] ?? false, vm_row($b, $v['media'])['status']], [true, 'pending'], 'D01 its lock held: skipped, nothing fetched');
            flock($fp, LOCK_UN); fclose($fp);
            $r2 = vm_run($b, '');
            $ctx->eq([$r2['run']['processed'] ?? 0, vm_row($b, $v['media'])['status']], [1, 'fetched'], 'D01 released: it runs');
            $ctx->t(!file_exists($b['data'] . '/AiReplyWorker.lock'), 'D01 its lock is its own, not the reply worker\'s');
        });
    }],
];

qf_run_sections($ctx, $SECTIONS, $O['sections']);

if ($O['mutants']) {
    $F = 'lib/MediaFetcher.php'; $I = 'lib/InboundMedia.php';
    qf_mutants($ctx, __FILE__, [
        ['label' => 'MF1 the announced size ignored', 'file' => $F, 'old' => '        if (is_numeric($declared) && (int)$declared > $max) {', 'new' => '        if (false) {',
         'sections' => 'B', 'cases' => 'B02', 'pattern' => '/B02 /'],
        ['label' => 'MF2 the base64 decode not strict', 'file' => $F, 'old' => '        $bytes = base64_decode($b64, true);', 'new' => '        $bytes = base64_decode($b64);',
         'sections' => 'B', 'cases' => 'B03', 'pattern' => '/B03 not base64/'],
        ['label' => 'MF3 the type allow-list ignored', 'file' => $F, 'old' => '        if (!MediaPolicy::allowed($kind, $mime)) {', 'new' => '        if (false) {',
         'sections' => 'B', 'cases' => 'B03', 'pattern' => '/B03 a type off/'],
        ['label' => 'MF4 a 4xx retried', 'file' => $F, 'old' => "        if (\$http >= 400 && \$http < 500) return self::refuse('fetch_failed', 'HTTP ' . \$http, false);",
         'new' => "        if (\$http >= 400 && \$http < 500) return self::refuse('fetch_failed', 'HTTP ' . \$http, true);", 'sections' => 'B', 'cases' => 'B03', 'pattern' => '/B03 a 404/'],
        ['label' => 'MF5 a second record of the same message not ignored', 'file' => $I, 'old' => "        \$st = \$pdo->prepare('INSERT OR IGNORE INTO wa_media", 'new' => "        \$st = \$pdo->prepare('INSERT INTO wa_media",
         'sections' => 'C', 'cases' => 'C01', 'pattern' => '/C01 /'],
        ['label' => 'MF6 ptt not read', 'file' => $I, 'old' => "            'ptt'            => \$kind === 'audio' && !empty(\$part['ptt']),", 'new' => "            'ptt'            => false,",
         'sections' => 'A', 'cases' => 'A01|A03', 'pattern' => '/A0[13] /'],
        ['label' => 'MF7 the wrappers not read through', 'file' => $I, 'old' => "        \$m = self::unwrap(\$m);\n        \$type = '';", 'new' => "        \$type = '';",
         'sections' => 'A', 'cases' => 'A03', 'pattern' => '/A03 /'],
        ['label' => 'MF8 photos and documents fetched (the development tree\'s kinds)', 'file' => 'lib/MediaPolicy.php', 'old' => "    public const SUPPORTED_KINDS = ['audio'];",
         'new' => "    public const SUPPORTED_KINDS = ['audio', 'image', 'document'];", 'sections' => 'B,C', 'cases' => 'B02|C02', 'pattern' => '/B02 |C02 /'],
    ], $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

<?php
declare(strict_types=1);
/**
 * test_voice_duration.php — 5.18.104 (root docs/73 §H.3.8; R-15, S2-23): lib/VoiceDuration.php counts a voice note's
 * duration from its Opus packets, so the announced seconds and the container's last granule — both written by the
 * sender's client — can never be what the budget trusts; and it refuses a file whose container disagrees with its
 * packets by more than 2 s. Pure: every file is built in memory by tests/fixtures/ogg_opus.php.
 *
 * Sections A (well-formed Ogg/Opus), B (refused: not Ogg/Opus, malformed), C (the container against the packets), each
 * beginning with its positive control; then the weakened copies of the guards inside measure() (VD1-VD8), each judged by
 * this file run on the copy with the sections it names. The provider-facing copies (V19-V21, V41) are judged in
 * tests/test_voice_media.php section D, where they show what reaches OpenAI.
 *
 *   cd tests && DN_VAULT_FILE=$(mktemp -d)/v.json php test_voice_duration.php [--root=DIR] [--sections=A] [--no-mutants]
 */
define('QF_TZ', 'Africa/Kampala');
require_once __DIR__ . '/voice_media/_lib.php';
date_default_timezone_set('UTC');

$O   = qf_options();
$own = qf_own_tmp('vd');
$ctx = new QfCtx($O['root'], $own);
$ctx->cases = $O['cases'];

$m = function (string $bytes, string $mime = 'audio/ogg') use ($ctx): array {
    require_once $ctx->tree() . '/lib/MediaPolicy.php';
    require_once $ctx->tree() . '/lib/MediaBlob.php';
    require_once $ctx->tree() . '/lib/VoiceDuration.php';
    return VoiceDuration::measure(new MediaBlob($bytes, $mime, 'audio'));
};
$short = function (array $r): array { return [$r['ok'], $r['reason'], $r['detail']]; };

$SECTIONS = [
    'A' => ['title' => 'Well-formed Ogg/Opus: the duration is the packets\', less the pre-skip', 'run' => function (QfCtx $ctx) use ($m, $short): void {
        $ctx->run('A01 control: 3 s of 20 ms CELT packets', function () use ($ctx, $m) {
            $r = $m(ogg_opus(['seconds' => 3.0]));
            $ctx->eq([$r['ok'], $r['packets'], $r['seconds'], $r['granule_seconds']], [true, 150, round(3.0 - 312 / 48000, 3), 3.0], 'A01 150 packets, 3 s less the 312-sample pre-skip');
        });
        $ctx->run('A02 every frame family and count code (RFC 6716 §3.1)', function () use ($ctx, $m) {
            foreach ([['SILK 60 ms (config 3)', 3 << 3, 60.0, 2.4], ['SILK 10 ms (config 8)', 8 << 3, 10.0, 1.0],
                      ['Hybrid 20 ms (config 13)', 13 << 3, 20.0, 2.0], ['CELT 2.5 ms (config 16)', 16 << 3, 2.5, 0.5],
                      ['CELT 10 ms, two frames (code 1)', (18 << 3) | 1, 20.0, 2.0], ['CELT 20 ms, two frames (code 2)', (19 << 3) | 2, 40.0, 2.0]] as [$label, $toc, $ms, $secs]) {
                $r = $m(ogg_opus(['seconds' => $secs, 'frame_ms' => $ms, 'toc' => $toc]));
                $ctx->eq([$r['ok'], $r['seconds']], [true, round($secs - 312 / 48000, 3)], 'A02 ' . $label);
            }
            $r = $m(ogg_opus(['seconds' => 2.4, 'frame_ms' => 60.0, 'toc' => (19 << 3) | 3, 'frames_byte' => 3]));
            $ctx->eq([$r['ok'], $r['seconds']], [true, round(2.4 - 312 / 48000, 3)], 'A02 code 3: three 20 ms frames from the count byte');
        });
        $ctx->run('A03 packets that span pages and lacing values of 255', function () use ($ctx, $m) {
            $r = $m(ogg_opus(['seconds' => 2.0, 'packet_bytes' => 600, 'packets_per_page' => 1]));
            $ctx->eq([$r['ok'], $r['packets']], [true, 100], 'A03 600-byte packets (three lacing values each) rebuilt whole');
            $r = $m(ogg_opus(['seconds' => 2.0, 'packets_per_page' => 255, 'packet_bytes' => 1]));
            $ctx->eq([$r['ok'], $r['packets']], [true, 100], 'A03 255 one-byte packets on a page');
        });
        $ctx->run('A04 a pre-skip of 0 and the maximum', function () use ($ctx, $m) {
            $ctx->eq($m(ogg_opus(['seconds' => 1.0, 'pre_skip' => 0]))['seconds'], 1.0, 'A04 no pre-skip: 1 s');
            $ctx->eq($m(ogg_opus(['seconds' => 2.0, 'pre_skip' => 48000]))['seconds'], 1.0, 'A04 one second of pre-skip: 1 s left');
        });
    }],
    'B' => ['title' => 'Refused: not Ogg/Opus is unsupported_audio, malformed is invalid_audio', 'run' => function (QfCtx $ctx) use ($m, $short): void {
        $ctx->run('B01 control: a well-formed file is accepted', function () use ($ctx, $m, $short) {
            $ctx->eq($short($m(ogg_opus(['seconds' => 1.0]))), [true, '', ''], 'B01 ok');
        });
        $ctx->run('B02 not Ogg/Opus', function () use ($ctx, $m, $short) {
            $ctx->eq($short($m("ID3\x03\x00" . str_repeat('x', 100), 'audio/mpeg')), [false, 'unsupported_audio', 'not_ogg'], 'B02 an MP3');
            $ctx->eq($short($m(ogg_opus(['head' => 'vorbis']))), [false, 'unsupported_audio', 'not_opus'], 'B02 Ogg Vorbis');
            $ctx->eq($short($m(ogg_opus(['head' => 'speex']))), [false, 'unsupported_audio', 'not_opus'], 'B02 Ogg Speex');
            $ctx->eq($short($m('OggS')), [false, 'unsupported_audio', 'not_ogg'], 'B02 four bytes');
            $head = 'OpusHead' . chr(2) . chr(1) . pack('v', 312) . pack('V', 48000) . pack('v', 0) . chr(0);
            $ctx->eq($short($m(ogg_opus(['head' => $head]))), [false, 'unsupported_audio', 'not_opus'], 'B02 an OpusHead of another version');
            $head0 = 'OpusHead' . chr(1) . chr(0) . pack('v', 312) . pack('V', 48000) . pack('v', 0) . chr(0);
            $ctx->eq($short($m(ogg_opus(['head' => $head0]))), [false, 'unsupported_audio', 'not_opus'], 'B02 an OpusHead with no channel');
        });
        $ctx->run('B03 malformed', function () use ($ctx, $m, $short) {
            $ctx->eq($short($m(ogg_opus(['no_tags' => true]))), [false, 'invalid_audio', 'no_tags'], 'B03 no OpusTags');
            $ctx->eq($short($m(ogg_opus(['second_serial' => true]))), [false, 'invalid_audio', 'second_stream'], 'B03 a second stream');
            $ctx->eq($short($m(ogg_opus(['truncate' => 5]))), [false, 'invalid_audio', 'truncated'], 'B03 truncated');
            $ctx->eq($short($m(ogg_opus(['toc' => (31 << 3) | 3, 'frames_byte' => 0]))), [false, 'invalid_audio', 'bad_packet'], 'B03 a code-3 packet with no frames');
            $ctx->eq($short($m(ogg_opus(['toc' => (31 << 3) | 3, 'frames_byte' => 49]))), [false, 'invalid_audio', 'bad_packet'], 'B03 a code-3 packet with 49 frames');
            $ctx->eq($short($m(ogg_opus(['seconds' => 2.4, 'frame_ms' => 120.0, 'toc' => (3 << 3) | 3, 'frames_byte' => 3]))), [false, 'invalid_audio', 'bad_packet'],
                     'B03 a packet over 120 ms (three 60 ms frames)');
            $ctx->eq($short($m(ogg_opus(['seconds' => 1.0, 'packet_bytes' => 1, 'toc' => (31 << 3) | 3, 'no_count_byte' => true]))), [false, 'invalid_audio', 'bad_packet'], 'B03 a code-3 packet with no count byte');
            $bytes = ogg_opus(['seconds' => 1.0]);
            $ctx->eq($short($m(substr_replace($bytes, chr(1), 4, 1))), [false, 'invalid_audio', 'bad_version'], 'B03 an Ogg page of another version');
            $ctx->eq($short($m($bytes . 'JUNK-AFTER-THE-LAST-PAGE')), [false, 'invalid_audio', 'bad_page'], 'B03 bytes after the last page');
            $ctx->eq($short($m(ogg_opus(['seconds' => 0.0]))), [false, 'invalid_audio', 'no_audio'], 'B03 headers and no audio');
        });
        $ctx->run('B04 too many pages', function () use ($ctx, $m, $short) {
            $ctx->eq($short($m(ogg_opus(['seconds' => 401.0, 'packets_per_page' => 1, 'packet_bytes' => 1]))), [false, 'invalid_audio', 'too_many_pages'], 'B04 20,051 pages');
        });
    }],
    'C' => ['title' => 'The container must agree with its packets to within 2 s (S2-23)', 'run' => function (QfCtx $ctx) use ($m, $short): void {
        $ctx->run('C01 control: within 2 s either way is accepted', function () use ($ctx, $m, $short) {
            $ctx->eq($short($m(ogg_opus(['seconds' => 10.0, 'granule_seconds' => 11.9]))), [true, '', ''], 'C01 the container 1.9 s longer');
            $ctx->eq($short($m(ogg_opus(['seconds' => 10.0, 'granule_seconds' => 8.1]))), [true, '', ''], 'C01 the container 1.9 s shorter');
        });
        $ctx->run('C02 the container claims 2 h over 60 s of packets, or 5 s over 300 s', function () use ($ctx, $m, $short) {
            $r = $m(ogg_opus(['seconds' => 60.0, 'granule_seconds' => 7200]));
            $ctx->eq([$r['ok'], $r['reason'], $r['detail'], $r['seconds']], [false, 'invalid_audio', 'duration_mismatch', round(60 - 312 / 48000, 3)], 'C02 2 h claimed, 60 s carried');
            $r2 = $m(ogg_opus(['seconds' => 300.0, 'granule_seconds' => 5, 'packet_bytes' => 1, 'packets_per_page' => 255]));
            $ctx->eq([$r2['ok'], $r2['detail']], [false, 'duration_mismatch'], 'C02 5 s claimed, 300 s carried');
            $ctx->eq($short($m(ogg_opus(['seconds' => 10.0, 'granule_seconds' => 12.5]))), [false, 'invalid_audio', 'duration_mismatch'], 'C02 2.5 s apart');
        });
        $ctx->run('C03 no granule on the audio: invalid', function () use ($ctx, $m) {
            $r = $m(ogg_opus(['seconds' => 3.0, 'no_granule' => true]));
            $ctx->eq([$r['ok'], $r['reason']], [false, 'invalid_audio'], 'C03 the audio pages carry no granule');
        });
    }],
];

qf_run_sections($ctx, $SECTIONS, $O['sections']);

if ($O['mutants']) {
    $VD = 'lib/VoiceDuration.php';
    qf_mutants($ctx, __FILE__, [
        ['label' => 'VD1 the pre-skip not subtracted', 'file' => $VD, 'old' => '        $seconds = max(0.0, $ms / 1000 - $preSkip / self::OPUS_RATE);',
         'new' => '        $seconds = max(0.0, $ms / 1000);', 'sections' => 'A', 'cases' => 'A01|A04', 'pattern' => '/A0[14] /'],
        ['label' => 'VD2 the code-3 frame count ignored (one frame)', 'file' => $VD, 'old' => "            \$frames = ord(\$pkt[1]) & 0x3F;\n            if (\$frames < 1 || \$frames > 48) return null;",
         'new' => '            $frames = 1;', 'sections' => 'A,B', 'cases' => 'A02|B03', 'pattern' => '/A02 code 3|B03 a code-3/'],
        ['label' => 'VD3 a second stream accepted', 'file' => $VD, 'old' => "            elseif (\$pageSerial !== \$serial) return self::no('invalid_audio', 'second_stream');\n",
         'new' => '', 'sections' => 'B', 'cases' => 'B03', 'pattern' => '/B03 a second stream/'],
        ['label' => 'VD4 a packet over 120 ms accepted', 'file' => $VD, 'old' => '        if ($total > self::MAX_PACKET_MS) return null;',
         'new' => '', 'sections' => 'B', 'cases' => 'B03', 'pattern' => '/B03 a packet over/'],
        ['label' => 'VD5 a lacing value of 255 ending a packet', 'file' => $VD, 'old' => "                if (\$l < 255) { \$packets[] = \$cur; \$cur = ''; \$open = false; }",
         'new' => "                { \$packets[] = \$cur; \$cur = ''; \$open = false; }", 'sections' => 'A', 'cases' => 'A03', 'pattern' => '/A03 /'],
        ['label' => 'VD6 the page limit removed', 'file' => $VD, 'old' => "            if (++\$pages > self::MAX_PAGES) return self::no('invalid_audio', 'too_many_pages');",
         'new' => '            ++$pages;', 'sections' => 'B', 'cases' => 'B04', 'pattern' => '/B04 /'],
        ['label' => 'VD7 the mismatch tolerance widened to a minute', 'file' => $VD, 'old' => '    public const MAX_MISMATCH_S      = 2.0;',
         'new' => '    public const MAX_MISMATCH_S      = 60.0;', 'sections' => 'C', 'cases' => 'C02', 'pattern' => '/C02 2.5 s/'],
        ['label' => 'VD8 a truncated page read as whole', 'file' => $VD, 'old' => "            if (\$data + \$body > \$len) return self::no('invalid_audio', 'truncated');",
         'new' => '', 'sections' => 'B', 'cases' => 'B03', 'pattern' => '/B03 truncated/'],
    ], $O['only']);
}

printf("\n%d passed, %d failed\n", $ctx->pass, $ctx->fail);
exit($ctx->fail === 0 ? 0 : 1);

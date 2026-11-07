<?php
declare(strict_types=1);
/**
 * ████ TEST ONLY ████ — a synthetic Ogg/Opus voice note, built in memory (5.18.104, root docs/73 §H.3.8, §H.3.24).
 * No binary fixture is shipped: every file a voice test uses is made here from its options, so what a test feeds
 * lib/VoiceDuration.php, the media worker and the fake providers is visible in the test itself.
 *
 *   ogg_opus(['seconds' => 3.0])                         3 s of 20 ms CELT packets, 40 bytes each, pre-skip 312
 *   ogg_opus(['seconds' => 300, 'packet_bytes' => 1])    5 minutes of TOC-only packets (small enough to look like 5 s)
 *   ogg_opus(['seconds' => 60, 'granule_seconds' => 7200])   the container claims 2 h
 *   ogg_opus(['head' => 'vorbis'])  ['no_tags' => true]  ['second_serial' => true]  ['truncate' => 5]
 *   ogg_opus(['toc' => 0x03, 'frames_byte' => 0])        a code-3 packet with no frame count (invalid)
 *
 * Pages carry up to `packets_per_page` packets; a packet over 254 bytes spans lacing values of 255, across pages when
 * `split_packet` asks. The CRC is computed as RFC 3533 defines it (VoiceDuration does not verify it). Fictitious bytes:
 * the payload is a repeated marker, so a test can search a log or the disk for it.
 */

/** The marker every synthetic packet's payload repeats (after its TOC byte). */
const OGG_OPUS_MARKER = 'DN-VOICE-TEST-AUDIO/';

function ogg_opus(array $o = []): string
{
    $seconds  = (float)($o['seconds'] ?? 3.0);
    $frameMs  = (float)($o['frame_ms'] ?? 20.0);
    $pbytes   = max(1, (int)($o['packet_bytes'] ?? 40));
    $preSkip  = (int)($o['pre_skip'] ?? 312);
    $perPage  = max(1, (int)($o['packets_per_page'] ?? 50));
    $serial   = (int)($o['serial'] ?? 0x5A17);
    $toc      = (int)($o['toc'] ?? ((31 << 3) | 0));   // CELT fullband, 20 ms, one frame
    $samplesPerPacket = (int)round($frameMs * 48);

    $packets = [];
    $head = (string)($o['head'] ?? 'opus');
    if ($head === 'opus') {
        $packets[] = 'OpusHead' . chr(1) . chr((int)($o['channels'] ?? 1)) . pack('v', $preSkip) . pack('V', 48000) . pack('v', 0) . chr(0);
    } elseif ($head === 'vorbis') {
        $packets[] = "\x01vorbis" . pack('V', 0) . chr(1) . pack('V', 48000) . pack('V', 0) . pack('V', 64000) . pack('V', 0) . chr(0xB8) . chr(1);
    } elseif ($head === 'speex') {
        $packets[] = 'Speex   1.2rc1' . str_repeat("\0", 66);
    } else {
        $packets[] = (string)$head;
    }
    if (empty($o['no_tags'])) $packets[] = 'OpusTags' . pack('V', 8) . 'DN-TESTS' . pack('V', 0);

    $n = (int)round($seconds * 1000 / $frameMs);
    $fill = substr(str_repeat(OGG_OPUS_MARKER, (int)ceil($pbytes / strlen(OGG_OPUS_MARKER)) + 1), 0, max(0, $pbytes - 1));
    for ($i = 0; $i < $n; $i++) {
        $p = chr($toc);
        if (($toc & 3) === 3 && empty($o['no_count_byte'])) $p .= chr((int)($o['frames_byte'] ?? 1));
        $packets[] = substr($p . $fill, 0, max(strlen($p), $pbytes));
    }

    // Pages: the two headers on pages of their own (granule 0), then the audio.
    $pages = [];
    $seq = 0;
    $granule = $preSkip;
    $pages[] = ogg_opus_page($serial, $seq++, 0x02, 0, [$packets[0]]);
    $rest = array_slice($packets, 1);
    if (empty($o['no_tags'])) {
        $pages[] = ogg_opus_page($serial, $seq++, 0x00, 0, [$rest[0]]);
        $rest = array_slice($rest, 1);
    }
    $chunks = array_chunk($rest, $perPage);
    $last = count($chunks) - 1;
    foreach ($chunks as $k => $chunk) {
        $granule += $samplesPerPacket * count($chunk);
        $g = $granule;
        if ($k === $last && isset($o['granule_seconds'])) $g = $preSkip + (int)round((float)$o['granule_seconds'] * 48000);
        if (!empty($o['no_granule'])) $g = -1;
        $pages[] = ogg_opus_page($serial, $seq++, $k === $last ? 0x04 : 0x00, $g, $chunk);
    }
    if (!empty($o['second_serial'])) $pages[] = ogg_opus_page($serial + 1, 0, 0x02, 0, ["\x01other"]);
    $bytes = implode('', $pages);
    if (!empty($o['truncate'])) $bytes = substr($bytes, 0, -(int)$o['truncate']);
    return $bytes;
}

/** One Ogg page holding whole packets (each laced in 255s plus a remainder). */
function ogg_opus_page(int $serial, int $seq, int $type, int $granule, array $packets): string
{
    $lacing = ''; $body = '';
    foreach ($packets as $p) {
        $l = strlen($p);
        while ($l >= 255) { $lacing .= chr(255); $l -= 255; }
        $lacing .= chr($l);
        $body .= $p;
    }
    if (strlen($lacing) > 255) throw new \LengthException('ogg_opus: too many segments on one page; lower packets_per_page');
    $hdr = 'OggS' . chr(0) . chr($type) . pack('P', $granule) . pack('V', $serial) . pack('V', $seq) . pack('V', 0)
         . chr(strlen($lacing)) . $lacing;
    $page = $hdr . $body;
    $crc = ogg_opus_crc($page);
    return substr($page, 0, 22) . pack('V', $crc) . substr($page, 26);
}

/** RFC 3533's CRC-32: polynomial 0x04c11db7, no reflection, initial 0, no final xor. */
function ogg_opus_crc(string $data): int
{
    static $t = null;
    if ($t === null) {
        $t = [];
        for ($i = 0; $i < 256; $i++) {
            $r = $i << 24;
            for ($j = 0; $j < 8; $j++) $r = ($r & 0x80000000) ? ((($r << 1) ^ 0x04c11db7) & 0xFFFFFFFF) : (($r << 1) & 0xFFFFFFFF);
            $t[$i] = $r;
        }
    }
    $crc = 0;
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $crc = ((($crc << 8) & 0xFFFFFFFF) ^ $t[(($crc >> 24) & 0xFF) ^ ord($data[$i])]);
    }
    return $crc & 0xFFFFFFFF;
}

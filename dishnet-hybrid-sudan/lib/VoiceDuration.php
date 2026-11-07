<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaBlob.php';

/**
 * VoiceDuration — how long a voice note plays, counted from its Opus packets, so a sender cannot fake it
 * (5.18.104, root docs/73 §H.3.8; R-15, S2-23).
 *
 * The announced `seconds` and the container's last granule are both written by the sender's client, so a billing cap
 * that trusted either would not be a cap. What a decoder plays is the sum of the Opus packets' frame durations
 * (RFC 6716 §3.1), so that is what is counted; and because whether the provider measures the decoded frames or the
 * container is not established, the container must agree with the packets to within 2 s, or the file is refused
 * before any provider sees it.
 *
 * Ogg pages are read from offset 0 (RFC 3533): the capture pattern, version 0, the header type, the granule, the
 * serial, the sequence, the CRC (not verified), the lacing table. Packets are rebuilt from the lacing values (255
 * continues, across pages too). Only the stream of the first page counts; a second serial is refused. Packet 1 must be
 * OpusHead (version 1, at least one channel, the pre-skip at offset 10); packet 2 OpusTags; every later packet's TOC
 * byte gives its frames. Pure: no I/O, no clock, no configuration. PHP 7.4 compatible.
 */
final class VoiceDuration
{
    public const MAX_PAGES           = 20000;
    public const MAX_PACKET_MS       = 120.0;   // RFC 6716 §3.2.5
    public const MAX_MISMATCH_S      = 2.0;
    private const OPUS_RATE          = 48000;

    /**
     * @return array{ok:bool, reason:string, detail:string, seconds:float, packets:int, granule_seconds:float}
     *         reason '' when ok; otherwise unsupported_audio (not Ogg/Opus) or invalid_audio (malformed, or the
     *         container disagreeing with its packets: detail duration_mismatch)
     */
    public static function measure(MediaBlob $b): array
    {
        $buf = (string)$b->bytes;
        $len = strlen($buf);
        if ($len < 27 || substr($buf, 0, 4) !== 'OggS') return self::no('unsupported_audio', 'not_ogg');

        $off = 0; $pages = 0; $serial = null; $packets = []; $cur = ''; $open = false;
        $lastGranule = null;
        while ($off < $len) {
            if (++$pages > self::MAX_PAGES) return self::no('invalid_audio', 'too_many_pages');
            if ($off + 27 > $len || substr($buf, $off, 4) !== 'OggS') return self::no('invalid_audio', 'bad_page');
            if (ord($buf[$off + 4]) !== 0) return self::no('invalid_audio', 'bad_version');
            $granule = unpack('P', substr($buf, $off + 6, 8))[1];
            $pageSerial = unpack('V', substr($buf, $off + 14, 4))[1];
            $nseg = ord($buf[$off + 26]);
            if ($off + 27 + $nseg > $len) return self::no('invalid_audio', 'truncated');
            $lacing = substr($buf, $off + 27, $nseg);
            $body = 0;
            for ($i = 0; $i < $nseg; $i++) $body += ord($lacing[$i]);
            $data = $off + 27 + $nseg;
            if ($data + $body > $len) return self::no('invalid_audio', 'truncated');
            if ($serial === null) $serial = $pageSerial;
            elseif ($pageSerial !== $serial) return self::no('invalid_audio', 'second_stream');

            $p = $data;
            for ($i = 0; $i < $nseg; $i++) {
                $l = ord($lacing[$i]);
                $cur .= substr($buf, $p, $l);
                $p += $l;
                $open = true;
                if ($l < 255) { $packets[] = $cur; $cur = ''; $open = false; }
            }
            if ($granule !== -1) $lastGranule = $granule;
            $off = $data + $body;
        }
        if ($open) return self::no('invalid_audio', 'truncated');
        if (count($packets) < 1) return self::no('invalid_audio', 'no_packets');

        $head = $packets[0];
        if (strlen($head) < 19 || substr($head, 0, 8) !== 'OpusHead') return self::no('unsupported_audio', 'not_opus');
        if (ord($head[8]) !== 1 || ord($head[9]) < 1) return self::no('unsupported_audio', 'not_opus');
        $preSkip = unpack('v', substr($head, 10, 2))[1];
        if (count($packets) < 2 || substr($packets[1], 0, 8) !== 'OpusTags') return self::no('invalid_audio', 'no_tags');

        $ms = 0.0; $n = 0;
        for ($k = 2, $c = count($packets); $k < $c; $k++) {
            $d = self::packetMs($packets[$k]);
            if ($d === null) return self::no('invalid_audio', 'bad_packet');
            $ms += $d; $n++;
        }
        if ($n === 0) return self::no('invalid_audio', 'no_audio');
        if ($lastGranule === null) return self::no('invalid_audio', 'no_granule');

        $seconds = max(0.0, $ms / 1000 - $preSkip / self::OPUS_RATE);
        $granuleSeconds = ($lastGranule - $preSkip) / self::OPUS_RATE;
        $out = ['ok' => true, 'reason' => '', 'detail' => '', 'seconds' => round($seconds, 3), 'packets' => $n,
                'granule_seconds' => round((float)$granuleSeconds, 3)];
        if (abs($granuleSeconds - $seconds) > self::MAX_MISMATCH_S) {
            return ['ok' => false, 'reason' => 'invalid_audio', 'detail' => 'duration_mismatch'] + $out;
        }
        return $out;
    }

    /** One Opus packet's duration in ms from its TOC byte (RFC 6716 §3.1), or null when it is not a valid packet. */
    private static function packetMs(string $pkt): ?float
    {
        $l = strlen($pkt);
        if ($l < 1) return null;
        $toc = ord($pkt[0]);
        $config = $toc >> 3;
        if ($config <= 11)      $frame = [10.0, 20.0, 40.0, 60.0][$config % 4];   // SILK
        elseif ($config <= 15)  $frame = [10.0, 20.0][$config % 2];               // Hybrid
        else                    $frame = [2.5, 5.0, 10.0, 20.0][$config % 4];     // CELT
        $code = $toc & 3;
        if ($code === 0)      $frames = 1;
        elseif ($code <= 2)   $frames = 2;
        else {
            if ($l < 2) return null;
            $frames = ord($pkt[1]) & 0x3F;
            if ($frames < 1 || $frames > 48) return null;
        }
        $total = $frame * $frames;
        if ($total > self::MAX_PACKET_MS) return null;
        return $total;
    }

    private static function no(string $reason, string $detail): array
    {
        return ['ok' => false, 'reason' => $reason, 'detail' => $detail, 'seconds' => 0.0, 'packets' => 0, 'granule_seconds' => 0.0];
    }
}

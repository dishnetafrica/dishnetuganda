<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/DocumentDeadline.php';

/**
 * PdfReader — the FACTS of a PDF (Slice 4a) and, since Slice 4b, its TEXT LAYER (Batch 4; docs/58 §3, §6.2, §13 D-1 = P-1).
 *
 * facts(): is it encrypted; how many pages; does it carry a text layer at all (a font resource, or text operators in a content
 * stream) or is it image-only (scanned). Streams are inflated only to look for those markers and nothing inflated is kept.
 *
 * text(): the in-house reader the operator chose (P-1), written for this purpose and for nothing else — no library, no process,
 * no file, no socket, in this process, bounded everywhere:
 *   - objects are found by scanning for "n g obj", the LAST definition in the file winning (an incremental update appends),
 *     and object streams (/Type /ObjStm) are opened so a PDF 1.5+ file whose catalogue lives inside one still reads;
 *   - FlateDecode (zlib, under zlib's own cap), the PNG predictors xref and object streams use, ASCIIHexDecode and
 *     ASCII85Decode. ANY other filter on a stream that must be read is a refusal (unsupported_document) — never a guess;
 *   - the page tree is walked from /Root → /Pages, /Resources inherited, cycles and depth refused, the first N pages read;
 *   - the content stream's text operators (Tj TJ ' ") are decoded through the font the stream selected: a ToUnicode CMap
 *     where there is one, else the simple-font encodings (WinAnsi, MacRoman, Standard) with /Differences; Form XObjects are
 *     followed to a fixed depth; inline images are skipped; nothing is ever evaluated or executed;
 *   - a glyph the reader cannot name is COUNTED, never invented: above a small share of such glyphs the text is not "seen
 *     whole" and the classifier sends the document to a person (classification_incomplete) rather than the assistant.
 * The deadline is asked between objects, between pages and every few thousand operators. PHP 7.4 compatible.
 */
final class PdfReader
{
    private const MAX_CONTENT_STREAMS = 64;
    private const MAX_TOTAL_INFLATED  = 64 * 1024 * 1024;

    // Slice 4b: every loop has a number.
    private const MAX_OBJECT_STREAMS  = 512;        // /Type /ObjStm containers opened
    private const MAX_TREE_NODES      = 20000;      // page-tree nodes visited
    private const MAX_TREE_DEPTH      = 64;
    private const MAX_FORM_DEPTH      = 8;          // nested Form XObjects
    private const MAX_FORMS           = 4000;       // Form XObjects drawn, per document
    private const MAX_OPERATORS       = 2000000;    // content-stream operators, per document
    private const MAX_FONTS           = 512;        // font decoders built
    private const MAX_CMAP_ENTRIES    = 65536;      // ToUnicode mappings per font
    private const MAX_STRING_TOKEN    = 65536;      // one string token
    private const MAX_OPERANDS        = 64;
    private const DEADLINE_EVERY_OPS  = 2000;
    private const UNMAPPED_SHARE_WHOLE = 0.10;      // more undecodable glyphs than this share: not seen whole

    private const WS    = " \t\r\n\x0C\x00";
    private const DELIM = "()<>[]{}/%";

    /** @var string */
    private $bytes;
    /** @var DocumentDeadline */
    private $deadline;
    /** @var array<int, array<int, array{pos:int, at:?int, stm:?int, off:?int}>> candidates per object number */
    private $index = [];
    /** @var array<int, mixed> parsed objects by number */
    private $cache = [];
    /** @var array<int, array{data:string, first:int}> opened object streams */
    private $objStm = [];
    /** @var int */
    private $inflated = 0;
    /** @var int */
    private $ops = 0;
    /** @var int */
    private $forms = 0;
    /** @var array<string, array|null> font decoders by key */
    private $fonts = [];
    /** @var int */
    private $glyphs = 0;
    /** @var int */
    private $unmapped = 0;
    /** @var array<int, bool> Form XObjects on the current drawing stack (cycle detection) */
    private $formStack = [];
    /** @var float|null text matrix y of the last Tm, for line breaks */
    private $lastY = null;
    /** @var float|null */
    private $lastX = null;
    /** @var int page leaves met while walking the tree, read or not */
    private $leaves = 0;

    private function __construct(string $bytes, DocumentDeadline $deadline)
    {
        $this->bytes = $bytes;
        $this->deadline = $deadline;
    }

    // ── Slice 4a: facts ───────────────────────────────────────────────────────────────────────────

    /**
     * @return array{version:string, encrypted:bool, pages_total:int, has_text_layer:bool, image_only:bool, objects:int, objects_capped:bool}
     * @throws DocumentRefused malformed_document
     */
    public static function facts(string $bytes, DocumentDeadline $deadline): array
    {
        $deadline->check('pdf header');
        $head = substr($bytes, 0, 1024);
        $hp = strpos($head, '%PDF-');
        if ($hp === false) throw new DocumentRefused('malformed_document', 'no PDF header');
        $version = preg_match('/%PDF-(\d\.\d)/', $head, $vm) === 1 ? $vm[1] : '';
        if (strpos($bytes, 'endobj') === false && strpos($bytes, 'xref') === false && strpos($bytes, '/Type') === false) {
            throw new DocumentRefused('malformed_document', 'no PDF objects');
        }

        $facts = ['version' => $version, 'encrypted' => false, 'pages_total' => 0, 'has_text_layer' => false, 'image_only' => false,
                  'objects' => 0, 'objects_capped' => false];
        // /Encrypt lives in a trailer or an xref-stream dictionary, never inside a compressed object stream.
        $facts['encrypted'] = preg_match('/\/Encrypt\s*(?:\d+\s+\d+\s+R|<<)/', $bytes) === 1;

        $pagesVisible = preg_match_all('/\/Type\s*\/Page(?![s\w])/', $bytes);
        $countMax = 0;
        if (preg_match_all('/\/Count\s+(\d{1,6})/', $bytes, $cm) > 0) {
            foreach ($cm[1] as $c) $countMax = max($countMax, (int)$c);
        }
        $fontSeen = preg_match('/\/Font\b/', $bytes) === 1;
        $imageSeen = preg_match('/\/Subtype\s*\/Image\b/', $bytes) === 1;
        $textOps = false;

        $objs = preg_match_all('/(?<![\d])(\d{1,9})\s+(\d{1,5})\s+obj\b/', $bytes, $om, PREG_OFFSET_CAPTURE);
        $facts['objects'] = (int)$objs;
        if ($objs > MediaPolicy::DOCUMENT_PDF_MAX_OBJECTS) { $facts['objects_capped'] = true; }
        $limit = min((int)$objs, MediaPolicy::DOCUMENT_PDF_MAX_OBJECTS);
        $contentStreams = 0; $totalInflated = 0; $n = strlen($bytes);
        for ($i = 0; $i < $limit; $i++) {
            if ($i % 50 === 0) $deadline->check('pdf object ' . $i);
            $at = (int)$om[0][$i][1];
            $next = $i + 1 < $objs ? (int)$om[0][$i + 1][1] : $n;
            $window = substr($bytes, $at, min($next - $at, 65536));
            $sp = strpos($window, 'stream');
            $ep = strpos($window, 'endobj');
            $dict = substr($window, 0, $sp !== false ? $sp : ($ep !== false ? $ep : strlen($window)));
            if (!$fontSeen && preg_match('/\/Font\b/', $dict) === 1) $fontSeen = true;
            if (!$imageSeen && preg_match('/\/Subtype\s*\/Image\b/', $dict) === 1) $imageSeen = true;
            if ($sp === false) continue;
            $isObjStm = preg_match('/\/Type\s*\/ObjStm\b/', $dict) === 1;
            if ($textOps && !$isObjStm) continue;   // text already found: only object streams are still worth opening, for their page counts
            if (preg_match('/\/Subtype\s*\/Image\b|\/Type\s*\/(XRef|Metadata|Font|FontDescriptor|EmbeddedFile)\b|\/FontFile\d?\b|\/Length1\b/', $dict) === 1) continue;
            if (!$isObjStm && $contentStreams >= self::MAX_CONTENT_STREAMS) continue;
            // The stream body: from after the "stream" keyword and its line end to "endstream".
            $bodyStart = $at + $sp + 6;
            if (substr($bytes, $bodyStart, 2) === "\r\n") $bodyStart += 2; elseif (substr($bytes, $bodyStart, 1) === "\n") $bodyStart += 1;
            $bodyEnd = strpos($bytes, 'endstream', $bodyStart);
            if ($bodyEnd === false || $bodyEnd > $next + 65536) continue;
            $len = $bodyEnd - $bodyStart;
            if ($len <= 0 || $len > MediaPolicy::DOCUMENT_PDF_MAX_STREAM_BYTES) continue;
            if ($totalInflated + MediaPolicy::DOCUMENT_PDF_MAX_STREAM_BYTES > self::MAX_TOTAL_INFLATED) break;
            $raw = substr($bytes, $bodyStart, $len);
            $flate = preg_match('/\/Filter\s*(?:\[\s*)?\/FlateDecode\b/', $dict) === 1;
            $plain = $flate ? self::inflate($raw, MediaPolicy::DOCUMENT_PDF_MAX_STREAM_BYTES) : (preg_match('/\/Filter\b/', $dict) === 1 ? null : $raw);
            if ($plain === null) continue;
            $totalInflated += strlen($plain);
            if ($isObjStm) {
                if (!$fontSeen && preg_match('/\/Font\b/', $plain) === 1) $fontSeen = true;
                if (!$imageSeen && preg_match('/\/Subtype\s*\/Image\b/', $plain) === 1) $imageSeen = true;
                $pagesVisible += preg_match_all('/\/Type\s*\/Page(?![s\w])/', $plain);
                if (preg_match_all('/\/Count\s+(\d{1,6})/', $plain, $cm2) > 0) { foreach ($cm2[1] as $c) $countMax = max($countMax, (int)$c); }
                continue;
            }
            $contentStreams++;
            if (preg_match('/\bBT\b/', $plain) === 1 && preg_match('/(?:\bTj\b|\bTJ\b|[^\\\\]\'\s|"\s)/', $plain) === 1) $textOps = true;
        }
        $facts['pages_total'] = min(99999, max($pagesVisible, $countMax));
        $facts['has_text_layer'] = $fontSeen || $textOps;
        $facts['image_only'] = !$facts['has_text_layer'] && $imageSeen;
        return $facts;
    }

    /** zlib (FlateDecode) with zlib's own cap; a raw-deflate fallback; null when neither reads it. Nothing is kept. */
    private static function inflate(string $raw, int $max): ?string
    {
        $t = ltrim($raw, "\r\n\t ");
        $p = @gzuncompress($t, $max);
        if ($p === false) $p = @gzinflate(substr($t, 2), $max);
        if ($p === false) $p = @gzinflate($t, $max);
        return $p === false ? null : $p;
    }

    /**
     * FlateDecode for the text path, fed to zlib in pieces so that a stream inflating BEYOND the cap is refused as too large
     * the moment it crosses it, while one zlib cannot read at all is refused as malformed. gzuncompress() cannot tell the
     * two apart (both come back false), which is why this exists.
     * @throws DocumentRefused too_large_document · malformed_document
     */
    private static function inflateBounded(string $raw, int $max): string
    {
        $t = ltrim($raw, "\r\n\t ");
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW] as $encoding) {
            $ctx = @inflate_init($encoding);
            if ($ctx === false) continue;
            $out = '';
            $ok = true;
            $ended = false;
            $n = strlen($t);
            for ($p = 0; $p < $n; $p += 65536) {
                $piece = @inflate_add($ctx, substr($t, $p, 65536), ZLIB_SYNC_FLUSH);
                if ($piece === false) { $ok = false; break; }
                $out = self::underCap($out . $piece, $max);
                if (inflate_get_status($ctx) === ZLIB_STREAM_END) { $ended = true; break; }
            }
            if ($ok) {
                if (!$ended) {
                    $tail = @inflate_add($ctx, '', ZLIB_FINISH);
                    if ($tail !== false) { $out = self::underCap($out . $tail, $max); $ended = inflate_get_status($ctx) === ZLIB_STREAM_END; }
                }
                if ($out !== '' || $ended) return $out;   // an empty stream that zlib closed cleanly is a valid, empty stream
            }
        }
        throw new DocumentRefused('malformed_document', 'pdf stream did not inflate');
    }

    /** The one place the inflate cap is enforced, so that removing it is one change and one test. */
    private static function underCap(string $out, int $max): string
    {
        if (strlen($out) > $max) throw new DocumentRefused('too_large_document', 'pdf stream inflates beyond ' . $max . ' bytes');
        return $out;
    }

    // ── Slice 4b: the text layer ──────────────────────────────────────────────────────────────────

    /**
     * The text of the first $maxPages pages, in the order the content streams draw it, lines broken where the text matrix
     * moves down. Call facts() first: an encrypted file is refused there and never reaches this.
     *
     * @return array{text:string, pages_total:int, pages_read:int, truncated:bool, glyphs:int, unmapped:int, forms:int}
     *         truncated: pages beyond the cap, characters beyond the cap, or too many glyphs the reader could not name
     * @throws DocumentRefused malformed_document · unsupported_document · too_large_document; DocumentTooSlow
     */
    public static function text(string $bytes, DocumentDeadline $deadline, int $maxPages, int $maxChars): array
    {
        $r = new self($bytes, $deadline);
        return $r->read(max(1, $maxPages), max(100, $maxChars));
    }

    private function read(int $maxPages, int $maxChars): array
    {
        $this->deadline->check('pdf index');
        $this->buildIndex();
        $this->openObjectStreams();
        $this->deadline->check('pdf catalogue');

        $pages = $this->pages($maxPages);
        $total = $pages['total'];
        $out = '';
        $read = 0;
        $truncated = $total > count($pages['pages']);
        $byteCap = $maxChars * 4 + 4096;   // UTF-8 worst case; the caller measures characters
        foreach ($pages['pages'] as $i => $page) {
            $this->deadline->check('pdf page ' . ($i + 1));
            $content = $this->pageContent($page['dict']);
            $resources = $this->dict($page['res']) ?? [];
            $this->lastY = null; $this->lastX = null; $this->formStack = [];
            $out .= $this->runContent($content, $resources, 0, $byteCap - strlen($out));
            $read++;
            if (strlen($out) >= $byteCap) { $truncated = true; break; }
            $out .= "\n";
        }
        if ($this->glyphs > 0 && $this->unmapped / $this->glyphs > self::UNMAPPED_SHARE_WHOLE) $truncated = true;
        if (mb_strlen($out) > $maxChars) { $out = mb_substr($out, 0, $maxChars); $truncated = true; }
        return ['text' => $out, 'pages_total' => $total, 'pages_read' => $read, 'truncated' => $truncated,
                'glyphs' => $this->glyphs, 'unmapped' => $this->unmapped, 'forms' => $this->forms];
    }

    // ── Objects ───────────────────────────────────────────────────────────────────────────────────

    private function buildIndex(): void
    {
        $n = preg_match_all('/(?<![\d])(\d{1,9})\s+(\d{1,5})\s+obj\b/', $this->bytes, $m, PREG_OFFSET_CAPTURE);
        if ($n === 0) throw new DocumentRefused('malformed_document', 'no PDF objects');
        if ($n > MediaPolicy::DOCUMENT_PDF_MAX_OBJECTS) throw new DocumentRefused('too_large_document', 'pdf objects ' . $n);
        for ($i = 0; $i < $n; $i++) {
            $num = (int)$m[1][$i][0];
            $pos = (int)$m[0][$i][1];
            $this->index[$num][] = ['pos' => $pos, 'at' => $pos + strlen($m[0][$i][0]), 'stm' => null, 'off' => null];
        }
    }

    /** Every /Type /ObjStm container: its contained objects join the index at the container's position. */
    private function openObjectStreams(): void
    {
        if (preg_match_all('/\/Type\s*\/ObjStm\b/', $this->bytes, $m, PREG_OFFSET_CAPTURE) === 0) return;
        $starts = [];
        foreach ($this->index as $num => $cands) foreach ($cands as $c) $starts[$c['pos']] = $num;
        ksort($starts);
        $positions = array_keys($starts);
        $opened = 0;
        foreach ($m[0] as $k => $hit) {
            if (++$opened > self::MAX_OBJECT_STREAMS) throw new DocumentRefused('too_large_document', 'pdf object streams ' . $opened);
            if ($k % 20 === 0) $this->deadline->check('pdf object stream ' . $k);
            $at = (int)$hit[1];
            // the object that starts before this marker
            $lo = 0; $hi = count($positions) - 1; $found = null;
            while ($lo <= $hi) { $mid = intdiv($lo + $hi, 2); if ($positions[$mid] <= $at) { $found = $positions[$mid]; $lo = $mid + 1; } else { $hi = $mid - 1; } }
            if ($found === null) continue;
            $num = $starts[$found];
            $obj = $this->object($num);
            if (!is_array($obj) || !isset($obj['stm']) || !isset($obj['d'])) continue;
            $d = $obj['d'];
            $count = (int)$this->num($this->resolve($d['N'] ?? null));
            $first = (int)$this->num($this->resolve($d['First'] ?? null));
            if ($count <= 0 || $first <= 0) continue;
            $data = $this->streamData($obj);
            if ($first > strlen($data)) continue;
            $this->objStm[$num] = ['data' => $data, 'first' => $first];
            $header = substr($data, 0, $first);
            if (preg_match_all('/(\d+)\s+(\d+)/', $header, $pairs) === 0) continue;
            $pairsN = min(count($pairs[1]), $count, MediaPolicy::DOCUMENT_PDF_MAX_OBJECTS);
            for ($i = 0; $i < $pairsN; $i++) {
                $this->index[(int)$pairs[1][$i]][] = ['pos' => $found, 'at' => null, 'stm' => $num, 'off' => (int)$pairs[2][$i]];
            }
        }
    }

    /** The object by number: the latest definition in the file that parses; null when it has none. */
    private function object(int $num)
    {
        if (array_key_exists($num, $this->cache)) return $this->cache[$num];
        $this->cache[$num] = null;   // a cycle through a broken reference resolves to nothing, not to a loop
        $cands = $this->index[$num] ?? [];
        usort($cands, function (array $a, array $b): int { return $b['pos'] <=> $a['pos']; });
        foreach ($cands as $c) {
            try {
                if ($c['stm'] !== null) {
                    $stm = $this->objStm[$c['stm']] ?? null;
                    if ($stm === null) continue;
                    $p = $stm['first'] + (int)$c['off'];
                    if ($p < 0 || $p >= strlen($stm['data'])) continue;
                    $v = $this->value($stm['data'], $p, strlen($stm['data']), false);
                } else {
                    $p = (int)$c['at'];
                    $end = min(strlen($this->bytes), $p + 16 * 1024 * 1024);
                    $v = $this->value($this->bytes, $p, $end, false);
                    if (is_array($v) && isset($v['d'])) {
                        $q = $p;
                        $t = $this->token($this->bytes, $q, $end, false);
                        if (is_array($t) && ($t['kw'] ?? '') === 'stream') {
                            $body = $q;
                            if (substr($this->bytes, $body, 2) === "\r\n") $body += 2; elseif (substr($this->bytes, $body, 1) === "\n") $body += 1;
                            $len = $this->num($this->resolve($v['d']['Length'] ?? null));
                            $len = is_int($len) || is_float($len) ? (int)$len : -1;
                            $ok = $len >= 0 && $body + $len <= strlen($this->bytes)
                               && preg_match('/\G\s*endstream/', $this->bytes, $em, 0, $body + $len) === 1;
                            if (!$ok) {
                                $es = strpos($this->bytes, 'endstream', $body);
                                if ($es === false) continue;
                                $len = $es - $body;
                                if (substr($this->bytes, $body + $len - 2, 2) === "\r\n") $len -= 2; elseif (substr($this->bytes, $body + $len - 1, 1) === "\n") $len -= 1;
                            }
                            $v['stm'] = ['at' => $body, 'len' => max(0, $len)];
                        }
                    }
                }
                if ($v === null || (is_array($v) && isset($v['kw']))) continue;
                $this->cache[$num] = $v;
                return $v;
            } catch (DocumentRefused $e) {
                throw $e;
            } catch (\Throwable $e) {
                continue;   // a candidate that does not parse is not this object
            }
        }
        return null;
    }

    /** Follows a reference (once, or through a chain of up to 32); a direct value is returned as it is. */
    private function resolve($v, int $hops = 0)
    {
        while (is_array($v) && isset($v['r'])) {
            if (++$hops > 32) return null;
            $v = $this->object((int)$v['r']);
        }
        return $v;
    }

    private function num($v)
    {
        return is_array($v) && isset($v['num']) ? $v['num'] : (is_int($v) || is_float($v) ? $v : null);
    }

    private function name($v): ?string
    {
        $v = $this->resolve($v);
        return is_array($v) && isset($v['n']) ? (string)$v['n'] : null;
    }

    private function dict($v): ?array
    {
        $v = $this->resolve($v);
        return is_array($v) && isset($v['d']) && is_array($v['d']) ? $v['d'] : null;
    }

    private function arr($v): ?array
    {
        $v = $this->resolve($v);
        return is_array($v) && isset($v['a']) && is_array($v['a']) ? $v['a'] : null;
    }

    // ── Streams and filters ───────────────────────────────────────────────────────────────────────

    /**
     * The decoded bytes of a stream object. FlateDecode (with the PNG predictors), ASCIIHexDecode and ASCII85Decode are the
     * filters this reader knows; any other filter on a stream it must read is a refusal. Image streams are never read.
     * @throws DocumentRefused unsupported_document · too_large_document · malformed_document
     */
    private function streamData(array $obj): string
    {
        $d = $obj['d'] ?? [];
        $len = (int)($obj['stm']['len'] ?? 0);
        if ($len > MediaPolicy::DOCUMENT_PDF_MAX_STREAM_BYTES) throw new DocumentRefused('too_large_document', 'pdf stream ' . $len . ' bytes');
        $data = substr($this->bytes, (int)$obj['stm']['at'], $len);
        $filters = [];
        $f = $this->resolve($d['Filter'] ?? null);
        if (is_array($f) && isset($f['n'])) $filters = [$f['n']];
        elseif (is_array($f) && isset($f['a'])) { foreach ($f['a'] as $x) { $n = $this->name($x); if ($n !== null) $filters[] = $n; } }
        $parmsAll = $this->resolve($d['DecodeParms'] ?? ($d['DP'] ?? null));
        $parmsList = [];
        if (is_array($parmsAll) && isset($parmsAll['d'])) $parmsList = [$parmsAll['d']];
        elseif (is_array($parmsAll) && isset($parmsAll['a'])) { foreach ($parmsAll['a'] as $x) $parmsList[] = $this->dict($x) ?? []; }
        foreach ($filters as $i => $filter) {
            $parms = $parmsList[$i] ?? [];
            switch ($filter) {
                case 'FlateDecode': case 'Fl':
                    $data = $this->unpredict(self::inflateBounded($data, MediaPolicy::DOCUMENT_PDF_MAX_STREAM_BYTES), $parms);
                    break;
                case 'ASCIIHexDecode': case 'AHx':
                    $hex = preg_replace('/[^0-9A-Fa-f]/', '', strstr($data, '>', true) ?: $data) ?? '';
                    if (strlen($hex) % 2 === 1) $hex .= '0';
                    $data = (string)hex2bin($hex);
                    break;
                case 'ASCII85Decode': case 'A85':
                    $data = self::ascii85($data);
                    break;
                default:
                    throw new DocumentRefused('unsupported_document', 'pdf filter ' . preg_replace('/[^A-Za-z0-9]/', '', $filter));
            }
            $this->inflated += strlen($data);
            if ($this->inflated > self::MAX_TOTAL_INFLATED) throw new DocumentRefused('too_large_document', 'pdf streams inflate beyond the cap');
        }
        return $data;
    }

    /** PNG predictors (10–15) as object and xref streams use them; TIFF (2) and anything else is a refusal. */
    private function unpredict(string $data, array $parms): string
    {
        $pred = (int)$this->num($this->resolve($parms['Predictor'] ?? null));
        if ($pred <= 1) return $data;
        if ($pred < 10) throw new DocumentRefused('unsupported_document', 'pdf predictor ' . $pred);
        $colors = max(1, (int)$this->num($this->resolve($parms['Colors'] ?? null)) ?: 1);
        $bpc    = max(1, (int)$this->num($this->resolve($parms['BitsPerComponent'] ?? null)) ?: 8);
        $cols   = max(1, (int)$this->num($this->resolve($parms['Columns'] ?? null)) ?: 1);
        $bpp    = max(1, intdiv($colors * $bpc + 7, 8));
        $rowLen = intdiv($cols * $colors * $bpc + 7, 8);
        $n = strlen($data);
        $out = '';
        $prev = str_repeat("\0", $rowLen);
        $rows = 0;
        for ($p = 0; $p + 1 <= $n; $p += $rowLen + 1) {
            if (++$rows > 1000000) throw new DocumentRefused('too_large_document', 'pdf predictor rows');
            $ft = ord($data[$p]);
            $row = substr($data, $p + 1, $rowLen);
            if (strlen($row) < $rowLen) $row = str_pad($row, $rowLen, "\0");
            $cur = $row;
            for ($i = 0; $i < $rowLen; $i++) {
                $a = $i >= $bpp ? ord($cur[$i - $bpp]) : 0;
                $b = ord($prev[$i]);
                $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                $x = ord($row[$i]);
                switch ($ft) {
                    case 0: $v = $x; break;
                    case 1: $v = $x + $a; break;
                    case 2: $v = $x + $b; break;
                    case 3: $v = $x + intdiv($a + $b, 2); break;
                    case 4:
                        $pp = $a + $b - $c; $pa = abs($pp - $a); $pb = abs($pp - $b); $pc = abs($pp - $c);
                        $v = $x + (($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c));
                        break;
                    default: throw new DocumentRefused('malformed_document', 'pdf predictor filter type ' . $ft);
                }
                $cur[$i] = chr($v & 0xFF);
            }
            $out .= $cur;
            $prev = $cur;
        }
        return $out;
    }

    private static function ascii85(string $data): string
    {
        $data = preg_replace('/\s+/', '', $data) ?? '';
        if (substr($data, 0, 2) === '<~') $data = substr($data, 2);
        $end = strpos($data, '~>');
        if ($end !== false) $data = substr($data, 0, $end);
        $out = '';
        $n = strlen($data);
        $i = 0;
        while ($i < $n) {
            if ($data[$i] === 'z') { $out .= "\0\0\0\0"; $i++; continue; }
            $chunk = substr($data, $i, 5);
            $len = strlen($chunk);
            if ($len < 5) $chunk = str_pad($chunk, 5, 'u');
            $v = 0;
            for ($k = 0; $k < 5; $k++) {
                $c = ord($chunk[$k]) - 33;
                if ($c < 0 || $c > 84) throw new DocumentRefused('malformed_document', 'pdf ascii85');
                $v = $v * 85 + $c;
            }
            $bytes = pack('N', $v & 0xFFFFFFFF);
            $out .= $len < 5 ? substr($bytes, 0, $len - 1) : $bytes;
            $i += 5;
        }
        return $out;
    }

    // ── The page tree ─────────────────────────────────────────────────────────────────────────────

    /**
     * @return array{pages: array<int, array{dict:array, res:mixed}>, total:int} the first $maxPages pages with their
     *         (inherited) resources, and how many pages the document declares or shows
     */
    private function pages(int $maxPages): array
    {
        $rootNum = null;
        if (preg_match_all('/\/Root\s+(\d+)\s+\d+\s+R/', $this->bytes, $rm) > 0) $rootNum = (int)end($rm[1]);
        $catalog = $rootNum !== null ? $this->dict(['r' => $rootNum]) : null;
        if ($catalog === null || $this->name($catalog['Type'] ?? null) !== 'Catalog') {
            $catalog = $this->findByType('Catalog');
        }
        $pagesRoot = $catalog !== null ? $this->dict($catalog['Pages'] ?? null) : null;
        if ($pagesRoot === null) $pagesRoot = $this->findByType('Pages', true);
        $pages = [];
        $nodes = 0;
        $visited = [];
        $declared = 0;
        if ($pagesRoot !== null) {
            $declared = (int)$this->num($this->resolve($pagesRoot['Count'] ?? null));
            $this->walk($pagesRoot, $pagesRoot['Resources'] ?? null, 0, $pages, $visited, $nodes, $maxPages, $declared);
        }
        if ($pages === []) {
            // No usable tree: every /Type /Page object in file order (a broken tree is common enough to deserve this one fallback).
            foreach ($this->pageObjectsByScan() as $pg) {
                if (count($pages) >= $maxPages) { $declared = max($declared, count($pages) + 1); break; }
                $pages[] = ['dict' => $pg, 'res' => $pg['Resources'] ?? null];
            }
            if ($pages === []) throw new DocumentRefused('malformed_document', 'pdf has no pages');
            $declared = max($declared, count($pages));
        }
        return ['pages' => $pages, 'total' => max($declared, $this->leaves, count($pages))];
    }

    private function walk(array $node, $resources, int $depth, array &$pages, array &$visited, int &$nodes, int $maxPages, int $declared): void
    {
        if ($depth > self::MAX_TREE_DEPTH) throw new DocumentRefused('malformed_document', 'pdf page tree depth');
        if (++$nodes > self::MAX_TREE_NODES) throw new DocumentRefused('too_large_document', 'pdf page tree nodes');
        if ($nodes % 200 === 0) $this->deadline->check('pdf page tree');
        if (isset($node['Resources'])) $resources = $node['Resources'];
        $type = $this->name($node['Type'] ?? null);
        $kids = $this->arr($node['Kids'] ?? null);
        if ($type === 'Pages' || ($kids !== null && $type !== 'Page')) {
            foreach ($kids ?? [] as $kid) {
                if (count($pages) >= $maxPages && $declared > 0) return;   // the rest is counted, not read
                $ref = is_array($kid) && isset($kid['r']) ? (int)$kid['r'] : null;
                if ($ref !== null) {
                    if (isset($visited[$ref])) throw new DocumentRefused('malformed_document', 'pdf page tree cycle');
                    $visited[$ref] = true;
                }
                $kd = $this->dict($kid);
                if ($kd !== null) $this->walk($kd, $resources, $depth + 1, $pages, $visited, $nodes, $maxPages, $declared);
            }
            return;
        }
        if ($type === 'Page' || isset($node['Contents']) || isset($node['MediaBox'])) {
            $this->leaves++;
            if (count($pages) < $maxPages) $pages[] = ['dict' => $node, 'res' => $resources];
        }
    }

    /** The first object whose dictionary says /Type /<name> (top level or inside an object stream); for Pages, one without a /Parent. */
    private function findByType(string $type, bool $rootOnly = false): ?array
    {
        $re = '/\/Type\s*\/' . $type . '(?![A-Za-z])/';
        $numsAt = [];
        if (preg_match_all($re, $this->bytes, $m, PREG_OFFSET_CAPTURE) > 0) {
            $starts = [];
            foreach ($this->index as $num => $cands) foreach ($cands as $c) if ($c['stm'] === null) $starts[$c['pos']] = $num;
            ksort($starts);
            $positions = array_keys($starts);
            foreach ($m[0] as $hit) {
                $at = (int)$hit[1]; $lo = 0; $hi = count($positions) - 1; $found = null;
                while ($lo <= $hi) { $mid = intdiv($lo + $hi, 2); if ($positions[$mid] <= $at) { $found = $positions[$mid]; $lo = $mid + 1; } else { $hi = $mid - 1; } }
                if ($found !== null) $numsAt[$starts[$found]] = true;
            }
        }
        foreach ($this->objStm as $num => $stm) {
            if (preg_match($re, $stm['data']) !== 1) continue;
            foreach ($this->index as $onum => $cands) foreach ($cands as $c) if ($c['stm'] === $num) $numsAt[$onum] = true;
        }
        $checked = 0;
        foreach (array_keys($numsAt) as $num) {
            if (++$checked > 2000) break;
            $d = $this->dict(['r' => (int)$num]);
            if ($d === null || $this->name($d['Type'] ?? null) !== $type) continue;
            if ($rootOnly && isset($d['Parent'])) continue;
            return $d;
        }
        return null;
    }

    /** @return array<int, array> every /Type /Page dictionary, in file order, capped */
    private function pageObjectsByScan(): array
    {
        $out = [];
        $nums = [];
        $starts = [];
        foreach ($this->index as $num => $cands) foreach ($cands as $c) if ($c['stm'] === null) $starts[$c['pos']] = $num;
        ksort($starts);
        $positions = array_keys($starts);
        if (preg_match_all('/\/Type\s*\/Page(?![s\w])/', $this->bytes, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as $hit) {
                $at = (int)$hit[1]; $lo = 0; $hi = count($positions) - 1; $found = null;
                while ($lo <= $hi) { $mid = intdiv($lo + $hi, 2); if ($positions[$mid] <= $at) { $found = $positions[$mid]; $lo = $mid + 1; } else { $hi = $mid - 1; } }
                if ($found !== null) $nums[$starts[$found]] = true;
            }
        }
        foreach ($this->objStm as $snum => $stm) {
            if (preg_match('/\/Type\s*\/Page(?![s\w])/', $stm['data']) !== 1) continue;
            foreach ($this->index as $onum => $cands) foreach ($cands as $c) if ($c['stm'] === $snum) $nums[$onum] = true;
        }
        $checked = 0;
        foreach (array_keys($nums) as $num) {
            if (++$checked > self::MAX_TREE_NODES) break;
            $d = $this->dict(['r' => (int)$num]);
            if ($d !== null && $this->name($d['Type'] ?? null) === 'Page') $out[] = $d;
        }
        return $out;
    }

    /** The page's content: one stream or an array of streams, decoded and joined. */
    private function pageContent(array $page): string
    {
        $c = $this->resolve($page['Contents'] ?? null);
        $parts = [];
        if (is_array($c) && isset($c['stm'])) $parts[] = $c;
        elseif (is_array($c) && isset($c['a'])) {
            foreach ($c['a'] as $k => $ref) {
                if ($k >= self::MAX_CONTENT_STREAMS) break;
                $s = $this->resolve($ref);
                if (is_array($s) && isset($s['stm'])) $parts[] = $s;
            }
        }
        $out = '';
        foreach ($parts as $s) $out .= $this->streamData($s) . "\n";
        return $out;
    }

    // ── The content interpreter ───────────────────────────────────────────────────────────────────

    /** Runs a content stream for its text; $byteCap bounds what this call may add. */
    private function runContent(string $content, array $resources, int $depth, int $byteCap): string
    {
        $out = '';
        $p = 0; $end = strlen($content);
        $stack = [];
        $font = null;
        $fontDict = $this->dict($resources['Font'] ?? null) ?? [];
        $xobjDict = $this->dict($resources['XObject'] ?? null) ?? [];
        while (($t = $this->token($content, $p, $end, true)) !== null) {
            if (++$this->ops > self::MAX_OPERATORS) throw new DocumentRefused('too_large_document', 'pdf content operators');
            if ($this->ops % self::DEADLINE_EVERY_OPS === 0) $this->deadline->check('pdf content');
            if (!isset($t['op'])) {
                if (count($stack) >= self::MAX_OPERANDS) array_shift($stack);
                $stack[] = $t;
                continue;
            }
            $n = count($stack);
            switch ($t['op']) {
                case 'BT':
                    $this->lastY = null; $this->lastX = null;
                    break;
                case 'ET':
                    $out .= "\n";
                    break;
                case 'Tf':
                    $fname = $n >= 2 && isset($stack[$n - 2]['n']) ? (string)$stack[$n - 2]['n'] : null;
                    $font = $fname !== null ? $this->fontDecoder($fontDict, $fname) : null;
                    break;
                case 'Td': case 'TD':
                    $ty = $n >= 1 ? (float)($this->num($stack[$n - 1]) ?? 0) : 0.0;
                    $tx = $n >= 2 ? (float)($this->num($stack[$n - 2]) ?? 0) : 0.0;
                    if (abs($ty) > 0.001) $out .= "\n"; elseif ($tx > 0.001) $out .= ' ';
                    break;
                case 'Tm':
                    $y = $n >= 1 ? (float)($this->num($stack[$n - 1]) ?? 0) : 0.0;
                    $x = $n >= 2 ? (float)($this->num($stack[$n - 2]) ?? 0) : 0.0;
                    if ($this->lastY !== null && abs($y - $this->lastY) > 0.001) $out .= "\n";
                    elseif ($this->lastX !== null && $x > $this->lastX + 0.001) $out .= ' ';
                    $this->lastY = $y; $this->lastX = $x;
                    break;
                case 'T*':
                    $out .= "\n";
                    break;
                case 'Tj':
                    if ($n >= 1 && isset($stack[$n - 1]['s'])) $out .= $this->decode($font, (string)$stack[$n - 1]['s']);
                    break;
                case "'":
                    if ($n >= 1 && isset($stack[$n - 1]['s'])) $out .= "\n" . $this->decode($font, (string)$stack[$n - 1]['s']);
                    break;
                case '"':
                    if ($n >= 1 && isset($stack[$n - 1]['s'])) $out .= "\n" . $this->decode($font, (string)$stack[$n - 1]['s']);
                    break;
                case 'TJ':
                    if ($n >= 1 && isset($stack[$n - 1]['a'])) {
                        foreach ($stack[$n - 1]['a'] as $el) {
                            if (is_array($el) && isset($el['s'])) $out .= $this->decode($font, (string)$el['s']);
                            elseif (is_array($el) && isset($el['num']) && (float)$el['num'] < -180) $out .= ' ';
                        }
                    }
                    break;
                case 'Do':
                    $xname = $n >= 1 && isset($stack[$n - 1]['n']) ? (string)$stack[$n - 1]['n'] : null;
                    if ($xname !== null && isset($xobjDict[$xname])) {
                        $out .= $this->runForm($xobjDict[$xname], $resources, $depth, $byteCap - strlen($out));
                    }
                    break;
                case 'BI':
                    // an inline image: skip to the EI that ends it; its bytes are never interpreted
                    $id = strpos($content, 'ID', $p);
                    if ($id === false) { $p = $end; break; }
                    $q = $id + 2;
                    $found = false;
                    while (($ei = strpos($content, 'EI', $q)) !== false) {
                        $before = $ei > 0 ? $content[$ei - 1] : ' ';
                        $after = $ei + 2 < $end ? $content[$ei + 2] : ' ';
                        if (strpos(self::WS, $before) !== false && strpos(self::WS, $after) !== false) { $p = $ei + 2; $found = true; break; }
                        $q = $ei + 2;
                    }
                    if (!$found) $p = $end;
                    break;
                default:
                    break;
            }
            $stack = [];
            if (strlen($out) >= $byteCap) break;
        }
        return $out;
    }

    /** A Form XObject's own content with its own resources, to a fixed depth, never twice on the same drawing stack. */
    private function runForm($ref, array $parentResources, int $depth, int $byteCap): string
    {
        if ($depth + 1 > self::MAX_FORM_DEPTH) throw new DocumentRefused('malformed_document', 'pdf form depth');
        if (++$this->forms > self::MAX_FORMS) throw new DocumentRefused('too_large_document', 'pdf forms');
        $num = is_array($ref) && isset($ref['r']) ? (int)$ref['r'] : null;
        if ($num !== null && isset($this->formStack[$num])) throw new DocumentRefused('malformed_document', 'pdf form cycle');
        $x = $this->resolve($ref);
        if (!is_array($x) || !isset($x['stm']) || !isset($x['d'])) return '';
        if ($this->name($x['d']['Subtype'] ?? null) !== 'Form') return '';   // an image, a PostScript XObject: nothing to read
        if ($num !== null) $this->formStack[$num] = true;
        $res = $this->dict($x['d']['Resources'] ?? null) ?? $parentResources;
        $saveY = $this->lastY; $saveX = $this->lastX;
        $out = $this->runContent($this->streamData($x), $res, $depth + 1, $byteCap);
        $this->lastY = $saveY; $this->lastX = $saveX;
        if ($num !== null) unset($this->formStack[$num]);
        return $out;
    }

    // ── Fonts ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * The decoder for a font resource: how many bytes make a code, the ToUnicode map, the base encoding, the differences.
     * @return array{bytes:int, map:array<int,string>|null, enc:string, diff:array<int,string>, nodecoder:bool}|null
     */
    private function fontDecoder(array $fontDict, string $name): ?array
    {
        $ref = $fontDict[$name] ?? null;
        $key = is_array($ref) && isset($ref['r']) ? 'r' . (int)$ref['r'] : 'n' . $name . ':' . md5(serialize($ref));
        if (array_key_exists($key, $this->fonts)) return $this->fonts[$key];
        if (count($this->fonts) >= self::MAX_FONTS) throw new DocumentRefused('too_large_document', 'pdf fonts');
        $this->fonts[$key] = null;
        $f = $this->dict($ref);
        if ($f === null) return null;
        $subtype = $this->name($f['Subtype'] ?? null) ?? '';
        $dec = ['bytes' => 1, 'map' => null, 'enc' => 'standard', 'diff' => [], 'nodecoder' => false];
        if ($subtype === 'Type0') {
            $dec['bytes'] = 2;
            $encName = $this->name($f['Encoding'] ?? null);
            $dec['enc'] = 'identity';
            if ($encName === null || strpos($encName, 'Identity') !== 0) $dec['nodecoder'] = true;   // a predefined or embedded CMap: not this reader's
        } else {
            $enc = $this->resolve($f['Encoding'] ?? null);
            $encName = is_array($enc) && isset($enc['n']) ? (string)$enc['n'] : null;
            $encDict = is_array($enc) && isset($enc['d']) ? $enc['d'] : null;
            if ($encDict !== null) $encName = $this->name($encDict['BaseEncoding'] ?? null);
            $dec['enc'] = $encName === 'WinAnsiEncoding' ? 'winansi' : ($encName === 'MacRomanEncoding' ? 'macroman' : 'standard');
            if ($encDict !== null) {
                $diffs = $this->arr($encDict['Differences'] ?? null) ?? [];
                $code = 0; $seen = 0;
                foreach ($diffs as $item) {
                    if (++$seen > 4096) break;
                    if (is_array($item) && isset($item['num'])) { $code = (int)$item['num']; continue; }
                    if (is_array($item) && isset($item['n'])) {
                        $u = self::glyphToUnicode((string)$item['n']);
                        if ($u !== null) $dec['diff'][$code] = $u;
                        $code++;
                    }
                }
            }
            if ($subtype === 'Type3' && !isset($f['ToUnicode'])) $dec['nodecoder'] = $dec['diff'] === [];
        }
        $tu = $this->resolve($f['ToUnicode'] ?? null);
        if (is_array($tu) && isset($tu['stm'])) {
            $map = $this->parseCMap($this->streamData($tu), $dec['bytes']);
            if ($map['map'] !== []) { $dec['map'] = $map['map']; $dec['bytes'] = $map['bytes']; $dec['nodecoder'] = false; }
        }
        $this->fonts[$key] = $dec;
        return $dec;
    }

    /**
     * A ToUnicode CMap: codespace ranges (for the code length), bfchar and bfrange entries, UTF-16BE destinations.
     * @return array{map:array<int,string>, bytes:int}
     */
    private function parseCMap(string $cmap, int $defaultBytes): array
    {
        $map = [];
        $bytes = $defaultBytes;
        $p = 0; $end = strlen($cmap);
        $stack = [];
        $mode = '';
        $entries = 0;
        while (($t = $this->token($cmap, $p, $end, true)) !== null) {
            if (++$this->ops > self::MAX_OPERATORS) throw new DocumentRefused('too_large_document', 'pdf cmap operators');
            if (isset($t['op'])) {
                switch ($t['op']) {
                    case 'begincodespacerange': $mode = 'cs'; $stack = []; break;
                    case 'beginbfchar': $mode = 'char'; $stack = []; break;
                    case 'beginbfrange': $mode = 'range'; $stack = []; break;
                    case 'endcodespacerange':
                        for ($i = 0; $i + 1 < count($stack); $i += 2) {
                            if (isset($stack[$i]['s'])) { $bl = strlen((string)$stack[$i]['s']); if ($bl >= 1 && $bl <= 4) $bytes = $bl; }
                        }
                        $mode = ''; $stack = [];
                        break;
                    case 'endbfchar':
                        for ($i = 0; $i + 1 < count($stack); $i += 2) {
                            if (!isset($stack[$i]['s']) || !isset($stack[$i + 1]['s'])) continue;
                            if (++$entries > self::MAX_CMAP_ENTRIES) throw new DocumentRefused('too_large_document', 'pdf cmap entries');
                            $src = (string)$stack[$i]['s'];
                            if (strlen($src) >= 1 && strlen($src) <= 4) $bytes = strlen($src);
                            $map[self::codeOf($src)] = self::utf16((string)$stack[$i + 1]['s']);
                        }
                        $mode = ''; $stack = [];
                        break;
                    case 'endbfrange':
                        for ($i = 0; $i + 2 < count($stack); $i += 3) {
                            if (!isset($stack[$i]['s']) || !isset($stack[$i + 1]['s'])) continue;
                            $lo = self::codeOf((string)$stack[$i]['s']); $hi = self::codeOf((string)$stack[$i + 1]['s']);
                            $bl = strlen((string)$stack[$i]['s']); if ($bl >= 1 && $bl <= 4) $bytes = $bl;
                            if ($hi < $lo) continue;
                            if ($hi - $lo > 65535) $hi = $lo + 65535;
                            $dst = $stack[$i + 2];
                            if (isset($dst['a'])) {
                                foreach ($dst['a'] as $k => $d) {
                                    if (!isset($d['s'])) continue;
                                    if (++$entries > self::MAX_CMAP_ENTRIES) throw new DocumentRefused('too_large_document', 'pdf cmap entries');
                                    $map[$lo + $k] = self::utf16((string)$d['s']);
                                }
                            } elseif (isset($dst['s'])) {
                                $d = (string)$dst['s'];
                                for ($c = $lo; $c <= $hi; $c++) {
                                    if (++$entries > self::MAX_CMAP_ENTRIES) throw new DocumentRefused('too_large_document', 'pdf cmap entries');
                                    $map[$c] = self::utf16($d);
                                    $d = self::incrementUtf16($d);
                                }
                            }
                        }
                        $mode = ''; $stack = [];
                        break;
                    default:
                        if ($mode === '') $stack = [];
                        break;
                }
                continue;
            }
            if ($mode !== '') { if (count($stack) < 3 * self::MAX_CMAP_ENTRIES) $stack[] = $t; }
        }
        return ['map' => $map, 'bytes' => $bytes];
    }

    private static function codeOf(string $s): int
    {
        $n = 0;
        $len = min(4, strlen($s));
        for ($i = 0; $i < $len; $i++) $n = ($n << 8) | ord($s[$i]);
        return $n;
    }

    private static function utf16(string $be): string
    {
        if (strlen($be) % 2 === 1) $be .= "\0";
        if ($be === '') return '';
        $u = @mb_convert_encoding($be, 'UTF-8', 'UTF-16BE');
        return is_string($u) ? $u : '';
    }

    private static function incrementUtf16(string $be): string
    {
        if (strlen($be) < 2) return $be;
        $last = (ord($be[strlen($be) - 2]) << 8) | ord($be[strlen($be) - 1]);
        $last = ($last + 1) & 0xFFFF;
        return substr($be, 0, -2) . chr($last >> 8) . chr($last & 0xFF);
    }

    /** Shown bytes → text, through the font's map, differences and encoding; a code with no name is counted, not invented. */
    private function decode(?array $font, string $s): string
    {
        $out = '';
        $bytes = $font !== null ? (int)$font['bytes'] : 1;
        $n = strlen($s);
        for ($i = 0; $i + $bytes <= $n; $i += $bytes) {
            $code = $bytes === 1 ? ord($s[$i]) : self::codeOf(substr($s, $i, $bytes));
            $this->glyphs++;
            if ($font !== null && $font['map'] !== null && isset($font['map'][$code])) { $out .= $font['map'][$code]; continue; }
            if ($font !== null && !empty($font['nodecoder'])) { $this->unmapped++; continue; }
            if ($font !== null && isset($font['diff'][$code])) { $out .= $font['diff'][$code]; continue; }
            if ($bytes !== 1) { $this->unmapped++; continue; }
            $u = self::simpleChar($code, $font !== null ? (string)$font['enc'] : 'standard');
            if ($u === null) $this->unmapped++; else $out .= $u;
        }
        if ($bytes === 2 && $n % 2 === 1) { $this->glyphs++; $this->unmapped++; }
        return $out;
    }

    /** One code of a simple font under WinAnsi, MacRoman or Standard encoding. */
    private static function simpleChar(int $code, string $enc): ?string
    {
        if ($code >= 32 && $code <= 126) return chr($code);
        if ($code < 32) return $code === 9 || $code === 10 || $code === 13 ? ' ' : null;
        if ($code === 160 && $enc !== 'macroman') return ' ';
        if ($enc === 'macroman') {
            $t = self::MACROMAN[$code] ?? null;
            return $t;
        }
        if ($enc === 'standard' && isset(self::STANDARD_HIGH[$code])) return self::STANDARD_HIGH[$code];
        $u = @mb_convert_encoding(chr($code), 'UTF-8', 'Windows-1252');
        return is_string($u) && $u !== '' && $u !== '?' ? $u : null;
    }

    /** A glyph name → the character it stands for, for the names that occur in practice; null when the name is not known. */
    private static function glyphToUnicode(string $name): ?string
    {
        if (strlen($name) === 1) return $name;
        if (preg_match('/^uni([0-9A-Fa-f]{4})/', $name, $m) === 1) return self::cp(hexdec($m[1]));
        if (preg_match('/^u([0-9A-Fa-f]{4,6})$/', $name, $m) === 1) return self::cp(hexdec($m[1]));
        if (isset(self::GLYPHS[$name])) return self::GLYPHS[$name];
        if (preg_match('/^([A-Za-z]+)\.(?:sc|alt|swash|fitted|oldstyle|sups|sinf|tnum|lnum|pnum|onum|liga|dlig|ss\d\d)$/', $name, $m) === 1) return self::glyphToUnicode($m[1]);
        return null;
    }

    /** A code point as UTF-8, built by hand (no HTML-entity detour, which PHP 8.2+ deprecates). */
    private static function cp(int $cp): ?string
    {
        if ($cp <= 0 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) return null;
        if ($cp < 0x80) return chr($cp);
        if ($cp < 0x800) return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        if ($cp < 0x10000) return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    private const GLYPHS = [
        'space' => ' ', 'exclam' => '!', 'quotedbl' => '"', 'numbersign' => '#', 'dollar' => '$', 'percent' => '%', 'ampersand' => '&',
        'quotesingle' => "'", 'quoteright' => '’', 'quoteleft' => '‘', 'parenleft' => '(', 'parenright' => ')', 'asterisk' => '*', 'plus' => '+',
        'comma' => ',', 'hyphen' => '-', 'minus' => '−', 'period' => '.', 'slash' => '/', 'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3',
        'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'colon' => ':', 'semicolon' => ';', 'less' => '<',
        'equal' => '=', 'greater' => '>', 'question' => '?', 'at' => '@', 'bracketleft' => '[', 'backslash' => '\\', 'bracketright' => ']',
        'asciicircum' => '^', 'underscore' => '_', 'grave' => '`', 'braceleft' => '{', 'bar' => '|', 'braceright' => '}', 'asciitilde' => '~',
        'quotedblleft' => '“', 'quotedblright' => '”', 'quotedblbase' => '„', 'quotesinglbase' => '‚', 'endash' => '–', 'emdash' => '—',
        'bullet' => '•', 'ellipsis' => '…', 'periodcentered' => '·', 'dagger' => '†', 'daggerdbl' => '‡', 'section' => '§', 'paragraph' => '¶',
        'degree' => '°', 'copyright' => '©', 'registered' => '®', 'trademark' => '™', 'sterling' => '£', 'Euro' => '€', 'yen' => '¥', 'cent' => '¢',
        'currency' => '¤', 'multiply' => '×', 'divide' => '÷', 'plusminus' => '±', 'onehalf' => '½', 'onequarter' => '¼', 'threequarters' => '¾',
        'guilsinglleft' => '‹', 'guilsinglright' => '›', 'guillemotleft' => '«', 'guillemotright' => '»', 'exclamdown' => '¡', 'questiondown' => '¿',
        'fi' => 'fi', 'fl' => 'fl', 'ff' => 'ff', 'ffi' => 'ffi', 'ffl' => 'ffl', 'nbspace' => ' ', 'nonbreakingspace' => ' ', 'softhyphen' => '-',
        'Agrave' => 'À', 'Aacute' => 'Á', 'Acircumflex' => 'Â', 'Atilde' => 'Ã', 'Adieresis' => 'Ä', 'Aring' => 'Å', 'AE' => 'Æ', 'Ccedilla' => 'Ç',
        'Egrave' => 'È', 'Eacute' => 'É', 'Ecircumflex' => 'Ê', 'Edieresis' => 'Ë', 'Igrave' => 'Ì', 'Iacute' => 'Í', 'Icircumflex' => 'Î', 'Idieresis' => 'Ï',
        'Eth' => 'Ð', 'Ntilde' => 'Ñ', 'Ograve' => 'Ò', 'Oacute' => 'Ó', 'Ocircumflex' => 'Ô', 'Otilde' => 'Õ', 'Odieresis' => 'Ö', 'Oslash' => 'Ø',
        'Ugrave' => 'Ù', 'Uacute' => 'Ú', 'Ucircumflex' => 'Û', 'Udieresis' => 'Ü', 'Yacute' => 'Ý', 'Thorn' => 'Þ', 'germandbls' => 'ß',
        'agrave' => 'à', 'aacute' => 'á', 'acircumflex' => 'â', 'atilde' => 'ã', 'adieresis' => 'ä', 'aring' => 'å', 'ae' => 'æ', 'ccedilla' => 'ç',
        'egrave' => 'è', 'eacute' => 'é', 'ecircumflex' => 'ê', 'edieresis' => 'ë', 'igrave' => 'ì', 'iacute' => 'í', 'icircumflex' => 'î', 'idieresis' => 'ï',
        'eth' => 'ð', 'ntilde' => 'ñ', 'ograve' => 'ò', 'oacute' => 'ó', 'ocircumflex' => 'ô', 'otilde' => 'õ', 'odieresis' => 'ö', 'oslash' => 'ø',
        'ugrave' => 'ù', 'uacute' => 'ú', 'ucircumflex' => 'û', 'udieresis' => 'ü', 'yacute' => 'ý', 'thorn' => 'þ', 'ydieresis' => 'ÿ', 'Ydieresis' => 'Ÿ',
        'Scaron' => 'Š', 'scaron' => 'š', 'Zcaron' => 'Ž', 'zcaron' => 'ž', 'OE' => 'Œ', 'oe' => 'œ', 'Lslash' => 'Ł', 'lslash' => 'ł', 'dotlessi' => 'ı',
        'macron' => '¯', 'acute' => '´', 'dieresis' => '¨', 'cedilla' => '¸', 'circumflex' => '^', 'tilde' => '~', 'caron' => 'ˇ', 'breve' => '˘',
        'ring' => '˚', 'ogonek' => '˛', 'hungarumlaut' => '˝', 'dotaccent' => '˙', 'florin' => 'ƒ', 'fraction' => '⁄', 'perthousand' => '‰',
        'logicalnot' => '¬', 'brokenbar' => '¦', 'ordfeminine' => 'ª', 'ordmasculine' => 'º', 'mu' => 'µ', 'onesuperior' => '¹', 'twosuperior' => '²',
        'threesuperior' => '³', 'checkmark' => '✓', 'arrowright' => '→', 'arrowleft' => '←', 'summation' => '∑', 'Delta' => 'Δ', 'Omega' => 'Ω',
        'pi' => 'π', 'infinity' => '∞', 'notequal' => '≠', 'lessequal' => '≤', 'greaterequal' => '≥', 'approxequal' => '≈', 'radical' => '√',
        'minute' => '′', 'second' => '″', 'lozenge' => '◊', 'Ohm' => 'Ω', 'estimated' => '℮',
    ];

    /** StandardEncoding's high half, where it differs from WinAnsi (the common names only). */
    private const STANDARD_HIGH = [
        0xA1 => '¡', 0xA2 => '¢', 0xA3 => '£', 0xA4 => '⁄', 0xA5 => '¥', 0xA6 => 'ƒ', 0xA7 => '§', 0xA8 => '¤', 0xA9 => "'", 0xAA => '“',
        0xAB => '«', 0xAC => '‹', 0xAD => '›', 0xAE => 'fi', 0xAF => 'fl', 0xB1 => '–', 0xB2 => '†', 0xB3 => '‡', 0xB4 => '·', 0xB6 => '¶',
        0xB7 => '•', 0xB8 => '‚', 0xB9 => '„', 0xBA => '”', 0xBB => '»', 0xBC => '…', 0xBD => '‰', 0xBF => '¿', 0xC1 => '`', 0xC2 => '´',
        0xC3 => '^', 0xC4 => '~', 0xC5 => '¯', 0xC6 => '˘', 0xC7 => '˙', 0xC8 => '¨', 0xCA => '˚', 0xCB => '¸', 0xCD => '˝', 0xCE => '˛',
        0xCF => 'ˇ', 0xD0 => '—', 0xE1 => 'Æ', 0xE3 => 'ª', 0xE8 => 'Ł', 0xE9 => 'Ø', 0xEA => 'Œ', 0xEB => 'º', 0xF1 => 'æ', 0xF5 => 'ı',
        0xF8 => 'ł', 0xF9 => 'ø', 0xFA => 'œ', 0xFB => 'ß',
    ];

    /** MacRomanEncoding's high half (the characters that occur in text; the rest are counted as unmapped). */
    private const MACROMAN = [
        0x80 => 'Ä', 0x81 => 'Å', 0x82 => 'Ç', 0x83 => 'É', 0x84 => 'Ñ', 0x85 => 'Ö', 0x86 => 'Ü', 0x87 => 'á', 0x88 => 'à', 0x89 => 'â',
        0x8A => 'ä', 0x8B => 'ã', 0x8C => 'å', 0x8D => 'ç', 0x8E => 'é', 0x8F => 'è', 0x90 => 'ê', 0x91 => 'ë', 0x92 => 'í', 0x93 => 'ì',
        0x94 => 'î', 0x95 => 'ï', 0x96 => 'ñ', 0x97 => 'ó', 0x98 => 'ò', 0x99 => 'ô', 0x9A => 'ö', 0x9B => 'õ', 0x9C => 'ú', 0x9D => 'ù',
        0x9E => 'û', 0x9F => 'ü', 0xA0 => '†', 0xA1 => '°', 0xA2 => '¢', 0xA3 => '£', 0xA4 => '§', 0xA5 => '•', 0xA6 => '¶', 0xA7 => 'ß',
        0xA8 => '®', 0xA9 => '©', 0xAA => '™', 0xAB => '´', 0xAC => '¨', 0xAD => '≠', 0xAE => 'Æ', 0xAF => 'Ø', 0xB0 => '∞', 0xB1 => '±',
        0xB2 => '≤', 0xB3 => '≥', 0xB4 => '¥', 0xB5 => 'µ', 0xB9 => 'π', 0xBA => '∫', 0xBB => 'ª', 0xBC => 'º', 0xBD => 'Ω', 0xBE => 'æ',
        0xBF => 'ø', 0xC0 => '¿', 0xC1 => '¡', 0xC2 => '¬', 0xC3 => '√', 0xC4 => 'ƒ', 0xC5 => '≈', 0xC6 => '∆', 0xC7 => '«', 0xC8 => '»',
        0xC9 => '…', 0xCA => ' ', 0xCB => 'À', 0xCC => 'Ã', 0xCD => 'Õ', 0xCE => 'Œ', 0xCF => 'œ', 0xD0 => '–', 0xD1 => '—', 0xD2 => '“',
        0xD3 => '”', 0xD4 => '‘', 0xD5 => '’', 0xD6 => '÷', 0xD7 => '◊', 0xD8 => 'ÿ', 0xD9 => 'Ÿ', 0xDA => '⁄', 0xDB => '€', 0xDC => '‹',
        0xDD => '›', 0xDE => 'fi', 0xDF => 'fl', 0xE0 => '‡', 0xE1 => '·', 0xE2 => '‚', 0xE3 => '„', 0xE4 => '‰', 0xE5 => 'Â', 0xE6 => 'Ê',
        0xE7 => 'Á', 0xE8 => 'Ë', 0xE9 => 'È', 0xEA => 'Í', 0xEB => 'Î', 0xEC => 'Ï', 0xED => 'Ì', 0xEE => 'Ó', 0xEF => 'Ô', 0xF1 => 'Ò',
        0xF2 => 'Ú', 0xF3 => 'Û', 0xF4 => 'Ù', 0xF5 => 'ı', 0xF6 => '^', 0xF7 => '~', 0xF8 => '¯', 0xF9 => '˘', 0xFA => '˙', 0xFB => '˚',
        0xFC => '¸', 0xFD => '˝', 0xFE => '˛', 0xFF => 'ˇ',
    ];

    // ── The lexer: PDF object syntax, shared by objects, content streams and CMaps ───────────────

    /**
     * The next token at $p in $buf (which ends at $end), or null at the end. Tagged arrays, never bare scalars:
     * ['num' => int|float] · ['n' => name] · ['s' => bytes] · ['a' => [...]] · ['d' => [...]] · ['r' => objnum] · ['b' => bool] ·
     * ['null' => true] · ['kw' => obj|endobj|stream|endstream] · ['op' => operator] (content mode) · ['end' => ']'|'>>'] · ['junk' => char].
     */
    private function token(string $buf, int &$p, int $end, bool $content): ?array
    {
        for (;;) {
            $p += strspn($buf, self::WS, $p, max(0, $end - $p));
            if ($p >= $end) return null;
            if ($buf[$p] === '%') { $p += strcspn($buf, "\r\n", $p, max(0, $end - $p)); continue; }
            break;
        }
        $c = $buf[$p];
        switch ($c) {
            case '/':
                $p++;
                $len = strcspn($buf, self::WS . self::DELIM, $p, max(0, $end - $p));
                $raw = substr($buf, $p, $len);
                $p += $len;
                if (strpos($raw, '#') !== false) $raw = preg_replace_callback('/#([0-9A-Fa-f]{2})/', function ($m) { return chr((int)hexdec($m[1])); }, $raw) ?? $raw;
                return ['n' => $raw];
            case '(':
                return ['s' => $this->literal($buf, $p, $end)];
            case '<':
                if ($p + 1 < $end && $buf[$p + 1] === '<') {
                    $p += 2;
                    $d = [];
                    $pairs = 0;
                    for (;;) {
                        $k = $this->token($buf, $p, $end, $content);
                        if ($k === null || isset($k['end']) || isset($k['kw'])) break;
                        if (!isset($k['n'])) { continue; }   // a stray value where a key should be: skipped
                        $v = $this->value($buf, $p, $end, $content);
                        if ($v === null || (is_array($v) && (isset($v['end']) || isset($v['kw'])))) break;
                        if (++$pairs > 4096) throw new DocumentRefused('too_large_document', 'pdf dictionary');
                        $d[(string)$k['n']] = isset($v['null']) ? null : $v;
                    }
                    return ['d' => $d];
                }
                $close = strpos($buf, '>', $p);
                if ($close === false || $close > $end) { $p = $end; return ['s' => '']; }
                $hex = substr($buf, $p + 1, $close - $p - 1);
                $p = $close + 1;
                if (strlen($hex) > self::MAX_STRING_TOKEN) throw new DocumentRefused('too_large_document', 'pdf string');
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex) ?? '';
                if (strlen($hex) % 2 === 1) $hex .= '0';
                return ['s' => (string)hex2bin($hex)];
            case '[':
                $p++;
                $a = [];
                for (;;) {
                    $v = $this->value($buf, $p, $end, $content);
                    if ($v === null || (is_array($v) && (isset($v['end']) || isset($v['kw'])))) break;
                    if (count($a) >= 8192) throw new DocumentRefused('too_large_document', 'pdf array');
                    $a[] = isset($v['null']) ? null : $v;
                }
                return ['a' => $a];
            case ']':
                $p++; return ['end' => ']'];
            case '>':
                if ($p + 1 < $end && $buf[$p + 1] === '>') { $p += 2; return ['end' => '>>']; }
                $p++; return ['junk' => '>'];
            case ')': case '{': case '}':
                $p++; return ['junk' => $c];
        }
        if (($c >= '0' && $c <= '9') || $c === '+' || $c === '-' || $c === '.') {
            if (!$content && preg_match('/\G(\d{1,9})\s+(\d{1,5})\s+R(?![A-Za-z0-9])/', $buf, $m, 0, $p) === 1) {
                $p += strlen($m[0]);
                return ['r' => (int)$m[1]];
            }
            if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $buf, $m, 0, $p) === 1) {
                $p += strlen($m[0]);
                $s = $m[0];
                return ['num' => strpos($s, '.') === false ? (int)$s : (float)$s];
            }
            $p++;
            return ['junk' => $c];
        }
        $len = strcspn($buf, self::WS . self::DELIM, $p, max(0, $end - $p));
        if ($len === 0) { $p++; return ['junk' => $c]; }
        $kw = substr($buf, $p, $len);
        $p += $len;
        if ($content) return ['op' => $kw];
        switch ($kw) {
            case 'true': return ['b' => true];
            case 'false': return ['b' => false];
            case 'null': return ['null' => true];
            case 'obj': case 'endobj': case 'stream': case 'endstream': case 'xref': case 'trailer': case 'startxref': return ['kw' => $kw];
            default: return ['junk' => $kw];
        }
    }

    /** A value: the next token, with junk skipped. */
    private function value(string $buf, int &$p, int $end, bool $content)
    {
        for ($guard = 0; $guard < 64; $guard++) {
            $t = $this->token($buf, $p, $end, $content);
            if ($t === null) return null;
            if (isset($t['junk'])) continue;
            return $t;   // a PDF null arrives tagged, ['null' => true], so a dictionary holding one keeps parsing
        }
        return null;
    }

    /** A literal string "( … )" with its escapes and nesting. */
    private function literal(string $buf, int &$p, int $end): string
    {
        $p++;   // (
        $depth = 1;
        $out = '';
        while ($p < $end) {
            $c = $buf[$p];
            if ($c === '\\') {
                $p++;
                if ($p >= $end) break;
                $e = $buf[$p];
                switch ($e) {
                    case 'n': $out .= "\n"; $p++; break;
                    case 'r': $out .= "\r"; $p++; break;
                    case 't': $out .= "\t"; $p++; break;
                    case 'b': $out .= "\x08"; $p++; break;
                    case 'f': $out .= "\x0C"; $p++; break;
                    case "\r": $p++; if ($p < $end && $buf[$p] === "\n") $p++; break;   // line continuation
                    case "\n": $p++; break;
                    default:
                        if ($e >= '0' && $e <= '7') {
                            $oct = '';
                            while ($p < $end && strlen($oct) < 3 && $buf[$p] >= '0' && $buf[$p] <= '7') { $oct .= $buf[$p]; $p++; }
                            $out .= chr(octdec($oct) & 0xFF);
                        } else { $out .= $e; $p++; }
                }
                continue;
            }
            if ($c === '(') { $depth++; $out .= $c; $p++; continue; }
            if ($c === ')') { $depth--; $p++; if ($depth === 0) break; $out .= $c; continue; }
            $out .= $c;
            $p++;
            if (strlen($out) > self::MAX_STRING_TOKEN) throw new DocumentRefused('too_large_document', 'pdf string');
        }
        return $out;
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/DocumentDeadline.php';

/**
 * PdfReader — the FACTS of a PDF, and nothing of its text (Batch 4, Slice 4a; docs/58 §3, §6.2, §13 D-1).
 *
 * Slice 4a reads no PDF text: the operator decides how that is done (D-1, an in-house reader as slice 4b). What this answers
 * is what a person needs to know and what routes the file: is it encrypted; how many pages; does it carry a text layer at all
 * (a font resource, or text operators in a content stream) or is it image-only (scanned). Streams are inflated only to look
 * for those markers, each under DOCUMENT_PDF_MAX_STREAM_BYTES through zlib's own cap, DOCUMENT_PDF_MAX_OBJECTS objects at most,
 * the deadline asked every fifty objects — and nothing inflated is kept or returned. PHP 7.4 compatible.
 */
final class PdfReader
{
    private const MAX_CONTENT_STREAMS = 64;
    private const MAX_TOTAL_INFLATED  = 64 * 1024 * 1024;

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
            if ($sp === false || $textOps) continue;
            $isObjStm = preg_match('/\/Type\s*\/ObjStm\b/', $dict) === 1;
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
}

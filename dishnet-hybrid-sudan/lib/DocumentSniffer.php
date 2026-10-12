<?php
declare(strict_types=1);

require_once __DIR__ . '/DocumentDeadline.php';
require_once __DIR__ . '/OoxmlArchive.php';

/**
 * DocumentSniffer — what IS this file? The content decides, never the label and never the file name (Batch 4, docs/58 §3, §7).
 *
 * Magic bytes first: a PDF header, a zip (then the OOXML content types say Word, Excel, macro-enabled or something else), the
 * OLE2 signature of a legacy or encrypted Office file, an image. Then text: no NUL byte, almost no control characters, valid
 * UTF-8 or re-codable Windows-1252; separated values on consistent delimiters make it CSV. Anything else is not a document this
 * plugin reads. Nothing here extracts; it names the kind and the facts the readers need, or throws a DocumentRefused with a
 * fixed reason. PHP 7.4 compatible.
 */
final class DocumentSniffer
{
    public const KINDS = ['pdf', 'docx', 'xlsx', 'csv', 'txt'];

    private const OLE2 = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** The content types that name a Word document, an Excel workbook, and the macro-enabled kinds that are refused. */
    private const CT_WORD  = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
    private const CT_EXCEL = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
    private const CT_MACRO = '/macroEnabled|vbaProject|application\/vnd\.ms-office\.vbaProject/i';

    public const MIME = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv'  => 'text/csv',
        'txt'  => 'text/plain',
    ];

    /**
     * @return array{kind:string, mime:string, archive:?OoxmlArchive, encoding:string, delimiter:string}
     * @throws DocumentRefused unsupported_mime (an image, or a zip that is not Word or Excel) · unsupported_document (legacy
     *                         binary Office, macro-enabled, zip64) · password_protected · malformed_document
     */
    public static function sniff(string $bytes, string $announcedMime, DocumentDeadline $deadline): array
    {
        $deadline->check('sniff');
        $n = strlen($bytes);
        if ($n === 0) throw new DocumentRefused('malformed_document', 'empty file');
        $out = ['kind' => '', 'mime' => '', 'archive' => null, 'encoding' => 'utf-8', 'delimiter' => ''];

        // PDF: the header must sit in the first kilobyte (the specification allows leading junk up to there).
        if (strpos(substr($bytes, 0, 1024), '%PDF-') !== false) {
            $out['kind'] = 'pdf'; $out['mime'] = self::MIME['pdf'];
            return $out;
        }

        // An image behind a document label is not a document.
        $img = self::imageMime($bytes);
        if ($img !== '') throw new DocumentRefused('unsupported_mime', $img);

        // A zip: Word, Excel, or something that is neither.
        if (OoxmlArchive::looksLikeZip($bytes)) {
            $zip = OoxmlArchive::open($bytes, $deadline);
            if (!$zip->has('[Content_Types].xml')) throw new DocumentRefused('unsupported_mime', 'zip without OOXML content types');
            $ct = $zip->read('[Content_Types].xml');
            if (preg_match('/<!DOCTYPE|<!ENTITY/i', $ct) === 1) throw new DocumentRefused('malformed_document', 'DTD in content types');
            if (preg_match(self::CT_MACRO, $ct) === 1) throw new DocumentRefused('unsupported_document', 'macro-enabled Office file');
            if (strpos($ct, self::CT_WORD) !== false && $zip->has('word/document.xml')) {
                $out['kind'] = 'docx'; $out['mime'] = self::MIME['docx']; $out['archive'] = $zip;
                return $out;
            }
            if (strpos($ct, self::CT_EXCEL) !== false && $zip->has('xl/workbook.xml')) {
                $out['kind'] = 'xlsx'; $out['mime'] = self::MIME['xlsx']; $out['archive'] = $zip;
                return $out;
            }
            throw new DocumentRefused('unsupported_document', 'OOXML that is not a Word document or an Excel workbook');
        }

        // OLE2: a legacy .doc/.xls, or an encrypted OOXML wrapped in an OLE2 container.
        if (strncmp($bytes, self::OLE2, 8) === 0) {
            $head = substr($bytes, 0, 65536);
            if (strpos($head, self::utf16('EncryptedPackage')) !== false || strpos($head, self::utf16('EncryptionInfo')) !== false) {
                throw new DocumentRefused('password_protected', 'encrypted Office package');
            }
            throw new DocumentRefused('unsupported_document', 'legacy binary Office file');
        }

        // Text, or nothing.
        $enc = self::textEncoding($bytes);
        if ($enc === '') throw new DocumentRefused('malformed_document', 'not a document type this plugin reads');
        $out['encoding'] = $enc;
        $delim = self::csvDelimiter($bytes, $enc);
        $announced = strtolower(trim((string)explode(';', $announcedMime)[0]));
        if ($delim !== '' && ($announced === 'text/csv' || $announced === 'application/vnd.ms-excel' || $announced !== 'text/plain')) {
            $out['kind'] = 'csv'; $out['mime'] = self::MIME['csv']; $out['delimiter'] = $delim;
            return $out;
        }
        if ($delim !== '' && $announced === 'text/plain' && substr_count(substr($bytes, 0, 4096), $delim) >= 4) {
            // Plain text that is really a table: read it as one.
            $out['kind'] = 'csv'; $out['mime'] = self::MIME['csv']; $out['delimiter'] = $delim;
            return $out;
        }
        $out['kind'] = 'txt'; $out['mime'] = self::MIME['txt'];
        return $out;
    }

    /** '' when the bytes are not an image of a known kind. */
    public static function imageMime(string $b): string
    {
        if (strncmp($b, "\x89PNG\r\n\x1a\n", 8) === 0) return 'image/png';
        if (strncmp($b, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
        if (strncmp($b, 'GIF8', 4) === 0) return 'image/gif';
        if (strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP') return 'image/webp';
        if (strncmp($b, 'BM', 2) === 0 && strlen($b) > 14) return 'image/bmp';
        return '';
    }

    /**
     * 'utf-8', 'utf-16le', 'utf-16be', 'windows-1252', or '' when this is not text: a NUL byte, or more than one control
     * character in a hundred among the first 8 KiB.
     */
    public static function textEncoding(string $b): string
    {
        if (strncmp($b, "\xFF\xFE", 2) === 0) return 'utf-16le';
        if (strncmp($b, "\xFE\xFF", 2) === 0) return 'utf-16be';
        $sample = substr($b, 0, 8192);
        if (strpos($sample, "\0") !== false) return '';
        $ctrl = preg_match_all('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sample);
        if ($ctrl > max(1, (int)(strlen($sample) / 100))) return '';
        if (strncmp($b, "\xEF\xBB\xBF", 3) === 0 || mb_check_encoding($b, 'UTF-8')) return 'utf-8';
        return 'windows-1252';
    }

    /** The delimiter the first lines agree on — ',' ';' or a tab — or '' when they do not look like separated values. */
    public static function csvDelimiter(string $b, string $encoding): string
    {
        $sample = substr($b, 0, 16384);
        if ($encoding === 'utf-16le' || $encoding === 'utf-16be') {
            $sample = (string)@mb_convert_encoding($sample, 'UTF-8', $encoding === 'utf-16le' ? 'UTF-16LE' : 'UTF-16BE');
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $sample) ?: []), function ($l) { return $l !== ''; }));
        if (count($lines) < 2) return '';
        $lines = array_slice($lines, 0, 10);
        if (count($lines) === 10) array_pop($lines);   // the last sampled line may be cut mid-way
        $best = ''; $bestCount = 0;
        foreach ([',', ';', "\t"] as $d) {
            $counts = array_map(function ($l) use ($d) { return substr_count($l, $d); }, $lines);
            $c = (int)$counts[0];
            if ($c < 1) continue;
            $agree = count(array_filter($counts, function ($x) use ($c) { return $x === $c; })) >= max(2, (int)ceil(count($counts) * 0.8));
            if ($agree && $c > $bestCount) { $best = $d; $bestCount = $c; }
        }
        return $best;
    }

    private static function utf16(string $ascii): string
    {
        $out = '';
        foreach (str_split($ascii) as $ch) $out .= $ch . "\0";
        return $out;
    }
}

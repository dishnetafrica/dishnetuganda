<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/DocumentDeadline.php';

/**
 * TextReader — plain text and separated values, bounded (Batch 4, docs/58 §4, §6.2).
 *
 * The BOM is stripped, UTF-16 and Windows-1252 are re-coded to UTF-8, at most DOCUMENT_MAX_TEXT_BYTES are read (the rest is
 * `truncated`), and a CSV is parsed line by line with str_getcsv on the delimiter the sniffer found — a quoted field that spans
 * lines is a known limitation, recorded, not hidden. Rows and cells are capped like a spreadsheet's (SheetReader renders both).
 * PHP 7.4 compatible.
 */
final class TextReader
{
    /** @return array{text:string, truncated:bool, lines:int} */
    public static function text(string $bytes, string $encoding, DocumentDeadline $deadline): array
    {
        $deadline->check('text');
        $truncated = strlen($bytes) > MediaPolicy::DOCUMENT_MAX_TEXT_BYTES;
        $s = $truncated ? substr($bytes, 0, MediaPolicy::DOCUMENT_MAX_TEXT_BYTES) : $bytes;
        $s = self::utf8($s, $encoding);
        $lines = trim($s) === '' ? 0 : count(preg_split('/\r\n|\r|\n/', rtrim($s, "\r\n")) ?: []);
        return ['text' => $s, 'truncated' => $truncated, 'lines' => $lines];
    }

    /**
     * @return array{sheets:array, cells:int, truncated:bool, rows_total:int}  one sheet named CSV, in SheetReader's shape
     */
    public static function csv(string $bytes, string $encoding, string $delimiter, DocumentDeadline $deadline): array
    {
        $deadline->check('csv');
        $truncated = strlen($bytes) > MediaPolicy::DOCUMENT_MAX_TEXT_BYTES;
        $s = $truncated ? substr($bytes, 0, MediaPolicy::DOCUMENT_MAX_TEXT_BYTES) : $bytes;
        $s = self::utf8($s, $encoding);
        $rows = []; $cells = 0; $cols = 0; $rowsTotal = 0;
        foreach (preg_split('/\r\n|\r|\n/', $s) ?: [] as $i => $line) {
            if ($i % 200 === 0) $deadline->check('csv row ' . $i);
            if (trim($line) === '') continue;
            $rowsTotal++;
            if (count($rows) >= MediaPolicy::DOCUMENT_MAX_ROWS_PER_SHEET || $cells >= MediaPolicy::DOCUMENT_MAX_CELLS) { $truncated = true; continue; }
            $row = str_getcsv($line, $delimiter === '' ? ',' : $delimiter);
            if (!is_array($row)) $row = [$line];
            $row = array_map(function ($c) { return mb_substr(trim((string)$c), 0, MediaPolicy::DOCUMENT_MAX_CELL_CHARS); }, $row);
            $cells += count($row);
            $cols = max($cols, count($row));
            $rows[] = $row;
        }
        return ['sheets' => [['name' => 'CSV', 'rows' => $rows, 'rows_total' => $rowsTotal, 'cols' => $cols, 'truncated' => $truncated]],
                'cells' => $cells, 'truncated' => $truncated, 'rows_total' => $rowsTotal];
    }

    /** The bytes as UTF-8: the BOM gone, UTF-16 and Windows-1252 re-coded, anything still invalid dropped. */
    public static function utf8(string $s, string $encoding): string
    {
        if ($encoding === 'utf-16le' || $encoding === 'utf-16be') {
            $s = substr($s, 2);
            $s = (string)@mb_convert_encoding($s, 'UTF-8', $encoding === 'utf-16le' ? 'UTF-16LE' : 'UTF-16BE');
        } elseif ($encoding === 'windows-1252') {
            $s = (string)@mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) $s = substr($s, 3);
        if (!mb_check_encoding($s, 'UTF-8')) $s = (string)@mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        return $s;
    }
}

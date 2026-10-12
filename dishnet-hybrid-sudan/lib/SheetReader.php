<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/DocumentDeadline.php';
require_once __DIR__ . '/OoxmlArchive.php';
require_once __DIR__ . '/DocxReader.php';

/**
 * SheetReader — the cells of an Excel workbook, bounded, and the rendering both it and a CSV share (Batch 4, docs/58 §6.2).
 *
 * Reads xl/workbook.xml (the sheet list), its rels (which part each sheet is), xl/sharedStrings.xml and each sheet part, with
 * the same hardened XMLReader as DocxReader. Only cached VALUES are read: a formula (<f>) is never evaluated, a number is shown
 * as written (a date serial stays a number — a limitation, not hidden). Caps: DOCUMENT_MAX_SHEETS sheets, DOCUMENT_MAX_ROWS_PER_SHEET
 * rows scanned per sheet, DOCUMENT_MAX_CELLS cells in all, DOCUMENT_MAX_CELL_CHARS per cell. Exceeding a scanning cap marks the
 * result `truncated`, and the classifier then refuses to call it harmless — a sheet it did not see all of goes to a person.
 * The existing, unused lib/XlsxReader.php (ZipArchive, no limits) is deliberately not this. PHP 7.4 compatible.
 */
final class SheetReader
{
    private const MAX_SHARED_STRINGS = 50000;
    private const MAX_COLS = 64;

    /**
     * @return array{sheets:array<int,array{name:string,rows:array,rows_total:int,cols:int,truncated:bool}>, cells:int, truncated:bool, rows_total:int, sheets_total:int}
     * @throws DocumentRefused
     */
    public static function read(OoxmlArchive $zip, DocumentDeadline $deadline): array
    {
        if (!class_exists('XMLReader')) throw new DocumentRefused('extractor_unavailable', 'XMLReader');
        $sheetsMeta = self::workbook($zip, $deadline);
        $shared = $zip->has('xl/sharedStrings.xml') ? self::sharedStrings($zip, $deadline) : ['strings' => [], 'truncated' => false];
        $truncated = $shared['truncated'];
        $out = []; $cells = 0; $rowsTotal = 0;
        if (count($sheetsMeta) > MediaPolicy::DOCUMENT_MAX_SHEETS) $truncated = true;
        foreach (array_slice($sheetsMeta, 0, MediaPolicy::DOCUMENT_MAX_SHEETS) as $meta) {
            $deadline->check('sheet ' . $meta['name']);
            if (!$zip->has($meta['part'])) continue;
            $sheet = self::sheet($zip->read($meta['part']), $meta['name'], $shared['strings'], $cells, $deadline);
            $cells += $sheet['cells'];
            $rowsTotal += $sheet['rows_total'];
            if ($sheet['truncated']) $truncated = true;
            unset($sheet['cells']);
            $out[] = $sheet;
        }
        return ['sheets' => $out, 'cells' => $cells, 'truncated' => $truncated, 'rows_total' => $rowsTotal, 'sheets_total' => count($sheetsMeta)];
    }

    /**
     * The text the classifier reads (every scanned cell) or the assistant reads (at most $showSheets sheets × $showRows ×
     * $showCols): `Sheet "Name" (R rows, C columns)` then rows of ` | `-separated cells.
     */
    public static function render(array $sheets, int $showSheets = 0, int $showRows = 0, int $showCols = 0): string
    {
        $out = '';
        $list = $showSheets > 0 ? array_slice($sheets, 0, $showSheets) : $sheets;
        foreach ($list as $s) {
            $out .= sprintf("Sheet \"%s\" (%d rows, %d columns)\n", str_replace(["\n", "\r", '"'], ' ', (string)$s['name']), (int)$s['rows_total'], (int)$s['cols']);
            $rows = $showRows > 0 ? array_slice((array)$s['rows'], 0, $showRows) : (array)$s['rows'];
            foreach ($rows as $row) {
                $cellsOut = $showCols > 0 ? array_slice((array)$row, 0, $showCols) : (array)$row;
                $out .= implode(' | ', array_map(function ($c) { return str_replace(["\n", "\r", '|'], ' ', (string)$c); }, $cellsOut)) . "\n";
            }
            $out .= "\n";
        }
        return rtrim($out) . ($out !== '' ? "\n" : '');
    }

    /** @return array<int,array{name:string,part:string}> */
    private static function workbook(OoxmlArchive $zip, DocumentDeadline $deadline): array
    {
        $wb = $zip->read('xl/workbook.xml');
        DocxReader::guard($wb);
        $rels = [];
        if ($zip->has('xl/_rels/workbook.xml.rels')) {
            $rx = $zip->read('xl/_rels/workbook.xml.rels');
            DocxReader::guard($rx);
            $r = DocxReader::reader($rx);
            try {
                while (@$r->read()) {
                    if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'Relationship') {
                        $id = (string)$r->getAttribute('Id'); $target = (string)$r->getAttribute('Target');
                        if ($id !== '' && $target !== '') $rels[$id] = $target;
                    }
                }
            } finally { libxml_clear_errors(); $r->close(); }
        }
        $sheets = [];
        $r = DocxReader::reader($wb);
        try {
            while (@$r->read()) {
                if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'sheet') {
                    $name = (string)$r->getAttribute('name');
                    $rid  = (string)$r->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    if ($rid === '') $rid = (string)$r->getAttribute('r:id');
                    $target = $rels[$rid] ?? '';
                    if ($target === '') $target = 'worksheets/sheet' . (count($sheets) + 1) . '.xml';
                    $part = ltrim($target, '/');
                    if (strpos($part, 'xl/') !== 0) $part = 'xl/' . $part;
                    $sheets[] = ['name' => $name !== '' ? $name : 'Sheet' . (count($sheets) + 1), 'part' => $part];
                    if (count($sheets) > MediaPolicy::DOCUMENT_MAX_SHEETS * 4) break;
                }
            }
            $errors = libxml_get_errors();
        } finally { libxml_clear_errors(); $r->close(); }
        if ($errors !== []) throw new DocumentRefused('malformed_document', 'workbook.xml is not well-formed');
        $deadline->check('workbook');
        return $sheets;
    }

    /** @return array{strings:string[], truncated:bool} */
    private static function sharedStrings(OoxmlArchive $zip, DocumentDeadline $deadline): array
    {
        $xml = $zip->read('xl/sharedStrings.xml');
        DocxReader::guard($xml);
        $r = DocxReader::reader($xml);
        $strings = []; $cur = null; $truncated = false; $nodes = 0;
        $advance = true;   // next() lands ON the following sibling: after a skipped subtree the loop must not read() past it
        try {
            while (true) {
                if ($advance && !@$r->read()) break;
                $advance = true;
                $nodes++;
                if ($nodes % 1000 === 0) $deadline->check('sharedStrings node ' . $nodes);
                if ($r->nodeType === XMLReader::ELEMENT) {
                    if ($r->localName === 'si') {
                        if (count($strings) >= self::MAX_SHARED_STRINGS) { $truncated = true; break; }
                        $cur = '';
                    } elseif ($r->localName === 'rPh') {
                        if (!$r->next()) break;   // phonetic runs are not the cell's text
                        $advance = false;
                        continue;
                    } elseif ($r->localName === 't' && $cur !== null) {
                        $cur .= (string)$r->readString();
                    }
                } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'si' && $cur !== null) {
                    $strings[] = mb_substr($cur, 0, MediaPolicy::DOCUMENT_MAX_CELL_CHARS);
                    $cur = null;
                }
            }
            $errors = libxml_get_errors();
        } finally { libxml_clear_errors(); $r->close(); }
        if ($errors !== []) throw new DocumentRefused('malformed_document', 'sharedStrings.xml is not well-formed');
        return ['strings' => $strings, 'truncated' => $truncated];
    }

    /** @return array{name:string,rows:array,rows_total:int,cols:int,truncated:bool,cells:int} */
    private static function sheet(string $xml, string $name, array $shared, int $cellsSoFar, DocumentDeadline $deadline): array
    {
        DocxReader::guard($xml);
        $r = DocxReader::reader($xml);
        $rows = []; $rowsTotal = 0; $cols = 0; $cells = 0; $truncated = false; $nodes = 0;
        $row = null; $cellRef = ''; $cellType = ''; $cellText = null; $inCell = false; $inIs = false;
        $advance = true;   // next() lands ON the following sibling: after a skipped subtree the loop must not read() past it
        try {
            while (true) {
                if ($advance && !@$r->read()) break;
                $advance = true;
                $nodes++;
                if ($nodes % 1000 === 0) $deadline->check('sheet node ' . $nodes);
                $type = $r->nodeType;
                if ($type === XMLReader::ELEMENT) {
                    $ln = $r->localName;
                    if ($ln === 'row') {
                        $rowsTotal++;
                        if (count($rows) >= MediaPolicy::DOCUMENT_MAX_ROWS_PER_SHEET || $cellsSoFar + $cells >= MediaPolicy::DOCUMENT_MAX_CELLS) {
                            $truncated = true;
                            if ($r->isEmptyElement) continue;
                            if (!$r->next()) break;
                            $advance = false;
                            continue;
                        }
                        $row = [];
                    } elseif ($ln === 'c' && $row !== null) {
                        $inCell = true; $cellRef = (string)$r->getAttribute('r'); $cellType = (string)$r->getAttribute('t'); $cellText = null;
                        if ($r->isEmptyElement) { $inCell = false; }
                    } elseif ($inCell && $ln === 'f') {
                        continue;   // a formula is never read — its text node is not a <v>, so only the cached value below counts
                    } elseif ($inCell && $ln === 'v') {
                        $v = (string)$r->readString();
                        if ($cellType === 's') { $cellText = $shared[(int)$v] ?? ''; }
                        elseif ($cellType === 'b') { $cellText = $v === '1' ? 'TRUE' : 'FALSE'; }
                        else { $cellText = $v; }
                    } elseif ($inCell && $ln === 'is') {
                        $inIs = true; $cellText = (string)$cellText;
                    } elseif ($inIs && $ln === 't') {
                        $cellText .= (string)$r->readString();
                    }
                } elseif ($type === XMLReader::END_ELEMENT) {
                    $ln = $r->localName;
                    if ($ln === 'is') { $inIs = false; }
                    elseif ($ln === 'c' && $inCell) {
                        $inCell = false;
                        if ($cellText !== null && $row !== null) {
                            $col = self::columnIndex($cellRef);
                            if ($col < 0) $col = count($row);
                            if ($col < self::MAX_COLS) {
                                $row[$col] = mb_substr(trim((string)$cellText), 0, MediaPolicy::DOCUMENT_MAX_CELL_CHARS);
                                $cells++;
                                $cols = max($cols, $col + 1);
                            } else { $truncated = true; }
                        }
                    } elseif ($ln === 'row' && $row !== null) {
                        if ($row !== []) {
                            ksort($row);
                            $dense = [];
                            $last = max(array_keys($row));
                            for ($i = 0; $i <= $last; $i++) $dense[] = $row[$i] ?? '';
                            $rows[] = $dense;
                        }
                        $row = null;
                    }
                }
            }
            $errors = libxml_get_errors();
        } finally { libxml_clear_errors(); $r->close(); }
        if ($errors !== []) throw new DocumentRefused('malformed_document', 'a sheet part is not well-formed');
        return ['name' => $name, 'rows' => $rows, 'rows_total' => $rowsTotal, 'cols' => $cols, 'truncated' => $truncated, 'cells' => $cells];
    }

    /** "B3" → 1; "AA10" → 26; '' → -1 */
    public static function columnIndex(string $ref): int
    {
        if (preg_match('/^([A-Z]{1,3})\d*$/i', $ref, $m) !== 1) return -1;
        $n = 0;
        foreach (str_split(strtoupper($m[1])) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n - 1;
    }
}

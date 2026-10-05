<?php
declare(strict_types=1);
/**
 * document_fixtures.php — deterministic, synthetic documents for the Batch 4 tests (docs/58 §14), built here byte for byte.
 *
 * A minimal zip writer (stored or deflated members, with per-member overrides for the declared size, the flags and the method,
 * so a bomb or an encrypted entry can be declared), a Word document, an Excel workbook, three kinds of PDF (text layer, scanned,
 * encrypted), an Evolution envelope for a document (optionally wrapped the ways Evolution wraps one), and the scans the
 * retention checks use. No fixture holds a real name, number, account or document. PHP 7.4 compatible.
 */

function vd_hit(int $port, string $path, $json = null, int $timeout = 40): string
{
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];
    if ($json !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($json); $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/json']; }
    curl_setopt_array($ch, $o);
    $r = curl_exec($ch); curl_close($ch);
    return $r === false ? '' : (string)$r;
}
function vd_state(int $port): array { return json_decode(vd_hit($port, '/__test/state'), true) ?: []; }
function vd_boot(string $router, int $base, string $sig): array
{
    foreach (range(0, 11) as $slot) {
        $cand = $base + ((getmypid() + $slot * 13) % 70);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 40; $i++) {
            $r = vd_hit($cand, '/__test/state', null, 3);
            if ($r !== '') { if (strpos($r, $sig) !== false) return [$p, $cand]; break; }
            usleep(100000);
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
}
function vd_sha(string $bytes): string { return hash('sha256', $bytes); }

/** A zip: stored or deflated members; per-member overrides: usize (the declared uncompressed size), flags, method. */
function vd_zip(array $members, bool $deflate = true, array $ov = []): string
{
    $out = ''; $cd = '';
    foreach ($members as $name => $data) {
        $o = $ov[$name] ?? [];
        $method = $o['method'] ?? ($deflate ? 8 : 0);
        $comp = $method === 8 ? gzdeflate($data, 9) : $data;
        $crc = crc32($data); $usize = $o['usize'] ?? strlen($data); $flags = $o['flags'] ?? 0;
        $offset = strlen($out);
        $out .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, $flags, $method, 0, 0, $crc, strlen($comp), $usize, strlen($name), 0) . $name . $comp;
        $cd  .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, $flags, $method, 0, 0, $crc, strlen($comp), $usize, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    return $out . $cd . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($members), count($members), strlen($cd), strlen($out), 0);
}
/** A Word document: paragraphs (a row of cells as an array). Options: ct (content type), vba, deleted, doctype, pad, extra, ov. */
function vd_docx(array $paragraphs, array $o = []): string
{
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="' . ($o['ct'] ?? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml') . '"/>'
        . (!empty($o['vba']) ? '<Override PartName="/word/vbaProject.bin" ContentType="application/vnd.ms-office.vbaProject"/>' : '') . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
    $body = '';
    foreach ($paragraphs as $p) {
        if (is_array($p)) {
            $body .= '<w:tbl><w:tr>' . implode('', array_map(function ($c) { return '<w:tc><w:p><w:r><w:t>' . htmlspecialchars((string)$c, ENT_XML1) . '</w:t></w:r></w:p></w:tc>'; }, $p)) . '</w:tr></w:tbl>';
        } else {
            $body .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars((string)$p, ENT_XML1) . '</w:t></w:r></w:p>';
        }
    }
    if (!empty($o['deleted'])) $body .= '<w:p><w:del><w:r><w:delText>' . htmlspecialchars((string)$o['deleted'], ENT_XML1) . '</w:delText></w:r></w:del></w:p>';
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . ($o['doctype'] ?? '')
         . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . ($o['pad'] ?? '') . $body . '</w:body></w:document>';
    $members = ['[Content_Types].xml' => $ct, '_rels/.rels' => $rels, 'word/document.xml' => $doc] + ($o['extra'] ?? []);
    return vd_zip($members, $o['deflate'] ?? true, $o['ov'] ?? []);
}
/** An Excel workbook: sheet name => rows; a cell is a string (shared), an int, 'inline:text', or ['f' => formula, 'v' => cached]. */
function vd_xlsx(array $sheets, array $o = []): string
{
    $shared = []; $sheetXml = []; $wbSheets = ''; $wbRels = ''; $ctOv = ''; $i = 0;
    foreach ($sheets as $name => $rows) {
        $i++; $rowsXml = ''; $r = 0;
        foreach ($rows as $row) {
            $r++; $cells = ''; $c = 0;
            foreach ($row as $val) {
                $ref = chr(65 + $c) . $r; $c++;
                if (is_int($val) || is_float($val)) { $cells .= '<c r="' . $ref . '"><v>' . $val . '</v></c>'; }
                elseif (is_array($val)) { $cells .= '<c r="' . $ref . '"><f>' . htmlspecialchars((string)$val['f'], ENT_XML1) . '</f><v>' . $val['v'] . '</v></c>'; }
                elseif (strpos((string)$val, 'inline:') === 0) { $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars(substr((string)$val, 7), ENT_XML1) . '</t></is></c>'; }
                else { $idx = array_search((string)$val, $shared, true); if ($idx === false) { $shared[] = (string)$val; $idx = count($shared) - 1; } $cells .= '<c r="' . $ref . '" t="s"><v>' . $idx . '</v></c>'; }
            }
            $rowsXml .= '<row r="' . $r . '">' . $cells . '</row>';
        }
        $sheetXml['xl/worksheets/sheet' . $i . '.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rowsXml . '</sheetData></worksheet>';
        $wbSheets .= '<sheet name="' . htmlspecialchars((string)$name, ENT_XML1) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $wbRels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $ctOv .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">'
         . implode('', array_map(function ($s) { return '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>'; }, $shared)) . '</sst>';
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="' . ($o['ct'] ?? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml') . '"/>' . $ctOv
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>';
    $wbr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '<Relationship Id="rId99" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>';
    $members = ['[Content_Types].xml' => $ct, '_rels/.rels' => $rels, 'xl/workbook.xml' => $wb, 'xl/_rels/workbook.xml.rels' => $wbr, 'xl/sharedStrings.xml' => $sst] + $sheetXml;
    return vd_zip($members, true, $o['ov'] ?? []);
}
/** A minimal PDF: text (a Flate content stream with Tj and a font), scanned (an image XObject, no text), encrypted (text + /Encrypt). */
function vd_pdf(string $kind, string $text = 'Hello from a PDF'): string
{
    $objs = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>'];
    if ($kind === 'scanned') {
        $objs[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>';
        $content = 'q 612 0 0 792 0 0 cm /Im0 Do Q';
        $objs[4] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $img = str_repeat("\xff", 16);
        $objs[5] = "<< /Type /XObject /Subtype /Image /Width 4 /Height 4 /ColorSpace /DeviceGray /BitsPerComponent 8 /Length " . strlen($img) . " >>\nstream\n" . $img . "\nendstream";
    } else {
        $objs[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>';
        $content = 'BT /F1 12 Tf 72 712 Td (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text) . ') Tj ET';
        $comp = gzcompress($content);
        $objs[4] = "<< /Length " . strlen($comp) . " /Filter /FlateDecode >>\nstream\n" . $comp . "\nendstream";
        $objs[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    }
    if ($kind === 'encrypted') $objs[6] = '<< /Filter /Standard /V 1 /R 2 /Length 40 /P -1 /O <0000> /U <0000> >>';
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [];
    foreach ($objs as $n => $body) { $offsets[$n] = strlen($pdf); $pdf .= "$n 0 obj\n" . $body . "\nendobj\n"; }
    $xref = strlen($pdf); $count = max(array_keys($objs)) + 1;
    $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    return $pdf . "trailer\n<< /Size $count /Root 1 0 R" . ($kind === 'encrypted' ? ' /Encrypt 6 0 R' : '') . " >>\nstartxref\n$xref\n%%EOF\n";
}
/**
 * An Evolution messages.upsert envelope: a document (optionally captioned, optionally wrapped — 'wrap' lists the wrappers
 * outermost first), a text, or an image.
 */
function vd_envelope(string $kind, string $id, string $phone, array $o = []): array
{
    $jid = $phone . '@s.whatsapp.net';
    $cap = (string)($o['caption'] ?? '');
    if ($kind === 'document') {
        $dm = ['url' => 'https://mmg.whatsapp.net/v/t62.7119-24/x.enc', 'mimetype' => $o['mimetype'] ?? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
               'fileLength' => (string)($o['bytes'] ?? 20480), 'fileName' => $o['fileName'] ?? 'document.docx', 'pageCount' => 1] + ($cap !== '' ? ['caption' => $cap] : []);
        $message = ['documentMessage' => $dm];
    } elseif ($kind === 'image') {
        $message = ['imageMessage' => ['url' => 'https://mmg.whatsapp.net/v/t62.7118-24/y.enc', 'mimetype' => 'image/png', 'fileLength' => '20480', 'height' => 480, 'width' => 640] + ($cap !== '' ? ['caption' => $cap] : [])];
    } else {
        $message = ['conversation' => (string)($o['text'] ?? 'Hello')];
    }
    foreach (array_reverse((array)($o['wrap'] ?? [])) as $w) $message = [$w => ['message' => $message]];
    return ['event' => 'messages.upsert', 'instance' => $o['instance'] ?? 'sj-sales', 'data' => [
        'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => $jid],
        'pushName' => 'Document Tester', 'messageType' => array_keys($message)[0], 'messageTimestamp' => time(), 'message' => $message]];
}
/** Files under $dir (the database itself excluded) holding any of the needles. */
function vd_scan(string $dir, array $needles): array
{
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        if (strpos($f->getFilename(), 'plugin.sqlite3') === 0) continue;
        $c = (string)@file_get_contents($f->getPathname());
        foreach ($needles as $n) if ($n !== '' && strpos($c, $n) !== false) { $hits[] = substr($f->getPathname(), strlen($dir)) . ' has ' . substr($n, 0, 12); break; }
    }
    return $hits;
}
/** Every row of every table that could hold the needle — the database side of the retention scan. */
function vd_dbscan(PDO $pdo, array $needles, array $skipTables = []): array
{
    $hits = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (in_array($t, $skipTables, true)) continue;
        foreach ($pdo->query("SELECT * FROM \"{$t}\"")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $blob = json_encode($row, JSON_UNESCAPED_UNICODE) ?: implode(' ', array_map('strval', $row));
            foreach ($needles as $n) if ($n !== '' && strpos($blob, $n) !== false) { $hits[] = $t . ' has ' . substr($n, 0, 12); break; }
        }
    }
    return array_values(array_unique($hits));
}
/** A file's PHP code with every comment removed — the comments may name what the code must not depend on. */
function vd_codeOf(string $file): string
{
    $out = '';
    foreach (token_get_all((string)file_get_contents($file)) as $k) {
        if (is_array($k)) { if (in_array($k[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $out .= $k[1]; }
        else $out .= $k;
    }
    return $out;
}
function vd_row(PDO $pdo, string $waId): array
{
    $st = $pdo->prepare('SELECT * FROM wa_media WHERE wa_message_id = ?'); $st->execute([$waId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($r) ? $r : [];
}
function vd_brief(array $r): array
{
    return ['status' => $r['status'] ?? null, 'reason' => $r['failure_reason'] ?? null, 'attempts' => (int)($r['attempts'] ?? 0),
            'kind' => $r['understanding_kind'] ?? null, 'text' => $r['understanding'] ?? null, 'bytes' => isset($r['fetched_bytes']) ? (int)$r['fetched_bytes'] : null];
}
/** Every table that holds money, and how many rows each has — what a receipt, a statement or an invoice must never change. */
function vd_money(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND (name LIKE '%pay%' OR name LIKE '%invoice%' OR name LIKE '%cash%' OR name LIKE '%ledger%' OR name LIKE '%money%' OR name LIKE '%receipt%' OR name LIKE '%wallet%' OR name LIKE '%transaction%') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $out[$t] = (int)$pdo->query("SELECT COUNT(*) FROM \"{$t}\"")->fetchColumn();
    }
    return $out;
}
/**
 * A PDF built to order (Slice 4b, docs/58 D-1 = P-1): pages of lines, the font shapes the reader knows, the layouts PDF
 * writers produce, and deliberate breakage — every byte deterministic, nothing real in it.
 *
 * $o:  pages    array of page specs: lines (array of strings) · font (resource name, default F1) · tj (TJ arrays with kerning)
 *               · hex (hex strings) · form (an XObject name to draw) · inline (an inline image first) · raw (content override)
 *      fonts    name => ['type' => 'simple'|'cid', 'enc' => 'WinAnsiEncoding'|'MacRomanEncoding'|null, 'diff' => [code => glyph],
 *               'map' => [char => code] (how the builder encodes those chars), 'tounicode' => bool, 'type3' => bool]
 *      forms    name => ['lines' => [...], 'font' => 'F1', 'nested' => 'Fy', 'self' => bool, 'image' => bool]
 *      flate    compress content streams (default true) · filter 'a85' (ASCII85 over Flate) | 'lzw' (an unsupported filter)
 *      objstm   PDF 1.5 layout: an object stream for every dictionary object, an xref STREAM with the PNG predictor, no table
 *      inherit  /Resources on the Pages node rather than on each page · count (override the declared /Count) · encrypt
 *      pad      bytes of comment padding after the header · extra_objects (N dummy objects) · bomb (a content stream that
 *               inflates to 9 MiB) · lying_length (a wrong /Length) · broken 'nocatalog' | 'nopages' | 'junk'
 */
function vd_pdfx(array $o): string
{
    $pages  = $o['pages'] ?? [['lines' => ['Hello from a PDF']]];
    $fonts  = $o['fonts'] ?? ['F1' => ['type' => 'simple', 'enc' => 'WinAnsiEncoding']];
    $forms  = $o['forms'] ?? [];
    $flate  = $o['flate'] ?? true;
    $filter = $o['filter'] ?? null;
    $objstm = !empty($o['objstm']);
    $inherit = !empty($o['inherit']);

    // Which characters each composite font must map: sequential codes from 1; digits through a bfrange at 0xA0.
    $cidMaps = [];
    $allText = function (string $font) use ($pages, $forms): string {
        $t = '';
        foreach ($pages as $pg) if (($pg['font'] ?? 'F1') === $font) $t .= implode('', $pg['lines'] ?? []);
        foreach ($forms as $fm) if (($fm['font'] ?? 'F1') === $font) $t .= implode('', $fm['lines'] ?? []);
        return $t;
    };
    foreach ($fonts as $name => $spec) {
        if (($spec['type'] ?? 'simple') !== 'cid') continue;
        $map = []; $next = 1;
        foreach (preg_split('//u', $allText($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (isset($map[$ch])) continue;
            $map[$ch] = ctype_digit($ch) ? 0xA0 + (int)$ch : $next++;
        }
        $cidMaps[$name] = $map;
    }
    $encode = function (string $text, string $font, bool $hex) use ($fonts, $cidMaps): string {
        $spec = $fonts[$font] ?? ['type' => 'simple'];
        if (($spec['type'] ?? 'simple') === 'cid') {
            $h = '';
            foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) $h .= sprintf('%04X', $cidMaps[$font][$ch] ?? 0);
            return '<' . $h . '>';
        }
        $bytes = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (isset($spec['map'][$ch])) { $bytes .= chr((int)$spec['map'][$ch]); continue; }
            // MacRoman through iconv (mbstring has no Macintosh table); WinAnsi through mbstring's Windows-1252.
            $b = ($spec['enc'] ?? 'WinAnsiEncoding') === 'MacRomanEncoding' ? @iconv('UTF-8', 'MACINTOSH', $ch) : @mb_convert_encoding($ch, 'Windows-1252', 'UTF-8');
            $bytes .= is_string($b) && $b !== '' ? $b : '?';
        }
        if ($hex) return '<' . strtoupper(bin2hex($bytes)) . '>';
        $out = '';
        foreach (str_split($bytes) as $c) {
            $n = ord($c);
            if ($c === '\\' || $c === '(' || $c === ')') $out .= '\\' . $c;
            elseif ($n < 32 || $n > 126) $out .= sprintf('\\%03o', $n);
            else $out .= $c;
        }
        return '(' . $out . ')';
    };
    $content = function (array $spec) use ($encode): string {
        if (isset($spec['raw'])) return (string)$spec['raw'];
        $font = $spec['font'] ?? 'F1';
        $c = '';
        if (!empty($spec['inline'])) $c .= "q 10 0 0 10 50 50 cm BI /W 2 /H 2 /CS /G /BPC 8 ID \x00\xff\xff\x00 EI Q\n";
        $y = 720;
        foreach ($spec['lines'] ?? [] as $line) {
            if (!empty($spec['tj'])) {
                $words = preg_split('/ /u', $line) ?: [$line];
                $parts = [];
                foreach ($words as $w) {
                    if (mb_strlen($w) > 3) $parts[] = $encode(mb_substr($w, 0, 2), $font, false) . ' -20 ' . $encode(mb_substr($w, 2), $font, false);
                    else $parts[] = $encode($w, $font, false);
                }
                $c .= sprintf("BT /%s 12 Tf 72 %d Td [%s] TJ ET\n", $font, $y, implode(' -300 ', $parts));
            } else {
                $c .= sprintf("BT /%s 12 Tf 72 %d Td %s Tj ET\n", $font, $y, $encode($line, $font, !empty($spec['hex'])));
            }
            $y -= 14;
        }
        if (!empty($spec['form'])) $c .= 'q 1 0 0 1 0 0 cm /' . $spec['form'] . " Do Q\n";
        return $c;
    };

    $objs = [];      // num => body (dictionary objects, may move into an object stream)
    $streams = [];   // num => [dict-without-length, data]  (always top level)
    $n = 3;          // 1 catalogue, 2 pages
    $fontRefs = []; $fontObjs = [];
    foreach ($fonts as $name => $spec) {
        $fnum = $n++;
        $fontRefs[$name] = "$fnum 0 R";
        if (($spec['type'] ?? 'simple') === 'cid') {
            $desc = $n++; $tu = $n++;
            $objs[$fnum] = "<< /Type /Font /Subtype /Type0 /BaseFont /ABCDEF+TestSans /Encoding /Identity-H /DescendantFonts [$desc 0 R] /ToUnicode $tu 0 R >>";
            $objs[$desc] = '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /TestSans /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /DW 500 >>';
            $chars = []; foreach ($cidMaps[$name] as $ch => $code) if ($code < 0xA0) $chars[] = sprintf('<%04X> <%s>', $code, strtoupper(bin2hex(mb_convert_encoding($ch, 'UTF-16BE', 'UTF-8'))));
            $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CMapName /Adobe-Identity-UCS def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
                  . count($chars) . " beginbfchar\n" . implode("\n", $chars) . "\nendbfchar\n1 beginbfrange\n<00A0> <00A9> <0030>\nendbfrange\nendcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
            $streams[$tu] = ['', $cmap];
        } else {
            $encName = $spec['enc'] ?? 'WinAnsiEncoding';
            if (!empty($spec['diff'])) {
                $d = []; foreach ($spec['diff'] as $code => $glyph) $d[] = $code . ' /' . $glyph;
                $enc = '<< /BaseEncoding /' . $encName . ' /Differences [' . implode(' ', $d) . '] >>';
            } else {
                $enc = $encName === null ? '' : '/' . $encName;
            }
            $sub = !empty($spec['type3']) ? 'Type3' : 'TrueType';
            $extra = !empty($spec['type3']) ? ' /FontMatrix [0.001 0 0 0.001 0 0] /CharProcs << >> /FontBBox [0 0 0 0]' : '';
            $tuPart = '';
            if (!empty($spec['tounicode'])) {
                $tu = $n++;
                $chars = [];
                foreach (preg_split('//u', $allText($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                    $b = isset($spec['map'][$ch]) ? (int)$spec['map'][$ch] : ord((string)@mb_convert_encoding($ch, 'Windows-1252', 'UTF-8'));
                    $chars[sprintf('<%02X>', $b)] = sprintf('<%02X> <%s>', $b, strtoupper(bin2hex(mb_convert_encoding($ch, 'UTF-16BE', 'UTF-8'))));
                }
                $cmap = "begincmap\n1 begincodespacerange\n<00> <FF>\nendcodespacerange\n" . count($chars) . " beginbfchar\n" . implode("\n", $chars) . "\nendbfchar\nendcmap\n";
                $streams[$tu] = ['', $cmap];
                $tuPart = " /ToUnicode $tu 0 R";
            }
            $objs[$fnum] = "<< /Type /Font /Subtype /$sub /BaseFont /TestSans" . ($enc !== '' ? " /Encoding $enc" : '') . $extra . $tuPart . ' >>';
        }
    }
    $fontDict = '/Font << ' . implode(' ', array_map(function ($k, $v) { return "/$k $v"; }, array_keys($fontRefs), $fontRefs)) . ' >>';
    // Form XObjects (streams), with their own resources; nested and self references by name.
    $formNums = []; foreach ($forms as $name => $fm) $formNums[$name] = $n++;
    $xobjDict = $formNums !== [] ? ' /XObject << ' . implode(' ', array_map(function ($k, $v) { return "/$k $v 0 R"; }, array_keys($formNums), $formNums)) . ' >>' : '';
    $resources = '<< ' . $fontDict . $xobjDict . ' >>';
    foreach ($forms as $name => $fm) {
        $fc = $content(['lines' => $fm['lines'] ?? [], 'font' => $fm['font'] ?? 'F1']);
        if (!empty($fm['nested'])) $fc .= 'q /' . $fm['nested'] . " Do Q\n";
        if (!empty($fm['self'])) $fc .= 'q /' . $name . " Do Q\n";
        $sub = !empty($fm['image']) ? 'Image' : 'Form';
        $dict = "/Type /XObject /Subtype /$sub" . ($sub === 'Form' ? " /BBox [0 0 612 792] /Resources $resources" : ' /Width 2 /Height 2 /ColorSpace /DeviceGray /BitsPerComponent 8');
        $streams[$formNums[$name]] = [$dict, $sub === 'Form' ? $fc : "\x00\xff\xff\x00"];
    }
    // Pages and their content streams.
    $kids = [];
    foreach ($pages as $i => $pg) {
        $pnum = $n++; $cnum = $n++;
        $kids[] = "$pnum 0 R";
        $objs[$pnum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]" . ($inherit ? '' : " /Resources $resources") . " /Contents $cnum 0 R >>";
        $data = $content($pg);
        if (!empty($o['bomb']) && $i === 0) $data = str_repeat(' ', 9 * 1024 * 1024) . $data;
        $streams[$cnum] = ['', $data];
    }
    $count = $o['count'] ?? count($pages);
    $objs[1] = ($o['broken'] ?? '') === 'nocatalog' ? '<< /Type /Foo >>' : '<< /Type /Catalog /Pages 2 0 R >>';
    $objs[2] = ($o['broken'] ?? '') === 'nopages' ? '<< /Type /Pages /Kids [] /Count 0 >>'
             : '<< /Type /Pages /Kids [' . implode(' ', $kids) . "] /Count $count" . ($inherit ? " /Resources $resources" : '') . ' >>';
    if (($o['broken'] ?? '') === 'nopages') { foreach (array_keys($objs) as $k) if ($k > 2 && strpos($objs[$k], '/Type /Page ') !== false) unset($objs[$k]); }
    for ($i = 0; $i < (int)($o['extra_objects'] ?? 0); $i++) $objs[$n++] = '<< /Foo ' . $i . ' >>';
    if (!empty($o['encrypt'])) { $encNum = $n++; $objs[$encNum] = '<< /Filter /Standard /V 1 /R 2 /Length 40 /P -1 /O <0000> /U <0000> >>'; }

    // Serialise: the stream bodies with their filters.
    $writeStream = function (string $dictInner, string $data, bool $isContent) use ($flate, $filter, $o): string {
        $filt = '';
        if ($isContent && $filter === 'lzw') { $filt = ' /Filter /LZWDecode'; }
        elseif ($isContent && $filter === 'a85') { $data = vd_a85(gzcompress($data)); $filt = ' /Filter [/ASCII85Decode /FlateDecode]'; }
        elseif ($flate) { $data = gzcompress($data); $filt = ' /Filter /FlateDecode'; }
        $len = !empty($o['lying_length']) && $isContent ? strlen($data) + 777 : strlen($data);
        return '<< ' . trim($dictInner . " /Length $len" . $filt) . " >>\nstream\n" . $data . "\nendstream";
    };
    $pdf = "%PDF-1." . ($objstm ? '5' : '4') . "\n%\xE2\xE3\xCF\xD3\n";
    if (!empty($o['pad'])) $pdf .= '% ' . str_repeat('x', (int)$o['pad']) . "\n";
    $offsets = [];
    $contentNums = []; foreach ($pages as $i => $pg) {} // (content streams are those not in $formNums and not ToUnicode: tracked below)
    $cidTu = []; foreach ($streams as $k => $s) if ($s[0] === '' && strpos($s[1], 'begincmap') !== false) $cidTu[$k] = true;
    $inStm = []; $topLevel = [];
    if ($objstm) {
        $stmNum = $n++; $xrefNum = $n++;
        $header = ''; $body = ''; $members = [];
        foreach ($objs as $num => $b) { $members[$num] = strlen($body); $header .= "$num " . strlen($body) . ' '; $body .= $b . "\n"; }
        $stmData = $header . "\n" . $body;
        $streams[$stmNum] = ['/Type /ObjStm /N ' . count($objs) . ' /First ' . (strlen($header) + 1), $stmData];
        foreach ($objs as $num => $b) $inStm[$num] = $stmNum;
        $objs = [];
    }
    foreach ($objs as $num => $b) { $offsets[$num] = strlen($pdf); $pdf .= "$num 0 obj\n$b\nendobj\n"; }
    foreach ($streams as $num => $s) {
        $isContent = !isset($formNums[$num]) && !isset($cidTu[$num]) && strpos($s[0], '/ObjStm') === false && strpos($s[0], '/XObject') === false;
        $offsets[$num] = strlen($pdf);
        $pdf .= "$num 0 obj\n" . $writeStream($s[0], $s[1], $isContent && $s[0] === '') . "\nendobj\n";
    }
    $size = $n + ($objstm ? 0 : 0);
    if ($objstm) {
        // The xref stream: W [1 4 2]; type 1 = at offset, type 2 = in object stream (index); PNG "Up" predictor, Columns 7.
        $rows = [];
        $idx = []; $i = 0; foreach (array_keys($inStm) as $num) $idx[$num] = $i++;
        for ($num = 0; $num < $size; $num++) {
            if ($num === 0) $rows[] = "\x00" . pack('N', 0) . pack('n', 0xFFFF);
            elseif (isset($inStm[$num])) $rows[] = "\x02" . pack('N', $inStm[$num]) . pack('n', $idx[$num]);
            elseif (isset($offsets[$num]) || $num === $xrefNum) $rows[] = "\x01" . pack('N', $num === $xrefNum ? strlen($pdf) : $offsets[$num]) . pack('n', 0);
            else $rows[] = "\x00" . pack('N', 0) . pack('n', 0);
        }
        $prev = str_repeat("\0", 7); $enc = '';
        foreach ($rows as $row) { $enc .= "\x02"; for ($k = 0; $k < 7; $k++) $enc .= chr((ord($row[$k]) - ord($prev[$k])) & 0xFF); $prev = $row; }
        $xrefOff = strlen($pdf);
        $comp = gzcompress($enc);
        $pdf .= "$xrefNum 0 obj\n<< /Type /XRef /Size $size /W [1 4 2] /Root 1 0 R" . (!empty($o['encrypt']) ? " /Encrypt $encNum 0 R" : '')
              . " /Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 7 >> /Length " . strlen($comp) . " >>\nstream\n" . $comp . "\nendstream\nendobj\n";
        return $pdf . "startxref\n$xrefOff\n%%EOF\n";
    }
    $xref = strlen($pdf);
    if (($o['broken'] ?? '') === 'junk') return $pdf . "xref\n0 " . $size . "\n" . str_repeat('garbage ', 20) . "\ntrailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n999999999\n%%EOF\n";
    $pdf .= "xref\n0 $size\n0000000000 65535 f \n";
    for ($i = 1; $i < $size; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    return $pdf . "trailer\n<< /Size $size /Root 1 0 R" . (!empty($o['encrypt']) ? " /Encrypt $encNum 0 R" : '') . " >>\nstartxref\n$xref\n%%EOF\n";
}
/** ASCII85 (the PDF flavour, with the <~ ~> markers), for a chained-filter fixture. */
function vd_a85(string $data): string
{
    $out = '<~';
    foreach (str_split($data, 4) as $chunk) {
        $len = strlen($chunk);
        $v = unpack('N', str_pad($chunk, 4, "\0"))[1];
        if ($len === 4 && $v === 0) { $out .= 'z'; continue; }
        $s = '';
        for ($i = 0; $i < 5; $i++) { $s = chr($v % 85 + 33) . $s; $v = intdiv($v, 85); }
        $out .= substr($s, 0, $len + 1);
    }
    return $out . '~>';
}
/** Every table that holds identity or KYC state — what an identity document must never change. */
function vd_kyc(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND (name LIKE '%kyc%' OR name LIKE '%identity%' OR name LIKE '%applic%' OR name LIKE '%verif%' OR name LIKE '%customer%') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $out[$t] = (int)$pdo->query("SELECT COUNT(*) FROM \"{$t}\"")->fetchColumn();
    }
    return $out;
}

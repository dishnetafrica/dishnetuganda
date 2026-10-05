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
/** Every table that holds identity or KYC state — what an identity document must never change. */
function vd_kyc(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND (name LIKE '%kyc%' OR name LIKE '%identity%' OR name LIKE '%applic%' OR name LIKE '%verif%' OR name LIKE '%customer%') ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $out[$t] = (int)$pdo->query("SELECT COUNT(*) FROM \"{$t}\"")->fetchColumn();
    }
    return $out;
}

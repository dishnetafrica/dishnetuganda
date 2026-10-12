<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/DocumentDeadline.php';
require_once __DIR__ . '/OoxmlArchive.php';

/**
 * DocxReader — the text of a Word document, in order, from word/document.xml alone (Batch 4, docs/58 §6.2, §7).
 *
 * XMLReader streams the part with LIBXML_NONET, entity substitution off and DTD loading off; a part carrying a DOCTYPE at all
 * is refused before the parser sees it (OOXML never has one; a DTD is how XXE and billion-laughs arrive). Paragraphs become
 * lines, table cells are separated by tabs, tabs and breaks are kept; deleted text, field codes, comments, footnotes, headers,
 * footers, embedded objects and the fallback half of mc:AlternateContent are never read. Node count and text length are
 * bounded, and the deadline is asked every thousand nodes. PHP 7.4 compatible.
 */
final class DocxReader
{
    private const W_NS = ['http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'http://purl.oclc.org/ooxml/wordprocessingml/main'];
    private const A_NS = ['http://schemas.openxmlformats.org/drawingml/2006/main', 'http://purl.oclc.org/ooxml/drawingml/main'];
    private const MC_NS = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

    /**
     * @param int $maxChars read this much text at most; beyond it the result is `truncated`
     * @return array{text:string, paragraphs:int, tables:int, truncated:bool, nodes:int}
     * @throws DocumentRefused malformed_document · too_large_document · extractor_unavailable
     */
    public static function read(OoxmlArchive $zip, DocumentDeadline $deadline, int $maxChars): array
    {
        if (!class_exists('XMLReader')) throw new DocumentRefused('extractor_unavailable', 'XMLReader');
        $xml = $zip->read('word/document.xml');
        self::guard($xml);

        $r = self::reader($xml);
        $text = ''; $paras = 0; $tables = 0; $nodes = 0; $truncated = false; $len = 0; $inCell = 0;
        // next() lands ON the following sibling, so after a skipped subtree the loop must not read() past it.
        $advance = true;
        try {
            while (true) {
                if ($advance && !@$r->read()) break;
                $advance = true;
                $nodes++;
                if ($nodes % 1000 === 0) $deadline->check('document.xml node ' . $nodes);
                if ($r->nodeType === XMLReader::ELEMENT) {
                    $ln = $r->localName; $ns = (string)$r->namespaceURI;
                    if ($ns === self::MC_NS && $ln === 'Fallback') {
                        // The fallback half of AlternateContent repeats the Choice half's text: skipped whole.
                        if (!$r->next()) break;
                        $advance = false;
                        continue;
                    }
                    $isW = in_array($ns, self::W_NS, true); $isA = in_array($ns, self::A_NS, true);
                    if ($ln === 't' && ($isW || $isA)) {
                        // Only w:t / a:t text is read: w:delText (deleted) and w:instrText (field codes) are other elements,
                        // and their text nodes are never looked at.
                        $t = (string)$r->readString();
                        $text .= $t; $len += strlen($t);
                    } elseif ($isW && $ln === 'tab') {
                        $text .= "\t"; $len++;
                    } elseif ($isW && ($ln === 'br' || $ln === 'cr')) {
                        $text .= "\n"; $len++;
                    } elseif ($isW && $ln === 'tbl') {
                        $tables++;
                    } elseif ($isW && $ln === 'tc' && !$r->isEmptyElement) {
                        $inCell++;
                    }
                } elseif ($r->nodeType === XMLReader::END_ELEMENT && in_array((string)$r->namespaceURI, self::W_NS, true)) {
                    $ln = $r->localName;
                    if ($ln === 'p') { $text .= $inCell > 0 ? ' ' : "\n"; $len++; $paras++; }
                    elseif ($ln === 'tc') { $text .= "\t"; $len++; if ($inCell > 0) $inCell--; }
                    elseif ($ln === 'tr') { $text .= "\n"; $len++; }
                }
                if ($len > $maxChars) { $truncated = true; break; }
            }
        } finally {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $r->close();
        }
        if ($errors !== []) throw new DocumentRefused('malformed_document', 'document.xml is not well-formed');
        return ['text' => $text, 'paragraphs' => $paras, 'tables' => $tables, 'truncated' => $truncated, 'nodes' => $nodes];
    }

    /** No DTD, no entity declaration — refused before any parser runs. */
    public static function guard(string $xml): void
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) throw new DocumentRefused('malformed_document', 'DTD in an XML part');
    }

    /** A reader that cannot reach the network, substitutes no entity and loads no DTD. */
    public static function reader(string $xml): XMLReader
    {
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $r = new XMLReader();
        if (!@$r->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_NOCDATA)) throw new DocumentRefused('malformed_document', 'XML part unreadable');
        $r->setParserProperty(XMLReader::LOADDTD, false);
        $r->setParserProperty(XMLReader::DEFAULTATTRS, false);
        $r->setParserProperty(XMLReader::VALIDATE, false);
        $r->setParserProperty(XMLReader::SUBST_ENTITIES, false);
        return $r;
    }
}

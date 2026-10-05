<?php
declare(strict_types=1);

require_once __DIR__ . '/MediaPolicy.php';
require_once __DIR__ . '/DocumentDeadline.php';

/**
 * OoxmlArchive — the smallest zip reader that can open a Word or Excel file safely, in memory (Batch 4, docs/58 §4, §6.2, §7).
 *
 * Written for this purpose rather than ZipArchive because ZipArchive opens a PATH — a temporary file — and has no limits,
 * while every limit here is explicit and checked in this order, each independent of the others:
 *
 *   1. the central directory: at most DOCUMENT_ZIP_MAX_ENTRIES entries; zip64 refused; an encrypted entry refused;
 *   2. a member is read only if its name is on the ALLOW-LIST below — the handful of parts a Word or Excel reader needs.
 *      Embedded objects, images, macros, a zip inside the zip: members nobody asks for, and members that may not be asked for;
 *   3. a member's DECLARED uncompressed size must fit DOCUMENT_ZIP_MAX_MEMBER_BYTES, and the members read together
 *      DOCUMENT_ZIP_MAX_TOTAL_BYTES, before a byte is inflated;
 *   4. inflation runs through gzinflate() with its max-length argument set to the declared size, so zlib stops at the cap and a
 *      member that lied about its size comes back false — no partial gigabyte string is ever built;
 *   5. the inflated length and CRC must match the directory, or the archive is malformed.
 *
 * Nothing is written anywhere. The archive's bytes are the caller's MediaBlob; this holds offsets into them. PHP 7.4 compatible.
 */
final class OoxmlArchive
{
    private const SIG_LOCAL   = "PK\x03\x04";
    private const SIG_CENTRAL = "PK\x01\x02";
    private const SIG_EOCD    = "PK\x05\x06";

    /** The only members a reader may open. Anything else in the archive stays sealed. */
    public const ALLOWED_MEMBERS = [
        '[Content_Types].xml',
        '_rels/.rels',
        'word/document.xml',
        'xl/workbook.xml',
        'xl/_rels/workbook.xml.rels',
        'xl/sharedStrings.xml',
    ];
    private const ALLOWED_PATTERN = '#^xl/worksheets/sheet\d{1,4}\.xml$#';

    /** @var string */
    private $bytes;
    /** @var array<string,array{method:int,flags:int,crc:int,csize:int,usize:int,offset:int}> */
    private $entries = [];
    /** @var DocumentDeadline */
    private $deadline;
    /** @var int */
    private $totalInflated = 0;
    /** @var string[] every member actually inflated, in order — so a test can prove the sealed ones stayed sealed */
    public $opened = [];

    private function __construct(string $bytes, DocumentDeadline $deadline)
    {
        $this->bytes    = $bytes;
        $this->deadline = $deadline;
    }

    /** Is this a zip at all? The sniffer asks before opening. */
    public static function looksLikeZip(string $bytes): bool
    {
        return strncmp($bytes, self::SIG_LOCAL, 4) === 0;
    }

    /**
     * Parse the central directory. Throws DocumentRefused: malformed_document, too_large_document, unsupported_document (zip64),
     * password_protected (an encrypted entry).
     */
    public static function open(string $bytes, DocumentDeadline $deadline): self
    {
        $z = new self($bytes, $deadline);
        $z->parseDirectory();
        return $z;
    }

    private function parseDirectory(): void
    {
        $n = strlen($this->bytes);
        if ($n < 22 || !self::looksLikeZip($this->bytes)) throw new DocumentRefused('malformed_document', 'not a zip');
        // The end-of-central-directory record is within the last 64 KiB + 22 bytes (a comment may follow it).
        $tail  = max(0, $n - 65557);
        $eocd  = strrpos($this->bytes, self::SIG_EOCD);
        if ($eocd === false || $eocd < $tail) throw new DocumentRefused('malformed_document', 'no end of central directory');
        if ($n - $eocd < 22) throw new DocumentRefused('malformed_document', 'truncated end record');
        $e = unpack('vdisk/vcddisk/ventriesdisk/ventries/Vcdsize/Vcdoffset', substr($this->bytes, $eocd + 4, 18));
        if ($e === false) throw new DocumentRefused('malformed_document', 'unreadable end record');
        if ($e['entries'] === 0xFFFF || $e['cdoffset'] === 0xFFFFFFFF || $e['cdsize'] === 0xFFFFFFFF
            || strpos($this->bytes, "PK\x06\x07", max(0, $eocd - 20)) !== false) {
            throw new DocumentRefused('unsupported_document', 'zip64');
        }
        if ($e['entries'] > MediaPolicy::DOCUMENT_ZIP_MAX_ENTRIES) {
            throw new DocumentRefused('too_large_document', sprintf('%d archive entries, limit %d', $e['entries'], MediaPolicy::DOCUMENT_ZIP_MAX_ENTRIES));
        }
        $pos = (int)$e['cdoffset'];
        $end = $pos + (int)$e['cdsize'];
        if ($end > $eocd) throw new DocumentRefused('malformed_document', 'central directory overruns the end record');
        for ($i = 0; $i < (int)$e['entries']; $i++) {
            if ($i % 100 === 0) $this->deadline->check('central directory entry ' . $i);
            if ($pos + 46 > $end || substr($this->bytes, $pos, 4) !== self::SIG_CENTRAL) {
                throw new DocumentRefused('malformed_document', 'central directory entry ' . $i . ' unreadable');
            }
            $h = unpack('vmade/vneed/vflags/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voffset', substr($this->bytes, $pos + 4, 42));
            if ($h === false) throw new DocumentRefused('malformed_document', 'central directory entry ' . $i . ' unreadable');
            $name = substr($this->bytes, $pos + 46, (int)$h['nlen']);
            if (($h['flags'] & 0x1) === 0x1 || ($h['flags'] & 0x40) === 0x40) {
                throw new DocumentRefused('password_protected', 'encrypted archive entry');
            }
            if ($h['csize'] === 0xFFFFFFFF || $h['usize'] === 0xFFFFFFFF || $h['offset'] === 0xFFFFFFFF) {
                throw new DocumentRefused('unsupported_document', 'zip64 entry');
            }
            if ($name !== '' && !isset($this->entries[$name])) {
                $this->entries[$name] = ['method' => (int)$h['method'], 'flags' => (int)$h['flags'], 'crc' => (int)$h['crc'],
                                        'csize' => (int)$h['csize'], 'usize' => (int)$h['usize'], 'offset' => (int)$h['offset']];
            }
            $pos += 46 + (int)$h['nlen'] + (int)$h['elen'] + (int)$h['clen'];
        }
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    /** The members a Word or Excel reader may ask for; any other name is refused by read() whatever the archive holds. */
    public static function allowed(string $name): bool
    {
        return in_array($name, self::ALLOWED_MEMBERS, true) || preg_match(self::ALLOWED_PATTERN, $name) === 1;
    }

    /**
     * Inflate one allow-listed member, under every cap. Throws DocumentRefused.
     */
    public function read(string $name): string
    {
        if (!self::allowed($name)) throw new DocumentRefused('malformed_document', 'member not on the allow-list');
        if (!isset($this->entries[$name])) throw new DocumentRefused('malformed_document', 'missing member ' . $name);
        $e = $this->entries[$name];
        $this->deadline->check('member ' . $name);
        if ($e['usize'] > MediaPolicy::DOCUMENT_ZIP_MAX_MEMBER_BYTES) {
            throw new DocumentRefused('too_large_document', sprintf('member declares %d bytes, limit %d', $e['usize'], MediaPolicy::DOCUMENT_ZIP_MAX_MEMBER_BYTES));
        }
        if ($this->totalInflated + $e['usize'] > MediaPolicy::DOCUMENT_ZIP_MAX_TOTAL_BYTES) {
            throw new DocumentRefused('too_large_document', sprintf('members read would exceed %d bytes', MediaPolicy::DOCUMENT_ZIP_MAX_TOTAL_BYTES));
        }
        if ($e['method'] !== 0 && $e['method'] !== 8) throw new DocumentRefused('unsupported_document', 'compression method ' . $e['method']);

        $off = $e['offset'];
        if ($off + 30 > strlen($this->bytes) || substr($this->bytes, $off, 4) !== self::SIG_LOCAL) {
            throw new DocumentRefused('malformed_document', 'local header of ' . $name . ' unreadable');
        }
        $l = unpack('vnlen/velen', substr($this->bytes, $off + 26, 4));
        $start = $off + 30 + (int)$l['nlen'] + (int)$l['elen'];
        if ($start + $e['csize'] > strlen($this->bytes)) throw new DocumentRefused('malformed_document', 'member ' . $name . ' overruns the file');
        $raw = substr($this->bytes, $start, $e['csize']);

        if ($e['method'] === 0) {
            if ($e['csize'] !== $e['usize']) throw new DocumentRefused('malformed_document', 'stored member sizes disagree');
            $data = $raw;
        } else {
            // zlib stops at the declared size: a member that is larger than it says comes back false, never as a huge string.
            $data = $e['usize'] === 0 ? '' : @gzinflate($raw, $e['usize']);
            if ($data === false) throw new DocumentRefused('malformed_document', 'member ' . $name . ' did not inflate within its declared size');
        }
        if (strlen($data) !== $e['usize']) throw new DocumentRefused('malformed_document', 'member ' . $name . ' size disagrees with the directory');
        if ((crc32($data) & 0xFFFFFFFF) !== ($e['crc'] & 0xFFFFFFFF)) throw new DocumentRefused('malformed_document', 'member ' . $name . ' checksum disagrees');
        $this->totalInflated += strlen($data);
        $this->opened[] = $name;
        return $data;
    }
}

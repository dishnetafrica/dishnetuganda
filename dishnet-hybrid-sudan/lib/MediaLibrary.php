<?php
declare(strict_types=1);

/**
 * MediaLibrary — photos and documents the assistant may send, named so it can
 * pick one.
 *
 * A customer asked to see the kit — "I want to see the pictorial of the kit" —
 * and got "I don't want to give you incorrect information. Let me confirm with
 * our team and come back to you today." That holding line is the correct
 * behaviour for a thing we do not have, and it is a bad answer to a request
 * that should be the easiest yes in the conversation.
 *
 * FlyerAsset already sends ONE image under a fixed name. This is the same idea
 * with a name on each file, so the model can ask for the right one.
 *
 * Drop files into <dataDir>/photos/ and the file name IS the key:
 *
 *     photos/mini-kit.jpg          → <<PHOTO mini-kit>>
 *     photos/standard-kit.jpg      → <<PHOTO standard-kit>>
 *     photos/installed-roof.jpg    → <<PHOTO installed-roof>>
 *
 * An empty or missing folder means the feature does not exist: the model is
 * never told the action is available, nothing is offered, and the install
 * behaves exactly as it did before. That absence is the compatibility story,
 * the same one FlyerAsset uses.
 *
 * A caption file alongside an image (mini-kit.txt) is sent with it. Without
 * one the image goes bare rather than captioned from a guess.
 */
class MediaLibrary
{
    const DIR     = 'photos';       // images
    const DIR_DOC = 'documents';    // PDFs — spec sheets, brochures

    /** extension => mime. Matches FlyerAsset: what Evolution reliably accepts. */
    const EXTS = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    /** extension => mime, for documents. */
    const DOC_EXTS = ['pdf' => 'application/pdf'];

    /** Evolution takes base64 inline. Same ceiling as the flyer. */
    const MAX_FILE_BYTES = 4194304;   // 4 MB

    /** A spec sheet is bigger than a snapshot, and still has to fit inline. */
    const MAX_DOC_BYTES = 10485760;   // 10 MB

    /**
     * Every usable photo, keyed by name. Reads no image content, so it is
     * cheap enough for worker construction and for every prompt build.
     *
     * @return array<string, array{path:string,mime:string,caption:string,bytes:int}>
     */
    public static function all(string $dataDir): array
    {
        return self::scan($dataDir, self::DIR, self::EXTS, self::MAX_FILE_BYTES, 'image');
    }

    /**
     * Documents — spec sheets, brochures. Same naming rule as photos, its own
     * folder so an operator is never unsure where a PDF goes.
     *
     * @return array<string, array{path:string,mime:string,caption:string,bytes:int,kind:string}>
     */
    public static function documents(string $dataDir): array
    {
        return self::scan($dataDir, self::DIR_DOC, self::DOC_EXTS, self::MAX_DOC_BYTES, 'document');
    }

    private static function scan(string $dataDir, string $folder, array $exts,
                                 int $maxBytes, string $kind): array
    {
        $dir = rtrim($dataDir, '/') . '/' . $folder;
        if (!is_dir($dir)) return [];

        $out = [];
        foreach ((array)@scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (!is_file($path)) continue;

            $ext = strtolower((string)pathinfo($entry, PATHINFO_EXTENSION));
            if (!isset($exts[$ext])) continue;

            $bytes = (int)@filesize($path);
            if ($bytes <= 0 || $bytes > $maxBytes) continue;

            // The key is the file name, lowercased, with anything that is not
            // a letter, digit or dash removed. A name the model cannot type
            // back exactly is a name it cannot ask for.
            $key = self::key((string)pathinfo($entry, PATHINFO_FILENAME));
            if ($key === '' || isset($out[$key])) continue;

            $capFile = $dir . '/' . pathinfo($entry, PATHINFO_FILENAME) . '.txt';
            $caption = is_file($capFile)
                ? trim((string)@file_get_contents($capFile)) : '';

            $out[$key] = ['path' => $path, 'mime' => $exts[$ext], 'kind' => $kind,
                          'file' => $entry,
                          'caption' => mb_substr($caption, 0, 400), 'bytes' => $bytes];
        }
        ksort($out);
        return $out;
    }

    /** One photo by name, or null. Names are matched exactly, never fuzzily. */
    public static function find(string $dataDir, string $name): ?array
    {
        return self::all($dataDir)[self::key($name)] ?? null;
    }

    /** One document by name, or null. */
    public static function findDocument(string $dataDir, string $name): ?array
    {
        return self::documents($dataDir)[self::key($name)] ?? null;
    }

    /** The comparable form of a name. A name the model cannot type back is useless. */
    public static function key(string $name): string
    {
        $k = trim(strtolower($name));
        return trim((string)preg_replace('/[^a-z0-9-]+/', '-', $k), '-');
    }

    /** base64 for Evolution, or '' if the file went away since it was listed. */
    public static function payload(array $photo): string
    {
        $path = (string)($photo['path'] ?? '');
        if ($path === '' || !is_file($path)) return '';
        $raw = @file_get_contents($path);
        return $raw === false ? '' : base64_encode($raw);
    }

    /**
     * Take an upload and put it in the library.
     *
     * Everything a customer could be sent passes through here, so nothing is
     * trusted from the browser. The extension comes from what the file
     * ACTUALLY is, not from what it was called: an image has to survive
     * getimagesize(), a PDF has to start with %PDF-, and the stored name is
     * rebuilt from the key rule so no upload can choose its own path.
     *
     * @param array $file one entry from $_FILES
     * @return array{ok:bool, name:string, error:string}
     */
    public static function store(string $dataDir, array $file, string $kind,
                                 string $name = '', string $caption = ''): array
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) return self::fail('No file was chosen.');
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return self::fail('That file is larger than the server accepts.');
        }
        if ($err !== UPLOAD_ERR_OK) return self::fail('The upload did not complete (code ' . $err . ').');

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) return self::fail('The uploaded file could not be read.');

        $isDoc  = $kind === 'document';
        $max    = $isDoc ? self::MAX_DOC_BYTES : self::MAX_FILE_BYTES;
        $bytes  = (int)@filesize($tmp);
        if ($bytes <= 0)    return self::fail('That file is empty.');
        if ($bytes > $max)  return self::fail('That file is ' . round($bytes / 1048576, 1)
                                 . ' MB. The limit is ' . (int)($max / 1048576) . ' MB.');

        // What is it really? A name ending .jpg proves nothing.
        if ($isDoc) {
            $head = (string)@file_get_contents($tmp, false, null, 0, 5);
            if (strncmp($head, '%PDF-', 5) !== 0) return self::fail('That is not a PDF file.');
            $ext = 'pdf';
        } else {
            $info = @getimagesize($tmp);
            $byType = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            $type = is_array($info) ? (int)($info[2] ?? 0) : 0;
            if (!isset($byType[$type])) {
                return self::fail('That is not a JPG, PNG or WebP image.');
            }
            $ext = $byType[$type];
        }

        // The stored name is built from the key rule, never from the upload.
        $base = self::key($name !== '' ? $name : (string)pathinfo((string)($file['name'] ?? ''), PATHINFO_FILENAME));
        if ($base === '') return self::fail('Give the file a name using letters, numbers and dashes.');

        $dir = rtrim($dataDir, '/') . '/' . ($isDoc ? self::DIR_DOC : self::DIR);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return self::fail('Could not create ' . $dir);
        }

        // One name, one file: replacing mini-kit.jpg with mini-kit.png must
        // not leave two files answering to "mini-kit".
        foreach (array_keys($isDoc ? self::DOC_EXTS : self::EXTS) as $old) {
            $stale = $dir . '/' . $base . '.' . $old;
            if ($old !== $ext && is_file($stale)) @unlink($stale);
        }

        $dest = $dir . '/' . $base . '.' . $ext;
        $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
        if (!$moved) return self::fail('Could not save the file. Check the folder is writable.');
        @chmod($dest, 0664);

        self::caption($dataDir, $kind, $base, $caption);

        return ['ok' => true, 'name' => $base, 'error' => ''];
    }

    /**
     * Set or clear the caption on something already stored.
     *
     * A caption is sent WITH the file. Without one the customer gets a bare
     * photo, or worse a bare PDF they have no reason to open. Passing an
     * empty caption removes it and goes back to sending bare.
     *
     * Returns false when there is nothing stored under that name, so a
     * caller never reports success for a file that is not there.
     */
    public static function caption(string $dataDir, string $kind, string $name,
                                   string $caption): bool
    {
        $base = self::key($name);
        if ($base === '') return false;
        $isDoc = $kind === 'document';
        $found = $isDoc ? self::findDocument($dataDir, $base) : self::find($dataDir, $base);
        if ($found === null) return false;

        $capFile = rtrim($dataDir, '/') . '/'
                 . ($isDoc ? self::DIR_DOC : self::DIR) . '/' . $base . '.txt';
        $caption = trim($caption);
        if ($caption === '') {
            if (is_file($capFile)) @unlink($capFile);
            return true;
        }
        if (@file_put_contents($capFile, mb_substr($caption, 0, 400)) === false) return false;
        @chmod($capFile, 0664);
        return true;
    }

    /** Remove one item and its caption. Returns false when there was nothing there. */
    public static function remove(string $dataDir, string $kind, string $name): bool
    {
        $base = self::key($name);
        if ($base === '') return false;
        $isDoc = $kind === 'document';
        $dir   = rtrim($dataDir, '/') . '/' . ($isDoc ? self::DIR_DOC : self::DIR);
        $gone  = false;
        foreach (array_keys($isDoc ? self::DOC_EXTS : self::EXTS) as $ext) {
            $f = $dir . '/' . $base . '.' . $ext;
            if (is_file($f) && @unlink($f)) $gone = true;
        }
        $cap = $dir . '/' . $base . '.txt';
        if (is_file($cap)) @unlink($cap);
        return $gone;
    }

    private static function fail(string $msg): array
    {
        return ['ok' => false, 'name' => '', 'error' => $msg];
    }

    /**
     * The prompt block. Empty string when there are no photos, so a prompt
     * without them is byte-identical to one built before this existed.
     */
    public static function promptBlock(string $dataDir): string
    {
        $all = self::all($dataDir);
        if (!$all) return '';

        $p = "\nPHOTOS YOU CAN SEND.\n"
           . "- A customer asking to see something is the easiest yes in the "
           . "conversation. Send it rather than offering to check.\n"
           . "- Put <<PHOTO name>> on its own line at the end of your reply, using a name "
           . "from this list EXACTLY as written. The customer never sees the marker.\n"
           . "- Only these exist. If they ask for something not on the list, say what you can "
           . "show them instead, or offer to have a colleague send it. Never name a photo that "
           . "is not here, and never describe a picture you have not got.\n"
           . "- One photo per reply, and do not send the same one twice in a conversation.\n";
        foreach ($all as $key => $ph) {
            $p .= '  ' . $key;
            if ($ph['caption'] !== '') $p .= ' — ' . $ph['caption'];
            $p .= "\n";
        }
        return $p . self::documentBlock($dataDir);
    }

    /**
     * The documents section, appended to the photo block.
     *
     * Separate marker because a spec sheet is a different answer from a
     * picture: somebody asking "what does it look like" wants the photo, and
     * somebody asking for the details wants the PDF.
     */
    public static function documentBlock(string $dataDir): string
    {
        $docs = self::documents($dataDir);
        if (!$docs) return '';

        $p = "\nDOCUMENTS YOU CAN SEND.\n"
           . "- When someone asks for full specifications, technical details, or the "
           . "datasheet, send the document rather than typing out figures from memory.\n"
           . "- Put <<DOC name>> on its own line at the end of your reply, using a name from "
           . "this list EXACTLY as written.\n"
           . "- Only these exist. Never name a document that is not here, and never claim to "
           . "have sent one you did not.\n"
           . "- Say in your reply what you are sending, so it does not arrive unexplained.\n"
           . "- A document does not replace the answer. Answer the question in your own words "
           . "too, briefly.\n";
        foreach ($docs as $key => $d) {
            $p .= '  ' . $key;
            if ($d['caption'] !== '') $p .= ' — ' . $d['caption'];
            $p .= "\n";
        }
        return $p;
    }
}

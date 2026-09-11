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

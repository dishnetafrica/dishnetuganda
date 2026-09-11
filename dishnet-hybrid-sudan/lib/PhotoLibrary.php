<?php
declare(strict_types=1);

/**
 * PhotoLibrary — photos the assistant may send, named so it can pick one.
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
class PhotoLibrary
{
    const DIR = 'photos';

    /** extension => mime. Matches FlyerAsset: what Evolution reliably accepts. */
    const EXTS = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    /** Evolution takes base64 inline. Same ceiling as the flyer. */
    const MAX_FILE_BYTES = 4194304;   // 4 MB

    /**
     * Every usable photo, keyed by name. Reads no image content, so it is
     * cheap enough for worker construction and for every prompt build.
     *
     * @return array<string, array{path:string,mime:string,caption:string,bytes:int}>
     */
    public static function all(string $dataDir): array
    {
        $dir = rtrim($dataDir, '/') . '/' . self::DIR;
        if (!is_dir($dir)) return [];

        $out = [];
        foreach ((array)@scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (!is_file($path)) continue;

            $ext = strtolower((string)pathinfo($entry, PATHINFO_EXTENSION));
            if (!isset(self::EXTS[$ext])) continue;

            $bytes = (int)@filesize($path);
            if ($bytes <= 0 || $bytes > self::MAX_FILE_BYTES) continue;

            // The key is the file name, lowercased, with anything that is not
            // a letter, digit or dash removed. A name the model cannot type
            // back exactly is a name it cannot ask for.
            $key = strtolower((string)pathinfo($entry, PATHINFO_FILENAME));
            $key = trim((string)preg_replace('/[^a-z0-9-]+/', '-', $key), '-');
            if ($key === '' || isset($out[$key])) continue;

            $capFile = $dir . '/' . pathinfo($entry, PATHINFO_FILENAME) . '.txt';
            $caption = is_file($capFile)
                ? trim((string)@file_get_contents($capFile)) : '';

            $out[$key] = ['path' => $path, 'mime' => self::EXTS[$ext],
                          'caption' => mb_substr($caption, 0, 400), 'bytes' => $bytes];
        }
        ksort($out);
        return $out;
    }

    /** One photo by name, or null. Names are matched exactly, never fuzzily. */
    public static function find(string $dataDir, string $name): ?array
    {
        $key = trim(strtolower($name));
        $key = trim((string)preg_replace('/[^a-z0-9-]+/', '-', $key), '-');
        $all = self::all($dataDir);
        return $all[$key] ?? null;
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
        return $p;
    }
}

<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * media.php — what the assistant can show and send a customer.
 *
 *   php tools/media.php                             list what is there
 *   php tools/media.php --caption <name> "text"     caption a photo
 *   php tools/media.php --caption <name> "text" --doc   caption a document
 *   php tools/media.php --caption <name> ""         remove the caption
 *
 * Listing is read-only. --caption writes one small text file beside the
 * image or PDF and touches nothing else.
 *
 * A caption is sent WITH the file. Without one it arrives bare, which for a
 * PDF means a customer gets an attachment and no reason to open it.
 *
 * A customer asked to see the kit and got "I don't want to give you incorrect
 * information, let me confirm with our team". Correct for a thing we do not
 * have, and a poor answer to the easiest request in the conversation.
 *
 * Drop image files into the photos folder and the FILE NAME is how the
 * assistant asks for it. Nothing else to configure.
 *
 * CLI only.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/MediaLibrary.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$dir     = rtrim($dataDir, '/') . '/' . MediaLibrary::DIR;
$docDir  = rtrim($dataDir, '/') . '/' . MediaLibrary::DIR_DOC;

// ── Set or clear one caption, then stop ─────────────────────────────────────
$argsIn = array_slice($argv, 1);
$capAt  = array_search('--caption', $argsIn, true);
if ($capAt !== false) {
    $isDoc = in_array('--doc', $argsIn, true);
    $what  = $isDoc ? 'document' : 'photo';
    $name  = (string)($argsIn[$capAt + 1] ?? '');
    $text  = (string)($argsIn[$capAt + 2] ?? '');
    $key   = MediaLibrary::key($name);
    $found = $isDoc
        ? MediaLibrary::findDocument($dataDir, $key)
        : MediaLibrary::find($dataDir, $key);
    if ($key === '' || $found === null) {
        echo "\n  There is no " . $what . " called \"" . $name . "\".\n";
        echo "  Run it with no arguments to see the names.\n\n";
        exit(1);
    }
    $text = trim($text);
    $kind = $isDoc ? 'document' : 'image';
    if (!MediaLibrary::caption($dataDir, $kind, $key, $text)) {
        echo "\n  Could not write the caption for \"" . $key . "\".\n";
        echo "  Check the folder is writable by the web user.\n\n";
        exit(1);
    }
    if ($text === '') {
        echo "\n  Caption removed. \"" . $key . "\" will now be sent bare.\n\n";
    } else {
        echo "\n  \"" . $key . "\" will be sent with:\n\n";
        echo "     " . mb_substr($text, 0, 400) . "\n\n";
    }
    exit(0);
}

$all     = MediaLibrary::all($dataDir);
$docs    = MediaLibrary::documents($dataDir);

echo "\n  PHOTOS THE ASSISTANT CAN SEND\n\n";
echo "     folder   " . $dir . "\n\n";

if (!$all) {
    echo "     none — so the assistant is never told it can show anything, and\n";
    echo "     offers to have a colleague send a picture instead.\n\n";
} else {
    foreach ($all as $key => $ph) {
        printf("     %-22s %8s KB   %s\n", $key,
               number_format($ph['bytes'] / 1024, 0),
               $ph['caption'] !== '' ? mb_substr($ph['caption'], 0, 44) : '(no caption)');
    }
    printf("\n     %d photo(s). The assistant picks by name from exactly this list.\n\n", count($all));
    $bare = array_keys(array_filter($all, static fn($x) => $x['caption'] === ''));
    if ($bare) {
        printf("     %d of them are sent bare, with no caption:\n", count($bare));
        echo "       php tools/media.php --caption " . $bare[0] . " \"what it is\"\n\n";
    }
}

echo "  DOCUMENTS THE ASSISTANT CAN SEND\n\n";
echo "     folder   " . $docDir . "\n\n";
if (!$docs) {
    echo "     none — so when a customer asks for full specifications it answers in\n";
    echo "     its own words and offers to have a colleague send the sheet.\n\n";
} else {
    foreach ($docs as $key => $d) {
        printf("     %-22s %8s KB   %s\n", $key,
               number_format($d['bytes'] / 1024, 0),
               $d['caption'] !== '' ? mb_substr($d['caption'], 0, 44) : '(no caption)');
    }
    printf("\n     %d document(s), sent as PDF attachments.\n\n", count($docs));
    $bareD = array_keys(array_filter($docs, static fn($x) => $x['caption'] === ''));
    if ($bareD) {
        printf("     %d arrive with no caption — a PDF and no reason to open it:\n", count($bareD));
        echo "       php tools/media.php --caption " . $bareD[0] . " \"what it is\" --doc\n\n";
    }
}

echo "  TO ADD ONE\n\n";
echo "     Copy an image in, and name the file what it is:\n\n";
echo "       docker cp mini-kit.jpg ucrm:" . $dir . "/\n\n";
echo "     jpg, jpeg, png or webp, under 4 MB. The file name becomes the name\n";
echo "     the assistant uses — mini-kit.jpg is asked for as 'mini-kit'.\n\n";
echo "     PDFs go in the documents folder the same way, up to 10 MB:\n\n";
echo "       docker cp specification_sheet_mini.pdf ucrm:" . $docDir . "/\n\n";
echo "     Name it what a customer would call it — mini-spec-sheet.pdf reads\n";
echo "     better on their phone than specification_sheet_mini(1).pdf.\n\n";
echo "     For a caption, add a text file beside it with the same name:\n\n";
echo "       mini-kit.jpg  +  mini-kit.txt\n\n";
echo "     Without one the photo is sent bare rather than captioned from a guess.\n\n";

echo "  WHAT TO PUT IN THEM\n\n";
echo "     Use DishNet's OWN photographs — your stock, your installs, your\n";
echo "     technicians. They are yours to send, they show what a customer\n";
echo "     actually receives, and a real roof in Kampala sells better than a\n";
echo "     manufacturer's studio shot. Do not copy product images off the web:\n";
echo "     they belong to somebody else and may not show what you supply.\n\n";
echo "     Worth having: mini-kit, standard-kit, what is in the box,\n";
echo "     a finished roof install, a pole mount, the team at work.\n\n";
echo "     Documents are different: Starlink's own specification sheets are\n";
echo "     published for resellers to pass on, so send those rather than\n";
echo "     retyping figures. Re-download them when a hardware generation\n";
echo "     changes — an old sheet is a confident wrong answer.\n\n";
exit(0);

<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
/**
 * photos.php — what the assistant can show a customer.
 *
 *   php tools/photos.php        list the library and how to add to it
 *
 * READ-ONLY.
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
require_once $root . '/lib/PhotoLibrary.php';

$dataDir = getenv('DN_DATA_DIR') ?: getDataDir($root);
$dir     = rtrim($dataDir, '/') . '/' . PhotoLibrary::DIR;
$all     = PhotoLibrary::all($dataDir);

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
}

echo "  TO ADD ONE\n\n";
echo "     Copy an image in, and name the file what it is:\n\n";
echo "       docker cp mini-kit.jpg ucrm:" . $dir . "/\n\n";
echo "     jpg, jpeg, png or webp, under 4 MB. The file name becomes the name\n";
echo "     the assistant uses — mini-kit.jpg is asked for as 'mini-kit'.\n\n";
echo "     For a caption, add a text file beside it with the same name:\n\n";
echo "       mini-kit.jpg  +  mini-kit.txt\n\n";
echo "     Without one the photo is sent bare rather than captioned from a guess.\n\n";

echo "  WHAT TO PUT IN IT\n\n";
echo "     Use DishNet's OWN photographs — your stock, your installs, your\n";
echo "     technicians. They are yours to send, they show what a customer\n";
echo "     actually receives, and a real roof in Kampala sells better than a\n";
echo "     manufacturer's studio shot. Do not copy product images off the web:\n";
echo "     they belong to somebody else and may not show what you supply.\n\n";
echo "     Worth having: mini-kit, standard-kit, what is in the box,\n";
echo "     a finished roof install, a pole mount, the team at work.\n\n";
exit(0);

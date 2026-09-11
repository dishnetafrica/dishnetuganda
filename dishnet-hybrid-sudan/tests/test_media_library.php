<?php
/**
 * test_photo_library.php — "I want to see the pictorial of the kit".
 *
 * The assistant answered: "I don't want to give you incorrect information.
 * Let me confirm with our team and come back to you today." That holding line
 * is right for something we do not have, and it is a bad answer to the easiest
 * request in a sales conversation.
 *
 * So photos get a name, and the model picks from a list. The safety property
 * is the lookup: it chooses a NAME, never a description, and a name the
 * library does not hold resolves to no file. A hallucinated name costs a
 * photo, never sends the wrong picture.
 *
 * And an empty folder means the feature does not exist — the model is never
 * told it can show anything, which is what keeps every install that predates
 * this behaving exactly as it did.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/MediaLibrary.php';
require_once $root . '/lib/DishNetAiBrain.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }
function has(string $m, string $h, string $n): void { is_(stripos($h,$n)!==false,$m,'missing: '.$n); }

$tmp = sys_get_temp_dir() . '/dn_photos_' . bin2hex(random_bytes(4));
@mkdir($tmp . '/photos', 0777, true);
// A 1x1 PNG is a real image file as far as everything here is concerned.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

echo "\nAn empty folder means the feature does not exist\n";
is_(MediaLibrary::all($tmp) === [], 'no photos found');
is_(MediaLibrary::promptBlock($tmp) === '', 'and the prompt block is empty',
    'an install with no photos must be byte-identical to one before this existed');
is_(MediaLibrary::all($tmp . '/nope') === [], 'a missing folder is not an error either');

echo "\nA file name is the name the assistant asks for\n";
file_put_contents($tmp . '/photos/mini-kit.jpg', $png);
file_put_contents($tmp . '/photos/Standard Kit.PNG', $png);
file_put_contents($tmp . '/photos/installed-roof.webp', $png);
$all = MediaLibrary::all($tmp);
is_(count($all) === 3, 'three photos found', (string)count($all));
is_(isset($all['mini-kit']), 'mini-kit.jpg is "mini-kit"');
is_(isset($all['standard-kit']), 'and "Standard Kit.PNG" normalises to "standard-kit"',
    'a name the model cannot type back exactly is a name it cannot ask for');
is_(isset($all['installed-roof']), 'webp is accepted too');

echo "\nA caption file beside the image travels with it\n";
file_put_contents($tmp . '/photos/mini-kit.txt', 'Starlink Mini — dish, built-in wifi, cables, power supply');
$all = MediaLibrary::all($tmp);
has('the caption is loaded', (string)$all['mini-kit']['caption'], 'built-in wifi');
is_($all['standard-kit']['caption'] === '', 'and one without a caption stays bare',
    'better bare than captioned from a guess');

echo "\nLookup is exact, and a name we do not hold finds nothing\n";
is_(MediaLibrary::find($tmp, 'mini-kit') !== null, 'an exact name resolves');
is_(MediaLibrary::find($tmp, 'Mini-Kit') !== null, 'case does not matter');
is_(MediaLibrary::find($tmp, 'starlink-v4-dish-2026') === null,
    'an invented name resolves to nothing',
    'this is what stops a hallucinated name becoming the wrong picture');
is_(MediaLibrary::find($tmp, '') === null, 'and so does an empty one');

echo "\nOnly real images, only sane sizes\n";
file_put_contents($tmp . '/photos/notes.txt', 'not an image');
file_put_contents($tmp . '/photos/contract.pdf', '%PDF-');
file_put_contents($tmp . '/photos/empty.jpg', '');
is_(!isset(MediaLibrary::all($tmp)['notes']), 'a stray .txt is not a photo');
is_(!isset(MediaLibrary::all($tmp)['contract']), 'nor a pdf');
is_(!isset(MediaLibrary::all($tmp)['empty']), 'nor a zero-byte file');
is_(MediaLibrary::payload(['path' => $tmp . '/photos/gone.jpg']) === '',
    'and a file that vanished yields no payload, not a crash');

echo "\nThe prompt lists exactly what exists, and forbids the rest\n";
$block = MediaLibrary::promptBlock($tmp);
has('the marker form is given', $block, '<<PHOTO name>>');
has('names must match exactly',  $block, 'EXACTLY as written');
has('mini-kit is listed',        $block, 'mini-kit');
has('with its caption',          $block, 'built-in wifi');
has('inventing a name is forbidden', $block, 'never name a photo that is not here');
has('and so is describing one we lack', $block, 'never describe a picture you have not got');
has('one per reply',             $block, 'One photo per reply');
has('and sending it beats offering to check', $block, 'easiest yes');

echo "\nThe marker is parsed and stripped from what the customer sees\n";
$brain = new DishNetAiBrain(['claude_api_key' => 'k']);
$m = new ReflectionMethod(DishNetAiBrain::class, 'parseMarkers');
$m->setAccessible(true);
$out = $m->invoke($brain, "Here is the Mini kit.\n<<PHOTO mini-kit>>");
is_($out['photo'] === 'mini-kit', 'the name is parsed out', (string)$out['photo']);
is_($out['reply'] === 'Here is the Mini kit.', 'and the reply is clean', $out['reply']);
is_(strpos($out['reply'], 'PHOTO') === false, 'no marker text survives');

$none = $m->invoke($brain, 'Our Residential plan is a good fit.');
is_($none['photo'] === '', 'no marker means no photo');

echo "\nA plan never requires a particular kit\n";
// "For the Residential Lite plan, you'll need the Starlink Mini Kit" — an
// invented dependency, stated to a customer as a requirement.
$p = (new DishNetAiBrain(['claude_api_key' => 'k', 'ai_qualification' => '1']))
     ->promptPreview(['channel' => 'sales', 'medium' => 'whatsapp', 'customer' => null,
                      'history' => [], 'message' => 'tell me about residential lite']);
has('the rule exists',            $p, 'A PLAN NEVER REQUIRES A PARTICULAR KIT');
has('the exact wrong phrasing is named', $p, 'will need');
has('they are separate choices',  $p, 'two separate choices');
has('and an unknown is confirmed', $p, 'offer to confirm it');

// ══════════════════════════════════════════════════════════════════════════
//  Uploading, which is how anything gets in here without a shell
// ══════════════════════════════════════════════════════════════════════════
echo "\nWhat a file IS decides the extension, not what it is called\n";
// A browser can claim anything. Everything here reaches a customer, so the
// name on the upload is never trusted for the stored name or the type.
$up = $tmp . '/up'; @mkdir($up, 0777, true);
$mk = function (string $file, string $bytes) use ($up) {
    file_put_contents($up . '/' . $file, $bytes);
    return ['name' => $file, 'tmp_name' => $up . '/' . $file,
            'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
$store2 = $tmp . '/store'; @mkdir($store2, 0777, true);

$r = MediaLibrary::store($store2, $mk('kit.png', $png), 'image', 'mini-kit', 'The Mini kit');
is_($r['ok'], 'a real PNG is accepted', $r['error']);
is_($r['name'] === 'mini-kit', 'and stored under the name given');
is_(MediaLibrary::find($store2, 'mini-kit') !== null, 'and is findable straight away');
is_((string)MediaLibrary::find($store2, 'mini-kit')['caption'] === 'The Mini kit',
    'with its caption written beside it');

echo "\nA file pretending to be an image is refused\n";
$r = MediaLibrary::store($store2, $mk('evil.jpg', '<?php echo "hello"; ?>'), 'image', 'evil');
is_(!$r['ok'], 'PHP source named .jpg is rejected', 'it claimed to be an image');
is_(stripos($r['error'], 'not a JPG') !== false, 'and told why', $r['error']);
is_(!is_file($store2 . '/photos/evil.jpg'), 'nothing was written');

echo "\nAnd so is a document that is not a PDF\n";
$r = MediaLibrary::store($store2, $mk('sheet.pdf', 'just text'), 'document', 'sheet');
is_(!$r['ok'] && stripos($r['error'], 'not a PDF') !== false, 'the %PDF- header is checked', $r['error']);
$r = MediaLibrary::store($store2, $mk('real.pdf', "%PDF-1.7\n1 0 obj\n"), 'document', 'mini-spec-sheet',
                         'Starlink Mini specifications');
is_($r['ok'], 'a real PDF is accepted', $r['error']);
is_(MediaLibrary::findDocument($store2, 'mini-spec-sheet') !== null, 'and lands in documents');
is_(MediaLibrary::find($store2, 'mini-spec-sheet') === null,
    'not in photos — the two folders stay separate');

echo "\nAn upload cannot choose its own path\n";
// The stored name is rebuilt from the key rule, so traversal and odd
// characters cannot survive it.
$r = MediaLibrary::store($store2, $mk('x.png', $png), 'image', '../../etc/passwd');
is_($r['ok'], 'it still stores');
is_(strpos($r['name'], '..') === false && strpos($r['name'], '/') === false,
    'with the path stripped out — got "' . $r['name'] . '"');
is_(!is_file($tmp . '/etc/passwd'), 'and nothing escaped the folder');

echo "\nOne name means one file\n";
// Replacing a jpg with a png must not leave two files answering to one name.
MediaLibrary::store($store2, $mk('a.png', $png), 'image', 'swapme');
$jpg = imagecreatetruecolor(2, 2);
ob_start(); imagejpeg($jpg); $jpgBytes = (string)ob_get_clean(); imagedestroy($jpg);
MediaLibrary::store($store2, $mk('b.jpg', $jpgBytes), 'image', 'swapme');
$left = glob($store2 . '/photos/swapme.*') ?: [];
$imgs = array_filter($left, fn($f) => !str_ends_with($f, '.txt'));
is_(count($imgs) === 1, 'exactly one image survives', implode(', ', $imgs));
is_(str_ends_with((string)array_values($imgs)[0], '.jpg'), 'and it is the new one');

echo "\nRemoving takes the caption with it\n";
is_(MediaLibrary::remove($store2, 'image', 'mini-kit'), 'the photo is removed');
is_(MediaLibrary::find($store2, 'mini-kit') === null, 'and is gone from the library');
is_(!is_file($store2 . '/photos/mini-kit.txt'), 'the caption file went too');
is_(!MediaLibrary::remove($store2, 'image', 'never-existed'), 'removing nothing reports false');

echo "\nThe failures a person actually hits are explained, not just refused\n";
$r = MediaLibrary::store($store2, ['error' => UPLOAD_ERR_NO_FILE], 'image', 'x');
is_(!$r['ok'] && stripos($r['error'], 'No file') !== false, 'no file chosen', $r['error']);
$r = MediaLibrary::store($store2, ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => ''], 'image', 'x');
is_(!$r['ok'] && stripos($r['error'], 'larger than') !== false, 'too large for the server', $r['error']);
$r = MediaLibrary::store($store2, $mk('blank.png', ''), 'image', 'blank');
is_(!$r['ok'] && stripos($r['error'], 'empty') !== false, 'an empty file', $r['error']);
$r = MediaLibrary::store($store2, $mk('c.png', $png), 'image', '!!!');
is_(!$r['ok'] && stripos($r['error'], 'letters, numbers') !== false,
    'a name with nothing usable in it', $r['error']);

echo "\nThe admin page calls the library rather than writing files itself\n";
$tab = (string)file_get_contents($root . '/tabs/engage/wa_ai_setup.php');
is_(strpos($tab, 'MediaLibrary::store(') !== false, 'upload goes through store()');
is_(strpos($tab, 'MediaLibrary::remove(') !== false, 'and removal through remove()');
is_(strpos($tab, 'move_uploaded_file') === false,
    'the page never moves an upload itself',
    'validation lives in one place or it lives in none');
is_(strpos($tab, 'enctype="multipart/form-data"') !== false, 'the form can actually carry a file');
is_(substr_count($tab, '$_csrf') >= 6, 'and every form on the page is CSRF-stamped');

echo "\nThe equipment screen files a photo under the product's own name\n";
// "Starlink Mini Kit" -> "starlink-mini-kit", which is also how the product
// reaches the prompt from uCRM. The row and its picture line up by name, with
// nothing to keep in step by hand.
foreach ([['Starlink Mini Kit', 'starlink-mini-kit'],
          ['Starlink Standard Kit', 'starlink-standard-kit'],
          ['Professional Installation', 'professional-installation'],
          ['Residential Lite ( up to 100 Mbps)', 'residential-lite-up-to-100-mbps']] as $pair) {
    list($title, $expect) = $pair;
    is_(MediaLibrary::key($title) === $expect,
        '"' . $title . '" files as "' . $expect . '"', MediaLibrary::key($title));
}

$hwTab  = (string)file_get_contents($root . '/tabs/sales/hardware.php');
$hwPost = (string)file_get_contents($root . '/includes/post/post_sync.php');
is_(strpos($hwTab, 'upload_hw_media') !== false, 'the equipment table can upload');
is_(strpos($hwTab, 'delete_hw_media') !== false, 'and remove');
is_(strpos($hwTab, 'MediaLibrary::key(') !== false,
    'and it derives the name the same way the library does',
    'a second naming rule is a row whose photo never matches');
is_(strpos($hwPost, 'MediaLibrary::store(') !== false, 'the handler goes through store()');
is_(strpos($hwPost, 'move_uploaded_file') === false,
    'and never moves an upload itself');
is_(strpos($hwPost, 'csrfCheck()') !== false, 'with CSRF checked before writing');

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

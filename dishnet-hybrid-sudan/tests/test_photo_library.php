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
require_once $root . '/lib/PhotoLibrary.php';
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
is_(PhotoLibrary::all($tmp) === [], 'no photos found');
is_(PhotoLibrary::promptBlock($tmp) === '', 'and the prompt block is empty',
    'an install with no photos must be byte-identical to one before this existed');
is_(PhotoLibrary::all($tmp . '/nope') === [], 'a missing folder is not an error either');

echo "\nA file name is the name the assistant asks for\n";
file_put_contents($tmp . '/photos/mini-kit.jpg', $png);
file_put_contents($tmp . '/photos/Standard Kit.PNG', $png);
file_put_contents($tmp . '/photos/installed-roof.webp', $png);
$all = PhotoLibrary::all($tmp);
is_(count($all) === 3, 'three photos found', (string)count($all));
is_(isset($all['mini-kit']), 'mini-kit.jpg is "mini-kit"');
is_(isset($all['standard-kit']), 'and "Standard Kit.PNG" normalises to "standard-kit"',
    'a name the model cannot type back exactly is a name it cannot ask for');
is_(isset($all['installed-roof']), 'webp is accepted too');

echo "\nA caption file beside the image travels with it\n";
file_put_contents($tmp . '/photos/mini-kit.txt', 'Starlink Mini — dish, built-in wifi, cables, power supply');
$all = PhotoLibrary::all($tmp);
has('the caption is loaded', (string)$all['mini-kit']['caption'], 'built-in wifi');
is_($all['standard-kit']['caption'] === '', 'and one without a caption stays bare',
    'better bare than captioned from a guess');

echo "\nLookup is exact, and a name we do not hold finds nothing\n";
is_(PhotoLibrary::find($tmp, 'mini-kit') !== null, 'an exact name resolves');
is_(PhotoLibrary::find($tmp, 'Mini-Kit') !== null, 'case does not matter');
is_(PhotoLibrary::find($tmp, 'starlink-v4-dish-2026') === null,
    'an invented name resolves to nothing',
    'this is what stops a hallucinated name becoming the wrong picture');
is_(PhotoLibrary::find($tmp, '') === null, 'and so does an empty one');

echo "\nOnly real images, only sane sizes\n";
file_put_contents($tmp . '/photos/notes.txt', 'not an image');
file_put_contents($tmp . '/photos/contract.pdf', '%PDF-');
file_put_contents($tmp . '/photos/empty.jpg', '');
is_(!isset(PhotoLibrary::all($tmp)['notes']), 'a stray .txt is not a photo');
is_(!isset(PhotoLibrary::all($tmp)['contract']), 'nor a pdf');
is_(!isset(PhotoLibrary::all($tmp)['empty']), 'nor a zero-byte file');
is_(PhotoLibrary::payload(['path' => $tmp . '/photos/gone.jpg']) === '',
    'and a file that vanished yields no payload, not a crash');

echo "\nThe prompt lists exactly what exists, and forbids the rest\n";
$block = PhotoLibrary::promptBlock($tmp);
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

exec('rm -rf ' . escapeshellarg($tmp));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

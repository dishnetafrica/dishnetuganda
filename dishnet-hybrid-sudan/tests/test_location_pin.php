<?php
/**
 * test_location_pin.php — a customer who sends a pin must be answered.
 *
 * 17 September. A prospect on the sales number was asked which area they were
 * in and replied with a WhatsApp location pin. Nothing happened at all: no
 * reply, no record of the coordinates, no error. The conversation looked like
 * somebody who lost interest.
 *
 * evoExtractText() knew eight message shapes — conversation, extendedText,
 * three captions, three button replies — and locationMessage was not one of
 * them. So the text came out empty and the queue step dropped the message as
 * media-only. The latitude and longitude, the one fact an installation cannot
 * proceed without, were never read out of the payload.
 *
 * This asserts the whole path: parsing, the three outcomes a pin can have,
 * storage, what the assistant is told, and what reaches the lead. The last of
 * those is the one with teeth — coordinates must come from the record and
 * never from the model, because a model asked for a latitude will supply one.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

require_once $root . '/lib/WaLocation.php';
require_once $root . '/lib/ConversationService.php';
require_once $root . '/lib/DishNetAiBrain.php';

/** An Evolution message envelope, as the webhook receives it. */
function evoMsg(array $message, string $id = 'M1'): array
{
    return [
        'key'              => ['id' => $id, 'remoteJid' => '256700000001@s.whatsapp.net', 'fromMe' => false],
        'message'          => $message,
        'pushName'         => 'Test Sender',
        'messageTimestamp' => 1789000000,
    ];
}
function pin(float $lat, float $lng, string $name = ''): array
{
    $n = ['degreesLatitude' => $lat, 'degreesLongitude' => $lng];
    if ($name !== '') $n['name'] = $name;
    return ['locationMessage' => $n];
}

// Kampala, roughly the Acacia Mall office.
$KLA_LAT = 0.3354; $KLA_LNG = 32.5876;

echo "\n1. A valid pin is read\n";
$p = WaLocation::fromMessage(pin($KLA_LAT, $KLA_LNG, 'Home'));
is_($p !== null, 'a locationMessage is recognised at all');
is_($p !== null && abs($p['lat'] - $KLA_LAT) < 0.000001, 'latitude survives exactly');
is_($p !== null && abs($p['lng'] - $KLA_LNG) < 0.000001, 'longitude survives exactly');
is_($p !== null && $p['name'] === 'Home', 'and the label the customer gave it');
$live = WaLocation::fromMessage(['liveLocationMessage' => [
    'degreesLatitude' => $KLA_LAT, 'degreesLongitude' => $KLA_LNG]]);
is_($live !== null && $live['live'] === true, 'a live location is a pin too, and says so');

echo "\n2. Invalid coordinates are not a location\n";
// (float)null is 0.0, so 0,0 is what a missing coordinate becomes. It is a
// real place in the Gulf of Guinea and nobody in either market is there.
is_(WaLocation::fromMessage(pin(0.0, 0.0)) === null, 'Null Island (0,0) is refused');
is_(WaLocation::fromMessage(pin(91.0, 32.0)) === null, 'a latitude past the pole is refused');
is_(WaLocation::fromMessage(pin(-91.0, 32.0)) === null, 'and past the other pole');
is_(WaLocation::fromMessage(pin(0.33, 181.0)) === null, 'a longitude off the globe is refused');
is_(WaLocation::fromMessage(['locationMessage' => [
    'degreesLatitude' => 'here', 'degreesLongitude' => 'there']]) === null,
    'words where the numbers should be are refused');
is_(!WaLocation::isSane(0.0, 0.0), 'isSane agrees about 0,0');
is_(WaLocation::isSane($KLA_LAT, $KLA_LNG), 'and about a real point');

echo "\n3. Missing coordinates are not a location either\n";
is_(WaLocation::fromMessage(['locationMessage' => []]) === null,
    'a locationMessage with no numbers in it');
is_(WaLocation::fromMessage(['locationMessage' => ['name' => 'My house']]) === null,
    'a label with no coordinates is not a place we can drive to');
is_(WaLocation::fromMessage([]) === null, 'an empty message');

echo "\n4. Bounds: kept when outside, never silently accepted\n";
is_(WaLocation::inBounds($KLA_LAT, $KLA_LNG, 'UG'), 'Kampala is in Uganda');
is_(WaLocation::inBounds(4.85, 31.58, 'SS'), 'Juba is in South Sudan');
is_(!WaLocation::inBounds(4.85, 31.58, 'UG'), 'Juba is not in Uganda');
is_(!WaLocation::inBounds(51.50, -0.12, 'UG'), 'nor is London');
// The out-of-area pin is still a pin. Discarding it would repeat the original
// bug with a better excuse: the box may be wrong, or the site may genuinely be
// across a border, and either way a person should see it.
$far = WaLocation::fromMessage(pin(51.50, -0.12));
is_($far !== null, 'an out-of-area pin is still parsed, not thrown away');
$desc = WaLocation::describe($far, false, 'UG');
is_(strpos($desc, 'outside Uganda') !== false, 'and the assistant is told it is outside Uganda');
is_(strpos($desc, 'confirm') !== false, 'and to confirm rather than assume');

echo "\n   the country comes from the deployment, not from a new setting\n";
is_(WaLocation::country(['timezone' => 'Africa/Kampala']) === 'UG', 'Kampala timezone means Uganda');
is_(WaLocation::country(['timezone' => 'Africa/Juba']) === 'SS', 'Juba timezone means South Sudan');
is_(WaLocation::country([]) === 'SS', 'unset falls back to the plugin default zone, South Sudan');
is_(WaLocation::country(['timezone' => 'Africa/Juba', 'geo_country' => 'UG']) === 'UG',
    'and an explicit geo_country overrides it');

echo "\n5. What the assistant is shown\n";
$d = WaLocation::describe(WaLocation::fromMessage(pin($KLA_LAT, $KLA_LNG, 'Home')), true, 'UG');
is_(strpos($d, '0.3354') !== false && strpos($d, '32.5876') !== false,
    'the coordinates are in the line it reads');
is_(strpos($d, '[The customer sent') === 0,
    'phrased as an event, not as words the customer typed', $d);
is_(strpos($d, 'outside') === false, 'with no doubt attached when the pin is in area');

echo "\n5b. A pin rescues a message that would otherwise be dropped\n";
// The line that lost the customer. evoExtractText returns '' for a pin, and
// the queue step drops an empty text — so unless the pin puts something there,
// nothing is queued and nobody is answered. Driven directly rather than
// inferred from the shape of the webhook source.
$pinOnly = WaLocation::mergeText('', WaLocation::fromMessage(pin($KLA_LAT, $KLA_LNG)), true, 'UG');
is_($pinOnly !== '', 'a pin with no caption produces text to queue');
is_(strpos($pinOnly, '0.3354') !== false, 'and the coordinates are in it', $pinOnly);
$both = WaLocation::mergeText('Here is my place',
    WaLocation::fromMessage(pin($KLA_LAT, $KLA_LNG)), true, 'UG');
is_(strpos($both, 'Here is my place') !== false && strpos($both, '0.3354') !== false,
    'a caption AND a pin keeps both halves', $both);
is_(WaLocation::mergeText('How much is the kit?', null, true, 'UG') === 'How much is the kit?',
    'an ordinary message is returned untouched');
is_(WaLocation::mergeText('', null, true, 'UG') === '',
    'and a media-only message stays empty, so it is still not queued');

echo "\n6. Storage: the message keeps the coordinates\n";
$dir = sys_get_temp_dir() . '/dishnet_pin_' . getmypid();
@mkdir($dir, 0700, true);
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
// SqliteStore::create runs the migrations, so this exercises 072 itself
// rather than a hand-made column that might not match it.
$store = SqliteStore::create($dir);
$pdo   = $store->getPdo();
$svc   = new ConversationService($dir, $pdo);

$cols = [];
foreach ($pdo->query('PRAGMA table_info(wa_messages)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $cols[] = $c['name'];
}
is_(in_array('location_lat', $cols, true) && in_array('location_lng', $cols, true),
    'migration 072 added the columns', implode(',', $cols));

$convId = $svc->importEvoMessage(evoMsg(pin($KLA_LAT, $KLA_LNG, 'Home'), 'PIN1'), 'sales');
is_($convId !== null, 'the pin message is stored');
$row = $pdo->query("SELECT body, media_type, location_lat, location_lng, metadata
                      FROM wa_messages WHERE wa_message_id = 'PIN1'")->fetch(PDO::FETCH_ASSOC);
is_(is_array($row), 'and can be read back');
is_(is_array($row) && abs((float)$row['location_lat'] - $KLA_LAT) < 0.000001,
    'latitude is on the row', (string)($row['location_lat'] ?? 'null'));
is_(is_array($row) && abs((float)$row['location_lng'] - $KLA_LNG) < 0.000001,
    'longitude is on the row', (string)($row['location_lng'] ?? 'null'));
is_(is_array($row) && $row['media_type'] === 'location', 'typed as a location');
// "[LOCATION]" was all a colleague opening the inbox used to see.
is_(is_array($row) && strpos((string)$row['body'], '0.3354') !== false,
    'and the inbox shows where, not just that a pin arrived', (string)($row['body'] ?? ''));

echo "\n7. Everything else still behaves exactly as it did\n";
$svc->importEvoMessage(evoMsg(['conversation' => 'I am in Mukono off Kayunga road'], 'TXT1'), 'sales');
$txt = $pdo->query("SELECT body, media_type, location_lat FROM wa_messages
                     WHERE wa_message_id = 'TXT1'")->fetch(PDO::FETCH_ASSOC);
is_(is_array($txt) && $txt['body'] === 'I am in Mukono off Kayunga road',
    'a typed location is stored as the words the customer used');
is_(is_array($txt) && $txt['media_type'] === null, 'with no media type');
is_(is_array($txt) && $txt['location_lat'] === null, 'and no coordinates invented for it');

$svc->importEvoMessage(evoMsg(['conversation' => 'How much is the kit?'], 'TXT2'), 'sales');
$plain = $pdo->query("SELECT body, location_lat FROM wa_messages
                       WHERE wa_message_id = 'TXT2'")->fetch(PDO::FETCH_ASSOC);
is_(is_array($plain) && $plain['body'] === 'How much is the kit?', 'an ordinary message is untouched');
is_(is_array($plain) && $plain['location_lat'] === null, 'and carries no coordinates');

$svc->importEvoMessage(evoMsg(['imageMessage' => ['mimetype' => 'image/jpeg']], 'IMG1'), 'sales');
$img = $pdo->query("SELECT body, media_type, location_lat FROM wa_messages
                     WHERE wa_message_id = 'IMG1'")->fetch(PDO::FETCH_ASSOC);
is_(is_array($img) && $img['body'] === '[IMAGE]', 'a photo with no caption still stores as [IMAGE]');
is_(is_array($img) && $img['media_type'] === 'image', 'still typed as an image');
is_(is_array($img) && $img['location_lat'] === null, 'and is not mistaken for a location');

echo "\n8. The webhook queues a pin instead of dropping it\n";
$wh = (string)file_get_contents($root . '/evo_webhook.php');
is_(strpos($wh, 'WaLocation::fromMessage') !== false,
    'evo_webhook reads the pin out of the payload');
// mergeText is what puts something in $text for a pin. Assert the webhook
// actually calls it, and calls it BEFORE the drop — a correct function nobody
// invokes is the same outage with tidier code.
$merges   = strpos($wh, 'WaLocation::mergeText($text, $loc, $inArea, $country)');
$dropTest = strpos($wh, "if (\$text === '') {");
is_($merges !== false, 'evo_webhook calls mergeText to build the text it queues');
is_($merges !== false && $dropTest !== false && $merges < $dropTest,
    'and does it BEFORE the empty-text drop — the line that lost the pin');
is_(strpos($wh, "'location'          => \$locEvent") !== false,
    'the coordinates ride on the event as data, separate from the prose');
// The silence is the fault. A message the AI cannot answer must leave a trace.
is_(strpos($wh, 'no text to answer') !== false,
    'a message that still cannot be answered is logged, not counted silently');
is_(strpos($wh, 'location pin UNUSABLE') !== false,
    'and a pin with unreadable coordinates says so in the log');

echo "\n9. The assistant is told about the pin, and told what it cannot do\n";
$brain = new DishNetAiBrain(['ai_provider' => 'openai', 'openai_api_key' => 'k']);
$base  = ['channel' => 'sales', 'message' => 'here is my place', 'identity_state' => 'unknown'];
$withPin = $brain->promptPreview($base + ['location' =>
    ['lat' => $KLA_LAT, 'lng' => $KLA_LNG, 'in_bounds' => true, 'name' => 'Home']]);
$noPin   = $brain->promptPreview($base);
is_(strpos($withPin, 'LOCATION PIN JUST RECEIVED') !== false, 'the block appears when a pin arrives');
is_(strpos($noPin, 'LOCATION PIN JUST RECEIVED') === false, 'and never when one has not');
is_(strpos($withPin, '0.3354') !== false, 'the coordinates are in it');
// It has no map. A confident guess about where somebody lives is worse than a
// question, and it would be believed.
is_(strpos($withPin, 'NO map') !== false, 'it is told it has no map');
is_(strpos($withPin, 'estimate a distance') !== false, 'and not to estimate distance or travel time');
is_(strpos($withPin, 'colleague confirms coverage') !== false, 'coverage stays a human answer');
$outPin = $brain->promptPreview($base + ['location' =>
    ['lat' => 51.50, 'lng' => -0.12, 'in_bounds' => false]]);
is_(strpos($outPin, 'OUTSIDE our service area') !== false,
    'an out-of-area pin is flagged to the assistant');

echo "\n10. The lead gets the real coordinates, and only the real ones\n";
require_once $root . '/lib/LeadMatcher.php';
require_once $root . '/lib/AiLeadService.php';
$leads = new AiLeadService($store, ['ai_lead_capture' => '1'], $pdo);

// A model that invents a latitude must not be able to write one. The keys are
// not in FIELDS, so clean() drops them before anything else can happen.
$r = $leads->capture(
    ['requirement' => 'Starlink at home', 'location' => 'Mukono',
     'location_lat' => 9.9999, 'location_lng' => 9.9999],
    '256700000001', $convId, 'whatsapp_ai');
is_($r['ok'] === true, 'a qualified lead is written');
$saved = $store->load('leads.json') ?? [];
$lead  = $saved[0] ?? [];
is_(!isset($lead['location_lat']) || $lead['location_lat'] === null,
    'a coordinate the MODEL supplied never reaches the record',
    'got ' . json_encode($lead['location_lat'] ?? null));

// The same coordinates offered as a trusted fact are kept.
$r2 = $leads->capture(['requirement' => 'Starlink at home'], '256700000001', $convId,
    'whatsapp_ai', ['location_lat' => $KLA_LAT, 'location_lng' => $KLA_LNG]);
is_($r2['action'] === 'updated', 'a later message updates the same lead, not a second one');
$lead = ($store->load('leads.json') ?? [])[0] ?? [];
is_(isset($lead['location_lat']) && abs((float)$lead['location_lat'] - $KLA_LAT) < 0.000001,
    'and the pin the system recorded IS written', json_encode($lead['location_lat'] ?? null));
is_(($lead['location_source'] ?? '') === 'whatsapp_pin', 'stamped with where it came from');

// Trusted does not mean unchecked.
$r3 = $leads->capture(['requirement' => 'x'], '256700000002', 0, 'whatsapp_ai',
    ['location_lat' => 0.0, 'location_lng' => 0.0]);
$l2 = array_values(array_filter($store->load('leads.json') ?? [],
    fn($l) => ($l['phone'] ?? '') === '256700000002'));
is_(($l2[0]['location_lat'] ?? null) === null,
    '0,0 is refused even when the system offers it');

// A pin is enough to act on: it is the location, better than a typed one.
$why = $leads->whyNotQualified(['requirement' => 'Starlink', 'location_lat' => $KLA_LAT,
                                'location_lng' => $KLA_LNG]);
is_($why === '', 'a requirement plus a pin qualifies a lead', $why);
$why2 = $leads->whyNotQualified(['requirement' => 'Starlink']);
is_($why2 !== '', 'a requirement with nothing to act on still does not');

echo "\n11. A pin sent earlier still reaches a lead written later\n";
// The pin and the sentence that qualifies the lead are almost never the same
// message: the customer sends the pin, and two replies later says "yes please".
// Reading only the current turn would attach coordinates only when the
// qualifying message happened to BE the pin.
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/EventBus.php';
require_once $root . '/workers/WorkerBase.php';
require_once $root . '/workers/AiReplyWorker.php';
// Point the worker at this test's own directory. Without it the constructor
// resolves the REAL data dir beside the plugin to look for a flyer, and a test
// that reads production state is not a test.
$prevDataDir = getenv('DN_DATA_DIR');
putenv('DN_DATA_DIR=' . $dir);
$worker = new AiReplyWorker($store, ['ai_provider' => 'openai', 'openai_api_key' => 'k'], 5, 1);
putenv('DN_DATA_DIR=' . ($prevDataDir === false ? '' : $prevDataDir));
$m = new ReflectionMethod('AiReplyWorker', 'latestPin');
$m->setAccessible(true);

$found = $m->invoke($worker, $convId, []);
is_(isset($found['location_lat']) && abs($found['location_lat'] - $KLA_LAT) < 0.000001,
    'the pin stored three messages ago is found', json_encode($found));

// The newest wins — a customer who corrects themselves is not overruled by
// the pin they sent first.
$svc->importEvoMessage(evoMsg(pin(0.4500, 32.6000), 'PIN2'), 'sales');
$pdo->exec("UPDATE wa_messages SET sent_at = '2030-01-01 00:00:00' WHERE wa_message_id = 'PIN2'");
$found2 = $m->invoke($worker, $convId, []);
is_(isset($found2['location_lat']) && abs($found2['location_lat'] - 0.45) < 0.000001,
    'and a newer pin replaces it', json_encode($found2));

$none = $m->invoke($worker, 999999, []);
is_($none === [], 'a conversation with no pin yields nothing to write');

// No database handle is not a reason to lose the pin in front of you.
$turnOnly = $m->invoke($worker, 999999, ['location' => ['lat' => $KLA_LAT, 'lng' => $KLA_LNG]]);
is_(isset($turnOnly['location_lat']), 'the turn\'s own pin is the fallback');

echo "\n12. A database without the new columns still stores every message\n";
// storeMessage runs for every message in and out. Naming location_lat
// unconditionally would mean a migration that has not run — or that failed,
// which stops the runner dead and skips everything after it — throws a
// PDOException on each message and takes the whole inbox down. A pin is worth
// having; it is not worth that.
$oldDir = $dir . '/premigration';
@mkdir($oldDir, 0700, true);
$oldPdo = new PDO('sqlite:' . $oldDir . '/plugin.sqlite3');
$oldPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$oldSvc = new ConversationService($oldDir, $oldPdo);   // builds the pre-072 schema
$oldCols = [];
foreach ($oldPdo->query('PRAGMA table_info(wa_messages)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $oldCols[] = $c['name'];
}
is_(!in_array('location_lat', $oldCols, true),
    'the fixture really is a database without the columns');

$threw = '';
$oldId = null;
try {
    $oldId = $oldSvc->importEvoMessage(evoMsg(pin($KLA_LAT, $KLA_LNG), 'OLDPIN'), 'sales');
} catch (\Throwable $e) {
    $threw = get_class($e) . ': ' . $e->getMessage();
}
is_($threw === '', 'a pin does not throw on a pre-migration database', $threw);
is_($oldId !== null, 'and the message is still stored');
$oldRow = $oldPdo->query("SELECT body FROM wa_messages WHERE wa_message_id = 'OLDPIN'")
                 ->fetch(PDO::FETCH_ASSOC);
is_(is_array($oldRow) && strpos((string)$oldRow['body'], '0.3354') !== false,
    'the coordinates still reach the inbox through the body');

$threw2 = '';
try { $oldSvc->importEvoMessage(evoMsg(['conversation' => 'still working'], 'OLDTXT'), 'sales'); }
catch (\Throwable $e) { $threw2 = get_class($e); }
is_($threw2 === '', 'and an ordinary message is unaffected', $threw2);

foreach (glob($oldDir . '/*') ?: [] as $f) @unlink($f);
@rmdir($oldDir);

foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
@rmdir($dir);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

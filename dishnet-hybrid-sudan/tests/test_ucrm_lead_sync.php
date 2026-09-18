<?php
/**
 * test_ucrm_lead_sync.php — Phase 2: an AI lead reaches uCRM, exactly once.
 *
 * The assistant has written qualified leads to leads.json since 5.18.3 and
 * nothing ever carried them into uCRM. The sales team's own system never
 * learned that a conversation had become an opportunity: every lead screen,
 * every assignment rule and every dashboard reads the plugin's copy, and uCRM
 * reads none of it.
 *
 * Driven through the REAL CrmApiClient against a fake uCRM, because the two
 * things most likely to be wrong are both on the wire: the payload uCRM will
 * accept (5.18.11 shipped one carrying a field the product record does not
 * have and every create failed with a 422), and how many clients a retry
 * creates.
 *
 * The count is the point. EventBus retries whenever uCRM times out AFTER doing
 * the work, so "created once" is not a property of the happy path — it is a
 * property that has to survive being run again.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  ok   {$m}\n"; }
function bad(string $m, string $d=''): void { global $fail; $fail++; echo "  FAIL {$m}\n"; if($d)echo "       {$d}\n"; }
function is_(bool $c, string $m, string $d=''): void { $c ? ok($m) : bad($m, $d); }

require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/CrmApiClient.php';
require_once $root . '/lib/LeadMatcher.php';
require_once $root . '/lib/WaLocation.php';
require_once $root . '/lib/UcrmLeadSync.php';

// ── the fake uCRM ───────────────────────────────────────────────────────
function boot(string $router, int $base, string $sig): array {
    foreach (range(0, 9) as $slot) {
        $cand = $base + ((getmypid() + $slot * 11) % 60);
        $p = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', $cand, escapeshellarg($router)),
                       [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 40; $i++) {
            $ch = curl_init("http://127.0.0.1:{$cand}/__test/state");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '']);
            $r = curl_exec($ch); curl_close($ch);
            if ($r !== false) {
                if (strpos((string)$r, $sig) !== false) return [$p, $cand];
                break;
            }
            usleep(100000);
        }
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    return [null, 0];
}
array_map('unlink', glob(sys_get_temp_dir() . '/fake_ucrm_*.json') ?: []);
[$srv, $port] = boot($root . '/tests/fixtures/fake_ucrm_server.php', 9760, 'FAKE-UCRM-TEST');
if ($srv === null) { echo "  SKIP could not start the fake uCRM\n"; exit(0); }
register_shutdown_function(function () use (&$srv) {
    if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }
});
$base = "http://127.0.0.1:{$port}";
$hit = function (string $p) use ($base) {
    $ch = curl_init($base . $p);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode((string)$r, true) ?: [];
};
$scenario = function (string $n) use ($hit) { $hit('/__test/scenario?name=' . $n); };
$reset    = function () use ($hit) { $hit('/__test/reset'); };
$created  = function () use ($hit) { return $hit('/__test/clients'); };

$dir = sys_get_temp_dir() . '/dn_leadsync_' . getmypid();
@mkdir($dir, 0700, true);

/** A store with one lead in it, reset for each case. */
$freshStore = function (array $lead) use ($dir) {
    foreach (glob($dir . '/*.json') ?: [] as $f) @unlink($f);
    $s = new JsonStore($dir);
    $s->save('leads.json', [$lead + ['id' => 1]]);
    return $s;
};
$LEAD = [
    'phone'          => '+256700000001',
    'customer_name'  => 'Damulira Ivan',
    'requirement'    => 'Starlink at home',
    'location'       => 'Mukono off Kayunga road',
    'customer_type'  => 'Residential',
    'location_lat'   => 0.3354,
    'location_lng'   => 32.5876,
    'quote_requested'=> true,
];
$ON  = ['ai_crm_lead_sync' => '1'];
$crm = function () use ($base) { return new CrmApiClient($base, 'test-key'); };
$leadRow = function ($store) { return ($store->load('leads.json') ?? [])[0] ?? []; };

echo "\n1. Off unless switched on — shipping is not enabling\n";
$s = $freshStore($LEAD);
$r = (new UcrmLeadSync($s, [], $crm()))->syncLead(1);
is_($r['ok'] === false && $r['action'] === 'disabled', 'absent config means no uCRM write');
is_(count($created()['clients']) === 0, 'and nothing was created', json_encode($created()['count'] ?? -1));

echo "\n2. A qualified lead becomes a uCRM lead client\n";
$reset(); $scenario('uganda');
$s = $freshStore($LEAD);
$r = (new UcrmLeadSync($s, $ON, $crm()))->syncLead(1);
is_($r['ok'] === true && $r['action'] === 'created', 'created', json_encode($r));
$c = $created();
is_(count($c['clients']) === 1, 'exactly one client exists', (string)count($c['clients']));
$made = $c['clients'][0] ?? [];
is_(($made['isLead'] ?? null) === true, 'flagged as a LEAD, not a customer');
is_(($made['firstName'] ?? '') === 'Damulira' && ($made['lastName'] ?? '') === 'Ivan',
    'the name is split for uCRM', json_encode([$made['firstName'] ?? '', $made['lastName'] ?? '']));
is_(($made['contacts'][0]['phone'] ?? '') === '+256700000001', 'the phone is on the contact');
is_(($made['street1'] ?? '') === 'Mukono off Kayunga road', 'the typed location is kept as an address');
is_(strpos((string)($made['note'] ?? ''), 'ASKED FOR A QUOTATION') !== false,
    'a quote request is in the note a salesperson reads');
is_(strpos((string)($made['note'] ?? ''), 'Starlink at home') !== false, 'and what they asked for');
is_(($leadRow($s)['crm_client_id'] ?? 0) > 0, 'the uCRM id is written back to the lead');

echo "\n   organizationId and countryId come from the existing clients\n";
// Never the literals 2 and null that the KYC payload carries — those are the
// two values tools/org_probe.php exists because nobody could justify.
is_(($made['organizationId'] ?? null) === 1,
    'organizationId is the one most clients actually use', json_encode($made['organizationId'] ?? null));
is_(($made['countryId'] ?? null) === 220, 'countryId likewise', json_encode($made['countryId'] ?? null));
$s2 = $freshStore($LEAD);
$r2 = (new UcrmLeadSync($s2, $ON + ['ucrm_lead_organization_id' => '7'], $crm()))->syncLead(1);
$c2 = $created();
is_((end($c2['clients'])['organizationId'] ?? null) === 7, 'and config overrides the evidence');

echo "\n3. The coordinates land on the client, by the proven mechanism\n";
$patches = $created()['patches'] ?? [];
$gps = null;
foreach ($patches as $p) if (isset($p['gpsLat'])) $gps = $p;
is_($gps !== null, 'a PATCH carried the coordinates', json_encode($patches));
is_($gps !== null && abs((float)$gps['gpsLat'] - 0.3354) < 0.000001, 'gpsLat is the pin');
is_($gps !== null && abs((float)$gps['gpsLon'] - 32.5876) < 0.000001, 'gpsLon is the pin');
// Narrow on purpose: a salesperson who fixed a name in uCRM must not have it
// undone by the next message the customer sends.
is_($gps !== null && !isset($gps['firstName']) && !isset($gps['street1']),
    'and the patch rewrites nothing else', json_encode($gps));

echo "\n4. Retried, it does not create a second client\n";
// EventBus retries whenever uCRM times out AFTER doing the work. This is the
// case that makes idempotence a property rather than a hope.
$reset(); $scenario('uganda');
$s = $freshStore($LEAD);
$sync = new UcrmLeadSync($s, $ON, $crm());
$a = $sync->syncLead(1);
$b = $sync->syncLead(1);
$c = $sync->syncLead(1);
is_($a['action'] === 'created', 'the first call creates');
is_($b['action'] === 'updated' && $c['action'] === 'updated', 'the next two update',
    json_encode([$b['action'], $c['action']]));
is_(count($created()['clients']) === 1, 'still exactly one client in uCRM',
    (string)count($created()['clients']));
is_($b['crm_client_id'] === $a['crm_client_id'], 'and it is the same one');

echo "\n5. A number uCRM already knows is linked, never duplicated\n";
$reset(); $scenario('known_phone');
$s = $freshStore($LEAD);
$r = (new UcrmLeadSync($s, $ON, $crm()))->syncLead(1);
is_($r['action'] === 'linked', 'linked to the existing client', json_encode($r));
is_($r['crm_client_id'] === 77, 'by its real id');
is_(count($created()['clients']) === 0, 'and no client was created', (string)count($created()['clients']));
is_(($leadRow($s)['crm_client_id'] ?? 0) === 77, 'the lead now points at it');

echo "\n6. Two customers on one number: never guessed\n";
// DishNetTools::identifyCustomerByPhone refuses to choose between two clients
// sharing a number. This inherits the refusal rather than re-deciding it:
// attaching a conversation to the wrong customer is worse than to nobody.
$reset(); $scenario('dup_phone');
$s = $freshStore($LEAD);
$r = (new UcrmLeadSync($s, $ON, $crm()))->syncLead(1);
is_($r['ok'] === false && $r['action'] === 'skipped', 'refused', json_encode($r));
is_(strpos($r['reason'], 'ambiguous') !== false, 'and says why', $r['reason']);
is_(count($created()['clients']) === 0, 'nothing created');
$row = $leadRow($s);
is_(($row['crm_sync_flag'] ?? '') === 'ambiguous_phone', 'the lead is flagged for a person');
is_(($row['crm_client_id'] ?? 0) === 0 || !isset($row['crm_client_id']),
    'and is attached to no uCRM client at all');

echo "\n7. A fuzzy search hit that is not this number is not a match\n";
// uCRM's search is fuzzy. Trusting it would link a lead to a stranger.
$reset(); $scenario('fuzzy_miss');
$s = $freshStore($LEAD);
$r = (new UcrmLeadSync($s, $ON, $crm()))->syncLead(1);
is_($r['action'] === 'created', 'the digits are confirmed, so a new lead is created',
    json_encode($r));
is_(($created()['clients'][0]['id'] ?? 0) !== 88, 'not linked to the stranger');

echo "\n8. uCRM unavailable: the lead is kept and the failure is loud\n";
$reset(); $scenario('crm_down');
$s = $freshStore($LEAD);
$r = (new UcrmLeadSync($s, $ON, $crm()))->syncLead(1);
is_($r['ok'] === false && $r['action'] === 'failed', 'reported as a failure, not a skip',
    json_encode($r));
is_(($leadRow($s)['crm_client_id'] ?? 0) === 0 || !isset($leadRow($s)['crm_client_id']),
    'no id is invented');
is_(($leadRow($s)['crm_sync_error'] ?? '') !== '', 'the reason is written onto the lead');
// 'failed' is what the worker rethrows on, so EventBus retries it. A 'skipped'
// would be swallowed — which is why the two are different words.
$w = (string)file_get_contents($root . '/workers/UcrmLeadWorker.php');
is_(strpos($w, "in_array(\$r['action'], ['disabled', 'skipped'], true)") !== false,
    'the worker retries a failure but not a decision');
is_(strpos($w, 'throw new \\RuntimeException') !== false, 'by rethrowing, so the queue backs off');
is_(strpos($w, 'NEVER reached uCRM after five attempts') !== false,
    'and a dead-lettered lead says so out loud');

echo "\n8b. A lookup that fails while the defaults ARE known\n";
// The dangerous shape, and the one a whole-uCRM outage hides: the phone search
// fails on its own. Nothing else is broken, organizationId is configured, so
// only findByPhone stands between this and a duplicate client for a customer
// uCRM already has. "No answer" must never be read as "no match".
$reset(); $scenario('lookup_down');
$s = $freshStore($LEAD);
$r = (new UcrmLeadSync($s, $ON + ['ucrm_lead_organization_id' => '1',
                                  'ucrm_lead_country_id' => '220'], $crm()))->syncLead(1);
is_($r['ok'] === false && $r['action'] === 'failed',
    'a failed lookup is a failure, not "nobody has this number"', json_encode($r));
is_(count($created()['clients']) === 0,
    'and NO client is created', (string)count($created()['clients']));
is_(($leadRow($s)['crm_sync_flag'] ?? '') === 'crm_unreachable', 'the lead says why it is waiting');

echo "\n9. A lead with nothing usable is skipped, not half-written\n";
$reset(); $scenario('uganda');
$s = $freshStore(['phone' => '', 'requirement' => 'Starlink']);
$r = (new UcrmLeadSync($s, $ON, $crm()))->syncLead(1);
is_($r['ok'] === false && $r['action'] === 'skipped', 'no phone, no sync', json_encode($r));
is_(count($created()['clients']) === 0, 'and nothing reached uCRM');
$r = (new UcrmLeadSync($s, $ON, $crm()))->syncLead(9999);
is_($r['ok'] === false && strpos($r['reason'], 'does not exist') !== false,
    'a lead id that is not there is said plainly', json_encode($r));

echo "\n10. A customer who gave no name is filed under their number\n";
$reset();
$s = $freshStore(['phone' => '+256700000001', 'customer_name' => '+256700000001',
                  'requirement' => 'Starlink']);
$r = (new UcrmLeadSync($s, $ON, $crm()))->syncLead(1);
$made = ($created()['clients'][0] ?? []);
is_(($made['firstName'] ?? '') === '+256700000001', 'the number is the name');
is_(($made['lastName'] ?? '') === '', 'and no surname is invented',
    json_encode($made['lastName'] ?? null));

echo "\n11. Only the fields uCRM accepts are sent\n";
// 5.18.11 sent a product payload carrying 'description' and every create
// failed with a 422. The fake refuses unknown fields for the same reason.
$reset();
$s = $freshStore($LEAD + ['company' => 'Bulwark Ltd', 'email' => 'ivan@example.com',
                          'ai_summary' => 'Wants it before month end']);
$r = (new UcrmLeadSync($s, $ON, $crm()))->syncLead(1);
is_($r['ok'] === true, 'a full lead is accepted by a strict uCRM', json_encode($r));
$made = ($created()['clients'][0] ?? []);
is_(($made['companyName'] ?? '') === 'Bulwark Ltd', 'the company goes in companyName');
is_(($made['contacts'][0]['email'] ?? '') === 'ivan@example.com', 'the email on the contact');
is_(strpos((string)($made['note'] ?? ''), 'Wants it before month end') !== false,
    'and the summary in the note');

echo "\n12. The reply path hands this to the queue, not to uCRM\n";
// A customer must never wait on uCRM for their answer.
$a = (string)file_get_contents($root . '/workers/AiReplyWorker.php');
is_(strpos($a, "\$this->bus->emit('crm.lead.sync'") !== false,
    'AiReplyWorker emits an event');
is_(strpos($a, 'UcrmLeadSync') === false,
    'and never calls the sync inline', 'an inline uCRM write blocks the reply');
$rw = (string)file_get_contents($root . '/run_worker.php');
is_(strpos($rw, 'new UcrmLeadWorker') !== false, 'the runner drains the queue');

foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
@rmdir($dir);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);

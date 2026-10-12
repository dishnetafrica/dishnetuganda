<?php
declare(strict_types=1);
/**
 * test_install_quote_prefill.php — 5.18.85: the installation authorisation's request form, filled from the customer's
 * latest uCRM quotation (lib/QuotationPrefill.php, install_auth_prefill in includes/api/api_install_auth.php, the form in
 * tabs/support/scheduling.php).
 *
 * On 06 Oct the first real authorisation went out with its charges typed by hand, while the customer's quotation already
 * held them. DishNet Uganda's quotations (quotation 000181, read for this release) carry four lines: the kit ("Starlink
 * Mini Kit + Mini Router", unit Pc), the plan ("Residential Lite (up to 100 Mbps)", Monthly), "Professional Installation"
 * (Time) and "Transportation charges to and from the site shall be borne by the customer." (Time, UGX 0). Proved here:
 *
 *   1. the quotation, read as the form reads it: the kit → equipment, the plan → service, the installation line → the
 *      installation charge, a transport line at 0 → asked for, never filled with 0; a priced transport line; other
 *      one-time charges; quantities; a discount is never a charge; decimals; long and broken labels
 *   2. which quotation: the latest, never another client's, never a rejected or void one, never one without lines
 *   3. through the real plugin: the prefill names the quotation and carries its values; another client's newer quotation
 *      is never used; a client with no quotation and a uCRM that does not answer leave the form as before; the prefill
 *      sends nothing and stores nothing
 *   4. the request then made with those values: the customer's WhatsApp carries them
 *   5. the form on the job page: the quotation named, the charges filled, transport asked for and refused when empty
 *   6. weakened copies of the code each fail this test
 *
 * Every person, number, quotation and job is fictitious; nothing leaves the machine.
 *
 *   php test_install_quote_prefill.php [--root=DIR] [--no-mutants]
 */
$opt  = getopt('', ['root:', 'no-mutants']);
$root = isset($opt['root']) ? rtrim((string)$opt['root'], '/') : dirname(__DIR__);
$withMutants = !isset($opt['no-mutants']);
require_once __DIR__ . '/fixtures/staff_jobs_sandbox.php';
require_once $root . '/lib/QuotationPrefill.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const CUST = '256700000915';   // client 15's WhatsApp, as the fake Evolution records it
const KIT  = 'Starlink Mini Kit + Mini Router';
const PLAN = 'Residential Lite (up to 100 Mbps)';
const INST = 'Professional Installation';
const TRAN = 'Transportation charges to and from the site shall be borne by the customer.';

/** A quotation as uCRM's billing/quotes answers it. */
$quote = function (int $id, int $client, string $created, array $items, int $status = 1, string $number = ''): array {
    return ['id' => $id, 'clientId' => $client, 'number' => $number !== '' ? $number : sprintf('%06d', $id), 'status' => $status,
            'createdDate' => $created, 'items' => $items];
};
$line = function (string $label, $price, string $unit, $qty = 1): array {
    return ['label' => $label, 'price' => $price, 'quantity' => $qty, 'total' => (float)$price * (float)$qty, 'type' => 'product', 'unit' => $unit];
};
$q181 = function (int $id = 181, int $client = 15, string $created = '2026-10-06T10:15:00+0300') use ($quote, $line): array {
    return $quote($id, $client, $created, [$line(KIT, 2249000, 'Pc'), $line(PLAN, 249000, 'Monthly'), $line(INST, 150000, 'Time'), $line(TRAN, 0, 'Time')]);
};
$kla = new \DateTimeZone('Africa/Kampala');
/** The key is there and holds null — `??` cannot say that: it reads a null as missing. */
$isNull = function (array $a, string $k): bool { return array_key_exists($k, $a) && $a[$k] === null; };

echo "test_install_quote_prefill.php — root {$root}\n";

// ── 1 ──────────────────────────────────────────────────────────────────
echo "\n1. The quotation, read as the form reads it\n";
$s1 = QuotationPrefill::suggest($q181(), $kla);
is_($s1['equipment'] === KIT, 'the kit line is the equipment', $s1['equipment']);
is_($s1['service'] === PLAN, 'the monthly line is the service', $s1['service']);
is_($s1['installation'] === '150000', 'the installation line is the installation charge', var_export($s1['installation'], true));
is_($s1['transport'] === null && $s1['transport_unpriced'] === true,
    'a transport line at 0 ("…borne by the customer") is listed without a price: asked for, never filled with 0', json_encode($s1));
is_($s1['other'] === null && $s1['other_label'] === '', 'nothing else is a charge: the kit\'s and the plan\'s prices are not', json_encode($s1));
is_($s1['number'] === '000181' && $s1['date'] === '6 Oct 2026', 'the quotation\'s number and its date, read in Kampala', $s1['number'] . ' ' . $s1['date']);
$s2 = QuotationPrefill::suggest($quote(2, 15, '2026-10-06T10:00:00+0300', [$line(KIT, 2249000, 'Pc'), $line(INST, 150000, 'Time'),
    $line('Transport to Mbarara', 180000, 'Time')]), $kla);
is_($s2['transport'] === '180000' && $s2['transport_unpriced'] === false, 'a priced transport line fills the transport charge', json_encode($s2));
is_($s2['service'] === '', 'no plan line: no service suggested (the form then keeps its own)', $s2['service']);
$s3 = QuotationPrefill::suggest($quote(3, 15, '2026-10-06T10:00:00+0300', [$line('Starlink Standard Kit', 2900000, 'Pc', 2), $line('Starlink Router Mini', 400000, 'Pc'),
    $line(INST, 150000, 'Time'), $line('Pole mounting', 50000, 'Time'), $line('Extra cable 30 m', 40000, 'Time'), $line('Loyalty discount', -20000, 'Time')]), $kla);
is_($s3['equipment'] === 'Starlink Standard Kit x2, Starlink Router Mini', 'two kits are "x2"; every piece is equipment', $s3['equipment']);
is_($s3['other'] === '90000' && $s3['other_label'] === 'Pole mounting, Extra cable 30 m',
    'other one-time lines are the other agreed charge, their labels what it is for; a discount is never a charge', json_encode($s3));
is_($s3['transport'] === null && $s3['transport_unpriced'] === false, 'no transport line at all: nothing filled, nothing asked', json_encode($s3));
$s4 = QuotationPrefill::suggest($quote(4, 15, '2026-10-06T10:00:00+0300', [$line(KIT, 2249000, 'Pc'), $line('Installation (free this month)', 0, 'Time'),
    $line('Delivery', 25000.5, 'Time')]), $kla);
is_($s4['installation'] === '0' && $s4['transport'] === '25000.50', 'a free installation is 0, not nothing; an amount with cents keeps them', json_encode($s4));
$s5 = QuotationPrefill::suggest($quote(5, 15, '2026-10-06T10:00:00+0300', [$line("Starlink\nStandard  Kit", 1, 'Pc'),
    $line(str_repeat('Very long extra work description ', 6), 10000, 'Time')]), $kla);
is_($s5['equipment'] === 'Starlink Standard Kit' && mb_strlen($s5['other_label']) <= 80,
    'a label\'s line breaks and spaces folded; what it is for cut to the form\'s 80 characters', json_encode($s5));
$s6 = QuotationPrefill::suggest($quote(6, 15, '2026-10-06T10:00:00+0300', [['label' => KIT, 'price' => 2249000, 'quantity' => 1],
    ['label' => PLAN, 'price' => 249000, 'quantity' => 1], ['label' => INST, 'price' => 150000, 'quantity' => 1]]), $kla);
is_($s6['service'] === PLAN && $s6['equipment'] === KIT && $s6['installation'] === '150000',
    'with no unit and no total: the plan is known by its speed, the amount is price × quantity', json_encode($s6));

// ── 2 ──────────────────────────────────────────────────────────────────
echo "\n2. Which quotation\n";
$list = [$q181(181, 15, '2026-10-06T10:15:00+0300'), $q181(170, 15, '2026-10-01T09:00:00+0300'), $q181(190, 16, '2026-10-06T12:00:00+0300'),
         $q181(191, 15, '2026-10-06T13:00:00+0300'), $q181(192, 15, '2026-10-06T14:00:00+0300'),
         ['id' => 193, 'clientId' => 15, 'status' => 1, 'createdDate' => '2026-10-06T15:00:00+0300', 'items' => []]];
$list[3]['status'] = 3; $list[4]['status'] = 4;   // 191 rejected, 192 void — both newer than 181
$p = QuotationPrefill::pick($list, 15);
is_((int)($p['id'] ?? 0) === 181, 'the latest of this client\'s quotations — not another client\'s newer one, not a rejected or void one, not one without lines',
    'picked ' . ($p['id'] ?? 'none'));
is_((int)(QuotationPrefill::pick($list, 16)['id'] ?? 0) === 190, 'each client its own');
is_(QuotationPrefill::pick($list, 17) === null && QuotationPrefill::pick(null, 15) === null && QuotationPrefill::pick($list, 0) === null,
    'a client with no quotation, an answer that is not a list, no client: none');
$same = [$q181(200, 15, '2026-10-06T10:15:00+0300'), $q181(201, 15, '2026-10-06T10:15:00+0300')];
is_((int)(QuotationPrefill::pick($same, 15)['id'] ?? 0) === 201, 'two made in the same second: the later one, by id');

// ── 3 ──────────────────────────────────────────────────────────────────
echo "\n3. Through the real plugin: what the request form starts from\n";
$users = [
    '1099' => ['id' => 1099, 'username' => 'sb-tech', 'firstName' => 'Sandbox', 'lastName' => 'Tech', 'email' => 'tech@example.test', 'isActive' => true],
];
$clients = [
    '15' => ['id' => 15, 'firstName' => 'Canary', 'lastName' => 'Customer', 'street1' => 'Plot 9 Canary Road', 'city' => 'Kampala', 'note' => '', 'isLead' => false,
             'contacts' => [['phone' => '+256700000915', 'email' => '', 'isBilling' => true]]],
    '16' => ['id' => 16, 'firstName' => 'Other', 'lastName' => 'Client', 'street1' => 'Plot 16 Canary Road', 'city' => 'Kampala', 'note' => '', 'isLead' => false,
             'contacts' => [['phone' => '+256700000916', 'email' => '', 'isBilling' => true]]],
    '17' => ['id' => 17, 'firstName' => 'No', 'lastName' => 'Quotation', 'street1' => 'Plot 17 Canary Road', 'city' => 'Kampala', 'note' => '', 'isLead' => false,
             'contacts' => [['phone' => '+256700000917', 'email' => '', 'isBilling' => true]]],
];
$job = function (int $id, int $client): array {
    return ['id' => $id, 'title' => 'Starlink Installation — Canary Customer', 'description' => '', 'clientId' => $client, 'client' => ['id' => $client],
            'date' => '2026-10-07T18:30:00+0300', 'duration' => 60, 'status' => 0, 'address' => 'Plot 9 Canary Road, Mbarara', 'gpsLat' => null, 'gpsLon' => null,
            'assignedUserId' => 1099];
};
$s = SjSandbox::start($root, ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala', 'install_auth_enabled' => '1', 'install_auth_whatsapp' => '1',
                              'job_photos_required' => '0'], 'iqp');
file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
$quotes = [];
foreach ($list as $q) $quotes[(string)$q['id']] = $q;
$s->seedCrm(['users' => $users, 'clients' => $clients, 'jobs' => ['901' => $job(901, 15), '902' => $job(902, 16), '903' => $job(903, 17)],
             'tasks' => [], 'quotes' => $quotes]);
$s->staff('tech', ['name' => 'Sandbox Tech', 'email' => 'tech@example.test', 'role' => 'support', 'phone' => '+256700000111',
                   'ucrm_user_id' => 1099, 'ucrm_link' => ['user_id' => 1099, 'email' => 'tech@example.test', 'verified_at' => '2026-10-01T00:00:00Z', 'verified_by' => 1]]);
$n0 = count($s->texts());
$r = $s->api('tech', 'GET', 'install_auth_prefill', null, '&job_id=901');
$d = (array)($r[2]['data'] ?? []); $sg = (array)($d['suggest'] ?? []);
is_($r[0] === 200 && ($d['quote_read'] ?? '') === 'found' && ($d['quote']['number'] ?? '') === '000181' && ($d['quote']['date'] ?? '') === '6 Oct 2026',
    'the form names the quotation that filled it: the customer\'s latest, 000181 of 6 Oct 2026', $r[0] . ' ' . substr($r[1], 0, 400));
is_(($sg['equipment'] ?? '') === KIT && ($sg['service'] ?? '') === PLAN && ($sg['installation'] ?? null) === '150000',
    'the kit, the plan and the installation charge come from it', json_encode($sg));
is_($isNull($sg, 'transport') && ($d['quote']['transport_unpriced'] ?? null) === true,
    'transport: listed without a price, so asked for — never filled with 0', json_encode($d['quote'] ?? null));
is_($isNull($sg, 'other') && ($sg['other_label'] ?? 'x') === '', 'no other charge');
$reqs = $s->crmReqs('GET', '#^/billing/quotes$#');
is_(count($reqs) === 1 && strpos((string)($reqs[0]['query'] ?? ''), 'clientId=15') !== false, 'one read of uCRM\'s quotations, asked for this client',
    json_encode($reqs));
is_(count($s->texts()) === $n0 && $s->q('SELECT 1 FROM install_auth') === [], 'the prefill sends nothing and stores nothing');
$r = $s->api('tech', 'GET', 'install_auth_prefill', null, '&job_id=902');
is_(($r[2]['data']['quote']['number'] ?? '') === '000190', 'another client\'s job: that client\'s quotation', substr($r[1], 0, 300));
$r = $s->api('tech', 'GET', 'install_auth_prefill', null, '&job_id=903');
$d = (array)($r[2]['data'] ?? []); $sg = (array)($d['suggest'] ?? []);
is_($r[0] === 200 && $isNull($d, 'quote') && ($d['quote_read'] ?? '') === 'none' && $isNull($sg, 'installation')
    && ($sg['service'] ?? '') === 'Starlink internet service' && ($sg['equipment'] ?? 'x') === '',
    'a client with no quotation: no quotation named, no charge filled, the form as before', substr($r[1], 0, 400));
$s->quotesDown(true);
$r = $s->api('tech', 'GET', 'install_auth_prefill', null, '&job_id=901');
$d = (array)($r[2]['data'] ?? []); $sg = (array)($d['suggest'] ?? []);
is_($r[0] === 200 && $isNull($d, 'quote') && ($d['quote_read'] ?? '') === 'unreadable' && $isNull($sg, 'installation')
    && ($sg['service'] ?? '') === 'Starlink internet service',
    'uCRM does not answer for quotations: the form opens as before, and says the quotations could not be read', substr($r[1], 0, 400));
$s->quotesDown(false);

// ── 4 ──────────────────────────────────────────────────────────────────
echo "\n4. The request made with those values\n";
$r = $s->api('tech', 'GET', 'install_auth_prefill', null, '&job_id=901');
$sg = (array)($r[2]['data']['suggest'] ?? []);
$r = $s->api('tech', 'POST', 'install_auth_request', ['job_id' => 901, 'service' => $sg['service'] ?? '', 'equipment' => $sg['equipment'] ?? '',
    'location' => $sg['location'] ?? '', 'installation' => $sg['installation'] ?? '0', 'transport' => '180000', 'other' => '0', 'other_label' => '']);
is_($r[0] === 201, 'the request is made with the quotation\'s values and the transport the sender typed: 201', $r[0] . ' ' . substr($r[1], 0, 300));
$t = array_values(array_filter($s->texts(), function ($x) { return $x['number'] === CUST; }));
$last = (string)($t[count($t) - 1]['text'] ?? '');
is_(strpos($last, 'Equipment: ' . KIT) !== false && strpos($last, 'Service: ' . PLAN) !== false && strpos($last, 'Installation Charges: UGX 150,000') !== false
    && strpos($last, 'Transport Charges: UGX 180,000') !== false && strpos($last, 'Total: UGX 330,000') !== false,
    'the customer\'s WhatsApp carries them: the kit, the plan, UGX 150,000 + 180,000 = 330,000', $last);

// ── 5 ──────────────────────────────────────────────────────────────────
echo "\n5. The form on the job page\n";
$s->login('tech', 'tech@example.test', 'sj-password-1');
$pg = $s->page('tech', 'page=dashboard&tab=scheduling&job=902');
is_(strpos($pg, 'Filled from quotation <b>') !== false && strpos($pg, "the customer\\'s latest in uCRM. Check every value: the customer accepts exactly what you send.") !== false,
    'the form says which quotation filled it, and that the customer accepts exactly what is sent');
is_(substr_count($pg, "value=\"'+iaEsc(amt(s.") === 3 && strpos($pg, "value=\"'+iaEsc(s.other_label||'')+'\"") !== false,
    'the installation, transport and other charges, and what the other is for, are filled from the suggestions');
is_(strpos($pg, 'The quotation lists transport without a price. Enter the agreed transport charge, or 0 if there is none.') !== false
    && strpos($pg, "if(window._iaTransportAsk && v('iaTransport')===''){") !== false,
    'transport the quotation does not price is asked for, and an empty one is refused before anything is sent');
is_(strpos($pg, "The customer\\'s quotations could not be read from uCRM: type the charges.") !== false, 'a uCRM that did not answer is said, not hidden');
$s->stop();

// ── 6 ──────────────────────────────────────────────────────────────────
if ($withMutants) {
    echo "\n6. Weakened copies of the code must each fail this test\n";
    $MUTANTS = [
        ['another client\'s quotation', 'lib/QuotationPrefill.php', "            if ((int)(\$q['clientId'] ?? 0) !== \$clientId) continue;", ''],
        ['a rejected or void quotation used', 'lib/QuotationPrefill.php', "            if (in_array((int)(\$q['status'] ?? 0), self::SKIP_STATUSES, true)) continue;", ''],
        ['the oldest quotation', 'lib/QuotationPrefill.php', "        if (\$ta !== \$tb) return \$ta > \$tb;", "        if (\$ta !== \$tb) return \$ta < \$tb;"],
        ['transport at 0 filled as 0', 'lib/QuotationPrefill.php', "        \$transportPriced = \$transportListed && (\$transport ?? 0.0) > 0;", "        \$transportPriced = \$transportListed;"],
        ['the installation line not found', 'lib/QuotationPrefill.php', "            if (preg_match('/install/i', \$label)) {", "            if (preg_match('/installation charge/i', \$label)) {"],
        ['the plan taken for equipment', 'lib/QuotationPrefill.php', "                if (\$service === '') \$service = \$label;\n                continue;", "                \$equipment[] = \$label;\n                continue;"],
        ['a discount counted as a charge', 'lib/QuotationPrefill.php', "                if (\$amount > 0) { \$other += \$amount; \$otherLabels[] = \$label; }", "                if (\$amount != 0) { \$other += \$amount; \$otherLabels[] = \$label; }"],
        ['the quotation\'s kit and plan not used', 'includes/api/api_install_auth.php', "        if (\$iaQuote !== null && \$iaQuote['service'] !== '')   \$service   = \$iaQuote['service'];", ''],
        ['the charges not passed to the form', 'includes/api/api_install_auth.php', "'installation' => \$iaQuote['installation'] ?? null,", "'installation' => null,"],
        ['the form not filled', 'tabs/support/scheduling.php', "placeholder=\"0\" value=\"'+iaEsc(amt(s.installation))+'\"", "placeholder=\"0\""],
        ['an empty transport sent as none', 'tabs/support/scheduling.php', "  if(window._iaTransportAsk && v('iaTransport')===''){", "  if(false){"],
    ];
    foreach ($MUTANTS as [$label, $rel, $old, $new]) {
        [$tmp, $n] = sj_weakened_copy($root, $rel, $old, $new);
        if ($n !== 1) { is_(false, "weakened copy \"{$label}\": its anchor occurs once", "{$rel}: found {$n} times"); exec('rm -rf ' . escapeshellarg($tmp)); continue; }
        $out = []; $rc = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tmp) . ' --no-mutants 2>&1', $out, $rc);
        exec('rm -rf ' . escapeshellarg($tmp));
        $fails = count(array_filter($out, function ($l) { return strpos($l, '  FAIL ') === 0; }));
        is_($rc !== 0 && $fails > 0, "weakened copy \"{$label}\" is caught ({$fails} failing)");
    }
}

echo "\n" . ($fail === 0 ? 'ALL PASS' : 'FAILURES') . ": {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);

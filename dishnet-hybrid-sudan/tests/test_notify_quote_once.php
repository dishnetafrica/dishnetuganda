<?php
declare(strict_types=1);
/**
 * test_notify_quote_once.php — 5.18.54, docs/46 row 17 (D2c) and N-9: one WhatsApp per quote.
 *
 * Through the real plugin under php -S (tests/fixtures/staff_jobs_sandbox.php): uCRM's quote.add as uCRM sends it,
 * the manual-quote form as a signed-in account submits it, the real cron_quote_wa.php, a fake uCRM that makes, lists
 * and prints quotes, and the fake Evolution.
 *
 *   1. N-9: a quote made in uCRM. quote.add sends its quotation; the quote cron's second flow, five minutes later,
 *      used to send it again — the list it checked never reads back (row 35). Now both take the same claim.
 *   2. D2c: a quote made on the quote screen. The screen sends its WhatsApp; uCRM's quote.add for it used to send a
 *      second. Now the screen claims the quote first and the webhook stands down; the cron skips it too.
 *   3. The race the other way: when the webhook's claim is first, the screen sends nothing more and records who sent it.
 *   4. South Sudan: 5.18.53 unchanged — both duplicates still happen there (docs/46 §E).
 *   5. Weakened copies, each caught (skipped with --no-mutants).
 *
 * What this proves is what the plugin hands to WhatsApp, never delivery to a phone. Every person, number and document
 * is fictitious; nothing leaves the machine.
 */
$root = dirname(__DIR__);
$withMutants = !in_array('--no-mutants', $argv, true);
require_once __DIR__ . '/fixtures/staff_jobs_scenario.php';

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; }
    else    { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       {$d}" : '') . "\n"; }
}

const C15 = '256700000915';   // SjScenario's customer

/** A sandbox with SjScenario's customer 15 and one quote made in uCRM for them, Q-3401. */
$start = function (string $tree, string $tenant, string $tag): SjSandbox {
    $base = $tenant === 'uganda' ? ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'] : ['timezone' => 'Africa/Juba'];
    $s = SjSandbox::start($tree, $base, $tag);
    file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
    $crm = SjScenario::crm();
    $crm['quotes'] = ['3401' => ['id' => 3401, 'number' => 'Q-3401', 'clientId' => 15, 'status' => 1, 'createdDate' => date('c'),
        'items' => [['label' => 'Starlink Standard Kit', 'quantity' => 1, 'price' => 2500000.0, 'total' => 2500000.0]],
        'total' => 2500000.0]];
    $s->seedCrm($crm);
    SjScenario::staff($s);
    return $s;
};

/** Quotation texts to the customer naming $number, since text $from. */
$texts = function (SjSandbox $s, string $number, int $from = 0): int {
    return count(array_filter(array_slice($s->texts(), $from), function ($t) use ($number) {
        return $t['number'] === C15 && strpos((string)$t['text'], $number) !== false;
    }));
};
/** php -S serves one request at a time: this returns once the webhook has finished what it does after answering. */
$drain = function (SjSandbox $s): void { $s->http('GET', "{$s->base}?page=login"); };
$ledger = function (SjSandbox $s, int $id): string {
    try { return implode(',', array_column($s->q('SELECT source FROM wa_sent_quotes WHERE quote_id = ?', [$id]), 'source')); }
    catch (\Throwable $e) { return strpos($e->getMessage(), 'no such table') !== false ? '' : 'error: ' . $e->getMessage(); }
};
/** The manual-quote form, as a signed-in account submits it: a uCRM quote for customer 15, and its WhatsApp. */
$screen = function (SjSandbox $s): array {
    $s->login('admin', 'admin@example.test', 'sj-password-1');
    return $s->form('admin', ['action' => 'send_manual_quote', 'mq_crm_client_id' => '15', 'mq_customer_name' => 'Sandbox Customer',
        'mq_customer_phone' => '+256700000915', 'mq_create_crm' => '1', 'mq_note' => '',
        'mq_items_json' => json_encode([['label' => 'Starlink Standard Kit', 'quantity' => 1, 'price' => 2500000, 'unit' => 'amount']])]);
};

// ── The cases, as functions of the sandbox, so a weakened copy runs the very same ones ──────────────────────────────

/** N-9: a quote made in uCRM — its quote.add, then the quote cron. */
$ucrmCase = function (SjSandbox $s) use ($texts, $drain, $ledger): array {
    $o = [];
    $n = count($s->texts());
    $o['fire'] = $s->fire('quote.add', 'quote', 3401, 'q-3401');
    $drain($s);
    $o['webhook'] = $texts($s, 'Q-3401', $n);
    [$o['cron_rc'], $o['cron_out']] = $s->run('cron_quote_wa.php');
    $o['cron_log'] = (string)@file_get_contents($s->data . '/quote_wa_log.json');   // the cron logs here, not to stdout
    $o['total'] = $texts($s, 'Q-3401', $n);
    $o['ledger'] = $ledger($s, 3401);
    return $o;
};

/** D2c: the quote screen makes Q-3500 in uCRM and sends it; then uCRM's quote.add for it; then the quote cron. */
$screenCase = function (SjSandbox $s) use ($texts, $drain, $ledger, $screen): array {
    $o = [];
    $s->seedCrm(['next_quote' => 3500]);   // the id the fake gives the screen's quote, whatever ran before
    $n = count($s->texts());
    $o['form'] = $screen($s);
    $o['screen'] = $texts($s, 'Q-3500', $n);
    $s->fire('quote.add', 'quote', 3500, 'q-3500');
    $drain($s);
    $o['after_event'] = $texts($s, 'Q-3500', $n);
    [$o['cron_rc']] = $s->run('cron_quote_wa.php');
    $o['total'] = $texts($s, 'Q-3500', $n);
    $o['ledger'] = $ledger($s, 3500);
    return $o;
};

/** The race the other way: uCRM's quote.add claimed Q-3501 first; the screen then sends nothing more. */
$raceCase = function (SjSandbox $s) use ($texts, $ledger, $screen): array {
    $o = [];
    $s->q('CREATE TABLE IF NOT EXISTS wa_sent_quotes (quote_id INTEGER NOT NULL, quote_ref TEXT NOT NULL DEFAULT \'\', source TEXT NOT NULL DEFAULT \'\', sent_at TEXT NOT NULL DEFAULT (datetime(\'now\')), PRIMARY KEY (quote_id))');
    $s->q("INSERT INTO wa_sent_quotes (quote_id, quote_ref, source) VALUES (3501, 'Q-3501', 'webhook')");
    $s->seedCrm(['next_quote' => 3501]);   // the screen's quote is the one the webhook claimed, whatever ran before
    $n = count($s->texts());
    $o['form'] = $screen($s);
    $o['screen'] = $texts($s, 'Q-3501', $n);
    $log = array_values(array_filter((array)($s->store()->load('quotes_log.json') ?? []), function ($q) {
        return (int)($q['crm_quote_id'] ?? 0) === 3501;
    }));
    $o['log'] = $log[0] ?? [];
    $o['ledger'] = $ledger($s, 3501);
    return $o;
};

// ══════════════════════════════════════════════════════════════════════════════
echo "\n1. Uganda — a quote made in uCRM (N-9): quote.add sends it, the quote cron does not send it again\n";
$u = $start($root, 'uganda', 'nqo');
$a = $ucrmCase($u);
is_((int)$a['fire'][0] === 200 && $a['webhook'] === 1, 'quote.add sends the quotation', json_encode([$a['fire'][0], $a['webhook']]));
is_($a['cron_rc'] === 0 && $a['total'] === 1, 'the quote cron runs and sends nothing more: one WhatsApp for the quote',
    json_encode([$a['cron_rc'], $a['total'], mb_substr($a['cron_out'], -400)], JSON_INVALID_UTF8_SUBSTITUTE));
is_($a['ledger'] === 'webhook', 'the claim is the webhook\'s', $a['ledger']);
is_(strpos($a['cron_log'], 'DEDUP BLOCK Flow B: quote #3401') !== false, 'and the cron says it found the claim (control: it did look at the quote)',
    mb_substr($a['cron_log'], 0, 600));

echo "\n2. Uganda — a quote made on the quote screen (D2c): one WhatsApp, the screen's\n";
$b = $screenCase($u);
is_((int)$b['form'][0] === 302 || (int)$b['form'][0] === 200, 'the form is accepted', (string)$b['form'][0]);
is_($b['screen'] === 1, 'the screen sends its quotation', (string)$b['screen']);
is_($b['after_event'] === 1 && $b['total'] === 1, 'uCRM\'s quote.add for it, then the quote cron: nothing more', json_encode([$b['after_event'], $b['total']]));
is_($b['ledger'] === 'plugin_manual', 'the claim is the screen\'s', $b['ledger']);
is_(strpos($u->http('GET', "{$u->base}?page=login")[1] . (string)@file_get_contents($u->data . '/webhook_log.json'),
    'Quote #Q-3500 already sent — by the quote screen that made it, or by cron_quote_wa — not sending it again') !== false,
    'and the webhook log says why it stood down');

echo "\n3. Uganda — the webhook claimed first: the screen sends nothing more, and says who sent it\n";
$c = $raceCase($u);
is_($c['screen'] === 0, 'no WhatsApp from the screen', (string)$c['screen']);
is_(!empty($c['log']['sent_via_wa']) && ($c['log']['wa_sent_by'] ?? '') === 'quote.add',
    'its quote log reads "sent", by uCRM\'s quote.add — staff are not told to send it again', json_encode($c['log']));
is_($c['ledger'] === 'webhook', 'and the claim stays the webhook\'s (control: the claim was there)', $c['ledger']);
$u->stop();

// ══════════════════════════════════════════════════════════════════════════════
echo "\n4. South Sudan: 5.18.53 unchanged — both duplicates still happen there (docs/46 §E)\n";
$ss = $start($root, 'south-sudan', 'nqo-ss');
$sa = $ucrmCase($ss);
is_($sa['webhook'] === 1 && $sa['total'] === 2, 'a quote made in uCRM: quote.add, then the cron again — two WhatsApps, as before (N-9)',
    json_encode([$sa['webhook'], $sa['total']]));
is_($sa['ledger'] === 'flow_b_ucrm', 'only the cron claims there', $sa['ledger']);
$sb = $screenCase($ss);
is_($sb['screen'] === 1 && $sb['after_event'] === 2 && $sb['total'] === 2, 'a quote made on the screen: the screen, then quote.add — two, as before (D2c)',
    json_encode([$sb['screen'], $sb['after_event'], $sb['total']]));
is_($sb['ledger'] === '', 'and nothing claims it there', $sb['ledger']);
$ss->stop();

// ══════════════════════════════════════════════════════════════════════════════
echo "\n5. Weakened copies, each caught\n";
// Each predicate names the defect itself, with a control that the case really ran, so a copy that merely crashed is
// never counted as caught.
$mutants = [
    'the webhook takes no claim (N-9 back)' => ['webhook.php',
        ['            } elseif ($_quoteOnce) {', '            } elseif (false) {'],
        'ucrm', function (array $x) { return $x['webhook'] === 1 && $x['total'] === 2; }, 'the cron sent the uCRM quote again'],
    'the screen takes no claim (D2c back)' => ['lib/QuotationService.php',
        ["            if (!\\NotifyGate::applies(\\NotifyGate::QUOTE_ONCE, \$this->config, \$this->dataDir)) return true;",
         "            return true;"],
        'screen', function (array $x) { return $x['screen'] === 1 && $x['after_event'] === 2; }, 'quote.add sent the screen\'s quote again'],
    'the screen sends although the webhook claimed it' => ['lib/QuotationService.php',
        ["        \$waSent = \$waByEvent ? true : \$this->sendWA(\$phone, \$msg, 'quote_manual');",
         "        \$waSent = \$this->sendWA(\$phone, \$msg, 'quote_manual');"],
        'race', function (array $x) { return $x['screen'] === 1 && $x['ledger'] === 'webhook' && !empty($x['log']); }, 'a second quotation after the webhook\'s'],
];
$cases = ['ucrm' => $ucrmCase, 'screen' => $screenCase, 'race' => $raceCase];
foreach ($withMutants ? $mutants : [] as $name => [$rel, [$old, $new], $case, $caught, $why]) {
    [$tree, $n] = sj_weakened_copy($root, $rel, $old, $new);
    if ($n !== 1) { is_(false, "caught: {$name}", "the anchor was not found exactly once in {$rel}"); exec('rm -rf ' . escapeshellarg($tree)); continue; }
    $wk = $start($tree, 'uganda', 'nqo-wk');
    $x = $cases[$case]($wk);
    $ok = (bool)$caught($x);
    is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''), (string)json_encode(array_diff_key($x, ['cron_out' => 1, 'cron_log' => 1]), JSON_INVALID_UTF8_SUBSTITUTE));
    $wk->stop();
    exec('rm -rf ' . escapeshellarg($tree));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

<?php
declare(strict_types=1);
/**
 * test_notify_tenant_text.php — 5.18.54, docs/46 rows 21, 22 and 39 (C8, C4, new N-10): no South Sudan content in
 * Uganda's messages and screens.
 *
 * Through the real plugin under php -S (tests/fixtures/staff_jobs_sandbox.php), a fake uCRM and the fake Evolution; the
 * ladder texts and the app pushes through fixtures/notify_text_probe.php, inside the same sandbox.
 *
 *   1. The quote resend (staff action wa_send_quote_pdf), with no PDF and with one: the total in the tenant's currency,
 *      the tenant's number, and the administrator's copy in the same currency — never "$" or +211 (C8)
 *   2. The app pushes for an invoice and a payment: "UGX …", not "$… USD" (C8)
 *   3. The ladder's WhatsApp, every stage: signed with this install's accounts number; a configured one wins (C8)
 *   4. The settings screen: no "RECOMMENDED" for uCRM's mailer; the path this install uses is marked IN USE (C4)
 *   5. The ladder template screen (N-10): an unset field shows Uganda's number and address; pressing Save stores them,
 *      not Juba's; the preview agrees
 *   6. South Sudan: all of it as in 5.18.53
 *   7. Weakened copies, each caught (skipped with --no-mutants)
 *
 * What this proves is what the plugin hands to WhatsApp, to the push sender and to the browser — never delivery.
 * Every person, number and document is fictitious; nothing leaves the machine.
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

const UG_NUMBER = '+256 705 993 348';     // profiles/uganda.json: contacts and dunning
const ADMIN_WA  = '256700000100';

$start = function (string $tree, string $tenant, string $tag, array $extra = []): SjSandbox {
    $base = $tenant === 'uganda' ? ['tenant_profile' => 'uganda', 'timezone' => 'Africa/Kampala'] : ['timezone' => 'Africa/Juba'];
    $s = SjSandbox::start($tree, $base + ['whatsapp_admin_phone' => ADMIN_WA] + $extra, $tag);
    file_put_contents($s->plug . '/ucrm.json', json_encode(['pluginDataDir' => $s->data, 'pluginPublicUrl' => $s->base]));
    $s->seedCrm(SjScenario::crm());
    SjScenario::staff($s);
    $s->login('admin', 'admin@example.test', 'sj-password-1');   // the screens are read as a signed-in administrator
    return $s;
};
/** "$" written against a figure: the dollar amounts of South Sudan's copy. */
function dollar_amount(string $t): bool { return preg_match('/\$\s?\d/', $t) === 1; }

// ── The cases, as functions of the sandbox, so a weakened copy runs the very same ones ──────────────────────────────

/** The staff action that resends a quote: once with no PDF in uCRM (a text), once with one (a document). */
$resend = function (SjSandbox $s): array {
    $s->store()->save('kyc_applications.json', [['id' => 91, 'firstname' => 'Test', 'lastname' => 'Resend',
        'mobile' => '+256700000991', 'total_amount' => 1600000, 'quote_ref' => 'Q-3601']]);
    $s->seedCrm(['quotes' => ['3602' => ['id' => 3602, 'number' => 'Q-3602', 'clientId' => 15, 'total' => 2500000.0]]]);
    $n = count($s->texts());
    $state0 = $s->http('GET', "{$s->evo}/__test/state")[2] ?? [];
    $m = count((array)($state0['media_calls'] ?? []));
    $o = [];
    $o['text_r'] = $s->api('admin', 'POST', 'wa_send_quote_pdf', ['application_id' => 91, 'crm_quote_id' => 3601, 'cc_admin' => true]);
    $o['doc_r']  = $s->api('admin', 'POST', 'wa_send_quote_pdf', ['crm_quote_id' => 3602, 'phone' => '256700000992', 'cc_admin' => true]);
    $texts = array_slice($s->texts(), $n);
    $media = array_slice((array)(($s->http('GET', "{$s->evo}/__test/state")[2] ?? [])['media_calls'] ?? []), $m);
    $o['to_customer'] = implode("\n", array_column(array_filter($texts, function ($t) { return $t['number'] === '256700000991'; }), 'text'));
    $o['to_admin']    = implode("\n", array_column(array_filter($texts, function ($t) { return $t['number'] === ADMIN_WA; }), 'text'));
    $o['caption']       = implode("\n", array_column(array_filter($media, function ($c) { return $c['number'] === '256700000992'; }), 'caption'));
    $o['admin_caption'] = implode("\n", array_column(array_filter($media, function ($c) { return $c['number'] === ADMIN_WA; }), 'caption'));
    return $o;
};
/** The ladder's WhatsApp for every stage, and the two app pushes, from inside the sandbox. */
$probe = function (SjSandbox $s): array {
    [$rc, $out] = $s->run(__DIR__ . '/fixtures/notify_text_probe.php', [$s->plug]);
    $j = json_decode((string)substr($out, (int)strpos($out, '{')), true);
    return is_array($j) ? $j + ['rc' => $rc] : ['rc' => $rc, 'raw' => substr($out, 0, 400), 'ladder' => [], 'push' => []];
};
/** The two mail-path boxes of the settings screen: the text of each <label>, badge included. */
$mailBadges = function (SjSandbox $s): array {
    $html = $s->page('admin', 'page=dashboard&tab=settings&stab=system');   // the e-mail card is on the System view
    $cut = function (string $from) use ($html): string {
        $i = strpos($html, $from);
        return $i === false ? '' : substr($html, $i, (int)strpos($html, '</label>', $i) - $i);
    };
    return ['ucrm' => $cut('📨 USE UCRM MAILER'), 'smtp' => $cut('📡 SMTP SETTINGS'), 'len' => strlen($html)];
};
/** The ladder template screen's four global fields, as shown. */
$globals = function (SjSandbox $s): array {
    $html = $s->page('admin', 'page=dashboard&tab=overdue_email_tpl');
    $o = [];
    foreach (['global_from', 'global_reply', 'global_phone', 'global_email'] as $f) {
        $o[$f] = preg_match('/name="' . $f . '" value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }
    return $o;
};
/** Save the global fields exactly as the screen shows them — an administrator pressing Save without typing. */
$saveGlobals = function (SjSandbox $s, array $shown): array {
    $s->form('admin', ['tpl_action' => 'save', 'save_stage' => 'global'] + $shown,
        'page=dashboard&tab=overdue_email_tpl', 'page=dashboard&tab=overdue_email_tpl');
    $cfg = $s->store()->load('kyc_config.json') ?? [];
    return array_intersect_key($cfg, array_flip(['overdue_email_from_name', 'overdue_email_reply_to', 'overdue_email_phone',
                                                 'overdue_email_accounts_email']));
};
$preview = function (SjSandbox $s): string {
    $r = $s->api('admin', 'POST', 'overdue_email_preview', ['stage' => 1, 'subject' => 'S', 'para1' => 'P1', 'para2' => 'P2',
        'cta' => 'Pay', 'footer' => 'Call us on {{accounts_phone}}.']);
    return (string)($r[2]['data']['html'] ?? $r[2]['html'] ?? $r[1]);
};

// ══════════════════════════════════════════════════════════════════════════════
$ug = $start($root, 'uganda', 'tt-ug');

echo "\n1. Uganda — the quote resend: the tenant's currency and number (C8)\n";
$r = $resend($ug);
is_(($r['text_r'][0] ?? 0) === 200 && ($r['doc_r'][0] ?? 0) === 200, 'both resends answered', json_encode([$r['text_r'][1] ?? '', $r['doc_r'][1] ?? '']));
is_(strpos($r['to_customer'], 'Total: UGX 1,600,000.00') !== false && strpos($r['to_customer'], UG_NUMBER) !== false,
    'no PDF: the text gives the total in UGX and the Uganda number', $r['to_customer']);
is_(!dollar_amount($r['to_customer']) && strpos($r['to_customer'], '+211') === false, 'no "$" amount, no +211', $r['to_customer']);
is_(strpos($r['caption'], 'Quote #Q-3602 — UGX 2,500,000.00') !== false && !dollar_amount($r['caption']),
    'with the PDF: its caption gives the total in UGX', $r['caption']);
is_(strpos($r['to_admin'], 'Amount: UGX 1,600,000.00') !== false && strpos($r['admin_caption'], 'Amount: UGX 2,500,000.00') !== false
    && !dollar_amount($r['to_admin'] . $r['admin_caption']), 'the administrator\'s copies: the same currency', $r['to_admin'] . ' | ' . $r['admin_caption']);

echo "\n2. Uganda — the app pushes (C8)\n";
$p = $probe($ug);
is_(($p['push']['invoice_created'] ?? '') === 'Invoice INV-9001 for UGX 1,600,000.00 has been created. Tap to view.',
    'a new invoice: "UGX 1,600,000.00"', json_encode($p['push'] ?? $p));
is_(($p['push']['payment_received'] ?? '') === 'Your payment of UGX 250,000.00 has been received. Thank you!',
    'a payment: "UGX 250,000.00", no "USD"', json_encode($p['push'] ?? []));

echo "\n3. Uganda — the ladder's WhatsApp, every stage (C8)\n";
$lad = (array)($p['ladder'] ?? []);
$signed = array_filter($lad, function ($t) { return substr(rtrim((string)$t), -strlen('— DishNet Accounts · ' . UG_NUMBER)) === '— DishNet Accounts · ' . UG_NUMBER; });
is_(count($lad) === 9 && count($signed) === 9, 'all nine stages signed "— DishNet Accounts · ' . UG_NUMBER . '"', (string)json_encode(array_map(function ($t) { return mb_substr((string)$t, -45); }, $lad), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
is_(strpos(implode("\n", $lad), '+211') === false, 'no +211 anywhere in them');
$ug->store()->save('kyc_config.json', ($ug->store()->load('kyc_config.json') ?? []) + ['overdue_email_phone' => '+256 700 000 555']);
file_put_contents($ug->data . '/kyc_config.json', json_encode($ug->store()->load('kyc_config.json')));
$p2 = $probe($ug);
is_(substr(rtrim((string)($p2['ladder'][3] ?? '')), -strlen('· +256 700 000 555')) === '· +256 700 000 555',
    'a number set for the ladder (overdue_email_phone) is the one it signs with', mb_substr((string)($p2['ladder'][3] ?? ''), -60));

echo "\n4. Uganda — the settings screen names the path in use (C4)\n";
$b = $mailBadges($ug);
is_($b['ucrm'] !== '' && $b['smtp'] !== '', 'both mail boxes are on the page', (string)$b['len']);
is_(strpos($b['ucrm'] . $b['smtp'], 'RECOMMENDED') === false, 'the word "RECOMMENDED" is gone');
is_(strpos($b['smtp'], '>IN USE<') !== false && strpos($b['ucrm'], '>IN USE<') === false,
    'uCRM\'s mailer off (this install\'s setup): the SMTP box is marked IN USE', $b['smtp']);
file_put_contents($ug->data . '/email_settings.json', json_encode(['use_ucrm_email' => true]));
$b2 = $mailBadges($ug);
is_(strpos($b2['ucrm'], '>IN USE<') !== false && strpos($b2['smtp'], '>FALLBACK<') !== false,
    'uCRM\'s mailer on: that box is IN USE, and SMTP is its fallback', $b2['ucrm'] . ' | ' . $b2['smtp']);
@unlink($ug->data . '/email_settings.json');
$ug->stop();

echo "\n5. Uganda — the ladder template screen shows, saves and previews Uganda's details (N-10)\n";
$ug2 = $start($root, 'uganda', 'tt-ug2');
$g = $globals($ug2);
is_(($g['global_phone'] ?? '') === UG_NUMBER && ($g['global_email'] ?? '') === 'accounts@dishnetuganda.com'
    && ($g['global_reply'] ?? '') === 'accounts@dishnetuganda.com', 'unset fields show Uganda\'s number and address', json_encode($g));
$saved = $saveGlobals($ug2, $g);
is_(($saved['overdue_email_phone'] ?? '') === UG_NUMBER && ($saved['overdue_email_accounts_email'] ?? '') === 'accounts@dishnetuganda.com',
    'Save without typing stores them — not the Juba number', json_encode($saved));
$pv = $preview($ug2);
is_(strpos($pv, 'Call us on ' . UG_NUMBER) !== false && strpos($pv, '+211') === false, 'the preview prints the Uganda number, and no +211',
    mb_substr(strip_tags($pv), -300));
is_(strpos($pv, 'Kampala, Uganda') !== false && strpos($pv, 'South Sudan') === false && strpos($pv, 'dishnetafrica.com') === false,
    'its frame carries the Uganda company line and website, as the e-mail does', mb_substr(strip_tags($pv), -300));
$ug2->stop();

echo "\n6. South Sudan — every one of them as in 5.18.53\n";
$ss = $start($root, 'south-sudan', 'tt-ss');
$r = $resend($ss);
is_(strpos($r['to_customer'], 'Total: $1600000') !== false && strpos($r['to_customer'], '+211 921 443 006') !== false,
    'the resend text: "$1600000" and +211 921 443 006, as before', $r['to_customer']);
is_(strpos($r['caption'], 'Quote #Q-3602 — $2500000') !== false && strpos($r['to_admin'] . $r['admin_caption'], 'Amount: $') !== false,
    'the caption and the administrator\'s copies: "$", as before', $r['caption']);
$p = $probe($ss);
is_(($p['push']['invoice_created'] ?? '') === 'Invoice INV-9001 for $1600000 USD has been created. Tap to view.'
    && ($p['push']['payment_received'] ?? '') === 'Your payment of $250000 USD has been received. Thank you!', 'the pushes, as before', json_encode($p['push'] ?? $p));
$ssSign = '— DishNet Accounts · +211 921 443 009';
$ssSigned = array_filter((array)($p['ladder'] ?? []), function ($t) use ($ssSign) { return substr(rtrim((string)$t), -strlen($ssSign)) === $ssSign; });
is_(count($ssSigned) === 9, 'the ladder, signed +211 921 443 009 on all nine stages, as before');
$b = $mailBadges($ss);
is_(strpos($b['ucrm'], '>RECOMMENDED<') !== false && strpos($b['smtp'], '>FALLBACK<') !== false && strpos($b['ucrm'] . $b['smtp'], 'IN USE') === false,
    'the settings screen: RECOMMENDED and FALLBACK, as before');
$g = $globals($ss);
is_(($g['global_phone'] ?? '') === '+211 921 443 009' && ($g['global_email'] ?? '') === 'accounts@dishnetafrica.com',
    'the template screen: the Juba details, as before', json_encode($g));
$ss->stop();

// ══════════════════════════════════════════════════════════════════════════════
echo "\n7. Weakened copies, each caught\n";
// Each predicate names the defect itself, with a control that the path under test really ran.
$mutants = [
    'the resend in dollars on Uganda' => [['includes/api/api_whatsapp.php',
            "        \$_c8Ug  = NotifyGate::applies(NotifyGate::TENANT_TEXT, is_array(\$config ?? null) ? \$config : [], \$GLOBALS['dataDir'] ?? null);\n",
            "        \$_c8Ug  = false;\n"], 'uganda',
        function (SjSandbox $s) use ($resend) { $r = $resend($s); return $r['to_customer'] !== '' && dollar_amount($r['to_customer'] . $r['caption']); },
        'a Ugandan customer was quoted in dollars'],
    'the push in dollars on Uganda' => [['lib/FcmPush.php',
            "            if (!NotifyGate::applies(NotifyGate::TENANT_TEXT, \$config, is_string(\$dd) ? \$dd : null)) return null;\n",
            "            return null;\n"], 'uganda',
        function (SjSandbox $s) use ($probe) { $p = $probe($s); return strpos((string)($p['push']['payment_received'] ?? ''), ' USD ') !== false; },
        'a Ugandan push said USD'],
    'the ladder signed from Juba on Uganda' => [['lib/OverdueDunningHelpers.php',
            "            if (!NotifyGate::applies(NotifyGate::TENANT_TEXT, \$cfg, \$dd)) return \$literal;\n",
            "            return \$literal;\n"], 'uganda',
        function (SjSandbox $s) use ($probe) { $p = $probe($s); return count((array)($p['ladder'] ?? [])) === 9 && strpos(implode("\n", $p['ladder']), '+211 921 443 009') !== false; },
        'Ugandan customers were told to call +211'],
    'RECOMMENDED back on Uganda' => [['tabs/admin/settings.php',
            "\$_c4Ug   = NotifyGate::applies(NotifyGate::TENANT_TEXT, is_array(\$config ?? null) ? \$config : [], \$dataDir ?? null);\n",
            "\$_c4Ug   = false;\n"], 'uganda',
        function (SjSandbox $s) use ($mailBadges) { $b = $mailBadges($s); return $b['ucrm'] !== '' && strpos($b['ucrm'], 'RECOMMENDED') !== false; },
        'the screen recommended uCRM\'s mailer again'],
    'the Juba defaults back on the template screen' => [['tabs/admin/overdue_email_tpl.php',
            "if (NotifyGate::applies(NotifyGate::TENANT_TEXT, is_array(\$cfg) ? \$cfg : [], \$dataDir ?? (\$GLOBALS['dataDir'] ?? null))) {\n",
            "if (false) {\n"], 'uganda',
        function (SjSandbox $s) use ($globals, $saveGlobals) {
            $g = $globals($s); $saved = $saveGlobals($s, $g);
            return ($g['global_phone'] ?? '') === '+211 921 443 009' && ($saved['overdue_email_phone'] ?? '') === '+211 921 443 009';
        }, 'Save wrote the Juba number into Uganda\'s configuration'],
    'the Uganda texts on South Sudan' => [['lib/NotifyGate.php', "    public const EVERYWHERE = [];\n",
            "    public const EVERYWHERE = [self::TENANT_TEXT];\n"], 'south-sudan',
        function (SjSandbox $s) use ($resend) { $r = $resend($s); return $r['to_customer'] !== '' && strpos($r['to_customer'], 'Total: $1600000') === false; },
        'South Sudan\'s resend lost its "$1600000"'],
];
foreach ($withMutants ? $mutants : [] as $name => [[$rel, $o_, $n_], $tenant, $caught, $why]) {
    [$tree, $n] = sj_weakened_copy($root, $rel, $o_, $n_);
    if ($n !== 1) { is_(false, "caught: {$name}", "the anchor was not found exactly once in {$rel}"); exec('rm -rf ' . escapeshellarg($tree)); continue; }
    $s = $start($tree, $tenant, 'tt-wk' . substr(md5($name), 0, 6));
    $ok = (bool)$caught($s);
    is_($ok, "caught: {$name}" . ($ok ? " ({$why})" : ''));
    $s->stop();
    exec('rm -rf ' . escapeshellarg($tree));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

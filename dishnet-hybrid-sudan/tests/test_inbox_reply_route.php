<?php
declare(strict_types=1);
/**
 * test_inbox_reply_route.php — 5.18.86 (docs/65 §A, multi-number Batch 1 Part A): a staff reply from the WhatsApp Inbox
 * leaves on the conversation's own number on Uganda, and on no other; South Sudan keeps the 5.18.85 rule.
 *
 * Written to run UNCHANGED on the development branch and on release/5.18.86 — the Inbox fix alone, applied on live
 * 5.18.85, where the channel registry's code is present but its tables are not (no migration 087) and there is no media
 * layer. It uses only what both trees hold: the sandbox (SjSandbox), the fake Evolution server's plain sends and its
 * forced failure, and the staff API exactly as the Inbox panel calls it. It never touches the registry's tables.
 *
 *   I  Uganda: sales → the sales number, support → support, account → account, accounts → the account number, web →
 *      support; images and documents too; a failed send reported as not sent; nothing ever sent from another number
 *   C  what the Inbox reads is its settings row (public.php's $config), not the files the workers read: Evolution
 *      missing from that row → refused and said; the account number missing → refused, never sent from support
 *   O  the registry switch set in that row: the three numbers route exactly as without it
 *   S  South Sudan: the 5.18.85 line verbatim — sales and account chats answered from support
 *   X  weakened copies, each caught
 *
 * Every number, name and instance here is fictitious; nothing leaves 127.0.0.1.
 * Driver mode: php tests/test_inbox_reply_route.php --driver <plugin-root> uganda|south_sudan
 */
$self     = __FILE__;
$isDriver = in_array('--driver', $argv ?? [], true);
$di       = $isDriver ? array_search('--driver', $argv, true) : false;
$root     = $isDriver ? (string)$argv[$di + 1] : dirname(__DIR__);
$part     = $isDriver ? (string)($argv[$di + 2] ?? 'uganda') : '';
date_default_timezone_set('UTC');

require_once $root . '/tests/fixtures/staff_jobs_sandbox.php';
require_once $root . '/lib/bootstrap_data.php';
require_once $root . '/lib/StoreInterface.php';
require_once $root . '/lib/JsonStore.php';
require_once $root . '/lib/SqliteStore.php';
require_once $root . '/lib/ConversationService.php';

const IR_FLAG = 'multi_number_channels_enabled';

/** The Inbox's own view of a sandbox: conversations, replies through the staff API, what the fake Evolution was asked. */
function ir_kit(SjSandbox $s): array
{
    $cfgRow = $s->cfg;
    return [
        // The settings row the Inbox reads (public.php: $store->load('kyc_config.json')). The data file — what the webhook
        // and the workers read — is left as the sandbox wrote it.
        'row' => function (array $ov) use ($s, &$cfgRow): void {
            foreach ($ov as $k => $v) { if ($v === null) unset($cfgRow[$k]); else $cfgRow[$k] = $v; }
            $s->store()->save('kyc_config.json', $cfgRow);
        },
        'conv' => function (string $phone, string $channel) use ($s): int {
            return (int)((new ConversationService($s->data, $s->store()->getPdo()))->ensureConversation($phone, $channel)['id'] ?? 0);
        },
        'reply' => function (int $convId, string $text) use ($s): array {
            $r = $s->api('admin', 'POST', 'wa_send_reply', ['conversation_id' => $convId, 'message' => $text]);
            return ['http' => $r[0], 'status' => $r[2]['status'] ?? null, 'message' => $r[2]['message'] ?? null,
                    'channel' => $r[2]['data']['channel'] ?? null];
        },
        'texts' => function () use ($s): array { return (array)($s->http('GET', "{$s->evo}/__test/state")[2]['text_calls'] ?? []); },
        'media' => function () use ($s): array { return (array)($s->http('GET', "{$s->evo}/__test/state")[2]['media_calls'] ?? []); },
        'stored' => function (int $convId, string $body) use ($s): array {
            return $s->q('SELECT wa_message_id FROM wa_messages WHERE conversation_id = ? AND body = ?', [$convId, $body]);
        },
        'state' => function (int $convId) use ($s): ?string {
            return $s->q('SELECT state FROM wa_conversations WHERE id = ?', [$convId])[0]['state'] ?? null;
        },
        'has_conv' => function (string $phone, string $channel) use ($s): bool {
            return $s->q('SELECT id FROM wa_conversations WHERE phone = ? AND channel = ?', [$phone, $channel]) !== [];
        },
    ];
}

/** Uganda: every Inbox route, facts only. */
function ir_uganda(string $root): array
{
    $f = [];
    $s = SjSandbox::start($root, ['tenant_profile' => 'uganda'], 'ir');
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
    $k = ir_kit($s);
    $since = function (int $n) use ($k): array { return array_slice(($k['texts'])(), $n); };

    // ── I. The routes ──────────────────────────────────────────────────────────────────────────────────────
    $cases = ['sales' => '256772500101', 'support' => '256772500102', 'account' => '256772500103',
              'accounts' => '256772500104', 'web' => '256772500105'];
    $ids = [];
    foreach ($cases as $ch => $phone) {
        $ids[$ch] = ($k['conv'])($phone, $ch);
        $n0 = count(($k['texts'])());
        $body = "Inbox reply on {$ch} IR-I";
        $res = ($k['reply'])($ids[$ch], $body);
        $t = $since($n0);
        $st = ($k['stored'])($ids[$ch], $body);
        $f['i_' . $ch] = ['res' => $res, 'sent_on' => array_column($t, 'instance'), 'to' => array_column($t, 'number'),
                          'stored' => count($st), 'stored_with_id' => count(array_filter($st, function ($m) { return (string)$m['wa_message_id'] !== ''; })),
                          'state' => ($k['state'])($ids[$ch]),
                          'support_conv_created' => $ch !== 'support' && ($k['has_conv'])($phone, 'support')];
    }
    // A channel that is not one of the three numbers (a registry channel id): no number to send from — refused, never support.
    $other = ($k['conv'])('256772500106', 'sales-002');
    $n0 = count(($k['texts'])());
    $r = ($k['reply'])($other, 'IR-I other channel');
    $f['i_other'] = ['res' => $r, 'sends' => count($since($n0)), 'stored' => count(($k['stored'])($other, 'IR-I other channel'))];
    // Images and documents on the sales chat: the three media actions.
    $m0 = count(($k['media'])());
    $img = $s->api('admin', 'POST', 'wa_send_image', ['conversation_id' => $ids['sales'], 'image_url' => 'http://127.0.0.1:9/ir.jpg', 'caption' => 'IR-IMG']);
    $doc = $s->api('admin', 'POST', 'wa_send_document', ['conversation_id' => $ids['sales'], 'document_url' => 'http://127.0.0.1:9/ir.pdf', 'filename' => 'ir.pdf', 'caption' => 'IR-DOC']);
    $med = $s->api('admin', 'POST', 'wa_send_media', ['conversation_id' => $ids['sales'], 'media_url' => 'http://127.0.0.1:9/ir2.pdf', 'media_type' => 'document', 'filename' => 'ir2.pdf', 'caption' => 'IR-MED']);
    $f['i_media'] = ['codes' => [$img[0], $doc[0], $med[0]],
                     'calls' => array_map(function ($c) { return [$c['instance'] ?? null, $c['mediatype'] ?? null]; }, array_slice(($k['media'])(), $m0))];
    // A failed send on the sales chat: reported as not sent; nothing stored; nothing from another number.
    $s->http('GET', "{$s->evo}/__test/fail_next?n=1");
    $n0 = count(($k['texts'])());
    $fl = ($k['reply'])($ids['sales'], 'IR-I fails');
    $f['i_fail'] = ['res' => $fl, 'sends' => count($since($n0)), 'stored' => count(($k['stored'])($ids['sales'], 'IR-I fails'))];

    // ── C. What the Inbox reads: its settings row ─────────────────────────────────────────────────────────
    ($k['row'])(['evo_api_url' => null]);
    $n0 = count(($k['texts'])());
    $f['c_no_evolution'] = ['sales' => ($k['reply'])($ids['sales'], 'IR-C no evolution'), 'sends' => count($since($n0))];
    ($k['row'])(['evo_api_url' => $s->evo]);
    ($k['row'])(['evo_instance_account' => null]);
    $n0 = count(($k['texts'])());
    $f['c_no_account'] = ['account' => ($k['reply'])($ids['account'], 'IR-C no account number'), 'sends' => count($since($n0))];
    ($k['row'])(['evo_instance_account' => 'sj-account']);
    $n0 = count(($k['texts'])());
    $back = ($k['reply'])($ids['account'], 'IR-C account back');
    $f['c_restored'] = ['account' => $back, 'sent_on' => array_column($since($n0), 'instance')];

    // ── O. The registry switch set in the Inbox's row: the three numbers route exactly as without it ──────────
    ($k['row'])([IR_FLAG => '1']);
    foreach (['sales', 'support', 'account'] as $ch) {
        $n0 = count(($k['texts'])());
        $res = ($k['reply'])($ids[$ch], "IR-O {$ch}");
        $f['o_' . $ch] = ['code' => $res['http'], 'channel' => $res['channel'], 'sent_on' => array_column($since($n0), 'instance')];
    }
    ($k['row'])([IR_FLAG => null]);

    $f['leak'] = strpos(json_encode([$f['i_other'], $f['i_fail'], $f['c_no_evolution'], $f['c_no_account']]), 'sj-') !== false;
    $s->stop();
    return $f;
}

/** South Sudan: the 5.18.85 rule, verbatim. */
function ir_south_sudan(string $root): array
{
    $f = [];
    $s = SjSandbox::start($root, ['tenant_profile' => 'south-sudan'], 'irss');
    $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
    $k = ir_kit($s);
    $codes = []; $channels = []; $n0 = count(($k['texts'])());
    foreach (['sales' => '211912500101', 'account' => '211912500102', 'accounts' => '211912500103'] as $ch => $phone) {
        $r = ($k['reply'])(($k['conv'])($phone, $ch), "IRSS {$ch}");
        $codes[] = $r['http']; $channels[] = $r['channel'];
    }
    $f['ss'] = ['codes' => $codes, 'channels' => $channels, 'sent_on' => array_column(array_slice(($k['texts'])(), $n0), 'instance')];
    $s->stop();
    return $f;
}

if ($isDriver) {
    echo "\n" . json_encode($part === 'south_sudan' ? ir_south_sudan($root) : ir_uganda($root)) . "\n";
    exit(0);
}

$pass = 0; $fail = 0;
function is_(bool $c, string $m, string $d = ''): void { global $pass, $fail;
    if ($c) { $pass++; echo "  ok   {$m}\n"; } else { $fail++; echo "  FAIL {$m}" . ($d !== '' ? "\n       " . substr($d, 0, 900) : '') . "\n"; } }
$j = function ($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE); };

$f  = ir_uganda($root);
$ss = ir_south_sudan($root);
$ok = function (array $c): bool { return ($c['res']['http'] ?? 0) === 200 && ($c['res']['status'] ?? '') === 'success'; };

echo "\nI. Uganda: the Inbox answers from the conversation's own number\n";
is_($ok($f['i_sales']) && $f['i_sales']['sent_on'] === ['sj-sales'] && $f['i_sales']['to'] === ['256772500101'],
    'a reply in a SALES conversation leaves on the sales number — not on support', $j($f['i_sales']));
is_($f['i_sales']['res']['channel'] === 'sales' && $f['i_sales']['stored'] === 1 && $f['i_sales']['stored_with_id'] === 1
    && $f['i_sales']['support_conv_created'] === false && $f['i_sales']['state'] === 'human_active',
    'stored once in its own conversation, with the WhatsApp id that dedupes the echo; no stray support thread; the AI stands down', $j($f['i_sales']));
is_($ok($f['i_support']) && $f['i_support']['sent_on'] === ['sj-support'] && $f['i_support']['res']['channel'] === 'support'
    && $f['i_support']['state'] === 'human_active', 'support → the support number, exactly as before', $j($f['i_support']));
is_($f['i_support']['stored'] === 2 && $f['i_accounts']['stored'] === 2,
    'support and accounts keep their path exactly — stored twice, the Inbox\'s row and sendVia\'s copy, as before (docs/65 §Z.6)', $j([$f['i_support'], $f['i_accounts']]));
is_($ok($f['i_account']) && $f['i_account']['sent_on'] === ['sj-account'] && $f['i_account']['res']['channel'] === 'account'
    && $f['i_account']['support_conv_created'] === false, 'account → the account number (it went out on support until now)', $j($f['i_account']));
is_($ok($f['i_accounts']) && $f['i_accounts']['sent_on'] === ['sj-account'] && $f['i_accounts']['res']['channel'] === 'accounts',
    'the notification thread (accounts) → the account number, exactly as before', $j($f['i_accounts']));
is_($ok($f['i_web']) && $f['i_web']['sent_on'] === ['sj-support'], 'a web chat → support, exactly as before', $j($f['i_web']));
is_(($f['i_other']['res']['http'] ?? 0) === 502 && $f['i_other']['sends'] === 0 && $f['i_other']['stored'] === 0
    && strpos((string)$f['i_other']['res']['message'], 'Nothing was sent from any other number') !== false,
    'a chat on a number the plugin cannot send from: refused and said — never sent from support', $j($f['i_other']));
is_($f['i_media']['codes'] === [200, 200, 200] && $f['i_media']['calls'] === [['sj-sales', 'image'], ['sj-sales', 'document'], ['sj-sales', 'document']],
    'images and documents from the Inbox leave on the conversation\'s number too', $j($f['i_media']));
is_(($f['i_fail']['res']['http'] ?? 0) === 502 && $f['i_fail']['sends'] === 0 && $f['i_fail']['stored'] === 0
    && strpos((string)$f['i_fail']['res']['message'], 'Not sent on the sales number') === 0,
    'a failed send is reported as not sent: nothing stored, nothing sent from another number', $j($f['i_fail']));

echo "\nC. What the Inbox reads: its settings row\n";
is_(($f['c_no_evolution']['sales']['http'] ?? 0) === 502 && $f['c_no_evolution']['sends'] === 0
    && strpos((string)$f['c_no_evolution']['sales']['message'], 'WhatsApp (Evolution) is not configured here') !== false,
    'Evolution missing from the Inbox\'s settings row (present in the files): a sales reply is refused and says why', $j($f['c_no_evolution']));
is_(($f['c_no_account']['account']['http'] ?? 0) === 502 && $f['c_no_account']['sends'] === 0
    && strpos((string)$f['c_no_account']['account']['message'], "the 'account' channel") !== false,
    'the account number missing from that row: an account reply is refused — never sent from support', $j($f['c_no_account']));
is_(($f['c_restored']['account']['http'] ?? 0) === 200 && $f['c_restored']['sent_on'] === ['sj-account'], 'and goes again once the row names it', $j($f['c_restored']));
is_($f['leak'] === false, 'no Inbox message names an Evolution instance');

echo "\nO. The registry switch set in the Inbox's row\n";
foreach (['sales', 'support', 'account'] as $ch) {
    is_($f['o_' . $ch] === ['code' => 200, 'channel' => $ch, 'sent_on' => ['sj-' . $ch]], "{$ch}: routes exactly as without the switch", $j($f['o_' . $ch]));
}

echo "\nS. South Sudan — the 5.18.85 rule\n";
is_($ss['ss']['codes'] === [200, 200, 200] && $ss['ss']['channels'] === ['support', 'support', 'accounts']
    && $ss['ss']['sent_on'] === ['sj-support', 'sj-support', 'sj-account'],
    'sales and account chats answered from support, the notification thread from the account number — as before', $j($ss['ss']));

// ── X. Weakened copies ─────────────────────────────────────────────────────────────────────────────────────
echo "\nX. Weakened copies, each caught\n";
$drive = function (string $tree, string $part) use ($self): array {
    $out = (string)shell_exec('php ' . escapeshellarg($self) . ' --driver ' . escapeshellarg($tree) . ' ' . escapeshellarg($part) . ' 2>/dev/null');
    $lines = array_values(array_filter(explode("\n", trim($out)), 'strlen'));
    $g = json_decode((string)end($lines), true);
    return is_array($g) ? $g : ['_raw' => substr($out, -400)];
};
$mutants = [
    ['the Inbox sends a sales chat through support again (the route)', 'lib/InboxReplyRoute.php',
     "            return ['mode' => 'channel', 'sender' => '', 'channel' => \$ch, 'reason' => ''];",
     "            return ['mode' => 'sender', 'sender' => 'support', 'channel' => \$ch, 'reason' => ''];", 'uganda',
     function (array $g) { return ($g['i_sales']['sent_on'] ?? null) !== ['sj-sales']; }],
    ['the Inbox reply action ignores the route (the 5.18.85 line back)', 'includes/api/api_whatsapp.php',
     "        \$_sent   = InboxReplyRoute::send(\$_route, svc('notify'), 'text', \$phone, \$text, 'wa_staff_reply');",
     "        \$_sent   = InboxReplyRoute::send(['mode' => 'sender', 'sender' => (\$conv['channel'] ?? 'support') === 'accounts' ? 'accounts' : 'support'], svc('notify'), 'text', \$phone, \$text, 'wa_staff_reply');", 'uganda',
     function (array $g) { return ($g['i_sales']['sent_on'] ?? null) !== ['sj-sales']; }],
    ['the channel send falls back to the support number', 'lib/NotificationService.php',
     "        \$instance = \$evo->instanceFor(\$channel);\n        if (\$instance === '') {",
     "        \$instance = \$evo->instanceFor(\$channel);\n        if (\$instance === '') { \$channel = 'support'; \$instance = \$evo->instanceFor('support'); }\n        if (\$instance === '') {", 'uganda',
     function (array $g) { return ($g['i_other']['sends'] ?? 0) !== 0 || ($g['c_no_account']['sends'] ?? 0) !== 0; }],
    ['a failed send is reported as sent', 'lib/InboxReplyRoute.php',
     "            if (empty(\$r['ok'])) return ['ok' => false,",
     "            if (false) return ['ok' => false,", 'uganda',
     function (array $g) { return ($g['i_fail']['res']['http'] ?? 0) !== 502; }],
    ['South Sudan answered by the Uganda rule', 'lib/InboxReplyRoute.php',
     "            return \\StaffJobsGate::applies(\$config, \$dataDir);",
     "            return true;", 'south_sudan',
     function (array $g) { return ($g['ss']['channels'] ?? null) !== ['support', 'support', 'accounts']; }],
];
foreach ($mutants as [$name, $rel, $old, $new, $part, $caught]) {
    [$copy, $n] = sj_weakened_copy($root, $rel, $old, $new);
    if ($n !== 1) { is_(false, "mutant anchor is unique: {$name}", "count {$n} in {$rel}"); exec('rm -rf ' . escapeshellarg($copy)); continue; }
    $g = $drive($copy, $part);
    is_(!isset($g['_raw']) && $caught($g), "mutant is caught: {$name}", isset($g['_raw']) ? $g['_raw'] : $j(array_intersect_key($g, array_flip(['i_sales', 'i_other', 'i_fail', 'c_no_account', 'ss']))));
    exec('rm -rf ' . escapeshellarg($copy));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

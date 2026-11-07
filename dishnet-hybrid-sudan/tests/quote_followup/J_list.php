<?php
declare(strict_types=1);
/**
 * Section J of tests/test_quote_followup.php — the daily list and the staff page (5.18.104, root docs/73 §H.2.11,
 * §H.2.14 J, §H.2.14 M for the job's output; rulings R18, R19; A11-01, A11-03, A13-03, Q17-F02, Q18-F03). TEST ONLY.
 *
 * The list runs in this process through QuoteDailyList::run() (the cron's one call) at a fixed Kampala moment, against
 * the repository's fake Evolution; the job itself, cron/quote_daily_list.php, runs from the copy in a child process
 * (tests/quote_followup/run_cron.php) where its wiring, its output and a try killed in the middle of its send are the
 * point. The page is served by the plugin itself (SjSandbox: public.php under php -S). Every person, number and message
 * is fictitious; nothing leaves 127.0.0.1.
 */

/** The list's configuration: Uganda, the ledger and tracking on, the list on, the fake Evolution, an alert number. */
function qf_jcfg(string $evo, array $extra = []): array
{
    return qf_tcfg($extra + ['quote_daily_list_enabled' => '1', 'evo_api_url' => $evo, 'evo_api_key' => 'fake-key-qf',
                             'alert_whatsapp' => '256700500999']);
}

/** A list book: its configuration in the store and in <data>/kyc_config.json (what the fresh read reads). */
function qf_jbook(QfCtx $ctx, string $evo, array $extra = []): array
{
    $b = $ctx->book(qf_jcfg($evo, $extra), 'j');
    qf_set_files($b, $b['cfg']);
    return $b;
}

/**
 * A tracked quotation on a list: a conversation (spoke-to unless 'spoke' => false), its tracker row, then the row's owner,
 * status and the rest as given. Its quote_key.
 */
function qf_jrow(\PDO $p, string $ref, string $phone, ?int $owner, array $o = []): string
{
    $o += ['spoke' => true, 'name' => null, 'channel' => 'support', 'sent_at' => '2026-11-01 07:00:00', 'valid_until' => '2026-11-20'];
    $conv = $o['conv'] ?? qf_conv($p, $phone, $o['channel']);
    if ($o['name'] !== null) $p->prepare('UPDATE wa_conversations SET display_name = ? WHERE id = ?')->execute([$o['name'], $conv]);
    $key = qf_track_row($p, $ref, $phone, $conv, ['spoke' => $o['spoke'] ? $conv : 0, 'sent_at' => $o['sent_at'], 'valid_until' => $o['valid_until']]);
    $set = ['owner_staff_id' => $owner, 'owner_source' => $owner === null ? 'none' : 'quotes_log'];
    foreach (['status', 'status_at', 'status_reason', 'chase_hold', 'quote_ref', 'followup_id'] as $f) if (array_key_exists($f, $o)) $set[$f] = $o[$f];
    foreach ($set as $f => $v) $p->prepare("UPDATE quote_tracker SET {$f} = ? WHERE quote_key = ?")->execute([$v, $key]);
    return $key;
}

/** One run of the list, in this process, at the Kampala moment $at (or a unix time), on the copy's tree. */
function qf_jrun(QfCtx $ctx, array $b, $at, ?callable $clock = null, int $budget = 21): array
{
    $t = is_int($at) ? $at : qf_t($at);
    return QuoteDailyList::run($b['pdo'], $b['cfg'], $b['data'], $b['store'], $ctx->tree(), $clock ?? function () use ($t) { return $t; }, $t + $budget);
}

function qf_jlists(\PDO $p): array
{
    return qf_rows($p, 'SELECT list_date, owner_key, outcome, item_count, detail, sections, created_at FROM quote_list_sends ORDER BY id');
}

function qf_jledger(\PDO $p): array
{
    return qf_rows($p, "SELECT ref, try, mode, outcome, wa_message_id, claimed_at, detail FROM wa_auto_sends WHERE kind = 'staff_list' ORDER BY id");
}

/** A row's field, NULL kept as null; '<absent>' when the row is not there. */
function qf_jv(array $rows, int $i, string $f)
{
    return isset($rows[$i]) && array_key_exists($f, $rows[$i]) ? $rows[$i][$f] : '<absent>';
}

/** The section entries as quote_list_sends.sections records them. */
function qf_jsec(array $by): array
{
    $out = [];
    foreach (['due', 'call', 'replied', 'stop', 'expiring', 'maybe_sent'] as $s) {
        $keys = $by[$s] ?? [];
        $n = $by[$s . '_n'] ?? count($keys);
        $out[] = ['section' => $s, 'n' => $n, 'keys' => $keys];
    }
    return $out;
}

/** No fixture phone (in any punctuation), no fixture name and no run of 9 or more digits. */
function qf_jprivate(string $text, array $phones, array $names): string
{
    $digits = (string)preg_replace('/\D+/', '', $text);
    foreach ($phones as $ph) if (strpos($digits, substr((string)preg_replace('/\D+/', '', $ph), -9)) !== false) return 'a phone';
    foreach ($names as $n) if (stripos($text, $n) !== false) return 'a name';
    if (preg_match('/\d{9,}/', $text)) return 'a 9-digit run';
    return '';
}

/** The list's cron in a child process, in the background: [proc, a function that waits and returns [rc, output]]. */
function qf_jcron_bg(QfCtx $ctx, array $b, array $vars): array
{
    $tree = $ctx->tree();
    $cfg = $b['cfg']; unset($cfg['_data_dir']);
    file_put_contents($b['data'] . '/kyc_config.json', json_encode($cfg));
    $uj = $tree . '/ucrm.json';
    $was = is_file($uj) ? (string)file_get_contents($uj) : null;
    file_put_contents($uj, json_encode(['pluginDataDir' => $b['data']]));
    $log = $b['data'] . '/jcron-' . bin2hex(random_bytes(3)) . '.log';
    $cmd = sprintf('cd %s && DN_DATA_DIR=%s DN_VAULT_FILE=%s exec php %s %s %s > %s 2>&1', escapeshellarg($tree . '/cron'), escapeshellarg($b['data']),
                   escapeshellarg($b['data'] . '/vault.json'), escapeshellarg(__DIR__ . '/run_cron.php'), escapeshellarg($tree . '/cron/quote_daily_list.php'),
                   escapeshellarg(json_encode($vars)), escapeshellarg($log));
    $env = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r']], $pipes, null, $env);
    $wait = function () use ($proc, $log, $uj, $was): array {
        $rc = proc_close($proc);
        if ($was === null) @unlink($uj); else file_put_contents($uj, $was);
        return [$rc, (string)@file_get_contents($log)];
    };
    return [$proc, $wait];
}

$QF_SECTIONS['J'] = ['title' => 'The daily list and the staff page (§H.2.11)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'QuoteChase', 'QuoteDailyList', 'WaSendBudget', 'FollowUpService'] as $l) require_once $tree . '/lib/' . $l . '.php';
    $evo = qf_fake_evo();
    $ctx->t($evo !== '', 'J00 control: the fake Evolution answers');
    if ($evo === '') return;
    $names = ['Test Seller One', 'Test Seller Two', 'Test Seller Three', 'Test Customer'];

    $ctx->run('J00 control: an owner with an open quotation gets one list at 08:30 on a Monday, and the job reads it as due', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_jrow($p, 'Q-J001', '256772502001', 101);
        $ctx->eq(QuoteDailyList::pendingToday($p, $b['cfg'], $b['data'], qf_t('2026-11-02 08:30:00')), ['staff:101'], 'J00 pendingToday names the owner');
        $c = qf_jrun($ctx, $b, '2026-11-02 08:30:00');
        $ctx->eq([$c['sent'], count(qf_texts($evo))], [1, 1], 'J00 one list sent', json_encode($c));
    });

    $ctx->run('J01 one list per owner per day, from the sales number, claimed on the ledger; quote numbers and counts only', function () use ($ctx, $evo, $names) {
        qf_fake_evo();
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_staff($p, 102, 'Test Seller Two', true, ['phone' => '0772500102']);
        qf_jrow($p, 'Q-J011', '256772502011', 101, ['name' => 'Test Customer Eleven']);
        qf_jrow($p, 'Q-J012', '256772502012', 101, ['spoke' => false, 'valid_until' => '2026-11-02', 'name' => 'Test Customer Twelve']);
        qf_jrow($p, 'Q-J013', '256772502013', 102, ['status' => 'answered', 'status_at' => '2026-11-01 09:00:00', 'status_reason' => 'reply']);
        qf_jrow($p, 'Q-J014', '256772502014', 102, ['status' => 'answered', 'status_at' => '2026-11-01 09:10:00', 'status_reason' => 'unread_inbound']);
        qf_jrow($p, 'Q-J015', '256772502015', null);
        qf_jrow($p, 'Q-J016', '256772502016', 101, ['status' => 'paid', 'status_at' => '2026-10-20 09:00:00', 'sent_at' => '2026-10-19 07:00:00']);
        $phones = ['256772502011', '256772502012', '256772502013', '256772502014', '256772502015', '0772500101', '0772500102', '256700500999'];

        $c1 = qf_jrun($ctx, $b, '2026-11-02 08:30:00');
        $tx = qf_texts($evo);
        $want1 = "📋 DishNet — your open quotations, Monday 2 November\n"
               . "Yours to call (no automatic reminder): 2 — Q-J011 (chase is off) · Q-J012 (never wrote)\n"
               . "Expiring today: 1 — Q-J012\n"
               . "This week: sent 2 · replied 0 · reminded 0 · paid 0\n"
               . "Names and details: Sales → My quotations";
        $ctx->eq([$c1['sent'], count($tx), $tx[0]['instance'] ?? null, preg_replace('/\D+/', '', (string)($tx[0]['number'] ?? '')), $tx[0]['text'] ?? null],
                 [1, 1, 'Sales-UG', '256772500101', $want1], 'J01 the first owner\'s list: from the sales instance, to the owner\'s phone, these bytes');
        $ctx->eq(qf_jprivate((string)($tx[0]['text'] ?? ''), $phones, $names), '', 'J01 no phone, no name, no 9-digit run in the text (QM36)');
        $ctx->eq(qf_jledger($p), [['ref' => 'list:2026-11-02:staff:101', 'try' => 1, 'mode' => 'claim', 'outcome' => 'sent', 'wa_message_id' => 'FAKE-EVO-MSG-1',
                                   'claimed_at' => '2026-11-02 05:30:00', 'detail' => null]],
                 'J01 claimed on the ledger as staff_list at list:<date>:<owner_key>, settled sent with the message id (QM29)');
        $ctx->eq((int)qf_rows($p, "SELECT COUNT(*) AS n FROM wa_auto_sends WHERE kind <> 'staff_list'")[0]['n'], 0, 'J01 AlertService records no second row for a quote_list: key (HK4)');
        $l = qf_jlists($p);
        $ctx->eq([$l[0]['owner_key'] ?? null, $l[0]['outcome'] ?? null, $l[0]['item_count'] ?? null, qf_jv($l, 0, 'detail'), json_decode((string)($l[0]['sections'] ?? ''), true)],
                 ['staff:101', 'sent', 2, null, qf_jsec(['call' => ['ref:Q-J011', 'ref:Q-J012'], 'expiring' => ['ref:Q-J012']])],
                 'J01 its row: sent, two items, the sections it listed');

        $c2 = qf_jrun($ctx, $b, '2026-11-02 08:34:00');
        $tx = qf_texts($evo);
        $want2 = "📋 DishNet — your open quotations, Monday 2 November\n"
               . "Replied, not yet paid — call them: 2 — Q-J013 · Q-J014 (open WhatsApp — the reply could not be read here)\n"
               . "This week: sent 2 · replied 2 · reminded 0 · paid 0\n"
               . "Names and details: Sales → My quotations";
        $ctx->eq([$c2['sent'], count($tx), preg_replace('/\D+/', '', (string)($tx[1]['number'] ?? '')), $tx[1]['text'] ?? null],
                 [1, 2, '256772500102', $want2], 'J01 the next run: the second owner\'s list (this week\'s answered quotations)');

        $c3 = qf_jrun($ctx, $b, '2026-11-02 08:38:00');
        $tx = qf_texts($evo);
        $want3 = "📋 DishNet — open quotations with no salesperson to list them, Monday 2 November\n"
               . "Yours to call (no automatic reminder): 1 — Q-J015 (chase is off)\n"
               . "Team totals: due 0 · to call 3 · replied 2 · stop 0 · expiring 1 · may have gone 0\n"
               . "This week (everyone): sent 5 · replied 2 · reminded 0 · paid 0\n"
               . "Names and details: Sales → My quotations";
        $ctx->eq([$c3['sent'], count($tx), preg_replace('/\D+/', '', (string)($tx[2]['number'] ?? '')), $tx[2]['text'] ?? null],
                 [1, 3, '256700500999', $want3], 'J01 then the central list, to alert_whatsapp: its quotations and the team\'s totals, no staff name (QM54)');
        $ctx->eq(qf_jprivate((string)($tx[2]['text'] ?? ''), $phones, $names), '', 'J01 the central list: no phone, no name, no 9-digit run');

        $c4 = qf_jrun($ctx, $b, '2026-11-02 08:42:00');
        $ctx->eq([$c4['none_due'], count(qf_texts($evo)), count(qf_jlists($p))], [1, 3, 3], 'J01 a fourth run: nobody is due, nothing is sent');
        $c5 = qf_jrun($ctx, $b, '2026-11-03 08:30:00');
        $tx = qf_texts($evo);
        $ctx->eq([$c5['sent'], count($tx), qf_jlists($p)[3]['list_date'] ?? null, qf_jlists($p)[3]['owner_key'] ?? null],
                 [1, 4, '2026-11-03', 'staff:101'], 'J01 the next day the first owner is due again: one list per owner per day');
    });

    $ctx->run('J02 08:00–11:00 Kampala, Monday to Saturday: never before, never on Sunday (QM35, QM57)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_jrow($p, 'Q-J021', '256772502021', 101);
        $c = qf_jrun($ctx, $b, '2026-11-02 07:59:00');
        $ctx->eq([$c['outside_window'], count(qf_texts($evo)), qf_jledger($p)], [1, 0, []], 'J02 07:59 Monday: outside the window, nothing claimed or sent');
        $c = qf_jrun($ctx, $b, '2026-11-08 09:00:00');
        $ctx->eq([$c['outside_window'], count(qf_texts($evo)), qf_jledger($p)], [1, 0, []], 'J02 09:00 Sunday: nothing claimed or sent');
        $c = qf_jrun($ctx, $b, '2026-11-07 09:00:00');
        $ctx->eq([$c['sent'], count(qf_texts($evo))], [1, 1], 'J02 09:00 Saturday: the list goes');
    });

    $ctx->run('J03 to whom: active owners with a phone, inside the pilot ids; the rest go on the central list', function () use ($ctx, $evo) {
        $b = qf_jbook($ctx, $evo, ['quote_daily_list_staff' => '101,103,104']); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_staff($p, 102, 'Test Seller Two', true, ['phone' => '0772500102']);     // outside the pilot ids
        qf_staff($p, 103, 'Test Seller Three', false, ['phone' => '0772500103']);  // inactive
        qf_staff($p, 104, 'Test Seller Four', true);                                // no phone
        foreach ([101, 102, 103, 104] as $i => $o) qf_jrow($p, 'Q-J03' . $i, '25677250203' . $i, $o);
        qf_jrow($p, 'Q-J039', '256772502039', null);
        $at = qf_t('2026-11-02 08:30:00');
        $ctx->eq(QuoteDailyList::pendingToday($p, $b['cfg'], $b['data'], $at), ['staff:101', 'central'], 'J03 due: the pilot owner, and the central list');
        $p->exec('BEGIN IMMEDIATE');
        $central = QuoteDailyList::build($p, $b['cfg'], $b['data'], 'central', $at);
        $p->exec('COMMIT');
        $ctx->eq($central['sections'][1]['keys'], ['ref:Q-J031', 'ref:Q-J032', 'ref:Q-J033', 'ref:Q-J039'],
                 'J03 the central list: outside the pilot ids, inactive, no phone, unattributed');
        $cfg = $b['cfg']; unset($cfg['quote_daily_list_staff']);
        $ctx->eq(QuoteDailyList::pendingToday($p, $cfg, $b['data'], $at), ['staff:101', 'staff:102', 'central'], 'J03 with no pilot ids, every active owner with a phone');
        $p->prepare("UPDATE quote_tracker SET owner_source = 'pending' WHERE quote_key = 'ref:Q-J039'")->execute();
        $p->exec('BEGIN IMMEDIATE');
        $central = QuoteDailyList::build($p, $b['cfg'], $b['data'], 'central', $at);
        $p->exec('COMMIT');
        $ctx->eq($central['sections'][1]['keys'], ['ref:Q-J031', 'ref:Q-J032', 'ref:Q-J033'], 'J03 an owner still pending (under 1 h) is on no list yet');
    });

    $ctx->run('J04 the sections when the chase is on: due today, held, the persona step, maybe sent', function () use ($ctx, $evo) {
        $b = qf_jbook($ctx, $evo, qf_ccfg()); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        $svc = new FollowUpService($p);
        $mk = function (string $ref, string $phone, string $due) use ($p, $svc): array {
            $conv = qf_conv($p, $phone);
            $key = qf_jrow($p, $ref, $phone, 101, ['conv' => $conv]);
            $fid = $svc->openQuote(['id' => $conv, 'channel' => 'support', 'phone' => $phone], ['quote_key' => $key, 'ref' => $ref, 'valid_until' => '2026-11-20',
                                   'sent_at' => '2026-11-01 07:00:00', 'due_at' => $due]);
            $p->prepare('UPDATE quote_tracker SET followup_id = ? WHERE quote_key = ?')->execute([$fid, $key]);
            return [$key, $fid, $conv];
        };
        $mk('Q-J041', '256772502041', '2026-11-02 12:00:00');                 // due today (15:00 Kampala)
        [, $f42] = $mk('Q-J042', '256772502042', '2026-11-04 07:00:00');      // its next step on Wednesday: on no section
        [, $f43, $c43] = $mk('Q-J043', '256772502043', '2026-11-06 13:00:00'); // day 3 held for the persona: theirs to call
        $svc->log($f43, $c43, 'skipped', 'step 2 held: ' . QuoteChaseText::PERSONA_PENDING, 'quote_chase');
        $p->prepare("INSERT INTO wa_auto_sends (handset, kind, ref, mode, proactive, phone, local_day, gap_s, outcome, settled_at)
                     VALUES ('i:dishnet-ug', 'quote', ?, 'record', 1, '256772502042', '2026-11-01', 60, 'uncertain', '2026-11-01 08:00:00')")->execute(['fu:' . $f42 . ':1']);
        qf_jrow($p, 'Q-J044', '256772502044', 101, ['chase_hold' => 'owned_number']);
        qf_jrow($p, 'Q-J045', '256772502045', 101, ['spoke' => false, 'chase_hold' => 'never_wrote']);
        $at = qf_t('2026-11-02 08:30:00');
        $p->exec('BEGIN IMMEDIATE');
        $l = QuoteDailyList::build($p, $b['cfg'], $b['data'], 'staff:101', $at);
        $p->exec('COMMIT');
        $ctx->eq($l['sections'], qf_jsec(['due' => ['ref:Q-J041'], 'call' => ['ref:Q-J043', 'ref:Q-J044', 'ref:Q-J045'], 'maybe_sent' => ['ref:Q-J042']]),
                 'J04 due today; the held persona step, the own number and never-wrote rows to call; a step that may have gone');
        $ctx->eq(strpos($l['text'], "Yours to call (no automatic reminder): 3 — Q-J043 · Q-J044 (your own number) · Q-J045 (never wrote)") !== false
                 && strpos($l['text'], "Reminder due today (sent automatically): 1 — Q-J041") !== false
                 && strpos($l['text'], "May have been sent — check the chat: 1 — Q-J042") !== false
                 && strpos($l['text'], 'reminded 1') !== false, true, 'J04 the text names them so', $l['text']);
    });

    $ctx->run('J05 the privacy guard: a 9-digit run outside the refs is never claimed or sent, and is missed at once (QM71)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_jrow($p, 'Q-J051', '256772502051', 101, ['quote_ref' => 'Q-J051 256772502051']);   // a planted number beside the ref
        $c = qf_jrun($ctx, $b, '2026-11-02 08:30:00');
        $l = qf_jlists($p);
        $ctx->eq([$c['digit_run'], count(qf_texts($evo)), qf_jledger($p), $l[0]['outcome'] ?? null, $l[0]['detail'] ?? null, qf_jv($l, 0, 'sections'), $l[0]['item_count'] ?? null],
                 [1, 0, [], 'missed', 'digit_run', null, 0], 'J05 written missed with detail digit_run, no sections; nothing claimed, nothing sent');
        $c = qf_jrun($ctx, $b, '2026-11-02 08:34:00');
        $ctx->eq([$c['none_due'], count(qf_texts($evo)), count(qf_jlists($p))], [1, 0, 1], 'J05 not tried again that day');
        $ctx->eq([QuoteDailyList::digitRunFree("x — Q-1, 2026110200123\nThis week: sent 2", ['Q-1', '2026110200123']),
                  QuoteDailyList::digitRunFree("x — 2, Q-1 256772502051", ['2', 'Q-1']),
                  QuoteDailyList::digitRunFree("x — 772502, 256772502051", ['772502']),
                  QuoteDailyList::digitRunFree("x — 25677 2502051", ['Q-9'])],
                 [true, false, false, true], 'J05 digitRunFree(): a long ref is taken out; a short ref inside a number never hides it (whole tokens only)');
    });

    $ctx->run('J06 "Asked us to stop": an answered quotation whose customer then sent STOP is there and nowhere else (S2-20, QM59)', function () use ($ctx, $evo) {
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_jrow($p, 'Q-J061', '256772502061', 101, ['status' => 'answered', 'status_at' => '2026-11-01 09:00:00', 'status_reason' => 'reply']);
        qf_jrow($p, 'Q-J062', '256772502062', 101, ['status' => 'opted_out', 'status_at' => '2026-11-01 09:00:00', 'status_reason' => 'stop_message']);
        qf_jrow($p, 'Q-J063', '256772502063', 101, ['valid_until' => '2026-11-02']);
        foreach (['256772502061', '256772502063'] as $ph) {
            $p->prepare("INSERT INTO contact_optouts (phone, reason, source) VALUES (?, 'customer_request', 'keyword')")->execute([$ph]);
        }
        $p->exec('BEGIN IMMEDIATE');
        $l = QuoteDailyList::build($p, $b['cfg'], $b['data'], 'staff:101', qf_t('2026-11-02 08:30:00'));
        $p->exec('COMMIT');
        $ctx->eq($l['sections'], qf_jsec(['stop' => ['ref:Q-J061', 'ref:Q-J062', 'ref:Q-J063']]),
                 'J06 the answered one with a live opt-out, the opted-out one and an open one with an opt-out: under stop only (not replied, call or expiring)');
        $ctx->eq(strpos($l['text'], 'Asked us to stop — do not message: 3 — Q-J061, Q-J062, Q-J063') !== false && strpos($l['text'], 'call them') === false,
                 true, 'J06 the text: "Asked us to stop", and no "call them"', $l['text']);
    });

    $ctx->run('J07 the sweep at 11:00 (A13-03): missed with the last code, once a day, never on Sunday or with the list off', function () use ($ctx, $evo) {
        // a) deferred by spacing on every claim until 11:00: missed with detail spacing
        qf_fake_evo();
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_jrow($p, 'Q-J071', '256772502071', 101);
        $p->exec("INSERT INTO wa_auto_sends (handset, kind, ref, mode, proactive, local_day, gap_s, claimed_at, outcome, settled_at)
                  VALUES ('i:sales-ug', 'other', 'a:spacing-plant', 'record', 0, '2026-11-02', 900, '2026-11-02 07:59:59', 'sent', '2026-11-02 07:59:59')");
        foreach (['08:05', '09:41'] as $hm) {
            $c = qf_jrun($ctx, $b, '2026-11-02 ' . $hm . ':00');
            $ctx->eq([$c['deferred'], qf_state($p, 'list_last:2026-11-02:staff:101')], [1, 'spacing'], "J07a {$hm}: deferred spacing, its code kept, no row");
        }
        $c = qf_jrun($ctx, $b, '2026-11-02 11:00:00');
        $l = qf_jlists($p);
        $ctx->eq([$c['swept'], $c['missed'], count($l), $l[0]['outcome'] ?? null, $l[0]['detail'] ?? null, qf_jv($l, 0, 'sections'), qf_state($p, 'list_swept'),
                  qf_state($p, 'list_last:2026-11-02:staff:101'), count(qf_texts($evo))],
                 [1, 1, 1, 'missed', 'spacing', null, '2026-11-02', null, 0], 'J07a the first run at 11:00: missed with detail spacing; list_swept set, the code removed, nothing sent (QM56)');

        // b) not_sent at 08:05 and not tried again before 11:00: missed not_sent
        qf_fake_evo();
        qf_http('GET', $evo . '/__test/fail_instance?name=Sales-UG&code=500');
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_jrow($p, 'Q-J072', '256772502072', 101);
        $c = qf_jrun($ctx, $b, '2026-11-02 08:05:00');
        qf_http('GET', $evo . '/__test/fail_instance?clear=1');
        $ctx->eq([$c['not_sent'], qf_jlists($p)[0]['outcome'] ?? null, qf_state($p, 'list_last:2026-11-02:staff:101')], [1, 'not_sent', 'not_sent'], 'J07b 08:05: a certain failure, not_sent');
        qf_jrun($ctx, $b, '2026-11-02 11:00:00');
        $l = qf_jlists($p);
        $ctx->eq([count($l), $l[1]['outcome'] ?? null, $l[1]['detail'] ?? null], [2, 'missed', 'not_sent'], 'J07b 11:00: missed with detail not_sent, beside the not_sent row');

        // c) never claimed: no_attempt; once a day; an owner due after the sweep is never missed
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_staff($p, 102, 'Test Seller Two', true, ['phone' => '0772500102']);
        qf_jrow($p, 'Q-J073', '256772502073', 101);
        qf_jrun($ctx, $b, '2026-11-02 11:00:00');
        $ctx->eq(array_map(function ($r) { return [$r['owner_key'], $r['outcome'], $r['detail']]; }, qf_jlists($p)), [['staff:101', 'missed', 'no_attempt']], 'J07c never claimed: no_attempt');
        $c = qf_jrun($ctx, $b, '2026-11-02 11:04:00');
        qf_jrow($p, 'Q-J074', '256772502074', 102, ['sent_at' => '2026-11-02 08:10:00']);
        $c2 = qf_jrun($ctx, $b, '2026-11-02 11:14:00');
        $ctx->eq([$c['swept'], $c2['swept'], count(qf_jlists($p))], [0, 0, 1], 'J07c later runs that day sweep nothing: the owner due after the sweep is not missed (QM73)');

        // d) not_sent at 08:05, then left out by `set list-staff`: not tried again, and not missed (Q17-F02)
        qf_fake_evo();
        qf_http('GET', $evo . '/__test/fail_instance?name=Sales-UG&code=500');
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_staff($p, 102, 'Test Seller Two', true, ['phone' => '0772500102']);
        qf_jrow($p, 'Q-J075', '256772502075', 101);
        qf_jrun($ctx, $b, '2026-11-02 08:05:00');
        qf_http('GET', $evo . '/__test/fail_instance?clear=1');
        $b['cfg']['quote_daily_list_staff'] = '102';
        qf_set_files($b, $b['cfg']);
        foreach (['08:09', '08:13', '11:00'] as $hm) qf_jrun($ctx, $b, '2026-11-02 ' . $hm . ':00');
        $mine = array_values(array_filter(qf_jlists($p), function ($r) { return $r['owner_key'] === 'staff:101'; }));
        $ctx->eq(array_map(function ($r) { return $r['outcome']; }, $mine), ['not_sent'], 'J07d the owner left out after a not_sent try: not tried again, never missed');
        $ctx->eq(count(array_filter(qf_jledger($p), function ($r) { return $r['ref'] === 'list:2026-11-02:staff:101'; })), 1, 'J07d one try only on its ref');

        // e) no sweep on Sunday; f) none with the list off (the job is quiet); g) none on a day the list began after 11:00
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_jrow($p, 'Q-J076', '256772502076', 101);
        $c = qf_jrun($ctx, $b, '2026-11-08 11:30:00');
        $ctx->eq([$c['swept'], qf_state($p, 'list_swept'), qf_jlists($p)], [0, null, []], 'J07e Sunday 11:30: no sweep');
        $off = $b['cfg']; $off['quote_daily_list_enabled'] = '0'; $b2 = $b; $b2['cfg'] = $off;
        [$proc, $wait] = qf_jcron_bg($ctx, $b2, ['_qf_test_now' => qf_t('2026-11-09 11:30:00')]);
        [$rc, $out] = $wait();
        $ctx->eq([$rc, trim($out), qf_state($p, 'list_swept'), qf_jlists($p)], [0, '', null, []], 'J07f the list off: the job says nothing and sweeps nothing', $out);
        qf_set_files($b, $b['cfg']);
        $late = $b['cfg']; $late['quote_daily_list_since'] = '2026-11-09 11:00:00'; $b3 = $b; $b3['cfg'] = $late;
        $c = qf_jrun($ctx, $b3, '2026-11-09 14:05:00');
        $ctx->eq([$c['swept'], $c['missed'], qf_state($p, 'list_swept'), qf_jlists($p)], [1, 0, '2026-11-09', []], 'J07g switched on at 14:00: that day\'s sweep writes no missed list');
    });

    $ctx->run('J08 the settle, from the raw answer (A11-01): maybe sent → uncertain and final; a certain failure → not_sent, tried again; a cooldown → uncertain (QM70)', function () use ($ctx, $evo) {
        // may have been sent: a gateway timeout in front of Evolution
        qf_fake_evo();
        qf_http('GET', $evo . '/__test/fail_instance?name=Sales-UG&code=504');
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_jrow($p, 'Q-J081', '256772502081', 101);
        $c = qf_jrun($ctx, $b, '2026-11-02 08:30:00');
        qf_http('GET', $evo . '/__test/fail_instance?clear=1');
        $l = qf_jlists($p); $g = qf_jledger($p);
        $ctx->eq([$c['uncertain'], $l[0]['outcome'] ?? null, $l[0]['detail'] ?? null, $g[0]['outcome'] ?? null, $g[0]['detail'] ?? null],
                 [1, 'uncertain', 'maybe_sent', 'uncertain', 'maybe_sent'], 'J08a a 504 on the send: the list and its ledger row uncertain');
        qf_jrun($ctx, $b, '2026-11-02 08:34:00');
        qf_jrun($ctx, $b, '2026-11-02 11:00:00');
        $ctx->eq([count(qf_texts($evo)), array_map(function ($r) { return $r['outcome']; }, qf_jlists($p)), count(qf_jledger($p))], [0, ['uncertain'], 1],
                 'J08a not sent again, never missed');

        // a certain failure: not_sent, then sent at the next run on the next try
        qf_fake_evo();
        qf_http('GET', $evo . '/__test/fail_instance?name=Sales-UG&code=500');
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_jrow($p, 'Q-J082', '256772502082', 101);
        qf_jrun($ctx, $b, '2026-11-02 08:30:00');
        qf_http('GET', $evo . '/__test/fail_instance?clear=1');
        qf_jrun($ctx, $b, '2026-11-02 08:34:00');
        $ctx->eq([array_map(function ($r) { return [$r['outcome'], $r['detail']]; }, qf_jlists($p)), array_map(function ($r) { return [$r['try'], $r['outcome']]; }, qf_jledger($p)), count(qf_texts($evo))],
                 [[['not_sent', 'send_failed'], ['sent', null]], [[1, 'not_sent'], [2, 'sent']], 1], 'J08b not_sent, then sent on try 2');

        // a cooldown on the list's key: the list went or may have gone
        qf_fake_evo();
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_jrow($p, 'Q-J083', '256772502083', 101);
        $b['store']->save('alert_locks.json', [['key' => 'quote_list:2026-11-02:101', 'ts' => time()]]);
        $c = qf_jrun($ctx, $b, '2026-11-02 08:30:00');
        $l = qf_jlists($p); $g = qf_jledger($p);
        $ctx->eq([$c['uncertain'], $l[0]['outcome'] ?? null, $l[0]['detail'] ?? null, $g[0]['outcome'] ?? null, $g[0]['detail'] ?? null, count(qf_texts($evo))],
                 [1, 'uncertain', 'alert_cooldown', 'uncertain', 'alert_cooldown', 0], 'J08c a cooldown: uncertain, alert_cooldown, nothing sent');
    });

    $ctx->run('J09 the build (ruling R18): created_at is the moment taken under the lock; the sections hold each count and at most 15 keys', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        $keys = [];
        for ($i = 10; $i <= 26; $i++) $keys[] = qf_jrow($p, 'Q-J9' . $i, '2567725029' . $i, 101);
        qf_jrun($ctx, $b, '2026-11-02 08:30:00');
        $l = qf_jlists($p);
        $sec = json_decode((string)($l[0]['sections'] ?? ''), true);
        $ctx->eq([$l[0]['created_at'] ?? null, $l[0]['item_count'] ?? null, $sec], ['2026-11-02 05:30:00', 17, qf_jsec(['call' => array_slice($keys, 0, 15), 'call_n' => 17])],
                 'J09 created_at is the build\'s moment (the run\'s clock, not the insert\'s default); call n 17 with its first 15 keys (QM79)');
        $tx = (string)(qf_texts($evo)[0]['text'] ?? '');
        $refs = implode(' · ', array_map(function ($k) { return substr($k, 4) . ' (chase is off)'; }, array_slice($keys, 0, 15)));
        $ctx->eq(strpos($tx, 'Yours to call (no automatic reminder): 17 — ' . $refs . ' and 2 more') !== false, true, 'J09 the text\'s count and refs are the sections\'', $tx);
    });

    $ctx->run('J09b the build waits for the lock and takes its moment after it (ruling R18)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_jrow($p, 'Q-J095', '256772502095', 101);
        $flag = $b['data'] . '/locked';
        $code = '$p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA busy_timeout = 5000"); $p->exec("BEGIN IMMEDIATE"); touch($argv[2]);'
              . ' sleep(2); $p->exec("INSERT INTO contact_optouts (phone, reason, source) VALUES (\'256772502095\', \'customer_request\', \'keyword\')"); $p->exec("COMMIT");';
        $h = proc_open('exec php -r ' . escapeshellarg($code) . ' ' . escapeshellarg($b['data'] . '/plugin.sqlite3') . ' ' . escapeshellarg($flag), [], $pipes);
        for ($i = 0; $i < 100 && !is_file($flag); $i++) usleep(50000);
        $t0 = qf_t('2026-11-02 08:30:00'); $s0 = time();
        $c = qf_jrun($ctx, $b, $t0, function () use ($t0, $s0) { return $t0 + (time() - $s0); }, 60);
        proc_close($h);
        $l = qf_jlists($p);
        $sec = json_decode((string)($l[0]['sections'] ?? ''), true);
        $ctx->eq([is_file($flag), UtcClock::parse((string)($l[0]['created_at'] ?? '')) - $t0 >= 1, $sec[3]['keys'] ?? null],
                 [true, true, ['ref:Q-J095']], 'J09b the build waited for the writer: its moment is after the wait, and it read the opt-out stored then (QM79)', json_encode([$c, $l]));
    });

    $ctx->run('J10 the fresh read (ruling R19): `off list` between the build and the claim — nothing claimed, sent or written (QM78)', function () use ($ctx, $evo) {
        foreach ([false, true] as $off) {
            qf_fake_evo();
            $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
            qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
            qf_jrow($p, 'Q-J10' . ($off ? '1' : '0'), '25677250210' . ($off ? '1' : '0'), 101);
            $t = qf_t('2026-11-02 08:30:00'); $n = 0;
            $offCfg = $b['cfg']; unset($offCfg['quote_daily_list_enabled']);
            $clock = function () use (&$n, $t, $b, $offCfg, $off) { if (++$n === 3 && $off) qf_set_files($b, $offCfg); return $t; };
            $c = qf_jrun($ctx, $b, $t, $clock);
            if (!$off) {
                $ctx->eq([$c['sent'], count(qf_texts($evo))], [1, 1], 'J10 control: the files unchanged, the list is claimed and sent');
            } else {
                $ctx->eq([$c['built'], $c['held_fresh_read'], count(qf_texts($evo)), qf_jledger($p), qf_jlists($p)], [1, 1, 0, [], []],
                         'J10 the run began with the list on; the files read it off just before the claim: no claim, no send, no row', json_encode($c));
            }
        }
    });

    $ctx->run('J11 a try that died after its send (Q18-F03): in flight, then uncertain on already_sent, never sent again; a 10:55 death is not missed', function () use ($ctx, $evo) {
        foreach (['09:00' => false, '10:55' => true] as $hm => $sweep) {
            qf_fake_evo();
            $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
            qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
            qf_jrow($p, 'Q-J11' . ($sweep ? '1' : '0'), '25677250211' . ($sweep ? '1' : '0'), 101);
            $hold = $ctx->dir('hold');
            qf_http('GET', $evo . '/__test/hold?dir=' . rawurlencode($hold));
            [$proc, $wait] = qf_jcron_bg($ctx, $b, ['_qf_test_now' => qf_t('2026-11-02 ' . $hm . ':00')]);
            for ($i = 0; $i < 200 && !is_file($hold . '/held'); $i++) usleep(50000);
            $held = is_file($hold . '/held');
            proc_terminate($proc, 9);
            $wait();
            touch($hold . '/release');
            usleep(300000);
            $g = qf_jledger($p);
            $ctx->eq([$held, count($g), qf_jv($g, 0, 'outcome'), qf_jlists($p)], [true, 1, null, []], "J11 {$hm}: the run killed in its send leaves the claim in flight and no list row");
            if (!$sweep) {
                $c = qf_jrun($ctx, $b, '2026-11-02 09:04:00');
                $ctx->eq([$c['deferred'], qf_state($p, 'list_last:2026-11-02:staff:101'), qf_jlists($p), count(qf_texts($evo))], [1, 'in_flight', [], 1],
                         'J11 09:04: in_flight, nothing sent, no row');
                $c = qf_jrun($ctx, $b, '2026-11-02 09:16:00');
                $l = qf_jlists($p); $g = qf_jledger($p);
                $ctx->eq([$c['already_sent'], $l[0]['outcome'] ?? null, $l[0]['detail'] ?? null, $l[0]['created_at'] ?? null, $l[0]['item_count'] ?? null,
                          json_decode((string)($l[0]['sections'] ?? ''), true), $g[0]['outcome'] ?? null, count(qf_texts($evo))],
                         [1, 'uncertain', 'already_sent', '2026-11-02 06:16:00', 1, qf_jsec(['call' => ['ref:Q-J110']]), 'uncertain', 1],
                         'J11 09:16, 900 s on: already_sent — the list written uncertain with this run\'s build, nothing sent (QM83)');
                $c = qf_jrun($ctx, $b, '2026-11-02 09:20:00');
                qf_jrun($ctx, $b, '2026-11-02 11:00:00');
                $ctx->eq([$c['none_due'], count(qf_jlists($p)), count(qf_texts($evo))], [1, 1, 1], 'J11 tried no more, and never missed');
            } else {
                $c = qf_jrun($ctx, $b, '2026-11-02 11:00:00');
                $ctx->eq([$c['swept'], $c['missed'], qf_jlists($p), qf_state($p, 'list_swept')], [1, 0, [], '2026-11-02'],
                         'J11 10:55: the 11:00 sweep leaves out the owner whose try may have gone — no missed row (QM84)');
            }
        }
    });

    $ctx->run('J12 the job (cron/quote_daily_list.php): one line of counts; deferred by master\'s budget; quiet on South Sudan (§H.2.14 M)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_jrow($p, 'Q-J121', '256772502121', 101);
        $t = qf_t('2026-11-02 08:30:00');
        [, $wait] = qf_jcron_bg($ctx, $b, ['_qf_test_now' => $t, '_m_deadline' => $t - 1]);
        [$rc, $out] = $wait();
        $ctx->eq([$rc, trim($out), count(qf_texts($evo))], [0, 'quote_daily_list: deferred: master budget', 0], 'J12 nothing left of master\'s cycle: deferred', $out);
        [, $wait] = qf_jcron_bg($ctx, $b, ['_qf_test_now' => $t, '_m_deadline' => $t + 300]);
        [$rc, $out] = $wait();
        $ctx->eq([$rc, (bool)preg_match('/^quote_daily_list:( [a-z_]+=\d+)+$/', trim($out)), (bool)preg_match('/\d{9,}/', $out), count(qf_texts($evo)),
                  strpos($out, 'sent=1') !== false],
                 [0, true, false, 1, true], 'J12 the job sends the list and prints one line of counts: no number, no name', $out);
        $ss = $b; $ss['cfg']['tenant_profile'] = 'south-sudan';
        [, $wait] = qf_jcron_bg($ctx, $ss, ['_qf_test_now' => $t + 240]);
        [$rc, $out] = $wait();
        $ctx->eq([$rc, trim($out), count(qf_texts($evo))], [0, '', 1], 'J12 South Sudan: the job is quiet and sends nothing', $out);
    });

    $ctx->run('J13 pendingToday() is the ledger\'s staff_list demand: before 11:00 an enquiry on the sales handset leaves the list its place', function () use ($ctx, $evo) {
        putenv(WaSendBudget::TEST_SEAM_ENV . '=1');
        try {
            $b = qf_jbook($ctx, $evo, ['wa_send_daily_cap' => 3]); $p = $b['pdo'];
            qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
            qf_jrow($p, 'Q-J131', '256772502131', 101);
            $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => qf_t('2026-11-02 09:30:00'), 'last_run_at' => 'x', 'duration_ms' => 900]]);
            foreach ([1, 2] as $i) {
                $p->prepare("INSERT INTO wa_auto_sends (handset, kind, ref, mode, proactive, local_day, gap_s, claimed_at, outcome, settled_at)
                             VALUES ('i:sales-ug', 'reminder', ?, 'record', 1, '2026-11-02', 0, '2026-11-02 04:00:00', 'sent', '2026-11-02 04:00:00')")->execute(['n:j13-' . $i]);
            }
            $hs = function (string $ch) { return $ch === 'sales' ? 'Sales-UG' : 'DishNet-UG'; };
            $at = function (string $hm) { $t = qf_t('2026-11-02 ' . $hm); return function () use ($t) { return $t; }; };
            $l = WaSendBudget::forInstall($b['cfg'], $b['data'], $b['store'], $hs, $at('10:00:00'));
            $ctx->eq($l->claim('Sales-UG', 'enquiry', 'fu:1301:1', ['phone' => '256772502139'])['defer'] ?? 'ok', 'reserved',
                     'J13 10:00, the day\'s cap 3 with 2 used and one list still due: the enquiry is refused `reserved`');
            $l = WaSendBudget::forInstall($b['cfg'], $b['data'], $b['store'], $hs, $at('11:30:00'));
            $r = $l->claim('Sales-UG', 'enquiry', 'fu:1301:1', ['phone' => '256772502139']);
            $ctx->eq([$r['ok'] ?? false, $r['defer'] ?? null], [true, null], 'J13 11:30: no list is due any more, the enquiry is claimed');
        } finally {
            putenv(WaSendBudget::TEST_SEAM_ENV);
        }
    });

    $ctx->run('J14 the page: own quotations for a salesperson, every one for a manager; shut with the list off; no phone; no call prompt on a stop', function () use ($ctx) {
        require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';
        $sb = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'quote_tracking_enabled' => '1', 'quote_daily_list_enabled' => '1'], 'qfj');
        $sb->staff('admin', ['name' => 'Test Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
        $sa = $sb->staff('sa', ['name' => 'Test Seller A', 'email' => 'sa@example.test', 'role' => 'sales', 'phone' => '0772500141']);
        $sbb = $sb->staff('sb', ['name' => 'Test Seller B', 'email' => 'sb@example.test', 'role' => 'sales', 'phone' => '0772500142']);
        $p = $sb->store()->getPdo();
        qf_jrow($p, 'Q-J141', '256772502141', $sa, ['name' => 'Test Buyer Alpha']);
        qf_jrow($p, 'Q-J142', '256772502142', $sa, ['name' => 'Test Buyer Bravo']);
        $p->prepare("INSERT INTO contact_optouts (phone, reason, source) VALUES ('256772502142', 'customer_request', 'keyword')")->execute();
        qf_jrow($p, 'Q-J143', '256772502143', $sbb, ['name' => 'Test Buyer Charlie']);
        qf_jrow($p, 'Q-J144', '256772502144', null, ['name' => 'Test Buyer Delta']);
        $conv142 = (int)$p->query("SELECT spoke_conv_id FROM quote_tracker WHERE quote_key = 'ref:Q-J142'")->fetchColumn();
        foreach (['admin' => 'admin@example.test', 'sa' => 'sa@example.test', 'sb' => 'sb@example.test'] as $who => $em) $sb->login($who, $em, 'sj-password-1');
        $page = function (string $who) use ($sb): string { return $sb->page($who, 'page=dashboard&tab=my_quotes'); };
        $has = function (string $h, array $names): array { $o = []; foreach ($names as $n) $o[$n] = strpos($h, $n) !== false; return $o; };
        $all = ['Test Buyer Alpha', 'Test Buyer Bravo', 'Test Buyer Charlie', 'Test Buyer Delta'];
        $ha = $page('sa');
        $ctx->eq($has($ha, $all), ['Test Buyer Alpha' => true, 'Test Buyer Bravo' => true, 'Test Buyer Charlie' => false, 'Test Buyer Delta' => false],
                 'J14 a salesperson sees their own quotations only (QM55)', substr(strip_tags($ha), -1500));
        $ctx->eq([strpos($ha, 'tab=my_quotes') !== false, strpos($ha, 'conv_id=' . $conv142) === false, strpos($ha, 'Do not message') !== false,
                  (bool)preg_match('/(?:256)?77250214\d/', $ha), strpos($ha, 'Salesperson</th>') === false],
                 [true, true, true, false, true], 'J14 the menu entry; the stop row shows no call prompt; no phone; no owner column');
        $hb = $page('sb');
        $ctx->eq($has($hb, $all), ['Test Buyer Alpha' => false, 'Test Buyer Bravo' => false, 'Test Buyer Charlie' => true, 'Test Buyer Delta' => false], 'J14 the other salesperson: theirs only');
        $hm = $page('admin');
        $ctx->eq([$has($hm, $all), strpos($hm, 'Salesperson</th>') !== false, strpos($hm, 'Test Seller A') !== false, strpos($hm, 'No salesperson') !== false],
                 [array_fill_keys($all, true), true, true, true], 'J14 an admin (seesAll): every row, with the owner column');
        $cfg = $sb->cfg; $cfg['quote_daily_list_enabled'] = '0';
        $sb->store()->save('kyc_config.json', $cfg);
        file_put_contents($sb->data . '/kyc_config.json', json_encode($cfg));
        $ho = $page('sa');
        $ctx->eq([$has($ho, $all), strpos($ho, 'This page is not in use here.') !== false, strpos($ho, 'tab=my_quotes"') !== false],
                 [array_fill_keys($all, false), true, false], 'J14 the list off: the page shut and the menu entry gone', substr(strip_tags($ho), -800));
        $sb->stop();
    });

    // B3-fix1 (B3-R01; root docs/73 §H.2.11.1, §H.2.11.3, as OM84 for Part O): a list the ledger refuses for good
    // (tries_exhausted, final) holds back no other owner. Before the fix every later run took that owner again, and the
    // sweep wrote the rest missed no_attempt.
    $ctx->run('J15 an owner whose three tries certainly failed holds back no one: the next owner and the central list go before 11:00, and the sweep writes it alone missed (tries_exhausted)', function () use ($ctx, $evo) {
        qf_fake_evo();
        $b = qf_jbook($ctx, $evo); $p = $b['pdo'];
        qf_staff($p, 101, 'Test Seller One', true, ['phone' => '0772500101']);
        qf_staff($p, 102, 'Test Seller Two', true, ['phone' => '0772500102']);
        qf_jrow($p, 'Q-J151', '256772502151', 101);
        qf_jrow($p, 'Q-J152', '256772502152', 102);
        qf_jrow($p, 'Q-J153', '256772502153', null);
        $mine = function () use ($p): array {
            return array_map(function ($r) { return [$r['try'], $r['outcome']]; },
                             array_values(array_filter(qf_jledger($p), function ($r) { return $r['ref'] === 'list:2026-11-02:staff:101'; })));
        };
        qf_http('GET', $evo . '/__test/fail_instance?name=Sales-UG&code=400');   // a 4xx: certainly not sent
        foreach (['08:00', '08:04', '08:08'] as $hm) qf_jrun($ctx, $b, '2026-11-02 ' . $hm . ':00');
        qf_http('GET', $evo . '/__test/fail_instance?clear=1');
        $ctx->eq([$mine(), count(qf_texts($evo)), qf_state($p, 'list_last:2026-11-02:staff:101')], [[[1, 'not_sent'], [2, 'not_sent'], [3, 'not_sent']], 0, 'not_sent'],
                 'J15 control: the first owner\'s three tries, each certainly not sent, nothing else tried');
        $c = qf_jrun($ctx, $b, '2026-11-02 08:12:00');
        $ctx->eq([$c['deferred'], qf_state($p, 'list_last:2026-11-02:staff:101'), count($mine()), count(qf_texts($evo))], [1, 'tries_exhausted', 3, 0],
                 'J15 08:12: the fourth claim answers tries_exhausted, its code kept; no try, nothing sent', json_encode($c));
        $c = qf_jrun($ctx, $b, '2026-11-02 08:16:00');
        $tx = qf_texts($evo);
        $ctx->eq([$c['tries_spent'], $c['sent'], count($tx), preg_replace('/\D+/', '', (string)($tx[0]['number'] ?? ''))], [1, 1, 1, '256772500102'],
                 'J15 08:16: the spent owner passed over, the next owner\'s list sent', json_encode($c));
        $last = null;
        for ($t = qf_t('2026-11-02 08:20:00'); $t < qf_t('2026-11-02 11:00:00'); $t += 240) $last = qf_jrun($ctx, $b, $t);
        $tx = qf_texts($evo);
        $ctx->eq([count($tx), preg_replace('/\D+/', '', (string)($tx[1]['number'] ?? '')), count($mine())], [2, '256700500999', 3],
                 'J15 before 11:00 the central list goes too, and the spent owner is never tried again');
        $ctx->eq([$last['tries_spent'] ?? null, $last['none_due'] ?? null, $last['built'] ?? null, $last['deferred'] ?? null], [1, 0, 0, 0],
                 'J15 the last run before 11:00, the spent owner alone still due: passed over (tries_spent 1), built nothing, and not counted none_due', json_encode($last));
        $c = qf_jrun($ctx, $b, '2026-11-02 11:00:00');
        $by = [];
        foreach (qf_jlists($p) as $r) $by[$r['owner_key']][] = [$r['outcome'], $r['detail']];
        $ctx->eq([$c['swept'], $c['missed'], $by],
                 [1, 1, ['staff:101' => [['not_sent', 'send_failed'], ['not_sent', 'send_failed'], ['not_sent', 'send_failed'], ['missed', 'tries_exhausted']],
                         'staff:102' => [['sent', null]], 'central' => [['sent', null]]]],
                 'J15 the 11:00 sweep: only the spent owner missed, with its true code tries_exhausted; no other owner written missed no_attempt', json_encode($c));
    });
}];

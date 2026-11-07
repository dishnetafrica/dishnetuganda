<?php
declare(strict_types=1);
/**
 * Section F of tests/test_quote_followup.php — "a person took over" and the seen marks (5.18.104, root docs/73 §H.2.7,
 * §H.2.14 F; contract C7; rulings R3, R4). TEST ONLY.
 *
 * Built in stage B1: the cases that need no tracker and no chase — HumanTakeover::since() row by row (a sticky
 * human_active from before the moment does not count, an escalation after it does, our own echo stored as Team does
 * not, an AI captionless photo whose mark beat its claim does not), the Inbox's own replies through the real
 * wa_send_reply route in sender mode and in channel mode, since() as P5 calls it (received_at − 30 min), and the
 * webhook's marks W1–W3 through the real evo_webhook.php.
 *
 * Stage B2a (5.18.104, root docs/73 §H.2.9.7, §H.2.10.8) adds the tracker's stops, F13–F20: every row of §H.2.9.7 with
 * its fixed code, expiry, the order (STOP-only is opted_out), a status leaving open once with its follow-up closed for the
 * same reason, a follow-up closed elsewhere mapped back, customerStop() — the read preSendCheck and the after-claim re-read
 * make — and the Inbox's real wa_send_reply route stopping a tracked quotation; its fix round 1 adds F21 (registered only
 * for non-KYC quotations, on Kampala stamps). The chase's own stops (preSendCheck, the
 * escalation scan at opening, QM23-24, QM52) are sections D and G's, with lib/QuoteChase.php (stage B2b).
 */

/** A book, a conversation, and inserts as the live writers make them. */
function qf_conv(\PDO $pdo, string $phone, string $channel = 'support', ?string $category = null, string $state = 'bot_active'): int
{
    $pdo->prepare('INSERT INTO wa_conversations (phone, channel, category, state) VALUES (?, ?, ?, ?)')->execute([$phone, $channel, $category, $state]);
    return (int)$pdo->lastInsertId();
}

function qf_msg(\PDO $pdo, int $conv, array $m): void
{
    $m += ['direction' => 'out', 'role' => 'agent', 'body' => 'a message (test)', 'agent_name' => null, 'wa_message_id' => null,
           'event_key' => null, 'sent_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s')];
    $pdo->prepare('INSERT INTO wa_messages (conversation_id, direction, role, body, agent_name, wa_message_id, event_key, sent_at, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$conv, $m['direction'], $m['role'], $m['body'], $m['agent_name'], $m['wa_message_id'], $m['event_key'], $m['sent_at'], $m['created_at']]);
}

function qf_mark(\PDO $pdo, string $dir, string $phone, string $channel, string $id, string $seenAt, string $kind = 'audioMessage'): void
{
    $pdo->prepare('INSERT INTO wa_seen_marks (direction, phone, channel, instance, kind, wa_message_id, seen_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$dir, $phone, $channel, 'DishNet-UG', $kind, $id, $seenAt]);
}

function qf_ledger_id(\PDO $pdo, string $id, string $kind = 'other'): void
{
    $pdo->prepare("INSERT INTO wa_auto_sends (handset, kind, ref, mode, proactive, local_day, gap_s, outcome, wa_message_id)
                   VALUES ('i:dishnet-ug', ?, ?, 'record', ?, '2026-11-02', 60, 'sent', ?)")
        ->execute([$kind, 'n:' . ($id === '' ? bin2hex(random_bytes(4)) : $id), in_array($kind, ['reminder', 'winback', 'brief'], true) ? 1 : 0, $id === '' ? null : $id]);
}

function qf_escalation(\PDO $pdo, int $conv, string $createdAt): void
{
    $pdo->prepare("INSERT INTO events (event_type, entity_type, entity_id, payload, created_at, created_by) VALUES ('wa.escalation', 'conversation', ?, '{}', ?, 'test')")
        ->execute([$conv, $createdAt]);
    $pdo->prepare("UPDATE wa_conversations SET state = 'needs_human' WHERE id = ?")->execute([$conv]);
}

/** The webhook served from the copy, with a data directory and settings of its own. [base url, data dir, write settings]. */
function qf_webhook(QfCtx $ctx): array
{
    static $w = null;
    if ($w !== null) return $w;
    $tree = $ctx->tree();
    $data = $ctx->dir('wh-data');
    file_put_contents($tree . '/ucrm.json', json_encode(['pluginDataDir' => $data]));
    SqliteStore::create($data);   // first, so that the settings file below is read as written (a new store migrates JSON files)
    $set = function (array $cfg) use ($data): void {
        file_put_contents($data . '/kyc_config.json', json_encode($cfg + ['evo_webhook_secret' => 'qf-webhook-secret-test', 'evo_api_url' => 'http://127.0.0.1:9',
            'evo_api_key' => 'fake-key-qf']));
    };
    $set(QF_UG);
    [$port] = qf_serve('-t ' . escapeshellarg($tree), function (int $p): bool {
        return qf_http('GET', "http://127.0.0.1:{$p}/evo_webhook.php", null, [], 3)[0] === 405;
    }, 13800, ['DN_VAULT_FILE' => (string)getenv('DN_VAULT_FILE')]);
    return $w = [$port === 0 ? '' : "http://127.0.0.1:{$port}/evo_webhook.php", $data, $set];
}

function qf_post_message(string $url, array $msg, string $instance = 'DishNet-UG'): array
{
    return qf_http('POST', $url, ['event' => 'messages.upsert', 'instance' => $instance, 'data' => $msg], ['X-DishNet-Token: qf-webhook-secret-test']);
}

$QF_SECTIONS['F'] = ['title' => 'A person took over (C7) and the seen marks (§H.2.7)', 'run' => function (QfCtx $ctx) {
    $tree = $ctx->tree();
    foreach (['StoreInterface', 'SqliteStore', 'UtcClock', 'HumanTakeover', 'WaSeenMarks', 'EvoWebhookGuard', 'ConversationService'] as $l) require_once $tree . '/lib/' . $l . '.php';
    $S = '2026-11-02 10:00:00';                       // the moment: a quotation's send, UTC
    $after = '2026-11-02 11:00:00'; $before = '2026-11-02 09:00:00';

    $ctx->run('F01 since() row by row: each sign of a person, and nothing else', function () use ($ctx, $S, $after, $before) {
        $b = $ctx->book(QF_UG, 'f'); $p = $b['pdo'];
        $c = qf_conv($p, '256772500701');
        $ctx->eq(HumanTakeover::since($p, '256772500701', [$c], $S), null, 'F01 control: an empty conversation, null');
        qf_msg($p, $c, ['agent_name' => 'Team', 'wa_message_id' => 'WA-F01-T', 'sent_at' => $after]);
        $ctx->eq(HumanTakeover::since($p, '256772500701', [$c], $S), 'team_message', 'F01 a colleague\'s handset text after the moment: team_message');
        $ctx->eq(HumanTakeover::since($p, '256772500701', [$c], '2026-11-02 12:00:00'), null, 'F01 … but not after a later moment');
        $c2 = qf_conv($p, '256772500702');
        qf_msg($p, $c2, ['agent_name' => 'Sandbox Staff', 'event_key' => 'wa_staff_reply', 'sent_at' => $after]);
        $ctx->eq(HumanTakeover::since($p, '256772500702', [$c2], $S), 'inbox_reply', 'F01 an Inbox reply in a staff member\'s name, no message id (sender mode): inbox_reply');
        $c3 = qf_conv($p, '256772500703');
        qf_msg($p, $c3, ['agent_name' => 'Sandbox Staff', 'event_key' => 'wa_send_document', 'wa_message_id' => 'WA-F01-D', 'sent_at' => $after]);
        $ctx->eq(HumanTakeover::since($p, '256772500703', [$c3], $S), 'inbox_reply', 'F01 an Inbox document: inbox_reply');
        $c4 = qf_conv($p, '256772500704');
        qf_mark($p, 'out', '256772500704', 'support', 'WA-F01-M', $after);
        $ctx->eq(HumanTakeover::since($p, '256772500704', [$c4], $S), 'handset_media', 'F01 a handset mark (a colleague\'s voice note): handset_media');
        qf_mark($p, 'out', '256772500705', 'sales', 'WA-F01-M2', $after);
        $c5 = qf_conv($p, '256772500705', 'support');
        $ctx->eq(HumanTakeover::since($p, '256772500705', [$c5], $S), null, 'F01 a mark on a channel none of the conversations is on: null');
        qf_mark($p, 'in', '256772500706', 'support', 'WA-F01-I', $after, 'ephemeralMessage');
        $c6 = qf_conv($p, '256772500706');
        $ctx->eq(HumanTakeover::since($p, '256772500706', [$c6], $S), null, 'F01 an inbound mark is the customer, not a person on our side: null');
        $c7 = qf_conv($p, '256772500707');
        qf_escalation($p, $c7, $after);
        $ctx->eq(HumanTakeover::since($p, '256772500707', [$c7], $S), 'escalated', 'F01 handed to a person after the moment: escalated');
        $ctx->eq(HumanTakeover::personEvents(), ['wa_staff_reply', 'wa_send_document', 'wa_image_send', 'wa_media_send'], 'F01 personEvents(): the Inbox\'s four events');
    });

    $ctx->run('F02 the plugin\'s own automation is never a person (HT1)', function () use ($ctx, $S, $after) {
        $b = $ctx->book(QF_UG, 'f'); $p = $b['pdo'];
        $c = qf_conv($p, '256772500711');
        foreach (['DishNet', 'DishNet Plugin', 'DishNet AI', 'DishNet Bot', '', 'WhatsApp auto-reply'] as $i => $label) {
            qf_msg($p, $c, ['agent_name' => $label, 'sent_at' => $after, 'event_key' => $i === 1 ? 'wa_staff_reply' : null]);
        }
        qf_msg($p, $c, ['role' => 'assistant', 'agent_name' => 'DishNet AI', 'sent_at' => $after]);
        $ctx->eq(HumanTakeover::since($p, '256772500711', [$c], $S), null,
                 'F02 the enquiry engine (DishNet), the notifier (DishNet Plugin, even under an Inbox event), the AI, the bot and the auto-reply: null');
    });

    $ctx->run('F03 a sticky human_active from before the moment does not count (HT2); an escalation only after it (HT3)', function () use ($ctx, $S, $before, $after) {
        $b = $ctx->book(QF_UG, 'f'); $p = $b['pdo'];
        $c = qf_conv($p, '256772500721', 'support', null, 'human_active');
        qf_msg($p, $c, ['agent_name' => 'Team', 'wa_message_id' => 'WA-F03', 'sent_at' => $before, 'created_at' => $before]);
        $ctx->eq(HumanTakeover::since($p, '256772500721', [$c], $S), null, 'F03 human_active set by a colleague before the quotation, nothing since: null');
        $c2 = qf_conv($p, '256772500722');
        qf_escalation($p, $c2, $before);
        $ctx->eq(HumanTakeover::since($p, '256772500722', [$c2], $S), null, 'F03 an escalation before the moment: null');
        qf_escalation($p, $c2, $after);
        $ctx->eq(HumanTakeover::since($p, '256772500722', [$c2], $S), 'escalated', 'F03 control: one after it: escalated');
        $c3 = qf_conv($p, '256772500723');
        qf_escalation($p, $c3, $after);
        $p->prepare("UPDATE wa_conversations SET state = 'bot_active' WHERE id = ?")->execute([$c3]);
        $ctx->eq(HumanTakeover::since($p, '256772500723', [$c3], $S), null, 'F03 an escalation whose conversation is no longer waiting for a person: null');
    });

    $ctx->run('F04 our own echo stored as Team is ours (HT4); the exclusion is NULL-safe (HT11)', function () use ($ctx, $S, $after) {
        $b = $ctx->book(QF_UG, 'f'); $p = $b['pdo'];
        $c = qf_conv($p, '256772500731');
        qf_ledger_id($p, 'WA-F04-ECHO');
        qf_msg($p, $c, ['agent_name' => 'Team', 'wa_message_id' => 'WA-F04-ECHO', 'sent_at' => $after]);
        $ctx->eq(HumanTakeover::since($p, '256772500731', [$c], $S), null, 'F04 an echo that beat its claim, stored as Team under an id the ledger holds: null');
        $c2 = qf_conv($p, '256772500732');
        qf_ledger_id($p, '');      // a ledger row with no message id (a WASender-less failure, a hook with no id)
        qf_msg($p, $c2, ['agent_name' => 'Sandbox Staff', 'event_key' => 'wa_staff_reply', 'sent_at' => $after]);
        $ctx->eq(HumanTakeover::since($p, '256772500732', [$c2], $S), 'inbox_reply', 'F04 with NULL ids in the ledger, a sender-mode Inbox reply (no id) still counts');
    });

    $ctx->run('F05 the Inbox\'s image and media rows are dated in Kampala (HT5); its name is the staff member\'s (HT12)', function () use ($ctx, $S) {
        $b = $ctx->book(QF_UG, 'f'); $p = $b['pdo'];
        $c = qf_conv($p, '256772500741');
        qf_msg($p, $c, ['agent_name' => 'Sandbox Staff', 'event_key' => 'wa_image_send', 'sent_at' => '2026-11-02 12:30:00']);   // 09:30 UTC
        $ctx->eq(HumanTakeover::since($p, '256772500741', [$c], $S), null, 'F05 an Inbox image at 12:30 Kampala (09:30 UTC), before a 10:00 UTC moment: null');
        $c2 = qf_conv($p, '256772500742');
        qf_msg($p, $c2, ['agent_name' => 'Sandbox Staff', 'event_key' => 'wa_media_send', 'sent_at' => '2026-11-02 13:30:00']);   // 10:30 UTC
        $ctx->eq(HumanTakeover::since($p, '256772500742', [$c2], $S), 'inbox_reply', 'F05 one at 13:30 Kampala (10:30 UTC): inbox_reply');
        $c3 = qf_conv($p, '256772500743');
        qf_msg($p, $c3, ['agent_name' => 'Sandbox Staff', 'event_key' => 'wa_staff_reply', 'sent_at' => '2026-11-02 10:05:00']);
        $ctx->eq(HumanTakeover::since($p, '256772500743', [$c3], $S), 'inbox_reply', 'F05 an Inbox reply in a staff member\'s name, never Team: inbox_reply');
    });

    $ctx->run('F06 an AI captionless photo whose mark beat its claim is not a person (HT13); handset marks are (HT6)', function () use ($ctx, $S, $after) {
        $b = $ctx->book(QF_UG, 'f'); $p = $b['pdo'];
        $c = qf_conv($p, '256772500751');
        qf_mark($p, 'out', '256772500751', 'support', 'WA-F06-AI', $after, 'imageMessage');
        qf_msg($p, $c, ['role' => 'assistant', 'agent_name' => 'DishNet AI', 'body' => '[IMAGE]', 'wa_message_id' => 'WA-F06-AI', 'sent_at' => $after]);
        $ctx->eq(HumanTakeover::since($p, '256772500751', [$c], $S), null, 'F06 the AI\'s own row holds the mark\'s id: null');
        $c2 = qf_conv($p, '256772500752');
        qf_mark($p, 'out', '256772500752', 'support', 'WA-F06-PL', $after, 'documentWithCaptionMessage');
        qf_msg($p, $c2, ['agent_name' => 'DishNet Plugin', 'body' => '[DOC]', 'wa_message_id' => 'WA-F06-PL', 'sent_at' => $after]);
        $ctx->eq(HumanTakeover::since($p, '256772500752', [$c2], $S), null, 'F06 a notifier PDF\'s mark (captioned, wrapped): null');
        $c3 = qf_conv($p, '256772500753');
        qf_mark($p, 'out', '256772500753', 'support', 'WA-F06-LG', $after);
        qf_ledger_id($p, 'WA-F06-LG');
        $ctx->eq(HumanTakeover::since($p, '256772500753', [$c3], $S), null, 'F06 a mark whose id the ledger holds: null');
        $c4 = qf_conv($p, '256772500754');
        qf_mark($p, 'out', '256772500754', 'support', 'WA-F06-PERSON', $after);
        $ctx->eq(HumanTakeover::since($p, '256772500754', [$c4], $S), 'handset_media', 'F06 control: a mark nothing claims is a person');
    });

    $ctx->run('F07 the time argument: gmdate(\'c\') normalised (HT15), unreadable answers a code (HT18)', function () use ($ctx) {
        $b = $ctx->book(QF_UG, 'f'); $p = $b['pdo'];
        $c = qf_conv($p, '256772500761');
        qf_msg($p, $c, ['agent_name' => 'Team', 'wa_message_id' => 'WA-F07', 'sent_at' => '2026-11-02 10:30:00']);
        $ctx->eq(HumanTakeover::since($p, '256772500761', [$c], gmdate('c', (int)strtotime('2026-11-02 10:00:00 UTC'))), 'team_message',
                 'F07 a gmdate(\'c\') moment (its T sorts after the space) still finds a person later the same day');
        $ctx->eq(HumanTakeover::since($p, '256772500761', [$c], '2026-11-02T13:00:00+03:00'), 'team_message', 'F07 an offset form is read as written (10:00 UTC)');
        $ctx->eq(HumanTakeover::since($p, '256772500761', [$c], 'not a time'), 'unreadable_time', 'F07 an unreadable moment answers unreadable_time, never null');
        $ctx->eq(HumanTakeover::since($p, '256772500799', [], '2026-11-02 10:00:00'), null, 'F07 no conversation: null');
    });

    $ctx->run('F08 since() on an 088-shaped book: an absent 089 table reads as empty (HT19)', function () use ($ctx) {
        require_once $ctx->tree() . '/lib/MigrationRunner.php';
        $d = $ctx->dir('f-088');
        $pdo = new \PDO('sqlite:' . $d . '/plugin.sqlite3'); $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $mig = $ctx->dir('f-mig');
        foreach (glob($ctx->tree() . '/migrations/*.sql') ?: [] as $f) if (strcmp(basename($f), '089') < 0) copy($f, $mig . '/' . basename($f));
        (new MigrationRunner($pdo, $mig, $d . '/m.log'))->run();
        $ctx->t((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('wa_auto_sends','wa_seen_marks')")->fetchColumn() === 0, 'F08 control: the book has neither 089 table');
        $c = qf_conv($pdo, '256772500771');
        $ctx->eq(HumanTakeover::since($pdo, '256772500771', [$c], '2026-11-02 10:00:00'), null, 'F08 nothing since: null, no error');
        qf_msg($pdo, $c, ['agent_name' => 'Team', 'wa_message_id' => 'WA-F08', 'sent_at' => '2026-11-02 11:00:00']);
        $ctx->eq(HumanTakeover::since($pdo, '256772500771', [$c], '2026-11-02 10:00:00'), 'team_message', 'F08 a colleague\'s row: team_message, as with the tables present and empty');
    });

    $ctx->run('F09 since() as P5 calls it: received_at − 30 min (ruling R4)', function () use ($ctx) {
        $b = $ctx->book(QF_UG, 'f'); $p = $b['pdo'];
        $c = qf_conv($p, '256772500781');
        $received = '2026-11-02T10:00:00+00:00';            // the payload's gmdate('c')
        qf_mark($p, 'out', '256772500781', 'support', 'WA-F09-W1', '2026-11-02 09:50:00');   // a W1 mark 10 min before the voice note
        $since = gmdate('Y-m-d H:i:s', (int)strtotime($received) - 1800);
        $ctx->eq(HumanTakeover::since($p, '256772500781', [$c], $since), 'handset_media', 'F09 a W1 mark 10 min before the voice note stands P5 down');
        $ctx->eq(HumanTakeover::since($p, '256772500781', [$c], $received), null, 'F09 control: from received_at itself it would be missed (why the 30 min)');
    });

    $ctx->run('F10 WaSeenMarks: kindOf(), the switch and the gate, never a throw (HT8, HT9)', function () use ($ctx) {
        $ctx->eq(array_values(array_diff(['documentWithCaptionMessage', 'ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2'], WaSeenMarks::WRAPPERS)), [],
                 'F10 WRAPPERS holds every wrapper of dev:lib/InboundMedia.php:34');
        if (is_file($ctx->tree() . '/lib/InboundMedia.php')) {
            require_once $ctx->tree() . '/lib/InboundMedia.php';
            $ctx->eq(array_values(array_diff(InboundMedia::WRAPPERS, WaSeenMarks::WRAPPERS)), [], 'F10 … and of the ported InboundMedia::WRAPPERS');
        }
        $env = function (array $m) { return ['key' => ['id' => 'x'], 'message' => $m]; };
        $ctx->eq([WaSeenMarks::kindOf($env(['messageContextInfo' => [], 'ephemeralMessage' => ['message' => ['conversation' => 'STOP']]]), 'in'),
                  WaSeenMarks::kindOf($env(['messageContextInfo' => [], 'audioMessage' => []]), 'in'),
                  WaSeenMarks::kindOf($env(['messageContextInfo' => [], 'audioMessage' => []]), 'out'),
                  WaSeenMarks::kindOf($env(['viewOnceMessageV2' => []]), 'out'),
                  WaSeenMarks::kindOf($env(['reactionMessage' => ['text' => '+']]), 'out'),
                  WaSeenMarks::kindOf($env(['protocolMessage' => []]), 'in'),
                  WaSeenMarks::kindOf($env(['protocolMessage' => []]), 'out'),
                  WaSeenMarks::kindOf(['key' => []], 'out')],
                 ['ephemeralMessage', null, 'audioMessage', 'viewOnceMessageV2', null, null, null, null],
                 'F10 kindOf(): in → a wrapper; out → a wrapper or media; a reaction or protocol message → null; the keys looked for, never the first');
        $msg = $env(['audioMessage' => []]);
        $b = $ctx->book(QF_UG, 'f'); $p = $b['pdo'];
        WaSeenMarks::note($p, ['wa_send_ledger' => 'off'] + $b['cfg'], $b['data'], 'out', '256772500791', 'support', 'DishNet-UG', 'WA-F10-OFF', $msg);
        WaSeenMarks::note($p, ['tenant_profile' => 'south-sudan'] + $b['cfg'], $b['data'], 'out', '256772500792', 'support', 'DishNet-UG', 'WA-F10-SS', $msg);
        WaSeenMarks::note($p, $b['cfg'], $b['data'], 'out', '256772500793', 'support', 'DishNet-UG', 'WA-F10-R', $env(['reactionMessage' => []]));
        $ctx->eq((int)$p->query('SELECT COUNT(*) FROM wa_seen_marks')->fetchColumn(), 0, 'F10 no mark with the ledger off, on South Sudan, or for a reaction');
        WaSeenMarks::note($p, $b['cfg'], $b['data'], 'out', '+256 772 500 794', 'support', 'DishNet-UG', 'WA-F10-ON', $msg);
        WaSeenMarks::noNumber($p, $b['cfg'], $b['data'], 'support', 'DishNet-UG', 'WA-F10-LID');
        $got = $p->query('SELECT direction, phone, channel, kind, wa_message_id FROM wa_seen_marks ORDER BY id')->fetchAll(\PDO::FETCH_NUM);
        $ctx->eq($got, [['out', '256772500794', 'support', 'audioMessage', 'WA-F10-ON'], ['in', '', 'support', 'lid_no_number', 'WA-F10-LID']],
                 'F10 control: on Uganda with the ledger on, the mark (digits, no text) and the @lid count');
        $p->exec('DROP TABLE wa_seen_marks');
        $l0 = $ctx->logLines('mark_write_lost'); $threw = '';
        try { WaSeenMarks::note($p, $b['cfg'], $b['data'], 'out', '256772500795', 'support', 'DishNet-UG', 'WA-F10-X', $msg); } catch (\Throwable $e) { $threw = $e->getMessage(); }
        $ctx->eq([$threw, $ctx->logLines('mark_write_lost') - $l0], ['', 1], 'F10 a mark that cannot be written never throws into the webhook, and says so once');
    });

    $ctx->run('F11 the webhook\'s marks W1–W3, through the real evo_webhook.php', function () use ($ctx) {
        [$url, $data, $set] = qf_webhook($ctx);
        $ctx->t($url !== '', 'F11 control: the webhook is served from the copy');
        if ($url === '') return;
        $db = function () use ($data): \PDO { $p = new \PDO('sqlite:' . $data . '/plugin.sqlite3'); $p->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION); return $p; };
        $marks = function (string $id) use ($db): array {
            $st = $db()->prepare('SELECT direction, phone, channel, instance, kind FROM wa_seen_marks WHERE wa_message_id = ?'); $st->execute([$id]);
            return $st->fetchAll(\PDO::FETCH_NUM);
        };
        $fromMe = function (string $id, array $message, string $jid = '256772500801@s.whatsapp.net') {
            return ['key' => ['remoteJid' => $jid, 'fromMe' => true, 'id' => $id], 'message' => $message, 'messageTimestamp' => time()];
        };
        $inbound = function (string $id, array $message, string $jid = '256772500802@s.whatsapp.net', array $key = []) {
            return ['key' => $key + ['remoteJid' => $jid, 'fromMe' => false, 'id' => $id], 'message' => $message, 'messageTimestamp' => time(), 'pushName' => 'Test'];
        };
        $r = qf_post_message($url, $fromMe('WA-W1-AUDIO', ['audioMessage' => ['seconds' => 4]]));
        $ctx->eq([$r[0], $marks('WA-W1-AUDIO')], [200, [['out', '256772500801', 'support', 'DishNet-UG', 'audioMessage']]],
                 'W1 a colleague\'s voice note from the handset leaves a content-free mark');
        $ctx->eq((int)$db()->query("SELECT COUNT(*) FROM wa_messages WHERE wa_message_id = 'WA-W1-AUDIO'")->fetchColumn(), 0, 'W1 … and stores nothing in wa_messages');
        // our own echo, claimed by the sender before it arrives (HT10)
        $cfg = QF_UG + ['evo_webhook_secret' => 'qf-webhook-secret-test'];
        (new EvoWebhookGuard($db(), $cfg, $data))->claim('WA-W1-ECHO', 'DishNet-UG', 'notify.support');
        qf_post_message($url, $fromMe('WA-W1-ECHO', ['documentMessage' => ['fileName' => 'x.pdf']]));
        $ctx->eq($marks('WA-W1-ECHO'), [], 'W1 our own echo, claimed at the send, never reaches the mark (W1 sits after step 7)');
        qf_post_message($url, $fromMe('WA-W1-REACT', ['reactionMessage' => ['text' => '+']]));
        $ctx->eq($marks('WA-W1-REACT'), [], 'W1 a reaction from the handset leaves no mark');
        qf_post_message($url, $fromMe('WA-W1-TEXT', ['conversation' => 'A typed reply (test)']));
        $ctx->eq($marks('WA-W1-TEXT'), [], 'W1 a typed reply is stored as before, not marked');
        qf_post_message($url, $inbound('WA-W2-EPH', ['ephemeralMessage' => ['message' => ['conversation' => 'STOP']]]));
        $ctx->eq($marks('WA-W2-EPH'), [['in', '256772500802', 'support', 'DishNet-UG', 'ephemeralMessage']], 'W2 a disappearing message the store cannot read leaves an inbound mark');
        qf_post_message($url, $inbound('WA-W2-AUDIO', ['audioMessage' => ['seconds' => 3]], '256772500803@s.whatsapp.net'));
        $ctx->eq($marks('WA-W2-AUDIO'), [], 'W2 a plain voice note is stored, not marked');
        qf_post_message($url, $inbound('WA-W2-REACT', ['reactionMessage' => ['text' => '+']], '256772500804@s.whatsapp.net'));
        $ctx->eq($marks('WA-W2-REACT'), [], 'W2 an inbound reaction leaves no mark');
        qf_post_message($url, $inbound('WA-W3-LID', ['conversation' => 'hello'], '1234567890123@lid'));
        $ctx->eq($marks('WA-W3-LID'), [['in', '', 'support', 'DishNet-UG', 'lid_no_number']], 'W3 an @lid message with no number is counted (lid_no_number)');
        $off = QF_UG; unset($off['wa_send_ledger']); $set($off);
        qf_post_message($url, $fromMe('WA-W1-OFF', ['audioMessage' => []]));
        qf_post_message($url, $inbound('WA-W2-OFF', ['viewOnceMessage' => ['message' => ['imageMessage' => []]]], '256772500805@s.whatsapp.net'));
        qf_post_message($url, $inbound('WA-W3-OFF', ['conversation' => 'hello'], '1234567890124@lid'));
        $ctx->eq([$marks('WA-W1-OFF'), $marks('WA-W2-OFF'), $marks('WA-W3-OFF')], [[], [], []], 'W1–W3 with the ledger off: no mark');
        $set(['tenant_profile' => 'south-sudan'] + QF_UG);
        qf_post_message($url, $fromMe('WA-W1-SS', ['audioMessage' => []]));
        qf_post_message($url, $inbound('WA-W3-SS', ['conversation' => 'hello'], '1234567890125@lid'));
        $ctx->eq([$marks('WA-W1-SS'), $marks('WA-W3-SS')], [[], []], 'W1–W3 on South Sudan, even with the key set: no mark');
        $set(QF_UG);
    });

    $ctx->run('F12 the Inbox\'s own replies through the real wa_send_reply route: a person (sender and channel modes)', function () use ($ctx) {
        require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';
        $s = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'wa_send_ledger' => 'on'], 'qf12');
        $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
        $pdo = $s->store()->getPdo();
        $cs = new ConversationService($s->data, $pdo);
        $sup = (int)$cs->ensureConversation('256772500811', 'support')['id'];
        $sal = (int)$cs->ensureConversation('256772500812', 'sales')['id'];
        $since = gmdate('Y-m-d H:i:s', time() - 60);
        $ctx->eq([HumanTakeover::since($pdo, '256772500811', [$sup], $since), HumanTakeover::since($pdo, '256772500812', [$sal], $since)], [null, null], 'F12 control: nothing yet');
        $r1 = $s->api('admin', 'POST', 'wa_send_reply', ['conversation_id' => $sup, 'message' => 'A reply from the Inbox (test)']);
        $r2 = $s->api('admin', 'POST', 'wa_send_reply', ['conversation_id' => $sal, 'message' => 'A reply on the sales number (test)']);
        $ctx->eq([$r1[0], $r2[0]], [200, 200], 'F12 control: both replies were sent');
        $ctx->eq(HumanTakeover::since($pdo, '256772500811', [$sup], $since), 'inbox_reply', 'F12 a support conversation (sender mode, no message id): inbox_reply');
        $ctx->eq(HumanTakeover::since($pdo, '256772500812', [$sal], $since), 'inbox_reply', 'F12 a sales conversation (channel mode): inbox_reply');
        $ctx->eq((int)$pdo->query("SELECT COUNT(*) FROM wa_auto_sends")->fetchColumn(), 0, 'F12 neither reply is filed in the ledger as automation');
        $s->stop();
    });

    // ── 5.18.104 stage B2a: the tracker's stops (§H.2.9.7), read every run, and the reads the chase makes again ────────
    require_once $tree . '/lib/QuoteSendEvidence.php';
    require_once $tree . '/lib/QuoteTracker.php';
    require_once $tree . '/lib/FollowUpService.php';
    $QS = '2026-11-02 07:00:00';          // the quotation's send, UTC (Monday 10:00 Kampala)
    $QL = '2026-11-02 10:00:00';          // the same moment as Kampala dates it (date(): KYC, cashbook, collections)
    $q = function (array $b, string $ph, string $ref, string $channel = 'support', array $log = []) use ($QS): int {
        $c = qf_conv($b['pdo'], $ph, $channel);
        if ($log !== []) qf_blob($b['pdo'], 'quotes_log', $log + ['type' => 'manual', 'quote_ref' => $ref, 'sent_by' => 'nobody']);
        qf_qmsg($b['pdo'], $c, 'quote_manual', qf_ref_text($ref, '05 Nov 2026'), $QS);
        return $c;
    };
    $status = function (\PDO $p, string $ref): array {
        $r = qf_trow($p, 'ref:' . $ref);
        return $r === null ? ['missing'] : [$r['status'], $r['status_reason']];
    };

    $ctx->run('F13 every stop of §H.2.9.7 through the tracker, each with its fixed code (QM6, QM16, QM18, QM19, HT7)', function () use ($ctx, $q, $status, $QS, $QL) {
        $b = $ctx->book(qf_tcfg(), 'f13'); $p = $b['pdo'];
        $after = '2026-11-02 08:00:00'; $afterL = '2026-11-02 11:00:00';
        $set = [];
        // opted_out: a live opt-out on another channel and scope; a STOP in the chat; a lifted opt-out is none
        $q($b, '256772502001', 'Q-8001');
        $p->exec("INSERT INTO contact_optouts (phone, channel, scope, reason, source) VALUES ('256772502001', 'sales', 'all', 'customer_request', 'keyword')");
        $c = $q($b, '256772502002', 'Q-8002'); qf_in($p, $c, 'STOP', $after);
        $q($b, '256772502003', 'Q-8003');
        $p->exec("INSERT INTO contact_optouts (phone, channel, scope, reason, source, active, lifted_at) VALUES ('256772502003', '*', 'proactive', 'customer_request', 'keyword', 0, '2026-11-01 00:00:00')");
        // answered: a reply; a voice note; a reply on another channel; a device-dated reply stored after the send; an inbound mark
        $c = $q($b, '256772502011', 'Q-8011'); qf_in($p, $c, 'Thanks, I will think (test)', $after);
        $c = $q($b, '256772502012', 'Q-8012'); qf_in($p, $c, '[AUDIO]', $after);
        $q($b, '256772502013', 'Q-8013'); qf_in($p, qf_conv($p, '256772502013', 'sales'), 'On the sales number (test)', $after);
        $c = $q($b, '256772502014', 'Q-8014'); qf_in($p, $c, 'Device clock behind (test)', '2026-11-02 06:59:00', $after);
        $q($b, '256772502015', 'Q-8015'); qf_mark($p, 'in', '256772502015', 'support', 'WA-F13-MARK', $after, 'ephemeralMessage');
        $c = $q($b, '256772502016', 'Q-8016'); qf_in($p, $c, 'Before the quotation (test)', '2026-11-02 06:30:00');
        // taken: a colleague's handset text; an escalation after; a sticky human_active before; our own echo stored as Team
        $c = $q($b, '256772502021', 'Q-8021'); qf_msg($p, $c, ['agent_name' => 'Team', 'wa_message_id' => 'WA-F13-T', 'sent_at' => $after, 'created_at' => $after]);
        $c = $q($b, '256772502022', 'Q-8022'); qf_escalation($p, $c, $after);
        $c = $q($b, '256772502023', 'Q-8023'); $p->prepare("UPDATE wa_conversations SET state = 'human_active' WHERE id = ?")->execute([$c]);
        qf_msg($p, $c, ['agent_name' => 'Team', 'wa_message_id' => 'WA-F13-OLD', 'sent_at' => '2026-11-02 06:00:00', 'created_at' => '2026-11-02 06:00:00']);
        $c = $q($b, '256772502024', 'Q-8024'); qf_ledger_id($p, 'WA-F13-ECHO'); qf_msg($p, $c, ['agent_name' => 'Team', 'wa_message_id' => 'WA-F13-ECHO', 'sent_at' => $after]);
        // paid: a receipt to p; a cashbook receipt, a collection, a Cash KYC for the client — each after the send
        $c = $q($b, '256772502031', 'Q-8031'); qf_msg($p, $c, ['agent_name' => 'DishNet Plugin', 'event_key' => 'ops_payment_received', 'sent_at' => $after]);
        $p->exec('ALTER TABLE cb_ledger ADD COLUMN crm_payment_id INTEGER NOT NULL DEFAULT 0');
        $p->exec('ALTER TABLE cb_ledger ADD COLUMN crm_client_id INTEGER NOT NULL DEFAULT 0');
        $q($b, '256772502032', 'Q-8032', 'support', ['crm_client_id' => 832]);
        $p->exec("INSERT INTO cb_ledger (direction, amount, crm_payment_id, crm_client_id, created_at) VALUES ('in', 100, 5501, 832, '{$afterL}')");
        $q($b, '256772502033', 'Q-8033', 'support', ['crm_client_id' => 833]);
        qf_blob($p, 'payment_collections', ['crm_customer_id' => '833', 'amount' => 100, 'created_at' => $afterL]);
        $q($b, '256772502034', 'Q-8034', 'support', ['crm_client_id' => 834]);
        qf_blob($p, 'kyc_applications', ['crm_client_id' => 834, 'sales_type' => 'Cash', 'amount_charged' => 100, 'submitted_at' => $afterL]);
        $q($b, '256772502035', 'Q-8035', 'support', ['crm_client_id' => 835]);
        $p->exec("INSERT INTO cb_ledger (direction, amount, crm_payment_id, crm_client_id, created_at) VALUES ('in', 100, 5502, 835, '2026-11-02 09:59:00')");
        // registered (non-KYC quotations): a KYC with the client; one with p as its mobile
        $q($b, '256772502041', 'Q-8041', 'support', ['crm_client_id' => 841]);
        qf_blob($p, 'kyc_applications', ['crm_client_id' => 841, 'sales_type' => 'Credit', 'submitted_at' => $afterL]);
        $q($b, '256772502042', 'Q-8042');
        qf_blob($p, 'kyc_applications', ['mobile' => '0772502042', 'sales_type' => 'Credit', 'submitted_at' => $afterL]);
        // accepted: ops_quote_approved holding #<ref>; one for another quotation sharing its prefix is not
        $c = $q($b, '256772502051', 'Q-8051'); qf_msg($p, $c, ['agent_name' => 'DishNet Plugin', 'event_key' => 'ops_quote_approved', 'body' => 'Thank you for approving Quote *#Q-8051*!', 'sent_at' => $after]);
        $c = $q($b, '256772502052', 'Q-8052'); qf_msg($p, $c, ['agent_name' => 'DishNet Plugin', 'event_key' => 'ops_quote_approved', 'body' => 'Thank you for approving Quote *#Q-80521*!', 'sent_at' => $after]);
        // superseded: a newer quotation to p
        $c = $q($b, '256772502061', 'Q-8061'); qf_qmsg($p, $c, 'quote_manual', qf_ref_text('Q-8062', '05 Nov 2026'), $after);
        // a control: nothing after the send
        $q($b, '256772502099', 'Q-8099');
        qf_track($b, '2026-11-02 12:00:00');
        foreach (['Q-8001', 'Q-8002', 'Q-8003', 'Q-8011', 'Q-8012', 'Q-8013', 'Q-8014', 'Q-8015', 'Q-8016', 'Q-8021', 'Q-8022', 'Q-8023', 'Q-8024',
                  'Q-8031', 'Q-8032', 'Q-8033', 'Q-8034', 'Q-8035', 'Q-8041', 'Q-8042', 'Q-8051', 'Q-8052', 'Q-8061', 'Q-8062', 'Q-8099'] as $r) $set[$r] = $status($p, $r);
        $ctx->eq(array_slice($set, 0, 3), ['Q-8001' => ['opted_out', 'optout_row'], 'Q-8002' => ['opted_out', 'stop_message'], 'Q-8003' => ['open', null]],
                 'F13 opted_out: a live opt-out on any channel and scope; a STOP in the chat; a lifted one is not');
        $ctx->eq(array_slice($set, 3, 6), ['Q-8011' => ['answered', 'reply'], 'Q-8012' => ['answered', 'reply'], 'Q-8013' => ['answered', 'reply'],
                 'Q-8014' => ['answered', 'reply'], 'Q-8015' => ['answered', 'unread_inbound'], 'Q-8016' => ['open', null]],
                 'F13 answered: a reply, a voice note, a reply on another channel, one device-dated before but stored after, an inbound mark; before the send is not');
        $ctx->eq(array_slice($set, 9, 4), ['Q-8021' => ['taken', 'team_message'], 'Q-8022' => ['taken', 'escalated'], 'Q-8023' => ['open', null], 'Q-8024' => ['open', null]],
                 'F13 taken: a colleague\'s text, an escalation after; a sticky human_active from before, and our own echo as Team, are not');
        $ctx->eq(array_slice($set, 13, 5), ['Q-8031' => ['paid', 'payment_message'], 'Q-8032' => ['paid', 'cashbook'], 'Q-8033' => ['paid', 'collection'],
                 'Q-8034' => ['paid', 'cash_kyc'], 'Q-8035' => ['open', null]],
                 'F13 paid: a receipt, a cashbook receipt, a collection, a Cash KYC — each after the send; a cashbook receipt before it is not');
        $ctx->eq(array_slice($set, 18, 2), ['Q-8041' => ['registered', 'kyc_client'], 'Q-8042' => ['registered', 'kyc_phone']], 'F13 registered: a KYC with the client, or with p as its mobile');
        $ctx->eq(array_slice($set, 20, 2), ['Q-8051' => ['accepted', 'quote_approved'], 'Q-8052' => ['open', null]], 'F13 accepted: #<ref> in an approval; another number sharing its prefix is not');
        $ctx->eq(array_slice($set, 22), ['Q-8061' => ['superseded', 'newer_quote'], 'Q-8062' => ['open', null], 'Q-8099' => ['open', null]],
                 'F13 superseded: the older of two quotations to p; the newer, and the control, stay open');
    });

    $ctx->run('F14 expiry: after 20:00 Kampala on valid_until (QM9)', function () use ($ctx, $q, $status) {
        $b = $ctx->book(qf_tcfg(), 'f14'); $p = $b['pdo'];
        $q($b, '256772502101', 'Q-8101');   // valid until 05 Nov 2026 (the stated date)
        qf_track($b, '2026-11-05 19:59:59');
        $ctx->eq($status($p, 'Q-8101'), ['open', null], 'F14 at 19:59:59 on valid_until: open');
        qf_track($b, '2026-11-05 20:00:01');
        $ctx->eq($status($p, 'Q-8101'), ['expired', 'valid_until'], 'F14 at 20:00:01: expired');
    });

    $ctx->run('F15 the order: STOP-only is opted_out, never answered (QM22); the first stop that holds wins', function () use ($ctx, $q, $status) {
        $b = $ctx->book(qf_tcfg(), 'f15'); $p = $b['pdo'];
        $c = $q($b, '256772502201', 'Q-8201'); qf_in($p, $c, 'Stop', '2026-11-02 08:00:00');
        $c = $q($b, '256772502202', 'Q-8202'); qf_in($p, $c, 'How much is the kit? (test)', '2026-11-02 08:00:00'); qf_in($p, $c, 'STOP', '2026-11-02 09:00:00');
        $c = $q($b, '256772502203', 'Q-8203', 'support', ['crm_client_id' => 8203]); qf_in($p, $c, 'Paid now (test)', '2026-11-02 08:00:00');
        qf_blob($p, 'kyc_applications', ['crm_client_id' => 8203, 'sales_type' => 'Cash', 'amount_charged' => 100, 'submitted_at' => '2026-11-02 11:30:00']);
        qf_track($b, '2026-11-02 13:00:00');
        $ctx->eq([$status($p, 'Q-8201'), $status($p, 'Q-8202'), $status($p, 'Q-8203')], [['opted_out', 'stop_message'], ['opted_out', 'stop_message'], ['answered', 'reply']],
                 'F15 a STOP alone: opted_out; a question then a STOP: opted_out (first in order); a reply and a payment: answered');
    });

    $ctx->run('F16 a status leaves open once; the linked follow-up is closed with the same reason', function () use ($ctx, $q, $status) {
        $b = $ctx->book(qf_tcfg(), 'f16'); $p = $b['pdo'];
        $c = $q($b, '256772502301', 'Q-8301', 'support', ['crm_client_id' => 8301]);
        qf_track($b, '2026-11-02 11:00:00');
        $p->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, kind, quote_key, opened_by, max_attempts) VALUES (?, 'support', '256772502301', ?, 'quote', 'ref:Q-8301', 'quote_chase', 3)")
          ->execute([$c, '2026-11-02 07:00:00']);
        $fu = (int)$p->lastInsertId();
        $p->prepare("UPDATE quote_tracker SET followup_id = ? WHERE quote_key = 'ref:Q-8301'")->execute([$fu]);
        qf_in($p, $c, 'Thank you (test)', '2026-11-02 08:30:00');
        qf_track($b, '2026-11-02 12:00:00');
        $fr = $p->query("SELECT closed_at IS NOT NULL AS closed, close_reason FROM followups WHERE id = {$fu}")->fetch(\PDO::FETCH_ASSOC);
        $ctx->eq([$status($p, 'Q-8301'), (int)$fr['closed'], $fr['close_reason']], [['answered', 'reply'], 1, 'replied'], 'F16 answered: the follow-up closed replied');
        qf_blob($p, 'kyc_applications', ['crm_client_id' => 8301, 'sales_type' => 'Cash', 'amount_charged' => 100, 'submitted_at' => '2026-11-02 13:00:00']);
        qf_track($b, '2026-11-02 14:00:00');
        $threw = '';
        try { $p->exec("UPDATE quote_tracker SET status = 'paid' WHERE quote_key = 'ref:Q-8301'"); } catch (\Throwable $e) { $threw = $e->getMessage(); }
        $ctx->eq([$status($p, 'Q-8301'), strpos($threw, 'leaves open once') !== false, QuoteTracker::setStatus($p, 'ref:Q-8301', 'paid', 'x', time())],
                 [['answered', 'reply'], true, false], 'F16 a later payment changes nothing: a status leaves open once (qt_status_once), and setStatus() says so');
    });

    $ctx->run('F17 a linked follow-up closed by another job maps back (§H.2.10.8; QM46)', function () use ($ctx, $q, $status) {
        $b = $ctx->book(qf_tcfg(), 'f17'); $p = $b['pdo'];
        $reasons = ['replied', 'human_closed', 'staff_cancelled', 'escalated', 'converted', 'not_interested', 'staff', 'opted_out',
                    'exhausted', 'send_failed', 'cancelled', 'channel_assistant_disabled', 'rollback'];
        $convs = [];
        foreach ($reasons as $i => $why) $convs[$why] = $q($b, '2567725024' . sprintf('%02d', $i), 'Q-84' . sprintf('%02d', $i));
        qf_track($b, '2026-11-02 10:30:00');
        foreach ($reasons as $i => $why) {
            $p->prepare("INSERT INTO followups (conversation_id, channel, phone, last_customer_at, kind, quote_key, opened_by) VALUES (?, 'support', ?, ?, 'quote', ?, 'quote_chase')")
              ->execute([$convs[$why], '2567725024' . sprintf('%02d', $i), '2026-11-02 07:00:00', 'ref:Q-84' . sprintf('%02d', $i)]);
            $fu = (int)$p->lastInsertId();
            $p->prepare("UPDATE quote_tracker SET followup_id = ? WHERE quote_key = ?")->execute([$fu, 'ref:Q-84' . sprintf('%02d', $i)]);
            (new FollowUpService($p))->close($fu, $why, 'test', 'test');
        }
        qf_track($b, '2026-11-02 10:40:00');
        $got = [];
        foreach ($reasons as $i => $why) { $r = qf_trow($p, 'ref:Q-84' . sprintf('%02d', $i)); $got[$why] = [$r['status'], $r['chase_hold']]; }
        $ctx->eq($got, ['replied' => ['answered', null], 'human_closed' => ['taken', null], 'staff_cancelled' => ['taken', null], 'escalated' => ['taken', null],
                        'converted' => ['accepted', null], 'not_interested' => ['declined', null], 'staff' => ['colleague', null], 'opted_out' => ['opted_out', null],
                        'exhausted' => ['open', null], 'send_failed' => ['open', 'send_failed'], 'cancelled' => ['open', 'cancelled'],
                        'channel_assistant_disabled' => ['open', 'channel_assistant_disabled'], 'rollback' => ['open', 'rollback']],
                 'F17 each close reason as §H.2.10.8 maps it: a status; exhausted stays open; every other code a chase_hold');
        $ctx->eq(qf_trow($p, 'ref:Q-8400')['status_reason'], 'followup:replied', 'F17 the reason names the follow-up\'s close');
    });

    $ctx->run('F18 customerStop(): what preSendCheck and the after-claim re-read ask (a reply on another channel, a mark; QM3, QM52)', function () use ($ctx, $q) {
        $b = $ctx->book(qf_tcfg(), 'f18'); $p = $b['pdo'];
        $q($b, '256772502501', 'Q-8501');
        qf_track($b, '2026-11-02 10:30:00');
        $row = function () use ($p) { return qf_trow($p, 'ref:Q-8501'); };
        $now = qf_t('2026-11-02 12:00:00');
        $ctx->eq(QuoteTracker::customerStop($p, $b['cfg'], $b['data'], $row(), $now), null, 'F18 control: nothing since the send');
        qf_qmsg($p, qf_conv($p, '256772502501', 'accounts'), 'quote_manual', qf_ref_text('Q-8502'), '2026-11-02 08:00:00');
        qf_track($b, '2026-11-02 11:05:00');
        $ctx->eq([QuoteTracker::customerStop($p, $b['cfg'], $b['data'], $row(), $now), QuoteTracker::stop($p, $b['cfg'], $b['data'], $row(), $now), $row()['status']],
                 [null, ['superseded', 'newer_quote'], 'superseded'], 'F18 a newer quotation is no customer stop (the tracker\'s own), but is a stop');
        qf_mark($p, 'in', '256772502501', 'support', 'WA-F18-M', '2026-11-02 08:10:00', 'viewOnceMessage');
        $ctx->eq(QuoteTracker::customerStop($p, $b['cfg'], $b['data'], $row(), $now), ['answered', 'unread_inbound'], 'F18 an inbound mark: answered, unread_inbound');
        qf_in($p, qf_conv($p, '256772502501', 'sales'), 'Replying on sales (test)', '2026-11-02 08:20:00');
        $ctx->eq(QuoteTracker::customerStop($p, $b['cfg'], $b['data'], $row(), $now), ['answered', 'reply'], 'F18 a reply on another channel, between drafting and sending: answered');
        $p->exec("INSERT INTO contact_optouts (phone, reason, source) VALUES ('256772502501', 'customer_request', 'keyword')");
        $ctx->eq(QuoteTracker::customerStop($p, $b['cfg'], $b['data'], $row(), $now), ['opted_out', 'optout_row'], 'F18 then an opt-out: opted_out comes first');
        $ctx->eq([QuoteTracker::ucrmStatus(2), QuoteTracker::ucrmStatus('3'), QuoteTracker::ucrmStatus(4), QuoteTracker::ucrmStatus(1), QuoteTracker::ucrmStatus(null)],
                 ['accepted', 'declined', 'declined', null, null], 'F18 ucrmStatus(): 2 accepted, 3 or 4 declined, anything else or a failed read none');
    });

    $ctx->run('F19 an Inbox reply after the quotation, through the real wa_send_reply route, stops it as taken (sender and channel modes)', function () use ($ctx) {
        require_once dirname(__DIR__) . '/fixtures/staff_jobs_sandbox.php';
        $s = SjSandbox::start($ctx->root, ['tenant_profile' => 'uganda', 'timezone' => QF_TZ, 'wa_send_ledger' => 'on'], 'qf19');
        $s->staff('admin', ['name' => 'Sandbox Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'is_admin' => true]);
        $store = $s->store(); $pdo = $store->getPdo();
        $cs = new ConversationService($s->data, $pdo);
        $sent = gmdate('Y-m-d H:i:s', time() - 120);
        $sup = (int)$cs->ensureConversation('256772502601', 'support')['id'];
        $sal = (int)$cs->ensureConversation('256772502602', 'sales')['id'];
        qf_qmsg($pdo, $sup, 'quote_manual', qf_ref_text('Q-8601'), $sent);
        qf_qmsg($pdo, $sal, 'quote_manual', qf_ref_text('Q-8602'), $sent);
        $b = ['pdo' => $pdo, 'store' => $store, 'data' => $s->data, 'cfg' => qf_tcfg(['_data_dir' => $s->data], gmdate('Y-m-d H:i:s', time() - 3600))];
        qf_track($b, time());
        $ctx->eq([qf_trow($pdo, 'ref:Q-8601')['status'], qf_trow($pdo, 'ref:Q-8602')['status']], ['open', 'open'], 'F19 control: both open before any reply');
        $r1 = $s->api('admin', 'POST', 'wa_send_reply', ['conversation_id' => $sup, 'message' => 'A reply from the Inbox (test)']);
        $r2 = $s->api('admin', 'POST', 'wa_send_reply', ['conversation_id' => $sal, 'message' => 'A reply on the sales number (test)']);
        qf_track($b, time() + 5);
        $ctx->eq([$r1[0], $r2[0], qf_pick(qf_trow($pdo, 'ref:Q-8601'), ['status', 'status_reason']), qf_pick(qf_trow($pdo, 'ref:Q-8602'), ['status', 'status_reason'])],
                 [200, 200, ['status' => 'taken', 'status_reason' => 'inbox_reply'], ['status' => 'taken', 'status_reason' => 'inbox_reply']],
                 'F19 support (sender mode, no message id) and sales (channel mode): taken, inbox_reply');
        $s->stop();
    });

    $ctx->run('F20 an AI captionless photo whose mark beat its claim, and the notifier\'s own rows, never stop a quotation', function () use ($ctx, $q, $status) {
        $b = $ctx->book(qf_tcfg(), 'f20'); $p = $b['pdo'];
        $c = $q($b, '256772502701', 'Q-8701');
        qf_mark($p, 'out', '256772502701', 'support', 'WA-F20-AI', '2026-11-02 08:00:00', 'imageMessage');
        qf_msg($p, $c, ['role' => 'assistant', 'agent_name' => 'DishNet AI', 'body' => '[IMAGE]', 'wa_message_id' => 'WA-F20-AI', 'sent_at' => '2026-11-02 08:00:00']);
        qf_msg($p, $c, ['agent_name' => 'DishNet Plugin', 'event_key' => 'ops_quote_pdf', 'body' => '[DOC]', 'sent_at' => '2026-11-02 08:01:00']);
        qf_msg($p, $c, ['agent_name' => 'DishNet', 'body' => 'An enquiry follow-up (test)', 'sent_at' => '2026-11-02 08:02:00']);
        qf_track($b, '2026-11-02 12:00:00');
        $ctx->eq($status($p, 'Q-8701'), ['open', null], 'F20 the AI\'s photo, a notifier\'s row and the enquiry engine\'s row: open');
    });

    $ctx->run('F21 registered is for non-KYC quotations only, judged on Kampala stamps (§H.2.9.7; B2a-R03)', function () use ($ctx, $q, $status, $QS) {
        $b = $ctx->book(qf_tcfg(), 'f21'); $p = $b['pdo'];
        $beforeL = '2026-11-02 09:30:00';   // Kampala (date()): before the 10:00 send there, after its 07:00 UTC stamp
        $afterL  = '2026-11-02 11:00:00';
        $kyc = function (array $a) use ($p) { qf_blob($p, 'kyc_applications', $a + ['sales_type' => 'Credit', 'sales_person' => 'nobody']); };
        // a KYC-made quotation (cron A's 📋 for its own application), then a later KYC for the same client: stays open
        $kyc(['quote_ref' => 'Q-8801', 'quote_id' => '8801', 'crm_client_id' => 881, 'mobile' => '0772502801', 'submitted_at' => $beforeL]);
        qf_qmsg($p, qf_conv($p, '256772502801'), 'ops_quote_wa', qf_ref_text('Q-8801', '05 Nov 2026'), $QS);
        $kyc(['crm_client_id' => 881, 'mobile' => '0772502801', 'submitted_at' => $afterL]);
        // the same setup for a non-KYC quotation (a manual one for client 882): registered
        $q($b, '256772502802', 'Q-8802', 'support', ['crm_client_id' => 882]);
        $kyc(['crm_client_id' => 882, 'mobile' => '0772502802', 'submitted_at' => $afterL]);
        // reading 11: a KYC submitted at 09:30 Kampala is BEFORE a quotation sent at 10:00 Kampala (07:00 UTC) — by the client and by p
        $q($b, '256772502803', 'Q-8803', 'support', ['crm_client_id' => 883]);
        $kyc(['crm_client_id' => 883, 'submitted_at' => $beforeL]);
        $q($b, '256772502804', 'Q-8804');
        $kyc(['mobile' => '0772502804', 'submitted_at' => $beforeL]);
        qf_track($b, '2026-11-02 12:00:00');
        $ctx->eq([qf_trow($p, 'ref:Q-8801')['made_by'], qf_trow($p, 'ref:Q-8802')['made_by'], $status($p, 'Q-8802')], ['kyc', 'manual', ['registered', 'kyc_client']],
                 'F21 control: the non-KYC quotation followed by a KYC for its client reads registered');
        $ctx->eq($status($p, 'Q-8801'), ['open', null], 'F21 a KYC-made quotation followed by a later KYC for the same client stays open (registered is for non-KYC quotations)');
        $ctx->eq([$status($p, 'Q-8803'), $status($p, 'Q-8804')], [['open', null], ['open', null]],
                 'F21 a KYC submitted at 09:30 Kampala is before a 10:00 Kampala send: by the client and by p, open');
    });
}];

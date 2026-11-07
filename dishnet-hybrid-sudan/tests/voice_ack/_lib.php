<?php
declare(strict_types=1);
/**
 * Shared by tests/test_voice_ack.php and its sections (tests/voice_ack/<Id>_*.php): Part V's replies — P5's instant
 * acknowledgement, R3's transcript in the Inbox, R4's answers, STOP in a voice note, the hand-over, and VoiceSteps'
 * checks, preconditions and off-hooks (5.18.104, root docs/73 §H.3.11–§H.3.17, §H.3.20, §H.3.24). TEST ONLY.
 *
 * Built on stage B5's harness (tests/voice_media/_lib.php, itself on B1's tests/quote_followup/_lib.php). Added here:
 * P5's configuration; a voice note and its ai.voice_ack event as the webhook queues them; the reply worker run in
 * process at a fixed Kampala moment (VoiceAck's clock seam, the ledger on the same clock, and every real-time stamp the
 * run wrote moved onto that moment, as a book written then would read — D's "backdated with its row"); a fake brain;
 * the read-only connection the switch tool gives a check ($ro, query_only, total_changes() asserted 0); and planting.
 * Every person, number, key and message is fictitious; nothing leaves 127.0.0.1.
 */
require_once dirname(__DIR__) . '/voice_media/_lib.php';

/** Thursday 5 November 2026, 12:00 Kampala: inside D15's 07:00–21:00, not a Sunday. Every P5 case runs from here. */
function va_noon(): int { return qf_t('2026-11-05 12:00:00'); }

/** Load the copy's reply-side classes (once per process). */
function va_load(QfCtx $ctx): string
{
    $t = vm_load($ctx);
    foreach (['lib/bootstrap_data.php', 'lib/timezone.php', 'lib/VoiceAckLimits.php', 'lib/ReminderDay.php', 'lib/WaSendBudget.php',
              'lib/HumanTakeover.php', 'lib/WaSeenMarks.php', 'lib/QuoteChaseText.php', 'lib/ChannelContext.php', 'lib/VoiceAck.php',
              'lib/VoiceSteps.php', 'lib/DishNetAiBrain.php', 'lib/BrainContext.php', 'lib/AutomationPolicy.php', 'lib/InternalNumbers.php',
              'lib/EvoWebhookGuard.php', 'workers/AiReplyWorker.php'] as $f) {
        if (is_file($t . '/' . $f)) require_once $t . '/' . $f;
    }
    if (!class_exists('VaBrain', false)) require_once __DIR__ . '/fakes.php';
    return $t;
}

/** A Uganda configuration with the ledger on and P5 on (step 2), the fake Evolution, every media rung off unless $over says. */
function va_cfg(string $evo, array $over = []): array
{
    return vm_cfg($evo, array_merge(['ai_media_enabled' => null, 'ai_media_since' => null, 'wa_send_ledger' => 'on',
        'wa_send_ledger_since' => '2026-11-01 00:00:00', 'wa_voice_ack_enabled' => '1', 'wa_voice_ack_since' => '2026-11-01 00:00:00',
        'claude_api_key' => 'va-test-key-never-called'], $over));
}

/**
 * A voice note as the webhook stores it — the [AUDIO] row — and its ai.voice_ack event, received at $t (a Kampala-moment
 * unix time; default va_noon()). The row's stamps are moved onto $t. $o: id, channel, instance, wa_media (also record the
 * wa_media row and its ai.media event), seconds.
 * @return array{conv:int, wa:string, event:int, media:int}
 */
function va_note(array $b, string $phone, ?int $t = null, array $o = []): array
{
    $t = $t ?? va_noon();
    $ch = (string)($o['channel'] ?? 'sales');
    $inst = (string)($o['instance'] ?? ($ch === 'sales' ? 'Sales-UG' : 'DishNet-UG'));
    $real = time();
    $env = vm_envelope($phone, $o + ['seconds' => (int)($o['seconds'] ?? 4)]);
    $cs = new ConversationService($b['data'], $b['pdo']);
    $conv = (int)$cs->importEvoMessage($env, $ch);
    $wa = (string)$env['key']['id'];
    $media = 0;
    $bus = new EventBus($b['pdo']);
    if (!empty($o['wa_media'])) {
        $media = (int)InboundMedia::record($b['pdo'], $conv, InboundMedia::fromEvoMessage($env), $inst, $ch, $wa);
        $bus->emit('ai.media', 'media', $media, ['media_id' => $media], 3, 'evo_webhook');
    }
    $ev = $bus->emit('ai.voice_ack', 'conversation', $conv, ['channel' => $ch, 'whatsapp_instance' => $inst, 'customer_phone' => $phone,
        'wa_message_id' => $wa, 'received_at' => gmdate('c', $t), 'ptt' => true], 3, 'evo_webhook');
    va_rebase($b, $real, $t);
    // The events stay due now (real time) for the worker; their created_at reads $t.
    $b['pdo']->exec("UPDATE events SET next_retry_at = datetime('now', '-1 second') WHERE status = 'pending'");
    return ['conv' => $conv, 'wa' => $wa, 'event' => (int)$ev, 'media' => $media];
}

/**
 * Move every stamp a step wrote in real time (at or after $realStart) onto the moment $t + (its offset from $realStart):
 * the book then reads as one written at $t. The ledger and the decisions already carry the injected clock.
 */
function va_rebase(array $b, int $realStart, int $t): void
{
    $shift = $t - $realStart;
    $from = UtcClock::stamp($realStart - 1);
    foreach (['events' => ['created_at', 'processed_at', 'locked_at'], 'wa_messages' => ['created_at', 'sent_at'],
              'wa_conversations' => ['created_at', 'updated_at', 'last_message_at', 'last_agent_at', 'last_customer_at', 'last_human_reply_at'],
              'wa_media' => ['created_at', 'updated_at'], 'wa_voice_calls' => ['created_at', 'settled_at'],
              'contact_optouts' => ['created_at']] as $table => $cols) {
        $have = array_column($b['pdo']->query("PRAGMA table_info({$table})")->fetchAll(\PDO::FETCH_ASSOC), 'name');
        foreach ($cols as $c) {
            if (!in_array($c, $have, true)) continue;
            $b['pdo']->prepare("UPDATE {$table} SET {$c} = datetime({$c}, ?) WHERE {$c} >= ? AND {$c} LIKE '____-__-__ __:__:__'")
                     ->execute([sprintf('%+d seconds', $shift), $from]);
        }
    }
    // The ledger's record rows (the alert hook records in real time) and the seen marks are fixed by 089's triggers: in
    // the test's own book they are lifted for the move and put back exactly as 089 wrote them.
    foreach (['wa_auto_sends' => "claimed_at = datetime(claimed_at, :s), settled_at = CASE WHEN settled_at IS NULL THEN NULL ELSE datetime(settled_at, :s) END,
                                   local_day = date(datetime(claimed_at, :s), '+3 hours')", 'wa_seen_marks' => 'seen_at = datetime(seen_at, :s)'] as $table => $set) {
        $col = $table === 'wa_auto_sends' ? 'claimed_at' : 'seen_at';
        $n = $b['pdo']->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} >= ?");
        $n->execute([$from]);
        if ((int)$n->fetchColumn() === 0) continue;
        $trg = $b['pdo']->query("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = '{$table}'")->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach (array_keys($trg) as $t2) $b['pdo']->exec('DROP TRIGGER ' . $t2);
        $b['pdo']->prepare("UPDATE {$table} SET {$set} WHERE {$col} >= :f")->execute([':s' => sprintf('%+d seconds', $shift), ':f' => $from]);
        foreach ($trg as $sql) $b['pdo']->exec($sql);
    }
}

/**
 * The reply worker, in process, as run_worker.php constructs it, at the moment $t: VoiceAck reads $t (advancing with the
 * real clock), and what the run wrote in real time is moved onto $t afterwards. $o: cfg (a stale read, ruling R19),
 * brain (a VaBrain), batch, before (fn(AiReplyWorker)).
 */
function va_run(array $b, ?int $t = null, array $o = []): array
{
    $t = $t ?? va_noon();
    $real = time();
    VoiceAck::useClock(function () use ($t, $real): int { return $t + (time() - $real); });
    $b['pdo']->exec("UPDATE events SET next_retry_at = datetime('now', '-1 second') WHERE status IN ('pending', 'failed')");
    try {
        $w = new AiReplyWorker($b['store'], $o['cfg'] ?? $b['cfg'], 45, (int)($o['batch'] ?? 10));
        if (isset($o['brain'])) { $p = new \ReflectionProperty(AiReplyWorker::class, 'brain'); $p->setAccessible(true); $p->setValue($w, $o['brain']); }
        if (isset($o['before']) && is_callable($o['before'])) ($o['before'])($w);
        ob_start();
        try { $r = $w->run(); } finally { $log = (string)ob_get_clean(); }
    } finally {
        VoiceAck::useClock(null);
        va_rebase($b, $real, $t);
    }
    return ['run' => $r, 'log' => $log, 'worker' => $w];
}

/** The decision row of a note (or []). */
function va_dec(array $b, string $wa): array
{
    $r = vm_q($b, 'SELECT * FROM wa_voice_acks WHERE wa_message_id = ?', [$wa]);
    return $r[0] ?? [];
}

/** outcome[:reason] of a note's decision, or 'none'. */
function va_outcome(array $b, string $wa): string
{
    $d = va_dec($b, $wa);
    if ($d === []) return 'none';
    return $d['outcome'] . ($d['reason'] !== null && $d['reason'] !== '' ? ':' . $d['reason'] : '');
}

/** The ledger rows of kind voice_ack for a note's ref. */
function va_ledger(array $b, string $wa): array
{
    return vm_q($b, "SELECT * FROM wa_auto_sends WHERE kind = 'voice_ack' AND ref = ? ORDER BY id", ['ack:' . $wa]);
}

/** Texts the fake Evolution received to one number since the last reset. */
function va_sent_to(string $evo, string $phone): array
{
    return array_values(array_filter(vm_texts($evo), function ($c) use ($phone) { return $c[1] === $phone; }));
}

/** The read-only connection the switch tool gives a check (ruling R13): query_only, its own handle on the book. */
function va_ro(array $b): \PDO
{
    $ro = new \PDO('sqlite:' . $b['data'] . '/plugin.sqlite3');
    $ro->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $ro->exec('PRAGMA query_only = ON');
    return $ro;
}

/** Run a VoiceSteps check on a fresh $ro; the result plus the connection's total_changes() afterwards. */
function va_check(array $b, string $id, int $now, ?array $cfg = null): array
{
    $ro = va_ro($b);
    $r = VoiceSteps::check($ro, $cfg ?? $b['cfg'], $b['data'], $id, $now);
    $r['_changes'] = (int)$ro->query('SELECT total_changes()')->fetchColumn();
    return $r;
}

/** A copy of the book (the D's "planted through $rw on a copy"): a new data folder with the same file and configuration. */
function va_copy(QfCtx $ctx, array $b): array
{
    $b['pdo']->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $d = $ctx->dir('copy');
    copy($b['data'] . '/plugin.sqlite3', $d . '/plugin.sqlite3');
    copy($b['data'] . '/kyc_config.json', $d . '/kyc_config.json');
    $store = SqliteStore::create($d);
    return ['store' => $store, 'pdo' => $store->getPdo(), 'data' => $d, 'cfg' => ['_data_dir' => $d] + $b['cfg']];
}

/** Insert a row (a planted fixture); its id. */
function va_put(array $b, string $table, array $row): int
{
    $cols = array_keys($row);
    $b['pdo']->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
             ->execute(array_values($row));
    return (int)$b['pdo']->lastInsertId();
}

/**
 * A reply worker in its own process (tests/voice_ack/worker_drive.php), constructed on $cfg — a read taken before the
 * `on` or `off` the test then makes — started after $delay seconds; [process, pipes]. va_join() collects its answer.
 */
function va_spawn(QfCtx $ctx, array $b, array $cfg, float $delay, ?int $clock = null, ?string $canned = null): array
{
    $f = $ctx->dir('wcfg') . '/cfg.json';
    file_put_contents($f, json_encode($cfg));
    $cmd = 'exec php ' . escapeshellarg(__DIR__ . '/worker_drive.php') . ' --tree=' . escapeshellarg($ctx->tree()) . ' --data=' . escapeshellarg($b['data'])
         . ' --cfg=' . escapeshellarg($f) . ' --delay=' . $delay . ' --clock=' . (int)($clock ?? va_noon())
         . ($canned !== null ? ' --canned=' . escapeshellarg($canned) : '');
    $e = array_filter(getenv(), function ($k) { return stripos((string)$k, 'proxy') === false; }, ARRAY_FILTER_USE_KEY);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['DN_DATA_DIR' => $b['data']] + $e);
    return [$p, $pipes];
}

function va_join(array $h): array
{
    [$p, $pipes] = $h;
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($p);
    $lines = array_values(array_filter(explode("\n", (string)$out), 'strlen'));
    $j = json_decode((string)end($lines), true);
    return is_array($j) ? $j + ['_rc' => $rc] : ['_rc' => $rc, '_out' => substr((string)$out . $err, -1500)];
}

/** The tool's $rw: SqliteStore::create($dataDir)->getPdo() (ruling R13), a connection of its own on the book. */
function va_rw(array $b): \PDO
{
    return SqliteStore::create($b['data'])->getPdo();
}

/**
 * An R4 voice turn as VoiceTranscription::complete queues it, in real time: the conversation, the note's [AUDIO] row
 * relabelled, its wa_media row understood at rung `reply`, and the one ai.reply event with origin voice.
 * @return array{conv:int, wa:string, media:int, event:int}
 */
function va_voice_turn(array $b, string $phone, string $channel = 'sales', array $o = []): array
{
    $env = vm_envelope($phone, ['seconds' => (int)($o['seconds'] ?? 4)]);
    $cs = new ConversationService($b['data'], $b['pdo']);
    $conv = (int)$cs->importEvoMessage($env, $channel);
    $wa = (string)$env['key']['id'];
    $mid = (int)InboundMedia::record($b['pdo'], $conv, InboundMedia::fromEvoMessage($env), 'Sales-UG', $channel, $wa);
    $text = (string)($o['text'] ?? 'How much is the kit?');
    $label = VoiceTranscription::label($text);
    $b['pdo']->prepare("UPDATE wa_media SET status = 'understood', voice_rung = 'reply', understanding = ?, understanding_kind = 'transcript',
                         updated_at = datetime('now') WHERE id = ?")->execute([$text, $mid]);
    $b['pdo']->prepare("UPDATE wa_messages SET body = ? WHERE wa_message_id = ?")->execute([$label, $wa]);
    $ev = (new EventBus($b['pdo']))->emit('ai.reply', 'conversation', $conv, [
        'channel' => $channel, 'whatsapp_instance' => 'Sales-UG', 'customer_phone' => $phone, 'message' => $label, 'push_name' => '',
        'wa_message_id' => $wa, 'remote_jid' => $phone . '@s.whatsapp.net', 'received_at' => gmdate('c'), 'location' => null,
        'origin' => 'voice', 'voice' => ['media_id' => $mid, 'seconds' => (int)($o['seconds'] ?? 4), 'chars' => mb_strlen($text)]], 3, 'media_worker');
    return ['conv' => $conv, 'wa' => $wa, 'media' => $mid, 'event' => (int)$ev];
}

/** A typed message and its ai.reply event, as the webhook queues it, in real time. */
function va_text_turn(array $b, string $phone, string $text, string $channel = 'sales'): array
{
    $env = vm_envelope($phone, ['kind' => 'text', 'text' => $text]);
    $cs = new ConversationService($b['data'], $b['pdo']);
    $conv = (int)$cs->importEvoMessage($env, $channel);
    $ev = (new EventBus($b['pdo']))->emit('ai.reply', 'conversation', $conv, ['channel' => $channel, 'whatsapp_instance' => 'Sales-UG',
        'customer_phone' => $phone, 'message' => $text, 'push_name' => '', 'wa_message_id' => (string)$env['key']['id'],
        'received_at' => gmdate('c')], 3, 'evo_webhook');
    return ['conv' => $conv, 'wa' => (string)$env['key']['id'], 'event' => (int)$ev];
}

// ── Planted worlds for sections M–P (VN-ON1–VN-ON4): rows as the workers leave them, at stated moments ────────────

/** A conversation of $phone on $channel; its id. */
function vp_conv(array $b, string $phone, string $channel = 'sales'): int
{
    return (int)(new ConversationService($b['data'], $b['pdo']))->ensureConversation($phone, $channel)['id'];
}

/**
 * A voice note as the webhook and the media worker leave it: its [AUDIO] row (or the body given) and its wa_media row,
 * created at $at and settled at $at + ($o['settle_s'] ?? 10). Options: status, rung, attempts, reason, fetched, mime,
 * understanding, body, channel, instance, seconds, wa.
 * @return array{conv:int, wa:string, media:int, at:int, upd:int}
 */
function vp_note(array $b, string $phone, int $at, array $o = []): array
{
    static $n = 0;
    $n++;
    $ch = (string)($o['channel'] ?? 'sales');
    $conv = vp_conv($b, $phone, $ch);
    $wa = (string)($o['wa'] ?? sprintf('VP-NOTE-%05d', $n));
    $upd = $at + (int)($o['settle_s'] ?? 10);
    va_put($b, 'wa_messages', ['conversation_id' => $conv, 'direction' => 'in', 'role' => 'customer', 'body' => (string)($o['body'] ?? '[AUDIO]'),
        'media_type' => 'audio', 'wa_message_id' => $wa, 'sent_at' => UtcClock::stamp($at), 'created_at' => UtcClock::stamp($at)]);
    $status = (string)($o['status'] ?? 'fetched');
    $fetched = array_key_exists('fetched', $o) ? $o['fetched'] : (in_array($status, ['fetched', 'understood', 'skipped'], true) ? 4096 : null);
    $mid = va_put($b, 'wa_media', ['conversation_id' => $conv, 'wa_message_id' => $wa, 'instance' => (string)($o['instance'] ?? 'Sales-UG'),
        'channel' => $ch, 'remote_jid' => $phone . '@s.whatsapp.net', 'kind' => 'audio', 'mimetype' => (string)($o['mime'] ?? 'audio/ogg; codecs=opus'),
        'seconds' => (int)($o['seconds'] ?? 6), 'status' => $status, 'attempts' => (int)($o['attempts'] ?? 1),
        'failure_reason' => $o['reason'] ?? null, 'fetched_bytes' => $fetched,
        'fetched_mimetype' => $fetched !== null ? (string)($o['mime'] ?? 'audio/ogg; codecs=opus') : null,
        'understanding' => $o['understanding'] ?? null, 'ptt' => 1, 'voice_rung' => (string)($o['rung'] ?? 'fetch_only'),
        'local_seconds' => (int)($o['seconds'] ?? 6), 'created_at' => UtcClock::stamp($at), 'updated_at' => UtcClock::stamp($upd)]);
    return ['conv' => $conv, 'wa' => $wa, 'media' => $mid, 'at' => $at, 'upd' => $upd];
}

/** An event row as the bus leaves it; its id. Options: status, attempts, max, by, processed, error, payload. */
function vp_event(array $b, string $type, string $etype, int $eid, int $at, array $o = []): int
{
    $status = (string)($o['status'] ?? 'done');
    return va_put($b, 'events', ['event_type' => $type, 'entity_type' => $etype, 'entity_id' => $eid,
        'payload' => json_encode($o['payload'] ?? new \stdClass()), 'status' => $status, 'attempts' => (int)($o['attempts'] ?? 1),
        'max_attempts' => (int)($o['max'] ?? 3), 'created_by' => (string)($o['by'] ?? 'media_worker'), 'created_at' => UtcClock::stamp($at),
        'processed_at' => array_key_exists('processed', $o) ? $o['processed'] : (in_array($status, ['done', 'dead'], true) ? UtcClock::stamp($at + 2) : null),
        'locked_at' => $o['locked_at'] ?? null, 'error' => $o['error'] ?? null]);
}

/** The note's own ai.media event, done by default. */
function vp_media_event(array $b, array $n, array $o = []): int
{
    return vp_event($b, 'ai.media', 'media', $n['media'], $n['at'], $o + ['by' => 'evo_webhook', 'payload' => ['media_id' => $n['media']]]);
}

/** A media_worker (or other) hand-over of the note's conversation at $at; its id. */
function vp_escalation(array $b, int $conv, int $at, string $by = 'media_worker', string $why = 'a voice message (test)'): int
{
    return vp_event($b, 'wa.escalation', 'conversation', $conv, $at, ['by' => $by, 'payload' => ['reason' => $why]]);
}

/** The note's R4 turn: its ai.reply event with origin voice; its id. */
function vp_turn(array $b, array $n, int $at, array $o = []): int
{
    return vp_event($b, 'ai.reply', 'conversation', $n['conv'], $at, $o + ['by' => 'media_worker',
        'payload' => ['channel' => 'sales', 'origin' => 'voice', 'voice' => ['media_id' => $n['media'], 'seconds' => 6, 'chars' => 20]]]);
}

/** An outbound row: 'answer' (the AI's), 'line' (a holding line), 'ack' (P5's), 'inbox' (a colleague), 'followup', 'team'. */
function vp_out(array $b, int $conv, int $at, string $what, string $id = ''): int
{
    $rows = [
        'answer'   => ['role' => 'assistant', 'agent_name' => 'DishNet AI', 'body' => 'VP answer (test)', 'metadata' => json_encode(['channel' => 'sales'])],
        'line'     => ['role' => 'assistant', 'agent_name' => 'DishNet AI', 'body' => 'VP holding line (test)', 'metadata' => json_encode(['handover' => true])],
        'ack'      => ['role' => 'assistant', 'agent_name' => 'DishNet AI', 'body' => 'VP ack (test)', 'metadata' => json_encode(['source' => 'voice_ack'])],
        'inbox'    => ['role' => 'agent', 'agent_name' => 'Vee Colleague', 'body' => 'VP colleague (test)', 'event_key' => 'wa_staff_reply'],
        'followup' => ['role' => 'agent', 'agent_name' => 'DishNet', 'body' => 'VP follow-up (test)'],
        'team'     => ['role' => 'agent', 'agent_name' => 'Team', 'body' => 'VP team (test)'],
    ];
    return va_put($b, 'wa_messages', $rows[$what] + ['conversation_id' => $conv, 'direction' => 'out', 'wa_message_id' => $id !== '' ? $id : null,
        'sent_at' => UtcClock::stamp($at), 'created_at' => UtcClock::stamp($at)]);
}

/** A P5 decision for the note, at $at; its id. */
function vp_ack(array $b, array $n, int $at, string $outcome = 'sent', string $channel = 'sales'): int
{
    return va_put($b, 'wa_voice_acks', ['wa_message_id' => $n['wa'], 'conversation_id' => $n['conv'], 'channel' => $channel, 'handset' => 'i:sales-ug',
        'outcome' => $outcome, 'variant' => 'A', 'persona' => 0, 'ledger_ref' => 'ack:' . $n['wa'], 'period_hours' => 24, 'gap_s' => 20,
        'local_day' => WaSendBudget::localDay($at), 'created_at' => UtcClock::stamp($at), 'updated_at' => UtcClock::stamp($at + 1)]);
}

/** A transcription call of the note at $at, as the media worker records it. Options: rung, outcome, reason, reserved, billed, cap, attempt. */
function vp_call(array $b, array $n, int $at, array $o = []): int
{
    $outcome = (string)($o['outcome'] ?? 'ok');
    return va_put($b, 'wa_voice_calls', ['media_id' => $n['media'], 'conversation_id' => $n['conv'], 'attempt' => (int)($o['attempt'] ?? 1),
        'model' => 'gpt-4o-mini-transcribe', 'local_day' => WaSendBudget::localDay($at), 'seconds_reserved' => (int)($o['reserved'] ?? 60),
        'seconds_billed' => $outcome === 'started' ? null : (int)($o['billed'] ?? ($o['reserved'] ?? 60)), 'cap_day_s' => (int)($o['cap'] ?? 7200),
        'voice_rung' => (string)($o['rung'] ?? 'dry_run'), 'outcome' => $outcome, 'reason' => $o['reason'] ?? null, 'http' => $outcome === 'ok' ? 200 : 503,
        'ms' => 900, 'created_at' => UtcClock::stamp($at), 'settled_at' => $outcome === 'started' ? null : UtcClock::stamp($at + 2)]);
}

/** A run of $k fetched notes at rung $rung from $t0, one every $step seconds (each with its done ai.media event); the notes. */
function vp_notes(array $b, int $k, int $t0, string $rung, int $step = 600, array $o = []): array
{
    $out = [];
    for ($i = 0; $i < $k; $i++) {
        $n = vp_note($b, sprintf('2567714%05d', 90000 + $i + (int)($o['phone_base'] ?? 0)), $t0 + $i * $step, $o + ['rung' => $rung]);
        vp_media_event($b, $n);
        $out[] = $n;
    }
    return $out;
}

/** Run a check on $b and say whether it read $want with nothing written through $ro. */
function vp_is(array $r, string $want): bool { return $r['status'] === $want && $r['_changes'] === 0; }

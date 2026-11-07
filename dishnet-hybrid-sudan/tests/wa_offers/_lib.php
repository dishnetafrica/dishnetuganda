<?php
declare(strict_types=1);
/**
 * Shared by tests/test_wa_offers.php and its sections (tests/wa_offers/<Id>_*.php): Part O's engine — migration 091, the
 * offer rows, the text, the image, the window, the audience and every exclusion, STOP, the sender and its crash
 * idempotency, the gateway, the counts (5.18.104, root docs/73 §H.4.16). TEST ONLY.
 *
 * Built on stage B1's harness (tests/quote_followup/_lib.php): QfCtx, the tree copy every class is loaded from, a fresh
 * book on the copy's migrations, the repository's fake Evolution, and the weakened-copy runner. Added here: an injected
 * clock in a fixed Kampala week (Monday 2 November 2026), books whose kyc_config.json is the configuration (the sender
 * reads the files afresh, ruling R19), conversations and messages written at a moment of that week (every stamp a row
 * carries moved onto it, created_at included, so a bound by storage time reads as a book written then would), a scripted
 * ledger and a scripted gateway sharing one call log, offers made and started through OfferStore, and the copy's
 * evo_webhook.php served on loopback for the echo and STOP cases. Every person, number and message is fictitious;
 * nothing leaves 127.0.0.1.
 */
if (!defined('QF_TZ')) throw new \LogicException('the test file names the zone (QF_TZ) before it loads wa_offers/_lib.php');
require_once dirname(__DIR__) . '/quote_followup/_lib.php';

/** Monday 2 November 2026, 10:30 Kampala: inside 08:00–20:00, after the day's reminders (09:05). */
function wo_t0(): int { return qf_t('2026-11-02 10:30:00'); }

/** Load the copy's classes (once per process). */
function wo_load(QfCtx $ctx): string
{
    $t = $ctx->tree();
    foreach (['lib/StoreInterface.php', 'lib/SqliteStore.php', 'lib/bootstrap_data.php', 'lib/timezone.php', 'lib/PluginConfig.php',
              'lib/UtcClock.php', 'lib/StaffJobsGate.php', 'lib/ConversationService.php', 'lib/ContactOptOut.php', 'lib/EvolutionApiService.php',
              'lib/EvoWebhookGuard.php', 'lib/ChannelRegistry.php', 'lib/MigrationRunner.php', 'lib/WaSendBudget.php', 'lib/HumanTakeover.php',
              'lib/QuoteChase.php', 'lib/ReminderDay.php', 'lib/JobBudget.php', 'lib/OfferSwitch.php', 'lib/OfferText.php', 'lib/OfferWindow.php',
              'lib/OfferSchema.php', 'lib/OfferImage.php', 'lib/OfferGateway.php', 'lib/EvolutionOfferGateway.php', 'lib/OfferAudience.php',
              'lib/OfferStore.php', 'lib/OfferSender.php', 'lib/EventBus.php', 'lib/FollowUpService.php'] as $f) {
        if (is_file($t . '/' . $f)) require_once $t . '/' . $f;
    }
    return $t;
}

/** A Uganda configuration with the ledger on and both offer switches on; $over's null unsets a key. */
function wo_cfg(array $over = []): array
{
    $c = QF_UG + [
        'evo_api_url' => 'http://127.0.0.1:9', 'evo_api_key' => 'wo-evo-key',
        'offers_test_enabled' => '1', 'offers_live_enabled' => '1', 'offers_live_since' => '2026-11-01 06:00:00',
        'reminder_hour' => 9, 'reminder_until_hour' => 17, 'evo_webhook_secret' => 'wo-webhook-secret-test',
    ];
    foreach ($over as $k => $v) { if ($v === null) unset($c[$k]); else $c[$k] = $v; }
    return $c;
}

/** A fresh book whose kyc_config.json is $cfg, the day's reminders done at 09:05 Kampala (duration 1000 ms). */
function wo_book(QfCtx $ctx, array $cfg, string $tag = 'wo', ?int $remindersAt = null): array
{
    wo_load($ctx);
    $b = $ctx->book($cfg, $tag);
    $b['cfg'] = $cfg;
    file_put_contents($b['data'] . '/kyc_config.json', json_encode($cfg));
    $b['store']->save('master_schedule.json', ['customer_reminders' => ['last_run' => $remindersAt ?? qf_t('2026-11-02 09:05:00'), 'duration_ms' => 1000]]);
    putenv('DN_DATA_DIR=' . $b['data']);
    return $b;
}

/** Change the configuration on disk (a null unsets a key): what the switch tool's on/off/set would leave there. */
function wo_setcfg(array &$b, array $over): void
{
    foreach ($over as $k => $v) { if ($v === null) unset($b['cfg'][$k]); else $b['cfg'][$k] = $v; }
    file_put_contents($b['data'] . '/kyc_config.json', json_encode($b['cfg']));
}

/** The injected clock. */
final class WoClock
{
    public $t;
    public function __construct(int $t) { $this->t = $t; }
    public function __invoke(): int { return $this->t; }
}

/** A conversation for $phone on $channel; its stamps are set by the messages written through wo_in/wo_out. */
function wo_conv(array $b, string $phone, string $channel = 'sales', array $set = []): int
{
    $cs = new ConversationService($b['data'], $b['pdo']);
    $id = (int)$cs->ensureConversation($phone, $channel)['id'];
    foreach ($set as $k => $v) $b['pdo']->prepare("UPDATE wa_conversations SET {$k} = ? WHERE id = ?")->execute([$v, $id]);
    return $id;
}

/** A customer's message at $t (unix), stored through ConversationService and every stamp moved onto $t. */
function wo_in(array $b, int $conv, string $body, int $t, array $o = []): int
{
    $cs = new ConversationService($b['data'], $b['pdo']);
    $id = (int)$cs->storeMessage($conv, ['direction' => 'in', 'role' => 'customer', 'body' => $body, 'media_type' => $o['media_type'] ?? null,
                                         'wa_message_id' => $o['id'] ?? ('WO-IN-' . bin2hex(random_bytes(5))), 'sent_at' => UtcClock::stamp($t)]);
    $b['pdo']->prepare('UPDATE wa_messages SET created_at = ? WHERE id = ?')->execute([UtcClock::stamp($o['stored'] ?? $t), $id]);
    return $id;
}

/** Our side's message at $t (an answer: last_agent_at moves). */
function wo_out(array $b, int $conv, string $body, int $t, array $o = []): int
{
    $cs = new ConversationService($b['data'], $b['pdo']);
    $id = (int)$cs->storeMessage($conv, ['direction' => 'out', 'role' => 'agent', 'body' => $body, 'agent_name' => $o['agent'] ?? 'DishNet AI',
                                         'event_key' => $o['event_key'] ?? null, 'wa_message_id' => $o['id'] ?? ('WO-OUT-' . bin2hex(random_bytes(5))),
                                         'sent_at' => UtcClock::stamp($t)]);
    $b['pdo']->prepare('UPDATE wa_messages SET created_at = ? WHERE id = ?')->execute([UtcClock::stamp($t), $id]);
    return $id;
}

/** A warm customer: a conversation on $channel, a message $agoS seconds before $now, answered by the AI 60 s later. */
function wo_warm(array $b, string $phone, int $now, int $agoS = 86400, string $channel = 'sales'): int
{
    $c = wo_conv($b, $phone, $channel);
    wo_in($b, $c, 'Hello, what plans do you have? (test)', $now - $agoS);
    wo_out($b, $c, 'Here are our plans. (test)', $now - $agoS + 60);
    return $c;
}

/** Two wordings, a title; a draft. */
function wo_offer(array $b, int $now, array $o = []): int
{
    $r = OfferStore::create($b['pdo'], $o['title'] ?? 'November promotion (test)',
        $o['wordings'] ?? ['A fictitious first wording for the test offer.', 'A fictitious second wording, different from the first.'],
        $o['size'] ?? 'all', 7, 'Sandbox Admin', $now);
    if (!$r['ok']) throw new \RuntimeException('wo_offer: ' . implode(',', $r['errors']));
    return $r['id'];
}

/** A sent, recorded test of every wording of the offer's current content, at $now. */
function wo_tested(array $b, int $offerId, int $now): void
{
    $o = OfferStore::find($b['pdo'], $offerId);
    foreach (array_keys(OfferStore::wordingsOf($o)) as $v) {
        $b['pdo']->prepare("INSERT INTO wa_offer_tests (offer_id, content_hash, variant, staff_id, channel, handset, state, ledger, wa_message_id,
                                                        echo, started_at, settled_at)
                            VALUES (?, ?, ?, 7, 'sales', 'i:sales-ug', 'sent', 'recorded', ?, 'claimed', ?, ?)")
            ->execute([$offerId, $o['content_hash'], $v, 'WO-TEST-' . $offerId . '-' . $v, UtcClock::stamp($now), UtcClock::stamp($now)]);
    }
}

/** A draft made, tested and started at $now through OfferStore::start; [offer id, start answer]. */
function wo_started(array $b, int $now, ?OfferGateway $gw = null, array $o = []): array
{
    $id = wo_offer($b, $now, $o);
    wo_tested($b, $id, $now);
    $off = OfferStore::find($b['pdo'], $id);
    $r = OfferStore::start($b['pdo'], $b['cfg'], $b['data'], [], [], $id, (string)$off['content_hash'], 7, 'Sandbox Admin', $now, $gw);
    return [$id, $r];
}

/** One call log the scripted ledger and the scripted gateway share, so their order can be asserted. */
final class WoLog
{
    public $calls = [];
}

/**
 * The scripted ledger: claim() answers from $answers in turn (default ok with a fresh handle), recording every call;
 * settle() and record() record theirs. An answer is ['ok' => true] or ['defer' => code, 'retry_at' => ?int].
 */
final class WoBudget
{
    public $log; public $answers = []; public $next = 900; public $recordAnswer = 'recorded'; public $onSettle = null;
    /** @var callable|null run at the start of every claim (step 6, after step 4's judgement): a row stored there */
    public $onClaim = null;
    public function __construct(WoLog $log, array $answers = []) { $this->log = $log; $this->answers = $answers; }
    public function claim(string $handset, string $kind, string $ref, array $o = []): array
    {
        if ($this->onClaim !== null) ($this->onClaim)($handset, $kind, $ref, $o);
        $this->log->calls[] = ['claim', $handset, $kind, $ref, $o];
        $a = array_shift($this->answers) ?? ['ok' => true];
        if (!empty($a['ok'])) return ['ok' => true, 'claim' => ++$this->next];
        return ['ok' => false, 'defer' => (string)$a['defer'], 'retry_at' => $a['retry_at'] ?? null, 'final' => !isset($a['retry_at'])];
    }
    public function settle(int $claim, string $result, array $o = []): void
    {
        $this->log->calls[] = ['settle', $claim, $result, $o];
        if ($this->onSettle !== null) ($this->onSettle)($claim, $result, $o);
    }
    public function record(string $handset, string $kind, string $ref, string $result, array $o = []): string
    {
        $this->log->calls[] = ['record', $handset, $kind, $ref, $result, $o];
        return $this->recordAnswer;
    }
}

/** The sender over a book, with the injected clock, the scripted ledger and gateway unless $seams say otherwise. */
function wo_sender(QfCtx $ctx, array $b, WoClock $clock, $budget, $gw, array $seams = []): OfferSender
{
    return OfferSender::forInstall($ctx->tree(), $b['data'], $b['cfg'], $seams + [
        'store' => $b['store'], 'clock' => $clock,
        'gateway' => function () use ($gw) { return $gw; },
        'budget' => function () use ($budget) { return $budget; },
        'wait' => function (int $s): void { /* the second-boundary wait is K's (real clock) */ },
    ]);
}

/** Rows of one offer: id => [state, reason]. */
function wo_rows(\PDO $pdo, int $offerId): array
{
    $st = $pdo->prepare('SELECT id, phone, state, reason FROM wa_offer_recipients WHERE offer_id = ? ORDER BY rank');
    $st->execute([$offerId]);
    $out = [];
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[(string)$r['phone']] = [$r['state'], $r['reason']];
    return $out;
}

/** The kinds of the log's calls, in order: ['claim', 'send', 'settle', …]. */
function wo_order(WoLog $log): array
{
    return array_map(function ($c) { return $c[0]; }, $log->calls);
}

/**
 * The copy's evo_webhook.php served on loopback with a data directory of its own: [url, data dir, write settings]. One
 * webhook per $name per process: 'main' is served from the copy itself; any other name from a second copy of it (the
 * webhook finds its book through the tree's ucrm.json), so a case whose sends leave echo claims and a live offer in its
 * book (E01) shares that book with no other case.
 */
function wo_webhook(QfCtx $ctx, array $cfg, string $name = 'main'): array
{
    static $w = [];
    if (isset($w[$name])) { ($w[$name][2])($cfg); return $w[$name]; }
    $tree = $ctx->tree();
    if ($name !== 'main') {
        $tree = $ctx->dir('wh-tree-' . $name);
        qf_copy_tree($ctx->tree(), $tree);
    }
    $data = $ctx->dir('wh-data');
    file_put_contents($tree . '/ucrm.json', json_encode(['pluginDataDir' => $data]));
    SqliteStore::create($data);
    $set = function (array $c) use ($data): void { file_put_contents($data . '/kyc_config.json', json_encode($c)); };
    $set($cfg);
    // exec disabled, as tests/fixtures/staff_jobs_sandbox.php serves the plugin: an inbound message queued for the AI would
    // otherwise spawn run_worker.php against this book, a writer no case controls (measured: a STOP typed inside E01's run
    // made the sender's next store in that book fail "database is locked").
    [$port] = qf_serve('-d disable_functions=exec -t ' . escapeshellarg($tree), function (int $p): bool {
        return qf_http('GET', "http://127.0.0.1:{$p}/evo_webhook.php", null, [], 3)[0] === 405;
    }, 14200, ['DN_VAULT_FILE' => (string)getenv('DN_VAULT_FILE')]);
    return $w[$name] = [$port === 0 ? '' : "http://127.0.0.1:{$port}/evo_webhook.php", $data, $set];
}

/** A message posted to the served webhook as Evolution posts it. */
function wo_post(string $url, array $msg, string $instance = 'Sales-UG', string $secret = 'wo-webhook-secret-test'): array
{
    return qf_http('POST', $url, ['event' => 'messages.upsert', 'instance' => $instance, 'data' => $msg], ['X-DishNet-Token: ' . $secret]);
}

/** tests/wa_offers/evo_stub.php served on loopback; its base URL with the mode set, or '' when it would not start. */
function wo_stub(string $mode = 'ok'): string
{
    static $base = null;
    if ($base === null) {
        [$port] = qf_serve(escapeshellarg(__DIR__ . '/evo_stub.php'), function (int $p): bool {
            return strpos(qf_http('GET', "http://127.0.0.1:{$p}/__marker", null, [], 2)[2], 'WO-EVO-STUB') !== false;
        }, 14800);
        $base = $port === 0 ? '' : "http://127.0.0.1:{$port}";
    }
    if ($base !== '') qf_http('GET', $base . '/__mode?m=' . rawurlencode($mode));
    return $base;
}

/**
 * The book the served webhook $name writes to (one per name per process: wo_webhook's data directory), with $cfg as its settings and the
 * day's reminders done at 09:05 Kampala; ['store', 'pdo', 'data', 'cfg', 'url'].
 */
function wo_whbook(QfCtx $ctx, array $cfg, string $name = 'main'): array
{
    wo_load($ctx);
    [$url, $data, $set] = wo_webhook($ctx, $cfg, $name);
    $set($cfg);
    $store = SqliteStore::create($data);
    $store->save('master_schedule.json', ['customer_reminders' => ['last_run' => qf_t('2026-11-02 09:05:00'), 'duration_ms' => 1000]]);
    putenv('DN_DATA_DIR=' . $data);
    return ['store' => $store, 'pdo' => $store->getPdo(), 'data' => $data, 'cfg' => $cfg, 'url' => $url];
}

/** A fromMe text as Evolution echoes our own message back, or an inbound text, posted to the served webhook. */
function wo_echo(string $url, string $phone, string $id, string $text, bool $fromMe = true, string $instance = 'Sales-UG'): array
{
    return wo_post($url, ['key' => ['remoteJid' => $phone . '@s.whatsapp.net', 'fromMe' => $fromMe, 'id' => $id],
                          'message' => ['conversation' => $text], 'messageTimestamp' => time(), 'pushName' => 'WO Test'], $instance);
}

/** Start tests/wa_offers/bg_writer.php and wait until it says it is ready (then 150 ms more); its process handle. */
function wo_bg(string $mode, string $db, string $arg, string $readyFile)
{
    @unlink($readyFile);
    $p = proc_open('exec php ' . escapeshellarg(__DIR__ . '/bg_writer.php') . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($db) . ' '
                   . escapeshellarg($readyFile) . ' ' . escapeshellarg($arg), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 100 && !is_file($readyFile); $i++) usleep(20000);
    usleep(150000);
    register_shutdown_function(function () use ($p) { if (is_resource($p)) proc_close($p); });
    return $p;
}

/** Wait until the real clock is 20 ms into a fresh second. */
function wo_second_start(): void
{
    $t = microtime(true);
    usleep((int)((ceil($t) - $t) * 1000000) + 20000);
}

/**
 * The control on the controls (root docs/73 §H.4.16: "each copy must fail its section, the unchanged tree must pass, and
 * 221c9dc must fail A–L"): this runner on 5.18.103's tree, taken from Git by sj_baseline_tree() (no working tree read or
 * written), with --no-mutants. Every section must run (its heading printed) and print at least one FAIL. A Git that
 * cannot supply 221c9dc is a FAIL of this test, never a skip.
 */
function wo_control_on_controls(QfCtx $ctx, string $testFile, array $sections, string $commit = '221c9dc'): void
{
    require_once dirname(__DIR__) . '/fixtures/staff_jobs_scenario.php';                 // sj_baseline_tree()
    echo "\nCC. The control on the controls: {$commit} must fail every section\n";
    [$dir, $why] = sj_baseline_tree($commit);
    if ($dir === null) { $ctx->t(false, "CC {$commit} taken from Git", $why); return; }
    $ctx->t(!is_file($dir . '/lib/OfferSender.php') && is_file($dir . '/manifest.json'), "CC control: the tree judged is {$commit}'s, without P6");
    $out = [];
    exec('php ' . escapeshellarg($testFile) . ' --no-mutants --root=' . escapeshellarg($dir) . ' 2>&1', $out, $rc);
    $ran = []; $fails = []; $cur = '';
    foreach ($out as $l) {
        if (preg_match('/^([A-Z]{1,2})\. /', $l, $h)) { $cur = $h[1]; $ran[$cur] = true; continue; }
        if ($cur !== '' && strpos($l, '  FAIL ') === 0) $fails[$cur] = ($fails[$cur] ?? 0) + 1;
    }
    foreach ($sections as $s) {
        $ctx->t(!empty($ran[$s]) && !empty($fails[$s]), "CC {$commit} fails section {$s} (" . ($fails[$s] ?? 0) . ' FAIL lines)',
                empty($ran[$s]) ? 'the section did not run: ' . implode("\n", array_slice($out, -15)) : 'every case passed on ' . $commit);
    }
}

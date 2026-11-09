<?php
// 5.18.104's rehearsal: the acts of its live cases (104-1's keys, 104-5's chase, reply, acknowledgement and orphan), each
// through the CODE ROOT's own writers — the INSTALLED plugin's (5.18.103's before the deploy, 5.18.104's once it serves),
// or the PIN's from Git where a case needs 5.18.104's own state on a book 5.18.104 never served (104-1's redeploy shape) —
// never raw SQL. Run by the rehearsal as the store's user (1000), with a COPY of the live vault (DN_VAULT_FILE), under the
// rehearsal's clock where a case says so (rh104-clock.so). Synthetic and fictitious: every phone is the rehearsal's own
// range (+2567001040NN), every name and text an RH104SECRET- value; staff first names only.
//   php rh104-live.php partkeys <ladder root> <parts JSON>
//        — the keys a case sets, from <ladder root>'s SalesFollowupLadder::STEPS alone (a process of their own, so that no
//          class of another code root is loaded beside it): for each part named, the switch keys of its first ladder row
//          that has any, with the values `on` writes and that row's since-stamp, then the ledger's two keys
//          (wa_send_ledger=on) and the record key sf_report_ran_at — {"changes":{…},"all":[allKeys()]}
//   php rh104-live.php keys     <writer root> <data dir> <changes JSON> <allowed JSON>
//        — PluginConfig::saveOverrides(<data dir>, <changes>) of <writer root> (the tool's one writer of keys, unchanged from
//          5.18.103 to 5.18.104): every key one of <allowed> (the ladder's allKeys()), every value null (unset), a digit
//          string, "on", a date or a UTC stamp
//   php rh104-live.php quote    <root> <data dir> <spec JSON>
//        — a customer's inbound text (the conversation the quotation is answered on, spoke-to) and, <gap_min> minutes later
//          by this process's clock, the quotation text as the Send Quotation path stores it (ConversationService::storeMessage,
//          outbound, event quote_manual, "📋 *Ref:* <ref>" and its "⏳ *Valid Until:*" line)
//   php rh104-live.php schedule <root> <data dir> <spec JSON>
//        — master_schedule.json through the store's own load()/save() (master's writer): every job of <root>'s cron/master.php
//          but those spec "due" names is recorded as run at this moment, so that a master cycle at this moment dispatches
//          only those (a sandbox that may not reach Starlink, Google Drive, Splynx or EFRIS); spec "keep" leaves a job's
//          entry as it is; spec "set" ({job: {"last_run": <unix>, "duration_ms": <ms, or -1>}}) writes that job's entry in
//          the form master itself writes (last_run, last_run_at, duration_ms — -1 before the job, the real duration after
//          it: cron/master.php) — a run of the job still going, or finished, which a sandbox cycle cannot hold open while
//          another job reads the schedule (master runs its jobs one after another in one process)
//   php rh104-live.php claim    <root> <data dir> <spec JSON>
//        — one ledger claim through WaSendBudget::forInstall(…)->claim() on the handset of a channel, never settled
//   php rh104-live.php webhook  <root> <data dir> <url> <spec JSON>
//        — one Evolution messages.upsert payload POSTed to the real evo_webhook.php served at <url>, the token read through
//          <root>'s own EvoWebhookGuard and sent in its header (never the query, never printed): kind text|audio|wrapped
//   php rh104-live.php secrets  <root> <data dir> [<token file>]
//        — whether claude_api_key, openai_api_key, shopbot_ai_url (the reply worker's external brain: askBrain posts to it
//          before anything else) and the webhook's secret are set (never a value): the live cases refuse to
//          run where a model provider could be reached; given <token file> (made by the rehearsal, 0600, the store's user's),
//          the webhook's token is written there, never printed — the pattern the sweeps search every log for
//   php rh104-live.php provider <root> <data dir> <spec JSON>
//        — the assistant's provider kept off openai on a copy of the book (104-5v, before any OpenAI key is stored): spec
//          exactly {"ai_provider":"claude"}, written through <root>'s PluginConfig::saveOverrides (the tool's one writer of
//          keys; ai_provider is no secret, so it accepts it, and it mirrors the store row), then read back through
//          PluginConfig::read as DishNetAiBrain's constructor reads it — {"ok":…,"provider":"claude"|"openai"}
//   php rh104-live.php open     <root> <data dir>
//        — SqliteStore::create(<data dir>) of <root>: the store opened as that code's first boot opens it, its MigrationRunner
//          applying what <root>'s migrations/ holds and the book's ledger lacks (104-1's redeploy shape) — {"opened":true,…}
//   php rh104-live.php allon    <root> <data dir> <spec JSON>
//        — 104-6's "every part on": every switch key of every row of <root>'s SalesFollowupLadder::STEPS with the value its
//          `on` writes, each row's since-stamp (UtcClock::stamp, now), its options' keys (a date option today in Kampala, a
//          channels option spec "channels"), the ledger's two keys and the ladder's record keys (sf_report_ran_at today,
//          sf_chase_first_on now, sf_cold_statement spec "statement", an RH104SECRET- line) — each only where it does not
//          read set already (a key the tool set keeps its own value and stamp) — through <root>'s PluginConfig::saveOverrides,
//          the tool's one writer of keys: {"ok":…,"n":<keys written>,"after":<allKeys() set after>}
//   php rh104-live.php offerprep  <root> <data dir> <spec JSON>
//        — an offer as the screen's own handlers make one, through <root>'s writers: OfferStore::create (a draft of spec
//          "title", "w1", "w2", audience all, by the store's first active RH104SECRET- admin with a phone of record), then
//          OfferSender::sendTest of each wording on spec "channel" to that admin's own phone (the stand-in Evolution, on
//          127.0.0.1) — {"offer":<id>,"tests":"<sent|code>,<sent|code>"}
//   php rh104-live.php offerstale <root> <data dir> <spec JSON>
//        — a test of spec "offer" left `sending` spec "age_s" seconds before now (the sendTest's own INSERT, raw: only a
//          sender stopped mid-test leaves such a row), on the handset and by the admin of that offer's latest sent test;
//          stale (16 minutes to a day old) unless spec "young" is true, then one to five minutes old (RH4-fix1: a test still
//          in its send when the rollback begins, which the hook must leave) — {"stale"|"young":1,"id":<test id>}
//   php rh104-live.php offerstart <root> <data dir> <spec JSON>
//        — OfferStore::start of spec "offer" with its content as stored, as the screen's offer_start calls it
//   php rh104-live.php offerclaim <root> <data dir> <spec JSON>
//        — two queued recipients of the running offer spec "offer", in the states only a sender stopped mid-send leaves
//          (OfferSender::oneRecipient's own statements, raw, said so): the first with its ledger claim — WaSendBudget's
//          own claim(handset, 'offer', 'offer:<offer>:<recipient>', phone, in_flight_s), then steps 2, 4 and 7's row
//          writes (claimed, judged with its day_max, its ledger claim and variant recorded) — the second claimed alone
//          (step 2, the 2–6 window): {"a":<recipient>,"b":<recipient>,"claim":<ledger claim>} or the ledger's deferral
//   php rh104-live.php vcall    <root> <data dir>
//        — one wa_voice_calls row through <root>'s VoiceBudget::reserve, as the media worker reserves before a call to
//          OpenAI — which never comes: the reservation's own state when a worker stops after it (VN-RB6's question)
//   php rh104-live.php admin    <root> <data dir> <spec JSON>
//        — an admin account for the offers screen through <root>'s own RetailerAuth::createRetailer (the writer the login page's
//          agent form and the staff screen call): spec name, e-mail (lower-case, an rh104secret- address) and phone (the
//          rehearsal's range); the password from RH104_ADMIN_PW in the environment, never an argument, never printed —
//          {"made":…,"found":…,"lower":…,"admin":…,"must_change":…} (booleans: never the account's id)
//   php rh104-live.php warm     <root> <data dir> <spec JSON>
//        — warm conversations for an offer's audience, through <root>'s ConversationService: per spec "convs" entry the
//          customer's inbound text <ago_min> minutes before now (ensureConversation, then storeMessage as the processor
//          stores a text), and the assistant's own reply <reply_after_min> minutes after it (role assistant, agent DishNet AI,
//          as AiReplyWorker stores its replies) — {"warm":<conversations written>}
//   php rh104-live.php mediaworker <root> <data dir> <spec JSON>
//        — one run of <root>'s INSTALLED MediaWorker, constructed as run_media_worker.php constructs it, given through its own
//          seam useTranscriber() <root>'s OpenAiTranscriber pointed at spec "endpoint" — the rehearsal's stand-in on
//          127.0.0.1 (the adapter's LOOPBACK rule; tests/voice_media/_lib.php's vm_run does the same) — never the factory,
//          whose adapter names api.openai.com. The process's proxy variables are unset first (no request may leave
//          127.0.0.1). Its trace appended to <data dir>/ai_platform.log as run_media_worker.php appends it — {"processed":…}
//   php rh104-live.php remind   <root> <data dir> <spec JSON>
//        — 104-7f: a reminder run of the earlier build (NotificationService as cron/customer_reminders.php constructs it, the
//          configuration read once with the ledger on), held at spec "marker".ready until "marker".go, then one payment
//          reminder through the stand-in Evolution, recorded by its record-only hook: {"ok":…,"http":…,"at":…}
//   php rh104-live.php offertest <root> <data dir> <spec JSON>
//        — 104-7o: one test of an offer's wording (OfferSender::sendTest, as the screen's handler sends it): {"ok":…,"code":…}
//   php rh104-live.php offerstop <root> <data dir> <spec JSON>
//        — 104-7o (RH5-fix1): the live offer spec "offer" stopped for good, as the screen's offer_stop handler stops it
//          (OfferStore::stop, by the admin): its queued rows skipped, the one live slot (O-D1) freed — {"ok":…}
//   php rh104-live.php deadev <root> <data dir> <spec JSON>
//        — 104-7v: one dead ai.media or ai.voice_ack event through EventBus's own emit and fail(): {"event":…,"status":"dead",…}
//   allon takes spec "only" or "except" (lists of the ladder's step names) and schedule spec "only" (the "set" entries alone)
// Prints one line of JSON — counts, ids, HTTP codes and fixed words; never a phone, a name, a body, a key's value or a token.
declare(strict_types=1);
ini_set('display_errors', 'stderr');
[$_, $mode] = $argv + [null, ''];
if ((string)getenv('DN_VAULT_FILE') === '') { fwrite(STDERR, "refusing: DN_VAULT_FILE must point into the sandbox\n"); exit(2); }
$out = function (array $a): void { echo json_encode($a, JSON_UNESCAPED_SLASHES), "\n"; };
$ours = function ($v): bool {
    if (!is_string($v) || $v === '') return true;
    return strpos($v, 'RH104SECRET-') !== false || (bool)preg_match('/^\+?2567001040[0-9]{2}$/', $v);
};
$libs = function (string $root, array $names): void {
    foreach ($names as $l) { $f = $root . '/lib/' . $l . '.php'; if (is_file($f)) require_once $f; }
};
try {
    if ($mode === 'partkeys') {
        [, , $lroot, $spec] = $argv + [null, null, '', '[]'];
        if (!is_file($lroot . '/lib/SalesFollowupLadder.php')) { fwrite(STDERR, "refusing: no ladder in the code root\n"); exit(2); }
        require_once $lroot . '/lib/SalesFollowupLadder.php';
        $parts = json_decode((string)$spec, true);
        if (!is_array($parts) || $parts === []) { fwrite(STDERR, "refusing: no parts\n"); exit(2); }
        $now = gmdate('Y-m-d H:i:s'); $changes = [];
        foreach ($parts as $p) {
            foreach (SalesFollowupLadder::STEPS as $step => $row) {
                if ((string)$row['part'] !== (string)$p || $row['switch'] === []) continue;
                foreach ($row['switch'] as $k => $v) $changes[$k] = (string)$v;
                if ($row['stamp'] !== null) $changes[$row['stamp']] = $now;
                break;
            }
        }
        foreach (SalesFollowupLadder::LEDGER_KEYS as $k) $changes[$k] = $k === 'wa_send_ledger' ? 'on' : $now;
        $changes['sf_report_ran_at'] = (new DateTime('now', new DateTimeZone(SalesFollowupLadder::TZ)))->format('Y-m-d');
        $all = SalesFollowupLadder::allKeys();
        foreach (array_keys($changes) as $k) if (!in_array($k, $all, true)) { fwrite(STDERR, "refusing: a key outside the ladder's\n"); exit(2); }
        $out(['changes' => $changes, 'all' => $all]);
        exit(0);
    }
    if ($mode === 'keys') {
        [, , $wroot, $data, $ch, $al] = $argv + [null, null, '', '', '{}', '[]'];
        foreach ([$wroot . '/lib/PluginConfig.php', $data . '/plugin.sqlite3'] as $f) if (!is_file($f)) { fwrite(STDERR, "refusing: no $f\n"); exit(2); }
        $changes = json_decode((string)$ch, true); $allowed = json_decode((string)$al, true);
        if (!is_array($changes) || $changes === [] || !is_array($allowed) || $allowed === []) { fwrite(STDERR, "refusing: no changes\n"); exit(2); }
        foreach ($changes as $k => $v) {
            if (!in_array((string)$k, $allowed, true)) { fwrite(STDERR, "refusing: a key outside the ladder's\n"); exit(2); }
            if ($v !== null && !preg_match('/^(on|[0-9]{1,6}|[0-9]{4}-[0-9]{2}-[0-9]{2}( [0-9]{2}:[0-9]{2}:[0-9]{2})?)$/', (string)$v)) { fwrite(STDERR, "refusing: a value this helper does not write\n"); exit(2); }
        }
        $libs($wroot, ['bootstrap_data', 'timezone', 'UtcClock', 'ConfigVault', 'PluginConfig']);
        $r = PluginConfig::saveOverrides($data, $changes);
        $out(['ok' => (bool)($r[0] ?? false), 'n' => count($changes)]);
        exit(!empty($r[0]) ? 0 : 3);
    }
    if ($mode === 'allon') {
        [, , $root, $data, $spec] = $argv + [null, null, '', '', '{}'];
        foreach ([$root . '/lib/SalesFollowupLadder.php', $root . '/lib/PluginConfig.php', $data . '/plugin.sqlite3'] as $f) if (!is_file($f)) { fwrite(STDERR, "refusing: no $f\n"); exit(2); }
        $s = json_decode((string)$spec, true);
        if (!is_array($s) || strpos((string)($s['statement'] ?? ''), 'RH104SECRET-') !== 0
            || !preg_match('/^[a-z0-9_-]{1,40}(,[a-z0-9_-]{1,40})*$/', (string)($s['channels'] ?? ''))) { fwrite(STDERR, "refusing: not the rehearsal's own spec\n"); exit(2); }
        $libs($root, ['bootstrap_data', 'timezone', 'UtcClock', 'ConfigVault', 'PluginConfig', 'SalesFollowupLadder']);
        $setNow = function (array $c, string $k): bool { return array_key_exists($k, $c) && $c[$k] !== null && $c[$k] !== '' && $c[$k] !== false; };
        $c = PluginConfig::read($root, $data);
        $stamp = UtcClock::stamp(time());
        $today = (new DateTime('now', new DateTimeZone(SalesFollowupLadder::TZ)))->format('Y-m-d');
        $changes = [];
        $want = function (string $k, string $v) use (&$changes, $c, $setNow): void { if (!$setNow($c, $k)) $changes[$k] = $v; };
        // 104-7 (RH5): spec "only" — these steps alone (with the ledger's two keys, never a record key); spec "except" —
        // every step but these. Each a list of the ladder's own step names; a name the ladder does not have is refused.
        $only = array_map('strval', (array)($s['only'] ?? [])); $except = array_map('strval', (array)($s['except'] ?? []));
        foreach (array_merge($only, $except) as $nm) if (!array_key_exists($nm, SalesFollowupLadder::STEPS)) { fwrite(STDERR, "refusing: a step the ladder does not have\n"); exit(2); }
        foreach (SalesFollowupLadder::STEPS as $step => $row) {
            if (($only !== [] && !in_array((string)$step, $only, true)) || in_array((string)$step, $except, true)) continue;
            foreach ($row['switch'] as $k => $v) $want((string)$k, (string)$v);
            if ($row['stamp'] !== null) $want((string)$row['stamp'], $stamp);
            foreach ($row['options'] as $o) {
                $t = (string)($o['type'] ?? '');
                if ($t === 'date') $want((string)$o['key'], $today);
                elseif ($t === 'channels') $want((string)$o['key'], (string)$s['channels']);
                else { fwrite(STDERR, "refusing: an option type this helper does not write\n"); exit(2); }
            }
        }
        foreach (SalesFollowupLadder::LEDGER_KEYS as $k) $want($k, $k === 'wa_send_ledger' ? 'on' : $stamp);
        $rec = ['sf_report_ran_at' => $today, 'sf_chase_first_on' => $stamp, 'sf_cold_statement' => (string)$s['statement']];
        foreach (SalesFollowupLadder::RECORD_KEYS as $k) {
            if (!array_key_exists($k, $rec)) { fwrite(STDERR, "refusing: a record key this helper does not know\n"); exit(2); }
            if ($only !== []) continue;   // "only": the steps named and the ledger, never a record key
            $want($k, $rec[$k]);
        }
        $all = SalesFollowupLadder::allKeys();
        foreach (array_keys($changes) as $k) if (!in_array($k, $all, true)) { fwrite(STDERR, "refusing: a key outside the ladder's\n"); exit(2); }
        $r = $changes === [] ? [true] : PluginConfig::saveOverrides($data, $changes);
        $after = PluginConfig::read($root, $data); $n = 0;
        foreach ($all as $k) if ($setNow($after, $k)) $n++;
        $out(['ok' => (bool)($r[0] ?? false), 'n' => count($changes), 'after' => $n]);
        exit(!empty($r[0]) ? 0 : 3);
    }
    if ($mode === 'secrets') {
        [, , $root, $data, $tokf] = $argv + [null, null, '', '', ''];
        $libs($root, ['bootstrap_data', 'timezone', 'UtcClock', 'ConfigVault', 'PluginConfig', 'StoreInterface', 'JsonStore', 'SqliteStore', 'EvoWebhookGuard']);
        $c = PluginConfig::load($root, $data);
        $w = 'unset';
        try {
            $pdo = new PDO('sqlite:' . $data . '/plugin.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $g = new EvoWebhookGuard($pdo, $c, $data);
            $rp = new ReflectionProperty($g, 'secret'); $rp->setAccessible(true);
            $w = strlen((string)$rp->getValue($g)) >= 32 ? 'set' : 'unset';
            if ($w === 'set' && (string)$tokf !== '') {
                if (!is_file((string)$tokf) || (fileperms((string)$tokf) & 0077) !== 0) { fwrite(STDERR, "refusing: the token file must exist, mode 0600\n"); exit(2); }
                file_put_contents((string)$tokf, (string)$rp->getValue($g) . "\n");
            }
        } catch (Throwable $e) { $w = 'unread'; }
        $set = function (string $k) use ($c): string { return trim((string)($c[$k] ?? '')) !== '' ? 'set' : 'unset'; };
        // the assistant's provider as DishNetAiBrain's constructor reads it, and whether that class reads itself configured
        // (a key for that provider): with a provider key stored for voice notes alone, the assistant must stay unconfigured
        $pv = strtolower(trim((string)($c['ai_provider'] ?? 'claude'))) === 'openai' ? 'openai' : 'claude';
        $brain = 'unread';
        try {
            if (is_file($root . '/lib/DishNetAiBrain.php')) { require_once $root . '/lib/DishNetAiBrain.php'; $brain = (new DishNetAiBrain($c))->isConfigured() ? 'configured' : 'unconfigured'; }
        } catch (Throwable $e) { $brain = 'unread'; }
        $out(['claude' => $set('claude_api_key'), 'openai' => $set('openai_api_key'), 'webhook' => $w, 'ai_enabled' => !empty($c['ai_enabled']) ? 'on' : 'off',
              'provider' => $pv, 'brain' => $brain, 'shopbot' => $set('shopbot_ai_url')]);
        exit(0);
    }
    if ($mode === 'provider') {
        // 104-5v: the chain's seed stores ai_provider=openai in kyc_config.json (merged last by PluginConfig::files), so an
        // OpenAI key stored for the voice notes would make the assistant configured for OpenAI. One value, one key, through
        // the code root's own writer; never a secret (saveOverrides refuses those).
        [, , $root, $data, $spec] = $argv + [null, null, '', '', '{}'];
        foreach ([$root . '/lib/PluginConfig.php', $data . '/plugin.sqlite3'] as $f) if (!is_file($f)) { fwrite(STDERR, "refusing: no $f\n"); exit(2); }
        $s = json_decode((string)$spec, true);
        if (!is_array($s) || array_keys($s) !== ['ai_provider'] || ($s['ai_provider'] ?? null) !== 'claude') { fwrite(STDERR, "refusing: this mode writes ai_provider=claude alone\n"); exit(2); }
        $libs($root, ['bootstrap_data', 'timezone', 'UtcClock', 'ConfigVault', 'PluginConfig']);
        $r = PluginConfig::saveOverrides($data, ['ai_provider' => 'claude']);
        $c = PluginConfig::read($root, $data);
        $pv = strtolower(trim((string)($c['ai_provider'] ?? 'claude'))) === 'openai' ? 'openai' : 'claude';
        $out(['ok' => (bool)($r[0] ?? false), 'provider' => $pv]);
        exit(!empty($r[0]) && $pv === 'claude' ? 0 : 3);
    }
    if ($mode === 'open') {
        [, , $root, $data] = $argv + [null, null, '', ''];
        foreach (['lib/SqliteStore.php', 'lib/MigrationRunner.php', 'migrations'] as $f) {
            if (!file_exists($root . '/' . $f)) { fwrite(STDERR, "refusing: the code root holds no $f\n"); exit(2); }
        }
        if (!is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no store\n"); exit(2); }
        $libs($root, ['error_handler', 'bootstrap_data', 'timezone', 'UtcClock', 'ConfigVault', 'StoreInterface', 'JsonStore', 'SqliteStore', 'PluginConfig']);
        $GLOBALS['dataDir'] = $data;
        $store = SqliteStore::create($data);
        $n = (int)$store->getPdo()->query('SELECT COUNT(*) FROM _migrations')->fetchColumn();
        $out(['opened' => true, 'migrations' => $n]);
        exit(0);
    }
    [, , $root, $data] = $argv + [null, null, '', ''];
    foreach (['lib/SqliteStore.php', 'lib/ConversationService.php', 'lib/PluginConfig.php', 'cron/master.php'] as $f) {
        if (!is_file($root . '/' . $f)) { fwrite(STDERR, "refusing: the code root holds no $f\n"); exit(2); }
    }
    if (!is_file($data . '/plugin.sqlite3')) { fwrite(STDERR, "refusing: no store\n"); exit(2); }
    $libs($root, ['error_handler', 'bootstrap_data', 'timezone', 'UtcClock', 'ConfigVault', 'StoreInterface', 'JsonStore', 'SqliteStore',
                  'PluginConfig', 'ContactOptOut', 'FollowUpPolicy', 'FollowUpService', 'WaInbound', 'ConversationService', 'StaffJobsGate',
                  'EvoWebhookGuard', 'WaSendBudget']);
    $GLOBALS['dataDir'] = $data;
    $store = SqliteStore::create($data);
    $pdo = $store->getPdo();
    if ($mode === 'admin') {
        // the offers screen's admin, through the code root's own account writer — its e-mail lower-cased, its password hashed
        $s = json_decode((string)($argv[4] ?? ''), true);
        $pw = (string)getenv('RH104_ADMIN_PW');
        $em = is_array($s) ? strtolower(trim((string)($s['email'] ?? ''))) : '';
        if (!is_array($s) || strpos((string)($s['name'] ?? ''), 'RH104SECRET-') !== 0 || !$ours($s['phone'] ?? null) || (string)($s['phone'] ?? '') === ''
            || strpos($em, 'rh104secret-') !== 0 || strpos($pw, 'RH104SECRET-') !== 0) { fwrite(STDERR, "refusing: not the rehearsal's own admin\n"); exit(2); }
        $libs($root, ['RetailerAuth']);
        if (!class_exists('RetailerAuth')) { fwrite(STDERR, "refusing: the code root has no RetailerAuth\n"); exit(2); }
        foreach ((array)($store->load('retailers.json') ?? []) as $r) {
            if (is_array($r) && strtolower(trim((string)($r['email'] ?? ''))) === $em) { fwrite(STDERR, "refusing: that e-mail has an account already\n"); exit(2); }
        }
        $row = (new RetailerAuth($store))->createRetailer(['name' => (string)$s['name'], 'email' => (string)$s['email'], 'phone' => (string)$s['phone'],
                                                         'password' => $pw, 'role' => 'admin', 'is_admin' => true]);
        $chk = $store->findOne('retailers.json', 'email', $em);
        $made = (int)($row['id'] ?? 0) > 0;
        $out(['made' => $made, 'found' => is_array($chk) && (int)($chk['id'] ?? 0) === (int)($row['id'] ?? -1),
              'lower' => (string)($row['email'] ?? '') === $em, 'admin' => !empty($row['is_admin']), 'must_change' => !empty($row['must_change_pwd'])]);
        exit($made ? 0 : 3);
    }
    if ($mode === 'warm') {
        $s = json_decode((string)($argv[4] ?? ''), true);
        $cv = is_array($s) ? (array)($s['convs'] ?? []) : [];
        if ($cv === []) { fwrite(STDERR, "refusing: no conversations\n"); exit(2); }
        foreach ($cv as $c) {
            if (!is_array($c) || !$ours($c['phone'] ?? null) || (string)($c['phone'] ?? '') === '' || !$ours($c['name'] ?? null) || !$ours($c['text'] ?? null)
                || !$ours($c['reply'] ?? null) || !in_array((string)($c['channel'] ?? ''), ['sales', 'support'], true)
                || (int)($c['ago_min'] ?? 0) < 130 || (int)($c['ago_min'] ?? 0) > 20160
                || (int)($c['reply_after_min'] ?? 0) < 1 || (int)($c['reply_after_min'] ?? 0) >= (int)($c['ago_min'] ?? 0) - 120) {
                fwrite(STDERR, "refusing: a conversation that is not the rehearsal's own, or not warm and answered\n"); exit(2);
            }
        }
        $conv = new ConversationService($data, $pdo);
        $now = time(); $n = 0;
        foreach ($cv as $c) {
            $ch = (string)$c['channel'];
            $row = $conv->ensureConversation((string)$c['phone'], $ch, (string)$c['name'], 'webhook');
            $cid = (int)($row['id'] ?? 0);
            if ($cid <= 0) throw new RuntimeException('conv');
            $digits = preg_replace('/\D/', '', (string)$c['phone']);
            $at = $now - 60 * (int)$c['ago_min'];
            $in = $conv->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => (string)$c['text'],
                'wa_message_id' => 'RH104WI' . strtoupper(substr(hash('sha256', $digits . '|' . $ch . '|warm'), 0, 16)), 'sent_at' => gmdate('Y-m-d H:i:s', $at)]);
            $rp = $conv->storeMessage($cid, ['direction' => 'out', 'role' => 'assistant', 'agent_name' => 'DishNet AI', 'body' => (string)$c['reply'],
                'wa_message_id' => 'RH104WR' . strtoupper(substr(hash('sha256', $digits . '|' . $ch . '|reply'), 0, 16)),
                'sent_at' => gmdate('Y-m-d H:i:s', $at + 60 * (int)$c['reply_after_min'])]);
            if ((int)$in > 0 && (int)$rp > 0) $n++;
        }
        $out(['warm' => $n]);
        exit($n === count($cv) ? 0 : 3);
    }
    if ($mode === 'mediaworker') {
        $s = json_decode((string)($argv[4] ?? ''), true);
        $ep = is_array($s) ? (string)($s['endpoint'] ?? '') : '';
        if (!preg_match('#^http://127\.0\.0\.1:\d{2,5}/v1/audio/transcriptions$#', $ep)) { fwrite(STDERR, "refusing: the transcriber's endpoint is the rehearsal's stand-in on 127.0.0.1 alone\n"); exit(2); }
        foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'all_proxy'] as $v) putenv($v);   // unset: nothing may leave 127.0.0.1
        putenv('NO_PROXY=*'); putenv('no_proxy=*');
        putenv('DN_DATA_DIR=' . $data);                                                                                  // the worker's own data folder rule
        $libs($root, ['EventBus', 'MediaPolicy', 'MediaBlob', 'InboundMedia', 'MediaFetcher', 'Transcriber', 'VoiceDuration', 'VoiceBudget',
                      'OpenAiTranscriber', 'VoiceTranscription', 'Handover', 'AlertService', 'LineOwner', 'EvolutionApiService']);
        foreach (['workers/WorkerBase.php', 'workers/MediaWorker.php'] as $f) {
            if (!is_file($root . '/' . $f)) { fwrite(STDERR, "refusing: the code root holds no $f\n"); exit(2); }
            require_once $root . '/' . $f;
        }
        if (!class_exists('MediaWorker') || !method_exists('MediaWorker', 'useTranscriber') || !class_exists('OpenAiTranscriber')) { fwrite(STDERR, "refusing: no seam\n"); exit(2); }
        $cfg = PluginConfig::load($root, $data);                                                                          // as run_media_worker.php loads it
        $w = new MediaWorker($store, $cfg, (int)min(90, max(10, (int)($s['max'] ?? 60))), 1);
        $w->useTranscriber(new OpenAiTranscriber(PluginConfig::read($root, $data), $ep));                                // the seam; the key read by the adapter itself
        ob_start();
        try { $r = $w->run(); } finally { $trace = (string)ob_get_clean(); }
        @file_put_contents($data . '/ai_platform.log', $trace . sprintf("[%s] media worker: processed=%d failed=%d deferred=%d\n", gmdate('Y-m-d H:i:s'),
                           (int)($r['processed'] ?? 0), (int)($r['failed'] ?? 0), (int)($r['deferred'] ?? 0)), FILE_APPEND);
        $out(['processed' => (int)($r['processed'] ?? 0), 'failed' => (int)($r['failed'] ?? 0), 'deferred' => (int)($r['deferred'] ?? 0)]);
        exit(0);
    }
    if ($mode === 'quote') {
        $s = json_decode((string)($argv[4] ?? ''), true);
        if (!is_array($s)) { fwrite(STDERR, "refusing: no spec\n"); exit(2); }
        foreach (['phone', 'name', 'ref', 'in_text'] as $k) if (!$ours($s[$k] ?? null)) { fwrite(STDERR, "refusing: a value that is not the rehearsal's own\n"); exit(2); }
        $ch = (string)($s['channel'] ?? 'sales');
        $conv = new ConversationService($data, $pdo);
        $c = $conv->ensureConversation((string)$s['phone'], $ch, (string)$s['name'], 'webhook');
        $cid = (int)($c['id'] ?? 0);
        if ($cid <= 0) throw new RuntimeException('conv');
        $now = time(); $gap = 60 * (int)($s['gap_min'] ?? 5);
        $digits = preg_replace('/\D/', '', (string)$s['phone']);
        $in = $conv->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => (string)$s['in_text'],
            'wa_message_id' => 'RH104IN' . strtoupper(substr(hash('sha256', $digits . '|' . $s['ref']), 0, 16)), 'sent_at' => gmdate('Y-m-d H:i:s', $now - $gap)]);
        $valid = (new DateTime('@' . ($now + 14 * 86400)))->setTimezone(new DateTimeZone('Africa/Kampala'))->format('d M Y');
        $body = "RH104SECRET-quotation for RH104SECRET-kit\n📋 *Ref:* " . (string)$s['ref'] . "\n⏳ *Valid Until:* " . $valid . "\n";
        $q = $conv->storeMessage($cid, ['direction' => 'out', 'role' => 'agent', 'agent_name' => 'RH104SECRET-Grace', 'body' => $body,
            'event_key' => 'quote_manual', 'wa_message_id' => 'RH104Q' . strtoupper(substr(hash('sha256', (string)$s['ref']), 0, 16)),
            'sent_at' => gmdate('Y-m-d H:i:s', $now)]);
        $out(['conv' => $cid, 'in' => (int)$in, 'quote' => (int)$q, 'at' => $now]);
        exit(((int)$in > 0 && (int)$q > 0) ? 0 : 3);
    }
    if ($mode === 'schedule') {
        $s = json_decode((string)($argv[4] ?? ''), true);
        if (!is_array($s)) { fwrite(STDERR, "refusing: no spec\n"); exit(2); }
        $due = array_map('strval', (array)($s['due'] ?? [])); $keep = array_map('strval', (array)($s['keep'] ?? []));
        $set = (array)($s['set'] ?? []);
        foreach ($set as $j => $e) {
            if (!is_array($e) || !is_int($e['last_run'] ?? null) || (int)$e['last_run'] <= 0 || !is_int($e['duration_ms'] ?? null) || (int)$e['duration_ms'] < -1
                || in_array((string)$j, $due, true) || in_array((string)$j, $keep, true)) { fwrite(STDERR, "refusing: a set entry is not master's form\n"); exit(2); }
        }
        preg_match_all("/^\s+'([a-z_]+)'\s*=>\s*\[\s*'interval'/m", (string)file_get_contents($root . '/cron/master.php'), $m);
        $jobs = array_values(array_unique($m[1]));
        if (count($jobs) < 20) throw new RuntimeException('jobs');
        foreach (array_merge($due, $keep, array_map('strval', array_keys($set))) as $j) if (!in_array($j, $jobs, true)) { fwrite(STDERR, "refusing: a job master does not have\n"); exit(2); }
        $sch = (array)($store->load('master_schedule.json') ?? []);
        $n = 0;
        $onlySet = !empty($s['only']);   // 104-7 (RH5): spec "only" — the "set" entries alone, every other entry as it stands
        if ($onlySet && ($due !== [] || $keep !== [] || $set === [])) { fwrite(STDERR, "refusing: only goes with set alone\n"); exit(2); }
        foreach ($jobs as $j) {
            if (in_array($j, $due, true) || in_array($j, $keep, true)) continue;
            if ($onlySet && !array_key_exists($j, $set)) continue;
            if (array_key_exists($j, $set)) {
                $sch[$j] = ['last_run' => (int)$set[$j]['last_run'], 'last_run_at' => date('Y-m-d H:i:s', (int)$set[$j]['last_run']), 'duration_ms' => (int)$set[$j]['duration_ms']];
                continue;
            }
            $sch[$j] = ['last_run' => time(), 'last_run_at' => date('Y-m-d H:i:s'), 'duration_ms' => 0];
            $n++;
        }
        $store->save('master_schedule.json', $sch);
        $out(['quiet' => $n, 'due' => count($due), 'set' => count($set), 'jobs' => count($jobs)]);
        exit(0);
    }
    if ($mode === 'remind') {
        // 104-7f (RH5): a reminder run of the earlier build, begun before the clear — NotificationService constructed as
        // cron/customer_reminders.php constructs it (the store row over PluginConfig::load, read ONCE, the ledger on), then held
        // until the rehearsal's go file, then one payment reminder (invoiceDue3Days, as the reminders job sends it) through the
        // stand-in Evolution: its record-only hook (WaSendBudget::recordNotify) writes one `record` row. Refuses unless the
        // accounts sender's transport is Evolution (never WASender: nothing may leave 127.0.0.1) and the ledger reads on.
        $s = json_decode((string)($argv[4] ?? ''), true);
        if (!is_array($s) || !$ours($s['phone'] ?? null) || (string)($s['phone'] ?? '') === '' || !$ours($s['name'] ?? null) || !$ours($s['invoice'] ?? null)
            || strpos((string)($s['marker'] ?? ''), '/act104/') === false) { fwrite(STDERR, "refusing: not the rehearsal's own reminder\n"); exit(2); }
        $libs($root, ['currency', 'ContactOptOut', 'CustomerContact', 'NotifyGate', 'PluginLog', 'TenantProfile', 'HumanTakeover', 'EvolutionApiService', 'NotificationService']);
        if (!class_exists('NotificationService')) { fwrite(STDERR, "refusing: the code root has no NotificationService\n"); exit(2); }
        $cfg = (array)($store->load('kyc_config.json') ?? []) + PluginConfig::load($root, $data);
        if ((string)($cfg['wa_send_ledger'] ?? '') !== 'on') { $out(['ok' => false, 'why' => 'ledger_off']); exit(3); }
        $ns = new NotificationService($store, $cfg);
        if ($ns->phoneTransport(NotificationService::ACCOUNTS) !== 'evolution') { $out(['ok' => false, 'why' => 'not_evolution']); exit(3); }
        $mk = (string)$s['marker'];
        @touch($mk . '.ready');
        $t0 = microtime(true);
        while (!is_file($mk . '.go') && microtime(true) - $t0 < 600) usleep(20000);
        if (!is_file($mk . '.go')) { $out(['ok' => false, 'why' => 'no_go']); exit(3); }
        $ns->invoiceDue3Days((string)$s['phone'], (string)$s['name'], (string)$s['invoice'], 1.0, 'UGX', gmdate('Y-m-d', time() + 3 * 86400));
        $r = $ns->lastSendResult();
        @touch($mk . '.done');
        $out(['ok' => !empty($r['success']), 'http' => (int)($r['http_code'] ?? 0), 'at' => time()]);
        exit(!empty($r['success']) ? 0 : 3);
    }
    if ($mode === 'deadev') {
        // 104-7v (RH5): the voice history's dead event, if the earlier runs left none — through the code root's own EventBus: emit
        // spec "type" (ai.media or ai.voice_ack) as the webhook's step 8c names it, then fail() it until it reads dead (its
        // max_attempts, EventBus's own failure path); the payload names no person, no number and no message
        $s = json_decode((string)($argv[4] ?? ''), true);
        $ty = is_array($s) ? (string)($s['type'] ?? '') : '';
        if (!in_array($ty, ['ai.media', 'ai.voice_ack'], true)) { fwrite(STDERR, "refusing: a type this mode does not make\n"); exit(2); }
        $libs($root, ['EventBus']);
        if (!class_exists('EventBus')) { fwrite(STDERR, "refusing: the code root has no EventBus\n"); exit(2); }
        $bus = new EventBus($pdo);
        $id = $bus->emit($ty, 'rh104', 0, ['rh104' => 'history'], 5, 'rh104');
        $dead = false; $n = 0;
        while (!$dead && $n < 12) { $n++; $dead = $bus->fail((int)$id, 'rh104 history'); }
        $st = (string)$pdo->query('SELECT status FROM events WHERE id = ' . (int)$id)->fetchColumn();
        $out(['event' => $id > 0, 'status' => $st, 'fails' => $n]);
        exit($st === 'dead' ? 0 : 3);
    }
    if ($mode === 'claim') {
        $s = json_decode((string)($argv[4] ?? ''), true);
        if (!is_array($s) || !$ours($s['phone'] ?? null) || strpos((string)($s['ref'] ?? ''), 'rh104-') !== 0) { fwrite(STDERR, "refusing: not the rehearsal's own claim\n"); exit(2); }
        $c = PluginConfig::load($root, $data);
        $inst = (string)($c['evo_instance_' . (string)($s['channel'] ?? 'support')] ?? '');
        $b = WaSendBudget::forInstall($c, $data, $store);
        $r = $b->claim($inst, (string)($s['kind'] ?? 'enquiry'), (string)$s['ref'], ['phone' => (string)$s['phone']]);
        $out(['ok' => !empty($r['ok']), 'claim' => (int)($r['claim'] ?? 0), 'defer' => preg_replace('/[^a-z_]/', '', (string)($r['defer'] ?? ''))]);
        exit(!empty($r['ok']) ? 0 : 3);
    }
    if ($mode === 'webhook') {
        $url = (string)($argv[4] ?? ''); $s = json_decode((string)($argv[5] ?? ''), true);
        if (strpos($url, 'http://127.0.0.1:') !== 0) { fwrite(STDERR, "refusing: the webhook is the sandbox's own, on 127.0.0.1\n"); exit(2); }
        // the text the rehearsal's own (an RH104SECRET- value), or the one fixed word STOP (a customer's opt-out, no personal data)
        if (!is_array($s) || !$ours($s['phone'] ?? null) || !$ours($s['name'] ?? null) || !(($s['text'] ?? null) === 'STOP' || $ours($s['text'] ?? null))
            || strpos((string)($s['id'] ?? ''), 'RH104') !== 0) {
            fwrite(STDERR, "refusing: a value that is not the rehearsal's own\n"); exit(2);
        }
        $c = PluginConfig::load($root, $data);
        $g = new EvoWebhookGuard($pdo, $c, $data);
        $rp = new ReflectionProperty($g, 'secret'); $rp->setAccessible(true); $tok = (string)$rp->getValue($g);
        if (strlen($tok) < 32) { $out(['http' => 0, 'answer' => 'nosecret']); exit(3); }
        $inst = (string)($c['evo_instance_' . (string)($s['channel'] ?? 'sales')] ?? '');
        $digits = preg_replace('/\D/', '', (string)$s['phone']);
        $kind = (string)($s['kind'] ?? 'text');
        $msg = $kind === 'audio' ? ['audioMessage' => ['mimetype' => 'audio/ogg; codecs=opus', 'seconds' => 7, 'ptt' => true,
                                    'url' => 'https://mmg.whatsapp.net/rh104', 'mediaKey' => 'cmgxMDQ=', 'fileLength' => '1024']]
             : ($kind === 'wrapped' ? ['ephemeralMessage' => ['message' => ['conversation' => (string)$s['text']]]]
             : ['conversation' => (string)$s['text']]);
        // spec from_me: the echo Evolution sends of a message the plugin itself sent (the same id), as it reaches the webhook
        $payload = ['event' => 'messages.upsert', 'instance' => $inst, 'data' => ['key' => ['remoteJid' => $digits . '@s.whatsapp.net', 'fromMe' => !empty($s['from_me']), 'id' => (string)$s['id']],
                    'pushName' => (string)$s['name'], 'message' => $msg, 'messageType' => array_keys($msg)[0], 'messageTimestamp' => time()]];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-DishNet-Token: ' . $tok]]);
        $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = json_decode($body, true);
        $word = is_array($j) ? preg_replace('/[^a-z_]/', '', strtolower((string)($j['status'] ?? $j['result'] ?? $j['message'] ?? 'answered'))) : 'unparsed';
        $out(['http' => $code, 'answer' => $word, 'at' => time()]);
        exit($code === 200 ? 0 : 3);
    }
    if (in_array($mode, ['offerprep', 'offerstale', 'offerstart', 'offerclaim', 'offertest', 'offerstop'], true)) {
        // 104-6's running offer, through the code root's own offer writers (OfferStore, OfferSender) — the classes the
        // screen's handler loads, each loading what it needs — and, for the two states only a stopped sender leaves, the
        // sender's own statements (said in the mode's line above). Every phone, wording and name is the rehearsal's own.
        $libs($root, ['TenantProfile', 'StaffDirectory', 'PluginLog', 'OfferSwitch', 'OfferSchema', 'OfferText', 'OfferImage', 'OfferGateway',
                      'EvolutionOfferGateway', 'OfferAudience', 'OfferStore', 'OfferSender']);
        foreach (['OfferStore', 'OfferSender', 'OfferSwitch', 'EvolutionOfferGateway'] as $k) if (!class_exists($k)) { fwrite(STDERR, "refusing: the code root has no $k\n"); exit(2); }
        $s = json_decode((string)($argv[4] ?? ''), true);
        if (!is_array($s)) { fwrite(STDERR, "refusing: no spec\n"); exit(2); }
        $cfg = PluginConfig::read($root, $data);
        $rows = (array)($store->load('retailers.json') ?? []);
        $admin = null;
        foreach ($rows as $r) {
            if (!is_array($r) || empty($r['is_admin']) || empty($r['is_active'])) continue;
            if (strpos((string)($r['name'] ?? ''), 'RH104SECRET-') !== 0 || (string)($r['phone'] ?? '') === '' || !$ours((string)$r['phone'])) continue;
            $admin = $r; break;
        }
        if ($admin === null) { fwrite(STDERR, "refusing: no admin of the rehearsal's own with a phone of record\n"); exit(2); }
        if ($mode === 'offerprep') {
            foreach (['title', 'w1', 'w2'] as $k) if (strpos((string)($s[$k] ?? ''), 'RH104SECRET') !== 0) { fwrite(STDERR, "refusing: a wording that is not the rehearsal's own\n"); exit(2); }
            if (!preg_match('/^[a-z0-9_-]{1,40}$/', (string)($s['channel'] ?? ''))) { fwrite(STDERR, "refusing: no channel\n"); exit(2); }
            $cr = OfferStore::create($pdo, (string)$s['title'], [(string)$s['w1'], (string)$s['w2']], 'all', (int)$admin['id'], (string)$admin['name'], time());
            if (empty($cr['ok'])) { $out(['offer' => 0, 'errors' => implode(',', array_map(function ($e) { return preg_replace('/[^a-z0-9_]/', '', strtolower((string)$e)); }, (array)$cr['errors']))]); exit(3); }
            $snd = OfferSender::forInstall($root, $data, $cfg, ['store' => $store]);
            $t = [];
            foreach ([1, 2] as $v) {
                $r = $snd->sendTest((int)$cr['id'], $v, (string)$s['channel'], $admin);
                $t[] = !empty($r['ok']) ? 'sent' : substr((string)preg_replace('/[^a-z0-9_:]/', '', strtolower((string)$r['code'])), 0, 40);
            }
            $out(['offer' => (int)$cr['id'], 'tests' => implode(',', $t)]);
            exit(implode(',', $t) === 'sent,sent' ? 0 : 3);
        }
        $o = OfferStore::find($pdo, (int)($s['offer'] ?? 0));
        if ($o === null) { fwrite(STDERR, "refusing: no such offer\n"); exit(2); }
        if ($mode === 'offerstale') {
            $age = (int)($s['age_s'] ?? 0);
            $young = !empty($s['young']);
            if ($young && ($age < 60 || $age > 300)) { fwrite(STDERR, "refusing: a young test is between one and five minutes old\n"); exit(2); }
            if (!$young && ($age < 960 || $age > 86400)) { fwrite(STDERR, "refusing: a stale test is between 16 minutes and a day old\n"); exit(2); }
            $q = $pdo->prepare("SELECT variant, staff_id, channel, handset FROM wa_offer_tests WHERE offer_id = ? AND state = 'sent' ORDER BY id DESC LIMIT 1");
            $q->execute([(int)$o['id']]);
            $t = $q->fetch(PDO::FETCH_ASSOC); $q->closeCursor();
            if (!is_array($t)) { fwrite(STDERR, "refusing: no sent test to stand beside\n"); exit(2); }
            // raw, as OfferSender::sendTest inserts a test before its send: only a sender stopped mid-test leaves one `sending`
            $pdo->prepare("INSERT INTO wa_offer_tests (offer_id, content_hash, variant, staff_id, channel, handset, state, started_at)
                           VALUES (?, ?, ?, ?, ?, ?, 'sending', ?)")
                ->execute([(int)$o['id'], (string)$o['content_hash'], (int)$t['variant'], (int)$t['staff_id'], (string)$t['channel'], (string)$t['handset'], UtcClock::stamp(time() - $age)]);
            $out([$young ? 'young' : 'stale' => 1, 'id' => (int)$pdo->lastInsertId()]);
            exit(0);
        }
        if ($mode === 'offerstart') {
            $r = OfferStore::start($pdo, $cfg, $data, $rows, (array)($store->load('kyc_config.json') ?? []), (int)$o['id'], (string)$o['content_hash'],
                                   (int)$admin['id'], (string)$admin['name'], time());
            $out(['ok' => !empty($r['ok']), 'code' => substr((string)preg_replace('/[^a-z0-9_:]/', '', strtolower((string)$r['code'])), 0, 60), 'queued' => (int)$r['queued']]);
            exit(!empty($r['ok']) ? 0 : 3);
        }
        if ($mode === 'offerstop') {
            // 104-7o (RH5-fix1): migration 091 holds one live offer (live_slot = 1 while running OR paused, a unique index on
            // it; OfferStore::start answers another_live while the slot is taken, and the off-hooks pause with the slot kept),
            // so an offer the rollback paused is stopped as the admin stops it on the screen before another can start
            $ok = OfferStore::stop($pdo, (int)$o['id'], (int)$admin['id'], (string)$admin['name'], time());
            $out(['ok' => $ok]);
            exit($ok ? 0 : 3);
        }
        if ($mode === 'offertest') {
            // 104-7o (RH5): one test of the offer's wording spec "variant" on spec "channel", by the admin, as the screen's
            // offer_test handler sends it (OfferSender::sendTest) — pressed just before the clear; the stand-in Evolution may
            // hold its answer (rh104-slow), and the test is then recorded and settled when it answers
            if (!preg_match('/^[a-z0-9_-]{1,40}$/', (string)($s['channel'] ?? '')) || !in_array((int)($s['variant'] ?? 0), [1, 2, 3], true)) { fwrite(STDERR, "refusing: no channel or variant\n"); exit(2); }
            $snd = OfferSender::forInstall($root, $data, $cfg, ['store' => $store]);
            $r = $snd->sendTest((int)$o['id'], (int)$s['variant'], (string)$s['channel'], $admin);
            $out(['ok' => !empty($r['ok']), 'code' => substr((string)preg_replace('/[^a-z0-9_:]/', '', strtolower((string)($r['code'] ?? ''))), 0, 40), 'at' => time()]);
            exit(!empty($r['ok']) ? 0 : 3);
        }
        // offerclaim
        if ((string)$o['state'] !== 'running') { fwrite(STDERR, "refusing: the offer is not running\n"); exit(2); }
        $q = $pdo->prepare("SELECT * FROM wa_offer_recipients WHERE offer_id = ? AND state = 'queued' ORDER BY rank LIMIT 20");
        $q->execute([(int)$o['id']]);
        $cand = array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC), function ($r) use ($ours) { return $ours('+' . (string)$r['phone']); }));
        $q->closeCursor();
        if (count($cand) < 2) { $out(['a' => 0, 'b' => 0, 'queued_ours' => count($cand)]); exit(3); }
        [$ra, $rb] = [$cand[0], $cand[1]];
        // the first: step 6 first, WaSendBudget's own claim on the recipient's own ledger under its stable ref (only a claim
        // the ledger grants is recorded); then, as one stopped sender leaves it, steps 2, 4 and 7's row writes
        $fb = WaSendBudget::forInstall($cfg, $data, $store);
        $a = $fb->claim((string)$ra['handset'], 'offer', 'offer:' . (int)$o['id'] . ':' . (int)$ra['id'], ['phone' => (string)$ra['phone'], 'in_flight_s' => OfferSender::IN_FLIGHT_S]);
        if (empty($a['ok'])) {
            $out(['a' => 0, 'b' => 0, 'defer' => preg_replace('/[^a-z_]/', '', (string)($a['defer'] ?? '')), 'retry_at' => is_int($a['retry_at'] ?? null) ? (int)$a['retry_at'] : 0]);
            exit(3);
        }
        $dm = OfferSwitch::settings($cfg)['offers_daily_max_per_handset'] ?? null;
        $dm = is_int($dm) && $dm >= 1 && $dm <= 60 ? $dm : 1;
        $at = UtcClock::stamp(time());
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $ta = bin2hex(random_bytes(8)); $tb = bin2hex(random_bytes(8));
            $n = 0;
            $st = $pdo->prepare("UPDATE wa_offer_recipients SET state = 'claimed', claim_token = ?, claimed_at = ?, attempt = attempt + 1 WHERE id = ? AND state = 'queued'");
            $st->execute([$ta, $at, (int)$ra['id']]); $n += $st->rowCount();
            $st = $pdo->prepare("UPDATE wa_offer_recipients SET judged_at = ?, day_max = ? WHERE id = ? AND state = 'claimed' AND claim_token = ?");
            $st->execute([$at, $dm, (int)$ra['id'], $ta]); $n += $st->rowCount();
            $st = $pdo->prepare("UPDATE wa_offer_recipients SET ledger_claim = ?, variant = ? WHERE id = ? AND state = 'claimed' AND claim_token = ?");
            $st->execute([(int)$a['claim'], 1, (int)$ra['id'], $ta]); $n += $st->rowCount();
            $st = $pdo->prepare("UPDATE wa_offer_recipients SET state = 'claimed', claim_token = ?, claimed_at = ?, attempt = attempt + 1 WHERE id = ? AND state = 'queued'");
            $st->execute([$tb, $at, (int)$rb['id']]); $n += $st->rowCount();
            if ($n !== 4) throw new RuntimeException('rows');
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) { /* none open */ }
            $fb->settle((int)$a['claim'], 'failed');
            throw $e;
        }
        $out(['a' => (int)$ra['id'], 'b' => (int)$rb['id'], 'claim' => (int)$a['claim']]);
        exit(0);
    }
    if ($mode === 'vcall') {
        $libs($root, ['MediaPolicy', 'VoiceBudget']);
        if (!class_exists('VoiceBudget')) { fwrite(STDERR, "refusing: the code root has no VoiceBudget\n"); exit(2); }
        $r = $pdo->query("SELECT id, conversation_id FROM wa_media WHERE kind = 'audio' ORDER BY (status = 'pending'), id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) { fwrite(STDERR, "refusing: no audio row to stand behind a reservation\n"); exit(2); }
        $res = (new VoiceBudget($pdo, PluginConfig::read($root, $data)))->reserve((int)$r['id'], (int)$r['conversation_id'], 1, 7, 'whisper-1', 'dry_run');
        $out(['ok' => !empty($res['ok']), 'reason' => preg_replace('/[^a-z_]/', '', (string)($res['reason'] ?? ''))]);
        exit(!empty($res['ok']) ? 0 : 3);
    }
    fwrite(STDERR, "refusing: a mode this helper does not know\n"); exit(2);
} catch (Throwable $e) { fwrite(STDERR, 'refused: ' . get_class($e) . ' ' . preg_replace('/[^a-z :]/', '', strtolower($e->getMessage())) . "\n"); exit(3); }

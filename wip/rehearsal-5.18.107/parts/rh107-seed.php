<?php
// 5.18.107's rehearsal: its plants (design-v2 §A3, §A7, §A13, §A16; design-v3 §A), each with its undo, over the live data
// folder. Written through the release's own classes where one exists — PluginConfig::saveOverrides for the settings text
// and for clearing a switch (the known-limits route, A13), the store's own load() and save() for a store row, EventBus::emit
// and ::fail and BurstMerge::markAside and ::claim for a burst, ConversationService::ensureConversation for its conversation,
// MigrationRunner for mig092 — and by a fixture where none does, said so in the output ("fixture": 1): the migration ledger,
// migration.log, 092's table and its sentinel row, a row that does not decode. Facts: wip/rehearsal-5.18.107/map-seed-facts.md.
//
// NEVER A BOOT BY ACCIDENT. SqliteStore::create() runs the code root's migrations over the live book, so a store made that
// way from the pin's tree while 5.18.106 serves would apply 092 — the one thing every first-deploy case must leave to the
// deploy. This helper therefore makes its store WITHOUT the factory (the class's own constructor, reached by reflection: the
// same object create() returns, minus the boot), and the two ops whose class boots by itself (PluginConfig::saveOverrides
// mirrors through create(): settext, clear107) are refused where the code root carries migrations/092_wa_no_ai.sql and the
// ledger has no 092. mig092 is the one op that applies it, on purpose, through the code root's MigrationRunner.
//
//   php rh107-seed.php <code root> <data dir> <ops as JSON: [{"op":…}, …]>
// Environment: DN_VAULT_FILE (into the sandbox, never the live vault) and RH107_RANGE (ten digits, 2567001070). Run as the
// book's owner (uid 1000) under the rehearsal's clock (LD_PRELOAD, RH104_CLOCK_FILE): every stamp is the clock's.
//
// The ops (keep is an absolute folder of the caller's, tag [a-z0-9-]{1,40}; every fixture keeps what it changes there first):
//   settext       {"text":"RH107SECRET- …"}                         bot_custom_instructions through PluginConfig::saveOverrides
//   clear107      {"keys":[…]}                                       K, Q, W.1 or a text key cleared from kyc_config.json AND the
//                                                                    store row through PluginConfig::saveOverrides ('' clears both)
//   store_keys107 {"keys":{key: value,…}}  /  store_keys107_undo {"keys":[…]}
//                                          a key put into the store row alone (and taken out), the file asserted unchanged
//   burst         {"phone","name","text","text2","instance","mark":"aside"|"claim"|"none"[,"fail":1][,"origin":"voice"]}
//                                          two typed ai.reply events on one conversation, as evo_webhook emits them; "aside"
//                                          marks the first for the second (a waits, b unsettled), "claim" takes the first (a
//                                          only), "fail" fails the second once (failed, 1 of its attempts, retrying)
//   noai          {"phone","name","reason"[,"alert":1]}
//                                          a number listed through the code root's NoAiList::add (as the staff screens list it),
//                                          and with alert the wa.no_ai_alert queued as evo_webhook queues it (EventBus::emit, its
//                                          payload); undone by book_back (a wa_no_ai row is never deleted)
//   burst_undo    {"ids":[…]}              the events this helper emitted, removed (fixture; each checked to be its own)
//   sched         {"keep","tag","set":{job:{"ago_s":n|"at":epoch,"duration_ms":n}}[,"drop":[job,…]]}
//   sched_raw     {"keep","tag","raw":"…"}  /  sched_back {"keep","tag"}
//                                          master_schedule's row: entries set through the store's save(); a row that does not
//                                          decode (fixture); and the row put back byte for byte (fixture)
//   budget        {"keep","tag","seconds":n|null}  /  budget_back {"keep","tag"}
//                                          master_budget_seconds in the store's kyc_config row (it lives nowhere else; set_config
//                                          refuses it), through the store's load() and save(); null takes it out
//   book_keep     {"keep","tag"}  /  book_back {"keep","tag"}
//                                          the whole book kept and put back through SQLite's backup (fixture) — the undo of every
//                                          ledger and table plant that cannot be reversed in place (a rename rewrites the SQL text
//                                          SQLite stores, and a wa_no_ai row is never deleted)
//   ledger092     {"what":"rename"|"checksum"|"sentinel"|"unledgered"|"partial","keep","tag"[,"phone"]}  /  ledger092_undo {"what","keep","tag"}
//   ledger071     {"keep","tag"}  /  ledger071_undo {"keep","tag"}
//                                          fixtures: 092's table renamed (the book kept first); 092's or 071's ledger checksum
//                                          rewritten to another md5 (the original kept); NA-CA1's first sentinel — the script's
//                                          own constant, passed in, 2560…: no number anyone can hold — inserted active (the book
//                                          kept first); 092's five objects made by its own statements with no ledger row and no
//                                          _schema_checks row (the pairing's ground); 092 recorded with its _schema_checks row
//                                          taken out (a partial 092) — each with the book kept first
//   log_keep      {"keep","tag"}  /  log_back {"keep","tag"}           <data>/migration.log kept, and put back byte for byte
//   log_add       {"keep","tag","line":"ok092"|"warning092"|"partial092"|"warning071"}
//   log_drop      {"keep","tag","match":"ok092"|"warning092"|"partial092"|"any092"}
//   log_mark      {"keep","tag","offset_s":n}
//   log_fill      {"keep","tag","over":n}
//                                          fixtures on migration.log, each refused unless log_keep kept it under that tag: one
//                                          line appended in the runner's own format, stamped at the clock's time (UTC); every
//                                          line of that kind taken out; the runner's halving imitated — every 092 line taken out
//                                          and the trim marker written as line 1, stamped (UTC) at 092's applied_at plus
//                                          offset_s, never from the clock (design-v2 RM13); and fictitious lines naming no
//                                          migration appended until the file is over "over" bytes
//   mig092        {}                       the code root's MigrationRunner over the live book, its log <data>/migration.log —
//                                          refused unless the code root carries 092 and the ledger has none; asserted: one new
//                                          ledger row, 092's, and one new OK line for it
// Prints one line of JSON: the last op's fields at the top level (as sd107 reads them), then "ops", "at" (the clock's
// time) and "done" (every op's). Counts, ids, codes, UTC times and the first 16 hex of sha256s — never a name, a phone, a
// text or a value. Exit 2: refused before anything was opened; 3: refused after N ops.
declare(strict_types=1);
ini_set('display_errors', 'stderr');

const RH107_MARK = 'RH107SECRET-';
const RH107_SW = ['quoted_no_prices_enabled', 'connection_quote_enabled', 'ai_burst_merge_enabled'];
const RH107_TXT = ['quoted_reply_text', 'quoted_alert_cooldown_hours', 'connection_quote_transport', 'connection_quote_tax_line',
                   'connection_quote_closing', 'no_ai_alert_cooldown_hours', 'ai_burst_merge_window_minutes'];
const RH107_PROSE = ['quoted_reply_text', 'connection_quote_transport', 'connection_quote_tax_line', 'connection_quote_closing'];
const RH107_OPS = ['noai', 'settext', 'clear107', 'store_keys107', 'store_keys107_undo', 'burst', 'burst_undo', 'sched', 'sched_raw', 'sched_back',
                   'budget', 'budget_back', 'book_keep', 'book_back', 'ledger092', 'ledger092_undo', 'ledger071', 'ledger071_undo',
                   'log_keep', 'log_back', 'log_add', 'log_drop', 'log_mark', 'log_fill', 'mig092'];
const RH107_F092 = '092_wa_no_ai.sql';
const RH107_F071 = '071_dpo_payments.sql';
const RH107_TRIM = '--- earlier entries trimmed ---';

function rh107_dir($v): bool { return is_string($v) && $v !== '' && $v[0] === '/' && strpos($v . '/', '/../') === false && is_dir($v); }
function rh107_list($v): bool { return is_array($v) && $v !== [] && array_keys($v) === range(0, count($v) - 1); }
function rh107_object($v): bool { return is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1); }
function rh107_tag($o): bool { return rh107_dir($o['keep'] ?? null) && is_string($o['tag'] ?? null) && preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $o['tag']) === 1; }
function rh107_job($v): bool { return is_string($v) && preg_match('/^[a-z][a-z0-9_]{1,40}$/', $v) === 1; }

/** '' when the op is the rehearsal's own and well formed, else why not. Values are judged, never echoed. */
function rh107_bad(array $o, string $range): string
{
    $root = (string)($GLOBALS['root'] ?? '');
    $op = (string)($o['op'] ?? '');
    if (!in_array($op, RH107_OPS, true)) return 'an op this helper does not know';
    if (array_key_exists('phone', $o) && $op !== 'ledger092' && !(is_string($o['phone']) && preg_match('/^\+?' . $range . '[0-9]{2}$/', $o['phone']))) {
        return "a phone outside the rehearsal's own range";
    }
    foreach (['name', 'text', 'text2'] as $k) {
        if (array_key_exists($k, $o) && !(is_string($o[$k]) && strpos($o[$k], RH107_MARK) !== false)) return 'a name or text without the ' . RH107_MARK . ' mark';
    }
    $keys = function ($ks, bool $values) {
        foreach ($ks as $k => $v) {
            $name = $values ? (string)$k : (string)$v;
            if (!in_array($name, array_merge(RH107_SW, RH107_TXT), true)) return 'a key that is not one of 5.18.107\'s switches or text keys';
            if ($values) {
                if (!is_string($v) && !is_int($v)) return 'a value is a string or a number';
                if (in_array($name, RH107_PROSE, true) && is_string($v) && $v !== '' && !ctype_digit($v) && strpos($v, RH107_MARK) === false) return 'a text value without the ' . RH107_MARK . ' mark';
            }
        }
        return '';
    };
    switch ($op) {
        case 'settext': return is_string($o['text'] ?? null) ? '' : 'settext needs text';
        case 'clear107':
        case 'store_keys107_undo': return rh107_list($o['keys'] ?? null) ? $keys($o['keys'], false) : $op . ' needs keys, a list';
        case 'store_keys107': return rh107_object($o['keys'] ?? null) ? $keys($o['keys'], true) : 'store_keys107 needs keys, an object';
        case 'burst':
            foreach (['phone', 'name', 'text', 'text2', 'instance'] as $k) if (!array_key_exists($k, $o)) return 'burst needs ' . $k;
            if (!is_string($o['instance']) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $o['instance'])) return 'not an instance name';
            if (!in_array($o['mark'] ?? null, ['aside', 'claim', 'none'], true)) return 'mark is aside, claim or none';
            if (array_key_exists('fail', $o) && $o['fail'] !== 1) return 'fail is 1 or absent';
            if (array_key_exists('origin', $o) && $o['origin'] !== 'voice') return 'origin is voice or absent';
            return '';
        case 'noai':
            foreach (['phone', 'name', 'reason'] as $k) if (!array_key_exists($k, $o)) return 'noai needs ' . $k;
            if (!is_string($o['reason']) || strpos($o['reason'], RH107_MARK) === false) return 'a reason without the ' . RH107_MARK . ' mark';
            if (array_key_exists('alert', $o) && $o['alert'] !== 1) return 'alert is 1 or absent';
            return is_file($root . '/lib/NoAiList.php') ? '' : 'noai needs the code root\'s NoAiList';
        case 'burst_undo':
            if (!rh107_list($o['ids'] ?? null)) return 'burst_undo needs ids';
            foreach ($o['ids'] as $i) if (!is_int($i) || $i < 1) return 'an id is a positive number';
            return '';
        case 'sched':
            if (!rh107_tag($o)) return 'a keep folder and a tag are needed';
            if (!rh107_object($o['set'] ?? null) && !rh107_list($o['drop'] ?? null)) return 'sched takes set, drop or both';
            foreach ((array)($o['set'] ?? []) as $j => $e) {
                if (!rh107_job((string)$j) || !is_array($e)) return 'set takes job names and entries';
                if (array_key_exists('ago_s', $e) === array_key_exists('at', $e)) return 'an entry takes exactly one of ago_s and at';
                if (!is_int($e['ago_s'] ?? $e['at']) || !is_int($e['duration_ms'] ?? null)) return 'an entry is numbers: ago_s or at, and duration_ms';
                if (array_diff(array_keys($e), ['ago_s', 'at', 'duration_ms']) !== []) return 'an entry takes ago_s or at, and duration_ms';
            }
            foreach ((array)($o['drop'] ?? []) as $j) if (!rh107_job($j)) return 'drop takes job names';
            return '';
        case 'sched_raw':
            if (!rh107_tag($o)) return 'a keep folder and a tag are needed';
            return (is_string($o['raw'] ?? null) && $o['raw'] !== '' && json_decode($o['raw'], true) === null) ? '' : 'raw is text that does not decode';
        case 'budget':
            if (!rh107_tag($o)) return 'a keep folder and a tag are needed';
            return (array_key_exists('seconds', $o) && ($o['seconds'] === null || (is_int($o['seconds']) && $o['seconds'] >= 0))) ? '' : 'seconds is a number or null';
        case 'ledger092':
        case 'ledger092_undo':
            if (!rh107_tag($o)) return 'a keep folder and a tag are needed';
            if (!in_array($o['what'] ?? null, ['rename', 'checksum', 'sentinel', 'unledgered', 'partial'], true)) return 'what is rename, checksum, sentinel, unledgered or partial';
            if ($op === 'ledger092' && $o['what'] === 'sentinel' && !(is_string($o['phone'] ?? null) && preg_match('/^2560[0-9]{8}$/', $o['phone']))) {
                return 'the sentinel is a number nobody can hold: 256, then 0, then eight digits (the script\'s NA_SENTINEL_1)';
            }
            if (array_key_exists('phone', $o) && ($op !== 'ledger092' || $o['what'] !== 'sentinel')) return 'only the sentinel takes a phone';
            return '';
        case 'log_add':
            if (!rh107_tag($o)) return 'a keep folder and a tag are needed';
            return in_array($o['line'] ?? null, ['ok092', 'warning092', 'partial092', 'warning071'], true) ? '' : 'line is ok092, warning092, partial092 or warning071';
        case 'log_drop':
            if (!rh107_tag($o)) return 'a keep folder and a tag are needed';
            return in_array($o['match'] ?? null, ['ok092', 'warning092', 'partial092', 'any092'], true) ? '' : 'match is ok092, warning092, partial092 or any092';
        case 'log_mark':
            if (!rh107_tag($o)) return 'a keep folder and a tag are needed';
            return is_int($o['offset_s'] ?? null) ? '' : 'log_mark needs offset_s';
        case 'log_fill':
            if (!rh107_tag($o)) return 'a keep folder and a tag are needed';
            return (is_int($o['over'] ?? null) && $o['over'] > 0 && $o['over'] <= 1048576) ? '' : 'over is a byte count up to 1 MiB';
        case 'sched_back': case 'budget_back': case 'book_keep': case 'book_back': case 'ledger071': case 'ledger071_undo':
        case 'log_keep': case 'log_back':
            return rh107_tag($o) ? '' : 'a keep folder and a tag are needed';
        case 'mig092': return '';
    }
    return 'an op this helper does not know';
}

function rh107_euid(): int
{
    if (function_exists('posix_geteuid')) return (int)posix_geteuid();
    $t = @tempnam(sys_get_temp_dir(), 'rh107');
    if ($t === false) return -1;
    clearstatcache();
    $u = @fileowner($t);
    @unlink($t);
    return $u === false ? -1 : (int)$u;
}

function rh107_sha(string $f): string
{
    clearstatcache();
    if (is_file($f)) { $h = @hash_file('sha256', $f); return is_string($h) ? $h : 'unread'; }
    return file_exists($f) ? 'other' : 'absent';
}

function rh107_s16(string $h): string { return strlen($h) === 64 ? substr($h, 0, 16) : $h; }

/** A kept file of the caller's folder: made new (never over one that is there), mode 0600, written whole. */
function rh107_put0600(string $f, string $c): void
{
    $h = @fopen($f, 'x');
    if ($h === false) throw new RuntimeException('keep: that tag is kept already, or the keep folder cannot be written');
    @chmod($f, 0600);
    $ok = fwrite($h, $c) === strlen($c);
    fclose($h);
    if (!$ok) throw new RuntimeException('keep: a kept file was not written whole');
}

/** The kept meta under KEEP/TAG for KIND, or an exception: the plant's undo needs exactly what its plant kept. */
function rh107_meta(string $keep, string $tag, string $kind): array
{
    $raw = @file_get_contents($keep . '/' . $tag . '.meta');
    $m = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($m) || ($m['kind'] ?? '') !== $kind) throw new RuntimeException('back: nothing is kept under that tag for this kind');
    return $m;
}

function rh107_unkeep(string $keep, string $tag): void
{
    foreach (['meta', 'orig', 'db', 'db-wal', 'db-shm'] as $x) @unlink($keep . '/' . $tag . '.' . $x);
}

/** PATH replaced by CONTENT through a file beside it and a rename, its mode kept (the owner is this process's: uid 1000's). */
function rh107_replace(string $path, string $content, int $mode): void
{
    $tmp = dirname($path) . '/.rh107-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content) !== strlen($content)) { @unlink($tmp); throw new RuntimeException('write: not written whole'); }
    if (!@chmod($tmp, $mode) || !@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('write: not put in place'); }
    clearstatcache();
}

/** The store, made by the class's own constructor — create() minus its boot (no migration runs, nothing is imported). */
function rh107_store(string $data): SqliteStore
{
    $pdo = new PDO('sqlite:' . $data . '/plugin.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $r = new ReflectionClass('SqliteStore');
    $s = $r->newInstanceWithoutConstructor();
    $c = $r->getConstructor();
    $c->setAccessible(true);
    $c->invoke($s, $pdo, $data);
    return $s;
}

/** The book's logical digest: every schema object's SQL text, then every table's rows in rowid order — 16 hex. */
function rh107_bookdig(PDO $p): string
{
    $h = hash_init('sha256');
    $objs = $p->query("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_NUM);
    foreach ($objs as $o) hash_update($h, json_encode($o) . "\n");
    foreach ($objs as $o) {
        if ($o[0] !== 'table') continue;
        $st = $p->query('SELECT * FROM "' . str_replace('"', '""', (string)$o[1]) . '"');
        while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) hash_update($h, json_encode($r, JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
    }
    return substr(hash_final($h), 0, 16);
}

function rh107_has(PDO $p, string $t): bool
{
    $s = $p->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $s->execute([$t]);
    return $s->fetchColumn() !== false;
}

function rh107_ledger(PDO $p, string $f): array
{
    $s = $p->prepare('SELECT id, checksum, applied_at FROM _migrations WHERE filename = ? ORDER BY id DESC');
    $s->execute([$f]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

[$_, $root, $data, $ops] = $argv + [null, '', '', ''];
$root = rtrim((string)$root, '/');
$data = rtrim((string)$data, '/');
$no = function (string $why): void { fwrite(STDERR, 'refusing: ' . $why . "\n"); exit(2); };
if ((string)getenv('DN_VAULT_FILE') === '') $no('DN_VAULT_FILE must point into the sandbox');
$range = (string)getenv('RH107_RANGE');
if (!preg_match('/^2567001070$/', $range)) $no("RH107_RANGE must be the rehearsal's own ten digits (2567001070)");
$libs = ['StoreInterface', 'JsonStore', 'SqliteStore', 'PluginConfig', 'EventBus', 'ConversationService', 'MigrationRunner'];
foreach ($libs as $l) if (!is_file($root . '/lib/' . $l . '.php')) $no('the code root holds no lib/' . $l . '.php');
if (!rh107_dir($data) || !is_file($data . '/plugin.sqlite3')) $no('no store in the data folder');
date_default_timezone_set('UTC');
if (is_file($root . '/lib/bootstrap_data.php')) require_once $root . '/lib/bootstrap_data.php';
foreach ($libs as $l) require_once $root . '/lib/' . $l . '.php';
$list = json_decode((string)$ops, true);
if (!rh107_list($list)) $no('no ops given');
foreach ($list as $o) {
    if (!is_array($o)) $no('an op is not an object');
    $w = rh107_bad($o, $range);
    if ($w !== '') $no($w);
    if (in_array((string)$o['op'], ['burst', 'burst_undo'], true) && !is_file($root . '/lib/BurstMerge.php')) $no('a burst needs BurstMerge, and the code root has none');
}
$me = rh107_euid();
clearstatcache();
$owner = @fileowner($data . '/plugin.sqlite3');
if ($me < 0 || $owner === false || (int)$owner !== $me) $no("this helper runs as the book's owner only");
if (is_file($root . '/lib/BurstMerge.php')) require_once $root . '/lib/BurstMerge.php';
$GLOBALS['dataDir'] = $data;
$s = rh107_store($data);
$pdo = $s->getPdo();
$kf = $data . '/kyc_config.json';
$lf = $data . '/migration.log';
$h16 = function (string $x): string { return strtoupper(substr(hash('sha256', $x), 0, 16)); };
// an op whose class boots by itself (saveOverrides → mirrorToStore → SqliteStore::create) must not apply 092 by the way
$bootSafe = function () use ($root, $pdo): void {
    if (is_file($root . '/migrations/' . RH107_F092) && rh107_ledger($pdo, RH107_F092) === []) {
        throw new RuntimeException('a boot from this code root would apply 092: the ledger has none and the root carries it');
    }
};
$places = function (string $key, $want) use ($kf, $data, $s): string {
    $p = [];
    $f = @file_get_contents($kf);
    $fd = is_string($f) ? json_decode($f, true) : null;
    if (is_array($fd) && array_key_exists($key, $fd) && $fd[$key] === $want) $p[] = 'k';
    $row = $s->load('kyc_config.json');
    if (array_key_exists($key, $row) && $row[$key] === $want) $p[] = 's';
    return $p === [] ? 'none' : implode(',', $p);
};
$present = function (string $key) use ($kf, $s): string {
    $p = [];
    $f = @file_get_contents($kf);
    $fd = is_string($f) ? json_decode($f, true) : null;
    if (is_array($fd) && array_key_exists($key, $fd)) $p[] = 'k';
    if (array_key_exists($key, $s->load('kyc_config.json'))) $p[] = 's';
    return $p === [] ? 'none' : implode(',', $p);
};
$logKept = function (array $o) use ($lf): array {
    $m = rh107_meta(rtrim((string)$o['keep'], '/'), (string)$o['tag'], 'log');
    if (($m['path'] ?? '') !== $lf) throw new RuntimeException('log: kept for another log');
    $c = @file_get_contents($lf);
    if (!is_string($c)) throw new RuntimeException('log: migration.log cannot be read');
    clearstatcache();
    return [$c, ((int)@fileperms($lf)) & 0777];
};
$applied = function () use ($pdo): int {
    $r = rh107_ledger($pdo, RH107_F092);
    if ($r === []) throw new RuntimeException('the ledger has no 092: nothing to stamp from');
    $t = strtotime((string)$r[0]['applied_at'] . ' UTC');
    if ($t === false) throw new RuntimeException('092 applied_at does not read');
    return $t;
};
$out = [];
$n = 0;
try {
    foreach ($list as $o) {
        $op = (string)$o['op'];
        $keep = isset($o['keep']) ? rtrim((string)$o['keep'], '/') : '';
        $tag = (string)($o['tag'] ?? '');
        $r = ['op' => $op];
        if ($op === 'settext') {
            // a fixture through the release's own writer: v9's bytes are production's and never planted (design-v2 §A3)
            $bootSafe();
            [$ok] = PluginConfig::saveOverrides($data, ['bot_custom_instructions' => (string)$o['text']]);
            if (!$ok) throw new RuntimeException('settext: saveOverrides refused');
            $r += ['fixture' => 1, 'what' => 'the settings text through PluginConfig::saveOverrides', 'places' => $places('bot_custom_instructions', (string)$o['text']),
                   'sha' => rh107_s16(hash('sha256', (string)$o['text']))];
        } elseif ($op === 'clear107') {
            $bootSafe();
            $ch = [];
            foreach ($o['keys'] as $k) $ch[(string)$k] = '';
            [$ok] = PluginConfig::saveOverrides($data, $ch);
            if (!$ok) throw new RuntimeException('clear107: saveOverrides refused');
            $left = [];
            foreach ($o['keys'] as $k) if ($present((string)$k) !== 'none') $left[] = (string)$k;
            if ($left !== []) throw new RuntimeException('clear107: a key is still in the file or the store row');
            $r += ['fixture' => 1, 'what' => 'cleared from kyc_config.json and the store row through PluginConfig::saveOverrides (the known-limits route)',
                   'keys' => implode(',', $o['keys']), 'places' => 'none'];
        } elseif ($op === 'store_keys107' || $op === 'store_keys107_undo') {
            $h0 = rh107_sha($kf);
            $row = $s->load('kyc_config.json');
            $next = $row;
            $keys = $op === 'store_keys107' ? array_map('strval', array_keys($o['keys'])) : array_map('strval', $o['keys']);
            foreach ($keys as $k) {
                $there = array_key_exists($k, $row);
                if ($op === 'store_keys107' && $there) throw new RuntimeException('store_keys107: the store row holds that key already');
                if ($op === 'store_keys107_undo' && !$there) throw new RuntimeException('store_keys107_undo: the store row does not hold that key');
                if ($op === 'store_keys107') $next[$k] = $o['keys'][$k]; else unset($next[$k]);
            }
            $s->save('kyc_config.json', $next);
            $back = $s->load('kyc_config.json');
            foreach ($row as $k => $v) {
                if (in_array((string)$k, $keys, true)) continue;
                if (!array_key_exists($k, $back) || $back[$k] !== $v) throw new RuntimeException('store_keys107: another key of the store row moved');
            }
            foreach ($keys as $k) {
                if ($op === 'store_keys107' && (!array_key_exists($k, $back) || $back[$k] !== $o['keys'][$k])) throw new RuntimeException('store_keys107: not read back');
                if ($op === 'store_keys107_undo' && array_key_exists($k, $back)) throw new RuntimeException('store_keys107_undo: still read back');
            }
            if (rh107_sha($kf) !== $h0) throw new RuntimeException('store_keys107: kyc_config.json changed');
            $r += ['fixture' => 1, 'what' => 'a key in the store row alone', 'keys' => implode(',', $keys), 'file' => 'unchanged', 'row_keys' => count($back)];
        } elseif ($op === 'burst') {
            $phone = ltrim((string)$o['phone'], '+');
            $conv = new ConversationService($data, $pdo);
            $c = $conv->ensureConversation($phone, 'sales', (string)$o['name'], 'webhook');
            $cid = (int)($c['id'] ?? 0);
            if ($cid <= 0) throw new RuntimeException('burst: no conversation');
            $bus = new EventBus($pdo);
            $ids = [];
            foreach (['text', 'text2'] as $i => $k) {
                $pl = ['channel' => 'sales', 'whatsapp_instance' => (string)$o['instance'], 'customer_phone' => $phone, 'message' => (string)$o[$k],
                       'push_name' => (string)$o['name'], 'wa_message_id' => 'RH107IN' . $h16($phone . '|' . $k . '|' . microtime(true) . '|' . bin2hex(random_bytes(6))),
                       'remote_jid' => $phone . '@s.whatsapp.net', 'received_at' => gmdate('c'), 'location' => null];
                if (($o['origin'] ?? '') === 'voice') $pl['origin'] = 'voice';
                $ids[] = $bus->emit('ai.reply', 'conversation', $cid, $pl, 3, 'evo_webhook');
            }
            [$a, $b] = $ids;
            $bm = new BurstMerge($pdo);
            $took = 0;
            if ($o['mark'] === 'aside') $bm->markAside($a, $b);
            if ($o['mark'] === 'claim') { $took = $bm->claim([$a], $b); if ($took !== 1) throw new RuntimeException('burst: the claim took no row'); }
            if (($o['fail'] ?? 0) === 1 && $bus->fail($b, RH107_MARK . 'a model timeout')) throw new RuntimeException('burst: the failure made the event dead');
            $st = $pdo->prepare('SELECT id, status, attempts, max_attempts, error FROM events WHERE id IN (?, ?) ORDER BY id');
            $st->execute([$a, $b]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $r += ['conv' => $cid, 'a' => $a, 'b' => $b, 'took' => $took, 'a_status' => (string)$rows[0]['status'], 'b_status' => (string)$rows[1]['status'],
                   'b_attempts' => (int)$rows[1]['attempts'] . '/' . (int)$rows[1]['max_attempts'],
                   'a_marked' => strpos((string)$rows[0]['error'], BurstMerge::MARK . $b) !== false ? 1 : 0, 'origin' => ($o['origin'] ?? '') === 'voice' ? 'voice' : 'typed'];
        } elseif ($op === 'noai') {
            foreach (['timezone', 'UtcClock', 'TenantProfile', 'StaffJobsGate', 'NoAiList'] as $l) if (is_file($root . '/lib/' . $l . '.php')) require_once $root . '/lib/' . $l . '.php';
            $cfg = PluginConfig::read($root, $data);
            $L = new NoAiList($pdo, $cfg, $data);
            $a = $L->add((string)$o['phone'], (string)$o['reason'], (string)$o['name']);
            if (empty($a['ok']) || !empty($a['already'])) throw new RuntimeException('noai: not listed new');
            $ev = 0;
            if (($o['alert'] ?? 0) === 1) {
                $conv = new ConversationService($data, $pdo);
                $c = $conv->ensureConversation(ltrim((string)$o['phone'], '+'), 'sales', (string)$o['name'], 'webhook');
                $cid = (int)($c['id'] ?? 0);
                $ev = (new EventBus($pdo))->emit('wa.no_ai_alert', 'conversation', $cid, ['channel' => 'sales', 'customer_phone' => (string)$a['phone'],
                    'conversation_id' => $cid, 'no_ai_id' => (int)$a['id']], 3, 'evo_webhook');
            }
            $r += ['what' => 'a number listed through NoAiList::add' . ($ev ? ', its alert queued as evo_webhook queues it' : ''), 'id' => (int)$a['id'], 'event' => $ev];
        } elseif ($op === 'burst_undo') {
            $st = $pdo->prepare("SELECT payload FROM events WHERE id = ? AND event_type = 'ai.reply' AND created_by = 'evo_webhook'");
            foreach ($o['ids'] as $id) {
                $st->execute([$id]);
                $pl = json_decode((string)$st->fetchColumn(), true);
                if (!is_array($pl) || strpos((string)($pl['message'] ?? ''), RH107_MARK) === false || strpos((string)($pl['wa_message_id'] ?? ''), 'RH107IN') !== 0) {
                    throw new RuntimeException('burst_undo: an event this helper did not emit');
                }
            }
            $in = implode(',', array_fill(0, count($o['ids']), '?'));
            $gone = $pdo->prepare("DELETE FROM events WHERE id IN ({$in})");
            $gone->execute($o['ids']);
            if ($gone->rowCount() !== count($o['ids'])) throw new RuntimeException('burst_undo: not every event was removed');
            $r += ['fixture' => 1, 'what' => 'the planted events removed', 'removed' => $gone->rowCount()];
        } elseif ($op === 'sched' || $op === 'sched_raw') {
            $has = rh107_has($pdo, 'master_schedule');
            $raw0 = $has ? $pdo->query('SELECT data FROM master_schedule WHERE id = 0')->fetchColumn() : false;
            rh107_put0600($keep . '/' . $tag . '.meta', (string)json_encode(['kind' => 'sched', 'table' => $has, 'row' => is_string($raw0), 'sha256' => is_string($raw0) ? hash('sha256', $raw0) : 'absent']));
            if (is_string($raw0)) rh107_put0600($keep . '/' . $tag . '.orig', $raw0);
            if ($op === 'sched') {
                $d = $s->load('master_schedule.json');
                foreach ((array)($o['set'] ?? []) as $j => $e) {
                    $at = array_key_exists('at', $e) ? (int)$e['at'] : time() - (int)$e['ago_s'];
                    $d[(string)$j] = ['last_run' => $at, 'last_run_at' => date('Y-m-d H:i:s', $at), 'duration_ms' => (int)$e['duration_ms']];
                }
                foreach ((array)($o['drop'] ?? []) as $j) unset($d[$j]);
                $s->save('master_schedule.json', $d);
                $back = $s->load('master_schedule.json');
                foreach ((array)($o['set'] ?? []) as $j => $e) if (!isset($back[$j]) || (int)$back[$j]['duration_ms'] !== (int)$e['duration_ms']) throw new RuntimeException('sched: not read back');
                foreach ((array)($o['drop'] ?? []) as $j) if (isset($back[$j])) throw new RuntimeException('sched: a dropped entry is still read back');
                $r += ['what' => "master_schedule's entries through the store's save()", 'entries' => count($back), 'now' => time()];
            } else {
                if (!$has || !is_string($raw0)) throw new RuntimeException('sched_raw: no row to make unreadable');
                $u = $pdo->prepare('UPDATE master_schedule SET data = ? WHERE id = 0');
                $u->execute([(string)$o['raw']]);
                $r += ['fixture' => 1, 'what' => "master_schedule's row made text that does not decode"];
            }
        } elseif ($op === 'sched_back') {
            $m = rh107_meta($keep, $tag, 'sched');
            if (!empty($m['row'])) {
                $orig = @file_get_contents($keep . '/' . $tag . '.orig');
                if (!is_string($orig) || hash('sha256', $orig) !== (string)$m['sha256']) throw new RuntimeException('sched_back: the kept row is not what was kept');
                $u = $pdo->prepare('UPDATE master_schedule SET data = ? WHERE id = 0');
                $u->execute([$orig]);
                if ($u->rowCount() !== 1) throw new RuntimeException('sched_back: the row is gone');
            } elseif (rh107_has($pdo, 'master_schedule')) {
                $pdo->exec('DELETE FROM master_schedule WHERE id = 0');
                if (empty($m['table'])) $pdo->exec('DROP TABLE master_schedule');
            }
            $now = rh107_has($pdo, 'master_schedule') ? $pdo->query('SELECT data FROM master_schedule WHERE id = 0')->fetchColumn() : false;
            if ((is_string($now) ? hash('sha256', $now) : 'absent') !== (string)$m['sha256']) throw new RuntimeException('sched_back: not put back byte for byte');
            rh107_unkeep($keep, $tag);
            $r += ['fixture' => 1, 'what' => "master_schedule's row put back byte for byte", 'now' => rh107_s16((string)$m['sha256'])];
        } elseif ($op === 'budget' || $op === 'budget_back') {
            $h0 = rh107_sha($kf);
            $row = $s->load('kyc_config.json');
            if ($op === 'budget') {
                rh107_put0600($keep . '/' . $tag . '.meta', (string)json_encode(['kind' => 'budget', 'had' => array_key_exists('master_budget_seconds', $row), 'was' => $row['master_budget_seconds'] ?? null]));
                if ($o['seconds'] === null) unset($row['master_budget_seconds']); else $row['master_budget_seconds'] = (int)$o['seconds'];
                $want = $o['seconds'];
            } else {
                $m = rh107_meta($keep, $tag, 'budget');
                if (!empty($m['had'])) $row['master_budget_seconds'] = $m['was']; else unset($row['master_budget_seconds']);
                $want = !empty($m['had']) ? $m['was'] : null;
            }
            $s->save('kyc_config.json', $row);
            $back = $s->load('kyc_config.json');
            if (($back['master_budget_seconds'] ?? null) !== $want || array_key_exists('master_budget_seconds', $back) !== ($want !== null)) throw new RuntimeException($op . ': not read back');
            if (rh107_sha($kf) !== $h0) throw new RuntimeException($op . ': kyc_config.json changed');
            if ($op === 'budget_back') rh107_unkeep($keep, $tag);
            $r += ['what' => "master_budget_seconds in the store's kyc_config row through its save()", 'budget' => $want === null ? 'absent' : (string)$want, 'file' => 'unchanged'];
        } elseif ($op === 'book_keep' || $op === 'book_back'
                  || (($op === 'ledger092' || $op === 'ledger092_undo') && in_array($o['what'], ['rename', 'sentinel', 'unledgered', 'partial'], true))) {
            $kdb = $keep . '/' . $tag . '.db';
            if ($op === 'book_keep' || $op === 'ledger092') {
                if (file_exists($kdb) || file_exists($keep . '/' . $tag . '.meta')) throw new RuntimeException('book_keep: that tag is kept already');
                $dig = rh107_bookdig($pdo);
                $dst = new SQLite3($kdb);
                $src = new SQLite3($data . '/plugin.sqlite3', SQLITE3_OPEN_READONLY);
                $src->busyTimeout(5000);
                if (!$src->backup($dst)) throw new RuntimeException('book_keep: the backup did not complete');
                $src->close(); $dst->close();
                @chmod($kdb, 0600);
                rh107_put0600($keep . '/' . $tag . '.meta', (string)json_encode(['kind' => 'book', 'dig' => $dig]));
                $r += ['fixture' => 1, 'what' => 'the book kept (SQLite backup)', 'dig' => $dig];
            }
            if ($op === 'ledger092' && $o['what'] === 'unledgered') {
                // the pairing's ground (§T.11): 092's objects made by its own statements (the code root's file, split by the
                // runner's own splitter) with no ledger row — the self-check INSERT left out, so no _schema_checks row either
                if (rh107_ledger($pdo, RH107_F092) !== [] || rh107_has($pdo, 'wa_no_ai')) throw new RuntimeException('ledger092: 092 or its table is there already');
                $sql = @file_get_contents($root . '/migrations/' . RH107_F092);
                if (!is_string($sql) || $sql === '') throw new RuntimeException('ledger092: the code root carries no 092');
                $ran = 0;
                foreach (MigrationRunner::splitStatements($sql) as $st) {
                    if (preg_match('/^\s*INSERT\b/i', $st)) continue;
                    $pdo->exec($st); $ran++;
                }
                $objs = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('wa_no_ai','idx_wna_active','wna_never_deleted','wna_identity_fixed','wna_lift_once')")->fetchColumn();
                if (rh107_ledger($pdo, RH107_F092) !== [] || $objs !== 5) throw new RuntimeException('ledger092: not five objects with no ledger row');
                $r = ['op' => $op, 'fixture' => 1, 'what' => "092's five objects with no ledger row (the book kept first)", 'ran' => $ran, 'objects' => $objs, 'dig' => $r['dig']];
            } elseif ($op === 'ledger092') {
                if (rh107_ledger($pdo, RH107_F092) === [] || !rh107_has($pdo, 'wa_no_ai')) throw new RuntimeException('ledger092: 092 is not applied');
                if ($o['what'] === 'partial') {
                    // a 092 the runner recorded without its verdict: the ledger row stays, its _schema_checks row goes
                    $d = $pdo->exec("DELETE FROM _schema_checks WHERE migration = '092_wa_no_ai.sql'");
                    if ($d !== 1) throw new RuntimeException('ledger092: no _schema_checks row for 092 to take out');
                    $r = ['op' => $op, 'fixture' => 1, 'what' => "092 recorded with no _schema_checks row (the book kept first)", 'removed' => $d, 'dig' => $r['dig']];
                } elseif ($o['what'] === 'rename') {
                    $pdo->exec('ALTER TABLE wa_no_ai RENAME TO rh107_wa_no_ai');
                    $r = ['op' => $op, 'fixture' => 1, 'what' => "092's table renamed (the book kept first)", 'table' => rh107_has($pdo, 'wa_no_ai') ? 1 : 0, 'dig' => $r['dig']];
                } elseif ($o['what'] === 'sentinel') {
                    $i = $pdo->prepare('INSERT INTO wa_no_ai (phone, reason, added_by, active) VALUES (?, ?, ?, 1)');
                    $i->execute([(string)$o['phone'], RH107_MARK . 'sentinel', RH107_MARK . 'seed']);
                    $r = ['op' => $op, 'fixture' => 1, 'what' => "NA-CA1's first sentinel inserted active (the book kept first)", 'id' => (int)$pdo->lastInsertId(), 'dig' => $r['dig']];
                }
            }
            if ($op === 'book_back' || $op === 'ledger092_undo') {
                $m = rh107_meta($keep, $tag, 'book');
                if (!is_file($kdb)) throw new RuntimeException('book_back: no kept book under that tag');
                $pdo = null; $s = null;   // the helper's own connection closed before the book is replaced
                $src = new SQLite3($kdb, SQLITE3_OPEN_READONLY);
                $dst = new SQLite3($data . '/plugin.sqlite3');
                $dst->busyTimeout(5000);
                if (!$src->backup($dst)) throw new RuntimeException('book_back: the backup did not complete');
                $src->close(); $dst->close();
                $s = rh107_store($data);
                $pdo = $s->getPdo();
                $dig = rh107_bookdig($pdo);
                if ($dig !== (string)$m['dig']) throw new RuntimeException('book_back: the book is not what was kept');
                rh107_unkeep($keep, $tag);
                $r += ['fixture' => 1, 'what' => 'the book put back (SQLite backup), its digest as kept', 'dig' => $dig];
            }
        } elseif ($op === 'ledger092' || $op === 'ledger092_undo' || $op === 'ledger071' || $op === 'ledger071_undo') {
            $f = ($op === 'ledger071' || $op === 'ledger071_undo') ? RH107_F071 : RH107_F092;
            $rows = rh107_ledger($pdo, $f);
            if (count($rows) !== 1) throw new RuntimeException($op . ': the ledger does not hold that file exactly once');
            if ($op === 'ledger092' || $op === 'ledger071') {
                rh107_put0600($keep . '/' . $tag . '.meta', (string)json_encode(['kind' => 'cks', 'file' => $f, 'id' => (int)$rows[0]['id'], 'was' => (string)$rows[0]['checksum']]));
                $other = md5('rh107-other|' . $f);
                if ($other === (string)$rows[0]['checksum']) throw new RuntimeException($op . ': the other md5 is the recorded one');
                $u = $pdo->prepare('UPDATE _migrations SET checksum = ? WHERE id = ?');
                $u->execute([$other, (int)$rows[0]['id']]);
                $r += ['fixture' => 1, 'what' => $f . "'s ledger checksum rewritten to another md5", 'was' => substr((string)$rows[0]['checksum'], 0, 16), 'now' => substr($other, 0, 16)];
            } else {
                $m = rh107_meta($keep, $tag, 'cks');
                if (($m['file'] ?? '') !== $f || (int)$m['id'] !== (int)$rows[0]['id']) throw new RuntimeException($op . ': kept for another row');
                $u = $pdo->prepare('UPDATE _migrations SET checksum = ? WHERE id = ?');
                $u->execute([(string)$m['was'], (int)$m['id']]);
                if ((string)rh107_ledger($pdo, $f)[0]['checksum'] !== (string)$m['was']) throw new RuntimeException($op . ': not put back');
                rh107_unkeep($keep, $tag);
                $r += ['fixture' => 1, 'what' => $f . "'s ledger checksum put back", 'now' => substr((string)$m['was'], 0, 16)];
            }
        } elseif ($op === 'log_keep') {
            clearstatcache();
            if (!is_file($lf)) throw new RuntimeException('log_keep: no migration.log');
            $c = @file_get_contents($lf);
            if (!is_string($c)) throw new RuntimeException('log_keep: migration.log cannot be read');
            rh107_put0600($keep . '/' . $tag . '.orig', $c);
            rh107_put0600($keep . '/' . $tag . '.meta', (string)json_encode(['kind' => 'log', 'path' => $lf, 'sha256' => hash('sha256', $c), 'mode' => ((int)@fileperms($lf)) & 0777]));
            $r += ['fixture' => 1, 'what' => 'migration.log kept', 'sha' => rh107_s16(hash('sha256', $c)), 'size' => strlen($c)];
        } elseif ($op === 'log_back') {
            $m = rh107_meta($keep, $tag, 'log');
            $orig = @file_get_contents($keep . '/' . $tag . '.orig');
            if (!is_string($orig) || hash('sha256', $orig) !== (string)$m['sha256'] || ($m['path'] ?? '') !== $lf) throw new RuntimeException('log_back: the kept log is not what was kept');
            rh107_replace($lf, $orig, (int)$m['mode']);
            if (rh107_sha($lf) !== (string)$m['sha256'] || (((int)@fileperms($lf)) & 0777) !== (int)$m['mode']) throw new RuntimeException('log_back: not put back byte for byte with its mode');
            rh107_unkeep($keep, $tag);
            $r += ['fixture' => 1, 'what' => 'migration.log put back byte for byte', 'sha' => rh107_s16((string)$m['sha256'])];
        } elseif (in_array($op, ['log_add', 'log_drop', 'log_mark', 'log_fill'], true)) {
            [$c, $mode] = $logKept($o);
            $lines = preg_split("/(?<=\n)/", $c, -1, PREG_SPLIT_NO_EMPTY);
            $st = '[' . gmdate('Y-m-d H:i:s') . '] ';
            $is092 = function (string $l): bool { return strpos($l, RH107_F092) !== false; };
            $kind = [
                'ok092' => function (string $l): bool { return strpos($l, 'OK: ' . RH107_F092 . ' (') !== false; },
                'partial092' => function (string $l): bool { return strpos($l, 'PARTIAL: ' . RH107_F092) !== false; },
                'warning092' => function (string $l): bool { return strpos($l, 'WARNING: ' . RH107_F092 . ' has been modified after being applied (checksum mismatch)') !== false; },
                'any092' => $is092,
            ];
            if ($op === 'log_add') {
                $add = ['ok092' => 'OK: ' . RH107_F092 . ' (6 stmts, 1ms)',
                        'partial092' => 'PARTIAL: ' . RH107_F092 . ' — 1/6 statements failed: CHECK constraint failed: found = expected',
                        'warning092' => 'WARNING: ' . RH107_F092 . ' has been modified after being applied (checksum mismatch). Skipping.',
                        'warning071' => 'WARNING: ' . RH107_F071 . ' has been modified after being applied (checksum mismatch). Skipping.'][(string)$o['line']];
                $new = $c . ($c !== '' && substr($c, -1) !== "\n" ? "\n" : '') . $st . $add . "\n";
                $r += ['fixture' => 1, 'what' => 'one line appended to migration.log in the runner\'s format (UTC)', 'line' => (string)$o['line']];
            } elseif ($op === 'log_drop') {
                $f = $kind[(string)$o['match']];
                $keepL = []; $gone = 0;
                foreach ($lines as $l) { if ($f($l)) $gone++; else $keepL[] = $l; }
                if ($gone === 0) throw new RuntimeException('log_drop: no such line to take out');
                $new = implode('', $keepL);
                $r += ['fixture' => 1, 'what' => 'lines taken out of migration.log', 'match' => (string)$o['match'], 'removed' => $gone];
            } elseif ($op === 'log_mark') {
                $at = $applied() + (int)$o['offset_s'];
                $keepL = []; $gone = 0;
                foreach ($lines as $l) { if ($is092($l)) $gone++; else $keepL[] = $l; }
                $new = '[' . gmdate('Y-m-d H:i:s', $at) . '] ' . RH107_TRIM . "\n" . implode('', $keepL);
                $r += ['fixture' => 1, 'what' => "the runner's halving imitated: 092's lines out, its trim marker as line 1, stamped (UTC) at 092's applied_at plus offset_s",
                       'removed' => $gone, 'mark_at' => $at, 'applied_at' => $applied()];
            } else {
                $want = (int)$o['over'];
                $new = $c . ($c !== '' && substr($c, -1) !== "\n" ? "\n" : '');
                $i = 0;
                while (strlen($new) <= $want) { $i++; $new .= $st . 'RH107 fill ' . str_pad((string)$i, 6, '0', STR_PAD_LEFT) . ' — a line of the rehearsal\'s, naming no migration' . str_repeat('.', 60) . "\n"; }
                $okoff = strpos($new, 'OK: ' . RH107_F092 . ' (');
                $r += ['fixture' => 1, 'what' => 'fictitious lines appended until migration.log is over the given size', 'lines' => $i, 'size' => strlen($new),
                       'okoff' => $okoff === false ? -1 : (int)$okoff];
            }
            rh107_replace($lf, $new, $mode);
            if (rh107_sha($lf) !== hash('sha256', $new)) throw new RuntimeException($op . ': not written whole');
            $r['sha'] = rh107_s16(hash('sha256', $new));
        } elseif ($op === 'mig092') {
            if (!is_file($root . '/migrations/' . RH107_F092)) throw new RuntimeException('mig092: the code root carries no 092');
            if (rh107_ledger($pdo, RH107_F092) !== []) throw new RuntimeException('mig092: the ledger has 092 already');
            $n0 = (int)$pdo->query('SELECT COUNT(*) FROM _migrations')->fetchColumn();
            $c0 = is_file($lf) ? (string)@file_get_contents($lf) : '';
            $k0 = substr_count($c0, 'OK: ' . RH107_F092 . ' (');
            $runner = new MigrationRunner($pdo, $root . '/migrations', $lf);
            $runner->run();
            $n1 = (int)$pdo->query('SELECT COUNT(*) FROM _migrations')->fetchColumn();
            $rows = rh107_ledger($pdo, RH107_F092);
            $c1 = is_file($lf) ? (string)@file_get_contents($lf) : '';
            $k1 = substr_count($c1, 'OK: ' . RH107_F092 . ' (');
            if ($n1 !== $n0 + 1 || count($rows) !== 1) throw new RuntimeException('mig092: not exactly one new ledger row, 092\'s');
            if ($k1 !== $k0 + 1) throw new RuntimeException('mig092: not exactly one new OK line for 092');
            $at = strtotime((string)$rows[0]['applied_at'] . ' UTC');
            $r += ['what' => "092 applied by the code root's MigrationRunner, its log the data folder's migration.log", 'rows' => $n1 - $n0, 'ok_lines' => $k1 - $k0,
                   'applied_at' => $at === false ? -1 : $at, 'cks' => substr((string)$rows[0]['checksum'], 0, 16)];
        }
        $out[] = $r;
        $n++;
    }
} catch (Throwable $e) {
    if ($pdo !== null && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'refused after ' . $n . ': ' . get_class($e) . ' ' . preg_replace('/[^a-z0-9_ :.()-]/', '', strtolower($e->getMessage())) . "\n");
    exit(3);
}
echo json_encode(end($out) + ['ops' => $n, 'at' => time(), 'uid' => $me, 'done' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

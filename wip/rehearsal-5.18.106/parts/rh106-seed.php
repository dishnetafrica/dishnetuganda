<?php
// 5.18.106's rehearsal: its plants — the jam root docs/75 §L.10 asks for (design-v2 §A3, §A5: J1–J3) and the fixtures
// design-v2 §A9 names, each with its undo — written through the PIN's own classes (bf29cbd: FollowUpService,
// ConversationService, SqliteStore, PluginConfig, from a code root holding the pin's lib/ from Git, which this helper
// checks is 5.18.106's), never raw SQL: a follow-up as the scan opens it and a person drafts and approves it
// (FollowUpService::open, ::draft, ::approve), a customer's message and a delivery as the processor and the sender store
// them (ConversationService::ensureConversation, ::storeMessage). Its reads go through the same classes (::getConversation,
// ::openFor, ::allOpen, the store's load()). The fixtures are the exceptions §A9 names — states no production writer
// makes — each said so in its output ("fixture": true) and each with its undo, which puts back what it found: the store
// row through the store's own load() and save(); a settings file or a state file byte for byte from the copy kept in the
// caller's own folder, with its mode and owner, its sha256 checked. Synthetic and fictitious: every name and text carries
// RH106SECRET-, every phone is the rehearsal's own range (RH106_RANGE: ten digits in the fictitious +2567001xxx block,
// two free digits after them) — any other value is refused before anything is opened.
//   php rh106-seed.php <code root> <data dir> <ops as JSON: [{"op":…}, …]> [time zone]
// Environment: DN_VAULT_FILE (into the sandbox, never the live vault) and RH106_RANGE. Run as the book's owner — a book op
// refuses any other user, since a write-ahead file left behind as root would lock the plugin out of its own book — and
// under the rehearsal's clock (LD_PRELOAD and RH104_CLOCK_FILE, as R4's seed104c runs rh104-seed.php): every stamp below
// is the clock's (time(), gmdate(), SQLite's datetime('now')). The file fixtures run as root or as the file's owner, and
// put back the owner and mode they found (a state file is root's: its two ops run as root).
// The ops that write the book (its plugin.sqlite3 must be there):
//   {"op":"enquiry","phone":…,"channel":…,"name":…,"text":…,"lca":"Y-m-d H:i:s"|"lca_ago_s":n,"upto":"open"|"draft"|"approve",
//    "body":…,"by":…[,"drafts":1|2][,"body2":…]}
//        — the customer's text stored inbound at lca (UTC) — the conversation's last_customer_at then exactly lca, or the
//          op is refused (the customer wrote later) — and the follow-up opened on it, refused unless newly opened; with
//          "draft" a SEND draft, with "approve" each draft approved by "by"; two drafts are both of attempt 1 (J3's pair)
//   {"op":"pair",…}      — enquiry with two drafts, both approved (J3), "upto" and "drafts" fixed
//   {"op":"inbound","phone":…,"channel":…,"name":…,"text":…,"sent_at":"Y-m-d H:i:s"|"ago_s":n}
//        — one customer message with that sent_at (an old one raises last_customer_at no further than it)
//   {"op":"delivery","phone":…,"channel":…,"name":…,"text":…[,"agent":…][,"wa_id":"RH106…"]}
//        — one outbound message with WhatsApp's id on that channel, stored now (sent_at and created_at the clock's), as the
//          sender stores what it delivered: refusedWhileDelivering's delivery (design-v2 §A5)
//   {"op":"gone","from":n,"count":n,"phone":…,"channel":…,"lca":…|"lca_ago_s":n}
//        — count follow-ups on conversation ids from … from+count-1 (one number for all), each id asserted to hold no
//          conversation and no open follow-up first, opened in one transaction (one 'created' event each), each asserted
//          again after; lca asserted older than every open enquiry follow-up's in the book before (design-v2 §A3: the
//          state a prune leaves — wa_prune_history.php is not run)
// The fixtures (design-v2 §A9), each {"fixture":true}:
//   {"op":"fx_store_keys","keys":{"quote_chase_enabled":"1",…}}  /  {"op":"fx_store_keys_undo","keys":["quote_chase_enabled",…]}
//        — a store-only claim key: the three claim switches (offers_live_enabled, offers_test_enabled,
//          quote_chase_enabled) put into the store row alone, and taken out, through the store's own load() and save();
//          refused where the row holds one already; <data>/kyc_config.json asserted byte for byte unchanged, every other
//          key of the row asserted as it was
//   {"op":"fx_type3","key":"followup_…"}  /  {"op":"fx_type3_undo","key":…}
//        — '3' against 3: a key the file holds as a digit string (as set_config.php writes it) and the store row holds the
//          same, the row's copy made that number; and back to the file's string
//   {"op":"fx_file","place":"k"|"p"|"d","keep":<dir>,"tag":…,"set":{key: value,…}|"raw":…[,"plugin":<dir>]}
//   {"op":"fx_file_back","place":…,"keep":…,"tag":…[,"plugin":…]}
//        — a key put into <data>/kyc_config.json (k), <plugin>/data/config.json (p, "plugin" the installed plugin folder) or
//          <data>/config.json (d) — a claim key LK-G1 holds, or followup_auto_send — refused where the file holds the key
//          already; or the file replaced by marked text that does not decode ("raw"); the original kept as <tag>.orig and
//          <tag>.meta (0600) in <keep>, and the back op restoring it byte for byte, mode and owner, or removing a file that
//          was not there
//   {"op":"fx_state_drop","path":<state-….env>,"key":"UJ_A"|"COPY_AT","keep":…,"tag":…[,"expect":n]}
//   {"op":"fx_state_back","path":…,"keep":…,"tag":…}
//        — every line of the state file beginning KEY= taken out (exactly "expect" of them, 1 by default), the rest byte for
//          byte; and the file put back as kept
// Prints one line of JSON — per op its kind, the new row ids, counts and the first 16 hex of sha256s, and the clock's time
// it ran at — never a name, a phone, a text, a body, a value or a path. Exit 2: refused before anything was opened; 3:
// refused after N ops (the gone op's transaction rolled back whole).
declare(strict_types=1);
ini_set('display_errors', 'stderr');

const RH106_MARK = 'RH106SECRET-';
const RH106_CLAIM = ['offers_live_enabled', 'offers_test_enabled', 'quote_chase_enabled'];
const RH106_BOOK = ['enquiry', 'pair', 'inbound', 'delivery', 'gone', 'fx_store_keys', 'fx_store_keys_undo', 'fx_type3', 'fx_type3_undo'];
const RH106_FILES = ['fx_file', 'fx_file_back', 'fx_state_drop', 'fx_state_back'];

function rh106_stamp($v): bool
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return false;
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $v, new DateTimeZone('UTC'));
    return $d !== false && $d->format('Y-m-d H:i:s') === $v;
}

/** Exactly one of the two: a UTC stamp, or a number of seconds before the clock's now. */
function rh106_when(array $o, string $at, string $ago): bool
{
    $a = array_key_exists($at, $o);
    if ($a === array_key_exists($ago, $o)) return false;
    return $a ? rh106_stamp($o[$at]) : (is_int($o[$ago]) && $o[$ago] >= 0);
}

function rh106_at(array $o, string $at, string $ago): string
{
    return array_key_exists($at, $o) ? (string)$o[$at] : gmdate('Y-m-d H:i:s', time() - (int)$o[$ago]);
}

function rh106_dir($v): bool
{
    return is_string($v) && $v !== '' && $v[0] === '/' && strpos($v . '/', '/../') === false && is_dir($v);
}

function rh106_list($v): bool
{
    return is_array($v) && $v !== [] && array_keys($v) === range(0, count($v) - 1);
}

function rh106_object($v): bool
{
    return is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1);
}

/** '' when the op is the rehearsal's own and well formed, else why not. Values are judged, never echoed. */
function rh106_bad(array $o, string $range): string
{
    $op = (string)($o['op'] ?? '');
    if (!in_array($op, array_merge(RH106_BOOK, RH106_FILES), true)) return 'an op this helper does not know';
    if (array_key_exists('phone', $o) && !(is_string($o['phone']) && preg_match('/^\+?' . $range . '[0-9]{2}$/', $o['phone']))) {
        return "a phone outside the rehearsal's own range";
    }
    foreach (['name', 'text', 'body', 'body2', 'by', 'agent', 'raw'] as $k) {
        if (array_key_exists($k, $o) && !(is_string($o[$k]) && strpos($o[$k], RH106_MARK) !== false)) return 'a name or text without the ' . RH106_MARK . ' mark';
    }
    if (array_key_exists('channel', $o) && !(is_string($o['channel']) && preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $o['channel']))) return 'not a channel id';
    $need = function (array $ks) use ($o, $op): string {
        foreach ($ks as $k) if (!array_key_exists($k, $o)) return $op . ' needs ' . $k;
        return '';
    };
    switch ($op) {
        case 'enquiry':
        case 'pair':
            if (($w = $need(['phone', 'name', 'text'])) !== '') return $w;
            if (!rh106_when($o, 'lca', 'lca_ago_s')) return $op . ' needs exactly one of lca (UTC) and lca_ago_s';
            $upto = $op === 'pair' ? 'approve' : ($o['upto'] ?? 'approve');
            $n = $op === 'pair' ? 2 : ($o['drafts'] ?? 1);
            if (!in_array($upto, ['open', 'draft', 'approve'], true)) return 'upto is open, draft or approve';
            if ($n !== 1 && $n !== 2) return 'drafts is 1 or 2';
            if ($n === 2 && $upto !== 'approve') return 'two drafts need approve: a follow-up holds one pending draft at a time';
            if ($upto !== 'open' && ($w = $need(['body'])) !== '') return $w;
            if ($upto === 'approve' && ($w = $need(['by'])) !== '') return $w;
            return '';
        case 'inbound':
            if (($w = $need(['phone', 'name', 'text'])) !== '') return $w;
            return rh106_when($o, 'sent_at', 'ago_s') ? '' : 'inbound needs exactly one of sent_at (UTC) and ago_s';
        case 'delivery':
            if (($w = $need(['phone', 'name', 'text'])) !== '') return $w;
            if (array_key_exists('wa_id', $o) && !(is_string($o['wa_id']) && preg_match('/^RH106[A-Z0-9]{4,40}$/', $o['wa_id']))) return "a WhatsApp id that is not the rehearsal's own";
            return '';
        case 'gone':
            if (($w = $need(['phone', 'from', 'count'])) !== '') return $w;
            if (!is_int($o['from']) || $o['from'] < 9000000 || !is_int($o['count']) || $o['count'] < 1 || $o['count'] > 1000 || $o['from'] + $o['count'] - 1 > 9999999) {
                return 'gone takes ids in 9000000..9999999 that no conversation can reach, at most 1000 of them';
            }
            return rh106_when($o, 'lca', 'lca_ago_s') ? '' : 'gone needs exactly one of lca (UTC) and lca_ago_s';
        case 'fx_store_keys':
            if (!rh106_object($o['keys'] ?? null)) return 'fx_store_keys needs keys, an object';
            foreach ($o['keys'] as $k => $v) {
                if (!in_array((string)$k, RH106_CLAIM, true)) return 'fx_store_keys plants the three claim switches only';
                if (!is_string($v) && !is_int($v) && !is_bool($v)) return 'a claim switch value is a string, a number or a boolean';
            }
            return '';
        case 'fx_store_keys_undo':
            if (!rh106_list($o['keys'] ?? null)) return 'fx_store_keys_undo needs keys, a list';
            foreach ($o['keys'] as $k) if (!in_array($k, RH106_CLAIM, true)) return 'fx_store_keys_undo takes the three claim switches only';
            return '';
        case 'fx_type3':
        case 'fx_type3_undo':
            $k = $o['key'] ?? null;
            if (!is_string($k) || !preg_match('/^followup_[a-z_]{1,40}$/', $k) || in_array($k, ['followup_auto_send', 'followup_enabled'], true)) {
                return 'fx_type3 takes a followup_ number key, never followup_auto_send or followup_enabled';
            }
            return '';
        case 'fx_file':
        case 'fx_file_back':
            if (!in_array($o['place'] ?? null, ['k', 'p', 'd'], true)) return 'place is k, p or d';
            if (!rh106_dir($o['keep'] ?? null) || !is_string($o['tag'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $o['tag'])) return 'a keep folder and a tag are needed';
            if ($o['place'] === 'p' && !(rh106_dir($o['plugin'] ?? null) && is_file($o['plugin'] . '/manifest.json') && is_dir($o['plugin'] . '/data'))) return 'place p needs the installed plugin folder';
            if ($op === 'fx_file_back') return '';
            if (array_key_exists('set', $o) === array_key_exists('raw', $o)) return 'fx_file takes exactly one of set and raw';
            if (array_key_exists('raw', $o)) return is_array(json_decode((string)$o['raw'], true)) ? 'raw decodes: it would not be a file that does not decode' : '';
            if (!rh106_object($o['set'])) return 'set is an object of keys';
            foreach ($o['set'] as $k => $v) {
                if (!preg_match('/^[a-z0-9_]{1,80}$/', (string)$k) || (!is_string($v) && !is_int($v) && !is_bool($v))) return 'set takes plain keys and scalar values';
                if (in_array((string)$k, PluginConfig::SECRET_KEYS, true) || (string)$k === 'admin_token') return 'never a secret key';
            }
            return '';
        case 'fx_state_drop':
        case 'fx_state_back':
            $p = $o['path'] ?? null;
            if (!is_string($p) || $p === '' || $p[0] !== '/' || !preg_match('/^state-[0-9][0-9.]*\.env$/', basename($p)) || !is_file($p)) return "the path is a deploy run's state file";
            if (!rh106_dir($o['keep'] ?? null) || !is_string($o['tag'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $o['tag'])) return 'a keep folder and a tag are needed';
            if ($op === 'fx_state_back') return '';
            if (!in_array($o['key'] ?? null, ['UJ_A', 'COPY_AT'], true)) return 'key is UJ_A or COPY_AT';
            if (array_key_exists('expect', $o) && !(is_int($o['expect']) && $o['expect'] >= 1)) return 'expect is a count of lines';
            return '';
    }
    return 'an op this helper does not know';
}

/** The effective uid: posix's, else the owner of a file this process has just made (a temporary file, removed). */
function rh106_euid(): int
{
    if (function_exists('posix_geteuid')) return (int)posix_geteuid();
    $t = @tempnam(sys_get_temp_dir(), 'rh106');
    if ($t === false) return -1;
    clearstatcache();
    $u = @fileowner($t);
    @unlink($t);
    return $u === false ? -1 : (int)$u;
}

function rh106_sha(string $f): string
{
    clearstatcache();
    if (is_file($f)) { $h = @hash_file('sha256', $f); return is_string($h) ? $h : 'unread'; }
    return file_exists($f) ? 'other' : 'absent';
}

function rh106_s16(string $h): string { return strlen($h) === 64 ? substr($h, 0, 16) : $h; }

function rh106_stat(string $f): array
{
    clearstatcache();
    $s = @stat($f);
    return is_array($s) ? ['mode' => ((int)$s['mode']) & 0777, 'uid' => (int)$s['uid'], 'gid' => (int)$s['gid']] : ['mode' => -1, 'uid' => -1, 'gid' => -1];
}

/** A kept file of the caller's folder: made new (never over one that is there), mode 0600, written whole. */
function rh106_put0600(string $f, string $c): void
{
    $h = @fopen($f, 'x');
    if ($h === false) throw new RuntimeException('keep: that tag is kept already, or the keep folder cannot be written');
    @chmod($f, 0600);
    $ok = fwrite($h, $c) === strlen($c);
    fclose($h);
    if (!$ok) throw new RuntimeException('keep: a kept file was not written whole');
}

/** PATH replaced by CONTENT through a file beside it and a rename, with MODE and — as root — UID and GID. */
function rh106_replace(string $path, string $content, int $mode, int $uid, int $gid, int $me): void
{
    $tmp = dirname($path) . '/.rh106-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) { @unlink($tmp); throw new RuntimeException('write: not written whole'); }
    $ok = @chmod($tmp, $mode);
    if ($ok && $me === 0) $ok = @chown($tmp, $uid) && @chgrp($tmp, $gid);
    if (!$ok || !@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('write: not put in place'); }
    clearstatcache();
}

/** The fixture's first half: PATH kept in KEEP as TAG.orig (when there) and TAG.meta — what it was, its mode and owner. */
function rh106_keep(string $kind, string $where, string $path, string $keep, string $tag, int $me): array
{
    $kf = $keep . '/' . $tag . '.orig';
    $mf = $keep . '/' . $tag . '.meta';
    if (file_exists($kf) || file_exists($mf)) throw new RuntimeException('keep: that tag is kept already');
    clearstatcache();
    $existed = is_file($path);
    if (!$existed && file_exists($path)) throw new RuntimeException('keep: not a plain file');
    $orig = '';
    if ($existed) {
        $c = @file_get_contents($path);
        if (!is_string($c)) throw new RuntimeException('keep: the file cannot be read');
        $orig = $c;
    }
    $st = rh106_stat($existed ? $path : dirname($path));
    if ($st['uid'] < 0) throw new RuntimeException('keep: no stat');
    $m = ['kind' => $kind, 'where' => $where, 'path' => $path, 'existed' => $existed, 'sha256' => $existed ? hash('sha256', $orig) : 'absent',
          'mode' => $existed ? $st['mode'] : 0600, 'uid' => $st['uid'], 'gid' => $st['gid']];
    if ($me !== 0 && $existed && $m['uid'] !== $me) throw new RuntimeException('keep: run as root or as the file owner');
    if ($existed) rh106_put0600($kf, $orig);
    rh106_put0600($mf, (string)json_encode($m, JSON_UNESCAPED_SLASHES));
    $m['orig'] = $orig;
    return $m;
}

/** The fixture's undo: PATH put back as kept — byte for byte, mode and owner, or removed when it was not there. */
function rh106_back(string $kind, string $where, string $path, string $keep, string $tag, int $me): array
{
    $kf = $keep . '/' . $tag . '.orig';
    $mf = $keep . '/' . $tag . '.meta';
    $raw = @file_get_contents($mf);
    $m = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($m) || ($m['kind'] ?? '') !== $kind || ($m['where'] ?? '') !== $where || ($m['path'] ?? '') !== $path) {
        throw new RuntimeException('back: nothing is kept under that tag for this place');
    }
    if (!empty($m['existed'])) {
        $orig = @file_get_contents($kf);
        if (!is_string($orig) || hash('sha256', $orig) !== (string)$m['sha256']) throw new RuntimeException('back: the kept copy is not what was kept');
        if ($me !== 0 && (int)$m['uid'] !== $me) throw new RuntimeException('back: run as root to put back a file another user owned');
        rh106_replace($path, $orig, (int)$m['mode'], (int)$m['uid'], (int)$m['gid'], $me);
        $st = rh106_stat($path);
        if (rh106_sha($path) !== (string)$m['sha256'] || $st['mode'] !== (int)$m['mode'] || $st['uid'] !== (int)$m['uid'] || $st['gid'] !== (int)$m['gid']) {
            throw new RuntimeException('back: not put back byte for byte with its mode and owner');
        }
    } else {
        if (file_exists($path) && !@unlink($path)) throw new RuntimeException('back: the planted file could not be removed');
        clearstatcache();
        if (file_exists($path)) throw new RuntimeException('back: the planted file is still there');
    }
    @unlink($kf);
    @unlink($mf);
    return ['existed' => !empty($m['existed']), 'sha' => rh106_s16(rh106_sha($path))];
}

[$_, $root, $data, $ops, $zone] = $argv + [null, '', '', '', 'UTC'];
$root = rtrim((string)$root, '/');
$data = rtrim((string)$data, '/');
$no = function (string $why): void { fwrite(STDERR, 'refusing: ' . $why . "\n"); exit(2); };
if ((string)getenv('DN_VAULT_FILE') === '') $no('DN_VAULT_FILE must point into the sandbox');
$range = (string)getenv('RH106_RANGE');
if (!preg_match('/^2567001[0-9]{3}$/', $range)) $no("RH106_RANGE must be the rehearsal's own ten digits (2567001…)");
$libs = ['StoreInterface', 'JsonStore', 'SqliteStore', 'ContactOptOut', 'FollowUpPolicy', 'FollowUpService', 'ConversationService', 'PluginConfig'];
foreach ($libs as $l) if (!is_file($root . '/lib/' . $l . '.php')) $no('the code root holds no lib/' . $l . '.php');
if (!@date_default_timezone_set((string)$zone)) $no('an unknown time zone');
if (is_file($root . '/lib/bootstrap_data.php')) require_once $root . '/lib/bootstrap_data.php';
foreach ($libs as $l) require_once $root . '/lib/' . $l . '.php';
// the pin's own classes, never an earlier release's: 5.18.106's FollowUpService carries F1's and F5's methods
if (!method_exists('FollowUpService', 'refusedWhileDelivering') || !method_exists('FollowUpService', 'expireOtherApproved')) {
    $no("the code root's FollowUpService is not 5.18.106's");
}
$list = json_decode((string)$ops, true);
if (!rh106_list($list)) $no('no ops given');
$book = false;
foreach ($list as $o) {
    if (!is_array($o)) $no('an op is not an object');
    $w = rh106_bad($o, $range);
    if ($w !== '') $no($w);
    if (in_array((string)$o['op'], RH106_BOOK, true)) $book = true;
}
$me = rh106_euid();
$s = null; $pdo = null; $conv = null; $fu = null;
if ($book) {
    if (!is_file($data . '/plugin.sqlite3')) $no('no store in the data folder');
    clearstatcache();
    $owner = @fileowner($data . '/plugin.sqlite3');
    if ($me < 0 || $owner === false || (int)$owner !== $me) $no("a book op runs as the book's owner only");
    $GLOBALS['dataDir'] = $data;   // as the plugin's own bootstrap sets it
    $s = SqliteStore::create($data);
    $pdo = $s->getPdo();
    $conv = new ConversationService($data, $pdo);
    $fu = new FollowUpService($pdo);
}
$h16 = function (string $x): string { return strtoupper(substr(hash('sha256', $x), 0, 16)); };
$digits = function (string $p): string { return (string)preg_replace('/\D/', '', $p); };
$kf = $data . '/kyc_config.json';
// the customer's message, then the follow-up opened, drafted and approved (rh104-seed.php's enquiry op, its lca chosen)
$plant = function (array $o, int $drafts, string $upto) use ($conv, $fu, $h16, $digits): array {
    $ch = (string)($o['channel'] ?? 'sales');
    $lca = rh106_at($o, 'lca', 'lca_ago_s');
    $c = $conv->ensureConversation((string)$o['phone'], $ch, (string)$o['name'], 'webhook');
    $cid = (int)($c['id'] ?? 0);
    if ($cid <= 0) throw new RuntimeException('enquiry: no conversation');
    if ($fu->openFor($cid) !== null) throw new RuntimeException('enquiry: the conversation holds an open follow-up already');
    $mid = $conv->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => (string)$o['text'],
        'wa_message_id' => 'RH106IN' . $h16($digits((string)$o['phone']) . '|' . $ch . '|' . $lca), 'sent_at' => $lca]);
    $cv = $conv->getConversation($cid);
    if ($cv === null || (string)($cv['last_customer_at'] ?? '') !== $lca) throw new RuntimeException('enquiry: the customer last wrote later than the chosen time');
    $f = $fu->open($cv, ['topic' => RH106_MARK . 'topic', 'opened_by' => 'scan']);
    if (empty($f['ok']) || empty($f['created'])) throw new RuntimeException('enquiry: not opened new');
    $fid = (int)$f['id'];
    $row = $fu->get($fid);
    if ($row === null || (string)$row['last_customer_at'] !== $lca) throw new RuntimeException('enquiry: opened with another last_customer_at');
    $ds = [];
    $ap = 0;
    if ($upto !== 'open') {
        for ($i = 0; $i < $drafts; $i++) {
            $body = $i === 0 ? (string)$o['body'] : (string)($o['body2'] ?? $o['body']);
            $d = $fu->draft($fid, ['verdict' => 'SEND', 'message' => $body, 'reason' => RH106_MARK . 'reason', 'model' => 'rh106']);
            if (empty($d['ok'])) throw new RuntimeException('enquiry: not drafted');
            $ds[] = (int)$d['id'];
            if ($upto === 'approve') {
                $a = $fu->approve((int)$d['id'], (string)$o['by']);
                if (empty($a['ok'])) throw new RuntimeException('enquiry: not approved');
                $ap++;
            }
        }
    }
    return ['conv' => $cid, 'message' => $mid === null ? 0 : (int)$mid, 'id' => $fid, 'drafts' => $ds, 'approved' => $ap,
            'age_s' => time() - (int)strtotime($lca . ' UTC')];
};
$out = [];
$n = 0;
try {
    foreach ($list as $o) {
        $op = (string)$o['op'];
        if ($op === 'enquiry' || $op === 'pair') {
            $r = $op === 'pair' ? $plant($o, 2, 'approve') : $plant($o, (int)($o['drafts'] ?? 1), (string)($o['upto'] ?? 'approve'));
            $out[] = ['op' => $op] + $r;
        } elseif ($op === 'inbound') {
            $ch = (string)($o['channel'] ?? 'sales');
            $at = rh106_at($o, 'sent_at', 'ago_s');
            $c = $conv->ensureConversation((string)$o['phone'], $ch, (string)$o['name'], 'webhook');
            $cid = (int)($c['id'] ?? 0);
            if ($cid <= 0) throw new RuntimeException('inbound: no conversation');
            $mid = $conv->storeMessage($cid, ['direction' => 'in', 'role' => 'customer', 'body' => (string)$o['text'],
                'wa_message_id' => 'RH106IN' . $h16($digits((string)$o['phone']) . '|' . $ch . '|' . $at . '|' . (string)$o['text']), 'sent_at' => $at]);
            $cv = $conv->getConversation($cid);
            $out[] = ['op' => $op, 'conv' => $cid, 'message' => $mid === null ? 0 : (int)$mid,
                      'lca' => ($cv !== null && (string)($cv['last_customer_at'] ?? '') === $at) ? 'this' : 'later'];
        } elseif ($op === 'delivery') {
            $ch = (string)($o['channel'] ?? 'sales');
            $c = $conv->ensureConversation((string)$o['phone'], $ch, (string)$o['name'], 'webhook');
            $cid = (int)($c['id'] ?? 0);
            if ($cid <= 0) throw new RuntimeException('delivery: no conversation');
            $now = gmdate('Y-m-d H:i:s');
            $wa = isset($o['wa_id']) ? (string)$o['wa_id'] : 'RH106OUT' . $h16($digits((string)$o['phone']) . '|' . $ch . '|' . $now . '|' . $n . '|' . bin2hex(random_bytes(8)));
            $mid = $conv->storeMessage($cid, ['direction' => 'out', 'role' => 'agent', 'body' => (string)$o['text'],
                'agent_name' => (string)($o['agent'] ?? RH106_MARK . 'agent'), 'wa_message_id' => $wa,
                'metadata' => ['source' => 'rh106'], 'sent_at' => $now]);
            if ($mid === null || (int)$mid <= 0) throw new RuntimeException('delivery: not stored, that WhatsApp id is stored already');
            $out[] = ['op' => $op, 'conv' => $cid, 'message' => (int)$mid];
        } elseif ($op === 'gone') {
            $ch = (string)($o['channel'] ?? 'sales');
            $lca = rh106_at($o, 'lca', 'lca_ago_s');
            $from = (int)$o['from'];
            $cnt = (int)$o['count'];
            // older than every open enquiry follow-up's in the book (design-v2 §A3), read through the class
            $open = $fu->allOpen(1000000, 'enquiry');
            foreach ($open as $r) {
                $l = (string)($r['last_customer_at'] ?? '');
                if ($l !== '' && !($lca < $l)) throw new RuntimeException('gone: not older than every open enquiry follow-up in the book');
            }
            for ($i = 0; $i < $cnt; $i++) {
                if ($conv->getConversation($from + $i) !== null) throw new RuntimeException('gone: a conversation holds one of the ids');
                if ($fu->openFor($from + $i) !== null) throw new RuntimeException('gone: an open follow-up holds one of the ids');
            }
            $ids = [];
            $pdo->beginTransaction();
            try {
                for ($i = 0; $i < $cnt; $i++) {
                    $f = $fu->open(['id' => $from + $i, 'last_customer_at' => $lca, 'channel' => $ch, 'phone' => (string)$o['phone']],
                                   ['topic' => RH106_MARK . 'gone', 'opened_by' => 'scan']);
                    if (empty($f['ok']) || empty($f['created'])) throw new RuntimeException('gone: not opened new');
                    $ids[] = (int)$f['id'];
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $absent = 0;
            for ($i = 0; $i < $cnt; $i++) if ($conv->getConversation($from + $i) === null) $absent++;
            if ($absent !== $cnt) throw new RuntimeException('gone: a conversation holds one of the ids after the plant');
            $out[] = ['op' => $op, 'opened' => count($ids), 'first' => $ids[0], 'last' => $ids[count($ids) - 1], 'open_before' => count($open), 'absent_after' => $absent];
        } elseif ($op === 'fx_store_keys' || $op === 'fx_store_keys_undo') {
            $h0 = rh106_sha($kf);
            $row = $s->load('kyc_config.json');
            $next = $row;
            $keys = $op === 'fx_store_keys' ? array_map('strval', array_keys($o['keys'])) : array_map('strval', $o['keys']);
            foreach ($keys as $k) {
                $there = array_key_exists($k, $row);
                if ($op === 'fx_store_keys' && $there) throw new RuntimeException('fx_store_keys: the store row holds that key already');
                if ($op === 'fx_store_keys_undo' && !$there) throw new RuntimeException('fx_store_keys_undo: the store row does not hold that key');
                if ($op === 'fx_store_keys') $next[$k] = $o['keys'][$k]; else unset($next[$k]);
            }
            $s->save('kyc_config.json', $next);
            $back = $s->load('kyc_config.json');
            foreach ($row as $k => $v) {
                if (in_array((string)$k, $keys, true)) continue;
                if (!array_key_exists($k, $back) || $back[$k] !== $v) throw new RuntimeException('fx_store_keys: another key of the store row moved');
            }
            foreach ($keys as $k) {
                if ($op === 'fx_store_keys' && (!array_key_exists($k, $back) || $back[$k] !== $o['keys'][$k])) throw new RuntimeException('fx_store_keys: not read back');
                if ($op === 'fx_store_keys_undo' && array_key_exists($k, $back)) throw new RuntimeException('fx_store_keys_undo: still read back');
            }
            if (rh106_sha($kf) !== $h0) throw new RuntimeException('fx_store_keys: kyc_config.json changed');
            $out[] = ['op' => $op, 'fixture' => true, 'what' => 'store-only claim key', 'keys' => $keys, 'file' => 'unchanged', 'row_keys' => count($back)];
        } elseif ($op === 'fx_type3' || $op === 'fx_type3_undo') {
            $k = (string)$o['key'];
            $h0 = rh106_sha($kf);
            $raw = @file_get_contents($kf);
            $file = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($file) || !array_key_exists($k, $file) || !is_string($file[$k]) || !preg_match('/^[0-9]{1,6}$/', $file[$k])) {
                throw new RuntimeException('fx_type3: kyc_config.json does not hold that key as a digit string');
            }
            $str = $file[$k];
            $num = (int)$str;
            $row = $s->load('kyc_config.json');
            $was = $op === 'fx_type3' ? $str : $num;
            if (!array_key_exists($k, $row) || $row[$k] !== $was) throw new RuntimeException('fx_type3: the store row does not hold what this step expects');
            $row[$k] = $op === 'fx_type3' ? $num : $str;
            $s->save('kyc_config.json', $row);
            $back = $s->load('kyc_config.json');
            if (!array_key_exists($k, $back) || $back[$k] !== $row[$k]) throw new RuntimeException('fx_type3: not read back');
            if (rh106_sha($kf) !== $h0) throw new RuntimeException('fx_type3: kyc_config.json changed');
            $out[] = ['op' => $op, 'fixture' => true, 'what' => "'3' against 3", 'key' => $k, 'row' => $op === 'fx_type3' ? 'number' : 'string', 'file' => 'unchanged'];
        } elseif ($op === 'fx_file' || $op === 'fx_file_back') {
            $pl = (string)$o['place'];
            $path = $pl === 'p' ? rtrim((string)$o['plugin'], '/') . '/data/config.json' : $data . ($pl === 'k' ? '/kyc_config.json' : '/config.json');
            $keep = rtrim((string)$o['keep'], '/');
            $tag = (string)$o['tag'];
            if ($op === 'fx_file_back') {
                $r = rh106_back('file', $pl, $path, $keep, $tag, $me);
                $out[] = ['op' => $op, 'fixture' => true, 'place' => $pl, 'tag' => $tag, 'was' => $r['existed'] ? 'present' : 'absent', 'now' => $r['sha']];
            } else {
                clearstatcache();
                $pre = is_file($path) ? @file_get_contents($path) : null;
                if ($pre === false) throw new RuntimeException('fx_file: the file cannot be read');
                $d0 = [];
                if (array_key_exists('set', $o)) {
                    $d0 = $pre === null ? [] : json_decode($pre, true);
                    if (!is_array($d0) || ($d0 !== [] && array_keys($d0) === range(0, count($d0) - 1))) throw new RuntimeException('fx_file: the file is not a settings object, so no key is added to it');
                    $d1 = $d0;
                    foreach ($o['set'] as $k => $v) {
                        if (array_key_exists($k, $d0)) throw new RuntimeException('fx_file: the file holds that key already');
                        $d1[$k] = $v;
                    }
                    $new = json_encode($d1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    if (!is_string($new)) throw new RuntimeException('fx_file: not encoded');
                } else {
                    $new = (string)$o['raw'];
                }
                $m = rh106_keep('file', $pl, $path, $keep, $tag, $me);
                if (($m['existed'] ? $m['orig'] : null) !== $pre) throw new RuntimeException('fx_file: the file moved meanwhile');
                rh106_replace($path, $new, (int)$m['mode'], (int)$m['uid'], (int)$m['gid'], $me);
                $st = rh106_stat($path);
                if (rh106_sha($path) !== hash('sha256', $new) || $st['mode'] !== (int)$m['mode'] || ($me === 0 && ($st['uid'] !== (int)$m['uid'] || $st['gid'] !== (int)$m['gid']))) {
                    throw new RuntimeException('fx_file: not written whole with its mode and owner');
                }
                $chk = json_decode((string)@file_get_contents($path), true);
                if (array_key_exists('set', $o)) {
                    if (!is_array($chk)) throw new RuntimeException('fx_file: the file does not decode after the key was added');
                    foreach ($o['set'] as $k => $v) {
                        if (!array_key_exists($k, $chk) || $chk[$k] !== $v) throw new RuntimeException('fx_file: the key is not read back');
                        unset($chk[$k]);
                    }
                    if ($chk !== $d0) throw new RuntimeException('fx_file: another key of the file moved');
                } elseif (is_array($chk)) {
                    throw new RuntimeException('fx_file: the file decodes');
                }
                $out[] = ['op' => $op, 'fixture' => true, 'what' => array_key_exists('set', $o) ? 'a key in a settings file' : 'a file that does not decode',
                          'place' => $pl, 'tag' => $tag, 'keys' => array_key_exists('set', $o) ? array_map('strval', array_keys($o['set'])) : [],
                          'was' => $m['existed'] ? 'present' : 'absent', 'before' => rh106_s16((string)$m['sha256']), 'after' => rh106_s16(hash('sha256', $new))];
            }
        } elseif ($op === 'fx_state_drop' || $op === 'fx_state_back') {
            $path = (string)$o['path'];
            $keep = rtrim((string)$o['keep'], '/');
            $tag = (string)$o['tag'];
            if ($op === 'fx_state_back') {
                $r = rh106_back('state', basename($path), $path, $keep, $tag, $me);
                $out[] = ['op' => $op, 'fixture' => true, 'tag' => $tag, 'now' => $r['sha']];
            } else {
                $key = (string)$o['key'];
                $want = (int)($o['expect'] ?? 1);
                $pre = @file_get_contents($path);
                if (!is_string($pre)) throw new RuntimeException('fx_state_drop: the state file cannot be read');
                $kept = [];
                $gone = 0;
                $glen = 0;
                foreach ((array)preg_split("/(?<=\n)/", $pre, -1, PREG_SPLIT_NO_EMPTY) as $l) {
                    if (strpos((string)$l, $key . '=') === 0) { $gone++; $glen += strlen((string)$l); } else $kept[] = (string)$l;
                }
                if ($gone !== $want) throw new RuntimeException('fx_state_drop: the line is not there the expected number of times');
                $new = implode('', $kept);
                if (strlen($new) !== strlen($pre) - $glen || preg_match('/^' . preg_quote($key, '/') . '=/m', $new)) throw new RuntimeException('fx_state_drop: the line was not taken out whole');
                $m = rh106_keep('state', basename($path), $path, $keep, $tag, $me);
                if ($m['orig'] !== $pre) throw new RuntimeException('fx_state_drop: the state file moved meanwhile');
                rh106_replace($path, $new, (int)$m['mode'], (int)$m['uid'], (int)$m['gid'], $me);
                if (rh106_sha($path) !== hash('sha256', $new)) throw new RuntimeException('fx_state_drop: not written whole');
                $out[] = ['op' => $op, 'fixture' => true, 'what' => 'a state line taken out', 'key' => $key, 'removed' => $gone, 'tag' => $tag,
                          'before' => rh106_s16((string)$m['sha256']), 'after' => rh106_s16(hash('sha256', $new))];
            }
        }
        $n++;
    }
} catch (Throwable $e) {
    if ($pdo !== null && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'refused after ' . $n . ': ' . get_class($e) . ' ' . preg_replace('/[^a-z_ :-]/', '', strtolower($e->getMessage())) . "\n");
    exit(3);
}
echo json_encode(['ops' => $n, 'at' => time(), 'uid' => $me, 'done' => $out], JSON_UNESCAPED_SLASHES), "\n";

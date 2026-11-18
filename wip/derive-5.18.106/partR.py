# Part R — the rollback (root docs/75 §L.7; U5 of §L.4; DL4–DL6, DL9): the one write in the script, and every text it
# changes. Runs after partL (LM-RB1 already inverted there) and after partUJ, which left the slot U5 in qf_stage.
#   R1 the rollback functions renamed: rb_pre105 → rb_pre106, rb105 → rb106, and the check code RB105 → RB106,
#      everywhere (functions, calls, comments, the header's and the summary's mentions included — partH rewords those
#      lines, partZ asserts the names); 5.18.105's block "the rollback keeps every switch" replaced by 5.18.106's.
#   R2 the one reader (as_read; AS_PHP, a heredoc): §L.7's five conditions — (1) <data>/kyc_config.json absent, or read
#      and decoded; (2) the store row as SqliteStore::load returns it, the book opened SQLITE_OPEN_READONLY as LK's lk mode
#      opens it; (3) every file key but followup_auto_send identical (===) in the row; (4, 4b) the file's and the row's
#      JSON round trips (the writer's two encodings) change no value; (5) the six files the write and the read-back load
#      at 7835906's sha256 in the installed tree (AS_SUMS), its migrations/ 7835906's set (AS_MIGS), no <plugin>/seed —
#      and followup_auto_send as the jobs read it (PluginConfig::read + FILTER_VALIDATE_BOOLEAN, FollowUpPolicy's own
#      reading), per place and effective; as DB_OWNER, through the installed code; key names and fixed codes out.
#   R3 U5 at stage A (as_u5, partUJ's slot), a note; AS-RB0 in rb_pre106 before ROLLBACK (followup_auto_send per place
#      and effective, the conditions, F5's count, the scan's reopening, a capitals notice per claim switch present, the
#      capitals notice when the key reads on and the rollback cannot write it, with the owner's command per cause); the
#      step-12 "WRITES PHONE NUMBERS AGAIN" notice and the chase note of rb_pre105 go (under 7835906 both are false).
#   R4 AS-RB1 (as_rb1 typed|v1): the one write — PluginConfig::saveOverrides with followup_auto_send '0' alone, as
#      -u "$DB_OWNER" with AS_ROOT the installed tree, the five conditions recomputed in the same PHP process just
#      before it, written only when all hold and the key reads on; the read-back; the LK map and V3g's keys; R7 right
#      after; AS_RB1 in the rollback's state file; FAIL in capitals when not written while on, never blocking. Called on
#      its own line in the typed rollback (after ROLLBACK is typed, before do_rollback) and on V1's line, before its
#      do_rollback. AS-RB2 in rb106: off through 7835906's code, FAIL unless AS-RB1 recorded why it did not write,
#      whether the key reads on or could not be read; AS_RB2_EFF what it read (partH's "what it means"); under
#      RB_NOTHING a capitals note with the owner's command. The texts after AS-RB1 (as_para, as_after_verdict, the RB
#      note) follow what it recorded: written, written with a read-back that did not hold, not known, or not written.
#   R5 OWNER_AUTOSEND_CMD — one assignment, in as_owner_cmd, defined right after DB_OWNER is read: set_config as the
#      book's owner on the live data folder, --value $v, its output cut at the settings listing, its own log; printed
#      (as_owner_text) at every site §L.7 names, each running the reader first: rb_pre106, AS-RB1's FAIL after the
#      verdict (as_after_verdict), V1's stops, the RB_NOTHING notice (AS-RB2), stage A's mixed-tree refusals, the
#      deploy-did-not-verify stop, the typed rollback's not-verified stop, the summary's by-hand paragraph (as_para);
#      the --after-only --value 1 form (as_back_on: the --after-only of a deploy that followed the latest rollback —
#      COPY_AT against that rollback's RB_COPY_AT, --redeploy typed or not) is defined here and placed by partH.
#   R6 the texts: PUTBACK_HAZARD (5.18.105's files; writes no setting) and its seven print sites; the ROLLBACK prompt
#      (whether it will write); the typed rollback's comment; V1's comment, line and stops; R7's header and db_judge's
#      ok line; the RB section's "writes nothing", "changes no setting", "reached/read by nothing" and the RB note; the
#      stage-A rollback note (S105:11575).
# Asserted from Git (7835906 and the pin, each file the same in both and outside 5.18.106's delta): the writer's path
# through PluginConfig and SqliteStore, set_config's arguments, its one success line and its listing after it,
# cliDataDir's DN_DATA_DIR, FollowUpPolicy's reading, followup_auto_send outside the ladder's keys, the vault keys and the
# secrets. Not here: the header (lines < ~660) and the summary's rollback paragraph (S105:14726-14736) — partH; generic
# carried release texts — partT; the closing of the slot U5 (partZ: the line above it starts "as_u5   # U5").
import re, textwrap, hashlib
from d106lib import git, sha_at, fill

SIX = ["lib/PluginConfig.php", "lib/ConfigVault.php", "lib/StoreInterface.php", "lib/JsonStore.php", "lib/SqliteStore.php",
       "lib/MigrationRunner.php"]
ALSO = ["tools/set_config.php", "lib/bootstrap_data.php", "lib/FollowUpPolicy.php", "lib/SalesFollowupLadder.php"]
RUNTIME = ["cron/followup_close.php", "cron/followup_run.php", "cron/followup_scan.php", "cron/followup_send.php",
           "lib/FollowUpService.php"]
V1_ECHO = ('echo "  ROLLING BACK — the public address must never redirect (every switch of the sales follow-up kept;'
           ' followup_auto_send set off first, AS-RB1)"')
RENAMES = (("rb_pre105", "rb_pre106", 1), ("rb105", "rb106", 1), ("RB105", "RB106", 12))   # left after the edits below


def cw(text, lead="# ", cont=None):
    """A comment paragraph, wrapped to 120 characters, every line starting with lead (cont after the first)."""
    out = textwrap.wrap(text, width=120, initial_indent=lead, subsequent_indent=cont if cont is not None else lead,
                        break_long_words=False, break_on_hyphens=False)
    return "\n".join(out) + "\n"


def rule(title):
    head = "# ── " + title + " "
    return head + "─" * (120 - len(head))


def need(text, s, what, n=1):
    c = text.count(s)
    if c != n:
        raise SystemExit(f"PART R: {what}: {s[:110]!r} found {c} times, expected {n}")


# ── the one reader, as the book's owner in the container: a heredoc, AS_PHP ──
AS_PHP = r"""// AS_PHP — 5.18.106's one reader (root docs/75 §L.7): U5, AS-RB0, AS-RB1, AS-RB2 and every site that prints the owner's
// own command run it. §L.7's five conditions and followup_auto_send as the jobs read it, as the database's owner in the
// container, through the code root AS_ROOT names — the installed tree; of the plugin's code only lib/PluginConfig.php's
// read() runs here, which writes nothing. Read-only: <data>/kyc_config.json read whole, the store row (kyc_config, id
// 0) through SQLITE_OPEN_READONLY and query_only, as LK's "lk" mode opens the book; values only in memory. Mode "read"
// prints one line of fixed codes, counts, key names and on/off words — never a value. AS-RB1 appends its write to this
// text (mode "write") and recomputes the five conditions in the same process, just before it. PHP 7.4 syntax.
$asMode = (string)($argv[1] ?? '');
$asPdd = rtrim((string)($argv[2] ?? ''), '/');
$asOwner = (string)($argv[3] ?? '');
$asSums = (string)($argv[4] ?? '');
$asMigs = (string)($argv[5] ?? '');
$asRoot = rtrim((string)getenv('AS_ROOT'), '/');
@ini_set('error_log', '/dev/null');   // a plugin class's own error_log() line never reaches the live PHP log
$asName = function ($k): string { $k = (string)$k; return preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $k) ? $k : '?'; };
$asList = function (array $a): string { return $a === [] ? '-' : implode(',', array_slice($a, 0, 12)) . (count($a) > 12 ? ',+' . (count($a) - 12) : ''); };
// a settings file as PluginConfig reads it: [state, the array or null] — absent (no file: no key), unreadable,
// undecodable (empty, or not JSON) or decoded
$asJson = function (string $f): array {
    if (!file_exists($f)) return ['absent', []];
    if (!is_file($f) || !is_readable($f)) return ['unreadable', null];
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? ['decoded', $d] : ['undecodable', null];
};
$asRo = function (string $pdd) {
    $p = new PDO('sqlite:' . $pdd . '/plugin.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15,
                                                                   PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $p->exec('PRAGMA query_only = ON');
    return $p;
};
// the store row as SqliteStore::load('kyc_config.json') returns it — a flat table, one JSON text with the sentinel id
// 0; no table, no row or a text that does not decode reads as []: [state, the array, or null when it cannot be read]
$asRow = function (string $pdd) use ($asRo): array {
    if (!is_file($pdd . '/plugin.sqlite3')) return ['nodb', null];
    try {
        $p = $asRo($pdd);
        $s = $p->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'kyc_config'"); $s->execute();
        if ($s->fetchColumn() === false) return ['absent', []];
        $j = $p->query('SELECT data FROM kyc_config WHERE id = 0 LIMIT 1')->fetchColumn();
        if ($j === false) return ['absent', []];
        $d = json_decode((string)$j, true);
        return is_array($d) ? ['decoded', $d] : ['undecodable', []];
    } catch (Throwable $x) { return ['unread', null]; }
};
// §L.7's five conditions; each failing one by its fixed code, in this order: code (5), file (1), row (2), differ (3),
// type (4)
$asPre = function () use ($asRoot, $asPdd, $asSums, $asMigs, $asJson, $asRow, $asName): array {
    $r = ['causes' => [], 'code' => [], 'differ' => [], 'type' => []];
    // 5 — the six files the write and the read-back load, at 7835906's sha256; the installed migrations/, 7835906's
    // set; no seed
    $n = 0;
    foreach (explode("\n", $asSums) as $x) {
        $x = trim($x);
        if ($x === '') continue;
        $n++;
        [$sha, $rel] = array_pad(explode(' ', $x, 2), 2, '');
        $f = $asRoot . '/' . $rel;
        if (!is_file($f) || !is_readable($f) || hash_file('sha256', $f) !== $sha) $r['code'][] = $asName(basename($rel, '.php'));
    }
    if ($n !== 6) $r['code'][] = 'sums' . $n;
    $m = [];
    foreach ((is_dir($asRoot . '/migrations') ? (scandir($asRoot . '/migrations') ?: []) : []) as $x) if ($x !== '.' && $x !== '..') $m[] = $x;
    sort($m, SORT_STRING);
    if (count($m) . ':' . hash('sha256', implode("\n", $m)) !== $asMigs) $r['code'][] = 'migrations' . count($m);
    if (file_exists($asRoot . '/seed')) $r['code'][] = 'seed';
    if ($r['code'] !== []) $r['causes'][] = 'code';
    // 1 — kyc_config.json absent, or present, readable and decoding to an array
    [$r['file'], $file] = $asJson($asPdd . '/kyc_config.json');
    if ($file === null) $r['causes'][] = 'file';
    // 2 — the store row
    [$r['row'], $row] = $asRow($asPdd);
    if ($row === null) $r['causes'][] = 'row';
    // 3 — every key of the file but followup_auto_send present in the row, its decoded value identical (===)
    if ($file !== null && $row !== null) {
        foreach ($file as $k => $v) {
            if ((string)$k === 'followup_auto_send') continue;
            if (!array_key_exists($k, $row) || $row[$k] !== $v) $r['differ'][] = $asName($k);
        }
    }
    if ($r['differ'] !== []) $r['causes'][] = 'differ';
    // 4 and 4b — no value changes type through the writer's two encodings: the file's (writeKycFile), the row's (save)
    foreach ([['k', $file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES], ['s', $row, JSON_UNESCAPED_UNICODE]] as [$w, $a, $fl]) {
        if (!is_array($a)) continue;
        $e = json_encode($a, $fl);
        $b = is_string($e) ? json_decode($e, true) : null;
        if ($b === $a) continue;
        $before = count($r['type']);
        foreach ($a as $k => $v) if (!is_array($b) || !array_key_exists($k, $b) || $b[$k] !== $v) $r['type'][] = $w . ':' . $asName($k);
        if (count($r['type']) === $before) $r['type'][] = $w . ':*';
    }
    if ($r['type'] !== []) $r['causes'][] = 'type';
    $r['fileArr'] = $file;
    $r['rowArr'] = $row;
    return $r;
};
// followup_auto_send as the jobs read it: PluginConfig::read() (load()'s files in load()'s order, the vault filling
// only what is missing, never written) and FILTER_VALIDATE_BOOLEAN, as FollowUpPolicy::mayAutoSend reads it
$asEff = function () use ($asRoot, $asPdd): string {
    try {
        require_once $asRoot . '/lib/PluginConfig.php';
        $c = PluginConfig::read($asRoot, $asPdd);
        return filter_var($c['followup_auto_send'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'on' : 'off';
    } catch (Throwable $x) { return 'unread'; }
};
$asPlace = function ($a): string {
    if (!is_array($a)) return '?';
    if (!array_key_exists('followup_auto_send', $a)) return '-';
    return filter_var($a['followup_auto_send'], FILTER_VALIDATE_BOOLEAN) ? 'on' : 'off';
};
$asPlaces = function (array $pre) use ($asPlace, $asJson, $asRoot, $asPdd): string {
    return 'k:' . $asPlace($pre['fileArr']) . ',s:' . $asPlace($pre['rowArr']) . ',p:' . $asPlace($asJson($asRoot . '/data/config.json')[1])
         . ',d:' . $asPlace($asJson($asPdd . '/config.json')[1]);
};
// F5's count on the live book, read-only: open enquiry follow-ups holding two or more approved drafts, and those drafts
$asF5 = function () use ($asRo, $asPdd): string {
    try {
        $p = $asRo($asPdd);
        $t = function (string $n) use ($p): bool { $s = $p->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"); $s->execute([$n]); return $s->fetchColumn() !== false; };
        if (!$t('followups') || !$t('followup_drafts')) return '-';
        $kind = false;
        foreach ($p->query('PRAGMA table_info(followups)')->fetchAll(PDO::FETCH_ASSOC) as $c) if ((string)$c['name'] === 'kind') $kind = true;
        $ek = $kind ? " AND COALESCE(f.kind,'enquiry')='enquiry'" : '';
        $r = $p->query("SELECT COUNT(*), COALESCE(SUM(n), 0) FROM (SELECT COUNT(*) AS n FROM followup_drafts d JOIN followups f ON f.id = d.followup_id"
                     . " WHERE d.status = 'approved' AND f.closed_at IS NULL" . $ek . " GROUP BY d.followup_id HAVING COUNT(*) >= 2)")->fetch(PDO::FETCH_NUM);
        return (int)$r[0] . '/' . (int)$r[1];
    } catch (Throwable $x) { return '?'; }
};
if ($asMode === 'read') {
    $pre = $asPre();
    echo 'as=read eff=', $asEff(), ' places=', $asPlaces($pre), ' pre=', $pre['causes'] === [] ? 'ok' : implode(',', $pre['causes']),
         ' file=', $pre['file'], ' row=', $pre['row'], ' code=', $asList($pre['code']), ' differ=', $asList($pre['differ']),
         ' type=', $asList($pre['type']), ' f5=', $asF5();
}"""

# ── AS-RB1's write, appended to AS_PHP inside as_rb1 (indented: no line of it may start a column-0 brace) ──
AS_WRITE = r"""    // AS-RB1's write (root docs/75 §L.7), appended to AS_PHP: the five conditions recomputed here, just before it; one
    // key, one value, through the plugin's own writer (PluginConfig::saveOverrides: kyc_config.json rewritten, the
    // whole file mirrored into the store row); then the read-back. One line out: fixed codes only.
    if ($asMode === 'write') {
        $pre = $asPre();
        $eff = $asEff();
        $say = ' eff=' . $eff . ' places=' . $asPlaces($pre) . ' file=' . $pre['file'] . ' row=' . $pre['row'] . ' code=' . $asList($pre['code'])
             . ' differ=' . $asList($pre['differ']) . ' type=' . $asList($pre['type']);
        if ($pre['causes'] !== [] || $eff !== 'on') {
            print 'as=nowrite pre=' . ($pre['causes'] === [] ? 'ok' : implode(',', $pre['causes'])) . $say;
            exit(0);
        }
        $dd = $asPdd;
        $kf = $dd . '/kyc_config.json';
        // each key but followup_auto_send, by the sha256 of serialize(): a change of type counts
        $hs = function ($a): array {
            $o = [];
            foreach ((array)$a as $k => $v) if ((string)$k !== 'followup_auto_send') $o[(string)$k] = hash('sha256', serialize($v));
            ksort($o, SORT_STRING);
            return $o;
        };
        $fh = function (string $f): string { return is_file($f) ? (string)hash_file('sha256', $f) : (file_exists($f) ? 'other' : 'absent'); };
        $keys0 = array_map('strval', array_keys($pre['fileArr']));
        $file0 = $hs($pre['fileArr']);
        $row0 = $hs($pre['rowArr']);
        $p0 = $fh($asRoot . '/data/config.json');
        $d0 = $fh($dd . '/config.json');
        [$ok, $err] = PluginConfig::saveOverrides($dd, ['followup_auto_send' => '0']);
        if (!$ok) { print 'as=refused' . $say; exit(0); }
        clearstatcache();
        $bad = [];
        [$fs1, $file1] = $asJson($kf);
        [$rs1, $row1] = $asRow($dd);
        if (!is_array($file1) || $fs1 !== 'decoded') $bad[] = 'decodes';
        else {
            $k1 = array_map('strval', array_keys($file1));
            $want = $keys0;
            if (!in_array('followup_auto_send', $want, true)) $want[] = 'followup_auto_send';
            sort($k1, SORT_STRING);
            sort($want, SORT_STRING);
            if ($k1 !== $want) $bad[] = 'keys';
            if (($file1['followup_auto_send'] ?? null) !== '0') $bad[] = 'value';
            if ($hs($file1) !== $file0) $bad[] = 'file-others';
        }
        $st = @stat($kf);
        if (!is_array($st) || ($st['uid'] . ':' . $st['gid']) !== $asOwner) $bad[] = 'owner';
        if (!is_array($st) || ((int)$st['mode'] & 0777) !== 0600) $bad[] = 'mode';
        if ($asEff() !== 'off') $bad[] = 'effective';
        if (!is_array($row1) || $rs1 === 'unread') $bad[] = 'row-unread';
        elseif ($hs($row1) !== $row0) $bad[] = 'row-others';
        if ($fh($asRoot . '/data/config.json') !== $p0 || $fh($dd . '/config.json') !== $d0) $bad[] = 'config-json';
        print 'as=written check=' . ($bad === [] ? 'ok' : implode(',', $bad)) . ' row0='
            . ((is_array($row1) && ($row1['followup_auto_send'] ?? null) === '0') ? 'yes' : 'no') . $say;
    }"""

RB_HEAD = (rule("5.18.106: the rollback, and its one write (RB106; AS-RB0, AS-RB1, AS-RB2; root docs/75 §L.7)") + "\n"
    + cw("The rollback — --rollback, and V1's automatic one — puts 7835906 back through do_rollback (git checkout, the "
         "documented deploy, stamp_copy on COPY_MARK, RB_COPY_AT) after one write, followup_auto_send '0' (AS-RB1): "
         "5.18.105's run drafts a follow-up whose draft is waiting and its sender sends both (root docs/75 §I) — with "
         "automatic approval off, a person approves each draft. Nothing else moves: 7835906 is the code the ladder was "
         "built on and kept, its tool and its two ladder classes the installed ones in both releases, so every other key "
         "keeps its meaning; the copy leaves ./data alone (scripts/deploy-hybrid.sh), so the keys, the store row, 089–091's "
         "tables and P7's collections stay as they are, and so do every close 5.18.106 made (nothing reopens one) and "
         "every draft it set expired. It never runs the switch tool, nor a by-hand put-back of rows or keys, at either "
         "call site. PUTBACK_SHOW stays: 7835906's code put-back only, in its own block. A rollback is an emergency act: "
         "neither the ladder nor followup_auto_send ever blocks it.")
    + cw("The one reader (as_read; AS_PHP below): §L.7's five conditions and followup_auto_send as the jobs read it, as "
         "the database's owner, through the installed code, on the live <data>/kyc_config.json and store row — "
         "read-only, values only in memory; key names, fixed codes and on/off words out. U5 at stage A, AS-RB0 before "
         "ROLLBACK, AS-RB1 (which recomputes the conditions in its own process just before it writes: the earlier "
         "readings are advisory), AS-RB2 after the put-back and every site that prints the owner's own command run it.",
         "#   ")
    + cw("The writer is the plugin's own, PluginConfig::saveOverrides($dataDir, ['followup_auto_send' => '0']): it reads "
         "kyc_config.json, writes it back pretty-printed and mirrors the whole file into the store row (array_merge(row, "
         "file)), so the five conditions are what keep the mirror from changing what the owner has not seen named — (1) "
         "kyc_config.json absent, or read and decoded; (2) the row read as SqliteStore::load returns it; (3) every key of "
         "the file but followup_auto_send in the row, its value identical; (4) neither the file nor the row changes a "
         "value's type through the writer's encodings; (5) DB_OWNER known, the six files the write and the read-back load "
         "at 7835906's sha256 in the installed tree (AS_SUMS), its migrations/ 7835906's set (AS_MIGS), no <plugin>/seed. "
         "Each failing condition has its own text; where the cause allows one, the owner gets their own command "
         "(OWNER_AUTOSEND_CMD, built by as_owner_cmd after DB_OWNER is read) — the mirror is accepted for the owner's "
         "own commands and refused only for the script's own write.", "#   "))


def rb_block(sums, migs, lk_rb0, rb_body):
    p = [RB_HEAD]
    p.append(cw("AS_SUMS, AS_MIGS — §L.7's condition 5, read off Git by the derivation (derive106.py, partR), which asserts "
                "each of the six files the same at 7835906 and the pin and outside the delta, and 7835906's migrations/ the "
                "pin's: the six files the write and the read-back load, each with the sha256 7835906 gives it; and the "
                "installed migrations/ as 7835906's set — the count of its names and the sha256 of them sorted, one a line."))
    p.append("AS_SUMS=\"$(cat <<'SUMS'\n" + sums + "\nSUMS\n)\"\n")
    p.append(f'AS_MIGS="{migs}"\n')
    p.append('AS_RB0=""; AS_RB0_SAY=""; AS_RB1=""; AS_RB1_SAY=""; AS_RB2_EFF=""\n')
    p.append("AS_PHP=\"$(cat <<'PHP'\n" + AS_PHP + "\nPHP\n)\"\n")
    p.append(cw("as_read — the one reader: one line, \"as=read eff=… places=… pre=ok|<causes> file=… row=… code=… "
                "differ=… type=… f5=…\", or \"as=noowner\" (DB_OWNER not known: nothing runs as root) or "
                "\"as=unread(<why>)\". Read-only. Runs in $( … ): it reads nothing under the run's folder."))
    p.append(r'''as_read() {
  local o
  [ -n "$DB_OWNER" ] || { printf 'as=noowner'; return 1; }
  o="$(docker exec -u "$DB_OWNER" -e "AS_ROOT=$IN_CONTAINER" "$CONTAINER" php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -r "$AS_PHP" read "$PDD_IN" "$DB_OWNER" "$AS_SUMS" "$AS_MIGS" 2>/dev/null </dev/null)"
  case "$o" in
    "as=read "*) printf '%s' "$o" ;;
    *) printf 'as=unread(%s)' "$(printf '%s' "${o:-no answer}" | tr -c 'A-Za-z0-9_=:.,?-' '_' | cut -c1-80)"; return 1 ;;
  esac
}
as_places_say() { printf 'per place: %s; - absent, ? unread' "$(kv "$1" places | sed -e 's/^k:/kyc_config.json /' -e 's/,s:/, its store row /' -e 's#,p:#, <plugin>/data/config.json #' -e 's#,d:#, <data>/config.json #')"; }   # as_places_say LINE
as_show() { printf '  %s\n' "${1# }"; }   # as_show TEXT — a text of as_owner_text or as_para, as its own lines
''')
    p.append(cw("as_causes_say LINE — §L.7's failing conditions, each with its fixed reason, key names only"))
    p.append(r'''as_causes_say() {
  local c o=""
  for c in $(kv "$1" pre | tr ',' ' '); do
    case "$c" in
      ok) ;;
      code) o="$o; condition 5: the code here is not $BASELINE_COMMIT's ($(kv "$1" code))" ;;
      file) o="$o; condition 1: <data>/kyc_config.json is $(kv "$1" file)" ;;
      row) o="$o; condition 2: the store row could not be read ($(kv "$1" row))" ;;
      differ) o="$o; condition 3: the file and the store row disagree on $(kv "$1" differ)" ;;
      type) o="$o; condition 4: a value would change type ($(kv "$1" type))" ;;
      *) o="$o; an unknown condition ($c)" ;;
    esac
  done
  printf '%s' "${o#; }"
}
as_cmd_ok() { case "$1" in "as=read "*) case ",$(kv "$1" pre)," in *,code,*|*,file,*) return 1 ;; esac; return 0 ;; esac; return 1; }   # as_cmd_ok LINE — the cause allows the owner a command
''')
    p.append(cw("as_owner_text 0|1 LINE — the owner's own command for followup_auto_send (0, or 1 to turn it back on), as "
                "the one reader's LINE (this site's own reading) allows it: the text for each failing condition, never one "
                "sentence for all; no command where the cause forbids one (the code is not 7835906's; kyc_config.json "
                "cannot be read or does not decode), where the reader could not run, or where nothing is to change. The "
                "command (OWNER_AUTOSEND_CMD, as_owner_cmd) runs set_config as the book's owner, its output cut where the "
                "settings listing begins, alone, with its own log; printed with the one line that means success, never "
                "run. Prints text only, starting with a space: a stop message carries it, or as_show."))
    p.append(r'''as_owner_text() {
  local v="${1:-0}" o="$2" w c say="" cmd=1 nx e
  w="$( [ "$v" = "1" ] && echo on || echo off)"
  nx="$( [ "$v" = "1" ] && echo "run --after-only again" || echo "run the rollback again")"
  case "$o" in
    as=noowner) printf ' No command is printed: the owner of plugin.sqlite3 is not known (DB_OWNER) — a settings write runs as that user, never as root (SQLite could leave a root-owned file beside the book, and the plugin could not read a root-owned kyc_config.json); find why first, and send the log file.'; return 0 ;;
    "as=read "*) ;;
    *) printf ' No command is printed: the one reader could not run (%s) — what a settings write would change is not known; send the log file.' "${o#as=}"; return 0 ;;
  esac
  e="$(kv "$o" eff)"
  if [ "$e" = "$w" ]; then printf ' It already reads %s (%s): nothing to run.' "$w" "$(as_places_say "$o")"; return 0; fi
  for c in $(kv "$o" pre | tr ',' ' '); do
    case "$c" in
      ok) ;;
      code) cmd=""; say="$say NO COMMAND IS PRINTED: the code a settings write would load here is not $BASELINE_COMMIT's reviewed code ($(kv "$o" code)) — the writer, the store, the migration runner or the seed would run code nobody reviewed for it; find why first, and send the log file." ;;
      file) cmd=""
        if [ "$(kv "$o" file)" = "unreadable" ]; then say="$say <data>/KYC_CONFIG.JSON CANNOT BE READ BY THE PLUGIN'S USER (ITS OWNER OR ITS MODE): THE PLUGIN IGNORES ITS VALUES TODAY, AND A SETTINGS SAVE REFUSES. NO COMMAND IS PRINTED: FIX ITS OWNERSHIP BY HAND FIRST (THE OWNER OF plugin.sqlite3), THEN $nx."
        else say="$say <data>/KYC_CONFIG.JSON DOES NOT DECODE (IT IS EMPTY OR NOT JSON): THE PLUGIN IGNORES THAT FILE TODAY, SO ITS VALUES ARE NOT IN EFFECT, AND set_config — OR ANY SETTINGS SAVE — WOULD REPLACE IT WITH A ONE-KEY FILE. NO COMMAND IS PRINTED: REPAIR IT BY HAND FIRST, THEN $nx."; fi ;;
      row) say="$say The settings row in the book could not be read ($(kv "$o" row)): the command below still writes <data>/kyc_config.json, which alone decides (the jobs read the file), and its copy into the row may fail quietly." ;;
      differ) say="$say The command below also copies <data>/kyc_config.json, whole, into the settings row, so $(kv "$o" differ) take the file's values there (missing or different in the row now), and it refreshes the vault (a later run's Z1 reads that) — your decision." ;;
      type) say="$say The command below rewrites <data>/kyc_config.json as the plugin does and copies it into the settings row: the values of $(kv "$o" type) would change type (k: in the file, s: in the row) — your decision." ;;
      *) cmd=""; say="$say No command is printed: the reader named a condition this script does not know ($c)." ;;
    esac
  done
  case "$e" in
    on|off) printf ' It reads %s (%s).%s' "$e" "$(as_places_say "$o")" "$say" ;;
    *) printf ' It could not be read as the jobs read it (%s; %s) — the command sets it whatever it reads.%s' "$e" "$(as_places_say "$o")" "$say" ;;
  esac
  [ -n "$cmd" ] || return 0
  as_owner_cmd "$v"
  printf ' Your own command, alone, with its own log:\n      %s\n  It prints the line "  followup_auto_send = %s" once it has saved; without that line take nothing as saved (%s: it reads the setting first).' "$OWNER_AUTOSEND_CMD" "$v" "$nx"
}
''')
    p.append(cw("as_para pb|sum|rb|v1 — followup_auto_send where a message prints the put-back by hand, or ends V1's "
                "automatic rollback: one paragraph of text, built at print time — the one reader run here, the text for its "
                "cause and the owner's own command where the cause allows one. pb (stage A's mixed-tree refusals, the "
                "deploy that did not verify) and sum (the summary's by-hand paragraph): before any rollback ran, the put-back "
                "writes no setting. rb (a rollback that did not verify): the sentence and the command only when AS-RB1 did "
                "not write, and once; when it wrote, that 5.18.106 keeps serving with automatic approval off. v1 (V1's "
                "verified stop): only when AS-RB1 did not write. The headline goes by what AS-RB1 recorded: a write whose "
                "read-back did not hold, or whose answer was lost, is said as such, never as not set off — the reading "
                "below says how it reads now (review RB-3). Runs in $( … ): it reads nothing under the run's folder."))
    p.append(r'''as_para() {
  case "$1:${AS_RB1:-}" in
    rb:written) printf ' followup_auto_send was set off before the put-back was tried (AS-RB1): %s keeps serving with automatic approval off, and a put-back by hand leaves it off — a person approves each draft.' "$EXPECTED_VERSION"; return 0 ;;
    rb:off) printf ' followup_auto_send read off before the put-back was tried (AS-RB1 wrote nothing), and a put-back by hand leaves it so.'; return 0 ;;
    v1:written|v1:off) return 0 ;;
    pb:*) printf '\n\n  The put-back by hand does not switch followup_auto_send off, as the scripted rollback does first (AS-RB1).' ;;
    sum:*) printf '\n  followup_auto_send before a put-back by hand, which writes no setting (the rollback above sets it off first, AS-RB1):' ;;
    rb:written-check:*|v1:written-check:*) printf "\n\n  AS-RB1 WROTE followup_auto_send '0', BUT ITS READ-BACK DID NOT HOLD (%s)%s." "${AS_RB1#written-check:}" "$( [ "$1" = "rb" ] && echo ", and the put-back by hand writes no setting")" ;;
    rb:unknown:*|v1:unknown:*) printf '\n\n  WHETHER AS-RB1 SET followup_auto_send OFF IS NOT KNOWN (%s)%s.' "${AS_RB1_SAY:-?}" "$( [ "$1" = "rb" ] && echo ", and the put-back by hand writes no setting")" ;;
    *) printf '\n\n  AS-RB1 DID NOT SET followup_auto_send OFF (%s)%s.' "${AS_RB1_SAY:-not run}" "$( [ "$1" = "rb" ] && echo ", and the put-back by hand does not either")" ;;
  esac
  as_owner_text 0 "$(as_read)"
}
''')
    p.append(cw("as_u5 — U5 (root docs/75 §L.4), stage A (a deploy and --check), a note: whether a rollback could write "
                "followup_auto_send — §L.7's five conditions by the one reader on the LIVE <data>/kyc_config.json and store "
                "row, never a copy's (a copy's settings are cut to the keep list, without the keys the conditions compare)"))
    p.append(r'''as_u5() {
  local o w; o="$(as_read)"
  case "$o" in
    "as=read "*)
      if [ "$(kv "$o" pre)" = "ok" ]; then w="hold"; else w="do not hold — $(as_causes_say "$o")"; fi
      case "$(kv "$o" eff):$(kv "$o" pre)" in
        on:ok) w="$w — a rollback would set it off itself before the put-back (AS-RB1), recomputing them just before" ;;
        on:*) w="$w: a rollback would not write it, and says so before ROLLBACK, with your own command where the cause allows one" ;;
        off:*) w="$w; while it reads off a rollback writes nothing (AS-RB1 writes only a key that reads on)" ;;
        *) w="$w; a rollback writes it only if AS-RB1, reading it again, finds it on and the five conditions holding" ;;
      esac
      note "U5 whether a rollback could write followup_auto_send (root docs/75 §L.7; the one reader, on the live settings file and store row, read-only): it reads $(kv "$o" eff) as the jobs read it ($(as_places_say "$o")); §L.7's five conditions $w" ;;
    *) note "U5 whether a rollback could write followup_auto_send could not be read (${o#as=}) — NOT ESTABLISHED; a rollback reads it again before ROLLBACK (AS-RB0)" ;;
  esac
}
''')
    p.append(cw("as_after_verdict — after a typed rollback's verdict, where AS-RB1 did not set followup_auto_send off "
                "while it read on, wrote it but its read-back did not hold, or could not tell (DL6: never a reason to stop): "
                "the one reader, run once, 7835906's code installed — reading off now, a plain line, nothing to run; "
                "otherwise a headline by what AS-RB1 recorded (a write whose read-back did not hold, or whose answer was "
                "lost, is never said as not set off; review RB-3, L8-3) and the owner's own command, alone, as that reading "
                "allows it. Printed, never run; counts nothing."))
    p.append(r'''as_after_verdict() {
  local o
  { [ "$MODE" = "rollback" ] && [ -z "$RB_NOTHING" ]; } || return 0
  case "${AS_RB1:-}" in ''|written|off) return 0 ;; esac
  o="$(as_read)"
  echo
  if [ "$(kv "$o" eff)" = "off" ]; then
    echo "  followup_auto_send reads off now, through $BASELINE_VERSION's code (AS-RB1: ${AS_RB1_SAY:-?}; $(as_places_say "$o")): nothing to run."
    return 0
  fi
  case "${AS_RB1:-}" in
    written-check:*) echo "  AS-RB1 WROTE followup_auto_send '0', BUT ITS READ-BACK DID NOT HOLD (${AS_RB1#written-check:}) — UNDER $BASELINE_VERSION IT APPROVES AND SENDS AUTOMATICALLY WHILE IT READS ON; as it reads now:" ;;
    unknown:*) echo "  WHETHER AS-RB1 SET followup_auto_send OFF IS NOT KNOWN (${AS_RB1_SAY:-?}) — UNDER $BASELINE_VERSION IT APPROVES AND SENDS AUTOMATICALLY WHILE IT READS ON; as it reads now:" ;;
    *) echo "  AS-RB1 DID NOT SET followup_auto_send OFF (${AS_RB1_SAY:-?}) — UNDER $BASELINE_VERSION IT APPROVES AND SENDS AUTOMATICALLY WHILE IT READS ON:" ;;
  esac
  as_show "$(as_owner_text 0 "$o")"
}
''')
    p.append(cw("as_rb_last — the latest rollback record (root docs/75 §L.8): among this folder's kept state-*.env.prev-* "
                "files and rollback-state-*.env — never this deploy's own state file: a V1 of this deploy that wrote "
                "followup_auto_send, or may have, stops as_back_on before it asks, and one that wrote nothing set nothing "
                "off, so it must not hide an earlier rollback's record (review 2) — the one with the newest RB_COPY_AT (A14 "
                "reads them so); prints its AS_RB1 "
                "(\"written\", a cause, or \"none\" when that record carries none) and that RB_COPY_AT, a space between, or "
                "nothing when no rollback is on record."))
    p.append(r'''as_rb_last() {
  local f v t="" w=""
  for f in "$STATE".prev-* "$OUT"/rollback-state-*.env; do
    [ -f "$f" ] || continue
    v="$(sed -n 's/^RB_COPY_AT=//p' "$f" 2>/dev/null | tail -n1)"
    case "$v" in ''|*[!0-9]*) continue ;; esac
    if [ -z "$t" ] || [ "$v" -gt "$t" ]; then t="$v"; w="$f"; fi
  done
  [ -n "$w" ] || return 0
  v="$(sed -n 's/^AS_RB1=//p' "$w" 2>/dev/null | tail -n1)"
  printf '%s %s' "${v:-none}" "$t"
}
''')
    p.append(cw("as_back_on — root docs/75 §L.8, for the summary of a PASSED --after-only (partH places the call): after a "
                "rollback followup_auto_send stays off; the --after-only of a deploy that followed the latest rollback — this "
                "state file's COPY_AT later than that rollback's RB_COPY_AT, whether --redeploy was typed or not (review "
                "L8-4) — that finds it off reads that rollback's record and, only when the record carries AS_RB1=written, "
                "says so and prints the owner's own command to turn it back on (--value 1), alone, as the owner's choice. A "
                "rollback whose copy came after this deploy's (one that left 5.18.106 serving), or a V1 rollback of this "
                "deploy that wrote followup_auto_send or may have (its AS_RB1 written, written-check or unknown in this "
                "state file, its copy made or not; review 2), offers nothing here: its own "
                "stop said what it left. Prints nothing otherwise; never runs it."))
    p.append(r'''as_back_on() {
  local o c r t
  [ "$MODE" = "after" ] || return 0
  case "$(sed -n 's/^AS_RB1=//p' "$STATE" 2>/dev/null | tail -n1)" in written|written-check:*|unknown:*) return 0 ;; esac   # this deploy's V1 wrote it, or may have (review 2)
  c="$(sed -n 's/^COPY_AT=//p' "$STATE" 2>/dev/null | tail -n1)"
  r="$(as_rb_last)"; t="${r##* }"
  case "$c" in ''|*[!0-9]*) return 0 ;; esac
  case "$t" in ''|*[!0-9]*) return 0 ;; esac
  [ "$t" -lt "$c" ] || return 0
  [ "${r%% *}" = "written" ] || return 0
  o="$(as_read)"
  [ "$(kv "$o" eff)" = "off" ] || return 0
  echo
  echo "  followup_auto_send reads off: the latest rollback set it off (AS_RB1=written in its record; its copy at $(utc_of "$t"), before this deploy's at $(utc_of "$c")), and it stays off until you turn it on. Turning it back on — automatic approval, as before that rollback — is your choice:"
  as_show "$(as_owner_text 1 "$o")"
}
''')
    p.append(cw("rb_pre106 — before ROLLBACK is typed (root docs/75 §L.7; read-only): LK-RB0 as 5.18.105 read it — the "
                "ladder's keys in the four places under the kept LK key (one of this run's own when the deploy's key file "
                "is not there), the rows RB106 judges \"never fell\" against, and the tool's show on a copy (the pin's "
                "tools/, 7835906's bytes); QC-RB2 was counted in stage A, as before. Then AS-RB0, the one reader: "
                "followup_auto_send per place and as the jobs read it, §L.7's five conditions, F5's count (in capitals "
                "when above 0) and what 5.18.105's scan reopens; for each claim switch present in the file or the store "
                "row (LK's map, as CS-G1 reads it), a notice in capitals with its off command alone on its line; and when "
                "followup_auto_send reads on and the rollback cannot write it — or DB_OWNER is not known, so AS-RB1 cannot "
                "write as anyone — a notice in capitals with the owner's own command where the cause allows one; when it "
                "could not be read as the jobs read it, the same notice saying the rollback may not write it; when the "
                "reader itself could not run, no capitals (AS-RB1 reads it again in its own process) and no command; when "
                "it reads off, a plain note that nothing will be written (review RB-1). "
                "The operator is never told to change a switch while this script waits at the ROLLBACK prompt: LK-RB0 is "
                "already read, a switch changed now would read as a FAIL in LK-RB1, and scripts/sales-followup.sh is "
                "forbidden while the rollback runs. Every alternative is: answer anything but ROLLBACK (nothing has been "
                "changed), run the command, alone, then run the rollback again — each command printed alone on its own "
                "line, never run by this script and never in one block with the rollback command (root docs/44 §16.9). "
                "AS_RB0_SAY carries what the rollback will write into the ROLLBACK prompt."))
    p.append("rb_pre106() {\n  local o sub=\"\" a f c w\n"
             "  hdr \"B. Before ROLLBACK: the ladder and followup_auto_send as they stand (LK-RB0, AS-RB0; read-only — the rollback writes followup_auto_send '0' alone, when it reads on and §L.7's five conditions hold, and keeps every other switch; root docs/75 §L.7)\"\n"
             + lk_rb0)
    p.append(r'''  # AS-RB0 — followup_auto_send, per place and as the jobs read it, and §L.7's five conditions (the one reader)
  a="$(as_read)"; AS_RB0="$a"
  case "$a" in
    "as=read "*)
      note "AS-RB0 followup_auto_send before the rollback, through the installed code (PluginConfig::read, as FollowUpPolicy::mayAutoSend reads it): $(kv "$a" eff) — $(as_places_say "$a"); §L.7's five conditions $( [ "$(kv "$a" pre)" = "ok" ] && echo hold || echo "do not hold: $(as_causes_say "$a")")"
      f="$(kv "$a" f5)"
      case "$f" in
        0/0) note "AS-RB0 no open enquiry follow-up holds two or more approved drafts (F5's count)" ;;
        [0-9]*/[0-9]*) note "AS-RB0 ${f%/*} OPEN ENQUIRY FOLLOW-UP(S) HOLD TWO OR MORE APPROVED DRAFTS (${f#*/} IN ALL): $BASELINE_VERSION'S SENDER WILL SEND EACH OF THOSE FOLLOW-UPS' APPROVED DRAFTS IN ONE PASS — $EXPECTED_VERSION SENDS ONE AND EXPIRES THE REST (F5). WITH followup_auto_send OFF NO NEW DRAFT IS APPROVED, BUT THESE ARE APPROVED ALREADY" ;;
        *) note "AS-RB0 F5's count could not be read (${f:-not read})" ;;
      esac ;;
    as=noowner) note "AS-RB0 followup_auto_send and §L.7's five conditions could not be read: the owner of plugin.sqlite3 is not known (DB_OWNER), and this script never reads or writes the book as root" ;;
    *) note "AS-RB0 followup_auto_send and §L.7's five conditions could not be read (${a#as=}) — the rollback will not write it unless AS-RB1, reading them again in its own process, finds all five holding and the key on" ;;
  esac
  note "AS-RB0 under $BASELINE_VERSION the scan opens a new follow-up for a recently quiet conversation (1–14 days) whose follow-up $EXPECTED_VERSION closed — $EXPECTED_VERSION keeps one follow-up per quiet spell (F4), $BASELINE_VERSION's scan does not; the closes themselves stay (nothing reopens one)"
  # the claim switches (LK's map, as CS-G1 reads it): under 5.18.105 each puts the enquiry sends into claim mode
  lk_ok "${LK_RB0:-}" || note "AS-RB0 whether a claim switch is on could not be read (LK-RB0 above) — under $BASELINE_VERSION each of offers_live_enabled, offers_test_enabled and quote_chase_enabled puts the enquiry sends into claim mode"
  f="$(cs_found "$(lk_map "${LK_RB0:-}")")"
  if cs_has "$f" offers_live_enabled; then
    note "STEP 12 (OFFERS-LIVE) IS ON: offers_live_enabled IS PRESENT IN <data>/kyc_config.json OR ITS STORE ROW. UNDER $BASELINE_VERSION THIS MEANS CLAIM MODE FOR THE ENQUIRY SENDS. CHANGE NO SWITCH WHILE THIS SCRIPT WAITS. TO END CLAIM MODE: ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — RUN THE COMMAND BELOW, ALONE (off offers-live also pauses a running offer; THIS SCRIPT NEVER RUNS IT), THEN RUN THE ROLLBACK AGAIN:"
    echo
    echo "      cd $REPO && bash $QF_WRAPPER off offers-live"   # step 12's own command: printed, never run (RB)
    echo
  fi
  if cs_has "$f" offers_test_enabled; then
    note "STEP 11 (OFFERS-TEST) IS ON: offers_test_enabled IS PRESENT IN <data>/kyc_config.json OR ITS STORE ROW. UNDER $BASELINE_VERSION THIS MEANS CLAIM MODE FOR THE ENQUIRY SENDS. CHANGE NO SWITCH WHILE THIS SCRIPT WAITS. TO END CLAIM MODE: ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — RUN THE COMMAND BELOW, ALONE (off offers-test also turns offers-live off and pauses a running offer; THIS SCRIPT NEVER RUNS IT), THEN RUN THE ROLLBACK AGAIN:"
    echo
    echo "      cd $REPO && bash $QF_WRAPPER off offers-test"   # step 11's own command: printed, never run (RB)
    echo
  fi
  if cs_has "$f" quote_chase_enabled; then
    note "STEP 6 (CHASE) IS ON: quote_chase_enabled IS PRESENT IN <data>/kyc_config.json OR ITS STORE ROW. UNDER $BASELINE_VERSION THIS MEANS CLAIM MODE FOR THE ENQUIRY SENDS. CHANGE NO SWITCH WHILE THIS SCRIPT WAITS. TO END CLAIM MODE: ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — RUN THE COMMAND BELOW, ALONE (off chase also turns step 10, cold, off; THIS SCRIPT NEVER RUNS IT), THEN RUN THE ROLLBACK AGAIN:"
    echo
    echo "      cd $REPO && bash $QF_WRAPPER off chase"   # step 6's own command: printed, never run (RB)
    echo
  fi
  # followup_auto_send: what the rollback will write, said before the question, by what the one reader answered —
  # DB_OWNER not known: AS-RB1 cannot write as anyone, capitals; a reading: by what it read — off, on with the five
  # conditions holding, or not: the capitals with their causes, never empty; the reader did not run: no capitals,
  # AS-RB1 reads it again in its own process (review RB-1)
  case "$a" in
    as=noowner) AS_RB0_SAY="followup_auto_send NOT set off — the rollback cannot write it (above)"
      note "THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (noowner: the owner of plugin.sqlite3 is not known, and this script never writes as root): $BASELINE_VERSION WILL THEN APPROVE AND SEND AUTOMATICALLY WHILE IT READS ON. TO AVOID THAT, ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — AND ACT ON WHAT FOLLOWS (NO COMMAND CAN BE PRINTED FOR THIS CAUSE), THEN RUN THE ROLLBACK AGAIN:"
      as_show "$(as_owner_text 0 "$a")" ;;
    "as=read "*)
      case "$(kv "$a" eff):$(kv "$a" pre)" in
        off:*) AS_RB0_SAY="followup_auto_send already off, nothing written"
          note "AS-RB0 followup_auto_send already reads off: the rollback writes nothing (AS-RB1 reads it again, and writes only a key that reads on), whatever §L.7's five conditions say" ;;
        on:ok) AS_RB0_SAY="followup_auto_send set off first (AS-RB1)"
          note "AS-RB0 followup_auto_send reads on and §L.7's five conditions hold: after ROLLBACK is typed, before the put-back, the rollback sets it off — '0' in <data>/kyc_config.json through the plugin's own writer, the five conditions recomputed in the same process just before; every other key of the file and of the store row kept (AS-RB1)" ;;
        *) c="$(as_causes_say "$a")"; w="CANNOT"; AS_RB0_SAY="followup_auto_send NOT set off — the rollback cannot write it (above)"
          if [ "$(kv "$a" eff)" != "on" ]; then
            c="it could not be read as the jobs read it${c:+; $c}"
            if [ "$(kv "$a" pre)" = "ok" ]; then w="MAY NOT"; AS_RB0_SAY="followup_auto_send not set off unless AS-RB1, reading it again, finds it on and the five conditions holding"; fi
          fi
          if as_cmd_ok "$a"; then
            note "THE ROLLBACK $w SET followup_auto_send OFF ITSELF ($c): $BASELINE_VERSION WILL THEN APPROVE AND SEND AUTOMATICALLY$( [ "$(kv "$a" eff)" = "on" ] || echo " WHILE IT READS ON"). TO AVOID THAT, ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — RUN YOUR OWN COMMAND BELOW, ALONE, THEN RUN THE ROLLBACK AGAIN:"
          else
            note "THE ROLLBACK $w SET followup_auto_send OFF ITSELF ($c): $BASELINE_VERSION WILL THEN APPROVE AND SEND AUTOMATICALLY WHILE IT READS ON. TO AVOID THAT, ANSWER ANYTHING BUT ROLLBACK BELOW — NOTHING HAS BEEN CHANGED — AND ACT ON WHAT FOLLOWS (NO COMMAND CAN BE PRINTED FOR THIS CAUSE), THEN RUN THE ROLLBACK AGAIN:"
          fi
          as_show "$(as_owner_text 0 "$a")" ;;
      esac ;;
    *) AS_RB0_SAY="followup_auto_send set off first only if AS-RB1, reading it again, finds it on and the five conditions holding"
      as_show "$(as_owner_text 0 "$a")" ;;
  esac
  return 0
}
''')
    p.append(cw("as_rb1 typed|v1 — AS-RB1 (root docs/75 §L.7, DL4–DL6), the one write in this script: after ROLLBACK is "
                "typed and before do_rollback (typed), and in V1's automatic rollback before its do_rollback (v1). Through "
                "the script's own owner-run PHP — as the book's owner, never as root, the installed tree as its code root — "
                "the one reader's text with the write appended: §L.7's five conditions recomputed in the same process "
                "just before it; followup_auto_send '0', never a clear (the file wins in PluginConfig::files(), so a value "
                "held in a config.json cannot show through), only when all five hold and the key reads on; the live book "
                "opened read-write by the writer itself (SqliteStore::create: its WAL pragmas, ensureIndexes(), the "
                "migration runner applying nothing). Read back: the file decodes, its key set is the set before plus "
                "followup_auto_send, it reads '0', owned by DB_OWNER with mode 0600, the key off through the installed "
                "PluginConfig::read() and FILTER_VALIDATE_BOOLEAN; every other key of the file and of the row with the hash "
                "it had just before (over serialize(), so a change of type counts), each place compared whole; both "
                "config.json files unchanged — FAIL otherwise. The row's '0' is a note. Then the LK map and V3g's keys "
                "against their readings just before, and R7 (the ledger the same) right after. AS_RB1 — \"written\", "
                "\"off\" or the cause it did not write — goes to the rollback's state file. A write that failed, or was not "
                "made while the key reads on, is a FAIL in capitals that never blocks the rollback; the owner's command "
                "follows the verdict (as_after_verdict), in V1 its stop message (as_para). No wait: a followup_run pass "
                "that began before the write is 5.18.106's, whose due() leaves out every follow-up holding an approved "
                "draft; 5.18.105's run can begin only after do_rollback, and reads its configuration then."))
    p.append(r'''as_rb1() {
  local m="$1" o w lk0="" lk1="" ai0="" ai1="" ran=""
  AS_RB1=""; AS_RB1_SAY=""
  if [ -z "$DB_OWNER" ]; then
    AS_RB1="not-written:owner-unknown"; AS_RB1_SAY="not written: the owner of plugin.sqlite3 is not known, and this script never writes as root"
    bad "AS-RB1 FOLLOWUP_AUTO_SEND WAS NOT SET OFF: THE OWNER OF plugin.sqlite3 IS NOT KNOWN (DB_OWNER), AND THIS SCRIPT NEVER WRITES AS ROOT — WHETHER IT READS ON IS NOT KNOWN HERE; the rollback goes on (it is never blocked by this)"
  else
    cih_main || true   # the run's folder, made in this shell before lk_read below, which runs in $( … ) (DS3-fix1)
    lk_key_init || true
    lk0="$(lk_read)"; ai0="$(ai_state)"
    w="$(cat <<'PHP'
''' + AS_WRITE + r'''
PHP
)"
    o="$(docker exec -u "$DB_OWNER" -e AS_ROOT="$IN_CONTAINER" "$CONTAINER" php -d display_errors=stderr -d log_errors=0 -d opcache.enable_cli=0 -r "$AS_PHP$w" write "$PDD_IN" "$DB_OWNER" "$AS_SUMS" "$AS_MIGS" 2>/dev/null </dev/null)"; ran=1
    case "$o" in
      "as=written check=ok "*) AS_RB1="written"; AS_RB1_SAY="set off first, AS-RB1"
        ok "AS-RB1 followup_auto_send set off before the put-back ($m) — '0' in <data>/kyc_config.json through the plugin's own writer (PluginConfig::saveOverrides, as the book's owner, with the installed code), §L.7's five conditions recomputed in the same process just before it; read back: the file decodes, its keys are those before plus followup_auto_send, it reads '0', owned by $DB_OWNER with mode 0600, and followup_auto_send reads off as the jobs read it; every other key of the file and of the settings row as it was (sha256 over serialize()), both config.json files unchanged" ;;
      "as=written "*) AS_RB1="written-check:$(kv "$o" check)"; AS_RB1_SAY="written, but its read-back did not hold ($(kv "$o" check))"
        bad "AS-RB1 FOLLOWUP_AUTO_SEND WAS WRITTEN, BUT ITS READ-BACK DID NOT HOLD ($(kv "$o" check)) — the rollback goes on (it is never blocked by this); AS-RB2 reads it again under $BASELINE_VERSION" ;;
      "as=nowrite "*" eff=off "*) AS_RB1="off"; AS_RB1_SAY="already off, AS-RB1 wrote nothing"
        note "AS-RB1 followup_auto_send already reads off — nothing was written ($(as_places_say "$o"))" ;;
      "as=nowrite "*" eff=on "*) AS_RB1="not-written:$(kv "$o" pre)"; AS_RB1_SAY="not written: $(as_causes_say "$o")"
        bad "AS-RB1 FOLLOWUP_AUTO_SEND READS ON AND WAS NOT SET OFF: §L.7'S FIVE CONDITIONS DO NOT HOLD ($(as_causes_say "$o")) — $BASELINE_VERSION WILL APPROVE AND SEND AUTOMATICALLY UNTIL IT IS OFF; the rollback goes on (it is never blocked by this) — your own command, where the cause allows one, is printed $( [ "$m" = "v1" ] && echo "in the stop message below" || echo "after the verdict")" ;;
      "as=nowrite "*) AS_RB1="not-written:unread"; AS_RB1_SAY="not written: followup_auto_send could not be read as the jobs read it ($(kv "$o" eff))"
        bad "AS-RB1 FOLLOWUP_AUTO_SEND WAS NOT SET OFF: IT COULD NOT BE READ AS THE JOBS READ IT ($(kv "$o" eff)) — WHETHER $BASELINE_VERSION WILL APPROVE AND SEND AUTOMATICALLY IS NOT KNOWN; the rollback goes on (it is never blocked by this)" ;;
      "as=refused"*) AS_RB1="not-written:refused"; AS_RB1_SAY="not written: the plugin's own writer refused the save"
        bad "AS-RB1 FOLLOWUP_AUTO_SEND WAS NOT SET OFF: THE PLUGIN'S OWN WRITER REFUSED THE SAVE — $BASELINE_VERSION WILL APPROVE AND SEND AUTOMATICALLY WHILE IT READS ON; the rollback goes on (it is never blocked by this)" ;;
      *) AS_RB1="unknown:no-answer"; AS_RB1_SAY="not known whether written: the write's answer could not be read"
        bad "AS-RB1 WHETHER FOLLOWUP_AUTO_SEND WAS SET OFF IS NOT KNOWN: THE WRITE'S ANSWER COULD NOT BE READ ($(printf '%s' "${o:-no answer}" | tr -c 'A-Za-z0-9_=:.,?-' '_' | cut -c1-80)) — AS-RB2 reads it under $BASELINE_VERSION; the rollback goes on (it is never blocked by this)" ;;
    esac
    case "$o" in
      "as=written "*)
        if [ "$(kv "$o" row0)" = "yes" ]; then note "AS-RB1 the settings row reads '0' too (the writer mirrors the file into it, best effort; no reader of followup_auto_send reads the row)"
        else note "AS-RB1 the settings row does not read '0' — the writer's mirror is best effort, and no reader of followup_auto_send reads the row: the file decides"; fi
        lk1="$(lk_read)"; ai1="$(ai_state)"
        if lk_ok "$lk0" && lk_ok "$lk1"; then
          if [ "$(lk_map "$lk0")" = "$(lk_map "$lk1")" ]; then ok "AS-RB1 the ladder's keys, in every place, read as just before the write (the LK map)"
          else bad "AS-RB1 a ladder key changed across the write: $(lk_diff "$(lk_map "$lk0")" "$(lk_map "$lk1")")"; fi
        else note "AS-RB1 the ladder's keys could not be read on both sides of the write (${lk0%% map=*} / ${lk1%% map=*}) — the reader's own check above compared every key of the file and of the row"; fi
        case "$ai0|$ai1" in
          files=*"|files="*) if [ "$ai0" = "$ai1" ]; then ok "AS-RB1 V3g's keys (every ai_, evo_, openai_ and claude_ key and the registry's switch, files/store) read as just before the write"
                             else bad "AS-RB1 V3g's keys changed across the write: $(ai_changed "$ai0" "$ai1")"; fi ;;
          *) note "AS-RB1 V3g's keys could not be read on both sides of the write (${ai0%%|*} / ${ai1%%|*})" ;;
        esac ;;
    esac
  fi
  [ -z "$ran" ] || db_judge R7 "${DBS_A:-}" "$(db_state)" "right after AS-RB1, before the put-back" strict
  printf 'AS_RB1=%s\n' "$AS_RB1" >> "${STATE_W:-$STATE}" 2>/dev/null || note "AS-RB1 could not be recorded in ${STATE_W:-$STATE}"
  return 0
}
''')
    p.append(cw("rb106 — RB106, after the put-back (root docs/75 §L.7; read-only): master as 7835906 has it, the four jobs "
                "last (the carried RB reads every other file — 5.18.106's changed ones by Git's sha and by "
                "PREV_CHANGED_SHA256S, PREV_KEPT by its own — and the event processor's signatures); LK-RB1 — every ladder "
                "key in every place equal to LK-RB0's (V1's automatic rollback, which takes no LK-RB0, compares with the "
                "deploy's LK-B1), the installed tool's show equal to LK-RB0's, and LK-CA2's \"never fell\" against LK-RB0's "
                "rows; the open quote follow-ups and the running offers counted, a note (7835906's own, which carry on); "
                "LM-RB1, 5.18.105's masked lines still in place (inverted since 5.18.106); AS-RB2, followup_auto_send off "
                "through 7835906's code — a FAIL unless AS-RB1 recorded why it did not write; then Part V's, Part O's and "
                "Part P's notes. Reports; never stops."))
    p.append(cw("RB_NOTHING — set only by --rollback when the container already served 7835906 as the run began (nothing "
                "to roll back: a completed rollback checked again, or a by-hand put-back checked). That run types no "
                "ROLLBACK and puts nothing back, so it takes no LK-RB0 (LK-RB0 is read before ROLLBACK is typed) and LK-RB1 "
                "— judged across the put-back, against LK-RB0 — has no before to compare with. A reading taken by this run "
                "itself, moments before the \"after\" one, would bracket no rollback, and an ok over it would claim a kept "
                "switch nobody observed. So rb106 says plainly that the three comparisons (the keys, the tool's show, the "
                "rows) are not judged, with the ladder as it reads now for the record; master, the counts and LM-RB1 are "
                "absolute and run as on every path. Nothing is written (AS-RB1 runs only after ROLLBACK is typed, and in "
                "V1): AS-RB2 reads followup_auto_send and, when it reads on, says so in capitals with the owner's own "
                "command — a note."))
    p.append('RB_NOTHING=""\nrb106() {\n' + rb_body)
    p.append(r'''  lm_rb1        # LM-RB1 — 5.18.105's masked lines still in place under 7835906, counted as blocks (root docs/75 §L.6)
  as_rb2        # AS-RB2 — followup_auto_send off through 7835906's code (root docs/75 §L.7)
  vn_rb_after   # VN-RB5 — a note: 090's tables stay and continue under 7835906
  of_rb_after   # OF-RB2 — a note: the four offer tables stay and continue under 7835906 as its switches say
  ac_rb_after   # AC-RB2 — a note: P7's two collections stay and continue under 7835906 as its switch says
}
''')
    p.append(cw("as_rb2 — AS-RB2 (root docs/75 §L.7), after the put-back: followup_auto_send effectively off through "
                "7835906's code, installed (the one reader: PluginConfig::read and FILTER_VALIDATE_BOOLEAN). A FAIL unless "
                "AS-RB1 recorded why it did not write (its own FAIL then stands, and the owner's command follows the "
                "verdict, or ends V1's stop message) — whether the key then reads on or could not be read (review RB-4). "
                "Under RB_NOTHING nothing was written: with the key on, a notice in capitals with the owner's own command "
                "— a note. AS_RB2_EFF keeps what it read — on, off, or unread when it could not be read — for the "
                "summary's \"what it means\" (partH; review L8-3)."))
    p.append(r'''as_rb2() {
  local o; o="$(as_read)"
  AS_RB2_EFF="$(kv "$o" eff)"; [ -n "$AS_RB2_EFF" ] || AS_RB2_EFF="unread"
  case "$(kv "$o" eff)" in
    off) ok "AS-RB2 followup_auto_send reads off through $BASELINE_VERSION's code ($BASELINE_COMMIT, installed), as FollowUpPolicy::mayAutoSend reads it — a person approves each draft ($(as_places_say "$o"))$( [ "${AS_RB1:-}" = "written" ] && echo "; AS-RB1 set it before the put-back")" ;;
    on)
      if [ -n "$RB_NOTHING" ]; then
        note "AS-RB2 FOLLOWUP_AUTO_SEND READS ON UNDER $BASELINE_VERSION, WHICH APPROVES AND SENDS AUTOMATICALLY WHILE IT DOES ($(as_places_say "$o")) — this run rolled nothing back, so it wrote nothing (AS-RB1 runs only in a rollback that puts $BASELINE_VERSION back); your own command, as the one reader allows it:"
        as_show "$(as_owner_text 0 "$o")"
      else
        case "${AS_RB1:-}" in
          not-written:*) note "AS-RB2 followup_auto_send reads on under $BASELINE_VERSION — AS-RB1 did not write it (${AS_RB1_SAY:-?}; its FAIL above): your own command, where its cause allows one, is printed $( [ "$MODE" = "rollback" ] && echo "after the verdict" || echo "in the stop message below")" ;;
          *) bad "AS-RB2 FOLLOWUP_AUTO_SEND READS ON UNDER $BASELINE_VERSION AFTER THE ROLLBACK (AS-RB1: ${AS_RB1_SAY:-not run}) — IT APPROVES AND SENDS AUTOMATICALLY WHILE IT DOES ($(as_places_say "$o"))" ;;
        esac
      fi ;;
    *) if [ -n "$RB_NOTHING" ]; then note "AS-RB2 followup_auto_send could not be read through $BASELINE_VERSION's code (${o#as=}) — this run rolled nothing back and wrote nothing"
       else case "${AS_RB1:-}" in
         not-written:*) note "AS-RB2 followup_auto_send could not be read through $BASELINE_VERSION's code after the rollback (${o#as=}) — AS-RB1 did not write it (${AS_RB1_SAY:-?}; its FAIL above stands)" ;;
         *) bad "AS-RB2 followup_auto_send could not be read through $BASELINE_VERSION's code after the rollback (${o#as=}) — whether it reads off is not shown" ;;
       esac; fi ;;
  esac
}

''')
    return "".join(p)


OWNER_CMD = (cw("5.18.106 (root docs/75 §L.7): the owner's own command for followup_auto_send — built here, at print time, by "
                "every site that prints it (as_owner_text), after DB_OWNER is read just above, never inside an assignment "
                "made before it; never run by this script. set_config as the book's owner on the live data folder, its "
                "output cut where its settings listing begins (it prints every setting it manages after saving, the "
                "company phone among them, and nothing before that listing carries a setting's value — so docker exec's "
                "errors, PHP's and the result line all reach the log), with its own log. as_owner_cmd 0|1 sets it.")
             + "as_owner_cmd() {\n"
             + "  local v=\"${1:-0}\"\n"
             + "  case \"$v\" in 0|1) ;; *) v=0 ;; esac\n"
             + "  OWNER_AUTOSEND_CMD=\"docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER php $IN_CONTAINER/tools/set_config.php --key followup_auto_send --value $v 2>&1 | sed '/^  AI SETTINGS\\$/,\\$d' $(LOGW \"autosend-$v\")\"\n"
             + "}\n")
U5_CALL = "  as_u5   # U5 — 5.18.106 (root docs/75 §L.4): whether a rollback could write followup_auto_send, §L.7's one reader on the live files; a note\n"
VERDICT_CALL = "as_after_verdict   # 5.18.106 (root docs/75 §L.7, DL6): after a rollback whose AS-RB1 did not set followup_auto_send off, the owner's own command, alone\n"
TYPED_COMMENT = cw("5.18.106 (root docs/75 §L.7, DL4–DL6): AS-RB1 first — followup_auto_send '0', the one write in this script, "
                   "when it reads on and §L.7's five conditions hold, recomputed in its own process; never a reason to stop — "
                   "then do_rollback: no switch tool, no other key, no by-hand put-back of rows or keys.", "    # ")


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    d106 = set(ctx["added"]) | set(ctx["changed"])
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()

    # ── Git: what the write and the owner's command rely on — each file the same at 7835906 and the pin, outside the delta ──
    for f in SIX + ALSO:
        if f in d106 or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"PART R: {f} is in 5.18.106's delta, or differs between 7835906 and the pin")
    if sorted(ctx["runtime_changed"]) != sorted(RUNTIME):
        raise SystemExit(f"PART R: 5.18.106's runtime files are {ctx['runtime_changed']}, not the four jobs and FollowUpService")
    pc = show(base, "lib/PluginConfig.php")
    so = pc[pc.index("    public static function saveOverrides(string $dataDir, array $changes): array\n"):pc.index("    private static function mirrorToStore(")]
    for s in ("        $path     = $dataDir . '/kyc_config.json';\n        $existing = self::readExistingForSave($path);\n",
              "            if ($v === null || $v === '') { unset($existing[$k]); $cleared[] = $k; continue; }\n            $existing[$k] = $v;\n",
              "        [$ok, $err] = self::writeKycFile($path, $existing);\n",
              "        self::mirrorToStore($dataDir, $existing, $cleared);\n        return [true, null];\n"):
        need(so, s, "7835906's PluginConfig::saveOverrides")
    for s in ("        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);\n",
              "        @chmod($tmp, 0600);\n",
              "        $d = json_decode((string)@file_get_contents($path), true);\n        return is_array($d) ? $d : [];\n",
              "        if (!is_file(rtrim($dataDir, '/') . '/plugin.sqlite3')) return;\n",
              "            $store = SqliteStore::create($dataDir);\n            $cur   = $store->load('kyc_config.json');\n",
              "            $next = array_merge($cur, $overrides);\n",
              "        foreach ([$pluginRoot . '/data/config.json', $dataDir . '/config.json',\n                  $dataDir . '/kyc_config.json'] as $path) {\n",
              "    public static function read(string $pluginRoot, string $dataDir): array\n",
              "        return self::normalise(ConfigVault::fill($pluginRoot, $dataDir, self::files($pluginRoot, $dataDir)));\n"):
        need(pc, s, "7835906's PluginConfig")
    for c in ("SECRET_KEYS", "BOOL_KEYS"):
        m = re.search(rf"const {c} = \[(.*?)\];", pc, re.S)
        if not m or "'followup_auto_send'" in m.group(1):
            raise SystemExit(f"PART R: PluginConfig::{c} not found, or it names followup_auto_send (saveOverrides would refuse it, or read() recast it)")
    sq = show(base, "lib/SqliteStore.php")
    for s in ("        'kyc_config', 'email_settings', 'backup_settings',\n", "    private const FLAT_ID = 0;\n",
              "        $migrationsDir = dirname(__DIR__) . '/migrations';\n", "        $seedDir = $pluginRoot . '/seed';\n",
              "            $store->ensureIndexes();\n", "            $runner->run(); // Safe: skips already-applied, logs errors, never throws\n"):
        need(sq, s, "7835906's SqliteStore")
    sv = sq[sq.index("    public function save(string $file, array $data): void\n"):sq.index("    public function append(")]
    need(sv, "                    ':data' => json_encode($data, JSON_UNESCAPED_UNICODE),\n", "SqliteStore::save's flat encoding", 2)
    need(show(base, "lib/MigrationRunner.php"), "        $files = glob($this->migrationsDir . '/*.sql');\n", "MigrationRunner's glob")
    vk = re.search(r"const VAULT_KEYS = \[(.*?)\];", show(base, "lib/ConfigVault.php"), re.S)
    if not vk or "'followup_auto_send'" in vk.group(1):
        raise SystemExit("PART R: ConfigVault::VAULT_KEYS not found, or it names followup_auto_send")
    need(show(base, "lib/FollowUpPolicy.php"), "        if (!filter_var($config['followup_auto_send'] ?? false, FILTER_VALIDATE_BOOLEAN)) {\n",
         "FollowUpPolicy::mayAutoSend's reading")
    lad = show(base, "lib/SalesFollowupLadder.php")
    if lad.count("'followup_auto_send'") != 1 or "'timezone', 'followup_enabled', 'followup_auto_send', 'followup_daily_cap', 'followup_not_before',\n" not in lad:
        raise SystemExit("PART R: followup_auto_send is named by the ladder other than in PREEXISTING — the LK map would hold it")
    sc = show(base, "tools/set_config.php")
    for s in ("    'followup_auto_send' => ['bool',\n", "$dataDir = cliDataDir($root);\n",
              "    return ($i !== false && isset($args[$i + 1])) ? (string)$args[$i + 1] : '';\n",
              "list($ok, $err) = PluginConfig::saveOverrides($dataDir, [$key => $new]);\n",
              'else                 echo "\\n  " . $key . " = " . $new . "\\n";\n$show();\nexit(0);\n',
              '$show = function () use ($root, $dataDir, $FLAGS) {\n    $cfg = PluginConfig::load($root, $dataDir);\n    echo "\\n  AI SETTINGS\\n\\n";\n'):
        need(sc, s, "7835906's tools/set_config.php")
    i, j = sc.index("$warn = [];\n"), sc.index("list($ok, $err) = PluginConfig::saveOverrides($dataDir, [$key => $new]);\n")
    if "followup_auto_send" in sc[i:j]:
        raise SystemExit("PART R: set_config warns or refuses on followup_auto_send before its save — the printed lines would differ")
    need(show(base, "lib/bootstrap_data.php"), "    $explicit = (string)getenv('DN_DATA_DIR');\n", "cliDataDir's DN_DATA_DIR")
    sums = "\n".join(f"{sha_at(repo, base, plugin + '/' + f)} {f}" for f in SIX)
    names = lambda c: sorted(x.split("/")[-1] for x in git(repo, "ls-tree", "--name-only", c, "--", f"{plugin}/migrations/").decode().split("\n") if x)
    mb, mp = names(base), names(pin)
    if mb != mp or len(mb) < 90:
        raise SystemExit(f"PART R: 7835906's migrations/ ({len(mb)}) is not the pin's ({len(mp)})")
    migs = f"{len(mb)}:{hashlib.sha256(chr(10).join(mb).encode()).hexdigest()}"
    for c in (base, pin):
        if git(repo, "ls-tree", "--name-only", c, "--", f"{plugin}/seed").decode().strip():
            raise SystemExit(f"PART R: {c[:7]} tracks a seed/ folder")

    # ── the source: what partR stands on ──
    for s in ("as_read", "as_rb1", "as_rb2", "as_owner", "as_para", "as_u5", "AS_PHP", "AS_RB1", "OWNER_AUTOSEND_CMD", "rb_pre106", "rb106"):
        doc.mustnot(s, why="partR's names are new")
    for s in ("cs_found() {\n", "cs_has() {", "lk_read() {\n", "ai_state() {\n", "ai_changed() {", "db_state() {", "db_judge() {",
              "lm_rb1() {\n", "lk_key_init() {\n", "LOGW() {", 'QF_WRAPPER="scripts/sales-followup.sh"', "  uj_stage \"$sub\"",
              "  uj_fe_g1   # FE-G1"):
        need(doc.t, s, "the source partR reads")
    need(doc.t, "# ── SLOT U5 ── ", "partUJ's slot U5")

    # ── R1–R4: 5.18.105's rollback block goes; 5.18.106's takes its place ──
    start = "# ── 5.18.105: the rollback keeps every switch (root docs/74 §H.5) ─"
    end = "\n# ── 5.18.104: Part V's checks — the voice notes (VN) and the acknowledgement (VA)"
    a = doc.t.index(start)
    b = doc.t.index(end, a)
    old = doc.t[a:b + 1]
    lk_rb0 = old[old.index("  cih_main || note \"LK-RB0 the run's folder"):old.index("  case \",$(lk_map \"${LK_RB0:-}\"),\" in *\",offers_live_enabled|\"*)\n")]
    for o, n in (("— the rollback keeps every one\"", "— the rollback keeps every one (its one write, followup_auto_send, is not a ladder key: AS-RB1)\""),
                 ("(RB105 judges \\\"never fell\\\" against them)", "(RB106 judges \\\"never fell\\\" against them)")):
        need(lk_rb0, o, "LK-RB0's carried lines")
        lk_rb0 = lk_rb0.replace(o, n)
    rb_body = old[old.index("  local o v sub=\"\" ref\n"):old.index("  lm_rb1        # LM-RB1 — the old lines back, counted as blocks (root docs/74 §H.4.5)\n")]
    for o, n in (("    ok \"RB105 the installed $MASTER", "    ok \"RB106 the installed $MASTER"),
                 ("  else bad \"RB105 the installed $MASTER", "  else bad \"RB106 the installed $MASTER"),
                 ("— the rollback kept every switch: $(lk_say \"$o\")\"", "— the rollback kept every ladder switch (its one write, followup_auto_send, is not a ladder key: AS-RB1): $(lk_say \"$o\")\""),
                 ("— the rollback writes none; was scripts/sales-followup.sh run while it ran?\"", "— the rollback writes none of them (its one write, followup_auto_send, is not a ladder key); was scripts/sales-followup.sh run while it ran?\""),
                 ("    open_quote=*) note \"RB105 open quote follow-ups", "    open_quote=*) note \"RB106 open quote follow-ups"),
                 ("as their switches say (root docs/74 §H.5.4)\" ;;", "as their switches say (root docs/75 §L.7)\" ;;"),
                 ("    *) note \"RB105 the open quote follow-ups", "    *) note \"RB106 the open quote follow-ups")):
        need(rb_body, o, "rb105's carried body")
        rb_body = rb_body.replace(o, n)
    for s in ("RB105", "rb105", "994d660"):
        if s in lk_rb0 or s in rb_body:
            raise SystemExit(f"PART R: the carried lines still name {s}")
    for s in ('note "STEP 12 (OFFERS-LIVE) IS ON. $BASELINE_COMMIT WRITES PHONE NUMBERS AGAIN', "its STOPs write the number again"):
        need(old, s, "rb_pre105's notices that go")
    block = rb_block(sums, migs, lk_rb0, rb_body)
    doc.between(start, end, block + end[1:], keep_ends=False, why="the rollback block")

    # ── R5: the owner's command, right after DB_OWNER is read ──
    dbo = "DB_OWNER=\"$(docker exec \"$CONTAINER\" stat -c '%u:%g' \"$PDD_IN/plugin.sqlite3\" 2>/dev/null || true)\"\n"
    doc.insert_after(dbo, OWNER_CMD, why="as_owner_cmd after DB_OWNER")

    # ── U5 in qf_stage: partUJ's slot ──
    fill(doc, "U5", U5_CALL, why="U5")

    # ── R6: the put-back's sentence, and its seven print sites ──
    doc.lines_between("# 5.18.103 (docs/72 §G.10), carried; 5.18.104 (DS5): the put-back counts nothing, and every message that prints it says",
                      "PUTBACK_HAZARD=\"The put-back below copies 5.18.104's files and counts nothing.",
        cw("5.18.103 (docs/72 §G.10), carried; 5.18.104 (DS5): the put-back counts nothing, and every message that prints it "
           "says what that leaves, first — nothing to quote by hand: the catalogue's files are byte for byte the same in both "
           "releases, and 5.18.105 handles a web-direct application as 5.18.106 does. The command still stands alone on its own "
           "line after it. Literal releases: the sentence is the same wherever it is printed. 5.18.106 (root docs/75 §L.7): the "
           "put-back writes no setting, so it does not switch followup_auto_send off as the scripted rollback does first "
           "(AS-RB1); every site that prints it says so (as_para) with the owner's own command in its own paragraph, built at "
           "print time — after DB_OWNER is read, below — and, after a rollback that did not verify, only when AS-RB1 did not "
           "write, once.").rstrip("\n") + "\n"
        + "PUTBACK_HAZARD=\"The put-back below copies 5.18.105's files and counts nothing. It changes nothing of the uCRM catalogue (its screen, its settings and its switch stay as they are; 5.18.106 changed none of it) and nothing of the web-direct applications: 5.18.105 quotes each itself, by its stored uCRM ids, and one it refuses says why on its Orders card. Quote none of them in uCRM by hand because of this put-back. It runs no switch tool and writes no setting: every step of the sales follow-up keeps running as its switch says.\"",
        why="PUTBACK_HAZARD")
    for o, mode, n in (("Nothing was changed. $PUTBACK_HAZARD Put $BASELINE_VERSION back by hand first, with its own command, below — then run this script again, and send both logs:$PUTBACK_SHOW\"", "pb", 2),
                       ("Nothing was read and nothing was changed. $PUTBACK_HAZARD Put $BASELINE_VERSION back by hand first, with its own command, below — then run this again, and send both logs:$PUTBACK_SHOW\"", "pb", 1),
                       ("so it changes nothing here. $PUTBACK_HAZARD Put $BASELINE_VERSION back by hand, with its own command, below — then run this command again, and send both logs:$PUTBACK_SHOW\"", "pb", 1),
                       ("refuses over them. $PUTBACK_HAZARD Put $BASELINE_VERSION back by hand, with its own command, below — then send this log file and that command's log:$PUTBACK_SHOW\"", "pb", 1),
                       ("(live=$(live_commit)). $PUTBACK_HAZARD Put $BASELINE_VERSION back by hand, with its own command:$PUTBACK_SHOW\"", "rb", 1),
                       ("$QCV1_SAY. $PUTBACK_HAZARD Put $BASELINE_VERSION back by hand, with its own command — then send this log file and that command's log:$PUTBACK_SHOW\"", "rb", 1)):
        doc.sub(o, o.replace("$PUTBACK_HAZARD Put", f"$PUTBACK_HAZARD$(as_para {mode}) Put"), n, why=f"PUTBACK_HAZARD's print site ({mode})")
    doc.mustnot("$PUTBACK_HAZARD Put", why="every print site says what the put-back leaves of followup_auto_send")
    doc.must("$PUTBACK_HAZARD$(as_para pb) Put", 5)
    doc.must("$PUTBACK_HAZARD$(as_para rb) Put", 2)
    doc.sub('  echo "  one above; it counts nothing and runs no switch tool):"\n  echo "      $PUTBACK_CMD"\n',
            '  echo "  one above; it counts nothing, runs no switch tool and writes no setting — it does not switch followup_auto_send"\n'
            '  echo "  off, as the rollback above does first, AS-RB1; your own command for that follows, alone, where it is needed):"\n'
            '  echo "      $PUTBACK_CMD"\n'
            '  as_show "$(as_para sum)"   # 5.18.106 (root docs/75 §L.7): followup_auto_send before a put-back by hand — the one reader, run now; the owner\'s own command in its own paragraph\n',
            why="the summary's by-hand paragraph")

    # ── R6: the typed rollback — rb_pre106, the prompt, AS-RB1 on its own line ──
    doc.sub("    rb_pre105   # 5.18.105 (root docs/74 §H.5.3): LK-RB0 and the step-12 notice, before ROLLBACK is typed — never a refusal\n"
            "    printf '  Type ROLLBACK to put %s (%s) back over live %s — every switch of the sales follow-up kept as it is — anything else to stop: ' \"$BASELINE_VERSION\" \"$BASELINE_COMMIT\" \"$LIVE_BEFORE\"\n",
            "    rb_pre106   # 5.18.106 (root docs/75 §L.7): LK-RB0, followup_auto_send and §L.7's five conditions (AS-RB0), the claim switches — before ROLLBACK is typed, never a refusal\n"
            "    printf '  Type ROLLBACK to put %s (%s) back over live %s — %s, every other switch of the sales follow-up kept as it is — anything else to stop: ' \"$BASELINE_VERSION\" \"$BASELINE_COMMIT\" \"$LIVE_BEFORE\" \"${AS_RB0_SAY:-followup_auto_send set off first only if it reads on and the five conditions hold (AS-RB1)}\"\n",
            why="rb_pre106 and the ROLLBACK prompt")
    doc.sub("    # 5.18.105 (root docs/74 §H.5.2): no switch tool and no by-hand put-back of rows or keys — do_rollback alone.\n",
            TYPED_COMMENT + "    as_rb1 typed\n", why="AS-RB1 in the typed rollback")
    doc.sub("fi   # nothing to roll back: rb105 judges no LK-RB1 (root docs/74 §H.5.3)\n",
            "fi   # nothing to roll back: rb106 judges no LK-RB1, and nothing is written — AS-RB2 says so (root docs/75 §L.7)\n",
            why="RB_NOTHING's comment")

    # ── R6: V1 — its comment, its line, its verified stop (its not-verified stop above) ──
    v1_comment = cw("5.18.103 (docs/72 §G.10), carried; 5.18.106 (root docs/75 §L.7, DL4): the deploy's own automatic rollback — "
                    "the public address must never redirect, and putting $BASELINE_VERSION back is what ends it — AS-RB1 first "
                    "(followup_auto_send '0', the one write, when it reads on and §L.7's five conditions hold; never a reason to "
                    "stop), then do_rollback, no switch tool, every other switch kept; then QC-RB2 counted after it on a "
                    "consistent copy of the book, read-only (qcrb_count; counts in the log, ids on the sheet), a record only. It "
                    "always proceeds, whatever step is on. do_rollback's own answer decides the words: verified, RB106 reads the "
                    "put-back (against the deploy's LK-B1) and no put-back command is printed; not verified — the checkout, "
                    "deploy-hybrid.sh, or a record that did not turn — $EXPECTED_VERSION may still serve, and the stop says the "
                    "rollback did not complete and prints the by-hand put-back of the code, alone on its own line. Where AS-RB1 "
                    "did not write while followup_auto_send reads on, either stop carries the owner's own command in its own "
                    "paragraph (as_para), before the put-back's.", "      # ")
    doc.lines_between("      # 5.18.103 (docs/72 §G.10), carried; 5.18.105 (root docs/74 §H.5): the deploy's own automatic rollback — the public",
                      "      # the rollback did not complete and prints the by-hand put-back of the code, alone on its own line.",
                      v1_comment.rstrip("\n"), why="V1's comment")
    doc.sub('echo "  ROLLING BACK — the public address must never redirect (every switch kept)"; if do_rollback; then',
            V1_ECHO + "; as_rb1 v1; if do_rollback; then", why="V1's line")
    doc.sub('        if [ "$QCV1_RB" = "ok" ]; then rb105   # RB105 — the put-back read, as RB reads it after a --rollback (root docs/74 §H.5.4)\n',
            '        if [ "$QCV1_RB" = "ok" ]; then rb106   # RB106 — the put-back read, as RB reads it after a --rollback (root docs/75 §L.7)\n',
            why="V1's rb106")
    doc.sub('          stop "rolled back (serves $(live_commit)); RB105 above reads the put-back — every switch of the sales follow-up kept as it was, the lines $EXPECTED_VERSION masked writing numbers again (LM-RB1); $QCV1_SAY; on Uganda the uCRM catalogue is left exactly as it was (root docs/74 §H.5); send the log file"; fi\n',
            '          stop "rolled back (serves $(live_commit)); RB106 above reads the put-back — every switch of the sales follow-up kept as it was but followup_auto_send (${AS_RB1_SAY:-?}), 5.18.105\'s masked lines still masked under $BASELINE_VERSION (LM-RB1); $QCV1_SAY; on Uganda the uCRM catalogue is left exactly as it was (root docs/75 §L.7); send the log file$(as_para v1)"; fi\n',
            why="V1's verified stop")

    # ── R6: the stage-A note on what the rollback leaves (S105:11575) ──
    m = [l for l in doc.t.split("\n") if l.startswith("  note \"after the rollback the code is 5.18.104's again where 5.18.105 changed it: the lines 5.18.105 masked write phone numbers again (LM-RB1)")]
    if len(m) != 1 or not m[0].endswith("master keeps 5.18.92's lock, main.php 5.18.93's change and the retry job 5.18.91's gate\""):
        raise SystemExit(f"PART R: the stage-A rollback note found {len(m)} times, or not as 5.18.105 wrote it")
    doc.line("  note \"after the rollback the code is 5.18.104's again where 5.18.105 changed it:",
             "  note \"after the rollback the code is 5.18.105's again where 5.18.106 changed it — the four follow-up jobs and lib/FollowUpService.php — and 5.18.105's masked lines stay masked under it (LM-RB1); the rollback writes one key, followup_auto_send '0', before the put-back, when it reads on and §L.7's five conditions hold (AS-RB1; AS-RB0 reads them before ROLLBACK), and keeps every other switch of the sales follow-up as it is — no switch tool runs, the steps that are on keep running (LK-RB0 below, LK-RB1 after). Every close 5.18.106 made stays — nothing reopens one — and so do the drafts it set expired. On Uganda the uCRM catalogue is 5.18.103's own and stays exactly as it is — 5.18.105 and 5.18.106 keep every file of it byte for byte and the rollback touches none of it: its screen, its settings (the switch, the installation product, the products kept off, the standard quotations) and its switch stay as an admin left them, and the KYC form, Send Quotation and the lead quotes offer what they offer now. A web-direct application is handled by 5.18.105 exactly as 5.18.106 handles it — retried by its uCRM ids, Make the quotation on its Orders card where that applies — so the rollback adds none to quote in uCRM by hand (QC-RB2 counts them, for the record). Nothing is quoted at price 0: 5.18.105 prices from uCRM or refuses (docs/72 §G.8). Quotes already made in uCRM are not changed. The quotation summary's sign-off, the card's owner lists and every number, the quoting organization, the Cashbook, cash in hand and ECO Bank stay exactly as they are: $EXPECTED_VERSION changes none of them. A later deploy of $EXPECTED_VERSION goes with --redeploy. 5.18.95's security fixes stay; 5.18.94's documents too; master keeps 5.18.92's lock, main.php 5.18.93's change and the retry job 5.18.91's gate\"",
             why="the stage-A rollback note")

    # ── R6: R7's header and db_judge's ok line — the rollback's one key ──
    doc.line("# ── 5.18.95: the database, before and after (R7, RB) — 5.18.105 adds no migration; this run writes nothing ─",
             rule("5.18.95: the database, before and after (R7, RB) — 5.18.106 adds no migration; a rollback writes one key (AS-RB1)"),
             why="R7's header")
    doc.sub("— and nothing in this run writes it$( [ \"$1\" = \"RB\" ] && echo \": the rollback runs no switch tool and no off-hook, and applies or removes no migration (root docs/74 §H.5.2)\")\"\n",
            "— and this run writes nothing in it$( [ \"${AS_RB1:-}\" = \"written\" ] && echo \" but followup_auto_send's '0' in the settings row, before the put-back (AS-RB1)\")$( [ \"$1\" = \"RB\" ] && echo \": the rollback runs no switch tool and no off-hook, writes no key but followup_auto_send (AS-RB1), and applies or removes no migration (root docs/75 §L.7)\")\"\n",
            why="db_judge's ok line")

    # ── R6: the RB section's texts ──
    n_ch, n_kp, n_ad = ctx["n_changed"], ctx["n_kept"], ctx["n_added"]
    rb_texts = [
        ("# 5.18.105's change is gone (below: 5.18.104's files by sha256 where 5.18.105 changed them (46) and the 386 it keeps\n"
         "# by theirs; the 43 files it adds left on disk and reached by nothing, QC-RB1; every ladder key as before, RB105;\n",
         f"# 5.18.106's change is gone (below: 7835906's files by sha256 where 5.18.106 changed them ({n_ch}) and the {n_kp} it keeps\n"
         f"# by theirs; the {n_ad} files it adds left on disk, named by nothing 7835906 tracks, QC-RB1; every ladder key as before,\n"
         "# RB106, and followup_auto_send off, AS-RB2;\n"),
        ("(deploy-hybrid.sh never deletes), reached by nothing (QC-RB1, below)\"",
         "(deploy-hybrid.sh never deletes), named by nothing $BASELINE_COMMIT tracks (QC-RB1, below)\""),
        ("# rollback, so counted and said, never \"read by nothing\" — and the web-direct applications\n",
         "# rollback, so counted and said, never called unread — and the web-direct applications\n"),
        ("closed, as it was before the rollback, which changes no setting (V3j)\"",
         "closed, as it was before the rollback, which changes no setting but followup_auto_send (V3j; AS-RB1)\""),
        ("# rollback restores code only — 5.18.105's rollback writes nothing to the book (QF-RB1 is retired) — so 088 stays\n",
         "# rollback restores code and writes no table — 5.18.106's rollback writes one settings key, followup_auto_send\n"
         "# (AS-RB1) — so 088 stays\n"),
        ("as before the rollback ($BK_RB; files/store) — the rollback changes no setting\"",
         "as before the rollback ($BK_RB; files/store) — the rollback changes no setting but followup_auto_send (AS-RB1)\""),
        ("# RB105 — 5.18.105 (root docs/74 §H.5.4): master as 5.18.104 left it, the ladder's keys and the tool's show as before the\n"
         "# rollback (LK-RB1), no table's rows fell, the open quote follow-ups and running offers counted, the old lines back\n"
         "# (LM-RB1). Read-only. When this run rolled nothing back (RB_NOTHING), LK-RB1 is a note: not judged, nothing to compare.\n"
         "rb105\n",
         "# RB106 — 5.18.106 (root docs/75 §L.7): master as 7835906 has it, the ladder's keys and the tool's show as before the\n"
         "# rollback (LK-RB1), no table's rows fell, the open quote follow-ups and running offers counted, 5.18.105's masked\n"
         "# lines still in place (LM-RB1), followup_auto_send off through 7835906's code (AS-RB2). Read-only. When this run\n"
         "# rolled nothing back (RB_NOTHING), LK-RB1 is a note — not judged, nothing to compare — and so is AS-RB2: nothing\n"
         "# was written.\n"
         "rb106\n")]
    for o, n in rb_texts:
        doc.sub(o, n, why="the RB section's texts")
    doc.line("note \"RB the code is $BASELINE_VERSION's again where $EXPECTED_VERSION changed it, and nothing else moved:",
             "note \"RB the code is $BASELINE_VERSION's again where $EXPECTED_VERSION changed it — the four follow-up jobs and lib/FollowUpService.php — and $( case \"${AS_RB1:-}\" in written) echo \"nothing else moved but followup_auto_send: the rollback ran no switch tool and no off-hook, applied or removed no migration, and wrote one key before the put-back, followup_auto_send '0' (AS-RB1: ${AS_RB1_SAY:-?}; root docs/75 §L.7)\" ;; written-check:*) echo \"the rollback ran no switch tool and no off-hook, applied or removed no migration, and wrote followup_auto_send '0' before the put-back through the plugin's own writer — but its read-back did not hold, so whether anything else moved is not shown: AS-RB1's FAIL above names what differed (AS-RB1: ${AS_RB1_SAY:-?}; root docs/75 §L.7)\" ;; unknown:*) echo \"nothing else moved: the rollback ran no switch tool and no off-hook and applied or removed no migration; whether it wrote followup_auto_send '0' before the put-back is not known (AS-RB1: ${AS_RB1_SAY:-?}; AS-RB2 above reads it)\" ;; *) echo \"nothing else moved: the rollback ran no switch tool and no off-hook, applied or removed no migration, and wrote no key (AS-RB1: ${AS_RB1_SAY:-this run put nothing back}; root docs/75 §L.7)\" ;; esac ) — every other step of the sales follow-up keeps running as its switch says, and 5.18.105's masked log lines stay masked (LM-RB1). Every close $EXPECTED_VERSION made stays (nothing reopens one), and so do the drafts it set expired. $ADDED_LEFT of the $N_ADDED files $EXPECTED_VERSION adds (its tests) are on disk (deploy-hybrid.sh never deletes; counted above), named by nothing $BASELINE_COMMIT tracks (QC-RB1). ECO Bank, the uCRM catalogue, the channel registry and every number's owner stay as they were (RB above). A14 of any later deploy needs the quarantine's --check begun after this rollback's copy (RB_COPY_AT), and a later deploy of $EXPECTED_VERSION goes with --redeploy\"",
             why="the RB note")

    # ── R5: AS-RB1's command after the verdict of a typed rollback ──
    doc.insert_before("# 5.18.98 — after a PASSED deploy or --after-only whose G3 went to its note under --without-bank-balance (this run's, or\n",
                      VERDICT_CALL, why="AS-RB1's command after the verdict")

    # ── R1: the names, everywhere ──
    for o, n, k in RENAMES:
        c = doc.t.count(o)
        if c != k:
            raise SystemExit(f"PART R: {o} is left {c} times before the rename, not {k}")
        doc.sub(o, n, k, why=f"{o} renamed")
    for s in ("rb_pre105", "rb105", "RB105"):
        doc.mustnot(s, why="the rollback's names are 5.18.106's")

    # ── the result ──
    t = doc.t
    for s, n in (("rb_pre106() {\n", 1), ("rb106() {\n", 1), ("as_rb1() {\n", 1), ("as_rb2() {\n", 1), ("as_read() {\n", 1),
                 ("as_owner_cmd() {\n", 1), ("as_owner_text() {\n", 1), ("as_para() {\n", 1), ("as_u5() {\n", 1),
                 ("as_after_verdict() {\n", 1), ("as_back_on() {\n", 1), ("as_rb_last() {\n", 1), (U5_CALL, 1), (VERDICT_CALL, 1),
                 ("  as_rb1 typed\n", 1), (V1_ECHO + "; as_rb1 v1; if do_rollback", 1), ('RB_NOTHING=""\n', 1), ("RB_NOTHING=1;", 1),
                 ("saveOverrides($dd, ['followup_auto_send' => '0'])", 1), ('docker exec -u "$DB_OWNER" -e AS_ROOT="$IN_CONTAINER"', 1),
                 ("  cih_main || note \"LK-RB0 the run's folder", 1), ("\n    rb_pre106   # ", 1), ("then rb106   # RB106", 1), ("\nrb106\n", 1),
                 ("printf 'AS_RB1=%s\\n' \"$AS_RB1\" >> \"${STATE_W:-$STATE}\"", 1)):
        doc.must(s, n, why="partR as written")
    # the review of 18 Nov: RB-1 rb_pre106's arms, RB-2 the RB note by AS_RB1, RB-3 and L8-3 the headlines by AS_RB1 and
    # AS_RB2_EFF, RB-4 AS-RB2's could-not-read branch, L8-4 as_back_on by COPY_AT against the latest rollback's RB_COPY_AT
    for s, n in (('AS_RB0=""; AS_RB0_SAY=""; AS_RB1=""; AS_RB1_SAY=""; AS_RB2_EFF=""\n', 1),
                 ('  AS_RB2_EFF="$(kv "$o" eff)"; [ -n "$AS_RB2_EFF" ] || AS_RB2_EFF="unread"\n', 1),
                 ('         not-written:*) note "AS-RB2 followup_auto_send could not be read through $BASELINE_VERSION\'s code after '
                  'the rollback (${o#as=}) — AS-RB1 did not write it (${AS_RB1_SAY:-?}; its FAIL above stands)" ;;\n', 1),
                 ('    as=noowner) AS_RB0_SAY="followup_auto_send NOT set off — the rollback cannot write it (above)"\n', 1),
                 ("THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (noowner: ", 1),
                 ("THE ROLLBACK $w SET followup_auto_send OFF ITSELF ($c): ", 2), ('w="MAY NOT"', 1),
                 ('c="it could not be read as the jobs read it${c:+; $c}"', 1),
                 ('    *) AS_RB0_SAY="followup_auto_send set off first only if AS-RB1, reading it again, finds it on and the five '
                  'conditions holding"\n', 1),
                 ("AS-RB1 WROTE followup_auto_send '0', BUT ITS READ-BACK DID NOT HOLD (", 2),
                 ("WHETHER AS-RB1 SET followup_auto_send OFF IS NOT KNOWN (", 2), ("AS-RB1 DID NOT SET followup_auto_send OFF (", 2),
                 ("followup_auto_send reads off now, through $BASELINE_VERSION's code (AS-RB1: ", 1),
                 ("printf '%s %s' \"${v:-none}\" \"$t\"", 1), ('  [ "$t" -lt "$c" ] || return 0\n', 1),
                 ('  for f in "$STATE".prev-* "$OUT"/rollback-state-*.env; do\n', 1),
                 ('  for f in "$STATE" "$STATE".prev-* "$OUT"/rollback-state-*.env; do\n', 0), ("grep -q '^AS_RB1='", 0),
                 ("  case \"$(sed -n 's/^AS_RB1=//p' \"$STATE\" 2>/dev/null | tail -n1)\" in written|written-check:*|unknown:*) "
                  "return 0 ;; esac   # this deploy's V1 wrote it, or may have", 1),
                 ('written) echo "nothing else moved but followup_auto_send: ', 1),
                 ('written-check:*) echo "the rollback ran no switch tool and no off-hook, applied or removed no migration, '
                  'and wrote followup_auto_send \'0\' before the put-back through the plugin\'s own writer — but its '
                  'read-back did not hold, so whether anything else moved is not shown', 1),
                 ('unknown:*) echo "nothing else moved: the rollback ran no switch tool and no off-hook and applied or removed no '
                  'migration; whether it wrote followup_auto_send \'0\' before the put-back is not known', 1),
                 ('*) echo "nothing else moved: the rollback ran no switch tool and no off-hook, applied or removed no migration, '
                  'and wrote no key (AS-RB1: ${AS_RB1_SAY:-this run put nothing back}', 1)):
        doc.must(s, n, why="partR, the review of 18 Nov")
    doc.mustnot("THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF ($(as_causes_say", why="RB-1: the cause is never empty")
    bo = t[t.index("as_back_on() {\n"):]
    bo = bo[:bo.index("\n}\n")]
    if "REDEPLOY" in bo or "sed -n 's/^COPY_AT=//p' \"$STATE\"" not in bo \
            or not bo.index('[ "$t" -lt "$c" ] || return 0') < bo.index('[ "${r%% *}" = "written" ] || return 0') < bo.index('o="$(as_read)"'):
        raise SystemExit("PART R: as_back_on does not go by this deploy's COPY_AT against the latest rollback's RB_COPY_AT (L8-4)")
    asg = [l for l in t.split("\n") if re.match(r"\s*OWNER_AUTOSEND_CMD=", l)]
    if len(asg) != 1 or "--key followup_auto_send --value $v 2>&1 | sed '/^  AI SETTINGS\\$/,\\$d' $(LOGW" not in asg[0]:
        raise SystemExit("PART R: OWNER_AUTOSEND_CMD is not assigned once, as §L.7 writes it")
    if not (t.index(dbo) < t.index("as_owner_cmd() {\n") < t.index("[ -n \"$CHECK_ONLY\" ] && [ \"$LIVE_BEFORE\" = \"$EXPECTED_PLUGIN_COMMIT\" ]")):
        raise SystemExit("PART R: as_owner_cmd is not defined right after DB_OWNER is read")
    for l in t.split("\n"):
        if "$OWNER_AUTOSEND_CMD" in l and not l.lstrip().startswith("printf ' Your own command, alone, with its own log:"):
            raise SystemExit(f"PART R: OWNER_AUTOSEND_CMD expanded outside as_owner_text's one printf: {l.strip()[:100]}")
    i = t.index("  as_rb1 typed\n")
    if not (t.index("[ \"$ANSWER\" = \"ROLLBACK\" ] || stop \"not confirmed\"") < i < t.index("    if do_rollback; then ok \"container serves $BASELINE_COMMIT ($BASELINE_VERSION)\"", i)):
        raise SystemExit("PART R: AS-RB1 is not after ROLLBACK is typed and before do_rollback")
    if not (t.index("  uj_stage \"$sub\"") < t.index("  uj_fe_g1   # FE-G1") < t.index(U5_CALL) < t.index("# ── SLOT U5 ── ")) \
            or "\n" + U5_CALL + "  # ── SLOT U5 ── " not in t:
        raise SystemExit("PART R: U5 is not read in qf_stage right after the UJ reading and FE-G1, just above its slot")
    fm = re.search(r"(?ms)^as_rb1\(\) \{\n(.*?)^\}", t)
    if not fm or fm.group(1).count('docker exec -u "$DB_OWNER"') != 1 or fm.group(1).count('"$IN_CONTAINER"') != 1 \
            or fm.group(1).count("saveOverrides(") != 1 or "saveOverrides($dd, ['followup_auto_send' => '0'])" not in fm.group(1):
        raise SystemExit("PART R: as_rb1's body does not hold the one write, as -u \"$DB_OWNER\", with the installed tree, once")
    for l in t.split("\n"):
        if re.search(r"(?<![\w-])as_rb1\b(?!\(\))", l) and not l.lstrip().startswith("#") and l != "    as_rb1 typed" \
                and "; as_rb1 v1; if do_rollback" not in l:
            raise SystemExit(f"PART R: as_rb1 named in a code line other than its two calls: {l.strip()[:100]}")
    if sum(1 for l in t.split("\n") if "saveOverrides(" in l and not l.lstrip().startswith(("#", "//"))) != 1:
        raise SystemExit("PART R: a saveOverrides( in a code line other than AS-RB1's write")
    added = block + OWNER_CMD + TYPED_COMMENT + v1_comment + "".join(n for _, n in rb_texts)
    for l in added.split("\n"):
        if l.lstrip().startswith(("#", "//")) and not l.startswith("# ── ") and len(l) > 120:
            raise SystemExit(f"PART R: a comment line partR adds is longer than 120 characters: {l[:80]!r}")
    reg = t[t.index("hdr \"RB. $BASELINE_VERSION, back in place"):t.index("note \"RB the code is")]
    for s in (r"numbers( in)? again", "WRITES PHONE", "reached by nothing", "read by nothing", "writes nothing to the book"):
        for rg in (block, reg):
            if re.search(s, rg, re.I):
                raise SystemExit(f"PART R: {s!r} survives in the rollback's texts")
    ctx.update(as_sums=sums, as_migs=migs, as_slot_before="as_u5   # U5")

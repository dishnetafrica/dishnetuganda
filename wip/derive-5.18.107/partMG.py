# Part MG — migration 092 (root docs/76 §T.3), its items in order:
#   1  A0: the migrations among the delta exactly MIG107_SUMS' — 092, added, never modified, at its sha256 and md5 — and
#      092's _schema_checks statement 089's byte for byte at the pin, its INSERT next; MIG104_SUMS, 088 and 087 carried
#      (partB). The constants: MIG107_SUMS, _N, _LAST, _LAST_MD5, _STMTS (read off Git by MigrationRunner::splitStatements,
#      ported below), _SC, _OBJECTS (092's INSERT's names, from Git), and NA-G1/NA-CA1's two sentinels.
#   2  MG-G1 (stage A, on QF-G1's copy): absent from the ledger, or present whole (checksum, 5 of 5, the five objects as
#      the pin's 092 makes them on :memory:); anything else NO-GO, nothing changed.
#   3  QF-G2's one trial extended to 092: applied ok with its 7 statements logged OK where MG-G1 read absent, none where it
#      read present; the copy's 092 then whole either way. QF_SC_EXPECT and the PHP $SC stay at 089–091.
#   4  neg092 on a fourth copy of its own (qf_copy g2x): the pin's files below 092 run into it, a redeploy's 092 taken off
#      (reset=1), a wa_no_ai of another shape planted, 092 alone through the pin's runner — partial, a PARTIAL line for 092
#      itself naming CHECK constraint failed, no 092 row; the pairing rule (reset=0 only with MG-G1 absent, reset=1 only
#      with it present). Part V's control carried.
#   5  NA-G1 (stage A, on QF-G2's trial copy, through the pin's code) and NA-CA1 (after the copy, through the installed
#      code): QF_PHP's new na mode — probe 1 read-only, probe 2 the positive control and probe 3 the negative one on a
#      throwaway copy in the run's /tmp (its first statement refuses any other path); StaffJobsGate::applies beside it.
#   6  MG-CA1 (a 60-s bounded wait on the live ledger, read-only, then a fresh copy), MG-CA2 (migration.log: OK and
#      PARTIAL judged, the checksum WARNING a note or not judged, a missing OK line keyed on MG_G1 and MG_CA2_SEEN),
#      the state lines MG_G1 and QF_COPY_UNIX (the DEPLOY step) and MG_CA2_SEEN (stage R of the deploy run).
#   7  The FAILED summary's lead (partH words it): MG_SAY, which of NA-CA1, MG-CA1 or MG-CA2 failed.
#   8  R7 by db_mig107: applied once, for 092 (stage R first deploy), same (redeploy), either (--after-only); RB same, or
#      applied only where 092 was absent at the rollback's stage A.
#   9  LK-CA2's and LK-RB1's _schema_checks rows: exact, or after = before plus exactly 092_wa_no_ai.sql:5/5 where MG-G1
#      read absent; wa_no_ai's rows among the tables that never fall.
import re, hashlib
from d107lib import git, sha_at, md5_at

MIG = "migrations/092_wa_no_ai.sql"
SENTINELS = ("256000000001", "256000000002")   # §T.3 item 5: the country code then 0, 12 digits, within 092's CHECKs


def split_statements(sql):
    """lib/MigrationRunner.php's splitStatements, ported (stripComments first): the statements as the runner runs them."""
    out, n, i, ins = [], len(sql), 0, False
    s = []
    while i < n:                               # stripComments
        c = sql[i]
        if ins:
            s.append(c)
            if c == "'":
                if i + 1 < n and sql[i + 1] == "'":
                    s.append(sql[i + 1]); i += 2; continue
                ins = False
            i += 1; continue
        if c == "'":
            ins = True; s.append(c); i += 1; continue
        if c == "-" and i + 1 < n and sql[i + 1] == "-":
            while i < n and sql[i] != "\n":
                i += 1
            s.append("\n"); i += 1; continue
        s.append(c); i += 1
    sql = "".join(s)
    n, buf, ins, depth, i = len(sql), "", False, 0, 0
    while i < n:
        c = sql[i]
        if ins:
            buf += c
            if c == "'":
                if i + 1 < n and sql[i + 1] == "'":
                    buf += sql[i + 1]; i += 2; continue
                ins = False
            i += 1; continue
        if c == "'":
            ins = True; buf += c; i += 1; continue
        if c in "bB" and re.match(r"(?i)^begin\b", sql[i:i + 6]) and re.search(r"(?i)\bcreate\s+trigger\b", buf):
            depth += 1
        elif c in "eE" and depth > 0 and re.match(r"(?i)^end\b", sql[i:i + 4]):
            depth -= 1
        if c == ";" and depth == 0:
            t = buf.strip()
            if t:
                out.append(t)
            buf = ""; i += 1; continue
        buf += c; i += 1
    t = buf.strip()
    if t:
        out.append(t)
    return out


def sc_block(text):
    """A0's reader, in Python: from 'CREATE TABLE IF NOT EXISTS _schema_checks (' to the first line ending ');', and the
    first non-empty line after it."""
    ls = text.split("\n")
    for k, l in enumerate(ls):
        if l.startswith("CREATE TABLE IF NOT EXISTS _schema_checks ("):
            for j in range(k, len(ls)):
                if ls[j].endswith(");"):
                    nxt = next((x for x in ls[j + 1:] if x.strip()), "")
                    return "\n".join(ls[k:j + 1]), nxt
    return None, None


# ── the PHP modes (QF_PHP), inserted before 'notes' ──
PHP_MODES = r"""    case 'mgledger': // mgledger DB — 5.18.107 (root docs/76 §T.3 item 6): MG-CA1's bounded wait — 092's row in the LIVE book's
                     // migration ledger, read-only, the ledger only (as OF-M1's 'ledger' reads 091's); 1 or 0
        $p = $ro($a[0]);
        echo 'mig092=', (int)$one($p, "SELECT COUNT(*) FROM _migrations WHERE filename = '092_wa_no_ai.sql'");
        break;

    case 'mg':     // mg ROOT DB — 5.18.107 (root docs/76 §T.3 items 2, 3, 6): migration 092 on DB, a copy, read-only (MG-G1 on
                   // QF-G1's copy, QF-G2's trial copy after its trial, MG-CA1's fresh copy after the copy): its ledger rows and
                   // checksum, its applied_at (UTC, as SQLite's datetime('now') wrote it), its _schema_checks row, and its five
                   // objects against what ROOT's own 092 makes through ROOT's MigrationRunner::splitStatements on an empty
                   // database in memory, by type, name, table and SQL text (as $want090 reads 090). Counts and codes only.
        [$root, $db] = [(string)($a[0] ?? ''), (string)($a[1] ?? '')];
        $p = $ro($db);
        $f = '092_wa_no_ai.sql';
        $names = ['wa_no_ai', 'idx_wna_active', 'wna_never_deleted', 'wna_identity_fixed', 'wna_lift_once'];
        $n = (int)$one($p, 'SELECT COUNT(*) FROM _migrations WHERE filename = ?', [$f]);
        $ck = $n > 0 ? substr((string)$one($p, 'SELECT checksum FROM _migrations WHERE filename = ? ORDER BY id DESC LIMIT 1', [$f]), 0, 16) : '-';
        $at = $n > 0 ? (string)$one($p, 'SELECT applied_at FROM _migrations WHERE filename = ? ORDER BY id DESC LIMIT 1', [$f]) : '';
        $r = $has($p, '_schema_checks') ? $p->query('SELECT expected, found FROM _schema_checks WHERE migration = ' . $p->quote($f))->fetch(PDO::FETCH_NUM) : false;
        $in = "('" . implode("','", $names) . "')";
        $got = [];
        foreach ($p->query('SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name IN ' . $in)->fetchAll(PDO::FETCH_ASSOC) as $o) $got[(string)$o['type'] . ':' . (string)$o['name']] = $o;
        foreach (['StoreInterface', 'MigrationRunner'] as $l) require_once $root . '/lib/' . $l . '.php';
        $sql = @file_get_contents(rtrim($root, '/') . '/migrations/' . $f);
        if (!is_string($sql) || $sql === '') { echo '?nofile'; exit(3); }
        $m = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (MigrationRunner::splitStatements($sql) as $st) $m->exec($st);
        $want = [];
        foreach ($m->query('SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name IN ' . $in)->fetchAll(PDO::FETCH_ASSOC) as $o) $want[(string)$o['type'] . ':' . (string)$o['name']] = $o;
        $objs = $got === [] ? 'none' : ((count($want) === 5 && $got == $want) ? 'same' : 'differs');
        echo 'mig092=', $n, ' cks=', $ck, ' sc=', $r ? (int)$r[1] . '/' . (int)$r[0] : 'missing', ' objects=', count($got), '/5',
             ' objsql=', $objs, ' table=', $has($p, 'wa_no_ai') ? 1 : 0, ' at=', $at === '' ? '-' : str_replace(' ', 'T', $at);
        break;

    case 'na':     // na ROOT DIR S1 S2 — 5.18.107 (root docs/76 §T.3 item 5): wa_no_ai readable the way the worker reads it,
                   // through ROOT's own NoAiList (listedId, as AiReplyWorker asks it), with a positive and a negative control.
                   // DIR must be a reading's folder of this run's in the container's /tmp — the first statement refuses any
                   // other path. Probe 1 reads DIR's copy read-only (ERRMODE_EXCEPTION: a list that cannot be read throws);
                   // probes 2 and 3 write only a throwaway copy of it inside DIR, removed again. S1 and S2 are numbers nobody
                   // can hold (NA_SENTINEL_1, _2). Counts, codes and an exception's class only; never a phone.
        [$root, $dir, $s1, $s2] = [(string)($a[0] ?? ''), rtrim((string)($a[1] ?? ''), '/'), (string)($a[2] ?? ''), (string)($a[3] ?? '')];
        if (!preg_match('#^/tmp/dnb-[0-9.]+-cih-[0-9]{8}T[0-9]{6}Z-[0-9]+/qf-[a-z0-9]+-[0-9]+$#', $dir) || realpath($dir) !== $dir || !is_file($dir . '/plugin.sqlite3')) { echo '?notmp'; exit(2); }
        foreach (['bootstrap_data', 'timezone', 'UtcClock', 'PluginConfig', 'TenantProfile', 'StaffJobsGate', 'NoAiList'] as $l) require_once $root . '/lib/' . $l . '.php';
        $o = [];
        $p = $ro($dir . '/plugin.sqlite3');
        $L = new NoAiList($p, [], $dir);
        try { $o[] = 'p1=' . ($L->listedId($s1) === null ? 'none' : 'listed'); } catch (Throwable $x) { $o[] = 'p1=?' . get_class($x); }
        try { $o[] = 'any=' . ($L->anyActive() ? 1 : 0); $o[] = 'active=' . count($L->activePhones()); } catch (Throwable $x) { $o[] = 'any=?' . get_class($x); }
        try { $o[] = 'scan=' . (int)$one($p, 'SELECT COUNT(*) FROM (SELECT ? AS phone) c WHERE NOT EXISTS (SELECT 1 FROM wa_no_ai n WHERE n.active = 1 AND n.phone = c.phone)', [$s1]); } catch (Throwable $x) { $o[] = 'scan=?' . get_class($x); }
        $p = null;
        $w = $dir . '/na-throwaway.sqlite3';
        if (!@copy($dir . '/plugin.sqlite3', $w)) { $o[] = 'p2=?copy p3=?copy'; }
        else {
            $q = $rw($w); $L2 = new NoAiList($q, [], $dir);
            try {
                $q->prepare("INSERT INTO wa_no_ai (phone, reason, added_by) VALUES (?, NULL, 'deploy-check')")->execute([$s1]);
                $id = (int)$q->lastInsertId();
                $o[] = 'p2=' . (($id > 0 && $L2->listedId($s1) === $id) ? 'listed' : 'notlisted') . ',' . ($L2->listedId($s2) === null ? 'none' : 'listed');
            } catch (Throwable $x) { $o[] = 'p2=?' . get_class($x); }
            try {
                $q->exec('ALTER TABLE wa_no_ai RENAME TO wa_no_ai_renamed');
                try { $L2->listedId($s1); $o[] = 'p3=nothrow'; } catch (Throwable $x) { $o[] = 'p3=' . get_class($x); }
            } catch (Throwable $x) { $o[] = 'p3=?' . get_class($x); }
            $q = null; $L2 = null;
            foreach (['', '-wal', '-shm', '-journal'] as $sfx) @unlink($w . $sfx);
        }
        try { $o[] = 'gate=' . (StaffJobsGate::applies(PluginConfig::read($root, $dir), $dir) ? 'true' : 'false'); } catch (Throwable $x) { $o[] = 'gate=?' . get_class($x); }
        echo implode(' ', $o);
        break;

    case 'neg092': // neg092 ROOT DIR — 5.18.107 (root docs/76 §T.3 item 4): 092's negative control on a copy of its own (never
                   // Part V's g2n): the pin's files below 092 run into it (on a live 091 book, nothing); a redeploy's 092 taken
                   // off this throwaway copy first (DROP TABLE wa_no_ai, which removes its index and triggers, and 092's
                   // _schema_checks and _migrations rows: reset=1); a wa_no_ai of another shape planted (no active column);
                   // then 092 alone through the pin's runner — it must read partial, a PARTIAL line for 092 itself naming
                   // CHECK constraint failed (the match tied to 092's own line), and no 092 row
        [$root, $dir] = $a;
        foreach (['StoreInterface', 'MigrationRunner'] as $l) require_once $root . '/lib/' . $l . '.php';
        $p = $rw($dir . '/plugin.sqlite3');
        @mkdir($dir . '/n1', 0700); @mkdir($dir . '/n2', 0700);
        foreach (glob($root . '/migrations/*.sql') ?: [] as $f) if (strcmp(basename($f), '092') < 0) copy($f, $dir . '/n1/' . basename($f));
        copy($root . '/migrations/092_wa_no_ai.sql', $dir . '/n2/092_wa_no_ai.sql');
        (new MigrationRunner($p, $dir . '/n1', $dir . '/n1.log'))->run();
        $reset = 0;
        if ($has($p, 'wa_no_ai')) {
            $p->exec('DROP TABLE wa_no_ai');
            if ($has($p, '_schema_checks')) $p->exec("DELETE FROM _schema_checks WHERE migration = '092_wa_no_ai.sql'");
            $p->exec("DELETE FROM _migrations WHERE filename = '092_wa_no_ai.sql'");
            $reset = 1;
        }
        $p->exec('CREATE TABLE wa_no_ai (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, note TEXT)');
        $res = (new MigrationRunner($p, $dir . '/n2', $dir . '/n2.log'))->run();
        $log = (string)@file_get_contents($dir . '/n2.log');
        $pl = 0; $pc = 0;
        foreach (explode("\n", $log) as $line) {
            if (strpos($line, 'PARTIAL: 092_wa_no_ai.sql') === false) continue;
            $pl = 1; if (stripos($line, 'CHECK constraint failed') !== false) $pc = 1;
        }
        $row = $has($p, '_schema_checks') ? (int)$one($p, "SELECT COUNT(*) FROM _schema_checks WHERE migration = '092_wa_no_ai.sql'") : 0;
        echo 'reset=', $reset, ' status=', (string)($res[0]['status'] ?? '-'), ' partial=', $pl, ' check=', $pc, ' row=', $row;
        break;

"""


def apply(doc, ctx):
    repo, plugin, base, pin = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"]
    added, changed = ctx["added"], ctx["changed"]
    show = lambda c, f: git(repo, "show", f"{c}:{plugin}/{f}").decode()

    # ── 1 — 092 from Git: the one migration, added; its sums, statements, self-check and objects ──
    migs = [f for f in added + changed if f.startswith("migrations/")]
    if migs != [MIG] or MIG not in added:
        raise SystemExit(f"PART MG: the delta's migrations are {migs}, not 092 added alone (root docs/76 §T.3 item 1)")
    s092, m092 = sha_at(repo, pin, f"{plugin}/{MIG}"), md5_at(repo, pin, f"{plugin}/{MIG}")
    if not s092.startswith("eb90f11e1160ca7d") or not m092.startswith("c51beb50b567131e"):
        raise SystemExit("PART MG: 092's sha256 or md5 is not §T.3's (eb90f11e…, c51beb50…)")
    t092 = show(pin, MIG)
    stmts = split_statements(t092)
    if len(stmts) != 7:
        raise SystemExit(f"PART MG: 092 splits into {len(stmts)} statements as the runner splits it, not §T.3's 7")
    ins = [s for s in stmts if s.startswith("INSERT INTO _schema_checks")]
    if len(ins) != 1 or stmts[-1] != ins[0]:
        raise SystemExit("PART MG: 092's last statement is not its one self-check INSERT")
    mins = re.search(r"SELECT '092_wa_no_ai\.sql', ([0-9]+), count\(\*\) FROM sqlite_master\s+WHERE name IN \(([^)]*)\)", ins[0])
    objects = re.findall(r"'([a-z_]+)'", mins.group(2)) if mins else []
    if not mins or int(mins.group(1)) != 5 or objects != ["wa_no_ai", "idx_wna_active", "wna_never_deleted", "wna_identity_fixed", "wna_lift_once"]:
        raise SystemExit(f"PART MG: 092's self-check is not (092_wa_no_ai.sql, 5) over §T.3's five objects: {objects}")
    if any(o not in PHP_MODES for o in objects) or "'092_wa_no_ai.sql'" not in PHP_MODES:
        raise SystemExit("PART MG: the PHP modes do not name 092's file and its five objects as Git has them")
    b089, _ = sc_block(show(pin, "migrations/089_quote_followups.sql"))
    b092, nxt = sc_block(t092)
    if not b089 or b089 != b092 or not nxt.startswith("INSERT INTO _schema_checks (migration, expected, found)"):
        raise SystemExit("PART MG: 092's _schema_checks statement is not 089's byte for byte, or its INSERT does not follow it")
    if show(base, "lib/MigrationRunner.php") != show(pin, "lib/MigrationRunner.php"):
        raise SystemExit("PART MG: lib/MigrationRunner.php differs between bf29cbd and the pin (§T.3 measured them identical)")
    mr = show(pin, "lib/MigrationRunner.php")
    for needle in ("'INSERT OR IGNORE INTO _migrations (filename, checksum, duration_ms) VALUES (?, ?, ?)'",
                   '$this->log("OK: {$filename} ({$stmtOk} stmts, {$durationMs}ms)");',
                   "$this->log(\"PARTIAL: {$filename} — {$stmtOk} ok, \" . count($stmtErrors) . \" skipped: {$summary}\");",
                   '$this->log("WARNING: {$filename} has been modified after being applied (checksum mismatch). Skipping.");',
                   "\"] --- earlier entries trimmed ---\\n\" . $keep,", "$files = glob($this->migrationsDir . '/*.sql');",
                   "public static function splitStatements(string $sql): array"):
        if mr.count(needle) < 1:
            raise SystemExit(f"PART MG: the runner does not hold what MG-CA2 reads: {needle[:80]}")
    ctx.update(mig107=(s092, m092, len(stmts), objects))

    doc.sub('MIG104_LAST="091_wa_offers.sql"; MIG104_LAST_MD5="9cb3b53833403417f7d3544055632c3e"\n',
            'MIG104_LAST="091_wa_offers.sql"; MIG104_LAST_MD5="9cb3b53833403417f7d3544055632c3e"\n'
            "# 5.18.107 (root docs/76 §T.3): its one migration, 092 — the assistant-off list, wa_no_ai — added, never modified, and\n"
            "# verifying itself: its _schema_checks statement is 089's byte for byte and its INSERT follows it (MIG107_SC, its key and\n"
            "# expected count; MIG107_OBJECTS, the five names the INSERT counts). The reviewed file byte for byte (sha256) and its\n"
            "# checksum as the runner records it in _migrations (md5), from Git; its statements as the runner splits them\n"
            "# (MIG107_STMTS). It must apply at the first boot after the copy, or no Uganda customer gets an AI reply (§X.5):\n"
            "# MG-G1 reads it before anything changes, QF-G2 tries it on a copy, MG-CA1, MG-CA2 and NA-CA1 read it after; a\n"
            "# rollback leaves its table, rows and file, and bf29cbd names none of them.\n"
            "MIG107_SUMS=\"$(cat <<'SUMS'\n"
            f"{s092} {m092}  {MIG}\n"
            "SUMS\n)\"\n"
            "MIG107_N=1\n"
            f'MIG107_LAST="092_wa_no_ai.sql"; MIG107_LAST_MD5="{m092}"\n'
            f"MIG107_STMTS={len(stmts)}\n"
            'MIG107_SC="092_wa_no_ai.sql:5"\n'
            f'MIG107_OBJECTS="{" ".join(objects)}"\n'
            "# NA-G1 and NA-CA1's sentinels (§T.3 item 5): numbers nobody can hold — the country code then 0, the rule the\n"
            "# project's tests use — 12 digits, within 092's CHECKs. Constants, so that the rehearsal can name them.\n"
            f'NA_SENTINEL_1="{SENTINELS[0]}"; NA_SENTINEL_2="{SENTINELS[1]}"\n',
            why="MIG107 constants")

    # A0's migration rule (§T.3 item 1)
    doc.sub("# 5.18.105 and 5.18.106 (root docs/74 §H.4.6, docs/75 §L.2): the pin adds no migration and changes none. 5.18.104's\n"
            "# three, 089–091, are what the live book must already carry (QF-G1 reads them applied); each is still the reviewed file\n"
            "# at the pin, by its sha256 and the md5 the runner records (MIG104_SUMS) — PREV_KEPT holds them by sha256 too.\n"
            '[ "$N_MIG" = "0" ] || stop "the pin carries $N_MIG migration(s) ($(printf \'%s\\n\' "$CHANGED" | grep "^$PLUGIN/migrations/" | sed "s#^$PLUGIN/migrations/##" | tr \'\\n\' \' \')) — $EXPECTED_VERSION adds none (root docs/75 §L.2): this script is for another build. Nothing was changed"\n',
            "# 5.18.107 (root docs/76 §T.3 item 1): the migrations among the delta are exactly MIG107_SUMS' — 092, added and never\n"
            "# modified, at its sha256 and the md5 the runner records — and 092's _schema_checks statement is 089's byte for byte at\n"
            "# the pin, its INSERT right after it: what lets 092 verify itself even where 089's statement failed. 5.18.104's three,\n"
            "# 089–091, are what the live book must already carry (QF-G1 reads them applied); each is still the reviewed file at the\n"
            "# pin, by its sha256 and the md5 the runner records (MIG104_SUMS) — PREV_KEPT holds them by sha256 too.\n"
            'MIG107_BAD=""; MIG107_SEEN=0\n'
            "while read -r mg_sha mg_md5 mg_rel; do\n"
            '  [ -n "$mg_rel" ] || continue\n'
            "  MIG107_SEEN=$((MIG107_SEEN + 1))\n"
            '  grep -qxF -- "$PLUGIN/$mg_rel" <<<"$ADDED" || MIG107_BAD="$MIG107_BAD ${mg_rel#migrations/}(not-added)"\n'
            '  [ "$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/$mg_rel" 2>/dev/null | sha256sum | cut -c1-64)" = "$mg_sha" ] || MIG107_BAD="$MIG107_BAD ${mg_rel#migrations/}(sha256)"\n'
            '  [ "$(git -C "$REPO" show "$EXPECTED_PLUGIN_COMMIT:$PLUGIN/$mg_rel" 2>/dev/null | md5sum | cut -c1-32)" = "$mg_md5" ] || MIG107_BAD="$MIG107_BAD ${mg_rel#migrations/}(md5)"\n'
            'done <<< "$MIG107_SUMS"\n'
            '[ "$MIG107_SEEN" = "$MIG107_N" ] || MIG107_BAD="$MIG107_BAD count:$MIG107_SEEN-read/$MIG107_N-expected"\n'
            '[ "$N_MIG" = "$MIG107_N" ] || MIG107_BAD="$MIG107_BAD the-pin-carries-$N_MIG-migration-files"\n'
            'for mg_rel in $(printf \'%s\\n\' "$CHANGED" | sed -n "s#^$PLUGIN/##p" | grep \'^migrations/\'); do grep -qF -- "  $mg_rel" <<<"$MIG107_SUMS" || MIG107_BAD="$MIG107_BAD ${mg_rel#migrations/}(not-092)"; done\n'
            "MIG107_SCB=\"$(git -C \"$REPO\" show \"$EXPECTED_PLUGIN_COMMIT:$PLUGIN/migrations/089_quote_followups.sql\" 2>/dev/null | awk '/^CREATE TABLE IF NOT EXISTS _schema_checks \\(/ { p = 1 } p { print } p && /\\);$/ { exit }')\"\n"
            "MIG107_SC2=\"$(git -C \"$REPO\" show \"$EXPECTED_PLUGIN_COMMIT:$PLUGIN/migrations/$MIG107_LAST\" 2>/dev/null | awk '/^CREATE TABLE IF NOT EXISTS _schema_checks \\(/ { p = 1 } p { print } p && /\\);$/ { exit }')\"\n"
            "MIG107_NX=\"$(git -C \"$REPO\" show \"$EXPECTED_PLUGIN_COMMIT:$PLUGIN/migrations/$MIG107_LAST\" 2>/dev/null | awk 'd && NF { print; exit } /^CREATE TABLE IF NOT EXISTS _schema_checks \\(/ { p = 1 } p && /\\);$/ { d = 1 }')\"\n"
            '{ [ -n "$MIG107_SCB" ] && [ "$MIG107_SCB" = "$MIG107_SC2" ]; } || MIG107_BAD="$MIG107_BAD 092:its-_schema_checks-statement-not-089\'s"\n'
            'case "$MIG107_NX" in "INSERT INTO _schema_checks (migration, expected, found)"*) ;; *) MIG107_BAD="$MIG107_BAD 092:its-INSERT-does-not-follow" ;; esac\n'
            '[ -z "$MIG107_BAD" ] || stop "the pin\'s migrations are not $EXPECTED_VERSION\'s one, 092, as reviewed ($MIG107_BAD) — root docs/76 §T.3: this script is for another build. Nothing was changed"\n',
            why="A0's migration rule (§T.3 item 1)")
    doc.sub('echo "  migrations      none added since $BASELINE_VERSION; 5.18.104\'s own, which the live book carries: ',
            'echo "  migrations      $MIG107_LAST added (the assistant-off list, applied at the first boot), its self-check 089\'s statement; 5.18.104\'s own, which the live book carries: ',
            why="A0's migrations line")

    # ── the PHP modes, and the live readers QF_PHP's header names ──
    doc.sub("    case 'notes':  // notes ROOT DIR — QF-N1, QF-N2, QF-N4, QF-N7 on the copy in DIR (its settings copies beside it)\n",
            PHP_MODES + "    case 'notes':  // notes ROOT DIR — QF-N1, QF-N2, QF-N4, QF-N7 on the copy in DIR (its settings copies beside it)\n",
            why="QF_PHP: mgledger, mg, na, neg092")
    doc.sub("// container. Every mode but \"lk\", \"base\", \"rbn\" and (at the baseline) \"lkrows\" reads only a COPY in a folder of this\n"
            "// run's; those open the live book with SQLITE_OPEN_READONLY and read its files as text — counts, key names and keyed\n",
            "// container. Every mode but \"lk\", \"base\", \"rbn\", \"mgledger\" and (at the baseline) \"lkrows\" reads only a COPY in a folder\n"
            "// of this run's; those open the live book with SQLITE_OPEN_READONLY and read its files as text — counts, key names and keyed\n",
            why="QF_PHP's header names mgledger among the live readers")
    doc.sub("if (in_array($mode, ['notes', 'ca4', 'ca6', 'ca8', 'on3', 'lon', 'cfgkey', 'uj'], true)) putenv('DN_VAULT_FILE=' . rtrim((string)($a[1] ?? ''), '/') . '/vault.json');",
            "if (in_array($mode, ['notes', 'ca4', 'ca6', 'ca8', 'on3', 'lon', 'cfgkey', 'uj', 'na'], true)) putenv('DN_VAULT_FILE=' . rtrim((string)($a[1] ?? ''), '/') . '/vault.json');",
            why="na reads its copy's own vault")
    doc.sub("if (in_array($mode, ['trial', 'neg'], true)) putenv('DN_VAULT_FILE=' . rtrim((string)($a[1] ?? ''), '/') . '/qf-' . $mode . '-vault-none.json');",
            "if (in_array($mode, ['trial', 'neg', 'neg092'], true)) putenv('DN_VAULT_FILE=' . rtrim((string)($a[1] ?? ''), '/') . '/qf-' . $mode . '-vault-none.json');\n"
            "if (in_array($mode, ['mg', 'mgledger'], true)) putenv('DN_VAULT_FILE=' . dirname((string)($a[$mode === 'mg' ? 1 : 0] ?? '')) . '/qf-' . $mode . '-vault-none.json');",
            why="neg092, mg and mgledger name a vault that is never made")
    # the trial reports 092's own line too: its status in the applied list, its log word and its statement count
    doc.sub("        echo 'applied=', $st === [] ? '-' : implode(',', $st), ' verdicts=', implode(',', $v), ' log=', implode(',', $l),\n"
            "             ' followups=', $before, '>', $fuHash();\n",
            "        // 5.18.107 (root docs/76 §T.3 item 3): the third argument, MIG107_LAST — its status in the applied list (none when\n"
            "        // the copy's ledger already held it), and its own migration.log line: OK with its statement count, PARTIAL, none\n"
            "        $xf = (string)($a[2] ?? ''); $xs = 'none'; $xw = 'none'; $xn = '-';\n"
            "        if ($xf !== '') {\n"
            "            foreach ($res as $r) if ((string)$r['file'] === $xf) $xs = (string)$r['status'];\n"
            "            foreach (explode(\"\\n\", $log) as $line) {\n"
            "                if (strpos($line, $xf) === false) continue;\n"
            "                if (strpos($line, 'PARTIAL: ' . $xf) !== false) { $xw = 'PARTIAL'; $xn = '-'; }\n"
            "                elseif (preg_match('/OK: ' . preg_quote($xf, '/') . ' \\(([0-9]+) stmts/', $line, $mm)) { $xw = 'OK'; $xn = $mm[1]; }\n"
            "                else { $xw = 'other'; $xn = '-'; }\n"
            "            }\n"
            "        }\n"
            "        echo 'applied=', $st === [] ? '-' : implode(',', $st), ' verdicts=', implode(',', $v), ' log=', implode(',', $l),\n"
            "             ' followups=', $before, '>', $fuHash(), ' extra=', $xf === '' ? '-' : $xf . ':' . $xs . ':' . $xw . ':' . $xn;\n",
            why="the trial reports 092")
    doc.sub("    case 'trial':  // trial ROOT DIR — QF-G2: 089 → 090 → 091 on DIR's copy through ROOT's (the pin's) MigrationRunner\n",
            "    case 'trial':  // trial ROOT DIR [EXTRA] — QF-G2: 089 → 090 → 091 → 092 on DIR's copy through ROOT's (the pin's) MigrationRunner;\n"
            "                   // EXTRA, since 5.18.107, the one file whose own line it reports beside the three verdicts (092)\n",
            why="the trial's header")

    # ── 2 — MG-G1 on QF-G1's copy, after QF-G1 ──
    doc.sub('  lk_g1                # LK-G1 — the ladder\'s keys as found (root docs/74 §H.4.3): a refusal stops here, nothing changed\n',
            "  # MG-G1 — 5.18.107 (root docs/76 §T.3 item 2): 092 on this copy, through the pin's code: absent from the ledger (a first\n"
            "  # deploy), or present whole — its checksum, its _schema_checks row 5 of 5, its five objects as the pin's 092 makes them\n"
            "  # (a redeploy after a rollback). Anything else is NO-GO and nothing is changed: a 092 the runner recorded partial, or\n"
            "  # with other bytes, is never re-run — where its table was not made the list stays unreadable and no Uganda customer\n"
            "  # gets an AI reply (§X.5); where it was, what it skipped is missing for good — and a deploy over it repairs neither.\n"
            "  # Its repair is not this script's (root docs/76 §T.10, known limits). The deploy run keeps the verdict (MG_G1).\n"
            '  o="$(qf_php mg "$sub/code" "$sub/plugin.sqlite3")"; MG_G1_LINE="$o"\n'
            '  case "$o" in\n'
            '    "mig092=0 "*) MG_G1=absent\n'
            '      ok "MG-G1 the copy\'s migration ledger has no 092 ($o) — a first deploy: 092 applies at the first boot after the copy (QF-G2 tries it on a copy first)" ;;\n'
            '    "mig092=1 cks=${MIG107_LAST_MD5:0:16} sc=5/5 objects=5/5 objsql=same table=1 at="*) MG_G1=present\n'
            '      ok "MG-G1 the copy\'s migration ledger holds 092 whole — its checksum ${MIG107_LAST_MD5:0:16}…, its _schema_checks row 5 of 5, its five objects as the pin\'s 092 makes them — a redeploy after a rollback: no boot applies it again" ;;\n'
            '    *) cih_drop "$sub"; stop "NO-GO (MG-G1): the book holds a 092 that is not whole ($o) — present with other bytes, or without its _schema_checks row 5 of 5, or with objects other than the pin\'s 092 makes. The runner never re-runs a file it has recorded, so a deploy over it repairs nothing: where its table was not made, no Uganda customer gets an AI reply. Its repair is a separate step the owner authorises (root docs/76 §T.10, known limits), never this script. Nothing was changed. Send the log file" ;;\n'
            "  esac\n"
            '  lk_g1                # LK-G1 — the ladder\'s keys as found (root docs/74 §H.4.3): a refusal stops here, nothing changed\n',
            why="MG-G1")
    doc.sub('QF_G1=""; QF_G1_LINE=""; QF_BASE=""   # 5.18.105: QF-G4 and its variables retired — LK-G1 (root docs/74 §H.4.4)\n',
            'QF_G1=""; QF_G1_LINE=""; QF_BASE=""   # 5.18.105: QF-G4 and its variables retired — LK-G1 (root docs/74 §H.4.4)\n'
            'MG_G1=""; MG_G1_LINE=""; QF_G2_MG=""; QF_G2_NEG092=""; NA_G1=""; MG_SAY=""; MG_WAIT=""; NA_P1=""; MG_CA1_R=""; NA_CA1_R=""; MG_CA2_R=""   # 5.18.107 (root docs/76 §T.3)\n',
            why="092's variables")

    # ── 3, 4, 5 — QF-G2 extended to 092; NA-G1 on the trial copy; neg092 on a fourth copy ──
    doc.sub('  o="$(qf_php trial "$sub/code" "$sub")"\n  QF_G2_TRIAL="$o"',
            '  o="$(qf_php trial "$sub/code" "$sub" "$MIG107_LAST")"\n  QF_G2_TRIAL="$o"', why="the trial names 092")
    doc.sub('  v="$(qf_kv "$o" followups)"; [ -n "$v" ] && [ "${v%%>*}" = "${v#*>}" ] || bad="$bad followups-changed(${v:-unread})"\n',
            '  v="$(qf_kv "$o" followups)"; [ -n "$v" ] && [ "${v%%>*}" = "${v#*>}" ] || bad="$bad followups-changed(${v:-unread})"\n'
            "  # 5.18.107 (root docs/76 §T.3 item 3): 092 on the same trial. Where MG-G1 read it absent (a first deploy) the trial\n"
            "  # applies it, ok, and its own migration.log holds OK with its MIG107_STMTS statements, never PARTIAL; where MG-G1 read\n"
            "  # it present (a redeploy) the trial applies none and logs none, and MG-G1's reading is the verdict. Either way the\n"
            "  # copy's 092 then reads whole: its checksum, its row 5 of 5, its five objects as the pin's 092 makes them on :memory:.\n"
            '  v="$(qf_kv "$o" extra)"\n'
            '  case "${MG_G1:-}|$v" in\n'
            '    "absent|$MIG107_LAST:ok:OK:$MIG107_STMTS"|"present|$MIG107_LAST:none:none:-") ;;\n'
            '    *) bad="$bad 092:${v:-unread}(MG-G1:${MG_G1:-not-read})" ;;\n'
            "  esac\n"
            '  QF_G2_MG="$(qf_php mg "$sub/code" "$sub/plugin.sqlite3")"\n'
            '  case "$QF_G2_MG" in "mig092=1 cks=${MIG107_LAST_MD5:0:16} sc=${MIG107_SC##*:}/${MIG107_SC##*:} objects=5/5 objsql=same table=1 at="*) ;; *) bad="$bad 092-on-the-trial-copy(${QF_G2_MG:-unread})" ;; esac\n'
            "  # NA-G1 — 5.18.107 (root docs/76 §T.3 item 5): wa_no_ai readable the way the worker reads it, on this trial copy\n"
            "  # through the pin's NoAiList — probe 1 read-only (the sentinel not listed, no throw; the list's counts; the scan's NOT\n"
            "  # EXISTS clause runs), probe 2 the positive control (a sentinel row on a throwaway copy reads listed, the second\n"
            "  # sentinel not), probe 3 the negative one (wa_no_ai renamed: listedId throws, its class only); the gate beside it\n"
            '  NA_G1="$(qf_php na "$sub/code" "$sub" "$NA_SENTINEL_1" "$NA_SENTINEL_2")"\n'
            '  echo "  wa_no_ai (NA-G1) $NA_G1"\n'
            '  case "$NA_G1" in "p1=none any="[01]" active="[0-9]*" scan=1 p2=listed,none p3=PDOException gate="*) ;; *) bad="$bad NA-G1(${NA_G1:-unread})" ;; esac\n',
            why="QF-G2 judges 092; NA-G1")
    doc.sub('  case "$o" in *"cols=24 status=partial partial=1 check=1 row=0"*) ;; *) bad="$bad negative-control(${o:-unread})" ;; esac\n',
            '  case "$o" in *"cols=24 status=partial partial=1 check=1 row=0"*) ;; *) bad="$bad negative-control(${o:-unread})" ;; esac\n'
            "  # neg092 — 5.18.107 (root docs/76 §T.3 item 4): 092's negative control on a fourth copy of its own (never Part V's: its\n"
            "  # own PARTIAL line for 090 would satisfy an untied match), dropped after. reset=0 is accepted only where MG-G1 read 092\n"
            "  # absent, reset=1 only where it read it present: any other pairing is NO-GO, nothing changed (a wa_no_ai with no\n"
            "  # ledger row of 092, for one, reads absent at MG-G1 and reset=1 here).\n"
            '  sub="$(qf_copy g2x "$P3B_PIN")" || stop "NO-GO (QF-G2): a fourth copy of the book (092\'s negative control) could not be made — ${sub:-?}. Nothing was changed. Send the log file"\n'
            '  o="$(qf_php neg092 "$sub/code" "$sub")"; cih_drop "$sub"\n'
            '  QF_G2_NEG092="$o"\n'
            '  echo "  negative control (092) $o"\n'
            '  case "${MG_G1:-}|$o" in\n'
            '    "absent|reset=0 status=partial partial=1 check=1 row=0"|"present|reset=1 status=partial partial=1 check=1 row=0") ;;\n'
            '    *"|reset="?" status=partial partial=1 check=1 row=0") bad="$bad 092-negative-control-pairing(MG-G1:${MG_G1:-not-read};${o%% status=*})" ;;\n'
            '    *) bad="$bad 092-negative-control(${o:-unread})" ;;\n'
            "  esac\n",
            why="neg092 and its pairing rule")
    doc.sub('  [ -z "$bad" ] || stop "NO-GO (QF-G2): the one migration trial (089 → 090 → 091 through the pin\'s runner, on a copy) did not read as reviewed:$bad. Nothing was changed. Send the log file"\n',
            '  [ -z "$bad" ] || stop "NO-GO (QF-G2): the one migration trial (089 → 090 → 091 → 092 through the pin\'s runner, on a copy), its list readings or its negative controls did not read as reviewed:$bad. Nothing was changed. Send the log file"\n',
            why="QF-G2's stop")
    doc.sub('  ok "QF-G2 on a copy, 089 → 090 → 091 through the pin\'s MigrationRunner: none pending on the copy of this live $BASELINE_VERSION book (each already in its ledger, none with other bytes),',
            '  ok "QF-G2 on a copy, 089 → 090 → 091 → 092 through the pin\'s MigrationRunner: 092 $( [ "${MG_G1:-}" = "present" ] && echo "already in the copy\'s ledger, applied by an earlier deploy (MG-G1), no line logged" || echo "applied, ok, its own migration.log OK with its $MIG107_STMTS statements") and whole on the copy ($QF_G2_MG); wa_no_ai readable the way the worker reads it ($NA_G1); 092\'s negative control on a fourth copy ($QF_G2_NEG092) paired with MG-G1\'s $MG_G1; 089–091 none pending on the copy of this live $BASELINE_VERSION book (each already in its ledger, none with other bytes),',
            why="QF-G2's ok line")

    # ── 6 — the state lines: MG_G1 and QF_COPY_UNIX at the DEPLOY step ──
    doc.sub("  printf 'QF_G1=%s\\nQF_BASE=%s\\nQF_COPY_AT=%s\\nLK_MAP=%s\\nLK_ON=%s\\nLK_SKIP=%s\\nLK_AT=%s\\n' \"${QF_G1:-}\" \"${QF_BASE:-}\" \"${QF_COPY_AT:-}\" \"$(lk_map \"${LK_B1:-}\")\" \"${LK_ON:-}\" \"${LK_SKIP:-}\" \"${LK_AT:-}\" >> \"$STATE\"\n",
            "  printf 'QF_G1=%s\\nQF_BASE=%s\\nQF_COPY_AT=%s\\nLK_MAP=%s\\nLK_ON=%s\\nLK_SKIP=%s\\nLK_AT=%s\\n' \"${QF_G1:-}\" \"${QF_BASE:-}\" \"${QF_COPY_AT:-}\" \"$(lk_map \"${LK_B1:-}\")\" \"${LK_ON:-}\" \"${LK_SKIP:-}\" \"${LK_AT:-}\" >> \"$STATE\"\n"
            "  # 5.18.107 (root docs/76 §T.3 item 6): MG-G1's verdict for --after-only, which never takes it from its own stage A (that\n"
            "  # sees 092 present after any deploy), and the copy's start, against which MG-CA1 dates 092's applied_at (a note)\n"
            "  printf 'MG_G1=%s\\nQF_COPY_UNIX=%s\\n' \"${MG_G1:-}\" \"${QF_COPY_UNIX:-}\" >> \"$STATE\"\n",
            why="the state lines MG_G1 and QF_COPY_UNIX")

    # ── 6, 7 — MG-CA1, MG-CA2 and NA-CA1 in stage R, before R7 (which reads the ledger after MG-CA1's wait) ──
    MG_AFTER = r'''# ── 5.18.107 (root docs/76 §T.3 items 6 and 7): migration 092 after the copy — MG-CA1, MG-CA2 and NA-CA1, stage R
#    (the deploy run and --after-only), before R7. MG-G1's verdict: this run's stage A in the deploy run; in --after-only
#    the state file's MG_G1 line, written at the DEPLOY step — never --after-only's own stage A, which sees 092 present
#    after any deploy. A missing record never falls back to the redeploy readings: each reading keyed on it is then NOT
#    ESTABLISHED, naming the missing record. No bound on applied_at: 092 can be applied during the copy, before COPY_AT
#    (a first boot's SqliteStore::create can run while tar is still writing other files), so it is dated against the
#    copy's start in a note, never judged. ──
mg_after() {
  local inst="$1" mg o sub w at au="" cu v seen="" wk="" ok1="" ok2="" r1 log l1 mk mkt okl pl wl i
  NA_P1=""   # set only by this run's NA-CA1 probe
  cih_main || true   # the run's folder, made in this shell before the readings on copies below, which run in $( … ) (DS3-fix1)
  if [ "$MODE" = "after" ]; then mg="$(sed -n 's/^MG_G1=//p' "$STATE" 2>/dev/null | tail -n1)"; cu="$(sed -n 's/^QF_COPY_UNIX=//p' "$STATE" 2>/dev/null | tail -n1)"
  else mg="${MG_G1:-}"; cu="${QF_COPY_UNIX:-}"; fi
  case "$mg" in absent|present) ;; *) mg="" ;; esac
  # MG-CA1 — a bounded wait (60 s) on the LIVE ledger, read-only, for 092's row; then a fresh copy
  for i in 1 2 3 4 5 6 7 8 9 10 11 12 13; do
    o="$(qf_php mgledger "$PDD_IN/plugin.sqlite3")"
    case "$o" in "mig092=1") seen=1; break ;; "mig092=0") wk="$i" ;; esac   # wk: the last reading at which the ledger answered without 092
    [ "$i" = "13" ] || sleep 5
  done
  r1="$( [ "$(sha256sum "$DEST/$MIG107_LAST_PATH" 2>/dev/null | cut -c1-64)" = "$MIG107_SHA" ] && echo ok || echo bad )"
  # §T.14 (review 2 of the derived script): the wait ends unseen either on the ledger read without 092 (absent) or on a
  # ledger that did not read (unread) — only the first says no boot has applied 092
  MG_WAIT=""; [ -n "$seen" ] || { [ "$o" = "mig092=0" ] && MG_WAIT=absent || MG_WAIT=unread; }   # review 4: the last reading decides; an earlier answer is said, never judged
  if [ -z "$seen" ]; then
    MG_CA1_R=bad
    if [ "$MG_WAIT" = "absent" ]; then bad "MG-CA1 092 is not in the live migration ledger after a wait of 60 s (its last reading: mig092=0): no boot has applied it since the copy — and until one does, no Uganda customer gets an AI reply (the list cannot be read)"
    else bad "MG-CA1 $( [ -n "$wk" ] && echo "the live migration ledger answered mig092=0 at reading $wk of 13 and did not answer after it" || echo "the live migration ledger could not be read in a wait of 60 s") (its last reading: ${o:-no answer}): whether a boot has applied 092 since the copy, and so whether the assistant-off list reads, is NOT ESTABLISHED"; fi
  elif [ -z "$inst" ] || ! sub="$(qf_copy mg "$inst")"; then
    MG_CA1_R=bad; bad "MG-CA1 092 is in the live ledger, but a fresh copy to read it on could not be made — ${sub:-no installed code to read with}; MG-CA1 and NA-CA1 are not judged"
  else
    o="$(qf_php mg "$sub/code" "$sub/plugin.sqlite3")"
    case "$o" in
      "mig092=1 cks=${MIG107_LAST_MD5:0:16} sc=${MIG107_SC##*:}/${MIG107_SC##*:} objects=5/5 objsql=same table=1 at="*)
        MG_CA1_R=ok; ok1=1
        ok "MG-CA1 092 on a fresh copy after the copy: in the migration ledger with its checksum ${MIG107_LAST_MD5:0:16}…, its _schema_checks row ${MIG107_SC##*:} of ${MIG107_SC##*:}, its five objects as the pin's 092 makes them ($o)" ;;
      ""|"?"*) MG_CA1_R=bad; bad "MG-CA1 092 could not be read on a fresh copy after the copy (${o:-no answer}): whether its ledger row, checksum, _schema_checks row and objects are whole is NOT ESTABLISHED" ;;
      *) MG_CA1_R=bad; bad "MG-CA1 092 on a fresh copy after the copy is not whole ($o; the reviewed checksum ${MIG107_LAST_MD5:0:16}…, ${MIG107_SC##*:} of ${MIG107_SC##*:}, its five objects): the runner never re-runs a file it has recorded" ;;
    esac
    at="$(qf_kv "$o" at)"; au=""; case "$at" in [0-9][0-9][0-9][0-9]-*) au="$(date -u -d "${at/T/ }" +%s 2>/dev/null)" ;; esac
    case "$mg|$au|$cu" in
      absent\|[0-9]*\|[0-9]*) [ "$au" -lt "$cu" ] && note "MG-CA1 092 was applied before this copy began (${at} UTC, the copy's start $(utc_of "$cu")): a process ran the runner after stage A — A0 makes such a 092 harmless, and MG-G1 (absent at stage A) and R7 (applied) show it is this release's" ;;
      present\|*) note "MG-CA1 092 was applied by an earlier deploy, before this copy (${at:-?} UTC)" ;;
    esac
    # NA-CA1 — the same probes as NA-G1, through the installed NoAiList, on this copy
    v="$(qf_php na "$sub/code" "$sub" "$NA_SENTINEL_1" "$NA_SENTINEL_2")"; NA_P1="$(qf_kv "$v" p1)"   # probe 1, the worker's own read: none or listed is an answer, ?<class> a list that does not read
    case "$v" in
      "p1=none any="[01]" active="[0-9]*" scan=1 p2=listed,none p3=PDOException gate="*) NA_CA1_R=ok; ok2=1
        ok "NA-CA1 wa_no_ai reads the way the worker reads it, through the installed NoAiList on a fresh copy: a number nobody holds is not listed and nothing throws; the positive control lists a sentinel row on a throwaway copy, the second sentinel not; with the table renamed the read throws ($v)" ;;
      *) NA_CA1_R=bad   # review 3: the verdict says what probe 1, the worker's own read, answered
         case "$NA_P1" in
           none) bad "NA-CA1 wa_no_ai reads the way the worker reads it (probe 1: a number nobody holds is not listed), but the rest of NA-CA1 did not hold ($v): probe 1 alone does not pass it" ;;
           listed) bad "NA-CA1 wa_no_ai lists a number nobody can hold (probe 1, the worker's own read, returned a row for NA-CA1's first sentinel; $v): the list reads, but its rows are not what 092 and staff left" ;;
           "?"*) bad "NA-CA1 wa_no_ai does not read the way the worker reads it, through the installed NoAiList ($v): probe 1 threw" ;;
           *) bad "NA-CA1 the probes did not answer ($v): whether wa_no_ai reads the way the worker reads it is NOT ESTABLISHED" ;;
         esac ;;
    esac
    cih_drop "$sub"
  fi
  # MG-CA2 — migration.log: its OK and PARTIAL lines for 092 are judged; its checksum WARNING never is a FAIL of MG-CA2's
  log="$(docker exec "$CONTAINER" sh -c 'grep -F "092_wa_no_ai.sql" "$1/migration.log" 2>/dev/null; printf "\n--line1--\n"; head -n1 "$1/migration.log" 2>/dev/null' _ "$PDD_IN" 2>/dev/null </dev/null)"
  okl="$(sed -n '/--line1--/q;p' <<<"$log" | grep -c 'OK: 092_wa_no_ai\.sql (')"; pl="$(sed -n '/--line1--/q;p' <<<"$log" | grep -c 'PARTIAL: 092_wa_no_ai\.sql')"
  wl="$(sed -n '/--line1--/q;p' <<<"$log" | grep -c 'WARNING: 092_wa_no_ai\.sql has been modified after being applied (checksum mismatch)')"
  l1="$(sed -n '/--line1--/{n;p;q}' <<<"$log")"; mk=""; mkt=""
  case "$l1" in "["*"] --- earlier entries trimmed ---") mk=1; mkt="$(date -u -d "$(sed -E 's/^\[([^]]*)\].*/\1/' <<<"$l1")" +%s 2>/dev/null)" ;; esac
  if [ "$MODE" = "deploy" ]; then
    if [ -z "$seen" ]; then printf 'MG_CA2_SEEN=nowait\n' >> "$STATE"; elif [ "$okl" -gt 0 ]; then printf 'MG_CA2_SEEN=1\n' >> "$STATE"; else printf 'MG_CA2_SEEN=0\n' >> "$STATE"; fi
  fi
  MG_CA2_R=ok
  [ "$pl" = "0" ] || { MG_CA2_R=bad; bad "MG-CA2 migration.log holds a PARTIAL line for 092 ($pl): a statement of 092 was refused when a boot ran it — the runner never runs it again"; }
  if [ "$wl" != "0" ]; then
    if [ -n "$ok1" ] && [ -n "$ok2" ] && [ "$r1" = "ok" ]; then
      note "MG-CA2 migration.log holds 092's checksum WARNING ($wl): a read met bytes other than those recorded — the tar straddle (root docs/76 §T.6, TD12): tar rewrites a 092 already recorded whole, and a boot read it meanwhile. MG-CA1 (checksum, 5 of 5, objects), NA-CA1 and R1 (the installed file at the pin's sha256) hold, so the WARNING was transient"
    else note "MG-CA2 migration.log holds 092's checksum WARNING ($wl) — not judged here: $( [ "$r1" = "ok" ] || echo "R1 reads the installed migrations/$MIG107_LAST other than the pin's; ")$( [ -n "$ok1" ] || echo "MG-CA1 failed; ")$( [ -n "$ok2" ] || echo "NA-CA1 failed or was not read; ")the FAIL is that reading's"; fi
  fi
  if [ "$okl" -gt 0 ]; then ok "MG-CA2 migration.log holds 092's OK line ($okl)$( [ "$pl" = "0" ] && echo " and no PARTIAL line for it")"
  elif [ -z "$mg" ]; then note "MG-CA2 NOT ESTABLISHED — THE STATE FILE HOLDS NO MG_G1 RECORD (an older state file): whether 092 was this deploy's to apply cannot be told, so its missing OK line is not judged"
  elif [ "$mg" = "present" ]; then note "MG-CA2 no OK line for 092 in migration.log — a redeploy: 092 was applied by an earlier deploy, the runner writes nothing for an applied file, and halving may have dropped the line since"
  elif [ "$MODE" = "deploy" ]; then
    if [ -z "$seen" ]; then note "MG-CA2 NOT ESTABLISHED — $( [ "$MG_WAIT" = "absent" ] && echo "NO BOOT HAD APPLIED 092 WHEN THE LOG WAS READ" || echo "092 COULD NOT BE SEEN IN THE LEDGER WHEN THE LOG WAS READ: THE LEDGER DID NOT ANSWER AT THE END OF THE WAIT") (MG-CA1's wait; its FAIL stands)"
    else note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092 IN migration.log, THOUGH THE LEDGER HOLDS IT: minutes after the first boot, halving would need about 128 KiB logged after 092's line, so the cause is a log write that failed silently (@file_put_contents)"; fi
  else
    v="$(sed -n 's/^MG_CA2_SEEN=//p' "$STATE" 2>/dev/null | tail -n1)"
    case "$v" in
      0) note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND STAGE R OF THE DEPLOY READ NONE EITHER (MG_CA2_SEEN=0), AFTER ITS WAIT SAW 092 IN THE LEDGER: the runner writes the ledger row and the OK line microseconds apart, so neither halving nor a later trim explains it" ;;
      1) note "MG-CA2 no OK line for 092 now — stage R of the deploy read it after the first boot (MG_CA2_SEEN=1), and it is gone since: $( [ -n "$mk" ] && echo "the log's first line is the runner's trim marker — 071_dpo_payments.sql's checksum WARNING is logged on every boot, so the runner halves the log within hours" || echo "the log's first line is no trim marker, so halving is not the cause: the log was rewritten or replaced some other way")" ;;
      nowait|"")
        if [ -n "$mk" ] && [ -n "$mkt" ] && [ -n "$au" ] && [ "$mkt" -ge "$au" ]; then
          note "MG-CA2 no OK line for 092 now — $( [ "$v" = "nowait" ] && echo "stage R's wait timed out (MG_CA2_SEEN=nowait), so its reading says nothing about the line;" || echo "the state file holds no MG_CA2_SEEN record;") the log's first line is a trim marker stamped (read as UTC) at or after 092's applied_at: 071_dpo_payments.sql's checksum WARNING is logged on every boot, and the runner halves the log within hours"
        else note "MG-CA2 NOT ESTABLISHED — NO OK LINE FOR 092, AND $( [ "$v" = "nowait" ] && echo "STAGE R'S WAIT TIMED OUT (MG_CA2_SEEN=nowait), SO ITS READING SAYS NOTHING ABOUT THE LINE" || echo "THE STATE FILE HOLDS NO MG_CA2_SEEN RECORD"); $( if [ -z "$seen" ]; then [ "$MG_WAIT" = "absent" ] && echo "092 IS STILL NOT IN THE LIVE LEDGER: THIS RUN'S WAIT TIMED OUT TOO (MG-CA1 ABOVE)" || echo "THE LIVE LEDGER DID NOT ANSWER AT THE END OF THIS RUN'S WAIT (MG-CA1 ABOVE)"; elif [ -z "$au" ]; then echo "092's applied_at could not be read, so no trim marker is weighed against it"; elif [ -n "$mk" ] && [ -z "$mkt" ]; then echo "the log's trim marker carries a stamp that could not be read"; elif [ -n "$mk" ]; then echo "the log's trim marker is stamped before 092's applied_at"; else echo "the log's first line is no trim marker"; fi)"; fi ;;
      *) note "MG-CA2 NOT ESTABLISHED — THE STATE FILE'S MG_CA2_SEEN RECORD CANNOT BE READ (${v})" ;;
    esac
  fi
  # the FAILED summary's lead (root docs/76 §T.3 item 7): NA-CA1 first, then MG-CA1, then MG-CA2 alone. "na" only where the
  # list is shown not to read (092 read absent, or probe 1 threw); "unk" where that is not established (the ledger at the
  # end of MG-CA1's wait, or the list, did not answer); "ctl" where NA-CA1 failed past probe 1, which answered (§T.14)
  # review 3: a list that reads (probe 1 answered none or listed) never outranks MG-CA1's fact; "dat" where probe 1 lists a
  # number nobody can hold (the list reads, its rows are wrong), "ctl" where the rest of NA-CA1 failed
  if [ -z "$seen" ]; then if [ "$MG_WAIT" = "absent" ]; then MG_SAY=na; else MG_SAY=unk; fi
  elif [ "$NA_CA1_R" = "bad" ] && [ "$NA_P1" != "none" ] && [ "$NA_P1" != "listed" ]; then case "$NA_P1" in "?"*) MG_SAY=na ;; *) MG_SAY=unk ;; esac
  elif [ "$MG_CA1_R" = "bad" ]; then MG_SAY=mg
  elif [ "$NA_CA1_R" = "bad" ]; then if [ "$NA_P1" = "listed" ]; then MG_SAY=dat; else MG_SAY=ctl; fi
  elif [ "$MG_CA2_R" = "bad" ]; then MG_SAY=log
  else MG_SAY=""; fi
}
'''
    doc.sub('[ -z "$CA1_T" ] || note "Q-CA1 PHP $PHPV rejects test files that are copied and never run on the server:$CA1_T"\n',
            '[ -z "$CA1_T" ] || note "Q-CA1 PHP $PHPV rejects test files that are copied and never run on the server:$CA1_T"\n'
            '# MG-CA1, MG-CA2 and NA-CA1 — 5.18.107 (root docs/76 §T.3 item 6): before R7, which reads the ledger after their wait\n'
            'mg_after "$(icode_build icode-mg "$EXPECTED_PLUGIN_COMMIT" 2>/dev/null || true)"   # the installed code, copied into the container (ICODE_R comes later, after R7)\n',
            why="mg_after is called before R7")
    doc.sub("# qf_stage — QF-G1–QF-G3, LK-G1, CS-G1,", MG_AFTER + "# qf_stage — QF-G1–QF-G3, LK-G1, CS-G1,", why="mg_after defined")
    doc.sub('MIG107_SC="092_wa_no_ai.sql:5"\n',
            'MIG107_SC="092_wa_no_ai.sql:5"\nMIG107_LAST_PATH="' + MIG + '"; MIG107_SHA="' + s092 + '"   # R1\'s reading of the installed file, for MG-CA2\'s note\n',
            why="092's path and sha for MG-CA2")

    # ── 8 — R7 by db_mig107 ──
    doc.sub("db_judge() {   # db_judge ID BEFORE NOW WHEN strict|loose — the ledger's last row the same (5.18.106 adds no migration: 091 last before and after, root docs/75 §L.2); the\n",
            "# db_mig107 BEFORE-LEDGER NOW-LEDGER — 5.18.104's \"applied\" rule (root scripts/deploy-5.18.104.sh), for 5.18.107's one\n"
            "# migration (root docs/76 §T.3 item 8): \"applied\" when NOW is BEFORE with exactly 092 added at a boot — BEFORE's last row\n"
            "# 091 (MIG104_LAST, its checksum), NOW's MIG107_LAST with its reviewed checksum, one row more; \"other\" otherwise. The\n"
            "# forms are DBS_PHP's: \"<rows>:<last migration>:<first 16 hex of its checksum>\".\n"
            "db_mig107() {\n"
            "  local br bf bc nr nf nc\n"
            '  IFS=: read -r br bf bc <<<"$1"; IFS=: read -r nr nf nc <<<"$2"\n'
            '  case "$br$nr" in \'\'|*[!0-9]*) echo other; return ;; esac\n'
            '  if [ "$bf|$bc" = "$MIG104_LAST|${MIG104_LAST_MD5:0:16}" ] && [ "$nf|$nc" = "$MIG107_LAST|${MIG107_LAST_MD5:0:16}" ] && [ "$nr" = "$((br + MIG107_N))" ]; then echo applied; else echo other; fi\n'
            "}\n"
            "db_judge() {   # db_judge ID BEFORE NOW WHEN strict|loose [same|applied|either] — the ledger's last row the same, or BEFORE with exactly 092 added (db_mig107) where the sixth word allows it (root docs/76 §T.3 item 8); the\n",
            why="db_mig107")
    doc.sub('  local b="$2" n="$3" bs ns bl nl\n  case "$b" in "size="*" ledger="*) ;; *) bad "$1 the database could not be read $4',
            '  local b="$2" n="$3" bs ns bl nl want="${6:-same}"\n  case "$b" in "size="*" ledger="*) ;; *) bad "$1 the database could not be read $4',
            why="db_judge's sixth word")
    doc.sub('  local mg="same"; [ "$bl" = "$nl" ] || mg="other"\n'
            '  if [ "$mg" = "other" ]; then bad "$1 the migration ledger CHANGED $4 ($bl → $nl; rows:last migration:checksum) — $( [ "$1" = "R7" ] && echo "$EXPECTED_VERSION adds no migration, and this run applies none" || echo "a rollback applies no migration and removes none ($BASELINE_VERSION\'s 089–091 stay, as they are)"). Send the log file"\n',
            '  local mg="same"; [ "$bl" = "$nl" ] || mg="$(db_mig107 "$bl" "$nl")"\n'
            '  case "$want|$mg" in same\\|same|applied\\|applied|either\\|same|either\\|applied) ;; *) mg="other" ;; esac\n'
            '  if [ "$mg" = "other" ]; then bad "$1 the migration ledger $( [ "$bl" = "$nl" ] && echo "DID NOT MOVE" || echo "CHANGED") $4 ($bl → $nl; rows:last migration:checksum) — $( case "$1|$want" in R7\\|applied) echo "on a first deploy $EXPECTED_VERSION\'s one migration, 092, is applied at the first boot, and the ledger must read exactly that" ;; R7\\|*) echo "$EXPECTED_VERSION adds exactly one migration, 092, applied at the first boot, and this run applies no other" ;; *) echo "a rollback applies no migration and removes none: 092 applied, if at all, by $BASELINE_VERSION\'s runner, only where it was absent at the rollback\'s stage A" ;; esac ). Send the log file"\n',
            why="db_judge judges 092")
    doc.sub('ok "$1 the database $4: the migration ledger its last row the same ($nl; rows:last migration:checksum),',
            'ok "$1 the database $4: the migration ledger $( [ "$mg" = "applied" ] && echo "moved by exactly 092, applied at a boot ($bl → $nl; rows:last migration:checksum — MG-CA1 judges it by its _schema_checks row)" || echo "its last row the same ($nl; rows:last migration:checksum)"),',
            why="db_judge's ok line")
    doc.sub("— $EXPECTED_VERSION adds no migration; what a live plugin writes at use is the plugin's own",
            "— $EXPECTED_VERSION adds exactly one migration, 092; what a live plugin writes at use is the plugin's own",
            why="db_judge's ok line: one migration")
    doc.sub('db_judge R7 "${DBS_A:-}" "$DBS_R" "since this run\'s stage A" strict\n',
            'db_judge R7 "${DBS_A:-}" "$DBS_R" "since this run\'s stage A" strict "$( [ "$MODE" = "deploy" ] && { [ "${MG_G1:-}" = "present" ] && echo same || echo applied; } || echo either )"   # 5.18.107: applied on a first deploy, same on a redeploy (MG-G1); --after-only, loose either way\n',
            why="R7: applied or same")
    doc.sub('  if [ -n "$DB_REC" ]; then db_judge R7 "$DB_REC" "$DBS_R" "since the deploy\'s stage A (its state file)" loose\n',
            '  if [ -n "$DB_REC" ]; then db_judge R7 "$DB_REC" "$DBS_R" "since the deploy\'s stage A (its state file)" loose either\n',
            why="R7 against DB_BEFORE: either")
    doc.sub('DBS_RB="$(db_state)"; db_judge RB "${DBS_A:-}" "$DBS_RB" "across the rollback" strict\n',
            'DBS_RB="$(db_state)"; db_judge RB "${DBS_A:-}" "$DBS_RB" "across the rollback" strict "$(case "${DBS_A:-}" in *" ledger="*":$MIG104_LAST:"*) echo either ;; *) echo same ;; esac)"   # 5.18.107: applied only where 092 was absent at the rollback\'s stage A\n',
            why="RB: same, or applied where 092 was absent")

    # ── 9 — LK-CA2's and LK-RB1's _schema_checks rows; wa_no_ai's rows among the tables that never fall ──
    doc.sub("        foreach (['quote_tracker', 'wa_auto_sends', 'quote_list_sends', 'wa_seen_marks', 'wa_media', 'wa_voice_calls', 'wa_voice_acks',\n"
            "                  'wa_offers', 'wa_offer_recipients', 'wa_offer_tests', 'wa_offer_events', 'quote_agent_makers', 'quote_agent_copies'] as $t) {\n",
            "        foreach (['quote_tracker', 'wa_auto_sends', 'quote_list_sends', 'wa_seen_marks', 'wa_media', 'wa_voice_calls', 'wa_voice_acks',\n"
            "                  'wa_offers', 'wa_offer_recipients', 'wa_offer_tests', 'wa_offer_events', 'quote_agent_makers', 'quote_agent_copies',\n"
            "                  'wa_no_ai'] as $t) {   // 5.18.107 (root docs/76 §T.3 item 9): wa_no_ai's trigger refuses a DELETE\n",
            why="lkrows counts wa_no_ai")
    doc.sub('  if [ "$(qf_kv "$a0" sc)" = "$(qf_kv "$b0" sc)" ]; then ok "$c every _schema_checks row unchanged $w ($(qf_kv "$a0" sc | tr \',\' \'\\n\' | grep -c .) rows)"\n'
            '  else bad "$c a _schema_checks row changed $w ($(qf_kv "$b0" sc | tr \',\' \' \') → $(qf_kv "$a0" sc | tr \',\' \' \'))"; fi\n',
            '  # 5.18.107 (root docs/76 §T.3 item 9): exact, or after = before plus exactly 092_wa_no_ai.sql:5/5 where MG-G1 read 092\n'
            '  # absent — 092\'s self-check adds its row at the first boot, after the baseline is taken\n'
            '  local scb sca scx\n'
            '  scb="$(qf_kv "$b0" sc)"; sca="$(qf_kv "$a0" sc)"\n'
            '  scx="$(printf \'%s\\n\' "$scb" "${MIG107_SC%%:*}:${MIG107_SC##*:}/${MIG107_SC##*:}" | tr \',\' \'\\n\' | grep -v \'^-*$\' | sort | paste -sd, -)"\n'
            '  if [ "$sca" = "$scb" ]; then ok "$c every _schema_checks row unchanged $w ($(printf \'%s\' "$sca" | tr \',\' \'\\n\' | grep -c .) rows)"\n'
            '  elif [ "$(lk_mg_g1)" = "absent" ] && [ "$(printf \'%s\' "$sca" | tr \',\' \'\\n\' | sort | paste -sd, -)" = "$scx" ]; then ok "$c every _schema_checks row unchanged $w but one added, exactly ${MIG107_SC%%:*}:${MIG107_SC##*:}/${MIG107_SC##*:} — 092\'s self-check at the first boot ($( [ "$MODE" = "rollback" ] && echo "this rollback\'s stage-A ledger" || echo "MG-G1") read 092 absent)"\n'
            '  else bad "$c a _schema_checks row changed $w ($(printf \'%s\' "$scb" | tr \',\' \' \') → $(printf \'%s\' "$sca" | tr \',\' \' \'))$( [ "$(lk_mg_g1)" = "absent" ] && echo " — only ${MIG107_SC%%:*}:${MIG107_SC##*:}/${MIG107_SC##*:} may be added, 092\'s self-check at the first boot" || echo " — $( [ "$MODE" = "rollback" ] && echo "this rollback\'s stage-A ledger" || echo "MG-G1") read 092 $( [ -n "$(lk_mg_g1)" ] && lk_mg_g1 || echo "as nothing known"): none may be added")"; fi\n',
            why="lk_rows_judge: exact or plus 092's row (K4b)")
    doc.sub("# lk_rows_judge CODE BEFORE AFTER WHEN — two lkrows readings: no table's rows fell (quote_followup_state over its rows NOT\n",
            "# lk_mg_g1 — MG-G1's verdict as lk_rows_judge reads it: this run's (the deploy run), the state file's MG_G1 line in\n"
            "# --after-only, or in --rollback, which runs no MG-G1, its own stage-A ledger (DBS_A) as R7 reads it — absent where the\n"
            "# last row is 091 (MIG104_LAST), present where it is 092 (MIG107_LAST); empty when none is known (root docs/76 §T.3\n"
            "# items 8 and 9: LK-RB1 and R7 accept 092 applied across a rollback on the same condition)\n"
            "lk_mg_g1() { case \"$MODE\" in after) sed -n 's/^MG_G1=//p' \"$STATE\" 2>/dev/null | tail -n1 ;; rollback) case \"${DBS_A:-}\" in *\" ledger=\"*\":$MIG104_LAST:\"*) printf absent ;; *\" ledger=\"*\":$MIG107_LAST:\"*) printf present ;; esac ;; *) printf '%s' \"${MG_G1:-}\" ;; esac; }\n"
            "# lk_rows_judge CODE BEFORE AFTER WHEN — two lkrows readings: no table's rows fell (quote_followup_state over its rows NOT\n",
            why="lk_mg_g1")
    for s in ("adds no migration, and this run applies none", "(5.18.106 adds no migration: 091 last before and after",
              '[ "$N_MIG" = "0" ] || stop'):
        doc.mustnot(s, why="no 'no migration' rule left in partMG's places")

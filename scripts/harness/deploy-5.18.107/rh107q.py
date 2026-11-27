#!/usr/bin/env python3
# 5.18.107's rehearsal: its own readers — of the script's own text (the static guard), and of the book, the migration
# ledger, migration.log and the state file where a case judges what the script read. Each rule is written here from the
# RELEASE's own code at its pin (771a3c1: lib/MigrationRunner.php, lib/NoAiList.php, lib/BurstMerge.php and the readers
# they call) or from the script's own lines (deploy-5.18.107.sh at abf0a25, cited per mode as S:n), in Python — never by
# running either, so one wrong reading cannot confirm itself. READERS ONLY: the store is opened read-only (mode=ro,
# query_only) and every file is read as bytes; nothing is written anywhere (run with python3 -I). Every mode prints ONE
# line of key=value words — counts, ids, fixed codes, UTC times and sha256 hashes; never a value, a name, a phone, a body
# or an event's detail. Exit 2: an unknown mode or a missing argument; exit 3: unread:<Error>. Modes:
#   verbs107 SCRIPT       the static guard: rh106q.py verbs' rules (rh105q.py's, unchanged) — lk= qftool= wrapper= verbs=
#                         — and, because 5.18.107's three wrapper lines are the assignments of the commands it PRINTS
#                         (S:7341–7343), cmds=<the variables those lines assign, comma-joined, in order> and
#                         printed-only=<how many of them are used only in lines that print them: echo or printf, never
#                         eval, never a bare word, never sh -c>. A wrapper line that is not such an assignment, or a use
#                         that runs one, shows in the counts and the guard refuses.
#   ledger DB             mig092=<092's rows in _migrations> wa_no_ai=<table|renamed|none> — 107-pre0's and every reset's word
#                         (renamed: the table is gone and rh107-seed.php's rh107_wa_no_ai stands in its place)
#   ledgerx DB            the same, then cks092= at092= (UTC epoch of applied_at, as SQLite's datetime('now') wrote it)
#                         sc092=<found>/<expected>|missing cks071= sentinel=<active rows on 2560…, NA-CA1's kind> rows=<ledger rows>
#   log092 LOG            migration.log as MG-CA2 reads it (S:8466–8469), per LINE: ok= partial= warning= (092's) w071=
#                         mk=<1 when line 1 is the runner's trim marker> mkt=<its stamp, read as UTC, epoch|-> size= okoff=<the
#                         byte offset of 092's first OK line|-> owner=<uid> lines=
#   sched DB              budget=<master_budget_seconds in the store's kyc_config row, or absent> entries=<master_schedule's
#                         entries, or absent|unreadable> — what master reads (MS_PHP's reading, S:8223)
#   evstat DB ID…         <id>:<status>:<attempts> for each event id, or <id>:none — the drain's named events, read back
#   bookdig DB            the book's logical digest — every schema object's text, then every table's rows in rowid order —
#                         16 hex: unchanged across a run proves the run wrote nothing to the book
#   blocks107 FILE…       design-v2 A18, the one-block rule, over the runs' kept outputs: files= cmds=<command lines of the five
#                         kinds — deploy, redeploy, after, rollback, putback — six-space lines> mixed=<lines holding a
#                         rollback command beside any other of the five> adjacent=<two of the five with no prose line between
#                         them: only blank lines or other six-space lines> at=<file:line of each offence, or ->
import calendar
import hashlib
import json
import os
import re
import sqlite3
import sys
import time


def code_lines(t):   # rh106q.py's, unchanged: comment lines (# or //) are not code
    for n, l in enumerate(t.split('\n'), 1):
        s = l.lstrip()
        if s.startswith('#') or s.startswith('//'):
            continue
        yield n, l


CMD_ASSIGN = re.compile(r'^([A-Z][A-Z0-9_]*_CMD)="cd \$REPO && bash \$QF_WRAPPER [^"]*"$')
PRINTS = re.compile(r'^\s*(?:\[[^]]*\]\s*(?:\|\||&&)\s*)?(echo|printf)\b')


def verbs107(path):
    t = open(path, encoding='utf-8', errors='replace').read()
    lk_ = sum(1 for l in t.split('\n') if l.startswith("    case 'lk':"))
    qft = wrp = vb = 0
    cmds = []
    for n, l in code_lines(t):
        if 'qf_tool ' in l and 'qf_tool()' not in l:
            for m in re.finditer(r'qf_tool "\$[A-Za-z0-9_]+(?:/ss)?" ([A-Za-z-]+)', l):
                if m.group(1) not in ('show', '--check', 'doctor'):
                    qft += 1
        if '$QF_WRAPPER' in l and not l.lstrip().startswith('echo '):
            wrp += 1
            m = CMD_ASSIGN.match(l)
            if m:
                cmds.append(m.group(1))
        if re.search(r'sales[-_]followup(\.php|\.sh)?"? (on|off|skip|set|erase)\b', l) and not l.lstrip().startswith('echo ') \
                and '--clear-switches)' not in l:
            vb += 1
        if re.search(r'"\$(?:IN_CONTAINER|d/code|sub/code|inst|P3B_PIN|P3B_BASE)/\$QF_TOOL" (on|off|skip|set|erase)\b', l):
            vb += 1
    printed_only = 0
    for v in cmds:
        uses = [l for n, l in code_lines(t) if re.search(r'\$\{?%s\b' % re.escape(v), l)]
        # every use prints it: the line is an echo or printf (optionally behind a [ … ] || / && guard), the variable
        # appears only inside that command's quoted arguments, and nothing on the line runs a string (eval, sh -c, bash -c)
        ok = bool(uses) and all(PRINTS.match(l) and not re.search(r'\b(eval|sh -c|bash -c)\b', l) for l in uses)
        printed_only += 1 if ok else 0
    print('lk=%d qftool=%d wrapper=%d verbs=%d cmds=%s printed-only=%d'
          % (lk_, qft, wrp, vb, ','.join(cmds) or '-', printed_only))


def ro(db):   # read-only, query_only: a reader never writes the book
    if not os.path.isfile(db):
        raise FileNotFoundError(db)
    c = sqlite3.connect('file:%s?mode=ro' % db, uri=True, timeout=15)
    c.execute('PRAGMA query_only = ON')
    return c


def has(c, t):
    return c.execute("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", (t,)).fetchone() is not None


def utc_epoch(stamp):   # 'Y-m-d H:i:s', read as UTC (SQLite's datetime('now'))
    return calendar.timegm(time.strptime(stamp.strip()[:19], '%Y-%m-%d %H:%M:%S'))


def ledger(db, full):
    c = ro(db)
    rows = c.execute("SELECT checksum, applied_at FROM _migrations WHERE filename = '092_wa_no_ai.sql' ORDER BY id DESC").fetchall() if has(c, '_migrations') else []
    tbl = 'table' if has(c, 'wa_no_ai') else ('renamed' if has(c, 'rh107_wa_no_ai') else 'none')
    out = ['mig092=%d' % len(rows), 'wa_no_ai=%s' % tbl]
    if full:
        out.append('cks092=%s' % (rows[0][0][:16] if rows else '-'))
        out.append('at092=%s' % (utc_epoch(rows[0][1]) if rows else '-'))
        sc = c.execute("SELECT found, expected FROM _schema_checks WHERE migration = '092_wa_no_ai.sql'").fetchone() if has(c, '_schema_checks') else None
        out.append('sc092=%s' % ('%d/%d' % (sc[0], sc[1]) if sc else 'missing'))
        r71 = c.execute("SELECT checksum FROM _migrations WHERE filename = '071_dpo_payments.sql' ORDER BY id DESC").fetchone() if has(c, '_migrations') else None
        out.append('cks071=%s' % (r71[0][:16] if r71 else '-'))
        out.append('sentinel=%d' % (c.execute("SELECT COUNT(*) FROM wa_no_ai WHERE active = 1 AND phone GLOB '2560*'").fetchone()[0] if tbl == 'table' else 0))
        out.append('rows=%d' % (c.execute('SELECT COUNT(*) FROM _migrations').fetchone()[0] if has(c, '_migrations') else 0))
    print(' '.join(out))


TRIM = re.compile(r'^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] --- earlier entries trimmed ---$')


def log092(path):
    b = open(path, 'rb').read()
    lines = b.decode('utf-8', 'replace').split('\n')
    ok = sum(1 for l in lines if 'OK: 092_wa_no_ai.sql (' in l)
    pa = sum(1 for l in lines if 'PARTIAL: 092_wa_no_ai.sql' in l)
    wa = sum(1 for l in lines if 'WARNING: 092_wa_no_ai.sql has been modified after being applied (checksum mismatch)' in l)
    w71 = sum(1 for l in lines if 'WARNING: 071_dpo_payments.sql has been modified after being applied (checksum mismatch)' in l)
    m = TRIM.match(lines[0]) if lines else None
    off = b.find(b'OK: 092_wa_no_ai.sql (')
    print('ok=%d partial=%d warning=%d w071=%d mk=%d mkt=%s size=%d okoff=%s owner=%d lines=%d'
          % (ok, pa, wa, w71, 1 if m else 0, utc_epoch(m.group(1)) if m else '-', len(b), off if off >= 0 else '-',
             os.stat(path).st_uid, len([l for l in lines if l != ''])))


def sched(db):
    c = ro(db)
    b = 'absent'
    if has(c, 'kyc_config'):
        r = c.execute('SELECT data FROM kyc_config WHERE id = 0').fetchone()
        try:
            d = json.loads(r[0]) if r else None
        except ValueError:
            d = None
        if isinstance(d, dict) and 'master_budget_seconds' in d:
            b = str(d['master_budget_seconds'])
    e = 'absent'
    if has(c, 'master_schedule'):
        r = c.execute('SELECT data FROM master_schedule WHERE id = 0').fetchone()
        if r:
            try:
                d = json.loads(r[0])
                e = str(len(d)) if isinstance(d, dict) else 'unreadable'
            except ValueError:
                e = 'unreadable'
    print('budget=%s entries=%s' % (b, e))


def evstat(db, ids):
    c = ro(db)
    out = []
    for i in ids:
        r = c.execute('SELECT status, attempts FROM events WHERE id = ?', (int(i),)).fetchone()
        out.append('%d:%s' % (int(i), '%s:%d' % (r[0], r[1]) if r else 'none'))
    print(' '.join(out))


def bookdig(db):
    c = ro(db)
    h = hashlib.sha256()
    objs = c.execute("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name").fetchall()
    for o in objs:
        h.update((json.dumps(o) + '\n').encode())
    for o in objs:
        if o[0] != 'table':
            continue
        for r in c.execute('SELECT * FROM "%s"' % o[1].replace('"', '""')):
            h.update((json.dumps([x if not isinstance(x, bytes) else x.hex() for x in r]) + '\n').encode())
    print(h.hexdigest()[:16])


def blocks107(files):
    """A18: (a) no line holds a rollback command beside a deploy, redeploy, after-only or put-back command; (b) no two command
    lines of the five kinds (six spaces of indent) follow each other with only blank lines, or other six-space lines,
    between them — a prose line must separate them. A command is classified by the deploy script it invokes and that
    invocation's own flags, or as a put-back (git checkout <commit> beside deploy-hybrid.sh)."""
    inv = re.compile(r'bash scripts/deploy-[0-9.]+\.sh((?:\s+--[a-z-]+)*)')
    def kinds(line):
        k = []
        for m in inv.finditer(line):
            fl = m.group(1).split()
            k.append('rollback' if '--rollback' in fl else 'redeploy' if '--redeploy' in fl else 'after' if '--after-only' in fl else 'deploy')
        if re.search(r'git checkout [0-9a-f]{7}', line) and 'deploy-hybrid.sh' in line:
            k.append('putback')
        return k
    n_files = n_cmd = n_mix = n_adj = 0
    at = []
    for path in files:
        n_files += 1
        with open(path, 'rb') as fh:
            lines = fh.read().decode('utf-8', 'replace').replace('\r', '').split('\n')
        prev = False
        for i, line in enumerate(lines, 1):
            k = kinds(line)
            if len(k) >= 2 and 'rollback' in k:
                n_mix += 1; at.append('%s:%d' % (os.path.basename(path), i))
            six = line.startswith('      ') and not line.startswith('       ') and line.strip() != ''
            if six and k:
                n_cmd += 1
                if prev:
                    n_adj += 1; at.append('%s:%d' % (os.path.basename(path), i))
                prev = True
            elif line.strip() == '' or six:
                pass
            else:
                prev = False
    print('files=%d cmds=%d mixed=%d adjacent=%d at=%s' % (n_files, n_cmd, n_mix, n_adj, ','.join(at[:12]) or '-'))


def main(argv):
    if len(argv) < 2:
        print('unknown'); return 2
    cmd = argv[1]
    try:
        if cmd == 'verbs107' and len(argv) == 3:
            verbs107(argv[2]); return 0
        if cmd in ('ledger', 'ledgerx') and len(argv) == 3:
            ledger(argv[2], cmd == 'ledgerx'); return 0
        if cmd == 'log092' and len(argv) == 3:
            log092(argv[2]); return 0
        if cmd == 'sched' and len(argv) == 3:
            sched(argv[2]); return 0
        if cmd == 'evstat' and len(argv) >= 4:
            evstat(argv[2], argv[3:]); return 0
        if cmd == 'bookdig' and len(argv) == 3:
            bookdig(argv[2]); return 0
        if cmd == 'blocks107' and len(argv) >= 3:
            blocks107(argv[2:]); return 0
    except Exception as e:   # a reader that could not read says so, and never guesses
        print('unread:%s' % type(e).__name__); return 3
    print('unknown'); return 2


if __name__ == '__main__':
    sys.exit(main(sys.argv))

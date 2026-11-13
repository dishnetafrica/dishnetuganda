#!/usr/bin/env python3
# 5.18.105's rehearsal: its own readers — of the ladder's keys in the four places, of the store's tables, of the logs the
# plugin writes and of the script's own text — written here from the rules root docs/74 states (§H.4.3, §H.4.5, §H.5,
# §H.6), in Python, over the store opened READ-ONLY and the files read as text, never through the release's code: the
# script's own readings (QF_PHP lk, lkrows, rbn; LM_PHP count, sinks) are set against these. Every mode prints counts,
# key or step names, labels and hashes of values (never a value, a name, a phone, an e-mail, a staff id or a key's
# secret). Run by the rehearsal with python3 -I. Modes:
#   lk DB KYC PCFG DCFG ALLCSV SETCSV STEPMAP [MAPOUT]
#        the ladder's keys — ALLCSV (SalesFollowupLadder::allKeys()) and SETCSV (settingKeys()), both read by the caller
#        from the RELEASE's own code — in the four places: KYC <data>/kyc_config.json (k), the store row kyc_config id 0
#        in DB (s), PCFG <plugin>/data/config.json (p), DCFG <data>/config.json (d). An absent file holds no key; one
#        that cannot be read or decoded is "unread". STEPMAP is the release's steps in their order, each step:key:rule —
#        rule r a record key (on when it is not blank), s a switch (on when true, 1, or '1'/'true'/'on'/'yes'). Prints
#        keys= settings= k= s= p= d= held= held_settings= setnames= differ= on= on_k= skipped= ledger= unread= digest=
#        (digest: the first 16 hex of the sha256 of the sorted key|place|hash lines, each hash the first 16 hex of the
#        sha256 of the value's canonical JSON); with MAPOUT those lines are written there (mode 0600), never printed
#   lkdiff MAPA MAPB      two MAPOUT files: changed=<key@place,…|-> keys=<key,…|-> n=<entries>
#   counts DB             the tables 5.18.105's rule says never fall, and the counts its rollback reads: name=n …
#                         (-1 when absent), qfs_kept (quote_followup_state NOT LIKE 'list_last:%'), qfs_all, qfs_listlast,
#                         the closed quote follow-ups, the paused offers, the settled recipients, the active opt-outs and
#                         sc= the first 16 hex of the sha256 of every _schema_checks row
#   fell A B              two counts lines: fell=<table:before>after,…|-> (the tables that never fall) unread=<…|->
#   same A B NAMES        same=yes, or diff:<name:a/b,…> for the comma-separated NAMES
#   optout DB PHONE       the active opt-outs whose phone ends in PHONE's last nine digits: n=<n>
#   listlast DB DAY       quote_followup_state's list_last:<DAY>:… rows: n=<n>
#   flags MASTERSRC DB JOBS
#                         each of the comma-separated JOBS, its 'flag' read from master.php's text (MASTERSRC, a file) and
#                         whether the store row reads it on: want=<jobs on, in JOBS' order|-> flags=<job:key:0|1,…>
#   logs COPYUNIX FORMS NAMES EXCL CTRL FROM [--optout-before REGEXFILE] SPEC…
#                         the records of each log or sink in SPEC (PATH:KIND:ZONE:TAG), chosen by their OWN stamp — never by
#                         byte offset: FROM after (stamped at or after COPYUNIX) or all. KIND lines (each line's [stamp]),
#                         server (PHP's built-in server's [Day Mon d hh:mm:ss yyyy]), phplog ([dd-Mon-yyyy hh:mm:ss Zone]),
#                         whlog (webhook_log.json: received_at; message and data judged), qwa (quote_wa_log.json: time; msg),
#                         activity (activity_log.json: time; title and detail), activitydb (the book's activity_log table, by
#                         its record's time or created_at), overdue (overdue_cron_status.json: one record, started_at, its
#                         output alone, the [hh:mm:ss] prefixes taken off), whole (captured whole after the copy: every line
#                         judged). A naive stamp is read in ZONE (kampala or utc); ambig counts the records a reading in the
#                         other zone would choose differently. ZONE kampala! (or utc!): the sink's writer fixes the zone
#                         (PluginLog: the plugin's, ' UTC' said when it falls back) — such a record is counted fixed=, never
#                         ambig=. Judged text is the record without its own stamp. FORMS: fixed
#                         strings, the fixture numbers' written forms; NAMES: fixed strings, the seeded names; EXCL:
#                         label<TAB>fixed words — a record holding them is left out of the NAME scan only, counted per label;
#                         CTRL: label<TAB>regex — the positive controls, counted. --optout-before: records stamped BEFORE the
#                         copy whose raw line matches REGEXFILE's regex are left out of both scans, counted (optout=). Prints
#                         one line per SPEC and a total; counts only, never a line
#   phones4 PHONES OUT    each E.164 number of PHONES (one per line) in its four written forms and its last nine digits,
#                         written to OUT (0600) for the scans; prints n=<numbers> forms=<lines>
#   blocks FILE VERSION   the copyable blocks of a run's output (six-space lines), as 5.18.104's reader, for VERSION's
#                         script: blocks=<n> mixed=<n> kinds=<…>
#   stcmp ACTUAL EXPECTED code:step=state pairs compared — EXPECTED may say judged (a PASS or a NOT_YET), notyet (any
#                         NOT_YET), any, or a|b alternatives: same, or the pairs that differ (got/want)
#   lmaudit REC           the fake docker's exec record of one run: the LM_PHP processes by mode — lm=<n> modes=<m:n,…|->
#                         other=<n> (modes outside count and sinks)
#   lmscript SCRIPT       the script's LM_HUNKS entries, one per line: file|label|old lines|old sha256|new lines|new sha256
#   gitlm REPO BASE PIN PLUGIN
#                         the same entries computed here from Git (diff -U0 BASE PIN over the delta's runtime files, the
#                         5.18.105 marker lines left out), the label LN-nn where a marker names one, else *; one per line
#   verbs SCRIPT          the static guard (root docs/74 §H.6.1): lk=<lines that open QF_PHP's lk case> qftool=<qf_tool calls
#                         other than show, --check or doctor> wrapper=<code lines using $QF_WRAPPER outside echo>
#                         verbs=<code lines invoking the switch tool's on, off, skip, set or erase outside printed text>
#   hdr SCRIPT VERSION    the header's commands between its two marker lines: cmds=<n> kinds=<k,…> alone=<n>
#                         back=<n> (a rollback, put-back, redeploy, clear or checkout) tee=<the script's runs with a log>
#                         outside=<commands in the header outside the markers>
#   retired SCRIPT        the retired codes in printed text (a check's ok/bad/note/stop/echo/warn) where it does not say
#                         they are retired: printed=<n> codes=<…|-> opt=<--rollback-unpriced-pending options>
#   secret KEYFILE OUT    the LK key file: mode=<octal> key=read|none — its value written to OUT (0600), never printed
#   kv LINE KEY           one key=value field of a line
import sys, os, re, json, sqlite3, hashlib, subprocess, calendar, time

KAMPALA = 3 * 3600   # Africa/Kampala is UTC+3, without summer time


def ro(db):
    c = sqlite3.connect('file:' + db + '?mode=ro', uri=True)
    c.execute('PRAGMA query_only = ON')
    return c


def has(con, t):
    return con.execute("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", (t,)).fetchone() is not None


def cols(con, t):
    return [r[1] for r in con.execute('PRAGMA table_info([%s])' % t)]


def sha16(s):
    return hashlib.sha256(s.encode('utf-8')).hexdigest()[:16]


def canon(v):
    return json.dumps(v, sort_keys=True, separators=(',', ':'), ensure_ascii=False)


def readjson(path):
    """(dict, state): state absent, read or unread."""
    if not os.path.exists(path):
        return {}, 'absent'
    try:
        with open(path, encoding='utf-8') as f:
            d = json.load(f)
        return (d if isinstance(d, dict) else {}), ('read' if isinstance(d, dict) else 'unread')
    except (OSError, ValueError):
        return {}, 'unread'


def storerow(db):
    if not os.path.isfile(db):
        return {}, 'unread'
    try:
        c = ro(db)
        try:
            if not has(c, 'kyc_config'):
                return {}, 'read'
            r = c.execute('SELECT data FROM kyc_config WHERE id = 0').fetchone()
            if r is None:
                return {}, 'read'
            d = json.loads(r[0])
            return (d if isinstance(d, dict) else {}), ('read' if isinstance(d, dict) else 'unread')
        finally:
            c.close()
    except (sqlite3.Error, ValueError, TypeError):
        return {}, 'unread'


def blank(v):
    return v is None or v is False or (isinstance(v, str) and v.strip() == '')


def truthy(v):
    if isinstance(v, bool):
        return v
    if isinstance(v, int):
        return v == 1
    return isinstance(v, str) and v.strip().lower() in ('1', 'true', 'on', 'yes')


def kv(line, key):
    m = re.search(r'(?:^| )' + re.escape(key) + r'=([^ ]*)', line)
    return m.group(1) if m else ''


def lk(argv):
    db, kyc, pcfg, dcfg, allcsv, setcsv, stepmap = argv[:7]
    mapout = argv[7] if len(argv) > 7 else ''
    allk = [x for x in allcsv.split(',') if x]
    setk = [x for x in setcsv.split(',') if x and x not in allk]
    docs, unread = {}, []
    for c, p in (('k', kyc), ('p', pcfg), ('d', dcfg)):
        d, st = readjson(p)
        docs[c] = d
        if st == 'unread':
            unread.append(c)
    d, st = storerow(db)
    docs['s'] = d
    if st == 'unread':
        unread.append('s')
    n = {'k': 0, 's': 0, 'p': 0, 'd': 0}
    held, helds, differ, mp, setn = [], 0, [], [], []
    for k in allk + setk:
        h = {}
        for c in ('k', 's', 'p', 'd'):
            if k in docs[c]:
                n[c] += 1
                h[c] = sha16(canon(docs[c][k]))
                mp.append('%s|%s|%s' % (k, c, h[c]))
        if k in allk and any(not blank(docs[c].get(k)) for c in ('k', 's') if k in docs[c]):
            setn.append(k)
        if 'p' in h or 'd' in h:
            if k in allk:
                held.append(k)
            else:
                helds += 1
        if h.get('k', '') != h.get('s', ''):
            differ.append(k)
    steps = []
    for x in [y for y in stepmap.split(',') if y]:
        p = x.split(':')
        if len(p) != 3:
            print('lk=badmap')
            return
        steps.append(p)

    def onlist(doc):
        out = []
        for s, key, rule in steps:
            v = doc.get(key)
            if (rule == 'r' and not blank(v) and str(v).strip() != '') or (rule == 's' and truthy(v)):
                out.append(s)
        return out
    on_s, on_k = onlist(docs['s']), onlist(docs['k'])
    sk = [s for s, _, _ in steps if s not in on_s and not blank(docs['s'].get('sf_skip_' + s)) and str(docs['s'].get('sf_skip_' + s)).strip() != '']
    led = 'on' if str(docs['s'].get('wa_send_ledger', '')) == 'on' else 'off'
    mp.sort()
    if mapout:
        fd = os.open(mapout, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            f.write(''.join(x + '\n' for x in mp))
    j = lambda xs: ','.join(xs) if xs else '-'
    print('keys=%d settings=%d k=%d s=%d p=%d d=%d held=%d held_settings=%d setnames=%s differ=%s on=%s on_k=%s skipped=%s ledger=%s unread=%s digest=%s' % (
        len(allk), len(setk), n['k'], n['s'], n['p'], n['d'], len(held), helds, j(setn), j(differ), j(on_s), j(on_k), j(sk), led,
        j(unread), sha16('\n'.join(mp))))


def lkdiff(a, b):
    def rd(p):
        out = {}
        try:
            for l in open(p, encoding='utf-8'):
                x = l.rstrip('\n').split('|')
                if len(x) == 3:
                    out[(x[0], x[1])] = x[2]
        except OSError:
            pass
        return out
    A, B = rd(a), rd(b)
    ch = sorted(set(k for k in set(A) | set(B) if A.get(k) != B.get(k)))
    names = sorted(set(k[0] for k in ch))
    print('changed=%s keys=%s n=%d' % (','.join('%s@%s' % k for k in ch) or '-', ','.join(names) or '-', len(ch)))


NEVER = ['quote_tracker', 'followups_quote', 'wa_auto_sends', 'quote_list_sends', 'wa_seen_marks', 'wa_media', 'wa_voice_calls',
         'wa_voice_acks', 'wa_offers', 'wa_offer_recipients', 'wa_offer_tests', 'wa_offer_events', 'quote_agent_makers',
         'quote_agent_copies', 'qfs_kept']


def counts(db):
    c = ro(db)
    try:
        r = []
        cnt = lambda t, w='', a=(): c.execute('SELECT COUNT(*) FROM [%s]%s' % (t, w), a).fetchone()[0] if has(c, t) else -1
        for t in ('quote_tracker', 'wa_auto_sends', 'quote_list_sends', 'wa_seen_marks', 'wa_media', 'wa_voice_calls', 'wa_voice_acks',
                  'wa_offers', 'wa_offer_recipients', 'wa_offer_tests', 'wa_offer_events', 'quote_agent_makers', 'quote_agent_copies'):
            r.append('%s=%d' % (t, cnt(t)))
        fk = has(c, 'followups') and 'kind' in cols(c, 'followups')
        r.append('followups_quote=%d' % (cnt('followups', " WHERE kind = 'quote'") if fk else -1))
        r.append('followups_quote_closed=%d' % (cnt('followups', " WHERE kind = 'quote' AND closed_at IS NOT NULL") if fk and 'closed_at' in cols(c, 'followups') else -1))
        r.append('qfs_kept=%d' % cnt('quote_followup_state', " WHERE k NOT LIKE 'list_last:%'"))
        r.append('qfs_all=%d' % cnt('quote_followup_state'))
        r.append('qfs_listlast=%d' % cnt('quote_followup_state', " WHERE k LIKE 'list_last:%'"))
        r.append('wa_offers_paused=%d' % cnt('wa_offers', " WHERE state = 'paused'"))
        r.append('wa_offers_running=%d' % cnt('wa_offers', " WHERE state = 'running'"))
        r.append('wa_offer_recipients_settled=%d' % cnt('wa_offer_recipients', " WHERE state IN ('sent', 'uncertain', 'failed', 'skipped')"))
        r.append('optouts_active=%d' % (cnt('contact_optouts', ' WHERE active = 1') if has(c, 'contact_optouts') and 'active' in cols(c, 'contact_optouts') else -1))
        sc = c.execute('SELECT * FROM _schema_checks ORDER BY 1, 2, 3').fetchall() if has(c, '_schema_checks') else []
        r.append('sc_rows=%d sc=%s' % (len(sc), sha16(repr(sc))))
        print(' '.join(r))
    finally:
        c.close()


def fell(a, b):
    out, unread = [], []
    for k in NEVER:
        x, y = kv(a, k), kv(b, k)
        if not re.fullmatch(r'-?\d+', x or '') or not re.fullmatch(r'-?\d+', y or ''):
            unread.append(k)
            continue
        if int(y) < int(x):
            out.append('%s:%s>%s' % (k, x, y))
    print('fell=%s unread=%s' % (','.join(out) or '-', ','.join(unread) or '-'))


def same(a, b, names):
    d = []
    for k in [x for x in names.split(',') if x]:
        x, y = kv(a, k), kv(b, k)
        if x != y or x == '':
            d.append('%s:%s/%s' % (k, x or '?', y or '?'))
    print('same=yes' if not d else 'same=diff:' + ','.join(d))


def optout(db, phone):
    c = ro(db)
    try:
        d9 = re.sub(r'\D', '', phone)[-9:]
        if not has(c, 'contact_optouts') or len(d9) != 9:
            print('n=-1')
            return
        print('n=%d' % c.execute("SELECT COUNT(*) FROM contact_optouts WHERE active = 1 AND phone LIKE ?", ('%' + d9,)).fetchone()[0])
    finally:
        c.close()


def listlast(db, day):
    c = ro(db)
    try:
        if not has(c, 'quote_followup_state'):
            print('n=-1')
            return
        print('n=%d' % c.execute("SELECT COUNT(*) FROM quote_followup_state WHERE k LIKE ?", ('list_last:' + day + ':%',)).fetchone()[0])
    finally:
        c.close()


def flags(src, db, jobs):
    t = open(src, encoding='utf-8', errors='replace').read()
    row, st = storerow(db)
    want, fl = [], []
    for j in [x for x in jobs.split(',') if x]:
        m = re.findall(r"^\s*'" + re.escape(j) + r"'\s*=>\s*\[[^\n]*'flag'\s*=>\s*'([a-z_]+)'", t, re.M)
        if len(m) != 1:
            fl.append('%s:?:?' % j)
            continue
        on = 1 if truthy(row.get(m[0])) else 0
        fl.append('%s:%s:%d' % (j, m[0], on))
        if on:
            want.append(j)
    print('want=%s flags=%s row=%s' % (','.join(want) or '-', ','.join(fl), st))


# ── the logs ──────────────────────────────────────────────────────────────────────────────────────────────────────────
MON = {m: i for i, m in enumerate(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], 1)}
LONG = re.compile(r'(?<![\d.])\d{9,}(?!\d)')


def naive(y, mo, d, h, mi, s):
    return calendar.timegm((y, mo, d, h, mi, s, 0, 0, 0))


def stamp_of(line, kind):
    """(epoch-as-if-UTC, zone-or-None, rest) for a line's own leading stamp, or None."""
    if kind in ('lines', 'phplog', 'server'):
        m = re.match(r'^\[(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\s+(UTC))?\]\s?', line)
        if m:
            t = naive(*[int(x) for x in m.groups()[:6]])
            return t, ('utc' if m.group(7) else None), line[m.end():]
        m = re.match(r'^\[(\d{2})-([A-Z][a-z]{2})-(\d{4}) (\d{2}):(\d{2}):(\d{2}) ([A-Za-z_/+0-9-]+)\]\s?', line)
        if m and m.group(2) in MON:
            t = naive(int(m.group(3)), MON[m.group(2)], int(m.group(1)), int(m.group(4)), int(m.group(5)), int(m.group(6)))
            z = m.group(7)
            zone = 'utc' if z in ('UTC', 'GMT', 'Z') else ('kampala' if z in ('Africa/Kampala', 'EAT') else None)
            return t, zone, line[m.end():]
        m = re.match(r'^\[[A-Z][a-z]{2} ([A-Z][a-z]{2}) +(\d{1,2}) (\d{2}):(\d{2}):(\d{2}) (\d{4})\]\s?', line)
        if m and m.group(1) in MON:
            t = naive(int(m.group(6)), MON[m.group(1)], int(m.group(2)), int(m.group(3)), int(m.group(4)), int(m.group(5)))
            return t, None, line[m.end():]
    return None


def when(v):
    """A JSON stamp: (epoch-as-if-UTC, zone-or-None) — epoch seconds (or milliseconds) carry their own zone."""
    if isinstance(v, bool):
        return None
    if isinstance(v, (int, float)) or (isinstance(v, str) and re.fullmatch(r'\d{9,13}', v.strip())):
        x = int(float(v))
        return (x // 1000 if x > 100000000000 else x), 'utc'
    if not isinstance(v, str):
        return None
    m = re.match(r'^\s*(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?(\s*(?:UTC|Z|\+00:?00))?', v)
    if not m:
        return None
    t = naive(int(m.group(1)), int(m.group(2)), int(m.group(3)), int(m.group(4)), int(m.group(5)), int(m.group(6) or 0))
    return t, ('utc' if m.group(7) else None)


def records(path, kind):
    """[(epoch-as-if-UTC or None, zone or None, judged text, raw)]"""
    out = []
    if kind == 'activitydb':
        c = ro(path)
        try:
            if not has(c, 'activity_log'):
                return out
            for (j,) in c.execute('SELECT data FROM activity_log ORDER BY id'):
                try:
                    r = json.loads(j)
                except (TypeError, ValueError):
                    continue
                if not isinstance(r, dict):
                    continue
                st = None
                for k in ('time', 'created_at', 'timestamp', 'at', 'date'):
                    if k in r and when(r[k]) is not None:
                        st = when(r[k])
                        r = {x: y for x, y in r.items() if x != k}
                        break
                out.append((st[0] if st else None, st[1] if st else None, canon(r), ''))
        finally:
            c.close()
        return out
    try:
        txt = open(path, encoding='utf-8', errors='replace').read()
    except OSError:
        return None
    if kind in ('whlog', 'qwa', 'activity'):
        try:
            d = json.loads(txt)
        except ValueError:
            return []
        if not isinstance(d, list):
            return []
        sk = {'whlog': 'received_at', 'qwa': 'time', 'activity': 'time'}[kind]
        jk = {'whlog': ('message', 'data'), 'qwa': ('msg',), 'activity': ('title', 'detail')}[kind]
        for r in d:
            if not isinstance(r, dict):
                continue
            st = when(r.get(sk))
            out.append((st[0] if st else None, st[1] if st else None, canon([r.get(k) for k in jk]), ''))
        return out
    if kind == 'overdue':
        try:
            d = json.loads(txt)
        except ValueError:
            return []
        if not isinstance(d, dict):
            return []
        st = when(d.get('started_at'))
        o = d.get('output')
        o = o if isinstance(o, str) else canon(o)
        o = '\n'.join(re.sub(r'^\[\d{2}:\d{2}:\d{2}\]\s?', '', l) for l in o.split('\n'))
        out.append((st[0] if st else None, st[1] if st else None, o, ''))
        return out
    if kind == 'whole':
        for l in txt.split('\n'):
            if l.strip():
                out.append((-1, 'utc', l, l))
        return out
    cur = None
    for l in txt.split('\n'):
        s = stamp_of(l, kind)
        if s is not None:
            if cur:
                out.append(tuple(cur))
            cur = [s[0], s[1], s[2], l]
        elif cur is not None:
            if l.strip():
                cur[2] += '\n' + l
                cur[3] += '\n' + l
        elif l.strip():
            out.append((None, None, l, l))
    if cur:
        out.append(tuple(cur))
    return out


def lines_of(p):
    try:
        return [l.rstrip('\n') for l in open(p, encoding='utf-8') if l.strip()]
    except OSError:
        return []


def logs(argv):
    cu, forms_f, names_f, excl_f, ctrl_f, frm = int(argv[0]), argv[1], argv[2], argv[3], argv[4], argv[5]
    rest = argv[6:]
    opt_re = None
    if rest[:1] == ['--optout-before']:
        opt_re = re.compile(open(rest[1], encoding='utf-8').read().strip())
        rest = rest[2:]
    forms = lines_of(forms_f)
    names = lines_of(names_f)
    excl = [l.split('\t', 1) for l in lines_of(excl_f) if '\t' in l]
    ctrl = [(a, re.compile(b)) for a, b in (l.split('\t', 1) for l in lines_of(ctrl_f) if '\t' in l)]
    T = {'sel': 0, 'ambig': 0, 'fixed': 0, 'num': 0, 'long': 0, 'names': 0, 'optout': 0, 'undated': 0, 'missing': 0}
    TE, TC = {}, {}
    for spec in rest:
        p = spec.rsplit(':', 3)
        if len(p) != 4:
            print('file=? spec=bad')
            T['missing'] += 1
            continue
        path, kind, zone, tag = p
        certain = zone.endswith('!')   # the writer fixes the zone: a naive stamp is in ZONE, never the other
        zone = zone.rstrip('!')
        recs = records(path, kind) if (kind == 'activitydb' and os.path.isfile(path)) or kind != 'activitydb' else None
        if recs is None:
            print('file=%s kind=%s exists=0' % (tag, kind))
            T['missing'] += 1
            continue
        off = KAMPALA if zone == 'kampala' else 0
        c = {'sel': 0, 'ambig': 0, 'fixed': 0, 'num': 0, 'long': 0, 'names': 0, 'optout': 0, 'undated': 0}
        ce, cc = {}, {}
        for t, z, judged, raw in recs:
            if t is None:
                c['undated'] += 1
                continue
            if t == -1:
                after = True
            else:
                zz = z or zone
                real = t - (KAMPALA if zz == 'kampala' else 0)
                after = real >= cu
                if z is None:
                    other = t - (0 if zone == 'kampala' else KAMPALA)
                    if (other >= cu) != after:
                        c['fixed' if certain else 'ambig'] += 1
            if frm == 'after' and not after:
                continue
            if opt_re is not None and not after and opt_re.search(raw or judged):
                c['optout'] += 1
                continue
            c['sel'] += 1
            if any(f in judged for f in forms):
                c['num'] += 1
            if LONG.search(judged):
                c['long'] += 1
            lab = next((a for a, w in excl if w in judged), None)
            if lab is not None:
                ce[lab] = ce.get(lab, 0) + 1
            elif any(n in judged for n in names):
                c['names'] += 1
            for a, rx in ctrl:
                if rx.search(judged):
                    cc[a] = cc.get(a, 0) + 1
        for k in c:
            T[k] += c[k]
        for k, v in ce.items():
            TE[k] = TE.get(k, 0) + v
        for k, v in cc.items():
            TC[k] = TC.get(k, 0) + v
        f = lambda d: ','.join('%s:%d' % kv_ for kv_ in sorted(d.items())) or '-'
        print('file=%s kind=%s exists=1 records=%d sel=%d undated=%d ambig=%d fixed=%d num=%d long=%d names=%d optout=%d excl=%s ctrl=%s' % (
            tag, kind, len(recs), c['sel'], c['undated'], c['ambig'], c['fixed'], c['num'], c['long'], c['names'], c['optout'], f(ce), f(cc)))
    f = lambda d: ','.join('%s:%d' % kv_ for kv_ in sorted(d.items())) or '-'
    print('total sel=%d undated=%d ambig=%d fixed=%d num=%d long=%d names=%d optout=%d missing=%d excl=%s ctrl=%s' % (
        T['sel'], T['undated'], T['ambig'], T['fixed'], T['num'], T['long'], T['names'], T['optout'], T['missing'], f(TE), f(TC)))


def phones4(src, out):
    nums = []
    for l in lines_of(src):
        d = re.sub(r'\D', '', l)
        if re.fullmatch(r'256\d{9}', d):
            nums.append(d)
    forms = set()
    for d in sorted(set(nums)):
        nat = '0' + d[3:]
        forms.update(['+' + d, d, nat, '%s %s %s' % (nat[:4], nat[4:7], nat[7:]), d[-9:]])
    fd = os.open(out, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, 'w', encoding='utf-8') as f:
        f.write(''.join(x + '\n' for x in sorted(forms)))
    print('n=%d forms=%d' % (len(set(nums)), len(forms)))


def blocks(path, ver):
    lines = open(path, encoding='utf-8', errors='replace').read().split('\n')
    v = re.escape('deploy-' + ver + '.sh')

    def kind(l):
        c = l.strip()
        if re.search(v + r' --rollback', c): return 'rollback'
        if 'sales-followup.sh off' in c: return 'sf-off'
        if re.search(r'git checkout [0-9a-f]{7}', c) and 'deploy-hybrid.sh' in c: return 'putback'
        if re.search(v + r' --redeploy', c): return 'redeploy'
        if re.search(v + r' --after-only', c): return 'after'
        if re.search(v, c): return 'deploy'
        if 'quarantine-stray-store.sh --check' in c: return 'qcheck'
        if 'sales-followup-report.sh' in c: return 'report'
        if 'sales-followup.sh' in c: return 'sf'
        if c.startswith('less '): return 'less'
        return 'other'
    two = re.compile(v + r'|quarantine-stray-store\.sh|deploy-hybrid\.sh|sales-followup(?:-report)?\.sh')
    bl, cur = [], []
    for l in lines + ['']:
        if l.startswith('      ') and not l.startswith('       '):
            if re.match(r'^      (cd |\( cd |\( umask |less |bash )', l):
                k = kind(l)
                cur.append(k)
                if len(two.findall(l)) > 1:
                    cur.extend(['other'] * (len(two.findall(l)) - 1))
            else:
                cur.append('text')
        else:
            if cur:
                bl.append(cur)
            cur = []
    bl = [b for b in bl if any(k != 'text' for k in b)]
    items = lambda b: sum(1 for k in b if k != 'text')
    back = ('rollback', 'putback', 'sf-off')
    mixed = sum(1 for b in bl if any(k in back for k in b) and items(b) > 1)
    print('blocks=%d mixed=%d kinds=%s' % (len(bl), mixed, '/'.join('+'.join(k for k in b if k != 'text') for b in bl) or '-'))


def stcmp(a, e):
    A = dict(x.split('=', 1) for x in a.split() if '=' in x)
    E = dict(x.split('=', 1) for x in e.split() if '=' in x)
    bad = []
    for k, v in E.items():
        g = A.get(k, 'none')
        ok = False
        for w in v.split('|'):
            if g == w or w == 'any' or (w == 'judged' and (g == 'pass' or g.startswith('notyet'))) or (w == 'notyet' and g.startswith('notyet')):
                ok = True
        if not ok:
            bad.append('%s:%s/%s' % (k, g, v))
    print('same' if not bad else ' '.join(bad))


def rec_procs(path):
    data = open(path, 'rb').read()
    for r in data.split(b'\x1e'):
        if not r:
            continue
        yield [x.decode('utf-8', 'replace') for x in r.split(b'\x1f')[:-1]] if r.endswith(b'\x1f') else [x.decode('utf-8', 'replace') for x in r.split(b'\x1f')]


def lmaudit(path):
    n, modes, other = 0, {}, 0
    try:
        for c in rec_procs(path):
            for i, x in enumerate(c):
                if i > 0 and c[i - 1] == '-r' and x.startswith('// LM_PHP'):
                    m = c[i + 1] if i + 1 < len(c) else '?'
                    n += 1
                    modes[m] = modes.get(m, 0) + 1
                    if m not in ('count', 'sinks'):
                        other += 1
    except OSError:
        pass
    print('lm=%d modes=%s other=%d' % (n, ','.join('%s:%d' % x for x in sorted(modes.items())) or '-', other))


def lmscript(path):
    t = open(path, encoding='utf-8').read()
    m = re.search(r"\nLM_HUNKS=\"\$\(cat <<'HUNKS'\n(.*?)\nHUNKS\n\)\"\n", t, re.S)
    if not m:
        print('lmscript=none')
        return
    for l in m.group(1).split('\n'):
        if l.strip():
            print(l)


MARK = re.compile(r'5\.18\.105\b.*root docs/74')


def git(repo, *a):
    return subprocess.run(['git', '-C', repo] + list(a), stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True).stdout.decode('utf-8', 'replace')


def gitlm(repo, base, pin, plugin):
    names = git(repo, 'diff', '--no-renames', '--name-only', '--diff-filter=M', base, pin, '--', plugin).split('\n')
    files = sorted(x[len(plugin) + 1:] for x in names if x.startswith(plugin + '/'))
    files = [f for f in files if not f.startswith('tests/') and not f.endswith('.md') and f != 'manifest.json']
    for f in files:
        d = git(repo, 'diff', '-U0', '--no-renames', base, pin, '--', plugin + '/' + f)
        pt = git(repo, 'show', '%s:%s/%s' % (pin, plugin, f)).split('\n')
        hs, cur = [], None
        for l in d.split('\n'):
            m = re.match(r'@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@', l)
            if m:
                cur = {'o': [], 'n': [], 'at': int(m.group(1))}
                hs.append(cur)
            elif cur is not None and not l.startswith(('+++', '---')):
                if l.startswith('-'):
                    cur['o'].append(l[1:])
                elif l.startswith('+'):
                    cur['n'].append(l[1:])
        for h in hs:
            marks = [l for l in h['n'] if MARK.search(l)]
            new = [l for l in h['n'] if not MARK.search(l)]
            old = h['o']
            if not new and not old:
                continue
            ids = re.findall(r'LN-\d\d', ' '.join(marks))
            if not ids and old:
                top = h['at'] - 1
                for l in reversed(pt[max(0, top - 30):top]):
                    if MARK.search(l):
                        ids = re.findall(r'LN-\d\d', l)
                        break
            lab = '+'.join(ids) if ids and old else '*'
            sh = lambda b: hashlib.sha256('\n'.join(b).encode('utf-8')).hexdigest() if b else '-'
            print('%s|%s|%d|%s|%d|%s' % (f, lab, len(old), sh(old), len(new), sh(new)))


def code_lines(t):
    for n, l in enumerate(t.split('\n'), 1):
        s = l.lstrip()
        if s.startswith('#') or s.startswith('//'):
            continue
        yield n, l


def verbs(path):
    t = open(path, encoding='utf-8', errors='replace').read()
    lk_ = sum(1 for l in t.split('\n') if l.startswith("    case 'lk':"))
    qft = wrp = vb = 0
    for n, l in code_lines(t):
        if 'qf_tool ' in l and 'qf_tool()' not in l:
            for m in re.finditer(r'qf_tool "\$[A-Za-z0-9_]+(?:/ss)?" ([A-Za-z-]+)', l):
                if m.group(1) not in ('show', '--check', 'doctor'):
                    qft += 1
        if '$QF_WRAPPER' in l and not l.lstrip().startswith('echo '):
            wrp += 1
        if re.search(r'sales[-_]followup(\.php|\.sh)?"? (on|off|skip|set|erase)\b', l) and not l.lstrip().startswith('echo ') \
                and '--clear-switches)' not in l:
            vb += 1
        if re.search(r'"\$(?:IN_CONTAINER|d/code|sub/code|inst|P3B_PIN|P3B_BASE)/\$QF_TOOL" (on|off|skip|set|erase)\b', l):
            vb += 1
    print('lk=%d qftool=%d wrapper=%d verbs=%d' % (lk_, qft, wrp, vb))


def hdr(path, ver):
    t = open(path, encoding='utf-8', errors='replace').read()
    head = t.split('\nset -uo pipefail\n', 1)[0]
    m = re.search(r'\n(# The deploy\. Run as root.*?\n# The rollback is a separate command[^\n]*)', head, re.S)
    if not m:
        print('cmds=0 kinds=- alone=0 back=0 tee=0 outside=-1')
        return
    blk = m.group(1)
    cmd = re.compile(r'^#   (?:cd|\( cd) ')
    lines = [l for l in blk.split('\n') if cmd.match(l)]
    allc = [l for l in head.split('\n') if cmd.match(l)]
    v = re.escape('deploy-' + ver + '.sh')
    kinds, alone, back, tee = [], 0, 0, 0
    for l in lines:
        c = l[4:]
        if re.search(v + r' --after-only', c):
            k = 'after'
        elif re.search(v + r'(?: |$)', c):
            k = 'deploy'
        elif 'sales-followup-report.sh' in c:
            k = 'report'
        elif re.search(r'sales-followup\.sh show$', c):
            k = 'show'
        else:
            k = 'other'
        kinds.append(k)
        n = len(re.findall(v + r'|sales-followup(?:-report)?\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh', c))
        if n == 1:
            alone += 1
        if re.search(r'--rollback|--put-back|--redeploy|--clear-switches|git checkout [0-9a-f]{7}', c):
            back += 1
        if re.search(v + r'(?: --after-only)? 2>&1 \| \( umask 077; exec tee /root/dnb-' + re.escape(ver) + r'/(?:deploy|after)-\$\(date -u \+%Y%m%dT%H%M%SZ\)\.log \)$', c):
            tee += 1
    print('cmds=%d kinds=%s alone=%d back=%d tee=%d outside=%d' % (len(lines), ','.join(kinds) or '-', alone, back, tee, len(allc) - len(lines)))


RETIRED = ['QF-G4', 'QF-RB1', 'QF-RB2', 'QF-RB3', 'VN-RB1', 'VN-RB2', 'VN-RB3', 'VN-RB4', 'VN-RB6', 'OF-RB1', 'AC-RB1',
           'QL-CA1', 'QL-LK1', 'QL-RB1', 'QL-RB2', 'QL-RB3']


def retired(path):
    t = open(path, encoding='utf-8', errors='replace').read()
    pr = re.compile(r'\b(ok|bad|note|stop|echo|warn)\s+"')
    hits = set()
    n = 0
    cl = list(code_lines(t))
    for i, (_, l) in enumerate(cl):
        if not pr.search(l) or 'retire' in l:
            continue
        # a parenthesis this printed line opens and the next printed line closes with 'retired)' says the codes are
        # retired (F's names paragraph: "(its QL-CA1, QL-LK1" / "and QL-RB1–QL-RB3 retired);"), not a line under their names
        nx = cl[i + 1][1] if i + 1 < len(cl) else ''
        if l.count('(') > l.count(')') and pr.search(nx) and re.search(r'\bretired\)', nx):
            continue
        for c in RETIRED:
            if re.search(r'(?<![A-Z-])' + re.escape(c) + r'(?![0-9])', l):
                n += 1
                hits.add(c)
    opt = sum(1 for l in t.split('\n') if re.match(r'^    --rollback-unpriced-pending\)', l))
    print('printed=%d codes=%s opt=%d' % (n, ','.join(sorted(hits)) or '-', opt))


def secret(kf, out):
    try:
        st = os.stat(kf)
    except OSError:
        print('mode=- key=none')
        return
    k = ''
    try:
        for l in open(kf, encoding='utf-8'):
            if l.startswith('LK_KEY='):
                k = l[len('LK_KEY='):].strip()
    except OSError:
        pass
    ok = bool(re.fullmatch(r'[0-9a-f]{48}', k))
    if ok:
        fd = os.open(out, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            f.write(k + '\n')
    print('mode=%o key=%s' % (st.st_mode & 0o777, 'read' if ok else 'none'))


cmd = sys.argv[1] if len(sys.argv) > 1 else ''
a = sys.argv[2:]
try:
    if cmd == 'lk':
        lk(a)
    elif cmd == 'lkdiff':
        lkdiff(a[0], a[1])
    elif cmd == 'counts':
        counts(a[0])
    elif cmd == 'fell':
        fell(a[0], a[1])
    elif cmd == 'same':
        same(a[0], a[1], a[2])
    elif cmd == 'optout':
        optout(a[0], a[1])
    elif cmd == 'listlast':
        listlast(a[0], a[1])
    elif cmd == 'flags':
        flags(a[0], a[1], a[2])
    elif cmd == 'logs':
        logs(a)
    elif cmd == 'phones4':
        phones4(a[0], a[1])
    elif cmd == 'blocks':
        blocks(a[0], a[1])
    elif cmd == 'stcmp':
        stcmp(a[0], a[1])
    elif cmd == 'lmaudit':
        lmaudit(a[0])
    elif cmd == 'lmscript':
        lmscript(a[0])
    elif cmd == 'gitlm':
        gitlm(a[0], a[1], a[2], a[3])
    elif cmd == 'verbs':
        verbs(a[0])
    elif cmd == 'hdr':
        hdr(a[0], a[1])
    elif cmd == 'retired':
        retired(a[0])
    elif cmd == 'secret':
        secret(a[0], a[1])
    elif cmd == 'kv':
        print(kv(a[0], a[1]))
    else:
        sys.stderr.write('rh105q.py: unknown mode\n')
        sys.exit(2)
except (sqlite3.Error, OSError, IndexError, ValueError, subprocess.CalledProcessError) as e:
    print('unread:%s' % type(e).__name__)
    sys.exit(3)

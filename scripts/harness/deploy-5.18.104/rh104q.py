#!/usr/bin/env python3
# 5.18.104's rehearsal: its own readers of the store and of the runs' records, beside the earlier releases' (rh103q.py's
# apps, cat and kv; rh104.py, derived from 5.18.103's rh103.py, for the copy audit). Written here from the rules the
# release and the script state (root docs/73 §H.2.15.1, §H.3.25, §H.4.18, §H.5.14), in Python, over the store opened
# READ-ONLY — never through the release's code — so that the script's own reading is set against another. Each prints
# counts, states or table names, never a name, a phone, an e-mail, an amount, a staff id or a key. Modes:
#   tables DB T1,T2,… C1,C2,…   how many of the given tables (089–091's, read from Git by the caller) and of followups'
#                               given columns the store holds, and the positive control: followups and wa_messages read
#   mig DB F1,F2,…              how many of the given migration files the store's ledger (_migrations) holds; its rows
#   n2 DB                       QF-N2's counts: open follow-ups, approved drafts by age, follow-ups with 3+ failed events
#   n7 DB                       QF-N7's: active accounts with a phone, by role ("role:n,…" or "-")
#   chan DB                     QF-N1's channels= part: wa_channels by status and ai_enabled ("status/aiN:n,…" or "-")
#   g5 DB                       VN-G5's first half: inbound [AUDIO] rows over 30 days, by department channel
#   g6 DB                       VN-G6's: ai.media and ai.voice_ack events ("media=<n> ack=<n>", or events=absent)
#   staffraw DB                 the retailers rows: active, active with a phone of record (non-empty), admins with one
#   scexp                       stdin: a migration's text; prints "<file>:<expected>" of its _schema_checks INSERT
#   section FILE TITLE          whether the sheet FILE holds a section "== TITLE…" and how many lines follow it
#   seckeys FILE TITLE NAMES    that section's body read as key names (whitespace-separated words, the script's
#                               QF_CLEARED): present|absent lines=<n> keys=<names found>/<names given> none=<1 when the
#                               body is the script's "none reported", else 0> other=<words that are none of NAMES>.
#                               Counts only — the names are compared, never printed
#   qfaudit REC LIVE            the fake docker's exec record of one run: 5.18.104's own readings — the PHP processes whose -r
#                               source is one of the script's four (it begins "// QF_PHP", "// VN_PHP", "// OF_PHP" or
#                               "// AC_PHP"), by the mode the argument after the source names. LIVE (the caller's, read from the
#                               script: tag:mode,…) are the modes that read live files by design — the pin's or the installed
#                               code, the live settings, the live vault to cut a copy — judged by the live vault staying as it
#                               was; every other mode reads a copy, judged by its vault — and so does a script of a reading's
#                               own code root run as a file (the switch tool or the report on its copy: "run:<name>"):
#                               env=<n> (DN_VAULT_FILE given by docker exec -e, in this run's folders) put=<n> (its source sets its own with putenv, as QF_PHP's copy
#                               modes do) bare=<n> (neither). Prints copy=<n> env=<n> put=<n> bare=<n> baremodes=<…> live=<n>
#                               livemodes=<…> (tag:mode, sorted)
#   stcmp ACTUAL EXPECTED       two "code:step=state …" lines compared pair by pair, an expected "judged" met by a PASS or a
#                               NOT_YET, "notyet" by any NOT_YET: same, or the pairs that differ (got/want)
#   kv LINE KEY                 one key=value field of a line
#   blocks FILE                 the copyable blocks of a run's output: every run of consecutive lines indented by exactly six
#                               spaces is one block (RH4-fix1: a line that is no command no longer ends a block — copying the
#                               run copies it all), each line a command (its kind), a line of the by-hand put-back ("byhand":
#                               an UPDATE statement, or a line of bare key names), or text. Blocks with no command and no
#                               by-hand line are not counted. blocks=<n> mixed=<n> byhand=<n> kinds=<k+k/k/…> — mixed counts
#                               the blocks that hold a rollback, a put-back, the tool's off all --rollback or the by-hand
#                               put-back beside any other command (the by-hand lines of one block are one item; root docs/44
#                               §16.9: a deploy and its rollback are never one copyable block; ruling R17: the by-hand
#                               put-back a block of its own); byhand counts the blocks that hold by-hand lines. A command line
#                               naming two of the scripts is two commands (a line is the smallest copyable unit)
#   stepline FILE               the order F's summary gives the operator's steps in ("then, for each step in turn — …"):
#                               steps=<a,b,…> optional=<x,y>
#   ledger DB NOW               wa_auto_sends as this rehearsal reads it: rows, quote claims sent, voice_ack claims, open claims,
#                               the seconds from the latest sent quote claim to the first voice_ack claim after it on its
#                               handset, and the orphans (a claim still open 900 s or more, with no later claim on its handset
#                               900 s or more after it), and the latest sent quote claim's moment (quote_at, unix)
#   tracker DB QUOTE_KEY        one quote_tracker row by its key: status, hold, its follow-up's kind and close reason, and the
#                               drafts of its follow-up approved/all
#   keysset DB KYC NAMES        how many of the comma-separated key names are set (not blank) in the settings file or the
#                               store row: set=<n> names=<the set ones>
#   keyval DB KYC ai_provider   the assistant's provider as stored, in the settings file and in the store row (the one key
#                               it reads — no secret ever): file=<value|-> store=<value|->
#   admins DB KYC               quote_agent_cc_admins as stored, in the settings file and in the store row, by the script's own
#                               rule ($adm in AC_PHP: absent, 0, 1 or other — a setting, never a person's): file=<…> store=<…>
#   vlabels DB LABEL            104-6's reading of R4's labelled bodies (RH4-fix1), by the rule root docs/73 §H.3.25 states for
#                               VN-RB3 (an audio row joined to its message by wa_message_id, the body beginning with LABEL —
#                               the release's VoiceTranscription::LABEL, read by the caller): r4=<at rung reply> r3=<at any
#                               other rung>, and the voice turns left (ai.reply events pending or processing whose payload says
#                               origin voice): turns=<n> turn_ids=<ids, ascending, or ->
#   rbrows DB TEST MEDIA EVENT  104-6's plants as they stand (RH4-fix1): one wa_offer_tests row's state, one wa_media row's
#                               status/attempts/updated_at, one events row's status/attempts/next_retry_at (each "-" when the id
#                               is 0 or the row is gone): test=<…> media=<…> event=<…> (stamps with T for the space)
#   quotes DB QSE [NOW]         QF-N3's headline as this rehearsal reads the store: the quotations sent on WhatsApp (an
#                               outbound wa_messages row whose event is one of QSE's EVENTS and whose body carries one of its
#                               MARKERS with a number — QSE the pin's lib/QuoteSendEvidence.php, read as text), the earliest per
#                               number, in [NOW − D·24 h, NOW − 7 d] for D = 30, 90, on a conversation that is not staff's or a
#                               web session's; "neither" when no row of any direction follows it on a conversation of the same
#                               phone within 7 days and no followup_sends row does. h30=<neither>/<all> h90=<neither>/<all>.
#                               A simpler rule than the report's (a STOP, a person's chase, DishNet's own numbers): the
#                               rehearsal reads what its own fixtures ADD by it, before and after, never the store's whole count
#   mail DB MAILLOG [NOW]       AC-N2's counts as this rehearsal reads them, by AC-N2's own rules: MAILLOG (quote_mail.log) —
#                               p1=read|absent p1_sent/other/older/undated over 30 days (UTC); customer_email_log's quotation
#                               rows over 30 days by status (p3_by_status, "-" for none), and of the sent ones, the quote id held
#                               by a KYC application (p3_sent_kyc), by a quotes_log row or a lead (p3_sent_log_or_lead), or by
#                               none (p3_sent_none)
#   qexpect OPS-JSON            what the seed's quote and quote_mail ops add, by those two readers' rules: h30=<n>/<n> h90=<n>/<n>
#                               p1_sent=<n> p3_sent=<n> p3_none=<n> numbers=<n>
#   ogg                         a 7-second Ogg Opus voice note (silence frames, granules as RFC 7845 counts them), base64, for
#                               the stand-in Evolution's media answer
#   rb104 DB [A B]              104-6's reading of what the rollback's hooks act on, before and after it, by the rules root docs/73
#                               §H.2.16, §H.3.25 and §H.4.19 state (never through the release's code): Part F — open quote
#                               follow-ups (oq) and their pending or approved drafts (od), quote follow-ups closed as rollback
#                               (cr) and their drafts expired (ex); Part V — audio rows pending (ap) and settled
#                               skipped/switched_off (as), ai.media events pending, failed or processing (em), ai.voice_ack
#                               events pending or processing (ea), acknowledgements recorded skipped/switched_off (ks),
#                               wa_voice_calls rows (vc); Part O — offers running (or_) and paused (op), recipients claimed
#                               with a ledger claim (cl) and without (cn), tests still sending (ts); and, given two recipient ids,
#                               A's state/reason/sent_at-is-claimed_at/its ledger row's outcome and B's state/not_before-set.
#                               Counts and fixed states only. "absent" for a table not on the book
#   emids DB                    the ai.media events pending, failed or processing, by id (VN-RB2/VN-RB4's sheet line), ascending
#   offer DB OFFER [DIGITS]     one offer as 104-5o reads it (wa_offers' own columns, never a data blob): state=<state>
#                               hash=ok|bad (content_hash 64 hex) tests=<variant:state/ledger/echo,…> handsets=<n> byh=<n,n,…>
#                               (the queued-or-later recipients per handset, sorted counts — never a handset's name) rs=<state:n,…>
#                               ours=<recipients in the rehearsal's own range> stops=<rows with stop_at>; given DIGITS (a phone's
#                               international digits, never printed) who=<state>/<stop:set|->, else who=-
#   oclaims DB FROM TO          the ledger's offer claims (wa_auto_sends kind offer) claimed in [FROM, TO) (unix): n=<n> first=<unix|0>
#                               byh=<n,n,…> (per handset, sorted counts)
#   testled DB OFFER            the offer's tests' ledger rows (kind other, ref offer_test:<test id>): n=<n> sent=<n>
#   handsets DB KYC             the distinct handsets the department channels an offer may use name (evo_instance_sales and
#                               evo_instance_support, the settings file over the store row; accounts is never a candidate): n=<n>
#   relaycc TRANSCRIPT CLIENTS AGENTS   the fake relay's transcript: per session, in order, C (the one RCPT a client's, no Cc
#                               header naming an agent), CA (RCPT the client then one agent, the Cc header holding exactly that one
#                               address beyond the client's — the same message), or X — kinds=<k,k,…> sessions=<n>; every address
#                               compared lower-case, none printed
#   acdec DB QID,QID,…          P7's decision rows (quote_agent_copies) per quote id, in the given order: <path>:<code>[+…] or none
#   vcalls DB SINCE             Part V's rows since SINCE (unix): calls=<n> ok=<n> byrung=<rung:n,…> rows=<rung/status:n,…>
#                               esc=<wa.escalation events by media_worker> aiesc=<…by ai_reply_worker> turns=<ai.reply events with a
#                               media_id in their payload>
#   ca104 FILE                  104-7: a deploy run's QF-CA2, QF-CA3, VN-CA4, OF-CA3, QF-G1 baseline, VN-G6 and OF-G1 lines, read by
#                               the script's own words: <code>=ok|fail|none and the numbers each prints
#   sched4 DB                   104-7: the four jobs' master_schedule.json entries (name:last_run), and the other jobs' count
#   vhist DB                    104-7v: Part V's history by table and status (wa_media, calls, acks, ai.media, ai.voice_ack events)
#   claimat DB KIND MAX         104-7: the ledger's rows of KIND above id MAX: <id>:<mode>:<claimed_at epoch>,…
#   callsat DB MAX              104-7v: wa_voice_calls rows above id MAX: <id>:<created_at epoch>,…
#   tl FILE                     104-7: the fake docker's timeline of a run's moments: EVENT.N=<start>-<end> (whole seconds)
import sys, re, json, sqlite3


def ro(db):
    return sqlite3.connect('file:' + db + '?mode=ro', uri=True)


def has(con, t):
    return con.execute("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", (t,)).fetchone() is not None


def cols(con, t):
    return [r[1] for r in con.execute('PRAGMA table_info([%s])' % t)] if has(con, t) else []


def php_empty(v):
    if v is None or v is False:
        return True
    if isinstance(v, bool):
        return False
    if isinstance(v, (int, float)) and v == 0:
        return True
    if isinstance(v, str) and v in ('', '0'):
        return True
    if isinstance(v, (list, dict)) and not v:
        return True
    return False


def docrows(con, name):
    # a SqliteStore record list, as SqliteStore::load() reads it: the table is the file's name, a row per record
    t = re.sub(r'[^a-zA-Z0-9_]', '_', re.sub(r'\.json$', '', name))
    if not has(con, t):
        return None
    out = []
    for (d,) in con.execute('SELECT data FROM [%s] ORDER BY id ASC' % t):
        try:
            r = json.loads(d)
        except (TypeError, ValueError):
            continue
        if isinstance(r, dict):
            out.append(r)
    return out


cmd = sys.argv[1] if len(sys.argv) > 1 else ''
if cmd == 'tables':
    con = ro(sys.argv[2])
    try:
        ts = [x for x in sys.argv[3].split(',') if x]
        cs = [x for x in (sys.argv[4] if len(sys.argv) > 4 else '').split(',') if x]
        fc = cols(con, 'followups')
        n = sum(1 for t in ts if has(con, t))
        c = sum(1 for x in cs if x in fc)
        ctl = sum(1 for t in ('followups', 'wa_messages') if has(con, t))
        print('t104=%d/%d cols=%d/%d control=%d/2 tables=%d' % (n, len(ts), c, len(cs), ctl,
              con.execute("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'").fetchone()[0]))
    finally:
        con.close()
elif cmd == 'mig':
    con = ro(sys.argv[2])
    try:
        fs = [x for x in sys.argv[3].split(',') if x]
        if not has(con, '_migrations'):
            print('mig=absent rows=0')
        else:
            n = sum(1 for f in fs if con.execute('SELECT COUNT(*) FROM _migrations WHERE filename = ?', (f,)).fetchone()[0] > 0)
            print('mig=%d/%d rows=%d' % (n, len(fs), con.execute('SELECT COUNT(*) FROM _migrations').fetchone()[0]))
    finally:
        con.close()
elif cmd == 'n2':
    con = ro(sys.argv[2])
    try:
        if not has(con, 'followups'):
            print('followups=absent')
        else:
            op = con.execute('SELECT COUNT(*) FROM followups WHERE closed_at IS NULL').fetchone()[0]
            ages = []
            if has(con, 'followup_drafts'):
                for lb, lo, hi in (('<1d', 0, 1), ('1-7d', 1, 7), ('>7d', 7, 100000)):
                    ages.append('%s:%d' % (lb, con.execute(
                        "SELECT COUNT(*) FROM followup_drafts WHERE status = 'approved' AND julianday('now') - julianday(created_at) >= ? "
                        "AND julianday('now') - julianday(created_at) < ?", (lo, hi)).fetchone()[0]))
            f3 = con.execute("SELECT COUNT(*) FROM (SELECT followup_id FROM followup_events WHERE event = 'failed' GROUP BY followup_id "
                             "HAVING COUNT(*) >= 3)").fetchone()[0] if has(con, 'followup_events') else -1
            print('open=%d approved=%s failed3=%d' % (op, ','.join(ages), f3))
    finally:
        con.close()
elif cmd == 'n7':
    con = ro(sys.argv[2])
    try:
        rows = docrows(con, 'retailers.json')
        by = {}
        for r in rows or []:
            if php_empty(r.get('is_active')) or str(r.get('phone') or '').strip() == '':
                continue
            role = r.get('role')
            rl = re.sub(r'[^a-z_]', '', ('none' if role is None else str(role)).lower())
            by[rl] = by.get(rl, 0) + 1
        print(','.join('%s:%d' % (k, by[k]) for k in sorted(by)) or '-')
    finally:
        con.close()
elif cmd == 'chan':
    con = ro(sys.argv[2])
    try:
        if not has(con, 'wa_channels'):
            print('-')
        else:
            cc = cols(con, 'wa_channels')
            sel = ('status' if 'status' in cc else "'-'") + ', ' + ('ai_enabled' if 'ai_enabled' in cc else "'-'")
            out = []
            for s, a, n in con.execute('SELECT ' + sel + ' AS s, COUNT(*) FROM wa_channels GROUP BY 1, 2'):
                out.append('%s/ai%s:%d' % (re.sub(r'[^a-z0-9_-]', '', str(s).lower()), '' if a is None else a, n))
            print(','.join(out) or '-')
    finally:
        con.close()
elif cmd == 'g5':
    con = ro(sys.argv[2])
    try:
        if not has(con, 'wa_messages') or not has(con, 'wa_conversations'):
            print('audio=absent')
        else:
            by = {}; tot = 0
            for (ch,) in con.execute("SELECT c.channel FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id "
                                     "WHERE m.direction = 'in' AND m.body = '[AUDIO]' AND m.created_at >= datetime('now', '-30 days')"):
                tot += 1
                k = re.sub(r'[^a-z0-9_.:-]', '', str(ch or '').lower())
                k = k if k in ('sales', 'support', 'account') else 'other'
                by[k] = by.get(k, 0) + 1
            print('audio=%d by_channel=%s' % (tot, ','.join('%s:%d' % (k, by[k]) for k in sorted(by)) or '-'))
    finally:
        con.close()
elif cmd == 'g6':
    con = ro(sys.argv[2])
    try:
        if not has(con, 'events'):
            print('events=absent media=0 ack=0')
        else:
            m = con.execute("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media'").fetchone()[0]
            a = con.execute("SELECT COUNT(*) FROM events WHERE event_type = 'ai.voice_ack'").fetchone()[0]
            print('events=present media=%d ack=%d' % (m, a))
    finally:
        con.close()
elif cmd == 'staffraw':
    con = ro(sys.argv[2])
    try:
        act = ph = adm = 0
        for r in docrows(con, 'retailers.json') or []:
            if php_empty(r.get('is_active')):
                continue
            act += 1
            if str(r.get('phone') or '').strip() != '':
                ph += 1
                if not php_empty(r.get('is_admin')):
                    adm += 1
        print('active=%d phone=%d admins_with_phone=%d' % (act, ph, adm))
    finally:
        con.close()
elif cmd == 'scexp':
    t = sys.stdin.read()
    m = re.search(r"INSERT INTO _schema_checks \(migration, expected, found\)\s*SELECT '([0-9]{3}_[a-z0-9_]+\.sql)', ([0-9]+),", t)
    print('%s:%s' % (m.group(1), m.group(2)) if m else '?')
elif cmd == 'section':
    try:
        lines = open(sys.argv[2], encoding='utf-8', errors='replace').read().split('\n')
    except OSError:
        lines = []
    at = [i for i, l in enumerate(lines) if l.startswith('== ' + sys.argv[3])]
    if not at:
        print('absent')
    else:
        n = 0
        for l in lines[at[0] + 1:]:
            if l.startswith('== '):
                break
            if l.strip():
                n += 1
        print('present:%d' % n)
elif cmd == 'seckeys':
    try:
        lines = open(sys.argv[2], encoding='utf-8', errors='replace').read().split('\n')
    except OSError:
        lines = []
    names = [x for x in sys.argv[4].split(',') if x]
    at = [i for i, l in enumerate(lines) if l.startswith('== ' + sys.argv[3])]
    if not at:
        print('absent lines=0 keys=0/%d none=0 other=0' % len(names))
    else:
        body = []
        for l in lines[at[0] + 1:]:
            if l.startswith('== '):
                break
            if l.strip():
                body.append(l.strip())
        none = 1 if body == ['none reported'] else 0
        words = [] if none else [w for l in body for w in l.split()]
        print('present lines=%d keys=%d/%d none=%d other=%d' % (len(body), sum(1 for k in names if k in words), len(names), none,
                                                               sum(1 for w in words if w not in names)))
elif cmd == 'qfaudit':
    try:
        data = open(sys.argv[2], 'rb').read()
    except OSError:
        data = b''
    live_modes = set(x for x in (sys.argv[3] if len(sys.argv) > 3 else '').split(',') if x)
    qfroot = re.compile(r'^/tmp/dnb-5\.18\.104-cih-[^/]+/qf-[a-z0-9]+-[0-9]+/code/')
    copy = env_n = put = bare = live = 0; bmodes = []; lmodes = []
    for rec in data.split(b'\x1e'):
        a = [x.decode('utf-8', 'replace') for x in rec.split(b'\x1f')]
        if a and a[-1] == '':
            a = a[:-1]
        if not a or a[0] != 'exec':
            continue
        i, env = 1, {}
        while i < len(a) and a[i].startswith('-'):
            if a[i] in ('-u', '-e', '-w'):
                if a[i] == '-e' and i + 1 < len(a) and '=' in a[i + 1]:
                    k, v = a[i + 1].split('=', 1); env[k] = v
                i += 2
            else:
                i += 1
        c = a[i + 1:]
        if not c or c[0] != 'php':
            continue
        src = ''; mode = '-'
        for k, x in enumerate(c):
            if k > 0 and c[k - 1] == '-r':
                src = x
                if k + 1 < len(c):
                    mode = re.sub(r'[^a-z0-9_]', '', c[k + 1])[:16] or '-'
                break
        m = re.match(r'// (QF|VN|OF|AC)_PHP\b', src)
        if m:
            tm = m.group(1).lower() + ':' + mode
        else:
            # a script of a reading's own code root run as a file — the switch tool or the report on its copy (qf_tool, QF-N3)
            f = [x for x in c[1:] if qfroot.match(x) and x.endswith('.php')]
            if not f:
                continue
            tm = 'run:' + re.sub(r'[^a-z0-9_]', '', f[0].rsplit('/', 1)[-1][:-4])[:24]
        if tm in live_modes:
            live += 1; lmodes.append(tm)
            continue
        copy += 1
        # the modes whose source sets their own vault first: "if (in_array($mode, [<modes>], true)) … putenv('DN_VAULT_FILE=…"
        # (QF_PHP's copy modes, and VN_PHP's, OF_PHP's and AC_PHP's), read from the source this process was given
        own = set()
        for mm in re.finditer(r"in_array\(\$mode, \[([^\]]*)\], true\)\)\s*(?:\{\s*\$vd = [^;]*;\s*)?putenv\('DN_VAULT_FILE=", src):
            own |= set(re.findall(r"'([a-z0-9_]+)'", mm.group(1)))
        if env.get('DN_VAULT_FILE', '').startswith('/tmp/dnb-5.18.104-'):
            env_n += 1
        elif mode in own:
            put += 1
        else:
            bare += 1; bmodes.append(tm)
    print('copy=%d env=%d put=%d bare=%d baremodes=%s live=%d livemodes=%s' % (
        copy, env_n, put, bare, ','.join(sorted(set(bmodes))) or '-', live, ','.join(sorted(set(lmodes))) or '-'))
elif cmd == 'expect':
    # expect OPS-JSON… — what the seed's ops add, by the rules the readers above count them: active staff, active with a
    # phone, admins with one, by role (n7's form), inbound [AUDIO] rows by channel, enquiry follow-ups
    act = ph = adm = au = enq = 0; by = {}; ch = {}
    for arg in sys.argv[2:]:
        for o in json.loads(arg):
            if o.get('op') == 'staff' and o.get('active', True):
                act += 1
                if str(o.get('phone') or '').strip():
                    ph += 1
                    adm += 1 if o.get('admin') else 0
                    rl = re.sub(r'[^a-z_]', '', str(o.get('role', 'sales')).lower()); by[rl] = by.get(rl, 0) + 1
            elif o.get('op') == 'conv':
                for m in o.get('msgs', []):
                    if m.get('kind') == 'audio':
                        au += 1
                        k = str(o.get('channel', 'sales')).lower(); k = k if k in ('sales', 'support', 'account') else 'other'
                        ch[k] = ch.get(k, 0) + 1
            elif o.get('op') == 'enquiry':
                enq += 1
    print('active=%d phone=%d admins_with_phone=%d audio=%d enquiries=%d n7=%s by_channel=%s' % (
        act, ph, adm, au, enq, ','.join('%s:%d' % (k, by[k]) for k in sorted(by)) or '-',
        ','.join('%s:%d' % (k, ch[k]) for k in sorted(ch)) or '-'))
elif cmd == 'merge':
    # merge A B — two "k:n,…" lists (or "-") added key by key, in key order
    tot = {}
    for arg in sys.argv[2:4]:
        for part in ([] if arg in ('', '-') else arg.split(',')):
            k, v = part.rsplit(':', 1); tot[k] = tot.get(k, 0) + int(v)
    print(','.join('%s:%d' % (k, tot[k]) for k in sorted(tot)) or '-')
elif cmd == 'phones':
    # phones OPS-JSON… — every phone the ops name, in the forms a log could carry it: +256…, 256…, 0… and the last nine digits
    seen = set()
    for arg in sys.argv[2:]:
        for o in json.loads(arg):
            d = re.sub(r'\D', '', str(o.get('phone') or ''))
            if len(d) >= 9:
                for f in ('+' + d, d, '0' + d[-9:], d[-9:]):
                    if f not in seen:
                        seen.add(f); print(f)
elif cmd == 'blocks':
    lines = open(sys.argv[2], encoding='utf-8', errors='replace').read().split('\n')
    def kind(l):
        c = l.strip()
        if 'deploy-5.18.104.sh --rollback' in c: return 'rollback'
        if 'sales-followup.sh off all --rollback' in c: return 'sf-rollback'
        if re.search(r'git checkout [0-9a-f]{7}', c) and 'deploy-hybrid.sh' in c: return 'putback'
        if 'deploy-5.18.104.sh --redeploy' in c: return 'redeploy'
        if 'deploy-5.18.104.sh --after-only' in c: return 'after'
        if 'deploy-5.18.104.sh' in c: return 'deploy'
        if 'quarantine-stray-store.sh --check' in c: return 'qcheck'
        if 'sales-followup-report.sh' in c: return 'report'
        if 'sales-followup.sh' in c: return 'sf'
        if c.startswith('less '): return 'less'
        return 'other'
    def kind6(l):   # a line of exactly six spaces' indent: a command's kind, a line of the by-hand put-back, or text
        if re.match(r'^      (cd |\( cd |\( umask |less |bash )', l): return kind(l)
        if re.match(r'^      UPDATE [a-z_]+ SET ', l): return 'byhand'
        if re.match(r'^      [a-z][a-z0-9_-]*( [a-z][a-z0-9_-]*)* ?$', l): return 'byhand'   # allKeys() names: sf_skip_voice-measure among them
        return 'text'
    # a command line that names two of the scripts (deploy, quarantine, put-back, the tool, its report) is two commands on
    # one line — the smallest copyable unit — and counts as two items (104-10's control on the controls plants one)
    two = re.compile(r'deploy-5\.18\.104\.sh|quarantine-stray-store\.sh|deploy-hybrid\.sh|sales-followup(?:-report)?\.sh')
    blocks, cur = [], []
    for l in lines + ['']:
        if l.startswith('      ') and not l.startswith('       '):
            k = kind6(l)
            cur.append(k)
            if k not in ('byhand', 'text') and len(two.findall(l)) > 1:
                cur.extend(['other'] * (len(two.findall(l)) - 1))
        else:
            if cur: blocks.append(cur)
            cur = []
    items = lambda b: sum(1 for k in b if k not in ('byhand', 'text')) + (1 if 'byhand' in b else 0)
    blocks = [b for b in blocks if items(b) > 0]
    back = ('rollback', 'putback', 'sf-rollback', 'byhand')
    mixed = sum(1 for b in blocks if any(k in back for k in b) and items(b) > 1)
    byhand = sum(1 for b in blocks if 'byhand' in b)
    say = lambda b: '+'.join(k for i, k in enumerate(b) if k != 'text' and not (k == 'byhand' and 'byhand' in b[:i]))
    print('blocks=%d mixed=%d byhand=%d kinds=%s' % (len(blocks), mixed, byhand, '/'.join(say(b) for b in blocks) or '-'))
elif cmd == 'stepline':
    t = open(sys.argv[2], encoding='utf-8', errors='replace').read()
    m = re.search(r'then, for each step in turn — (.*?) — on, then that step', re.sub(r'\n +', ' ', t), re.S)
    if not m:
        print('steps=- optional=-')
    else:
        parts = [x.strip() for x in m.group(1).split(',')]
        steps = [re.sub(r' \(optional\)$', '', x) for x in parts]
        opt = [re.sub(r' \(optional\)$', '', x) for x in parts if x.endswith('(optional)')]
        print('steps=%s optional=%s' % (','.join(steps), ','.join(opt) or '-'))
elif cmd == 'ledger':
    con = ro(sys.argv[2]); now = int(sys.argv[3])
    try:
        if not has(con, 'wa_auto_sends'):
            print('rows=absent')
        else:
            rows = con.execute("SELECT id, handset, kind, mode, outcome, CAST(strftime('%s', claimed_at) AS INTEGER) FROM wa_auto_sends ORDER BY id").fetchall()
            q = [r for r in rows if r[2] == 'quote' and r[3] == 'claim']
            qs = [r for r in q if r[4] == 'sent']
            ack = [r for r in rows if r[2] == 'voice_ack' and r[3] == 'claim']
            open_ = [r for r in rows if r[3] == 'claim' and r[4] is None]
            delta = '-'
            if qs:
                last = qs[-1]
                after = [r for r in ack if r[1] == last[1] and r[0] > last[0]]
                if after:
                    delta = str(after[0][5] - last[5])
            orph = 0
            for r in open_:
                later = [b for b in rows if b[1] == r[1] and b[3] == 'claim' and b[0] > r[0] and b[5] >= r[5] + 900]
                if not later and now - r[5] >= 900:
                    orph += 1
            print('rows=%d quote=%d/%d voice_ack=%d open_claims=%d ack_after_quote_s=%s orphans=%d quote_at=%s' % (
                len(rows), len(qs), len(q), len(ack), len(open_), delta, orph, qs[-1][5] if qs else '-'))
    finally:
        con.close()
elif cmd == 'tracker':
    con = ro(sys.argv[2])
    try:
        if not has(con, 'quote_tracker'):
            print('status=absent')
        else:
            t = con.execute('SELECT status, chase_hold, followup_id FROM quote_tracker WHERE quote_key = ?', (sys.argv[3],)).fetchone()
            if t is None:
                print('status=none')
            else:
                fk, fc = '-', 'none'
                if t[2] is not None:
                    f = con.execute('SELECT kind, close_reason, closed_at FROM followups WHERE id = ?', (t[2],)).fetchone() \
                        if 'close_reason' in cols(con, 'followups') else con.execute('SELECT kind, NULL, closed_at FROM followups WHERE id = ?', (t[2],)).fetchone()
                    if f is not None:
                        fk = f[0] or '-'
                        fc = (f[1] or 'closed') if f[2] is not None else 'open'
                dr = '-'
                if t[2] is not None and has(con, 'followup_drafts'):
                    a = con.execute("SELECT COUNT(*) FROM followup_drafts WHERE followup_id = ? AND status = 'approved'", (t[2],)).fetchone()[0]
                    n = con.execute('SELECT COUNT(*) FROM followup_drafts WHERE followup_id = ?', (t[2],)).fetchone()[0]
                    dr = '%d/%d' % (a, n)
                print('status=%s hold=%s followup=%s closed=%s drafts=%s' % (t[0], t[1] or '-', fk, fc, dr))
    finally:
        con.close()
elif cmd == 'keysset':
    con = ro(sys.argv[2])
    try:
        row = {}
        if has(con, 'kyc_config'):
            r = con.execute('SELECT data FROM kyc_config WHERE id = 0').fetchone()
            try:
                row = json.loads(r[0]) if r else {}
            except (TypeError, ValueError):
                row = {}
        try:
            f = json.load(open(sys.argv[3], encoding='utf-8'))
        except (OSError, ValueError):
            f = {}
        names = [x for x in sys.argv[4].split(',') if x]
        on = [k for k in names if any(isinstance(d, dict) and k in d and str(d[k]).strip() not in ('', 'None') for d in (row, f))]
        print('set=%d names=%s' % (len(on), ','.join(on) or '-'))
    finally:
        con.close()
elif cmd == 'keyval':
    if sys.argv[4] != 'ai_provider':
        print('refused')
        raise SystemExit(2)
    con = ro(sys.argv[2])
    try:
        row = {}
        if has(con, 'kyc_config'):
            r = con.execute('SELECT data FROM kyc_config WHERE id = 0').fetchone()
            try:
                row = json.loads(r[0]) if r else {}
            except (TypeError, ValueError):
                row = {}
        try:
            f = json.load(open(sys.argv[3], encoding='utf-8'))
        except (OSError, ValueError):
            f = {}
        v = lambda d: (re.sub(r'[^a-z]', '', str(d.get('ai_provider')).strip().lower()) or '-') if isinstance(d, dict) and str(d.get('ai_provider') or '').strip() else '-'
        print('file=%s store=%s' % (v(f), v(row)))
    finally:
        con.close()
elif cmd == 'admins':
    con = ro(sys.argv[2])
    try:
        row = {}
        if has(con, 'kyc_config'):
            r = con.execute('SELECT data FROM kyc_config WHERE id = 0').fetchone()
            try:
                row = json.loads(r[0]) if r else {}
            except (TypeError, ValueError):
                row = {}
        try:
            f = json.load(open(sys.argv[3], encoding='utf-8'))
        except (OSError, ValueError):
            f = {}
        def adm(d):   # the script's $adm, written out: absent, 1, 0 or other (PHP's === by JSON type)
            if not isinstance(d, dict) or 'quote_agent_cc_admins' not in d:
                return 'absent'
            x = d['quote_agent_cc_admins']
            if x is True or (type(x) is int and x == 1) or x == '1':
                return '1'
            if x is False or (type(x) is int and x == 0) or x == '0':
                return '0'
            return 'other'
        print('file=%s store=%s' % (adm(f), adm(row)))
    finally:
        con.close()
elif cmd == 'vlabels':
    con = ro(sys.argv[2])
    try:
        lab = sys.argv[3]
        r4 = r3 = 0
        if lab and has(con, 'wa_media') and has(con, 'wa_messages'):
            for rung, body in con.execute("SELECT w.voice_rung, m.body FROM wa_media w JOIN wa_messages m ON m.wa_message_id = w.wa_message_id WHERE w.kind = 'audio'"):
                if isinstance(body, str) and body.startswith(lab):
                    if rung == 'reply':
                        r4 += 1
                    else:
                        r3 += 1
        ids = []
        if has(con, 'events'):
            for i, p in con.execute("SELECT id, payload FROM events WHERE event_type = 'ai.reply' AND status IN ('pending', 'processing') ORDER BY id"):
                try:
                    d = json.loads(p) if p else {}
                except (TypeError, ValueError):
                    d = {}
                if isinstance(d, dict) and d.get('origin') == 'voice':
                    ids.append(str(i))
        print('r4=%d r3=%d turns=%d turn_ids=%s' % (r4, r3, len(ids), ','.join(ids) or '-'))
    finally:
        con.close()
elif cmd == 'rbrows':
    con = ro(sys.argv[2])
    try:
        t, m, e = (int(x) if x.isdigit() else 0 for x in sys.argv[3:6])
        st = lambda v: '-' if v is None else str(v).replace(' ', 'T')
        r = con.execute('SELECT state FROM wa_offer_tests WHERE id = ?', (t,)).fetchone() if t and has(con, 'wa_offer_tests') else None
        w = ['test=%s' % (r[0] if r else '-')]
        r = con.execute('SELECT status, attempts, updated_at FROM wa_media WHERE id = ?', (m,)).fetchone() if m and has(con, 'wa_media') else None
        w.append('media=%s' % ('%s/%s/%s' % (r[0], r[1], st(r[2])) if r else '-'))
        r = con.execute('SELECT status, attempts, next_retry_at FROM events WHERE id = ?', (e,)).fetchone() if e and has(con, 'events') else None
        w.append('event=%s' % ('%s/%s/%s' % (r[0], r[1], st(r[2])) if r else '-'))
        print(' '.join(w))
    finally:
        con.close()
elif cmd == 'ogg':
    import base64, struct
    def crc(data):
        c = 0
        for byte in data:
            c ^= byte << 24
            for _ in range(8):
                c = ((c << 1) ^ 0x04C11DB7) & 0xFFFFFFFF if c & 0x80000000 else (c << 1) & 0xFFFFFFFF
        return c
    def page(seq, granule, packets, flag):
        lacing = b''.join(bytes([len(x)]) for x in packets)
        hdr = b'OggS' + bytes([0, flag]) + struct.pack('<qII', granule, 0x52483104, seq) + b'\0\0\0\0' + bytes([len(packets)]) + lacing
        body = b''.join(packets)
        c = crc(hdr + body)
        return hdr[:22] + struct.pack('<I', c) + hdr[26:] + body
    pre = 312
    head = b'OpusHead' + bytes([1, 1]) + struct.pack('<HIhB', pre, 48000, 0, 0)
    tags = b'OpusTags' + struct.pack('<I', 5) + b'rh104' + struct.pack('<I', 0)
    frames = [bytes([0xF8])] * 350          # 350 CELT frames of 20 ms, code 0: 7 s
    out = page(0, 0, [head], 2) + page(1, 0, [tags], 0) + page(2, 255 * 960 + pre, frames[:255], 0) + page(3, 350 * 960 + pre, frames[255:], 4)
    print(base64.b64encode(out).decode())
elif cmd == 'stcmp':
    a = dict(x.split('=', 1) for x in sys.argv[2].split())
    e = dict(x.split('=', 1) for x in sys.argv[3].split())
    bad = []
    for k, v in e.items():
        g = a.get(k, 'none')
        ok = g == v or (v == 'judged' and (g == 'pass' or g.startswith('notyet:'))) or (v == 'notyet' and g.startswith('notyet:'))
        if not ok:
            bad.append('%s:%s/%s' % (k, g, v))
    print('same' if not bad else ' '.join(bad))
elif cmd == 'quotes':
    import time
    qse = open(sys.argv[3], encoding='utf-8').read()
    me = re.search(r"const EVENTS = \[([^\]]*)\]", qse)
    mm = re.search(r"const MARKERS = \[(.*?)\];", qse, re.S)
    events = re.findall(r"'([a-z_]+)'", me.group(1)) if me else []
    markers = [(k, v) for k, v in re.findall(r"'([a-z]+)'\s*=>\s*'([^']*)'", mm.group(1))] if mm else []
    if len(events) < 1 or len(markers) != 3:
        print('unread=events:%d,markers:%d' % (len(events), len(markers))); sys.exit(0)
    now = int(sys.argv[4]) if len(sys.argv) > 4 else int(time.time())
    def unix(st):
        st = str(st or '').replace('T', ' ')[:19]
        try:
            return int(__import__('calendar').timegm(time.strptime(st, '%Y-%m-%d %H:%M:%S')))
        except ValueError:
            return 0
    def stamp(t):
        return time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime(t))
    def number_of(body):
        best = None
        for kind, mk in markers:
            at = body.find(mk)
            if at >= 0 and (best is None or at < best[1]):
                best = (kind, at, mk)
        if best is None:
            return None
        rest = body[best[1] + len(best[2]):]
        line = rest.split('\n', 1)[0]
        if best[0] == 'quote':
            if '*' not in line:
                return None
            line = line[:line.index('*')]
        n = line.strip()
        if n.startswith('#'):
            n = n[1:].strip()
        return n or None
    con = ro(sys.argv[2])
    try:
        if not has(con, 'wa_messages') or not has(con, 'wa_conversations'):
            print('h30=0/0 h90=0/0 quotes=0 wa=absent'); sys.exit(0)
        first = {}
        q = 'SELECT id, conversation_id, body, sent_at FROM wa_messages WHERE direction = ? AND event_key IN (%s) ORDER BY id' % ','.join('?' * len(events))
        for mid, cid, body, sent in con.execute(q, ['out'] + events):
            n = number_of(str(body or ''))
            t = unix(sent)
            if n is None or t == 0:
                continue
            k = 'ref:' + n
            if k not in first or first[k][0] > t:
                first[k] = (t, int(cid), int(mid))
        conv = {}
        for cid, ph, cat in con.execute('SELECT id, phone, %s FROM wa_conversations' % ('category' if 'category' in cols(con, 'wa_conversations') else "''")):
            conv[int(cid)] = (str(ph or ''), str(cat or '').lower())
        fs = has(con, 'followup_sends') and has(con, 'followups')
        head = {30: [0, 0], 90: [0, 0]}; judged = 0
        for k, (t, cid, mid) in first.items():
            if t < now - 90 * 86400 or t >= now or cid not in conv:
                continue
            ph, cat = conv[cid]
            if cat == 'staff' or ph.startswith('web:'):
                continue
            judged += 1
            d9 = re.sub(r'\D', '', ph)[-9:]
            ids = [i for i, (p2, c2) in conv.items() if c2 != 'staff' and d9 and re.sub(r'\D', '', p2)[-9:] == d9] or [cid]
            ins = ','.join('?' * len(ids))
            later = con.execute('SELECT COUNT(*) FROM wa_messages WHERE conversation_id IN (%s) AND id <> ? AND sent_at > ? AND sent_at <= ?' % ins,
                                ids + [mid, stamp(t), stamp(t + 7 * 86400)]).fetchone()[0]
            if fs:
                later += con.execute('SELECT COUNT(*) FROM followup_sends s JOIN followups f ON f.id = s.followup_id WHERE f.conversation_id IN (%s) '
                                     'AND s.sent_at > ? AND s.sent_at <= ?' % ins, ids + [stamp(t), stamp(t + 7 * 86400)]).fetchone()[0]
            for dd in (30, 90):
                if now - dd * 86400 <= t <= now - 7 * 86400:
                    head[dd][1] += 1
                    head[dd][0] += 1 if later == 0 else 0
        print('h30=%d/%d h90=%d/%d quotes=%d' % (head[30][0], head[30][1], head[90][0], head[90][1], judged))
    finally:
        con.close()
elif cmd == 'mail':
    import time, calendar
    now = int(sys.argv[4]) if len(sys.argv) > 4 else int(time.time())
    since = now - 30 * 86400
    def st(x):
        if not isinstance(x, str) or not re.match(r'^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$', x):
            return None
        return calendar.timegm(time.strptime(x, '%Y-%m-%d %H:%M:%S'))
    p1 = 'absent'; sent = other = old = undated = 0
    try:
        fh = open(sys.argv[3], 'rb')
    except FileNotFoundError:
        fh = None
    except OSError:
        fh = None; p1 = 'unreadable'
    if fh is not None:
        p1 = 'read'
        for raw in fh.read().decode('utf-8', 'replace').split('\n'):
            l = raw.rstrip('\r')
            if l == '':
                continue
            m = re.match(r'^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] ', l)
            t = st(m.group(1)) if m else None
            if t is None:
                undated += 1; continue
            if t < since:
                old += 1; continue
            if l.endswith(' sent') and ' -> ' in l:
                sent += 1
            else:
                other += 1
        fh.close()
    con = ro(sys.argv[2])
    try:
        p3 = 'absent'; by = {}; kyc = log = none = 0
        if has(con, 'customer_email_log'):
            p3 = 'read'; ids = []
            for key, status, created in con.execute("SELECT dedupe_key, status, created_at FROM customer_email_log WHERE template = 'quotation'"):
                t = st(str(created) if created is not None else None)
                if t is None or t < since:
                    continue
                sx = re.sub(r'[^a-z0-9_]', '', str(status or '').lower()) or 'other'
                by[sx] = by.get(sx, 0) + 1
                k = str(key or '')
                if sx == 'sent' and k.startswith('quotation:') and k[10:].isdigit():
                    ids.append(int(k[10:]))
            def held(t, f):
                o = set()
                for r in (docrows(con, t) or []):
                    v = r.get(f)
                    if isinstance(v, (int, str)) and not isinstance(v, bool) and str(v).strip().isdigit():
                        o.add(int(str(v).strip()))
                return o
            K = held('kyc_applications', 'quote_id'); L = held('quotes_log', 'crm_quote_id') | held('leads', 'crm_quote_id')
            for i in ids:
                if i in K: kyc += 1
                elif i in L: log += 1
                else: none += 1
        print('p1=%s p1_sent=%d p1_other=%d p1_older=%d p1_undated=%d p3=%s p3_by_status=%s p3_sent_kyc=%d p3_sent_log_or_lead=%d p3_sent_none=%d' % (
            p1, sent, other, old, undated, p3, ','.join('%s:%d' % (k, by[k]) for k in sorted(by)) or '-', kyc, log, none))
    finally:
        con.close()
elif cmd == 'qexpect':
    h = {30: 0, 90: 0}; p1 = p3 = 0; nums = set()
    for o in json.loads(sys.argv[2]):
        if o.get('op') == 'quote':
            ago = int(o.get('ago_min', 0)) * 60
            nums.add(str(o.get('ref')))
            for dd in (30, 90):
                if 7 * 86400 <= ago <= dd * 86400:
                    h[dd] += 1
        elif o.get('op') == 'quote_mail':
            if o.get('path') == 'app': p1 += 1
            elif o.get('path') == 'webhook': p3 += 1
            nums.add(str(o.get('number')))
    print('h30=%d/%d h90=%d/%d p1_sent=%d p3_sent=%d p3_none=%d numbers=%d' % (h[30], h[30], h[90], h[90], p1, p3, p3, len(nums)))
elif cmd == 'rb104':
    con = ro(sys.argv[2])
    try:
        a = int(sys.argv[3]) if len(sys.argv) > 3 and sys.argv[3].isdigit() else 0
        b = int(sys.argv[4]) if len(sys.argv) > 4 and sys.argv[4].isdigit() else 0
        one = lambda q, p=(): con.execute(q, p).fetchone()[0]
        w = []
        if has(con, 'followups') and 'kind' in cols(con, 'followups') and has(con, 'followup_drafts'):
            w.append('oq=%d' % one("SELECT COUNT(*) FROM followups WHERE kind = 'quote' AND closed_at IS NULL"))
            w.append('od=%d' % one("SELECT COUNT(*) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE f.kind = 'quote' "
                                   "AND f.closed_at IS NULL AND d.status IN ('pending', 'approved')"))
            w.append('cr=%d' % one("SELECT COUNT(*) FROM followups WHERE kind = 'quote' AND close_reason = 'rollback'"))
            w.append('ex=%d' % one("SELECT COUNT(*) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id WHERE f.kind = 'quote' "
                                   "AND f.close_reason = 'rollback' AND d.status = 'expired'"))
        else:
            w.append('oq=absent od=absent cr=absent ex=absent')
        if has(con, 'wa_media'):
            w.append('ap=%d' % one("SELECT COUNT(*) FROM wa_media WHERE kind = 'audio' AND status = 'pending'"))
            w.append('as=%d' % one("SELECT COUNT(*) FROM wa_media WHERE kind = 'audio' AND status = 'skipped' AND failure_reason = 'switched_off'"))
        else:
            w.append('ap=absent as=absent')
        if has(con, 'events'):
            w.append('em=%d' % one("SELECT COUNT(*) FROM events WHERE event_type = 'ai.media' AND status IN ('pending', 'failed', 'processing')"))
            w.append('ea=%d' % one("SELECT COUNT(*) FROM events WHERE event_type = 'ai.voice_ack' AND status IN ('pending', 'processing')"))
        else:
            w.append('em=absent ea=absent')
        w.append('ks=%s' % (one("SELECT COUNT(*) FROM wa_voice_acks WHERE outcome = 'skipped' AND reason = 'switched_off'") if has(con, 'wa_voice_acks') else 'absent'))
        w.append('vc=%s' % (one('SELECT COUNT(*) FROM wa_voice_calls') if has(con, 'wa_voice_calls') else 'absent'))
        if has(con, 'wa_offers') and has(con, 'wa_offer_recipients') and has(con, 'wa_offer_tests'):
            w.append('or_=%d' % one("SELECT COUNT(*) FROM wa_offers WHERE state = 'running'"))
            w.append('op=%d' % one("SELECT COUNT(*) FROM wa_offers WHERE state = 'paused'"))
            w.append('cl=%d' % one("SELECT COUNT(*) FROM wa_offer_recipients WHERE state = 'claimed' AND ledger_claim IS NOT NULL"))
            w.append('cn=%d' % one("SELECT COUNT(*) FROM wa_offer_recipients WHERE state = 'claimed' AND ledger_claim IS NULL"))
            w.append('ts=%d' % one("SELECT COUNT(*) FROM wa_offer_tests WHERE state = 'sending'"))
            ra = con.execute('SELECT state, reason, sent_at, claimed_at, ledger_claim FROM wa_offer_recipients WHERE id = ?', (a,)).fetchone() if a else None
            if ra is None:
                w.append('ra=-')
            else:
                lo = '-'
                if ra[4] is not None and has(con, 'wa_auto_sends'):
                    r2 = con.execute('SELECT outcome FROM wa_auto_sends WHERE id = ?', (ra[4],)).fetchone()
                    lo = (r2[0] or 'open') if r2 else 'none'
                w.append('ra=%s/%s/%s/%s' % (ra[0], ra[1] or '-', 'same' if ra[2] is not None and ra[2] == ra[3] else 'other', lo))
            rb = con.execute('SELECT state, not_before, ledger_claim FROM wa_offer_recipients WHERE id = ?', (b,)).fetchone() if b else None
            w.append('rb=-' if rb is None else 'rb=%s/%s/%s' % (rb[0], 'nb' if rb[1] else '-', 'ledger' if rb[2] is not None else 'noledger'))
        else:
            w.append('or_=absent op=absent cl=absent cn=absent ts=absent ra=- rb=-')
        print(' '.join(w))
    finally:
        con.close()
elif cmd == 'emids':
    con = ro(sys.argv[2])
    try:
        ids = [str(r[0]) for r in con.execute("SELECT id FROM events WHERE event_type = 'ai.media' AND status IN ('pending', 'failed', 'processing') ORDER BY id")] if has(con, 'events') else []
        print(' '.join(ids) if ids else 'none')
    finally:
        con.close()
elif cmd == 'offer':
    con = ro(sys.argv[2])
    try:
        oid = int(sys.argv[3]); who = re.sub(r'\D', '', sys.argv[4]) if len(sys.argv) > 4 else ''
        r = con.execute('SELECT state, content_hash FROM wa_offers WHERE id = ?', (oid,)).fetchone() if has(con, 'wa_offers') else None
        if r is None:
            print('state=none hash=bad tests=- handsets=0 byh=- rs=- ours=0 stops=0 who=-')
        else:
            w = ['state=%s' % r[0], 'hash=%s' % ('ok' if re.match(r'^[0-9a-f]{64}$', r[1] or '') else 'bad')]
            ts = []
            for v, st, led, ec in con.execute('SELECT variant, state, ledger, echo FROM wa_offer_tests WHERE offer_id = ? ORDER BY variant, id', (oid,)):
                ts.append('%s:%s/%s/%s' % (v, st, re.sub(r'[^a-z_]', '', str(led or '-')) or '-', ec or '-'))
            w.append('tests=' + (','.join(ts) or '-'))
            rows = con.execute('SELECT phone, handset, state, stop_at FROM wa_offer_recipients WHERE offer_id = ?', (oid,)).fetchall()
            byh = {}
            rs = {}
            for ph, h, st, sp in rows:
                byh[h] = byh.get(h, 0) + 1
                rs[st] = rs.get(st, 0) + 1
            w.append('handsets=%d' % len(byh))
            w.append('byh=' + (','.join(str(x) for x in sorted(byh.values())) or '-'))
            w.append('rs=' + (','.join('%s:%d' % (k, rs[k]) for k in sorted(rs)) or '-'))
            w.append('ours=%d' % sum(1 for x in rows if re.match(r'^\+?2567001040\d\d$', str(x[0] or ''))))
            w.append('stops=%d' % sum(1 for x in rows if x[3]))
            hit = [x for x in rows if who and re.sub(r'\D', '', str(x[0] or '')) == who]
            w.append('who=%s/%s' % (hit[0][2], 'set' if hit[0][3] else '-') if hit else 'who=-')
            print(' '.join(w))
    finally:
        con.close()
elif cmd == 'oclaims':
    con = ro(sys.argv[2])
    try:
        a, b = int(sys.argv[3]), int(sys.argv[4])
        st = lambda t: __import__('time').strftime('%Y-%m-%d %H:%M:%S', __import__('time').gmtime(t))
        rows = con.execute("SELECT claimed_at, handset FROM wa_auto_sends WHERE kind = 'offer' AND claimed_at >= ? AND claimed_at < ? ORDER BY claimed_at",
                           (st(a), st(b))).fetchall() if has(con, 'wa_auto_sends') else []
        import calendar, time as _t
        first = calendar.timegm(_t.strptime(rows[0][0][:19], '%Y-%m-%d %H:%M:%S')) if rows else 0
        byh = {}
        for _, h in rows:
            byh[h] = byh.get(h, 0) + 1
        print('n=%d first=%d byh=%s' % (len(rows), first, ','.join(str(x) for x in sorted(byh.values())) or '-'))
    finally:
        con.close()
elif cmd == 'testled':
    con = ro(sys.argv[2])
    try:
        oid = int(sys.argv[3])
        ids = [str(r[0]) for r in con.execute('SELECT id FROM wa_offer_tests WHERE offer_id = ?', (oid,))] if has(con, 'wa_offer_tests') else []
        refs = ['offer_test:' + x for x in ids]
        rows = [r for r in con.execute("SELECT ref, outcome FROM wa_auto_sends WHERE kind = 'other'") if r[0] in refs] if has(con, 'wa_auto_sends') else []
        print('n=%d sent=%d' % (len(rows), sum(1 for r in rows if r[1] == 'sent')))
    finally:
        con.close()
elif cmd == 'handsets':
    con = ro(sys.argv[2])
    try:
        kyc = {}
        try:
            kyc = json.load(open(sys.argv[3], encoding='utf-8'))
        except (OSError, ValueError):
            kyc = {}
        row = {}
        if has(con, 'kyc_config'):
            r = con.execute('SELECT data FROM kyc_config WHERE id = 0').fetchone()
            try:
                row = json.loads(r[0]) if r else {}
            except (TypeError, ValueError):
                row = {}
        hs = set()
        for k in ('evo_instance_sales', 'evo_instance_support'):
            v = kyc.get(k) if isinstance(kyc, dict) and str(kyc.get(k) or '').strip() else (row.get(k) if isinstance(row, dict) else None)
            if str(v or '').strip():
                hs.add(str(v).strip().lower())
        print('n=%d' % len(hs))
    finally:
        con.close()
elif cmd == 'relaycc':
    try:
        t = json.load(open(sys.argv[2], encoding='utf-8'))
    except (OSError, ValueError):
        t = []
    cl = set(x.strip().lower() for x in sys.argv[3].split(',') if x.strip())
    ag = set(x.strip().lower() for x in sys.argv[4].split(',') if x.strip())
    kinds = []
    for se in (t if isinstance(t, list) else []):
        if not isinstance(se, dict) or not se.get('rcpt_to'):
            continue
        rc = [str(x).strip().lower() for x in se.get('rcpt_to', [])]
        data = str(se.get('data') or '').replace('\r\n', '\n')
        head = data.split('\n\n', 1)[0]
        head = re.sub(r'\n[ \t]+', ' ', head)                       # unfold
        ccs = []
        for line in head.split('\n'):
            if line.lower().startswith('cc:'):
                ccs += [m.lower() for m in re.findall(r'[^\s<>,;"]+@[^\s<>,;"]+', line[3:])]
        cc_ag = [x for x in ccs if x in ag]
        cc_other = [x for x in ccs if x not in ag and x not in cl]
        if len(rc) == 1 and rc[0] in cl and not cc_ag:
            kinds.append('C')
        elif len(rc) == 2 and rc[0] in cl and rc[1] in ag and cc_ag == [rc[1]] and not cc_other:
            kinds.append('CA')
        else:
            kinds.append('X')
    print('sessions=%d kinds=%s' % (len(kinds), ','.join(kinds) or '-'))
elif cmd == 'acdec':
    con = ro(sys.argv[2])
    try:
        rows = docrows(con, 'quote_agent_copies.json') or []
        out = []
        for q in [x for x in sys.argv[3].split(',') if x]:
            mine = ['%s:%s' % (re.sub(r'[^a-z_]', '', str(r.get('path') or '')), re.sub(r'[^a-z_]', '', str(r.get('code') or ''))) for r in rows if str(r.get('quote_id')) == q]
            out.append('%s=%s' % (q, '+'.join(mine) if mine else 'none'))
        print(' '.join(out))
    finally:
        con.close()
elif cmd == 'vcalls':
    con = ro(sys.argv[2])
    try:
        import time as _t
        since = _t.strftime('%Y-%m-%d %H:%M:%S', _t.gmtime(int(sys.argv[3])))
        w = []
        if has(con, 'wa_voice_calls'):
            c = con.execute('SELECT COUNT(*), SUM(outcome = ?) FROM wa_voice_calls WHERE created_at >= ?', ('ok', since)).fetchone()
            w.append('calls=%d ok=%d' % (c[0] or 0, c[1] or 0))
            br = con.execute('SELECT voice_rung, COUNT(*) FROM wa_voice_calls WHERE created_at >= ? GROUP BY voice_rung ORDER BY voice_rung', (since,)).fetchall()
            w.append('byrung=' + (','.join('%s:%d' % x for x in br) or '-'))
        else:
            w.append('calls=absent ok=absent byrung=-')
        if has(con, 'wa_media'):
            rr = con.execute("SELECT COALESCE(voice_rung, '-'), status, COUNT(*) FROM wa_media WHERE kind = 'audio' AND created_at >= ? GROUP BY 1, 2 ORDER BY 1, 2", (since,)).fetchall()
            w.append('rows=' + (','.join('%s/%s:%d' % x for x in rr) or '-'))
        else:
            w.append('rows=absent')
        if has(con, 'events'):
            e1 = con.execute("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation' AND created_by = 'media_worker' AND created_at >= ?", (since,)).fetchone()[0]
            e2 = con.execute("SELECT COUNT(*) FROM events WHERE event_type = 'wa.escalation' AND created_by = 'ai_reply_worker' AND created_at >= ?", (since,)).fetchone()[0]
            tn = con.execute("SELECT COUNT(*) FROM events WHERE event_type = 'ai.reply' AND payload LIKE '%media_id%' AND created_at >= ?", (since,)).fetchone()[0]
            w.append('esc=%d aiesc=%d turns=%d' % (e1, e2, tn))
        else:
            w.append('esc=absent aiesc=absent turns=absent')
        print(' '.join(w))
    finally:
        con.close()
elif cmd == 'ca104':
    # 104-7 (RH5): a deploy run's after-the-copy judgements, read off its terminal (FILE) by the script's own words — QF-CA2,
    # QF-CA3 (the schedule half), VN-CA4, OF-CA3 in their redeploy forms, the QF-G1 baseline line, VN-G6's and OF-G1's notes.
    # Each <code>=ok|fail|none, and the numbers each line prints; never a name or a phone (the lines carry none).
    t = open(sys.argv[2], encoding='utf-8', errors='replace').read().split('\n')
    def line(prefix):
        r = [l for l in t if l.startswith(prefix)]
        return r[0] if r else ''
    def grab(rx, s, d='-'):
        m = re.search(rx, s)
        return m.group(1) if m else d
    w = {}
    b = line('  ok    QF-G1 the redeploy baseline, read-only from the live book just before the copy: ')
    w['base'] = 'ok' if b else 'none'
    w['basemax'] = grab(r' max=(\d+) ', b); w['baserows'] = grab(r': rows=([0-9,-]+) ', b); w['baseentries'] = grab(r' entries=(\S+) ', b)
    w['unix'] = grab(r'\(unix (\d+)\)$', b)
    o = line('  ok    QF-CA2 a redeploy, against the baseline taken just before the copy ('); f = line('  FAIL  QF-CA2 a redeploy: ')
    w['ca2'] = 'ok' if o else ('fail' if f else 'none')
    w['ca2added'] = grab(r'added since the baseline, by mode and kind: (\S+); ', o)
    w['ca2same'] = grab(r"claims in the copy's start second (\d+), before it ", o); w['ca2before'] = grab(r', before it (\d+) — counted and printed', o)
    w['ca2late'] = grab(r'^  FAIL  QF-CA2 a redeploy: (\d+) claim\(s\) above', f)
    o = line('  ok    QF-CA3 a redeploy: master_schedule.json read on the copy and at the baseline ('); f = line('  FAIL  QF-CA3 a redeploy: ')
    w['ca3'] = 'ok' if o else ('fail' if f else 'none')
    w['ca3now'] = grab(r'\(entries now (\S+); at the baseline ', o); w['ca3base'] = grab(r'; at the baseline (\S+)\) — an earlier', o)
    w['ca3late'] = grab(r'^  FAIL  QF-CA3 a redeploy: (\d+) of the four jobs', f)
    o = line('  ok    VN-CA4 a redeploy, against VN-G6'); f = line('  FAIL  VN-CA4 a redeploy: ')
    w['vn4'] = 'ok' if o else ('fail' if f else 'none')
    w['vn4rows'] = grab(r'added since the baseline — rows (\S+) \(wa_media', o); w['vn4ev'] = grab(r', events (\S+) \(ai\.media, ai\.voice_ack\); claims', o)
    w['vn4ackssame'] = grab(r"claims in the copy's start second (\d+), before it \d+; decisions", o)
    w['vn4dec'] = grab(r'; decisions (\S+) \(sent or uncertain in that second', o)
    w['vn4callssame'] = grab(r'; calls in that second (\d+), before it', o)
    w['vn4late'] = grab(r'voice_ack claims (\d+), sent or uncertain decisions \d+, OpenAI calls \d+ in a later second', f) + '/' + \
                   grab(r'voice_ack claims \d+, sent or uncertain decisions (\d+), OpenAI calls', f) + '/' + grab(r', OpenAI calls (\d+) in a later second', f)
    o = line('  ok    OF-CA3 a redeploy, against OF-CA3'); f = line('  FAIL  OF-CA3 a redeploy: ')
    w['of3'] = 'ok' if o else ('fail' if f else 'none')
    w['of3claims'] = grab(r'added offer claims at or before that second (\d+), ', o)
    w['of3tests'] = grab(r', tests started then (\d+) with ', o); w['of3testrows'] = grab(r' with (\d+) offer_test:\* row\(s\), offer', o)
    w['of3echoes'] = grab(r'offer\.\* echoes (\d+), rows added', o); w['of3added'] = grab(r', rows added (\S+), events added by action', o)
    w['of3events'] = grab(r'events added by action (\S+); the tables hold', o); w['of3offers'] = grab(r'by state: offers (\S+), recipients', o)
    w['of3late'] = grab(r'\(on the sheet\), (\d+) offer claim\(s\)', f) + '/' + grab(r', (\d+) offer\.send echo\(es\)', f) + '/' + \
                   grab(r', (\d+) test\(s\), and', f) + '/' + grab(r', and (\d+) offer_test:\* ledger row\(s\)', f)
    g = line('  note  VN-G6 a redeploy: '); w['g6'] = 'note' if g else 'none'
    w['g6media'] = grab(r'— ai\.media (\d+) \(', g); w['g6ack'] = grab(r', ai\.voice_ack (\d+) \(', g)
    w['of1'] = 'note' if line('  note  OF-G1 091 IS ALREADY APPLIED ON THIS BOOK') else 'none'
    w['vnbase'] = 'note' if line("  note  VN-G6 VN-CA4's redeploy baseline, read-only from the live book in QF-G1's one call") else 'none'
    w['ofbase'] = 'note' if line("  note  OF-G1 OF-CA3's redeploy baseline, read-only from the live book in QF-G1's one call") else 'none'
    print(' '.join('%s=%s' % (k, v) for k, v in w.items()))
elif cmd == 'sched4':
    # 104-7 (RH5): master_schedule.json's entries for the four jobs, as QF_PHP's base mode prints them (name:last_run, in
    # the order quote_chase, quote_daily_list, ai_media, offers_send; "-" when none), and how many other jobs' entries it holds
    con = ro(sys.argv[2])
    try:
        d = {}
        if has(con, 'master_schedule'):
            r = con.execute('SELECT data FROM master_schedule WHERE id = 0 LIMIT 1').fetchone()
            try:
                d = json.loads(r[0]) if r else {}
            except ValueError:
                d = {}
        four = ['quote_chase', 'quote_daily_list', 'ai_media', 'offers_send']
        e = ['%s:%d' % (j, int(d[j].get('last_run') or 0)) for j in four if isinstance(d, dict) and isinstance(d.get(j), dict)]
        others = sum(1 for k, v in (d.items() if isinstance(d, dict) else []) if isinstance(v, dict) and k not in four)
        print('entries=%s others=%d n=%d' % (','.join(e) or '-', others, len(e)))
    finally:
        con.close()
elif cmd == 'vhist':
    # 104-7v (RH5): Part V's history on the book — wa_media by status, wa_voice_calls rows, wa_voice_acks by outcome, the
    # ai.media and ai.voice_ack events by status; counts only
    con = ro(sys.argv[2])
    try:
        def by(sql):
            return ','.join('%s:%d' % (r[0], r[1]) for r in con.execute(sql).fetchall()) or '-'
        w = []
        w.append('media=' + (by('SELECT status, COUNT(*) FROM wa_media GROUP BY status ORDER BY status') if has(con, 'wa_media') else 'absent'))
        w.append('calls=%s' % (con.execute('SELECT COUNT(*) FROM wa_voice_calls').fetchone()[0] if has(con, 'wa_voice_calls') else 'absent'))
        w.append('acks=' + (by("SELECT outcome || '/' || COALESCE(reason, '-'), COUNT(*) FROM wa_voice_acks GROUP BY 1 ORDER BY 1") if has(con, 'wa_voice_acks') else 'absent'))
        w.append('evmedia=' + by("SELECT status, COUNT(*) FROM events WHERE event_type = 'ai.media' GROUP BY status ORDER BY status"))
        w.append('evack=' + by("SELECT status, COUNT(*) FROM events WHERE event_type = 'ai.voice_ack' GROUP BY status ORDER BY status"))
        # RH5-fix1 (R08): the audio rows by the rung they were settled at and their status, the OpenAI calls by rung and
        # outcome, and the ai.reply events by status — (a)'s "audio rows at each rung" read, not assumed ("-" for no rung)
        w.append('rungs=' + (by("SELECT COALESCE(voice_rung, '-') || '/' || status, COUNT(*) FROM wa_media WHERE kind = 'audio' GROUP BY 1 ORDER BY 1") if has(con, 'wa_media') else 'absent'))
        w.append('callrungs=' + (by("SELECT COALESCE(voice_rung, '-') || '/' || COALESCE(outcome, '-'), COUNT(*) FROM wa_voice_calls GROUP BY 1 ORDER BY 1") if has(con, 'wa_voice_calls') else 'absent'))
        w.append('aireply=' + by("SELECT status, COUNT(*) FROM events WHERE event_type = 'ai.reply' GROUP BY status ORDER BY status"))
        print(' '.join(w))
    finally:
        con.close()
elif cmd == 'liveoffers':
    # 104-7o (RH5-fix1, R01): the offers holding the one live slot (091: live_slot = 1 while running or paused, a unique
    # index on it — O-D1), each "<id>:<state>", and every offer the table holds (n, the reading's positive control: a book
    # whose slot is free reads live=- with n >= 1, never a zero-row read)
    con = ro(sys.argv[2])
    try:
        if not has(con, 'wa_offers'):
            print('live=absent n=-1')
        else:
            r = con.execute('SELECT id, state FROM wa_offers WHERE live_slot IS NOT NULL ORDER BY id').fetchall()
            print('live=%s n=%d' % (','.join('%d:%s' % (x[0], x[1]) for x in r) or '-', con.execute('SELECT COUNT(*) FROM wa_offers').fetchone()[0]))
    finally:
        con.close()
elif cmd == 'atleast':
    # 104-7c (RH5-fix1, R07): what a copy of the script judging "at or after" the copy's start counts late, read off the book
    # by this rehearsal — every ledger claim above QMAX stamped at or after U (QF-CA2's), the voice_ack claims among them, the
    # sent or uncertain wa_voice_acks decisions above AMAX whose latest voice_ack ledger row is so stamped, and the
    # wa_voice_calls rows above CMAX created at or after U (VN-CA4's three); then the same four in U itself
    con = ro(sys.argv[2])
    try:
        qmax, amax, cmax, u = int(sys.argv[3]), int(sys.argv[4]), int(sys.argv[5]), int(sys.argv[6])
        ep = "CAST(strftime('%s', {}) AS INTEGER)"
        cl = con.execute("SELECT kind, " + ep.format('claimed_at') + " FROM wa_auto_sends WHERE mode = 'claim' AND id > ?", (qmax,)).fetchall() if has(con, 'wa_auto_sends') else []
        dec = []
        if has(con, 'wa_voice_acks') and has(con, 'wa_auto_sends'):
            for o, ref in con.execute("SELECT outcome, ledger_ref FROM wa_voice_acks WHERE id > ? AND outcome IN ('sent', 'uncertain')", (amax,)).fetchall():
                t = con.execute("SELECT " + ep.format('claimed_at') + " FROM wa_auto_sends WHERE kind = 'voice_ack' AND ref = ? ORDER BY id DESC LIMIT 1", (ref,)).fetchone() if ref else None
                if t is not None and t[0] is not None:
                    dec.append(int(t[0]))
        ca = [x[0] for x in con.execute("SELECT " + ep.format('created_at') + " FROM wa_voice_calls WHERE id > ?", (cmax,)).fetchall()] if has(con, 'wa_voice_calls') else []
        ge = lambda ts: sum(1 for t in ts if t is not None and int(t) >= u)
        eq = lambda ts: sum(1 for t in ts if t is not None and int(t) == u)
        ack = [t for k, t in cl if k == 'voice_ack']
        print('claims=%d ack=%d dec=%d calls=%d same_claims=%d same_ack=%d same_dec=%d same_calls=%d' % (
            ge([t for _, t in cl]), ge(ack), ge(dec), ge(ca), eq([t for _, t in cl]), eq(ack), eq(dec), eq(ca)))
    finally:
        con.close()
elif cmd == 'claimat':
    # 104-7 (RH5): the ledger's rows of a kind added above an id (MAX), each "<id>:<mode>:<epoch of claimed_at>", oldest first
    con = ro(sys.argv[2])
    try:
        r = con.execute("SELECT id, mode, CAST(strftime('%s', claimed_at) AS INTEGER) FROM wa_auto_sends WHERE kind = ? AND id > ? ORDER BY id",
                        (sys.argv[3], int(sys.argv[4]))).fetchall() if has(con, 'wa_auto_sends') else []
        print(','.join('%d:%s:%d' % (x[0], x[1], x[2]) for x in r) or '-')
    finally:
        con.close()
elif cmd == 'callsat':
    # 104-7v (RH5): wa_voice_calls rows above an id, each "<id>:<epoch of created_at>"
    con = ro(sys.argv[2])
    try:
        r = con.execute("SELECT id, CAST(strftime('%s', created_at) AS INTEGER) FROM wa_voice_calls WHERE id > ? ORDER BY id", (int(sys.argv[3]),)).fetchall() if has(con, 'wa_voice_calls') else []
        print(','.join('%d:%d' % x for x in r) or '-')
    finally:
        con.close()
elif cmd == 'tl':
    # 104-7 (RH5): the fake docker's timeline of a deploy run's moments (EVENT N start|end EPOCH.NANOS lines): EVENT.N=<start>-<end>,
    # the seconds as integers, in the order they began
    w = []
    for l in open(sys.argv[2], encoding='utf-8'):
        p = l.split()
        if len(p) == 4:
            w.append((p[0] + '.' + p[1], p[2], int(float(p[3]))))
    se = {}
    for k, kind, t in w:
        se.setdefault(k, ['-', '-'])[0 if kind == 'start' else 1] = str(t)
    print(' '.join('%s=%s-%s' % (k, v[0], v[1]) for k, v in se.items()) or '-')
elif cmd == 'kv':
    m = re.search(r'(?:^| )' + re.escape(sys.argv[3]) + r'=([^ ]*)', sys.argv[2])
    print(m.group(1) if m else '')
else:
    sys.stderr.write('rh104q.py: unknown mode\n')
    sys.exit(2)

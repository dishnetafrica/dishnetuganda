#!/usr/bin/env python3
# 5.18.106's rehearsal: its own readers — of the enquiry follow-ups the jam plants (U1's classing order, U2's and U3's
# counts, refusedWhileDelivering, the stale and gone order, the closer's closes and followup_close's schedule entry), of
# the rollback records (as_rb_last and the V1 guard before as_back_on), of the four settings places (their bytes, and the
# keys each holds) and of the script's own text. Each rule is written here from the RELEASE's own code at its pin
# (bf29cbd: lib/FollowUpService.php, cron/followup_close.php, cron/followup_send.php, lib/PluginConfig.php and the
# readers they call) or from the script's own lines (deploy-5.18.106.sh, cited per mode), in Python — never by running
# either: the script's readings (QF_PHP uj, AS_PHP read, as_rb_last) are set against these, so one wrong reading cannot
# confirm itself. READERS ONLY: the store is opened read-only (mode=ro, query_only) and every file is read as bytes;
# nothing is written anywhere — no map file, no pattern file, no cache (run with python3 -I, as rh105q.py is). Every mode
# prints ONE line of key=value words: counts, ids, place letters, key names, fixed codes, UTC times and sha256 hashes —
# never a value, a name, a phone, a body or an event's detail. Modes:
#   verbs SCRIPT          the static guard (root docs/74 §H.6.1), re-pointed to deploy-5.18.106.sh: rh105q.py verbs' own
#                         rules, unchanged (5.18.106's one write is AS-RB1's saveOverrides inside AS_PHP, which no rule
#                         here reads as a switch verb) — lk=<lines that open QF_PHP's lk case> qftool=<qf_tool calls other
#                         than show, --check or doctor> wrapper=<code lines using $QF_WRAPPER outside echo>
#                         verbs=<code lines invoking the switch tool's on, off, skip, set or erase outside printed text>
#   u1mirror BOOK PLUGIN NOW [HELD [UG]]
#                         U1 as QF_PHP uj reads it (deploy-5.18.106.sh 6955-7079): BOOK <data>/plugin.sqlite3, PLUGIN the
#                         installed plugin folder (its data/config.json), NOW the epoch to read at (the clock's, or the
#                         run's own at=), HELD the registry's held channels as AutomationPolicy::sqlHeld would hold them
#                         ('-' none, 'out:ID,…' or 'only:ID,…'; the registry's own reading is the release's and is not
#                         mirrored), UG 1 (Uganda, StaffJobsGate::applies; default) or 0. The settings as PluginConfig
#                         reads its three files (<plugin>/data/config.json, <data>/config.json, <data>/kyc_config.json,
#                         a later one winning a key; none of the keys read here is a vault key). Prints uj=mirror at=
#                         ug= en= hours= cutoff= ledger= out= held= places= place= classes= — out, held, places, place and
#                         classes in the uj line's own form, so the notes U1 prints can be set against them
#   u2u3 BOOK PLUGIN NOW [UG]
#                         U2 and U3 (deploy-5.18.106.sh 7081-7130): uj=mirror at= ug= hours= cutoff= last_send= sends7=
#                         approved= approved_stale= approved_fresh= approved_gone= failed7= failed7_f1= open= open_age=
#                         due105= due106= xs_all= xs_passes= xs_first_expire= xs_first_cancel= f5_multi=
#   rwd BOOK FOLLOWUP_ID… refusedWhileDelivering (bf29cbd lib/FollowUpService.php:256-296) for each follow-up, since its
#                         first approved draft's decided_at (the sender's own $d['decided_at']) on its own channel:
#                         rwd=<id>@<n, counted to 3 as F1 calls it>@<n, uncounted>@<the F1-kind refusals since>@<the
#                         deliveries on its channel since>;… (or <id>@nofu, <id>@nodraft) min=<least n, or -> ge3=<k>/<ids>
#   staleorder BOOK LIMIT_HOURS NOW [LO HI]
#                         the stale and gone order (design-v2 §A3): expireStale's SELECT (lib/FollowUpService.php:218-238)
#                         at NOW less LIMIT_HOURS — cutoff= stale= stale_gone= stale_present= first400_gone=<gone>/<of the
#                         400 oldest> gone_first=<every stale gone row before every stale present row: yes|no|-> open=
#                         open_gone= open_present= gone_older=<every open gone row older than every open present row:
#                         yes|no|-> planted_open= planted_conv= (with LO HI: open follow-ups on conversation ids LO..HI,
#                         and conversations holding those ids — 0) xs_all= xs_passes= xs_first_expire= xs_first_cancel=
#                         (U3's forecast: expireStale alone) and the first close pass forecast, cron/followup_close.php in
#                         its order — the main loop over allOpen(200,'enquiry') by due_at, then expireStale over what is
#                         still open: p1_main=cancelled:n,replied:n,human_closed:n,opted_out:n,exhausted:n
#                         p1_xs=expired:n,cancelled:n p1_cn=<the pass's cancels> p1_ex=<its expiries> (Uganda assumed)
#   rblast DIR STATE      as_rb_last (deploy-5.18.106.sh 8805-8818) over DIR (the run folder) beside STATE (this deploy's
#                         state file), and as_back_on's guards (8826-8839, the V1 guard of 8829 first) on STATE: last=
#                         t= file= records= v1= v1guard=pass|stop copy_at= before=yes|no|- would=offer-if-off|nothing:<why>
#                         (would: what as_back_on does when followup_auto_send reads off; it never reads the key)
#   cfgsha PLUGIN DATA    the four settings places' bytes: k=<sha256 of <data>/kyc_config.json> s=<of the store row
#                         kyc_config id 0, as stored> p=<of <plugin>/data/config.json> d=<of <data>/config.json> (absent,
#                         nodb, null, other or unread where there is nothing to hash) all=<16 hex over the four>
#   kycplaces PLUGIN DATA KEYS
#                         where each of the comma-separated KEYS is present, by place, and as the readers read it:
#                         file= row= pcfg= dcfg= (absent|decoded|undecodable|unreadable; the row also nodb|unread)
#                         keys=<key>@<place><type>,…;… (type s string, i int, f float, b bool, n null, a array; '-' in no
#                         place) eff=<key>:on|off;… (PluginConfig::read's files, as the jobs read each key: followup_enabled
#                         by empty(), the others by FILTER_VALIDATE_BOOLEAN) asplaces=k:…,s:…,p:…,d:… (AS_PHP's places= for followup_auto_send: on, off, - absent,
#                         ? unread) cs=<cs_found's claim switches in k or s, highest step first, '+' between, or -> pd=<the
#                         KEYS held by a config.json, key@p,d, or -> pre13=ok|<§L.7's conditions 1-3 that fail: file,row,
#                         differ> differ=<condition 3's keys, as AS_PHP lists them, or -> (conditions 4 and 5 are the
#                         script's alone: the writer's encodings and the installed code's sums)
#   closer BOOK COPYUNIX  since a copy (UJ-CA's and UJ-ON's counts, deploy-5.18.106.sh 7134-7159): since= sched=
#                         fc_last=<followup_close's last_run, an epoch, or never|unread> fc_last_iso= fc_dur=
#                         fc_state=none|ended|running control=held|not|unread on_expired= expired= cancelled=
#                         by_closer=cancelled:n,human_closed:n,opted_out:n,exhausted:n replied= and, the control holding,
#                         t= on_cancelled_t= (the closer's cancels at or after T, its last_run)
import sys, os, re, json, sqlite3, hashlib, time, math, glob

KIND_E = " AND COALESCE(f.kind,'enquiry')='enquiry'"
CLAIMS = ('offers_live_enabled', 'offers_test_enabled', 'quote_chase_enabled')   # highest step first (12, 11, 6)
BOOL_KEYS = ('ai_enabled', 'tools_legacy_phone_match', 'identity_enabled', 'starlink_mail_enabled', 'ai_shadow_compare')
STAFF_ROLES = ('admin', 'accountant', 'field_accountant', 'support', 'support_leader', 'support_engineer')
DEPARTMENT = ('sales', 'support', 'account')
PHP_WS = ' \t\n\r\0\x0b'
F1_LIKE = "detail LIKE '% [HTTP 400 on POST /message/sendText/%' AND detail NOT LIKE 'Got an HTML page%'"


def ro(db):
    c = sqlite3.connect('file:' + db + '?mode=ro', uri=True)
    c.execute('PRAGMA query_only = ON')
    return c


def has(con, t):
    return con.execute("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", (t,)).fetchone() is not None


def cols(con, t):
    return [r[1] for r in con.execute('PRAGMA table_info([%s])' % t)]


def haskind(con):   # FollowUpService::hasKind(): 089's column
    try:
        return 'kind' in cols(con, 'followups')
    except sqlite3.Error:
        return False


def rows_assoc(cur):
    """PDO::FETCH_ASSOC: each row a dict by column name, a later column of the same name winning (d.*, f.channel, …)."""
    names = [d[0] for d in cur.description] if cur.description else []
    out = []
    for r in cur.fetchall():
        x = {}
        for n, v in zip(names, r):
            x[n] = v
        out.append(x)
    return out


def sha16(s):
    return hashlib.sha256(s.encode('utf-8')).hexdigest()[:16]


# ── PHP's own conversions, as the release's code applies them ─────────────────────────────────────────────────────────
def php_trim(s):
    return s.strip(PHP_WS)


def php_float_str(v):   # (string) of a float under PHP's precision 14
    if v != v:
        return 'NAN'
    if v in (float('inf'), float('-inf')):
        return 'INF' if v > 0 else '-INF'
    if v == int(v) and abs(v) < 1e15:
        return str(int(v))
    s = '%.14G' % v
    if 'E' in s:
        m, e = s.split('E')
        if '.' not in m:
            m += '.0'
        s = m + 'E' + ('+' if not e.startswith('-') else '-') + e.lstrip('+-').lstrip('0')
    return s


def php_str(v):   # (string)$v
    if v is None or v is False:
        return ''
    if v is True:
        return '1'
    if isinstance(v, int):
        return str(v)
    if isinstance(v, float):
        return php_float_str(v)
    if isinstance(v, bytes):
        return v.decode('utf-8', 'replace')
    if isinstance(v, str):
        return v
    return 'Array'


NUMPFX = re.compile(r'[ \t\n\r\x0b\x0c]*([+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)')
NUMSTR = re.compile(r'[ \t\n\r\x0b\x0c]*[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?[ \t\n\r\x0b\x0c]*\Z')


def php_float(v):   # (float)$v
    if v is None or v is False:
        return 0.0
    if v is True:
        return 1.0
    if isinstance(v, (int, float)):
        return float(v)
    if isinstance(v, (list, dict)):
        return 1.0 if v else 0.0
    m = NUMPFX.match(php_str(v))
    if not m:
        return 0.0
    try:
        return float(m.group(1))
    except (ValueError, OverflowError):
        return 0.0


def php_int(v):   # (int)$v
    if isinstance(v, bool):
        return 1 if v else 0
    if isinstance(v, int):
        return v
    f = php_float(v)
    return int(f) if math.isfinite(f) else 0


def php_is_numeric(v):
    if isinstance(v, bool):
        return False
    if isinstance(v, (int, float)):
        return True
    return isinstance(v, str) and NUMSTR.match(v) is not None


def php_empty(v):
    if v is None or v is False:
        return True
    if v is True:
        return False
    if isinstance(v, (int, float)):
        return v == 0
    if isinstance(v, str):
        return v in ('', '0')
    if isinstance(v, (list, dict)):
        return len(v) == 0
    return False


def filter_bool(v):   # filter_var($v, FILTER_VALIDATE_BOOLEAN), no flags
    if v is True:
        return True
    if v is None or v is False or isinstance(v, (list, dict)):
        return False
    return php_trim(php_str(v)).lower() in ('1', 'true', 'on', 'yes')


def to_bool(v):   # PluginConfig::toBool
    if isinstance(v, bool):
        return v
    if isinstance(v, int):
        return v == 1
    if isinstance(v, str):
        return php_trim(v).lower() in ('1', 'true', 'yes', 'on')
    return False


def php_identical(a, b):   # ===, over json_decode(…, true)'s values: int, float, bool and string never equal each other
    if type(a) is not type(b):
        return False
    if isinstance(a, dict):
        return list(a.keys()) == list(b.keys()) and all(php_identical(a[k], b[k]) for k in a)
    if isinstance(a, list):
        return len(a) == len(b) and all(php_identical(x, y) for x, y in zip(a, b))
    return a == b


def _nojs(c):
    raise ValueError('not JSON: ' + c)


def php_json(b):
    """json_decode(…, true): (value, True), or (None, False) where PHP answers NULL for a text that is not JSON."""
    if isinstance(b, bytes):
        try:
            s = b.decode('utf-8')
        except UnicodeDecodeError:
            return None, False
    elif isinstance(b, str):
        s = b
    else:
        s = php_str(b)
    if s.strip(' \t\n\r') == '':
        return None, False
    try:
        return json.loads(s, parse_constant=_nojs), True
    except (ValueError, RecursionError):
        return None, False


def as_dict(v):
    return v if isinstance(v, dict) else {i: x for i, x in enumerate(v)}


def gm(ts):
    return time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime(ts))


ISO = re.compile(r'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\n?\Z')


def iso(t):   # the uj mode's $iso
    t = php_str(t)
    if ISO.match(t):
        return t.replace(' ', 'T') + 'Z'
    return 'never' if t == '' else 'unreadable'


LAB = re.compile(r'(sales|support|account|retailer)(-[0-9]{1,4})?\n?\Z')


def lab(ch):   # the uj mode's $lab: a generic channel label
    ch = php_str(ch)
    return ch if LAB.match(ch) else 'other'


ASNAME = re.compile(r'[A-Za-z0-9_.-]{1,80}\Z')


def asname(k):   # AS_PHP's $asName
    k = php_str(k)
    return k if ASNAME.match(k) else '?'


def aslist(a):   # AS_PHP's $asList
    return '-' if not a else ','.join(a[:12]) + (',+%d' % (len(a) - 12) if len(a) > 12 else '')


# ── the settings, as the readers read them ────────────────────────────────────────────────────────────────────────────
def as_json(path):
    """AS_PHP's $asJson: ('absent', {}) | ('unreadable', None) | ('undecodable', None) | ('decoded', dict)."""
    if not os.path.exists(path):
        return 'absent', {}
    if not os.path.isfile(path) or not os.access(path, os.R_OK):
        return 'unreadable', None
    try:
        with open(path, 'rb') as f:
            b = f.read()
    except OSError:
        b = b''   # @file_get_contents false: (string)false, which does not decode
    v, ok = php_json(b)
    if ok and isinstance(v, (dict, list)):
        return 'decoded', as_dict(v)
    return 'undecodable', None


def as_row(book):
    """AS_PHP's $asRow: the store row kyc_config id 0 — ('nodb', None) | ('absent', {}) | ('undecodable', {}) |
    ('decoded', dict) | ('unread', None)."""
    if not os.path.isfile(book):
        return 'nodb', None
    try:
        c = ro(book)
        c.text_factory = bytes
        try:
            if not has(c, 'kyc_config'):
                return 'absent', {}
            r = c.execute('SELECT data FROM kyc_config WHERE id = 0 LIMIT 1').fetchone()
            if r is None:
                return 'absent', {}
            v, ok = php_json(r[0] if r[0] is not None else b'')
            if ok and isinstance(v, (dict, list)):
                return 'decoded', as_dict(v)
            return 'undecodable', {}
        finally:
            c.close()
    except sqlite3.Error:
        return 'unread', None


def files_config(plugin, data):
    """PluginConfig::read's three files, merged in its order (a later file wins a key), normalised (BOOL_KEYS to booleans,
    strings trimmed). The vault (not read here) fills only its own keys: none that u1mirror, u2u3 or a followup_* eff= reads is one, but evo_api_key and evo_api_url are (lib/ConfigVault.php VAULT_KEYS), so kycplaces' eff= for those two is not PluginConfig::read's (no call site reads it)."""
    c = {}
    for p in (os.path.join(plugin, 'data', 'config.json'), os.path.join(data, 'config.json'), os.path.join(data, 'kyc_config.json')):
        if not os.path.isfile(p) or not os.access(p, os.R_OK):
            continue
        try:
            with open(p, 'rb') as f:
                v, ok = php_json(f.read())
        except OSError:
            continue
        if ok and isinstance(v, (dict, list)):
            c.update(as_dict(v))
    for k in BOOL_KEYS:
        if k in c:
            c[k] = to_bool(c[k])
    for k in list(c):
        if isinstance(c[k], str):
            c[k] = php_trim(c[k])
    return c


def age_hours(cfg):
    v = cfg.get('followup_max_age_hours')
    return 336.0 if v is None else php_float(v)


def stale_before(cfg, now):   # FollowUpService::staleBefore: null when the setting is not a positive number
    h = age_hours(cfg)
    if not (h > 0):
        return None
    x = h * 3600
    return gm(now - (int(x) if math.isfinite(x) else 0))


def stale_hours(cfg):   # FollowUpService::staleHours
    h = age_hours(cfg)
    if math.isfinite(h) and h == math.floor(h):
        return str(int(h))
    return php_float_str(h)


def ledger_mode(cfg, ug):   # the uj mode's $ledger: WaSendBudget::on, then ::enquiryClaims
    if not (php_str(cfg.get('wa_send_ledger', '')) == 'on' and ug):
        return 'off'
    for k in CLAIMS:
        if php_trim(php_str(cfg.get(k, ''))).lower() in ('1', 'true', 'on', 'yes'):
            return 'claim'
    return 'record'


# ── the sender's checks (cron/followup_send.php's order) ──────────────────────────────────────────────────────────────
def store_load(con, name):
    """The uj mode's read-only stub of the store's load(): the flat row (id 0) of the file's table, else every row's
    document in id order, else []."""
    t = re.sub(r'[^a-zA-Z0-9_]', '_', re.sub(r'\.json$', '', name))
    if not has(con, t):
        return []
    r = con.execute('SELECT data FROM [%s] WHERE id = 0 LIMIT 1' % t).fetchone()
    if r is not None and isinstance(r[0], str):
        v, ok = php_json(r[0])
        if ok and isinstance(v, (dict, list)):
            return v
    out = []
    for (j,) in con.execute('SELECT data FROM [%s] ORDER BY id ASC' % t):
        v, ok = php_json(j if isinstance(j, (str, bytes)) else php_str(j))
        if ok and isinstance(v, (dict, list)):
            out.append(v)
    return out


def phone_intl(raw):   # PhoneNumber::withDialCode(raw, the Uganda profile's '256', 9) — the copy keeps no dial-code override
    t = php_trim(raw)
    plus = t != '' and t[0] == '+'
    d = re.sub(r'[^0-9]+', '', t)
    if d == '':
        return None
    if d.startswith('00'):
        plus = True
        d = d[2:]
    if plus or len(d) >= 11:
        if d == '' or d[0] == '0' or len(d) < 10:
            return None
        return '+' + d
    if d[0] == '0':
        d = d[1:]
    return '+256' + d if len(d) == 9 else None


def is_colleague(rows, phone):   # StaffDirectory::activeStaffByPhone, the whole number
    want = re.sub(r'[^0-9]+', '', php_str(phone))
    if len(want) < 10:
        return False
    for r in (rows.values() if isinstance(rows, dict) else rows):
        if not isinstance(r, dict) or php_empty(r.get('is_active')):
            continue
        if php_empty(r.get('is_admin')) and php_trim(php_str(r.get('role', ''))).lower() not in STAFF_ROLES:
            continue
        raw = php_trim(php_str(r.get('phone', '')))
        if raw == '':
            continue
        p = phone_intl(raw)
        if p is not None and re.sub(r'[^0-9]+', '', p) == want:
            return True
    return False


def is_colleague_conv(rows, cv):   # ColleagueNumbers::isColleagueConversation
    if php_trim(php_str(cv.get('category', ''))).lower() == 'staff':
        return True
    return is_colleague(rows, php_str(cv.get('phone', '')))


def owned_hold(con, cfg, ug):   # OwnedNumberHold::forInstall: (on, the staff-owned channel ids, or None when unread)
    if filter_bool(cfg.get('wa_followups_on_owned_numbers', False)):
        return False, set()
    if not (filter_bool(cfg.get('multi_number_channels_enabled', False)) and ug):
        return False, set()
    try:
        if not has(con, 'wa_channels'):
            return False, set()
    except sqlite3.Error:
        return False, set()
    try:
        staff = set()
        for r in rows_assoc(con.execute("SELECT * FROM wa_channels ORDER BY CASE channel_id WHEN 'sales' THEN 0 WHEN 'support' THEN 1"
                                        " WHEN 'account' THEN 2 ELSE 3 END, created_at, channel_id")):
            if php_str(r.get('owner_type', '')) == 'staff':
                staff.add(php_str(r.get('channel_id')))
        return True, staff
    except sqlite3.Error:
        return True, None


def holds(oh, ch):   # OwnedNumberHold::holds
    on, staff = oh
    if not on or ch in DEPARTMENT:
        return False
    return True if staff is None else ch in staff


def opted_out(con, phone, ch):   # ContactOptOut::blocks(…, CLASS_PROACTIVE): any live row blocks a proactive send
    p = re.sub(r'[^0-9]+', '', php_str(phone))
    if p == '':
        return False
    try:
        return con.execute("SELECT id FROM contact_optouts WHERE phone = ? AND active = 1 AND (channel = '*' OR channel = ?)"
                           " ORDER BY CASE WHEN channel = '*' THEN 0 ELSE 1 END, id DESC LIMIT 1", (p, ch)).fetchone() is not None
    except sqlite3.Error:
        return False   # the release answers "not blocked" when it cannot read, loudly


def attempt_taken(con, fu, did, att):   # FollowUpService::attemptTaken
    try:
        r = con.execute("SELECT (SELECT COUNT(*) FROM followups WHERE id = ? AND attempts >= ?)"
                        " + (SELECT COUNT(*) FROM followup_drafts WHERE followup_id = ? AND id <> ? AND attempt = ? AND status IN ('sent', 'uncertain'))"
                        " + (SELECT COUNT(*) FROM followup_sends WHERE followup_id = ? AND (draft_id = ? OR attempt >= ?))",
                        (fu, att, fu, did, att, fu, did, att)).fetchone()
        return php_int(r[0]) > 0
    except sqlite3.Error:
        return False


END = object()


def rwd_count(con, fu, ch, since, enough):
    """FollowUpService::refusedWhileDelivering (bf29cbd lib/FollowUpService.php:256-296): each F1-kind refusal since
    `since` counted when a delivery on the channel (an outbound row with WhatsApp's id, placed by created_at) falls in
    (the refusal before it — for the first, since — , this refusal]; counted up to `enough`; 0 on any error."""
    try:
        refs = [r[0] for r in con.execute("SELECT created_at FROM followup_events WHERE followup_id = ? AND event = 'failed' AND created_at >= ?"
                                          " AND " + F1_LIKE + " ORDER BY id", (fu, since))]
        dl = None
        d = END
        prev = since
        n = 0
        for r in refs:
            r = php_str(r)
            if dl is None:
                dl = iter([x[0] for x in con.execute(
                    "SELECT m.created_at FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id"
                    " WHERE m.direction = 'out' AND m.sent_at > datetime(?, '-1 day') AND m.created_at > ?"
                    " AND m.wa_message_id IS NOT NULL AND m.wa_message_id <> '' AND c.channel = ? ORDER BY m.created_at, m.id",
                    (since, since, ch))])
                d = next(dl, END)
            while d is not END and php_str(d) <= prev:
                d = next(dl, END)
            if d is not END and php_str(d) <= r:
                n += 1
                if n >= enough:
                    break
            prev = r
        return n
    except sqlite3.Error:
        return 0


def need_tables(con):
    return [x for x in ('followups', 'followup_drafts', 'followup_sends', 'followup_events', 'wa_conversations', 'wa_messages') if not has(con, x)]


def held_sql(spec):   # AutomationPolicy::sqlHeld('f.channel')'s two forms, from the caller's statement of the held channels
    if spec in ('', '-', 'none'):
        return '', [], 'none'
    m = re.fullmatch(r'(out|only):([A-Za-z0-9_,-]+)', spec)
    ids = [x for x in m.group(2).split(',') if x] if m else []
    if not ids:
        raise ValueError('held')
    q = ','.join('?' * len(ids))
    return ' AND f.channel %s (%s)' % ('NOT IN' if m.group(1) == 'out' else 'IN', q), ids, '%s:%d' % (m.group(1), len(ids))


def gate_fields(cfg, now, ug):
    st = stale_before(cfg, now)
    return st, ['uj=mirror', 'at=%d' % now, 'ug=%s' % ('1' if ug else '0'), 'en=' + ('off' if php_empty(cfg.get('followup_enabled')) else 'on'),
                'hours=' + ('misread' if st is None else stale_hours(cfg)), 'cutoff=' + ('-' if st is None else iso(st))]


def u1mirror(a):
    book, plugin, now = a[0], a[1], int(a[2])
    spec = a[3] if len(a) > 3 else '-'
    ug = (a[4] if len(a) > 4 else '1') == '1'
    cfg = files_config(plugin, os.path.dirname(os.path.abspath(book)))
    hsql, hargs, hsay = held_sql(spec)
    con = ro(book)
    try:
        miss = need_tables(con)
        if miss:
            print('uj=no-table:' + ','.join(miss))
            return
        E = KIND_E if haskind(con) else ''
        stale, f = gate_fields(cfg, now, ug)
        ledger = ledger_mode(cfg, ug)
        f.append('ledger=' + ledger)
        ago = lambda s: gm(now - s)
        # U1 — each channel's last outbound delivery and its count in 24 h (deploy-5.18.106.sh 7012-7027)
        try:
            outs = {}
            for ch, mx, n in con.execute("SELECT c.channel, MAX(m.created_at), SUM(CASE WHEN m.created_at > ? THEN 1 ELSE 0 END)"
                                         " FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id"
                                         " WHERE m.direction = 'out' AND m.sent_at > datetime(?, '-1 day') AND m.created_at > ?"
                                         " AND m.wa_message_id IS NOT NULL AND m.wa_message_id <> '' GROUP BY c.channel",
                                         (ago(86400), ago(30 * 86400), ago(30 * 86400))).fetchall():
                l = lab(ch)
                o = outs.get(l, ['', 0])
                outs[l] = [max(o[0], php_str(mx)), o[1] + php_int(n)]
            chs = [r[0] for r in con.execute('SELECT DISTINCT f.channel FROM followups f WHERE f.closed_at IS NULL' + E)]
            if has(con, 'wa_channels'):
                chs += [r[0] for r in con.execute('SELECT channel_id FROM wa_channels')]
            for ch in chs:
                outs.setdefault(lab(ch), ['', 0])
            u1 = ['%s@%s@%d' % (l, 'none' if v[0] == '' else iso(v[0]), v[1]) for l, v in sorted(outs.items())]
            f.append('out=' + (';'.join(u1) if u1 else '-'))
        except (sqlite3.Error, ValueError, TypeError) as x:
            f.append('out=unread:' + type(x).__name__)
        # U1 — the sender's ten places, each classed by the sender's checks in its order (deploy-5.18.106.sh 7028-7079;
        # cron/followup_send.php 122-312): F5's two, the empty draft, gone, colleague, owned, opted out, taken over,
        # replied, F2, F1 (outside claim mode); whatever passes every check read here "waits"
        try:
            rows = rows_assoc(con.execute(
                "SELECT d.*, f.channel, f.phone, f.conversation_id, f.attempts FROM followup_drafts d JOIN followups f ON f.id = d.followup_id"
                " WHERE d.status = 'approved' AND f.closed_at IS NULL" + E + hsql + " ORDER BY d.decided_at LIMIT ?", hargs + [10]))
            try:
                colg = store_load(con, 'retailers.json') if ug else None
            except sqlite3.Error:
                colg = None   # ColleagueNumbers::forInstall answers null when its check cannot be set up
            own = owned_hold(con, cfg, ug)
            cls = {'held': 0, 'f5': 0, 'closes': 0, 'f2': 0, 'f1': 0, 'waits': 0}
            seen, pa = set(), []
            for i, d in enumerate(rows, 1):
                fu, ch, ph, dec = php_int(d.get('followup_id')), php_str(d.get('channel')), php_str(d.get('phone')), php_str(d.get('decided_at'))
                fr = con.execute("SELECT COUNT(*), COALESCE(SUM(CASE WHEN " + F1_LIKE + " THEN 1 ELSE 0 END), 0)"
                                 " FROM followup_events WHERE followup_id = ? AND event = 'failed' AND created_at >= ?", (fu, dec)).fetchone() or (0, 0)
                k = None
                if ug:
                    if fu in seen:
                        k = ('held', 'held')
                    else:
                        seen.add(fu)
                        if attempt_taken(con, fu, php_int(d.get('id')), php_int(d.get('attempt'))):
                            k = ('f5', 'f5-expires')
                eb = php_str(d.get('edited_body'))
                body = eb if php_trim(eb) != '' else php_str(d.get('body'))
                if k is None and php_trim(body) == '':
                    k = ('waits', 'waits')
                if k is None:
                    cr = rows_assoc(con.execute('SELECT * FROM wa_conversations WHERE id = ?', (php_int(d.get('conversation_id')),)))
                    cv = cr[0] if cr else None
                    lc = php_str(cv.get('last_customer_at')) if cv is not None else ''
                    if cv is None:
                        k = ('closes', 'closes-gone')
                    elif colg is not None and (is_colleague(colg, ph) or is_colleague_conv(colg, cv)):
                        k = ('closes', 'closes-colleague')
                    elif holds(own, ch):
                        k = ('closes', 'closes-owned')
                    elif opted_out(con, ph, ch):
                        k = ('closes', 'closes-optout')
                    elif php_str(cv.get('state')) == 'human_active':
                        k = ('closes', 'closes-takenover')
                    elif lc > dec:
                        k = ('closes', 'closes-replied')
                    elif ug and stale is not None and lc < stale:
                        k = ('f2', 'f2-expires')
                    elif ug and ledger != 'claim' and rwd_count(con, fu, ch, dec, 3) >= 3:
                        k = ('f1', 'f1-closes')
                    else:
                        k = ('waits', 'waits')
                cls[k[0]] += 1
                pa.append('%d@%s@%s@%d@%d' % (i, k[1], lab(ch), php_int(fr[0]), php_int(fr[1])))
            f += ['held=' + hsay, 'places=%d' % len(rows), 'place=' + (';'.join(pa) if pa else '-'),
                  'classes=' + ','.join('%s:%d' % (k, v) for k, v in cls.items())]
        except (sqlite3.Error, ValueError, TypeError) as x:
            f.append('places=unread:' + type(x).__name__)
        print(' '.join(f))
    finally:
        con.close()


def xs_rows(con, E, cut, lim):   # expireStale's SELECT (bf29cbd lib/FollowUpService.php:222-227), run as a SELECT only
    return con.execute("SELECT f.id, f.last_customer_at, c.id AS cid FROM followups f LEFT JOIN wa_conversations c ON c.id = f.conversation_id"
                       " WHERE f.closed_at IS NULL" + E + " AND f.last_customer_at < ?"
                       " AND (c.id IS NULL OR c.last_customer_at IS NULL OR c.last_customer_at < ?)"
                       " ORDER BY f.last_customer_at, f.id LIMIT ?", (cut, cut, lim)).fetchall()


def xs_forecast(con, E, cut):   # U3's: without the limit, its passes at 200, and its first pass split expired/cancelled
    if cut is None:
        return ['xs_all=-', 'xs_passes=-', 'xs_first_expire=-', 'xs_first_cancel=-']
    na = len(xs_rows(con, E, cut, -1))
    first = xs_rows(con, E, cut, 200)
    fg = sum(1 for r in first if r[2] is None)
    return ['xs_all=%d' % na, 'xs_passes=%d' % math.ceil(na / 200), 'xs_first_expire=%d' % (len(first) - fg), 'xs_first_cancel=%d' % fg]


def u2u3(a):
    book, plugin, now = a[0], a[1], int(a[2])
    ug = (a[3] if len(a) > 3 else '1') == '1'
    cfg = files_config(plugin, os.path.dirname(os.path.abspath(book)))
    con = ro(book)
    try:
        miss = need_tables(con)
        if miss:
            print('uj=no-table:' + ','.join(miss))
            return
        E = KIND_E if haskind(con) else ''
        stale, f = gate_fields(cfg, now, ug)
        f = [x for x in f if not x.startswith('en=')]
        ago = lambda s: gm(now - s)
        cnt = lambda sql, x=(): php_int(con.execute(sql, x).fetchone()[0])
        # U2 (deploy-5.18.106.sh 7085-7110)
        try:
            r = con.execute('SELECT MAX(s.sent_at) FROM followup_sends s JOIN followups f ON f.id = s.followup_id WHERE 1 = 1' + E).fetchone()
            f.append('last_send=' + iso(r[0] if r else None))
            f.append('sends7=%d' % cnt('SELECT COUNT(*) FROM followup_sends s JOIN followups f ON f.id = s.followup_id WHERE s.sent_at >= ?' + E, (ago(7 * 86400),)))
            r = con.execute("SELECT COUNT(*), COUNT(DISTINCT d.followup_id) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id"
                            " WHERE d.status = 'approved' AND f.closed_at IS NULL" + E).fetchone()
            f.append('approved=%d/%d' % (php_int(r[0]), php_int(r[1])))
            sp = {'stale': '0/0', 'fresh': '0/0', 'gone': '0/0'}
            sv = '' if stale is None else stale
            for k, n, m in con.execute(
                    "SELECT CASE WHEN c.id IS NULL THEN 'gone' WHEN ? <> '' AND COALESCE(c.last_customer_at, '') < ? THEN 'stale' ELSE 'fresh' END,"
                    " COUNT(*), COUNT(DISTINCT d.followup_id) FROM followup_drafts d JOIN followups f ON f.id = d.followup_id"
                    " LEFT JOIN wa_conversations c ON c.id = f.conversation_id WHERE d.status = 'approved' AND f.closed_at IS NULL" + E + " GROUP BY 1",
                    (sv, sv)).fetchall():
                sp[php_str(k)] = '%d/%d' % (php_int(n), php_int(m))
            f += ['approved_%s=%s' % (k, v) for k, v in sp.items()]
            r = con.execute("SELECT COUNT(*), COALESCE(SUM(CASE WHEN e.detail LIKE '% [HTTP 400 on POST /message/sendText/%'"
                            " AND e.detail NOT LIKE 'Got an HTML page%' THEN 1 ELSE 0 END), 0)"
                            " FROM followup_events e JOIN followups f ON f.id = e.followup_id WHERE e.event = 'failed' AND e.created_at >= ?" + E,
                            (ago(7 * 86400),)).fetchone()
            f += ['failed7=%d' % php_int(r[0]), 'failed7_f1=%d' % php_int(r[1])]
            d3, d7, d14 = ago(3 * 86400), ago(7 * 86400), ago(14 * 86400)
            r = con.execute("SELECT COUNT(*), COALESCE(SUM(CASE WHEN f.last_customer_at >= ? THEN 1 ELSE 0 END), 0),"
                            " COALESCE(SUM(CASE WHEN f.last_customer_at < ? AND f.last_customer_at >= ? THEN 1 ELSE 0 END), 0),"
                            " COALESCE(SUM(CASE WHEN f.last_customer_at < ? AND f.last_customer_at >= ? THEN 1 ELSE 0 END), 0),"
                            " COALESCE(SUM(CASE WHEN f.last_customer_at < ? THEN 1 ELSE 0 END), 0) FROM followups f WHERE f.closed_at IS NULL" + E,
                            (d3, d3, d7, d7, d14, d14)).fetchone()
            f += ['open=%d' % php_int(r[0]), 'open_age=lt3d:%d,3-7d:%d,7-14d:%d,gt14d:%d' % tuple(php_int(x) for x in r[1:5])]
            # FollowUpService::due (bf29cbd :170-192), its kind unaliased: 5.18.105's call, then the pin's as followup_run calls it
            k2 = " AND COALESCE(kind,'enquiry')='enquiry'" if E else ''

            def due(st, wait):
                sql, x = 'SELECT * FROM followups WHERE closed_at IS NULL AND due_at IS NOT NULL AND due_at <= ?' + k2, [gm(now)]
                if st is not None:
                    sql += ' AND last_customer_at >= ?'
                    x.append(st)
                if wait:
                    sql += (" AND NOT EXISTS (SELECT 1 FROM followup_drafts wd WHERE wd.followup_id = followups.id"
                            " AND (wd.status = 'approved' OR (wd.status = 'uncertain' AND wd.attempt = followups.attempts + 1)))")
                return len(con.execute(sql + ' ORDER BY due_at LIMIT ?', x + [-1]).fetchall())
            f += ['due105=%d' % due(None, False), 'due106=%d' % due(stale if ug else None, ug)]
        except (sqlite3.Error, ValueError, TypeError) as x:
            f.append('u2=unread:' + type(x).__name__)
        # U3 (deploy-5.18.106.sh 7111-7130)
        try:
            f += xs_forecast(con, E, stale)
            r = con.execute("SELECT COUNT(*), COALESCE(SUM(n), 0) FROM (SELECT COUNT(*) AS n FROM followup_drafts d JOIN followups f ON f.id = d.followup_id"
                            " WHERE d.status = 'approved' AND f.closed_at IS NULL" + E + " GROUP BY d.followup_id HAVING COUNT(*) >= 2)").fetchone()
            f.append('f5_multi=%d/%d' % (php_int(r[0]), php_int(r[1])))
        except (sqlite3.Error, ValueError, TypeError) as x:
            f.append('u3=unread:' + type(x).__name__)
        print(' '.join(f))
    finally:
        con.close()


def rwd(a):
    con = ro(a[0])
    try:
        out, mins, ge, n = [], [], 0, 0
        for x in a[1:]:
            fid = int(x)
            n += 1
            r = con.execute('SELECT channel FROM followups WHERE id = ?', (fid,)).fetchone()
            if r is None:
                out.append('%d@nofu' % fid)
                continue
            ch = php_str(r[0])
            s = con.execute("SELECT decided_at FROM followup_drafts WHERE followup_id = ? AND status = 'approved' ORDER BY decided_at, id LIMIT 1", (fid,)).fetchone()
            if s is None:
                out.append('%d@nodraft' % fid)
                continue
            since = php_str(s[0])
            n3, nall = rwd_count(con, fid, ch, since, 3), rwd_count(con, fid, ch, since, 1 << 30)
            rf = php_int(con.execute("SELECT COUNT(*) FROM followup_events WHERE followup_id = ? AND event = 'failed' AND created_at >= ? AND " + F1_LIKE,
                                     (fid, since)).fetchone()[0])
            dl = php_int(con.execute("SELECT COUNT(*) FROM wa_messages m JOIN wa_conversations c ON c.id = m.conversation_id"
                                     " WHERE m.direction = 'out' AND m.sent_at > datetime(?, '-1 day') AND m.created_at > ?"
                                     " AND m.wa_message_id IS NOT NULL AND m.wa_message_id <> '' AND c.channel = ?", (since, since, ch)).fetchone()[0])
            out.append('%d@%d@%d@%d@%d' % (fid, n3, nall, rf, dl))
            mins.append(n3)
            ge += 1 if n3 >= 3 else 0
        print('rwd=%s min=%s ge3=%d/%d' % (';'.join(out) or '-', min(mins) if mins else '-', ge, n))
    finally:
        con.close()


def staleorder(a):
    book, lim, now = a[0], php_float(a[1]), int(a[2])
    lo, hi = (int(a[3]), int(a[4])) if len(a) > 4 else (None, None)
    con = ro(book)
    try:
        E = KIND_E if haskind(con) else ''
        x = lim * 3600
        cut = gm(now - (int(x) if math.isfinite(x) else 0)) if lim > 0 else None
        f = ['cutoff=' + ('-' if cut is None else iso(cut))]
        if cut is None:
            f += ['stale=-', 'stale_gone=-', 'stale_present=-', 'first400_gone=-', 'gone_first=-']
        else:
            rows = xs_rows(con, E, cut, -1)
            gi = [i for i, r in enumerate(rows) if r[2] is None]
            pi = [i for i, r in enumerate(rows) if r[2] is not None]
            k = min(400, len(rows))
            f += ['stale=%d' % len(rows), 'stale_gone=%d' % len(gi), 'stale_present=%d' % len(pi),
                  'first400_gone=%d/%d' % (sum(1 for r in rows[:400] if r[2] is None), k),
                  'gone_first=' + ('-' if not gi or not pi else ('yes' if max(gi) < min(pi) else 'no'))]
        op = con.execute("SELECT CASE WHEN c.id IS NULL THEN 'gone' ELSE 'present' END, MIN(f.last_customer_at), MAX(f.last_customer_at), COUNT(*)"
                         " FROM followups f LEFT JOIN wa_conversations c ON c.id = f.conversation_id WHERE f.closed_at IS NULL" + E + " GROUP BY 1").fetchall()
        g = {php_str(r[0]): r for r in op}
        ng, npr = (php_int(g['gone'][3]) if 'gone' in g else 0), (php_int(g['present'][3]) if 'present' in g else 0)
        f += ['open=%d' % (ng + npr), 'open_gone=%d' % ng, 'open_present=%d' % npr,
              'gone_older=' + ('-' if not ng or not npr else ('yes' if php_str(g['gone'][2]) < php_str(g['present'][1]) else 'no'))]
        if lo is not None:
            f.append('planted_open=%d' % php_int(con.execute(
                'SELECT COUNT(*) FROM followups f LEFT JOIN wa_conversations c ON c.id = f.conversation_id'
                ' WHERE f.closed_at IS NULL AND f.conversation_id BETWEEN ? AND ? AND c.id IS NULL' + E, (lo, hi)).fetchone()[0]))
            f.append('planted_conv=%d' % php_int(con.execute('SELECT COUNT(*) FROM wa_conversations WHERE id BETWEEN ? AND ?', (lo, hi)).fetchone()[0]))
        else:
            f += ['planted_open=-', 'planted_conv=-']
        f += xs_forecast(con, E, cut)
        # the first close pass, cron/followup_close.php in its order: allOpen(200, 'enquiry') by due_at (lib/FollowUpService.php
        # :347-354), its five cases; then expireStale(staleBefore(now), 200) over the rows still open (Uganda)
        k2 = " AND COALESCE(kind,'enquiry') = 'enquiry'" if E else ''
        main = {'cancelled': 0, 'replied': 0, 'human_closed': 0, 'opted_out': 0, 'exhausted': 0}
        shut = set()
        for fu in rows_assoc(con.execute('SELECT * FROM followups WHERE closed_at IS NULL' + k2 + ' ORDER BY due_at LIMIT ?', (200,))):
            fid = php_int(fu.get('id'))
            cr = rows_assoc(con.execute('SELECT * FROM wa_conversations WHERE id = ?', (php_int(fu.get('conversation_id')),)))
            cv = cr[0] if cr else None
            why = None
            if cv is None:
                why = 'cancelled'
            else:
                lc = php_str(cv.get('last_customer_at'))
                if lc != '' and lc > php_str(fu.get('last_customer_at')):
                    why = 'replied'
                elif php_str(cv.get('state')) == 'human_active':
                    why = 'human_closed'
                elif opted_out(con, php_str(fu.get('phone')), php_str(fu.get('channel'))):
                    why = 'opted_out'
                elif php_int(fu.get('attempts')) >= php_int(fu.get('max_attempts')) and php_empty(fu.get('due_at')):
                    why = 'exhausted'
            if why is not None:
                main[why] += 1
                shut.add(fid)
        xe = xc = 0
        if cut is not None:
            left = [r for r in xs_rows(con, E, cut, -1) if php_int(r[0]) not in shut][:200]
            xc = sum(1 for r in left if r[2] is None)
            xe = len(left) - xc
        f += ['p1_main=' + ','.join('%s:%d' % kv for kv in main.items()), 'p1_xs=expired:%d,cancelled:%d' % (xe, xc),
              'p1_cn=%d' % (main['cancelled'] + xc), 'p1_ex=%d' % xe]
        print(' '.join(f))
    finally:
        con.close()


def lastval(path, key):   # sed -n 's/^KEY=//p' FILE | tail -n1
    try:
        with open(path, 'rb') as fh:
            t = fh.read().decode('utf-8', 'replace')
    except OSError:
        return ''
    v = ''
    for l in t.split('\n'):
        if l.startswith(key + '='):
            v = l[len(key) + 1:]
    return v


def word(v):
    return re.sub(r'\s', '_', v) if v != '' else '-'


def rblast(a):
    d, st = a[0], a[1]
    t, w, nrec = None, None, 0
    for f in sorted(glob.glob(glob.escape(st) + '.prev-*')) + sorted(glob.glob(os.path.join(glob.escape(d), 'rollback-state-*.env'))):
        if not os.path.isfile(f):
            continue
        v = lastval(f, 'RB_COPY_AT')
        if not re.fullmatch(r'[0-9]+', v):
            continue
        nrec += 1
        if t is None or int(v) > t:
            t, w = int(v), f
    last = '-' if w is None else (lastval(w, 'AS_RB1') or 'none')
    v1 = lastval(st, 'AS_RB1')
    guard = 'pass' if v1 in ('', 'off', 'not-written:owner-unknown', 'not-written:unread') else 'stop'
    c = lastval(st, 'COPY_AT')
    c = int(c) if re.fullmatch(r'[0-9]+', c) else None
    before = '-' if c is None or t is None else ('yes' if t < c else 'no')
    if guard == 'stop':
        would = 'nothing:v1guard'
    elif c is None:
        would = 'nothing:no-copy-at'
    elif t is None:
        would = 'nothing:no-record'
    elif not t < c:
        would = 'nothing:not-before'
    elif last != 'written':
        would = 'nothing:not-written'
    else:
        would = 'offer-if-off'
    print('last=%s t=%s file=%s records=%d v1=%s v1guard=%s copy_at=%s before=%s would=%s' % (
        word(last), '-' if t is None else t, '-' if w is None else word(os.path.basename(w)), nrec, word(v1), guard,
        '-' if c is None else c, before, would))


def sha_file(p):
    if not os.path.exists(p):
        return 'absent'
    if not os.path.isfile(p):
        return 'other'
    try:
        with open(p, 'rb') as fh:
            return hashlib.sha256(fh.read()).hexdigest()
    except OSError:
        return 'unread'


def sha_row(book):
    if not os.path.isfile(book):
        return 'nodb'
    try:
        c = ro(book)
        c.text_factory = bytes
        try:
            if not has(c, 'kyc_config'):
                return 'absent'
            r = c.execute('SELECT data FROM kyc_config WHERE id = 0 LIMIT 1').fetchone()
            if r is None:
                return 'absent'
            if r[0] is None:
                return 'null'
            b = r[0] if isinstance(r[0], bytes) else php_str(r[0]).encode('utf-8')
            return hashlib.sha256(b).hexdigest()
        finally:
            c.close()
    except sqlite3.Error:
        return 'unread'


def cfgsha(a):
    plugin, data = a[0], a[1]
    k = sha_file(os.path.join(data, 'kyc_config.json'))
    s = sha_row(os.path.join(data, 'plugin.sqlite3'))
    p = sha_file(os.path.join(plugin, 'data', 'config.json'))
    d = sha_file(os.path.join(data, 'config.json'))
    print('k=%s s=%s p=%s d=%s all=%s' % (k, s, p, d, sha16('|'.join((k, s, p, d)))))


def tletter(v):
    if isinstance(v, bool):
        return 'b'
    if isinstance(v, int):
        return 'i'
    if isinstance(v, float):
        return 'f'
    if isinstance(v, str):
        return 's'
    if v is None:
        return 'n'
    return 'a'


def kycplaces(a):
    plugin, data = a[0], a[1]
    keys = [k for k in a[2].split(',') if k]
    fs, fk = as_json(os.path.join(data, 'kyc_config.json'))
    rs, rw = as_row(os.path.join(data, 'plugin.sqlite3'))
    ps, pc = as_json(os.path.join(plugin, 'data', 'config.json'))
    ds, dc = as_json(os.path.join(data, 'config.json'))
    docs = (('k', fk), ('s', rw), ('p', pc), ('d', dc))
    per, pd = [], []
    for k in keys:
        pl = ['%s%s' % (c, tletter(doc[k])) for c, doc in docs if isinstance(doc, dict) and k in doc]
        per.append('%s@%s' % (asname(k), ','.join(pl) or '-'))
        h = [c for c, doc in (('p', pc), ('d', dc)) if isinstance(doc, dict) and k in doc]
        if h:
            pd.append('%s@%s' % (asname(k), ','.join(h)))
    cfg = files_config(plugin, data)
    eff = ['%s:%s' % (asname(k), (('off' if php_empty(cfg.get(k)) else 'on') if k == 'followup_enabled' else ('on' if filter_bool(cfg.get(k, False)) else 'off'))) for k in keys]   # followup_enabled as the jobs read it: empty() (cron/followup_close.php:33, followup_send.php:48); every other key FILTER_VALIDATE_BOOLEAN (followup_auto_send's, lib/FollowUpPolicy.php:101) (review r4 Q106-1)

    def asplace(doc):   # AS_PHP's $asPlace
        if not isinstance(doc, dict):
            return '?'
        return '-' if 'followup_auto_send' not in doc else ('on' if filter_bool(doc['followup_auto_send']) else 'off')
    cs = []
    for k in CLAIMS:   # cs_found (deploy-5.18.106.sh 7390-7397): present, with any value, in k or s
        p = [c for c, doc in (('k', fk), ('s', rw)) if isinstance(doc, dict) and k in doc]
        if p:
            cs.append('%s@%s' % (k, ','.join(p)))
    causes, differ = [], []
    if fk is None:
        causes.append('file')
    if rw is None:
        causes.append('row')
    if fk is not None and rw is not None:   # condition 3: every key of the file but followup_auto_send, === in the row
        for k, v in fk.items():
            if php_str(k) == 'followup_auto_send':
                continue
            if k not in rw or not php_identical(rw[k], v):
                differ.append(asname(k))
    if differ:
        causes.append('differ')
    print('file=%s row=%s pcfg=%s dcfg=%s keys=%s eff=%s asplaces=k:%s,s:%s,p:%s,d:%s cs=%s pd=%s pre13=%s differ=%s' % (
        fs, rs, ps, ds, ';'.join(per) or '-', ';'.join(eff) or '-', asplace(fk), asplace(rw), asplace(pc), asplace(dc),
        '+'.join(cs) or '-', ';'.join(pd) or '-', ','.join(causes) or 'ok', aslist(differ)))


def closer(a):
    book, cu = a[0], int(a[1])
    con = ro(book)
    try:
        E = KIND_E if haskind(con) else ''
        ci = gm(cu)
        f = ['since=' + iso(ci)]
        sst, ms = 'absent', None
        if has(con, 'master_schedule'):
            r = con.execute('SELECT data FROM [master_schedule] WHERE id = 0 LIMIT 1').fetchone()
            if r is not None and isinstance(r[0], (str, bytes)):
                v, ok = php_json(r[0])
                sst, ms = ('read', v) if ok and isinstance(v, (dict, list)) else ('unreadable', None)
        fc = ms.get('followup_close') if isinstance(ms, dict) else None
        fc = fc if isinstance(fc, dict) else None
        lr = php_int(fc.get('last_run')) if fc is not None and php_is_numeric(fc.get('last_run')) else None
        du = php_int(fc.get('duration_ms')) if fc is not None and php_is_numeric(fc.get('duration_ms')) else None
        if sst == 'unreadable' or (fc is not None and lr is None):
            ctl = 'unread'
        else:
            ctl = 'held' if lr is not None and lr > cu and du is not None and du >= 0 else 'not'
        f += ['sched=' + sst, 'fc_last=' + ('never' if lr is None else str(lr)), 'fc_last_iso=' + ('never' if lr is None else iso(gm(lr))),
              'fc_dur=' + ('-' if du is None else str(du)), 'fc_state=' + ('none' if lr is None else ('ended' if du is not None and du >= 0 else 'running')),
              'control=' + ctl]
        ev = lambda where, x: php_int(con.execute('SELECT COUNT(*) FROM followup_events e JOIN followups f ON f.id = e.followup_id WHERE ' + where + E, x).fetchone()[0])
        f.append('on_expired=%d' % ev("e.event = 'closed' AND e.actor = 'closer' AND f.close_reason = 'expired' AND instr(e.detail, ?) = 1 AND e.created_at >= ?",
                                      ('expired — the customer last wrote at ', ci)))
        f.append('expired=%d' % ev("e.event = 'closed' AND e.actor = 'closer' AND f.close_reason = 'expired' AND e.created_at >= ?", (ci,)))
        f.append('cancelled=%d' % ev("e.event = 'closed' AND e.actor = 'closer' AND f.close_reason = 'cancelled' AND e.created_at >= ?", (ci,)))
        f.append('by_closer=' + ','.join('%s:%d' % (w, ev("e.event IN ('closed', 'opted_out') AND e.actor = 'closer' AND f.close_reason = ? AND e.created_at >= ?", (w, ci)))
                                          for w in ('cancelled', 'human_closed', 'opted_out', 'exhausted')))
        f.append('replied=%d' % ev("e.event = 'closed' AND e.actor = 'closer' AND f.close_reason = 'replied' AND e.created_at >= ?", (ci,)))
        if ctl == 'held':
            t = gm(lr)
            f += ['t=' + iso(t), 'on_cancelled_t=%d' % ev("e.event = 'closed' AND e.actor = 'closer' AND f.close_reason = 'cancelled' AND e.created_at >= ?", (t,))]
        print(' '.join(f))
    finally:
        con.close()


def code_lines(t):
    for n, l in enumerate(t.split('\n'), 1):
        s = l.lstrip()
        if s.startswith('#') or s.startswith('//'):
            continue
        yield n, l


def verbs(path):   # rh105q.py verbs' own rules, unchanged
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


cmd = sys.argv[1] if len(sys.argv) > 1 else ''
a = sys.argv[2:]
try:
    if cmd == 'verbs':
        verbs(a[0])
    elif cmd == 'u1mirror':
        u1mirror(a)
    elif cmd == 'u2u3':
        u2u3(a)
    elif cmd == 'rwd':
        rwd(a)
    elif cmd == 'staleorder':
        staleorder(a)
    elif cmd == 'rblast':
        rblast(a)
    elif cmd == 'cfgsha':
        cfgsha(a)
    elif cmd == 'kycplaces':
        kycplaces(a)
    elif cmd == 'closer':
        closer(a)
    else:
        sys.stderr.write('rh106q.py: unknown mode\n')
        sys.exit(2)
except (sqlite3.Error, OSError, IndexError, ValueError, TypeError) as e:
    print('unread:%s' % type(e).__name__)
    sys.exit(3)

#!/usr/bin/env python3
# 5.18.103's rehearsal: its own readers, beside the previous releases' (rh102q.py, whose u102req, limits, salts and keychk
# it uses as they are; rh103.py, derived from 5.18.102's rh102.py): the store's applications as 5.18.103's notes and
# checks count them — QC-N3 (by quote_sale_entry), QC-N4 (5.18.102's web applications refused P1 with no quotation),
# QC-ON4 (G-7 over the web-direct records), QC-N7 (the plugin's active plans by type) and QC-RB2 (the web-direct
# applications with no quotation made, by sync status) — and the uCRM catalogue's table (ucrm_catalogue) as QC-G1, QC-N1
# and QC-RB1 read it; the state file's QC lines; the sheet's QC sections. Written here from the rules the release and the
# script state (root docs/72 §G.7, §G.10, §G.11, §G.13), in Python, over the store opened READ-ONLY — never through the
# release's code — so that the script's own reading is set against another. Each prints counts or states, never a name,
# a price, an id or a key (QC-RB2's ids go to a 0600 file named by the caller, never to stdout).
import sys, re, json, os, sqlite3


def read(p):
    try:
        return open(p, encoding='utf-8', errors='replace').read()
    except OSError:
        return ''


def rows_of(db, table):
    con = sqlite3.connect('file:' + db + '?mode=ro', uri=True)
    try:
        if not con.execute("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", (table,)).fetchone():
            return None
        out = []
        for (i, d) in con.execute('SELECT id, data FROM [%s] ORDER BY id' % table):
            try:
                r = json.loads(d)
            except (TypeError, ValueError):
                continue
            if isinstance(r, dict):
                r.setdefault('id', i)
                out.append(r)
        return out
    finally:
        con.close()


def php_empty(v):
    # PHP's empty(): null, false, 0, 0.0, '', '0', an empty array
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


def is_int0(v):
    # PHP's === 0: an integer zero, never a bool, a float or a string
    return type(v) is int and v == 0


def cart_keeps_g7(v):
    # G-7, as the script's local reading writes it for hw_cart_json and quote_sale_cart: absent (null) is fine; a string
    # must decode to an array (else it breaks G-7); a decoded or stored array must hold only arrays whose id is exactly 0
    if isinstance(v, str):
        try:
            v = json.loads(v)
        except ValueError:
            return False
        if not isinstance(v, (list, dict)):
            return False
    if v is None:
        return True
    if not isinstance(v, (list, dict)):
        return False
    items = list(v.values()) if isinstance(v, dict) else v
    return all(isinstance(c, dict) and is_int0(c.get('id')) for c in items)


def unquoted(r):
    # QC-RB2's "no quotation made": COALESCE(json_extract(data, '$.quote_created'), 0) IN (0, '', '0') — JSON false reads 0
    q = r.get('quote_created')
    if q is None or q is False:
        return True
    if isinstance(q, bool):
        return False
    if isinstance(q, (int, float)) and q == 0:
        return True
    return isinstance(q, str) and q in ('', '0')


cmd = sys.argv[1] if len(sys.argv) > 1 else ''
if cmd == 'apps':
    # apps DB — JSON, counts only: entry (QC-N3: web, app, web-direct, none — the key absent or null — and any other
    # value), webp1 (QC-N4: quote_sale_entry exactly 'web', quote_refused exactly 'P1', no quotation made by PHP's
    # empty()), g7 (QC-ON4: the web-direct records, and those breaking G-7 — package_choice or device_id not exactly '',
    # or a cart that is not absent or id-0 rows only), qcrb2 (the web-direct ones with no quotation made, by
    # crm_sync_status), plantype (QC-N7: the active plans by type — a type lowercased and trimmed, 'starlink' when null or
    # absent, 'other' when it is not 1–20 of a-z, 0-9, _ and -; sorted)
    apps = rows_of(sys.argv[2], 'kyc_applications') or []
    e = {'web': 0, 'app': 0, 'direct': 0, 'none': 0, 'other': 0}
    p1 = 0; dn = 0; bad = 0
    rb = {'unquoted': 0, 'pending': 0, 'review': 0, 'failed': 0, 'synced': 0}
    for r in apps:
        v = r.get('quote_sale_entry')
        k = 'none' if v is None else ('web' if v == 'web' else ('app' if v == 'app' else ('direct' if v == 'web-direct' else 'other')))
        if not isinstance(v, str) and v is not None:
            k = 'other'
        e[k] += 1
        if v == 'web' and r.get('quote_refused') == 'P1' and php_empty(r.get('quote_created')):
            p1 += 1
        if v != 'web-direct':
            continue
        dn += 1
        ok = cart_keeps_g7(r.get('hw_cart_json')) and cart_keeps_g7(r.get('quote_sale_cart'))
        pc, dv = r.get('package_choice'), r.get('device_id')
        if not (isinstance(pc, str) and pc == '') or not (isinstance(dv, str) and dv == '') or not ok:
            bad += 1
        if unquoted(r):
            rb['unquoted'] += 1
            st = r.get('crm_sync_status')
            if st in ('pending', 'review', 'failed', 'synced'):
                rb[st] += 1
    plans = rows_of(sys.argv[2], 'subscription_plans') or []
    t = {}
    for r in plans:
        if php_empty(r.get('is_active')):
            continue
        ty = r.get('type')
        ty = 'starlink' if ty is None else (str(ty) if not isinstance(ty, bool) else ('1' if ty else ''))
        ty = ty.strip().lower()
        ty = ty if re.fullmatch(r'[a-z0-9_-]{1,20}', ty) else 'other'
        t[ty] = t.get(ty, 0) + 1
    print(json.dumps({
        'entry': 'web:%d,app:%d,direct:%d,none:%d,other:%d' % (e['web'], e['app'], e['direct'], e['none'], e['other']),
        'webp1': p1, 'g7': 'direct:%d,bad:%d' % (dn, bad),
        'qcrb2': 'direct:%d,unquoted:%d,pending:%d,review:%d,failed:%d,synced:%d' % (dn, rb['unquoted'], rb['pending'], rb['review'], rb['failed'], rb['synced']),
        'plantype': ','.join('%s:%d' % (k, t[k]) for k in sorted(t)) or 'none'}))
elif cmd == 'ids':
    # ids DB OUT — the web-direct applications with no quotation made: their ids, "#a, #b" or "none", as QC-RB2 puts them
    # on the sheet, to the 0600 file OUT; prints how many
    apps = rows_of(sys.argv[2], 'kyc_applications') or []
    ids = sorted(int(r.get('id')) for r in apps if r.get('quote_sale_entry') == 'web-direct' and unquoted(r) and str(r.get('id', '')).isdigit())
    fd = os.open(sys.argv[3], os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    os.write(fd, (('#' + ', #'.join(str(i) for i in ids)) if ids else 'none').encode() + b'\n')
    os.close(fd)
    print(len(ids))
elif cmd == 'cat':
    # cat DB — the uCRM catalogue's table as the script's local reading and QC-RB1 count it: "absent" (never written), or
    # records:<all>,inforce:<ended_at_ts null>|switch:<none|on|off|two>|install:<unset|set|two>|std:<n in force>|hidden:<n>
    # — the switch on only where exactly one mode record is in force and its "on" is exactly true
    recs = rows_of(sys.argv[2], 'ucrm_catalogue')
    if recs is None:
        print('absent')
        sys.exit(0)
    inf = [r for r in recs if r.get('ended_at_ts') is None]
    by = {}
    for r in inf:
        by.setdefault(str(r.get('kind', '?')), []).append(r)
    m = by.get('mode', [])
    sw = 'none' if not m else ('two' if len(m) > 1 else ('on' if m[0].get('on') is True else 'off'))
    ins = by.get('install', [])
    print('records:%d,inforce:%d|switch:%s|install:%s|std:%d|hidden:%d' % (
        len(recs), len(inf), sw, 'unset' if not ins else ('two' if len(ins) > 1 else 'set'), len(by.get('standard', [])), len(by.get('hidden', []))))
elif cmd == 'stateqc':
    # stateqc STATE… — the QC lines the deploy records (QC_A, QC_ITEMS, QCSW_A, QCCAT_A), from the last state file given
    # that holds them: QC_A as written (counts by client, never a name), how many item hashes and whether every one is
    # "<32 hex>:<32 hex>", the switch and the table as stage A read them, and how many QC lines carry a seeded name
    last = {}
    for f in sys.argv[2:]:
        for line in read(f).split('\n'):
            m = re.match(r'^(QC_A|QC_ITEMS|QCSW_A|QCCAT_A)=(.*)$', line)
            if m:
                last[m.group(1)] = m.group(2)
    it = last.get('QC_ITEMS', '')
    hs = [h for h in it.split(',') if h] if it not in ('', 'none') else []
    shaped = 'shaped' if hs and all(re.fullmatch(r'[0-9a-f]{32}:[0-9a-f]{32}', h) for h in hs) else ('none' if it == 'none' else 'bad')
    names = sum(1 for v in last.values() if 'RH10' in v)
    print('qca:%s|items:%d:%s|sw:%s|cat:%s|names:%d' % (last.get('QC_A', '-'), len(hs), shaped, last.get('QCSW_A', '-'), last.get('QCCAT_A', '-'), names))
elif cmd == 'sheet':
    # sheet FILE — the sheet's QC sections: offer (what the forms offer, one per reading that read the catalogue), set (the
    # uCRM catalogue's settings and the applications, one per reading), rb2 (QC-RB2's), and the QC-ON4 line's presence
    s = read(sys.argv[2])
    print('offer:%d,set:%d,rb2:%d,on4:%d' % (
        len(re.findall(r'^== QC what the forms offer from uCRM in direct mode — ', s, re.M)),
        len(re.findall(r"^== QC the uCRM catalogue's settings and the applications on the copy — ", s, re.M)),
        len(re.findall(r'^== QC-RB2 the web-direct applications with no quotation — ', s, re.M)),
        len(re.findall(r'^  QC-ON4 web-direct applications that break G-7 ', s, re.M))))
elif cmd == 'rb2ids':
    # rb2ids FILE WHEN — the ids line under the sheet's QC-RB2 section headed WHEN ("stage A, before the rollback", …):
    # printed to the 0600 file named by $RH103_IDS_OUT, never to stdout; prints "found" or "absent"
    s = read(sys.argv[2]).split('\n')
    head = '== QC-RB2 the web-direct applications with no quotation — %s (application ids: never paste) ==' % sys.argv[3]
    got = None
    for i, l in enumerate(s):
        if l == head and i + 1 < len(s):
            got = s[i + 1].strip()
    if got is None:
        print('absent')
    else:
        fd = os.open(os.environ['RH103_IDS_OUT'], os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        os.write(fd, got.encode() + b'\n')
        os.close(fd)
        print('found')
elif cmd == 'section':
    # section FILE HEADPREFIX — the lines of the sheet's first section whose header begins with HEADPREFIX, up to the next
    # "== " header, to the 0600 file named by $RH103_SECTION_OUT; prints how many lines
    s = read(sys.argv[2]).split('\n')
    out = []; on = False
    for l in s:
        if l.startswith('== '):
            if on:
                break
            on = l.startswith(sys.argv[3])
        if on:
            out.append(l)
    fd = os.open(os.environ['RH103_SECTION_OUT'], os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    os.write(fd, ''.join(l + '\n' for l in out).encode())
    os.close(fd)
    print(len(out))
elif cmd == 'kv':
    # one field of a JSON object given as text: kv JSON KEY
    print(json.loads(sys.argv[2])[sys.argv[3]])
elif cmd == 'part':
    # part 'A|B|C' N — the Nth field (1-based) of a "|"-separated line
    print(sys.argv[2].split('|')[int(sys.argv[3]) - 1])
else:
    print('?' + cmd)
    sys.exit(2)

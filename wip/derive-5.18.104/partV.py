# Part V — 5.18.104's voice checks, Part V's codes VN (the voice notes) and VA (the acknowledgement) (root docs/73
# §H.3.25; §H.3.19, §H.3.20, §H.3.26, §H.3.28; rulings R7, R14, R18). Stage DS2. Registered after partH, it fills the
# slots partF and partR left, inserts its own probe (vn_probe.php.txt) and functions (vn_funcs.sh.txt) after partR's at the
# same anchor, and makes five small asserted edits to DS1's text that its codes need and that change no QF verdict:
#   - QF-G2 keeps its trial's and its negative control's lines (QF_G2_TRIAL, QF_G2_NEG) for VN-G7, its read of them;
#   - QF-G1's baseline call writes, in the same PHP process and before the copy's start is stamped, VN-CA4's baseline to
#     a file of the run's folder (D §H.3.25 VN-G6: "at QF-G1's one moment"; Part F §H.2.15.1) — QF_BASE's line is
#     unchanged, so none of the baseline's largest ids reaches the log through QF-G1's or QF-CA3's lines;
#   - QF-RB1 keeps the installed tool's lines (QF_RB1_OUT) for VN-RB1–VN-RB4;
#   - QF-RB3's VN-RB6 note becomes Part V's own VN-RB6, an instruction to the owner, not a note, with the first call's
#     date on the sheet (D §H.3.25 VN-RB6); QF-RB3 still reads the count, and it stands in when Part V's copy cannot be
#     made (QF_RB3_VC).
# Every value the fragments carry from the release is read off Git here and asserted (the methods that ask the gate and
# their calls, 8c's block, the fromMe branch, the settings tab, ruling R1's line, 090 against 089, the event processor's
# lists, VoiceTranscription::LABEL, 090's md5 and objects, the ladder's Part V rows and checks, and the tool's lines the
# checks read), each by the same rule the script applies at run time, so that a count which moves is a pin that is not the
# reviewed one.
import os, re
from d104lib import git, md5_at, fill

HERE = os.path.dirname(os.path.abspath(__file__))
TN_ANCHOR = "# ── 5.18.100: a technician may own a number (TN; read-only — as text, on a copy of the book, and the live store row) ──────\n"
# Ruling R1's ai_media line, byte for byte, as root docs/73 §H.3.19 (D 5306) prints it: the third of the four new jobs
R1_AI_MEDIA = ("    'ai_media'         => ['interval' => 60,  'uganda' => true, 'flag' => 'ai_media_enabled',         "
               "'script' => dirname(__DIR__) . '/run_media_worker.php'],")
V_ROWS = [("ack", ["L-ON", "VA-ON1"], "VA-ON1"), ("voice-measure", ["VN-ON1"], "VN-ON1"), ("voice-dry-run", ["VN-ON2"], "VN-ON2"),
          ("voice-staff", ["VN-ON3"], "VN-ON3"), ("voice-reply", ["VN-ON4"], "VN-ON4")]
ASKERS_REVIEWED = {("MediaPolicy", "enabled"), ("MediaPolicy", "voiceEnabled"), ("MediaPolicy", "voiceHandoverEnabled"),
                   ("MediaPolicy", "voiceReplyEnabled"), ("MediaPolicy", "voiceMode"), ("VoiceAck", "enabled")}


def put(text, key, value, n=1):
    if text.count(key) != n:
        raise SystemExit(f"VN PLACEHOLDER COUNT: {key} found {text.count(key)}, expected {n}")
    out = text.replace(key, value)
    if key in out:
        raise SystemExit(f"VN PLACEHOLDER RESIDUE: {key}")
    return out


def php_code_mask(src):
    """The PHP source with string literals and comments blanked (same length), for brace matching."""
    out, i, n = [], 0, len(src)
    while i < n:
        c = src[i]
        if src.startswith("//", i) or c == "#":
            j = src.find("\n", i)
            j = n if j < 0 else j
            out.append(" " * (j - i)); i = j; continue
        if src.startswith("/*", i):
            j = src.find("*/", i + 2)
            j = n if j < 0 else j + 2
            out.append(re.sub(r"[^\n]", " ", src[i:j])); i = j; continue
        if c in "'\"":
            j = i + 1
            while j < n and src[j] != c:
                j += 2 if src[j] == "\\" else 1
            out.append(c + " " * (min(j, n - 1) - i - 1) + c); i = j + 1; continue
        out.append(c); i += 1
    return "".join(out)


def static_methods(src):
    """name -> body text of every static method (public or private) of the file's one class."""
    mask = php_code_mask(src)
    out = {}
    for m in re.finditer(r"\bstatic function (\w+)\s*\(", mask):
        b = mask.find("{", m.end())
        semi = mask.find(";", m.end())
        if b < 0 or (0 <= semi < b):
            continue
        d, j = 0, b
        while j < len(mask):
            if mask[j] == "{": d += 1
            elif mask[j] == "}":
                d -= 1
                if d == 0: break
            j += 1
        out[m.group(1)] = src[b:j + 1]
    return out


def awk_calls(lines, pat):
    """vn_calls's awk, line for line: calls, one-argument calls, unclosed calls, calls whose second argument names no dataDir."""
    calls = one = unclosed = nodir = 0
    rx = re.compile(pat)
    for line in lines:
        line = re.sub(r"^[^:]*:[^:]*:[0-9]+:", "", line, count=1)
        rest = line
        while True:
            m = rx.search(rest)
            if not m:
                break
            calls += 1
            s = rest[m.end():]
            depth, commas, q, closed, i, p1, p2 = 1, 0, "", False, 0, 0, 0
            while i < len(s):
                c = s[i]
                if q:
                    if c == "\\": i += 2; continue
                    if c == q: q = ""
                    i += 1; continue
                if c in "\"'": q = c; i += 1; continue
                if c in "([{": depth += 1
                elif c in ")]}":
                    depth -= 1
                    if depth == 0:
                        closed = True
                        if not p2: p2 = i
                        break
                elif c == "," and depth == 1:
                    commas += 1
                    if commas == 1: p1 = i
                    elif commas == 2: p2 = i
                i += 1
            if not closed: unclosed += 1
            elif commas < 1: one += 1
            elif "dataDir" not in s[p1 + 1:p2]: nodir += 1
            rest = rest[m.end():]
    return calls, one, unclosed, nodir


def apply(doc, ctx):
    repo, plugin, pin, base = ctx["repo"], ctx["plugin"], ctx["pin_full"], ctx["base_full"]
    show = lambda p, c=pin: git(repo, "show", f"{c}:{plugin}/{p}").decode()

    # ── the ladder: Part V's five rows, their checks, and their place in the one sequence (rulings R7, R11) ──
    lad = show("lib/SalesFollowupLadder.php")
    rows = re.findall(r"^        '([a-z0-9-]+)' => \['n' => '([0-9b]+)', 'part' => '([A-Z])'(.*?)(?=^        '[a-z0-9-]+' => \['n' =>|^    \];)",
                      lad, re.S | re.M)
    vrows = [(r[0], r[3]) for r in rows if r[2] == "V"]
    if [r[0] for r in vrows] != [v[0] for v in V_ROWS]:
        raise SystemExit(f"VN: the ladder's Part V rows are {[r[0] for r in vrows]}")
    for (name, body), (_, checks, _c) in zip(vrows, V_ROWS):
        m = re.search(r"'check' => \[([^\]]*)\]", body)
        got = re.findall(r"'([A-Z0-9-]+)'", m.group(1)) if m else None
        if got != checks:
            raise SystemExit(f"VN: row {name} checks {got}, expected {checks}")
    seq = re.search(r"const SEQUENCE = \[(.*?)\];", lad, re.S)
    sq = re.findall(r"'([a-z0-9-]+)'", seq.group(1)) if seq else []
    vpos = [sq.index(s) for s in ["voice-reply", "voice-staff", "voice-dry-run", "voice-measure", "ack"]] if sq else []
    if not sq or vpos != sorted(vpos) or sq[-1] != "ack":
        raise SystemExit(f"VN: SEQUENCE does not place Part V's rows as rulings R7/R11 do: {sq}")
    vn_steps = " ".join(f"{name}:{c}" for name, _, c in V_ROWS)

    # ── the methods that ask the gate, derived from their text (B5-fix1's reading of D 4199 against D 4194) ──
    meth = {"MediaPolicy": static_methods(show("lib/MediaPolicy.php")), "VoiceAck": static_methods(show("lib/VoiceAck.php"))}
    asks = set()
    while True:   # a fixed point: a method asks when its body calls StaffJobsGate::applies or a method that asks
        more = set()
        for cls, ms in meth.items():
            for name, body in ms.items():
                if (cls, name) in asks:
                    continue
                hit = "StaffJobsGate::applies(" in body
                for (oc, on) in asks:
                    if (oc == cls and re.search(r"\bself::" + on + r"\(", body)) or re.search(r"\b" + oc + r"::" + on + r"\(", body):
                        hit = True
                if hit:
                    more.add((cls, name))
        if not more:
            break
        asks |= more
    pub = set()
    for cls in meth:
        src = show(f"lib/{cls}.php")
        for (c, name) in asks:
            if c == cls and re.search(r"public static function " + name + r"\(", src):
                pub.add((c, name))
    if pub != ASKERS_REVIEWED:
        raise SystemExit(f"VN-G4: the public methods that ask the gate are {sorted(pub)}, reviewed {sorted(ASKERS_REVIEWED)}")
    for (c, name) in pub:   # each takes ?string $dataDir second
        if not re.search(r"public static function " + name + r"\(array \$config, \?string \$dataDir[,)]", show(f"lib/{c}.php")):
            raise SystemExit(f"VN-G4: {c}::{name} does not take ?string $dataDir second")
    mp = sorted(n for (c, n) in pub if c == "MediaPolicy")
    askers = "(MediaPolicy::(" + "|".join(mp) + ")|VoiceAck::enabled)[(]"
    out = git(repo, "grep", "-nE", askers, pin[:7], "--", f"{plugin}/*.php", f":(exclude){plugin}/tests", f":(exclude){plugin}/docs").decode()
    glines = [l for l in out.split("\n") if l]
    calls, one, unclosed, nodir = awk_calls(glines, askers)
    if one or unclosed or nodir or calls < 10:
        raise SystemExit(f"VN-G4: asker calls {calls}, one-argument {one}, unclosed {unclosed}, second argument not the data directory {nodir}")
    for l in glines:   # none of them in a comment (the run-time scan does not read comments; here they would be counted)
        code = re.sub(r"^[^:]*:[^:]*:[0-9]+:", "", l, count=1).strip()
        if code.startswith(("//", "*", "#", "/*")):
            raise SystemExit(f"VN-G4: an asker call in a comment: {l[:120]}")

    # ── 8c's block, inside its try/catch (vn_8c's rule) ──
    evo = show("evo_webhook.php").split("\n")
    blocks = inb = t = cin = cout = req = 0
    for l in evo:
        if l.startswith("    // ── 8c. 5.18.104"):
            blocks += 1; inb = 1; t = 0; continue
        if inb and l.startswith("    // ── 9. Queue for the AI"):
            inb = 0
        if inb:
            if l == "        try {": t = 1
            elif l.startswith("        } catch (\\Throwable $e)"): t = 0
            if re.search(r"(MediaPolicy|VoiceAck|InboundMedia|StaffJobsGate)::", l) or "$bus->emit(" in l:
                if t: cin += 1
                else: cout += 1
            if "require_once" in l and not t: req += 1
    if (blocks, cout, req) != (1, 0, 0) or cin < 8:
        raise SystemExit(f"VN-G4: 8c blocks={blocks} in={cin} out={cout} require_out={req}")

    # ── the fromMe branch: 5.18.103's but for Part F's W1 (vn_fromme, vn_fromme_w1) ──
    def fromme(lines):
        o, f = [], False
        for l in lines:
            if l == "    if ($fromMe) {": f = True
            if f: o.append(l)
            if f and l == "    }": break
        return o
    pb, pp = fromme(show("evo_webhook.php", base).split("\n")), fromme(evo)
    keep, w1, marks, d = [], 0, 0, 0
    for l in pp:
        if not d and "5.18.104 (root docs/73" in l and "R-07)" in l: d = 1
        if d == 1:
            w1 += 1
            if "WaSeenMarks::note(" in l and "'out'" in l: marks += 1
            if l == "        }": d = 2
            continue
        keep.append(l)
    if not pb or keep != pb or marks != 1 or w1 < 3:
        raise SystemExit(f"VN-G4: the fromMe branch is not 5.18.103's but for W1 (w1={w1}, marks={marks}, equal={keep == pb})")

    # ── the settings tab (vn_tab), the pin's and, as the control, 5.18.103's ──
    def tab(text):
        inp = emp = asg = look = 0
        for l in text.split("\n"):
            if 'name="openai_api_key"' in l:
                inp += 1; look = 4; continue
            if look > 0:
                look -= 1
                if "value=\"<?= $_aiKeyUg ? '' : " in l: emp += 1; look = 0
            if "$_aiKeyUg = StaffJobsGate::applies(" in l: asg += 1
        return inp, emp, asg
    if tab(show("tabs/engage/whatsapp.php")) != (1, 1, 1) or tab(show("tabs/engage/whatsapp.php", base))[1:] != (0, 0):
        raise SystemExit("VN-G4/VN-K2: the settings tab's OpenAI key is not rendered empty on Uganda as reviewed (or 5.18.103's already is)")

    # ── ruling R1's ai_media line, the third of the four new jobs (vn_r1) ──
    master = show("cron/master.php").split("\n")
    if sum(1 for l in master if l == R1_AI_MEDIA) != 1:
        raise SystemExit("VN-G4: master.php does not hold ruling R1's ai_media line byte for byte, once")
    jobs, seen, at = [], False, 0
    for l in master:
        if re.match(r"^\s*'notify_retry'\s*=>", l): seen = True; continue
        if seen and re.match(r"^\s*'[a-z_]+'\s*=>", l):
            jobs.append(re.match(r"^\s*'([a-z_]+)'", l).group(1))
            if l == R1_AI_MEDIA: at = len(jobs)
        if seen and l.startswith("];"): break
    if jobs != ["quote_chase", "quote_daily_list", "ai_media", "offers_send"] or at != 3:
        raise SystemExit(f"VN-G4: master's jobs after notify_retry are {jobs}, ai_media at {at}")

    # ── 090 against 089: the _schema_checks CREATE byte for byte, then the INSERT keyed 090, no INDEXED BY (ruling R9) ──
    def sc_create(text):
        o, f = [], False
        for l in text.split("\n"):
            if re.match(r"^CREATE TABLE IF NOT EXISTS _schema_checks \(", l): f = True
            if f: o.append(l)
            if f and l.endswith(");"): break
        return o
    m89, m90 = show("migrations/089_quote_followups.sql"), show("migrations/090_wa_voice.sql")
    if not sc_create(m89) or sc_create(m89) != sc_create(m90):
        raise SystemExit("VN-G4: 090's _schema_checks CREATE is not 089's byte for byte")
    l90 = m90.split("\n")
    ci = next(i for i, l in enumerate(l90) if l.startswith("CREATE TABLE IF NOT EXISTS _schema_checks ("))
    ii = [i for i, l in enumerate(l90) if l == "INSERT INTO _schema_checks (migration, expected, found)"]
    if len(ii) != 1 or ii[0] < ci or not l90[ii[0] + 1].startswith("SELECT '090_wa_voice.sql', ") or "indexed by" in m90.lower():
        raise SystemExit("VN-G4: 090's INSERT is not keyed 090_wa_voice.sql after the CREATE, or 090 holds INDEXED BY")

    # ── the event processor's two lists (VN-CA3) ──
    ep = [l for l in show("cron/event_processor.php").split("\n") if re.match(r"^\s*\$_epWorkerOwned\s*=", l)]
    epb = [l for l in show("cron/event_processor.php", base).split("\n") if re.match(r"^\s*\$_epWorkerOwned\s*=", l)]
    if len(ep) != 1 or "['ai.reply', 'ai.media', 'ai.voice_ack', 'crm.lead.sync'] : ['ai.reply'];" not in ep[0] \
            or epb != ["$_epWorkerOwned = $_epUg ? ['ai.reply', 'crm.lead.sync'] : ['ai.reply'];"]:
        raise SystemExit(f"VN-CA3: the event processor's lists are not as reviewed ({ep} / {epb})")

    # ── VoiceTranscription::LABEL, 090's md5 and _schema_checks count, its objects on its three tables ──
    lab = re.findall(r"public const LABEL = '([^'\\]*)';", show("lib/VoiceTranscription.php"))
    if len(lab) != 1 or not lab[0].startswith("[voice message"):
        raise SystemExit(f"VN-RB3: VoiceTranscription::LABEL is {lab}")
    md5 = md5_at(repo, pin, f"{plugin}/migrations/090_wa_voice.sql")
    sc90 = [s.split(":")[1] for s in ctx["qf_sc"].split() if s.startswith("090_wa_voice.sql:")]
    if len(sc90) != 1 or sc90[0] != "79":
        raise SystemExit(f"VN: 090's _schema_checks count is {sc90}")
    nobj = len(re.findall(r"^CREATE TABLE IF NOT EXISTS (?:wa_media|wa_voice_calls|wa_voice_acks) \(", m90, re.M)) + \
           len(re.findall(r"^CREATE (?:UNIQUE )?INDEX IF NOT EXISTS \w+ ON (?:wa_media|wa_voice_calls|wa_voice_acks)\(", m90, re.M))
    if nobj != 12:
        raise SystemExit(f"VN-G2: 090 makes {nobj} objects on its three tables, reviewed 12")

    # ── the tool's lines and the hooks' counts the checks read ──
    tool = show("tools/sales_followup.php")
    for s in ["sf_out('OFF ' . $name);", "sf_out('  hook ' . $step . ': ' . implode(' ', $p));", "sf_out('FAIL off_hook ' . $step);",
              "sf_out('FAIL rollback_assert voice — pending audio '", "sf_out($step . ': ' . $st['status']);",
              "sf_out('  ' . $id . ': ' . SalesFollowupLadder::status(", "sf_out('    counts: ' . implode(' ', $counts));",
              "sf_out('    note: ' . (string)$n);", "foreach (array_values(array_unique($wasOn)) as $k) sf_out('was on: ' . $k);"]:
        if tool.count(s) != 1:
            raise SystemExit(f"VN: tools/sales_followup.php has {s!r} {tool.count(s)} times, expected 1")
    vs = show("lib/VoiceSteps.php")
    for s in ["$c = ['settled' => $st->rowCount()];", "$c['waited_s'] = self::drain(", "$c['voice_turns_left'] = self::voiceTurnsPending($rw);",
              "$c['ai_media_events_pending'] = ", "$c = ['ack_events_at_start' => self::ackEvents($rw)];", "$c['ack_events_left'] = self::ackEvents($rw);",
              "$c['ack_events_left_processing'] = "]:
        if s not in vs:
            raise SystemExit(f"VN-RB: lib/VoiceSteps.php's off-hooks do not count {s!r}")
    if show("tools/cron_status.php").count("$r['off'] ? 'OFF (switch)'") != 1:
        raise SystemExit("VN-CA2: tools/cron_status.php's OFF (switch) state is not the one vn_cs reads")

    # ── the fragments, filled ──
    probe = put(open(os.path.join(HERE, "vn_probe.php.txt"), encoding="utf-8").read(), "@@VN_LABEL@@", lab[0])
    if "\nPHP\n" in "\n" + probe + "\n" or "@@" in probe:
        raise SystemExit("VN: the probe holds a line PHP (which would end its heredoc) or a placeholder")
    # DS2-fix1 R01: every mode that reads a COPY through PluginConfig::read($root, $dir) reads the copy's own vault and data
    # folder (the modes reading the LIVE files, g3 and k1, take $live and $pdd and are not in the list)
    vault_line = "if (in_array($mode, ['g1', 'g5', 'ca1'], true)) {\n"
    if probe.count(vault_line) != 1 or probe.count("    putenv('DN_VAULT_FILE=' . $vd . '/vault.json'); putenv('DN_DATA_DIR=' . $vd);\n") != 1:
        raise SystemExit("VN (DS2-fix1 R01): the probe does not set DN_VAULT_FILE and DN_DATA_DIR once for its copy modes")
    listed = re.findall(r"'(\w+)'", vault_line)
    for blk in probe.split("\n    case '")[1:]:
        mode = blk.split("'", 1)[0]
        if "PluginConfig::read($root, $dir)" in blk and mode not in listed:
            raise SystemExit(f"VN (DS2-fix1 R01): mode {mode} reads a copy through PluginConfig::read without its own vault")
        if mode in listed and "PluginConfig::read($root, $dir)" not in blk:
            raise SystemExit(f"VN (DS2-fix1 R01): mode {mode} is listed for the copy's vault but reads no copy through PluginConfig::read")
    for s in ["' ug_gate=', $gate", "' ugai=', $modes($cai, $dir)", "' factory=', $fac, ' factory_ctl=', $facc",
              "$fk = $c; $fk['openai_api_key'] = $SENTINEL;", "$fac = TranscriberFactory::fromConfig($fk) === null",
              "$facc = TranscriberFactory::fromConfig($fkc) === null", "$tail = ' | tenant='"]:
        if probe.count(s) != 1:
            raise SystemExit(f"VN (DS2-fix1 R01/R02): the probe holds {s!r} {probe.count(s)} times, expected 1")
    # R02: the factory is never judged on a configuration with no key, nothing calls a transcriber, and the sentinel is
    # used only where it is set (the two scratch configurations), never printed
    if "TranscriberFactory::fromConfig($c)" in probe or "->transcribe(" in probe or probe.count("$SENTINEL") != 3:
        raise SystemExit("VN (DS2-fix1 R02): the factory is judged on a configuration with no key, a transcriber is called, or the sentinel travels")
    funcs = open(os.path.join(HERE, "vn_funcs.sh.txt"), encoding="utf-8").read()
    for k, v in [("@@VN_PHP@@", probe.rstrip("\n")), ("@@VN_090_MD5@@", md5), ("@@VN_090_SC@@", sc90[0]), ("@@VN_OBJ_N@@", str(nobj)),
                 ("@@VN_ASKERS@@", askers), ("@@VN_CALLS_N@@", str(calls)), ("@@VN_8C_IN@@", str(cin)), ("@@VN_W1_N@@", str(w1)),
                 ("@@VN_R1_LINE@@", R1_AI_MEDIA), ("@@VN_EP_LINE@@", ep[0]), ("@@VN_STEPS@@", vn_steps)]:
        if "\nTXT\n" in "\n" + v + "\n" or ("'" in v and k == "@@VN_ASKERS@@"):
            raise SystemExit(f"VN: {k}'s value cannot travel in its quoting")
        funcs = put(funcs, k, v)
    if "@@" in funcs:
        raise SystemExit("VN: a placeholder is left in vn_funcs")
    ctx.update(vn_probe=probe, vn_funcs=funcs, vn_calls=calls, vn_8c_in=cin, vn_w1=w1, vn_md5=md5, vn_askers=askers)
    doc.must("\nqf_rb3() {", 1, why="partR ran first")
    doc.insert_before(TN_ANCHOR, funcs + "\n", 1, why="VN functions and probe")
    fns = ["vn_php", "vn_pin", "vn_base_text", "vn_book_copy", "vn_8c", "vn_calls", "vn_fromme", "vn_fromme_w1", "vn_tab", "vn_r1",
           "vn_sc_create", "vn_text_gates", "vn_stage", "vn_g7", "vn_base_prep", "vn_baseline", "vn_after", "vn_on_one", "vn_on",
           "vn_rb1", "vn_rb_after"]
    for fn in fns:
        doc.must(f"\n{fn}() {{", 1, why=f"{fn} defined once")
    if doc.t.index("\nqf_rb3() {") > doc.t.index("\nvn_php() {") or doc.t.index("\nvn_rb_after() {") > doc.t.index(TN_ANCHOR):
        raise SystemExit("VN: the functions are not between partR's and the TN section")

    # ── DS1's text the codes need (asserted edits; no QF verdict changes) ──
    doc.insert_after('  o="$(qf_php trial "$sub/code" "$sub")"\n', '  QF_G2_TRIAL="$o"   # 5.18.104 Part V: VN-G7 reads 090\'s half of this one trial\n',
                     1, why="QF-G2 keeps its trial line")
    doc.insert_after('  o="$(qf_php neg "$sub/code" "$sub")"; cih_drop "$sub"\n', '  QF_G2_NEG="$o"     # 5.18.104 Part V: VN-G7 reads Part V\'s negative control\n',
                     1, why="QF-G2 keeps its control line")
    doc.insert_after('  o="$(UCRM_CONTAINER="$CONTAINER" bash "$REPO/$QF_WRAPPER" off all --rollback 2>&1 </dev/null | qf_safe)"; rc=${PIPESTATUS[0]}\n',
                     '  QF_RB1_OUT="$o"   # 5.18.104 Part V: VN-RB1–VN-RB4 read the tool\'s own lines (its rows cleared, its hooks\' counts)\n',
                     1, why="QF-RB1 keeps the tool's lines")
    base_old = ("        foreach ($JOBS as $j) if (is_array($ms) && isset($ms[$j]) && is_array($ms[$j])) $e[] = $j . ':' . (int)($ms[$j]['last_run'] ?? 0);\n"
                "        $t = time();\n")
    base_new = ("        foreach ($JOBS as $j) if (is_array($ms) && isset($ms[$j]) && is_array($ms[$j])) $e[] = $j . ':' . (int)($ms[$j]['last_run'] ?? 0);\n"
                "        if (isset($a[1]) && (string)$a[1] !== '') {   // 5.18.104 Part V (root docs/73 §H.3.25 VN-G6): VN-CA4's baseline, in this\n"
                "            // one call and before the copy's start is stamped — the rows and largest ids of 090's tables, the largest\n"
                "            // ai.media and ai.voice_ack event ids — to the file named (the run's folder; the sheet), never this line\n"
                "            try {\n"
                "                $vr = []; $vm = []; $ve = [];\n"
                "                foreach (['wa_media', 'wa_voice_calls', 'wa_voice_acks'] as $x) {\n"
                "                    $vr[] = $has($p, $x) ? (int)$one($p, 'SELECT COUNT(*) FROM ' . $x) : -1;\n"
                "                    $vm[] = $has($p, $x) ? (int)$one($p, 'SELECT COALESCE(MAX(id), 0) FROM ' . $x) : 0;\n"
                "                }\n"
                "                foreach (['ai.media', 'ai.voice_ack'] as $x) $ve[] = $has($p, 'events') ? (int)$one($p, 'SELECT COALESCE(MAX(id), 0) FROM events WHERE event_type = ?', [$x]) : 0;\n"
                "                $vl = 'rows=' . implode(',', $vr) . ' max=' . implode(',', $vm) . ' evmax=' . implode(',', $ve);\n"
                "            } catch (Throwable $vx) { $vl = '?' . get_class($vx); }   // never $e: this mode's entries\n"
                "            if (@file_put_contents((string)$a[1], $vl . \"\\n\") !== false) @chmod((string)$a[1], 0600);\n"
                "        }\n"
                "        $t = time();\n")
    doc.sub(base_old, base_new, 1, why="QF-G1's baseline call takes VN-CA4's in the same moment")
    doc.sub('  QF_BASE="$(qf_php base "$PDD_IN/plugin.sqlite3")"\n',
            '  vn_base_prep   # 5.18.104 Part V: the file VN-CA4\'s baseline is written to, in the same call (root docs/73 §H.3.25 VN-G6)\n'
            '  QF_BASE="$(qf_php base "$PDD_IN/plugin.sqlite3" "${VN_BASE_F:-}")"\n', 1, why="QF-G1's baseline call names VN's file")
    rb6_old = ('  if [ "$(qf_kv "$o" voice_calls)" != "0" ] && [ -n "$(qf_kv "$o" voice_calls)" ]; then\n'
               '    note "QF-RB3 (VN-RB6) OpenAI was called from here ($(qf_kv "$o" voice_calls) wa_voice_calls row(s)): ROTATE the OpenAI key on OpenAI\'s own pages — $BASELINE_VERSION\'s settings page prints it"\n'
               '  fi\n')
    doc.sub(rb6_old, '  # VN-RB6 is Part V\'s own (vn_rb_after, below): an instruction to the owner, not a note (root docs/73 §H.3.25); this\n'
                     '  # read\'s count stands in when Part V\'s copy cannot be made\n'
                     '  QF_RB3_VC="$(qf_kv "$o" voice_calls)"\n', 1, why="QF-RB3's VN-RB6 note becomes Part V's VN-RB6")

    # ── the slots ──
    fill(doc, "VN-G7", "  vn_g7   # VN-G7 — Part V's read of QF-G2's one trial (root docs/73 §H.3.25; ruling R10)\n")
    fill(doc, "VN-G stage A", "  vn_stage   # VN-G1–VN-G6 — Part V's stage A (root docs/73 §H.3.25): a refusal stops here, nothing changed\n")
    fill(doc, "VA-G stage A", "  # (VA has no stage-A code of its own, root docs/73 §H.3.25: P5's keys are VN-G1's read of QF-G4, its daily ceiling\n"
                              "  # VN-G5's baseline, its events VN-G6's; VN-G7 covers 090, whose wa_voice_acks P5 writes.)\n")
    fill(doc, "baseline", "  vn_baseline   # VN-G6 — VN-CA4's redeploy baseline, read in QF-G1's own call above (root docs/73 §H.3.25)\n")
    fill(doc, "VN-CA", '  vn_after "$inst"   # VN-M1, VN-M2, VN-CA1–VN-CA4, VN-K1, VN-K2 — after the copy, through the installed code\n')
    fill(doc, "VA-CA", "  # (VA has no after-the-copy code of its own: VN-CA1 reads VoiceAck::enabled, VN-CA4 the acknowledgements' claims\n"
                       "  # and decisions; VA-ON1 judges them once step 2 is on.)\n")
    fill(doc, "VN-ON", '  vn_on "$sub"   # VA-ON1 and VN-ON1–VN-ON4 — each step that is on, through the installed tool\'s --check\n')
    fill(doc, "VN-RB by hand", "  # (Part V adds no by-hand statement, as root docs/73 §H.2.16 lists none: its pending audio rows and events are read\n"
                               "  # by nothing in 5.18.103, and on a redeploy the media worker settles a row it finds before switch-on (ruling R14);\n"
                               "  # its keys are in the list above.)\n")
    fill(doc, "VN-RB", "  vn_rb1   # VN-RB1–VN-RB4 — Part V's reads of QF-RB1's result, before the put-back (root docs/73 §H.3.25)\n")
    fill(doc, "VN-RB3", '  vn_rb_after "$QF_RB3_VC"   # VN-RB5 and VN-RB6 — after the put-back (root docs/73 §H.3.25; ruling R7)\n')
    for c in ["  vn_g7   #", "  vn_stage   #", "  vn_baseline   #", '  vn_after "$inst"   #', '  vn_on "$sub"   #', "  vn_rb1   #", '  vn_rb_after "$QF_RB3_VC"   #']:
        doc.must(c, 1, why="slot filled once")
    order = [doc.t.index(x) for x in ["\nqf_stage() {", "  vn_g7   #", "  vn_stage   #", "\nqf_clear_and_baseline() {", "  vn_base_prep   #",
                                      "  vn_baseline   #", "\nqf_after() {", '  vn_after "$inst"   #', "\nqf_on() {", '  vn_on "$sub"   #',
                                      "\nqf_rb1() {", "  vn_rb1   #", "\nqf_rb3() {", '  vn_rb_after "$QF_RB3_VC"   #']]
    if order != sorted(order):
        raise SystemExit("VN: a call is not inside the function its slot belongs to")

    # ── what the operator reads: the header, the options, the names and the steps' line ──
    doc.insert_after("#            and, after the put-back, QF-RB3 (the rollback paragraph below).\n",
        "#\n"
        "#   5.18.104'S OWN CHECKS, Part V's (VN \"voice notes\", VA \"voice acknowledgement\"; root docs/73 §H.3.25): counts,\n"
        "#            fixed codes and key and schema names in the log; event ids, the baseline's largest ids and the first OpenAI\n"
        "#            call's date on the sheet; the OpenAI key \"set\" or \"not set\", never its value; nothing calls OpenAI. Refusals\n"
        "#            before the copy, on a copy through the PIN's code and as the pin's text from Git: VN-G1 a read of QF-G4's\n"
        "#            one rule for Part V's keys (its settings printed, never NO-GO); VN-G2 none of 090's tables on a first\n"
        "#            deploy, exactly 090's objects and its _schema_checks row on a redeploy; VN-G4 the pin's voice gates as text\n"
        "#            (8c inside its try/catch, every gate call passing the data directory, the fromMe branch 5.18.103's but\n"
        "#            for W1, the OpenAI key's input empty on Uganda, ruling R1's ai_media line, 090 against 089, no INDEXED BY);\n"
        "#            VN-G6 no voice event on a first deploy (on a redeploy, the history counted); VN-G7 090's half of QF-G2's\n"
        "#            trial. Notes: VN-G3 the key set or not, ai_provider, ai_enabled; VN-G5 the voice baseline. On a redeploy\n"
        "#            VN-G6 takes VN-CA4's baseline in QF-G1's own call, at its one moment. After the copy: VN-M1 and VN-M2 090\n"
        "#            on the book; VN-CA1 the installed gates off on Uganda and inert as South Sudan with every voice key on;\n"
        "#            VN-CA2 ai_media listed OFF (switch) by cron_status, and never as South Sudan; VN-CA3 the event\n"
        "#            processor's two lists; VN-CA4 nothing of Part V written by the dark build (judged in the deploy run);\n"
        "#            VN-K1 the key in none of the plugin's logs; VN-K2 the installed tab. In an --after-only run, VA-ON1 and\n"
        "#            VN-ON1–VN-ON4 through the installed tool's --check, each step that is on. In the rollback, VN-RB1–VN-RB4\n"
        "#            read QF-RB1's result before the put-back; after it, VN-RB5 and VN-RB6 (an instruction to rotate the key\n"
        "#            whenever OpenAI was called).\n", 1, why="the header's Part V paragraph")
    doc.sub("# follow-up (and VN-RB6's instruction to rotate the OpenAI key whenever OpenAI was called). 089's, 090's and 091's\n",
            "# follow-up (and Part V's VN-RB5 and VN-RB6, its instruction to rotate the OpenAI key whenever OpenAI was called;\n"
            "# VN-RB1–VN-RB4 read QF-RB1's result before the put-back). 089's, 090's and 091's\n", 1, why="the rollback paragraph names VN-RB")
    doc.sub("# its own command, with its own log, read-only, nothing deployed (QF-CA1–QF-CA8; QF-ON0–QF-ON5 for each step that is\n# on); then",
            "# its own command, with its own log, read-only, nothing deployed (QF-CA1–QF-CA8, VN-M1–VN-K2; QF-ON0–QF-ON5, VA-ON1 and\n"
            "# VN-ON1–VN-ON4 for each step that is on); then", 1, why="the after-only line names VN")
    doc.sub("#   --after-only         the deploy already happened: run the checks again — QF-CA1–QF-CA8 and QF-ON0–QF-ON5 (each step\n",
            "#   --after-only         the deploy already happened: run the checks again — QF-CA1–QF-CA8, VN-M1, VN-M2, VN-CA1–VN-CA4,\n"
            "#                        VN-K1, VN-K2, and QF-ON0–QF-ON5, VA-ON1 and VN-ON1–VN-ON4 (each step\n", 1, why="the --after-only option names VN")
    doc.sub('echo "                    checks (QF, Part F\'s; root docs/73 §H.2.15); QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4,"\n',
            'echo "                    checks (QF, Part F\'s; root docs/73 §H.2.15); VN-G1–VN-G7, VN-M1, VN-M2, VN-CA1–VN-CA4, VN-K1, VN-K2,"\n'
            'echo "                    VN-ON1–VN-ON4, VN-RB1–VN-RB6 and VA-ON1 are Part V\'s (VN, VA; §H.3.25); QC-G1, QC-N1–QC-N7, QC-CA1–QC-CA3, QC-ON1–QC-ON4,"\n',
            1, why="the names line names VN and VA")
    doc.sub('  echo "                    After each step, --after-only (check it, above): QF-ON0–QF-ON5 judge each step that is on."\n',
            '  echo "                    After each step, --after-only (check it, above): QF-ON0–QF-ON5, VA-ON1 and VN-ON1–VN-ON4 judge"\n'
            '  echo "                    each step that is on."\n', 1, why="the steps' line names VA and VN")

    # ── the result, as a whole ──
    for code in ["VN-G1", "VN-G2", "VN-G3", "VN-G4", "VN-G5", "VN-G6", "VN-G7", "VN-M1", "VN-M2", "VN-CA1", "VN-CA2", "VN-CA3", "VN-CA4",
                 "VN-K1", "VN-K2", "VN-RB1", "VN-RB1b", "VN-RB2", "VN-RB3", "VN-RB4", "VN-RB5", "VN-RB6"]:
        n = len(re.findall(r'(?:ok|bad|note|stop) "(?:NO-GO \()?' + re.escape(code) + r'[ )]', doc.t))
        if n < 1 and code not in ("VN-RB6",):
            raise SystemExit(f"VN: no ok/bad/note/stop line reports {code}")
    doc.must("INSTRUCTION TO THE OWNER (VN-RB6)", 1)
    # DS2-fix1: VN-CA1's Uganda half is judged only where the copy reads as Uganda and the factory's control built one
    # (R01, R02); an unread wa_voice_calls count is a FAIL and the instruction still prints (R03)
    doc.must('        if [ "$(qf_kv "$o" ug_gate)" != "1" ]; then\n          bad "VN-CA1 the copy does not read as Uganda', 1, why="R01")
    doc.must('        elif [ "$(qf_kv "$o" factory_ctl)" != "built" ]; then\n          bad "VN-CA1 the transcriber factory\'s control', 1, why="R02")
    doc.must('|$(qf_kv "$o" factory)" = "off:$n|off:$n|0|0|0|0|none" ] \\\n            && ok "VN-CA1 on Uganda, as the copy reads after the copy (tenant uganda', 1, why="R01/R02")
    doc.must("  case \"$n\" in\n    0) return 0 ;;\n    ''|*[!0-9]*)\n      bad \"VN-RB6 the wa_voice_calls count could not be read", 1, why="R03")
    doc.mustnot("  case \"$n\" in ''|0) return 0 ;; esac\n", why="R03: an unread count is never taken for none")
    doc.must('      n=""; first="first call: not read"\n', 1, why="R03")
    if doc.t.index('bad "VN-RB6 the wa_voice_calls count could not be read') > doc.t.index("INSTRUCTION TO THE OWNER (VN-RB6)"):
        raise SystemExit("VN (DS2-fix1 R03): the unread count's FAIL is not before the instruction")
    doc.must('vn_on_one "$1" "${p##*:}" "${p%%:*}"', 1)
    doc.must(f'VN_STEPS="{vn_steps}"', 1)
    doc.mustnot('note "QF-RB3 (VN-RB6) OpenAI was called from here')
    doc.mustnot("@@VN_")

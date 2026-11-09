# Part F — 5.18.104's own checks, Part F's codes QF (root docs/73 §H.2.15): the probe (qf_probe.php.txt) and the bash
# functions (qf_funcs.sh.txt) inserted before the carried TN section; the option --clear-switches parsed beside 5.18.103's;
# the calls — stage A (qf_stage, after QC-G1), stage B (qf_clear_and_baseline, once DEPLOY is typed and before the
# checkout and the copy), after the copy (qf_after, after QC-CA1) and --after-only (qf_on) — and the marked slots where
# DS2 (Part V: VN, VA), DS3 (Part O: OF) and DS4 (Part P: AC) add theirs. Every value the fragments carry from the release
# (the _schema_checks counts, the 085 shape, the hooks and NOTIFY_KINDS' events) is read off Git here, never typed.
# partR (the rollback functions) is inserted at the same anchor, after these.
import os, re
from d104lib import git

HERE = os.path.dirname(os.path.abspath(__file__))
TN_ANCHOR = "# ── 5.18.100: a technician may own a number (TN; read-only — as text, on a copy of the book, and the live store row) ──────\n"


def snippet(name):
    return open(os.path.join(HERE, name), encoding="utf-8").read()


def put(text, key, value, n=1):
    """Replace exactly n placeholders; assert the count before and none after."""
    if text.count(key) != n:
        raise SystemExit(f"PLACEHOLDER COUNT: {key} found {text.count(key)}, expected {n}")
    out = text.replace(key, value)
    if key in out:
        raise SystemExit(f"PLACEHOLDER RESIDUE: {key}")
    return out


def apply(doc, ctx):
    repo, plugin, pin = ctx["repo"], ctx["plugin"], ctx["pin_full"]
    show = lambda p: git(repo, "show", f"{pin}:{plugin}/{p}").decode()

    # ── from Git: each migration's _schema_checks key and expected count (the verdict, §H.2.4) ──
    sc = []
    for m in ctx["migrations"]:
        name = m.split("/", 1)[1]
        hits = re.findall(r"INSERT INTO _schema_checks \(migration, expected, found\)\nSELECT '" + re.escape(name) + r"', (\d+),", show(m))
        if len(hits) != 1:
            raise SystemExit(f"QF: {name}'s _schema_checks INSERT found {len(hits)} times, expected 1")
        sc.append(f"{name}:{hits[0]}")
    if [s.split(":")[0] for s in sc] != ["089_quote_followups.sql", "090_wa_voice.sql", "091_wa_offers.sql"]:
        raise SystemExit(f"QF: the migrations are {sc}")
    if sc[0] != "089_quote_followups.sql:38" or show("tools/sales_followup.php").count("sf_out('089 names: '") != 1:
        raise SystemExit(f"QF: 089's count is not 38, or the doctor's '089 names:' line is not the tool's ({sc[0]}) — qf_stage reads 38 of 38")
    sc_expect = " ".join(sc)
    ctx["qf_sc"] = sc_expect

    # ── from Git: the 085 shape (Part V's negative control), as tests/test_migration_090.php builds it ──
    t90 = show("tests/test_migration_090.php")
    cols = re.search(r"'wa_media' => \[([^\]]*)\]", t90)
    new8 = re.search(r"const M90_NEW = \[([^\]]*)\];", t90)
    if not cols or not new8:
        raise SystemExit("QF: M90_COLS['wa_media'] or M90_NEW not found in tests/test_migration_090.php")
    allc = re.findall(r"'([a-z_0-9]+)'", cols.group(1))
    newc = re.findall(r"'([a-z_0-9]+)'", new8.group(1))
    c085 = [c for c in allc if c not in newc]
    if (len(allc), len(newc), len(c085)) != (32, 8, 24):
        raise SystemExit(f"QF: wa_media {len(allc)} columns, {len(newc)} new, {len(c085)} in 085's shape (32, 8, 24 expected)")
    if "PARTIAL: 090_wa_voice.sql" not in t90 or "CHECK constraint failed" not in t90:
        raise SystemExit("QF: the 085-shape case's expectations are not the test's")

    # ── from Git: QF-G3's hooks and marks (D §H.2.15.1: three, one, one; W1–W3), and NOTIFY_KINDS' events. Each class
    # file's hook is counted in the method that holds it, by the reader the script's awk applies (a line naming a function
    # opens that method): NotificationService's three once each in sendVia, sendDocument and sendImage (D's names),
    # AlertService's in sendTo, StarlinkMailWorker's in the one method that holds it; the file totals beside them; the
    # webhook's marks are top-level code, counted per file. method '-' is the whole file. ──
    fn_re = re.compile(r"^\s*((public|private|protected|static|final|abstract)\s+)*function\s+[A-Za-z_][A-Za-z0-9_]*\s*\(")
    def per_method(text, nd):
        cur, out = "", {}
        for ln in text.split("\n"):
            if fn_re.match(ln):
                cur = re.sub(r"\s*\(.*", "", re.sub(r"^.*function\s+", "", ln))
            if nd in ln:
                out[cur] = out.get(cur, 0) + 1
        return out
    hooks = [("lib/NotificationService.php", "WaSendBudget::recordNotify(", 3, ["sendVia", "sendDocument", "sendImage"]),
             ("lib/AlertService.php", "WaSendBudget::recordAlert(", 1, ["sendTo"]),
             ("workers/StarlinkMailWorker.php", "WaSendBudget::recordDirect(", 1, None),
             ("evo_webhook.php", "WaSeenMarks::note(", 2, []), ("evo_webhook.php", "WaSeenMarks::noNumber(", 1, [])]
    hk = []
    for f, nd, want, methods in hooks:
        text = show(f)
        n = sum(1 for ln in text.split("\n") if nd in ln)   # as grep -cF counts: lines
        if n != want:
            raise SystemExit(f"QF-G3: {f} has {nd} on {n} lines, the design says {want}")
        if any(ch in f + nd for ch in " |*?"):
            raise SystemExit(f"QF-G3: {f}|{nd} cannot travel as one word")
        hk.append(f"{f}|{nd}|{n}|-")
        pm = per_method(text, nd)
        if methods is None:   # the one method that holds it, whatever it is called
            if len(pm) != 1 or "" in pm:
                raise SystemExit(f"QF-G3: {f}'s {nd} is not in exactly one method ({pm})")
            methods = list(pm)
        for m in methods:
            if pm.get(m) != 1:
                raise SystemExit(f"QF-G3: {f}'s {nd} is in {m} {pm.get(m, 0)} times, the design says once ({pm})")
            hk.append(f"{f}|{nd}|1|{m}")
        if methods and sum(pm.get(m, 0) for m in methods) != n:
            raise SystemExit(f"QF-G3: {f}'s {nd} lies outside the methods named ({pm})")
    ctx["qf_hooks"] = hk

    # ── from Git: HK7 (§H.2.6.7) — the pin's own scan and class table and HK07's controls, as the suite runs them ──
    twsb = show("tests/test_wa_send_budget.php")
    scan = re.findall(r"^function wsb_send_sites\(string \$tree\): array\n\{\n.*?^\}$", twsb, re.S | re.M)
    if len(scan) != 1:
        raise SystemExit(f"QF-G3: wsb_send_sites() found {len(scan)} times in tests/test_wa_send_budget.php")
    hk07 = re.findall(r"\$ctx->run\('HK07 [^']*', function \(\) use \(\$ctx\) \{\n        \$classes = (\[\n.*?\n        \]);\n        \$sites = wsb_send_sites\(\$ctx->tree\(\)\);\n"
                      r"        \$ctx->t\(count\(\$sites\) >= (\d+), ", twsb, re.S)
    if len(hk07) != 1:
        raise SystemExit(f"QF-G3: HK07's class table and its count control found {len(hk07)} times")
    classes, hk7_min = hk07[0]
    if twsb.count("in_array(['workers/AiReplyWorker.php', 'handle'], array_map(function ($s) { return [$s[0], $s[1]]; }, $sites), true)") != 1:
        raise SystemExit("QF-G3: HK07's AiReplyWorker::handle control is not the one the probe applies")
    if "'lib/NotificationService.php' => [" not in classes or "'workers/AiReplyWorker.php' => [" not in classes or int(hk7_min) < 15:
        raise SystemExit("QF-G3: HK07's class table does not read as reviewed")
    ctx["hk7"] = (scan[0], classes, hk7_min)
    blk = re.search(r"const NOTIFY_KINDS = \[(.*?)\];", show("lib/WaSendBudget.php"), re.S)
    events = re.findall(r"'([a-z0-9_]+)'\s*=>", blk.group(1)) if blk else []
    if len(events) != 13:
        raise SystemExit(f"QF-G3: NOTIFY_KINDS has {len(events)} events, expected 13")
    for ev in events:   # each a literal elsewhere in the pin's tree — QF-G3's run-time rule, asserted here first
        found = git(repo, "grep", "-lF", f"'{ev}'", pin, "--", plugin, f":(exclude){plugin}/lib/WaSendBudget.php", f":(exclude){plugin}/tests").decode()
        if not found.strip():
            raise SystemExit(f"QF-G3: event {ev} is not a literal elsewhere in the pin's tree")

    # ── the tool's own lines the checks read (qf_stage, qf_after, qf_on, qf_rb1), each exactly once in the pin ──
    tool = show("tools/sales_followup.php")
    for s in ["sf_out('OFF — not judged')", "sf_out($step . ': OFF — not judged')", "sf_out('not this install')",
              "sf_out('was on: ' . $k)", "sf_out('  the ledger: '", "sf_out('openai key: '",
              "sf_out(($ok ? 'PASS ' : 'FAIL schema_check_missing:') . $f)", "sf_out('rollback: every 5.18.104 key is unset')",
              "sf_out('VN-RB6: OpenAI was called from here"]:
        if tool.count(s) != 1:
            raise SystemExit(f"QF: tools/sales_followup.php has {s!r} {tool.count(s)} times, expected 1")
    if show("tools/sales_followup_report.php").count("'== sales follow-up report (READ-ONLY) — '") != 1:
        raise SystemExit("QF-N3: the report's headline is not the one qf_stage reads")
    if show("tools/cron_status.php").count("'OFF (switch)'") != 1:
        raise SystemExit("QF-CA3: tools/cron_status.php's OFF (switch) is not the one qf_after reads")
    # ── the pin's interfaces the probe calls, as built (each exactly once), and the built Q3-ON's three limits, which
    # QF-ON3 applies to every proactive claim the same way (D §H.2.15.3: "as Q3-ON judges a chase") ──
    for f, ss in [
        ("lib/SalesFollowupLadder.php", ["public static function check(\\PDO $ro, array $config, string $dataDir, string $pluginRoot, string $id, int $now): array",
                                         "public static function status(string $s): array", "public static function ledgerOn(array $config): bool",
                                         "public static function isOn(array $config, string $step): bool", "public static function allKeys(): array"]),
        ("lib/WaSendBudget.php", ["const PHONE_KINDS = ['quote', 'enquiry', 'offer'];", "const PERSON_KINDS = ['quote', 'enquiry', 'offer', 'reminder', 'winback'];",
                                  "'wa_send_daily_cap'  => 60,", "public static function handset(string $instance): string",
                                  "public static function on(array $config, ?string $dataDir): bool",
                                  "public static function recordNotify($store, array $config, string $channel, string $event, string $to, bool $success,"]),
        ("lib/SalesFollowupChecks.php", ["public static function handsetLabels(array $config): array", "public static function kindOfEvent(string $event): string",
            "$day = (int)self::one($ro, \"SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND local_day = ? AND proactive = 1 AND id < ? AND {$live}\",",
            "$hour = (int)self::one($ro, \"SELECT COUNT(*) FROM wa_auto_sends WHERE handset = ? AND proactive = 1 AND id < ? AND {$live}",
            "$gap = self::one($ro, \"SELECT MAX(CAST(strftime('%s', claimed_at) AS INTEGER) + gap_s) FROM wa_auto_sends WHERE handset = ? AND id < ? AND {$live}\","]),
        ("lib/QuoteSendEvidence.php", ["public static function forceAccounts(array $config): bool"]),
        ("lib/FollowUpService.php", ["public function due(string $nowUtc, int $limit = 25): array",
                                     "public function approvedDrafts(int $limit = 20, string $andSql = '', array $args = []): array",
                                     "public function hasKind(): bool"]),
        ("lib/QuoteChase.php", ["public static function on(array $config, ?string $dataDir): bool"]),
        ("lib/WaSeenMarks.php", ["public static function note(\\PDO $pdo, array $config, ?string $dataDir, string $direction, string $phone, string $channel,"]),
        ("migrations/070_followups.sql", ["    last_customer_at TEXT    NOT NULL,      -- UTC. Their last message when this opened\n"])]:
        src = show(f)
        for s in ss:
            if src.count(s) != 1:
                raise SystemExit(f"QF: {f} has {s[:90]!r} {src.count(s)} times, expected 1 — the probe calls the pin as built")

    master = show("cron/master.php")
    for j in ["quote_chase", "quote_daily_list", "ai_media", "offers_send"]:
        if master.count(f"'{j}'") < 1:
            raise SystemExit(f"QF-CA3: master.php does not register {j}")

    # ── the fragments, filled ──
    probe = put(snippet("qf_probe.php.txt"), "@@SC_EXPECT@@", sc_expect)
    probe = put(probe, "@@COLS085@@", ",".join(c085))
    probe = put(probe, "@@WSB_SEND_SITES@@", ctx["hk7"][0])
    probe = put(probe, "@@HK7_CLASSES@@", ctx["hk7"][1])
    probe = put(probe, "@@HK7_MIN@@", ctx["hk7"][2])
    if "\nPHP\n" in "\n" + probe + "\n" or "@@" in probe:
        raise SystemExit("QF: the probe holds a line PHP (which would end its heredoc) or a placeholder")
    ctx["qf_probe"] = probe
    funcs = put(snippet("qf_funcs.sh.txt"), "@@QF_PHP@@", probe.rstrip("\n"))
    funcs = put(funcs, "@@SC_EXPECT@@", sc_expect)
    funcs = put(funcs, "@@HOOKS@@", " ".join(hk))
    funcs = put(funcs, "@@EVENTS@@", " ".join(events))
    funcs = put(funcs, "@@HK7_MIN@@", ctx["hk7"][2])
    if "@@" in funcs:
        raise SystemExit("QF: a placeholder is left in qf_funcs")
    ctx["qf_funcs"] = funcs
    doc.insert_before(TN_ANCHOR, funcs + "\n", 1, why="QF functions and probe")
    for fn in ("qf_php", "qf_copy", "qf_root", "qf_tool", "qf_stage", "qf_clear_and_baseline", "qf_after", "qf_on_one", "qf_on"):
        doc.must(f"\n{fn}() {{", 1, why=f"{fn} defined once")
    doc.must(f'QF_SC_EXPECT="{sc_expect}"', 1)

    # ── the option, beside 5.18.103's ──
    doc.insert_after('ACCEPT_UNPRICED=""   # 5.18.102 (docs/71 §L.3, §Q D-R13): the explicit choice QL-G1 accepts, said as such — what decided it is QLG1_NOTED, set where it does (5.18.103: QL-RB2, and with it --rollback-unpriced-pending, is retired — docs/72 §G.10)\n',
                     'CLEAR_SWITCHES=""   # 5.18.104 (root docs/73 §H.2.15.1, QF-G4): keys found set on a redeploy are cleared by the pin\'s tool (off all --rollback, every part\'s hooks) once DEPLOY is typed, before the copy\n',
                     1, why="CLEAR_SWITCHES initialised")
    doc.insert_after("    --accept-unpriced) ACCEPT_UNPRICED=1 ;;                      # 5.18.102 (QL-G1): a row the KYC form offers that uCRM does not price, accepted\n",
                     "    --clear-switches) CLEAR_SWITCHES=1 ;;                        # 5.18.104 (QF-G4): 5.18.104's keys found set on a redeploy, cleared by the pin's tool before the copy\n",
                     1, why="--clear-switches parsed")
    doc.insert_after('{ [ -z "$QOFF" ] || [ "$MODE" = "deploy" ] || [ "$MODE" = "movelist" ]; } || { echo "--quote-company-off goes with a deploy, --check or --move-list-only, never with --after-only, --rollback or --check-bank" >&2; exit 64; }\n',
                     '# 5.18.104 (root docs/73 §H.2.15.1): --clear-switches is QF-G4\'s — the switch and record keys of every part found set\n'
                     '# before a redeploy — so it goes with a deploy, or with its --check (which clears nothing and says what the deploy would).\n'
                     '{ [ -z "$CLEAR_SWITCHES" ] || [ "$MODE" = "deploy" ]; } || { echo "--clear-switches goes with a deploy or --check, never with --after-only, --rollback, --check-bank or --move-list-only" >&2; exit 64; }\n',
                     1, why="--clear-switches validated")
    doc.sub('$( [ -n "$ACCEPT_UNPRICED" ] && echo ", --accept-unpriced")) — $TS — $(hostname)"',
            '$( [ -n "$ACCEPT_UNPRICED" ] && echo ", --accept-unpriced")$( [ -n "$CLEAR_SWITCHES" ] && echo ", --clear-switches")) — $TS — $(hostname)"',
            1, why="the banner names --clear-switches")
    doc.sub('${ACCEPT_UNPRICED:+--accept-unpriced} "$1"; do',
            '${ACCEPT_UNPRICED:+--accept-unpriced} ${CLEAR_SWITCHES:+--clear-switches} "$1"; do', 1, why="with_flags carries --clear-switches")
    # a deploy that finds the pin already serving runs an --after-only's checks: --clear-switches decides nothing there
    doc.insert_after('      if [ -n "$ACCEPT_UNPRICED" ]; then ACCEPT_UNPRICED=""; note "--accept-unpriced was typed with a deploy, for QL-G1, and this run makes no deploy: it decides nothing here, and no check after the copy reads it"; fi\n',
                     '      # 5.18.104: so is --clear-switches, QF-G4\'s — no deploy, so nothing is cleared; an operator\'s own off is the installed tool\'s\n'
                     '      if [ -n "$CLEAR_SWITCHES" ]; then CLEAR_SWITCHES=""; note "--clear-switches was typed with a deploy, for QF-G4, and this run makes no deploy: it clears nothing (a switch is turned off with the installed tool, through $QF_WRAPPER)"; fi\n',
                     1, why="--clear-switches on a run that makes no deploy")

    # ── stage A: after QC-G1 ──
    doc.insert_after('  hdr "A. What the forms would offer from uCRM in direct mode (5.18.103; GETs only)"\n  qc_stage\n',
                     '  # QF-G1–QF-G4, QF-N1–QF-N7 — 5.18.104 (root docs/73 §H.2.15.1), before anything changes: the copy\'s shape, the one\n'
                     '  # migration trial for every part (ruling R10), the pin\'s hooks and events as text, and the one stage-A rule for every\n'
                     '  # part\'s keys (ruling R7); then the notes. On copies, through the PIN\'s code from Git. A refusal stops here: nothing\n'
                     '  # has changed. Counts, codes and key names in the log; the report\'s owners on the sheet. --check runs it too.\n'
                     '  hdr "A. The sales follow-up (5.18.104, Part F\'s QF; read-only, on copies through the pin\'s code)"\n'
                     '  qf_stage\n', 1, why="stage A calls qf_stage")
    doc.must('  qf_stage\nfi\n', 1, why="qf_stage is the last of stage A")

    # ── stage B: the clear and the baseline, once DEPLOY is typed, before the checkout and the copy ──
    doc.insert_after('  TOUCHED=1   # from here on a stop says "Nothing further was done", never "Nothing was changed"\n  DEPLOY_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"\n',
                     '  # 5.18.104 (root docs/73 §H.2.15.1): QF-G4\'s --clear-switches, then QF-G4 read again (every key unset: the moment it\n'
                     '  # "returns"), and on a redeploy QF-G1\'s pause of busy_timeout plus 1 s and the redeploy baseline — the copy\'s start, in\n'
                     '  # both its forms — before the checkout, so that a stop here leaves the checkout where it was. Kept in the state file.\n'
                     '  qf_clear_and_baseline\n'
                     '  printf \'QF_G1=%s\\nQF_G4_SET=%s\\nQF_BASE=%s\\nQF_COPY_AT=%s\\n\' "${QF_G1:-}" "${QF_G4_SET:-}" "${QF_BASE:-}" "${QF_COPY_AT:-}" >> "$STATE"\n',
                     1, why="stage B: the clear and the baseline")

    # ── after the copy (stage R; --after-only too): after QC-CA1 ──
    doc.insert_after('hdr "R. What the forms offer from uCRM in direct mode, installed (5.18.103; GETs only)"\nqc_after "${ICODE_R:-}"\n',
                     '# QF-CA1–QF-CA8 — 5.18.104 (root docs/73 §H.2.15.2), after the copy: on fresh copies, through the INSTALLED code\n'
                     '# (ICODE_R) — 089–091 applied at the first boot, what the dark build could have written (the deploy run), the four jobs\n'
                     '# registered and listed OFF (switch), the F1 filters, the tool\'s show, the gates, the vault, the marks and the hooks\n'
                     '# with their controls. Then, in an --after-only run, QF-ON0–QF-ON5 (§H.2.15.3) through the installed tool\'s --check on\n'
                     '# a fresh copy: only the steps that are on are judged. Reports; never stops.\n'
                     'hdr "R. The sales follow-up, installed (5.18.104, Part F\'s QF; read-only, on copies through the installed code)"\n'
                     'qf_after "${ICODE_R:-}"\n'
                     'if [ "$MODE" = "after" ]; then\n'
                     '  hdr "R. The sales follow-up\'s steps, as they stand (5.18.104, --after-only: QF-ON0–QF-ON5; a step that is off is not judged)"\n'
                     '  qf_on "${ICODE_R:-}"\n'
                     'fi\n', 1, why="after the copy: qf_after, then qf_on in --after-only")
    doc.must('qf_after "${ICODE_R:-}"\n', 1)
    doc.must('  qf_on "${ICODE_R:-}"\n', 1)

    # ── the slots DS2–DS4 fill, each once ──
    for name in ("VN-G7", "VN-G stage A", "VA-G stage A", "OF-G stage A", "AC stage A", "baseline", "VN-CA", "VA-CA", "OF-CA",
                 "AC-CA", "VN-ON", "OF-ON", "AC-ON1"):
        lines = [l for l in doc.t.split("\n") if l.lstrip().startswith(f"# ── SLOT {name} ── ")]
        if len(lines) != 1:
            raise SystemExit(f"SLOT {name}: {len(lines)} marker lines, expected 1")

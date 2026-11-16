# Part CS — the three claim switches must be off (root docs/75 §L.3, §F; DL1, DL8). New: CS-G1 at stage A — a deploy and
# --check, so --check too — on LK-G1's reading, called in qf_stage right after lk_g1; CS-B1 after DEPLOY is typed and
# before the copy, inside qf_keep_and_baseline, on LK-B1's reading before it is compared with LK-G1's (a switch turned on
# meanwhile is then named with its remedy, not as a bare map difference). Both read LK's own map (QF_PHP lk's
# key|place|hash entries, through lk_map) — no new reader — and refuse (stop, NO-GO) when any of quote_chase_enabled
# (step 6), offers_test_enabled (step 11) and offers_live_enabled (step 12) is PRESENT, with any value, in
# <data>/kyc_config.json (place k) or its store row (s). A key in either config.json (p, d) is LK-G1's held rule, which
# refuses first (any allKeys() key there), its own remedy standing. The refusal names keys and places, never a value, and
# prints one command per present switch, highest step first (off offers-live, off offers-test, off chase), each alone on
# its own echo line — literal text (K1: $QF_WRAPPER only on echo lines; the wrapper writes its own log in
# /root/dnb-sales), with a trailing comment that keeps each line unique (rb_pre105 prints the same offers-live command) —
# after a line saying what it also does; then the --check to run again (this run's flags, as with_flags gives them, its
# own log), then STOP. LK-G1's two notes on offers_live_enabled and quote_chase_enabled (S105:7038-7039) and their
# description in lk_g1's comment (S105:7025-7026) go: they said the opposite of §F. Every other LK check is carried
# unchanged. Header text is partH's. New names: cs_found, cs_has, cs_check_cmd, cs_check (codes CS-G1, CS-B1).
# Asserted from Git, at 7835906 and the pin (each file kept byte for byte): the three keys are the ladder's switch keys of
# steps 6, 11 and 12 — so in allKeys(), in LK's map and under LK-G1's held rule; offers-test's off also clears
# offers-live, chase's also clears cold (step 10); OfferSwitch::offHook, both offer steps' off-hook, pauses every running
# offer; WaSendBudget::enquiryClaims reads exactly these three keys, at line 173. Asserted on the source: LK's map format
# and place letters, lk_map, LK-G1's held rule and LK_G1, with_flags, LOGW and QF_WRAPPER.
import re, textwrap
from d106lib import git, sha_at

CLAIM = [("offers_live_enabled", "offers-live", "12"), ("offers_test_enabled", "offers-test", "11"),
         ("quote_chase_enabled", "chase", "6")]                  # highest step first: the order of the printed commands
LADDER, BUDGET, OFFERS, TOOL = ("lib/SalesFollowupLadder.php", "lib/WaSendBudget.php", "lib/OfferSwitch.php",
                                "tools/sales_followup.php")
BUDGET_LINE = (173, "        foreach (['quote_chase_enabled', 'offers_test_enabled', 'offers_live_enabled'] as $k) {")
# the step rows' facts the message relies on (what each step's off also does), each a substring of its row at the pin
ROWS = {"offers-live": ["'switch' => ['offers_live_enabled' => '1']", "'clears' => []", "'hook' => ['O', 'before', 'always']"],
        "offers-test": ["'switch' => ['offers_test_enabled' => '1']", "'clears' => ['offers-live']",
                        "'hook' => ['O', 'before', 'always']"],
        "chase": ["'switch' => ['quote_chase_enabled' => '1']", "'clears' => ['cold']"],
        "cold": ["'switch' => ['quote_chase_cold' => '1']", "'clears' => []"]}
STEP_N = {"offers-live": "12", "offers-test": "11", "chase": "6", "cold": "10"}

CS_RULE = "# ── 5.18.106: the three claim switches must be off (CS-G1, CS-B1; root docs/75 §L.3, §F) ─────────────────────────────────"
CS_PARA = ("Until 5.18.106 serves, none of offers_live_enabled (step 12), offers_test_enabled (step 11) and quote_chase_enabled "
           "(step 6) may be on: each puts the enquiry sends into the ledger's claim mode (WaSendBudget::enquiryClaims, line 173 of "
           "lib/WaSendBudget.php), which under 5.18.105 closes the drafts Evolution refuses after three tries each and then sends "
           "the stale backlog. CS-G1 (stage A — a deploy and --check — on LK-G1's reading, right after it) and CS-B1 (after DEPLOY "
           "is typed, before the copy, on LK-B1's reading before it is compared with LK-G1's) read LK's own map, no new reader, and "
           "refuse when any of the three is PRESENT, with any value, in <data>/kyc_config.json (k) or its store row (s). Presence, "
           "not truth: the readers disagree on truth (root docs/75 §L.3), and absent — what `off <step>` leaves, in the file and in "
           "the store row — is the one state every reader agrees on; no production writer leaves a claim key present but off. A key "
           "held by <plugin>/data/config.json or <data>/config.json (p, d) is LK-G1's held rule, which refuses first, its own remedy "
           "standing. The refusal names the keys and the places, never a value, and prints one command per present switch, highest "
           "step first, each alone on its own line after what it also does — printed by echo, never run: this script runs no "
           "switch-tool verb — then the --check to run again, its own command with its own log.")
CS_FUNCS = r'''# cs_found MAP — the claim switches present in k or s of an LK map, highest step first, each as key@places
# ("offers_live_enabled@k,s quote_chase_enabled@s"), or nothing: key names and place letters only, never a hash
cs_found() {
  local k p o=""
  for k in offers_live_enabled offers_test_enabled quote_chase_enabled; do
    p="$(printf '%s' "$1" | tr ',' '\n' | awk -F'|' -v k="$k" 'NF == 3 && $1 == k && ($2 == "k" || $2 == "s") { print $2 }' | sort -u | paste -sd',' -)"
    [ -z "$p" ] || o="$o $k@$p"
  done
  printf '%s' "${o# }"
}
cs_has() { case " $1" in *" $2@"*) return 0 ;; esac; return 1; }   # cs_has FOUND KEY — KEY is among cs_found's FOUND
cs_check_cmd() { local c; c="$(with_flags --check)"; printf '%s %s' "${c%%" 2>&1 | "*}" "$(LOGW check)"; }   # the --check to run again: this run's flags (with_flags), its own log
# cs_check CODE READING — CS-G1 (stage A, on LK-G1's reading) or CS-B1 (after DEPLOY, on LK-B1's): ok when none of the
# three is present in k or s; otherwise the refusal — keys and places, never a value — then, for each switch present,
# highest step first, what its off also does and its own command, alone on its own line (its log in /root/dnb-sales,
# written by the wrapper); then --check again, its own command, alone; then STOP (stage A: nothing was changed; after
# DEPLOY: nothing was copied).
cs_check() {
  local c="$1" f
  f="$(cs_found "$(lk_map "$2")")"
  if [ -z "$f" ]; then
    ok "$c none of the three claim switches — offers_live_enabled (step 12), offers_test_enabled (step 11), quote_chase_enabled (step 6) — is present in <data>/kyc_config.json (k) or its store row (s)$( [ "$c" = "CS-B1" ] && echo ", read again after DEPLOY, before the copy"), by LK's map (in either config.json LK-G1's held rule refuses one$( [ "$c" = "CS-B1" ] && echo ", and LK-B1 requires every place as LK-G1 read it")) — the enquiry sends stay out of the ledger's claim mode until $EXPECTED_VERSION serves (root docs/75 §F)"
    return 0
  fi
  echo
  echo "  $c A CLAIM SWITCH IS PRESENT: $f (key@place — k <data>/kyc_config.json, s its store row; no value is read out). Until $EXPECTED_VERSION serves, none of offers_live_enabled (step 12), offers_test_enabled (step 11) and quote_chase_enabled (step 6) may be present, with any value: each puts the enquiry sends into the ledger's claim mode, which under $BASELINE_VERSION closes the drafts Evolution refuses after three tries each and then sends the stale backlog (root docs/75 §F). Absent is the one state every reader agrees on, and off <step> leaves it, in the file and in the store row."
  echo "  Turn each one off yourself, highest step first, each its own command, alone, its log in /root/dnb-sales (the wrapper writes it) — this script never runs them:"
  if cs_has "$f" offers_live_enabled; then
    echo
    echo "  step 12 (offers-live) — off offers-live also pauses a running offer:"
    echo "      cd $REPO && bash $QF_WRAPPER off offers-live"   # step 12's own command: printed, never run (CS)
  fi
  if cs_has "$f" offers_test_enabled; then
    echo
    echo "  step 11 (offers-test) — off offers-test also turns offers-live off and pauses a running offer:"
    echo "      cd $REPO && bash $QF_WRAPPER off offers-test"   # step 11's own command: printed, never run (CS)
  fi
  if cs_has "$f" quote_chase_enabled; then
    echo
    echo "  step 6 (chase) — off chase also turns step 10 (cold) off:"
    echo "      cd $REPO && bash $QF_WRAPPER off chase"   # step 6's own command: printed, never run (CS)
  fi
  echo
  echo "  Then run --check again, its own command, alone:"
  echo "      $(cs_check_cmd)"
  [ "$c" = "CS-B1" ] && stop "NO-GO (CS-B1): a claim switch was turned on during this run, after stage A ($f) — nothing was copied. Turn each off with its own command above, alone, highest step first; then run --check again (above) and, once it passes, the deploy. Send the log file"
  stop "NO-GO (CS-G1): a claim switch is present ($f) — until $EXPECTED_VERSION serves none may be, with any value (root docs/75 §F). Turn each off with its own command above, alone, highest step first; then run --check again (above). Nothing was changed. Send the log file"
}
'''

LKG1_COMMENT_OLD_FIRST = "# held there is a note (gap 11), compared in LK-CA1. Otherwise never NO-GO for a key that is on: a note gives the steps"
LKG1_COMMENT_OLD_LAST = "# when offers_live_enabled is set; quote_chase_enabled set is a plain note (the owner's rulings of 9 Nov)."
LKG1_COMMENT_NEW = """# held there is a note (gap 11), compared in LK-CA1. Otherwise LK-G1 itself is never NO-GO for a key that is on: a note
# gives the steps on, those skipped, the ledger, the count per place and every key where file and store disagree. Since
# 5.18.106 a claim switch present in k or s is CS-G1's refusal, on this reading, right after it (cs_check; root docs/75
# §L.3) — 5.18.105's two notes on offers_live_enabled and quote_chase_enabled are gone: they said the opposite of §F."""
LKG1_NOTES = ('  case ",$(lk_map "$o")," in *",offers_live_enabled|"*) note "LK-G1 OFFERS_LIVE_ENABLED IS SET — STEP 12 (OFFERS-LIVE) IS ALREADY ON UNDER $BASELINE_VERSION, AGAINST THE OWNER\'S RULING OF 9 NOV THAT IT WAITS FOR $EXPECTED_VERSION. THIS DEPLOY IS THE REMEDY, AND IT CHANGES THE KEY NEITHER WAY" ;; esac\n'
              '  case ",$(lk_map "$o")," in *",quote_chase_enabled|"*) note "LK-G1 quote_chase_enabled is set: step 6 (chase) is on — the owner\'s later ruling of 9 Nov: the chase does not wait for $EXPECTED_VERSION" ;; esac\n')
G1_CALL = "  lk_g1                # LK-G1 — the ladder's keys as found (root docs/74 §H.4.3): a refusal stops here, nothing changed\n"
G1_CS = "  cs_check CS-G1 \"$LK_G1\"   # CS-G1 — the three claim switches absent from k and s, on LK-G1's reading (root docs/75 §L.3): a refusal stops here, nothing changed\n"
B1_READ = "  lk_ok \"$o\" || stop \"NO-GO (LK-B1): the ladder's keys could not be read again before the copy (${o%% map=*}) — nothing was copied. Send the log file\"\n"
B1_CS = "  cs_check CS-B1 \"$o\"   # CS-B1 — the same on this reading, before it is compared with LK-G1's (root docs/75 §L.3): a refusal stops here, nothing copied\n"
QKB_OLD = ("# replaces 5.18.104's qf_clear_and_baseline and runs no tool): LK-B1 — the same reading as LK-G1, required equal key by\n"
           "# key and place by place, else NO-GO and nothing copied; then the pause (busy_timeout, 5 s, lib/SqliteStore.php:158,\n")
QKB_NEW = ("# replaces 5.18.104's qf_clear_and_baseline and runs no tool): LK-B1 — the same reading as LK-G1, judged first by CS-B1\n"
           "# (5.18.106, root docs/75 §L.3: none of the three claim switches present in k or s), then required equal to LK-G1's key\n"
           "# by key and place by place, else NO-GO and nothing copied; then the pause (busy_timeout, 5 s, lib/SqliteStore.php:158,\n")
STB_OLD = "  # never a value), the steps on and skipped.\n  qf_keep_and_baseline\n"
STB_NEW = ("  # never a value), the steps on and skipped. 5.18.106 (root docs/75 §L.3): CS-B1 first, on LK-B1's reading — none of\n"
           "  # the three claim switches present in <data>/kyc_config.json or its store row, or nothing is copied; its remedies are\n"
           "  # printed, never run.\n  qf_keep_and_baseline\n")


def cmd_line(step, n):
    return f'    echo "      cd $REPO && bash $QF_WRAPPER off {step}"   # step {n}\'s own command: printed, never run (CS)'


def cs_block():
    para = textwrap.wrap(CS_PARA, width=120, initial_indent="# ", subsequent_indent="# ", break_long_words=False,
                         break_on_hyphens=False)
    return CS_RULE + "\n" + "\n".join(para) + "\n" + CS_FUNCS


def row(src, step):
    lines = src.split("\n")
    head = f"        '{step}' => ['n' => '{STEP_N[step]}', "
    hits = [i for i, l in enumerate(lines) if l.startswith(head)]
    if len(hits) != 1:
        raise SystemExit(f"PART CS: the ladder's row of {step} (n {STEP_N[step]}) is found {len(hits)} times")
    j = hits[0]
    while j < len(lines) and "'noskip' =>" not in lines[j]:
        j += 1
    if j - hits[0] > 8:
        raise SystemExit(f"PART CS: the ladder's row of {step} does not end within eight lines")
    return "\n".join(lines[hits[0]:j + 1])


def apply(doc, ctx):
    repo, plugin, base, pin, kept = ctx["repo"], ctx["plugin"], ctx["base_full"], ctx["pin_full"], ctx["kept"]
    d106 = set(ctx["added"]) | set(ctx["changed"])
    show = lambda c, p: git(repo, "show", f"{c}:{plugin}/{p}").decode()
    block = cs_block()

    # ── Git: the files the message speaks for, kept byte for byte, and what they say ──
    for f in (LADDER, BUDGET, OFFERS, TOOL):
        if f in d106 or f not in kept or sha_at(repo, base, f"{plugin}/{f}") != sha_at(repo, pin, f"{plugin}/{f}"):
            raise SystemExit(f"PART CS: {f} is in 5.18.106's delta, not kept by sha256, or differs between 7835906 and the pin")
    lad = show(pin, LADDER)
    for step, subs in ROWS.items():
        r = row(lad, step)
        for s in subs:
            if r.count(s) != 1:
                raise SystemExit(f"PART CS: the ladder's row of {step} does not hold {s!r} once")
    for s in ("const SEQUENCE = ['offers-live', 'offers-test', 'cold', 'chase', ", "public static $drop = [];",
              "foreach (self::rowKeys($name) as $k) $out[] = $k;", "$out = array_keys($row['switch']);"):
        if lad.count(s) != 1:
            raise SystemExit(f"PART CS: the ladder does not hold {s!r} once (the claim keys' place in allKeys())")
    bl = show(pin, BUDGET).split("\n")
    n, want = BUDGET_LINE
    if bl[n - 1] != want or sum(1 for l in bl if "'offers_live_enabled'" in l) != 1 \
            or "public static function enquiryClaims(array $config): bool" not in bl[n - 3]:
        raise SystemExit("PART CS: WaSendBudget::enquiryClaims does not read exactly the three claim keys at line 173")
    if sorted(re.findall(r"'([a-z_]+)'", want)) != sorted(k for k, _, _ in CLAIM):
        raise SystemExit("PART CS: the claim keys are not enquiryClaims' three")
    off = show(pin, OFFERS)
    for s in ("if ($step !== 'offers-test' && $step !== 'offers-live') throw new \\InvalidArgumentException('offHook: unknown step');",
              "// 1. every running offer paused, live_slot kept",
              "UPDATE wa_offers SET state = 'paused', updated_at = ? WHERE id = ? AND state = 'running'"):
        if off.count(s) != 1:
            raise SystemExit(f"PART CS: OfferSwitch::offHook does not hold {s!r} once (an offer step's off pauses a running offer)")
    if "case 'off':    $rc = sf_off(" not in show(pin, TOOL):
        raise SystemExit("PART CS: the switch tool has no off verb")

    # ── the source: LK's map and LK-G1's held rule, which CS reads and stands behind ──
    for s, why in (("$map[] = $k . '|' . $c . '|' . $h[$c];", "the map's entries"),
                   ("' map=', $map === [] ? '-' : implode(',', $map);", "the map's joining"),
                   ("foreach (['k' => $pdd . '/kyc_config.json', 'p' => $live . '/data/config.json', 'd' => $pdd . '/config.json'] as $c => $f) {", "the place letters"),
                   ("try { $doc['s'] = $storeRow($ro($pdd . '/plugin.sqlite3')); }", "the store row's place letter"),
                   ("foreach (array_values(array_unique(array_merge($L['all'], $L['settings']))) as $k) {", "the map's keys"),
                   ("if (isset($h['p']) || isset($h['d'])) { if ($isAll) $held[] = $k; else $heldS++; }", "LK-G1's held rule"),
                   ("lk_map() { printf '%s' \"${1##* map=}\"; }", "lk_map"),
                   ('  [ "$(qf_kv "$o" held)" = "0" ] || stop "NO-GO (LK-G1): ', "LK-G1's held refusal"),
                   ('  LK_G1="$o"; LK_ON="$(qf_kv "$o" on)";', "LK_G1"),
                   ("with_flags() {   # the move-list run", "with_flags"),
                   ("LOGW() { printf '2>&1 | ( umask 077; exec tee %s/%s-$(date -u +%%Y%%m%%dT%%H%%M%%SZ).log )' \"$OUT\" \"$1\"; }", "LOGW"),
                   ('QF_WRAPPER="scripts/sales-followup.sh"', "QF_WRAPPER"),
                   ("  printf 'cd %s && bash scripts/deploy-%s.sh%s %s' \"$REPO\" \"$EXPECTED_VERSION\" \"$f\" \"$(LOGW \"$w\")\"\n", "with_flags' form")):
        doc.must(s, 1, why=f"CS reads {why}")
    for s in ("cs_found", "cs_has", "cs_check", "CS-G1", "CS-B1"):
        doc.mustnot(s, why="CS's names are new")

    # ── LK-G1: its two notes go, and their description in its comment ──
    doc.lines_between(LKG1_COMMENT_OLD_FIRST, LKG1_COMMENT_OLD_LAST, LKG1_COMMENT_NEW, why="lk_g1 comment")
    doc.sub(LKG1_NOTES, "", why="LK-G1's two notes")
    doc.must("  qf_sheet \"LK-G1 the ladder's keys, key|place|hash", 1)
    doc.must("  [ \"$LK_KEY_FROM\" = \"own\" ] && [ -n \"$CHECK_ONLY\" ] && note \"LK-G1 --check:", 1)
    for s in ("OFFERS_LIVE_ENABLED IS SET", "the owner's later ruling of 9 Nov", "A NOTE IN CAPITALS\n# when offers_live_enabled",
              "quote_chase_enabled set is a plain note"):
        doc.mustnot(s, why="LK-G1's notes gone")

    # ── the functions, beside LK's, after lk_g1; the two call sites ──
    for k, step, n in CLAIM:
        if block.count(cmd_line(step, n) + "\n") != 1:
            raise SystemExit(f"PART CS: CS's text does not print {step}'s command once")
    doc.insert_before("# lk_stage_a SUB — stage A's readings for QF-CA5 and LK-ON, through the PIN's tool on SUB", block,
                      why="CS functions")
    doc.insert_after(G1_CALL, G1_CS, why="CS-G1 call")
    doc.insert_after(B1_READ, B1_CS, why="CS-B1 call")
    doc.sub(QKB_OLD, QKB_NEW, why="qf_keep_and_baseline comment")
    doc.sub(STB_OLD, STB_NEW, why="stage B comment")

    # ── the result ──
    for s, n in (("cs_found() {\n", 1), ("cs_has() {", 1), ("cs_check_cmd() {", 1), ("cs_check() {\n", 1),
                 (G1_CALL + G1_CS, 1), (B1_READ + B1_CS, 1), ("  qf_keep_and_baseline\n", 1),
                 ('  cs_check CS-G1 "$LK_G1"', 1), ('  cs_check CS-B1 "$o"', 1)):
        doc.must(s, n, why="CS as written")
    i = doc.t.index(B1_CS)
    if not (doc.t.index("qf_keep_and_baseline() {\n") < i < doc.t.index('  if [ "$(lk_map "$o")" != "$(lk_map "$LK_G1")" ]; then\n', i)):
        raise SystemExit("PART CS: CS-B1 is not inside qf_keep_and_baseline, before LK-B1's comparison")
    i = doc.t.index(G1_CS)
    if not (doc.t.index("qf_stage() {\n") < i < doc.t.index("  lk_stage_a \"$sub\"", i)):
        raise SystemExit("PART CS: CS-G1 is not in qf_stage, right after LK-G1")
    # the printed commands: one per switch, literal, each alone on its echo line, guarded by its key, highest step first
    blk = doc.t[doc.t.index("cs_check() {\n"):]
    blk = blk[:blk.index("\n}\n")]
    at = []
    for k, step, n in CLAIM:
        ln = cmd_line(step, n)
        if doc.t.count(ln + "\n") != 1 or blk.count(ln + "\n") != 1:
            raise SystemExit(f"PART CS: the command for {step} is not printed once, on its own echo line, in cs_check")
        g = blk.index(f"  if cs_has \"$f\" {k}; then\n")
        if blk.count(f"  if cs_has \"$f\" {k}; then\n") != 1 or not (g < blk.index(ln) < blk.index("  fi\n", g)) \
                or f"  step {n} ({step}) — off {step} also " not in blk[g:blk.index(ln)]:
            raise SystemExit(f"PART CS: {step}'s command is not guarded by its key, or its line saying what it also does is missing")
        at.append(blk.index(ln))
    if at != sorted(at) or not (at[-1] < blk.index('  echo "      $(cs_check_cmd)"\n') < blk.index('  stop "NO-GO (CS-G1)')):
        raise SystemExit("PART CS: the commands are not printed highest step first, then --check, then STOP")
    # K1's rule, over the whole text: $QF_WRAPPER only on echo lines; and no writer or switch-tool run in CS's code
    for l in doc.t.split("\n"):
        if "$QF_WRAPPER" in l and not l.lstrip().startswith(("echo ", "#")):
            raise SystemExit(f"PART CS: $QF_WRAPPER outside an echo line: {l.strip()[:100]}")
    for l in block.split("\n"):
        if l.lstrip().startswith(("#", "echo ", "ok ", "stop ", "[ \"$c\" = \"CS-B1\" ] && stop ")):
            continue
        if re.search(r"saveOverrides|writeKycFile|->save\(|set_config\.php|sales-followup\.sh|qf_tool|\$QF_WRAPPER", l):
            raise SystemExit(f"PART CS: a code line of CS names a writer or the switch tool: {l.strip()[:100]}")
    if any(len(l) > 120 for l in (block + "\n" + LKG1_COMMENT_NEW + "\n" + QKB_NEW + STB_NEW).split("\n")
           if l.lstrip().startswith("#") and not l.startswith("# ── ")) or len(CS_RULE) > 123:
        raise SystemExit("PART CS: a comment line of CS is too long")

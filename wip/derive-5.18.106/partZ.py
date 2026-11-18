# Part Z — rules over the whole result, last of the parts (root docs/75 §L; 5.18.105's partZ carried, re-pointed). One
# edit: partUJ's slot U5 closed (partR filled it; ctx["as_slot_before"] above it). Then Z1–Z28 over the whole text, every
# failure reported together, and weakened copies of the result, each refused by its own rule alone (every rule has one).
# Z1 the switch tool: show/--check/doctor only; the wrapper and the tool's verbs only echoed. Z2 set_config --key in
# code: Q-CA6's throwaway run and OWNER_AUTOSEND_CMD's text alone. Z3 the rollback to 7835906: rb_pre106 just before
# ROLLBACK, the prompt saying what AS-RB1 writes, rb106 from V1 and RB alone, review D1 (RB_NOTHING). Z4 the one write
# (§L.7): as_rb1's saveOverrides, followup_auto_send '0' alone, -u "$DB_OWNER", the installed tree; as_rb1 typed and in
# V1, each before do_rollback; OWNER_AUTOSEND_CMD assigned once, in as_owner_cmd, and only printed. Z5 retired names
# (rb_pre105, rb105, RB105: DL9). Z6 --clear-switches refused on its own echo line. Z7 retired codes silent; new codes
# printed. Z8 replaces 5.18.105's D3 rule (§L.6): no "numbers again", "WRITES PHONE" or step-12 notice. Z9 the pipe rule
# (carried). Z10 no slot left. Z11 NOT YET (§L.5, DL3): UJ-ON's alone, three states, exit 3. Z12 ME-CA1's NOT
# ESTABLISHED four notes (DL10). Z13 stage R's order. Z14 no 5.18.105 identity; 994d660 only as LM-G1's control. Z15
# printed commands never adjacent; the rollback's after the verdict (root docs/44 §16.9). Z16 the summary (§L.8): §F's
# steps, ECO Bank's guard, check it. Z17 partT's and partH's texts. Retired: 5.18.105's edits (in the source now; Z9
# asserts its here-strings) and its D3 rule (Z8).
# 18 Nov (rev106-findings, DL11): Z7 also names FE-G1, and Z17 pins stage A's heading with it. Z18 FE-G1 (DL11): once,
# in qf_stage after CS-G1 and the UJ reading and before U5; a refusal only on a whole reading of followup_enabled off;
# its NO-GO, last; its command printed once, by echo, alone on its line, never run. Z19 the uj mode names its copy as
# the data folder, and says the zone it read (review UJ-1). Z20 UJ-ON's gate split: false now, nothing to do; false only
# at stage A, not judged for this deploy (UJ-2). Z21 NOT YET's counts in UJ_NY_SAY, said once (UJ-3). Z22 rb_pre106's
# arms: noowner in capitals, a reading by its cause (never an empty one), a reader that did not run in none (RB-1). Z23
# the RB note says the one key written only when AS-RB1 wrote it (RB-2). Z24 AS-RB2's could-not-read branch a note after
# AS-RB1's recorded cause (RB-4), and AS_RB2_EFF. Z25 as_back_on by the latest rollback's RB_COPY_AT against this
# deploy's COPY_AT, and the texts that say "a deploy after a rollback" (L8-4). Z26 the summary's claim-switch clauses by
# the mode, and §F's step 2 by LK-AO's reading (LK_AO_ON; L8-2). Z27 check it by stage A's reading (L8-1) and "what it
# means" by AS-RB2's (L8-3). Z28 after a typed rollback and in V1's stop, the headline by what AS-RB1 recorded, and a
# plain line when it reads off now (RB-3, L8-3).
# 18 Nov, review 2 (rev106-2-findings): Z20 the limit alone differing is its own note, judged once set back, and only
# the stage-A note says F3 cannot be judged for this deploy (UJ2-G3); Z23 a written-check never says nothing else
# moved (RB2-wc); Z25 as_rb_last reads this deploy's own state file, and as_back_on offers nothing after this
# deploy's own V1 (L84-V1); Z27 stage A says, in capitals before DEPLOY, where UJ-ON will not judge F3 (L8-1).
# 18 Nov, the re-check of review 2: Z20 pins each gate note's return and the limit note's close pass (Z20-RET) and
# UJ_ON_GATE; Z25 as_back_on returns only on a V1 of this deploy that wrote or may have, and as_rb_last reads only
# the kept and rollback records (R2-V1OFF); Z27 U4's DEPLOY clause by the mode, and check it by UJ_ON_GATE (UJ2-G3-r).
# 18 Nov, the re-check of round 3: Z20 the 'now' note says to wait for a close pass once the gate holds again
# (R3-NOW-CP); Z25 as_back_on returns too on a V1 that read it on (R3-V1NW); Z27 pins check it's three arms and its
# else text whole (R3-PIN-ARMS, CHK-WAIT).
import re
from d106lib import close

SLOT = "as_u5   # U5"
V1 = ('echo "  ROLLING BACK — the public address must never redirect (every switch of the sales follow-up kept;'
      ' followup_auto_send set off first, AS-RB1)"; as_rb1 v1; if do_rollback')
WRITE = "saveOverrides($dd, ['followup_auto_send' => '0'])"
AS_EXEC = 'docker exec -u "$DB_OWNER" -e AS_ROOT="$IN_CONTAINER"'
DISPLAY = re.compile(r'^\s*(?:echo|ok|bad|note|stop|hdr|notyet)\b')
SAYS = re.compile(r'\b(?:ok|bad|note|stop|echo|warn|notyet)\s+"')
CMD = re.compile(r'\becho "      \S')       # a printed command: six spaces, then the command
TXT = re.compile(r'\becho "\s*[^\s"]')       # a printed line with text
HCMD = re.compile(r"#   (?:cd |\( umask )")  # a command in the header
QUIET = re.compile(r"(?<!\|)\|\s*grep\s+(?:-[A-Za-z]+\s+)*-[A-Za-z]*q")
PIPES = [("  if printf '%s\\n' \"$o\" | grep -q '^== sales follow-up report (READ-ONLY)'; then",
          "  if grep -q '^== sales follow-up report (READ-ONLY)' <<<\"$o\"; then"),
         ("  for v in $QF_SC_EXPECT; do printf '%s\\n' \"$o\" | grep -qx \"PASS ${v%%:*}\" || bad=\"$bad doctor:${v%%:*}\"; done",
          "  for v in $QF_SC_EXPECT; do grep -qx \"PASS ${v%%:*}\" <<<\"$o\" || bad=\"$bad doctor:${v%%:*}\"; done"),
         ("  printf '%s\\n' \"$o\" | grep -qE '^089 names: 38 of 38 present$' || bad=\"$bad doctor:089-names\"",
          "  grep -qE '^089 names: 38 of 38 present$' <<<\"$o\" || bad=\"$bad doctor:089-names\""),
         ("  if printf '%s\\n' \"$v\" | grep -qx \"090_wa_voice.sql=$VN_090_SC/$VN_090_SC/$VN_090_SC\" \\",
          "  if grep -qx \"090_wa_voice.sql=$VN_090_SC/$VN_090_SC/$VN_090_SC\" <<<\"$v\" \\")]
PASSED = 'if [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ]; then echo; echo "  $EXPECTED_VERSION ($MODE): PASSED.'
NY_ELIF = '\nelif [ "$FAIL" = "0" ]; then\n'
FAILED = '\nelse echo; echo "  $EXPECTED_VERSION ($MODE): $FAIL FAILED'
OWN_IF = 'if [ "$MODE" = "after" ] && [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ]; then\n'
ECO_IF = 'if [ "$MODE" != "rollback" ] && [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ] && [ -n "${V3J_WBB:-}" ]; then\n'
AFTER = '  echo "      $AFTER_CMD"'
ROLLBACK = '  echo "      $ROLLBACK_CMD"'
PUTBACK = '  echo "      $PUTBACK_CMD"\n  as_show "$(as_para sum)"'
STAGE_R = ("\nlm_after   # LM-CA1", "\nme_ca1\n", "\nuj_after   # UJ-CA (notes) and, in --after-only, UJ-ON (judged)\n",
           '\n  qf_on "${ICODE_R:-}"\n')
STEPS = ("--key followup_daily_cap --value '<CAP>'", 'bash $QF_WRAPPER on chase"', 'bash $QF_WRAPPER --check chase"',
         "--key followup_run_limit --value '<LIMIT>'", "\n  as_back_on   #")
CODE_RETIRED = ("qf_rb1", "qf_clear_and_baseline", "qf_byhand", "CLEAR_SWITCHES", "QF_RB1_START", "db_mig104", "QF_G4",
                "QF_CLEAR", "ac_rb_pre", "ac_rb1", "of_rb1", "vn_rb1", "AC_RB_ADMINS", "F_QG1", "off all")
RETIRED_CODES = ("QF-G4", "QF-RB1", "QF-RB2", "QF-RB3", "VN-RB1", "VN-RB2", "VN-RB3", "VN-RB4", "VN-RB6", "OF-RB1", "AC-RB1")
NEW_CODES = ("LK-G1 LK-B1 LK-CA1 LK-CA2 LK-ON LK-AO LK-RB0 LK-RB1 LM-G1 LM-G2 LM-CA1 LM-CA2 LM-ON LM-RB1 RB106 CS-G1 CS-B1 "
             "U1 U2 U3 U4 U5 FE-G1 UJ-CA UJ-ON ME-CA1 AS-RB0 AS-RB1 AS-RB2").split()
ONCE = {   # found exactly once
    "Z3": ('BASELINE_COMMIT="7835906"', "rb_pre106() {\n", "rb106() {\n"),
    "Z4": (WRITE, AS_EXEC, '\n    as_rb1 typed\n    if do_rollback; then ok "container serves $BASELINE_COMMIT ($BASELINE_VERSION)"'),
    "Z9": tuple(p[1] for p in PIPES),
    "Z11": ("notyet() { NOT_YET=$((NOT_YET+1)); printf '  wait  %s\\n' \"$*\"; }", "notyet() {", "return 3",
            "PASS=0; FAIL=0; NOTE=0; NOT_YET=0", '$( [ "$NOT_YET" = "0" ] || echo ", $NOT_YET not yet")', PASSED, NY_ELIF,
            'echo; echo "  $EXPECTED_VERSION (after-only): NOT YET — ', FAILED,
            '[ "$FAIL" = "0" ] || return 1      # FAILED: exit status 1\n[ "$NOT_YET" = "0" ] || return 3   # 5.18.106 '
            '(root docs/75 §L.5): NOT YET, exit status 3; PASSED 0\n}\n\nmain "$@"; exit $?'),
    "Z14": ('RELEASE_BRANCH="release/5.18.106"', 'OUT="${DNB_OUT:-/root/dnb-5.18.106}"',
            "bash scripts/deploy-5.18.106.sh 2>&1 | ( umask 077; exec tee /root/dnb-5.18.106/deploy-",
            "bash scripts/deploy-5.18.106.sh --after-only 2>&1 | ( umask 077; exec tee /root/dnb-5.18.106/after-"),
    "Z15": (ROLLBACK, 'ROLLBACK_CMD="cd $REPO && bash scripts/deploy-$EXPECTED_VERSION.sh --rollback $(LOGW rollback)"'),
    "Z16": (OWN_IF, ECO_IF, STEPS[0], STEPS[3]),
    "Z17": ("# ── 5.18.105: the ladder rule (LK; root docs/74 §H.4.3) — every ladder switch kept exactly as found ",
            "  hdr \"A. The sales follow-up (5.18.104, Part F's QF, kept live by 5.18.105's LK; 5.18.106's CS-G1, U1–U5 and FE-G1; "
            "read-only, on copies through the pin's code)\"\n", "hdr \"R. The masked lines, installed (5.18.105's LM, kept by "
            "$EXPECTED_VERSION;"),
}
NONE = {   # found nowhere
    "Z5": ("rb_pre105", "rb105", "RB105", "old lines back"),
    "Z9": tuple(p[0] for p in PIPES),
    "Z10": ("# ── SLOT ", "SLOT U5"),
    "Z14": ("deploy-5.18.105.sh", "/root/dnb-5.18.105/lk-key", "release/5.18.105, is cut", "5.18.104 must be the live plugin",
            "deploy 5.18.105 again", "while 5.18.104 is live"),
    "Z15": ("deploy-5.18.106.sh --rollback",),
    "Z17": ("5.18.103's code and 5.18.104's", "5.18.103 classes (G3)", "rollback target (5.18.104 here)",
            "the installed plugin is live 5.18.104", "the live plugin is 5.18.104", "every file 994d660 tracks",
            "continue under 994d660", "994d660's own, which carry on", "5.18.105 adds no migration",
            "5.18.104 does not change cash in hand", "5.18.104 changes no figure", "rollback target, 5.18.104",
            "which this rollback leaves", "5.18.105's files are 5.18.104's again", "5.18.105 never runs the switch tool",
            "$EXPECTED_VERSION's LM", "5.18.105 keeps every switch as found", "writes nothing to the book",
            "reached by nothing", "read by nothing", "5.18.105'S OWN CHECKS", "AGAINST 5.18.103'S CODE",
            "AGAINST 5.18.104'S CODE", "the documented deploy of 5.18.104", "5.18.105's own first", "5.18.106's CS-G1 and U1–U5;"),
}
COUNTS = [("Z12", "ME-CA1 NOT ESTABLISHED — ", 4), ("Z16", '[ "$NOT_YET" = "0" ]', 5), ("Z16", AFTER, 4),
          ("Z22", "THE ROLLBACK $w SET followup_auto_send OFF ITSELF ($c): ", 2),
          ("Z28", "AS-RB1 WROTE followup_auto_send '0', BUT ITS READ-BACK DID NOT HOLD (", 2),
          ("Z28", "WHETHER AS-RB1 SET followup_auto_send OFF IS NOT KNOWN (", 2), ("Z28", "AS-RB1 DID NOT SET followup_auto_send OFF (", 2)]

# ── 18 Nov (rev106-findings, DL11): the texts Z18–Z28 pin, as partUJ, partR and partH write them ──
NRULES = 28
FE_CALL = ("  uj_fe_g1   # FE-G1 — 5.18.106 (DL11): U4 read followup_enabled off — NO-GO, nothing changed; not read — no "
           "refusal (DL2)\n")
FE_GUARD = '  uj_ok "$UJ_A" && [ "$(kv "$UJ_A" en)" = "off" ] || return 0\n'
FE_STOP = ('  stop "NO-GO (FE-G1): followup_enabled reads off — the enquiry follow-ups are paused (the 18 Nov interim). UJ-ON '
           'reads this value from stage A for the life of this deploy, so F3 could never be judged and the claim steps (6, 11, '
           '12) would follow unproven (root docs/75 §L.5, DL3). Turn step 6 off first if it is on (CS-G1), then turn '
           'followup_enabled on, each its own command, alone, then run this again. Nothing was changed."')
FE_CMD = (r"""    echo "      docker exec -u $DB_OWNER -e DN_DATA_DIR=$PDD_IN $CONTAINER php $IN_CONTAINER/tools/set_config.php"""
          r""" --key followup_enabled --value 1 2>&1 | sed '/^  AI SETTINGS\$/,\$d' $(LOGW followup-enabled-1)" """
          r"""  # the owner's own command: printed, never run (FE-G1)""")
DD_LINE = "if ($mode === 'uj') putenv('DN_DATA_DIR=' . rtrim((string)($a[1] ?? ''), '/'));"
VAULT_LINE = ("if (in_array($mode, ['notes', 'ca4', 'ca6', 'ca8', 'on3', 'lon', 'cfgkey', 'uj'], true)) putenv('DN_VAULT_FILE=' . "
              "rtrim((string)($a[1] ?? ''), '/') . '/vault.json');")
TZ = ("'tz=' . preg_replace('~[^A-Za-z0-9_/+-]~', '?', dn_tz())];\n", 'now (in $(kv "$o" tz), the zone the sender reads)')
G_CONDS = ('  [ "$(kv "$o" en)" = "on" ] || g="$g followup_enabled is off now;"\n',
           '  [ "$(kv "$o" ug)" = "1" ] || g="$g StaffJobsGate::applies does not read Uganda now;"\n',
           '  [ "$(kv "$o" hours)" != "misread" ] || g="$g followup_max_age_hours is not a positive number now;"\n',
           '    gs="$(uj_gate_a "$a")"; ga="$gs"\n')
WAIT_NOW = (" Once it holds again, run --after-only only after a close pass has run under it (followup_close runs every 10 "
            "minutes): a run before that judges F3 by passes made while it did not hold, and can read FAIL;")
G_NOW = ('  if [ -n "$g" ]; then g="$g$ga$lim"; UJ_ON_GATE="now"; note "UJ-ON not judged — its gate did not hold:${g%;}$alone. F3 then has nothing '
         'to do, or another limit to do it by.' + WAIT_NOW + ' a note, which holds back nothing"; return 0; fi\n')
G_A = ('  if [ -n "$ga" ]; then ga="$ga$lim"; note "UJ-ON not judged for this deploy — its gate did not hold at stage A:'
       '${ga%;}. F3 may be running now')
G_LIM = ('      || lim=" the age limit differs — $(kv "$a" hours) h at stage A, $(kv "$o" hours) h now;"\n',
         '  if [ -n "$lim" ]; then UJ_ON_GATE="limit"; note "UJ-ON not judged on this run — stage A\'s gate held, but '
         'followup_max_age_hours is ', ' and, once a close pass has run after that (followup_close runs every 10 minutes), '
         'run --after-only again to have F3 judged')
G_RET = 'a note, which holds back nothing"; return 0; fi\n'
U4_TAIL = 'PUT IT RIGHT$( [ -n "$CHECK_ONLY" ] || echo ", ANSWER ANYTHING BUT DEPLOY BELOW"), THEN RUN THIS AGAIN"'
GATE_LIMIT = ('limit) echo "UJ-ON judges F3 on it only once followup_max_age_hours is back at stage A\'s $(kv "$UJ_A" hours) h '
              'and a close pass has run after that (UJ-ON above) — run it only then: a run before that pass judges F3 by '
              'passes made under the other limit, and can read FAIL; while the limit differs it gives that note, no verdict, '
              'and the run reads PASSED without that proof"')
GATE_NOW = ('now) echo "UJ-ON judges F3 on it only once its gate holds now (UJ-ON above names what did not) and a close '
            'pass has run after that (followup_close runs every 10 minutes) — run it only then: a run before that pass '
            'judges F3 by passes made while the gate did not hold, and can read FAIL; while it does not hold it gives that '
            'note, no verdict, and the run reads PASSED without that proof"')
GATE_DEFAULT = '*) echo "UJ-ON judges F3 on it, and until one has it reads NOT YET (exit status 3) and says when to run it again"'
GATE_SAY = '$( case "${UJ_ON_GATE:-}" in ' + GATE_LIMIT + ' ;; ' + GATE_NOW + ' ;; ' + GATE_DEFAULT + ' ;; esac ):'
ELSE_READS = ("to read U1–U3 and the sender's closes and sends since the copy (UJ-CA) — a close pass's own closes are "
              "counted only by a judged UJ-ON — but UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY")
U4_WARN = ('  [ "$(kv "$o" en)" = "off" ] || [ -z "$(uj_gate_a "$o")" ] || note "U4 UJ-ON WILL NOT JUDGE F3 FOR THIS '
           'DEPLOY:$(uj_gate_a "$o" | sed \'s/;$//\'). STAGE A\'S READING IS KEPT IN THE STATE FILE FOR THE LIFE OF THE DEPLOY')
RB_LAST_LOOP = '  for f in "$STATE".prev-* "$OUT"/rollback-state-*.env; do\n'
BACK_V1 = ('  case "$(sed -n \'s/^AS_RB1=//p\' "$STATE" 2>/dev/null | tail -n1)" in \'\'|off|not-written:owner-unknown|'
           'not-written:unread) ;; *) return 0 ;; esac   # this deploy\'s V1 wrote it, may have, or read it on (review 2)\n')
JUDGEABLE = 'uj_judgeable() { [ -z "$(uj_gate_a "$UJ_A")" ]; }'
NY_LINE = 'notyet "UJ-ON NOT YET — $UJ_NY_SAY$alone; run it again after $UJ_NY_AT"'
RB0_HEAD = "  # followup_auto_send: what the rollback will write, said before the question"
RB0_NOOWNER = ('    as=noowner) note "AS-RB0 followup_auto_send and §L.7\'s five conditions could not be read: the owner of '
               'plugin.sqlite3 is not known (DB_OWNER)')
RB0_ARM_NOOWNER = ('    as=noowner) AS_RB0_SAY="followup_auto_send NOT set off — the rollback cannot write it (above)"\n'
                   '      note "THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (noowner: ')
RB0_CAUSE = '            c="it could not be read as the jobs read it${c:+; $c}"\n'
RB0_MAYNOT = ('            if [ "$(kv "$a" pre)" = "ok" ]; then w="MAY NOT"; AS_RB0_SAY="followup_auto_send not set off unless AS-RB1, '
              'reading it again, finds it on and the five conditions holding"; fi\n')
RB0_UNREAD = ('    *) AS_RB0_SAY="followup_auto_send set off first only if AS-RB1, reading it again, finds it on and the five '
              'conditions holding"\n      as_show "$(as_owner_text 0 "$a")" ;;\n  esac\n  return 0\n')
RB_NOTE = 'note "RB the code is $BASELINE_VERSION\'s again where $EXPECTED_VERSION changed it'
RB2_MARKS = ('$( case "${AS_RB1:-}" in written) echo "nothing else moved but followup_auto_send: ',
             "and wrote one key before the put-back, followup_auto_send '0' (AS-RB1: ${AS_RB1_SAY:-?}; root docs/75 §L.7)\" ;; ",
             "written-check:*) echo \"the rollback ran no switch tool and no off-hook, applied or removed no migration, and "
             "wrote followup_auto_send '0' before the put-back through the plugin's own writer — but its read-back did not "
             "hold, so whether anything else moved is not shown",
             'unknown:*) echo "nothing else moved: the rollback ran no switch tool and no off-hook and applied or removed no '
             "migration; whether it wrote followup_auto_send '0' before the put-back is not known",
             '*) echo "nothing else moved: the rollback ran no switch tool and no off-hook, applied or removed no migration, and '
             'wrote no key (AS-RB1: ${AS_RB1_SAY:-this run put nothing back}; root docs/75 §L.7)" ;; esac ) — every other step')
RB4 = ('         not-written:*) note "AS-RB2 followup_auto_send could not be read through $BASELINE_VERSION\'s code after the '
       'rollback (${o#as=}) — AS-RB1 did not write it (${AS_RB1_SAY:-?}; its FAIL above stands)" ;;\n'
       '         *) bad "AS-RB2 followup_auto_send could not be read through $BASELINE_VERSION\'s code after the rollback '
       '(${o#as=}) — whether it reads off is not shown" ;;\n')
EFF = ('AS_RB0=""; AS_RB0_SAY=""; AS_RB1=""; AS_RB1_SAY=""; AS_RB2_EFF=""\n',
       '  AS_RB2_EFF="$(kv "$o" eff)"; [ -n "$AS_RB2_EFF" ] || AS_RB2_EFF="unread"\n')
BACK_ON = ('  [ "$MODE" = "after" ] || return 0\n', '  c="$(sed -n \'s/^COPY_AT=//p\' "$STATE" 2>/dev/null | tail -n1)"\n',
           '  r="$(as_rb_last)"; t="${r##* }"\n', '  case "$c" in \'\'|*[!0-9]*) return 0 ;; esac\n',
           '  case "$t" in \'\'|*[!0-9]*) return 0 ;; esac\n', '  [ "$t" -lt "$c" ] || return 0\n',
           '  [ "${r%% *}" = "written" ] || return 0\n', '  o="$(as_read)"\n', '  [ "$(kv "$o" eff)" = "off" ] || return 0\n')
RB_LAST = "  printf '%s %s' \"${v:-none}\" \"$t\"\n"
BR1 = ("This run is a deploy after that rollback all the same: the copy replaces those files, and its PASSED --after-only "
       "reads the latest rollback's record")
LKAO = ('\nLK_AO_ON=""\n', '  lk_ok "$o" || { note "LK-AO the ladder\'s keys could not be read (${o%% map=*})"; return; }\n'
        '  LK_AO_ON="$(qf_kv "$o" on)"   #')
WHAT_CHANGED = ('$( if [ "$MODE" = "after" ]; then echo "; its keys as LK-AO above reads them against the deploy\'s, the claim '
                'switches among them', '"$LK_AO_ON"', '; else echo " and every key of it is as this run found it (LK-CA1), none of '
                'the three claim switches present', '; fi)"')
WHAT_DIDNOT = ('$( if [ "$MODE" = "after" ]; then echo "the sales follow-up\'s ladder but for your own steps since the deploy',
               '; else echo "every key of the sales follow-up\'s ladder, in every place, as this run found it (LK-B1, LK-CA1), none '
               'of the three claim switches present', '"; fi);')
STEP2 = ('  case ",$LK_AO_ON," in\n    *,chase,*)\n', "Do not run on chase again", "\n    *)\n", '  if [ -n "$LK_AO_ON" ]; then\n',
         'bash $QF_WRAPPER show"', "\n  fi\n", STEPS[1], "\n  esac\n", STEPS[2])
CHECK_IT = ("  if uj_judgeable; then   # 5.18.106 (review L8-1)", "UJ-ON judges F3 on it", "\n  else\n",
            'UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY: its gate did not hold at stage A ($(uj_gate_a "$UJ_A"', "\n  fi\n", AFTER)
AV_MARKS = ('o="$(as_read)"\n  echo\n  if [ "$(kv "$o" eff)" = "off" ]; then\n',
            "echo \"  followup_auto_send reads off now, through $BASELINE_VERSION's code (AS-RB1: ", 'return 0\n  fi\n  case "${AS_RB1:-}" in\n',
            "written-check:*) echo \"  AS-RB1 WROTE followup_auto_send '0', BUT ITS READ-BACK DID NOT HOLD (${AS_RB1#written-check:})",
            'unknown:*) echo "  WHETHER AS-RB1 SET followup_auto_send OFF IS NOT KNOWN (${AS_RB1_SAY:-?})',
            '*) echo "  AS-RB1 DID NOT SET followup_auto_send OFF (${AS_RB1_SAY:-?})', 'esac\n  as_show "$(as_owner_text 0 "$o")"\n')
AP_MARKS = ("rb:written-check:*|v1:written-check:*) printf \"\\n\\n  AS-RB1 WROTE followup_auto_send '0', BUT ITS READ-BACK DID NOT HOLD (",
            "rb:unknown:*|v1:unknown:*) printf '\\n\\n  WHETHER AS-RB1 SET followup_auto_send OFF IS NOT KNOWN (",
            "*) printf '\\n\\n  AS-RB1 DID NOT SET followup_auto_send OFF (", "esac\n  as_owner_text 0 \"$(as_read)\"\n")
WHAT_MEANS = ('$( case "${AS_RB2_EFF:-}" in off) echo "— it reads off now, so a person approves each draft',
              ' ;; on) echo "— IT READS ON NOW (AS-RB2): UNDER $BASELINE_VERSION IT APPROVES AND SENDS AUTOMATICALLY WHILE IT DOES',
              ' ;; *) echo "— whether it reads off now is not shown (AS-RB2 above)" ;; esac ); every other switch')
ONCE.update({
    "Z18": (FE_CALL, FE_GUARD, FE_STOP, FE_CMD + "\n", "uj_fe_g1() {\n"),
    "Z19": (DD_LINE, VAULT_LINE) + TZ,
    "Z20": (G_NOW, G_A, JUDGEABLE, "uj_gate_a() {\n") + G_CONDS + G_LIM,
    "Z22": (RB0_NOOWNER, RB0_ARM_NOOWNER, RB0_CAUSE, RB0_MAYNOT, RB0_UNREAD),
    "Z24": (RB4,) + EFF,
    "Z25": (RB_LAST, BR1, BACK_ON[5], RB_LAST_LOOP, BACK_V1),
    "Z26": (LKAO[0], LKAO[1], WHAT_CHANGED[0], WHAT_DIDNOT[0], STEP2[0]),
    "Z27": (CHECK_IT[0], WHAT_MEANS[0], U4_WARN, U4_TAIL, GATE_SAY, ELSE_READS),
})
NONE.update({
    "Z22": ("THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF ($(as_causes_say",),
    "Z25": ("s/^REDEPLOY=//p", "a --redeploy's", "after a --redeploy whose rollback set"),
    "Z27": ("— with it off a person approves each draft",),
})


def _code(t):
    return [(n, l) for n, l in enumerate(t.split("\n"), 1) if not l.lstrip().startswith(("#", "//"))]


def _body(t, name):
    m = re.search(r"(?ms)^" + re.escape(name) + r"\(\) \{\n(.*?)^\}", t)
    return m.group(1) if m else ""


def _ordered(t, marks, start=0):
    p = [t.find(m, start) for m in marks]
    return min(p) >= 0 and p == sorted(p)


def _seq(t, marks):
    """each mark found, each after the end of the one before it (the rules of 18 Nov)"""
    at = 0
    for m in marks:
        i = t.find(m, at)
        if i < 0:
            return False
        at = i + len(m)
    return True


def rules(t):
    """Every rule over the text t: [(rule, why)], empty when all hold."""
    bad = []
    B = lambda z, m: bad.append((z, m))
    L, CL = t.split("\n"), _code(t)
    for z, ss in ONCE.items():
        for s in ss:
            if t.count(s) != 1:
                B(z, f"{s.strip()[:60]!r} found {t.count(s)} times, not once")
    for z, ss in NONE.items():
        for s in ss:
            if s in t:
                B(z, f"{s.strip()[:60]!r} is in the text")
    for z, s, n in COUNTS:
        if t.count(s) != n:
            B(z, f"{s.strip()[:60]!r} found {t.count(s)} times, not {n}")
    # Z1 the switch tool
    calls = [(n, l) for n, l in CL if "qf_tool " in l and "qf_tool()" not in l]
    if len(calls) < 8:
        B("Z1", f"{len(calls)} qf_tool calls")
    for n, l in calls:
        v = re.findall(r'qf_tool "\$[A-Za-z0-9_]+(?:/ss)?" ([A-Za-z-]+)', l)
        if not v or set(v) - {"show", "--check", "doctor"}:
            B("Z1", f"line {n}: qf_tool {v or l.strip()[:60]}")
    for n, l in CL:
        if not l.lstrip().startswith("echo ") and ("$QF_WRAPPER" in l or re.search(
                r"sales[-_]followup(\.php|\.sh)?\"? (on|off|skip|set|erase)\b", l)):
            B("Z1", f"line {n}: the wrapper or the switch tool's verb outside printed text")
    # Z2 set_config with --key
    runs = [l for _, l in CL if re.search(r'set_config\.php"?\s+--key\b', l) and not DISPLAY.match(l)]
    q6 = [l for l in runs if "--key ucrm_quote_organization_id --value disabled" in l and 'DN_DATA_DIR="$d"' in l]
    oc = [l for l in runs if re.match(r"\s*OWNER_AUTOSEND_CMD=", l) and "--key followup_auto_send --value $v 2>&1" in l]
    if len(runs) != 2 or len(q6) != 1 or len(oc) != 1:
        B("Z2", f"{len(runs)} set_config runs with --key in code, not Q-CA6's and OWNER_AUTOSEND_CMD's alone")
    # Z3 the rollback
    pre = [m.start() for m in re.finditer(r"(?m)^\s+rb_pre106\b(?!\(\))", t)]
    if len(pre) != 1 or not 0 < t.find("Type ROLLBACK", pre[0]) - pre[0] < 400:
        B("Z3", "rb_pre106 is not called once, just before ROLLBACK is typed")
    pr = [l for _, l in CL if l.lstrip().startswith("printf '  Type ROLLBACK to put")]
    if len(pr) != 1 or "AS_RB0_SAY" not in pr[0]:
        B("Z3", "the ROLLBACK prompt does not say what AS-RB1 will write")
    if len(re.findall(r"(?m)^(?:rb106[ \t]*$|.*then rb106 )", t)) != 2:
        B("Z3", "rb106 is not called from V1 and RB alone")
    body = t[t.find("rb106() {\n"):]
    body = body[:body.find("\n}\n")]
    a = body.find('\n  if [ -n "$RB_NOTHING" ]; then\n')
    e = body.find("\n  else\n", a)
    f = body.find('\n  fi\n  o="$(qf_php rbn "$PDD_IN")"\n', e)
    if min(a, e, f) < 0 or body.count("RB_NOTHING") != 1 \
            or 'note "LK-RB1 not judged: this run rolled nothing back' not in body[a:e] \
            or re.search(r'\b(ok|bad) "LK-RB1', body[a:e]) \
            or len(re.findall(r'\b(?:ok|bad) "LK-RB1', body)) != len(re.findall(r'\b(?:ok|bad) "LK-RB1', body[e:f])) \
            or len(re.findall(r'\bbad "LK-RB1', body[e:f])) != 7:
        B("Z3", "review D1: rb106 does not branch once on RB_NOTHING, a plain note, before its seven LK-RB1 verdicts")
    sets = [l.strip() for _, l in CL if "RB_NOTHING=" in l]
    if len(sets) != 2 or sets[0] != 'RB_NOTHING=""' or not sets[1].startswith("else RB_NOTHING=1; DEPLOY_STARTED="):
        B("Z3", "RB_NOTHING is not initialised once and set once, on the nothing-to-roll-back branch")
    rbm = t.find('elif [ "$MODE" = "rollback" ]; then\n  hdr "B. The rollback')
    if rbm < 0 or not _ordered(t, ("    rb_pre106   #", "\n    as_rb1 typed\n", '    if do_rollback; then ok "container serves',
                                   "  else RB_NOTHING=1;", "\nelse\n"), rbm):
        B("Z3", "--rollback is not rb_pre106, ROLLBACK, AS-RB1, do_rollback, the nothing-to-roll-back branch, in order")
    # Z4 the one write
    w = [l for _, l in CL if re.search(r"saveOverrides\s*\(", l) and not DISPLAY.match(l)]
    rb1 = _body(t, "as_rb1")
    if len(w) != 1 or WRITE not in w[0] or WRITE not in rb1 or rb1.count(AS_EXEC) != 1:
        B("Z4", f"{len(w)} saveOverrides in code; the one write is not as_rb1's, as -u \"$DB_OWNER\" with the installed tree")
    ac = [l for _, l in CL if re.search(r"(?<![\w-])as_rb1\b(?!\(\))", l)]
    if len(ac) != 2 or "    as_rb1 typed" not in ac or not any(V1 in l for l in ac):
        B("Z4", f"as_rb1 called {len(ac)} times, not typed and by V1 alone, each before its do_rollback")
    asg = [l for _, l in CL if re.match(r"\s*OWNER_AUTOSEND_CMD=", l)]
    use = [l for _, l in CL if "OWNER_AUTOSEND_CMD" in l and l not in asg]
    if len(asg) != 1 or asg[0] not in _body(t, "as_owner_cmd") or len(use) != 1 \
            or not use[0].lstrip().startswith("printf ' Your own command, alone, with its own log:") \
            or '"$OWNER_AUTOSEND_CMD"' not in use[0] or re.search(r"\beval\b|\bsh -c\b|\|", use[0]):
        B("Z4", "OWNER_AUTOSEND_CMD is not assigned once, in as_owner_cmd, and expanded once, as as_owner_text's printf argument")
    # Z5 retired names in code
    for s in CODE_RETIRED:
        n = [k for k, l in CL if s in l]
        if n:
            B("Z5", f"the retired {s} in code, line(s) {n[:4]}")
    # Z6 --clear-switches
    cs = [(n, l) for n, l in CL if "--clear-switches" in l]
    if len(cs) != 2 or not cs[0][1].startswith("    --clear-switches)   #") or cs[1][0] != cs[0][0] + 1 \
            or not cs[1][1].startswith('      echo "--clear-switches: refused') or not cs[1][1].endswith(">&2; exit 64 ;;"):
        B("Z6", "--clear-switches in code is not the parser's label and its refusal on the next line, exit 64")
    # Z7 the codes
    for c in RETIRED_CODES:
        n = [k for k, l in CL if c in l and SAYS.search(l) and "retire" not in l]
        if n:
            B("Z7", f"the retired {c} printed, line(s) {n[:4]}")
    for c in NEW_CODES:
        if not any(c in l and SAYS.search(l) for _, l in CL):
            B("Z7", f"{c} is printed by no check")
    # Z8 no text says the masked lines write numbers again
    for p in (r"numbers( in)? again", "WRITES PHONE", "step-12 notice"):
        if re.search(p, t, re.I):
            B("Z8", f"{p!r} survives")
    # Z9 pipelines
    q = [n for n, l in CL if QUIET.search(l)]
    if q or not any(re.search(r"\|\s*drained\s+grep\s+-[A-Za-z]*q", l) for _, l in CL) \
            or sum(1 for _, l in CL if re.search(r"grep\s+-[A-Za-z]*q[^|]*<<<", l)) < 28:
        B("Z9", f"a quiet grep reads from a pipe (lines {q[:6]}), or the greps the rule allows are not found")
    # Z10 U5 where its slot stood
    u5 = [k for k, l in enumerate(L) if l.startswith("  " + SLOT)]
    if len(u5) != 1 or L[u5[0] + 1:u5[0] + 2] != ['  cih_drop "$sub"']:
        B("Z10", "U5's reading is not where its slot stood, right before qf_stage's cih_drop")
    # Z11 NOT YET
    ny = [l for _, l in CL if re.search(r'(?<![\w-])notyet\s+"', l)]
    nyu = [l for l in _body(t, "uj_on").split("\n") if not l.lstrip().startswith("#") and re.search(r'(?<![\w-])notyet\s+"', l)]
    if not ny or len(ny) != len(nyu) or not re.search(r"(?m)^notyet\(\) \{", t):
        B("Z11", f"notyet called {len(ny)} times, {len(nyu)} by UJ-ON: NOT YET is UJ-ON's alone")
    i, j = t.find(NY_ELIF), t.find(FAILED)
    br = t[i:j] if 0 <= i < j else ""
    if not _ordered(t, (PASSED, NY_ELIF, FAILED)) or [l for l in br.split("\n") if CMD.search(l)] != [AFTER] \
            or re.search(r"QF_WRAPPER|set_config|ROLLBACK_CMD|PUTBACK_CMD|OWNER_AUTOSEND|as_back_on", br):
        B("Z11", "the summary is not PASSED, NOT YET, FAILED in order, the NOT YET branch printing --after-only alone")
    # Z12 ME-CA1
    for n, l in enumerate(L, 1):
        if "ME-CA1 NOT ESTABLISHED" in l and not l.lstrip().startswith("#") and 'note "ME-CA1 NOT ESTABLISHED — ' not in l:
            B("Z12", f"line {n}: ME-CA1's NOT ESTABLISHED is not a note")
    if not re.search(r'\bbad "ME-CA1 ', t):
        B("Z12", "ME-CA1 has no FAIL")
    # Z13 stage R
    if not _ordered(t, STAGE_R) or any(t.count(s) != 1 for s in STAGE_R) or any(
            sum(1 for _, l in CL if re.match(r"\s*" + f + r"\b(?!\(\))", l)) != 1 for f in ("lm_after", "me_ca1", "uj_after", "qf_on")):
        B("Z13", "stage R is not lm_after, me_ca1, uj_after, qf_on, in order, each called once")
    # Z14 identity
    for m in re.finditer(r"/root/dnb-5\.18\.105|release/5\.18\.105", t):
        if not (t.startswith("/root/dnb-5.18.105/state-5.18.105.env", m.start()) or t.startswith("release/5.18.105 (7835906)", m.start())):
            B("Z14", f"a 5.18.105 identity: {t[m.start():m.start() + 50]!r}")
    c994 = [l for _, l in CL if "994d660" in l]
    if not L[2].startswith("# deploy-5.18.106.sh — ") or len(c994) != 1 \
            or not c994[0].startswith('LM_CONTROL_COMMIT="994d660628cc5b1b42bee648b44b58e389f44bf2"'):
        B("Z14", "the title is not 5.18.106's, or 994d660 is in code beyond LM-G1's control")
    # Z15 printed commands: never two with no text between; the rollback's after the verdict
    def adj(k, lo, hi, is_text, is_cmd):
        for d in (-1, 1):
            q = k + d
            while lo <= q < hi and not is_text(L[q]):
                q += d
            if lo <= q < hi and is_cmd(L[q]):
                B("Z15", f"lines {k + 1} and {q + 1}: two printed commands with no text between")
    st, he = t.find("# Every command printed here stands alone on its line"), t.find("\nset -uo pipefail\n")
    s0, h0 = t.count("\n", 0, st), t.count("\n", 0, he)
    sc = [k for k in range(s0, len(L)) if re.match(r'\s*echo "      \S', L[k])] if st > 0 else []
    hc = [k for k in range(h0) if HCMD.match(L[k])] if he > 0 else []
    for k in sc:
        adj(k, s0, len(L), lambda l: not l.lstrip().startswith("#") and TXT.search(l), lambda l: CMD.search(l))
    for k in hc:
        adj(k, 0, h0, lambda l: re.match(r"#\s*\S", l), lambda l: HCMD.match(l))
    if len(sc) < 10 or len(hc) < 2 or not _ordered(t, (FAILED, ROLLBACK, PUTBACK)):
        B("Z15", f"{len(sc)} printed commands in the summary, {len(hc)} in the header, or the rollback's not after the verdict")
    # Z16 the summary's obligations
    i = t.find(OWN_IF)
    blk = t[i:t.find("\nfi\n", i)] if i >= 0 else ""
    if not _ordered(blk, STEPS) or any(blk.count(s) != 1 for s in STEPS) \
            or any(("set_config.php" in l or "QF_WRAPPER" in l) and not l.lstrip().startswith('echo "') for l in blk.split("\n")) \
            or sum(1 for _, l in CL if re.match(r"\s*as_back_on\b(?!\(\))", l)) != 1:
        B("Z16", "§F's steps are not printed once, in order, after a PASSED --after-only alone, as_back_on last")
    if not _ordered(t, (FAILED, "\nas_after_verdict   # 5.18.106", OWN_IF, ECO_IF, PUTBACK, '\nmain "$@"; exit $?')):
        B("Z16", "after the verdict: not as_after_verdict, §F's steps, ECO Bank's block, the put-back by hand, in order")
    ck = t.find('  echo "  check it          --after-only, three runs')
    if ck < 0 or t[ck:t.find('  echo "      $QCHECK_CMD"', ck)].count(AFTER) != 3:
        B("Z16", "check it does not print --after-only three times before the quarantine's --check")
    # Z18 FE-G1 (DL11): called once, in qf_stage, after CS-G1 and the UJ reading, before U5; on a whole reading of
    # followup_enabled off alone; its command printed, then its one NO-GO, last; the command once, by echo, alone,
    # never run
    qs, fe = _body(t, "qf_stage"), _body(t, "uj_fe_g1")
    fc = [l for _, l in CL if re.match(r"\s*uj_fe_g1\b(?!\(\))", l)]
    if fc != [FE_CALL.rstrip("\n")] or not _seq(qs, ('  cs_check CS-G1 "$LK_G1"', '  uj_stage "$sub"', FE_CALL, "  " + SLOT)):
        B("Z18", "FE-G1 is not called once, in qf_stage, after CS-G1 and the UJ reading and before U5")
    if not fe.startswith(FE_GUARD) or not fe.endswith(FE_STOP + "\n") \
            or sum(1 for l in fe.split("\n") if l.lstrip().startswith('stop "')) != 1 \
            or not _seq(fe, ('  if [ -n "$DB_OWNER" ]; then\n', FE_CMD + "\n", FE_STOP)):
        B("Z18", "uj_fe_g1 is not: a whole reading of followup_enabled off, the owner's command printed, then its one NO-GO")
    fl = [l for _, l in CL if "followup-enabled-1" in l]
    if fl != [FE_CMD]:
        B("Z18", f"FE-G1's command is not printed once, by echo, alone on its line ({len(fl)} line(s) carry its log)")
    # Z19 UJ-1: the uj mode names its copy as the data folder (dn_tz(), the window's zone), right after the vault rule
    dd = [k for k, l in enumerate(L) if l == DD_LINE]
    if len(dd) != 1 or dd[0] < 3 or L[dd[0] - 3] != VAULT_LINE \
            or not 0 <= t.find("QF_PHP=\"$(cat <<'PHP'\n") < t.find(DD_LINE) < t.find("    case 'uj':"):
        B("Z19", "the uj mode does not name its copy as the data folder, right after the vault rule, at QF_PHP's top")
    # Z20 UJ-2: false now — nothing to do; false only at stage A — not judged for this deploy; nothing of stage A in g
    uo = _body(t, "uj_on")
    if not _seq(uo, G_CONDS + (G_LIM[0], G_NOW, G_A, G_RET, G_LIM[1], G_LIM[2], G_RET,
                               '  [ -n "$cu" ] || { bad "UJ-ON FAIL — the copy')) \
            or t.count("F3 then has nothing to do") != 1 or re.search(r'\bg="\$g[^"$]*at stage A', uo) \
            or t.count("cannot judge F3 for this deploy") != 1 or "set back to stage A's" in t:
        B("Z20", "UJ-ON's gate is not split: false now (nothing to do), then false only at stage A (not judged for this deploy)")
    # Z21 UJ-3: NOT YET's counts in UJ_NY_SAY, so the wait line and the summary carry them once each
    ny = [l.strip() for l in uo.split("\n") if l.strip().startswith("UJ_NY_SAY=")]
    nyc = [l.strip() for l in uo.split("\n") if re.search(r'(?<![\w-])notyet\s+"', l)]
    if len(ny) != 2 or not all(s.endswith('; $cnt"') for s in ny) or [re.sub(r"\s*;;$", "", s) for s in nyc] != [NY_LINE, NY_LINE]:
        B("Z21", "NOT YET's counts are not in UJ_NY_SAY, said once, in the wait line and the summary alike")
    # Z22 RB-1: noowner in capitals; a reading by its cause, never empty; a reader that did not run, no capitals
    rp = _body(t, "rb_pre106")
    tl = rp[rp.find(RB0_HEAD):] if RB0_HEAD in rp else ""
    if RB0_NOOWNER not in rp or not tl.endswith(RB0_UNREAD) or tl.count("ANSWER ANYTHING BUT ROLLBACK") != 3 \
            or not _seq(tl, (RB0_ARM_NOOWNER, '    "as=read "*)\n', RB0_CAUSE, RB0_MAYNOT, RB0_UNREAD)):
        B("Z22", "rb_pre106's AS-RB0 arms are not: noowner in capitals, a reading by its cause, an unread reader no capitals")
    # Z23 RB-2: the RB note says the one key written only when AS-RB1 wrote it
    rbn = [l for l in L if l.startswith(RB_NOTE)]
    if len(rbn) != 1 or not _seq(rbn[0], RB2_MARKS) or rbn[0].count("wrote one key") != 1:
        B("Z23", "the RB note does not say the one key written only when AS-RB1 wrote it")
    # Z24 RB-4: AS-RB2 could not read it — a note after AS-RB1's recorded cause, else a FAIL; AS_RB2_EFF what it read
    r2 = _body(t, "as_rb2")
    if RB4 not in r2 or not r2.startswith("  local o; o=\"$(as_read)\"\n" + EFF[1]) or r2.count('bad "AS-RB2') != 2:
        B("Z24", "AS-RB2's could-not-read branch is not a note after AS-RB1's recorded cause, or AS_RB2_EFF is not what it read")
    # Z25 L8-4: as_back_on by the latest rollback's RB_COPY_AT against this deploy's COPY_AT, --redeploy typed or not
    bo = _body(t, "as_back_on")
    if not _seq(bo, BACK_ON) or "REDEPLOY" in bo or not _body(t, "as_rb_last").endswith(RB_LAST) \
            or RB_LAST_LOOP not in _body(t, "as_rb_last") or not bo.startswith("  local o c r t\n" + BACK_ON[0] + BACK_V1):
        B("Z25", "as_back_on does not go by the latest rollback's RB_COPY_AT against this deploy's COPY_AT")
    # Z26 L8-2: LK_AO_ON defined once and set once, in lk_ao; the claim clauses by the mode; §F's step 2 by LK-AO's
    # reading
    wcl = [l for l in L if l.startswith('  echo "  what changed      ')]
    wdl = [l for l in L if l.startswith('  echo "  what did not      ')]
    if sum(1 for _, l in CL if re.match(r"\s*LK_AO_ON=", l)) != 2 or LKAO[1] not in _body(t, "lk_ao") \
            or len(wcl) != 1 or not _seq(wcl[0], WHAT_CHANGED) or wcl[0].count("none of the three claim switches") != 1 \
            or len(wdl) != 1 or not _seq(wdl[0], WHAT_DIDNOT) or wdl[0].count("none of the three claim switches") != 1 \
            or not _seq(blk, STEP2) or blk.count(STEP2[1]) != 1:
        B("Z26", "the claim-switch clauses do not go by the mode, or §F's step 2 by LK-AO's reading (LK_AO_ON)")
    # Z27 L8-1, L8-3: check it by stage A's reading (uj_judgeable); what it means by AS-RB2's (AS_RB2_EFF)
    ckt = t[ck:t.find('  echo "      $QCHECK_CMD"', ck)] if ck >= 0 else ""
    wm = [l for l in L if l.startswith('            echo "  what it means     ')]
    if not _seq(ckt, CHECK_IT) or ckt.count("if uj_judgeable") != 1 or len(wm) != 1 or not _seq(wm[0], WHAT_MEANS) \
            or U4_WARN not in _body(t, "uj_u4") or U4_TAIL not in _body(t, "uj_u4") or GATE_SAY not in ckt \
            or ELSE_READS not in ckt:
        B("Z27", "check it does not go by stage A's reading, or what it means by what AS-RB2 read")
    # Z28 RB-3, L8-3: the headline by what AS-RB1 recorded — written but not read back, not known, not written — after a
    # typed rollback's verdict (as_after_verdict, which first reads it again: off now is a plain line) and in as_para
    if not _seq(_body(t, "as_after_verdict"), AV_MARKS) or not _seq(_body(t, "as_para"), AP_MARKS):
        B("Z28", "the headline after the rollback's verdict, or in its stop messages, does not go by what AS-RB1 recorded")
    return bad


def _rep(t, old, new):
    if t.count(old) != 1:
        raise SystemExit(f"PART Z CONTROL: its anchor is found {t.count(old)} times: {old[:80]!r}")
    return t.replace(old, new)


def _at(t, test, f):
    L = t.split("\n")
    k = [i for i, l in enumerate(L) if test(l)]
    if len(k) != 1:
        raise SystemExit(f"PART Z CONTROL: its line is found {len(k)} times")
    return "\n".join(L[:k[0]] + f(L[k[0]]) + L[k[0] + 1:])


def _swap(t, pa, pb):
    L = t.split("\n")
    a = [i for i, l in enumerate(L) if l.startswith(pa)]
    b = [i for i, l in enumerate(L) if l.startswith(pb)]
    if len(a) != 1 or len(b) != 1:
        raise SystemExit(f"PART Z CONTROL: the lines to swap are found {len(a)} and {len(b)} times")
    L[a[0]], L[b[0]] = L[b[0]], L[a[0]]
    return "\n".join(L)


def _in_line(t, prefix, start, end, new):
    """the line starting with prefix (once): what lies from start to the first end after it, ends included, replaced"""
    def f(l):
        i = l.find(start)
        j = l.find(end, i)
        if i < 0 or j < 0:
            raise SystemExit(f"PART Z CONTROL: {start[:40]!r} … {end!r} not found in its line")
        return [l[:i] + new + l[j + len(end):]]
    return _at(t, lambda l: l.startswith(prefix), f)


ME = "\nme_ca1() {\n"
ins = lambda s: (lambda t: _rep(t, ME, ME + s + "\n"))
CONTROLS = [   # (rule, what, the weakened copy)
    ("Z1", "the switch tool's on in code", ins('  qf_tool "$sub" on chase')),
    ("Z2", "set_config with another key",
     ins('  docker exec -u "$DB_OWNER" "$CONTAINER" php "$IN_CONTAINER/tools/set_config.php" --key followup_enabled --value 0')),
    ("Z3", "rb_pre106 not called", lambda t: _at(t, lambda l: l.startswith("    rb_pre106   # "), lambda l: [])),
    ("Z4", "a second saveOverrides", ins("  dxo php -r 'PluginConfig::saveOverrides(getenv(\"DN_DATA_DIR\"), [\"followup_enabled\" => \"0\"]);'")),
    ("Z4", "the write without -u", lambda t: _rep(t, AS_EXEC, 'docker exec -e AS_ROOT="$IN_CONTAINER"')),
    ("Z4", "V1's write after its do_rollback",
     lambda t: _rep(t, V1, V1.replace("; as_rb1 v1; if do_rollback", "; if do_rollback; then as_rb1 v1; fi; if do_rollback"))),
    ("Z4", "OWNER_AUTOSEND_CMD run",
     lambda t: _at(t, lambda l: re.match(r"\s*OWNER_AUTOSEND_CMD=", l), lambda l: [l, '  eval "$OWNER_AUTOSEND_CMD"'])),
    ("Z5", "rb_pre105 named", lambda t: _rep(t, "\nrb_pre106() {\n", "\n# rb_pre105\nrb_pre106() {\n")),
    ("Z6", "the refusal goes on",
     lambda t: _at(t, lambda l: l.startswith('      echo "--clear-switches: refused'), lambda l: [l.replace("; exit 64 ;;", " ;;")])),
    ("Z7", "CS-B1 printed by nothing", lambda t: t.replace("CS-B1", "CS-BX")),
    ("Z8", "numbers again", ins('  note "LM-RB1 THE MASKED LINES WRITE PHONE NUMBERS AGAIN"')),
    ("Z9", "a judged grep from a pipe", lambda t: _rep(t, PIPES[0][1], PIPES[0][0])),
    ("Z10", "slot U5 left open", lambda t: _at(t, lambda l: l.startswith("  " + SLOT), lambda l: [l, "  # ── SLOT U5 ── open ──"])),
    ("Z11", "NOT YET exits 0", lambda t: _rep(t, '[ "$NOT_YET" = "0" ] || return 3', '[ "$NOT_YET" = "0" ] || return 0')),
    ("Z11", "notyet outside UJ-ON", ins('  notyet "ME-CA1 waits"')),
    ("Z11", "an owner's step in the NOT YET branch",
     lambda t: _rep(t, NY_ELIF, NY_ELIF + '  echo "      cd $REPO && bash $QF_WRAPPER on chase"\n')),
    ("Z12", "a NOT ESTABLISHED made a FAIL",
     lambda t: _rep(t, 'note "ME-CA1 NOT ESTABLISHED — THE POSITIVE', 'bad "ME-CA1 NOT ESTABLISHED — THE POSITIVE')),
    ("Z13", "ME-CA1 after UJ-CA", lambda t: _rep(_rep(t, STAGE_R[1], "\n"), STAGE_R[2], STAGE_R[2] + "me_ca1\n")),
    ("Z13", "ME-CA1 twice", lambda t: _rep(t, STAGE_R[1], STAGE_R[1] + "me_ca1\n")),
    ("Z14", "the run's folder 5.18.105's",
     lambda t: _rep(t, 'OUT="${DNB_OUT:-/root/dnb-5.18.106}"', 'OUT="${DNB_OUT:-/root/dnb-5.18.105}"')),
    ("Z14", "994d660 the baseline in code",
     lambda t: _rep(t, 'BASELINE_COMMIT_FULL="7835906a0e4af49557f7c0eb994cb9129637ca00"',
                    'BASELINE_COMMIT_FULL="994d660628cc5b1b42bee648b44b58e389f44bf2"')),
    ("Z15", "the rollback next to --after-only", lambda t: _rep(t, AFTER + "\n  echo\n", AFTER + "\n" + ROLLBACK + "\n  echo\n")),
    ("Z16", "ECO Bank's block after a NOT YET", lambda t: _rep(t, ECO_IF, ECO_IF.replace(' && [ "$NOT_YET" = "0" ]', ""))),
    ("Z16", "§F's steps out of order",
     lambda t: _rep(_rep(_rep(t, STEPS[0], "\0"), STEPS[3], STEPS[0].replace("CAP", "LIMIT")), "\0", STEPS[3].replace("LIMIT", "CAP"))),
    ("Z17", "a re-pointed text back", lambda t: _rep(t, "\nrb_pre106() {\n", "\n# the live plugin is 5.18.104\nrb_pre106() {\n")),
    ("Z18", "FE-G1 called before CS-G1",
     lambda t: _rep(_rep(t, FE_CALL, ""), '  cs_check CS-G1 "$LK_G1"', FE_CALL + '  cs_check CS-G1 "$LK_G1"')),
    ("Z18", "FE-G1 made a note", lambda t: _rep(t, '  stop "NO-GO (FE-G1): ', '  note "NO-GO (FE-G1): ')),
    ("Z18", "FE-G1 refusing an unread reading too", lambda t: _rep(t, FE_GUARD, '  [ "$(kv "$UJ_A" en)" != "on" ] || return 0\n')),
    ("Z18", "FE-G1's command piped into sh",
     lambda t: _rep(t, FE_CMD, FE_CMD.replace('$(LOGW followup-enabled-1)"   #', '$(LOGW followup-enabled-1)" | sh   #'))),
    ("Z18", "a second command on FE-G1's line",
     lambda t: _rep(t, FE_CMD, FE_CMD.replace('$(LOGW followup-enabled-1)"   #',
                                              '$(LOGW followup-enabled-1)"; echo "      cd $REPO && bash $QF_WRAPPER show"   #'))),
    ("Z19", "the uj mode without its data folder", lambda t: _at(t, lambda l: l == DD_LINE, lambda l: [])),
    ("Z19", "the data folder named for another mode", lambda t: _rep(t, DD_LINE, DD_LINE.replace("'uj'", "'lk'"))),
    ("Z20", "UJ-ON's two gate branches swapped",
     lambda t: _swap(t, '  if [ -n "$g" ]; then g="$g$ga$lim"; UJ_ON_GATE="now"; note "UJ-ON not judged',
                     '  if [ -n "$ga" ]; then ga="$ga$lim"; note "UJ-ON not judged')),
    ("Z20", "the limit alone differing put back into the stage-A note",
     lambda t: _rep(t, '      || lim=" the age limit differs', '      || ga="$ga the age limit differs')),
    ("Z20", "the 'now' note without its close pass", lambda t: _rep(t, WAIT_NOW, ";")),
    ("Z20", "the limit-only note falling through to the verdict",
     lambda t: _rep(t, 'without that proof; a note, which holds back nothing"; return 0; fi\n  [ -n "$cu" ]',
                    'without that proof; a note, which holds back nothing"; fi\n  [ -n "$cu" ]')),
    ("Z20", "the stage-A note falling through",
     lambda t: _rep(t, 'its stage A taken with the gate holding; a note, which holds back nothing"; return 0; fi\n',
                    'its stage A taken with the gate holding; a note, which holds back nothing"; fi\n')),
    ("Z20", "the limit-only note without its close pass",
     lambda t: _rep(t, ' and, once a close pass has run after that (followup_close runs every 10 minutes), run',
                    ' and run')),
    ("Z20", "the limit-only note setting no UJ_ON_GATE",
     lambda t: _rep(t, 'then UJ_ON_GATE="limit"; note', 'then note')),
    ("Z20", "the limit-only note saying F3 cannot be judged for this deploy",
     lambda t: _rep(t, "run --after-only again to have F3 judged", "UJ-ON cannot judge F3 for this deploy")),
    ("Z20", "the stage-A branch says nothing to do", lambda t: _rep(t, "F3 may be running now", "F3 then has nothing to do")),
    ("Z21", "NOT YET's counts left out of UJ_NY_SAY",
     lambda t: _rep(t, ', and none closed expired yet; $cnt"\n', ', and none closed expired yet"\n')),
    ("Z22", "an unread reader in capitals",
     lambda t: _rep(t, RB0_UNREAD, RB0_UNREAD.replace(
         '      as_show "$(as_owner_text 0 "$a")" ;;\n',
         '      note "THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (${a#as=}): ANSWER ANYTHING BUT ROLLBACK BELOW"\n'
         '      as_show "$(as_owner_text 0 "$a")" ;;\n'))),
    ("Z22", "an empty cause", lambda t: _rep(t, RB0_CAUSE, '            c="$c"\n')),
    ("Z23", "a read-back that did not hold said as nothing else moved",
     lambda t: _rep(t, '"${AS_RB1:-}" in written) echo "nothing else moved but',
                    '"${AS_RB1:-}" in written|written-check:*) echo "nothing else moved but')),
    ("Z23", "the RB note's write unconditional",
     lambda t: _in_line(t, RB_NOTE, '$( case "${AS_RB1:-}" in written)', " esac )",
                        "nothing else moved but followup_auto_send: the rollback ran no switch tool and no off-hook, applied or "
                        "removed no migration, and wrote one key before the put-back, followup_auto_send '0' (AS-RB1: "
                        "${AS_RB1_SAY:-this run put nothing back}; root docs/75 §L.7)")),
    ("Z24", "AS-RB2 failing again on AS-RB1's recorded cause",
     lambda t: _rep(t, '         not-written:*) note "AS-RB2 followup_auto_send could not be read',
                    '         not-written:*) bad "AS-RB2 followup_auto_send could not be read')),
    ("Z25", "as_rb_last reading this deploy's own state file",
     lambda t: _rep(t, RB_LAST_LOOP, '  for f in "$STATE" "$STATE".prev-* "$OUT"/rollback-state-*.env; do\n')),
    ("Z25", "as_back_on letting a V1 that read it on through",
     lambda t: _rep(t, BACK_V1, '  case "$(sed -n \'s/^AS_RB1=//p\' "$STATE" 2>/dev/null | tail -n1)" in '
                    'written|written-check:*|unknown:*) return 0 ;; esac\n')),
    ("Z25", "as_back_on returning on any AS_RB1 of this deploy's V1",
     lambda t: _rep(t, BACK_V1, "  ! grep -q '^AS_RB1=' \"$STATE\" 2>/dev/null || return 0\n")),
    ("Z25", "as_back_on offering after this deploy's own V1", lambda t: _rep(t, BACK_V1, "")),
    ("Z25", "as_back_on gated on --redeploy again",
     lambda t: _rep(t, BACK_ON[5], '  [ "$(sed -n \'s/^REDEPLOY=//p\' "$STATE" 2>/dev/null | tail -n1)" = "1" ] || return 0\n')),
    ("Z26", "LK_AO_ON never set", lambda t: _at(t, lambda l: l.startswith('  LK_AO_ON="$(qf_kv "$o" on)"'), lambda l: [])),
    ("Z26", "step 6 never read as already on", lambda t: _rep(t, "    *,chase,*)\n", "    *,chase-never,*)\n")),
    ("Z26", "the deploy's claim clause in --after-only too",
     lambda t: _rep(t, '$( if [ "$MODE" = "after" ]; then echo "; its keys as LK-AO', '$( if false; then echo "; its keys as LK-AO')),
    ("Z28", "a write not read back said as not written",
     lambda t: _rep(t, "written-check:*) echo \"  AS-RB1 WROTE followup_auto_send '0', BUT ITS READ-BACK DID NOT HOLD (${AS_RB1#written-check:})",
                    "written-check:*) echo \"  AS-RB1 DID NOT SET followup_auto_send OFF (${AS_RB1_SAY:-?})")),
    ("Z28", "no plain line when it reads off now",
     lambda t: _rep(t, "    echo \"  followup_auto_send reads off now, through $BASELINE_VERSION's code (AS-RB1: ",
                    "    echo \"  FOLLOWUP_AUTO_SEND READS OFF NOW, through $BASELINE_VERSION's code (AS-RB1: ")),
    ("Z27", "check it without its 'now' arm", lambda t: _rep(t, " ;; " + GATE_NOW, "")),
    ("Z27", "check it's limit arm promising NOT YET again",
     lambda t: _rep(t, GATE_LIMIT, 'limit) echo "UJ-ON judges F3 on it, and until one has it reads NOT YET (exit status 3) '
                    'and says when to run it again"')),
    ("Z27", "check it's 'now' arm without its close pass",
     lambda t: _rep(t, ' and a close pass has run after that (followup_close runs every 10 minutes) — run it only then',
                    ' — run it only then')),
    ("Z27", "check it's else branch saying it reads the closes",
     lambda t: _rep(t, ELSE_READS, 'to read the closes — but UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY')),
    ("Z27", "U4's DEPLOY clause in --check too",
     lambda t: _rep(t, U4_TAIL, 'PUT IT RIGHT, ANSWER ANYTHING BUT DEPLOY BELOW, THEN RUN THIS AGAIN"')),
    ("Z27", "check it promising a verdict whatever UJ-ON's gate note",
     lambda t: _rep(t, 'case "${UJ_ON_GATE:-}" in limit)', 'case "" in limit)')),
    ("Z27", "stage A silent before DEPLOY where UJ-ON will not judge F3",
     lambda t: _at(t, lambda l: l.startswith(U4_WARN), lambda l: [])),
    ("Z27", "check it promising a judgement whatever stage A read",
     lambda t: _rep(t, "  if uj_judgeable; then   # 5.18.106 (review L8-1)", "  if true; then   # 5.18.106 (review L8-1)")),
    ("Z27", "what it means unconditional again",
     lambda t: _in_line(t, '            echo "  what it means     ', '$( case "${AS_RB2_EFF:-}" in off)', " esac )",
                        "— with it off a person approves each draft, and it stays off until you turn it on")),
]


def apply(doc, ctx):
    if ctx.get("as_slot_before") != SLOT:
        raise SystemExit(f"PART Z: partR's slot line is {ctx.get('as_slot_before')!r}, not {SLOT!r}")
    close(doc, "U5", SLOT, why="slot U5 closed (partR's U5 above it)")
    bad = rules(doc.t)
    if bad:
        raise SystemExit(f"PART Z: {len(bad)} refusal(s): " + "; ".join(f"{z}: {m}" for z, m in bad[:30]))
    for z, what, weak in CONTROLS:
        got = rules(weak(doc.t))
        if sorted({g for g, _ in got}) != [z]:
            raise SystemExit(f"PART Z CONTROL ({what}): refused for {sorted({g for g, _ in got}) or 'nothing'}, not {z} alone: {got[:3]}")
    if sorted({c[0] for c in CONTROLS}) != sorted(f"Z{k}" for k in range(1, NRULES + 1)):
        raise SystemExit("PART Z: a rule has no weakened copy")
    ctx.update(z_rules=NRULES, z_controls=len(CONTROLS))

"""Fix round 2 of deploy-5.18.106.sh: review 2's eleven findings, by asserted edits to the part files.

Usage: python3 -I -B fix2.py DIR   (DIR = wip/derive-5.18.106). Every edit asserts its anchor's count before, the
replacement's count after, and the file's new text; exits non-zero on any miss and writes nothing unless all hold.
"""
import os
import sys

D = sys.argv[1]
FILES = {}


def load(name):
    if name not in FILES:
        with open(os.path.join(D, name), encoding="utf-8") as f:
            FILES[name] = f.read()
    return FILES[name]


def rep(name, old, new, n=1):
    t = load(name)
    c = t.count(old)
    if c != n:
        raise SystemExit(f"FIX2 {name}: anchor found {c} times, not {n}: {old[:100]!r}")
    before_new = t.count(new) if new else 0
    t2 = t.replace(old, new)
    if new and t2.count(new) != before_new + n:
        raise SystemExit(f"FIX2 {name}: replacement not counted {n} more times: {new[:100]!r}")
    if old not in new and t2.count(old) != 0:
        raise SystemExit(f"FIX2 {name}: anchor still present after the edit: {old[:100]!r}")
    FILES[name] = t2


# ── partR: RB2-wc — the RB note's written-check arm no longer says "nothing else moved" ──
R_OLD_ARM = ("in written|written-check:*) echo \\\"nothing else moved but followup_auto_send: the rollback ran no switch tool "
             "and no off-hook, applied or removed no migration, and wrote one key before the put-back, followup_auto_send '0' "
             "(AS-RB1: ${AS_RB1_SAY:-?}; root docs/75 §L.7)\\\" ;; unknown:*)")
R_NEW_ARM = ("in written) echo \\\"nothing else moved but followup_auto_send: the rollback ran no switch tool "
             "and no off-hook, applied or removed no migration, and wrote one key before the put-back, followup_auto_send '0' "
             "(AS-RB1: ${AS_RB1_SAY:-?}; root docs/75 §L.7)\\\" ;; written-check:*) echo \\\"the rollback ran no switch tool and "
             "no off-hook, applied or removed no migration, and wrote followup_auto_send '0' before the put-back through the "
             "plugin's own writer — but its read-back did not hold, so whether anything else moved is not shown: AS-RB1's FAIL "
             "above names what differed (AS-RB1: ${AS_RB1_SAY:-?}; root docs/75 §L.7)\\\" ;; unknown:*)")
rep("partR.py", R_OLD_ARM, R_NEW_ARM)
rep("partR.py",
    "                 ('written|written-check:*) echo \"nothing else moved but followup_auto_send: ', 1),\n",
    "                 ('written) echo \"nothing else moved but followup_auto_send: ', 1),\n"
    "                 ('written-check:*) echo \"the rollback ran no switch tool and no off-hook, applied or removed no migration, '\n"
    "                  'and wrote followup_auto_send \\'0\\' before the put-back through the plugin\\'s own writer — but its '\n"
    "                  'read-back did not hold, so whether anything else moved is not shown', 1),\n")

# ── partR: RB4-sib — AS-RB2's not-written note promises a command only where its cause allows one ──
rep("partR.py",
    "AS-RB1 did not write it (${AS_RB1_SAY:-?}; its FAIL above): your own command is printed $(",
    "AS-RB1 did not write it (${AS_RB1_SAY:-?}; its FAIL above): your own command, where its cause allows one, is printed $(")

# ── partR: L84-V1 — as_rb_last reads this deploy's own state file too; as_back_on offers nothing after its own V1 ──
rep("partR.py",
    "    p.append(cw(\"as_rb_last — the latest rollback record (root docs/75 §L.8): among this folder's kept state-*.env.prev-* \"\n",
    "    p.append(cw(\"as_rb_last — the latest rollback record (root docs/75 §L.8): among this deploy's own state file (where \"\n"
    "                \"a V1 rollback records its own; review 2), this folder's kept state-*.env.prev-* \"\n")
rep("partR.py",
    "  for f in \"$STATE\".prev-* \"$OUT\"/rollback-state-*.env; do\n",
    "  for f in \"$STATE\" \"$STATE\".prev-* \"$OUT\"/rollback-state-*.env; do\n")
rep("partR.py",
    "                \"rollback whose copy came after this deploy's (one that left 5.18.106 serving) offers nothing here: its own \"\n",
    "                \"rollback whose copy came after this deploy's (one that left 5.18.106 serving), or a V1 rollback of this \"\n"
    "                \"deploy (its AS_RB1 in this state file, its copy made or not; review 2), offers nothing here: its own \"\n")
rep("partR.py",
    "as_back_on() {\n  local o c r t\n  [ \"$MODE\" = \"after\" ] || return 0\n",
    "as_back_on() {\n  local o c r t\n  [ \"$MODE\" = \"after\" ] || return 0\n"
    "  ! grep -q '^AS_RB1=' \"$STATE\" 2>/dev/null || return 0   # a V1 rollback of this deploy (review 2)\n")
rep("partR.py",
    "                 (\"printf '%s %s' \\\"${v:-none}\\\" \\\"$t\\\"\", 1), ('  [ \"$t\" -lt \"$c\" ] || return 0\\n', 1),\n",
    "                 (\"printf '%s %s' \\\"${v:-none}\\\" \\\"$t\\\"\", 1), ('  [ \"$t\" -lt \"$c\" ] || return 0\\n', 1),\n"
    "                 ('  for f in \"$STATE\" \"$STATE\".prev-* \"$OUT\"/rollback-state-*.env; do\\n', 1),\n"
    "                 (\"  ! grep -q '^AS_RB1=' \\\"$STATE\\\" 2>/dev/null || return 0   # a V1 rollback of this deploy\", 1),\n")

# ── partUJ: UJ2-G3 / UJ-2-limit-text — the limit alone differing is its own note, judged once set back ──
rep("partUJ.py",
    "  local o=\"$1\" cu=\"$2\" a=\"$UJ_A\" g=\"\" ga=\"\" gs=\"\" alone=\"\" ex ctl t cn st stn r cnt\n",
    "  local o=\"$1\" cu=\"$2\" a=\"$UJ_A\" g=\"\" ga=\"\" gs=\"\" lim=\"\" alone=\"\" ex ctl t cn st stn r cnt\n")
rep("partUJ.py",
    "      || ga=\"$ga the age limit differs — $(kv \"$a\" hours) h at stage A, $(kv \"$o\" hours) h now;\"\n",
    "      || lim=\" the age limit differs — $(kv \"$a\" hours) h at stage A, $(kv \"$o\" hours) h now;\"\n")
rep("partUJ.py",
    "  if [ -n \"$g\" ]; then g=\"$g$ga\"; note \"UJ-ON not judged — its gate did not hold:",
    "  if [ -n \"$g\" ]; then g=\"$g$ga$lim\"; note \"UJ-ON not judged — its gate did not hold:")
rep("partUJ.py",
    "  # false only at stage A, or the limit differing: F3 may run now, but this deploy's stage A is fixed in the state\n"
    "  # file (a deploy run while 5.18.106 serves is an --after-only of it): UJ-ON cannot judge F3 for it (review UJ-2)\n"
    "  if [ -n \"$ga\" ]; then note \"UJ-ON not judged for this deploy",
    "  # false only at stage A: F3 may run now, but this deploy's stage A is fixed in the state file (a deploy run while\n"
    "  # 5.18.106 serves is an --after-only of it): UJ-ON cannot judge F3 for it (review UJ-2)\n"
    "  if [ -n \"$ga\" ]; then ga=\"$ga$lim\"; note \"UJ-ON not judged for this deploy")
rep("partUJ.py",
    "A judged UJ-ON needs a rollback and then a --redeploy, its stage A taken with the gate holding$( [ -n \"$gs\" ] || echo \" — or "
    "followup_max_age_hours set back to stage A's $(kv \"$a\" hours) h\"); a note, which holds back nothing\"; return 0; fi\n",
    "A judged UJ-ON needs a rollback and then a --redeploy, its stage A taken with the gate holding; a note, which holds "
    "back nothing\"; return 0; fi\n"
    "  # stage A's gate held, but the age limit is another now: UJ-ON judges F3 only by the limit stage A read — set\n"
    "  # back, and a close pass run after that, the next --after-only judges F3 (review 2: UJ2-G3)\n"
    "  if [ -n \"$lim\" ]; then note \"UJ-ON not judged on this run — stage A's gate held, but followup_max_age_hours is "
    "$(kv \"$o\" hours) h now and was $(kv \"$a\" hours) h at stage A, and UJ-ON judges F3 only by the limit stage A read: "
    "set it back to $(kv \"$a\" hours) h and, once a close pass has run after that (followup_close runs every 10 minutes), "
    "run --after-only again to have F3 judged — or, to keep the new limit, a rollback and then a --redeploy, whose stage A "
    "reads it. Until then F3 is unproven, and the steps that wait for it (6, 11, 12) would follow without that proof; a "
    "note, which holds back nothing\"; return 0; fi\n")
rep("partUJ.py",
    "         \"limit to do it by); false only at stage A, or the limit differing, F3 may run now but cannot be judged for this \"\n"
    "         \"deploy, whose stage A is fixed in the state file (review UJ-2); a reading that could not be made is never \"\n",
    "         \"limit to do it by); false only at stage A, F3 may run now but cannot be judged for this \"\n"
    "         \"deploy, whose stage A is fixed in the state file (review UJ-2); stage A's gate holding and only the limit now \"\n"
    "         \"another, it is not judged on this run, and is once the limit is set back and a close pass has run after it \"\n"
    "         \"(review 2); a reading that could not be made is never \"\n")
rep("partUJ.py",
    "                 (\"UJ-ON not judged for this deploy — its gate did not hold at stage A:${ga%;}. F3 may be running now\", 1),\n",
    "                 (\"UJ-ON not judged for this deploy — its gate did not hold at stage A:${ga%;}. F3 may be running now\", 1),\n"
    "                 (\"UJ-ON not judged on this run — stage A's gate held, but followup_max_age_hours is \", 1),\n"
    "                 (\"cannot judge F3 for this deploy\", 1), (\"set back to stage A's\", 0),\n"
    "                 (\"U4 UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY:\", 1),\n")

# ── partUJ: L8-1 point 1 (L8-1-pt1-unbuilt, FX1-1, L8-1-residual) — stage A says so before DEPLOY, in capitals ──
rep("partUJ.py",
    "    + cw(\"uj_u4 LINE — U4, stage A: the settings as the jobs read them; StaffJobsGate::applies false (F1–F5 inert) in capitals\")\n",
    "    + cw(\"uj_u4 LINE — U4, stage A: the settings as the jobs read them; StaffJobsGate::applies false (F1–F5 inert) in capitals; \"\n"
    "         \"and, where stage A's reading keeps UJ-ON from judging F3 for the life of the deploy (StaffJobsGate::applies false, \"\n"
    "         \"or the age limit not a positive number; followup_enabled off is FE-G1's, which stops), that in capitals before \"\n"
    "         \"DEPLOY is asked — a note, never a refusal (DL2; review 2: L8-1 point 1)\")\n")
rep("partUJ.py",
    "  [ \"$(kv \"$o\" ug)\" = \"1\" ] || note \"U4 STAFFJOBSGATE::APPLIES IS FALSE ON THIS BOOK'S SETTINGS — F1 TO F5 ARE INERT HERE: "
    "$EXPECTED_VERSION CHANGES NONE OF THIS SERVER'S FOLLOW-UPS\"\n",
    "  [ \"$(kv \"$o\" ug)\" = \"1\" ] || note \"U4 STAFFJOBSGATE::APPLIES IS FALSE ON THIS BOOK'S SETTINGS — F1 TO F5 ARE INERT HERE: "
    "$EXPECTED_VERSION CHANGES NONE OF THIS SERVER'S FOLLOW-UPS\"\n"
    "  [ \"$(kv \"$o\" en)\" = \"off\" ] || [ -z \"$(uj_gate_a \"$o\")\" ] || note \"U4 UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY:"
    "$(uj_gate_a \"$o\" | sed 's/;$//'). STAGE A'S READING IS KEPT IN THE STATE FILE FOR THE LIFE OF THE DEPLOY, SO F3 WOULD "
    "STAY UNPROVEN AND THE STEPS THAT WAIT FOR IT (6, 11, 12) WOULD FOLLOW WITHOUT THAT PROOF; AFTERWARDS A JUDGED UJ-ON "
    "NEEDS A ROLLBACK AND THEN A --redeploy. TO HAVE IT JUDGED, DO NOT DEPLOY ON THIS READING: PUT IT RIGHT"
    "$( [ -n \"$CHECK_ONLY\" ] || echo \", ANSWER ANYTHING BUT DEPLOY BELOW\"), THEN RUN THIS AGAIN\"\n")

# ── partH: FX1-2 — check it's else branch ends its rollback clause, then introduces the --after-only ──
rep("partH.py",
    "a judged UJ-ON needs a rollback, then a --redeploy whose stage A holds the gate:\"\\n'\n",
    "a judged UJ-ON needs a rollback, then a --redeploy whose stage A holds the gate. This --after-only still reads the "
    "closes, its own command, alone:\"\\n'\n")

# ── partH: FX1-3 — deploy again states the order: claim switches off first, THEN followup_enabled on ──
rep("partH.py",
    "            '  echo \"  deploy again      only once the cause of the rollback is understood, with every claim switch off (CS-G1 refuses\"\\n'\n"
    "            '  echo \"                    otherwise) and followup_enabled on (FE-G1 refuses otherwise) — with --redeploy, its own command,\"\\n'\n"
    "            '  echo \"                    alone; where this rollback set followup_auto_send off it stays off, and that deploy\\'s PASSED\"\\n'\n"
    "            '  echo \"                    --after-only prints your own command to turn it back on:\"\\n',\n"
    "            why=\"summary: deploy again\")\n",
    "            '  echo \"  deploy again      only once the cause of the rollback is understood. First every claim switch off (CS-G1\"\\n'\n"
    "            '  echo \"                    refuses otherwise), THEN followup_enabled on (FE-G1 refuses otherwise) — each its own\"\\n'\n"
    "            '  echo \"                    command, alone, never the reverse: under $BASELINE_VERSION, followup_enabled on while a\"\\n'\n"
    "            '  echo \"                    claim switch is on is root docs/75 §F\\'s hazard (claim mode closes the refused drafts\"\\n'\n"
    "            '  echo \"                    after three tries each, then sends the stale backlog). Then --redeploy, its own command,\"\\n'\n"
    "            '  echo \"                    alone; where this rollback set followup_auto_send off it stays off, and that deploy\\'s PASSED\"\\n'\n"
    "            '  echo \"                    --after-only prints your own command to turn it back on:\"\\n',\n"
    "            why=\"summary: deploy again\")\n"
    "    da = doc.t[doc.t.index('  echo \"  deploy again      '):]\n"
    "    da = da[:da.index('  echo \"      $REDEPLOY_CMD\"')]\n"
    "    if not (da.index(\"First every claim switch off\") < da.index(\"THEN followup_enabled on\") < da.index(\"never the reverse\")\n"
    "            < da.index(\"Then --redeploy\")) or \"set_config\" in da or \"sales-followup.sh\" in da:\n"
    "        raise SystemExit(\"PART H: deploy again does not say the order — claim switches off first, THEN followup_enabled on, \"\n"
    "                         \"never the reverse, then --redeploy — or prints a switch command (review 2: FX1-3)\")\n")

# ── partZ: the rules re-pinned, and their weakened copies ──
rep("partZ.py",
    "RB2_MARKS = ('$( case \"${AS_RB1:-}\" in written|written-check:*) echo \"nothing else moved but followup_auto_send: ',\n"
    "             \"and wrote one key before the put-back, followup_auto_send '0' (AS-RB1: ${AS_RB1_SAY:-?}; root docs/75 §L.7)\\\" ;; \",\n",
    "RB2_MARKS = ('$( case \"${AS_RB1:-}\" in written) echo \"nothing else moved but followup_auto_send: ',\n"
    "             \"and wrote one key before the put-back, followup_auto_send '0' (AS-RB1: ${AS_RB1_SAY:-?}; root docs/75 §L.7)\\\" ;; \",\n"
    "             \"written-check:*) echo \\\"the rollback ran no switch tool and no off-hook, applied or removed no migration, and \"\n"
    "             \"wrote followup_auto_send '0' before the put-back through the plugin's own writer — but its read-back did not \"\n"
    "             \"hold, so whether anything else moved is not shown\",\n")
rep("partZ.py",
    "     lambda t: _in_line(t, RB_NOTE, '$( case \"${AS_RB1:-}\" in written|', \" esac )\",\n",
    "     lambda t: _in_line(t, RB_NOTE, '$( case \"${AS_RB1:-}\" in written)', \" esac )\",\n")
rep("partZ.py",
    "G_NOW = ('  if [ -n \"$g\" ]; then g=\"$g$ga\"; note \"UJ-ON not judged — its gate did not hold:",
    "G_NOW = ('  if [ -n \"$g\" ]; then g=\"$g$ga$lim\"; note \"UJ-ON not judged — its gate did not hold:")
rep("partZ.py",
    "G_A = ('  if [ -n \"$ga\" ]; then note \"UJ-ON not judged for this deploy — its gate did not hold at stage A:${ga%;}. F3 may be '\n"
    "       'running now')\n",
    "G_A = ('  if [ -n \"$ga\" ]; then ga=\"$ga$lim\"; note \"UJ-ON not judged for this deploy — its gate did not hold at stage A:'\n"
    "       '${ga%;}. F3 may be running now')\n"
    "G_LIM = ('      || lim=\" the age limit differs — $(kv \"$a\" hours) h at stage A, $(kv \"$o\" hours) h now;\"\\n',\n"
    "         '  if [ -n \"$lim\" ]; then note \"UJ-ON not judged on this run — stage A\\'s gate held, but followup_max_age_hours is ')\n"
    "U4_WARN = ('  [ \"$(kv \"$o\" en)\" = \"off\" ] || [ -z \"$(uj_gate_a \"$o\")\" ] || note \"U4 UJ-ON WILL NOT JUDGE F3 FOR THIS '\n"
    "           'DEPLOY:$(uj_gate_a \"$o\" | sed \\'s/;$//\\'). STAGE A\\'S READING IS KEPT IN THE STATE FILE FOR THE LIFE OF THE DEPLOY')\n"
    "RB_LAST_LOOP = '  for f in \"$STATE\" \"$STATE\".prev-* \"$OUT\"/rollback-state-*.env; do\\n'\n"
    "BACK_V1 = \"  ! grep -q '^AS_RB1=' \\\"$STATE\\\" 2>/dev/null || return 0   # a V1 rollback of this deploy (review 2)\\n\"\n")
rep("partZ.py",
    "    \"Z20\": (G_NOW, G_A, JUDGEABLE, \"uj_gate_a() {\\n\") + G_CONDS,\n",
    "    \"Z20\": (G_NOW, G_A, JUDGEABLE, \"uj_gate_a() {\\n\") + G_CONDS + G_LIM,\n")
rep("partZ.py",
    "    \"Z25\": (RB_LAST, BR1, BACK_ON[5]),\n",
    "    \"Z25\": (RB_LAST, BR1, BACK_ON[5], RB_LAST_LOOP, BACK_V1),\n")
rep("partZ.py",
    "    \"Z27\": (CHECK_IT[0], WHAT_MEANS[0]),\n})\n",
    "    \"Z27\": (CHECK_IT[0], WHAT_MEANS[0], U4_WARN),\n})\n")
rep("partZ.py",
    "    if not _seq(uo, G_CONDS + (G_NOW, G_A, '  [ -n \"$cu\" ] || { bad \"UJ-ON FAIL — the copy')) \\\n"
    "            or t.count(\"F3 then has nothing to do\") != 1 or re.search(r'\\bg=\"\\$g[^\"$]*at stage A', uo):\n",
    "    if not _seq(uo, G_CONDS + (G_LIM[0], G_NOW, G_A, G_LIM[1], '  [ -n \"$cu\" ] || { bad \"UJ-ON FAIL — the copy')) \\\n"
    "            or t.count(\"F3 then has nothing to do\") != 1 or re.search(r'\\bg=\"\\$g[^\"$]*at stage A', uo) \\\n"
    "            or t.count(\"cannot judge F3 for this deploy\") != 1 or \"set back to stage A's\" in t:\n")
rep("partZ.py",
    "    if not _seq(bo, BACK_ON) or \"REDEPLOY\" in bo or not _body(t, \"as_rb_last\").endswith(RB_LAST):\n",
    "    if not _seq(bo, BACK_ON) or \"REDEPLOY\" in bo or not _body(t, \"as_rb_last\").endswith(RB_LAST) \\\n"
    "            or RB_LAST_LOOP not in _body(t, \"as_rb_last\") or not bo.startswith(\"  local o c r t\\n\" + BACK_ON[0] + BACK_V1):\n")
rep("partZ.py",
    "    if not _seq(ckt, CHECK_IT) or ckt.count(\"if uj_judgeable\") != 1 or len(wm) != 1 or not _seq(wm[0], WHAT_MEANS):\n",
    "    if not _seq(ckt, CHECK_IT) or ckt.count(\"if uj_judgeable\") != 1 or len(wm) != 1 or not _seq(wm[0], WHAT_MEANS) \\\n"
    "            or U4_WARN not in _body(t, \"uj_u4\"):\n")
rep("partZ.py",
    "     lambda t: _swap(t, '  if [ -n \"$g\" ]; then g=\"$g$ga\"; note \"UJ-ON not judged', '  if [ -n \"$ga\" ]; then note \"UJ-ON not judged')),\n",
    "     lambda t: _swap(t, '  if [ -n \"$g\" ]; then g=\"$g$ga$lim\"; note \"UJ-ON not judged',\n"
    "                     '  if [ -n \"$ga\" ]; then ga=\"$ga$lim\"; note \"UJ-ON not judged')),\n"
    "    (\"Z20\", \"the limit alone differing put back into the stage-A note\",\n"
    "     lambda t: _rep(t, '      || lim=\" the age limit differs', '      || ga=\"$ga the age limit differs')),\n"
    "    (\"Z20\", \"the limit-only note saying F3 cannot be judged for this deploy\",\n"
    "     lambda t: _rep(t, \"run --after-only again to have F3 judged\", \"UJ-ON cannot judge F3 for this deploy\")),\n")
rep("partZ.py",
    "    (\"Z25\", \"as_back_on gated on --redeploy again\",\n",
    "    (\"Z25\", \"as_rb_last blind to this deploy's own state file (a V1 rollback's record)\",\n"
    "     lambda t: _rep(t, RB_LAST_LOOP, '  for f in \"$STATE\".prev-* \"$OUT\"/rollback-state-*.env; do\\n')),\n"
    "    (\"Z25\", \"as_back_on offering after this deploy's own V1\", lambda t: _rep(t, BACK_V1, \"\")),\n"
    "    (\"Z25\", \"as_back_on gated on --redeploy again\",\n")
rep("partZ.py",
    "    (\"Z23\", \"the RB note's write unconditional\",\n",
    "    (\"Z23\", \"a read-back that did not hold said as nothing else moved\",\n"
    "     lambda t: _rep(t, '\"${AS_RB1:-}\" in written) echo \"nothing else moved but',\n"
    "                    '\"${AS_RB1:-}\" in written|written-check:*) echo \"nothing else moved but')),\n"
    "    (\"Z23\", \"the RB note's write unconditional\",\n")
rep("partZ.py",
    "    (\"Z27\", \"check it promising a judgement whatever stage A read\",\n",
    "    (\"Z27\", \"stage A silent before DEPLOY where UJ-ON will not judge F3\",\n"
    "     lambda t: _at(t, lambda l: l.startswith(U4_WARN), lambda l: [])),\n"
    "    (\"Z27\", \"check it promising a judgement whatever stage A read\",\n")
rep("partZ.py",
    "# plain line when it reads off now (RB-3, L8-3).\n",
    "# plain line when it reads off now (RB-3, L8-3).\n"
    "# 18 Nov, review 2 (rev106-2-findings): Z20 the limit alone differing is its own note, judged once set back, and only\n"
    "# the stage-A note says F3 cannot be judged for this deploy (UJ2-G3); Z23 a written-check never says nothing else\n"
    "# moved (RB2-wc); Z25 as_rb_last reads this deploy's own state file, and as_back_on offers nothing after this\n"
    "# deploy's own V1 (L84-V1); Z27 stage A says, in capitals before DEPLOY, where UJ-ON will not judge F3 (L8-1).\n")

for name, t in FILES.items():
    with open(os.path.join(D, name), "w", encoding="utf-8") as f:
        f.write(t)
    print(f"FIX2 wrote {name}")

"""Fix round 3 of deploy-5.18.106.sh: the re-check of round 2 (R2-V1OFF, Z20-RET, UJ2-G3-r, UJ-AO-CLOSES).

Usage: python3 -I -B fix3.py DIR   (DIR = wip/derive-5.18.106). Asserted edits, as fix2.py: every anchor found the
expected number of times, every replacement counted afterwards, the anchor gone; nothing written unless all hold.
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
        raise SystemExit(f"FIX3 {name}: anchor found {c} times, not {n}: {old[:100]!r}")
    before = t.count(new)
    t2 = t.replace(old, new)
    if t2.count(new) != before + n:
        raise SystemExit(f"FIX3 {name}: replacement not counted {n} more times: {new[:100]!r}")
    if old not in new and t2.count(old) != 0:
        raise SystemExit(f"FIX3 {name}: anchor still present after the edit: {old[:100]!r}")
    FILES[name] = t2


# ── partR: R2-V1OFF — as_back_on returns only on a V1 of this deploy that wrote, or may have; as_rb_last as before ──
rep("partR.py",
    r'''    p.append(cw("as_rb_last — the latest rollback record (root docs/75 §L.8): among this deploy's own state file (where "
                "a V1 rollback records its own; review 2), this folder's kept state-*.env.prev-* "
                "files and rollback-state-*.env, the one with the newest RB_COPY_AT (A14 reads them so); prints its AS_RB1 "
''',
    r'''    p.append(cw("as_rb_last — the latest rollback record (root docs/75 §L.8): among this folder's kept state-*.env.prev-* "
                "files and rollback-state-*.env — never this deploy's own state file: a V1 of this deploy that wrote "
                "followup_auto_send, or may have, stops as_back_on before it asks, and one that wrote nothing set nothing "
                "off, so it must not hide an earlier rollback's record (review 2) — the one with the newest RB_COPY_AT (A14 "
                "reads them so); prints its AS_RB1 "
''')
rep("partR.py",
    '  for f in "$STATE" "$STATE".prev-* "$OUT"/rollback-state-*.env; do\n',
    '  for f in "$STATE".prev-* "$OUT"/rollback-state-*.env; do\n')
rep("partR.py",
    r'''                "rollback whose copy came after this deploy's (one that left 5.18.106 serving), or a V1 rollback of this "
                "deploy (its AS_RB1 in this state file, its copy made or not; review 2), offers nothing here: its own "
''',
    r'''                "rollback whose copy came after this deploy's (one that left 5.18.106 serving), or a V1 rollback of this "
                "deploy that wrote followup_auto_send or may have (its AS_RB1 written, written-check or unknown in this "
                "state file, its copy made or not; review 2), offers nothing here: its own "
''')
rep("partR.py",
    '''  ! grep -q '^AS_RB1=' "$STATE" 2>/dev/null || return 0   # a V1 rollback of this deploy (review 2)\n''',
    '''  case "$(sed -n 's/^AS_RB1=//p' "$STATE" 2>/dev/null | tail -n1)" in written|written-check:*|unknown:*) return 0 ;; esac'''
    '''   # this deploy's V1 wrote it, or may have (review 2)\n''')
rep("partR.py",
    r'''                 ('  for f in "$STATE" "$STATE".prev-* "$OUT"/rollback-state-*.env; do\n', 1),
                 ("  ! grep -q '^AS_RB1=' \"$STATE\" 2>/dev/null || return 0   # a V1 rollback of this deploy", 1),
''',
    r'''                 ('  for f in "$STATE".prev-* "$OUT"/rollback-state-*.env; do\n', 1),
                 ('  for f in "$STATE" "$STATE".prev-* "$OUT"/rollback-state-*.env; do\n', 0), ("grep -q '^AS_RB1='", 0),
                 ("  case \"$(sed -n 's/^AS_RB1=//p' \"$STATE\" 2>/dev/null | tail -n1)\" in written|written-check:*|unknown:*) "
                  "return 0 ;; esac   # this deploy's V1 wrote it, or may have", 1),
''')

# ── partUJ: UJ2-G3-r — uj_on says, for the summary, which gate note it gave (now, limit) ──
rep("partUJ.py", "    + 'UJ_A=\"\"; UJ_NY_AT=\"\"; UJ_NY_SAY=\"\"\\n'\n",
    "    + 'UJ_A=\"\"; UJ_NY_AT=\"\"; UJ_NY_SAY=\"\"; UJ_ON_GATE=\"\"\\n'\n")
rep("partUJ.py",
    '  if [ -n "$g" ]; then g="$g$ga$lim"; note "UJ-ON not judged — its gate did not hold:',
    '  if [ -n "$g" ]; then g="$g$ga$lim"; UJ_ON_GATE="now"; note "UJ-ON not judged — its gate did not hold:')
rep("partUJ.py",
    '  if [ -n "$lim" ]; then note "UJ-ON not judged on this run',
    '  if [ -n "$lim" ]; then UJ_ON_GATE="limit"; note "UJ-ON not judged on this run')
rep("partUJ.py",
    '         "UJ-3) — for the summary.")\n',
    '         "UJ-3) — for the summary; the gate\'s note measured now, or on the limit alone, sets UJ_ON_GATE (now, limit), "\n'
    '         "so the summary\'s check it says when the next run judges F3 (review 2).")\n')
rep("partUJ.py",
    "                 (\"UJ-ON not judged on this run — stage A's gate held, but followup_max_age_hours is \", 1),\n",
    "                 (\"UJ-ON not judged on this run — stage A's gate held, but followup_max_age_hours is \", 1),\n"
    "                 ('UJ_ON_GATE=\"now\"; note \"UJ-ON not judged — its gate did not hold:', 1),\n"
    "                 ('UJ_ON_GATE=\"limit\"; note \"UJ-ON not judged on this run', 1), ('UJ_ON_GATE=\"\"\\n', 1),\n")

# ── partH: UJ2-G3-r — check it by UJ-ON's gate note; UJ-AO-CLOSES — the else branch says what the run reads ──
rep("partH.py",
    r'''        '  echo "                    again once a close pass has run since the copy (followup_close runs every 10 minutes) — UJ-ON judges F3 on it, and until one has it reads NOT YET (exit status 3) and says when to run it again:"\n'
''',
    r'''        '  echo "                    again once a close pass has run since the copy (followup_close runs every 10 minutes) — $( case "${UJ_ON_GATE:-}" in limit) echo "UJ-ON judges F3 on it only once followup_max_age_hours is back at stage A\'s $(kv "$UJ_A" hours) h and a close pass has run after that (UJ-ON above); until then it gives that note, no verdict, and the run reads PASSED without that proof" ;; now) echo "UJ-ON judges F3 on it only once its gate holds now (UJ-ON above names what did not); until then it gives that note, no verdict, and the run reads PASSED without that proof" ;; *) echo "UJ-ON judges F3 on it, and until one has it reads NOT YET (exit status 3) and says when to run it again" ;; esac ):"\n'
''')
rep("partH.py",
    r'''(followup_close runs every 10 minutes), to read the closes — but UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY:''',
    r'''(followup_close runs every 10 minutes), to read U1–U3 and the sender\'s closes and sends since the copy (UJ-CA) — a close pass\'s own closes are counted only by a judged UJ-ON — but UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY:''')
rep("partH.py",
    r'''a judged UJ-ON needs a rollback, then a --redeploy whose stage A holds the gate. This --after-only still reads the closes, its own command, alone:"\n'
''',
    r'''a judged UJ-ON needs a rollback, then a --redeploy whose stage A holds the gate. This --after-only reads those, its own command, alone:"\n'
''')

# ── partZ: the pins the re-check found missing (Z20-RET), and R2-V1OFF's and UJ2-G3-r's ──
rep("partZ.py",
    "G_NOW = ('  if [ -n \"$g\" ]; then g=\"$g$ga$lim\"; note \"UJ-ON not judged — its gate did not hold:",
    "G_NOW = ('  if [ -n \"$g\" ]; then g=\"$g$ga$lim\"; UJ_ON_GATE=\"now\"; note \"UJ-ON not judged — its gate did not hold:")
rep("partZ.py",
    "         '  if [ -n \"$lim\" ]; then note \"UJ-ON not judged on this run — stage A\\'s gate held, but followup_max_age_hours is ')\n",
    "         '  if [ -n \"$lim\" ]; then UJ_ON_GATE=\"limit\"; note \"UJ-ON not judged on this run — stage A\\'s gate held, but '\n"
    "         'followup_max_age_hours is ', ' and, once a close pass has run after that (followup_close runs every 10 minutes), '\n"
    "         'run --after-only again to have F3 judged')\n"
    "G_RET = 'a note, which holds back nothing\"; return 0; fi\\n'\n"
    "U4_TAIL = 'PUT IT RIGHT$( [ -n \"$CHECK_ONLY\" ] || echo \", ANSWER ANYTHING BUT DEPLOY BELOW\"), THEN RUN THIS AGAIN\"'\n"
    "GATE_SAY = ('$( case \"${UJ_ON_GATE:-}\" in limit) echo \"UJ-ON judges F3 on it only once followup_max_age_hours is back '\n"
    "            'at stage A')\n")
rep("partZ.py",
    "RB_LAST_LOOP = '  for f in \"$STATE\" \"$STATE\".prev-* \"$OUT\"/rollback-state-*.env; do\\n'\n"
    "BACK_V1 = \"  ! grep -q '^AS_RB1=' \\\"$STATE\\\" 2>/dev/null || return 0   # a V1 rollback of this deploy (review 2)\\n\"\n",
    "RB_LAST_LOOP = '  for f in \"$STATE\".prev-* \"$OUT\"/rollback-state-*.env; do\\n'\n"
    "BACK_V1 = ('  case \"$(sed -n \\'s/^AS_RB1=//p\\' \"$STATE\" 2>/dev/null | tail -n1)\" in written|written-check:*|unknown:*) '\n"
    "           'return 0 ;; esac   # this deploy\\'s V1 wrote it, or may have (review 2)\\n')\n")
rep("partZ.py",
    "    \"Z27\": (CHECK_IT[0], WHAT_MEANS[0], U4_WARN),\n",
    "    \"Z27\": (CHECK_IT[0], WHAT_MEANS[0], U4_WARN, U4_TAIL, GATE_SAY),\n")
rep("partZ.py",
    "    if not _seq(uo, G_CONDS + (G_LIM[0], G_NOW, G_A, G_LIM[1], '  [ -n \"$cu\" ] || { bad \"UJ-ON FAIL — the copy')) \\\n",
    "    if not _seq(uo, G_CONDS + (G_LIM[0], G_NOW, G_A, G_RET, G_LIM[1], G_LIM[2], G_RET,\n"
    "                               '  [ -n \"$cu\" ] || { bad \"UJ-ON FAIL — the copy')) \\\n")
rep("partZ.py",
    "            or U4_WARN not in _body(t, \"uj_u4\"):\n",
    "            or U4_WARN not in _body(t, \"uj_u4\") or U4_TAIL not in _body(t, \"uj_u4\") or GATE_SAY not in ckt:\n")
rep("partZ.py",
    "     lambda t: _swap(t, '  if [ -n \"$g\" ]; then g=\"$g$ga$lim\"; note \"UJ-ON not judged',\n",
    "     lambda t: _swap(t, '  if [ -n \"$g\" ]; then g=\"$g$ga$lim\"; UJ_ON_GATE=\"now\"; note \"UJ-ON not judged',\n")
rep("partZ.py",
    "    (\"Z20\", \"the limit-only note saying F3 cannot be judged for this deploy\",\n",
    "    (\"Z20\", \"the limit-only note falling through to the verdict\",\n"
    "     lambda t: _rep(t, 'without that proof; a note, which holds back nothing\"; return 0; fi\\n  [ -n \"$cu\" ]',\n"
    "                    'without that proof; a note, which holds back nothing\"; fi\\n  [ -n \"$cu\" ]')),\n"
    "    (\"Z20\", \"the stage-A note falling through\",\n"
    "     lambda t: _rep(t, 'its stage A taken with the gate holding; a note, which holds back nothing\"; return 0; fi\\n',\n"
    "                    'its stage A taken with the gate holding; a note, which holds back nothing\"; fi\\n')),\n"
    "    (\"Z20\", \"the limit-only note without its close pass\",\n"
    "     lambda t: _rep(t, ' and, once a close pass has run after that (followup_close runs every 10 minutes), run',\n"
    "                    ' and run')),\n"
    "    (\"Z20\", \"the limit-only note setting no UJ_ON_GATE\",\n"
    "     lambda t: _rep(t, 'then UJ_ON_GATE=\"limit\"; note', 'then note')),\n"
    "    (\"Z20\", \"the limit-only note saying F3 cannot be judged for this deploy\",\n")
rep("partZ.py",
    "    (\"Z25\", \"as_rb_last blind to this deploy's own state file (a V1 rollback's record)\",\n"
    "     lambda t: _rep(t, RB_LAST_LOOP, '  for f in \"$STATE\".prev-* \"$OUT\"/rollback-state-*.env; do\\n')),\n",
    "    (\"Z25\", \"as_rb_last reading this deploy's own state file\",\n"
    "     lambda t: _rep(t, RB_LAST_LOOP, '  for f in \"$STATE\" \"$STATE\".prev-* \"$OUT\"/rollback-state-*.env; do\\n')),\n"
    "    (\"Z25\", \"as_back_on returning on any AS_RB1 of this deploy's V1\",\n"
    "     lambda t: _rep(t, BACK_V1, \"  ! grep -q '^AS_RB1=' \\\"$STATE\\\" 2>/dev/null || return 0\\n\")),\n")
rep("partZ.py",
    "    (\"Z27\", \"stage A silent before DEPLOY where UJ-ON will not judge F3\",\n",
    "    (\"Z27\", \"U4's DEPLOY clause in --check too\",\n"
    "     lambda t: _rep(t, U4_TAIL, 'PUT IT RIGHT, ANSWER ANYTHING BUT DEPLOY BELOW, THEN RUN THIS AGAIN\"')),\n"
    "    (\"Z27\", \"check it promising a verdict whatever UJ-ON's gate note\",\n"
    "     lambda t: _rep(t, 'case \"${UJ_ON_GATE:-}\" in limit)', 'case \"\" in limit)')),\n"
    "    (\"Z27\", \"stage A silent before DEPLOY where UJ-ON will not judge F3\",\n")
rep("partZ.py",
    "# deploy's own V1 (L84-V1); Z27 stage A says, in capitals before DEPLOY, where UJ-ON will not judge F3 (L8-1).\n",
    "# deploy's own V1 (L84-V1); Z27 stage A says, in capitals before DEPLOY, where UJ-ON will not judge F3 (L8-1).\n"
    "# 18 Nov, the re-check of review 2: Z20 pins each gate note's return and the limit note's close pass (Z20-RET) and\n"
    "# UJ_ON_GATE; Z25 as_back_on returns only on a V1 of this deploy that wrote or may have, and as_rb_last reads only\n"
    "# the kept and rollback records (R2-V1OFF); Z27 U4's DEPLOY clause by the mode, and check it by UJ_ON_GATE (UJ2-G3-r).\n")

for name, t in FILES.items():
    with open(os.path.join(D, name), "w", encoding="utf-8") as f:
        f.write(t)
    print(f"FIX3 wrote {name}")

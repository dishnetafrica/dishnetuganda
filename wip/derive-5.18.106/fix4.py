"""Fix round 4 of deploy-5.18.106.sh: the re-check of round 3 (R3-NOW-CP + CHK-WAIT, R3-V1NW, R3-PIN-ARMS, CITE-LAO).

Usage: python3 -I -B fix4.py DIR   (DIR = wip/derive-5.18.106). Asserted edits, as fix2.py and fix3.py.
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
        raise SystemExit(f"FIX4 {name}: anchor found {c} times, not {n}: {old[:100]!r}")
    before = t.count(new)
    t2 = t.replace(old, new)
    if t2.count(new) != before + n:
        raise SystemExit(f"FIX4 {name}: replacement not counted {n} more times: {new[:100]!r}")
    if old not in new and t2.count(old) != 0:
        raise SystemExit(f"FIX4 {name}: anchor still present after the edit: {old[:100]!r}")
    FILES[name] = t2


WAIT_NOW = (" Once it holds again, run --after-only only after a close pass has run under it (followup_close runs every 10 "
            "minutes): a run before that judges F3 by passes made while it did not hold, and can read FAIL;")

# ── partUJ: R3-NOW-CP / CHK-WAIT — the 'now' note says to wait for a close pass once the gate holds again ──
rep("partUJ.py",
    'F3 then has nothing to do, or another limit to do it by; a note, which holds back nothing"; return 0; fi\n',
    'F3 then has nothing to do, or another limit to do it by.' + WAIT_NOW + ' a note, which holds back nothing"; return 0; fi\n')
rep("partUJ.py",
    "                 ('UJ_ON_GATE=\"now\"; note \"UJ-ON not judged — its gate did not hold:', 1),\n",
    "                 ('UJ_ON_GATE=\"now\"; note \"UJ-ON not judged — its gate did not hold:', 1),\n"
    "                 (\"" + WAIT_NOW + "\", 1),\n")

# ── partH: check it's two gate arms say to run only after a close pass; the citations name §L.8 and DL3 ──
rep("partH.py",
    'and a close pass has run after that (UJ-ON above); until then it gives that note, no verdict, and the run reads PASSED '
    'without that proof" ;; now) echo "UJ-ON judges F3 on it only once its gate holds now (UJ-ON above names what did not); '
    'until then it gives that note, no verdict, and the run reads PASSED without that proof" ;;',
    'and a close pass has run after that (UJ-ON above) — run it only then: a run before that pass judges F3 by passes made '
    'under the other limit, and can read FAIL; while the limit differs it gives that note, no verdict, and the run reads '
    'PASSED without that proof" ;; now) echo "UJ-ON judges F3 on it only once its gate holds now (UJ-ON above names what did '
    'not) and a close pass has run after that (followup_close runs every 10 minutes) — run it only then: a run before that '
    'pass judges F3 by passes made while the gate did not hold, and can read FAIL; while it does not hold it gives that note, '
    'no verdict, and the run reads PASSED without that proof" ;;')
rep("partH.py", "may be on once its --after-only has PASSED (root docs/75 §L.3, §F):",
    "may be on once its --after-only has PASSED (root docs/75 §L.3, §L.8, DL3, §F):")
rep("partH.py", "may be on once its --after-only has PASSED; root docs/75 §L.3)",
    "may be on once its --after-only has PASSED; root docs/75 §L.3, §L.8, DL3)")
rep("partH.py",
    '        doc.must(s, n, why="partH, the review of 18 Nov and DL11")\n',
    '        doc.must(s, n, why="partH, the review of 18 Nov and DL11")\n'
    "    for s in ('limit) echo \"UJ-ON judges F3 on it only once followup_max_age_hours is back at stage A',\n"
    "              ' — run it only then: a run before that pass judges F3 by passes made under the other limit',\n"
    "              'now) echo \"UJ-ON judges F3 on it only once its gate holds now (UJ-ON above names what did not) and a close '\n"
    "              'pass has run after that', 'by passes made while the gate did not hold, and can read FAIL',\n"
    "              \"to read U1–U3 and the sender's closes and sends since the copy (UJ-CA)\",\n"
    "              '(root docs/75 §L.3, §L.8, DL3, §F)', '; root docs/75 §L.3, §L.8, DL3)'):\n"
    "        doc.must(s, 1, why=\"partH, check it by UJ-ON's gate note, and the citations (re-check of round 3)\")\n")

# ── partR: R3-V1NW — as_back_on also returns when this deploy's V1 read followup_auto_send on ──
rep("partR.py",
    '''  case "$(sed -n 's/^AS_RB1=//p' "$STATE" 2>/dev/null | tail -n1)" in written|written-check:*|unknown:*) return 0 ;; esac'''
    '''   # this deploy's V1 wrote it, or may have (review 2)\n''',
    '''  case "$(sed -n 's/^AS_RB1=//p' "$STATE" 2>/dev/null | tail -n1)" in ''|off|not-written:owner-unknown|'''
    '''not-written:unread) ;; *) return 0 ;; esac   # this deploy's V1 wrote it, may have, or read it on (review 2)\n''')
rep("partR.py",
    r'''                 ("  case \"$(sed -n 's/^AS_RB1=//p' \"$STATE\" 2>/dev/null | tail -n1)\" in written|written-check:*|unknown:*) "
                  "return 0 ;; esac   # this deploy's V1 wrote it, or may have", 1),
''',
    r'''                 ("  case \"$(sed -n 's/^AS_RB1=//p' \"$STATE\" 2>/dev/null | tail -n1)\" in ''|off|not-written:owner-unknown|"
                  "not-written:unread) ;; *) return 0 ;; esac   # this deploy's V1 wrote it, may have, or read it on", 1),
''')
rep("partR.py",
    r'''                "followup_auto_send, or may have, stops as_back_on before it asks, and one that wrote nothing set nothing "
                "off, so it must not hide an earlier rollback's record (review 2) — the one with the newest RB_COPY_AT (A14 "
''',
    r'''                "followup_auto_send, may have, or read it on stops as_back_on before it asks, and one that read it off "
                "or could not read it set nothing off, so it must not hide an earlier rollback's record (review 2) — the "
                "one with the newest RB_COPY_AT (A14 "
''')
rep("partR.py",
    r'''                "deploy that wrote followup_auto_send or may have (its AS_RB1 written, written-check or unknown in this "
                "state file, its copy made or not; review 2), offers nothing here: its own "
''',
    r'''                "deploy that wrote followup_auto_send, may have, or read it on (any AS_RB1 in this state file but off, "
                "not-written:owner-unknown or not-written:unread, its copy made or not; review 2), offers nothing here — "
                "a key it read on was not the earlier rollback's doing: its own "
''')

# ── partZ: the pins (R3-PIN-ARMS), and those the other three findings move ──
rep("partZ.py",
    "         'to do, or another limit to do it by; a note, which holds back nothing\"; return 0; fi\\n')\n",
    "         'to do, or another limit to do it by.' + WAIT_NOW + ' a note, which holds back nothing\"; return 0; fi\\n')\n")
rep("partZ.py",
    "G_NOW = ('  if [ -n \"$g\" ]",
    "WAIT_NOW = (\" Once it holds again, run --after-only only after a close pass has run under it (followup_close runs every 10 \"\n"
    "            \"minutes): a run before that judges F3 by passes made while it did not hold, and can read FAIL;\")\n"
    "G_NOW = ('  if [ -n \"$g\" ]")
rep("partZ.py",
    "GATE_SAY = ('$( case \"${UJ_ON_GATE:-}\" in limit) echo \"UJ-ON judges F3 on it only once followup_max_age_hours is back '\n"
    "            'at stage A')\n",
    "GATE_LIMIT = ('limit) echo \"UJ-ON judges F3 on it only once followup_max_age_hours is back at stage A\\'s $(kv \"$UJ_A\" hours) h '\n"
    "              'and a close pass has run after that (UJ-ON above) — run it only then: a run before that pass judges F3 by '\n"
    "              'passes made under the other limit, and can read FAIL; while the limit differs it gives that note, no verdict, '\n"
    "              'and the run reads PASSED without that proof\"')\n"
    "GATE_NOW = ('now) echo \"UJ-ON judges F3 on it only once its gate holds now (UJ-ON above names what did not) and a close '\n"
    "            'pass has run after that (followup_close runs every 10 minutes) — run it only then: a run before that pass '\n"
    "            'judges F3 by passes made while the gate did not hold, and can read FAIL; while it does not hold it gives that '\n"
    "            'note, no verdict, and the run reads PASSED without that proof\"')\n"
    "GATE_DEFAULT = '*) echo \"UJ-ON judges F3 on it, and until one has it reads NOT YET (exit status 3) and says when to run it again\"'\n"
    "GATE_SAY = '$( case \"${UJ_ON_GATE:-}\" in ' + GATE_LIMIT + ' ;; ' + GATE_NOW + ' ;; ' + GATE_DEFAULT + ' ;; esac ):'\n"
    "ELSE_READS = (\"to read U1–U3 and the sender's closes and sends since the copy (UJ-CA) — a close pass's own closes are \"\n"
    "              \"counted only by a judged UJ-ON — but UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY\")\n")
rep("partZ.py",
    "BACK_V1 = ('  case \"$(sed -n \\'s/^AS_RB1=//p\\' \"$STATE\" 2>/dev/null | tail -n1)\" in written|written-check:*|unknown:*) '\n"
    "           'return 0 ;; esac   # this deploy\\'s V1 wrote it, or may have (review 2)\\n')\n",
    "BACK_V1 = ('  case \"$(sed -n \\'s/^AS_RB1=//p\\' \"$STATE\" 2>/dev/null | tail -n1)\" in \\'\\'|off|not-written:owner-unknown|'\n"
    "           'not-written:unread) ;; *) return 0 ;; esac   # this deploy\\'s V1 wrote it, may have, or read it on (review 2)\\n')\n")
rep("partZ.py",
    "    \"Z27\": (CHECK_IT[0], WHAT_MEANS[0], U4_WARN, U4_TAIL, GATE_SAY),\n",
    "    \"Z27\": (CHECK_IT[0], WHAT_MEANS[0], U4_WARN, U4_TAIL, GATE_SAY, ELSE_READS),\n")
rep("partZ.py",
    "or U4_TAIL not in _body(t, \"uj_u4\") or GATE_SAY not in ckt:\n",
    "or U4_TAIL not in _body(t, \"uj_u4\") or GATE_SAY not in ckt \\\n"
    "            or ELSE_READS not in ckt:\n")
rep("partZ.py",
    "    (\"Z20\", \"the limit-only note falling through to the verdict\",\n",
    "    (\"Z20\", \"the 'now' note without its close pass\", lambda t: _rep(t, WAIT_NOW, \";\")),\n"
    "    (\"Z20\", \"the limit-only note falling through to the verdict\",\n")
rep("partZ.py",
    "    (\"Z25\", \"as_back_on returning on any AS_RB1 of this deploy's V1\",\n",
    "    (\"Z25\", \"as_back_on letting a V1 that read it on through\",\n"
    "     lambda t: _rep(t, BACK_V1, '  case \"$(sed -n \\'s/^AS_RB1=//p\\' \"$STATE\" 2>/dev/null | tail -n1)\" in '\n"
    "                    'written|written-check:*|unknown:*) return 0 ;; esac\\n')),\n"
    "    (\"Z25\", \"as_back_on returning on any AS_RB1 of this deploy's V1\",\n")
rep("partZ.py",
    "    (\"Z27\", \"U4's DEPLOY clause in --check too\",\n",
    "    (\"Z27\", \"check it without its 'now' arm\", lambda t: _rep(t, \" ;; \" + GATE_NOW, \"\")),\n"
    "    (\"Z27\", \"check it's limit arm promising NOT YET again\",\n"
    "     lambda t: _rep(t, GATE_LIMIT, 'limit) echo \"UJ-ON judges F3 on it, and until one has it reads NOT YET (exit status 3) '\n"
    "                    'and says when to run it again\"')),\n"
    "    (\"Z27\", \"check it's 'now' arm without its close pass\",\n"
    "     lambda t: _rep(t, ' and a close pass has run after that (followup_close runs every 10 minutes) — run it only then',\n"
    "                    ' — run it only then')),\n"
    "    (\"Z27\", \"check it's else branch saying it reads the closes\",\n"
    "     lambda t: _rep(t, ELSE_READS, 'to read the closes — but UJ-ON WILL NOT JUDGE F3 FOR THIS DEPLOY')),\n"
    "    (\"Z27\", \"U4's DEPLOY clause in --check too\",\n")
rep("partZ.py",
    "# the kept and rollback records (R2-V1OFF); Z27 U4's DEPLOY clause by the mode, and check it by UJ_ON_GATE (UJ2-G3-r).\n",
    "# the kept and rollback records (R2-V1OFF); Z27 U4's DEPLOY clause by the mode, and check it by UJ_ON_GATE (UJ2-G3-r).\n"
    "# 18 Nov, the re-check of round 3: Z20 the 'now' note says to wait for a close pass once the gate holds again\n"
    "# (R3-NOW-CP); Z25 as_back_on returns too on a V1 that read it on (R3-V1NW); Z27 pins check it's three arms and its\n"
    "# else text whole (R3-PIN-ARMS, CHK-WAIT).\n")

for name, t in FILES.items():
    with open(os.path.join(D, name), "w", encoding="utf-8") as f:
        f.write(t)
    print(f"FIX4 wrote {name}")

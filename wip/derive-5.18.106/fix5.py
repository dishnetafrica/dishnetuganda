"""Fix round 5 of deploy-5.18.106.sh: R4-NOW-STAGEA — the 'now' note promises a judgement only when stage A's gate held.

Usage: python3 -I -B fix5.py DIR   (DIR = wip/derive-5.18.106). Asserted edits, as fix2.py–fix4.py.
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
        raise SystemExit(f"FIX5 {name}: anchor found {c} times, not {n}: {old[:100]!r}")
    before = t.count(new)
    t2 = t.replace(old, new)
    if t2.count(new) != before + n:
        raise SystemExit(f"FIX5 {name}: replacement not counted {n} more times: {new[:100]!r}")
    if old not in new and t2.count(old) != 0:
        raise SystemExit(f"FIX5 {name}: anchor still present after the edit: {old[:100]!r}")
    FILES[name] = t2


WAIT_NOW = (" Once it holds again, run --after-only only after a close pass has run under it (followup_close runs every 10 "
            "minutes): a run before that judges F3 by passes made while it did not hold, and can read FAIL;")
STAGEA_TOO = (" Stage A's gate did not hold either, so UJ-ON will not judge F3 for this deploy whatever is put right now (a "
              "judged UJ-ON needs a rollback and then a --redeploy);")

# partUJ: the wait only where stage A's gate held (ga empty); otherwise say this deploy will not be judged
rep("partUJ.py",
    'F3 then has nothing to do, or another limit to do it by.' + WAIT_NOW + ' a note, which holds back nothing"; return 0; fi\n',
    'F3 then has nothing to do, or another limit to do it by.$( [ -z "$ga" ] && echo "' + WAIT_NOW + '" || echo "'
    + STAGEA_TOO + '") a note, which holds back nothing"; return 0; fi\n')
rep("partUJ.py",
    "                 (\"" + WAIT_NOW + "\", 1),\n",
    "                 (\"" + WAIT_NOW + "\", 1), (\"" + STAGEA_TOO + "\", 1),\n")

# partZ: G_NOW re-pinned, and a weakened copy that promises the wait whatever stage A read
rep("partZ.py",
    "         'to do, or another limit to do it by.' + WAIT_NOW + ' a note, which holds back nothing\"; return 0; fi\\n')\n",
    "         'to do, or another limit to do it by.$( [ -z \"$ga\" ] && echo \"' + WAIT_NOW + '\" || echo \"' + STAGEA_TOO + '\") a note, '\n"
    "         'which holds back nothing\"; return 0; fi\\n')\n")
rep("partZ.py",
    "G_NOW = ('  if [ -n \"$g\" ]",
    "STAGEA_TOO = (\" Stage A's gate did not hold either, so UJ-ON will not judge F3 for this deploy whatever is put right now (a \"\n"
    "              \"judged UJ-ON needs a rollback and then a --redeploy);\")\n"
    "G_NOW = ('  if [ -n \"$g\" ]")
rep("partZ.py",
    "    (\"Z20\", \"the 'now' note without its close pass\", lambda t: _rep(t, WAIT_NOW, \";\")),\n",
    "    (\"Z20\", \"the 'now' note without its close pass\", lambda t: _rep(t, WAIT_NOW, \";\")),\n"
    "    (\"Z20\", \"the 'now' note promising the wait whatever stage A read\",\n"
    "     lambda t: _rep(t, '$( [ -z \"$ga\" ] && echo \"' + WAIT_NOW, '$( true && echo \"' + WAIT_NOW)),\n")
rep("partZ.py",
    "# else text whole (R3-PIN-ARMS, CHK-WAIT).\n",
    "# else text whole (R3-PIN-ARMS, CHK-WAIT). The re-check of round 4: Z20 the 'now' note's wait only where stage A's gate\n"
    "# held; otherwise that this deploy will not be judged (R4-NOW-STAGEA).\n")

for name, t in FILES.items():
    with open(os.path.join(D, name), "w", encoding="utf-8") as f:
        f.write(t)
    print(f"FIX5 wrote {name}")

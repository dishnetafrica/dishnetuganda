#!/usr/bin/env python3
# derive103.py — scripts/deploy-5.18.103.sh from scripts/deploy-5.18.102.sh by asserted edits (root docs/72 §G.13).
# Usage: python3 -I derive103.py REPO RELEASE_REPO PIN_FULL [OUT]
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from d103lib import Doc
import partA
repo, rel, pin = sys.argv[1], sys.argv[2], sys.argv[3]
out = sys.argv[4] if len(sys.argv) > 4 else os.path.join(repo, "scripts/deploy-5.18.103.sh")
src = os.path.join(repo, "scripts/deploy-5.18.102.sh")
doc = Doc(open(src, encoding="utf-8").read())
ctx = {"repo": rel, "plugin": "dishnet-hybrid-sudan", "base_full": "1a0ea5f103f237db0c7c103c9efd8d38408fbac9", "pin_full": pin}
parts = [partA]
for extra in ("partB", "partC", "partE", "partF", "partD", "partH1", "partH2", "partH3", "partH4", "partG", "partI"):
    parts.append(__import__(extra))
for p in parts:
    p.apply(doc, ctx)
    print(f"  {p.__name__}: {len(doc.log)} edits so far")
open(out, "w", encoding="utf-8").write(doc.t)
os.chmod(out, 0o755)
print(f"wrote {out}: {doc.t.count(chr(10))} lines; added {ctx['n_added']}, changed {ctx['n_changed']}, kept {ctx['n_kept']}")

# fix-r3.py — the expected-text review (wqqznhutm, 18 Nov): 130 fragments not literal in the script, every one MATCH; one
# other finding, confirmed by a skeptic and re-read here (S:9049-9057, S:12895, S:14143-14150, AIS_PHP S:1879-1893):
# AC3's AS-RB1 mirrors the whole file into the store row, so the row's evo_api_url changes and V3g — inside AS-RB1 and
# again at the end of the run (S6) — FAILs as well: fails() reads 3, not 1. Asserted: anchor once, result holds it.
import sys
p = "scripts/harness/deploy-5.18.106/rehearse.sh"
t = open(p, encoding="utf-8").read()
E = [
 ('''      "no|1|1|1|1|written-check:row-others|k:off,s:off,p:-,d:-|ok|$B106|removed" \\''',
  '''      "no|1|1|3|1|written-check:row-others|k:off,s:off,p:-,d:-|ok|$B106|removed" \\'''),
 ('''# deploy-5.18.106.sh:8927 (the five conditions read holding), :9034 (AS-RB1's read-back FAIL), :1683
check "$(has "$OAC3_106" 'THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (condition 3')''',
  '''# deploy-5.18.106.sh:8927 (the five conditions read holding), :9034 (AS-RB1's read-back FAIL), :1683; three FAIL lines in
# all (review r3): AS-RB1's read-back (:9034), AS-RB1's own V3g compare across the write (:9057: store.evo_api_url, the row
# mirrored from the file), and V3g S6 at the end of the run (:14147, against AIS_A read at stage A, :12895)
check "$(has "$OAC3_106" 'THE ROLLBACK CANNOT SET followup_auto_send OFF ITSELF (condition 3')'''),
 ('''its own read-back FAILs, row-others (S:9034), record written-check:row-others: what condition 3 is there to prevent''',
  '''its own read-back FAILs, row-others (S:9034), and V3g FAILs twice on the row's evo_api_url (S:9057, S:14147) — three FAIL lines; record written-check:row-others: what condition 3 is there to prevent'''),
]
for old, new in E:
    if t.count(old) != 1: sys.exit(f"anchor found {t.count(old)} times: {old[:90]!r}")
    t = t.replace(old, new, 1)
    if t.count(new) != 1: sys.exit("result check failed")
open(p, "w", encoding="utf-8").write(t)
for q in ("wip/rehearsal-5.18.106/rehearse.assembled.sh",):
    u = open(q, encoding="utf-8").read(); n = 0
    for old, new in E:
        if u.count(old) == 1: u = u.replace(old, new, 1); n += 1
    open(q, "w", encoding="utf-8").write(u); print(q, n)
print(p, "3 edits applied")

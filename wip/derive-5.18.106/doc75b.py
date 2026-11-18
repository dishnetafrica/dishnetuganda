# doc75b.py — docs/75 §L.10: the rehearsal design's refinements (18 Nov). Asserted: anchor once, inserted once, result holds it.
import sys
p = "docs/75-uganda-enquiry-sender-unjam-5-18-106-design-2026-11-15.md"
t = open(p).read()
anchor = "\n\nEverything seeded is fictitious, from the rehearsal's own number range.\n"
if t.count(anchor) != 1: sys.exit(f"anchor found {t.count(anchor)} times, not once")
add = """
- *(18 Nov, the rehearsal's design — measured against the script at `745e668` and the release at `bf29cbd`)* Five
  refinements of the cases above, and one limit:
  - **The gone rows are 400, not 200.** One close pass cancels gone rows in its main loop and `expireStale` expires the
    present row in the same pass, so 200 gone rows beside one present stale row would read PASS at once. With 400 gone
    rows, all older than the present row, both 200-place lists are spent on gone rows: the first pass reads NOT YET (200
    or more cancelled, none expired), and the next reads PASS.
  - **F3's weakened copy is judged after the gone rows are cleared.** While 200 or more remain, the main loop alone gives
    NOT YET. The copy is judged on UJ-ON's own FAIL line (F3 did not expire, with the stale count), beside R1's FAIL
    naming `cron/followup_close.php`.
  - **A `kyc_config.json` that does not decode reads `followup_auto_send` off by itself.** The rollback then gives
    AS-RB0's plain note naming condition 1, writes nothing, prints no command, and leaves the file byte for byte
    unchanged. The capitals text *DOES NOT DECODE … NO COMMAND IS PRINTED* needs the key on from a `config.json`. That is
    a second sub-case, planted by a fixture and said so.
  - **"A V1 whose put-back failed" is made by failing the put-back before its copy.** A planted `.git/index.lock` makes the
    baseline's `git checkout` fail, so 5.18.106 keeps serving and its later `--after-only` can PASS. It is not 5.18.104's
    `.rbfail104`, which copies and then misreads the record.
  - **K5's eight weakened copies are the derivation's.** `derive106.py` runs them on every derivation. The rehearsal does
    not repeat them, and says so.
  - **A known limit, recorded, not built.** Suppose V1's put-back succeeded and it found the key off after a typed
    rollback that had written it. V1's record is then the latest one, once rotated, and a later redeploy's
    `--after-only` offers nothing. This is §L.8's base rule. The command to turn the key back on stays the owner's
    `set_config`."""
t2 = t.replace(anchor, add + anchor, 1)
if t2.count("Five\n  refinements of the cases above, and one limit:") != 1: sys.exit("the inserted section is not in the result once")
if t2.count(anchor) != 1 or len(t2) - len(t) != len(add): sys.exit("the result is not the anchor plus the section")
open(p, "w").write(t2); print("docs/75 §L.10: inserted", add.count("\n"), "lines")

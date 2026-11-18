1. **Part 1's seed106 cannot run Part 2's helper on its own** (rehearse.part1.sh lines 454–464).
   - It never sets RH106_RANGE, and rh106-seed.php refuses with exit 2 without it.
   - Its comment calls the first argument MODE, but Part 2's argv is <code root> <data dir> <ops JSON> [zone]. As a "$@" pass-through it works.
   - Part 3's plant106 works around it: it gives RH106_RANGE as a temporary assignment on the seed106 call (bash passes that to the child) and calls it with Part 2's argv. Fix the comment, or set RH106_RANGE inside seed106.
   - Part 1 also has no root runner for the file fixtures. Part 3 defines seed106r, the name Part 2 suggested. Part 4 must use it for fx_state_drop and fx_state_back, not define its own.
2. **One reorder in 106-1.** CS-G1's off command is run as printed (106-1b) before the step-2 variants. A store-only chase@s (design-v2 106-1 step 2) cannot be planted while the store row holds quote_chase_enabled: fx_store_keys refuses a key the row already holds. followup_enabled stays off throughout the variants, as the design requires. 106-1a still shows the k,s refusal.
3. **CS1's expected outcome differs from the design.** The design says CS1 is caught "because GO or LK-B1 prints instead". That cannot hold in 106-1: followup_enabled is off (the interim), so the copy's --check stops at the next gate, FE-G1. CS1 is caught by CS-G1's text being absent and FE-G1's refusal present, on both the k,s state and the s-only state.
4. **5.18.105's --after-only (106-pre1b) runs without aft105.** aft105 calls copy_back105 and set_sched, which A1 and A2 forbid.
   - PASSED is inferred, not run. R19's not-yet-conclusive is a note (deploy-5.18.105.sh:13761-13764). LM-ON's FAIL counts only lines stamped 60 s or more after COPY_AT, so a later COPY_AT can only count fewer (:7455-7463).
   - R5's own aftx104 calls in pre-d ran on master104's quiet schedule plants without after_back.
   - If the dev trial shows a FAIL, the design must choose. Do not quietly use aft105.
5. **The chain's count is assumed.** 106-pre4b requires at least one chain approved enquiry draft. The review read one (followup 1, 9 Nov) in snap1. After pass 1 it requires that only J1's three remain approved. A chain draft held without closing (e.g. a paused number) would fail that check visibly. Whether 105's sections added approved drafts is not established.
6. **J1's refusals rest on two readings, not on a run.**
   - That 7835906's request() turns the stand-in's JSON 400 into "Bad Request [HTTP 400 on POST /message/sendText/<instance>]" (rel105 lib/EvolutionApiService.php:898-904).
   - That normalisePhone sends digits the router's list matches.
   - The 5.18.105 sender's pass-1 summary "sent+held = C106, failed=3" also assumes the chain draft is sent or closed in that pass.
7. **The design's "stale refused set" is one follow-up (S).** At stage A, Part 4's U1 should read f2:1.
8. **Exact classes are asserted at 106-1k1** (held:1,f5:0,closes:0,f2:1,f1:2,waits:1, places=5) from Part 2's u1mirror, which has not been run. If the mirror and the code disagree, Part 4's U1 comparison will show it too.
9. **The refusal list is not undone in this part.** It is a stand-in setting, not a book fixture, and 106-6's sender pass needs it. cleanup106 removes it at exit. Part 5 or Part 6 should assert it gone (A9).
10. **QL key files are left in place.** The declined deploy (106-1i3) and the two held deploys (106-1j) may write ql-key-* files, which tidy106 does not move (tidy105 did not either). The next deploy keeps the earlier one beside it before writing, which is harmless.
11. **The order checks need the typed answer on the prompt line.** They rely on `script` echoing the answer after "  Type DEPLOY to deploy …", as R5's runs show.
12. **The FE-G1 command check assumes ucrm.json has no pluginDataDir.** The script's PDD_IN must then fall back to /data/ucrm/data/plugins/.$P-data, which equals PDD104_IN. The quarantine rehearsal restores ucrm.json after each change.
13. **Nothing was executed:** no PHP, no rehearsal. The checks were bash -n on Part 1 + Part 3 piped (clean), an ast parse of the six embedded Python blocks, and in-memory checks that:
    - the anchors cs1, fe1, u41 and csb each occur exactly once in deploy-5.18.106.sh at 745e668;
    - each weakened copy meets weak106's replacement assertion and passes bash -n;
    - order106, rwdk106 and fsl106 parse sample text as intended.
    The first dev trial from devsnap106 is the real test.
14. **One minor rule slip.** I created and at once removed an empty directory in the scratchpad (rh106/p3chk) before remembering the task is read-only. No content was written.
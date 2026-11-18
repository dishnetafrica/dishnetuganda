1. A1 is gated by RH106_TOUCH, which only 106's runners set (run106, run_line106c). Design A1 says "from the first line of 106's own sections". The gate keeps 106-pre's 5.18.105 runs exactly as R5 ran them.
   - Consequence for Parts 3–5: once COPY_AT and RB_COPY_AT are clock-based, A14 of any later 106 deploy needs a quarantine --check begun after every 106 copy.
   - Before, the wall-clock COPY_AT made A14's order trivially true.
   - rbx106 already runs qcheck106. Between two 106 deploys with no rollback between them (for example 7e's --redeploy, then the V1 deploy), call qcheck106.
2. A7's control runs while 994d660 (5.18.104) serves, because infra precedes 106-pre's 5.18.105 redeploy. Design A7 says "while 7835906 serves"; the control is equally discriminating, since the manifest is not 5.18.106.
   - The firing half is Part 5's: the 302, both triggers spent, index.lock present, "git checkout 7835906 failed — nothing was deployed", then unlock106.
   - .redirect106lock is spent with .redirect106 (one-shot). The index.lock is not spent.
3. A6's control "one pass's failed-event detail matches F1's LIKE" needs a real sender pass, so it belongs to Part 3 (J1). Infra checks the HTTP answer's shape. By 7835906's requestUg (checked by reading it), that answer gives detail "Bad Request [HTTP 400 on POST /message/sendText/<instance>]".
4. The uj hook is not one-shot. While $SB/.ujfail106 exists it refuses every QF_PHP uj call: stage A's U1–U4, UJ-CA and UJ-ON alike. Parts 3 and 4 must arm it just before the run and remove it just after.
5. 106-0 and 106-cut use comment banners, as R5's guards do, because they print only on refusal. The first echo heading is 106-infra.
6. The verbs contract keeps three plants. No set_config plant was added, since that would change rh106q.py's output contract. 106 runs set_config only on Q-CA6's throwaway folder.
7. Helpers added beyond design B's list, so that Parts 3–5 share one implementation; drop any that ends up unused:
   - nogo106/checkgo106/quiet106, computed as deltas from the run's .pre record, because state files persist after the first 106 deploy;
   - seed106 and setcfg106;
   - keep106/back106 and mark106/cut106 (A9);
   - anc106, weak106 and ancc106;
   - refuse106, refused106 and evopost106;
   - arm106, lock106 and unlock106;
   - ctl106, copyok106, pig106 and sget106.
8. The free-range proof greps the checkout's whole $P/tests, not only tests/fixtures. It also greps $RECS and the sandbox data, with digit-boundary regexes.
   - All 100 numbers go into NAMES104 and FORMS106, so every number Parts 3–5 pick is swept.
   - FORMS105 is untouched; Part 6 should scan with FORMS106.
9. NM106F holds the bare marker RH106SECRET-, so any RH106SECRET- text in a swept file counts. Plants must not leak texts into the script's terminals or logs.
   - setcfg106's output goes to $R106/setcfg.txt, which no sweep reads.
   - Note that set_config output for a planted quote_company_phone holds a range number. Keep it out of the swept files.
10. aft106's FAILED verdict does not fix the UJ-ON wait-line count, because ME-CA1 can FAIL while UJ-ON reads NOT YET. The caller asserts UJ-ON's own line from $AFT106.
11. sender106 accepts a followup_close that was skipped by its interval. It asserts only that the close was never planted after a 106 copy. Before a 106 copy, master104 still records followup_close as quietly run; that entry is earlier than any later clock-based COPY_AT.
12. Not established here; each is asserted at runtime and fails visibly otherwise:
    - that the clock offset at 106's start is at least 3600 s (check g);
    - that $H106_ROOT/$P/tests exists in the record worktree (check e reads notests otherwise);
    - that the chain's range appears in $DATA (check e's control);
    - that `trap -p EXIT` inside $( ) and inside a pipeline returns the parent's trap. This was verified in a bash here, and devres105 relies on the same.
13. rbx106 expects a new rollback-state-*.env from every typed rollback. A case expected to refuse or stop must use run106c directly.
14. If $SB/datebin/touch already exists, infra overwrites it and records datebin:touch106:present.
15. NO PHP WAS RUN. The 106-infra checks have not been executed; they need a dev trial from a devsnap106 snapshot.
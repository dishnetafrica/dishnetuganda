Part 6 of scripts/harness/deploy-5.18.106/rehearse.sh: section 106-10 and the tally. 158 lines. bash -n is clean on Part 6 alone and on Parts 1+3+4 (with the 15 fixes applied in memory) + Part 6, all piped. Its one Python block parses with ast, and its assertions were dry-read against R4 (the anchor once; sweep_files104, sheets104 and reqs104 each once after it, each one line ending '; }').

TAKES. 106-10a0 asserts, through Part 4's dpre106, the state design-v2 says 106-7i leaves:
- P106 (bf29cbd) serving after the last --redeploy;
- followup_auto_send off;
- no claim switch;
- followup_enabled on at LIM106;
- rblast "off:nothing:not-written": V1's AS_RB1=off is the latest record.
If Part 5 ends in another state, change that one dpre106 line.

READS.
- Part 1: sweep106, sweep105, uo106, tl106, FORMS106, NM106F, RX106, PR106, LKF106, R106, OUT106, DEPLOY106, AM0_106, WF0_106, BR0_106, ST0_106, EVO0_106, FAIL_PRE106, refuse106, refused106, evopost106, lock106, ancc106, installed106.
- Part 3: J1A106, and RF4_106 (the rename fix).
- Part 4: dpre106, LIM106, PLOG106.
- The chain, all set before the cut: EVO0_105, EVODRV105, AM0_105, WF0_105, H104_HARNESS105, OUT104, q105 secret, sqx, evo_calls, live, inst_ver, n103, check.
- Every cross-part variable is guarded with ${X:-}, so a missing one fails a check and never aborts under set -u.

DEFINES.
- sweepx106: master-106m-*.out and master-106s-*.out in $R104/logs, $R106/logs/act-*.txt, and $R106/ucrm/run-*.req.
- sweepall106 and sweep104_106.
- sweep_files104, sheets104 and reqs104, sourced from R4's 104-10 after an asserted read. A miss is recorded as rh106:lists104. No line of R5 past the cut is read.
- SCN106=$R106/scan106.

CHECKS, in order:
- 106-10a0: the preconditions.
- 106-10a: Evolution. No run of 106 or 105 called it; nothing off the base path since EVO0_105. EVA106 ≥ refusals since RF4_106, which must be ≥ 15. The 105 span ≥ EVODRV105.
- 106-10b: uCRM GETs only and no token copy left, over R106, R105 and R104; nothing left in /tmp (dnb-$V106/$V105/$V104/report); planted controls.
- 106-10c: 106's own records, NM106F and FORMS106. Controls are planted as a run terminal and as a master pass.
- 106-10c': 105's records, through sweep105.
- 106-10c'': 104's records, through 104-10's three lists.
- 106-10d: LK keys. $LKF106 and the case folders' lk-key files are each mode 600 and read; no key value appears in any swept file.
- 106-10e: 106's own range (RX106) and the RH106SECRET- marker in no file of $DATA, $PD/data or $RECS except plugin.sqlite3*. The book holding both is the control.
- 106-10f: the A9 leftovers:
  - no uj trigger, index.lock, or .redirect106/.redirect106lock;
  - the refuse list removed here, then J1A106's send answers id and nothing more is refused;
  - no $REPO/scripts/.*.sh newer than DEPLOY106 (a planted control is counted, then removed);
  - $R106/kept: no fx kept copy left, then the folder removed and asserted gone;
  - no [master] line in plugin.log;
  - no zz106ctl control left, and SCN106 empty;
  - neither RH106_TOUCH nor RH106_RANGE exported.
- 106-10g: 105's span (R5:1166-1168, cut). Anchor-missing AM0_105→AM0_106, wait104.fail WF0_105→WF0_106, and no ^run105- line in bad-runs.
- 106-10h: 106's own span against AM0_106, WF0_106 and BR0_106, plus ancc106 reading "0|…|-".
- 106-10i: the end state. Live is P106 or B106, installed106 of that commit is 0, and the checkout status equals ST0_106.
- Then R5's form: echo; echo "RESULT: $PASS passed, $FAILN failed — of them $FAIL_PRE106 before $V106's own sections (the earlier rehearsals' chain)"; [ "$FAILN" = 0 ].

SIDE EFFECTS.
- It removes $R106/kept and $SB/evo/rh106-refuse.list.
- It sends one POST to the Evolution stand-in (J1A106's number), after 106-10a's count.
- It plants only controls, each said, removed and asserted gone.
- It plants no number and takes no new NN. J1A106 (NN 00) is used only as a stand-in send.

WHAT PART 5 MUST HOLD FOR 106-10 TO PASS:
1. The end state above.
2. No planted number or name in a swept file. In particular, 6b's uncut set_config output showing the phone must not go to $R106/run-*, mutants/ or logs/ (run_line106c and run106 write there).
3. Every settings or store fixture undone. 106-10e reads kyc_config.json and both config.json files for 106's numbers and the marker.
4. Weakened copies only via weak106, removed after use.
5. fx keep folders emptied by fx_file_back.
6. RH106_RANGE and RH106_TOUCH never exported.
7. No label starting 106-10.

Interface notes now stale and to update at assembly:
- p1-interface's "seed106 MODE ARG…" is now CODE-ROOT DATA OPS-JSON [ZONE], with RH106_RANGE set inside it.
- p4-interface's "RH106_RANGE exported from PR106" no longer holds: it is not exported.
- p3-interface adds HELD106=- and renames RF0_106 to RF4_106. Part 1 renames GL106 to GLK106.
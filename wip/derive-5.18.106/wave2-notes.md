# wave 2 notes (partUJ, partME; drafting agent)

# test_output
One python3 -I -B process (sys.dont_write_bytecode = True; no file written; both module texts exec'd from strings as types.ModuleType). Doc = d106lib.Doc(S105) with the ctx the task gave. Parts applied in order:
  partUJ text 64873 chars, 729 lines, sha256 cddbe21bf8f3c9b9…
  partME text 18106 chars, 238 lines, sha256 91bb1c1068335333…
  partA: 50 edits (50 so far)
  partB: 15 edits (65 so far)
  partCS: 7 edits (72 so far)
  partUJ: 11 edits (83 so far)
  partME: 2 edits (85 so far)
  partL: 12 edits (97 so far)
bash -n (stdin) 0
folder_check: 309 functions, 3274 subshells; 24 may make the folder, none in a subshell; 27 read it, each after the folder is made
result: 15346 lines, sha256 c73679876339237a…

Stub-function tests (bash fed on stdin, no docker, no php, no file). kv/utc_of/uj_ok/notyet/uj_gate_ok/uj_on/me_ca1 were extracted from the derived text; ok/bad/note were stubbed:
uj_on:
  pass expired          -> OK   UJ-ON PASS — F3 works: 5 …
  pass nothing stale    -> OK   UJ-ON PASS — nothing stale for F3 to close …
  control held, 250 cancelled, stale 1 -> wait UJ-ON NOT YET — the close passes' places went to gone conversations …
  control held, 3 cancelled, stale 1   -> BAD  UJ-ON FAIL — F3 did not expire …
  control not           -> wait UJ-ON NOT YET — followup_close has not started and ended since the copy …
  control unread        -> BAD  UJ-ON FAIL — followup_close's entry … could not be read
  en=off now            -> NOTE UJ-ON not judged — its gate did not hold: followup_enabled is off now …
  hours 168 vs 336      -> NOTE UJ-ON not judged — … the age limit differs — 168 h at stage A, 336 h now …
  UJ_A empty            -> NOT YET with the "judged on this run's values alone" suffix
  no copy time          -> BAD  UJ-ON FAIL — the copy's time is not on record …
  reading unread        -> BAD  UJ-ON FAIL — this run's reading could not be made …
  on=unread             -> BAD  UJ-ON FAIL — the closes since the copy could not be read …
  NOT_YET=3 afterwards
me_ca1:
  ok                    -> OK   ME-CA1 no ERROR line …
  err_after=2, err_24h=5 -> BAD  ME-CA1 2 ERROR line(s) … (by job: close 0 scan 0 run 0 send 2) — in the 24 h before the copy, for comparison: 5 …
  err_in=1              -> NOTE (the cycle the copy straddled, up to its own Cycle complete), then OK
  control=not           -> NOTE ME-CA1 NOT ESTABLISHED — THE POSITIVE CONTROL DID NOT HOLD …
  log=absent / ?Error / empty -> NOTE ME-CA1 NOT ESTABLISHED — … COULD NOT BE READ THERE …
  COPY_AT empty         -> NOTE ME-CA1 NOT ESTABLISHED — THE COPY'S TIME IS NOT KNOWN …
  err_24h empty         -> NOTE ME-CA1 NOT ESTABLISHED — … COULD NOT BE COUNTED …
  MODE=after reading COPY_AT from a state stream -> takes the last COPY_AT= line

Not run: php, the test suite, the deploy and the rehearsal (forbidden). K1–K5 were not run; K5 is expected to fail until partR adds as_rb1's writer. The UJ and ME texts assert that their own lines name no writer, no switch tool and no close.

# notes
Both modules apply cleanly in the chain partA → partB → partCS → partUJ → partME → partL: 50+15+7+11+2+12 = 97 edits. bash -n gives 0, and folder_check is clean. partL still applies after them; its CA1_LINE and lm_expect counts are untouched.

## The edits partUJ makes (11), in order
1. QF_PHP's vault rule now ends `'cfgkey', 'uj'], true)) putenv('DN_VAULT_FILE='`, so PluginConfig::load's vault refresh writes only the copy's own vault.
2. conf's $n1 list now ends `'alert_hours_from', 'alert_hours_to',` followed by a `// 5.18.106 …` line and `'followup_max_age_hours', 'followup_run_limit', 'followup_scan_limit'];`.
3. `case 'uj':` is inserted before `    case 'rbn':    // rbn PDD — `. It includes expireStale's SELECT, taken from bf29cbd by regex and asserted 4 lines long.
4. The UJ block (UJ_RULE plus the functions) is inserted before `# qf_stage — QF-G1–QF-G3, LK-G1 and QF-N1–QF-N7, stage A, before anything changes`.
5. In qf_stage, before `  cih_drop "$sub"\n\n  # QF-G2 …`, two lines are added: `  uj_stage "$sub"   # U1–U4 — …`, then `  # ── SLOT U5 ── partR: U5 (root docs/75 §L.4), the one reader of §L.7 on the live files, before DEPLOY is asked ──`.
6. After the backup block's QG3_A line: a comment, then `  [ "$MODE" = "deploy" ] && printf 'UJ_A=%s\n' … >> "$STATE_W"`.
7. After the `lm_after   # LM-CA1 …` line: a comment, then `hdr "R. The enquiry follow-ups after the copy (5.18.106, UJ-CA$( … && echo " and UJ-ON"); …)"`, then `uj_after   # UJ-CA (notes) and, in --after-only, UJ-ON (judged)`.
8. The counter line becomes `PASS=0; FAIL=0; NOTE=0; NOT_YET=0`.
9. `notyet() { NOT_YET=$((NOT_YET+1)); printf '  wait  %s\n' "$*"; }` is added after note().
10. The summary is rewritten:
    - the checks line gains `$( [ "$NOT_YET" = "0" ] || echo ", $NOT_YET not yet")`;
    - a two-line comment follows;
    - `if [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ]; then … PASSED …`;
    - `elif [ "$FAIL" = "0" ]; then`, printing three lines: the NOT YET verdict with UJ_NY_SAY and UJ_NY_AT; "Send this LOG FILE back … --after-only again, its own command, alone, with its own log:"; then `      $AFTER_CMD` alone on its line;
    - `else … FAILED …; fi`.
11. The end of main becomes `[ "$FAIL" = "0" ] || return 1`, then `[ "$NOT_YET" = "0" ] || return 3`, then `}`.

## The edits partME makes (2)
1. The ME block (ME_RULE, its header comments, the ME_PHP heredoc, me_php and me_ca1) goes before the qf_stage comment line, so it sits after UJ's block.
2. The ME call (a comment, `hdr "R. Master's errors after the copy (5.18.106, ME-CA1; …)"`, then `me_ca1`) goes right after the lm_after line.

Stage R's order is therefore lm_after → ME-CA1 → UJ-CA/UJ-ON → qf_on, which is §L.5's order.

## New names
- **Shell functions:** uj_read, uj_ok, uj_gate_ok, uj_was, uj_out, uj_places, uj_notes, uj_u4, uj_stage, uj_after, uj_on, notyet, me_php, me_ca1.
- **Variables:** UJ_A (also a state-file key), UJ_NY_AT, UJ_NY_SAY, NOT_YET (the counter), ME_PHP.
- **The PHP mode:** `uj ROOT DIR [COPYUNIX [AUNIX]]`.
- **Codes:** U1–U4, UJ-CA, UJ-ON, ME-CA1.
- **ctx keys:** uj_xs (expireStale's SELECT), uj_slot="U5", me_rule.
- **Output words:** notyet prints "  wait  ". The capitals note `ME-CA1 NOT ESTABLISHED — ` occurs exactly 4 times; `UJ-ON NOT YET — ` and `UJ-ON PASS — ` occur 2 times each.

## For partR
- **Where U5 goes:** call `fill(doc, "U5", text)`. It inserts between the uj_stage line and the slot line inside qf_stage, in the main shell, after QF-G1's copy and before its cih_drop. UJ_A is set at that point.
- **The one writer:** UJ and ME add no writer. as_rb1's saveOverrides stays the single writer that K5 counts.
- **Rollback texts:** all of them are yours. UJ and ME touch none.

## For partH
- **The §F owner's steps:** print them only when MODE=after, FAIL=0 and NOT_YET=0. A NOT YET withholds them.
- **Left unchanged:**
  - the ECO Bank block (`[ "$FAIL" = "0" ] && [ -n "${V3J_WBB:-}" ]`) — decide whether it needs `&& [ "$NOT_YET" = "0" ]`;
  - the "check it", "your steps" and rollback texts;
  - the header's mentions of UJ, ME, NOT YET and exit status 3.
- **Summary anchors to edit from:** the new `if [ "$FAIL" = "0" ] && [ "$NOT_YET" = "0" ]; then` line and the `elif [ "$FAIL" = "0" ]; then` branch.

## For partT
These carried texts were left as they are:
- qf_stage's header comment line (used only as an insertion anchor; still unique);
- the stage-A hdr wording;
- lm_after's hdr wording ($EXPECTED_VERSION's LM).

## For partZ
Assert:
- slot U5 is closed: `close(doc, "U5", <partR's last filled line prefix>)`;
- `notyet() {` occurs once and `return 3` occurs once;
- `ME-CA1 NOT ESTABLISHED — ` occurs 4 times;
- the stage-R order is lm_after < me_ca1 < uj_after < qf_on.

## Recorded limits
- **The ten places are a partial simulation.** They do not simulate the window, the fence, the registry's pause, the policy or a claim; a draft that passes every check read shows as "waits". The window state is printed separately.
- **The plugin's zone may differ from master's.** dn_tz() reads `<data>/kyc_config.json`, using DN_DATA_DIR from the container's environment if set, else the derived data folder — the same disk read master makes. If master's process environment differed from docker exec's, the zones could differ.
- **Only the log's last 32 MiB is read**, as LM-CA2 reads it. A partial first line counts as undated.
- **me_php runs without `-i`.** Its stdin is /dev/null.

# wave 2 notes (partR; drafting agent)

# test_output
One python3 -I process, no file written. The real partUJ and partME were taken from the task prompt (de-indented) and exec'd in their places. Order: partA, partB, partCS, partUJ, partME, partL, partR (exec'd from a string as types.ModuleType). The source is S105 (scripts/deploy-5.18.105.sh). ctx was exactly as the task gives it.

Edit counts:
- partA: 50 (50 so far)
- partB: 15 (65 so far)
- partCS: 7 (72 so far)
- partUJ: 11 (83 so far)
- partME: 2 (85 so far)
- partL: 12 (97 so far)
- partR: 33 (130 so far)

Result: 15,881 lines.

bash -n on the result (fed on stdin): rc 0, no stderr.

d106lib.folder_check(doc.t): "323 functions, 3381 subshells; 25 may make the folder, none in a subshell; 29 read it, each after the folder is made".

The driver's own keep_check (derive106.py, from "keep_check: K1–K4" to "the two wrappers stay as committed") on the result as it stands fails in one place:
"KEEP CHECK: K5: 2 writer(s) besides the two exceptions, not exactly one: line 1570: sales-followup.sh off; line 8792: saveOverrides("
- Line 8792 is as_rb1's one write, as intended.
- Line 1570 comes from S105 (its line 1580) and partR does not touch it. It is the option-parsing case line `    --clear-switches) echo "--clear-switches: $EXPECTED_VERSION never clears a ladder key; turn a step off yourself with scripts/sales-followup.sh off <step>, its own command (root docs/74 §H.4.4). Nothing was run" >&2; exit 64 ;;`. K5's _is_display does not treat it as display text, because the line starts with `--clear-switches)`, not with echo.

In a separate copy of the document, I simulated partH's options edit: that line split so its echo starts a line of its own. With that, the driver's keep_check and folder-check segments ran in full:
- "keep check: K1–K5; twelve weakened copies (K1, K2, K3, K4 and K5's eight), each refused for its own reason alone"
- "folder check: 323 functions, 3381 subshells; …"
- "folder check control: three weakened copies, each refused for its own reason" (rb_pre106's cih_main line is the anchor).

Counts on the result:
- rb_pre105, rb105, RB105: 0 each
- rb_pre106: 4; 'rb106() {': 1; RB106: 25
- OWNER_AUTOSEND_CMD=: 1
- "  as_rb1 typed\n": 1 (the comment "as_rb1 typed|v1" holds the other mention)
- slot U5 marker: 1, with the line above it starting "  as_u5   # U5"

U5 in context (qf_stage):
  uj_stage "$sub"   # U1–U4 — …
  as_u5   # U5 — 5.18.106 (root docs/75 §L.4): …
  # ── SLOT U5 ── partR: U5 …

Stub runs, bash fed on stdin with the result's own functions plus stub as_read, note, ok and bad; no docker, no php, no files:
- as_owner_text 0|1 covers every cause: ok, differ, code+file, already off, noowner, unread.
- as_para covers pb, rb not-written, rb written, v1 written.
- as_u5 covers on/ok, on/differ, off/ok, off/row, unread/file, noowner, unread. Run under set -uo pipefail, rc 0.
- as_after_verdict, and as_rb2 under RB_NOTHING, off after a write, and on after a write.
- OWNER_AUTOSEND_CMD expands to `docker exec -u 33:33 -e DN_DATA_DIR=/data c1 php /app/plugin/tools/set_config.php --key followup_auto_send --value 0 2>&1 | sed '/^  AI SETTINGS$/,$d' 2>&1 | ( umask 077; exec tee …/autosend-0-$(date …).log )`.
- --value 7 is clamped to 0.
- On sample output the sed cut keeps "  followup_auto_send = 0" and drops the listing from "  AI SETTINGS" on.

# notes
THE ONE BLOCKER FOR THE DRIVER'S K5 — partH (options) must fix it. S105:1580 (result line 1570) is `    --clear-switches) echo "…turn a step off yourself with scripts/sales-followup.sh off <step>, its own command (root docs/74 §H.4.4). Nothing was run" >&2; exit 64 ;;`. K5's WRITER regex matches it, and _is_display does not exempt it because the line starts with `--clear-switches)`. partH should split it as `    --clear-switches)\n      echo "…" >&2; exit 64 ;;` (or drop the literal "sales-followup.sh off"), and reword it for 5.18.106: §L.7, docs/75, not docs/74 §H.4.4. With that simulated, K1–K5, all 12 weakened copies and the folder controls pass.

WHAT partR INTRODUCES
- Top-level variables: AS_SUMS (heredoc of six "sha256 path" lines from 7835906), AS_MIGS ("90:<sha256 of sorted names joined by \n>", computed), AS_PHP (heredoc), and AS_RB0, AS_RB0_SAY, AS_RB1, AS_RB1_SAY (initialised empty). OWNER_AUTOSEND_CMD is assigned once, inside as_owner_cmd.
- Functions: as_read, as_places_say, as_show, as_causes_say, as_cmd_ok, as_owner_text, as_para, as_u5, as_after_verdict, as_rb_last, as_back_on, rb_pre106, as_rb1, rb106, as_rb2, as_owner_cmd.
  - as_owner_cmd is inserted right after the DB_OWNER= line, at top level.
- Check codes: AS-RB0, AS-RB1, AS-RB2, RB106. U5 is a note.
- State-file key: AS_RB1, appended to ${STATE_W:-$STATE}. Values: written, off, written-check:<codes>, not-written:<pre-causes>, not-written:owner-unknown, not-written:unread, not-written:refused, unknown:no-answer.
- ctx keys added: as_sums, as_migs, as_slot_before="as_u5   # U5". partZ closes slot U5 with that `before`.

CALL SITES
- `  as_u5   # U5 …` is filled before partUJ's slot in qf_stage.
- `    rb_pre106   # …` and the ROLLBACK printf: the prompt now has four %s, the fourth being "${AS_RB0_SAY:-…}".
- `    as_rb1 typed` is on its own line, after the typed-rollback comment (TYPED_COMMENT) and before `    if do_rollback; then ok …`.
- V1's line is exactly `V1_ECHO; as_rb1 v1; if do_rollback; then`.
- V1's `then rb106   # RB106 …`.
- `as_after_verdict   # 5.18.106 …` sits at column 0, inserted before "# 5.18.98 — after a PASSED deploy or --after-only whose G3 went to its note…".
- In the RB section: `rb106` at column 0, with a new comment.
- as_back_on is DEFINED but NOT CALLED. partH places it in the PASSED --after-only summary (§L.8). It prints nothing unless MODE=after, REDEPLOY=1 in $STATE, the key reads off, and as_rb_last gives "written".

ANCHORS partR CHANGED — later parts must not look for the old text:
- The whole block from "# ── 5.18.105: the rollback keeps every switch (root docs/74 §H.5) ─" up to (not including) "# ── 5.18.104: Part V's checks — …" is replaced. The new rule line is "# ── 5.18.106: the rollback, and its one write (RB106; AS-RB0, AS-RB1, AS-RB2; root docs/75 §L.7) ─…".
- The PUTBACK_HAZARD comment and its value: now "copies 5.18.105's files … It runs no switch tool and writes no setting: …".
- The 7 print sites now read `$PUTBACK_HAZARD$(as_para pb) Put` ×5 and `$(as_para rb)` ×2.
- The summary's by-hand lines: "one above; it counts nothing, runs no switch tool and writes no setting — …", a second echo line, then `  as_show "$(as_para sum)"   # …`.
- The RB_NOTHING comment ("fi   # nothing to roll back: rb106 judges no LK-RB1, and nothing is written — AS-RB2 says so (root docs/75 §L.7)").
- V1's comment block, which now starts "      # 5.18.103 (docs/72 §G.10), carried; 5.18.106 (root docs/75 §L.7, DL4): …".
- V1's verified stop, which now ends "…send the log file$(as_para v1)".
- The stage-A note (S105:11575), which now starts "  note \"after the rollback the code is 5.18.105's again where 5.18.106 changed it — …".
- R7's header, now the rule "5.18.95: the database, before and after (R7, RB) — 5.18.106 adds no migration; a rollback writes one key (AS-RB1)".
- db_judge's ok tail.
- In the RB section:
  - the opening comment (counts from ctx n_changed/n_kept/n_added);
  - "named by nothing $BASELINE_COMMIT tracks";
  - "never called unread";
  - the two "changes no setting but followup_auto_send" texts;
  - the 088 comment;
  - the rb106 call comment;
  - the "RB the code is …" note.
- Every RB105→RB106 and rb_pre105→rb_pre106 rename, header and summary included.

LEFT FOR partH — the wording is still 5.18.105's; only the names were renamed:
- Header mentions of RB106 and rb_pre106 at S105 lines ~123, 492, 554, 615, 638, 650.
- Summary mentions at ~14589, 14795, 14811.
- The summary's rollback paragraph, S105:14726–14736.
- "what it means", ~14570.
- Header passages on the rollback, the switches and the put-back: ~102–103, 427–436, 486–494, 550–566, 570–573, 585–588, 611–613, 636–650.
- The --clear-switches option line (above).
- Placing as_back_on.

LEFT FOR partT — carried release texts in or near the region partR did not rewrite. Check each:
- the db_judge function's comment still saying "5.18.105 adds no migration", if any;
- the vn_rb_after, of_rb_after and ac_rb_after comments naming 994d660 or 5.18.105;
- other release-history comments in the RB section (around the hdr "RB. $BASELINE_VERSION, back in place");
- comments near S105 ~6850 and ~7163.
partR asserts that the RB region holds none of "numbers again", "WRITES PHONE", "reached by nothing", "read by nothing", "writes nothing to the book".

BEHAVIOUR CHOICES partZ/partH SHOULD KNOW
1. An empty DB_OWNER means AS-RB1 writes nothing, sets FAIL (owner-unknown), never runs as root, and skips R7. Every reader site prints "as=noowner" with no command.
2. The row follows SqliteStore::load: no table, no row, or undecodable JSON reads as [] and is not a cause. The row cause fires only for "unread" (exception) or "nodb" (no plugin.sqlite3). nodb is conservative: the writer itself would skip the mirror.
3. Condition 4 checks both encodings: the file's (PRETTY_PRINT|UNESCAPED_SLASHES) and the row's (UNESCAPED_UNICODE). The type list is prefixed k:/s:.
4. Condition 5 checks the six files by sha256 in the INSTALLED tree (AS_ROOT=$IN_CONTAINER), migrations/ as count:sha256 of sorted names, and that no <plugin>/seed exists.
5. AS-RB1 writes only when the key reads on AND all conditions hold, recomputed in the same PHP process. The read-back covers decodes, keys, value, file-others, owner, mode, effective, row-unread, row-others and config-json, each a FAIL. The row's '0' is a note.
6. AS-RB2 FAILs when the key reads on unless AS_RB1 is not-written:*. Under RB_NOTHING it is a capitals note plus the owner's command.
7. F5's count is read from the live book through SQLITE_OPEN_READONLY and query_only.
8. AS_PHP silences error_log. Values never leave PHP; only codes, key names and on/off words are printed.
9. The OWNER_AUTOSEND_CMD text is expanded only in as_owner_text's single printf, and partR asserts this. The command is built at print time after as_read; nothing runs it.
10. Every printed command stands alone on its own line. The claim-switch off commands are echoed using $QF_WRAPPER and $REPO, never run.

partZ CAN ASSERT
- exactly one `saveOverrides($dd, ['followup_auto_send' => '0'])`, inside as_rb1;
- `docker exec -u "$DB_OWNER" -e AS_ROOT="$IN_CONTAINER"` once;
- as_rb1 called only in its two places;
- OWNER_AUTOSEND_CMD assigned once and never run;
- no rb_pre105, rb105 or RB105 left;
- slot U5 closed with before "as_u5   # U5".
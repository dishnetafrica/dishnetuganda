# The 5.18.107 rehearsal — what the build found against design v3 (27 Nov)

Parts 4c to 7 were written from design v3 §B and then read against the script (S:n is `scripts/deploy-5.18.107.sh` at
`abf0a25`). Where the design and the script differ, the script is right; each difference below is recorded here, and the
part that follows it says so in its own comment. Nothing here has run yet: the dev trials follow suite pass E2.

## Design statements corrected by the script

1. **V1's automatic rollback is deploy-mode only** (S:13950). v3 §B item 9 calls the V1 cases "a `--rollback` whose put-back
   V1 refuses". No such path exists: in `--rollback` V1 is an ordinary FAIL (S:13937). The V1 cases are `--redeploy` runs
   with H5 armed (part 6, `v1run107`).
2. **R1's settings-text note prints no restore command** (S:9009: "This script prints no command that writes
   bot_custom_instructions"). v3 says "with the restore command". Part 6 asserts its absence.
3. **The "put-back paragraph in a completed rollback" (v3 item 10) cannot be reached**: a rollback's summary skips the
   block that prints it (S:16269). The paragraph's readings are judged in a PASSED `--after-only`'s summary
   (`pb_para sum`), the cheapest mode reaching `pb_ready` and `pb_para`.
4. **R3's three copies** (v3: "each a rollback in the same state") run as `--after-only` with Q on: `pb_para`'s K/Q lines
   (S:9121–9131) are the same branch there, and a rollback-state copy would need a redeploy and a rollback per copy.
5. **A declined `--rollback` writes its record before the question** (S:13757), with no `RB_COPY_AT`. "Nothing changed"
   reads: no new record holding a copy time, the LK key file and the deploy's state file unchanged (part 4c `rbq107`),
   as 5.18.106's rehearsal reads it.
6. **Stage R's seen=0 capitals (S:8485) are deploy-mode only.** v3 §C item 6 ("the branch is the same in both modes") does
   not hold for it; its weakened copy is a deploy of its own from A9 (part 5, 107-5e#). The `--after-only` seen=0
   branch (S:8489) keeps its own copies.
7. **D8's plant** needs H2 to alter the installed 092 at `stamp_copy`, which the H2 hook as first built could not do: a
   second trigger, `.alter092touch107`, with its own control (part 2b), in LEFT107 and `cleanup107`.
8. **The one-block rule (v2 A18) had no reader**: `rh107q.py blocks107`, with two controls (adjacent, mixed).

## Found by the two reviews (workflow `wfp8kp297`), all fixed

- **Blocker — A14 after every rollback that copies** (S:13218–13239): a typed rollback, H6's (its `RB_COPY_AT` is written
  before the verify fails, S:13847–13852) and V1's automatic one each need the quarantine's `--check` before any later
  deploy-mode run, `--check` included. Part 6 now runs `qcheck106` after R2, R3, R4 and inside `v1run107`, as 5.18.106's
  rehearsal does after every rollback. R5 (H3) copies nothing and needs none.
- **107-8Rq′** (then 107-8q′) looked for INDETERMINATE anywhere; stage A's SA note says it on every run with control 0 (S:8096). It now
  reads the stop's own text.
- **A V1 case with control above 0 and no mark** was missing (the unmodified script's "it lists no conversation" branch,
  S:8906): 107-8Ro2, an unmarked typed burst.
- **Copies without a positive observable** (107-8b″, 107-8b*, 107-5e*) and the WARNING count in 107-5d″ (the real line
  beside the planted one) tightened; 107-8b asserts the reason the switch was not read (P3B_WHY, S:12540).
- Smaller: conversation ids matched whole (`hasid107`); V1's weakened copy kept out of the one-block sweep; the `mixed`
  rule's own control; 107-10f asserts every control gone and the NS keep empty; the boot outputs read line by line;
  `plugin.log`'s absence read as 0; the seed's JSON outputs swept.

## Found by the second round of reviews (workflow `wnaq3qe25`), each verified, all fixed

- **Blocker — the H4/H6 docker hook was never inserted.** Its own comment named both triggers with the leading dot, so the
  insertion's count assertion (2 and 5) read 3 and 6 and refused: every case arming `.boot107` or `.rbfail107` would have run
  without its hook. The comment no longer carries the dots; the hook's own text counts 2 and 5, measured.
- **107-infra b** looked for W.1's key in `allKeys()`; it is a row of `SETTINGS` (part W), so `settingKeys()` holds it. It now
  asserts allKeys() equal to 5.18.105's and settingKeys() 5.18.105's plus exactly W.1's two keys, none lost.
- **ONP107** lacked the VN_STEPS pairs and AC-ON1 that 5.18.106's construction appends (106:256); built the same way now —
  14 pairs, none of 106's lost (computed from both scripts).
- **107-infra k** counted lines of phones106.txt; the reader counts distinct numbers (105's file holds both written forms).
  Counted as 106:575 counts 105's.
- **107-1a0** matched a plugin-relative list with the repository path; **107-1i2** expected the drain wording without
  `unread(…)` — the script prints `the drain not read (unread(the pin is not yet staged))` (pb_para's `bd=` through
  `rb_bd_say`). Recorded as script wording, not changed: accurate, only doubled.
- **The p3bunset copy had no observable**: every read of `P3B_PIN`/`P3B_WHY` on the path to the BASE_DIFFERS stop is guarded.
  It now also removes pb_para's guard, so it is the control that 107-1i2's "no unbound-variable error" can read one.
- **107-infra e's weakened-copy reading was vacuous**: part 2a's `cp` reset 5.18.106's script's mtime. Copied now only when it
  differs, with `-p`, and the reading has 106-10f's control (a planted copy counted 1, then removed).
- **rbx107** compared the ladder keys through 5.18.105's lists; `digest107` reads them through the pin's, W.1's two included.
- **107-3 fixture readings** expected `True`; sd106 prints JSON booleans lower-case.
- **107-3g** planted South Sudan in d, but kyc_config.json (k) is merged last and already says uganda, so SA would read
  Uganda. The plant moved to k — and the third round showed that cannot reach SA either (below).
- **107-6b** rewrites 092's ledger md5, so the --after-only's own V boot logs 092's real checksum WARNING into migration.log;
  nothing kept the log, and every later `back4_107` would have failed. 6b keeps and restores the log and asserts the real
  WARNING. **107-6g** (092's installed bytes altered) counts the planted WARNING and the real one: 2 or more, not 1.
- Smaller: `S4_107` was computed and never read (removed, the header reworded); the hold actions' exit status (HOLDRC107) is
  now the seed's own answer (`fxsh107`, `schedath107`, non-zero on `none(…)`); 107-7b's "quick" times from the action to the
  run's end against the poll's 360 s minimum bound; the readers print one line on failure; stale S:n citations (abf0a25) and
  the refusal-list comment corrected; `sqx` and 5.18.105's key lists added to part 1's presence checks.
- The second round's audits found **no A14 violation** and **no case assuming the opposite of the 125 s wait**.

## Found by the third round (workflow `waw08fzc2`: the second round's fixes checked, parts 4c–7 read again), all fixed

- **Blocker — 107-3g could not reach SA on a live book.** Any profile other than Uganda in the settings files makes A23.7
  refuse first: the pin's `cashOnlyRuleInForce()` reads the same gate over a copy of the same three files (S:2853–2858,
  S:13442), and A23 runs before `qf_stage`, SA's only caller (S:8566). The d plant of the first build could not reach it
  either (k said uganda). 107-3g is now three readings: the real one says Uganda (107-pre6's --check); a READER CONTROL — a
  copy whose SA reading returns `ug=0`, nothing planted, as 107-3t' does — shows the capitals with the --check still GO; and
  the live plant in k shows the refusal at A23.7, nothing changed, SA never reached. The design's "SA notes StaffJobsGate
  false" is a branch no live book reaches through a --check; recorded, not a script defect (A23.7 is the stronger refusal).
- **107-8g** read 'ANSWER ANYTHING BUT ROLLBACK BELOW', which K's and Q's step-5 notes also print while they are on: it now
  reads the drain's own capitals.
- **107-10c''** had lost 106-10c''s reading that each of 104's three lists reads something before the controls (a 0|0 over
  empty lists read clean): carried back.
- **107-10e**'s marker exemption excluded every file named kyc_config.json at any depth; it is now the one path.
- **The installed worker's Evolution calls in 107-8f** were bounded by nothing (EVO0_107 was set and never read): asserted 0
  around the worker run; 107-10a states the count since 107-pre. *(Superseded by trial 1d, T1-9 below: the worker runs
  every due event, and its requests are now attributed rather than asserted 0.)*
- **R3 (107-8Ri)** asserts its one rollback record (RS3_107 was taken and never read).
- **Labels**: part 6's cases now carry their own range, `107-8R…`, and its helper calls (`rbx107`, `dredeploy107`,
  `v1run107`) labels of their own (`-RB`, `-RD2`…`-RD4`, `-V1`), so no check label in 107's sections is used twice.

## Accepted as known limits

- In 107-8f the installed worker runs every due event of D1's book, not only #A and #B; what else it does is judged by
  107-10's sweeps (Evolution counts, names and numbers), not case by case.
- Whether rollback-mode stage A under `FAKE_CP_FAIL` reaches the question (107-8b) can only be shown by a run.
- Whether 107-7b's action-to-end time stays under 120 s, and whether the installed worker in 107-8f calls Evolution for
  nothing (asserted 0), can only be shown by a run. *(The second half was answered by trial 1d, T1-9: it calls it for another
  due event's typing presence.)*

## The development trials (28 Nov) — from the snapshot `b107rh/snap1`, never committed

Each trial restores 5.18.106's rehearsal as `snap1` saved it (over snap4's lower chain) and runs 5.18.107's own lines; the
record run is the whole chain from scratch. Trials 1–1c stopped early on the stand-in web server (T1-2, T1-3); trial 1d ran to
the end on `dfe5f54`: **3,534 passed, 19 failed** — 5 inherited from the chain sections snap1 holds, and 14 of 5.18.107's own,
every one explained below. **No finding was a defect of `deploy-5.18.107.sh`**: each was the rehearsal's own expectation, plant
or reader, and each was fixed there, reassembled, committed and pushed.

- **T1-1, 107-infra m** — the anchor-missing file absent made `grep -c` print nothing, read as a failure; now through `n103`.
- **T1-2, T1-3, 107-infra h (H5)** — the stand-in relaunched on the clock answered from `public.php` as compiled before H5's edit.
  `opcache.revalidate_freq=0` did not cure it; the relaunched stand-in runs with opcache off. Why the cache ignored the edit is
  not established; a fresh server without opcache serves it.
- **T1-4, 107-3w** — W.1's switch is a ladder *setting*, and LK-G1 counts `allKeys()` only (S:7511): NS-G1 refuses it in p, as
  §T.11 says. The case expected LK-G1.
- **T1-5, 107-4-0** — `sv107` reads `NAME=` only at a line's start; `QF_WRAPPER` is the third assignment on S:7336 (the only
  such name of the fifteen it reads). A reader of its own, and the assignment asserted to occur once.
- **T1-6, 107-6a and 107-6i** — the seed's rename of `wa_no_ai` was refused: SQLite 3.45 re-parses every view on a rename, and a
  migration-built book carries views that no longer compile (`error in view v_lte_pending_jobs`). The seed renames under
  `legacy_alter_table`, as the script's own probe 3 does (S:6738–6745, §T.16 S-1), and asserts the rename took. Shown on a
  backup of the trial's book, with the unfixed seed refused on the same copy.
- **T1-7, 107-3g"'** — two expectation errors. With South Sudan's profile planted, **A9 refuses first** (the registry is not
  taken off Uganda, S:12956–12957; A9b next, A23.7 later still). And the data digest moved because the **live vault** moved:
  the script's `tools/set_distributors.php --show`, run as root on the live folder, loads the configuration, and that load
  refreshes the vault when its content differs — documented by the script itself (S:669; Z1, S:11844: *said, never failed*).
  `tenant_profile` is a vault key. The vault is now kept and put back around the plant, and its one move asserted.
- **T1-8, 107-7b3** — the `--after-only` command is printed three times in a NOT YET run (twice in *check it*, S:16091–16095,
  once after the summary's sentence, S:16188); the case counted one. It now reads the line after the sentence, as 106-5a' does.
- **T1-9, 107-8f"** — the installed worker runs every due event, not only the case's two (the known limit): an `ai.reply` left
  pending by an earlier chain section took the normal path, `conv N: in channel=` then the typing presence
  (AiReplyWorker.php:306–310), then failed for want of a provider key. The case's own events reach neither typing nor a send.
  Every request of the run is now attributed: a presence per other conversation taken in, a send only for #B's own alert.
- **T1-10, 107-5c1 (and 107-5c, 5c', 5c\*)** — the H4 hook fired on stage A's `deploy-hybrid.sh --check` (S:12315), whose read
  of the record has a deploy-hybrid parent, and booted 5.18.106 over the planted 092. The deploy and the rollback call it with no
  argument (S:13884, S:13844); a `--check` parent now passes through, marked, with a control in 107-infra h. 5c, 5c' and 5c\*
  started from that boot's state.
- **T1-11, 107-5e##** — the keep tag `l5eR` broke the seed's rule (`^[a-z0-9][a-z0-9-]{0,39}$`), so the log was never kept or
  put back. Now `l5er`; 107-5e# asserts its keep; every other tag swept.
- **T1-12, 107-10b** — two `/tmp/dnb-5.18.106-*` folders left by trial 1, cut by a container restart before its clean-up. Not
  the rehearsal's; removed by name, and the development runner now refuses to start while such folders exist.

**Reviews during the trial**, each by two read-only agents, every finding verified before it was applied: of 7a781fe (R-1: one
comment, two checks tightened; 107-3g the only vault-key plant), a pre-sweep of 107-8…107-10 before the trial reached them
(P-1: no defect; four plants now asserted before their cases), and of T1-8…T1-11 (R-2: 107-8f"'s alert scoped to #B's own
line and counting a send Evolution refused; 107-7b/7d's schedule plants asserted; two texts brought up to date).

**Trial 2, on `977a893`** (28 Nov 15:42 – 19:51 UTC), ran to the end: **3,558 passed, 5 failed** — the same 5 inherited
from the chain sections snap1 holds, and **0 of 5.18.107's own** (404 passed, against 380 and 14 failed in trial 1d: the cases
T1-4…T1-11 fixed, the controls and plants R-1, P-1 and R-2 added). Every case those fixes touched passed: 107-infra h and m,
107-3w, 107-3g" (and its vault reading), 107-4-0, 107-5c1 and 5c…5c\*, 107-5e# and 5e##, 107-6a, 107-6i, 107-7b3, the
7bk/7dk plants, 107-8f", 107-8Rg0, 107-8Rh, 107-10b. Its two `note` lines are trial 1d's, word for word: RH-NOTE A2 (A6's
reading, for the derivation's record) and 107-5h's V1 under H1 (recorded, not judged).

One thing outside the sandbox outlived it: `/tmp/dishnet_catalogue_<hash>.json` (2,253 bytes, mode 0600, the stand-in's
user), the plugin's own uCRM catalogue cache (`lib/DishNetTools.php:387`, `sys_get_temp_dir()`), keyed by the install path.
Not 5.18.107's: every run of the chain that serves the catalogue writes it, and 107-10 sweeps the sandbox and the plugin's
folders, not the host's `/tmp`. It holds the synthetic catalogue only. Removed by name; a new sandbox path gives a new name, so
it cannot reach the record run. Recorded, not changed.

## The record run (28 Nov) — the whole chain from scratch, from `/home/user/t106`

**Run 1, on `a9e67d0`** (19:56:04 UTC), was stopped by hand at 19:58 UTC, in 94-1: 74 passed, 33 failed, every failure in the
chain's root (the quarantine's rehearsal, 5.18.93's deploy, and 5.18.94's own checks) and every one the same cause, **docs/07
known limit 17**. 5.18.94's harness repacks its clone into one pack, as a fresh clone of the remote is, so that Git's short form
follows the real object count; the pushed branches alone now pass 16,384 packed objects (a fresh clone, repacked: **17,248**),
so the clone writes `69c87a6f` where every pin in the chain names `69c87a6`. The quarantine's and 5.18.93's checks then compared
7 characters with 8, A14 never passed, and nothing after it could. Foreseen in 5.18.106's chain notes on 20 Nov (*margin: 145
packed objects … once the pushed branches alone pass 16,384, every future chain fails there*); the development runners met it
first and set the deploy scripts' own remedy on their restored clone, so the trials never showed it. **Not the script's**:
`deploy-5.18.107.sh` refuses an 8-character checkout at A0 with nothing changed and prints `git -C <checkout> config
core.abbrev 7` (S:12119), and the server's checkout wrote the 7-character form at every deploy so far.

**The fix, in 5.18.94's harness** (the chain's one clone; no later harness reads the lines changed — 5.18.95's cut asserts only
untouched anchors): where the repacked clone writes more than 7 characters it gets `core.abbrev 7`, said on its own line with the
packed count; and 1z, which sets `core.abbrev 8` to drive A0's refusal, now puts back what it found (unset, or 7) instead of
always unsetting, its last check reading `--local`. Shown before the rerun: 5.18.94's Python cut applied to the quarantine's
rehearsal parses, and its three inserted lines, run on a real clone of the checkout, print *8-character commits (17248 packed
objects)*, set 7, and read `69c87a6f` back as `69c87a6`; 1z's put-back run both ways (from 7 → 7, from unset → unset).

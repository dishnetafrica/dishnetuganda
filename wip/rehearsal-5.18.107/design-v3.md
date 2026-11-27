# The 5.18.107 rehearsal — design v3 (27 Nov): v2 with review 2 taken

`design-review2.md` holds two critics' findings: TC1–TC12 (did v2 take review 1?) and FM1–FM10 (fresh mechanics).
Every finding is taken. v3 amends v2 point by point (§A) and replaces v2's §B sections 107-4 to 107-9 with one run
sequence (§B). Every other part of v2 stands. Where a finding is narrowed, §A says so.

## A. Amendments to v2

### Corrections to v2's machinery

- **A9 step 1** (TC1, FM2 — blocker).
  - The reset installs `P106` (`bf29cbd`), not `B106`. In R6's shell `B106` is 5.18.105.
  - Before use, `P106` is asserted equal to `deploy-5.18.107.sh`'s `BASELINE_COMMIT`. After the install,
    `.deployed-commit` is asserted to read it.
- **A9 steps 2 and 3** (TC9).
  - `drest104` checks digests (the dump without `master_schedule`, the vault, the tables), not every file. A9 adds
    its own sha256 check of `migration.log`, `config.json`, `kyc_config.json` and the plugin's `data/config.json`
    against the 107-pre snapshot.
  - Step 3 becomes an assertion: `install_commit` removes every plugin file but `ucrm.json` and `data` (root:298), so
    no 092 is on disk.
  - `qcheck106` takes its label.
- **H4's arming** (TC2, FM1 — blocker).
  - H4 is armed only by `csb1run107`'s action, at *Type DEPLOY* (S:13852). Arming it before the run would let it fire
    at stage A's LIVE_BEFORE (S:12299 → `live_commit`, S:1853), the same call.
  - The hook also requires its parent's command line to name `deploy-hybrid.sh` (`ps -o args= -p $PPID`).
  - Controls:
    - armed before a `--check`: the counter is 0 at the end, because of the parent condition;
    - armed at the hold: the counter is 0 at the hold and 1 after the run.
  - A8's wording changes: the hook keys on the exact `.deployed-commit` read inside deploy-hybrid, not on "the only
    container call between `QF_BASE` and the tar". `qf_php lkrows` (S:7579) and `docker inspect`
    (deploy-hybrid.sh:74) also fall in that window.
  - The anchor is written exactly as generated: ``row() { grep "^$1|" `` (FM10).
- **The boots' output** (FM7). H2's and H4's boots send stdout and stderr to `$R107/boot/<hook>.<n>.out`, never to the
  stream they pass through. H4 inside `read_live`'s `$(… | tail -n 1 | tr -d '[:space:]')` would otherwise glue
  output onto the commit. 107-10 sweeps those files.
- **The probes' places** (FM9, TC12/RM10).
  - `rh107c_probe` (A1's clock probe) sits before the boot, beside `rh107b_probe`, so a control request never boots the
    live store.
  - Both are polled for OPcache after every edit of public.php (R6:674–679, :717). v2 had dropped this silently.
- **The shim's clock write** (TC12/RC16). H2's write to `$CLKF104` is forward only. A write that would move the clock
  backward is refused, recorded in `$SB/plant092touch107.refused`, and the case fails on it.
- **The stand-in's log** (FM6). A1's relaunch appends to `$SB/web.log`, as root:930 does. 107-infra records the log's
  line count and its count of `Fatal error|Parse error`, as a baseline. 107-10 asserts that no new line matches.
- **The chain's leftover triggers** (FM8). 106-10f's leftover reading (R6:2561–2579) runs in 107-infra, before A1,
  over 106's triggers (`.ujfail106`, `.redirect106*`, the refusal list, the index lock). 107-10 runs it again over
  106's and 107's.
- **pollrun107** (TC6).
  - It runs `--after-only`; the poll exists only in `MODE=after` (S:8375).
  - Its watcher waits for *ME-CA1 waiting up to* (S:8380), then 1 s, so the poll's `t0` (S:8381) is taken before any
    move.
  - Then, by case:
    - the no-cycle case only moves the clock past `b+360`;
    - the later-cycle and re-dispatch cases only write `master_schedule` (the loop ends at its first read by `break`,
      S:8387).
  - If the line never appears, the watcher runs nothing and records `pollrun107:<label>:noline`.
  - The case asserts the line appeared and the run's real duration was under 30 s.
- **Time zones** (FM5).
  - The runner stamps with PHP's `date()` in the writing process's zone: §T.3's recorded limit. master106 boots in
    `dn_tz` (Kampala); the stand-in, H2, H4 and the seed write UTC.
  - Fixture markers are written by `rh107-seed.php logline` in UTC, from the ledger's `applied_at`.
  - No case asserts an order on a stamp written by master without converting it from `dn_tz`.
  - The 071 halving's boots go through the stand-in (UTC), so its marker can be compared with `applied_at` directly.
- **The 071 halving** (TC10, FM4).
  - A new seed op, `ledger071` with `ledger071_undo`, rewrites 071's checksum so that every boot logs 071's WARNING.
    The undo restores it and is asserted.
  - The fill: fictitious lines, naming none of 086, 087 or 089–092, are appended after 092's OK line until the file is
    just over 262,144 bytes.
  - Asserted before the boot:
    - the OK line's byte offset is under 131,072;
    - the size is over 262,144;
    - the file is owned by uid 1000.
  - One logging boot (a stand-in request) then halves the log: the runner keeps from byte 131,072, at the next newline,
    and writes the marker as line 1.
  - Asserted after the boot: the marker is line 1, 092's OK line is gone, and 071's WARNING follows.
  - No *until* loop: a second boot is a failure of the plant.
  - The readers never see the fill: MG-CA2 reads only 092's lines and line 1 (S:8454–8458), and every other reader
    greps 086, 087 or 089–091 only.
- **A2, §C item 5, and where A6 runs** (TC8).
  - M0 is the measurement partB records (`ceac392`). 107-pre's `--check` reads the values again and asserts them.
  - §T.2 and §T.12 step 2 had placed this measurement in the suite. §T.15 records that M0 made it instead.
  - A6 runs after stage A's earlier stops (S:12370, :12398, :12429, :12445): it is not before them. 107-1's runs stop
    at BASE_DIFFERS, before A6.
- **§C item 2's reason** (TC11). The root's public.php already has one-shot triggers that change the book after the
  boot (root:229–266). Judging the post-boot faults in `--after-only` is therefore a choice, not a constraint.
  - It is justified: the lead and the rollback block are independent of the mode (S:16147–16179), and `mg_after` runs
    in `MODE=after`.
  - R7's `--after-only` judgement is its second one, against the deploy's record (S:14877), not the deploy's own
    (S:14874). §C says so.

### Cases restored or narrowed

- **Restored** (TC5, FM3). Both are run in 107-S (§B):
  - §T.11:3485–3486, a redeploy with a planted 092 WARNING and no OK line: the note, PASSED, and the copy that counts
    the WARNING as a FAIL;
  - §T.11:3499, a redeploy with no 092 line: the note;
  - §M's second half, §T.11:3528–3529: the list is empty once the event is answered by the real worker.
- **Narrowed and recorded** (FM3). §T.11's redeploy framing for 3489, 3492 and 3497 (bytes altered beside the
  WARNING; the sentinel; the rename) is run as `--after-only` after a first deploy. The branches judged are
  independent of the mode (FM's A5 reading). §C gains this as item 6.
- **§T.11:3487** (TC7), a first deploy with WARNING only and the ledger's md5 other.
  - The plant is H2, altering the installed 092's bytes at `stamp_copy`, before V1. V1's boot then applies the altered
    file, so the ledger records another md5.
  - R1 FAILs too, which the case asserts and §C records.
- **`reset=1`** (TC7) is asserted on every redeploy in 107-S, not only the last.

## B. The run sequence (replaces v2's §B for 107-4 to 107-9)

107-0 to 107-3 are v2's. Their exit is 107-pre's state, asserted:
- 5.18.106 serving;
- steps 1–6 on;
- MG-G1 absent;
- no K, Q or W.1 key;
- the RH107SECRET- settings text;
- the A9 snapshot.

Each step below names its run and its entry state. *A9* means v3's corrected reset. Each plant is undone before the
next step unless the step says it stays. *Copy* means a weakened copy; each runs in the cheapest mode that reaches the
same branch, said so.

### D1 — the first deploy, PASSED, and everything judged after it (107-4, 107-6 seen=1, 107-7, 107-8 part one)

Entry: 107-pre's state (no A9 needed; MG-G1 absent asserted).

1. **The deploy.** PASSED. Asserted:
   - QF-G2's trial line and NA-G1's probes;
   - neg092 `reset=0`;
   - MG-CA1, MG-CA2 and NA-CA1;
   - R7 *applied*;
   - LK-CA2 with one row more;
   - `MG_G1=absent` and `MG_CA2_SEEN=1`;
   - ME-CA1 NOT YET, as a note.
2. **master106, then `--after-only`, PASSED.** ME-CA1 PASS with its *not judged (plugin.log: …)* clause.
3. **107-6 seen=1, each an `--after-only` with its plant undone after:**
   - the rename;
   - another md5 (S11);
   - `MG_CA2_SEEN=1` with the OK line removed and no marker, and its two copies;
   - the 071 halving, and its two copies plus its control;
   - `MG_CA2_SEEN` taken out (three stamps, two copies);
   - no `MG_G1`;
   - the bytes altered beside a WARNING, and its copy;
   - the sentinel, and its copy;
   - the sentinel with the rename;
   - the ladder edited after the copy (§T.11:3577), and its copy.
4. **107-7 on D1:**
   - the `[master]` lines;
   - (L, −1) with a later cycle, then with no cycle, then a re-dispatch (`pollrun107`);
   - an unreadable schedule;
   - the NOT YET summary (exit 3);
   - ME-CA2's three cases.

   Each plant is undone, and `master_budget_seconds` is restored. Exit is asserted by a PASSED `--after-only` after
   master106.
5. **107-8 part one: every "answer anything but ROLLBACK" case,** each a `--rollback` answered `no`, nothing changed
   (asserted by `quiet`):
   - W.1 on, through the pin's wrapper `set`, with the capitals, both commands, the redeploy sentence, the prompt
     saying *on*, and any other answer changing nothing;
   - NOT READ (`FAKE_CP_FAIL`), with step 5's capitals and its two copies;
   - TD7, K then Q on through the pin's `set_config`;
   - §M, a retrying event listed with its event ids. The off path sleeps 125 s. The real worker then answers the event,
     and a second `--rollback no` lists it empty (§T.11:3528–3529);
   - a listed number and a queued alert: the counts;
   - control 0, after the clock is moved two days on so no typed `ai.reply` is in the last day; off path, 125 s; and
     its copy.

   Then W.1 off by `BD_OFF_CMD`, run as printed under 5.18.107 (it leaves `'0'`); K and Q off by their printed
   `set_config --value 0` lines. Exit: 5.18.107 serving, PASSED, W.1 `'0'`, K and Q `0`.

### D2 to D9 — first-deploy variants (107-5, 107-6 seen=0 and nowait), each from A9

Each: A9, then the deploy, then the step's own `--after-only` runs, with master106 before any `--after-only` that must
PASS.

- **D2 — A6/H2, the plant before `stamp_copy`.** PASSED. The counter is 1, and `QF_COPY_UNIX` ≤ `applied_at` <
  `COPY_AT`. No dated note. master106, then `--after-only` PASSED. The copy (an `applied_at` < `COPY_AT` bound) runs
  as an `--after-only` and FAILS there; the deploy-run half is not repeated, said so.
- **D3 — A7, the `QF_COPY_UNIX` note.** Through `csb1run107` with `mig092`. `applied_at` < `QF_COPY_UNIX`; the dated
  note; R7 *applied*; LK-CA2 exact.
- **D4 — A8/H4, left on disk, with a WARNING planted.** H4 armed at the hold; counter 0 then 1; `applied_at` ≥
  `QF_COPY_UNIX`. The straddle note, no dated note, PASSED. Its copy (the note keyed on `applied_at` < `QF_COPY_UNIX`)
  runs as `--after-only`.
- **D5 — a WARNING beside the OK line on a plain first deploy.** The straddle note, PASSED. Its copy (keyed on MG-G1
  alone) runs as `--after-only`. Then 092's installed bytes are altered, and an `--after-only` reads:
  - R1 FAIL;
  - MG-CA2 names the WARNING as not judged;
  - no straddle note.
- **D6 — seen=0** (the log made unwritable before the run).
  - Stage R's NOT ESTABLISHED in capitals; `MG_CA2_SEEN=0`. Its copy (a note instead) runs as `--after-only`.
  - Then `--after-only` cases:
    - no marker: NOT ESTABLISHED; the copy reading MG-G1 from its own stage A;
    - a marker after `applied_at`: NOT ESTABLISHED, with two copies.
  - The log is made writable again at the end.
- **D7 — WARNING only, first deploy.** A WARNING is planted, then the log made unwritable. The straddle note beside
  NOT ESTABLISHED.
- **D8 — WARNING only, with the ledger's md5 other** (§T.11:3487). D7's plants plus H2 altering 092 before V1.
  MG-CA1 FAIL, R1 FAIL (said so).
- **D9 — nowait (H1).**
  - FAILED: MG-CA1's timed-out FAIL; `MG_CA2_SEEN=nowait`; *NO BOOT HAD APPLIED 092*; R7 *DID NOT MOVE*; the lead;
    V1's 503 recorded, not judged.
  - H1 is then removed and the ledger asserted without 092.
  - One stand-in boot, then a halving marker stamped after `applied_at` (fixture, UTC): the `--after-only` note naming
    the halving.
  - The copy writing `MG_CA2_SEEN=0` on a timeout is caught.
  - Control: the line removed and no marker reads NOT ESTABLISHED naming the wait.
- **D10 — a PARTIAL line planted before the run.**
  - MG-CA2 FAIL in the deploy run, then in `--after-only`.
  - The copy printing MG-G1's redeploy-refusal consequence, as `--after-only`.

### D11 and on — the rollback, the redeploys, and the end (107-8 part two, 107-9)

Entry for D11: A9, then a plain first deploy, PASSED, then master106 and `--after-only` PASSED.

1. **R1, a typed rollback on the off path** (W.1 absent: off).
   - Two readings 125 s apart, then the question.
   - The settings text's note: *not v9*, with the restore command.
   - The reader-control copy's v9 run of step 6 (A3) is an `--rollback no` before it, also off path.
   - ROLLBACK is typed; 5.18.106 serves.
   - RB's `NA_RB_*`: the alert acked as unknown (EP_NOAI_DONE).
   - `rb107`'s notes.
   - Then `--rollback` again: `RB_NOTHING`.
2. **RD1, a `--redeploy`, with a WARNING planted and the OK line removed before it** (§T.11:3485–3486).
   - MG-G1 present, GO, none pending; neg092 `reset=1`; R7 *same*; LK-CA2 exact; MG-CA1 *applied by an earlier
     deploy*; NS-G1 GO (W.1 absent).
   - The note, PASSED.
   - Its copy, counting the WARNING as a FAIL, as `--after-only`.
   - Then master106, and `--after-only` PASSED.
3. **R2, W.1 on and a mark written while *Type ROLLBACK* waits** (`rbhold107`), ROLLBACK typed.
   - Completes.
   - `rb_pre107`'s reading does not list the mark; `rb107` lists its conversation.
   - `--check`: NS-G1 NO-GO naming the key and its places, nothing changed.
   - A fixture clears W.1 (A13).
4. **RD2, a `--redeploy`, with every 092 line removed before it** (§T.11:3499). The note, PASSED, and `reset=1`. Then
   Q on through the pin's `set_config`.
5. **R3 — H6 with Q on.**
   - 5.18.106's tools are installed; the record does not verify.
   - The tools reading: the ladder, `sales_followup.php` and `set_config.php` *bf29cbd's*; BurstMerge *the pin's*.
   - `burst-drain` *is refused (an unknown step)*; the known-limits repair named.
   - Q's line alone, with the reason *once 5.18.106's tools are installed none can write it*; no K line.
   - The three copies, each a rollback in the same state, said so.
   - Then a fixture clears Q.
6. **RD3, a `--redeploy`** (`reset=1`). Then K on.
7. **R4 — H6 with K on.** K's line alone, no Q line. A fixture clears K.
8. **RD4, a `--redeploy`.** Then **R5 — H3**, the index lock, `--rollback`: *5.18.107's tools remain*, every command
   worded as running, *it leaves '0'* beside the switch-off. The lock is removed and the state asserted unchanged.
9. **V1 cases, through H5,** each a `--rollback` whose put-back V1 refuses:
   - V1 with no mark;
   - §K's way out: a planted leftover mark, roll back anyway, then a `--redeploy` ending in V1. *this run wrote no
     mark*, with the stage-A count; the stop lists the ids;
   - V1 with control 0: INDETERMINATE, and its copy.
10. **The put-back paragraph** in a completed rollback: off with (a) 0 and (b) 0, then on. Each step and copy is named
    in the build.
11. **The final step** (107-9 proper).
    - Under 5.18.107, `BD_OFF_CMD` is run as printed (`'0'`), then a typed rollback on the off path.
    - Then `--redeploy`: NS-G1 GO with `'0'` (S:7530); MG-G1 present; `reset=1`; R7 *same*; LK-CA2 exact; MG-CA1's
      earlier-deploy note.
    - master106, and `--after-only` PASSED, which prints the owner's steps.
    - Steps 1 and 3 are run as printed, each alone with its own log. Then the drain through the wrapper, and the
      put-back's and step 5's `set_config --value 0` lines. K and W.1 go off again through their printed off lines.
    - The one-block rule is checked over every run's output (A18).
    - Exit: 5.18.107 serving, with K, Q and W.1 off.

107-10 is v2's, with v3's additions:
- the boots' output files;
- the stand-in log's fatal check;
- the leftover reading over 106's and 107's triggers;
- the `ledger071` undo, asserted.

### Cost

D1–D11, RD1–RD4 and the final redeploy make 16 deploy runs. There are 9 typed rollbacks; 5 of them take the off
path, at 125 s each. There are about 45 `--after-only` runs and about 35 refusing runs.

At about 1.4 min a run, this is about 3.5–4 h for 107's sections, on top of the chain.

## C. §C of v2, item 6 (new)

6. §T.11's redeploy framing for 3489, 3492 and 3497 is run as `--after-only` after a first deploy. The branches judged
   are independent of the mode, and the redeploy-only notes are covered by RD1 and RD2 (FM3).

# The 5.18.107 rehearsal — section plan and settled points (27 Nov; refines root docs/76 §T.11)

Inputs:
- `map-mechanics.md`: 5.18.106's rehearsal, R6, and the chain beneath it.
- `map-cases.md`: §T.11's cases against `scripts/deploy-5.18.107.sh` at `d790edf` (S:n), pinned to the release commit
  `771a3c1` (R:).

The rehearsal is `scripts/harness/deploy-5.18.107/`, with three files:
- `rehearse.sh`;
- `rh107q.py`, its readers;
- `rh107-seed.php`, its plants, made through the release's own classes.

It is built in parts in `parts/`, reviewed, and assembled, as 5.18.106's was.

## Settled points

### Where §T.11 and the script differ

The script is right in each case. Each point below is a reading of §T.11, and the rehearsal asserts what the script
prints. None changes a decision of §T.10.

- **S1 — the control-0 reading before ROLLBACK** (map (1).3). §T.7 step 4 counts a control of 0 among the
  "otherwise" states. `rb_pre107` prints:
  - the counts with *control 0*;
  - the capitals *ANSWER ANYTHING BUT ROLLBACK BELOW*;
  - the drain command, alone;
  - §K's rule, *a reading whose control is 0 does not count*;
  - no *drain holds* note.

  The word INDETERMINATE belongs to the put-back's `rb_bd_staff` and to V1's stop. §T.11's "INDETERMINATE" names the
  state, and the case asserts the five items above.
- **S2 — ME-CA2 and `notify_retry`** (1).1. The `note ME-CA2 the seven other jobs…` line always prints. The case
  asserts that there is no `ME-CA2 FAIL` line and that the note does not name `notify_retry`.
- **S3 — ME-CA1 with no new cycle** (1).2. The text is *master did not start a new cycle within the poll's bound*. The
  poll's bound is the budget plus 360 s, and a budget below 0 counts as 0 (S:8377–8379). That case therefore costs at
  least 360 s of real time whatever budget is planted. The plant is `master_budget_seconds` = 0, said so.
- **S4 — the K/Q reason** (1).4. The text is *once 5.18.106's tools are installed none can write it*. The old reason,
  *after the put-back no shipped tool can write these keys*, appears nowhere, and that is asserted.
- **S5 — "nothing is saved"** (1).5.
  - Where a command is refused, the assertion is the script's *A refusal changes nothing and saves nothing*, printed on
    the §K-rule line whenever the switch-off or the drain is not *runs*.
  - Where every command runs, the assertion is that this sentence is absent.
  - For a single line, the assertion is its own wording: *runs, and leaves '0'*, *is refused (undeclared)*, *may be
    refused*.
- **S6 — the tools reading not made** (1).6. Each of the four files reads *not read* (`pb_word`). That is the
  assertion.
- **S7 — the settings text** (1).7. The workers' view prints *NOT v9 as the workers read it* in capitals. The store
  row's part prints *not v9*.
- **S8 — `--rollback` with the pin unavailable** (1).8. K and Q read *COULD NOT BE READ*, from `lk_read`'s
  `lk=nopin`.
- **S9 — the drain's ids** (1).9. `rb107`'s note lists conversation ids. The event ids are asserted in `rb_pre107`'s
  readings (`rb_bd_ids`).
- **S10 — `MG_CA2_SEEN=1`, no marker** (1).10. The branch is told apart by *is no trim marker*. The assertion is that
  *the runner halves the log* is absent, never that the word "halving" is absent.
- **S11 — 092 with another md5** (1).11. R7 FAILs as well (*the migration ledger CHANGED*), because `db_mig107`
  compares the checksum prefix. The case asserts both FAILs and the summary's lead.
- **S12 — MG-CA2's NOT ESTABLISHED lines are notes** (1).12. A case whose only fault is MG-CA2's reads PASSED.
  §T.11's "never a PASS" is about MG-CA2's own line.
- **S13 — 092 applied between the extraction and `stamp_copy`** (1).13. The script sets no bound against `COPY_AT`.
  MG-CA1 PASSES with no dated note, because the note keys on `QF_COPY_UNIX` and here `applied_at` ≥ `QF_COPY_UNIX`.
  The weakened copy adds the bound (`applied_at` < `COPY_AT`) and must FAIL.
- **S14 — NS-B1's stop** (1).14. *…turned on during this run, after stage A. Nothing was copied.*, then `stop`'s
  *Nothing further was done.*, exit 1.

### The first boot and the copy

- **S15 — what boots the store.** `deploy-hybrid.sh` extracts with tar, writes `.deployed-commit` and reads it back
  through `docker exec`; it boots nothing. The stand-in `public.php` boots the installed store on every request. The
  first request after the copy is V1's `GET ?page=customer_login` (S:13917), after `stamp_copy`. Every deploy in the
  sandbox therefore applies 092 at V1, and MG-CA1's 60-s wait (S:8410–8414, after R1 at S:14456) finds it.
- **S16 — no first boot** (hook H1). A trigger `$SB/web/.noboot107` makes the stand-in `public.php` answer 503 with a
  fixed body, without booting, for as long as it exists. It is never consumed. It is cleared by the case and by the
  EXIT trap.
  - The case asserts MG-CA1's timed-out FAIL, `MG_CA2_SEEN=nowait`, MG-CA2's *NO BOOT HAD APPLIED 092 WHEN THE LOG WAS
    READ*, and the summary's lead.
  - V1–V7's own lines in that run are recorded, not judged: the container answering 503 is the condition planted.
  - The later `--after-only` case removes the trigger and makes one request (the boot), then plants the halving.
- **S17 — the plant between the extraction and `stamp_copy`** (hook H2). 5.18.106's A1 touch shim
  (`$SB/datebin/touch`, active under `RH106_TOUCH`) gains one asserted insertion. On its first call in a run, while a
  one-shot trigger `$SB/.plant092touch107` exists, it:
  1. consumes the trigger;
  2. applies 092 to the live book through the INSTALLED code's runner (the pin's, already extracted), by one boot of
     the stand-in;
  3. moves the rehearsal's clock forward by 3 s (`clk_to104`);
  4. then stamps.

  The case asserts `applied_at` < `COPY_AT` before it judges anything.
- **S18 — the `QF_COPY_UNIX` plant.** It goes through `csb1run106`'s hold (renamed `csb1run107`, the same mechanics):
  092 is applied to the live ledger while *Type DEPLOY* waits.
  - The live code is 5.18.106's, and 5.18.106's runner applies any `.sql` in `migrations/`. So the plant is
    `rh107-seed.php mig092`: the pin's `MigrationRunner` over the live book, a fixture writer, said so.
  - LK-B1's 6-s pause follows. The case asserts `applied_at` < `QF_COPY_UNIX` (whole seconds).
- **S19 — 092 left on disk, a first deploy.** A fixture copies the pin's `migrations/092_wa_no_ai.sql` into the live
  plugin's `migrations/` before the run, said so. The ledger has no 092 row, so MG-G1 reads it absent. One boot of
  the stand-in, held at *Type DEPLOY* as in S18 (after `QF_BASE`, before the tar), applies it under 5.18.106's runner.
  The case asserts `applied_at` ≥ `QF_COPY_UNIX`. With a 092 checksum WARNING planted beside the OK line, it reads the
  straddle note and no dated MG-CA1 note, and the run PASSED.

### Production's state before 5.18.107

- **S20 — production's state.** R6 is cut before 106-10. At the cut 5.18.106 serves, from 106-7i6's `--redeploy`:
  - steps 1–5 are on and step 6 (chase) is off;
  - `followup_enabled` is on;
  - `followup_auto_send` is off;
  - the latest rollback record is V1's.

  107-pre makes production's state:
  1. on chase through the installed tool, yes typed (`sf104c --tty yes on chase`), so the ladder reads steps 1–6 on
     and the sends are in claim mode;
  2. 5.18.106's `--after-only` PASSED (`aft106`);
  3. the quarantine's `--check` PASSED (`qcheck106`);
  4. the settings text at v9's bytes, through the real `set_config` (W.2's precondition, §T.5);
  5. no `wa_no_ai` table and no 092 anywhere.

  Each step is asserted.
- **S21 — K, Q and W.1 off by default.** Production has never seen these keys (§N.1). A first deploy over 107-pre
  reads them absent, which NS-G1 accepts as off. Every case that turns one on is planted through a real writer:
  - 5.18.107's `set_config` once the pin serves;
  - before that, a fixture writing the place the reader reads, said so, because 5.18.106's tools refuse the keys.

### Harness machinery

- **S22 — W.8 after a snapshot restore.** The record run builds the sandbox from the root at HEAD, which carries
  `cc6a714`, so W.8 is in place.
  - A development run restored from a snapshot made before `cc6a714` re-applies the one line, asserted: either the
    fixed line is there once, or the old line `--clear-groups --); fi` is there once and is replaced. Its control is
    the root's own: `printenv PATH` through `docker exec -u`.
  - 107-0 asserts W.8 in either shell.
- **S23 — the cut.** R6 is read up to the exact 106-10 heading (`map-mechanics.md` §c), asserted:
  - once;
  - the last `== 106-` heading before it is 106-7i's;
  - the last code line before it is the 106-7z RH-NOTE echo;
  - `H106_DIR=` is re-pointed once, followed by `H106_ROOT=`.

  The result is written to `H107_PREFIX`, sourced and removed. That block is the development runner's skip block.
  - Every R6 function and variable this file uses is asserted defined.
  - `FAIL_PRE107` holds the chain's failures before 107's sections.
- **S24 — the numbers.** 107's own range is `+2567001070NN`, proved free by a grep over every harness, test and
  fixture, and appended to the pattern files for the sweeps. Every text is an `RH107SECRET-` value.
- **S25 — weakened copies.** These are `anc107` and `weak107`, the same mechanics as R6's, run with
  `R107_MUTANT_OUT=1`. Each is asserted caught by its case's own observable.
  - K1–K7's copies are the derivation's (108 copies, 16 pass controls), run on every derivation. The rehearsal does
    not repeat them, and says so.
- **S26 — sleeps and time.** These cases spend real time, because the script sleeps:
  - `rb_pre107`'s 125 s, once per off-path rollback case;
  - MG-CA1's 60-s wait, once per timed-out case;
  - LK-B1's 6 s, on every deploy;
  - ME-CA1's poll, at least 360 s per poll case.

  The plan counts them; there are no shortcuts.
- **S27 — the clock.** Everything the script compares with `COPY_AT`, `QF_COPY_UNIX` or a ledger `applied_at` is
  stamped by the rehearsal's clock (LD_PRELOAD) or by the touch shim. The kernel's own time never enters a comparison.
  The cases assert their order (strict, whole seconds) before judging.

## Sections

The case lists are §T.11's. Each case says its plant, its observable (S:line), and its weakened copy, if any.

- **107-0 — guards.**
  - Not on the server.
  - `F107` is pinned: short and full commit, and `T1_SIGNATURE`.
  - The helpers parse, and no `__pycache__` sits beside them.
  - The verbs guard holds, with its planted controls. 5.18.107 adds no verb: the K, Q and W.1 commands are printed,
    never run.
  - `deploy-5.18.106.sh` is pinned to `BASELINE_COMMIT` `bf29cbd`.
  - The wrappers are committed and equal to HEAD.
  - The derivation is interim: the rehearsal records which MEASURED values it measures (UG_PIN_SIGNATURE,
    EP_NOAI_PIN_UG, EP_NOAI_DONE: from A6 and R13 in 107-4) and prints them for the derivation, never writing them.
- **107-cut and 107-infra.** These are S22 and S23, plus:
  - `run107` and `run107c`: run106 re-pointed, with the state file `state-5.18.107.env` and its keys;
  - `aft107`, `rbx107`, `dredeploy107`, `v1run107`, `csb1run107`;
  - `seed107` (`rh107-seed.php`);
  - hooks H1 (public.php), H2 (the touch shim), and H3, the `.git/index.lock` for a rollback's checkout (R6's A7
    pattern);
  - the 107 number range;
  - the EXIT trap, which removes 107's triggers first, then the chain's cleanup.
- **107-pre.** S20, each step asserted.
- **107-1 — a plain 5.18.106 tree** (§T.11 "A plain 5.18.106 tree"). One changed file other than the four tools files
  is edited, said so, giving `BASE_DIFFERS` (S:12399).
  - The tools reading names the three present files *bf29cbd's* and `lib/BurstMerge.php` *absent*.
  - Commands are worded refused; *it leaves '0'* is never said; K and Q *cannot be read* with *is refused
    (unmanaged)*.
  - The file is put back and checked put back.
  - Then the mixed-tree cases at the same stop: only the ladder the pin's; `set_config` the pin's; `set_config` edited
    by hand; the three present files removed (not read); the ladder the pin's with the tool removed; the stage-A
    mixed-tree with `P3B_PIN` unset; BurstMerge absent; BurstMerge edited.
  - Each is planted with its tree and put back. Each weakened copy named in §T.11 is caught.
- **107-2 — MG-G1, QF-G2, NA-G1**, all before any 5.18.107 copy:
  - the pairing (a fixture creates `wa_no_ai` with no ledger row), with two weakened copies;
  - a partial 092 (a fixture writer through the pin's runner on a planted bad copy of the file);
  - 092 present with other bytes;
  - each NO-GO with nothing changed.
- **107-3 — NS-G1, NS-B1, and stage A's notes:**
  - each switch on in each place its reader reads (p, d, k, s), each refused, with its weakened copy;
  - the in-memory positive control's weakened copy;
  - `'0'` present → GO; the store row alone → GO with the note; a text key set → the note;
  - NS-B1 through `csb1run107`;
  - settings text v9, then not v9; StaffJobsGate false.

  Fixtures write before 5.18.107 serves (S21).
- **107-4 — the first deploy, PASSED.**
  - QF-G2's trial line and NA-G1's probes; neg092 `reset=0`; MG-CA1, MG-CA2 and NA-CA1; R7 *applied*; LK-CA2 with one
    row more.
  - The state file holds `MG_G1=absent` and `MG_CA2_SEEN=1`.
  - The rehearsal's own reading of the A6 and R13 values (the event processor's signatures) is recorded for MEASURED.
  - Then `--after-only` PASSED: ME-CA1 PASS with its *not judged (plugin.log: …)* clause, after master's four jobs.
- **107-5 — the deploy variants.** Each on a fresh book state, rolled back to 5.18.106 between them by the typed
  rollback (S26 counts the waits):
  - the plant before `stamp_copy` (S17) with its weakened copy;
  - the `QF_COPY_UNIX` note (S18);
  - the WARNING beside the OK line (straddle), with its weakened copy, and the same with 092's installed bytes altered;
  - the left-on-disk first deploy (S19), with its weakened copy.
- **107-6 — failures after the copy, and MG-CA2's missing line.**
  - Failures: `wa_no_ai` renamed; another md5 (S11); no first boot (S16); the PARTIAL line.
  - MG-CA2's missing-line cases, in §T.11's order, each with the weakened copies it names:
    - stage R with the line removed;
    - `--after-only` with no marker;
    - the log halved with the marker after `applied_at`;
    - `MG_CA2_SEEN=1` with the OK line removed;
    - `--after-only` after the timed-out deploy;
    - `MG_CA2_SEEN` taken out;
    - no `MG_G1`;
    - a redeploy with WARNING only;
    - a first deploy with WARNING only;
    - the bytes altered beside the WARNING;
    - the sentinel row;
    - the rename;
    - a redeploy with no line.
  - Each fault is planted by a fixture, said so, and undone (A9).
- **107-7 — ME-CA1 and ME-CA2.** §T.11's list:
  - planted `[master]` lines (stamped in `dn_tz`, not UTC);
  - (L, −1) with a later cycle;
  - (L, −1) with no new cycle (S3);
  - a re-dispatch between two reads;
  - an unreadable schedule;
  - the NOT YET summary (exit 3);
  - ME-CA2's three cases (S2).
- **107-8 — the rollback.**
  - `rb_pre107` off, with no marks: two readings 125 s apart, then the question.
  - On: the capitals, the commands, the prompt saying *on*.
  - On, with a mark written after the reading, then ROLLBACK: `rb107` lists the mark's conversation id (S9), and a
    later `--check` is an NS-G1 NO-GO.
  - NOT READ, with step 5's capitals (S8), with its two weakened copies.
  - Control 0 (S1).
  - §M's assertion: a retrying event listed, then empty once answered by the real worker.
  - TD7, with K then Q on.
  - The settings text not v9.
  - Listed numbers and a queued alert, acked as unknown after the rollback (EP_NOAI_DONE measured here).
  - V1 with no mark, and a `--redeploy` after §K's way out with a leftover mark, ending in V1.
  - V1 with control 0 (INDETERMINATE), with its weakened copy.
  - `rb107` and `RB_NOTHING`.
  - The put-back paragraph, off and on.
  - The put-back after a rollback that did not verify (H3), with Q on, then K on, with the weakened copies.
  - A put-back where 5.18.107's tools remain (the rollback's checkout made to fail, H3).
  - The `--after-only` summary with the ladder edited after the copy.
- **107-9 — `--redeploy` after the rollback, and the printed commands.**
  - The redeploy reads MG-G1 present, GO, none pending; neg092 `reset=1`; R7 *same*; LK-CA2 exact; MG-CA1's *applied
    by an earlier deploy*; NS-G1 GO with `'0'`.
  - Then the printed commands, run as printed, each alone, each with its own log: owner steps 1 and 3, the drain
    through the wrapper, and the put-back's and step 5's `set_config --value 0` lines.
  - No summary prints a deploy and a rollback in one block (asserted over every run's output).
- **107-10 — the sweeps.**
  - 106-10's sweeps, re-read with 107's range and records added: Evolution, uCRM, the three sweeps, the LK keys, the
    numbers, the fixtures' leftovers, the failure files, the end state.
  - No weakened copy is left behind, and no trigger file.
  - The RESULT line.

## Open, for the review

- O1. Is S15's "first boot at V1" true in every 5.18.107 run mode the cases use (`--redeploy`, `--after-only`, the
  rollback's put-back)? The `--after-only` makes requests too. Which run boots first after a rollback?
- O2. S16's 503: does V1's handling of 503 change any line that MG-CA1 or the summary's lead reads? Is MG_SAY still
  `na`/`unk` as §T.11 expects?
- O3. S17: is the touch shim's first call in a run really `stamp_copy`'s? Does anything in the script call `touch`
  earlier under `RH106_TOUCH`?
- O4. S19: does 5.18.106's `MigrationRunner` (bf29cbd) apply a `.sql` it finds on disk at a boot, as S19 assumes, and
  write the same `migration.log` OK line?
- O5. 107-5/107-6 need many deploys and rollbacks. A rollback's `rb_pre107` sleeps 125 s on the off path. Can the
  cases be ordered so that most share one deploy (read-only plants undone between `--after-only` runs)?
- O6. MEASURED: where exactly do A6 and R13 print the values the derivation needs (UG_PIN_SIGNATURE, EP_NOAI_PIN_UG,
  EP_NOAI_DONE), and can the rehearsal read them without parsing the cash sheet?

# The 5.18.107 rehearsal — design v2 (27 Nov; refines root docs/76 §T.11)

v1 (`design-skeleton.md`) was reviewed by two critics (`design-review1.md`: RM1–RM18 and RC1–RC24). Every finding is
taken here; where one is narrowed, it says so.

Notation:
- S:n — `scripts/deploy-5.18.107.sh` at `d790edf`, pinned to `771a3c1`;
- R6 — 5.18.106's rehearsal;
- root — `scripts/harness/quarantine-stray-store/rehearse.sh`.

The settled points of v1 that the review verified (S3, S4, S8, S10, S11, S14, S16's text, S23, S25) stand as written
and are not repeated. Every other point is restated here.

## A. Settled points

### A1–A3: the clock, the gate, the settings text

**A1 — the stand-in web server runs on the rehearsal's clock** (RM1, RC1; replaces v1's S27).

The chain starts `php -S 127.0.0.1:$WPORT -t $SB/web` without LD_PRELOAD (root:285, :930), and nothing later restarts
it. Every boot through it stamps 092's `applied_at` (SQLite's `datetime('now')`, MigrationRunner.php:59) and the
migration.log lines with the kernel's time. `QF_COPY_UNIX` and `COPY_AT` are on the rehearsal's clock, about forty days
ahead. Left so, every first deploy would print the dated note *092 was applied before this copy began* (S:8435).

107-infra therefore stops `$WEB_PID` and relaunches the same server:
- as uid 1000;
- with `LD_PRELOAD=$CLKPRE104 RH104_CLOCK_FILE=$CLKF104`, in the form of 104:2079;
- re-registered in `PIDS` and `WEB_PID`.

It is idempotent, so it works in either shell: a snapshot restore brings back the old environment (devres106:64–71).

Controls:
- A probe page `rh107c_probe` answers PHP `time()` and SQLite `strftime('%s','now')`. Both must be within 2 s of
  `fnow104` after the relaunch.
- The control on the control: before the relaunch, the same probe reads the wall clock, more than an hour behind.

From here on, every comparison the script makes between `COPY_AT`, `QF_COPY_UNIX`, a ledger `applied_at` and a
migration.log stamp is between two times on the rehearsal's clock. Each case asserts its order (strict, whole seconds)
before judging anything.

**A2 — the interim values are measured first** (RM5).

A6 runs in every deploy and every `--check`, before stage A's other gates (S:13100–13145). It stops NO-GO when what it
reads differs from the interim literals (S:1532, :1538–1539, :13136–13142). So a wrong interim value stops the
rehearsal at its first deploy.

The values print on plain console lines before A6's verdict:
- UG_PIN_SIGNATURE — S:13128 *pin 5.18.107: …*;
- EP_NOAI_PIN_UG — S:13132 *pin …: … (Uganda), … (South Sudan)*;
- EP_NOAI_DONE — S:13131 *live 5.18.106: … (Uganda), …*. The pin's South Sudan reading must equal it as well.

The measurement comes in two steps:
1. **M0, before the rehearsal, outside it.** A6's own probes (`ep_run` on `$PIN_ROOT` and on `bf29cbd`'s tree, Uganda
   and South Sudan, as S:13100–13132 builds them) are run on a plain checkout of each commit. If a value differs from
   the derivation's interim literal, the derivation's MEASURED entry is corrected with its source, the script is
   re-derived, and only then does the rehearsal start.
2. **107-pre's `--check`.** It parses the four lines and asserts they equal what the script pins. A difference stops
   the rehearsal there, said so. That run is the in-sandbox measurement the derivation records as *measured*. R13 and
   RB are the later cross-checks.

**A3 — the settings text** (RM2, RC2; replaces v1's S20 step 4 and S7).

`SA_TXT_V9` is a 48-bit prefix of v9's sha256 (S:8028). v9's bytes exist only on production, and neither release's
`set_config` manages `bot_custom_instructions` (bf29cbd `$FLAGS`). So:
- Every run reads the plant's text as *NOT v9 as the workers read it* (only NOT in capitals, S:8066) and *the store
  row: not v9*. The plant is an RH107SECRET- text written by a fixture (`rh107-seed.php settext`), said so.
  - §T.11's "settings text not v9" cases are those runs.
  - 107-8's "the settings text not v9: step 6's note naming the restore command" is asserted in the off-path run, at
    no extra cost.
- The v9 branch is shown once, by a reader control: an `anc107` copy replaces `SA_TXT_V9="fca936af7760"` with the
  prefix of the planted text.
  - The copy is asserted to differ in that one line only.
  - It is labelled a reader control, never a production reading.
  - It runs in 107-3 (the stage-A note *v9 as the workers read it*) and once for `rb_pre107` step 6.
- This is a §T.11 difference for the owner. It is listed in §C.

### A4–A8: the copy, the boot and the plants

**A4 — what boots the store** (v1 S15, verified by RM18).

`deploy-hybrid.sh` extracts with tar, writes `.deployed-commit`, and reads it back through `docker exec`; it boots
nothing. The stand-in `public.php` boots the installed store at its top (root:224–226). The first request of every run
mode is V1's `GET ?page=customer_login` (S:13917), after `stamp_copy` in a deploy.

In `--rollback`, V1 boots `bf29cbd`'s code over the 092 left on disk. 092 is recorded already, so the runner skips it,
or logs the checksum WARNING if its bytes differ. MigrationRunner.php and SqliteStore.php are identical at `bf29cbd`
and `771a3c1` (RM12, O4), so the OK, PARTIAL and WARNING lines and the 7-statement count are the same whichever release
boots.

Between runs, the rehearsal's own tools also boot the store (RM13): master106, sender106, set_config through
PluginConfig's store-row mirror, sf104c. Before each intended boot, the ledger is asserted to hold no 092.

**A5 — the hooks.** Each is an asserted insertion into a stand-in, in R6's pattern:
- the anchor is found exactly once;
- the order relative to the chain's own hooks is asserted;
- the file is checked with `bash -n` or `php -l`;
- there is a control with the trigger absent, a check with it present, and a check after removal.

Every trigger is removed by `cleanup107` (the EXIT trap, before the chain's) and swept by 107-10.

| Hook | Stand-in | Anchor | What it does |
|---|---|---|---|
| H1 `.noboot107` (RM10) | `$SB/web/public.php` | before the `foreach (['bootstrap_data', …` line that precedes `$store = SqliteStore::create($dataDir);` (each once; order asserted) | While present (never consumed): 503 with a fixed body, no boot. With it: a pre-boot probe `rh107b_probe` that never boots. Control: a request under H1 leaves the ledger without 092 |
| H2 `.plant092touch107` (RM11) | `$SB/datebin/touch` (R6:589–598) | inside the `RH106_TOUCH` branch (R6:593) | On its first call while the trigger exists, once: consumes the trigger; boots `SqliteStore::create($DATA)` from `$PD/lib` as uid 1000 with the run's LD_PRELOAD (clocked); adds 3 to the integer in `$CLKF104`, forward only, keeping the file's mode (never `clk_to104`, never `date` inside the shim); bumps `$SB/plant092touch107.fired`; then stamps. Own controls: absent → passes through; present → fires once, consumed, counter 1 |
| H3 `.git/index.lock` (RM4) | `$REPO/.git` | — | Planted directly before a `--rollback` run, so do_rollback's checkout fails before deploy-hybrid (S:13832): *5.18.107's tools remain* (§T.11:3552). R6:780–786 is the control |
| H4 `.boot107` (RM3) | `$SB/bin/docker` (root:426) | before the line beginning `row() { grep "^$1\|" ` (root:429), after `.rbfail104` and `.ujfail106` (order asserted) | One-shot. Fires on exactly `exec ucrm cat /data/ucrm/data/plugins/dishnet-hybrid-sudan/.deployed-commit` while `$PD/.deployed-commit` names P106: boots the store as H2 does (5.18.106's code applies the 092 on disk), counts itself, passes through. That read is deploy-hybrid's `read_live 3` (deploy-hybrid.sh:86), the only container call between `QF_BASE` and the tar |
| H5 `.redirect107` and `.redirect107lock` (RM6, RC7) | `$SB/web/public.php` | before `$page = $_GET['page'] ?? '';`, after `.redirect106`'s line (asserted) | Gated on the installed manifest saying 5.18.107; one-shot, spent by the root's `$spend()`; the lock variant touches `$REPO/.git/index.lock`, as R6's A7. Probe `rh107_probe`, polled for OPcache (R6:674–679, :717) |
| H6 `.rbfail107` (RM4, RC7) | `$SB/bin/docker` | as `.rbfail104` (104:2505–2520), keyed P107 → P106 | One-shot: the first `.deployed-commit` read naming P106 after one naming P107 answers `0000000`. deploy-hybrid exits 1 after copying (deploy-hybrid.sh:144–150), with no rc=2 wait. Gives *a rollback that did not verify, with 5.18.106's tools installed* (§T.11:3542) |

The post-boot faults go to `--after-only` and need no hook (RM8; RC5 narrowed). §T.11's faults "after the boot"
(`wa_no_ai` renamed; another md5; 092's installed bytes altered; the sentinel row) are planted after a PASSED deploy
and judged by an `--after-only`. MG-CA1, NA-CA1, MG-CA2, R1 and R7's second judgement (against the deploy's record,
S:14877) all run there. With 092's bytes altered, the `--after-only`'s own V1 boot writes the real checksum WARNING
(MigrationRunner.php:192–196): a real writer, not a fixture.

The deploy-run plants are only these:
- before the run: the log made unwritable (`chmod 0444 $DATA/migration.log`; the boot's `@file_put_contents` fails
  silently, MigrationRunner.php:313–319, so `MG_CA2_SEEN=0`); a PARTIAL line; a WARNING line. mg_after greps the whole
  log;
- at `stamp_copy`: H2;
- at the DEPLOY hold: A7;
- at deploy-hybrid's read: H4;
- no boot at all: H1.

§C records this narrowing.

**A6 — the plant before `stamp_copy`** (v1 S13 and S17, with RM11 and RC16).

H2 fires at `stamp_copy`'s first `touch -c`, the script's only touch (S:13827, RM11 O3). That is after the tar and
before V1. The case asserts, in order:
1. the counter is 1;
2. `applied_at` < `COPY_AT`;
3. `applied_at` ≥ `QF_COPY_UNIX`, which makes "no dated note" meaningful.

MG-CA1 then PASSES with no dated note, in the deploy run and in a later `--after-only` (RC13: §T.11:3428). The
weakened copy adds the bound `applied_at` < `COPY_AT` to MG-CA1 and must FAIL in both runs.

**A7 — the `QF_COPY_UNIX` plant** (v1 S18, with RM12).

`csb1run107` holds at *Type DEPLOY* (S:13852), before `qf_keep_and_baseline` (LK-B1, 6 s, then `QF_BASE` at
S:7576–7578). Its action is `rh107-seed.php mig092`: the pin's `MigrationRunner` over the live book, said so. It is run
as uid 1000 under the clock and given `$DATA/migration.log` (the runner's default is the code folder's `data/`,
MigrationRunner.php:51). It asserts one OK line for 092. `csb1run107` records the seed's own exit code, not `sfrc104`.

The case asserts `applied_at` < `QF_COPY_UNIX` before judging. Expected:
- MG-CA1 PASS with the dated note *applied before this copy began*;
- R7 *applied*;
- LK-CA2 exact.

In 107-9's `--redeploy` the note reads *applied by an earlier deploy*.

**A8 — 092 left on disk, a first deploy** (v1 S19, RM3, RC3).

A fixture copies the pin's `migrations/092_wa_no_ai.sql` into the live plugin, said so; the ledger has no 092 row.
Under H4, 5.18.106's runner applies it at deploy-hybrid's `read_live`, after `QF_BASE` and before the tar.

The case asserts, in order:
1. H4 fired once;
2. `applied_at` ≥ `QF_COPY_UNIX`;
3. the ledger held no 092 before the run.

With a 092 checksum WARNING planted beside the OK line, it reads the straddle note, no dated MG-CA1 note, and the run
PASSED. The weakened copy keys the note on `applied_at` < `QF_COPY_UNIX` and so reads no note; it is caught.

### A9–A10: resets and concurrent writers

**A9 — the first-deploy reset** (RM7, RC6).

A typed rollback writes nothing: 092 stays in the ledger and on disk, so the next MG-G1 reads present. Between
first-deploy variants the rehearsal resets instead, in this order:
1. `install_commit $B106`, root:297 (asserted defined), with the chown as root:929;
2. `drest107`: 104's `drest104` (104:1030–1042) of the snapshot taken at the end of 107-pre — `plugin.sqlite3`,
   `migration.log`, the config files, sha256-checked;
3. the planted on-disk 092 removed, said so;
4. `tidy107` of the state file, the LK key and the backups (in R6:323's shape);
5. `qcheck106`;
6. the assertion that MG-G1 reads absent (a `--check`) and that no 092 file is on disk.

Nothing may boot the store between the restore and the deploy (A4). Every case in 107-5 and 107-6 is tagged *first
deploy* or *redeploy*, with its entry state.

**A10 — the concurrent writers** (RM9, RC17). Each is a csb1run-shaped runner (`run106c --hold`) keyed on a line of the
stream:
- `pollrun107`, keyed on *ME-CA1 waiting up to*. Its action writes `master_schedule` through `rh107-seed.php` (a later
  entry, or a re-dispatch), then moves the clock past the poll's bound (`clk_to104` in the rehearsal's shell).
  - The poll measures with `date -u +%s` under LD_PRELOAD (S:8381–8382), so the loop ends after one `sleep 5`.
  - me_after_poll judges entries only (S:8282–8294), so the texts are unchanged.
  - This replaces v1's S3 cost: no case spends 360 s (RM9, RC23).
- `rbhold107`, keyed on *Type ROLLBACK* (S:13898). It writes a burst mark through `BurstMerge`'s own writer while the
  question waits (§T.11:3518).

### A11–A14: §T.11 read against the script

**A11 — v1's S1, S2, S5, S6, S9 and S12, corrected.**

- **S1, control 0** (RC10). The plant: the switch off, (a)=0 and (b)=0 in both readings, and no typed `ai.reply` in
  the last day (control 0), said so. Asserted:
  - *control 0* in both RB107 readings;
  - no *drain holds* note;
  - the capitals;
  - the drain command alone;
  - §K's rule text.

  The weakened copy drops `control != 0` from `rb_bd_zero`; it is caught by *drain holds* appearing.
- **S2, ME-CA2 and `notify_retry`** (RC11). Asserted: no `ME-CA2 FAIL` line. The positive control is ME-CA2's first
  case (the same plant on one of the seven: FAIL). The "does not name `notify_retry`" assertion is dropped: the script
  never prints it.
- **S5, the refusal sentence** (RC12).
  - A running switch-off: *runs, and leaves '0'*, with no *Where the switch-off does not run* (S:9108).
  - K/Q worded *runs*: their *take nothing as saved.* line (S:9117) ends without *A refusal changes nothing*. The
    sentence on the §K-rule line is expected whenever the switch-off or the drain is not *runs* (S:9107).
- **S6, the tools reading not made** (RC8). Three files read *not read*, `lib/BurstMerge.php` reads *absent* (`pb_tool`
  S:9066), and all three commands read *may be refused*.
- **S9, the ids** (RC18).
  - In §T.11:3518's case the mark is written after `rb_pre107`'s reading. The assertion is that the reading does not
    list it (the timing control) and that `rb107`'s note lists its conversation.
  - Event ids are asserted in §M's case (§T.11:3530), where the mark exists before the reading.
- **S12, a lone MG-CA2 note** (RC24). A case whose only finding is MG-CA2's NOT ESTABLISHED note reads PASSED.

**A12 — no vacuous assertion** (RC11). These strings are never printed by the script:
- *nothing to drain*;
- *no mark to drain*;
- the old K/Q reason;
- `notify_retry` in ME-CA2.

Asserting their absence counts only as the observable of a named weakened copy that prints them. §T.11:3545's
old-reason copy exists. For the put-back's and V1's wordings, add copies that print the forbidden text, or say there
are none. The substantive assertions are the positive texts:
- *once 5.18.106's tools are installed none can write it* (S:9115);
- *One reading, not a drain.* (S:9124);
- *this run wrote no mark*, with the count.

**A13 — the writers for K, Q, W.1 and the text keys** (RM14, RC15).
- **The places.** NS-G1 judges p, d and k, and reports the store row s without judging it (S:6420–6423, S:7517–7531).
  So 107-3 refuses p, d and k, and *the store row alone → GO with the note* is its own case (RC14).
- **No release writes p or d.** Before 5.18.107 serves, no tool writes any of the keys; 5.18.106's tools refuse them.
  Fixtures plant them, said so:
  - rh106-seed's `fx_file` for p, d and k;
  - a new `rh107-seed.php` store-row op for K, Q, W.1, `no_ai_alert_cooldown_hours` and the text keys.
- **Once 5.18.107 serves:**
  - K and Q are written through 5.18.107's `set_config`;
  - W.1 is written through the switch tool's `set`, via the pin's wrapper (S:7330–7332; 771a3c1
    tools/sales_followup.php:103, :147–151).
- **Clearing a switch after rollback.** After every switch-on rollback, K, Q and W.1 are cleared by a fixture writing
  `kyc_config.json` and the store row, the known-limits route, said so (RC9).
- **The state-file helper.** `dstdrop107` works on `state-5.18.107.env`.

**A14 — `--rollback` with the pin unavailable** (v1 S8, RM15). `run107 --env FAKE_CP_FAIL=1` (root:456) makes every
`docker cp` fail. P3B_WHY then reads *the copies could not be put into the container's /tmp* (S:12537), and `lk_read`
gives `lk=nopin`. Asserted:
- the P3B_WHY text;
- *SWITCH NOT READ*;
- K and Q *COULD NOT BE READ*.

The run's other `docker cp`-dependent lines are recorded, not judged.

### A15–A18: production's state, machinery, time, numbers

**A15 — production's state** (v1 S20, with RM16 and A3). 107-pre runs, each step asserted:
1. *on chase*, through the installed tool, yes typed (`sf104c --tty yes on chase`). The ladder then reads steps 1–6
   on, and sends are in claim mode.
2. `master106`, then `aft106 "107-pre…" PASSED $L2ON106`. `chase=judged` accepts NOT_YET, which 5.18.106 prints as a
   note (deploy-5.18.106.sh:8442). R6 never ran this with chase on; it is proved here.
3. `qcheck106` PASSED.
4. The settings text: the RH107SECRET- plant (A3).
5. No `wa_no_ai` table and no 092 anywhere.
6. A2's `--check`.
7. The snapshot A9 restores.

**A16 — machinery** (v1 S22–S26, with RC22).
- **W.8.** §W.8's four controls are carried (root:949–965):
  - `printenv PATH`;
  - `rm` at `/usr/bin`;
  - a uid-1000 delete exiting 0;
  - the sed-undone copy exiting 2, with its file kept.

  In a restored shell the one-line edit is re-applied first, asserted.
- **The runners.** `run107` and `run107c`: run106 re-pointed, with the state file `state-5.18.107.env`. Also `aft107`,
  `rbx107`, `dredeploy107`, `v1run107`, `csb1run107`, `pollrun107` and `rbhold107`. Each has R6's contract, rc kept.
- **The seed.** `rh107-seed.php` takes R6's ops plus these:
  - `mig092` (A7);
  - `fx092disk` and `fx092disk_undo` (A8);
  - `settext` (A3);
  - `store_keys107` and `store_keys107_undo` (A13);
  - `mark` (a burst mark through BurstMerge);
  - `sched` (a master_schedule entry);
  - `budget` (`master_budget_seconds`);
  - `ledger092` (rename the table; rewrite the checksum; insert a sentinel row; each with its `_undo`);
  - `logline` (append a PARTIAL or WARNING line, remove a line, or halve the log with the runner's marker, stamped from
    the ledger's `applied_at` and never from the clock, RM13; each with its `_undo`).

  Every op writes through the release's own classes where one exists, and says *fixture* where none does.

**A17 — time** (RM17, RC23). The script's real sleeps:
- LK-B1's 6 s, plus `GUARD_SECONDS` 3 s, per deploy past DEPLOY;
- MG-CA1's 60 s, only when 092 is never seen;
- `rb_pre107`'s 125 s, only when the switch first reads off.

No plant may leave the container not answering (30 s in deploy-hybrid and 120 s in the rc=2 waits).

The ordering:
1. 107-1's runs stop at BASE_DIFFERS, before A6.
2. 107-2 and 107-3 stop in stage A; NS-B1 stops before the copy.
3. First-deploy variants reset by A9, never by a typed rollback.
4. One deploy per source of `MG_CA2_SEEN` (1, 0, nowait). Each is followed by `master106`, then its `--after-only`
   cases, each plant undone with `keep`/`back`.
5. In 107-8, every *answer anything but ROLLBACK* case runs first, on one deploy. Then H6 and H3. Then the typed
   rollbacks, each followed by a `--redeploy` that carries 107-9's assertions.
6. The H6 Q/K cases, their copies and TD7 run with the switch on, so `rb_pre107`'s sleep is skipped. The off path is
   kept for four runs only: off with no marks, control 0, §M, and the off put-back paragraph.
7. The ME-CA1 poll by A10.

Estimate: about 15 min of sleeps and about 3–3.5 h for 107's sections, on top of the chain (about 9 h 48 min for 105's
and about 2.8 h for 106's).

**A18 — numbers and texts** (RC19, RC20).
- **Numbers.** 107's range is `+2567001070NN`, proved free (RM18), and appended to the pattern files. `RH107SECRET-` is
  added to the names pattern. NA-CA1's sentinel (`NA_SENTINEL_1`, S:861) is the one seeded value outside the range: it
  is the script's own constant. Its row is swept and removed.
- **Swept:**
  - the `setoff-*` and `seton-*` logs;
  - `mutants/`;
  - every trigger;
  - the RH107 records.
- **The one-block rule** (§T.11's last bullet), over every run's output:
  - no line holds both a `--rollback` command and a deploy, `--redeploy` or `--after-only` command;
  - no two command lines (six-space indent) of {deploy, redeploy, after, rollback, putback} are adjacent without a prose
    line between them.

  Control: a copy that prints `REDEPLOY_CMD` beside `ROLLBACK_CMD` is caught.

## B. Sections

Each section states its entry state and its exit state, and undoes its own plants (RC9).

### 107-0, 107-cut, 107-infra and 107-pre

- **107-0 — guards.**
  - Not on the server.
  - `F107` is pinned (short and full, `T1_SIGNATURE`).
  - The helpers parse, with no `__pycache__`.
  - The verbs guard, with its planted controls: 5.18.107 adds no verb.
  - `deploy-5.18.106.sh` is pinned to `bf29cbd`.
  - The wrappers are committed and equal to HEAD.
  - The derivation's MEASURED states are printed; M0 must have run (A2).
- **107-cut** (v1 S23, verified). R6 up to its 106-10 heading, with the checks of v1. `H107_PREFIX` is the development
  runner's skip block.
- **107-infra.**
  - A1's relaunch and its controls.
  - A16's W.8.
  - The runners and the seed.
  - H1–H6, each with its controls.
  - The number range and the patterns.
  - `cleanup107`.
- **107-pre.** A15. Its exit state:
  - 5.18.106 serving;
  - steps 1–6 on;
  - production's settings;
  - the RH107SECRET- settings text;
  - A2's values recorded;
  - the A9 snapshot taken.

### 107-1 to 107-3: refusals before any copy

- **107-1 — BASE_DIFFERS and the tools reading.** Entry: 107-pre's. Every run stops at BASE_DIFFERS (S:12399); exit is
  107-pre's state.
  - The plain 5.18.106 tree: one changed non-tools file edited, then put back and checked put back.
    - Expected: the three present files *bf29cbd's*; BurstMerge *absent*; commands worded refused; K and Q *cannot be
      read* with *is refused (unmanaged)*.
  - The eight mixed-tree cases, each tree planted and put back:
    - only the ladder the pin's;
    - `set_config` the pin's;
    - `set_config` edited by hand;
    - the three present files removed (A11 S6);
    - the ladder the pin's with the tool removed;
    - the stage-A mixed tree (`P3B_PIN` unset, no unbound variable);
    - BurstMerge absent;
    - BurstMerge edited.
  - Each weakened copy §T.11 names.
- **107-2 — MG-G1, QF-G2, NA-G1.** Entry: 107-pre's. Each plant is undone by `keep`/`back` and `cut106` on
  migration.log. Exit: MG-G1 absent and the keys absent, asserted.
  - The pairing, by a fixture `wa_no_ai` with no ledger row: MG-G1 absent, neg092 `reset=1`, QF-G2 NO-GO naming the
    pairing, nothing changed. Two weakened copies.
  - A partial 092: NO-GO.
  - 092 with other bytes: NO-GO.
- **107-3 — NS-G1, NS-B1, stage A's notes.** Entry: 107-pre's; fixtures per A13. Exit: every key absent again,
  asserted.
  - Each switch on in p, d and k, each refused, with its weakened copy.
  - The in-memory positive control's copy: NOT ESTABLISHED.
  - `'0'` present → GO.
  - The store row alone → GO with the note.
  - A text key set, `no_ai_alert_cooldown_hours` among them → the note (RC13).
  - NS-B1 through `csb1run107`: S14's stop, exit 1.
  - The settings-text note (A3), with the reader control.
  - StaffJobsGate false: the capitals note, and no refusal.

### 107-4 to 107-7: deploys and their after-the-copy readings

- **107-4 — the first deploy, PASSED.** Entry: 107-pre's (A9 reset).
  - QF-G2's trial line and NA-G1's probes.
  - neg092 `reset=0`.
  - MG-CA1, MG-CA2 and NA-CA1.
  - R7 *applied*.
  - LK-CA2 with one row more.
  - The state file holds `MG_G1=absent` and `MG_CA2_SEEN=1`.
  - ME-CA1 NOT YET, as a note (RC13: §T.11:3501).
  - Then `master106` and `--after-only` PASSED: ME-CA1 PASS with its *not judged (plugin.log: …)* clause.
  - Exit: 5.18.107 serving, with a deploy record whose `MG_CA2_SEEN=1`.
- **107-5 — the first-deploy variants.** Each starts with an A9 reset.
  - A6, H2, with its copy, in the deploy run and a later `--after-only`.
  - A7.
  - A8, H4, with the straddle and its copy.
  - The WARNING beside the OK line on a first deploy (planted before the run): the straddle note, PASSED, with the copy
    keyed on MG-G1 alone.
  - A first deploy with WARNING only, and no OK line, by the unwritable log or a line removal: the straddle note beside
    NOT ESTABLISHED.
    - With the ledger's md5 other as well: MG-CA1 FAIL (RC13: §T.11:3487).
  - Exit: 5.18.107 serving after the last variant.
- **107-6 — after the copy: failures, and MG-CA2's missing line.** Three deploys, one per `MG_CA2_SEEN` source (A17
  item 4), each followed by master106 and its `--after-only` cases. Each `--after-only` plant is undone before the
  next. Entry and exit are stated per deploy.
  - **Deploy `seen=1`** (107-4's, or a fresh one after an A9 reset):
    - `wa_no_ai` renamed: NA-CA1 and MG-CA1 FAIL; the lead S:16150; ROLLBACK_CMD alone.
    - Another md5 over a whole table: MG-CA1 FAIL, R7 FAIL *CHANGED* (S11), NA-CA1 holds; the lead S:16161.
    - A PARTIAL line beside a whole ledger: MG-CA2 FAIL. The deploy-run half is planted before a fresh deploy (RC13:
      §T.11:3453).
      - Its weakened copy prints MG-G1's redeploy-refusal consequence; it is caught.
    - `MG_CA2_SEEN=1` with the OK line removed and no marker: the note *is no trim marker*; *the runner halves the log*
      absent. Two copies.
    - 071's mismatched checksum with real halving (RC4: §T.11:3466–3472):
      - migration.log is pre-filled near 256 KiB with fictitious lines, said so;
      - clocked boots (master106) are made until the runner's marker is line 1 and 092's OK line is gone;
      - expected: the note naming 071's WARNING and the halving;
      - two copies, one ignoring both `MG_CA2_SEEN` and the marker, one treating `MG_CA2_SEEN=1` as 0;
      - the control is the no-sighting, no-marker case.
    - `MG_CA2_SEEN` taken out:
      - a marker at or after `applied_at` → the note;
      - one before it → NOT ESTABLISHED naming the missing record;
      - no marker → the same;
      - two copies.
    - No `MG_G1`: NOT ESTABLISHED naming the missing record.
    - 092's bytes altered beside a planted WARNING: R1 FAIL; MG-CA2 names the WARNING as not judged; no straddle text.
      The copy keyed on MG-CA1 alone is caught.
    - The sentinel row planted, after asserting MG-CA1's readings and R1 hold: NA-CA1 FAIL; no straddle note; the lead
      item 7. The copy dropping NA-CA1 is caught.
    - The same with `wa_no_ai` renamed: no copy claimed.
    - The `--after-only` summary with the ladder edited after the copy (RC21: §T.11:3577, moved here). The copy saying
      *it leaves '0'* regardless is caught.
  - **Deploy `seen=0`** (the log unwritable, A5):
    - stage R's NOT ESTABLISHED in capitals; its copy turning it into a note.
    - `--after-only` with no marker: NOT ESTABLISHED. Its copy reads MG-G1 from the `--after-only`'s own stage A.
    - The log then halved with the marker after `applied_at`: NOT ESTABLISHED. Two copies.
  - **Deploy `seen=nowait`** (H1):
    - MG-CA1's timed-out FAIL; `MG_CA2_SEEN=nowait`; MG-CA2's *NO BOOT HAD APPLIED 092*; R7 FAIL *DID NOT MOVE*
      (RM10); the lead S:16148–16150; V1's 503 line recorded, not judged.
    - Then H1 removed, the ledger asserted without 092, one boot by master106, and a halving whose marker is stamped
      after `applied_at`: `--after-only` reads the note naming the halving. The copy writing `MG_CA2_SEEN=0` on a
      timed-out wait is caught.
    - The control: the line removed and no marker reads NOT ESTABLISHED naming the timed-out wait.
- **107-7 — ME-CA1 and ME-CA2.** On 107-6's last deploy.
  - Planted `[master]` lines (stamped in `dn_tz`): 0 ERROR, then a planted ERROR: FAIL (RC13: §T.11:3504).
  - (L, −1) with a later cycle (`pollrun107`): FAIL.
  - (L, −1) with no new cycle: NOT YET, *master did not start a new cycle within the poll's bound*; budget planted 0,
    said so.
  - A re-dispatch between two reads: NOT YET, *missed*.
  - An unreadable schedule: the capitals.
  - The NOT YET summary: exit 3, the next-run time.
  - ME-CA2:
    - (L, −1) on one of the seven, then a later cycle: FAIL.
    - (L, −1) with no later entry: the note.
    - (L, −1) on `notify_retry` with a later cycle: no `ME-CA2 FAIL` line (A11 S2).

### 107-8 to 107-10: the rollback, the redeploy, the sweeps

- **107-8 — the rollback.** On one 5.18.107 deploy, ordered by A17 item 5.
  1. **Every "answer anything but ROLLBACK" case first:**
     - switch on: the capitals, both commands each alone, the redeploy sentence (RC13: §T.11:3516), the prompt saying
       *on*, and any other answer changes nothing;
     - NOT READ (A14), with step 5's capitals and its two copies;
     - TD7 with K then Q on;
     - control 0 (A11 S1);
     - §M, with event ids;
     - a listed number and a queued alert: the counts.
  2. **The off path, typed:** two readings 125 s apart, then the question. The settings text's note (A3) is in this
     run.
  3. **Switch on and a mark written while *Type ROLLBACK* waits** (`rbhold107`), then ROLLBACK:
     - `rb107` lists its conversation (A11 S9);
     - a later `--check` is an NS-G1 NO-GO naming the key and its places, with nothing changed;
     - a fixture then clears the switch (A13).
  4. **H6, the rollback that did not verify, 5.18.106's tools installed:**
     - the tools reading names the ladder, `tools/sales_followup.php` and `tools/set_config.php` *bf29cbd's* and
       BurstMerge *the pin's*;
     - `burst-drain` *is refused (an unknown step)*;
     - the known-limits repair is named.

     Then the same with Q planted on, then with K planted on, with the three copies.
  5. **H3:** *5.18.107's tools remain*, every command worded as running.
  6. **V1, through H5:**
     - with no mark;
     - a `--redeploy` after §K's way out with a planted leftover mark, ending in V1: *this run wrote no mark* with the
       stage-A count, and the stop lists the ids;
     - V1 with control 0: INDETERMINATE, with its copy;
     - `rb107` and `RB_NOTHING`.
  7. **The put-back paragraph**, off with (a) 0 and (b) 0, and on.
  8. **After the rollback**, the alert acked as unknown (RB's NA_RB_* cross-checks A2's EP_NOAI_DONE).

  Exit: 5.18.106 serving after a typed rollback whose switch-off ran under 5.18.107 and left `'0'` (RC9).
- **107-9 — `--redeploy` and the printed commands.**
  - The redeploy: MG-G1 present, GO, none pending; neg092 `reset=1`; R7 *same*; LK-CA2 exact; MG-CA1 *applied by an
    earlier deploy*; NS-G1 GO with `'0'`.
  - `master106`, then a PASSED `--after-only` (RC21), which prints the owner's steps.
  - Steps 1 and 3 run as printed, each alone, each with its own log.
  - The drain through the wrapper.
  - The put-back's and step 5's `set_config --value 0` lines.
  - K and W.1 turned off again with their printed off lines.
  - The one-block rule over every run (A18).
  - Exit: 5.18.107 serving, K, Q and W.1 off.
- **107-10 — the sweeps.**
  - 106-10's sweeps, re-read with 107's range, texts and records.
  - Evolution and uCRM.
  - The LK keys.
  - The fixtures' leftovers, the sentinel row included.
  - No weakened copy and no trigger left.
  - The failure files.
  - The end state.
  - RESULT.

## C. §T.11 differences for the owner (recorded, not decided here)

1. The v9 settings text cannot be rehearsed; its branch is shown by a reader control (A3).
2. The faults "after the boot" are judged in `--after-only`, not inside the deploy run. A deploy-run plant there would
   need a hook between V1 and R1 that changes the book under a running deploy, and the observables are the same lines
   (A5).
3. "INDETERMINATE" before ROLLBACK is the control-0 state, printed as *control 0*, the capitals and §K's rule (A11 S1).
4. The texts §T.11 quotes loosely, asserted as the script prints them (A11, A12; map (1)):
   - *master did not start a new cycle within the poll's bound*;
   - *once 5.18.106's tools are installed none can write it*;
   - *COULD NOT BE READ*.
5. The interim MEASURED values are a gate at A6 for every deploy and `--check`. M0 measures them before the rehearsal
   (A2).

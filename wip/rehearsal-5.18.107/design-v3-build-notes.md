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
- **107-8q′** looked for INDETERMINATE anywhere; stage A's SA note says it on every run with control 0 (S:8096). It now
  reads the stop's own text.
- **A V1 case with control above 0 and no mark** was missing (the unmodified script's "it lists no conversation" branch,
  S:8906): 107-8o2, an unmarked typed burst.
- **Copies without a positive observable** (107-8b″, 107-8b*, 107-5e*) and the WARNING count in 107-5d″ (the real line
  beside the planted one) tightened; 107-8b asserts the reason the switch was not read (P3B_WHY, S:12540).
- Smaller: conversation ids matched whole (`hasid107`); V1's weakened copy kept out of the one-block sweep; the `mixed`
  rule's own control; 107-10f asserts every control gone and the NS keep empty; the boot outputs read line by line;
  `plugin.log`'s absence read as 0; the seed's JSON outputs swept.

## Accepted as known limits

- In 107-8f the installed worker runs every due event of D1's book, not only #A and #B; what else it does is judged by
  107-10's sweeps (Evolution counts, names and numbers), not case by case.
- Whether rollback-mode stage A under `FAKE_CP_FAIL` reaches the question (107-8b) can only be shown by a run.

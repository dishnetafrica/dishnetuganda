# 124 — O-1 closed: migration 029 makes a site and its service belong to one operator

**Status:** built and proved in the development schema, 2026-09-23.
**REDEPLOYED on staging 2026-09-24 04:14:49 UTC, first attempt, every check
held (§H).** **Production:** there is
no Domain B outside staging on the DishNet host (`docs/123` §F), so there is
nothing to migrate there; a first production install applies 029 to empty
tables like every other migration.

**Why now.** GATE 1 of `docs/107`, the census, read CLEAR on staging at 22:08
UTC (`docs/123` §F). GATE 2, the migration, is a separate decision. I
recommended taking it. The operator replied *"i will go with your
receommadantion"*. This document records what was built on that decision, what
was measured first, and the one command that applies it to staging.

**What O-1 is** (`docs/104`–`106`). `mt_sites` carries two independent
single-column foreign keys, `customer_id` and `service_id`, and nothing required
them to agree. Operator A could attach its site to operator B's service by
literal UUID. Referential integrity is enforced below row security, so neither
operator can see the other's row, yet B can then never end that service and
cannot see why. The fix is W-2's shape one level up: `UNIQUE (id, customer_id)`
on `mt_services` and a composite foreign key from `mt_sites`.

---

## A. What governs this

| Rule | Where | Applied here |
|---|---|---|
| GATE 1 and GATE 2 are separate acts; never one script that measures and then acts on its own measurement | `docs/107` | the census ran on its own (`docs/123`); the staging command re-takes it read-only and **stops** unless it reads CLEAR, and 029 refuses by itself if anything changed in between |
| Six steps; step 6 is verifying **independently, afterwards** — "exit code 0" is not evidence | `docs/79` §7b | the staging command re-reads the catalogue, re-runs the census, and runs an execution test after the migration |
| Remediate only the proven defect; do not bundle | `docs/105` | 029 touches `mt_sites` and `mt_services` only; a test asserts it |
| Both single-column keys stay; no CHECK; `NO ACTION`; no `NOT VALID`; no `CONCURRENTLY` | `docs/105`, `docs/106` | the shape is unchanged from `docs/106`; a test asserts each |
| The migration must fail closed; `SET LOCAL row_security = off` makes a read under row security **error** instead of validating a partial view | O-1 acceptance record (CLAUDE.md) | the guard is kept, and a test asserts it |
| No superuser and no `BYPASSRLS` role for any of this | `docs/105`, `docs/107` | 029 runs as the schema owner, as every migration does; the checks run as `dnb_adminapi` |
| Credible evidence: negative result, positive control, known authorisation context | `docs/103` | every refusal in the suite is paired with an accepted control in the same session |
| A regression test must be shown to fail when its control is removed (T-9) | `docs/106` | the constraint is dropped inside a rolled-back transaction and the attack then succeeds |

### A.1 The candidate DDL could not have been the migration — measured

`tools/audit/o1_composite_fk.sql` is the DDL `docs/106` demonstrated, with the
guard line the acceptance harness added. It was never run the way a migration
runs: the Migrator applies each file **as the schema owner**, in one implicit
transaction. The owner has not been a superuser since 017 (F2), and both tables
are `FORCE ROW LEVEL SECURITY`.

Measured through the real installer on throwaway databases, and now asserted in
`tests/test_o1_site_service.php` §9 and the acceptance harness phase 4:

| Candidate, run as the owner | Result |
|---|---|
| with the guard, on an **empty** install | **refused** — *query would be affected by row-level security policy for table "mt_sites"* |
| with the guard, on a **clean** estate | **refused**, the same error |
| without the guard, on a violating estate | **applied and marked VALIDATED** with the violating row still underneath |

So the guarded file refuses on every estate, and the unguarded one validates
nothing. Neither is a migration. The candidate is kept, marked **SUPERSEDED**,
because it is the record of that measurement.

### A.2 The design, and why lifting FORCE is safe

029 lifts `FORCE ROW LEVEL SECURITY` on exactly `mt_sites` and `mt_services`
**inside its own transaction**, validates against every row, and restores FORCE
before the transaction ends. Four properties make that safe, each measured:

- **FORCE binds only the table owner.** Every other role stays bound by the
  enabled policies whether FORCE is set or not. The owner is `dnb`, which only
  the installer uses; no running process connects as it.
- **Nobody else can read either table while FORCE is lifted.** `ALTER TABLE …
  NO FORCE ROW LEVEL SECURITY` takes an **ACCESS EXCLUSIVE** lock on the table,
  held until the transaction ends. Measured on both tables.
- **A failure anywhere undoes all of it, FORCE included.** The file is one
  transaction. Proved: against a violating estate the owner-run migration
  refuses, and afterwards FORCE is still on, the ledger still reads 28, and
  neither constraint exists.
- **The guard stays.** If a read in 029 were still subject to row security — the
  file run by a role that does not own the tables — it errors instead of
  validating a partial view.

Before any constraint is attempted, 029 **counts** the violating rows and names
up to five site→service pairs, then refuses with *"nothing was changed"*. The
foreign key's own error would name only one pair. After the constraints are
added it **verifies itself**: FORCE restored on both tables, the key present and
validated, the supporting UNIQUE present, both single-column keys still there.

**The cost, stated.** `docs/106` planned only `mt_services` to be
ACCESS EXCLUSIVE-locked. With the FORCE bracket, **both** tables are, for the
length of the migration. That duration is unmeasured at production scale; on
staging the tables hold five sites and three services. Production holds no
Domain B at all (`docs/123` §F).

**Apply it only as one transaction.** The file's header says so: through the
installer, or `psql --single-transaction -v ON_ERROR_STOP=1`. A plain `psql -f`
would commit statement by statement.

---

## B. Decisions

| # | Decision | Reason |
|---|---|---|
| D-1 | The constraint shape is exactly `docs/106`'s | it was demonstrated there; only its execution context changed |
| D-2 | Lift FORCE on the two tables inside 029's transaction; keep the guard | §A.1: the only way the owner can validate every row; §A.2: why that is safe |
| D-3 | Enumerate before refusing, with a count and up to five pairs | the key's own error names one pair; the census enumerates, and so does 029 |
| D-4 | 029 verifies itself before committing | `docs/79` §7b: exit code 0 is not evidence |
| D-5 | Nothing else is bundled: no `mt_vouchers.site_id NOT NULL`, no device or customer keys, no `credential_hash` drop | `docs/105` |
| D-6 | `S-A` becomes impossible by constraint: a referenced service's operator cannot change | `ON UPDATE NO ACTION`, asserted in §5 of the suite; `docs/107` already closed S-A as prohibited |
| D-7 | The candidate DDL stays in `tools/audit/`, marked SUPERSEDED | it is the record of §A.1 |
| D-8 | The acceptance harness measures O-1 **constraint-suppressed** from now on, like the five anomalies before it | at level 29 the schema refuses the row; the census must still be able to see it in a database below 029 |
| D-9 | Staging is migrated by the redeploy command, with the census re-taken first and verification afterwards | GATE 2 on its own evidence, in `docs/79` §7b order |
| D-10 | The staging command also runs an **execution test** inside a transaction that is always rolled back | a catalogue row is weaker evidence than an execution test (`docs/103` hierarchy) |

---

## C. The build

| File | What |
|---|---|
| `migrations/029_o1_site_service_same_operator.sql` | §A.2 |
| `tests/test_o1_site_service.php` | new suite, **65** assertions, §D |
| `tests/test_router_control_plane.php`, `test_operator_staff.php`, `test_router_lifecycle_provision.php` | their pins "the last migration is 028, ledger 28" now say 029 and 29, each with its reason in the assertion text |
| `tools/audit/o1_acceptance.php` | O-1 moved to constraint-suppressed; two orphan cases now also lift the composite key; phase 4 runs **029 itself as the owner** against a violating and then a clean estate; **75** assertions, stable over two runs, zero residue |
| `tools/audit/o1_composite_fk.sql` | SUPERSEDED header only |
| `plugin/doc/INSTALL.md` | an upgrade note: 029 refuses, and changes nothing, on an estate where a site names another operator's service |
| `plugin/plugin.json`, `src/Api/AdminRoutes.php` | wording only: `POST /sites` now waits for its writer, `mt_site_create`, not for O-1. It still answers 501 |

**Suite: 35 suites, 3,327 assertions, 0 failed**, three runs (was 34 / 3,262).
**`plugin/bin/install-test.sh`: 85 of 85.** The release package is 119 files,
content digest **`780023ff04545bb8b5eb921c63b9e7bb7d885b3079ecc6069c1797d89b1c46d0`**.
The name is still `0.1.0-rc1`; compare the digest, never the name.

One local fault was found and fixed during this work, and it was not in the
product: the first install test failed 12 of 85 because two sandbox processes
from the `docs/122` harness still held ports 8099 and 443. The new redeploy
harness now stops both when it exits.

---

## D. What the suite proves — `tests/test_o1_site_service.php`

| § | Proof | Control |
|---|---|---|
| 1 | the key's exact shape: columns, target, validated, not deferrable, `NO ACTION`, `MATCH SIMPLE`; the UNIQUE on `(id, customer_id)`; both single-column keys kept with `RESTRICT`; no CHECK on `mt_sites`; FORCE on both tables; the catalogue-wide row-security guard clean; the file lifts and restores FORCE on exactly two tables in that order, keeps the guard, contains no `NOT VALID`, `CONCURRENTLY`, dropped constraint or widened role, and names no other table | — |
| 2 | as `dnb_app` in operator A's context, a site on B's service is **refused by name** | the same-operator site is accepted in the same transaction; A reads its own service (1) and not B's (0); residue 0 |
| 3 | re-pointing an existing site onto B's service is refused by name | re-pointing it onto A's own second service is accepted |
| 4 | the refusal binds the fixture identity too, which bypasses row security | its same-operator insert is accepted |
| 5 | re-papering a referenced service onto another operator is refused by the composite key | an unreferenced service can be re-papered, so the refusal is the key |
| 6 | deleting a referenced service is still refused by a site key | an unreferenced service deletes — `docs/105` C1 |
| 7 | isolation unchanged: each operator sees its own site and service and not the other's; no tenant context reads 0 sites | the fixture identity sees them |
| 8 | **T-9:** with the key dropped inside a rolled-back transaction, the identical attack as `dnb_app` is **accepted** | the same shape with the key present is refused; afterwards the key is back and validated |
| 9 | on a throwaway database built to 028 by the Migrator: the attack is accepted through the real path at 028; the owner sees 0 of 2 sites; the candidate refuses on a clean estate and leaves nothing; **029 as the owner refuses the violating estate, counts it, says nothing was changed, and nothing was** (ledger 28, no constraint, FORCE on); after the per-row decision 029 applies alone; the key is validated and FORCE restored; the attack is then refused by name | the same-operator site is still accepted; the throwaway database is dropped |
| 10 | 029 is the last migration; the ledger holds 29; the candidate says SUPERSEDED; the migration cites this document and `docs/123` | — |

**Controls on the controls.** Three weakened copies of 029 were each run
against the suite:

| Copy | Suite result |
|---|---|
| naive: no guard, no FORCE bracket, no count | **7 of 65 fail**, including "029 as the owner REFUSES the violating estate" — the naive file validated nothing |
| FORCE never restored | **6 of 65 fail**, including the catalogue-wide row-security guard |
| no migration 029 at all | **29 of 65 fail** |

---

## E. The handover — one command, as root on the server

```sh
curl -fsSL -o /root/dnb-redeploy.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-redeploy.sh \
  && sh /root/dnb-redeploy.sh 2>&1 | tee /root/dnb-staging-evidence/redeploy-$(date -u +%Y%m%dT%H%M%SZ).log
```

**Since 2026-09-24 this URL serves the next build's command** (`docs/125` §E),
as this command replaced the 028 one before it. The 029 command, byte for byte as
the operator ran it (sha256 `c558e26a…`), stays at commit `938c002`:
`https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/938c00291d8ff7519ddce88b7e66627ac7d9fb46/scripts/dnb-staging-redeploy.sh`.

`scripts/dnb-staging-redeploy.sh` is rewritten for this build. Its steps:

| Step | What it does | If it fails |
|---|---|---|
| 0 | read-only checks; reads which staff identity the API runs; refuses if `DN_ALLOW_REAL_BINDINGS` is set anywhere | stops, nothing changed |
| 1 | builds the artifact **on the server** from the reviewed commit **`83edb98`**, fetched by its hash; refuses unless the content digest is `780023ff…`; checks the census file is the reviewed one | stops, nothing changed |
| 2 | **GATE 1 re-taken:** the census as `dnb_adminapi` must read **CLEAR**; the new build's doctor must report no blocker, in production posture | stops, nothing changed, and prints the census lines that matter |
| 3 | swaps the tree (the previous one is kept) and runs the installer, which applies only 029 | if 029 refuses, **the previous tree is put back** and no container is restarted |
| 4 | **verifies independently:** the key validated, the UNIQUE present, both single-column keys kept, FORCE on both tables; the census again, CLEAR over a visible estate; through the projections as `dnb_adminapi`, sites seen and none crossing; an **execution test** as `dnb_app` in a transaction that is always rolled back — own site accepted, a site on another operator's service refused by name — and residue 0 | stops with the reason |
| 5 | restarts **only** the API and worker containers; checks loopback and the hostname; asserts no other container changed | stops with the reason |
| 6 | one result line | — |

**Why a pinned commit and not the branch tip.** The script used to build
whatever the branch tip was. Any later push, such as the step-3 work that
follows this document, would then change the build and make the operator's
command refuse on the digest. The commit is now fetched by its hash, which
GitHub serves both to `git fetch` and as a tarball (both measured), so the
command stays valid however far the branch moves on.

It prints no secret, so its whole output may be pasted back. It touches no
production container, Traefik file, DNS record, firewall rule or other
PostgreSQL instance. The API keeps the real DishNet staff login it runs with.

**Rollback of the code**, printed by the script: move the previous tree back and
restart the two containers. Migration 029 does not need undoing — the previous
build neither uses nor conflicts with the two constraints.

---

## F. Rehearsal — `scripts/harness/redeploy/`

The real script, run against a sandbox rebuilt from nothing into the staging
state of 22:08 UTC: the 028 build reproduced from commit `9f95353` and checked
against its digest `1bc95524…`, the simulated estate at ledger 28, the stage-2
route, and the API switched to the real staff login **by the real
`dnb-staging-staff-login.sh`**. Only `docker` is faked; every database is real
PostgreSQL, and the build is fetched from a clone of the committed branch.

Before any scenario, the harness **moves the branch on**: it pushes a later
commit that changes a packaged file, and asserts that the new tip no longer
builds the reviewed digest. Every scenario therefore runs against a branch
that has moved past the reviewed commit, as the real one will.

| Scenario | What is asserted |
|---|---|
| **R1** staging now | exit 0; the pinned commit was built, not the tip; census CLEAR before; doctor in production posture with no blocker; the installer applies exactly 029; ledger 29; catalogue `1|1|2|true`; census CLEAR after; projections show sites and none crossing; the execution test refused by name with the control accepted and residue 0; loopback and hostname answer as the real provider; exactly the API and worker changed; the previous tree kept; no container created or removed |
| **R2** run again | exit 0; notes the build is already deployed; *schema already current*; verification passes again |
| **R5** wrong digest pinned | refused before anything changes; no new tree, ledger 28, no restart |
| **R3** a cross-operator site exists | census **BLOCKED(1)**; stops with *nothing was changed*; no installer run, no restart; ledger 28, no key, FORCE on |
| **R4** a violating row appears **after** the census | 029 refuses by itself with its count; the previous tree is live again, the refused one kept aside; ledger 28, no key, FORCE on; no restart |
| **R6** the stage-2 posture | exit 0 with the development identity; doctor `--disposable`; basic auth still in front |
| **M1–M3** | three broken copies of the script, each caught: no census gate; no swap-back on refusal; an execution test aimed at the same operator (the script's own check fails it) |

The counts are in §F.1.

### F.1 Counts

**69 of 69 passed** on the final run, with the branch moved past the reviewed
commit. Two earlier runs are part of the record. The first passed 65 of 66:
the harness's own race check looked for an `UPDATE 1` line that its quiet
`psql` never printed, while the race itself had happened, as 029's refusal
showed; the check was corrected and a second assertion added. The second run
passed 67 of 67 before the commit pin existed.

| Scenario | Assertions |
|---|---|
| setup: the branch tip no longer builds the reviewed digest | 1 |
| R1 staging now | 29 |
| R2 second run | 5 |
| R5 wrong digest | 4 |
| R3 violation before the run | 7 |
| R4 violation after the census | 10 |
| R6 stage-2 posture | 6 |
| M1–M3 broken copies | 7 |

---

## G. What this unblocks, and what it does not

- **O-1 is closed in the development schema, and on staging since 2026-09-24
  04:14:49 UTC** (§H). `mt_site_create` may now be designed and built (`docs/105`: *"do
  not build it until O-1 is closed"*) — as **derive, never accept**: no
  `p_customer`, the operator read from the service row.
- **The writer order of `docs/108` still holds:** the non-tenant idempotency
  store (step 0b) comes before the first spine writer, because a duplicate
  operator cannot be deleted.
- **Not decided here:** `mt_vouchers.site_id NOT NULL` (`docs/78` §4.2), U-1,
  U-5, B-3, F-3. `POST /api/v1/admin/sites` still answers 501.
- **Nothing here is HARDWARE VERIFIED**, and F6-B is still NOT AUTHORIZED.

---

## H. Result

**REDEPLOYED 2026-09-24 04:14:49 UTC, on the first attempt, with no
correction.** The operator ran the §E command as root on the server, starting at
04:14:22 UTC, and pasted the whole output back. It printed no secret. The log and
the two census outputs stay on the server under `/root/dnb-staging-evidence/`.

| Step | What the output shows |
|---|---|
| 0 | deployed build `1bc95524…` (028); the API runs the **real DishNet staff login** — trusted proxy `172.22.0.1`, origin `https://portal-staging.dishnetuganda.com`; 28 migrations, last 028; stage 1 healthy |
| 1 | built from commit **`83edb9875f88…`, fetched by its hash**. By then the branch had moved on to the step-3 work (`46c778e`), which is exactly the case the pin exists for. 119 files verified against `SHA256SUMS`; archive sha256 `3b200fae…dce18772`; **content digest `780023ff…b1c46d0`, the reviewed one**; the census file is the reviewed one |
| 2 | **GATE 1 re-taken: the census read CLEAR, 11 rows seen.** The new build's doctor, production posture: **24 checks, 23 ok, 1 warn, 0 blockers** |
| 3 | tree swapped, previous kept at `/opt/dnb-staging/app.prev-20260924T041422Z`; **the installer applied exactly one migration, 029**; 29 recorded, 29 files in the build |
| 4 | catalogue **`1|1|2|true`** — the composite key present and validated, the supporting UNIQUE present, both single-column keys kept, FORCE on both tables. **Census after: CLEAR, 11 rows seen.** Through the Admin projections as `dnb_adminapi`: **5 sites seen, 0 whose service is another operator's**. **Execution test as `dnb_app`, operator `482c71c7…`, rolled back:** own site `INSERT 0 1`; a site on another operator's service **refused by `mt_sites_service_customer_fkey`** (*Key is not present in table "mt_services"* — the other operator's row stays invisible, as §D expects); residue 0 |
| 5 | loopback: panel 200, `routers.js` 200, session **401 from the `dishnet` provider**, second factor required; through Traefik: `/` 200, session 401 the same; worker `simulated-routeros`; **only `dnb-staging-api` and `dnb-staging-worker` changed, and no container was removed** |

**The one doctor warning is not printed** — the script shows only the count
line. By the doctor's own rules the warning expected at that moment is
`schema.present`, *28 of 29 migration(s) applied*, because the new build's
doctor runs **before** the installer. The warning `docs/122` recorded (*no staff
on record*) no longer applies, since `dishnet-admin` exists. **This is an
expectation, not an observation.** The next staging command prints its warning
lines. Its rehearsal, in the same posture, prints exactly one — *plugin schema,
29 of 30 migration(s) applied*, 23 ok / 1 warn (`docs/125` §E.1). That is
consistent with the expectation, and still not an observation of this run.

**What this establishes, on staging:**

- **O-1 is closed.** The invariant is enforced by a validated key, below row
  security. It is demonstrated by execution as the customer-facing role, with a
  positive control in the same transaction and residue 0.
- **GATE 1 and GATE 2 stayed separate acts.** The census was taken twice by the
  command, as a role with no special privilege. The migration is the installer's.
  Verification is independent of both, as `docs/79` §7b step 6 requires.
- **The pin worked as designed.** The branch had moved on and the command still
  built the reviewed digest.

**What it does not establish:** anything about production, which holds no
Domain B (`docs/123` §F); anything about a MikroTik — nothing is HARDWARE
VERIFIED, and F6-B stays NOT AUTHORIZED; and nothing about `mt_vouchers.site_id
NOT NULL`, U-1, U-5, B-3 or F-3, none of which 029 touches.

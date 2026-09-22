# 79 — Operator handoff: run the production census

**For the person with access to the deployed Domain B control-plane database.**
Everything below is read-only. It changes nothing, repairs nothing, and reveals
no credential.

**Do not send credentials back — return the census output only.**

---

## 0. Before anything: find the right database

The control-plane database is **not** necessarily `dn-phase0-postgres` — that
container holds the **RADIUS** database (`radius`), which is a different
instance. The control plane uses its own DSN (`DNB_DSN`), and where it runs was
never settled (docs/55 §416).

**Take the DSN from the deployed Domain B service's own configuration** — its
environment, compose file or service definition — **not from memory and not from
this repository.**

> **If no Domain B service is deployed, stop here and say so, naming how you
> checked.** That is the answer to question 1 and no census is needed.

---

## 1. The command

```bash
cd <repo>/dishnet-hybrid-sudan/dishnet-mikrotik-control-plane

psql -q -f tools/audit/production_census.sql > census.txt 2>&1
```

**The `2>&1` is required, not decoration.** The census prints through
`RAISE NOTICE`, which goes to **stderr**. Verified: a plain `>` captures 8 lines
of psql chatter and **none** of the census; `> census.txt 2>&1` captures the
whole transcript, all 6 sections.

## 2. Connection, without typing a password anywhere

Set the non-secret parts as environment variables and let `psql` obtain the
password itself:

```bash
export PGHOST=<host>          # or the socket directory
export PGPORT=<port>
export PGDATABASE=<control-plane database name>
export PGUSER=<role — see §3>
```

For the password, use **either**:

- **`~/.pgpass`** (preferred) — `chmod 600`, one line:
  `hostname:port:database:username:password`. `psql` reads it automatically and
  nothing is typed or echoed; **or**
- **`psql -W`**, which prompts and does not echo.

**Do not** put the password in the command line, in `PGPASSWORD`, in a shell
history, or in any message back to us.

Running inside the container instead is equally fine:

```bash
docker exec -i <container> psql -q -U <role> -d <database> \
  < tools/audit/production_census.sql > census.txt 2>&1
```

## 3. Minimum role requirements — NO SUPERUSER, NO `BYPASSRLS`

**This section was rewritten once the census was re-routed through the Admin
projections. It no longer needs a bypassing role, and you should not create
one: a role created to bypass RLS for a census outlives the census.**

Every data measurement now reads through `mt_admin_sites()`,
`mt_admin_services()`, `mt_admin_routers()`, `mt_admin_vouchers()`,
`mt_admin_voucher_batches()` and `mt_admin_customers()`. `dnb_def_admin` holds
SELECT-only `USING (true)` policies behind them, so an ordinary
**`dnb_adminapi`** login sees the whole estate with **`superuser = f`,
`bypassrls = f`**. The script prints the path it actually took.

**Run it TWICE, with two ordinary roles. Neither is a superuser.**

| Run | Role | What it establishes | Why the other role cannot |
|---|---|---|---|
| **1** | **`dnb_adminapi`** | all DATA — sections 1b, 2, 3, 4 | the owner cannot EXECUTE the projections, so it falls back to base tables and is blinded by `FORCE RLS` |
| **2** | the schema **owner** (`dnb`) | the SCHEMA level — section 1, the migration ledger and applied filenames | the projections do not cover `mt_migrations`, and `dnb_adminapi` has no `SELECT` on it |

**Do not grant anything to make one run do both.** No production privilege
change is authorised for the census. Two transcripts is the correct answer.

| | Requirement | Why |
|---|---|---|
| `CONNECT` on the database, `USAGE` on schema `public` | essential | to run at all |
| Run 1: `EXECUTE` on the `mt_admin_*()` projections | essential | `dnb_adminapi` already has it |
| Run 2: `SELECT` on `mt_migrations` | essential | the owner already has it |
| `BYPASSRLS` / superuser | **NOT required, and not to be created** | the projection path replaces it |
| Any write privilege | **not required** | the script cannot write — §7 |

**A section the role cannot read is now SKIPPED, not fatal.** Earlier the whole
run aborted on the first permission error, which could be mistaken for a short
but clean census. It now prints `*** SECTION n UNREADABLE by <role>` and
carries on.

### The verdict is withheld unless the run proved it could see something

`SECTION 6` prints `CLEAR`, `BLOCKED(n)` or `INDETERMINATE`. It reports
**INDETERMINATE** whenever any measurement was refused *or* when every table
read empty and nothing proved the session can see anything at all — because a
zero-row read has seven possible causes and only one of them is a finding.
**A census that reports INDETERMINATE has not been run.**

## 4. Confirming you are on the authoritative database

The output answers this itself; check three things in `census.txt`:

1. **§0** — `database`, `server address`, `port`, `user`. Confirm these match the
   deployed service's configured DSN.
2. **§1** — whether `mt_sites`, `mt_devices`, `mt_vouchers`,
   `mt_voucher_batches` and `mt_migrations` exist, and **every migration
   filename applied**. A control plane in real use has the full ledger.
3. If §1 reports the schema **absent**, that is authoritative for *that database
   only*. Say which DSN you used, so we can tell "wrong database" from "not
   deployed".

## 5. Saving the output

`census.txt` from §1. It is plain text, counts only. **Verified: it contains no
customer names, phone numbers, voucher codes, credentials or connection
strings.** The only occurrence of the word "password" anywhere in the transcript
is the script's own line *"identity only — no password or connection string is
read or printed."*

## 6. What to return

**The whole of `census.txt`** — all six sections, unedited:

| | |
|---|---|
| **§0** | role and RLS visibility, database identity |
| **§1** | deployment evidence — tables present, migrations applied |
| **§2** | `mt_devices` — totals, unsited, partial-null, cross-customer, orphaned, decommissioned-but-sited, shared tunnel IPs |
| **§3** | `mt_vouchers` and `mt_voucher_batches` — totals, NULL site, cross-customer, orphaned, **site-less vouchers broken down by state**, and **NULL-site batches that already contain vouchers** |
| **§4** | per-constraint blocking-row counts |
| **§5** | the caveats, including the RLS restatement |

Plus, in your own words, the §0 answer for the deployment questions the database
cannot answer: **(a)** no other database holds these tables, **(b)** nothing is
running against one, **(c)** the DSN you used is the one a deployment would use.

**Do not return** any credential, connection string, `.pgpass` content or
environment dump.

## 7. The warning

- **The census is read-only and enforced as such.** The whole run is inside
  `BEGIN; SET TRANSACTION READ ONLY;` and ends in `ROLLBACK`. Verified: an
  `UPDATE` inside such a transaction returns
  `ERROR: cannot execute UPDATE in a read-only transaction`. The engine refuses
  writes — this is not a promise in a comment.
- **Run nothing else.** Do **not** follow it with any repair, backfill,
  `UPDATE`, `DELETE`, `ALTER TABLE`, constraint addition, RLS change, or
  "quick fix" of anything it reports. **No migration is authorized.**
- **If it reports violations, that is the expected outcome of a census.** Send
  the numbers. The treatment for each class is designed (docs/74 §7) and
  **awaiting approval**; applying any of it now would destroy the evidence of
  what was there.
- If the script errors, send the error verbatim rather than editing the script.

---

## 7b. If the census is CLEAR, the migration is still a SEPARATE act

**Do not run `census → looks clean → ALTER TABLE`.** The synthetic acceptance
harness disproved the assumption that made that sequence look safe: a
constraint can be added and marked `convalidated = true` while violating rows
remain, if the role applying it cannot see them. Exit code 0 is therefore
**not** evidence that the invariant now holds.

The sequence is six steps, and steps 3 and 4 are decisions, not formalities:

| | Step | Why it is separate |
|---|---|---|
| 1 | **Establish role and provenance** | the transcript must say which role, which database, and which read path — a census whose provenance is unknown is not evidence |
| 2 | **Run the census** (both runs, §3) | read-only; it enumerates, where the migration's error would name only one pair |
| 3 | **Review the anomalies** | `BLOCKED(n)` lists the offending rows; each needs a per-row decision, and repair is not automatic |
| 4 | **Authorise the migration separately** | a `CLEAR` census authorises nothing by itself. GATE 2 is its own decision on its own evidence |
| 5 | **Execute the guarded migration** | as a role that can see every row. `SET LOCAL row_security = off` makes it refuse rather than validate a partial view |
| 6 | **Verify independently, afterwards** | re-run the census, and confirm the constraint exists **and** that no violating row survives beneath it. **Do not accept "the migration returned 0".** |

Step 6 exists because of exactly what step 5's guard prevents: the failure mode
is a constraint that reports success while the data underneath still violates
it. The only way to know is to look again, with a role that can see.

## 8. What happens next

`AUTHORITATIVE PRODUCTION CENSUS → REVIEW EVIDENCE → DECIDE B → FINALIZE
BACKFILL/MIGRATION PLAN → EXPLICIT F6 AUTHORIZATION.`

Nothing is implemented until the evidence is reviewed. In particular
**`mt_voucher_batches.site_id NOT NULL` is an OPEN schema decision**, not a
pending consequence — see docs/78 §4.2.

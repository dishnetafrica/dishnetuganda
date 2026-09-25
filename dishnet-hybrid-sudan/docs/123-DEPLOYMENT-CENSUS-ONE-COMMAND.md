# 123 — The deployment census in one command: is Domain B anywhere but staging, and is staging ready for O-1?

**Status:** review, census corrections and harness evidence written **before**
the handover, 2026-09-23. **RESULT 2026-09-23 22:08 UTC (§F): no Domain B
outside staging; staging CLEAR at migration 028.** **Read only:**
nothing is migrated, repaired or configured. **GATE 2 of `docs/107` — the O-1
migration — is untouched and not authorised by anything here.**

**Why now.** After step 4 (`docs/122`) the operator said *"go with your
recommendation"*. The next step on the roadmap is step 3, operator onboarding
from the Admin panel. It needs a production writer for sites, and
`docs/105` forbids building `mt_site_create` until **O-1** is closed. Closing O-1
takes GATE 1, the census, then GATE 2, a separately authorised migration
(`docs/107`). `docs/79` is the census handoff, but it asks the operator to find
DSNs and run `psql` by hand twice, and it was never run. This document turns
GATE 1 into one read-only command, as `docs/120`–`122` did for staging.

---

## A. What governs this

| Rule | Where | Applied here |
|---|---|---|
| *"If no Domain B service is deployed, stop here and say so, naming how you checked"* | `docs/79` §0 | step 1 searches every container's and swarm service's configuration for `DNB_DSN` and prints how |
| Two runs, **no superuser, no `BYPASSRLS`**: `dnb_adminapi` for the data, the owner for the schema | `docs/79` §3 | step 3, exactly those two roles |
| The deployment questions (a) no other database holds the tables, (b) nothing runs against one, (c) the DSN is the deployment's own | `docs/79` §6 | step 2 answers (a) from catalogs; step 1 answers (b); (c) is the staging containers' own `DNB_DSN` |
| Read only; run nothing else; a CLEAR authorises nothing | `docs/79` §7, §7b; `docs/107` | the census is a `READ ONLY` transaction that rolls back; every other query runs with `default_transaction_read_only=on` |
| Credible evidence: negative result + positive control + known authorisation context | `docs/103` | the verdict is withheld when a read was refused or hidden |
| This session cannot reach the server | CLAUDE.md | the operator runs it |
| No modification of any existing PostgreSQL | stage-1 approval (`docs/120` §15) | catalog reads only, no row of another system |
| A secret must not appear where a copy of the terminal carries it | `docs/122` §E.2 | this script prints none, so its output may be pasted whole |

### A.1 Three census defects, found by running the documented procedure first

`tools/audit/production_census.sql` was run in the sandbox, against the
staging-like database the harness builds (the release package at digest
`1bc95524…`, migrations 001–028, the simulated estate), as the two roles
`docs/79` §3 prescribes. **All three defects were measured there, not inferred.**

| # | Defect | Measured | Fix |
|---|---|---|---|
| **1** | **The documented DATA run could never reach a verdict** | With one cross-operator site planted, `dnb_adminapi` printed *"sites whose service belongs to ANOTHER customer : 1"* and then **`>> INDETERMINATE`**. Its refusal to read the migration ledger — **schema** evidence — set the same flag as a hidden **data** read. The owner's run cannot see the data at all (FORCE RLS). So **neither documented run could say CLEAR or BLOCKED**, while `docs/79` says *"A census that reports INDETERMINATE has not been run"*. The O-1 acceptance harness had asserted the withheld verdict as intended (its Phase 6); its Phases 1–5 ran as a superuser, which is why the gap never showed | the ledger read is recorded separately and reported in SECTION 6 beside the verdict, **not as part of it** |
| **2** | **A refused read aborted the whole run** | With one projection unavailable, as at an older migration level, SECTION 4 raised *"permission denied for function mt_current_customer"*: **psql exit 3, no verdict line** — against `docs/79`'s *"SKIPPED, not fatal"* | sections 2, 3 and 4 each skip on refusal and withhold the verdict, as 1b and 1c already did |
| **3** | **A hidden read counted as a measurement** (latent) | No current role mixes the two read paths: `dnb_adminapi` executes all seven projections and selects no base table; `dnb`, `dnb_admin`, `dnb_app` and `dnb_worker` select every base table and execute no projection, so they read all zeros, which the positive control already catches. **With one constructed grant** in the sandbox, sites read through the base table and services through the projection gave **`>> CLEAR` over a real violation**; the reverse gave **`>> BLOCKED(6)`** where one row blocks. An older schema level could produce such a mix | any base-table read of a row-security table by a role that does not bypass it is **HIDDEN** and withholds the verdict |

Also corrected: SECTION 0 told a non-bypassing role to *"re-run as a role that
bypasses RLS"*, contradicting `docs/79` §3; it now describes what the run
actually does. SECTION 5 says *NOT MEASURED* only when something was not.

**After the fix**, the same sandbox, every run exiting 0:

| Run | Verdict |
|---|---|
| `dnb_adminapi`, violation present | **BLOCKED(1)** |
| `dnb_adminapi`, clean estate | **CLEAR** |
| owner `dnb` | INDETERMINATE by design (HIDDEN), migration ledger read: 28 |
| superuser | BLOCKED(1) — unchanged |
| `dnb_adminapi` without the services projection | INDETERMINATE, sections 1b and 4 UNREADABLE, **no abort** |
| the two constructed mixes | INDETERMINATE (HIDDEN) |

**`tools/audit/o1_acceptance.php`**: it predated migration 026 and could no
longer install (the installer rightly refused to invent `dnb_staffauth`'s
password), so `DNB_STAFFAUTH_PASS` was added; Phase 6 was **rewritten, not
deleted**, to the verdict the documented run must give; Phase 7 was added for
hidden reads, the abort, and a restore control. **68 assertions pass** (was 52).
**Control on the controls:** against the pre-fix census, **11 of 68 fail** —
exactly the new ones, including the false CLEAR and the abort.

---

## B. Decisions

| # | Decision | Why |
|---|---|---|
| **D-1** | read only; no migration, repair or configuration change; GATE 2 untouched | `docs/79` §7, `docs/107` |
| **D-2** | question (b) from **configuration**: `DNB_DSN` searched in every container (`docker ps -a`) and every swarm service; **only host, port and dbname** of a found DSN are printed | the key every Domain-B process needs; a DSN may carry `user=` and `password=` |
| **D-3** | question (a) from **catalogs**: every running PostgreSQL container (image name contains *postgres*), its database list, and `to_regclass('public.mt_migrations')` in each — as the container's own `POSTGRES_USER` over its local socket, `default_transaction_read_only=on`, `psql -w` so nothing can prompt. **A container or database that cannot be opened is NOT CHECKED, never "no ledger"** | answers (a) with evidence rather than inference. It opens UISP's and the Phase-0 PostgreSQL containers **at catalog level only**; no row of either is read |
| **D-4** | the census on staging, twice, as `docs/79` §3: `dnb_adminapi` gives the DATA verdict, the owner gives the SCHEMA level. The SQL is fetched from the branch and **refused unless its sha256 is `513218356a1bb933…61033983`** | the reviewed instrument, and nothing else, runs |
| **D-5** | host PHP is reported: without `pdo_pgsql` no host-level PHP process can reach PostgreSQL (`docs/120` §1.3 measured it absent). Crontab and systemd are **not** searched, and the output says so | stated, not assumed |
| **D-6** | nothing secret is printed: DSN parts as above; the census prints counts and 8-character UUID prefixes (`docs/79` §5) | the whole output can come back |
| **D-7** | evidence kept under `/root/dnb-staging-evidence/census-<time>/`, mode 0700 | transcripts `docs/79` §6 asks for |
| **D-8** | the result **informs** GATE 2 and authorises nothing | `docs/79` §7b |

---

## C. The handover — one command, as root on the server

```sh
curl -fsSL -o /root/dnb-census.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-census.sh \
  && sh /root/dnb-census.sh 2>&1 | tee /root/dnb-staging-evidence/census-$(date -u +%Y%m%dT%H%M%SZ).log
```

| Step | What it does |
|---|---|
| 0 | fetches the census from the branch; refuses unless the digest matches (nothing else runs) |
| 1 | lists every container and swarm service carrying `DNB_DSN`, host/port/dbname only; reports host PHP |
| 2 | for every running PostgreSQL container: each database, and whether it holds the Domain-B ledger |
| 3 | the census on staging: run 1 as `dnb_adminapi` (DATA verdict), run 2 as the owner (SCHEMA level); both transcripts printed in full |
| 4 | a five-line result and one summary line |

Expected, if the server is as the stage-1 audit recorded it: *Domain B services:
NONE outside staging*; the ledger only in `dnb-staging-postgres / dnb`; a staging
verdict; 28 migrations. **Expectation, not result.**

---

## D. Harness evidence — `scripts/harness/census/`

The real script, unchanged, against a fake `docker` describing a server:
staging's three containers, the Phase-0 RADIUS PostgreSQL, UISP's PostgreSQL,
other services whose settings carry secrets, and two swarm services. Each fake
PostgreSQL container maps onto real databases of the local cluster, so every
catalog query and both census runs execute for real. The stub **refuses any
catalog query outside a read-only session**. **27 assertions, 0 failed.**

| Scenario | Asserted |
|---|---|
| S1 the expected server | exit 0; 9 containers and 2 services searched; exactly the two staging containers, host/port/dbname only; *NONE outside staging*; 5 databases looked at; ledger in staging only; **CLEAR** from `dnb_adminapi`; **28 migrations, last 028**; **no planted secret in the output**; **the audit trail unchanged**; every catalog query read-only |
| S2 a Domain-B container outside staging, password inside its DSN | found; printed host/port/dbname only; neither the password nor `user=` printed |
| S3 a swarm service carrying `DNB_DSN` | listed and counted outside staging |
| S4 the ledger in a database outside staging | the other holder named |
| S5 a PostgreSQL container that cannot be opened | NOT CHECKED with the reason; the summary does not call the scan complete |
| S5b a database listed but not openable | NOT CHECKED, never *no ledger*; counted |
| S6 an O-1 violation in staging | **BLOCKED(1)**, the offending pair enumerated |
| S7 a census file that is not the reviewed one | refused before anything ran; no container even inspected |
| S8 the staging database unreachable | *NO VERDICT*, never a verdict; the run completes |

**Controls on the controls** — four deliberately broken copies:

| Defect | Assertions that fail |
|---|---|
| the full DSN printed | 4, including *no planted secret* |
| the digest check skipped | 2 |
| catalog queries outside a read-only session | 4 |
| a database that cannot be opened counted as *no ledger* | **0 at first — an assertion gap**; scenario S5b was added, then 2 |

**What the harness cannot prove:** the real server's containers, images and
database credentials; whether UISP's PostgreSQL accepts its own user over the
local socket (if not, it is reported NOT CHECKED, D-3).

---

## E. What the result will mean

| Result | Meaning | Next |
|---|---|---|
| nothing outside staging, ledger only in staging, **CLEAR**, nothing unchecked | **there is no production Domain B**: `docs/79` question 1 is answered *not deployed*, with the method recorded. O-1's GATE 2 concerns staging's synthetic data and every future install | the operator decides GATE 2: whether the guarded O-1 constraint becomes migration 029. Step 3 waits on that decision |
| as above, but something NOT CHECKED | *not deployed* cannot yet be said | look at what was not checked first |
| anything outside staging | a deployment nobody has recorded | stop; it gets its own census before any decision |
| **BLOCKED(n)** on staging | the rows are synthetic (`SIM-`) | a per-row decision (`docs/107`), then the census again |
| INDETERMINATE or NO VERDICT | the census has not been run | paste the output back |

---

## F. Result — 2026-09-23 22:08 UTC: nothing outside staging; staging CLEAR

The operator ran the command at 22:08:00 UTC; it ended at 22:08:03 and changed
nothing.

| Step | Measured on the server |
|---|---|
| 0 | census file verified, sha256 `513218…3983` |
| 1 | **24 containers (running and stopped) and 7 swarm services searched**; `DNB_DSN` is carried by `dnb-staging-api` and `dnb-staging-worker` only, both `host=dnb-staging-postgres port=5432 dbname=dnb`; host PHP lacks `pdo_pgsql` |
| 2 | **4 running PostgreSQL containers, 8 databases, every one opened**: the ledger only in `dnb-staging-postgres / dnb`; none in `dn-phase0-postgres` (`postgres`, `radius`), `wa_evolution-api-db` (`postgres`, `wa`) or `unms-postgres` (`postgres`, `unms`); **NOT CHECKED: none** |
| 3, run 1 | `dnb_adminapi`, every read through a projection, **nothing hidden or refused**: 5 sites, 3 services, 3 principals (all `owner`/`active`; 0 `operator` rows for 027 to rewrite), 5 devices (1 unsited, 5 distinct tunnel addresses), 17 vouchers, 3 batches; **every proposed constraint 0 blocking rows**; **`>> CLEAR — 11 row(s) seen, 0 would refuse the O-1 composite FK`** |
| 3, run 2 | owner `dnb`: **28 migrations, `001` … `028_admin_router_lifecycle_and_provisioning.sql`**; data HIDDEN and verdict INDETERMINATE, as designed |

### F.1 What this establishes, and its limits

- **`docs/79` question 1 is answered: no Domain B service is deployed outside
  staging** on this host, checked by the configuration of every container,
  stopped ones included, and every swarm service. Per `docs/79` §0 no
  production census is needed.
- `docs/79` §6: **(a)** no other running PostgreSQL database on this host holds
  the Domain-B ledger; **(b)** nothing runs against one; **(c)** the census ran
  on the DSN the staging deployment itself uses.
- **Production Domain B is therefore NOT DEPLOYED, and that is the production
  data state for this host at 22:08 UTC.** The only Domain-B database is
  staging's, and its data is synthetic (`SIM-`).
- **Limits, stated:** this host only; another DishNet host, if one exists, was
  not examined. Step 2 opens running PostgreSQL containers whose image name
  contains *postgres*; a stopped database container is not opened, though step
  1 found no Domain-B configuration anywhere, stopped containers included.
  Crontab and systemd were not searched, and host PHP cannot reach PostgreSQL.

### F.2 What it unblocks, and what it does not decide

- **GATE 2 of O-1 is now the operator's decision** (`docs/107`): whether
  `tools/audit/o1_composite_fk.sql` becomes **migration 029**, applied to the
  development and test schema and, through the redeploy script, to staging. Its
  evidence is complete: staging CLEAR under the documented role, nothing else
  anywhere. **Nothing here takes that decision**, and the migration keeps
  `docs/79` §7b's six steps, including independent verification afterwards.
- SECTION 4 also shows 0 blocking rows for `mt_vouchers.site_id NOT NULL` and
  for the voucher and batch composite FKs. Those are **separate** decisions
  (`docs/77`, `docs/78` §4.2), and `docs/105` forbids bundling them with O-1.
- U-2 (`ucrm_client_id NOT NULL`) waited on E-2, *how many `mt_customers` rows
  production holds*. The answer is **none, because no production Domain-B
  database exists**. U-2 itself stays a design decision; `docs/110` froze the
  standalone boundary it would break.

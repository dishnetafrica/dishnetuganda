# 125 — Operators, their HotSpot service and their locations, created from the Admin panel

**Status:** review written **before** code, 2026-09-23 (§A–§C). The build record
follows in §D. **REDEPLOYED on staging 2026-09-24 04:47:05 UTC, first attempt,
every check held (§F).** It came after the migration-029 result (`docs/124` §H),
by its own command pinned to its own commit (§E). **Not in production:** the
DishNet host holds no production Domain B (`docs/123` §F).

**Why now.** Roadmap step 3. The operator approved the plan *"add the fix
[O-1] … then I'll start on the screens for creating real operators and their
locations"* with *"i will go with your receommadantion"*. O-1 is closed in code
(`docs/124`), which `docs/105` required before any site writer exists.

---

## A. What governs this

| Rule | Where | Consequence here |
|---|---|---|
| Every spine writer is W-1: `SECURITY DEFINER`, a definer role owns it, `EXECUTE` to **`dnb_adminwrite` only**, one audit row in the same transaction, the actor a parameter from the identity boundary | `docs/112` §1, §5.3 | the three new functions, asserted |
| **No spine function may be EXECUTE-able by `dnb_admin`, `dnb_worker` or `dnb_app`**, and no new route may connect as `dnb_admin` | `docs/112` A-1, `docs/113` | asserted by enumeration |
| **Derive, never accept:** `mt_site_create` has **no customer parameter**; the operator comes from the service row; the route carries no customer | `docs/105`, `docs/106`, `docs/112` §1.5 | asserted on the catalogue and the route |
| **RULE I-1:** replay detection happens **before** the audited mutation; a replay writes no audit row | `docs/108` | the check is the first thing each function does after validation |
| The idempotency store must be **non-tenant**, keyed `(endpoint, key)` with a request digest, reachable by `dnb_adminwrite` only through functions | `docs/107` I-A, `docs/108` 0b, `docs/112` §4 | built here, **before** the first writer that needs it |
| Same key + different digest → **refuse**; a unique violation is not a replay until the stored digest matches | `docs/112` §4 | both paths tested, and the race |
| A table must not be created as the owner if it must stay private: migration 015's default privileges would hand `dnb_admin` rights on it | `docs/114` §N, migration 026 | the store is created under `SET LOCAL ROLE dnb_def_prov` |
| The first operator owner (a principal) is created only on its own instruction | `docs/116` J-1 | `POST /customers/{id}/principals` stays 501 |
| The uCRM link is its own audited act and is never required | `docs/110` | no uCRM field is accepted |
| Operators are `mt_customers`; the JSON key stays `customer` | `docs/117` | unchanged |
| Nothing contacts a router | F2 | none of this reaches the delivery boundary |

### A.1 What is measured, not assumed

| Fact | Evidence |
|---|---|
| `mt_customer_create(name, actor)` exists, is audited (`customer.created`, `actor_kind = 'staff'`), is owned by `dnb_def_prov`, and has **no production caller** | catalogue, 2026-09-23 |
| It is EXECUTE-able by **`dnb_admin`** as well as `dnb_adminwrite` — a pre-existing grant that `docs/112` A-1 would not allow for a new function | catalogue. **Not changed here**: the simulator and the test fixtures call it that way; revoking it is F-3's remediation |
| Every function `dnb_def_prov` owns is reachable **only** from `dnb_admin` and `dnb_adminwrite` — no customer-plane role | catalogue, enumerated |
| `dnb_def_prov` holds INSERT on `mt_customers` and **nothing** on `mt_services` or `mt_sites` | catalogue |
| `mt_idempotency` is keyed `(customer_id, key)` with `customer_id NOT NULL`, and `dnb_adminwrite` holds no table privilege at all | `docs/107`, `docs/112` §4 |
| `mt_customers.status` is `active · suspended · closed`, default `active`; `mt_services.kind` permits exactly `mikrotik_hotspot`; `mt_services.status` is `active · suspended · ended` | catalogue |
| The panel draws write controls without checking capabilities itself; the server's 403 is the authority | `panel/app.js` |

---

## B. Decisions

| # | Decision | Reason |
|---|---|---|
| D-1 | **Scope:** three writes — create an operator, start its HotSpot service, add a location — and the store they need. **Not here:** the operator-owner login (J-1), the uCRM link (U-1), plans and vouchers (G-C2), editing or ending anything | the approved step, and nothing it does not need |
| D-2 | **The store:** `mt_admin_idempotency (endpoint, key, request_digest, actor, result, created_at)`, primary key `(endpoint, key)`; `endpoint` limited to the three names; `key` `^[A-Za-z0-9._:-]{8,128}$`; digest 64 hex characters. **Created under `SET LOCAL ROLE dnb_def_prov`** so no login role inherits a default privilege, and asserted: **no login role holds any privilege on it.** No row security: it has no tenant, and its isolation is privilege, as with `mt_staff` | `docs/108` 0b; the 026 lesson |
| D-3 | **Order inside every function:** validate → compute the digest **in SQL** from the parameters → look up the key (replay or refuse) → read-only existence checks, returning NULL with nothing claimed when the target does not exist → **claim the key** with `ON CONFLICT DO NOTHING`, re-reading on conflict → mutate → audit → store the result | RULE I-1; the digest cannot be forged by the caller; the claim closes the race |
| D-4 | **Operator:** `mt_admin_operator_create(name, key, actor)` wraps the existing `mt_customer_create`, which stays the one implementation and writes the one audit row. Name required, at most 120 characters. **No uniqueness on the name** — none is established, and inventing one is a business rule. The panel warns when the name already exists; the key stops a double submission | one writer; `docs/112` §1.1 |
| D-5 | **Service:** `mt_admin_service_create(operator, key, actor)`. The kind is the one legal value. The operator must exist (else 404) and be `active` (else refused). An operator may hold more than one service — the schema is 1:N — so only the key stops a duplicate | `docs/112` §1.4 |
| D-6 | **Location:** `mt_admin_site_create(service, name, location, key, actor)` — **no operator parameter**. The operator is read from the service row. The service must exist (else 404) and be `active`, and its operator `active` (else refused). Name required, at most 120 characters; location optional, at most 200. The O-1 key (029) is the floor beneath | derive, never accept |
| D-7 | `dnb_def_prov` gains row policies and grants exactly for what the three functions do: SELECT on `mt_customers`, `mt_services`, `mt_sites`; INSERT on `mt_services`, `mt_sites`. Named as 017 names them. It is reachable only from the Admin plane (§A.1), so no customer-plane path widens | least privilege for the job |
| D-8 | **Capabilities:** operator create → `customers.write`; service → **`services.write`, new**; location → `sites.write`. Admin holds all; **Sales** holds the three; NOC and Support hold none of them | creating commercial records is a sales act; NOC's role is the network |
| D-9 | **Routes:** `POST /api/v1/admin/customers`, `POST /api/v1/admin/customers/{customer_id}/services`, `POST /api/v1/admin/sites`. A body carrying a field the server derives — `id`, `actor`, `status`, `created_at`, `customer_id` on a location, `ucrm_client_id`, `radius_ref` — is **400, refused rather than ignored**. `idempotency_key` is required. **201** new, **200** replay, 404, 409 with the reason | the router routes' shape (`docs/121`) |
| D-10 | **Façade:** `Dn\Admin\OnboardingAdmin` on `dnb_adminwrite`, reaching exactly the three functions and never a table | as `RouterAdmin` |
| D-11 | **Panel:** a fourth client, `panel/onboarding.js`, with exactly three methods; `api.js` stays read-only. *Add an operator* on the Operators page; an operator page listing its services and locations, with *Start the HotSpot service* and *Add a location*. **The location form sends no operator.** Keys are minted once per rendered form | the 028 pattern |
| D-12 | **Simulator:** operators through the new wrapper; services and locations through the new writers, with fixed `sim-` keys. They are the real path now, and the simulator is built on real paths | `docs/91` |
| D-13 | **Manifest:** `writes.bound` becomes seven; `declared_unbound` keeps plans, voucher batches, disconnect and principal creation | the served surface must equal the declared one |
| D-14 | **Staging:** a separate command, after the 029 result; the 029 command stays pinned to its commit | `docs/124` §E |

---

## C. What this does not do

- It creates no login for anybody. An operator created here is managed by
  DishNet staff until a principal exists (`docs/112` §2.1).
- It changes no existing function. `mt_customer_create`'s `dnb_admin` grant
  stays (F-3). The test fixtures keep creating services and sites as `dnb_app`
  (B-3, open).
- It does not bind `POST /customers/{id}/principals`, plans, voucher batches or
  disconnect.
- Nothing in it is HARDWARE VERIFIED, and F6-B stays NOT AUTHORIZED.

---

## D. Build record

### D.1 What was built

| File | What |
|---|---|
| `migrations/030_admin_operator_onboarding.sql` | the store (D-2); two internal helpers, `mt_admin_idem_seen` and `mt_admin_idem_claim`, which run with their caller's privileges and are executable by their owner only; the three writers (D-4…D-6); the policies of D-7; a self-verification block that refuses to commit if any of it is wrong |
| `src/Admin/OnboardingAdmin.php`, `OnboardingRefused.php` | the façade (D-10) |
| `src/Admin/Capability.php`, `StaffRole.php` | `services.write`, held by Admin and Sales (D-8) |
| `src/Api/AdminRoutes.php`, `plugin/public/api.php` | the three routes (D-9) and their wiring; `/sites` leaves the declared-unbound list |
| `plugin/plugin.json` | seven bound estate writes, the three new ones on the `admin-write` gate; four declared-unbound (D-13) |
| `src/Plugin/Simulator.php` | operators, services and locations through the new writers (D-12) |
| `panel/onboarding.js`, `panel/app.js` | the client and the screens (D-11) |
| `panel/index.html` | one CSS rule, §D.3 |
| `plugin/doc/INSTALL.md` | one row in the capability table |

### D.2 Proofs

**`tests/test_operator_onboarding.php`, 153 assertions:**

- **Catalogue.** All five functions are owned by `dnb_def_prov`, with no `PUBLIC` EXECUTE. The three writers are `SECURITY DEFINER` with a fixed `search_path`, executable by `dnb_adminwrite` and their owner only. The helpers are callable by their owner only. The location writer's arguments are exactly service, name, description, key and actor, with no operator. None reads a tenant context.
- **The store.** Its owner is `dnb_def_prov` and it has no row security. **No login role holds any privilege on it**, enumerated from `pg_roles`, with the owner as the positive control. The control on the control: an owner-created table **would** have handed `dnb_admin` SELECT by default, which is why the table is created as `dnb_def_prov`.
- **Operator.** One row and one audit row (`customer.created`, the staff actor, `actor_kind = 'staff'`). A replay returns the first result and writes nothing. The same key with another name is refused and writes nothing. Validation refusals are tested for the name, the key and the actor.
- **The race, by execution.** A second database session claims a key and holds it uncommitted. The same request from the suite **waits on that claim, about one second**, and is then answered as a replay. The result is one operator and one audit row.
- **Service.** The target operator, the one legal kind, and `service.created`. A replay writes nothing. The key reused for another operator is refused. An unknown operator returns nothing. A suspended operator is refused. A second service with a new key is a new, audited act.
- **Location.** The operator is **derived**: naming B's service makes a location of B's. The audit detail names the operator and the service. Replays and a reused key behave as above. An unknown service returns nothing. An ended service and a suspended operator are refused. The name and description limits hold, and 029's key still stands beneath.
- **Routes.** NOC and Support get 403 naming the capability on all three routes; Admin and Sales hold all three capabilities. A new act answers 201 and a replay 200. The operator comes back through the Admin projection, without `radius_ref`, and the audit actor is the signed-in subject. **Every derived field is refused with 400**: eight on operators, two on services, five on locations. Missing keys, long names and other service kinds are 400. An unknown operator is 404; an unknown service or a suspended operator is 409 with the reason. A process with no write connection answers 501.
- **Panel.** `onboarding.js` makes exactly three POSTs and nothing else, sending through `routers.js`. `addLocation` sends nothing that names an operator. `api.js` is unchanged. Each form and the Start button carry a key minted per render. Nothing is credential-shaped. The gate rule of §D.3 is present.
- **Manifest, simulator, repository.** Each is asserted as §D.1 describes.

**Controls on the controls — four weakened copies of 030, each caught by the assertions aimed at it:**

| Copy | Caught by |
|---|---|
| the claim without `ON CONFLICT` | the race: the second request fails with a unique violation instead of replaying |
| the store created as the owner | the privilege scan: `dnb_admin`, `dnb_app` and others hold privileges on it |
| the writers also granted to `dnb_admin` | the EXECUTE enumeration, for all three |
| the operator inserted **before** the replay check | the counts: three operators where there should be one, and a replay that wrote |

**Deliberately updated existing assertions**, each with its reason in the text: the provisioning role's policy matrix; the last-migration pins; the manifest's bound and unbound lists and its surface string, in four suites; and the UI guard, which **did not see the three new calls at all**, because none of their names was on its verb list. That guard now names the onboarding calls and bounds them, adds their create shapes to the verbs forbidden elsewhere, and carries a control showing the scan fires. The O-1 suite's throwaway-database proof now applies 029 **alone** (files 001–029), so a later migration cannot blur what it shows.

**Numbers.**

| Check | Result |
|---|---|
| full suite | **36 suites, 3,500 assertions, 0 failed**, twice (was 35 / 3,327) |
| `plugin/bin/install-test.sh` | **85 of 85** |
| `o1_acceptance.php` | **75 of 75** |
| package | **123 files**, content digest **`4a6291849f0b687d2472909dd1e416af837227dcf648fd41fb99fc5c7a5fd571`** |

### D.3 Driven in a real browser — and one layout fault found

Headless Chromium drove the screens against a fresh local install with the
development sign-in, signed in as *sales*. It walked: the empty operator list,
*Add an operator*, the operator's page, *Start the HotSpot service*, *Add a
location*, and back to the list. A second operator with the same name made the
panel **ask first**; dismissing the question created nothing. The database
afterwards held one operator, one service and one location, with
`customer.created`, `service.created` and `site.created` by `dev`, `staff`. The
only failed request was the sign-in gate's own first question — 401, nobody
signed in yet, which is its job.

**Found, and fixed:** after sign-in an empty block a full screen tall sat
above the panel. The earlier record calls it *"a blank band above the sidebar on
first load"*. The cause is that `#gate{display:flex}` is an id rule, so it
outranks the browser's own rule for `hidden`, and the signed-out gate never
disappeared. One CSS line makes `hidden` win. **Measured:** the gate is
**860 px** tall after sign-in with the old stylesheet, and **0** with the new one.

### D.4 What comes next

- **Staging:** after the migration-029 result is back (`docs/124` §H), a
  separate command pinned to this build's commit applies 030 and restarts the
  two application containers. It is not written yet, so nothing can race the
  029 command. **The 029 result is back** — REDEPLOYED 2026-09-24 04:14:49 UTC
  — so the prerequisite is met. **The command is written, rehearsed and handed
  over in §E, and REDEPLOYED on staging at 04:47:05 UTC (§F).**
- **The operator-owner login** (`POST /customers/{id}/principals`) is the next
  decision: `docs/116` J-1 reserves it for its own instruction.
- Plans and voucher batches from the Admin plane wait for G-C2; the
  `session.disconnect` replay fix is still owed before F6-B.

---

## E. The handover — one command, as root on the server

```sh
curl -fsSL -o /root/dnb-redeploy.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-redeploy.sh \
  && sh /root/dnb-redeploy.sh 2>&1 | tee /root/dnb-staging-evidence/redeploy-$(date -u +%Y%m%dT%H%M%SZ).log
```

The same URL and file name as the 029 command, rewritten for this build. The 029
version, as the operator ran it, stays at commit `938c002`. Running the command
again is safe: it notes that the build and 030 are already in place, applies
nothing, and verifies everything again.

| Step | What it does | If it fails |
|---|---|---|
| 0 | read-only checks; reads which staff identity the API runs; refuses if `DN_ALLOW_REAL_BINDINGS` is set. **Requires 029 already applied, with its key in place** (`1|1|2|true`) — this command applies 030 on top of 029 and nothing else, because 029 needs the census re-taken and only its own command does that | stops, nothing changed |
| 1 | builds the artifact **on the server** from the reviewed commit **`46c778e`**, fetched by its hash; refuses unless the content digest is `4a629184…` and the build carries 030 and `onboarding.js` | stops, nothing changed |
| 2 | the new build's doctor must report no blocker. **Its warning lines are now printed**; one is expected, *plugin schema — 29 of 30 migration(s) applied*, because the doctor runs before the installer | stops, nothing changed |
| 3 | swaps the tree (the previous one kept) and runs the installer, which applies only 030. 030 checks its own catalogue and refuses to commit if anything is wrong | if 030 refuses, **the previous tree is put back** and nothing is restarted |
| 4 | **verifies independently.** (a) The catalogue: the store is owned by `dnb_def_prov` with no row security. The three writers are `SECURITY DEFINER`, owned by `dnb_def_prov`, and executable by `dnb_adminwrite` and nobody else; an ACL still at its default counts as `PUBLIC` holding EXECUTE. The helpers are callable by their owner only. The location writer takes no operator. 029's key holds, and the projections show sites with none crossing. (b) **An execution test as `dnb_adminwrite`, always rolled back:** create an operator; replay it and get the same operator; reuse its key for another name and be refused; start its service; add a location whose operator is **derived** from the service. (c) **Every other login role, enumerated from `pg_roles`, is refused each of the three writers, and every login role is refused the store**, each call in its own rolled-back transaction; the superuser's read of the store is the positive control. (d) Residue 0: no audit row by the probe actor, and no probe operator, location or key | stops with the reason; the new schema is in place and the old code keeps serving until someone restarts it (the previous build does not use 030) |
| 5 | restarts **only** the API and worker. Loopback: panel, `routers.js` and **`onboarding.js`** 200; the sign-in gate fix of §D.3 present in the page; session 401 from the `dishnet` provider. **Each of the three new routes answers an anonymous POST with 401** — routed and guarded, where a missing route answers 404 and an unbound one 501. Through the hostname: `/` and `onboarding.js` 200, session 401. No other container changed | stops with the reason |
| 6 | one result line | — |

**No census step, deliberately.** 030 validates nothing against existing rows: it
adds a table, five functions, grants and row policies. The census was GATE 1 for
029, and a census that is CLEAR authorises nothing anyway (`docs/79` §7b). The
command keeps the cheap evidence that O-1 still holds — the catalogue line and the
projection check — and requires 029 to be in place before it starts.

**Locks, measured.** `CREATE POLICY` takes **ACCESS EXCLUSIVE** on its table until
the migration commits; `GRANT` takes no table lock (both measured in a
rolled-back transaction on the development cluster). 030 creates policies on
`mt_customers`, `mt_services` and `mt_sites`, so readers of those three tables wait
for the length of the migration — milliseconds on staging's handful of rows.
Nothing fails; the API and worker keep running.

**Why an execution test and not only the catalogue.** Grants are the weakest
evidence in this project's hierarchy (`docs/103`). Rehearsal scenario R7 plants
`GRANT dnb_adminwrite TO dnb_admin WITH INHERIT TRUE` after the migration: the
catalogue still reads `3|3|0`, because a role membership is not in a function's
ACL, and **only the execution test finds it**.

It prints no secret, so its whole output may be pasted back. It touches no
production container, Traefik file, DNS record, firewall rule or other
PostgreSQL instance. The API keeps the real DishNet staff login.

**Rollback of the code**, printed by the script: move the previous tree back and
restart the two containers. Migration 030 needs no undoing — the previous build
never calls the table, the functions or the policies it adds.

### E.1 Rehearsal — `scripts/harness/redeploy/`

The real script, against a sandbox rebuilt from nothing into the staging state
**now**: the 028 build reproduced from commit `9f95353`, the simulated estate, the
stage-2 route, the API switched to the real staff login by the real
`dnb-staging-staff-login.sh`, and then **029 applied by the real 029 command**,
byte for byte the one the operator ran (sha256 `c558e26a…`, from commit
`938c002`). Only `docker` is faked; every database is real PostgreSQL. Before any
scenario the harness moves the branch on past the reviewed commit and asserts
that the new tip no longer builds the reviewed digest.

The fake `docker` gained two hooks for this: SQL planted just before the
installer runs, and just after it commits.

| Scenario | What is asserted |
|---|---|
| **R1** staging now | exit 0; the pinned commit built, not the tip; the doctor's one warning printed (*29 of 30 applied*); the installer applies exactly 030; ledger 30; the catalogue lines; the rolled-back execution test (replay, reused key refused, operator derived); **every login role of the cluster listed and refused**, the owner included; residue 0; `onboarding.js` and the gate fix served; the three anonymous POSTs 401; the hostname; exactly the API and worker changed; the estate's counts unchanged by the command |
| **R2** run again | exit 0; build and 030 already in place; *schema already current*; verification passes again |
| **R3** 029 not applied (ledger 28) | refused in step 0 with *on top of 029 and nothing else*; nothing built, installed or restarted; ledger 28 |
| **R4** wrong digest pinned | refused before anything changes; ledger 29, no store, no restart |
| **R5** a default privilege planted before the installer that would hand `dnb_admin` the store | **030 refuses by its own check**, naming `dnb_admin`; the previous tree is live again and the refused one kept aside; ledger 29, no store, no restart |
| **R7** `GRANT dnb_adminwrite TO dnb_admin WITH INHERIT TRUE` planted after the installer | the catalogue still reads `3|3|0`; **the execution test names `dnb_admin`**; no restart; the writes it made rolled back; the membership revoked again, because roles are cluster-wide |
| **R8** `GRANT SELECT ON mt_admin_idempotency TO dnb_app` planted after the installer | the execution test names `dnb_app`; no restart |
| **R6** the stage-2 posture | exit 0 with the development identity; doctor `--disposable`; the three anonymous POSTs 401; basic auth in front |
| **M1–M5** | five broken copies of the script, each caught: both step-0 gates removed (029 would ride in without its census, and the ledger leaves 28); no swap-back on refusal; the writer refusal not enforced (R7 then passes); the store refusal not enforced (R8 then passes); the execution test committing instead of rolling back (the script's own residue check fails it) |

The counts are in §E.2.

### E.2 Counts

**98 of 98 passed on two consecutive runs** (3 min 12 s and 3 min 11 s). The
first full run passed 97 of 97. After it, the fake `docker logs` was fixed: it
read `--since` as the container name, so the sandbox never saw the worker's log
line, although the real server prints it. One assertion was added for the
worker's binding. The script under test did not change between the runs.
Afterwards the development cluster held no leaked role membership, and ports
8099 and 443 were free.

| Scenario | Assertions |
|---|---|
| setup: the 029 command byte for byte; the branch tip no longer builds the reviewed digest | 2 |
| R1 staging now | 43 |
| R2 run again | 6 |
| R3 029 not applied | 5 |
| R4 wrong digest | 4 |
| R5 a default privilege; 030 refuses by itself | 8 |
| R7 a role membership after the migration | 7 |
| R8 a store grant after the migration | 4 |
| R6 the stage-2 posture | 7 |
| M1–M5 broken copies | 12 |

---

## F. Result

**REDEPLOYED 2026-09-24 04:47:05 UTC, on the first attempt, with no
correction.** The operator ran the §E command as root on the server, starting at
04:46:38 UTC, and pasted the whole output back. It printed no secret. The log
stays on the server under `/root/dnb-staging-evidence/`.

| Step | What the output shows |
|---|---|
| 0 | deployed build `780023ff…` (029); the real DishNet staff login — trusted proxy `172.22.0.1`, origin `https://portal-staging.dishnetuganda.com`; 29 migrations, last 029; **029 found applied, and O-1 `1|1|2|true`, before anything else** |
| 1 | built from commit **`46c778e6efd5…`, fetched by its hash**; 123 files verified against `SHA256SUMS`; archive sha256 `b9719337…03c461a6`; **content digest `4a629184…`, the reviewed one** |
| 2 | the new build's doctor, production posture: **24 checks, 23 ok, 1 warn, 0 blockers**. The warning is printed, and it is the expected one: *plugin schema — 29 of 30 migration(s) applied* |
| 3 | tree swapped, previous kept at `/opt/dnb-staging/app.prev-20260924T044638Z`; **the installer applied exactly one migration, 030**; 30 recorded, 30 files in the build |
| 4 | store owned by `dnb_def_prov`, row security off; writers **`3|3|0`**; helpers **`2|0`**; the location writer takes `p_service, p_name, p_location, p_idempotency_key, p_actor` — **no operator**; **O-1 after: `1|1|2|true`**; projections: 5 sites, 0 crossing. **Execution test as `dnb_adminwrite`, rolled back:** an operator created; the replay returned the same one; the reused key refused (*this idempotency key was already used for a different request*); the service started for it; the location's operator was the operator of its service. **8 login roles, enumerated from the instance: every one refused the store, and the 7 other than `dnb_adminwrite` refused all three writers** — `dnb`, the owner, included. Positive control: the store read as the superuser, 0 rows. **Residue 0** |
| 5 | loopback: panel, `routers.js` and **`onboarding.js` 200**; **the sign-in gate fix is in the served page**; session 401 from the `dishnet` provider, second factor required. **An anonymous POST on each of the three new routes: 401** — routed and guarded. Through Traefik: `/` 200, `onboarding.js` 200, session 401 the same. Worker `simulated-routeros`. **Only `dnb-staging-api` and `dnb-staging-worker` changed, and no container was removed** |

The doctor's warning is consistent with the expectation `docs/124` §H recorded
for the 029 run: on the same server, in the same posture, the one warning is the
migration the installer has not yet applied. It remains an observation of this
run, not of that one.

**What this establishes, on staging:**

- The three onboarding writes are live, behind the real staff login and one
  capability each.
- **Only `dnb_adminwrite` can call the writers, and no login role can touch the
  store.** This is proved by execution for every login role the instance has,
  not read off the grants.
- In the deployed schema, a replay returns the same operator, a reused key is
  refused, and a location's operator is derived from its service.
- O-1 is untouched, and the blank band is gone from the served page.

**What it does not establish:** that the screens work in a browser for a real
person — that is the next thing to try (§F.1). Nothing about production, which
holds no Domain B (`docs/123` §F). Nothing about a MikroTik: nothing is HARDWARE
VERIFIED, and F6-B stays NOT AUTHORIZED. The operator-owner login (J-1) is still
not bound.

### F.1 Trying the screens

Sign in at `https://portal-staging.dishnetuganda.com` as a DishNet staff member
with Admin or Sales. Open *Operators & sites*, then *Add an operator*. Open the
operator and use *Start the HotSpot service*, then *Add a location*.

**What is created there stays.** An operator cannot be deleted. Its creation
writes an audit row, and that row's key to the operator is `ON DELETE RESTRICT`
(`004_audit.sql`), so the delete is refused, as `docs/100` proved by execution.
Nothing in this build edits or ends an operator either. Staging data is not production data, but give
anything created there a name that says it is a test.

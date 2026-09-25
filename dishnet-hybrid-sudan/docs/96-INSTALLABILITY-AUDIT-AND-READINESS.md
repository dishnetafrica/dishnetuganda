# 96 — Installability audit and installation readiness

**Scope.** Is the MikroTik plugin installable, and can Bhavin open the DishNet
Admin panel from an installed copy? Real MikroTik management is **not** in
scope; that remains F6-B and is not authorized.

**Method.** Everything below was measured by running it. Where something could
not be measured, this document says so in those words rather than inferring.

---

## A. What was true before this session

The answer to "what is the installation artifact?" was: **there isn't one.**

| Searched for | Result |
|---|---|
| archive / package builder | none |
| `composer.json` | none |
| `Dockerfile`, `docker-compose*` | none |
| `*.service` (systemd) | none |
| `Makefile` | none |
| `.env` template | none |
| installer that creates its database | none — `Installer::install()` assumed one existed |
| production web-server configuration | none |

`plugin/` was a directory inside a repository containing six files
(`plugin.json`, `bin/plugin.php`, `public/api.php`, and the three `panel/`
files sit outside it). Nothing stated which of the nine top-level directories
were part of the deliverable, so the plugin could be *read* but not *handed to
anyone*.

**Classification before this session: D — a development project that had not
become an installable artifact.**

---

## B. Question 2 — does `plugin/bin/plugin.php` install, or only report?

It genuinely installs. `install` is not a status alias: it validates
configuration and extensions, runs `Migrator` over `migrations/`, and then
**re-reads the database and raises if the effect is absent** rather than
printing success. That last property is why the audit trusts it.

What it did **not** do, all measured:

| Gap | Consequence |
|---|---|
| does not create the database | `Database::owner()` fails, install cannot start |
| does not create the owner role | same |
| `uninstall` dropped 4 roles; the migrations create **12** | 8 roles left on the cluster after a "clean" uninstall |
| no preflight | a misconfiguration surfaced as a PDO exception mid-migration |

The role gap was not visible from the code. It appeared because `uninstall()`
was made to **verify itself afterwards**, and the verification reported
`dropped 0 of 12 role(s)` on the first run of the install test.

---

## C. Question 3 — every runtime dependency

| Dependency | Requirement | How established |
|---|---|---|
| PHP | ≥ 8.1 (ran on 8.4.19) | manifest + measured |
| PHP extensions | `pdo_pgsql`, `json`, `openssl` | `extension_loaded()` at install; also `random_bytes`, `hash_hmac` (core) |
| PostgreSQL | ≥ 14 (measured on 16.13) | `SHOW server_version` |
| PostgreSQL role | one owner with **CREATEROLE + CREATEDB**, **not** superuser, **not** BYPASSRLS | measured: install succeeds with exactly these |
| Database | one, dedicated. **The plugin does not create it.** | `bootstrap.sql` |
| Roles created by install | **12, cluster-wide** — 6 login + 6 definer | enumerated from `migrations/*.sql` |
| Filesystem | the package directory, readable by the web user. No writes at runtime; no upload, cache, log or temp directory | no `fopen(...'w')`, no `mkdir` outside the packager |
| Environment | **21 variables**, of which 2 are required (`DNB_DSN`, `DNB_TOKEN_PEPPER`) | grep of `src/ bin/ plugin/ tools/` |
| Web server | anything that serves `panel/` statically and routes `/api/v1/admin/*` to `plugin/public/api.php`. **The API reads the raw `REQUEST_URI` path**, so a prefix mount must be stripped before PHP sees it | `Request::fromGlobals()` |
| Migrations | run by `plugin.php install`; idempotent; recorded in `mt_migrations` | measured, including a second run |
| Worker / cron | `bin/worker.php` (intent queue) is a long-running process. **The Admin read panel does not need it.** Not bound. | read |
| Redis | **none** | no reference anywhere |
| Docker / container | **none required** | no Dockerfile, no container assumption in any path |
| TLS | not provided. The plugin terminates nothing | — |
| Process supervision | not provided. No systemd unit | — |

### The configuration gap this found

The manifest declared **5** configuration keys. The code read **21**. Among the
undeclared was `DNB_SECRET_KEY`, which `SecretBox` **refuses to run without** —
so `missingConfig()` could return empty while a runtime path failed closed. All
21 are now declared, and `tests/test_installability.php` re-derives the list
from the source on every run, so the two cannot drift apart again.

---

## D. Question 4 — what kind of thing is this?

**Not A (a UCRM plugin).** Measured, not assumed from the directory name:

- no UCRM plugin manifest, no `public.php` UCRM entry point, no UCRM hook
  registration, no UCRM API client, no read or write of any UCRM table;
- the single occurrence of "UCRM" in the code is the column `ucrm_client_id`,
  a stored foreign reference that nothing dereferences;
- the manifest declares `consumes_domain_a: false`, and the plugin owns its own
  PostgreSQL database, its own roles, its own HTTP surface and its own identity
  model. A UCRM plugin has none of those.

**Not C (an Admin panel package)** either — the panel is one of four parts; the
schema, the API and the worker are the rest.

> ### Classification: **B — an independent Domain-B service**, installable
> beside UCRM but not into it, **with the conditions in §G**.
>
> Before this session it was **D**. The work in §F is what moved it.

---

## E. The constraint on requirement 6, stated plainly

> **This session cannot reach the DishNet server.** There is no SSH client, no
> DSN, outbound HTTPS returns 403, and the Phase 0 database is loopback-only
> (`docs/78` §1.1). `CLAUDE.md` forbids network access, SSH discovery, DSN
> guessing and production probing.

"Install into an isolated location on the existing server" therefore **was not
performed and could not be**. It is not reported as done.

What was done instead is the honest equivalent, in the pattern `docs/79` used
for the census: **a complete disposable install → verify → uninstall test in
this container**, plus an operator runbook (§H) for the server. The test is
shipped as `plugin/bin/install-test.sh` so the operator runs the *same* proof
rather than a description of it.

---

## F. What was built — the minimum packaging, and nothing else

No business feature was added.

| File | What it is |
|---|---|
| `plugin/bin/package.sh` | builds `dishnet-mikrotik-<version>.tar.gz`; **extracts it again and verifies it against its own `SHA256SUMS`** before reporting success |
| `plugin/bin/bootstrap.sql` | the privileged step, as a file the operator reads before running: creates one owner role and one empty database, **nothing else**. Idempotent — a re-run rotates no credential |
| `plugin/bin/serve.php` | one-origin router (panel + API), shipped **inside** the package because `tools/` is excluded from it |
| `plugin/bin/install-test.sh` | the disposable test, 32 checks, builds its own PostgreSQL cluster |
| `plugin/.env.example` | all 21 variables, required/secret marked, no real values |
| `src/Plugin/Doctor.php` | preflight: 18 checks in four states — `ok`, `warn`, `blocker`, and **`skip` = NOT MEASURED**, so an unreachable database can never read as a clean bill |
| `src/Plugin/Installer.php` | `uninstall()` now covers all 12 roles, revokes before dropping, and **verifies against the catalogue afterwards** |
| `tools/dev_server.php` | path-traversal fix (§G, S-1) |
| `tests/test_installability.php` | 68 assertions, mostly drift guards |

### The package

**90 files, 128 KB.** Contains `src/`, `migrations/`, `plugin/`, `panel/`,
`bin/worker.php`, `VERSION`, `SHA256SUMS`.

Deliberately excluded, each for a reason:

- **`tests/`** — the suite needs a BYPASSRLS fixture identity. Shipping it puts
  a row-level-security bypass into a deployment.
- **`tools/`** — development helpers.
- **`docs/`** — the decision record; useful to read, not needed to run.
- **`public/`** — the **customer** API front controller. This package installs
  the Domain-B control plane and its Admin panel. Shipping that entry point
  would put an unbound HTTP surface on the server.

---

## G. Findings

### S-1 — path traversal in the development server — MEASURED, FIXED

`tools/dev_server.php` had no containment check. Against the running server:

```
GET /../src/Db/Database.php   ->  HTTP 200, PHP source returned
```

Source disclosure is **credential disclosure here**, because the migrations
carry the development role passwords as SQL literals (B-1). Both servers now
resolve with `realpath` and require the result to sit under `panel/`, and send
`X-Content-Type-Options: nosniff`. The install test asserts `404`.

### B-1 — six role passwords are repository literals — **BLOCKS A REAL INSTALL**

`migrations/*.sql` create six login roles with password literals that are
published in this repository, and `Database::connect()` defaults to the same
strings. Nothing in the install path rotates them.

Measured after a clean install:

```
BLOCK  development passwords
       ACCEPTED by: dnb_app, dnb_worker, dnb_admin, dnb_adminapi,
                    dnb_adminwrite, dnb_radius
```

That is measured by **attempting a connection**, not by reading `pg_authid` —
the stored verifier is a SCRAM hash and cannot be compared to a candidate, so
the only honest test is to try it.

**This is the single blocker for installing anywhere real.** The fix is a
schema/privilege change (env-driven passwords, or an `ALTER ROLE` step after
install) and is **not authorized here** — it touches role creation, which is
migration territory. `doctor` refuses to pass while the condition holds, so it
cannot travel silently.

### B-2 — the panel renders nothing under the production identity binding

Measured on a clean install:

| Identity | `/` | `/api/v1/admin/health` |
|---|---|---|
| `DenyAllIdentity` (production, W-4 open) | 200 | **401** |
| `DevStaffIdentity` (`DN_DEV_STAFF_IDENTITY`) | 200 | 200 |

So the answer to *"can Bhavin install it and open the Admin panel?"* is **yes —
under the development identity gate, as a demonstration install.** Staff
authentication (W-4) is OPEN, so there is no other way. This is a stated
condition, not a defect, and not something to work around by binding an
identity that has not been decided.

### B-3 — the 12 roles are cluster-wide

PostgreSQL roles belong to the cluster, not the database. Installing onto the
cluster that serves UCRM would add 12 roles visible there (granted nothing in
it), and **uninstall would drop them cluster-wide**. That is why the disposable
test builds its own cluster: it is the only arrangement in which "uninstall left
nothing behind" is both testable and safe to test.

`doctor` now reports whether the target database shares its `public` schema with
anything else, and names what it found.

### Lesser notes

- `/favicon.ico` → 404. `panel/` ships no icon. Cosmetic.
- Voucher rows display an opaque reference (`25971f2a`). Correct under
  Decision 3 — the voucher code must never enter this path — but it is the kind
  of identifier previously flagged as looking accidental. UX, not installability;
  **not acted on**.

---

## H. The installation plan for the existing DishNet server

**Not executed. Nothing in this session reached that machine.**

### What it touches

| Thing | Touched? |
|---|---|
| UCRM | **No.** No file, no database, no route, no container |
| Traefik | **No** route required *if* served on a port. A route is required only if it is to be reachable by name — that is a separate, explicit approval |
| Existing PostgreSQL | **Only if you install into that cluster**, which adds 12 cluster-wide roles (B-3). **Recommendation: a separate PostgreSQL instance.** |
| Existing Redis | **No.** The plugin does not use Redis |
| Docker / EasyPanel / Swarm | **No.** No container is created or modified |
| Domain A (Starlink) | **No.** `consumes_domain_a: false`, measured |
| Ports | one HTTP port, bound to `127.0.0.1`. **Nothing listens publicly** |

### Prerequisites

1. PHP ≥ 8.1 with `pdo_pgsql`, `json`, `openssl`.
2. PostgreSQL ≥ 14 — **preferably an instance of its own** (B-3).
3. A superuser on that instance, for `bootstrap.sql` only.
4. A directory for the package, readable by the account that serves HTTP.

### Commands

```sh
# 1. build the artifact (on a machine with the repository)
sh plugin/bin/package.sh
#    -> dist/dishnet-mikrotik-<version>.tar.gz

# 2. on the server: unpack and check the artifact against itself
tar -xzf dishnet-mikrotik-<version>.tar.gz -C /opt
cd /opt/dishnet-mikrotik-<version> && sha256sum -c SHA256SUMS

# 3. the privileged step — read this file before running it
psql -U postgres -d postgres \
     -v db=dnb -v owner=dnb \
     -v owner_pass="$(php -r 'echo bin2hex(random_bytes(24));')" \
     -f plugin/bin/bootstrap.sql

# 4. configure
cp plugin/.env.example /etc/dnb/dnb.env && chmod 0640 /etc/dnb/dnb.env
#    edit it: DNB_DSN, DNB_TOKEN_PEPPER, DNB_SECRET_KEY, every DNB_*_PASS

# 5. preflight — it must pass before step 6
set -a; . /etc/dnb/dnb.env; set +a
php plugin/bin/plugin.php doctor

# 6. install
php plugin/bin/plugin.php install
php plugin/bin/plugin.php status

# 7. demonstration data (optional; refuses if real bindings are enabled)
php plugin/bin/plugin.php simulate

# 8. serve, on the loopback interface
DN_DEV_STAFF_IDENTITY=yes-development-only \
  php -S 127.0.0.1:8099 plugin/bin/serve.php
#    then reach it over an SSH tunnel:  ssh -L 8099:127.0.0.1:8099 <server>
```

**Step 5 will report B-1 and refuse.** That is the gate working. Clearing it is
a separate, authorized change.

### Uninstall

```sh
php plugin/bin/plugin.php uninstall --i-understand-this-drops-data
#   drops every mt_* table and function, and the 12 roles
#   does NOT drop the database or the owner role — it did not create them

psql -U postgres -d postgres -c 'DROP DATABASE dnb'    # if you want it gone
psql -U postgres -d postgres -c 'DROP ROLE dnb'
rm -rf /opt/dishnet-mikrotik-<version> /etc/dnb
```

`uninstall` prints what it verified, and names anything it could not drop.

---

## I. The disposable installation test — what it proved

`sh plugin/bin/install-test.sh` — **32 checks, 32 passed, 0 failed.**

It builds its own PostgreSQL cluster (unix socket only, **no TCP port**),
builds the artifact, extracts it, and runs the whole lifecycle from the
extracted copy — not from the repository.

| Stage | Proved |
|---|---|
| cluster | starts with **0** `dnb` roles; opens no TCP port |
| artifact | extracts; matches its own `SHA256SUMS`; ships no `tests/` |
| preflight | reports blockers before bootstrap; says **NOT MEASURED** where it could not look |
| bootstrap | creates the database; owner is **not** superuser and does **not** bypass RLS |
| migrations | all 21 applied; a second run is a no-op; status agrees |
| preflight again | **detects the development passwords** (B-1) |
| serve, production identity | panel 200, API **401**, traversal **404** |
| serve, dev identity | health 200; estate empty before the simulator |
| simulator | customers, routers, vouchers and sessions all visible **through the API**; every serial carries `SIM-`; refuses to run alongside real bindings |
| uninstall | refuses without confirmation; then drops everything |
| residue | 0 `mt_` tables, 0 `mt_` functions, **0 of 12 plugin roles remain** |
| what survives | the database and the owner role — the plugin did not create them |

A **positive control** asserts the 12 roles exist after install. Without it,
"no plugin role remains" would pass equally well on a cluster where install had
created none.

### The panel, in a real browser

Loaded from the installed copy at `127.0.0.1:8099` in Chromium:

- 12 navigation items across the two planes;
- **17 `SIM-` identifiers** rendered on the landing view;
- the Routers view lists 5 simulated routers, `SIM-MT-0001` … `SIM-MT-0005`,
  across five lifecycle states;
- the Vouchers view lists all 17 vouchers under the **SIMULATED — no router or
  AAA system has been contacted** banner;
- every network request returned 200 except `/favicon.ico`.

The `staged` router shows no name. That is correct — a device is named when it
is claimed, and this one has not been.

---

## J. Installation readiness

> # STATUS: **INSTALLABLE WITH CONDITIONS**
>
> The artifact exists, installs, serves, and uninstalls cleanly — measured end
> to end. It is **not** ready for an operational installation, for two reasons
> that are stated rather than worked around.

### Blockers for a real installation

| | Blocker | Status |
|---|---|---|
| **B-1** | six role passwords are repository literals | **must be fixed before any non-disposable install.** `doctor` refuses while it holds. The fix is a migration change — **not authorized here** |
| **B-2** | no staff authentication (W-4 OPEN); the panel only renders under `DN_DEV_STAFF_IDENTITY` | a demonstration install is possible today; an operational one is not |

### Conditions, not blockers

| | Condition |
|---|---|
| **B-3** | the 12 roles are cluster-wide — prefer a separate PostgreSQL instance |
| **B-4** | no TLS, no production web server config, no process supervision. Serve on loopback and reach it over an SSH tunnel |
| **B-5** | `DNB_SECRET_KEY` must be set before any device-credential path is used |

### Answering the objective

> *"Can Bhavin install the MikroTik Plugin and open the DishNet Admin panel?"*

**Yes** — as a demonstration install, on a PostgreSQL instance of its own,
served on loopback, under the development identity gate, with the simulated
estate. Every step of that is measured above.

**Not yet** as an operational installation: B-1 and B-2 stand in the way, and
both are decisions rather than code this session was authorized to write.

### Unchanged by this work

F6-B remains NOT AUTHORIZED. The Admin write routes remain unbound and
`DenyAllIdentity` remains the production binding. The portal remains unbuilt.
Decision 5 remains OPEN and gated. The production census remains the next gate.
**No architecture gate moved.**

---

## K. Assertions

| | |
|---|---|
| baseline (`8b23010`) | 1,488 |
| added — `tests/test_installability.php` | **+68** |
| **total** | **1,556**, 27 suites, all green |
| install test | 32 checks, separate from the suite (it builds a PostgreSQL cluster) |


---

# AMENDMENT — B-1 is closed (`docs/97`)

**`dishnet-mikrotik-0.1.0-rc1.tar.gz`** — 94 files.

**Content digest `aa48b4b46c9e4e15c97262f31e3e7f44961062a733b9499fc7a65863b1db63c7`.**

That is the digest of the archive's own `SHA256SUMS`, which lists every file by
content. The archive's *own* sha256 is **not** an identity: `tar` records
modification times, so two builds of identical source produce different bytes —
measured, not assumed. Compare the content digest when asking whether two
artifacts hold the same code; `package.sh` prints both and says which is which.

**B-1 no longer stands.** The migrations create every login role with no
password; the installer provisions one, supplied or generated, at install time.
Measured on a cluster that requires `scram-sha-256`: **0 of 6 burned credentials
authenticate**, the generated one does, and one role's password does not open
another. `docs/97` has the mechanism and the five defects finding it produced.

The status above therefore reads, as of this amendment:

> ## STATUS: **INSTALLABLE WITH ONE CONDITION**

| | | |
|---|---|---|
| **B-1** | role credentials in the repository | **CLOSED** — `docs/97` |
| **B-2** | no staff authentication; the panel renders only under the development identity | **OPEN** — W-4 |
| B-3 | the 12 roles are cluster-wide | condition |
| B-4 | no TLS, no process supervision | condition |
| B-5 | `DNB_SECRET_KEY` must be set before any device-credential path is used | now generated at install |

**B-2 is deliberately untouched.** It is W-4, and inventing a staff identity to
clear it was explicitly out of scope.

The installation plan in §H stands, with two changes: step 3 now requires the
cluster to enforce `scram-sha-256` (`doctor` tests this with a deliberately
wrong password), and step 4 chooses between supplied and generated credentials.
`plugin/doc/INSTALL.md` ships the current version inside the artifact.

The disposable test grew from 32 checks to **67**, adding credential security,
role security, source disclosure over ten representative paths, exact simulator
counts, and a **second installation from the release artifact alone**.

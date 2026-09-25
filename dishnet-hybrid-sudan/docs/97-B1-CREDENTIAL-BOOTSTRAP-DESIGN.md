# 97 — B-1: the credential bootstrap mechanism

**Status of this part: DESIGN, written before implementation**, as instructed.
Part B records what was built and measured; it was appended after Part A was
settled.

---

# Part A — the proposal

## A.1 What is actually wrong

Six roles are created by the migrations with a password literal:

```
migrations/001_roles_and_extensions.sql   dnb_app
migrations/015_role_separation.sql        dnb_worker, dnb_admin
migrations/018_radius_ingestion_role.sql  dnb_radius
migrations/019_admin_read_boundary.sql    dnb_adminapi
migrations/020_admin_write_boundary.sql   dnb_adminwrite
```

and `Database::connect()` defaults to the same six strings, so an installation
that sets no environment works — with credentials published in this repository.

The defect is **not** that the passwords are weak. It is that **the migration
is the wrong place for a credential at all.** A migration is source-controlled,
replayed identically everywhere, and describes schema. A credential is
per-installation, must never be replayed, and must never be readable by anyone
who can read the schema. Putting one inside the other guarantees the leak
regardless of how the string is chosen — which is why replacing six literals
with six better literals fixes nothing.

## A.2 The principle

> **Migrations declare privilege. The installer supplies credentials.
> Neither ever carries a secret in source control.**

That line decides every question below.

## A.3 The mechanism

### Step 1 — migrations create roles with no password

```sql
CREATE ROLE dnb_app LOGIN NOSUPERUSER NOCREATEDB
                    NOCREATEROLE NOINHERIT NOBYPASSRLS;
```

The `PASSWORD` clause is removed. **Nothing else about any role changes** — not
one attribute, membership, grant, policy, or `NOLOGIN`/`LOGIN` marking.

`rolpassword` is then `NULL`, and under `scram-sha-256` a role with a null
password **cannot authenticate at all**. So the role exists, carries exactly the
privileges it was designed to carry, and nobody can be it. That is fail-closed:
the state after migration is *unusable*, not *usable by everyone*.

> **Caveat that must be stated, not glossed.** Under a `trust` entry in
> `pg_hba.conf`, a null-password role *can* connect, because `trust` asks for no
> credential. The mechanism therefore depends on the cluster requiring
> `scram-sha-256` for these roles. The installation test is being changed to
> initialise its cluster with `scram-sha-256` for both local and host
> connections, so the proof does not rest on an assumption.

### Step 2 — the installer sets credentials, in one of two modes

A new `src/Plugin/Credentials.php`, run by `plugin.php install` after the
migrations and before the install verifies itself.

**Supplied mode** — every `DNB_<ROLE>_PASS` is present in the environment:

```sql
ALTER ROLE dnb_app PASSWORD :supplied
```

applied on every install. The operator's secret manager, vault or env file is
the source of truth; re-running install re-asserts it, which is why re-running
is safe.

**Generated mode** — a password is absent and the role's `rolpassword IS NULL`:
generate 32 bytes from `random_bytes()`, apply, and write to the file named by
`DNB_SECRETS_OUT`, created `0600` before anything is written to it.

**Refusal** — a password is absent and the role **already has one**. The
installer refuses and says so. Silently rotating a credential that a running
deployment is using would break it in a way that looks like a database outage;
refusing names the real problem.

Plugin secrets (`DNB_TOKEN_PEPPER`, `DNB_SECRET_KEY`) take the same generated
path. They are not role passwords and never reach the database, but they have
the same property — required, per-installation, never in Git.

### Step 3 — `Database::connect()` fails closed

The six `?: '<role>-local-dev'` fallbacks are deleted. An unset password for an
application role raises, naming the variable, rather than attempting a
connection with an empty string.

Two identities keep the "may be empty" behaviour, deliberately:

| Identity | Why |
|---|---|
| `owner` | may legitimately be peer- or trust-authenticated at install time; `bootstrap.sql` sets its password, and `doctor` already warns when `DNB_OWNER_PASS` is unset |
| `inspector` | the BYPASSRLS test fixture. `tests/` only; F2 already forbids it in `src/` |

### Step 4 — the test runner provisions ephemeral credentials

`tests/run.sh` generates fresh random passwords per run, exports them, and lets
the installer apply them. Nothing is written to disk, and no two runs share a
credential.

### Step 5 — a regression guard

A test walks every tracked file and fails on a `PASSWORD '<literal>'` in SQL or
a `-local-dev`-shaped default in PHP. It has to be an enumeration, because the
whole class of defect is "someone added one back".

## A.4 Why this preserves the role model exactly

The security model is a statement about **what each role may do**. This change
touches only **who may be that role**. Concretely, none of the following moves:

| Property | Before | After |
|---|---|---|
| `dnb_app` NOSUPERUSER, NOCREATEDB, NOCREATEROLE, NOINHERIT, NOBYPASSRLS | yes | **unchanged** |
| the six definer roles NOLOGIN | yes | **unchanged** |
| the six application roles LOGIN | yes | **unchanged** |
| `dnb_adminapi` — EXECUTE on projections, zero table privileges | yes | **unchanged** |
| `dnb_adminwrite` — EXECUTE on seven functions, zero table privileges | yes | **unchanged** |
| `dnb_radius` — EXECUTE on one function, zero table privileges | yes | **unchanged** |
| FORCE ROW LEVEL SECURITY on every tenant table | yes | **unchanged** |
| no application role is a member of another | yes | **unchanged** |
| the owner is not a superuser and does not bypass RLS | yes | **unchanged** |
| every `GRANT`, policy and `SET LOCAL ROLE` block | — | **not edited** |

The diff to the migrations is the removal of the token `PASSWORD '<literal>'`
from six `CREATE ROLE` statements. Nothing else in `migrations/` changes.

## A.5 What this does NOT fix, and must not pretend to

- **The six strings are in Git history.** They cannot be removed from it here,
  and they do not need to be: the plugin has never been installed anywhere, so
  no live credential is exposed. They are **burned** — `Doctor::DEV_PASSWORDS`
  keeps testing that those exact strings never authenticate again, which is now
  the only reason that list exists.
- **B-2 is untouched.** `DenyAllIdentity` remains the production binding and
  W-4 remains OPEN. No staff identity is invented.
- **This is not a secret manager.** It is a bootstrap that refuses to hold a
  secret. An operator using Vault, `systemd` credentials or EasyPanel secrets
  supplies the environment and the installer never generates anything.

## A.6 Options considered and rejected

| Option | Rejected because |
|---|---|
| six new literals, better chosen | fixes nothing; the defect is the location, not the entropy |
| migrations read a session GUC | the secret then lives in a `SET` visible in `pg_stat_activity` and in logs |
| installer writes the passwords into a generated migration | puts a credential back into a replayable, source-shaped artifact |
| roles created `NOLOGIN`, flipped to `LOGIN` at install | changes the role model — the thing this must not do |
| no password; rely on `pg_hba` peer/ident | works only where the app runs as a matching OS user; the panel and worker do not |

---

# Part B — what was built, and what it measured

Written after Part A was settled and implemented. Every number below came from
running it.

## B.1 The change

| File | Change |
|---|---|
| `migrations/001, 015, 018, 019, 020` | the `PASSWORD '<literal>'` token removed from six `CREATE ROLE` statements. **Nothing else in `migrations/` changed** |
| `migrations/015` | a comment block that described the literals as a deployment gate was rewritten; it had become false |
| `src/Plugin/Credentials.php` | new. Provisioning, generation, the secrets file |
| `src/Plugin/Installer.php` | provisions credentials after migrating; refuses before altering anything if it would have to generate with nowhere to write |
| `src/Db/Database.php` | six password defaults deleted; an unset one raises |
| `src/Plugin/Doctor.php` | the burned-credential check gained a positive control |
| `tests/run.sh` | mints fresh credentials per run and installs the way an operator does |
| `tools/dev_panel.sh` | new. Restores the local panel workflow, which needed credentials once the defaults were gone |
| `plugin/.env.example`, `plugin/doc/` | the template and the installation documentation |

## B.2 The five defects the work found

Each was found by running the thing, not by reading it.

### 1. The refusal fired after the damage

The first `Installer` checked `DNB_SECRETS_OUT` *after* `provision()`. Six
credentials were generated, applied with `ALTER ROLE`, and only then did the
install refuse for having nowhere to write them — leaving six roles with
passwords nobody would ever know. Caught on the install test's first run.

`Credentials::wouldGenerate()` is now asked before migrating and before a single
`ALTER ROLE`.

### 2. `pg_authid` is superuser-only

The design's "does this role already have a password" could not be asked: the
verifier lives in `pg_authid`, which the owner — deliberately not a superuser —
cannot read, and `pg_roles` masks it to a constant. Granting the owner more to
answer a convenience question would have widened the boundary the class exists
to protect.

So the installer asks a question ordinary privilege *can* answer: **did this
role exist before this install ran?** A role that predates the install may
belong to another database on the cluster, which is the case worth refusing.

### 3. The burned-credential check was reporting a blocker while measuring nothing

On a cluster whose `pg_hba.conf` says `trust`, every password succeeds. The
check duly reported all six burned credentials "ACCEPTED" on a database where
none of them was set. A control that fails without measuring anything is as
useless as one that passes without measuring anything.

A deliberately wrong password now goes first. If *that* connects, the cluster
requires no password — its own blocker, and a different one — and the burned
check reports **SKIP**. The disposable cluster was changed to
`scram-sha-256` so the proof does not rest on the assumption.

### 4. `missingConfig()` refused over the secret it was about to mint

`DNB_TOKEN_PEPPER` is `required`, so the installer refused to start — while
`DNB_SECRETS_OUT` was set and generating it was the plan. A required key is now
satisfied **either** by being supplied **or** by being generatable into a named
file.

### 5. An unquoted DSN is three shell commands

`INSTALL.md` tells the operator to run `set -a; . /etc/dnb/dnb.env; set +a`.
The template's DSN was unquoted, and it contains semicolons:

```
  unquoted -> DNB_DSN='pgsql:host=/var/tmp'  port='55432'  dbname='dnb_sim'
  quoted   -> DNB_DSN='pgsql:host=/var/tmp;port=55432;dbname=dnb_sim'
```

The installer then tried to reach a database called `dnb`. The template, the
generated secrets file and the development helper all quote now, and a test
fails on any value in the template that needs quotes and lacks them.

## B.3 B-1, measured

On a disposable cluster that **requires** `scram-sha-256`, after a clean install:

| | |
|---|---|
| password literals in the packaged migrations | **0** |
| burned strings in packaged `src/` or `migrations/` outside the burned list | **0** |
| burned credentials that authenticate | **0 of 6** |
| the generated credential authenticates | **yes** |
| one role's password opens another | **no** |
| secrets printed by the installer | **0** |
| the secrets file's mode | **0600**, set before a byte is written |
| a second install mints a different credential set | **yes** |

The positive control that makes all of this mean something: **the cluster
rejects a deliberately wrong password.**

## B.4 The role model, verified rather than asserted

Part A claimed the change touches only *who may be a role*, never *what a role
may do*. Checked on a fresh install:

| | |
|---|---|
| plugin roles that are superusers | 0 |
| plugin roles that bypass RLS | 0 |
| plugin roles that may create roles or databases | 0 |
| definer roles that cannot log in | 6 of 6 |
| application roles that can | 6 of 6 |
| plugin roles that are members of another | 0 |
| the owner's memberships in definer roles | 6, **none inheriting** |
| table privileges held by `dnb_adminapi` / `dnb_adminwrite` / `dnb_radius` | 0, 0, 0 |
| tenant tables with RLS enabled but not FORCEd | 0 |

Two of those checks were wrong when first written, and the way they were wrong
is worth recording. "No plugin role may create roles" matched the *owner*, which
legitimately has `CREATEROLE`. "No plugin role is a member of another" filtered
the owner out as the **group** when migration 017 makes it the **member** — it
must be, because PostgreSQL requires membership before it will hand a role
ownership of an object, and the grant is `WITH INHERIT FALSE` for exactly that
reason. Both now carry positive controls asserting the memberships that *should*
exist.

## B.5 Source disclosure

Ten representative paths, requested through the serving path on a live
installation — source, migration, manifest, environment template, version
stamp, checksum file, the worker, the bootstrap SQL, and **the generated
secrets file itself**:

```
  no source, config, secret or manifest file is reachable   0 leaked
  panel files themselves still serve                        200
```

## B.6 What B-1 does not cover

- The six strings remain in this repository's history. Nothing was ever
  installed with them, so no live credential is exposed, but they are burned and
  `Doctor::DEV_PASSWORDS` exists only to keep proving they are dead.
- **B-2 is untouched.** `DenyAllIdentity` is still the production binding and
  W-4 is still OPEN. The panel still renders only under the development gate.
- The mechanism depends on the cluster requiring a password. `doctor` measures
  that and says so; it cannot enforce it.

# DishNet MikroTik Control Plane

Domain B control plane. **A separate service** — docs/30 Artifact 9: *"The network API
is a separate service on its own host with its own database. The plugin may call it. It
does not contain it."*

Nothing here touches Domain A, the uCRM plugin, its SQLite database or its files.

**Status: step 1 of 8.** Schema, tenancy, isolation, audit and idempotency. No HTTP layer,
no devices, no vouchers, no RADIUS yet. See `docs/55` in the plugin repo for the plan.

---

## This directory is a repository in waiting

It is self-contained and has no path dependency on the plugin. To extract it:

```bash
git subtree split --prefix=dishnet-mikrotik-control-plane -b control-plane
# then push that branch to the new repository as its main
```

It lives here only so step 1 could be reviewed alongside the plan that produced it.

---

## Running the tests

Requires PostgreSQL 13+ (`gen_random_uuid()`) and PHP 8.1+ with `pdo_pgsql`.

```bash
./tests/run.sh
```

It creates a throwaway database, migrates it and runs every suite. Override with
`DNB_PGHOST`, `DNB_PGPORT`, `DNB_OWNER_USER`, `DNB_TEST_DB`.

**123 assertions across 5 suites.**

---

## The one thing to understand before changing anything

Isolation rests on **the application connecting as a role that owns nothing.**

PostgreSQL row-level security is bypassed by superusers, and — unless `FORCE ROW LEVEL
SECURITY` is set — by the table owner. Connect the app as the owner and every policy in
`006_rls.sql` becomes decorative: present, correct-looking, and doing nothing. Every
customer would read every other customer, and a test that only checked the policies
existed would still pass.

So:

- `dnb` (owner) runs migrations and **never serves a request**
- `dnb_app` serves requests: not superuser, `NOBYPASSRLS`, owns nothing, has no DDL
- every table also sets `FORCE ROW LEVEL SECURITY`

`Database::app()` and `Database::owner()` are separate methods rather than one with a
flag, so using the wrong one is a visible choice.

`tests/test_rls_isolation.php` asserts the role attributes directly, so a deployment that
misconfigures this fails the suite rather than leaking silently.

---

## Layout

```
migrations/   001 roles · 002 identity · 003 commercial plane
              004 audit · 005 idempotency · 006 RLS
src/Db/       Database (two roles), Migrator
src/Tenancy/  TenantContext — the only way to reach customer data
src/Audit/    AuditLog — append-only, enforced by the database
src/Http/     Idempotency — replay protection
bin/          migrate.php
tests/        run.sh + 5 suites
```

## Invariants the tests enforce

From `docs/53` F1–F13, binding:

| | |
|---|---|
| **F1** | No Domain A artefact named in any source file or migration; every table carries `mt_` |
| **F4** | No customer id is read from a request; `TenantContext` takes a derived id only |
| **F8** | `mt_entitlements.key` has a CHECK listing exactly five platform-scope keys. Any bandwidth, rate or concurrency key is rejected by the schema |
| **F10** | Entitlements are recorded, never enforced against an operation — asserted by source scan |
| **F13** | No shaping, throttling or rationing verb exists in the source |
| docs/53 §4 | No adoption, BYO or capability-discovery code exists |

These are executable because a frozen decision recorded only in prose gets reopened by
whoever has not read the prose.

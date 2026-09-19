# DishNet MikroTik Control Plane

Domain B control plane. **A separate service** — docs/30 Artifact 9: *"The network API
is a separate service on its own host with its own database. The plugin may call it. It
does not contain it."*

Nothing here touches Domain A, the uCRM plugin, its SQLite database or its files.

**Status: step 3 of 8.** Schema, tenancy, isolation, audit, idempotency, authentication,
the `/me` read surface, the response projection, and the intent queue. No devices,
vouchers, RADIUS or telemetry yet. See `docs/55` in the plugin repo for the plan.

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

**282 assertions across 9 suites**, including one that runs against a real `php -S` server.

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
              004 audit · 005 idempotency · 006 RLS · 007 auth · 008 intents
src/Db/       Database (two roles), Migrator
src/Tenancy/  TenantContext — the only way to reach customer data
src/Auth/     Authenticator — credential to derived (principal, customer)
src/Audit/    AuditLog — append-only, enforced by the database
src/Http/     Request, Response, Router, Kernel, Idempotency
src/Http/Serializer/  Projection — the allowlist for what leaves
src/Intents/  IntentQueue, IntentState — the only path to a router
src/Delivery/ DeliveryPort (interface), DeliveryResult, NullDelivery
src/Jobs/     IntentWorker — the only caller of DeliveryPort
src/Api/      Routes — auth + /me
public/       index.php
bin/          migrate.php, worker.php
tests/        run.sh + 9 suites
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

Each guard has been **negative-tested**: a violation is planted, the suite is confirmed to
fail, and the plant removed. A guard nobody has watched fail is a guard nobody knows works.

---

## Delivery is at-least-once, and nothing pretends otherwise

A worker can die after sending a command but before recording that it sent it. That is
indistinguishable from dying before sending, so the intent is retried and the command may
arrive twice. Exactly-once delivery across a process boundary is not available; what is
available is making the second arrival harmless. That is what the idempotency key is for,
and it is why every operation delivered through the queue must be idempotent at the far
end.

The lease is what makes a crash recoverable: a worker claims a row for a bounded time, and
if it dies the lease lapses and another worker picks the row up, still queued. Claims use
`FOR UPDATE SKIP LOCKED`, so several workers never claim the same row and none blocks
behind another — asserted with twenty intents and two racing workers.

**Confirmation is a read, never the delivery call's own return value.** A router that
accepts a command and does not apply it is a real failure mode, and trusting the write's
success is how it goes unnoticed. There is a test in which delivery reports success and
the read-back reports otherwise; the intent is not confirmed.

The state machine is enforced by a trigger, not by application code. In code it is a
convention, and one forgotten branch takes an intent from confirmed back to queued —
reconfiguring a router for a request the customer was told had completed.

## Authentication, and the chicken-and-egg it solves

Signing in has to read `mt_principals` — a table under RLS keyed on the customer being
derived. The lookup cannot satisfy the policy it is trying to establish.

`migrations/007_auth.sql` solves it with `SECURITY DEFINER` functions that return **only
the ids needed to establish context** — never a row, never a hash, never a name. The app
role has no privilege on `mt_auth_codes` at all. Every such function pins `search_path`,
and a test asserts that: an unpinned `SECURITY DEFINER` function can be hijacked by a
caller who shadows an object earlier on the path.

`POST /auth/request-code` returns the same response for a registered and an unregistered
number, so it cannot be used as a directory of who holds an account. Every verify failure —
wrong code, expired, reused, too many attempts, unknown phone — returns one identical 401.

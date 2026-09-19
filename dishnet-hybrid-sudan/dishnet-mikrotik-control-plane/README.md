# DishNet MikroTik Control Plane

Domain B control plane. **A separate service** — docs/30 Artifact 9: *"The network API
is a separate service on its own host with its own database. The plugin may call it. It
does not contain it."*

Nothing here touches Domain A, the uCRM plugin, its SQLite database or its files.

**Status: step 5 of 8.** Schema, tenancy, isolation, audit, idempotency, authentication,
the `/me` surface, the response projection, the intent queue, the policy plane, and
vouchers with their projection into AAA. No devices, RADIUS daemon integration, sessions
or telemetry yet. See `docs/55` in the plugin repo for the plan.

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

**381 assertions across 11 suites**, including one that runs against a real `php -S` server.

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
              009 policy · 010 vouchers
src/Db/       Database (two roles), Migrator
src/Tenancy/  TenantContext — the only way to reach customer data
src/Auth/     Authenticator — credential to derived (principal, customer)
src/Audit/    AuditLog — append-only, enforced by the database
src/Http/     Request, Response, Router, Kernel, Idempotency
src/Http/Serializer/  Projection — the allowlist for what leaves
src/Policy/   PlanValidator (validity, never ceiling), ProfileResolver,
              PlanRepository
src/Vouchers/ CodeSource, CodeGenerator, VoucherService
src/Intents/  IntentQueue, IntentState — the only path to a router
src/Delivery/ DeliveryPort (interface), DeliveryResult, NullDelivery
src/Jobs/     IntentWorker — the only caller of DeliveryPort
src/Api/      Routes — auth + /me
public/       index.php
bin/          migrate.php, worker.php
tests/        run.sh + 11 suites
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

## One code, one redemption — and how that is tested

Two guests typing the same code at the same moment is not hypothetical; it is what happens
when a code is shared. The guard is a `WHERE state = 'unused'` inside the redeeming UPDATE,
so the second transaction blocks on the row lock, re-evaluates, and matches nothing.

The test spawns **twelve real processes** that connect first, spin until an agreed instant,
then fire together. Exactly one wins. A sequential test — redeem, redeem again — passes
against code that has the bug, so it would not have been a test of this at all.

Every redemption failure returns the same nothing: unknown code, already used, revoked,
expired. Nothing distinguishes "wrong code" from "someone else got there first".

## A PostgreSQL trap worth knowing about

**A failed statement aborts the whole transaction.** Every statement after it raises 25P02
until rollback. So the familiar pattern —

```php
try { insert(); } catch (UniqueViolation) { /* try again, or re-read */ }
```

— does not work inside a transaction. The recovery code cannot run, and neither can
anything after it. It looks correct, passes review, and fails the first time the collision
it exists for actually happens.

`Database::attempt()` takes a savepoint around the attempt and rolls back to it on failure,
confining the abort. Three call sites needed it. A guard asserts that any file catching
`23505` routes through it.

The voucher collision-retry is why this surfaced: at 32^10 a natural collision would never
occur in a test run, so the path was given a deliberate seam (`CodeSource`) and driven —
and the bug was underneath it.

## Validity is not a ceiling

A plan is checked for one thing: whether its values can be **expressed** — by the RADIUS
attributes that carry them and the hardware that enforces them.

*"That rate cannot be written into the attribute"* is a fact about the protocol and stays.
*"You did not buy that much"* is a commercial ceiling, and under F8/F9 it is wrong: the
customer's uplink is their own and DishNet does not ration it. A customer may create a
20 Mbps plan whether or not their link carries it — overselling their own uplink is their
business decision, and the platform shows them what is happening rather than refusing them.

Three guards hold that line: no source file reads an entitlement key; `mt_entitlements` is
read in exactly one place and never written from a customer path; and no source file
contains commercial-refusal wording. A ceiling creeping back would announce itself in the
wording before it showed up anywhere else.

`PlanValidator::MAX_RATE_BPS` is a conservative 32-bit bound that **requires verification on
hardware in step 7** — RouterOS's real maximum has not been read off a device, and docs/31
§7's matrix is filled by testing rather than assumption. It is set high enough that no
hotspot plan will meet it, so it cannot act as a commercial limit by accident.

**Profiles are derived, never chosen.** The customer expresses what they are selling; the
platform works out how to enforce it, deduplicating on the technical tuple so two customers
selling the same shape share one profile row. No customer-facing response carries a profile
id, so neither can observe the other.

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

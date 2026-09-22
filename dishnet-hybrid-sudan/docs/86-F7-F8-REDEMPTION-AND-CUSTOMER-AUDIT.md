# 86 — F-7 and F-8: the redemption path and the customer-API audit sites

**Status:** EVIDENCE / DESIGN AUDIT ONLY. **No code was changed.** Repository
clean; suite unchanged at **1,228 assertions green**.
**Decides nothing. Implements nothing. Authorises nothing.**

All measurements ran on a **throwaway database** (`dnb_f7`, created and dropped
inside this audit) built by `bin/migrate.php` through migration 020. Production
was not contacted, and nothing here establishes production data state.

---

# PART A — F-7: `mt_voucher_redeem`

## A.1 F-7 EVIDENCE

### The finding that reframes everything else

**The redemption path is not wired into the application.**

`mt_voucher_redeem` has exactly one caller in the codebase —
`VoucherService::redeem()` — and **`VoucherService::redeem()` has no callers at
all**. There is no HTTP route, no worker, no CLI entry point, and no reference
in the PWA or the Admin UI. Measured:

```
grep -rn '->redeem('  src/ public/ bin/   →  (no matches)
grep -rn 'redeem'     src/Api/            →  (no matches)
grep -rn 'redeem'     public/             →  (no matches)
```

So F-7 is not "a live function missing an audit row". It is **a designed
function that nothing reaches**, sitting beside an issue path that already does
the thing redemption was supposed to do. That changes what the remediation is.

### The fifteen questions, measured

| # | Question | Measured answer |
|---|---|---|
| 1 | Who calls `mt_voucher_redeem`? | `VoucherService::redeem()` only. |
| 2 | Every caller | One in application code; one in `tools/audit/boundaries.php` and one in `tools/audit/f_remaining.php` (both historical probes). `VoucherService::redeem()` itself is called by **nothing**. |
| 3 | Customer, guest, worker or system initiated? | **None of them — it is unreachable.** The code comments describe the intended initiator as "the network side". That intent was never bound to an entry point. |
| 4 | What actor information exists at redemption time? | **Only the code.** `pronargs = 1`. No NAS, no site, no principal, no session, no source address. |
| 5 | Does it change security-bearing state? | **Yes.** `unused → active`, sets `activated_at` and `expires_at = now() + duration`. Under Decision 1 this is the moment the AAA credential is supposed to be published. |
| 6 | Should it create an audit event? | **Yes** — it is the revenue-and-access event. But not before §A.2 settles who the actor is, and not before the entry point exists. |
| 7 | What should the actor be with no human identity? | See §A.2. **The existing schema already answers this**, and the answer is not a new taxonomy. |
| 8 | Must the audit row be in the same transaction? | **Yes**, and it can be: the function runs inside the caller's transaction. Migration 020's `mt_audit_write` is already callable by `dnb_def_net`'s sibling pattern — though `dnb_def_net` currently holds **no** grant on it (§A.3). |
| 9 | Is redemption idempotent? | **No — it is at-most-once.** Measured: 1st redeem returns the voucher; 2nd returns `null`. A retry after a lost response is indistinguishable from an invalid code. |
| 10 | Duplicate / simultaneous redemption? | **Correct and measured.** Two connections, one code: txn 2 **blocked on the row lock** while txn 1 was open, then returned `null` after txn 1 committed. The `AND v.state = 'unused'` guard is doing real work. |
| 11 | AAA publication succeeds, surrounding transaction fails? | **Cannot happen today, and will not stay that way.** Measured: a forced failure mid-issue rolled back the `mt_hotspot_users` rows with the vouchers — because that table is in the *same* database. The real AAA store (`radcheck`/`radreply`) is a **separate PostgreSQL instance**, so under Decision 1/7 publication **cannot** share the transaction. This is the unresolved design problem, not a solved one. |
| 12 | AAA publication fails? | **No publication exists.** `RadiusPublisherPort` is never called by any production code path — only by `tests/test_ports_and_bindings.php`. `Bindings` exposes `publisher()`; nothing consumes it. |
| 13 | Is site binding checked before activation? | **No.** One argument, the code. `mt_hotspot_users` has **no `site_id` column**. `dnb_cred_site` and `dnb_site_nas` do not exist. Decision 2b is not enforced anywhere in this path. |
| 14 | Does a commercial voucher code enter the AAA credential path? | **YES.** See §A.1.1 — this is the most serious finding in this audit. |
| 15 | Is a raw voucher code written to audit / detail / logs? | **No.** Measured: no voucher code appears in `mt_audit_log`. `AdminProjection` withholds `code`. The issue-time audit detail carries only `count`. |

### A.1.1 The voucher code IS the AAA username — measured

`VoucherService::issueBatch()` writes the AAA registry row **at issue time**:

```php
'INSERT INTO mt_hotspot_users (voucher_id, customer_id, radius_username) …'
  … $this->radiusUsername($customer, $v['code'])

public function radiusUsername(array $customer, string $code): string
{
    return $customer['radius_ref'] . '-' . str_replace('-', '', $code);
}
```

Measured on a clean database:

```
voucher state at issue               unused
AAA row written AT ISSUE TIME        YES
  radius_username                    cust7-D7CBSXKMCG
  the voucher code                   D7CBS-XKMCG
  code recoverable from username     YES - the code IS the username
  AAA row carries a site             NO
  AAA row carries a secret           NO - no credential is stored
AAA row changed by redemption        NO - unchanged since issue
```

This contradicts **two closed decisions**, and it is live code, not a leftover:

- **Decision 1 — Model B (CLOSED):** the AAA credential is published at
  **redemption/activation**, not at voucher issue. The row is written at issue.
- **Decision 3 — P2 (CLOSED):** *the voucher code never enters the AAA
  credential path.* The username is a reversible transform of the code.

A third consequence follows from the measurement, not from the decisions: the
row holds **no secret at all**. So `mt_hotspot_users` is today a *registry*, not
a credential — consistent with how this project has always described the three
layers. What does not yet exist is anything that turns it into one.

### A.1.2 Grants and reachability, unchanged

```
mt_voucher_redeem(text)   owner dnb_def_net
  dnb_def_net=X/dnb_def_net   dnb_app=X/dnb_def_net
```

`dnb_app` — the request role — still holds `EXECUTE`. That is the standing **F6**
finding, and it is unchanged by migration 020. Measured, still true:

```
customer B redeems A's code          REDEEMED  <-- not tenant-scoped
redeem with NO tenant context        REDEEMED  - by design: the code is the only input
redeeming a revoked voucher          null (refused)
AAA row still present for it         YES - revocation does not remove it
```

The cross-customer result is **by design** for a network-side caller (the code is
the only identity presented, and resolving it *is* the authorization). It is a
defect only because `dnb_app` — a request-path role reachable from HTTP — holds
the grant. That is F6, and F6 is not authorised here.

The last line is a separate gap worth naming: **revoking a voucher leaves its
AAA registry row in place.** Nothing removes it, because nothing publishes or
unpublishes.

## A.2 F-7 ACTOR MODEL OPTIONS

**The existing system already fixes the taxonomy, and it is three values.**
`mt_audit_log` carries its own CHECK constraint — this is schema, not convention:

```
mt_audit_log_actor_kind_check :: CHECK (actor_kind = ANY (ARRAY['principal','staff','system']))
```

Usage today: `principal` ×6 (the customer API), `system` ×2 (the intent worker).
`staff` is used by migration 020's seven provisioning functions.

So **`GUEST` and `WORKER` do not exist**, and adding either is a **schema change
with a migration and its own decision** — not a detail of an audit row. The
options below are therefore about which *existing* value fits, plus one option
that admits the taxonomy is genuinely insufficient.

| Option | Actor / kind | For | Against |
|---|---|---|---|
| **R-1** | `actor = 'redemption'`, `kind = 'system'` | Fits the existing CHECK with no migration. Honest: no human identity is presented, and the system is what acted. | Loses *which* redemption. Recovering that means reading `target_id` (the voucher). |
| **R-2** | `actor = <NAS identifier or source IP>`, `kind = 'system'` | Records where the redemption came from, which is what an operator actually wants when a code is disputed. Aligns with Decision 2a, which will need the source address at this point anyway. | **The information does not exist today** — the function takes one argument. Requires a signature change and an entry point that has it. Also: a NAS-asserted identifier is client-asserted data (`docs/70` §5.3 forbids letting it reach the authorize query; recording it in audit is a different act, but the distinction must be deliberate). |
| **R-3** | `actor = <principal id>`, `kind = 'principal'` | Correct **if** redemption is performed by a logged-in customer in the PWA (a receptionist activating on a guest's behalf). | Wrong for the guest captive-portal case, which is the case Decision 1 describes. Would misattribute a guest's act to a staff member of the customer. |
| **R-4** | Add `'guest'` to the CHECK | Most descriptive of the real-world act. | **A schema change to the audit contract.** It also asserts a product fact — that guests are actors in our system — that no closed decision has established. Would need its own decision and would touch a table whose contract three other paths depend on. |

**Not recommended without more input:** R-2 and R-4 both require facts this audit
cannot supply — R-2 an entry point that carries the source, R-4 a product
decision. R-1 is the only option that can be implemented today without inventing
either. **No recommendation is made here**, because §A.3 shows the audit row is
not the first thing this path needs.

## A.3 F-7 AUDIT REQUIREMENT

If and when redemption is bound, the audit row must satisfy all of:

1. **Written inside `mt_voucher_redeem` itself**, in the same transaction, via
   `mt_audit_write` — not by the caller. The measured F-8 result (§B.2) shows
   exactly what caller-written audit is worth.
2. **`dnb_def_net` must be granted `EXECUTE` on `mt_audit_write`.** It is not
   today — migration 020 granted it to `dnb_def_prov` only. Until that grant
   exists, a redemption audit row is impossible, and adding the call without the
   grant would make every redemption fail rather than silently skip audit. That
   is the safer failure, but it must be deliberate.
3. **The raw voucher code must never enter `detail`, `target_id` or any log.**
   `target_id` must be the voucher **id**, never the code. Measured: no code
   reaches the audit log today, and that property must survive the change.
4. **`customer_id` must come from the voucher row**, which the function already
   resolves — never from the caller, which has no tenant context.
5. **Failure must leave no row.** A refused redemption (unknown, already used,
   revoked, expired) is not an event that happened; it returns `null` and must
   audit nothing. This is already the natural shape, because the `RETURN QUERY`
   matches no row.
6. **Audit must not become a redemption oracle.** Today every failure is
   indistinguishable — that is deliberate (`VoucherService::redeem` docblock). An
   audit row written on failure would reintroduce the distinction for anyone who
   can read audit. Another reason for (5).

**But the audit row is not the first requirement.** In priority order this path
needs: an entry point (nothing calls it) → the Decision 1/3 contradiction in
`issueBatch` resolved → site binding (Decision 2b) → the credential itself →
*then* the audit row. Adding audit to an unreachable function would be a control
that never runs.

## A.4 F-7 OPEN QUESTIONS

| Ref | Question | Why this audit cannot answer it |
|---|---|---|
| **Q-F7-1** | Is `issueBatch()` writing `mt_hotspot_users` at issue time an accepted deviation from Decision 1, an un-migrated legacy, or a defect to correct? | Decision 1 closed on Model B; the code predates it. Which one it is, is your call, not a reading of the code. |
| **Q-F7-2** | `radiusUsername()` derives the AAA username from the voucher code, contradicting Decision 3. What replaces it? | Decision 3 says the portal receives *generated* credentials. The generator does not exist. Its shape (length, alphabet, collision handling, whether the username is also generated or derived from the voucher **id**) is a design decision. |
| **Q-F7-3** | Who initiates redemption — a captive-portal guest, a receptionist in the PWA, or the NAS itself? | Determines R-1/R-2/R-3 **and** the entry point. The code comments say "network side"; no decision document states it. |
| **Q-F7-4** | Should redemption become **idempotent** (same code, same answer, within a window) rather than at-most-once? | A guest who loses the response currently cannot recover a paid-for credential. Fixing this is a product decision about double-spend risk versus support burden. |
| **Q-F7-5** | How is AAA publication made atomic with redemption across **two database instances**? | Outbox + reconciling publisher, two-phase, or accept the window and reconcile. `docs/71` designed the writer; this specific atomicity question is not settled there. |
| **Q-F7-6** | Should revocation unpublish the AAA row? | Today the row survives revocation. Whether that matters depends on whether the row is ever a credential. |
| **Q-F7-7** | Does `mt_hotspot_users` need `site_id` for Decision 2b/2a? | `dnb_cred_site(radius_username, site_id)` is the chosen 2a mechanism and does not exist. Whether it is a new table or a column here is a schema decision awaiting the census. |

---

# PART B — F-8: the customer-API audit sites

## B.1 F-8 EVIDENCE

### A correction to how F-8 was first stated

`docs/85` §5.3 recorded these six as "the same F-1 pattern". That is **half
right, and the wrong half matters.**

`Kernel::handle()` runs every authenticated handler inside `TenantContext::run()`,
which opens a transaction, sets `app.customer_id`, and commits or rolls back:

```php
$who = $this->auth->resolve($req->bearer());
if ($who === null) { return Response::unauthorized(); }
return $this->ctx->run($who['customer_id'],
    fn(Database $db) => $handler($req, $this->db, $who));
```

So **all six audit rows are already written inside the same transaction as their
mutation.** They are not "audit after the fact". The defect is **skippability**,
not atomicity — and skippability is real, measured below.

### The six sites

| # | Endpoint | Mutation | Audit event | Actor | Same txn? | Forgeable? | Sensitive detail? |
|---|---|---|---|---|---|---|---|
| 1 | `POST /api/v1/me/plans` | `PlanRepository::create` | `plan.created` | `$who['principal_id']`, kind `principal` | **yes** | no | no — `{name}` |
| 2 | `PATCH /api/v1/me/plans/{id}` | `PlanRepository::update` | `plan.updated` | same | **yes** | no | no — none |
| 3 | `POST /api/v1/me/plans/{id}/retire` | `PlanRepository::retire` | `plan.retired` | same | **yes** | no | no — none |
| 4 | `POST /api/v1/me/vouchers` | `VoucherService::issueBatch` (+ `mt_hotspot_users`, + intent) | `voucher.issued` | same | **yes** | no | no — `{count}`; **no code** |
| 5 | `POST /api/v1/me/vouchers/{id}/revoke` | `VoucherService::revoke` + intent | `voucher.revoked` | same | **yes** | no | no — none |
| 6 | `POST /api/v1/me/sessions/{id}/disconnect` | intent only (no state change) | `session.disconnect_requested` | same | **yes** | no | no — none |

Provenance of each field, verified:

- **`actor` / `customer_id`** come from `$who`, produced by
  `Authenticator::resolve()` → `mt_auth_resolve_token(hash(bearer))`, which joins
  `mt_auth_sessions` to `mt_principals` and requires the session unrevoked,
  unexpired, and the principal `active`. **Nothing from the request body reaches
  either field.** No route accepts a customer id at all —
  `tests/test_frozen_guards.php` enforces that.
- **`source`** is `$req->ip` = `$_SERVER['REMOTE_ADDR']`, **not** a forwarded
  header. Not client-settable. (Behind a reverse proxy it records the proxy —
  a truthfulness caveat, not a forgery.)

### B.1.1 Two properties measured, not assumed

**RLS is an independent second layer on the audit row.** A handler that passed
the *wrong* customer id would still be refused, because `mt_audit_log` is
`FORCE`d with `WITH CHECK (customer_id = mt_current_customer())`:

```
A writes an audit row claiming B       REFUSED - RLS WITH CHECK
an arbitrary actor_kind string         refused (the table's own CHECK)
```

**The audit is skippable, and skipping is silent.** Calling the service directly
— which is exactly what a worker, a CLI tool, or a future Admin route would do —
produced no audit row at all:

```
calling the service directly, not the route    NO audit row written
```

That single line is F-8. The act happened; the record did not.

### B.1.2 Four mutating routes write no audit at all

Enumerating every route, not only the ones that already audit:

| Endpoint | Writes | Audited? | In a transaction? |
|---|---|---|---|
| `POST /api/v1/auth/request-code` | `mt_auth_issue_code` → a login code | **no** | **no** — `auth:false`, so `Kernel` calls the handler outside `TenantContext` |
| `POST /api/v1/auth/verify` | `mt_auth_verify_code` + `mt_auth_create_session` | **no** | **no** |
| `POST /api/v1/auth/logout` | `mt_auth_revoke_token` | **no** | **no** |
| `POST /internal/radius/accounting` | `mt_session_account` → session rows | **no** | **no** (separate `dnb_radius` identity, by design) |

A successful login is a security-bearing event with **no record whatsoever** —
neither who logged in nor from where. This is not a gap in the six; it is a gap
the six make easy to miss.

## B.2 F-8 SECURITY FINDINGS

| Ref | Finding | Severity |
|---|---|---|
| **F-8.1** | Audit belongs to the route, not to the act. Any other caller of `PlanRepository`, `VoucherService` or `IntentQueue` mutates business state with no record. **Measured.** | **High** — it is the same defect as F-1, and the Admin write API would be exactly such a caller. |
| **F-8.2** | Authentication events (`request-code`, `verify`, `logout`) are entirely unaudited. No record of successful logins, failed attempts, or session revocation. | **High** for incident response; there is no way to answer "who logged in as this principal, and when". |
| **F-8.3** | The three auth routes run **outside any transaction**. A partial failure between code verification and session creation leaves no consistent record and no rollback boundary. | Medium. |
| **F-8.4** | `source` is `REMOTE_ADDR`. Behind a proxy every row records the proxy's address. | Low — a truthfulness caveat, worth recording before anyone relies on the field. |
| **F-8.5** | `session.disconnect_requested` audits a *request*, and the disconnect is an intent with **no delivery case** (`docs/84` §5, W-6). The audit row is truthful about the request; nothing must later read it as evidence the session was disconnected. | Low now, **trap later.** |

**Not findings** — verified and sound: actor and customer cannot be forged from
the request; RLS independently constrains the row; `actor_kind` is constrained by
the table; no sensitive field and no voucher code appears in any detail.

## B.3 F-8 REQUIRED REMEDIATION

Stated as requirements, not as a plan. **Nothing here is authorised.**

1. **Move each audit row to the act.** Audit must be written where the state
   changes — inside the repository/service method, or inside a definer function
   as W-1 did — so a second caller cannot omit it. The customer path must use
   `actor_kind = 'principal'` with the principal id; it must **not** reuse the
   Admin/staff model, and the staff and principal identity boundaries must not
   be merged to make one mechanism fit both.
2. **`dnb_app` must not be able to write arbitrary audit rows** once the audit
   moves. Today `AuditLog` inserts directly and `dnb_app` holds `INSERT`. The
   W-1 shape (a narrow definer writer no HTTP role may call) applies here too,
   with a **separate** grant for the customer path.
3. **Audit the authentication events**, inside the auth definer functions, which
   already exist and already own the state change. The actor for a successful
   verify is the principal; for `request-code` there is no authenticated actor
   yet, and that is its own question.
4. **Do not widen `mt_audit_log`'s contract to make this fit.** `actor_kind` is
   `principal | staff | system` by CHECK. If the customer path needs a fourth,
   that is a schema decision.
5. **Preserve what already works:** no route may accept a customer id; `$who`
   must stay token-derived; RLS must remain the second layer; no voucher code,
   secret, or free-form document may enter `detail` (D-2).

---

# PRODUCTION BLOCKERS

| # | Blocker | Status |
|---|---|---|
| 1 | **The production census** | Not obtained. `docs/79` is the operator handoff. Gates every production migration, including 020. |
| 2 | **Migration 020 not applied to production** | Correct and required. It assumes zero customer/site violations — established for the development schema only. |
| 3 | **Decision 1 vs `issueBatch`** | The AAA registry row is written at **issue**, not redemption. Unresolved (Q-F7-1). |
| 4 | **Decision 3 vs `radiusUsername()`** | The voucher code **is** the AAA username, reversibly. Unresolved (Q-F7-2). **This blocks any production-capable voucher activation path.** |
| 5 | **F6 — `dnb_app` holds EXECUTE on `mt_voucher_redeem`** | Unchanged. NOT AUTHORIZED to fix. |
| 6 | **No AAA credential is published by anything** | `RadiusPublisherPort` is called by no production code. Decision 7's publisher (`docs/71`) is designed, unapproved, blocked. |
| 7 | **Decision 2b not enforced at activation** | Redemption takes only a code; `mt_hotspot_users` has no site. `dnb_cred_site` / `dnb_site_nas` do not exist (Decision 2a chose, built nothing). |
| 8 | **Authentication events unaudited** | F-8.2. |
| 9 | **Staff identity (W-4)** | `DenyAllIdentity` remains the production Admin binding. |
| 10 | **Missing delivery cases (W-6)** | Only `device.provision` has one. |
| 11 | **Physical MikroTik verification** | H1–H7 unverified (`docs/80` §7). |

# DEVELOPMENT STATUS

| Area | State |
|---|---|
| Customer PWA | Wired to the real API; authorization tested; voucher **issue** flow wired. **Redemption is not wired** — no route exists. |
| Admin read | Eleven projections, `dnb_adminapi`, read-only UI, responsive-verified. |
| Admin write | Floor built (W-1/W-2/W-3, migration 020). **No route or button bound.** |
| Audit | Seven provisioning functions audit transactionally. Six customer routes audit transactionally but **skippably**. Four mutating routes do not audit at all. |
| Integrity | Customer/site invariant enforced by constraint for future writes; no backfill. |
| AAA / RADIUS | Ports and simulators exist; **nothing calls them**. `radcheck`/`radreply` untouched by this application. |
| Suite | **1,228 assertions green**, unchanged by this audit. |

# OPEN DECISIONS

| Ref | Decision | State |
|---|---|---|
| **Q-F7-1…7** | The seven redemption questions in §A.4 | **NEW, OPEN** |
| **F-8.1…5** | The five customer-audit findings in §B.2 | **NEW, OPEN** |
| W-4 | Staff identity provider | OPEN |
| W-5 | Missing product operations | OPEN |
| W-6 | Missing delivery cases | OPEN |
| D-3 | Audit scope — which acts are auditable at all | OPEN; F-8 is its concrete case |
| D-4 | Telemetry / entitlements exposure | OPEN |
| Q2 | One MikroTik serving several sites | OPEN |
| — | `dnb_site_nas` cardinality | OPEN |
| — | `mt_voucher_batches.site_id NOT NULL` | OPEN schema decision |
| — | Decision 4 (front-desk activation), 5 (rate/timing numbers), 6 (retention) | OPEN |
| — | F6 / F6-B | NOT AUTHORIZED |

---

## What this audit did not do

No code changed. No migration written or applied. No production contact. No
FreeRADIUS change. C-b not deployed. AAA Publisher not built. `dnb_site_nas` not
created. Q2 not decided. No staff authentication. No Admin write buttons.
Migration 020 not applied to production. F6-B not started. Suite unchanged.

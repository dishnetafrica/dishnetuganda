# 116 — T-2: the Operator Staff capability model (C6 / C16)

**Status: DESIGN, for approval. Nothing implemented.** Answers `docs/115` T-2
after T-1 (**`mt_customers` = Operator, vocabulary only**) and T-8 (**resume
`docs/114` with `dnb_staffauth` and *target operator*)** were closed on
2026-09-23. C6 (*owner vs staff roles in the Customer PWA*, `docs/47`) and
C16 (*where do an operator's people live*, `docs/48`) have been open since
the PWA prototype; `docs/46` §1.2 called the owner/receptionist split *"the
largest gap this exercise found"*. Every "today" statement below is measured
on `dnb_sim` at migration level 25 or read from the repository at `224efec`.

> **The finding that shapes this design.** Proved by execution, rolled back,
> residue 0: **`dnb_app` — the operator-plane HTTP role — can `UPDATE` a
> principal's `kind` and `INSERT` a forged `owner` inside its own tenant
> today** (`UPDATE 1`, `INSERT 0 1`). RLS confines it to the tenant, so this
> is not a tenancy breach; but it means any capability column on
> `mt_principals` would have **no floor below the application** until that
> grant is revoked. The capability model therefore ships **with** the
> principal-table grant closure, or not at all.

---

## 0. The operator plane as it is — measured

| Fact | Measurement |
|---|---|
| Who a signed-in person is | `mt_auth_resolve_token(token_hash)` → `(principal_id, customer_id)` if the session is unrevoked, unexpired and `p.status = 'active'` — **`kind` is not returned**; `Kernel` passes `$who = [principal_id, customer_id]` into every handler |
| What `kind` does | `mt_principals.kind CHECK (owner \| operator)`, no column comment; `Projection::PRINCIPAL = [id, kind, display_name]` shows it on `/me`; **no line of PHP or SQL branches on it** |
| What a principal may do | **everything the operator plane offers**: 5 reads, `POST/PATCH/retire` plans, `POST` vouchers, `POST` revoke, `POST` disconnect — the six definer functions verify only *"actor is a principal of this customer"* |
| Who can write `mt_principals` | `dnb_app`: **SELECT INSERT UPDATE DELETE** (grant); `dnb_def_auth`: SELECT/UPDATE `USING (true)`; the only production-shaped writer is `Plugin/Simulator.php`, which INSERTs directly as `dnb_admin` |
| The estate | 3 principals, all `owner`, all with a phone; **0 `operator`** |
| Other `dnb_app` write grants that survive 024/025 | `mt_auth_sessions`, `mt_customers`, `mt_device_config`, `mt_device_secrets`, `mt_devices`, `mt_entitlements`, `mt_idempotency`, `mt_migrations`, `mt_principals`, `mt_services`, `mt_sessions`, `mt_sites`, `mt_uplink_samples` (INSERT/UPDATE, most also DELETE) and `mt_audit_log` UPDATE/DELETE (refused by trigger) — **14 tables**. B-2 closed the six commercial tables only. Recorded here as **B-3**, §F.3 |
| Prior product statements | `docs/46` §1.2: the owner cares about billing and usage, the receptionist issues vouchers all day; *"a receptionist should not see the hotel's invoices"*. `docs/48`: the operator sees a router's health **by name**, never provisions, never touches the tunnel or RADIUS. `docs/42` §4: the Reseller row (superseded) — vouchers *"within quota"*, sales *"own only"* |

The four planes, restated in the vocabulary of `docs/115`:

```
DishNet Staff      mt_staff (docs/114)     across operators, by capability, naming a target operator
Operator Owner     mt_principals.kind='owner'   ONE operator, every operator-plane capability
Operator Staff     mt_principals.kind='staff'   ONE operator, exactly the capabilities assigned
Guest              none                    a voucher, then a session
```

---

## A. The capability matrix — final

Capabilities live in their own namespace, `op.*`, so an operator-plane
capability can never be confused with a DishNet-staff `Capability`. The
canonical list is **one SQL function**, `mt_op_capabilities()`, and a test
asserts `OpCapability::ALL` in PHP equals it.

| Capability | Route(s) today | Owner | Manager | Seller | Viewer | DishNet staff equivalent (Admin plane, target operator) |
|---|---|---|---|---|---|---|
| `op.profile.read` | `GET /me` | ✅ | ✅ | ✅ | ✅ | `customers.read` |
| `op.profile.write` | *(no route yet)* | ✅ | — | — | — | `customers.write` |
| `op.locations.read` | `GET /me/services`, `/me/sites`, `/me/sites/{id}` | ✅ | ✅ | ✅ | ✅ | `sites.read` |
| `op.routers.read` | *(no route yet — router **name and state** at a site, never a secret; F3/F4)* | ✅ | ✅ | — | ✅ | `routers.read` |
| `op.intents.read` | `GET /me/intents` | ✅ | ✅ | — | ✅ | `intents.read` |
| `op.plans.read` | `GET /me/plans` | ✅ | ✅ | ✅ | ✅ | `plans.read` |
| `op.plans.write` | `POST /me/plans`, `PATCH /me/plans/{id}`, `POST …/retire` | ✅ | ✅ | — | — | `plans.write` |
| `op.vouchers.read` | `GET /me/vouchers` | ✅ | ✅ | ✅ | ✅ | `vouchers.read` |
| `op.vouchers.issue` | `POST /me/vouchers` | ✅ | ✅ | ✅ | — | `vouchers.generate` |
| `op.vouchers.revoke` | `POST /me/vouchers/{id}/revoke` | ✅ | ✅ | — | — | `vouchers.revoke` |
| `op.sessions.read` | `GET /me/sessions` | ✅ | ✅ | — | ✅ | `sessions.read` |
| `op.sessions.disconnect` | `POST /me/sessions/{id}/disconnect` | ✅ | ✅ | — | — | `sessions.disconnect` |
| `op.reports.read` | `GET /me/usage`, `GET /me/uplink` | ✅ | ✅ | — | ✅ | `health.read` (D-4 for uplink) |
| `op.sales.read` | *(no route yet — vouchers issued × price, per plan and period)* | ✅ | ✅ | — | — | `vouchers.read` |
| `op.billing.read` | `GET /me/entitlements` *(what the operator bought from DishNet)* | ✅ | — | — | — | `customers.read` |
| `op.audit.read` | *(no route yet — the operator's own audit trail)* | ✅ | — | — | — | `audit.read` |
| `op.staff.manage` | *(new routes, §E.3)* | ✅ | — | — | — | `customers.write` + the Admin-plane principal writer |

- **Owner** is not a preset: `kind = 'owner'` **implies every capability** and
  the `capabilities` column is empty by constraint.
- **Manager / Seller / Viewer are UI presets only.** The row stores the
  resolved list, so a preset can change later without rewriting anyone.
- **Not a Domain-B capability:** *support* — no ticketing exists in Domain B
  (`docs/110`). *Router actions, provisioning, tunnel, RADIUS* — DishNet only
  (F2, F3, `docs/48`); no `op.*` name exists for them, so none can be granted.
- `op.locations.write` does not exist: locations are created by DishNet staff
  (`docs/112`: none of the nine spine writers is customer-facing).

---

## B. Owner vs operator-staff — the differences

| | Owner | Staff |
|---|---|---|
| capabilities | **all**, implicitly; cannot be narrowed | **exactly** `capabilities[]`; `op.staff.manage` is **not grantable** (CHECK) |
| manages people | creates, disables, promotes, demotes, sets capabilities — within its operator | never — not even its own row |
| sees money | billing (entitlements), sales, audit | as granted; never billing or audit |
| count | **at least one active owner per operator** — disabling or demoting the last one is refused inside the function | any number |
| promotion / demotion | `staff → owner` by an owner, audited; `owner → staff` only while another active owner remains | — |
| identity rules | P-B (one phone, one principal), P-C (disable, never reassign), C10 (one person, one operator) — unchanged | identical |
| created by | DishNet staff on the Admin plane (the **first** owner of a new operator can be created by nobody else) or another owner | an owner, or DishNet staff |

**Why `kind` stays and `capabilities` is added rather than folding owner
into a capability list:** the owner invariant (*at least one*) and the
non-grantability of `op.staff.manage` are statements about a **class** of
person, not about a set; a CHECK can express them only if the class is a
column.

**Why the value `operator` is renamed to `staff`:** after T-1 the word
Operator means the tenant. A principal whose `kind` reads `operator` would
read as "the Operator itself". The user's own hierarchy says *Owner / Staff*,
so the CHECK becomes `('owner','staff')`. The naming rule that comes with it
is binding and pinned by a test (§G).

---

## C. Location-level restriction — later, not now

**Later.** The evidence:

1. No link exists between a principal and a site; every operator-plane read
   is tenant-wide by RLS, and the request itself says *"belongs to exactly one
   Operator initially"*.
2. The reference portal has location users because its **Location** is the
   tenant-like unit; ours is the Operator (`docs/115` A.2, D.1).
3. A site scope is a **second predicate inside the tenant** on every table
   that carries `site_id` — sites, devices, plans, vouchers, batches, and
   sessions through the voucher — plus a session model that carries the
   scope. That is real RLS work and deserves its own evidence, not a column
   reserved on faith.
4. The estate this product starts with is single- and dual-site operators.

**What is reserved:** nothing physical. When wanted (`docs/115` T-6) it is
`mt_auth_sessions.site_scope uuid[]` set at login from the principal, a
`mt_current_site_scope()` GUC reader, and `AND (site_id IS NULL OR site_id =
ANY (mt_current_site_scope()))` appended to the affected policies — with
the same negative/positive proofs the existing isolation has.

---

## D. Exact changes to `mt_principals` — migration 027 (proposed; 026 is staff identity)

| # | Change | Note |
|---|---|---|
| 1 | `kind` CHECK → `('owner','staff')`; data rewrite `UPDATE mt_principals SET kind = 'staff' WHERE kind = 'operator'` | synthetic estate: 0 rows affected. **Production: count first** — `docs/79` gains one line: *principals by kind and status* |
| 2 | `ADD COLUMN capabilities text[] NOT NULL DEFAULT '{}'` | inherits RLS with the row |
| 3 | `CREATE FUNCTION mt_op_capabilities() RETURNS text[] IMMUTABLE` — the canonical list | the **single source of truth**; PHP's `OpCapability::ALL` is asserted equal |
| 4 | `CHECK (capabilities <@ mt_op_capabilities())` | an unknown capability is a constraint violation, not a silent no-op |
| 5 | `CHECK ((kind = 'owner' AND capabilities = '{}') OR (kind = 'staff' AND NOT ('op.staff.manage' = ANY (capabilities))))` | owner implies all; staff can never hold staff management |
| 6 | **`REVOKE INSERT, UPDATE, DELETE ON mt_principals FROM dnb_app`** | the floor. After this the only writers are the functions below. A test proves the direct `UPDATE … kind` and the forged `INSERT` are **refused**, with `dnb_def_comm` as the positive control |
| 7 | `mt_principal_can(p_principal uuid, p_capability text) RETURNS boolean` — `STABLE SECURITY DEFINER`, owner **`dnb_def_comm`** | `dnb_def_comm` has **no widening policy** (024), so the read is RLS-bound: a principal outside the caller's tenant answers `false`. Owner → `true` for every `op.*`; staff → membership in `capabilities`; disabled → `false` |
| 8 | operator-plane writers, owner `dnb_def_comm`, EXECUTE → `dnb_app`: `mt_principal_create(p_kind, p_display_name, p_phone, p_capabilities, p_actor)` · `mt_principal_set_capabilities(p_principal, p_capabilities, p_actor)` · `mt_principal_set_kind(p_principal, p_kind, p_actor)` · `mt_principal_disable(p_principal, p_actor)` | tenant = `mt_current_customer()`, **no customer parameter**; `p_actor` must be an **active owner of the same tenant** (checked inside); last-owner guard inside `set_kind`/`disable`; disable **revokes every live session of that principal in the same transaction** through a new `dnb_def_auth` function `mt_auth_revoke_principal_sessions(p_principal)`, EXECUTE → `dnb_def_comm` only; duplicate phone → refusal (P-B); each writes exactly one audit row |
| 9 | Admin-plane writer, owner decided by measurement (a role with an explicit INSERT policy on `mt_principals` across tenants — `dnb_def_auth` holds SELECT/UPDATE only today), EXECUTE → `dnb_adminwrite`: `mt_admin_principal_create(p_operator uuid, p_kind, p_display_name, p_phone, p_capabilities, p_actor text)` | **the first owner of a new operator can be created by nobody else.** Target operator explicit and validated; `actor_kind = 'staff'`; this is `docs/112`'s spine step 1 with its target renamed |
| 10 | `mt_auth_resolve_token(p_token_hash)` → `RETURNS TABLE (principal_id, customer_id, kind, capabilities)` | **re-read live** on every request, as `status` already is — a capability change or demotion takes effect immediately, the P-C property |
| 11 | the six commercial functions gain `IF NOT mt_principal_can(p_actor, '<capability>') THEN RAISE … USING ERRCODE = '42501'` before the mutation | the floor beneath the route guard; a refusal writes **no** audit row (W-1.2) |
| 12 | `mt_admin_principals()` projection, `dnb_def_admin`: `id, customer_id, kind, display_name, status, capabilities, created_at, last_login_at` | **`phone`, `email`, `credential_hash` withheld** — phone is the authentication key (`docs/100`) |
| — | `credential_hash` (dead, `docs/100`) | **not touched** — its own decision |

Idempotency (`docs/108` classes): principal-by-phone → the UNIQUE index;
NULL-phone principal → `mt_idempotency(customer_id, key)` on the operator
plane, since the tenant is known; the Admin-plane creator keys on the target
operator the same way. RULE I-1 holds: the replay check runs before the
audited mutation.

---

## E. Exact changes to the API guards

### E.1 Resolution

`Authenticator::resolve()` returns `principal_id, customer_id, kind,
capabilities`; `Kernel` is unchanged and passes it as `$who`. Nothing is read
from the request.

### E.2 A guard on every `/me` route

`src/Api/Routes.php` gains the same shape `AdminRoutes` already has:

```php
$guard = static fn(string $cap, callable $h) => static function (Request $req, Database $db, array $who) use ($cap, $h) {
    if (!OpCapability::allows($who, $cap)) {          // owner ⇒ true; staff ⇒ in capabilities[]
        return new Response(403, ['error' => 'forbidden', 'capability' => $cap]);
    }
    return $h($req, $db, $who);
};
```

Every `/me` route is wrapped with the capability from §A. **The customer
plane's "404, never 403" rule is untouched:** that rule protects *records* —
a foreign id must not learn that a row exists. A 403 here describes the
caller's **own** role inside its **own** operator and discloses nothing about
any other tenant. A test asserts a foreign site id is still 404 for a staff
member who holds `op.locations.read`.

### E.3 New routes — all `op.staff.manage`

`GET /me/staff` · `POST /me/staff` · `POST /me/staff/{id}/capabilities` ·
`POST /me/staff/{id}/kind` · `POST /me/staff/{id}/disable`. The list shows
`id, kind, display_name, status, capabilities` and a **masked** phone (never
the full number of another principal — the existing `Projection` rule).
`Projection::PRINCIPAL` gains `status` and `capabilities`.

### E.4 The Admin plane

`POST /operators/{id}/staff` (capability `customers.write`, target operator
explicit) → `mt_admin_principal_create`, in the vocabulary T-1 fixed; this
route is what makes the first owner exist.

### E.5 Tests that must exist before any of it is called real

- the **matrix**: every `/me` route × owner / each preset / a staff row with
  an empty list → exactly the table in §A, both 200-or-404 and 403;
- **the floor**: calling `mt_voucher_batch_issue` directly as `dnb_app` with a
  staff actor lacking `op.vouchers.issue` is **refused by the function**, so
  removing the route guard cannot reopen it (a control on the control);
- **the grant**: `dnb_app` cannot INSERT/UPDATE/DELETE `mt_principals`;
  `dnb_def_comm` can — the §0 execution test, inverted;
- **the invariant**: the last active owner cannot be disabled or demoted;
- **immediacy**: a capability removed while a session is live is refused on
  the very next request; a disabled principal's live session is gone;
- **naming**: no code maps `mt_principals.kind` onto `mt_audit_log.actor_kind`
  (§G).

---

## F. RLS implications

1. **No policy changes.** Capabilities are evaluated *inside* the tenant;
   `mt_principals` keeps `customer_id = mt_current_customer()`; the new
   column inherits it; `mt_principal_can()` is RLS-bound by construction
   because its owner has no widening policy.
2. **The measured hole is closed by a grant revoke, not a policy.** Today's
   `dnb_app` INSERT/UPDATE/DELETE on `mt_principals` lets the HTTP role rewrite
   `kind` within its tenant (§0). After D.6 the only writers are definer
   functions that check the actor's class. This is B-2's shape applied to the
   identity table.
3. **B-3 — recorded, not done here.** Thirteen more tables still carry
   `dnb_app` write grants (§0). Each must be **inventoried for actual
   writers before it is revoked**, exactly as B-2 was (`mt_idempotency` may
   still be written by the application; `mt_auth_sessions` and `mt_sessions`
   are written by definer functions and the grants look dead). It is its own
   task, after G-B.
4. Site scoping (§C) would be the first RLS extension, later.

---

## G. Audit implications

| Act | actor_kind | actor | detail |
|---|---|---|---|
| any operator-plane act by an owner or a staff member | `principal` | principal id | `{principal_kind, capability}` recorded **at act time** — a later demotion must not rewrite history |
| `principal.created` / `.disabled` / `.capabilities_changed` / `.kind_changed` by an owner | `principal` | the owner's id | target id, previous and new value, reason if given |
| the same by DishNet staff on the Admin plane | `staff` | the staff username (D-AUTH-6) | `{operator: <target>}` |
| a refused act (403 at the route, `42501` in the function) | — | — | **no row** (W-1.2) |

**The naming rule, binding:** `mt_audit_log.actor_kind = 'staff'` means
**DishNet staff and nothing else**; every person of an operator — owner or
staff — is `actor_kind = 'principal'`, with `detail.principal_kind` saying
which. No actor kind is added: `principal | staff | system` is
CHECK-constrained and stays so. A test scans the schema and the code for
any mapping from `mt_principals.kind` to `actor_kind` and fails on one.

`op.audit.read` (owner-only, §A) is how an owner would read this trail;
`mt_admin_audit()` already exposes it to DishNet staff across operators.

---

## H. Remaining blockers to G-B

**None from T-2.** G-B is DishNet staff authentication (`docs/114`); it does
not depend on operator-staff capabilities, and the reverse dependency runs
the other way (the Admin-plane principal creator needs a staff actor).

What precedes G-B: **the T-1 vocabulary pass** — a small, mechanical commit
(Admin API keys and paths `customer → operator`, panel labels *Operators &
locations*, manifest, tests) so that G-B's new routes and tests are written
in the final vocabulary once.

What does **not** block G-B: O-1 and the census, this document's migration
027, B-3, T-6, T-10, T-11, hardware.

---

## I. Updated implementation sequence

```
1  T-1 vocabulary pass                      Admin API/UI say Operator; no schema change
2  G-B  — docs/114                          migration 026: dnb_def_staff, dnb_staffauth, mt_staff*,
                                            provider, credential login, revocable sessions, TOTP,
                                            the twelve proofs, suite ×2, real HTTP
3  T-2 build — this document                migration 027: kind owner|staff, capabilities[],
                                            mt_op_capabilities(), principal grant closure,
                                            mt_principal_can(), operator-plane staff functions,
                                            resolve_token(kind, capabilities), route guards,
                                            the six functions' floor, /me/staff, mt_admin_principals(),
                                            mt_admin_principal_create (target operator) — spine step 1
4  G-C  — routers                           register / assign / actions bound, NullDelivery
5  G-C2 — commercial writes by DishNet staff, target operator explicit
6  B-3  — inventory, then close the remaining dnb_app write grants
7  O-1 census → migration → site writer → operator-create caller → 2b
8  later, each its own decision             T-6 location scope · T-10 reports · T-11 access log
```

*Nothing above is implemented. Migrations end at 025, `DenyAllIdentity`
remains the production binding, `dnb_app` can still rewrite a principal's
kind until 027 lands, and the production census remains the handoff for O-1
and now also counts principals by kind.*

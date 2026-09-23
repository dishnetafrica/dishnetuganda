# 116 — T-2: the Operator Staff capability model (C6 / C16)

**Status: BUILT 2026-09-23 in the development and test schema — migration 027; §J records the pre-implementation review, §K the build and its proofs. Production: NOT applied, nothing installed anywhere, and the production count of `kind = 'operator'` rows is NOT ESTABLISHED — census §1c (`docs/79`) is what establishes it.** Vocabulary per `docs/117`. **B-3 remains OPEN.** Answers `docs/115` T-2
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

## B. Owner vs Operator Staff — the differences

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
not depend on Operator Staff capabilities, and the reverse dependency runs
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

*When §A–§I were written nothing was implemented and migrations ended at
025. §J and §K below record the build of migration 027.*

---

## J. T-2 pre-implementation review — 2026-09-23, before any code

Read again before coding: this document (§A–§I), `docs/114` §M.3 (D-AUTH-3
*target operator*, D-AUTH-6 audit identity, D-AUTH-7) and §O (what G-B built),
`docs/117` (vocabulary), and the closed decisions P-B, P-C, S-A, T-1, T-8.
**No contradiction with a closed decision was found.** The points below are
where the instruction, this document and the code as it stands leave a choice;
each is resolved here, in writing, so the build resolves nothing silently.

| # | Point | Resolution |
|---|---|---|
| **J-1** | §E.4 binds `POST /operators/{id}/staff` on the Admin plane; the instruction's API scope names the `/me/staff` routes only, forbids G-C/G-C2, and `docs/117` kept the `/customers` Admin paths for a later non-breaking change | **`mt_admin_principal_create` is built and proved at the function level (instruction item 6). The Admin HTTP route is NOT bound**: it is declared in the manifest under `declared_unbound` as `POST /customers/{customer_id}/principals` (`customers.write`) and answers 501 like the seven estate writes, so the served surface stays truthful. Binding it is one guarded handler and needs its own instruction |
| **J-2** | §D.12 specifies the `mt_admin_principals()` projection but no route | **`GET /api/v1/admin/principals` (`customers.read`) is bound** — a read projection like the thirteen, declared in the manifest, tested for withheld fields. A projection nothing serves cannot be proved over the API |
| **J-3** | §D.9 leaves the owner of `mt_admin_principal_create` to measurement | **Measured:** `dnb_def_prov` already holds an `INSERT WITH CHECK (true)` policy on `mt_customers` and no other definer role writes across tenants; `dnb_def_auth` holds SELECT/UPDATE only and is the authentication role. **`dnb_def_prov` owns it**, gains exactly one policy — `INSERT WITH CHECK (true)` on `mt_principals` — and no SELECT: the function pre-generates the uuid and inserts without `RETURNING`, the `mt_customer_create` pattern, so an unknown target operator is refused by the foreign key and a duplicate phone by the unique index. `dnb_def_prov` reads no principal of any tenant |
| **J-4** | the data rewrite `operator → staff` runs as the migration owner, which since 017 is subject to FORCE RLS and sees **zero** principals with no tenant context (the O-1 lesson) | **The rewrite runs `SET LOCAL ROLE dnb_def_auth`**, whose `UPDATE USING (true)` policy sees every row; the migration reports the count it rewrote (`RAISE NOTICE`), then asserts as the same role that no `operator` value remains before the new CHECK is added — the CHECK validates the real rows anyway and would refuse, but the assertion names the count. **The production count is still a census line** (`docs/79` and `production_census.sql` gain *principals by kind and status*); this migration is not authorised for production any more than 020–026 are |
| **J-5** | §D.8 signatures carry no request source; the six commercial functions record `p_source` | the four operator-plane writers take a trailing **`p_source text DEFAULT NULL`**, so the audit row carries the request address exactly as the six do |
| **J-6** | may an owner disable or demote **itself**? §B is silent | **Refused** (`check_violation`), as 026 refuses staff self-disable: an owner removing its own authority mid-session is a footgun, and the last-owner invariant already covers the only case where it would matter. Recorded, not silent |
| **J-7** | `mt_principal_set_kind` and the `capabilities` column | demotion to `staff` **clears** capabilities to `{}` (the CHECK requires an owner's list empty; a freshly demoted staff member holds nothing until an owner grants it); promotion clears too. Neither revokes sessions: §D.10's live re-read makes the change effective on the next request, which the suite proves |
| **J-8** | idempotency for `POST /me/staff` (§D: NULL-phone principal → `mt_idempotency`) | **Not built.** `src/Http/Idempotency.php` is wired to no route today and I-A is open; the phone-unique index covers every principal who can sign in. Recorded as the I-A item it is |
| **J-9** | the capability floor's SQLSTATE | **`42501` with the message `capability required: <op.x>`**, raised before the mutation so no audit row exists to roll back. The customer API maps *that message* on 42501 to **403 `forbidden`** naming the capability; any other 42501 (a missing grant) stays a 500, so a misconfiguration cannot masquerade as a policy refusal |
| **J-10** | `mt_principal_can` vs the floor | `mt_principal_can(p_principal, p_capability)` is the boolean §D.7 specifies, EXECUTE to `dnb_app` (RLS-bound: a foreign or disabled principal answers `false`). The floor inside the writers is an internal `mt_principal_require(p_actor, p_capability)` that raises 42501 and returns the actor's kind for the audit detail; it is granted to nobody |
| **J-11** | which roles may call the four operator-plane writers | `dnb_app` only, as §D.8 says; **not** `dnb_admin`, `dnb_worker` or `dnb_adminwrite`. `dnb_def_comm` gains `INSERT, UPDATE` on `mt_principals` and stays without any widening policy, so the writers are tenant-bound by RLS below the function |
| **J-12** | the two test fixtures and the simulator insert principals **directly as `dnb_app`** | they cannot after D.6, and that is the point. They now create principals through **`mt_admin_principal_create`** on the Admin write connection — the real path — and the isolation test proves the RLS floor beneath the revoked grant with the table **owner**, which still holds INSERT and is still bound by FORCE RLS |
| **J-13** | §E.2's 403 on a `/me` route vs the customer plane's *"404, never 403"* | preserved exactly as §E.2 states: 403 describes the caller's own role inside its own operator; a foreign record stays 404. The matrix test asserts both on the same route |
| **J-14** | the Customer PWA screens for staff management | **Not in scope** (instruction item 9 lists the API). The PWA keeps rendering `/me`; `principal` gains `status` and `capabilities` |

None of these changes a decision this document froze. Every one is measured or
asserted by the suite where a claim is made.

---

## K. T-2 build record — 2026-09-23 (migration 027)

**Built in the development and test schema only.** Nothing is installed
anywhere; production remains behind the census (`docs/79`), and the production
count of `kind = 'operator'` rows is **NOT ESTABLISHED** — §1c of the census is
what will establish it. The suite: **32 suites / 2,769 assertions / 0 failed,
twice** (was 31 / 2,375). `tests/test_operator_staff.php` alone: **378**.

### K.1 What shipped, against §D and §J

| # | §D item | Delivered as |
|---|---|---|
| D.1 | `kind` → `owner \| staff`; data rewrite | **as `dnb_def_auth`** (J-4): `RAISE NOTICE` census per kind and status, `UPDATE … SET kind = 'staff' WHERE kind = 'operator'`, the rewritten count reported, an assertion that none remain, **then** `CHECK (kind IN ('owner','staff'))` |
| D.2 | `capabilities text[] NOT NULL DEFAULT '{}'` | as specified, column comments on both |
| D.3 | `mt_op_capabilities()` | the 17 names of §A, `IMMUTABLE`; `OpCapability::ALL` asserted equal element for element. **EXECUTE to `dnb_def_comm`, `dnb_def_prov`, `dnb_def_auth` only** — see K.2.3 |
| D.4 / D.5 | the two CHECKs | `mt_principals_capabilities_known`, `mt_principals_kind_capabilities` |
| D.6 | `REVOKE INSERT, UPDATE, DELETE ON mt_principals FROM dnb_app` | done; SELECT kept; proved by execution (K.4) |
| D.7 | `mt_principal_can` | `STABLE SECURITY DEFINER`, `dnb_def_comm`, EXECUTE `dnb_app`; RLS-bound (foreign → `false`, disabled → `false`, unknown name → `false`) |
| D.8 | the four writers | `mt_principal_create / _set_capabilities / _set_kind / _disable`, each with trailing `p_source` (J-5), owner `dnb_def_comm`, EXECUTE **`dnb_app` only** (J-11); the floor is the internal `mt_principal_require` (J-10), granted to nobody; self-acts refused (J-6); `set_kind` clears the list and revokes nothing (J-7); `disable` revokes every session through `mt_auth_revoke_principal_sessions` (`dnb_def_auth`, EXECUTE `dnb_def_comm` only) |
| D.9 | `mt_admin_principal_create(p_operator, …, p_actor)` | owner **`dnb_def_prov`** with one `INSERT WITH CHECK (true)` policy and no SELECT (J-3); target explicit; the body never references `mt_current_customer()` (asserted on `prosrc`); audit `actor_kind = 'staff'`, `source = 'admin'`, `detail.operator`; EXECUTE `dnb_adminwrite` |
| D.10 | `mt_auth_resolve_token` → `(principal_id, customer_id, kind, capabilities)` | dropped and re-created; `Authenticator::resolve()` carries both into `$who`; re-read live, proved on an existing session |
| D.11 | the floor in the six | each re-created with `v_kind := mt_principal_require(p_actor_principal, '<op.x>')` **after** the actor check and **before** any mutation; detail gains `principal_kind` + `capability`; refusal is **`42501` `capability required: <op.x>`** (J-9) and writes nothing |
| D.12 | `mt_admin_principals()` | eight columns exactly; `phone`, `email`, `credential_hash` unreturnable; `dnb_def_admin` gains a SELECT-only policy on `mt_principals`; EXECUTE `dnb_adminapi`; **bound** as `GET /api/v1/admin/principals` (`customers.read`, J-2) |
| §E.2 | a guard on every `/me` route | `Routes::$guard(capability, handler)` on all 17 existing routes — a route cannot be added without naming a capability; 403 `{error: forbidden, capability}`; `Kernel` maps **only** the `capability required:` 42501 to 403, any other 42501 stays 500 |
| §E.3 | the staff routes | `GET /me/staff` (listing with `phone_masked`, last three digits), `POST /me/staff`, `POST /me/staff/{id}/capabilities`, `…/kind`, `…/disable` — all `op.staff.manage`; 23505 → 409 `phone unavailable`; the function's `check_violation` messages → 409; NULL → 404 |
| §E.4 | the Admin plane | `POST /api/v1/admin/customers/{customer_id}/principals` **declared unbound, 501** (J-1) |
| §G | audit | every operator-plane act `actor_kind = 'principal'` with `detail.principal_kind` and `detail.capability` at act time; Admin-plane creation `actor_kind = 'staff'`; a test scans every `mt_audit_write(` call in every migration for a literal third argument and `src/` for any mapping of `kind` onto `actor_kind` |
| J-12 | fixtures and the simulator | `tests/bootstrap.php` and `Plugin/Simulator.php` create the first owner through `mt_admin_principal_create` on the Admin write connection; A's seed now carries `customer.created` **and** `principal.created` |
| census | `docs/79` §6 + `production_census.sql` SECTION 1c | principals by kind and status, `mt_admin_principals()` where 027 is applied, the base table otherwise, UNREADABLE / 0-under-RLS reported as such |

### K.2 Three findings made while building

**K.2.1 The rewrite is RLS-blind for the migration owner — measured, so 027
does not run it as the owner.** A disposable database was built to level 026
with the real `Migrator`, seeded as the fixture identity with **two
`operator` principals and one `owner`**, and 027 applied as the owner `dnb` in
one transaction with its notices captured:

```
CONTROL, owner dnb with no tenant context sees: 0 principals (FORCE RLS)
NOTICE:  027 census — principals kind=operator status=active: 2
NOTICE:  027 census — principals kind=owner status=active: 1
NOTICE:  027 — rewrote 2 principal row(s) from operator to staff
after: kind=owner status=active caps={} n=1
after: kind=staff status=active caps={} n=2
after: retired value remaining: 0
after: kind CHECK: CHECK ((kind = ANY (ARRAY['owner'::text, 'staff'::text])))
after: audit rows written by the migration: 0
```

The database was dropped; residue zero. **This is NOT production evidence** —
it proves the instrument (the migration counts what is there and rewrites all
of it) on synthetic rows. Unlike the O-1 foreign-key validation, an `ADD
CONSTRAINT … CHECK` scan is *not* RLS-blind, so even an owner-run rewrite that
saw nothing would have failed closed on the CHECK; what the `dnb_def_auth`
rewrite adds is that the count is **reported and asserted** rather than the
migration merely erroring.

**K.2.2 The last-owner invariant was unreachable as first written.** Only an
active owner can be the actor of `set_kind` / `disable`; with one owner left,
the actor *is* that owner, so a self-guard checked first shadowed the
invariant forever — and a guard that cannot fire cannot be proved. The
invariant is now checked **before** the self-guard in both functions; both
messages are reached and asserted over HTTP and at the function.

**K.2.3 `mt_op_capabilities()` was first PUBLIC-executable** "because it
names no secret". `test_isolation_s1_s2` refused it — no `mt_` function may be
PUBLIC-executable, no exceptions — and its sweep revoked the grant mid-run,
after which every `INSERT` into `mt_principals` failed: a CHECK expression
runs as the role writing the row. EXECUTE now goes to exactly the three
definer roles that write or update the table (`dnb_def_auth` because 007's
`last_login_at` UPDATE re-evaluates every CHECK), the owner implicitly. **The
guard was right; the migration was wrong.**

### K.3 The instruction's sixteen proofs — where each lives (`tests/test_operator_staff.php`)

| Proof | Section |
|---|---|
| owner holds every capability · staff holds exactly its list · staff cannot receive `op.staff.manage` | 3 (function level, both CHECKs and the PHP mirror), 5 (the 22-route × 5-identity matrix) |
| last active owner cannot be disabled · cannot be demoted | 8 — over HTTP (409) and at the function (23514), with two owners present for the self-guard cases |
| staff cannot manage staff even if the HTTP guard is bypassed | 4 (direct calls as `dnb_app`, five 42501s, zero audit rows) and 11b (an unguarded route through the Kernel → 403) |
| `dnb_app` cannot directly mutate `mt_principals` | 2 — UPDATE kind, INSERT owner, DELETE, UPDATE capabilities, each `permission denied`; SELECT as the control; `dnb_def_comm` as the positive control, refused by policy across tenants |
| cross-tenant principal mutation fails | 9 — NULL from all three writers under B, `false` from `can`, 404 over HTTP indistinguishable from an absent id, foreign actor refused |
| live capability re-read | 7 — the same token is refused on its next request; promotion and demotion likewise |
| disabling revokes sessions | 10 — proved **in one transaction** by rolling one back (sessions and status both return), then for real (two tokens → 401, `sessions_revoked` in the detail) |
| Admin Staff can target an explicit Operator · target cannot be substituted by `mt_current_customer()` | 12 — row lands in B with `app.customer_id` set to A on the connection; NULL target refused; `prosrc` contains no `mt_current_customer` |
| six commercial functions enforce the floor · refusal produces no audit row | 11 — six 42501s, delta 0, the plan untouched; the seller's own `voucher.issued` audited with `principal_kind = staff` |
| audit `actor_kind` semantics | 6, 12, 14 — literal scan of every `mt_audit_write(` call; no `kind → actor_kind` mapping in `src/`; CHECK still `principal \| staff \| system` |
| Admin projection contains no withheld identity fields | 13 — eight keys exactly; no `phone`/`email`/`credential`/`token`/`hash`/`secret` and no seeded digit string in the response; `proargnames` cannot return them |
| security regression | 15 — migrations end at 027, no 028, ledger 27; census §1c present; 1b — EXECUTE on every 027 function enumerated from `pg_roles`; 2b — B-3's thirteen tables asserted unchanged |

### K.4 `dnb_app` privilege evidence — exact, by execution

| Measurement (as `dnb_app`, inside its own tenant context unless stated) | Result |
|---|---|
| `has_table_privilege('dnb_app','mt_principals', INSERT / UPDATE / DELETE)` | **false / false / false** |
| `has_table_privilege('dnb_app','mt_principals', SELECT)` | true |
| `UPDATE mt_principals SET kind = 'owner' WHERE id = <own owner>` | **42501 permission denied for table mt_principals** |
| `INSERT INTO mt_principals (customer_id, kind, display_name) VALUES (<own>, 'owner', 'forged')` | **42501 permission denied** |
| `DELETE FROM mt_principals WHERE id = …` | **42501 permission denied** |
| `UPDATE mt_principals SET capabilities = '{op.staff.manage}' …` | **42501 permission denied** |
| `SELECT count(*) FROM mt_principals` (control) | ≥ 1 |
| `dnb_def_comm`, same context, INSERT under its own tenant (control, rolled back) | `INSERT 0 1` |
| `dnb_def_comm`, same context, INSERT naming the other tenant | **42501 new row violates row-level security policy** |
| policies whose role list names `dnb_def_comm` | **0** |
| `dnb_app` write grants remaining (B-3) | exactly `mt_audit_log mt_auth_sessions mt_customers mt_device_config mt_device_secrets mt_devices mt_entitlements mt_idempotency mt_migrations mt_services mt_sessions mt_sites mt_uplink_samples` — thirteen; `mt_principals` absent |
| `dnb_admin`, `dnb_worker` INSERT on `mt_principals` | **true** — migration 015's blanket grant, **F-3, OPEN**, no production route connects as either; recorded and asserted, not 027's to revoke |

### K.5 What did NOT move

- **B-3 is OPEN** — the thirteen tables above keep their `dnb_app` write
  grants; inventory every writer before revoking, as B-2 did; its own task.
- **G-C, G-C2, O-1, G-D, T-6, T-10, T-11 — not begun.** `DenyAllIdentity`
  is still the default Admin binding; no production TLS; no
  `staff:bootstrap` anywhere; nothing installed; the production census is
  still the handoff and still first.
- **Not built, by decision recorded in §J:** the Admin HTTP route (J-1, 501
  declared), idempotency for `POST /me/staff` (J-8, I-A), the Customer PWA
  staff screens (J-14).
- **F-3** — `dnb_admin` / `dnb_worker` still hold 015's blanket grants on
  `mt_principals`; measured and asserted, out of scope here.
- `credential_hash` untouched (its own decision).

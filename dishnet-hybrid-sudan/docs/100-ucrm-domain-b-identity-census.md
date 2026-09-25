# 100 — uCRM ↔ Domain-B identity census

**Read-only. Nothing was migrated, no schema altered, no row backfilled, no
link created, nothing installed.** Every number below came from running a query
or a command; where something could not be measured it says so.

---

## 0. What this census is, and what it is NOT

It was run against the **development databases in this container**:

| Database | Contents |
|---|---|
| `dnb_sim` | the simulated estate, built through the real write paths (`docs/91`) |
| `dnb_test` | rebuilt by `tests/run.sh` on every run |

> ### This is NOT E-2.
>
> **E-2 — how many `mt_customers` rows production holds, and how many could be
> linked — remains OPEN and unmeasured.** This session cannot reach the DishNet
> server, and `docs/79` remains the handoff. **Production data state is NOT
> ESTABLISHED and must not be inferred from what follows.**

What a development census *can* establish, and does: **the schema, its
constraints, and how they behave under the operations Model A would require.**
Those are properties of the schema, not of the data, so they hold wherever the
schema is installed. §2 and §4 are of that kind. §1's row counts describe the
simulator and nothing else.

---

## 1. Domain-B customer census — `dnb_sim`

```
total_rows          3
with_ucrm_link      0
without_ucrm_link   3
distinct_links      0
earliest/latest     2026-09-22 (all created the same day, by the simulator)
active / not active 3 / 0
```

Dependants, per customer:

| Customer | services | sites | routers | vouchers | sessions | principals | audit |
|---|---|---|---|---|---|---|---|
| SIM-CUST-001 Riverside Hotel | 1 | 2 | 2 | 8 | 2 | 1 | 12 |
| SIM-CUST-002 Kabale Hostel | 1 | 1 | 1 | 5 | 2 | 1 | 4 |
| SIM-CUST-003 Mbarara Lodge | 1 | 2 | 1 | 4 | 2 | 1 | 8 |

> **C-1 — every customer already has dependants, including audit rows.**
> Not one of the three is a bare row that could simply be dropped. That is not
> an artefact of the simulator: a customer acquires an audit row the moment
> anything is done to it (W-1 writes one inside every provisioning function), so
> **any customer that has ever been used has history that cannot be deleted.**

---

## 2. Principal census — and what `phone` actually is

```
total               3        distinct_customers   3
has_display_name    3        owners / operators   3 / 0
has_phone           3        max principals on one customer: 1
has_email           0
has_credential_hash 0
has_logged_in       0
```

Two findings, both measured.

### P-1 — `credential_hash` is dead

Its only mentions in the whole codebase are its own `CREATE TABLE` line and a
comment in `Projection.php` saying it never leaves. **No code reads or writes
it.** Authentication is entirely OTP:

```
mt_auth_issue_code(p_phone, p_code_hash, p_ttl)
mt_auth_verify_code(p_phone, p_code_hash) -> (principal_id, customer_id)
mt_auth_create_session / mt_auth_resolve_token / mt_auth_revoke_token
```

### P-2 — the phone is the AUTHENTICATION KEY, not a contact detail

From `migrations/007_auth.sql`:

```sql
SELECT id, customer_id INTO v_principal, v_customer
  FROM mt_principals WHERE phone = p_phone AND status = 'active';
```

**The entire PWA identity chain starts from `mt_principals.phone`.** It is
uniquely indexed — `mt_principals_phone_uq ... WHERE (phone IS NOT NULL)` — so
the lookup is unambiguous.

> This **reframes U-6 (I-2)**. `docs/99` recorded that `mt_principals` holds
> name, phone and email that uCRM also owns, and asked whether they should be a
> cache. That framing was incomplete: `email` is unused and `credential_hash`
> is dead, but **`phone` is a credential lookup key**. It cannot be treated as
> cached contact data, because:
>
> - if uCRM's number changes and Domain B's does not, the customer keeps
>   logging in with the old one;
> - if Domain B's is refreshed from uCRM, **the customer is locked out the
>   moment uCRM's record is corrected**, with no warning;
> - a uCRM contact edit becomes, silently, a credential rotation.
>
> **U-6 is therefore not a caching question. It is: what is authoritative for
> the number a customer authenticates with, and what is the procedure when the
> two disagree?**

---

## 3. Service census

```
kind              status   rows   customers
mikrotik_hotspot  active   3      3
max services on any one customer: 1
columns: id, customer_id, kind, status, started_at, ended_at
```

`kind` is CHECK-constrained to `mikrotik_hotspot` alone. The schema permits many
services per customer; exactly one exists per customer today.

---

## 4. Foreign-key and delete census

**27 foreign keys** reference the three identity tables. Enumerated from
`pg_constraint`, not from the migration text.

### 4.1 Children of `mt_customers` — 18

| ON DELETE | Count | Tables |
|---|---|---|
| **RESTRICT** | 15 | `mt_audit_log`, `mt_auth_sessions`, `mt_devices`, `mt_entitlements`, `mt_hotspot_users`, `mt_idempotency`, `mt_intents`, `mt_plans`, `mt_principals`, `mt_services`, `mt_sessions`, `mt_sites`, `mt_uplink_samples`, `mt_voucher_batches`, `mt_vouchers` |
| **NO ACTION** | 2 | `mt_device_config`, `mt_device_secrets` |
| **CASCADE** | 1 | `mt_auth_codes` |

> **The NO ACTION pair is cosmetic, not a hole.** Measured: neither constraint
> is `DEFERRABLE`, and **there is not one deferrable foreign key in the whole
> schema**. A non-deferrable `NO ACTION` is checked at the same moment as
> `RESTRICT` and blocks the same delete. The inconsistency is in the wording of
> two migrations, not in behaviour.

### 4.2 Children of `mt_principals` — 7

| ON DELETE | Tables |
|---|---|
| **CASCADE** | `mt_auth_codes`, `mt_auth_sessions` |
| **SET NULL** | `mt_idempotency.principal_id`, `mt_intents.actor_principal_id`, `mt_plans.created_by`, `mt_voucher_batches.created_by`, `mt_vouchers.sold_by` |

> **C-2 — deleting a principal silently erases attribution.** `created_by` and
> `sold_by` become `NULL`; the plan, batch and voucher survive with no record of
> who made or sold them. Nothing errors. Any design that replaces principals
> with uCRM contacts must say what happens to that history first.

### 4.3 Children of `mt_services` — 2

`mt_entitlements.service_id` **CASCADE**; `mt_sites.service_id` **RESTRICT**.

### 4.4 Can an existing customer be deleted? — proved by execution

Rows present in 12 child tables: `mt_uplink_samples` 36, `mt_audit_log` 29,
`mt_vouchers` 17, `mt_hotspot_users` 17, `mt_sessions` 6, `mt_intents` 6,
`mt_devices` 5, `mt_sites` 5, `mt_plans` 3, `mt_principals` 3,
`mt_voucher_batches` 3, `mt_services` 3.

Attempted inside a transaction and rolled back:

```
BEGIN;  DELETE FROM mt_customers WHERE name LIKE 'SIM-CUST-001%';
ERROR:  update or delete on table "mt_customers" violates foreign key
        constraint "mt_principals_customer_id_fkey" on table "mt_principals"
DETAIL: Key (id)=(ea2e…1f97) is still referenced from table "mt_principals".
ROLLBACK
```

> **C-3 — "delete the unlinked rows" is not an available migration step.**
> An unlinked customer with any history must be **linked**, not removed. The
> only rows that could be deleted are ones nothing has ever touched — and C-1
> says a used customer always has an audit row.

---

## 5. The sibling plugin's link precedent

`migrations/026_lte_financial_ledger.sql`, in its own words:

> *"Cross-reference between LTE subscribers and UCRM clients (when same person).
> **More flexible than storing ucrm_id directly in lte_subscribers**"*

```sql
CREATE TABLE IF NOT EXISTS lte_service_links (
    lte_subscriber_id INTEGER NOT NULL,   -- the plugin's own entity
    ucrm_client_id    INTEGER NOT NULL,   -- UCRM client ID
    ucrm_service_type TEXT DEFAULT NULL,  -- fiber, starlink, etc.
    linked_by         INTEGER DEFAULT NULL,   -- Staff who created link
    linked_at         TEXT DEFAULT (datetime('now')),
    notes             TEXT DEFAULT NULL,
    UNIQUE(lte_subscriber_id, ucrm_client_id)
);
```

| Property | What it actually is |
|---|---|
| shape | **a separate link table**, not a column on the entity — and the comment says that was deliberate |
| cardinality | `UNIQUE` is on the **pair**, so the schema permits **many-to-many** |
| service reference | `ucrm_service_type` is a **label** (`fiber`, `starlink`), **not a uCRM service id** |
| provenance | `linked_by`, `linked_at`, `notes` — who, when, why |
| who links | a staff member; the column exists for exactly that |
| unlink | possible — ordinary row deletion; **no audit of removal** |
| uCRM user identity | `/current-user` via the session cookie (`docs/98` §2.7) |

> **C-4 — the precedent is not the same shape as either Model A or Model B.**
> `docs/99` framed both as a column on `mt_customers`. The one working example
> in this estate is a **link table with pair-uniqueness and provenance**, chosen
> over a column on purpose. That is recorded here as evidence, **not adopted**:
> a many-to-many customer↔client relationship would have consequences for RLS
> and for `/me` that nothing has examined.

---

## 6. The service identity gap

### 6.1 uCRM has stable service identifiers, and this estate already reads them

Endpoints exercised by the working plugins:

```
clients            clients/{id}          clients/services
clients/services/{id}                    clients/services?clientId={id}&limit=100
billing            billing/invoices      billing/invoices/{id}
billing/payments   billing/payments/{id} billing/quotes   billing/credit  billing/refunds
payment-methods    settings              quotes
```

Service fields read in production code: **`id`** (e.g. `331`), **`clientId`**,
**`servicePlanId`** (e.g. `12`). `CrmApiClient` supports `get`, `post`, `patch`,
`delete`.

**So a stable uCRM service identifier exists, is per-client, and is already
consumed in this repository.**

### 6.2 What Domain B loses today

```
uCRM                              Domain B
Client 42
 ├── Service 331  Starlink        (not represented)
 ├── Service 332  Fiber           (not represented)
 └── Service 333  MikroTik HotSpot ──?── mt_services(kind='mikrotik_hotspot')
                                          no uCRM reference of any kind
```

Measured consequences:

| | Today |
|---|---|
| which uCRM service the HotSpot corresponds to | **not representable** |
| a client with two separately-billed MikroTik sites | **not distinguishable** — both would be `kind='mikrotik_hotspot'` under one customer |
| suspending one uCRM service while another continues | **not representable** — `mt_services.status` is Domain B's own |
| billing a site to the right uCRM service | **impossible** |

> **C-5 — the service gap is larger than the customer gap.** A customer link
> can be added later to a table that already has the column. A service link has
> **no column at all**, and `mt_sites.service_id` already `RESTRICT`-references
> `mt_services`, so the shape of any service link decides how sites hang off it.
> **Not implemented here — U-5 remains open**, but it should be decided
> *together with* U-1 rather than after it.

---

## 7. The principal identity gap

### 7.1 The authorization path, measured end to end

```
POST /api/v1/auth/request-code  { phone }
  → mt_auth_issue_code(phone, code_hash, ttl)
      → SELECT id, customer_id FROM mt_principals WHERE phone = ? AND status='active'
POST /api/v1/auth/verify        { phone, code }
  → mt_auth_verify_code(phone, code_hash) -> (principal_id, customer_id)
  → mt_auth_create_session -> token
GET  /api/v1/me/...             Authorization: Bearer <token>
  → mt_auth_resolve_token(token) -> (principal_id, customer_id)
  → SET LOCAL app.customer_id
  → RLS: customer_id = mt_current_customer()
```

**No browser-supplied `customer_id` appears anywhere in that chain**, and there
is no `/customers/{id}` on the customer API. The requirement holds today and
must survive any model.

### 7.2 What `mt_principals` is — four candidates, none chosen

| | Reading | Against it | For it |
|---|---|---|---|
| **A** | a uCRM contact/person | uCRM contacts have no login; `phone` here is a credential key | name/phone/email overlap uCRM's contact fields |
| **B** | a DishNet application login identity | `email` unused, `credential_hash` dead — thin for a login record | it *is* what authenticates; `kind` (owner/operator) is an app-level role uCRM does not have |
| **C** | both | this is what it is **today**, and it is why the question exists | — |
| **D** | a separate concept — "who may act for this customer in Domain B" | needs a name and a lifecycle nobody has written | matches `kind`, matches `sold_by`/`created_by` attribution |

**Measured, it is C**: one table serving as login identity *and* contact record.
`docs/45` §2.1 separates the DishNet account from the HotSpot user but says
nothing about the principal.

**U-6 restated, with what P-2 added:** what is authoritative for the
authentication phone number, and what is the procedure when uCRM's differs?

---

## 8. Q5 — the operator question

Exactly one question, asked plainly, not interpreted:

> ### Q5
> **Does DishNet ever install, stage or ship a MikroTik router for a customer
> before that customer exists in uCRM?**
>
> - **A — Never.** The customer always exists in uCRM first.
> - **B — Yes.** Equipment may be staged or shipped before the uCRM customer
>   is created.
> - **C — Both**, depending on the workflow.

### ANSWERED — **C — BOTH**

> *Operator: "DishNet uses both workflows. Customer-first: the uCRM
> customer/service may exist before the MikroTik is staged or assigned.
> Equipment-first: DishNet may stage or ship a MikroTik before the final
> customer exists in uCRM."*

Recorded uninterpreted. **Q5 is CLOSED.** The consequences are designed in
`docs/101`; in short, equipment-first is a **device without a customer**, which
`mt_devices.customer_id` already permits, so C settles the *gate* question
(U-1) rather than the model question.

---

## 9. The open-decision matrix, reduced

`docs/98` and `docs/99` between them listed **13** items as needing operator
input. The repository answers five of them.

### 9.1 RESOLVED from the repository — no longer operator questions

| Was | Answer, measured |
|---|---|
| Q7 — which uCRM client fields exist? | `id, contacts, phone, firstName, lastName, companyName, name, email, status, note, accountBalance, currency, isLead, commission, stage, attributes, username` — read by working code |
| Q8a — are invoices in the API? | **Yes.** `billing/invoices` and `billing/invoices/{id}` are exercised |
| — is a uCRM **service** identifier available? | **Yes.** `clients/services?clientId=`, fields `id`, `clientId`, `servicePlanId` (§6.1) |
| — is webhook registration manual only? | **No** — `CrmApiClient` has `getWebhooks`, `createWebhook`, `updateWebhook`. **This refines `docs/98` §2.8**, which said webhooks are wired by hand in System → Webhooks; the UI is how it was done, not the only way |
| — can an unlinked customer be deleted during a Model-A migration? | **No** (§4.4), proved by execution |

### 9.2 Still OPERATOR INPUT REQUIRED — four

| # | Question | Blocks |
|---|---|---|
| **Q5** | customer-first, equipment-first, or both (§8) | **U-1 — the model choice** |
| **Q1** | exact installed UISP and uCRM version | everything version-dependent |
| **Q2** | may a disposable UISP/uCRM be stood up — licence and policy? | the entire test plan |
| **Q6** | must the network panel keep working during a uCRM upgrade? | U-1 |

### 9.3 Requires a PHYSICAL UISP/uCRM TEST — three

| # | Question | Why not answerable from here |
|---|---|---|
| **T1** | are client-zone plugin pages supported in the installed version? | no manifest in this repository uses one; only a running instance can say |
| **T2** | which webhook **events** does this version offer? | the six in use are the six wired, not the six available |
| **T3** | does `clients/services` return these fields on **this** installation? | field names are known from code; the live response shape is version-dependent |

### 9.4 Not established either way

**Support/tickets.** `tickets` appears 24 times in the codebase but **no
`tickets` endpoint appears among the uCRM paths called**. So `docs/98`'s
"Support comes from uCRM" is **unproven**: it may not be API-reachable at all.
That moves Support from "not built" to **"not known to be possible"**.

---

## 10. The decision tree

```
Q5 — can Domain-B state exist before the uCRM customer?

 ├── A · NEVER
 │     → uCRM customer is a genuine prerequisite
 │     → Model A is coherent: ucrm_client_id NOT NULL, created only from a uCRM client
 │     → still requires: the E-2 census, then link-or-keep for every existing row (C-3)
 │     → still open: what a deleted uCRM client leaves behind (docs/99 A-1)
 │
 ├── B · YES
 │     → a router may be staged against a customer uCRM does not have
 │     → Model A is NOT coherent without inventing a placeholder uCRM client
 │     → an explicit Domain-B entity plus a link is required
 │     → the link needs a lifecycle: unlinked → linked, and how long unlinked may last (U-8)
 │
 └── C · BOTH
       → the link is optional at creation and required at some later gate
       → that gate must be named: billing? activation? first voucher?
       → this is the sibling plugin's situation, and it chose a link table (§5)
```

### 10.1 Compatibility, per model

| Concern | Model A (projection) | Model B (entity + link) |
|---|---|---|
| router staging before sale | **breaks** unless a placeholder client is created in uCRM | works |
| customer PWA | unaffected — `/me` resolves from the token either way | unaffected |
| uCRM billing | direct: `ucrm_client_id` is always present | needs the link resolved; unlinked customers have no billing |
| support | unaffected — and §9.4 says it may not be reachable at all |
| vouchers | unaffected | unaffected |
| audit history | preserved; the projection keeps its uuid | preserved |
| customer deactivation | uCRM's status governs; Domain B needs a local flag anyway (`docs/99` A-1) | Domain B's own status governs |
| customer migration between uCRM clients | **hard** — `ucrm_client_id` is the identity | easy — re-point the link, with provenance |
| orphaned equipment | cannot exist by construction | exists, and needs a rule |

**Neither model is selected.**

---

## 11. Implementation consequences

### Model A

1. **E-2 census first.** Not optional; C-3 proves rows cannot simply be dropped.
2. For each unlinked row: find its uCRM client, or keep it and accept that
   `NOT NULL` cannot yet apply.
3. `mt_customer_create(p_name, p_created_by)` → gains the uCRM id; **the old
   signature is dropped**, as migration 020 dropped four.
4. A local "no longer backed by uCRM" flag is still needed (`docs/99` A-1).
5. Decide U-3: staff-initiated, or `client.add` webhook — the latter projects
   the entire uCRM client base, including clients with no network service.

### Model B

1. Two nullable columns (`ucrm_linked_by`, `ucrm_linked_at`) — no census
   required to *add* them.
2. **U-8 becomes load-bearing**: an unlinked customer is permitted, so a rule
   is needed for how long, and what is withheld meanwhile (billing certainly).
3. Conflict is not resolved but *displayed*: uCRM's name beside Domain B's.
4. §5 says the working precedent used a **link table**, not a column. If that
   shape is wanted, the many-to-many implications for RLS and `/me` must be
   examined first — nobody has.

### Both

- **U-5 should be decided with U-1, not after** (C-5): the service link has no
  column at all, and `mt_sites.service_id` already constrains the shape.
- **U-6 is an authentication decision, not a caching one** (P-2).
- **C-2**: whatever happens to principals, `sold_by` and `created_by` go `NULL`
  on delete and the history is gone silently.
- `credential_hash` is dead and can be dropped in either model (P-1) — a
  separate, trivial cleanup, not authorized here.

---

## 12. The next gate

> **Q5, from the operator.** One question, three possible answers, recorded
> uninterpreted.
>
> Then **Q1, Q2 and Q6**, and — for U-2 specifically — **E-2, the production
> census, which `docs/79` still governs.**

Not before that:

- no model chosen;
- no webhook, polling, automatic creation, automatic linking, backfill, or any
  customer or service synchronisation;
- no schema change, including the trivial `credential_hash` drop;
- no plugin installed anywhere.

---

**Suite unchanged: 1,599 assertions, 27 suites. Read-only throughout. No model
recommended, no gate moved.**

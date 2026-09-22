# 104 — The Domain-B onboarding spine

**Documentation and code inspection only. No implementation, no migration, no
uCRM sync, no production change.**

`docs/103` established that four of the five identity entities have no
production write path. This designs the **one coherent lifecycle** they belong
to, so they are built as a spine rather than as four independent writers.

Cardinality below is **read off the schema** — `pg_constraint`, `pg_indexes`,
`information_schema.columns` — never invented.

---

## 0. A defect found while measuring cardinality — **O-1**

Before the design, a finding that the spine must not inherit.

> **O-1 — a customer can attach their own site to ANOTHER customer's service.**
> Proved by execution, **as `dnb_app`, under RLS**.

```sql
-- mt_sites has two INDEPENDENT single-column foreign keys:
mt_sites_customer_id_fkey  FOREIGN KEY (customer_id) REFERENCES mt_customers(id)
mt_sites_service_id_fkey   FOREIGN KEY (service_id)  REFERENCES mt_services(id)
-- nothing requires site.customer_id = service.customer_id

BEGIN; SET LOCAL app.customer_id = '<tenant A>';
INSERT INTO mt_sites (customer_id, service_id, name)
     VALUES ('<tenant A>', '<a service owned by tenant B>', 'CROSS-TENANT');
-- INSERT 0 1
```

**RLS does not stop it.** RLS checks the row being written — whose
`customer_id` is correctly A's — while `service_id` points at a row A cannot
read.

### What it actually causes — measured, not assumed

| | Result |
|---|---|
| **Disclosure?** | **No.** With the cross-tenant site present, A's join resolves *2 of 3* sites to a readable service; the third is **dangling to A**. RLS still hides B's service row |
| **Integrity** | **Yes.** A holds a site referencing a service it cannot see |
| **Cross-tenant denial** | **Yes, confirmed.** `DELETE FROM mt_services` for B is refused — *"violates foreign key constraint `mt_sites_service_id_fkey`"*. **A can pin B's service so B can never end it** |

### Why this is exactly W-2's defect, one level up

W-2 closed the identical hole for devices:

```
mt_devices_site_customer_fkey  FOREIGN KEY (site_id, customer_id)
                               REFERENCES mt_sites (id, customer_id)
```

`mt_sites → mt_services` never received the same treatment. **The fix has the
same shape** — a `UNIQUE (id, customer_id)` on `mt_services` and a composite FK
from `mt_sites` — but it is **a schema change and is NOT authorized here.**

> Latent today because **no production writer creates a site** (`docs/103`) and
> no path deletes a service. **It stops being latent the moment the spine builds
> the site writer.** Recorded as a prerequisite in §15.

---

## 1. The identity model — cardinality measured

```
uCRM client ──1:1── mt_customers ──1:N── mt_principals
                          │
                          ├──1:N── mt_services ──1:N── mt_sites ──1:N── mt_devices
                          │                                   ▲              │
                          └──1:N── mt_devices ─────────────────┘ (site_id)   │
                                   (customer_id, both nullable)              │
                                                                             │
   mt_plans ──1:N── mt_vouchers ──N:1── mt_sites            mt_sessions ◄────┘
                                                            (NOT attributable
                                                             to a device)
```

| Relationship | Cardinality | Evidence |
|---|---|---|
| uCRM client → customer | **1:1** | `mt_customers_ucrm_client_id_key UNIQUE (ucrm_client_id)`, nullable |
| customer → principals | **1:N** | `customer_id NOT NULL`, no unique on it |
| customer → services | **1:N** | `customer_id NOT NULL`, no unique |
| service → sites | **1:N** | `mt_sites.service_id` **NOT NULL**, no unique |
| customer → sites | **1:N** | `mt_sites.customer_id` NOT NULL |
| site → devices | **1:N** | `mt_devices.site_id` **nullable** FK |
| customer → devices | **1:N** | `mt_devices.customer_id` **nullable** |
| phone → principal | **1:1 GLOBALLY** | `mt_principals_phone_uq UNIQUE (phone) WHERE phone IS NOT NULL` — **not scoped to customer** |
| customer → `radius_ref` | **1:1** | `mt_customers_radius_ref_uq`, `NOT NULL`, defaulted |
| device serial / `wg_pubkey` / `tunnel_ip` | **globally unique** | three UNIQUE indexes — device identity is estate-wide |

**Vocabularies, CHECK-constrained:**

| | |
|---|---|
| `mt_customers.status` | `active · suspended · closed` |
| `mt_principals.kind` | `owner · operator` |
| `mt_principals.status` | `active · disabled` |
| `mt_services.kind` | **`mikrotik_hotspot` — a single value** |
| `mt_services.status` | `active · suspended · ended` |
| `mt_devices.state` | `registered · staged · shipped · connected · provisioned · active · orphaned · diverged · decommissioned` |

> **A site cannot exist without a service** (`service_id NOT NULL`), but the
> schema permits **a service with no site, and a site with no device.**
> Measured in the simulated estate: 5 sites, **1 with no device**; 0 services
> with no site — so the site-without-hardware case is exercised and the
> service-without-site case is permitted but unexercised. The chain
> `customer → service → site` is mandatory; hardware is not.

---

## 2. Four onboarding events, deliberately not collapsed

| | Event | Requires | Measured |
|---|---|---|---|
| **A** | customer created | a name + an actor | `mt_customer_create(p_name, p_created_by)` — no uCRM, no service, no phone |
| **B** | authentication principal created | `customer_id`, `kind`, `display_name` | **no production writer** |
| **C** | network service created | `customer_id`, `kind` | **no production writer** |
| **D** | physical device assigned | device, customer, site | `mt_device_assign` — exists, no route bound |

They occur in **different orders**, and the schema permits both.

### Can customer → principal → service exist with no device?

**Yes — measured.** `mt_devices.customer_id` and `.site_id` are both nullable,
nothing references a device from `mt_sites` or `mt_vouchers`, and the estate
already contains a site with no router. A customer can be fully onboarded,
authenticate, define plans and sell vouchers **with no hardware in Domain B at
all.**

---

## 3. The minimum valid Domain-B customer

```
mt_customer_create(p_name text, p_created_by text)
  → INSERT INTO mt_customers (id, name) VALUES (uuid, btrim(p_name))
  → mt_audit_write(..., 'customer.created', ...)          [W-1, same transaction]
```

| Required | Not required |
|---|---|
| a non-blank **name** | uCRM link (`ucrm_client_id` nullable) |
| the **actor** who did it | a service · a site · a router · a phone · a voucher |
| | `radius_ref` — **defaulted**, `'c' || 10 hex chars`, NOT NULL and UNIQUE |

**The minimum valid principal** is `customer_id`, `kind`, `display_name`,
`status`. **`phone` is nullable** — so a principal can be created that **cannot
authenticate at all**, because the OTP path looks up
`mt_principals WHERE phone = ?`. That is a design question, not a bug (§4).

---

## 4. Principal lifecycle

```
customer exists
     ↓
principal created   (kind = owner | operator, display_name, phone?)
     ↓
OTP: mt_auth_issue_code(phone) → mt_auth_verify_code(phone, code)
     ↓                             → (principal_id, customer_id)
mt_auth_create_session → token → mt_auth_resolve_token → SET LOCAL app.customer_id
     ↓
the customer API, bounded by RLS
```

| Question | Measured answer | Open |
|---|---|---|
| owner vs operator | `kind` CHECK allows both; **only `owner` exists in the estate**; nothing in code branches on it | **what does `operator` mean?** |
| one customer → many principals | **permitted**; one in use | — |
| one principal → one customer | **enforced** (`customer_id NOT NULL`, single) | — |
| phone uniqueness scope | **GLOBAL**, not per customer | is that intended? a person acting for two customers cannot |
| principal creation | **no writer** | who may create one? |
| principal disable | `status='disabled'`; `mt_auth_issue_code` filters on `status='active'` | no writer |
| phone change | **no writer** — and it rotates the login (U-6) | procedure? |
| principal reassignment | `customer_id` is NOT NULL and could be updated; **`sold_by`/`created_by` would then misattribute history** | forbid, or re-point with audit? |
| uCRM contact relationship | **none, deliberately.** `docs/101` §11: copying uCRM contact data would silently rotate a credential | U-6 |

> **Creating a principal grants a login.** It is the only operation in Domain B
> that does, which puts it in a different risk class from creating a service or
> a site.

---

## 5. Service lifecycle

```
customer exists → service created → (site) → (device) → …
                       │
                  status: active → suspended → ended
```

| Question | Measured |
|---|---|
| what creates it | **nothing in production** — `Plugin/Simulator.php` only |
| `kind` | CHECK permits **exactly one value**, `mikrotik_hotspot` |
| requires a uCRM service link | **not today** — no column exists (U-5) |
| requires a site | **no** — sites reference services, not the reverse |
| requires a device | **no** |
| can exist without a device | **yes** |
| suspension | `status='suspended'` is in the CHECK; **no writer** (U-9) |
| cancellation | `status='ended'`, `ended_at`; **no writer** |
| migration between customers | `customer_id` NOT NULL; no path; would strand sites |

**uCRM has stable service identifiers** — `clients/services?clientId=` returning
`id`, `clientId`, `servicePlanId` (`docs/100`). **Do not implement the adapter
yet**; the spine only needs to leave room for `ucrm_service_id`.

---

## 6. Site lifecycle

| Question | Measured |
|---|---|
| belongs to customer directly, or through the service? | **both** — `customer_id NOT NULL` *and* `service_id NOT NULL`, and **nothing ties them together** (**O-1**) |
| requires a service | **yes**, `service_id NOT NULL` |
| can several services share a site | **no** — a site has exactly one `service_id` |
| can several routers belong to one site | **yes** — `mt_devices.site_id` is a plain FK |
| can a site exist without a router | **yes** — one such site exists in the estate |
| what creates it | **nothing in production** — simulator only |

> The site is where **Decision 2b** lands: a voucher is bound to its issuing
> site, and `mt_vouchers.site_id` is decided `NOT NULL` but **not migrated**
> (`docs/77` §1). The spine's site writer is therefore a prerequisite for a
> conforming voucher path.

---

## 7. Device lifecycle — unchanged

```
registered → staged → shipped → [ mt_device_assign ] → connected → provisioned → active
                                                    ↘ orphaned · diverged · decommissioned
customer_id NULL ───────────────┘                   customer_id set ─────────┘
```

**Preserved exactly.** `customer_id` stays NULL before assignment;
**`UNCLAIMED` remains a predicate, never a state**; **no fake customer is
created for staged equipment.** Unclaimed devices are already invisible to every
tenant — measured in `docs/101` §5.1.

---

## 8. Commercial lifecycle

```
plan created (price) ──┐
                       ├──► voucher batch ──► vouchers ──► [redemption] ──► sessions
site (2b) ─────────────┘                                    NOT BUILT
```

| Operation | Needs a device? | Needs a site? | Route |
|---|---|---|---|
| plan create / update / retire | **no** | no | `POST/PATCH /me/plans` — **live** |
| voucher batch + issuance | **no** | **yes** (2b) | `POST /me/vouchers` — **live, the only idempotent route** |
| voucher revoke | no | — | `POST …/revoke` — live |
| voucher redemption | no | yes | **wired to nothing** (`docs/86`) |
| session accounting | no | — | `dnb_radius` — live |

**Plans and vouchers being live is not evidence that onboarding works.** They
sit at the end of a chain whose first four links have no production writer.

---

## 9. Journey A — customer-first

| # | Step | Today | Actor | Authz | Audit | Idempotent | uCRM |
|---|---|---|---|---|---|---|---|
| 1 | uCRM client exists | external | staff | uCRM | uCRM | — | source |
| 2 | Domain-B customer created + linked | **fn, no caller; link NOT IMPL** | staff | `dnb_adminwrite` | **W-1 ✓** | no | link |
| 3 | principal created | **NOT IMPL** | staff | `dnb_adminwrite` | required | no | none |
| 4 | service created + linked | **NOT IMPL** | staff | `dnb_adminwrite` | required | no | service link |
| 5 | site created | **NOT IMPL** | staff | `dnb_adminwrite` | required | no | none |
| 6 | router staged | **built**, no route | staff | `dnb_adminwrite` | **W-1 ✓** | no | none |
| 7 | router assigned | **built**, no route | staff | `dnb_adminwrite` | **W-1 ✓** | no | customer link |
| 8 | provisioned | **built** (inert until F6-B) | staff + worker | `dnb_adminwrite` | **W-1 ✓** | lease | none |
| 9 | customer uses the PWA | **built** | customer | OTP → RLS | — | — | none |
| 10 | plans / vouchers | **built** | customer | RLS | skippable | vouchers only | per `docs/102` |
| 11 | accounting | **built** | RADIUS | one function | **none** | by session id | **FORBIDDEN** |

**Missing: steps 2 (caller + link), 3, 4, 5.**

## 10. Journey B — equipment-first

Steps 1–3 (register, stage, ship) are **built** and need **no customer**.
Steps 4–11 are Journey A steps 1–8 in the same order. **The only difference is
where the journey starts.** Nothing additional is missing.

---

## 11. Identity and order constraints

| Constraint | Source |
|---|---|
| a site requires a service | `service_id NOT NULL` |
| a voucher requires a site | Decision 2b (`docs/77`) — **decided, not migrated** |
| a device requires a customer **only at assignment** | `customer_id` nullable + `mt_devices_site_needs_customer` CHECK |
| `device.customer_id = site.customer_id` | **W-2 composite FK** |
| `site.customer_id = service.customer_id` | **NOT ENFORCED — O-1** |
| one phone, one principal, estate-wide | partial UNIQUE |
| a customer needs no uCRM link to exist | `ucrm_client_id` nullable |

---

## 12–13. Production paths, and uCRM dependency

Per `docs/103` §8 and unchanged here. Summary: **built** — devices (7 functions,
unbound), plans, vouchers, sessions, intents, uplink. **Missing** — customer
caller, principal, service, site, both links, suspension.

uCRM dependency per operation is the `docs/102` §8 matrix; **no gate is added
here.**

---

## 14. The first usable PWA customer

The minimum sequence that yields a customer who can actually log in and do
something, with nothing invalid in between:

```
1. mt_customer_create(name, actor)              EXISTS — needs a caller
2. principal_create(customer, owner, name,      MISSING
                    phone, actor)                 ← this is what grants the login
3. service_create(customer, 'mikrotik_hotspot', MISSING
                  actor)
4. site_create(customer, service, name, actor)  MISSING  ← must close O-1
   ────────────────────────────────────────────────────────────────
   at this point the customer can authenticate and see an empty estate
5. plan create                                   EXISTS (PWA)
6. voucher batch                                 EXISTS (PWA)
   ────────────────────────────────────────────────────────────────
   the customer can now sell access. NO ROUTER HAS BEEN INVOLVED.
7. mt_device_register / stage / ship             EXISTS, unbound
8. mt_device_assign(device, customer, site)      EXISTS, unbound
9. provisioning                                  EXISTS, inert until F6-B
```

> **Steps 1–6 produce a commercially active customer with no hardware.** That is
> not a gap in the design — it is what the schema permits and what `docs/102`
> §6.1 measured. It is also why the uCRM link cannot be gated on device
> assignment.

**Four writers, one spine: customer caller → principal → service → site.**
Building any one alone produces a customer that cannot log in, a service with
nowhere to put a site, or a site with no service.

---

## 15. Proposed implementation sequence — **not authorized**

| # | Step | Why first |
|---|---|---|
| **0** | **close O-1** — `UNIQUE (id, customer_id)` on `mt_services` + composite FK from `mt_sites`, mirroring W-2 | the site writer would otherwise inherit a cross-tenant defect **it is about to make reachable** |
| 1 | `mt_principal_create` / `_disable` — definer functions, `dnb_adminwrite`, W-1 audit | grants the login; highest risk; decides U-6's shape |
| 2 | `mt_service_create` — leaves room for `ucrm_service_id`, writes no link yet | the container everything hangs off |
| 3 | `mt_site_create` — **after** step 0 | unblocks Decision 2b's voucher path |
| 4 | a production caller for `mt_customer_create` | it already exists and is audited; only the route is missing (W-4) |
| 5 | **then** revisit U-1 with a spine that exists | gates belong on operations, and the operations now exist |

Steps 1–4 each follow the W-1 pattern exactly: `SECURITY DEFINER`, owned by a
definer role, `EXECUTE` to `dnb_adminwrite` only, audit row in the same
transaction, actor a parameter from the identity boundary.

---

## 16. Security, audit and idempotency requirements

| | |
|---|---|
| **authorization** | `dnb_adminwrite` only. **`dnb_app` gains nothing** — a customer must not create their own service, site or principal |
| **audit** | W-1 shape: inside the function, same transaction, unskippable. **Never gated on a uCRM link** |
| **actor** | a parameter from the identity boundary; `actor_kind = 'staff'`. **No new actor kind** |
| **idempotency** | today only `POST /me/vouchers` has a key. Creating a principal or a site twice on a retry is a real hazard — **each new writer needs a decision**, not a default |
| **RLS** | unchanged. Definer functions run under `SET LOCAL ROLE`; the tenant boundary stays `customer_id = mt_current_customer()` |
| **O-1** | must be closed **before** the site writer exists |
| **W-4** | binding any of these to an HTTP route is still **not authorized** |

---

## 17. Unresolved decisions

| # | Open | Waits on |
|---|---|---|
| **O-1** | composite FK `mt_sites → mt_services` | approval — **prerequisite for the site writer** |
| **U-1** | which operations require a uCRM customer link | the spine existing (§15 step 5) |
| **U-2** | `ucrm_client_id NOT NULL` | **E-2, the production census** |
| **U-5** | the service link | U-1 |
| **U-6** | authoritative source for the OTP phone; what a phone change does | approval |
| **U-7** | S-1 proxy or S-2 signed assertion | approval |
| **U-9** | does a uCRM suspension suspend the Domain-B service? | approval |
| **P-A** | what does `operator` mean, as distinct from `owner`? | **nothing in code branches on it** |
| **P-B** | is a globally unique phone right? one person cannot act for two customers | approval |
| **P-C** | may a principal be reassigned, given `sold_by`/`created_by` would misattribute? | approval |
| **S-A** | may a service be migrated between customers? | approval |
| **I-A** | idempotency for each new writer | approval |
| **C3** | may one uCRM client hold several Domain-B customers? | a real case |
| **B-2** | move plan/voucher writes behind definer functions | approval |

---

**Stopping here. Suite unchanged: 1,599 assertions, 27 suites. No code written,
no schema changed, no migration, nothing installed, no gate moved.**

# 101 — uCRM ↔ Domain-B identity lifecycle and link design

**Documentation only. No schema change, no migration, no backfill, no
synchronisation, no production change. Nothing here is implemented.**

---

## 1. Q5 — ANSWERED: **C — BOTH**

> DishNet uses both workflows:
> 1. **Customer-first** — the uCRM customer/service may exist before the
>    MikroTik is staged or assigned.
> 2. **Equipment-first** — DishNet may stage or ship a MikroTik before the
>    final customer exists in uCRM.
>
> *Operator, recorded uninterpreted. `docs/100` §8 closed.*

Neither *"the uCRM customer must exist first"* nor *"the Domain-B customer is
always independent"* is sufficient. An explicit link model with two supported
lifecycles is required.

---

## 2. Lifecycle A — customer-first

```
uCRM client              created in uCRM
      ↓
uCRM service             created in uCRM
      ↓
mt_customers             Domain-B customer, LINKED to the uCRM client
      ↓
mt_services              Domain-B service, LINKED to the uCRM service
      ↓
mt_sites                 site created under that service
      ↓
mt_devices               register → staged → shipped
      ↓                  mt_device_assign(device, customer, site, name)
   connected → provisioned → active
```

---

## 3. Lifecycle B — equipment-first

```
mt_devices               registered      customer_id NULL
      ↓                  staged          customer_id NULL   ← possession proven
      ↓                  shipped         customer_id NULL
      ↓
   UNCLAIMED             (a property, not a state — §5.2)
      ↓
uCRM client              created in uCRM when the sale closes
      ↓
uCRM service             created in uCRM
      ↓
mt_customers             created and LINKED
mt_services              created and LINKED
mt_sites                 site created
      ↓                  mt_device_assign(device, customer, site, name)
   connected → provisioned → active
```

> **The two lifecycles differ only in the order of the left and right columns.
> They converge at exactly one operation: `mt_device_assign`.** That is the
> single point where a device acquires a customer, in either path.

---

## 4. The equipment-first path is already built — measured

This is not a design proposal. It exists, is granted, and is exercised.

| Evidence | Measured |
|---|---|
| `mt_devices.customer_id` | nullable, commented **`-- NULL until assigned`** |
| `mt_device_register(p_serial, p_model, p_ros, p_wg_pubkey, p_tunnel_ip, p_staged_by)` | **takes no customer** |
| `mt_device_assign(p_device, p_customer, p_site, p_name)` | separate, later |
| states | `registered, staged, shipped, connected, provisioned, active, orphaned, diverged, decommissioned` |
| simulator | `SIM-MT-0004`, state `staged`, `customer_id` NULL, `site_id` NULL |
| `docs/35` §9 | records serial, model, RouterOS, WG public key, tunnel address, staged-at, staged-by — **no customer** |

**A router is identified by possession, not ownership.** No fake customer is
needed, and none may be invented to satisfy a foreign key.

---

## 4A. L-1 — **CLOSED**

> **A staged or shipped device does NOT require an intended customer to be
> recorded before the uCRM customer exists.** *(Operator.)*

Equipment-first is represented by:

```
device.customer_id = NULL      across REGISTERED → STAGED → SHIPPED
        ↓
mt_device_assign()             customer_id set
        ↓
customer / site relationship
```

**No fake customer. No pending customer. No provisional uCRM customer. No
customer inference from phone or email.**

A DishNet warehouse row is legitimate and complete as:

```
serial XXXXXXXX · model RB4011 · WireGuard identity XXXXX
state STAGED · customer_id NULL
```

*Inventory is not ownership.* `docs/35` §9 records possession — serial, model,
RouterOS version, public key, tunnel address, staged-at, staged-by — and no
owner. §5.1 measures that such a device is already invisible to every customer.

---

## 5. Device and customer stay separable — and the PWA is already safe

### 5.1 Unclaimed devices are invisible to customers — MEASURED

The requirement *"a staged/shipped/unclaimed router must not become visible to
arbitrary PWA users"* **is already enforced**, and not by convention:

```
ground truth (inspector):      5 devices, 1 unclaimed
as dnb_app, per tenant:
  SIM-CUST-001 Riverside Hotel   2 visible, 0 of them unclaimed
  SIM-CUST-002 Kabale Hostel     1 visible, 0 of them unclaimed
  SIM-CUST-003 Mbarara Lodge     1 visible, 0 of them unclaimed
with NO tenant context:          0 visible
```

2 + 1 + 1 = **4 = 5 − 1**. Every customer sees their own devices — so the test
is not vacuous — and **no customer sees the unclaimed one**.

The mechanism is the RLS policy `customer_id = mt_current_customer()`: for an
unclaimed device `NULL = <uuid>` evaluates to **NULL, not true**, so the row is
excluded. **Nothing needs building, and nothing may be weakened to accommodate
equipment-first.**

### 5.2 `UNCLAIMED` should NOT become a device state

`registered`, `staged` and `shipped` exist. "Unclaimed" is **not a fourth
state** — it is the predicate `customer_id IS NULL`, which holds *across* those
three and ceases at `mt_device_assign`.

Adding it to the `CHECK` would create **two sources of truth** that can
disagree: a row could be `state='unclaimed'` with a `customer_id`, or
`state='shipped'` with none. The condition is already expressible, already
enforced, and already measured. **Recommendation: express it as a predicate and
a UI label, not as an enum value.**

---

## 5C. U-1 — the proposed rule, and the identity split

> - A Domain-B **device** may exist with no customer.
> - A Domain-B **customer** may exist **temporarily** with no uCRM link.
> - **But before that customer becomes customer-facing or commercially active,
>   it must carry a valid uCRM customer link** — and a valid uCRM *service* link
>   wherever a service is involved.

That separates two identities that have been conflated:

```
        NETWORK INVENTORY IDENTITY          COMMERCIAL CUSTOMER IDENTITY
        (may stand alone)                   (requires the uCRM link)
        ──────────────────────────          ────────────────────────────
        device registered                   visible in the customer PWA
        device staged                       plans, and their prices
        device shipped                      vouchers issued and sold
        a customer row created              billing
        the audit of all the above          support
                                            service activation
```

**`mt_customers.ucrm_client_id` does NOT become `NOT NULL` globally.** That is
**U-2**, and it still waits on **E-2, the production census** — `docs/100`
proved by execution that an existing customer cannot simply be deleted, so the
migration is link-or-keep, one row at a time, by a person.

The rule is therefore enforced **at the operations that constitute commercial
activity**, not at row creation. Enforcing it at `mt_customer_create` would
block the legitimate internal inventory workflow L-1 just protected.

> **Which operations those are is measured, not assumed, in `docs/102`.**

**There is no single structural gate.** `mt_device_assign` looked like one —
setting `customer_id` is what makes a device visible through RLS — but it is
not sufficient, and the estate disproves it:

```
mt_sites    columns: id, customer_id, service_id, name, location, created_at
mt_vouchers references a device?  NO
live estate: sites with no device = 1
```

**Neither a site nor a voucher references a router.** So the chain
`service → site → plan → voucher` runs to a completed sale with no device ever
assigned, and an assignment-only gate would never fire for that customer.

> **The rule is therefore enforced at a measured SET of commercial operations,
> not at one moment.** `docs/102` enumerates every operation, states whether it
> exists at all, and classifies each as requiring only Domain-B identity, a
> customer link, or a customer *and* service link.

---

## 6. The customer link

### 6.1 Cardinality — analysed, and determined

Four questions, answered from the business relationship rather than from the
precedent's shape.

**Q — may one Domain-B customer link to several uCRM clients?**
**No.** `/me/billing` must resolve to exactly one payer. Two linked clients
would make "your invoices" ambiguous, and the resolution would have to be
invented at read time. There is no DishNet relationship that requires it.
→ **`UNIQUE (customer_id)` on the link.**

**Q — may one uCRM client link to several Domain-B customers?**
Arguable. A hotel group billed as one uCRM client, whose properties must be
isolated from each other in Domain B, would need it — because Domain B's
isolation boundary *is* the customer. But Domain B already represents several
premises as **sites under one customer**, so this is only needed when the
*staff at property A must not see property B*. That is **`docs/45` C3, still
open**, and no case has been produced.

**Determination: 1:1 — `UNIQUE` on both sides, for now.**

The reason is asymmetric cost, not preference:

| | |
|---|---|
| starting 1:1, relaxing later | **drop a `UNIQUE`** — non-breaking, no census, no data change |
| starting many-to-one, tightening later | **add a `UNIQUE`** — needs a census, and fails if duplicates exist |

**Start with the constraint that is cheap to remove.** If C3 later produces a
real case, relaxing is a one-line migration.

> **Do not adopt the sibling plugin's `UNIQUE(pair)` many-to-many** merely
> because it exists. Its own comment says a table was chosen for flexibility,
> not because many-to-many was required — and `lte_service_links` has no RLS to
> worry about, which Domain B does.

**Q — may a link be changed after routers, sites and vouchers exist?**
**Yes, and it must be** — a customer can be re-papered onto a new uCRM client
(company restructure, billing account replaced). Forbidding it would strand the
estate. But it is a **privileged, audited, reason-bearing** operation, never a
silent `UPDATE`.

### 6.2 Shape — columns, not a link table, *given* 1:1

| | Columns on `mt_customers` | A separate link table |
|---|---|---|
| RLS | **inherited** from a table that already has it | must be given `FORCE ROW LEVEL SECURITY` deliberately — the silent-no-op failure mode is recorded four times in this project |
| referential integrity | the existing FK graph is untouched | a new FK, plus a `customer_id` that must be kept consistent |
| holds >1 link | no — **which is what we determined we want** | yes — the capability we are declining |
| history | `mt_audit_log`, via `mt_audit_write()` | also `mt_audit_log` |

**Because history lives in the audit log under either shape, the link table's
only real advantage is multiplicity — and §6.1 determined against multiplicity.
So columns are the smaller claim.** If C3 later forces many-to-one, converting
columns to a link table is a contained migration.

### 6.3 Who may link — the part that must not be got wrong

> **`dnb_app` must never write a link.**

A customer-facing role able to set its own `ucrm_client_id` could point itself
at any uCRM client and read that client's billing through `/me/billing`. That is
a cross-tenant disclosure created by one `UPDATE`.

The link write follows the W-1 pattern exactly:

- a `SECURITY DEFINER` function owned by a definer role;
- `EXECUTE` granted **only** to `dnb_adminwrite` — never `dnb_app`, never
  `dnb_portal`;
- an audit row written **in the same transaction** via `mt_audit_write()`;
- the actor a **parameter from the Admin identity boundary** — for the bridge
  plugin, the uCRM staff member from `/current-user`, `actor_kind = 'staff'`
  (**no new actor kind**);
- **unlink and relink are audited events**, carrying the previous relationship,
  the new one and a reason. The precedent has no unlink audit; Domain B must not
  copy that.

---

## 7. The service link

Q5 = C makes this **more** important, not less: in the equipment-first path the
uCRM service is created last, so the moment of linking is explicit and has to be
somebody's job.

### 7.1 Identity — the id, never the name

uCRM exposes `clients/services?clientId={id}` returning **`id`**, **`clientId`**
and **`servicePlanId`**, already read by the working plugins.

> **`ucrm_service_type` / plan name must never be the identity.** The sibling
> plugin stores a label (`fiber`, `starlink`); a label cannot distinguish two
> HotSpot services for the same client, which is exactly the case Domain B
> cannot represent today.

### 7.2 Cardinality — 1:1

- **Several Domain-B services under one uCRM service?** Not needed — several
  premises are already several **sites** under one service
  (`mt_sites.service_id`).
- **Several uCRM services onto one Domain-B service?** That is split billing for
  one network service; ambiguous, and no case exists.

→ **`UNIQUE` on both sides.**

### 7.3 The coherence rule a foreign key cannot express

> **The uCRM service's `clientId` must equal the uCRM client linked to that
> Domain-B service's customer.**

Nothing in PostgreSQL can enforce this: the uCRM side is not in our database.
It must be **checked at link time by the linking function, against uCRM**, and
refused otherwise. Without it, a service belonging to client 42 could be linked
under a customer linked to client 77 — and `/me/billing` would cross tenants.

**This is the single most dangerous operation in the whole bridge.**

### 7.4 Lifecycle

| uCRM event | Domain B |
|---|---|
| service suspended | **no automatic action.** `mt_services.status` is Domain B's own; suspension policy is U-9, open |
| service cancelled | link marked broken; the Domain-B service and its history **survive** (§10) |
| service migrated to another client | requires unlink + relink, both audited, and the §7.3 check re-run |

---

## 8. PWA authorization — unchanged, and it must stay unchanged

```
authenticate (OTP)
  → mt_auth_verify_code(phone, code) -> (principal_id, customer_id)
  → mt_auth_create_session -> token
  → mt_auth_resolve_token(token) -> (principal_id, customer_id)
  → SET LOCAL app.customer_id
  → RLS: customer_id = mt_current_customer()
      → services → sites → routers → plans → vouchers → sessions
```

**The PWA cannot tell which lifecycle produced the relationship**, and must not
be able to. It sees only the final authorized estate. The surface stays:

`/me`, `/me/services`, `/me/sites`, `/me/plans`, `/me/vouchers`,
`/me/sessions`, `/me/usage`, `/me/uplink`, `/me/intents`
— plus `/me/billing` and `/me/support`, **neither of which exists**
(`docs/98` P-1; and `docs/100` §9.4 records that Support may not even be
API-reachable).

`/me/access-points` does not exist; routers are reached through sites.

---

## 9. Staff linking workflow, and the browser boundary

```
uCRM staff member, signed into UISP
   → bridge plugin page (same origin; /current-user verifies them SERVER-SIDE)
   → choose the uCRM client
   → choose or create the Domain-B customer          → LINK (audited)
   → choose the uCRM service
   → choose or create the Domain-B service           → LINK (audited, §7.3 check)
   → choose or create the site
   → assign a staged router                          → mt_device_assign (audited)
   → confirm
```

Every link operation records: **actor, uCRM client/service, Domain-B
customer/service, the previous relationship, the new relationship, timestamp,
and a reason where one applies.**

> **Browser JavaScript must never perform a privileged link.** The staff
> identity is trustworthy only on the server side of the plugin, where uCRM
> answered `/current-user`. This is **U-7**, still open: **S-1** the plugin
> proxies every call, or **S-2** it mints a short-lived signed assertion.

---

## 10. No phone matching — a rule, not a preference

> **A phone number must never determine a uCRM ↔ Domain-B customer link.**

`lib/LeadMatcher.php` matches on the last nine digits, and the plugin's own
manifest says beside the switch that loosens it that this *"can identify the
WRONG customer and disclose their balance."*

Phone remains what it already is — **the OTP authentication key**
(`mt_auth_issue_code` looks up `mt_principals WHERE phone = ?`, uniquely
indexed). It is **not** authority for a commercial link. Links are explicit,
made by a named person, and audited.

---

## 11. Customer, principal, contact — four things, kept apart

| Concept | Home | Is |
|---|---|---|
| uCRM client | uCRM | the billing subject |
| uCRM contact / staff | uCRM | people uCRM knows; **no login to Domain B** |
| `mt_customers` | Domain B | the **authorization boundary** — what RLS partitions by |
| `mt_principals` | Domain B | **who may act for that customer**, and the OTP login identity |

> **Do not copy uCRM contact data into `mt_principals` to keep them in step.**
> `mt_principals.phone` participates in authentication. Refreshing it from uCRM
> would **lock the customer out the moment uCRM's record is corrected** — a
> contact edit silently becoming a credential rotation.

**U-6, restated and still open:** what is authoritative for the login number,
and what is the procedure when uCRM's differs? Candidate answers — not chosen:
Domain B authoritative and uCRM advisory; a staff-confirmed change flow; or the
two kept deliberately independent with the difference displayed.

Also measured: `mt_principals.email` is unpopulated and `credential_hash` is
**dead** — no code reads or writes it.

---

## 12. Orphan and unlinked states

**Nothing here deletes Domain-B history because a uCRM relationship ended.**

| # | State | Expressible today? | Meaning |
|---|---|---|---|
| 1 | staged device, no customer | **yes** — `customer_id IS NULL` | normal, equipment-first |
| 2 | shipped device, no customer | **yes** | normal, awaiting the sale |
| 3 | Domain-B customer, no uCRM link | **yes** (this is I-1) | must become bounded — U-8 |
| 4 | uCRM client, no Domain-B customer | **yes**, trivially — most clients | normal; only network customers need one |
| 5 | uCRM service, no Domain-B service | **yes** | normal — Starlink and Fiber services |
| 6 | Domain-B service, no uCRM service | **yes** (nothing links today) | must become bounded |
| 7 | linked customer now inactive in uCRM | **no** — nothing records it | needs a local flag (`docs/99` A-1) |
| 8 | linked service cancelled in uCRM | **no** | needs a local flag |
| 9 | device decommissioned, customer remains | **yes** — `decommissioned` state | history preserved |
| 10 | device returned to stock | **partly** — `orphaned` exists; whether the link clears is undefined | needs a rule |
| 11 | customer re-papered onto a new uCRM client | **no** — no unlink/relink path | §6.1 says it must exist |

Rows 7, 8, 10 and 11 are the gaps. **None is fixed by deleting anything.**

---

## 13. System of record

| Owns | uCRM / UISP | Domain B |
|---|---|---|
| commercial customer, contacts, CRM status | **✓** | reference only |
| commercial service, plans, billing, invoices, payments | **✓** | reference only |
| support / tickets | **✓** *(API reachability unproven — `docs/100` §9.4)* | — |
| CRM staff identity | **✓** | consumed per request, never stored |
| network authorization boundary (`mt_customers`) | — | **✓** |
| network service, sites | — | **✓** |
| routers, WireGuard identity, lifecycle, provisioning | — | **✓** |
| HotSpot plans, vouchers, batches | — | **✓** |
| sessions, accounting, telemetry | — | **✓** |
| AAA credentials | — | **✓** (separate `radius` database) |
| network audit | — | **✓** |
| **the link itself** | — | **✓**, with provenance |

`mt_customers` is **not deleted and not demoted to a cache.** It is the
Domain-B-side identity that network ownership and authorization require, and it
carries an explicit uCRM relationship when one exists.

---

## 14. Schema changes that WOULD eventually be required — **DO NOT RUN**

Sketches for approval, not migrations.

```sql
-- customer link (S-A, columns; §6.2)
--   ucrm_client_id already EXISTS, nullable, UNIQUE
ALTER TABLE mt_customers ADD COLUMN ucrm_linked_by   text;
ALTER TABLE mt_customers ADD COLUMN ucrm_linked_at   timestamptz;
ALTER TABLE mt_customers ADD COLUMN ucrm_link_state  text
  CHECK (ucrm_link_state IN ('unlinked','linked','broken'));   -- rows 3, 7

-- service link (§7)
ALTER TABLE mt_services  ADD COLUMN ucrm_service_id  integer UNIQUE;
ALTER TABLE mt_services  ADD COLUMN ucrm_linked_by   text;
ALTER TABLE mt_services  ADD COLUMN ucrm_linked_at   timestamptz;
ALTER TABLE mt_services  ADD COLUMN ucrm_link_state  text
  CHECK (ucrm_link_state IN ('unlinked','linked','broken'));   -- rows 6, 8

-- four SECURITY DEFINER functions, EXECUTE to dnb_adminwrite ONLY (§6.3)
mt_customer_ucrm_link  (p_customer uuid, p_ucrm_client_id  integer, p_actor text, p_note text)
mt_customer_ucrm_unlink(p_customer uuid, p_reason text,              p_actor text)
mt_service_ucrm_link   (p_service  uuid, p_ucrm_service_id integer, p_actor text, p_note text)
mt_service_ucrm_unlink (p_service  uuid, p_reason text,              p_actor text)
--   each writes mt_audit_write() in the SAME transaction
--   mt_service_ucrm_link ALSO enforces §7.3 (clientId coherence) against uCRM

-- NOT proposed here:
--   ucrm_client_id NOT NULL      -> U-2, gated on E-2, the production census
--   dropping credential_hash     -> separate trivial cleanup
--   any device state change      -> §5.2 says UNCLAIMED must not become a state
```

---

## 15. Migration and backfill implications

1. **Adding the columns is safe** — all nullable, no existing row changes, no
   census needed. `ucrm_link_state` backfills to `'unlinked'`.
2. **`NOT NULL` is a different matter entirely.** `docs/100` proved by execution
   that an existing customer **cannot be deleted** — the transaction is refused
   and names `mt_principals_customer_id_fkey`. 27 foreign keys reach the three
   identity tables, and every used customer carries an audit row. **So
   "delete the unlinked rows" is not an available step:** every existing
   customer must be *linked*, one at a time, by a person.
3. **E-2 is still unmeasured.** `docs/100` censused the development databases.
   Production state is NOT ESTABLISHED; `docs/79` governs.
4. **No backfill may be automatic.** A link asserts that a Domain-B customer and
   a uCRM client are the same business. Nothing in the data proves that —
   §10 forbids inferring it from a phone number, and there is no other candidate
   key. Each link is a human judgement, recorded with its author.

---

## 16. Security implications

| | |
|---|---|
| **cross-tenant billing** | the worst case. A wrong customer link exposes another business's invoices through `/me/billing`. Mitigated by: `dnb_adminwrite`-only writes, the §7.3 coherence check, full audit, and no phone matching |
| **privilege** | `dnb_app` gets **no** new grant. `dnb_portal` gets none. The bridge plugin holds one API token and **never** a database credential |
| **RLS** | unchanged. Columns on existing tables inherit existing policies; §5.1 measured that unclaimed devices are already invisible |
| **staff identity** | trustworthy only server-side (U-7). Browser JavaScript must never link |
| **audit** | link, unlink and relink are all events, each carrying the previous and new relationship. `actor_kind = 'staff'` — **no new actor kind** |
| **authentication** | untouched. Copying uCRM contact data into `mt_principals` would silently rotate a credential (§11) |

---

## 17. Unresolved decisions

| # | Open | Waits on |
|---|---|---|
| **U-1** | the gate: must a customer be linked at creation (G1), at device assignment (G2), at first voucher (G3), or never enforced (G4/G5, the status quo)? | approval |
| **U-2** | `ucrm_client_id NOT NULL` | **E-2, the production census** |
| **U-6** | authoritative source for the OTP phone number | approval |
| **U-7** | S-1 proxy or S-2 signed assertion | approval |
| **U-8** | how long may a customer stay unlinked, and what is withheld meanwhile | U-1 |
| **U-9** | does a uCRM suspension suspend the Domain-B service? | approval |
| **C3** | may one uCRM client hold several Domain-B customers? (`docs/45`) | a real case; 1:1 until then |
| **L-1** | must an *intended* customer be recorded for a shipped router before uCRM has the client? | **operator** |

### Determined here, for approval

| | |
|---|---|
| customer link cardinality | **1:1**, both sides unique — cheap to relax, expensive to tighten (§6.1) |
| service link cardinality | **1:1**, both sides unique (§7.2) |
| link shape | **columns**, not a link table, *given* 1:1 (§6.2) |
| service identity | the uCRM **service id**, never a type or plan name (§7.1) |
| `UNCLAIMED` | a **predicate**, not a device state (§5.2) |
| write authority | `dnb_adminwrite` only, W-1 audit pattern (§6.3) |
| coherence | the service's `clientId` must match the customer's linked client (§7.3) |

---

**Stopping here for approval. Suite unchanged: 1,599 assertions, 27 suites. No
schema changed, no migration written, no link created, nothing installed.**

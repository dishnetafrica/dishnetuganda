# 72 — P-1 measured, and Q2 (router/site cardinality) investigated

**Status: EVIDENCE GATE. Nothing implemented, nothing chosen.**

No function modified, no constraint added, no table, role, privilege or
application code created, no production change. The provisioning writer is
**not** designed further here: docs/71 stands as-is and remains unapproved.

Two tasks: **A** — measure P-1 in a disposable control-plane database.
**B** — establish Q2 factually, without inferring it from the schema.

---

## Part A — P-1: `DOES NOT WORK / exploitable by authorized administrative path`

### A.1 What was run

`tools/audit/p1_run.sh` builds its own disposable database (`dnb_p1`, so
`tests/run.sh`'s `dnb_test` is untouched), migrates all 18 migrations, and runs
`tools/audit/p1_device_site_ownership.php`. Customers and sites are created
through `seed_two_customers()` — the same paths the application uses, because
since migration 017 there is no other way. The calling role is **`dnb_admin`**,
the role the grant matrix authorizes, connected as itself:
`current_user = dnb_admin`.

The invariant under test:

```
  mt_devices.customer_id  =  mt_sites.customer_id   (for the device's own site_id)
```

### A.2 Result

| Case | `mt_device_assign` call | invariant afterwards |
|---|---|---|
| **1 — matching** (`A.customer` + `A.site`) | **ACCEPTED** | **holds** |
| **2 — mismatched** (`A.customer` + **B's** site) | **ACCEPTED** | ***violated*** |

Case 2 left a device with `customer_id = 7cd2680f…` (customer **A**) whose
`site_id` points at a site whose `customer_id = 355827ac…` (customer **B**).
No error, no warning, no refusal.

### A.3 Nothing in the database prevents it

Every guard on `mt_devices`, enumerated from the catalogue rather than from the
migration text:

| Kind | What exists | Does it constrain site ownership? |
|---|---|---|
| CHECK | `mt_devices_state_check`, `mt_devices_wan_provenance`, `mt_devices_wan_shape` | **No** — state machine and WAN provenance only |
| FK | `customer_id → mt_customers(id)`; `site_id → mt_sites(id) ON DELETE SET NULL` | **No** — each column is validated *independently*. Existence, not ownership |
| Trigger | `mt_devices_no_delete`, `mt_devices_transition` | **No** — deletion ban and state transitions |
| RLS | `mt_devices_isolation` — `USING/CHECK (customer_id = mt_current_customer())`, to `PUBLIC` | **Not for this role.** `dnb_def_prov` also has `dnb_def_prov_mt_devices_update` with `USING (true) WITH CHECK (true)`, and PostgreSQL combines permissive policies with **OR** — so `true` wins |

The `dnb_def_prov` policies are `USING (true) WITH CHECK (true)` **deliberately**;
migration 017 comments it as *"provisioning and onboarding: stock belongs to
nobody until assigned."* That is a sound reason for the policy and is exactly why
**RLS cannot be the control here** — the role that performs assignment must be
able to see unassigned stock, so it is exempted from the tenant predicate, and
the ownership invariant is then enforced by nothing at all.

### A.4 The act leaves no audit row

`mt_audit_log` held **0 rows** after both calls.

*Recorded carefully, because the first attempt to check this was wrong.* Querying
`mt_audit_log` as the owner (`psql -U dnb`) returned nothing — but `mt_audit_log`
has `FORCE ROW LEVEL SECURITY`, so the owner is bound by the tenant predicate too,
and with no `app.customer_id` set it matches no rows. An empty result there is
**not** evidence of an empty table. Re-checked through the `inspector` connection
(`current_user = postgres`, `rolbypassrls = true`), the table is genuinely empty.

So `mt_device_assign` writes no audit record of its own. Audit for device acts
comes from the application layer (`src/Api/Routes.php`), which means a call made
directly against the function — which is what holding `dnb_admin` permits — is
**unattributable**.

### A.5 Why "admin-only" is not a mitigation

docs/71 §1.2 noted the path is `dnb_admin`-only and that `dnb_app` is refused
(asserted by `test_isolation_s1_s2.php:72`). **That bounds who can reach it; it
does not make the invariant enforced.** Three reasons it must not be read as
mitigation:

1. The provisioning writer's purpose is to turn `mt_devices.site_id` into an
   **AAA authorization boundary**. A boundary whose correctness rests on the
   caller being trusted is an assumption, not a control — the same category of
   finding as the three inert privilege statements this project already found.
2. The act is **unattributable** (§A.4), so a wrong assignment leaves no trace to
   detect or reverse it.
3. Under the projector design the error propagates into RADIUS **without anything
   touching RADIUS**, so no RADIUS-side control can see it.

### A.6 The concrete harm, as measured

The disposable database now holds exactly the state the projection would read:

| serial | tunnel_ip | site | site's owner | device's owner | |
|---|---|---|---|---|---|
| `P1-MATCH` | `10.90.0.1` | `ca36ec4b` | `7cd2680f` | `7cd2680f` | consistent |
| `P1-CROSS` | `10.90.0.2` | `4769d395` | `355827ac` | `7cd2680f` | **mismatch** |

A projector faithfully mirroring `site_id → tunnel_ip` would publish
`dnb_site_nas: site 4769d395 → 10.90.0.2`. Site `4769d395` belongs to customer
**B**, and the router at `10.90.0.2` is recorded as customer **A**'s. So **B's
site-bound voucher credentials would authenticate on a router held by A** — and
C-b would be behaving perfectly, authorizing exactly what the mapping said. This
is P-2's lesson in a different guise: the mechanism is only as good as the data
invariant behind it.

### A.7 Remediation requirement — documented, not implemented

**Requirement.** The invariant `device.customer_id = site.customer_id` must be
enforced at the authoritative layer — in the database, where no caller can bypass
it — not in `mt_device_assign`'s body alone and not by trusting `dnb_admin`.

Candidate mechanisms, **none chosen**:

| | Mechanism | Note |
|---|---|---|
| **R1** | **Composite foreign key** — `UNIQUE (id, customer_id)` on `mt_sites`, then `FOREIGN KEY (site_id, customer_id) REFERENCES mt_sites (id, customer_id)` on `mt_devices` | Declarative and unbypassable, the standard relational answer. **Caveat:** default `MATCH SIMPLE` treats a row with *any* NULL in the referencing columns as satisfying the constraint, so a device with a `site_id` but a NULL `customer_id` would still pass. Whether that hole matters depends on whether that state is reachable — not established |
| **R2** | A trigger on `mt_devices` asserting the pair | Covers the NULL case R1 leaves, at the cost of being procedural |
| **R3** | A check inside `mt_device_assign` | **Insufficient alone** — it protects one caller, not the table. Acceptable only as an early, clearer error on top of R1 or R2 |

This is a **control-plane change** to a `dnb_def_prov` function and/or the
`mt_devices` schema. It is **not** part of Decision 7 and needs its own
authorization. It also needs a guard test: the measurement in §A.2 case 2,
inverted into an assertion that the call is refused.

---

## Part B — Q2: can one physical MikroTik serve multiple sites?

### B.1 Result: `NOT ESTABLISHED`

The repository does not answer it. Nothing found states, implies as intent, or
argues against, one physical MikroTik HotSpot/RADIUS NAS serving more than one
DishNet site.

### B.2 What was searched, and what it says

| Source | Finding | Bearing |
|---|---|---|
| **docs/50 §4** (pricing options) | *"B Per site — DishNet sells: **Each location**"*; *"Where the limit bites: Adding a site"* | The nearest thing to a definition: **a site is a location.** Says nothing about how many gateways a location has, or whether two businesses at one location are one site or two |
| **docs/54** (C20 instrument) | Asks *"Do you have more than one location? How many?"* and records *"Locations (count)"*. Asks for the **exact MikroTik model and RouterOS version** — singular, of the respondent's own box | Counts locations and assesses one router. **Never asks whether one router covers several locations or businesses.** As written it cannot answer Q2, just as §2.6b found it cannot answer the converse |
| **docs/32 §A8** (push order) | `WAN → bridge → DHCP → DNS → pool → HotSpot → RADIUS client → walled garden` | A setup sequence with one HotSpot step. Design *shape*, not a cardinality statement |
| **docs/30, 31, 33, 42** | Reseller/operator language throughout, always about *a* router being provisioned, staged, paired | No shared-gateway scenario anywhere |
| Search for `VLAN`, `shared router/gateway/box`, `same router`, `two businesses`, `multi-tenant router`, `sub-site` | **No hits** in any architecture or product document | Nobody has written about this case at all |

### B.3 Two facts that are *not* product intent, recorded separately

Per the instruction not to turn technical possibility into product intent, these
are kept out of §B.1's answer:

1. **`mt_devices.site_id` is a single scalar column**, so the current
   implementation already permits only one site per device. This is an
   implementation commitment made before the question was asked — **not**
   evidence of intent. Its real bearing is on cost: if Q2's answer is **YES**,
   the change is *not* confined to `dnb_site_nas`; the control plane would need a
   device↔site relation, which is a materially larger change touching frozen
   territory.
2. **`RouterOsDelivery::assertRadiusBacked()` iterates over multiple HotSpot
   profiles** and accepts if *any* is RADIUS-backed. The code therefore
   *tolerates* several HotSpot servers on one router — technical tolerance only.
   Worth noting because if a router ever did serve two sites, that check would
   pass while establishing nothing about either site's configuration.
3. `max_routers`, `max_sites` and `max_operators` are **separate** entitlement
   keys, so routers and sites are counted independently. Consistent with either
   cardinality; evidence for neither.

### B.4 What would answer it

A single business question, of the same kind as C20's, which the C20 instrument
does **not** currently contain:

> Will DishNet ever install **one** MikroTik HotSpot gateway that serves **two or
> more separately-billed DishNet sites** — for example two unrelated businesses
> sharing one building's connection, or an ISP customer putting several downstream
> businesses behind a single box?

It is a product/topology decision for the operator, and cannot be derived from
any document in this repository.

---

## 3. Impact on the provisioning-writer design

| | Impact |
|---|---|
| **The projector decision (docs/71 §2) survives** | P-1 does not argue against projecting; it confirms the design's own stated cost — that it *concentrates* the boundary on `mt_devices.site_id`. The measurement turns that from a predicted weakness into a demonstrated one |
| **P-1 is promoted from precondition to blocking defect** | docs/71 §10 listed it as precondition 1 on the strength of a code reading. It is now measured. Until R1/R2 is in place, the projection would faithfully export a broken invariant |
| **The writer needs no new authority to fix this** | The remediation is entirely upstream. Nothing in §A.7 changes the privilege model in docs/71 §6 |
| **An audit gap is now measured, not assumed** | docs/71 §4.11 proposed two-sided audit because the `radius` database has none. §A.4 shows the **control-plane** side is also absent for this act. Both sides need it |
| **The design cannot be finalised** | The key/cardinality question (§4) is unresolved, so docs/71's schema section stays open |

## 4. Impact on the `dnb_site_nas` key and cardinality

**No schema is chosen, because Q2 is NOT ESTABLISHED.** Both branches, stated so
the decision is a one-step choice once the fact arrives:

| | If Q2 = **NO** (one router serves one site) | If Q2 = **YES** (a router may serve several) |
|---|---|---|
| **Key** | `PRIMARY KEY (nas_ip)` | `PRIMARY KEY (site_id, nas_ip)` — as docs/70 recorded |
| **P-2 (shared NAS)** | **Structurally impossible.** The schema cannot represent it | **Remains possible and becomes legitimate**, so it must be distinguished from the accidental case by something other than the key |
| **Reassignment atomicity** | One `UPDATE`, inherent (docs/70 S3) | Needs an explicit delete+insert or a transaction, and the stale-removal guarantee returns to resting on the writer behaving correctly |
| **Control-plane change** | None — `mt_devices.site_id` already matches | **A device↔site relation is required**, a materially larger change (§B.3.1) |
| **Cross-customer risk** | Confined to P-1 | A shared NAS is, by construction, a surface where two customers' credentials authenticate at one router — needing its own decision, not just a key |

**Recommendation deferred deliberately.** docs/71 §2.1 proposed `PRIMARY KEY
(nas_ip)`; that proposal is **conditional on Q2 = NO** and is not advanced here.

## 5. Impact on P-2 and T10

**P-2 (one NAS in two sites/customers).** Its status is now precisely: *the
measured behaviour was correct for the data given; the data invariant was
insufficient.* Whether that insufficiency is a **defect to close** or a
**capability to support** is exactly Q2 — so P-2 cannot be resolved before Q2 is
answered. Under Q2 = NO it closes by construction; under Q2 = YES it becomes a
first-class authorization question.

**T10 (reused `tunnel_ip` inherits stale authorization).** Q2 does not affect it
and P-1 makes it worse. Two independent routes now exist to a router authorized
for the wrong site: a reused address (T10) and a cross-customer assignment (P-1).
T10's remedy is unchanged and unconditional — **detach before reissue**, plus
invariant N9 — and it does not wait on Q2.

## 6. Invariants the final writer must enforce

Restating docs/71 §7 with what this gate changed. **Measured** = observed, here or
in docs/70 §2.

| | Invariant | State |
|---|---|---|
| **N1** | A credential never authenticates against a NAS outside its authorized site | **Measured** (S1) |
| **N2** | A reassignment leaves no stale authorization; the old binding ends in the statement that creates the new one | **Measured** (S3) — *conditional on Q2 = NO for the single-statement form* |
| **N3** | `dnb_site_nas` is a projection; it never holds a mapping the control plane does not hold, and drift is detectable | design intent |
| **N4** | A NAS belongs to at most one site at any instant | **BLOCKED on Q2** |
| **N5** | Voucher activation cannot create, move, widen or delete any `dnb_site_nas` row | design intent |
| **N6** | The provisioning writer can neither read nor write any AAA credential, and never reads `nas.secret` | design intent |
| **N7** | Absent, partial or inconsistent mapping denies access, never grants it | **Measured** (S4, S5, S8, S9) |
| **N8** | Every mapping change is attributable to an actor, on both sides | design intent — **and now known to be absent on the control-plane side too** (§A.4) |
| **N9** | A tunnel IP is not reissued while any `dnb_site_nas` row names it | design intent |
| **N10** | **NEW — `mt_devices.customer_id = mt_sites.customer_id` for a device's own site, enforced in the database.** The projected authorization boundary is invalid without it | **MEASURED ABSENT** (§A.2 case 2). Remediation §A.7 |

N10 is the gate's main product. It is stated as an invariant of the *control
plane*, not of the writer, because that is where it must be enforced — the writer
cannot validate what it is merely copying.

---

## 7. State after this gate

**Unchanged:** F1–F13 frozen. Decisions 1, 2a, 2b, 3 and 7 as recorded. **F6 NOT
AUTHORIZED.** Production FreeRADIUS and production database untouched. docs/71
remains a design awaiting approval.

**Blocked, in order:**

1. **N10 / P-1 remediation** — requirement in §A.7, mechanism not chosen, needs
   its own authorization. Blocking.
2. **Q2** — `NOT ESTABLISHED`; the question that would settle it is §B.4. Blocks
   the `dnb_site_nas` key, N4, and P-2's classification.

Neither is answerable from this repository, and no schema should be chosen until
Q2 is answered.

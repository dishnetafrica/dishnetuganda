# 71 — Decision 7 extension: the provisioning writer for `dnb_site_nas`

**Status: DESIGN AND EVIDENCE GATE. Nothing here is approved and nothing is built.**

No table, role, privilege, function, production SQL, FreeRADIUS change or
application code has been created. §10 states what would require approval.

Scope: the security boundary for the writer that maintains the site → authorized
NAS mapping that **Decision 2a** (docs/70 §8) selected. Decision 7 (docs/66 §2)
scoped exactly one writer into the `radius` database — the AAA Publisher, on the
*publication* lifecycle. `dnb_site_nas` sits on the *device-provisioning*
lifecycle, which that boundary does not cover. This document proposes the
extension.

**The invariant being preserved throughout:**

> A voucher credential must never authenticate against a NAS outside its
> authorized site, and a site/router reassignment must not leave stale
> authorization behind.

---

## 1. Evidence from the existing system

Read-only audit of the control plane. No writes, no execution of any function.

### 1.1 House practice is already established and consistent

Every `SECURITY DEFINER` function in `migrations/` — **24 of them** — pins
`SET search_path = public, pg_temp`. Nothing to invent; the extension follows it.

*(A first pass comparing raw `grep -c` counts of `SECURITY DEFINER` against
`SET search_path` suggested four files had unpinned functions. Parsing each
function body with comments stripped showed **zero** unpinned. The count
mismatch was prose in comments. The parsed result is the one recorded.)*

**The ownership model** (`017_definer_roles.sql`): four `NOLOGIN` owner roles —
`dnb_def_auth`, `dnb_def_net`, `dnb_def_work`, `dnb_def_prov` — created
`NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS`, members of nothing,
so none can reach another's privileges. Functions are owned by one of them;
the login roles (`dnb_app`, `dnb_worker`, `dnb_admin`, `dnb_radius`) hold only
`EXECUTE`. `CREATE ON SCHEMA public` is granted transiently and revoked at the
end, with a test asserting none of those roles can create anything afterwards.

**`PUBLIC EXECUTE` is handled explicitly**, and the migration comments show it
was got wrong once: `mt_revoke_public_execute()` sweeps `mt_*` functions, and
deliberately runs **two** loops, because `proacl IS NULL` means *"never touched,
carries the built-in default — PUBLIC included"*, which the ACL-explode loop
cannot see. Every grant in the matrix is `REVOKE ALL ... FROM PUBLIC` followed by
`GRANT EXECUTE ... TO <role>`, executed under `SET LOCAL ROLE <owner>`.

**Tenant tables use `FORCE ROW LEVEL SECURITY`**, so the owner is bound too.

**`mt_audit_log`** (`004_audit.sql`) exists in the control plane:
`customer_id, actor, actor_kind ∈ {principal,staff,system}, action, target_type,
target_id, source, detail jsonb, at`.

**Idempotency** is done with a partial unique index —
`mt_intents (customer_id, idempotency_key) WHERE idempotency_key IS NOT NULL`.

### 1.2 Finding P-1 — `mt_device_assign` does not check that the site belongs to the customer

```sql
CREATE OR REPLACE FUNCTION mt_device_assign(
  p_device uuid, p_customer uuid, p_site uuid, p_name text
) RETURNS mt_devices
LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE r mt_devices;
BEGIN
  UPDATE mt_devices SET customer_id = p_customer, site_id = p_site,
                        name = p_name, claimed_at = now()
   WHERE id = p_device RETURNING * INTO r;
  ...
```

`p_customer` and `p_site` are caller-supplied and **independent**. Nothing
verifies that `p_site`'s `customer_id` equals `p_customer`. The foreign key
`site_id uuid REFERENCES mt_sites(id)` guarantees the site **exists**, not that
it belongs to anyone in particular, and there is no `CHECK`, trigger or
constraint tying the two (searched; none).

**Bounding it honestly.** This is **not** reachable by the request role:
`test_isolation_s1_s2.php:72` asserts `dnb_app` calling `mt_device_assign` gets
`permission denied`. The grant matrix gives it to **`dnb_admin` only**, through
a `dnb_def_prov`-owned function. So the exposure is a mistaken or compromised
**administrative** path, not a customer-facing one.

**Why it matters here.** Under the design in §2 the AAA authorization boundary is
a projection of `mt_devices.site_id`. A device assigned to another customer's
site is then a NAS authorized for that customer's site — the cross-customer
outcome the Decision 2a floor exists to prevent, arriving through a path that
never touches RADIUS. **The AAA boundary is only as strong as this function.**

No existing test supplies a mismatched customer/site pair —
`test_definer_roles.php:110` passes `$A['customer'], $A['site']`, which match.
**The behaviour above is read from the code; it has not been executed** (this
gate creates nothing and runs nothing). §9 Q1 names the measurement that would
confirm it.

### 1.3 Finding P-2 — a correction to docs/70: the composite key permits one NAS in two sites

docs/70 §2 recorded `dnb_site_nas` with `PRIMARY KEY (site_id, nas_ip)` and read
case **S2** as *"a site gains a second router."* It was that. It was **also**
something I did not report.

S2's baseline was `site-a={127.0.0.1}`, `site-b={127.0.0.2}`. S2 inserted
`('site-a','127.0.0.2')`, giving `site-a={127.0.0.1, 127.0.0.2}` and
`site-b={127.0.0.2}` — **`127.0.0.2` in both sites at once**, belonging to two
different customers. The measured result was `U1@B = Access-Accept` **and**
`U2@B = Access-Accept`: two customers' credentials authenticating at the same
router.

So S2 measured multi-NAS-per-site **and**, unremarked, that a composite key lets
the schema hold a NAS shared between two customers' sites, with both authorized.
The predicate behaved correctly — it authorized exactly what the table said. The
table was permitted to say something that should probably never be true.

This does not weaken Decision 2a: the mechanism did what it was asked. It does
change what the mapping's **integrity** rests on — see §2.2 and §9 Q2.

### 1.4 Finding P-3 — two silent paths to a stale mapping

- **`mt_devices.site_id uuid REFERENCES mt_sites(id) ON DELETE SET NULL`.**
  Deleting a site silently blanks `site_id` on its devices. Under a projection
  that reacts to changes it is told about, nothing is told.
- **`tunnel_ip text UNIQUE`** is unique, not immutable, and `mt_devices` has a
  `decommissioned` state. A tunnel IP freed by a decommissioned device and later
  issued to a different customer's router would, with a stale `dnb_site_nas`
  row, authorize the **new** router for the **old** site. This is the
  reassignment invariant in its worst form, because nothing in RADIUS can detect
  it — the address is the same.

---

## 2. The central design decision: a projector, not an author

**Proposal: the provisioning writer holds no policy authority. It may only make
`dnb_site_nas` agree with control-plane state. It can copy a mapping; it cannot
invent one.**

The control plane already decides which device belongs to which site
(`mt_devices.site_id`) and which address it answers on (`mt_devices.tunnel_ip`,
DishNet-assigned and server-derived). `dnb_site_nas` is that relationship,
projected into the database where the authorize query runs — the two live in
**separate PostgreSQL instances** with no connection between them, so a view or
foreign key is not available and a process must carry it.

This is chosen over an authoring writer (one that takes `(site, nas_ip)` from an
operator) for three reasons:

1. **It removes a whole class of threat by construction.** A writer that cannot
   express a mapping the control plane does not hold cannot assign a NAS to an
   arbitrary customer's site, however it is called.
2. **It makes reconciliation meaningful** — Decision 7's principle (docs/66 §2).
   A projection has a defined correct value at all times, so drift is detectable
   and repairable. An authored table has no independent truth to check against.
3. **It puts the security question where it belongs** — on `mt_devices.site_id`,
   and therefore on P-1, rather than spreading it across two systems.

The cost is stated plainly: **it concentrates the boundary on P-1.** That is why
§10 makes closing P-1 a *precondition*, not a follow-up.

### 2.1 The proposed primitive is keyed on the NAS, not on the pair

```
  nas_set_site(nas_ip, site_id)   -- "this NAS now belongs to this site"
  nas_detach(nas_ip)              -- "this NAS belongs to no site"
```

Not `add(site, nas)` / `remove(site, nas)`. Keying the operation on the NAS makes
four requirements fall out of the shape instead of resting on the caller:

- **atomic reassignment** — one upsert, no delete-then-insert window
- **idempotency** — re-applying the same pair is a no-op, by definition
- **stale removal** — the old row *is* the row being overwritten; there is no
  second step to forget
- **no shared NAS** — if `nas_ip` is the **PRIMARY KEY**, P-1.3's two-sites-at-once
  state is unrepresentable, enforced by the schema rather than by the writer
  behaving well

**This changes the schema shape docs/70 recorded** (`PRIMARY KEY (site_id,
nas_ip)` → `PRIMARY KEY (nas_ip)`), and it is **not** a change I can make on my
own: it depends on a factual question nobody has answered — §9 Q2. The measured
behaviour of the C-b predicate is unaffected either way; `EXISTS` reads the same
rows.

### 2.2 What the mapping's integrity now rests on

Under site-bound policy, `mt_sites.customer_id` is `NOT NULL`, so a site belongs
to exactly one customer and **cross-customer isolation is a consequence of the
site mapping being right** (docs/70 §4 fact 8). Combined with §2, that means the
entire AAA authorization boundary reduces to two things:

1. `mt_devices.site_id` being correct — **P-1**
2. the projection being faithful and current — **P-3**, §3

Nothing else stands between a voucher credential and a router.

---

## 3. The lifecycle, modelled

| # | Event | Control plane | Projection | `dnb_site_nas` |
|---|---|---|---|---|
| 1 | **router registered** | `mt_device_register` → row, `state='registered'`, no `site_id`, no `tunnel_ip` | nothing | no row |
| 2 | **tunnel assigned** | `tunnel_ip` set (DishNet-assigned) | nothing — a NAS with no site authorizes nothing | no row |
| 3 | **assigned to site** | `mt_device_assign(device, customer, site, name)` — **P-1 applies here** | `nas_set_site(tunnel_ip, site)` | row created |
| 4 | **NAS authorized for site** | — | — | credentials for that site now authenticate there (S1) |
| 5 | **router reassigned to another site** | `mt_device_assign` with a new `site_id` | `nas_set_site(tunnel_ip, new_site)` | **row updated in place**; old site's authorization ends and new site's begins in the same statement (S3) |
| 6 | **site deleted** | `ON DELETE SET NULL` blanks `site_id` **silently** — **P-3** | must fire `nas_detach` | row removed — *only if something notices* |
| 7 | **router decommissioned** | `state='decommissioned'` | must fire `nas_detach` **before** the tunnel IP can be reissued — **P-3** | row removed |
| 8 | **voucher activated** | publisher writes `radcheck` + `dnb_cred_site` | **no effect on `dnb_site_nas`** — requirement 16 | unchanged |

Events 6 and 7 are the ones with no current trigger. A projection that only
reacts to explicit calls will miss both, which is why §4 item 9 proposes a
**reconciler** as part of the design rather than an operational afterthought.

---

## 4. The seventeen requirements

| # | Requirement | Proposal |
|---|---|---|
| 1 | **Writer identity** | Two identities, one process. Control plane: **`dnb_netprov`** (LOGIN) — reads the projection source. `radius` DB: **`dnb_nas`** (LOGIN) — applies it. Neither is `dnb_pub`, `dnb_publisher`, `dnb_radius`, `dnb_admin` or `dnb_app`; neither is a member of any of them |
| 2 | **Tables/functions it may access** | **No table privileges in either database.** Control plane: `EXECUTE` on one read-only definer function returning `(device_id, site_id, customer_id, tunnel_ip, state)` for devices whose projection may have changed — nothing else, and **not** `mt_device_secrets`, `mt_device_config`, `mt_vouchers`, `mt_hotspot_users`, `mt_customers`. `radius` DB: `EXECUTE` on `nas_set_site` and `nas_detach` only — **not** `radcheck`, `radreply`, `radacct`, `radpostauth`, `nas` (which holds `nas.secret`), or `dnb_cred_site` |
| 3 | **Rows it may create/update/delete** | Exactly one row of `dnb_site_nas` per call, identified by `nas_ip`. It cannot write `dnb_cred_site`, so it cannot move a *credential* between sites |
| 4 | **Customer/site ownership enforcement** | By construction (§2): the writer copies `mt_devices.site_id`; it cannot name a site the control plane does not already hold for that device. **Conditional on P-1 being closed** — otherwise the control plane itself may hold a cross-customer pairing |
| 5 | **Prevent cross-customer NAS assignment** | Same mechanism as 4, plus §2.1's `nas_ip` primary key removing the shared-NAS state (P-1.3). **Both conditional** — 4 on P-1, 5 on Q2 |
| 6 | **Prevent unauthorized site reassignment** | The writer has no site-reassignment authority of its own: reassignment happens in the control plane and is *observed*. The control-plane path is `mt_device_assign`, `dnb_admin`-only, audited |
| 7 | **Atomic add/remove/reassign** | One upsert keyed on `nas_ip` (§2.1). No delete-then-insert window. Measured for the mapping change itself: S3 |
| 8 | **Idempotency** | Structural: re-applying `(nas_ip, site_id)` is a no-op. The cross-database step is at-least-once, which an idempotent target makes safe — same reasoning as `mt_intents`' partial unique index |
| 9 | **Stale mapping cleanup** | `nas_detach` on decommission and on site deletion (lifecycle 6, 7), **plus a reconciler** that compares the projection against control-plane truth and repairs drift. Required, not optional — P-3 shows two paths that fire no call at all |
| 10 | **Failure/rollback** | Two databases, **no distributed transaction** — this cannot be made atomic end to end. Each side is atomic alone; the join is at-least-once delivery onto an idempotent, convergent target. A half-applied projection is a **stale** mapping, and §4.17 makes stale fail closed rather than fail open |
| 11 | **Auditability** | Two-sided. Control plane: `mt_audit_log` already exists — record the provisioning act with its actor. `radius` DB: **no audit facility exists there**; propose an append-only `dnb_nas_audit(nas_ip, site_id_old, site_id_new, at, actor, reason)` written *inside* the definer function, so it cannot be bypassed by the caller |
| 12 | **SECURITY DEFINER boundary** | `nas_set_site` / `nas_detach` owned by a **NOLOGIN** owner role in the `radius` database (the house pattern: `NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS`, member of nothing), **not** by the `radius` superuser-adjacent role and **not** by the publisher's owner |
| 13 | **Pinned `search_path`** | `SET search_path = public, pg_temp` on every function, matching all 24 existing definer functions |
| 14 | **`PUBLIC EXECUTE`** | `REVOKE ALL ON FUNCTION ... FROM PUBLIC` then `GRANT EXECUTE ... TO dnb_nas`, under `SET LOCAL ROLE <owner>`. **The `radius` database has no `mt_revoke_public_execute()` equivalent**, and `proacl IS NULL` hides an untouched function from an ACL sweep (§1.1) — so the revoke must be explicit per function, with a guard test asserting `PUBLIC` holds nothing |
| 15 | **Separation from the AAA publisher** | Different login roles, different owner roles, disjoint function sets, no membership either way. `dnb_pub` gains **no** new privilege; `dnb_nas` gets **no** access to the credential path. Stated as a testable pair: `dnb_pub` cannot execute `nas_set_site`; `dnb_nas` cannot execute `publish()` |
| 16 | **Voucher activation cannot move or broaden NAS authorization** | The publisher writes `radcheck`, `radreply` and `dnb_cred_site` and has no privilege on `dnb_site_nas`. Activation therefore cannot change which routers a site authorizes. **Residual: T5** — it can still bind a *credential* to a site of its choosing |
| 17 | **Fail closed when absent or inconsistent** | **Measured, not asserted**: empty site (S4), credential with no site (S5), and both mapping tables dropped (S8, S9) all produced real `Access-Reject`s, verified in the server log, with the credential's password row still present. A missing or broken mapping denies access; it does not restore unrestricted access |

---

## 5. Threat cases

| | Threat | Outcome |
|---|---|---|
| **T1** | Compromised **AAA Publisher** broadens a site's NAS set | **Denied.** No privilege on `dnb_site_nas`, no `EXECUTE` on the NAS functions (req. 15, 16) |
| **T2** | Compromised **provisioning writer** mints or reads credentials | **Denied.** No privilege on `radcheck`/`radreply`/`nas`, no `EXECUTE` on `publish()` (req. 2) |
| **T3** | Compromised **provisioning writer** attaches a foreign router to a customer's site | **Bounded, not denied.** Its whole job is setting mappings. Bounded by the projector design (a reconciler restores truth), by the `nas_ip` key (it can move one NAS, not fan one out), and by audit. **Residual** |
| **T4** | **Admin assigns a device to another customer's site** (P-1) | **Currently possible.** Propagates into AAA authorization without touching RADIUS. **Blocking precondition** (§10) |
| **T5** | Compromised **publisher** binds a credential to a site it chooses via `dnb_cred_site` | **Possible.** `publish()` cannot validate a site — the control plane is not reachable from the `radius` database. Mitigated only upstream, by audit and reconciliation. **Residual, and the sharpest one** |
| **T6** | `search_path` hijack against a definer function | **Denied** by pinning (req. 13) |
| **T7** | A function left with the built-in `PUBLIC` default | **Open unless explicitly revoked** — `proacl IS NULL` is invisible to an ACL sweep (§1.1). Guard test required |
| **T8** | Site deleted; devices' `site_id` silently nulled (P-3) | **Stale row** until a reconciler notices. Fails *closed* for the deleted site, but leaves a NAS authorized for a site that no longer exists |
| **T9** | Two writers race on the same NAS | **Resolved by the key** — upsert on `nas_ip` is last-write-wins and atomic. Under a composite key both rows can survive (P-1.3) |
| **T10** | **Tunnel IP reused** after decommission, stale row survives (P-3) | **The worst case.** The new router inherits the old site's authorization, and RADIUS cannot detect it — the source address is genuinely that address. Requires detach-before-reissue plus reconciliation |

T4, T5 and T10 are the three that are not closed by the privilege model alone.

---

## 6. The privilege model, exactly

```
CONTROL PLANE                          RADIUS DATABASE
─────────────                          ───────────────
dnb_netprov  (LOGIN)                   dnb_nas   (LOGIN)
  EXECUTE: mt_nas_projection_source()    EXECUTE: nas_set_site(text, text)
  no table privileges                    EXECUTE: nas_detach(text)
  no membership in any role              no table privileges
                                         no membership in any role

                                       dnb_def_nas  (NOLOGIN)   ← owns both
                                         NOSUPERUSER NOCREATEDB
                                         NOCREATEROLE NOINHERIT NOBYPASSRLS
                                         member of nothing

UNCHANGED (docs/66 §2.3):
dnb_publisher (LOGIN, control plane) — EXECUTE on claim/complete/fail
dnb_pub       (LOGIN, radius DB)     — EXECUTE on publish()/unpublish() only
```

Every function: `SECURITY DEFINER SET search_path = public, pg_temp`,
`REVOKE ALL ... FROM PUBLIC`, then a single `GRANT EXECUTE` to one role.

**Disjointness, as testable assertions:** `dnb_pub` cannot execute `nas_set_site`
or `nas_detach`; `dnb_nas` cannot execute `publish()` or `unpublish()`; neither
can `SELECT` any table in the `radius` database; neither is a member of the
other's owner role; `PUBLIC` holds `EXECUTE` on none of the four.

---

## 7. Invariants this design must hold

| | Invariant |
|---|---|
| **N1** | A voucher credential never authenticates against a NAS outside its authorized site |
| **N2** | A reassignment leaves no stale authorization — the old binding ends in the same statement that creates the new one |
| **N3** | `dnb_site_nas` is a **projection**. It never holds a mapping the control plane does not hold; drift is detectable and repairable |
| **N4** | A NAS belongs to **at most one site** at any instant *(depends on Q2)* |
| **N5** | Voucher activation cannot create, move, widen or delete any row of `dnb_site_nas` |
| **N6** | The provisioning writer can neither read nor write any AAA credential, and never reads `nas.secret` |
| **N7** | Absent, partial or inconsistent mapping state denies access — never grants it |
| **N8** | Every mapping change is attributable to an actor, on both sides |
| **N9** | A tunnel IP is not reissued to another device while any `dnb_site_nas` row names it |

N1, N2 and N7 are measured (S1/S3/S4/S5/S8/S9). N3–N6, N8 and N9 are design
intent and would need guard tests **at** the implementation gate.

---

## 8. What this design deliberately does not do

- It does **not** give the provisioning writer a way to express customer intent.
  It has no notion of "customer" at all — it moves a NAS to a site, and site
  ownership is the control plane's business.
- It does **not** let either writer read the other's data, so neither becomes a
  path into the other. **The provisioning writer must not become a backdoor into
  the voucher/AAA publisher** — hence disjoint owner roles, not one shared owner.
- It does **not** introduce a tenant layer from the ISP/operator hierarchy.
- It does **not** answer whether a site may have several routers, and does not
  need to: both are supported.

---

## 9. Unresolved questions

| | Question | Why it is not mine to answer |
|---|---|---|
| **Q1** | Does `mt_device_assign` actually permit a cross-customer `(customer, site)` pair when executed? | P-1 is read from the code. This gate creates and runs nothing. The measurement: call it in a disposable database with a site belonging to another customer and record the result. **Needs authorization** |
| **Q2** | **Can one physical MikroTik serve two sites?** | Decides N4 and the primary key (§2.1). A business/topology fact, not a code fact. Related to but **distinct from** the open multiple-routers-per-site question (docs/68 §2.6b) — that asks whether a site may have many routers; this asks whether a router may have many sites. It has never been asked |
| **Q3** | Who operates the provisioning writer — an automatic projector following `mt_device_assign`, or an explicit administrative action? | Changes the audit actor (`system` vs `staff`) and whether T3 is a service compromise or an admin compromise |
| **Q4** | What is the reconciler's authority when it finds drift — repair silently, or alarm and stop? | Repairing silently hides T3 and T10; alarming and stopping leaves a stale mapping in place. Both have a failure mode; this is an operational policy choice |
| **Q5** | Should the tunnel IP, or a separate stable NAS identifier, be the key? | D-2 (docs/68 §2.7) says a NAS identifier must exist in the control plane and does not today. `tunnel_ip` is unique but reusable (P-3), which is exactly what makes T10 possible |
| **Q6** | Does the `radius` database have an owner role suitable for `dnb_def_nas`, and what owns its existing tables? | All `radius`-side schema knowledge is **operator-relayed**; this session cannot reach the Phase 0 server (no SSH client, egress denied) |

---

## 10. What would require explicit approval

**Preconditions — before any implementation, in this order:**

1. **Close P-1.** `mt_device_assign` must reject a `(customer, site)` pair where
   the site's `customer_id` differs. Under §2 the AAA boundary is a projection of
   what that function writes, so an unenforced pairing upstream is an unenforced
   boundary downstream. This is a **control-plane change** on a `dnb_def_prov`
   function and needs its own authorization — it is not part of Decision 7.
2. **Answer Q2**, which fixes N4 and the primary key of `dnb_site_nas`.

**Then, each as its own reviewed step:**

3. The **`dnb_site_nas` schema shape** — including whether docs/70's recorded
   `PRIMARY KEY (site_id, nas_ip)` becomes `PRIMARY KEY (nas_ip)` (§2.1).
4. The **privilege model** in §6 — two login roles, one owner role, four
   functions, all additive to the `radius` database.
5. The **projector-not-author** decision in §2, which is the load-bearing choice
   and the one that concentrates the boundary on P-1.
6. The **reconciler** — its existence, its schedule and its authority (Q4).
7. The **audit facility** in the `radius` database (§4.11), which does not exist
   there today.
8. The **guard tests** for N3–N6, N8, N9 and T7, at the implementation gate.

**Not requested and not authorized by this document:** creating any table, role,
privilege or function; writing production SQL; changing production FreeRADIUS or
the production database; implementing F6; or reopening Decisions 1, 2a, 2b, 3
or 7.

**F1–F13 remain frozen. Decisions 1, 2a, 2b, 3 and 7 stand as recorded. F6
remains NOT AUTHORIZED.**

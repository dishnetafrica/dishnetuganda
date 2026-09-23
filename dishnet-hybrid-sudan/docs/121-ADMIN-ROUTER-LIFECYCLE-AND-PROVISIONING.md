# 121 — Router lifecycle and provisioning from the Admin plane: review before code, then the build record

**Status:** review written **before** code on 2026-09-23 (§A–§C); the build
record (§D–§G) is appended after the suite has run twice.
**NOTHING in this document is HARDWARE VERIFIED.** Every result below is about
the control plane's own database, its HTTP surface and its panel. No MikroTik
unit was contacted; the physical gate is `docs/119` and it has not moved.

**The instruction.** After stage 2 of the staging deployment (`docs/120` §15.8.6)
the operator asked how the platform becomes production-ready — *"we want to add
router from admin or nas devices how?"* — and, given a seven-step order, accepted
the recommendation to take **steps 1 and 2 together**:

1. **Add-router and assign forms in the Admin panel**, on the two operations G-C
   already bound (`POST /api/v1/admin/routers`, `POST …/routers/{id}/assign`).
   No new authority is created by step 1.
2. **Router lifecycle and provisioning from the Admin side**: a staff member
   moves a router along its recorded lifecycle and queues its configuration job.
   This needs the one migration `docs/118` D-2 named and did not authorise then
   (an Admin-plane enqueue function `dnb_adminwrite` may execute). Delivery stays
   **simulated**: F6-B is NOT AUTHORIZED and `DN_DELIVERY` is the worker's choice.

**Not in this instruction, stated so nobody reads it in:** the **NAS / RADIUS**
side of the question. Decision 2a chose the site-keyed source-address mechanism
(`dnb_cred_site` + `dnb_site_nas`) and **nothing of it is built**; registering a
NAS is a later step behind its own gate (`docs/70` §8.2–8.3, F6 NOT AUTHORIZED).
Nothing here touches FreeRADIUS, the `radius` database or Domain A.

---

## A. What governs this work — re-read before code

### A.1 W-1 — the write floor (`docs/85`, migration 020)

Every Admin write is one `SECURITY DEFINER` function that writes its own audit
row **inside the same transaction** through `mt_audit_write()`; the **actor is a
parameter from the identity boundary** — never a session GUC, never a request
field. `dnb_adminwrite` holds EXECUTE on the approved list and **zero table
privileges** (W-3). G-C bound register and assign exactly this way: the route
hands over `StaffIdentity::$subject`, and a body that carries `actor` or
`staged_by` is **refused, not ignored**.

### A.2 RULE I-1 — detection before the audited mutation (`docs/108`)

> *Idempotency detection must occur BEFORE the mutating, audited operation
> executes. A replay must not create a second audit event.*

Forced by W-1: the audit row is written inside the function, so a replay check
placed after the call cannot prevent the duplicate, and `mt_audit_log` is
append-only by trigger, so **a duplicate audit row is a false record that cannot
be corrected afterwards**. Intents belong to the *existing UNIQUE* class —
`mt_intents_idem_uq (customer_id, idempotency_key)` — and `docs/112` adds that a
constraint is stronger than a table only when the caller cannot bypass it by
omitting the key. Device assignment belongs to the *domain invariant* class:
*identical arguments are a no-op with no audit row*.

### A.3 F2 — no route touches a router (`docs/42` §0, `docs/118` rule 2)

Anything that would change a device is queued as an **intent** and delivered by
`Dn\Jobs`; `tests/test_frozen_guards.php` asserts nothing under `Api/` or
`Http/` can reference the delivery port. A router write on the Admin plane is a
**row** in the registry. That holds for everything built here.

### A.4 No router signal may be invented; nothing moves a device but a staff act

`docs/90`/`docs/91`: the panel may not colour a dot for a signal nothing
measures. `docs/118` D-4: *the adapter and the worker never write
`mt_devices.state`*; moving a router to `connected` or `provisioned` is a **staff
act through `mt_device_set_state`**, and whether a confirmed delivery may drive
it is **UNRESOLVED** (a B1 question). Migration 012 says *"Desired and actual,
both stored; divergence is COMPUTED"* — so `diverged` is a measurement, not a
disposition a person records.

### A.5 The plan row, and the reason the action stayed unbound

`docs/114` §E/§K: *`POST /routers`, `POST /routers/{id}/assign`, `POST
/routers/{id}/actions` — bound to `mt_device_register`, `mt_device_assign`, and a
new **`mt_device_provision_request()`** (`dnb_def_prov`, enqueues the intent the
simulator already enqueues on the admin connection). `p_actor =
identity.subject`.* `docs/118` D-2: *Queuing a `device.provision` intent from the
Admin plane needs a role that can write `mt_intents`: `dnb_adminwrite` holds
zero table privileges and EXECUTE on no function that enqueues; … `docs/112`
forbids a route that connects as `dnb_admin`. The honest fix is one SECURITY
DEFINER enqueue function for `dnb_adminwrite` — a migration, and this instruction
fixes the migration state at 027.* This instruction lifts that: **migration 028
is authorised by the acceptance of step 2.**

### A.6 What a new definer function may and may not be granted (`docs/112` A-1, `docs/113`)

*None of the spine functions may be EXECUTE-able by `dnb_admin`*, `dnb_worker`
or `dnb_app`; no new route may connect as `dnb_admin`. Migration 027 set the
precedent for an Admin-plane function acting across tenants with an **explicit
target**: `mt_admin_principal_create`, owner `dnb_def_prov`, an **INSERT-only
policy** on the table it writes, EXECUTE to `dnb_adminwrite` alone.

### A.7 Evidence (`docs/103`/`docs/105` §0)

Execution first; grants are the weakest evidence. Every negative carries a
positive control in the same session. A guard must be shown to fail when the
control it guards is removed. Repository edits assert their anchors.

### A.8 What already exists — measured, not assumed

| Piece | State before this work |
|---|---|
| `mt_device_set_state(uuid,text,text)` | 020, owner `dnb_def_prov`, EXECUTE `dnb_admin` + `dnb_adminwrite`; **audits even a same-state call** (`from = to`) — the trigger treats it as a no-op but the function does not |
| `mt_device_transition()` trigger (012) | the **only** authority on legal transitions: `registered→staged\|decommissioned · staged→shipped\|connected\|decommissioned · shipped→connected\|orphaned\|decommissioned · connected→provisioned\|orphaned\|decommissioned · provisioned→active\|diverged\|orphaned\|decommissioned · active→diverged\|orphaned\|decommissioned · diverged→provisioned\|active\|orphaned\|decommissioned · orphaned→connected\|decommissioned`; a decommissioned row refuses every change; illegal → `DN409` |
| `mt_intents` | `customer_id NOT NULL`; `actor_kind CHECK (principal\|staff\|system)`; `mt_intents_idem_uq (customer_id, idempotency_key) WHERE idempotency_key IS NOT NULL`; only `dnb_def_work` (S,U) and the blanket-grant roles can reach it; `dnb_def_prov` holds **nothing** on it |
| `DeliveryTarget::DELIVERABLE_STATES` | `connected · provisioned · active · diverged`; any other state → *retryable* refusal at the worker; no tunnel address in `10.66/16` → *retryable* |
| the measured consequence (`docs/120` §15.8.6) | a job queued for a router the worker cannot deliver to reaches `failed` after five attempts (~8 min) **with no `intent.failed` audit row** |
| `RouterAdmin` | `register()`, `assign()` on `dnb_adminwrite`; maps 23505/23503/23514/23502/`DN409` to `RouterRefused` (→ 409); names **no** state change and **no** intent |
| `POST /routers/{id}/actions` | routed, capability `routers.act`, answers **501 `router_action_not_bound`** naming D-2 |
| `SignalReport::actions()` | four actions, all `available: false`; `push_config`'s reason says the route is unbound |
| the panel | `api.js` estate **read-only** (one `fetch`, no POST, asserted); `staff.js` the identity-plane client (paths `/session`, `/staff` only, asserted); **no router form of any kind**; the four action buttons inert with the server's reasons |
| the simulator | queues its three `device.provision` jobs by direct `INSERT` on the `dnb_admin` connection, with a comment anticipating the Admin-plane function; its routers carry tunnel addresses `10.99.0.10–14`, outside `10.66/16` (finding recorded in `docs/120`, unchanged) |
| migrations | end at **027**; `test_router_control_plane` §13 and `test_operator_staff` §15 assert *no 028 exists* — both to be updated in the same commit |
| capabilities | `routers.read · register · assign · act`; nothing names a lifecycle change |

---

## B. Decisions taken before code

| # | Question | Decision, and why |
|---|---|---|
| **D-1** | scope | **Steps 1 and 2 exactly:** the panel's register/assign forms; a lifecycle route; migration 028 with the enqueue function; the action route bound for **`push_config` only**; the simulator moved onto the real write path. **Out:** authoring `mt_device_config.desired` (no screen composes a desired document — the job carries whatever `mt_device_set_desired` recorded), device secrets, the WAN fact, `reboot`/`reprovision`/`diagnostics` (no delivery case exists), device-state automation, B1, anything hardware, G-C2, B-3, O-1, G-D, NAS/RADIUS. The staging redeploy is **handed over**, not executed |
| **D-2** | which capability gates a lifecycle change | **A new capability, `routers.lifecycle`**, held by Admin and NOC. `AdminRoutes` rule 1 says a route cannot exist without deciding who may reach it; `routers.act` means *queue an intent* (this is not one) and `routers.register` is the bench act. `docs/42` §4 gives *Routers — register/edit* to Admin and Operations, which is NOC. Sales and Support get 403 naming it |
| **D-3** | which states a person may record | **Seven:** `staged · shipped · connected · provisioned · active · orphaned · decommissioned`. **`diverged` is excluded** — it is COMPUTED (012), so recording it by hand asserts a measurement nobody made (A.4). **`registered` is excluded** — the initial state, with no transition into it. The trigger remains the authority on *legality* (409 with its own message); the route's 400 covers only values staff may never record. **The restriction lives in `RouterAdmin::RECORDABLE_STATES`, not in the SQL function**, because the simulator sets `diverged` legitimately and a future divergence detector would too, as `system` |
| **D-4** | RULE I-1 for the lifecycle write | **Migration 028 amends `mt_device_set_state`: a state the row already holds is a no-op — no `UPDATE`, no audit row, the row returned.** Same signature, same owner, grants preserved (`CREATE OR REPLACE`), and a blank actor is refused on every path including the no-op. The domain-invariant class of `docs/107`/`docs/112`. Without it a browser retry of a state click would write a second, false `device.state_changed` row that the append-only trigger then makes permanent. The one W-1 body this work changes |
| **D-5** | the enqueue function | **`mt_device_provision_request(p_device uuid, p_idempotency_key text, p_actor text) RETURNS jsonb`** — `{"replayed": bool, "intent": <row>}`; owner **`dnb_def_prov`** (the plan's choice, and 027's precedent for an Admin-plane writer with an explicit target); EXECUTE **`dnb_adminwrite` only** — never `dnb_admin` (A.6). **There is no `p_customer`: the operator is DERIVED from the device row**, the `mt_site_create` contract. Payload is `{"device_id": …}` and nothing else (`DeliveryTarget::FORBIDDEN_KEYS`); `actor_kind = 'staff'`, `actor_principal_id NULL`; audit `device.provision_requested`, source `admin`, detail `{intent, state}` |
| **D-6** | guards inside the function, in order | actor required → key required → device exists (NULL → *not found*, 404 at the route, as `mt_device_assign`) → **assigned to an operator** (an intent needs `customer_id NOT NULL` and the worker runs under the intent's tenant; an unassigned router has no tenant to run under) → **state deliverable** (D-7) → **`tunnel_ip` present**. Each refusal is `DN409` with a plain reason and writes **nothing**. Why refuse early what the worker would refuse later: the measured alternative is a `failed` row after ~8 minutes with no audit row (A.8). **The `10.66/16` rule is NOT copied into SQL** — it lives once, in `TunnelAddress`, and is enforced at registration; the function refuses only a NULL address |
| **D-7** | one list of deliverable states | The function's accepted set is `connected · provisioned · active · diverged`, i.e. **`DeliveryTarget::DELIVERABLE_STATES`**, and the suite asserts the equality **by execution over all nine states** (a fresh device walked to each state by legal transitions, then the function called), so the two lists cannot drift silently. `decommissioned` gets its own message |
| **D-8** | replay | **Before the INSERT** (RULE I-1): the same `(operator, key)` naming the **same device and kind** returns the existing intent with `replayed: true` and writes **no audit row**; the same key naming a **different** device or kind is a **conflict**, `DN409`, nothing written (`docs/112`: *same key, different digest → refuse*). The race two identical concurrent requests can win against the pre-check is closed **inside the function** with `INSERT … ON CONFLICT (customer_id, idempotency_key) WHERE idempotency_key IS NOT NULL DO NOTHING`, then the same two-way check on the row that got there first. **The key is required**: a caller cannot bypass the constraint by omitting it |
| **D-9** | the action route | `POST /api/v1/admin/routers/{device_id}/actions`, body `{action, idempotency_key}`, capability `routers.act`. `action` must be a key of `SignalReport::actions()`; an action the inventory marks unavailable answers **501 `router_action_not_available`** carrying the inventory's own reason (one source, `docs/90`); an unknown key is 400. `push_config` → `RouterAdmin::requestProvision()` → **202** for a new intent, **200** for a replay, body `{intent, replayed}` with the intent through `AdminProjection::intent()` (no payload, no `last_error`, D-2 of `docs/93`). Key format `[A-Za-z0-9._:-]{8,128}`. Derived fields in the body (`actor`, `staged_by`, `customer_id`, `device_id`, `kind`, `payload`, `state`) are **400, refused rather than ignored** |
| **D-10** | the lifecycle route | `POST /api/v1/admin/routers/{device_id}/state`, body `{state}`, capability `routers.lifecycle` → `RouterAdmin::setState()` → `mt_device_set_state(id, state, subject)`. **200** `{router}`; **400** for a value outside D-3's seven or a non-string; **404** unknown or malformed id; **409** with the trigger's own reason (`illegal device transition staged -> active`, `device … is decommissioned`). A same-state request is **200 with no audit row** (D-4) |
| **D-11** | `SignalReport` | **`push_config` becomes `available: true`**; its reason says what queuing means — a `device.provision` intent the worker delivers through *its* binding (nothing under `null`, an in-memory simulator under `simulated`, a router only under F6-B) carrying whatever desired document exists, which no screen authors. The other three stay inert with their reasons. `summary.actions_available` becomes **1**, derived, not typed. The inventory remains the panel's only source |
| **D-12** | the panel | **A third client, `panel/routers.js`** — estate WRITE for routers only: `register`, `assign`, `setState`, `pushConfig`; every path under `/routers`; asserted. **`api.js` stays estate read-only and keeps every guard.** Forms: *Add a router* on the Routers page; *Assign to an operator* and *Record the next step* on Router Detail. The lifecycle buttons come from **`NEXT_STATES`**, a strict-JSON literal in `routers.js` mirroring 012's trigger **minus `diverged`**; the suite parses it and proves **by execution** that every offered transition is one the trigger accepts, and that a non-offered pair is refused (the control). The push button is live **only where the server inventory says `available`**; the idempotency key is minted once per rendered page (`crypto.randomUUID()`), so a double click is one job and a fresh page a fresh request. Copy says plainly that nothing here contacts the router and that a recorded state is what a person observed |
| **D-13** | `test_admin_ui` §8 | amended **deliberately**, not weakened: `api.js`'s assertions are unchanged (one `fetch`, no POST, no mutating call); `app.js` may perform an estate write **only** as `routersApi.<one of the four>(…)` and contains no `fetch(` of its own; the mutating-word scan skips exactly those call sites and is shown to still fire on a bare call |
| **D-14** | manifest | `writes.bound` = **four** (register, assign, state, actions) each with function, role, gate, actor rule and — for the two new — `see: docs/121`; `declared_unbound` = **five** (sites, plans, voucher-batches, disconnect, principals); `surface` = *estate read + router register/assign/lifecycle/provision; identity read-write*; `requires.worker` and `gates.admin-write.meaning` updated; the equality test and the count tests updated **in the same commit** |
| **D-15** | the simulator | its three provisioning jobs go through **`mt_device_provision_request` on `dnb_adminwrite`** (actor `sim:provisioning`, key `SIM-JOB-<serial>`), as 025's comment anticipated; `$ctxAdmin` disappears. **Its tunnel addresses stay `10.99.0.x`** — the `docs/120` finding is recorded, not silently repaired; the new function refuses only a NULL address, so those jobs still queue and still fail at the worker's `10.66/16` gate exactly as measured on staging. A router registered through the new form with a `10.66` address, assigned, recorded `connected` and pushed shows the whole chain confirm under `simulated` |
| **D-16** | migration 028's grants | `GRANT SELECT, INSERT ON mt_intents TO dnb_def_prov` with policies `dnb_def_prov_mt_intents_select` (`USING (true)`) and `_insert` (`WITH CHECK (true)`) in 017's naming — SELECT because the replay check must **see** the existing row (with an INSERT-only policy the pre-check would silently read zero rows, the O-1 lesson, and every replay would surface as a 23505); EXECUTE on the new function to `dnb_adminwrite` and **revoked from PUBLIC**; `test_definer_roles`' matrix gains the row on purpose. Nothing else in the schema changes: no table, no column, no enum value, no `guest`, no `actor_kind` |

---

## C. The evidence register going in

Labels per `docs/00` §13. This work is software: **nothing here can become
HARDWARE VERIFIED**, and rows H1–H12 of `docs/118` §C/§F are **unchanged**.

| # | Claim | How it is proved | Label |
|---|---|---|---|
| S-1 | the trigger of 012 is the only authority on legal transitions; the panel offers no transition it refuses | execution over every `NEXT_STATES` pair on fresh devices + a refused control pair | MEASURED (software) |
| S-2 | `mt_device_provision_request` accepts exactly `DeliveryTarget::DELIVERABLE_STATES` | execution over all nine states | MEASURED (software) |
| S-3 | a replay writes nothing — no second intent, no second audit row; a same-state record writes nothing | counts before/after, with the first call as the positive control | MEASURED (software) |
| S-4 | no bound route reaches a router | F2 guard (`Api/`, `Admin/` reference no delivery type) + the intent is delivered only by the worker's binding | MEASURED (software) |
| S-5 | *queued → confirmed* through `SimulatedRouterOs` proves the queue, the lease and the confirm-is-a-read logic | worker run in the suite | MEASURED (software) — **proves nothing about RouterOS** |
| S-6 | *queued → retryable* under `NullDelivery` | worker run in the suite (the `docs/114` §K proof) | MEASURED (software) |
| H1–H12 | as `docs/118` §C | untouched | as before; **HARDWARE VERIFIED pending** |

---

## D. What was built — 2026-09-23

Development and test schema only. Nothing is installed anywhere by this work;
the staging redeploy is handed over in §H and its result is **PENDING**.

| Piece | Files | What changed |
|---|---|---|
| **Migration 028** | `migrations/028_admin_router_lifecycle_and_provisioning.sql` | `GRANT SELECT, INSERT ON mt_intents TO dnb_def_prov` + policies `dnb_def_prov_mt_intents_select` / `_insert` (D-16); **`mt_device_set_state` replaced under `SET LOCAL ROLE dnb_def_prov`** with the RULE I-1 no-op and an actor check on every path (D-4), grants preserved; **`mt_device_provision_request(uuid,text,text) RETURNS jsonb`** with the guards of D-6 in order, the replay check before the insert and the audit, the race closed with `ON CONFLICT … DO NOTHING` (D-8), EXECUTE to `dnb_adminwrite` only and revoked from PUBLIC; a `DO` block that refuses to apply if any of that is not so. **Trialled first on a throwaway copy of the test database**: same-state no-op returned the row and wrote nothing; a real change wrote one row; `active → staged` raised the trigger's `DN409`; a blank actor was refused on the no-op path; the first request answered `replayed: false`, the second `true` with the same intent id and no second audit row; the same key for another router raised; an unknown device returned NULL; `dnb_admin` was refused EXECUTE. Then dropped |
| Capability and roles | `src/Admin/Capability.php`, `src/Admin/StaffRole.php` | **`routers.lifecycle`** (D-2), in `Capability::ALL`, held by Admin (all) and NOC; Sales and Support do not hold it |
| The façade | `src/Admin/RouterAdmin.php` | `RECORDABLE_STATES` (D-3); `setState()` and `requestProvision()`; the header now says four functions, never a table, never a router |
| The routes | `src/Api/AdminRoutes.php` | the 501 action block removed; `POST /routers/{device_id}/state` (`routers.lifecycle`) and `POST /routers/{device_id}/actions` (`routers.act`, `push_config` only — anything else 501 `router_action_not_available` with the inventory's reason) inside `routers()`; derived fields refused; `declaredCapabilities()` gains the new one; the route file still names no SQL function |
| The inventory | `src/Network/SignalReport.php` | `push_config` → `available: true`, its reason saying what queuing means and that nothing here contacts the router (D-11) |
| The simulator | `src/Plugin/Simulator.php` | the three provisioning jobs go through `mt_device_provision_request` on `dnb_adminwrite` (D-15); `$ctxAdmin` and the `IntentQueue` import are gone; tunnel addresses unchanged |
| Manifest and runbook | `plugin/plugin.json`, `plugin/doc/INSTALL.md` | `writes.bound` = four, `declared_unbound` = five, surface, `requires.worker`, gate `admin-write` text (D-14); the runbook's capability table and worker paragraph |
| The panel | `panel/routers.js` (new), `panel/app.js`, `panel/index.html` | the router-write client with `send()`, `NEXT_STATES` (strict JSON), `STEP_MEANING`, `freshKey()`, `RouterWriteApi` (D-12); *Add a router* on the Routers page; *Assignment* (operator and site selects, the site list filtered to the chosen operator), *Record the next step* and the live *Push configuration* button on Router Detail; a message shown once after each act; styles for the live action, the steps row and selects. `api.js` is **byte-identical** |
| Tests | `tests/test_router_lifecycle_provision.php` (new, 214 assertions) and ten amended suites | see §E |

### D.1 The `docs/114` §K row, verb by verb

| Verb | State |
|---|---|
| router **register** bound | bound (G-C) |
| router **assign** bound | bound (G-C) |
| router **action** bound | **bound (028)** — `push_config` queues a `device.provision` intent; the *intent queued → `NullDelivery` reports retryable* proof now runs from the route itself (§E 6) |
| matrix noc **can**, sales and support **cannot** | holds for register, assign, lifecycle and act |
| audit rows carry the staff username | `device.state_changed`, `device.provision_requested` carry `StaffIdentity::$subject` |
| manifest updated, equality test green | four bound, five unbound, equality asserted |

The lifecycle route was not in the §K row; it is what "moves a router along the
ladder from the Admin side" needed, and it is its own capability.

---

## E. The proofs — where each lives (`tests/test_router_lifecycle_provision.php`, 214 assertions, 10 sections)

| § | Proves | Method |
|---|---|---|
| 1 | migration 028: the function exists with the designed signature, owner `dnb_def_prov`, EXECUTE **exactly** `dnb_adminwrite` + owner over a `pg_roles` enumeration, PUBLIC nothing, `dnb_adminwrite` zero table privileges, `dnb_def_prov` exactly S+I on `mt_intents` with its two policies, `mt_device_set_state` still owned and still granted, its body carrying the no-op | catalog queries |
| 2 | RULE I-1 in `mt_device_set_state`: a real change writes one audit row (control); the same state again returns the row, writes nothing, does not even move `updated_at`; a blank actor is refused on the no-op path; an unknown device is null | execution, counts before/after |
| 3 | the lifecycle route: staged → shipped → connected with the subject as actor; same state through the route is 200 and **no audit row**; an illegal step is 409 with the **trigger's** reason and nothing written; `diverged`, `registered`, `bogus`, a number and a missing state are 400; a body carrying `actor` or `customer_id` is 400; unknown and malformed ids 404; Sales and Support 403 naming `routers.lifecycle`; Admin 200; no write connection 501; a decommissioned router refuses every further step; the role matrix; the capability is declared | route driven in-process with the identity double |
| 4 | `mt_device_provision_request` over **all nine states** on fresh devices walked by legal transitions (the `registered` fixture manufactured and labelled): the accepted set equals `DeliveryTarget::DELIVERABLE_STATES`; unassigned, address-less, unknown, blank key and blank actor refusals, each writing nothing; the intent row (operator derived, `staff`, no principal, payload = `{device_id}` only); the audit row; **replay** (same intent, no audit row, one intent); **conflict** (same key, other router: refused, nothing written); a different key is a second request | execution |
| 5 | the action route: 202 with the intent through the projection (payload, `last_error`, `claimed_by`, key withheld); 200 on replay with the same id and nothing written; the queued intent visible through `mt_admin_intents()`; missing/short/malformed/overlong key 400; unknown action 400 listing the keys; `reboot`, `reprovision`, `diagnostics` 501 `router_action_not_available` carrying the **inventory's** reason and queuing nothing; seven derived fields refused; an unassigned router and a `staged` router 409 with the reason and nothing queued; 404s; Sales and Support 403 naming `routers.act`; Admin 202; no write connection 501; the route file names no SQL function and reads no actor from a body; neither file reaches the delivery boundary | route driven in-process |
| 6 | end to end: under `NullDelivery` the job is *queued, one attempt, "no delivery path is configured"* (the §K proof); under `SimulatedRouterOs` it is **confirmed** from the simulator's memory holding the desired document; **the router's recorded state is unchanged**; the worker's audit actor carries `simulated-routeros`; `last_seen_at` still written by nothing | real worker runs |
| 7 | the inventory: exactly `push_config` available, four declared, every one with a reason, `actions_available` = 1 | `SignalReport` |
| 8 | the panel: `routers.js` issues exactly four `POST`s, all under `/routers`, one `fetch` inside `send()`, no other verb; `api.js` still one `fetch` and no POST; `app.js` imports the client, opens no `fetch` carrying a URL, calls exactly `register`/`assign`/`setState`/`pushConfig`, renders both forms, the step buttons, the live action with its key, and says *Nothing here contacts the router*; **`NEXT_STATES` parsed as JSON**: covers the nine states, offers exactly `RECORDABLE_STATES`, never `diverged`; **every offered pair accepted by the trigger by execution (20 pairs)** and two non-offered pairs refused (controls); no credential-shaped assignment in any panel file | static scans + execution |
| 9 | the manifest: the four bound routes with their functions, all `dnb_adminwrite`, the two new pointing at `docs/121`; five unbound; the surface string; `admin-write` not OPEN; the worker requirement; the role matrix | `Manifest::load` |
| 10 | repository state: the last migration is 028, the ledger records 28, the migration cites its review and RULE I-1 and closes the race, **no `10.66` in the function body** (the rule lives once, in `TunnelAddress`), `docs/121` withholds the label, no file claims a hardware result | files + catalog |

**Amended suites, each deliberately and with the reason in the file:**
`test_router_control_plane` §12 (the action is bound: a `staged` router is 409, nothing queued; the façade reaches four functions) and §13 (028 exists, ledger 28) · `test_operator_staff` §15 (027 applied, 028 follows) · `test_plugin_boundary` (four bound / five unbound / surface; 4c one available action; 5b the live control drawn only where the server says so; the SQL-leak guard's `SELECT ` needle now **case-sensitive** because `<select>` and `querySelector` are HTML and DOM, with a control that it still finds an SQL statement) · `test_installability` (four bound) · `test_admin_write_boundary` (`$approved` gains the enqueue function) · `test_definer_roles` (`dnb_def_prov` matrix gains `mt_intents: SELECT,INSERT`) · `test_admin_ui` (§5 the WireGuard key may appear in `app.js` only as the Add-router form's field name, counted; §8 estate writes only as `routersApi.<four>(…)`, the scan shown to still fire on a bare call, `app.js` opens no `fetch` carrying a URL — its `list()` helper's parameter is called `fetch`) · `test_admin_login` (`routers.js` scanned for credential shapes too) · `test_admin_api` (Sales/Support 403 on the lifecycle route; NOC 501 without a write connection on both new routes) · `test_simulator` (one action available).

**Two guards caught their author, as this project expects:** the `10.66` scan first matched the migration's own comment saying the rule is *not* copied — moved to the function body from the catalog; and the `fetch(` count matched `list()`'s parameter — narrowed to a call carrying a URL, with a control.

---

## F. The evidence register — results

| # | Claim | Result |
|---|---|---|
| S-1 | the trigger is the authority; the panel offers no transition it refuses | **MEASURED** — 20 offered pairs accepted, 2 non-offered pairs refused |
| S-2 | the function accepts exactly `DELIVERABLE_STATES` | **MEASURED** — nine states executed; accepted = `connected · provisioned · active · diverged` |
| S-3 | a replay writes nothing; a same-state record writes nothing | **MEASURED** — counts before/after with the first call as control, in SQL and through both routes |
| S-4 | no bound route reaches a router | **MEASURED** — F2 scans; delivery only by the worker's binding |
| S-5 | *queued → confirmed* through `SimulatedRouterOs` | **MEASURED (software)** — the queue, the lease and confirm-is-a-read; **nothing about RouterOS** |
| S-6 | *queued → retryable* under `NullDelivery` | **MEASURED** |
| H1–H12 | `docs/118` §C/§F | **unchanged; HARDWARE VERIFIED pending** |

**Nothing became HARDWARE VERIFIED.** `docs/119` is still the handoff; B1 is
still decided nowhere; a confirmed delivery still moves no device.

### F.1 Not started, deliberately

Desired-state authoring (no screen composes `mt_device_config.desired`) · device
secrets and the WAN fact from the panel · `reboot` / `reprovision` /
`diagnostics` (no delivery case) · device-state automation from a confirmed
delivery (D-4 of `docs/118`, a B1 question) · the simulator's `10.99.0.x`
addresses (`docs/120` finding, recorded) · G-C2 · B-3 · O-1 · G-D · T-6 / T-10 /
T-11 · F-3 · NAS / RADIUS · F6-B · any B1 decision · any deployment (§H is a
handover).

---

## G. Proof runs

| Run | Suites | Assertions | Failed |
|---|---|---|---|
| full suite, run 1 (`tests/run.sh`, fresh database, per-run credentials) | 34 | 3,262 | 0 |
| full suite, run 2 | 34 | 3,262 | 0 |
| `tests/test_router_lifecycle_provision.php` alone | 1 | 214 | 0 |
| `tests/test_router_control_plane.php` (G-C) | — | 250 | 0 |
| `plugin/bin/install-test.sh` — from the built artifact into a cluster created for the test | — | 85 | 0 |
| migration 028 trial on a throwaway copy of the test database (`dnb_028trial`, dropped) | — | 11 probes | as expected |

Was 33 suites / 3,026 assertions before this work. The first full run had
**8 failing assertions in 4 suites**, every one a guard reading the new code:
the WireGuard field name, `<select>` against the SQL needle, `list()`'s `fetch`
parameter, the migration's own comment, this document's wrapped opening line, and
the simulator suite's "no action available". Each was resolved by amending the
guard **deliberately, with its reason and a control**, or by fixing the text —
never by deleting an assertion.

---

## H. Staging redeploy — HANDED OVER, NOT executed; result PENDING

This session cannot reach the `dishnetuganda` host. The staging estate of
`docs/120` runs the build with content digest `4e7467ad…16c798e` (migrations end
at 027, no forms, the action route 501). To show this work there, the operator
runs **one command** on the server, as root:

```sh
curl -fsSL -o /root/dnb-redeploy.sh https://raw.githubusercontent.com/dishnetafrica/dishnetuganda/claude/study-this-jhe2eg/scripts/dnb-staging-redeploy.sh \
  && sh /root/dnb-redeploy.sh 2>&1 | tee /root/dnb-staging-evidence/redeploy-$(date -u +%Y%m%dT%H%M%SZ).log
```

`scripts/dnb-staging-redeploy.sh` (repository root, outside the package):

| Step | What it does | Stops when |
|---|---|---|
| 0 | read-only: the three `dnb-staging-*` containers running, the stage-1 layout present, the image present; snapshots every container's `StartedAt`/`RestartCount`; reads the migration ledger; the panel answers 200 on `127.0.0.1:8099` | anything is missing |
| 1 | builds the artifact **on the server** from the public branch with `plugin/bin/package.sh` and compares the content digest with **`1bc95524cd36f38413b5325fe26cdf76a20cb9d67e4253051c5b0bae7a7af74b`** (118 files, `dishnet-mikrotik-0.1.0-rc1`) | the digest differs — nothing has been changed yet |
| 2 | extracts into `/opt/dnb-staging/app.new-<ts>`, verifies `SHA256SUMS`, then swaps: the previous tree is **kept** at `app.prev-<ts>` | the checksums fail |
| 3 | `plugin.php install` in a one-off container with the installation's **own** `runtime.env` + `install.env` + `secrets.docker.env`: applies **exactly the pending migration** (028) and re-applies the supplied credentials — proved locally: a second install with the same secrets prints *schema already current*, and one with 028 removed from the ledger applies only 028 | the installer refuses, or the ledger count ≠ the build's migration files |
| 4 | `docker restart dnb-staging-api dnb-staging-worker` — **nothing else** | — |
| 5 | verifies: panel 200, `routers.js` 200, `GET /api/v1/admin/session` 401 (nobody signed in), the public hostname through Traefik 401 (basic auth), the worker's `delivery_binding` line, and that the **only** containers whose `StartedAt` changed are the two application containers | any of these fails |
| 6 | prints the result line and the rollback | — |

Same posture as stage 1: no production container, no Traefik file, no DNS, no
firewall rule, no PostgreSQL instance but `dnb-staging-postgres`, nothing real,
`DN_ALLOW_REAL_BINDINGS` absent, delivery `simulated`. **Rollback** is printed
by the script: swap `app.prev-<ts>` back and restart the two containers;
migration 028 adds two functions and two policies that the previous build never
calls, so it needs no undoing.

**Version name.** The artifact is still `0.1.0-rc1`; **compare the content
digest, never the name** (`docs/96`). The staging record holds `4e7467ad…`, this
build is `1bc95524…`.

**What the operator will see afterwards** at `portal-staging.dishnetuganda.com`
(basic auth, then the development identity as before): the Routers page gains
*Add a router*; a router's page gains *Assignment*, *Record the next step* and a
live *Push configuration*. **The simulated estate's five routers carry
`10.99.0.x` addresses, so a job pushed to one of them still ends `failed` after
five attempts, exactly as `docs/120` measured — that is the recorded finding, not
a regression.** To see the whole chain confirm under the simulated worker:
register a router with a `10.66.0.x` address, assign it to an operator and a
site, record *connected*, then *Push configuration* — within about fifteen
seconds *Provisioning jobs* shows `confirmed`, the router's state is still
`connected` (nothing moves a device but a person), and the audit log shows
`device.provision_requested` by `dev` and `intent.confirmed` by the worker with
`simulated-routeros` in its name.

**Result: PENDING** the operator's pasted output.

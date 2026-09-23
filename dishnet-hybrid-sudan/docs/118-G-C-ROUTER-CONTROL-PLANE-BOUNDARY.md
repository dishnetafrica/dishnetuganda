# 118 — G-C: the MikroTik router control-plane boundary — review, build, evidence

**Status: BUILD RECORD, development and test schema only. No physical MikroTik
was available; NOTHING in this document is HARDWARE VERIFIED, and nothing
here claims hardware activation, RouterOS compatibility, WireGuard push
viability or HotSpot operation. F6-B (`DN_ALLOW_REAL_BINDINGS`) stays NOT
AUTHORIZED. Migrations end at 027 — G-C adds no migration.** §A is the
re-read of what governs this gate, §B the decisions taken in writing before
any code, §C the evidence register going in; §D onward is the build record.

G-C is the third question of the identity → authorization → control-plane
sequence: G-B answered *who is DishNet staff*, T-2 *what an Operator Owner or
Staff may do*, and G-C *how an authorized action reaches a MikroTik* — in
software, with every hardware-dependent claim labelled and withheld.

---

## A. What governs G-C — re-read before code

Quoted, not paraphrased, so the build can be checked against the sentence.

### A.1 The four layers (`docs/30` Artifact 3b)

> | transport | WireGuard | carry management traffic to a router with no public IP | *must not also* authenticate subscribers |
> | management | RouterOS REST (`/rest`, over `www-ssl`) | read and write device configuration | *must not also* enforce access |
> | AAA | FreeRADIUS | authenticate and account for subscribers | *must not also* configure devices |
> | enforcement | RouterOS HotSpot | captive portal, gate the user's traffic | *must not also* decide policy |
> | control | DishNet Control Plane | registry, provisioning, plans, vouchers, tenancy | *must not do* any of the above directly |

> ```
> bootstrap / recovery  →  RouterOS scripting, /import, run-after-reset
> steady-state config   →  REST over the WireGuard tunnel
> ```

> Certificates. REST needs `www-ssl` … a self-signed per-device certificate
> is sufficient … **REQUIRES VERIFICATION** that RouterOS will serve REST on a
> self-signed certificate without further configuration.

G-C therefore builds and proves the **control** row only: the adapter that
speaks REST over the tunnel, the worker that invokes it, and the Admin-plane
writes that record a router. It configures no HotSpot, publishes no
credential, and asserts nothing about the tunnel.

### A.2 Tenancy and secrets (`docs/30` Artifact 7)

> 1. The tenant is never an argument. It is derived from the authenticated
>    principal server-side and injected into the query.
> 2. Tenant scoping at the lowest layer that can enforce it. PostgreSQL
>    row-level security …
> 3. Every secret encrypted at rest, per-device, envelope-encrypted. Router
>    passwords and RADIUS secrets are never readable in a dump.
> 4. Every administrative action audited — actor, action, target, tenant,
>    time, source — in an append-only table …

### A.3 Identity and the claim model (`docs/30` §6.3, `docs/31`)

> A serial is claimed when the tunnel comes up carrying it *and* it is
> unclaimed *and* the bundle matches. … A second tenant presenting the same
> serial is refused …
> **REQUIRES VERIFICATION:** whether the RouterBOARD serial is readable
> pre-authentication and whether it can be spoofed on a device the customer
> controls. Assume it can be, and treat first-claim-wins as the real protection.

`docs/31` §1.4: `/system/resource/print → version, board-name, architecture`;
`/system/routerboard/print → serial-number, model, firmware`; *"Record the
serial from the sticker too, and confirm it matches
`routerboard.serial-number`."* §3.1 step 5: the WireGuard keypair is generated
**on the router**, the public key registered with the serial; step 7: one
`/32` per device; step 11: *"Register in the device registry: serial, model,
version, WG public key, tunnel /32, staged-at, staged-by."* §3.2: *"No
customer data. The device does not know who it belongs to; the registry does.
Assignment happens server-side."* Test A6: *"Identity confirmed — Gateway
reads serial over the tunnel; it matches the registry."*

So, as the instruction restates: **the serial is the hardware identity, the
WireGuard public key the cryptographic identity, the tunnel `/32` belongs to
the registered device, and ownership is server-derived.** The schema already
says so: `mt_devices.serial`, `wg_pubkey` and `tunnel_ip` are each `UNIQUE`,
`customer_id` is `NULL` until `mt_device_assign`, and the lifecycle trigger
(migration 012) enforces `registered → staged → shipped → connected →
provisioned → active` with `orphaned`, `diverged` and `decommissioned` exactly
as the instruction lists them.

### A.4 The intent model is frozen and B1 is open (`docs/42` §0, `docs/00` §14)

> No screen in this specification issues a synchronous instruction to a
> router. … `Queued → Sent → Confirmed`, `Sent → Failed → [retry] → Queued`,
> `Queued → Expired`. **This model is correct under both B1 outcomes.**

> **`B.idle` determines whether the future control plane is PUSH or POLL.**
> … **Status: UNRESOLVED.** B1 has not been run.

Migration 008 enforces those transitions by trigger and its header says:
*"Nothing here says WHEN an intent reaches a router. B1 is unresolved."*
`tests/test_frozen_guards.php` scans every source file for push- or
poll-implying language. **G-C adds no such string, decides nothing about B1,
and the adapter it hardens is invoked by the worker whenever the worker runs —
whether that invocation can reach an idle router is exactly what B1 measures.**

### A.5 Three execution identities (`docs/57` §10.3)

> HTTP request → `dnb_app` · background job → `dnb_worker` (claim, expire …)
> · provisioning → `dnb_admin` (+ device register, assign, set_state,
> set_secret)

Since migration 020 the provisioning identity for HTTP is **`dnb_adminwrite`**
(W-3: EXECUTE on the approved functions, zero table privileges), and
`docs/112` binds: *"no new route may connect as `dnb_admin`."*

### A.6 The adapter boundary was planned, and its rule stated (`docs/80` §4, §7, §8)

> ```
> Application ──► DeliveryPort ──► NullDelivery       (default)
>                              ──► SimulatedRouterOs  (deterministic, F6-A)
>                              ──► RouterOsDelivery   (real, F6-B activation)
> ```
> **Rule: the simulator never masquerades as a router.** Every simulated
> response is tagged at the port boundary, `GET /api/v1/admin/health` reports
> which binding is active, and a guard test asserts the real bindings are
> **not** selectable without an explicit configuration flag that no default
> sets.

§7 carries the register H1–H7 with the labels of `docs/00` §13 (*DOCUMENTED ·
VERSION/MODEL DEPENDENT · HARDWARE VERIFIED · UNRESOLVED*): *"Nothing is
`HARDWARE VERIFIED` today — no physical MikroTik has answered anything."* §8:
*"The simulator narrows nothing here; it only lets the application be built
and tested meanwhile."* `docs/30` Artifact 13 rule 2: *"A RouterOS harness,
not a mock … A fake MikroTik would pass while the real one rejects the
command"*; rule 3: *"CHR has no radio, no RouterBOARD serial and no
factory-reset behaviour … The bootstrap flow can only be proven on metal."*

### A.7 The gate itself (`docs/114` §J, §K)

> **G-C First real operation** — router register / assign / action bound;
> matrix: noc **can**, sales and support **cannot**; audit rows carry the
> staff username; intent queued and `NullDelivery` reports retryable;
> manifest updated, equality test green; `declared_unbound` = sites + disconnect

> **J. Must wait for physical MikroTik** — F6-B · a WireGuard peer for a real
> router · B1 idle-reach · RouterOS REST over the tunnel · H1–H7 ·
> provisioning delivery confirmed · HotSpot on the router · RADIUS
> authentication from a real NAS · accounting rows from real sessions · … · a
> writer for `mt_devices.last_seen_at` · **the words "MikroTik activation
> proven"**.

`CLAUDE.md`: *"No Admin write route or button is bound, and none may be
without a new instruction."* The instruction *"Proceed to G-C"* — whose
definition is the §K row above — is that instruction, for **router register
and assign**. §B.2 records why the third route is not bound by it.

### A.8 What already exists — measured, not assumed

| Piece | State before G-C |
|---|---|
| `Dn\Delivery\DeliveryPort` | interface; *"ONLY Dn\Jobs may call an implementation"*, asserted by the F2 guard |
| `Dn\Delivery\NullDelivery` | the default binding of `bin/worker.php`; delivers nothing, confirms nothing |
| `Dn\Delivery\RouterOsDelivery` | the MikroTik adapter: `device.provision`, `session.disconnect`, `voucher.*`; header *"UNPROVEN ON HARDWARE"*; resolves the device from `payload.device_id`; **no state gate, no identity check, no gate check of its own** |
| `Dn\Delivery\RouterOs\RestClient` | REST over `https://<tunnel_ip>/rest/`; refuses any host outside `10.66.0.0/16`; peer verification deliberately off *for tunnel addresses only*; **no F6-B check at the socket**; non-JSON bodies decoded to `null` silently; one timeout |
| `Dn\Runtime\Bindings` | `DN_ALLOW_REAL_BINDINGS=yes-f6b-authorized`; `defaults()` = Null + Null; `requireRealBindingsAllowed()` throws; **no environment-driven selection of a delivery binding** |
| `Dn\Jobs\IntentWorker` / `Dn\Intents\IntentQueue` / `mt_intent_claim` | lease, backoff, bounded attempts, deadline, state trigger, worker audit via `mt_intent_audit` (023) — all tested |
| `Dn\Devices\DeviceRegistry` | façade over the seven W-1 functions and the sealed credential store |
| Admin routes | `POST /routers`, `/routers/{id}/assign`, `/routers/{id}/actions` declared with capabilities, each **501** |
| `tests/fake_routeros.php` | speaks the REST *shape* (auth, JSON, methods, status codes); knows `ip/hotspot/profile`, `ip/hotspot/active`, `system/identity` only |
| `tools/chr_harness.sh` | *"THE REAL CHECK — AND IT HAS NOT BEEN RUN"* |
| `SimulatedRouterOs` (`docs/80` §4) | **does not exist**; `Bindings::simulated()` falls back to `NullDelivery` for delivery |

---

## B. Decisions taken before code

| # | Question the instruction leaves open | Decision, and why |
|---|---|---|
| **D-1** | the instruction names `MikroTikDeliveryAdapter` and `RouterOsClient`; the repository has `RouterOsDelivery` and `RouterOs\RestClient` | **Keep the existing names.** They *are* the adapter and the client, both already fenced by frozen guards by path and namespace; renaming changes nothing about the boundary and touches two guards. The map is recorded here and in the class headers |
| **D-2** | `docs/114` §K G-C says *action bound … intent queued* | **Register and assign are bound; the actions route stays declared-unbound (501) with a specific reason.** Queuing a `device.provision` intent from the Admin plane needs a role that can write `mt_intents`: `dnb_adminwrite` holds zero table privileges and EXECUTE on no function that enqueues; the only roles that can INSERT there are `dnb_admin`/`dnb_worker` (migration 015's blanket grant, F-3) and `dnb_def_comm` through the six *operator-plane* functions. `docs/112` forbids a route that connects as `dnb_admin`. The honest fix is one SECURITY DEFINER enqueue function for `dnb_adminwrite` — **a migration, and this instruction fixes the migration state at 027.** So the §K row is met for two of its three verbs and says so; nothing is worked around. The *intent queued → `NullDelivery` reports retryable* half is proved at the worker with a fixture-queued intent |
| **D-3** | which identity provider may bind the router writes | **Any identity the process runs — the real provider or, behind its own gate, the development identity.** The audit actor is `StaffIdentity::$subject`, exactly as `StaffAdmin` does: the username under the real provider, the literal `dev` under `DEVELOPMENT-ONLY`, which cannot exist in a real-bindings process (it throws) and is blocked by the doctor outside a disposable environment. A demonstration install can therefore register a simulated router; a production install audits the person |
| **D-4** | the lifecycle vs delivery | **The adapter delivers only to a device whose recorded state is `connected`, `provisioned`, `active` or `diverged`.** `registered`/`staged`/`shipped`/`orphaned` → retryable refusal (*not recorded as connected*), `decommissioned` → permanent. **The adapter never changes `mt_devices.state`** and neither does the worker: moving a router to `connected`/`provisioned`/`active` is a staff act through `mt_device_set_state` today, and whether a confirmed delivery may drive `provisioned` automatically is **UNRESOLVED** (it needs a worker-callable definer function — a migration — and its meaning depends on B1). A simulated delivery moves nothing (instruction item 9) |
| **D-5** | identity read | **Before its first write the adapter reads `system/routerboard` and compares `serial-number` with the registry serial; a mismatch is a permanent refusal that touches nothing.** A router that returns no serial (CHR, or a version spelling it differently) is refused by default; `requireSerial: false` exists for the CHR harness only. This is a *consistency* guard (the wrong router behind this tunnel address), not the trust anchor — the trust anchor is the WireGuard key at the transport layer, which this adapter does not implement, and `docs/30` §6.3 says the serial may be spoofable. Labelled **H8, VERSION/MODEL DEPENDENT** |
| **D-6** | server-derived destination | **The destination is `mt_devices.tunnel_ip` of the device resolved by id under the intent's own tenant context, and nothing else.** The id comes from `payload.device_id` or from `target_id` when `target_type = 'device'`, must be a uuid, and any of `host`, `endpoint`, `address`, `tunnel_ip`, `ip`, `url`, `port`, `serial`, `username`, `password` in a payload is a **permanent refusal before any connection is opened**. The tunnel network rule moves to `Dn\Devices\TunnelAddress` so the Admin route can apply the same rule to a registration without referencing `Dn\Delivery` (the F2 guard forbids that) |
| **D-7** | the real-binding gate | **Checked at the socket.** `RestClient` on its real transport calls `Bindings::requireRealBindingsAllowed()` before `curl`; the test seam (an injected transport) is unaffected. `RouterOsDelivery::client()` without a factory checks it too. `Bindings::fromEnvironment()` selects the worker's delivery from `DN_DELIVERY`: unset or `null` → `NullDelivery`; `simulated` → `SimulatedRouterOs`, which **refuses to construct when the F6-B gate is open** (a simulator must never share a process with real bindings — the estate simulator's rule); `routeros` → **requires the gate**, else throws; anything else throws. **No fallback in any direction** |
| **D-8** | tagging | `DeliveryPort` gains `bindingName()` and `isSimulated()`; `DeliveryResult` gains `simulated`; `Bindings::describe()` reports `delivery_simulated`; the worker id carries the binding name, so an `intent.confirmed` audit row written under a simulator is attributable from its actor (`mt_intent_audit` is fixed by migration 023 and gains nothing) |
| **D-9** | `SimulatedRouterOs` | an in-memory router per device: applies desired paths to memory, confirms from memory, deterministic; **writes nothing to the database** — not `mt_devices.state`, not `mt_device_config.actual`. Its device resolution, payload refusal and state gate are the **same code** as the real adapter's (a shared resolver), so the simulator cannot accept what the real adapter would refuse |
| **D-10** | timeouts and malformed responses | `RestClient` gets a connect timeout and a total timeout; a transport failure is a `RuntimeException` naming neither URL nor credential; a 2xx whose body is not JSON is returned as `malformed` and the adapter treats it as **retryable, never confirmed**. Before any message reaches `mt_intents.last_error` the adapter scrubs the device's username, password and tunnel address from it |
| **D-11** | the Admin register / assign routes | `POST /api/v1/admin/routers` takes `serial`, `model`, optional `ros_version`, `wg_pubkey` (a 32-byte base64 WireGuard key), `tunnel_ip` (must lie in the management network); **`staged_by` in the body is refused** — it is the staff subject. `POST /api/v1/admin/routers/{device_id}/assign` takes `customer_id` (the explicit target operator), optional `site_id`, `name`; the W-2 constraint refuses a foreign site and the FK an unknown operator, mapped to 409; an unknown device is 404. Both go through a `RouterAdmin` façade on `Database::adminWrite()` that mirrors `StaffAdmin`. The route file never names a SQL function and never reads an actor from a body |
| **D-12** | manifest | `writes.bound` = the two routes with their capabilities; `declared_unbound` = actions, sites, plans, voucher-batches, disconnect, principals (six); `surface` and the `admin-write` gate text updated; the equality test keeps declared = served |
| **D-13** | the panel | no forms are added; the four router actions stay inert with their reasons (only the `push_config` reason changes wording where it said no route was bound). UI for register/assign is its own step |
| **D-14** | the estate simulator | untouched in behaviour: SIM- serials, `sim:*` actors, states set through `mt_device_set_state`. It gains nothing from `SimulatedRouterOs`; the two are different things — an estate of rows, and a delivery double |
| **D-15** | not done in G-C, deliberately | no migration; no `reboot`/`reprovision`/`diagnostics` delivery case; no `last_seen_at` writer; no B1 decision; no device-state automation; no HotSpot/RADIUS claim; B-3, O-1, G-C2, G-D, T-6, T-10, T-11 untouched; production untouched |

---

## C. The evidence register going in

Labels per `docs/00` §13. **Nothing may become HARDWARE VERIFIED in G-C.** Rows
H1–H7 are `docs/80` §7 restated; H8–H12 are added by this gate.

| # | Assumption | Where it lives | Label |
|---|---|---|---|
| H1 | RouterOS serves REST on a self-signed certificate with no further configuration | `RestClient` | **VERSION/MODEL DEPENDENT** |
| H2 | `Mikrotik-Rate-Limit` accepts up to 2³²−1 bps | `PlanValidator::MAX_RATE_BPS` | DOCUMENTED |
| H3 | HotSpot `shared-users` accepts up to 65535 | `PlanValidator::MAX_DEVICES` | DOCUMENTED |
| H4 | ~~`ether1` is the WAN~~ | — | RESOLVED (a recorded fact, R4) |
| H5 | `ip/hotspot/profile` carries `use-radius` | `RouterOsDelivery::assertRadiusBacked` | **VERSION/MODEL DEPENDENT** |
| H6 | `ip/hotspot/active/remove` takes `.id` | `RouterOsDelivery::disconnect` | **UNRESOLVED** — fails silently if wrong |
| H7 | `interface` returns `rx-bits-per-second` | `UplinkSampler` | **VERSION/MODEL DEPENDENT** |
| **H8** | `system/routerboard` returns `serial-number` equal to the sticker serial recorded at staging | `RouterOsDelivery` identity check (D-5) | **VERSION/MODEL DEPENDENT** (CHR has none; `docs/30` §6.3 spoofability **UNRESOLVED**) |
| **H9** | `system/resource` returns `version` and `board-name` | `RestClient::resource()` | DOCUMENTED (`docs/31` §1.4 console print; REST spelling derived from the manual's *"JSON wrapper over the same console API"*) — **not verified on a device** |
| **H10** | `system/identity` returns `name` (`DN-<serial-tail>` after staging) | `RestClient::identity()`, fake | DOCUMENTED (`docs/31` §3.1 step 3) |
| **H11** | a REST call over the tunnel completes within 5 s connect / 10 s total | `RestClient` timeouts | **UNRESOLVED** — MTU and CGNAT behaviour are `docs/31` Tests B/C |
| **H12** | a refused request answers 4xx with a JSON `{error, message}` body, and RouterOS never answers 2xx with a non-JSON body | adapter classification (D-10) | **UNRESOLVED** — the fake mirrors the shape recorded in `tests/fake_routeros.php`; the real device has not answered |
| B1 | the backend can reach an idle router through the persistent WireGuard path | the worker invoking the adapter at all | **UNRESOLVED** (`docs/00` §14) |
| — | WireGuard peer syntax; HotSpot server/profile creation order; `Mikrotik-Group` semantics; disconnect without HotSpot reload; reset / run-after-reset; tunnel MTU | `docs/80` §7 tail | **UNRESOLVED** |

What the fake proves and does not (`tests/fake_routeros.php` header, unchanged):
*"PROVES our client's HTTP handling — auth, JSON, methods, status codes — and
the delivery/confirm logic built on top of it. PROVES nothing whatsoever about
whether RouterOS accepts these paths, these payload shapes, or these values."*
G-C extends the fake with `system/routerboard` and `system/resource` under the
same rule.

---

## D. What was built — 2026-09-23

No migration. Migrations end at 027; no `028*` file exists; the test ledger
records 27. Nothing was installed anywhere and no production connection was
used (the only DSNs in this session point at the local development socket).

| Area | Files | What changed |
|---|---|---|
| The port and its results | `src/Delivery/DeliveryPort.php`, `DeliveryResult.php` | `bindingName()` and `isSimulated()` on every binding; `simulated` on every result (D-8) |
| The shared resolver | `src/Delivery/DeliveryTarget.php` (new) | the device is read under the intent's tenant context by a validated uuid; twelve payload keys (`host`, `endpoint`, `address`, `tunnel_ip`, `ip`, `url`, `port`, `serial`, `username`, `password`, `secret`, `wg_pubkey`) are a permanent refusal before any connection; the lifecycle gate — `connected`/`provisioned`/`active`/`diverged` deliverable, `decommissioned` permanent, everything else retryable (D-4, D-6) |
| The management-network rule | `src/Devices/TunnelAddress.php` (new) | `10.66.0.0/16`, one place, used by the REST client to refuse a host and by the Admin route to refuse a registration (the F2 guard forbids the route importing `Dn\Delivery`) |
| The REST client | `src/Delivery/RouterOs/RestClient.php` | **the F6-B gate at the socket**: the real transport calls `Bindings::requireRealBindingsAllowed()` before `curl`; connect and total timeouts (5 s / 10 s — H11); a non-JSON success body is returned as `malformed`; transport errors are scrubbed of host, username and password; `identity()`, `resource()`, `routerboard()` reads (H8–H10) |
| The MikroTik adapter | `src/Delivery/RouterOsDelivery.php` | every kind resolves through `DeliveryTarget`; the identity check reads `system/routerboard` before the first write and refuses a mismatch permanently, a missing serial permanently unless `requireSerial: false` (CHR harness only); malformed answers are retryable and never confirm; every exception is scrubbed before the worker records it; the no-factory path checks the gate again (D-5, D-7, D-10) |
| The simulated router | `src/Delivery/SimulatedRouterOs.php` (new) | in-memory, deterministic, every result `simulated`, refuses to construct inside a gated process, writes nothing to the database, resolves through the same `DeliveryTarget` (D-9) |
| The null binding | `src/Delivery/NullDelivery.php` | names itself `null`, reports simulated |
| Binding selection | `src/Runtime/Bindings.php`, `bin/worker.php` | `fromEnvironment()` from `DN_DELIVERY` — `null` / `simulated` / `routeros` — throws on anything else, on `routeros` without the gate, on `simulated` inside it; `describe()` gains `delivery_simulated` and `delivery_configured`; the worker id carries the binding name into every claim and audit row (D-7, D-8) |
| The Admin plane | `src/Admin/RouterAdmin.php`, `RouterRefused.php` (new), `src/Api/AdminRoutes.php`, `plugin/public/api.php` | `POST /api/v1/admin/routers` and `POST /api/v1/admin/routers/{device_id}/assign` bound through a façade on `dnb_adminwrite`; actor = `StaffIdentity::$subject`; a body carrying `staged_by`, `actor`, `state`, `customer_id`/`site_id` (on register) or `id` is **400, refused rather than ignored**; serial, model, version, WireGuard key (32-byte base64) and tunnel address (one address in `10.66/16`) validated; 23505 / 23503 / 23514 / DN409 → 409 with a neutral reason; unknown device 404; the action route answers 501 `router_action_not_bound` naming D-2 (D-3, D-11) |
| Manifest and runbook | `plugin/plugin.json`, `plugin/.env.example`, `plugin/doc/INSTALL.md` | `writes.bound` = the two routes with function, role and actor rule; `declared_unbound` = six; `surface`, the `admin-write` gate (*PARTIALLY BOUND*), a `delivery` gate, `DN_DELIVERY` declared; the runbook gains §8 *The worker and its delivery binding* and the capability table gains the two rows (D-12) |
| Signals | `src/Network/SignalReport.php` | the `push_config` reason now says why the action is not bound; all four actions stay inert (D-13) |
| Fake router | `tests/fake_routeros.php` | learns `system/routerboard` (serial from `FAKE_ROS_SERIAL`, default the test device's) and `system/resource`; fault knobs `FAKE_ROS_SLOW` and `GET system/malformed`; defaults merged under persisted state |
| Tests | `tests/test_router_control_plane.php` (new, **250 assertions**); `test_devices`, `test_intents`, `test_admin_api`, `test_plugin_boundary`, `test_installability` updated | see §E |

### D.1 The docs/114 §K row, verb by verb

| §K says | G-C |
|---|---|
| router **register** bound | **bound** — `POST /routers`, `routers.register`, actor = subject, audit `device.registered` |
| router **assign** bound | **bound** — `POST /routers/{id}/assign`, `routers.assign`, target operator explicit, audit `device.assigned` |
| router **action** bound | **NOT bound** — 501 `router_action_not_bound`. Needs an Admin-plane enqueue function = a migration; not authorised here (D-2) |
| matrix: noc can, sales and support cannot | proved on both routes and on the unbound action |
| audit rows carry the staff username | proved: `actor = 'noc-user'`, `actor_kind = 'staff'`, `source = 'admin'` |
| intent queued and `NullDelivery` reports retryable | proved at the worker with a fixture-queued intent (§1b: the intent stays queued, *no delivery path is configured* / *not authorized*) — not from the Admin route, which cannot queue |
| manifest updated, equality test green | done; declared = served |
| `declared_unbound` = sites + disconnect | now six: action, sites, plans, voucher-batches, disconnect, principals — the row predates G-C2's and 027's additions |

## E. The instruction's proofs — where each lives (`tests/test_router_control_plane.php`, 250 assertions, 14 sections)

| Instruction item | Section | The decisive assertion |
|---|---|---|
| adapter selection | 1 | three modes; `magic` throws *not a delivery binding*; no fallback in either direction |
| real-vs-simulator binding gate | 1, 1b | `routeros` without the gate throws naming `DN_ALLOW_REAL_BINDINGS`; with the gate (set from the constants) it constructs and reports F6-B; `simulated` inside the gate refuses; a bare real-transport client refuses to call out; the adapter without a factory leaves the intent queued with *not authorized* in `last_error` and no request made |
| identity derivation | 2, 5 | the first call is `GET <device tunnel_ip> system/routerboard`; every call in a delivery went to the one host the registry holds; a serial mismatch makes exactly one call and fails permanently naming neither serial; no serial → permanent unless `requireSerial: false` |
| cross-tenant isolation | 3, 11 | B's intent naming A's device: *device not found*, nothing contacted, A's row untouched; the simulator sees the same nothing; the operator plane has no router route at all |
| server-derived destination | 2 | twelve forged keys each fail permanently with *payload names a destination or credential* and **zero requests**; malformed id, unknown id and no id fail closed |
| intent lifecycle | 6 | queued → sent → confirmed with `sent_at`/`confirmed_at`, one attempt, lease released; 5xx → queued with backoff; 4xx → failed at once |
| idempotency | 6 | one key, one row, one intent |
| worker lease | 6 | a second worker claims nothing while the lease is held; the work returns when it lapses |
| delivery success / failure | 2, 6 | confirmed by read-back; a retried disconnect of a gone session confirms without a second destructive act (the router holds no session — removed once) |
| timeout | 7 | a 3-second router against a 1-second client: retryable, *router unreachable*, gave up in 1.0 s, `last_error` names no address or credential |
| malformed RouterOS response | 8 | non-JSON 200 flagged `malformed` (empty body is not); a malformed identity or write answer is retryable; a malformed read-back is **not a confirmation** |
| withheld secret fields | 9 | every `last_error` of the run carries none of the password, username, tunnel prefix, URL or either WireGuard key; the Admin router projection has no `wg_pubkey`; `mt_admin_routers()` cannot return it; the credential store holds envelopes; no device audit detail carries a key or address |
| audit behaviour | 10 | `device.registered` (actor `noc-user`, `staff`, `admin`, no operator), `device.assigned` (for the target operator); six refusals → zero rows; every confirmation names `w-gc:routeros` as `system`; every failure carries its reason |
| simulator cannot masquerade as hardware | 11 | result tagged simulated; device state unchanged; `mt_device_config.actual` untouched; `last_seen_at` still null; audit actor `w-gc:simulated-routeros`; refuses forged destinations and foreign devices; `Bindings::simulated()` reports both doubles simulated and F6-A |
| Admin plane | 12 | 201 with the subject as `staged_by` and no key in the response; four derived fields refused with 400; eight invalid inputs 400; duplicate 409 and no audit; assign 200 for an explicit target operator; foreign site 409 (W-2) and no audit; unknown/malformed device 404; sales and support 403 naming the capability; no write connection → 501; the action → 501 `router_action_not_bound` and nothing queued; the route file reads no actor from a body and names no SQL function; neither `AdminRoutes` nor `RouterAdmin` can reach the delivery boundary |
| existing G-B and T-2 regression | the whole suite | `test_staff_identity` 487 and `test_operator_staff` 378 unchanged |
| repository state | 13 | migrations end at 027, ledger 27; the client checks the gate; no source file claims a hardware result |

## F. What remains HARDWARE VERIFIED pending — every one

Nothing moved to HARDWARE VERIFIED. Each of these is a statement only a
physical MikroTik can settle, and the Phase-0 protocol (`docs/31`, `docs/32`)
is the instrument:

| # | Pending | Label now |
|---|---|---|
| H1 | REST served on a self-signed certificate with no further configuration | VERSION/MODEL DEPENDENT |
| H5 | `ip/hotspot/profile` carries `use-radius` | VERSION/MODEL DEPENDENT |
| H6 | `ip/hotspot/active/remove` takes `.id` | UNRESOLVED |
| H7 | `interface` returns `rx-bits-per-second` | VERSION/MODEL DEPENDENT |
| H8 | `system/routerboard` returns `serial-number` equal to the sticker serial; whether it can be spoofed (`docs/30` §6.3) | VERSION/MODEL DEPENDENT / UNRESOLVED |
| H9 | `system/resource` returns `version`, `board-name` over REST | DOCUMENTED |
| H10 | `system/identity` returns `name` over REST | DOCUMENTED |
| H11 | a REST call over the tunnel completes within 5 s connect / 10 s total; MTU and CGNAT behaviour | UNRESOLVED |
| H12 | refused requests answer 4xx JSON `{error, message}`; no 2xx ever carries a non-JSON body | UNRESOLVED |
| B1 | the backend can reach an idle router through the persistent WireGuard path (push) or must wait for the router (poll) | UNRESOLVED — `docs/00` §14 |
| — | WireGuard peer syntax on the target version; HotSpot server/profile creation order; `Mikrotik-Group` semantics; disconnect without HotSpot reload; reset / run-after-reset; tunnel MTU | UNRESOLVED |
| — | provisioning delivery confirmed on a device; HotSpot on the router; RADIUS authentication from a real NAS; accounting from real sessions; a writer for `mt_devices.last_seen_at`; who moves a router to `connected` and `provisioned` in production (D-4) | UNRESOLVED — `docs/114` §J |
| — | the words *"MikroTik activation proven"* | not said |

`tools/chr_harness.sh` remains *"THE REAL CHECK — AND IT HAS NOT BEEN RUN"*,
and even a green run of it is not the Phase-0 gate (CHR has no radio, no
RouterBOARD serial, no factory-reset behaviour).

### F.1 Not started, deliberately

G-C2 · B-3 (the thirteen `dnb_app` write grants are asserted unchanged by
`test_operator_staff`) · O-1 (no composite FK applied, no census run) · G-D
(no TLS, no `staff:bootstrap`, no deployment) · T-6 / T-10 / T-11 · the Admin
action route and its enqueue migration · the panel's register/assign forms ·
any device-state automation · any B1 decision.

## G. Proof runs

| Run | Suites | Assertions | Failed |
|---|---|---|---|
| full suite, run 1 | 33 | 3,026 | 0 |
| full suite, run 2 | 33 | 3,026 | 0 |
| `tests/test_router_control_plane.php` alone | 1 | 250 | 0 |
| `tests/test_staff_identity.php` (G-B) | — | 487 | 0 |
| `tests/test_operator_staff.php` (T-2) | — | 378 | 0 |
| `plugin/bin/install-test.sh` (tarball → own cluster → install → serve → simulate → uninstall) | 85 checks | | 0 |

Before G-C the suite was 32 / 2,769 / 0. The two full runs are identical
suite by suite. The development cluster was restarted once during this work
(the container had paused); it was restarted on the same socket and port the
suite has always used, and nothing else changed.

Three things the guards taught during the build, recorded because each cost
a run:

- `test_installability` recognises an environment read only as a literal
  `getenv('X')`, so `DN_DELIVERY` is read literally in `Bindings` — a constant
  would have declared a variable the sweep believes nobody reads.
- the device suite's lifecycle step continued from `staged`; moving the
  device to `connected` for delivery (D-4) meant that step now proves
  `connected → provisioned` instead of `staged → shipped`. The trigger's rule
  is unchanged; the fixture followed the lifecycle.
- the installability suite pinned *no write route is bound*; it now pins
  *exactly the two G-C routes*, so a third would have to be declared on
  purpose.

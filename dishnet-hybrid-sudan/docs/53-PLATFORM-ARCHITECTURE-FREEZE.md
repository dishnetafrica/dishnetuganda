# 53 — Platform Architecture Freeze

**Status:** BINDING — ratified, not proposed
**Companion to:** docs/41 (Domain A/B boundary freeze, also binding)
**Ratified:** at the C20 decision gate, before market validation
**Changes made:** NONE. This document records decisions; it does not implement them.

---

## 1. Why this freeze exists

The product model changed four times in short succession — restricted customer portal →
reseller platform → customer-owned uplink → the C20 hardware fork. Each change was correct
and each invalidated part of a previous document.

**Thirteen architectural decisions survived all four changes unaltered.** That is not a
coincidence; it is the signal that they sit below the product model rather than inside it.
They are frozen here so that further product movement does not reopen them.

---

## 2. The frozen items

**Binding. Not to be reopened without an explicit amendment to this document.**

| # | Decision | Source |
|---|---|---|
| **F1** | **Domain A / Domain B separation.** The Starlink system and the MikroTik system share no table, no row, no live connection | docs/41 |
| **F2** | **The intent queue is the only management path to a router.** No screen issues a synchronous router command | docs/42 §0 |
| **F3** | **The Customer PWA never talks to a router.** Both surfaces write to the backend; the backend owns the queue | docs/46 §4.2 |
| **F4** | **Authorization is server-derived.** The client holds a session token and asks by intent; no endpoint accepts a customer id | docs/45 §3 |
| **F5** | **Three experiences, not three products:** Admin Web, Customer PWA, Captive Portal | docs/46 §6 |
| **F6** | **The four planes.** Commercial, Policy, Enforcement, Transaction | docs/50 §2 |
| **F7** | **The customer owns the uplink.** Starlink/Fiber/LTE is theirs | docs/50 §1 |
| **F8** | **DishNet does not sell or ration bandwidth.** No bandwidth entitlement returns to the architecture | docs/50 |
| **F9** | **The customer controls retail policy** — speed, duration, devices, price — bounded only by device and protocol validity, never by a commercial ceiling | docs/50 §3 |
| **F10** | **No DishNet commercial limit is ever experienced by a guest.** Commercial limits bind at admin time | docs/50 §4.2 |
| **F11** | **A voucher is a RADIUS user.** A HotSpot user is not a DishNet account | docs/32 §A, docs/45 §2.1 |
| **F12** | **Phase 0 stands:** WireGuard tunnel, FreeRADIUS, tunnel-based management | docs/34, 36 |
| **F13** | **Measure and inform, never gate.** Uplink visibility is a feature; uplink enforcement is prohibited | docs/51 §6 |

---

## 3. What is NOT frozen

To prevent this document being read too widely:

| Open | Where |
|---|---|
| C20 — hardware model (A / B / C / C′) | docs/52 |
| C11 — pricing unit, then price | docs/50 §4 |
| C17 — support demarcation wording | docs/51 §5 |
| C18 — display only, or display + alert | docs/51 §6 |
| C19 — trial shape | docs/51 §7 |
| C6, C16 — customer-side roles and where staff accounts live | docs/48 |
| C9, C13, C14, C15 — portal: shop or login, customisation bounds, merchant of record, oversell | docs/48 §5–6 |
| C8′ — tunnel-down behaviour | docs/49 §2.2 |
| C12 — suspension and guests holding paid vouchers | docs/48 §6 |

---

## 4. The standing decision on BYO

**BYO MikroTik support is not to be built speculatively.**

docs/52 §7 scopes it as eight pieces of work, one of which — trust establishment without
possession — is a research problem, and another of which is a rebuilt recovery model,
because every recovery path in docs/31 assumes a `staged.rsc` baseline DishNet wrote.

Nothing in the current build should be shaped "in case BYO happens later." If C20=B is
chosen, it starts as a named project with that scope, not as an accumulation of hooks.

---

## 5. Amendment rule

An item here is unfrozen only by an explicit amendment to this document stating which item,
why, and what it is replaced by. Superseding it silently in a later document does not count.

*No production system was contacted. No prototype, schema or architecture was changed.*

# 52 — C20 Decision Paper: What Kind of Product Is DishNet Building?

**Status:** DECISION GATE — no option chosen
**Supersedes:** docs/51 §3 closing paragraph (corrected in §1 below)
**Changes made:** NONE. No code, schema or prototype change.

---

## 1. Correcting my own phrasing first

docs/51 said:

> *"C is the option that preserves the architecture while lowering the hardware barrier."*

**"Hardware barrier" was the wrong term** and it blurred the thing this decision turns on.

| C actually lowers | C does **not** lower |
|---|---|
| **Procurement** — the customer may buy a certified model anywhere; DishNet need not stock or mark up every unit | **Onboarding** — the device must still reach someone who can run the twelve staging steps |

For the target you have now named — *a hotel that already owns a MikroTik and a Starlink* —
**procurement is not the friction. Onboarding is.** On that friction, **C behaves like A**:
the device still has to be staged before it can join.

So: **certified ≠ BYO zero-touch.** Your challenge stands, and it removes the middle option's
apparent advantage for exactly the market in question.

---

## 2. The fundamental question

> **Is DishNet selling a managed MikroTik appliance/service, or a general MikroTik
> management platform?**

This is not answerable from the architecture — it is the question that decides *which*
architecture is right. What the architecture *can* say is which answer it was built for, and
that is unambiguous:

**docs/31 §3.1 is twelve steps of physical possession. The product as designed is A.**

The three answers are three companies:

| | **A** | **B** | **C** |
|---|---|---|---|
| **DishNet is** | An ISP/MSP that manages devices it owns and staged | A **software vendor** whose product manages hardware it has never seen | An MSP with an approved-hardware programme |
| **Comparable to** | A managed-service operator | **MikroTicket, Mikhmon** — software for router owners | A carrier with certified CPE |
| **Revenue** | Hardware + platform | **Platform only** | Platform, optionally hardware |
| **Moat** | Hardware relationship + platform | Platform quality alone | Certification + platform |
| **Fails when** | The customer already owns a router | The customer's device is hostile or unknown | The customer's model is not on the list |

**Worth noticing:** B is the model the competitors we studied actually use. docs/43 recorded
MikroTicket as single-tier, with Winbox passthrough — software for people who own their
routers. Choosing B moves DishNet into their category and competes on software. A and C
compete on something they do not offer: a managed, staged, warranted device.

---

## 3. The fifteen dimensions

| | **A — DishNet-supplied** | **B — Customer BYO** | **C — Certified models** |
|---|---|---|---|
| **1. What the customer buys** | Managed hotspot service **incl. the router** | **Software subscription only** | Platform, plus approval of a device they source |
| **2. DishNet responsible for** | Device, staging, firmware, tunnel, RADIUS, platform, replacement | **Platform only** — and a device it cannot warrant | Platform, staging, tunnel; device within certified limits |
| **3. Customer responsible for** | Uplink, siting, power, retail policy | Uplink, **the device and its history**, retail policy | Uplink, sourcing an approved model, retail policy |
| **4. Onboarding** | DishNet stages → ships → customer plugs in | **Customer self-adopts** — mechanism does not exist | Customer sources → **device reaches a stager** → ships back / installed |
| **5. Zero-touch still applies** | ✓✓ As designed | ✗ **Steps 1–12 have no equivalent** | ✓ Once staged |
| **6. WireGuard trust model** | ✓✓ Key on-device, registered with `staged-by` | ✗✗ **Anchor gone** — serial and key are asserted | ✓ Same as A once in a stager's hands |
| **7. Watchdog** | ✓✓ `staged.rsc` is DishNet's own baseline | ✗✗ **Unsafe** — resets to a baseline DishNet never wrote, destroying the customer's config | ✓ Valid — a stager wrote the baseline |
| **8. Capability matrix** | ✓✓ Row exists before the device ships | ✗ **No row** for an untested model; discovery must happen at adoption | ✓✓ **Certification is filling the row** |
| **9. Security** | ✓✓ Clean provenance; known-good state to restore | ✗✗ Unknown history; `/export` is not a full audit; gateway per-peer exposure (docs/51 §1.3) | ✓ Model known; provenance established at staging |
| **10. Support** | ✓✓ Known unit, known history | ✗✗ *"Was it already broken?"* is unanswerable | ✓ Known model; history known from staging on |
| **11. Reseller model** | ✗ Hardware purchase is a barrier to entry | ✓✓ **Lowest barrier, widest market** | ✓ Middle on procurement, **A-like on onboarding** (§1) |
| **12. Hardware / logistics** | Capital, stock, shipping, RMA — **DishNet's burden** | **None** | Reduced stock; staging logistics remain |
| **13. Customer acquisition** | Slowest — a purchase decision precedes a subscription | **Fastest in principle** — subscribe and run | Medium on procurement; **slow on onboarding** |
| **14. Phase 0** | ✓✓ Directly the product. docs/31/32 are the acceptance tests | ✗ **Still valid, but tests a path the product no longer uses** | ✓✓ Valid; docs/31 §7 becomes the certification instrument |
| **15. New architecture required** | **None** | **Seven pieces** (docs/51 §3), of which trust-without-possession is a research problem | **One** — a certification process; no new architecture |

---

## 4. What "trust" actually means here — a refinement

docs/51 implied BYO loses control of the device. On reflection that is not quite it, and the
distinction changes what B actually costs.

**Under A, the customer also has physical access.** DishNet ships the router to their
premises. They can reset it, add a user, unplug it. So A's trust is not *"the customer can
never touch it."*

What A actually provides is two things B does not:

| | What it gives |
|---|---|
| **A verified starting point** | The device was known-clean at a moment DishNet witnessed |
| **A known-good state to return to** | `staged.rsc` — a baseline DishNet wrote and can restore |

**B does not lose ongoing control, because A never had it. B loses the baseline.** Every
recovery mechanism in docs/31 — the watchdog, the rollback target, Test C's interrupted
provisioning, Test E's customer-pressed-reset — rests on that baseline existing.

That is the precise cost of B, and it is why B is a new architecture project rather than a
configuration option: **the recovery model, not the control model, is what has to be
rebuilt.**

---

## 5. The option A/B/C does not contain

The three-way framing assumes staging happens at DishNet or not at all. There is a fourth
shape, and it is the only one that addresses your stated target without abandoning the
trust model:

### C′ — Certified models, distributed staging

A trained technician runs the twelve steps **at the customer's premises**, on a certified
model the customer already owns.

| | |
|---|---|
| Trust anchor | **Preserved** — possession by a party DishNet trusts, `staged-by` records who |
| Watchdog | **Valid** — the technician writes `staged.rsc` |
| Capability matrix | **Applies** — certified model, row exists |
| Onboarding | **A site visit**, not a shipment |
| New architecture | **None.** It is a field-operations programme, not a code change |
| Costs | A trained, equipped, trusted technician network; a staging kit; quality control across techs |
| Fails when | No technician is near the customer |

**This is not a recommendation.** It is the completion of the option space: C′ is the only
option that keeps every architectural guarantee while removing the shipment. It converts an
architecture problem into an operations problem — which may be the better trade or may not,
depending on whether DishNet can field technicians where the customers are.

It is worth stating plainly that **C′ does not scale the way software does.** B's appeal is
that it scales without people. C′ scales with headcount.

---

## 6. The market question, answered factually

> *For hotels, guest houses, hostels and restaurants that already have Starlink/Fiber/LTE
> and want to monetise their Wi-Fi — which of A/B/C is the smallest architectural change?*

**Architecturally, the answer is not close:**

| | Architectural change |
|---|---|
| **A** | **Zero. A is the current architecture.** |
| **C** | **Near zero** — a certification process; no new architecture |
| **C′** | **Zero architecturally** — a field-operations programme |
| **B** | **Largest** — seven pieces, one of them a research problem (§3.15) |

**But the question contains a tension worth naming, because it is the decision:**

The market you describe is defined by *already owning* the uplink — and often the router.
The architectural answer (A) asks that customer to buy a router they may already have. The
commercial answer for that market (B) asks DishNet to rebuild its recovery model.

```
        smallest architectural change  ────────▶  A
        smallest commercial friction   ────────▶  B
                        and they point in opposite directions
```

**That gap is C20.** It cannot be closed by analysis — only by deciding which cost DishNet
would rather carry: **asking customers to adopt DishNet's hardware, or asking DishNet to
build trust without possession.**

C′ is the only option that sits outside the trade, by paying in field operations instead.

### 6.1 One empirical question that would narrow this

**How many of the target customers actually already own a MikroTik?**

If most have Starlink but no router, A's friction is much smaller than it appears — they
have to buy a router from someone, and DishNet supplying it is not an imposition. If most
already run a MikroTik, A asks them to discard working hardware, and the friction is real.

**This is answerable by asking a dozen prospects**, and it would do more to settle C20 than
further analysis. I would not guess at it.

---

## 7. The freeze tables

### CURRENT ARCHITECTURE — what can be frozen now

Independent of C20. Safe to build on:

| | |
|---|---|
| Domain A / Domain B separation (docs/41) | **Frozen, binding** |
| Intent queue as the only management path | **Frozen** |
| Customer PWA never talks to a router | **Frozen** |
| Server-derived authorization (docs/45) | **Frozen** |
| Three experiences: Admin, Customer PWA, Captive portal | **Frozen** |
| Four planes; uplink outside them (docs/50) | **Frozen** |
| DishNet does not sell or ration bandwidth | **Frozen** |
| Customer sets retail speed, duration, devices, price | **Frozen** |
| No DishNet commercial limit felt by a guest | **Frozen** |
| Vouchers are RADIUS users (docs/32 §A) | **Frozen** |
| Phase 0 WireGuard + FreeRADIUS | **Built; unaffected by C20** |
| C18 = measure and display, never gate | **Frozen as a principle** |

**None of these is at risk under any C20 answer.** The decision is narrower than it feels.

### C20 = A — what continues unchanged

**Everything.** docs/31 and docs/32 are the product's acceptance tests. Nothing in Phase 0
is wasted, no new trust mechanism, no new recovery model. The only open work is the
commercial one — hardware pricing folded into C11 — and the market friction of §6.1.

### C20 = B — what becomes a new architecture project

Not a feature. A project, with these as its scope:

| | |
|---|---|
| 1 | **Remote adoption** replacing docs/31 §3.1 steps 1–12 |
| 2 | **Trust establishment without possession** — the research problem |
| 3 | **Pre-adoption audit** — and `/export` is not sufficient |
| 4 | **A watchdog safe without a DishNet-written baseline**, or an explicit decision to run without one |
| 5 | **Capability discovery** replacing the certified matrix row |
| 6 | **Gateway per-peer restriction** (docs/51 §1.3) — before the first device |
| 7 | **A recovery model that does not assume a known-good state** (§4) |
| 8 | Support scope widened from uplink to uplink + device history |

Item 7 is the one to size before committing: **every recovery path in docs/31 assumes
`staged.rsc` exists.**

### C20 = C — what stays, what is added

**Stays:** the entire architecture. Staging, trust anchor, watchdog, matrix, Phase 0 — all
unchanged.

**Added — a process, not architecture:**

| | |
|---|---|
| A certified-model list, with minimum RouterOS version |
| A certification procedure — docs/31 §7 is already the instrument |
| A published capability envelope per certified model |
| A staging route for customer-sourced devices: ship-in, or **C′** field staging |
| Commercial terms for a device DishNet staged but did not sell |

---

## 8. What I have not done

No option chosen. §1 corrects my own phrasing. §4 revises a claim I made in docs/51 —
BYO's cost is the lost baseline, not lost control. §5 adds C′ to complete the option space,
explicitly not as a recommendation. §6 answers the architectural question factually and
names the tension rather than resolving it. §6.1 identifies the one fact that would narrow
the decision, which is a question for prospects, not for me.

**No production system was contacted. No prototype, schema or architecture was changed.**

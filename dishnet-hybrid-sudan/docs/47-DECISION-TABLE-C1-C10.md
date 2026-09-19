# 47 — Decision Table C1–C10

**Status:** DECISION REQUEST — nothing here is decided by this document
**Purpose:** Put every open product question in one place, in answerable form
**Changes made:** NONE.

**How to answer:** reply with the option letters, e.g. `C1=A, C2=B, C6=A …`.
Any question may be answered *"defer"* — §5 shows what that costs.

---

## 0. Two corrections to docs/46 before the table

I checked C6 and C8 against the existing documents instead of restating them.
Both were wrong as I wrote them.

### 0.1 C8 was not open — it was decided before I raised it

docs/36 is titled **"Phase 0 — RADIUS build"**, and docs/32 §A already tests the full
RADIUS path end to end:

| Step | Expected result |
|---|---|
| A10 | *"FreeRADIUS logs `Access-Accept`; Internet works"* |
| A12 | *"`radacct` Start row, then Interim, octets growing"* |
| A13 | *"Wait for expiry → access stops **with no intervention**"* |
| A14 | *"`radacct` Stop row, final octets"* |

**A voucher is a RADIUS user. That is settled architecture**, and expiry-without-
intervention is already an acceptance criterion. I marked it open without reading the
runbook I had already written. C8 is withdrawn as a choice — but see C8′ below, which is
the real question that was hiding underneath it.

### 0.2 C6 has a precedent I did not account for — and it exposes a contradiction

docs/42 §4 already contains a role matrix. It is **Admin Web** roles, but the **Reseller**
row is close to a hotel operator:

| Area | Reseller (docs/42 §4) |
|---|---|
| Dashboard | Own business only |
| Routers — view | Assigned only |
| Routers — queue action | **No** |
| Vouchers — generate | **Yes, within quota** |
| Sales / Revenue | **Own only** |
| Sessions — view | Own routers only |

docs/42 defines *Reseller* as *"A tenant with voucher-selling rights. A **role**, not a
separate entity."*

**That is a contradiction with docs/45 and docs/46**, which assume the hotel operator is a
Customer PWA user. Both cannot be true without deciding which. Raised as **C10**, and it
is the most structurally important question in this table — it decides whether we build
one permission system or two.

---

## 1. The decision table

**Type:** `COMM` commercial · `PROD` product · `SUPPORT` support policy ·
`ARCH` architectural · `TECH` technical
**Decider:** **You** on everything marked COMM/PROD/SUPPORT. I have decided none of them.

| # | Question | Type | Options | Blocks |
|---|---|---|---|---|
| **C1** | Can a customer create vouchers themselves? | PROD | **A** create freely · **B** create within a quota · **C** request only, DishNet issues | PWA freeze |
| **C2** | Who sets voucher prices? | COMM | **A** DishNet sets · **B** customer sets · **C** DishNet sets a ceiling, customer sets within it | PWA, portal, billing |
| **C3** | Can one person hold several accounts? | PROD | **A** one account per login · **B** account switcher | Login flow |
| **C4** | How is the customer billed? | COMM | **A** flat monthly service fee · **B** per voucher issued · **C** revenue share · **D** fee + usage | Billing, uCRM |
| **C5** | Should a customer see an access point is down? | SUPPORT | **A** show it (current) · **B** hide it · **C** show only after N minutes | One card |
| **C6** | Owner vs staff roles inside the Customer PWA | PROD | **A** two roles (owner / operator) · **B** single login · **C** configurable per customer | **PWA freeze** |
| **C7** | How does a voucher reach a guest? | PROD | **A** on-screen + print · **B** QR · **C** SMS/WhatsApp · **D** combination | Journey B end |
| **C8′** | *(replaces C8)* If the tunnel is down, can guests still log in? | **ARCH** | **A** no — RADIUS only · **B** yes — local fallback users · **C** decide after Phase 0 measures real outage frequency | Phase 0 config, portal |
| **C9** | Can a guest buy access at the captive portal? | **COMM** | **A** voucher only · **B** self-purchase · **C** voucher now, self-purchase later | **Portal design** |
| **C10** | Is the docs/42 "Reseller" the same person as the Customer PWA user? | **ARCH** | **A** same — one role system, two surfaces · **B** different — Reseller is a DishNet partner, hotel is a PWA customer · **C** collapse: no Customer PWA, hotel operators use Admin Web scoped | **Everything below** |

---

## 2. C8′ — the question that was hiding under C8

Phase 0 places FreeRADIUS at `10.66.0.1`, reachable **over the WireGuard tunnel**. If a
voucher is a RADIUS user, then authentication depends on that tunnel.

**Consequence:** when the tunnel is down, **no guest can log in — even holding a valid,
paid voucher.** Already-connected sessions may survive; new logins cannot. For a hotel at
check-in time, that is not degraded management, it is total guest Wi-Fi failure, and the
hotel will experience it as DishNet being down.

MikroTik supports router-local hotspot users, so a fallback is technically available. The
trade is real in both directions:

| | **A — RADIUS only** | **B — local fallback** |
|---|---|---|
| Accounting | Complete, central | **Gaps** — local sessions are invisible to `radacct` |
| Revenue reconciliation | Sound | **Local redemptions may not reconcile** |
| Guest experience in an outage | **Cannot log in** | Keeps working |
| Expiry | Central, guaranteed (A13) | Must be enforced on-device |
| Complexity | One path | Two paths, and they can disagree |

**This is architectural, not commercial** — but it has a commercial consequence (the SLA
you can offer a hotel), so it is yours to decide, not mine. **Option C is legitimate and
cheap:** Phase 0 will produce real tunnel-uptime numbers, and the decision is much better
made against a measured outage rate than a guess.

---

## 3. C10 — the question that changes the shape of the product

Three coherent answers, with very different costs:

| | **A — same person** | **B — different people** | **C — no Customer PWA** |
|---|---|---|---|
| Meaning | One role system; the PWA is a mobile view of a scoped Admin role | Two role systems: DishNet partners in Admin, customers in the PWA | Hotel operators log into Admin Web, scoped |
| Permission logic | **Written once** | **Written twice** | Written once |
| Risk | Customer surface inherits admin concepts | Divergence between two systems | Customers see an operator-shaped product |
| docs/45/46 | Need revision | Stand as written | **Largely invalidated** |
| The prototype | Re-framed, not rebuilt | Unchanged | Discarded |

I am not recommending one. But note this is the only question here that can **invalidate
work already done**, which is why it should be answered before C1–C7 rather than after.

If C10=B, one thing still needs saying explicitly: *Reseller* and *Customer* would then be
two different commercial relationships, and docs/42 §4's Reseller row should stop being
read as a description of a hotel.

---

## 4. Dependency order

```
C10  ── decides whether one permission system exists or two
 │
 ├──▶ C6   owner vs staff        ─┐
 ├──▶ C1   who may create         ├──▶  CUSTOMER PWA CAN BE FROZEN
 ├──▶ C3   multi-account          │
 └──▶ C5   AP visibility         ─┘

C9   ── login page or shop?  ─────▶  CAPTIVE PORTAL CAN BE DESIGNED
 └──▶ C2 prices ──▶ C4 billing model

C8′  ── tunnel-down behaviour ────▶  PHASE 0 ROUTER CONFIG
                                     (answerable from Phase 0 data)

C7   ── voucher delivery ─────────▶  completes Journey B (needs C9 for context)
```

**Three independent tracks.** They can be decided in parallel — only C10 gates other
questions.

**Minimum to unblock each piece of work:**

| To do this | You must answer |
|---|---|
| Freeze the Customer PWA | **C10, C6** (C1, C3, C5 can default) |
| Design the captive portal | **C9** |
| Configure Phase 0 hotspot | **C8′** (or C8′=C, defer on data) |
| Finish Journey B | **C7** |
| Touch billing | C2, C4 |

---

## 5. Reversibility — what deferring actually costs

The useful question is not "is this important" but "does it get more expensive later".

| # | Reversible later? | Cost of deferring |
|---|---|---|
| **C10** | **No** | **Highest.** Rebuilds permission logic and possibly a surface |
| **C4** | **Barely** | Billing model shapes the schema; retrofitting revenue share is painful |
| **C9** | **No for the portal** | Portal-as-shop is a different product; rebuild, not extend |
| **C8′** | Partly | Config, not schema — but a live outage teaches it expensively |
| **C6** | **Partly** | Cheap now; expensive once customers have logins and habits |
| **C2** | Yes | Pricing authority is a permission flag |
| **C1** | Yes | Same |
| **C7** | Yes | Additive |
| **C3** | Yes | Login-flow change |
| **C5** | Yes | One card |

**Reading:** C10, C4 and C9 are the load-bearing three. C1, C3, C5, C7 are genuinely safe
to defer — they can ship with a default and change later without penalty.

So the table is shorter than it looks: **answer C10, C9, C4 and C6.** The rest can wait,
and C8′ can wait on Phase 0 data.

---

## 6. What I have and have not done

**Have:** laid out options, consequences, dependencies and reversibility; corrected two
errors of mine; surfaced the docs/42 ↔ docs/45/46 contradiction.

**Have not:** chosen any commercial or product answer. C1, C2, C3, C4, C5, C6, C7, C9 and
C10 are yours. C8 I closed only because the documents already closed it — that is a
finding, not a decision.

**No production system was contacted. No prototype, schema, or architecture was changed.**

---

## 7. Note for whoever answers

Two answers here are worth more than the other eight:

- **C10**, because it is the only one that can invalidate finished work.
- **C9**, because it decides whether the captive portal is a login page or a shop —
  and if it is a shop, DishNet needs guest payments, receipts, refunds, pricing authority
  and a revenue split with the hotel. That is a business line, not a screen.

Everything else can proceed behind a default.

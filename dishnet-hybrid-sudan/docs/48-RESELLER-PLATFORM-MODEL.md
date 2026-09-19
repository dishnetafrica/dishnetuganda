# 48 — Reseller Platform Model and Revised Decision Table

**Status:** MODEL CORRECTION + DECISION REQUEST
**Supersedes:** docs/47 (C1–C10 answers withdrawn at your instruction)
**Amends:** docs/42 §4 and §8.1 · docs/45 · docs/46
**Changes made:** NONE. No code, prototype, schema or architecture change.

---

## 1. What the new objective changes

> **DishNet provides the network and platform. The customer runs their own HotSpot
> business on it, sets their own retail prices, and keeps the margin.**

This is not a bigger version of the previous model. It inverts who holds commercial
authority, and it breaks one approved specification outright.

### 1.1 The collision

docs/42 §8.1, an approved baseline, states:

> *"Plan editing is **Admin only**. Resellers select plans; they do not define them."*

A Plan carries **price** (docs/42 §8.1 field list). So under the approved spec, a customer
**cannot** set a retail price — not as a permission choice, but structurally. The new
objective requires exactly that.

**docs/42 §8.1 must change.** Flagged rather than quietly reinterpreted, because it is an
approved document.

### 1.2 Why a permission flag will not fix it

The obvious patch — "let Resellers edit Plans" — breaks the other half. A Plan also carries
**speed limit** and **data allowance**, which are enforcement values. A customer free to
edit their own Plan could raise their own speed limit.

The two-layer model (Plan → Profile) cannot express *"you may set price freely but may not
exceed what you bought."* It has no place to put the ceiling.

---

## 2. The correction: three layers, not two

```
┌─────────────────────────────────────────────────────────────┐
│  LAYER 1 — PROFILE                        DISHNET ONLY      │
│  What RADIUS and the router actually enforce.               │
│  Rate limits, session timeout, shared-users, address pool.  │
│  The customer never sees this layer exists.                 │
└─────────────────────────────────────────────────────────────┘
                              ▲ enforces
┌─────────────────────────────────────────────────────────────┐
│  LAYER 2 — ENTITLEMENT              DISHNET SETS · CUSTOMER │
│                                     SEES, CANNOT CHANGE     │
│  What Riverside actually bought. The commercial ceiling:    │
│  max speed per session · max concurrent sessions ·          │
│  max devices per voucher · allowed durations · data pool.   │
└─────────────────────────────────────────────────────────────┘
                              ▲ bounds
┌─────────────────────────────────────────────────────────────┐
│  LAYER 3 — RETAIL PLAN                 CUSTOMER CREATES     │
│                                        FREELY, WITHIN L2    │
│  "Lobby Day Pass" · 24h · 2 devices · 8 Mbps · UGX 8,000    │
│  Name, duration, devices, speed, PRICE, currency.           │
│  This is the customer's own product.                        │
└─────────────────────────────────────────────────────────────┘
```

A voucher is issued against a **Retail Plan**. The platform validates it against the
**Entitlement**, then maps it to a **Profile** for enforcement.

**This one change delivers the whole objective.** The customer gets genuine commercial
freedom — any price, any packaging, any margin — while every value that touches the network
stays bounded by what they purchased. Nothing needs to be trusted; the ceiling is
structural.

It also means a customer *cannot* accidentally break their own service by mispricing, and
DishNet never has to approve a price.

---

## 3. Capability split

Drawn from the MikroTicket features verified in docs/43 §2.1 plus your list.
**Principle: business functions to the customer; anything requiring RouterOS to DishNet.**

| Capability | Customer PWA | Admin / NOC | Note |
|---|---|---|---|
| Create retail plans, set prices | **✅ Full** | Sets the entitlement ceiling | Layer 3 |
| Duration / validity | **✅** | Bounds allowed range | |
| Devices per voucher | **✅** | Bounds maximum | |
| Elapsed vs paused time | **✅** | — | Commercial packaging |
| Speed per plan | **✅ up to ceiling** | Sets ceiling | Cannot exceed entitlement |
| Generate voucher batches | **✅ Unlimited within capacity** | Visibility only | C11 defines capacity |
| Print / share / QR | **✅** | — | |
| View own sessions | **✅** | All sessions | |
| Disconnect a session | **✅ own, audited** | All | Queued as an Intent |
| Sales and revenue | **✅ their retail revenue** | DishNet's own revenue | **Two different numbers — §4.2** |
| Reports | **✅ own** | Estate-wide | |
| Operator / staff accounts | **✅** | — | C6, C16 |
| Multiple locations | **✅** | Assigns sites | |
| Captive portal appearance | **✅ bounded** | Publishes it | C13 — content, not code |
| See a router's health | **✅ by name** | Full detail | |
| **Router provisioning** | ❌ | **✅ only** | |
| **WireGuard / tunnel** | ❌ | **✅ only** | |
| **RADIUS profiles** | ❌ | **✅ only** | Layer 1 |
| **Firewall / WAN / routing** | ❌ | **✅ only** | |
| **RouterOS upgrade** | ❌ | **✅ only** | |
| **Winbox / SSH / terminal** | **❌ never** | **❌ not exposed as a product feature** | docs/43 §C — an unauditable channel around the intent queue |
| **Registering a router** | ❌ | **✅ only** | DishNet's hardware |

**The boundary in one line:** the customer controls *what is sold and for how much*;
DishNet controls *what the network does*.

Winbox is the only row that is a hard never. Everything else is a scope question.

---

## 4. Two consequences worth stating before the table

### 4.1 The customer's business is not DishNet's business

DishNet's commercial relationship is with Riverside. Riverside's is with its guests. These
must never merge:

```
DishNet ──── invoices ────▶ Riverside ──── sells vouchers ────▶ Guest
        ◀─── pays ─────                ◀─── pays ─────
```

DishNet does not bill the guest. DishNet does not set the guest price. DishNet does not
see, hold or route the guest's money — **unless C9=B**, which is where §5 C14 becomes a
regulatory question rather than a feature.

### 4.2 "Revenue" now means two different things

| Surface | "Revenue" means |
|---|---|
| Admin Web | What DishNet bills its customers |
| Customer PWA | What Riverside collects from its guests |

These must never appear in the same chart or be summed. A single mislabeled figure here
misstates DishNet's income. Worth a naming rule now: **DishNet revenue** vs
**customer retail sales**.

---

## 5. Revised decision table

Each row separates the three levels you asked for:
**① DishNet infrastructure · ② Customer business · ③ Guest access**

### Questions your stated model now answers

I am reading your decision, not making one.

| # | Now answered as | ① DishNet | ② Customer | ③ Guest |
|---|---|---|---|---|
| **C1** | **A — create freely** within purchased capacity. Not a DishNet control lever | Enforces capacity ceiling | Issues any quantity | Receives a code |
| **C2** | **B — customer sets retail price** | Sets the entitlement, never the price | Full pricing authority, keeps margin | Pays the customer's price |
| **C4** | **Split into two flows** — see §4.1 | Bills the customer for capacity | Bills guests; keeps margin | Pays the customer, never DishNet |
| **C6** | **A — owner / manager / operator** roles required | — | Defines its own staff and permissions | — |
| **C10** | **A — Reseller is a capability of a Customer**, not a separate party | One permission system | PWA is the customer-facing rendering of a scoped role | — |

**C10=A has a large upside:** one permission system, written once, rendered on two
surfaces. docs/42 §4's Reseller column becomes the specification of what the Customer PWA
shows — the two documents stop competing. It also means **none of the prototype is
discarded.**

### Questions still genuinely open

| # | Question | Options | ① DishNet | ② Customer | ③ Guest |
|---|---|---|---|---|---|
| **C3** | One person, several accounts? | A one per login · B switcher | — | Chains may hold many sites | — |
| **C5** | Customer sees an AP is down? | A show · B hide · C delay | Sees all | **Now leans A** — they are running a business on it | Affected either way |
| **C7** | How a voucher reaches a guest | A print · B QR · C SMS/WhatsApp · D combination | Provides the rail | Chooses per location | Receives it |
| **C8′** | Guests loggable-in when the tunnel is down? | A RADIUS only · B local fallback · C decide on Phase 0 data | Owns the tunnel | **Now loses revenue in an outage** | Cannot connect under A |
| **C9** | Guest self-purchase at the portal? | A voucher only · B self-purchase · C later | Would provide the rail | Would gain automated sales | Would pay at the portal |

**C8′ has changed weight.** Under the old model a tunnel outage cost goodwill. Under the
reseller model it stops the customer earning, on infrastructure they pay DishNet for. It is
now an SLA question with money attached.

---

## 6. New questions the reseller model creates

These did not exist before and none is a UI detail.

| # | Question | Why it is new |
|---|---|---|
| **C11** | **What exactly does the customer buy?** Concurrent sessions · link bandwidth · data volume · unmetered | You ruled out voucher count as the control. Something must still be the ceiling, or capacity is unbounded and unpriceable. **This is now the core commercial product definition.** |
| **C12** | **A customer stops paying DishNet. What happens to guests holding paid vouchers?** Cut immediately · honour outstanding vouchers · grace period | Guests paid *Riverside*, not DishNet, and would be stranded holding valid credentials. Commercial, reputational and arguably a fairness question |
| **C13** | **How far does portal customisation go?** Logo + colours + text · templates · arbitrary HTML/JS | Publishing writes files to a router on DishNet's network, served to guests. Arbitrary code would be a security exposure |
| **C14** | **If C9=B, whose money is it and who is the merchant?** Customer is merchant, DishNet routes · DishNet collects and remits | Collecting on a customer's behalf may make DishNet a payment intermediary. **Needs legal advice in Uganda before it is designed** — not something to settle from a product discussion |
| **C15** | **Oversell: if a customer sells more than the link carries, who is blamed?** Enforce a cap · warn only · contractual | The portal reads *"Powered by DishNet"*. The customer's commercial freedom becomes DishNet's reputational exposure |
| **C16** | **Are hotel staff uCRM records?** Sub-accounts under the customer · full uCRM contacts | C6 creates logins for people who are not DishNet customers. Where they live is a data-model decision |

**C11 is now the first question in the whole table.** Without it, C1 ("create freely within
purchased capacity") has no definition of capacity, and the product cannot be priced.

**C14 is the one not to answer in this conversation.** If guests pay through
DishNet's infrastructure, the question of who is the merchant of record is regulatory, and
should be put to someone qualified in Ugandan payments law before any design follows.

---

## 7. Revised priority

```
C11  what is capacity          ──▶ defines the product · prices it · bounds C1
 │
C10=A  one permission system   ──▶ unifies docs/42 §4 with the PWA
 │
C6   owner/manager/operator    ──▶ + C16 · unblocks the PWA
 │
C12  suspension and guests     ──▶ before the first customer is signed
 │
C9 ──▶ C14 (legal) ──▶ C15     ──▶ before the portal is designed
 │
C8′  outage behaviour          ──▶ on Phase 0 data
 │
C3 · C5 · C7 · C13             ──▶ safe behind defaults
```

---

## 8. Effect on existing documents

| Doc | Effect |
|---|---|
| **docs/41** boundary freeze | **Unaffected.** Domain A/B separation is untouched |
| **docs/42 §8.1** | **Must change.** Three layers replace two; Retail Plans become customer-editable |
| **docs/42 §4** | **Reinterpreted, not rewritten.** The Reseller column becomes the Customer PWA spec (C10=A) |
| **docs/42 §0** intent model | **Unaffected and now more load-bearing** — customer actions queue too |
| **docs/45** | Identity chain holds. Server-derived authorization matters **more** — real money, real multi-tenancy |
| **docs/46** | Three actors hold. Actor B gains the reseller capability |
| **docs/47** | Superseded |

### 8.1 The prototype is under-built, not wrong

Nothing in it needs removing. All 244 checks remain valid, and both things it was most
careful about — server-derived authorization and the projection that hides router
internals — become **more** important once customers are running competing businesses on
shared infrastructure.

What it is missing: retail plan editing, batches, sessions with disconnect, staff accounts,
portal customisation, sales and reports. That is additive work, once C11, C6 and C16 are
answered.

---

## 9. What I have not done

I have not chosen any commercial answer. Where §5 records C1, C2, C4, C6 and C10 as
answered, that is **your stated model read back** — say so if I have read it wrong.

C11–C16 are new questions, not recommendations. C14 in particular is flagged as needing
advice I am not qualified to give.

**No production system was contacted. No prototype, schema or architecture was changed.**

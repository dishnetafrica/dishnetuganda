# 50 — C11 Reframed: What the Customer Buys from DishNet

**Status:** OPTIONS ANALYSIS — no option chosen
**Supersedes:** docs/49 §§3–7 (entitlement comparison). docs/49 §2 survives — see §7
**Amends:** docs/48 §2 (the Entitlement layer)
**Changes made:** NONE. No code, schema or prototype change.

---

## 1. The correction, and how far it reaches

> **The MikroTik HotSpot service runs behind the customer's own uplink — Starlink, Fiber
> or LTE. DishNet does not sell, ration or enforce that uplink.**

### 1.1 The architecture never assumed otherwise

I checked before revising. The word **uplink** appears nowhere in docs/30, 31, 41 or 42.
docs/31 §46 already treats the WAN as whatever the site happens to have:

> *"WAN type differs from the bench — staged for DHCP, delivered to PPPoE"*

and A8 simply configures the WAN it finds. **Phase 0 provisioning is unaffected by this
correction** — it was already uplink-agnostic.

The bandwidth assumption entered in **docs/48 §2, in the Entitlement layer I proposed.**
That is the only place it lives. Correcting it does not disturb the architecture beneath.

### 1.2 What was wrong with it

I built L2 to solve *"stop the customer taking more capacity than they bought."* That is a
real problem **only if DishNet supplies the capacity.** Behind the customer's own Starlink
it is not DishNet's capacity, and the layer was answering a question nobody asked.

---

## 2. What replaces it

Your proposal drops L2. That is right in substance, but taken literally it leaves DishNet
selling nothing — the subscription has no home in L1/L2/L3.

**The entitlement should not be deleted. Its subject should change** — from *network
capacity* to *platform scope*.

Layers implied a stack where each bounded the one above. That was the flawed idea. These
are better understood as **separate planes**:

```
   ┌── UPLINK ─────────────────────────────────── THE CUSTOMER'S ──┐
   │   Starlink / Fiber / LTE                                      │
   │   DishNet neither sells, rations nor enforces it.             │
   │   DishNet MAY measure it and show the customer. Never gate.   │
   └───────────────────────────────────────────────────────────────┘

   COMMERCIAL PLANE          DishNet ↔ Customer
   What the customer buys: platform access, routers under management,
   sites, operator seats, features.        ← the subscription lives HERE
   Binds at ADMIN time. Never at a guest's login.

   POLICY PLANE              The customer alone
   Retail plans: speed, duration, devices, pause rules, price.
   Bounded only by device and protocol validity (§3) — never commercially.

   ENFORCEMENT PLANE         DishNet-built mechanism, customer-directed
   RADIUS attributes and router config that carry out the customer's policy.
   DishNet supplies the instrument; the customer chooses the tune.

   TRANSACTION PLANE         Vouchers · sessions · accounting · revenue
```

**The sentence that replaces the three-layer model:**

> DishNet sells the control system. The customer decides how to operate their network. The
> uplink is the customer's and is never DishNet's to ration.

---

## 3. What still gets validated — and why it is not a ceiling

Removing the commercial ceiling does not mean the plan editor accepts anything. Two kinds
of bound remain, and neither is a purchase limit:

| Bound | Example | Why |
|---|---|---|
| **Protocol validity** | A rate-limit value the RADIUS attribute can express | Invalid values fail or behave unpredictably on the router |
| **Device capability** | A hAP ax lite has finite RAM; hotspot users and queues are not unlimited | Physics of the hardware DishNet provisioned |

**The distinction matters commercially.** *"You cannot set that — you did not buy it"* is a
ceiling and is now wrong. *"That router cannot hold 5,000 simultaneous hotspot users"* is a
fact about hardware and stays. The plan editor validates against **what the equipment can
do**, never against **what was purchased.**

---

## 4. C11 reframed

**Old:** *How much bandwidth/concurrency/data does DishNet allow?* — withdrawn.

**New:** **What does the customer purchase from DishNet to obtain and operate the HotSpot
platform?**

This is a SaaS pricing question. The options:

| | **A Per router** | **B Per site** | **C Per voucher** | **D Revenue share** | **E Flat tiers** | **F Free w/ DishNet uplink** |
|---|---|---|---|---|---|---|
| **DishNet sells** | Each managed router | Each location | Each voucher issued or redeemed | % of customer retail | Starter / Pro / Business | Platform free if connectivity bought from DishNet |
| **Scales with** | Estate size | Footprint | **Their actual trade** | **Their actual revenue** | Chosen tier | Connectivity spend |
| **Customer can predict the bill** | ✓✓ Exactly | ✓✓ | ✗ Varies monthly | ✗ Varies | ✓✓ Fixed | ✓✓ |
| **Aligned with customer success** | ✗ Quiet and busy pay alike | ✗ | ✓✓ | ✓✓ Strongest | ✗ Within a tier | ✓ |
| **Needs DishNet to see retail revenue** | No | No | Partly — counts, not prices | **Yes — prices too** | No | No |
| **Where the limit bites** | Adding a router | Adding a site | **Nowhere — usage-priced** | Nowhere | Tier boundary, at admin time | — |
| **Does a guest ever feel it** | **No** | **No** | **No** | **No** | **No** | **No** |
| **Metering to build** | Trivial — count rows | Trivial | Moderate — meter and bill per event | **Hard — needs trustworthy retail figures** | Trivial | Trivial |
| **Works for BYO-Starlink customer** | ✓ | ✓ | ✓ | ✓ | ✓ | **✗ — nothing bought to bundle** |
| **Easiest to quote** | ✓✓ | ✓✓ | ✗ | ✗ | ✓✓ | ✓ |

### 4.1 Notes the table cannot hold

**C — per voucher.** Aligns DishNet with the customer's trade, but it **taxes the act of
issuing.** A hotel handing free vouchers to loyalty guests would pay DishNet for giveaways,
and the obvious workaround — issuing fewer, longer vouchers, or off-platform — degrades the
product's own data.

**D — revenue share.** The strongest alignment and the hardest to operate. It requires
DishNet to see, and trust, prices the customer sets. If guests pay cash at reception,
DishNet cannot verify anything, and auditing a customer's takings sits badly with
*"the customer's business is their own"* — the principle this whole correction rests on.

**F — free with DishNet connectivity.** Coherent as a bundle strategy and **fails exactly
the case that prompted this correction.** A hotel on its own Starlink buys no connectivity,
so there is nothing to bundle. Viable only as one arm of a two-arm strategy (§6.1).

### 4.2 A property worth noticing

**Every option above binds at admin time. None is felt by a guest.**

Under the old entitlement, exhaustion hit a paying guest at the moment of login (docs/49
§6 — *"10 paying guests refused"*). Under platform-scope pricing, a limit is reached by the
**customer**, in their own admin screen, when adding a router or a site.

That is strictly better, and it is a free consequence of your correction rather than
something that had to be designed. Worth adopting as a rule:

> **No DishNet commercial limit should ever be experienced by a guest.**

---

## 5. Riverside under the new model

```
Starlink  ──▶  Riverside's Internet  ──▶  MikroTik  ──▶  DishNet HotSpot platform  ──▶  Guests
   └─ Riverside's contract, Riverside's bill, Riverside's capacity decision
```

**DishNet sells Riverside:** the platform — 2 routers under management, 2 sites, 3 operator
seats, portal branding, reports. *(Shape depends on C11; scale does not depend on Mbps.)*

**Riverside decides, with no DishNet ceiling:**

| Retail product | Price | Speed | Duration | Devices |
|---|---|---|---|---|
| 1 Hour | UGX 2,000 | 5 Mbps | 1 h | 1 |
| 6 Hours | UGX 5,000 | 8 Mbps | 6 h | 2 |
| Day Pass | UGX 8,000 | 10 Mbps | 24 h | 2 |
| VIP | UGX 15,000 | **20 Mbps** | 24 h | 4 |

The platform accepts all four. **20 Mbps is not checked against anything DishNet sold**,
because DishNet sold no bandwidth. It is checked only for protocol validity and device
capability (§3).

**Saturday night, 30 guests, Starlink congested.** Everyone slows. That is Riverside's
capacity decision playing out on Riverside's uplink. DishNet's platform:

- **shows** Riverside the congestion, per site, with session counts and throughput
- **offers** tools — a concurrent-session cap, lower plan speeds, fewer devices per voucher
- **does not** refuse anyone on DishNet's behalf, because DishNet is not the constraint

**Measure and inform. Never gate.** That is the whole posture in three words.

---

## 6. Consequences worth stating

### 6.1 DishNet's revenue shape changes

If a hotel's uplink is Starlink bought directly from Starlink, **DishNet earns nothing on
connectivity. The platform subscription is the entire revenue from that customer.**

This creates two customer shapes that will price differently:

| | Uplink from DishNet | Own uplink (Starlink/other) |
|---|---|---|
| DishNet earns | Connectivity **+** platform | **Platform only** |
| Option F works | ✓ | ✗ |
| Addressable market | DishNet's coverage area | **Anywhere Starlink reaches** |

The second column is the larger opportunity and the one with no connectivity revenue to
fall back on. **C11 should be answered for that customer**, not the bundled one.

### 6.2 Support demarcation is now load-bearing

DishNet cannot fix a congested Starlink link it did not sell. But a guest complaining of
slow Wi-Fi will be complaining about a portal that says **"Powered by DishNet."**

Needs an explicit demarcation — DishNet supports the platform, the router and the tunnel;
the customer owns the uplink — and the portal wording probably needs revisiting so DishNet
is not implicitly warranting someone else's Starlink. Raised as **C17**.

### 6.3 What happens to docs/49

Its **§2 survives and gains value.** The four enforcement mechanisms and their status are a
capability inventory, and that is exactly what the Enforcement Plane needs. In particular
concurrency is still **not built** — but its meaning has inverted:

| | Old role | New role |
|---|---|---|
| Concurrency | **DishNet's ceiling** on the customer | **The customer's own tool** to protect their uplink |
| Priority | Commercial blocker | Optional customer feature |
| C8′ link | Stale rows refuse guests for DishNet's limit | Stale rows refuse guests for **the customer's own** limit — still a bug, lower stakes |

docs/49 §§3–7 are superseded: they compare entitlements DishNet no longer sells.

---

## 7. New questions

| # | Question |
|---|---|
| **C17** | Support demarcation and portal wording — what is DishNet warranting on a customer-owned uplink? (§6.2) |
| **C18** | Does the platform measure the uplink at all? Visibility is valuable and is not control — but it means reading a link DishNet does not own |
| **C19** | Is there a free or trial tier? Relevant because F fails for BYO-uplink customers, who are the larger market |
| **C20** | Does DishNet still supply the MikroTik hardware, or may a customer bring their own? This changes §3's device-capability bound from a known quantity to an unknown one |

**C20 may be the most consequential.** Every assumption about device capability, provisioning
and zero-touch rests on DishNet knowing the hardware. A bring-your-own-router customer breaks
that, and the reseller model makes such a customer plausible for the first time.

---

## 8. What I have not done

No pricing model chosen. A–F are presented with costs; §4.2 states a structural property, not
a preference.

§1.1 is evidence: the architecture's silence on uplink was verified across docs/30, 31, 41
and 42 rather than assumed. §3 draws a line I think matters — validity versus ceiling — and
if you read it differently, that changes the plan editor.

**No production system was contacted. No prototype, schema or architecture was changed.**

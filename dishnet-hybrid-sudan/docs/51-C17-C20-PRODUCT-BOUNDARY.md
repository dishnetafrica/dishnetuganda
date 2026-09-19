# 51 — C17–C20: Hardware, Support, Measurement, Trial

**Status:** DECISION ANALYSIS — no decision made
**Builds on:** docs/50 (four planes, uplink outside them)
**Changes made:** NONE. No code, schema or prototype change.

---

# C20 — Hardware model

Taken first, because it determines whether the product is a **managed-MikroTik platform**
or a **MikroTik management platform**, and those are different companies.

## 1. What the architecture actually assumes

Not inferred — read from docs/31 §3.1, the staging procedure that "becomes the product":

```
 1. Unbox. Record serial, model, RouterOS version.
 2. Upgrade RouterOS to the standard version.
 3. Set identity.
 4. Create the management user with a generated password.
 5. Generate a WireGuard keypair ON THE ROUTER. Private key never leaves.
    Register the PUBLIC key plus the serial.
 6. Add the gateway as a peer, with keepalive.
 7. Address the tunnel.
 8. Firewall: gateway in over the tunnel only; management NOT reachable from WAN.
 9. Install the watchdog.
10. /export file=staged  → the rollback target.
11. Register: serial, model, version, WG public key, tunnel /32, staged-at, staged-by.
12. Power off. Ship.
```

**All twelve steps require physical possession.** The product does not merely prefer
DishNet-supplied hardware — its trust model is *established* during those twelve steps.
docs/31 §3.1 names it:

> *"hardware identity + DishNet-issued identity + management key"*

### 1.1 The three assumptions that break

| Assumption | Where | Why BYO breaks it |
|---|---|---|
| **Trust is anchored at staging** | §3.1 step 5, 11 | Serial and WG public key are registered *by DishNet, with `staged-by` recorded*. Under BYO the customer asserts both, and DishNet can verify neither |
| **The device starts known-clean** | §3.1 step 1–2 | A BYO router arrives with unknown history: existing users, schedulers, scripts, VPNs. `/export` does not reveal everything (files, certificate material, some binary state) |
| **`staged.rsc` is a valid rollback target** | §3.3 | **The watchdog runs `/system/reset-configuration run-after-reset=staged.rsc`.** On a BYO device this either cannot be installed, or fires and **destroys the customer's own configuration** — a safety mechanism turned into a hazard |

### 1.2 The one that is easy to miss

docs/31 §7's compatibility matrix is **deliberately empty** — *"nothing here is pre-filled,
because a guess in this table is worse than a blank."* Capability is established per
**model × RouterOS version**, by testing.

Under BYO, a customer's model may have **no row**. docs/50 §3 said the plan editor validates
against device capability — but with BYO, for an untested model, **DishNet does not know the
capability envelope.** The validity check docs/50 relies on has nothing to check against.

### 1.3 A security consequence to verify, not assume

Staging step 6 gives the router `allowed-address=10.66.0.0/16` — the management network,
where FreeRADIUS listens on `10.66.0.1:1812/1813` (docs/36). A device DishNet did not stage
is a peer inside that plane whose provenance is asserted rather than established.

Mitigation exists — tighten per-peer `allowed-ips` at the gateway, firewall RADIUS per
peer — but **I have not audited the gateway's current per-peer restrictions**, so this is a
question to answer, not a vulnerability I am claiming. Under DishNet-supplied hardware it is
moot; under BYO it must be settled before the first device.

## 2. The three options

| | **A — DishNet-supplied only** | **B — Customer BYO** | **C — Certified models** |
|---|---|---|---|
| **Zero-touch provisioning** | ✓✓ Works as designed | ✗ **No staging step exists.** Needs a new remote-adoption path | ✓ Works if the customer ships it to DishNet, or a field tech stages it |
| **WireGuard identity** | ✓✓ Key generated on-device by DishNet, registered with `staged-by` | ✗✗ **The trust anchor disappears.** Customer asserts serial + key | ✓ Same as A once in DishNet's hands |
| **Device capability validation** | ✓✓ Matrix row exists | ✗ **Unknown model = no envelope** (§1.2) | ✓✓ Certification *is* filling the matrix row |
| **RouterOS versions** | ✓✓ Standardised at staging | ✗ Whatever arrives; v6→v7 may need an intermediate hop | ✓ Minimum version is part of certification |
| **Support** | ✓✓ Known unit, known history | ✗✗ Unknown history; *"was it already broken?"* is unanswerable | ✓ Known model, history still unknown |
| **Staging** | Cost per unit, borne by DishNet | None — **and that is the problem** | Cost per unit, or a certification programme |
| **Reseller model** | ✗ Hardware purchase is a barrier to entry | ✓✓ Lowest barrier — widest market | ✓ Middle |
| **Security** | ✓✓ Clean provenance | ✗✗ §1.1, §1.3 | ✓ Model known, provenance still asserted |
| **Onboarding** | Slow — ship hardware | **Fast — the customer already has a router** | Medium |
| **Future scale** | Bounded by logistics and capital | Bounded by support cost | Bounded by the certification programme |

## 3. What would have to change for BYO

Not a configuration switch. Each of these is design work that does not currently exist:

1. **A remote adoption path** replacing steps 1–12. The customer runs something, or gives
   DishNet temporary access, and a tunnel plus registry entry results.
2. **A trust-establishment step that does not rely on possession.** Some out-of-band proof
   binding *this* device to *this* customer — the hard part, and the reason BYO is a
   different product rather than a looser version of this one.
3. **A pre-adoption audit** — what is already on the device, and what must be removed. Note
   `/export` is not sufficient (§1.1).
4. **A watchdog that is safe on a device DishNet did not stage** (§1.1), or an explicit
   decision to run BYO devices without a watchdog — accepting the lock-out risk the
   watchdog exists to prevent.
5. **A capability-discovery step** replacing the matrix row (§1.2).
6. **Per-peer network restriction** at the gateway (§1.3).
7. **A support boundary for the device itself**, not only the uplink — which is C17 widened.

**C is the option that preserves the architecture** while lowering the hardware barrier:
certification fills the matrix row, and staging still happens in DishNet's hands. It does
not solve onboarding speed, because the device still has to reach DishNet.

---

# C17 — Support demarcation

## 4. The boundary

| Layer | Owner | DishNet supports |
|---|---|---|
| **Uplink** — Starlink / Fiber / LTE | **Customer** | ✗ No. Not sold, not controlled, not measured for warranty |
| Physical siting, power, cabling | Customer | ✗ Advice only |
| **MikroTik router** | DishNet (under A/C) | ✓ Provisioning, config, firmware, replacement |
| **WireGuard tunnel** | DishNet | ✓ |
| **HotSpot / RADIUS / AAA** | DishNet | ✓ |
| **Voucher engine, plans, portal** | DishNet | ✓ |
| **Customer PWA, reporting** | DishNet | ✓ |
| **Retail policy** — prices, speeds, durations | **Customer** | ✗ Advice only; the platform enforces whatever they choose |
| **Guest experience** | **Shared, and this is the hard one** | See §4.1 |

### 4.1 *"The internet is slow"* — the case that decides the boundary

The most common complaint has a cause on either side of the line. It is only answerable
with **measurement**, which is why C17 and C18 are one question:

| Symptom | Likely cause | Whose |
|---|---|---|
| Slow for everyone, uplink saturated | Uplink congested, or oversold | **Customer's** |
| Slow for everyone, uplink idle | Router, config, or platform | **DishNet's** |
| Slow for one guest | Their plan's speed limit — as the customer set it | **Customer's policy, working** |
| Nobody can log in, tunnel down | Tunnel or RADIUS | **DishNet's** |
| Nobody can log in, tunnel up | Voucher/plan policy | Customer's policy |

**Without C18, row 1 and row 2 are indistinguishable**, and every slow-Wi-Fi complaint
escalates to DishNet by default. Measurement is not a nice-to-have here; it is what makes
the support boundary operable.

## 5. Proposed customer-facing wording

The current portal reads **"Riverside Hotel Wi-Fi / Powered by DishNet."**

*"Powered by"* claims the internet. On a customer's own Starlink, that is a warranty DishNet
cannot honour — and it invites the guest to blame DishNet for a link DishNet did not sell.

**Three options, least to most change:**

| | Portal shows | Comment |
|---|---|---|
| **1** | *"Riverside Hotel Wi-Fi"* · small: **"HotSpot managed by DishNet"** | Minimal change. *Managed* is narrower than *powered* and closer to true |
| **2** | *"Riverside Hotel Wi-Fi"* only — **white-label** | See §5.1 |
| **3** | White-label by default, DishNet badge as an opt-in | Combines them |

### 5.1 White-labelling fits the reseller model better than co-branding

Worth raising even though it was not asked: in a reseller product the guest is **Riverside's
customer, not DishNet's**. DishNet branding on that portal is a vestige of the
managed-service framing this correction moved away from. Removing it:

- ends the brand exposure in C15 and §5 outright, rather than softening the wording
- matches what the customer is actually buying — *their* hotspot business
- is a natural **paid tier feature**, which feeds C11 and C19

Against: DishNet loses free distribution at the point of sale, which for a platform sold to
new hotspot operators is not nothing.

**Not a decision I should make** — it trades legal exposure against marketing reach.

### 5.2 Terms wording

Proposed, for review rather than adoption:

> *"DishNet provides and supports the HotSpot management platform, the managed router and
> the secure management connection. The internet connection at this location is provided
> and controlled by [Customer], who is responsible for its speed, availability and capacity.
> Access plans, prices and speed limits are set by [Customer]."*

---

# C18 — Uplink measurement

## 6. Four postures

| | **A Don't measure** | **B Measure + display** | **C Measure + alert** | **D Measure + enforce** |
|---|---|---|---|---|
| Customer sees congestion | ✗ | ✓ | ✓ Proactively | ✓ |
| Support can attribute a fault (§4.1) | ✗✗ **Cannot** | ✓✓ | ✓✓ | ✓✓ |
| Customer can act before guests complain | ✗ | Partly — if they look | ✓✓ | — |
| DishNet controls customer capacity | No | No | No | **✗ Yes — violates the principle** |
| Reads a link DishNet does not own | No | **Yes** | Yes | Yes |
| Build cost | None | Low — the router already sees it | Medium — thresholds, delivery | High |

**D is excluded by the principle you set.** *Measure and inform. Never gate.*

**A makes C17 unworkable** (§4.1). It is the only option that costs nothing now and costs
every support call later.

**The real question is B versus C**, and it is smaller than it looks: C is B plus a
threshold and a delivery channel.

### 6.1 The question inside C18 that is not about measurement

Measuring the uplink means reading traffic on a link the customer owns. It is *their* router
reporting *their* link, so consent is implicit in buying the platform — but it should be
**stated**, not assumed, and the boundary should be explicit: **throughput and utilisation,
never destinations or content.** That distinction belongs in the terms before the feature is
built, not after.

---

# C19 — Free / trial tier

## 7. Why it matters more for BYO-uplink

For a BYO-Starlink customer, the platform subscription is **the entire revenue** (docs/50
§6.1). So a free tier is not a discount on a bundle — it is revenue foregone with nothing
behind it.

| | **A No free tier** | **B Time-limited trial** | **C Free forever, limited** | **D Free tier only with DishNet hardware** |
|---|---|---|---|---|
| Revenue risk | None | Low — it ends | **Highest** — permanently free users cost support | Low |
| Onboarding friction | **Highest** | Low | Lowest | Medium |
| Fits BYO market | ✗ Hardest sell where DishNet is unknown | ✓✓ | ✓✓ | ✗ Excludes the BYO customer |
| Support cost of free users | — | Bounded | **Unbounded** | Bounded |
| Natural limit to use | — | Days | Routers? sites? vouchers? — **needs C11 first** | — |

**C19 cannot be answered before C11.** A "free tier" must be free *of something*, and what
that something is (routers, sites, vouchers, seats) is exactly what C11 decides. Attempting
C19 first produces a limit with no unit.

**Under C20=A** (DishNet hardware only), the hardware purchase is already a commitment
filter, and a trial matters less. **Under C20=B/C**, a customer can be running in an hour,
and a trial is close to essential — which is another way C20 precedes everything.

---

# C11 — what is still needed before choosing a pricing model

Not a pricing recommendation. The inputs C11 lacks:

| From | What C11 needs |
|---|---|
| **C20** | **Is hardware part of what is sold?** If yes, pricing may be hardware + subscription, and "per router" means something DishNet supplied. If BYO, the subscription is the whole product and per-router pricing meters someone else's asset |
| **C20** | **What is the unit of scale?** Per-router pricing presumes DishNet knows the routers. Under BYO that count is customer-declared |
| **C17** | **What is being warranted?** Price follows obligation. A platform-only support scope prices differently from one implying guest experience |
| **C18** | **Is measurement a feature or a baseline?** If C18=C, alerting is a tier feature and shapes the tier ladder |
| **C19** | Trial shape — but C19 depends on C11 (§7). **Decide C11's *unit* first, then C19, then C11's *price*** |
| **C13** | Portal white-labelling (§5.1) is a classic tier boundary |

**The ordering that resolves the circularity:** C20 → C17 → C18 → **C11 unit** → C19 →
C11 price.

---

# A. What is already architecturally frozen

Unaffected by everything above:

| | Status |
|---|---|
| Domain A / Domain B separation (docs/41) | **Frozen, binding** |
| The intent model — no synchronous router commands (docs/42 §0) | **Frozen** |
| Server-derived authorization; no client-supplied identity (docs/45) | **Frozen** |
| Three experiences: Admin, Customer PWA, Captive portal (docs/46) | **Frozen** |
| Four planes; uplink outside them (docs/50) | **Frozen by this correction** |
| No DishNet commercial limit felt by a guest (docs/50 §4.2) | **Adopted as a rule** |
| Phase 0: WireGuard, FreeRADIUS, tunnel-based management | **Built, unaffected by uplink ownership** |
| Vouchers are RADIUS users (docs/32 §A) | **Settled** |

# B. What C20 = BYO changes

| | Consequence |
|---|---|
| **Trust model** | **Rebuilt.** "hardware identity + DishNet-issued identity + management key" no longer establishable by possession (§1.1) |
| **Staging (docs/31 §3.1)** | Twelve steps replaced by a remote adoption path that does not exist |
| **Watchdog (docs/31 §3.3)** | **Unsafe as written** — would reset a customer's device to a `staged.rsc` DishNet never wrote |
| **Compatibility matrix (docs/31 §7)** | Becomes discovery at adoption, not certification in advance |
| **docs/50 §3 validity check** | Has no capability envelope for an untested model |
| **Gateway network exposure** | Per-peer restriction must be settled first (§1.3) |
| **Support (C17)** | Widens from *uplink* to *uplink + device history* |
| **Product identity** | Managed-MikroTik platform → MikroTik management platform |
| **Market** | Narrow and controlled → broad and heterogeneous |
| **Phase 0 test protocol** | Still valid for A and C. For B it tests a path the product no longer uses |

# C. Decisions needed before UX

| | Why |
|---|---|
| **C20** | Determines whether the PWA has an "add your router" flow at all |
| **C6 + C16** | Roles and where staff accounts live — blocks the PWA |
| **C11 unit** | The plan editor and any headroom display need a unit to show |
| **C18 (B or C)** | Decides whether an uplink panel exists |
| **C9** | Portal as login page or shop |

# D. Decisions needed before implementation

| | Why |
|---|---|
| **C20 + §1.3** | Gateway per-peer restriction, before any non-DishNet device connects |
| **C8′** | Tunnel-down behaviour — Phase 0 data |
| **C12** | Suspension and guests holding paid vouchers |
| **C14** | Merchant of record, if C9=B — **legal advice, not a product decision** |
| **C13** | Portal customisation bounds — a security boundary, not styling |
| **C11 full** | Billing cannot be built against an undecided unit |

---

## What I have not done

No decision made on C17, C18, C19, C20 or C11. §1 and §B are evidence read from docs/31 and
docs/36; §1.3 is flagged as unverified because I have not audited the gateway's per-peer
rules. §5.1 and §5.2 are proposals for review, not adopted wording.

**No production system was contacted. No prototype, schema or architecture was changed.**

# 43 — Competitive UX / Product Teardown

**Purpose:** understand how established MikroTik hotspot platforms organise the operator's
work, then decide what DishNet should adopt, improve or deliberately avoid.
**Not** to copy any product's code or visual design.
**Status:** Research deliverable. No code, no Figma, no migrations, no architecture change.
docs/42 is **not modified** by this document.
**Date:** 2026-09-19

---

## 1. Evidence table — what was actually accessible

**This environment's egress proxy blocked every direct page fetch.** `play.google.com`,
`apps.apple.com`, `icubeug.net` and `hotbill.app` all returned `EGRESS_BLOCKED`. Everything
below comes from **web search result summaries**, which return vendor marketing copy and
store-listing text.

| Product | Evidence obtained | Screens seen | Confidence |
|---|---|---|---|
| **MikroTicket** | Store description, feature list, version + changelog | **NONE** | Feature list: high. Screens: zero |
| **Mikhmon** | Third-party descriptions, Docker/GitHub refs | **NONE** | Medium — community summaries, not official docs |
| **MikroRadius** | Vendor site copy | **NONE** | Medium — marketing claims |
| **Powerlynx** | Vendor site copy, docs/FAQ refs, blog titles | **NONE** | Medium |
| **Hotspot Uganda** | Vendor site copy | **NONE** | Medium |
| **Shiftnet** (surfaced, not in brief) | Vendor site copy | **NONE** | Medium |
| **iCube** | **NOT FOUND** — did not appear in any search | — | **Unverified** |
| **HotBill** | **NOT FOUND** — did not appear in any search | — | **Unverified** |

### Two corrections to the brief

1. **iCube and HotBill could not be verified.** The brief cited `icubeug.net` and
   `hotbill.app` with specific claims. Neither domain was reachable and neither appeared in
   search results. The claims may well be accurate — they are simply **not corroborated
   here**, and this document will not restate them as findings.
2. **No screenshots were obtained for any product.** Every statement below describes a
   **claimed feature**, never an observed screen. The brief's instruction —
   *"Do not claim to have inspected a screen if the source does not actually expose that
   screen"* — means almost everything here is a feature claim, not a UX observation.

### The distinction this document holds throughout

> **A feature list is not a screen inventory.** "Sales reports" tells us a capability
> exists. It does not tell us whether it is a screen, a tab, a filter, or a PDF export.
> Inferring layout from a bullet point is how you end up designing a competitor's
> marketing copy rather than their product.

---

## 2. MikroTicket — deep study

**Source:** Google Play store listing, retrieved via search 2026-09-19.
Version **3.0.131**, last updated **11 September 2026**. Publisher markets it to
*"entrepreneurs, technicians, or businesses that want to sell internet access using Hotspot
tickets with Mikrotik routers."*

### 2.1 Feature claims — VERIFIED FROM SOURCE

Quoted or closely paraphrased from the listing:

| Area | Claim |
|---|---|
| Tickets | *"Generate time- or data-based access tickets for cafés, WiFi zones, hostels"* |
| Ticket lifecycle | *"Tickets are automatically deleted after their usage time ends—no manual work needed"* |
| Plans | *"Create internet plans for elapsed or paused time"* |
| Printing | *"Supports Bluetooth and TCP/IP printers for instant ticket printing"* |
| Formats | *"Professional formats ready for PDF printing (A4/A3) or digital sharing with QR codes"* |
| Sales | *"Visualize your income with smart charts filtered by date, plans, and operators"* |
| Operators | *"Create operator accounts with custom access permissions"* |
| Remote access | *"Manage your routers and create tickets from anywhere in the world. Also supports Winbox for advanced control"* |
| Captive portal | *"Design, preview, and publish fully customized captive portal templates easily"* |
| Platform | *"Manage everything from your PC or mobile device"* |

### 2.2 Changelog — VERIFIED FROM SOURCE

Recent versions added:

- **search bar in the routers screen**
- **deletion of active sessions**
- **validity limit for paused time plans**
- video component in templates
- redirect URL in templates

**This is the most informative evidence in the whole teardown.** A mature product at
version 3.0.131 adding a *search bar to the routers screen* tells us operators are managing
enough routers that scanning a list stopped working. That is a scale signal, not a feature
signal.

### 2.3 The twenty areas the brief asked about

| # | Area | Status | What the evidence supports |
|---|---|---|---|
| 1 | Login / account | **UNKNOWN** | Not described |
| 2 | Dashboard | **UNKNOWN** | No dashboard is mentioned anywhere in the listing |
| 3 | Router management | **VERIFIED** (exists) | A "routers screen" exists — confirmed by the changelog adding search to it |
| 4 | Add router | **INFERRED** | Must exist for the product to function. No detail |
| 5 | Router details | **UNKNOWN** | Not described |
| 6 | Plans | **VERIFIED** | Time-based, data-based, elapsed, paused |
| 7 | Create plan | **INFERRED** | Implied by "create internet plans" |
| 8 | Tickets | **VERIFIED** | Core of the product |
| 9 | Generate batch | **INFERRED** | Printing A4/A3 implies multiple per sheet. Batch as an *object* is not evidenced |
| 10 | Ticket inventory | **UNKNOWN** | No "inventory" or registry concept described |
| 11 | Ticket activation | **INFERRED** | Auto-deletion after usage implies tracked activation |
| 12 | Active sessions | **VERIFIED** | Changelog: *"deletion of active sessions"* |
| 13 | Disconnect / delete session | **VERIFIED** (the action exists) | **Mechanism UNKNOWN** — see §2.4 |
| 14 | Sales | **VERIFIED** | Charts by date, plan, operator |
| 15 | Reports | **VERIFIED** | Same as sales; no separate reporting module evidenced |
| 16 | Operators / users | **VERIFIED** | Operator accounts exist |
| 17 | Permissions | **VERIFIED** | *"custom access permissions"* — granularity UNKNOWN |
| 18 | Captive portal | **VERIFIED** | Design → preview → publish; video and redirect-URL components |
| 19 | Printing | **VERIFIED** | Bluetooth, TCP/IP, PDF A4/A3 |
| 20 | QR tickets | **VERIFIED** | QR for digital sharing |
| 21 | Remote access | **VERIFIED** (exists) | *"from anywhere in the world"*, Winbox supported. **Transport UNKNOWN** |

**Nine of twenty-one areas are UNKNOWN or INFERRED.** Notably, **no dashboard is mentioned
at all** — which may mean it has none, or simply that the listing doesn't sell it.

### 2.4 The inference trap the brief warned about

> *"Disconnect session exists" does NOT prove "CoA is used."*

Correct, and it matters. MikroTicket's *"deletion of active sessions"* could be implemented
as: RouterOS API removing the active host; RADIUS Disconnect-Message; CoA; or removing the
hotspot user so the next re-auth fails. **The listing distinguishes none of these.**

The same applies to *"manage your routers from anywhere in the world"*. That could be a
cloud relay, a VPN, a public IP with port forwarding, or a router-initiated outbound
connection. **Transport is UNKNOWN**, and it is precisely the question B1 exists to answer
for DishNet.

**No competitor's architecture may be inferred from its buttons.**

---

## 3. Competitor comparison

**Legend:** ✓ claimed by vendor · ? unknown/not described · — not applicable ·
**U** = unverified (source unreachable)

| | MikroTicket | Mikhmon | MikroRadius | Powerlynx | Hotspot UG | Shiftnet | iCube | HotBill | **DishNet proposed** |
|---|---|---|---|---|---|---|---|---|---|
| Dashboard | ? | ✓ | ✓ | ? | ✓ | ✓ | **U** | **U** | ✓ |
| Router management | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | **U** | **U** | ✓ |
| Router provisioning | ? | — | ? | ? | ? | ✓ script | **U** | **U** | **✓ zero-touch** |
| Remote management | ✓ | ? | ✓ API | ✓ | ✓ | ✓ | **U** | **U** | ✓ via WireGuard |
| WireGuard | ? | — | ? | ? | ? | ? | **U** | **U** | **✓ core** |
| RADIUS | ? | **✗ explicitly not** | ✓ | ✓ | ✓ | ✓ cloud | **U** | **U** | **✓ core** |
| HotSpot | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | **U** | **U** | ✓ |
| Plans | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | **U** | **U** | ✓ |
| Vouchers | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | **U** | **U** | ✓ |
| Voucher batches | ? | ? | ✓ registry | ? | ? | ✓ | **U** | **U** | ✓ |
| QR codes | ✓ | ? | ✓ | ? | ? | ? | **U** | **U** | ✓ |
| Printing | ✓ BT/TCP/PDF | ✓ | ✓ | ✓ | ? | ? | **U** | **U** | ✓ |
| Active sessions | ✓ | ✓ | ✓ | ? | ✓ | ? | **U** | **U** | ✓ gated |
| Disconnect | ✓ | ? | ✓ "kick" | ? | ? | ? | **U** | **U** | **gated** |
| Accounting | ? | ✓ usage | ✓ | ✓ | ✓ | ✓ | **U** | **U** | ✓ RADIUS |
| Payments | ? | ? | ? | ✓ 11 gateways | ✓ | ✓ | **U** | **U** | **✗ out of scope** |
| Mobile Money | ? | ? | ? | ✓ MTN/M-PESA | ✓ | ✓ | **U** | **U** | **✗ gap** |
| Resellers / agents | ✓ operators | ? | ? | ? | ? | **✓ commissions** | **U** | **U** | ✓ role |
| Roles | ✓ | ? | ? | ? | ? | ✓ | **U** | **U** | ✓ |
| Multi-tenancy | ? | ✗ single | ? | ✓ multi-site | ? | ✓ provider view | **U** | **U** | **✓ core** |
| Captive portal editor | **✓** | ? | ✓ branding | **✓ white-label** | ? | ? | **U** | **U** | **✗ gap** |
| Alerts | ? | ? | ? | ? | ? | **✓ live status** | **U** | **U** | ✓ |
| Audit | ? | ? | ? | ? | ? | ? | **U** | **U** | ✓ |
| Reports | ✓ charts | ✓ | ✓ | ✓ | ✓ | ✓ | **U** | **U** | ✓ |
| Remote configuration | ✓ Winbox | ? | ✓ API | ? | ✓ | ✓ script | **U** | **U** | ✓ intents |
| Offline router handling | ? | ? | ? | ? | ? | ✓ alerts | **U** | **U** | **✓ intents survive** |
| **Intent / queue model** | **?** | **?** | **?** | **?** | **?** | **?** | **U** | **U** | **✓ UNIQUE** |

### 3.1 The two findings that matter most

**Finding 1 — no competitor evidences an intent/queue model.**

Every product that describes remote control describes it as **immediate**: MikroRadius
*"real-time user kicking"* and *"disconnect them instantly via the API bridge"*;
MikroTicket *"manage your routers… from anywhere in the world."*

The most reasonable reading is that **they assume the router is reachable** — public IP,
port-forward, or their own always-on tunnel. That assumption is available to them and is
**not available to DishNet**, whose routers sit behind Starlink CGNAT.

So there is **no prior art to copy for the intent model**. It is not a gap in our design;
it is a consequence of a harder problem. It also means we cannot borrow anyone's UX for it,
and §0 of docs/42 has to stand on its own reasoning.

**Finding 2 — Shiftnet is the closest competitor, and it is not zero-touch.**

Shiftnet: *"Plug in your MikroTik and run one generated script — authentication runs in the
cloud, with live telemetry from every router."*

That is the nearest thing in the market to DishNet's direction — cloud RADIUS, live
telemetry, agent commissions, Uganda-focused. **But "run one generated script" means a human
still touches the router.** The gap between *"run one script"* and *"plug it in and it
configures itself"* is exactly DishNet's differentiator, and it is a narrower gap than the
Zero-Touch brief assumes.

---

## 4. Adopt / Improve / Avoid

### A. ADOPT — proven, useful, low risk

| Pattern | Evidence | Why |
|---|---|---|
| **Sales charts filtered by date, plan and operator** | MikroTicket | Three filters that match how a hotspot business actually asks questions. docs/42 §14 should adopt exactly these three |
| **Operator accounts with custom permissions** | MikroTicket, Shiftnet | Validates docs/42's role model |
| **Router list search** | MikroTicket changelog | Added at v3.0.x — they hit the scale where lists fail. Build it in, don't retrofit |
| **QR + printed voucher in one artefact** | MikroTicket, MikroRadius, Powerlynx | Universal. A voucher must be scannable *and* readable |
| **Thermal/Bluetooth printing** | MikroTicket | Physical slips at a counter; a PDF is not enough |
| **Voucher registry with search over large volumes** | MikroRadius (*"millions of codes"*) | Confirms voucher search is a first-class destination, not a filter |
| **Agent portal: assigned codes, recorded sales, commission, branch** | Shiftnet | The Uganda reseller pattern, more concrete than docs/42's |
| **Live router/port status with alerts** | Shiftnet | Validates the Needs Attention band |
| **Dashboard = revenue AND health together** | Hotspot UG, MikroRadius, Shiftnet | Three independent products converge. Answers the brief's first navigation question |

### B. IMPROVE — exists, DishNet should do better

| Pattern | Who | DishNet improvement |
|---|---|---|
| **Immediate-action framing** | MikroRadius *"instantly"*, MikroTicket | We cannot promise immediacy behind CGNAT — and neither can they, honestly, when a router is offline. **The intent model is more truthful even where push works** |
| **"Router status" as a binary** | Hotspot UG, Shiftnet | Online/offline hides *provisioning* state. Our six-state stepper says where a router is in its life, not just whether it answers |
| **Tickets auto-deleted after use** | MikroTicket | Good hygiene, bad auditability — deleted tickets cannot be reconciled against revenue. **Expire, don't delete** |
| **Sales reporting as charts** | MikroTicket, Hotspot UG | Charts answer "how much". Operators also need "which batch, which agent, which router" — reconciliation, not just visualisation |
| **Flat operator permissions** | MikroTicket | No evidence of tenant isolation. Ours is a hard boundary, enforced server-side |
| **Mobile money as a payment feature** | Hotspot UG, Shiftnet, Powerlynx | Shiftnet's framing is better — *"reconcile to the shilling"*, withdrawable balance. Treat money as **reconciliation**, not a checkout button |

### C. AVOID

| Anti-pattern | Where | Why avoid |
|---|---|---|
| **Winbox/SSH passthrough in the product** | MikroTicket, Hotspot UG | Directly contradicts *"the operator should never need to understand RouterOS"*. It also creates an unauditable side channel around the intent queue — anything done in Winbox is invisible to our audit log |
| **Deleting records after use** | MikroTicket tickets | Destroys the audit trail. Never delete a voucher, session or sale |
| **"Instant"/"real-time" language** | MikroRadius | Sets an expectation the network cannot keep. Show state, not promises |
| **Dashboard as a metric wall** | Hotspot UG lists ~8 tiles | Already guarded in docs/42 §5: every element must answer "and then what?" |
| **Vendor breadth as a goal** | Powerlynx (6 vendors) | DishNet manages its own estate. Supporting Cambium/Nokia/Ruckus adds surface with no benefit |
| **Advertising monetisation** | Powerlynx | Out of scope, and it changes the captive portal's purpose |
| **Assuming router reachability** | All of them | The assumption DishNet cannot make. Designing as if we could would fail the first CGNAT install |

---

## 5. DishNet differentiation — honest assessment

| # | Capability | Genuinely differentiating? | Evidence |
|---|---|---|---|
| 1 | **Zero-touch provisioning** | **Partly.** Shiftnet does "one generated script" | The gap is narrower than assumed. Differentiator only if we remove the human step entirely |
| 2 | **WireGuard device identity** | **Yes, apparently.** No competitor evidences it | Unverified for iCube/HotBill |
| 3 | **Central RADIUS** | **No.** MikroRadius, Powerlynx, Hotspot UG, Shiftnet all have it | Table stakes, not a differentiator |
| 4 | **Router lifecycle** | **Yes.** No competitor evidences states beyond online/offline | Our six-state model is richer |
| 5 | **Provisioning status** | **Yes.** No competitor describes it | Follows from 1 and 4 |
| 6 | **Intent queue** | **Yes — apparently unique** | §3.1. Forced by CGNAT, not chosen |
| 7 | **Offline-router handling** | **Yes.** Others alert; we accept work that applies on return | Shiftnet alerts only |
| 8 | **Multi-tenant isolation** | **Partly.** Powerlynx multi-site, Shiftnet provider view | Ours is stricter; theirs is unverified |
| 9 | **Reseller operations** | **No — we are behind.** Shiftnet has commissions, wallets, branches | **docs/42 is thinner here than the market** |
| 10 | **Voucher commerce** | **No.** Universal | Table stakes |
| 11 | **Accounting** | **No.** Universal | Table stakes |
| 12 | **Router health** | **No.** Shiftnet has live telemetry and alerts | Table stakes |
| 13 | **Audit trail** | **Possibly.** No competitor mentions one | Weak evidence — marketing rarely sells audit |

### What this means

**Four capabilities are genuinely differentiating**: WireGuard identity, router lifecycle,
provisioning status, and the intent queue. All four descend from **one decision** —
operating routers behind CGNAT without requiring reachability.

**Six are table stakes** and must simply be good: RADIUS, vouchers, accounting, health,
commerce, dashboards.

**One is a deficit.** Shiftnet's agent model — assigned codes, recorded sales, commission
context, branch responsibility, withdrawable balance reconciled to the shilling — is
**more developed than docs/42's reseller-as-a-role**. In the Uganda market that is a
competitive gap, not a simplification.

> **Zero-touch provisioning is a weaker differentiator than the brief assumes, and reseller
> commerce is a bigger gap than docs/42 assumes.** Both are worth knowing before building.

---

## 6. Proposed final navigation — challenging docs/42

### 6.1 The brief's nine questions, answered from evidence

**Should the Dashboard contain revenue AND network health?**
**Yes.** Hotspot Uganda, MikroRadius and Shiftnet independently converge on it. Hotspot
Uganda pairs income and monthly revenue with router status and active users on one screen;
Shiftnet puts *"voucher batches, agent activity, wallet checks, and router readiness… in one
provider view."* Three products, same answer. docs/42 §5 stands.

**Should Routers be under Network or Operations?**
**Neither — top level.** MikroTicket has a routers *screen*, not a routers section. Routers
are the estate; burying them one level down adds a click to the most-visited destination.
Delete the "Network" grouping.

**Should Vouchers and Plans be together?**
**Yes — under one "Selling" section.** They are the same job: define the product, generate
the credential, sell it. MikroTicket lists Plans and Tickets adjacently and its sales charts
filter by plan. Splitting them across sections forces an operator to cross navigation to
answer "what am I selling and how much is left?"

**Should Sessions be under HotSpot?**
**No — Sessions belongs under Operations.** Sessions is a live-state screen used when
something is wrong or someone is asking. That is operational, not commercial. And with
Plans/Vouchers moved to Selling, "HotSpot" as a section has nothing distinctive left in it.

**Should Sales be separate from Vouchers?**
**No.** A sale *is* a voucher transaction. MikroTicket treats sales as a **view over
tickets** filtered by date/plan/operator, not a separate module. Sales becomes a tab of
Selling, not a section.

**Should Resellers be a role or a navigation section?**
**Role** (docs/42 decision 2 stands) — **but** §5 shows Shiftnet's agent portal is richer
than a scoped dashboard. Recommendation: keep reseller as a role, and add **Agents** as a
screen under Selling for assignment, commission and balance. That is where the deficit is.

**What belongs in Operations?**
Live and exception state: Alerts, Sessions, Intents, Provisioning.

**What belongs in Administration?**
Rarely-touched configuration: Tenants, Users, Roles, Settings, Audit Log, Profiles.

**Which screens are unnecessary?**
- **Sites** as a screen — a field on Router until a tenant has enough to need grouping
- **Usage** as a screen — a tab of Reports
- **Reseller Balances** as a screen — part of Agents
- **Router Groups** — a filter, already removed in docs/42
- **"Network" and "HotSpot" as sections** — both dissolve

### 6.2 Proposed navigation — five sections, down from seven

```
DASHBOARD

ROUTERS                  ← top level; the estate
  (list, detail, provisioning state)

SELLING                  ← the commercial job, in one place
  Plans
  Vouchers
  Batches
  Sales
  Agents                 ← NEW, from Shiftnet's pattern

OPERATIONS               ← live and exception state
  Alerts
  Sessions
  Intents
  Provisioning

REPORTS                  ← tabbed: Revenue · Usage · Sessions · Vouchers · Routers

ADMINISTRATION
  Tenants · Users · Roles · Profiles · Settings · Audit Log
```

**Changes from docs/42:** Network and HotSpot dissolved; Routers promoted; Plans, Vouchers,
Batches and Sales unified under Selling; Sessions moved to Operations; Agents added; Sites,
Usage and Reseller Balances demoted to fields or tabs.

**Result: 5 sections and 17 destinations, against docs/42's 7 sections and 21.** The most
visited screens are now one click from anywhere.

### 6.3 The captive portal gap

**MikroTicket and Powerlynx both ship a visual captive-portal editor** — MikroTicket's
changelog is actively adding video components and redirect URLs to it. **docs/42 has no
captive portal screen at all.**

This is a real gap, and it is customer-visible: the portal is the only part of the system a
guest ever sees. It is **not** MVP — it depends on Step 0 proving HotSpot configuration —
but it belongs in the navigation plan under Selling or Administration once the gate passes.
Flagged, not designed.

---

## 7. Proposed MVP screens

**No change to the approved nine.** The teardown does not justify reopening docs/42 §19 —
every competitor screen maps onto one of them, and the two genuine gaps (captive portal,
payments) are both gated or out of scope.

**One addition recommended for post-MVP priority:** **Agents**, promoted above Reports,
because §5 identifies it as the one place DishNet is behind the local market.

---

## 8. Screen priority — recommended adjustment

| # | docs/42 approved | Teardown says |
|---|---|---|
| 1 | Dashboard | Unchanged — three competitors validate the design |
| 2 | Routers | Unchanged — **add search from day one** (MikroTicket added it at v3.0.x under scale pressure) |
| 3 | Router Details | Unchanged |
| 4 | Plans | Unchanged |
| 5 | Vouchers | Unchanged |
| 6 | Voucher Batch | Unchanged — **add thermal/Bluetooth printing to scope**, not just PDF |
| 7 | Sessions | Unchanged (STEP 0) |
| 8 | Intents | Unchanged (B1) |
| 9 | Reports / Revenue | Unchanged — **adopt MikroTicket's three filters exactly: date, plan, operator** |
| 10 | — | **Agents** — new, first post-MVP |

---

## 9. What to copy conceptually

1. **Three sales filters: date, plan, operator** — MikroTicket, validated at 100K+ installs
2. **Voucher = scannable + readable + printable** on one artefact
3. **Router list search as a founding feature**, not an upgrade
4. **Agent portal shape**: assigned codes → recorded sales → commission → branch
5. **Money reconciles**: Shiftnet's *"to the shilling"* and withdrawable balance
6. **One provider view** spanning commerce and readiness — the Dashboard's job
7. **Portal preview before publish** — when the captive portal is built

---

## 10. What DishNet should deliberately do differently

1. **No Winbox/SSH passthrough.** Every competitor that offers it creates an unauditable
   side channel. Every router change goes through the intent queue so the audit log is
   complete.
2. **Never delete a record.** MikroTicket auto-deletes used tickets; we expire them. Revenue
   must remain reconcilable.
3. **Never say "instant".** Show intent state; let the network prove itself.
4. **Lifecycle, not liveness.** Six provisioning states versus the market's online/offline.
5. **Tenant isolation as a hard boundary**, enforced server-side, not a filter.
6. **Audit everything privileged.** No competitor advertises this; it is cheap now and
   impossible to retrofit.
7. **One estate, one vendor.** No multi-vendor breadth.
8. **Money as reconciliation, not checkout.**

---

## 11. Technical dependencies

| Item | Gate | Note |
|---|---|---|
| Session list | **STEP 0** | RADIUS accounting from a real router |
| Disconnect | **STEP 0** | Mechanism unproven. **No competitor's implementation tells us ours** |
| Provisioning execution | **STEP 0** | |
| Captive portal editor | **STEP 0** | HotSpot config must be proven first |
| Thermal/Bluetooth printing | none | Client-side; **READY** |
| Sales filters | none | **READY** |
| Router search | none | **READY** |
| Agents / commissions | none | **READY** — no router dependency |
| Intent delivery timing | **B1** | |
| Mobile money | out of scope | Would need its own decision |

---

## 12. Open questions

1. **iCube and HotBill are unverified.** Both were cited in the brief; neither was
   reachable. If they matter, direct access or screenshots are needed.
2. **No screenshots were obtained for any product.** Every finding is a feature claim. **A
   single set of MikroTicket screenshots would be worth more than this entire document**
   for layout, density and terminology.
3. **Is the reseller deficit real?** Shiftnet's commission/wallet model may reflect a
   market DishNet does not serve. This is a business question, not a UX one.
4. **Does DishNet need mobile money for MVP?** Three Uganda products treat it as core.
   docs/42 puts payments out of scope.
5. **Does the captive portal need an editor, or fixed branding?** Two competitors invest in
   editors. Gated on Step 0 either way.
6. **Is zero-touch worth the engineering** given Shiftnet's one-script approach is close and
   already shipping?

---

## 13. Boundary and constraints

Domain B only. No Domain A concept appears. No code, no Figma, no migrations, no tables, no
production change, no architecture change. **docs/42 is unmodified** — §6 and §8 are
*proposals* for review, not applied edits. Step 0 not started.

**Sources:** [MikroTicket (Google Play)](https://play.google.com/store/apps/details?id=com.mikroisp.apps.android.mikrotik.hotspot.voucher.ticket.mikroticket) ·
[MikroTicket (App Store)](https://apps.apple.com/us/app/mikroticket-wifi-vouchers/id6757659743) ·
[MikroRadius](https://mikroradius.com/) · [Powerlynx](https://powerlynx.app/) ·
[Hotspot Uganda](https://hotspot.ug/) · [Shiftnet](https://shiftnet.africa/) ·
[Easy-Mikhmon](https://www.easy-mikhmon.com/en)

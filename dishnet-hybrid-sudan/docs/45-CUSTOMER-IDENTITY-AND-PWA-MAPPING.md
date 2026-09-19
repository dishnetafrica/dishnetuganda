# 45 — Customer Identity Chain and the Admin ↔ Customer Mapping

**Status:** DESIGN PROPOSAL — for approval
**Depends on:** docs/41 (boundary freeze, BINDING), docs/42 (UX baseline, APPROVED)
**Artefact:** `prototype/dishnet-customer-pwa-prototype.html`
**Verification:** `prototype/verify-customer-pwa.js` — 244 checks
**Changes to production:** NONE. No migration, no table, no uCRM, no RADIUS, no router.

---

## 1. What this document settles

docs/42 specified the **operator** experience. It did not say what a *customer*
is, and it did not say how a customer's view is authorized. This document closes
that gap and records the object mapping between the two experiences.

It does not authorize any implementation.

---

## 2. The identity chain

```
Person / Company                 (a legal party — may hold several accounts)
  └── DishNet Customer           (the commercial relationship; the billing subject)
        └── Service              (what they bought: MikroTik HotSpot, Fiber, …)
              └── Site           (a physical location covered by that service)
                    └── Router   (the hardware serving that site)
                          └── Voucher   (grants HotSpot access at that site)
                                └── Device
                                      └── Session
```

### 2.1 The two identities that must never merge

| | **DishNet account** | **HotSpot user** |
|---|---|---|
| Created by | Sales / onboarding | Redeeming a voucher |
| Lives in | uCRM (commercial) | Router / RADIUS (network) |
| Identified by | Account number | Voucher code, then MAC |
| Is billed | Yes | No — the *customer* is billed |
| Can sign into the PWA | Yes | **No** |
| Survives the session | Yes | No |

A voucher creates a HotSpot user. **It does not create a DishNet account.**
A hotel guest is not a DishNet customer and never becomes one by connecting.

This is why there are **three experiences, not three products** (§6).

---

## 3. Authorization: server-derived, never client-supplied

### 3.1 The rule

> The client never names the entity it wants. It states an **intent**
> (*"my Wi-Fi service"*). The server walks the chain from the authenticated
> session and returns what that walk reaches.

### 3.2 Why "check the id belongs to them" is the wrong design

The common pattern is to accept `/customer/481`, then check ownership. That is
one forgotten check away from disclosure, and the check lives far from the route.

The design here removes the parameter instead:

```
GET /me/services      GET /me/sites      GET /me/wifi      GET /me/vouchers
```

There is **no endpoint that accepts a customer id**, so `/customer/481` →
`/customer/482` does not return another customer's data — it returns `404`,
because no such route exists. The prototype demonstrates this in the identity
chain panel: every id typed in, including the signed-in customer's *own*, gets
the same `404`.

Routers are reached **through sites**, never by router id. A router id supplied
by the client is not validated and rejected — it is never read.

### 3.2a Navigation ids are a filter, not a lookup

Selecting a location does pass a site id (`go('site','S-01')`), and an
implementer should understand exactly why that is not the pattern §3.2 rejects.

The id is applied as a **filter over the already-derived set**, never as a
lookup key against storage:

```js
const w = ask('wifi');                  // derived from the session
const st = w.sites.find(x => x.id === ARG);   // filter within what was derived
if (!st) return <not available>;        // a foreign id simply matches nothing
```

The distinction is where the id enters. In the rejected pattern the id reaches
the data layer and a later check decides whether the answer may be shown. Here
the authorized set is built first, from the session alone, and the id can only
narrow it. A foreign id is not *denied* — there is nothing for it to match.

This means the failure mode of forgetting a check is absent by construction: the
worst outcome of a bad id is an empty view of the customer's own data. §8 asserts
this with a real foreign site id (`S-07` requested while signed in as C-01).

### 3.3 What the prototype proves, and what it cannot

| Claim | Status |
|---|---|
| No screen reads an entity outside `derive()` | Demonstrated in code |
| Cross-customer isolation holds for all 3 personas | Verified, §8 |
| A forged or absent token yields nothing | Verified, §8 |
| A client-supplied site id is not honoured | Verified, §8 |
| Operational fields never reach the customer DOM | Verified, §8 |
| **The real server will behave this way** | **NOT proven — mock only** |

A client-side mock cannot provide a server guarantee. The prototype states this
on screen rather than letting a reviewer infer otherwise.

---

## 4. The projection: what a customer is not shown

The admin router object carries fields the customer has no business seeing.
`routerForCustomer()` drops them:

| Field | Admin | Customer |
|---|---|---|
| Name, online state, device count | ✅ | ✅ |
| Serial number | ✅ | ❌ |
| WireGuard address / tunnel state | ✅ | ❌ |
| Public endpoint | ✅ | ❌ |
| RouterOS version | ✅ | ❌ |
| Provisioning state, desired/actual config | ✅ | ❌ |
| Other customers' existence | ✅ | ❌ |

The fields are deliberately left on the mock object so the projection is
*visible* rather than implied — and §8 asserts they never reach the DOM.

---

## 5. Admin ↔ Customer object mapping

One domain model, two vocabularies. Same ids in both prototypes.

| Domain object | Admin V2 calls it | Customer PWA calls it | Customer may |
|---|---|---|---|
| Customer | Customer / Tenant | **My Account** | view |
| Service | Service | **My Service** | view |
| Site | Site | **My Location** | view |
| Router | Router (`MT-0001`) | **Access point** — by name | view state only |
| Plan / Profile | Plan | **How long** (on the voucher) | choose from allowed |
| Voucher | Voucher | **My Vouchers** | view, **create** |
| HotSpot user | HotSpot user | *not shown as an entity* | — |
| Device | Device / MAC | **Connected device** | view |
| Session | Session | **Connected device** (merged) | view |
| Intent | Intent (queued work) | **Queued request** | view own only |
| Invoice | Invoice | **Billing** | view, pay |

**Deliberate asymmetries**

- *Device* and *Session* are one row to a customer. A hotelier asks "who is on
  my Wi-Fi", not "how many accounting sessions are open".
- *HotSpot user* is never surfaced. The customer thinks in vouchers; the user
  record is an implementation consequence.
- *Router* is never shown by id — always by the name of its location.
- *Tenant* has no customer-side equivalent. A customer must not learn that
  multi-tenancy exists.

---

## 6. Three experiences, not three products

| | Audience | Auth | Surface |
|---|---|---|---|
| **Admin** | DishNet staff | Staff login | Desktop-first (V2) |
| **Customer PWA** | Account holders | Account login | **Mobile-first (this)** |
| **Captive portal** | Guests | Voucher code | Router-served page |

The captive portal is included as a **preview only**, so reviewers can see that
the guest journey is separate. It is not part of this prototype's scope.

> **No second native app.** Confirmed: MikroTik services are added to the
> existing DishNet Customer PWA. Customers have one app.

### 6.1 A constraint the captive portal will hit

The portal is served **before the guest has internet**. It therefore cannot load
web fonts, analytics, or any CDN asset. The customer PWA can (it runs on a
connected device); the portal must be fully self-contained. Recording this now
because it constrains whoever builds the portal, and it is easy to miss.

*(This prototype and admin V2 both load fonts from a CDN. Acceptable for both —
neither is captive-portal-served — but the portal cannot copy that pattern.)*

---

## 7. Boundary compliance (docs/41)

| Rule | How this complies |
|---|---|
| Domain A untouched | No Starlink data, endpoint or concept is read |
| Shared UI permitted (§5) | Home lists all services; only MikroTik is interactive |
| Non-B services | Read-only stubs, marked *Managed separately* |
| No delivery model asserted | §8 two-sided guard; neutral wording throughout |
| Intent model honoured | Customer voucher creation queues an Intent |
| No production change | Single HTML file, no backend |

**Note on the service list.** Showing Fiber beside MikroTik is UI unification,
which docs/41 §5 permits. It is not backend unification: the stub renders from
the customer's own service record and reaches no Domain A system.

---

## 8. Verification — 244 checks, all passing

`node prototype/verify-customer-pwa.js`

| Group | Checks |
|---|---|
| **1. B1 neutrality** | Two-sided guard (push *and* poll) over every screen, 3 personas, plus sheets, toasts and the queued-request card |
| **2. Field leakage** | 10 operational values asserted absent from the customer DOM |
| **2b. Isolation** | Per-persona: no foreign site, no router outside own sites; no session → null; forged token → null |
| **2c. Client ids** | `go('site','S-07')` as C-01 (a real site, another customer's) → refused |
| **3. Render health** | 9 screens × 4 widths (360/390/414/430) × 3 personas = 108 renders; no overflow, none blank |
| **3b. No-MikroTik** | All 5 Wi-Fi routes degrade to the empty state; home lists only real services |

Application errors: **none**. Two resource failures — the font CDN, blocked by
the sandbox proxy; the page renders on its CSS fallback stack.

**One method note.** The first version of this suite counted the blocked font as
a page error, which would have let a genuine script error hide inside an
environmental one. The suite now separates application errors from resource
failures and reports both. This is the same class of mistake as the one-sided B1
guard: a check that cannot distinguish two causes is not a check.

---

## 9. The twelve questions

| # | Question | Answer |
|---|---|---|
| 1 | Customer login | Phone + OTP. Person authenticates; the account is **derived**, never chosen |
| 2 | Customer identity | DishNet account is the billing subject; person is the credential |
| 3 | My Services | All services listed; only MikroTik interactive |
| 4 | My MikroTik Wi-Fi | Live devices, active vouchers, access points up — no router internals |
| 5 | My Sites | Per location: devices, vouchers, access-point state |
| 6 | My Routers | **Not a screen.** Access points appear inside a location, by name |
| 7 | Vouchers | List by state; create via plan picker; queued as an Intent |
| 8 | Connected devices | Session + device merged; each shown against its voucher |
| 9 | Usage | Vouchers used, value, busiest location — own sites only |
| 10 | Billing / account | Amount due, history, account details, sign out |
| 11 | Support | Account context attached automatically; four common questions |
| 12 | No MikroTik service | Explicit empty state; all 5 Wi-Fi routes redirect; other services unaffected |

Question 6 is the one that changed shape. *"My Routers"* was in the brief, but a
customer-facing router list re-introduces exactly the identifier-shaped thinking
this design removes. Access points are shown where they belong — inside a
location — and identified by name.

---

## 10. What is NOT decided here

- Whether voucher creation is a customer right or an operator-granted permission
- Pricing authority: who sets what a code costs
- Whether a person may hold more than one DishNet account, and the account
  switcher that would require
- Payment rails for **guest** purchase at the portal
- B1 (delivery path) — unchanged, still pending docs/32 Step 0
- Captive portal design — preview only

---

## 11. Open questions for approval

| # | Question |
|---|---|
| C1 | Can a customer create vouchers themselves, or only request them? |
| C2 | Can a customer set voucher prices, or is that operator-controlled? |
| C3 | One person → many accounts: in scope, or one account per login? |
| C4 | Is voucher creation charged to the customer, or is the customer billed a flat service fee? |
| C5 | Should a customer see an access point is down at all, or only DishNet? |

C5 matters more than it looks. The prototype currently shows it with *"DishNet
can see this too — no action is needed from you"*, which is honest and reduces
support calls. The alternative is hiding it. This is a support-policy decision,
not a UI one.

---

*No production system was contacted, modified or deployed in producing this document.*

# 46 — Product Model: Three Actors, Four Journeys

**Status:** ARCHITECTURE NOTE — product clarification, for approval
**Purpose:** Settle the product boundary before any further UX work
**Changes made:** NONE. No prototype, backend, database or architecture change.
**Evidence discipline:** MikroTicket statements are drawn from the store feature list and
changelog verified in docs/43 §2.1–2.2. **No MikroTicket screen was ever obtained.**
Anything beyond those quotes is marked INFERENCE.

---

## 0. The short answer

**Your suspicion is correct. The Customer PWA is not the MikroTicket management system.**

MikroTicket is a **single-tier** product: the person who logs in *owns the router* and has
Winbox passthrough to it. There is no ISP above them.

DishNet is **two-tier**: DishNet owns and operates the router estate; the customer operates
a *service*. So MikroTicket's single management UI splits across **two** DishNet surfaces,
and the dividing line is: **anything requiring knowledge of RouterOS belongs to DishNet.**

Your diagram is right, with one correction in §4.2.

---

## 1. The three actors

**Concrete case:** Riverside Hotel buys a DishNet MikroTik HotSpot service.

| | **A — DishNet Admin / NOC** | **B — Customer / HotSpot operator** | **C — Guest** |
|---|---|---|---|
| Who | DishNet staff | Riverside Hotel | A person in the lobby |
| Device | Desktop browser | Phone (mostly) | Their own phone |
| Logs into | Admin Web | Customer PWA | **Nothing** — enters a code |
| Sees | The whole estate, all customers | Their own sites, vouchers, devices, bills | A login page and then the internet |
| Can change | Everything: provisioning, config, plans, pricing | Issue vouchers; request support | Nothing |
| DishNet customer? | No — staff | **Yes** | **No** |
| DishNet account? | Staff account | **Yes** | **No** — a HotSpot user only |
| Needs the PWA? | No | **Yes** | **No, never** |
| Knows RouterOS? | Yes | **Never** | No |

### 1.1 The chain, end to end

```
DishNet Admin  registers  Riverside Hotel            (customer created)
               sells      MikroTik HotSpot service   (service created)
               assigns    Lobby, Poolside            (sites)
               provisions MT-0001, MT-0002           (routers — DishNet's hardware)
               configures 1 Hour / 6 Hours / 1 Day   (plans)
                                  │
                                  ▼
Hotel operator logs into the Customer PWA
               issues     voucher HT4K-9M2P for Lobby
               hands it   to a guest at reception
                                  │
                                  ▼
Guest         connects to "Riverside Hotel Wi-Fi"
              captive portal appears (served by MT-0001)
              enters HT4K-9M2P → authenticated → HotSpot user exists
              device joins → session starts → session ends → voucher expires
```

### 1.2 Actor B is actually two people — and our prototype missed this

The hotel **owner/manager** cares about billing, usage and whether the service is worth
keeping. The **receptionist** issues vouchers all day and cares about nothing else.

MikroTicket ships *"operator accounts with custom access permissions"* (VERIFIED) —
evidence that this split is real in the market, not a DishNet peculiarity.

**Our Customer PWA currently has one login per customer and conflates the two.** A
receptionist should not see the hotel's invoices; an owner should not have to wade through
a voucher counter to find their bill. docs/42 has a role model, but the prototype does not
implement it.

This is the largest gap this exercise found. Recorded as **C6** in §9.

---

## 2. What MikroTicket actually does

### 2.1 Answers from verified evidence

| Question | Answer | Basis |
|---|---|---|
| Who logs in? | The router owner/operator | *"Manage your routers… Also supports Winbox for advanced control"* |
| Is it the ISP admin? | **Not a separate tier.** Same login | No evidence of an ISP-above-operator tier |
| Is it the hotspot owner? | **Yes** — owner and admin are the same account | Winbox passthrough implies direct router ownership |
| Separate guest app? | **No evidence of one** | Nothing in the listing describes an end-user app |
| How are vouchers generated? | In the management app; printed or shared | *"Bluetooth and TCP/IP printers"*, *"PDF (A4/A3) or digital sharing with QR codes"* |
| How does a guest get internet? | Captive portal, code entry | *"Design, preview, and publish… captive portal templates"* |
| Does the guest use the management system? | **No** | The portal is a published artefact, not a login |
| Where does the portal fit? | **Designed in** the management app, **served by** the router | Same quote |
| Management UI vs portal? | Two surfaces. One authors, the other is consumed | See §6 |

### 2.2 The journey MikroTicket implies

```
Owner installs MikroTicket → adds router (IP + credentials) → creates plans →
designs captive portal → publishes it to the router → generates tickets →
prints them → hands one to a guest → guest connects → portal → code → internet →
ticket auto-deletes when its time ends
```

**One tier. One login. The owner is the admin.** That is the whole product shape, and it is
why it cannot be copied directly.

### 2.3 Three consequences for DishNet

1. **Winbox passthrough is the giveaway.** A product offering Winbox assumes its user
   *may legitimately reconfigure the router*. DishNet's customers may not — the routers are
   DishNet's estate. docs/43 §C already rejected Winbox passthrough; this explains why it
   was never a small feature decision.
2. **The portal editor belongs to Admin, not the customer.** Publishing a portal writes
   files to a router. Under docs/41, that is DishNet's act.
3. **Auto-delete is wrong for us.** *"Tickets automatically deleted after usage"* destroys
   the audit trail. A DishNet voucher is a revenue record. **Expire, never delete.**

---

## 3. MikroTicket → DishNet mapping

| MikroTicket / typical MikroTik system | DishNet |
|---|---|
| Management login | **Splits in two:** Admin Web (estate) + Customer PWA (own service) |
| Adding a router by IP + credentials | **Admin only.** Zero-touch provisioning; the customer never sees an address |
| Winbox passthrough | **Does not exist.** Deliberately removed (docs/43 §C) |
| Router | Admin: `MT-0001`. Customer: *"Lobby access point"* — by name, never by id |
| HotSpot plan | Plan. **Admin defines; customer chooses from what is allowed** (C2 open) |
| Voucher generation | Customer PWA — raised as an **Intent**, not a direct router write |
| Voucher delivery | Print / share / QR — **not yet designed** (§9 C7) |
| Guest captive portal | Served by the router. **Not yet built** — preview only |
| Guest session | Admin: full session. Customer: *"a connected device"* |
| Customer billing | uCRM. DishNet bills the customer. **The guest is never billed by DishNet** (today) |
| Customer account | DishNet account in uCRM — the billing subject |
| Operator accounts | **Gap.** MikroTicket has them; we do not (§1.2, C6) |
| Ticket auto-delete | **Rejected.** Expire and retain |
| Sales charts (date/plan/operator) | Adopt as-is (docs/43 §4A) |

---

## 4. Confirming your architecture

### 4.1 Confirmed

```
DISHNET ADMIN WEB  →  DISHNET CUSTOMER PWA  →  MIKROTIK  →  CAPTIVE PORTAL  →  GUEST
```

The ordering, the surfaces and the separation are all correct.

### 4.2 One correction: the PWA does not talk to the router

Your diagram has Admin Web and Customer PWA both sitting above MikroTik. Read as a *data
path*, that would license a direct PWA→router call — exactly what docs/42 §0 forbids.

**Neither surface reaches a router.** Both write to the DishNet backend; the backend owns
the intent queue; the queue is the only thing that reaches routers:

```
   ADMIN WEB                 CUSTOMER PWA
  (DishNet staff)          (Riverside Hotel)
        │                         │
        └───────────┬─────────────┘
                    ▼
            DISHNET BACKEND
         ┌──────────────────────┐
         │  identity + authz    │   ← server derives who you are
         │  INTENT QUEUE        │   ← the ONLY path to a router
         └──────────┬───────────┘
                    ▼
              MIKROTIK ROUTER  ────────────────┐
                    │                          │
            serves CAPTIVE PORTAL      authenticates via RADIUS
                    │                          │
                  GUEST ──── enters voucher ───┘
                    │
                 INTERNET
```

The guest's authentication (right-hand path) is **live and synchronous** — a guest cannot
wait in a queue. The management path (left) is **asynchronous by design**. These two
timing models coexisting is the core of the architecture.

### 4.3 What belongs where

| Customer PWA | Captive portal |
|---|---|
| Runs on DishNet's servers | **Runs on the router** |
| Needs internet | **Works with no internet** — that is its job |
| Account login | Voucher code, no account |
| Manages the service | Grants access |
| Persistent identity | Session-scoped, then gone |
| Can load fonts, CDN assets | **Cannot** — fully self-contained |
| Changed by deploying the PWA | Changed by **delivering files to each router** — itself an Intent |

That last row is the one implementers miss. The portal is not a web page we deploy; it is a
router artefact, so changing it is subject to the same delivery question as everything else
(B1, still open).

---

## 5. The four journeys

### Journey A — DishNet Admin *(Admin Web)*
Register customer → create service → assign site(s) → provision router → assign to site →
configure plans → activate. **Provisioning is the only step touching RouterOS, and only
DishNet performs it.**

### Journey B — Customer / operator *(Customer PWA)*
Log in (phone + OTP) → server derives the account → *My Services* → tap Wi-Fi → choose
location → New voucher → pick duration → **voucher created, queued for delivery** → hand
the code to a guest.
*Delivery to the guest (print/QR/SMS) is not designed — C7.*

### Journey C — Guest **with** a voucher *(captive portal)*
Connect to the SSID → portal appears → enter code → **RADIUS authenticates** → HotSpot user
created → internet starts → session runs → voucher expires → access ends.
No DishNet account is created at any point.
*Voucher-as-RADIUS-user vs router-local user is a Phase 0 design point — mark as open (C8).*

### Journey D — Guest **without** a voucher

**Today: they go to reception.** That is the entire designed answer. Everything else is a
decision, not a feature:

| Could they… | Status |
|---|---|
| See a price list | **FUTURE** — needs C2 (who sets prices) |
| Buy a voucher at the portal | **FUTURE, COMMERCIAL** — biggest open question |
| Pay by mobile money | **FUTURE** — needs a payment rail; none exists in Domain B |
| Request access / self-register | **FUTURE** — would need a guest identity model we do not have |
| Contact reception | **FUTURE** — trivial to display, but it is the hotel's detail, not DishNet's |

Journey D is where the product could grow most, and where nothing should be assumed. If a
guest can pay at the portal, DishNet needs guest payments, guest refunds, guest receipts and
a revenue split with the hotel — a business, not a screen.

---

## 6. Management vs access: why two surfaces

```
  CUSTOMER MANAGEMENT                 │        GUEST INTERNET ACCESS
  ════════════════════                │        ════════════════════
  WHO   Riverside Hotel               │  WHO   a person in the lobby
  ASKS  "how is my service doing?"    │  ASKS  "how do I get online?"
  AUTH  account + OTP                 │  AUTH  a voucher code
  HOST  DishNet servers               │  HOST  the router itself
  NET   needs internet                │  NET   has none yet — that is the point
  LIFE  persists for years            │  LIFE  minutes to days, then gone
  ID    a DishNet customer            │  ID    a HotSpot user
  BILL  invoiced monthly              │  BILL  not billed by DishNet
  FAILS the hotel can't manage today  │  FAILS the guest can't get online now
```

**They are separate because the guest has no internet when they need the portal.** The
Customer PWA cannot reach them — the router must. This is a network fact, not a design
preference, and it alone forces two surfaces.

Second reason: the identities are different in kind. A guest has no account to log into, and
giving them one would convert every visitor into a DishNet record — a privacy and data
liability with no upside.

---

## 7. Prototype screen review

No changes made. Verdicts only.

| Screen | Verdict | Reason |
|---|---|---|
| **Login** | **KEEP** | Correctly routes guests away: *"you don't need an account"* |
| **Home / My Services** | **KEEP** | The service list is the right spine |
| **My Wi-Fi** | **KEEP** | Right altitude — outcomes, not infrastructure |
| **Locations** | **KEEP** | The correct substitute for a router list |
| **Vouchers** | **KEEP** | Actor B's core job |
| **New voucher** | **KEEP** | Correctly queued as an Intent |
| **Connected devices** | **KEEP** | Merging device+session is right for this actor |
| **Usage** | **KEEP** | Owner-facing |
| **Billing** | **KEEP**, but see C6 | Owner-only once roles exist — a receptionist must not see invoices |
| **Account** | **KEEP** | — |
| **Support** | **KEEP** | — |
| **No-MikroTik empty state** | **KEEP** | — |
| **Guest captive portal** | **MOVE TO CAPTIVE PORTAL** | Correctly built as a preview; belongs in its own prototype |
| **Identity chain panel** | **REMOVE before production** | Reviewer instrument, never a customer screen |
| *Portal template editor* | **ADMIN — do not build here** | Publishing writes to a router |
| *Router internals* | **ADMIN — already absent** | Correctly excluded |
| *Operator/staff accounts* | **FUTURE — Customer PWA** | The C6 gap |
| *Guest self-purchase* | **FUTURE — captive portal** | Journey D, commercial |

**Nothing needs to move out of the Customer PWA.** The prototype was built at the right
altitude for actor B. What it is missing is the B-owner/B-staff split, and what it borrowed
(the portal) was already marked a preview.

---

## 8. The sentence

> **If Riverside Hotel is a DishNet customer, the person using the Wi-Fi on their phone sees
> a captive portal served by the router asking for a voucher code — and nothing else, with
> no account and no app; the Riverside Hotel operator sees the DishNet Customer PWA showing
> their own locations, vouchers, connected devices and bill — but never a router, an address
> or a configuration; and the DishNet administrator sees the Admin Web console showing the
> entire router estate across all customers, including everything the other two are
> deliberately never shown.**

---

## 9. Open questions

Carried from docs/45, plus new:

| # | Question | Status |
|---|---|---|
| C1 | Can a customer create vouchers, or only request them? | Open |
| C2 | Who sets voucher prices? | Open |
| C3 | One person → many accounts? | Open |
| C4 | Is voucher creation charged, or a flat service fee? | Open |
| C5 | Should a customer see that an access point is down? | Open |
| **C6** | **Owner vs staff roles in the Customer PWA** | **NEW — §1.2, largest gap** |
| **C7** | **How does a voucher reach a guest?** (print / QR / SMS) | **NEW — Journey B ends undesigned** |
| **C8** | **Voucher = RADIUS user or router-local user?** | **NEW — Phase 0 design point** |
| **C9** | **Can a guest buy access at the portal?** | **NEW — Journey D, commercial** |

**C9 is the one to decide first.** It determines whether the captive portal is a login page
or a shop, and those are different products.

---

## 10. What this note does not decide

Nothing about B1 (delivery path). Nothing about Domain A. No screen, no schema, no endpoint.
The captive portal is **not designed** — it is only now correctly scoped as a separate
surface with its own constraints.

Recommended next step: design the captive portal separately, once **C9** is answered.

---

*No production system was contacted, modified or deployed. No prototype file was changed.*

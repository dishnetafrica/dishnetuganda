# DishNet Zero-Touch MikroTik & Hotspot Platform — Architecture

**Status: architecture only. No production code has been written or modified.**
Requested under the brief of 19 September 2026, section 22: produce thirteen
artifacts and an implementation plan before any implementation.

Labels used throughout, as in docs/27:

- **EXISTING** — verified in this repository or on the live system
- **PROPOSED** — a design decision offered for review
- **REQUIRES DEVELOPMENT** — does not exist and must be built
- **REQUIRES VERIFICATION** — cannot be confirmed from here; needs hardware,
  network access, or an answer from the operator

---

## 0. Read this first: four findings that change the brief

The brief is sound. Four things about the ground it lands on are not what it
assumes, and all four change scope, cost or sequence. They are here rather
than buried at artifact 7 because each one is cheaper to settle now than
after a sprint.

### 0.1 CORRECTED 19 Sep 2026 — the customer actor DOES exist

> **This section was wrong.** See docs/37 for the audit that found it.
>
> A complete customer-facing application already exists:
> `includes/api/api_customer_app.php` (5,104 lines), OTP login, JWT with
> `kind='app'`, a token blacklist, an installable PWA at
> `tabs/customer_app/portal.php`, and **a working hotspot feature** with
> time-based paid access, device tracking and per-router authorisation.
>
> The error was looking in one place. `api/v2/router.php` is a staff field
> API — that much was right — but the search stopped at the `api/` directory
> and never reached `includes/api/`.
>
> **Consequence:** Phase 1 below should read *build tenancy*, not *build the
> customer principal*. Authentication, the app shell, billing views and push
> notifications all exist and must not be rebuilt. docs/37 §8 has the revised
> reuse boundary.
>
> The original text is kept below because the reasoning built on it appears
> throughout this document, and silently editing it would leave those
> passages unexplained.

### 0.1 (original, incorrect) The actor the whole brief depends on does not exist

The brief's central sentence is *"Customer opens the DishNet App"* and every
screen in sections 12–13 is a customer looking at their own routers.

**EXISTING:** `api/v2/router.php` is the only v2 API. Its resources are
`auth`, `health`, `tickets`, `install`, `wallet`, `noc`. Its login calls
`$auth->webLogin($email, $password)` and issues a JWT carrying
`'role' => $user['role'] ?? 'sales'`, alongside an agent wallet balance.
Those are staff: sales agents, installers, NOC.

There is **no authenticated customer**, anywhere. A uCRM client is a record
the staff app reads; it is not a principal that can log in and hold
permissions. Grep for `tenant` across all 191 classes in `lib/` returns
nothing.

So "multi-tenant" is not a property to be added to an existing tenancy
model. **The tenancy model is the first thing that must be built**, and
until it exists there is nothing for router ownership to hang from. This is
artifact 7 and it belongs at the front of the schedule, not the middle.

### 0.2 "Thousands of customers" is not what the CRM contains

**EXISTING, verified live earlier in this session:** the uCRM instance holds
**one organization** (id 1, DishNet Africa Limited) and **47 clients**.

The brief sizes the admin dashboard at 1,842 routers and 18,421 concurrent
hotspot users. That is three orders of magnitude above the current client
count.

**REQUIRES VERIFICATION — this is the single most important open question in
this document.** Which is meant?

1. **47 ISP customers, each reselling to thousands of hotspot end-users.**
   Then tenants number in the tens, sessions in the tens of thousands, and
   the hard scaling problem is RADIUS and accounting, not tenancy.
2. **Thousands of DishNet customers each with a router.** Then the 47 is an
   under-migrated CRM, and CRM data quality becomes a blocking dependency.
3. **A growth target rather than today's number.** Then build for (1) and
   leave the seams for (2).

These produce materially different systems. Interpretation (1) is assumed
throughout this document because it matches the verified data, and every
sizing figure below is marked where the answer would change it.

### 0.3 The CGNAT bootstrap has a clean answer, and it is not a tunnel-first one

Correctly identified in the brief as the hard part. The full statement of
the problem is worse than "behind CGNAT":

> A factory-reset MikroTik will never contact DishNet, because nothing in its
> factory configuration knows DishNet exists.

No amount of inbound cleverness solves that; it is not a reachability problem
but a **trust-origination** problem. There are exactly three ways a router
can learn about DishNet:

| | mechanism | works for |
|---|---|---|
| a | something on its LAN pushes config | any router the customer can physically touch |
| b | it ships pre-staged | routers DishNet sells |
| c | branded firmware / netinstall fetches config | not realistic for retail hardware |

**PROPOSED: (a) and (b), and never (c).** The phone is the bridge. The
router is provisioned from the LAN side by a device the customer already
controls, and the *only* thing that local push installs is identity plus an
outbound management tunnel. Everything else is configured afterwards from
the backend, over that tunnel.

This inverts the usual instinct and it is the central design decision in
this document. Section 4 sets it out in full. Its consequence is that
**CGNAT stops being an architectural concern at all** — after bootstrap,
every management connection is router-initiated outbound.

### 0.4 Half the listed hardware cannot do the flow the brief describes

The brief lists hEX, hEX S, hAP ac2, hAP ax2, hAP ax3, RB750, RB951, RB4011,
RB5009 and asks for a compatibility matrix. Two facts collapse that list:

**No Wi-Fi, no phone bootstrap.** hEX, hEX S, RB750, RB5009 and the base
RB4011 have no radio. The phone cannot join their network, so "scan the QR
and it configures itself" cannot happen from a phone alone. They need a
laptop, a USB-Ethernet adapter, or pre-staging.

**RouterOS 6 cannot do the job.** The REST API arrived in RouterOS 7.1.
WireGuard arrived in RouterOS 7. A v6 device has neither, so it can be
managed only over the legacy binary API or SSH, and its tunnel options
degrade to OpenVPN-over-TCP or L2TP/IPsec.

**PROPOSED: require RouterOS 7.x, and have the provisioner upgrade v6
devices as its first step** — it has a local connection and can do that.
**PROPOSED: the self-service QR flow is offered for Wi-Fi models only**;
hEX-class hardware is either pre-staged by DishNet or installed by a
technician. Both are marked REQUIRES VERIFICATION until tested on real
units, per section 10.

---

## Artifact 1 — Current DishNet architecture audit

**EXISTING.** 252,206 lines of PHP 7.4, no framework, no Composer. It runs as
a uCRM plugin: uCRM writes `data/config.json` when an admin saves the
settings form, and the plugin keeps its own SQLite database in a **sibling**
directory (`.dishnet-hybrid-sudan-data/plugin.sqlite3`).

| area | files | what it is |
|---|---|---|
| `lib/` | 191 | domain classes — CRM client, billing, EFRIS, WhatsApp, AI |
| `workers/` | 11 | queue consumers, incl. `AiReplyWorker` |
| `cron/` | 29 | scheduled jobs under `cron/master.php` |
| `migrations/` | 75 | SQL, applied in order |
| `tools/` | 96 | operator CLI — doctors, probes, config |
| `tests/` | 189 | `bash tests/run.sh`, no framework |
| `api/` | 2 | `index.php` (118 KB, legacy) and `v2/router.php` |

**Relevant to this brief:**

- **Storage is SQLite.** Single-writer. It is correct for one uCRM instance
  and wrong for a RADIUS accounting stream. This is the strongest technical
  argument for the brief's own instruction that the platform must not live
  inside the plugin.
- **No network layer exists.** No router, RADIUS, hotspot, voucher or device
  class. `StarlinkSessionStore` is Starlink service state, unrelated.
- **`api/v2/router.php`** already parses `/api/v2/{resource}/{id}/{action}`
  and rate-limits per IP in SQLite. The brief's `/api/v2/network/...` routes
  would slot in as a new `case`. **This is a trap** — see artifact 9.
- **Secrets** already have a home: `ConfigVault`, which survives re-install
  (observed restoring `dpo_payment_method_uuid` on every config write).

## Artifact 2 — Existing customer and service lifecycle

**EXISTING.** uCRM is the system of record for client, service and invoice.
The plugin reads it through `CrmApiClient` and holds its own operational
state locally. The chain the brief must attach to:

```
uCRM organization (1)
  └── uCRM client (47)
        └── uCRM service            ← the billable Internet subscription
              └── invoices, payments, EFRIS fiscalisation
```

The brief's section 18 is right that a second customer database must not
appear. **PROPOSED:** the network platform stores `ucrm_client_id` as the
tenant key and holds **no name, phone, address or billing data of its own**.
It is a foreign key, not a copy. Every customer-identifying field is read
back from uCRM at display time.

**REQUIRES VERIFICATION:** whether a hotspot reseller is modelled as a uCRM
*client* with a service, or needs a new service type. This determines
whether a router attaches to a client or to a service, and the answer is a
billing decision, not a technical one.

## Artifact 3 — Database and schema impact

**PROPOSED: the network platform does not use the plugin's SQLite database
at all.** Not a preference — a correctness requirement. RADIUS accounting
for even the brief's lower sizing produces sustained concurrent writes that
SQLite's single-writer lock cannot absorb, and putting them in the same file
as billing state risks the billing state.

Three stores, by job:

| store | holds | why |
|---|---|---|
| **PostgreSQL** (new) | tenants, devices, provisioning state, plans, vouchers, sessions, audit | concurrent writes, real constraints, `radacct` is a PostgreSQL schema FreeRADIUS already ships |
| **Redis** (new) | live sessions, rate limits, pairing tokens, CoA queue | ephemeral, TTL-native, the dashboard's "online now" |
| **SQLite** (existing) | everything the plugin does today | unchanged; **zero migrations added** |

The plugin gains **no new tables**. Its only change, eventually, is a
read-only client of the network API for showing hotspot revenue on an
invoice. That is Phase 4, and nothing before it touches the plugin.

## Artifact 3b — The four layers, and why they must not merge

**Added 19 September 2026 after the operator checked the RouterOS manual.**
This separation was implicit in the sections below and is now explicit,
because the failure mode of merging them is expensive and not obvious.

| layer | technology | job | must not also do |
|---|---|---|---|
| **transport** | WireGuard | carry management traffic to a router with no public IP | authenticate subscribers |
| **management** | RouterOS REST (`/rest`, over `www-ssl`) | read and write device configuration | enforce access |
| **AAA** | FreeRADIUS | authenticate and account for subscribers | configure devices |
| **enforcement** | RouterOS HotSpot | captive portal, gate the user's traffic | decide policy |
| **control** | DishNet Control Plane | registry, provisioning, plans, vouchers, tenancy | any of the above directly |

**CONFIRMED BY DOCUMENTATION** (capability, not syntax): RouterOS has native
WireGuard; a REST API from 7.1beta4 exposed at `/rest` and requiring
`www-ssl`; RADIUS for HotSpot and PPP/PPPoE with accounting, where RADIUS
attributes override profile parameters; and HotSpot with remote RADIUS
authentication and accounting.

**Two consequences worth stating:**

**DishNet builds no captive portal.** RouterOS HotSpot already is one, with
remote RADIUS built in. The DishNet-branded login page is a *template served
by HotSpot*, not an application. Anything beyond that is rebuilding a
supported feature.

**RouterOS REST becomes the management interface, not scripting.** Scripting
and `/import` remain the **bootstrap and recovery** mechanism — they work
before REST is configured and after a reset, which REST does not. So:

```
bootstrap / recovery  →  RouterOS scripting, /import, run-after-reset
steady-state config   →  REST over the WireGuard tunnel
```

Keeping a hand-rolled scripting protocol for steady-state configuration
would mean maintaining a private RPC against a moving target. The manual
describes REST as a JSON wrapper over the same console API, so the
capability is the same and the surface is far smaller.

**Certificates.** REST needs `www-ssl`, which needs a certificate. Over the
WireGuard tunnel the transport is already authenticated and encrypted, so a
self-signed per-device certificate is sufficient and avoids a public PKI for
every router. **REQUIRES VERIFICATION** that RouterOS will serve REST on a
self-signed certificate without further configuration.

## Artifact 4 — MikroTik provisioning architecture (the core)

### 4.1 Why not a tunnel first

The tempting design has the router dial home and DishNet configure it. It
cannot work as the *first* step: a factory-reset router has no idea where
home is. Every "zero-touch" product that appears to do this either ships
pre-staged hardware or runs branded firmware.

### 4.2 The proposed flow

**PROPOSED.** Two phases with different trust properties. The local phase is
deliberately tiny; the heavy configuration is server-side where it can be
observed, retried and rolled back.

```
PHASE 1 — LOCAL, on the customer's LAN, seconds
  app (cellular) ── POST /pair/session ─────────► backend
  app ◄── pairing_id + short-lived signed token ─┘
  app joins router's default network (192.168.88.1)
  app reads identity: serial, model, RouterOS version        [no writes yet]
  app leaves, returns to cellular
  app ── POST /pair/{id}/identify {serial,model,ver} ───────► backend
  backend: is this serial already claimed? version supported?
  backend ── signed bootstrap bundle, bound to THAT serial ─► app
  app rejoins router network, pushes bundle, leaves
       bundle = { identity, admin credential rotation,
                  WireGuard client config, one scheduled script }

PHASE 2 — REMOTE, over the tunnel, minutes
  router ── outbound WireGuard ────────────────► DishNet concentrator
  backend verifies serial on the tunnel matches the claim
  backend configures everything else, step by step, each step recorded:
      WAN · bridge · DHCP · DNS · pools · HotSpot · RADIUS ·
      walled garden · firewall · NAT · queues · NTP · identity
  backend verifies, marks ONLINE, router appears in the dashboard
```

**Why this is the right shape:**

- The fragile step — a phone on a strange network — lasts seconds and writes
  one small bundle.
- CGNAT, dynamic WAN IPs and firewalls are all irrelevant after Phase 1:
  the tunnel is outbound and the router re-establishes it by itself.
- A Phase 2 failure leaves a router that is **still reachable**. It can be
  fixed or rolled back remotely. This is what stops the half-configured
  router the brief's section 15 warns about.
- The customer's phone never holds a long-lived credential.

### 4.3 The dual-connectivity trap

**REQUIRES DEVELOPMENT, and it is the hardest mobile work in the project.**
A phone joined to a Wi-Fi network with no Internet will, depending on OS
version and vendor, either keep cellular for data or lose it. The design
above **deliberately never needs both at once** — the app alternates. That
costs two network switches and buys a large reduction in platform-specific
breakage. Android still needs `ConnectivityManager.requestNetwork` plus
`bindProcessToNetwork` to force local sockets to the Wi-Fi interface;
iOS needs `NWConnection` with `requiredInterfaceType: .wifi`.

### 4.4 Where the QR code actually belongs

Not where the brief puts it. If the customer is holding the phone that is
doing the provisioning, there is nothing to scan — the app has the pairing
session already. The QR is useful in exactly two places:

1. **Desk-to-field handover** — an office generates the session, a field
   installer scans it to adopt it onto their phone.
2. **Pre-staged hardware** — DishNet stages the router, prints a label, and
   the customer scans it to *claim* an already-provisioned device. This is
   the best experience in the whole document and needs no field work at all.

## Artifact 5 — FreeRADIUS architecture

**PROPOSED.** FreeRADIUS 3.2 with the PostgreSQL `rlm_sql` module, reading
the same database the control plane writes. No custom RADIUS server, and no
attempt to make PHP speak RADIUS.

```
MikroTik HotSpot ──► FreeRADIUS ──► PostgreSQL (radcheck/radreply/radacct)
        ▲                               ▲
        │                               │ writes plans, vouchers, users
   CoA / Disconnect                 Control Plane (new service)
        │                               │
        └──── radclient ◄───────────────┘
```

**Authorisation is a database question, not a policy-language one.** A
voucher becomes rows in `radcheck`/`radreply`; expiry is `Expiration`; a plan
becomes MikroTik VSAs. Keeping logic in SQL rather than `unlang` means the
control plane's tests can assert on rows.

Per-plan attributes:

| attribute | purpose |
|---|---|
| `Mikrotik-Rate-Limit` | `rx/tx` shaping, e.g. `5M/5M` |
| `Session-Timeout` | hard cut at plan duration |
| `Idle-Timeout` | reclaim abandoned sessions |
| `Acct-Interim-Interval` | 300s — drives the live dashboard |
| `Mikrotik-Total-Limit` | data cap, where a plan has one |
| `Expiration` | voucher validity window |

**Tenancy inside RADIUS.** FreeRADIUS has no concept of a tenant. **PROPOSED:**
every username is namespaced (`t{tenant_id}-{code}`) and every NAS row carries
its tenant. A `radacct` row is only ever shown after joining back to the NAS's
tenant. Artifact 7 covers the isolation test that must accompany this.

**CoA and Disconnect.** `Disconnect-Request` on voucher revocation or account
suspension; `CoA-Request` for a mid-session plan change. **REQUIRES
VERIFICATION** per model. CoA is inherently server-initiated, so it has the
same inbound-reachability problem as management — see §5.2, which is where
that stops being a footnote.

### 5.1 Does RADIUS go through the management tunnel?

**This question was not asked in the first draft and it should have been.**
It is an availability decision, not a security one.

| | RADIUS through the WireGuard tunnel | RADIUS direct, over RadSec |
|---|---|---|
| confidentiality | WireGuard provides it | TLS provides it |
| certificates | none needed per device | one per device |
| **if the tunnel drops** | **hotspot logins stop** | hotspot keeps working |
| complexity | low | moderate |

The coupling in row three is the problem. A management tunnel outage is an
inconvenience; a management tunnel outage that also stops every paying
hotspot user from logging in, across every reseller, is an incident. The
operator's own instruction — *the management tunnel should be separate from
the customer's production traffic* — points the same way: subscriber
authentication **is** production traffic.

**PROPOSED:** through-tunnel for Phase 0, because it is simplest and proves
the chain. But the RADIUS layer is built so that a device's AAA transport is
a **per-device setting**, not an assumption baked into the provisioning
templates. Moving to RadSec later must not require re-staging the fleet.

### 5.2 RadSec may solve the CGNAT inbound problem for CoA

**CONFIRMED BY DOCUMENTATION:** RouterOS supports `protocol=radsec` with
certificates, alongside conventional UDP RADIUS.

**REQUIRES VERIFICATION, and it is worth verifying early.** RadSec is RADIUS
over TLS on TCP, and the connection is opened *by the NAS* — outbound. If
RouterOS accepts CoA and Disconnect **back down that existing connection**,
then CoA works through CGNAT with no inbound reachability at all, and no
dependence on the WireGuard idle-reach behaviour that Test B1 measures.

That matters because of what otherwise happens if B1 fails: management goes
poll-based, and **voucher revocation and mid-session plan changes go with
it**, since both are server-initiated. A reseller revoking a voucher would
wait for the next poll. If RadSec carries CoA down the NAS-initiated
connection, revocation stays immediate even in the poll-based world.

**PROPOSED:** not a Phase 0 blocker, per the operator. But add one check to
Phase 0 — see docs/31 §2.1 — because a positive result de-risks the entire
B1-fails branch, and it costs one test.

## Artifact 6 — Zero-touch pairing and device identity

### 6.1 Pairing token

**PROPOSED.** The brief is right that no permanent credential goes in a QR.
The token is a signed, single-use, short-lived claim *ticket* — it authorises
starting a pairing, nothing else:

```
{ pairing_id, tenant_id, nonce, exp (≤ 10 min), sig }
```

It is not a device credential. It cannot configure anything. Consumed on
first use; a replay is rejected on the nonce.

### 6.2 The bootstrap bundle is the sensitive object

The bundle pushed in Phase 1 contains a WireGuard private key and rotated
admin credentials. **PROPOSED:**

- bound to **one serial**, checked again when the tunnel comes up
- lifetime ≤ 10 minutes
- single use — a second presentation fails and raises an audit event
- never logged, never persisted on the phone, held in memory only
- the WireGuard key is generated **on the backend per device**, so a leaked
  bundle compromises one router and not a fleet

### 6.3 Device identity and claim integrity

**PROPOSED** state machine, extending the brief's list with the states the
two-phase flow actually needs:

```
UNCLAIMED → PAIRING → BOOTSTRAPPED → PROVISIONING → ONLINE
                ↓           ↓              ↓           ↕
             EXPIRED    ORPHANED        FAILED     OFFLINE
                                           ↓
                                      SUSPENDED → DECOMMISSIONED
```

`BOOTSTRAPPED` and `ORPHANED` are additions. `BOOTSTRAPPED` means the bundle
was pushed but the tunnel has not yet appeared — a real and common state,
and the one a customer will phone about. `ORPHANED` means it never appeared
within the timeout: the router now holds DishNet configuration but is not
manageable, and it needs an explicit recovery path rather than being left
looking like a failed claim.

**The claim rule, stated precisely.** A serial is claimed when the tunnel
comes up carrying it *and* it is unclaimed *and* the bundle matches. From
that moment only a DishNet administrator can release it, with an audit
record. A second tenant presenting the same serial is refused — the brief
requires this and it is the whole defence against a stolen router being
re-adopted.

**REQUIRES VERIFICATION:** whether the RouterBOARD serial is readable
pre-authentication and whether it can be spoofed on a device the customer
controls. Assume it can be, and treat first-claim-wins as the real
protection.

## Artifact 7 — Multi-tenant security model

Per 0.1, this is **REQUIRES DEVELOPMENT in full**, not an extension.

**PROPOSED principles**, in the order they must hold:

1. **The tenant is never an argument.** It is derived from the authenticated
   principal server-side and injected into the query. `GET /routers/{id}`
   must never accept a tenant id from the caller. This is the same invariant
   the AI layer already lives under — *the model must never choose which
   customer it is authorised to access* — restated for a different client.
2. **Tenant scoping at the lowest layer that can enforce it.** PostgreSQL
   row-level security keyed on a session variable, so a missing `WHERE` in
   an application query returns nothing rather than another tenant's rows.
   Application-level filtering is a second layer, never the only one.
3. **Every secret encrypted at rest**, per-device, envelope-encrypted.
   Router passwords and RADIUS secrets are never readable in a dump.
4. **Every administrative action audited** — actor, action, target, tenant,
   time, source — in an append-only table with the same delete protection
   the ledger already uses (migration 070's trigger pattern).
5. **The customer principal is new.** A uCRM client is not a login. Building
   customer authentication — registration, credential reset, session
   lifetime, and the binding from login to `ucrm_client_id` — is a
   prerequisite, and is where a cross-tenant flaw would do the most damage.

**PROPOSED test, to be written before the feature:** a fixture with two
tenants and one router each, then every read endpoint called with tenant A's
token and tenant B's ids, asserting 404 — never 403, which confirms
existence. This is the artifact-13 gate for the whole phase.

## Artifact 8 — Hotspot plan and voucher data model

**PROPOSED.** Close to the brief, with the money fields made explicit.

```
tenants          ucrm_client_id (unique), status, limits
devices          serial (unique), tenant_id, model, ros_version,
                 state, tunnel_ip, last_seen, claimed_at, claimed_by
hotspot_plans    tenant_id, name, duration_s, rate_up, rate_down,
                 data_cap_bytes, price_minor, currency, active
vouchers         tenant_id, plan_id, device_id|null, code (unique),
                 batch_id, state, created_at, activated_at, expires_at,
                 price_minor, sold_at, sold_by
sessions         tenant_id, device_id, voucher_id, radacct_id,
                 mac, ip, started_at, ended_at, bytes_in, bytes_out
audit_log        actor, action, target, tenant_id, at, source, detail
```

**Voucher codes.** The brief says never predictable. **PROPOSED:** 10
characters from a 32-symbol alphabet with `0/O/1/I` removed (they are read
aloud and typed by hand on a phone), drawn from a CSPRNG, uniqueness enforced
by a unique index rather than by checking first. That is ~10^15 per tenant —
unguessable, and still readable off a printed card.

**Money.** `price_minor` as an integer, with currency. The plugin already
learned this lesson once: a float UGX amount is a rounding bug waiting for
month-end.

**A voucher is not a payment.** The brief's section 19 chain — sold, paid,
activated — means `sold_at` and activation are different events with
different actors. Reconciliation is Phase 4 and is deliberately not in the
MVP.

## Artifact 9 — API contract

The brief's routes are good. One structural objection:

**PROPOSED: these do NOT belong in `api/v2/router.php`.** That file is the
uCRM plugin's staff API — same process, same SQLite, same deployment, same
blast radius. Putting a multi-tenant network control plane inside it
contradicts the brief's own section 2, and a bug in voucher generation would
then be able to take down invoicing.

The network API is **a separate service** on its own host with its own
database, reachable at its own base path. The plugin may *call* it. It does
not *contain* it.

```
POST   /api/v2/network/pair/session            → pairing_id + token
POST   /api/v2/network/pair/{id}/identify      → serial/model/version
POST   /api/v2/network/pair/{id}/bundle        → signed bootstrap bundle
POST   /api/v2/network/pair/{id}/pushed        → phone reports success
GET    /api/v2/network/pair/{id}               → live provisioning state

GET    /api/v2/network/routers                 (tenant-scoped, always)
GET    /api/v2/network/routers/{id}
POST   /api/v2/network/routers/{id}/provision  (idempotency-key)
POST   /api/v2/network/routers/{id}/reprovision
POST   /api/v2/network/routers/{id}/restart
POST   /api/v2/network/routers/{id}/decommission
GET    /api/v2/network/routers/{id}/sessions

POST   /api/v2/hotspot/plans
GET    /api/v2/hotspot/plans
POST   /api/v2/hotspot/vouchers
POST   /api/v2/hotspot/vouchers/bulk           (async job → job_id)
POST   /api/v2/hotspot/vouchers/{id}/revoke
GET    /api/v2/hotspot/sessions
POST   /api/v2/hotspot/sessions/{id}/disconnect
```

**Idempotency.** Every state-changing route takes an `Idempotency-Key` and
stores the first response against it. A phone on a bad connection will retry
`bundle`, and a second bundle for a claimed device must return the first
answer, not a new secret.

**Bulk generation is asynchronous.** 10,000 vouchers is not an HTTP
request — it returns a `job_id` and the client polls. Synchronous bulk
generation is how this endpoint becomes an outage.

## Artifact 10 — RouterOS compatibility matrix

**REQUIRES VERIFICATION in full.** Nothing below is confirmed on hardware.
This is the matrix to fill, and per the brief's closing note it must be
filled on 2–3 real units before any implementation.

| model | Wi-Fi | RouterOS | phone bootstrap | notes |
|---|---|---|---|---|
| hAP ac2 | yes | 7.x | **candidate** | the reference device |
| hAP ax2 | yes | 7.x | **candidate** | |
| hAP ax3 | yes | 7.x | **candidate** | |
| hEX (RB750Gr3) | **no** | 7.x | no radio → laptop or pre-stage | |
| hEX S | **no** | 7.x | as above | |
| RB5009 | **no** | 7.x | as above | |
| RB4011 | variant | 7.x | only the wireless variant | |
| RB750 (older) | **no** | may be 6.x | likely unsupportable | |
| RB951 | yes | may be 6.x | needs upgrade first | MIPS, verify v7 |

Per device, record: does it reach v7; does REST answer on the LAN at factory
default; what the default credential state is; does WireGuard establish
outbound through CGNAT; does HotSpot + RADIUS + CoA work; how long a full
provision takes; and what happens when power is cut mid-provision.

That last one is not optional. It is the most likely field failure and it
decides the rollback design.

## Artifact 11 — Failure and rollback model

**PROPOSED.** The brief's section 15 is right that a half-configured router
must never be silent. The two-phase design makes this tractable, because
after Phase 1 the router is reachable regardless of how badly Phase 2 goes.

- **Phase 2 is a sequence of named, individually recorded steps.** The state
  is always the last completed step, never a guess.
- **Each step is idempotent**, so a retry resumes rather than restarts.
- **Configuration is generated from the backend**, versioned, and applied
  with a RouterOS `/system scheduler` safety net: a script that reverts to
  the last known-good export if the backend does not confirm within N
  minutes. This is the standard defence against locking yourself out of a
  remote router and it is what makes remote reconfiguration safe at all.
- **Rollback target** is the export taken immediately after bootstrap, so
  rollback lands on a manageable router rather than a factory-reset one.
- **`ORPHANED`** (bundle pushed, tunnel never appeared) gets its own queue
  and its own operator screen. It is the state that will generate support
  calls, and it must be visible without a database query.

## Artifact 12 — Deployment architecture

**PROPOSED.** The plugin stays where it is. Everything new is separate.

```
                   customer phone / browser
                              │
                     ┌────────┴────────┐
                     │  Network API    │  (new service)
                     │  control plane  │
                     └────┬───────┬────┘
                          │       │
              ┌───────────┘       └───────────┐
              ▼                               ▼
      PostgreSQL + Redis              WireGuard concentrator
              ▲                               ▲
              │                               │ outbound, router-initiated
        FreeRADIUS ◄── RADIUS/CoA ──────► MikroTik fleet
              │
              └──── reads plans/vouchers, writes radacct

   uCRM + dishnet-hybrid-sudan plugin  ──► calls Network API (Phase 4)
```

**Sized for interpretation (1) of §0.2** — tens of tenants, tens of
thousands of sessions. The concentrator is the component to watch: every
managed router holds a persistent tunnel, so it is sized by *device count*,
not traffic, and it must be horizontally scalable from day one. **REQUIRES
VERIFICATION** once §0.2 is answered.

## Artifact 13 — Test strategy

The rule this project has earned the hard way, three times in one day: **a
test written from my own assumptions tests my assumptions.** Every fix that
actually caught something read production state or real source. So:

1. **Tenant isolation suite** — written first, before the feature, per
   artifact 7. Two tenants, every endpoint, assert 404.
2. **A RouterOS harness, not a mock.** A container running a real RouterOS
   CHR instance, factory-reset between runs, driven by the real provisioner.
   A fake MikroTik would pass while the real one rejects the command — this
   is the `sendText`/`body`-vs-`text` lesson applied to a much more expensive
   surface.
3. **Hardware test bench** — 2–3 real units, per the brief's closing note,
   because CHR has no radio, no RouterBOARD serial and no factory-reset
   behaviour to speak of. The bootstrap flow can only be proven on metal.
4. **Voucher lifecycle** — generation, activation, concurrent redemption of
   one code (must fail for the second), expiry, revocation mid-session.
5. **Failure injection** — power cut at each Phase 2 step, tunnel dropped
   mid-provision, RADIUS unavailable, bundle replayed, serial claimed twice.
6. **Load** — the brief's numbers against the answer to §0.2.

---

## Implementation plan

Sequenced so that the riskiest unknown is settled first and nothing is built
on an unproven assumption.

| phase | what | exit condition |
|---|---|---|
| **0. Prove the bootstrap** | 2–3 real units. Fill artifact 10 by hand, no product code. Push a bundle manually, bring up a tunnel through real CGNAT. | One router, factory-reset, manageable from DishNet over its own outbound tunnel. **If this fails, nothing after it matters.** |
| **1. Tenancy and identity** | Customer principal, tenant model, RLS, audit log, isolation suite. | Two tenants provably cannot see each other. |
| **2. Device lifecycle** | Registry, pairing, bundle issue, Phase 2 orchestration, failure/rollback, operator screens. | A router provisions end to end, and a power cut at any step is recoverable. |
| **3. RADIUS and vouchers** | FreeRADIUS, plans, vouchers, sessions, CoA, live dashboard. | The brief's §24 flow works: scan → provision → plan → voucher → connect → enforced → accounted → expired. |
| **4. Billing integration** | uCRM reconciliation, suspension rules, plugin read-only views. | Voucher revenue reaches the books. |

**Phase 0 is not a formality.** If a factory-reset hAP ac2 behind a real
CGNAT connection cannot be brought under management from a phone in under
five minutes, the product described in this brief does not exist and the
design must change before anything is built on it.

---

## What must be answered before Phase 1

1. **§0.2 — what does "thousands of customers" mean?** Sizing, tenancy and
   deployment all hang on it.
2. **Who supplies the routers?** If DishNet sells most of them, pre-staging
   is cheaper, more reliable and a better customer experience than field
   provisioning — and it changes the priority of the entire QR flow.
3. **Is a hotspot reseller a uCRM client, or a new service type on one?**
   Billing decision, blocks artifact 2.
4. **Which models will actually be supported?** Restricting to Wi-Fi hAP
   models makes the brief's experience achievable. Including hEX-class means
   accepting a second, different provisioning path.
5. **Where would this run?** The plugin lives in the uCRM container. The
   control plane, PostgreSQL, Redis, FreeRADIUS and the concentrator do not
   fit there, and a WireGuard concentrator needs a stable public endpoint.

## What this document is not

It is not a commitment that the brief is buildable as written. Section 0
lists four places where it is not, and Phase 0 exists to test the largest
remaining assumption on hardware rather than on paper.

No production code has been modified. The only change in this commit is this
file.

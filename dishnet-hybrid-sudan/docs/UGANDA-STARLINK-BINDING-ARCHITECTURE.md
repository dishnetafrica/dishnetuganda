# Starlink Kit ↔ Customer Binding — Architecture Research & Proposed Plan

**Status:** research only. No production code, migration, UI or manual changed.
**Date:** 12 September 2026 · **Branch:** `claude/study-this-jhe2eg`
**Companion:** `docs/UGANDA-STARLINK-INSTALLATION-RESEARCH.md`

## Evidence labelling

Per instruction, every claim carries its provenance:

| Tag | Meaning |
|---|---|
| **[CODE]** | Verified in DishNet code. File and line read directly. |
| **[STARLINK]** | Starlink official documentation, cited. |
| **[INFERENCE]** | My reasoning from the above. Not observed. |
| **[OPEN]** | Cannot verify here. Needs a person, a live box, or Starlink. |

External fetch is still blocked in this environment (`starlink.com` and every other host
answer `403 to CONNECT`). **[STARLINK]** claims come from web *search* extracts of official
`starlink.com` pages, cited to the page. I have not opened those pages. Where a decision
depends on exact wording, treat it as **[OPEN]** and confirm.

---

# 1. The current binding architecture, end to end

## 1.1 The chain as built

```
uCRM Client ──┬── uCRM Service ─────┐
              │                     │
              │   (scheduling job)  │   (Splynx ticket)
              │        │            │         │
              │        │            ▼         │
              │        │   equipment_assignments ──── stock_units
              │        │            │
              │        │            ├─ starlink_account       ACC-…
              │        │            ├─ starlink_service_line  SL-…
              │        │            ├─ terminal_id            ut…
              │        │            ├─ router_id
              │        │            └─ kit_serial             KIT…
              │        │                        │
              │        │      data-report plugin└── sl_usage.json  (joined on kit_number)
              │        │
              ▼        ▼
       job_checkins.json   splynx_tickets.json → photos
        (GPS, uCRM job)      (install photos, ticket id)
```

The two bottom-left boxes are the problem: **neither installation record connects to
`equipment_assignments` at all.**

## 1.2 Step-by-step

### Step 1 — uCRM Client
- **Table:** uCRM's own (external). **[CODE]**
- **Key field:** client id (integer).
- **Created by:** uCRM / KYC onboarding.
- **Guaranteed?** Yes. `StockService::install()` **refuses** a zero client id with
  *"Installing needs the uCRM client id, not just a name."* **[CODE]** `lib/StockService.php:915`
- **Multiple kits/services:** supported — one client, many assignments. **[CODE]**

### Step 2 — uCRM Service
- **Key field:** service id (integer) → `equipment_assignments.crm_service_id`.
- **Guaranteed?** **No. Optional, and in practice always NULL.** **[CODE]**
  `StockService::install()` reads `(int)($data['crm_service_id'] ?? 0)` and no screen sends it.
- **Consequence:** `idx_ea_live_service` (one service → one live kit) **never engages**, because
  the index is partial on `crm_service_id IS NOT NULL`. **[CODE]** `migrations/068:71`

### Step 3 — Job / Installation
**There are two parallel mechanisms and they are not the same system.** **[CODE]**

| | GPS check-in | Install photos |
|---|---|---|
| Action | `install_checkin` / `install_checkout` / `install_mark_ready` | `install_upload_photo`, `install_add_note` |
| File | `includes/api/api_field_ops.php:209–310` | `includes/api/api_support.php:307–330` |
| Id taken | `job_id` **or** `ticket_id` → `$jobId` | `ticket_id` |
| Acts on | `PATCH scheduling/jobs/{id}` in **uCRM** | `SplynxTicketService::savePhoto()` |
| Stored | `job_checkins.json`, `staff_live_locations.json`, `staff_trail_*` | `uploads/install_photos/<ticket_id>/`, `splynx_tickets.json` |
| Links to assignment? | **No** | **No** |

`install_checkin` accepting `job_id ?? ticket_id` into one variable and then PATCHing a uCRM
scheduling job means a caller passing a Splynx ticket id would patch **an unrelated uCRM job with
the same number**, silently. **[CODE]** Whether any caller does is **[OPEN]** — see §6.

### Step 4 — Stock Equipment
- **Table:** `stock_units` **[CODE]** `migrations/036_stock_tables.sql:24–45`
- **Fields:** `serial_number`, `secondary_serial`, `status`, `location_type/ref/name`,
  `crm_client_id`, `crm_service_id`, `job_id`, `starlink_account`, `starlink_status`,
  `condition_grade`, `purchase_ref`, `purchase_cost`.
- **Note:** `stock_units` carries its **own** copy of client/service/account. `mirrorToUnit()`
  pushes from the assignment to the unit. **[CODE]** The assignment is the source of truth; the
  unit columns are a mirror. **[INFERENCE]** — but nothing in the schema *enforces* that direction,
  so a direct UPDATE to `stock_units` would create a silent disagreement. **[CODE]**

### Step 5 — Starlink Kit identifiers
See §2. Captured: `kit_serial` only. **[CODE]**

### Step 6 — Equipment Assignment
- **Table:** `equipment_assignments` **[CODE]** `migrations/068`
- **Guarantees, enforced by SQLite not by convention** **[CODE]**:
  - one live assignment per unit (`idx_ea_live_unit`)
  - one live kit per service (`idx_ea_live_service`, only when service id present)
  - one live assignment per `kit_serial` / `terminal_id` / `router_id` / `starlink_service_line`
  - `RAISE(ABORT)` on editing a released row, and on **any** delete
- **Reverse lookup:** `resolve()` accepts any identifier set and, if two identifiers point at
  different assignments, **returns nothing rather than picking one**. **[CODE]**
  `lib/EquipmentAssignment.php:292`

### Step 7 — Fleet / Data Plugin
- `KitUsage` reads `sl_usage.json` from the sibling `dishnet-data-report` plugin and joins on
  `kit_number` only, sorting by `cycle_key`. **[CODE]** `lib/KitUsage.php:62`
- `StarlinkFleet::build()` walks `liveAssignments()` and attaches usage per kit. **[CODE]**
- **Consequence:** a kit with no `kit_serial` is invisible to usage, and a usage row whose
  `kit_number` does not exactly match is silently unjoined. **[CODE]**

### Step 8 — Blocking / suspension
- `StarlinkBlockService` documents `equipment_assignments` as authoritative for *who owns the kit*
  **[CODE]** `lib/StarlinkBlockService.php:59`, but the operational rows it acts on carry
  `router_id`, `kit_serial` and `client_id` from the **data plugin's** router map. **[CODE]**
- **Consequence:** since our assignments hold no `router_id`, the authoritative table cannot
  currently answer "which customer owns this router". **[INFERENCE from CODE]**

---

# 2. Every identifier, precisely

| Identifier | Example | Who creates it | Where we get it | When | Stable? | Uniquely identifies | Ours to use? |
|---|---|---|---|---|---|---|---|
| **Kit serial** | `KIT404246364BX6` | Starlink (manufacture) | Printed on box/hardware | Unboxing / stock intake | Stable per **box**; changes on kit swap | The kit as shipped | Yes — we own the hardware |
| **Terminal ID** | `ut01301694-01e07c1c-59d52912` | Starlink | On the dish; Starlink portal/API | Install, or portal | Stable per **dish** | The dish itself | Yes |
| **Router ID** | (stored without `Router-` prefix **[CODE]**) | Starlink | Portal / data plugin router map | Portal | Stable per **router** | The router | Yes |
| **Service line** | `SL-DF-16046613-35504-0` | Starlink | Created on activation | Activation | Stable per **subscription** | The subscription | Yes |
| **Starlink account** | `ACC-DF-15973474-59163-60` | Starlink | Our account | Account opening | Stable | Our whole account | Yes |
| **CRM client id** | integer | uCRM | uCRM | Onboarding | Stable | The customer | Yes — **the identity** |
| **CRM service id** | integer | uCRM | uCRM | Subscription created | Stable | The subscription we bill | Yes |
| **Stock unit id** | integer | DishNet | our DB | Stock intake | Stable | The physical unit we hold | Yes |
| **Data-plugin key** | `kit_number` | data-report plugin | `sl_usage.json` | Each collection cycle | Follows kit serial | The kit, by serial | Yes |

**The distinction that matters most** **[INFERENCE]**: *kit serial* identifies a **box**, *terminal
ID* identifies a **dish**, *service line* identifies a **subscription**. They are not
interchangeable. A warranty swap changes the first two and keeps the third. A plan change keeps the
first two and may change the third. Today we record only the first — the least durable of the three.

**[OPEN]** Whether a Starlink kit swap under warranty preserves the terminal ID (I believe not —
new dish, new terminal) and whether the service line survives. Confirm with Starlink before
building the replacement flow in §7-I.

---

# 3. Starlink official API and reseller capability

## 3.1 What is officially offered

- **Who may have API access:** "API access is available for Starlink Authorized Resellers or larger
  Business/Enterprise Customers in order to manage accounts, user terminals, and service."
  **[STARLINK]** — [API setup & authentication](https://starlink.com/support/article/c3be63c6-a7a3-c054-cbb3-2602fac52ccb)
- **Documentation:** `starlink.readme.io/docs`; an account manager supplies the password.
  **[STARLINK]** — same article.
- **Telemetry API:** for enterprise customers with their own data infrastructure, to monitor devices
  and enable near-real-time analysis. **[STARLINK]** —
  [telemetry API](https://starlink.com/support/article/90109cc2-c7ec-31ff-d160-0a87f16ef759)
- **Fields exposed:** user-terminal endpoints return `userTerminalId`, `kitSerialNumber`,
  `dishSerialNumber` and linked routers; service-line detail returns `userTerminalId` and
  `kitSerialNumber`. **[STARLINK]** — as cited above.
- **Activation by API** is documented for eligible accounts. **[STARLINK]** —
  [activate using API](https://starlink.com/support/article/4aa53c87-3b38-619c-1db8-cf59711e2aa5)
- **Enterprise Dashboard** exists for managing terminals and viewing performance. **[STARLINK]** —
  [Enterprise Dashboard](https://starlink.com/support/article/6db4d69f-deb3-0e0f-00ac-7cbecc89e86c),
  [Enterprise Account Management Tools](https://starlink.com/support/article/2a140927-d1e3-de25-9a73-71e1410a76e9)
- **Reseller programme:** [starlink.com/resellers](https://starlink.com/resellers) **[STARLINK]**

**Our schema already uses Starlink's own field names** (`terminal_id` ≈ `userTerminalId`,
`kit_serial` ≈ `kitSerialNumber`). **[CODE + INFERENCE]** That is a real asset: an official API
integration later would populate the columns we already have.

## 3.2 Terminals per service line — a constraint we may already violate

> Only Enterprise accounts with a Global Priority service plan may assign up to **two** Starlink
> terminals per service line, while the Local Priority service plan allows only **one** Starlink
> terminal per service line. **[STARLINK]** —
> [How many terminals can I link to a subscription?](https://starlink.com/support/article/446c37a3-b34e-4c31-f1c2-abd6cdeb2154)

**Our schema assumes one.** `idx_ea_live_sl` is UNIQUE on `starlink_service_line` while live.
**[CODE]** If DishNet ever runs Enterprise Global Priority with two terminals on one service line,
the second assignment **will be rejected by the database**. Not silently — it will fail loudly,
which is the right failure — but it is a design limit to decide on consciously. **[INFERENCE]**

**[OPEN]** Which plan tier DishNet Uganda actually holds, and whether two-terminal service lines
are in scope. This decides whether §7-D changes that index.

## 3.3 Transfers — officially defined

> Enterprise accounts transfer Starlinks using the "Manage" page in three parts: **Cancel Service,
> Unlock, and Add.** **[STARLINK]** —
> [How do Enterprise accounts transfer Starlinks?](https://starlink.com/support/article/e76ca6b1-1c1a-92de-6c99-e47218c5d3d3)

A kit cannot be owned by two accounts at once. **[INFERENCE from the above]** This maps cleanly to
our release→assign model. **[INFERENCE]**

## 3.4 Do we qualify?

**[OPEN] — and I will not guess.** What is known: DishNet holds a Starlink account (`ACC-DF-…`)
with multiple service lines under it, buys kits as supplier purchases, and resells service to
Ugandan customers. That is the shape of a reseller or a larger business customer. Whether Starlink
classifies DishNet as an **Authorized Reseller** or an **Enterprise customer** — and therefore
whether API access is available — is a commercial question **only your Starlink account manager
can answer.** It is the single highest-leverage question in this document; see §Final.

## 3.5 Risks of the current browser-session scraper

`StarlinkPortalConnector` drives undocumented internal endpoints
(`/api/webagg/v2/accounts/service-lines`, `/api/accounts/v3/accounts/contact`, `/auth-rp/auth/user`,
`/api/auth/v1/session/refresh`) with an imported browser cookie. **[CODE]**

| Risk | Severity | Evidence |
|---|---|---|
| No compatibility promise — endpoints can change without notice | **High** | Undocumented internal API **[INFERENCE]** |
| Session expiry needs a human to re-import; there is no login | **High** | *"It does not log in — there is no password to log in with"* **[CODE]** |
| Already realised — the `ACC-DF-15973474-59163-60` cookie is dead and outstanding | **Live now** | This session |
| Two-tier auth: SSO alive while access token expired; a naive reader calls the session dead | Medium | Handled **[CODE]**, but fragile |
| Anti-bot / ToS exposure | **[OPEN]** | Not assessed. Ask Starlink. |
| Credentials are a human's browser session — scope is that person's whole access | Medium | **[INFERENCE]** |

**[INFERENCE]** The scraper is a reasonable bridge and a poor destination. It should not be
extended; it should be contained behind the interface the official API would also satisfy, so a
later swap touches one class.

---

# 4. The correct installation binding — proposed

Designed against what exists, not invented. **[INFERENCE]** throughout; nothing here is built.

```
1  Technician opens the INSTALLATION JOB           (uCRM scheduling job — one id space, see §6)
2  Job already carries: uCRM client id + uCRM SERVICE id      ← service id becomes REQUIRED
3  Technician scans/enters KIT SERIAL               → validated against stock_units
4  System shows the matched stock unit + kit type   → refuses if not in stock / reserved elsewhere
5  Technician enters TERMINAL ID and ROUTER ID      → from the dish and router, at the site
6  Technician enters SERVICE LINE + ACCOUNT         → from activation (may be a later step)
7  System VALIDATES each identifier's shape         → KIT…/ut…/SL-…/ACC-… patterns
8  System creates the EQUIPMENT ASSIGNMENT          → client + service + all five identifiers
9  Install record captured: obstruction, speed, latency, mount, dish GPS, photos, acceptance
10 Photos attach to the INSTALLATION RECORD          → which carries assignment_id
11 Technician completes the job
12 Fleet/Data plugin resolves ANY identifier → exactly one customer, or none
```

**Design rules** **[INFERENCE]**:
- **A missing identifier is empty, never guessed.** Migration 068 already states this. Step 5–6 must
  allow "not available now" and let it be added later via the existing `addIdentifiers()`. **[CODE]**
- **Validation is shape-only, never invention.** We can check that a terminal id looks like `ut…`;
  we cannot check it is *this* dish without the API.
- **The binding may complete in two passes** — physical install now, service line after activation.
  The assignment should be creatable with what is known and completed later, which `addIdentifiers()`
  already supports. **[CODE]**

---

# 5. Multiple services, replacement, transfer, suspension, release

## 5.1 The cases

| Case | Supported today? | Notes |
|---|---|---|
| One customer → one Starlink | **Yes** | **[CODE]** |
| One customer → multiple Starlinks | **Yes** | Many assignments per `crm_client_id`; no index blocks it **[CODE]** |
| One customer → multiple services | **Schema yes, practice no** | `crm_service_id` is never populated **[CODE]** |
| One Starlink → one service | **Yes, enforced** | `idx_ea_live_service` — when service id is present **[CODE]** |
| One service line → two terminals | **No — would be rejected** | `idx_ea_live_sl` is UNIQUE; Starlink permits 2 on Enterprise Global Priority **[STARLINK + CODE]** |
| Replacement kit for existing service | **Yes** | `replaceUnit()` **[CODE]** |

**The unblocker for rows 3 and 4 is the same one-line change: send `crm_service_id` at install.**
**[INFERENCE]**

## 5.2 Replacement (warranty swap)
`replaceUnit($oldUnitId, $newUnitId, …)` releases the old assignment and creates a new one inside
one call. **[CODE]** `lib/EquipmentAssignment.php:207`
- Old row keeps `replaced_by_unit_id`, so the chain stays walkable. **[CODE]**
- Released rows are immutable and undeletable. **[CODE]**
- **[INFERENCE]** The new assignment must carry the **new** kit serial and terminal id, and usually
  the **same** service line — which is exactly why service line must not be copied blindly from the
  old row without a technician confirming it on the portal.

## 5.3 Transfer (customer A → customer B)
**[INFERENCE]** Release from A (reason: transfer), assign to B. Never edit in place — the release
trigger forbids it anyway. **[CODE]** On Starlink's side this is Cancel Service → Unlock → Add.
**[STARLINK]** The two must be done together or the records diverge; **[OPEN]** whether we should
block a transfer in our system until the Starlink side is confirmed.

## 5.4 Suspension
Suspension is a **service state**, not an assignment change. **[INFERENCE]** The assignment stays
live; the customer still holds the kit. Today blocking acts on `router_id` from the data plugin
**[CODE]** — once assignments carry router ids, `resolve()` gives suspension an authoritative
target. **[INFERENCE]**

## 5.5 Release
`release($unitId, …)` sets `released_at` + reason + who. The unit returns to stock. **[CODE]**
Partial unique indexes then free every identifier for reassignment. **[CODE]**

**The rule the user asked for, and it already holds** **[CODE]**: nothing in
`equipment_assignments` resolves by customer name or phone. Ownership is `crm_client_id`, an
integer, and every reverse lookup is an exact match on an indexed identifier or nothing.

---

# 6. Installation photos — the two mechanisms traced

## 6.1 What each actually does **[CODE]**

**GPS check-in** — `includes/api/api_field_ops.php:209`
`$jobId = (int)($body['job_id'] ?? $body['ticket_id'] ?? 0)` → `PATCH scheduling/jobs/{$jobId}`
in uCRM. Writes `job_checkins.json` keyed by that id.

**Photos** — `includes/api/api_support.php:307`
`$ticketId = (int)($body['ticket_id'] ?? 0)` → file at
`uploads/install_photos/<ticket_id>/<photo_type>_<ts>.jpg`, recorded by
`SplynxTicketService::savePhoto()` into `splynx_tickets.json` matching `$t['id'] === $ticketId`.

**These are two different id spaces: uCRM scheduling job ids, and Splynx ticket ids.**
Nothing in the code maps one to the other. **[CODE]**

## 6.2 How a ticket reaches a customer — and why it is not safe to build on

`SplynxTicketService::enrichWithCrm()` resolves ticket → `crm_client_id` in four strategies,
in order **[CODE]** `lib/SplynxTicketService.php:624–690`:

1. `ticket_to_crm` map, built from the uCRM client's **TicketID attribute** — exact, sound.
2. `username_index` — username / identity match.
3. `splynx_to_crm` — Splynx customer id.
4. **Exact name match** on `name_index`.
5. **Partial name match** — splits the ticket's customer name into words > 2 chars and scans every
   CRM name for one containing **all** of them, taking **the first hit and breaking**.

Strategy 5 is precisely the failure mode migration 068 was written to abolish: two customers sharing
name words resolve to whichever happens to be first in the index, with no trace. **[CODE +
INFERENCE]** Install photos inherit that link.

## 6.3 Verdict

**The two mechanisms do not currently refer to the same installation, and neither refers to the
equipment assignment.** **[CODE]** A photo cannot be reliably traced from a customer's kit today.

**[OPEN]** Whether, on the live Uganda box, any caller passes a Splynx ticket id into
`install_checkin` — which would PATCH an unrelated uCRM job of the same number. This is worth
checking on the server before anything else, because it is a live data-integrity question, not a
design one. A one-line check is given in §Final.

## 6.4 What should be added **[INFERENCE]**

An **installation record** that is the single anchor:
`installation_id` → `crm_client_id`, `crm_service_id`, `assignment_id`, `ucrm_job_id`,
`splynx_ticket_id` (nullable), technician, timestamps, and the survey/test results. Photos attach to
`installation_id`, not to a ticket. Both existing mechanisms then become inputs to one record rather
than two parallel truths.

---

# 7. Proposed implementation plan

## A. Current architecture
§1. Sound core (`equipment_assignments` with database-level guarantees); starved inputs; two
disconnected installation mechanisms.

## B. Problems and gaps
1. No screen sends any Starlink identifier. **[CODE]**
2. `crm_service_id` never populated → service-level binding unused. **[CODE]**
3. Install photos keyed to Splynx tickets, resolved to customers partly **by fuzzy name**. **[CODE]**
4. GPS check-in and photos use different id spaces; neither links to the assignment. **[CODE]**
5. Blocking cannot resolve customer-from-router via the authoritative table. **[CODE]**
5b. `stock_install` is the only action in `api_stock.php` with no role check. **[CODE]** — §7-J.
6. Usage joins on kit serial only — the least durable identifier. **[CODE]**
7. `stock_units` mirror can silently diverge from the assignment. **[CODE]**
8. `idx_ea_live_sl` assumes one terminal per service line. **[STARLINK + CODE]**

## C. Recommended architecture
Keep `equipment_assignments` as the single authority — it is already right. Add an **installation
record** as the anchor for the physical event, carrying `assignment_id`. Feed the assignment its
identifiers at the moment the technician holds the hardware. Contain the scraper behind an
interface an official API could later satisfy.

## D. Database changes
| Change | Why | Risk |
|---|---|---|
| New `installations` table (see §6.4) | One anchor for the physical event | Low — additive |
| `installations.assignment_id` FK-ish + unique per assignment | One install record per assignment | Low |
| Photo rows keyed to `installation_id` | Removes the fuzzy-name dependency | Low |
| **Decide** on `idx_ea_live_sl` | Starlink permits 2 terminals/line on Enterprise Global Priority | **Blocked on §3.4/§3.2 [OPEN]** |
| Optional: trigger/check so `stock_units` mirror cannot diverge | Gap 7 | Medium — touches existing writes |
**No change is needed to the five identifier columns. They already exist.** **[CODE]**

## E. UI changes
1. Install screens (`stock_inout`, `stock_hub`, `my_equipment`) gain: service picker (required),
   terminal id, router id, service line, account. **[CODE — these are the only three callers]**
2. Installation completion screen: obstruction result, speed, latency, mount type, dish GPS,
   photos, customer acceptance.
3. Fleet screen: show which identifiers each kit is missing.

## F. API / backend changes
1. Pass the new fields through `stock_install` — `StockService::install()` **already accepts all of
   them**; this is wiring, not new logic. **[CODE]**
2. Shape validation for each identifier (reject malformed; never invent).
3. New actions for the installation record and its photos.
4. `binding_doctor`: report "live assignment with no terminal id" as a named weakness.
5. Decide the `install_checkin` `job_id ?? ticket_id` ambiguity — it should take one, named thing.

## G. Technician workflow
§4.

## H. Fleet / Data plugin workflow
Unchanged in shape: `resolve()` already accepts any identifier and refuses on disagreement.
**[CODE]** Once terminal and router ids exist, the plugin can resolve by either. **[OPEN]** whether
`sl_usage.json` carries anything but `kit_number` — if it carries terminal ids we should join on
those too. Needs a look at the data-report plugin, which is outside this repo.

## I. Replacement / transfer workflow
§5.2–5.5. `replaceUnit()` exists and is correct. **[CODE]** What is missing is the *screen* and the
rule about which identifiers carry over — which depends on the **[OPEN]** question in §2.

## J. Security considerations
1. Starlink identifiers are not secrets, but the **session cookie is** — it must stay in the vault,
   never in config, repo or chat. (Consistent with the standing rule in this project.)
2. **`stock_install` has no role check at all.** **[CODE]** `includes/api/api_stock.php:141`
   In the same file, `stock_checkout` tests `$_stockIsPriv`, `stock_checkin` tests it and then
   ownership of the unit, `stock_unit_update` tests `$_stockIsPriv`, and `stock_unit_delete`
   requires `$isAdmin`. `stock_install` — the action that **creates the customer↔kit binding** —
   tests nothing. `$_stockIsPriv` is computed in `includes/routes.php:1207` and simply never
   consulted on this branch.
   Scope: any authenticated staff session that can reach the route, not the public. **[INFERENCE]**
   — the plugin page itself requires a uCRM login; I have not traced that chain, so the exact
   reachable population is **[OPEN]**.
   Worth noting that `$_stockRoles` is nearly every role anyway (admin, accountant, support_leader,
   field_accountant, sales, sales_staff, field_agent, collection, support) **[CODE]**, so the
   practical difference may be small — but the asymmetry is unintended on its face, and binding a
   kit to the wrong customer is exactly the harm migration 068 exists to prevent.
   **This is a pre-existing finding, unrelated to the new work. I have not changed it.**
3. Photos may contain customer premises and faces — retention and access need a stated policy.
   **[OPEN]**
4. An official API key, if obtained, is a far more powerful credential than a cookie — vault, rotate,
   and scope it. **[INFERENCE]**

## K. Starlink official API option
§3. **The one question to ask the account manager:** does DishNet qualify as an Authorized Reseller
or Enterprise customer for API access, and can we have `starlink.readme.io` credentials?
**[STARLINK]** says those are the two qualifying categories.

## L. Current scraper risks
§3.5. Summary: no compatibility promise, human-dependent sessions, one already dead, unassessed ToS
exposure. Contain, do not extend.

## M. Testing plan
Following this project's existing discipline (121 suites, database-level guarantees pinned by tests):
1. **Identifier validation** — every shape accepted/rejected, including empty (must stay allowed).
2. **Binding completeness** — an assignment created through the new path carries client, service and
   every supplied identifier.
3. **Uniqueness under the new path** — two kits on one service rejected; same terminal twice rejected.
4. **Multiple services** — one client, two services, two kits, each resolving to the right service.
5. **Replacement** — `replaceUnit` leaves exactly one live assignment and a walkable chain.
6. **Transfer** — release then assign; released row immutable (trigger fires).
7. **`resolve()` disagreement** — two identifiers pointing at different assignments returns nothing.
8. **Installation record** — photos land against the assignment, not a ticket; no name matching
   anywhere in the path.
9. **Regression** — Sudan install unchanged with no new config (the standing rule).
10. **Doctor** — `binding_doctor` names the missing-terminal weakness.

---

# 8. Installation SOP — deliberately not written

Unchanged from the companion document: the official PDFs could not be read here, so no SOP.
Needed before it can be written, per kit we actually sell in Uganda:
Starlink **Mini**, **Standard**, **Standard 4 / 4X** (if we sell it), and **the specific mounts
DishNet fits**. **[OPEN]** — which mounts those are is itself a question for your installation team.

---

# Final: the proposed architecture, and what we still need

## The architecture in one paragraph

Keep `equipment_assignments` as the sole authority for who owns which kit — it already enforces, in
the database, one live owner per unit, per service and per Starlink identifier, refuses to resolve
when identifiers disagree, and cannot be edited or deleted after release. Populate it fully at the
moment of installation, when a technician is physically holding the hardware. Add one
**installation record** that anchors the physical event — obstruction, tests, photos, acceptance —
and carries `assignment_id`, so photos stop depending on a fuzzy name match through the Splynx
ticket store. Leave Starlink as the owner of terminal, router and service-line identity; we record
those, never mint them. Contain the browser-session scraper behind an interface an official API
could replace.

## What we need from Starlink

1. **Does DishNet qualify for API access** — Authorized Reseller or Enterprise? And can we get
   `starlink.readme.io` credentials? This single answer decides §3.4, §7-K and §7-L.
2. **Which plan tier do our Uganda service lines run on** — does two-terminals-per-service-line
   apply to us? Decides whether `idx_ea_live_sl` stays as it is.
3. **On a warranty kit swap, what changes** — new kit serial, new terminal id, same service line?
   Decides the replacement flow.
4. **The official installation PDFs** for Mini, Standard and Standard 4/4X, since I cannot fetch them.
5. **[OPEN]** Any ToS position on automated access to the customer portal.

## What we need from your own installation team

1. **Which mounts do we actually fit in Kampala**, and on which roof types?
2. **Who reads the identifiers today** — does anyone record the terminal id at all, on paper or in
   WhatsApp? If there is an existing habit, the screen should match it rather than fight it.
3. **Is activation done at the site or afterwards from the office?** This decides whether the
   binding completes in one pass or two (§4, step 6).
4. **What does the technician carry** — a phone with the Starlink app, a company device, both?
   Decides whether identifier capture can be a camera scan or must be typed.
5. **Lightning and UPS policy** — what do we fit and what do we promise? (§3 of the companion doc.)

## One thing worth checking on the live box now

Whether any caller passes a Splynx ticket id into `install_checkin` — it would PATCH an unrelated
uCRM scheduling job of the same number. This is a live data question, not a design one:

```
docker exec -u nginx ucrm php -r '$d="/data/ucrm/data/plugins/dishnet-hybrid-sudan-data"; $c=@json_decode(@file_get_contents("$d/job_checkins.json"),true)?:[]; $t=@json_decode(@file_get_contents("$d/splynx_tickets.json"),true)?:[]; $ti=[]; foreach($t as $x){$ti[(int)($x["id"]??0)]=true;} $o=array_values(array_filter(array_keys($c),fn($k)=>isset($ti[(int)$k]))); printf("check-ins: %d, tickets: %d, ids present in BOTH: %d\n", count($c), count($t), count($o)); if($o) echo "  overlapping ids: ".implode(", ", array_slice($o,0,20))."\n";'
```

An overlap is not proof of a collision, but a zero overlap would rule it out cheaply.

---

# ADDENDUM — Uganda is Starlink-only, and Jobs are the anchor

Added after direction from DishNet: *"for uganda we are doing only starlink for now so we have to
focus on starlink and we have to use Jobs the way we used in south sudan — in south sudan when we
create job technician gets whatsapp message with link to do installation."*

This resolves the §6 design question. It also changes what I recommend, so the earlier §6.4
proposal ("a new installations table as the anchor") is **superseded** by §A4 below.

## A1. What the South Sudan job flow actually is **[CODE]**

`cron/job_assignment_notify.php` polls **uCRM scheduling jobs** every 5 minutes and sends WhatsApp
via `NotificationService::sendRaw()`:

| Trigger | Message | Link sent |
|---|---|---|
| New / reassigned job | "New Job Has Been Assigned to You" + job details | **ACCEPT JOB** |
| Status 0 → 1 (accepted) | "Thank you for accepting" | **JOB COMPLETED**, **RESCHEDULE**, **ADD COMMENTS** |
| Either | — | Customer gets `installationScheduled(...)` |

Dual-pool assignee identity is already handled: `assignedUserId` → `ucrm_user_id` (admin pool),
`assignees[].userId` → `ftth_crm_client_id` (client pool). **[CODE]** `cron/job_assignment_notify.php:13–16`

**Every one of those four links is the same URL** **[CODE]** `job_assignment_notify.php:144–147`:

```php
$acceptLink   = "{$ucrmBase}/crm/scheduling/job/{$jobId}";
$completeLink = "{$ucrmBase}/crm/scheduling/job/{$jobId}";
$reschedLink  = "{$ucrmBase}/crm/scheduling/job/{$jobId}";
$commentsLink = "{$ucrmBase}/crm/scheduling/job/{$jobId}";
```

**The technician's tool is uCRM's own scheduling-job screen.** It is not a DishNet page. It knows
nothing about `equipment_assignments`, stock units, or Starlink identifiers — and it cannot be
made to, because it is uCRM's UI, not ours. **[CODE + INFERENCE]**

The job title is already inspected for the word `starlink` when notifying the customer **[CODE]**
`job_assignment_notify.php:236`, so Starlink jobs already flow through this path.

## A2. The OTHER installation flow is fibre, and Uganda must not inherit it **[CODE]**

`tabs/support/splynx_my_jobs.php` is a real technician installation screen — assign, photos, notes,
submit data, ready, reject, commission. But look at what `install_submit_data` captures
**[CODE]** `includes/api/api_support.php`:

```
onu_serial      ← Optical Network Unit
olt_port        ← Optical Line Terminal port
signal_db       ← optical signal
testing_status
```

Those are **FTTH fibre fields**. That whole workflow is South Sudan's fibre installation process,
keyed on **Splynx ticket ids**, backed by `splynx_tickets.json` and a Splynx API at
`splynx_url` / `splynx_key` / `splynx_secret`. **[CODE]** `lib/SplynxApiClient.php:11–13`

Uganda is Starlink-only and has no fibre plant. **[per DishNet direction]** So:

- the fibre technician screen is the wrong shape (wrong fields, wrong identity);
- the install-photo mechanism (§6) hangs off **that** flow, which is why it was keyed to tickets;
- and the fuzzy-name ticket→customer matching in §6.2 is a **fibre-era** problem Uganda should
  simply never inherit. **[INFERENCE]**

**This is good news.** We are not fixing the Splynx path for Uganda. We are not using it.

## A3. So the two mechanisms in §6 are now explained

They were never meant to be one system. **[INFERENCE from CODE]**

- **uCRM scheduling job** — the dispatch and notification spine. Generic, all job types.
- **Splynx ticket** — the fibre installation record.

`install_checkin`'s `job_id ?? ticket_id` **[CODE]** is the seam where somebody once tried to make
them interchangeable. For Uganda the answer is clean: **one id space, the uCRM scheduling job id.**
The §6 open question about id collision still matters for the South Sudan install, but it stops
being an architectural question for Uganda.

## A4. Revised recommendation — the Starlink job page

Supersedes §6.4. Keep the South Sudan *shape* the team already knows, change only where the link
points and what the page collects. **[INFERENCE]**

```
Admin creates uCRM scheduling job          ← unchanged, already how SS works
        │
        ▼
cron/job_assignment_notify.php             ← unchanged mechanism, ONE link changed
        │  WhatsApp to technician
        ▼
   "START INSTALLATION" → plugin page, not uCRM's job screen
        │
        ▼
 DishNet Starlink Installation page (new), anchored on the uCRM JOB ID
        ├── shows: customer, SERVICE, address, phone           (from the job)
        ├── captures: kit serial (scan/type) → validates against stock_units
        ├── captures: terminal id, router id, service line, account
        ├── captures: obstruction result, speed, latency, mount type, dish GPS
        ├── captures: photos → against the JOB, not a Splynx ticket
        ├── captures: customer acceptance
        └── on submit → creates the EQUIPMENT ASSIGNMENT (client + service + all identifiers)
        │
        ▼
  Job completed in uCRM  ·  Fleet resolves any identifier → exactly one customer
```

**Why this is the right shape here** **[INFERENCE]**:

1. **It reuses the flow the team already understands.** Technicians already receive a WhatsApp link
   and tap it. Only the destination changes.
2. **The uCRM job already carries client and service**, which is exactly the `crm_service_id` that
   is missing today (§1 step 2). Anchoring on the job supplies it for free.
3. **No new job system.** The uCRM scheduling job *is* the installation record; we attach to it
   rather than inventing a parallel one. That kills the §6.4 "new installations table" idea as an
   *anchor* — we may still want a table, but it hangs off the job id, not off a new concept.
4. **Photos land against the job**, so the fuzzy-name path is bypassed entirely.
5. **Identifier capture happens while the technician holds the hardware** — the only moment it can.

## A5. Open questions this raises **[OPEN]**

1. **Authentication on that link.** uCRM's job URL requires a uCRM login. Our plugin page — does the
   technician already have a session on their phone, or does the link need a token? The existing
   PWA (`splynx_my_jobs`) is behind the normal login and `$isSupportAny2`. **[CODE]** If technicians
   are already logged in on their phones, this is a non-issue; if not, a scoped one-time job token
   is needed, and that is a security design in itself. **This must be answered before building.**
2. **Does the uCRM scheduling job carry the service id**, or only the client id? The notifier reads
   `clientId`, `clientName`, `clientPhone`, `address` **[CODE]** but I have not confirmed a service
   id is present on the job payload. If it is not, the technician must pick the service — which is
   fine, but it changes the screen.
3. **Should the ACCEPT link stay on uCRM** and only the post-acceptance link change? Probably yes —
   acceptance is a uCRM status change and uCRM's screen does it properly. **[INFERENCE]**
4. **One job → one kit, or can one job install two kits?** Affects whether the page binds one
   assignment or several.

## A6. A live defect found while reading this — not fixed

`cron/job_assignment_notify.php` sends Ugandan technicians a **South Sudan support number**:

```
:175   $msg .= "📞 +211 921 443 002\n";     // on the job-accepted message
:196   $msg .= "📞 +211 921 443 002\n";     // on the new-job message
```

and `cron/staff_jobs_summary.php:192` does the same:

```
:192   $msg .= "Need support? 📞 +211 921 443 002\n";
```

**[CODE]** These are live outbound WhatsApp messages to staff, not documentation. A technician on a
Kampala roof who needs help is being given a Juba number. `job_assignment_notify.php:196` also
signs off `🌐 dishnetafrica.com` rather than the Uganda site.

This is the same class of residue already corrected in the manuals, and the fix is the same:
`CustomerContact::support($config)` / `escalation($config)`, which already exist and are already
config-driven. **[CODE]**

**I have not changed it**, because the standing instruction for this task is research only and no
production code. It is a small, self-contained fix whenever you want it.

# 99 — Customer identity, and the uCRM bridge

**Design document. Nothing is decided here, nothing is built, nothing was
installed.** Models A and B are presented so they can be compared; §3 states
what separates them and §12 records what must be chosen.

---

## 0. The problem, measured

`docs/98` established I-1. Restated with the full measurement:

```
mt_customers.ucrm_client_id    integer UNIQUE     -- nullable: "C16 is open"
sole write path                mt_customer_create(p_name text, p_created_by text)
                               -> cannot set it
only writer in the repository  tests/bootstrap.php:268     <- a TEST FIXTURE
uCRM client code in Domain B   CrmApiClient 0 · X-Auth-App-Key 0 · api/v1.0/clients 0
live simulator                 3 customers, 0 linked

mt_services                    id, customer_id, kind, status, started_at, ended_at
                               -> NO uCRM service reference of any kind

blast radius                   18 tables carry customer_id
                               18 foreign keys reference mt_customers(id)
```

Two duplications exist, not one. The second has not been discussed before:

> **Finding I-2 — the principal duplicates uCRM contact data.**
> `mt_principals` holds `display_name`, `phone`, `email`, `credential_hash`.
> Name, phone and email are fields uCRM also owns for the same human being.
> Whatever is decided for `mt_customers` must also be decided for these, or the
> duplication simply moves down one level.

### 0.1 The authorization chain that must not break

Measured in `src/Auth/Authenticator.php` and `src/Tenancy/TenantContext.php`:

```
bearer token
  → mt_auth_resolve_token(token)  →  (principal_id, customer_id)
  → SET LOCAL app.customer_id
  → RLS policy: customer_id = mt_current_customer()
```

The customer is **derived from the token inside the database**. No route takes a
customer id. Every model below must preserve this exactly; a model that requires
resolving the customer from a request field is disqualified before it is
compared.

### 0.2 A precedent already in this estate

The sibling plugin solved the same problem once, and its answer is instructive
(`migrations/026_lte_financial_ledger.sql`):

```sql
lte_subscriber_id  INTEGER NOT NULL,   -- the plugin's own entity
ucrm_client_id     INTEGER NOT NULL,   -- UCRM client ID
ucrm_service_type  TEXT,               -- what UCRM service they have
linked_by          INTEGER             -- Staff who created the link
```

An independent entity, an explicit link, **and provenance for who made it.**
That is Model B in miniature, already running.

### 0.3 A precedent that is a warning

`lib/LeadMatcher.php` matches a person to a uCRM client by the **last nine
digits** of a phone number. The plugin manifest carries this beside the switch
that loosens it:

> *"Strict matching requires 9 digits to agree before a customer is identified.
> Turning this ON restores looser matching, which can identify the **WRONG
> customer** and disclose their balance."*

So a *runtime* identity match between the two systems is already known to be
dangerous here. Both models below therefore store the link; neither infers it
per request.

---

## 1. Model A — the uCRM client is the source of truth

`mt_customers` becomes a **projection**: a local handle for a uCRM client, and
nothing more.

### 1.1 Schema

| Column | Model A |
|---|---|
| `id` uuid | kept — 18 foreign keys depend on it; changing it is not on the table |
| `ucrm_client_id` | **`NOT NULL`**, stays `UNIQUE` |
| `name` | a **cache** of uCRM's client name, for display and search only |
| `status` | **removed or ignored** — uCRM owns commercial status |
| `created_at` | kept, meaning "when this projection was created" |

### 1.2 Creation

`mt_customer_create(p_name, p_created_by)` gains a required uCRM id:

```
mt_customer_create(p_ucrm_client_id integer, p_name text, p_actor text)
```

The old two-argument signature is **dropped**, exactly as migration 020 dropped
four signatures rather than leaving both callable.

Two creation triggers are possible and they are not equivalent:

| | Trigger | Consequence |
|---|---|---|
| A-i | a staff member picks a uCRM client in the bridge plugin | explicit, audited, slow, and cannot happen without a person |
| A-ii | a uCRM `client.add` webhook | automatic, but Domain B then holds a projection for **every** uCRM client, including those with no network service |

A-ii's projection count is the whole uCRM client base. A-i's is only customers
who have a MikroTik service. **This is a real difference and must be chosen.**

### 1.3 Update, deletion, conflict

| Event | Model A |
|---|---|
| uCRM renames the client | the cached `name` is refreshed on next read or webhook; uCRM always wins |
| uCRM archives/deletes the client | **the hard case.** 18 FKs use `ON DELETE RESTRICT`, so the projection cannot be deleted while a site, router, voucher or audit row references it. It must be *marked* — which means Model A needs a local state field after all, contradicting §1.1 |
| Domain B is asked to create a customer with no uCRM client | **refused.** There is no such thing |
| uCRM is unreachable | reads fall back to the cached name; **no new customer can be created** |
| the two names differ | uCRM wins, silently |

> **A-1 — Model A does not remove local state, it renames it.** A projection of
> a deleted client still owns routers and an audit history that cannot be
> deleted. Something local must record "this projection is no longer backed".
> Model A is therefore not "no local fields"; it is "no local *commercial*
> fields".

### 1.4 Migration

`ucrm_client_id NOT NULL` cannot be applied to a table with unlinked rows.
Required first, in order:

1. **A census** of every existing `mt_customers` row on the target database —
   the same discipline as `docs/79`. Production state is NOT ESTABLISHED and
   must not be inferred.
2. A decision for each unlinked row: link it, or delete it. Deletion is blocked
   by `ON DELETE RESTRICT` wherever a site, voucher or audit row exists.
3. Only then the migration.

**In the development schema this is currently trivial and that fact is
misleading**: the simulator's three customers can simply be rebuilt. Production
has not been looked at.

---

## 2. Model B — a Domain-B customer that carries a link

`mt_customers` stays an entity in its own right. `ucrm_client_id` becomes a
**populated, optional-or-required link** with recorded provenance.

### 2.1 Schema

| Column | Model B |
|---|---|
| `id` uuid | unchanged |
| `ucrm_client_id` | `UNIQUE`; **`NOT NULL` is a separate decision (U-2)** |
| `name` | Domain B's own, authoritative for network screens |
| `status` | Domain B's own network-side status, distinct from uCRM's commercial status |
| `ucrm_linked_by` | **new** — who made the link (the sibling plugin's `linked_by`) |
| `ucrm_linked_at` | **new** — when |

### 2.2 Why two entities might be justified

Not "because they already exist" — that is inertia, not a reason. The
defensible reasons, each of which must be true to count:

| | Reason | Is it true here? |
|---|---|---|
| B-i | a network customer may exist before the sale is in uCRM | **operator input** — does DishNet ever install before billing exists? |
| B-ii | one uCRM client may hold several independent network estates needing separate isolation | **open** — `docs/45` C3 (one person → many accounts) is unresolved |
| B-iii | Domain B must keep working when uCRM is down or being upgraded | **true** — uCRM is a container on the same host and does get updated |
| B-iv | a reseller/operator hierarchy sits above the customer | **excluded** — `docs/68` says the ISP hierarchy is a business input, **not** a tenant layer. This is not a valid reason |

### 2.3 Ownership per field

| Field | uCRM | Domain B |
|---|---|---|
| legal name, contact, address | **owns** | — |
| billing status, balance, invoices | **owns** | — |
| tickets | **owns** | — |
| network display name | — | **owns** |
| network status (active/suspended/closed) | — | **owns** |
| sites, routers, vouchers, sessions | — | **owns** |
| the link itself | — | **owns**, with provenance |

### 2.4 Conflict, orphans, lifecycle

| Event | Model B |
|---|---|
| names differ | **both are correct** — they are different fields. The bridge shows uCRM's beside Domain B's rather than reconciling them |
| uCRM client deleted | the link is marked broken; Domain B keeps running; the bridge reports an orphan |
| Domain-B customer with no link | **permitted if `ucrm_client_id` stays nullable.** This is the state that produced I-1, so if B is chosen, permitting it must be a deliberate decision with a rule for how long it may last |
| two Domain-B customers linked to one uCRM client | prevented by `UNIQUE` |
| one Domain-B customer, several uCRM services | needs `mt_services.ucrm_service_id` — **absent today** (U-5) |

---

## 3. What actually separates them

Not recommended — compared.

| | Model A | Model B |
|---|---|---|
| customer may exist without uCRM | no | yes, if nullable |
| survives uCRM being down | reads yes, creation no | fully |
| a second name to reconcile | no | yes — by design, two fields |
| local commercial state | none, in principle; **one flag in practice** (A-1) | explicit |
| migration cost | **census + backfill or delete, blocked by 18 RESTRICT FKs** | add two nullable columns |
| I-1 recurrence | structurally impossible | **possible** — nullable is how I-1 happened |
| `mt_customer_create` | signature changes, old one dropped | unchanged |
| matches `docs/45` §2.1 wording | closely | needs the doc amended or the field ownership stated |
| matches the estate's own precedent (§0.2) | no | **yes** |
| audit of who linked | not needed | required (`ucrm_linked_by`) |

**Both preserve §0.1 unchanged.** Neither resolves the customer from a request
field; in both the token still yields `customer_id` inside the database.

**Neither model, on its own, fixes I-2.** The principal's `phone`/`email`
duplication is a separate decision either way.

### 3.1 What evidence would decide it

| | Question | Who answers |
|---|---|---|
| E-1 | Does DishNet ever provision network service before the customer exists in uCRM? | operator |
| E-2 | How many `mt_customers` rows exist on the production database, and how many could be linked today? | **the census — nobody has looked** |
| E-3 | Can one uCRM client legitimately need two isolated network estates? | operator, and `docs/45` C3 |
| E-4 | Must the Admin panel keep working during a uCRM upgrade? | operator |

**E-2 is the blocking one, and it is exactly the position `docs/79` describes:
production data state is NOT ESTABLISHED and must not be inferred from a
disposable database.**

---

## 4. The identity chain, in both models

```
                       MODEL A                         MODEL B
uCRM Client            SOURCE OF TRUTH                 SOURCE OF TRUTH (commercial)
  │                    ucrm_client_id, NOT NULL        ucrm_client_id, link
  ▼
DishNet Customer       projection                      entity + link
  │                    mt_customers.id (uuid)          mt_customers.id (uuid)
  ▼
Service                mt_services                     mt_services
  │                    uCRM service id — U-5 OPEN      uCRM service id — U-5 OPEN
  ▼
Site                   Domain B, authoritative         same
  ▼
Router                 Domain B, authoritative         same
  ▼
HotSpot / Plan         Domain B, authoritative         same
  ▼
Voucher                Domain B; site-bound (2b)       same
  ▼
Session                Domain B; RADIUS accounting     same
```

Per arrow, identical in both models except where noted:

| Arrow | Source of truth | Identifier | API | Authorization | Mutable? | Audit |
|---|---|---|---|---|---|---|
| uCRM client → customer | uCRM | `ucrm_client_id` | `api/v1.0/clients` | staff, via the bridge | A: no · B: yes | **yes — who linked** |
| customer → service | Domain B | `mt_services.id` | Admin + `/me/services` | RLS | yes | yes |
| service → site | Domain B | `mt_sites.id` | Admin + `/me/sites` | RLS | yes | yes |
| site → router | Domain B | `mt_devices.id` | Admin | RLS + W-2 constraint | yes | yes (W-1) |
| router → HotSpot | Domain B | `mt_plans.id` | Admin + `/me/plans` | RLS | yes | yes |
| site → voucher | Domain B | `mt_vouchers.site_id` | `/me/vouchers` | RLS; **2b site-bound** | **no — frozen at issue** | yes |
| voucher → session | Domain B | RADIUS username | accounting ingest | `dnb_radius`, one function | no | separate store |

> The voucher→site arrow is **immutable by decision** (2b) and the session arrow
> is **not attributable to a router** (`docs/91`). Neither model changes either.

---

## 5. PWA identity

Unchanged, and unchanged by both models. The 18 customer routes measured in
`docs/98` are all `/api/v1/me/...`; there is no `/customers/{id}`.

```
authentication (OTP → token)
  → mt_auth_resolve_token  →  principal_id, customer_id      [inside the database]
  → SET LOCAL app.customer_id
  → RLS decides every row the request may see
```

**The PWA never chooses the customer, and no browser value reaches that
resolution.** Adding `/me/billing` and `/me/support` must not change it: the
bridge resolves `customer → ucrm_client_id` **server-side**, from the stored
link, and calls uCRM with the plugin key.

> **Never resolve the uCRM client from a phone number at request time.** §0.3
> records that the estate already has a switch for loose phone matching whose
> own description says it "can identify the WRONG customer and disclose their
> balance". The link is stored, once, with provenance — not inferred per
> request.

`/me/access-points` does not exist today; sites carry the routers. Whether to
add it is a UI decision, not an identity one.

---

## 6. The uCRM bridge plugin

A native uCRM plugin built to the contract measured in `docs/98` §2: a ZIP with
`manifest.json` at the root, one `public.php`, `main.php` on the tick.

### 6.1 Responsibilities

1. establish **who the staff member is**, from uCRM (§7);
2. add the menu entry;
3. fetch uCRM customer/service context through `api/v1.0/*` with the issued
   `pluginAppKey`;
4. call the Domain-B Admin API server-to-server;
5. render the network panel;
6. carry the staff identity to Domain B as a **server assertion**;
7. return to the browser only what the screen needs.

### 6.2 Prohibitions — restated as design constraints

It must never connect to Domain-B PostgreSQL; never hold `dnb_app`,
`dnb_admin`, `dnb_adminapi`, `dnb_adminwrite` or the owner credential; never
write a Domain-B table or call a provisioning function directly; never bypass
RLS; never hold a MikroTik or FreeRADIUS credential; never store a WireGuard
private key; never duplicate billing or CRM customers; never accept a
`customer_id` from the browser as authority.

The plugin's **only** Domain-B credential is one API token for the bridge
account. That token grants what the Admin API grants and nothing more — which
today is read-only, because no write route is bound (W-4 open).

---

## 7. Staff identity, and the trust boundary

### 7.1 The mechanism, exactly (measured, `lib/UcrmUser.php`)

1. The plugin is served from the uCRM origin, at
   `/crm/_plugins/<name>/public.php`.
2. The browser therefore sends uCRM's session cookies with the request:
   `nms-crm-php-session-id`, `nms-session` (UISP 1.0+), or `PHPSESSID` (older).
3. `public.php`, **server-side**, forwards those cookies to uCRM's
   `/current-user` (`/crm/current-user` since UISP 1.0; both are tried).
4. uCRM answers with the logged-in user, or **403** when nobody is.

No login, no password, no token to configure — and it works **only because the
plugin is same-origin with uCRM**.

### 7.2 The boundary that must not be crossed

> **Domain B must never accept a staff identity from a browser.**

If the panel's JavaScript called the Domain-B API directly with a
`X-Staff-User` header, anyone who could reach that API could name themselves.
The identity is trustworthy **only** on the server side of the plugin, where it
came from uCRM's own answer.

Two arrangements satisfy that, and they are not equivalent:

| | Arrangement | Consequence |
|---|---|---|
| **S-1 — the plugin proxies** | the browser talks only to `public.php?page=…`; PHP calls the Domain-B API and returns the JSON | the Domain-B API needs no new concept; one credential; every call is attributable. The plugin becomes a forwarding surface and its own route table |
| **S-2 — a signed assertion** | the plugin mints a short-lived token naming the verified staff member; the browser calls the Domain-B API with it; Domain B verifies the signature | fewer hops; but Domain B gains a token format, a key to rotate, a clock-skew window, and a replay question |

Under either, the actor written to `mt_audit_log` is **`staff`** — already
permitted by the CHECK constraint. **No new actor kind may be invented**
(`docs/86`, `docs/89`). The actor string arrives as a **parameter from the
identity boundary**, which is precisely what W-1 already requires of the seven
provisioning functions.

### 7.3 What this does and does not close

- It closes **B-2 inside uCRM**: a staff member signed into UISP is identified
  without a second login.
- It does **not** close B-2 for a standalone Domain-B deployment, which has no
  uCRM to ask.
- It does **not** authorize binding any Admin write route. **W-4, W-5 and W-6
  remain open**, and `DenyAllIdentity` remains the production binding until a
  separate instruction says otherwise.

---

## 8. Admin screen placement

| Screen | Final home | Note |
|---|---|---|
| Dashboard (network) | **bridge plugin** | Domain-B data |
| Routers, Router detail | **bridge plugin** | every signal still UNMEASURED (`docs/90`) |
| Sites | **bridge plugin** | reconcile with the uCRM service address — U-5 |
| Provisioning ladder | **bridge plugin** | inert until F6-B |
| Intents | **bridge plugin** | Domain-B only |
| HotSpot plans | **bridge plugin** | |
| Vouchers, Batches | **bridge plugin** | `code` withheld everywhere (`docs/93`) |
| Sessions | **bridge plugin** | not attributable to a router (`docs/91`) |
| Network health, Diagnostics | **bridge plugin** | |
| Audit log (Domain B) | **bridge plugin** | uCRM keeps its own separately |
| Customers (Domain-B list) | **bridge plugin, read-only, links out to uCRM** | must not become an editor |
| Customer CRM, contacts | **uCRM native** | do not rebuild |
| Billing, invoices, payments | **uCRM native** | do not rebuild |
| Support, tickets | **uCRM native** | do not rebuild |
| Staff accounts and roles | **uCRM native** | this is why B-2 closes |
| Customer self-service | **DishNet PWA** | |
| Guest authentication | **MikroTik captive portal** | outside the plugin entirely |

---

## 9. PWA screen ownership

| Screen | API | Source | Status |
|---|---|---|---|
| Home | `/me` | Domain B | **built** |
| My Wi-Fi | `/me/sites`, `/me/sites/{id}` | Domain B | **built** |
| Locations | `/me/sites` | Domain B | **built** |
| Vouchers | `/me/vouchers`, POST, revoke | Domain B | **built** — activation lifecycle unreachable (`docs/93`) |
| Connected devices | `/me/sessions`, disconnect | Domain B | **built** — not attributable to a router |
| Usage | `/me/usage`, `/me/uplink` | Domain B | **built** — uplink not Admin-readable (D-4 open) |
| Plans | `/me/plans` (+PATCH, retire) | Domain B | **built** |
| Queued requests | `/me/intents` | Domain B | **built** |
| Account | `/me` | Domain B (+uCRM under either model) | **partial** — name only; no link (I-1) |
| **Billing** | `/me/billing` | **uCRM** | **UNIMPLEMENTED** — no endpoint, no adapter, and the uCRM field list is unverified |
| **Support** | `/me/support` | **uCRM** | **UNIMPLEMENTED** — same |

Billing and Support are **not** to be fabricated. Both are blocked on §10 Q3 and
on whichever identity model is chosen.

---

## 10. OPERATOR INPUT REQUIRED

None of these can be answered from this repository. Read-only; change nothing.

| # | Question | Blocks |
|---|---|---|
| **Q1** | Exact installed UISP version and uCRM version | everything in the bridge |
| **Q2** | May a disposable UISP/uCRM be stood up for testing — licence and policy? | the entire test plan; without it nothing can be exercised before production |
| **Q3** | Are **client-zone** plugin pages supported in the installed version, and are they wanted? | whether the PWA could ever live inside uCRM |
| **Q4** | Which webhook events does the installed version offer? | Model A-ii (`client.add` creation) |
| **Q5** | Does DishNet ever install network service **before** the customer exists in uCRM? | **E-1 — chooses between A and B** |
| **Q6** | Must the network panel keep working during a uCRM upgrade? | **E-4** |
| **Q7** | `api/v1.0/clients` — which **field names** exist for one client? | the projection's cached fields; Billing |
| **Q8** | Do invoices and tickets appear in the API in this version? | Billing, Support |

**Field names and counts only. No customer names, phone numbers, email
addresses, invoice numbers or amounts.**

And one that is not a question for the operator but a task:

| **E-2** | How many `mt_customers` rows exist in production, and how many can be linked? | **the census — `docs/79` remains the handoff.** Production data state is NOT ESTABLISHED |

---

## 11. Implementation order

Approved architecture first, then:

```
A. customer identity model              <- BLOCKED on Q5, Q6, E-2
B. uCRM → Domain-B customer/service adapter
C. Domain-B API boundary (incl. the bridge account)
D. the thin bridge plugin
E. staff authentication bridge          <- BLOCKED until A–D approved
F. PWA CRM integration (Billing, Support)
G. disposable uCRM integration test     <- BLOCKED on Q2
H. read-only production integration
I. controlled writes, one path at a time
J. physical MikroTik activation         <- F6-B, unchanged
```

**E, F and G may not start until A–D are architecturally approved.**
Nothing in this list is authorized by this document.

---

## 12. What must be decided

| # | Decision | Depends on |
|---|---|---|
| **U-1** | Model **A** or Model **B** | Q5, Q6, E-3 |
| **U-2** | Does `ucrm_client_id` become `NOT NULL`? | U-1; **E-2 first** |
| **U-3** | Who creates a Domain-B customer — a staff member in the bridge (A-i), or a `client.add` webhook (A-ii)? | U-1, Q4 |
| **U-4** | Is `mt_customers.name` authoritative or a cache, and what happens when uCRM's differs? | U-1 |
| **U-5** | Does `mt_services` gain a uCRM service reference? It has **none** today | U-1, Q7 |
| **U-6** | **I-2** — do `mt_principals.phone` / `.email` stay authoritative, become a cache, or leave Domain B? | U-1 |
| **U-7** | **S-1 or S-2** — does the bridge proxy every call, or mint a signed staff assertion? | §7.2 |
| **U-8** | May a Domain-B customer exist unlinked, and if so for how long? | U-1, U-2 |

> **U-2 cannot be closed by choosing a value.** It needs the production census.
> Every week Domain B runs unlinked, more rows accumulate that a later backfill
> must resolve by hand — but guessing the count is exactly the error `docs/79`
> exists to prevent.

---

**No model recommended. No production installation, no migration, no Domain-A
change, no hardware claim, no code written. Stopping here for approval.**

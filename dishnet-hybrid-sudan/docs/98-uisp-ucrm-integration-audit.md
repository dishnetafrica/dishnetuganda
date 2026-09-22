# 98 — UISP/uCRM integration audit

**Documentation only. No code was changed, nothing was installed, and no
production system was contacted.**

---

## 0. The evidence basis, and its limits

Two things were asked for that could not be done, and neither is reported as
done.

### 0.1 The production installation was NOT inspected

`CLAUDE.md` forbids network access, SSH discovery, DSN guessing and production
probing, and this session has no means anyway. Measured this session:

```
https://github.com/Ubiquiti-App/UCRM-plugins   ->  HTTP 403 (proxy refuses CONNECT)
https://help.uisp.com/                         ->  HTTP 000 (no route)
```

So **official UISP/uCRM documentation was also unreachable.** Everything below
marked MEASURED comes from a third source, which turns out to be better than
either: **two working uCRM plugins that are installed on that very server and
whose source is in this repository.**

| Source | What it proves |
|---|---|
| `dishnet-hybrid-sudan/` (v5.18.27) | what a plugin the DishNet uCRM *runs today* sends and expects |
| `dishnet-ai/` (v1.0.0) | a second, independent instance of the same contract |

What that evidence **cannot** establish: the version actually installed, what
the server would *reject*, and any capability these two plugins do not happen to
use. Those are §14, the operator evidence request.

### 0.2 Much of §3–§5 was already decided

`docs/45` and `docs/46` froze the identity chain and the three-actor product
model. This audit does not reopen them; it measures whether the **code** matches
them. In one important case it does not (§6).

---

## 1. Exact UISP/uCRM version — **UNVERIFIED**

Nothing in this repository records the installed version, and it cannot be read
from here. What *is* measured is what the running plugins declare they need:

| | `dishnet-hybrid-sudan` | `dishnet-ai` |
|---|---|---|
| min uCRM | `2.14.0` | `2.14.0` |
| min UNMS/UISP | `1.0.0` | `1.0.0` |
| max | `null` (unbounded) | `null` |

`docs/01` records that uCRM is not a separate installation — in current UISP
releases the CRM is a component of UISP, reached on **8443/tcp**, with
EasyPanel/Traefik alongside on 80/443.

> **Finding M-1 — a manifest key is misspelt.**
> `dishnet-hybrid-sudan/manifest.json` declares **`ucrmVersionCompliability`**.
> `dishnet-ai/manifest.json` declares **`ucrmVersionCompliancy`**. They cannot
> both be right. If uCRM reads the second spelling, the Sudan plugin's minimum
> version constraint is silently not enforced. One-line fix, but it must be
> confirmed against the real installation before touching it.

---

## 2. The official plugin mechanism — MEASURED

### 2.1 Package format

From `build-zip.sh`, which exists specifically to get this right:

> *"uCRM looks for manifest.json at the ROOT of the archive. Zipping the
> containing folder instead of its contents produces 'Plugin manifest could not
> be found in the ZIP archive.'"*

- **A ZIP**, not a tarball.
- `manifest.json` at the **archive root**, not inside a directory.
- The script verifies this by listing the archive after building it.

### 2.2 Manifest schema

```json
{
  "version": "1",
  "information": { "name", "displayName", "description", "url", "version",
                   "ucrmVersionCompliancy": {"min","max"},
                   "unmsVersionCompliancy": {"min","max"}, "author" },
  "executionPeriod": 5,
  "menu": [ { "label": "...", "type": "admin", "target": "iframe" } ],
  "configuration": [ { "key", "label", "description", "required", "type", "choices" } ]
}
```

Configuration field types observed in production: `text`, `textarea`,
`checkbox`, `choice` (with a `choices` map).

### 2.3 Execution model

`main.php` is the scheduled entry point. Its own header states:

> *"UISP/UCRM executes this on its plugin schedule (~every 5 minutes)."*

matching `executionPeriod: 5`. **There is no daemon.** A plugin gets a periodic
tick, not a long-running process.

### 2.4 The public surface — one file

From `webhook.php`:

> *"UCRM only exposes public.php directly. This file is included via
> `public.php?page=webhook`."*

Public URL shape, recorded in the same file:

```
https://crm.dishnetafrica.com/crm/_plugins/<plugin-name>/public.php?page=<x>
```

A plugin therefore has **exactly one HTTP entry point** and routes internally by
query string. It cannot publish `/api/v1/admin/routers`.

### 2.5 The plugin context file

uCRM writes `ucrm.json` into the plugin's data directory. Keys used in
production: `ucrmLocalUrl`, `ucrmPublicUrl`, `pluginAppKey`, `pluginPublicUrl`.
`tabs/admin/settings.php` searches three locations for it.

### 2.6 CRM API access

```php
new CrmApiClient(rtrim($crmUrl, '/'), $crmKey, 'X-Auth-App-Key');
```

Endpoints already exercised: `api/v1.0/clients`, `api/v1.0/billing`,
`api/v1.0/payment-methods`, `api/v1.0/quotes`, `api/v1.0/settings`. The key is
the `pluginAppKey` uCRM itself issued — **no credential is configured by hand.**

### 2.7 Staff authentication — the important one

`lib/UcrmUser.php`, verbatim:

> *"uCRM does not authenticate plugin pages, but it does give a plugin a way to
> ask: the plugin is served from the same host as uCRM, so the browser sends
> uCRM's session cookies along with the request. Forwarding those to uCRM's
> `/current-user` endpoint returns the logged-in user, or 403 if there is none.
> That means no login, no password and no token to configure."*

Cookies forwarded: `nms-crm-php-session-id`, `nms-session` (UISP 1.0+),
`PHPSESSID` (older uCRM). The endpoint moved to `/crm/current-user` in UISP 1.0,
so both paths are tried.

**This works only because the plugin is same-origin with uCRM.** A panel served
from another host or port receives no cookie and cannot use it.

### 2.8 Webhooks — uCRM to plugin

Wired manually in **System → Webhooks**, pointing at `public.php?page=webhook`,
with a shared secret in header `X-Crm-Key`. Events already consumed:
`client.add`, `invoice.add`, `payment.add`, `service.suspend`, `service.end`,
`service.activate`.

### 2.9 Storage

The plugin data directory survives updates (`lib/bootstrap_data.php`). The
existing plugin stores state as **SQLite databases and JSON files** inside it.

> **No plugin in this repository has a PostgreSQL database of its own, and
> nothing observed suggests uCRM offers one.**

### 2.10 Client zone — **UNVERIFIED**

Both manifests declare `"type": "admin"` only. A `"client"` menu type appears
nowhere in this repository, so this audit **cannot say** whether the installed
version supports a client-zone plugin page. §14 asks the operator.

---

## 3. Is RC1 installable through uCRM's plugin mechanism? — **NO**

Compared directly: `dishnet-mikrotik-0.1.0-rc1` against §2.

| Requirement | uCRM plugin contract | RC1 | |
|---|---|---|---|
| Archive | `.zip`, `manifest.json` at root | `.tar.gz`, `plugin/plugin.json` nested | ✗ |
| Manifest schema | `information` / `executionPeriod` / `menu` / `configuration` | `id` / `domain` / `api` / `gates` / `writes` | ✗ |
| Runtime storage | data dir; SQLite/JSON | **PostgreSQL 14+, its own database** | ✗ |
| Database roles | none available | **12 cluster-wide roles, `CREATE ROLE`** | ✗ |
| Isolation model | none | **FORCE RLS + SECURITY DEFINER + 6 definer roles** | ✗ |
| Background work | ~5-minute tick of `main.php` | long-running `bin/worker.php` | ✗ |
| HTTP surface | one file, `public.php?page=` | 15 routes under `/api/v1/admin/*` | ✗ |
| Staff identity | uCRM session → `/current-user` | `DenyAllIdentity` (W-4 open) | ✗ |
| Install | upload ZIP in the UI | `bootstrap.sql` + `plugin.php install` | ✗ |

### 3.1 What is missing (question B)

Not a gap list — a category error. Making RC1 a uCRM plugin would mean deleting
its database, its twelve roles, its row-level security, its definer-function
boundary and its worker. **Every control the last several months established
lives in PostgreSQL features a uCRM plugin does not have.**

`docs/97` closed B-1 by proving the installer never holds a credential;
`docs/85` built the Admin write floor on `SECURITY DEFINER` + `FORCE RLS`;
`docs/70` chose a site-keyed SQL mechanism for FreeRADIUS. None of that survives
translation into a SQLite file in a plugin data directory.

### 3.2 Can Domain B remain separate with a thin plugin frontend? (question C)

**Yes, and that is the recommendation.** The plugin contract gives exactly what
Domain B lacks and nothing it would have to give up:

| Domain B lacks | The uCRM plugin supplies |
|---|---|
| a staff identity (B-2 / W-4) | `/current-user` — **already solved in the sibling plugin** |
| a place in the operator's daily tool | an admin menu entry |
| business-event awareness | webhooks |
| customer/billing truth | `api/v1.0/clients`, `api/v1.0/billing` with an issued key |

> ### Recommendation R-1 — the thin bridge
>
> ```
>   UISP/uCRM  ──menu──►  dishnet-mikrotik plugin (thin, ZIP, public.php)
>                             │  identity: uCRM /current-user
>                             │  transport: server-to-server HTTP, loopback
>                             ▼
>                        Domain-B Admin API  (RC1, unchanged)
>                             │
>                             ▼
>                        Domain-B PostgreSQL (RLS, definer roles)
> ```
>
> The plugin renders the panel and proves who the staff member is. It holds
> **no** Domain-B credential beyond one API token, **never** touches Domain-B
> tables, and **never** connects to Domain-B PostgreSQL.

### 3.3 Which parts go where (questions D and E)

| Lives **in** the plugin | Lives **outside**, in Domain B |
|---|---|
| the admin menu entry | the PostgreSQL schema and all twelve roles |
| `public.php` routing | RLS, definer functions, the audit log |
| the staff identity bridge | the Admin API and its projections |
| panel static assets (or a proxied iframe) | the provisioning queue and worker |
| uCRM webhook receipt | RADIUS publication, vouchers, sessions |
| the uCRM API adapter | every hardware adapter (F6-B) |

**Nothing that enforces a boundary may move into the plugin.**

---

## 4. System-of-record matrix — proposed, for approval

| Object | Master | Domain B holds |
|---|---|---|
| Person / company | **uCRM** | nothing |
| Customer (billing subject) | **uCRM** | a reference + a display name |
| Contact details | **uCRM** | nothing |
| CRM status | **uCRM** | nothing |
| Invoices, payments, billing | **uCRM** | nothing |
| Tickets / support | **uCRM** | nothing |
| Staff identity | **uCRM** | nothing — consumed per request |
| Service (the sold product) | **uCRM** | a reference; `mt_services` is the network-side view |
| Site (physical location) | **Domain B** | authoritative |
| Router identity, serial, model, RouterOS | **Domain B** | authoritative |
| WireGuard identity, tunnel address | **Domain B** | authoritative |
| Router lifecycle, desired config, intents | **Domain B** | authoritative |
| HotSpot plans, vouchers, batches | **Domain B** | authoritative |
| Sessions, accounting, telemetry | **Domain B** | authoritative |
| AAA credentials | **Domain B** → `radius` DB | authoritative |
| Domain-B audit | **Domain B** | authoritative |

This matches `docs/45` §2.1, which already states the DishNet account "lives in
uCRM (commercial)" while the HotSpot user lives in "router / RADIUS (network)".

---

## 5. Customer identity bridge — and the defect that blocks it

### 5.1 The chain (`docs/45` §2, unchanged)

```
uCRM Client  ──►  DishNet Customer  ──►  Service  ──►  Site
                                                        └──►  Router
                                                               └──►  Voucher
                                                                      └──►  Device ──► Session
```

### 5.2 **Finding I-1 — Domain B is currently a second, unlinked customer master**

Measured, not inferred:

```
mt_customers.ucrm_client_id   integer UNIQUE   -- nullable: C16 is open

sole write path:   mt_customer_create(p_name text, p_created_by text)
                   -> takes no uCRM identifier and cannot set one

writers of ucrm_client_id anywhere in this repository:
                   tests/bootstrap.php:268     <- a TEST FIXTURE
                   (nothing else)

uCRM client code inside Domain B:
                   CrmApiClient        0 files
                   X-Auth-App-Key      0 files
                   api/v1.0/clients    0 files

live simulator estate:  3 customers, 0 with a ucrm_client_id
```

The column exists, is `UNIQUE`, and is read by two Admin projections. **Nothing
in production can ever populate it.** So every customer Domain B creates is
unlinked, and a uCRM client and a Domain-B customer describing the same business
have no relationship a query can follow.

This is exactly the outcome to avoid, and it is present now. It is **not** a
design decision that was taken — `docs/45` says the opposite — it is an
unimplemented link, left open as C16.

### 5.3 What must be decided (not decided here)

| # | Question |
|---|---|
| **U-1** | Is `mt_customers` a **projection** of a uCRM client (reference + cached name), or an independent record that *may* carry a link? |
| **U-2** | Does `ucrm_client_id` become `NOT NULL`? If so, `mt_customer_create` must require it, and existing rows need a census first. |
| **U-3** | Who creates a Domain-B customer — an operator in the plugin, or a uCRM `client.add` webhook? |
| **U-4** | Is `name` in `mt_customers` authoritative or a cache, and what happens when uCRM's differs? |
| **U-5** | Does `mt_services` reference a uCRM service id, and under whose authority? |

> **U-1 and U-2 are the ones that cannot wait.** Every week Domain B runs
> unlinked, more rows accumulate that a later backfill must resolve by hand.

### 5.4 The PWA authorization rule stands (`docs/45` §3)

The customer is **server-derived**, never client-supplied. Measured: all 18
customer routes are `/api/v1/me/...` — there is no `/customers/{id}` on the
customer API at all.

---

## 6. Three interfaces — measured against what exists

### Interface 1 — Staff: uCRM + the DishNet plugin

| Screen | Today | Under R-1 |
|---|---|---|
| Dashboard, Routers, Sites, Provisioning, HotSpot, Sessions, Intents, Diagnostics, Audit | `panel/`, 13 Admin projections, **served outside uCRM** | same code, reached through the plugin menu |
| Customers, Billing, Invoices, Support | **not built, and must not be** | uCRM's own screens |

### Interface 2 — Customer: the DishNet PWA

Domain-B customer API, measured — **18 routes**:

```
auth/request-code   auth/verify   auth/logout
me                  me/services   me/sites        me/sites/{id}
me/plans (+{id}, retire)          me/entitlements
me/vouchers (+revoke)             me/sessions (+disconnect)
me/usage            me/uplink     me/intents
```

> **Finding P-1 — billing and support do not exist in Domain B.**
> `grep -cE "billing|invoice|support|ticket" src/Api/Routes.php` → **0**.
> They can only come from uCRM, and no adapter exists.

### Interface 3 — Guest: the MikroTik captive portal

Unchanged and still unbuilt. Every closed decision holds: Model B publication at
redemption, generated AAA credentials, the voucher code never entering the AAA
path, `dnb_portal` as the role, site authority from `voucher.site_id`,
`nas_claimed` untrusted, the AAA Publisher separate. **The portal is not part of
the uCRM plugin and must never be reachable through it.**

---

## 7. API boundary

```
        PWA                     uCRM admin UI (staff)
         │                              │
         │                       DishNet plugin (thin)
         │                         │           │
         ▼                         ▼           ▼
   DishNet Customer API      uCRM REST     Domain-B Admin API
         │                   api/v1.0/*      /api/v1/admin/*
    ┌────┴─────┐                                  │
    ▼          ▼                                  ▼
 uCRM REST   Domain B  ◄──────────────────  Domain-B PostgreSQL
 (billing)   (network)                       RLS + definer roles
```

**Forbidden, and each already true today:**

| Path | Status |
|---|---|
| PWA → uCRM database | never existed |
| PWA → Domain-B database | never existed; the PWA holds no DB access |
| PWA → MikroTik | blocked by F6-B |
| plugin → Domain-B tables | must never be built |
| plugin → Domain-B PostgreSQL | must never be built |
| guest portal → anything but one function | `dnb_portal`, P-1 CLOSED |

Adapters to introduce (names for discussion, none built):
`UcrmCustomerProvider`, `UcrmBillingProvider`, `UcrmSupportProvider`,
`UcrmServiceProvider` — each a read-through to `api/v1.0/*` with the issued
`pluginAppKey`, never a database connection.

---

## 8. PWA screen map

| Screen | Endpoint | Source | Auth | Status |
|---|---|---|---|---|
| Home | `/me` | Domain B | session, server-derived | **built** |
| My Wi-Fi | `/me/sites`, `/me/sites/{id}` | Domain B | same | **built** |
| Locations | `/me/sites` | Domain B | same | **built** |
| Vouchers | `/me/vouchers`, POST, revoke | Domain B | same | **built**; activation lifecycle unreachable (`docs/93`) |
| Connected devices | `/me/sessions`, disconnect | Domain B | same | **built**; not attributable to a router (`docs/91`) |
| Usage | `/me/usage`, `/me/uplink` | Domain B | same | **built**; uplink not Admin-readable (D-4 open) |
| Plans | `/me/plans` (+PATCH, retire) | Domain B | same | **built** |
| Queued requests | `/me/intents` | Domain B | same | **built** |
| **Billing** | — | **uCRM** | — | **NOT BUILT** — no endpoint, no adapter |
| **Support** | — | **uCRM** | — | **NOT BUILT** — no endpoint, no adapter |
| Account | `/me` | uCRM + Domain B | same | **partial** — name only; no uCRM link (I-1) |

Billing and Support are **not** to be fabricated. Both depend on uCRM API
capability that §14 must confirm first.

---

## 9. Admin screen ownership

| Screen | Home | Note |
|---|---|---|
| Customers, Contacts, Invoices, Payments, Tickets | **uCRM native** | do not rebuild |
| Dashboard (network) | plugin | Domain-B data |
| Routers, Router detail | plugin | Domain-B data |
| Sites | plugin | reconcile with uCRM service address — U-5 |
| Provisioning ladder, Intents | plugin | Domain-B only |
| HotSpot: plans, vouchers, batches | plugin | Domain-B only |
| Sessions | plugin | Domain-B only |
| Network health, Diagnostics | plugin | every signal still UNMEASURED (`docs/90`) |
| Audit log | plugin | Domain-B audit only; uCRM keeps its own |
| Customers list (Domain-B) | **plugin, read-only, links out to uCRM** | must not become an editor |

---

## 10. Security boundary

The plugin must not become a shortcut around Domain-B security. Concretely it
must never:

- connect to Domain-B PostgreSQL, under any role;
- hold `dnb_app`, `dnb_admin`, `dnb_adminapi`, `dnb_adminwrite` or the owner
  credential;
- write a Domain-B table, or call a provisioning function directly;
- bypass or disable RLS;
- write `radcheck`/`radreply` or reach FreeRADIUS;
- configure a MikroTik or hold a RouterOS credential;
- expose a WireGuard private key, a router secret, an AAA credential or a
  voucher code;
- accept a `customer_id` from the browser as authority.

**The staff actor.** `mt_audit_log.actor_kind` is CHECK-constrained to
`principal | staff | system`. A uCRM admin arriving through `/current-user` is
**`staff`** — this needs **no new actor kind**, and none may be invented
(`docs/86`, `docs/89`). The actor string must come from the identity boundary as
a parameter, exactly as W-1 requires.

**W-4 restated.** B-2 is open because Domain B has no staff identity. R-1 closes
it *inside uCRM* by consuming `/current-user`. It does **not** close it for a
standalone Domain-B deployment, and this audit does not authorize binding any
Admin write route — W-4, W-5, W-6 all remain open.

---

## 11. Disposable test plan — nothing yet authorized

| | Test | Prerequisite |
|---|---|---|
| A | a disposable UISP/uCRM instance | **is self-hosting permitted, and is there a licence constraint?** — §14 |
| B | plugin install from ZIP | A |
| C | plugin upgrade (install over an existing version, data dir survives) | B |
| D | plugin uninstall, and what uCRM leaves behind | B |
| E | `/current-user` identity: logged-in admin 200, anonymous 403 | B |
| F | `api/v1.0/clients` read with the issued `pluginAppKey` | B |
| G | customer mapping: uCRM client ↔ `mt_customers` after U-1/U-2 | F, U-1, U-2 |
| H | webhook receipt with `X-Crm-Key` | B |
| I | PWA against a bridged customer | G |

A, B and C cannot be designed further until §14 is answered: a plugin test needs
a uCRM to install into, and this session has neither one nor a way to obtain one.

---

## 12. Production read-only rollout — proposed

1. Operator answers §14 from the real installation.
2. Decide U-1…U-5. **No code before that.**
3. Build the thin plugin; exercise it entirely against the disposable instance.
4. Install on production with **every write path disabled**: the panel reads
   Domain B, the uCRM adapter performs `GET` only, no webhook registered.
5. Observe. Confirm `/current-user` identifies real staff and that no uCRM state
   changed.
6. Only then propose writes, one path at a time, each with its own approval.

**Nothing in steps 3–6 is authorized by this document.**

---

## 13. Remaining blockers

| | Blocker | Kind |
|---|---|---|
| **I-1** | Domain B is an unlinked second customer master; `ucrm_client_id` has no writer but a test fixture | **defect — decide U-1/U-2 first** |
| **U-1…U-5** | the identity-bridge decisions | decision |
| **B-2 / W-4** | no staff identity outside uCRM | open; R-1 closes it only inside uCRM |
| **P-1** | no billing or support anywhere in Domain B | gap, gated on §14 |
| **M-1** | `ucrmVersionCompliability` vs `ucrmVersionCompliancy` | defect, one line, needs confirmation |
| **V-1** | installed UISP/uCRM version unknown | **UNVERIFIED** |
| **V-2** | client-zone plugin support unknown | **UNVERIFIED** |
| **V-3** | whether a disposable uCRM may be stood up | **UNVERIFIED** |
| — | F6-B: every hardware claim | unchanged, not authorized |
| — | Decision 5, the census, the portal | unchanged |

---

## 14. Operator evidence request

Only the operator can answer these; this session cannot reach the server. Same
pattern as `docs/79`. **Read-only — change nothing.**

| # | Question | How |
|---|---|---|
| Q1 | UISP version and uCRM version | UISP UI → the version in the footer, or `unms-cli --version` |
| Q2 | Does the plugin list show both plugins as active, and at what versions? | UISP → System → Plugins |
| Q3 | Does the manifest accept `"type": "client"` in `menu`? | UISP → System → Plugins → any plugin's documentation panel, or try a disposable plugin |
| Q4 | Which webhook events does this version offer? | UISP → System → Webhooks → Add → the event list |
| Q5 | Is there an existing webhook pointing at a DishNet plugin, and is it enabled? | same screen |
| Q6 | What does `api/v1.0/clients` return for one client — which fields exist? | UISP → System → API tokens, then one read-only call. **Redact names, phones, emails; report field NAMES only.** |
| Q7 | Do invoices and tickets appear in the API for this version? | as Q6, field names only |
| Q8 | The plugin data directory path and free space | `docker exec -it ucrm ls -la /data/ucrm/data/plugins/` |
| Q9 | May a second, disposable UISP/uCRM be stood up for testing — licence and policy? | operator judgement |
| Q10 | Is the CRM reachable on the loopback interface from other containers on that host? | operator judgement; decides whether the plugin can call Domain B locally |

**Do not send customer names, phone numbers, email addresses, invoice numbers or
amounts. Field names and counts only.**

---

## 15. Answers to the twenty required outputs

| # | | Answer |
|---|---|---|
| 1 | UISP/uCRM version | **UNVERIFIED** (V-1). Plugins declare min uCRM 2.14.0 / UNMS 1.0.0 |
| 2 | Official plugin mechanism | §2 — ZIP, root `manifest.json`, `main.php` tick, single `public.php`, `ucrm.json`, `X-Auth-App-Key` |
| 3 | Current RC compatibility | **NOT installable**, and must not be forced — §3 |
| 4 | Plugin installation path | upload ZIP in UISP → System → Plugins; served at `/crm/_plugins/<name>/public.php` |
| 5 | Admin-zone capabilities | menu entry, iframe page, configuration schema — MEASURED |
| 6 | Client-zone capabilities | **UNVERIFIED** (V-2) |
| 7 | CRM API capabilities | `api/v1.0/clients\|billing\|payment-methods\|quotes\|settings` exercised; full surface UNVERIFIED |
| 8 | Authentication | plugin→uCRM: `X-Auth-App-Key` from `ucrm.json`. staff→plugin: session cookie → `/current-user`. **Same-origin only** |
| 9 | Event/webhook | supported; manual registration; `X-Crm-Key`; six events already used |
| 10 | Database/storage | data dir surviving updates; SQLite/JSON. **No PostgreSQL** |
| 11 | Recommended architecture | **R-1 — thin bridge plugin, Domain B stays separate** (§3.2) |
| 12 | System-of-record matrix | §4 |
| 13 | Customer identity mapping | §5 — blocked by **I-1**; U-1…U-5 open |
| 14 | PWA integration map | §8 — 9 screens built, Billing and Support absent |
| 15 | Admin screen ownership | §9 |
| 16 | Guest portal boundary | §6 Interface 3 — unchanged, outside the plugin |
| 17 | Security boundary | §10 |
| 18 | Disposable test plan | §11 — gated on Q9 |
| 19 | Production read-only rollout | §12 |
| 20 | Remaining blockers | §13 |

---

**No production installation. No database migration. No Domain-A change. No
hardware claim. No code written. Stopping here for approval.**

# 39 — Provider-Side Audit: the two installed Starlink plugins

**Scope:** `dishnet-starlink-finance` and `dishnet-data-report` as actually installed on
the production server.
**Type:** Read-only. Nothing modified, restarted or redeployed. No migration, no
configuration change, no database change.
**Evidence:** three probes run on the live host — a value-blind structural probe, a source
inspection, and the existing `sibling_doctor.php`. No credential value was read into any
output; the probes have no code path that prints a scalar value.

**Identifier redaction:** kit numbers, Starlink account numbers, service-line ids and
router hardware ids appear in the raw probe output but are redacted throughout this
document, per the standing observation rule.

> **INSTALLATION SCOPE (added 2026-09-19).** Every finding here was measured on
> **installation B — Uganda, `crm.dishnetuganda.com:8443` / `209.97.137.203`**. DishNet
> also runs **installation A — South Sudan, `crm.dishnetafrica.com` / `46.101.93.167`**,
> which runs its own `dishnet-data-report` and is where the customer Android application
> operates. **Installation A has never been audited.** Whether the two
> `dishnet-data-report` deployments match in version or fleet ownership is
> **UNVERIFIED**. See docs/00 §2.1 and §16.1.
>
> **`dr_wifi_*` surface — corrected.** This document originally named five actions. The
> installed plugin on installation B implements **26**. Of those, **9 are VERIFIED**
> (5 customer-facing, called by the Android app: `dr_wifi_lookup`,
> `dr_wifi_request_token`, `dr_wifi_change_password`, `dr_wifi_change_confirm`,
> `dr_wifi_list_routers`; 4 administrative: `dr_wifi_get_status`, `dr_wifi_pause_client`,
> `dr_wifi_get_config`, `dr_wifi_test_block`) and **17 remain INFERRED** — implemented,
> but with no traced caller. **Do not promote an inferred action to verified without
> tracing its caller.**

---

## 1. Actual installed plugin paths

Plugins directory: **`/data/ucrm/data/plugins`**

| | `dishnet-starlink-finance` | `dishnet-data-report` |
|---|---|---|
| Path | `/data/ucrm/data/plugins/dishnet-starlink-finance` | `/data/ucrm/data/plugins/dishnet-data-report` |
| Version | **7.3.9** | **2.8.80** |
| Directory mtime | 2026-09-15 21:10 | **2026-09-19 13:00** (same day as audit) |
| Size | 4.4 MB | 2.6 MB |
| PHP files | 12 | 24 |
| Subdirectories | `data`, `lib`, `templates`, `uploads` | `data`, `lib`, `templates`, `backups`, `debug` |
| Entry points | `main.php`, `public.php`, `wifi_manager_api.php` | `main.php`, `public.php`, + 6 cron scripts |

`dishnet-data-report` carries its own cron infrastructure: `cron.php`, `cron_backup.php`,
`cron_auto_block.php`, `cron_orders.php`, `cron_invoice_details.php`,
`cron_test_block_extend.php`, `cron_session.php`.

---

## 2. THE UPGRADE FINDING — confirmed, and worse than flagged

I flagged this as a risk in docs/38 without being able to confirm it. **It is real, and it
applies to both plugins, not one.**

```
dishnet-starlink-finance
  persistent  absent   /data/ucrm/data/plugins/.dishnet-starlink-finance-data
  legacy      EXISTS   /data/ucrm/data/plugins/dishnet-starlink-finance/data
  RESOLVES TO: LEGACY  *** AT RISK ***

dishnet-data-report
  persistent  absent   /data/ucrm/data/plugins/.dishnet-data-report-data
  legacy      EXISTS   /data/ucrm/data/plugins/dishnet-data-report/data
  RESOLVES TO: LEGACY  *** AT RISK ***
```

**Neither plugin has a persistent data directory.** Every file both plugins own lives
inside the plugin's own folder — the directory uCRM replaces when a plugin is upgraded.

### What an upgrade of either plugin would take with it

| Lost | Consequence |
|---|---|
| `sl_kits.json` | `ca_hotspot_authz_router()` returns **503 on all 12 gated actions**. The hotspot stops, including all four `app_paid_access_*` billing actions. |
| `wifi_router_map.json` | Path B fallback gone; router→kit resolution lost |
| `sl_sync_settings.json` | The live Starlink session cookie — requires re-login |
| `dr_accounts.json` | 4 Starlink accounts with their session state |
| `sl_svc_cache.json` | Service-line→kit resolution for 16 service lines |
| `dr_kit_registry.json`, `dr_orders.json`, `dr_plan_cache.json` | Portal liveness, order history, plan cache |
| The plugin's SQLite database (§5) | `auto_block_queue`, `pause_overlay` — location not yet confirmed |

**This is a live production risk that exists today, independent of the MikroTik project.**
It is not caused by anything we are planning, and it is not caused by anything we have
done. Reporting only, as instructed — no fix applied.

### The partial mitigation that already exists

`cron/dr_snapshot.php` in `dishnet-hybrid-sudan` copies ten of these files into *this*
plugin's data directory under `dr_snapshots/`. This plugin's data directory **is** the
persistent sibling form (`.dishnet-hybrid-sudan-data`), so those copies would survive an
upgrade of either sibling.

Whether that backup is actually current is **not yet established** — §9 has the check.
A backup nobody has verified is a belief, not a mitigation.

---

## 3. Actual production Starlink data sources, and the volume reality

### `dishnet-starlink-finance/data/`

| File | Size | Records | Age | Structure |
|---|---|---|---|---|
| `sl_kits.json` | 2,181 B | **2 kits** | 18 h | LIST of 28-field records |
| `sl_accounts.json` | 965 B | 4 accounts | 6 d | LIST of 7-field records |
| `sl_account_cycles.json` | — | — | — | **ABSENT** |
| `accounts.json` | — | — | — | **ABSENT** |

Also present: `config.json`, `crm_clients_cache.json`, `crm_invoice_export.json`,
`crm_services_cache.json`, `crm_starlink_reference.json`, `missing_invoice_alert.json`,
`processed_pdfs.json`, `sl_hardware.json`, `sl_invoices.json`, `sl_orders.json`,
`sl_plan_pricing.json`, `version_history.json`.

Two files this plugin reads — `sl_account_cycles.json` and `accounts.json`, both consumed
by `includes/api/api_crm_misc.php:2893-2894` — **do not exist**. Those reads have been
returning null. Not on the hotspot path, so nothing user-facing breaks, but a report
sourced from them shows nothing and says nothing about why.

### `dishnet-data-report/data/`

| File | Size | Records | Age | Structure |
|---|---|---|---|---|
| `wifi_router_map.json` | 1,658 B | **2 routers** | 1 h | Keyed by router hardware id, 17 fields each |
| `sl_svc_cache.json` | 5,852 B | 16 service lines | 1 h | Keyed by service-line id, 20 fields |
| `dr_kit_registry.json` | 4,851 B | **2 kits** | 1 h | Envelope + `kits` object |
| `dr_accounts.json` | 33,800 B | 4 accounts | 1 h | Keyed by account number |
| `dr_orders.json` | 6,345 B | 1 account / 4 orders | 1 h | Keyed by account number |
| `sl_usage.json` | **2 B** | **0** | 1 h | **EMPTY** |
| `dr_plan_cache.json` | 286 B | 2 plans | 1 h | Keyed by plan id |
| `sl_sync_settings.json` | 7,540 B | 18 keys | 1 h | Settings map (holds the session cookie) |
| `backup_settings.json` | 163 B | 3 keys | 8 h | Backup metadata only |
| `wifi_test_block_state.json` | — | — | — | **ABSENT** |
| `crm_kit_authority.json` | — | — | — | **ABSENT** |

Freshness is good: most files are 1 hour old, so the sync is running.

### The volume finding

**The Starlink hotspot surface covers 2 routers.**

`ca_hotspot_authz_router()` can only authorize a router that appears in `sl_kits.json`
(2 kits) or `wifi_router_map.json` (2 routers). Everything else 404s.

`cron_paid_access.php:10` reasons about *"all 44 routers"*. The production data holds 2
mapped routers and 16 service lines. The comment describes a scale the data does not show.

This partially answers the open question from docs/37 §9. The exact customer count still
needs the table query in §9, but the ceiling is now known: **at most 2 routers can be
authorized**, whatever `hotspot_paid_access` contains.

That materially changes the MikroTik risk calculus. Freezing a 2-router system is a very
different proposition from freezing a 44-router one.

### Two files are empty or absent where the portal expects data

`sl_usage.json` is 2 bytes — an empty array. `sibling_doctor.php` names it *"data usage
shown in the customer portal."* `wifi_test_block_state.json` is absent entirely; it is
named *"WHO IS CURRENTLY BLOCKED — cannot be reconstructed."* Whether either is expected
is not something this audit can settle; both are flagged, neither is touched.

---

## 4. Data ownership and authority — corrected

The source inspection **corrects the model in docs/38**. The two plugins are not peers
over the same data; they have distinct roles, and the Starlink API access is not where I
assumed.

### `dishnet-data-report` is the Starlink client and the WiFi control plane

External endpoints found in its source:

```
https://api.starlink.com/auth-rp/auth/user
https://api.starlink.com/webagg/v2/accounts/service-lines
```

It is the only one of the two that talks to Starlink. It also serves the dealer API: the
repository builds `.../_plugins/dishnet-data-report/public.php` at
`StarlinkBlockService.php:1248`, `StarlinkBlockBridge.php:404` and
`api_customer_app.php:2402,3247`, and the failure message is literally
*"data-report plugin URL not configured."*

So every `dr_wifi_*` action — `dr_wifi_get_status`, `dr_wifi_pause_client`,
`dr_wifi_change_password`, `dr_wifi_get_config`, `dr_wifi_test_block` — is served by
**`dishnet-data-report`**.

`wifi_manager_api.php` lives in `dishnet-starlink-finance`, but nothing on the hotspot
path calls it.

### `dishnet-starlink-finance` is finance and CRM, not Starlink access

Its external endpoints contain **no `api.starlink.com`**. They are `unms-api` (uCRM
internal), the CRM web host, Google OAuth / Drive (`accounts.google.com`,
`oauth2.googleapis.com`), and front-end CDNs. It holds the kit register and billing data,
and backs itself up to Google Drive.

### Authority per file

| File | Authoritative or derived | Owner |
|---|---|---|
| `sl_kits.json` | **Authoritative** — the kit↔customer binding authorization depends on | starlink-finance |
| `sl_accounts.json` | Authoritative — billing days | starlink-finance |
| `wifi_router_map.json` | **Derived** — built by router discovery (`last_router_discovery.json`, `wifi_discovery_ts.json` present) | data-report |
| `sl_svc_cache.json` | **Cache** — name says so; 1 h old | data-report |
| `dr_kit_registry.json` | **Generated snapshot** — has `schema_version`, `generated_at`, `generator`, `contract`, `auto_discovered` | data-report |
| `dr_accounts.json` | Authoritative for session state | data-report |
| `dr_orders.json` | Derived from the Starlink API | data-report |
| `dr_plan_cache.json` | **Cache** | data-report |
| `sl_sync_settings.json` | **Authoritative** — holds the live session cookie | data-report |
| `sl_usage.json` | Derived (currently empty) | data-report |

`dr_kit_registry.json` is self-describing about being generated — it carries a `generator`
field and a 102-character `contract` string. That is the clearest producer evidence in the
whole dataset.

### Producer evidence is INCOMPLETE — stated rather than guessed

The probe searched for `file_put_contents(... '<name>.json')`. For
`dishnet-data-report` it found **nothing**, and for `dishnet-starlink-finance` it found
only `backup_settings.json`, `email_settings.json`, `gdrive_tokens.json`.

That does **not** mean `dishnet-data-report` writes no files — it plainly does, since its
files are 1 hour old. It means both plugins write through a helper (a `saveJson()`-style
function or a variable path) that a literal-filename grep cannot see.

**So "who writes `sl_kits.json`" and "who writes `wifi_router_map.json`" are not yet
established.** The ownership table above is inferred from file naming, plugin role and the
`generator`/`contract` self-description — not from observed write calls. §9 closes it.

I am flagging this rather than presenting the inference as a finding, because a producer
map built from assumptions is exactly the kind of thing that reads as verified later.

---

## 5. Database relationships

**`dishnet-starlink-finance` owns no database tables.** No `CREATE TABLE`, no PDO usage.
It is entirely file-backed.

**`dishnet-data-report` owns a SQLite database:**

| Table | Purpose (from name) |
|---|---|
| `auto_block_queue` | Queue for the auto-block cron |
| `pause_overlay` | Pause state layered over Starlink's own |
| `sqlite_sequence` | SQLite internal (confirms the engine) |

Used by `dr_wifi_change.php` and `cron_auto_block.php`.

The database **file location is not yet confirmed** — §9 locates it. This matters for §2:
if it sits under `dishnet-data-report/data/`, it is inside the upgradeable directory too,
and `dr_snapshot.php` only copies the ten named JSON files, not a `.db` file.

For contrast: `hotspot_paid_access` and `hotspot_session_log` are **also SQLite**, but they
belong to `dishnet-hybrid-sudan` and live in its persistent data directory. Two separate
SQLite databases owned by two different plugins.

---

## 6. Producer/consumer relationships and PWA dependencies

Unchanged from docs/38 and confirmed here:

- 10 sibling files consumed by `dishnet-hybrid-sudan`, all as files, no shared DB, no
  sibling HTTP call **except** the `dr_wifi_*` dealer API to `data-report/public.php`.
- 12 PWA actions behind `ca_hotspot_authz_router()`, including the four
  `app_paid_access_*` billing actions.
- `app_me` (every login) reads three sibling files, for display enrichment only.
- One cron in this plugin reads siblings: `cron/dr_snapshot.php`, which **copies**
  (`DrSnapshot.php:182` is `@copy()`), correcting the claim in the first version of docs/38.
- `cron_paid_access.php` reads no sibling file.

### Neither sibling holds authorization logic — and the dealer API is not authenticated

The search for `authz` / `authorize` / `verify_owner` / `belongs_to` / `can_access`
functions returned **nothing in either plugin**. Name-pattern matching is suggestive, not
proof, but it is corroborated by the repository's own comment at
`api_customer_app.php:2393`:

> *"Authentication: `dr_wifi_get_status` is currently open (staff-facing, behind UCRM
> admin gate). Since this call originates from inside the UCRM host itself, the UCRM admin
> check is implicitly satisfied via the session cookie the browser sent to us, which we
> forward."*

So the dealer endpoints are not independently authenticated. **All customer-facing
authorization for the Starlink hotspot lives in `ca_hotspot_authz_router()` in
`dishnet-hybrid-sudan`.** The siblings are the record; this plugin is the only judge.

That is consistent with the governing invariant — *"AI should never receive information
merely because it exists in your database"* — but it concentrates the entire hotspot
authorization boundary in one function reading two files that sit in an upgradeable
directory. §2 and this section are the same risk seen from two directions.

---

## 7. Credential inventory (metadata only — no value was read into output)

| File | Records | Credential-bearing field names | At rest |
|---|---|---|---|
| `dr_accounts.json` | 4 accounts | `cookie_enc`, `cookie_encrypted`, `cookie_at`, `session_alive`, `session_status`, `last_cookie_refresh`, `session_log`, `cookie_updated`, `cookie_auto_updated`, `cookie_dead_at`, `cookie_checked_at` | Field names indicate **encrypted** |
| `sl_sync_settings.json` | 18 keys | `starlink_cookie` (single string, 5,236 chars), `cookie_saved_at`, `cookie_valid`, `cookie_health`, `cookie_refresh_count`, … | **Encryption status NOT established** |
| `sl_kits.json` | 2 kits | `report_token` (40 chars), `report_token_created` | Per-kit report-sharing token |
| `gdrive_tokens.json` | — | Google Drive OAuth tokens (written by starlink-finance) | Not inspected |

**Actual values: REDACTED. None was read into any output.**

Answering the specific question: **`dr_accounts.json` exists: YES.** Path:
`/data/ucrm/data/plugins/dishnet-data-report/data/dr_accounts.json`. **Contains
session-cookie fields: YES. Number of records: 4. Actual values: REDACTED.**

Two honest qualifications:

1. **`dr_accounts.json` names suggest encryption at rest** (`cookie_enc`,
   `cookie_encrypted`). `sl_sync_settings.json` holds `starlink_cookie` as a plain key with
   a 5,236-character string value and **no `_enc` suffix**. I did **not** read the value, so
   I cannot say whether it is encrypted. Length alone proves nothing. §9 settles it from the
   write path rather than from the value.

2. **My detector over-flags, by design.** It matches field *names*, so `is_bypassed` matched
   on "pass", and `report_token_created`, `cookie_saved_at`, `cookie_valid` are timestamps
   and flags, not secrets. Over-flagging is the safe direction; I am naming it so the table
   is not read as "23 secrets exist."

---

## 8. Corrected production data flow

The flow in the brief has `dishnet-starlink-finance` ahead of `dishnet-data-report`, as if
finance fetched from Starlink and passed data on. **The server shows the opposite.**

```
STARLINK (api.starlink.com)
  auth-rp/auth/user   +   webagg/v2/accounts/service-lines
        │
        │  session cookie held in sl_sync_settings.json
        │  per-account session state in dr_accounts.json (4 accounts, encrypted)
        ▼
┌──────────────────────────────────────────────────────────────────┐
│ dishnet-data-report  v2.8.80    ← THE STARLINK CLIENT            │
│   own crons: cron.php, cron_session, cron_auto_block, cron_orders│
│   own SQLite: auto_block_queue, pause_overlay                    │
│   writes:  wifi_router_map.json   (2 routers, derived)           │
│            sl_svc_cache.json      (16 service lines, cache)      │
│            dr_kit_registry.json   (2 kits, generated)            │
│            dr_orders.json, dr_plan_cache.json, sl_usage.json     │
│   serves:  public.php?action=dr_wifi_*   ← the WiFi control API  │
└──────────────────────────────────────────────────────────────────┘
        │                                    ▲
        │ router map / caches                │ dr_wifi_get_status
        │                                    │ dr_wifi_pause_client
        ▼                                    │ dr_wifi_change_password
┌───────────────────────────────────┐        │
│ dishnet-starlink-finance  v7.3.9  │        │
│   NO api.starlink.com access      │        │
│   NO database tables              │        │
│   holds: sl_kits.json (2 kits)    │        │
│          — THE KIT REGISTER       │        │
│          sl_accounts.json         │        │
│   talks to: unms-api, Google Drive│        │
└───────────────────────────────────┘        │
        │                                    │
        │ sl_kits.json (Path A, HARD)        │
        │ wifi_router_map.json (Path B)      │
        ▼                                    │
┌──────────────────────────────────────────────────────────────────┐
│ dishnet-hybrid-sudan  — THE ONLY AUTHORIZATION BOUNDARY          │
│   OTP → JWT → ca_require_auth()      (no sibling dependency)     │
│   ca_hotspot_authz_router()          503 if sl_kits.json absent  │
│   12 gated actions incl. 4x app_paid_access_*                    │
│   SQLite: hotspot_paid_access, hotspot_session_log               │
│   cron_paid_access.php → reads TABLE, calls dr_wifi_get_status ──┘
│   cron/dr_snapshot.php → @copy() sibling files to dr_snapshots/  │
└──────────────────────────────────────────────────────────────────┘
        │
        ▼
   Customer PWA  (installation B; the Android app runs on installation A)
```

**Three corrections to the brief's diagram:**

1. `dishnet-data-report` comes **first**, not second. It is the Starlink API client.
2. `dishnet-starlink-finance` does **not** reach Starlink at all. It holds the kit register
   and the finance data, and it is downstream.
3. The WiFi controls are **not** reached through `starlink-finance`. They are served by
   `dishnet-data-report/public.php`, called directly by `dishnet-hybrid-sudan`.

---

## 9. What is NOT yet established — one short probe closes it

Four items are unresolved. None blocks the isolation verdict; all affect the risk picture.

1. **Who actually writes `sl_kits.json` and `wifi_router_map.json`** (§4)
2. **Where the `dishnet-data-report` SQLite file lives** — inside the upgradeable
   directory or not (§5)
3. **Whether `starlink_cookie` in `sl_sync_settings.json` is encrypted at rest** (§7)
4. **Whether `dr_snapshots/` actually holds a current backup** — the only mitigation for §2
5. **How many customers use the hotspot** — the `hotspot_paid_access` count (docs/37 §9)

The probe for these is in the accompanying message. It is read-only and passes source
lines through a literal-redacting sanitiser, so a hardcoded secret cannot reach the output.

---

## 10. MikroTik boundary — reconfirmed against the real installation

Nothing found on the server weakens the isolation verdict. It strengthens it: the two
systems do not share a datastore, a table, a file, an API or a cron.

| Concern | Starlink (frozen) | MikroTik (Phase 0 built) | Shared |
|---|---|---|---|
| Upstream | `api.starlink.com` via data-report | None — RADIUS is local | No |
| Device registry | `sl_kits.json` (2 kits) | `radcheck` / NAS table | No |
| Router map | `wifi_router_map.json` (2 routers) | RADIUS `nas` | No |
| Authorization | `ca_hotspot_authz_router()` | FreeRADIUS Access-Accept | No |
| Datastore | uCRM SQLite + data-report SQLite | `dn-phase0-postgres` `127.0.0.1:5433` | No |
| Sessions | `hotspot_session_log` | `radacct` | No |
| Grants | `hotspot_paid_access` | `mt_vouchers` (to build) | No |
| Transport | HTTPS to Starlink | WireGuard `10.66.0.1/24` | No |
| Enforcement | `cron_paid_access.php` + `dr_wifi_*` | RADIUS accounting + CoA | No |
| Credentials | Starlink session cookies | RADIUS shared secret | No |

**Shared, deliberately:** customer identity only — OTP → JWT → `ca_require_auth()`, which
reads no sibling file. Reused, not modified.

### The `hotspot_paid_access` question, answered mechanically

You asked me to confirm a MikroTik voucher can **never accidentally** enter
`hotspot_paid_access`. The honest answer:

**Today, nothing prevents it.** The table is:

```sql
CREATE TABLE IF NOT EXISTS hotspot_paid_access (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    router_id TEXT NOT NULL,          -- free text: no constraint, no foreign key
    fingerprint TEXT NOT NULL,
    crm_client_id INTEGER NOT NULL,
    ...
);
```

`router_id` is unconstrained `TEXT`. `cron_paid_access.php` then does:

```sql
SELECT DISTINCT router_id FROM hotspot_paid_access WHERE status='active'
```

and hands every value to `dr_wifi_get_status`. A MikroTik router id in that column becomes
a Starlink dealer API call against a device Starlink has never heard of.

So the current separation is **by convention**, and convention is exactly what fails
silently — the same failure mode as `FollowUpEvaluator.php:9`: *"telling a model what to
say is a request, and a request fails silently the first time the model is confused."* A
rule that lives only in a document is a request.

To make it **mechanical**, Phase 2 needs one of these — a change, not applied now:

1. **Separate table** (`mt_vouchers`) — necessary but not sufficient on its own; nothing
   stops a future code path writing to the wrong one.
2. **A `CHECK` constraint on `hotspot_paid_access.router_id`** rejecting anything that
   isn't a Starlink router id, so the database refuses the row rather than the reviewer
   catching it. SQLite enforces `CHECK` on insert.
3. **A guard in `cron_paid_access.php`** that resolves each `router_id` against
   `sl_kits.json` / `wifi_router_map.json` before calling the dealer API, and skips with a
   logged reason otherwise.

**Recommendation: 1 + 2 together.** Separate table for the design, `CHECK` constraint so
the invariant is enforced by the engine rather than remembered by a person. Item 3 is
defence in depth and also fixes the case where a Starlink kit is decommissioned but a
stale grant remains.

Adding a `CHECK` constraint to an existing SQLite table requires a table rebuild, which is
a migration — out of scope here, and flagged for Phase 2 planning rather than done.

---

## 11. Risks to address before Phase 2

| # | Risk | Severity | Caused by this project? |
|---|---|---|---|
| 1 | **Both plugins store all data in the upgrade-deleted directory.** An upgrade of either takes the kit register, the router map and the Starlink session cookie. The hotspot 503s. | **High** | No — exists today |
| 2 | `hotspot_paid_access.router_id` has no constraint, so MikroTik/Starlink separation is convention only | **High for Phase 2** | Would be introduced by Phase 2 |
| 3 | `dr_snapshot.php` backup not verified current; it also copies no `.db` file, so data-report's SQLite may be unprotected | Medium | No |
| 4 | `starlink_cookie` encryption at rest unconfirmed | Medium | No |
| 5 | `wifi_test_block_state.json` absent — *"WHO IS CURRENTLY BLOCKED — cannot be reconstructed"* | Medium | No |
| 6 | `sl_usage.json` empty; portal usage display has no source | Low | No |
| 7 | `sl_account_cycles.json` and `accounts.json` absent; two reads in `api_crm_misc.php` return null | Low | No |
| 8 | Dealer API not independently authenticated; all authorization concentrated in one function | Medium | No |
| 9 | Fleet is 2 routers, while `cron_paid_access.php` reasons about 44 | Low (planning accuracy) | No |

Risks 1 and 3 together are the sharpest: the one mitigation for the highest risk is itself
unverified.

**None of these was introduced by the MikroTik work, and none is fixed here.** Reported
only, per instruction.

---

## 12. Constraints honoured

Read-only throughout. No plugin, file, database, JSON payload, uCRM setting, Starlink
function, PWA, Android application or production configuration was modified. Nothing was
restarted or redeployed. No credential, cookie, token, password or key value was read into
any output — the structural probe has no code path that prints a scalar value, and the
source probe redacts string literals of 16 characters or more.

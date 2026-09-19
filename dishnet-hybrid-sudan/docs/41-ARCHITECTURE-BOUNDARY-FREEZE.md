# 41 — Architecture Boundary Freeze: Starlink and MikroTik as Independent Domains

**Status:** BINDING. This document freezes a boundary. It is the reference that later work
is checked against.
**Supersedes:** nothing. **Amends:** nothing.
**Evidence base:** docs/37 (PWA/HotSpot audit), docs/38 (sibling dependency audit),
docs/39 (provider-side audit), docs/40 (five-question closure). Every factual claim below
traces to one of those, all of which were read-only audits against the live server.
**Type:** Architecture governance. No code, schema, data, configuration or deployment was
changed in producing it.

---

## 0. Why this document exists

The audits established that two systems are about to sit beside each other in the same
plugin, serving the same customers, doing superficially similar things — hotspot access
control — on entirely different infrastructure.

That is the exact shape of a system that becomes accidentally coupled. Not by decision, but
by someone reasonably reusing a table that already exists.

The specific mechanism is already documented at docs/39 §10 and docs/40: a MikroTik voucher
written into `hotspot_paid_access` would be picked up by `cron_paid_access.php` and sent to
the **Starlink dealer API** as though it were a Starlink device grant. Nothing in the
database prevents this. `router_id` is unconstrained `TEXT`.

This document exists so that the prohibition is written down before the code is written,
rather than discovered afterwards.

The governing principle is the one already established in this codebase at
`lib/FollowUpEvaluator.php:9`:

> *"telling a model what to say is a request, and a request fails silently the first time
> the model is confused."*

A rule that lives only in a document is a request. §4 therefore states both the rule and
the mechanical enforcement that must accompany it.

---

## 1. What the audits established

Recorded as settled fact, with sources.

| # | Fact | Source |
|---|---|---|
| 1 | The customer PWA code is deployed but **has never served a customer request**. `ca_init_tables()` has never executed. | docs/40 Q5 |
| 2 | `hotspot_paid_access`, `hotspot_session_log`, `hotspot_seen_devices` and `app_jwt_blacklist` are **absent from all 13 databases**, including every retained backup 4–19 Sep | docs/40 Q5 |
| 3 | The existing Starlink HotSpot path **carries no customer traffic today** | docs/40 Q5 |
| 4 | `cron_paid_access.php` has been a **no-op since deployment** — it returns at its first query | docs/40 Q5 |
| 5 | **`dishnet-data-report` is the Starlink API/client layer.** It holds the `api.starlink.com` endpoints and serves every `dr_wifi_*` action from its own `public.php` | docs/39 §4, docs/40 Q3 |
| 6 | **`dishnet-starlink-finance` is downstream** and holds the Starlink kit/finance registry. It has **no** `api.starlink.com` access and **no** database | docs/39 §4 |
| 7 | `sl_kits.json` is a **hard** dependency for authorization — absence returns 503 on all twelve gated actions | docs/38 §3 |
| 8 | `wifi_router_map.json` is the customer-app router mapping, built by `drWifiSaveRouterMap()` *"so the customer app can find router_id"* | docs/40 Q3 |
| 9 | `dr_accounts.json` holds Starlink account/session information, 4 records, encrypted at rest | docs/39 §7, docs/40 Q3 |
| 10 | **Both Starlink plugins store all data in upgrade-deletable locations** | docs/39 §2, docs/40 Q2 |
| 11 | `hotspot_paid_access.router_id` is **unconstrained `TEXT`** | docs/39 §10 |
| 12 | The legacy-database rescue path **may restore a near-empty database** | docs/40 Q5 |
| 13 | The live fleet is **2 kits / 2 mapped routers** against 16 service lines | docs/39 §3 |

**The consequence that shapes this document:** there are no live Starlink HotSpot customers
to protect during this development phase, but the underlying Starlink fleet data is a
production asset and must be preserved. Those are two different obligations, and conflating
them would lead either to over-caution or to carelessness.

---

## 2. DOMAIN A — EXISTING STARLINK (preserve unchanged)

Everything in this domain is **frozen**. It is not removed, not rewritten, not migrated,
not refactored, and not replaced.

### Components

| Component | Role |
|---|---|
| `dishnet-data-report` (v2.8.80) | Starlink API client; device operations; serves `dr_wifi_*` |
| `dishnet-starlink-finance` (v7.3.9) | Starlink kit/finance registry |
| Starlink API client | `api.starlink.com/auth-rp/auth/user`, `.../webagg/v2/accounts/service-lines` |
| Starlink account data | `dr_accounts.json`, `sl_sync_settings.json` |
| Starlink kit registry | `sl_kits.json`, `sl_accounts.json` |
| Router mapping | `wifi_router_map.json` |
| Router operations | `dr_wifi_get_status`, `dr_wifi_pause_client`, `dr_wifi_change_password`, `dr_wifi_get_config`, `dr_wifi_test_block` |
| Existing customer PWA code | `includes/api/api_customer_app.php`, `tabs/customer_app/` |
| Existing HotSpot implementation | `ca_hotspot_authz_router()`, the 12 gated actions, `hotspot_paid_access`, `hotspot_session_log`, `hotspot_seen_devices`, `cron_paid_access.php` |
| Supporting data | `sl_svc_cache.json`, `dr_kit_registry.json`, `dr_orders.json`, `dr_plan_cache.json`, `sl_usage.json`, `auto_block.sqlite3` |

### Rules for Domain A

1. **No removal.** Including code that has never executed. Fact 1 is not a licence to delete.
2. **No rewrite or refactor**, including "while we're in here" cleanups.
3. **No migration** of Starlink data to new locations under this project.
4. **No schema change** to `hotspot_paid_access`, `hotspot_session_log` or
   `hotspot_seen_devices`.
5. **No modification** of the existing PWA, the Android application, or `dr_wifi_*`.
6. **Risks are documented, not remediated** under this project — see §6.

### One clarification, so the freeze is not misread

Fact 1 says the hotspot code has never run. That makes it **low-risk to leave alone**. It
does not make it safe to modify, and it does not make it dead code to remove. It is a
working implementation with a real Starlink registry behind it, and the business may launch
it. Freeze means preserve, not neglect.

---

## 3. DOMAIN B — NEW MIKROTIK (build independently)

Everything here is **new**. Nothing in this domain reads, writes or depends on anything in
Domain A, with the single exception named in §5.

| Component | Status | Notes |
|---|---|---|
| MikroTik device registry | To build | Own identifiers; never Starlink router IDs |
| MikroTik provisioning state | To build | |
| WireGuard peer registry | Partially built | Gateway `10.66.0.1/24`, UDP 51820, proven Phase 0 |
| MikroTik voucher/access model | To build | Own tables; **never** `hotspot_paid_access` |
| FreeRADIUS | Built | `dn-phase0-radius`, 3.2.10, host net, `10.66.0.1:1812/1813` |
| RADIUS accounting | Built | `radacct` in `dn-phase0-postgres` |
| MikroTik HotSpot | To build | RouterOS HotSpot + RADIUS client |
| RouterOS REST management | To build | |
| Reseller/customer control plane | Future | |

**Datastore:** `dn-phase0-postgres`, `postgres:16-alpine`, bound to `127.0.0.1:5433`.
Separate from uCRM's PostgreSQL, separate from the plugin's SQLite, separate from
`auto_block.sqlite3`.

---

## 4. HARD DATA BOUNDARY

### 4.1 Absolute prohibitions

The MikroTik system **must not** use, read, write, extend, alias or derive from:

| Prohibited | Belongs to |
|---|---|
| `sl_kits.json` | Domain A — Starlink kit register |
| `wifi_router_map.json` | Domain A — Starlink router map |
| `dr_accounts.json` | Domain A — Starlink account/session data |
| Starlink session cookies (`sl_sync_settings.json`, `dr_accounts.json`) | Domain A |
| Starlink router IDs (`router_id`, `router_id_full`) as a device registry | Domain A |
| `dr_wifi_*` actions | Domain A — `dishnet-data-report/public.php` |
| `hotspot_paid_access` | Domain A — Starlink paid-access model |
| `hotspot_session_log`, `hotspot_seen_devices` | Domain A |
| `ca_hotspot_authz_router()` | Domain A — Starlink authorization |
| `sl_svc_cache.json`, `dr_kit_registry.json`, `auto_block.sqlite3` | Domain A |

MikroTik gets **its own tables and its own identifiers**.

### 4.2 The single most important rule

> **A MikroTik voucher must NEVER be represented as a row in `hotspot_paid_access`.**

**Why, mechanically.** `cron_paid_access.php` runs:

```sql
SELECT DISTINCT router_id FROM hotspot_paid_access WHERE status='active'
```

and passes every value it finds to `dr_wifi_get_status` — the **Starlink dealer API**. A
MikroTik router identifier in that column becomes a Starlink API call against a device
Starlink has never heard of.

This is not a style preference. It is a live code path that would execute.

### 4.3 Convention is not enough — the required enforcement

Today the separation is convention only. `hotspot_paid_access.router_id` is
unconstrained `TEXT`, with no `CHECK` and no foreign key, so the database would accept a
MikroTik voucher without complaint.

**When Phase 2 builds the MikroTik voucher model, it must deliver both:**

1. **A separate table** — `mt_vouchers` (or equivalent), never a reuse or extension of
   `hotspot_paid_access`. Necessary, but not sufficient on its own: nothing stops a future
   code path writing to the wrong table.
2. **A database-level constraint** — a `CHECK` on `hotspot_paid_access.router_id` that
   rejects anything not a Starlink router identifier, so the engine refuses the row rather
   than a reviewer catching it in diff.

Item 2 is what converts the rule from a request into an invariant. Adding a `CHECK` to an
existing SQLite table requires a table rebuild, which is a migration — **out of scope here,
and listed as a Phase 2 deliverable, not done.**

A third, optional defence: a guard in `cron_paid_access.php` resolving each `router_id`
against `sl_kits.json` / `wifi_router_map.json` before calling the dealer API, skipping with
a logged reason otherwise. This also covers a decommissioned kit with a stale grant.

### 4.4 Naming convention

To make accidental coupling visible in review, every MikroTik artefact carries an explicit
prefix:

- Tables: `mt_` (`mt_devices`, `mt_vouchers`, `mt_sessions`, `mt_wg_peers`)
- API actions: `app_mt_*`
- Functions: `mt_*`
- Config keys: `mt_*`

A MikroTik identifier appearing without its prefix, or a `dr_`/`sl_`/`hotspot_` name
appearing in MikroTik code, is a boundary violation and should fail review on sight.

---

## 5. CUSTOMER EXPERIENCE — the one permitted shared layer

The customer-facing layer **may** eventually present one unified experience:

```
                    DISHNET CUSTOMER EXPERIENCE
                              |
                 Existing PWA / Future App
                              |
             ┌────────────────┴────────────────┐
             │                                 │
       STARLINK PATH                      MIKROTIK PATH
             │                                 │
   Existing Starlink plugins            New MikroTik registry
             │                                 │
     Starlink API/client                  WireGuard
             │                                 │
       Starlink routers                 FreeRADIUS
             │                                 │
     Existing WiFi/HotSpot              MikroTik HotSpot
```

**The network-control backends remain independent. Permanently.**

### What may be shared

**Customer identity only.** `ca_require_auth()` — OTP → JWT (`kind='app'`) — reads no
sibling file and touches no Starlink data (docs/38 §Q5). It may be **reused as-is**.

### How it must be shared

- **Read, not modify.** MikroTik reuses the identity primitive; it does not change it.
- **Branch above the backend, never inside it.** The UI may show a customer their Starlink
  service and their MikroTik hotspot on one screen. That screen calls two independent
  backends. It does not call one backend that decides which network it is talking to.
- **No shared authorization function.** `ca_hotspot_authz_router()` stays Starlink-only.
  MikroTik gets `mt_authz_*`, resolving against the MikroTik registry.
- **No shared session or grant record.** Starlink sessions stay in
  `hotspot_session_log`; MikroTik sessions live in RADIUS `radacct` and `mt_*` tables.

The unification is a **presentation** concern. The moment it becomes a data concern, the
boundary is broken.

---

## 6. EXISTING STARLINK DATA-PERSISTENCE RISKS

Recorded here as the standing register. **None is remediated by this document.** Each
requires its own controlled remediation plan, separately approved.

| ID | Risk | Severity | Source |
|---|---|---|---|
| **R1** | Both Starlink plugins store all data in `<plugin>/data`, the directory uCRM replaces on upgrade. Neither has a persistent `.plugin-data` directory. Both write there actively | **High** | docs/39 §2, docs/40 Q2 |
| **R2** | `sl_kits.json` is **not** included in `dr_snapshots`. The backup covers `dishnet-data-report` files only, so it protects the soft dependency and misses the hard one | **High** | docs/40 Q4 |
| **R3** | `bootstrap_data.php` rescue may restore the near-empty legacy database — see §7 | **High** | docs/40 Q5 |
| **R4** | `auto_block.sqlite3` sits inside `dishnet-data-report/data/`, and `dr_snapshot.php` copies named JSON files only — no `.db` file is backed up | Medium | docs/40 Q1 |
| **R5** | The legacy hybrid database is stale by content yet was modified the morning of the audit, with a migration count identical to live. Something still opens it | Medium | docs/40 Q5 |
| **R6** | `sl_sync_settings.json` holds `starlink_cookie` with no `_enc` suffix; encryption at rest unconfirmed | Medium | docs/39 §7, docs/40 Q3 |
| **R7** | `wifi_test_block_state.json` absent — *"WHO IS CURRENTLY BLOCKED — cannot be reconstructed"* | Medium | docs/39 §3 |
| **R8** | The dealer API is not independently authenticated; all customer-facing authorization is concentrated in one function | Medium | docs/39 §6 |
| **R9** | `sl_account_cycles.json` and `accounts.json` absent; two reads in `api_crm_misc.php` return null | Low | docs/39 §3 |
| **R10** | `sl_usage.json` empty; portal usage display has no source | Low | docs/39 §3 |

**None of these was introduced by the MikroTik project, and none is caused by it.** They are
pre-existing conditions of the Starlink production system, discovered during audit.

### Current data state, for reference

| | Live (`.dishnet-hybrid-sudan-data/plugin.sqlite3`) | Legacy (`dishnet-hybrid-sudan/data/plugin.sqlite3`) |
|---|---|---|
| Size | 17,804 KB | 2,064 KB |
| Tables | 209 | 112 |
| Conversations | **705** | 0 |
| Messages | **5,833** | 0 |
| Followups | **391** | 0 |

---

## 7. THE RESCUE MECHANISM — documented exactly, as required before anyone touches it

`lib/bootstrap_data.php`. This is the precise behaviour, not a paraphrase.

### 7.1 How the data directory is chosen

1. If uCRM supplies `pluginDataDir`, **that wins** — *"it knows better than we do, and it is
   already outside the plugin."*
2. Otherwise, if the plugins root is a writable directory:
   `$dataDir = $parent . '/.' . $plugin . '-data'` — the persistent location.
3. Otherwise `$dataDir = $pluginRoot . '/data'` — the fallback, with this logged:
   *"plugins root not writable — data stays inside the plugin directory and WILL be lost on
   the next upgrade."*

### 7.2 What triggers the rescue

All four conditions must hold simultaneously:

```php
$dataDir !== $legacy
&& is_dir($legacy)
&& !is_file($dataDir . '/plugin.sqlite3')     // ← persistent DB MISSING
&& is_file($legacy . '/plugin.sqlite3')       // ← legacy DB present
```

The third condition is the trigger. **The rescue fires only when the persistent database is
absent.** Under normal operation it never runs.

### 7.3 What it copies

```php
foreach ((scandir($legacy) ?: []) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $from = $legacy . '/' . $entry;
    $to   = $dataDir . '/' . $entry;
    if (is_file($from) && !file_exists($to)) @copy($from, $to);
}
```

Three properties that matter:

- **Copy, never move.** The design comment is explicit: *"if anything here goes wrong the
  original must still be there."*
- **Files only.** `is_file()` excludes directories, so `_backups/` and `dr_snapshots/` are
  **not** copied.
- **Never overwrites.** `!file_exists($to)` means an existing file in the destination is
  left alone.

### 7.4 What would actually happen if the live database were lost

1. `plugin.sqlite3` disappears from `.dishnet-hybrid-sudan-data/`.
2. The next request resolves `$dataDir` to the persistent directory as usual.
3. The persistent DB is missing; the legacy one exists → **rescue fires**.
4. The 2,064 KB / 112-table / **zero-conversation** legacy database is copied in.
5. The plugin starts normally against a valid database.
6. **No error is raised.** The system comes back up looking healthy, with 705
   conversations, 5,833 messages and 391 followups absent from the active database.

**Recovery would still be possible** — `_backups/daily_2026-09-19/plugin.sqlite3` (17,308 KB)
is intact, and the rescue does not touch directories or overwrite existing files. The danger
is not permanent loss. **The danger is silent substitution**: nothing announces that the
wrong database is now live, so the window before anyone notices is unbounded.

### 7.5 What would happen during an upgrade

uCRM replaces `<plugin>/` on upgrade, which deletes `<plugin>/data/`. For the **hybrid**
plugin the live database is outside that directory, so it survives — this is exactly what
the persistent location was built for.

For the **two Starlink siblings**, whose data is entirely inside `<plugin>/data/` (R1), an
upgrade deletes it. They have no equivalent rescue, because the rescue is
`dishnet-hybrid-sudan` code protecting `dishnet-hybrid-sudan` data.

### 7.6 The safety property worth preserving

`cliDataDir()` contains a deliberate refusal that any remediation must not remove. When a
CLI tool falls back and finds no database, it **exits rather than creating an empty one**:

> *"Nothing was created: an empty one would make every tool report an empty system as fact."*

And when several candidate databases exist, it refuses to choose:

> *"Not chosen automatically: a tool that writes must be aimed deliberately, not at
> whichever database it happened to find."*

This is correct behaviour and is the counterpart to R3: the CLI path already refuses to
guess, while the web path silently rescues. **Any remediation of R3 should bring the web
path towards the CLI path's honesty, not the reverse.**

---

## 8. What is now PERMITTED for the MikroTik project

### Permitted

1. Physical MikroTik **Step 0** on real hardware — docs/32, unstarted.
2. Behavioural discovery: RouterOS under CGNAT, WireGuard from behind NAT, REST API,
   HotSpot + RADIUS client, `default-configuration` and reset behaviour.
3. Validating RouterOS commands against the physical unit and recording **only** what was
   actually verified (per the standing instruction on docs/32).
4. Designing MikroTik schemas **on paper**: `mt_devices`, `mt_vouchers`, `mt_sessions`,
   `mt_wg_peers`.
5. Continued read-only use of the Phase 0 RADIUS/WireGuard stack already built and proven.
6. Phase 0 cleanup owed at docs/36 §6.6 — removing `client dn-localtest`,
   `client localhost`/`localhost_ipv6`, and the test rows. This touches only Phase 0
   infrastructure, nothing in Domain A.

### Not permitted without separate approval

1. MikroTik application/control-plane development.
2. Any change to Domain A — code, schema, data, configuration.
3. Remediating R1–R10. Each needs its own plan and approval.
4. Creating a persistent data directory for either Starlink sibling.
5. Any database migration, including the `CHECK` constraint of §4.3.
6. Deployment of anything.

### The next useful unknown

Repository and server discovery has reached diminishing returns. What software exists is
now established across docs/37–41. The open question is behavioural and can only be
answered against physical hardware: **what does RouterOS actually do under CGNAT?**

That is what Step 0 and B1 exist to establish.

---

## 9. Change control

This document is binding. To change the boundary in §4:

1. State which prohibition is being lifted and the specific technical need.
2. Show the mechanical enforcement replacing it — not an intention, a constraint.
3. Record the decision as an amendment here, preserving the original text.

**The boundary is not lifted by a code review approving a diff that crosses it.** If a
change requires crossing §4, the crossing is the decision, and it belongs here first.

---

## 10. Constraints honoured

No MikroTik application development started. No Starlink plugin modified. No Starlink data
migrated. No PWA modified. No database schema modified. Nothing deployed. Read-only
throughout; this document records and freezes, it does not change.

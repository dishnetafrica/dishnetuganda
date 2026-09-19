# 38 — Sibling Plugin Dependency Audit

**Scope:** `dishnet-starlink-finance` and `dishnet-data-report`, the two plugins the
existing hotspot authorization depends on.
**Type:** Read-only audit. No code changed, no migration, no deployment, no
configuration or database change.

> **INSTALLATION SCOPE (added 2026-09-19).** Every finding here was measured on
> **installation B — Uganda, `crm.dishnetuganda.com:8443` / `209.97.137.203`**, plugin
> `dishnet-hybrid-sudan`. DishNet also runs **installation A — South Sudan,
> `crm.dishnetafrica.com` / `46.101.93.167`**, plugin `dishnet-hybrid-telecom`, which is
> where the customer Android application operates. **Installation A has never been
> audited**; nothing in this document describes it. See docs/00 §2.1.
**Question behind it:** can the MikroTik project be built without touching the
working Starlink HotSpot system?

---

## 0. The finding that shapes everything else

**Neither plugin is in this repository.**

`lib/SiblingPlugin.php:8` states it in its own words:

> *"Two other plugins hold data this one needs: dishnet-starlink-finance has the KIT
> register, dishnet-data-report has the router map. Neither is in this repository, so
> nothing here can test against them."*

Confirmed by search: the repository root holds `dishnet-ai/`, `dishnet-hybrid-sudan/`,
`dishnet-mail/`, `dishnet-marketing/`, `dishnet-web-uganda/`, `dishnet-web/`, `docs/`,
`hybrid-plugin/`, `n8n/`, `scripts/`. No `dishnet-starlink-finance`, no
`dishnet-data-report`.

So this audit splits in two, and the two halves have different standing:

| Half | Can be audited here? | Standing of the findings |
|---|---|---|
| **Consumer side** — what this plugin reads from them, and what breaks without it | Yes, completely | Verified from source |
| **Provider side** — their tables, their APIs, their internal logic | No | Requires read-only commands on the server (§8) |

Everything in §1–§7 is the consumer side and is verified. The provider side is **not
claimed**, only asked for.

---

## 1. The dependency is a file contract, not an API

There is no HTTP call, no shared database connection, and no class import between this
plugin and the two siblings. The entire coupling is **JSON files read off disk** through
one reader, `lib/SiblingPlugin.php`.

That reader looks in two places, in order (`SiblingPlugin.php:87`):

```
1.  <plugins>/.<plugin>-data/<file>     ← runtime directory, survives upgrade
2.  <plugins>/<plugin>/data/<file>      ← pre-upgrade location
```

The second is the one uCRM **deletes on plugin upgrade**. `SiblingPlugin`'s docblock
records that all 22 original hand-rolled reads looked only there — meaning that before
the reader was introduced, an upgrade of either sibling could silently empty this
plugin's view of the world with no error anywhere.

`readJson()` deliberately distinguishes *"could not read"* (`null`) from *"read it and it
was empty"* (`array`), and records every failure in `misses()`. `tools/sibling_doctor.php`
surfaces them.

---

## 2. Every file this plugin consumes

Production reads only (tests excluded), counted by call site:

### From `dishnet-starlink-finance`

| File | Reads | What it carries | Consumed by |
|---|---|---|---|
| `sl_kits.json` | 10 | **The KIT register** — kit number ↔ customer ↔ `router_id_full` | `ca_hotspot_authz_router()`, `app_me`, `app_site_diagnostics`, `app_site_refresh`, `api_crm_misc.php` |
| `sl_accounts.json` | 2 | Starlink billing accounts | `tools/starlink_accounts_list.php` |
| `sl_account_cycles.json` | 1 | Billing cycles | `api_crm_misc.php:2894` |
| `accounts.json` | 1 | Account list | `api_crm_misc.php:2893` |

### From `dishnet-data-report`

| File | Reads | What it carries | Consumed by |
|---|---|---|---|
| `wifi_router_map.json` | 15 | **The router map** — `router_id` ↔ kit / service line | `ca_hotspot_authz_router()` Path B, `app_wifi_get`, `ca_site_dish_resolve()`, `app_me`, PWA portal |
| `sl_svc_cache.json` | 5 | Service-line cache | `api_crm_misc.php`, `tools/block_doctor.php`, `tools/starlink_probe.php`, PWA portal |
| `wifi_test_block_state.json` | 4 | Block-test state | `app_me`, PWA portal |
| `dr_accounts.json` | 2 | **Starlink accounts and their session cookies** | `tools/block_doctor.php`, `tools/starlink_accounts_list.php` |
| `dr_kit_registry.json` | 1 | Kit registry snapshot | PWA portal |
| `dr_orders.json` | 1 | Starlink orders | `tools/import_starlink_orders.php` |

**Note on `dr_accounts.json`:** `lib/DrSnapshot.php:44` describes it as *"Starlink accounts
and their session cookies — needs a re-login"*. A credential store sitting in a sibling
plugin's data directory is worth a separate security decision; it is outside this audit's
scope, but it should not be inherited by anything new.

---

## 3. How `ca_hotspot_authz_router()` depends on them

`includes/api/api_customer_app.php:3052`. It is the single authorization gate for the
hotspot surface, and it is **wholly dependent on sibling files**. It has no database
fallback and no independent source of truth.

```
ca_hotspot_authz_router(routerId, clientId, er2)
│
├─ normalise: prepend "Router-" if absent
│
├─ Path A  (v4.14.0, fast path)
│    read  dishnet-starlink-finance / sl_kits.json
│    ├─ file missing     → HARD FAIL 503 "Kit registry not available."
│    ├─ not valid JSON   → HARD FAIL 503 "Kit registry unreadable."
│    └─ match k['router_id_full'] === routerId  AND kit belongs to clientId
│
└─ Path B  (v4.15.2 fallback, only if Path A found nothing)
     read  dishnet-data-report / wifi_router_map.json
     ├─ file missing → no fallback; the 404 from Path A stands
     └─ lookup drMap[routerId], else drMap[short]  (strip "Router-")
```

Two consequences, both verified in source:

1. **`sl_kits.json` is a hard dependency.** If that one file is missing or corrupt, every
   gated action returns 503 — not a degraded mode, a full stop.
2. **`wifi_router_map.json` is a soft dependency.** Its absence loses the fallback for
   kits whose `router_id_full` is not recorded in `sl_kits.json`; those customers get 404.

### The 12 actions behind that gate

`app_hotspot_status`, `app_hotspot_prepare`, `app_hotspot_resync`,
`app_hotspot_rotate_password`, `app_hotspot_toggle_mode`,
`app_paid_access_grant`, `app_paid_access_extend`, `app_paid_access_revoke`,
`app_paid_access_list`,
`app_devices_record_seen`, `app_devices_get_seen`, `app_devices_acknowledge`.

Plus `app_wifi_get`, which reads `wifi_router_map.json` directly
(`api_customer_app.php:2037`) rather than through the gate.

---

## 4. The seven questions, answered

**Q1 — How does `ca_hotspot_authz_router()` depend on them?**
Totally. `sl_kits.json` (hard, 503 on absence) and `wifi_router_map.json` (soft fallback).
No database path, no cache, no alternative source. See §3.

**Q2 — Which tables, files and APIs do they use?**
*Files consumed by this plugin:* the ten in §2 — verified.
*Their own tables and APIs:* **unknown and not claimed** — the plugins are not in this
repository. §8 has read-only commands to answer it on the server.
What is verified is that the coupling this plugin relies on is **file-only**: no sibling
table is queried and no sibling HTTP endpoint is called from this codebase.

**Q3 — Do they contain Starlink customer/device authorization logic?**
They hold the **data** that authorization is decided from — the kit register and the router
map. The **decision logic** lives here, in `ca_hotspot_authz_router()`. That is the useful
distinction: they are the record, this plugin is the judge. Whether either plugin also
authorizes anything on its own account cannot be determined from here (§8).

**Q4 — Billing/payment dependencies?**
Yes, and this is the sharpest finding.

The four `app_paid_access_*` actions — the paid hotspot billing flow — sit **behind**
`ca_hotspot_authz_router()`. So the billing flow inherits the hard dependency: **if
`sl_kits.json` is unavailable, customers cannot buy, extend, list or revoke paid access.**

But the *enforcement* cron is clean. `cron_paid_access.php` (repository root, not `cron/`)
contains **zero** `SiblingPlugin` references. It works off
`SELECT DISTINCT router_id FROM hotspot_paid_access WHERE status='active'` and the
`dr_wifi_get_status` dealer API. So grants already written keep being enforced even if a
sibling file disappears; only the creation of new grants breaks.

Separately, `sl_accounts.json`, `sl_account_cycles.json`, `accounts.json` and
`dr_orders.json` feed Starlink **supplier-side** accounting via `api_crm_misc.php` and
`tools/import_starlink_orders.php`. That is company purchasing, not customer billing.

**Q5 — Does any PWA screen depend on them?**
Yes. `tabs/customer_app/portal_data.php` reads `wifi_router_map.json`,
`sl_svc_cache.json`, `dr_kit_registry.json` and `wifi_test_block_state.json` through
`portalJsonLoad()`. And `app_me` — the action every PWA session calls on login — reads
three sibling files at `api_customer_app.php:1501,1572,1573,1574`.

`app_me` uses them for **display enrichment**, not for the auth decision: login itself is
OTP → JWT → `ca_require_auth()`, which touches no sibling file. So a missing sibling file
degrades what the PWA shows; it does not let anyone in.

**Q6 — Does any cron depend on them?**
Exactly one: **`cron/dr_snapshot.php`**. It uses `SiblingPlugin::pluginRoot()` and
`SiblingPlugin::dataDir(DrSnapshot::PLUGIN)`.

**Correction to an earlier reading of this file:** it does **not** generate the `dr_*`
files. `lib/DrSnapshot.php:182` is `@copy()` — the cron *backs up* files that
`dishnet-data-report` has already written, into this plugin's own data directory under
`dr_snapshots/`. `DrSnapshot::FILES` is a list of things to *take*, and `survey()` only
reports which of them exist in the sibling's directory.

So the producer is `dishnet-data-report` itself; this cron is a **backup consumer**. That
distinction matters for Q7 of the provider-side audit and it changes who owns each file.
Of the 28 jobs in `cron/`, no other reads a sibling.
`cron_paid_access.php` does not (§Q4).

**Q7 — Can the MikroTik project be completely isolated from them?**
**Yes — and the Phase 0 build already is.** See §5.

---

## 5. Isolation verdict

The MikroTik path shares **nothing** with the Starlink path:

| Concern | Starlink HotSpot (frozen) | MikroTik (Phase 0, built) | Shared? |
|---|---|---|---|
| Device identity | `sl_kits.json` kit register | RADIUS `radcheck` / `radreply` | No |
| Router map | `wifi_router_map.json` | RADIUS NAS table | No |
| Authorization | `ca_hotspot_authz_router()` | FreeRADIUS Access-Accept | No |
| Session record | `hotspot_session_log` | RADIUS `radacct` | No |
| Grants | `hotspot_paid_access` | RADIUS vouchers (not yet built) | No |
| Datastore | uCRM PostgreSQL | `dn-phase0-postgres` `127.0.0.1:5433` | No |
| Transport | Starlink dealer API over HTTPS | WireGuard `10.66.0.1/24` | No |
| Enforcement | `cron_paid_access.php` + `dr_wifi_*` | RADIUS accounting + CoA | No |

Isolation holds because the Starlink dependency is a **file contract on two specific JSON
files**, and the MikroTik design reads neither. Nothing in the RADIUS stack needs a kit
number or a `router_id_full`.

**The rule that keeps it true:** the MikroTik voucher system must get its own table.
`hotspot_paid_access` stays exactly as it is — as instructed. The moment a MikroTik
voucher is written into `hotspot_paid_access`, `cron_paid_access.php` will pick up its
`router_id`, try `dr_wifi_get_status` against a router that is not a Starlink kit, and the
two systems stop being independent.

---

## 6. Diagram A — existing Starlink hotspot path (FROZEN)

```
Customer phone (PWA)
      │  OTP → JWT (kind='app')
      ▼
ca_require_auth()                         ← no sibling dependency
      │
      ▼
app_hotspot_* / app_paid_access_* / app_devices_*        (12 actions)
      │
      ▼
ca_hotspot_authz_router(routerId, clientId)
      │
      ├── Path A ─► dishnet-starlink-finance/sl_kits.json     [HARD: 503 if absent]
      │                 match router_id_full → kit → clientId
      │
      └── Path B ─► dishnet-data-report/wifi_router_map.json  [SOFT: fallback only]
      │
      ▼  authorized
StarlinkBlockService ──drHttp──► Starlink dealer API
                                  dr_wifi_get_status / _pause_client
                                  _change_password / _get_config / _test_block
      │
      ▼
hotspot_paid_access  +  hotspot_session_log      (uCRM PostgreSQL)
      │
      ▼
cron_paid_access.php   ← reads the TABLE, not the siblings
   SELECT DISTINCT router_id WHERE status='active'
   → batch dr_wifi_get_status per router → expire → pause device

Written by:   dishnet-data-report (the producer)  ──►  dr_*.json / wifi_*.json
Backed up by: cron/dr_snapshot.php  ──@copy()──►  <this plugin>/data/dr_snapshots/
              (a COPY of the sibling's files, not their source)
```

## 7. Diagram B — proposed MikroTik path (ADDITIVE, no overlap)

```
Customer phone
      │  (same PWA shell, new screens — same OTP/JWT login)
      ▼
ca_require_auth()                         ← reused, unchanged
      │
      ▼
NEW: app_mt_* actions                     ← new namespace, no collision
      │
      ▼
NEW: mt_authz_router()                    ← RADIUS/NAS identity
      │                                      reads NO sibling file
      ▼
dn-phase0-postgres  (127.0.0.1:5433)      ← separate datastore
   radcheck / radreply / radacct / nas
   NEW: mt_vouchers                       ← NOT hotspot_paid_access
      │
      ▼
dn-phase0-radius  (FreeRADIUS 3.2.10, host net, 10.66.0.1:1812/1813)
      │
      ▼
WireGuard 10.66.0.1/24  UDP 51820
      │
      ▼
MikroTik RouterOS  (HotSpot + RADIUS client)

Touch points with Diagram A:  ca_require_auth() only — read, not modified.
Shared tables: none.   Shared files: none.   Shared crons: none.
```

---

## 8. Provider side — read-only commands still owed

These answer Q2 and Q3 for the plugins themselves. All are read-only: `ls`, `cat`,
`grep`, `head`. None writes, migrates or restarts anything.

```bash
# 1. Do the two plugins exist, and where is their data actually kept?
ls -la /data/ucrm/data/plugins/ | grep -Ei 'starlink-finance|data-report'
ls -la /data/ucrm/data/plugins/.dishnet-starlink-finance-data/ 2>/dev/null | head -30
ls -la /data/ucrm/data/plugins/.dishnet-data-report-data/ 2>/dev/null | head -30
ls -la /data/ucrm/data/plugins/dishnet-starlink-finance/data/ 2>/dev/null | head -30
ls -la /data/ucrm/data/plugins/dishnet-data-report/data/ 2>/dev/null | head -30

# 2. Are the two load-bearing files present, and which location won?
for f in .dishnet-starlink-finance-data/sl_kits.json \
         dishnet-starlink-finance/data/sl_kits.json \
         .dishnet-data-report-data/wifi_router_map.json \
         dishnet-data-report/data/wifi_router_map.json; do
  p="/data/ucrm/data/plugins/$f"
  if [ -f "$p" ]; then
    echo "PRESENT  $f  ($(stat -c%s "$p") bytes, mtime $(stat -c%y "$p" | cut -d. -f1))"
  else
    echo "ABSENT   $f"
  fi
done

# 3. Do those plugins own database tables?
grep -rlE 'CREATE TABLE|->exec\(|->query\(' \
  /data/ucrm/data/plugins/dishnet-starlink-finance/ \
  /data/ucrm/data/plugins/dishnet-data-report/ 2>/dev/null | head -20

# 4. Do they call any external API of their own?
grep -rhoE 'https?://[a-zA-Z0-9._/-]+' \
  /data/ucrm/data/plugins/dishnet-starlink-finance/ \
  /data/ucrm/data/plugins/dishnet-data-report/ 2>/dev/null \
  | sort -u | head -30

# 5. Do they contain authorization logic of their own?
grep -rniE 'function .*(authz|authorize|verify_owner|belongs_to)' \
  /data/ucrm/data/plugins/dishnet-starlink-finance/ \
  /data/ucrm/data/plugins/dishnet-data-report/ 2>/dev/null | head -20

# 6. What does the sibling reader itself report at runtime?
docker exec -i ucrm php /data/ucrm/data/plugins/dishnet-hybrid-sudan/tools/sibling_doctor.php 2>&1 | head -40
```

**Expected:** command 2 should show `sl_kits.json` PRESENT in one of its two locations. If
it is ABSENT in both, the hotspot is already returning 503 and that is a live production
fault, not an audit finding — stop and report it.

Command 6 is the highest-value one: `sibling_doctor.php` reports `misses()`, so it says
whether any read is *currently* failing in production.

---

## 9. Open items this audit did not close

1. **Provider-side internals** — §8 is owed before the picture is complete.
2. **Hotspot usage count** (carried from docs/37 §9) — how many customers actually use
   these features. Twelve gated actions matter differently at 4 users than at 400.
3. **`dr_accounts.json` holding session cookies** — a credential-store question, raised
   here, not addressed here.
4. **Legacy-location risk** — if command 2 shows either file resolving from
   `<plugin>/data`, the next upgrade of that sibling deletes it and the hotspot 503s.
   Worth knowing before it happens.

---

## 10. Standing constraints honoured

No file outside `docs/` was read for modification; nothing was modified. No migration, no
deployment, no configuration change, no database change. The Starlink HotSpot system is
untouched. `hotspot_paid_access` was not altered and is explicitly excluded from the
MikroTik design (§5).

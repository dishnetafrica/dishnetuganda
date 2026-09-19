# 40 — Closing the Five Open Questions

**Type:** Read-only. Nothing modified, migrated, restarted or redeployed. SQLite opened
`mode=ro`; the engine refuses writes through that handle and no `-wal`/`-shm` files are
created. Source lines pass a literal redactor. No cookie, token, password or key value was
read into any output.

**Status: four closed, one not.** Q5 is unresolved because of a coverage gap in my own
probe, described plainly in §5 rather than papered over.

---

## Q1 — The `dishnet-data-report` SQLite database

**Found by magic bytes**, not by extension, so an unexpected filename could not hide it.

| | |
|---|---|
| **Exact path** | `/data/ucrm/data/plugins/dishnet-data-report/data/auto_block.sqlite3` |
| **Filename** | `auto_block.sqlite3` |
| **Size** | 16.0 KB |
| **Last modified** | 6.2 days ago |
| **Tables** | `auto_block_queue` (**0 rows**), `sqlite_sequence` (0 rows) |
| **Inside the upgrade-deletable `data/` directory?** | **YES** |
| **Actively updated?** | **No** — 6.2 days old and empty |
| **Writers** | `dr_wifi_change.php`, `cron_auto_block.php` |

**`dishnet-starlink-finance` has no SQLite database at all.** It is entirely file-backed.

Two things worth recording:

1. **`pause_overlay` does not exist in this database.** It appears in the plugin's source
   as a `CREATE TABLE`, but the live file holds only `auto_block_queue` and
   `sqlite_sequence`. So either it is created elsewhere on demand, or that code path has
   never run. Evidence of code is not evidence of function.
2. **The auto-block feature appears idle.** An empty queue untouched for 6.2 days is not a
   feature under load.

A third database was found in the same scan: `dishnet-hybrid-sudan/data/plugin.sqlite3`,
2,064 KB, 6.5 hours old — **also inside an upgrade-deletable `data/` directory**. That one
belongs to this plugin and is central to Q5.

---

## Q2 — Persistent data location

**Neither sibling has one.**

| | `dishnet-starlink-finance` | `dishnet-data-report` |
|---|---|---|
| Persistent location exists | **NO** | **NO** |
| Path checked | `/data/ucrm/data/plugins/.dishnet-starlink-finance-data` | `/data/ucrm/data/plugins/.dishnet-data-report-data` |
| Legacy `data/` still in use | **YES** | **YES** |
| JSON files there | 14 | 22 |
| Newest write | 17.9 h ago | **0.6 h ago** |
| Symlinks inside plugin | none | none |

The only persistent directory in the whole plugins root is
**`.dishnet-hybrid-sudan-data`**.

Both siblings are actively writing into the directory uCRM replaces on upgrade — one of
them 36 minutes before the audit ran. This is not dormant data waiting to be migrated; it
is the live working set.

Per instruction, no persistent location was created.

---

## Q3 — The actual writers, traced rather than inferred

Both are now **proven from source**, including the helper and its caller.

### `sl_kits.json` ← `dishnet-starlink-finance`

| Role | Location |
|---|---|
| **Write helper** | `public.php:175` — `saveJSON()` |
| **Writer (apply mode)** | `public.php:5314` — `$kitsFile = $dataDir . '/sl_kits.json'` |
| **Writer (enrichment)** | `public.php:3258` — `autoEnrichKitData()` |
| Other helpers present | `main.php:42` `saveJSON()`, `wifi_manager_api.php:40` `saveJSON()` |
| Readers | `main.php:50`, `public.php:124`, `public.php:4008` (`loadJSON`), `wifi_manager_api.php:24` |

The plugin documents its own write discipline at `public.php:5249` and `:5258`:

> *"mode=apply — actually writes sl_kits.json (and creates a backup first)"*
> *"Before any apply-mode write, the existing sl_kits.json is copied to …"*

So the kit register has a backup-before-write on its apply path. That is a real safeguard,
and it is internal to the plugin — it does not protect against the directory being deleted.

### `wifi_router_map.json` ← `dishnet-data-report`

| Role | Location |
|---|---|
| **Write helper / writer** | `dr_wifi_change.php:1212` — **`drWifiSaveRouterMap()`** |
| **Builder** | `dr_wifi_change.php:1672` — *"builds wifi_router_map.json: routerId → {account, sl, kit, terminal, customer, hw}"* |
| **Merge path** | `dr_wifi_change.php:1980` — *"merges new routers into wifi_router_map.json, and retries the match"* |
| **Cron caller** | `cron.php:162-163` — *"Builds wifi_router_map.json so the customer app can find router_id for WiFi changes"* |
| Readers | `cron_auto_block.php:173`, `public.php:4107`, `templates/wifi_tab.php:10` |

**This settles the architecture question from the server rather than from inference.** The
router map is built by the plugin that holds the Starlink API access, and its own comment
says it exists *"so the customer app can find router_id"* — the exact dependency
`ca_hotspot_authz_router()` Path B relies on.

### Two findings that came free with the trace

1. **`dr_kit_registry.json` has a named writer:**
   `lib/KitRegistryWriter.php::regenerate()` at line 392 — matching the `generator` and
   `contract` fields the file describes itself with.

2. **An encryption mechanism genuinely exists.** `session_manager.php` defines
   `smDeriveKey()` (61), `smSave()` (105), `smSaveAccounts()` (657) and
   **`smMigrateEncryption()`** (682), alongside the `SM_KEY_LENGTH` constant.
   A key-derivation routine plus an encryption *migration* is strong support for
   `dr_accounts.json`'s `cookie_enc` / `cookie_encrypted` fields being genuinely encrypted
   at rest.

   It does **not** settle `sl_sync_settings.json`'s `starlink_cookie`, which carries no
   `_enc` suffix. That remains open, and I am not closing it by association.

---

## Q4 — `dr_snapshots` is a real, automatic, current backup — with one hole

**It exists, it runs daily, and it is current.**

| | |
|---|---|
| Path | `/data/ucrm/data/plugins/.dishnet-hybrid-sudan-data/dr_snapshots` |
| Location | **Inside the persistent directory** — survives a sibling upgrade |
| Snapshot sets | 8 |
| Cadence | Daily at ~05:40 UTC (`20260919-054005`, `20260918-054025`, `20260917-054024`, …) |
| Newest | 8.0 hours old, 8 files |
| Generated by | `cron/dr_snapshot.php`, scheduled via `cron/master.php` |

### Newest snapshot vs live source

| File | Snapshot | Live | Verdict |
|---|---|---|---|
| `wifi_router_map.json` | 1,658 B | 1,658 B | **identical** |
| `dr_kit_registry.json` | 4,851 B | 4,851 B | **identical** |
| `sl_svc_cache.json` | 5,852 B | 5,852 B | **identical** |
| `sl_sync_settings.json` | 7,540 B | 7,540 B | **identical** |
| `dr_accounts.json` | 33,789 B | 33,800 B | 11 B drift over 8 h — expected |
| `wifi_test_block_state.json` | ABSENT | ABSENT | nothing to copy |
| **`sl_kits.json`** | **ABSENT** | 2,181 B | **NOT BACKED UP** |

### The hole, stated plainly

The backup is genuine and working. But `DrSnapshot::FILES` lists only
`dishnet-data-report` files. **`sl_kits.json` belongs to `dishnet-starlink-finance` and is
not in the list.**

That is precisely inverted relative to the risk:

- `wifi_router_map.json` is the **soft** dependency — losing it costs the Path B fallback.
  It **is** backed up.
- `sl_kits.json` is the **hard** dependency — losing it returns **503 on all twelve gated
  actions**, including all four `app_paid_access_*` billing actions. It is **not** backed up.

So the one mitigation that exists protects the file we could survive losing and misses the
file we could not. Recorded, not fixed.

---

## Q5 — NOT CLOSED: my probe had a coverage gap

### What the probe returned

```
hotspot_paid_access:  TABLE DOES NOT EXIST
hotspot_session_log:  TABLE DOES NOT EXIST
```

### Why I am not reporting that as the answer

Two `plugin.sqlite3` files exist:

| | Path | Size | Age |
|---|---|---|---|
| A | `.dishnet-hybrid-sudan-data/plugin.sqlite3` | not measured | — |
| B | `dishnet-hybrid-sudan/data/plugin.sqlite3` | 2,064 KB | 6.5 h |

**My Q1 walk only descended into the three plugin directories.**
`.dishnet-hybrid-sudan-data` is a *sibling* of those, not inside them, so database A was
never scanned or table-listed. Q5 opened A and checked two table names; Q1b listed B's
tables in full.

Neither shows the hotspot tables. But B also reports `wa_conversations = 0` and
`wa_messages = 0`, which is **known to be false** for this production system — there are
real conversations. So B is not the live database, and I have no table listing for A.

**Conclusion I can support:** the hotspot tables are absent from both files inspected.
**Conclusion I cannot yet support:** that this means zero hotspot usage.

### Why the two databases exist

`lib/bootstrap_data.php:85-95` performs a one-time rescue: when the persistent directory
has no `plugin.sqlite3` but the legacy one does, it **copies** every file across —
*"Copy, never move -- if anything here goes wrong the original must still be there."*
That rescue has run, which is why both exist. The persistent copy should be live and the
legacy one the original left behind.

### The hypothesis this points to, stated as a hypothesis

`ca_init_tables()` creates `hotspot_paid_access`, `hotspot_session_log` and
`hotspot_seen_devices`, and it is called at the top of **44 customer-app handlers** —
including `app_send_otp` and `app_me`. If the PWA had ever been exercised against the live
database, those tables would exist.

Their absence suggests **the customer app has never been used in production, and the
hotspot schema was never created** — making live hotspot usage zero.

If that holds it is the single most consequential fact for MikroTik planning: the system
being frozen would have no users at all. Which is exactly why I am verifying it rather
than asserting it. The probe in the accompanying message lists every SQLite file under the
plugins root including dot-directories, and compares the databases side by side.

---

## Recorded for Phase 2 — data isolation

Carried forward from docs/39 §10, unchanged and unimplemented:

**`hotspot_paid_access.router_id` is unconstrained `TEXT`.** No `CHECK`, no foreign key.
`cron_paid_access.php` selects every distinct `router_id` with `status='active'` and hands
each to `dr_wifi_get_status`. A MikroTik voucher in that table becomes a Starlink dealer
API call against a device Starlink has never heard of.

**Phase 2 requirement:** the MikroTik voucher/access model must be **mechanically**
separated from the Starlink paid-access model — a separate table (`mt_vouchers`) *plus* a
database-level constraint — not separated by application convention. A rule that lives
only in a document is a request, and requests fail silently.

Not fixed, not migrated, not designed further here.

---

## Architecture preserved

The corrected relationship stands, and Q3 has now proven it from the installed source
rather than from inference:

- **`dishnet-data-report`** → Starlink API client → Starlink router/device operations →
  `dr_wifi_*`. It builds `wifi_router_map.json` via `drWifiSaveRouterMap()`.
- **`dishnet-starlink-finance`** → Starlink kit/finance registry → authorization data
  consumed by the customer application. It writes `sl_kits.json` via `saveJSON()`.

The old assumption is not reinstated anywhere in this document.

## MikroTik boundary

Unchanged and strict. The MikroTik system must not use `sl_kits.json`,
`wifi_router_map.json`, `dr_accounts.json`, Starlink session cookies, or Starlink router
IDs as its device registry; must not call `dr_wifi_*`; and must not insert vouchers into
`hotspot_paid_access`. It gets its own device registry and its own voucher/access tables.
Nothing in the Phase 0 RADIUS build touches any of them.

## Constraints honoured

Read-only throughout. No migration, no fix, no plugin change, no MikroTik implementation.
No snapshot created or modified. No writer triggered. The existing Starlink HotSpot is
untouched.

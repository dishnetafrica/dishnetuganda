# 00 — MUST READ: DishNet Platform Orientation

> **STATUS: BINDING / MUST READ**
> **Scope:** DishNet customer platform + existing Starlink ecosystem + MikroTik
> Zero-Touch/HotSpot project
> **Last verified:** 2026-09-19
> **Source documents:** docs/30–41
> **This document does not replace the detailed documents. It is the entry point and
> system map.**

---

## 1. PURPOSE — READ THIS FIRST

**This document is mandatory reading before changing the DishNet platform.**

It explains the platform as discovered through the 2026 architecture audit. It is an
**orientation document**, not a replacement for the detailed ADRs and protocols in
docs/30–41.

If you are new to this repository: read this first. It tells you what already exists,
what is production, what is frozen, what is Phase 0, what may be reused, what must remain
separate, and which architectural discoveries have already been proven.

**Precedence.** If this document conflicts with newer approved architecture documentation,
**the newer approved document wins**, and this document must be updated to match. If it
conflicts with docs/30–41, the detailed document is the authoritative evidence and this
document is wrong — fix it here.

### Evidence labels used throughout

| Label | Meaning |
|---|---|
| **VERIFIED** | Observed directly on the live server or proven from source during the audit |
| **HARDWARE VERIFIED** | Confirmed against physical RouterOS hardware. **Nothing carries this label yet.** |
| **DOCUMENTED** | Stated in vendor documentation or in our own design docs, not yet confirmed against the thing itself |
| **INFERRED** | Reasoned from naming, role or structure — not observed |
| **UNRESOLVED** | Known to be unknown. Named so it is not mistaken for settled |
| **DEFERRED** | Understood, decided, and deliberately not acted on yet |

Preserve these labels when you edit. A claim that quietly loses its label becomes a fact
nobody checked.

---

## 2. THE MOST IMPORTANT ARCHITECTURAL FACT

DishNet has an **existing Starlink ecosystem**. We are adding a **new MikroTik
Zero-Touch / HotSpot ecosystem**.

**These are TWO NETWORK DOMAINS.**

They may eventually share **customer identity and presentation/UI**.
They **MUST NOT** share **network-control data or device registries**.

```
                              Customer
                                 |
                 +---------------+---------------+
                 |                               |
          Starlink Domain                 MikroTik Domain
                 |                               |
        Existing Starlink                 New MikroTik
         plugins / data                   control plane
                 |                               |
           Starlink API                     WireGuard
                 |                               |
         Starlink routers                   FreeRADIUS
                 |                               |
        Existing HotSpot                MikroTik HotSpot
```

**The boundary is between the two columns, below the customer.** Above it, unification is
permitted. Below it, never.

Authoritative source: **docs/41**, which is binding.

---

## 3. EXISTING STARLINK DOMAIN — DO NOT BREAK IT

All **VERIFIED** (docs/38, docs/39, docs/40).

These already exist and are production components:

`dishnet-starlink-finance` · `dishnet-data-report` · Starlink API/client · Starlink account
data · `sl_kits.json` · `wifi_router_map.json` · `dr_accounts.json` · Starlink router
operations · `dr_wifi_*` · existing customer PWA · existing Starlink HotSpot implementation

### `dishnet-data-report` (v2.8.80) — the Starlink client/API layer

**VERIFIED** (docs/39 §4, docs/40 Q3). It:

- communicates with the Starlink APIs — `api.starlink.com/auth-rp/auth/user`,
  `api.starlink.com/webagg/v2/accounts/service-lines`
- holds Starlink account/session-related information (`dr_accounts.json`,
  `sl_sync_settings.json`)
- **serves the `dr_wifi_*` actions** from its own `public.php`
- owns its SQLite operational database (`auto_block.sqlite3`: `auto_block_queue`)
- runs its own Starlink crons (`cron.php`, `cron_session.php`, `cron_auto_block.php`,
  `cron_orders.php`, `cron_invoice_details.php`, `cron_test_block_extend.php`,
  `cron_backup.php`)
- **writes `wifi_router_map.json`** via `drWifiSaveRouterMap()` in `dr_wifi_change.php`

### `dishnet-starlink-finance` (v7.3.9) — downstream kit/finance registry

**VERIFIED** (docs/39 §4, docs/40 Q3). It:

- holds the Starlink kit/finance registry (`sl_kits.json`, `sl_accounts.json`)
- manages Starlink-related finance and kit data
- provides the kit data consumed by authorization and application functions
- **writes `sl_kits.json`** via `saveJSON()` in `public.php`, with backup-before-write on
  the apply path
- has **no `api.starlink.com` access** and **no database tables**

> ### CORRECTION — do not reinstate the old assumption
>
> An earlier reading had `dishnet-starlink-finance` calling Starlink directly and feeding
> `dishnet-data-report`. **That is wrong and was corrected from the installed source.**
> `dishnet-data-report` is the Starlink client and comes first; `dishnet-starlink-finance`
> is downstream. See docs/39 §8 and docs/40 Q3.

---

## 4. EXISTING CUSTOMER APPLICATION

**VERIFIED** (docs/37, docs/38):

- existing customer PWA — `includes/api/api_customer_app.php` (5,104 lines),
  `tabs/customer_app/`
- OTP authentication
- JWT customer authentication (`kind='app'`, `app_jwt_blacklist`)
- customer API — 44 `app_*` actions, guarded by `ca_require_auth()`
- existing Starlink customer functionality

### Important correction

**docs/30 §0.1 originally stated that no authenticated customer actor existed. That was
proven FALSE** and is corrected in place at docs/30 §0.1, with the original text preserved
below it as `0.1 (original, incorrect)`.

**The correct current understanding:** a customer authentication/application system
already exists and is production-deployed.

### Usage versus deployment — read both halves

Per **docs/40 Q5**, the PWA has **never served a customer request**. But the code is
**production-deployed**.

> **Do not treat it as dead code.** "Never executed" is not "safe to delete." It is a
> working implementation with a real Starlink registry behind it, and the business may
> launch it.

### UNSOURCED — flagged, not documented as fact

An **Android customer application**, a **WebView/PWA relationship**, and an **Android ZIP
reviewed during the audit** are **not present anywhere in docs/30–41**, and no Android
application was audited during this work.

The only Android references in the source documents are:

- docs/30:323 — a technical note on Android's `ConnectivityManager.requestNetwork` for
  captive-portal behaviour. That is **device OS behaviour**, not a DishNet application.
- docs/39:364 — a diagram label reading "Customer PWA / Android app".
- docs/39:491, docs/41:96 — constraint lines stating no Android application was modified.

**Status: UNSOURCED.** An Android customer application may well exist — but this audit did
not examine one, so this document cannot describe it. If it exists, it needs its own audit
before anything here claims knowledge of it.

The constraint still stands regardless: **do not modify any Android application**, and do
not treat any Android artefact as a drop-in replacement for the current production
application.

---

## 5. EXISTING STARLINK HOTSPOT

**VERIFIED** (docs/37, docs/38). The implementation includes:

- Starlink WiFi/device management
- paid-access code paths — `app_paid_access_grant`, `_extend`, `_revoke`, `_list`
- `hotspot_paid_access`
- `hotspot_session_log`
- `hotspot_seen_devices`
- `cron_paid_access.php`
- `ca_hotspot_authz_router()` — the single authorization gate, 12 gated actions

### The critical finding

**VERIFIED** (docs/40 Q5):

- The audit found **ZERO historical customer usage**.
- `hotspot_paid_access`, `hotspot_session_log`, `hotspot_seen_devices` and
  `app_jwt_blacklist` **have never existed in any retained database** — absent from all 13
  SQLite files, including every backup from 4 to 19 September 2026.
- `ca_init_tables()` creates those tables and runs at the top of 44 customer-app handlers.
  Their absence everywhere proves it **has never executed**.
- Therefore the customer PWA has **never actually served a customer request**.
- `cron_paid_access.php` has been a **no-op since deployment** — it returns at its first
  query when the table is missing.

### What this means

**The code is frozen and must be preserved, but it is not currently carrying live customer
HotSpot traffic.**

> **Do not interpret "unused" as permission to delete it.**

---

## 6. STARLINK DATA FLOW

**VERIFIED** direction (docs/39 §8, docs/40 Q3):

```
Starlink API  ──►  dishnet-data-report  ──►  Starlink/router data and operations
                          │                   (dr_wifi_* served from its public.php)
                          │
                          ▼
              dishnet-starlink-finance  ──►  Starlink kit/finance registry
                          │
                          ▼
              Customer application
                          │
                          ▼
              ca_hotspot_authz_router()  ──►  Starlink authorization data
                          │
                          ▼
                 Starlink operations
```

### The exact dependency

| File | Role | Failure mode |
|---|---|---|
| **`sl_kits.json`** | **HARD authorization dependency** | Absent or unreadable → **503 on all twelve gated actions**, including all four billing actions. No fallback, no cache. |
| **`wifi_router_map.json`** | Customer-app router mapping — the Path B fallback | Absent → Path B lost; kits whose `router_id_full` is not in `sl_kits.json` return 404 |

`cron.php:162` in `dishnet-data-report` states the purpose in its own words: it builds
`wifi_router_map.json` *"so the customer app can find router_id for WiFi changes."*

### Scale — **VERIFIED** (docs/39 §3)

2 kits, 2 mapped routers, 16 service lines, 4 Starlink accounts. `cron_paid_access.php:10`
reasons about *"all 44 routers"*; the data does not show that. **At most 2 routers can be
authorized.**

---

## 7. STARLINK DATA PERSISTENCE RISKS — **NOT REMEDIATED**

All **VERIFIED** (docs/39 §2, docs/40 Q1/Q2/Q5). **None of these is fixed. All are
deferred to separate, individually-approved remediation work.**

- Both sibling plugins store important data in **upgrade-deletable directories**
- **Neither has a persistent `.plugin-data` location** — the only one in the plugins root
  belongs to `dishnet-hybrid-sudan`
- **`sl_kits.json` is not included in `dr_snapshots`**
- `dishnet-data-report` has `auto_block.sqlite3` **inside its upgradeable data directory**
- `dr_accounts.json` contains Starlink account/session-cookie fields (4 records; field
  names indicate encryption at rest)
- `sl_sync_settings.json` contains Starlink cookie-related data
- The hybrid application's **persistent** database is live: 705 conversations, 5,833
  messages, 391 followups, 209 tables
- The **legacy** hybrid database is stale/near-empty: 112 tables, 0 conversations
- `bootstrap_data.php` has a rescue mechanism that can **silently substitute the stale
  legacy database** if the persistent database disappears

### The rescue mechanism, exactly (docs/41 §7)

It **copies, never moves** — the design comment is explicit that *"if anything here goes
wrong the original must still be there."*

- **Files only.** `is_file()` excludes directories, so it does **not** copy `_backups/` or
  `dr_snapshots/`
- **Never overwrites** — `!file_exists($to)` leaves existing destination files alone
- Fires **only** when the persistent `plugin.sqlite3` is missing and the legacy one exists
- Would restore the **2,064 KB near-empty** legacy database
- The application then **starts normally**, with **no error raised**
- **Recovery remains possible**, because the proper backup still exists intact
  (`_backups/daily_*/plugin.sqlite3`, 7 daily + 1 weekly, taken 23:00)

> **The danger is silent substitution, not necessarily irreversible data loss.** Nothing
> announces that the wrong database is now live, so the window before anyone notices is
> unbounded.

**Counterpart worth preserving:** `cliDataDir()` already refuses to create an empty
database — *"an empty one would make every tool report an empty system as fact"* — and
refuses to auto-choose between candidates. Any remediation should move the web path
**towards** that honesty, not away from it.

---

## 8. HARD STARLINK / MIKROTIK BOUNDARY

**This is the most important operational section in this document.** Binding source:
docs/41 §4.

### MikroTik MUST NOT use

`sl_kits.json` · `wifi_router_map.json` · `dr_accounts.json` · Starlink session cookies ·
Starlink router IDs as a MikroTik registry · `dr_wifi_*` · `hotspot_paid_access` ·
`hotspot_session_log` · `hotspot_seen_devices` · `ca_hotspot_authz_router()`

### MikroTik MUST have its own

device registry · provisioning state · WireGuard peer registry · voucher/access model ·
session/accounting model · RADIUS lifecycle

### Why — the mechanism, not a preference

**VERIFIED:**

1. `hotspot_paid_access.router_id` is **unconstrained `TEXT`** — no `CHECK`, no foreign key.
2. `cron_paid_access.php` runs
   `SELECT DISTINCT router_id FROM hotspot_paid_access WHERE status='active'`
   and passes every value to `dr_wifi_get_status` — **the Starlink dealer API**.

Therefore a MikroTik router or voucher in that table would cause the Starlink system to
treat it as a Starlink device and call the dealer API against hardware Starlink has never
heard of.

> **A MikroTik voucher must NEVER be represented as a row in `hotspot_paid_access`.**

### This separation must become mechanical — **DEFERRED to Phase 2**

Today it is **convention only**, and convention fails silently. Phase 2 must deliver:

- separate `mt_*` tables (`mt_devices`, `mt_vouchers`, `mt_sessions`, `mt_wg_peers`)
- **database-level `CHECK`/constraints where appropriate**, so the engine refuses the row
  rather than a reviewer catching it in diff
- an explicit naming boundary: tables `mt_`, actions `app_mt_*`, functions `mt_*`, config
  `mt_*`

A `dr_`/`sl_`/`hotspot_` name inside MikroTik code, or a MikroTik identifier without its
prefix, is a boundary violation and should fail review on sight.

**Not implemented. Adding a `CHECK` to an existing SQLite table requires a rebuild, which
is a migration.**

---

## 9. CUSTOMER EXPERIENCE BOUNDARY

The customer-facing PWA/app **may** eventually provide one unified DishNet experience:

```
Customer  →  DishNet app/PWA  →  Starlink service
                    OR
Customer  →  DishNet app/PWA  →  MikroTik HotSpot service
```

> **UI unification is allowed. Network backend unification is not.**

- The shared layer **may** include customer identity/authentication where explicitly
  approved. `ca_require_auth()` (OTP → JWT) reads no sibling file and touches no Starlink
  data — it may be **reused as-is**, read but not modified.
- **Branch above the backend, never inside it.** One screen may call two independent
  backends. One backend must not decide which network it is talking to.
- No shared authorization function, no shared session or grant record.

The network control domains remain **independent, permanently**. Unification is a
**presentation** concern; the moment it becomes a data concern, the boundary is broken.

---

## 10. PHASE 0 SERVER — CURRENT VERIFIED STATE

**VERIFIED** (docs/34, docs/36). This is the **existing production host** — Phase 0 was
built alongside production, not on a new VPS.

| | |
|---|---|
| Host | DigitalOcean droplet |
| OS | Ubuntu 24.04.4 LTS, kernel 6.8.0-139 |
| Public IP | `209.97.137.203/20` on eth0 |
| Orchestration | **Docker Swarm, orchestrated by EasyPanel** — not a plain Docker host |
| Existing containers | **18**, all production |
| Firewall | **UFW installed but NOT enforcing** (`ufw status` = inactive) |
| Timezone | **`Etc/UTC`** |

### Constraints that remain in force

- **Do not enable UFW.** On a Swarm host with 18 containers that is a production incident,
  not a hardening step: Docker writes its own rules in the `nat` table and bypasses UFW for
  published ports, while UFW's default INPUT policy would cut off traffic — in the worst
  case your own SSH session (docs/34 §3).
- **Do not reuse the existing PostgreSQL or Redis.** Both are owned by applications that
  matter.
- **Do not modify** iptables, Docker or Swarm configuration, or the Docker daemon.
- **Do not change the timezone.** docs/33 §6.1 said set Kampala; **docs/34 supersedes it —
  leave `Etc/UTC` alone.**
- **Do not restart** Docker, EasyPanel or Traefik.
- **Do not create an EasyPanel service** for Phase 0 components.

### Phase 0 additions — isolated, +2 containers

| | |
|---|---|
| WireGuard | **native on the host**, not containerised |
| Interface | `wg0`, `10.66.0.1/24`, **UDP 51820** |
| Phase 0 PostgreSQL | `dn-phase0-postgres` (postgres:16-alpine), **`127.0.0.1:5433`** — loopback only, 5433 not 5432 so it is unmistakably ours |
| Network | user-defined bridge **`dn-phase0`**, isolated from the swarm overlays |
| FreeRADIUS | `dn-phase0-radius` (freeradius/freeradius-server:3.2.10), **`--network host`** because it must bind `10.66.0.1`, a host interface |
| RADIUS binding | **`10.66.0.1:1812/1813`** — the WireGuard address, not the public IP |
| Config | bind-mounted from `/opt/dn-phase0/raddb` |

**No changes were made to existing production containers.**

**Operational note (docs/36):** FreeRADIUS binds `10.66.0.1`, which exists only while `wg0`
is up. Start order matters at boot.

**No secrets appear in this document, and none should be added to it.**

---

## 11. WHAT PHASE 0 HAS ALREADY PROVEN

**VERIFIED** — all ten evidence items PASSED (docs/36 §7).

| Area | Evidence |
|---|---|
| **WireGuard** | Handshake externally verified from another network (a MacBook on a different connection); 4/4 ping at 149 ms; clean routing |
| **FreeRADIUS auth** | `Access-Accept` **with attributes**: `Mikrotik-Rate-Limit = "5M/5M"`, `Session-Timeout = 3600`, `Acct-Interim-Interval` |
| **Accounting** | **Start → Interim → Stop proven** — one row, 600s, `User-Request` |
| **Exposure** | RADIUS unreachable on the public IP; PostgreSQL `5433/tcp Connection refused` from outside |
| **Production safety** | Exactly +2 containers; **all 18 existing unchanged, 9/9 ports answering**; WireGuard config unchanged (same public key, port, address) |
| **Cleanup** | Phase 0 test clients and test rows removed (docs/36 §6.6, executed 2026-09-19) |

### Current RADIUS state after cleanup

- Remaining client: **`dn-test-mikrotik`**, `ipaddr = 10.66.0.11`, `nas_type = other`
- Its secret exists and is distinct (not the stock default). **It MUST NOT be printed.**
- `radcheck`, `radreply`, `radacct`, `nas` — **all 0 rows**

`radacct` at zero is useful: the first real MikroTik session gives an unambiguous signal,
because any row that appears came from the router.

---

## 12. MIKROTIK PROJECT — CURRENT STATUS

**MikroTik is NOT yet production software.**

**Current stage: architecture + hardware validation.**

### Allowed now

physical Step 0 · RouterOS command validation · WireGuard validation · REST validation ·
HotSpot/RADIUS validation · reset/restore validation · CGNAT testing · **B1 idle testing** ·
paper schema design

### Not yet allowed

production control plane · production voucher system · customer deployment · reseller
deployment · application deployment · production migrations · **any Starlink plugin change**

---

## 13. DOCS/32 STEP 0 IS THE HARDWARE GATE

**docs/32 is the authoritative bench protocol.** Its §0.5 gate must be satisfied before
Test A begins.

Step 0 must physically verify: RouterOS version · hardware · WireGuard · peer syntax ·
endpoint fields · allowed-address · keepalive · REST · certificates · export/import ·
run-after-reset · HotSpot · RADIUS · accounting · scheduler/scripts · watchdog

### Commands documented online are NOT equivalent to hardware verified

Use the labels. Only actual physical results may become **HARDWARE VERIFIED**:

| Label | Use when |
|---|---|
| **DOCUMENTED** | It is in MikroTik's documentation or ours |
| **VERSION/MODEL DEPENDENT** | Known to differ between RouterOS versions or hardware |
| **HARDWARE VERIFIED** | The physical unit accepted it and you saw the result |
| **UNRESOLVED** | Tried and did not work, or not yet attempted |

docs/32 §0.5 requires that **the file has been corrected and committed** to reflect what
the hardware actually accepts. That is what converts docs/32 from proposal to procedure.

> **As of 2026-09-19, nothing in this platform carries the HARDWARE VERIFIED label.
> Physical Step 0 has not been executed.**

---

## 14. B1 IS AN ARCHITECTURAL GATE

> ## ⚠ READ THIS BEFORE WRITING ANY CONTROL-PLANE CODE
>
> **`B.idle` (docs/32) determines whether the future control plane is PUSH or POLL.**

| Outcome | Meaning |
|---|---|
| **PASS** | The backend can reach an idle router through the persistent WireGuard path. VPS-initiated traffic gets an immediate reply at 30 min, 2 h and overnight. **Push-based management is viable.** |
| **FAIL** | Replies come only after the router's own keepalive fires, or not at all. **Management must be POLL-based** — the router initiates contact and retrieves pending work. |

**Do NOT write production control-plane code before B1 is proven.**

If B1 fails, the provisioning/control-plane architecture must be **redesigned around
polling**, and docs/30 §4.2 Phase 2 must be revised before any of it is written.

This is not a tuning parameter. It decides whether the control plane pushes or the device
polls, and the bench is the cheapest possible place to learn it.

**Status: UNRESOLVED.** B1 has not been run.

---

## 15. OPERATIONAL LESSONS FROM THE AUDIT

Recorded because each one cost real time or nearly caused a real error.

1. **Never assume a repository contains all production dependencies.** Two load-bearing
   plugins are not in this repo at all.
2. **Inspect the actual installed server plugins** when dependencies live outside the repo.
   The consumer side can be audited from source; the provider side cannot.
3. **Don't confuse CSS `border-radius` hits with RADIUS.**
4. **Don't confuse accounting "voucher" terminology with HotSpot vouchers.**
5. **Don't treat grep hits as proof without distinguishing comments from code.** A
   `testing123` check reported STILL PRESENT when all five hits were commented-out stock
   examples. **Use comment-aware checks.**
6. **Empty command output is not automatically proof of absence.** A gawk-only
   `match($0, re, arr)` silently produced an empty file under mawk, and the verification
   then "passed" because an empty file trivially satisfies a negative grep. **A test that
   passes on empty output is not a test.**
7. **Don't expose `freeradius -X`** — it can contain the database password. Use `-C` with
   filtered, redacted output.
8. **Never output Starlink cookies, session tokens, passwords or keys.** Structure,
   counts and field names only.
9. **Don't infer ownership from filenames when execution tracing can establish it.** A
   literal-filename grep found no writer for either load-bearing file; both write through
   helpers. The real writers were found by tracing.
10. **Don't call code "dead" merely because it has never executed.**
11. **Keep production domains separate from prototype infrastructure.**
12. **Hardware documentation is not hardware verification.**
13. **Do not write architecture based on assumptions that have not been tested.**
14. **A test written from your own assumptions tests your assumptions.** The counter-method
    that worked: read production state or real source — diff against the stock file, read
    the worker's source, enumerate the callers.
15. **Verify a probe's own coverage.** A directory walk that descended only into plugin
    directories missed the sibling `.plugin-data` directory and nearly produced a wrong
    conclusion about which database was live.

---

## 16. CURRENT OPEN RISKS

From docs/41 §6. **No new risks invented here.**

Note on "affects current customer traffic": per docs/40, **there is no customer HotSpot
traffic at all**. Risks are therefore rated against *admin/reporting paths, live CRM data,
and future launch*.

| ID | Risk | Severity | Affects current customer traffic? | Remediation |
|---|---|---|---|---|
| **R1** | Both Starlink plugins store all data in the upgrade-deleted directory; neither has a persistent location; both write there actively | High | No — but breaks admin/reporting paths and any future launch | **Deferred** |
| **R2** | `sl_kits.json` not in `dr_snapshots` — the backup protects the soft dependency and misses the hard one | High | No — same as R1 | **Deferred** |
| **R3** | `bootstrap_data.php` rescue may silently restore the near-empty legacy database | High | **Yes — for live CRM/WhatsApp data** (705 conversations, 5,833 messages, 391 followups) | **Deferred** |
| **R4** | `auto_block.sqlite3` inside `dishnet-data-report/data/`; `dr_snapshot.php` copies named JSON only, no `.db` | Medium | No | **Deferred** |
| **R5** | Legacy hybrid database stale by content yet modified the morning of the audit, migration count identical to live — something still opens it | Medium | No | **Deferred** |
| **R6** | `sl_sync_settings.json` holds `starlink_cookie` with no `_enc` suffix; encryption at rest **UNRESOLVED** | Medium | No | **Deferred** |
| **R7** | `wifi_test_block_state.json` absent — *"WHO IS CURRENTLY BLOCKED — cannot be reconstructed"* | Medium | No | **Deferred** |
| **R8** | Dealer API not independently authenticated; all customer-facing authorization concentrated in one function | Medium | No | **Deferred** |
| **R9** | `sl_account_cycles.json` and `accounts.json` absent; two reads in `api_crm_misc.php` return null | Low | No | **Deferred** |
| **R10** | `sl_usage.json` empty; portal usage display has no source | Low | No | **Deferred** |

**None of these was introduced by the MikroTik project.** They are pre-existing conditions
discovered during audit.

---

## 17. AUTHORITATIVE DOCUMENT MAP

Read the relevant document before acting. **This orientation document is the entry point;
those are the evidence.**

| Doc | Purpose |
|---|---|
| **docs/30** | Zero-Touch network platform architecture. §0.1 carries a correction — read the correction, not the original |
| **docs/31** | Phase 0 hardware test protocol — bench protocol requiring physical hardware |
| **docs/32** | Phase 0 operator/bench run book — **the authoritative Step 0 gate and Run Sheets A/B/C** |
| **docs/33** | Original Phase 0 server foundation — *"given a fresh VPS today…"* |
| **docs/34** | Existing-server audit and corrections. **Supersedes docs/33 §4 (VPS specification) and amends §5.1, §6.1, §6.2** for this host, because docs/33 assumed a fresh VPS and we used the production one. **The architecture in docs/33 §3 is unchanged** |
| **docs/35** | MikroTik staging script — what to configure on a router so it joins DishNet by itself |
| **docs/36** | Phase 0 RADIUS build + execution record + §6.6 cleanup record |
| **docs/37** | Existing PWA/HotSpot audit |
| **docs/38** | Sibling plugin dependency audit (consumer side) |
| **docs/39** | Provider-side audit of the two installed Starlink plugins |
| **docs/40** | Five-question closure — production usage and persistence |
| **docs/41** | **BINDING** Starlink/MikroTik architecture boundary freeze |

**docs/35 is included here deliberately.** It exists and was omitted from an earlier
version of this map.

> Reading only one document and drawing a platform-wide conclusion is how the reversed
> data-flow assumption survived as long as it did. Read the map first.

---

## 18. MANDATORY RULE FOR FUTURE CLAUDE SESSIONS AND DEVELOPERS

**Before changing anything:**

1. **Read this document.**
2. **Read the relevant authoritative document** from the map in §17.
3. **Determine whether the requested change affects Domain A (Starlink) or Domain B
   (MikroTik).**
4. **Do not cross the Starlink/MikroTik boundary** without an explicit architecture
   decision recorded in docs/41.
5. **Do not assume undocumented behavior.**
6. **Do not modify production systems during bench validation.**
7. **Do not implement the MikroTik control plane before B1.**
8. **Preserve existing Starlink functionality.**
9. **Do not expose secrets in reports or logs.**
10. **Record newly verified behavior** in the appropriate authoritative document — not
    only here.

> ### If you discover something that contradicts this document
>
> **STOP.** Do not implement the change. Update and reconcile the architecture
> documentation first, then proceed.
>
> A contradiction is evidence that someone's model of the platform is wrong. Finding out
> which one, before writing code against it, is cheaper every time.

---

## 19. DOCUMENT STATUS

> **STATUS: BINDING / MUST READ**
> **Scope:** DishNet customer platform + existing Starlink ecosystem + MikroTik
> Zero-Touch/HotSpot project
> **Last verified:** 2026-09-19
> **Source documents:** docs/30–41
> **This document does not replace the detailed documents. It is the entry point and
> system map.**

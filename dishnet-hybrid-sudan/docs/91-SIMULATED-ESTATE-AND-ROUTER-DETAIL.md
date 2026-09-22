# 91 — MikroTik Admin prototype v0.1: a coherent simulated estate

**Status:** BUILT, development/test only. Production untouched.
**Suite: 1,446 assertions green** (1,349 before; +93 simulator, +4 boundary).
No migration, no schema change, no production contact, no Domain A contact.
**No gate moved.**

---

## 1. The contradiction, and what actually caused it

The review spotted that the Routers list said *"No routers yet"* while Router
Detail showed `WAN-UNSET-1`. That was right, and the cause was worse than a UI
inconsistency.

**Two findings, both mine:**

1. **The two screenshots were never one state.** `02-router-detail.png` was
   fifty seconds *older* than `01-routers.png`. The screenshot script looked for
   a router row, found none, skipped the detail shot, and left the previous
   run's file in place. A stale pair was sent as if it were one view. The script
   now **fails** if the list is empty or the detail does not open a `SIM-`
   router, so the pair cannot be incoherent again.

2. **There was no simulator dataset at all.** The panel was pointed at
   `dnb_test` — the database `tests/run.sh` **drops and recreates on every run**.
   What the panel displayed was fixture residue from whichever test file ran
   last. `WAN-UNSET-1`, `4be1d3c3` and `be96294c` were test artifacts, exactly as
   the review suspected.

The fix is therefore not a UI patch. The simulator gets **its own database**,
which the suite never touches.

---

## 2. The simulated estate

```
php plugin/bin/plugin.php install     # against DNB_DSN
php plugin/bin/plugin.php simulate    # builds the estate
```

| | |
|---|---|
| customers | 3 — `SIM-CUST-001/2/3`, each with a service, a principal, sites and a plan |
| sites | 5 — `SIM-SITE-001` … `SIM-SITE-005` |
| routers | 5 — `SIM-MT-0001` … `SIM-MT-0005`, five distinct lifecycle states |
| vouchers | 17 in 3 batches |
| sessions | 6, ingested as RADIUS accounting |
| uplink samples | 36 across 3 routers |
| provisioning jobs | 3 |
| audit rows | 24 tenant-visible, **written by the acts, not inserted** |

### 2.1 Two rules the simulator keeps

**Everything is built through the real Domain-B write paths.** Customers from
`mt_customer_create`, routers from `mt_device_register`, assignment from
`mt_device_assign`, WAN facts from `mt_device_set_wan`, transitions walked one
legal step at a time through migration 012's trigger, vouchers from
`VoucherService`, sessions through `AccountingIngest`. Nothing is hand-inserted
to make a screen look full.

Two consequences worth having. The estate obeys every constraint the real system
has — a state on screen is one a real router could actually be in. And the audit
trail exists **because the acts happened**, which is migration 020's W-1 working
end to end rather than being asserted.

**Every identifier announces itself.** `SIM-` on serials, customer names, site
names, WireGuard keys and NAS identifiers. A test asserts no `WAN-UNSET`-shaped
value survives anywhere.

### 2.2 The simulator refuses two things

- A second build without `--again`, so it cannot silently double an estate.
- Running at all when `DN_ALLOW_REAL_BINDINGS` is set. **A simulated router must
  never share a process with a real adapter**, and that is enforced rather than
  documented.

---

## 3. Router Detail — the five sections

**IDENTITY** — name, serial, model, RouterOS, lifecycle state, and customer and
site resolved to **names** rather than truncated uuids. A truncated uuid in a
Customer column tells an operator nothing and reads like a stray identifier,
which is what prompted the review's comment.

**CONNECTIVITY** — only what is *recorded*: tunnel address, WAN interface, who
established it, last contact. The *observed* half lives in SIGNALS, where each
entry says it has no source.

**PROVISIONING** — the ladder, with the timestamps that exist. A device in a
state that is not on the ladder is not drawn as a half-finished ladder.

**OPERATIONS** — vouchers at this site, provisioning jobs with a table of them,
and two honest negatives (§4).

**ACTIONS** — all four inert, each with its reason.

---

## 4. Three further gaps found while building this

Each was found by trying to render a number and discovering it does not exist.

| Gap | What the panel says |
|---|---|
| **Sessions cannot be attributed to a router.** `mt_session_account` never sets `device_id`; accounting carries a NAS identifier and nothing maps a NAS identifier to a device. | **"not attributable"**, with the reason — not `0`, which would read as *none* and is a different claim |
| **Uplink is measured but not exposed to Admin.** `mt_uplink_samples` is written, but there is no Admin projection for it, and telemetry exposure is **decision D-4, which is open**. | **"not exposed to Admin"**, with D-4 named. No projection was added, because adding one would decide D-4 |
| **`wan_interface_set_at` is not in the Admin projection.** | The row was **removed**. Showing an em dash would imply the value is unset rather than unexposed |

The signal inventory now separates two questions that were wrongly collapsed:
**`status`** (measured / unmeasured in Domain B) and **`admin_readable`**
(whether the Admin API can fetch it). Uplink is the case that needed it:
measured, not readable.

---

## 5. Verification

**1,446 assertions green.** 93 new in `tests/test_simulator.php`, which drives
the **real CLI** against a throwaway database rather than testing a private
method — so it proves the documented commands work.

Proofs the review asked for:

| Requirement | How it is proven |
|---|---|
| list and detail use the same dataset | every id `mt_admin_routers()` returns is opened by `mt_admin_router(id)`, and an id the list never offered returns nothing |
| no production-looking identifiers | every serial, key, customer name and NAS identifier starts with `SIM-`; no `WAN-UNSET`-shaped value exists |
| panel contains no DB access | no `pdo`, `pgsql`, `SELECT` or role name in panel code |
| panel contains no credentials | no password, secret, api key, `Authorization`, `radius_ref`, `radcheck`, `radreply`, `rest/`, 8728 or 8729 |
| no real network call | the simulator contains no `curl_`, `fsockopen`, `stream_socket_client`, `RouterOs` or `RestClient` |
| all real actions unavailable | every action reports `available: false`; summary reports 0 of 4 |
| signal colour from the server | the CSS class is the server's status string; the panel asserts nothing about WireGuard, RADIUS or HotSpot |
| banner always present | every render writes it, driven by the server's binding report |

### 5.1 The suite caught the simulator breaking three architectural rules

Worth recording, because the guards earned their keep:

- **`Database::inspector()` in application code** — F2 forbids the BYPASSRLS
  test identity in `src/`. My first simulator used it to read across tenants.
  Replaced with the legitimate path: the Admin projection on `dnb_adminapi`.
- **Writing `mt_entitlements` from a customer path** — F8/F10 forbid it. The
  insert was removed entirely.
- **Reading `Acct-Input-Octets` without `Gigawords`** — RFC 2869; a reader that
  ignores the wrap count under-reports every session past 4 GiB. Gigawords
  added.

And a fourth, found by the fix itself: `alreadyBuilt()` first counted
`mt_devices` directly as the admin role and would have returned **zero however
much existed**, because under FORCE RLS with no tenant context
`customer_id = NULL` is NULL rather than true. It reported "nothing built" and
built a second estate on top of the first. The test caught it as *"three
customers — got 4"*.

### 5.2 And the B1-neutral guard caught its author again

Fifth occurrence. The simulator's docblock said it *"does not write
last_seen_at"*, and a crude guard matched the sentence rather than a write. The
guard now strips comments and looks for an assignment, with a meta-assertion
proving the stripper strips. A second guard forbade the word `radius` in panel
code and matched legitimate screen copy explaining why a per-router session
count is not derivable; it now looks for credential-shaped things instead.

---

## 6. What is simulated, what is real, what is unavailable

**Simulated** — the estate's *content*: which customers, sites, routers,
vouchers, sessions and jobs exist. All of it is `SIM-` prefixed.

**Genuinely backed by Domain B** — the *mechanisms*. Every row was written
through the real functions, under real RLS, past real constraints and the real
state machine, producing real audit rows. The Admin API, the projections, the
identity gate and the privilege boundaries are the production ones.

**Unavailable, pending F6-B or an open decision** — every real-network
capability: RouterOS REST, WireGuard state, RADIUS health, HotSpot read-back,
reboot, reset, reprovision, configuration push, real provisioning, voucher
redemption, the AAA publisher, and Decision 5's rate limiting. Uplink exposure
to Admin waits on D-4. Per-router session attribution waits on something that
maps a NAS identifier to a device.

---

## 7. What this does not do

No migration, no schema change, no privilege change, no production contact, no
Domain A contact, no FreeRADIUS change. No Admin write route bound. No portal,
publisher, attempt store or rate limiter. C-b not deployed; `dnb_site_nas` and
`dnb_cred_site` still do not exist. Q2 open, the production census still
required, F6-B still not authorized, `DenyAllIdentity` still the production
Admin identity.

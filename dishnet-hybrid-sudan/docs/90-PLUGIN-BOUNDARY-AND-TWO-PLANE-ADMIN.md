# 90 — The MikroTik plugin boundary and the two-plane Admin Panel

**Status:** BUILT, in the development/test environment. Production untouched.
**Suite: 1,349 assertions green** (1,228 before; +120 new, +1 from an existing
UI assertion). No migration written, no schema change, no production contact.

**Nothing about the gates moved.** F6-B is not authorized, C-b is not deployed,
no Admin write route is bound, the portal is not built, `DenyAllIdentity` is
still the production Admin identity, and migration 020 has not been applied to
production.

---

## 1. What was asked, and the one part that could not be built as drawn

The brief proposed separating the MikroTik control layer from the commercial
layer, packaging it as an installable Domain-B plugin, and building a
professional network-management Admin Panel against it with simulator data.

Almost all of it was buildable. One part was not, and it is worth stating
first because it is the only place this build departs from the mockup.

The Router Detail mockup showed:

```
NETWORK
WAN            ● Up
WireGuard      ● Connected
RADIUS         ● Healthy
HotSpot        ● Running
```

**Measured, this system has no source for any of those four.** Not "no value
right now" — no column, no probe, no adapter, nothing. Rendering them green
would not have been a small inaccuracy; it would have been the screen asserting
that a router had been contacted when nothing contacted it, in a product whose
entire discipline has been not to do that.

A fifth finding came out of the same check: **`mt_devices.last_seen_at` is never
written by anything.** The schema carries a liveness column that no code
populates, so every router reads null. It is not a stale signal — it is an empty
field that looks like a signal.

So the panel shows those five as **"no signal"**, in grey, each with the reason
and what would have to exist to change it. Four other signals are genuinely
measured and are shown as such.

| Signal | Verdict | Source or reason |
|---|---|---|
| Provisioning state | **measured** | `mt_devices.state` |
| WAN interface | **measured** | `mt_devices.wan_interface` — which port a named person recorded at staging. **Not a link state** |
| Uplink throughput | **measured** | `mt_uplink_samples` |
| Active sessions | **measured** | `mt_sessions`, from RADIUS accounting |
| WAN link up/down | no signal | No column, no probe. Only the interface *name* is recorded |
| WireGuard tunnel | no signal | `wg_pubkey` and `tunnel_ip` are **configuration intent**. Neither says a handshake occurred |
| RADIUS | no signal | No health probe exists, and the AAA publisher has never been built |
| HotSpot service | no signal | Nothing observes whether a HotSpot server is running |
| Last contact | no signal | The column exists and **nothing writes it** |

Grey, never red. Red would claim a fault was observed, and nothing observed one.

---

## 2. The plugin boundary

### 2.1 What was physically separated, and what deliberately was not

```
dishnet-mikrotik-control-plane/
├── plugin/                     ← the installable unit
│   ├── plugin.json             manifest: routes, config, gates, lifecycle
│   ├── bin/plugin.php          install | uninstall | status
│   └── public/api.php          THE API ENTRY POINT
├── panel/                      ← a SEPARATE consumer, moved out of public/
│   ├── index.html  app.js  api.js
├── src/  migrations/  tests/   the plugin's implementation
└── tools/dev_server.php        development only, not a deployment artifact
```

**The panel moved out of `public/admin/` into `panel/`.** That is the boundary
that actually matters: the panel is a separate consumer that reaches Domain B
only over HTTP, and a test now asserts it contains no `pdo`, no `pgsql`, no
`SELECT` and no database role name anywhere in its code.

**`src/`, `migrations/` and `tests/` stayed put.** They *are* the plugin's
implementation, the manifest declares them, and moving them would have been
churn across every path in the suite for no boundary gain. Recorded as a
deliberate choice rather than an omission.

### 2.2 The finding that made the boundary real

Before this build, **`AdminRoutes` had no HTTP entry point at all.**
`public/index.php` wires the customer API only, so the Admin surface was
reachable from tests and from nowhere else. "The panel consumes the plugin
through an API" was an aspiration, not a boundary.

`plugin/public/api.php` is that boundary. Two properties it holds by
construction rather than by intention:

1. **It reads as `dnb_adminapi`** — EXECUTE on the eleven projections of
   migration 019 and no privilege on any table. A bug in the entry point cannot
   widen that, because the limit is on the connection. A test asserts the file
   never reaches for `Database::owner()` or `Database::admin()`.
2. **It admits nobody by default.** `DenyAllIdentity`. A development identity
   needs `DN_DEV_STAFF_IDENTITY=yes-development-only`, and `DevStaffIdentity`
   **throws** rather than degrading when real bindings are allowed — so a
   process running against real hardware cannot accept a development staff
   member.

Measured: `GET /api/v1/admin/routers` → **401** by default, **200** only behind
the gate, and the health endpoint reports `provider: DEVELOPMENT-ONLY` and
`real_bindings_allowed: false` so no screen can imply otherwise.

### 2.3 The manifest describes the plugin that exists

A manifest listing routes the code does not serve is a brochure. A test
compares the declared routes against the routes the router actually builds and
requires them to be **identical**.

That comparison immediately caught something my first manifest got wrong. I
declared **zero write routes**. In fact `AdminRoutes` registers **seven POST
paths** — register, assign, actions, sites, plans, voucher-batches, disconnect —
each of which answers **501**. They exist so the capability matrix is complete
and testable. Claiming they did not exist was less honest than declaring them,
so the manifest now carries them under `declared_unbound`, and a test proves
**each one answers 501 rather than writing**.

### 2.4 The lifecycle verifies its own work

`php plugin/bin/plugin.php status` reports what is true of the installation by
asking the database, not what the manifest hopes:

```
  database reachable                 yes
  schema installed                   yes (20 migrations)
  admin read role                    present
  API surface                        read-only, 13 routes
  write routes declared              0
  gates
    F6-B                             NOT AUTHORIZED
    admin-write                      NOT BOUND
    portal                           NOT BUILT
  configuration
    DNB_TOKEN_PEPPER                 set (value withheld)
```

The migration count is read from `mt_migrations`. Secrets are reported as
set/unset and never printed. `install()` **raises** rather than reporting a
success it did not verify. `uninstall` refuses without
`--i-understand-this-drops-data` and says plainly that it is not reversible.

---

## 3. The two-plane Admin Panel

```
NETWORK PLANE            COMMERCIAL PLANE        ADMINISTRATION
  Overview                 Customers & sites       Audit log
  Routers                  Plans
  Network health           Vouchers
  Provisioning jobs        Batches
  Active sessions
  Diagnostics
```

**Router Detail** carries the router's facts, a **provisioning ladder**
(registered → staged → shipped → connected → provisioned → active, with the
timestamps that exist), the **signal panel** of §1, and the **actions**.

A device in a state that is not on the ladder — orphaned, diverged,
decommissioned — is **not** drawn as a half-finished ladder, because it is not
part-way along one. It says which state it is in instead.

**Every action renders inert**, with the server's reason attached:

| Action | Why it is unavailable |
|---|---|
| Push configuration | A delivery case exists, but no Admin write route is bound and F6-B is not authorized — so it would reach a simulator, not a router |
| Reboot router | **No delivery case exists.** Nothing to queue and nothing to deliver |
| Reprovision | No delivery case exists |
| Run diagnostics | Diagnostics would read live router state. Nothing reads live router state yet |

They are shown rather than hidden on purpose: an operator should be able to see
what the product will do and why it cannot do it yet. The buttons carry
`disabled` and `aria-disabled`, and no handler is bound to any of them.

### 3.1 The inventory is the server's answer, not the panel's

`GET /api/v1/admin/network-signals` returns the inventory. The panel renders
what it is given and holds no opinion of its own — a test asserts the panel
asserts nothing about WireGuard, RADIUS or HotSpot in its own code, and that the
signal's CSS class comes from the server's status string. A future front-end
edit therefore cannot turn a dot green.

---

## 4. Verification

**1,349 assertions green.** 120 are new (`tests/test_plugin_boundary.php`).

Four proofs worth naming:

- **the declared surface equals the served surface** — which is what caught the
  seven unbound POST routes;
- **each declared-unbound write path answers 501**, checked by dispatching it;
- **`mt_devices.last_seen_at` is genuinely unwritten** — the panel tells an
  operator this, so a test scans `src/` and `migrations/` and fails if anything
  starts writing it, at which point the copy gets corrected rather than becoming
  a lie;
- **the panel code contains no database access** of any kind.

Responsive: 360 / 390 / 768 / 1024 / 1440 px, five views, all PASS.

### 4.1 Three things that went wrong, recorded

- **The B1-neutral guard caught my prose for the fourth time.** I wrote that the
  Last-contact signal "needs a writer: provisioning read-back or an agent
  check-in", and `check-in` implies the poll delivery model, which B1 leaves
  open. Rewritten to name no mechanism.
- **A guard of mine was silently vacuous.** The panel-leak check used
  `strip_php_comments()`, which is a PHP tokenizer and leaves JavaScript block
  comments intact — so it failed on a comment rather than on code. Replaced with
  a real JS stripper, plus a meta-assertion proving the stripper strips.
- **The responsive tool's `min-font` check does not cover the new views.** It
  reports `99px`, its sentinel for "measured nothing", because it targets table
  cells and the signal grid has none. The overflow, escape and clipping checks
  did run and did pass. Recorded rather than read as a pass.

---

## 5. What this does not do

No migration, no schema change, no privilege change, no production contact, no
Domain A contact, no FreeRADIUS change. No Admin write route bound. No portal,
publisher, attempt store or rate limiter. C-b not deployed, `dnb_site_nas` and
`dnb_cred_site` still do not exist, Q2 still open, the production census still
required, F6-B still not authorized.

The plugin can be installed, inspected, served and looked at. It still cannot
touch a router, and it says so on every screen that would otherwise imply it
could.

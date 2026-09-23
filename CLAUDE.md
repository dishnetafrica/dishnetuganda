# Working in this repository

The Domain B / MikroTik control-plane work lives under **`dishnet-hybrid-sudan/`**.
Note there are two `docs/` directories in this repository; every path below is
from the repository root.

**Read `dishnet-hybrid-sudan/docs/69-DECISION-INDEX.md` first.** Before changing
anything it covers, read the authoritative decision document it points to. Where
the index and a decision document disagree, **the decision document is right.**

## Vocabulary — binding since 2026-09-23 (`docs/117`)

| Term | Means | Stored / named in code as (unchanged) |
|---|---|---|
| **Operator** | the Domain-B **tenant** — the RLS boundary | `mt_customers`, `customer_id`, `mt_current_customer()`, `/api/v1/admin/customers`, JSON key `customer` |
| **Operator Owner** | a principal with `kind = 'owner'` — every operator-plane capability | `mt_principals` |
| **Operator Staff** | a principal with `kind = 'staff'` — the stored value `'operator'` is renamed by migration **027** (built; the production row count it would touch is **NOT ESTABLISHED**, census §1c) | `mt_principals` |
| **DishNet Staff** | DishNet's own people (Admin · NOC · Sales · Support) | `mt_staff` (planned, 026); `StaffRole`; **`actor_kind = 'staff'` means them and nobody else** |
| **DishNet engineer** | the human installing or operating the platform — what documents before `docs/115` call "the operator" | — |
| **Guest** | a voucher, then a session; never an account, never an actor kind | `mt_vouchers`, `mt_sessions` |
| **Location / Site** | `mt_sites`; the UI still says *sites* (T-1b, open wording) | `mt_sites`, `site_id` |
| **Customer PWA** | the product name of the operator-plane app; its users are Operator Staff | `public/`, `/api/v1/me/*` |
| **Commercial customer** | the external billing relationship (uCRM/Splynx), not a Domain-B entity | `ucrm_client_id` |

**Reading rule.** Documents `30`–`113` and the CLAUDE.md sections above
`docs/115` were written before this vocabulary: there, *"the operator"* is the
DishNet engineer and *"customer"* is the tenant. They are records and are
**not rewritten**. `mt_principals.kind = 'operator'` in those documents is the
schema value that still exists; it becomes `'staff'` in 027.

**Never** add a tenant table above `mt_customers`, rename it, or add an
`actor_kind` to tell Operator Staff from DishNet Staff — the former are
`principal`, the latter `staff`.

## Settled — do not reopen without an explicit instruction

- **F1–F13 are FROZEN.** Amendment only by the process in `docs/53` §5.
- **Decision 1 — CLOSED: Model B.** The AAA credential is published at
  redemption/activation, not at voucher issue. (`docs/66` §1)
- **Decision 3 — CLOSED: P2.** The portal receives *generated* AAA credentials;
  **the voucher code never enters the AAA credential path.** (`docs/67`)
- **Decision 7 — CLOSED:** a dedicated **AAA Publisher**, with the privilege and
  reconciliation boundary as documented. (`docs/66` §2)
- **Decision 2b — CLOSED: A / SITE-BOUND.** A voucher is bound to its issuing
  site and is valid only against the NAS set authorized for that site. This does
  **not** imply that a site may have only one router. (`docs/68` §2.5)
- **Decision 2a — CLOSED: C-b**, site-keyed dynamic SQL source-address
  restriction — `dnb_cred_site` + `dnb_site_nas` + the `EXISTS` predicate
  against the authorized NAS set. **Huntgroups are RETIRED as a production
  candidate; do not build on them.** (`docs/70` §8)
- **Voucher issuance requires a site — CLOSED.** Under 2b a voucher may not be
  issued without an issuing site; `mt_vouchers.site_id` becomes `NOT NULL`.
  Decided, **not migrated**. (`docs/77` §1)
- **The customer/site ownership invariant covers `mt_devices`, `mt_vouchers`
  and `mt_voucher_batches` only.** `mt_plans` is **out of scope** — do not add
  it because the column names match. (`docs/75` §5, `docs/77` §3)
- **P-B — CLOSED.** `mt_principals.phone` stays **globally UNIQUE**. Do not
  narrow it per-customer, weaken or drop it: `mt_auth_issue_code` resolves the
  tenant with a non-`STRICT` `SELECT … INTO`, so duplicates would bind a
  one-time code — and the session's customer — to an arbitrary principal,
  silently. **C10 may not be solved by weakening it.** (`docs/107` §2)
- **P-C — CLOSED: principal reassignment is PROHIBITED.** No operation may
  change `mt_principals.customer_id`. Resolution re-reads `p.status` live but
  takes `customer_id` from the session's own snapshot, so disable is enforced
  immediately and reassignment would not be enforced at all. The lifecycle is
  **disable, then create a new principal**. (`docs/107` §3)
- **S-A — CLOSED: service migration is PROHIBITED** as an ordinary operation.
  `mt_services.customer_id` is not a mutable field. If commercial ownership
  genuinely changes: **end the service and create a new one**, never re-point
  it. (`docs/107` §4)

## Chosen ≠ built

- **Decision 2a chose a mechanism and built nothing.** `dnb_cred_site` and
  `dnb_site_nas` **do not exist**, the production authorize query is still
  username-only, and that query change is a **separately authorized** step.
  (`docs/70` §8.2–8.3)
- **Every measurement behind it ran on a disposable instance.** Production
  FreeRADIUS has never run this mechanism. (`docs/70` §5.2)

## The next gate

**The production census** — the operator runs it; **`docs/79` is the handoff**.
This session **cannot** reach production: no SSH client, no DSN, egress 403, and
the Phase 0 database is loopback-only (`docs/78` §1.1). **Do not attempt network
access, SSH discovery, DSN guessing or production probing.** **Production data
state is NOT ESTABLISHED**; never infer it from a disposable database or from
what a document permits. The provisioning writer (`docs/71`) is designed but
unapproved and blocked. **It is not F6, and F6 is not the next step.**

- **`mt_voucher_batches.site_id NOT NULL` is an OPEN SCHEMA DECISION**, not a
  consequence of the voucher decision and **not** in the migration plan. It
  awaits production evidence and explicit approval. (`docs/78` §4.2)

## The Admin write floor — built, but bound to nothing

Migration **020** (`docs/85`) implemented W-1/W-2/W-3 **in the development and
test schema only**:

- **W-1** — all seven provisioning functions write their own audit row inside
  the same transaction, via `mt_audit_write()`, owned by `dnb_def_audit` and
  callable **only** by `dnb_def_prov`. No HTTP role may write an audit row
  directly. The actor is a **parameter from the Admin identity boundary** —
  never a session GUC, never a request field. Four signatures changed and the
  four old ones were **dropped**.
- **W-2** — `device.site_id IS NULL OR device.customer_id = site.customer_id` is
  a **constraint** (`UNIQUE (id, customer_id)` + composite FK + CHECK), so it
  binds the direct-`UPDATE` path too. **Nothing was backfilled.**
- **W-3** — `dnb_adminwrite`: EXECUTE on the seven, **zero table privileges**.
  `dnb_admin`'s existing grants were **not** revoked (needs its own audit).

**Since G-C (`docs/118`) exactly TWO Admin write routes are bound — router
register and router assign, each one W-1 function on `dnb_adminwrite` with the
authenticated staff subject as the actor. No other Admin write route or button
is bound, and none may be without a new instruction.** `DenyAllIdentity` is
still the default Admin binding. `Database::adminWrite()` is reached by those
two routes through `RouterAdmin` and by nothing else.

- **Applying 020 to production is NOT authorized.** It assumes zero existing
  customer/site violations, which is established for the development schema
  only. The census comes first.
- **New findings:** **F-7** — `mt_voucher_redeem` changes business state and
  writes no audit row; it is on the frozen redemption path and was **not**
  changed. **F-8** — six caller-written audit sites remain on the customer API.
  Both audited in `docs/86`; see below.

## The redemption path contradicts Decisions 1 and 3 — `docs/86`

Measured, not inferred:

- **Redemption is wired to nothing.** `mt_voucher_redeem` is called only by
  `VoucherService::redeem()`, which **has no caller** — no route, no worker, no
  PWA reference. Adding an audit row to it would be a control that never runs.
- **The AAA registry row is written at ISSUE time**, not at redemption
  (`VoucherService::issueBatch`) — Decision 1 says Model B, publish at
  redemption.
- **`radius_username` is a reversible transform of the voucher code**
  (`radius_ref . '-' . code-without-dashes`) — Decision 3 says the voucher code
  **never** enters the AAA credential path. **This blocks any production-capable
  voucher activation path.**
- `mt_hotspot_users` holds **no secret** and **no site**; `RadiusPublisherPort`
  is called by no production code; Decision 2b is not enforced at activation.
- **Do not invent an actor taxonomy.** `mt_audit_log` already constrains
  `actor_kind` by CHECK to `principal | staff | system`. `guest` and `worker` do
  **not** exist; adding either is a schema decision.
- **F-8 corrected:** the six customer-API audit sites *are* transactional
  (`Kernel` wraps every authenticated handler in `TenantContext::run`). The
  defect is **skippability** — measured. Separately, four mutating routes audit
  nothing at all: `auth/request-code`, `auth/verify`, `auth/logout`, and
  `internal/radius/accounting`; the three auth routes also run outside any
  transaction.

**Remediation is DESIGNED in `docs/87`, and NOT authorized to build.** The
current voucher/AAA implementation is classified **NON-CONFORMING — NOT
PRODUCTION CAPABLE**. Key points that bind any future work:

- The target flow is `docs/66` (Decisions 1 and 7) + `docs/67` (Decision 3), not
  a new design: `mt_portal_redeem(code, nas)` in one control-plane transaction →
  `activating` → a **separate** publication store (never `mt_intents`, whose
  timings `docs/66` §2.7 explicitly refuses) → the AAA Publisher mints
  username **and** password independently from a CSPRNG → `publish()` in the
  `radius` database → `active`.
- **`dnb_cred_site` must be written from `voucher.site_id`, never from the
  guest-asserted NAS.** The portal's NAS claim may only reject early; C-b at
  FreeRADIUS is the enforcement.
- **Do not add a `guest` actor kind.** `principal | staff | system` is
  CHECK-constrained in the schema and `system` is correct for portal activation.
- `mt_voucher_redeem`, `VoucherService::redeem()` and `radiusUsername()` are all
  to be **deleted**, not made reachable.
- **P-1…P-7 in `docs/87` §H.1 must be decided first** — including `dnb_portal`,
  Decision 5's numbers, and the `mt_vouchers.site_id NOT NULL` migration, which
  is still gated on the production census.

**D-1a and P-1 are now CLOSED** (`docs/88` §C.1):

- **D-1a** — the guest NAS is `detail.nas_claimed`, **untrusted request context
  only**. Do not overload `source`. Authorization derives from `voucher.site_id`
  and that site's authorized NAS set. The portal may never choose or establish a
  voucher's site.
- **P-1** — **`dnb_portal`**, a distinct unauthenticated guest-portal role.
  **Never `dnb_app`.** Minimum privilege: EXECUTE on one narrowly scoped
  redemption function plus ticket status, and **no table privileges** — it may
  not enumerate vouchers, customers or sites, read credentials or sessions,
  modify arbitrary voucher state, run arbitrary SQL, or call Admin functions.

**Decision 5 is OPEN and GATED — it cannot be closed by picking numbers**
(`docs/89` §3). Its premise was refuted: `docs/65` §9.3 made per-NAS primary,
`docs/68` §2.2 refuted the claim it rested on (a portal POST's NAS is
guest-asserted, so per-NAS limiting is evadable), `docs/65` §9.2 records that
per-code *"does not slow an enumeration run at all"*, and §9.3 that source IP is
**not primary** because a NAT'd venue shares one address.

> **The gate.** Decision 5 cannot close until the remaining
> trustworthy/effective portal-rate-limit dimension is established. Per-NAS and
> per-code alone are insufficient; source-IP alone is operationally
> problematic. **The next required evidence is whether the MikroTik
> redirect/portal path can provide a verifiable infrastructure-bound signal, or
> whether another anti-enumeration mechanism must be designed.** That is a
> hardware/RouterOS evidence question (H1–H7 unverified) and cannot be answered
> from documentation. Disposable environment only.

**Do not choose R-a/R-b/R-c/R-d. Do not invent numeric limits.** The eight
parameters are recorded in `docs/89` §3.1 with a fifth column, *evidence still
required*, and read **UNMEASURED / OPEN** wherever a number would be invented —
`docs/88` §A.1 is **superseded** and its numbers must not be used. **Publication
latency stays unmeasured until the publisher exists and is exercised against the
disposable FreeRADIUS environment.** Two properties are not tradeable: response
uniformity, and decaying windows rather than hard locks.

## Attempt store and site authority — CLOSED (`docs/89`)

**The attempt store is a CLOSED requirement and a prerequisite** (`docs/65` §8
remains authoritative). Failed unauthenticated redemption attempts go to a
**separate non-tenant store**, never `mt_audit_log`:

- **never the raw voucher code** — store `code_prefix` + `code_hash` only;
- **classify the outcome** (`unknown_code`, `wrong_site`, `race_lost`, …) while
  the guest is told `invalid` for every one;
- **the rate-limit counters live here**, transactional with the attempt, inside
  the redemption function where a second caller cannot bypass them;
- it is operational/security telemetry, not business audit — volume, tenancy and
  retention all differ;
- **tenant/customer data must not be required to perform the anti-enumeration
  check**: an unknown code resolves no customer, so a check needing one could
  not run in exactly the case enumeration produces.

**`guest` is NOT added to `actor_kind`.** `docs/64` §10 offered two ways to
satisfy `docs/65` §8 — add `guest`, or give redemption its own log table — and
the attempt store **is** that table, so §8's enum proposal is **superseded**.
**No actor kind is not no attribution:** the attempt store records source,
`nas_claimed`, code prefix/hash and outcome for abuse control without pretending
an unauthenticated guest is a `principal` or `staff` actor.

**Site authority — CLOSED.** `docs/65` §8/§9.3's *"site resolved from that NAS"*
is **superseded**. The authoritative rule is
`voucher.site_id` → that site's authorized `dnb_site_nas` mapping → decision.
`nas_claimed` is untrusted context that may only reject early; it may never
establish, select or widen the NAS set a voucher is valid at.

> **SI-1 — changing `nas_claimed` alone must never change the site to which a
> voucher is authorized.** Proven by execution at implementation time
> (`docs/87` T-13, and T-18, the mandatory C1 guard).

**Decision 4 — no second redemption path required** (`docs/88` Part B).
`docs/64` Q5 recommends **no**; if ever built it is a **separate function with a
separate grant**, never a flag on the portal one, and **`dnb_app` must never get
generic redemption authority because it might exist**. The tenant-crossing
danger is measured, not theoretical.

**Q-F7-1…7 and F-8.1…5 are OPEN.** Nothing in this area may be implemented
without an explicit instruction.
- **W-4, W-5, W-6 remain OPEN.** Do not invent a staff credential store, a
  suspended state, customer/site editing, or a delivery case that does not exist.

## The plugin boundary and the Admin Panel — built (`docs/90`)

The control plane is now an **installable Domain-B plugin**:
`plugin/plugin.json` (manifest), `plugin/bin/plugin.php` (install / uninstall /
status), `plugin/public/api.php` (**the API entry point, which did not exist
before** — `AdminRoutes` was reachable only from tests). The Admin Panel moved
out of `public/admin/` to **`panel/`** as a separate consumer and holds no
database access of any kind. `src/`, `migrations/` and `tests/` stayed put
deliberately: they are the plugin's implementation.

- The entry point reads as **`dnb_adminapi`** and binds **`DenyAllIdentity`** by
  default. `DN_DEV_STAFF_IDENTITY=yes-development-only` enables a development
  identity, which **throws** rather than degrading when real bindings are
  allowed. Measured: 401 by default, 200 only behind the gate.
- **The manifest's declared surface is asserted equal to the served surface.**
  That caught seven POST paths I had wrongly declared absent; they exist and
  **each answers 501**, declared under `declared_unbound`.
- The Admin UI is now **Network plane / Commercial plane / Administration**,
  with Router Detail, a provisioning ladder, Network health and Diagnostics.

**NO ROUTER SIGNAL MAY BE INVENTED.** Measured during this build: there is **no
source** for WAN link up/down, WireGuard tunnel state, RADIUS health or HotSpot
service, and **`mt_devices.last_seen_at` is never written by anything**. All
five render **"no signal"** in grey with a reason and what would be needed —
never green, and never red (red would claim a fault was observed). The inventory
is declared server-side in `src/Network/SignalReport.php` so a front-end change
cannot colour a dot in, and a test fails if anything starts writing
`last_seen_at` while the panel still says nothing does.

All four router actions (push config, reboot, reprovision, diagnostics) render
**inert** with specific reasons. Suite **1,349** assertions.

## The simulated estate — `docs/91`

`php plugin/bin/plugin.php simulate` builds a coherent, explicitly synthetic
MikroTik estate. **It has its own database.** Never point the panel at
`dnb_test`: `tests/run.sh` drops and recreates that on every run, so the panel
then shows fixture debris — which is exactly how a Routers list saying "no
routers" came to sit beside a Router Detail showing one.

- Everything is built through the **real Domain-B write paths**, so the estate
  obeys every constraint and the audit trail exists because the acts happened.
- **Fixture debris travels BOTH ways — measured 2026-09-22.** `dnb_sim` was found
  holding **two customers named `Riverside Hotel` and `Kabale Hostel` with
  `ucrm_client_id` 1001/1002**, zero `SIM-` identifiers of any kind, and
  **`test:seed` as its only audit actor**. Those are `tests/bootstrap.php`'s
  `seed_two_customers()` fixtures, not an estate: a bootstrap-based probe had
  been run with `DNB_DSN` pointed at `dnb_sim`. **Not caused by migration 022** —
  a targeted audit revoke neither creates nor deletes a customer, and the proof
  is positive rather than absent: `ucrm_client_id`'s only writer is the test
  bootstrap, and the simulator's own `sim:seed` actor appears **zero** times.
  **The append-only audit trail is what made this answerable** — simulator rows
  could not have been deleted, so their absence proves they were never written.
  Rebuild with `plugin.php simulate`; never diagnose a panel from row counts
  alone.
- Every identifier is `SIM-` prefixed. A test asserts no `WAN-UNSET`-shaped
  value survives.
- The simulator **refuses to run when `DN_ALLOW_REAL_BINDINGS` is set**: a
  simulated router must never share a process with a real adapter.
- It must not use `Database::inspector()` (F2), must not write
  `mt_entitlements` (F8/F10), and must read `Gigawords` wherever it reads
  octets (RFC 2869). The suite caught all three.

**Three more things the panel may not claim** (`docs/91` §4):

- **Sessions cannot be attributed to a router.** `mt_session_account` never sets
  `device_id`; accounting carries a NAS identifier and nothing maps it to a
  device. The panel says **"not attributable"**, never `0`.
- **Uplink is measured but NOT Admin-readable.** There is no Admin projection
  for `mt_uplink_samples`, and telemetry exposure is **D-4, which is open**.
  Do not add a projection — that would decide D-4.
- **`wan_interface_set_at` is not in the Admin projection**, so it is not shown
  at all rather than shown as an em dash.

The signal inventory separates **`status`** (measured in Domain B) from
**`admin_readable`** (the Admin API can fetch it). Do not collapse them.

## The Admin read boundary is THIRTEEN projections — `docs/93`

Migration **021** added the two approved additions and nothing else:

- **`mt_admin_services()`** — `id, customer_id, kind, status, started_at,
  ended_at`. **`status` is RECORDED, not observed.** A service marked `active`
  does **not** mean a HotSpot server is running. A test asserts `SignalReport`
  keeps HotSpot liveness `UNMEASURED` whatever this returns. **Never derive
  HotSpot liveness from service status or from the router's lifecycle state.**
- **`mt_admin_voucher(uuid)`** — column list **identical to
  `mt_admin_vouchers()`**, deliberately: a detail view that returned more would
  be a way to reach a withheld field one row at a time. **`code` is withheld**,
  here and everywhere in Admin.

**The voucher lifecycle is declared server-side** in
`src/Vouchers/LifecycleReport.php`: `unused` and `revoked` are reachable;
`activating`, `active` and `expired` are **not**, each with the reason. **Do not
manufacture `active` or `expired` vouchers in the simulator to fill a screen** —
the estate shows 17 vouchers, all `unused`, because that is the truth.

**Operator/engineer split.** Router Detail shows concise verdicts
(`WireGuard tunnel — NOT MEASURED`) and links to Diagnostics; **Diagnostics**
carries source, limitation and what would be required. Both render from the same
server inventory. `WAN interface` is **`WAN interface assignment — RECORDED`**:
what is measured is the interface a person recorded at staging, not the link.

## The plugin is installable — B-1 closed, B-2 open (`docs/96`, `docs/97`)

Release candidate **`dishnet-mikrotik-0.1.0-rc1`**, content digest `aa48b4b4…b1db63c7` —
the digest of the archive's `SHA256SUMS`, which is stable across rebuilds. The
archive's own sha256 is **not** an identity: tar records mtimes, so identical
source yields different bytes. Compare the content digest.
Measured end to end from the built tarball into a PostgreSQL cluster created for
the test: **install → serve → simulate → uninstall leaves zero residue**, then a
**second install from the artifact alone** (67 checks). The classification is
**B — an independent Domain-B service**, measurably **not** a UCRM plugin. **Do
not call it a UCRM plugin because the directory is named `plugin`.**

**B-1 — CLOSED.** *Migrations declare privilege; the installer supplies
credentials; neither ever carries a secret in source control.* The six
`CREATE ROLE … PASSWORD '<literal>'` clauses are gone — **nothing else in
`migrations/` changed**, not one attribute, grant or policy. `rolpassword` stays
NULL, so under `scram-sha-256` a role cannot authenticate until
`plugin.php install` provisions one, supplied via `DNB_*_PASS` or generated into
`DNB_SECRETS_OUT` at mode 0600. `Database::connect()` has **no password
defaults** — an unset one raises.

- **Do not put a credential in a migration, ever**, however well chosen. The
  defect was the location, not the entropy.
- **`Doctor::DEV_PASSWORDS` is the BURNED list**, not a description of the
  migrations. Those six strings are in Git history; the check exists only to
  keep proving they are dead. Do not delete it and do not "update" it.
- **A credential check needs a positive control.** Under `trust` every password
  succeeds, and the check reported six live credentials on a database where none
  was set. A deliberately wrong password goes first; if that connects the result
  is **SKIP**, never a verdict.
- **The installer must not alter anything it might then refuse over.** The first
  version generated six credentials, applied them, and only then found it had
  nowhere to write them.
- **`pg_authid` is superuser-only** and the owner is deliberately not a
  superuser. Do not widen its privilege to ask a convenience question — the
  installer asks "did this role predate this install?" instead.
- **Quote every value a shell will source.** An unquoted DSN contains semicolons
  and becomes three commands.
- Roles are provisioned only where the owner created them; PostgreSQL refuses
  otherwise. On a development cluster with older roles, drop them and let the
  migrations rebuild them — **do not grant the owner more privilege**.
- `tests/run.sh` mints fresh credentials per run and installs the way an
  operator does. `tools/dev_panel.sh` restores the local panel workflow.

**B-2 — OPEN, and deliberately untouched.** `DenyAllIdentity` → 401 on every
route; only `DN_DEV_STAFF_IDENTITY` makes the panel render. W-4 is OPEN, so a
**demonstration** install is possible and an operational one is not. **Do not
invent a staff identity to work around this.**

- **The 12 roles install creates are cluster-wide.** Installing onto the cluster
  that serves UCRM would add them there, and uninstall would drop them
  cluster-wide. **Prefer a separate PostgreSQL instance.**
- **Nothing is installed anywhere.** This session cannot reach the DishNet
  server — no SSH client, no DSN, egress 403. `plugin/bin/install-test.sh` is
  the operator's equivalent; `plugin/doc/INSTALL.md` ships the runbook.
- The package **excludes `tests/`** (it needs a BYPASSRLS fixture identity),
  `tools/`, `docs/`, and **`public/`** (the customer API front controller, which
  nothing in this install serves). Do not add them.
- Both static servers resolve with `realpath` and require containment under
  `panel/`. Ten representative paths — source, migration, manifest, env
  template, and the generated secrets file — are asserted unreachable.

No gate moved. F6-B still NOT AUTHORIZED, Admin writes still unbound, portal
still unbuilt, Decision 5 still OPEN and gated, the census still next.

## UISP/uCRM integration — audited, nothing decided (`docs/98`)

The plugin contract is **MEASURED** from two plugins already running on the
DishNet server (`dishnet-hybrid-sudan/`, `dishnet-ai/`) — not from documentation,
which is unreachable (egress 403), and not from the production server, which
this session cannot touch.

- **The contract:** a **ZIP with `manifest.json` at the archive ROOT**;
  `main.php` executed on a **~5-minute tick**, never a daemon; **exactly one**
  public file — *"UCRM only exposes public.php directly"* — routed by
  `public.php?page=`; uCRM writes `ucrm.json` (`ucrmLocalUrl`, `ucrmPublicUrl`,
  `pluginAppKey`); the CRM REST API is `api/v1.0/*` with `X-Auth-App-Key`;
  storage is a data dir that survives updates, holding **SQLite and JSON —
  there is no PostgreSQL**.
- **Staff identity is already solved, in the sibling plugin:** forward the uCRM
  session cookie (`nms-crm-php-session-id`, `nms-session`, `PHPSESSID`) to
  `/current-user`; 403 when nobody is logged in. **It works only same-origin.**
  A uCRM admin arriving that way is **`staff`** — `actor_kind` already allows it
  and **no new actor kind may be invented**.
- **RC1 is NOT installable through the plugin mechanism, and must not be
  forced.** Wrong archive, wrong manifest, and — decisively — a plugin has no
  PostgreSQL, cannot `CREATE ROLE`, and offers no RLS or `SECURITY DEFINER`.
  **Every Domain-B control lives in exactly those features.** Cramming it in
  deletes the security model.
- **Recommendation R-1 — a thin bridge plugin; Domain B stays a separate
  service.** The plugin supplies the menu, the staff identity and the uCRM
  adapter. It must **never** connect to Domain-B PostgreSQL, hold a Domain-B
  role credential, write a Domain-B table, or call a provisioning function.

> **I-1 — Domain B is today a SECOND, UNLINKED CUSTOMER MASTER.** Measured:
> `mt_customers.ucrm_client_id` is nullable, its **only writer in the whole
> repository is `tests/bootstrap.php`**, `mt_customer_create(p_name, p_created_by)`
> cannot set it, Domain B contains no uCRM client code at all, and the simulator
> shows 3 customers with 0 linked. `docs/45` says the commercial identity lives
> in uCRM; nothing implements that. **Decide U-1 (projection or independent
> record) and U-2 (`NOT NULL`?) before more customers accumulate.**

- **Billing and Support do not exist in Domain B** — zero matches in
  `src/Api/Routes.php`. They can only come from uCRM, and no adapter exists.
  **Do not fabricate either screen.**
- **UNVERIFIED and only the operator can answer** (`docs/98` §14): the installed
  UISP/uCRM version, whether a **client-zone** plugin page is supported, the full
  webhook event list, and whether a disposable uCRM may be stood up at all.
  Without that last one the plugin test plan cannot even begin.
- **M-1:** `dishnet-hybrid-sudan/manifest.json` says `ucrmVersionCompliability`
  where `dishnet-ai` says `ucrmVersionCompliancy`. One is wrong; confirm against
  the real installation before touching it.

Nothing here is authorized to build. No gate moved.

## Customer identity — designed, NOT chosen (`docs/99`)

Two models are written out and compared. **Neither is recommended and neither
may be implemented without an explicit instruction.**

- **Model A** — the uCRM client is the source of truth; `mt_customers` becomes a
  projection; `ucrm_client_id` becomes `NOT NULL`.
- **Model B** — `mt_customers` stays an entity carrying a linked
  `ucrm_client_id` with provenance (`ucrm_linked_by`, `ucrm_linked_at`).

**Do not pick one by assumption.** What decides it is operator evidence
(`docs/99` §3.1): does DishNet ever install before the customer exists in uCRM,
must the panel survive a uCRM upgrade, and **E-2 — how many `mt_customers` rows
production actually holds.**

> **U-2 (`ucrm_client_id NOT NULL`) cannot be closed by choosing a value.** It
> needs the production census, and 18 foreign keys use `ON DELETE RESTRICT`, so
> an unlinked row with a site, voucher or audit history cannot simply be
> deleted. **`docs/79` remains the handoff; production data state is NOT
> ESTABLISHED.**

New findings that bind any future work:

- **I-2 — the duplication is not only `mt_customers`.** `mt_principals` holds
  `display_name`, `phone`, `email` — fields uCRM owns for the same person.
  Deciding the customer model without deciding this just moves the problem down
  a level (**U-6**).
- **`mt_services` has NO uCRM service reference of any kind** — only
  `customer_id` and `kind`. One uCRM client with several services cannot be
  represented today (**U-5**).
- **The estate already solved this once**: the sibling plugin's
  `026_lte_financial_ledger.sql` links its own entity to `ucrm_client_id
  NOT NULL` and records `linked_by`. That is Model B in miniature, running.
- **Never resolve a uCRM client from a phone number at request time.**
  `lib/LeadMatcher.php` matches on the last nine digits, and the plugin's own
  manifest says loose matching "can identify the WRONG customer and disclose
  their balance". **The link is stored once, with provenance — never inferred
  per request.**

**The staff trust boundary — U-7.** The uCRM session cookie is trustworthy only
on the **server side** of the bridge plugin, where `/current-user` answered it.
**Domain B must never accept a staff identity from a browser.** Two
arrangements, neither chosen: **S-1** the plugin proxies every call, or **S-2**
the plugin mints a short-lived signed assertion. Either way the audit actor is
**`staff`**, which `actor_kind` already permits — **invent no new actor kind**,
and the actor arrives as a parameter from the identity boundary, exactly as W-1
requires. This closes B-2 **only inside uCRM**; it does not authorize binding
any Admin write route, and W-4/W-5/W-6 stay open.

Nothing here is authorized to build. No gate moved.

## Identity census — read-only, development databases only (`docs/100`)

> **This is NOT E-2.** The census ran against `dnb_sim`/`dnb_test` in this
> container. **Production data state remains NOT ESTABLISHED**; `docs/79` is
> still the handoff and U-2 still waits on it. Do not quote `docs/100`'s row
> counts as production facts — they describe the simulator.

What it *does* establish are schema properties, which hold wherever the schema
is installed:

- **An existing customer cannot be deleted. Proved by execution**, not read off
  the DDL: the transaction is refused and the error names
  `mt_principals_customer_id_fkey`. **27 foreign keys** — 18 → `mt_customers`
  (15 RESTRICT, 2 NO ACTION, 1 CASCADE), 7 → `mt_principals`, 2 →
  `mt_services`. The two `NO ACTION` constraints are **not** a hole: nothing in
  the schema is `DEFERRABLE`, so they block exactly as RESTRICT does.
  **"Delete the unlinked rows" is therefore not an available migration step** —
  an unlinked customer with any history must be linked, not removed.
- **Every customer that has ever been used has an audit row**, because W-1
  writes one inside every provisioning function. There are no bare rows to drop.
- **`mt_principals.phone` is the AUTHENTICATION KEY**, not duplicated contact
  data — `mt_auth_issue_code` looks up `mt_principals WHERE phone = ?`. It is
  uniquely indexed, so the lookup is safe. **U-6 is therefore not a caching
  question**: refreshing the phone from uCRM would lock the customer out the
  moment uCRM's record is corrected. Decide what is authoritative for the login
  number and what happens when the two disagree.
- **`credential_hash` is dead** — no code reads or writes it. Authentication is
  entirely OTP.
- **Deleting a principal silently erases attribution**: `sold_by`,
  `created_by` and `actor_principal_id` are `SET NULL` and nothing errors.
- **uCRM does expose stable service identifiers** — `clients/services?clientId=`
  with `id`, `clientId`, `servicePlanId`, already read by the working plugins.
  **Domain B cannot represent them at all**: `mt_services` has no uCRM column,
  so a client with several services, or two separately-billed MikroTik sites,
  is not distinguishable. **U-5 should be decided WITH U-1, not after it.**
- **The working precedent is a LINK TABLE, not a column** — `lte_service_links`,
  `UNIQUE` on the **pair** (so many-to-many capable), with `linked_by`/
  `linked_at`/`notes`, and its own comment says that was chosen over a column
  deliberately. Recorded as evidence, **not adopted**: many-to-many has
  consequences for RLS and `/me` that nothing has examined.
- **Webhook registration is programmatic**, not manual-only — `CrmApiClient`
  has `getWebhooks`/`createWebhook`/`updateWebhook`. This **refines `docs/98`
  §2.8**.
- **Support may not be API-reachable at all.** `tickets` appears in the code but
  **no `tickets` endpoint is among the uCRM paths called**. Support moves from
  "not built" to **"not known to be possible"**.

**Operator questions reduced 13 → 4**: **Q5** (does DishNet ever stage
equipment before the uCRM customer exists — *the* input for U-1), Q1 (version),
Q2 (may a disposable uCRM exist), Q6 (must the panel survive a uCRM upgrade).
Three more need a physical instance: client-zone support, the webhook event
list, and the live `clients/services` response shape.

**Nothing chosen. No sync engine, no webhook, no backfill, no automatic linking,
no schema change — including the trivial `credential_hash` drop.** No gate moved.

## Q5 is CLOSED — C, BOTH. Identity lifecycle designed (`docs/101`)

**DishNet uses both workflows: customer-first AND equipment-first.** Operator,
recorded uninterpreted.

**It does not force a Domain-B customer without a uCRM client.** "Equipment-first"
means a **device with no customer**, and that is already built and exercised:
`mt_devices.customer_id` is nullable (`-- NULL until assigned`),
`mt_device_register` takes no customer, `mt_device_assign` is a separate later
call, and `docs/35` §9 records possession — serial, model, keys, staged-by —
never ownership. **Both lifecycles converge at exactly one operation,
`mt_device_assign`.** Never invent a placeholder customer to satisfy a foreign
key.

- **`UNCLAIMED` must NOT become a device state.** It is the predicate
  `customer_id IS NULL`, true across `registered`/`staged`/`shipped`. Adding an
  enum value creates two sources of truth that can disagree.
- **Unclaimed devices are already invisible to customers — measured.** Ground
  truth 5 devices, 1 unclaimed; the three tenants see 2+1+1 = 4, their own, and
  none sees the unclaimed one; with no tenant context, 0. The mechanism is
  `NULL = <uuid>` being NULL, not true. **Do not weaken RLS to accommodate
  equipment-first — nothing needs accommodating.**
- **The question is the GATE, not "Model A or B"**: at which moment must a
  customer carry a uCRM link — creation, device assignment, first voucher, or
  never (**the status quo, which is how I-1 happened**). That is **U-1**, open.

**Determined in `docs/101`, for approval:**

| | |
|---|---|
| customer link | **1:1**, unique both sides — cheap to relax, expensive to tighten |
| service link | **1:1**, unique both sides |
| shape | **columns on the existing tables**, *given* 1:1 — they inherit RLS; a new link table must be given `FORCE RLS` deliberately, and history lives in `mt_audit_log` either way |
| service identity | the uCRM **service id**, **never** a type or plan name |
| write authority | **`dnb_adminwrite` only** — never `dnb_app`, never `dnb_portal`. W-1 pattern: definer function, audit row in the same transaction, actor a parameter, `actor_kind = 'staff'` |
| coherence | **the uCRM service's `clientId` must equal the client linked to that service's customer.** No FK can express it; the linking function must check it against uCRM. This is the most dangerous operation in the bridge |
| unlink / relink | **required** (a customer can be re-papered onto a new uCRM client) and **audited**, carrying previous and new relationship plus a reason |

- **No phone matching, ever, for linking.** `LeadMatcher` warns it "can identify
  the WRONG customer and disclose their balance". Phone stays the OTP key only.
- **Do not copy uCRM contact data into `mt_principals`** — `phone` is the
  authentication key, so a contact edit would silently rotate a credential
  (U-6, open).
- **Nothing is deleted because a uCRM relationship ended.** Eleven orphan states
  are enumerated; rows 7, 8, 10 and 11 are not representable today.
- **`mt_customers` is neither deleted nor demoted to a cache.** It is the
  Domain-B authorization boundary, carrying an explicit uCRM relationship when
  one exists.
- **L-1 — OPERATOR:** must an *intended* customer be recorded for a shipped
  router before uCRM has the client? Not representable today.

**No schema change, no migration, no backfill, no sync engine.** `NOT NULL`
(U-2) still waits on **E-2, the production census**. No gate moved.

## The commercial identity boundary — measured (`docs/102`)

**L-1 CLOSED.** A staged or shipped device needs **no** intended customer
recorded before uCRM has the client. Equipment-first is
`device.customer_id = NULL` across `REGISTERED → STAGED → SHIPPED`, then
`mt_device_assign()`. **No fake, pending or provisional customer; no inference
from phone or email.** *Inventory is not ownership.*

**U-1's rule (proposed):** a device may exist with no customer; a customer may
be **temporarily** unlinked; but before it becomes customer-facing or
commercially active it needs a valid uCRM customer link, and a service link
wherever a service is involved. **`ucrm_client_id` does NOT become `NOT NULL`
globally** — U-2, still gated on **E-2**.

Three measurements, each contradicting the obvious answer:

- **The effective boundary is RLS + triggers + which functions exist — NOT the
  grants.** `dnb_app` holds write grants on 20 tables including `mt_customers`
  and `mt_audit_log` and can use almost none of it: the customer INSERT is
  refused by **RLS `WITH CHECK`**, the audit DELETE by the **append-only
  trigger**. Proved by execution. **Never classify an operation by its grant.**
- **`mt_services` and `mt_sites` have NO production writer** — only
  `Plugin/Simulator.php`. They cannot be gated; they must be **built**, gate
  included.
- **There is no single gate.** `mt_device_assign` looked like one, but neither
  `mt_sites` nor `mt_vouchers` references a device, and the estate already holds
  a site with no router — so `service → site → plan → voucher` completes a sale
  with nothing assigned. **The rule binds a SET of operations, never
  `mt_customer_create`.**

Two classifications that invert the obvious:

- **Voucher redemption must NEVER consult uCRM — FORBIDDEN, not required.**
  `docs/89`: tenant data must not be required for the anti-enumeration check,
  since an unknown code resolves no customer — exactly what enumeration
  produces. A remote round-trip would also break response uniformity, which is
  not tradeable. Redemption authorizes from `voucher.site_id` alone.
- **Audit must NEVER be gated on a link**, or the least-established records
  become the least recorded. `actor_kind` stays `principal | staff | system`.

> **B-2 — the customer-plane commercial writes are not behind functions.**
> `PlanRepository` and `VoucherService` write `mt_plans`, `mt_voucher_batches`
> and `mt_vouchers` **directly** under RLS. Gating plans and vouchers means
> giving them definer functions with audit, the shape W-1 gave the seven.
> **The largest single item U-1 implies — not a flag.**

Nothing authorized to build. No gate moved.

## Write paths — four identity entities have none (`docs/103`)

The schema and the simulator make Domain B look more complete than it is.
Enumerated from the code, **not** from grants:

```
mt_customers   function exists, NO production caller
mt_principals  NONE — Plugin/Simulator.php only
mt_services    NONE — Plugin/Simulator.php only
mt_sites       NONE — Plugin/Simulator.php only
mt_devices     YES — seven definer functions (no route bound)
mt_plans       YES — Policy/PlanRepository      ← POST /me/plans
mt_vouchers    YES — Vouchers/VoucherService    ← POST /me/vouchers
mt_sessions    YES — mt_session_account         ← dnb_radius
```

**`src/Customers/` is an empty directory.** The simulator is excluded from the
release package, so **an installed RC1 cannot create a principal, a service or a
site at all.**

> **You cannot gate an operation that does not exist.** The largest item ahead
> is not the uCRM link — it is that customer, principal, service and site have
> no production write path. A synchronisation engine built now would synchronise
> against workflows that exist only in the simulator.

- **35 mutating operations inventoried**, with actor, tables, audit class and
  idempotency. Audit falls in three classes: **W-1 unskippable** (the seven +
  customer create), **caller-written and skippable** (the six customer routes +
  the worker, F-8), and **none at all** (intent enqueue, redemption F-7,
  accounting ingest, uplink, idempotency, migrations).
- **`POST /me/vouchers` is the only idempotent route.** Everything else has no
  idempotency key.
- **Voucher issuance requires no router** — `mt_vouchers` has no device column,
  and a sale completes with no hardware.
- **Accounting ingest must never consult uCRM** — `dnb_radius` holds EXECUTE on
  one function and no table privileges; a NAS packet must not trigger a CRM
  lookup.

### Three project engineering rules — now binding

**The security evidence hierarchy.** Strongest first:
`1 execution test · 2 RLS/policy · 3 SECURITY DEFINER boundary · 4 application
authorization · 5 route/UI availability · 6 grants alone`.
**Grants are the weakest evidence and are not proof.** Earned, not asserted:
`dnb_app` holds write grants on 20 tables and can use almost none of them —
RLS `WITH CHECK` and the append-only trigger stop it. **Never cite a grant, a
route or a button as proof that something is permitted or prevented.**

**Repository-edit safety.** Three documentation edits in this project used
`str.replace` with no assertion; when the anchor did not match they **changed
nothing and reported success**. Any automated source or document transformation
must assert — and exit non-zero on failure — that the **anchor exists**, the
**occurrence count** is expected, the **replacement count** is expected, and the
**result contains the intended section**. **"The script exited 0" is not
evidence that the change happened.**

**Credible evidence.** `negative result + positive control + known authorization
context`. A `0 rows` result has **seven** possible causes and only one of them is
a finding: genuinely zero · RLS hid everything · wrong tenant context · wrong
database · wrong role · the query never executed · the fixture was never created.
**Every census query and every security test must carry a positive control that
proves the session can see something it is entitled to see**, and must state its
role, database and tenant context. A zero-mismatch result over a zero-row read is
**INDETERMINATE**, never "clean". Three measurements in this project have already
failed this way — a burned-credential check with no positive control, a count of
`mt_sites` that read `0` because no tenant was set, and a cross-tenant `INSERT …
SELECT` that returned **`INSERT 0 0`** because the subquery ran under the
attacker's own RLS context and the *next* statement then "passed". A regression
test also needs **the control on the controls**: it must be shown to fail when the
control it guards is removed. (`docs/105` §0, §5)

Nothing authorized to build. No gate moved.

## The onboarding spine — designed, NOT built (`docs/104`)

The four missing writers from `docs/103` are **one lifecycle**, not four tasks.
Cardinality below is read off the schema; **do not invent cardinality the
repository has not established.**

- **Measured cardinality:** uCRM client **1:1** customer (`ucrm_client_id`
  UNIQUE, nullable) · customer **1:N** principals · customer **1:N** services ·
  service **1:N** sites (`mt_sites.service_id` **NOT NULL**) · site **1:N**
  devices · **`mt_principals.phone` is UNIQUE GLOBALLY**, not per customer, so
  one person cannot act for two customers · device `serial`/`wg_pubkey`/
  `tunnel_ip` are globally unique.
- **`mt_services.kind` permits exactly one value, `mikrotik_hotspot`.**
- **The minimum valid customer is a name and an actor.** No uCRM link, service,
  site, router, phone or voucher. `radius_ref` is supplied by a column DEFAULT.
- **A principal may exist with `phone` NULL** — and then cannot authenticate at
  all, since `mt_auth_issue_code` looks up `mt_principals WHERE phone = ? AND
  status = 'active'`. That is a design question (U-6), not a bug.
- **A site cannot exist without a service; a service needs no site and a site
  needs no device.** Measured in the estate: 5 sites, **1 with no device**.
- **Steps 1–6 of the journey produce a commercially active customer with no
  hardware** — plans and vouchers need no device. **This is why the uCRM link
  cannot be gated on device assignment.**
- **The four onboarding events stay separate:** A customer created · B
  authentication principal created · C network service created · D physical
  device assigned. Only A and D exist; **B and C have no production writer.**
- **`operator` vs `owner` is undefined** — the CHECK allows both and **nothing
  in code branches on it** (P-A, open).

### O-1 — a tenant can attach its site to another customer's service

**Proved by execution, as `dnb_app`, under RLS.** `mt_sites` has two
*independent* single-column FKs (`customer_id`, `service_id`) and nothing
requires them to agree. RLS checks the written row's own `customer_id`, which is
correctly the attacker's, while `service_id` points at a row they cannot read.

- **No disclosure** — measured: the cross-tenant site is dangling to its owner;
  RLS still hides the other tenant's service row.
- **Confirmed integrity defect and cross-tenant denial** — `DELETE FROM
  mt_services` is refused by `mt_sites_service_id_fkey`, so **the victim can
  never end that service.**
- **It is exactly the defect W-2 closed for devices**, one level up. The fix has
  the same shape — `UNIQUE (id, customer_id)` on `mt_services` plus a composite
  FK from `mt_sites` — and is **a schema change, NOT authorized here.**
- **Latent only because `mt_sites` has no production writer.** The spine builds
  that writer, so **O-1 must be closed first** — it is step 0 of the sequence,
  before `mt_site_create` exists.

**Proposed sequence (not authorized):** 0 close O-1 · 1 `mt_principal_create` /
`_disable` · 2 `mt_service_create` · 3 `mt_site_create` · 4 a production caller
for `mt_customer_create` · 5 **then** revisit U-1. Each follows the W-1 pattern
exactly: `SECURITY DEFINER`, definer-role owned, `EXECUTE` to `dnb_adminwrite`
only, audit row in the same transaction, actor a parameter. **`dnb_app` gains
nothing** — a customer must not create their own principal, service or site.

**Idempotency is a per-writer decision, not a default.** Today only
`POST /me/vouchers` has a key; creating a principal or a site twice on a retry
is a real hazard.

**U-1 is deliberately NOT closed**, and no gate was added. New open items:
**O-1**, **P-A** (`operator`'s meaning), **P-B** (is a globally unique phone
right?), **P-C** (may a principal be reassigned, given `sold_by`/`created_by`
would misattribute?), **S-A** (may a service be migrated between customers?),
**I-A** (idempotency per writer). Nothing authorized to build. No gate moved.

## Identity integrity remediation — designed, NOT authorized (`docs/105`)

**Do not build `mt_site_create`, or any other onboarding writer, until O-1 is
closed.** A production site writer would make a known cross-customer integrity
hole reachable.

### O-1, characterised under full controls

Run as `dnb_app` under RLS, one transaction, rolled back, residue 0:

| | | |
|---|---|---|
| **C1** | B deletes its own unreferenced service | `DELETE 1` — the path works |
| 2 | A attaches **its** site to **B's** service, by literal UUID | `INSERT 0 1` |
| **C2** | can A *read* that service? | **0** |
| **C3** | can B *see* the referencing row? | **0** |
| 4 | B deletes that service again | **refused** — `mt_sites_service_id_fkey` |

- **Referential integrity is enforced BELOW RLS.** Neither party can see the
  other's row, yet the constraint binds both. **That is why a composite FK is a
  real floor and an application-level check is not** — an application check runs
  *above* RLS and finds nothing to object to.
- **Not disclosure. Not enumerable.** The writer must name a `gen_random_uuid()`
  it cannot read (C2). **The realistic trigger is not an attacker but a writer
  that passes a `service_id` it did not derive** — a stale id, a copied request,
  a bug. Which is exactly what the spine will build.
- Consequence: **cross-tenant denial** — the victim can never end that service
  and cannot see why.

### The remediation — W-2's shape, one level up

`UNIQUE (id, customer_id)` on `mt_services` + `FOREIGN KEY (service_id,
customer_id) REFERENCES mt_services (id, customer_id)` on `mt_sites`.

- **Both existing single-column FKs stay.** W-2 was additive; `mt_devices` kept
  its two alongside the composite one.
- **No CHECK, and none may be added.** `mt_devices` needs
  `site_needs_customer` only because `site_id` is *nullable* (MATCH SIMPLE skips
  a NULL component). `mt_sites.service_id` and `.customer_id` are **both NOT
  NULL**, so nothing can skip it.
- **Omit `ON DELETE`/`ON UPDATE`** — inherit `NO ACTION`, as W-2 does. Nothing in
  this schema is `DEFERRABLE`, so it blocks as `RESTRICT` does. Deliberate
  consequence: `mt_services.customer_id` cannot be updated while a site
  references it, so **service migration becomes impossible by accident** (S-A).
- **Measured migration constraint:** `Migrator` runs each file as **one implicit
  transaction** (`PDO::exec`, `src/Db/Migrator.php:26`), so **`CREATE INDEX
  CONCURRENTLY` is unavailable.** Lock duration is unknown until E-2.
- **Remediate only the proven defect.** Do not bundle customer FKs, principal
  relationships, service or device lifecycle, `mt_vouchers.site_id NOT NULL`, or
  the `credential_hash` drop.

### The census needs no superuser

`dnb_def_admin` already holds SELECT-only `USING (true)` policies, and
`mt_admin_sites()` → `(id, customer_id, service_id, …)` and
`mt_admin_services()` → `(id, customer_id, …)` already expose every column O-1
concerns, EXECUTE-able by **`dnb_adminapi`** — an ordinary non-superuser login
role. **Do not create a `BYPASSRLS` role for a census**; it would outlive it. A
superuser fallback must be reported as `method=superuser`, never silently.

**This is NOT E-2.** E-2 remains the whole production census; `docs/79` is still
the handoff.

### Questions resolved, retired or newly bounded

- **P-A is RETIRED — it is C6/C16**, open since `docs/47`/`docs/48`, and the
  schema comment says so: `-- kind: owner | operator. C6/C16 OPEN`. **A writer
  may STORE `kind` but must not branch on it** until C6 closes.
- **P-B is genuinely new** — no document addresses the *scope* of phone
  uniqueness. The global index is what makes `phone → exactly one principal`
  resolvable for OTP at all. **A writer must treat a duplicate phone as a
  refusal, never an upsert.**
- **P-C — recommendation: no principal reassignment operation.** `sold_by`,
  `created_by` and `actor_principal_id` are already `SET NULL` silently.
- **S-A — UNIMPLEMENTED**, neither supported nor planned; no writer, no
  document. Leave it forbidden-by-constraint; **do not design migration
  behaviour.**
- **I-A — the existing idempotency mechanism is unusable by all four writers.**
  Measured: `mt_idempotency` is keyed `(customer_id, key)` with `customer_id NOT
  NULL`, so **there is nothing to key a customer-creation retry on**; and
  `dnb_adminwrite` holds **zero table privileges** —
  `has_table_privilege(…,'mt_idempotency','INSERT') = false` — while the Admin
  plane sets no tenant context. **Do not copy `POST /me/vouchers`.** Four of the
  five writers have **no natural key at all** (`mt_services` worst: `customer_id`
  + a `kind` with one legal value). `mt_device_assign` is **idempotent in state,
  not in record** — it re-stamps `claimed_at` and writes another audit row, and
  differing arguments are a legitimate *reassignment*, not a retry.

### Site creation — derive, never accept

`mt_site_create(p_service, p_name, p_location, p_actor)` — **there is no
`p_customer` parameter.** `customer_id` is read from the service row, so the
forgery is *unrepresentable* rather than rejected. Three layers, weakest last:
**composite FK → the function derives → the route carries no customer.** A
`service_id` from a browser is untrusted input naming a candidate: it may only
be resolved **within the caller's own visibility**, exactly as `docs/88` D-1a
treats `nas_claimed` — untrusted context may reject early, never establish
authority.

**U-1 is still OPEN.** ~~Proposed boundary: the uCRM link becomes mandatory at
`mt_service_create` and again at the commercial writes (B-2).~~ **The commercial-writes
half is WITHDRAWN by `docs/110` §1 — no uCRM link is required for plan or voucher
operations.** Customer create, principal create and device possession stay
**unconditional**, and so now do plans and vouchers. Ruled out by evidence: a blanket `NOT NULL` (U-2),
gating at `mt_customer_create`, and gating only at `mt_device_assign`.

**Order: O-1 → census → decide I-A/U-1/U-5/C6/P-B → writers.** Nothing
authorized to build. No gate moved.

## The remediation package — demonstrated, NOT applied (`docs/106`)

Every DDL statement below was **executed against the real schema inside a
rolled-back transaction**. Residue checked afterwards: **zero rows, zero
constraints.** `migrations/` is untouched and nothing is authorized to build.

```sql
ALTER TABLE mt_services ADD CONSTRAINT mt_services_id_customer_key
  UNIQUE (id, customer_id);
ALTER TABLE mt_sites    ADD CONSTRAINT mt_sites_service_customer_fkey
  FOREIGN KEY (customer_id, service_id) REFERENCES mt_services (customer_id, id);
```

- **Referenced column order is cosmetic.** Measured: both `(customer_id, id)`
  and `(id, customer_id)` are accepted against the same UNIQUE — PostgreSQL
  matches the column **set**, not the sequence. W-2's order is equally valid.
- **The supporting UNIQUE cannot fail on existing data.** `PRIMARY KEY (id)` is
  strictly stronger, so `(id, customer_id)` can never reject a row the PK
  accepts — proved twice, including once with a violating site row present. It
  adds an index, not a restriction, and **legitimate cardinality is unchanged**.
  **Only the foreign key can refuse.**
- **No `MATCH FULL`, and no CHECK.** Both `mt_sites` columns are already
  `NOT NULL`, so `MATCH SIMPLE` is equivalent and no row can present a partial
  key. `mt_devices` needed a CHECK only because its `site_id` *is* nullable.
  Adding either here would be inert **and would imply to a future reader that a
  NULL case exists**. If `mt_sites.customer_id` were ever relaxed, `MATCH FULL`
  would become necessary — that is the reason to record.
- **The migration fails closed by itself** — PostgreSQL validates against every
  existing row, and the whole file is one transaction. **No guard clause is
  needed and none should be added.** But its error names only **one** offending
  pair, so the census enumerates and the error merely diagnoses. **`NOT VALID`
  is available and NOT recommended** — it would declare the invariant without
  enforcing it.
- **Locks, measured per statement:** the UNIQUE takes **ACCESS EXCLUSIVE on
  `mt_services`** — the only read-blocking window. The FK takes **SHARE ROW
  EXCLUSIVE** on both, so **`mt_sites` readers are never blocked.** The index
  builds fine inside the migration transaction; only `CONCURRENTLY` cannot.
  **Duration is UNMEASURED — claim no production timing until E-2.** If E-2
  shows `mt_services` too large, the alternative needs a **migrator change**,
  which must not be invented pre-emptively.

**Matrix measured against the proposed schema**, as `dnb_app` under RLS, every
zero paired with a non-zero control in the same session: A→own service
`INSERT 0 1`; A→B's service **refused**; repointing an existing site **refused**;
A reads B's service `0`; B reads A's site `0`; B deleting a service its own sites
reference still refused by `mt_sites_service_id_fkey` — **unchanged and
correct**. **T-9 is mandatory**: the regression test must be shown to fail when
the constraint is removed.

### P-B is BREAKING — measured

`mt_auth_issue_code` resolves the tenant with a **non-`STRICT`**
`SELECT … INTO`, **no `ORDER BY`, no `LIMIT`**:

| Form | Two matching rows |
|---|---|
| `SELECT … INTO` | **first row, NO ERROR** |
| `SELECT … INTO STRICT` | raises `P0003` |

> Relaxing phone uniqueness would **silently bind a one-time code to an
> arbitrary principal, and therefore an arbitrary customer** — the code row
> stores `customer_id` and `mt_auth_verify_code` returns it, so the session's
> tenant would be non-deterministic. **The unique index is load-bearing for
> correctness, not lookup speed.**

Whether one person may legitimately hold two customers is **C10** (*"Is the
Reseller the same person as the Customer PWA user?"* — ARCH, *"Highest. Rebuilds
permission logic"*), with C6 and C16. **Not P-B's to settle.** Candidate
answers if ever needed: a second number, a principal-selection step after OTP, or
a login that names the customer first. **Do not change it.**

### P-C — recommended FORBIDDEN

`mt_auth_sessions` carries **its own `customer_id`**, and `mt_auth_resolve_token`
returns **`s.customer_id`** — the session's copy; the join to `mt_principals`
only checks `status`. **Reassigning a principal would leave every live session
serving the old tenant** until expiry — a cross-tenant window opened
administratively and invisible to everyone. With the already-measured `SET NULL`
attribution erasure: **no operation may change `mt_principals.customer_id`.**
Disable and create anew. If ever built, it must revoke every live session in the
same transaction.

### `mt_site_create` — derive, never accept

No `p_customer` parameter exists, so the forgery is **unrepresentable** rather
than rejected: authenticated customer → requested service → verify ownership →
**derive** `customer_id` → insert, with the composite FK as the floor beneath.
**An application check alone is not the fix** — integrity is evaluated *below*
RLS, an application check *above* it.

**S-A — UNIMPLEMENTED**, and forbidden-by-constraint once O-1 lands
(`ON UPDATE NO ACTION` refuses while a site references the service). If ever
wanted it is **administrative reassignment** — service, sites and devices in one
transaction with one audit trail. **I-A** needs a **non-tenant** store reachable
by `dnb_adminwrite`; four of five writers have no natural key at all. **U-1
refined, still open**: proposed first hard gate at `mt_service_create`, decided
together with U-5.

## Identity and onboarding decisions (`docs/107`)

The last identity/onboarding design checkpoint before the spine is implemented.
**P-B, P-C and S-A are CLOSED and listed under *Settled* above.** What follows is
what binds the work that comes next.

### O-1 — closed as a design, with TWO operational gates

```
GATE 1  census (read-only)  →  CLEAR | BLOCKED(n) | INDETERMINATE
                                    │  operator reviews; per-row decisions
GATE 2  migration (one transaction) →  applies, or refuses
```

**Do not combine them into one script.** A script that measures and then acts on
its own measurement gives the operator nothing to approve. Violating rows must be
**zero** before gate 2; it refuses by itself if they are not, but its error names
only **one** pair — the census is what enumerates.

### U-1 / U-5 — OPEN, reduced to one operator question

> **Q7 — when DishNet sets up a new service, does the uCRM service record always
> exist before the Domain-B service is created, or is the Domain-B service
> sometimes created first?**

- **A** — uCRM first → gate at **creation**, **no schema change**.
- **B/C** — sometimes Domain-B first → gate at **activation**, which needs a new
  `mt_services.status` value. The CHECK permits only `active · suspended ·
  ended`, so **that is a schema decision** — and the `UNCLAIMED` rule applies:
  do not add an enum value to express what a predicate could.

Ruled out by evidence and not to be revisited: gating at `mt_customer_create`,
gating **only** at `mt_device_assign`, and a blanket `NOT NULL` (U-2, still
**E-2**). **U-5 is decided WITH U-1**, because the coherence rule needs both
links.

### I-A — both existing mechanisms are tenant-scoped

Measured: `mt_idempotency` is keyed `(customer_id, key)`, and
`mt_intents_idem_uq` is `(customer_id, idempotency_key)` with a lookup that
**relies on RLS to scope itself** — `IntentQueue::enqueue` documents the
precondition (*"call inside `TenantContext::run()`"*). **Neither can serve an
operation that has no customer yet.** The spine needs a **non-tenant** store
reachable by `dnb_adminwrite`, keyed `(endpoint, key)` with a request digest.

**Three mechanisms across eight operations, deliberately:** a **unique
constraint** where a natural key exists (uCRM links, intents, principal-by-phone);
a **domain-specific invariant** for `mt_device_assign`, which is a state
assertion — identical arguments are a no-op with **no audit row and no
`claimed_at` re-stamp**, while different arguments are a *reassignment, not a
retry*; a **table** only where there is genuinely no natural key — customer,
service and site creation, and the NULL-phone principal. **A replay must not
write a second audit row**, so replay detection happens **before** the function
body, since W-1 makes the audit row unskippable inside it.

Also measured: of the three production `enqueue` call sites only
`voucher.publish` passes a key — **`voucher.revoke` and `session.disconnect`
pass none**. **Corrected by `docs/108`:** the clause that once followed here
("…that reaches a router") was an **overstatement and is withdrawn** — see the
`docs/108` section below.

### Convergence is `mt_device_assign` — and convergence is NOT a gate

The commercial chain (customer → service → site) and the network chain
(register → stage → ship) are **independent**; neither needs the other, and both
are identical in both journeys. **`mt_device_assign` is the only operation taking
both a device and a customer/site**, so it is where they meet — in customer-first
and equipment-first alike.

> **The two journeys are not two designs. They are one design, entered from
> either end.** That is why no placeholder customer is ever needed.

But `docs/102` measured that `service → site → plan → voucher` completes a sale
with nothing assigned, so `mt_device_assign` is **not** where commercial
authority is established. Do not conflate the two.

### The census must also measure the SCHEMA

Data counts alone cannot reveal that production is at a different migration
level. The census reports migrations applied and the latest filename
(development: **22**, `022_audit_write_boundary.sql`), the existing FK and
UNIQUE constraints on `mt_sites`/`mt_services`/`mt_devices`, current indexes, and
**whether migration 020 is applied** — if it is not, W-2 is absent too. Run as
`dnb_adminapi` through the `mt_admin_*()` projections. **No superuser, and no
`BYPASSRLS` role** — one created for a census would outlive it.

**RC1 must NOT be installed into the live UISP/uCRM or production environment.**
The production migration, the voucher activation path and the onboarding identity
model are each still short of their gates.

## Onboarding spine and intent idempotency (`docs/108`)

### A correction: nothing reaches a router today

Measured: **`bin/worker.php` — the only production construction of
`IntentWorker` — binds `NullDelivery`**, whose `deliver()` returns
`retryable('no delivery path is configured')` and whose `confirm()` returns
`false`. `RouterOsDelivery` is not referenced by `Runtime/Bindings.php` at all;
`Bindings::defaults()` is `NullDelivery` + `NullPublisher`, and a real binding
needs `DN_ALLOW_REAL_BINDINGS`. **The router consequence of a duplicate intent is
latent until F6-B.** `docs/107`'s "reaches a router" is withdrawn — an overstated
risk is as much a measurement failure as an understated one.

### The intent asymmetry is THREE-way, not two

| Operation | Key | Guard | Retry-safe today |
|---|---|---|---|
| `voucher.publish` | **yes** | — | **safe** — `enqueue` returns the first intent |
| `voucher.revoke` | no | **yes — a state guard in the SQL** | **safe, incidentally** |
| `session.disconnect` | no | **none** | **NOT safe** — duplicate intent **and** duplicate audit row |

`VoucherService::revoke` is
`UPDATE … WHERE id = ? AND state IN ('unused','active') RETURNING *`, and the
handler returns **404 before reaching the enqueue** when it yields null. So the
replay is stopped — **but by where the guard sits, not by design**: the replay
answers a misleading 404, and any future edit reordering those statements would
silently remove the safety. `session.disconnect` only does a `find()` read
first, so every retry proceeds.

> **`session.disconnect` replay is a NEW BLOCKER — it must be fixed before
> F6-B.** Today its cost is a duplicate audit row, not a duplicated router
> action.

### RULE I-1 — detection before the audited mutation

> **Idempotency detection must occur BEFORE the mutating, audited operation
> executes. A replay must not create a second audit event.**

Forced by W-1, not chosen: the audit row is written by `mt_audit_write()` inside
the function, in the same transaction, so a replay check placed after the call —
or inside it after the mutation — cannot prevent the duplicate. The check is the
**first** thing the function does, and a replay returns the stored result while
writing **nothing**. `mt_audit_log` is append-only by trigger, so **a duplicate
audit row is a false record that cannot be corrected afterwards.**

### Where the existing mechanisms suffice — and where they cannot

**Seven of the eight onboarding operations have NO tenant context at execution**;
only intent enqueue does, and it already has its mechanism. So:

- **unique constraint, no table needed** — the uCRM customer link
  (`ucrm_client_id` is already UNIQUE), the uCRM service link (U-5, column does
  not exist), intents, and principal-creation's common case (`phone`);
- **domain-specific invariant** — `mt_device_assign`, a state assertion;
- **a non-tenant table, keyed `(endpoint, key)` with a request digest** — the
  four with no natural key: customer, service and site creation, and the
  NULL-phone principal.

A unique constraint is **stronger** than a table here, because it cannot be
bypassed by a caller that omits the key.

### Q7 was searched for and NOT answered

The sibling plugin shows `lte_subscribers` carrying **no uCRM column at all**,
with the relationship in a separate `lte_service_links` table
(`UNIQUE(lte_subscriber_id, ucrm_client_id)`, `linked_by`, `linked_at`) — so the
local entity **can exist unlinked**, and linking is a separate, later, attributed
act. **But that table links a uCRM *client*, not a *service*, despite its name**,
and it is the Sudan LTE product, not Uganda MikroTik.

> **It raises the prior for "sometimes Domain-B first" — it does not answer Q7.**
> Reading a sales workflow off a table definition in another product line is
> exactly the inference this project forbids. **U-1 and U-5 stay OPEN**, and Q7
> is an operator question.

### Writer order — Q7 is the critical path, not O-1

`0a` O-1 (census → decision → migration) · **`0b` the non-tenant idempotency
store — before the first writer, not after**, because a duplicate customer
cannot be deleted (18 `ON DELETE RESTRICT` FKs) · `1` principal create/disable ·
`2` `mt_service_create` · `3` `mt_site_create` · `4` a caller for
`mt_customer_create` · `5` the uCRM link writers · `6` the intent replay fix.

> **Step 2 cannot begin until Q7 is answered**, because the answer decides
> whether the gate lives in the function or in a service state that does not yet
> exist. **That makes Q7 the critical path.**

## Q7 — searched for, NOT answered (`docs/109`)

Operational sources were searched, not schema. Five findings, all measured:

- **The deployed Uganda sales assistant does not sell HotSpot.** Its own system
  prompt: *"We sell **Starlink** — kits and monthly internet plans… We do NOT
  sell fiber, and we do NOT sell SIM cards."*
- **No HotSpot or MikroTik revenue path exists in the live stack.** The only
  `MikroTik` occurrence is a vendor name in a hardware-advice list
  (`HardwareKnowledge.php:145`).
- **No uCRM service plan names HotSpot or MikroTik** anywhere in the repository.
- **`hotspot.html` is a lead-generation page, not a product** — vouchers
  mentioned, three *contact* calls-to-action, **no price of any kind**.
- **The site README records its own uncertainty:** *"If any of these is not
  actually sold in Uganda yet, remove the page… an advertised service nobody can
  buy costs trust."*

> **Q7 is therefore not an archaeological question.** There is no onboarding
> history to recover: the product is at the enquiry stage and the platform that
> would onboard an operator has never run. Asking *"what do you currently do?"*
> presumes a practice that may not exist.

**Reduced to one lookup the operator can do in a single uCRM screen:** *does a
HotSpot / MikroTik / WiFi-zone service plan exist, and does any client hold a
service on it?* No plan ⇒ uCRM has nothing from which a HotSpot service could be
created, so Domain-B-first is forced for the first operator — **a decision to
ratify, not a fact to discover**. A plan with clients ⇒ the practice exists and
Q7 is answered from those records.

**U-1 and U-5 remain OPEN.** Not closed, not narrowed by assumption. **U-5's
premise may be empty** — if no uCRM HotSpot service plan exists, the service link
has nothing to reference yet. Recorded, not concluded: the repository is not
uCRM's database.

### A divergence flagged, not resolved

`docs/107` §9.2 proposed that voucher issuance require the uCRM customer link.
That diverges from the stated boundary that uCRM must not be a prerequisite of
`voucher issuance → redemption → AAA publication → RADIUS → session →
accounting`. The distinction that may dissolve it:

| | |
|---|---|
| **runtime dependency** — calling uCRM during the operation | **FORBIDDEN**, already settled (`docs/102`) for redemption and accounting |
| **stored-link precondition** — reading a local column | no network call, no latency, no availability coupling — **but still a prerequisite in effect** |

**Whether that is wanted is a business decision.** Flagged rather than silently
resolved either way. Redemption onward is not in question.

### The three identities

**Domain-B customer/operator** (the HotSpot platform customer) · **uCRM
customer/service** (the commercial relationship, where one applies) · **guest /
voucher user** (transient, **never requires uCRM**, no `mt_customers` row, no
principal, no actor kind — `docs/89`; **no `guest` actor kind is to be added**).

> **`mt_customers` existing is not a reason to make uCRM mandatory.** It is the
> Domain-B **authorization boundary** — what RLS keys on — carrying a uCRM
> relationship when one exists. It is not a projection of uCRM.

## Domain B is standalone — the boundary is FROZEN (`docs/110`)

### Withdrawn

**`docs/105` §9.2 and `docs/106` §9 are WITHDRAWN.** A stored uCRM link is **not**
a prerequisite for plan creation or voucher issuance. The error was treating
**commercial representation** and **technical capability** as one requirement: a
voucher sale is revenue to the **operator**, and becomes DishNet revenue only
where DishNet bills that operator commercially.

### The boundary is MEASURED, not designed

- **Zero** non-column uCRM references in Domain-B `src/` — no client, no adapter,
  no API call. `ucrm_client_id`'s only writer is still `tests/bootstrap.php`.
- The **only** outbound HTTP client in Domain B is
  `Delivery/RouterOs/RestClient.php` — to a **router**, not to uCRM, and inert.
- `Projection.php` already withholds the field from customers: *"`ucrm_client_id`
  is internal billing linkage."*

> **Therefore the standalone boundary requires NO migration.** `ucrm_client_id`
> is already nullable. The one decision that would break it is **U-2**
> (`NOT NULL`), still gated on **E-2**.

### Nothing on this list needs uCRM

`Domain-B customer → principal → service → site → router register/stage/ship →
assign → provisioning → plan → voucher batch → voucher → guest redemption → AAA
→ RADIUS → session → accounting`. Both journeys complete end to end with **no
uCRM record in existence**.

**"Optional" does NOT mean "uCRM is never used."** The bridge (R-1) remains the
architecture for every operator DishNet manages commercially. What is rejected is
uCRM as a **technical dependency of HotSpot operation**.

### uCRM is NOT in the real-time path

```
guest code → redemption → AAA publication → RADIUS auth → session → accounting
                  └── uCRM appears NOWHERE on this line ──┘
```

Not a performance preference — a **security** requirement: anti-enumeration
(tenant data must not be *required*, since an unknown code resolves no
customer), **response uniformity is not tradeable**, availability (a CRM outage
must never stop a paying guest), and privilege (`dnb_portal` and `dnb_radius`
hold no table privileges and one EXECUTE each). **Putting a uCRM call on this
line is a regression, not a feature.**

### Where uCRM enters — exactly one point

**An audited link recorded against an existing Domain-B operator**, written once
with provenance, `dnb_adminwrite` only, never inferred per request and **never
from a phone number**. It sits *beside* the lifecycle, not inside it.

### If uCRM is unavailable

**Every HotSpot operation continues** — Domain B cannot call uCRM, so there is
nothing to fail. **Billing, invoicing, dunning and support stop** (they exist
only in uCRM; `tickets` may not even be API-reachable). And one consequence to
accept knowingly: under the proposed bridge (U-7 S-1/S-2) **staff authentication
into the Admin panel depends on uCRM**, so a uCRM outage removes Admin write
access while the network plane and every guest transaction keep running.

### The three identities

**Domain-B operator** (the RLS authorization boundary) · **uCRM commercial
customer/service** (where DishNet bills that operator) · **guest** (transient;
**never** a CRM record of any kind — not a client, not a lead, not a contact; no
`mt_customers` row, no principal, **no `guest` actor kind**).

### U-1 restated, U-5 deferred

> **U-1 — when, and under what commercial circumstances, does a Domain-B
> operator get linked to uCRM?**

A **commercial/integration decision**, not a technical dependency. The previous
framing — *which operation first requires the link* — presumed the answer now
withdrawn. **Q7 must not be turned into a technical prerequisite for Domain-B
onboarding.** **U-5 is deferred** until the bridge actually needs it.

**Sequence: freeze the boundary → define the bridge → design the columns →
implement.** Nothing in `mt_customers`, `mt_services` or the spine changes at
step 1 — designing columns before the bridge was defined is what produced the
withdrawn proposal.

## The uCRM bridge — designed, NOT authorized (`docs/111`)

**APIs only, never Domain-B PostgreSQL.** The bridge holds no Domain-B role
credential, writes no Domain-B table, calls no provisioning function, and is
**never a dependency of guest redemption** — `dnb_portal` and `dnb_radius` hold
no table privileges and one EXECUTE each, so neither could reach it.

### The measured surface

`plugin/plugin.json` declares — and a test asserts equal to what is served —
**15 GET routes**, `"surface": "read-only"`, and **7 declared-unbound POSTs that
each answer 501** (routers, assign, actions, sites, plans, voucher-batches,
session disconnect).

- **N-1 — the bridge's two required endpoints do not exist**, not even as 501s:
  there is **no route to create a Domain-B operator and none to link one to a
  uCRM client**. **The bridge therefore depends on the onboarding spine**, not
  the reverse.
- **N-2 — a bridge could not authenticate today.** `DenyAllIdentity` answers
  **401 on every route**; only `DN_DEV_STAFF_IDENTITY` renders the panel. W-4.
- **N-3 — if S-2 (signed assertion) is chosen, its signing key is a new secret**
  and inherits every B-1 lesson: not in source control, not in a migration,
  provisioned at install, rotatable.

### What the bridge can never reach — measured, two layers

> A query for `dnb_def_admin` `USING(true)` policies across
> `mt_device_secrets`, `mt_hotspot_users`, `mt_auth_sessions` and `mt_auth_codes`
> returns **NONE**. **The Admin read boundary structurally cannot reach a
> secret-bearing table** — the projection omitting a column is the *second*
> layer, not the first.

`mt_admin_router()` returns **no `wg_pubkey`** — even the *public* key is
withheld — and no secret column. `code` is absent from **both** voucher
projections, whose column lists are identical so a detail view cannot leak one
row at a time. RADIUS credentials live in a **separate PostgreSQL instance** the
Admin API has no connection to.

### Write surface — at most two operations

`POST /customers/{id}/ucrm-link` (link · relink · unlink) and, **only if the
bridge is to onboard from uCRM at all**, `POST /customers`. Both
`dnb_adminwrite`, definer functions, W-1 audit, actor a parameter. **The bridge
must not be given the other five POSTs** — router registration, assignment,
actions, sites, plans, voucher batches and disconnect are **network** operations.
**A CRM plugin has no business rebooting a router.**

### Cardinality is OPEN — and it cuts both ways

**`mt_customers.ucrm_client_id` is `integer UNIQUE`, so the schema already
enforces 1:1** — anything else is a **schema change**. Against assuming it: the
working precedent is a **pair-unique link table** chosen over a column
deliberately, and many-to-many has consequences for RLS and `/me` nothing has
examined. **Decide C10 before cardinality, not after** — they are entangled.
`Domain-B service ↔ uCRM service` has **no column at all** and its far side is
not known to exist, so **U-5 stays deferred**.

### Staff identity

The uCRM cookie is trustworthy **only server-side in the plugin**, where
`/current-user` answered it, and **only same-origin**. **Domain B must never
accept a staff identity from a browser.** The actor is **`staff`** — no new actor
kind — arriving as a **parameter from the identity boundary**, per W-1. And:
**being logged into uCRM establishes *who*, never *what they may do in Domain
B*** — uCRM's permission model is not Domain B's authorization. W-4/W-5/W-6 stay
open.

### When uCRM is unavailable

**Fail closed on identity, fail soft on data.** If `/current-user` is
unreachable the bridge must **refuse to assert an identity** — never a cached or
assumed one. A failed commercial read renders **"not available"**, never a zero
that reads like a fact. **No queue of pending commercial writes that later
auto-apply** — a link is an audited act, and replaying it with a stale actor
would misattribute it. **A uCRM outage must never widen Domain-B authorization.**

### The one legitimate live dependency

The **coherence check** — the uCRM service's `clientId` must equal the client
linked to that service's customer — is the single place a Domain-B write depends
on a live uCRM read. It is an **administrative** operation, never on the
operating path, and no FK can express it.

## The onboarding spine — final design (`docs/112`)

All nine operations specified. **Nothing authorized to build.**

### A-1 — the audit trail is forgeable, and it extends `docs/84` F-3

F-3 already records that migration 015 grants `dnb_admin`
`SELECT, INSERT, UPDATE, DELETE` on **all tables**, with `ALTER DEFAULT
PRIVILEGES` so new tables inherit it — but states it as a hypothetical. **Proved
by execution as `dnb_admin` under RLS, rolled back, with a non-zero control:**

| | | |
|---|---|---|
| **A-1a** | `INSERT INTO mt_audit_log …` | **`INSERT 0 1`** |
| **A-1b** | `INSERT INTO mt_customers …` | **refused by RLS `WITH CHECK`** |
| **A-1c** | `dnb_worker` `mt_audit_log` INSERT | **true** — line 73 grants **both**; F-3 names only `dnb_admin` |

- **A forged audit row cannot be removed** — `mt_audit_log` is append-only by
  trigger. That is worse than a forgeable business write, which can be reversed.
- **RLS still bounds it to the caller's own tenant** (A-1b), so this is an
  **attribution/integrity** problem, **not** a tenancy breach. Do not overstate
  it.
- **Latent**: no production route connects as `dnb_admin`, and `Database`
  documents `adminApi()`/`adminWrite()` as *"separate from `admin()` on
  purpose"*.

> **Binding: none of the nine spine functions may be EXECUTE-able by
> `dnb_admin`, and no new route may connect as it.** W-1's *"no HTTP role may
> write an audit row directly"* is true of `dnb_adminwrite` and `dnb_adminapi`
> — **not of `dnb_admin` or `dnb_worker`.** Revoking the blanket grant is F-3's
> own remediation, out of scope here, but **A-1 raises its priority** because the
> forgeable target is the audit trail.

**Also: a security-critical comment names the wrong role.**
`src/Api/AdminRoutes.php:103` says *"dnb_admin holds EXECUTE and no table
privilege whatsoever"* — true of **`dnb_adminapi`**, which the route actually
uses, and **false of the role it names**. The behaviour is correct; the comment
is wrong.

### Required vs optional — settled

| Step | Required for |
|---|---|
| customer | everything |
| **principal** | **a login only** — an operator managed entirely by DishNet staff needs none |
| service | a site |
| site | a voucher (2b) and a router's `site_id` |
| **device** | **nothing in the commercial chain** |
| **uCRM link** | **nothing** |

### Per-writer notes that bind implementation

- **`mt_customer_create` exists and is audited — what is missing is the caller.**
  No new signature is needed; `docs/110` withdrew the link-at-creation idea.
- **Principal creation is the highest-risk writer** — the only operation granting
  a human a login. A duplicate phone is a **REFUSAL** (P-B); it may **store**
  `kind` but **not branch on it** (C6); there is **no reassignment** (P-C).
  Disable **does** close live sessions, because `p.status` is re-read on every
  resolution.
- **`mt_service_create` has no natural key whatsoever** — `customer_id` plus a
  `kind` with one legal value. The worst of the nine; a caller-supplied key is
  mandatory.
- **`mt_site_create` takes no `p_customer`** — derive, never accept — and is
  **blocked on O-1**.
- **`mt_device_assign` idempotency is a domain invariant, not a table**, and it
  returns **NULL** rather than raising for a missing device — a retry handler
  must not read that as success.
- **uCRM link cardinality is OPEN** — `ucrm_client_id UNIQUE` already enforces
  1:1, so **decide C10 first**. **U-5 deferred**: no column, and the far side is
  unevidenced.

### Idempotency classes

**Non-tenant table** (customer, service, site, NULL-phone principal — the four
with no natural key) · **existing UNIQUE** (principal-by-phone, uCRM link,
intents — stronger, because a caller cannot bypass it by omitting the key) ·
**domain invariant** (device assign). Conflict is uniform: same key, different
digest → **refuse**. **`session.disconnect` deliberately NOT fixed here**; it
remains a blocker before F6-B.

### Customer-facing vs staff-only

**None of the nine is customer-facing.** The customer plane keeps exactly what it
has — plans, vouchers, sessions, `/me`. **B-2** is separate work.

## A-1 audit integrity — remediation designed (`docs/113`)

Every login role was **execution-tested** with a connection control, so no
refusal below is a false negative.

| Role | direct INSERT | UPDATE | DELETE | Required final |
|---|---|---|---|---|
| `dnb_admin` | **ALLOWED** | refused | refused | **NONE** |
| `dnb_app` | **ALLOWED** | refused | refused | **NONE**, after F-8 |
| `dnb_worker` | **ALLOWED** | refused | refused | **NONE**, via a definer |
| `dnb_adminapi` · `dnb_adminwrite` · `dnb_radius` | refused | refused | refused | already correct |
| `dnb_def_audit` | owns `mt_audit_write` | no | no | **the only writer** |

- **THREE roles can forge, not two.** `docs/112` named `dnb_admin` and
  `dnb_worker`; **`dnb_app` is the third, and it is the customer-facing HTTP
  role** — it writes six audit sites today (F-8), so it **cannot simply be
  revoked**.
- **UPDATE and DELETE are refused for every role, including those holding the
  grant.** The append-only trigger holds universally, so the exposure is
  **forgery only — never tampering or erasure**.

### The diagnosis

Migration 015's blanket grant rested on its own stated reasoning: *"Each is
subject to RLS on every table; the difference between them is only which
SECURITY DEFINER functions they may call."* That **holds for business tables**
and **fails for `mt_audit_log`**, because **RLS constrains which tenant a row
belongs to — not whether the row is true.** A forged row naming another actor
satisfies `customer_id = mt_current_customer()` perfectly.

> **A-1 is not an error in migration 015's logic; it is a table to which that
> logic does not apply.**

### Proven safe to remediate

Run as `dnb_adminwrite`: it **cannot INSERT** `mt_audit_log` (*permission
denied*) and **cannot even SELECT** it (no EXECUTE on `mt_current_customer`, so
it cannot evaluate the policy) — yet `mt_customer_create(…)` **succeeds and
writes 1 audit row**. **The eight functions depend on `dnb_def_audit`'s
privilege, never the caller's**, so revoking direct INSERT cannot break them.

### Three tiers

| | Role | Action | New objects | Breaks |
|---|---|---|---|---|
| **T1** | `dnb_admin` | `REVOKE` | **none — no schema change** | the **simulator only** |
| **T2** | `dnb_worker` | one definer for `intent.confirmed`/`.failed`, then revoke | one function | nothing |
| **T3** | `dnb_app` | the **F-8 remediation**, then revoke | six sites | the customer API until done |

- **`dnb_admin` has no production caller.** The only caller of
  `Database::admin()` is **`Simulator.php`**, excluded from the package. So T1
  breaks the simulator and nothing else — and the simulator builds its estate
  *through the real write paths* deliberately, so **do not silently break it**:
  point it at the owner role or give it a development-only identity.
- **`dnb_worker` genuinely needs `mt_intents`** (the claim/lease `UPDATE`), so it
  cannot go to zero privileges like `dnb_adminwrite`. Only its audit write moves.
- **A revoke that leaves `ALTER DEFAULT PRIVILEGES` in place is a fix that
  expires** — future tables would silently re-acquire the grant.

### Binding on the spine

**No spine function may be granted EXECUTE to `dnb_admin`, `dnb_worker` or
`dnb_app`** while each can independently forge an audit row. An unskippable
audit row gains nothing if a forged one can sit beside it. **T1 should precede
the first spine writer** — it costs a `REVOKE`.

**Also found: `dnb_plain`**, a role in the development cluster that **nothing in
the repository creates**. Not one of the twelve. **The production census must
enumerate roles**, not assume them.

## A-1 and B-2 are CLOSED in development (migrations 022–025)

**The first remediation shipped in code.** `migrations/022_audit_write_boundary.sql`
plus `023_worker_audit_boundary.sql`, `024_commercial_write_boundary.sql`,
`tests/test_audit_boundary.php` and `tests/test_commercial_boundary.php`.
Suite: **29 suites, 1,763 assertions, 0 failed** (1,599 → 1,617 after T1 →
1,641 after T2 → 1,740 after T3 → 1,763 after the B-2 grant closure).

> **NO LOGIN ROLE CAN WRITE AN AUDIT ROW.** `dnb_admin` (022), `dnb_worker`
> (023) and `dnb_app` (024) are all revoked, each with its matching
> `ALTER DEFAULT PRIVILEGES`. The only writer is `mt_audit_write()`, owned by
> `dnb_def_audit` and callable only by the definer roles that own audited
> mutations. **Production application is still a separate gate behind the
> census.**

```sql
REVOKE INSERT ON mt_audit_log FROM dnb_admin;
ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE INSERT ON TABLES FROM dnb_admin;
```

### Scope: dnb_admin ONLY, and the reason is measured

`docs/113` listed three forging roles. **Only `dnb_admin` could be revoked
without breaking a live path**, and this was established before writing the
migration:

| Role | Direct audit writer | Revoking today |
|---|---|---|
| `dnb_admin` | only caller is `Simulator.php`, which **only SELECTs** `mt_audit_log` | **breaks nothing** |
| `dnb_app` | `public/index.php` → `Database::app()`; `AuditLog::record` INSERTs at **six** `Routes.php` sites | **breaks the customer API** |
| `dnb_worker` | `bin/worker.php` → `Database::worker()`; `IntentWorker` audits at lines 63/77 | **breaks the worker** |

> **`docs/113` predicted T1 would break the simulator. It does not.** That
> prediction was about revoking the *whole* blanket grant; a **targeted** revoke
> of audit INSERT breaks nothing, because the simulator's own comment is
> accurate — its audit rows are *"written by those acts, not inserted"*.

**T2 is now DONE and T3 is reported blocked** — see below. The table above is
kept because it is what was measured before 022; `dnb_worker`'s row is closed by
023, and `dnb_app`'s row is the reason T3 stops.

### T2 — migration 023, `mt_intent_audit()`

`dnb_def_work` **already owns the intent lifecycle** (`mt_intent_claim`,
`mt_intent_expire_overdue`) and its own `mt_intents` policies, so the audit
write went where the lifecycle already lives rather than into a boundary
invented for it. `IntentWorker`'s two sites now call
`SELECT mt_intent_audit(intent, worker, outcome[, reason])`, then:

```sql
REVOKE INSERT ON mt_audit_log FROM dnb_worker;
ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE INSERT ON TABLES FROM dnb_worker;
```

**What the caller can no longer choose.** Under the direct INSERT the worker
supplied `customer_id`, `actor_kind` and the action string itself. Through the
function: `customer_id` is **DERIVED from the intent row**, `actor_kind` is
**fixed to `system`**, `action` is **constrained to two values**, and the target
is the intent by construction. Only `p_worker` remains the worker's to supply —
which is exactly the W-1 shape, the actor as a parameter from the identity
boundary. `dnb_worker` **keeps `mt_intents` UPDATE**: the claim/lease is not
audit, and a test asserts it still runs.

### T3 — BUILT: migration 024, six commercial boundaries

The measurement that shaped it: `dnb_app` could EXECUTE exactly six
`SECURITY DEFINER` functions and **not one performed any of the six audited
mutations** (five `mt_auth_*`, plus `mt_voucher_redeem`, which has no caller
and is to be deleted). There was no boundary to move the audit into, so one
was built per mutation.

| Function | Route | Audit action |
|---|---|---|
| `mt_plan_create` | `POST /me/plans` | `plan.created` |
| `mt_plan_update` | `PATCH /me/plans/{id}` | `plan.updated` |
| `mt_plan_retire` | `POST /me/plans/{id}/retire` | `plan.retired` |
| `mt_voucher_batch_issue` | `POST /me/vouchers` | `voucher.issued` |
| `mt_voucher_revoke` | `POST /me/vouchers/{id}/revoke` | `voucher.revoked` |
| `mt_session_disconnect_request` | `POST /me/sessions/{id}/disconnect` | `session.disconnect_requested` |

**`dnb_def_comm` is a NEW definer role, and the reason is measured.** Every
existing definer role already carries a widening `USING (true)` policy on
exactly the table these functions must write — `dnb_def_net` on `mt_vouchers`
and `mt_hotspot_users`, `dnb_def_work` on `mt_intents`, `dnb_def_admin` on all
of them. Owning the commercial writers with any of those would have **removed**
the tenant isolation that makes them safe.

> **Tenancy is NOT enforced by the function bodies.** `dnb_def_comm` was
> created with **no policy of its own**, so it inherits
> `<table>_isolation FOR ALL TO public` and is bound by
> `customer_id = mt_current_customer()` exactly as `dnb_app` is. **Proved by
> execution:** adding a widening policy for `dnb_def_comm` breaks **11**
> assertions; removing it restores all 89.

- **There is no `p_customer` parameter in any of the six.** The customer is
  `mt_current_customer()`, so a forged one is **unrepresentable** rather than
  rejected — the `mt_site_create` contract from `docs/105`.
- **The actor is a parameter**, per W-1, and is verified to be a principal of
  *this* customer by a SELECT that RLS has already scoped.
- **`src/Audit/AuditLog.php` was DELETED.** After the revoke it could only
  fail; `test_audit.php` now writes its row through a real boundary.
- **Behaviour was reproduced, not redesigned.** The `mt_hotspot_users` row is
  still written at issue with the reversible `radius_username` (the `docs/86`
  defects, carried over verbatim — Decision 1 stays Model B and **no AAA
  publication was reintroduced**); a NULL update field still means *unchanged*,
  so a data cap still cannot be cleared; `plan.retire` still has no state
  guard; an idempotency key still deduplicates only the **intent**.
- **Voucher codes are still drawn from the injectable `CodeSource`** and passed
  in with spares; the function skips any the unique index rejects. Eager rather
  than lazy drawing is the one mechanical change, and the outcome is identical.

> **`session.disconnect` replay is deliberately NOT fixed, and the suite
> asserts the gap** — a replay still enqueues a second intent and writes a
> second audit row. T3 gave it a boundary, not replay safety. It remains the
> blocker `docs/108` records for F6-B.

### B-2's other half — CLOSED: migration 025

T3 closed audit **forgery**. It did not close **unaudited mutation**: migration
006 line 47 granted `dnb_app` `SELECT, INSERT, UPDATE, DELETE` on **all
tables**, so the application could still bypass the six functions and write a
business table with no audit row at all.

**Every `dnb_app` write path was inventoried from the code before anything was
revoked**, and every one had already been replaced by 024:

| Table | Former `dnb_app` writer | Replaced by |
|---|---|---|
| `mt_plans` | `PlanRepository::create/update/retire` | the three `mt_plan_*` |
| `mt_vouchers` | `VoucherService::issueBatch/revoke` | `mt_voucher_batch_issue`, `mt_voucher_revoke` |
| `mt_voucher_batches` | `VoucherService::issueBatch` | `mt_voucher_batch_issue` |
| `mt_hotspot_users` | `VoucherService::issueBatch` | `mt_voucher_batch_issue` |
| `mt_intents` | three `enqueue` call sites | all three functions |
| `mt_profiles` | `ProfileResolver` | `mt_profile_resolve` |

**`dnb_app` is now READ-ONLY on all six.** `SELECT` is deliberately kept —
`PlanRepository::all/find`, `VoucherService::list/find` and
`IntentQueue::forCustomer/find` are ordinary RLS-scoped reads and are not what
B-2 is about. `dnb_worker` keeps `mt_intents` UPDATE: the claim/lease and the
`mark*` transitions are its own, not `dnb_app`'s.

- **`src/Policy/ProfileResolver.php` was DELETED**, like `AuditLog` before it —
  the function owns profile resolution now, and two implementations of one
  dedup rule can only drift.
- **The default privilege was closed too.** 006 line 50 set `ALTER DEFAULT
  PRIVILEGES` for `dnb_app`; 024 took `INSERT` out of it and 025 takes `UPDATE`
  and `DELETE`. **`SELECT` stays** — a future table that genuinely needs a
  `dnb_app` write must say so in its own migration, which is the point.
- **The simulator's one `dnb_app` enqueue moved to the admin connection.** A
  device provisioning job is a *network*-plane act; when the Admin route that
  raises it is finally bound it will be `dnb_adminwrite`, never the customer
  role. The row written is identical.

### The tests were refactored, not the privileges preserved

**No test manufactures state as `dnb_app` any more.** 32 fixture writes moved to
`Database::inspector()` — the documented **test fixture identity** (`postgres`),
which already exists for exactly this and is excluded from the package.

> **Two traps were hit and recorded.** The read-isolation assertions in
> `test_intents.php` were briefly moved to the fixture identity too — which is
> `BYPASSRLS`, so they would have become **vacuous**. They are back on
> `dnb_app`, where the isolation under test actually lives. And the
> default-privilege probe first created its table as `postgres` and read `0`
> for everything: `ALTER DEFAULT PRIVILEGES` is recorded **per granting role**,
> so the probe must create the table as the **owner** that set it.

The two no-delete trigger tests are now **two assertions, not one**: the
fixture identity first, so the refusal is demonstrably the **trigger**; then
`dnb_app`, which since 025 cannot reach the trigger at all. Neither stands in
for the other.

> **Proved substantive, not assumed.** Widening every `*_isolation` policy to
> `USING (true)` breaks **84 assertions across five suites** —
> `test_rls_isolation` 43, `test_commercial_boundary` 18, `test_api_me` 11,
> `test_isolation_s1_s2` 9, `test_intents` 3. The refactor did not hollow the
> isolation tests out.

**Still open, and asserted:** `dnb_app` retains `UPDATE`/`DELETE` **grants** on
`mt_audit_log`, both refused by the append-only trigger for every role. Left
alone deliberately — it is the clearest demonstration in the schema that **a
grant was never the boundary**.

### The default privilege was the half that would have expired

015 also set `ALTER DEFAULT PRIVILEGES`, so the **next table created** would
have handed `dnb_admin` INSERT again — and the attempt store (`docs/89`) and the
non-tenant idempotency store (`docs/108`) are both still to be created. A test
creates a table and asserts `dnb_admin` gets nothing on it.

### A-2 — the residue is asserted, not hidden

**The residue is now empty, and that is asserted too.** The mechanism worked
twice: the lines written in 022 to fail when T2 landed did fail, and the lines
written in 023 to fail when T3 landed did fail. Each was rewritten to the new
truth rather than deleted. What the suite now pins is the **closed** state —
no login role holds audit INSERT, no `AuditLog` class exists, a newly created
table grants `dnb_app` no INSERT — each paired with a control (`dnb_app` can
still SELECT the audit log and the new table; `dnb_def_audit` still holds the
INSERT that it should).

### Controls

Every negative is paired with a positive. `dnb_adminwrite` holds **no** audit
privilege yet `mt_customer_create` writes exactly **one** audit row through
`dnb_def_audit`. Append-only still refuses UPDATE and DELETE for every role.
Roles are **enumerated from `pg_roles`**, never from a hardcoded list.
**Control on the control, proved separately:** with the revoke in place the
insert is *permission denied*; reverting the grant in the same transaction makes
the identical statement return **`INSERT 0 1`**, then rolled back — so the
assertion has real subject matter.

> **A-1 and B-2 are closed IN DEVELOPMENT ONLY.** No login role can write an
> audit row, and `dnb_app` cannot mutate a commercial table at all.
> **Production application is a separate gate and the production census still
> comes first.** `session.disconnect` replay remains open, and is asserted in
> the suite rather than assumed.

## O-1 acceptance — SYNTHETIC, and it overturned a documented claim

`tools/audit/o1_acceptance.php` builds a throwaway `dnb_o1acc`, seeds an
`O1FIX-` prefixed estate, runs the **exact** `production_census.sql` against
it, and drops the database. **52 assertions, and NONE of it is production
evidence** — production remains **CENSUS NOT OBTAINED**.

### The finding: the O-1 migration does NOT fail closed

> **`docs/106`'s "the migration fails closed by itself… No guard clause is
> needed and none should be added" is WITHDRAWN.** It is true only for a role
> that can see every row. Proved by execution against a violating estate:

| Run as | Result |
|---|---|
| the owner `dnb`, no guard | **`ALTER TABLE` / `COMMIT`, no error** — constraint added and marked **`convalidated = true`** with the violating row still underneath |
| a role that sees every row | **refuses**, names `mt_sites_service_customer_fkey` and the offending pair, rolls back, **0 constraints** |
| the owner `dnb`, **with the guard** | **refuses** — *query would be affected by row-level security policy* — **0 constraints** |

The cause: since migration 017 (F2) the owner is **not** a superuser and
`mt_sites` has **FORCE RLS**, so with no tenant context it sees **zero rows**
and the FK validation scan finds nothing to object to. The first outcome is
the dangerous one — **the invariant is asserted but not true, and nothing
would ever re-check it.**

**The fix is one line**, now in `tools/audit/o1_composite_fk.sql`:
`SET LOCAL row_security = off;`.

> **Do not describe this as "bypassing RLS".** The property it buys is narrower
> and is the one that matters: **the migration refuses to proceed when its own
> validation query would be affected by row-level security**, instead of
> validating against whatever subset happened to be visible. Under FORCE RLS
> that setting makes such a query **error** rather than silently return fewer
> rows. The migration fails closed; it gains sight of nothing.

> **This raises the census from useful to load-bearing.** The migration cannot
> be relied on to catch a violation, and its error names only **one** pair
> anyway. **The census is the only thing that enumerates.**

### The candidate DDL is NOT a migration

`tools/audit/o1_composite_fk.sql`, deliberately **not** in `migrations/`:
putting it there would apply it on every install, which is precisely the
authorisation that has not been given. `tools/` is also excluded from the
package.

### Five of the fourteen anomalies CANNOT EXIST at migration level 25

Measured: orphan `customer_id`, orphan `service_id`, NULL `service_id`,
device customer/site mismatch and duplicate `tunnel_ip` are each already
refused by an existing constraint. The harness proves the **current schema
refuses each one first**, then drops that single guard in its own throwaway
database to prove the census would still **detect** it at an earlier migration
level, then restores it. That is instrument testing, not a claim that such
rows are reachable.

**What IS representable today:** the O-1 cross-customer site→service (two
independent single-column FKs that nothing requires to agree), a service
reached from two customers' sites, a voucher with NULL `site_id`, and a
decommissioned device still sited.

### Census corrections made by running it

- **`BLOCKED(n)` counted detectors, not rows.** One bad row reported
  `BLOCKED(3)`. `n` is now the distinct offending row count from the
  authoritative SECTION 4 check; the other detectors stay as diagnostics.
- A test tool must not invent configuration. `test_installability.php` sweeps
  `tools/` and refused two undeclared env vars the harness had introduced;
  host and port now come from the declared `DNB_DSN`. **The guard was right;
  the tool was wrong.**

Suite **29 suites / 1,763 assertions / 0 failed**, unchanged by this work.
Acceptance harness **52 assertions**, stable over two runs, leaving **zero
residue** — no synthetic database and no `O1FIX-` row anywhere.

### Three kinds of O-1 evidence — do not let them blur

| | Status | What it can and cannot support |
|---|---|---|
| **Synthetic validation** | **✅ obtained** | proves the census DETECTS each anomaly and the guarded migration fails closed. Proves **nothing** about the DishNet estate |
| **Production census** | **❌ NOT OBTAINED** | the only thing that can say whether production holds zero, one or many violations, or legacy rows needing repair |
| **Migration authorisation** | **❌ NOT GIVEN** | a separate operator decision. **A `CLEAR` census authorises nothing by itself** |

```
synthetic instrument   OK        production census       NOT OBTAINED
synthetic detection    OK        production O-1 migration NOT APPLIED
synthetic safety       OK
```

**The migration is a SEPARATE act from the census**, and `census → looks clean
→ ALTER TABLE` is precisely the sequence this finding rules out. The six-step
operator sequence is in `docs/79` §7b, and step 6 — **verify independently
afterwards** — exists because *"the migration returned exit code 0"* is now
known not to be evidence that the invariant holds.

**`o1_composite_fk.sql` stays out of `migrations/`** until the census is
obtained, reviewed, and the migration separately authorised.

## The Admin login boundary — built; production authentication is NOT

**You can open the Admin panel and sign in. Real staff cannot.** Those are two
different things and the build keeps them apart deliberately.

`src/Admin/AdminSession.php` (signed, short-lived, stateless) +
`DevSessionIdentity` + three session routes + the panel's login gate.
**`DenyAllIdentity` is still the production binding and W-4 is still open.**

### What production does

| | |
|---|---|
| `GET /api/v1/admin/session` | **401** with `can_authenticate: false`, `roles: []`, `provider: deny-all` |
| `POST /api/v1/admin/session` | **501 `production_authentication_unavailable`** — *not* 401 |
| every estate route | **401** |

**501, not 401, is the point.** A deployment that authenticates nobody has a
configuration state, not a credential problem. Answering 401 would invite staff
to type credentials at something that will never accept them and conclude their
own details were wrong.

### There is no fallback from production to development — proved, not asserted

`DevSessionIdentity` **throws** if `DN_DEV_STAFF_IDENTITY` is not the exact
string, and throws again if the process is authorized for real bindings
(F6-B). It never degrades to `DenyAllIdentity`, and `DenyAllIdentity` never
upgrades to it. `$issuer` stays `null` outside the gate, so **the login route
has nothing to mint with** in a deployment.

> **A security gate asserted against a guessed environment variable proves
> nothing.** The first version of this test set `DN_ALLOW_REAL_BINDINGS=1`; the
> real value is `yes-f6b-authorized`, so the assertion would have passed by
> never firing. It now reads `Bindings::REAL_GATE_ENV` and
> `REAL_GATE_VALUE` from the constants, with a control proving the gate is
> genuinely open before the refusal is expected.

### The seven UI states

login · invalid · working · authenticated · **expired** · **forbidden** ·
**unavailable**. The last three are the ones usually collapsed into "something
went wrong": expired says you *were* signed in; forbidden says you *are* signed
in and lack a capability, so signing in again will not help; unavailable says
nobody can sign in here and therefore **shows no form at all**.

### What "logging in" means here, and what it does not

There is **no password and no credential store**, because
`AdminIdentityPort` exists so the provider can be chosen later and a password
table written now is the one thing that would make that harder. **The
environment gate is the credential**; the form only picks a role, so the
capability boundary can be exercised.

- **The signing key is DERIVED, not reused.** `DNB_SECRET_KEY` is documented as
  the key for stored device credentials; signing sessions with the same bytes
  would be key reuse across unrelated purposes. The session key is
  HMAC-derived under a distinct label. No new secret to provision, none in
  source control.
- The cookie is **HttpOnly + SameSite=Strict**, `Secure` only over TLS — a
  Secure cookie on plain http is dropped and the developer sees a login that
  silently never works.
- **Logout clears a cookie; it does not revoke.** Found by driving the real
  HTTP server, not the router: a client still replaying the old cookie is
  admitted until the token expires. Acceptable for a one-hour development
  token, **not** for a production provider, which needs real revocation. The
  suite asserts the limitation so it stays visible.

### The panel carries nothing secret

Asserted on the bundle with **comments stripped first** — scanning prose
flagged the login screen's own honest copy. The credential guard checks
credential *shapes* (`password:`, `secret=`, a quoted `token:`), not the bare
word, because a screen that says *"nothing is asked for here"* should not fail
a test for saying so. Two pre-existing panel guards (no `SELECT`, no
`password`) fired on the new UI copy and **the copy was reworded — the guards
were not weakened.**

Suite **30 suites / 1,846 assertions / 0 failed**, stable over two runs.
Proved over real HTTP in both modes as well as in-process.

## The DishNet Portal direction — staff authentication PLANNED, not built (`docs/114`)

**Direction corrected 2026-09-23.** The product is the **DishNet Portal**:
DishNet Admin + the Customer PWA, both over the Domain-B API, both
DishNet-owned, at DishNet's own URL.

- **uCRM / UISP / Splynx are NOT the operating portal, NOT a login
  dependency, and there is NO staff-auth bridge.** Do not move the Admin UI
  into any of them and do not redesign Domain B around them. `docs/111`'s
  staff-identity path (U-7, S-1/S-2) is **superseded**; its data-adapter half
  is parked, not chosen. They may later be integrations for selected
  commercial data at the one audited link point `docs/110` defines.
- **W-4 is now authorised to be DESIGNED** — and only designed. `docs/114`
  is the plan: `mt_staff` + `mt_staff_sessions` owned by a new NOLOGIN
  `dnb_def_staff`; password (bcrypt) and TOTP verified **inside PostgreSQL**
  via pgcrypto — the schema's first extension, to be proved installable by
  the non-superuser owner; decaying lockout **inside `mt_staff_login()`**;
  opaque 256-bit cookie, HttpOnly/Secure/SameSite=Strict, stored hashed under
  a **label-derived** key (no new secret); resolve re-reads `status` and
  `role` live; disable revokes sessions transactionally; actor = username,
  a parameter from the identity boundary (W-1); `mt_current_customer()` is
  **never set on the Admin plane** — a target customer is a validated
  function parameter, never tenant context.
- **Credential tables must be created under `SET LOCAL ROLE dnb_def_staff`**,
  never as the owner: migration 015's default privileges for `dnb_admin` are
  per granting role and 022 revoked only INSERT, so an owner-created table
  would hand `dnb_admin` SELECT on password hashes by default. A test must
  assert every login role holds zero privileges on them.
- Bound writes come **after** the provider: routers first (three existing
  W-1 functions + one new enqueue function), then Admin-plane voucher/plan
  issuers for a **target** customer (D-AUTH-3, owner role decided by
  measurement), idempotent through `mt_idempotency(customer_id, key)` with
  the check before the mutation (RULE I-1). `POST /sites` waits for O-1;
  `disconnect` waits for the replay fix.
- **Superseded by G-B (built, see the section below):** at the time of the
  plan nothing was implemented and migrations ended at 025. `DenyAllIdentity`
  is still the DEFAULT binding, the preview artifact is a recording, and the
  production census remains the handoff for O-1. Gates G-A…G-F and decisions
  D-AUTH-1…7 are in `docs/114` §K–§L; the build is recorded in `docs/114` §O.

## Multi-operator tenancy — ANALYSED, not decided (`docs/115`); D-AUTH SUSPENDED

**D-AUTH-1…7 were measured and provisionally frozen (`docs/114` §M) and then
SUSPENDED the same day, before any code.** T-1 and T-8 were then answered
(`docs/116`) and G-B was built (migration 026) — see the G-B section below.

The product is **DishNet → Operators → Operator Staff → Locations → routers /
HotSpot / plans / vouchers / sessions → guests**. A reference ISP portal was
supplied for its **operating model only** — nothing of its implementation is
copied, and the personal details on its pages are reproduced nowhere.

- **`mt_customers` already IS the Operator — measured, not assumed.** Nothing
  references anything above it (`FKs FROM mt_customers: NONE`); sites (via
  services), devices, plans, vouchers, sessions, principals and audit all key
  on it; 19 of 21 `mt_*` tables carry FORCED RLS with 18 policies on
  `customer_id = mt_current_customer()`; `docs/110` already calls it "the
  Domain-B operator". The commercial customer is **external** (the nullable,
  withheld `ucrm_client_id`).
- **Do NOT add a tenant layer above it.** `mt_operators` above `mt_customers`
  would make `mt_customers` mean subscriber accounts — which the product does
  not have (**F11**) — and re-key **18 tables, 18 policies, 32 functions, 21
  migrations, 15 PHP files, 19 test files and `mt_auth_sessions`** while
  contradicting F4, F5 and `docs/81` §9. **Rejected on evidence.**
- **Recommended: vocabulary at the API/UI** (Operators & locations), no
  physical rename (same blast radius, zero security gain) — **T-1, awaiting
  instruction.**
- **Operator Staff = `mt_principals`** (exactly one operator, phone-OTP, PWA).
  Their intra-operator **capability model is UNDEFINED** — `kind
  owner|operator` is stored and never branched (C6/C16 = **T-2**). **Guests
  have no identity** and get none (F11, `docs/89`). **DishNet Staff** stay
  global by capability and name a **target operator** explicitly; D-AUTH-3
  reads *target operator* from now on.
- **Subscriber accounts are not a gap.** The reference's subscriber lifecycle
  is a different product shape; if ever wanted it is a child of the operator
  (T-3), never a layer above.
- Sessions attribute to the operator (via the HotSpot user) and to the
  location (via the voucher) — **never to a router** (unchanged).
- Sequence: T-1 → resume `docs/114` → T-2 → O-1/spine → projections and
  reports → G-C. Twelve open decisions in `docs/115` §N.

## T-1 / T-8 CLOSED — and the Operator Staff capability model is DESIGNED (`docs/116`)

- **T-1 CLOSED:** `mt_customers` **= Domain-B Operator**. UI/API/business say
  **Operator**; the table stays `mt_customers`. **Never create `mt_operators`,
  never physically rename.** Operator isolation through `customer_id` + RLS
  remains authoritative.
- **T-8 CLOSED:** `docs/114` resumes with the **dedicated `dnb_staffauth`**
  login role (M1: `dnb_adminwrite` reaches seven write functions) and
  **D-AUTH-3 = *target operator***. The Admin plane never sets
  `mt_current_customer()`; a target operator is an explicit validated
  parameter authorised by the staff capability.
- **T-2 DESIGNED, not built (`docs/116`)** — C6/C16 answered. Four planes:
  DishNet Staff (`mt_staff`) · Operator Owner (`kind='owner'`, every `op.*`)
  · Operator Staff (`kind='staff'`, exactly `capabilities[]`) · Guest (none).
  The value **`operator` is renamed to `staff`** because after T-1 "operator"
  means the tenant. **`mt_audit_log.actor_kind = 'staff'` means DishNet staff
  only; every operator person is `principal`** — pinned by a test; no actor
  kind is added. Owner implies all; `op.staff.manage` is **not grantable**;
  **at least one active owner per operator**. Location scoping is **later**
  (T-6), nothing reserved physically.
- **Measured, proved by execution (rolled back, residue 0): `dnb_app` can
  `UPDATE` a principal's `kind` and `INSERT` a forged `owner` inside its own
  tenant today.** Not a tenancy breach (RLS bounds it), but a capability
  column would have no floor below the application until
  `REVOKE INSERT, UPDATE, DELETE ON mt_principals FROM dnb_app` lands — so the
  capability model ships **with** that revoke (migration 027) or not at all.
  **B-3:** thirteen more tables still carry `dnb_app` write grants after
  024/025 (`mt_customers`, `mt_sites`, `mt_services`, `mt_devices`,
  `mt_auth_sessions`, `mt_sessions`, `mt_idempotency`, …) — inventory every
  writer before revoking, as B-2 did; its own task after G-B.
- The census (`docs/79`) gains one line: **principals by kind and status**,
  because the `operator → staff` value rewrite touches existing rows.
- **Sequence:** T-1 vocabulary pass → **G-B** (migration 026) → **027**
  (this model + principal grant closure + `mt_admin_principal_create` with
  target operator) → G-C → B-3 → O-1. G-B is BUILT (below), **027 is
  BUILT** (the T-2 section below) and **G-C is BUILT in software** (the G-C
  section below); G-C2 / B-3 / O-1 have NOT been started.

## G-B — DishNet Staff authentication is BUILT (migration 026); NOT bound by default

**W-4 is closed in code and in the development schema; the production posture
did not move.** `DenyAllIdentity` is still the default binding. A deployment
binds the real provider explicitly with `DN_STAFF_IDENTITY=dishnet`, and that
also needs TLS in front of PHP and a first administrator — G-D territory, not
authorised. **Nothing is installed anywhere; migrations end at 026; no `027*`
file exists; 027 / T-2 / B-3 / G-C / O-1 were NOT begun.** Full record:
`docs/114` §O.

- **Migration 026:** `mt_staff` + `mt_staff_sessions` **created under
  `SET LOCAL ROLE dnb_def_staff`** (migration 015's default ACL would
  otherwise hand `dnb_admin` SELECT on password hashes), no RLS — isolation is
  by privilege: **no login role holds any privilege on either table**, asserted
  over a `pg_roles` enumeration (stray `dnb_plain` included) with the owner as
  positive control. `pgcrypto` is the schema's first extension, created by the
  non-superuser owner. bcrypt (cost 12) and RFC 6238 TOTP are verified
  **inside `mt_staff_login()`**, in one transaction with the decaying lockout
  (5 → 15 min, ×2 per lock, 24 h ceiling, never permanent) and the audit row.
  **Every number lives in `mt_staff_policy()` and nowhere else** — a test
  asserts no PHP file carries one. Every failing path returns the empty set
  after spending a bcrypt → **one byte-identical 401**; the lock is audited,
  failures are counters.
- **Roles:** `dnb_staffauth` (LOGIN, 14th role) reaches **exactly** login /
  resolve / logout + the constants function, zero table privileges, no write
  function, no projection. Lifecycle and self-service → `dnb_adminwrite`
  (**W-3's approved list updated deliberately**, still zero table
  privileges). `mt_admin_staff()` → `dnb_adminapi`, no hash/secret/session.
  15 roles now: 7 login + 8 definer; `Doctor::DEV_PASSWORDS` **stays at six** —
  it is the burned list and `dnb_staffauth` never had a burned credential.
- **Provider selection is explicit and never falls back:** unset → DenyAll;
  `dishnet` → `DishnetStaffIdentity`; any other value → refuses to start;
  **dev gate + dishnet → refuse to coexist**; the real provider **works under
  the F6-B gate** that makes the dev identity throw; a provider that cannot
  connect → **500 on every request** with one log line, never deny-all, never
  the dev identity. `StaffIdentityFactory` is the only construction site.
- **Sessions:** opaque 256-bit cookie, HttpOnly · Secure · SameSite=Strict,
  stored only as `HMAC(token, K)` with `K` derived from `DNB_TOKEN_PEPPER`
  under a label (no new secret); 8 h absolute; **revocable** — logout,
  disable, role change, password reset and TOTP reset revoke in the same
  transaction; resolve **re-reads status live**, proved by flipping status
  under an unrevoked row. **Issued only over TLS**: PHP terminated it, or
  `X-Forwarded-Proto` from an address in `DN_TRUSTED_PROXY`; otherwise **403
  `insecure_transport`** and no row. So over an SSH tunnel to plain HTTP the
  real provider refuses; the demonstration path stays `DN_DEV_STAFF_IDENTITY`.
- **Second factor required by default** (`DN_STAFF_REQUIRE_TOTP=no` is
  development-only; the doctor blocks it outside a disposable environment). A
  pending session may only GET/DELETE `/session` and enrol/confirm; every
  capability route answers **403 `second_factor_required`**, distinct from
  `forbidden`. Enrolment returns the new key once; an independent RFC 6238
  implementation in the suite computes the code the database accepts; ±1
  window; replay of a code, and any older step, refused.
- **CSRF:** SameSite + Origin (must equal `DN_PORTAL_ORIGIN`, or Host when
  unset) + `Sec-Fetch-Site` + JSON only → 403 `cross_origin` / 415.
- **Routes:** six session routes (no capability), seven `/staff` routes
  (`staff.manage`, Admin only, **bound only under the real provider**; the dev
  identity gets 501). The seven estate POSTs still answer 501; manifest
  `surface` is now `estate read-only; identity read-write` and
  `writes.bound` is still `[]`. **No role, customer or operator from the
  browser establishes authority** — `role: admin` in the body yields the
  row's role; the actor-taking functions are called only from `StaffAdmin`
  with `$s->subject`.
- **Audit:** eleven actions, `actor_kind='staff'` (no new kind), actor = the
  immutable username, `customer_id NULL`; delta-counted exactly once per act,
  none on any refusal; every password, key, secret, token and hash generated
  in the suite is searched for in every detail — none.
- **Bootstrap:** `plugin.php staff:bootstrap <username> [--display]` on the
  server, once; prints the generated password once; refuses once any staff
  row exists. Passwords are always **generated server-side and shown once**.
- **Guard amendments, each with a control:** the frozen-guards column rule
  exempts `mt_staff.totp_secret` (R-5) and proves no login role can read it,
  and skips timestamp/boolean columns; the simulator's bare `password` needle
  became the credential-shape pattern; `panel/staff.js` is a separate
  identity-plane client so `api.js` stays estate read-only under its guards.
- **Proved over real HTTP** (`php -S … plugin/bin/serve.php`): 401 → 403
  plain → 401 wrong → 200 with `Secure; HttpOnly` → estate 200 → roster 403
  for sales → logout 204 → **replayed cookie 401**; and dev gate + dishnet in
  one process → 500 on every request with the reason logged.

Suite **31 suites / 2,375 assertions / 0 failed**, twice (was 30 / 1,846).

## T-2 — Operator Owner / Staff capabilities are BUILT (migration 027); B-3 still OPEN

**Development and test schema only.** Nothing is installed anywhere; migrations
end at **027** and no `028*` file exists; the production census is still the
handoff. Full record: `docs/116` §J (decisions taken before code) and §K (the
build and its proofs).

- **`mt_principals.kind` is `owner | staff`.** The `operator → staff` rewrite
  runs **as `dnb_def_auth`** with a `RAISE NOTICE` census per kind and status
  and an assertion that none remain, *then* the CHECK tightens — because the
  migration owner under FORCE RLS sees **0** principals (the O-1 lesson; the
  control printed 0). Proved on a disposable level-026 database seeded with
  two legacy rows: census 2 + 1, rewrote 2, 0 remain, dropped. **That is not
  production evidence; census SECTION 1c is.**
- **`capabilities text[]`, one canonical list** — `mt_op_capabilities()`
  (17 names); `OpCapability::ALL` is asserted equal. Owner implies all and
  stores `{}` by CHECK; **`op.staff.manage` is never grantable to staff** by
  CHECK; an unknown name is a constraint violation. Manager / Seller / Viewer
  are PHP presets only; the row stores the list.
- **The last-owner invariant is checked BEFORE the self-guard** in
  `mt_principal_set_kind` and `_disable`. Only an active owner can act, so with
  one owner left the actor *is* that owner: checked second, the invariant
  could never fire, and a guard that cannot fire cannot be proved.
- **`dnb_app` holds SELECT only on `mt_principals`.** INSERT, UPDATE and
  DELETE each proved refused by execution; `dnb_def_comm` (INSERT + UPDATE,
  **no widening policy**, so tenant-bound *below* the function) is the
  positive control, and is refused by the policy when it names another tenant.
  The four writers and `mt_principal_can` → **`dnb_app` only**; the floor
  `mt_principal_require` is granted to **nobody**; `dnb_admin` / `dnb_worker`
  still hold migration 015's blanket grant here — **F-3, OPEN**, asserted, not
  027's to revoke.
- **`mt_op_capabilities()` is EXECUTE-able by exactly `dnb_def_comm`,
  `dnb_def_prov`, `dnb_def_auth`** (a CHECK runs as the writing role; 007's
  `last_login_at` UPDATE re-evaluates every CHECK). It was first
  PUBLIC-executable; `test_isolation_s1_s2` refused it — **no `mt_` function
  may be PUBLIC-executable, with no exceptions** — and the guard was right.
- **Resolution is live.** `mt_auth_resolve_token` returns kind and
  capabilities on every request; a removed capability or a demotion binds the
  next request of an existing session; **disable revokes every session in the
  same transaction** (proved by rolling one back). A kind change clears the
  list and revokes nothing (J-7). Self-disable and self-demote are refused
  (J-6).
- **Every `/me` route runs through `Routes::$guard(capability, handler)`** — a
  route cannot be added without naming a capability. 403 is
  `{error: forbidden, capability}` and describes the caller's own role; **the
  404 record rule is untouched** (a foreign id is still indistinguishable from
  an absent one, capability or not). Five `/me/staff` routes, all
  `op.staff.manage`. `Kernel` maps **only** a 42501 whose message is
  `capability required: <op.x>` to 403; **any other 42501 stays 500**.
- **The six commercial functions carry the floor inside**, after the actor
  check and before any mutation: **42501, no audit row**, and the detail
  records `principal_kind` + `capability` at act time. Proved with an
  unguarded route through the Kernel: the floor answers 403 by itself.
- **Admin plane:** `mt_admin_principal_create(p_operator, …, p_actor)` —
  target explicit, `dnb_def_prov` with an INSERT-only policy, body contains no
  `mt_current_customer` (asserted on `prosrc`), audits `actor_kind = 'staff'`
  with `detail.operator`. `GET /api/v1/admin/principals` is bound as the
  **fourteenth** projection (phone, email, credential unreturnable);
  `POST /api/v1/admin/customers/{customer_id}/principals` is **declared unbound
  and answers 501** (J-1) — binding it needs its own instruction.
- **Fixtures and the simulator create the first owner through the Admin
  creator**, on the Admin write connection; A's seed now carries
  `customer.created` **and** `principal.created` (actor `test:seed` /
  `sim:seed`, `actor_kind = 'staff'`).
- **The naming rule is asserted:** every `mt_audit_write(` call in every
  migration passes a literal actor kind; no PHP maps `kind` onto `actor_kind`;
  `actor_kind` stays `principal | staff | system`.
- **Not built, deliberately:** B-3 (the thirteen other `dnb_app` write grants
  are asserted unchanged), G-C, G-C2, O-1, G-D, T-6, T-10, T-11, the PWA staff
  screens (J-14), idempotency for `POST /me/staff` (J-8, I-A), F-3.

Suite **32 suites / 2,769 assertions / 0 failed**, twice (was 31 / 2,375);
`tests/test_operator_staff.php` alone 378.

## G-C — the MikroTik router control-plane boundary is BUILT in software; NOTHING is HARDWARE VERIFIED

**Development schema only, no migration (still 027), nothing installed, no
physical MikroTik.** Full record: `docs/118` — §A the governing rules quoted,
§B fifteen decisions taken before code, §C/§F the evidence register with the
`docs/00` labels, §D–§E the build and the proofs. **Do not describe anything
built here as hardware activation, RouterOS compatibility, WireGuard push
viability or HotSpot operation.** The Phase-0 protocol (`docs/31`, `docs/32`)
remains the only instrument for those, `tools/chr_harness.sh` is still unrun,
and B1 (push vs poll) is decided nowhere.

- **Names.** The instruction's *MikroTikDeliveryAdapter* is
  `Dn\Delivery\RouterOsDelivery`; its *RouterOsClient* is
  `Dn\Delivery\RouterOs\RestClient`. Kept, not renamed (D-1).
- **The destination is derived, never accepted.** `Dn\Delivery\DeliveryTarget`
  is the one resolver both adapters use: the device row read under the
  intent's tenant context is the only source of a router's address; a payload
  carrying `host`, `endpoint`, `address`, `tunnel_ip`, `ip`, `url`, `port`,
  `serial`, `username`, `password`, `secret` or `wg_pubkey` is a **permanent
  refusal before any connection**; a malformed or unknown device id fails
  closed; another operator's device is *not found*.
- **Lifecycle gate.** Delivery only to `connected` / `provisioned` / `active` /
  `diverged`; `decommissioned` permanent; everything else retryable. **The
  adapter and the worker never write `mt_devices.state`** — moving a router to
  `connected` or `provisioned` is a staff act through `mt_device_set_state`,
  and whether a confirmed delivery may drive it is **UNRESOLVED** (a migration
  and a B1 question). A simulated delivery moves nothing either.
- **Identity check (H8, VERSION/MODEL DEPENDENT).** Before its first write the
  adapter reads `system/routerboard` and refuses permanently if the serial is
  not the registry's, making exactly one call; a router with no serial (CHR)
  is refused unless constructed with `requireSerial: false`, which only the
  CHR harness may do. A consistency guard, not the trust anchor — the
  WireGuard key at the transport layer is that, and `docs/30` §6.3 says the
  serial may be spoofable.
- **The F6-B gate is at the socket.** `RestClient`'s real transport calls
  `Bindings::requireRealBindingsAllowed()` before `curl`; the injected test
  transport never opens a socket. `DN_DELIVERY` selects the worker's binding
  — unset/`null` → `NullDelivery`; `simulated` → `SimulatedRouterOs`, which
  **refuses to construct inside a gated process**; `routeros` → requires the
  gate, else throws; anything else throws. **No fallback in any direction.**
  Every result carries `simulated`; `/health` reports `delivery_binding`,
  `delivery_simulated`, `delivery_configured`; the worker id carries the
  binding name into every audit row.
- **`SimulatedRouterOs` writes nothing to the database** — not device state,
  not `mt_device_config.actual` — and confirms from its own memory. What it
  proves is the worker, queue, lease and confirm-is-a-read logic. What it
  proves about RouterOS: nothing.
- **Timeouts and malformed answers.** 5 s connect / 10 s total (H11,
  UNRESOLVED on the tunnel); a transport failure is retryable and its message
  names no address or credential; a 2xx with a non-JSON body is `malformed`,
  retryable, and **never a confirmation**. The adapter scrubs the device's
  username, password and tunnel address from anything the worker records.
- **Admin plane: exactly two estate writes are bound** — `POST
  /api/v1/admin/routers` (`routers.register`) and `POST
  /api/v1/admin/routers/{device_id}/assign` (`routers.assign`) — through
  `Dn\Admin\RouterAdmin` on `dnb_adminwrite`, the W-1 functions, **actor =
  `StaffIdentity::$subject`**; a body carrying `staged_by`, `actor`, `state`,
  `customer_id`/`site_id` (on register) or `id` is **400, refused rather than
  ignored**; a tunnel address must be one address in `10.66.0.0/16`
  (`Dn\Devices\TunnelAddress`, the one rule the client and the route share);
  W-2 refuses a foreign site below the function. Bound under any identity
  provider the process runs (the development identity's actor is the literal
  `dev`, and it cannot exist in a gated process).
- **The router ACTION route is NOT bound** and answers 501
  `router_action_not_bound`: queuing a `device.provision` intent from the
  Admin plane needs a SECURITY DEFINER enqueue function for `dnb_adminwrite`
  — a migration — and G-C fixed the state at 027. **Recorded (D-2), not
  worked around**; `docs/114` §K G-C is met for register and assign and says
  so. Do not route the action through `dnb_admin` or `dnb_app` to close it.
- **Manifest:** `writes.bound` = the two routes with function, role and actor
  rule; `declared_unbound` = six (action, sites, plans, voucher-batches,
  disconnect, principals); `surface` = *estate read + router register/assign;
  identity read-write*; gates `admin-write` = *PARTIALLY BOUND (G-C)*,
  `delivery` = *NULL BY DEFAULT*; `DN_DELIVERY` declared (read literally —
  the installability sweep only sees `getenv('X')`).
- **The evidence register** (`docs/118` §C, §F): H1 self-signed REST and H5,
  H7 — VERSION/MODEL DEPENDENT; H6 — UNRESOLVED; H8 routerboard serial —
  VERSION/MODEL DEPENDENT; H9 `system/resource`, H10 `system/identity` —
  DOCUMENTED; H11 timeouts, H12 error shapes — UNRESOLVED; B1 — UNRESOLVED.
  **Nothing became HARDWARE VERIFIED and nothing may without a physical
  unit.**
- **Not started, deliberately:** G-C2, B-3 (the thirteen `dnb_app` grants
  asserted unchanged), O-1, G-D, T-6, T-10, T-11, the action route and its
  migration, panel forms, device-state automation, any B1 decision.

Suite **33 suites / 3,026 assertions / 0 failed**, twice (was 32 / 2,769);
`tests/test_router_control_plane.php` alone 250; G-B 487 and T-2 378
unchanged; `plugin/bin/install-test.sh` 85/85.

## Open and parked

- **Whether a site may have several MikroTik HotSpot routers is OPEN**
  (`docs/68` §2.6b). It did **not** block Decision 2a and is **not** settled by
  it: the chosen mechanism supports several, measured (`docs/70` §2, S2).
- **The ISP/operator hierarchy is a business-model input, not a tenant layer.**
  Do not build one from it. (`docs/68` §2.5, §2.6)
- **F6 is NOT AUTHORIZED.** Closing Decision 2a did not authorize it and did not
  bring it closer; the gate must be opened explicitly.
- **C1 guard tests are mandatory at that gate**, not after it (`docs/68` Part 1),
  and must also assert that **no client-asserted value reaches the authorize
  query** (`docs/70` §5.3).
- **`s1_s2_probe.php` repair is a separate test-maintenance task** and must not
  be bundled into F6. (`docs/65` §15)

*(`docs/NN` above means `dishnet-hybrid-sudan/docs/NN-*.md`.)*

## Always

Do not modify production FreeRADIUS, production databases, schema, privileges,
deployment, or Domain A (Starlink) without explicit authorization.

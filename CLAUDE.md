# Working in this repository

The Domain B / MikroTik control-plane work lives under **`dishnet-hybrid-sudan/`**.
Note there are two `docs/` directories in this repository; every path below is
from the repository root.

**Read `dishnet-hybrid-sudan/docs/69-DECISION-INDEX.md` first.** Before changing
anything it covers, read the authoritative decision document it points to. Where
the index and a decision document disagree, **the decision document is right.**

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

**No Admin write route or button is bound, and none may be without a new
instruction.** `DenyAllIdentity` is still the production Admin binding.
`Database::adminWrite()` exists and is used by no route.

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

### Two project engineering rules — now binding

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

Nothing authorized to build. No gate moved.

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

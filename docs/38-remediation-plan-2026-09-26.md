# 38 — Remediation plan for the Uganda customer platform (26 September 2026)

**Status: PLAN ONLY. Nothing is implemented, deployed or configured.** Three change sets, each to be approved
on its own. Every statement of fact below is drawn from docs/37 (the audit, measured on the live installation
on 26 September) or from the code as it stands in `dishnet-hybrid-sudan` at commit `4a2f41c` (the deployed
build, version 5.18.40). Where a decision is the operator's, it is marked **DECISION**.

Rules that bind every change set: no code, configuration, customer record, sync state, lock, inventory or
production service changes until the set is approved; secrets, codes and tokens never in chat, logs, tests or
the terminal; the South Sudan install runs the same plugin and must behave exactly as before wherever it
configures nothing new; a fix that reaches customers is proved in the sandbox, then deployed with the
existing one-command deploy script, then verified with the read-only audit command.

---

## Change set A — Uganda PWA and canonical URL

Split in two, so the contacts and the URL do not wait for the lawyer: **A1** everything except the legal
wording; **A2** the legal wording, gated on the operator's approval of text.

### A1.1 Help, company information and support contacts through `TenantProfile`

**Scope.** The post-login portal reads the tenant profile nowhere (docs/37 §J.1). Every South Sudan literal
in `tabs/customer_app/` moves behind `TenantProfile`, with the South Sudan values as the fallbacks so the
Sudan install renders byte-for-byte what it renders today.

**Files and exact sites.**

| File | Change |
|---|---|
| `tabs/customer_app/portal_data.php` | load `TenantProfile::current($config, $dataDir)` once (after `$config` exists, before line 826's view switch); expose `$portalTenant`; line 1020 `$portalLocation = 'Juba'` and 1024 `?? 'Juba'` → `office.city`; line 1031 `?? 'USD'` → `currency.code` |
| `tabs/customer_app/portal.php` | support view 1062–1135: WhatsApp card, "Call us" (display **and** dial the same `contacts.support_phone` — today it displays 005 and dials 002), e-mail row (`email`), the two WhatsApp rows; plans 975–978; WiFi 1587, 5180–5182; hotspot 3310; status view 4885 (locality), 4966, 4991 (service-area copy → profile or removed), 5010; the JS helper: `openWhatsApp` calls at 4200, 6805, 6947, 7228, 7282, 7296 take a page constant `DishNet.supportWa` emitted once from PHP (`contacts.support_wa`) |
| `tabs/customer_app/legal_page.php` | 307 footer entity + locality; 334 WhatsApp; 335 e-mail → profile (`login.footer_entity`, `login.footer_locality`, `contacts.support_wa`, `email`) |
| `profiles/uganda.json`, `profiles/south-sudan.json` | no value changes for A1; A2 adds the legal fields |
| `lib/TenantProfile.php` | one accessor for the portal's needs if not already present (`contacts()`, `text()` exist) |

**Design rule.** One load, in `portal_data.php`; views read variables, never call `TenantProfile` themselves;
every literal keeps the Sudan value as the fallback argument (the pattern `login_web.php:271–278` already uses).

**Dependencies.** None. Does not touch the API, the session, or the siblings.

**Tests.** New `tests/test_portal_tenant.php`, modelled on `test_customer_pwa.php` (serves the plugin under
`/crm/_plugins/<slug>/` with `php -S`) and `test_email_no_sudan.php` (renders for real, then reads the
output): sign in a fixture customer under the **uganda** profile and render `view=support`, `home`,
`service_status`, `?page=terms`, `?page=privacy`; assert zero `+211`, `Juba`, the South Sudan domain and
"South Sudan", and assert the Uganda contacts are present; then the **south-sudan** profile as the control:
the same pages carry the Sudan values exactly as today. A scan over `tabs/customer_app/*.php` and
`lib/LegalContent.php` fails on any new cross-tenant literal outside a `TenantProfile` fallback argument;
exceptions named with their reason. The journey rehearsal (`scripts/harness/…`, docs/37 §J.3) currently pins
L5, L9 and L10 as expected failures: it flips them to expected passes in the same commit.

**Migration impact.** None (no schema, no data).

### A1.2 Consent shared across verified sign-in routes of the same customer

**Finding.** `app_tos_consent` is keyed by the OTP identifier (`phone` column holds the phone **or** the
e-mail); the phone route had consented and rendered every page, the e-mail route of the same customer was
sent to the consent step (docs/37 §D.15). The row already stores `crm_client_id`; the lookup ignores it.

**Files.**

| File | Change |
|---|---|
| `lib/CustomerSession.php:188` `hasCurrentConsent($pdo, $identifier)` | gains `int $clientId = 0`; true when a row at the current versions matches the identifier **or** (`clientId > 0` and a row's `crm_client_id = clientId`) |
| `includes/api/api_customer_app.php:875` (login response `needs_consent`), `:4552` `ca_has_current_consent`, `:4589` `app_record_consent` | pass the session's `sub`; recording unchanged (it already writes `crm_client_id` and is only reachable with a verified session since 5.18.37) |
| `tabs/customer_app/portal_data.php:79`, `tabs/customer_app/login_web.php` (the consent-step decision near line 49–60) | pass `sub` |

**Why this does not bypass consent.** A row is written only by `app_record_consent`, which requires the
Bearer/cookie of a session the OTP just proved, for the `sub` that session names. Sharing by `crm_client_id`
therefore shares consent only between identities that have each proved they are that customer. A customer
with one phone on several CRM clients keeps today's behaviour (the identifier row covers all of them) and
additionally gains per-client rows.

**Tests.** New `tests/test_consent_identity.php`: consent recorded by the phone route → the e-mail route of
the same `sub` passes; no consent → both routes are asked; a row for another client id does not admit this
one; version bump re-asks both routes; the multi-account identifier case unchanged.

**Migration impact.** None: the column exists. Existing rows already carry `crm_client_id` (the identity log
shows `consent_recorded` with the client id).

### A1.3 Canonical host for the customer pages

**Finding.** The plugin answers on whatever host and port a request arrives on; on `:8443` UISP presents a
self-signed `localhost` certificate (docs/37 §I.2). The plugin's *generated* links are already corrected by
`crm_public_url` (5.18.34); the *page's own origin* is not.

**Design.** New `lib/CanonicalHost.php` with one static `enforce(array $config): void`, called in
`public.php` before the `customer_login` / `customer_portal` / `terms` / `privacy` routes (852–870) and in
`includes/routes.php` before `customer_manifest` (571):
- takes `dn_public_override($config)` (the same rule as every generated link); if it is empty — the Sudan
  case — it returns without doing anything;
- GET and HEAD only; **page requests only** — never `page=api` (once the page is on the public origin every
  relative API call follows; and the Android wrapper's API calls are never redirected);
- compares `scheme://HTTP_HOST` (normalised: `:443` dropped for https, `:80` for http) with the override;
  when they differ, answers **302** to `override + REQUEST_URI` (path and query unchanged). 302 first, as
  `scripts/traefik/dnb-customer-login.yml.template` does for the same reason; 301 in a later release once the
  address is proven stable;
- honours `X-Forwarded-Host` only if it is already trusted elsewhere in the plugin — today nothing reads it,
  so the first version uses `HTTP_HOST` alone and says so.

**Files.** `lib/CanonicalHost.php` (new), `public.php` (one call before the customer routes),
`includes/routes.php` (one call before `customer_manifest`), `lib/crm_url.php` unchanged.

**Dependencies.** `crm_public_url` set on Uganda (it is: `config.json` and the vault, docs/37 §I.2). The
Android wrapper: the plan exempts API calls, so a wrapper built on `:8443` keeps working; its *portal page*
load would be redirected once — acceptable only if the wrapper follows redirects and keeps cookies; verify
in the sandbox with the wrapper's marker header (`X-DishNet-Client`) before approving, or exempt requests
carrying that header as well (`CustomerSession::nativeClient()` already recognises it).

**Tests.** New `tests/test_canonical_host.php`: override set + request on `crm.example:8443` →
`?page=customer_portal&view=home` answers 302 to the public address with the same path and query; same for
`customer_login`, `terms`, `customer_manifest`; a POST is never redirected; `page=api` is never redirected; a
request carrying the native marker is never redirected; **no override → no redirect at all** (the Sudan
control). `tests/test_links_without_port.php`'s scan will see `HTTP_HOST` read in the new file: add the named
exception with its reason (comparison, not link building).

**Migration impact.** None.

### A1.4 uCRM `isActive` — investigate, do not change eligibility

No code change in A. The evidence step and the recommendation are in §4 below. `ca_login_eligibility`
(`api_customer_app.php:344–354`) stays as it is: archived → refused; lead → refused unless allowed; no service
→ refused only when `portal_login_require_service` is on.

### A1 — deployment, verification, rollback

- **Version** 5.18.41; `docs/07-change-control.md` entry; the plugin suite twice (`tests/run.sh`), weakened
  copies of the three new tests each caught; the journey rehearsal green with L5/L9/L10 flipped.
- **Deploy** with `scripts/deploy-hybrid.sh` (it derives the served directory from the container's mount,
  records `.deployed-commit`, reads it back through the container, never touches `data/`), wrapped as
  `scripts/deploy-5.18.41.sh` pinned to the reviewed commit, as 5.18.39 and 5.18.40 were.
- **Verify** with the read-only audit: `journey-audit.sh --login-phone` and `--login-email` (L5, L9, L10 must
  pass; PDF still WORKING; 401 after logout), `--urls` (U1–U5 unchanged), and one manual check: open the
  portal from a bookmark on `:8443` and see it land on the public address.
- **Rollback:** `git checkout 4a2f41c -- dishnet-hybrid-sudan && bash scripts/deploy-hybrid.sh` (the script
  never deletes and never touches data). Consent rows written meanwhile stay valid: same versions, same
  columns. No data migration to reverse.
- **Sudan:** same code; `crm_public_url` unset there → no redirect; profile `south-sudan` → the same values as
  today, except that "Call us" shows the profile's support phone instead of the unlisted 005 number
  (**DECISION A-1:** confirm 006 or another number for South Sudan).

### A2 — the legal wording (gated on approval of text)

**Finding.** `lib/LegalContent.php` binds every customer to South Sudan: "registered in South Sudan…
customers in Juba" (37–38), "governed by the laws of the Republic of South Sudan… the courts of Juba"
(116–118), "South Sudan regulatory authorities" (174–175); the legal page footer is South Sudan
(`legal_page.php:307, 334, 335`). The profile holds `jurisdiction.law` (Uganda: "the Republic of Uganda"),
`regulators[0]` (Uganda Communications Commission), `legal_entity`, `office.*` — and **`jurisdiction.courts`
is null and `legal_texts` is null for Uganda**. No Uganda wording exists in the repository; none is invented.

**Design.** `dnTermsContent()` and `dnPrivacyContent()` become templates over the profile: entity, place of
registration, service area, governing law, courts, regulator. **The version becomes tenant-scoped**
(`dnLegalVersion(TenantProfile $tp)`, read from `legal.tos_version` / `legal.privacy_version` in the
profile): Uganda moves to `1.1` when its wording lands; South Sudan stays `1.0`, so no Sudan customer is asked
again. `app_legal_version`, the consent recorder and the consent check take the tenant's versions.

**Files.** `lib/LegalContent.php`, `profiles/uganda.json` (`legal.*`), `profiles/south-sudan.json`
(`legal.tos_version: "1.0"`, `privacy_version: "1.0"` — the current values, made explicit),
`tabs/customer_app/legal_page.php` (footer, from A1.1), `includes/api/api_customer_app.php` (the three
`dnLegalVersion()` call sites: 878, 4573, 4594), `lib/CustomerSession.php:193`.

**Wording that requires the operator's approval (DECISION A-2), one line each:**
1. The identity sentence: *"DishNet Africa Limited … registered in Uganda, providing Starlink internet
   services to customers in Kampala and across Uganda"* — confirm the registration wording and the service
   scope (the Uganda profile lists `products: [starlink]`).
2. Governing law: *"the laws of the Republic of Uganda"* — from the profile; confirm.
3. **Courts:** none recorded. Confirm the forum (for example "the courts of Uganda" or a named court).
4. Regulator sentence in the Privacy Policy: *"the Uganda Communications Commission and other Ugandan
   authorities, if required by law"* — confirm.
5. Any Uganda-specific clause a lawyer requires (data protection: the Data Protection and Privacy Act, 2019,
   is the likely reference — **not** asserted here; to be confirmed).
6. Whether existing Uganda customers must re-accept (the version bump asks them once on their next visit).

**Tests.** `test_portal_tenant.php` extends to the legal pages under both profiles; a test that the Sudan
version stays `1.0` and the Uganda version is `1.1`; the consent tests re-run with per-tenant versions.

**Migration impact.** Behavioural only: every Uganda customer is asked to accept the new Terms once. Sudan:
none.

**Deployment / rollback.** Ships as 5.18.42 (or with A1 if the wording is approved in time). Rollback as A1;
consent rows for version `1.1` remain and are harmless under `1.0`.

---

## Change set B — Starlink data architecture (a design to approve; code follows separately)

### B.1 What the code already decided, and the audit measured

- **The authoritative register already exists: `equipment_assignments` + `stock_units`** in the hybrid
  (migration 036; `lib/EquipmentAssignment.php`). It is DB-enforced (a serial must be in stock, one live
  assignment per unit, one kit per uCRM service), keeps history (`release()`, `replaceUnit()`,
  `historyForUnit()`), records actor, and carries the Starlink identifiers (`starlink_account`,
  `starlink_service_line`, `terminal_id`, `router_id`).
- **The block workflow already reads only that register** — `StarlinkBlockService` header: "sl_kits.json is
  no longer consulted for ownership at all"; `StarlinkBlockBridge` the same, then Data Report's
  `dr_wifi_lookup_by_kit` for the router. So on Uganda, where the register is empty, **a suspension cannot find
  a router for any customer today.**
- **The typed uCRM attribute is the INPUT, not the store** (`KitAttributeIntake` header, `kit_intake.php`
  boundary: `starlinkDetails` = where a human enters the kit; `equipment_assignments` = who owns it; Data
  Report = Starlink operational data). The intake reproduces Data Report's extraction exactly (the same accepted
  keys — `starlinkdetails`, `kitnumber`, `starlinkkit`, `kitno`, `kit` — a test pins the agreement) and
  refuses to assign a serial that is not in stock ("assigning it would invent inventory").
- **Measured on Uganda (docs/37 §I.4, §I.1.2, §I.6):** `stock_units` 2 rows and `equipment_assignments` 2 live
  rows, both other customers', none for client #1; client #1's service carries `starlinkDetails` = KIT…KWW,
  which is in no register but is named by 2 routers in Data Report's `wifi_router_map.json`; Finance's
  `sl_kits.json` holds 3 kits (CRM #7, #47, #69) with no service line, router or account, and Finance's uCRM
  PATCH helper has 0 callers; Data Report's registry = those 3 + one unassigned kit; its 19 service
  lines have 17 empty kit numbers and 0 CRM ids; its 5 Starlink sessions are dead or cookie-less.
- **Two usage collectors exist.** The hybrid's own (`cron/starlink_usage.php` on `StarlinkSessionStore`, a
  Uganda-only imported session, `KitSlMap` for typed kit↔service-line pairings, `KitUsage` joining on the
  register) and Data Report's (`sl_usage.json`, 14 historical rows). `KitUsage` reads the hybrid's file first
  and Data Report's as fallback; `app_usage` is a TODO returning `unavailable`.

### B.2 Recommendation — one register, one chain, one usage path

**DECISION B-1 — the authoritative kit register is the hybrid's `equipment_assignments` + `stock_units`.**
Nothing new is built to hold kits; the portal reads only this (it already does, through
`CustomerAccountService`). Finance's `sl_kits.json` stops being a source of ownership and keeps only finance
attributes (billing package, revenue) keyed by kit serial. Data Report's registry is generated from the
hybrid's published register, not from Finance's typed file.

**The chain, with the owner of each link:**

```
uCRM client (id)                                  ← uCRM, source of the customer
  └─ uCRM service (id; attribute starlinkDetails)  ← uCRM; the attribute is a LABEL the hybrid writes back
       └─ equipment_assignments (unit, client, service, account, service line, terminal, router, actor)
            ├─ stock_units (serial, condition, purchase, starlink_account)   ← intake
            ├─ KitSlMap (kit ↔ service line typed pairing; gap-fill, never overwrite)
            └─ Starlink account / service line ──▶ usage rows ──▶ KitUsage ──▶ app_usage / portal usage view
```

**Inventory intake (DECISION B-2, per source of kits):**
- Kits bought from Starlink: `StarlinkOrderImport` books the order as a purchase and **creates the stock unit
  only when a line reports delivered and carries a serial** (idempotent on order number and serial).
- Kits from anywhere else: the Stock screen (`tabs/admin/stock_inout.php`, `stock_scanner.php`,
  `StockService`) receives them with serial and condition.
- Binding to a customer: `tabs/admin/kit_intake.php` reviews the `starlinkDetails` typed in uCRM against the
  register and offers exactly one verb — bind a kit that nobody holds — through
  `KitAttributeIntake::apply()` → `EquipmentAssignment::assign()`. Field installs use the same door
  (`tools/assign_kit.php`). Nothing binds by itself.
- For client #1 concretely: receive the kit named in its `starlinkDetails` into stock (order import or
  Stock screen), then bind it on the review screen. The portal's Equipment, Starlink and WiFi screens fill
  from that moment; suspension becomes possible from that moment.

**Replacements and transfers.** `replaceUnit()` (release + assign in one transaction, the chain recorded),
`release()` with a reason; a transfer between customers is a release and a new assignment by a person with an
actor, never by editing a text field — `KitAttributeIntake` refuses to move or release (a typo must not).

**How Finance and Data Report consume it (DECISION B-3).** The hybrid publishes a read-only register file,
`<plugins>/_dishnet_shared/kit_register.json` — the shared directory already used for `internal_auth.json`,
outside every plugin directory so it survives plugin upgrades — `{version, generated_at, kits:[{serial,
crm_client_id, crm_service_id, starlink_account, service_line, router_id, assigned_at}]}`, written atomically
on every assignment change and hourly, no names. Finance reads it the way it already reads Data Report's
files (path-based, with a freshness check); Data Report's `KitRegistryWriter` regenerates from it instead of
`sl_kits.json`. Those two consumer changes belong to the siblings and are staged: the hybrid publishes first
(harmless), each sibling switches when its owner approves; until then Finance's register remains for finance
fields only. **Nothing writes into Domain-B PostgreSQL, no second inventory, no CRM lookups at redemption or
accounting time.**

**Usage (DECISION B-4).** The portal's usage path is the hybrid's own collector: (1) an administrator imports
the Uganda Starlink session on `tabs/admin/starlink_session.php` (POST-only form, never printed;
`StarlinkSessionStore`, isolated from Sudan's by construction) — one was imported on 13 September and is now
**expired** (`--chain 1`), so the import recurs whenever Starlink ends the session, and B-4 needs an owner for
that chore exactly as Data Report's Sessions tab does; (2) kits are paired to service lines
(`starlink_service_line` on the assignment, or `KitSlMap`); (3) `cron/starlink_usage.php` writes the hybrid's
`sl_usage.json`; (4) `app_usage` returns `KitUsage::forClient()` instead of `unavailable` — the portal's usage
view already joins the same class. Data Report keeps collecting for its own fleet screens and stays the
fallback source; its dead sessions are its owner's to re-authenticate (Sessions tab). Freshness is stated in
the API (`collected_at`) so the app never shows a stale number as current. Real-time: balance and payments on
account open. Cached with a visible age: plan, invoices, kits. Eventual (hourly): usage, Starlink state.

**What stays separate.** The Data Report hand-off (docs/36) is **still non-functional (404)** and is not
touched or claimed by B; its redesign is docs/36 §J, its own approval.

### B.3 Files, tests, migration, deployment, rollback

- **Files (hybrid):** `includes/api/api_customer_app.php` (`app_usage` → `KitUsage`), new
  `lib/KitRegisterPublisher.php` (+ a hook in `EquipmentAssignment::assign/release/replaceUnit` and in
  `cron/master.php` hourly), `lib/KitUsage.php` (expose `collected_at`), no change to
  `EquipmentAssignment`, `KitAttributeIntake`, `StarlinkBlockService`, `StockService`, `StarlinkOrderImport`.
  **Files (siblings, separate approvals):** Finance `public.php` kit reads → the published file; Data Report
  `lib/KitRegistryWriter.php` → the published file.
- **Tests:** publisher (schema, atomic write, no name, permission), `app_usage` with fixture rows (fresh, stale,
  none), the block bridge finding a router for an assigned kit, the existing `KitAttributeIntake` agreement
  test unchanged; the sibling changes tested against the published file's fixture.
- **Migration impact:** none to schema (036 and 068 exist). **Data work by staff:** receive the kits into
  stock and bind them (three in Finance's register + #1's); import the Starlink session; pair kits to service
  lines. That is operational, audited, and not done by code.
- **Deployment:** the hybrid part as one release after A; each sibling as its own deployment.
- **Rollback:** redeploy the previous hybrid commit; delete `kit_register.json` (consumers then fall back to
  their current files); assignments are data and are kept — they are correct records of who holds a kit.

**DECISIONS for B:** B-1 register · B-2 intake path per source · B-3 the siblings consume the published
register (owners' approvals) · B-4 the hybrid's collector as the portal's usage path and who imports the
session · B-5 the three kits Finance holds for #7, #47, #69: confirm ownership per kit before receiving and
binding them · B-6 re-authenticate Data Report's five Starlink accounts (operational; unblocks its fleet
screens and the router map the block workflow needs).

---

## Change set C — Infrastructure: the `:8443` door

### C.1 The exact cause (measured, docs/37 §I.2, §J.2)

- One host, two origins for the same plugin. **443**: Traefik, Let's Encrypt for `crm.dishnetuganda.com`,
  verify 0. **8443**: UISP's own web server, a **self-signed certificate for `localhost`**, verify 18, so a
  browser objects to the issuer and to the name.
- uCRM believes its own address is `https://crm.dishnetuganda.com:8443/crm/` (`ucrmPublicUrl`,
  `pluginPublicUrl`), so **uCRM's own e-mails and post-login redirects carry `:8443`**.
- The bare `https://crm.dishnetuganda.com/crm` is answered by UISP's web server with **301 →
  `https://crm.dishnetuganda.com:8443/crm/`**, even through Traefik; browsers cache a 301.
  `http://crm.dishnetuganda.com:8080/` → 301 → `:8443/`.
- The plugin's generated links and the website are already on 443 (5.18.34; decision 8 deployed).
- **The invoice PDF document itself carries two links to `crm.dishnetuganda.com:8443`** (MEASURED 09:44, both
  walks, docs/39 §7): uCRM writes its own address into every PDF it renders; the plugin streams the bytes
  unchanged. This is the likeliest door for the operator's own warning.

### C.2 Where the fix belongs — three places, in this order

**C-1 Traefik: absorb the bare `/crm` on 443** (recommended first; smallest; reversible in one command).
One new file `/etc/easypanel/traefik/config/dnb-crm-root.yml` in the shape of
`scripts/traefik/dnb-customer-login.yml.template`: routers for `Host(crm.dishnetuganda.com) && Path(/crm)`
on the `https` and `http` entry points, priority above `uisp.yaml`'s host router, a `redirectRegex`
middleware to `https://crm.dishnetuganda.com/crm/` (UISP then 302s to its own sign-in on 443, as measured).
Traefik's file provider picks it up without a restart (precedent: stage 2 and the mail stack). **Rollback:**
delete the file. **It never edits `main.yaml` or `uisp.yaml`.** Placed by a script that reads the certificate
resolver and priority from `uisp.yaml` and verifies the 302 — the clean-URL script already does exactly this
for `/customer-login`. Evidence to collect first (read-only): `cat /etc/easypanel/traefik/config/uisp.yaml`.

**C-2 uCRM: the address it believes it has** (fixes uCRM's own e-mails and redirects; the only fix for those).
uCRM → Settings → System → Application → *Server domain name* `crm.dishnetuganda.com`, *Server port* `443`.
Before touching it: (a) confirm the fields are editable and not greyed out as managed by UISP (docs/33 §6);
(b) confirm in UISP → Settings → Devices that the **device connection hostname/port is a separate setting**
and stays `:8443` — routers connect there and it must never change (docs/05); (c) note the current values
for rollback. Effect on the plugin: none — `ucrm.json` will then say 443 and the override agrees. **C-2 is also the only fix
for the links inside the invoice PDFs**; after the change, download one already-issued invoice to learn whether
uCRM re-renders it with the new address or keeps the old document, before telling customers. **Rollback:**
set the two fields back. Evidence to collect first: a screenshot of that settings page, and
`grep -riE 'port|host' /home/unms/app/unms.conf` (read-only) for UISP's own ports.

**C-3 Certificate on 8443 — deferred.** docs/05 Option B: a DNS-01 certificate placed in UISP's certificate
directory, renewal to build and maintain, the URL keeps `:8443`. Worth it only for a trusted certificate on
the device port; not for a browser address. Not recommended now.

**C-4 Port 8080 — optional.** It only redirects to `:8443` (docs/01); closing it in the firewall removes one
door (docs/06: low risk, test device adoption afterwards). Not required if C-1 and C-2 are done.

**Dependencies.** C-1 none. C-2 the two confirmations above. Both independent of A and B; the plugin-side
canonical redirect (A1.3) covers whatever still reaches `:8443` after them (old bookmarks, cached 301s) — but
not a link inside a PDF, which points at uCRM's own pages, not the plugin's.

**Verification:** `journey-audit.sh --urls` before and after (U4's `/crm` line changes from 301→`:8443` to
302→`/crm/` on 443; U1 stays clean; U3 unchanged until C-3 ever happens), plus opening the sign-in from a
phone on mobile data in a private window.

---

## 4. uCRM `isActive = false` on client #1 — evidence and recommendation

**What is measured (docs/37 §I.4).** uCRM live: client #1 `isActive false`, `isLead false`, `isArchived
false`, clientType 2 (company), one service id 6 with **status 1 (active)** since 20 Sep 2026, one unpaid
invoice (329,000), balance −329,000, 0 payments. The plugin's own service-status map reads 1 as `active`
(`CustomerAccountService.php:329`, `CustomerDataTools.php:335`).

**What the code does with it.** `ClientSearchIndex` copies `isActive` into `is_active` (line 95) and stores
it; **`ca_login_eligibility` never reads it** — archived → refused, lead → refused unless allowed, no service
→ refused only if `portal_login_require_service` is on. So the flag has had no effect on any sign-in, and
client #1 signed in by both routes on 26 September.

**What the repository does not know.** No document or fixture in either plugin defines uCRM's client
`isActive`. Reading it as "archived or disabled" would be a guess; so would "has no active service" (it has
one). The honest position is that its meaning on this installation is unknown until measured.

**The evidence step (read-only, one command, added to the audit):**

```
cd /opt/dishnet && git pull origin claude/study-this-jhe2eg
bash scripts/journey-audit.sh --client-flags 1 2>&1 | tee /root/dnb-verify/journey-client-flags-$(date -u +%Y%m%dT%H%M%SZ).log
```

It reads every client and every service through the plugin's own uCRM client (GET only), and prints counts
only: how many clients are active/inactive; a cross-tabulation of the flag against "has an active service",
"has services but none active", "no service", lead, archived, outstanding balance, `hasOverdueInvoice`,
`hasSuspendedService`; then, for six candidate rules, how many of the clients each rule reproduces; the
statuses seen; client #1's own row; and the ids of every client that is inactive yet holds an active service.
No name, phone or e-mail is printed. Read also, in uCRM: client #1's page (the status badge shown) and the
service's page (any "activate" or "prepared" state) — a screenshot with the identifiers cropped is enough.

**Recommendation, independent of what the probe finds:**
1. **Do not gate portal access on `isActive`, and do not deactivate client #1.** A flag whose meaning is not
   established must not lock a paying customer out. The plugin's existing gates are the right levers: archived
   (always), lead (configurable), and `has_service` (configurable) — the last is what "no active service"
   would mean if that is what the flag tracks.
2. If the probe shows `isActive` equals "has at least one active service" for every client **except** #1, the
   defect is in uCRM's record for #1 (a stale or unrecomputed flag): re-save the service in uCRM or wait for
   uCRM's own recalculation; verify with the probe again; no plugin change.
3. If the probe shows `isActive` tracks payment (all unpaid first invoices inactive), it is a billing state,
   not an access state: the portal must keep admitting the customer so they can pay (Pay Now lives in the
   portal); no plugin change.
4. If the probe shows the flag is unset or inconsistent across many clients, treat it as informational; keep
   ignoring it; record that in `docs/07`.
5. Only if the operator decides a business rule ("inactive clients may not sign in") does eligibility change —
   then as a configurable gate on `is_active` beside the existing three, with a test and the Sudan default off.

---

## 5. Order, approvals and what each unlocks

| Order | Set | Needs from the operator | Unlocks |
|---|---|---|---|
| 1 | **A1** (contacts, consent, canonical host) | approval; DECISION A-1 (Sudan support number) | Help/Support correct on Uganda; one consent per customer; bookmarks land on 443 |
| 2 | **C-1**, then **C-2** | `uisp.yaml` read; the uCRM settings screenshot; approval | no `:8443` door from `/crm` or from uCRM's own e-mails |
| 3 | **§4 probe** | run the one command; the uCRM screenshot | the `isActive` position, evidence-based |
| 4 | **A2** | the six wording decisions (A-2) | Uganda Terms and Privacy |
| 5 | **B decisions** B-1…B-6 | decisions; staff receive and bind kits; session import; Data Report re-auth | Equipment, Starlink, WiFi, suspension and usage for Uganda customers |
| 6 | **B build** (hybrid part, then siblings) | approval per part | `app_usage` real; one register published |

Each set is proved in the sandbox, deployed with the pinned one-command script, verified by the read-only
audit command, and reversible by redeploying the previous commit (plugin) or deleting one file (Traefik) or
restoring two fields (uCRM).

## 6. Safety

No code, configuration, customer record, sync state, lock, inventory or production service is changed by this
plan. The only new thing that exists is the read-only `--client-flags` probe in `scripts/journey-audit.sh`,
rehearsed in the sandbox against a fake uCRM. Approval of one change set authorises that set only.

## 7. Decisions and status — 26 September 2026, after the operator's approval

The operator's instruction, verbatim: *"i will go with your recommendation"*. Read as adoption of the recommended
path (§5): **A1 first, then C, then A2, then the B decisions and build.** What that changed, and what it did not:

| Set | Status | Notes |
|---|---|---|
| **A1.1** contacts, company information, currency through `TenantProfile` | **BUILT — 5.18.41, awaiting the deployment command** | the plan's sites plus three found while building (§7.1): the invoice screen's payee, bank line and " USD"; the payment-notification currency; the fibre and LTE status cards. Rendered proof: `tests/test_portal_tenant.php` (88) |
| **A1.2** consent shared across verified routes of one customer | **BUILT — 5.18.41** | `tests/test_consent_identity.php` (28); the type-affinity trap it found is in §7.1 |
| **A1.3** canonical host for the customer pages | **BUILT — 5.18.41** | `tests/test_canonical_host.php` (49); the rule is narrower than planned and loop-proof by construction (§7.1) |
| **A1.4** `isActive` | unchanged (decided §4) | — |
| **DECISION A-1** (Sudan "Call us" number) | **resolved by the profile**: the portal shows and dials `contacts.support_phone` — South Sudan `+211 921 443 006`, the number the rest of the Sudan code already calls the support phone | the portal displayed 005 and dialled 002 before; if 006 is wrong, it is one value in `profiles/south-sudan.json` |
| **A2** legal wording | **PROPOSAL for approval — §7.3**; nothing changed in the documents' identity, jurisdiction or regulator sentences | the CONTACT lines inside the documents already read the profile (A1.1) |
| **C-1** Traefik absorbs the bare `/crm` | **HANDED OVER**: `scripts/dnb-crm-root-redirect.sh` (read-first, verified, one-line rollback), rehearsed 17/17 | run it once, send the log file |
| **C-2** uCRM's own address | **CHECKLIST — §7.2**; an operator act in uCRM's settings | the only fix for the `:8443` links inside every invoice PDF (docs/39 §7) |
| **B-1** the authoritative kit register | **DECIDED: O3** (docs/39 §13) — the uCRM attribute as the human entry point, the hybrid's `stock_units` + `equipment_assignments` as the store, Finance and Data Report consume a published register | validation (b) done by `--chain 1`; (a) is one question to the person who deploys kits (§7.4) |
| **B-2…B-6** | recommended values adopted as the direction; **nothing built** | B-5 and B-6 are operator acts (confirm the three Finance kits' owners; re-authenticate Data Report's Starlink sessions) |
| deployment / configuration / customer data | **NOTHING CHANGED on the server** | the server changes are the two commands in §7.2, each run by the operator |

### 7.1 Found while building A1 (recorded, each pinned by a test)

- **Three more South Sudan literals than the plan listed**, all in the invoice screen and the status view:
  the bank-transfer block (`DishNet Africa Ltd` / `Stanbic Bank / Equity Bank` / a literal ` USD` after an
  amount `dn_cur()` had already prefixed with `UGX `), the WhatsApp payment notification (` USD*`), and the
  **Fiber** and **4G LTE** status cards with `Juba metro areas` / `Juba, Yei, Wau`. Resolution: the bank
  details are `payment_instructions` in the profile — **added to `profiles/south-sudan.json` as the literals
  the code carried** (its charter), **null for Uganda**, and a tenant whose profile holds none gets **no bank
  line**, never the other tenant's (TenantProfile's rule for a null); the currency code is printed only when
  the symbol does not already carry it (`$ 50 USD` stays, `UGX 50,000` does not repeat itself); the fibre
  and LTE cards render only where the profile's `products` lists them (Uganda: `starlink` only).
  **New operator input A-3:** Uganda's own bank-transfer details for the invoice screen, if wanted — until
  then the screen shows the payment reference and the amount, and the Pay Now / Airtel routes.
- **A closing PHP tag inside a `//` comment ends PHP mode and prints the rest of the file.** The first build
  did exactly that in `portal_data.php`; the portal's own source appeared on the home page and the currency
  helper was never defined. Caught by the rendered-page test, then pinned by a tokenizer-based guard in
  `test_portal_tenant.php` — with the control that the guard's own first wording tripped it too.
- **PDO binds an integer as TEXT and SQLite orders TEXT above INTEGER**, so a `? > 0` guard bound from PHP is
  true for `'0'`. The consent lookup now branches in PHP and binds the client id as an integer. Found by the
  unit part of `test_consent_identity.php` before any HTTP.
- **A customer token's issuer is derived from the plugin's directory name.** A test that mints a session in
  process must run the copy under the plugin's own directory name, or every token it issues is `invalid`.
- **The canonical-host rule is narrower than the plan's, deliberately:** it redirects only a request whose
  `Host` names the public host **with an explicit port that differs** from the public one — the shape `:8443`
  (and `:8080`) has and the public origin never has, since a browser omits `:443` and the proxies present the
  bare host (measured: the cookie POSTs' same-origin check on `HTTP_HOST` passed on 443, docs/37 §I.5). A
  request without an explicit port is therefore never redirected under any scheme hint, and a loop is
  impossible by construction; the deployment command still checks and rolls back by itself if the public
  address ever redirected.
- **opcache serves an edited copy up to two seconds late** (`revalidate_freq=2`), so every control that
  edits the sandbox copy and re-requests polls for the change instead of asserting at once.

### 7.2 The two server commands (operator acts; each sends back its log file)

1. **Deploy 5.18.41** — `scripts/deploy-5.18.41.sh`, pinned to the reviewed plugin commit; before-evidence,
   backup, the documented deploy, then stage **V**: the public address answers with **zero redirects** (else
   it rolls back by itself), the sign-in, Terms and Privacy pages carry no South Sudan contact, the `:8443`
   door answers 302 to the public address for the customer pages and **never** for `page=api`, a POST or
   the native wrapper, and no fatal since the deploy. Then the operator's own `journey-audit.sh --login-phone`
   proves the signed-in screens: **L5 and L9 pass; L10 still fails until A2.**
2. **C-1** — `scripts/dnb-crm-root-redirect.sh`: reads `uisp.yaml`, writes one Traefik file for the exact path
   `/crm`, verifies `302 → https://crm.dishnetuganda.com/crm/`, `/crm/` unchanged, the portal and uCRM's
   login still 200, Traefik not restarted. Rollback: delete the file.
3. **C-2 checklist** (uCRM → Settings → System → Application), by hand: (a) the fields *Server domain name*
   and *Server port* are editable, not greyed out as managed by UISP; (b) UISP → Settings → Devices shows the
   device connection hostname/port as a **separate** setting that stays `:8443`; (c) note the current values;
   then set `crm.dishnetuganda.com` / `443`; (d) afterwards download **one already-issued invoice** from the
   portal and run `journey-audit.sh --login-phone`: the in-PDF scan (L11) shows whether uCRM re-rendered the
   document without `:8443`. Rollback: the two fields back to their noted values.

### 7.3 A2 — the wording that needs your approval (proposal; nothing is final until you say so)

Drawn only from `profiles/uganda.json` and the repository. Each line: **APPROVE** as written, or **EDIT**.
Points 3, 5 and 7–10 cannot be answered from the repository and are questions.

1. Identity: *"DishNet Africa Limited ("DishNet", "we", "us") is an IT solutions provider and UCC-authorised
   Starlink installer registered in Uganda (Reg. No. 80046255496181), providing Starlink internet services to
   customers in Kampala and across Uganda."* — entity, positioning, registration number and product from the
   profile.
2. Governing law: *"These Terms are governed by the laws of the Republic of Uganda."* — `jurisdiction.law`.
3. **Courts — a question:** *"the courts of Uganda"* or a named court (for example the High Court of Uganda at
   Kampala)? The profile holds `courts: null`.
4. Regulator (Privacy, "Who we share with"): *"the Uganda Communications Commission and other Ugandan
   authorities, if required by law"* — `regulators[0]`.
5. **Data protection — a question for your lawyer:** whether to name Uganda's data-protection law (the Data
   Protection and Privacy Act, 2019 is the likely reference; not asserted) and whether a Ugandan customer must
   be told where the data is held.
6. Re-acceptance: the version becomes tenant-scoped — **Uganda 1.1, South Sudan stays 1.0** — so every Uganda
   customer accepts the new wording once on their next sign-in and no Sudan customer is asked. Recommended.
7. **Fees and currency in the Terms — a question:** the Sudan text says a 5 % late fee after 7 days,
   **USD 25** reconnection, **USD 150** Starlink transfer fee after 6 months with a 120-day lead time, cheques
   payable to "DishNet Africa Limited". Which of these apply in Uganda, and in which currency?
8. **Products — a question:** the Sudan text names "Starlink, fibre, and LTE" and "fibre partners, LTE
   carriers" throughout. The Uganda profile sells Starlink only; the proposal drops fibre and LTE from the
   Uganda text.
9. **Sign-in wording:** the Privacy Policy's "WhatsApp and login codes" section describes WhatsApp only; Uganda
   also signs in by e-mail. Proposal: *"…by sending a six-digit code to your WhatsApp number or your e-mail
   address."*
10. **Sharing clause:** "Fibre and LTE partners (e.g. Splynx-managed operators)" does not apply to Uganda;
    proposal: drop it from the Uganda text.

Once approved, A2 ships as 5.18.42: `dnTermsContent()` / `dnPrivacyContent()` become templates over the
profile, the profile gains `legal.*` (Uganda 1.1; South Sudan 1.0 made explicit), and
`test_portal_tenant.php`'s pinned "STILL South Sudan's" assertions flip.

### 7.4 B — the one question before the build

O3 is adopted as the direction. Before the first B build step, one answer from the person who deploys kits:
**where do you record a Starlink kit deployment today — Finance's "Deploy to customer", the uCRM service
attribute, or both?** Two other customers' kits are already received and bound in the hybrid's register
(docs/39 §4), so that workflow exists; whether those two are Finance's #7 / #47 / #69 is what a read-only
`--chain 7`, `--chain 47`, `--chain 69` would show. B-5 (confirm each Finance kit's owner) and B-6
(re-authenticate Data Report's five Starlink accounts) remain operator acts.

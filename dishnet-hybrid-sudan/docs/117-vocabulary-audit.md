# 117 — T-1 vocabulary pass: canonical terms and the audit

**Status: DONE, wording only.** Step 1 of the sequence in `docs/116` §I,
authorised 2026-09-23 as *documentation and code-vocabulary alignment*. **No
migration, no schema change, no grant or revoke, no API behaviour change, no
production access.** Migrations end at `025`. Migration 027 (`docs/116` §D)
remains **pending**; B-3 (`docs/116` §F.3) remains **open**; the measured
`dnb_app` finding (`docs/116` §0) is unchanged by anything here.

---

## 1. The canonical vocabulary

| Term | Means | Stored / named in code as — **unchanged** |
|---|---|---|
| **Operator** | the Domain-B **tenant**, the RLS boundary (`docs/115` D.1, T-1) | `mt_customers`, `customer_id`, `mt_current_customer()`, `Database::app()` (the operator-plane request role), Admin API `/api/v1/admin/customers`, JSON key `customer`, capabilities `customers.read` / `customers.write` |
| **Operator Owner** | a principal with `kind = 'owner'`; every operator-plane capability | `mt_principals.kind` |
| **Operator Staff** | a principal with `kind = 'staff'` — **the stored value is still `'operator'`** until migration 027 renames it (`docs/116` §D.1) | `mt_principals.kind` |
| **DishNet Staff** | DishNet's own people — Admin · NOC · Sales · Support | `mt_staff` (planned, 026), `StaffRole`, `Capability`; **`mt_audit_log.actor_kind = 'staff'` means them and nobody else** |
| **DishNet engineer** | the human who installs, censuses or operates the platform — what documents before `docs/115` call *"the operator"* | — |
| **Guest** | a voucher, then a session; never an account, never an actor kind (`docs/89`) | `mt_vouchers`, `mt_sessions`, `mt_hotspot_users` |
| **Location / Site** | `mt_sites`; the UI keeps *sites* for now — **T-1b**, an open wording choice | `mt_sites`, `site_id` |
| **Customer PWA** | the product name of the operator-plane app; its users are Operator Staff | `public/index.php`, `/api/v1/me/*` |
| **Commercial customer** | the external billing relationship (uCRM / Splynx client); not a Domain-B entity | `ucrm_client_id` (nullable, withheld from the operator plane) |

**Three rules that follow.**

1. `actor_kind` stays `principal | staff | system`. Every person of an
   operator — owner or staff — is `principal`; DishNet Staff are `staff`;
   no actor kind is added to tell them apart (`docs/116` §G).
2. Internal identifiers keep the schema's names. Renaming `mt_customers`,
   `customer_id` or the definer functions would be the migration `docs/115`
   §B.13 measured and rejected. The **user-facing** word is Operator.
3. **Reading rule for older documents.** `docs/30`–`docs/113` and the
   CLAUDE.md sections that precede `docs/115` use *"the operator"* for the
   DishNet engineer and *"customer"* for the tenant. They are records and are
   **not rewritten**; where they quote `kind = 'operator'` they quote the
   schema value that still exists.

---

## 2. The audit

Classes: **UI** (a label or copy a person sees) · **comment** (a code or
tooling comment, or an assertion message) · **manifest** (descriptive prose
in `plugin.json`, not a route, key or capability) · **doc** · **027**
(bound to the `kind` CHECK; changes with migration 027) · **API** (a contract
change, deferred) · **decision** (needs its own instruction) · **historical**
(left as written).

### 2.1 Changed in this pass

| File | Old | Meant | Now | Class |
|---|---|---|---|---|
| `panel/app.js` L29 | `Customers & sites` | the tenants | `Operators & sites` | UI |
| `panel/app.js` L104, L221, L338 | column / field `Customer` | the tenant | `Operator` | UI |
| `panel/app.js` L416 | screen title `Customers & Sites` | the tenants | `Operators & Sites` | UI |
| `panel/app.js` L420 | empty-state noun `customers` | the tenants | `operators` | UI |
| `panel/app.js` L68, L149, L184 · `panel/index.html` L128 | "an operator" | the person at the console | `DishNet staff` / `the NOC view` | comment |
| `src/Plugin/Simulator.php` L90 | seeded principal named `{ref} operator` | the operator's **owner** (its `kind` was already `owner`) | `{ref} owner` | synthetic label (dev only) |
| `src/Api/AdminRoutes.php` L231 · `src/Network/SignalReport.php` L32 · `src/Jobs/UplinkSampler.php` L124 · `src/Radius/SimulatedPublisher.php` L11 · `src/Plugin/Installer.php` L145 · `src/Plugin/Doctor.php` L9, L20, L296 · `plugin/bin/bootstrap.sql` L5 · `plugin/bin/serve.php` L11 · `tests/run.sh` L13 · `tests/test_plugin_boundary.php` L204 · `tests/test_installability.php` L271 · `tools/audit/n10_backfill_census.php` L5 · `tools/audit/production_census.sql` L2 | "the operator" | the DishNet engineer / DishNet staff | `DishNet staff` / `the engineer` / `the installing engineer` / `RUN BY THE DISHNET ENGINEER` | comment |
| `tests/test_admin_api.php` L97 · `tests/test_auth.php` L88 | "the operator" in an assertion **message** | DishNet staff / an engineer | `DishNet staff` / `an engineer` | comment (message text only; the assertion is unchanged) |
| `plugin/plugin.json` `config.DNB_APP_USER/PASS.description` | `Customer request role.` | the operator-plane connection | `Operator-plane request role (the Customer PWA API).` | manifest |
| `plugin/plugin.json` `package.excludes[3]` | `the customer API front controller` | the PWA's API | `the Customer PWA API front controller` | manifest |
| `docs/114` ×6, on five lines (E, G.11, I, L.3, M.3) | `target customer` | the operator an Admin-plane write names | `target operator` | doc — the two §M lines that *record* the rename keep the old phrase |
| `docs/116` §B heading, §H | `operator-staff` | Operator Staff | `Operator Staff`; status line records 027 pending / B-3 open | doc |
| `dishnet-mikrotik-control-plane/README.md` | — | — | vocabulary paragraph | doc |
| `CLAUDE.md` | — | — | **Vocabulary** section, first thing after the index pointer | doc |
| `docs/69` | — | — | one row | doc |

### 2.2 Left as written, and why

| File | Term | Meant | What would change it | Class |
|---|---|---|---|---|
| `migrations/002_identity.sql` L14 (comment), L19 `CHECK (kind IN ('owner','operator'))` | `operator` | Operator Staff | the CHECK becomes `('owner','staff')` and existing rows are rewritten — **after the production census counts principals by kind** (`docs/116` §D.1) | **027** |
| `tests/test_rls_isolation.php` L191, L214 | fixtures insert `kind = 'operator'` | a planted / probe principal of tenant B | must stay a legal CHECK value until 027; change to `'staff'` in the same commit as 027 | **027** |
| `Projection::PRINCIPAL` (`/me` returns `kind`) | value `operator` | the schema value | the value changes with 027; the key does not | **027** |
| `migrations/003_commercial_plane.sql` L30 · `tests/test_frozen_guards.php` L40, L54 | entitlement key `max_operators` | the maximum number of Operator Staff accounts an operator bought (F8/F10 territory) | a stored key; renaming to `max_staff` is a data change with its own decision; nothing reads it today | **decision** |
| `plugin/plugin.json` routes `/customers`, `/customers/{customer_id}`; capabilities `customers.read` / `customers.write`; JSON keys `customer` / `customers`; `panel/api.js` `customers()` / `customer(id)`; nav id `customers` | `customer` | the operator | a **later, non-breaking** code change: add `/operators` paths and `operator` keys, keep the old ones for one release, update the manifest and the surface-equality test, then retire. Not in this step: it is an API behaviour change | **API** |
| `src/Db/Database.php` and other internal docblocks saying "customer" | `customer` | the tenant | nothing — internal names mirror the schema by rule 2 | historical |
| `migrations/015_role_separation.sql` L35 | "until an operator provisions a credential" | the engineer | migration files are not edited for wording | historical |
| `tools/audit/production_census.sql` L347, L378 | `NOTICE` text "the operator must also confirm", "separate operator decision" | DishNet | output text the acceptance harness runs verbatim; meaning is clear in context | historical |
| `tools/audit/proto_f6.sql`, `proto_f6_cases.sh` | `pf_operator_activate`, `('principal','operator', …)` | the customer-side person activating a voucher in the docs/64 Q5 prototype | a disposable F6 probe, excluded from the package; not touched | historical |
| `docs/42`, `43`, `46`, `47`, `48`, `51`, `53`, `55`, `56`, `80`, `100`, `104`, `105`, `106`, `107`, `112` | `owner \| operator`, "operator accounts", "operator/staff accounts" | the schema value, or Operator Staff before the term existed | rule 3 — records | historical |
| `docs/30`–`113` and pre-`docs/115` CLAUDE.md sections, ~23 phrases of the form "the operator runs / confirms / decides …" and ~58 further uses in CLAUDE.md | "the operator" | the DishNet engineer | rule 3 — records | historical |
| UI *sites* vs *locations* | `Site` | `mt_sites` | **T-1b**, an open wording choice; the UI keeps *sites* until it is made | **decision** |

---

## 3. Confirmations

- `ls migrations/ | tail -1` → `025_commercial_grant_closure.sql`; the suite's
  installer applied **25** migrations. No `026_*` or `027_*` file exists.
- No `GRANT`, `REVOKE`, `ALTER`, `CREATE` or `UPDATE` was run against any
  database by this pass; the only database activity was the test suite
  rebuilding `dnb_test` as it always does.
- `plugin/plugin.json`: `api`, `gates` and the `config` key list are
  byte-identical before and after (asserted in the edit script); the
  surface-equality test still compares the same routes.
- `panel/`: nav **ids** (`customers`, …), API paths and JSON keys are unchanged;
  only labels and comments moved.
- The full suite ran twice after the edits — results in the commit message.

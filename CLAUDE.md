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

**Q-F7-1…7 and F-8.1…5 are OPEN.** Nothing in this area may be implemented
without an explicit instruction.
- **W-4, W-5, W-6 remain OPEN.** Do not invent a staff credential store, a
  suspended state, customer/site editing, or a delivery case that does not exist.

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

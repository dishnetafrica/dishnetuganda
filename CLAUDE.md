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
